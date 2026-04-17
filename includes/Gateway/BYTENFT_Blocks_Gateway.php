<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) exit;

class BYTENFT_Blocks_Gateway extends AbstractPaymentMethodType
{
    protected $name = 'bytenft';

    protected $settings = [];

    public function initialize()
    {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active()
    {
        return ($this->settings['enabled'] ?? '') === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'bytenft-blocks-js',
            plugin_dir_url(BYTENFT_PAYMENT_GATEWAY_FILE) . 'assets/js/bytenft-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element'],
            '1.0.0',
            true
        );

        return ['bytenft-blocks-js'];
    }

    public function get_payment_method_data()
    {
        $title       = $this->settings['title'] ?? 'ByteNFT';
        $description = $this->settings['description'] ?? '';

        return [
            'id'           => $this->name,
            'title'        => $title,
            'description'  => $description,
            'supports'     => ['products'],
            'isActive'     => $this->is_active(),
            'sandbox'      => $this->settings['sandbox'] ?? '',
            'order_status' => $this->settings['order_status'] ?? '',
        ];
    }
}