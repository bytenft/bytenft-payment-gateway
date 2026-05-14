<?php
if (!defined('ABSPATH')) exit;

/**
 * Payment State Machine (Stripe-grade - Phase 1)
 */
class BYTENFT_PAYMENT_ENGINE
{
    const LOCK_TTL  = 12;    // seconds
    const EVENT_TTL = 86400; // 24h dedupe window

    /**
     * PUBLIC ENTRY POINT
     */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        /**
         * -----------------------------
         * 1. STRONG EVENT HASH (IMPROVED)
         * -----------------------------
         */
        $event_id = self::generate_event_id($event_type, $payload);

        if (self::is_duplicate_event($order_id, $event_id)) {
            return self::safe_response($order, 'duplicate_event_ignored');
        }

        self::mark_event($order_id, $event_id);

        /**
         * -----------------------------
         * 2. SAFE TRANSIENT LOCK
         * -----------------------------
         */
        $lock_key = "bytenft_lock_{$order_id}";

        if (get_transient($lock_key)) {
            return self::safe_response($order, 'locked_skip');
        }

        set_transient($lock_key, 1, self::LOCK_TTL);

        try {

            /**
             * -----------------------------
             * 3. CURRENT STATE
             * -----------------------------
             */
            $current_state = $order->get_meta('_bytenft_state') ?: 'pending';

            /**
             * -----------------------------
             * 4. RESOLVE NEW STATE
             * -----------------------------
             */
            $new_state = self::resolve_state($payload);

            if (!$new_state) {
                return self::safe_response($order, 'no_state');
            }

            /**
             * -----------------------------
             * 5. NO CHANGE
             * -----------------------------
             */
            if ($new_state === $current_state) {
                return self::safe_response($order, 'no_change');
            }

            /**
             * -----------------------------
             * 6. VALID TRANSITION CHECK
             * -----------------------------
             */
            if (!self::is_valid_transition($current_state, $new_state)) {
                return self::safe_response($order, 'invalid_transition');
            }

            /**
             * -----------------------------
             * 7. APPLY STATE
             * -----------------------------
             */
            self::apply($order, $new_state, $event_type, $payload);

            $order->save();

            return self::safe_response($order, 'updated');

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * STATE RESOLUTION
     */
    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        return match ($status) {
            'success', 'paid', 'completed' => 'success',
            'failed' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            'expired' => 'expired',
            'pending', 'processing' => 'processing',
            default => null
        };
    }

    /**
     * VALID TRANSITIONS
     */
    private static function is_valid_transition($from, $to)
    {
        $map = [
            'pending'    => ['processing', 'success', 'failed', 'cancelled'],
            'processing' => ['success', 'failed', 'cancelled'],
            'success'    => [],
            'failed'     => [],
            'cancelled'  => [],
            'expired'    => ['failed', 'cancelled'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /**
     * APPLY STATE
     */
    private static function apply($order, $state, $event_type, $payload)
    {
        $wc_status = match ($state) {
            'success'    => 'processing',
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            'processing' => 'pending',
            default      => null
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

        if (in_array($state, ['success', 'failed', 'cancelled'], true)) {
            $order->update_meta_data('_bytenft_finalized', 'yes');
        }
    }

    /**
     * STRONG EVENT HASH (Phase 1)
     */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', $type . '|' . wp_json_encode($payload));
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

    /**
     * 🧾 MERCHANT FRIENDLY SOURCE LABEL
     */
    private static function get_friendly_source($event_type)
    {
        return match ($event_type) {

            'popup_close' => 'Customer Checkout Session Closed',
            'api_update'  => 'Payment Gateway Webhook Update',
            'redirect'    => 'Customer Return from Payment Page',

            // fallback safety
            'cron'        => 'Automatic Payment Reconciliation',
            'manual'      => 'Manual Admin Update',

            default       => 'Payment Gateway Update'
        };
    }

    /**
     * ORDER NOTE (MERCHANT CLEAN OUTPUT)
     */
    private static function build_note($state, $event_type, $payload)
    {
        $map = [
            'success'    => '✅ Payment Successfully Completed',
            'failed'     => '❌ Payment Failed',
            'cancelled'  => '⚠️ Payment Cancelled',
            'processing' => '⏳ Payment Processing'
        ];

        $source_label = self::get_friendly_source($event_type);

        $note  = ($map[$state] ?? 'Payment Update') . "\n";
        $note .= "State: " . strtoupper($state) . "\n";
        $note .= "Update via: {$source_label}\n";

        if (!empty($payload['payment_token'])) {
            $note .= "Transaction ID: {$payload['payment_token']}\n";
        }

        $note .= "Timestamp: " . current_time('mysql') . "\n";

        return $note;
    }

    /**
     * RESPONSE
     */
    private static function safe_response($order, $reason)
    {
        return [
            'ok'       => true,
            'reason'   => $reason,
            'order_id' => $order->get_id(),
            'state'    => $order->get_meta('_bytenft_state')
        ];
    }
}