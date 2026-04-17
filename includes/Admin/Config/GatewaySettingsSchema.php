<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_GatewaySettingsSchema
{
    public function __construct(
        private string $base_url
    ) {}

    public function get(string $gateway_id): array
    {
        $dev_link = sprintf(
            '<strong><a href="%s" target="_blank">%s</a></strong><br>',
            esc_url($this->base_url . '/developers'),
            __('click here to access your developer account', 'bytenft-payment-gateway')
        );

        return apply_filters(
            "bytenft_gateway_settings_fields_{$gateway_id}",
            $this->build($dev_link)
        );
    }

    private function build(string $dev_link): array
    {
        return [

            // =========================
            // BASIC SETTINGS
            // =========================
            'enabled' => [
                'title'   => __('Enable/Disable', 'bytenft-payment-gateway'),
                'label'   => __('Enable ByteNFT Payment Gateway', 'bytenft-payment-gateway'),
                'type'    => 'checkbox',
                'default' => 'no',
            ],

            'title' => [
                'title'       => __('Title', 'bytenft-payment-gateway'),
                'type'        => 'text',
                'description' => __('Shown at checkout.', 'bytenft-payment-gateway'),
                'default'     => __('Pay with ByteNFT', 'bytenft-payment-gateway'),
                'desc_tip'    => true,
            ],

            'description' => [
                'title'       => __('Description', 'bytenft-payment-gateway'),
                'type'        => 'textarea',
                'description' => __('Checkout description.', 'bytenft-payment-gateway'),
                'default'     => __(
                    'Pay securely using ByteNFT gateway.',
                    'bytenft-payment-gateway'
                ),
                'desc_tip'    => true,
            ],

            // =========================
            // INFO BLOCK
            // =========================
            'instructions' => [
                'title'       => __('Instructions', 'bytenft-payment-gateway'),
                'type'        => 'title',
                'description' => sprintf(
                    __('%s Configure API keys in developer settings.', 'bytenft-payment-gateway'),
                    $dev_link
                ),
            ],

            // =========================
            // MODE
            // =========================
            'sandbox' => [
                'title'       => __('Sandbox', 'bytenft-payment-gateway'),
                'label'       => __('Enable Sandbox Mode', 'bytenft-payment-gateway'),
                'type'        => 'checkbox',
                'default'     => 'no',
            ],

            // =========================
            // CORE DATA
            // =========================
            'group_id' => [
                'type' => 'hidden',
            ],

            'accounts' => [
                'title'       => __('Payment Accounts', 'bytenft-payment-gateway'),
                'type'        => 'accounts_repeater',
                'description' => __('Manage multiple payment accounts.', 'bytenft-payment-gateway'),
            ],

            // =========================
            // ORDER SETTINGS
            // =========================
            'order_status' => [
                'title'   => __('Order Status', 'bytenft-payment-gateway'),
                'type'    => 'select',
                'default' => 'processing',
                'options' => [
                    'processing' => __('Processing', 'bytenft-payment-gateway'),
                    'completed'  => __('Completed', 'bytenft-payment-gateway'),
                ],
            ],

            'show_consent_checkbox' => [
                'title'   => __('Show Consent Checkbox', 'bytenft-payment-gateway'),
                'type'    => 'checkbox',
                'default' => 'no',
            ],
        ];
    }
}