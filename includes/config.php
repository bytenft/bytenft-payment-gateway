<?php
// config.php
if (!defined('BYTENFT_PROTOCOL')) {
    define('BYTENFT_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('BYTENFT_HOST')) {
    // define('BYTENFT_HOST', 'byte-nft.lcl');
    define('BYTENFT_HOST', '127.0.0.1:8000');
}

if (!defined('BYTENFT_BASE_URL')) {
	define('BYTENFT_BASE_URL', BYTENFT_PROTOCOL . BYTENFT_HOST);
}
