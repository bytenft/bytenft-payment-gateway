<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BYTENFT_Blocks_Gateway extends AbstractPaymentMethodType
{
    protected $name = 'bytenft';
    protected $id = 'bytenft';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active()
    {
        return (
            isset($this->settings['enabled']) &&
            $this->settings['enabled'] === 'yes'
        );
    }

    // In class
    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'bytenft-blocks-js',
            plugin_dir_url(BYTENFT_PAYMENT_GATEWAY_FILE) . 'assets/js/bytenft-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element'],
            '1.0.0',
            true
        );
        return ['bytenft-blocks-js']; // match your registered handle
    }

    public function get_payment_method_data()
    {

        // 🔥 Call your real logic
        $can_pay = function_exists('bytenft_can_make_payment_safe')
            ? bytenft_can_make_payment_safe()
            : true;

        error_log('[ByteNFT Blocks] FINAL can_pay = ' . json_encode($can_pay));

        return [
            'id'          => $this->name,
            'title'       => $this->settings['title'] ?? 'ByteNFT',
            'description' => $this->settings['description'] ?? '',
            'supports'    => ['products'],

            // 🔥 IMPORTANT
            'can_pay'     => (bool) $can_pay,

            // REQUIRED
            'isActive'    => $this->is_active(),

            // 🔥 break cache (optional but useful)
            'refresh_key' => time(),

            // other data
            'sandbox'     => $this->settings['sandbox'] ?? '',
            'order_status' => $this->settings['order_status'] ?? '',
            'instructions' => $this->settings['instructions'] ?? '',
            'accounts'    => $this->settings['accounts'] ?? '',
        ];
    }
}
