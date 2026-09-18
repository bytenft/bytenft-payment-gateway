<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class VOUCHER_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = 'voucher';
	protected $id   = 'voucher';

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
			'voucher-blocks-js',
			plugin_dir_url(VOUCHER_PAYMENT_GATEWAY_FILE) . 'assets/js/voucher-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element'],
			'1.0.0',
			true
		);
		return ['voucher-blocks-js'];
	}

	public function get_payment_method_data() {
         $title       = $this->settings['title'] ?? 'Voucher';
        $description = $this->settings['description'] ?? '';

		if (WC()->cart) {
			$amount   = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways['voucher'] ?? null;
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

		error_log('Voucher Blocks Data: ' . print_r($data, true));
	}
}


/**
 * ─────────────────────────────────────────────────────────────────────────────
 * AJAX handler for Block Checkout payment processing.
 *
 * KEY FIX: Instead of `new VOUCHER_PAYMENT_GATEWAY()` (which creates a fresh,
 * partially-initialised instance), we pull the already-booted gateway instance
 * from WooCommerce's payment gateway registry.  That instance has had
 * init_settings() called by WooCommerce during the normal boot cycle, so
 * $this->sandbox, $this->enabled, and all get_option() values are correctly
 * populated when process_payment() runs.
 * ─────────────────────────────────────────────────────────────────────────────
 */
function voucher_register_block_ajax_handlers() {
	add_action('wp_ajax_voucher_block_gateway_process',        'handle_voucher_gateway_ajax');
	add_action('wp_ajax_nopriv_voucher_block_gateway_process', 'handle_voucher_gateway_ajax');
}
add_action('init', 'voucher_register_block_ajax_handlers');

function handle_voucher_gateway_ajax() {

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

	if (empty($nonce) || !wp_verify_nonce($nonce, 'voucher_payment')) {


		Voucher_Payment_Gateway_Logger::info($log_prefix . ' AJAX | Invalid nonce');

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
	$voucherPayment = $gateways['voucher'] ?? null;

	if (!$voucherPayment) {

		$voucherPayment = new VOUCHER_PAYMENT_GATEWAY();
		$voucherPayment->init_settings();
		$voucherPayment->load_gateway_settings();

		Voucher_Payment_Gateway_Logger::warning(
			$log_prefix . ' AJAX | Gateway fallback used (not found in registry)',
			['event' => 'gateway_fallback']
		);
	}

	// ─────────────────────────────────────────────
	// ORDER VALIDATION LOG
	// ─────────────────────────────────────────────
	if (!$orderID) {

		Voucher_Payment_Gateway_Logger::error(
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
	$status = $voucherPayment->process_payment($orderID);

	// ─────────────────────────────────────────────
	// LOG PROCESS RESULT
	// ─────────────────────────────────────────────
	
	Voucher_Payment_Gateway_Logger::info(
		$log_prefix . ' AJAX | process_payment executed',
		['status' => $status]
	);

	// ─────────────────────────────────────────────
	// NORMALIZE RESPONSE (SAFE FIX LAYER)
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
	Voucher_Payment_Gateway_Logger::info(
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
