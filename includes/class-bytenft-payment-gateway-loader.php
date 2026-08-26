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
	 * Account decision values.
	 */
	private const ACCOUNT_ACTION_USE_EXISTING = 'use_existing';
	private const ACCOUNT_ACTION_CREATE_NEW   = 'create_new';

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
			// Allow notices on the checkout page, and preserve them during AJAX or REST requests
			if ( ! is_checkout() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
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

		add_action(
			'wp_ajax_bytenft_check_customer_account',
			[ $this, 'bytenft_check_customer_account' ]
		);

		add_action(
			'wp_ajax_nopriv_bytenft_check_customer_account',
			[ $this, 'bytenft_check_customer_account' ]
		);

		add_action(
			'wp_ajax_bytenft_save_customer_account_action',
			[ $this, 'bytenft_save_customer_account_action' ]
		);

		add_action(
			'wp_ajax_nopriv_bytenft_save_customer_account_action',
			[ $this, 'bytenft_save_customer_account_action' ]
		);

		add_action('woocommerce_before_checkout_form', [$this, 'bytenft_show_checkout_error']);
	}

	private function normalize_checkout_data($data) {

		$same_address =
			empty($data['ship_to_different_address']) ||
			$data['ship_to_different_address'] === '0' ||
			!empty($data['wfacp_billing_same_as_shipping']);

		if ($same_address) {

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
				'phone'
			];

			foreach ($fields as $field) {

				if (
					empty($data["billing_$field"]) &&
					!empty($data["shipping_$field"])
				) {
					$data["billing_$field"] = $data["shipping_$field"];
				}
			}

			if (empty($data['billing_email']) && !empty($_POST['contact_email'])) {
				$data['billing_email'] = sanitize_email($_POST['contact_email']);
			}
		}

		return $data;
	}

	/**
	 * Whether an order has already been paid for and must never be reused for a
	 * new payment attempt.
	 *
	 * A failed/cancelled attempt is retryable; a successful one is not.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return bool
	 */
	private function bytenft_order_is_finalized($order) {

		if (!$order instanceof WC_Order) {
			return false;
		}

		if ($order->has_status(['processing', 'completed', 'refunded'])) {
			return true;
		}

		if ($order->get_meta('_bytenft_payment_success') === 'yes') {
			return true;
		}

		if ($order->get_meta('_bytenft_state') === 'success') {
			return true;
		}

		return false;
	}

	/**
     * Handle the block checkout AJAX payment request using standard WooCommerce validation.
     */
    function handle_bytenft_gateway_ajax() {

        // 1. Nonce verification
        $nonce = isset($_POST['nonce'])
            ? sanitize_text_field(wp_unslash($_POST['nonce']))
            : '';

        if (empty($nonce) || !wp_verify_nonce($nonce, 'bytenft_payment')) {
            // Fallback: If strict nonce fails (due to WC session regeneration in Store API),
            // ensure the user has an active session and a non-empty cart.
            if ( function_exists('WC') && is_null(WC()->session) ) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }
            
            if ( ! function_exists('WC') || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
                wp_send_json([
                    'result'   => 'fail',
                    'messages' => '<ul class="woocommerce-error"><li>' . esc_html__('Security check failed. Please refresh the page.', 'bytenft-payment-gateway') . '</li></ul>',
                    'error'    => true
                ]);
                wp_die();
            }
        }

		/**
		 * Normalize POST data when billing address is the same as shipping.
		 * This ensures validation, order creation, and third-party plugins
		 * all receive complete billing data.
		 */
		$same_address =
			empty($_POST['ship_to_different_address']) ||
			$_POST['ship_to_different_address'] === '0' ||
			!empty($_POST['wfacp_billing_same_as_shipping']);

		if ($same_address) {

			foreach ([
				'first_name',
				'last_name',
				'company',
				'address_1',
				'address_2',
				'city',
				'state',
				'postcode',
				'country',
				'phone'
			] as $field) {

				if (
					empty($_POST["billing_$field"]) &&
					!empty($_POST["shipping_$field"])
				) {
					$_POST["billing_$field"] = wp_unslash($_POST["shipping_$field"]);
				}
			}

			if (empty($_POST['billing_email']) && !empty($_POST['contact_email'])) {
				$_POST['billing_email'] = sanitize_email(wp_unslash($_POST['contact_email']));
			}
		}

        // 2. Fetch Gateway Instance safely
        $gateways       = WC()->payment_gateways()->payment_gateways();
        $bytenftPayment = $gateways['bytenft'] ?? null;

        if (!$bytenftPayment) {
            $bytenftPayment = new BYTENFT_PAYMENT_GATEWAY();
            $bytenftPayment->init_settings();
            $bytenftPayment->load_gateway_settings();
        }

        // 3. Obtain or prepare order
        //
        // A failed payment is an ATTEMPT, not a reason to create a new order.
        // Since WooCommerce 10.8 the Store API only creates its draft order at
        // place-order time, and this handler bypasses that route, so
        // 'store_api_draft_order' is never populated for the ByteNFT block flow.
        // 'order_awaiting_payment' is therefore the authoritative pointer and is
        // written back below so every retry lands on the SAME order.
        $orderID = 0;
        if (WC()->session) {
            $orderID = WC()->session->get('order_awaiting_payment') ?: WC()->session->get('store_api_draft_order');
        }

        if (function_exists('wc_get_order')) {
            $order = $orderID ? wc_get_order($orderID) : false;

            // Never recycle an order that has already been paid for.
            if ($order && $this->bytenft_order_is_finalized($order)) {
                $order = false;
            }

            // A different customer needs a different order.
            //
            // Retrying keeps the same order only while it is the same customer.
            // Once the email or phone has been edited - typically after closing
            // the payment popup and correcting the details - this order and its
            // ByteNFT link belong to the old details, so it is released and a
            // brand new order is created below from the newly posted data.
            if ($order && bytenft_order_customer_identity_changed($order)) {

                ByteNFT_Payment_Gateway_Logger::info(
                    '[Order #' . $order->get_id() . '] Customer details changed, starting a new order',
                    [
                        'order_id'     => $order->get_id(),
                        'stored_email' => $order->get_meta('_bytenft_request_email'),
                        'stored_phone' => $order->get_meta('_bytenft_request_phone'),
                        'wc_status'    => $order->get_status(),
                    ]
                );

                $order->add_order_note(
                    __('Customer changed their contact details before retrying; a new order was started for the new details.', 'bytenft-payment-gateway')
                );

                $order->save();

                $order   = false;
                $orderID = 0;
            }

            // Re-open a retryable order from a previous failed/cancelled attempt
            // instead of abandoning it. This keeps order #1001 across attempts
            // 1..N and lets a later success finalize that same order.
            if ($order && $order->has_status(['failed', 'cancelled', 'on-hold'])) {
                $order->update_status(
                    'pending',
                    __('Reopened for a new ByteNFT payment attempt.', 'bytenft-payment-gateway')
                );
            }

            if (!$order && !empty(WC()->cart) && !WC()->cart->is_empty()) {
                $order = wc_create_order();
                if ($order) {
                    $orderID = $order->get_id();
                }
            }

            // Persist the pointer so the NEXT attempt reuses this order rather
            // than minting a fresh one.
            if ($order && WC()->session) {
                $orderID = $order->get_id();
                WC()->session->set('order_awaiting_payment', $orderID);
                WC()->session->save_data();
            }

            if ($order && ($order->has_status('checkout-draft') || $order->has_status('pending'))) {
                try {
                    $cart_hash = (!empty(WC()->cart)) ? WC()->cart->get_cart_hash() : '';

                    // Rebuild the line items when the order has none yet, or when
                    // the cart changed since the previous attempt. Reusing the
                    // order must never mean shipping stale contents.
                    $needs_items = count($order->get_items()) === 0
                        || ($cart_hash && $order->get_cart_hash() !== $cart_hash);

                    if ($needs_items && !empty(WC()->cart) && !WC()->cart->is_empty()) {
                        $order->remove_order_items('line_item');

                        foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
                            $item = new WC_Order_Item_Product();
                            $item->set_product($values['data']);
                            $item->set_quantity($values['quantity']);
                            $item->set_total($values['line_total']);
                            $item->set_subtotal($values['line_subtotal']);
                            $order->add_item($item);
                        }

                        $order->set_cart_hash($cart_hash);
                        $order->calculate_totals();
                    }

                    $customer = WC()->customer;
                    if ($customer) {
                        $order->set_billing_first_name(sanitize_text_field($_POST['billing_first_name'] ?? $customer->get_billing_first_name()));
                        $order->set_billing_last_name(sanitize_text_field($_POST['billing_last_name'] ?? $customer->get_billing_last_name()));
                        $order->set_billing_company(sanitize_text_field($_POST['billing_company'] ?? $customer->get_billing_company()));
                        $order->set_billing_address_1(sanitize_text_field($_POST['billing_address_1'] ?? $customer->get_billing_address_1()));
                        $order->set_billing_address_2(sanitize_text_field($_POST['billing_address_2'] ?? $customer->get_billing_address_2()));
                        $order->set_billing_city(sanitize_text_field($_POST['billing_city'] ?? $customer->get_billing_city()));
                        $order->set_billing_state(sanitize_text_field($_POST['billing_state'] ?? $customer->get_billing_state()));
                        $order->set_billing_postcode(sanitize_text_field($_POST['billing_postcode'] ?? $customer->get_billing_postcode()));
                        $order->set_billing_country(sanitize_text_field($_POST['billing_country'] ?? ($customer->get_billing_country() ?: 'US')));
                        $order->set_billing_email(sanitize_email($_POST['contact_email'] ?? $_POST['billing_email'] ?? $customer->get_billing_email()));
                        $order->set_billing_phone(sanitize_text_field($_POST['billing_phone'] ?? $customer->get_billing_phone()));

                        $order->set_shipping_first_name(sanitize_text_field($_POST['shipping_first_name'] ?? $customer->get_shipping_first_name()));
                        $order->set_shipping_last_name(sanitize_text_field($_POST['shipping_last_name'] ?? $customer->get_shipping_last_name()));
                        $order->set_shipping_company(sanitize_text_field($_POST['shipping_company'] ?? $customer->get_shipping_company()));
                        $order->set_shipping_address_1(sanitize_text_field($_POST['shipping_address_1'] ?? $customer->get_shipping_address_1()));
                        $order->set_shipping_address_2(sanitize_text_field($_POST['shipping_address_2'] ?? $customer->get_shipping_address_2()));
                        $order->set_shipping_city(sanitize_text_field($_POST['shipping_city'] ?? $customer->get_shipping_city()));
                        $order->set_shipping_state(sanitize_text_field($_POST['shipping_state'] ?? $customer->get_shipping_state()));
                        $order->set_shipping_postcode(sanitize_text_field($_POST['shipping_postcode'] ?? $customer->get_shipping_postcode()));
                        $order->set_shipping_country(sanitize_text_field($_POST['shipping_country'] ?? ($customer->get_shipping_country() ?: 'US')));
                    }

                    $order->set_currency(get_woocommerce_currency());
                    
                    if ((float) $order->get_total() < 0.01) {
                        $order->calculate_totals();
                    }
                    
                    $order->save();
                } catch (Exception $e) {
                    ByteNFT_Payment_Gateway_Logger::error('Order sync error: ' . $e->getMessage(), ['source' => 'bytenft-payment-gateway']);
                }
            }
        }

        // 4. FUTURE-PROOF NATIVE WOOCOMMERCE VALIDATION
        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }

        $checkout = WC()->checkout();
        $data     = $checkout->get_posted_data();
		$data = $this->normalize_checkout_data($data);
        $errors   = new WP_Error();

        // Normalize email payload parameter
        if (empty($data['billing_email']) && !empty($_POST['contact_email'])) {
            $data['billing_email'] = sanitize_email(wp_unslash($_POST['contact_email']));
        }

        // Run processes and hooks for custom/3rd party validation plugins
        do_action('woocommerce_checkout_process');
        do_action('woocommerce_after_checkout_validation', $data, $errors);

        // 5. RETURN ERRORS IF ANY VALIDATION FAILED
        $has_wp_errors = $errors->has_errors();
		$has_wc_errors = (function_exists('wc_notice_count') && wc_notice_count('error') > 0);

		if ($has_wp_errors || $has_wc_errors) {

			wc_clear_notices();

			$messages = array_unique($errors->get_error_messages());

			foreach ($messages as $message) {
				wc_add_notice($message, 'error');
			}

			$notices = wc_print_notices(true);

			wp_send_json([
				'result'   => 'fail',
				'messages' => $notices,
				'html'     => $notices,
				'error'    => true,
			]);

			wp_die();
		}

        // 6. PROCESS PAYMENT
        $order = $orderID ? wc_get_order($orderID) : false;

        if ($order instanceof WC_Order) {
            try {
                $status = $bytenftPayment->process_payment($orderID);
            } catch (\Exception $e) {
                wc_add_notice($e->getMessage(), 'error');
                $notices = wc_print_notices(true);
                $status = [
                    'result'   => 'fail',
                    'messages' => $notices,
                    'html'     => $notices,
                    'error'    => true,
                ];
            }
        } else {
            wc_add_notice(__('Invalid order.', 'bytenft-payment-gateway'), 'error');
            $notices = wc_print_notices(true);
            $status = [
                'result'   => 'fail',
                'messages' => $notices,
                'html'     => $notices,
                'error'    => true,
            ];
        }
        
        wp_send_json($status);
        wp_die();
    }

	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function bytenft_init()
	{

		if (!class_exists('WC_Checkout')) {
			return;
		}

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

	/**
	 * Handles ByteNFT popup close.
	 *
	 * This is a browser/AJAX request.
	 *
	 * Business rules:
	 * - If payment is already successful -> return Thank You URL.
	 * - If webhook already completed the order -> return Thank You URL.
	 * - If payment is still processing/pending -> do NOT redirect to checkout.
	 * - If payment failed/cancelled/expired -> return checkout URL.
	 * - If engine is locked -> reload persisted order state and wait for
	 *   the next poll unless the order has already become successful.
	 *
	 * @return void
	 */
	public function handle_popup_close()
	{
		$order_id = isset($_POST['order_id'])
			? sanitize_text_field(wp_unslash($_POST['order_id']))
			: 'unknown';

		$security = isset($_POST['security'])
			? sanitize_text_field(wp_unslash($_POST['security']))
			: '';

		$log_prefix = "[Order #{$order_id}] PopupClose";

		// ---------------------------------------------------------
		// 1. NONCE CHECK
		// ---------------------------------------------------------

		if (
			empty($security) ||
			!wp_verify_nonce($security, 'bytenft_payment')
		) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' | Invalid nonce'
			);

			wp_send_json_error([
				'reload' => true,
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 2. ORDER CHECK
		// ---------------------------------------------------------

		$order = wc_get_order($order_id);

		if (!$order) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' | Order not found'
			);

			wp_send_json_error([
				'reload' => true,
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 3. GET CURRENT PAYMENT TOKEN
		// ---------------------------------------------------------

		$payment_token = $order->get_meta('_bytenft_active_pay_id');

		if (empty($payment_token)) {
			$payment_token = $order->get_meta('_bytenft_pay_id');
		}

		// ---------------------------------------------------------
		// 4. ASK BYTE NFT FOR LATEST TRANSACTION STATUS
		// ---------------------------------------------------------

		$response = wp_remote_post(
			$this->get_api_url('/api/update-txn-status'),
			[
				'method'  => 'POST',

				'body' => wp_json_encode([
					'order_id'      => $order_id,
					'payment_token' => $payment_token,
				]),

				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $security,
				],

				'timeout' => 15,
			]
		);

		// ---------------------------------------------------------
		// 5. API ERROR
		// ---------------------------------------------------------

		if (is_wp_error($response)) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' | API error: ' . $response->get_error_message()
			);

			wp_send_json_error([
				'reload' => true,
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 6. DECODE RESPONSE
		// ---------------------------------------------------------

		$response_data = json_decode(
			wp_remote_retrieve_body($response),
			true
		);

		if (!is_array($response_data)) {

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' | Invalid API response'
			);

			wp_send_json_error([
				'reload' => true,
			]);

			wp_die();
		}

		$payment_status =
			$response_data['payment_status']
			?? $response_data['transaction_status']
			?? null;

		// ---------------------------------------------------------
		// 7. NO STATUS
		// ---------------------------------------------------------
		//
		// Do NOT immediately send customer to checkout.
		// The payment may have completed but the webhook may still
		// be processing.
		// ---------------------------------------------------------

		if (!$payment_status) {

			$order = wc_get_order($order_id);

			if ($order) {

				$wc_status = $order->get_status();

				// Webhook may have completed the order while the
				// status API returned nothing.
				if (
					in_array($wc_status, ['processing', 'completed'], true) ||
					$order->get_meta('_bytenft_payment_success') === 'yes' ||
					$order->get_meta('_bytenft_state') === 'success'
				) {

					wp_send_json([
						'success' => true,
						'message' => 'Payment completed successfully.',
						'data' => [
							'payment_status' => 'success',
							'order_status'   => $wc_status,
							'state'          => 'success',
							'redirect'       => $order->get_checkout_order_received_url(),
							'order_id'       => $order_id,
						],
					]);

					wp_die();
				}
			}

			wp_send_json([
				'success' => false,
				'message' => 'Payment status is being verified.',
				'data' => [
					'payment_status' => 'pending',
					'order_status'   => $order->get_status(),
					'state'          => 'processing',
					'redirect'       => null,
					'order_id'       => $order_id,
				],
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 8. ENGINE EVENT
		// ---------------------------------------------------------

		$result = BYTENFT_PAYMENT_ENGINE::handle_event(
			$order_id,
			'popup_close',
			[
				'status'        => $payment_status,
				'payment_token' => $payment_token,
			]
		);

		ByteNFT_Payment_Gateway_Logger::info(
			$log_prefix . ' | Engine result: ' .
			wp_json_encode($result)
		);

		// ---------------------------------------------------------
		// 9. ALWAYS RELOAD ORDER
		// ---------------------------------------------------------

		$order = wc_get_order($order_id);

		if (!$order) {

			wp_send_json_error([
				'reload' => true,
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 10. RESOLVE PERSISTED STATE
		// ---------------------------------------------------------

		$state = BYTENFT_PAYMENT_ENGINE::resolve_final_state(
			$order,
			$payment_status
		);

		$wc_status = $order->get_status();

		// ---------------------------------------------------------
		// 11. SUCCESS SAFETY OVERRIDE
		// ---------------------------------------------------------
		//
		// Your business rule:
		//
		// processing = success
		// completed  = success
		//
		// Also respect the explicit ByteNFT success markers.
		// ---------------------------------------------------------

		if (
			in_array($wc_status, ['processing', 'completed'], true) ||
			$order->get_meta('_bytenft_payment_success') === 'yes' ||
			$order->get_meta('_bytenft_state') === 'success'
		) {
			$state = 'success';
		}

		// ---------------------------------------------------------
		// 12. HANDLE ENGINE LOCK RACE
		// ---------------------------------------------------------
		//
		// If another request currently owns the engine lock,
		// NEVER overwrite that result.
		//
		// Reload the order and use the persisted state.
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
						$payment_status
					);
				}
			}
		}

		// ---------------------------------------------------------
		// 13. SUCCESS
		// ---------------------------------------------------------
		//
		// IMPORTANT:
		// If the status API returned success and the engine has
		// persisted success, immediately return the Thank You URL.
		//
		// This is what prevents the customer from ending up on
		// the broken checkout page.
		// ---------------------------------------------------------

		if ($state === 'success') {

			$thank_you_url =
				$order->get_checkout_order_received_url();

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix .
				' | SUCCESS | Returning Thank You redirect: ' .
				$thank_you_url
			);

			wp_send_json([
				'success' => true,
				'message' => 'Your payment was completed successfully.',
				'data' => [
					'payment_status' => $payment_status,
					'order_status'   => $wc_status,
					'state'          => 'success',
					'redirect'       => $thank_you_url,
					'order_id'       => $order_id,
				],
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 14. FAILED / CANCELLED / EXPIRED
		// ---------------------------------------------------------

		if (
			in_array(
				$state,
				['failed', 'cancelled', 'expired'],
				true
			)
		) {

			wp_send_json([
				'success' => false,
				'message' => match ($state) {
					'failed' =>
						'Payment failed. Please try again or use another method.',

					'cancelled' =>
						'You cancelled the payment.',

					'expired' =>
						'The payment session has expired.',

					default =>
						'Payment was not completed.',
				},

				'data' => [
					'payment_status' => $payment_status,
					'order_status'   => $wc_status,
					'state'          => $state,
					'redirect'       => wc_get_checkout_url(),
					'order_id'       => $order_id,
				],
			]);

			wp_die();
		}

		// ---------------------------------------------------------
		// 15. PROCESSING / NOTHING RECORDED
		// ---------------------------------------------------------

		wp_send_json([
			'success' => false,
			'message' => (
				$state === 'processing'
					? 'Your payment is still being processed. Please wait a moment.'
					: 'Your payment was not completed. Please try again when you are ready.'
			),

			'data' => [
				'payment_status' => $payment_status,
				'order_status'   => $wc_status,
				'state'          => $state ?: 'processing',
				'redirect'       => null,
				'order_id'       => $order_id,
			],
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


	public function bytenft_check_customer_account() {

		/*
		* ---------------------------------------------------------
		* SECURITY CHECK
		* ---------------------------------------------------------
		*/

		if ( ! isset( $_POST['security'] ) ) {

			ByteNFT_Payment_Gateway_Logger::info(
				'Customer account AJAX rejected - security token missing'
			);

			wp_send_json_error(
				[
					'message' => 'Security token is missing.',
				],
				400
			);
		}

		/*
		* ---------------------------------------------------------
		* PARSE CHECKOUT DATA
		* ---------------------------------------------------------
		*/

		$checkout_data = [];

		if ( isset( $_POST['checkout_data'] ) ) {

			parse_str(
				wp_unslash( $_POST['checkout_data'] ),
				$checkout_data
			);
		}

		/*
		* ---------------------------------------------------------
		* LOG IMPORTANT CUSTOMER DATA
		* ---------------------------------------------------------
		*
		* Do not log API keys/secrets here.
		*/

		ByteNFT_Payment_Gateway_Logger::info(
			'Customer account validation input',
			[
				'email'        => $checkout_data['billing_email']
					?? $checkout_data['contact_email']
					?? null,

				'phone'        => $checkout_data['billing_phone']
					?? null,

				'country_code' => $checkout_data['country_code']
					?? $checkout_data['billing_country']
					?? null,

				'first_name'   => $checkout_data['billing_first_name']
					?? null,

				'last_name'    => $checkout_data['billing_last_name']
					?? null,
			]
		);

		/*
		* ---------------------------------------------------------
		* CUSTOMER ACCOUNT VALIDATION / API CALL
		* ---------------------------------------------------------
		*/

		$result = $this->bytenft_validate_customer_account(
			$checkout_data
		);

		/*
		* ---------------------------------------------------------
		* LOG IMPORTANT API RESULT VALUES
		* ---------------------------------------------------------
		*/

		ByteNFT_Payment_Gateway_Logger::info(
			'Customer account validation result',
			[
				'valid'                  => $result['valid'] ?? null,
				'action'                 => $result['action'] ?? null,
				'requires_confirmation'  => $result['requires_confirmation'] ?? null,
				'existing_user'          => $result['existing_user'] ?? null,
				'user_id'                => $result['user_id'] ?? null,
				'existing_phone'         => $result['existing_phone'] ?? null,
				'message'                => $result['message'] ?? null,
				'phone_validation'       => $result['phone_validation'] ?? null,
			]
		);

		/*
		* ---------------------------------------------------------
		* PHONE VALIDATION
		* ---------------------------------------------------------
		*
		* IMPORTANT:
		*
		* The API can return:
		*
		* "valid": true
		*
		* while:
		*
		* "phone_validation": {
		*     "valid": false,
		*     "error": "VOIP number..."
		* }
		*
		* Phone validation must take priority.
		*
		* If phone_validation.valid === false:
		*
		* - Do NOT continue customer account logic.
		* - Do NOT show existing account confirmation.
		* - Do NOT store customer ID.
		* - Do NOT allow payment popup.
		* - Return the phone validation error to frontend.
		*/

		$phone_validation = $result['phone_validation'] ?? null;

		if (
			is_array( $phone_validation ) &&
			isset( $phone_validation['valid'] ) &&
			$phone_validation['valid'] === false
		) {

			$phone_error = $phone_validation['error']
				?? 'The phone number is invalid or not supported for payments.';

			ByteNFT_Payment_Gateway_Logger::info(
				'Customer phone validation FAILED - stopping customer account flow',
				[
					'phone'            => $checkout_data['billing_phone'] ?? null,
					'phone_validation' => $phone_validation,
					'phone_error'      => $phone_error,
					'api_valid'        => $result['valid'] ?? null,
					'existing_user'    => $result['existing_user'] ?? null,
					'user_id'          => $result['user_id'] ?? null,
					'action'            => $result['action'] ?? null,
				]
			);

			/*
			* IMPORTANT:
			*
			* Return error immediately.
			*
			* This prevents the existing-account confirmation popup.
			*/

			wp_send_json_error(
				[
					'message'          => $phone_error,
					'phone_validation' => $phone_validation,
					'status_code'      => $result['status_code'] ?? null,
					'api_response'     => $result['api_response'] ?? null,
				],
				400
			);
		}

		/*
		* ---------------------------------------------------------
		* GENERAL CUSTOMER VALIDATION ERROR
		* ---------------------------------------------------------
		*/

		if ( empty( $result['valid'] ) ) {

			ByteNFT_Payment_Gateway_Logger::info(
				'Customer account validation FAILED',
				[
					'message'          => $result['message']
						?? 'Unable to validate customer information.',

					'status_code'      => $result['status_code'] ?? null,

					'api_response'     => $result['api_response'] ?? null,

					'phone_validation' =>
						$result['phone_validation'] ?? null,
				]
			);

			wp_send_json_error(
				[
					'message' => $result['message']
						?? 'Unable to validate customer information.',

					'status_code' => $result['status_code'] ?? null,

					'api_response' => $result['api_response'] ?? null,

					'phone_validation' =>
						$result['phone_validation'] ?? null,
				],
				400
			);
		}

		/*
		* ---------------------------------------------------------
		* CUSTOMER ACCOUNT SESSION HANDLING
		* ---------------------------------------------------------
		*/

		$action = isset( $result['action'] )
			? sanitize_key( $result['action'] )
			: '';

		$existing_user = ! empty(
			$result['existing_user']
		);

		$requires_confirmation = ! empty(
			$result['requires_confirmation']
		);

		ByteNFT_Payment_Gateway_Logger::info(
			'Customer account decision received',
			[
				'action'                => $action,
				'existing_user'         => $existing_user,
				'requires_confirmation' => $requires_confirmation,
				'user_id'               => $result['user_id'] ?? null,
				'phone_validation'      => $result['phone_validation'] ?? null,
			]
		);

		if ( WC()->session ) {

			/*
			* -----------------------------------------------------
			* CASE 1:
			* EXISTING USER + CONFIRMATION REQUIRED
			* -----------------------------------------------------
			*/

			if (
				$existing_user &&
				$requires_confirmation &&
				! empty( $result['user_id'] )
			) {

				$detected_user_id = absint(
					$result['user_id']
				);

				/*
				* Clear any previously selected customer account.
				*/

				$previous_customer_id = WC()->session->get(
					'bytenft_customer_user_id'
				);

				WC()->session->__unset(
					'bytenft_customer_user_id'
				);

				/*
				* Mark session as awaiting customer choice.
				*/

				WC()->session->set(
					'bytenft_customer_account_action',
					'confirmation_required'
				);

				/*
				* Store detected ID separately.
				*
				* This is NOT the active customer ID.
				*/

				WC()->session->set(
					'bytenft_pending_customer_user_id',
					$detected_user_id
				);

				ByteNFT_Payment_Gateway_Logger::info(
					'Existing customer detected - waiting for customer confirmation',
					[
						'detected_user_id'        => $detected_user_id,
						'previous_customer_id'    => $previous_customer_id,
						'action'                  => $action,
						'requires_confirmation'   => $requires_confirmation,
						'customer_user_id_stored' => false,
						'phone_validation'        => $result['phone_validation'] ?? null,
					]
				);

			/*
			* -----------------------------------------------------
			* CASE 2:
			* EXISTING USER WITHOUT CONFIRMATION
			* -----------------------------------------------------
			*/

			} elseif (
				$existing_user &&
				! $requires_confirmation &&
				! empty( $result['user_id'] )
			) {

				$customer_user_id = absint(
					$result['user_id']
				);

				WC()->session->set(
					'bytenft_customer_user_id',
					$customer_user_id
				);

				WC()->session->set(
					'bytenft_customer_account_action',
					self::ACCOUNT_ACTION_USE_EXISTING
				);

				WC()->session->__unset(
					'bytenft_pending_customer_user_id'
				);

				ByteNFT_Payment_Gateway_Logger::info(
					'Existing customer account stored in WooCommerce session',
					[
						'customer_user_id'      => $customer_user_id,
						'action'                => $action,
						'requires_confirmation' => $requires_confirmation,
						'phone_validation'      => $result['phone_validation'] ?? null,
					]
				);

			/*
			* -----------------------------------------------------
			* CASE 3:
			* NEW CUSTOMER
			* -----------------------------------------------------
			*/

			} else {

				$previous_customer_id = WC()->session->get(
					'bytenft_customer_user_id'
				);

				WC()->session->__unset(
					'bytenft_customer_user_id'
				);

				WC()->session->__unset(
					'bytenft_pending_customer_user_id'
				);

				WC()->session->set(
					'bytenft_customer_account_action',
					self::ACCOUNT_ACTION_CREATE_NEW
				);

				ByteNFT_Payment_Gateway_Logger::info(
					'New customer account selected - previous customer session cleared',
					[
						'previous_customer_user_id' => $previous_customer_id,
						'action'                    => $action,
						'existing_user'             => $existing_user,
						'requires_confirmation'     => $requires_confirmation,
						'phone_validation'          => $result['phone_validation'] ?? null,
					]
				);
			}

			/*
			* -----------------------------------------------------
			* PERSIST WOOCOMMERCE SESSION
			* -----------------------------------------------------
			*/

			if ( method_exists( WC()->session, 'save_data' ) ) {

				WC()->session->save_data();

				ByteNFT_Payment_Gateway_Logger::info(
					'WooCommerce customer account session persisted',
					[
						'customer_user_id' =>
							WC()->session->get(
								'bytenft_customer_user_id'
							),

						'pending_customer_user_id' =>
							WC()->session->get(
								'bytenft_pending_customer_user_id'
							),

						'account_action' =>
							WC()->session->get(
								'bytenft_customer_account_action'
							),
					]
				);
			}
		}

		/*
		* ---------------------------------------------------------
		* FINAL RESPONSE TO FRONTEND
		* ---------------------------------------------------------
		*/

		wp_send_json_success(
			$result
		);
	}

	/**
	 * Validate customer account against ByteNFT API.
	 *
	 * Uses the existing WooCommerce checkout values.
	 *
	 * @return bool
	 */
	private function bytenft_validate_customer_account( $checkout_data = null ) {

		/*
		* Get checkout values.
		*/
		if ( is_array( $checkout_data ) ) {

			$email = isset( $checkout_data['billing_email'] )
				? sanitize_email( $checkout_data['billing_email'] )
				: '';

			// WooCommerce Blocks uses contact_email.
			if ( empty( $email ) && isset( $checkout_data['contact_email'] ) ) {
				$email = sanitize_email( $checkout_data['contact_email'] );
			}

			$phone = isset( $checkout_data['billing_phone'] )
				? sanitize_text_field( $checkout_data['billing_phone'] )
				: '';

			$country_code = isset( $checkout_data['billing_country'] )
				? sanitize_text_field( $checkout_data['billing_country'] )
				: '';

		} else {

			$email = isset( $_POST['billing_email'] )
				? sanitize_email( wp_unslash( $_POST['billing_email'] ) )
				: '';

			$phone = isset( $_POST['billing_phone'] )
				? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) )
				: '';

			$country_code = isset( $_POST['billing_country'] )
				? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) )
				: '';
		}

		$api_country_code = ( strtoupper( $country_code ) === 'US' )
		? '+1'
		: $country_code;

		/*
		* Nothing to validate.
		*/
		if ( empty( $email ) && empty( $phone ) ) {
			return [
				'valid'                 => true,
				'requires_confirmation' => false,
			];
		}

		/*
		* Customer validation API.
		*/
		$api_url = esc_url_raw(
			$this->base_url . '/api/check-customer'
		);

		$payload = [
			'email'        => $email ?: null,
			'phone_number' => $phone ?: null,
			'country_code' => $api_country_code ?: null,
		];

		ByteNFT_Payment_Gateway_Logger::info(
			'Customer account validation API request',
			[
				'url'     => $api_url,
				'payload' => $payload,
			]
		);

		$response = wp_remote_post(
			$api_url,
			[
				'method'    => 'POST',
				'timeout'   => 30,
				'body'      => $payload,
				'headers'   => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field( $this->public_key ),
				],
				'sslverify' => true,
			]
		);

		/*
		* WordPress HTTP error.
		*/
		if ( is_wp_error( $response ) ) {

			$error_message = $response->get_error_message();

			ByteNFT_Payment_Gateway_Logger::error(
				'Customer account validation WP HTTP error',
				[
					'error' => $error_message,
				]
			);

			return [
				'valid'   => false,
				'message' => $error_message,
			];
		}

		/*
		* Get API response.
		*/
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		$response_data = json_decode( $body, true );

		/*
		* Invalid JSON response.
		*/
		if ( ! is_array( $response_data ) ) {

			return [
				'valid'   => false,
				'message' => sprintf(
					'Invalid response from customer validation service. HTTP %d.',
					$status_code
				),
			];
		}

		/*
		* API returned an error.
		*/
		if (
			$status_code >= 400 ||
			( $response_data['status'] ?? '' ) === 'error'
		) {

			/*
			* Try all possible message locations.
			*/
			$message =
				$response_data['message']
				?? $response_data['data']['message']
				?? $response_data['error']
				?? 'Unable to validate customer information.';

			ByteNFT_Payment_Gateway_Logger::error(
				'Customer account validation API returned error',
				[
					'status_code' => $status_code,
					'message'     => $message,
					'response'    => $response_data,
				]
			);

			return [
				'valid'           => false,
				'message'         => sanitize_text_field( $message ),
				'status_code'     => $status_code,
				'api_response'    => $response_data,
				'phone_validation' => $response_data['data']['phone_validation'] ?? null,
			];
		}

		/*
		* Successful API response.
		*/
		$data = $response_data['data'] ?? [];

		/*
		* Preserve phone validation exactly as returned by API.
		*/
		$phone_validation = null;

		if (
			isset( $data['phone_validation'] ) &&
			is_array( $data['phone_validation'] )
		) {
			$phone_validation = $data['phone_validation'];
		}

		/*
		* Existing customer confirmation required.
		*/
		if (
			( $data['action'] ?? '' ) === 'confirmation_required'
			|| ! empty( $data['requires_confirmation'] )
		) {

			return [
				'valid'                 => true,
				'requires_confirmation' => true,
				'action'                => 'confirmation_required',
				'existing_user'         => ! empty( $data['existing_user'] ),

				'user_id' => isset( $data['user_id'] )
					? absint( $data['user_id'] )
					: 0,

				'existing_phone' => $data['existing_phone'] ?? null,

				/*
				* IMPORTANT:
				* Keep phone validation in the response.
				*/
				'phone_validation' => $phone_validation,

				'message' => ! empty( $data['message'] )
					? sanitize_text_field( $data['message'] )
					: __(
						'An existing customer account was found. Would you like to continue using that account?',
						'bytenft-payment-gateway'
					),
			];
		}

		/*
		* Normal customer.
		*/
		return [
			'valid'                 => true,
			'requires_confirmation' => false,
			'action'                => $data['action'] ?? null,
			'existing_user'         => ! empty( $data['existing_user'] ),

			'user_id' => isset( $data['user_id'] )
				? absint( $data['user_id'] )
				: 0,

			/*
			* Preserve phone validation for normal customer response too.
			*/
			'phone_validation' => $phone_validation,
		];
	}

	/**
	 * Get the account action selected during checkout.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_account_action( $order ) {

		$action = $order->get_meta( '_bytenft_account_action', true );

		if ( ! in_array(
			$action,
			[
				self::ACCOUNT_ACTION_USE_EXISTING,
				self::ACCOUNT_ACTION_CREATE_NEW,
			],
			true
		) ) {
			return '';
		}

		return $action;
	}

	public function bytenft_save_customer_account_action() {

		$log_prefix = '[Customer Account Selection]';

		ByteNFT_Payment_Gateway_Logger::info(
			$log_prefix . ' AJAX request received',
			[
				'has_security' => isset( $_POST['security'] ),
				'action'       => isset( $_POST['account_action'] )
					? sanitize_key( wp_unslash( $_POST['account_action'] ) )
					: '',
				'user_id'      => isset( $_POST['customer_user_id'] )
					? absint( wp_unslash( $_POST['customer_user_id'] ) )
					: 0,
			]
		);

		// -------------------------------------------------
		// SECURITY
		// -------------------------------------------------

		if ( ! isset( $_POST['security'] ) ) {

			ByteNFT_Payment_Gateway_Logger::warning(
				$log_prefix . ' Security token missing'
			);

			wp_send_json_error(
				[
					'message' => 'Missing security token.',
				],
				400
			);
		}

		$security = sanitize_text_field(
			wp_unslash( $_POST['security'] )
		);

		if ( ! wp_verify_nonce( $security, 'bytenft_payment' ) ) {

			ByteNFT_Payment_Gateway_Logger::warning(
				$log_prefix . ' Security check failed'
			);

			wp_send_json_error(
				[
					'message' => 'Security check failed.',
				],
				403
			);
		}

		// -------------------------------------------------
		// WOOCOMMERCE SESSION
		// -------------------------------------------------

		if ( ! WC()->session ) {

			ByteNFT_Payment_Gateway_Logger::error(
				$log_prefix . ' WooCommerce session unavailable'
			);

			wp_send_json_error(
				[
					'message' => 'Checkout session is unavailable.',
				],
				500
			);
		}

		// -------------------------------------------------
		// INPUT
		// -------------------------------------------------

		$account_action = isset( $_POST['account_action'] )
			? sanitize_key(
				wp_unslash( $_POST['account_action'] )
			)
			: '';

		$customer_user_id = isset( $_POST['customer_user_id'] )
			? absint(
				wp_unslash( $_POST['customer_user_id'] )
			)
			: 0;

		// -------------------------------------------------
		// VALIDATE ACTION
		// -------------------------------------------------

		$allowed_actions = [
			self::ACCOUNT_ACTION_USE_EXISTING,
			self::ACCOUNT_ACTION_CREATE_NEW,
		];

		if ( ! in_array( $account_action, $allowed_actions, true ) ) {

			ByteNFT_Payment_Gateway_Logger::warning(
				$log_prefix . ' Invalid account action',
				[
					'action'  => $account_action,
					'user_id' => $customer_user_id,
				]
			);

			wp_send_json_error(
				[
					'message' => 'Invalid customer account action.',
				],
				400
			);
		}

		// -------------------------------------------------
		// USE EXISTING CUSTOMER
		// -------------------------------------------------

		if ( self::ACCOUNT_ACTION_USE_EXISTING === $account_action ) {

			if ( $customer_user_id <= 0 ) {

				ByteNFT_Payment_Gateway_Logger::warning(
					$log_prefix . ' Customer ID missing for existing account'
				);

				wp_send_json_error(
					[
						'message' => 'Customer account ID is required.',
					],
					400
				);
			}

			/*
			* We only save the selected customer here.
			*
			* Do NOT create/update the customer in this AJAX
			* handler. requestPayment() will handle the actual
			* customer/payment logic later.
			*/

			WC()->session->set(
				'bytenft_customer_account_action',
				self::ACCOUNT_ACTION_USE_EXISTING
			);

			WC()->session->set(
				'bytenft_customer_user_id',
				$customer_user_id
			);

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' Existing customer selected',
				[
					'action'  => self::ACCOUNT_ACTION_USE_EXISTING,
					'user_id' => $customer_user_id,
				]
			);
		}

		// -------------------------------------------------
		// CREATE NEW CUSTOMER
		// -------------------------------------------------

		if ( self::ACCOUNT_ACTION_CREATE_NEW === $account_action ) {

			/*
			* Save only the decision.
			*
			* requestPayment() will create/find the appropriate
			* customer when payment processing starts.
			*/

			WC()->session->set(
				'bytenft_customer_account_action',
				self::ACCOUNT_ACTION_CREATE_NEW
			);

			// Make sure an old selected customer is not reused.
			WC()->session->__unset(
				'bytenft_customer_user_id'
			);

			ByteNFT_Payment_Gateway_Logger::info(
				$log_prefix . ' CREATE NEW customer selected',
				[
					'action'  => self::ACCOUNT_ACTION_CREATE_NEW,
					'user_id' => 0,
				]
			);
		}

		// -------------------------------------------------
		// PERSIST SESSION
		// -------------------------------------------------

		WC()->session->save_data();

		// -------------------------------------------------
		// RESPONSE
		// -------------------------------------------------

		wp_send_json_success(
			[
				'action'  => $account_action,
				'user_id' => self::ACCOUNT_ACTION_USE_EXISTING === $account_action
					? $customer_user_id
					: 0,
			]
		);
	}
}
