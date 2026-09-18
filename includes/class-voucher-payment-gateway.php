<?php
if (!defined('ABSPATH')) {
	exit();
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-voucher-payment-logger.php';

class VOUCHER_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'voucher';

	protected $sandbox;
	private $base_url;
	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

	private static $log_once_flags = [];


	/**
	 * Account selected during the availability filter for dynamic title/subtitle.
	 *
	 * @var array|null
	 */
	private $selected_account_for_display = null;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'woocommerce_not_active_notice']);
			return;
		}

		$this->admin_notices = new VOUCHER_PAYMENT_GATEWAY_Admin_Notices();
		$this->base_url      = VOUCHER_BASE_URL;

		$this->id                 = self::ID;
		$this->icon               = '';
		$this->method_title       = __('Voucher Payment Gateway', 'voucher-payment-gateway');
		$this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration.', 'voucher-payment-gateway');

		$this->voucher_init_form_fields();
		$this->init_settings();
		$this->settings['group_id'] = get_option('voucher_group_id') ? get_option('voucher_group_id') : $this->voucher_get_group_id();
		$this->load_gateway_settings();

		$this->register_hooks();
	}

	/**
	 * Load gateway settings.
	 * Called once in constructor AND can be re-called to refresh in AJAX context.
	 */
	public function load_gateway_settings() {
		$this->title       = sanitize_text_field($this->get_option('title'));
		$this->description = !empty($this->get_option('description'))
			? sanitize_textarea_field($this->get_option('description'))
			: ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);

		$this->enabled    = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox    = 'yes' === sanitize_text_field($this->get_option('sandbox'));
		$this->public_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_public_key' : 'public_key'));
		$this->secret_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_secret_key' : 'secret_key'));
		$this->current_account_index = 0;
	}

	/**
	 * Register hooks for the gateway.
	 */
	private function register_hooks() {
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'voucher_process_admin_options']);
		add_action('wp_enqueue_scripts', [$this, 'voucher_enqueue_styles_and_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'voucher_admin_scripts']);

		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'voucher_display_test_order_tag']);
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'voucher_add_custom_label_to_order_row'], 10, 2);
		add_filter('woocommerce_available_payment_gateways', [$this, 'voucher_hide_custom_payment_gateway_conditionally']);

		add_action('woocommerce_after_checkout_validation', [$this, 'voucher_validate_checkout_fields'], 10, 2);
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			[$this, 'voucher_validate_blocks_checkout'],
			10,
			2
		);

		add_action('wp_ajax_voucher_log_event', [$this, 'handle_log_event']);
		add_action('wp_ajax_nopriv_voucher_log_event', [$this, 'handle_log_event']);

		add_action('woocommerce_thankyou_' . $this->id, [$this, 'voucher_thankyou_payment_link_notice']);

	}

	/**
	 * Strict validation for Phone and Zip Code.
	 * Runs before process_payment to ensure only clean data reaches your API.
	 */
	public function voucher_validate_checkout_fields($data, $errors)
	{
		$selected_gateway = wc_clean(
			wp_unslash($_POST['payment_method'] ?? '')
		);

		if (empty($selected_gateway)) {
			return;
		}

		if ($selected_gateway !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($data['billing_phone'] ?? '');
		$country = strtoupper($data['billing_country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->voucher_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				Voucher_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'country'    => $country,
						'normalized' => $normalized,
						'error'      => $normalized['error'] ?? null,
					]
				);

				$errors->add(
					'voucher_phone_error',
					$normalized['error'] ?? __('Invalid phone number.', 'voucher-payment-gateway')
				);

				return;
			}

			Voucher_Payment_Gateway_Logger::info(
				'Classic checkout phone validation passed',
				[
					'phone'      => $phone,
					'country'    => $country,
					'normalized' => $normalized,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($data['billing_address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			Voucher_Payment_Gateway_Logger::warning(
				'Classic checkout validation failed: PO Box detected',
				[
					'address' => $billing_address_1,
					'country' => $country,
				]
			);

			$errors->add(
				'voucher_po_box_error',
				__('PO Box addresses are not accepted. Please enter a physical street address.', 'voucher-payment-gateway')
			);

			return;
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($data['billing_postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				Voucher_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid postcode',
					[
						'postcode' => $postcode,
						'country'  => $country
					]
				);

				$errors->add(
					'voucher_postcode_error',
					__('Invalid ZIP / postal code.', 'voucher-payment-gateway')
				);

				return;
			}

			Voucher_Payment_Gateway_Logger::info(
				'Classic checkout postcode validation passed',
				[
					'postcode' => $postcode,
					'country'  => $country
				]
			);
		}
	}


	public function voucher_validate_blocks_checkout($order, $request)
	{
		$payment_method = $request['payment_method'] ?? '';

		if (empty($payment_method)) {
			return;
		}

		if ($payment_method !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($request['billing_address']['phone'] ?? '');
		$country = strtoupper($request['billing_address']['country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->voucher_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				Voucher_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'country'    => $country,
						'normalized' => $normalized,
						'order_id'   => $order->get_id() ?? null,
						'error'      => $normalized['error'] ?? null,
					]
				);

				throw new Exception(
					$normalized['error'] ?? 'Invalid phone number.'
				);
			}

			// Sync latest validated value to order
			$order->set_billing_phone($phone);

			Voucher_Payment_Gateway_Logger::info(
				'Blocks checkout phone validation passed',
				[
					'phone'      => $phone,
					'country'    => $country,
					'normalized' => $normalized,
					'order_id'   => $order->get_id() ?? null,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($request['billing_address']['address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			Voucher_Payment_Gateway_Logger::warning(
				'Blocks checkout validation failed: PO Box detected',
				[
					'address'  => $billing_address_1,
					'country'  => $country,
					'order_id' => $order->get_id() ?? null,
				]
			);

			throw new Exception(
				'PO Box addresses are not accepted. Please enter a physical street address.'
			);
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($request['billing_address']['postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				Voucher_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid postcode',
					[
						'postcode' => $postcode,
						'country'  => $country,
						'order_id' => $order->get_id() ?? null
					]
				);

				throw new Exception(
					'Invalid ZIP / postal code.'
				);
			}

			$order->set_billing_postcode($postcode);

			Voucher_Payment_Gateway_Logger::info(
				'Blocks checkout postcode validation passed',
				[
					'postcode' => $postcode,
					'country'  => $country,
					'order_id' => $order->get_id() ?? null
				]
			);
		}

		return $order;
	}

	private function get_api_url($endpoint) {
		return $this->base_url . $endpoint;
	}

	public function voucher_process_admin_options() {
		$enabled     = isset($_POST['woocommerce_' . $this->id . '_enabled']) ? 'yes' : 'no';
		$accounts    = isset($_POST['accounts']) ? $_POST['accounts'] : [];
		$keys_entered = false;

		if (!empty($accounts)) {
			foreach ($accounts as $account) {
				if (
					!empty($account['live_public_key']) ||
					!empty($account['live_secret_key']) ||
					!empty($account['sandbox_public_key']) ||
					!empty($account['sandbox_secret_key'])
				) {
					$keys_entered = true;
					break;
				}
			}
		}

		parent::process_admin_options();

		if (!isset($_POST['voucher_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['voucher_accounts_nonce'])), 'voucher_accounts_nonce_action')) {
			Voucher_Payment_Gateway_Logger::info('CSRF check failed during admin options update.');
			wp_die(esc_html__('Security check failed!', 'voucher-payment-gateway'));
		}

		$errors             = [];
		$valid_accounts     = [];
		$unique_live_keys   = [];
		$unique_sandbox_keys = [];
		$normalized_index   = 0;
		$raw_accounts       = [];

		if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$unslashed_accounts = wp_unslash($_POST['accounts']);
			$raw_accounts = array_map(
				static function ($account) {
					return is_array($account)
						? array_map('sanitize_text_field', $account)
						: sanitize_text_field($account);
				},
				$unslashed_accounts
			);
		}

		if (!is_array($raw_accounts) || empty($raw_accounts)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'voucher-payment-gateway');
			Voucher_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
		}

		foreach ((array) $raw_accounts as $account) {
			if (!is_array($account)) continue;

			$account = array_map('sanitize_text_field', $account);

			$account_title      = $account['title'] ?? '';
			$priority           = intval($account['priority'] ?? 1);
			$live_public_key    = $account['live_public_key'] ?? '';
			$live_secret_key    = $account['live_secret_key'] ?? '';
			$sandbox_public_key = $account['sandbox_public_key'] ?? '';
			$sandbox_secret_key = $account['sandbox_secret_key'] ?? '';
			$has_sandbox         = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			$live_status        = $account['live_status'] ?? 'Active';
			$sandbox_status     = $has_sandbox ? ($account['sandbox_status'] ?? 'Active') : '';
			$unique_id          = $account['unique_id'] ?? '';
			$checkout_title      = $account['checkout_title'] ?? '';
	        $checkout_subtitle   = $account['checkout_subtitle'] ?? '';

			if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
				continue;
			}

			if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
				$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'voucher-payment-gateway'), $account_title);
				Voucher_Payment_Gateway_Logger::info("Validation failed: missing required fields for account '{$account_title}'");
				continue;
			}

			$live_combined = $live_public_key . '|' . $live_secret_key;
			if (in_array($live_combined, $unique_live_keys, true)) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'voucher-payment-gateway'), $account_title);
				Voucher_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
				continue;
			}

			if ($live_public_key === $live_secret_key) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'voucher-payment-gateway'), $account_title);
				Voucher_Payment_Gateway_Logger::info("Validation warning: live keys are identical for account '{$account_title}'");
			}

			$unique_live_keys[] = $live_combined;

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'voucher-payment-gateway'), $account_title);
					Voucher_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'voucher-payment-gateway'), $account_title);
					Voucher_Payment_Gateway_Logger::info("Validation warning: sandbox keys are identical for account '{$account_title}'");
				}
				$unique_sandbox_keys[] = $sandbox_combined;
			}

			$valid_accounts[$normalized_index++] = [
				'title'              => $account_title,
				'priority'           => $priority,
				'live_public_key'    => $live_public_key,
				'live_secret_key'    => $live_secret_key,
				'sandbox_public_key' => $sandbox_public_key,
				'sandbox_secret_key' => $sandbox_secret_key,
				'has_sandbox'        => $has_sandbox ? 'on' : 'off',
				'sandbox_status'     => $sandbox_status,
				'live_status'        => $live_status,
				'unique_id'          => $unique_id,
				'checkout_title'     => $checkout_title,
	            'checkout_subtitle'  => $checkout_subtitle,
			];

			Voucher_Payment_Gateway_Logger::info("Validated and added account '{$account_title}' to saved list.");
		}

		if (empty($valid_accounts) && empty($errors)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'voucher-payment-gateway');
			Voucher_Payment_Gateway_Logger::info('All submitted accounts failed validation. No accounts will be saved.');
		}

		if (empty($errors)) {
			update_option('woocommerce_voucher-payment-gateway_accounts', $valid_accounts);

			$public_key    = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$api_url       = esc_url($this->base_url . '/api/plugin/check/plugin');
			$plugin_version = VOUCHER_PLUGIN_VERSION;

			global $wp_version;

			$body = [
				'valid_accounts' => $valid_accounts,
				'plugin_status'  => $enabled === 'yes' ? 1 : 0,
				'plugin_version' => $plugin_version,
				'gateway_loaded' => 0,
				'group_id'       => get_option('voucher_group_id'),
				'domain_name'    => parse_url(home_url(), PHP_URL_HOST),
				'wordpress_version'     => $wp_version,
				'woocommerce_version'   => class_exists('WooCommerce') ? WC()->version : null,
				'woocommerce_db_version'=> get_option('woocommerce_db_version'),
			];

			wp_remote_post($api_url, [
				'method'    => 'POST',
				'timeout'   => 30,
				'body'      => $body,
				'headers'   => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'sslverify' => true,
			]);

			Voucher_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('VOUCHER_PAYMENT_GATEWAY_Loader')) {
				$loader = VOUCHER_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event();
					Voucher_Payment_Gateway_Logger::info('Triggered VOUCHER_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');
				}
			}
		} else {
			foreach ($errors as $error) {
				$this->admin_notices->voucher_add_notice('settings_error', 'notice notice-error', $error);
				Voucher_Payment_Gateway_Logger::info("Admin settings error: {$error}");
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}

	public function get_updated_account() {
		$accounts       = get_option('woocommerce_voucher-payment-gateway_accounts', []);
		$valid_accounts = [];

		foreach ($accounts as $index => $account) {
			$useSandbox = $this->sandbox;
			$secretKey  = $useSandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			$publicKey  = $useSandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

			Voucher_Payment_Gateway_Logger::info("Checking merchant status for account '{$account['title']}'", [
				'useSandbox' => $useSandbox,
				'publicKey'  => $publicKey,
			]);

			$checkStatusUrl = $this->get_api_url('/api/check-merchant-status');
			$response = wp_remote_post($checkStatusUrl, [
				'headers' => [
					'Authorization' => 'Bearer ' . $publicKey,
					'Content-Type'  => 'application/json',
				],
				'timeout' => 10,
				'body'    => wp_json_encode([
					'api_secret_key' => $secretKey,
					'is_sandbox'     => $useSandbox,
				]),
			]);

			$body    = json_decode(wp_remote_retrieve_body($response), true);
			$isError = is_array($body) && strtolower($body['status'] ?? '') === 'error';

			$valid_accounts[] = [
				'title'              => $account['title'],
				'priority'           => $account['priority'],
				'live_public_key'    => $account['live_public_key'],
				'live_secret_key'    => $account['live_secret_key'],
				'sandbox_public_key' => $account['sandbox_public_key'],
				'sandbox_secret_key' => $account['sandbox_secret_key'],
				'has_sandbox'        => $account['has_sandbox'],
				'sandbox_status'     => $isError ? 'Inactive' : 'Active',
				'live_status'        => $isError ? 'Inactive' : 'Active',
				'checkout_title'     => $account['checkout_title'] ?? '',
	            'checkout_subtitle'  => $account['checkout_subtitle'] ?? '',
			];

			if ($isError) {
				Voucher_Payment_Gateway_Logger::info("Account '{$account['title']}' is inactive", ['response' => $body]);
			} else {
				Voucher_Payment_Gateway_Logger::info("Account '{$account['title']}' is active");
			}
		}

		if (!empty($valid_accounts)) {
			update_option('woocommerce_voucher-payment-gateway_accounts', $valid_accounts);
			return true;
		}

		Voucher_Payment_Gateway_Logger::info('No active account. Removing voucher gateway.');
		return false;
	}

	public function voucher_init_form_fields() {
		$this->form_fields = $this->voucher_get_form_fields();
	}

	function voucher_get_group_id() {
		$group_id = get_option('voucher_group_id');
		if (empty($group_id)) {
			$group_id = 'grp_' . wp_rand(100000, 999999);
			update_option('voucher_group_id', $group_id);
		}
		return $group_id;
	}

	function voucher_get_unique_id() {
		$unique_id = get_option('voucher_unique_id');
		if (empty($unique_id)) {
			$unique_id = 'acc_' . wp_rand(100000, 999999);
		}
		return $unique_id;
	}

	function update_accounts_uniqueID($accounts) {
		if (empty($accounts) || !is_array($accounts)) return $accounts;
		$updated = false;
		foreach ($accounts as $index => &$account) {
			if (!is_array($account)) continue;
			if (empty($account['unique_id'])) {
				$account['unique_id'] = $this->voucher_get_unique_id();
				$updated = true;
			}
		}
		unset($account);
		if ($updated) {
			update_option('woocommerce_voucher-payment-gateway_accounts', $accounts);
		}
		return $accounts;
	}

	public function voucher_get_form_fields() {
		$dev_instructions_link = sprintf(
			'<strong><a class="voucher-instructions-url" href="%s" target="_blank">%s</a></strong><br>',
			esc_url($this->base_url . '/developers'),
			__('click here to access your developer account', 'voucher-payment-gateway')
		);

		return apply_filters('voucher_woocommerce_gateway_settings_fields_' . $this->id, [

			'enabled' => [
				'title'   => __('Enable/Disable', 'voucher-payment-gateway'),
				'label'   => __('Enable Voucher Payment Gateway', 'voucher-payment-gateway'),
				'type'    => 'checkbox',
				'default' => 'no',
			],

			'title' => [
				'title'       => __('Title', 'voucher-payment-gateway'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'voucher-payment-gateway'),
				'default'     => __('Buy with USDC Using Your Credit/Debit Card, Apple Pay or Google Pay — Secure, Modern Checkout 🔐', 'voucher-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'voucher-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('Provide a brief description of the payment option.', 'voucher-payment-gateway'),
				'default'     => __(
					'<p style="margin:0 0 6px; font-size:13px;">Use a Credit Card, Debit Card or Google Pay, Apple Pay to complete your purchase via USDC.</p>
					<p style="margin:0 0 6px; font-size:13px;">The transaction will appear on your bank or card statement as *Voucher</p>',
					'voucher-payment-gateway'
				),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('Instructions', 'voucher-payment-gateway'),
				'type'        => 'title',
				'description' => sprintf(__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'voucher-payment-gateway'), $dev_instructions_link, ''),
				'desc_tip'    => true,
			],

			'sandbox' => [
				'title'       => __('Sandbox', 'voucher-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'voucher-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Use sandbox API keys (real payments will not be taken).', 'voucher-payment-gateway'),
				'default'     => 'no',
			],

			'group_id' => [
				'type' => 'hidden',
			],

			'accounts' => [
				'title'       => __('Payment Accounts', 'voucher-payment-gateway'),
				'type'        => 'accounts_repeater',
				'description' => __('Add multiple payment accounts dynamically.', 'voucher-payment-gateway'),
			],

			'order_status' => [
				'title'       => __('Order Status', 'voucher-payment-gateway'),
				'type'        => 'select',
				'description' => __('Order status after successful payment.', 'voucher-payment-gateway'),
				'default'     => '',
				'id'          => 'order_status_select',
				'desc_tip'    => true,
				'options'     => [
					'processing' => __('Processing', 'voucher-payment-gateway'),
					'completed'  => __('Completed', 'voucher-payment-gateway'),
				],
			],

			'show_consent_checkbox' => [
				'title'       => __('Show Consent Checkbox', 'voucher-payment-gateway'),
				'label'       => __('Enable consent checkbox on checkout page', 'voucher-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Show a checkbox for user consent during checkout.', 'voucher-payment-gateway'),
				'default'     => 'no',
			],

		], $this);
	}

	public function generate_accounts_repeater_html($key, $data) {
		$option_value    = get_option('woocommerce_voucher-payment-gateway_accounts', []);
		$option_value    = maybe_unserialize($option_value);
		$active_account  = get_option('voucher_active_account', 0);
		$global_settings = get_option('woocommerce_voucher_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		$updated = false;
		if (!empty($option_value)) {
			foreach ($option_value as $index => &$account) {
				if (empty($account['unique_id'])) {
					$account['unique_id'] = $this->voucher_get_unique_id();
					$updated = true;
				}
				// Ensure all fields are present for new/empty accounts
				if (!isset($account['checkout_title'])) {
					$account['checkout_title'] = '';
				}
				if (!isset($account['checkout_subtitle'])) {
					$account['checkout_subtitle'] = '';
				}
			}
		}
		unset($account);

		if ($updated) {
			update_option('woocommerce_voucher-payment-gateway_accounts', $option_value);
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="voucher-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="voucher-sync-account">
							<span id="voucher-sync-status"></span>
							<button class="button" id="voucher-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'voucher-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>

					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'voucher-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status    = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id      = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							?>
							<div class="voucher-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'voucher-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down <?php echo esc_attr($this->id); ?>-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<span class="account-status-label <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> <?php echo esc_attr(strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'voucher-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'voucher-payment-gateway') . esc_html(ucfirst($live_status));
												}
												?>
											</span>
										</div>
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>

								<div class="<?php echo esc_attr($this->id); ?>-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'voucher-payment-gateway'); ?></label>
											<input type="text" class="account-title" name="accounts[<?php echo esc_attr($index); ?>][title]" placeholder="<?php esc_attr_e('Account Title', 'voucher-payment-gateway'); ?>" value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div>
											<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][unique_id]" value="<?php echo esc_attr($unique_id); ?>" readonly>
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'voucher-payment-gateway'); ?></label>
											<input type="number" class="account-priority" name="accounts[<?php echo esc_attr($index); ?>][priority]" placeholder="<?php esc_attr_e('Priority', 'voucher-payment-gateway'); ?>" value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>

									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Title', 'voucher-payment-gateway'); ?></label>
											<input type="text"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_title]"
												placeholder="<?php esc_attr_e('Title shown to customers at checkout', 'voucher-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['checkout_title'] ?? ''); ?>">
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Subtitle', 'voucher-payment-gateway'); ?></label>
											<textarea
												name="accounts[<?php echo esc_attr($index); ?>][checkout_subtitle]"
												placeholder="<?php esc_attr_e('Subtitle/description shown below the title at checkout', 'voucher-payment-gateway'); ?>"
												rows="2"><?php echo esc_textarea($account['checkout_subtitle'] ?? ''); ?></textarea>
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'voucher-payment-gateway'); ?></label>
											<input type="text" class="live-public-key" name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" placeholder="<?php esc_attr_e('Public Key', 'voucher-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'voucher-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
										$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked($account['has_sandbox'] == 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('Do you have the sandbox keys?', 'voucher-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = $account['has_sandbox'] == 'off' ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'voucher-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Public Key', 'voucher-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="text" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'voucher-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('voucher_accounts_nonce_action', 'voucher_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button voucher-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'voucher-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function process_payment($order_id, $used_accounts = [])
	{
		global $wpdb;

		$lock_name = '';

		$log_prefix = "[Order #{$order_id}]";

		$start_time = microtime(true);

		Voucher_Payment_Gateway_Logger::info(
			$log_prefix . ' Payment process started',
			[
				'order_id' => $order_id
			]
		);

		wc_clear_notices();

		// -------------------------------------------------
		// 1. ORDER VALIDATION
		// -------------------------------------------------
		$order = wc_get_order($order_id);

		if (!$order) {


			if (is_checkout()) {
				wc_add_notice(__('Invalid order.', 'voucher-payment-gateway'), 'error');
			}

			return $this->build_response(
				'fail',
				'Invalid order.',
				[],
				400,
				$order_id
			);
		}

		Voucher_Payment_Gateway_Logger::info(
			"Payment initiated",
			[
				'order_id' => $order_id,
				'status'   =>  $order->get_status()
			]
		);

		$lock_name = 'voucher_order_' . $order_id;

		// Try lock
		$lock_result = $wpdb->get_var(
			$wpdb->prepare("SELECT GET_LOCK(%s, 3)", $lock_name)
		);

		if ((string)$lock_result !== '1') {
			return $this->build_response(
				'fail',
				'Payment already in progress. Please wait a few seconds and try again.',
				[],
				409,
				$order_id
			);
		}

		try {

			// -------------------------------------------------
			// 4. RATE LIMITING (UNCHANGED)
			// -------------------------------------------------
			$ip_address  = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'invalid';
			$window_size = 10;
			$max_requests = 5;

			$timestamp_key = "rate_limit_{$ip_address}_timestamps";
			$timestamps    = get_transient($timestamp_key) ?: [];
			$current_time  = time();

			$timestamps = array_filter($timestamps, fn($ts) => $current_time - $ts <= $window_size);

			if (count($timestamps) >= $max_requests) {

				Voucher_Payment_Gateway_Logger::warning(
					$log_prefix . ' Rate limit exceeded',
					[
						'ip_address' => $ip_address,
					]
				);

				if (is_checkout()) {
					wc_add_notice(__('Too many requests. Please try again later.', 'voucher-payment-gateway'), 'error');
				}

				return $this->build_response(
					'fail',
					'Too many requests. Please try again later.',
					[],
					429,
					$order_id
				);
			}

			$timestamps[] = $current_time;
			set_transient($timestamp_key, $timestamps, $window_size);

			// -------------------------------------------------
			// 5. ORDER STATUS PROTECTION
			// -------------------------------------------------
			$status = $order->get_status();

			if ($status === 'completed' || $status === 'processing') {

				if (WC()->cart) {
					WC()->cart->empty_cart();
				}

				$redirect = $status === 'completed'
				? $order->get_checkout_order_received_url()
				: $order->get_cancel_order_url();

				return $this->build_response(
					'success',
					'Order already processed',
					[],
					200,
					$order->get_id()
				);
			}

			// -------------------------------------------------
			// 6. SANDBOX FLAG (UNCHANGED)
			// -------------------------------------------------
			if ($this->sandbox) {
				if (!$order->get_meta('_is_test_order')) {
					$order->update_meta_data('_is_test_order', true);
					voucher_add_unique_order_note(
						$order,
						'sandbox_mode',
						__('This is a test order processed in sandbox mode.', 'voucher-payment-gateway')
					);
				}
			}

			// -------------------------------------------------
			// 7. VOUCHER EMAIL (SENT BY VOUCHER)
			// -------------------------------------------------
			// Checkout creates no payment link. Voucher emails the customer a
			// voucher, and the payment link behind its button is created when they
			// open it. All this plugin does is ask for that email and repeat what
			// Voucher says about it.
			$order->update_status('pending', __('Awaiting voucher purchase.', 'voucher-payment-gateway'));

			$voucher = $this->voucher_request_voucher_email($order);

			if (!$voucher['success']) {

				$voucher_error = $voucher['message'];

				// Classic checkout only relays queued notices in its failure response.
				if (!$this->is_block_checkout_request() && is_checkout()) {
					wc_add_notice($voucher_error, 'error');
				}

				return $this->build_response(
					'fail',
					$voucher_error,
					[],
					502,
					$order_id
				);
			}

			$this->voucher_record_voucher_sent($order, $voucher['data']);

			Voucher_Payment_Gateway_Logger::info(
				$log_prefix . ' Voucher email requested',
				[
					'order_id'  => $order_id,
					'reference' => $voucher['data']['reference'] ?? null,
				]
			);

			return $this->build_response(
				'success',
				$voucher['message'],
				[
					'payment_status' => 'pending',
					'order_received' => [
						'order_number' => $order->get_order_number(),
						'email'        => $order->get_billing_email(),
						'items'        => $this->voucher_get_summary_rows($order),
						'amount_due'   => $this->voucher_plain_price($order->get_total(), $order),
						'site_name'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
						// Shown on the checkout exactly as Voucher worded it.
						'message'      => $voucher['message'],
						'reference'    => $voucher['data']['reference'] ?? '',
					],
					// Followed by non-AJAX submissions such as the order-pay page.
					'redirect'       => $order->get_checkout_order_received_url(),
				],
				200,
				$order_id
			);

			} catch (\Exception $e) {

				Voucher_Payment_Gateway_Logger::error(
					"Payment processing exception: " . $e->getMessage(),
					[
						'order_id' => $order_id ?? null,
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'trace'    => $e->getTraceAsString()
					]
				);

				return $this->build_response('fail', 'An internal error occurred.', [], 500, $order_id);

			} finally {

				$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
			}
	}

	/**
	 * Create a Voucher payment link for an order.
	 *
	 * Vouchers are emailed by Voucher now, and the button in one is redeemed on
	 * Voucher's side, so nothing in the current flow reaches this. It is kept
	 * for the voucher links this plugin emailed before that change, which are
	 * still sitting in customers' inboxes and still have to open.
	 *
	 * @param WC_Order $order         Order being paid.
	 * @param array    $used_accounts Public keys already tried.
	 * @return array build_response() payload; data['payment_link'] on success.
	 */
	public function voucher_create_payment_link($order, $used_accounts = []) {

		global $wpdb;

		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}]";

		$lock_name   = 'voucher_order_' . $order_id;
		$lock_result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_name));

		if ((string) $lock_result !== '1') {
			return $this->build_response(
				'fail',
				'Payment already in progress. Please wait a few seconds and try again.',
				[],
				409,
				$order_id
			);
		}

		try {

			// Clicking the emailed voucher twice must not create a second payment
			// request; reuse the link already stored for an unpaid order.
			$existing = $this->voucher_get_stored_payment_link($order_id);

			if (!empty($existing) && $order->has_status('pending')) {

				Voucher_Payment_Gateway_Logger::info(
					$log_prefix . ' Reusing stored payment link',
					['order_id' => $order_id]
				);

				return $this->build_response(
					'success',
					'Payment link reused',
					['payment_link' => $existing],
					200,
					$order_id
				);
			}

			// -------------------------------------------------
			// 7. PAYMENT ACCOUNT LOOP (UNCHANGED LOGIC)
			// -------------------------------------------------

		$accounts = $this->get_all_available_accounts();

		if (empty($accounts)) {
			return $this->build_response(
				'fail',
				'No eligible payment provider available.',
				[],
				400,
				$order_id
			);
		}

		$selected_account = null;
		$payment_data     = null;
		$last_error_data  = null;
		$failed_accounts  = [];

		foreach ($accounts as $account) {

			$public_key = $this->sandbox
				? $account['sandbox_public_key']
				: $account['live_public_key'];

			$secret_key = $this->sandbox
				? $account['sandbox_secret_key']
				: $account['live_secret_key'];

			// Skip already used accounts
			if (in_array($public_key, $used_accounts, true)) {

				Voucher_Payment_Gateway_Logger::info(
					$log_prefix . ' Account skipped (already used)',
					[
						'account_title' => $account['title'] ?? null,
						'public_key'    => $public_key,
					]
				);

				continue;
			}

			// Prepare payment data
			$data = $this->voucher_prepare_payment_data($order, $public_key, $secret_key);

			if (is_array($data) && ($data['result'] ?? '') === 'fail') {

				Voucher_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account preparation failed',
					[
						'account_title' => $account['title'] ?? null,
						'public_key'    => $public_key,
						'data'          => $data,
					]
				);

				$used_accounts[] = $public_key;
				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'prepare_failed',
				];

				continue;
			}

			$limit_url = $this->get_api_url('/api/dailylimit');

			$limit_resp = wp_remote_post($limit_url, [
				'method'  => 'POST',
				'timeout' => 30,
				'body'    => $data,
				'headers' => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
			]);

			if (is_wp_error($limit_resp)) {

				Voucher_Payment_Gateway_Logger::warning(
					$log_prefix . ' Daily limit API WP error',
					[
						'account_title' => $account['title'] ?? null,
						'error'         => $limit_resp->get_error_message(),
					]
				);

				$used_accounts[] = $public_key;
				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'wp_error',
				];

				continue;
			}

			$limit_data = json_decode(wp_remote_retrieve_body($limit_resp), true);

			if (($limit_data['status'] ?? '') === 'error') {

				Voucher_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account rejected by daily limit API',
					[
						'account_title' => $account['title'] ?? null,
						'response'      => $limit_data,
					]
				);

				$last_error_data = $limit_data;

				$used_accounts[] = $public_key;
				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'limit_error',
					'response'=> $limit_data,
				];

				continue;
			}

			// ✅ SUCCESS
			Voucher_Payment_Gateway_Logger::info(
				$log_prefix . ' Account selected',
				[
					'account_title' => $account['title'] ?? null,
					'public_key'    => $public_key,
				]
			);

			$selected_account = $account;
			$payment_data     = $data;

			break;
		}

			if (!$selected_account) {

				if ($last_error_data) {

					if (!empty($last_error_data['max_limit_reached'])) {

						return $this->build_response(
							'fail',
							'The transaction amount exceeds the maximum allowed limit.',
							[],
							400,
							$order_id
						);
					}

					$order->update_meta_data('_voucher_limit_exceeded', true);
					$order->save();

					return $this->build_response(
						'fail',
						$last_error_data['message'] ?? 'Payment limit error.',
						[],
						400,
						$order_id
					);
				}

				Voucher_Payment_Gateway_Logger::error(
					'No eligible payment provider available for this order.',
					[
						'order_id' => $order_id ?? null
					]
				);

				return $this->build_response(
					'fail',
					'No eligible payment provider available for this order',
					[],
					400,
					$order_id
				);
				}

				// -------------------------------------------------
				// 8. PAYMENT REQUEST
				// -------------------------------------------------
				$account    = $selected_account;
				$data       = $payment_data;

				$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
				$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

				$api_url = esc_url($this->base_url . '/api/request-payment');

				$response = wp_remote_post($api_url, [
					'method'    => 'POST',
					'timeout'   => 30,
					'body'      => $data,
					'headers'   => [
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
					],
					'sslverify' => true,
				]);

				if (is_wp_error($response)) {

					return $this->build_response(
						'fail',
						'Payment error: Unable to process.',
						[],
						500,
						$order_id
					);
				}

				$resp_data = json_decode(wp_remote_retrieve_body($response), true);

				Voucher_Payment_Gateway_Logger::info(
					'Payment API response received',
					[
						'order_id' => $order_id,
						'status'   => $resp_data['status'] ?? null,
						'pay_id'   => $resp_data['data']['pay_id'] ?? null,
					]
				);

				if (($resp_data['status'] ?? '') === 'error') {

					$error_msg = sanitize_text_field(
						$resp_data['message'] ?? $resp_data['context']['message'] ?? 'Payment failed.'
					);
					if (!$this->is_block_checkout_request() && is_checkout()) {
						wc_add_notice($error_msg, 'error');
					}

					return $this->build_response(
						'fail',
						$error_msg,
						[],
						400,
						$order_id
					);
				}

				// -------------------------------------------------
				// 9. DATABASE (UNCHANGED - KEPT EXACTLY SAME)
				// -------------------------------------------------
				$table_name = $wpdb->prefix . 'order_payment_link';

				$pay_id = $resp_data['data']['pay_id'] ?? '';

				if (!empty($resp_data['data']['payment_link'])) {

					$existing = $wpdb->get_var($wpdb->prepare(
						"SELECT id FROM $table_name WHERE order_id = %d",
						$order_id
					));

					if ($existing) {

						$wpdb->update(
							$table_name,
							[
								'uuid'           => sanitize_text_field($pay_id),
								'payment_link'   => esc_url_raw($resp_data['data']['payment_link']),
								'customer_email' => sanitize_email($resp_data['data']['customer_email']),
								'amount'         => number_format((float)($resp_data['data']['amount'] ?? 0), 2, '.', ''),
								'created_at'     => current_time('mysql', 1),
							],
							['order_id' => $order_id]
						);
					} else {

						$wpdb->insert(
							$table_name,
							[
								'order_id'       => $order_id,
								'uuid'           => sanitize_text_field($pay_id),
								'payment_link'   => esc_url_raw($resp_data['data']['payment_link']),
								'customer_email' => sanitize_email($resp_data['data']['customer_email']),
								'amount'         => number_format((float)($resp_data['data']['amount'] ?? 0), 2, '.', ''),
								'created_at'     => current_time('mysql', 1),
							]
						);
					}
				}

				// -------------------------------------------------
				// 10. PAY ID UPDATE (UNCHANGED)
				// -------------------------------------------------
				if (!empty($pay_id)) {

					$order->update_meta_data('_voucher_pay_id', $pay_id);
					$order->update_meta_data('_voucher_pay_id_updated_at', time());

					$order->update_meta_data('_voucher_active_pay_id', $pay_id);
					$order->update_meta_data('_voucher_payment_finalized', false);
				}

				// -------------------------------------------------
				// 11. SUCCESS RESPONSE
				// -------------------------------------------------

				$order->update_status('pending', __('Payment pending.', 'voucher-payment-gateway'));

				voucher_add_unique_order_note(
					$order,
					'payment_initiated',
					sprintf(
						__('Payment initiated via Voucher (%s)', 'voucher-payment-gateway'),
						$account['title']
					)
				);

				$payment_link = $resp_data['data']['payment_link'] ?? null;

				if (empty($payment_link)) {

					Voucher_Payment_Gateway_Logger::error(
						'Missing payment link in response',
						[
							'order_id' => $order_id,
							'pay_id'   => $pay_id ?? null,
						]
					);

					return $this->build_response(
						'fail',
						'Payment could not be initiated. Please try again in a moment.',
						[],
						500,
						$order_id
					);
				}

				Voucher_Payment_Gateway_Logger::info(
					$log_prefix . ' Payment initiated successfully',
					[
						'order_id'    => $order_id,
						'pay_id'      => $pay_id,
						'payment_link'=> $payment_link,
					]
				);

			return $this->build_response(
				'success',
				'Payment link created',
				[
					'payment_link' => esc_url_raw($payment_link),
					'pay_id'       => $pay_id,
				],
				200,
				$order_id
			);

		} catch (\Exception $e) {

			Voucher_Payment_Gateway_Logger::error(
				'Payment link creation exception: ' . $e->getMessage(),
				[
					'order_id' => $order_id,
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
				]
			);

			return $this->build_response('fail', 'An internal error occurred.', [], 500, $order_id);

		} finally {

			$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
		}
	}

	/**
	 * Payment link already stored for an order, if any.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function voucher_get_stored_payment_link($order_id) {

		global $wpdb;

		$table = esc_sql($wpdb->prefix . 'order_payment_link');

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$link = $wpdb->get_var($wpdb->prepare("SELECT payment_link FROM {$table} WHERE order_id = %d LIMIT 1", $order_id));

		return $link ? esc_url_raw($link) : '';
	}

	/**
	 * URL that turns a voucher this plugin emailed into a payment page.
	 *
	 * No longer put in front of a customer: the voucher email is Voucher's, and
	 * its button points at Voucher. Kept as the definition of the link shape
	 * the loader still accepts for older emails.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	public function voucher_get_voucher_url($order) {

		return add_query_arg(
			[
				'voucher_link' => $order->get_id(),
				'key'             => $order->get_order_key(),
				'token'           => self::voucher_link_token($order),
			],
			home_url('/')
		);
	}

	/**
	 * Signature tying a voucher link to one order.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	public static function voucher_link_token($order) {

		return wp_hash('voucher_link|' . $order->get_id() . '|' . $order->get_order_key());
	}

	/**
	 * Price as plain text, e.g. "$90.00".
	 *
	 * @param float    $amount Amount.
	 * @param WC_Order $order  Order supplying the currency.
	 * @return string
	 */
	private function voucher_plain_price($amount, $order) {

		return html_entity_decode(
			wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Order summary rows (items, then discounts/shipping/fees/tax) as plain text.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array List of ['label' => string, 'value' => string].
	 */
	private function voucher_get_summary_rows($order) {

		$rows = [];

		foreach ($order->get_items() as $item) {

			$label = $item->get_name();

			if ($item->get_quantity() > 1) {
				$label .= ' ×' . $item->get_quantity();
			}

			$rows[] = [
				'label' => $label,
				'value' => $this->voucher_plain_price($order->get_line_total($item, true), $order),
			];
		}

		// The subtotal repeats the items above and the total gets its own row.
		foreach ($order->get_order_item_totals() as $key => $total) {

			if (in_array($key, ['cart_subtotal', 'payment_method', 'order_total'], true)) {
				continue;
			}

			$rows[] = [
				'label' => rtrim(wp_strip_all_tags($total['label']), ': '),
				'value' => html_entity_decode(wp_strip_all_tags($total['value']), ENT_QUOTES, 'UTF-8'),
			];
		}

		return $rows;
	}

	private function build_response(
		string $result,
		string $message = '',
		array $data = [],
		int $code = 200,
		?int $order_id = null
	) {
		return [
			'result'   => $result, // success | fail
			'message'  => $message,
			'data'     => $data,
			'order_id' => $order_id,
			'code'     => $code,
			'success'  => $result === 'success',
		];
	}

	/**
	 * Ask Voucher to email the customer their voucher.
	 *
	 * The email is Voucher's, not this plugin's: there is no template, no
	 * wp_mail() call and no voucher link here. The order is posted to
	 * /api/voucher/send and whatever comes back is handed to the checkout as it
	 * is - including the wording of a refusal, which is already written for the
	 * customer.
	 *
	 * The payment link is still created only when the customer opens the button
	 * in that email; this call creates nothing but the voucher itself.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array ['success' => bool, 'message' => string, 'data' => array]
	 */
	private function voucher_request_voucher_email($order) {

		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}]";

		$accounts = $this->get_all_available_accounts();

		if (empty($accounts)) {

			Voucher_Payment_Gateway_Logger::error(
				$log_prefix . ' Voucher not requested: no eligible account',
				['order_id' => $order_id]
			);

			return [
				'success' => false,
				'message' => __('No eligible payment provider available.', 'voucher-payment-gateway'),
				'data'    => [],
			];
		}

		$api_url      = esc_url($this->base_url . '/api/voucher/send');
		$last_message = '';

		foreach ($accounts as $account) {

			$public_key = $this->sandbox
				? $account['sandbox_public_key']
				: $account['live_public_key'];

			$secret_key = $this->sandbox
				? $account['sandbox_secret_key']
				: $account['live_secret_key'];

			// The same payload request-payment is given, so the voucher is raised
			// for exactly the order the payment will be for.
			$data = $this->voucher_prepare_payment_data($order, $public_key, $secret_key);

			if (is_array($data) && ($data['result'] ?? '') === 'fail') {

				$last_message = sanitize_text_field($data['error'] ?? '');
				continue;
			}

			// What the customer actually bought, so the redemption page can show
			// it back to them. Carried by the voucher only - request-payment
			// neither wants nor keeps it.
			$data['items'] = $this->voucher_get_voucher_items($order);

			$response = wp_remote_post($api_url, [
				'method'    => 'POST',
				'timeout'   => 30,
				'body'      => $data,
				'headers'   => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'sslverify' => true,
			]);

			if (is_wp_error($response)) {

				Voucher_Payment_Gateway_Logger::error(
					$log_prefix . ' Voucher request failed to reach the API',
					[
						'order_id'      => $order_id,
						'account_title' => $account['title'] ?? null,
						'error'         => $response->get_error_message(),
					]
				);

				$last_message = __('We could not reach the voucher service. Please try again in a moment.', 'voucher-payment-gateway');
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);
			$body = is_array($body) ? $body : [];

			$message = isset($body['message']) ? sanitize_text_field($body['message']) : '';

			Voucher_Payment_Gateway_Logger::info(
				$log_prefix . ' Voucher API response received',
				[
					'order_id'   => $order_id,
					'http_code'  => $code,
					'status'     => $body['status'] ?? null,
					'voucher_id' => $body['data']['voucher_id'] ?? null,
					'reference'  => $body['data']['reference'] ?? null,
				]
			);

			if (($body['status'] ?? '') === 'success') {

				return [
					'success' => true,
					'message' => $message,
					'data'    => isset($body['data']) && is_array($body['data']) ? $body['data'] : [],
				];
			}

			$last_message = $message ?: $last_message;

			/*
			 * What one account is refused - a key that is not accepted, a service
			 * that is down - the next may be granted. A refusal of the order
			 * itself would only be repeated, so it is handed back as it is.
			 */
			if (!in_array($code, [401, 403, 500, 502, 503, 504], true)) {
				break;
			}
		}

		return [
			'success' => false,
			'message' => $last_message ?: __('We could not email your voucher. Please try again in a moment.', 'voucher-payment-gateway'),
			'data'    => [],
		];
	}

	/**
	 * The ordered products, as this store lists them.
	 *
	 * Line items only - shipping, tax and discounts are the order's totals, not
	 * what the customer picked. Prices are formatted here rather than sent raw
	 * so the redemption page shows the same strings the customer saw at
	 * checkout, in this store's currency and format, and each line carries its
	 * product picture so the page shows what they were looking at.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array List of ['name' => string, 'quantity' => int, 'total' => string, 'image' => string].
	 */
	private function voucher_get_voucher_items($order) {

		$items = [];

		foreach ($order->get_items() as $item) {

			$name = sanitize_text_field($item->get_name());

			if ($name === '') {
				continue;
			}

			$items[] = [
				'name'     => $name,
				'quantity' => max(1, (int) $item->get_quantity()),
				// Line total including tax, matching the order-received summary.
				'total'    => $this->voucher_plain_price($order->get_line_total($item, true), $order),
				'image'    => $this->voucher_get_item_image_url($item),
			];
		}

		return $items;
	}

	/**
	 * Product picture for an order line.
	 *
	 * A variation often has no image of its own, in which case the parent
	 * product's is the one the customer was shown on the product page. The
	 * thumbnail size is used: this ends up in a 36px box, and the full-size
	 * upload would be a slow way to fill it.
	 *
	 * @param WC_Order_Item $item Order line item.
	 * @return string Absolute URL, or '' when the product has no image.
	 */
	private function voucher_get_item_image_url($item) {

		if (!method_exists($item, 'get_product')) {
			return '';
		}

		$product = $item->get_product();

		if (!$product) {
			return '';
		}

		$image_id = $product->get_image_id();

		if (!$image_id && $product->get_parent_id()) {

			$parent = wc_get_product($product->get_parent_id());

			if ($parent) {
				$image_id = $parent->get_image_id();
			}
		}

		if (!$image_id) {
			return '';
		}

		$url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');

		return $url ? esc_url_raw($url) : '';
	}

	/**
	 * Note on the order that Voucher has the voucher in hand.
	 *
	 * @param WC_Order $order   Order being paid.
	 * @param array    $voucher data block from /api/voucher/send.
	 * @return void
	 */
	private function voucher_record_voucher_sent($order, $voucher) {

		$reference = isset($voucher['reference']) ? sanitize_text_field($voucher['reference']) : '';
		$to        = sanitize_email($voucher['customer_email'] ?? $order->get_billing_email());

		// Read by the order-received notice to say payment is still to come.
		$order->update_meta_data('_voucher_link_emailed_at', time());

		if (!empty($voucher['voucher_id'])) {
			$order->update_meta_data('_voucher_link_id', sanitize_text_field($voucher['voucher_id']));
		}

		if ($reference !== '') {
			$order->update_meta_data('_voucher_link_reference', $reference);
		}

		$order->add_order_note(
			$reference !== ''
				? sprintf(
					/* translators: 1: customer email address, 2: voucher reference */
					__('Voucher voucher emailed to %1$s (reference %2$s).', 'voucher-payment-gateway'),
					$to,
					$reference
				)
				: sprintf(
					/* translators: %s: customer email address */
					__('Voucher voucher emailed to %s.', 'voucher-payment-gateway'),
					$to
				)
		);

		$order->save();
	}

	/**
	 * Mask an email address for display, e.g. "harry@example.com" → "ha•••@example.com".
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function voucher_mask_email($email) {
		$at = strrpos((string) $email, '@');

		if ($at === false) {
			return '';
		}

		$local   = substr($email, 0, $at);
		$visible = min(2, max(1, mb_strlen($local) - 1));

		return mb_substr($local, 0, $visible) . "\u{2022}\u{2022}\u{2022}" . substr($email, $at);
	}

	/**
	 * Remind the customer on the order-received page that payment happens via the emailed voucher.
	 *
	 * @param int $order_id Order ID.
	 */
	public function voucher_thankyou_payment_link_notice($order_id) {
		$order = wc_get_order($order_id);

		if (!$order || !$order->has_status('pending') || !$order->get_meta('_voucher_link_emailed_at')) {
			return;
		}

		printf(
			'<p class="voucher-thankyou-email-notice">%s</p>',
			sprintf(
				/* translators: %s: masked customer email address */
				esc_html__('We have emailed your voucher to %s. Purchase the voucher to complete this order.', 'voucher-payment-gateway'),
				'<strong>' . esc_html($this->voucher_mask_email($order->get_billing_email())) . '</strong>'
			)
		);
	}

	private function is_block_checkout_request() {
		return wp_doing_ajax() && isset($_REQUEST['action'])
			&& $_REQUEST['action'] === 'voucher_block_gateway_process';
	}

	public function voucher_display_test_order_tag($order) {
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'voucher-payment-gateway') . '</strong></p>';
		}
	}

	private function voucher_get_return_url_base() {
		return rest_url('/voucher/v1/data');
	}

	private function is_po_box($address) {
		if (empty($address)) return false;

		$clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $address));

		return preg_match('/pob|postoffice/', $clean) === 1;
	}

	private function voucher_prepare_payment_data($order, $api_public_key, $api_secret) {
		$order_id    = $order->get_id();
		$is_sandbox  = $this->get_option('sandbox') === 'yes';
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		$first_name  = sanitize_text_field($order->get_billing_first_name());
		$last_name   = sanitize_text_field($order->get_billing_last_name());
		$amount      = number_format($order->get_total(), 2, '.', '');
		$email       = sanitize_text_field($order->get_billing_email());
		$original_phone = $order->get_billing_phone();
		$phone       = sanitize_text_field($original_phone);
		$country     = $order->get_billing_country();
		$country_code = WC()->countries->get_country_calling_code($country);
		
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city      = sanitize_text_field($order->get_billing_city());
		$billing_postcode  = sanitize_text_field($order->get_billing_postcode());
		$billing_country   = sanitize_text_field($order->get_billing_country());
		$billing_state     = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(add_query_arg([
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
			'nonce'    => wp_create_nonce('voucher_payment_nonce'),
			'mode'     => 'wp',
		], $this->voucher_get_return_url_base()));

		$ip_address = sanitize_text_field($this->voucher_get_client_ip());

		if (empty($order_id)) {
			Voucher_Payment_Gateway_Logger::error(
				'Order ID is missing or invalid.',
				[]
			);
			return ['result' => 'fail','error'=>'Order ID is missing or invalid.'];
		}

		$meta_data_array = array_map('sanitize_text_field', [
			'order_id' => $order_id,
			'amount'   => $amount,
			'source'   => 'woocommerce',
		]);

		return [
			'api_secret'       => $api_secret,
			'api_public_key'   => $api_public_key,
			'first_name'       => $first_name,
			'last_name'        => $last_name,
			'request_for'      => $request_for,
			'amount'           => $amount,
			'redirect_url'     => $redirect_url,
			'redirect_time'    => 3,
			'ip_address'       => $ip_address,
			'source'           => 'wordpress',
			'meta_data'        => $meta_data_array,
			'remarks'          => 'Order ' . $order->get_order_number(),
			'email'            => $email,
			'phone_number'     => $phone,
			'country_code'     => $country_code,
			'billing_address_1'=> $billing_address_1,
			'billing_address_2'=> $billing_address_2,
			'billing_city'     => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country'  => $billing_country,
			'billing_state'    => $billing_state,
			'is_sandbox'       => $is_sandbox,
			'curr_code'        => sanitize_text_field($order->get_currency()),
			'plugin_source'    => 'voucher',
		];
	}

	private function voucher_normalize_phone($phone, $country_code) {
		$cleanedPhone  = preg_replace('/[()\s-]/', '', $phone ?? '');
		$countryCode   = preg_replace('/[^0-9]/', '', $country_code ?? '');
		$phoneNumber   = preg_replace('/[^\d]/', '', $cleanedPhone);

		if (!empty($countryCode) && strlen($phoneNumber) > strlen($countryCode) && strpos($phoneNumber, $countryCode) === 0) {
			$normalizedPhone = substr($phoneNumber, strlen($countryCode));
		} else {
			$normalizedPhone = $phoneNumber;
		}

		$normalizedPhone = ltrim($normalizedPhone, '0');

		if (empty($phoneNumber)) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
		}

		$localLength   = strlen($normalizedPhone);
		$totalLength   = strlen($countryCode . $normalizedPhone);
		$requires10Digits = in_array($countryCode, ['1']);
		$europeCodes   = ['33','34','39','31','44','46','47','48','49','41','45','358'];

		if ($requires10Digits) {
			if ($localLength !== 10) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => 'Phone number must be exactly 10 digits.'];
			}
		} elseif (in_array($countryCode, $europeCodes)) {
			$min = ($countryCode === '49' || $countryCode === '358') ? 5 : 8;
			$max = ($countryCode === '49' || $countryCode === '358') ? 11 : 10;
			if ($localLength < $min || $localLength > $max) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => "European number invalid: should be $min-$max digits"];
			}
		} else {
			// Default international validation
			if ($localLength < 10 || $localLength > 15) {

				return [
					'phone'        => $normalizedPhone,
					'country_code' => '+' . $countryCode,
					'is_valid'     => false,
					'error'        => 'Phone number must be between 10 and 15 digits.'
				];
			}
		}

		if ($totalLength > 15) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => sprintf('Phone number is too long. Maximum allowed length is 15 digits (including country code). Your phone number has %d digits.', $totalLength)];
		}

		return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
	}

	private function voucher_get_client_ip() {
		$ip = '';
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
			$ip = trim($ip_list[0]);
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	public function voucher_add_custom_label_to_order_row($line_items, $order) {
		$order_origin = $order->get_meta('_order_origin');
		if (!empty($order_origin)) {
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}
		return $line_items;
	}

	public function voucher_woocommerce_not_active_notice() {
		echo '<div class="error"><p>' . esc_html__('Voucher Payment Gateway requires WooCommerce to be installed and active.', 'voucher-payment-gateway') . '</p></div>';
	}

	public function payment_fields() {
		$description = $this->get_option('description');
		if (is_array($this->selected_account_for_display) && !empty($this->selected_account_for_display['checkout_subtitle'])) {
			$description = $this->selected_account_for_display['checkout_subtitle'];
		} elseif (WC()->cart) {
			
			$accounts = $this->get_all_accounts();
			$sorted   = $this->get_routing_sorted_accounts($accounts);
			if (!empty($sorted) && !empty($sorted[0]['checkout_subtitle'])) {
				$description = $sorted[0]['checkout_subtitle'];
			}
		}

		if ($description) {
			echo wp_kses_post(wpautop(wptexturize(trim($description))));
		}
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			echo '<p class="form-row form-row-wide">
                <label for="voucher_consent">
                    <input type="checkbox" id="voucher_consent" name="voucher_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'voucher-payment-gateway') .
				'</label></p>';
			wp_nonce_field('voucher_payment', 'voucher_nonce');
		}
	}

	public function validate_fields() {

		if ($this->get_option('show_consent_checkbox') === 'yes') {
			$nonce = isset($_POST['voucher_nonce']) ? sanitize_text_field(wp_unslash($_POST['voucher_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'voucher_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'voucher-payment-gateway'), 'error');
				return false;
			}
			$consent = isset($_POST['voucher_consent']) ? sanitize_text_field(wp_unslash($_POST['voucher_consent'])) : '';
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'voucher-payment-gateway'), 'error');
				return false;
			}
		}
		return true;
	}

	public function voucher_enqueue_styles_and_scripts() {
		if (is_checkout()) {
			$image_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/loader.gif';
			// filemtime versions so browsers and page caches drop the old popup script.
			wp_enqueue_style('voucher-payment-loader-styles', plugins_url('../assets/css/frontend.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/frontend.css'), 'all');
			wp_enqueue_script('voucher-js', plugins_url('../assets/js/voucher.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/voucher.js'), true);
			wp_localize_script('voucher-js', 'voucher_params', [
				'ajax_url'       => admin_url('admin-ajax.php'),
				'checkout_url'   => wc_get_checkout_url(),
				'voucher_loader' => $image_url,
				'voucher_nonce'  => wp_create_nonce('voucher_payment'),
				'payment_method' => $this->id,
			]);
		}
	}

	function voucher_admin_scripts($hook) {
		if (
			'woocommerce_page_wc-settings' !== $hook ||
			(sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}
		wp_enqueue_style('voucher-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');
		wp_enqueue_style('voucher-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');
		wp_enqueue_script('voucher-admin-script', plugins_url('../assets/js/voucher-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/voucher-admin.js'), true);
		wp_localize_script('voucher-admin-script', 'voucher_admin_data', [
			'ajax_url'   => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('voucher_sync_nonce'),
			'gateway_id' => $this->id,
		]);
	}

	public function voucher_hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;
		$this->selected_account_for_display = null;

		// =====================================================
		// STEP 1: SAFE CART CHECK
		// =====================================================
		if (!WC()->cart) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 2: CHECKOUT CONTEXT (STRICT)
		// =====================================================
		$is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
		$is_blocks = defined('REST_REQUEST') && REST_REQUEST && !is_admin();
		$is_checkout_page = function_exists('is_checkout') && is_checkout();

		if (!$is_checkout_page && !$is_ajax && !$is_blocks) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 3: FLOW LABEL
		// =====================================================
		$flow = 'Checkout (Classic)';

		if ($is_blocks) {
			$flow = 'Checkout (Blocks)';
		} elseif ($is_ajax) {
			$flow = 'Checkout (AJAX)';
		}

		// =====================================================
		// STEP 4: CART INFO
		// =====================================================
		$amount = (float) WC()->cart->get_total('raw');
		if ($amount < 0.01) {
			$amount = (float) (WC()->cart->get_totals()['total'] ?? 0);
		}

		$items = count(WC()->cart->get_cart());

		// =====================================================
		// STEP 5: REQUEST FINGERPRINT (REAL FIX)
		// =====================================================
		static $executed = false;

		$fingerprint = md5(json_encode([
			'flow'   => $flow,
			'items'  => $items,
			'total'  => $amount,
			'ajax'   => $is_ajax,
			'blocks' => $is_blocks
		]));

		if ($executed === $fingerprint) {
			return $available_gateways;
		}

		$executed = $fingerprint;

		// =====================================================
		// STEP 6: LOAD ACCOUNTS
		// =====================================================
		if (!method_exists($this, 'get_all_accounts')) {
			return $available_gateways;
		}

		$accounts = $this->get_all_accounts();

		// =====================================================
		// STEP 7: NO ACCOUNTS
		// =====================================================
		if (empty($accounts)) {

			Voucher_Payment_Gateway_Logger::info(
				"Voucher Gateway Decision",
				[
					'result' => 'HIDDEN',
					'reason' => 'No merchant accounts configured',
					'items'  => $items,
					'total'  => $amount,
					'flow'   => $flow
				]
			);

			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		// =====================================================
		// STEP 8: SORT
		// =====================================================
		usort($accounts, fn($a, $b) =>
			($a['priority'] ?? 1) <=> ($b['priority'] ?? 1)
		);

		// =====================================================
		// STEP 9: EVALUATION
		// =====================================================
		$selected = null;
		$reason   = 'No eligible merchant account';

		$pluginLogApiUrl        = $this->get_api_url('/api/plugin/check/checkout');
		$all_accounts_limited = true;

		$force_refresh = (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			$_GET['refresh_accounts'] === '1' &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);


		foreach ($accounts as $account) {

			$public = $this->sandbox
				? ($account['sandbox_public_key'] ?? '')
				: ($account['live_public_key'] ?? '');

			$secret = $this->sandbox
				? ($account['sandbox_secret_key'] ?? '')
				: ($account['live_secret_key'] ?? '');

			if (empty($public) || empty($secret)) {
				continue;
			}

			$data = [
				'is_sandbox'     => $this->sandbox,
				'amount'         => $amount,
				'api_public_key' => $public,
				'api_secret_key' => $secret,
			];

			$cache = 'voucher_' . md5($public . $amount);

			$status = $this->get_cached_api_response(
				$this->get_api_url('/api/check-merchant-status'),
				$data,
				$cache . '_status',
				10
			);

			if (($status['status'] ?? '') !== 'success') {
				continue;
			}

			$limit = $this->get_cached_api_response(
				$this->get_api_url('/api/dailylimit'),
				$data,
				$cache . '_limit',
				10
			);

			if (($limit['status'] ?? '') !== 'success') {
				continue;
			}

			if (!empty($limit['status']) && $limit['status'] === 'success') {
				$all_accounts_limited = false;
			}

			$this->send_plugin_logs(
				$accounts,
				$public,
				$secret,
				$amount,
				$all_accounts_limited ? 0 : 1,
				$pluginLogApiUrl,
				$force_refresh
			);

			$selected = $account;
			$reason   = 'Valid merchant account found';
			break;
		}

		// =====================================================
		// STEP 10: SINGLE FINAL LOG ONLY
		// =====================================================
		Voucher_Payment_Gateway_Logger::info(
			"Voucher Gateway Decision",
			[
				'result' => $selected ? 'SHOWN' : 'HIDDEN',
				'reason' => $reason,
				'items'  => $items,
				'total'  => $amount,
				'flow'   => $flow,
				'account'=> $selected['title'] ?? null
			]
		);

		// =====================================================
		// STEP 11: RETURN RESULT
		// =====================================================
		$this->selected_account_for_display = $selected;

		if (!$selected) {
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		return $available_gateways;
	}
	private function send_plugin_logs($accounts, $public_key, $secret_key, $amount, $gateway_loaded, $pluginLogApiUrl, $force_refresh)
	{
		$plugin_version = VOUCHER_PLUGIN_VERSION;
		$accounts       = $this->update_accounts_uniqueID($accounts);
		$group_id       = get_option('voucher_group_id');
		$cache_base     = 'voucher_daily_limit_' . md5($public_key . $amount);

		$plugin_logs_data = [
			'valid_accounts' => $accounts,
			'gateway_loaded' => $gateway_loaded,
			'plugin_status'  => $gateway_loaded,
			'plugin_version' => $plugin_version,
			'api_public_key' => $public_key,
			'api_secret_key' => $secret_key,
			'is_sandbox'     => $this->sandbox,
			'group_id'       => $group_id ? $group_id : $this->voucher_get_group_id(),
			'domain_name'    => parse_url(home_url(), PHP_URL_HOST),
		];

		$this->get_cached_api_response(
			$pluginLogApiUrl,
			$plugin_logs_data,
			$cache_base . '_pluginlogs',
			5,
			$force_refresh
		);
	}

	private function gateway_visibility_label($reason) {

		return match ($reason) {

			'no_accounts' => 'No payment accounts configured',
			'merchant_inactive' => 'Payment provider unavailable',
			'daily_limit_exceeded' => 'Daily limit reached for this account',
			'no_eligible_accounts' => 'No valid payment account found',
			'non_checkout_page' => 'Not on checkout page',

			default => 'Payment validation step executed'
		};
	}

	private function hide_gateway($available_gateways, $gateway_id) {
		unset($available_gateways["voucher"]);
		$GLOBALS['voucher_gateway_visibility_' . $this->id] = $available_gateways;
		return $available_gateways;
	}

	private function log_info_once_per_session($key, $message, $context = [])
	{
		if (!function_exists('WC') || !WC()) {
			return;
		}

		if (!WC()->session) {
			WC()->initialize_session();
		}

		// -----------------------------
		// 🔥 FIXED FLOW DETECTION (REAL WOOCOMMERCE SAFE)
		// -----------------------------
		$flow = 'background';

		$is_ajax = defined('DOING_AJAX') && DOING_AJAX;
		$is_rest = defined('REST_REQUEST') && REST_REQUEST;

		$wc_ajax = $_REQUEST['wc-ajax'] ?? '';

		if (is_checkout()) {
			$flow = 'checkout_page';
		} elseif ($is_rest) {
			$flow = 'checkout_block';
		} elseif ($is_ajax && $wc_ajax === 'update_order_review') {
			$flow = 'checkout_refresh';
		}

		// -----------------------------
		// SAFE CONTEXT
		// -----------------------------
		$clean_context = [
			'Gateway' => $this->id,
			'Flow'    => $flow,
		];

		if (WC()->cart) {
			$clean_context['Items'] = count(WC()->cart->get_cart());
			$clean_context['Total'] = (float) WC()->cart->get_total('raw');
		}

		if (isset($context['reason'])) {
			$clean_context['Reason'] = $this->gateway_visibility_label($context['reason']);
		}

		if (isset($context['account'])) {
			$clean_context['Account'] = $context['account'];
		}

		// -----------------------------
		// 🔥 FIX: STABLE SESSION KEY
		// -----------------------------
		$session_key = 'voucher_log_' . md5($key . $this->id);

		if (WC()->session->get($session_key)) {
			return;
		}

		WC()->session->set($session_key, true);

		Voucher_Payment_Gateway_Logger::info($message, $clean_context);
	}

	protected function validate_account($account, $index) {
		$is_empty  = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
		$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);
		if (!$is_empty && !$is_filled) {
			return sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'voucher-payment-gateway'), $index + 1);
		}
		return true;
	}

	protected function validate_accounts($accounts) {
		$valid_accounts = [];
		$errors         = [];
		foreach ($accounts as $index => $account) {
			$is_empty  = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
			$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);
			if (!$is_empty && !$is_filled) {
				$errors[] = sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'voucher-payment-gateway'), $index + 1);
			} elseif ($is_filled) {
				$valid_accounts[] = $account;
			}
		}
		if (!empty($errors)) return ['errors' => $errors, 'valid_accounts' => $valid_accounts];
		return ['valid_accounts' => $valid_accounts];
	}

	private function get_cached_api_response($url, $data, $cache_key, $ttl = 120, $force_refresh = false) {
		if (!$force_refresh && isset($_GET['refresh_accounts']) && $_GET['refresh_accounts'] === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')) {
			$force_refresh = true;
		}
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		} else {
			delete_transient($cache_key);
		}
		$response = wp_remote_post($url, [
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => $data,
			'headers'   => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . $data['api_public_key'],
			],
			'sslverify' => true,
		]);
		if (is_wp_error($response)) return ['status' => 'error', 'message' => $response->get_error_message()];
		$response_data = json_decode(wp_remote_retrieve_body($response), true);
		set_transient($cache_key, $response_data, $ttl);
		return $response_data;
	}

	private function get_all_accounts() {
		$accounts = get_option('woocommerce_voucher-payment-gateway_accounts', []);
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}
		$valid_accounts = [];
		foreach ($accounts as $i => $account) {
			if ($this->sandbox) {
				$status   = strtolower($account['sandbox_status'] ?? '');
				$has_keys = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
				if ($status === 'active' && $has_keys) $valid_accounts[] = $account;
			} else {
				$status   = strtolower($account['live_status'] ?? '');
				$has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
				if ($status === 'active' && $has_keys) $valid_accounts[] = $account;
			}
		}
		$this->accounts = $valid_accounts;
		return $valid_accounts;
	}

	function voucher_enqueue_admin_styles($hook) {
		if (strpos($hook, 'woocommerce') === false) return;
		wp_enqueue_style('voucher-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
	}

	private function send_account_switch_email($oldAccount, $newAccount) {
		$btyenftApiUrl = $this->get_api_url('/api/switch-account-email');
		$api_key       = $this->sandbox ? $oldAccount['sandbox_public_key'] : $oldAccount['live_public_key'];
		$api_secret    = $this->sandbox ? $oldAccount['sandbox_secret_key'] : $oldAccount['live_secret_key'];
		$emailData     = [
			'old_account' => ['title' => $oldAccount['title'], 'secret_key' => $api_secret],
			'new_account' => ['title' => $newAccount['title']],
			'message'     => 'Payment processing account has been switched. Please review the details.',
			'is_sandbox'  => $this->sandbox,
		];
		$response = wp_remote_post($btyenftApiUrl, [
			'method'    => 'POST',
			'timeout'   => 30,
			'body'      => json_encode($emailData),
			'headers'   => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . sanitize_text_field($api_key)],
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'voucher-payment-gateway']);
			return false;
		}
		$response_code = wp_remote_retrieve_response_code($response);
		$response_data = json_decode(wp_remote_retrieve_body($response), true);
		if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
			wc_get_logger()->error('Email Sending Failed: Authentication failed', ['source' => 'voucher-payment-gateway']);
			return false;
		}
		if (!empty($response_data['error'])) {
			wc_get_logger()->error('Voucher API Error: ' . json_encode($response_data), ['source' => 'voucher-payment-gateway']);
			return false;
		}
		return true;
	}


	/**
	 * Sort and filter accounts for a given order amount.
	 * Accounts whose max_single_txn is set and less than $amount are excluded.
	 * Remaining accounts are sorted: lowest max_single_txn first (tightest fit),
	 * then by priority.
	 *
	 * @param array $accounts All accounts.
	 * @param float $amount   Order/cart total.
	 * @return array          Sorted array of eligible accounts.
	 */

