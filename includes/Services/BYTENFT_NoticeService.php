<?php

if (!defined('ABSPATH')) {
    exit;
}

class BYTENFT_NoticeService
{
    /**
     * Add notice (Stripe-style centralized storage)
     */
    public static function add($key, $type, $message)
    {
        $key     = sanitize_key($key);
        $type    = sanitize_text_field($type);
        $message = sanitize_text_field($message);

        $notices = self::get_all();

        // prevent duplicates (Stripe behavior)
        if (isset($notices[$key])) {
            return;
        }

        $notices[$key] = [
            'type'    => $type,
            'message' => $message,
        ];

        set_transient(self::storage_key(), $notices, 300);
    }

    /**
     * Remove notice
     */
    public static function remove($key)
    {
        $key     = sanitize_key($key);
        $notices = self::get_all();

        unset($notices[$key]);

        set_transient(self::storage_key(), $notices, 300);
    }

    /**
     * Get all notices
     */
    public static function get_all()
    {
        $notices = get_transient(self::storage_key());

        return is_array($notices) ? $notices : [];
    }

    /**
     * Render notices (UI layer only)
     */
    public static function render()
    {
        $notices = self::get_all();

        foreach ($notices as $notice) {

            $type    = esc_attr($notice['type'] ?? 'info');
            $message = esc_html($notice['message'] ?? '');

            echo "<div class='bytenft-notice {$type}'><p>{$message}</p></div>";
        }

        // clear after render (Stripe-like flash behavior)
        delete_transient(self::storage_key());
    }

    /**
     * Storage key (isolated per site)
     */
    private static function storage_key()
    {
        return 'bytenft_admin_notices';
    }
}