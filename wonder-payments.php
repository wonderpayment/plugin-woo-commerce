<?php
/*
Plugin Name: Wonder Payments for WooCommerce
Plugin URI: https://yourwebsite.com/
Description: Integrates Wonder Payments with WooCommerce using official Wonder Payment SDK
Version: 1.0.0
Author: Your Name
License: GPL v2 or later
Text Domain: wonder-payments
*/

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 在你的插件主文件顶部（wonder-payments.php）
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    error_log('✅ Composer autoloader loaded');
} else {
    error_log('❌ Composer autoloader not found at: ' . __DIR__ . '/vendor/autoload.php');

    // 尝试直接包含SDK文件
    if (file_exists(__DIR__ . '/vendor/wonderpayment/sdk/src/PaymentSDK.php')) {
        require_once __DIR__ . '/vendor/wonderpayment/sdk/src/PaymentSDK.php';
        error_log('✅ Directly loaded PaymentSDK.php');
    }
}

// 检查 WooCommerce 是否激活
// 使用优先级 0 确保在 WooCommerce 完全初始化后再加载
add_action('plugins_loaded', 'init_wonder_payments_gateway', 0);

function init_wonder_payments_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'wonder_payments_wc_notice');
        return;
    }

    // 包含必要的文件
    require_once dirname(__FILE__) . '/includes/class-wonder-payments-gateway.php';

    // 添加支付网关
    add_filter('woocommerce_payment_gateways', 'add_wonder_payments_gateway');
}

function add_wonder_payments_gateway($gateways) {
    error_log('Wonder Payments: 添加支付网关到列表');
    $gateways[] = 'WC_Wonder_Payments_Gateway';
    error_log('Wonder Payments: 当前网关列表数量 = ' . count($gateways));
    return $gateways;
}

function wonder_payments_wc_notice() {
    echo '<div class="error"><p>';
    echo __('Wonder Payments requires WooCommerce to be installed and activated.', 'wonder-payments');
    echo '</p></div>';
}

// 注册 AJAX 处理函数
add_action('wp_ajax_wonder_generate_keys', 'wonder_ajax_generate_keys');
add_action('wp_ajax_wonder_test_config', 'wonder_ajax_test_config');

// 注册定时清理日志的定时任务
add_action('init', 'wonder_payments_schedule_log_cleanup');

function wonder_payments_schedule_log_cleanup() {
    if (!wp_next_scheduled('wonder_payments_daily_log_cleanup')) {
        // 安排每天凌晨3点执行清理任务
        wp_schedule_event(strtotime('tomorrow 3:00am'), 'daily', 'wonder_payments_daily_log_cleanup');
    }
}

// 执行日志清理
add_action('wonder_payments_daily_log_cleanup', 'wonder_payments_clean_debug_log');

function wonder_payments_clean_debug_log() {
    $log_file = WP_CONTENT_DIR . '/debug.log';
    
    if (!file_exists($log_file)) {
        return;
    }
    
    // 删除7天前的日志
    $cutoff_time = time() - (7 * 24 * 60 * 60); // 7天前
    
    try {
        // 读取日志文件
        $lines = file($log_file);
        if ($lines === false) {
            return;
        }
        
        // 找到7天前的第一行
        $keep_from = 0;
        for ($i = 0; $i < count($lines); $i++) {
            // 提取日志时间戳
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $lines[$i], $matches)) {
                $log_time = strtotime($matches[1]);
                if ($log_time && $log_time > $cutoff_time) {
                    $keep_from = $i;
                    break;
                }
            }
        }
        
        // 如果有需要保留的行，重写文件
        if ($keep_from > 0) {
            $keep_lines = array_slice($lines, $keep_from);
            file_put_contents($log_file, implode('', $keep_lines));
            error_log('✅ Wonder Payments: 已清理7天前的日志，保留了 ' . count($keep_lines) . ' 行');
        } else {
            // 如果所有日志都是7天前的，清空文件
            file_put_contents($log_file, '');
            error_log('✅ Wonder Payments: 已清空所有日志（都是7天前的）');
        }
    } catch (Exception $e) {
        error_log('❌ Wonder Payments 日志清理失败: ' . $e->getMessage());
    }
}


/**
 * 生成密钥的 AJAX 处理函数 - 使用 SDK
 */
function wonder_ajax_generate_keys() {
    // 检查权限
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }

    // 检查 nonce
    check_ajax_referer('wonder_generate_keys', 'security');

    try {
        // 始终使用 SDK 生成新的密钥对
        error_log('Generating new key pair using SDK...');
        $keyPair = PaymentSDK::generateKeyPair(4096);
        
        $private_key = $keyPair['private_key'];
        $public_key = $keyPair['public_key'];

        // 更新插件设置中的公钥和私钥
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $settings['generated_public_key'] = $public_key;
        $settings['private_key'] = $private_key;
        update_option('woocommerce_wonder_payments_settings', $settings);

        wp_send_json_success(array(
            'private_key' => $private_key,
            'public_key' => $public_key,
            'message' => 'The key pair has been successfully generated.'
        ));
    } catch (Exception $e) {
        error_log('Generate keys error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Failed to generate key pair: ' . $e->getMessage()
        ));
    }
}

