<?php
if (!defined('ABSPATH')) exit;

class BYTENFT_PaymentService
{
    public function __construct(
        private BYTENFT_AccountService $accountService,
        private BYTENFT_ValidationService $validationService,
        private BYTENFT_RateLimitService $rateLimitService,
        private BYTENFT_ByteNFTApiClient $apiClient,
        private BYTENFT_OrderPaymentRepository $repo,
        private BYTENFT_PaymentRequestBuilder $builder,
        private WC_Logger $logger
    ) {}

    public function process($order, array $used_accounts = [], bool $sandbox = false)
    {
        $this->validationService->validate($order);

        $this->rateLimitService->check($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        $accounts = $this->accountService->getAccounts();

        if (empty($accounts)) {
            return (object)[
                'success' => false,
                'message' => 'No payment accounts configured'
            ];
        }

        $tried = [];
        $max = count($accounts);
        $attempt = 0;
        $lastError = null;

        while ($attempt < $max) {

            $account = $this->accountService->selectAccount($accounts, array_merge($used_accounts, $tried), $sandbox);

            if (!$account) break;

            $key = $sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

            if (in_array($key, $tried, true)) {
                $attempt++;
                continue;
            }

            $tried[] = $key;

            $payload = $this->builder->build(
                $order,
                $key,
                $sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'],
                $sandbox
            );

            if (!$payload) {
                $attempt++;
                continue;
            }

            $limit = $this->apiClient->checkDailyLimit($payload, $key);

            if (($limit['status'] ?? '') === 'error' && ($limit['code'] ?? '') === 'transaction_limit_exceeded') {
                $attempt++;
                continue;
            }

            if (($limit['status'] ?? '') === 'error') {
                $lastError = $limit['message'] ?? 'Error';
                $attempt++;
                continue;
            }

            $response = $this->apiClient->requestPayment($payload, $key);

            if (is_wp_error($response)) {
                $lastError = 'API request failed';
                $attempt++;
                continue;
            }

            if (($response['status'] ?? '') === 'error') {
                $lastError = $response['message'] ?? 'Payment failed';
                $attempt++;
                continue;
            }

            $this->repo->save($order->get_id(), $response);

            return (object)[
                'success'      => true,
                'redirect_url' => $response['data']['payment_link'] ?? '',
                'account_used' => $key
            ];
        }

        return (object)[
            'success' => false,
            'message' => $lastError ?: 'No available payment accounts'
        ];
    }
}