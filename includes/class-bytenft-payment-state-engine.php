<?php
if (!defined('ABSPATH')) exit;

class BYTENFT_PAYMENT_ENGINE
{
    const LOCK_TTL  = 12;
    const EVENT_TTL = 86400;

    /* =========================================================
     * ENTRY POINT
     * ========================================================= */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        $event_id = self::generate_event_id($event_type, $payload);

        // prevent exact webhook replay duplication only
        if (self::is_duplicate_event($order_id, $event_id)) {
            return self::safe_response($order, 'duplicate_event_ignored', self::get_state($order));
        }

        self::mark_event($order_id, $event_id);

        $lock_key = "bytenft_lock_{$order_id}";
        if (get_transient($lock_key)) {
            return self::safe_response($order, 'locked_skip');
        }

        set_transient($lock_key, 1, self::LOCK_TTL);

        try {

            $new_state = self::normalize_state(
                self::resolve_state($payload)
            );

            if (!$new_state) {
                return self::safe_response($order, 'no_state', self::get_state($order));
            }

            // ALWAYS write note (no conditions)
            self::write_note($order, $new_state, $event_type, $payload);

            // ALWAYS update WooCommerce state (even repeated same state)
            self::update_state($order, $new_state, $event_type, $payload);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_transient($lock_key);
        }
    }

    /* =========================================================
     * STATE RESOLUTION
     * ========================================================= */
    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        return match ($status) {
            'success','paid','completed' => 'success',
            'failed'                     => 'failed',
            'cancelled','canceled'       => 'cancelled',
            'expired'                    => 'failed',
            'pending','processing'       => 'processing',
            default => null
        };
    }

    /* =========================================================
     * NORMALIZATION
     * ========================================================= */
    private static function normalize_state($state)
    {
        return match ($state) {
            'completed' => 'success',
            'canceled'  => 'cancelled',
            default     => $state
        };
    }

    /* =========================================================
     * WRITE ORDER NOTE (ALWAYS)
     * ========================================================= */
    private static function write_note($order, $state, $event_type, $payload)
    {
        $note = self::build_note($state, $event_type, $payload);
        $order->add_order_note($note, false, true);
    }

    /* =========================================================
     * UPDATE STATE (ALWAYS)
     * ========================================================= */
    private static function update_state($order, $state, $event_type, $payload)
    {
        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            'processing' => 'pending',
            default => null
        };

        if ($wc_status) {
            $order->set_status($wc_status);
        }

        $order->update_meta_data('_bytenft_state', $state);
        $order->update_meta_data('_bytenft_last_event', $event_type);
        $order->update_meta_data('_bytenft_last_event_time', current_time('mysql'));

        if (!empty($payload['payment_token'])) {
            $order->update_meta_data('_bytenft_pay_id', $payload['payment_token']);
        }

        if ($state === 'success') {
            $order->update_meta_data('_bytenft_payment_success', 'yes');
        }

        if (in_array($state, ['success','failed','cancelled'], true)) {
            $order->update_meta_data('_bytenft_finalized', 'yes');
        }

        $order->save();
    }

    /* =========================================================
     * WC STATUS CONFIG
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = get_option('woocommerce_bytenft_settings', []);
        $status = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing','completed'], true)
            ? $status
            : 'processing';
    }

    /* =========================================================
     * EVENT ID (light dedupe only)
     * ========================================================= */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $type,
            $payload['status'] ?? '',
            $payload['payment_token'] ?? '',
            microtime(true) // ensures repeated failed still allowed
        ]));
    }

    private static function is_duplicate_event($order_id, $event_id)
    {
        return get_transient("bytenft_event_{$order_id}_{$event_id}") !== false;
    }

    private static function mark_event($order_id, $event_id)
    {
        set_transient("bytenft_event_{$order_id}_{$event_id}", 1, self::EVENT_TTL);
    }

    /* =========================================================
     * BUILD NOTE
     * ========================================================= */
    private static function build_note($state, $event_type, $payload)
    {
        $map = [
            'success'    => 'Payment completed successfully',
            'failed'     => 'Payment failed',
            'cancelled'  => 'Payment was cancelled',
            'processing' => 'Payment is being processed'
        ];

        $source_label = match ($event_type) {
            'popup_close' => 'Customer Checkout Session Closed',
            'api_update'  => 'Payment Gateway Webhook Update',
            'redirect'    => 'Customer Return from Payment Page',
            default       => 'Payment Gateway Update'
        };

        $transaction_id = null;

        if (!empty($payload['payment_token'])) {
            $decoded = base64_decode($payload['payment_token'], true);
            if ($decoded) {
                $transaction_id = $decoded;
            }
        }

        $lines = [];

        $lines[] = 'ByteNFT Gateway';
        $lines[] = '';
        $lines[] = $map[$state] ?? 'Payment update';

        if ($transaction_id) {
            $lines[] = 'Payment ID: ' . $transaction_id;
        }

        if ($source_label) {
            $lines[] = 'Updated via: ' . $source_label;
        }

        $lines[] = '';
        $lines[] = 'Date: ' . date_i18n('M j, Y \a\t g:i A');

        return implode("\n", $lines);
    }

    /* =========================================================
     * SAFE RESPONSE
     * ========================================================= */
    private static function safe_response($order, $reason, $state = null)
    {
        return [
            'ok'       => true,
            'reason'   => $reason,
            'order_id' => $order->get_id(),
            'state'    => $state ?? self::get_state($order)
        ];
    }

    private static function get_state($order)
    {
        return $order->get_meta('_bytenft_state') ?: 'pending';
    }
}