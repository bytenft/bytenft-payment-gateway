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

	private static function mask_secrets($data) {
		if (is_string($data)) {
			// Mask if it looks like a JSON string that contains secret keys? No, better just handle arrays.
			return $data;
		}

		if (is_array($data) || is_object($data)) {
			$array_data = (array) $data;
			foreach ($array_data as $key => $value) {
				if (is_string($key) && preg_match('/secret_key|api_secret/i', $key)) {
					$array_data[$key] = '*** MASKED ***';
				} else if (is_array($value) || is_object($value)) {
					$array_data[$key] = self::mask_secrets($value);
				}
			}
			return is_object($data) ? (object) $array_data : $array_data;
		}

		return $data;
	}

	private static function format_context($context)
	{
		$entry = [
			'source' => 'bytenft-payment-gateway'
		];

		if (!is_array($context)) {
			return $entry;
		}

		$masked_context = self::mask_secrets($context);

		foreach ($masked_context as $key => $value) {
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