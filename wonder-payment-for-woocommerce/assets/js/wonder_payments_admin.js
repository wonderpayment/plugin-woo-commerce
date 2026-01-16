jQuery(document).ready(function($) {
    // 断开连接确认
    $('#disconnect-wonder').on('click', function(e) {
        e.preventDefault();

        if (!confirm(wonderPayments.strings.confirm_disconnect)) {
            return;
        }

        var button = $(this);
        button.prop('disabled', true).text(wonderPayments.strings.disconnecting);

        $.ajax({
            url: wonderPayments.ajax_url,
            type: 'POST',
            data: {
                action: 'wonder_payments_disconnect',
                nonce: wonderPayments.nonce
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(wonderPayments.strings.error);
                    button.prop('disabled', false).text(button.data('original-text'));
                }
            },
            error: function() {
                alert(wonderPayments.strings.error);
                button.prop('disabled', false).text(button.data('original-text'));
            }
        });
    });

    // 模拟连接流程
    $('.wonder-step .step-number').on('click', function() {
        var step = $(this).parent('.wonder-step');
        var allSteps = $('.wonder-step');

        // 移除所有活动状态
        allSteps.removeClass('active');

        // 设置当前步骤和之前步骤为活动状态
        step.addClass('active');
        step.prevAll('.wonder-step').addClass('active');
    });

    // 自动刷新QR码（可选）
    var qrRefreshInterval = 30000; // 30秒

    if ($('.qrcode-image').length) {
        setInterval(function() {
            $.get(wonderPayments.ajax_url + '?action=wonder_refresh_qr&nonce=' + wonderPayments.nonce, function(data) {
                if (data.success) {
                    $('.qrcode-image img').attr('src', data.qr_url);
                }
            });
        }, qrRefreshInterval);
    }
});