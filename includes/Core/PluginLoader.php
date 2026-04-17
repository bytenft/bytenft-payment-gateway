<?php
class BYTENFT_PluginLoader
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function init(): void
    {
        add_action('plugins_loaded', [$this, 'boot'], 10);
    }

    public function boot(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        BYTENFT_GatewayRegistrar::register();
        BYTENFT_RestRegistrar::register();
        BYTENFT_AjaxRegistrar::register();
        BYTENFT_CronRegistrar::register();
        BYTENFT_BlocksRegistrar::register();
        BYTENFT_AssetsRegistrar::register();
    }
}