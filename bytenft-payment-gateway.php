<?php

/**
 * Plugin Name: ByteNFT Payment Gateway
 * Description: This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.
 * Author: ByteNFT
 * Author URI: https://www.bytenft.xyz/
 * Text Domain: bytenft-payment-gateway
 * Plugin URI: 
 * Version: 1.0.0
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2025 ByteNFT
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
	if (strpos($class, 'BYTENFT_PAYMENT_GATEWAY_') === 0) {
		$class_file = BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

BYTENFT_PAYMENT_GATEWAY_Loader::get_instance();

add_action('woocommerce_cancel_unpaid_order', 'cancel_bytenft_unpaid_order_action');
add_action('woocommerce_order_status_cancelled', 'cancel_bytenft_unpaid_order_action');

function cancel_bytenft_unpaid_order_action($order_id) {
	global $wpdb;
    if (!$order_id) {
		wc_get_logger()->error('Cancel order Error: Order ID is missing.', ['source' => 'bytenft-payment-gateway']);
        return;
    }

	$order = wc_get_order($order_id);
	if (!$order_id || !is_numeric($order_id)) {
        $order_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'shop_order_placehold'
            ORDER BY ID DESC
            LIMIT 1
        ");	
    }
	$order = wc_get_order($order_id);
	if (!$order) {
		wc_get_logger()->error('Error: No unpaid orders found.', ['source' => 'bytenft-payment-gateway']);
        return;
    }
	else
	{
				//$pending_time = $order->get_meta('_pending_order_time');
		$pending_time = get_post_meta($order_id, '_pending_order_time', true);
   		$pending_time = is_numeric($pending_time) ? (int) $pending_time : 0; // Ensure it's an integer
		// Check if the order is still unpaid and the timeout has passed
		if ($order->has_status('pending') && (time() - $pending_time) >= (30 * 60)) {
			wc_get_logger()->info('Auto-fetched latest unpaid order ID: ' . $order_id, ['source' => 'bytenft-payment-gateway']);
			$order->update_status('cancelled', 'Order automatically cancelled due to unpaid timeout.');
			//$order->reduce_order_stock(); // Release the stock
			wc_reduce_stock_levels($order_id);
		
		// ========================== start code for expiring payment link ==========================
		//  Get latest payment URL for this order from custom table
        $table_name = esc_sql($wpdb->prefix . 'order_payment_link'); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		
		$latest_uuid = $wpdb->get_row($wpdb->prepare(
            "SELECT uuid FROM {$table_name} WHERE order_id = %d ORDER BY id DESC",
            $order_id
        ));

        if (!$latest_uuid || !isset($latest_uuid->uuid)) {
            wc_get_logger()->error('No Record found for order ID - ' . intval($order_id), ['source' => 'bytenft-payment-gateway']);
            return;
        }

        $encoded_uuid_from_db = sanitize_text_field($latest_uuid->uuid);

		// Call cancel API before inserting new
		$apiPath = '/api/cancel-order-link';

		// Concatenate the base URL and path
		$url = BNFT_PROTOCOL . BNFT_HOST . $apiPath;

		// Remove any double slashes in the URL except for the 'http://' or 'https://'
		$cleanUrl = esc_url(preg_replace('#(?<!:)//+#', '/', $url));
		$response = wp_remote_post($cleanUrl, array(
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => json_encode(array(
				'order_id'       => $order_id,
				'order_uuid'  => $encoded_uuid_from_db,
				'status'         => 'canceled'
			)),
			'headers'   => array(
				'Content-Type'  => 'application/json',
			),
			'sslverify' => true, // Ensure SSL verification
		));


		// Log API response for debugging (with json_encode for structured data)
		if (is_wp_error($response)) {
		    wc_get_logger()->error('Cancel API Error: ' . $response->get_error_message(), ['source' => 'bytenft-payment-gateway']);
		} else {
		    $response_body = wp_remote_retrieve_body($response);
		    // If the response is an array or object, convert it to a JSON string
		    $response_json = is_array($response_body) || is_object($response_body) ? json_encode($response_body) : $response_body;
		    wc_get_logger()->info('Cancel API Response: ' . $response_json, ['source' => 'bytenft-payment-gateway']);
		}
	}
		// ==============/ end code for payment link expirty / =============================

	}
}
