<?php

if (!defined('ABSPATH')) {
    exit;
}

class Wonder_Payments_Admin {

    public function __construct() {
        // Add admin menu - commented because settings are accessed via the modal
        // add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue settings page styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Handle form submission
        add_action('admin_init', array($this, 'handle_form_submissions'));
    }

    public function render_setup_page() {
        // Check whether already connected
        $is_connected = get_option('wonder_payments_app_id', false);
        $business_name = get_option('wonder_payments_business_name', '');
        ?>
        <div class="modal-container">
            <!-- Base card - left menu -->
            <div class="base-card">
                <div class="menu-items">
                    <div class="menu-level-1">Setup Wonder Payment</div>
                    <div class="menu-item active" data-tab="scan">Scan qrcode to login wonder</div>
                    <div class="menu-item" data-tab="business">Choose business connect this shop</div>
                    <div class="menu-item" data-tab="activation">Activation AppID</div>
                    <div class="menu-item" data-tab="settings">Settings</div>
                </div>
            </div>

            <!-- Top card - header and right content area -->
            <div class="floating-card">
                <!-- Header -->
                <div class="card-header">
                    <h2>Setup Wonder Payment</h2>
                    <button class="card-close-btn" id="close-wonder-modal">&times;</button>
                </div>

                <!-- Scan QR Code panel -->
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

                <!-- Choose Business panel -->
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

                <!-- Activation AppID panel -->
                <div class="content-panel" id="panel-activation">
                    <!--                    <h1 class="content-title">Activation AppID</h1>-->

                    <div class="activation-form">
                        <!-- AppID section -->
                        <div class="form-group">
                            <label class="form-label">AppID</label>
                            <input type="text" id="app-id-input" class="form-input" value="" readonly>
                            <div class="form-hint">AppID will be automatically generated after created</div>
                        </div>

                        <!-- RSA Key section -->
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

                        <!-- Webhook Key section -->
                        <div class="form-group">
                            <label class="form-label">Webhook Key</label>
                            <textarea id="webhook-key-input" class="form-textarea" rows="4" readonly></textarea>
                            <div class="form-hint">Webhook key will be automatically generated after created</div>
                        </div>

                        <!-- Action buttons -->
                        <div class="form-actions">
                            <button id="recreate-btn" class="btn btn-secondary">ReCreate</button>
                            <button id="create-app-id-btn" class="btn btn-primary">Create</button>
                        </div>
                    </div>
                </div>

                <!-- Settings panel -->
                <div class="content-panel" id="panel-settings">
                    <!--                    <h1 class="content-title">Settings</h1>-->

                    <div class="settings-form">
                        <!-- Title section -->
                        <div class="form-group">
                            <label class="form-label">Title</label>
                            <input type="text" id="settings-title" class="form-input">
                            <div class="form-hint">The payment option name</div>
                        </div>

                        <!-- Description section -->
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea id="settings-description" class="form-textarea" rows="4"></textarea>
                            <div class="form-hint">The payment option description</div>
                        </div>

                        <!-- Sandbox Mode section -->
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

                        <!-- Payment Due Days section -->
                        <div class="form-group">
                            <label class="form-label">Payment Due Days</label>
                            <input type="number" id="settings-due-date" class="form-input" min="1" max="365" value="30">
                        </div>

                        <!-- Action buttons -->
                        <div class="form-actions">
                            <button id="save-settings-btn" class="btn btn-primary">Save</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Close top card -->
        </div>
        </div>

        <style>
            /* Card grid layout */
            .cards-container {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-top: 30px;
                padding-left: 15px;
                padding-right: 15px;
            }

            /* Base card styles */
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

            /* Selected card state */
            .business-card.selected {
                border: 3px solid #28a745;
                box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.1);
                transform: translateY(-2px);
            }

            .business-card.selected .card-header {
                background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            }

            /* Connected card state */
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

            /* Connection status text */
            .connection-status {
                font-size: 16px;
                font-weight: bold;
                text-align: center;
                padding: 8px 0;
            }

            .connection-status.connected {
                color: white;
            }

            /* Connected button style (kept but unused) */
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

            /* Connection info container (kept but unused) */
            .connection-info {
                display: flex;
                flex-direction: column;
                gap: 8px;
                align-items: center;
            }

            /* App ID display */
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

