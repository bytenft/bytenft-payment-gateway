<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-voucher-payment-state-engine.php';
require_once plugin_dir_path(__FILE__) . 'class-voucher-payment-logger.php';
/**
 * Class VOUCHER_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the Voucher Payment Gateway plugin.
 */
class VOUCHER_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $base_url;

	/**
	 * Get the singleton instance of this class.
	 * @return VOUCHER_PAYMENT_GATEWAY_Loader
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

		$this->base_url = VOUCHER_BASE_URL;
		
		$this->admin_notices = new VOUCHER_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'voucher_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'voucher_init'], 10);

		// Register the AJAX action callback for checking payment status
		add_action('wp_ajax_voucher_check_payment_status', array($this, 'voucher_handle_check_payment_status_request'));
		add_action('wp_ajax_nopriv_voucher_check_payment_status', array($this, 'voucher_handle_check_payment_status_request'));

		add_action('wp_ajax_voucher_popup_closed_event', array($this, 'handle_popup_close'));
		add_action('wp_ajax_nopriv_voucher_popup_closed_event', array($this, 'handle_popup_close'));

		add_action('wp_ajax_voucher_manual_sync', [$this, 'voucher_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'voucher_add_cron_interval']);
		add_action('voucher_cron_event', [$this, 'handle_cron_event']);
		add_action('wp_ajax_voucher_block_gateway_process', [$this,'handle_voucher_gateway_ajax']);
		add_action('wp_ajax_nopriv_voucher_block_gateway_process', [$this,'handle_voucher_gateway_ajax']); 
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

		add_action('woocommerce_before_checkout_form', [$this, 'voucher_show_checkout_error']);

		// Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
			if ( isset( $_POST['payment_method'] ) && 'voucher' === $_POST['payment_method'] ) {
				if ( function_exists('WC') && WC()->session ) {
					$awaiting_order_id = WC()->session->get('order_awaiting_payment');
					if ( $awaiting_order_id ) {
						$awaiting_order = wc_get_order( $awaiting_order_id );
						$posted_email   = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';
						$posted_phone   = isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '';
						
						if ( $awaiting_order && ( $awaiting_order->get_billing_email() !== $posted_email || $awaiting_order->get_billing_phone() !== $posted_phone ) ) {
							WC()->session->set( 'order_awaiting_payment', null );
						}
					}
				}
			}
		});
	}

	/**
	 * ── FIXED ──────────────────────────────────────────────────────────────────
	 * Handle the block checkout AJAX payment request.
	 *
	 * Root cause of "No available payment accounts":
	 * `new VOUCHER_PAYMENT_GATEWAY()` creates a cold instance. In an AJAX
	 * context WooCommerce has not called init_settings() on it, so
	 * $this->sandbox defaults to false and get_option() returns empty values.
	 * get_next_available_account() then finds no matching keys → returns false.
	 *
	 * Fix: pull the already-booted instance from WC()->payment_gateways().
	 * That instance was fully initialised during the normal WC boot cycle so
	 * sandbox mode and account keys are correct.
	 * ───────────────────────────────────────────────────────────────────────────
	 */
	function handle_voucher_gateway_ajax(){

		// Nonce verification
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (empty($nonce) || !wp_verify_nonce($nonce, 'voucher_payment')) {
			wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
			die;
		}

		// Pull the already-initialised gateway from the WC registry.
		// Never use `new VOUCHER_PAYMENT_GATEWAY()` here — see note above.
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$voucherPayment = $gateways['voucher'] ?? null;

		if (!$voucherPayment) {
			// Fallback: manually instantiate and force-load settings from DB.
			// Should never happen in normal operation.
			$voucherPayment = new VOUCHER_PAYMENT_GATEWAY();
			$voucherPayment->init_settings();
			$voucherPayment->load_gateway_settings();

			Voucher_Payment_Gateway_Logger::warning(
				'Voucher: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
				['source' => 'voucher-payment-gateway']
			);
		}

		$orderID = WC()->session
			? ( WC()->session->get('store_api_draft_order') ?: WC()->session->get('order_awaiting_payment') )
			: null;

		if ( ! empty( WC()->cart ) && ! WC()->cart->is_empty() ) {
			$orderID = $this->voucher_create_block_order() ?: $orderID;
		}

		$status = [];
		if($orderID){
			$status = $voucherPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'voucher-payment-gateway'), 'error');
			$status = ['result' => 'fail','error' => 'Invalid order.'];
		}
		
		wp_send_json($status);
		die;
	}

	/**
	 * Create the WooCommerce order for a block checkout payment.
	 *
	 * Delegates to WC_Checkout::create_order() so line items, shipping lines,
	 * coupons, taxes and totals are built by WooCommerce itself rather than by
	 * hand here.
	 *
	 * @return int Order ID, or 0 on failure.
	 */
	private function voucher_create_block_order() {

		$checkout = WC()->checkout();
		$data     = $checkout->get_posted_data();
		$customer = WC()->customer;

		if ( $customer ) {

			$fields = [
				'first_name',
				'last_name',
				'company',
				'address_1',
				'address_2',
				'city',
				'state',
				'postcode',
				'country',
				'phone',
				'email',
			];

			foreach ( $fields as $field ) {

				$key = 'billing_' . $field;

				if ( ! empty( $data[ $key ] ) ) {
					continue;
				}

				$getter = 'get_' . $key;

				if ( is_callable( [ $customer, $getter ] ) ) {
					$data[ $key ] = $customer->$getter();
				}
			}
		}

		if ( empty( $data['payment_method'] ) ) {
			$data['payment_method'] = 'voucher';
		}

		// Prevent reusing an order if the email address OR phone number doesn't match
		if ( WC()->session ) {
			$awaiting_order_id = WC()->session->get('order_awaiting_payment') ?: WC()->session->get('store_api_draft_order');
			if ( $awaiting_order_id ) {
				$awaiting_order = wc_get_order( $awaiting_order_id );
				$posted_email   = sanitize_email( $data['billing_email'] ?? '' );
				$posted_phone   = sanitize_text_field( $data['billing_phone'] ?? '' );
				
				if ( $awaiting_order && ( $awaiting_order->get_billing_email() !== $posted_email || $awaiting_order->get_billing_phone() !== $posted_phone ) ) {
					WC()->session->set( 'order_awaiting_payment', null );
					WC()->session->set( 'store_api_draft_order', null );
				}
			}
		}

		$order_id = $checkout->create_order( $data );

		if ( is_wp_error( $order_id ) ) {

			Voucher_Payment_Gateway_Logger::error(
				'Could not create order for block checkout',
				[
					'error' => $order_id->get_error_message(),
				]
			);

			return 0;
		}

		if ( WC()->session ) {
			WC()->session->set( 'order_awaiting_payment', $order_id );
		}

		$created = wc_get_order( $order_id );

		Voucher_Payment_Gateway_Logger::info(
			'Created order for block checkout',
			[
				'order_id' => $order_id,
				'total'    => $created ? $created->get_total() : null,
				'email'    => $created ? $created->get_billing_email() : null,
			]
		);

		return $order_id;
	}

	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function voucher_init()
	{
		// Check if the environment is compatible
		$environment_warning = voucher_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Initialize gateways
		$this->voucher_init_gateways();

		// Register blocks gateway
		$this->voucher_init_blocks();
		
		add_action( 'enqueue_block_assets', [ $this, 'register_blocks_assets' ] );

		// Initialize REST API
		$rest_api = VOUCHER_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->voucher_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(VOUCHER_PAYMENT_GATEWAY_FILE), [$this, 'voucher_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'voucher_plugin_row_meta'], 10, 2);
	}

	public function voucher_show_checkout_error()
	{
		if (!function_exists('WC')) return;

		$error = WC()->session->get('voucher_error') ?: WC()->session->get('bytenft_error');
		if (!$error) return;

		$messages = [
			'failed'    => 'Payment failed. Please try again.',
			'cancelled' => 'Payment was cancelled.',
			'expired'   => 'Payment session expired. Please try again.'
		];

		// Clear error immediately
		WC()->session->__unset('voucher_error');
		WC()->session->__unset('bytenft_error');

		if (isset($messages[$error])) {
			wc_add_notice($messages[$error], 'error');
		}
	}

	/**
	 * Initialize gateways.
	 */
	private function voucher_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once VOUCHER_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-voucher-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'VOUCHER_PAYMENT_GATEWAY';			
			return $methods;
		});
	}

	private function voucher_init_blocks() {
		
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

				require_once VOUCHER_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-voucher-blocks-gateway.php';

				add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {
					$registry->register( new VOUCHER_Blocks_Gateway() );
				});
			}
	
	}
	
	public function register_blocks_assets() {
		
		if (is_checkout()) {
			$image_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/loader.gif';
			wp_register_script(
				'voucher-blocks-js',
				plugin_dir_url( VOUCHER_PAYMENT_GATEWAY_FILE ) . 'assets/js/voucher-blocks.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				'1.0.0',
				true
			);

			$settings = get_option( 'woocommerce_voucher_settings', [] );
			if (empty($settings)) {
				$settings = get_option( 'woocommerce_bytenft_settings', [] );
			}

			wp_localize_script(
				'voucher-blocks-js',
				'voucher_params',
				[ 'settings' => $settings,
				 'ajax_url' => admin_url('admin-ajax.php'),
				 'voucher_loader' => $image_url,
				 'voucher_nonce' => wp_create_nonce('voucher_payment'), 
				 'checkout_url' => wc_get_checkout_url(),
				 'payment_method' => 'voucher' 
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
	public function voucher_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=voucher')) . '">' . esc_html__('Settings', 'voucher-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function voucher_plugin_row_meta($links, $file)
	{
		if (plugin_basename(VOUCHER_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('voucher_docs_url', 'https://pay.voucher.xyz/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'voucher-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('voucher_support_url', 'https://pay.voucher.xyz/contact-us')) . '" target="_blank">' . esc_html__('Support', 'voucher-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function voucher_handle_environment_check()
	{
		$environment_warning = voucher_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->voucher_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}

	/**
	 * Handle the AJAX request for checking payment status.
	 * @param $request
	 */
	public function voucher_handle_check_payment_status_request($request)
	{
		check_ajax_referer('voucher_payment', 'security');

		// Sanitize and validate the order ID from $_POST
		$order_id = isset($_POST['order_id']) ? intval(sanitize_text_field(wp_unslash($_POST['order_id']))) : null;
		if (!$order_id) {
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'voucher-payment-gateway')));
		}

		// Call the function to check payment status with the validated order ID
		return $this->voucher_check_payment_status($order_id);
	}

	/**
	 * Check the payment status for an order.
	 * @param int $order_id
	 * @return WP_REST_Response
	 */
	public function voucher_check_payment_status($order_id)
	{
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response([
				'error' => esc_html__('Order not found', 'voucher-payment-gateway')
			], 404);
		}

		$security = isset($_POST['security'])
			? sanitize_text_field(wp_unslash($_POST['security']))
			: '';

		$log_prefix = "[Order #{$order_id}]";

		// -------------------------
		// NONCE CHECK
		// -------------------------
		if (empty($security) || !wp_verify_nonce($security, 'voucher_payment')) {

			Voucher_Payment_Gateway_Logger::info(
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
		$payment_token = $order->get_meta('_voucher_pay_id') ?: $order->get_meta('_bytenft_pay_id');

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

			Voucher_Payment_Gateway_Logger::info(
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

			Voucher_Payment_Gateway_Logger::info(
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

			Voucher_Payment_Gateway_Logger::info(
				$log_prefix . " CheckStatus | Engine trigger ({$payment_status})"
			);

			$result = Voucher_Payment_State_Engine::handle_event(
				$order_id,
				'redirect_check',
				[
					'status'        => $payment_status,
					'payment_token' => $payment_token,
				]
			);

			Voucher_Payment_Gateway_Logger::info(
				$log_prefix . " CheckStatus | Engine result: " . json_encode($result)
			);
		}

		// -------------------------
		// REFRESH ORDER
		// -------------------------
		$order = wc_get_order($order_id);

		$wc_status = $order->get_status();

		$state = Voucher_Payment_State_Engine::resolve_final_state(
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

	private function voucher_log($message, $context = [])
	{
		if (function_exists('wc_get_logger')) {
			Voucher_Payment_Gateway_Logger::info(
				$message,
				array_merge([
					'source' => 'voucher-payment-gateway'
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
		if (empty($security) || !wp_verify_nonce($security, 'voucher_payment')) {

			Voucher_Payment_Gateway_Logger::info(
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

			Voucher_Payment_Gateway_Logger::info(
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
		$payment_token = $order->get_meta('_voucher_pay_id') ?: $order->get_meta('_bytenft_pay_id');

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

			Voucher_Payment_Gateway_Logger::info(
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

			Voucher_Payment_Gateway_Logger::info(
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
		$result = Voucher_Payment_State_Engine::handle_event(
			$order_id,
			'popup_close',
			[
				'status'        => $payment_status,
				'payment_token' => $payment_token,
			]
		);

		Voucher_Payment_Gateway_Logger::info(
			$log_prefix . " PopupClose | Engine result: " . json_encode($result)
		);

		// -------------------------
		// ALWAYS RELOAD ORDER AFTER ENGINE
		// -------------------------
		$order = wc_get_order($order_id);

		// 🔥 PRIMARY STATE = ENGINE STORED STATE ONLY
		$state = Voucher_Payment_State_Engine::resolve_final_state($order);

		// -------------------------
		// HARD OVERRIDE SAFETY (ONLY ONE SOURCE)
		// -------------------------
		if ($order->get_meta('_voucher_state') === 'success' || $order->get_meta('_bytenft_state') === 'success') {
			$state = 'success';
		}

		// -------------------------
		// FINAL SUCCESS CHECK
		// -------------------------
		$is_success = ($state === 'success');

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
	public function voucher_add_cron_interval($schedules)
	{
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'voucher-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		Voucher_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'voucher-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('voucher_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'voucher_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'voucher_cron_event');
	}

	function deactivate_cron_job()
	{
		Voucher_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'voucher-payment-gateway']);
		wp_clear_scheduled_hook('voucher_cron_event');
	}


	public function handle_cron_event()
	{
		$logger_context = ['source' => 'voucher-payment-gateway'];

		$accounts = get_option('woocommerce_voucher_payment_gateway_accounts');
		if (empty($accounts)) {
			$accounts = get_option('woocommerce_bytenft_payment_gateway_accounts');
		}
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			Voucher_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
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
			Voucher_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
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
			Voucher_Payment_Gateway_Logger::error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
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
				update_option('woocommerce_voucher_payment_gateway_accounts', $accounts);

				Voucher_Payment_Gateway_Logger::info('Payment account statuses were successfully updated after syncing.', [
					'source'  => 'voucher-payment-gateway',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				Voucher_Payment_Gateway_Logger::info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'voucher-payment-gateway',
					'context' => ['checked_accounts' => $statusSummary],
				]);
			}
		} else {
			Voucher_Payment_Gateway_Logger::info('Sync completed. No account status data was returned from the server.', $logger_context);
		}

		return $statusSummary;
	}


	function voucher_manual_sync_callback()
	{
		$logger_context = ['source' => 'voucher-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('voucher_sync_nonce', 'nonce', false)) {
			Voucher_Payment_Gateway_Logger::error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'voucher-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
			Voucher_Payment_Gateway_Logger::error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'voucher-payment-gateway')
			], 403);
			wp_die();
		}

		Voucher_Payment_Gateway_Logger::info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				Voucher_Payment_Gateway_Logger::warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			Voucher_Payment_Gateway_Logger::info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'  => __('Payment accounts synchronized successfully.', 'voucher-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			Voucher_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'voucher-payment-gateway') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}

	public function voucher_send_plugin_status($plugin_status, $gateway_loaded)
	{
		$accounts = get_option('woocommerce_voucher_payment_gateway_accounts', []);
		if (empty($accounts)) {
			$accounts = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
		}

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
			Voucher_Payment_Gateway_Logger::error(
				'Unable to send plugin status. No public key found.',
				[
					'source' => 'voucher-payment-gateway',
				]
			);
			return;
		}

		global $wp_version;

		$body = [
			'valid_accounts'         => $accounts,
			'plugin_status'          => (int) $plugin_status,
			'gateway_loaded'         => (int) $gateway_loaded,
			'plugin_version'         => VOUCHER_PLUGIN_VERSION,
			'wordpress_version'      => $wp_version,
			'woocommerce_version'    => class_exists('WooCommerce') && function_exists('WC')
				? WC()->version
				: '',
			'woocommerce_db_version' => get_option('woocommerce_db_version'),
			'group_id'               => get_option('voucher_group_id'),
			'domain_name'            => wp_parse_url(home_url(), PHP_URL_HOST),
		];

		$response = wp_remote_post(
			trailingslashit(VOUCHER_BASE_URL) . 'api/plugin/check/plugin',
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
			Voucher_Payment_Gateway_Logger::error(
				'Plugin status API call failed.',
				[
					'source'  => 'voucher-payment-gateway',
					'context' => [
						'error' => $response->get_error_message(),
					],
				]
			);
			return;
		}

		Voucher_Payment_Gateway_Logger::info(
			'Plugin status updated successfully.',
			[
				'source'  => 'voucher-payment-gateway',
				'context' => [
					'plugin_status'  => $plugin_status,
					'gateway_loaded' => $gateway_loaded,
					'response_code'  => wp_remote_retrieve_response_code($response),
				],
			]
		);
	}
}
