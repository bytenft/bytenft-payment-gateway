<?php

namespace BYTENFT\Gateway;

use WC_Payment_Gateway_CC;
use BYTENFT\Services\PaymentService;
use BYTENFT\Services\AccountService;
use BYTENFT\Services\ValidationService;
use BYTENFT\Services\RateLimitService;
use BYTENFT\Api\ByteNFTApiClient;
use BYTENFT\Repository\OrderPaymentRepository;
use BYTENFT\Logger\LoggerFactory;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentGateway extends WC_Payment_Gateway_CC
{
    private PaymentService $paymentService;
    private bool $sandbox = false;

    public function __construct()
    {
        if (!class_exists(WC_Payment_Gateway_CC::class)) {
            return;
        }

        parent::__construct();

        $this->id                 = 'bytenft';
        $this->icon               = '';
        $this->has_fields         = true;
        $this->method_title       = __('ByteNFT Payment Gateway', 'bytenft-payment-gateway');
        $this->method_description = __('Secure crypto payment gateway', 'bytenft-payment-gateway');

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );

        /**
         * ✅ SETTINGS LINK (Plugins page)
         */
        add_filter(
            'plugin_action_links_' . plugin_basename(BYTENFT_FILE),
            [$this, 'plugin_action_links']
        );

        /**
         * ✅ ROW META (Docs + Support)
         */
        add_filter(
            'plugin_row_meta',
            [$this, 'plugin_row_meta'],
            10,
            2
        );

        $this->register_hooks();

        $this->paymentService = new PaymentService(
            new AccountService(),
            new ValidationService(),
            new RateLimitService(),
            new ByteNFTApiClient(defined('BYTENFT_BASE_URL') ? BYTENFT_BASE_URL : ''),
            new OrderPaymentRepository(),
            LoggerFactory::getLogger(),
            LoggerFactory::context()
        );
    }

    /**
     * SETTINGS LINK (Plugins page)
     */
    public function plugin_action_links($links)
    {
        $settings_link = [
            '<a href="' . esc_url(
                admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id)
            ) . '">' . esc_html__('Settings', 'bytenft-payment-gateway') . '</a>',
        ];

        return array_merge($settings_link, $links);
    }

    /**
     * ROW META (Docs + Support)
     */
    public function plugin_row_meta($links, $file)
    {
        if (plugin_basename(BYTENFT_FILE) !== $file) {
            return $links;
        }

        $row_meta = [
            'docs' => '<a href="' . esc_url(
                apply_filters('bytenft_docs_url', 'https://pay.bytenft.xyz/api/docs/wordpress-plugin')
            ) . '" target="_blank">' . esc_html__('Documentation', 'bytenft-payment-gateway') . '</a>',

            'support' => '<a href="' . esc_url(
                apply_filters('bytenft_support_url', 'https://pay.bytenft.xyz/reach-out')
            ) . '" target="_blank">' . esc_html__('Support', 'bytenft-payment-gateway') . '</a>',
        ];

        return array_merge($links, $row_meta);
    }

    private function load_settings()
    {
        $this->enabled = $this->get_option('enabled');
        $this->sandbox = $this->get_option('sandbox') === 'yes';
    }

    private function register_hooks()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
    }

    public function process_payment($order_id, $used_accounts = [])
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return ['result' => 'fail'];
        }

        $result = $this->paymentService->process(
            $order,
            $used_accounts,
            $this->sandbox
        );

        if (!$result->success) {
            wc_add_notice($result->message ?? 'Payment failed', 'error');
            return ['result' => 'fail'];
        }

        return [
            'result'   => 'success',
            'redirect' => $result->redirect_url
        ];
    }
}