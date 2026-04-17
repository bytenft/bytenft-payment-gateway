<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_AccountService
{
    public function __construct(
        private BYTENFT_ByteNFTApiClient $apiClient,
        private WC_Logger $logger,
        private array $logger_context = []
    ) {}

    /**
     * Refresh account status from remote API (Stripe-style)
     */
    public function refreshAccountStatuses(bool $sandbox): bool
    {
        $accounts = get_option('woocommerce_bytenft_payment_gateway_accounts', []);

        if (empty($accounts)) {
            $this->log('info', 'No accounts found for refresh');
            return false;
        }

        $updatedAccounts = [];

        foreach ($accounts as $account) {

            $publicKey = $sandbox
                ? $account['sandbox_public_key']
                : $account['live_public_key'];

            $secretKey = $sandbox
                ? $account['sandbox_secret_key']
                : $account['live_secret_key'];

            $this->log('info', "Checking merchant status", [
                'title'      => $account['title'],
                'sandbox'    => $sandbox,
                'public_key' => $publicKey
            ]);

            // =========================
            // API CALL VIA CLIENT
            // =========================
            $response = $this->apiClient->checkMerchantStatus([
                'public_key' => $publicKey,
                'secret_key' => $secretKey,
                'sandbox'    => $sandbox
            ]);

            $isError = false;

            if (is_wp_error($response)) {
                $isError = true;
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $isError = strtolower($body['status'] ?? '') === 'error';
            }

            $updatedAccounts[] = [
                'title'              => $account['title'],
                'priority'           => $account['priority'] ?? 1,
                'live_public_key'    => $account['live_public_key'],
                'live_secret_key'    => $account['live_secret_key'],
                'sandbox_public_key' => $account['sandbox_public_key'],
                'sandbox_secret_key' => $account['sandbox_secret_key'],
                'has_sandbox'        => $account['has_sandbox'] ?? 'off',
                'sandbox_status'     => $isError ? 'Inactive' : 'Active',
                'live_status'        => $isError ? 'Inactive' : 'Active',
                'checkout_title'     => $account['checkout_title'] ?? '',
                'checkout_subtitle'  => $account['checkout_subtitle'] ?? '',
            ];

            $this->log(
                $isError ? 'warning' : 'info',
                "Account status updated",
                [
                    'title' => $account['title'],
                    'status' => $isError ? 'Inactive' : 'Active'
                ]
            );
        }

        // =========================
        // PERSIST
        // =========================
        update_option(
            'woocommerce_bytenft_payment_gateway_accounts',
            $updatedAccounts
        );

        return true;
    }

    /**
     * Simple getter (used by PaymentService)
     */
    public function getAccounts(): array
    {
        return get_option('woocommerce_bytenft_payment_gateway_accounts', []);
    }

    /**
     * Logging wrapper
     */
    private function log(string $level, string $message, array $context = [])
    {
        $this->logger->log(
            $level,
            $message,
            array_merge($this->logger_context, $context)
        );
    }
}