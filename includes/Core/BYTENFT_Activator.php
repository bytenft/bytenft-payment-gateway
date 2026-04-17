<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_Activator
{
    public static function activate()
    {
        $service = new BYTENFT_EnvironmentService();
        $result  = $service->check();

        if (!$result['valid']) {
            deactivate_plugins(plugin_basename(BYTENFT_PAYMENT_GATEWAY_FILE));

            wp_die(esc_html($result['message']));
        }
    }
}