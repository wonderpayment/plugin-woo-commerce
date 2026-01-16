<?php
/**
 * Wonder Payments - 支付处理页面
 * 
 * 这是一个独立的HTML处理页面，用于在支付过程中显示状态
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 渲染支付处理页面
 */
function wonder_render_payment_processing_page($order) {
    $order_id = $order->get_id();
    $order_key = $order->get_order_key();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>支付处理中</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                text-align: center; 
                padding: 50px; 
                background-color: #f9f9f9;
            }
            .container {
                max-width: 500px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
            .spinner { 
                border: 8px solid #f3f3f3; 
                border-top: 8px solid #3498db; 
                border-radius: 50%; 
                width: 60px; 
                height: 60px; 
                animation: spin 2s linear infinite; 
                margin: 20px auto; 
            }
            @keyframes spin { 
                0% { transform: rotate(0deg); } 
                100% { transform: rotate(360deg); } 
            }
            .message { 
                margin: 20px 0; 
                font-size: 18px; 
                color: #333; 
            }
            .order-info { 
                margin: 30px auto; 
                padding: 20px; 
                background: #f5f5f5; 
                border-radius: 8px; 
                max-width: 400px; 
                text-align: left; 
            }
            .info-row { 
                display: flex; 
                justify-content: space-between; 
                margin-bottom: 10px; 
            }
            .label { 
                color: #666; 
                font-weight: bold;
            }
            .value { 
                color: #333; 
                font-weight: bold; 
            }
            .success-message {
                color: #27ae60;
                font-size: 16px;
                margin-top: 20px;
            }
            .error-message {
                color: #e74c3c;
                font-size: 16px;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>正在处理您的支付</h2>
            
            <div class="spinner"></div>
            <div class="message" id="status-message">正在验证您的支付信息，请稍候...</div>

            <div class="order-info">
                <div class="info-row">
                    <span class="label">订单号：</span>
                    <span class="value">#<?php echo esc_html($order_id); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">订单金额：</span>
                    <span class="value"><?php echo wp_kses_post(wc_price($order->get_total())); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">支付方式：</span>
                    <span class="value">Wonder Payments</span>
                </div>
            </div>

            <div id="result-message"></div>
        </div>

        <script>
            (function() {
                let checkCount = 0;
                const maxChecks = 20; // 最多查询20次
                const orderData = {
                    order_id: <?php echo json_encode($order_id); ?>,
                    order_key: <?php echo json_encode($order_key); ?>
                };

                // 开始查询支付状态
                function checkPaymentStatus() {
                    checkCount++;
                    
                    const statusMessage = document.getElementById('status-message');
                    statusMessage.textContent = `正在查询支付状态... (${checkCount}/${maxChecks})`;

                    // 使用AJAX检查支付状态
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4) {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    
                                    if (response.success) {
                                        // 支付成功
                                        statusMessage.innerHTML = '<span style="color: #27ae60;">✅ 支付成功！正在跳转...</span>';
                                        
                                        setTimeout(function() {
                                            window.location.href = response.redirect_url || '<?php echo esc_url($order->get_checkout_order_received_url()); ?>';
                                        }, 1000);
                                    } else {
                                        if (response.status === 'pending' && checkCount < maxChecks) {
                                            // 支付处理中，继续轮询
                                            statusMessage.innerHTML = `<span style="color: #f39c12;">⏳ ${response.message || '支付处理中，请稍候...'} (${checkCount})</span>`;
                                            
                                            setTimeout(checkPaymentStatus, 3000);
                                        } else {
                                            // 支付失败或超过最大查询次数
                                            statusMessage.innerHTML = '<span style="color: #e74c3c;">⚠️ 支付状态未知</span>';
                                            
                                            // 提供手动跳转选项
                                            const resultDiv = document.getElementById('result-message');
                                            resultDiv.innerHTML = `
                                                <div style="margin-top: 20px;">
                                                    <p>如果长时间未收到响应，请手动检查订单状态：</p>
                                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="btn" style="
                                                        display: inline-block;
                                                        margin: 10px;
                                                        padding: 10px 20px;
                                                        background: #3498db;
                                                        color: white;
                                                        text-decoration: none;
                                                        border-radius: 4px;
                                                    ">查看订单</a>
                                                </div>
                                            `;
                                        }
                                    }
                                } catch (e) {
                                    console.error('响应解析错误:', e);
                                    statusMessage.innerHTML = '<span style="color: #e74c3c;">❌ 数据解析错误</span>';
                                }
                            } else {
                                statusMessage.innerHTML = '<span style="color: #e74c3c;">❌ 网络请求失败</span>';
                                
                                if (checkCount < maxChecks) {
                                    setTimeout(checkPaymentStatus, 3000);
                                }
                            }
                        }
                    };

                    const params = new URLSearchParams({
                        'action': 'wonder_payments_check_status',
                        'order_id': orderData.order_id,
                        'order_key': orderData.order_key,
                        'nonce': '<?php echo esc_js(wp_create_nonce('wonder_payments_check_status')); ?>'
                    }).toString();
                    
                    xhr.send(params);
                }

                // 稍等1秒后开始查询
                setTimeout(checkPaymentStatus, 1000);
            })();
        </script>
    </body>
    </html>
    <?php
    exit;
}