<?php

/**
 * Plugin Name: ByteNFT Payment Gateway
 * Description: Use a Credit Card, Debit Card or Google Pay, Apple Pay to complete your purchase via USDC. The transaction will appear on your bank or card statement as *ByteNFT.
 * Author: ByteNFT
 * Author URI: https://pay.bytenft.xyz/
 * Text Domain: bytenft-payment-gateway
 * Plugin URI: https://github.com/bytenft/bytenft-payment-gateway
 * Version: 1.0.14
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 ByteNFT
 */

if (!defined('ABSPATH')) {
	exit;
}

define('BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('BYTENFT_PAYMENT_GATEWAY_FILE', __FILE__);
define('BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include utility functions
require_once BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/bytenft-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'BYTENFT_PAYMENT_GATEWAY') === 0) {
		$class_file = BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

BYTENFT_PAYMENT_GATEWAY_Loader::get_instance();

add_action('woocommerce_cancel_unpaid_order', 'bytenft_cancel_unpaid_order_action');
add_action('woocommerce_order_status_cancelled', 'bytenft_cancel_unpaid_order_action');
add_action('woocommerce_order_status_changed', 'bytenft_cancel_unpaid_order_action', 10, 4);

add_filter('woocommerce_payment_successful_result', function ($result, $order_id) {

    $order = wc_get_order($order_id);

    if ($order && $order->get_payment_method() === 'bytenft') {
        return [
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        ];
    }

    return $result;

}, 10, 2);


/**
 * Cancels an unpaid order after a specified timeout.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function bytenft_cancel_unpaid_order_action($order_id = null, $old_status = null, $new_status = null, $order = null)
{
	global $wpdb;

	// -----------------------------
	// Normalize order object
	// -----------------------------
	if ($order instanceof WC_Order) {
		$order_id = $order->get_id();
	} else {
		$order_id = is_object($order_id) ? $order_id->get_id() : intval($order_id);
		$order = wc_get_order($order_id);
	}

	if (!$order) {
		return;
	}

	// -----------------------------
	// Only handle cancelled state
	// -----------------------------
	$current_status = $order->get_status();

	if ($current_status !== 'cancelled') {
		return;
	}

	// -----------------------------
	// Prevent duplicate sync
	// -----------------------------
	$already_synced = $order->get_meta('_bytenft_cancel_synced');

	if ($already_synced) {
		return;
	}

	// -----------------------------
	// Detect cancel source
	// -----------------------------
	$source = 'manual';

	if (did_action('woocommerce_cancel_unpaid_order')) {
		$source = 'cron';
	} elseif (did_action('woocommerce_order_status_cancelled')) {
		$source = 'woocommerce';
	}

	// -----------------------------
	// Get payment row
	// -----------------------------
	$table_name = $wpdb->prefix . 'order_payment_link';
	$sql = "SELECT * FROM {$table_name} WHERE order_id = %d LIMIT 1";

	$payment_row = $wpdb->get_row($wpdb->prepare($sql, $order_id), ARRAY_A);

	if (empty($payment_row) || empty($payment_row['uuid'])) {
		return;
	}

	$uuid = sanitize_text_field($payment_row['uuid']);

	// -----------------------------
	// Call API
	// -----------------------------
	$url = esc_url(BYTENFT_BASE_URL . '/api/cancel-order-link');

	$response = wp_remote_post($url, [
		'method'  => 'POST',
		'timeout' => 30,
		'body'    => wp_json_encode([
			'order_id'   => $order_id,
			'order_uuid' => $uuid,
			'status'     => 'cancelled',
			'source'     => $source
		]),
		'headers' => [
			'Content-Type' => 'application/json',
		],
	]);

	if (is_wp_error($response)) {
		wc_get_logger()->error('Cancel sync failed', [
			'order_id' => $order_id,
			'error' => $response->get_error_message(),
		]);
		return;
	}

	// mark synced ONLY on success response
	$order->update_meta_data('_bytenft_cancel_synced', time());
	$order->save();

	// -----------------------------
	// Logging
	// -----------------------------
	if (is_wp_error($response)) {
		wc_get_logger()->error('Cancel sync failed', [
			'order_id' => $order_id,
			'error' => $response->get_error_message(),
		]);
		return;
	}

	wc_get_logger()->info('Cancel synced successfully', [
		'order_id' => $order_id,
		'uuid' => $uuid,
		'source' => $source
	]);
}

