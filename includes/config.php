<?php
// config.php

// Determine SIP protocol based on the site's protocol
define('BNFT_PROTOCOL', is_ssl() ? 'https://' : 'http://');
define('BNFT_HOST','www.bytenft.xyz');
