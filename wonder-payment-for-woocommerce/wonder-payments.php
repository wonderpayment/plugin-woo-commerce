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

// 防止直接访问
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
 * 获取 WooCommerce Logger 实例
 *
 * @return WC_Logger
 */
function wonder_payments_get_logger() {
    return wc_get_logger();
}

// 在你的插件主文件顶部（wonder-payments.php）
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // 尝试直接包含SDK文件
    if (file_exists(__DIR__ . '/vendor/wonderpayment/sdk/src/PaymentSDK.php')) {
        require_once __DIR__ . '/vendor/wonderpayment/sdk/src/PaymentSDK.php';
    }
}

// 检查 WooCommerce 是否激活
// 使用优先级 0 确保在 WooCommerce 完全初始化后再加载
add_action('plugins_loaded', 'wonder_payments_init_gateway', 0);

// 声明与区块结账兼容，避免后台显示“不兼容”
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

    // 包含必要的文件
    require_once dirname(__FILE__) . '/includes/class-wonder-payments-gateway.php';
    require_once dirname(__FILE__) . '/includes/Wonder_Payments_Admin.php';

    // WooCommerce Blocks 兼容支持
    add_action('woocommerce_blocks_payment_method_type_registration', 'wonder_payments_register_blocks_support');

    // 添加支付网关
    add_filter('woocommerce_payment_gateways', 'wonder_payments_add_gateway');

    // 添加自定义管理链接 - 尝试多个可能的过滤器
    add_filter('woocommerce_payment_gateway_settings_link', 'wonder_payments_add_modal_link', 10, 2);
    add_filter('plugin_action_links', 'wonder_payments_plugin_action_links', 10, 4);
    add_filter('plugin_action_links_woocommerce/woocommerce.php', 'wonder_payments_gateway_menu_links', 10, 4);
    
    // 修改支付网关标题，添加状态指示
    add_filter('woocommerce_gateway_title', 'wonder_payments_add_status_to_title', 10, 2);
    
    // 添加自定义状态列
    add_filter('woocommerce_payment_gateways_setting_columns', 'wonder_payments_add_status_column');
    add_action('woocommerce_payment_gateways_setting_column_wonder_status', 'wonder_payments_render_status_column');
    
    // 输出网关状态信息到 JavaScript
    add_action('admin_footer', 'wonder_payments_output_gateway_status');}

/**
 * 修改支付网关的管理链接，改为打开模态窗口
 */
function wonder_payments_add_modal_link($link, $gateway_id) {
    if ($gateway_id === 'wonder_payments') {
        // 返回自定义的链接，点击打开模态窗口
        $link = '<a href="#" class="wonder-payments-manage-link" data-gateway-id="wonder-payments">' . esc_html__('Manage', 'wonder-payments') . '</a>';
    }
    return $link;
}


/**
 * 修改插件操作链接
 */
function wonder_payments_plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
    // 检查是否是我们的插件
    if (strpos($plugin_file, 'wonder-payments.php') !== false && $context === 'active') {
        // 添加自定义链接到插件列表页面
        $actions['pricing'] = '<a href="https://wonderpayment.com/pricing" target="_blank">' . esc_html__('查看定价和费用', 'wonder-payments') . '</a>';
        $actions['docs'] = '<a href="https://docs.wonderpayment.com" target="_blank">' . esc_html__('了解更多', 'wonder-payments') . '</a>';
        $actions['terms'] = '<a href="https://wonderpayment.com/terms" target="_blank">' . esc_html__('查看服务条款', 'wonder-payments') . '</a>';
    }

    return $actions;
}

/**
 * 为WooCommerce支付网关三点菜单添加链接
 */
