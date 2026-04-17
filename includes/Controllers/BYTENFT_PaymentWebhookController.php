<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_PaymentWebhookController
{
    private $logger;

    public function __construct()
    {
        $this->logger = wc_get_logger();
    }

    /**
     * Register REST routes
     */
    public function register()
    {
        add_action('rest_api_init', function () {

            register_rest_route('bytenft/v1', '/data', [
                'methods'             => ['GET', 'POST'],
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);

            // CORS handled cleanly here
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

            add_filter('rest_pre_serve_request', [$this, 'cors_headers'], 15);
        });
    }

    /**
     * CORS handler (clean separation)
     */
    public function cors_headers($value)
    {
        header('Access-Control-Allow-Origin: ' . BYTENFT_BASE_URL);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        header('Access-Control-Allow-Credentials: true');

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');

        if ($method === 'OPTIONS') {
            status_header(200);
            exit;
        }

        return $value;
    }

    /**
     * MAIN HANDLER (Stripe-style thin controller)
     */
    public function handle(WP_REST_Request $request)
    {
        $method = $request->get_method();
        $params = $request->get_params();

        $data = $params['api_data'] ?? $params;

        $order_id        = (int) ($data['order_id'] ?? 0);
        $api_status      = sanitize_text_field($data['order_status'] ?? '');
        $pay_id          = sanitize_text_field($data['pay_id'] ?? '');
        $api_key_raw     = $data['nonce'] ?? '';

        $this->logger->info("Webhook received for Order #{$order_id}", [
            'source' => 'bytenft-webhook'
        ]);

        if (!$order_id) {
            return new WP_REST_Response(['success' => false], 400);
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_REST_Response(['success' => false], 404);
        }

        // 🔐 SECURITY (delegated later to service if needed)
        if ($method === 'POST') {
            $decoded = base64_decode($api_key_raw);

            if (!$this->verify_api_key($decoded)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => 'INVALID_API_KEY'
                ], 401);
            }
        }

        // 🔁 IDEMPOTENCY
        if ($order->get_meta('_bytenft_last_status') === $api_status) {
            return new WP_REST_Response(['success' => true], 200);
        }

        // 🔒 LOCK (race protection)
        $lock_key = "bytenft_lock_{$order_id}";
        if (get_transient($lock_key)) {
            return new WP_REST_Response(['success' => true], 200);
        }

        set_transient($lock_key, true, 15);

        try {

            $target_status = $this->map_status($api_status);

            if (!$target_status) {
                return new WP_REST_Response(['success' => true], 200);
            }

            if ($order->has_status('completed')) {
                return new WP_REST_Response(['success' => true], 200);
            }

            $note = "ByteNFT Update\nStatus: {$api_status}\nTX: {$pay_id}";

            $order->update_status($target_status, $note);

            if ($pay_id) {
                $order->update_meta_data('_bytenft_pay_id', $pay_id);
            }

            $order->update_meta_data('_bytenft_last_status', $api_status);
            $order->save();

        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), [
                'source' => 'bytenft-webhook'
            ]);
        } finally {
            delete_transient($lock_key);
        }

        if ($method === 'POST') {
            return new WP_REST_Response(['success' => true], 200);
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    /**
     * STATUS MAPPER (Stripe-style clean separation)
     */
    private function map_status($status)
    {
        $settings = get_option('woocommerce_bytenft_settings', []);
        $success  = $settings['order_status'] ?? 'processing';

        return match ($status) {
            'completed'  => $success,
            'failed'     => 'failed',
            'expired'    => 'cancelled',
            'cancelled'  => 'cancelled',
            default      => null,
        };
    }

    /**
     * API KEY VALIDATION (temporary inline, move to service later)
     */
    private function verify_api_key($api_key)
    {
        $accounts = get_option('woocommerce_bytenft_payment_gateway_accounts');

        if (is_string($accounts)) {
            $accounts = maybe_unserialize($accounts);
        }

        if (!is_array($accounts)) {
            return false;
        }

        foreach ($accounts as $account) {

            $keys = [
                $account['live_public_key'] ?? '',
                $account['sandbox_public_key'] ?? ''
            ];

            foreach ($keys as $key) {
                if (!empty($key) && hash_equals($key, $api_key)) {
                    return true;
                }
            }
        }

        return false;
    }
}