/**
 * 测试配置的 AJAX 处理函数 - 使用SDK进行验证
 */
function wonder_ajax_test_config() {
    // 检查权限
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }

    // 检查 nonce
    check_ajax_referer('wonder_test_config', 'security');

    // 获取 POST 数据
    $app_id = isset($_POST['app_id']) ? sanitize_text_field($_POST['app_id']) : '';
    $private_key = isset($_POST['private_key']) ? $_POST['private_key'] : '';
    $webhook_key = isset($_POST['webhook_key']) ? $_POST['webhook_key'] : '';
    $environment = isset($_POST['environment']) ? sanitize_text_field($_POST['environment']) : 'yes';

    $result = wonder_test_api_connection($app_id, $private_key, $webhook_key, $environment);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message']
        ));
    } else {
        wp_send_json_error(array(
            'message' => $result['message']
        ));
    }
}

/**
 * 测试 API 连接函数 - 使用SDK进行验证
 */
function wonder_test_api_connection($app_id, $private_key, $webhook_key = '', $environment = 'yes') {
    // 检查SDK是否存在
    if (!class_exists('PaymentSDK')) {
        return array(
            'success' => false,
            'message' => 'Wonder Authentication SDK is not available. Please ensure the SDK files are properly included.'
        );
    }

    // 简单验证输入参数
    $errors = array();

    if (empty($app_id)) {
        $errors[] = 'App ID is required';
    }

    if (!empty($private_key)) {
        // 验证私钥格式
        $key = openssl_pkey_get_private($private_key);
        if (!$key) {
            $errors[] = 'Invalid private key format';
        } else {
            openssl_pkey_free($key);
        }
    }

    if (!empty($errors)) {
        return array(
            'success' => false,
            'message' => implode(', ', $errors)
        );
    }

    // 根据环境选择环境字符串（关闭时使用 stg，开启时使用 prod）
    $environment_value = $environment === 'yes' ? 'prod' : 'stg';

    // 使用SDK进行测试
    try {
        error_log('=== Wonder Payments: Test Configuration 开始 ===');
        error_log('App ID: ' . $app_id);
        error_log('Environment: ' . $environment_value);
        error_log('Private Key 长度: ' . strlen($private_key));
        error_log('====================================');

        // 检查SDK类是否存在
        if (!class_exists('PaymentSDK')) {
            error_log('PaymentSDK 类不存在！');
            return array(
                'success' => false,
                'message' => 'PaymentSDK class not found. Please check if the SDK is properly installed.'
            );
        }

        // 确保所有必需参数存在
        $webhookPublicKeyValue = !empty($webhook_key) ? $webhook_key : '';

        $options = array(
            'appid' => $app_id,
            'signaturePrivateKey' => $private_key,
            'webhookVerifyPublicKey' => $webhookPublicKeyValue,
            'callback_url' => '',
            'redirect_url' => '',
            'environment' => $environment_value,
            'skipSignature' => false
        );

        error_log('SDK Options: ' . print_r($options, true));

        // 尝试实例化SDK
        error_log('正在实例化 PaymentSDK...');
        $authSDK = new PaymentSDK($options);
        error_log('✅ PaymentSDK 实例化成功');

        if (!$authSDK) {
            error_log('PaymentSDK 实例化失败');
            return array(
                'success' => false,
                'message' => 'Failed to initialize PaymentSDK'
            );
        }

        // 检查 verifySignature 方法是否存在
        if (!method_exists($authSDK, 'verifySignature')) {
            error_log('verifySignature 方法不存在！');
            return array(
                'success' => false,
                'message' => 'verifySignature method not found in PaymentSDK'
            );
        }

        // 使用 SDK 的 verifySignature 方法进行测试
        error_log('正在调用 verifySignature()...');
        $result = $authSDK->verifySignature();
        error_log('verifySignature() 返回结果类型: ' . gettype($result));
        error_log('verifySignature() 返回结果: ' . var_export($result, true));

        // verifySignature 返回的是数组，需要检查 success 字段
        if (is_array($result) && isset($result['success']) && $result['success'] === true) {
            error_log('✅ 签名验证成功');
            
            // 获取business信息
            $business_name = isset($result['business']['business_name']) ? $result['business']['business_name'] : 'Unknown';
            $business_id = isset($result['business']['id']) ? $result['business']['id'] : 'Unknown';
            
            $message = sprintf('Successful connect to business: %s (%s)', $business_name, $business_id);
            
            return array(
                'success' => true,
                'message' => $message
            );
        } else {
            error_log('签名验证失败');
            $error_msg = 'Verify failed, please make sure the signature key pair are correct.';
            if (is_array($result) && isset($result['business'])) {
                $error_msg .= ' Business: ' . ($result['business'] ? 'Valid' : 'Invalid');
            }
            return array(
                'success' => false,
                'message' =>  $error_msg
            );
        }

    } catch (Exception $e) {
        error_log('Wonder Payments SDK Test Error');
        error_log('错误消息: ' . $e->getMessage());
        error_log('错误文件: ' . $e->getFile() . ':' . $e->getLine());
        error_log('错误追踪: ' . $e->getTraceAsString());

        // 检查错误信息
        $error_msg = $e->getMessage();
        if (strpos($error_msg, 'Invalid credential') !== false || strpos($error_msg, '403') !== false) {
            return array(
                'success' => false,
                'message' => 'Invalid App ID or Private Key. Please check your credentials.'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Verification failed: ' . $error_msg
            );
        }
    }
}