function wonder_payments_gateway_menu_links($actions, $plugin_file, $plugin_data, $context) {
    // 添加自定义链接
    $actions['pricing'] = '<a href="https://wonderpayment.com/pricing" target="_blank">' . esc_html__('查看定价和费用', 'wonder-payments') . '</a>';
    $actions['docs'] = '<a href="https://docs.wonderpayment.com" target="_blank">' . esc_html__('了解更多', 'wonder-payments') . '</a>';
    $actions['terms'] = '<a href="https://wonderpayment.com/terms" target="_blank">' . esc_html__('查看服务条款', 'wonder-payments') . '</a>';
    $actions['hide_suggestions'] = '<a href="#" class="wonder-payments-hide-suggestions" data-nonce="' . wp_create_nonce('wonder_payments_hide_suggestions') . '">' . esc_html__('隐藏建议', 'wonder-payments') . '</a>';

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
 * 输出网关状态信息到 JavaScript
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
        // Wonder Payments 网关状态
        window.wonderPaymentsGatewayStatus = {
            enabled: '<?php echo esc_js($enabled); ?>',
            app_id: '<?php echo esc_js($app_id); ?>',
            private_key: '<?php echo esc_js($private_key); ?>'
        };
        
        console.log('Wonder Payments Gateway Status:', window.wonderPaymentsGatewayStatus);
        
        // 等待 jQuery 加载完成后添加状态标记
        (function($) {
            $(document).ready(function() {
                console.log('=== Wonder Payments: jQuery ready in status badge function ===');
                
                // 初始状态
                var currentEnabled = window.wonderPaymentsGatewayStatus && window.wonderPaymentsGatewayStatus.enabled === 'yes';
                var currentAppId = window.wonderPaymentsGatewayStatus ? window.wonderPaymentsGatewayStatus.app_id : '';
                var currentPrivateKey = window.wonderPaymentsGatewayStatus ? window.wonderPaymentsGatewayStatus.private_key : '';
                
                console.log('=== Wonder Payments: Initial state - enabled:', currentEnabled, ', app_id:', currentAppId, ', private_key:', currentPrivateKey, '===');
                
                // 监听启用/禁用开关的变化
                $(document).on('change', '.woocommerce-input-toggle', function() {
                    var $toggle = $(this);
                    var $row = $toggle.closest('.woocommerce-list__item');
                    var rowId = $row.attr('id');
                    
                    if (rowId === 'wonder_payments') {
                        // 检查开关是否启用
                        var isEnabled = $toggle.hasClass('woocommerce-input-toggle--enabled');
                        console.log('=== Wonder Payments: Toggle changed, enabled:', isEnabled, '===');
                        
                        // 更新状态
                        currentEnabled = isEnabled;
                        
                        // 立即更新状态标记
                        updateStatusBadge();
                    }
                });
                
                // 监听 AJAX 请求完成事件
                $(document).ajaxComplete(function(event, xhr, settings) {
                    console.log('=== Wonder Payments: AJAX request completed ===');
                    console.log('=== Wonder Payments: URL:', settings.url, '===');
                    console.log('=== Wonder Payments: Data:', settings.data, '===');
                    
                    // 检查是否是保存 AppID 或保存设置的请求
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
                        
                        // 延迟执行，等待保存完成
                        setTimeout(function() {
                            console.log('=== Wonder Payments: Reloading configuration ===');
                            reloadConfiguration();
                        }, 500);
                    }
                    
                    // 检查是否是清除所有数据的请求
                    if (action === 'wonder_payments_clear_all') {
                        console.log('=== Wonder Payments: All data cleared via AJAX ===');
                        console.log('=== Wonder Payments: Response:', xhr.responseJSON, '===');
                        
                        // 延迟执行，等待清除完成
                        setTimeout(function() {
                            console.log('=== Wonder Payments: Reloading configuration after clear ===');
                            reloadConfiguration();
                        }, 500);
                    }
                });
                
                // 监听 Settings 面板的保存按钮
                $(document).on('click', '#panel-settings .btn-primary', function() {
                    console.log('=== Wonder Payments: Settings save button clicked ===');
                    
                    // 延迟执行，等待保存完成
                    setTimeout(function() {
                        console.log('=== Wonder Payments: Reloading configuration after settings save ===');
                        reloadConfiguration();
                    }, 1000);
                });
                
                // 监听表单提交事件
                $(document).on('submit', '#wonder-modal-body form', function() {
                    console.log('=== Wonder Payments: Form submitted ===');
                    
                    // 延迟执行，等待保存完成
                    setTimeout(function() {
                        console.log('=== Wonder Payments: Reloading configuration after form submit ===');
                        reloadConfiguration();
                    }, 1000);
                });
                
                // 监听模态框关闭事件，重新加载配置
                $(document).on('click', '#wonder-settings-modal .components-modal__header button', function() {
                    console.log('=== Wonder Payments: Modal closed, reloading configuration ===');
                    reloadConfiguration();
                });
                
                // 重新加载配置的函数
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
                                
                                // 更新状态标记
                                updateStatusBadge();
                            }
                        },
                        error: function() {
                            console.log('=== Wonder Payments: Failed to reload configuration ===');
                        }
                    });
                }
                
                // 定义更新状态标记的函数
                function updateStatusBadge() {
                    console.log('=== Wonder Payments: Updating status badge, enabled:', currentEnabled, ', app_id:', currentAppId, ', private_key:', currentPrivateKey, '===');
                    
                    // 查找所有网关行
                    var $rows = $('.woocommerce-list__item');
                    
                    if ($rows.length > 0) {
                        $rows.each(function(index) {
                            var $row = $(this);
                            var rowId = $row.attr('id');
                            
                            // 查找包含 "Wonder Payment" 文本的标题元素
                            var $title = $row.find('h1, h2, h3, h4, h5, h6, .woocommerce-list__item-title, .woocommerce-list__item-name, .components-card__header-title').first();
                            var titleText = $title.text().trim();
                            
                            // 如果标题包含 "Wonder Payment"
                            if (titleText.indexOf('Wonder Payment') !== -1) {
                                console.log('=== Wonder Payments: Found Wonder Payment row ===');
                                
                                // 移除旧的状态标记
                                $title.find('.wonder-payments-status-badge').remove();

                                // 添加状态标记
                                if (currentEnabled) {
                                    if (currentAppId === '' || currentPrivateKey === '') {
                                        // 启用但未配置，添加"Action is needed"标记
                                        $title.append(' <span class="wonder-payments-status-badge wonder-payments-status-action-needed">Action needed</span>');
                                        console.log('=== Wonder Payments: Added "Action is needed" badge ===');
                                    } else {
                                        // 已配置，不添加任何标记
                                        console.log('=== Wonder Payments: Configuration complete, no badge needed ===');
                                    }
                                } else {
                                    // 未启用，不添加任何标记
                                    console.log('=== Wonder Payments: Gateway not enabled, no badge needed ===');
                                }
                            }
                        });
                    }
                }
                
                // 延迟执行，等待 React 渲染完成
                setTimeout(function() {
                    console.log('=== Wonder Payments: Delayed execution started ===');
                    updateStatusBadge();
                }, 2000); // 延迟 2 秒执行
            });
        })(jQuery);
    </script>
    <?php
}

