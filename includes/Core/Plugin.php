<?php

namespace BYTENFT\Core;

if (!defined('ABSPATH')) {
    exit;
}

use BYTENFT\Http\Ajax\PaymentAjaxController;
use BYTENFT\Http\Rest\RestRegistrar;
use BYTENFT\Jobs\AccountSyncJob;
use BYTENFT\Gateway\BYTENFT_Blocks_Gateway;
use BYTENFT\Gateway\PaymentGateway;

class Plugin
{
    private static $instance = null;

    public static function instance()
    {
        return self::$instance ??= new self();
    }

    public function init()
    {
        if (!class_exists(PaymentAjaxController::class)) {
            return;
        }

        (new PaymentAjaxController())->register();

        if (class_exists(AccountSyncJob::class)) {
            (new AccountSyncJob(defined('BYTENFT_BASE_URL') ? BYTENFT_BASE_URL : ''))->register();
        }

        if (class_exists(RestRegistrar::class)) {
            (new RestRegistrar())->register();
        }

        add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
            if (class_exists(BYTENFT_Blocks_Gateway::class)) {
                $registry->register(new BYTENFT_Blocks_Gateway());
            }
        });

        add_filter('woocommerce_payment_gateways', function ($methods) {
            if (class_exists(PaymentGateway::class)) {
                $methods[] = PaymentGateway::class;
            }
            return $methods;
        });
    }
}