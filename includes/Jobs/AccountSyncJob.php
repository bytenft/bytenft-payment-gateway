<?php

if (!defined('ABSPATH')) exit;

class BYTENFT_AccountSyncJob
{
    private string $base_url;
    private $logger;

    public function __construct(string $base_url)
    {
        $this->base_url = $base_url;
        $this->logger   = wc_get_logger();
    }

    public function register()
    {
        add_action('bytenft_cron_event', [$this, 'handle']);
    }

    public function handle()
    {
        $logger_context = ['source' => 'bytenft-account-sync'];

        $accounts = get_option('woocommerce_bytenft_payment_gateway_accounts');

        if (is_string($accounts)) {
            $accounts = maybe_unserialize($accounts);
        }

        if (!is_array($accounts)) {
            $this->logger->warning('Invalid accounts format', $logger_context);
            return [];
        }

        $payload = $this->build_payload($accounts);

        if (empty($payload)) {
            $this->logger->warning('No valid accounts found', $logger_context);
            return [];
        }

        $response = wp_remote_post($this->base_url . '/api/sync-account-status', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['accounts' => $payload]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Sync API failed', $logger_context);
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['data'])) {
            return [];
        }

        $this->update_accounts($accounts, $data['data']);

        return $data['data'];
    }

    private function build_payload(array $accounts): array
    {
        $payload = [];

        foreach ($accounts as $account) {
            if (!is_array($account)) continue;

            if (!empty($account['live_public_key'])) {
                $payload[] = [
                    'account_name' => $account['title'],
                    'public_key'   => $account['live_public_key'],
                    'secret_key'   => $account['live_secret_key'] ?? '',
                    'mode'         => 'live',
                ];
            }

            if (!empty($account['sandbox_public_key'])) {
                $payload[] = [
                    'account_name' => $account['title'],
                    'public_key'   => $account['sandbox_public_key'],
                    'secret_key'   => $account['sandbox_secret_key'] ?? '',
                    'mode'         => 'sandbox',
                ];
            }
        }

        return $payload;
    }

    private function update_accounts(array &$accounts, array $updates): void
    {
        $updated = false;

        foreach ($updates as $status) {
            foreach ($accounts as &$account) {

                if (!is_array($account)) continue;

                if (
                    $status['mode'] === 'live' &&
                    ($account['live_public_key'] ?? '') === $status['public_key']
                ) {
                    $account['live_status'] = $status['status'];
                    $updated = true;
                }

                if (
                    $status['mode'] === 'sandbox' &&
                    ($account['sandbox_public_key'] ?? '') === $status['public_key']
                ) {
                    $account['sandbox_status'] = $status['status'];
                    $updated = true;
                }
            }
        }

        if ($updated) {
            update_option('woocommerce_bytenft_payment_gateway_accounts', $accounts);

            $this->logger->info('Account sync updated', [
                'source' => 'bytenft-account-sync'
            ]);
        }
    }
}