/**
 * 添加自定义状态列
 */
function wonder_payments_add_status_column($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        
        // 在状态列后添加我们的自定义列
        if ($key === 'status') {
            $new_columns['wonder_status'] = __('配置状态', 'wonder-payments');
        }
    }
    return $new_columns;
}

/**
 * 输出自定义状态列内容
 */
function wonder_payments_render_status_column($gateway) {
    
    if ($gateway->id !== 'wonder_payments') {
        // 其他支付网关不显示任何内容
        echo '-';
        return;
    }
    // 我们的支付网关显示自定义状态
    if (method_exists($gateway, 'get_admin_status_html')) {
        echo wp_kses_post($gateway->get_admin_status_html());
    } else {
        echo esc_html__('未知', 'wonder-payments');
    }
}

/**
 * 为支付网关标题添加状态指示
 */
function wonder_payments_add_status_to_title($title, $gateway_id) {
    if ($gateway_id !== 'wonder_payments') {
        return $title;
    }
    
    // 获取网关配置
    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $enabled = isset($settings['enabled']) ? $settings['enabled'] : 'no';
    $app_id = isset($settings['app_id']) ? $settings['app_id'] : '';
    $private_key = isset($settings['private_key']) ? $settings['private_key'] : '';
    // 检查状态
    if ($enabled === 'yes') {
        if (empty($app_id) || empty($private_key)) {
            // 启用但未配置，添加"Action is needed"标记
            $title .= ' <span class="wonder-payments-status-badge wonder-payments-status-action-needed">Action needed</span>';
        }
        // 已配置，不添加任何标记（显示"已激活"）
    }
    // 未启用，不添加任何标记（显示"未激活"）

    return $title;
}

function wonder_payments_wc_notice()
{
    echo '<div class="error"><p>';
    /* translators: Admin notice when WooCommerce is not installed or activated */
    echo esc_html__('Wonder Payments requires WooCommerce to be installed and activated.', 'wonder-payments');
    echo '</p></div>';
}

// 注册 AJAX 处理函数
add_action('wp_ajax_wonder_generate_keys', 'wonder_ajax_generate_keys');
add_action('wp_ajax_wonder_test_config', 'wonder_ajax_test_config');

// 注册定时清理日志的定时任务
add_action('init', 'wonder_payments_schedule_log_cleanup');

// 注册定时检查过期订单的任务
add_action('init', 'wonder_payments_schedule_order_expiry_check');

function wonder_payments_schedule_log_cleanup()
{
    if (!wp_next_scheduled('wonder_payments_daily_log_cleanup')) {
        // 安排每天凌晨3点执行清理任务
        wp_schedule_event(strtotime('tomorrow 3:00am'), 'daily', 'wonder_payments_daily_log_cleanup');
    }
}

function wonder_payments_schedule_order_expiry_check()
{
    if (!wp_next_scheduled('wonder_payments_daily_order_expiry_check')) {
        // 安排每天检查一次过期订单（降低频率以优化性能）
        wp_schedule_event(time(), 'daily', 'wonder_payments_daily_order_expiry_check');
    }
}

// 执行日志清理
add_action('wonder_payments_daily_log_cleanup', 'wonder_payments_clean_debug_log');

// 执行过期订单检查
add_action('wonder_payments_daily_order_expiry_check', 'wonder_payments_check_expired_orders');

// 注册Deactivate AJAX处理
add_action('wp_ajax_wonder_payments_deactivate', 'wonder_payments_deactivate_handler');

// 注册隐藏建议AJAX处理
add_action('wp_ajax_wonder_payments_hide_suggestions', 'wonder_payments_hide_suggestions_handler');

/**
 * 隐藏建议处理函数
 */
function wonder_payments_hide_suggestions_handler() {
    // 检查权限
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    // 检查nonce
    check_ajax_referer('wonder_payments_hide_suggestions', 'security');

    // 这里可以添加额外的逻辑，比如保存到数据库
    // 目前只是返回成功
    wp_send_json_success(array('message' => 'Suggestions hidden'));
}

