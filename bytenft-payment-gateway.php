<?php

/**
 * Plugin Name: ByteNFT Payment Gateway
 * Description: Use a Credit Card, Debit Card or Google Pay, Apple Pay to complete your purchase via USDC. The transaction will appear on your bank or card statement as *ByteNFT.
 * Author: ByteNFT
 * Author URI: https://pay.bytenft.xyz/
 * Text Domain: bytenft-payment-gateway
 * Plugin URI: https://github.com/bytenft/bytenft-payment-gateway
 * Version: 1.0.18
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

/**
 * Cancels an unpaid order after a specified timeout.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function bytenft_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	// Fallback: try to fetch latest placeholder if order is invalid
	if (!$order) {
		$args = [
			'post_type'      => 'shop_order_placehold',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		$placeholder_orders = get_posts($args);

		if (!empty($placeholder_orders)) {
			$order_id = $placeholder_orders[0];
			$order    = wc_get_order($order_id);

			ByteNFT_Payment_Gateway_Logger::info('Fallback to latest unpaid placeholder order.', [
				'source'  => 'bytenft-payment-gateway',
				'context' => ['order_id' => $order_id],
			]);
		} else {
			ByteNFT_Payment_Gateway_Logger::error('No unpaid placeholder orders found.', [
				'source' => 'bytenft-payment-gateway',
			]);
			return;
		}
	}

	if (!$order) {
		ByteNFT_Payment_Gateway_Logger::error('Order not found.', [
			'source'  => 'bytenft-payment-gateway',
			'context' => ['order_id' => $order_id],
		]);
		return;
	}

	if ($order->has_status('pending')) {

		$pending_time = (int) get_post_meta($order_id, '_pending_order_time', true);

		if (
			$pending_time > 0 &&
			(time() - $pending_time) < (30 * 60)
		) {

			ByteNFT_Payment_Gateway_Logger::info(
				'Order still within pending timeout. Skipping cancel.',
				[
					'source'  => 'bytenft-payment-gateway',
					'context' => [
						'order_id' => $order_id
					],
				]
			);

			return;
		}

		$order->update_status(
			'cancelled',
			'Order automatically cancelled due to unpaid timeout.'
		);

		wc_reduce_stock_levels($order_id);

		wp_cache_delete(
			'bytenft_payment_link_uuid_' . $order_id,
			'bytenft_payment_gateway'
		);

		wp_cache_delete(
			'bytenft_payment_row_' . $order_id,
			'bytenft_payment_gateway'
		);

		ByteNFT_Payment_Gateway_Logger::info(
			'Order auto-cancelled due to unpaid timeout.',
			[
				'source'  => 'bytenft-payment-gateway',
				'context' => [
					'order_id' => $order_id
				],
			]
		);
	}

	if (!$order->has_status('cancelled')) {
		return;
	}
	
}

