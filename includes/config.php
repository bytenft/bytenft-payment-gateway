<?php
// config.php

// Determine SIP protocol based on the site's protocol
define('BNP_PROTOCOL', is_ssl() ? 'https://' : 'http://');
define('BNP_HOST', 'qa.bytenft.xyz');
