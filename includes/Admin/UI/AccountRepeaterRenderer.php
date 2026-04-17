<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_AccountRepeaterRenderer
{
    public function __construct(
        private string $gateway_id
    ) {}

    public function render(string $key, array $data): string
    {
        $option_value   = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
        $active_account = get_option('bytenft_active_account', 0);

        $global_settings = get_option('woocommerce_bytenft_settings', []);
        $global_settings = maybe_unserialize($global_settings);

        $sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

        $option_value = $this->ensureUniqueIds($option_value);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>

            <td class="forminp">

                <div class="bytenft-accounts-container">

                    <?php if (!empty($option_value)): ?>
                        <div class="bytenft-sync-account">
                            <button type="button" class="button" id="bytenft-sync-accounts">
                                Sync Accounts
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($option_value)): ?>

                        <div class="empty-account">
                            <?php esc_html_e('No accounts available. Please add one.', 'bytenft-payment-gateway'); ?>
                        </div>

                    <?php else: ?>

                        <?php foreach (array_values($option_value) as $index => $account): ?>

                            <?php $this->renderAccount($account, $index, $sandbox_enabled); ?>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    <?php wp_nonce_field('bytenft_accounts_nonce_action', 'bytenft_accounts_nonce'); ?>

                    <div class="add-account-btn">
                        <button type="button" class="button bytenft-add-account">
                            + Add Account
                        </button>
                    </div>

                </div>

            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    private function renderAccount(array $account, int $index, bool $sandbox_enabled): void
    {
        $live_status    = $account['live_status'] ?? '';
        $sandbox_status = $account['sandbox_status'] ?? '';
        $unique_id      = $account['unique_id'] ?? '';

        ?>
        <div class="bytenft-account" data-index="<?php echo esc_attr($index); ?>">

            <input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]"
                   value="<?php echo esc_attr($live_status); ?>">

            <input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]"
                   value="<?php echo esc_attr($sandbox_status); ?>">

            <h4>
                <?php echo esc_html($account['title'] ?: 'Untitled Account'); ?>
            </h4>

            <input type="hidden"
                   name="accounts[<?php echo esc_attr($index); ?>][unique_id]"
                   value="<?php echo esc_attr($unique_id); ?>">

            <input type="text"
                   name="accounts[<?php echo esc_attr($index); ?>][title]"
                   value="<?php echo esc_attr($account['title'] ?? ''); ?>">

            <input type="number"
                   name="accounts[<?php echo esc_attr($index); ?>][priority]"
                   value="<?php echo esc_attr($account['priority'] ?? 1); ?>">

            <input type="text"
                   name="accounts[<?php echo esc_attr($index); ?>][live_public_key]"
                   value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">

            <input type="text"
                   name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]"
                   value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">

        </div>
        <?php
    }

    private function ensureUniqueIds(array $accounts): array
    {
        $updated = false;

        foreach ($accounts as &$account) {
            if (empty($account['unique_id'])) {
                $account['unique_id'] = uniqid('bnft_', true);
                $updated = true;
            }

            $account['checkout_title'] ??= '';
            $account['checkout_subtitle'] ??= '';
        }

        if ($updated) {
            update_option('woocommerce_bytenft_payment_gateway_accounts', $accounts);
        }

        return $accounts;
    }
}