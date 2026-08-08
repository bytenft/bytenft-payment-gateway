<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'class-bytenft-payment-state-engine.php';

class BYTENFT_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();
		

		add_action('rest_api_init', function () {
			// Remove WordPress's default CORS headers
			remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

			// Add custom CORS headers
			add_filter('rest_pre_serve_request', function ($value) {

			    header('Access-Control-Allow-Origin: '.BYTENFT_BASE_URL);
			    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
			    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, User-Agent, Accept');
			    header('Access-Control-Allow-Credentials: true');

			   // Safely get the request method
					$request_method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
					$request_method = $request_method ? strtoupper($request_method) : '';

					// Handle preflight request
					if ($request_method === 'OPTIONS') {
						status_header(200);
						exit;
					}

			    return $value;
			}, 15);
		    });
	}

	public function bytenft_register_routes()
	{
		// Log incoming request with sanitized parameters
		add_action('rest_api_init', function () {
			register_rest_route('bytenft/v1', '/data', array(
				'methods' => ['GET', 'POST'],
				'callback' => array($this, 'bytenft_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	private function bytenft_verify_api_key($api_key)
	{
	    $api_key = sanitize_text_field($api_key);

	    // Retrieve plugin options
	    $accounts_data = get_option('woocommerce_bytenft_payment_gateway_accounts');
	    $general_settings = get_option('woocommerce_bytenft_settings');

	    if (empty($accounts_data)) {
	        ByteNFT_Payment_Gateway_Logger::warning('No account data found', ['source' => 'bytenft-payment-gateway']);
	        return false;
	    }

	    // If it's a single account array, wrap it inside an array for consistency
	    if (isset($accounts_data['live_public_key']) || isset($accounts_data['sandbox_public_key'])) {
	        $accounts_data = [ $accounts_data ];
	    }

	    $sandbox = isset($general_settings['sandbox']) && $general_settings['sandbox'] === 'yes';

	    foreach ($accounts_data as $account_id => $account) {
	        // Ensure valid array
	        if (!is_array($account)) {
	            ByteNFT_Payment_Gateway_Logger::warning('Skipping invalid account entry', [
	                'source' => 'bytenft-payment-gateway',
	                'account_id' => $account_id,
	                'account_value' => $account
	            ]);
	            continue;
	        }

	        $public_key = $sandbox
	            ? sanitize_text_field($account['sandbox_public_key'] ?? '')
	            : sanitize_text_field($account['live_public_key'] ?? '');

	        ByteNFT_Payment_Gateway_Logger::info('Checking public key :: ' . $public_key, [
	            'source' => 'bytenft-payment-gateway',
	            'sandbox' => $sandbox,
	        ]);

	        if (!empty($public_key) && hash_equals($public_key, $api_key)) {
	            ByteNFT_Payment_Gateway_Logger::info('Keys matched successfully', [
	                'source' => 'bytenft-payment-gateway',
	                'account_id' => $account_id,
	            ]);
	            return true;
	        }
	    }

	    return false;
	}

	/**
     * Handles incoming ByteNFT API requests (Webhooks & Browser Redirects).
     *
     * @param WP_REST_Request $request The REST API request object.
     * @return WP_REST_Response|void
     */
    public function bytenft_handle_api_request(WP_REST_Request $request)
    {
        $method      = $request->get_method();
        $params      = $request->get_params();
        $log_context = ['source' => 'bytenft-payment-gateway'];

        $data = (isset($params['api_data']) && is_array($params['api_data']))
			? $params['api_data']
			: $params;

        $order_id         = intval($data['order_id'] ?? 0);
        $api_order_status = sanitize_text_field($data['order_status'] ?? '');
        $pay_id           = sanitize_text_field($data['pay_id'] ?? '');
        $api_key_raw      = $data['nonce'] ?? '';

        // -------------------------
        // 1. VALIDATION
        // -------------------------
        if ($order_id <= 0) {
            ByteNFT_Payment_Gateway_Logger::warning(
                "ByteNFT API REJECTED | Invalid Order ID: {$order_id}",
                $log_context
            );

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid Order ID'
            ], 400);
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            ByteNFT_Payment_Gateway_Logger::warning(
                "ByteNFT API REJECTED | Order #{$order_id} not found in WooCommerce",
                $log_context
            );

            return new WP_REST_Response([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Safe initial logging after $order object is verified
        ByteNFT_Payment_Gateway_Logger::info(
            "ByteNFT BROWSER RETURN | Order #{$order_id} | " .
            "Method: {$method} | " .
            "API Status: {$api_order_status} | " .
            "Pay ID: {$pay_id} | " .
            "WC Status: {$order->get_status()} | " .
            "Engine State: " . $order->get_meta('_bytenft_state') . " | " .
            "Payment Success: " . $order->get_meta('_bytenft_payment_success'),
            $log_context
        );

        // -------------------------
        // 2. SECURITY CHECK (POST Webhooks Only)
        // -------------------------
        if ($method === 'POST') {
            $decoded_nonce = base64_decode($api_key_raw, true);

           if (
				empty($api_key_raw) ||
				$decoded_nonce === false ||
				!$this->bytenft_verify_api_key($decoded_nonce)
			) {
                ByteNFT_Payment_Gateway_Logger::warning(
                    "ByteNFT API SECURITY FAILURE | Order #{$order_id} | Invalid API Key/Nonce",
                    $log_context
                );

                return new WP_REST_Response([
                    'success'    => false,
                    'error_code' => 'INVALID_API_KEY'
                ], 401);
            }
        }

		if (!in_array($method, ['GET', 'POST'], true)) {
			ByteNFT_Payment_Gateway_Logger::warning(
				"ByteNFT API REJECTED | Unsupported HTTP Method: {$method}",
				$log_context
			);

			return new WP_REST_Response([
				'success' => false,
				'message' => 'Method not allowed'
			], 405);
		}

        // -------------------------
        // 3. EVENT TYPE & FALLBACK STATUS
        // -------------------------
        $event_type   = ($method === 'POST') ? 'webhook_update' : 'redirect';
        $event_source = ($method === 'POST') ? 'Webhook' : 'Redirect';

        // Fallback status for GET requests if empty: check if WC order was already completed by Webhook
        $effective_status = $api_order_status;
        if (empty($effective_status) && $method === 'GET') {
            if ($order->has_status(['processing', 'completed']) || $order->get_meta('_bytenft_payment_success') === 'yes') {
                $effective_status = 'completed';
            }
        }

        // -------------------------
        // 4. ENGINE CALL
        // -------------------------
        if (!empty($effective_status)) {
            $engine_result = BYTENFT_PAYMENT_ENGINE::handle_event(
                $order_id,
                $event_type,
                [
                    'status'        => $effective_status,
                    'payment_token' => $pay_id,
                    'source'        => $event_source
                ]
            );

            ByteNFT_Payment_Gateway_Logger::info(
                "ByteNFT ENGINE RESULT | Order #{$order_id} | Result: " . json_encode($engine_result),
                $log_context
            );
        }

        // -------------------------
        // 5. REFRESH ORDER & RESOLVE STATE
        // -------------------------
        $order = wc_get_order($order_id);

        $state = BYTENFT_PAYMENT_ENGINE::resolve_final_state(
            $order,
            $effective_status
        );

        $wc_status = $order->get_status();

        // -------------------------
        // 6. SUCCESS OVERRIDE
        // -------------------------
        if (in_array($wc_status, ['processing', 'completed'], true) && $order->get_meta('_bytenft_payment_success') === 'yes') {
            $state = 'success';
        }

        $is_success = ($state === 'success');

        ByteNFT_Payment_Gateway_Logger::info(
            "ByteNFT BROWSER REDIRECT DECISION | Order #{$order_id} | " .
            "State: {$state} | " .
            "Success: " . ($is_success ? 'YES' : 'NO') . " | " .
            "Target: " . ($is_success
                ? $order->get_checkout_order_received_url()
                : wc_get_checkout_url()),
            $log_context
        );

        // -------------------------
        // 7. MESSAGE GENERATION
        // -------------------------
        $message = match ($state) {
            'success'    => 'Payment confirmed successfully.',
            'failed'     => 'Payment failed. Please try again.',
            'cancelled'  => 'Payment was cancelled.',
            'processing' => 'Payment is being processed.',
            'pending'    => 'Payment is pending.',
            default      => 'Payment status is being verified.'
        };

        // -------------------------
        // 8. REDIRECT URL CALCULATION
        // -------------------------
        $redirect = null;
        if ($is_success) {
            $redirect = $order->get_checkout_order_received_url();
        } elseif (in_array($state, ['failed', 'cancelled'], true)) {
            $redirect = wc_get_checkout_url();
        }

        ByteNFT_Payment_Gateway_Logger::info(
            "ByteNFT FINAL RESPONSE | Order #{$order_id} | State: {$state} | WC: {$wc_status} | Method: {$method} | Redirect: {$redirect}",
            $log_context
        );

        // -------------------------
        // 9. FINALIZE RESPONSE
        // -------------------------
        return $this->bytenft_finalize_response(
            $method,
            $order,
            $is_success,
            $message,
            $state,
            $wc_status,
            $redirect
        );
    }

    /**
     * HELPER: Handles API responses for POST webhooks and performs HTTP redirects for GET browser returns
     */
    private function bytenft_finalize_response(
        $method,
        $order,
        $is_success,
        $message,
        $state = '',
        $wc_status = '',
        $redirect_url = ''
    ) {
        $log_context = ['source' => 'bytenft-payment-gateway'];
        $order_id    = $order ? $order->get_id() : 0;

        /*
        * ----------------------------------------------------
        * SERVER-TO-SERVER WEBHOOK (POST)
        * ----------------------------------------------------
        */
        if ($method === 'POST') {
            ByteNFT_Payment_Gateway_Logger::info(
                "ByteNFT WEBHOOK RESPONSE SENT | Order #{$order_id} | Success: " .
                ($is_success ? 'YES' : 'NO') .
                " | State: {$state} | WC: {$wc_status}",
                $log_context
            );

            return new WP_REST_Response([
                'success' => $is_success,
                'message' => $message
            ], 200);
        }

        /*
        * ----------------------------------------------------
        * BROWSER RETURN (GET)
        * ----------------------------------------------------
        */
        if (!$order) {
            ByteNFT_Payment_Gateway_Logger::error(
                "ByteNFT BROWSER REDIRECT FAILED | Order not available",
                $log_context
            );

            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        /*
        * Preserve session and persist notice state before REST exit
        */
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('order_awaiting_payment', $order_id);
            if (method_exists(WC()->session, 'set_customer_session_cookie')) {
                WC()->session->set_customer_session_cookie(true);
            }
        }

        /*
        * ----------------------------------------------------
        * SUCCESS REDIRECT
        * ----------------------------------------------------
        */
        if ($is_success) {
            // Empty the cart on successful customer return
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            $thank_you_url = $redirect_url ? esc_url_raw($redirect_url) : $order->get_checkout_order_received_url();

            ByteNFT_Payment_Gateway_Logger::info(
                "ByteNFT BROWSER REDIRECT | Order #{$order_id} | " .
                "SUCCESS | State: {$state} | WC: {$wc_status} | " .
                "Target: {$thank_you_url}",
                $log_context
            );

            if (function_exists('WC') && WC()->session) {
                WC()->session->save_data();
            }

            wp_safe_redirect($thank_you_url);
            exit;
        }

        /*
        * ----------------------------------------------------
        * FAILED / CANCELLED REDIRECT
        * ----------------------------------------------------
        */
        $checkout_url = $redirect_url ? esc_url_raw($redirect_url) : wc_get_checkout_url();

        ByteNFT_Payment_Gateway_Logger::warning(
            "ByteNFT BROWSER REDIRECT | Order #{$order_id} | " .
            "NOT SUCCESS | State: {$state} | WC: {$wc_status} | " .
            "Target: {$checkout_url}",
            $log_context
        );

        if (function_exists('WC') && WC()->session) {
            wc_add_notice(__('Payment was not completed. Please try again.', 'woocommerce'), 'error');
            WC()->session->save_data(); // Persist notices before exit
        }

        wp_safe_redirect($checkout_url);
        exit;
    }
}