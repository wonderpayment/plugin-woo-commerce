<?php
/*
Plugin Name: Wonder Payment For WooCommerce
Plugin URI: https://wonder.app/
Description: Accept Wonder Payments in WooCommerce with payment links, webhooks, order sync, and refunds.
Version: 1.0.3
Author: wonder
Requires Plugins: woocommerce
Requires PHP: 7.4
License: GPL v2 or later
Text Domain: wonder-payment-for-woocommerce
*/

// Note.
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WONDER_PAYMENTS_PLUGIN_FILE')) {
    define('WONDER_PAYMENTS_PLUGIN_FILE', __FILE__);
}

/**
 * Note.
 *
 * @return WC_Logger
 */
function wonder_payments_get_logger() {
    return wc_get_logger();
}

/**
 * Return the plugin uploads directory, creating it when needed.
 *
 * @return string
 */
function wonder_payments_get_upload_dir() {
    $uploads = wp_upload_dir();
    $dir = trailingslashit($uploads['basedir']) . 'wonder-payments';

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    return $dir;
}

/**
 * Return the debug log file path used by the plugin.
 *
 * @return string
 */
function wonder_payments_get_debug_log_file() {
    return trailingslashit(wonder_payments_get_upload_dir()) . 'debug.log';
}

/**
 * Sanitize PEM-like secrets while preserving line breaks.
 *
 * @param mixed $value Raw secret value.
 * @return string
 */
function wonder_payments_sanitize_multiline_secret($value) {
    $value = is_scalar($value) ? (string) $value : '';
    $value = str_replace("\0", '', $value);
    $value = preg_replace("/\r\n?/", "\n", $value);
    return trim($value);
}

/**
 * Sanitize header/token values used for signature verification.
 *
 * @param mixed  $value Raw header value.
 * @param string $pattern Allowed character pattern.
 * @return string
 */
function wonder_payments_sanitize_token_value($value, $pattern = '/[^A-Za-z0-9_\\-:\\.\\/+=]/') {
    $value = is_scalar($value) ? (string) $value : '';
    $value = wp_unslash($value);
    $value = sanitize_text_field($value);
    return (string) preg_replace($pattern, '', $value);
}

/**
 * Check whether the current admin page is the WooCommerce checkout settings page.
 *
 * @return bool
 */
function wonder_payments_is_checkout_settings_page() {
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);
    $tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS);

    return $page === 'wc-settings' && $tab === 'checkout';
}

/**
 * Check whether the current admin screen is an order screen.
 *
 * @param WP_Screen|null $screen Current screen object.
 * @return bool
 */
function wonder_payments_is_order_admin_screen($screen) {
    if (!$screen) {
        return false;
    }

    if (in_array($screen->id, array('shop_order', 'woocommerce_page_wc-orders'), true)) {
        return true;
    }

    return isset($screen->post_type) && $screen->post_type === 'shop_order';
}

/**
 * Register shared admin assets for the plugin.
 *
 * @return void
 */
function wonder_payments_register_admin_assets() {
    static $registered = false;

    if ($registered) {
        return;
    }

    $style_path = plugin_dir_path(WONDER_PAYMENTS_PLUGIN_FILE) . 'assets/css/admin.css';
    $script_path = plugin_dir_path(WONDER_PAYMENTS_PLUGIN_FILE) . 'assets/js/wonder_payments_admin.js';

    wp_register_style(
        'wonder-payments-admin-ui',
        plugins_url('assets/css/admin.css', WONDER_PAYMENTS_PLUGIN_FILE),
        array(),
        file_exists($style_path) ? (string) filemtime($style_path) : '1.0.3'
    );

    wp_register_script(
        'wonder-payments-admin-ui',
        plugins_url('assets/js/wonder_payments_admin.js', WONDER_PAYMENTS_PLUGIN_FILE),
        array('jquery'),
        file_exists($script_path) ? (string) filemtime($script_path) : '1.0.3',
        true
    );

    $registered = true;
}

/**
 * Enqueue shared admin assets and expose page configuration to JavaScript.
 *
 * @param array $data Page-specific configuration.
 * @return void
 */
function wonder_payments_enqueue_admin_assets(array $data = array()) {
    wonder_payments_register_admin_assets();

    wp_enqueue_style('wonder-payments-admin-ui');
    wp_enqueue_script('wonder-payments-admin-ui');

    $inline_config = 'window.wonderPaymentsAdmin = window.wonderPaymentsAdmin || {}; Object.assign(window.wonderPaymentsAdmin, ' . wp_json_encode($data) . ');';
    wp_add_inline_script('wonder-payments-admin-ui', $inline_config, 'before');
}

// Note.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Note.
    if (file_exists(__DIR__ . '/vendor/wonderpayment/sdk/src/PaymentSDK.php')) {
        require_once __DIR__ . '/vendor/wonderpayment/sdk/src/PaymentSDK.php';
    }
}

// Note.
// Note.
add_action('plugins_loaded', 'wonder_payments_init_gateway', 0);

// Note.
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        $plugin_file = plugin_basename(__FILE__);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $plugin_file, true);
    }
});

function wonder_payments_init_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'wonder_payments_wc_notice');
        return;
    }

    // Note.
    require_once dirname(__FILE__) . '/includes/class-wonder-payments-gateway.php';
    require_once dirname(__FILE__) . '/includes/Wonder_Payments_Admin.php';

    // Note.
    add_action('woocommerce_blocks_payment_method_type_registration', 'wonder_payments_register_blocks_support');

    // Note.
    add_filter('woocommerce_payment_gateways', 'wonder_payments_add_gateway');

    // Note.
    add_action('wp_ajax_wonder_sync_order_status', 'wonder_payments_ajax_sync_order_status');

    // Note.
    add_filter('woocommerce_payment_gateway_settings_link', 'wonder_payments_add_modal_link', 10, 2);
    add_filter('plugin_action_links', 'wonder_payments_plugin_action_links', 10, 4);
    add_filter('plugin_action_links_woocommerce/woocommerce.php', 'wonder_payments_gateway_menu_links', 10, 4);
    add_action('admin_enqueue_scripts', 'wonder_payments_admin_scripts');
    
    // Note.
    add_filter('woocommerce_gateway_title', 'wonder_payments_add_status_to_title', 10, 2);
    
    // Note.
    add_filter('woocommerce_payment_gateways_setting_columns', 'wonder_payments_add_status_column');
    add_action('woocommerce_payment_gateways_setting_column_wonder_status', 'wonder_payments_render_status_column');
}

/**
 * Note.
 */
function wonder_payments_add_modal_link($link, $gateway_id) {
    if ($gateway_id === 'wonder_payments') {
        // Note.
        $link = '<a href="#" class="wonder-payments-manage-link" data-gateway-id="wonder_payments">' . esc_html__('Manage', 'wonder-payment-for-woocommerce') . '</a>';
    }
    return $link;
}

