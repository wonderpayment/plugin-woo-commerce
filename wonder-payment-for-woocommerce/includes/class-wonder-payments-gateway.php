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

    public function __construct()
    {
        $this->id = 'wonder_payments';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Wonder Payments', 'wonder-payment-for-woocommerce');
        $this->method_description = __('Accept payments via Wonder Payments', 'wonder-payment-for-woocommerce');

        // 声明支持的功能
        $this->supports = array(
                'products',
                'refunds'
        );

        // 初始化设置
        $this->init_form_fields();
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
    }

    public function init_form_fields()
    {
        // 获取当前环境配置并记录日志
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $sandbox_enabled = isset($settings['environment']) ? $settings['environment'] : 'no';
        $environment = ($sandbox_enabled === 'yes') ? 'prod' : 'stg';
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        error_log('=== Wonder Payments 当前环境配置 ===');
        error_log('Sandbox Mode: ' . ($sandbox_enabled === 'yes' ? '开启' : '关闭'));
        error_log('当前环境: ' . $environment);
        error_log('API端点: ' . $api_endpoint);
        error_log('====================================');

        $this->form_fields = array(
                'enabled' => array(
                        'title' => __('Enable/Disable', 'wonder-payment-for-woocommerce'),
                        'label' => __('Enable Wonder Payments', 'wonder-payment-for-woocommerce'),
                        'type' => 'checkbox',
                        'default' => 'no'
                ),
                'environment' => array(
                        'title' => __('Sandbox Mode', 'wonder-payment-for-woocommerce'),
                        'label' => __('Enable Sandbox Mode', 'wonder-payment-for-woocommerce'),
                        'type' => 'checkbox',
                        'description' => __('When enabled, uses the staging environment. When disabled, uses the production environment.', 'wonder-payment-for-woocommerce'),
                        'default' => 'yes',
                        'desc_tip' => true
                ),
                'due_date' => array(
                        'title' => __('Payment Due Days', 'wonder-payment-for-woocommerce'),
                        'type' => 'number',
                        'description' => __('Number of days from order creation until payment is due. Default is 30 days.', 'wonder-payment-for-woocommerce'),
                        'default' => '30',
                        'desc_tip' => true,
                        'css' => 'width: 100px;',
                        'custom_attributes' => array(
                                'min' => '1',
                                'step' => '1'
                        )
                ),
                'title' => array(
                        'title' => __('Title', 'wonder-payment-for-woocommerce'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'wonder-payment-for-woocommerce'),
                        'default' => __('Wonder Payments', 'wonder-payment-for-woocommerce'),
                        'desc_tip' => true
                ),
                'description' => array(
                        'title' => __('Description', 'wonder-payment-for-woocommerce'),
                        'type' => 'textarea',
                        'description' => __('This controls the description which the user sees during checkout.', 'wonder-payment-for-woocommerce'),
                        'default' => __('Pay securely via Wonder Payments', 'wonder-payment-for-woocommerce'),
                        'desc_tip' => true
                ),
                'app_id' => array(
                        'title' => __('App ID', 'wonder-payment-for-woocommerce'),
                        'type' => 'text',
                        'description' => __('Your Wonder Payments App ID', 'wonder-payment-for-woocommerce'),
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
                        'title' => __('Webhook Public Key', 'wonder-payment-for-woocommerce'),
                        'type' => 'textarea',
                        'description' => __('Get webhook public key from wonder portal when created appid.', 'wonder-payment-for-woocommerce'),
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

            <!-- 密钥对显示区域 -->
            <div class="wonder-keys-display" style="display: flex; gap: 20px; margin-bottom: 20px;">
                <div style="flex: 1;">
                    <h4><?php _e('Private Key', 'wonder-payment-for-woocommerce'); ?></h4>
                    <p class="description"><?php _e('Your RSA 4096-bit private key. Keep this secure.', 'wonder-payment-for-woocommerce'); ?></p>
                    <textarea id="wonder-private-key-display"
                              style="width: 100%; height: 200px; font-family: monospace; font-size: 11px; margin: 10px 0; padding: 10px; background: #fff;">
                            <?php echo esc_textarea($private_key); ?>
                        </textarea>

                    <button type="button" class="button button-primary" id="wonder-generate-keys">
                        <?php _e('Generate RSA Keys', 'wonder-payment-for-woocommerce'); ?>
                    </button>
                    <span class="spinner" style="float: none; margin: 0 10px;"></span>
                    <span id="wonder-generate-message" style="margin-left: 10px;"></span>
                </div>

                <div style="flex: 1;">
                    <h4><?php _e('Public Key', 'wonder-payment-for-woocommerce'); ?></h4>
                    <p class="description"><?php _e('Copy this public key and upload it to Wonder Portal.', 'wonder-payment-for-woocommerce'); ?></p>
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
                <?php _e('Test Configuration', 'wonder-payment-for-woocommerce'); ?>
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
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'wonder_generate_keys',
                            security: '<?php echo wp_create_nonce('wonder_generate_keys'); ?>'
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
                            $message.html('<span style="color: red;">❌ <?php _e('Error:', 'wonder-payment-for-woocommerce'); ?> ' + error + '</span>').addClass('error');
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
        // 强制记录日志到多个地方
        $log_message = "=== Wonder Payments: Generate RSA Keys 方法被调用 ===\n";
        $log_message .= "时间: " . date('Y-m-d H:i:s') . "\n";
        $log_message .= "POST: " . print_r($_POST, true) . "\n";
        $log_message .= "GET: " . print_r($_GET, true) . "\n";

        error_log($log_message);
        file_put_contents('/Users/wonder/Development/wordpress-docker/wp-content/plugins/wonder-payment-for-woocommerce/debug_generate_keys.txt', $log_message . "\n", FILE_APPEND);

        check_ajax_referer('wonder_generate_keys', 'security');
        error_log('✅ Nonce验证通过');
        file_put_contents('/Users/wonder/Development/wordpress-docker/wp-content/plugins/wonder-payment-for-woocommerce/debug_generate_keys.txt', "✅ Nonce验证通过\n", FILE_APPEND);

        // 获取当前环境配置
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $sandbox_enabled = isset($settings['environment']) ? $settings['environment'] : 'no';

        // 根据Sandbox Mode判断环境
        $environment = ($sandbox_enabled === 'yes') ? 'prod' : 'stg';
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        error_log('=== 环境配置信息 ===');
        error_log('Sandbox Mode设置: ' . ($sandbox_enabled === 'yes' ? '开启' : '关闭') . ' (值: ' . $sandbox_enabled . ')');
        error_log('当前环境: ' . $environment . ' (' . ($environment === 'prod' ? '生产环境' : '测试环境') . ')');
        error_log('API端点: ' . $api_endpoint);
        error_log('====================');

        try {
            error_log('开始生成RSA 4096位密钥对...');

            // 生成RSA 4096位密钥对
            $config = array(
                    "digest_alg" => "sha256",
                    "private_key_bits" => 4096,
                    "private_key_type" => OPENSSL_KEYTYPE_RSA,
            );

            error_log('密钥配置: ' . print_r($config, true));

            // 生成密钥对
            $key_pair = openssl_pkey_new($config);

            if (!$key_pair) {
                error_log('❌ 生成密钥对失败');
                throw new Exception('Failed to generate RSA key pair');
            }

            error_log('✅ 密钥对生成成功');

            // 获取私钥
            openssl_pkey_export($key_pair, $private_key);
            error_log('✅ 私钥导出成功，长度: ' . strlen($private_key) . ' 字符');

            // 获取公钥
            $key_details = openssl_pkey_get_details($key_pair);
            $public_key_pem = $key_details['key'];
            error_log('✅ 公钥导出成功，长度: ' . strlen($public_key_pem) . ' 字符');

            // 更新插件设置
            $settings = get_option('woocommerce_wonder_payments_settings', array());
            error_log('当前设置: ' . print_r(array_keys($settings), true));

            $settings['private_key'] = $private_key;
            $settings['generated_public_key'] = $public_key_pem;

            update_option('woocommerce_wonder_payments_settings', $settings);
            error_log('✅ 设置已更新到数据库');

            $response = array(
                    'message' => __('RSA 4096-bit key pair generated successfully', 'wonder-payment-for-woocommerce'),
                    'private_key' => $private_key,
                    'public_key' => $public_key_pem
            );

            error_log('✅ 准备返回成功响应');
            error_log('响应数据: ' . print_r(array(
                    'message' => $response['message'],
                    'private_key_length' => strlen($private_key),
                    'public_key_length' => strlen($public_key_pem)
            ), true));

            wp_send_json_success($response);

        } catch (Exception $e) {
            error_log('❌ 生成密钥对失败: ' . $e->getMessage());
            error_log('❌ 异常位置: ' . $e->getFile() . ':' . $e->getLine());
            error_log('❌ 异常追踪: ' . $e->getTraceAsString());

            wp_send_json_error(array(
                    'message' => $e->getMessage()
            ));
        }

        error_log('=== Wonder Payments: Generate RSA Keys 请求结束 ===');
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
        error_log('=== Wonder Payments is_available() 调用 ===');
        error_log('enabled: ' . $this->enabled);
        error_log('app_id: ' . (empty($this->app_id) ? '空' : '已设置'));
        error_log('private_key: ' . (empty($this->private_key) ? '空' : '已设置'));

        $is_available = parent::is_available();
        error_log('父类 is_available(): ' . ($is_available ? 'true' : 'false'));

        // 如果父类返回 false，直接返回
        if (!$is_available) {
            error_log('结果: false (父类返回 false)');
            return false;
        }

        // 检查必要配置
        if (empty($this->app_id) || empty($this->private_key)) {
            error_log('结果: false (配置不完整)');
            return false;
        }

        error_log('结果: true');
        error_log('======================================');
        return true;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // 记录当前环境配置
        $environment = $this->get_environment();
        $sandbox_enabled = $this->get_option('environment');
        $api_endpoint = ($environment === 'prod') ? 'https://gateway.wonder.today' : 'https://gateway-stg.wonder.today';

        error_log('=== Wonder Payments: process_payment 环境配置 ===');
        error_log('Sandbox Mode: ' . ($sandbox_enabled === 'yes' ? '开启' : '关闭'));
        error_log('当前环境: ' . $environment);
        error_log('API端点: ' . $api_endpoint);
        error_log('====================================');

        // 检查是否设置了必要参数
        if (empty($this->app_id) || empty($this->private_key)) {
            wc_add_notice(__('Payment gateway is not configured properly. Please check your App ID and Private Key.', 'wonder-payment-for-woocommerce'), 'error');
            return;
        }

        // 检查SDK是否存在
        if (!class_exists('PaymentSDK')) {
            wc_add_notice(__('Payment gateway SDK is not available. Please ensure the SDK files are properly included.', 'wonder-payment-for-woocommerce'), 'error');
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
                wc_add_notice(__('Private key is not set. Please configure your payment gateway.', 'wonder-payment-for-woocommerce'), 'error');
                return;
            }

            $privateKeyId = openssl_pkey_get_private($privateKey);
            if (!$privateKeyId) {
                error_log('WONDER PAYMENTS: Invalid private key format');
                wc_add_notice(__('Invalid private key format. Please reconfigure your payment gateway.', 'wonder-payment-for-woocommerce'), 'error');
                return;
            }
            openssl_pkey_free($privateKeyId);

            // 初始化SDK
            $webhook_key = !empty($this->webhook_public_key) ? $this->webhook_public_key : null;
            $environment = $this->get_environment();

            error_log('=== Wonder Payments: SDK初始化配置 ===');
            error_log('App ID: ' . substr($this->app_id, 0, 8) . '...');
            error_log('环境: ' . $environment);
            error_log('====================================');

            $options = array(
                    'appid' => $this->app_id,
                    'signaturePrivateKey' => $privateKey,
                    'webhookVerifyPublicKey' => $webhook_key ?: '',
                    'callback_url' => $callback_url,
                    'redirect_url' => $redirect_url,
                    'environment' => $environment,
                    'skipSignature' => false
            );

            error_log('SDK Options: ' . print_r(array(
                'appid' => substr($options['appid'], 0, 8) . '...',
                'environment' => $environment,
                'apiEndpoint' => $options['apiEndpoint']
            ), true));

            $sdk = new PaymentSDK($options);

            if (!$sdk) {
                wc_add_notice(__('Payment gateway SDK initialization failed.', 'wonder-payment-for-woocommerce'), 'error');
                return;
            }

            // 构建订单数据
            $order_currency = $order->get_currency();
            $supported_currencies = array('HKD', 'USD', 'EUR', 'GBP');
            $api_currency = in_array($order_currency, $supported_currencies) ? $order_currency : 'HKD';

            $reference_number = $order->get_order_number();

            // 获取配置的付款截止天数，默认为30天
            $due_days = !empty($this->due_date) ? intval($this->due_date) : 30;
            $due_date = date('Y-m-d', strtotime('+' . $due_days . ' days'));

            error_log('付款截止日期: ' . $due_date . ' (' . $due_days . '天后)');

            $order_data = array(
                    'order' => array(
                            'reference_number' => $reference_number,
                            'charge_fee' => number_format($order->get_total(), 2, '.', ''),
                            'due_date' => $due_date,
                            'currency' => $api_currency,
                            'note' => 'Order #' . $order->get_order_number() . ' from ' . get_bloginfo('name'),
                            'customer' => array(
                                    'name' => $order->get_billing_first_name() . ' class-wonder-payments-gateway.php' . $order->get_billing_last_name(),
                                    'email' => $order->get_billing_email(),
                                    'phone' => $order->get_billing_phone()
                            )
                    )
            );

                // 检查是否已有缓存的支付链接  （update_post_meta： 缓存数据存储在WordPress 的 wp_postmeta 数据库表中）
                $cached_payment_link = get_post_meta($order_id, '_wonder_payment_link', true);
                if ($cached_payment_link) {
                    error_log('使用缓存的支付链接: ' . substr($cached_payment_link, 0, 50) . '...');
                    // 检查订单状态，如果已完成支付则跳转到感谢页面
                    if ($order->is_paid()) {
                        return array(
                            'result' => 'success',
                            'redirect' => $order->get_checkout_order_received_url()
                        );
                    }
                    // 重定向到缓存的支付链接
                    return array(
                        'result' => 'success',
                        'redirect' => $cached_payment_link
                    );
                }
                // 使用SDK创建支付链接
                $response = $sdk->createPaymentLink($order_data);
                // 记录完整响应用于调试
                error_log('Wonder Payments API Response: ' . print_r($response, true));
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
                    // 保存交易ID（如果有）
                    if (isset($response['data']['id'])) {
                        update_post_meta($order_id, '_wonder_transaction_id', $response['data']['id']);
                    }
                    // 更新订单状态为待支付
                    $order->update_status('pending', __('Awaiting Wonder Payments payment', 'wonder-payment-for-woocommerce'));
                    // 重定向到支付链接
                    return array(
                            'result'   => 'success',
                            'redirect' => $payment_link
                    );
                } else {
                    $error_msg = __('Payment link could not be created. Please contact administrator.', 'wonder-payment-for-woocommerce');

                    if (isset($response['message'])) {
                        $error_msg = $response['message'];
                    } elseif (isset($response['error'])) {
                        $error_msg = $response['error'];
                    }

                    error_log('Wonder Payments Error: ' . $error_msg);
                    wc_add_notice($error_msg, 'error');
                    return;
                }
        } catch (Exception $e) {
            error_log('WONDER PAYMENTS: Payment Processing Error - ' . $e->getMessage());
            wc_add_notice(-wonder - payments - gateway . php__('Payment processing failed: ', 'wonder-payment-for-woocommerce') . $e->getMessage(), 'error');
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
            return new WP_Error('error', __('Payment gateway not configured', 'wonder-payment-for-woocommerce'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('error', __('Order not found', 'wonder-payment-for-woocommerce'));
        }

        if (!$amount || $amount <= 0) {
            return new WP_Error('error', __('Invalid refund amount', 'wonder-payment-for-woocommerce'));
        }

        try {
            // 检查SDK是否存在
            if (!class_exists('PaymentSDK')) {
                return new WP_Error('error', __('PaymentSDK not available', 'wonder-payment-for-woocommerce'));
            }

            // 获取订单参考号
            $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
            if (empty($reference_number)) {
                return new WP_Error('error', __('Order reference number not found', 'wonder-payment-for-woocommerce'));
            }

            // 获取交易UUID（从订单备注或元数据中获取）
            $transaction_uuid = get_post_meta($order_id, '_wonder_transaction_id', true);
            if (empty($transaction_uuid)) {
                return new WP_Error('error', __('Transaction ID not found', 'wonder-payment-for-woocommerce'));
            }

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

            $sdk = new PaymentSDK($options);

            // 构建退款参数
            $refund_params = array(
                    'order' => array(
                            'reference_number' => $reference_number
                    ),
                    'transaction' => array(
                            'uuid' => $transaction_uuid
                    ),
                    'refund' => array(
                            'amount' => number_format($amount, 2, '.', ''),
                            'currency' => $api_currency,
                            'reason' => $reason ? $reason : 'Refund via WooCommerce'
                    )
            );

            error_log('=== Wonder Payments: 退款请求 ===');
            error_log('订单ID: ' . $order_id);
            error_log('参考号: ' . $reference_number);
            error_log('交易UUID: ' . $transaction_uuid);
            error_log('退款金额: ' . $amount . ' ' . $api_currency);
            error_log('退款原因: ' . ($reason ?: 'N/A'));
            error_log('====================================');

            // 调用SDK的refundTransaction方法
            $response = $sdk->refundTransaction($refund_params);

            error_log('退款响应: ' . print_r($response, true));

            // 检查响应
            if (isset($response['code']) && $response['code'] == 200) {
                // 退款成功
                error_log('✅ 退款响应成功，code: ' . $response['code']);
                
                $order->add_order_note(
                        sprintf(
                                __('Wonder Payments refund successful. Amount: %s, Reason: %s', 'wonder-payment-for-woocommerce'),
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
                            error_log('保存退款ID: ' . $transaction['uuid']);
                            break;
                        }
                    }
                }

                // 更新订单状态为已退款
                $order->update_status('refunded', __('Order refunded via Wonder Payments', 'wonder-payment-for-woocommerce'));
                error_log('✅ 订单状态已更新为已退款');

                return true;
            } else {
                // 退款失败
                $error_message = isset($response['message']) ? $response['message'] : 'Unknown error';
                if (isset($response['error_code'])) {
                    $error_message .= ' (' . $response['error_code'] . ')';
                }
                
                error_log('❌ 退款失败: ' . $error_message);
                
                $order->add_order_note(
                        sprintf(
                                __('Wonder Payments refund failed. Amount: %s, Error: %s', 'wonder-payment-for-woocommerce'),
                                wc_price($amount),
                                $error_message
                        )
                );

                return new WP_Error('refund_error', $error_message);
            }

        } catch (Exception $e) {
            error_log('Wonder Payments 退款异常: ' . $e->getMessage());
            $order->add_order_note(
                    sprintf(
                            __('Wonder Payments refund exception: %s', 'wonder-payment-for-woocommerce'),
                            $e->getMessage()
                    )
            );

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
        $signature = isset($_SERVER['HTTP_SIGNATURE']) ? $_SERVER['HTTP_SIGNATURE'] : '';
        $credential = isset($_SERVER['HTTP_CREDENTIAL']) ? $_SERVER['HTTP_CREDENTIAL'] : '';
        $nonce = isset($_SERVER['HTTP_NONCE']) ? $_SERVER['HTTP_NONCE'] : '';

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
            error_log('WONDER PAYMENTS: Webhook verification error - ' . $e->getMessage());
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
            $order->add_order_note(__('Wonder Payments payment completed.', 'wonder-payment-for-woocommerce'));
        } else {
            $order->update_status('failed', __('Wonder Payments payment failed.', 'wonder-payment-for-woocommerce'));
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
        error_log('=== AJAX支付状态检查开始 ===');
        error_log('POST数据: ' . print_r($_POST, true));

        try {
            check_ajax_referer('wonder_payments_check_status', 'nonce');
            error_log('Nonce验证通过');
        } catch (Exception $e) {
            error_log('❌ Nonce验证失败: ' . $e->getMessage());
            wp_send_json(array(
                    'success' => false,
                    'message' => '安全验证失败',
                    'status' => 'error'
            ));
            return;
        }

        $order_id = intval($_POST['order_id']);
        $order_key = sanitize_text_field($_POST['order_key']);

        error_log('订单ID: ' . $order_id);
        error_log('订单Key: ' . $order_key);

        if (!$order_id || !$order_key) {
            error_log('❌ 订单ID或Key为空');
            wp_send_json(array(
                    'success' => false,
                    'message' => '订单信息不完整',
                    'status' => 'error'
            ));
            return;
        }

        try {
            $handler = new WC_Wonder_Payments_Result_Handler($this);
            error_log('结果处理器创建成功');

            $result = $handler->check_payment_status($order_id, $order_key);
            error_log('支付状态检查结果: ' . print_r($result, true));

            wp_send_json($result);

        } catch (Exception $e) {
            error_log('❌ AJAX处理异常: ' . $e->getMessage());
            error_log('❌ 异常位置: ' . $e->getFile() . ':' . $e->getLine());
            error_log('❌ 异常追踪: ' . $e->getTraceAsString());

            wp_send_json(array(
                    'success' => false,
                    'message' => '服务器处理错误: ' . $e->getMessage(),
                    'status' => 'error'
            ));
        }

        error_log('=== AJAX支付状态检查结束 ===');
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

        error_log('=== 自动检查订单支付状态 ===');
        error_log('订单ID: ' . $order_id);

        try {
            $handler = new WC_Wonder_Payments_Result_Handler($this);
            $result = $handler->check_payment_status($order_id, $order->get_order_key());

            // 更新最后检查时间
            update_post_meta($order_id, '_wonder_last_status_check', time());

            error_log('自动检查结果: ' . print_r($result, true));

        } catch (Exception $e) {
            error_log('自动检查支付状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 处理支付返回
     */
    public function handle_payment_return()
    {
        // 从URL参数获取订单信息
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order_key = isset($_GET['order_key']) ? sanitize_text_field($_GET['order_key']) : '';

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
                console.log('Wonder Payments: 后台检查订单<?php echo $order_id; ?>支付状态...');

                const formData = new URLSearchParams();
                formData.append('action', 'wonder_payments_check_status');
                formData.append('order_id', <?php echo $order_id; ?>);
                formData.append('order_key', '<?php echo $order->get_order_key(); ?>');
                formData.append('nonce', '<?php echo wp_create_nonce('wonder_payments_check_status'); ?>');

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
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
}