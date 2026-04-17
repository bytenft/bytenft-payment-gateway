<?php
if (!defined('ABSPATH')) exit;

class BYTENFT_PaymentRequestBuilder
{
    public function build($order, $api_public_key, $api_secret, $sandbox = false)
    {
        $order_id = $order->get_id();

        $first_name = sanitize_text_field($order->get_billing_first_name());
        $last_name  = sanitize_text_field($order->get_billing_last_name());

        $amount = number_format($order->get_total(), 2, '.', '');

        $email = sanitize_text_field($order->get_billing_email());
        $phone = sanitize_text_field($order->get_billing_phone());

        $country = $order->get_billing_country();
        $country_code = WC()->countries->get_country_calling_code($country);

        $ip_address = $this->get_client_ip();

        $redirect_url = esc_url_raw(add_query_arg([
            'order_id' => $order_id,
            'key'      => $order->get_order_key(),
            'nonce'    => wp_create_nonce('bytenft_payment_nonce'),
            'mode'     => 'wp',
        ], rest_url('/bytenft/v1/data')));

        return [
            'api_secret'     => $api_secret,
            'api_public_key' => $api_public_key,

            'first_name'     => $first_name,
            'last_name'      => $last_name,

            'amount'         => $amount,
            'email'          => $email,
            'phone_number'   => $phone,
            'country_code'   => $country_code,

            'redirect_url'   => $redirect_url,
            'redirect_time'  => 3,

            'ip_address'     => $ip_address,
            'source'         => 'wordpress',
            'plugin_source'  => 'bytenft',

            'is_sandbox'     => $sandbox,
            'curr_code'      => sanitize_text_field($order->get_currency()),

            'remarks'        => 'Order ' . $order->get_order_number(),

            'meta_data'      => [
                'order_id' => $order_id,
                'amount'   => $amount,
                'source'   => 'woocommerce',
            ],

            'billing_address_1' => sanitize_text_field($order->get_billing_address_1()),
            'billing_address_2' => sanitize_text_field($order->get_billing_address_2()),
            'billing_city'      => sanitize_text_field($order->get_billing_city()),
            'billing_postcode'  => sanitize_text_field($order->get_billing_postcode()),
            'billing_country'   => sanitize_text_field($order->get_billing_country()),
            'billing_state'     => sanitize_text_field($order->get_billing_state()),
        ];
    }

    private function get_client_ip()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}