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

	private static function sanitize_context($data)
	{
		if (!is_array($data)) {
			return $data;
		}

		$clean = [];
		foreach ($data as $key => $value) {
			$normalized_key = strtolower(str_replace(['_', '-'], '', (string) $key));
			if (in_array($normalized_key, [
				'publickey',
				'livepublickey',
				'sandboxpublickey',
				'apipublickey',
				'secretkey',
				'livesecretkey',
				'sandboxsecretkey',
				'apisecretkey',
				'apisecret',
			], true)) {
				continue;
			}

			$clean[$key] = is_array($value) ? self::sanitize_context($value) : $value;
		}

		return $clean;
	}

	private static function format_context($context)
	{
		$entry = [
			'source' => 'bytenft-payment-gateway'
		];

		if (!is_array($context)) {
			return $entry;
		}

		$sanitized = self::sanitize_context($context);

		foreach ($sanitized as $key => $value) {
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