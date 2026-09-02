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

            if ($current_state === 'success') {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            // ONLY apply state change (NO NOTES HERE)
            $can_transition = self::can_transition($current_state, $new_state);

            /**
             * ALWAYS allow success recovery
             */
            if ($new_state === 'success') {
                $can_transition = true;
            }

            /**
             * BLOCK invalid transitions
             */
            if (!$can_transition) {

                return self::safe_response(
                    $order,
                    'invalid_transition_blocked',
                    $current_state
                );
            }

            /**
             * APPLY STATE
             */
            self::apply($order, $new_state, $event_type, $payload);

            /**
             * PUSH TIMELINE
             */
            if ($new_state === 'failed') {

                $existing = self::timeline_has_state_for_token(
                    $order,
                    'failed',
                    $payload['payment_token'] ?? ''
                );

                if (!$existing) {
                    self::push_timeline_event(
                        $order,
                        $new_state,
                        $event_type,
                        $payload
                    );
                }

            } else {

                self::push_timeline_event(
                    $order,
                    $new_state,
                    $event_type,
                    $payload
                );
            }

            // SINGLE SOURCE OF TRUTH FOR NOTES
            self::sync_order_notes($order);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_transient($lock_key);
        }
    }

    private static function timeline_has_state_for_token($order, $state, $payment_token)
    {
        if (empty($payment_token)) {
            return false;
        }

        $timeline = self::get_timeline($order);

        foreach ($timeline as $event) {

            if (
                ($event['type'] ?? '') === $state &&
                ($event['payment_token'] ?? '') === $payment_token
            ) {
                return true;
            }
        }

        return false;
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

        // stable idempotency key (VERY IMPORTANT)
        $state_lock_key = md5($order_id . '|' . $state . '|' . $payment_token);

        $last_lock = $order->get_meta('_bytenft_state_lock');

        if ($last_lock === $state_lock_key) {
            return;
        }

        $order->update_meta_data('_bytenft_state_lock', $state_lock_key);

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
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

        if (empty($timeline)) {
            return;
        }

        $failed_events = [];
        $success_event = null;
        $cancel_event  = null;
        $seen_failed   = [];

        /**
         * SINGLE PASS TIMELINE PARSE
         */
        foreach ($timeline as $event) {

            $type  = $event['type'] ?? '';
            $token = trim((string) ($event['payment_token'] ?? ''));

            /**
             * FAILED EVENTS
             */
            if ($type === 'failed') {

                if (
                    !empty($token) &&
                    !isset($seen_failed[$token])
                ) {
                    $seen_failed[$token] = true;

                    $failed_events[] = $event;
                }
            }

            /**
             * SUCCESS EVENT
             */
            if (
                $type === 'success' &&
                !$success_event
            ) {
                $success_event = $event;
            }

            /**
             * CANCEL EVENT
             */
            if (
                $type === 'cancelled' &&
                !$cancel_event
            ) {
                $cancel_event = $event;
            }
        }

        /**
         * FAILED NOTES
         */
        $already_synced = (int) $order->get_meta('_bytenft_failed_note_count');

        $actual_failed_count = count($failed_events);

        if ($actual_failed_count > $already_synced) {

            for ($i = $already_synced; $i < $actual_failed_count; $i++) {

                $event = $failed_events[$i];

                $attempt_number = $i + 1;

                $order->add_order_note(
                    self::build_order_note(
                        "Payment Failed (Attempt {$attempt_number})",
                        $event['payment_token'] ?? '',
                        $event['event_type'] ?? ''
                    )
                );
            }

            $order->update_meta_data(
                '_bytenft_failed_note_count',
                $actual_failed_count
            );
        }

        /**
         * SUCCESS NOTE
         */
        if (
            $success_event &&
            !$order->get_meta('_bytenft_success_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment completed successfully.',
                    $success_event['payment_token'] ?? '',
                    $success_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_bytenft_success_note_added',
                'yes'
            );
        }

        /**
         * CANCEL NOTE
         */
        if (
            $cancel_event &&
            !$order->get_meta('_bytenft_cancel_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment was cancelled.',
                    $cancel_event['payment_token'] ?? '',
                    $cancel_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_bytenft_cancel_note_added',
                'yes'
            );
        }

        $order->save();
    }

    private static function build_order_note(
        $title,
        $payment_token,
        $event_type
    ) {

        $source = match ($event_type) {
            'popup_close'    => 'Customer Return from Payment Page',
            'redirect'       => 'Customer Redirect',
            'webhook_update' => 'Webhook',
            default          => 'System'
        };

        // Decode Base64 payment token if possible
        $decoded_payment_id = base64_decode($payment_token, true);

        if (empty($decoded_payment_id)) {
            $decoded_payment_id = $payment_token;
        }

        return sprintf(
            '<strong>ByteNFT Gateway</strong><br><br>
            <strong>%s</strong><br><br>
            <strong>Payment ID:</strong> %s<br>
            <strong>Updated Via:</strong> %s<br>
            <strong>Recorded At:</strong> %s',
            esc_html($title),
            esc_html($decoded_payment_id),
            esc_html($source),
            esc_html(current_time('F j, Y \a\t g:i A'))
        );
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
            'failed' => ['failed','success','processing','cancelled'],
            'cancelled' => ['success','failed'],
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
            $payload['payment_token'] ?? ''
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