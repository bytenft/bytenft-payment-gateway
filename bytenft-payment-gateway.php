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

add_action(
    'woocommerce_cancel_unpaid_order',
    'bytenft_cancel_unpaid_order_action'
);

add_action(
    'woocommerce_order_status_cancelled',
    'bytenft_cancelled_order_sync',
    10,
    1
);


/**
 * Handles WooCommerce unpaid order timeout cancellation.
 *
 * This runs before WooCommerce changes the order status.
 *
 * @param int $order_id
 */
function bytenft_cancel_unpaid_order_action($order_id)
{
    if (empty($order_id) || !is_numeric($order_id)) {
        return;
    }

    $order = wc_get_order($order_id);

    if (!$order instanceof WC_Order) {
        ByteNFT_Payment_Gateway_Logger::error(
            'Unable to load order for unpaid cancellation.',
            [
                'source' => 'bytenft-payment-gateway',
                'context' => [
                    'order_id' => $order_id,
                ],
            ]
        );

        return;
    }


    // Only process pending orders.
    if (!$order->has_status('pending')) {
        return;
    }


    $pending_time = (int) $order->get_meta('_pending_order_time', true);


    // Respect 30 minute payment window.
    if (
        $pending_time > 0 &&
        (time() - $pending_time) < (30 * 60)
    ) {

        ByteNFT_Payment_Gateway_Logger::info(
            'Order still within pending timeout. Skipping cancel.',
            [
                'source' => 'bytenft-payment-gateway',
                'context' => [
                    'order_id' => $order_id,
                ],
            ]
        );

        return;
    }


    $order->update_status(
        'cancelled',
        'Order automatically cancelled due to unpaid timeout.'
    );


    ByteNFT_Payment_Gateway_Logger::info(
        'Order automatically cancelled due to unpaid timeout.',
        [
            'source' => 'bytenft-payment-gateway',
            'context' => [
                'order_id' => $order_id,
            ],
        ]
    );
}



/**
 * Sync cancelled order with ByteNFT.
 *
 * Runs when:
 * - Admin manually cancels order
 * - WooCommerce automatically cancels order
 * - Any integration changes status to cancelled
 *
 * @param int $order_id
 */
function bytenft_cancelled_order_sync($order_id)
{
    if (empty($order_id) || !is_numeric($order_id)) {
        return;
    }


    $order = wc_get_order($order_id);


    if (!$order instanceof WC_Order) {
        return;
    }

    $table_name  = $GLOBALS['wpdb']->prefix . 'order_payment_link';
    $cache_key   = 'bytenft_payment_row_' . intval($order_id);
    $cache_group = 'bytenft_payment_gateway';


    $payment_row = wp_cache_get(
        $cache_key,
        $cache_group
    );


    if (false === $payment_row) {

        global $wpdb;

        $safe_table_name = esc_sql($table_name);


        $sql = "
            SELECT *
            FROM {$safe_table_name}
            WHERE order_id = %d
            LIMIT 1
        ";


        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $payment_row = $wpdb->get_row(
            $wpdb->prepare(
                $sql,
                intval($order_id)
            ),
            ARRAY_A
        );


        if ($payment_row) {
            wp_cache_set(
                $cache_key,
                $payment_row,
                $cache_group,
                5 * MINUTE_IN_SECONDS
            );
        }
    }


    if (empty($payment_row)) {

        ByteNFT_Payment_Gateway_Logger::error(
            'Payment link record not found for cancelled order.',
            [
                'source' => 'bytenft-payment-gateway',
                'context' => [
                    'order_id' => $order_id,
                ],
            ]
        );

        return;
    }


    $uuid = sanitize_text_field(
        $payment_row['uuid'] ?? ''
    );


    if (empty($uuid)) {

        ByteNFT_Payment_Gateway_Logger::error(
            'Missing UUID for cancelled order.',
            [
                'source' => 'bytenft-payment-gateway',
                'context' => [
                    'order_id' => $order_id,
                ],
            ]
        );

        return;
    }

	// Prevent duplicate cancellation for the same payment link (UUID).
	$last_cancelled_uuid = sanitize_text_field(
		$order->get_meta('_bytenft_last_cancelled_uuid', true)
	);

	if (!empty($last_cancelled_uuid) && $last_cancelled_uuid === $uuid) {

		ByteNFT_Payment_Gateway_Logger::info(
			'Cancel API already synced for this payment link. Skipping.',
			[
				'source' => 'bytenft-payment-gateway',
				'context' => [
					'order_id'             => $order_id,
					'uuid'                 => $uuid,
					'last_cancelled_uuid'  => $last_cancelled_uuid,
				],
			]
		);

		return;
	}

    $api_url = BYTENFT_BASE_URL . '/api/cancel-order-link';


    $api_url = esc_url(
        preg_replace(
            '#(?<!:)//+#',
            '/',
            $api_url
        )
    );


    $payload = [
        'order_id'   => $order_id,
        'order_uuid' => $uuid,
        'status'     => 'canceled',
    ];


    $response = wp_remote_post(
        $api_url,
        [
            'method'    => 'POST',
            'timeout'   => 30,
            'headers'   => [
                'Content-Type' => 'application/json',
            ],
            'body'      => wp_json_encode($payload),
            'sslverify' => true,
        ]
    );


    if (is_wp_error($response)) {

        ByteNFT_Payment_Gateway_Logger::error(
            'Cancel API request failed.',
            [
                'source' => 'bytenft-payment-gateway',
                'context' => [
                    'order_id' => $order_id,
                    'uuid' => $uuid,
                    'error' => $response->get_error_message(),
                ],
            ]
        );

        return;
    }


    $response_body = wp_remote_retrieve_body($response);

    $decoded_response = json_decode(
        $response_body,
        true
    );


    ByteNFT_Payment_Gateway_Logger::info(
        'Cancel API response received.',
        [
            'source' => 'bytenft-payment-gateway',
            'context' => [
                'order_id' => $order_id,
                'uuid' => $uuid,
                'response' => $decoded_response,
            ],
        ]
    );


    // Mark this payment link (UUID) as cancelled.
	$order->update_meta_data(
		'_bytenft_last_cancelled_uuid',
		$uuid
	);

	$order->update_meta_data(
		'_bytenft_last_cancelled_at',
		current_time('mysql')
	);

	$order->save();

    // Clear cache.
    wp_cache_delete(
        'bytenft_payment_link_uuid_' . $order_id,
        'bytenft_payment_gateway'
    );

    wp_cache_delete(
        'bytenft_payment_row_' . $order_id,
        'bytenft_payment_gateway'
    );
}