<?php
if (!defined('ABSPATH')) {
	exit();
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-bytenft-payment-logger.php';

class BYTENFT_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'bytenft';

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

		$this->admin_notices = new BYTENFT_PAYMENT_GATEWAY_Admin_Notices();
		$this->base_url      = BYTENFT_BASE_URL;

		$this->id                 = self::ID;
		$this->icon               = '';
		$this->method_title       = __('ByteNFT Payment Gateway', 'bytenft-payment-gateway');
		$this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration.', 'bytenft-payment-gateway');

		$this->bytenft_init_form_fields();
		$this->init_settings();
		$this->settings['group_id'] = get_option('bytenft_group_id') ? get_option('bytenft_group_id') : $this->bytenft_get_group_id();
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
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'bytenft_process_admin_options']);
		add_action('wp_enqueue_scripts', [$this, 'bytenft_enqueue_styles_and_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'bytenft_admin_scripts']);

		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'bytenft_display_test_order_tag']);
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'bytenft_add_custom_label_to_order_row'], 10, 2);
		add_filter('woocommerce_available_payment_gateways', [$this, 'bytenft_hide_custom_payment_gateway_conditionally']);

		add_action('woocommerce_after_checkout_validation', [$this, 'bytenft_validate_checkout_fields'], 10, 2);
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			[$this, 'bytenft_validate_blocks_checkout'],
			10,
			2
		);

		add_action('wp_ajax_bytenft_log_event', [$this, 'handle_log_event']);
		add_action('wp_ajax_nopriv_bytenft_log_event', [$this, 'handle_log_event']);
	}

	/**
	 * Strict validation for Phone and Zip Code.
	 * Runs before process_payment to ensure only clean data reaches your API.
	 */
	public function bytenft_validate_checkout_fields($data, $errors)
	{
		$phone = $data['billing_phone'] ?? '';

		if (!empty($phone)) {

			$digits_only = preg_replace('/[^\d]/', '', $phone);

			if (strlen($digits_only) < 10) {

				ByteNFT_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'digits'     => $digits_only,
						'min_length' => 10
					]
				);

				$errors->add(
					'bytenft_phone_error',
					__('Invalid phone number. Please enter a valid phone number.', 'bytenft-payment-gateway')
				);

				return;
			}

			ByteNFT_Payment_Gateway_Logger::info(
				'Classic checkout phone validation passed',
				[
					'phone' => $phone
				]
			);
		}
	}

	public function bytenft_validate_blocks_checkout($order, $request)
	{
		$phone = $request['billing_address']['phone'] ?? '';

		if (!empty($phone)) {

			$cleaned = preg_replace('/[\s\-().]/', '', $phone);

			$usPattern      = '/^(\+1|1)?\d{10}$/';
			$euPattern      = '/^(\+|00)[1-9]\d{6,14}$/';
			$generalPattern = '/^\+?\d{10,15}$/';

			$valid = preg_match($usPattern, $cleaned)
				|| preg_match($euPattern, $cleaned)
				|| preg_match($generalPattern, $cleaned);

			if (!$valid) {

				ByteNFT_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid phone number',
					[
						'raw_phone' => $phone,
						'cleaned'   => $cleaned,
						'order_id'  => $order->get_id() ?? null
					]
				);

				throw new Exception(
					'Invalid phone number. Please enter a valid phone number or leave it blank.'
				);
			}

			ByteNFT_Payment_Gateway_Logger::info(
				'Blocks checkout phone validation passed',
				[
					'phone'    => $phone,
					'order_id' => $order->get_id() ?? null
				]
			);
		}

		return $order;
	}

	private function get_api_url($endpoint) {
		return $this->base_url . $endpoint;
	}

	public function bytenft_process_admin_options() {
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

		if (!isset($_POST['bytenft_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bytenft_accounts_nonce'])), 'bytenft_accounts_nonce_action')) {
			ByteNFT_Payment_Gateway_Logger::info('CSRF check failed during admin options update.');
			wp_die(esc_html__('Security check failed!', 'bytenft-payment-gateway'));
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
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'bytenft-payment-gateway');
			ByteNFT_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
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
				$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'bytenft-payment-gateway'), $account_title);
				ByteNFT_Payment_Gateway_Logger::info("Validation failed: missing required fields for account '{$account_title}'");
				continue;
			}

			$live_combined = $live_public_key . '|' . $live_secret_key;
			if (in_array($live_combined, $unique_live_keys, true)) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'bytenft-payment-gateway'), $account_title);
				ByteNFT_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
				continue;
			}

			if ($live_public_key === $live_secret_key) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'bytenft-payment-gateway'), $account_title);
				ByteNFT_Payment_Gateway_Logger::info("Validation warning: live keys are identical for account '{$account_title}'");
			}

			$unique_live_keys[] = $live_combined;

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'bytenft-payment-gateway'), $account_title);
					ByteNFT_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'bytenft-payment-gateway'), $account_title);
					ByteNFT_Payment_Gateway_Logger::info("Validation warning: sandbox keys are identical for account '{$account_title}'");
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

			ByteNFT_Payment_Gateway_Logger::info("Validated and added account '{$account_title}' to saved list.");
		}

		if (empty($valid_accounts) && empty($errors)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'bytenft-payment-gateway');
			ByteNFT_Payment_Gateway_Logger::info('All submitted accounts failed validation. No accounts will be saved.');
		}

		if (empty($errors)) {
			update_option('woocommerce_bytenft_payment_gateway_accounts', $valid_accounts);

			$public_key    = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$api_url       = esc_url($this->base_url . '/api/plugin/check/plugin');
			$plugin_version = BYTENFT_PLUGIN_VERSION;

			$body = [
				'valid_accounts' => $valid_accounts,
				'plugin_status'  => $enabled === 'yes' ? 1 : 0,
				'plugin_version' => $plugin_version,
				'gateway_loaded' => 0,
				'group_id'       => get_option('bytenft_group_id'),
				'domain_name'    => parse_url(home_url(), PHP_URL_HOST),
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

			$this->admin_notices->bytenft_add_notice('settings_success', 'notice notice-success', __('Settings saved successfully.', 'bytenft-payment-gateway'));
			ByteNFT_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('BYTENFT_PAYMENT_GATEWAY_Loader')) {
				$loader = BYTENFT_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event();
					ByteNFT_Payment_Gateway_Logger::info('Triggered BYTENFT_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');
				}
			}
		} else {
			foreach ($errors as $error) {
				$this->admin_notices->bytenft_add_notice('settings_error', 'notice notice-error', $error);
				ByteNFT_Payment_Gateway_Logger::info("Admin settings error: {$error}");
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}

	public function get_updated_account() {
		$accounts       = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
		$valid_accounts = [];

		foreach ($accounts as $index => $account) {
			$useSandbox = $this->sandbox;
			$secretKey  = $useSandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			$publicKey  = $useSandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

			ByteNFT_Payment_Gateway_Logger::info("Checking merchant status for account '{$account['title']}'", [
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
				ByteNFT_Payment_Gateway_Logger::info("Account '{$account['title']}' is inactive", ['response' => $body]);
			} else {
				ByteNFT_Payment_Gateway_Logger::info("Account '{$account['title']}' is active");
			}
		}

		if (!empty($valid_accounts)) {
			update_option('woocommerce_bytenft_payment_gateway_accounts', $valid_accounts);
			return true;
		}

		ByteNFT_Payment_Gateway_Logger::info('No active account. Removing bytenft gateway.');
		return false;
	}

	public function bytenft_init_form_fields() {
		$this->form_fields = $this->bytenft_get_form_fields();
	}

	function bytenft_get_group_id() {
		$group_id = get_option('bytenft_group_id');
		if (empty($group_id)) {
			$group_id = 'grp_' . wp_rand(100000, 999999);
			update_option('bytenft_group_id', $group_id);
		}
		return $group_id;
	}

	function bytenft_get_unique_id() {
		$unique_id = get_option('bytenft_unique_id');
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
				$account['unique_id'] = $this->bytenft_get_unique_id();
				$updated = true;
			}
		}
		unset($account);
		if ($updated) {
			update_option('woocommerce_bytenft_payment_gateway_accounts', $accounts);
		}
		return $accounts;
	}

	public function bytenft_get_form_fields() {
		$dev_instructions_link = sprintf(
			'<strong><a class="bytenft-instructions-url" href="%s" target="_blank">%s</a></strong><br>',
			esc_url($this->base_url . '/developers'),
			__('click here to access your developer account', 'bytenft-payment-gateway')
		);

		return apply_filters('bytenft_woocommerce_gateway_settings_fields_' . $this->id, [

			'enabled' => [
				'title'   => __('Enable/Disable', 'bytenft-payment-gateway'),
				'label'   => __('Enable ByteNFT Payment Gateway', 'bytenft-payment-gateway'),
				'type'    => 'checkbox',
				'default' => 'no',
			],

			'title' => [
				'title'       => __('Title', 'bytenft-payment-gateway'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'bytenft-payment-gateway'),
				'default'     => __('Buy with USDC Using Your Credit/Debit Card, Apple Pay or Google Pay — Secure, Modern Checkout 🔐', 'bytenft-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'bytenft-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('Provide a brief description of the payment option.', 'bytenft-payment-gateway'),
				'default'     => __(
					'<p style="margin:0 0 6px; font-size:13px;">Use a Credit Card, Debit Card or Google Pay, Apple Pay to complete your purchase via USDC.</p>
					<p style="margin:0 0 6px; font-size:13px;">The transaction will appear on your bank or card statement as *ByteNFT</p>',
					'bytenft-payment-gateway'
				),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('Instructions', 'bytenft-payment-gateway'),
				'type'        => 'title',
				'description' => sprintf(__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'bytenft-payment-gateway'), $dev_instructions_link, ''),
				'desc_tip'    => true,
			],

			'sandbox' => [
				'title'       => __('Sandbox', 'bytenft-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'bytenft-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Use sandbox API keys (real payments will not be taken).', 'bytenft-payment-gateway'),
				'default'     => 'no',
			],

			'group_id' => [
				'type' => 'hidden',
			],

			'accounts' => [
				'title'       => __('Payment Accounts', 'bytenft-payment-gateway'),
				'type'        => 'accounts_repeater',
				'description' => __('Add multiple payment accounts dynamically.', 'bytenft-payment-gateway'),
			],

			'order_status' => [
				'title'       => __('Order Status', 'bytenft-payment-gateway'),
				'type'        => 'select',
				'description' => __('Order status after successful payment.', 'bytenft-payment-gateway'),
				'default'     => '',
				'id'          => 'order_status_select',
				'desc_tip'    => true,
				'options'     => [
					'processing' => __('Processing', 'bytenft-payment-gateway'),
					'completed'  => __('Completed', 'bytenft-payment-gateway'),
				],
			],

			'show_consent_checkbox' => [
				'title'       => __('Show Consent Checkbox', 'bytenft-payment-gateway'),
				'label'       => __('Enable consent checkbox on checkout page', 'bytenft-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Show a checkbox for user consent during checkout.', 'bytenft-payment-gateway'),
				'default'     => 'no',
			],

		], $this);
	}

	public function generate_accounts_repeater_html($key, $data) {
		$option_value    = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
		$option_value    = maybe_unserialize($option_value);
		$active_account  = get_option('bytenft_active_account', 0);
		$global_settings = get_option('woocommerce_bytenft_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		$updated = false;
		if (!empty($option_value)) {
			foreach ($option_value as $index => &$account) {
				if (empty($account['unique_id'])) {
					$account['unique_id'] = $this->bytenft_get_unique_id();
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
			update_option('woocommerce_bytenft_payment_gateway_accounts', $option_value);
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="bytenft-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="bytenft-sync-account">
							<span id="bytenft-sync-status"></span>
							<button class="button" id="bytenft-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'bytenft-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>

					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'bytenft-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status    = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id      = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							?>
							<div class="bytenft-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'bytenft-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down <?php echo esc_attr($this->id); ?>-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<span class="account-status-label <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> <?php echo esc_attr(strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'bytenft-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'bytenft-payment-gateway') . esc_html(ucfirst($live_status));
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
											<label><?php esc_html_e('Account Name', 'bytenft-payment-gateway'); ?></label>
											<input type="text" class="account-title" name="accounts[<?php echo esc_attr($index); ?>][title]" placeholder="<?php esc_attr_e('Account Title', 'bytenft-payment-gateway'); ?>" value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div>
											<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][unique_id]" value="<?php echo esc_attr($unique_id); ?>" readonly>
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'bytenft-payment-gateway'); ?></label>
											<input type="number" class="account-priority" name="accounts[<?php echo esc_attr($index); ?>][priority]" placeholder="<?php esc_attr_e('Priority', 'bytenft-payment-gateway'); ?>" value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>

									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Title', 'bytenft-payment-gateway'); ?></label>
											<input type="text"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_title]"
												placeholder="<?php esc_attr_e('Title shown to customers at checkout', 'bytenft-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['checkout_title'] ?? ''); ?>">
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Subtitle', 'bytenft-payment-gateway'); ?></label>
											<textarea
												name="accounts[<?php echo esc_attr($index); ?>][checkout_subtitle]"
												placeholder="<?php esc_attr_e('Subtitle/description shown below the title at checkout', 'bytenft-payment-gateway'); ?>"
												rows="2"><?php echo esc_textarea($account['checkout_subtitle'] ?? ''); ?></textarea>
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'bytenft-payment-gateway'); ?></label>
											<input type="text" class="live-public-key" name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" placeholder="<?php esc_attr_e('Public Key', 'bytenft-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'bytenft-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
										$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked($account['has_sandbox'] == 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('Do you have the sandbox keys?', 'bytenft-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = $account['has_sandbox'] == 'off' ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'bytenft-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Public Key', 'bytenft-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="text" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'bytenft-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('bytenft_accounts_nonce_action', 'bytenft_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button bytenft-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'bytenft-payment-gateway'); ?>
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

		$logger_context = ['source' => 'bytenft-payment-gateway'];
		$lock_name = '';

		$log_prefix = "[Order #{$order_id}]";

		$start_time = microtime(true);

		$this->bytenft_log(
			$log_prefix . ' Payment process started',
		);

		wc_clear_notices();

		// -------------------------------------------------
		// 1. ORDER VALIDATION
		// -------------------------------------------------
		$order = wc_get_order($order_id);

		if (!$order) {


			if (is_checkout()) {
				wc_add_notice(__('Invalid order.', 'bytenft-payment-gateway'), 'error');
			}

			return $this->build_response(
				'fail',
				'Invalid order.',
				[],
				400,
				$order_id
			);
		}

		$billing_phone = $order->get_billing_phone();

		if (!empty($billing_phone)) {

			$cleaned = preg_replace('/[\s\-().]/', '', $billing_phone);

			$valid = preg_match('/^\+?\d{10,15}$/', $cleaned);

			if (!$valid) {

				$this->bytenft_log_warning(
					$log_prefix . ' Blocked invalid phone BEFORE account selection',
					$log_ctx
				);

				return $this->build_response(
					'fail',
					'Invalid phone number',
					[],
					400,
					$order_id
				);
			}
		}

		$this->bytenft_log(
			$log_prefix . ' Order loaded successfully',
			array_merge($log_ctx, [
				'status' => $order->get_status(),
				'total'  => $order->get_total(),
				'email'  => $order->get_billing_email(),
			])
		);

		// Acquire MySQL Named Lock
		$lock_name = 'bytenft_order_' . (int)$order_id;

		$lock_result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_name));

		if ("1" !== (string)$lock_result) {
			return $this->build_response('fail', 'Payment already in progress (DB Lock).', [], 409, $order_id);
		}

		// Acquire Application Meta Lock (Atomic check)
		$is_locked = $order->get_meta('_bytenft_lock');
		if ('1' === (string)$is_locked) {
			$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
			return $this->build_response('fail', 'Payment already in progress (App Lock).', [], 409, $order_id);
		}

		// Set the lock
		$order->update_meta_data('_bytenft_lock', '1');
		$order->save();

		try {

			// -------------------------------------------------
			// 2. EMAIL VALIDATION
			// -------------------------------------------------
			$billing_email = $order->get_billing_email();

			if (!filter_var($billing_email, FILTER_VALIDATE_EMAIL)) {
				if (is_checkout()) {
					wc_add_notice(__('Please enter a valid email address.', 'bytenft-payment-gateway'), 'error');
				}

				return $this->build_response(
					'fail',
					'Invalid email address',
					[],
					400,
					$order_id
				);
			}

			// -------------------------------------------------
			// 3. PO BOX VALIDATION
			// -------------------------------------------------
			$billing = $order->get_billing_address_1();

			if ($this->is_po_box($billing)) {

				if (is_checkout()) {
					wc_add_notice(__('PO Box addresses are not accepted. Please enter a physical street address.', 'bytenft-payment-gateway'), 'error');
				}

				return $this->build_response(
					'fail',
					'PO Box addresses are not accepted. Please enter a physical street address.',
					[],
					400,
					$order_id
				);
			}

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

				$this->bytenft_log_warning(
					$log_prefix . ' Rate limit exceeded',
					array_merge([
						'ip_address' => $ip_address
					])
				);

				if (is_checkout()) {
					wc_add_notice(__('Too many requests. Please try again later.', 'bytenft-payment-gateway'), 'error');
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
					WC()->session->cleanup_sessions();
					WC()->session->destroy_session();
					WC()->session->set_customer_session_cookie(false);
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
					bytenft_add_unique_order_note(
						$order,
						'sandbox_mode',
						__('This is a test order processed in sandbox mode.', 'bytenft-payment-gateway')
					);
				}
			}

			// -------------------------------------------------
			// 7. PAYMENT ACCOUNT LOOP (UNCHANGED LOGIC)
			// -------------------------------------------------
			$selected_account = null;
			$payment_data     = null;
			$last_error_data  = null;

			while (true) {

				$account = $this->get_next_available_account($used_accounts);

				if (!$account) {

					$this->bytenft_log_warning(
						$log_prefix . ' No eligible payment provider available for this order',
						$log_ctx
					);

					break;
				}

				$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
				$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

				$data = $this->bytenft_prepare_payment_data($order, $public_key, $secret_key);

				if (is_array($data) && ($data['result'] ?? '') === 'fail') {
					$used_accounts[] = $public_key;
					continue;
				}

				$limit_url  = $this->get_api_url('/api/dailylimit');

				$limit_resp = wp_remote_post($limit_url, [
					'method'    => 'POST',
					'timeout'   => 30,
					'body'      => $data,
					'headers'   => [
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
					],
					'sslverify' => true,
				]);

				if (is_wp_error($limit_resp)) {
					$used_accounts[] = $public_key;
					continue;
				}

				$limit_data = json_decode(wp_remote_retrieve_body($limit_resp), true);

				if (($limit_data['status'] ?? '') === 'error') {
					$last_error_data = $limit_data;

					$used_accounts[] = $public_key;

					continue;
				}

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

				$order->update_meta_data('_bytenft_limit_exceeded', true);
				$order->save();

				return $this->build_response(
					'fail',
					$last_error_data['message'] ?? 'Payment limit error.',
					[],
					400,
					$order_id
				);
			}

			ByteNFT_Payment_Gateway_Logger::error(
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

			$this->bytenft_log(
				$log_prefix . ' Payment API response received',
				array_merge([
					'response' => $resp_data
				])
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

					$this->bytenft_log(
						$log_prefix . ' Updating existing payment link record'
					);

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

					$this->bytenft_log(
						$log_prefix . ' Inserting new payment link record',
						[
							'pay_id' => $pay_id,
							'payment_link' => $resp_data['data']['payment_link'] ?? null,
						]
					);

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

				$order->update_meta_data('_bytenft_pay_id', $pay_id);
				$order->update_meta_data('_bytenft_pay_id_updated_at', time());

				$order->update_meta_data('_bytenft_active_pay_id', $pay_id);
				$order->update_meta_data('_bytenft_payment_finalized', false);
			}

			// -------------------------------------------------
			// 11. SUCCESS RESPONSE
			// -------------------------------------------------

			$order->update_status('pending', __('Payment pending.', 'bytenft-payment-gateway'));

			bytenft_add_unique_order_note(
				$order,
				'payment_initiated',
				sprintf(
					__('Payment initiated via ByteNFT (%s)', 'bytenft-payment-gateway'),
					$account['title']
				)
			);

			$payment_link = $resp_data['data']['payment_link'] ?? null;

			if (empty($payment_link)) {

				$this->bytenft_log_error(
					$log_prefix . ' Missing payment link in response',
				);

				return $this->build_response(
					'fail',
					'Payment could not be initiated. Please try again in a moment.',
					[],
					500,
					$order_id
				);
			}

			$this->bytenft_log(
				$log_prefix . ' Payment initiated successfully',
				array_merge([
					'payment_link' => $payment_link,
					'pay_id' => $pay_id
				])
			);

			return $this->build_response(
				'success',
				'Payment initiated',
				[
					'payment_status' => $resp_data['data']['payment_status'] ?? 'pending',
					'redirect' => esc_url($payment_link)
				],
				200,
				$order_id
			);

		} catch (\Exception $e) {

			$this->bytenft_log_error(
				$log_prefix . ' Exception occurred during payment processing',
				array_merge([
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
					'trace'   => $e->getTraceAsString(),
				])
			);

			ByteNFT_Payment_Gateway_Logger::error(
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

			if ($order instanceof WC_Order) {

				$order->update_meta_data('_bytenft_lock', '0');
				$order->save();
			}

			$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
		}
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

	private function is_block_checkout_request() {
		return wp_doing_ajax() && isset($_REQUEST['action'])
			&& $_REQUEST['action'] === 'bytenft_block_gateway_process';
	}

	public function bytenft_display_test_order_tag($order) {
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'bytenft-payment-gateway') . '</strong></p>';
		}
	}

	private function bytenft_get_return_url_base() {
		return rest_url('/bytenft/v1/data');
	}

	private function is_po_box($address) {
		if (empty($address)) return false;

		$clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $address));

		return preg_match('/pob|postoffice/', $clean) === 1;
	}

	private function bytenft_prepare_payment_data($order, $api_public_key, $api_secret) {
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
		$phone_for_normalization = $original_phone ?: $phone;
		$normalized  = $this->bytenft_normalize_phone($phone_for_normalization, $country_code);

		if (empty($normalized['is_valid'])) {
			$error_message = $normalized['error'];
			ByteNFT_Payment_Gateway_Logger::error(
				'Phone number validation failed',
				[
					'order_id'       => $order->get_id(),
					'original_phone' => $original_phone,
					'error'          => $error_message,
				]
			);
			wc_add_notice($error_message, 'error');
			return ['result' => 'fail', 'error' => $error_message];
		}

		$phone        = $normalized['phone'];
		$country_code = $normalized['country_code'];

		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city      = sanitize_text_field($order->get_billing_city());
		$billing_postcode  = sanitize_text_field($order->get_billing_postcode());
		$billing_country   = sanitize_text_field($order->get_billing_country());
		$billing_state     = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(add_query_arg([
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
			'nonce'    => wp_create_nonce('bytenft_payment_nonce'),
			'mode'     => 'wp',
		], $this->bytenft_get_return_url_base()));

		$ip_address = sanitize_text_field($this->bytenft_get_client_ip());

		if (empty($order_id)) {
			ByteNFT_Payment_Gateway_Logger::error(
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
			'plugin_source'    => 'bytenft',
		];
	}

	private function bytenft_normalize_phone($phone, $country_code) {
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
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => 'Only US and European numbers are allowed'];
		}

		if ($totalLength > 15) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => sprintf('Phone number is too long. Maximum allowed length is 15 digits (including country code). Your phone number has %d digits.', $totalLength)];
		}

		return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
	}

	private function bytenft_get_client_ip() {
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

	public function bytenft_add_custom_label_to_order_row($line_items, $order) {
		$order_origin = $order->get_meta('_order_origin');
		if (!empty($order_origin)) {
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}
		return $line_items;
	}

	public function bytenft_woocommerce_not_active_notice() {
		echo '<div class="error"><p>' . esc_html__('ByteNFT Payment Gateway requires WooCommerce to be installed and active.', 'bytenft-payment-gateway') . '</p></div>';
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
                <label for="bytenft_consent">
                    <input type="checkbox" id="bytenft_consent" name="bytenft_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'bytenft-payment-gateway') .
				'</label></p>';
			wp_nonce_field('bytenft_payment', 'bytenft_nonce');
		}
	}

	public function validate_fields() {
		if (!$this->check_for_sql_injection()) return false;

		if ($this->get_option('show_consent_checkbox') === 'yes') {
			$nonce = isset($_POST['bytenft_nonce']) ? sanitize_text_field(wp_unslash($_POST['bytenft_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'bytenft_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'bytenft-payment-gateway'), 'error');
				return false;
			}
			$consent = isset($_POST['bytenft_consent']) ? sanitize_text_field(wp_unslash($_POST['bytenft_consent'])) : '';
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'bytenft-payment-gateway'), 'error');
				return false;
			}
		}
		return true;
	}

	public function bytenft_enqueue_styles_and_scripts() {
		if (is_checkout()) {
			$image_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/loader.gif';
			wp_enqueue_style('bytenft-payment-loader-styles', plugins_url('../assets/css/frontend.css', __FILE__), [], '1.0', 'all');
			wp_enqueue_script('bytenft-js', plugins_url('../assets/js/bytenft.js', __FILE__), ['jquery'], '1.0', true);
			wp_localize_script('bytenft-js', 'bytenft_params', [
				'ajax_url'       => admin_url('admin-ajax.php'),
				'checkout_url'   => wc_get_checkout_url(),
				'bytenft_loader' => $image_url,
				'bytenft_nonce'  => wp_create_nonce('bytenft_payment'),
				'payment_method' => $this->id,
			]);
		}
	}

	function bytenft_admin_scripts($hook) {
		if (
			'woocommerce_page_wc-settings' !== $hook ||
			(sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}
		wp_enqueue_style('bytenft-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');
		wp_enqueue_style('bytenft-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');
		wp_enqueue_script('bytenft-admin-script', plugins_url('../assets/js/bytenft-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/bytenft-admin.js'), true);
		wp_localize_script('bytenft-admin-script', 'bytenft_admin_data', [
			'ajax_url'   => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('bytenft_sync_nonce'),
			'gateway_id' => $this->id,
		]);
	}

	public function bytenft_hide_custom_payment_gateway_conditionally($available_gateways)
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

			ByteNFT_Payment_Gateway_Logger::info(
				"ByteNFT Gateway Decision",
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

			$cache = 'bytenft_' . md5($public . $amount);

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

			$selected = $account;
			$reason   = 'Valid merchant account found';
			break;
		}

		// =====================================================
		// STEP 10: SINGLE FINAL LOG ONLY
		// =====================================================
		ByteNFT_Payment_Gateway_Logger::info(
			"ByteNFT Gateway Decision",
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
		$plugin_version = BYTENFT_PLUGIN_VERSION;
		$accounts       = $this->update_accounts_uniqueID($accounts);
		$group_id       = get_option('bytenft_group_id');
		$cache_base     = 'bytenft_daily_limit_' . md5($public_key . $amount);

		$plugin_logs_data = [
			'valid_accounts' => $accounts,
			'gateway_loaded' => $gateway_loaded,
			'plugin_status'  => $gateway_loaded,
			'plugin_version' => $plugin_version,
			'api_public_key' => $public_key,
			'api_secret_key' => $secret_key,
			'is_sandbox'     => $this->sandbox,
			'group_id'       => $group_id ? $group_id : $this->bytenft_get_group_id(),
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
		unset($available_gateways["bytenft"]);
		$GLOBALS['bytenft_gateway_visibility_' . $this->id] = $available_gateways;
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
		$session_key = 'bytenft_log_' . md5($key . $this->id);

		if (WC()->session->get($session_key)) {
			return;
		}

		WC()->session->set($session_key, true);

		ByteNFT_Payment_Gateway_Logger::info($message, $clean_context);
	}

	protected function validate_account($account, $index) {
		$is_empty  = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
		$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);
		if (!$is_empty && !$is_filled) {
			return sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'bytenft-payment-gateway'), $index + 1);
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
				$errors[] = sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'bytenft-payment-gateway'), $index + 1);
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
		$accounts = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
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

	function bytenft_enqueue_admin_styles($hook) {
		if (strpos($hook, 'woocommerce') === false) return;
		wp_enqueue_style('bytenft-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
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
			wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'bytenft-payment-gateway']);
			return false;
		}
		$response_code = wp_remote_retrieve_response_code($response);
		$response_data = json_decode(wp_remote_retrieve_body($response), true);
		if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
			wc_get_logger()->error('Email Sending Failed: Authentication failed', ['source' => 'bytenft-payment-gateway']);
			return false;
		}
		if (!empty($response_data['error'])) {
			wc_get_logger()->error('byteNFT API Error: ' . json_encode($response_data), ['source' => 'bytenft-payment-gateway']);
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
		$pluginLogApiUrl        = $this->get_api_url('/api/plugin/check/checkout');

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
			$cache_base  = 'bytenft_daily_limit_' . md5($public_key . $amount);
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
				$this->log_info_once_per_session('skip_limit_' . $acc_title, "Skipping '{$acc_title}': daily limit exceeded", [
					'response_status' => $limit_data['status'] ?? 'unknown',
					'message' => $limit_data['message'] ?? '',
				]);
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

	/**
	 * Get the next available payment account.
	 * Uses the already-loaded $this->sandbox value — no re-instantiation needed.
	 */
	private function get_next_available_account($used_accounts = []){
		
		$settings = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
		if (is_string($settings)) $settings = maybe_unserialize($settings);
		if (!is_array($settings)) return false;

		$mode       = $this->sandbox ? 'sandbox' : 'live';
		$status_key = $mode . '_status';
		$public_key = $mode . '_public_key';
		$secret_key = $mode . '_secret_key';
	

		$available_accounts = array_filter($settings, function ($account) use ($used_accounts, $status_key, $public_key, $secret_key) {
			if (in_array($account[$public_key] ?? '', $used_accounts, true)) {
				return false;
			}
			if (!isset($account[$status_key]) || !in_array(strtolower($account[$status_key]), ['active'], true)) {
				return false;
			}
			if (empty($account[$public_key]) || empty($account[$secret_key])) {
				return false;
			}
			return true;
		});

		if (empty($available_accounts)) return false;

		$available_accounts = $this->get_routing_sorted_accounts(array_values($available_accounts));

		if (empty($available_accounts)) {
			return false;
		}
		$account = $available_accounts[0];
		$sanitized_title = preg_replace('/\s+/', '_', $account['title'] ?? 'account');
		$account['lock_key'] = "bytenft_lock_{$sanitized_title}";
		
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

	function check_for_sql_injection() {
		$sql_injection_patterns = [
			'/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER)\b(?![^{}]*})/i',
			'/(\-\-|\/\*|\*\/)/i',
			'/(\b(AND|OR)\b\s*\d+\s*[=<>])/i',
		];
		$errors = [];
		$checkout_fields = WC()->checkout()->get_checkout_fields();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ($_POST as $key => $value) {
			if (is_string($value)) {
				foreach ($sql_injection_patterns as $pattern) {
					if (preg_match($pattern, $value)) {
						$field_label = isset($checkout_fields['billing'][$key]['label'])
							? $checkout_fields['billing'][$key]['label']
							: (isset($checkout_fields['shipping'][$key]['label'])
								? $checkout_fields['shipping'][$key]['label']
								: ucfirst(str_replace('_', ' ', $key)));
						$ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
						wc_get_logger()->info("SecurityCheck | Potential SQL Injection | Field: {$field_label}, IP: {$ip_address}", ['source' => 'bytenft-payment-gateway']);
						/* translators: %s is the field label. */
						$errors[] = sprintf(esc_html__('Please enter a valid "%s".', 'bytenft-payment-gateway'), $field_label);
						break;
					}
				}
			}
		}
		if (!empty($errors)) {
			foreach ($errors as $error) wc_add_notice($error, 'error');
			return false;
		}
		return true;
	}
}
