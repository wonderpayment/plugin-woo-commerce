<?php
/*
Plugin Name: Wonder Payment For Wooemmerce
Plugin URI: http://localhost:8888/wp-admin/about.php
Description: 7 minutes onboarding, then accepted 34+ payment methods
Version: 1.0.0
Author: Your Name
License: GPL v2 or later
Text Domain: wonder-payments
*/

// Note.
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WONDER_PAYMENTS_PLUGIN_FILE')) {
    define('WONDER_PAYMENTS_PLUGIN_FILE', __FILE__);
}
if (!defined('WONDER_PAYMENTS_BLOCKS_LOG_FILE')) {
    define('WONDER_PAYMENTS_BLOCKS_LOG_FILE', WP_CONTENT_DIR . '/debug1.log');
}

/**
 * Note.
 *
 * @return WC_Logger
 */
function wonder_payments_get_logger() {
    return wc_get_logger();
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
    
    // Note.
    add_filter('woocommerce_gateway_title', 'wonder_payments_add_status_to_title', 10, 2);
    
    // Note.
    add_filter('woocommerce_payment_gateways_setting_columns', 'wonder_payments_add_status_column');
    add_action('woocommerce_payment_gateways_setting_column_wonder_status', 'wonder_payments_render_status_column');
    
    // Note.
    add_action('admin_footer', 'wonder_payments_output_gateway_status');}

/**
 * Note.
 */
function wonder_payments_add_modal_link($link, $gateway_id) {
    if ($gateway_id === 'wonder_payments') {
        // Note.
        $link = '<a href="#" class="wonder-payments-manage-link" data-gateway-id="wonder-payments">' . esc_html__('Manage', 'wonder-payments') . '</a>';
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

    if (!class_exists('WC_Wonder_Payments_Gateway')) {
        require_once dirname(__FILE__) . '/includes/class-wonder-payments-gateway.php';
    }

    $gateway = new WC_Wonder_Payments_Gateway();
    $gateway->ajax_sync_order_status();
}


/**
 * Note.
 */
function wonder_payments_plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
    // Note.
    if (strpos($plugin_file, 'wonder-payments.php') !== false && $context === 'active') {
        // Note.
        $actions['pricing'] = '<a href="https://wonderpayment.com/pricing" target="_blank">' . esc_html__('View pricing and fees', 'wonder-payments') . '</a>';
        $actions['docs'] = '<a href="https://docs.wonderpayment.com" target="_blank">' . esc_html__('Learn more', 'wonder-payments') . '</a>';
        $actions['terms'] = '<a href="https://wonderpayment.com/terms" target="_blank">' . esc_html__('View terms of service', 'wonder-payments') . '</a>';
    }

    return $actions;
}

/**
 * Note.
 */
function wonder_payments_gateway_menu_links($actions, $plugin_file, $plugin_data, $context) {
    // Note.
    $actions['pricing'] = '<a href="https://wonderpayment.com/pricing" target="_blank">' . esc_html__('View pricing and fees', 'wonder-payments') . '</a>';
    $actions['docs'] = '<a href="https://docs.wonderpayment.com" target="_blank">' . esc_html__('Learn more', 'wonder-payments') . '</a>';
    $actions['terms'] = '<a href="https://wonderpayment.com/terms" target="_blank">' . esc_html__('View terms of service', 'wonder-payments') . '</a>';
    $actions['hide_suggestions'] = '<a href="#" class="wonder-payments-hide-suggestions" data-nonce="' . wp_create_nonce('wonder_payments_hide_suggestions') . '">' . esc_html__('Hide suggestions', 'wonder-payments') . '</a>';

    return $actions;
}

function wonder_payments_add_gateway($gateways)
{
    $gateways[] = 'WC_Wonder_Payments_Gateway';
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
    $payment_method_registry->register(new WC_Wonder_Payments_Blocks_Support());
}

/**
 * Note.
 */
