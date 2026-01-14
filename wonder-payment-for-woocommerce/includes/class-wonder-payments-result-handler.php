<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wonder Payments 结果处理类
 */
class WC_Wonder_Payments_Result_Handler {

    private $gateway;

    public function __construct($gateway) {
        $this->gateway = $gateway;
    }

    /**
     * 获取 WooCommerce logger 实例
     */
    private function get_logger() {
        return wc_get_logger();
    }

    /**
     * 显示支付处理页面（中转页面）
     */
    public function show_payment_processing_page($order_id, $order_key) {
        // 清除输出缓冲区
        while (ob_get_level()) {
            ob_end_clean();
        }

        $order = wc_get_order($order_id);

        // 验证订单
        if (!$order || $order->get_order_key() !== $order_key) {
            $this->show_error_page('订单验证失败');
            return;
        }

        // 如果订单已经支付完成，直接跳转到感谢页面
        if ($order->is_paid()) {
            $this->redirect_to_thankyou_page($order);
            return;
        }

        // 在显示处理页面之前，先检查一次支付状态
        // 避免用户支付完成后返回时显示"处理中"
        $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
        if (!empty($reference_number)) {
            try {
                $check_result = $this->check_payment_status($order_id, $order_key);

                if ($check_result['success'] === true) {
                    // 如果检查发现已支付，直接跳转
                    $this->redirect_to_thankyou_page($order);
                    return;
                }
            } catch (Exception $e) {
            }
        }

        // 显示支付处理页面（简化版）
        $this->render_processing_page($order);
    }

