<?php
// config.php

// Determine SIP protocol based on the site's protocol
define('SIP_PROTOCOL', is_ssl() ? 'https://' : 'http://');
define('SIP_HOST', 'www.bytenft.xyz');