function wonder_payments_output_gateway_status() {
    // @codingStandardsIgnoreLine WordPress.Security.NonceVerification.Recommended - Nonce verification not required for GET parameter checks
    if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings' || !isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
        return;
    }
    
    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $enabled = isset($settings['enabled']) ? $settings['enabled'] : 'no';
    $app_id = isset($settings['app_id']) ? $settings['app_id'] : '';
    $private_key = isset($settings['private_key']) ? $settings['private_key'] : '';
    ?>
    <script>
        // Note.
        window.wonderPaymentsGatewayStatus = {
            enabled: '<?php echo esc_js($enabled); ?>',
            app_id: '<?php echo esc_js($app_id); ?>',
            private_key: '<?php echo esc_js($private_key); ?>'
        };
        
        console.log('Wonder Payments Gateway Status:', window.wonderPaymentsGatewayStatus);
        
        // Note.
        (function($) {
            $(document).ready(function() {
                console.log('=== Wonder Payments: jQuery ready in status badge function ===');
                
                // Note.
                var currentEnabled = window.wonderPaymentsGatewayStatus && window.wonderPaymentsGatewayStatus.enabled === 'yes';
                var currentAppId = window.wonderPaymentsGatewayStatus ? window.wonderPaymentsGatewayStatus.app_id : '';
                var currentPrivateKey = window.wonderPaymentsGatewayStatus ? window.wonderPaymentsGatewayStatus.private_key : '';
                
                console.log('=== Wonder Payments: Initial state - enabled:', currentEnabled, ', app_id:', currentAppId, ', private_key:', currentPrivateKey, '===');
                
                // Note.
                $(document).on('change', '.woocommerce-input-toggle', function() {
                    var $toggle = $(this);
                    var $row = $toggle.closest('.woocommerce-list__item');
                    var rowId = $row.attr('id');
                    
                    if (rowId === 'wonder_payments') {
                        // Note.
                        var isEnabled = $toggle.hasClass('woocommerce-input-toggle--enabled');
                        console.log('=== Wonder Payments: Toggle changed, enabled:', isEnabled, '===');
                        
                        // Note.
                        currentEnabled = isEnabled;
                        
                        // Note.
                        updateStatusBadge();
                    }
                });
                
                // Note.
                $(document).ajaxComplete(function(event, xhr, settings) {
                    console.log('=== Wonder Payments: AJAX request completed ===');
                    console.log('=== Wonder Payments: URL:', settings.url, '===');
                    console.log('=== Wonder Payments: Data:', settings.data, '===');
                    
                    // Note.
                    var action = '';
                    if (settings.data && typeof settings.data === 'string') {
                        var match = settings.data.match(/action=([^&]+)/);
                        if (match) {
                            action = match[1];
                        }
                    } else if (settings.data && settings.data.action) {
                        action = settings.data.action;
                    }
                    
                    console.log('=== Wonder Payments: Action:', action, '===');
                    
                    if (action === 'wonder_payments_save_selected_business' || 
                        action === 'wonder_payments_save_settings' ||
                        action === 'wonder_payments_generate_app_id') {
                        console.log('=== Wonder Payments: Configuration saved via AJAX ===');
                        console.log('=== Wonder Payments: Response:', xhr.responseJSON, '===');
                        
                        // Note.
                        setTimeout(function() {
                            console.log('=== Wonder Payments: Reloading configuration ===');
                            reloadConfiguration();
                        }, 500);
                    }
                    
                    // Note.
                    if (action === 'wonder_payments_clear_all') {
                        console.log('=== Wonder Payments: All data cleared via AJAX ===');
                        console.log('=== Wonder Payments: Response:', xhr.responseJSON, '===');
                        
                        // Note.
                        setTimeout(function() {
                            console.log('=== Wonder Payments: Reloading configuration after clear ===');
                            reloadConfiguration();
                        }, 500);
                    }
                });
                
                // Note.
                $(document).on('click', '#panel-settings .btn-primary', function() {
                    console.log('=== Wonder Payments: Settings save button clicked ===');
                    
                    // Note.
                    setTimeout(function() {
                        console.log('=== Wonder Payments: Reloading configuration after settings save ===');
                        reloadConfiguration();
                    }, 1000);
                });
                
                // Note.
                $(document).on('submit', '#wonder-modal-body form', function() {
                    console.log('=== Wonder Payments: Form submitted ===');
                    
                    // Note.
                    setTimeout(function() {
                        console.log('=== Wonder Payments: Reloading configuration after form submit ===');
                        reloadConfiguration();
                    }, 1000);
                });
                
                // Note.
                $(document).on('click', '#wonder-settings-modal .components-modal__header button', function() {
                    console.log('=== Wonder Payments: Modal closed, reloading configuration ===');
                    reloadConfiguration();
                });
                
                // Note.
                function reloadConfiguration() {
                    $.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                        type: 'POST',
                        data: {
                            action: 'wonder_payments_get_config',
                            security: '<?php echo esc_attr(wp_create_nonce('wonder_payments_config_nonce')); ?>'
                        },
                        success: function(response) {
                            console.log('=== Wonder Payments: Configuration reloaded ===', response);
                            if (response.success && response.data) {
                                currentAppId = response.data.app_id || '';
                                currentPrivateKey = response.data.private_key || '';
                                currentEnabled = response.data.enabled === 'yes';
                                
                                console.log('=== Wonder Payments: Updated state - enabled:', currentEnabled, ', app_id:', currentAppId, ', private_key:', currentPrivateKey, '===');
                                
                                // Note.
                                updateStatusBadge();
                            }
                        },
                        error: function() {
                            console.log('=== Wonder Payments: Failed to reload configuration ===');
                        }
                    });
                }
                
                // Note.
                function updateStatusBadge() {
                    console.log('=== Wonder Payments: Updating status badge, enabled:', currentEnabled, ', app_id:', currentAppId, ', private_key:', currentPrivateKey, '===');
                    
                    // Note.
                    var $rows = $('.woocommerce-list__item');
                    
                    if ($rows.length > 0) {
                        $rows.each(function(index) {
                            var $row = $(this);
                            var rowId = $row.attr('id');
                            
                            // Note.
                            var $title = $row.find('h1, h2, h3, h4, h5, h6, .woocommerce-list__item-title, .woocommerce-list__item-name, .components-card__header-title').first();
                            var titleText = $title.text().trim();
                            
                            // Note.
                            if (titleText.indexOf('Wonder Payment') !== -1) {
                                console.log('=== Wonder Payments: Found Wonder Payment row ===');
                                
                                // Note.
                                $title.find('.wonder-payments-status-badge').remove();

                                // Note.
                                if (currentEnabled) {
                                    if (currentAppId === '' || currentPrivateKey === '') {
                                        // Note.
                                        $title.append(' <span class="wonder-payments-status-badge wonder-payments-status-action-needed">Action needed</span>');
                                        console.log('=== Wonder Payments: Added "Action is needed" badge ===');
                                    } else {
                                        // Note.
                                        console.log('=== Wonder Payments: Configuration complete, no badge needed ===');
                                    }
                                } else {
                                    // Note.
                                    console.log('=== Wonder Payments: Gateway not enabled, no badge needed ===');
                                }
                            }
                        });
                    }
                }
                
                // Note.
                setTimeout(function() {
                    console.log('=== Wonder Payments: Delayed execution started ===');
                    updateStatusBadge();
                }, 2000); // Note.
            });
        })(jQuery);
    </script>
    <?php
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
            $new_columns['wonder_status'] = __('Configuration status', 'wonder-payments');
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
        echo esc_html__('Unknown', 'wonder-payments');
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
    echo esc_html__('Wonder Payments requires WooCommerce to be installed and activated.', 'wonder-payments');
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
    $log_file = WP_CONTENT_DIR . '/debug.log';

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
                __('Order cancelled automatically due to payment expiry (%s days)', 'wonder-payments'),
                $due_days
            ));

            // Note.
            $order->add_order_note(sprintf(
                /* translators: %1$s: order creation date, %2$s: due days */
                __('Order created on %1$s and cancelled after %2$s days due to payment expiry.', 'wonder-payments'),
                $order_date->date_i18n('Y-m-d H:i:s'),
                $due_days
            ));

            $cancelled_count++;
        }
    }

    $logger = wonder_payments_get_logger();
    if ($cancelled_count > 0) {
        $logger->info('Cancelled ' . $cancelled_count . ' expired orders', array( 'source' => 'wonder-payments' ));
    } else {
        $logger->debug('No expired orders to cancel', array( 'source' => 'wonder-payments' ));
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
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Private key must keep original formatting.
    $private_key = isset($_POST['private_key']) ? wp_unslash($_POST['private_key']) : '';
    // Note.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Webhook key must keep original formatting.
    $webhook_key = isset($_POST['webhook_key']) ? wp_unslash($_POST['webhook_key']) : '';
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
                $logger->debug('SDK Options initialized', array( 'source' => 'wonder-payments' ));
        
        
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
            $message = sprintf(__('Successful connect to business: %1$s (%2$s)', 'wonder-payments'), $business_name, $business_id);

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

// Note.
add_action('admin_footer', 'wonder_payments_add_custom_ellipsis_menu');

function wonder_payments_add_custom_ellipsis_menu() {
    // Note.
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);
    $tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS);
    if (!$page || $page !== 'wc-settings' || !$tab || $tab !== 'checkout') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        console.log('=== Wonder Payments: Custom ellipsis menu script loaded ===');
        
        // Note.
        window.wonderPaymentsDeactivate = function() {
            if (!confirm('Are you sure you want to delete the App ID and all configuration data? This cannot be undone.')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wonder_payments_deactivate',
                    security: '<?php echo esc_attr(wp_create_nonce('wonder_payments_deactivate')); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Note.
                        localStorage.removeItem('wonder_access_token');
                        localStorage.removeItem('wonder_business_id');
                        localStorage.removeItem('wonder_selected_business_id');
                        localStorage.removeItem('wonder_selected_business_name');
                        
                        // Note.
                        if (typeof window.wonderPaymentsLogout === 'function') {
                            window.wonderPaymentsLogout();
                        }
                        
                        alert('All configuration data was deleted successfully.');
                        
                        // Note.
                        $('#wonder-modal-body').empty();
                        $('#wonder-settings-modal').fadeOut(300);
                    } else {
                        alert('Deletion failed: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('Deletion failed, please try again.');
                }
            });
        };
        
        // Note.
        $(document).on('click', '.gridicons-ellipsis', function(e) {
            var $toggle = $(this);
            var $row = $toggle.closest('.woocommerce-list__item');
            var rowId = $row.attr('id');
            
            console.log('=== Wonder Payments: Ellipsis menu clicked, row ID:', rowId, '===');
            
            // Note.
            if (rowId === 'wonder_payments') {
                console.log('=== Wonder Payments: This is Wonder Payment gateway ===');
                
                // Note.
                setTimeout(function() {
                    // Note.
                    console.log('=== Wonder Payments: All dropdown menus:', $('.woocommerce-ellipsis-menu__content').length, '===');
                    
                    // Note.
                    var $menu = $('.woocommerce-ellipsis-menu__content').first();
                    
                    console.log('=== Wonder Payments: Menu found:', $menu.length, '===');
                    
                    if ($menu.length > 0 && $menu.find('.wonder-payments-menu-item').length === 0) {
                        console.log('=== Wonder Payments: Adding custom menu items ===');
                        
                        var customItems = `
                            <div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item" onclick="window.open('https://wonder.app/pricing', '_blank')">
                                <button type="button" class="components-button">See pricing & fees</button>
                            </div>
                            <div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item" onclick="window.open('https://help.wonder.app/', '_blank')">
                                <button type="button" class="components-button">Get support</button>
                            </div>
                            <div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item" onclick="window.open('https://developers.wonder.app', '_blank')">
                                <button type="button" class="components-button">View documentation</button>
                            </div>
                            <div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item" onclick="window.open('https://help.wonder.app/articles/onboard-your-business-in-wonder-app', '_blank')">
                                <button type="button" class="components-button">Onboarding</button>
                            </div>
                            <div class="woocommerce-ellipsis-menu__content__item wonder-payments-menu-item" onclick="wonderPaymentsDeactivate()">
                                <button type="button" class="components-button components-button__danger">Deactivate</button>
                            </div>
                        `;
                        
                        $menu.append(customItems);
                        console.log('=== Wonder Payments: Custom menu items added ===');
                    }
                }, 50);
            }
        });
    });
    </script>
    <style>
    .wonder-payments-menu-item {
        cursor: pointer;
    }
    .wonder-payments-menu-item button {
        width: 100%;
        text-align: left;
        background: none;
        border: none;
        padding: 8px 12px;
        font-size: 13px;
        cursor: pointer;
        border-radius: 0;
        color: #1e1e1e;
    }
    .wonder-payments-menu-item button:hover {
        background: #f0f0f1;
    }
    </style>
    <?php
}


