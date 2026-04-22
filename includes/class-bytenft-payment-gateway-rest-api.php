<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

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

	    // $this->logger->info('Raw settings loaded', [
	    //     'source' => 'bytenft-payment-gateway',
	    //     'accounts_data' => $accounts_data,
	    //     'general_settings' => $general_settings,
	    // ]);

	    if (empty($accounts_data)) {
	        $this->logger->warning('No account data found', ['source' => 'bytenft-payment-gateway']);
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
	            $this->logger->warning('Skipping invalid account entry', [
	                'source' => 'bytenft-payment-gateway',
	                'account_id' => $account_id,
	                'account_value' => $account
	            ]);
	            continue;
	        }

	        $public_key = $sandbox
	            ? sanitize_text_field($account['sandbox_public_key'] ?? '')
	            : sanitize_text_field($account['live_public_key'] ?? '');

	        $this->logger->info('Checking public key :: ' . $public_key, [
	            'source' => 'bytenft-payment-gateway',
	            'sandbox' => $sandbox,
	        ]);

	        if (!empty($public_key) && hash_equals($public_key, $api_key)) {
	            $this->logger->info('Keys matched successfully', [
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

		$this->logger->info(
			"ByteNFT: {$method} request for Order #{$order_id} (status: {$api_order_status})",
			$log_context
		);

		// -------------------------------------------------
		// 1. VALIDATION
		// -------------------------------------------------
		if ($order_id <= 0) {
			return new WP_REST_Response(['success' => false, 'message' => 'Invalid Order ID'], 400);
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response(['success' => false, 'message' => 'Order not found'], 404);
		}

		$current_status = $order->get_status();

		// -------------------------------------------------
		// 1.1 PAY ID SAFETY CHECK (NEW IMPORTANT FIX)
		// -------------------------------------------------
		$stored_pay_id = $order->get_meta('_bytenft_pay_id');

		if (!empty($stored_pay_id) && !empty($pay_id)) {

			// If webhook arrives with OLD pay_id → ignore safely
			if ($stored_pay_id !== $pay_id) {

				$this->logger->warning("Stale Pay ID received, ignoring callback", [
					...$log_context,
					'stored_pay_id'  => $stored_pay_id,
					'incoming_pay_id'=> $pay_id,
					'order_id'       => $order_id,
					'status'         => $api_order_status
				]);

				// IMPORTANT: do NOT fail the request
				if ($method === 'POST') {
					return new WP_REST_Response(['success' => true, 'ignored' => 'stale_pay_id'], 200);
				}

				wp_safe_redirect($order->get_checkout_order_received_url());
				exit;
			}
		}

		// -------------------------------------------------
		// 2. STATUS MAPPING (ADMIN CONTROLLED)
		// -------------------------------------------------
		$settings        = get_option('woocommerce_bytenft_settings', []);
		$success_status  = $settings['order_status'] ?? 'processing';

		switch ($api_order_status) {
			case 'completed':
				$target_status = $success_status;
				break;

			case 'failed':
				$target_status = 'failed';
				break;

			case 'expired':
			case 'cancelled':
				$target_status = 'cancelled';
				break;

			default:
				$target_status = null;
		}

		if (!$target_status) {
			if ($method === 'POST') {
				return new WP_REST_Response(['success' => true, 'message' => 'No action needed'], 200);
			}

			wp_safe_redirect($order->get_checkout_order_received_url());
			exit;
		}

		// -------------------------------------------------
		// 3. FINAL STATE RULE
		// -------------------------------------------------
		if ($current_status === 'completed' || $current_status === 'processing') {

			$this->logger->info("Order #{$order_id} already completed. Skipping update.", $log_context);

			if ($method === 'POST') {
				return new WP_REST_Response(['success' => true], 200);
			}

			wp_safe_redirect($order->get_checkout_order_received_url());
			exit;
		}

		// -------------------------------------------------
		// 4. SECURITY (POST ONLY)
		// -------------------------------------------------
		if ($method === 'POST') {
			$decoded_nonce = base64_decode($api_key_raw);

			if (empty($api_key_raw) || !$this->bytenft_verify_api_key($decoded_nonce)) {

				$this->logger->error("INVALID_API_KEY for Order #{$order_id}", $log_context);

				return new WP_REST_Response([
					'success' => false,
					'error_code' => 'INVALID_API_KEY'
				], 401);
			}
		}

		// -------------------------------------------------
		// 5. DUPLICATE CALLBACK PROTECTION
		// -------------------------------------------------
		$last_status = $order->get_meta('_bytenft_last_status');

		if ($last_status === $api_order_status) {

			$this->logger->info("Duplicate callback ignored for Order #{$order_id}", $log_context);

			if ($method === 'POST') {
				return new WP_REST_Response(['success' => true], 200);
			}

			wp_safe_redirect($order->get_checkout_order_received_url());
			exit;
		}

		// -------------------------------------------------
		// 6. CONCURRENCY LOCK
		// -------------------------------------------------
		$lock_key = 'bytenft_lock_' . $order_id;

		if (get_transient($lock_key)) {

			$this->logger->info("Order #{$order_id} locked, skipping concurrent request.", $log_context);

			if ($method === 'POST') {
				return new WP_REST_Response(['success' => true], 200);
			}

			wp_safe_redirect($order->get_checkout_order_received_url());
			exit;
		}

		set_transient($lock_key, true, 15);

		try {

			$order = wc_get_order($order_id);
			$current_status = $order->get_status();

			if ($current_status === 'completed') {
				delete_transient($lock_key);

				if ($method === 'POST') {
					return new WP_REST_Response(['success' => true], 200);
				}

				wp_safe_redirect($order->get_checkout_order_received_url());
				exit;
			}

			$source = ($method === 'POST') ? 'Webhook/API' : 'Customer Redirect';

			$note  = "ByteNFT Payment Update\n";
			$note .= "Status: {$api_order_status}\n";
			$note .= "Source: {$source}\n";

			$order->update_status($target_status, $note);

			// IMPORTANT: ALWAYS overwrite latest pay_id
			if (!empty($pay_id)) {
				$order->update_meta_data('_bytenft_pay_id', $pay_id);
			}

			$order->update_meta_data('_bytenft_last_status', $api_order_status);
			$order->save();

			$this->logger->info("Order #{$order_id} updated → {$target_status}", $log_context);

		} catch (\Exception $e) {

			$this->logger->error("Order #{$order_id} update failed: " . $e->getMessage(), $log_context);

		} finally {
			delete_transient($lock_key);
		}

		// -------------------------------------------------
		// 7. RESPONSE
		// -------------------------------------------------
		if ($method === 'POST') {
			return new WP_REST_Response(['success' => true], 200);
		}

		if (in_array($target_status, ['failed', 'cancelled'])) {
			wc_add_notice('Payment failed or cancelled. Please try again.', 'error');
			wp_safe_redirect(wc_get_checkout_url());
		} else {
			wp_safe_redirect($order->get_checkout_order_received_url());
		}

		exit;
	}
}
