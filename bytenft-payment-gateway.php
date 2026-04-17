<?php

/**
 * Plugin Name: ByteNFT Payment Gateway
 * Description: Use a Credit Card, Debit Card or Google Pay, Apple Pay to complete your purchase via USDC. The transaction will appear on your bank or card statement as *ByteNFT.
 * Author: ByteNFT
 * Author URI: https://pay.bytenft.xyz/
 * Text Domain: bytenft-payment-gateway
 * Plugin URI: https://github.com/bytenft/bytenft-payment-gateway
 * Version: 1.0.13
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) exit;

define('BYTENFT_FILE', __FILE__);
define('BYTENFT_DIR', plugin_dir_path(__FILE__));

/**
 * =========================
 * AUTOLOADER
 * =========================
 */
spl_autoload_register(function ($class) {

    $prefix   = 'BYTENFT\\';
    $base_dir = BYTENFT_DIR . 'includes/';

    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * =========================
 * BOOTSTRAP PLUGIN
 * =========================
 */
add_action('plugins_loaded', function () {
    if (class_exists(\BYTENFT\Core\Plugin::class)) {
        \BYTENFT\Core\Plugin::instance()->init();
    }
});

/**
 * =========================
 * PLUGIN ACTION LINKS (THIS FIXES SETTINGS LINK)
 * =========================
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {

    $settings_link = '<a href="' . esc_url(
        admin_url('admin.php?page=wc-settings&tab=checkout&section=bytenft')
    ) . '">' . esc_html__('Settings', 'bytenft-payment-gateway') . '</a>';

    array_unshift($links, $settings_link);

    return $links;
});