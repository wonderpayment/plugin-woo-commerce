<?php
if (!defined('ABSPATH')) {
    exit;
}


class WC_Wonder_Payments_Gateway extends WC_Payment_Gateway
{

    public $app_id;
    public $private_key;
    public $generated_public_key; // New: public key generated from the private key
    public $webhook_public_key; // Webhook public key from the portal
    public $title;
    public $description;
    public $enabled;

    /**
     * Get WooCommerce logger instance
     *
     * @return WC_Logger
     */
    private function get_logger() {
        return wc_get_logger();
    }

    public function __construct()
    {
        $this->id = 'wonder_payments';
        $this->icon = 'https://cdn.prod.website-files.com/66a434223af11b56a4762411/673175cb1da46b92a7e535fa_Icon-App-1024x1024.png';
        $this->has_fields = false;
        $this->method_title = __('Wonder Payment For WooCommerce', 'wonder-payments');
        $this->method_description = __('7 minutes onboarding, then accepted 34+ payment methods', 'wonder-payments');

        // Declare supported features
        $this->supports = array(
                'products',
                'refunds'
        );

        // Initialize form fields
        $this->init_form_fields();

        // Initialize settings (loads saved config from the database)
        $this->init_settings();

        // Get settings values
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->app_id = $this->get_option('app_id');
        $this->private_key = $this->get_option('private_key');
        $this->generated_public_key = $this->get_option('generated_public_key'); // New
        $this->webhook_public_key = $this->get_option('webhook_public_key');
        $this->environment = $this->get_option('environment'); // New environment setting
        $this->due_date = $this->get_option('due_date'); // New payment due days

        // Set testmode: sandbox off shows test mode, sandbox on shows active
        $this->testmode = ($this->environment === 'no');

        // Save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Webhook handling
        add_action('woocommerce_api_wonder_payments_webhook', array($this, 'handle_webhook'));

        // Register AJAX handler for key pair generation
        add_action('wp_ajax_wonder_generate_keys', array($this, 'ajax_generate_keys'));

        // Clear cart after successful payment
        add_action('woocommerce_thankyou', array($this, 'clear_cart_after_payment'), 10, 1);

        // Add order status sync button
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'add_order_sync_button'), 20, 1);

    }

    public function init_form_fields()
    {
        // Get current environment config and log
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $sandbox_enabled = isset($settings['environment']) ? $settings['environment'] : 'no';
        $environment = ($sandbox_enabled === 'yes') ? 'prod' : 'stg';
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        $this->form_fields = array(
                'enabled' => array(
                        'title' => __('Enable/Disable', 'wonder-payments'),
                        'label' => __('Enable Wonder Payments', 'wonder-payments'),
                        'type' => 'checkbox',
                        'default' => 'no'
                ),
                'environment' => array(
                        'title' => __('Sandbox Mode', 'wonder-payments'),
                        'label' => __('Enable Sandbox Mode', 'wonder-payments'),
                        'type' => 'checkbox',
                        'description' => __('When enabled, uses the staging environment. When disabled, uses the production environment.', 'wonder-payments'),
                        'default' => 'yes',
                        'desc_tip' => true
                ),
                'due_date' => array(
                        'title' => __('Payment Due Days', 'wonder-payments'),
                        'type' => 'number',
                        'description' => __('Number of days from order creation until payment is due. Default is 30 days.', 'wonder-payments'),
                        'default' => '30',
                        'desc_tip' => true,
                        'css' => 'width: 100px;',
                        'custom_attributes' => array(
                                'min' => '1',
                                'step' => '1'
                        )
                ),
                'title' => array(
                        'title' => __('Title', 'wonder-payments'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'wonder-payments'),
                        'default' => __('Wonder Payments', 'wonder-payments'),
                        'desc_tip' => true
                ),
                'description' => array(
                        'title' => __('Description', 'wonder-payments'),
                        'type' => 'textarea',
                        'description' => __('This controls the description which the user sees during checkout.', 'wonder-payments'),
                        'default' => __('Pay securely via Wonder Payments', 'wonder-payments'),
                        'desc_tip' => true
                ),
                'app_id' => array(
                        'title' => __('App ID', 'wonder-payments'),
                        'type' => 'text',
                        'description' => __('Your Wonder Payments App ID', 'wonder-payments'),
                        'default' => '',
                        'desc_tip' => true,
                        'css' => 'width: 400px;'
                ),
            // Hidden field for storing private key
                'private_key' => array(
                        'type' => 'hidden',
                ),
            // Hidden field for storing generated public key
                'generated_public_key' => array(
                        'type' => 'hidden',
                ),
            // Webhook public key field - from the portal
                'webhook_public_key' => array(
                        'title' => __('Webhook Public Key', 'wonder-payments'),
                        'type' => 'textarea',
                        'description' => __('Get webhook public key from wonder portal when created appid.', 'wonder-payments'),
                        'default' => '',
                        'css' => 'font-family: monospace; font-size: 11px; width: 100%; height: 200px;',
                        'desc_tip' => false
                ),
        );
    }

    public function admin_options()
    {
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $private_key = isset($settings['private_key']) ? $settings['private_key'] : '';
        $generated_public_key = isset($settings['generated_public_key']) ? $settings['generated_public_key'] : '';
        ?>
        <h2><?php echo esc_html($this->method_title); ?></h2>

        <?php echo wp_kses_post(wpautop($this->method_description)); ?>

        <table class="form-table">
            <?php
            // Only output basic fields (before App ID)
            $basic_fields = array('enabled', 'environment', 'title', 'description', 'app_id', 'due_date');
            foreach ($basic_fields as $field) {
                if (isset($this->form_fields[$field])) {
                    $this->generate_settings_html(array($field => $this->form_fields[$field]));
                }
            }
            ?>
        </table>

        <!-- Output hidden fields -->
        <?php
        if (isset($this->form_fields['private_key'])) {
            $this->generate_settings_html(array('private_key' => $this->form_fields['private_key']));
        }
        if (isset($this->form_fields['generated_public_key'])) {
            $this->generate_settings_html(array('generated_public_key' => $this->form_fields['generated_public_key']));
        }
        ?>

            <!-- Key pair display area -->
            <div class="wonder-keys-display" style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div style="flex: 1;">
                    <h4><?php esc_html_e('Private Key', 'wonder-payments'); ?></h4>
                    <p class="description"><?php esc_html_e('Your RSA 4096-bit private key. Keep this secure.', 'wonder-payments'); ?></p>
                    <textarea id="wonder-private-key-display"
                              style="width: 100%; height: 200px; font-family: monospace; font-size: 11px; margin: 10px 0; padding: 10px; background: #fff;">
                            <?php echo esc_textarea($private_key); ?>
                        </textarea>

                    <button type="button" class="button button-primary" id="wonder-generate-keys">
                        <?php esc_html_e('Generate RSA Keys', 'wonder-payments'); ?>
                    </button>
                    <span class="spinner" style="float: none; margin: 0 10px;"></span>
                    <span id="wonder-generate-message" style="margin-left: 10px;"></span>
                </div>

                <div style="flex: 1;">
                    <h4><?php esc_html_e('Public Key', 'wonder-payments'); ?></h4>
                    <p class="description"><?php esc_html_e('Copy this public key and upload it to Wonder Portal.', 'wonder-payments'); ?></p>
                    <textarea id="wonder-generated-public-key-display" readonly
                              style="width: 100%; height: 200px; font-family: monospace; font-size: 11px; margin: 10px 0; padding: 10px; background: #fff;">
                            <?php echo esc_textarea($generated_public_key); ?>
                        </textarea>
                </div>
            </div>
        </table>

        <!-- Continue output of the Webhook Public Key field -->
        <table class="form-table" style="margin-top: 30px;">
            <?php
            // Note.
            if (isset($this->form_fields['webhook_public_key'])) {
                $this->generate_settings_html(array('webhook_public_key' => $this->form_fields['webhook_public_key']));
            }

            // Output hidden fields
            if (isset($this->form_fields['private_key'])) {
                $this->generate_settings_html(array('private_key' => $this->form_fields['private_key']));
            }
            if (isset($this->form_fields['generated_public_key'])) {
                $this->generate_settings_html(array('generated_public_key' => $this->form_fields['generated_public_key']));
            }
            ?>
        </table>

        <!-- Test Configuration button -->
        <div class="wonder-payments-actions">
            <button type="button" class="button" id="wonder-test-config">
                <?php esc_html_e('Test Configuration', 'wonder-payments'); ?>
            </button>
            <span class="spinner" style="float: none; margin: 0 10px;"></span>
            <span id="wonder-action-message" style="margin-left: 10px;"></span>
        </div>

        <style>
            #woocommerce_wonder_payments_webhook_public_key_field .forminp-textarea textarea {
                width: 100%;
                max-width: 800px;
                height: 200px;
                font-family: monospace;
                font-size: 11px;
            }

            #wonder-rsa-keys-section textarea {
                resize: vertical;
            }

            .wonder-keys-display {
                flex-wrap: wrap;
            }

            @media (max-width: 768px) {
                .wonder-keys-display {
                    flex-direction: column;
                }
            }

            /* Hide rows for hidden fields */
            #woocommerce_wonder_payments_private_key_field,
            #woocommerce_wonder_payments_generated_public_key_field {
                display: none !important;
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                // Check for saved private key and display it
                var savedPrivateKey = '<?php echo esc_js($private_key); ?>';
                var savedGeneratedPublicKey = '<?php echo esc_js($generated_public_key); ?>';

                if (savedPrivateKey) {
                    $('#wonder-private-key-display').val(savedPrivateKey);
                }

                if (savedGeneratedPublicKey) {
                    $('#wonder-generated-public-key-display').val(savedGeneratedPublicKey);
                }

                // Key pair generation button click event
                $(document).on('click', '#wonder-generate-keys', function (e) {
                    e.preventDefault();

                    var $button = $(this);
                    var $spinner = $(this).siblings('.spinner');
                    var $message = $('#wonder-generate-message');
                    var $privateKeyDisplay = $('#wonder-private-key-display');
                    var $publicKeyDisplay = $('#wonder-generated-public-key-display');

                    // Show loading state
                    $button.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $message.html('').removeClass('success error');

                    $.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                        type: 'POST',
                        data: {
                            action: 'wonder_generate_keys',
                            security: '<?php echo esc_attr(wp_create_nonce('wonder_generate_keys')); ?>'
                        },
                        success: function (response) {
                            console.log('Generate keys response:', response);
                            if (response.success) {
                                // Show success message
                                $message.html('<span style="color: green;">✅ ' + response.data.message + '</span>').addClass('success');

                                // Fill private and public keys in the display area
                                $privateKeyDisplay.val(response.data.private_key);
                                $publicKeyDisplay.val(response.data.public_key);

                                // Update hidden form fields
                                $('input[name="woocommerce_wonder_payments_private_key"]').val(response.data.private_key);
                                $('input[name="woocommerce_wonder_payments_generated_public_key"]').val(response.data.public_key);
                            } else {
                                // Show error message
                                $message.html('<span style="color: red;">❌ ' + response.data.message + '</span>').addClass('error');
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('Generate keys error:', error);
                            $message.html('<span style="color: red;">❌ <?php echo esc_js(__('Error:', 'wonder-payments')); ?> ' + error + '</span>').addClass('error');
                        },
                        complete: function () {
                            // Restore button state
                            $button.prop('disabled', false);
                            $spinner.removeClass('is-active');
                        }
                    });
                });

            });
        </script>
        <?php
    }

    /**
     * AJAX: generate RSA key pair
     */
    public function ajax_generate_keys()
    {
        // Log
        $logger = $this->get_logger();
        $logger->debug('Generate RSA Keys method called', array( 'source' => 'wonder-payments' ));

        check_ajax_referer('wonder_generate_keys', 'security');

        // Get current environment config
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $sandbox_enabled = isset($settings['environment']) ? $settings['environment'] : 'no';

        // Determine environment by sandbox mode
        $environment = ($sandbox_enabled === 'yes') ? 'prod' : 'stg';
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        try {
            // Generate 4096-bit RSA key pair
            $config = array(
                    "digest_alg" => "sha256",
                    "private_key_bits" => 4096,
                    "private_key_type" => OPENSSL_KEYTYPE_RSA,
            );

            // Generate key pair
            $key_pair = openssl_pkey_new($config);

            if (!$key_pair) {
                throw new Exception('Failed to generate RSA key pair');
            }

            // Get private key
            openssl_pkey_export($key_pair, $private_key);
            // Get public key
            $key_details = openssl_pkey_get_details($key_pair);
            $public_key_pem = $key_details['key'];
            // Update plugin settings
            $settings = get_option('woocommerce_wonder_payments_settings', array());
            $settings['private_key'] = $private_key;
            $settings['generated_public_key'] = $public_key_pem;

            update_option('woocommerce_wonder_payments_settings', $settings);
            $response = array(
                    'message' => __('RSA 4096-bit key pair generated successfully', 'wonder-payments'),
                    'private_key' => $private_key,
                    'public_key' => $public_key_pem
            );

            $logger = $this->get_logger();
            $logger->debug('Prepare success response', array( 'source' => 'wonder-payments' ));
            $logger->debug('Response data: message=' . $response['message'] . ', private_key_length=' . strlen($private_key) . ', public_key_length=' . strlen($public_key_pem), array( 'source' => 'wonder-payments' ));

            wp_send_json_success($response);

        } catch (Exception $e) {
            $logger = $this->get_logger();
            $logger->error('Generate RSA keys request ended - error: ' . $e->getMessage(), array( 'source' => 'wonder-payments' ));
            wp_send_json_error(array(
                    'message' => $e->getMessage()
            ));
        }
    }

    /**
     * Check if the payment gateway is available
     *
     * @return bool
     */
    /**
         * Get environment config
         *
         * @return string 'stg' or 'prod'
         */
        public function get_environment() {
            // Sandbox Use stg when sandbox is off, prod when sandbox is on
            return $this->environment === 'yes' ? 'prod' : 'stg';
        }        public function is_available() {
        $logger = $this->get_logger();
        $logger->debug('is_available() - enabled: ' . ($this->enabled ? 'yes' : 'no'), array( 'source' => 'wonder-payments' ));

        $is_available = parent::is_available();

        // If the parent returns false, return immediately
        if (!$is_available) {
            return false;
        }

        // Check if configuration is complete (app_id and private_key)
        $app_id_value = $this->app_id ? 'set' : 'empty';
        $private_key_value = $this->private_key ? 'set' : 'empty';
        $logger->debug('is_available() - app_id: ' . $app_id_value . ', private_key: ' . $private_key_value, array( 'source' => 'wonder-payments' ));

        if (empty($this->app_id) || empty($this->private_key)) {
            // Configuration incomplete; return false to show "Action Needed"
            $logger->debug('is_available() - Configuration incomplete, returning false', array( 'source' => 'wonder-payments' ));
            return false;
        }

        $logger->debug('is_available() - Configuration complete, returning true', array( 'source' => 'wonder-payments' ));
        return true;
    }
    
    /**
     * Check if AppID is configured
     */
    public function is_appid_configured() {
        return !empty($this->app_id);
    }
    
    /**
     * Get custom status
     * Return:
     * 1. Enabled + AppID missing → "Action is needed" (Action Needed)
     * 2. Enabled + AppID configured → "Active" (Activated)
     * 3. Disabled → "Inactive" (Inactive)
     */
    public function get_custom_status() {
        if ($this->enabled !== 'yes') {
            return array(
                'text' => __('Inactive', 'wonder-payments'),
                'status' => 'inactive',
                'color' => '#a7aaad',
                'icon' => 'dashicons-dismiss'
            );
        }

        if (!$this->is_appid_configured()) {
            return array(
                'text' => __('Action needed', 'wonder-payments'),
                'status' => 'action_needed',
                'color' => '#d63638',
                'icon' => 'dashicons-warning'
            );
        }

        return array(
            'text' => __('Active', 'wonder-payments'),
            'status' => 'activated',
            'color' => '#00a32a',
            'icon' => 'dashicons-yes-alt'
        );
    }
    
    /**
     * Get custom status HTML
     */
    public function get_admin_status_html() {
        $status = $this->get_custom_status();
        
        return sprintf(
            '<span class="wonder-payment-status" style="display:inline-flex;align-items:center;gap:4px;color:%s;font-size:12px;font-weight:500;">
                <span class="dashicons %s" style="font-size:16px;"></span>
                <strong>%s</strong>
            </span>',
            esc_attr($status['color']),
            esc_attr($status['icon']),
            esc_html($status['text'])
        );
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Log current environment config
        $environment = $this->get_environment();
        $sandbox_enabled = $this->get_option('environment');
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        $logger = $this->get_logger();
        $logger->debug('Payment init - credentials snapshot', array(
            'source' => 'wonder-payments',
            'app_id' => $this->mask_credential($this->app_id),
            'environment' => $environment
        ));
        $logger->debug('API endpoint: ' . $api_endpoint, array( 'source' => 'wonder-payments' ));

        // Check required parameters
        if (empty($this->app_id) || empty($this->private_key)) {
            wc_add_notice(__('Payment gateway is not configured properly. Please check your App ID and Private Key.', 'wonder-payments'), 'error');
            return array(
                'result' => 'failure',
                'message' => __('Payment gateway is not configured properly. Please check your App ID and Private Key.', 'wonder-payments')
            );
        }

        // Check if SDK exists
        if (!class_exists('PaymentSDK')) {
            wc_add_notice(__('Payment gateway SDK is not available. Please ensure the SDK files are properly included.', 'wonder-payments'), 'error');
            return array(
                'result' => 'failure',
                'message' => __('Payment gateway SDK is not available. Please ensure the SDK files are properly included.', 'wonder-payments')
            );
        }

        try {
            // Build callback and redirect URLs
            $callback_url = WC()->api_request_url('wonder_payments_webhook');

            // Redirect URL points to the WooCommerce thank-you page
            $redirect_url = $order->get_checkout_order_received_url();

            // Log callback/redirect URLs to verify delivery to Wonder
            $logger->debug('Create payment link URL', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id,
                'callback_url' => $callback_url,
                'redirect_url' => $redirect_url
            ));
            $logger->debug('Payment create link - credentials snapshot', array(
                'source' => 'wonder-payments',
                'app_id' => $this->mask_credential($this->app_id),
                'environment' => $environment
            ));

            // Validate private key format
            $privateKey = trim($this->private_key);
            if (empty($privateKey)) {
                wc_add_notice(__('Private key is not set. Please configure your payment gateway.', 'wonder-payments'), 'error');
                return array(
                    'result' => 'failure',
                    'message' => __('Private key is not set. Please configure your payment gateway.', 'wonder-payments')
                );
            }

            $privateKeyId = openssl_pkey_get_private($privateKey);
            if (!$privateKeyId) {
                wc_add_notice(__('Invalid private key format. Please reconfigure your payment gateway.', 'wonder-payments'), 'error');
                return array(
                    'result' => 'failure',
                    'message' => __('Invalid private key format. Please reconfigure your payment gateway.', 'wonder-payments')
                );
            }
            openssl_pkey_free($privateKeyId);

            // Initialize SDK
            $webhook_key = !empty($this->webhook_public_key) ? $this->webhook_public_key : null;
            $environment = $this->get_environment();

            $options = array(
                    'appid' => $this->app_id,
                    'signaturePrivateKey' => $privateKey,
                    'webhookVerifyPublicKey' => $webhook_key ?: '',
                    'callback_url' => $callback_url,
                    'redirect_url' => $redirect_url,
                    'environment' => $environment,
                    'skipSignature' => false
            );

// Generate a unique request_id (random UUID)
            $request_id = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                wp_rand(0, 0xffff), wp_rand(0, 0xffff),
                wp_rand(0, 0xffff),
                wp_rand(0, 0x0fff) | 0x4000,
                wp_rand(0, 0x3fff) | 0x8000,
                wp_rand(0, 0xffff), wp_rand(0, 0xffff), wp_rand(0, 0xffff)
            );
            $options['request_id'] = $request_id;

            $sdk = new PaymentSDK($options);

            if (!$sdk) {
                wc_add_notice(__('Payment gateway SDK initialization failed.', 'wonder-payments'), 'error');
                return array(
                    'result' => 'failure',
                    'message' => __('Payment gateway SDK initialization failed.', 'wonder-payments')
                );
            }

            // Build order data
            $order_currency = $order->get_currency();
            $supported_currencies = array('HKD', 'USD', 'EUR', 'GBP');
            $api_currency = in_array($order_currency, $supported_currencies) ? $order_currency : 'HKD';

            // Generate a unique reference_number: order number - sequence - timestamp
            // Get current sequence from order meta, start at 1 if missing
            $sequence_number = get_post_meta($order_id, '_wonder_payment_sequence', true);
            if (empty($sequence_number)) {
                $sequence_number = 1;
            } else {
                $sequence_number = intval($sequence_number) + 1;
            }
            update_post_meta($order_id, '_wonder_payment_sequence', $sequence_number);

            $reference_number = $order->get_order_number() . '-' . $sequence_number . '-' . time();

            // Get configured payment due days, default 30
            $due_days = !empty($this->due_date) ? intval($this->due_date) : 30;
            $due_date = gmdate('Y-m-d', strtotime('+' . $due_days . ' days'));

            $order_data = array(
                    'order' => array(
                            'reference_number' => $reference_number,
                            'charge_fee' => number_format($order->get_total(), 2, '.', ''),
                            'due_date' => $due_date,
                            'currency' => $api_currency,
                            'note' => 'Order #' . $order->get_order_number() . ' from ' . get_bloginfo('name'),
                            'customer' => array(
                                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                                    'email' => $order->get_billing_email(),
                                    'phone' => $order->get_billing_phone()
                            )
                    )
            );

            $logger->debug('Create payment link params', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id,
                'redirect_url' => $redirect_url,
                'callback_url' => $callback_url,
                'reference_number' => $reference_number
            ));

                // Create payment link via SDK (always create a new order, no cache)
                $response = $sdk->createPaymentLink($order_data);
                // Log full response for debugging
                $logger->debug('Create payment link response', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id,
                    'response' => $response
                ));
                // Validate response and get payment link
                $payment_link = null;
                if (isset($response['data']['payment_link']) && !empty($response['data']['payment_link'])) {
                    $payment_link = $response['data']['payment_link'];
                } elseif (isset($response['payment_link']) && !empty($response['payment_link'])) {
                    $payment_link = $response['payment_link'];
                }
                if ($payment_link) {
                    // Save payment link and reference number to order meta (cache)
                    update_post_meta($order_id, '_wonder_payment_link', $payment_link);
                    update_post_meta($order_id, '_wonder_reference_number', $reference_number);
                    // Save request_id for tracking
                    update_post_meta($order_id, '_wonder_request_id', $request_id);
                    // Try saving Wonder order number (if returned)
                    if (isset($response['data']['order']['number'])) {
                        update_post_meta($order_id, '_wonder_order_number', $response['data']['order']['number']);
                    } elseif (isset($response['data']['order_number'])) {
                        update_post_meta($order_id, '_wonder_order_number', $response['data']['order_number']);
                    }
                    // Save transaction ID (if present)
                    if (isset($response['data']['id'])) {
                        update_post_meta($order_id, '_wonder_transaction_id', $response['data']['id']);
                    }
                    // Update order status to pending payment
                    $order->update_status('pending', __('Awaiting Wonder Payments payment', 'wonder-payments'));
                    // Redirect to payment link
                    return array(
                            'result'   => 'success',
                            'redirect' => $payment_link
                    );
                } else {
                    $error_msg = __('Payment link could not be created. Please contact administrator.', 'wonder-payments');

                    if (isset($response['message'])) {
                        $error_msg = $response['message'];
                    } elseif (isset($response['error'])) {
                        $error_msg = $response['error'];
                    }

                    $logger->error('Payment Error: ' . $error_msg, array( 'source' => 'wonder-payments' ));
                    wc_add_notice($error_msg, 'error');
                    return array(
                        'result' => 'failure',
                        'message' => $error_msg
                    );
                }
        } catch (Exception $e) {
            /* translators: %s: error message */
            $error_message = sprintf(__('Payment processing failed: %s', 'wonder-payments'), $e->getMessage());
            wc_add_notice($error_message, 'error');
            return array(
                'result' => 'failure',
                'message' => $error_message
            );
        }
    }

    /**
     * Handle refund request
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // Validate required parameters
        if (!$this->app_id || !$this->private_key) {
            return new WP_Error('error', __('Payment gateway not configured', 'wonder-payments'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('error', __('Order not found', 'wonder-payments'));
        }

        if (!$amount || $amount <= 0) {
            return new WP_Error('error', __('Invalid refund amount', 'wonder-payments'));
        }

        try {
            // Check if SDK exists
            if (!class_exists('PaymentSDK')) {
                return new WP_Error('error', __('PaymentSDK not available', 'wonder-payments'));
            }

            // Get order reference number
            $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
            if (empty($reference_number)) {
                return new WP_Error('error', __('Order reference number not found', 'wonder-payments'));
            }

            $logger = $this->get_logger();

            // Get order currency
            $order_currency = $order->get_currency();
            $supported_currencies = array('HKD', 'USD', 'EUR', 'GBP');
            $api_currency = in_array($order_currency, $supported_currencies) ? $order_currency : 'HKD';

// Initialize SDK
            $webhook_key = !empty($this->webhook_public_key) ? $this->webhook_public_key : null;
            $options = array(
                    'appid' => $this->app_id,
                    'signaturePrivateKey' => $this->private_key,
                    'webhookVerifyPublicKey' => $webhook_key ?: '',
                    'callback_url' => '',
                    'redirect_url' => '',
                    'environment' => $this->get_environment(),
                    'skipSignature' => false
            );

            // Generate a unique request_id (random UUID)
            $request_id = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                wp_rand(0, 0xffff), wp_rand(0, 0xffff),
                wp_rand(0, 0xffff),
                wp_rand(0, 0x0fff) | 0x4000,
                wp_rand(0, 0x3fff) | 0x8000,
                wp_rand(0, 0xffff), wp_rand(0, 0xffff), wp_rand(0, 0xffff)
            );
            $options['request_id'] = $request_id;

            $sdk = new PaymentSDK($options);
            
            $query_params = array(
                'order' => array(
                    'reference_number' => $reference_number
                )
            );

            $query_response = $sdk->queryOrder($query_params);
            $logger->debug('Order query response received', array( 'source' => 'wonder-payments' ));

            // Fill Wonder order number and transaction UUID from query response
            $wonder_order_number = get_post_meta($order_id, '_wonder_order_number', true);
            if (empty($wonder_order_number) && isset($query_response['data']['order']['number'])) {
                $wonder_order_number = $query_response['data']['order']['number'];
                update_post_meta($order_id, '_wonder_order_number', $wonder_order_number);
            }

            $payment_transaction_uuid = get_post_meta($order_id, '_wonder_transaction_id', true);
            if (empty($payment_transaction_uuid) && isset($query_response['data']['order']['transactions']) && !empty($query_response['data']['order']['transactions'])) {
                foreach ($query_response['data']['order']['transactions'] as $transaction) {
                    if (!empty($transaction['uuid'])) {
                        $payment_transaction_uuid = $transaction['uuid'];
                        update_post_meta($order_id, '_wonder_transaction_id', $payment_transaction_uuid);
                        break;
                    }
                }
            }

            if (empty($wonder_order_number)) {
                return new WP_Error('error', __('Wonder Payments order number not found', 'wonder-payments'));
            }

            if (empty($payment_transaction_uuid)) {
                return new WP_Error('error', __('Payment transaction UUID not found', 'wonder-payments'));
            }

            $logger->debug('Refund using payment transaction UUID: ' . $payment_transaction_uuid, array( 'source' => 'wonder-payments' ));
            
            // Check if refund is allowed (from transactions array)
            $allow_refund = false;
            if (isset($query_response['data']['order']['transactions']) && !empty($query_response['data']['order']['transactions'])) {
                $allow_refund = isset($query_response['data']['order']['transactions'][0]['allow_refund']) && $query_response['data']['order']['transactions'][0]['allow_refund'] == 1;
            }
            
            if (!$allow_refund) {
                return new WP_Error('error', __('This order does not allow refunds', 'wonder-payments'));
            }
            
            // Get refunded amount (from transactions array)
            $refunded_amount = 0;
            if (isset($query_response['data']['order']['transactions'][0]['refunded_amount'])) {
                $refunded_amount = floatval($query_response['data']['order']['transactions'][0]['refunded_amount']);
            }
            $order_total = floatval($query_response['data']['order']['initial_total']);
            $available_refund = $order_total - $refunded_amount;

            $logger->debug('Refund validation - order total: ' . $order_total, array( 'source' => 'wonder-payments' ));
            $logger->debug('Refund validation - refunded amount: ' . $refunded_amount, array( 'source' => 'wonder-payments' ));
            $logger->debug('Refund validation - refundable amount: ' . $available_refund, array( 'source' => 'wonder-payments' ));
            $logger->debug('Refund validation - requested amount: ' . $amount, array( 'source' => 'wonder-payments' ));

            // Validate refund amount (use small epsilon to avoid float precision issues)
            $epsilon = 0.01; // Tolerance value
            if ($amount - $available_refund > $epsilon) {
                $refund_error = sprintf(
                    /* translators: %1$s: available amount, %2$s: requested amount */
                    __('Refund amount exceeds available amount. Available: %1$s, Requested: %2$s', 'wonder-payments'),
                    wc_price($available_refund),
                    wc_price($amount)
                );
                return new WP_Error('error', $refund_error);
            }

            // Note.
            $payment_transaction_uuid = get_post_meta($order_id, '_wonder_transaction_id', true);
            if (empty($payment_transaction_uuid)) {
                return new WP_Error('error', __('Payment transaction UUID not found', 'wonder-payments'));
            }

            // Step 3: build refund params (use payment transaction UUID)
            $refund_params = array(
                    'order' => array(
                            'number' => $wonder_order_number,          // Order identifiers unchanged
                            'reference_number' => $reference_number     // Order identifiers unchanged
                    ),
                    'transaction' => array(
                            'uuid' => $payment_transaction_uuid        // Use payment transaction UUID
                    ),
                    'refund' => array(
                            'amount' => number_format($amount, 2, '.', ''),
                            'currency' => $api_currency,
                            'reason' => $reason ? $reason : 'Refund via WooCommerce'
                    )
            );

            // Step 4: call SDK refundTransaction
            $response = $sdk->refundTransaction($refund_params);

            $logger->debug('Refund response received', array( 'source' => 'wonder-payments' ));

            // Check response
            if (isset($response['code']) && $response['code'] == 200) {
                // Refund success
                $refund_note = sprintf(
                    /* translators: %1$s: refund amount, %2$s: refund reason */
                    __('Wonder Payments refund successful. Amount: %1$s, Reason: %2$s', 'wonder-payments'),
                    wc_price($amount),
                    $reason ? $reason : 'N/A'
                );
                $order->add_order_note($refund_note);

                // Save refund ID (if present)
                if (isset($response['data']['order']['transactions'])) {
                    $transactions = $response['data']['order']['transactions'];
                    foreach ($transactions as $transaction) {
                        if (isset($transaction['type']) && $transaction['type'] === 'Refund') {
                            update_post_meta($order_id, '_wonder_refund_id', $transaction['uuid']);
                            break;
                        }
                    }
                }

                // Update refund post status to wc-completed
                $refunds = $order->get_refunds();
                foreach ($refunds as $refund) {
                    $refund_id = $refund->get_id();
                    $refund_post_status = get_post_status($refund_id);
                    if ($refund_post_status === 'draft') {
                        wp_update_post(array(
                            'ID' => $refund_id,
                            'post_status' => 'wc-completed'
                        ));
                    }
                }

                // Step 5: decide order status based on refund amount
                $already_refunded = $order->get_total_refunded();
                $order_total = $order->get_total();

                // Check refund record status
                $refunds = $order->get_refunds();
                foreach ($refunds as $refund) {
                    $refund_id = $refund->get_id();
                    $refund_status = $refund->get_status();
                    $refund_post_status = get_post_status($refund_id);
                }
                
                if ($already_refunded >= $order_total) {
                    // Full refund
                    $order->update_status('refunded', __('Order fully refunded via Wonder Payments', 'wonder-payments'));
                } else {
                    // Note.
                    $order->update_status('completed', __('Order partially refunded via Wonder Payments', 'wonder-payments'));
                }
                
                return true;
            } else {
                // Refund failed
                $error_message = isset($response['message']) ? $response['message'] : 'Unknown error';
                if (isset($response['error_code'])) {
                    $error_message .= ' (' . $response['error_code'] . ')';
                }

                // Check error code 100701 and show custom error message
                if (isset($response['code']) && $response['code'] == 100701) {
                    $error_message = 'Refund failed, duplicate transaction. Refunds of the same amount are not allowed within five minutes.';
                }

                $refund_note = sprintf(
                    /* translators: %1$s: refund amount, %2$s: error message */
                    __('Wonder Payments refund failed. Amount: %1$s, Error: %2$s', 'wonder-payments'),
                    wc_price($amount),
                    $error_message
                );
                $order->add_order_note($refund_note);

                return new WP_Error('refund_error', $error_message);
            }
        } catch (Exception $e) {
            return new WP_Error('error', $e->getMessage());
        }
    }

    public function handle_webhook()
    {
        $logger = $this->get_logger();
        $logger->debug('Webhook entry: request received', array( 'source' => 'wonder-payments' ));

        // Note.
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);

        $logger->debug('Webhook raw request', array(
            'source' => 'wonder-payments',
            'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
            'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? absint(wp_unslash($_SERVER['CONTENT_LENGTH'])) : 0,
            'raw_length' => strlen($raw_data)
        ));

        if (!$data) {
            $logger->error('Webhook parse failed: invalid JSON', array(
                'source' => 'wonder-payments',
                'raw' => $raw_data
            ));
            wp_die('Invalid webhook data', 'Wonder Payments', array('response' => 400));
        }

        $logger->debug('Webhook parse success', array(
            'source' => 'wonder-payments',
            'keys' => array_keys($data)
        ));

        // Verify signature
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $signature = isset($_SERVER['HTTP_SIGNATURE']) ? wp_unslash($_SERVER['HTTP_SIGNATURE']) : '';
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $credential = isset($_SERVER['HTTP_CREDENTIAL']) ? wp_unslash($_SERVER['HTTP_CREDENTIAL']) : '';
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $nonce = isset($_SERVER['HTTP_NONCE']) ? wp_unslash($_SERVER['HTTP_NONCE']) : '';

        $is_valid = false;

        // Check and initialize verification
        if (!class_exists('PaymentSDK')) {
            wp_die('SDK not available for webhook verification', 'Wonder Payments', array('response' => 403));
        }

        $webhook_key = !empty($this->webhook_public_key) ? $this->webhook_public_key : null;
        $normalized_webhook_key = $this->normalize_webhook_public_key($webhook_key);
        $logger->debug('Webhook public key info', array(
            'source' => 'wonder-payments',
            'webhook_public_key_len' => $webhook_key ? strlen($webhook_key) : 0,
            'webhook_public_key_pem' => ($normalized_webhook_key && strpos($normalized_webhook_key, 'BEGIN PUBLIC KEY') !== false) ? 'yes' : 'no'
        ));
        $options = array(
                'appid' => $this->app_id,
                'signaturePrivateKey' => $this->private_key,
                'webhookVerifyPublicKey' => $webhook_key ?: '',
                'callback_url' => '',
                'redirect_url' => '',
                'environment' => $this->get_environment(),
                'skipSignature' => false
        );

        $sdk = new PaymentSDK($options);

        if (!$sdk) {
            wp_die('SDK not available for webhook verification', 'Wonder Payments', array('response' => 403));
        }

        $logger->debug('Webhook verify start', array(
            'source' => 'wonder-payments',
            'signature' => $signature ? 'set' : 'empty',
            'credential' => $credential ? 'set' : 'empty',
            'nonce' => $nonce ? 'set' : 'empty'
        ));

        $verify_start = microtime(true);
        try {
            $body = $raw_data ? $raw_data : '';
            $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'POST';

            $signature_message = $sdk->generateSignatureMessage($credential, $nonce, $method, $uri, $body);
            $public_key = $normalized_webhook_key ? openssl_pkey_get_public($normalized_webhook_key) : false;
            if ($public_key) {
                $verify_result = openssl_verify($signature_message, base64_decode($signature), $public_key, OPENSSL_ALGO_SHA256);
                $is_valid = ($verify_result === 1);
            } else {
                $logger->error('Webhook verify failed: invalid public key', array(
                    'source' => 'wonder-payments'
                ));
                $is_valid = false;
            }
        } catch (Throwable $e) {
            $logger->error('Webhook verify exception', array(
                'source' => 'wonder-payments',
                'message' => $e->getMessage(),
                'type' => get_class($e)
            ));
        }
        $verify_elapsed = (int) round((microtime(true) - $verify_start) * 1000);
        $logger->debug('Webhook verify end', array(
            'source' => 'wonder-payments',
            'result' => $is_valid ? 'valid' : 'invalid',
            'elapsed_ms' => $verify_elapsed
        ));

        if (!$is_valid) {
            $logger->error('Webhook verify failed', array(
                'source' => 'wonder-payments',
                'credential' => $credential ? 'set' : 'empty',
                'nonce' => $nonce ? 'set' : 'empty',
                'signature' => $signature ? 'set' : 'empty'
            ));
            wp_die('Invalid signature', 'Wonder Payments', array('response' => 403));
        }

        $logger->debug('Webhook verify passed', array(
            'source' => 'wonder-payments'
        ));

        // Support payload shape: order object or data.order
        $payload = $data;
        if (isset($data['data']['order']) && is_array($data['data']['order'])) {
            $payload = $data['data']['order'];
        }

        $reference_number = isset($payload['reference_number']) ? sanitize_text_field($payload['reference_number']) : '';
        if (empty($reference_number)) {
            $logger->error('Webhook missing reference_number', array( 'source' => 'wonder-payments' ));
            wp_die('Missing reference_number', 'Wonder Payments', array('response' => 400));
        }

        $logger->debug('Webhook parsed reference_number', array(
            'source' => 'wonder-payments',
            'reference_number' => $reference_number
        ));


        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- reference_number lookup is required for webhook routing.
        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_wonder_reference_number',
            'meta_value' => $reference_number,
            'return' => 'ids'
        ));
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        if (empty($orders)) {
            $order_id_guess = absint(strtok($reference_number, '-'));
            if ($order_id_guess) {
                $fallback_order = wc_get_order($order_id_guess);
                if ($fallback_order && $fallback_order->get_payment_method() === $this->id) {
                    update_post_meta($order_id_guess, '_wonder_reference_number', $reference_number);
                    $orders = array($order_id_guess);
                    $logger->debug('Webhook fallback order match success', array(
                        'source' => 'wonder-payments',
                        'order_id' => $order_id_guess,
                        'reference_number' => $reference_number
                    ));
                }
            }
        }

        if (empty($orders)) {
            $logger->error('Webhook order not found', array(
                'source' => 'wonder-payments',
                'reference_number' => $reference_number
            ));
            wp_die('Order not found', 'Wonder Payments', array('response' => 404));
        }

        $order_id = (int) $orders[0];
        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->error('Webhook order load failed', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id
            ));
            wp_die('Order not found', 'Wonder Payments', array('response' => 404));
        }

        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $action = isset($_SERVER['HTTP_X_ACTION']) ? strtolower(wp_unslash($_SERVER['HTTP_X_ACTION'])) : '';

        $state = isset($payload['state']) ? strtolower($payload['state']) : '';
        $correspondence_state = isset($payload['correspondence_state']) ? strtolower($payload['correspondence_state']) : '';

        $logger->debug('Webhook status fields', array(
            'source' => 'wonder-payments',
            'action' => $action,
            'reference_number' => $reference_number,
            'state' => $state,
            'correspondence_state' => $correspondence_state
        ));
        // Persist key fields
        if (!empty($payload['number'])) {
            update_post_meta($order_id, '_wonder_order_number', $payload['number']);
        }
        update_post_meta($order_id, '_wonder_state', $state);
        update_post_meta($order_id, '_wonder_correspondence_state', $correspondence_state);
        if (isset($payload['paid_total'])) {
            update_post_meta($order_id, '_wonder_paid_total', $payload['paid_total']);
        }

        if (isset($payload['transactions']) && is_array($payload['transactions'])) {
            foreach ($payload['transactions'] as $transaction) {
                if (!empty($transaction['uuid'])) {
                    update_post_meta($order_id, '_wonder_transaction_id', $transaction['uuid']);
                    break;
                }
            }
        }

        $order_total = isset($payload['initial_total']) ? floatval($payload['initial_total']) : floatval($order->get_total());
        $refunded_amount = 0.0;
        if (isset($payload['transactions']) && is_array($payload['transactions'])) {
            foreach ($payload['transactions'] as $transaction) {
                if (isset($transaction['type']) && $transaction['type'] === 'Refund' && !empty($transaction['success'])) {
                    $refunded_amount += abs(floatval($transaction['amount']));
                }
                if (isset($transaction['refunded_amount'])) {
                    $refunded_amount = max($refunded_amount, floatval($transaction['refunded_amount']));
                }
            }
        }

        $logger->debug('Webhook refund amount summary', array(
            'source' => 'wonder-payments',
            'order_id' => $order_id,
            'order_total' => $order_total,
            'refunded_amount' => $refunded_amount
        ));

        $logger->debug('Webhook received status', array(
            'source' => 'wonder-payments',
            'order_id' => $order_id,
            'action' => $action,
            'state' => $state,
            'correspondence_state' => $correspondence_state
        ));

        if ($action === 'order.paid') {
            if ($correspondence_state === 'paid' || $state === 'completed') {
                $order->payment_complete();
                /* translators: Order note when payment is completed via Wonder Payments */
                $order->add_order_note(__('Wonder Payments payment completed (webhook).', 'wonder-payments'));
                $logger->debug('Webhook update order to completed', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id
                ));
            } elseif ($correspondence_state === 'partial_paid') {
                $order->update_status('on-hold', __('Wonder Payments partial payment received.', 'wonder-payments'));
                $logger->debug('Webhook update order to partially paid', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id
                ));
            }
        } elseif ($action === 'order.refunded') {
            if ($state === 'refunded') {
                $order->update_status('refunded', __('Wonder Payments order refunded.', 'wonder-payments'));
                $logger->debug('Webhook update order to refunded', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id
                ));
            } elseif ($correspondence_state === 'partial_paid') {
                $order->update_status('completed', __('Wonder Payments order partially refunded.', 'wonder-payments'));
                $logger->debug('Webhook update order to partial refund', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id
                ));
            } elseif ($order_total > 0 && $refunded_amount >= $order_total) {
                $order->update_status('refunded', __('Wonder Payments order refunded.', 'wonder-payments'));
                $logger->debug('Webhook update order to refunded (amount fallback)', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id
                ));
            } else {
                $order->update_status('completed', __('Wonder Payments order partially refunded.', 'wonder-payments'));
                $logger->debug('Webhook update order to partial refund', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id
                ));
            }
        } elseif ($action === 'order.payment_failure') {
            $order->update_status('failed', __('Wonder Payments payment failed.', 'wonder-payments'));
            $logger->debug('Webhook update order to failed', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id
            ));
        } elseif ($action === 'order.voided' || $action === 'transaction.voided') {
            $order->update_status('cancelled', __('Wonder Payments order voided.', 'wonder-payments'));
            $logger->debug('Webhook update order to cancelled', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id
            ));
        } elseif ($action === 'order.created') {
            // Note.
            $logger->debug('Webhook order created event', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id
            ));
        }

        // Return success response
        status_header(200);
        echo json_encode(array('status' => 'ok'));
        exit;
    }

    /**
     * Normalize webhook public key into PEM format when possible.
     */
    private function normalize_webhook_public_key($key)
    {
        if (empty($key)) {
            return '';
        }

        $key = trim($key);
        if (strpos($key, 'BEGIN PUBLIC KEY') !== false) {
            return $key;
        }

        $decoded = base64_decode($key, true);
        if ($decoded && strpos($decoded, 'BEGIN PUBLIC KEY') !== false) {
            return trim($decoded);
        }

        if (ctype_xdigit($key) && strlen($key) % 2 === 0) {
            $binary = hex2bin($key);
            if ($binary === false) {
                return '';
            }
            $key = base64_encode($binary);
        }

        $key = preg_replace('/\s+/', '', $key);
        if ($key === '') {
            return '';
        }

        $wrapped = trim(chunk_split($key, 64, "\n"));
        return "-----BEGIN PUBLIC KEY-----\n" . $wrapped . "\n-----END PUBLIC KEY-----\n";
    }

    private function mask_credential($value)
    {
        $value = (string) $value;
        $length = strlen($value);
        if ($length <= 8) {
            return $value;
        }
        return substr($value, 0, 4) . '...' . substr($value, -4);
    }

    /**
     * Clear cart after successful payment
     */
    public function clear_cart_after_payment($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'wonder_payments') {
            return;
        }

        if (!$order->is_paid()) {
            return;
        }

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
    }

    /**
     * Add status sync button on order details
     */
    public function add_order_sync_button($order) {
        // Only show the button for Wonder Payments orders
        if ($order->get_payment_method() !== 'wonder_payments') {
            return;
        }

        $order_id = $order->get_id();
        $nonce = wp_create_nonce('wonder_sync_order_status_' . $order_id);
        ?>
        <div style="margin: 15px 0; clear: both;">
            <button type="button" class="button button-secondary" id="wonder-sync-status-btn" data-order-id="<?php echo esc_attr($order_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" style="margin-top: 15px;">
                <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>Sync Wonder Payments Status
            </button>
            <span id="wonder-sync-status-message" style="margin-left: 10px; font-size: 12px;"></span>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#wonder-sync-status-btn').on('click', function() {
                    var $btn = $(this);
                    var orderId = $btn.data('order-id');
                    var nonce = $btn.data('nonce');
                    var $message = $('#wonder-sync-status-message');

                    $btn.prop('disabled', true);
                    $message.text('Syncing...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wonder_sync_order_status',
                            order_id: orderId,
                            security: nonce
                        },
                        success: function(response) {
                            console.log('Wonder Payments Sync response:', response);
                            if (response.success) {
                                $message.text('Sync successful!').css('color', 'green');
                                console.log('Wonder Payments Preparing to reload page...');
                                setTimeout(function() {
                                    console.log('Wonder Payments Reloading...');
                                    location.reload();
                                }, 1500);
                            } else {
                                $message.text('Sync failed: ' + (response.data || 'Unknown error')).css('color', 'red');
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function(xhr, status, error) {
                            $message.text('Sync failed: ' + error).css('color', 'red');
                            $btn.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX handler for order status sync
     */
    public function ajax_sync_order_status() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $logger = $this->get_logger();

        if (!$order_id) {
            $logger->error('Order sync failed: invalid order ID', array( 'source' => 'wonder-payments' ));
            wp_send_json_error('Invalid order ID');
        }

        // Verify nonce
        if (!check_ajax_referer('wonder_sync_order_status_' . $order_id, 'security', false)) {
            $logger->error('Order sync failed: nonce verification failed', array( 'source' => 'wonder-payments', 'order_id' => $order_id ));
            wp_send_json_error('Security verification failed');
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            $logger->error('Order sync failed: insufficient permissions', array( 'source' => 'wonder-payments', 'order_id' => $order_id ));
            wp_send_json_error('Insufficient permissions');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->error('Order sync failed: order not found', array( 'source' => 'wonder-payments', 'order_id' => $order_id ));
            wp_send_json_error('Order not found');
        }

        // Only handle Wonder Payments orders
        if ($order->get_payment_method() !== 'wonder_payments') {
            $logger->error('Order sync failed: not a Wonder Payments order', array( 'source' => 'wonder-payments', 'order_id' => $order_id ));
            wp_send_json_error('This is not a Wonder Payments order');
        }

        // Get reference_number
        $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
        if (empty($reference_number)) {
            $logger->error('Order sync failed: missing reference_number', array( 'source' => 'wonder-payments', 'order_id' => $order_id ));
            wp_send_json_error('Wonder Payments reference number not found');
        }

        // Check configuration
        if (empty($this->app_id) || empty($this->private_key)) {
            $logger->error('Order sync failed: configuration incomplete', array( 'source' => 'wonder-payments', 'order_id' => $order_id ));
            wp_send_json_error('Wonder Payments configuration is incomplete');
        }

        try {
            // Note.
            $webhook_key = !empty($this->webhook_public_key) ? $this->webhook_public_key : null;
            $options = array(
                'appid' => $this->app_id,
                'signaturePrivateKey' => $this->private_key,
                'webhookVerifyPublicKey' => $webhook_key ?: '',
                'callback_url' => '',
                'redirect_url' => '',
                'environment' => $this->get_environment(),
                'skipSignature' => false
            );

            // Note.
            $request_id = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                wp_rand(0, 0xffff), wp_rand(0, 0xffff),
                wp_rand(0, 0xffff),
                wp_rand(0, 0x0fff) | 0x4000,
                wp_rand(0, 0x3fff) | 0x8000,
                wp_rand(0, 0xffff), wp_rand(0, 0xffff), wp_rand(0, 0xffff)
            );
            $options['request_id'] = $request_id;

            $sdk = new PaymentSDK($options);

            $logger->debug('Order sync - credentials snapshot', array(
                'source' => 'wonder-payments',
                'app_id' => $this->mask_credential($this->app_id),
                'environment' => $this->get_environment()
            ));

            // Query order status
            $query_params = array(
                'order' => array(
                    'reference_number' => $reference_number
                )
            );

            $logger->debug('Order sync - request params', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id,
                'reference_number' => $reference_number,
                'environment' => $this->get_environment()
            ));

            $response = $sdk->queryOrder($query_params);

            // Log full API response
            $logger->debug('Order sync - API response', array(
                'source' => 'wonder-payments',
                'order_id' => $order_id,
                'response' => $response
            ));

            // Check response
            if (!isset($response['data']['order'])) {
                $logger->error('Order sync failed: invalid API response format', array(
                    'source' => 'wonder-payments',
                    'order_id' => $order_id,
                    'response' => $response
                ));
                wp_send_json_error('Invalid API response format');
            }

            $wonder_order = $response['data']['order'];
            $wonder_state = isset($wonder_order['state']) ? strtolower($wonder_order['state']) : '';
            $wonder_correspondence_state = isset($wonder_order['correspondence_state']) ? strtolower($wonder_order['correspondence_state']) : '';

            // Update WooCommerce order status based on Wonder Payments status
            $wc_status = $order->get_status();
            $updated = false;

            $logger->debug('Order sync - Wonder state: ' . $wonder_state, array( 'source' => 'wonder-payments' ));
            $logger->debug('Order sync - Wonder correspondence state: ' . $wonder_correspondence_state, array( 'source' => 'wonder-payments' ));
            $logger->debug('Order sync - WooCommerce current status: ' . $wc_status, array( 'source' => 'wonder-payments' ));

            // Check if there are refund transactions
            $has_refund = false;
            $refunded_amount = 0;
            $order_total = floatval($wonder_order['initial_total']);

            if (isset($wonder_order['transactions']) && !empty($wonder_order['transactions'])) {
                foreach ($wonder_order['transactions'] as $transaction) {
                    if (isset($transaction['type']) && $transaction['type'] === 'Refund' && isset($transaction['success']) && $transaction['success']) {
                        $has_refund = true;
                        $refunded_amount += abs(floatval($transaction['amount']));
                    }
                }

                // Also read refunded_amount from the first transaction
                if (isset($wonder_order['transactions'][0]['refunded_amount'])) {
                    $refunded_amount = floatval($wonder_order['transactions'][0]['refunded_amount']);
                }
            }

            $logger->debug('Order sync - Has refund: ' . ($has_refund ? 'yes' : 'no'), array( 'source' => 'wonder-payments' ));
            $logger->debug('Order sync - Refunded amount: ' . $refunded_amount, array( 'source' => 'wonder-payments' ));
            $logger->debug('Order sync - Order total: ' . $order_total, array( 'source' => 'wonder-payments' ));

            // Status mapping
            if ($has_refund) {
                if ($refunded_amount >= $order_total) {
                    // Full refund
                    if ($wc_status !== 'refunded') {
                        $logger->debug('Order sync - Preparing to update to refunded', array( 'source' => 'wonder-payments' ));
                        $logger->debug('Order sync - Order ID: ' . $order_id, array( 'source' => 'wonder-payments' ));
                        $logger->debug('Order sync - Current status: ' . $wc_status, array( 'source' => 'wonder-payments' ));
                        $logger->debug('Order sync - Target status: refunded', array( 'source' => 'wonder-payments' ));

                        $result = $order->update_status('refunded', sprintf('Synced from Wonder Payments: refunded %.2f', $refunded_amount));
                        $logger->debug('Order sync - update_status result: ' . ($result ? 'success' : 'failed'), array( 'source' => 'wonder-payments' ));

                        $updated = true;
                    }
                } else {
                    // Partial refund
                    if ($wc_status !== 'completed') {
                        $logger->debug('Order sync - Update to completed(Partial refund)', array( 'source' => 'wonder-payments' ));
                        $order->update_status('completed', sprintf('Synced from Wonder Payments: completed (partial refund %.2f)', $refunded_amount));
                        $updated = true;
                    }
                }
            } else if (in_array($wonder_state, array('in_completed', 'completed'), true) || $wonder_correspondence_state === 'paid') {
                // Paid
                if ($wc_status !== 'completed') {
                    $logger->debug('Order sync - Update to completed', array( 'source' => 'wonder-payments' ));
                    $order->update_status('completed', 'Synced from Wonder Payments: paid');
                    $updated = true;
                }
            } else if (in_array($wonder_state, array('in_cancelled', 'cancelled'), true)) {
                // Cancelled
                if ($wc_status !== 'cancelled') {
                    $logger->debug('Order sync - Update to cancelled', array( 'source' => 'wonder-payments' ));
                    $order->update_status('cancelled', 'Synced from Wonder Payments: cancelled');
                    $updated = true;
                }
            } else if (in_array($wonder_state, array('in_failed', 'failed'), true)) {
                // Payment failed
                if ($wc_status !== 'failed') {
                    $logger->debug('Order sync - Update to failed', array( 'source' => 'wonder-payments' ));
                    $order->update_status('failed', 'Synced from Wonder Payments: payment failed');
                    $updated = true;
                }
            } else {
                $logger->debug('Order sync - No status update needed', array( 'source' => 'wonder-payments' ));
            }

            // If refunded, update refund amount
            if ($has_refund) {
                $logger->debug('Order sync - Update refunded amount: ' . $refunded_amount, array( 'source' => 'wonder-payments' ));
                update_post_meta($order_id, '_wonder_refunded_amount', $refunded_amount);
            }

            if ($updated) {
                $new_status = $has_refund ? 'refunded' : $wonder_state;
                wp_send_json_success(array(
                    'message' => sprintf('Order status updated from %s to %s', $wc_status, $new_status)
                ));
            } else {
                wp_send_json_success(array(
                    'message' => 'Order status is already up to date'
                ));
            }

        } catch (Exception $e) {
            $logger = $this->get_logger();
            $logger->error('Order sync exception: ' . $e->getMessage(), array( 'source' => 'wonder-payments' ));
            wp_send_json_error('Sync failed: ' . $e->getMessage());
        }
    }
}
