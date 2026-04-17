<?php

if (!defined('ABSPATH')) {
	exit;
}

class BYTENFT_LoggerFactory
{
	/**
	 * Get WooCommerce logger instance with dynamic daily channel
	 */
	public static function getLogger(): WC_Logger
	{
		return wc_get_logger();
	}

	/**
	 * Build context with consistent source naming
	 */
	public static function context(): array
	{
		return [
			'source' => 'bytenft-payment-gateway-' . date('Y-m-d')
		];
	}

	/**
	 * Info log
	 */
	public static function info(string $message, array $context = []): void
	{
		self::getLogger()->info(
			$message,
			array_merge(self::context(), $context)
		);
	}

	/**
	 * Warning log
	 */
	public static function warning(string $message, array $context = []): void
	{
		self::getLogger()->warning(
			$message,
			array_merge(self::context(), $context)
		);
	}

	/**
	 * Error log
	 */
	public static function error(string $message, array $context = []): void
	{
		self::getLogger()->error(
			$message,
			array_merge(self::context(), $context)
		);
	}
}