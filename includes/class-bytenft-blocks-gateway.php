<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BYTENFT_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = 'bytenft';
	protected $id   = 'bytenft';

	public function initialize() {
		$this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
	}

	public function is_active() {
		return (
			isset($this->settings['enabled']) &&
			$this->settings['enabled'] === 'yes'
		);
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'bytenft-blocks-js',
			plugin_dir_url(BYTENFT_PAYMENT_GATEWAY_FILE) . 'assets/js/bytenft-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element'],
			'1.0.0',
			true
		);
		return ['bytenft-blocks-js'];
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
			'isActive'    => $this->is_active(),
			'sandbox'     => $this->settings['sandbox'] ?? '',
			'order_status'=> $this->settings['order_status'] ?? '',
			'instructions'=> $this->settings['instructions'] ?? '',
			'accounts'    => $this->settings['accounts'] ?? '',
		];
	}
}


/**
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX handler for Block Checkout payment processing.
 *
 * KEY FIX: Instead of `new BYTENFT_PAYMENT_GATEWAY()` (which creates a fresh,
 * partially-initialised instance), we pull the already-booted gateway instance
 * from WooCommerce's payment gateway registry.  That instance has had
 * init_settings() called by WooCommerce during the normal boot cycle, so
 * $this->sandbox, $this->enabled, and all get_option() values are correctly
 * populated when process_payment() runs.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function bytenft_register_block_ajax_handlers() {
	add_action('wp_ajax_bytenft_block_gateway_process',        'handle_bytenft_gateway_ajax');
	add_action('wp_ajax_nopriv_bytenft_block_gateway_process', 'handle_bytenft_gateway_ajax');
}
add_action('init', 'bytenft_register_block_ajax_handlers');

function handle_bytenft_gateway_ajax() {
	// ── Nonce verification ────────────────────────────────────────────────────
	$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
	if (empty($nonce) || !wp_verify_nonce($nonce, 'bytenft_payment')) {
		wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
		die;
	}

	// ── Get the already-initialised gateway instance from WooCommerce ─────────
	// This is the critical fix. WC()->payment_gateways()->payment_gateways()
	// returns instances that have already been through init_settings(), so
	// sandbox mode, enabled state, and all options are correctly loaded.
	$gateways       = WC()->payment_gateways()->payment_gateways();
	$bytenftPayment = $gateways['bytenft'] ?? null;

	if (!$bytenftPayment) {
		// Fallback: if for any reason the registry doesn't have it yet,
		// instantiate manually and force-reload settings from the DB.
		$bytenftPayment = new BYTENFT_PAYMENT_GATEWAY();
		$bytenftPayment->init_settings();
		$bytenftPayment->load_gateway_settings();

		wc_get_logger()->warning(
			'ByteNFT: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
			['source' => 'bytenft-payment-gateway']
		);
	}

	// ── Resolve the draft order ID from the block checkout session ────────────
	$orderID = WC()->session ? WC()->session->get('store_api_draft_order') : null;

	$status = [];

	if ($orderID) {
		$status = $bytenftPayment->process_payment($orderID);
	} else {
		wc_add_notice(__('Invalid order.', 'bytenft-payment-gateway'), 'error');
		$status = ['result' => 'fail', 'error' => 'Invalid order.'];
	}

	wp_send_json($status);
	die;
}
