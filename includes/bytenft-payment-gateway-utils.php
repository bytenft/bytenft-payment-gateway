<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function bytenft_check_system_requirements()
{
	if (version_compare(phpversion(), BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('The ByteNFT Payment Gateway plugin requires PHP version %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
			BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('The ByteNFT Payment Gateway plugin requires WooCommerce database version %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
			BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('undefined', 'bytenft-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('The ByteNFT Payment Gateway plugin requires WooCommerce plugin version %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
			BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('undefined', 'bytenft-payment-gateway')
		);
	}

	return false;
}

/**
 * Activation check for the plugin.
 */
function bytenft_activation_check()
{
	$environment_warning = bytenft_check_system_requirements();
	if ($environment_warning) {
		deactivate_plugins(plugin_basename(BYTENFT_PAYMENT_GATEWAY_FILE));
		wp_die(esc_html($environment_warning)); // Escape the output before calling wp_die
	}
}

if (!function_exists('bytenft_add_unique_order_note')) {

    function bytenft_add_unique_order_note($order, $key, $message)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty($message)) {
            return false;
        }

        // Plugin identifier (IMPORTANT for tracking in WP admin)
        $plugin_prefix = '<strong>ByteNFT Gateway</strong>';

        // Unique meta key per note type (scoped to plugin)
        $meta_key = '_bytenft_order_note_' . sanitize_key($key);

        // Check if already exists
        $existing = $order->get_meta($meta_key, true);

        if (!empty($existing)) {
            return false;
        }

        // Prepend plugin identifier to every note
        $final_message = $plugin_prefix . "\n\n" . wp_kses_post($message);

        // Add WooCommerce order note
        $order->add_order_note($final_message);

        // Store timestamp using WooCommerce timezone-aware time
        $order->update_meta_data($meta_key, current_time('timestamp'));

        $order->save();

        return true;
    }
}

/**
 * Normalise an email address for identity comparison.
 *
 * @param mixed $email Raw email.
 * @return string
 */
function bytenft_normalize_identity_email( $email ) {
	return strtolower( trim( (string) $email ) );
}

/**
 * Normalise a phone number for identity comparison.
 *
 * Digits only, so "+1 (555) 010-1234" and "15550101234" compare equal and a
 * pure formatting change never counts as a different customer.
 *
 * @param mixed $phone Raw phone number.
 * @return string
 */
function bytenft_normalize_identity_phone( $phone ) {
	return preg_replace( '/\D/', '', (string) $phone );
}

/**
 * Read the billing email / phone the customer just submitted.
 *
 * Covers Classic Checkout ('billing_email' / 'billing_phone') and the block
 * checkout AJAX route, whose contact step posts 'contact_email' and may carry
 * the number as 'shipping_phone'. The first NON-EMPTY of the aliases wins, so
 * a layout that posts an empty 'billing_email' alongside a filled
 * 'contact_email' still yields the real address.
 *
 * A field the customer did not supply - absent from the request, or submitted
 * empty - comes back as null. Callers must read null as "nothing to compare",
 * never as "the customer cleared it": some checkout layouts simply do not post
 * a phone field at all.
 *
 * @return array{email: ?string, phone: ?string}
 */
function bytenft_get_posted_customer_identity() {

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only comparison; the checkout nonce is verified by WC_Checkout::process_checkout() / the AJAX handler before these callers run.
	$email = null;

	foreach ( [ 'billing_email', 'contact_email' ] as $key ) {

		if ( ! empty( $_POST[ $key ] ) ) {
			$email = sanitize_email( wp_unslash( $_POST[ $key ] ) );
			break;
		}
	}

	$phone = null;

	foreach ( [ 'billing_phone', 'shipping_phone' ] as $key ) {

		if ( ! empty( $_POST[ $key ] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			break;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	return [
		'email' => $email,
		'phone' => $phone,
	];
}

/**
 * Record the customer identity a ByteNFT payment link was minted for.
 *
 * Called once a payment link exists for the order, so the next "Place Order"
 * can tell "another attempt by the same customer" apart from "the customer
 * edited their details and needs a fresh order and link".
 *
 * Does not save the order; the caller saves.
 *
 * @param WC_Order $order Order the link belongs to.
 * @return void
 */
function bytenft_store_order_customer_identity( $order ) {

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$order->update_meta_data(
		'_bytenft_request_email',
		bytenft_normalize_identity_email( $order->get_billing_email() )
	);

	$order->update_meta_data(
		'_bytenft_request_phone',
		bytenft_normalize_identity_phone( $order->get_billing_phone() )
	);
}

/**
 * Has the customer changed the email or phone since this order's last
 * ByteNFT payment link was minted?
 *
 * Used to decide whether the next attempt may resume this order or has to get
 * a brand new WooCommerce order and payment link.
 *
 * Deliberately conservative - it only reports a change when a field arrives
 * with a NON-EMPTY value that differs from the stored one:
 *
 * - No stored identity (no link ever minted) -> false, nothing to compare.
 * - Field absent or submitted empty -> ignored, because some checkout layouts
 *   never post a phone field and that must not look like the customer
 *   cleared it.
 *
 * Both of those fall back to "unchanged", which preserves the existing
 * retry-the-same-order behaviour; only a real, visible edit forces a new order.
 * The trade-off is deliberate: a customer who blanks their phone outright
 * keeps the same order, which is safe, whereas a false positive here would
 * break the failed-then-success retry flow.
 *
 * @param WC_Order $order Order awaiting payment.
 * @return bool
 */
function bytenft_order_customer_identity_changed( $order ) {

	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$stored_email = bytenft_normalize_identity_email( $order->get_meta( '_bytenft_request_email' ) );
	$stored_phone = bytenft_normalize_identity_phone( $order->get_meta( '_bytenft_request_phone' ) );

	// No payment link has been minted for this order yet.
	if ( '' === $stored_email && '' === $stored_phone ) {
		return false;
	}

	$posted = bytenft_get_posted_customer_identity();

	if ( null !== $posted['email'] ) {

		$email = bytenft_normalize_identity_email( $posted['email'] );

		if ( '' !== $email && '' !== $stored_email && $email !== $stored_email ) {
			return true;
		}
	}

	if ( null !== $posted['phone'] ) {

		$phone = bytenft_normalize_identity_phone( $posted['phone'] );

		if ( '' !== $phone && '' !== $stored_phone && $phone !== $stored_phone ) {
			return true;
		}
	}

	return false;
}
