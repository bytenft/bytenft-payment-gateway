<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_ByteNFTApiClient
{
    private string $base_url;

    public function __construct(string $base_url)
    {
        $this->base_url = rtrim($base_url, '/');
    }

    public function preparePayment($order, $public_key, $secret_key): array
    {
        return [
            'amount' => $order->get_total(),
            'email'  => $order->get_billing_email(),
            'order_id' => $order->get_id(),
            'public_key' => $public_key,
        ];
    }

    public function checkDailyLimit(array $payload, string $public_key): array
    {
        $response = wp_remote_post($this->base_url . '/api/dailylimit', [
            'method'  => 'POST',
            'timeout' => 30,
            'body'    => $payload,
            'headers' => [
                'Authorization' => 'Bearer ' . $public_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'error', 'code' => 'api_error'];
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function requestPayment(array $payload, string $public_key): array
    {
        $response = wp_remote_post($this->base_url . '/api/request-payment', [
            'method'  => 'POST',
            'timeout' => 30,
            'body'    => $payload,
            'headers' => [
                'Authorization' => 'Bearer ' . $public_key,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => 'API error'];
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}