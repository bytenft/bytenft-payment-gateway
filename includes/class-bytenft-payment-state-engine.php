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

            $existing = self::get_state($order);

            if ($existing === 'success') {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            $new_state = self::resolve_state($payload);

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $existing);
            }

            $current_state = self::normalize_state($existing);
            $new_state     = self::normalize_state($new_state);

            /**
             * 🔥 ALWAYS WRITE NOTE (NO CONDITIONS)
             */
            self::write_note($order, $new_state, $event_type, $payload);

            /**
             * 🚀 STATE UPDATE ONLY IF REAL CHANGE
             */
            if ($current_state !== $new_state && self::can_transition($current_state, $new_state)) {
                self::apply($order, $new_state, $event_type, $payload);
            }

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_transient($lock_key);
        }
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
     * STATE
     * ========================================================= */
    private static function get_state($order)
    {
        return $order->get_meta('_bytenft_state') ?: 'pending';
    }

    /* =========================================================
     * RESOLVE
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
            'failed' => 'failed',
            'cancelled','canceled' => 'cancelled',
            'expired' => 'expired',
            'pending','processing' => 'processing',
            default => null
        };
    }

    /* =========================================================
     * FIXED TRANSITION RULES
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if ($from === 'success') {
            return false;
        }

        // allow repeated failed events (IMPORTANT)
        if ($from === 'failed' && $to === 'failed') {
            return true;
        }

        $map = [
            'pending' => ['failed','processing','cancelled','success'],
            'failed'  => ['success'],
            'cancelled' => ['success'],
            'expired' => ['failed','cancelled','success'],
            'processing' => ['success','failed','cancelled'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * APPLY STATE
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        $wc_status = match ($state) {
            'success' => self::get_success_wc_status(),
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            'expired' => 'failed',
            'processing' => 'pending',
            default => null
        };

        if (!$wc_status) return;

        if ($order->get_status() !== $wc_status) {
            $order->update_status($wc_status);
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
     * 🔥 NOTE WRITER (FIXED - NO DUPLICATE BLOCKING)
     * ========================================================= */
    private static function write_note($order, $state, $event_type, $payload)
    {
        $map = [
            'success' => 'Payment completed successfully',
            'failed' => 'Payment failed',
            'cancelled' => 'Payment cancelled',
            'processing' => 'Payment processing',
            'expired' => 'Payment expired'
        ];

        $source = self::get_friendly_source($event_type);

        $payment_id = !empty($payload['payment_token'])
            ? base64_decode($payload['payment_token'], true)
            : null;

        $lines = [];
        $lines[] = '<strong>ByteNFT Gateway</strong>';
        $lines[] = $map[$state] ?? 'Payment update';

        if ($payment_id) {
            $lines[] = 'Payment ID: ' . $payment_id;
        }

        if ($source) {
            $lines[] = 'Updated via: ' . $source;
        }

        $lines[] = date_i18n('M j, Y \a\t g:i A');

        $order->add_order_note(implode("\n", $lines), false, true);
    }

    /* =========================================================
     * EVENT ID (IMPROVED SAFETY)
     * ========================================================= */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $type,
            $payload['status'] ?? '',
            $payload['payment_token'] ?? '',
            time() // prevents over-blocking retries
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
     * STATUS MAP
     * ========================================================= */
    private static function get_friendly_source($event_type)
    {
        return match ($event_type) {
            'popup_close' => 'Checkout Session Closed',
            'api_update'  => 'Webhook',
            'redirect'    => 'Return URL',
            default       => 'System'
        };
    }

    private static function get_success_wc_status()
    {
        $settings = get_option('woocommerce_bytenft_settings', []);
        return $settings['order_status'] ?? 'processing';
    }

    private static function safe_response($order, $reason, $state)
    {
        return [
            'ok' => true,
            'reason' => $reason,
            'order_id' => $order->get_id(),
            'state' => $state
        ];
    }
}