/**
 * Wonder Payments Admin JavaScript - Fixed Version
 * This version is more robust and handles common issues
 */

(function($) {
    'use strict';

    console.log('=== Wonder Payments Admin JS (Fixed) Loading ===');

    // 等待 DOM 完全加载
    $(document).ready(function() {
        console.log('DOM ready, initializing Wonder Payments Admin...');

        // 检查全局对象
        if (typeof wonder_payments_admin === 'undefined') {
            console.error('CRITICAL ERROR: wonder_payments_admin object is not defined!');
            console.error('This usually means:');
            console.error('1. The script is not being localized properly');
            console.error('2. There is a JavaScript error before this script loads');
            console.error('3. The script is being cached');
            return;
        }

        console.log('wonder_payments_admin object found:', wonder_payments_admin);
        console.log('AJAX URL:', wonder_payments_admin.ajax_url);
        console.log('Generate nonce:', wonder_payments_admin.generate_nonce ? 'Set' : 'Missing');
        console.log('Test nonce:', wonder_payments_admin.test_nonce ? 'Set' : 'Missing');

        // 初始化函数
        function initWonderPayments() {
            console.log('Initializing Wonder Payments buttons...');

            var $generateBtn = $('#wonder-generate-keys');
            var $testBtn = $('#wonder-test-config');
            var $message = $('#wonder-action-message');

            console.log('Found generate button:', $generateBtn.length);
            console.log('Found test button:', $testBtn.length);
            console.log('Found message div:', $message.length);

            // 移除旧的事件监听器（避免重复绑定）
            $generateBtn.off('click.wonder');
            $testBtn.off('click.wonder');

            // 绑定生成密钥按钮
            $generateBtn.on('click.wonder', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Generate RSA Keys button clicked');
                handleGenerateKeys();
            });

            // 绑定测试配置按钮
            $testBtn.on('click.wonder', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Test Configuration button clicked');
                handleTestConfig();
            });

            // 添加按钮样式提示
            $generateBtn.css('cursor', 'pointer');
            $testBtn.css('cursor', 'pointer');

            console.log('Wonder Payments buttons initialized successfully');
        }

        // 生成密钥处理函数
        function handleGenerateKeys() {
            var $button = $('#wonder-generate-keys');
            var $spinner = $button.siblings('.spinner');
            var $message = $('#wonder-generate-message');

            // 获取用户输入的私钥
            var privateKey = $('#wonder-private-key-display').val();

            // 验证私钥
            if (!privateKey || privateKey.trim() === '') {
                showGenerateMessage('error', 'Please enter your private key first.');
                return;
            }

            // 验证 nonce
            if (!wonder_payments_admin.generate_nonce) {
                showMessage('error', 'Security nonce is missing. Please refresh the page.');
                return;
            }

            // 显示加载状态
            $button.prop('disabled', true);
            $spinner.addClass('is-active').show();
            $message.html('').removeClass('success error').show();

            console.log('Sending generate keys request with user input private key...');

            $.ajax({
                url: wonder_payments_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wonder_generate_keys',
                    security: wonder_payments_admin.generate_nonce,
                    private_key: privateKey
                },
                success: function(response) {
                    console.log('=== Generate keys SUCCESS ===');
                    console.log('Full response:', response);
                    console.log('Response type:', typeof response);
                    console.log('Response keys:', Object.keys(response));
                    console.log('Response success:', response.success);
                    console.log('Response data:', response.data);

                    if (response.success) {
                        showGenerateMessage('success', response.data.message);

                        // 填充公钥（私钥保持不变）
                        $('#wonder-generated-public-key-display').val(response.data.public_key);
                        $('textarea[name="woocommerce_wonder_payments_private_key"]').val(response.data.private_key);
                    } else {
                        showGenerateMessage('error', response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('=== AJAX ERROR ===');
                    console.error('Status:', status);
                    console.error('Error:', error);
                    console.error('XHR Status:', xhr.status);
                    console.error('XHR Status Text:', xhr.statusText);
                    console.error('XHR Response:', xhr.responseText);
                    console.error('XHR Response Headers:', xhr.getAllResponseHeaders());
                    showGenerateMessage('error', wonder_payments_admin.strings.error + ' ' + error + ' (Status: ' + xhr.status + ')');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }

        // 测试配置处理函数
        function handleTestConfig() {
            var $button = $('#wonder-test-config');
            var $spinner = $button.siblings('.spinner');
            var $message = $('#wonder-action-message');

            // 验证 nonce
            if (!wonder_payments_admin.test_nonce) {
                showMessage('error', 'Security nonce is missing. Please refresh the page.');
                return;
            }

            // 获取表单值
            var appId = $('input[name="woocommerce_wonder_payments_app_id"]').val();
            var privateKey = $('#wonder-private-key-display').val();
            var webhookKey = $('textarea[name="woocommerce_wonder_payments_webhook_public_key"]').val();
            var environment = $('input[name="woocommerce_wonder_payments_environment"]').is(':checked') ? 'yes' : 'no';

            console.log('Test config values:');
            console.log('App ID:', appId);
            console.log('Private Key length:', privateKey ? privateKey.length : 0);
            console.log('Webhook Key length:', webhookKey ? webhookKey.length : 0);
            console.log('Environment:', environment);

            // 验证必填字段
            if (!appId || !privateKey) {
                showMessage('error', wonder_payments_admin.strings.enter_fields);
                return;
            }

            // 显示加载状态
            $button.prop('disabled', true);
            $spinner.addClass('is-active').show();
            $message.html('').removeClass('success error').show();

            console.log('Sending test config request...');

            $.ajax({
                url: wonder_payments_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wonder_test_config',
                    security: wonder_payments_admin.test_nonce,
                    app_id: appId,
                    private_key: privateKey,
                    webhook_key: webhookKey,
                    environment: environment
                },
                success: function(response) {
                    console.log('Test config response:', response);
                    if (response.success) {
                        showMessage('success', response.data.message);
                    } else {
                        showMessage('error', response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error, xhr);
                    showMessage('error', wonder_payments_admin.strings.error + ' ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }

        // 显示消息函数
        function showMessage(type, text) {
            var $message = $('#wonder-action-message');
            var icon = type === 'success' ? '✅' : '❌';
            var color = type === 'success' ? 'green' : 'red';

            $message.html('<span style="color: ' + color + ';">' + icon + ' ' + text + '</span>')
                   .removeClass('success error')
                   .addClass(type)
                   .show();
        }

        // 显示生成密钥消息函数
        function showGenerateMessage(type, text) {
            var $message = $('#wonder-generate-message');
            var icon = type === 'success' ? '✅' : '❌';
            var color = type === 'success' ? 'green' : 'red';

            $message.html('<span style="color: ' + color + ';">' + icon + ' ' + text + '</span>')
                   .removeClass('success error')
                   .addClass(type)
                   .show();
        }

        // 初始化和重试机制
        function initializeWithRetry(retryCount) {
            retryCount = retryCount || 0;
            var maxRetries = 5;

            // 检查按钮是否存在
            if ($('#wonder-generate-keys').length === 0 || $('#wonder-test-config').length === 0) {
                if (retryCount < maxRetries) {
                    console.log('Buttons not found, retrying in 500ms... (' + (retryCount + 1) + '/' + maxRetries + ')');
                    setTimeout(function() {
                        initializeWithRetry(retryCount + 1);
                    }, 500);
                } else {
                    console.error('Failed to find buttons after ' + maxRetries + ' attempts');
                    console.error('Please check:');
                    console.error('1. The HTML structure in admin_options() method');
                    console.error('2. JavaScript errors on the page');
                    console.error('3. Cached page (try Ctrl+F5)');
                }
            } else {
                initWonderPayments();
            }
        }

        // 开始初始化
        initializeWithRetry();

        console.log('=== Wonder Payments Admin JS Initialization Complete ===');
    });

})(jQuery);