/**
 * Note.
 */
function wonder_payments_ajax_sync_order_status() {
    if (!class_exists('WC_Payment_Gateway')) {
        wp_send_json_error('WooCommerce not loaded');
    }

    if (!class_exists('Wonderpay_Gateway_For_Woocommerce_Gateway')) {
        require_once dirname(__FILE__) . '/includes/class-wonder-payments-gateway.php';
    }

    $gateway = new Wonderpay_Gateway_For_Woocommerce_Gateway();
    $gateway->ajax_sync_order_status();
}


/**
 * Note.
 */
function wonder_payments_plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
    // Note.
    if (strpos($plugin_file, 'wonderpay-gateway-for-woocommerce.php') !== false && $context === 'active') {
        // Note.
        $actions['pricing'] = '<a href="https://wonder.app/pricing" target="_blank">' . esc_html__('View pricing and fees', 'wonder-payment-for-woocommerce') . '</a>';
        $actions['docs'] = '<a href="https://developer.wonder.today/api/api_references/open-api" target="_blank">' . esc_html__('Learn more', 'wonder-payment-for-woocommerce') . '</a>';
        $actions['terms'] = '<a href="https://wonder.app/terms-conditions" target="_blank">' . esc_html__('View terms of service', 'wonder-payment-for-woocommerce') . '</a>';
    }

    return $actions;
}

/**
 * Note.
 */
function wonder_payments_gateway_menu_links($actions, $plugin_file, $plugin_data, $context) {
    // Note.
    $actions['pricing'] = '<a href="https://wonder.app/pricing" target="_blank">' . esc_html__('View pricing and fees', 'wonder-payment-for-woocommerce') . '</a>';
    $actions['docs'] = '<a href="https://developer.wonder.today/api/api_references/open-api" target="_blank">' . esc_html__('Learn more', 'wonder-payment-for-woocommerce') . '</a>';
    $actions['terms'] = '<a href="https://wonder.app/terms-conditions" target="_blank">' . esc_html__('View terms of service', 'wonder-payment-for-woocommerce') . '</a>';
    $actions['hide_suggestions'] = '<a href="#" class="wonder-payments-hide-suggestions" data-nonce="' . wp_create_nonce('wonder_payments_hide_suggestions') . '">' . esc_html__('Hide suggestions', 'wonder-payment-for-woocommerce') . '</a>';

    return $actions;
}

function wonder_payments_add_gateway($gateways)
{
    $gateways[] = 'Wonderpay_Gateway_For_Woocommerce_Gateway';
    return $gateways;
}

/**
 * Register WooCommerce Blocks payment method support.
 */
function wonder_payments_register_blocks_support($payment_method_registry) {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once dirname(__FILE__) . '/includes/class-wonder-payments-blocks-support.php';
    $payment_method_registry->register(new Wonderpay_Gateway_For_Woocommerce_Blocks_Support());
}

/**
 * Note.
 */
function wonder_payments_output_gateway_status() {
    return;
}

/**
 * Note.
 */
function wonder_payments_add_status_column($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        
        // Note.
        if ($key === 'status') {
            $new_columns['wonder_status'] = __('Configuration status', 'wonder-payment-for-woocommerce');
        }
    }
    return $new_columns;
}

/**
 * Note.
 */
function wonder_payments_render_status_column($gateway) {
    
    if ($gateway->id !== 'wonder_payments') {
        // Note.
        echo '-';
        return;
    }
    // Note.
    if (method_exists($gateway, 'get_admin_status_html')) {
        echo wp_kses_post($gateway->get_admin_status_html());
    } else {
        echo esc_html__('Unknown', 'wonder-payment-for-woocommerce');
    }
}

/**
 * Note.
 */
function wonder_payments_add_status_to_title($title, $gateway_id) {
    if ($gateway_id !== 'wonder_payments') {
        return $title;
    }
    
    // Note.
    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $enabled = isset($settings['enabled']) ? $settings['enabled'] : 'no';
    $app_id = isset($settings['app_id']) ? $settings['app_id'] : '';
    $private_key = isset($settings['private_key']) ? $settings['private_key'] : '';
    // Note.
    if ($enabled === 'yes') {
        if (empty($app_id) || empty($private_key)) {
            // Note.
            $title .= ' <span class="wonder-payments-status-badge wonder-payments-status-action-needed">Action needed</span>';
        }
        // Note.
    }
    // Note.

    return $title;
}

function wonder_payments_wc_notice()
{
    echo '<div class="error"><p>';
    /* translators: Admin notice when WooCommerce is not installed or activated */
    echo esc_html__('Wonder Payments requires WooCommerce to be installed and activated.', 'wonder-payment-for-woocommerce');
    echo '</p></div>';
}

// Note.
add_action('wp_ajax_wonder_generate_keys', 'wonder_ajax_generate_keys');
add_action('wp_ajax_wonder_test_config', 'wonder_ajax_test_config');

// Note.
add_action('init', 'wonder_payments_schedule_log_cleanup');

// Note.
add_action('init', 'wonder_payments_schedule_order_expiry_check');

function wonder_payments_schedule_log_cleanup()
{
    if (!wp_next_scheduled('wonder_payments_daily_log_cleanup')) {
        // Note.
        wp_schedule_event(strtotime('tomorrow 3:00am'), 'daily', 'wonder_payments_daily_log_cleanup');
    }
}

function wonder_payments_schedule_order_expiry_check()
{
    if (!wp_next_scheduled('wonder_payments_daily_order_expiry_check')) {
        // Note.
        wp_schedule_event(time(), 'daily', 'wonder_payments_daily_order_expiry_check');
    }
}

// Note.
add_action('wonder_payments_daily_log_cleanup', 'wonder_payments_clean_debug_log');

// Note.
add_action('wonder_payments_daily_order_expiry_check', 'wonder_payments_check_expired_orders');

// Note.
add_action('wp_ajax_wonder_payments_deactivate', 'wonder_payments_deactivate_handler');

// Note.
add_action('wp_ajax_wonder_payments_hide_suggestions', 'wonder_payments_hide_suggestions_handler');

/**
 * Note.
 */
function wonder_payments_hide_suggestions_handler() {
    // Note.
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    // Note.
    check_ajax_referer('wonder_payments_hide_suggestions', 'security');

    // Note.
    // Note.
    wp_send_json_success(array('message' => 'Suggestions hidden'));
}

/**
 * Note.
 */
