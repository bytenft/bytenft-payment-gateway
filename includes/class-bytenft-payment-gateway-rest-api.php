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
	 * Handles incoming ByteNFT API requests to update order status.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function bytenft_handle_api_request(WP_REST_Request $request)
	{
		$method      = $request->get_method();
		$params      = $request->get_params();
		$log_context = ['source' => 'bytenft-payment-gateway'];

		$data = isset($params['api_data']) ? $params['api_data'] : $params;

		$order_id         = intval($data['order_id'] ?? 0);
		$api_order_status = sanitize_text_field($data['order_status'] ?? '');
		$pay_id           = sanitize_text_field($data['pay_id'] ?? '');
		$api_key_raw      = $data['nonce'] ?? '';

		ByteNFT_Payment_Gateway_Logger::info(
			"ByteNFT API HIT | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id}",
			$log_context
		);

		// -------------------------
		// 1. VALIDATION
		// -------------------------
		if ($order_id <= 0) {
			return new WP_REST_Response(['success' => false, 'message' => 'Invalid ID'], 400);
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['success' => false, 'message' => 'Order not found'], 404);
		}

		// -------------------------
		// 2. SECURITY CHECK (POST ONLY)
		// -------------------------
		if ($method === 'POST') {
			$decoded_nonce = base64_decode($api_key_raw);

			if (empty($api_key_raw) || !$this->bytenft_verify_api_key($decoded_nonce)) {
				ByteNFT_Payment_Gateway_Logger::error("ByteNFT SECURITY FAIL #{$order_id}", $log_context);
				return new WP_REST_Response(['success' => false, 'error_code' => 'INVALID_API_KEY'], 401);
			}
		}

		// -------------------------
		// 3. EVENT SOURCE
		// -------------------------
		$event_source = ($method === 'POST') ? 'Webhook' : 'Redirect';
		$event_type = ($method === 'POST')
			? 'webhook_update'
			: 'redirect';

		// -------------------------
		// 4. ENGINE CALL (SINGLE SOURCE OF TRUTH)
		// -------------------------
		$result = BYTENFT_PAYMENT_ENGINE::handle_event(
			$order_id,
			$event_type,
			[
				'status'        => $api_order_status,
				'payment_token' => $pay_id,
				'source'        => $event_source
			]
		);

		ByteNFT_Payment_Gateway_Logger::info(
			"ByteNFT ENGINE RESULT | Order #{$order_id} | " . json_encode($result),
			$log_context
		);

		// -------------------------
		// 5. REFRESH ORDER (AFTER ENGINE)
		// -------------------------
		$order = wc_get_order($order_id);

		// 🔥 PRIMARY SOURCE = ENGINE STATE
		$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state($order, $api_order_status);

		$wc_status = $order->get_status();

		$is_success = ($state === 'success');

		// -------------------------
		// 6. MESSAGE (STATE BASED ONLY)
		// -------------------------
		$message = match ($state) {
			'success'    => 'Payment confirmed successfully.',
			'failed'     => 'Payment failed. Please try again.',
			'cancelled'  => 'Payment was cancelled.',
			'processing' => 'Payment is being processed.',
			default      => 'Payment is being processed.'
		};

		// -------------------------
		// 7. REDIRECT LOGIC (SAFARI SAFE + WEB SAFE)
		// -------------------------
		$redirect = null;

		if ($state === 'success') {
			$redirect = $order->get_checkout_order_received_url();
		} elseif (in_array($state, ['failed', 'cancelled'], true)) {
			$redirect = wc_get_checkout_url();
		}

		// -------------------------
		// 8. RESPONSE HANDLING (IMPORTANT FIX)
		// -------------------------
		return $this->bytenft_finalize_response(
			$method,
			$order,
			$is_success,
			$message,
			$wc_status,
			$redirect
		);
	}

	/**
	 * HELPER: Handles API responses and Safari-safe redirects
	 */
	private function bytenft_finalize_response($method, $order, $success, $message, $target_status = '', $redirect_url = '') 
	{
		// If it's a server-to-server Webhook, just return JSON
		if ($method === 'POST') {
			return new WP_REST_Response(['success' => $success, 'message' => $message], 200);
		}

		// --- SAFARI/SESSION FIX ---
		// If the browser (Safari) lost the session cookie, WooCommerce might not know which 
		// order the user just paid for. We force the session to recognize this order.
		if (isset(WC()->session) && !empty($order)) {
			WC()->session->set('order_awaiting_payment', $order->get_id());
		}

		if (in_array($target_status, ['failed', 'cancelled'])) {
			wc_add_notice('Payment was not completed. Please try again.', 'error');
			wp_safe_redirect(wc_get_checkout_url());
		} else {
			// Send user to the 'Thank You' page
			wp_safe_redirect($order->get_checkout_order_received_url());
		}
		exit;
	}

	/**
	 * HELPER: Simple Status Mapping
	 */
	private function bytenft_map_status($api_status, $success_target) 
	{
		switch ($api_status) {
			case 'completed': return $success_target;
			case 'failed':    return 'failed';
			case 'expired':
			case 'cancelled': return 'cancelled';
			default:          return null;
		}
	}
}