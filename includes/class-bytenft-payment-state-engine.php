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

        // ONLY dedupe webhook retries (NOT payment attempts)
        if ($event_type === 'api_update' && self::is_duplicate_event($order_id, $event_id)) {
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

            // HARD LOCK SUCCESS
            if ($current_state === 'success') {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            
            // FAILURE → just apply (no blocking logic)
            if ($new_state === 'failed') {

                $failure_key = md5(($payload['payment_token'] ?? '') . '|' . $event_type . '|failed');
                $last_fail = $order->get_meta('_bytenft_last_fail_key');

                if ($last_fail === $failure_key) {
                    return self::safe_response($order, 'duplicate_failure_ignored', $new_state);
                }

                $order->update_meta_data('_bytenft_last_fail_key', $failure_key);


                $fail_count = (int) $order->get_meta('_bytenft_fail_count');
                $fail_count++;

                $order->update_meta_data('_bytenft_fail_count', $fail_count);
                $order->save();

                self::apply($order, $new_state, $event_type, $payload);
                self::sync_order_notes($order);

                return self::safe_response($order, 'updated', $new_state);
            }

            // NO CHANGE
            if ($new_state === $current_state) {
                return self::safe_response($order, 'no_change', $new_state);
            }

            // TRANSITION CHECK
            if (!self::can_transition($current_state, $new_state)) {
                return self::safe_response($order, 'invalid_transition', $current_state);
            }

            // FINAL GUARD BEFORE ANY WRITE
            if (
                $new_state &&
                $current_state !== 'success' &&
                self::can_transition($current_state, $new_state)
            ) {
                self::push_timeline_event($order, $new_state, $event_type, $payload);
            }

            self::apply($order, $new_state, $event_type, $payload);
            self::sync_order_notes($order);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_transient($lock_key);
        }
    }

    /* =========================================================
     * TIMELINE ENGINE (SOURCE OF TRUTH)
     * ========================================================= */
    private static function push_timeline_event($order, $state, $event_type, $payload)
    {
        $timeline = self::get_timeline($order);

        $timeline[] = [
            'type'          => $state,
            'event_type'    => $event_type,
            'time'          => current_time('mysql'),
            'payment_token' => $payload['payment_token'] ?? null,
        ];

        $order->update_meta_data('_bytenft_timeline', $timeline);
        $order->save();
    }

    /* =========================================================
     * APPLY STATE (WooCommerce sync only)
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        if (self::get_state($order) === 'success') {
            return;
        }

        $order_id = $order->get_id();
        $payment_token = $payload['payment_token'] ?? '';

        $state_lock_key = md5($order_id . '|' . $state . '|' . $payment_token);
        $last_lock = $order->get_meta('_bytenft_state_lock');

        if ($last_lock === $state_lock_key) {
            return;
        }

        $order->update_meta_data('_bytenft_state_lock', $state_lock_key);

        $current_state = self::get_state($order);

        if ($current_state === $state) {
            return;
        }

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'processing' => 'processing',
            'expired'    => 'failed',
            default      => null
        };

        if (!$wc_status) return;

        $order->update_status($wc_status, '');

        $order->update_meta_data('_bytenft_state', $state);
        $order->update_meta_data('_bytenft_last_event', $event_type);
        $order->update_meta_data('_bytenft_last_event_time', current_time('mysql'));

        if (!empty($payment_token)) {
            $order->update_meta_data('_bytenft_pay_id', $payment_token);
        }

        if ($state === 'success') {
            $order->update_meta_data('_bytenft_payment_success', 'yes');
            $order->delete_meta_data('_bytenft_fail_count');
        }

        $order->save();
    }

    private static function get_timeline($order)
    {
        $data = $order->get_meta('_bytenft_timeline', true);

        if (empty($data)) {
            return [];
        }

        // If corrupted string
        if (!is_array($data)) {
            return [];
        }

        // sanitize each entry
        return array_values(array_filter($data, function ($item) {
            return is_array($item) && isset($item['type']);
        }));
    }

    /* =========================================================
     * TIMELINE → ORDER NOTES SYNC
     * ========================================================= */
    private static function sync_order_notes($order)
    {
        $timeline = self::get_timeline($order);

        if (empty($timeline)) return;

        $note_key = md5('timeline_v1|' . json_encode($timeline));
        $last_key = $order->get_meta('_bytenft_note_fingerprint');

        if ($note_key === $last_key) return;

        $order->update_meta_data('_bytenft_note_fingerprint', $note_key);

        $failed    = array_values(array_filter($timeline, fn($e) => $e['type'] === 'failed'));
        $success   = array_values(array_filter($timeline, fn($e) => $e['type'] === 'success'));
        $cancelled = array_values(array_filter($timeline, fn($e) => $e['type'] === 'cancelled'));

        /**
         * FAILED (max 3)
         */
        $count = count($failed);
        $show = min($count, 3);

        for ($i = 0; $i < $show; $i++) {
            $order->add_order_note("Payment attempt #" . ($i + 1) . " failed.");
        }

        if ($count > 3) {
            $order->add_order_note("Payment failed {$count} times.");
        }

        /**
         * SUCCESS overrides everything
         */
        if (!empty($success)) {
            $order->add_order_note("Payment completed successfully.");
            $order->save();
            return;
        }

        /**
         * CANCELLED
         */
        if (!empty($cancelled)) {
            $order->add_order_note("Payment was cancelled.");
            $order->save();
            return;
        }

        $order->save();
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
            'cancelled', 'canceled'        => 'cancelled',
            'expired'                      => 'expired',
            'pending', 'processing'        => 'processing',
            default                        => null
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
     * TRANSITIONS
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if ($from === 'success') return false;
        if ($from === 'processing') return false;

        $map = [
            'pending' => ['cancelled','processing','success','failed'],
            'failed' => ['success','processing','cancelled'],
            'cancelled' => ['success'],
            'expired' => ['failed','cancelled','success'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * CURRENT STATE
     * ========================================================= */
    private static function get_state($order)
    {
        return $order->get_meta('_bytenft_state') ?: 'pending';
    }

    /* =========================================================
     * SUCCESS STATUS
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
     * EVENT ID (webhook dedupe only)
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
        set_transient("bytenft_event_{$order_id}_{$event_id}", 1, self::EVENT_TTL);
    }

    /* =========================================================
     * RESPONSE
     * ========================================================= */
    private static function safe_response($order, $reason, $state = null)
    {
        return [
            'ok' => true,
            'reason' => $reason,
            'order_id' => $order->get_id(),
            'state' => $state ?? self::get_state($order)
        ];
    }

    public static function resolve_final_state($order, $api_status = null)
    {
        // 1. PRIMARY: engine state (validated)
        $state = $order->get_meta('_bytenft_state');

        if (!empty($state) && in_array($state, ['pending','processing','success','failed','cancelled','expired'], true)) {
            return $state;
        }

        // 2. SAFETY: API status (current response)
        if (!empty($api_status)) {
            $mapped = match ($api_status) {
                'success', 'paid', 'completed' => 'success',
                'failed' => 'failed',
                'cancelled', 'canceled' => 'cancelled',
                'processing', 'pending' => 'processing',
                default => null
            };

            if ($mapped) {
                return $mapped;
            }
        }

        // 3. BACKUP: WooCommerce status
        if ($order->has_status(['processing', 'completed'])) {
            return 'success';
        }

        if ($order->has_status(['failed'])) {
            return 'failed';
        }

        if ($order->has_status(['cancelled'])) {
            return 'cancelled';
        }

        return null;
    }
}