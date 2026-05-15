<?php

if (!defined('ABSPATH')) {
	exit;
}

class ByteNFT_Payment_Gateway_Logger {

	/**
	 * Central logging utility for ByteNFT Gateway
	 */
	public static function log($message, $context = [])
	{
		if (!function_exists('wc_get_logger')) {
			return;
		}

		$logger = wc_get_logger();

		$entry = [
			'source' => 'bytenft-payment-gateway'
		];

		if (!empty($context)) {
			foreach ($context as $key => $value) {
				$entry[$key] = is_scalar($value)
					? $value
					: wp_json_encode($value);
			}
		}

		$logger->info($message, $entry);
	}
}