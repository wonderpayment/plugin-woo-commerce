<?php

if (!defined('ABSPATH')) {
    exit;
}

class Wonder_Payments_Admin {

    public function __construct() {
        // 添加管理菜单 - 已注释，因为设置页面通过模态框访问，不需要在菜单中显示
        // add_action('admin_menu', array($this, 'add_admin_menu'));

        // 设置页面样式和脚本
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // 处理表单提交
        add_action('admin_init', array($this, 'handle_form_submissions'));
    }

    public function render_setup_page() {
        // 检查是否已经连接
        $is_connected = get_option('wonder_payments_app_id', false);
        $business_name = get_option('wonder_payments_business_name', '');
        ?>
        <div class="modal-container">
            <!-- 底层卡片 - 左侧菜单栏 -->
            <div class="base-card">
                <div class="menu-items">
                    <div class="menu-level-1">Setup Wonder Payment</div>
                    <div class="menu-item active" data-tab="scan">Scan qrcode to login wonder</div>
                    <div class="menu-item" data-tab="business">Choose business connect this shop</div>
                    <div class="menu-item" data-tab="activation">Activation AppID</div>
                    <div class="menu-item" data-tab="settings">Settings</div>
                </div>
            </div>

            <!-- 上层卡片 - 包含头部和右侧内容区 -->
            <div class="floating-card">
                <!-- 头部 -->
                <div class="card-header">
                    <h2>Setup Wonder Payment</h2>
                    <button class="card-close-btn" id="close-wonder-modal">&times;</button>
                </div>

                <!-- Scan QR Code 面板 -->
                <div class="content-panel active" id="panel-scan">
                    <h1 class="content-title">Connect Wonder Payment</h1>
                    <p class="content-description">
                        scan qrcode use wonder app scanner starting integration 34+ payment methods
                    </p>

                    <div class="qr-code-section">
                        <div class="qr-code-placeholder">
                            <?php $this->generate_qrcode(); ?>
                        </div>
                        <button class="qr-refresh-btn" id="refresh-qr-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            Refresh QR Code
                        </button>
                    </div>

                    <div id="logout-section" style="display: none; text-align: center; margin-top: 20px;">
                        <p style="color: #28a745; font-size: 16px; margin-bottom: 15px;">
                            ✓ Successfully logged in!
                        </p>
                        <button id="logout-btn" class="btn btn-secondary" style="margin-top: 10px;">
                            Logout
                        </button>
                    </div>

                    <div class="bottom-note">
                        <a href="https://www.wonder.app" target="_blank">Have not yet onboarding wonder app?</a>
                    </div>
                </div>

                <!-- Choose Business 面板 -->
                <div class="content-panel" id="panel-business">
                    <h1 class="content-title">Wonder Payment For WooCommerce</h1>

                    <div class="cards-container">
                        <div class="business-card pending">
                            <div class="card-header">PENDING APPROVE</div>
                            <div class="card-body">
                                <div class="store-name">Wonder Payment Test Store</div>
                            </div>
                            <div class="card-footer">
                                <div class="status-text">Complete Your application soon</div>
                            </div>
                        </div>

                        <div class="business-card active">
                            <div class="card-header">ACTIVE</div>
                            <div class="card-body">
                                <div class="store-name">Wonder Payment Test Store</div>
                            </div>
                            <div class="card-footer">
                                <button class="choose-btn">Choose</button>
                            </div>
                        </div>

                        <div class="business-card inactive">
                            <div class="card-header">INACTIVE</div>
                            <div class="card-body">
                                <div class="store-name">Wonder Payment Test Store</div>
                            </div>
                            <div class="card-footer">
                                <div class="status-text">Can not choose this business</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activation AppID 面板 -->
                <div class="content-panel" id="panel-activation">
<!--                    <h1 class="content-title">Activation AppID</h1>-->

                    <div class="activation-form">
                        <!-- AppID 区块 -->
                        <div class="form-group">
                            <label class="form-label">AppID</label>
                            <input type="text" id="app-id-input" class="form-input" value="" readonly>
                            <div class="form-hint">AppID will be automatically generated after created</div>
                        </div>

                        <!-- RSA Key 区块 -->
                        <div class="form-group">
                            <label class="form-label">RSA Key</label>
                            <div class="rsa-key-row">
                                <div class="rsa-key-item">
                                    <label class="rsa-label">Public Key</label>
                                    <textarea id="public-key-input" class="form-textarea" rows="4"></textarea>
                                </div>
                                <div class="rsa-key-item">
                                    <label class="rsa-label">Private Key</label>
                                    <textarea id="private-key-input" class="form-textarea" rows="4"></textarea>
                                </div>
                            </div>
                            <div class="form-hint">You can replace with your own RSA key pair</div>
                        </div>

                        <!-- Webhook Key 区块 -->
                        <div class="form-group">
                            <label class="form-label">Webhook Key</label>
                            <textarea id="webhook-key-input" class="form-textarea" rows="4" readonly></textarea>
                            <div class="form-hint">Webhook key will be automatically generated after created</div>
                        </div>

                        <!-- 操作按钮 -->
                        <div class="form-actions">
                            <button id="recreate-btn" class="btn btn-secondary">ReCreate</button>
                            <button id="create-app-id-btn" class="btn btn-primary">Create</button>
                        </div>
                    </div>
                </div>

                <!-- Settings 面板 -->
                <div class="content-panel" id="panel-settings">
<!--                    <h1 class="content-title">Settings</h1>-->

                    <div class="settings-form">
                        <!-- Title 区块 -->
                        <div class="form-group">
                            <label class="form-label">Title</label>
                            <input type="text" id="settings-title" class="form-input">
                            <div class="form-hint">The payment option name</div>
                        </div>

                        <!-- Description 区块 -->
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea id="settings-description" class="form-textarea" rows="4"></textarea>
                            <div class="form-hint">The payment option description</div>
                        </div>

                        <!-- Sandbox Mode 区块 -->
                        <div class="form-group">
                            <label class="form-label">Sandbox Mode</label>
                            <div class="toggle-switch">
                                <div id="settings-sandbox" class="toggle-button" data-enabled="false">
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-text">Enable Sandbox Mode</span>
                                </div>
                            </div>
                            <div class="form-hint">Please turn off sandbox mode before you go live</div>
                        </div>

                        <!-- Payment Due Days 区块 -->
                        <div class="form-group">
                            <label class="form-label">Payment Due Days</label>
                            <input type="number" id="settings-due-date" class="form-input" min="1" max="365" value="30">
                        </div>

                        <!-- 操作按钮 -->
                        <div class="form-actions">
                            <button id="save-settings-btn" class="btn btn-primary">Save</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 关闭上层卡片 -->
            </div>
        </div>

<style>
    /* 卡片网格布局 */
    .cards-container {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-top: 30px;
        padding-left: 15px;
        padding-right: 15px;
    }

    /* 卡片基础样式 */
    .business-card {
        width: 100%;
        min-height: 200px;
        border: 1px solid #e0e0e0;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    #create-app-id-btn {
        margin-top: -30px;
    }

    #recreate-btn {
        margin-top: -30px;
    }