function wonder_payments_deactivate_handler() {
    // Note.
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    // Note.
    check_ajax_referer('wonder_payments_deactivate', 'security');

    try {
        // Note.
        delete_option('wonder_payments_app_id');
        delete_option('wonder_payments_private_key');
        delete_option('wonder_payments_public_key');
        delete_option('wonder_payments_business_name');
        delete_option('wonder_payments_business_id');
        delete_option('wonder_payments_user_access_token');
        delete_option('wonder_payments_connected');
        delete_option('wonder_payments_temp_app_id');
        delete_option('wonder_payments_temp_private_key');
        delete_option('wonder_payments_qr_code_uuid');
        delete_option('wonder_payments_qr_code_short_link');
        delete_option('wonder_payments_qr_code_status');
        delete_option('wonder_payments_qr_code_login_status');

        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        unset($settings['app_id']);
        unset($settings['private_key']);
        unset($settings['generated_public_key']);
        unset($settings['webhook_public_key']);
        update_option('woocommerce_wonder_payments_settings', $settings);

        // Note.
        wp_clear_scheduled_hook('wonder_payments_daily_log_cleanup');
        wp_clear_scheduled_hook('wonder_payments_hourly_order_expiry_check');
        wp_send_json_success(array('message' => 'Deactivated successfully'));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}

function wonder_payments_clean_debug_log()
{
    $log_file = wonder_payments_get_debug_log_file();

    if (!file_exists($log_file)) {
        return;
    }

    // Note.
    $cutoff_time = time() - (7 * 24 * 60 * 60); // 7Note.

    try {
        // Note.
        $lines = file($log_file);
        if ($lines === false) {
            return;
        }

        // Note.
        $keep_from = 0;
        for ($i = 0; $i < count($lines); $i++) {
            // Note.
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $lines[$i], $matches)) {
                $log_time = strtotime($matches[1]);
                if ($log_time && $log_time > $cutoff_time) {
                    $keep_from = $i;
                    break;
                }
            }
        }

        // Note.
        if ($keep_from > 0) {
            $keep_lines = array_slice($lines, $keep_from);
            file_put_contents($log_file, implode('', $keep_lines));
        } else {
            // Note.
            file_put_contents($log_file, '');
        }
    } catch (Exception $e) {
    }
}

/**
 * Note.
 */
function wonder_payments_check_expired_orders()
{
    // Note.
    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $due_days = isset($settings['due_date']) ? intval($settings['due_date']) : 30;

    if ($due_days < 1) {
        $due_days = 30;
    }

    // Note.
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta query is required for filtering by payment method.
    $args = array(
        'status' => array('pending', 'on-hold'),
        'limit' => 50,  // Note.
        'orderby' => 'date',
        'order' => 'ASC',
        'type' => 'shop_order',
        'date_created' => '>' . gmdate('Y-m-d', strtotime('-30 days')),  // Note.
        // Note.
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_payment_method',
                'value' => 'wonder_payments',
                'compare' => '='
            )
        )
    );

    $orders = wc_get_orders($args);
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

    if (empty($orders)) {
        return;
    }

    $cancelled_count = 0;
    $cutoff_time = time() - ($due_days * 24 * 60 * 60); // due_daysNote.

    foreach ($orders as $order) {
        $order_id = $order->get_id();
        $order_date = $order->get_date_created();
        $order_timestamp = $order_date->getTimestamp();

        // Note.
        if ($order_timestamp < $cutoff_time) {
            // Note.
            $order->update_status('cancelled', sprintf(
                /* translators: %s: number of days */
                __('Order cancelled automatically due to payment expiry (%s days)', 'wonder-payment-for-woocommerce'),
                $due_days
            ));

            // Note.
            $order->add_order_note(sprintf(
                /* translators: %1$s: order creation date, %2$s: due days */
                __('Order created on %1$s and cancelled after %2$s days due to payment expiry.', 'wonder-payment-for-woocommerce'),
                $order_date->date_i18n('Y-m-d H:i:s'),
                $due_days
            ));

            $cancelled_count++;
        }
    }

    $logger = wonder_payments_get_logger();
    if ($cancelled_count > 0) {
        $logger->info('Cancelled ' . $cancelled_count . ' expired orders', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
    } else {
        $logger->debug('No expired orders to cancel', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
    }
}


/**
 * Note.
 */
function wonder_ajax_generate_keys()
{
    // Note.
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }

    // Note.
    check_ajax_referer('wonder_generate_keys', 'security');

    try {
        // Note.
        $keyPair = PaymentSDK::generateKeyPair(4096);

        $private_key = $keyPair['private_key'];
        $public_key = $keyPair['public_key'];

        // Note.
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
        wp_send_json_error(array(
            'message' => 'Failed to generate key pair: ' . $e->getMessage()
        ));
    }
}

/**
 * Note.
 */
function wonder_ajax_test_config()
{
    // Note.
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }

    // Note.
    check_ajax_referer('wonder_test_config', 'security');

    // Note.
    $app_id = isset($_POST['app_id']) ? sanitize_text_field(wp_unslash($_POST['app_id'])) : '';
    // Note.
    $private_key = isset($_POST['private_key']) ? wonder_payments_sanitize_multiline_secret(wp_unslash($_POST['private_key'])) : '';
    $webhook_key = isset($_POST['webhook_key']) ? wonder_payments_sanitize_multiline_secret(wp_unslash($_POST['webhook_key'])) : '';
    $environment = isset($_POST['environment']) ? sanitize_text_field(wp_unslash($_POST['environment'])) : 'yes';

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
 * Note.
 */