/**
 * Deactivate处理函数 - 删除App ID和所有配置信息
 */
function wonder_payments_deactivate_handler() {
    // 检查权限
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    // 检查nonce
    check_ajax_referer('wonder_payments_deactivate', 'security');

    try {
        // 删除所有Wonder Payments相关的选项
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

        // 删除WooCommerce设置中的Wonder Payments配置
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        unset($settings['app_id']);
        unset($settings['private_key']);
        unset($settings['generated_public_key']);
        unset($settings['webhook_public_key']);
        update_option('woocommerce_wonder_payments_settings', $settings);

        // 删除定时任务
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
        } else {
            // 如果所有日志都是7天前的，清空文件
            file_put_contents($log_file, '');
        }
    } catch (Exception $e) {
    }
}

/**
 * 检查并取消过期的Wonder Payments订单
 */
function wonder_payments_check_expired_orders()
{
    // 获取due_date设置
    $settings = get_option('woocommerce_wonder_payments_settings', array());
    $due_days = isset($settings['due_date']) ? intval($settings['due_date']) : 30;

    if ($due_days < 1) {
        $due_days = 30;
    }

    // 查找所有待支付的Wonder Payments订单
    $args = array(
        'status' => array('pending', 'on-hold'),
        'limit' => 50,  // 减少限制数量，提高性能
        'orderby' => 'date',
        'order' => 'ASC',
        'type' => 'shop_order',
        'date_created' => '>' . gmdate('Y-m-d', strtotime('-30 days')),  // 只查询30天内的订单
        // @codingStandardsIgnoreLine WordPress.DB.SlowDBQuery.slow_db_query_meta_query - meta_query 是 WooCommerce 订单查询的标准做法，已通过限制查询范围和频率进行优化
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

    if (empty($orders)) {
        return;
    }

    $cancelled_count = 0;
    $cutoff_time = time() - ($due_days * 24 * 60 * 60); // due_days天前

    foreach ($orders as $order) {
        $order_id = $order->get_id();
        $order_date = $order->get_date_created();
        $order_timestamp = $order_date->getTimestamp();

        // 检查订单是否过期
        if ($order_timestamp < $cutoff_time) {
            // 取消订单
            $order->update_status('cancelled', sprintf(
                /* translators: %s: number of days */
                __('Order cancelled automatically due to payment expiry (%s days)', 'wonder-payments'),
                $due_days
            ));

            // 添加订单备注
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
 * 生成密钥的 AJAX 处理函数 - 使用 SDK
 */
function wonder_ajax_generate_keys()
{
    // 检查权限
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }

    // 检查 nonce
    check_ajax_referer('wonder_generate_keys', 'security');

    try {
        // 始终使用 SDK 生成新的密钥对
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
        wp_send_json_error(array(
            'message' => 'Failed to generate key pair: ' . $e->getMessage()
        ));
    }
}

/**
 * 测试配置的 AJAX 处理函数 - 使用SDK进行验证
 */
function wonder_ajax_test_config()
{
    // 检查权限
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }

    // 检查 nonce
    check_ajax_referer('wonder_test_config', 'security');

    // 获取 POST 数据
    $app_id = isset($_POST['app_id']) ? sanitize_text_field(wp_unslash($_POST['app_id'])) : '';
    // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - 私钥是密钥数据，需要保持原始格式，不进行清理
    $private_key = isset($_POST['private_key']) ? wp_unslash($_POST['private_key']) : '';
    // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - Webhook 密钥是密钥数据，需要保持原始格式，不进行清理
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
 * 测试 API 连接函数 - 使用SDK进行验证
 */
function wonder_test_api_connection($app_id, $private_key, $webhook_key = '', $environment = 'yes')
{
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
        // 检查SDK类是否存在
        if (!class_exists('PaymentSDK')) {
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
        
                $logger = wonder_payments_get_logger();
                $logger->debug('SDK Options initialized', array( 'source' => 'wonder-payments' ));
        
        
                // 尝试实例化SDK
        $authSDK = new PaymentSDK($options);
        if (!$authSDK) {
            return array(
                'success' => false,
                'message' => 'Failed to initialize PaymentSDK'
            );
        }

        // 检查 verifySignature 方法是否存在
        if (!method_exists($authSDK, 'verifySignature')) {
            return array(
                'success' => false,
                'message' => 'verifySignature method not found in PaymentSDK'
            );
        }

        // 使用 SDK 的 verifySignature 方法进行测试
        $result = $authSDK->verifySignature();
        // verifySignature 返回的是数组，需要检查 success 字段
        if (is_array($result) && isset($result['success']) && $result['success'] === true) {
            // 获取business信息
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

// 在支付设置页面底部添加自定义三点菜单
add_action('admin_footer', 'wonder_payments_add_custom_ellipsis_menu');

function wonder_payments_add_custom_ellipsis_menu() {
    // 只在支付设置页面加载
    $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);
    $tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS);
    if (!$page || $page !== 'wc-settings' || !$tab || $tab !== 'checkout') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        console.log('=== Wonder Payments: Custom ellipsis menu script loaded ===');
        
        // Deactivate函数
        window.wonderPaymentsDeactivate = function() {
            if (!confirm('确定要删除AppID和所有配置信息吗？此操作不可恢复。')) {
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
                        // 删除成功后，清除localStorage中的登录信息
                        localStorage.removeItem('wonder_access_token');
                        localStorage.removeItem('wonder_business_id');
                        localStorage.removeItem('wonder_selected_business_id');
                        localStorage.removeItem('wonder_selected_business_name');
                        
                        // 触发logout功能（如果存在）
                        if (typeof window.wonderPaymentsLogout === 'function') {
                            window.wonderPaymentsLogout();
                        }
                        
                        alert('已成功删除所有配置信息。');
                        
                        // 关闭模态框
                        $('#wonder-modal-body').empty();
                        $('#wonder-settings-modal').fadeOut(300);
                    } else {
                        alert('删除失败：' + response.data.message);
                    }
                },
                error: function() {
                    alert('删除失败，请重试。');
                }
            });
        };
        
        // 监听WooCommerce三点菜单的点击
        $(document).on('click', '.gridicons-ellipsis', function(e) {
            var $toggle = $(this);
            var $row = $toggle.closest('.woocommerce-list__item');
            var rowId = $row.attr('id');
            
            console.log('=== Wonder Payments: Ellipsis menu clicked, row ID:', rowId, '===');
            
            // 检查是否是Wonder Payment网关
            if (rowId === 'wonder_payments') {
                console.log('=== Wonder Payments: This is Wonder Payment gateway ===');
                
                // 延迟添加，等待菜单打开
                setTimeout(function() {
                    // 添加调试信息
                    console.log('=== Wonder Payments: All dropdown menus:', $('.woocommerce-ellipsis-menu__content').length, '===');
                    
                    // 查找菜单内容 - 使用正确的WooCommerce类名
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

    // 只在 WooCommerce 设置页面加载

    if ('woocommerce_page_wc-settings' !== $hook) {

        return;

    }


    // 检查是否是我们的支付网关设置页面

    $section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS);
    if (!$section || $section !== 'wonder_payments') {

        return;

    }


    // 使用修复版的 JavaScript 文件


    $script_url = plugin_dir_url(__FILE__) . 'assets/js/admin-fixed.js';


    $script_path = plugin_dir_path(__FILE__) . 'assets/js/admin-fixed.js';

    if (!file_exists($script_path)) {
        // 回退到原始文件
        $script_url = plugin_dir_url(__FILE__) . 'assets/js/admin.js';


        $script_path = plugin_dir_path(__FILE__) . 'assets/js/admin.js';


        if (!file_exists($script_path)) {
            return;
        }


    }


    // 获取文件修改时间作为版本号，避免缓存


    $script_version = filemtime($script_path);
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
 * AJAX: 获取订单调试信息
 */


function wonder_payments_debug_order()
{


    check_ajax_referer('wonder_payments_debug', 'nonce');


    if (!current_user_can('manage_options')) {


        wp_die('权限不足');


    }


    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;


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


function wonder_payments_get_logs()
{


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

/**
 * AJAX: 使用 SDK 创建二维码
 */
function wonder_payments_sdk_create_qrcode() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // 从 WooCommerce 设置中获取 appid
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';
        // 初始化 SDK
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBwX2lkIjoiODBhOTg0ZTItNGVjNC00ZDA2LWFiYTktZTQzMDEwOTU2ZTEzIiwiaWF0IjoxNjgxMzkyMzkyLCJleHAiOjE5OTY3NTIzOTJ9.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'language' => 'en-US'
        ]);
        // 调用 SDK 创建二维码
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
 * AJAX: 使用 SDK 查询二维码状态
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
        // 从 WooCommerce 设置中获取 appid
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';

        // 初始化 SDK
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBpZCI6IjgwYTk4NGUyLTRlYzQtNGQwNi1hYmE5LWU0MzAxMDk1NmUxMyIsImlhdCI6MTY4MTM5MjM5MiwiZXhwIjoxOTk2NzUyMzkyfQ.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'language' => 'en-US'
        ]);

        // 调用 SDK 查询状态
        $status = $sdk->getQRCodeStatus($uuid);

        wp_send_json_success(array('data' => $status['data']));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to get QR code status: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_sdk_qrcode_status', 'wonder_payments_sdk_qrcode_status');

/**
 * AJAX: 获取店铺列表
 */
function wonder_payments_sdk_get_businesses() {
    $startTime = microtime(true);
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // 从 WooCommerce 设置中获取 appid
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';

        // 获取用户访问令牌
        $userAccessToken = get_option('wonder_payments_user_access_token', '');

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        // 初始化 SDK
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBpZCI6IjgwYTk4NGUyLTRlYzQtNGQwNi1hYmE5LWU0MzAxMDk1NmUxMyIsImlhdCI6MTY4MTM5MjM5MiwiZXhwIjoxOTk2NzUyMzkyfQ.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'userAccessToken' => $userAccessToken,
            'language' => 'en-US'
        ]);

        // 调用 SDK 获取店铺列表
        $sdkStartTime = microtime(true);
        $businesses = $sdk->getBusinesses();

        // 检查响应结构,可能需要访问 data.data
        $businessData = isset($businesses['data']) ? $businesses['data'] : array();

        // 如果 data 中还有 data 字段,则访问内层的 data
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
 * AJAX: 保存用户访问令牌
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

    // 保存到 WordPress 选项
    update_option('wonder_payments_user_access_token', $accessToken);
    update_option('wonder_payments_business_id', $businessId);
    wp_send_json_success(array('message' => 'Access Token saved successfully'));
}
add_action('wp_ajax_wonder_payments_sdk_save_access_token', 'wonder_payments_sdk_save_access_token');

/**
 * AJAX: 生成密钥对并获取 App ID
 */
function wonder_payments_sdk_generate_app_id() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // 从 WooCommerce 设置中获取 appid
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $appId = isset($settings['app_id']) ? $settings['app_id'] : '';

        // 获取用户访问令牌
        $userAccessToken = get_option('wonder_payments_user_access_token', '');

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        // 获取业务 ID
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

            // 保存私钥到 WordPress 选项
            update_option('wonder_payments_private_key', $privateKey);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Failed to generate key pair: ' . $e->getMessage()));
        }

        // 初始化 SDK
        $sdk = new PaymentSDK([
            'appid' => $appId,
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => get_option('wonder_payments_public_key', ''),
            'environment' => 'stg',
            'jwtToken' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhcHBfa2V5IjoiMDJlYjMzNjItMWNjYi00MDYzLThmNWUtODI1ZmRlNzYxZWZiIiwiYXBpZCI6IjgwYTk4NGUyLTRlYzQtNGQwNi1hYmE5LWU0MzAxMDk1NmUxMyIsImlhdCI6MTY4MTM5MjM5MiwiZXhwIjoxOTk2NzUyMzkyfQ.2UF7FOI-d344wJsZt5zVg7dC2r1DzqdmSV_bhSpdt-I',
            'userAccessToken' => $userAccessToken,
            'language' => 'en-US'
        ]);
        
        // 返回成功，提示用户手动创建AppID
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
 * AJAX: 检查是否已连接店铺
 */
function wonder_payments_check_connection() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // 从 WooCommerce 设置中获取 app_id
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

            // 尝试从 localStorage 选项中获取业务信息
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
 * AJAX: 加载模态窗口内容
 */
function wonder_payments_load_modal_content() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    ob_start();
    // 调用 Wonder_Payments_Admin 类的渲染方法
    $admin = new Wonder_Payments_Admin();
    $admin->render_setup_page();
    $content = ob_get_clean();

    wp_send_json_success(array('content' => $content));
}
add_action('wp_ajax_wonder_payments_load_modal_content', 'wonder_payments_load_modal_content');