    .business-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
    }

    /* 选中状态的卡片 */
    .business-card.selected {
        border: 3px solid #28a745;
        box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.1);
        transform: translateY(-2px);
    }

    .business-card.selected .card-header {
        background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    }

    /* 已连接状态的卡片 */
    .business-card.connected {
        border: 3px solid #28a745;
        box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.1);
        transform: translateY(-2px);
    }

    .business-card.connected .card-header {
        background: linear-gradient(135deg, #28a745 0%, #218838 100%);
    }

    .business-card.connected .card-footer {
        background: #28a745;
        color: white;
    }

    /* 连接状态文字 */
    .connection-status {
        font-size: 16px;
        font-weight: bold;
        text-align: center;
        padding: 8px 0;
    }

    .connection-status.connected {
        color: white;
    }

    /* 已连接按钮样式 (保留但不使用) */
    .choose-btn.selected-btn,
    .choose-btn.connected-btn {
        background: #28a745;
        color: white;
        cursor: not-allowed;
        opacity: 0.8;
    }

    .choose-btn.selected-btn:hover,
    .choose-btn.connected-btn:hover {
        background: #28a745;
        opacity: 0.8;
    }

    /* 连接信息容器 (保留但不使用) */
    .connection-info {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: center;
    }

    /* App ID 显示 */
    .app-id-display {
        font-size: 12px;
        color: #6c757d;
        background: #f8f9fa;
        padding: 6px 12px;
        border-radius: 4px;
        border: 1px solid #dee2e6;
        word-break: break-all;
        text-align: center;
        max-width: 100%;
    }

    /* 卡片头部 - 全宽色块 */
    .card-header {
        width: 100%;
        height: 60px;
        color: white;
        font-weight: bold;
        font-size: 15px;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
    }

    /* 不同状态的颜色 */
                .pending .card-header {
                    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
                }

                .active .card-header {
                    background: rgba(0, 186, 173, 1);
                }

                .inactive .card-header {
                    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
                }    /* 卡片中间部分 */
    .card-body {
        width: 100%;
        padding: 25px 20px;
        text-align: center;
        background: white;
        box-sizing: border-box;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .store-name {
                    font-size: 18px;
                    font-weight: 600;
                    color: #333;
                    margin: 0 0 8px 0;
                }

                .business-id {
                    font-size: 14px;
                    color: #6c757d;
                    margin: 0;
                }
    /* 卡片底部 */
    .card-footer {
        width: 100%;
        height: 40px;
        padding: 0 20px;
        text-align: center;
        box-sizing: border-box;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Pending和Inactive卡片的底部文字 */
                .pending .card-footer,
                .inactive .card-footer {
                    background: rgba(217, 217, 217, 1);
                }

                .status-text {
                    color: #333;
                    font-size: 15px;
                    margin: 0;
                    line-height: 1.5;
                }    /* Active卡片的按钮 */
    .active .card-footer {
        padding: 0;
    }

    .choose-btn {
        width: 100%;
        height: 40px;
        background: rgba(42, 130, 228, 1);
        color: white;
        border: none;
        font-size: 16px;
        font-weight: bold;
        letter-spacing: 0.5px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .choose-btn:hover {
        background: rgba(42, 130, 228, 0.8);
    }

                .loading-business,
                .no-business {
                    text-align: center;
                    color: #6c757d;
                    font-size: 16px;
                    padding: 40px 20px;
                }

                .no-business {
                    color: #dc3545;
                }
    /* Activation AppID 面板样式 */
    .activation-form {
        max-width: 800px;
        margin: 0 auto;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        font-size: 16px;
        font-weight: 600;
        color: #333;
        margin-bottom: 10px;
    }

    .form-input {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        font-size: 14px;
        color: #333;
        background-color: #f8f9fa;
        box-sizing: border-box;
    }

    .form-input[readonly] {
        background-color: #e9ecef;
        cursor: not-allowed;
    }

    .rsa-key-row {
        display: flex;
        gap: 20px;
    }

    .rsa-key-item {
        flex: 1;
    }

    .rsa-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #666;
        margin-bottom: 8px;
    }

    .form-textarea {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        font-size: 13px;
        color: #333;
        background-color: #fff;
        box-sizing: border-box;
        resize: vertical;
        font-family: monospace;
    }

    .form-hint {
        margin-top: 8px;
        font-size: 13px;
        color: #6c757d;
        font-style: italic;
    }

    /* Toggle Switch 样式 */
    .toggle-switch {
        display: flex;
        align-items: center;
        margin-top: 10px;
    }

    .toggle-button {
        display: flex;
        align-items: center;
        cursor: pointer;
        user-select: none;
    }

    .toggle-slider {
        width: 50px;
        height: 26px;
        background-color: #ccc;
        border-radius: 13px;
        position: relative;
        transition: background-color 0.3s;
    }

    .toggle-slider:before {
        content: "";
        position: absolute;
        width: 22px;
        height: 22px;
        left: 2px;
        bottom: 2px;
        background-color: white;
        border-radius: 50%;
        transition: transform 0.3s;
    }

    .toggle-button[data-enabled="true"] .toggle-slider {
        background-color: #3b82f6;
    }

    .toggle-button[data-enabled="true"] .toggle-slider:before {
        transform: translateX(24px);
    }

    .toggle-text {
        margin-left: 12px;
        font-size: 14px;
        color: #333;
    }

    .form-actions {
        display: flex;
        gap: 15px;
        margin-top: 40px;
        justify-content: flex-end;
    }

    .btn {
        padding: 12px 30px;
        border: none;
        border-radius: 6px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    .btn-secondary {
        background: #f8f9fa;
        color: #333;
        border: 1px solid #e0e0e0;
    }

    .btn-secondary:hover {
        background: #e9ecef;
    }
            /* 模态框样式 */
            .modal-container {
                width: 100%;
                height: 100%;
                background: #f5f7fa;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
                overflow: visible;
                display: flex;
                position: relative;
                padding-bottom: 20px;
            }

            /* 底层卡片 - 左侧菜单栏 */
            .base-card {
                width: 280px;
                height: 100%;
                background: #f8f9fa;
                z-index: 1;
                flex-shrink: 0;
                display: flex;
                flex-direction: column;
            }

            .menu-items {
                padding: 0 15px;
                flex: 1;
                overflow-y: auto;
                margin-top: 15px;
            }

            .menu-level-1 {
                padding: 20px 20px 15px;
                font-size: 18px;
                font-weight: 600;
                color: #1a1a1a;
                border-bottom: 1px solid #e9ecef;
                margin-bottom: 10px;
            }

            .menu-item {
                padding: 14px 20px;
                margin: 6px 0;
                border-radius: 8px;
                cursor: pointer;
                color: #495057;
                font-size: 15px;
                transition: all 0.2s;
            }

            .menu-item:hover {
                background: #e9ecef;
            }

            .menu-item.active {
                background: #3b82f6;
                color: white;
            }

            /* 上层卡片 - 包含头部和内容区 */
            .floating-card {
                flex: 1;
                padding: 0;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
                height: calc(100% - 20px);
                background: white;
                margin: 10px 10px 10px 0px;
                border-radius: 16px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 10px 20px rgba(0, 0, 0, 0.10), 8px 0 16px rgba(0, 0, 0, 0.08);
                z-index: 2;
            }

            /* 卡片头部 */
            .card-header {
                padding: 25px 30px 20px;
                border-bottom: 1px solid #e9ecef;
                flex-shrink: 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .card-header h2 {
                font-size: 20px;
                color: #1a1a1a;
                margin: 0;
            }

            .card-close-btn {
                background: none;
                border: none;
                font-size: 28px;
                color: #999;
                cursor: pointer;
                padding: 0;
                width: 30px;
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: color 0.2s;
            }

            .card-close-btn:hover {
                color: #333;
            }

            .content-title {
                font-size: 28px;
                color: #1a1a1a;
                margin: 0 0 15px 0;
                text-align: center;
            }

            .content-description {
                font-size: 16px;
                color: #6c757d;
                line-height: 1.5;
                text-align: center;
                margin: 0 auto 40px;
                max-width: 600px;
            }

            /* QR码区域 */
            .qr-code-section {
                text-align: center;
                margin-bottom: 20px;
            }

            .qr-code-placeholder {
                width: 280px;
                height: 280px;
                background: #f8f9fa;
                border: 2px dashed #dee2e6;
                border-radius: 12px;
                margin: 0 auto;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                color: #6c757d;
                overflow: hidden;
            }

            .qr-code-placeholder img {
                width: 100%;
                height: 100%;
                object-fit: contain;
                border: none;
                padding: 10px;
                border-radius: 12px;
            }

            .scan-success {
                color: #28a745;
                font-size: 24px;
                font-weight: bold;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                text-align: center;
                padding: 20px;
            }

            .qr-expiry {
                text-align: center;
                color: #6c757d;
                font-size: 14px;
                margin-top: 15px;
                padding: 8px 15px;
                background: #f8f9fa;
                border-radius: 4px;
                display: inline-block;
            }

            .qr-expiry strong {
                color: #dc3545;
            }

            .qr-refresh-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin-top: 15px;
                padding: 10px 20px;
                background: #667eea;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .qr-refresh-btn:hover {
                background: #5568d3;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }

            .qr-refresh-btn:active {
                transform: translateY(0);
            }

            .qr-refresh-btn svg {
                width: 16px;
                height: 16px;
            }

            /* 底部提示 */
            .bottom-note {
                text-align: center;
                color: #6c757d;
                font-size: 15px;
                margin-top: 10px;
            }

            .bottom-note a {
                text-decoration: none;
                border-bottom: 1px solid;
            }

            .bottom-note a:hover {
                color: #5568d3;
            }

            /* 内容面板 */
            .content-panel {
                display: none;
                width: 100%;
                max-width: 800px;
                padding: 30px 40px 40px;
                box-sizing: border-box;
            }

            .content-panel.active {
                display: block;
            }

            .btn btn-primary{
                margin-top: -30px;
            }

            .btn btn-secondary{
                margin-top: -30px;
            }

            /* 响应式调整 */
            @media (max-width: 950px) {
                .modal-container {
                    width: 95%;
                    height: 90%;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // 使用window对象存储轮询变量，确保全局唯一
                if (typeof window.wonderPaymentsPollInterval === 'undefined') {
                    window.wonderPaymentsPollInterval = null;
                }
                if (typeof window.wonderPaymentsCurrentUuid === 'undefined') {
                    window.wonderPaymentsCurrentUuid = null;
                }

                // 页面加载时检查登录状态并直接跳转到合适的页面
                var accessToken = localStorage.getItem('wonder_access_token');
                var businessId = localStorage.getItem('wonder_business_id');
                var selectedBusinessId = localStorage.getItem('wonder_selected_business_id');

                console.log('Page load - Access Token:', accessToken ? 'exists' : 'not exists');
                console.log('Page load - Business ID:', businessId);
                console.log('Page load - Selected Business ID:', selectedBusinessId);

                // 先清除可能存在的旧轮询
                if (window.wonderPaymentsPollInterval) {
                    console.log('Clearing existing poll interval on page load:', window.wonderPaymentsPollInterval);
                    clearInterval(window.wonderPaymentsPollInterval);
                    window.wonderPaymentsPollInterval = null;
                }

                // 先隐藏所有面板，避免闪烁
                $('.content-panel').removeClass('active');
                $('.menu-item').removeClass('active');

                // 根据状态直接显示合适的页面
                if (accessToken && businessId) {
                    // 已登录
                    console.log('User is already logged in');
                    
                    // 显示Logout按钮
                    $('#logout-section').show();
                    $('.qr-code-section').hide();
                    $('.qr-loading').html('<div class="scan-success">✓ Scanned Successfully</div>').show();
                    
                    // 检查是否已选择店铺
                    if (selectedBusinessId && selectedBusinessId !== '' && selectedBusinessId !== 'null' && selectedBusinessId !== 'undefined') {
                        // 已选择店铺，直接显示Activation AppID页面
                        console.log('Business already selected, showing activation page directly');
                        $('.menu-item[data-tab="activation"]').addClass('active');
                        $('#panel-activation').addClass('active');
                        loadActivationPage();
                    } else {
                        // 未选择店铺，直接显示Choose business页面
                        console.log('Business not selected, showing business selection page directly');
                        $('.menu-item[data-tab="business"]').addClass('active');
                        $('#panel-business').addClass('active');
                        loadBusinessList();
                    }
                } else {
                    // 未登录，显示scan页面
                    console.log('User is not logged in, showing scan page');
                    $('.menu-item[data-tab="scan"]').addClass('active');
                    $('#panel-scan').addClass('active');
                    generateQRCode();
                }

                // 刷新按钮点击事件
                $('#refresh-qr-btn').on('click', function() {
                    console.log('Refresh QR Code clicked');
                    generateQRCode();
                });

                // Logout按钮点击事件
                $('#logout-btn').on('click', function() {
                    console.log('Logout button clicked');
                    
                                        // 清除localStorage中的登录信息
                                        localStorage.removeItem('wonder_access_token');
                                        localStorage.removeItem('wonder_business_id');
                                        localStorage.removeItem('wonder_selected_business_id');
                                        localStorage.removeItem('wonder_selected_business_name');
                    
                                        console.log('Logged out successfully');
                    
                                        
                    
                                                            // 重新生成二维码
                    
                                                            generateQRCode();
                    
                                        
                    
                                                            // 隐藏logout section,显示二维码section
                    
                                                            $('#logout-section').hide();
                    
                                                            $('.qr-code-section').show();
                    
                                                        });

                // 重新创建按钮点击事件
                $('#recreate-btn').on('click', function() {
                    console.log('Recreate button clicked');

                    // 清除所有localStorage数据
                    localStorage.removeItem('wonder_access_token');
                    localStorage.removeItem('wonder_business_id');
                    localStorage.removeItem('wonder_selected_business_id');
                    localStorage.removeItem('wonder_selected_business_name');
                    
                    // 清除其他可能的状态数据
                    localStorage.removeItem('wonder_skip_business_list_load');
                    localStorage.removeItem('wonder_app_id');
                    localStorage.removeItem('wonder_private_key');
                    localStorage.removeItem('wonder_public_key');
                    localStorage.removeItem('wonder_webhook_key');

                    console.log('All localStorage data cleared');

                    // 调用后端清除所有数据（包括Settings）
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_clear_all',
                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>'
                        },
                        success: function(response) {
                            console.log('All data cleared:', response);

                            // 清除business页面的DOM状态
                            $('.business-card').removeClass('selected connected');
                            $('.choose-btn').removeClass('selected-btn connected-btn');
                            
                            // 跳转到第一个页面（Scan qrcode）
                            $('.menu-item[data-tab="scan"]').click();
                        },
                        error: function(xhr, status, error) {
                            console.error('Failed to clear all data:', error);
                        }
                    });
                });

                // Create按钮点击事件
                $('#create-app-id-btn').on('click', function() {
                    console.log('Create button clicked');

                    var businessId = localStorage.getItem('wonder_selected_business_id');
                    var businessName = localStorage.getItem('wonder_selected_business_name');

                    if (!businessId) {
                        $('.menu-item[data-tab="business"]').click();
                        return;
                    }

                    // 生成app_id
                    generateAppIdOnly(businessId, businessName);
                });

// 监听来自父窗口的停止轮询消息
                window.addEventListener('message', function(event) {
                    if (event.data && event.data.action === 'stopPolling') {
                        console.log('Received stop polling message');
                        if (window.wonderPaymentsPollInterval) {
                            clearInterval(window.wonderPaymentsPollInterval);
                            window.wonderPaymentsPollInterval = null;
                        }
                    }
                });

// 左侧菜单点击事件
                    $('.menu-item').on('click', function() {
                        var $this = $(this);
                        var tabId = $this.data('tab');

                        console.log('Menu item clicked:', tabId);

                        // 清除轮询定时器（如果存在）
                        if (window.wonderPaymentsPollInterval) {
                            console.log('Clearing poll interval');
                            clearInterval(window.wonderPaymentsPollInterval);
                            window.wonderPaymentsPollInterval = null;
                        }

                        // 移除所有激活状态
                        $('.menu-item').removeClass('active');
                        $('.content-panel').removeClass('active');

                        // 激活当前项
                        $this.addClass('active');
                        $('#panel-' + tabId).addClass('active');

                        // 如果是扫描二维码页面，检查登录状态
                        if (tabId === 'scan') {
                            var accessToken = localStorage.getItem('wonder_access_token');
                            var businessId = localStorage.getItem('wonder_business_id');

                            if (accessToken && businessId) {
                                // 已登录,显示Logout按钮
                                console.log('User is logged in, showing logout button');
                                $('#logout-section').show();
                                $('.qr-code-section').hide();
                                $('.qr-loading').html('<div class="scan-success">✓ Scanned Successfully</div>').show();
                            } else {
                                // 未登录,生成二维码
                                console.log('User is not logged in, generating QR code');
                                generateQRCode();
                            }
                        }

                        // 如果是商家选择页面，加载商户店铺信息
                        if (tabId === 'business') {
                            console.log('Business tab clicked, loading business list');
                            loadBusinessList();
                        }

                        // 如果是Activation AppID页面，生成密钥对
                        if (tabId === 'activation') {
                            loadActivationPage();
                        }

                        // 如果是Settings页面，加载设置
                        if (tabId === 'settings') {
                            loadSettings();
                        }
                    });

                // 轮询检查二维码扫描状态
                function startPolling(uuid) {
                    console.log('startPolling called with UUID:', uuid);

                    // 清除之前的轮询
                    if (window.wonderPaymentsPollInterval) {
                        console.log('Clearing previous poll interval:', window.wonderPaymentsPollInterval);
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                    }

                    // 保存当前UUID
                    window.wonderPaymentsCurrentUuid = uuid;

                    // 每2秒轮询一次
                    console.log('Starting new poll interval for UUID:', uuid);
                    window.wonderPaymentsPollInterval = setInterval(function() {
                        console.log('Polling UUID:', window.wonderPaymentsCurrentUuid);
                        // 使用 SDK 查询二维码状态
                        $.ajax({
                            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                            method: 'GET',
                            data: {
                                action: 'wonder_payments_sdk_qrcode_status',
                                uuid: uuid,
                                security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>'
                            },
                            success: function(response) {
                                console.log('Poll Response:', response);

                                if (response.success && response.data && response.data.data) {
                                    var data = response.data.data;

                                    // 已扫描并且有 access_token，表示登录成功
                                    if (data.is_scan && data.access_token) {
                                        console.log('QR Code Scanned Successfully! Access Token:', data.access_token);
                                        console.log('Business ID:', data.business_id);

                                        // 保存 business_id 和 access_token 到 localStorage
                                        if (data.business_id) {
                                            localStorage.setItem('wonder_business_id', data.business_id);
                                            console.log('Business ID saved to localStorage');
                                        }

                                        if (data.access_token) {
                                            localStorage.setItem('wonder_access_token', data.access_token);
                                            console.log('Access Token saved to localStorage');
                                        }

                                        // 保存到后端
                                        $.ajax({
                                            url: ajaxurl,
                                            method: 'POST',
                                            data: {
                                                action: 'wonder_payments_sdk_save_access_token',
                                                security: "<?php echo esc_attr( wp_create_nonce( 'wonder_payments_modal_nonce' ) ); ?>",
                                                access_token: data.access_token,
                                                business_id: data.business_id || ''
                                            },
                                            success: function(response) {
                                                console.log('Access Token saved to backend:', response);

                                                // 如果business_id为空,从business列表中获取
                                                if (!data.business_id) {
                                                    console.log('Business ID is empty, fetching from business list...');
                                                    $.ajax({
                                                        url: ajaxurl,
                                                        method: 'POST',
                                                        data: {
                                                            action: 'wonder_payments_sdk_get_businesses',
                                                            security: "<?php echo esc_attr( wp_create_nonce( 'wonder_payments_modal_nonce' ) ); ?>"
                                                        },
                                                        success: function(bizResponse) {
                                                            console.log('Business list response:', bizResponse);
                                                            if (bizResponse.success && bizResponse.data && bizResponse.data.data && bizResponse.data.data.length > 0) {
                                                                var firstBusiness = bizResponse.data.data[0];
                                                                var businessId = firstBusiness.business_id || firstBusiness.id;
                                                                if (businessId) {
                                                                    localStorage.setItem('wonder_business_id', businessId);
                                                                    console.log('Business ID fetched from business list:', businessId);
                                                                }
                                                            }
                                                        },
                                                        error: function(xhr, status, error) {
                                                            console.error('Failed to fetch business list:', error);
                                                        }
                                                    });
                                                }
                                            },
                                            error: function(xhr, status, error) {
                                                console.error('Failed to save access token to backend:', error);
                                            }
                                        });

                                        // 停止轮询
                                        if (window.wonderPaymentsPollInterval) {
                                            clearInterval(window.wonderPaymentsPollInterval);
                                            window.wonderPaymentsPollInterval = null;
                                        }

// 显示扫描成功提示
                                        showScanSuccess();

                                        // 2秒后自动跳转到商家选择页面
                                        setTimeout(function() {
                                            console.log('Redirecting to business selection page');
                                            // 点击 "Choose business connect this shop" 菜单项
                                            $('.menu-item[data-tab="business"]').click();
                                        }, 2000);
                                    }

                                    // 二维码已过期
                                    if (data.is_expired) {
                                        console.log('QR Code expired');
                                        if (window.wonderPaymentsPollInterval) {
                                            clearInterval(window.wonderPaymentsPollInterval);
                                            window.wonderPaymentsPollInterval = null;
                                        }
                                    }

                                    // 用户取消
                                    if (data.is_cancel) {
                                        console.log('User cancelled login');
                                        if (window.wonderPaymentsPollInterval) {
                                            clearInterval(window.wonderPaymentsPollInterval);
                                            window.wonderPaymentsPollInterval = null;
                                        }
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Poll Error:', error);
                            }
                        });
                    }, 2000);
                }

                // 显示扫描成功提示
                function showScanSuccess() {
                    var $loading = $('.qr-loading');
                    var $img = $('.qr-code-placeholder img');

                    // 隐藏二维码图片
                    $img.hide();

                    // 显示成功提示
                    $loading.html('<div class="scan-success">✓ Scanned Successfully</div>').show();

                    // 显示Logout按钮
                    $('#logout-section').show();
                }

                // 加载商户店铺列表

                                // 检查现有连接状态
                function checkExistingConnection() {
                    console.log('checkExistingConnection called');
                    
                    var deferred = $.Deferred();
                    
                    var businessId = localStorage.getItem('wonder_business_id');
                    var accessToken = localStorage.getItem('wonder_access_token');
                    
                    if (!businessId || !accessToken) {
                        console.log('No business ID or access token, resolving with no connection');
                        deferred.resolve({
                            success: true,
                            data: {
                                connected: false
                            }
                        });
                        return deferred.promise();
                    }
                    
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_check_connection',
                            security: "<?php echo esc_attr( wp_create_nonce( 'wonder_payments_modal_nonce' ) ); ?>"
                        },
                        success: function(response) {
                            console.log('Check connection response:', response);
                            deferred.resolve(response);
                        },
                        error: function(xhr, status, error) {
                            console.error('Check connection error:', error);
                            deferred.resolve({
                                success: true,
                                data: {
                                    connected: false
                                }
                            });
                        }
                    });
                    
                    return deferred.promise();
                }

                function loadBusinessList() {

                                                                    console.log('loadBusinessList() called');

                                

                                                                    var businessId = localStorage.getItem('wonder_business_id');

                                                                    var accessToken = localStorage.getItem('wonder_access_token');

                                

                                                                    console.log('Business ID:', businessId);

                                                                    console.log('Access Token:', accessToken);

                                

                                                                    if (!businessId || !accessToken) {

                                                                        console.error('Business ID or Access Token not found');

                                                                        // 显示提示信息

                                                                        $('.cards-container').html('<div class="no-business">Please scan QR code to login first</div>');

                                                                        return;

                                                                    }

                                

                                                                    // 临时显示加载中

                                                                    $('.cards-container').html('<div class="loading-business">Loading business list...</div>');

                                

                                                                    // 先获取店铺列表

                                                                    $.ajax({

                                                                        url: ajaxurl,

                                                                        method: 'POST',

                                                                        data: {

                                                                            action: 'wonder_payments_sdk_get_businesses',

                                                                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>'

                                                                        },

                                                                        success: function(response) {

                                                                            console.log('Business List Response:', response);

                                                                            console.log('Response success:', response.success);

                                                                            console.log('Response data:', response.data);

                                

                                                                            // 后端返回的是嵌套结构: {data: {data: [店铺数组]}}

                                                                            // 需要提取 response.data.data

                                                                            var businessList = response.data && response.data.data ? response.data.data : [];

                                

                                                                            console.log('Business list:', businessList);

                                                                            console.log('Business list type:', typeof businessList);

                                                                            console.log('Business list is array:', Array.isArray(businessList));

                                                                            console.log('Business list length:', businessList.length);

                                

                                                                            if (response.success && businessList && Array.isArray(businessList) && businessList.length > 0) {

                                                                                // 先检查连接状态，等待完成后再渲染店铺列表

                                                                                console.log('Checking existing connection before rendering...');

                                                                                checkExistingConnection().done(function(connectionResponse) {

                                                                                    console.log('Existing connection response:', connectionResponse);

                                                                                    console.log('Connection response success:', connectionResponse.success);

                                                                                    console.log('Connection response data:', connectionResponse.data);

                                

                                                                                    var existingConnection = null;

                                                                                    if (connectionResponse.success && connectionResponse.data && connectionResponse.data.connected) {

                                                                                        existingConnection = connectionResponse.data;

                                                                                        console.log('Found existing connection:', existingConnection);

                                                                                        console.log('Business ID from connection:', existingConnection.business_id);

                                                                                    } else {

                                                                                        console.log('No existing connection found');

                                                                                    }

                                

                                                                                    // 使用连接状态渲染店铺列表（只渲染一次）

                                                                                    console.log('Rendering business list with connection status...');

                                                                                    renderBusinessList(businessList, existingConnection);

                                                                                }).fail(function(xhr, status, error) {

                                                                                    console.error('Check connection error:', error);

                                                                                    // 即使检查失败，也渲染店铺列表（不显示连接状态）

                                                                                    console.log('Rendering business list without connection status...');

                                                                                    renderBusinessList(businessList, null);

                                                                                });

                                                                            } else {

                                                                                $('.cards-container').html('<div class="no-business">No business found</div>');

                                                                            }

                                                                        },

                                                                        error: function(xhr, status, error) {

                                                                            console.error('Business List API Error:', error);

                                                                            $('.cards-container').html('<div class="no-business">Failed to load business list</div>');

                                                                        }

                                                                    });

                                                                }



                                        // 渲染商户店铺列表
                function renderBusinessList(businesses, existingConnection) {
                    console.log('Rendering business list:', businesses);
                    console.log('Existing connection:', existingConnection);

                    // 优先使用 localStorage 中的已选择店铺 ID
                    var selectedBusinessId = localStorage.getItem('wonder_selected_business_id');
                    console.log('Selected business ID from localStorage:', selectedBusinessId);

                    // 从后端获取已连接的店铺 ID
                    var connectedBusinessId = existingConnection && existingConnection.business_id ? existingConnection.business_id : null;
                    var connectedAppId = existingConnection && existingConnection.app_id ? existingConnection.app_id : null;

                    console.log('Connected business ID from backend:', connectedBusinessId);
                    console.log('Connected app ID:', connectedAppId);

                    // 使用 localStorage 中的值作为已选择的店铺 ID
                    var finalBusinessId = selectedBusinessId || connectedBusinessId;
                    console.log('Final business ID to use:', finalBusinessId);

                    var html = '';

                    businesses.forEach(function(business, index) {
                        var status = business.status || 'inactive';
                        var statusText = status.toUpperCase();

                        // 只有Active状态使用active类，其他都使用inactive类
                        var statusClass = (status === 'Active') ? 'active' : 'inactive';

                        // 检查是否已连接该店铺 - 使用 finalBusinessId
                        var isConnected = (finalBusinessId === business.id);
                        var connectedClass = isConnected ? 'connected' : '';

                        var businessName = business.business_name || business.business_dba || 'Business Name';
                        var businessId = business.business_id || '';

                        var buttonHtml = '';
                        if (status === 'Active') {
                            if (isConnected) {
                                // 已连接,显示绿色背景和 Connected 文字
                                buttonHtml = '<div class="connection-status connected">Connected</div>' +
                                           (connectedAppId ? '<div class="app-id-display">App ID: ' + connectedAppId + '</div>' : '');
                            } else {
                                // 未连接,显示"选择"按钮
                                buttonHtml = '<button class="choose-btn" data-business-id="' + business.id + '" data-business-name="' + businessName.replace(/"/g, '&quot;') + '">Choose</button>';
                            }
                        } else {
                            buttonHtml = '<div class="status-text">Can not choose this business</div>';
                        }

                        html += '<div class="business-card ' + statusClass + ' ' + connectedClass + '" data-business-id="' + business.id + '">' +
                                '<div class="card-header">' + statusText + '</div>' +
                                '<div class="card-body">' +
                                '<div class="store-name">' + businessName + '</div>' +
                                '</div>' +
                                '<div class="card-footer">' +
                                buttonHtml +
                                '</div>' +
                                '</div>';
                    });

                    // 更新页面
                    $('.cards-container').html(html);

                    // 绑定选择按钮点击事件
                    console.log('Binding click events to choose buttons...');
                    console.log('Found choose buttons:', $('.choose-btn:not(.connected-btn)').length);

                    $('.choose-btn:not(.connected-btn)').on('click', function() {
                        var businessId = $(this).data('business-id');
                        var businessName = $(this).data('business-name');

                        console.log('Choose button clicked!');
                        console.log('Choose business:', businessId, businessName);

                        // 检查是否已有连接 - 使用 finalBusinessId
                        if (finalBusinessId) {
                            // 已有连接,询问是否切换
                            var currentBusinessName = existingConnection && existingConnection.business_name ? existingConnection.business_name : 'Unknown';
                            var switchMessage = 'You are already connected to a business.\n\n' +
                                              'Do you want to switch to this new business?\n\n' +
                                              'New: ' + businessName + '\n\n' +
                                              'Note: This will generate a new App ID and replace the old one.';

                            // 用户确认切换
                            localStorage.setItem('wonder_selected_business_id', businessId);
                            localStorage.setItem('wonder_selected_business_name', businessName);

                            // 调用后端保存选择的店铺ID
                            saveSelectedBusiness(businessId, businessName);
                        } else {
                            // 没有连接,直接确认
                            // 用户确认,保存选择的商户信息
                            localStorage.setItem('wonder_selected_business_id', businessId);
                            localStorage.setItem('wonder_selected_business_name', businessName);

                            // 调用后端保存选择的店铺ID
                            saveSelectedBusiness(businessId, businessName);
                        }
                    });
                }

                // 保存选择的店铺ID
                function saveSelectedBusiness(businessId, businessName) {
                    console.log('Saving selected business:', businessId, businessName);

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_save_selected_business',
                            security: "<?php echo esc_attr( wp_create_nonce( 'wonder_payments_modal_nonce' ) ); ?>",
                            business_id: businessId,
                            business_name: businessName
                        },
                        success: function(response) {
                            console.log('Selected business saved:', response);
                            if (response.success) {
                                // 重新加载店铺列表以刷新页面状态
                                loadBusinessList();
                                // 跳转到 Activation AppID 页面
                                setTimeout(function() {
                                    console.log('About to switch to activation tab...');
                                    $('.menu-item[data-tab="activation"]').click();
                                    console.log('Activation tab clicked');
                                }, 500);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Save selected business error:', error);
                        }
                    });
                }

                // 调用后端生成密钥对并获取 app_id
                function generateKeyPairAndAppId(businessId, businessName) {
                    console.log('Generating key pair and app_id for business:', businessId);

                    // 显示加载中
                    $('.cards-container').html('<div class="loading-business">Generating key pair and app_id...</div>');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_sdk_generate_app_id',
                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>',
                            business_id: businessId,
                            business_name: businessName
                        },
                        success: function(response) {
                            console.log('App ID generated successfully:', response);
                            console.log('Response structure:', JSON.stringify(response));

                            if (response.success) {
                                // 尝试从不同位置提取 app_id
                                var appId = response.app_id ||
                                           (response.data && response.data.app_id) ||
                                           (response.data && response.data.data && response.data.data.app_id) || '';

                                console.log('Extracted App ID:', appId);

                                if (appId) {

                                    console.log('About to call loadBusinessList()...');
                                    console.log('loadBusinessList function exists:', typeof loadBusinessList === 'function');

                                    // 重新加载店铺列表以刷新页面状态
                                    loadBusinessList();

                                    console.log('loadBusinessList() called');

                                    // 跳转到 Settings 页面
                                    setTimeout(function() {
                                        console.log('About to switch to settings tab...');
                                        $('.menu-item[data-tab="settings"]').click();
                                        console.log('Settings tab clicked');
                                    }, 500);
                                } else {
                                }
                            } else {
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Generate app_id error:', error);
                            console.error('Response:', xhr.responseText);
                        }
                    });
                }

                // 加载Activation页面,生成密钥对
                function loadActivationPage() {
                    console.log('Loading Activation page');

                    // 检查是否已选择店铺
                    var businessId = localStorage.getItem('wonder_selected_business_id');
                    var businessName = localStorage.getItem('wonder_selected_business_name');

                    if (!businessId) {
                        $('.menu-item[data-tab="business"]').click();
                        return;
                    }

                    // 生成密钥对(会自动检查是否已生成app_id并设置按钮状态)
                    generateKeyPairOnly(businessId);
                }

                // 只生成密钥对,不生成app_id
                                function generateKeyPairOnly(businessId) {
                                    console.log('Generating key pair only for business:', businessId);

                                    // 先清空app_id输入框和重置按钮状态
                                    $('#app-id-input').val('');
                                    $('#create-app-id-btn').text('Create').prop('disabled', false);

                                    $.ajax({
                                        url: ajaxurl,
                                        method: 'POST',
                                        data: {
                                            action: 'wonder_payments_generate_key_pair_only',
                                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>',
                                            business_id: businessId
                                        },                						success: function(response) {
                							console.log('Key pair generated:', response);
                							console.log('Response success:', response.success);
                							console.log('Response data:', response.data);
                							console.log('Full response JSON:', JSON.stringify(response));

                							if (response.success) {
                								// 处理嵌套的data结构
                								var keyData = response.data.data || response.data || {};

                								console.log('Setting public key to input...');
                								console.log('Public key length:', keyData.public_key ? keyData.public_key.length : 0);
                								console.log('Private key length:', keyData.private_key ? keyData.private_key.length : 0);
                								console.log('Webhook key length:', keyData.webhook_key ? keyData.webhook_key.length : 0);
                								console.log('Saved App ID:', keyData.app_id);

                								// 检查文本框是否存在
                								console.log('Public key input exists:', $('#public-key-input').length);
                								console.log('Private key input exists:', $('#private-key-input').length);
                								console.log('Webhook key input exists:', $('#webhook-key-input').length);
                								console.log('App ID input exists:', $('#app-id-input').length);

                								// 显示密钥对
                								                								var publicKey = keyData.public_key || '';

                								                								var privateKey = keyData.private_key || '';

                								                								var savedAppId = keyData.app_id || '';



                								                								// 检查公钥和私钥的前缀,确保显示正确

                								                								console.log('publicKey prefix:', publicKey.substring(0, 30));

                								                								console.log('privateKey prefix:', privateKey.substring(0, 30));



                								                								$('#public-key-input').val(publicKey);

                								                								$('#private-key-input').val(privateKey);

                								// 根据是否已生成app_id设置按钮状态
                								if (savedAppId) {
                									$('#app-id-input').val(savedAppId);
                									$('#create-app-id-btn').text('Created').prop('disabled', true);
                								} else {
                									$('#create-app-id-btn').text('Create').prop('disabled', false);
                								}
                						} else {
                						}
                					},
                					error: function(xhr, status, error) {
                						console.error('Generate key pair error:', error);
                					}
                				});
                				}
                // 只生成app_id,使用已保存的公钥
                function generateAppIdOnly(businessId, businessName) {
                    console.log('Generating app_id only for business:', businessId);

                    // 显示加载中
                    $('#create-app-id-btn').text('Creating...').prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_sdk_create_app_id',
                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>',
                            business_id: businessId,
                            business_name: businessName
                        },
                        success: function(response) {
                            console.log('App ID generated successfully:', response);
                            console.log('Response structure:', JSON.stringify(response));

                            if (response.success) {
                                // 尝试从不同位置提取 app_id
                                var appId = response.app_id ||
                                           (response.data && response.data.app_id) ||
                                           (response.data && response.data.data && response.data.data.app_id) || '';

                                // 尝试从不同位置提取 webhook_key
                                var webhookKey = response.webhook_private_key ||
                                              (response.data && response.data.webhook_private_key) ||
                                              (response.data && response.data.data && response.data.data.webhook_private_key) || '';

                                console.log('Extracted App ID:', appId);
                                console.log('Extracted Webhook Key:', webhookKey);

                                if (appId) {
                                    $('#app-id-input').val(appId);
                                    $('#create-app-id-btn').text('Created').prop('disabled', true);
                                    
                                    // 填充webhook_key
                                    if (webhookKey) {
                                        $('#webhook-key-input').val(webhookKey);
                                    }
                                    
                                    // 跳转到 Settings 页面
                                    setTimeout(function() {
                                        console.log('About to switch to settings tab...');
                                        $('.menu-item[data-tab="settings"]').click();
                                        console.log('Settings tab clicked');
                                    }, 500);
                                } else {
                                    $('#create-app-id-btn').text('Create').prop('disabled', false);
                                }
                            } else {
                                $('#create-app-id-btn').text('Create').prop('disabled', false);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Create App ID error:', error);
                            console.error('Response:', xhr.responseText);
                            $('#create-app-id-btn').text('Create').prop('disabled', false);
                        }
                    });
                }