    /**
     * 查询支付状态并处理
     */
    public function check_payment_status($order_id, $order_key) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return array(
                'success' => false,
                'message' => '订单验证失败：订单不存在',
                'status' => 'error'
            );
        }

        if ($order->get_order_key() !== $order_key) {
            return array(
                'success' => false,
                'message' => '订单验证失败：订单Key不匹配',
                'status' => 'error'
            );
        }

        // 获取参考号
        $reference_number = get_post_meta($order_id, '_wonder_reference_number', true);
        $request_id = get_post_meta($order_id, '_wonder_request_id', true);
        if (empty($reference_number)) {
            return array(
                'success' => false,
                'message' => '未找到支付信息',
                'status' => 'error'
            );
        }

        try {
            // 初始化SDK
            $webhook_key = !empty($this->gateway->webhook_public_key) ? $this->gateway->webhook_public_key : null;
            $options = array(
                'appid' => $this->gateway->app_id,
                'signaturePrivateKey' => $this->gateway->private_key,
                'webhookVerifyPublicKey' => $webhook_key ?: '',
                'callback_url' => '',
                'redirect_url' => '',
                'environment' => $this->gateway->get_environment(),
                'skipSignature' => false
            );

            // 如果存在 request_id，则传递给 SDK
            if (!empty($request_id)) {
                $options['request_id'] = $request_id;
            }

            $logger = $this->get_logger();
            $logger->debug('SDK Options initialized', array( 'source' => 'wonder-payments' ));

            $sdk = new PaymentSDK($options);

            if (!$sdk) {
                return array(
                    'success' => false,
                    'message' => '支付网关SDK初始化失败',
                    'status' => 'error'
                );
            }

            // 查询订单状态 - 添加重试机制
            $params = array(
                'order' => array(
                    'reference_number' => $reference_number
                )
            );


            $max_retries = 3;
            $retry_count = 0;
            $response = null;
            $last_error = null;
            
            while ($retry_count < $max_retries) {
                try {
                    $response = $sdk->queryOrder($params);
                    break; // 成功则跳出循环
                } catch (Exception $e) {
                    $retry_count++;
                    $last_error = $e;

                    if ($retry_count < $max_retries) {
                        // 等待后重试
                        sleep(1);
                    }
                }
            }
            
            if ($retry_count >= $max_retries && $last_error) {
                throw $last_error;
            }

            // 记录完整响应用于调试
            // 检查响应结构
            if (!is_array($response)) {
                return array(
                    'success' => false,
                    'message' => '支付状态查询返回无效格式',
                    'status' => 'error'
                );
            }

            // 处理支付结果
            $result = $this->process_payment_result($order, $response);
            $logger = $this->get_logger();
            $logger->debug('Payment result processed', array( 'source' => 'wonder-payments' ));
            return $result;

        } catch (Exception $e) {
            // 提供更友好的错误消息
            $error_message = '查询支付状态失败';
            if (strpos($e->getMessage(), 'cURL') !== false) {
                $error_message = '网络连接失败，请检查网络设置';
            } elseif (strpos($e->getMessage(), 'SSL') !== false) {
                $error_message = 'SSL证书验证失败';
            } elseif (strpos($e->getMessage(), 'signature') !== false) {
                $error_message = '签名验证失败，请检查私钥配置';
            } elseif (strpos($e->getMessage(), 'appid') !== false) {
                $error_message = 'App ID验证失败';
            }

            return array(
                'success' => false,
                'message' => $error_message . ' (' . $e->getMessage() . ')',
                'status' => 'error'
            );
        }
    }

    /**
     * 处理支付查询结果
     */
    private function process_payment_result($order, $response) {
        $order_id = $order->get_id();
        // 检查响应是否包含错误
        if (isset($response['error'])) {
            return array(
                'success' => false,
                'message' => isset($response['message']) ? $response['message'] : '支付查询失败',
                'status' => 'error'
            );
        }

        // 检查响应是否包含数据
        if (!isset($response['data'])) {
            return array(
                'success' => false,
                'message' => '支付状态查询返回无效数据',
                'status' => 'error'
            );
        }

                // 检查是否包含订单数据
                if (!isset($response['data']['order'])) {
                    return array(
                            'success' => false,
                            'message' => '未找到订单支付信息',
                            'status' => 'error'
                    );
                }
        
                $order_data = $response['data']['order'];
        // 获取订单状态 - 尝试多种可能的字段
        $payment_status = 'unknown';
        
        // 尝试不同的状态字段
        $status_fields = ['correspondence_state', 'state', 'status', 'payment_status'];
        foreach ($status_fields as $field) {
            if (isset($order_data[$field])) {
                $payment_status = strtolower($order_data[$field]);
                break;
            }
        }

        // 如果还是unknown，检查是否有其他指示状态的字段
        if ($payment_status === 'unknown') {
            foreach ($order_data as $key => $value) {
                if (is_string($value) && (strpos($key, 'state') !== false || strpos($key, 'status') !== false)) {
                    $payment_status = strtolower($value);
                    break;
                }
            }
        }

        // 记录支付状态
        switch ($payment_status) {
            case 'paid':
            case 'completed':
            case 'settled':
            case 'success':
                // 检查是否已经支付过
                if (!$order->is_paid()) {
                    /* translators: Order status when payment is completed via Wonder Payments */
                    $order->update_status('completed', __('通过 Wonder Payments 完成支付', 'wonder-payments'));

                    // 保存交易信息 - 优先保存交易UUID，如果没有则保存订单号
                    $transaction_id = '';
                    if (isset($order_data['transactions']) && !empty($order_data['transactions'])) {
                        // 保存第一个交易的UUID
                        $transaction_id = $order_data['transactions'][0]['uuid'];
                        update_post_meta($order_id, '_wonder_transaction_id', $transaction_id);
                    } elseif (isset($order_data['number'])) {
                        // 如果没有交易UUID，保存订单号
                        $transaction_id = $order_data['number'];
                        update_post_meta($order_id, '_wonder_transaction_id', $transaction_id);
                    }

                    // 同时保存订单号（用于查询）
                    if (isset($order_data['number'])) {
                        update_post_meta($order_id, '_wonder_order_number', $order_data['number']);
                    } else {
                    }

                    // 添加订单备注
                    $note = $this->build_payment_note($order_data);
                    $order->add_order_note($note);

                    // 清空购物车
                    if (WC()->cart) {
                        WC()->cart->empty_cart();
                    }
                } else {
                    // 订单已经支付过，不需要重复处理
                }

                return array(
                    'success' => true,
                    'message' => '支付成功',
                    'status' => 'paid',
                    'redirect_url' => $order->get_checkout_order_received_url()
                );

            case 'pending':
            case 'processing':
            case 'unpaid':
            case 'waiting':
                // 订单仍在处理中
                if ($order->get_status() !== 'pending') {
                    /* translators: Order status when payment is pending confirmation */
                    $order->update_status('pending', __('等待支付确认', 'wonder-payments'));
                }

                return array(
                    'success' => false,
                    'message' => '支付处理中，请稍候',
                    'status' => 'pending',
                    'redirect_url' => ''
                );

            case 'failed':
            case 'cancelled':
            case 'voided':
            case 'expired':
                // 支付失败
                /* translators: Order status when payment fails */
                $order->update_status('failed', __('支付失败', 'wonder-payments'));

                $failure_reason = isset($order_data['failure_reason']) ? $order_data['failure_reason'] : '';
                if ($failure_reason) {
                    $order->add_order_note("支付失败原因: {$failure_reason}");
                }

                return array(
                    'success' => false,
                    'message' => $failure_reason ? "支付失败: {$failure_reason}" : '支付失败',
                    'status' => 'failed',
                    'redirect_url' => $order->get_checkout_payment_url()
                );

            default:
                // 未知状态
                $logger = $this->get_logger();
                $logger->debug('未知支付状态: ' . $payment_status, array( 'source' => 'wonder-payments' ));
                /* translators: %s: unknown payment status */
                $order->add_order_note(sprintf(__('未知支付状态: %s', 'wonder-payments'), $payment_status));

                // 检查是否有其他信息可以帮助诊断
                $debug_info = [];
                foreach (['number', 'reference_number', 'created_at', 'updated_at'] as $field) {
                    if (isset($order_data[$field])) {
                        $debug_info[$field] = $order_data[$field];
                    }
                }

                return array(
                    'success' => false,
                    'message' => __('支付状态未知，请稍后查看', 'wonder-payments'),
                    'status' => 'unknown',
                    'redirect_url' => $order->get_checkout_order_received_url()
                );
        }
    }

    /**
     * 构建支付备注
     */
    private function build_payment_note($order_data) {
        $note = '通过 Wonder Payments 完成支付。';

        if (isset($order_data['number'])) {
            $note .= "订单号: {$order_data['number']}";
        }

        if (isset($order_data['paid_total'])) {
            $currency = isset($order_data['currency']) ? $order_data['currency'] : 'HKD';
            $note .= ", 支付金额: {$order_data['paid_total']} {$currency}";
        }

        if (isset($order_data['transactions'][0]['payment_method'])) {
            $note .= ", 支付方式: " . $order_data['transactions'][0]['payment_method'];
        }

        return $note;
    }

    /**
     * 显示支付处理页面
     */
    private function render_processing_page($order) {
        $order_id = $order->get_id();
        $order_key = $order->get_order_key();

        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>支付处理中</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                .spinner { border: 8px solid #f3f3f3; border-top: 8px solid #3498db; border-radius: 50%; width: 60px; height: 60px; animation: spin 2s linear infinite; margin: 20px auto; }
                @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                .message { margin: 20px 0; font-size: 18px; color: #333; }
                .order-info { margin: 30px auto; padding: 20px; background: #f5f5f5; border-radius: 8px; max-width: 400px; text-align: left; }
                .info-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
                .label { color: #666; }
                .value { color: #333; font-weight: bold; }
            </style>
        </head>
        <body>
        <div class="spinner"></div>
        <div class="message" id="message">正在验证您的支付信息，请稍候...</div>

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
        <div class="timer" id="timer">预计需要10-30秒...</div>
        </div>

        <script>
            let checkCount = 0;
            const maxChecks = 10; // 减少查询次数，最多查询10次
            const maxWaitTime = 30; // 最大等待30秒
            let startTime = new Date().getTime();
            let timerInterval;
            let lastStatus = '';

            // 订单信息
            const orderData = {
                order_id: <?php echo (int)$order_id; ?>,
                order_key: '<?php echo esc_js($order_key); ?>'
            };

            // 更新页面状态
            function updateStatus(message, isError = false, isSuccess = false) {
                const messageEl = document.getElementById('message');
                const spinnerEl = document.querySelector('.spinner');
                
                messageEl.innerHTML = message;
                
                if (isSuccess) {
                    spinnerEl.style.borderTopColor = '#27ae60';
                    messageEl.style.color = '#27ae60';
                } else if (isError) {
                    spinnerEl.style.borderTopColor = '#e74c3c';
                    messageEl.style.color = '#e74c3c';
                } else {
                    spinnerEl.style.borderTopColor = '#3498db';
                    messageEl.style.color = '#333';
                }
            }

            // 显示操作按钮
            function showActionButtons() {
                const buttonsHtml = `
                    <div style="margin-top: 20px;">
                        <button onclick="window.location.href='<?php echo esc_url($order->get_checkout_payment_url()); ?>'"
                                style="padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 4px; margin-right: 10px; cursor: pointer;">
                            重新支付
                        </button>
                        <button onclick="window.location.href='<?php echo esc_url(home_url('/')); ?>'"
                                style="padding: 10px 20px; background: #95a5a6; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            返回首页
                        </button>
                    </div>
                `;
                document.getElementById('message').insertAdjacentHTML('afterend', buttonsHtml);
            }

            // 开始查询
            function checkPaymentStatus() {
                checkCount++;

                // 显示正在查询
                updateStatus('正在查询支付状态... (' + checkCount + '/' + maxChecks + ')');

                // 发送查询请求
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        'action': 'wonder_payments_check_status',
                        'order_id': orderData.order_id,
                        'order_key': orderData.order_key,
                        'nonce': '<?php echo esc_js(wp_create_nonce('wonder_payments_check_status')); ?>'
                    })
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('网络请求失败: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('支付状态查询结果:', data);
                        
                        if (data.success) {
                            // 支付成功
                            updateStatus('✅ ' + (data.message || '支付成功！正在跳转...'), false, true);
                            lastStatus = 'success';

                            // 2秒后跳转
                            setTimeout(() => {
                                window.location.href = data.redirect_url || '<?php echo esc_url($order->get_checkout_order_received_url()); ?>';
                            }, 2000);
                        } else if (data.status === 'pending' && checkCount < maxChecks) {
                            // 支付处理中，继续轮询
                            lastStatus = 'pending';
                            updateStatus('⏳ ' + (data.message || '支付处理中，请稍候...') + ' (' + checkCount + '/' + maxChecks + ')');
                            setTimeout(checkPaymentStatus, 3000);
                        } else if (data.status === 'failed') {
                            // 支付失败
                            updateStatus('❌ ' + (data.message || '支付失败'), true);
                            lastStatus = 'failed';
                            showActionButtons();
                        } else if (data.status === 'error') {
                            // 查询错误
                            updateStatus('⚠️ ' + (data.message || '查询支付状态失败'), true);
                            lastStatus = 'error';
                            
                            // 如果是网络错误，重试几次
                            if (checkCount < 5 && data.message.includes('网络') || data.message.includes('连接')) {
                                setTimeout(checkPaymentStatus, 3000);
                            } else {
                                showActionButtons();
                            }
                        } else if (data.status === 'unknown') {
                            // 未知状态
                            updateStatus('❓ ' + (data.message || '支付状态未知'), true);
                            lastStatus = 'unknown';
                            
                            // 如果是未知状态，继续查询几次
                            if (checkCount < 10) {
                                setTimeout(checkPaymentStatus, 3000);
                            } else {
                                updateStatus('❓ 支付状态长时间未知，请联系客服', true);
                                showActionButtons();
                            }
                        } else {
                            // 其他情况
                            updateStatus('⚠️ ' + (data.message || '支付状态异常'), true);
                            lastStatus = 'other';
                            
                            if (checkCount < maxChecks) {
                                setTimeout(checkPaymentStatus, 3000);
                            } else {
                                showActionButtons();
                            }
                        }
                    })
                    .catch(error => {
                        console.error('查询失败:', error);
                        
                        // 分析错误类型
                        let errorMessage = '查询支付状态失败';
                        let showRetry = true;
                        
                        if (error.message.includes('网络') || error.message.includes('Network') || error.message.includes('Failed to fetch')) {
                            errorMessage = '网络连接失败，请检查网络设置';
                        } else if (error.message.includes('timeout') || error.message.includes('超时')) {
                            errorMessage = '查询超时，服务器响应缓慢';
                        } else if (error.message.includes('SSL') || error.message.includes('证书')) {
                            errorMessage = 'SSL证书验证失败';
                            showRetry = false;
                        }
                        
                        updateStatus('⚠️ ' + errorMessage + ' (' + checkCount + '/' + maxChecks + ')', true);
                        
                        if (showRetry && checkCount < 5) {
                            setTimeout(checkPaymentStatus, 3000);
                        } else {
                            updateStatus('⚠️ 多次查询失败，请尝试以下解决方案：<br>' +
                                        '1. 检查网络连接<br>' +
                                        '2. 刷新页面重试<br>' +
                                        '3. 联系客服获取帮助', true);
                            showActionButtons();
                        }
                    });
            }

            // 第一次查询
            setTimeout(checkPaymentStatus, 1000);
            
            // 添加超时检查
            setTimeout(() => {
                if (lastStatus === '' || lastStatus === 'pending') {
                    updateStatus('⚠️ 查询超时，请稍后查看订单状态', true);
                    showActionButtons();
                }
            }, 90000); // 90秒超时
        </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * 显示错误页面
     */
    private function show_error_page($message) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>支付出错</title>
            <style>
                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                h1 { color: #e74c3c; }
                .error-message { margin: 20px 0; color: #666; }
                .btn { display: inline-block; margin: 10px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; }
                .btn:hover { background: #2980b9; }
            </style>
        </head>
        <body>
        <h1>支付出错</h1>
        <div class="error-message"><?php echo esc_html($message); ?></div>
        <div>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="btn">返回首页</a>
            <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="btn">查看订单</a>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * 跳转到感谢页面
     */
    private function redirect_to_thankyou_page($order) {
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }
}