function wonder_payments_admin_scripts($hook)
{

    // Note.

    if ('woocommerce_page_wc-settings' !== $hook) {

        return;

    }


    // Note.

    $section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS);
    if (!$section || $section !== 'wonder_payments') {

        return;

    }


    // Note.


    $script_url = plugin_dir_url(__FILE__) . 'assets/js/admin-fixed.js';


    $script_path = plugin_dir_path(__FILE__) . 'assets/js/admin-fixed.js';

    if (!file_exists($script_path)) {
        // Note.
        $script_url = plugin_dir_url(__FILE__) . 'assets/js/admin.js';


        $script_path = plugin_dir_path(__FILE__) . 'assets/js/admin.js';


        if (!file_exists($script_path)) {
            return;
        }


    }


    // Note.


    $script_version = filemtime($script_path);
    // Note.


    wp_register_script(


        'wonder-payments-admin',


        $script_url,


        array('jquery'),


        $script_version,


        true


    );


    // Note.


    wp_enqueue_script('wonder-payments-admin');


    // Note.


    wp_localize_script('wonder-payments-admin', 'wonder_payments_admin', array(


        'ajax_url' => admin_url('admin-ajax.php'),


        'generate_nonce' => wp_create_nonce('wonder_generate_keys'),


        'test_nonce' => wp_create_nonce('wonder_test_config'),


        'strings' => array(


            /* translators: Label for the public key field */


            'public_key_label' => __('Public Key (upload to Wonder Portal):', 'wonder-payments'),


            /* translators: Important notice label */


            'important' => __('Important:', 'wonder-payments'),


            /* translators: Instruction to save private key */


            'save_changes' => __('Please click "Save changes" to save the private key.', 'wonder-payments'),


            /* translators: Instruction to enter App ID and Private Key */


            'enter_fields' => __('Please enter App ID and Private Key first.', 'wonder-payments'),


            /* translators: Error message label */


            'error' => __('Error:', 'wonder-payments')


        )


    ));

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


    $log_file = WP_CONTENT_DIR . '/debug.log';


    $logs = '';


    if (file_exists($log_file)) {


        // Note.


        $logs = shell_exec('tail -100 ' . escapeshellarg($log_file));


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
        // Note.
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'language' => 'en-US'
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

        // Note.
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBpZCI6IjgwYTk4NGUyLTRlYzQtNGQwNi1hYmE5LWU0MzAxMDk1NmUxMyIsImlhdCI6MTY4MTM5MjM5MiwiZXhwIjoxOTk2NzUyMzkyfQ.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'language' => 'en-US'
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
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBpZCI6IjgwYTk4NGUyLTRlYzQtNGQwNi1hYmE5LWU0MzAxMDk1NmUxMyIsImlhdCI6MTY4MTM5MjM5MiwiZXhwIjoxOTk2NzUyMzkyfQ.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'userAccessToken' => $userAccessToken,
            'language' => 'en-US'
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
    wp_send_json_success(array('message' => 'Access Token saved successfully'));
}
add_action('wp_ajax_wonder_payments_sdk_save_access_token', 'wonder_payments_sdk_save_access_token');

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

        // Note.
        $userAccessToken = get_option('wonder_payments_user_access_token', '');

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        // Note.
        $businessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';

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
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBpZCI6IjgwYTk4NGUyLTRlYzQtNGQwNi1hYmE5LWU0MzAxMDk1NmUxMyIsImlhdCI6MTY4MTM5MjM5MiwiZXhwIjoxOTk2NzUyMzkyfQ.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'userAccessToken' => $userAccessToken,
            'language' => 'en-US'
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

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        // Note.
        $sdk = new PaymentSDK([
            'appid' => '',
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => '',
            'environment' => 'stg',
            'jwtToken' => '...',
            'userAccessToken' => $userAccessToken,
            'language' => 'en-US'
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
                    $logger->debug('DB Verification: Unserialized successfully', array( 'source' => 'wonder-payments' ));
                    $logger->debug('DB Verification: Keys = ' . implode(', ', array_keys($unserialized)), array( 'source' => 'wonder-payments' ));
                } else {
                    $logger->warning('DB Verification WARNING: Failed to unserialize', array( 'source' => 'wonder-payments' ));
                }
            } else {
                $logger->error('DB Verification ERROR: Record NOT found in wp_options table!', array( 'source' => 'wonder-payments' ));
            }
        } else {
            $logger->error('Settings verification failed!', array( 'source' => 'wonder-payments' ));
        }

        wp_send_json_success(array('message' => 'Settings saved successfully'));
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
    $logger->debug('Loading modal on payment gateways list page', array( 'source' => 'wonder-payments' ));

    ?>
    <!-- Note. -->
    <div id="wonder-settings-modal" class="wonder-modal" style="display: none;">
        <div class="wonder-modal-content">
            <div class="wonder-modal-body" id="wonder-modal-body">
                <!-- Note. -->
                <div class="wonder-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e('Loading...', 'wonder-payments'); ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        #wonder-settings-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 999999;
            display: none;
        }

        .wonder-modal-content {
            position: relative;
            background-color: #fff;
            margin: 2% auto;
            width: 95%;
            max-width: 1000px;
            max-height: 96vh;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .wonder-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-bottom: none;
        }

        .wonder-modal-header h2 {
            margin: 0;
            font-size: 24px;
            color: #fff;
            font-weight: 600;
        }

        .wonder-modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            font-size: 36px;
            cursor: pointer;
            color: #fff;
            padding: 0;
            width: 40px;
            height: 40px;
            line-height: 1;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .wonder-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .wonder-modal-body {
            padding: 0;
            overflow-y: auto;
            width: 100%;
            height: 620px;
        }

        .wonder-loading {
            text-align: center;
            padding: 50px;
        }

        .wonder-loading .spinner {
            display: inline-block;
            float: none;
            margin: 0 10px 0 0;
        }

        /* Note. */
        .wonder-payments-admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            position: relative;
        }

        .wonder-payments-admin-header h2 {
            margin: 0;
        }

        .wonder-payments-menu-container {
            position: relative;
        }

        .wonder-payments-menu-button {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wonder-payments-menu-button:hover {
            background: #f6f7f7;
            border-color: #c3c4c7;
        }

        .wonder-payments-menu-button svg {
            width: 18px;
            height: 18px;
            color: #1d2327;
        }

        .wonder-payments-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            min-width: 220px;
            z-index: 1000;
            overflow: hidden;
        }

        .wonder-payments-dropdown-menu .menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            font-size: 14px;
            color: #1d2327;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .wonder-payments-dropdown-menu .menu-item:hover {
            background: #f6f7f7;
        }

        .wonder-payments-dropdown-menu .menu-item.menu-item-danger {
            color: #d63638;
            border-top: 1px solid #f0f0f1;
            padding-top: 16px;
        }

        .wonder-payments-dropdown-menu .menu-item.menu-item-danger:hover {
            background: #fef7f7;
        }

        .wonder-payments-dropdown-menu .menu-icon {
            font-size: 16px;
        }

        
    </style>

    <script>
        console.log('=== Wonder Payments: Modal script loaded ===');
        console.log('=== Wonder Payments: Current URL:', window.location.href, '===');

        jQuery(document).ready(function ($) {
            console.log('=== Wonder Payments: jQuery document ready ===');
            
            // Note.
            var checkInterval = setInterval(function() {
                var $manageBtns = $('.components-button.is-secondary');
                if ($manageBtns.length > 0) {
                    clearInterval(checkInterval);
                    var $wonderBtn = $manageBtns.eq(0);
                    console.log('=== Wonder Payments: Found Wonder Payment button ===');
                    
                    // Note.
                    var $newBtn = $wonderBtn.clone();
                    $newBtn.attr('href', 'javascript:void(0)');
                    $newBtn.removeAttr('onclick');
                    $newBtn.off();
                    
                    // Note.
                    $newBtn.on('click', function(e) {
                        console.log('=== Wonder Payment button clicked ===');
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        
                        // Note.
                        $('#wonder-settings-modal').fadeIn(300);

                        // Note.
                        $.ajax({
                            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                            type: 'POST',
                            data: {
                                action: 'wonder_payments_load_modal_content',
                                security: '<?php echo esc_attr(wp_create_nonce('wonder_payments_modal_nonce')); ?>'
                            },
                            success: function (response) {
                                console.log('Wonder Payments: AJAX response', response);
                                if (response.success) {
                                    $('#wonder-modal-body').html(response.data.content);
                                } else {
                                    $('#wonder-modal-body').html('<p class="error"><?php echo esc_js(__('Failed to load content', 'wonder-payments')); ?></p>');
                                }
                            },
                            error: function () {
                                $('#wonder-modal-body').html('<p class="error"><?php echo esc_js(__('Failed to load content', 'wonder-payments')); ?></p>');
                            }
                        });
                        
                        return false;
                    });
                    
                    // Note.
                    $wonderBtn.replaceWith($newBtn);
                    console.log('=== Wonder Payments: Button replaced ===');
                }
            }, 100);
            
            // Note.
            setTimeout(function() {
                clearInterval(checkInterval);
            }, 5000);

            // Note.
            $(document).on('click', '#close-wonder-modal', function () {
                // Note.
                if (window.wonderPaymentsPollInterval) {
                    clearInterval(window.wonderPaymentsPollInterval);
                    window.wonderPaymentsPollInterval = null;
                    console.log('Cleared poll interval on modal close');
                }
                // Note.
                $('#wonder-modal-body').empty();
                $('#wonder-settings-modal').fadeOut(300);
            });

            // Note.
            $('#wonder-settings-modal').on('click', function (e) {
                if (e.target === this) {
                    // Note.
                    if (window.wonderPaymentsPollInterval) {
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                        console.log('Cleared poll interval on modal close');
                    }
                    // Note.
                    $('#wonder-modal-body').empty();
                    $(this).fadeOut(300);
                }
            });

            // Note.
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && $('#wonder-settings-modal').is(':visible')) {
                    // Note.
                    if (window.wonderPaymentsPollInterval) {
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                        console.log('Cleared poll interval on modal close');
                    }
                    // Note.
                    $('#wonder-modal-body').empty();
                    $('#wonder-settings-modal').fadeOut(300);
                }
            });

            // Note.
            $(document).on('click', '.wonder-payments-menu-button', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $menu = $(this).siblings('.wonder-payments-dropdown-menu');
                $menu.toggle();
            });

            // Note.
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.wonder-payments-menu-container').length) {
                    $('.wonder-payments-dropdown-menu').hide();
                }
            });

            // Note.
            $(document).on('click', '.hide-suggestions', function(e) {
                e.preventDefault();
                $('.wonder-payments-dropdown-menu').hide();

                // Note.
                $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();

                // Note.
                localStorage.setItem('wonder_payments_suggestions_hidden', 'true');

                // Note.
                alert('<?php echo esc_js(__('Suggestions hidden', 'wonder-payments')); ?>');
            });

            // Note.
            if (localStorage.getItem('wonder_payments_suggestions_hidden') === 'true') {
                $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();
            }

            // Note.
            $(document).on('click', '.wonder-payments-hide-suggestions', function(e) {
                e.preventDefault();

                // Note.
                $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();

                // Note.
                localStorage.setItem('wonder_payments_suggestions_hidden', 'true');

                // Note.
                alert('<?php echo esc_js(__('Suggestions hidden', 'wonder-payments')); ?>');
            });

            
        });
    </script>
    
    <style>
        .wonder-payments-status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        .wonder-payments-status-action-needed {
            background: var(--Alias-bg-bg-surface-warning, #fff2d7);
            color: var(--Alias-text-text-warning, #4d3716);
        }
    </style>
    <?php
}
add_action('admin_head', 'wonder_payments_add_modal_to_settings_page');
