<?php
class BYTENFT_RestRegistrar
{
    public static function register(): void
    {
        $api = BYTENFT_PaymentGateway_REST_API::get_instance();
        $api->bytenft_register_routes();
    }
}