/**
 * AJAX: 获取网关配置
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
 * AJAX: 保存选择的店铺ID
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

        // 保存到 WordPress 选项
        update_option('wonder_payments_business_id', $businessId);
        update_option('wonder_payments_business_name', $businessName);

        // 清空之前的app_id(因为换了店铺)
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
 * AJAX: 只生成密钥对
 */
function wonder_payments_generate_key_pair_only() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {

        // 获取当前选择的business_id
        $currentBusinessId = isset($_POST['business_id']) ? sanitize_text_field(wp_unslash($_POST['business_id'])) : '';

        // 获取已保存的app_id和business_id
        $settings = get_option('woocommerce_wonder_payments_settings', array());
        $savedAppId = isset($settings['app_id']) ? $settings['app_id'] : '';
        $savedBusinessId = get_option('wonder_payments_business_id', '');

        // 如果已有AppID且business_id一致，直接返回已保存的密钥，避免重置私钥导致签名失效
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

        // 生成 2048 位 RSA 密钥对
        $keyPair = PaymentSDK::generateKeyPair(2048);

        if (!isset($keyPair['private_key']) || !isset($keyPair['public_key'])) {
            throw new Exception('Failed to generate key pair: invalid response');
        }

        $privateKey = $keyPair['private_key'];
        $publicKey = $keyPair['public_key'];

        // 生成 Webhook Key
        $webhookKey = bin2hex(random_bytes(32));
        // 保存密钥对到 WordPress 选项
        update_option('wonder_payments_private_key', $privateKey);
        update_option('wonder_payments_public_key', $publicKey);
        update_option('wonder_payments_webhook_key', $webhookKey);

        // 同时也保存私钥到 WooCommerce 设置中
        $settings['private_key'] = $privateKey;
        $settings['generated_public_key'] = $publicKey;
        update_option('woocommerce_wonder_payments_settings', $settings);

        $appIdToReturn = '';

        wp_send_json_success(array(
            'data' => array(
                'public_key' => $publicKey,
                'private_key' => $privateKey,
                'webhook_key' => $webhookKey,
                'app_id' => $appIdToReturn
            )
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => 'Failed to generate key pair: ' . $e->getMessage()));
    }
}
add_action('wp_ajax_wonder_payments_generate_key_pair_only', 'wonder_payments_generate_key_pair_only');

