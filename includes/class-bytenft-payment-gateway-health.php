<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * ByteNFT Integration Health.
 *
 * Reports three things a merchant can act on: whether the gateway is configured,
 * whether real payments are completing, and whether ByteNFT and WooCommerce agree
 * on order status.
 *
 * Constraints:
 *  - Read only. No order, payment or webhook is ever created, retried or repaired.
 *  - No new endpoint and no test payments. Every check observes data the real
 *    payment flow already produced.
 *  - No duplicated business logic. Payment state is read from the `_bytenft_state`
 *    meta owned by BYTENFT_PAYMENT_ENGINE; the success target comes from the
 *    gateway's own `order_status` setting.
 */
class BYTENFT_PAYMENT_GATEWAY_Health
{
	const STATUS_PASSED  = 'passed';
	const STATUS_PENDING = 'pending';
	const STATUS_FAILED  = 'failed';

	/** Maximum number of recent orders inspected per run. Keeps every check bounded. */
	const ORDER_SCAN_LIMIT = 50;

	/**
	 * Run the health checks and return the report.
	 *
	 * @return array<string, mixed>
	 */
	public static function bytenft_run_checks()
	{
		$settings = get_option('woocommerce_bytenft_settings', []);
		$settings = is_array($settings) ? $settings : [];

		$accounts = maybe_unserialize(get_option('woocommerce_bytenft_payment_gateway_accounts', []));
		$accounts = is_array($accounts) ? $accounts : [];

		$configuration = self::bytenft_check_configuration($settings, $accounts);

		// Only one order query, shared by the two order based areas.
		$orders = self::bytenft_get_recent_orders();

		return self::bytenft_build_report([
			$configuration,
			self::bytenft_check_payment_flow($orders),
			self::bytenft_check_order_sync($settings, $orders),
		]);
	}

