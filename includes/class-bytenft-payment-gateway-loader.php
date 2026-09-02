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

		// Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
			if ( isset( $_POST['payment_method'] ) && 'bytenft' === $_POST['payment_method'] ) {
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

		// Integration Validation Guide hooks
		add_action('admin_menu', [$this, 'bytenft_admin_menu']);
		add_action('admin_enqueue_scripts', [$this, 'bytenft_guide_admin_scripts']);
		add_action('wp_ajax_bytenft_get_guide_status', [$this, 'bytenft_get_guide_status']);
		add_action('wp_ajax_bytenft_reset_guide_status', [$this, 'bytenft_reset_guide_status']);

		// Track thank you page visits for validation guide (runs only when browser renders thank you page)
		add_action('woocommerce_thankyou_bytenft', function ($order_id) {
			update_option('bytenft_thankyou_page_verified', true);
			delete_option('bytenft_last_payment_status');
			delete_option('bytenft_thankyou_page_status');
		});

		// Track order status changes for real-time validation guide updates
		add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
			$order = wc_get_order($order_id);
			if (!$order || $order->get_payment_method() !== 'bytenft') {
				return;
			}
			if (in_array($new_status, ['processing', 'completed'], true)) {
				delete_option('bytenft_last_payment_status');
			} elseif (in_array($new_status, ['failed', 'cancelled'], true)) {
				update_option('bytenft_last_payment_status', 'failed');
				update_option('bytenft_thankyou_page_status', 'failed');
			}
		}, 10, 3);
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
				['source' => 'bytenft-payment-gateway']
			);
		}

		$orderID = WC()->session
			? ( WC()->session->get('store_api_draft_order') ?: WC()->session->get('order_awaiting_payment') )
			: null;

		/*
		 * WooCommerce 10.8+ writes 'store_api_draft_order' in exactly one place:
		 * the Store API /checkout route (StoreApi/Routes/V1/Checkout.php). This
		 * handler bypasses that route - the JS calls preventDefault() on Place
		 * Order and posts here instead - so on WooCommerce 11 the key is never
		 * set and every block checkout ended at "Invalid order.".
		 *
		 * Older WooCommerce created the draft order during the cart routes, so
		 * the key was already populated by the time Place Order ran. That is why
		 * this worked in 1.0.16 and stopped working since.
		 *
		 * Build the order here, ALWAYS - not only when the pointers are empty.
		 *
		 * Reusing a session order id verbatim was sending whatever total that
		 * order was built with. Once an attempt had been rejected, changing the
		 * amount changed the cart but never the order, so request-payment kept
		 * receiving the identical order+amount and kept answering "This order
		 * appears to be a duplicate".
		 *
		 * WC_Checkout::create_order() already resolves this correctly: it
		 * resumes 'order_awaiting_payment' only while the cart hash still
		 * matches and the order is pending/failed, otherwise it starts a new
		 * order - and either way it calls set_data_from_cart(), so items,
		 * shipping, coupons and totals are rebuilt from the current cart every
		 * time. A changed amount therefore produces a correct amount, and a
		 * changed cart produces a new order id that is not a duplicate.
		 *
		 * The session pointers stay as the fallback for the case where there is
		 * no cart to build from.
		 */
		if ( ! empty( WC()->cart ) && ! WC()->cart->is_empty() ) {
			$orderID = $this->bytenft_create_block_order() ?: $orderID;
		}

		$status = [];
		if($orderID){
			$status = $bytenftPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'bytenft-payment-gateway'), 'error');
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
	private function bytenft_create_block_order() {

		$checkout = WC()->checkout();
		$data     = $checkout->get_posted_data();
		$customer = WC()->customer;

		/*
		 * Block checkout inputs carry no name attribute, so the serialized form
		 * posts almost nothing and get_posted_data() returns blanks - it reads
		 * $_POST only, with no customer fallback. WC()->customer is what the
		 * Store API keeps in sync as the shopper types, so use it to fill in
		 * anything the post did not carry.
		 */
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

			/*
			 * BILLING ONLY - never shipping.
			 *
			 * WooCommerce already owns shipping: get_posted_data() decides via
			 * maybe_skip_fieldset() whether shipping was submitted at all, and
			 * create_order() copies billing into shipping when ship-to-different
			 * is off. Writing shipping keys here puts WC()->customer's copy on
			 * top of that decision, which overrides the address FunnelKit (and
			 * any other checkout that manages shipping itself) already set.
			 *
			 * Leaving shipping untouched keeps WooCommerce's own behaviour.
			 */
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
			$data['payment_method'] = 'bytenft';
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

			ByteNFT_Payment_Gateway_Logger::error(
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

		ByteNFT_Payment_Gateway_Logger::info(
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
				'docs'    => '<a href="' . esc_url(apply_filters('bytenft_docs_url', 'https://pay.bytenft.xyz/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'bytenft-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('bytenft_support_url', 'https://pay.bytenft.xyz/contact-us')) . '" target="_blank">' . esc_html__('Support', 'bytenft-payment-gateway') . '</a>',
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
			wp_send_json_error(array('error' => esc_html__('Invalid order ID', 'bytenft-payment-gateway')));
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
				'error' => esc_html__('Order not found', 'bytenft-payment-gateway')
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
					'source' => 'bytenft-payment-gateway'
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
			$log_prefix . " PopupClose | Engine result: " . json_encode($result)
		);

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
			'display'  => __('Every Two Hours', 'bytenft-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		ByteNFT_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'bytenft-payment-gateway']);

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
		ByteNFT_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'bytenft-payment-gateway']);
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
					'source'  => 'bytenft-payment-gateway',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				ByteNFT_Payment_Gateway_Logger::info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'bytenft-payment-gateway',
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
		$logger_context = ['source' => 'bytenft-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('bytenft_sync_nonce', 'nonce', false)) {
			ByteNFT_Payment_Gateway_Logger::error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'bytenft-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
		ByteNFT_Payment_Gateway_Logger::error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'bytenft-payment-gateway')
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
				'message'  => __('Payment accounts synchronized successfully.', 'bytenft-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			ByteNFT_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'bytenft-payment-gateway') . $e->getMessage(),
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
					'source' => 'bytenft-payment-gateway',
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
					'source'  => 'bytenft-payment-gateway',
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
				'source'  => 'bytenft-payment-gateway',
				'context' => [
					'plugin_status'  => $plugin_status,
					'gateway_loaded' => $gateway_loaded,
					'response_code'  => wp_remote_retrieve_response_code($response),
				],
			]
		);
	}

	/**
	 * Register the Merchant Integration Validation Guide admin page.
	 */
	public function bytenft_admin_menu()
	{
		add_submenu_page(
			null,
			__('ByteNFT Merchant Integration Validation Guide', 'bytenft-payment-gateway'),
			__('Integration Guide', 'bytenft-payment-gateway'),
			'manage_woocommerce',
			'bytenft-integration-guide',
			[$this, 'bytenft_display_integration_guide']
		);
	}

	/**
	 * Render the Merchant Integration Validation Guide admin page.
	 */
	public function bytenft_display_integration_guide()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'bytenft-payment-gateway'));
		}

		$template_path = BYTENFT_PAYMENT_GATEWAY_PLUGIN_DIR . 'integration-guide.html';
		if (file_exists($template_path)) {
			include $template_path;
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__('Integration guide template not found.', 'bytenft-payment-gateway') . '</p></div>';
		}
	}

	/**
	 * Automatically compute the validation statuses for the 7 steps based on real system state.
	 *
	 * @return array<int, string> Map of step numbers to 'passed', 'failed', or 'pending'.
	 */
	public static function bytenft_get_validation_guide_statuses()
	{
		global $wpdb;

		$statuses = [
			1 => 'pending',
			2 => 'pending',
			3 => 'pending',
			4 => 'pending',
			5 => 'pending',
			6 => 'pending',
			7 => 'pending',
		];

		$reset_time      = get_option('bytenft_guide_reset_time');
		$reset_timestamp = $reset_time ? strtotime($reset_time) : 0;

		// STEP 1: Configure the Plugin
		$settings          = get_option('woocommerce_bytenft_settings', []);
		$accounts          = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
		$is_enabled        = !empty($settings['enabled']) && $settings['enabled'] === 'yes';
		$has_valid_account = false;

		if (!empty($accounts) && is_array($accounts)) {
			foreach ($accounts as $acc) {
				if (!empty($acc['live_public_key']) && !empty($acc['live_secret_key'])) {
					$has_valid_account = true;
					break;
				}
				if (!empty($acc['sandbox_public_key']) && !empty($acc['sandbox_secret_key'])) {
					$has_valid_account = true;
					break;
				}
			}
		}

		if ($is_enabled && $has_valid_account) {
			$statuses[1] = 'passed';
		} elseif (!empty($accounts) && !$is_enabled) {
			$statuses[1] = 'failed';
		} else {
			$statuses[1] = 'pending';
		}

		// STEP 2: Verify the Payment Page
		$table_name       = $wpdb->prefix . 'order_payment_link';
		$has_payment_link = false;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_table        = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name);

		if ($has_table) {
			$sql = "SELECT COUNT(*) FROM {$table_name} WHERE payment_link IS NOT NULL AND payment_link != ''";
			if ($reset_time) {
				$sql .= $wpdb->prepare(" AND (created_at >= %s OR updated_at >= %s)", $reset_time, $reset_time);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var($sql);
			if ($count && intval($count) > 0) {
				$has_payment_link = true;
			}
		}

		if ($has_payment_link || get_option('bytenft_payment_page_verified')) {
			$statuses[2] = 'passed';
		} elseif (get_option('bytenft_last_payment_page_status') === 'failed') {
			$statuses[2] = 'failed';
		} else {
			$statuses[2] = 'pending';
		}

		// STEP 3: Create a Test Payment
		$order_args_3 = [
			'payment_method' => 'bytenft',
			'limit'          => 1,
			'return'         => 'ids',
		];
		if ($reset_timestamp > 0) {
			$order_args_3['date_created'] = '>=' . $reset_timestamp;
		}
		$bytenft_orders = wc_get_orders($order_args_3);

		if (!empty($bytenft_orders) || $has_payment_link || get_option('bytenft_payment_created_verified')) {
			$statuses[3] = 'passed';
		} elseif (get_option('bytenft_last_payment_creation_status') === 'failed') {
			$statuses[3] = 'failed';
		} else {
			$statuses[3] = 'pending';
		}

		// Fetch the latest ByteNFT order in the test window
		$latest_order_args = [
			'payment_method' => 'bytenft',
			'limit'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ($reset_timestamp > 0) {
			$latest_order_args['date_created'] = '>=' . $reset_timestamp;
		}
		$latest_orders = wc_get_orders($latest_order_args);
		$latest_order  = !empty($latest_orders) ? $latest_orders[0] : null;

		// STEP 4: Verify a Successful Payment
		$order_args_4 = [
			'payment_method' => 'bytenft',
			'status'         => ['processing', 'completed'],
			'limit'          => 1,
			'return'         => 'ids',
		];
		if ($reset_timestamp > 0) {
			$order_args_4['date_created'] = '>=' . $reset_timestamp;
		}
		$successful_orders = wc_get_orders($order_args_4);

		if (!empty($successful_orders)) {
			if ($latest_order && in_array($latest_order->get_status(), ['failed', 'cancelled'], true)) {
				$statuses[4] = 'failed';
			} else {
				$statuses[4] = 'passed';
			}
		} elseif (get_option('bytenft_last_payment_status') === 'failed' || ($latest_order && in_array($latest_order->get_status(), ['failed', 'cancelled'], true))) {
			$statuses[4] = 'failed';
		} else {
			$statuses[4] = 'pending';
		}

		// STEP 5: Verify the Thank You Page (ONLY verified when customer loads thank you page)
		if (get_option('bytenft_thankyou_page_verified')) {
			if ($latest_order && in_array($latest_order->get_status(), ['failed', 'cancelled'], true)) {
				$statuses[5] = 'failed';
			} else {
				$statuses[5] = 'passed';
			}
		} elseif (get_option('bytenft_thankyou_page_status') === 'failed' || ($latest_order && in_array($latest_order->get_status(), ['failed', 'cancelled'], true))) {
			$statuses[5] = 'failed';
		} else {
			$statuses[5] = 'pending';
		}

		// STEP 6: Verify Webhook
		$webhook_validation = get_option('bytenft_webhook_validation_status');
		if ($webhook_validation === 'passed' || get_option('bytenft_webhook_verified')) {
			$statuses[6] = 'passed';
		} elseif ($webhook_validation === 'failed' || get_option('bytenft_last_webhook_status') === 'failed') {
			$statuses[6] = 'failed';
		} else {
			// Check if any order has recorded webhook events in timeline metadata
			$order_args_wh = [
				'payment_method' => 'bytenft',
				'limit'          => 10,
			];
			if ($reset_timestamp > 0) {
				$order_args_wh['date_created'] = '>=' . $reset_timestamp;
			}
			$bytenft_orders_wh = wc_get_orders($order_args_wh);

			$found_webhook = false;
			if (!empty($bytenft_orders_wh) && is_array($bytenft_orders_wh)) {
				foreach ($bytenft_orders_wh as $o) {
					$timeline = $o->get_meta('_bytenft_timeline');
					if (is_array($timeline)) {
						foreach ($timeline as $evt) {
							if (
								($evt['event_type'] ?? '') === 'webhook_update' ||
								($evt['source'] ?? '') === 'Webhook'
							) {
								$found_webhook = true;
								break 2;
							}
						}
					}
				}
			}

			if ($found_webhook) {
				update_option('bytenft_webhook_verified', true);
				update_option('bytenft_webhook_validation_status', 'passed');
				$statuses[6] = 'passed';
			} else {
				$statuses[6] = 'pending';
			}
		}

		// STEP 7: Review Before Enabling Live Mode
		$has_failed_step = (
			$statuses[1] === 'failed' ||
			$statuses[2] === 'failed' ||
			$statuses[3] === 'failed' ||
			$statuses[4] === 'failed' ||
			$statuses[5] === 'failed' ||
			$statuses[6] === 'failed'
		);

		if (
			$statuses[1] === 'passed' &&
			$statuses[2] === 'passed' &&
			$statuses[3] === 'passed' &&
			$statuses[4] === 'passed' &&
			$statuses[5] === 'passed' &&
			$statuses[6] === 'passed'
		) {
			$statuses[7] = 'passed';
		} elseif ($has_failed_step) {
			$statuses[7] = 'failed';
		} else {
			$statuses[7] = 'pending';
		}

		return $statuses;
	}

	/**
	 * Enqueue scripts and styles for the Integration Guide page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function bytenft_guide_admin_scripts($hook)
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
		if ($page !== 'bytenft-integration-guide') {
			return;
		}

		wp_enqueue_style(
			'bytenft-font-awesome',
			plugins_url('../assets/css/font-awesome.css', __FILE__),
			[],
			filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'),
			'all'
		);

		wp_enqueue_style(
			'bytenft-admin-css',
			plugins_url('../assets/css/admin.css', __FILE__),
			[],
			filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'),
			'all'
		);

		wp_enqueue_script(
			'bytenft-admin-script',
			plugins_url('../assets/js/bytenft-admin.js', __FILE__),
			['jquery'],
			filemtime(plugin_dir_path(__FILE__) . '../assets/js/bytenft-admin.js'),
			true
		);

		$statuses   = self::bytenft_get_validation_guide_statuses();
		$all_passed = (
			($statuses[1] ?? '') === 'passed' &&
			($statuses[2] ?? '') === 'passed' &&
			($statuses[3] ?? '') === 'passed' &&
			($statuses[4] ?? '') === 'passed' &&
			($statuses[5] ?? '') === 'passed' &&
			($statuses[6] ?? '') === 'passed' &&
			($statuses[7] ?? '') === 'passed'
		);

		wp_localize_script('bytenft-admin-script', 'bytenft_admin_data', [
			'ajax_url'     => admin_url('admin-ajax.php'),
			'nonce'        => wp_create_nonce('bytenft_guide_nonce'),
			'guide_nonce'  => wp_create_nonce('bytenft_guide_nonce'),
			'gateway_id'   => 'bytenft',
			'statuses'     => $statuses,
			'all_passed'   => $all_passed,
			'settings_url' => admin_url('admin.php?page=wc-settings&tab=checkout&section=bytenft'),
		]);
	}

	/**
	 * AJAX handler to get latest automatic integration validation statuses.
	 */
	public function bytenft_get_guide_status()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => __('Unauthorized permission.', 'bytenft-payment-gateway')], 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'bytenft_guide_nonce') && !wp_verify_nonce($nonce, 'bytenft_sync_nonce')) {
			wp_send_json_error(['message' => __('Security check failed.', 'bytenft-payment-gateway')], 403);
		}

		$statuses   = self::bytenft_get_validation_guide_statuses();
		$all_passed = (
			($statuses[1] ?? '') === 'passed' &&
			($statuses[2] ?? '') === 'passed' &&
			($statuses[3] ?? '') === 'passed' &&
			($statuses[4] ?? '') === 'passed' &&
			($statuses[5] ?? '') === 'passed' &&
			($statuses[6] ?? '') === 'passed' &&
			($statuses[7] ?? '') === 'passed'
		);

		wp_send_json_success([
			'statuses'   => $statuses,
			'all_passed' => $all_passed,
		]);
	}

	/**
	 * AJAX handler to reset validation guide status.
	 */
	public function bytenft_reset_guide_status()
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => __('Unauthorized permission.', 'bytenft-payment-gateway')], 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'bytenft_guide_nonce') && !wp_verify_nonce($nonce, 'bytenft_sync_nonce')) {
			wp_send_json_error(['message' => __('Security check failed.', 'bytenft-payment-gateway')], 403);
		}

		update_option('bytenft_guide_reset_time', current_time('mysql'));
		delete_option('bytenft_webhook_verified');
		delete_option('bytenft_webhook_validation_status');
		delete_option('bytenft_last_webhook_status');
		delete_option('bytenft_webhook_failure_reason');
		delete_option('bytenft_payment_page_verified');
		delete_option('bytenft_payment_created_verified');
		delete_option('bytenft_successful_payment_verified');
		delete_option('bytenft_thankyou_page_verified');
		delete_option('bytenft_last_payment_page_status');
		delete_option('bytenft_last_payment_creation_status');
		delete_option('bytenft_last_payment_status');
		delete_option('bytenft_thankyou_page_status');

		$statuses   = self::bytenft_get_validation_guide_statuses();
		$all_passed = (
			($statuses[1] ?? '') === 'passed' &&
			($statuses[2] ?? '') === 'passed' &&
			($statuses[3] ?? '') === 'passed' &&
			($statuses[4] ?? '') === 'passed' &&
			($statuses[5] ?? '') === 'passed' &&
			($statuses[6] ?? '') === 'passed' &&
			($statuses[7] ?? '') === 'passed'
		);

		wp_send_json_success([
			'statuses'   => $statuses,
			'all_passed' => $all_passed,
			'message'    => __('Validation guide status has been reset.', 'bytenft-payment-gateway'),
		]);
	}
}