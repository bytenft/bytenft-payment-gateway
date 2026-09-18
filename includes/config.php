<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('VOUCHER_PROTOCOL')) {
    define('VOUCHER_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('VOUCHER_HOST')) {
    define('VOUCHER_HOST', 'pay.bytenft.xyz');
}

if (!defined('VOUCHER_BASE_URL')) {
	define('VOUCHER_BASE_URL', VOUCHER_PROTOCOL . VOUCHER_HOST);
}

if (!defined('VOUCHER_PLUGIN_VERSION')) {
    define('VOUCHER_PLUGIN_VERSION', '1.0.22');
}