	/**
	 * The most recent ByteNFT orders.
	 *
	 * @return array<int, WC_Order>
	 */
	private static function bytenft_get_recent_orders()
	{
		if (!function_exists('wc_get_orders')) {
			return [];
		}

		$orders = wc_get_orders([
			'payment_method' => 'bytenft',
			'limit'          => self::ORDER_SCAN_LIMIT,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		return is_array($orders) ? $orders : [];
	}

	/* =====================================================================
	 * AREA 1: GATEWAY CONFIGURATION
	 * ===================================================================== */

	/**
	 * Gateway enabled, and a usable account for the active environment.
	 *
	 * @param array $settings Gateway settings.
	 * @param array $accounts Configured payment accounts.
	 * @return array<string, mixed>
	 */
	private static function bytenft_check_configuration($settings, $accounts)
	{
		$title = __('Gateway Configuration', 'bytenft-payment-gateway');

		if (($settings['enabled'] ?? '') !== 'yes') {
			return self::bytenft_area('configuration', $title, self::STATUS_FAILED, __('ByteNFT is turned off and is not offered at checkout.', 'bytenft-payment-gateway'));
		}

		$is_sandbox = (($settings['sandbox'] ?? '') === 'yes');
		$prefix     = $is_sandbox ? 'sandbox' : 'live';

		$has_credentials = false;
		$has_active      = false;

		foreach ($accounts as $account) {
			if (!is_array($account) || empty($account[$prefix . '_public_key']) || empty($account[$prefix . '_secret_key'])) {
				continue;
			}

			$has_credentials = true;

			// Account state is maintained by the gateway's existing account sync.
			if (strtolower((string) ($account[$prefix . '_status'] ?? 'active')) === 'active') {
				$has_active = true;
				break;
			}
		}

		if (!$has_credentials) {
			return self::bytenft_area('configuration', $title, self::STATUS_FAILED, $is_sandbox
				? __('No sandbox API keys have been added yet.', 'bytenft-payment-gateway')
				: __('No live API keys have been added yet.', 'bytenft-payment-gateway'));
		}

		if (!$has_active) {
			return self::bytenft_area('configuration', $title, self::STATUS_FAILED, __('Your API keys are not being accepted by ByteNFT. Check them in your ByteNFT account.', 'bytenft-payment-gateway'));
		}

		return self::bytenft_area('configuration', $title, self::STATUS_PASSED, $is_sandbox
			? __('Ready, in sandbox mode. Real payments are not being taken.', 'bytenft-payment-gateway')
			: __('Ready to accept payments.', 'bytenft-payment-gateway'));
	}

	/* =====================================================================
	 * AREA 2: PAYMENT FLOW
	 * ===================================================================== */

	/**
	 * Whether real ByteNFT payments are completing.
	 *
	 * @param array<int, WC_Order> $orders Recent ByteNFT orders.
	 * @return array<string, mixed>
	 */
	private static function bytenft_check_payment_flow($orders)
	{
		$title = __('Payment Flow', 'bytenft-payment-gateway');

		if (empty($orders)) {
			return self::bytenft_area('payment_flow', $title, self::STATUS_PENDING, __('No ByteNFT payment yet. This confirms itself on the first real payment.', 'bytenft-payment-gateway'));
		}

		$completed = 0;
		$attempted = 0;

		foreach ($orders as $order) {
			if (self::bytenft_is_paid($order)) {
				$completed++;
				continue;
			}

			// Only count attempts that actually reached ByteNFT and came back unpaid.
			if (in_array((string) $order->get_meta('_bytenft_state'), ['failed', 'cancelled', 'expired'], true)) {
				$attempted++;
			}
		}

		if ($completed > 0) {
			return self::bytenft_area('payment_flow', $title, self::STATUS_PASSED, __('Recent ByteNFT payments are completing successfully.', 'bytenft-payment-gateway'));
		}

		if ($attempted > 0) {
			return self::bytenft_area('payment_flow', $title, self::STATUS_FAILED, __('Recent ByteNFT payments are not completing. Customers are unable to pay.', 'bytenft-payment-gateway'));
		}

		return self::bytenft_area('payment_flow', $title, self::STATUS_PENDING, __('No ByteNFT payment has been completed yet.', 'bytenft-payment-gateway'));
	}

	/* =====================================================================
	 * AREA 3: ORDER STATUS / WEBHOOK SYNC
	 * ===================================================================== */

	/**
	 * Whether the ByteNFT payment state and the WooCommerce order status agree.
	 *
	 * Detection only. A mismatch is reported for a human to resolve; no order is
	 * ever modified here.
	 *
	 * @param array                $settings Gateway settings.
	 * @param array<int, WC_Order> $orders   Recent ByteNFT orders.
	 * @return array<string, mixed>
	 */
	private static function bytenft_check_order_sync($settings, $orders)
	{
		$title = __('Order Status Sync', 'bytenft-payment-gateway');

		$unpaid    = []; // Paid at ByteNFT, not paid in WooCommerce. The costly case.
		$other     = []; // Failed or cancelled at ByteNFT, still open in WooCommerce.
		$compared  = 0;

		foreach ($orders as $order) {
			$state = (string) $order->get_meta('_bytenft_state');

			// Orders the engine never resolved cannot be compared.
			if (!in_array($state, ['success', 'failed', 'cancelled', 'expired'], true)) {
				continue;
			}

			$compared++;

			if ($state === 'success') {
				if (!self::bytenft_is_paid($order)) {
					$unpaid[] = $order->get_id();
				}
				continue;
			}

			// Failed, cancelled or expired must not be sitting in WooCommerce as paid.
			if (self::bytenft_is_paid($order)) {
				$other[] = $order->get_id();
			}
		}

		if (!empty($unpaid)) {
			return self::bytenft_area('order_sync', $title, self::STATUS_FAILED, sprintf(
				/* translators: 1: number of orders, 2: comma separated order numbers. */
				_n(
					'%1$d order was paid at ByteNFT but is not marked paid in WooCommerce: %2$s',
					'%1$d orders were paid at ByteNFT but are not marked paid in WooCommerce: %2$s',
					count($unpaid),
					'bytenft-payment-gateway'
				),
				count($unpaid),
				self::bytenft_list_orders($unpaid)
			));
		}

		if (!empty($other)) {
			return self::bytenft_area('order_sync', $title, self::STATUS_FAILED, sprintf(
				/* translators: 1: number of orders, 2: comma separated order numbers. */
				_n(
					'%1$d order is marked paid in WooCommerce but was not paid at ByteNFT: %2$s',
					'%1$d orders are marked paid in WooCommerce but were not paid at ByteNFT: %2$s',
					count($other),
					'bytenft-payment-gateway'
				),
				count($other),
				self::bytenft_list_orders($other)
			));
		}

		if ($compared === 0) {
			return self::bytenft_area('order_sync', $title, self::STATUS_PENDING, __('No completed ByteNFT payment to compare yet.', 'bytenft-payment-gateway'));
		}

		return self::bytenft_area('order_sync', $title, self::STATUS_PASSED, __('ByteNFT payments and WooCommerce order statuses match.', 'bytenft-payment-gateway'));
	}

	/**
	 * Whether WooCommerce considers this order paid.
	 *
	 * Accepts both success targets the gateway offers, plus statuses an order may
	 * legitimately reach after payment.
	 *
	 * @param WC_Order $order Order to test.
	 * @return bool
	 */
	private static function bytenft_is_paid($order)
	{
		return $order->has_status(['processing', 'completed', 'refunded']);
	}

	/**
	 * Render up to three order numbers, e.g. "#588, #566 and 4 more".
	 *
	 * @param array<int, int> $ids Order ids.
	 * @return string
	 */
	private static function bytenft_list_orders($ids)
	{
		$shown = array_slice($ids, 0, 3);
		$text  = '#' . implode(', #', $shown);

		if (count($ids) > count($shown)) {
			$text .= sprintf(
				/* translators: %d: number of additional orders. */
				__(' and %d more', 'bytenft-payment-gateway'),
				count($ids) - count($shown)
			);
		}

		return $text;
	}

	/* =====================================================================
	 * REPORT
	 * ===================================================================== */

	/**
	 * Build one area result.
	 *
	 * @param string $id      Area id.
	 * @param string $title   Area title.
	 * @param string $status  passed|pending|failed.
	 * @param string $message Single merchant facing sentence.
	 * @return array<string, mixed>
	 */
	private static function bytenft_area($id, $title, $status, $message)
	{
		return [
			'id'      => $id,
			'title'   => $title,
			'status'  => $status,
			'message' => $message,
		];
	}

	/**
	 * Reduce the areas to the overall verdict.
	 *
	 * Configuration gates the result: until the gateway is set up, nothing else can
	 * be judged.
	 *
	 * @param array<int, array<string, mixed>> $areas Area results.
	 * @return array<string, mixed>
	 */
	private static function bytenft_build_report($areas)
	{
		$configuration_failed = (($areas[0]['status'] ?? '') === self::STATUS_FAILED);

		$failed = 0;
		foreach ($areas as $area) {
			if ($area['status'] === self::STATUS_FAILED) {
				$failed++;
			}
		}

		if ($configuration_failed) {
			$status = 'setup';
		} elseif ($failed > 0) {
			$status = 'attention';
		} else {
			$status = 'healthy';
		}

		return [
			'status'      => $status,
			'label'       => self::bytenft_get_status_label($status),
			'description' => self::bytenft_get_status_description($status, $areas),
			'areas'       => $areas,
			'checked_at'  => current_time('mysql'),
		];
	}

	/**
	 * Report used when WooCommerce is unavailable.
	 *
	 * @return array<string, mixed>
	 */
	public static function bytenft_unavailable_report()
	{
		return [
			'status'      => 'setup',
			'label'       => self::bytenft_get_status_label('setup'),
			'description' => __('WooCommerce is unavailable, so integration health cannot be checked.', 'bytenft-payment-gateway'),
			'areas'       => [],
			'checked_at'  => current_time('mysql'),
		];
	}

	/**
	 * Human readable label for an overall status.
	 *
	 * @param string $status healthy|attention|setup.
	 * @return string
	 */
	public static function bytenft_get_status_label($status)
	{
		switch ($status) {
			case 'healthy':
				return __('Healthy', 'bytenft-payment-gateway');
			case 'attention':
				return __('Attention Required', 'bytenft-payment-gateway');
			default:
				return __('Setup Required', 'bytenft-payment-gateway');
		}
	}

	/**
	 * One sentence explaining the overall verdict.
	 *
	 * @param string $status Overall status.
	 * @param array  $areas  Area results.
	 * @return string
	 */
	private static function bytenft_get_status_description($status, $areas)
	{
		if ($status === 'setup') {
			return $areas[0]['message'] ?? __('Finish setting up the gateway.', 'bytenft-payment-gateway');
		}

		if ($status === 'attention') {
			foreach ($areas as $area) {
				if ($area['status'] === self::STATUS_FAILED) {
					// Surface the first real failure reason, not a count.
					return $area['message'];
				}
			}
		}

		return __('Your ByteNFT integration is working normally.', 'bytenft-payment-gateway');
	}

	/* =====================================================================
	 * RENDERING - shared by the page template and the AJAX refresh
	 * ===================================================================== */

	/**
	 * Render the areas as compact rows.
	 *
	 * @param array<int, array<string, mixed>> $areas Area results.
	 * @return string HTML.
	 */
	public static function bytenft_render_areas($areas)
	{
		if (empty($areas) || !is_array($areas)) {
			return '<p class="bytenft-health-empty">'
				. esc_html__('No health information is available yet.', 'bytenft-payment-gateway')
				. '</p>';
		}

		$icons = [
			self::STATUS_PASSED  => 'fa-check-circle',
			self::STATUS_FAILED  => 'fa-times-circle',
			self::STATUS_PENDING => 'fa-clock-o',
		];

		$html = '';

		foreach ($areas as $area) {
			$status = $area['status'] ?? self::STATUS_PENDING;
			$icon   = $icons[$status] ?? 'fa-clock-o';

			$html .= '<li class="bytenft-area bytenft-area--' . esc_attr($status) . '">';
			$html .= '<i class="fa ' . esc_attr($icon) . ' bytenft-area-icon" aria-hidden="true"></i>';
			$html .= '<span class="bytenft-area-body">';
			$html .= '<span class="bytenft-area-title">' . esc_html($area['title'] ?? '') . '</span>';
			$html .= '<span class="bytenft-area-message">' . esc_html($area['message'] ?? '') . '</span>';
			$html .= '</span>';
			$html .= '</li>';
		}

		return $html;
	}
}