            /* Card header - full-width color block */
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

            /* Colors for different states */
            .pending .card-header {
                background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            }

            .active .card-header {
                background: rgba(0, 186, 173, 1);
            }

            .inactive .card-header {
                background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            }    /* Card middle section */
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
            /* Card footer */
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

            /* Footer text for Pending and Inactive cards */
            .pending .card-footer,
            .inactive .card-footer {
                background: rgba(217, 217, 217, 1);
            }

            .status-text {
                color: #333;
                font-size: 15px;
                margin: 0;
                line-height: 1.5;
            }    /* Buttons for Active cards */
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
            /* Activation AppID panel styles */
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

            /* Toggle switch styles */
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
            /* Modal styles */
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

            /* Base card - left menu */
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

            .menu-item.locked {
                opacity: 0.5;
                cursor: not-allowed;
                pointer-events: none;
            }

            /* Top card - header and content area */
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

            /* Card header */
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

            /* QR code area */
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

            /* Footer note */
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

            /* Content panel */
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

            /* Responsive adjustments */
            @media (max-width: 950px) {
                .modal-container {
                    width: 95%;
                    height: 90%;
                }
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Store polling variables on window to ensure global uniqueness
                if (typeof window.wonderPaymentsPollInterval === 'undefined') {
                    window.wonderPaymentsPollInterval = null;
                }
                if (typeof window.wonderPaymentsCurrentUuid === 'undefined') {
                    window.wonderPaymentsCurrentUuid = null;
                }

                var isMenuLocked = false;
                function setMenuLock(locked) {
                    isMenuLocked = locked;
                    var $lockedItems = $('.menu-item[data-tab="scan"], .menu-item[data-tab="business"]');
                    $lockedItems.toggleClass('locked', locked);
                    $lockedItems.attr('aria-disabled', locked ? 'true' : 'false');
                }

                // Check login state on page load and jump to the correct view
                var accessToken = localStorage.getItem('wonder_access_token');
                var businessId = localStorage.getItem('wonder_business_id');
                var selectedBusinessId = localStorage.getItem('wonder_selected_business_id');

                console.log('Page load - Access Token:', accessToken ? 'exists' : 'not exists');
                console.log('Page load - Business ID:', businessId);
                console.log('Page load - Selected Business ID:', selectedBusinessId);

                // Clear any existing poller first
                if (window.wonderPaymentsPollInterval) {
                    console.log('Clearing existing poll interval on page load:', window.wonderPaymentsPollInterval);
                    clearInterval(window.wonderPaymentsPollInterval);
                    window.wonderPaymentsPollInterval = null;
                }

                // Hide all panels first to avoid flicker
                $('.content-panel').removeClass('active');
                $('.menu-item').removeClass('active');
                setMenuLock(false);

                // Show the correct panel based on state
                if (accessToken && businessId) {
                    // Already logged in
                    console.log('User is already logged in');

                    // Show Logout button
                    $('#logout-section').show();
                    $('.qr-code-section').hide();
                    $('.qr-loading').html('<div class="scan-success">✓ Scanned Successfully</div>').show();

                    // Check if a business is selected
                    if (selectedBusinessId && selectedBusinessId !== '' && selectedBusinessId !== 'null' && selectedBusinessId !== 'undefined') {
                        // Business selected, show Activation AppID panel directly
                        console.log('Business already selected, showing activation page directly');
                        $('.menu-item[data-tab="activation"]').addClass('active');
                        $('#panel-activation').addClass('active');
                        loadActivationPage();
                    } else {
                        // No business selected, show Choose Business panel directly
                        console.log('Business not selected, showing business selection page directly');
                        $('.menu-item[data-tab="business"]').addClass('active');
                        $('#panel-business').addClass('active');
                        loadBusinessList();
                    }
                } else {
                    // Not logged in, show scan panel
                    console.log('User is not logged in, showing scan page');
                    $('.menu-item[data-tab="scan"]').addClass('active');
                    $('#panel-scan').addClass('active');
                    generateQRCode();
                }

                // Refresh button click handler
                $('#refresh-qr-btn').on('click', function() {
                    console.log('Refresh QR Code clicked');
                    generateQRCode();
                });

                // Logout button click handler
                $('#logout-btn').on('click', function() {
                    console.log('Logout button clicked');

                    // Clear login info from localStorage
                    localStorage.removeItem('wonder_access_token');
                    localStorage.removeItem('wonder_business_id');
                    localStorage.removeItem('wonder_selected_business_id');
                    localStorage.removeItem('wonder_selected_business_name');

                    console.log('Logged out successfully');



                    // Regenerate QR code

                    generateQRCode();



                    // Hide logout section, show QR section

                    $('#logout-section').hide();

                    $('.qr-code-section').show();

                });

                // Recreate button click handler
                $('#recreate-btn').on('click', function() {
                    console.log('Recreate button clicked');

                    // Clear all localStorage data
                    localStorage.removeItem('wonder_access_token');
                    localStorage.removeItem('wonder_business_id');
                    localStorage.removeItem('wonder_selected_business_id');
                    localStorage.removeItem('wonder_selected_business_name');

                    // Clear other possible state data
                    localStorage.removeItem('wonder_skip_business_list_load');
                    localStorage.removeItem('wonder_app_id');
                    localStorage.removeItem('wonder_private_key');
                    localStorage.removeItem('wonder_public_key');
                    localStorage.removeItem('wonder_webhook_key');

                    console.log('All localStorage data cleared');
                    setMenuLock(false);

                    // Call backend to clear all data (including Settings)
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_clear_all',
                            security: '<?php echo esc_attr( wp_create_nonce( "wonder_payments_modal_nonce" ) ); ?>'
                        },
                        success: function(response) {
                            console.log('All data cleared:', response);

                            // Clear business page DOM state
                            $('.business-card').removeClass('selected connected');
                            $('.choose-btn').removeClass('selected-btn connected-btn');

                            // Jump to the first page (Scan QR code)
                            $('.menu-item[data-tab="scan"]').click();
                        },
                        error: function(xhr, status, error) {
                            console.error('Failed to clear all data:', error);
                        }
                    });
                });

                // Create button click handler
                $('#create-app-id-btn').on('click', function() {
                    console.log('Create button clicked');

                    var businessId = localStorage.getItem('wonder_selected_business_id');
                    var businessName = localStorage.getItem('wonder_selected_business_name');

                    if (!businessId) {
                        $('.menu-item[data-tab="business"]').click();
                        return;
                    }

                    // Generate app_id
                    generateAppIdOnly(businessId, businessName);
                });