/**
 * AJAX: 只生成app_id(使用已保存的公钥)
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

        // 获取已保存的公钥
        $publicKey = get_option('wonder_payments_public_key', '');

        if (empty($publicKey)) {
            wp_send_json_error(array('message' => 'Public key not found. Please generate key pair first.'));
        }

        // 获取用户访问令牌
        $userAccessToken = get_option('wonder_payments_user_access_token', '');

        if (empty($userAccessToken)) {
            wp_send_json_error(array('message' => 'User Access Token not found. Please scan QR code to login first.'));
        }

        // 初始化 SDK
        $sdk = new PaymentSDK([
            'appid' => '',
            'signaturePrivateKey' => get_option('wonder_payments_private_key', ''),
            'webhookVerifyPublicKey' => '',
            'environment' => 'stg',
            'jwtToken' => '...',
            'userAccessToken' => $userAccessToken,
            'language' => 'en-US'
        ]);

        // 调用 SDK 生成 app_id
        $result = $sdk->generateAppId($businessId, $publicKey);

        // 从响应中提取 app_id
        $newAppId = '';
        if (isset($result['data']['app_id'])) {
            $newAppId = $result['data']['app_id'];
        } elseif (isset($result['app_id'])) {
            $newAppId = $result['app_id'];
        }

        // 从响应中提取 webhook_private_key
        $webhookPrivateKey = '';
        if (isset($result['data']['webhook_private_key'])) {
            $webhookPrivateKey = $result['data']['webhook_private_key'];
        } elseif (isset($result['webhook_private_key'])) {
            $webhookPrivateKey = $result['webhook_private_key'];
        }

        // 保存 app_id 到 WooCommerce 设置
        if (!empty($newAppId)) {
            $settings = get_option('woocommerce_wonder_payments_settings', array());
            $settings['app_id'] = $newAppId;
            update_option('woocommerce_wonder_payments_settings', $settings);
        }

        // 返回 app_id 和 webhook_private_key
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
 * AJAX: 清除所有数据
 */
