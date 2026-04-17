<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_PaymentEligibilityService
{
    public function __construct(
        private BYTENFT_AccountService $accountService,
        private BYTENFT_ByteNFTApiClient $apiClient,
        private BYTENFT_RateLimitService $rateLimitService,
        private BYTENFT_Logger $logger
    ) {}

    public function evaluate($cart, bool $sandbox): object
    {
        $cart_hash = $cart ? $cart->get_cart_hash() : 'no_cart';

        if (!$cart || empty($cart_hash)) {
            return (object)[
                'isEligible' => true
            ];
        }

        $amount = (float) ($cart->get_total('raw') ?: ($cart->get_totals()['total'] ?? 0));

        $accounts = $this->accountService->getAllAccounts();

        if (empty($accounts)) {
            return (object)[
                'isEligible' => true
            ];
        }

        usort($accounts, fn($a, $b) => ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1));

        $eligible = [];
        $notEligible = [];
        $selected = null;
        $allLimited = true;

        foreach ($accounts as $account) {

            $public = $sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
            $secret = $sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

            if (!$public || !$secret) {
                continue;
            }

            $payload = [
                'is_sandbox'     => $sandbox,
                'amount'         => $amount,
                'api_public_key' => $public,
                'api_secret_key' => $secret,
            ];

            // 1. Merchant Status Check
            $status = $this->apiClient->checkMerchantStatus($payload, $public);

            if (($status['status'] ?? '') !== 'success') {
                $notEligible[] = $account;
                continue;
            }

            // 2. Daily Limit Check
            $limit = $this->apiClient->checkDailyLimit($payload, $public);

            if (($limit['status'] ?? '') === 'success') {
                $eligible[] = $account;
                $allLimited = false;

                if (!$selected) {
                    $selected = $account;
                }
            } else {
                $notEligible[] = $account;
            }
        }

        // fallback logic (Stripe-style deterministic fallback)
        if (!$selected && !$allLimited) {
            $selected = $eligible[0] ?? null;
        }

        $title = $selected['checkout_title'] ?? null;

        return (object)[
            'isEligible'      => $allLimited ? false : true,
            'selectedAccount' => $selected,
            'eligible'        => $eligible,
            'notEligible'     => $notEligible,
            'allLimited'      => $allLimited,
            'title'           => $title
        ];
    }
}