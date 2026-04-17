<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_AccountService
{
    private $apiClient;
    private $logger;
    private $sandbox;

    public function __construct($apiClient, $logger, $sandbox = false)
    {
        $this->apiClient = $apiClient;
        $this->logger    = $logger;
        $this->sandbox   = $sandbox;
    }

    /**
     * Refresh account statuses from remote API
     */
    public function refreshAccounts(): bool
    {
        $accounts = get_option('woocommerce_bytenft_payment_gateway_accounts', []);

        if (empty($accounts)) {
            $this->logger->info('No accounts found to refresh');
            return false;
        }

        $updatedAccounts = [];

        foreach ($accounts as $account) {

            $useSandbox = $this->sandbox;

            $publicKey = $useSandbox
                ? $account['sandbox_public_key']
                : $account['live_public_key'];

            $secretKey = $useSandbox
                ? $account['sandbox_secret_key']
                : $account['live_secret_key'];

            $this->logger->info("Checking account status", [
                'title' => $account['title'],
                'sandbox' => $useSandbox,
            ]);

            $response = $this->apiClient->post('/api/check-merchant-status', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $publicKey,
                    'Content-Type'  => 'application/json',
                ],
                'body' => [
                    'api_secret_key' => $secretKey,
                    'is_sandbox'     => $useSandbox,
                ],
                'timeout' => 10,
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

            $this->logger->info("Account status updated", [
                'title' => $account['title'],
                'status' => $isError ? 'Inactive' : 'Active'
            ]);
        }

        update_option('woocommerce_bytenft_payment_gateway_accounts', $updatedAccounts);

        return true;
    }

    /**
     * Get cached or updated accounts
     */
    public function getAccounts()
    {
        return get_option('woocommerce_bytenft_payment_gateway_accounts', []);
    }
}