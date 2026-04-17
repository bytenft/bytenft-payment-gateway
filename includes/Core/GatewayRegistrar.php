<?php
class BYTENFT_GatewayRegistrar
{
    public static function register(): void
    {
        add_filter('woocommerce_payment_gateways', function ($methods) {
            $methods[] = BYTENFT_PaymentGateway::class;
            return $methods;
        });
    }
}