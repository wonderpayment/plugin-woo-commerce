<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/class-wonder-payments-result-handler.php';
require_once dirname(__FILE__) . '/html-payment-processing-page.php';

class WC_Wonder_Payments_Gateway extends WC_Payment_Gateway
{

    public $app_id;
    public $private_key;
    public $generated_public_key; // 新增：由私钥生成的公钥
    public $webhook_public_key; // 从Portal获取的Webhook公钥
    public $title;
    public $description;
    public $enabled;

    /**
     * 获取 WooCommerce Logger 实例
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

        // 声明支持的功能
        $this->supports = array(
                'products',
                'refunds'
        );

        // 初始化表单字段
        $this->init_form_fields();

        // 初始化设置（这会从数据库加载已保存的配置）
        $this->init_settings();

        // 获取设置值
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->app_id = $this->get_option('app_id');
        $this->private_key = $this->get_option('private_key');
        $this->generated_public_key = $this->get_option('generated_public_key'); // 新增
        $this->webhook_public_key = $this->get_option('webhook_public_key');
        $this->environment = $this->get_option('environment'); // 新增环境配置
        $this->due_date = $this->get_option('due_date'); // 新增付款截止天数

        // 设置 testmode 属性：Sandbox Mode 关闭时显示测试模式，开启时显示激活
        $this->testmode = ($this->environment === 'no');

        // 保存设置
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Webhook 处理
        add_action('woocommerce_api_wonder_payments_webhook', array($this, 'handle_webhook'));

        // 注册AJAX处理
        add_action('wp_ajax_wonder_payments_check_status', array($this, 'ajax_check_payment_status'));
        add_action('wp_ajax_nopriv_wonder_payments_check_status', array($this, 'ajax_check_payment_status'));

        // 支付返回处理
        add_action('woocommerce_api_wc_gateway_wonder_payments', array($this, 'handle_payment_return'));

        // 注册生成密钥对的AJAX处理
        add_action('wp_ajax_wonder_generate_keys', array($this, 'ajax_generate_keys'));

        // 在订单详情页和感谢页面自动检查支付状态
        add_action('woocommerce_order_details_after_order_table', array($this, 'auto_check_payment_status'), 10, 1);
        add_action('woocommerce_thankyou', array($this, 'auto_check_payment_status'), 10, 1);
        add_action('woocommerce_view_order', array($this, 'auto_check_payment_status'), 10, 1);
        add_action('wp_head', array($this, 'auto_check_on_page_load'), 10);

        // 添加订单状态同步按钮
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'add_order_sync_button'), 20, 1);
        add_action('wp_ajax_wonder_sync_order_status', array($this, 'ajax_sync_order_status'));
    }

    public function init_form_fields()
    {
        // 获取当前环境配置并记录日志
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
            // 隐藏字段，用于存储私钥
                'private_key' => array(
                        'type' => 'hidden',
                ),
            // 隐藏字段，用于存储生成的公钥
                'generated_public_key' => array(
                        'type' => 'hidden',
                ),
            // Webhook公钥字段 - 从Portal获取
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
            // 只输出基本字段（App ID之前的字段）
            $basic_fields = array('enabled', 'environment', 'title', 'description', 'app_id', 'due_date');
            foreach ($basic_fields as $field) {
                if (isset($this->form_fields[$field])) {
                    $this->generate_settings_html(array($field => $this->form_fields[$field]));
                }
            }
            ?>
        </table>

        <!-- 输出隐藏字段 -->
        <?php
        if (isset($this->form_fields['private_key'])) {
            $this->generate_settings_html(array('private_key' => $this->form_fields['private_key']));
        }
        if (isset($this->form_fields['generated_public_key'])) {
            $this->generate_settings_html(array('generated_public_key' => $this->form_fields['generated_public_key']));
        }
        ?>

            <!-- 密钥对显示区域 -->
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

        <!-- 继续输出Webhook Public Key字段 -->
        <table class="form-table" style="margin-top: 30px;">
            <?php
            // 输出Webhook Public Key字段
            if (isset($this->form_fields['webhook_public_key'])) {
                $this->generate_settings_html(array('webhook_public_key' => $this->form_fields['webhook_public_key']));
            }

            // 输出隐藏字段
            if (isset($this->form_fields['private_key'])) {
                $this->generate_settings_html(array('private_key' => $this->form_fields['private_key']));
            }
            if (isset($this->form_fields['generated_public_key'])) {
                $this->generate_settings_html(array('generated_public_key' => $this->form_fields['generated_public_key']));
            }
            ?>
        </table>

        <!-- Test Configuration 按钮 -->
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

            /* 隐藏隐藏字段的行 */
            #woocommerce_wonder_payments_private_key_field,
            #woocommerce_wonder_payments_generated_public_key_field {
                display: none !important;
            }
        </style>

        <script>
            jQuery(document).ready(function ($) {
                // 检查是否有已保存的私钥，如果有则显示
                var savedPrivateKey = '<?php echo esc_js($private_key); ?>';
                var savedGeneratedPublicKey = '<?php echo esc_js($generated_public_key); ?>';

                if (savedPrivateKey) {
                    $('#wonder-private-key-display').val(savedPrivateKey);
                }

                if (savedGeneratedPublicKey) {
                    $('#wonder-generated-public-key-display').val(savedGeneratedPublicKey);
                }

                // 生成密钥对按钮点击事件
                $(document).on('click', '#wonder-generate-keys', function (e) {
                    e.preventDefault();

                    var $button = $(this);
                    var $spinner = $(this).siblings('.spinner');
                    var $message = $('#wonder-generate-message');
                    var $privateKeyDisplay = $('#wonder-private-key-display');
                    var $publicKeyDisplay = $('#wonder-generated-public-key-display');

                    // 显示加载状态
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
                                // 显示成功消息
                                $message.html('<span style="color: green;">✅ ' + response.data.message + '</span>').addClass('success');

                                // 填充私钥和公钥到显示区域
                                $privateKeyDisplay.val(response.data.private_key);
                                $publicKeyDisplay.val(response.data.public_key);

                                // 更新隐藏的表单字段
                                $('input[name="woocommerce_wonder_payments_private_key"]').val(response.data.private_key);
                                $('input[name="woocommerce_wonder_payments_generated_public_key"]').val(response.data.public_key);
                            } else {
                                // 显示错误消息
                                $message.html('<span style="color: red;">❌ ' + response.data.message + '</span>').addClass('error');
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('Generate keys error:', error);
                            $message.html('<span style="color: red;">❌ <?php echo esc_js(__('Error:', 'wonder-payments')); ?> ' + error + '</span>').addClass('error');
                        },
                        complete: function () {
                            // 恢复按钮状态
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
     * AJAX: 生成RSA密钥对
     */
    public function ajax_generate_keys()
    {
        // 记录日志
        $logger = $this->get_logger();
        $logger->debug('Generate RSA Keys method called', array( 'source' => 'wonder-payments' ));

        check_ajax_referer('wonder_generate_keys', 'security');

        // 获取当前环境配置
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $sandbox_enabled = isset($settings['environment']) ? $settings['environment'] : 'no';

        // 根据Sandbox Mode判断环境
        $environment = ($sandbox_enabled === 'yes') ? 'prod' : 'stg';
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        try {
            // 生成RSA 4096位密钥对
            $config = array(
                    "digest_alg" => "sha256",
                    "private_key_bits" => 4096,
                    "private_key_type" => OPENSSL_KEYTYPE_RSA,
            );

            // 生成密钥对
            $key_pair = openssl_pkey_new($config);

            if (!$key_pair) {
                throw new Exception('Failed to generate RSA key pair');
            }

            // 获取私钥
            openssl_pkey_export($key_pair, $private_key);
            // 获取公钥
            $key_details = openssl_pkey_get_details($key_pair);
            $public_key_pem = $key_details['key'];
            // 更新插件设置
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
            $logger->debug('准备返回成功响应', array( 'source' => 'wonder-payments' ));
            $logger->debug('响应数据: message=' . $response['message'] . ', private_key_length=' . strlen($private_key) . ', public_key_length=' . strlen($public_key_pem), array( 'source' => 'wonder-payments' ));

            wp_send_json_success($response);

        } catch (Exception $e) {
            $logger = $this->get_logger();
            $logger->error('Generate RSA Keys 请求结束 - 错误: ' . $e->getMessage(), array( 'source' => 'wonder-payments' ));
            wp_send_json_error(array(
                    'message' => $e->getMessage()
            ));
        }
    }

    /**
     * 检查支付网关是否可用
     *
     * @return bool
     */
    /**
         * 获取环境配置
         *
         * @return string 'stg' 或 'prod'
         */
        public function get_environment() {
            // Sandbox 关闭时使用 stg，开启时使用 prod
            return $this->environment === 'yes' ? 'prod' : 'stg';
        }        public function is_available() {
        $logger = $this->get_logger();
        $logger->debug('is_available() - enabled: ' . ($this->enabled ? 'yes' : 'no'), array( 'source' => 'wonder-payments' ));

        $is_available = parent::is_available();

        // 如果父类返回 false，直接返回
        if (!$is_available) {
            return false;
        }

        // 检查配置是否完整（app_id 和 private_key）
        $app_id_value = $this->app_id ? 'set' : 'empty';
        $private_key_value = $this->private_key ? 'set' : 'empty';
        $logger->debug('is_available() - app_id: ' . $app_id_value . ', private_key: ' . $private_key_value, array( 'source' => 'wonder-payments' ));

        if (empty($this->app_id) || empty($this->private_key)) {
            // 配置不完整，返回false，这样会显示"Action Needed"状态
            $logger->debug('is_available() - Configuration incomplete, returning false', array( 'source' => 'wonder-payments' ));
            return false;
        }

        $logger->debug('is_available() - Configuration complete, returning true', array( 'source' => 'wonder-payments' ));
        return true;
    }
    
    /**
     * 检查是否已配置 AppID
     */
    public function is_appid_configured() {
        return !empty($this->app_id);
    }
    
    /**
     * 获取自定义状态
     * 返回: 
     * 1. 启用 + 未配置 AppID → "需采取行动" (Action Needed)
     * 2. 启用 + 已配置 AppID → "已激活" (Activated)
     * 3. 禁用 → "未激活" (Inactive)
     */
    public function get_custom_status() {
        if ($this->enabled !== 'yes') {
            return array(
                'text' => __('未激活', 'wonder-payments'),
                'status' => 'inactive',
                'color' => '#a7aaad',
                'icon' => 'dashicons-dismiss'
            );
        }
        
        if (!$this->is_appid_configured()) {
            return array(
                'text' => __('需采取行动', 'wonder-payments'),
                'status' => 'action_needed',
                'color' => '#d63638',
                'icon' => 'dashicons-warning'
            );
        }
        
        return array(
            'text' => __('已激活', 'wonder-payments'),
            'status' => 'activated',
            'color' => '#00a32a',
            'icon' => 'dashicons-yes-alt'
        );
    }
    
    /**
     * 获取自定义状态 HTML
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

        // 记录当前环境配置
        $environment = $this->get_environment();
        $sandbox_enabled = $this->get_option('environment');
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        $logger = $this->get_logger();
        $logger->debug('API端点: ' . $api_endpoint, array( 'source' => 'wonder-payments' ));

        // 检查是否设置了必要参数
        if (empty($this->app_id) || empty($this->private_key)) {
            wc_add_notice(__('Payment gateway is not configured properly. Please check your App ID and Private Key.', 'wonder-payments'), 'error');
            return;
        }

        // 检查SDK是否存在
        if (!class_exists('PaymentSDK')) {
            wc_add_notice(__('Payment gateway SDK is not available. Please ensure the SDK files are properly included.', 'wonder-payments'), 'error');
            return;
        }

        try {
            // 构建回调URL和重定向URL
            $callback_url = WC()->api_request_url('wonder_payments_webhook');

            // 重定向URL应该指向支付返回处理页面，而不是感谢页面
            $redirect_url = add_query_arg(array(
                    'order_id' => $order_id,
                    'order_key' => $order->get_order_key()
            ), WC()->api_request_url('wc_gateway_wonder_payments'));

            // 验证私钥格式
            $privateKey = trim($this->private_key);
            if (empty($privateKey)) {
                wc_add_notice(__('Private key is not set. Please configure your payment gateway.', 'wonder-payments'), 'error');
                return;
            }

            $privateKeyId = openssl_pkey_get_private($privateKey);
            if (!$privateKeyId) {
                wc_add_notice(__('Invalid private key format. Please reconfigure your payment gateway.', 'wonder-payments'), 'error');
                return;
            }
            openssl_pkey_free($privateKeyId);

            // 初始化SDK
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

// 生成唯一的 request_id (随机 UUID)
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
                return;
            }

            // 构建订单数据
            $order_currency = $order->get_currency();
            $supported_currencies = array('HKD', 'USD', 'EUR', 'GBP');
            $api_currency = in_array($order_currency, $supported_currencies) ? $order_currency : 'HKD';

            // 生成唯一的 reference_number：订单号-递增数字-时间戳
            // 从订单元数据中获取当前的递增数字，如果不存在则从1开始
            $sequence_number = get_post_meta($order_id, '_wonder_payment_sequence', true);
            if (empty($sequence_number)) {
                $sequence_number = 1;
            } else {
                $sequence_number = intval($sequence_number) + 1;
            }
            update_post_meta($order_id, '_wonder_payment_sequence', $sequence_number);

            $reference_number = $order->get_order_number() . '-' . $sequence_number . '-' . time();

            // 获取配置的付款截止天数，默认为30天
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

                // 使用SDK创建支付链接（每次都创建新的订单，不使用缓存）
                $response = $sdk->createPaymentLink($order_data);
                // 记录完整响应用于调试
                $logger->debug('API Response received', array( 'source' => 'wonder-payments' ));
                // 检查响应并获取支付链接
                $payment_link = null;
                if (isset($response['data']['payment_link']) && !empty($response['data']['payment_link'])) {
                    $payment_link = $response['data']['payment_link'];
                } elseif (isset($response['payment_link']) && !empty($response['payment_link'])) {
                    $payment_link = $response['payment_link'];
                }
                if ($payment_link) {
                    // 保存支付链接和参考号到订单元数据（缓存）
                    update_post_meta($order_id, '_wonder_payment_link', $payment_link);
                    update_post_meta($order_id, '_wonder_reference_number', $reference_number);
                    // 保存 request_id 用于追踪
                    update_post_meta($order_id, '_wonder_request_id', $request_id);
                    // 保存交易ID（如果有）
                    if (isset($response['data']['id'])) {
                        update_post_meta($order_id, '_wonder_transaction_id', $response['data']['id']);
                    }
                    // 更新订单状态为待支付
                    $order->update_status('pending', __('Awaiting Wonder Payments payment', 'wonder-payments'));
                    // 重定向到支付链接
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
                    return;
                }
        } catch (Exception $e) {
            /* translators: %s: error message */
            wc_add_notice(sprintf(__('Payment processing failed: %s', 'wonder-payments'), $e->getMessage()), 'error');
            return;
        }
    }

    /**
     * 处理退款请求
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        // 验证必要参数
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
            // 检查SDK是否存在
            if (!class_exists('PaymentSDK')) {
                return new WP_Error('error', __('PaymentSDK not available', 'wonder-payments'));
            }

            // 获取订单参考号
            $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
            if (empty($reference_number)) {
                return new WP_Error('error', __('Order reference number not found', 'wonder-payments'));
            }

            // 获取Wonder Payments订单号
            $wonder_order_number = get_post_meta($order_id, '_wonder_order_number', true);
            if (empty($wonder_order_number)) {
                return new WP_Error('error', __('Wonder Payments order number not found', 'wonder-payments'));
            }

            // 获取支付交易UUID（退款需要使用原来的支付交易UUID）
            $payment_transaction_uuid = get_post_meta($order_id, '_wonder_transaction_id', true);
            if (empty($payment_transaction_uuid)) {
                return new WP_Error('error', __('Payment transaction UUID not found', 'wonder-payments'));
            }

            $logger = $this->get_logger();
            $logger->debug('使用支付交易UUID进行退款: ' . $payment_transaction_uuid, array( 'source' => 'wonder-payments' ));

            // 获取订单货币
            $order_currency = $order->get_currency();
            $supported_currencies = array('HKD', 'USD', 'EUR', 'GBP');
            $api_currency = in_array($order_currency, $supported_currencies) ? $order_currency : 'HKD';

// 初始化SDK
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

            // 生成唯一的 request_id (随机 UUID)
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
            $logger->debug('查询订单响应 received', array( 'source' => 'wonder-payments' ));

            // 检查查询到的订单是否与退款订单匹配
            if (isset($query_response['data']['order'])) {
                $queried_order_number = $query_response['data']['order']['number'];
                $queried_reference_number = $query_response['data']['order']['reference_number'];
            }
            
            // 检查是否允许退款（从transactions数组中获取）
            $allow_refund = false;
            if (isset($query_response['data']['order']['transactions']) && !empty($query_response['data']['order']['transactions'])) {
                $allow_refund = isset($query_response['data']['order']['transactions'][0]['allow_refund']) && $query_response['data']['order']['transactions'][0]['allow_refund'] == 1;
            }
            
            if (!$allow_refund) {
                return new WP_Error('error', __('This order does not allow refunds', 'wonder-payments'));
            }
            
            // 获取已退款金额（从transactions数组中获取）
            $refunded_amount = 0;
            if (isset($query_response['data']['order']['transactions'][0]['refunded_amount'])) {
                $refunded_amount = floatval($query_response['data']['order']['transactions'][0]['refunded_amount']);
            }
            $order_total = floatval($query_response['data']['order']['initial_total']);
            $available_refund = $order_total - $refunded_amount;

            $logger->debug('退款验证 - 订单总额: ' . $order_total, array( 'source' => 'wonder-payments' ));
            $logger->debug('退款验证 - 已退款金额: ' . $refunded_amount, array( 'source' => 'wonder-payments' ));
            $logger->debug('退款验证 - 可退款金额: ' . $available_refund, array( 'source' => 'wonder-payments' ));
            $logger->debug('退款验证 - 请求退款金额: ' . $amount, array( 'source' => 'wonder-payments' ));

            // 验证退款金额（使用小的容差值避免浮点数精度问题）
            $epsilon = 0.01; // 容差值
            if ($amount - $available_refund > $epsilon) {
                return new WP_Error('error',
                    sprintf(
                        /* translators: %1$s: available amount, %2$s: requested amount */
                        __('Refund amount exceeds available amount. Available: %1$s, Requested: %2$s', 'wonder-payments'),
                        wc_price($available_refund),
                        wc_price($amount)
                    )
                );
            }

            // 步骤2: 使用支付交易UUID进行退款
            $payment_transaction_uuid = get_post_meta($order_id, '_wonder_transaction_id', true);
            if (empty($payment_transaction_uuid)) {
                return new WP_Error('error', __('Payment transaction UUID not found', 'wonder-payments'));
            }

            // 步骤3: 构建退款参数（使用支付交易UUID）
            $refund_params = array(
                    'order' => array(
                            'number' => $wonder_order_number,          // 订单标识不变
                            'reference_number' => $reference_number     // 订单标识不变
                    ),
                    'transaction' => array(
                            'uuid' => $payment_transaction_uuid        // 使用支付交易UUID
                    ),
                    'refund' => array(
                            'amount' => number_format($amount, 2, '.', ''),
                            'currency' => $api_currency,
                            'reason' => $reason ? $reason : 'Refund via WooCommerce'
                    )
            );

            // 步骤4: 调用SDK的refundTransaction方法
            $response = $sdk->refundTransaction($refund_params);

            $logger->debug('退款响应 received', array( 'source' => 'wonder-payments' ));

            // 检查响应
            if (isset($response['code']) && $response['code'] == 200) {
                // 退款成功
                $order->add_order_note(
                        sprintf(
                                /* translators: %1$s: refund amount, %2$s: refund reason */
                                __('Wonder Payments refund successful. Amount: %1$s, Reason: %2$s', 'wonder-payments'),
                                wc_price($amount),
                                $reason ? $reason : 'N/A'
                        )
                );

                // 保存退款ID（如果有）
                if (isset($response['data']['order']['transactions'])) {
                    $transactions = $response['data']['order']['transactions'];
                    foreach ($transactions as $transaction) {
                        if (isset($transaction['type']) && $transaction['type'] === 'Refund') {
                            update_post_meta($order_id, '_wonder_refund_id', $transaction['uuid']);
                            break;
                        }
                    }
                }

                // 更新退款记录的Post状态为wc-completed
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

                // 步骤5: 根据退款金额决定订单状态
                $already_refunded = $order->get_total_refunded();
                $order_total = $order->get_total();

                // 检查退款记录的状态
                $refunds = $order->get_refunds();
                foreach ($refunds as $refund) {
                    $refund_id = $refund->get_id();
                    $refund_status = $refund->get_status();
                    $refund_post_status = get_post_status($refund_id);
                }
                
                if ($already_refunded >= $order_total) {
                    // 全额退款
                    $order->update_status('refunded', __('Order fully refunded via Wonder Payments', 'wonder-payments'));
                } else {
                    // 部分退款，保持 completed 状态
                    $order->update_status('completed', __('Order partially refunded via Wonder Payments', 'wonder-payments'));
                }
                
                return true;
            } else {
                // 退款失败
                $error_message = isset($response['message']) ? $response['message'] : 'Unknown error';
                if (isset($response['error_code'])) {
                    $error_message .= ' (' . $response['error_code'] . ')';
                }

                // 检查错误码 100701，显示自定义错误提示
                if (isset($response['code']) && $response['code'] == 100701) {
                    $error_message = 'Refund failed, duplicate transaction. Refunds of the same amount are not allowed within five minutes.';
                }

                $order->add_order_note(
                        sprintf(
                                /* translators: %1$s: refund amount, %2$s: error message */
                                __('Wonder Payments refund failed. Amount: %1$s, Error: %2$s', 'wonder-payments'),
                                wc_price($amount),
                                $error_message
                        )
                );

                return new WP_Error('refund_error', $error_message);
            }
        } catch (Exception $e) {
            return new WP_Error('error', $e->getMessage());
        }
    }

    public function handle_webhook()
    {
        // 获取原始数据
        $raw_data = file_get_contents('php://input');
        $data = json_decode($raw_data, true);

        if (!$data) {
            wp_die('Invalid webhook data', 'Wonder Payments', array('response' => 400));
        }

        // 验证签名
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $signature = isset($_SERVER['HTTP_SIGNATURE']) ? wp_unslash($_SERVER['HTTP_SIGNATURE']) : '';
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $credential = isset($_SERVER['HTTP_CREDENTIAL']) ? wp_unslash($_SERVER['HTTP_CREDENTIAL']) : '';
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - HTTP headers should not be sanitized as they are used for webhook verification
        $nonce = isset($_SERVER['HTTP_NONCE']) ? wp_unslash($_SERVER['HTTP_NONCE']) : '';

        $is_valid = false;

        // 检查并初始化验证SDK
        if (!class_exists('PaymentSDK')) {
            wp_die('SDK not available for webhook verification', 'Wonder Payments', array('response' => 403));
        }

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

        $sdk = new PaymentSDK($options);

        if (!$sdk) {
            wp_die('SDK not available for webhook verification', 'Wonder Payments', array('response' => 403));
        }

        try {
            $is_valid = $sdk->verifyWebhook($data, $signature, $credential, $nonce);
        } catch (Exception $e) {
        }

        if (!$is_valid) {
            wp_die('Invalid signature', 'Wonder Payments', array('response' => 403));
        }

        // 处理支付结果
        $order_id = isset($data['order_id']) ? intval($data['order_id']) : 0;
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_die('Order not found', 'Wonder Payments', array('response' => 404));
        }

        $status = isset($data['status']) ? $data['status'] : '';

        if ($status === 'success' || $status === 'completed' || $status === 'paid') {
            $transaction_id = isset($data['transaction_id']) ? $data['transaction_id'] : '';
            $order->payment_complete($transaction_id);
            /* translators: Order note when payment is completed via Wonder Payments */
            $order->add_order_note(__('Wonder Payments payment completed.', 'wonder-payments'));
        } else {
            /* translators: Order status when payment fails via Wonder Payments */
            $order->update_status('failed', __('Wonder Payments payment failed.', 'wonder-payments'));
        }

        // 返回成功响应
        status_header(200);
        echo json_encode(array('status' => 'ok'));
        exit;
    }

    /**
     * AJAX: 检查支付状态
     */
    public function ajax_check_payment_status()
    {
        try {
            check_ajax_referer('wonder_payments_check_status', 'nonce');
        } catch (Exception $e) {
            wp_send_json(array(
                    'success' => false,
                    'message' => '安全验证失败',
                    'status' => 'error'
            ));
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';

        if (!$order_id || !$order_key) {
            wp_send_json(array(
                    'success' => false,
                    'message' => '订单信息不完整',
                    'status' => 'error'
            ));
            return;
        }

        try {
            $handler = new WC_Wonder_Payments_Result_Handler($this);
            $result = $handler->check_payment_status($order_id, $order_key);
            wp_send_json($result);

        } catch (Exception $e) {

            wp_send_json(array(
                    'success' => false,
                    'message' => '服务器处理错误: ' . $e->getMessage(),
                    'status' => 'error'
            ));
        }

    }

    /**
     * 在订单详情页和感谢页面自动检查支付状态
     */
    public function auto_check_payment_status($order_id) {
        $order = wc_get_order($order_id);

        // 只处理Wonder Payments的订单
        if (!$order || $order->get_payment_method() !== 'wonder_payments') {
            return;
        }

        // 只在订单状态为pending时检查
        if ($order->get_status() !== 'pending') {
            return;
        }

        // 检查是否已经有Wonder Payments的参考号
        $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
        if (empty($reference_number)) {
            return;
        }

        // 避免频繁检查（5分钟内只检查一次）
        $last_check = get_post_meta($order_id, '_wonder_last_status_check', true);
        if ($last_check && (time() - $last_check) < 300) {
            return;
        }

        try {
            $handler = new WC_Wonder_Payments_Result_Handler($this);
            $result = $handler->check_payment_status($order_id, $order->get_order_key());

            // 更新最后检查时间
            update_post_meta($order_id, '_wonder_last_status_check', time());

        } catch (Exception $e) {
        }
    }

    /**
     * 处理支付返回
     */
    public function handle_payment_return()
    {
        // 从URL参数获取订单信息
        // @codingStandardsIgnoreLine WordPress.Security.NonceVerification.Recommended - Nonce not required for payment return callback
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        // @codingStandardsIgnoreLine WordPress.Security.NonceVerification.Recommended - Nonce not required for payment return callback
        $order_key = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : '';

        if (!$order_id || !$order_key) {
            wp_die('Invalid request');
        }

        $order = wc_get_order($order_id);
        if (!$order || $order->get_order_key() !== $order_key) {
            wp_die('Invalid order');
        }

        // 调用结果处理器显示处理页面
        $handler = new WC_Wonder_Payments_Result_Handler($this);
        $handler->show_payment_processing_page($order_id, $order_key);
    }

    /**
     * 在页面加载时自动检查支付状态（通过JavaScript）
     */
    public function auto_check_on_page_load()
    {
        // 只在订单相关页面执行
        if (!is_order_received_page() && !is_wc_endpoint_url('view-order')) {
            return;
        }

        // 获取订单ID
        $order_id = 0;
        if (is_order_received_page()) {
            $order_id = absint(get_query_var('order-received'));
        } elseif (is_wc_endpoint_url('view-order')) {
            $order_id = absint(get_query_var('view-order'));
        }

        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);

        // 只处理Wonder Payments的pending订单
        if (!$order || $order->get_payment_method() !== 'wonder_payments' || $order->get_status() !== 'pending') {
            return;
        }

        // 检查是否已经有Wonder Payments的参考号
        $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
        if (empty($reference_number)) {
            return;
        }

        // 避免频繁检查（3分钟内只检查一次）
        $last_check = get_post_meta($order_id, '_wonder_last_status_check', true);
        if ($last_check && (time() - $last_check) < 180) {
            return;
        }

        // 输出JavaScript进行后台检查
        ?>
        <script type="text/javascript">
            (function() {
                console.log('Wonder Payments: 后台检查订单<?php echo esc_html($order_id); ?>支付状态...');

                const formData = new URLSearchParams();
                formData.append('action', 'wonder_payments_check_status');
                formData.append('order_id', <?php echo esc_js($order_id); ?>);
                formData.append('order_key', '<?php echo esc_js($order->get_order_key()); ?>');
                formData.append('nonce', '<?php echo esc_attr(wp_create_nonce('wonder_payments_check_status')); ?>');

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Wonder Payments: 支付状态检查结果', data);

                    if (data.success) {
                        // 支付成功，刷新页面
                        console.log('Wonder Payments: 检测到支付成功，刷新页面...');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                })
                .catch(error => {
                    console.error('Wonder Payments: 检查失败', error);
                });
            })();
        </script>
        <?php
    }

    /**
     * 在订单详情页添加同步状态按钮
     */
    public function add_order_sync_button($order) {
        // 只显示Wonder Payments订单的按钮
        if ($order->get_payment_method() !== 'wonder_payments') {
            return;
        }

        $order_id = $order->get_id();
        $nonce = wp_create_nonce('wonder_sync_order_status_' . $order_id);
        ?>
        <div style="margin: 15px 0; clear: both;">
            <button type="button" class="button button-secondary" id="wonder-sync-status-btn" data-order-id="<?php echo esc_attr($order_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" style="margin-top: 15px;">
                <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>同步Wonder Payments状态
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
                    $message.text('正在同步...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'wonder_sync_order_status',
                            order_id: orderId,
                            security: nonce
                        },
                        success: function(response) {
                            console.log('Wonder Payments 同步响应:', response);
                            if (response.success) {
                                $message.text('同步成功！').css('color', 'green');
                                console.log('Wonder Payments 准备刷新页面...');
                                setTimeout(function() {
                                    console.log('Wonder Payments 执行刷新...');
                                    location.reload();
                                }, 1500);
                            } else {
                                $message.text('同步失败: ' + (response.data || '未知错误')).css('color', 'red');
                                $btn.prop('disabled', false);
                            }
                        },
                        error: function(xhr, status, error) {
                            $message.text('同步失败: ' + error).css('color', 'red');
                            $btn.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX处理订单状态同步
     */
    public function ajax_sync_order_status() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error('无效的订单ID');
        }

        // 验证nonce
        if (!check_ajax_referer('wonder_sync_order_status_' . $order_id, 'security', false)) {
            wp_send_json_error('安全验证失败');
        }

        // 检查权限
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('权限不足');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('订单不存在');
        }

        // 只处理Wonder Payments订单
        if ($order->get_payment_method() !== 'wonder_payments') {
            wp_send_json_error('这不是Wonder Payments订单');
        }

        // 获取reference_number
        $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
        if (empty($reference_number)) {
            wp_send_json_error('未找到Wonder Payments参考号');
        }

        // 检查配置
        if (empty($this->app_id) || empty($this->private_key)) {
            wp_send_json_error('Wonder Payments配置不完整');
        }

        try {
            // 初始化SDK - 使用与退款功能相同的方式
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

            // 生成唯一的 request_id (随机 UUID) - 与退款功能保持一致
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

            // 查询订单状态
            $query_params = array(
                'order' => array(
                    'reference_number' => $reference_number
                )
            );

            $response = $sdk->queryOrder($query_params);

            // 打印完整的API响应
            $logger = $this->get_logger();
            $logger->debug('同步订单 - API响应 received', array( 'source' => 'wonder-payments' ));

            // 检查响应
            if (!isset($response['data']['order'])) {
                wp_send_json_error('API返回数据格式错误');
            }

            $wonder_order = $response['data']['order'];
            $wonder_state = isset($wonder_order['state']) ? $wonder_order['state'] : '';
            $wonder_correspondence_state = isset($wonder_order['correspondence_state']) ? $wonder_order['correspondence_state'] : '';

            // 根据Wonder Payments状态更新WooCommerce订单状态
            $wc_status = $order->get_status();
            $updated = false;

            $logger->debug('同步订单 - Wonder状态: ' . $wonder_state, array( 'source' => 'wonder-payments' ));
            $logger->debug('同步订单 - Wonder对应状态: ' . $wonder_correspondence_state, array( 'source' => 'wonder-payments' ));
            $logger->debug('同步订单 - WooCommerce当前状态: ' . $wc_status, array( 'source' => 'wonder-payments' ));

            // 检查是否有退款交易
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

                // 也从第一个交易获取refunded_amount
                if (isset($wonder_order['transactions'][0]['refunded_amount'])) {
                    $refunded_amount = floatval($wonder_order['transactions'][0]['refunded_amount']);
                }
            }

            $logger->debug('同步订单 - 是否有退款: ' . ($has_refund ? '是' : '否'), array( 'source' => 'wonder-payments' ));
            $logger->debug('同步订单 - 退款金额: ' . $refunded_amount, array( 'source' => 'wonder-payments' ));
            $logger->debug('同步订单 - 订单总额: ' . $order_total, array( 'source' => 'wonder-payments' ));

            // 状态映射
            if ($has_refund) {
                if ($refunded_amount >= $order_total) {
                    // 全额退款
                    if ($wc_status !== 'refunded') {
                        $logger->debug('同步订单 - 准备更新为已退款', array( 'source' => 'wonder-payments' ));
                        $logger->debug('同步订单 - 订单ID: ' . $order_id, array( 'source' => 'wonder-payments' ));
                        $logger->debug('同步订单 - 当前状态: ' . $wc_status, array( 'source' => 'wonder-payments' ));
                        $logger->debug('同步订单 - 目标状态: refunded', array( 'source' => 'wonder-payments' ));

                        $result = $order->update_status('refunded', sprintf('从Wonder Payments同步状态: 已退款 %.2f', $refunded_amount));
                        $logger->debug('同步订单 - update_status返回: ' . ($result ? 'success' : 'failed'), array( 'source' => 'wonder-payments' ));

                        $updated = true;
                    }
                } else {
                    // 部分退款
                    if ($wc_status !== 'completed') {
                        $logger->debug('同步订单 - 更新为已完成(部分退款)', array( 'source' => 'wonder-payments' ));
                        $order->update_status('completed', sprintf('从Wonder Payments同步状态: 已完成(部分退款 %.2f)', $refunded_amount));
                        $updated = true;
                    }
                }
            } else if ($wonder_state === 'in_completed' && $wonder_correspondence_state === 'paid') {
                // 已支付
                if ($wc_status !== 'completed') {
                    $logger->debug('同步订单 - 更新为已完成', array( 'source' => 'wonder-payments' ));
                    $order->update_status('completed', '从Wonder Payments同步状态: 已支付');
                    $updated = true;
                }
            } else if ($wonder_state === 'in_cancelled') {
                // 已取消
                if ($wc_status !== 'cancelled') {
                    $logger->debug('同步订单 - 更新为已取消', array( 'source' => 'wonder-payments' ));
                    $order->update_status('cancelled', '从Wonder Payments同步状态: 已取消');
                    $updated = true;
                }
            } else if ($wonder_state === 'in_failed') {
                // 支付失败
                if ($wc_status !== 'failed') {
                    $logger->debug('同步订单 - 更新为失败', array( 'source' => 'wonder-payments' ));
                    $order->update_status('failed', '从Wonder Payments同步状态: 支付失败');
                    $updated = true;
                }
            } else {
                $logger->debug('同步订单 - 状态无需更新', array( 'source' => 'wonder-payments' ));
            }

            // 如果订单已退款，更新退款金额
            if ($has_refund) {
                $logger->debug('同步订单 - 更新退款金额: ' . $refunded_amount, array( 'source' => 'wonder-payments' ));
                update_post_meta($order_id, '_wonder_refunded_amount', $refunded_amount);
            }

            if ($updated) {
                $new_status = $has_refund ? 'refunded' : $wonder_state;
                wp_send_json_success(array(
                    'message' => sprintf('订单状态已从 %s 更新为 %s', $wc_status, $new_status)
                ));
            } else {
                wp_send_json_success(array(
                    'message' => '订单状态已是最新的'
                ));
            }

        } catch (Exception $e) {
            $logger = $this->get_logger();
            $logger->error('同步订单状态异常: ' . $e->getMessage(), array( 'source' => 'wonder-payments' ));
            wp_send_json_error('同步失败: ' . $e->getMessage());
        }
    }
}