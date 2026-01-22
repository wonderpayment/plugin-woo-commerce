<?php
/**
 * WooCommerce Blocks Support for Wonder Payments
 *
 * This file provides support for WooCommerce Blocks checkout.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Wonder_Payments_Blocks_Support extends AbstractPaymentMethodType {
    private function log($message, $context = array()) {
        if (!defined('WONDER_PAYMENTS_BLOCKS_LOG_FILE')) {
            return;
        }
        $line = '[Wonder Payments][Blocks] ' . $message . PHP_EOL;
    }
    /**
     * Payment method name.
     *
     * @var string
     */
    protected $name = 'wonder_payments';

    /**
     * Initialize settings.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_wonder_payments_settings', array());
        $this->log('initialize: settings loaded');
    }

    /**
     * Check if payment method is active for Blocks checkout.
     *
     * @return bool
     */
    public function is_active() {
        $enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : 'no';
        $app_id = isset($this->settings['app_id']) ? $this->settings['app_id'] : '';
        $private_key = isset($this->settings['private_key']) ? $this->settings['private_key'] : '';

        $active = $enabled === 'yes' && !empty($app_id) && !empty($private_key);
        $this->log(
            sprintf(
                'is_active: enabled=%s app_id=%s private_key=%s result=%s',
                $enabled,
                $app_id ? 'set' : 'empty',
                $private_key ? 'set' : 'empty',
                $active ? 'true' : 'false'
            )
        );

        return $active;
    }

    /**
     * Register payment method script.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $handle = 'wonder-payments-blocks';

        wp_register_script(
            $handle,
            plugins_url('assets/js/wonder-payments-blocks.js', WONDER_PAYMENTS_PLUGIN_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            '1.0.0',
            true
        );

        $this->log('script registered: ' . $handle);

        return array($handle);
    }

    /**
     * Data exposed to the frontend Blocks payment method.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $title = isset($this->settings['title']) && $this->settings['title']
            ? $this->settings['title']
            : __('Wonder Payments', 'wonder-payments');
        $description = isset($this->settings['description']) && $this->settings['description']
            ? $this->settings['description']
            : __('Pay securely via Wonder Payments', 'wonder-payments');

        $data = array(
            'title' => $title,
            'description' => $description,
            'supports' => array('products', 'refunds'),
            'is_active' => $this->is_active(),
        );
        $this->log('data: ' . wp_json_encode($data));
        return $data;
    }
}
