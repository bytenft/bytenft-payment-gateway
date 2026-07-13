<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

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

		error_log('ByteNFT Blocks Data: ' . print_r($data, true));
	}
}


/**
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX handler for Block Checkout payment processing.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function bytenft_register_block_ajax_handlers() {
	add_action('wp_ajax_bytenft_block_gateway_process',        'handle_bytenft_gateway_ajax');
	add_action('wp_ajax_nopriv_bytenft_block_gateway_process', 'handle_bytenft_gateway_ajax');
}
add_action('init', 'bytenft_register_block_ajax_handlers');

function handle_bytenft_gateway_ajax() {

	// ─────────────────────────────────────────────
	// CONTEXT + LOG PREFIX (ADDED FOR DEBUGGING)
	// ─────────────────────────────────────────────
	$orderID = WC()->session ? WC()->session->get('store_api_draft_order') : null;

	$log_prefix = "[Order #{$orderID}]";
	$log_ctx    = ['order_id' => $orderID];

	// ─────────────────────────────────────────────
	// NONCE CHECK (WITH LOGGING)
	// ─────────────────────────────────────────────
	$nonce = isset($_POST['nonce'])
		? sanitize_text_field(wp_unslash($_POST['nonce']))
		: '';

	if (empty($nonce) || !wp_verify_nonce($nonce, 'bytenft_payment')) {


		ByteNFT_Payment_Gateway_Logger::info($log_prefix . ' AJAX | Invalid nonce');

		wp_send_json([
			'success' => false,
			'message' => 'Security check failed.',
			'data'    => [
				'reload'   => true,
				'order_id' => $orderID
			]
		]);

		die;
	}

	// ─────────────────────────────────────────────
	// GET GATEWAY INSTANCE (UNCHANGED LOGIC)
	// ─────────────────────────────────────────────
	$gateways       = WC()->payment_gateways()->payment_gateways();
	$bytenftPayment = $gateways['bytenft'] ?? null;

	if (!$bytenftPayment) {

		$bytenftPayment = new BYTENFT_PAYMENT_GATEWAY();
		$bytenftPayment->init_settings();
		$bytenftPayment->load_gateway_settings();

		ByteNFT_Payment_Gateway_Logger::warning(
			$log_prefix . ' AJAX | Gateway fallback used (not found in registry)',
			['event' => 'gateway_fallback']
		);
	}

	// ─────────────────────────────────────────────
	// ORDER VALIDATION LOG
	// ─────────────────────────────────────────────
	if (!$orderID) {

		ByteNFT_Payment_Gateway_Logger::error(
			$log_prefix . ' AJAX | Missing order ID from session',
		);

		wp_send_json([
			'success' => false,
			'message' => 'Invalid order.',
			'data'    => [
				'order_id' => null
			]
		]);

		die;
	}

	// ─────────────────────────────────────────────
	// PAYMENT PROCESS FLOW (UNCHANGED LOGIC)
	// ─────────────────────────────────────────────
	$status = $bytenftPayment->process_payment($orderID);

	// ─────────────────────────────────────────────
	// LOG PROCESS RESULT
	// ─────────────────────────────────────────────
	
	ByteNFT_Payment_Gateway_Logger::info(
		$log_prefix . ' AJAX | process_payment executed',
		['status' => $status]
	);

	// ─────────────────────────────────────────────
	// NORMALIZE RESPONSE
	// ─────────────────────────────────────────────
	$is_success = false;

	if (is_array($status)) {
		$is_success =
			($status['success'] ?? false) === true ||
			($status['result'] ?? '') === 'success';
	}

	$message =
		$status['message']
		?? $status['error']
		?? ($is_success ? 'Payment initiated' : 'Payment failed');

	$redirect =
		$status['data']['redirect']
		?? $status['redirect']
		?? null;

	// ─────────────────────────────────────────────
	// FINAL RESPONSE LOG
	// ─────────────────────────────────────────────
	ByteNFT_Payment_Gateway_Logger::info(
		$log_prefix . ' AJAX | Final response prepared',
		[
			'success'  => $is_success,
			'message'  => $message,
			'redirect' => $redirect
		]
	);

	// ─────────────────────────────────────────────
	// RESPONSE (UNIFIED CONTRACT)
	// ─────────────────────────────────────────────
	wp_send_json([
		'success' => $is_success,
		'message' => $message,
		'data'    => [
			'order_id' => $orderID,
			'redirect' => $redirect,
			'raw'      => $status // optional debugging (safe for dev)
		]
	]);

	die;
}
