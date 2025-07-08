<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Class BYTENFT_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the Bytenft Payment Gateway plugin.
 */
class BYTENFT_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $base_url;

	/**
	 * Get the singleton instance of this class.
	 * @return BYTENFT_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->base_url = BYTENFT_BASE_URL;

		$this->admin_notices = new BYTENFT_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'bytenft_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'bytenft_init'], 11);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_check_payment_status', array($this, 'bytenft_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_check_payment_status', array($this, 'bytenft_handle_check_payment_status_request'));

		add_action('wp_ajax_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_bytenft_manual_sync', [$this, 'bytenft_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'bytenft_add_cron_interval']);
		add_action('bytenft_cron_event', [$this, 'handle_cron_event']);

		add_action('wp_ajax_send_payment_link', [$this, 'bytenft_send_payment_link']);
		add_action('wp_ajax_nopriv_send_payment_link', [$this, 'bytenft_send_payment_link']);
	}


	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function bytenft_init()
	{
		// Check if the environment is compatible
		$environment_warning = bytenft_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->bytenft_init_gateways();

		// Initialize REST API
		$rest_api = BYTENFT_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->bytenft_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(BYTENFT_PAYMENT_GATEWAY_FILE), [$this, 'bytenft_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'bytenft_plugin_row_meta'], 10, 2);
	}

	/**
	 * Initialize gateways.
	 */
	private function bytenft_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bytenft-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'BYTENFT_PAYMENT_GATEWAY';			
			return $methods;
		});
	}


	private function get_api_url($endpoint)
	{
		return $this->base_url . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function bytenft_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bytenft')) . '">' . esc_html__('Settings', 'bytenft-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}
	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function bytenft_plugin_row_meta($links, $file)
	{
		if (plugin_basename(BYTENFT_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('bytenft_docs_url', BYTENFT_BASE_URL.'/api/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'bytenft-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bytenft_support_url', BYTENFT_BASE_URL.'/reach-out')) . '" target="_blank">' . esc_html__('Support', 'bytenft-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function bytenft_handle_environment_check()
	{
		$environment_warning = bytenft_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->bytenft_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function bytenft_handle_check_payment_status_request($request)
	{
		// Verify nonce for security (recommended)
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'bytenft_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'bytenft-payment-gateway')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->bytenft_check_payment_status($order_id);
	}

	public function bytenft_check_payment_status($order_id)
	{
	    global $wpdb;

	    $logger_context = ['source' => 'bytenft-payment-gateway', 'order_id' => $order_id];

	    // 1. Get order
	    $order = wc_get_order($order_id);
	    if (!$order) {
	        wc_get_logger()->error("Order not found", $logger_context);
	        return new WP_REST_Response(['error' => 'Order not found'], 404);
	    }

	    $payment_return_url = esc_url($order->get_checkout_order_received_url());

	    // 2. Get stored payment data
	    $pay_id     = base64_decode($order->get_meta('_bytenft_pay_id'));
	    $public_key = $order->get_meta('_bytenft_public_key');
	    $secret_key = $order->get_meta('_bytenft_secret_key');

	    if (empty($pay_id) || empty($public_key) || empty($secret_key)) {
	        wc_get_logger()->warning("Missing stored payment metadata.", $logger_context);
	        return new WP_REST_Response([
	            'success' => false,
	            'error' => 'Missing payment data for the order.',
	        ], 400);
	    }

	    // 3. Skip if already in final state
	    if ($order->is_paid()) {
	        wc_get_logger()->info("Order already paid", $logger_context);
	        wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
	    }

	    if ($order->has_status(['failed', 'canceled', 'refunded'])) {
	        wc_get_logger()->info("Order is in final state: " . $order->get_status(), $logger_context);
	        wp_send_json_success(['status' => $order->get_status(), 'redirect_url' => $payment_return_url]);
	    }

	    // 4. Call status API
	    $url = trailingslashit($this->base_url) . 'api/orders/' . $pay_id . '/status';

	    $response = wp_remote_get($url, [
	        'timeout' => 20,
	        'headers' => [
	            'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
	            'x-api-secret'  => sanitize_text_field($secret_key),
	        ],
	    ]);

	    // 5. Handle error
	    if (is_wp_error($response)) {
	        wc_get_logger()->error("HTTP error while checking status: " . $response->get_error_message(), $logger_context);
	        wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
	    }

	    $response_body = wp_remote_retrieve_body($response);
	    $status_data = json_decode($response_body, true);

	    // 6. Validate response
	    if (!isset($status_data['status']) || $status_data['status'] !== 'success' || !isset($status_data['data']['payment_status'])) {
	        wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
	    }

	    $payment_status = strtolower($status_data['data']['payment_status']);
	    wc_get_logger()->info("Payment status for $pay_id: $payment_status", $logger_context);

	    // 7. Update WooCommerce order status
	    if ($payment_status === 'success') {
	        $order->payment_complete();
	        wp_send_json_success(['status' => 'success', 'redirect_url' => $payment_return_url]);
	    }

	    if (in_array($payment_status, ['failed', 'cancelled', 'canceled'])) {
	        $order->update_status('failed', __('Payment failed via API status check', 'bytenft-payment-gateway'));
	        wp_send_json_success(['status' => 'failed', 'redirect_url' => $payment_return_url]);
	    }

	    if (in_array($payment_status, ['pending', 'on-hold'])) {
	        $order->update_status('pending', __('Awaiting payment confirmation.', 'bytenft-payment-gateway'));
	        wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
	    }

	    // 8. Fallback
	    wp_send_json_success(['status' => 'pending', 'redirect_url' => $payment_return_url]);
	}


	public function handle_popup_close()
	{
		// Sanitize and unslash the 'security' value
		$security = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';

		// Check the nonce for security
		if (empty($security) || !wp_verify_nonce($security, 'bytenft_payment')) {
			wp_send_json_error(['message' => 'Nonce verification failed.']);
			wp_die();
		}

		// Get the order ID from the request
		$order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : null;

		// Validate order ID
		if (!$order_id) {
			wp_send_json_error(['message' => 'Order ID is missing.']);
			wp_die();
		}

		// Fetch the WooCommerce order
		$order = wc_get_order($order_id);

		// Check if the order exists
		if (!$order) {
			wp_send_json_error(['message' => 'Order not found in WordPress.']);
			wp_die();
		}

		//Get uuid from WP
		$payment_token = $order->get_meta('_bytenft_pay_id');

		// Proceed only if the order status is 'pending'
		if ($order->get_status() === 'pending') {
			// Call the DFin Sell to update status
			$transactionStatusApiUrl = $this->get_api_url('/api	');
			$response = wp_remote_post($transactionStatusApiUrl, [
				'method'    => 'POST',
				'body'      => wp_json_encode(['order_id' => $order_id, 'payment_token' => $payment_token]),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],
				'timeout'   => 15,
			]);

			// Check for errors in the API request
			if (is_wp_error($response)) {
				wp_send_json_error(['message' => 'Failed to connect to the DFin Sell.']);
				wp_die();
			}

			// Parse the API response
			$response_body = wp_remote_retrieve_body($response);
			$response_data = json_decode($response_body, true);

			$log_message = 'Popup closed. Transaction status received from DFin Sell.';

			wc_get_logger()->info($log_message, [
				'source'  => 'bytenft-payment-gateway',
				'context' => [
					'order_id'           => $order_id,
					'transaction_status' => $response_data['transaction_status'] ?? 'unknown'
				],
			]);

			// Ensure the response contains the expected data
			if (!isset($response_data['transaction_status'])) {
				wp_send_json_error(['message' => 'Invalid response from DFin Sell.']);
				wp_die();
			}

			// Get the configured order status from the payment gateway settings
			$gateway_id = 'bytenft'; // Replace with your gateway ID
			$payment_gateways = WC()->payment_gateways->payment_gateways();
			if (isset($payment_gateways[$gateway_id])) {
				$gateway = $payment_gateways[$gateway_id];
				$configured_order_status = sanitize_text_field($gateway->get_option('order_status'));
			} else {
				wp_send_json_error(['message' => 'Payment gateway not found.']);
				wp_die();
			}

			// Validate the configured order status
			$allowed_statuses = wc_get_order_statuses();
			if (!array_key_exists('wc-' . $configured_order_status, $allowed_statuses)) {
				wp_send_json_error(['message' => 'Invalid order status configured: ' . esc_html($configured_order_status)]);
				wp_die();
			}

			$payment_return_url = esc_url($order->get_checkout_order_received_url());


			if (isset($response_data['transaction_status'])) {
				// Handle transaction status from API
				switch ($response_data['transaction_status']) {
					case 'success':
					case 'paid':
					case 'processing':
						// Update the order status based on the selected value
						try {
							$order->update_status($configured_order_status, 'Order marked as ' . $configured_order_status . ' by DFin Sell.');
							wp_send_json_success(['message' => 'Order status updated successfully.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;

					case 'failed':
						try {
							$order->update_status('failed', 'Order marked as failed by DFin Sell.');
							wp_send_json_success(['message' => 'Order status updated to failed.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;
					case 'canceled':
					case 'expired':
						try {
							$order->update_status('canceled', 'Order marked as canceled by DFin Sell.');
							wp_send_json_success(['message' => 'Order status updated to canceled.', 'order_id' => $order_id, 'redirect_url' => $payment_return_url]);
						} catch (Exception $e) {
							wp_send_json_error(['message' => 'Failed to update order status: ' . $e->getMessage()]);
						}
						break;
					default:
						wp_send_json_error(['message' => 'Unknown transaction status received.']);
				}
			}
		} else {
			// Skip API call if the order status is not 'pending'
			wp_send_json_success(['message' => 'No update required as the order status is not pending.', 'order_id' => $order_id]);
		}

		wp_die();
	}

	/**
     * Add custom cron schedules.
     */


	public function bytenft_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'bytenft-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		wc_get_logger()->info('Automatic payment status checks have been enabled.', ['source' => 'bytenft-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('bytenft_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'bytenft_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'bytenft_cron_event');
	}

	function deactivate_cron_job()
	{
		wc_get_logger()->info('Automatic payment status checks have been disabled.', ['source' => 'bytenft-payment-gateway']);
		wp_clear_scheduled_hook('bytenft_cron_event');
	}


	public function handle_cron_event()
	{
		$logger_context = ['source' => 'bytenft-payment-gateway'];

		$accounts = get_option('woocommerce_bytenft_payment_gateway_accounts');
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			wc_get_logger()->warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			return [];
		}

		$accountsData = [];

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// Prepare both live and sandbox entries
			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'],
					'public_key'   => $account['live_public_key'],
					'secret_key'   => $account['live_secret_key'],
					'mode'         => 'live',
				];
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
				$accountsData[] = [
					'account_name' => $account['title'],
					'public_key'   => $account['sandbox_public_key'],
					'secret_key'   => $account['sandbox_secret_key'],
					'mode'         => 'sandbox',
				];
			}
		}

		if (empty($accountsData)) {
			wc_get_logger()->warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		$url = esc_url($this->base_url . '/api/sync-account-status');
		$response = wp_remote_post($url, [
			'headers' => [
				'Content-Type'  => 'application/json',
			],
			'body' => json_encode(['accounts' => $accountsData]),
			'timeout' => 15,
		]);

		if (is_wp_error($response)) {
			wc_get_logger()->error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
			return [];
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		$updated = false;
		$statusSummary = [];

		if (!empty($response_data['statuses'])) {
			foreach ($response_data['statuses'] as $statusData) {
				if (
					isset($statusData['mode'], $statusData['public_key'], $statusData['status']) &&
					!empty($statusData['status'])
				) {
					foreach ($accounts as &$account) {
						if (
							$statusData['mode'] === 'live' &&
							$account['live_public_key'] === $statusData['public_key']
						) {
							$account['live_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}

						if (
							$statusData['mode'] === 'sandbox' &&
							$account['sandbox_public_key'] === $statusData['public_key']
						) {
							$account['sandbox_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}
					}
				}
			}
		}

		if (!empty($statusSummary)) {
			if ($updated) {
				update_option('woocommerce_bytenft_payment_gateway_accounts', $accounts);

				wc_get_logger()->info('Payment account statuses were successfully updated after syncing.', [
					'source'  => 'bytenft-payment-gateway',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				wc_get_logger()->info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'bytenft-payment-gateway',
					'context' => ['checked_accounts' => $statusSummary],
				]);
			}
		} else {
			wc_get_logger()->info('Sync completed. No account status data was returned from the server.', $logger_context);
		}

		return $statusSummary;
	}


	function bytenft_manual_sync_callback()
	{
		$logger_context = ['source' => 'bytenft-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('bytenft_sync_nonce', 'nonce', false)) {
			wc_get_logger()->error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'bytenft-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
		wc_get_logger()->error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'bytenft-payment-gateway')
			], 403);
			wp_die();
		}

		wc_get_logger()->info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				wc_get_logger()->warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			wc_get_logger()->info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'  => __('Payment accounts synchronized successfully.', 'bytenft-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			wc_get_logger()->error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'bytenft-payment-gateway') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}

	function bytenft_send_payment_link() {
		$email = sanitize_email($_POST['email']);
		$phone = sanitize_text_field($_POST['phone']);
		$payment_link = esc_url_raw($_POST['payment_link']);
		$order_id = sanitize_text_field($_POST['order_id']);

		if (empty($email) && empty($phone)) {
			wp_send_json_error(['message' => 'Please provide email or phone.']);
		}

		// 👇 Prepare the payload to send to your external API
		$payload = [
			'email' => $email,
			'phone' => $phone,
			'payment_link' => $payment_link,
			'order_id' => $order_id,
		];


	    // 1. Get order
	    $order = wc_get_order($order_id);
	    if (!$order) {
	        wc_get_logger()->error("Order not found");
	        return new WP_REST_Response(['error' => 'Order not found'], 404);
	    }

	    // 2. Get stored payment data
	    $public_key = $order->get_meta('_bytenft_public_key');
	    $secret_key = $order->get_meta('_bytenft_secret_key');
		
	    // 4. Call status API
	    $url = trailingslashit($this->base_url) . 'api/re-send/payment-link';

	    $response = wp_remote_post($url, [
	        'timeout' => 20,
	        'headers' => [
	            'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
	            'x-api-secret'  => sanitize_text_field($secret_key),
	        ],
			'body' => json_encode($payload),
	    ]);

		if (is_wp_error($response)) {
			wp_send_json_error(['message' => 'Failed to connect to API.']);
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$response_data = json_decode(wp_remote_retrieve_body($response), true);
		
		if ($status_code === 200 && !empty($response_data['success'])) {
			wp_send_json_success(['message' => 'Link sent successfully.']);
		}

		// Handle API error
		wp_send_json_error([
			'message' => $body['message'] ?? 'Failed to send payment link via API.',
		]);
	}

}
