<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BYTENFT_PAYMENT_GATEWAY_BLOCKS_SUPPORT extends AbstractPaymentMethodType
{

	protected $name = 'bytenft';

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

	public function get_payment_method_script_handles()
	{

		wp_register_script(
			'bytenft-blocks',
			plugins_url('../assets/js/bytenft-blocks.js', __FILE__),
			['wc-blocks-registry', 'wp-element', 'wp-html-entities','wc-settings'],
			'1.0.0',
			true
		);

		return ['bytenft-blocks'];
	}

	public function get_payment_method_data()
	{

		// ✅ reuse your existing gateway logic
		$can_pay = function_exists('bytenft_can_make_payment_safe')
			? bytenft_can_make_payment_safe()
			: false;
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
        ];
	}
}
