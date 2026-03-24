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
				'methods' => 'POST',
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
		$parameters = $request->get_json_params();

		// Sanitize incoming data
		$api_key_raw       = $parameters['nonce'] ?? '';
		$api_key           = sanitize_text_field($api_key_raw);
		$order_id          = isset($parameters['order_id']) ? intval($parameters['order_id']) : 0;
		$api_order_status  = sanitize_text_field($parameters['order_status'] ?? '');
		$pay_id            = sanitize_text_field($parameters['pay_id'] ?? '');

		// Base log context for easier tracing
		$log_context = [
			'source' => 'bytenft-payment-gateway',
			'order_id' => $order_id,
			'api_status' => $api_order_status,
			'pay_id' => $pay_id,
		];

		$this->logger->info('API Request Received', $log_context);

		// --------------------------
		// 1️⃣ Validate API key
		// --------------------------
		if (empty($api_key) || !$this->bytenft_verify_api_key(base64_decode($api_key))) {
			$this->logger->error('Invalid API key', $log_context);

			return new WP_REST_Response([
				'success' => false,
				'error_code' => 'INVALID_API_KEY',
				'message' => 'Authentication failed. Please check API key configuration.'
			], 401);
		}

		// --------------------------
		// 2️⃣ Validate order ID
		// --------------------------
		if ($order_id <= 0) {
			$this->logger->error('Invalid order ID', $log_context);

			return new WP_REST_Response([
				'success' => false,
				'error_code' => 'INVALID_ORDER_ID',
				'message' => 'Order ID is missing or invalid.'
			], 400);
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			$this->logger->error('Order not found', $log_context);

			return new WP_REST_Response([
				'success' => false,
				'error_code' => 'ORDER_NOT_FOUND',
				'message' => 'Order not found in WooCommerce.'
			], 404);
		}

		// --------------------------
		// 3️⃣ Validate Pay ID
		// --------------------------
		$stored_payment_token = $order->get_meta('_bytenft_pay_id');

		if (!empty($stored_payment_token) && $stored_payment_token !== $pay_id) {
			$this->logger->error('Pay ID mismatch', [
				...$log_context,
				'stored_pay_id' => $stored_payment_token
			]);

			return new WP_REST_Response([
				'success' => false,
				'error_code' => 'PAY_ID_MISMATCH',
				'message' => 'Payment verification failed. Pay ID does not match.'
			], 400);
		}

		// --------------------------
		// 4️⃣ Map API status to WooCommerce status
		// --------------------------
		// Admin-selected success status
		$settings = get_option('woocommerce_bytenft_settings', []);
		$success_status = isset($settings['order_status']) ? sanitize_text_field($settings['order_status']) : 'processing';

		switch ($api_order_status) {
			case 'completed':
				$target_order_status = $success_status; // use admin-selected
				break;

			case 'failed':
				$target_order_status = 'failed';
				break;

			case 'expired':
				$target_order_status = 'expired'; // must register this status
				break;

			case 'cancelled':
				$target_order_status = 'cancelled';
				break;

			default:
				$target_order_status = null;
		}

		if (!$target_order_status) {
			$this->logger->warning('Unknown API status', $log_context);

			return new WP_REST_Response([
				'success' => true,
				'error_code' => 'UNKNOWN_STATUS',
				'message' => 'Status received but no action taken.'
			], 200);
		}

		// --------------------------
		// 5️⃣ Idempotency check
		// --------------------------
		$current_status = $order->get_status();

		if ($current_status === $target_order_status) {
			$this->logger->info('Order already in target status', $log_context);

			return new WP_REST_Response([
				'success' => true,
				'message' => 'Order already in correct status.',
				'status' => $current_status,
				'payment_return_url' => $order->get_checkout_order_received_url()
			], 200);
		}

		// --------------------------
		// 6️⃣ Update order safely
		// --------------------------
		try {
			$order->update_status(
				$target_order_status,
				"Updated via ByteNFT API ({$api_order_status})"
			);

			$this->logger->info('Order updated successfully', [
				...$log_context,
				'from' => $current_status,
				'to' => $target_order_status
			]);

		} catch (\Exception $e) {
			$this->logger->error('Order update failed', [
				...$log_context,
				'error' => $e->getMessage()
			]);

			return new WP_REST_Response([
				'success' => false,
				'error_code' => 'UPDATE_FAILED',
				'message' => 'Failed to update order. Please check WooCommerce logs.'
			], 500);
		}

		// --------------------------
		// 7️⃣ Return response
		// --------------------------
		return new WP_REST_Response([
			'success' => true,
			'status' => $target_order_status,
			'message' => "Order status mapped: {$api_order_status} → {$target_order_status}",
			'payment_return_url' => $order->get_checkout_order_received_url()
		], 200);
	}
}
