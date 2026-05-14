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

            /* -------------------------------------------------
             * FINAL LOCK CHECK (IMPORTANT FIX)
             * processing OR completed = FINAL SUCCESS
             * -------------------------------------------------
             */
            $existing = self::get_state($order);

            if (in_array($existing, ['success'], true)) {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            /* -----------------------------
             * CURRENT STATE
             * ----------------------------- */
            $current_state = self::get_state($order);

            /* -----------------------------
             * RESOLVE NEW STATE
             * ----------------------------- */
            $new_state = self::resolve_state($payload);

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $current_state);
            }

            /* -----------------------------
             * NORMALIZE
             * ----------------------------- */
            $current_state = self::normalize_state($current_state);
            $new_state     = self::normalize_state($new_state);

            /* -----------------------------
             * NO CHANGE
             * ----------------------------- */
            if ($new_state === $current_state) {
                return self::safe_response($order, 'no_change', $new_state);
            }

            /* -----------------------------
             * TRANSITION CHECK (FIXED EXACT MATRIX)
             * ----------------------------- */
            if (!self::can_transition($current_state, $new_state)) {
                return self::safe_response($order, 'invalid_transition', $current_state);
            }

            /* -----------------------------
             * APPLY STATE
             * ----------------------------- */
            self::apply($order, $new_state, $event_type, $payload);

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
            'success','paid','completed' => 'success',
            'failed'                     => 'failed',
            'cancelled','canceled'       => 'cancelled',
            'expired'                    => 'expired',
            'pending','processing'       => 'processing',
            default => null
        };
    }

    /* =========================================================
     * FINAL STATE TRANSITION MATRIX (YOUR EXACT RULES)
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        $map = [

            'pending' => [
                'processing','failed','cancelled','success'
            ],

            'failed' => [
                'success'
            ],

            'cancelled' => [
                'success'
            ],

            /* -------------------------------------------------
             * BLOCK RULES (YOUR REQUIREMENT)
             * -------------------------------------------------
             */
            'processing' => [
                // ❌ BLOCK failed/cancelled/success updates
            ],

            'success' => [
                // ❌ FULL LOCK (processing OR completed)
            ],

            'expired' => [
                'failed','cancelled'
            ],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    public static function resolve_final_state($order, $api_status = null)
    {
        // 1. PRIMARY: engine state
        $state = $order->get_meta('_bytenft_state');

        if (!empty($state)) {
            return $state;
        }

        // 2. SAFETY: API status (current response)
        if (!empty($api_status)) {
            return match ($api_status) {
                'success', 'paid', 'completed' => 'success',
                'failed' => 'failed',
                'cancelled', 'canceled' => 'cancelled',
                'processing', 'pending' => 'processing',
                default => null
            };
        }

        // 3. BACKUP: WooCommerce status
        if ($order->has_status(['processing', 'completed'])) {
            return 'success';
        }

        return null;
    }

    /* =========================================================
     * APPLY STATE (UNCHANGED CORE LOGIC)
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        $current_state = self::get_state($order);

        if ($current_state === $state) return;

        $wc_status = match ($state) {

            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            'processing' => 'pending',

            default => null
        };

        if (!$wc_status) return;

        $note = self::build_note($state, $event_type, $payload);

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

        if (in_array($state, ['success','failed','cancelled'], true)) {
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
        $status = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing','completed'], true)
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
            $payload['payment_token'] ?? ''
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

    /* =========================================================
     * ORDER NOTE (UNCHANGED)
     * ========================================================= */
    private static function build_note($state, $event_type, $payload)
    {
        $map = [
            'success'    => 'Payment completed successfully',
            'failed'     => 'Payment failed',
            'cancelled'  => 'Payment was cancelled',
            'processing' => 'Payment is being processed'
        ];

        $source_label = self::get_friendly_source($event_type);

        $transaction_id = null;

        if (!empty($payload['payment_token'])) {
            $decoded = base64_decode($payload['payment_token'], true);
            if (!empty($decoded)) {
                $transaction_id = $decoded;
            }
        }

        $lines = [];

        $lines[] = '<strong> ByteNFT Gateway </strong>';
        $lines[] = '';
        $lines[] = $map[$state] ?? 'Payment update';

        if ($state === 'processing') {
            $lines[] = 'Your payment is currently being verified.';
        }

        if (!empty($transaction_id)) {
            $lines[] = '<strong>Payment ID:</strong> ' . $transaction_id;
        }

        if (!empty($source_label)) {
            $lines[] = '<strong>Updated via:</strong> ' . $source_label;
        }

        $lines[] = '';
        $lines[] = '<strong>Date:</strong> ' . date_i18n('M j, Y \a\t g:i A');
        $lines[] = '';
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