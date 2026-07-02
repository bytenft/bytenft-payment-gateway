<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-bytenft-payment-state-engine.php';
require_once plugin_dir_path(__FILE__) . 'class-bytenft-payment-logger.php';
/**
 * Class BYTENFT_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the ByteNFT Payment Gateway plugin.
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
		add_action('plugins_loaded', [$this, 'bytenft_init'], 10);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_bytenft_check_payment_status', array($this, 'bytenft_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_bytenft_check_payment_status', array($this, 'bytenft_handle_check_payment_status_request'));

		add_action('wp_ajax_bytenft_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_bytenft_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_bytenft_manual_sync', [$this, 'bytenft_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'bytenft_add_cron_interval']);
		add_action('bytenft_cron_event', [$this, 'handle_cron_event']);
		add_action('wp_ajax_bytenft_block_gateway_process', [$this,'handle_bytenft_gateway_ajax']);
		add_action('wp_ajax_nopriv_bytenft_block_gateway_process', [$this,'handle_bytenft_gateway_ajax']); 
		add_action('wp', function () {
		    // Allow notices ONLY on checkout page
		    if ( ! is_checkout() ) {
			remove_action(
			    'woocommerce_before_checkout_form',
			    'woocommerce_output_all_notices',
			    10
			);
			// Clear queued notices (errors, success, info)
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
		    }

		});

		add_action('woocommerce_checkout_create_order', function($order){
			$order->delete_meta_data('_wc_order_attribution_session_entry');
		}, 10);
		add_action('init', function() {
			if (function_exists('WC') && WC()->session == null) {
				WC()->initialize_session();
			}
		});

		add_action('woocommerce_before_checkout_form', [$this, 'bytenft_show_checkout_error']);
	}

	/**
	 * ── FIXED ──────────────────────────────────────────────────────────────────
	 * Handle the block checkout AJAX payment request.
	 *
	 * Root cause of "No available payment accounts":
	 * `new BYTENFT_PAYMENT_GATEWAY()` creates a cold instance. In an AJAX
	 * context WooCommerce has not called init_settings() on it, so
	 * $this->sandbox defaults to false and get_option() returns empty values.
	 * get_next_available_account() then finds no matching keys → returns false.
	 *
	 * Fix: pull the already-booted instance from WC()->payment_gateways().
	 * That instance was fully initialised during the normal WC boot cycle so
	 * sandbox mode and account keys are correct.
	 * ───────────────────────────────────────────────────────────────────────────
	 */
	function handle_bytenft_gateway_ajax(){

		// Nonce verification
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (empty($nonce) || !wp_verify_nonce($nonce, 'bytenft_payment')) {
			wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
			die;
		}

		// Pull the already-initialised gateway from the WC registry.
		// Never use `new BYTENFT_PAYMENT_GATEWAY()` here — see note above.
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$bytenftPayment = $gateways['bytenft'] ?? null;

		if (!$bytenftPayment) {
			// Fallback: manually instantiate and force-load settings from DB.
			// Should never happen in normal operation.
			$bytenftPayment = new BYTENFT_PAYMENT_GATEWAY();
			$bytenftPayment->init_settings();
			$bytenftPayment->load_gateway_settings();

			ByteNFT_Payment_Gateway_Logger::warning(
				'ByteNFT: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
				['source' => 'bytenft-payment-gateway-main']
			);
		}

		ByteNFT_Payment_Gateway_Logger::info(
			'Session Debug',
			[
				'context' => [
					'store_api_draft_order' => WC()->session ? WC()->session->get('store_api_draft_order') : null,
					'order_awaiting_payment' => WC()->session ? WC()->session->get('order_awaiting_payment') : null,
					'posted' => $_POST,
				]
			]
		);

		$orderID = 0;
		if (WC()->session) {
			$orderID = WC()->session->get('store_api_draft_order');
			if (!$orderID) {
				$orderID = WC()->session->get('order_awaiting_payment');
			}
		}

		// Fallback: If it's a draft order OR if no order was found in the session, create/update the order with cart details and customer details
		if (function_exists('wc_get_order') && !empty(WC()->cart) && !WC()->cart->is_empty()) {
			$order = $orderID ? wc_get_order($orderID) : false;
			if (!$order) {
				// Create a new draft order
				$order = wc_create_order();
				if ($order) {
					$orderID = $order->get_id();
				}
			}

			// Sync Cart & Customer to the Order if it is a draft or pending order
			if ($order && ($order->has_status('checkout-draft') || $order->has_status('pending'))) {
				try {
					// Clear existing items to avoid duplicates
					$order->remove_order_items();

					// Add products from cart
					foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
						$item = new WC_Order_Item_Product();
						$item->set_product($values['data']);
						$item->set_quantity($values['quantity']);
						$item->set_total($values['line_total']);
						$item->set_subtotal($values['line_subtotal']);
						$order->add_item($item);
					}

					// Copy billing details from Customer session
					$customer = WC()->customer;
					if ($customer) {
						$order->set_billing_first_name($customer->get_billing_first_name() ?: sanitize_text_field($_POST['billing_first_name'] ?? ''));
						$order->set_billing_last_name($customer->get_billing_last_name() ?: sanitize_text_field($_POST['billing_last_name'] ?? ''));
						$order->set_billing_company($customer->get_billing_company() ?: sanitize_text_field($_POST['billing_company'] ?? ''));
						$order->set_billing_address_1($customer->get_billing_address_1() ?: sanitize_text_field($_POST['billing_address_1'] ?? ''));
						$order->set_billing_address_2($customer->get_billing_address_2() ?: sanitize_text_field($_POST['billing_address_2'] ?? ''));
						$order->set_billing_city($customer->get_billing_city() ?: sanitize_text_field($_POST['billing_city'] ?? ''));
						$order->set_billing_state($customer->get_billing_state() ?: sanitize_text_field($_POST['billing_state'] ?? ''));
						$order->set_billing_postcode($customer->get_billing_postcode() ?: sanitize_text_field($_POST['billing_postcode'] ?? ''));
						$order->set_billing_country($customer->get_billing_country() ?: sanitize_text_field($_POST['billing_country'] ?? 'US'));
						$order->set_billing_email($customer->get_billing_email() ?: sanitize_text_field($_POST['billing_email'] ?? ''));
						$order->set_billing_phone($customer->get_billing_phone() ?: sanitize_text_field($_POST['billing_phone'] ?? ''));

						$order->set_shipping_first_name($customer->get_shipping_first_name() ?: sanitize_text_field($_POST['shipping_first_name'] ?? ''));
						$order->set_shipping_last_name($customer->get_shipping_last_name() ?: sanitize_text_field($_POST['shipping_last_name'] ?? ''));
						$order->set_shipping_company($customer->get_shipping_company() ?: sanitize_text_field($_POST['shipping_company'] ?? ''));
						$order->set_shipping_address_1($customer->get_shipping_address_1() ?: sanitize_text_field($_POST['shipping_address_1'] ?? ''));
						$order->set_shipping_address_2($customer->get_shipping_address_2() ?: sanitize_text_field($_POST['shipping_address_2'] ?? ''));
						$order->set_shipping_city($customer->get_shipping_city() ?: sanitize_text_field($_POST['shipping_city'] ?? ''));
						$order->set_shipping_state($customer->get_shipping_state() ?: sanitize_text_field($_POST['shipping_state'] ?? ''));
						$order->set_shipping_postcode($customer->get_shipping_postcode() ?: sanitize_text_field($_POST['shipping_postcode'] ?? ''));
						$order->set_shipping_country($customer->get_shipping_country() ?: sanitize_text_field($_POST['shipping_country'] ?? 'US'));
					}

					// Set order currency
					$order->set_currency(get_woocommerce_currency());

					// Calculate totals
					$order->calculate_totals();
					$order->save();
				} catch (Exception $e) {
					// Ignore
				}
			}
		}

		$status = [];
		if($orderID){
			$status = $bytenftPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'bytenft-payment-gateway-main'), 'error');
			$status = ['result' => 'fail','error' => 'Invalid order.'];
		}
		
		wp_send_json($status);
		die;
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

		// Register blocks gateway
		$this->bytenft_init_blocks();
		
		add_action( 'enqueue_block_assets', [ $this, 'register_blocks_assets' ] );

		// Initialize REST API
		$rest_api = BYTENFT_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->bytenft_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(BYTENFT_PAYMENT_GATEWAY_FILE), [$this, 'bytenft_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'bytenft_plugin_row_meta'], 10, 2);
	}

	public function bytenft_show_checkout_error()
	{
		if (!function_exists('WC')) return;

		$error = WC()->session->get('bytenft_error');
		if (!$error) return;

		$messages = [
			'failed'    => 'Payment failed. Please try again.',
			'cancelled' => 'Payment was cancelled.',
			'expired'   => 'Payment session expired. Please try again.'
		];

		// Clear error immediately
		WC()->session->__unset('bytenft_error');

		if (isset($messages[$error])) {
			wc_add_notice($messages[$error], 'error');
		}
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

	private function bytenft_init_blocks() {
		
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

				require_once BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-bytenft-blocks-gateway.php';

				add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {
					$registry->register( new BYTENFT_Blocks_Gateway() );
				});
			}
	
	}
	
	public function register_blocks_assets() {
		
		if (is_checkout()) {
			$image_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/loader.gif';
			wp_register_script(
				'bytenft-blocks-js',
				plugin_dir_url( BYTENFT_PAYMENT_GATEWAY_FILE ) . 'assets/js/bytenft-blocks.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				'1.0.0',
				true
			);

			$settings = get_option( 'woocommerce_bytenft_settings', [] );

			wp_localize_script(
				'bytenft-blocks-js',
				'bytenft_params',
				[ 'settings' => $settings,
				 'ajax_url' => admin_url('admin-ajax.php'),
				 'bytenft_loader' => $image_url,
				 'bytenft_nonce' => wp_create_nonce('bytenft_payment'), 
				 'checkout_url' => wc_get_checkout_url(),
				 'payment_method' => 'bytenft' 
				]
			);
	
		}
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
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bytenft')) . '">' . esc_html__('Settings', 'bytenft-payment-gateway-main') . '</a>',
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
				'docs'    => '<a href="' . esc_url(apply_filters('bytenft_docs_url', 'https://pay.bytenft.xyz/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'bytenft-payment-gateway-main') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bytenft_support_url', 'https://pay.bytenft.xyz/contact-us')) . '" target="_blank">' . esc_html__('Support', 'bytenft-payment-gateway-main') . '</a>',
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
		check_ajax_referer('bytenft_payment', 'security');

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'bytenft-payment-gateway-main')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->bytenft_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function bytenft_check_payment_status($order_id)
	{
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response([
				'error' => esc_html__('Order not found', 'bytenft-payment-gateway-main')
			], 404);
		}

		$security = isset($_POST['security'])
			? sanitize_text_field(wp_unslash($_POST['security']))
			: '';

		$log_prefix = "[Order #{$order_id}]";

		// -------------------------
		// NONCE CHECK
		// -------------------------
		if (empty($security) || !wp_verify_nonce($security, 'bytenft_payment')) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' CheckStatus | Invalid nonce'
			);

			wp_send_json_error([
				'message' => 'Nonce verification failed.'
			]);

			wp_die();
		}

		// -------------------------
		// API CALL
		// -------------------------
		$payment_token = $order->get_meta('_bytenft_pay_id');

		$response = wp_remote_post(
			$this->get_api_url('/api/update-txn-status'),
			[
				'method'  => 'POST',
				'body'    => wp_json_encode([
					'order_id'      => $order_id,
					'payment_token' => $payment_token
				]),
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' CheckStatus | API error'
			);

			wp_send_json_error([
				'message' => 'API connection failed.'
			]);

			wp_die();
		}

		$response_data = json_decode(
			wp_remote_retrieve_body($response),
			true
		);

		if (!is_array($response_data)) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' CheckStatus | Invalid API response'
			);

			wp_send_json_error([
				'message' => 'Invalid API response.'
			]);

			wp_die();
		}

		$payment_status =
			$response_data['transaction_status']
			?? $response_data['payment_status']
			?? null;

		// -------------------------
		// ENGINE CALL
		// -------------------------
		if ($payment_status) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . " CheckStatus | Engine trigger ({$payment_status})"
			);

			$result = BYTENFT_PAYMENT_ENGINE::handle_event(
				$order_id,
				'redirect_check',
				[
					'status'        => $payment_status,
					'payment_token' => $payment_token,
				]
			);

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . " CheckStatus | Engine result: " . json_encode($result)
			);
		}

		// -------------------------
		// REFRESH ORDER
		// -------------------------
		$order = wc_get_order($order_id);

		$wc_status = $order->get_status();

		$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state(
			$order,
			$payment_status
		);

		/**
		 * SUCCESS ALWAYS WINS
		 */
		if ($order->has_status(['processing', 'completed'])) {
			$state = 'success';
		}

		// -------------------------
		// REDIRECT
		// -------------------------
		$redirect = null;

		if ($order->has_status(['processing', 'completed'])) {

			$redirect = $order->get_checkout_order_received_url();

		} elseif (in_array($state, ['failed', 'cancelled'], true)) {

			$redirect = wc_get_checkout_url();
		}

		// -------------------------
		// RESPONSE
		// -------------------------
		wp_send_json_success([
			'status'        => $state,
			'payment_status'=> $payment_status,
			'order_status'  => $wc_status,
			'redirect_url'  => $redirect,
		]);

		wp_die();
	}

	private function bytenft_log($message, $context = [])
	{
		if (function_exists('wc_get_logger')) {
			ByteNFT_Payment_Gateway_Logger::info(
				$message,
				array_merge([
					'source' => 'bytenft-payment-gateway-main'
				], $context)
			);
		}
	}

	public function handle_popup_close()
	{
		$order_id = isset($_POST['order_id'])
			? sanitize_text_field(wp_unslash($_POST['order_id']))
			: 'unknown';

		$security = isset($_POST['security'])
			? sanitize_text_field(wp_unslash($_POST['security']))
			: '';

		$log_prefix = "[Order #{$order_id}]";

		// -------------------------
		// NONCE CHECK
		// -------------------------
		if (empty($security) || !wp_verify_nonce($security, 'bytenft_payment')) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' PopupClose | Invalid nonce'
			);

			wp_send_json_error([
				'reload' => true
			]);

			wp_die();
		}

		// -------------------------
		// ORDER CHECK
		// -------------------------
		$order = wc_get_order($order_id);

		if (!$order) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' PopupClose | Order not found'
			);

			wp_send_json_error([
				'reload' => true
			]);

			wp_die();
		}

		// -------------------------
		// API CALL
		// -------------------------
		$payment_token = $order->get_meta('_bytenft_active_pay_id');

		if (empty($payment_token)) {
			$payment_token = $order->get_meta('_bytenft_pay_id');
		}

		$response = wp_remote_post(
			$this->get_api_url('/api/update-txn-status'),
			[
				'method'  => 'POST',
				'body'    => wp_json_encode([
					'order_id'      => $order_id,
					'payment_token' => $payment_token
				]),
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' PopupClose | API error'
			);

			wp_send_json_error([
				'reload' => true
			]);

			wp_die();
		}

		$response_data = json_decode(
			wp_remote_retrieve_body($response),
			true
		);

		if (!is_array($response_data)) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' PopupClose | Invalid API response'
			);

			wp_send_json_error([
				'reload' => true
			]);

			wp_die();
		}

		$payment_status =
			$response_data['payment_status']
			?? $response_data['transaction_status']
			?? null;

		// -------------------------
		// NO STATUS
		// -------------------------
		if (!$payment_status) {

			wp_send_json([
				'success' => false,
				'message' => 'Payment was not completed.',
				'data' => [
					'payment_status' => 'abandoned',
					'order_status'   => $order->get_status(),
					'state'          => 'abandoned',
					'redirect'       => null,
				]
			]);

			wp_die();
		}

		// -------------------------
		// ENGINE CALL
		// -------------------------
		$result = BYTENFT_PAYMENT_ENGINE::handle_event(
			$order_id,
			'popup_close',
			[
				'status'        => $payment_status,
				'payment_token' => $payment_token,
			]
		);

		// ❌ REMOVE locked_skip handling completely

		ByteNFT_Payment_Gateway_Logger::info(
			$log_prefix . ' PopupClose | Engine result: ' . wp_json_encode($result)
		);

		// Ignore lock races and wait for next poll
		if (
			is_array($result) &&
			(($result['reason'] ?? '') === 'locked_skip')
		)
		{
			$order = wc_get_order($order_id);

			$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state($order);

			wp_send_json([
				'success' => ($state === 'success'),
				'message' => '',
				'data' => [
					'state'          => $state ?: 'processing',
					'payment_status' => $payment_status,
					'redirect'       => $state === 'success'
						? $order->get_checkout_order_received_url()
						: null,
					'order_id'       => $order_id,
				],
			]);

			wp_die();
		}

		// -------------------------
		// ALWAYS RELOAD ORDER AFTER ENGINE
		// -------------------------
		$order = wc_get_order($order_id);

		// 🔥 PRIMARY STATE = ENGINE STORED STATE ONLY
		$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state($order);

		// -------------------------
		// HARD OVERRIDE SAFETY (ONLY ONE SOURCE)
		// -------------------------
		if ($order->get_meta('_bytenft_state') === 'success') {
			$state = 'success';
		}

		// -------------------------
		// FINAL SUCCESS CHECK
		// -------------------------
		$is_success = ($state === 'success');

		if (
			$state !== 'success' &&
			!empty($response_data['transaction_status']) &&
			$response_data['transaction_status'] === 'processing'
		) {
			$state = 'processing';
		}
		// -------------------------
		// MESSAGE
		// -------------------------
		$message = match ($state) {

			'success' =>
				'Your payment was completed successfully.',

			'failed' =>
				'Payment failed. Please try again or use another method.',

			'cancelled' =>
				'You cancelled the payment.',

			'processing' =>
				'Payment is being processed.',

			default =>
				'We couldn’t confirm your payment status yet. If needed, you can try placing the order again after checking your order status.'
		};

		// -------------------------
		// REDIRECT
		// -------------------------
		$redirect = null;

		// 🔥 ONLY ENGINE STATE DECIDES REDIRECT
		if ($state === 'success') {

			$redirect = $order->get_checkout_order_received_url();

		} elseif (in_array($state, ['failed', 'cancelled', 'expired'], true)) {

			$redirect = wc_get_checkout_url();

		} else {

			$redirect = null; // processing → no redirect
		}

		// -------------------------
		// RESPONSE
		// -------------------------
		wp_send_json([
			'success' => $is_success,
			'message' => $message,
			'data' => [
				'payment_status' => $payment_status,
				'order_status'   => $order->get_status(),
				'state'          => $state,
				'redirect'       => $redirect,
				'order_id'       => $order_id,
			]
		]);

		wp_die();
	}

	/**
     * Add custom cron schedules.
     */
	public function bytenft_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'bytenft-payment-gateway-main')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		ByteNFT_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'bytenft-payment-gateway-main']);

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
		ByteNFT_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'bytenft-payment-gateway-main']);
		wp_clear_scheduled_hook('bytenft_cron_event');
	}


	public function handle_cron_event()
	{
		$logger_context = ['source' => 'bytenft-payment-gateway-main'];

		$accounts = get_option('woocommerce_bytenft_payment_gateway_accounts');
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			ByteNFT_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
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
			ByteNFT_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
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
			ByteNFT_Payment_Gateway_Logger::error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
			return [];
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		$updated = false;
		$statusSummary = [];

		if (!empty($response_data['data'])) {
			foreach ($response_data['data'] as $statusData) {
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

				ByteNFT_Payment_Gateway_Logger::info('Payment account statuses were successfully updated after syncing.', [
					'source'  => 'bytenft-payment-gateway-main',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				ByteNFT_Payment_Gateway_Logger::info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'bytenft-payment-gateway-main',
					'context' => ['checked_accounts' => $statusSummary],
				]);
			}
		} else {
			ByteNFT_Payment_Gateway_Logger::info('Sync completed. No account status data was returned from the server.', $logger_context);
		}

		return $statusSummary;
	}


	function bytenft_manual_sync_callback()
	{
		$logger_context = ['source' => 'bytenft-payment-gateway-main'];
		// Verify nonce first
		if (!check_ajax_referer('bytenft_sync_nonce', 'nonce', false)) {
			ByteNFT_Payment_Gateway_Logger::error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'bytenft-payment-gateway-main')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
		ByteNFT_Payment_Gateway_Logger::error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'bytenft-payment-gateway-main')
			], 403);
			wp_die();
		}

		ByteNFT_Payment_Gateway_Logger::info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				ByteNFT_Payment_Gateway_Logger::warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			ByteNFT_Payment_Gateway_Logger::info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'  => __('Payment accounts synchronized successfully.', 'bytenft-payment-gateway-main'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			ByteNFT_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'bytenft-payment-gateway-main') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}

	public function bytenft_send_plugin_status($plugin_status, $gateway_loaded)
	{
		$accounts = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
		
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (empty($accounts) || !is_array($accounts)) {
			return;
		}

		// Find first available public key
		$public_key = '';

		foreach ($accounts as $account) {
			if (!empty($account['live_public_key'])) {
				$public_key = $account['live_public_key'];
				break;
			}

			if (!empty($account['sandbox_public_key'])) {
				$public_key = $account['sandbox_public_key'];
				break;
			}
		}

		if (empty($public_key)) {
			ByteNFT_Payment_Gateway_Logger::error(
				'Unable to send plugin status. No public key found.',
				[
					'source' => 'bytenft-payment-gateway-main',
				]
			);
			return;
		}

		global $wp_version;

		$body = [
			'valid_accounts'         => $accounts,
			'plugin_status'          => (int) $plugin_status,
			'gateway_loaded'         => (int) $gateway_loaded,
			'plugin_version'         => BYTENFT_PLUGIN_VERSION,
			'wordpress_version'      => $wp_version,
			'woocommerce_version'    => class_exists('WooCommerce') && function_exists('WC')
				? WC()->version
				: '',
			'woocommerce_db_version' => get_option('woocommerce_db_version'),
			'group_id'               => get_option('bytenft_group_id'),
			'domain_name'            => wp_parse_url(home_url(), PHP_URL_HOST),
		];

		$response = wp_remote_post(
			trailingslashit(BYTENFT_BASE_URL) . 'api/plugin/check/plugin',
			[
				'method'    => 'POST',
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'body'      => $body,
			]
		);

		if (is_wp_error($response)) {
			ByteNFT_Payment_Gateway_Logger::error(
				'Plugin status API call failed.',
				[
					'source'  => 'bytenft-payment-gateway-main',
					'context' => [
						'error' => $response->get_error_message(),
					],
				]
			);
			return;
		}

		ByteNFT_Payment_Gateway_Logger::info(
			'Plugin status updated successfully.',
			[
				'source'  => 'bytenft-payment-gateway-main',
				'context' => [
					'plugin_status'  => $plugin_status,
					'gateway_loaded' => $gateway_loaded,
					'response_code'  => wp_remote_retrieve_response_code($response),
				],
			]
		);
	}
}