function wonder_test_api_connection($app_id, $private_key, $webhook_key = '', $environment = 'yes')
{
    // Note.
    if (!class_exists('PaymentSDK')) {
        return array(
            'success' => false,
            'message' => 'Wonder Authentication SDK is not available. Please ensure the SDK files are properly included.'
        );
    }

    // Note.
    $errors = array();

    if (empty($app_id)) {
        $errors[] = 'App ID is required';
    }

    if (!empty($private_key)) {
        // Note.
        $key = openssl_pkey_get_private($private_key);
        if (!$key) {
            $errors[] = 'Invalid private key format';
        }
    }

    if (!empty($errors)) {
        return array(
            'success' => false,
            'message' => implode(', ', $errors)
        );
    }

    // Note.
    $environment_value = $environment === 'yes' ? 'prod' : 'stg';

    // Note.
    try {
        // Note.
        if (!class_exists('PaymentSDK')) {
            return array(
                'success' => false,
                'message' => 'PaymentSDK class not found. Please check if the SDK is properly installed.'
            );
        }

        // Note.
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
        
                $logger = wonder_payments_get_logger();
                $logger->debug('SDK Options initialized', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
        
        
                // Note.
        $authSDK = new PaymentSDK($options);
        if (!$authSDK) {
            return array(
                'success' => false,
                'message' => 'Failed to initialize PaymentSDK'
            );
        }

        // Note.
        if (!method_exists($authSDK, 'verifySignature')) {
            return array(
                'success' => false,
                'message' => 'verifySignature method not found in PaymentSDK'
            );
        }

        // Note.
        $result = $authSDK->verifySignature();
        // Note.
        if (is_array($result) && isset($result['success']) && $result['success'] === true) {
            // Note.
            $business_name = isset($result['business']['business_name']) ? $result['business']['business_name'] : 'Unknown';
            $business_id = isset($result['business']['id']) ? $result['business']['id'] : 'Unknown';

            /* translators: %1$s: business name, %2$s: business ID */
            $message = sprintf(__('Successful connect to business: %1$s (%2$s)', 'wonder-payment-for-woocommerce'), $business_name, $business_id);

            return array(
                'success' => true,
                'message' => $message
            );
        } else {
            $error_msg = 'Verify failed, please make sure the signature key pair are correct.';
            if (is_array($result) && isset($result['business'])) {
                $error_msg .= ' Business: ' . ($result['business'] ? 'Valid' : 'Invalid');
            }
            return array(
                'success' => false,
                'message' => $error_msg
            );
        }

    } catch (Exception $e) {

        // Note.
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

function wonder_payments_add_custom_ellipsis_menu() {
    return;
}


function wonder_payments_admin_scripts($hook)
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS);
    $is_checkout_settings = wonder_payments_is_checkout_settings_page();
    $is_gateway_section = $is_checkout_settings && $section === 'wonder_payments';
    $is_gateway_list = $is_checkout_settings && empty($section);
    $is_order_screen = wonder_payments_is_order_admin_screen($screen);

    if (!$is_gateway_section && !$is_gateway_list && !$is_order_screen) {
        return;
    }

    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $admin_data = array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'isGatewaySection' => $is_gateway_section,
        'isGatewayList' => $is_gateway_list,
        'isOrderScreen' => $is_order_screen,
        'nonces' => array(
            'generateKeys' => wp_create_nonce('wonder_generate_keys'),
            'testConfig' => wp_create_nonce('wonder_test_config'),
            'modal' => wp_create_nonce('wonder_payments_modal_nonce'),
            'deactivate' => wp_create_nonce('wonder_payments_deactivate'),
            'hideSuggestions' => wp_create_nonce('wonder_payments_hide_suggestions'),
            'config' => wp_create_nonce('wonder_payments_config_nonce'),
        ),
        'gatewayStatus' => array(
            'enabled' => isset($settings['enabled']) ? $settings['enabled'] : 'no',
            'appId' => isset($settings['app_id']) ? $settings['app_id'] : '',
            'privateKey' => isset($settings['private_key']) ? $settings['private_key'] : '',
        ),
        'urls' => array(
            'pricing' => 'https://wonder.app/pricing',
            'support' => 'https://help.wonder.app/',
            'docs' => 'https://developer.wonder.today/api/api_references/open-api',
            'onboarding' => 'https://help.wonder.app/articles/onboard-your-business-in-wonder-app',
        ),
        'strings' => array(
            'enterFields' => __('Please enter App ID and Private Key first.', 'wonder-payment-for-woocommerce'),
            'errorPrefix' => __('Error:', 'wonder-payment-for-woocommerce'),
            'failedToLoadContent' => __('Failed to load content', 'wonder-payment-for-woocommerce'),
            'hideSuggestions' => __('Suggestions hidden', 'wonder-payment-for-woocommerce'),
            'deactivateConfirm' => __('Are you sure you want to delete the App ID and all configuration data? This cannot be undone.', 'wonder-payment-for-woocommerce'),
            'deactivateSuccess' => __('All configuration data was deleted successfully.', 'wonder-payment-for-woocommerce'),
            'deactivateError' => __('Deletion failed, please try again.', 'wonder-payment-for-woocommerce'),
            'syncing' => __('Syncing...', 'wonder-payment-for-woocommerce'),
            'syncSuccess' => __('Sync successful!', 'wonder-payment-for-woocommerce'),
            'syncFailed' => __('Sync failed:', 'wonder-payment-for-woocommerce'),
            'save' => __('Save', 'wonder-payment-for-woocommerce'),
            'saving' => __('Saving...', 'wonder-payment-for-woocommerce'),
            'create' => __('Create', 'wonder-payment-for-woocommerce'),
            'creating' => __('Creating...', 'wonder-payment-for-woocommerce'),
            'created' => __('Created', 'wonder-payment-for-woocommerce'),
            'scanSuccess' => __('Scanned Successfully', 'wonder-payment-for-woocommerce'),
            'loginFirst' => __('Please scan QR code to login first', 'wonder-payment-for-woocommerce'),
            'loadingBusinesses' => __('Loading business list...', 'wonder-payment-for-woocommerce'),
            'noBusiness' => __('No business found', 'wonder-payment-for-woocommerce'),
            'loadBusinessFailed' => __('Failed to load business list', 'wonder-payment-for-woocommerce'),
            'generateLoading' => __('Generating key pair and app_id...', 'wonder-payment-for-woocommerce'),
        ),
    );

    wonder_payments_enqueue_admin_assets($admin_data);
}


/**
 * Note.
 */