// Listen for stop-polling message from parent window
                window.addEventListener('message', function(event) {
                    if (event.data && event.data.action === 'stopPolling') {
                        console.log('Received stop polling message');
                        if (window.wonderPaymentsPollInterval) {
                            clearInterval(window.wonderPaymentsPollInterval);
                            window.wonderPaymentsPollInterval = null;
                        }
                    }
                });

// Left menu click handler
                $('.menu-item').on('click', function() {
                    var $this = $(this);
                    var tabId = $this.data('tab');

                    console.log('Menu item clicked:', tabId);
                    if (isMenuLocked && (tabId === 'scan' || tabId === 'business')) {
                        console.log('Menu locked, ignoring click for:', tabId);
                        return;
                    }

                    // Clear polling timer (if any)
                    if (window.wonderPaymentsPollInterval) {
                        console.log('Clearing poll interval');
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                    }

                    // Remove all active states
                    $('.menu-item').removeClass('active');
                    $('.content-panel').removeClass('active');

                    // Activate current item
                    $this.addClass('active');
                    $('#panel-' + tabId).addClass('active');

                    // If on scan page, check login state
                    if (tabId === 'scan') {
                        var accessToken = localStorage.getItem('wonder_access_token');
                        var businessId = localStorage.getItem('wonder_business_id');

                        if (accessToken && businessId) {
                            // Already logged in,Show Logout button
                            console.log('User is logged in, showing logout button');
                            $('#logout-section').show();
                            $('.qr-code-section').hide();
                            $('.qr-loading').html('<div class="scan-success">✓ Scanned Successfully</div>').show();
                        } else {
                            // Not logged in, generate QR code
                            console.log('User is not logged in, generating QR code');
                            generateQRCode();
                        }
                    }

                    // If on business page, load business list
                    if (tabId === 'business') {
                        console.log('Business tab clicked, loading business list');
                        loadBusinessList();
                    }

                    // If on Activation AppID page, generate key pair
                    if (tabId === 'activation') {
                        loadActivationPage();
                    }

                    // If on Settings page, load settings
                    if (tabId === 'settings') {
                        loadSettings();
                    }
                });

                // Poll for QR scan status
                function startPolling(uuid) {
                    console.log('startPolling called with UUID:', uuid);

                    // Clear previous poller
                    if (window.wonderPaymentsPollInterval) {
                        console.log('Clearing previous poll interval:', window.wonderPaymentsPollInterval);
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                    }

                    // Save current UUID
                    window.wonderPaymentsCurrentUuid = uuid;

                    // Poll every 2 seconds
                    console.log('Starting new poll interval for UUID:', uuid);
                    window.wonderPaymentsPollInterval = setInterval(function() {
                        console.log('Polling UUID:', window.wonderPaymentsCurrentUuid);
                        // Use SDK to query QR status
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

                                    // Scanned with access_token indicates login success
                                    if (data.is_scan && data.access_token) {
                                        console.log('QR Code Scanned Successfully! Access Token:', data.access_token);
                                        console.log('Business ID:', data.business_id);

                                        // Save business_id and access_token to localStorage
                                        if (data.business_id) {
                                            localStorage.setItem('wonder_business_id', data.business_id);
                                            console.log('Business ID saved to localStorage');
                                        }

                                        if (data.access_token) {
                                            localStorage.setItem('wonder_access_token', data.access_token);
                                            console.log('Access Token saved to localStorage');
                                        }

                                        // Save to backend
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

                                                // If business_id is empty, get it from the business list
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

                                        // Stop polling
                                        if (window.wonderPaymentsPollInterval) {
                                            clearInterval(window.wonderPaymentsPollInterval);
                                            window.wonderPaymentsPollInterval = null;
                                        }

// Show scan success message
                                        showScanSuccess();

                                        // Auto-jump to business selection after 2 seconds
                                        setTimeout(function() {
                                            console.log('Redirecting to business selection page');
                                            // Click the "Choose business connect this shop" menu item
                                            $('.menu-item[data-tab="business"]').click();
                                        }, 2000);
                                    }

                                    // QR code expired
                                    if (data.is_expired) {
                                        console.log('QR Code expired');
                                        if (window.wonderPaymentsPollInterval) {
                                            clearInterval(window.wonderPaymentsPollInterval);
                                            window.wonderPaymentsPollInterval = null;
                                        }
                                    }

                                    // User cancelled
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

                // Show scan success message
                function showScanSuccess() {
                    var $loading = $('.qr-loading');
                    var $img = $('.qr-code-placeholder img');

                    // Hide QR image
                    $img.hide();

                    // Show success message
                    $loading.html('<div class="scan-success">✓ Scanned Successfully</div>').show();

                    // Show Logout button
                    $('#logout-section').show();
                }

                // Load business list

                // Check existing connection state
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

                        // Show hint message

                        $('.cards-container').html('<div class="no-business">Please scan QR code to login first</div>');

                        return;

                    }



                    // Temporarily show loading state

                    $('.cards-container').html('<div class="loading-business">Loading business list...</div>');



                    // Fetch business list first

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



                            // Backend returns nested structure: {data: {data: [businesses]}}

                            // Extract response.data.data

                            var businessList = response.data && response.data.data ? response.data.data : [];



                            console.log('Business list:', businessList);

                            console.log('Business list type:', typeof businessList);

                            console.log('Business list is array:', Array.isArray(businessList));

                            console.log('Business list length:', businessList.length);



                            if (response.success && businessList && Array.isArray(businessList) && businessList.length > 0) {

                                // Check connection status before rendering the business list

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



                                    // Render business list with connection state (once)

                                    console.log('Rendering business list with connection status...');

                                    renderBusinessList(businessList, existingConnection);

                                }).fail(function(xhr, status, error) {

                                    console.error('Check connection error:', error);

                                    // Render list even if check fails (no connection state)

                                    console.log('Rendering business list without connection status...');

                                    renderBusinessList(businessList, null);

                                });

                            } else if (!response.success && response.data && response.data.message) {

                                if (response.data.message.indexOf('Access token expired') !== -1) {
                                    localStorage.removeItem('wonder_access_token');
                                    localStorage.removeItem('wonder_business_id');
                                }

                                $('.cards-container').html('<div class="no-business">' + response.data.message + '</div>');
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



                // Render business list
                function renderBusinessList(businesses, existingConnection) {
                    console.log('Rendering business list:', businesses);
                    console.log('Existing connection:', existingConnection);

                    // Prefer selected business ID from localStorage
                    var selectedBusinessId = localStorage.getItem('wonder_selected_business_id');
                    console.log('Selected business ID from localStorage:', selectedBusinessId);

                    // Get connected business ID from backend
                    var connectedBusinessId = existingConnection && existingConnection.business_id ? existingConnection.business_id : null;
                    var connectedAppId = existingConnection && existingConnection.app_id ? existingConnection.app_id : null;

                    console.log('Connected business ID from backend:', connectedBusinessId);
                    console.log('Connected app ID:', connectedAppId);

                    // Use localStorage value as selected business ID
                    var finalBusinessId = selectedBusinessId || connectedBusinessId;
                    console.log('Final business ID to use:', finalBusinessId);

                    var html = '';

                    businesses.forEach(function(business, index) {
                        var status = business.status || 'inactive';
                        var statusText = status.toUpperCase();

                        // Use active class only for Active status; others use inactive
                        var statusClass = (status === 'Active') ? 'active' : 'inactive';

                        // Check if the business is connected - use finalBusinessId
                        var isConnected = (finalBusinessId === business.id);
                        var connectedClass = isConnected ? 'connected' : '';

                        var businessName = business.business_name || business.business_dba || 'Business Name';
                        var businessId = business.business_id || '';

                        var buttonHtml = '';
                        if (status === 'Active') {
                            if (isConnected) {
                                // Connected: show green background and "Connected"
                                buttonHtml = '<div class="connection-status connected">Connected</div>' +
                                    (connectedAppId ? '<div class="app-id-display">App ID: ' + connectedAppId + '</div>' : '');
                            } else {
                                // Not connected: show "Choose" button
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

                    // Refresh page
                    $('.cards-container').html(html);

                    // Bind choose button click handler
                    console.log('Binding click events to choose buttons...');
                    console.log('Found choose buttons:', $('.choose-btn:not(.connected-btn)').length);

                    $('.choose-btn:not(.connected-btn)').on('click', function() {
                        var businessId = $(this).data('business-id');
                        var businessName = $(this).data('business-name');

                        console.log('Choose button clicked!');
                        console.log('Choose business:', businessId, businessName);

                        // Check if already connected - use finalBusinessId
                        if (finalBusinessId) {
                            // Already connected; ask to switch
                            var currentBusinessName = existingConnection && existingConnection.business_name ? existingConnection.business_name : 'Unknown';
                            var switchMessage = 'You are already connected to a business.\n\n' +
                                'Do you want to switch to this new business?\n\n' +
                                'New: ' + businessName + '\n\n' +
                                'Note: This will generate a new App ID and replace the old one.';

                            // User confirms switch
                            localStorage.setItem('wonder_selected_business_id', businessId);
                            localStorage.setItem('wonder_selected_business_name', businessName);

                            // Call backend to save selected business ID
                            saveSelectedBusiness(businessId, businessName);
                        } else {
                            // No connection; confirm directly
                            // User confirms; save selected business info
                            localStorage.setItem('wonder_selected_business_id', businessId);
                            localStorage.setItem('wonder_selected_business_name', businessName);

                            // Call backend to save selected business ID
                            saveSelectedBusiness(businessId, businessName);
                        }
                    });
                }

                // Save selected business ID
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
                                // Reload business list to refresh state
                                loadBusinessList();
                                // Go to Activation AppID page
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

                // Call backend to generate key pair and get app_id
                function generateKeyPairAndAppId(businessId, businessName) {
                    console.log('Generating key pair and app_id for business:', businessId);

                    // Show loading
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
                                // Try extracting app_id from multiple locations
                                var appId = response.app_id ||
                                    (response.data && response.data.app_id) ||
                                    (response.data && response.data.data && response.data.data.app_id) || '';

                                console.log('Extracted App ID:', appId);

                                if (appId) {

                                    console.log('About to call loadBusinessList()...');
                                    console.log('loadBusinessList function exists:', typeof loadBusinessList === 'function');

                                    // Reload business list to refresh state
                                    loadBusinessList();

                                    console.log('loadBusinessList() called');

                                    // Go to Settings page
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

                // Load Activation page and generate key pair
                function loadActivationPage() {
                    console.log('Loading Activation page');

                    // Check if a business is selected
                    var businessId = localStorage.getItem('wonder_selected_business_id');
                    var businessName = localStorage.getItem('wonder_selected_business_name');

                    if (!businessId) {
                        $('.menu-item[data-tab="business"]').click();
                        return;
                    }

                    // Prefer displaying saved config before generating key pair
                    loadActivationSettings(businessId);
                }

                function loadActivationSettings(businessId) {
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'wonder_payments_load_settings',
                            security: "<?php echo esc_attr( wp_create_nonce( 'wonder_payments_modal_nonce' ) ); ?>"
                        },
                        success: function(response) {
                            var settingsData = response.data && response.data.data ? response.data.data : response.data;
                            settingsData = settingsData || {};

                            var savedAppId = settingsData.app_id || '';
                            var savedPrivateKey = settingsData.private_key || '';
                            var savedPublicKey = settingsData.generated_public_key || '';
                            var savedWebhookKey = settingsData.webhook_public_key || '';

                            if (savedAppId) {
                                $('#app-id-input').val(savedAppId);
                                $('#create-app-id-btn').text('Created').prop('disabled', true);
                                setMenuLock(true);
                                $('#webhook-key-input').val(savedWebhookKey);
                                $('#public-key-input').val(savedPublicKey);
                                $('#private-key-input').val(savedPrivateKey);
                                return;
                            }

                            // Only generate key pair when no saved AppID
                            generateKeyPairOnly(businessId);
                        },
                        error: function() {
                            generateKeyPairOnly(businessId);
                        }
                    });
                }

                // Generate key pair only, do not generate app_id
                function generateKeyPairOnly(businessId) {
                    console.log('Generating key pair only for business:', businessId);

                    // Clear app_id input and reset button state first
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
                                // Handle nested data structure
                                var keyData = response.data.data || response.data || {};

                                console.log('Setting public key to input...');
                                console.log('Public key length:', keyData.public_key ? keyData.public_key.length : 0);
                                console.log('Private key length:', keyData.private_key ? keyData.private_key.length : 0);
                                console.log('Webhook key length:', keyData.webhook_key ? keyData.webhook_key.length : 0);
                                console.log('Saved App ID:', keyData.app_id);

                                // Check if textareas exist
                                console.log('Public key input exists:', $('#public-key-input').length);
                                console.log('Private key input exists:', $('#private-key-input').length);
                                console.log('Webhook key input exists:', $('#webhook-key-input').length);
                                console.log('App ID input exists:', $('#app-id-input').length);

                                // Display key pair
                                var publicKey = keyData.public_key || '';
                                var privateKey = keyData.private_key || '';
                                var webhookKey = keyData.webhook_key || '';
                                var savedAppId = keyData.app_id || '';



                                // Check public/private key prefixes to display correctly

                                console.log('publicKey prefix:', publicKey.substring(0, 30));

                                console.log('privateKey prefix:', privateKey.substring(0, 30));



                                $('#public-key-input').val(publicKey);
                                $('#private-key-input').val(privateKey);
                                $('#webhook-key-input').val(savedAppId ? webhookKey : '');

                                // Set button state based on app_id generation
                                if (savedAppId) {
                                    $('#app-id-input').val(savedAppId);
                                    $('#create-app-id-btn').text('Created').prop('disabled', true);
                                    setMenuLock(true);
                                } else {
                                    $('#create-app-id-btn').text('Create').prop('disabled', false);
                                    setMenuLock(false);
                                }
                            } else {
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Generate key pair error:', error);
                        }
                    });
                }
                // Generate app_id only, using saved public key
                function generateAppIdOnly(businessId, businessName) {
                    console.log('Generating app_id only for business:', businessId);

                    // Show loading
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
                                // Try extracting app_id from multiple locations
                                var appId = response.app_id ||
                                    (response.data && response.data.app_id) ||
                                    (response.data && response.data.data && response.data.data.app_id) || '';

                                // Try extracting webhook_key from multiple locations
                                var webhookKey = response.webhook_private_key ||
                                    (response.data && response.data.webhook_private_key) ||
                                    (response.data && response.data.data && response.data.data.webhook_private_key) ||
                                    response.webhook_public_key ||
                                    (response.data && response.data.webhook_public_key) ||
                                    (response.data && response.data.data && response.data.data.webhook_public_key) || '';

                                console.log('Extracted App ID:', appId);
                                console.log('Extracted Webhook Key:', webhookKey);

                                if (appId) {
                                    $('#app-id-input').val(appId);
                                    $('#create-app-id-btn').text('Created').prop('disabled', true);
                                    setMenuLock(true);

                                    // Fill webhook_key
                                    if (webhookKey) {
                                        $('#webhook-key-input').val(webhookKey);
                                    }

                                    // Go to Settings page
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

// Load Settings page
                function loadSettings() {
                    console.log('Loading Settings page');

                    // Ensure DOM is fully loaded before running
                    setTimeout(function() {
                        // Load settings from WordPress options
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

                                    // Check if response.data.data needs to be accessed
                                    var settingsData = response.data.data || response.data;
                                    console.log('Final settings data:', settingsData);
                                    console.log('Final settings data type:', typeof settingsData);

                                    console.log('Setting title to:', settingsData.title);
                                    console.log('Setting description to:', settingsData.description);
                                    console.log('Setting sandbox to:', settingsData.sandbox_mode);

                                    // Check if elements exist
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

                                    // Load due_date
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

                // Save Settings
                function saveSettings() {
                    console.log('Saving settings');

                    var settings = {
                        title: $('#settings-title').val(),
                        description: $('#settings-description').val(),
                        sandbox_mode: $('#settings-sandbox').attr('data-enabled') === 'true' ? '1' : '0',
                        due_date: $('#settings-due-date').val(),
                        app_id: $('#app-id-input').val(),
                        private_key: $('#private-key-input').val(),
                        generated_public_key: $('#public-key-input').val(),
                        webhook_public_key: $('#webhook-key-input').val()
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
                                // Close modal
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

                // Save button click handler
                $('#save-settings-btn').on('click', function() {
                    saveSettings();
                });

                // Toggle switch click handler
                $('#settings-sandbox').on('click', function() {
                    var currentStatus = $(this).attr('data-enabled');
                    var newStatus = currentStatus === 'true' ? 'false' : 'true';
                    $(this).attr('data-enabled', newStatus);
                    console.log('Sandbox mode toggled to:', newStatus);
                });

                // Generate QR code
                function generateQRCode() {
                    console.log('generateQRCode called');

                    // Clear old poller (if any)
                    if (window.wonderPaymentsPollInterval) {
                        console.log('Clearing existing poll interval before generating new QR code:', window.wonderPaymentsPollInterval);
                        clearInterval(window.wonderPaymentsPollInterval);
                        window.wonderPaymentsPollInterval = null;
                    }

                    // Hide Logout button
                    $('#logout-section').hide();

                    // Show QR section
                    $('.qr-code-section').show();

                    // Use SDK to create QR code
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

                                // Start polling for scan status
                                startPolling(uuid);

                                // Show QR image
                                var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' + encodeURIComponent(sUrl);

                                var $img = $('.qr-code-placeholder img');
                                var $loading = $('.qr-loading');

                                // Hide loading and show image
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

                // Generate UUID
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
        // Do not generate placeholder; wait for JavaScript to create the QR code
        echo '<div class="qrcode-image" style="margin-left: -10px;">';
        echo '<img src="" alt="' . esc_attr__('Loading QR code...', 'wonder-payments') . '" style="display: none;">';
        echo '<div class="qr-loading">Loading QR code...</div>';
        echo '</div>';
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wonder-payments') === false) {
            return;
        }

        // Load styles
        wp_enqueue_style(
                'wonder-payments-admin',
                plugin_dir_url(__FILE__) . 'assets/css/admin.css',
                array(),
                '1.0.0'
        );

        // Load scripts
        wp_enqueue_script(
                'wonder-payments-admin',
                plugin_dir_url(__FILE__) . 'assets/js/wonder_payments_admin.js',
                array('jquery'),
                '1.0.0',
                true
        );

        // Localize script
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

            // Clean up options
            delete_option('wonder_payments_app_id');
            delete_option('wonder_payments_business_name');
            delete_option('wonder_payments_connected');

            wp_safe_redirect(admin_url('admin.php?page=wonder-payments-setup'));
            exit;
        }
    }
}

new Wonder_Payments_Admin();
