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
             * IMPORTANT FIX:
             * DO NOT BLOCK same-state events (failed→failed)
             * we still want notes
             */
            $can_transition = self::can_transition($current_state, $new_state);

            // only block invalid transitions, but allow note logging always
            if (!$can_transition && $current_state !== $new_state) {
                return self::safe_response($order, 'invalid_transition', $current_state);
            }

            /**
             * ALWAYS WRITE NOTE (even failed → failed)
             */
            self::write_note($order, $new_state, $event_type, $payload);

            /**
             * APPLY STATE ONLY IF:
             * - different state OR valid transition
             */
            if ($current_state !== $new_state && $can_transition) {
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

    private static function get_state($order)
    {
        return $order->get_meta('_bytenft_state') ?: 'pending';
    }

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
     * STATE MATRIX (UNCHANGED LOGIC)
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if (in_array($from, ['processing', 'completed'], true)) {
            return false;
        }

        $map = [
            'pending' => ['failed','cancelled','processing','success'],
            'failed'  => ['failed','success'],
            'cancelled' => ['success'],
            'success' => [],
            'expired' => ['failed','cancelled','success'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * FINAL STATE (RESTORED - UNTOUCHED)
     * ========================================================= */
    public static function resolve_final_state($order, $api_status = null)
    {
        $state = $order->get_meta('_bytenft_state');

        if (!empty($state)) {
            return $state;
        }

        if (!empty($api_status)) {
            return match ($api_status) {
                'success','paid','completed' => 'success',
                'failed' => 'failed',
                'cancelled','canceled' => 'cancelled',
                'processing','pending' => 'processing',
                default => null
            };
        }

        if ($order->has_status(['processing','completed'])) {
            return 'success';
        }

        return null;
    }

    /* =========================================================
     * NOTE WRITER (NEW SAFE SEPARATION)
     * ========================================================= */
    private static function write_note($order, $state, $event_type, $payload)
    {
        $note = self::build_note($state, $event_type, $payload);

        // allow duplicate FAILED logs, but block exact spam (same payload)
        $hash = md5($state . $event_type . json_encode($payload));

        if ($order->get_meta('_bytenft_last_note_hash') === $hash) {
            return;
        }

        $order->add_order_note($note, false, true);
        $order->update_meta_data('_bytenft_last_note_hash', $hash);
    }

    /* =========================================================
     * APPLY STATE (ONLY WC STATUS + META)
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
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
     * WC STATUS
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = get_option('woocommerce_bytenft_settings', []);
        return in_array($settings['order_status'] ?? 'processing', ['processing','completed'], true)
            ? $settings['order_status']
            : 'processing';
    }

    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $payload['payment_token'] ?? '',
            $payload['status'] ?? '',
            $type
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

    private static function get_friendly_source($event_type)
    {
        return match ($event_type) {
            'popup_close' => 'Customer Checkout Session Closed',
            'api_update'  => 'Payment Gateway Webhook Update',
            'redirect'    => 'Customer Return from Payment Page',
            'cron'        => 'Automatic Payment Reconciliation',
            'manual'      => 'Manual Admin Update',
            default       => 'Payment Gateway Update'
        };
    }

    private static function build_note($state, $event_type, $payload)
    {
        $map = [
            'success'    => 'Payment completed successfully',
            'failed'     => 'Payment failed',
            'cancelled'  => 'Payment was cancelled',
            'processing' => 'Payment is being processed'
        ];

        $transaction_id = null;

        if (!empty($payload['payment_token'])) {
            $decoded = base64_decode($payload['payment_token'], true);
            if ($decoded) $transaction_id = $decoded;
        }

        $lines = [];
        $lines[] = '<strong> ByteNFT Gateway </strong>';
        $lines[] = '';
        $lines[] = $map[$state] ?? 'Payment update';

        if ($transaction_id) {
            $lines[] = '<strong>Payment ID:</strong> ' . $transaction_id;
        }

        $lines[] = '<strong>Updated via:</strong> ' . self::get_friendly_source($event_type);
        $lines[] = '<strong>Date:</strong> ' . date_i18n('M j, Y \a\t g:i A');

        return implode("\n", $lines);
    }

    private static function safe_response($order, $reason, $state = null)
    {
        return [
            'ok' => true,
            'reason' => $reason,
            'order_id' => $order->get_id(),
            'state' => $state ?? self::get_state($order)
        ];
    }
}