function wonder_payments_debug_order()
{


    check_ajax_referer('wonder_payments_debug', 'nonce');


    if (!current_user_can('manage_options')) {


        wp_die('Insufficient permissions');


    }


    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;


    $order = wc_get_order($order_id);


    if (!$order) {


        wp_send_json_error('Order not found');


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
 * Note.
 */


function wonder_payments_get_logs()
{


    check_ajax_referer('wonder_payments_debug', 'nonce');


    if (!current_user_can('manage_options')) {


        wp_die('Insufficient permissions');


    }


    $log_file = wonder_payments_get_debug_log_file();


    $logs = '';


    if (file_exists($log_file)) {


        // Note.


        $lines = file($log_file, FILE_IGNORE_NEW_LINES);
        if (is_array($lines) && !empty($lines)) {
            $logs = implode("\n", array_slice($lines, -100));
        }


        if (!$logs) {


            $logs = 'Unable to read log file';


        }


    } else {


        $logs = 'Log file does not exist: ' . $log_file;


    }


    wp_send_json_success(array('logs' => $logs));


}


add_action('wp_ajax_wonder_payments_get_logs', 'wonder_payments_get_logs');

/**
 * Note.
 */
function wonder_payments_sdk_create_qrcode() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';
        $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
        $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
        $jwtToken = ($environment === 'prod')
            ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
            : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
        $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

        if ($sandbox_mode === '1') {
            $sandboxLogin = get_option('wonder_payments_sandbox_public_login', array());
            $sandboxBusiness = get_option('wonder_payments_sandbox_business', array());
            if (isset($sandboxLogin['data']['access_token'])) {
                $userAccessToken = $sandboxLogin['data']['access_token'];
            }
            if (isset($sandboxBusiness['data']['p_business_id'])) {
                $businessId = $sandboxBusiness['data']['p_business_id'];
            }
        }
        // Note.
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => $environment,
            'jwtToken' => $jwtToken,
            'language' => $language
        ]);
        // Note.
        $qrCode = $sdk->createQRCode();

        wp_send_json_success(array(
            'uuid' => $qrCode['uuid'],
            'sUrl' => $qrCode['sUrl'],
            'lUrl' => $qrCode['lUrl'],
            'expiresAt' => $qrCode['expiresAt']
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to create QR code: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_sdk_create_qrcode', 'wonder_payments_sdk_create_qrcode');

/**
 * Note.
 */
function wonder_payments_sdk_qrcode_status() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $uuid = isset($_GET['uuid']) ? sanitize_text_field(wp_unslash($_GET['uuid'])) : '';

    if (empty($uuid)) {
        wp_send_json_error(array('message' => 'UUID is required'));
    }

    try {
        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';
        $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
        $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
        $jwtToken = ($environment === 'prod')
            ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
            : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
        $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

        // Note.
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => $environment,
            'jwtToken' => $jwtToken,
            'language' => $language
        ]);

        // Note.
        $status = $sdk->getQRCodeStatus($uuid);

        wp_send_json_success(array('data' => $status['data']));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to get QR code status: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_sdk_qrcode_status', 'wonder_payments_sdk_qrcode_status');

/**
 * Note.
 */
function wonder_payments_sdk_get_businesses() {
    $startTime = microtime(true);
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';
        $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
        $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
        $jwtToken = ($environment === 'prod')
            ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
            : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
        $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

        // Note.
        $userAccessToken = get_option('wonder_payments_user_access_token', '');

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        // Note.
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => $environment,
            'jwtToken' => $jwtToken,
            'userAccessToken' => $userAccessToken,
            'language' => $language
        ]);

        // Note.
        $sdkStartTime = microtime(true);
        $businesses = $sdk->getBusinesses();

        // Note.
        $businessData = isset($businesses['data']) ? $businesses['data'] : array();

        // Note.
        if (isset($businessData['data']) && is_array($businessData['data'])) {
            $businessData = $businessData['data'];
        }

        wp_send_json_success(array('data' => $businessData));
    } catch (Exception $e) {
        $message = $e->getMessage();
        if (strpos($message, 'HTTP status: 401') !== false || stripos($message, 'unauthorized') !== false) {
            delete_option('wonder_payments_user_access_token');
            wp_send_json_error(array('message' => 'Access token expired. Please scan QR code to login again.'));
        }
        wp_send_json_error(array('message' => 'Failed to get business list: ' . $message));
    }
}
add_action('wp_ajax_wonder_payments_sdk_get_businesses', 'wonder_payments_sdk_get_businesses');

/**
 * Note.
 */
function wonder_payments_sdk_save_access_token() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $accessToken = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';
    $businessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';

    if (empty($accessToken)) {
        wp_send_json_error(array('message' => 'Access Token is required'));
    }

    // Note.
    update_option('wonder_payments_user_access_token', $accessToken);
    update_option('wonder_payments_business_id', $businessId);

    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
    $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
    $jwtToken = ($environment === 'prod')
        ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
        : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
    $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

    $sdk = new PaymentSDK([
        'appid' => '',
        'signaturePrivateKey' => '',
        'webhookVerifyPublicKey' => '',
        'environment' => $environment,
        'jwtToken' => $jwtToken,
        'userAccessToken' => $accessToken,
        'language' => $language
    ]);

    $userInfo = $sdk->getUserInfo();
    if (isset($userInfo['data'])) {
        update_option('wonder_payments_user_info', $userInfo);
    }

    wp_send_json_success(array(
        'message' => 'Access Token saved successfully',
        'user_info' => $userInfo
    ));
}
add_action('wp_ajax_wonder_payments_sdk_save_access_token', 'wonder_payments_sdk_save_access_token');

/**
 * Note.
 */
function wonder_payments_sdk_get_user_info() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $accessToken = isset($_POST['access_token']) ? sanitize_text_field(wp_unslash($_POST['access_token'])) : '';
    if (empty($accessToken)) {
        wp_send_json_error(array('message' => 'Access Token is required'));
    }

    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
    $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
    $jwtToken = ($environment === 'prod')
        ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
        : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
    $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

    try {
        $sdk = new PaymentSDK([
            'appid' => '',
            'signaturePrivateKey' => '',
            'webhookVerifyPublicKey' => '',
            'environment' => $environment,
            'jwtToken' => $jwtToken,
            'userAccessToken' => $accessToken,
            'language' => $language
        ]);

        $userInfo = $sdk->getUserInfo();
        if (isset($userInfo['data'])) {
            update_option('wonder_payments_user_info', $userInfo);
        }

        wp_send_json_success(array('data' => $userInfo));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to get user info: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_sdk_get_user_info', 'wonder_payments_sdk_get_user_info');

/**
 * Note.
 */
function wonder_payments_sdk_generate_app_id() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';
        $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
        $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
        $jwtToken = ($environment === 'prod')
            ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
            : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
        $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

        // Note.
        $userAccessToken = get_option('wonder_payments_user_access_token', '');
        // Note.
        $businessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';
        if ($sandbox_mode === '1') {
            $sandboxLogin = get_option('wonder_payments_sandbox_public_login', array());
            $sandboxBusiness = get_option('wonder_payments_sandbox_business', array());
            if (isset($sandboxLogin['data']['access_token'])) {
                $userAccessToken = $sandboxLogin['data']['access_token'];
            }
            if (isset($sandboxBusiness['data']['p_business_id'])) {
                $businessId = $sandboxBusiness['data']['p_business_id'];
            }
        }

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }
        if (empty($businessId)) {
            wp_send_json_error(array('message' => 'Business ID is required'));
        }

        try {
            $keyPair = PaymentSDK::generateKeyPair(2048);

            if (!isset($keyPair['private_key']) || !isset($keyPair['public_key'])) {
                throw new Exception('Failed to generate key pair: invalid response');
            }

            $privateKey = $keyPair['private_key'];
            $publicKey = $keyPair['public_key'];

            // Note.
            update_option('wonder_payments_private_key', $privateKey);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Failed to generate key pair: ' . $e->getMessage()));
        }

        // Note.
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => $environment,
            'jwtToken' => $jwtToken,
            'userAccessToken' => $userAccessToken,
            'language' => $language
        ]);
        
        // Note.
        wp_send_json_success(array(
            'message' => 'Key pair generated successfully. Please create App ID manually in Wonder Portal and enter it in the settings.',
            'public_key' => $publicKey,
            'private_key_length' => strlen($privateKey),
            'public_key_length' => strlen($publicKey),
            'note' => 'Copy the public key above and paste it in Wonder Portal when creating your App ID.'
        ));

        wp_send_json_success(array('data' => $result, 'app_id' => $newAppId));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to generate app_id: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_sdk_generate_app_id', 'wonder_payments_sdk_generate_app_id');

