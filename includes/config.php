<?php
// config.php
if (!defined('BYTENFT_PROTOCOL')) {
    define('BYTENFT_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('BYTENFT_HOST')) {
    define('BYTENFT_HOST', 'byte-nft.lcl');
}

if (!defined('BYTENFT_BASE_URL')) {
	define('BYTENFT_BASE_URL', BYTENFT_PROTOCOL . BYTENFT_HOST);
}
