<?php

if (!defined('ABSPATH')) {
	exit;
}

class ByteNFT_Payment_Gateway_Logger {

	private static function get_logger()
	{
		if (!function_exists('wc_get_logger')) {
			return null;
		}

		return wc_get_logger();
	}

	private static function format_context($context)
	{
		$entry = [
			'source' => 'bytenft-payment-gateway'
		];

		if (!is_array($context)) {
			return $entry;
		}

		foreach ($context as $key => $value) {
			$entry[$key] = is_scalar($value)
				? $value
				: wp_json_encode($value);
		}

		return $entry;
	}

	public static function info($message, $context = [])
	{
		$logger = self::get_logger();
		if (!$logger) return;

		$logger->info($message, self::format_context($context));
	}

	public static function warning($message, $context = [])
	{
		$logger = self::get_logger();
		if (!$logger) return;

		$logger->warning($message, self::format_context($context));
	}

	public static function error($message, $context = [])
	{
		$logger = self::get_logger();
		if (!$logger) return;

		$logger->error($message, self::format_context($context));
	}
}