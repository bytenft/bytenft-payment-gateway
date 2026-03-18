<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BYTENFT_Blocks_Gateway extends AbstractPaymentMethodType {
    protected $name = 'bytenft';
	protected $id = 'bytenft';

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
        return [
            'id'          => $this->name, // e.g. 'bytenft'
            'title'       => $this->settings['title'] ?? 'ByteNFT',
            'description' => $this->settings['description'] ?? '',
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