/**
 * Note.
 */
function wonder_payments_check_connection() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';

        $connected = false;
        $connectionInfo = array(
            'connected' => false,
            'app_id' => '',
            'business_id' => '',
            'business_name' => ''
        );

        if (!empty($appId)) {
            $connected = true;
            $connectionInfo['connected'] = true;
            $connectionInfo['app_id'] = $appId;

            // Note.
            $businessId = get_option('wonder_payments_business_id', '');
            $businessName = get_option('wonder_payments_business_name', '');

            $connectionInfo['business_id'] = $businessId;
            $connectionInfo['business_name'] = $businessName;
        }

        wp_send_json_success(array('data' => $connectionInfo));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to check connection: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_check_connection', 'wonder_payments_check_connection');

/**
 * Note.
 */
function wonder_payments_load_modal_content() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    ob_start();
    // Note.
    $admin = new Wonder_Payments_Admin();
    $admin->render_setup_page();
    $content = ob_get_clean();

    wp_send_json_success(array('content' => $content));
}
add_action('wp_ajax_wonder_payments_load_modal_content', 'wonder_payments_load_modal_content');

/**
 * Note.
 */
function wonder_payments_get_config() {
    check_ajax_referer('wonder_payments_config_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $settings = get_option('woocommerce_wonder_payments_settings', array());
    
    wp_send_json_success(array(
        'enabled' => isset($settings['enabled']) ? $settings['enabled'] : 'no',
        'app_id' => isset($settings['app_id']) ? $settings['app_id'] : '',
        'private_key' => isset($settings['private_key']) ? $settings['private_key'] : ''
    ));
}
add_action('wp_ajax_wonder_payments_get_config', 'wonder_payments_get_config');

/**
 * Note.
 */
function wonder_payments_save_selected_business() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        $businessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';
        $businessName = isset($_POST['business_name']) ? sanitize_text_field(wp_unslash($_POST['business_name'])) : '';

        if (empty($businessId)) {
            wp_send_json_error(array('message' => 'Business ID is required'));
        }

        // Note.
        update_option('wonder_payments_business_id', $businessId);
        update_option('wonder_payments_business_name', $businessName);

        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $settings['app_id'] = '';
        update_option('woocommerce_wonder_payments_settings', $settings);

        wp_send_json_success(array('message' => 'Business selected successfully'));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to save selected business: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_save_selected_business', 'wonder_payments_save_selected_business');

/**
 * Note.
 */
function wonder_payments_generate_key_pair_only() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {

        // Note.
        $currentBusinessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';

        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $savedAppId = isset($settings['app_id']) ? $settings['app_id'] : '';
        $savedBusinessId = get_option('wonder_payments_business_id', '');

        // Note.
        if ($savedAppId && $savedBusinessId === $currentBusinessId) {
            $storedPrivateKey = isset($settings['private_key']) ? $settings['private_key'] : get_option('wonder_payments_private_key', '');
            $storedPublicKey = isset($settings['generated_public_key']) ? $settings['generated_public_key'] : get_option('wonder_payments_public_key', '');
            $storedWebhookKey = get_option('wonder_payments_webhook_key', '');

            wp_send_json_success(array(
                'data' => array(
                    'public_key' => $storedPublicKey,
                    'private_key' => $storedPrivateKey,
                    'webhook_key' => $storedWebhookKey,
                    'app_id' => $savedAppId
                )
            ));
        }

        // Note.
        if (empty($savedAppId)) {
            update_option('wonder_payments_webhook_key', '');
        }

        // Note.
        $pendingBusinessId = get_option('wonder_payments_pending_business_id', '');
        $pendingPrivateKey = get_option('wonder_payments_pending_private_key', '');
        $pendingPublicKey = get_option('wonder_payments_pending_public_key', '');
        $pendingWebhookKey = get_option('wonder_payments_pending_webhook_key', '');
        $pendingAppId = get_option('wonder_payments_pending_app_id', '');

        if ($pendingBusinessId === $currentBusinessId && $pendingPrivateKey && $pendingPublicKey) {
            wp_send_json_success(array(
                'data' => array(
                    'public_key' => $pendingPublicKey,
                    'private_key' => $pendingPrivateKey,
                    'webhook_key' => $pendingWebhookKey,
                    'app_id' => $pendingAppId
                )
            ));
        }

        // Note.
        $keyPair = PaymentSDK::generateKeyPair(2048);

        if (!isset($keyPair['private_key']) || !isset($keyPair['public_key'])) {
            throw new Exception('Failed to generate key pair: invalid response');
        }

        $privateKey = $keyPair['private_key'];
        $publicKey = $keyPair['public_key'];

        // Note.
        update_option('wonder_payments_pending_private_key', $privateKey);
        update_option('wonder_payments_pending_public_key', $publicKey);
        update_option('wonder_payments_pending_webhook_key', '');
        update_option('wonder_payments_pending_business_id', $currentBusinessId);

        $appIdToReturn = '';

        wp_send_json_success(array(
            'data' => array(
                'public_key' => $publicKey,
                'private_key' => $privateKey,
                'webhook_key' => '',
                'app_id' => $appIdToReturn
            )
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to generate key pair: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_generate_key_pair_only', 'wonder_payments_generate_key_pair_only');

/**
 * Note.
 */
function wonder_payments_sdk_create_app_id() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {

        $businessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';

        if (empty($businessId)) {
            wp_send_json_error(array('message' => 'Business ID is required'));
        }

        // Note.
        $publicKey = get_option('wonder_payments_pending_public_key', '');
        if (empty($publicKey)) {
            $publicKey = get_option('wonder_payments_public_key', '');
        }

        if (empty($publicKey)) {
            wp_send_json_error(array('message' => 'Public key not found. Please generate key pair first.'));
        }

        // Note.
        $userAccessToken = get_option('wonder_payments_user_access_token', '');

        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $sandbox_mode = isset($settings['sandbox_mode']) ? $settings['sandbox_mode'] : '0';
        $environment = ($sandbox_mode === '1') ? 'stg' : 'prod';
        $jwtToken = ($environment === 'prod')
            ? 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiOWE1NGVkNTItN2EyYy00ZDA4LWFhYmMtNGUxYzU0OGZmZjAyIiwiYXBwX2lkIjoiMDJjYTAxZmYtNzZkOC00NTQyLWE1Y2YtMmU1YzY1ZTQ0MmI4IiwiaWF0IjoxNjgyMDEwMTg5LCJleHAiOjE5OTczNzAxODl9.tr9s1n6YmqvubeXmsZvRTBN-B4UcaVOrT4gjjFpO6QM'
            : 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
        $language = ($environment === 'prod') ? 'zh-CN' : 'en-US';

        if ($sandbox_mode === '1') {
            $sandboxLogin = get_option('wonder_payments_sandbox_public_login', array());
            $sandboxBusiness = get_option('wonder_payments_sandbox_business', array());
            if (isset($sandboxLogin['data']['access_token'])) {
                $userAccessToken = $sandboxLogin['data']['access_token'];
            }
            if (isset($sandboxBusiness['data']['p_business_id'])) {
                $businessId = $sandboxBusiness['data']['p_business_id'];
            }
        }

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        $sdk = new PaymentSDK([
            'appid' => '',
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => '',
            'environment' => $environment,
            'jwtToken' => $jwtToken,
            'userAccessToken' => $userAccessToken,
            'language' => $language
        ]);

        // Note.
        $result = $sdk->generateAppId($businessId, $publicKey);

        // Note.
        $newAppId = '';
        if (isset($result['data']['app_id'])) {
            $newAppId = $result['data']['app_id'];
        } elseif (isset($result['data']['data']['app_id'])) {
            $newAppId = $result['data']['data']['app_id'];
        } elseif (isset($result['app_id'])) {
            $newAppId = $result['app_id'];
        }

        // Note.
        $webhookPrivateKey = '';
        if (isset($result['data']['webhook_private_key'])) {
            $webhookPrivateKey = $result['data']['webhook_private_key'];
        } elseif (isset($result['data']['data']['webhook_private_key'])) {
            $webhookPrivateKey = $result['data']['data']['webhook_private_key'];
        } elseif (isset($result['data']['webhook_public_key'])) {
            $webhookPrivateKey = $result['data']['webhook_public_key'];
        } elseif (isset($result['data']['data']['webhook_public_key'])) {
            $webhookPrivateKey = $result['data']['data']['webhook_public_key'];
        } elseif (isset($result['webhook_private_key'])) {
            $webhookPrivateKey = $result['webhook_private_key'];
        } elseif (isset($result['webhook_public_key'])) {
            $webhookPrivateKey = $result['webhook_public_key'];
        }

        // Note.
        if (!empty($newAppId)) {
            update_option('wonder_payments_pending_app_id', $newAppId);
            if (!empty($webhookPrivateKey)) {
                update_option('wonder_payments_pending_webhook_key', $webhookPrivateKey);
            }
        }

        // Note.
        wp_send_json_success(array(
            'app_id' => $newAppId,
            'webhook_private_key' => $webhookPrivateKey
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to create app_id: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_sdk_create_app_id', 'wonder_payments_sdk_create_app_id');

/**
 * Note.
 */
function wonder_payments_clear_all() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {

        // Note.
        delete_option('wonder_payments_app_id');
        delete_option('wonder_payments_business_id');
        delete_option('wonder_payments_business_name');
        delete_option('wonder_payments_public_key');
        delete_option('wonder_payments_private_key');
        delete_option('wonder_payments_user_access_token');
        delete_option('wonder_payments_connected'); // Note.
        delete_option('wonder_payments_pending_private_key');
        delete_option('wonder_payments_pending_public_key');
        delete_option('wonder_payments_pending_webhook_key');
        delete_option('wonder_payments_pending_app_id');
        delete_option('wonder_payments_pending_business_id');

        // Note.
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $settings['app_id'] = '';
        $settings['private_key'] = '';
        $settings['generated_public_key'] = '';
        $settings['webhook_public_key'] = '';
        update_option('woocommerce_wonder_payments_settings', $settings);

        wp_send_json_success(array('message' => 'All data cleared'));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to clear all data: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_clear_all', 'wonder_payments_clear_all');

/**
 * Note.
 */
function wonder_payments_load_settings() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // Note.
        $wcSettings = get_option('woocommerce_wonder_payments_settings', array());
        // Note.
        if (empty($wcSettings)) {
            $wcSettings = get_option('wonder_payments_settings', array());
        }

        wp_send_json_success(array('data' => $wcSettings));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to load settings: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_load_settings', 'wonder_payments_load_settings');

/**
 * Note.
 */
function wonder_payments_save_settings() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // Note.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings array sanitized per-field below.
        $settings = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : array();

    // Note.
    $title = isset($settings['title']) ? sanitize_text_field($settings['title']) : '';
    $description = isset($settings['description']) ? sanitize_textarea_field($settings['description']) : '';
    $sandboxMode = isset($settings['sandbox_mode']) ? ($settings['sandbox_mode'] === '1' ? '1' : '0') : '0';
    $dueDate = isset($settings['due_date']) ? intval($settings['due_date']) : 30;
    $appId = isset($settings['app_id']) ? sanitize_text_field($settings['app_id']) : '';
    $privateKey = isset($settings['private_key']) ? sanitize_textarea_field($settings['private_key']) : '';
    $publicKey = isset($settings['generated_public_key']) ? sanitize_textarea_field($settings['generated_public_key']) : '';
    $webhookPublicKey = isset($settings['webhook_public_key']) ? sanitize_textarea_field($settings['webhook_public_key']) : '';

        // Note.
        if ($dueDate < 1) {
            $dueDate = 1;
        } elseif ($dueDate > 365) {
            $dueDate = 365;
        }

        // Note.
    $environment = ($sandboxMode === '1') ? 'stg' : 'prod';

    // Note.
    $wcSettings = get_option('woocommerce_wonder_payments_settings', array());
    $previousEnvironment = isset($wcSettings['environment']) ? $wcSettings['environment'] : '';
    $environmentChanged = ($previousEnvironment && $previousEnvironment !== $environment);
    if ($environmentChanged) {
        // Environment changed: clear credentials so user must regenerate matching App ID/keys.
        $appId = '';
        $privateKey = '';
        $publicKey = '';
        $webhookPublicKey = '';

        $wcSettings['app_id'] = '';
        $wcSettings['private_key'] = '';
        $wcSettings['generated_public_key'] = '';
        $wcSettings['webhook_public_key'] = '';

        delete_option('wonder_payments_private_key');
        delete_option('wonder_payments_public_key');
        delete_option('wonder_payments_webhook_key');
        delete_option('wonder_payments_pending_private_key');
        delete_option('wonder_payments_pending_public_key');
        delete_option('wonder_payments_pending_webhook_key');
        delete_option('wonder_payments_pending_app_id');
        delete_option('wonder_payments_pending_business_id');
    }

    // Note.
    $existingAppId = isset($wcSettings['app_id']) ? $wcSettings['app_id'] : '';
    $existingPrivateKey = isset($wcSettings['private_key']) ? $wcSettings['private_key'] : '';

    if ($appId !== '' && $privateKey === '' && $existingPrivateKey === '') {
        wp_send_json_error(array('message' => 'Private Key is required when saving App ID.'));
    }

    if ($privateKey !== '' && $appId === '' && $existingAppId === '') {
        wp_send_json_error(array('message' => 'App ID is required when saving Private Key.'));
    }

    if ($appId !== '' && $existingAppId !== '' && $appId !== $existingAppId && $privateKey === '' && $existingPrivateKey !== '') {
        wp_send_json_error(array('message' => 'App ID changed. Please save a matching Private Key.'));
    }

        // Note.
        $wcSettings['title'] = $title;
        $wcSettings['description'] = $description;
        $wcSettings['sandbox_mode'] = $sandboxMode;
        $wcSettings['environment'] = $environment;
        $wcSettings['due_date'] = $dueDate;
        if ($environmentChanged) {
            $wcSettings['app_id'] = '';
            $wcSettings['private_key'] = '';
            $wcSettings['generated_public_key'] = '';
            $wcSettings['webhook_public_key'] = '';
        } else {
            if ($appId !== '') {
                $wcSettings['app_id'] = $appId;
            }
            if ($privateKey !== '') {
                $wcSettings['private_key'] = $privateKey;
            }
            if ($publicKey !== '') {
                $wcSettings['generated_public_key'] = $publicKey;
            }
            if ($webhookPublicKey !== '') {
                $wcSettings['webhook_public_key'] = $webhookPublicKey;
            } elseif ($appId === '') {
                $wcSettings['webhook_public_key'] = '';
            }
        }

        // Note.
        update_option('wonder_payments_settings', $wcSettings);

        // Note.
        update_option('woocommerce_wonder_payments_settings', $wcSettings);

        // Note.
        delete_option('wonder_payments_pending_private_key');
        delete_option('wonder_payments_pending_public_key');
        delete_option('wonder_payments_pending_webhook_key');
        delete_option('wonder_payments_pending_app_id');
        delete_option('wonder_payments_pending_business_id');

        // Note.
        $savedSettings = get_option('woocommerce_wonder_payments_settings', array());
        $verificationPassed = true;

        if (!isset($savedSettings['title']) || $savedSettings['title'] !== $title) {
            $verificationPassed = false;
        }

        if (!isset($savedSettings['description']) || $savedSettings['description'] !== $description) {
            $verificationPassed = false;
        }

        if (!isset($savedSettings['environment']) || $savedSettings['environment'] !== $environment) {
            $verificationPassed = false;
        }

        if ($verificationPassed) {
            // Note.
            $optionName = 'woocommerce_wonder_payments_settings';
            $dbResult = get_option($optionName);

            if ($dbResult) {

                // Note.
                $unserialized = maybe_unserialize($dbResult);
                $logger = wonder_payments_get_logger();
                if (is_array($unserialized)) {
                    $logger->debug('DB Verification: Unserialized successfully', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
                    $logger->debug('DB Verification: Keys = ' . implode(', ', array_keys($unserialized)), array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
                } else {
                    $logger->warning('DB Verification WARNING: Failed to unserialize', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
                }
            } else {
                $logger->error('DB Verification ERROR: Record NOT found in wp_options table!', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
            }
        } else {
            $logger->error('Settings verification failed!', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));
        }

        $sandboxDebug = array();
        if ($sandboxMode === '1') {
            $userInfo = get_option('wonder_payments_user_info', array());
            $referenceId = '';
            if (is_array($userInfo) && isset($userInfo['data']['id'])) {
                $referenceId = $userInfo['data']['id'];
            }
            $accessToken = get_option('wonder_payments_user_access_token', '');

            if ($referenceId && $accessToken) {
                $jwtToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I';
                $sdk = new PaymentSDK([
                    'appid' => '',
                    'signaturePrivateKey' => '',
                    'webhookVerifyPublicKey' => '',
                    'environment' => 'stg',
                    'jwtToken' => $jwtToken,
                    'userAccessToken' => $accessToken,
                    'language' => 'zh-CN'
                ]);

                $sandboxDebug['public_login'] = $sdk->sandboxPublicLogin($referenceId);
                update_option('wonder_payments_sandbox_public_login', $sandboxDebug['public_login']);

                $sandboxUserId = '';
                $sandboxUserToken = '';
                if (isset($sandboxDebug['public_login']['data']['user_id'])) {
                    $sandboxUserId = $sandboxDebug['public_login']['data']['user_id'];
                }
                if (isset($sandboxDebug['public_login']['data']['access_token'])) {
                    $sandboxUserToken = $sandboxDebug['public_login']['data']['access_token'];
                }

                $pBusinessId = get_option('wonder_payments_business_id', '');
                $sandboxBusinessName = get_option('wonder_payments_business_name', '');

                if ($sandboxUserId && $sandboxUserToken && $pBusinessId) {
                    $sandboxDebug['sandbox_business'] = $sdk->sandboxOnboardingBusiness(
                        $sandboxUserId,
                        $sandboxUserToken,
                        $pBusinessId,
                        $sandboxBusinessName
                    );
                    update_option('wonder_payments_sandbox_business', $sandboxDebug['sandbox_business']);
                } else {
                    $sandboxDebug['sandbox_business'] = array(
                        'status' => 0,
                        'body' => array(
                            'message' => 'Missing sandbox_user_id, sandbox_user_token, or p_business_id'
                        )
                    );
                }
            } else {
                $sandboxDebug['public_login'] = array(
                    'status' => 0,
                    'body' => array(
                        'message' => 'Missing reference_id or access_token'
                    )
                );
            }
        }

        $response = array(
            'message' => 'Settings saved successfully',
            'environment_changed' => $environmentChanged ? true : false
        );
        if (!empty($sandboxDebug)) {
            $response['sandbox_debug'] = $sandboxDebug;
        }

        wp_send_json_success($response);
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to save settings: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_save_settings', 'wonder_payments_save_settings');

/**
 * Note.
 */
function wonder_payments_add_modal_to_settings_page() {
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);
    $tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS);
    $section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$page || $page !== 'wc-settings') {
        return;
    }
    if (!$tab || $tab !== 'checkout') {
        return;
    }
    if ($section && !empty($section)) {
        return;
    }

    $logger = wonder_payments_get_logger();
    $logger->debug('Loading modal on payment gateways list page', array( 'source' => 'wonderpay-gateway-for-woocommerce' ));

    ?>
    <!-- Note. -->
    <div id="wonder-settings-modal" class="wonder-modal">
        <div class="wonder-modal-content">
            <div class="wonder-modal-body" id="wonder-modal-body">
                <!-- Note. -->
                <div class="wonder-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e('Loading...', 'wonder-payment-for-woocommerce'); ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
add_action('admin_head', 'wonder_payments_add_modal_to_settings_page');