// 注册设置页面脚本

add_action('admin_enqueue_scripts', 'wonder_payments_admin_scripts', 9999); // 使用高优先级确保最后加载



function wonder_payments_admin_scripts($hook) {
    // 只在 WooCommerce 设置页面加载
    if ('woocommerce_page_wc-settings' !== $hook) {
        return;
    }
    // 检查是否是我们的支付网关设置页面
    if (!isset($_GET['section']) || $_GET['section'] !== 'wonder_payments') {
        return;
    }

    // 记录调试信息
    error_log('=== WONDER PAYMENTS: Loading admin scripts ===');
    error_log('Current hook: ' . $hook);
    error_log('Current section: ' . $_GET['section']);
        // 使用修复版的 JavaScript 文件
        $script_url = plugin_dir_url(__FILE__) . 'assets/js/admin-fixed.js';
        $script_path = plugin_dir_path(__FILE__) . 'assets/js/admin-fixed.js';
        error_log('Script URL: ' . $script_url);
        error_log('Script path: ' . $script_path);
        error_log('Script exists: ' . (file_exists($script_path) ? 'YES' : 'NO'));
        if (!file_exists($script_path)) {
            error_log('ERROR: Fixed script file does not exist! Trying original...');
            // 回退到原始文件
            $script_url = plugin_dir_url(__FILE__) . 'assets/js/admin.js';
            $script_path = plugin_dir_path(__FILE__) . 'assets/js/admin.js';
            if (!file_exists($script_path)) {
                error_log('ERROR: Original script file also does not exist!');
                return;
            }
        }

        // 获取文件修改时间作为版本号，避免缓存
        $script_version = filemtime($script_path);
        error_log('Script version (filemtime): ' . $script_version);
        // 先注册脚本
        wp_register_script(
            'wonder-payments-admin',
            $script_url,
            array('jquery'),
            $script_version,
            true
        );
        // 最后排队脚本
        wp_enqueue_script('wonder-payments-admin');
        // 传递数据到 JavaScript
        wp_localize_script('wonder-payments-admin', 'wonder_payments_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'generate_nonce' => wp_create_nonce('wonder_generate_keys'),
            'test_nonce' => wp_create_nonce('wonder_test_config'),
            'strings' => array(
                'public_key_label' => __('Public Key (upload to Wonder Portal):', 'wonder-payments'),
                'important' => __('Important:', 'wonder-payments'),
                'save_changes' => __('Please click "Save changes" to save the private key.', 'wonder-payments'),
                'enter_fields' => __('Please enter App ID and Private Key first.', 'wonder-payments'),
                'error' => __('Error:', 'wonder-payments')
            )
        ));
        error_log('=== WONDER PAYMENTS: Admin scripts loaded successfully ===');
    }
    /**
     * AJAX: 获取订单调试信息
     */

    function wonder_payments_debug_order() {
        check_ajax_referer('wonder_payments_debug', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('订单不存在');
        }
        $debug_info = array(
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'order_key' => $order->get_order_key(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'date_created' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'payment_method' => $order->get_payment_method(),
            'is_paid' => $order->is_paid(),
            'meta_data' => array(
                'reference_number' => get_post_meta($order_id, '_wonder_reference_number', true),
                'payment_link' => get_post_meta($order_id, '_wonder_payment_link', true),
                'transaction_id' => get_post_meta($order_id, '_wonder_transaction_id', true),
                'app_id' => get_post_meta($order_id, '_wonder_app_id', true),
            )
        );
        wp_send_json_success($debug_info);
    }

    add_action('wp_ajax_wonder_payments_debug_order', 'wonder_payments_debug_order');

    /**
     * AJAX: 获取调试日志
     */
    function wonder_payments_get_logs() {
        check_ajax_referer('wonder_payments_debug', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        $log_file = WP_CONTENT_DIR . '/debug.log';
        $logs = '';
        if (file_exists($log_file)) {
            // 读取最后100行日志
            $logs = shell_exec('tail -100 ' . escapeshellarg($log_file));
            if (!$logs) {
                $logs = '无法读取日志文件';
            }
        } else {
            $logs = '日志文件不存在: ' . $log_file;
        }
        wp_send_json_success(array('logs' => $logs));
    }
    add_action('wp_ajax_wonder_payments_get_logs', 'wonder_payments_get_logs');