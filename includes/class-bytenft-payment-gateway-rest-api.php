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

	        $live_pub = sanitize_text_field($account['live_public_key'] ?? '');
	        $sand_pub = sanitize_text_field($account['sandbox_public_key'] ?? '');
	        $live_sec = sanitize_text_field($account['live_secret_key'] ?? '');
	        $sand_sec = sanitize_text_field($account['sandbox_secret_key'] ?? '');

	        if (
	            (!empty($live_pub) && hash_equals($live_pub, $api_key)) ||
	            (!empty($sand_pub) && hash_equals($sand_pub, $api_key)) ||
	            (!empty($live_sec) && hash_equals($live_sec, $api_key)) ||
	            (!empty($sand_sec) && hash_equals($sand_sec, $api_key))
	        ) {
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
	 * GET  = Browser redirect flow (PRIMARY CUSTOMER REDIRECT)
	 * POST = Webhook flow (FALLBACK / SERVER-TO-SERVER)
	 *
	 * Business rule:
	 * - WooCommerce "processing" = ByteNFT payment success.
	 * - WooCommerce "completed" = ByteNFT payment success.
	 * - _bytenft_payment_success = yes = ByteNFT payment success.
	 * - Webhook NEVER performs a browser redirect.
	 * - Browser redirect ALWAYS goes to Thank You when payment is confirmed.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response|void
	 */
	public function bytenft_handle_api_request(WP_REST_Request $request)
	{
		$method      = strtoupper($request->get_method());
		$params      = $request->get_params();
		$log_context = ['source' => 'bytenft-payment-gateway'];

		$data = isset($params['api_data'])
			? $params['api_data']
			: $params;

		$order_id_raw     = trim((string)($data['order_id'] ?? 0), " \t\n\r\0\x0B\"'");
		$order_id         = intval($order_id_raw);
		$api_order_status = trim(sanitize_text_field($data['order_status'] ?? ''), " \t\n\r\0\x0B\"'");
		$pay_id           = trim(sanitize_text_field($data['pay_id'] ?? ''), " \t\n\r\0\x0B\"'");
		$api_key_raw      = trim((string)($data['nonce'] ?? ''), " \t\n\r\0\x0B\"'");

		ByteNFT_Payment_Gateway_Logger::info(
			"ByteNFT API HIT | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id} | Method: {$method}",
			$log_context
		);

		// ---------------------------------------------------------
		// 1. VALIDATE ORDER ID
		// ---------------------------------------------------------

		if ($order_id <= 0) {
			if ($method === 'POST') {
				update_option('bytenft_webhook_validation_status', 'failed');
				update_option('bytenft_last_webhook_status', 'failed');
				update_option('bytenft_webhook_failure_reason', 'Invalid order ID in webhook payload');
			}

			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid ID',
			], 400);
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			if ($method === 'POST') {
				update_option('bytenft_webhook_validation_status', 'failed');
				update_option('bytenft_last_webhook_status', 'failed');
				update_option('bytenft_webhook_failure_reason', 'Referenced order not found in WooCommerce');
			}

			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order not found',
			], 404);
		}

		// ---------------------------------------------------------
		// 2. WEBHOOK SECURITY
		// ---------------------------------------------------------

		if ($method === 'POST') {

			$decoded_nonce = base64_decode($api_key_raw, true);
			$is_key_valid  = (
				(!empty($decoded_nonce) && $this->bytenft_verify_api_key($decoded_nonce)) ||
				(!empty($api_key_raw) && $this->bytenft_verify_api_key($api_key_raw))
			);

			if ( empty($api_key_raw) || !$is_key_valid ) {
				update_option('bytenft_webhook_validation_status', 'failed');
				update_option('bytenft_last_webhook_status', 'failed');
				update_option('bytenft_webhook_failure_reason', 'Invalid API key or signature in webhook');

				ByteNFT_Payment_Gateway_Logger::info(
					"ByteNFT API | Order #{$order_id} | Invalid API key",
					$log_context
				);

				return new WP_REST_Response([
					'success'    => false,
					'error_code' => 'INVALID_API_KEY',
				], 401);
			}
		}

		// ---------------------------------------------------------
		// 3. EVENT TYPE
		// ---------------------------------------------------------

		$is_webhook = ($method === 'POST');

		$event_type = $is_webhook
			? 'webhook_update'
			: 'redirect';

		$event_source = $is_webhook
			? 'Webhook'
			: 'Redirect';

		// ---------------------------------------------------------
		// 4. PROCESS ENGINE EVENT
		// ---------------------------------------------------------

		$result = null;

		if (!empty($api_order_status)) {

			$result = BYTENFT_PAYMENT_ENGINE::handle_event(
				$order_id,
				$event_type,
				[
					'status'        => $api_order_status,
					'payment_token' => $pay_id,
					'source'        => $event_source,
				]
			);

			ByteNFT_Payment_Gateway_Logger::info(
				"ByteNFT ENGINE RESULT | Order #{$order_id} | " .
				wp_json_encode($result),
				$log_context
			);
		}

		// ---------------------------------------------------------
		// 5. ALWAYS RELOAD ORDER AFTER ENGINE
		// ---------------------------------------------------------

		$order = wc_get_order($order_id);

		if (!$order) {
			if ($is_webhook) {
				update_option('bytenft_webhook_validation_status', 'failed');
				update_option('bytenft_last_webhook_status', 'failed');
				update_option('bytenft_webhook_failure_reason', 'Order disappeared during webhook execution');
			}

			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order no longer exists',
			], 404);
		}

		$wc_status = $order->get_status();

		// ---------------------------------------------------------
		// 5b. WEBHOOK AUTOMATIC VALIDATION STATE RECORDING
		// ---------------------------------------------------------
		if ($is_webhook) {
			$is_engine_success = !empty($result) && !empty($result['success']);
			$is_engine_locked  = is_array($result) && (($result['reason'] ?? '') === 'locked_skip');
			$is_engine_done    = is_array($result) && in_array(($result['reason'] ?? ''), ['final_success_locked', 'duplicate_event_ignored'], true);

			if (!empty($api_order_status) && ($is_engine_success || $is_engine_locked || $is_engine_done || !empty($wc_status))) {
				update_option('bytenft_webhook_verified', true);
				update_option('bytenft_webhook_validation_status', 'passed');
				update_option('bytenft_webhook_last_verified_at', current_time('mysql'));
				update_option('bytenft_webhook_last_order_id', $order_id);
				update_option('bytenft_last_webhook_status', 'success');
				delete_option('bytenft_webhook_failure_reason');
			} else {
				update_option('bytenft_webhook_validation_status', 'failed');
				update_option('bytenft_last_webhook_status', 'failed');
				update_option('bytenft_webhook_failure_reason', 'Failed to update order status from webhook');
			}
		}

		// ---------------------------------------------------------
		// 6. RESOLVE FINAL BYTE NFT STATE
		// ---------------------------------------------------------
		//
		// IMPORTANT BUSINESS RULE:
		//
		// processing  = SUCCESS
		// completed   = SUCCESS
		//
		// This is intentional and must remain because your plugin
		// considers both WC states successful after ByteNFT payment.
		// ---------------------------------------------------------

		$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state(
			$order,
			$api_order_status
		);

		// ---------------------------------------------------------
		// 7. SUCCESS SAFETY OVERRIDE
		// ---------------------------------------------------------
		//
		// If another request (usually webhook) already completed
		// the order while this request was running, treat it as
		// successful immediately.
		//
		// This is what protects the redirect flow from webhook/
		// redirect race conditions.
		// ---------------------------------------------------------

		if (
			in_array($wc_status, ['processing', 'completed'], true) ||
			$order->get_meta('_bytenft_payment_success') === 'yes' ||
			$order->get_meta('_bytenft_state') === 'success'
		) {
			$state = 'success';
		}

		// ---------------------------------------------------------
		// 8. IF ENGINE LOCKED, RE-READ ORDER
		// ---------------------------------------------------------
		//
		// Do NOT treat locked_skip as a failure.
		//
		// Another request may currently be updating the same order.
		// The safest action is to reload the order and use the
		// persisted state.
		// ---------------------------------------------------------

		if (
			is_array($result) &&
			($result['reason'] ?? '') === 'locked_skip'
		) {

			$order = wc_get_order($order_id);

			if ($order) {

				$wc_status = $order->get_status();

				if (
					in_array($wc_status, ['processing', 'completed'], true) ||
					$order->get_meta('_bytenft_payment_success') === 'yes' ||
					$order->get_meta('_bytenft_state') === 'success'
				) {
					$state = 'success';
				} else {
					$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state(
						$order,
						$api_order_status
					);
				}
			}
		}

		$is_success = ($state === 'success');

		// ---------------------------------------------------------
		// 9. MESSAGE
		// ---------------------------------------------------------

		$message = match ($state) {

			'success' =>
				'Payment confirmed successfully.',

			'failed' =>
				'Payment failed. Please try again.',

			'cancelled' =>
				'Payment was cancelled.',

			'processing' =>
				'Payment is being processed.',

			'pending' =>
				'Payment is pending.',

			default =>
				'Payment status is being verified.',
		};

		// ---------------------------------------------------------
		// 10. REDIRECT
		// ---------------------------------------------------------
		//
		// ONLY THE BROWSER/GET REQUEST CAN REDIRECT.
		//
		// Webhook receives the same success state but returns JSON.
		// It must NEVER redirect because there is no customer browser
		// attached to a server-to-server webhook.
		// ---------------------------------------------------------

		$redirect = null;

		if ($state === 'success') {

			$redirect = $order->get_checkout_order_received_url();
			update_option('bytenft_successful_payment_verified', true);
			update_option('bytenft_thankyou_page_verified', true);

		} elseif (
			in_array($state, ['failed', 'cancelled', 'expired'], true)
		) {
			update_option('bytenft_last_payment_status', 'failed');
			update_option('bytenft_thankyou_page_status', 'failed');
			$redirect = wc_get_checkout_url();
		}

		ByteNFT_Payment_Gateway_Logger::info(
			"ByteNFT FINAL RESPONSE | Order #{$order_id} | " .
			"State: {$state} | WC: {$wc_status} | " .
			"Success: " . ($is_success ? 'yes' : 'no') . " | " .
			"Redirect: " . ($redirect ?: 'none') .
			" | Method: {$method}",
			$log_context
		);

		// ---------------------------------------------------------
		// 11. FINAL RESPONSE
		// ---------------------------------------------------------

		return $this->bytenft_finalize_response(
			$method,
			$order,
			$is_success,
			$message,
			$state,
			$redirect
		);
	}


	/**
	 * Finalizes ByteNFT API response.
	 *
	 * POST = webhook -> JSON only.
	 * GET  = browser -> redirect customer.
	 *
	 * @param string    $method
	 * @param WC_Order  $order
	 * @param bool      $success
	 * @param string    $message
	 * @param string    $target_status
	 * @param string    $redirect_url
	 *
	 * @return WP_REST_Response|void
	 */
	private function bytenft_finalize_response(
		$method,
		$order,
		$success,
		$message,
		$target_status = '',
		$redirect_url = ''
	) {
		// ---------------------------------------------------------
		// WEBHOOK
		// ---------------------------------------------------------
		//
		// Server-to-server request.
		// NEVER redirect here.
		// ---------------------------------------------------------

		if (strtoupper($method) === 'POST') {

			return new WP_REST_Response([
				'success' => $success,
				'message' => $message,
			], 200);
		}

		// ---------------------------------------------------------
		// BROWSER REQUEST
		// ---------------------------------------------------------

		if (empty($order)) {

			wp_safe_redirect(
				wc_get_checkout_url()
			);

			exit;
		}

		// ---------------------------------------------------------
		// SAFARI / WOOCOMMERCE SESSION
		// ---------------------------------------------------------

		if (
			function_exists('WC') &&
			WC()->session
		) {
			WC()->session->set(
				'order_awaiting_payment',
				$order->get_id()
			);
		}

		// ---------------------------------------------------------
		// SUCCESS
		// ---------------------------------------------------------
		//
		// This is the PRIMARY redirect.
		//
		// If ByteNFT says success OR WC has already moved to
		// processing/completed OR success meta exists,
		// send customer to Thank You.
		// ---------------------------------------------------------

		if (
			$target_status === 'success' ||
			$success === true ||
			$order->has_status(['processing', 'completed']) ||
			$order->get_meta('_bytenft_payment_success') === 'yes' ||
			$order->get_meta('_bytenft_state') === 'success'
		) {

			if (
				function_exists('WC') &&
				WC()->cart
			) {
				WC()->cart->empty_cart();
			}

			$thank_you_url = $order->get_checkout_order_received_url();

			ByteNFT_Payment_Gateway_Logger::info(
				"[Order #{$order->get_id()}] Browser Redirect | SUCCESS | Redirecting to Thank You: {$thank_you_url}"
			);

			wp_safe_redirect($thank_you_url);

			exit;
		}

		// ---------------------------------------------------------
		// FAILED / CANCELLED / EXPIRED
		// ---------------------------------------------------------

		if (
			in_array(
				$target_status,
				['failed', 'cancelled', 'expired'],
				true
			)
		) {

			wc_add_notice(
				'Payment was not completed. Please try again.',
				'error'
			);

			wp_safe_redirect(
				wc_get_checkout_url()
			);

			exit;
		}

		// ---------------------------------------------------------
		// PROCESSING / PENDING
		// ---------------------------------------------------------
		//
		// Do NOT send the customer to checkout.
		//
		// The payment is not yet confirmed by the persisted state.
		// Frontend polling can continue and redirect once success
		// is confirmed.
		// ---------------------------------------------------------

		return new WP_REST_Response([
			'success' => false,
			'message' => $message,
			'data' => [
				'state'    => $target_status ?: 'processing',
				'order_id' => $order->get_id(),
			],
		], 200);
	}
}