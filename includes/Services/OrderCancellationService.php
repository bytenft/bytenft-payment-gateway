<?php

class BYTENFT_OrderCancellationService
{
    private string $base_url;

    public function __construct($base_url)
    {
        $this->base_url = $base_url;
    }

    /**
     * Cancel unpaid order (Stripe-style service layer)
     */
    public function cancel_unpaid_order($order_id)
    {
        global $wpdb;

        if (!$order_id || $order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            wc_get_logger()->error('Order not found for cancellation', [
                'source' => 'bytenft-payment-gateway',
                'order_id' => $order_id
            ]);
            return;
        }

        // Prevent double cancel
        if ($order->get_status() === 'cancelled') {
            return;
        }

        // Time check (your existing logic preserved)
        $pending_time = (int) get_post_meta($order_id, '_pending_order_time', true);

        if ($order->has_status('pending') && (time() - $pending_time) < 1800) {
            return;
        }

        // Mark cancelled locally
        $order->update_status('cancelled', 'Auto-cancelled due to timeout');
        wc_reduce_stock_levels($order_id);

        // Get payment row (same logic you already had)
        $table_name = $wpdb->prefix . 'order_payment_link';

        $payment_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE order_id = %d LIMIT 1",
                $order_id
            ),
            ARRAY_A
        );

        if (!$payment_row) {
            return;
        }

        $uuid = sanitize_text_field($payment_row['uuid'] ?? '');

        if (empty($uuid)) {
            return;
        }

        // Call external cancel API
        $url = trailingslashit($this->base_url) . 'api/cancel-order-link';

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'order_id'   => $order_id,
                'order_uuid' => $uuid,
                'status'     => 'canceled'
            ]),
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            wc_get_logger()->error('Cancel API failed', [
                'source' => 'bytenft-payment-gateway',
                'order_id' => $order_id,
                'error' => $response->get_error_message()
            ]);
        }

        wc_get_logger()->info('Order cancelled successfully', [
            'source' => 'bytenft-payment-gateway',
            'order_id' => $order_id,
            'uuid' => $uuid
        ]);
    }
}