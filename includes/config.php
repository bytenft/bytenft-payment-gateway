<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('BYTENFT_PROTOCOL')) {
    define('BYTENFT_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('BYTENFT_HOST')) {
    define('BYTENFT_HOST', 'pay.bytenft.xyz');
}

if (!defined('BYTENFT_BASE_URL')) {
	define('BYTENFT_BASE_URL', BYTENFT_PROTOCOL . BYTENFT_HOST);
}