function wonder_payments_clear_all() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {

        // 清除所有WordPress选项
        delete_option('wonder_payments_app_id');
        delete_option('wonder_payments_business_id');
        delete_option('wonder_payments_business_name');
        delete_option('wonder_payments_public_key');
        delete_option('wonder_payments_private_key');
        delete_option('wonder_payments_user_access_token');
        delete_option('wonder_payments_connected'); // 清除连接状态

        // 清除WooCommerce设置
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
 * AJAX: 加载Settings
 */
function wonder_payments_load_settings() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // 优先从 WooCommerce 配置加载
        $wcSettings = get_option('woocommerce_wonder_payments_settings', array());
        // 如果 WooCommerce 配置为空,尝试从 wonder_payments_settings 加载
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
 * AJAX: 保存Settings
 */
function wonder_payments_save_settings() {
    check_ajax_referer('wonder_payments_modal_nonce', 'security');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    try {
        // @codingStandardsIgnoreLine WordPress.Security.ValidatedSanitizedInput.InputNotSanitized - settings 数组将在下面逐个字段进行清理
        $settings = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : array();

        // 验证和清理数据
        $title = isset($settings['title']) ? sanitize_text_field($settings['title']) : '';
        $description = isset($settings['description']) ? sanitize_textarea_field($settings['description']) : '';
        $sandboxMode = isset($settings['sandbox_mode']) ? ($settings['sandbox_mode'] === '1' ? '1' : '0') : '0';
        $dueDate = isset($settings['due_date']) ? intval($settings['due_date']) : 30;

        // 验证due_date范围
        if ($dueDate < 1) {
            $dueDate = 1;
        } elseif ($dueDate > 365) {
            $dueDate = 365;
        }

        // 将sandbox_mode映射为环境: stg(启用) 或 prod(关闭)
        $environment = ($sandboxMode === '1') ? 'stg' : 'prod';

        // 保存到 WooCommerce 配置
        $wcSettings = get_option('woocommerce_wonder_payments_settings', array());

        // 更新配置
        $wcSettings['title'] = $title;
        $wcSettings['description'] = $description;
        $wcSettings['sandbox_mode'] = $sandboxMode;
        $wcSettings['environment'] = $environment;
        $wcSettings['due_date'] = $dueDate;

        // 同时也保存到 wonder_payments_settings (用于Settings页面加载)
        update_option('wonder_payments_settings', $wcSettings);

        // 保存到 WooCommerce 设置
        update_option('woocommerce_wonder_payments_settings', $wcSettings);

        // 验证数据是否真的保存到数据库
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
            // 数据库级别验证 - 使用 get_option() 查询
            $optionName = 'woocommerce_wonder_payments_settings';
            $dbResult = get_option($optionName);

            if ($dbResult) {

                // 反序列化验证
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
 * 在 WooCommerce 设置页面添加模态窗口和 JavaScript
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
    <!-- Wonder Payment 设置模态窗口 -->
    <div id="wonder-settings-modal" class="wonder-modal" style="display: none;">
        <div class="wonder-modal-content">
            <div class="wonder-modal-body" id="wonder-modal-body">
                <!-- 内容将通过 AJAX 加载 -->
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

        /* Wonder Payments 支付设置页面三点菜单样式 */
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
            
            // 等待按钮加载完成后，替换第一个管理按钮
            var checkInterval = setInterval(function() {
                var $manageBtns = $('.components-button.is-secondary');
                if ($manageBtns.length > 0) {
                    clearInterval(checkInterval);
                    var $wonderBtn = $manageBtns.eq(0);
                    console.log('=== Wonder Payments: Found Wonder Payment button ===');
                    
                    // 创建一个新按钮来替换它
                    var $newBtn = $wonderBtn.clone();
                    $newBtn.attr('href', 'javascript:void(0)');
                    $newBtn.removeAttr('onclick');
                    $newBtn.off();
                    
                    // 绑定点击事件
                    $newBtn.on('click', function(e) {
                        console.log('=== Wonder Payment button clicked ===');
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        
                        // 打开模态窗口
                        $('#wonder-settings-modal').fadeIn(300);

                        // 加载设置页面内容
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
                    
                    // 替换旧按钮
                    $wonderBtn.replaceWith($newBtn);
                    console.log('=== Wonder Payments: Button replaced ===');
                }
            }, 100);
            
            // 最大等待5秒
            setTimeout(function() {
                clearInterval(checkInterval);
            }, 5000);

            // 关闭模态窗口 - 使用事件委托
            $(document).on('click', '#close-wonder-modal', function () {
                // 清除全局轮询变量
                if (window.wonderPaymentsPollInterval) {
                    clearInterval(window.wonderPaymentsPollInterval);
                    window.wonderPaymentsPollInterval = null;
                    console.log('Cleared poll interval on modal close');
                }
                // 清空模态框内容，停止所有JavaScript执行
                $('#wonder-modal-body').empty();
                $('#wonder-settings-modal').fadeOut(300);
            });

            // 点击背景关闭
            $('#wonder-settings-modal').on('click', function (e) {
                if (e.target === this) {
                    // 清除全局轮询变量
                    if (window.wonderPaymentsPollInterval) {
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                        console.log('Cleared poll interval on modal close');
                    }
                    // 清空模态框内容，停止所有JavaScript执行
                    $('#wonder-modal-body').empty();
                    $(this).fadeOut(300);
                }
            });

            // ESC 键关闭
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && $('#wonder-settings-modal').is(':visible')) {
                    // 清除全局轮询变量
                    if (window.wonderPaymentsPollInterval) {
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                        console.log('Cleared poll interval on modal close');
                    }
                    // 清空模态框内容，停止所有JavaScript执行
                    $('#wonder-modal-body').empty();
                    $('#wonder-settings-modal').fadeOut(300);
                }
            });

            // Wonder Payments 支付设置页面三点菜单
            $(document).on('click', '.wonder-payments-menu-button', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $menu = $(this).siblings('.wonder-payments-dropdown-menu');
                $menu.toggle();
            });

            // 点击其他地方关闭菜单
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.wonder-payments-menu-container').length) {
                    $('.wonder-payments-dropdown-menu').hide();
                }
            });

            // 隐藏建议功能
            $(document).on('click', '.hide-suggestions', function(e) {
                e.preventDefault();
                $('.wonder-payments-dropdown-menu').hide();

                // 隐藏建议区域
                $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();

                // 保存状态到localStorage
                localStorage.setItem('wonder_payments_suggestions_hidden', 'true');

                // 显示成功提示
                alert('<?php echo esc_js(__('建议已隐藏', 'wonder-payments')); ?>');
            });

            // 检查是否需要隐藏建议
            if (localStorage.getItem('wonder_payments_suggestions_hidden') === 'true') {
                $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();
            }

            // 隐藏建议功能
            $(document).on('click', '.wonder-payments-hide-suggestions', function(e) {
                e.preventDefault();

                // 隐藏建议区域
                $('.woocommerce-Message.woocommerce-Message--info.woocommerce-message').hide();

                // 保存状态到localStorage
                localStorage.setItem('wonder_payments_suggestions_hidden', 'true');

                // 显示成功提示
                alert('<?php echo esc_js(__('建议已隐藏', 'wonder-payments')); ?>');
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
