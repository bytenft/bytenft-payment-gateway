<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BYTENFT_Blocks_Gateway extends AbstractPaymentMethodType {
    protected $name = 'bytenft';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
    }

	public function is_active() {
        return (
            isset( $this->settings['enabled'] ) &&
            $this->settings['enabled'] === 'yes'
        );
    }

    // In class
	public function get_payment_method_script_handles() {
	   	wp_register_script(
			'bytenft-blocks-js',
			plugin_dir_url( BYTENFT_PAYMENT_GATEWAY_FILE ) . 'assets/js/bytenft-blocks.js',
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
			'1.0.0',
			true
		);
	    	return [ 'bytenft-blocks-js' ]; // match your registered handle
	}

  	public function get_payment_method_data() {
         $title       = $this->settings['title'] ?? 'ByteNFT';
        $description = $this->settings['description'] ?? '';

        if (WC()->cart) {
            $amount   = (float) WC()->cart->get_total('edit');
            if ($amount < 0.01) {
                $totals = WC()->cart->get_totals();
                $amount = (float) ($totals['total'] ?? 0);
            }
            $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
            $gateway  = $gateways['bytenft'] ?? null;
            if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
                $info = $gateway->get_checkout_info_for_amount($amount);
                if (!empty($info['title']))    $title       = $info['title'];
                if (!empty($info['subtitle'])) $description = $info['subtitle'];
            }
        }
        return [
            'id'          => $this->name,
            'title'       => $title,
            'description' => $description,
            'supports'    => ['products'],
            'isActive'    => $this->is_active(), // ✅ camelCase, boolean

            // Optional extra data
            'sandbox'     => $this->settings['sandbox'] ?? '',
            'order_status'=> $this->settings['order_status'] ?? '',
            'instructions'=> $this->settings['instructions'] ?? '',
            'accounts'    => $this->settings['accounts'] ?? '',
            
        ];
    }
}