private function get_routing_sorted_accounts(array $accounts): array {
	// No max_single_txn logic: return all accounts sorted by priority only
	usort($accounts, function ($a, $b) {
		return ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1);
	});
	return array_values($accounts);
}

	/**
	 * Get checkout display info (title + subtitle) for a given cart amount.
	 *
	 * @param float $amount Order/cart total.
	 * @return array ['title' => string, 'subtitle' => string]
	 */
	public function get_checkout_info_for_amount(float $amount): array {
		$selected_account = [];
		$sorted_accounts = array();
		$cart_hash = WC()->cart ? WC()->cart->get_cart_hash() : 'no_cart';
		$accounts = $this->get_all_accounts();
		$sorted   = $this->get_routing_sorted_accounts($accounts);
		$account  = !empty($sorted) ? $sorted[0] : null;
		
		$accounts = $this->get_all_accounts();
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		if (empty($accounts)) return $available_gateways;

		usort($accounts, fn($a, $b) => $a['priority'] <=> $b['priority']);

		$accStatusApiUrl        = $this->get_api_url('/api/check-merchant-status');
		$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
		$user_account_active = false;
		$all_accounts_limited = true;
		$limit_data = [];

		$force_refresh = (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			$_GET['refresh_accounts'] === '1' &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);

		// New logic: filter by daily limit, then pick by priority
		$eligible_accounts = [];
		foreach ($accounts as $account) {
			$acc_title  = $account['title'] ?? '(unknown)';
			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			if (empty($public_key) || empty($secret_key)) {
				continue;
			}
			$data = [
				'is_sandbox'     => $this->sandbox,
				'amount'         => $amount,
				'api_public_key' => $public_key,
				'api_secret_key' => $secret_key,
			];
			$cache_base  = 'voucher_daily_limit_' . md5($public_key . $amount);
			$status_data = $this->get_cached_api_response($accStatusApiUrl, $data, $cache_base . '_status', 10, $force_refresh);
			
			if (!empty($status_data['status']) && $status_data['status'] === 'success') {
				$user_account_active = true;
			}

			if (($status_data['status'] ?? '') !== 'success') {
				$this->log_info_once_per_session('skip_status_' . $acc_title, "Skipping '{$acc_title}': merchant status check failed", [
					'response_status' => $status_data['status'] ?? 'unknown',
				]);
				continue;
			}

			$limit_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit', 10, $force_refresh);
			
			if (($limit_data['status'] ?? '') === 'success') {
				$eligible_accounts[] = $account;
			} else {
				continue;
			}
			if (!empty($limit_data['status']) && $limit_data['status'] === 'success') {
				$all_accounts_limited = false;
			}

			$selected_account = $account;
			break;
		}

		$gateway_id = $this->id;
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ($all_accounts_limited) {
			
			if (!isset($limit_data['max_limit_reached']) || $limit_data['max_limit_reached'] == false) {
				return $this->hide_gateway($available_gateways, $gateway_id);
			}
		}
		// Fallback logic if no eligible account found
		
		if (!$selected_account) {
			$this->log_info_once_per_session('fallback_search', 'No routing-eligible account passed all checks, searching for fallback', [
				'amount' => $amount,
			]);
			usort($accounts, function ($a, $b) {
				return ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1);
			});
			
			if (!$all_accounts_limited) {
				$selected_account = $accounts[0] ?? null;
				$this->log_info_once_per_session('fallback_account', 'Fallback display account: ' . ($selected_account['title'] ?? 'none'));
			} else {
				$this->log_info_once_per_session('no_fallback', 'All accounts are limited, no fallback selected');
				$selected_account = null;
			}
		}

		$this->selected_account_for_display = $selected_account;

		if (!empty($selected_account['checkout_title'])) {
			
			return [
				'title'    => $selected_account['checkout_title'] ?? '',
				'subtitle' => $selected_account['checkout_subtitle'] ?? '',
				'accounts' => $selected_account['checkout_subtitle'] ?? '',
			];
		}

		return [];
	}

	private function get_all_available_accounts()
	{
		$settings = get_option('woocommerce_voucher-payment-gateway_accounts', []);
		$settings = maybe_unserialize($settings);

		if (!is_array($settings)) {
			return [];
		}

		$mode = $this->sandbox ? 'sandbox' : 'live';

		$status_key = $mode . '_status';
		$public_key  = $mode . '_public_key';
		$secret_key  = $mode . '_secret_key';

		$available = [];

		foreach ($settings as $account) {

			if (empty($account[$public_key]) || empty($account[$secret_key])) {
				continue;
			}

			if (strtolower($account[$status_key] ?? '') !== 'active') {
				continue;
			}

			$available[] = $account;
		}

		return $this->get_routing_sorted_accounts($available);
	}

	/**
	 * Get the next available payment account.
	 * Uses the already-loaded $this->sandbox value — no re-instantiation needed.
	 */
	private function get_next_available_account($used_accounts = [])
	{
		$settings = get_option('woocommerce_voucher-payment-gateway_accounts', []);
		$settings = maybe_unserialize($settings);

		if (!is_array($settings)) {
			return false;
		}

		$mode = $this->sandbox ? 'sandbox' : 'live';

		$status_key = $mode . '_status';
		$public_key = $mode . '_public_key';
		$secret_key = $mode . '_secret_key';

		$available = [];

		foreach ($settings as $account) {

			$pub = $account[$public_key] ?? '';

			if (empty($pub)) {
				continue;
			}

			// already used
			if (in_array($pub, $used_accounts, true)) {
				continue;
			}

			// inactive
			if (strtolower($account[$status_key] ?? '') !== 'active') {
				continue;
			}

			// missing keys
			if (empty($account[$public_key]) || empty($account[$secret_key])) {
				continue;
			}

			$available[] = $account;
		}

		if (empty($available)) {
			return false;
		}

		$available = $this->get_routing_sorted_accounts($available);

		if (empty($available)) {
			return false;
		}

		$account = $available[0];

		$account['lock_key'] =
			'voucher_lock_' . sanitize_title($account['title'] ?? 'account');

		return $account;
	}

	private function acquire_lock($lock_key) {
		$lock_timeout   = 500;
		$now            = time();
		$existing_lock  = get_option($lock_key);
		if ($existing_lock && intval($existing_lock) > $now) return false;
		update_option($lock_key, $now + $lock_timeout, false);
		return true;
	}

	private function release_lock($lock_key) {
		delete_option($lock_key);
	}
}
