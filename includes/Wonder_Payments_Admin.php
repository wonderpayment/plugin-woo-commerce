<?php

if (!defined('ABSPATH')) {
    exit;
}

class Wonder_Payments_Admin {

    public function __construct() {
        // Add admin menu - commented because settings are accessed via the modal
        // add_action('admin_menu', array($this, 'add_admin_menu'));

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

                    <div id="logout-section" class="wonder-logout-section">
                        <p class="wonder-logout-success">
                            ✓ Successfully logged in!
                        </p>
                        <button id="logout-btn" class="btn btn-secondary wonder-logout-button">
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
        <?php
    }

    private function generate_qrcode() {
        // Do not generate placeholder; wait for JavaScript to create the QR code
        echo '<div class="qrcode-image wonder-qrcode-image">';
        echo '<img src="" alt="' . esc_attr__('Loading QR code...', 'wonder-payment-for-woocommerce') . '" class="wonder-qrcode-image__img">';
        echo '<div class="qr-loading">Loading QR code...</div>';
        echo '</div>';
    }

    public function enqueue_admin_assets($hook) {
        if (function_exists('wonder_payments_admin_scripts')) {
            wonder_payments_admin_scripts($hook);
        }
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
