<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_EnvironmentService
{
    /**
     * Run full system compatibility check
     *
     * @return array{
     *   valid: bool,
     *   message: string|null
     * }
     */
    public function check(): array
    {
        $phpCheck = $this->check_php_version();
        if ($phpCheck) {
            return $phpCheck;
        }

        $wcCheck = $this->check_woocommerce_version();
        if ($wcCheck) {
            return $wcCheck;
        }

        return [
            'valid'   => true,
            'message' => null,
        ];
    }

    /**
     * PHP version validation
     */
    private function check_php_version(): ?array
    {
        if (version_compare(PHP_VERSION, BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
            return [
                'valid'   => false,
                'message' => sprintf(
                    __('The ByteNFT plugin requires PHP %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
                    BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER,
                    PHP_VERSION
                ),
            ];
        }

        return null;
    }

    /**
     * WooCommerce version validation
     */
    private function check_woocommerce_version(): ?array
    {
        $wc_db_version    = get_option('woocommerce_db_version');
        $wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

        if (!$wc_db_version || version_compare($wc_db_version, BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
            return [
                'valid'   => false,
                'message' => sprintf(
                    __('Requires WooCommerce DB version %1$s. Current: %2$s', 'bytenft-payment-gateway'),
                    BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER,
                    $wc_db_version ?: 'undefined'
                ),
            ];
        }

        if (!$wc_plugin_version || version_compare($wc_plugin_version, BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
            return [
                'valid'   => false,
                'message' => sprintf(
                    __('Requires WooCommerce plugin version %1$s. Current: %2$s', 'bytenft-payment-gateway'),
                    BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER,
                    $wc_plugin_version ?: 'undefined'
                ),
            ];
        }

        return null;
    }
}