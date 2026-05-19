<?php
if (!defined('ABSPATH')) exit;

class BYTENFT_PAYMENT_ENGINE
{
    const LOCK_TTL      = 12;
    const EVENT_TTL     = 86400;
    const MAX_FAIL_NOTES = 3; // show individual notes up to this count

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

            $current_state = self::normalize_state(self::get_state($order));
            $new_state     = self::normalize_state(self::resolve_state($payload));

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $current_state);
            }

            // HARD LOCK
            if ($current_state === 'success') {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            /**
             * ✅ FAILURE HANDLING (IMPORTANT FIX)
             * - record failure
             * - STILL allow apply() so WC status updates
             */
            if ($new_state === 'failed') {

                self::record_failure($order, $event_type, $payload);

                // OPTIONAL: still respect transition rules
                if (!self::can_transition($current_state, $new_state)) {
                    return self::safe_response($order, 'invalid_transition', $current_state);
                }

                self::apply($order, $new_state, $event_type, $payload);

                return self::safe_response($order, 'updated', $new_state);
            }

            // NO CHANGE
            if ($new_state === $current_state) {
                return self::safe_response($order, 'no_change', $new_state);
            }

            // NORMAL TRANSITION CHECK
            if (!self::can_transition($current_state, $new_state)) {
                return self::safe_response($order, 'invalid_transition', $current_state);
            }

            // SUCCESS / CANCEL / ETC
            self::apply($order, $new_state, $event_type, $payload);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_transient($lock_key);
        }
    }

    /* =========================================================
     * FAILURE TRACKING
     * Records each failed attempt as an order note.
     * After MAX_FAIL_NOTES, adds a summary note instead.
     * Does NOT change WC order status — order stays 'pending'
     * so a later success can still go through.
     * ========================================================= */
    private static function record_failure($order, $event_type, $payload)
    {
        $attempt_key = self::get_attempt_key($payload);

        $last_attempt = $order->get_meta('_bytenft_last_failure_attempt');

        // ❌ If same attempt (popup + redirect + webhook), skip
        if ($last_attempt === $attempt_key) {
            return;
        }

        /* ======================================================
        * ✔ NOW THIS IS A NEW FAILURE ATTEMPT
        * ====================================================== */

        $fail_count = (int) $order->get_meta('_bytenft_fail_count');
        $fail_count++;

        $order->update_meta_data('_bytenft_fail_count', $fail_count);
        $order->update_meta_data('_bytenft_last_event', $event_type);
        $order->update_meta_data('_bytenft_last_event_time', current_time('mysql'));

        // ✅ STORE HERE (IMPORTANT PLACE)
        $order->update_meta_data('_bytenft_last_failure_attempt', $attempt_key);

        /* ======================================================
        * NOTE LOGIC
        * ====================================================== */
        $source_label   = self::get_friendly_source($event_type);
        $transaction_id = self::decode_token($payload['payment_token'] ?? '');

        if ($fail_count <= self::MAX_FAIL_NOTES) {

            $lines   = [];
            $lines[] = '<strong>ByteNFT Gateway</strong>';
            $lines[] = '';
            $lines[] = "❌ Payment attempt <strong>#{$fail_count}</strong> failed.";

            if (!empty($transaction_id)) {
                $lines[] = '<strong>Payment ID:</strong> ' . esc_html($transaction_id);
            }

            $lines[] = '<strong>Updated via:</strong> ' . esc_html($source_label);
            $lines[] = '<strong>Date:</strong> ' . date_i18n('M j, Y \a\t g:i A');

            $order->add_order_note(implode("\n", $lines));
        }

        $order->save();
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
     * CURRENT STATE
     * ========================================================= */
    private static function get_state($order)
    {
        return $order->get_meta('_bytenft_state') ?: 'pending';
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
            'success', 'paid', 'completed'  => 'success',
            'failed'                        => 'failed',
            'cancelled', 'canceled'         => 'cancelled',
            'expired'                       => 'expired',
            'pending', 'processing'         => 'processing',
            default                         => null
        };
    }

    /* =========================================================
     * TRANSITION MATRIX
     *
     * KEY CHANGE vs original:
     *   - Removed the blanket hard-lock on 'processing'
     *   - 'processing' can now go to 'failed' or 'cancelled'
     *     (but NOT back to 'pending'; success stays locked)
     *   - 'failed' is no longer a WC status change; it's handled
     *     by record_failure() above, so it doesn't appear here
     *     as a destination that changes the stored _bytenft_state.
     *
     * Matrix (stored _bytenft_state → allowed next stored states):
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        // 'success' is the only truly terminal state
        if ($from === 'success') {
            return false;
        }

        $map = [
            'pending' => [
                'cancelled',
                'processing',
                'success',
                // 'failed' handled separately via record_failure()
            ],

            'processing' => [
                'success',
                'cancelled',
                // 'failed' handled separately via record_failure()
            ],

            'failed' => [
                'success',
                'cancelled',
            ],

            'cancelled' => [
                'success',
            ],

            'expired' => [
                'failed',   // here 'failed' IS a state change (expired → failed is final)
                'cancelled',
                'success',
            ],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * RESOLVE FINAL STATE (public helper, unchanged logic)
     * ========================================================= */
    public static function resolve_final_state($order, $api_status = null)
    {
        $state = $order->get_meta('_bytenft_state');
        if (!empty($state)) return $state;

        if (!empty($api_status)) {
            return match ($api_status) {
                'success', 'paid', 'completed'  => 'success',
                'failed'                        => 'failed',
                'cancelled', 'canceled'         => 'cancelled',
                'processing', 'pending'         => 'processing',
                default                         => null
            };
        }

        if ($order->has_status(['processing', 'completed'])) return 'success';

        return null;
    }

    /* =========================================================
     * APPLY STATE
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        $current_state = self::get_state($order);
        if ($current_state === $state) return;

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            'processing' => 'pending',
            'failed'     => 'failed',
            default      => null
        };

        if (!$wc_status) return;

        // If succeeding after failures, prepend failure summary to the success note
        $fail_count = (int) $order->get_meta('_bytenft_fail_count');
        $note       = self::build_note($state, $event_type, $payload, $fail_count);

        $order->update_status($wc_status, $note);

        $order->update_meta_data('_bytenft_state', $state);
        $order->update_meta_data('_bytenft_last_event', $event_type);
        $order->update_meta_data('_bytenft_last_event_time', current_time('mysql'));

        if (!empty($payload['payment_token'])) {
            $order->update_meta_data('_bytenft_pay_id', $payload['payment_token']);
        }

        if ($state === 'success') {
            $order->update_meta_data('_bytenft_payment_success', 'yes');
        }

        if (in_array($state, ['success', 'failed', 'cancelled'], true)) {
            $order->update_meta_data('_bytenft_finalized', 'yes');
        }

        $order->save();
    }

    /* =========================================================
     * WC SUCCESS STATUS
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = get_option('woocommerce_bytenft_settings', []);
        $status   = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing', 'completed'], true)
            ? $status
            : 'processing';
    }

    /* =========================================================
     * EVENT HASH
     * ========================================================= */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $type,
            $payload['status'] ?? '',
            $payload['payment_token'] ?? '',
            $payload['transaction_id'] ?? ''
        ]));
    }

    private static function is_duplicate_event($order_id, $event_id)
    {
        return get_transient("bytenft_event_{$order_id}_{$event_id}") !== false;
    }

    private static function mark_event($order_id, $event_id)
    {
        set_transient(
            "bytenft_event_{$order_id}_{$event_id}",
            1,
            self::EVENT_TTL
        );
    }

    /* =========================================================
     * SOURCE LABELS
     * ========================================================= */
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

    private static function get_attempt_key($payload)
    {
        return $payload['payment_token'] ?? null;
    }

    /* =========================================================
     * DECODE TOKEN HELPER
     * ========================================================= */
    private static function decode_token($token)
    {
        if (empty($token)) return null;
        $decoded = base64_decode($token, true);
        return !empty($decoded) ? $decoded : null;
    }

    /* =========================================================
     * ORDER NOTE
     * ========================================================= */
    private static function build_note($state, $event_type, $payload, $fail_count = 0)
    {
        $map = [
            'success'    => 'Payment completed successfully',
            'failed'     => 'Payment failed',
            'cancelled'  => 'Payment was cancelled',
            'processing' => 'Payment is being processed',
        ];

        $source_label   = self::get_friendly_source($event_type);
        $transaction_id = self::decode_token($payload['payment_token'] ?? '');

        $lines   = [];
        $lines[] = '<strong>ByteNFT Gateway</strong>';
        $lines[] = '';
        $lines[] = $map[$state] ?? 'Payment update';

        if ($state === 'processing') {
            $lines[] = 'Your payment is currently being verified.';
        }

        if ($state === 'success' && $fail_count > 0) {
            $lines[] = "";
        }

        if (!empty($transaction_id)) {
            $lines[] = '<strong>Payment ID:</strong> ' . esc_html($transaction_id);
        }

        if (!empty($source_label)) {
            $lines[] = '<strong>Updated via:</strong> ' . esc_html($source_label);
        }

        $lines[] = '';
        $lines[] = '<strong>Date:</strong> ' . date_i18n('M j, Y \a\t g:i A');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /* =========================================================
     * RESPONSE
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
}