// 加载Settings页面
                function loadSettings() {
                    console.log('Loading Settings page');

                    // 确保DOM完全加载后再执行
                    setTimeout(function() {
                        // 从WordPress选项加载设置
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'wonder_payments_load_settings',
                                security: "<?php echo esc_attr( wp_create_nonce( 'wonder_payments_modal_nonce' ) ); ?>"
                            },
                            success: function(response) {
                                console.log('Settings loaded:', response);
                                console.log('Response success:', response.success);
                                console.log('Response data:', response.data);
                                console.log('Full response JSON:', JSON.stringify(response));

                                if (response.success && response.data) {
                                    console.log('Response data type:', typeof response.data);
                                    console.log('Response data keys:', Object.keys(response.data));
                                    console.log('Response.data.data:', response.data.data);

                                    // 检查是否需要访问 response.data.data
                                    var settingsData = response.data.data || response.data;
                                    console.log('Final settings data:', settingsData);
                                    console.log('Final settings data type:', typeof settingsData);

                                    console.log('Setting title to:', settingsData.title);
                                    console.log('Setting description to:', settingsData.description);
                                    console.log('Setting sandbox to:', settingsData.sandbox_mode);

                                    // 检查元素是否存在
                                    var $titleInput = $('#settings-title');
                                    var $descInput = $('#settings-description');
                                    var $sandboxToggle = $('#settings-sandbox');

                                    console.log('Title input exists:', $titleInput.length);
                                    console.log('Description input exists:', $descInput.length);
                                    console.log('Sandbox toggle exists:', $sandboxToggle.length);

                                    if ($titleInput.length > 0) {
                                        $titleInput.val(settingsData.title || '');
                                        console.log('Title input value after set:', $titleInput.val());
                                    } else {
                                        console.error('Title input not found!');
                                    }

                                    if ($descInput.length > 0) {
                                        $descInput.val(settingsData.description || '');
                                        console.log('Description input value after set:', $descInput.val());
                                    } else {
                                        console.error('Description input not found!');
                                    }

                                    if ($sandboxToggle.length > 0) {
                                        var enabled = settingsData.sandbox_mode === '1';
                                        $sandboxToggle.attr('data-enabled', enabled);
                                        console.log('Sandbox toggle data-enabled after set:', $sandboxToggle.attr('data-enabled'));
                                    } else {
                                        console.error('Sandbox toggle not found!');
                                    }

                                    // 加载due_date
                                    var $dueDateInput = $('#settings-due-date');
                                    if ($dueDateInput.length > 0) {
                                        $dueDateInput.val(settingsData.due_date || '30');
                                        console.log('Due date input value after set:', $dueDateInput.val());
                                    } else {
                                        console.error('Due date input not found!');
                                    }
                                } else {
                                    console.log('Response success or data is missing');
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Load settings error:', error);
                            }
                        });
                    }, 100);
                }

                // 保存Settings
                function saveSettings() {
                    console.log('Saving settings');

                    var settings = {
                        title: $('#settings-title').val(),
                        description: $('#settings-description').val(),
                        sandbox_mode: $('#settings-sandbox').attr('data-enabled') === 'true' ? '1' : '0',
                        due_date: $('#settings-due-date').val()
                    };

                    $('#save-settings-btn').text('Saving...').prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_save_settings',
                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>',
                            settings: settings
                        },
                        success: function(response) {
                            console.log('Settings saved:', response);

                            if (response.success) {
                                // 关闭模态框
                                $('#close-wonder-modal').click();
                            } else {
                            }

                            $('#save-settings-btn').text('Save').prop('disabled', false);
                        },
                        error: function(xhr, status, error) {
                            console.error('Save settings error:', error);
                            $('#save-settings-btn').text('Save').prop('disabled', false);
                        }
                    });
                }

                // Save按钮点击事件
                $('#save-settings-btn').on('click', function() {
                    saveSettings();
                });

                // Toggle开关点击事件
                $('#settings-sandbox').on('click', function() {
                    var currentStatus = $(this).attr('data-enabled');
                    var newStatus = currentStatus === 'true' ? 'false' : 'true';
                    $(this).attr('data-enabled', newStatus);
                    console.log('Sandbox mode toggled to:', newStatus);
                });

                // 生成二维码
                function generateQRCode() {
                    console.log('generateQRCode called');

                    // 先清除旧的轮询（如果存在）
                    if (window.wonderPaymentsPollInterval) {
                        console.log('Clearing existing poll interval before generating new QR code:', window.wonderPaymentsPollInterval);
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                    }

                    // 隐藏Logout按钮
                    $('#logout-section').hide();

                    // 显示二维码section
                    $('.qr-code-section').show();

                    // 使用 SDK 创建二维码
                    $.ajax({
                        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_sdk_create_qrcode',
                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>'
                        },
                        success: function(response) {
                            console.log('SDK QR Code Response:', response);

                            if (response.success && response.data) {
                                var uuid = response.data.uuid;
                                var sUrl = response.data.sUrl;

                                console.log('UUID:', uuid);
                                console.log('Short URL:', sUrl);

                                // 启动轮询检查扫描状态
                                startPolling(uuid);

                                // 显示二维码图片
                                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' + encodeURIComponent(sUrl);

                                var $img = $('.qr-code-placeholder img');
                                var $loading = $('.qr-loading');

                                // 隐藏 loading，显示图片
                                $loading.hide();
                                $img.attr('src', qrUrl).show();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('SDK QR Code API Error:', error);
                            console.error('Response:', xhr.responseText);
                        }
                    });
                }

                // 生成UUID
                function generateUUID() {
                    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                        var r = Math.random() * 16 | 0,
                            v = c == 'x' ? r : (r & 0x3 | 0x8);
                        return v.toString(16);
                    });
                }
            });
        </script>
        <?php
    }

    private function generate_qrcode() {
        // 不生成占位符，等待 JavaScript 调用接口生成正确的二维码
        echo '<div class="qrcode-image" style="margin-left: -10px;">';
        echo '<img src="" alt="' . esc_attr__('Loading QR code...', 'wonder-payments') . '" style="display: none;">';
        echo '<div class="qr-loading">Loading QR code...</div>';
        echo '</div>';
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wonder-payments') === false) {
            return;
        }

        // 加载样式
        wp_enqueue_style(
            'wonder-payments-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            '1.0.0'
        );

        // 加载脚本
        wp_enqueue_script(
            'wonder-payments-admin',
            plugin_dir_url(__FILE__) . 'assets/js/wonder_payments_admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // 本地化脚本
        wp_localize_script('wonder-payments-admin', 'wonderPayments', array(
            'ajax_url' => esc_url(admin_url('admin-ajax.php')),
            'nonce' => esc_attr(wp_create_nonce('wonder_payments_nonce')),
            'strings' => array(
                'confirm_disconnect' => __('Are you sure you want to disconnect?', 'wonder-payments'),
                'disconnecting' => __('Disconnecting...', 'wonder-payments'),
                'error' => __('An error occurred. Please try again.', 'wonder-payments')
            )
        ));
    }

    public function handle_form_submissions() {
        if (isset($_POST['wonder_payments_disconnect'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wonder_payments_disconnect')) {
                wp_die('Security check failed');
            }

            // 清理选项
            delete_option('wonder_payments_app_id');
            delete_option('wonder_payments_business_name');
            delete_option('wonder_payments_connected');

            wp_safe_redirect(admin_url('admin.php?page=wonder-payments-setup'));
            exit;
        }
    }
}

new Wonder_Payments_Admin();