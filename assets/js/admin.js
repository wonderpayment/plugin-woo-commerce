console.log('=== Wonder Payments Admin JS Start ===');

jQuery(document).ready(function($) {
    console.log('Wonder Payments Admin JS loaded');
    
    // 检查全局对象是否存在
    if (typeof wonder_payments_admin === 'undefined') {
        console.error('ERROR: wonder_payments_admin object is not defined!');
        console.error('This means the script was not properly localized.');
        return;
    }
    
    console.log('wonder_payments_admin object:', wonder_payments_admin);

    // 检查按钮是否存在
    console.log('Checking for buttons...');
    console.log('Generate button:', $('#wonder-generate-keys').length);
    console.log('Test button:', $('#wonder-test-config').length);
    console.log('Actions div:', $('.wonder-payments-actions').length);
    console.log('Message div:', $('#wonder-action-message').length);

    // 如果按钮不存在，尝试重新查找（可能动态加载）
    if ($('#wonder-generate-keys').length === 0) {
        console.log('Buttons not found, waiting for DOM to be ready...');
        // 等待一下再检查
        setTimeout(function() {
            console.log('Re-checking buttons after delay...');
            console.log('Generate button:', $('#wonder-generate-keys').length);
            console.log('Test button:', $('#wonder-test-config').length);
        }, 500);
    }



    // Test Configuration 按钮 - 使用直接绑定
    $('#wonder-test-config').on('click', function(e) {
        console.log('=== Test button clicked! ===');
        e.preventDefault();
        e.stopPropagation();

        var $button = $(this);
        var $spinner = $button.siblings('.spinner');
        var $message = $('#wonder-action-message');

        // 获取当前表单中的配置值
        var appId = $('input[name="woocommerce_wonder_payments_app_id"]').val();
        var privateKey = $('#wonder-private-key-display').val();
        var webhookKey = $('textarea[name="woocommerce_wonder_payments_webhook_public_key"]').val();

        console.log('Test config - App ID:', appId);
        console.log('Test config - Private Key length:', privateKey ? privateKey.length : 0);
        console.log('Test config - Webhook Key length:', webhookKey ? webhookKey.length : 0);
        console.log('Test config - Private Key value (first 50 chars):', privateKey ? privateKey.substring(0, 50) : 'empty');

        // 验证必填字段
        if (!appId || !privateKey) {
            $message.html('<span style="color: red;">❌ ' + wonder_payments_admin.strings.enter_fields + '</span>').removeClass('notice success').addClass('error');
            return;
        }

        // 显示加载状态
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $message.html('').removeClass('success error').addClass('notice');

        console.log('Sending AJAX request to test config...');
        console.log('AJAX URL:', wonder_payments_admin.ajax_url);
        console.log('Nonce:', wonder_payments_admin.test_nonce ? 'Present' : 'Missing');

        $.ajax({
            url: wonder_payments_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'wonder_test_config',
                security: wonder_payments_admin.test_nonce,
                app_id: appId,
                private_key: privateKey,
                webhook_key: webhookKey
            },
            success: function(response) {
                console.log('Test config response:', response);
                if (response.success) {
                    $message.html('<span style="color: green;">✅ ' + response.data.message + '</span>').removeClass('notice error').addClass('success');
                } else {
                    $message.html('<span style="color: red;">❌ ' + response.data.message + '</span>').removeClass('notice success').addClass('error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Test config error:', error);
                console.error('XHR status:', status);
                console.error('XHR response:', xhr.responseText);
                $message.html('<span style="color: red;">❌ ' + wonder_payments_admin.strings.error + ' ' + error + '</span>').removeClass('notice success').addClass('error');
            },
            complete: function() {
                // 恢复按钮状态
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
                console.log('=== Test request completed ===');
            }
        });
    });

    // 添加事件委托作为备用
    $(document).on('click', '#wonder-generate-keys', function(e) {
        console.log('Generate button clicked via delegation');
    });
    
    $(document).on('click', '#wonder-test-config', function(e) {
        console.log('Test button clicked via delegation');
    });

    console.log('=== Wonder Payments Admin JS Setup Complete ===');
});