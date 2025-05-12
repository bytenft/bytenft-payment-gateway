<?php

/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function bytenft_check_system_requirements()
{
	if (version_compare(phpversion(), BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('The Byte NFT Payment Gateway plugin requires PHP version %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
			BYTENFT_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('The Byte NFT Payment Gateway plugin requires WooCommerce database version %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
			BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('undefined', 'bytenft-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('The Byte NFT Payment Gateway plugin requires WooCommerce plugin version %1$s or greater. You are running %2$s.', 'bytenft-payment-gateway'),
			BYTENFT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('undefined', 'bytenft-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version and database version are different
	if ($wc_plugin_version && $wc_db_version && $wc_plugin_version !== $wc_db_version) {
		return sprintf(
			// translators: %1$s is the WooCommerce plugin version, %2$s is the WooCommerce database version
			__('Warning: The WooCommerce plugin version (%1$s) and database version (%2$s) do not match. Please ensure both are synchronized.', 'bytenft-payment-gateway'),
			$wc_plugin_version,
			$wc_db_version
		);
	}

	return false;
}

/**
 * Activation check for the plugin.
 */
function bytenft_activation_check()
{
	$environment_warning = bytenft_check_system_requirements();
	if ($environment_warning) {
		deactivate_plugins(plugin_basename(BYTENFT_PAYMENT_GATEWAY_FILE));
		wp_die(esc_html($environment_warning)); // Escape the output before calling wp_die
	}
}

function bytenft_clear_all_caches() {
    global $wpdb;

    $logger = wc_get_logger();
    $log_source = 'bytenft-payment-gateway';

    $logger->info("===== [BYTENFT] CACHE LOGGING START =====", ['source' => $log_source]);

    // === 1. TRY TO GET `bytenft` TRANSIENTS FROM CACHE FIRST ===
    $bytenft_transients = wp_cache_get('bytenft_transients', 'myplugin');

    if ( false === $bytenft_transients ) {
        // Direct query, if cache is empty
        $bytenft_transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bytenft%' 
             AND option_name NOT LIKE '_transient_timeout_%'"
        );

        // Cache the result for future use
        wp_cache_set('bytenft_transients', $bytenft_transients, 'myplugin', 3600); // Cache for 1 hour
    }

    // Log the `bytenft` transients
    foreach ($bytenft_transients as $option_name) {
        $transient_key = str_replace('_transient_', '', $option_name);
        $value = get_transient($transient_key);

        // Log transient value based on its data type
        if (is_array($value) || is_object($value)) {
            $logger->info("[Transient] {$transient_key} => Array/Object data", ['source' => $log_source]);
        } else {
            $logger->info("[Transient] {$transient_key} => {$value}", ['source' => $log_source]);
        }

        // Log timeout if exists
        $timeout_key = str_replace('_transient_', '_transient_timeout_', $option_name);
        $timeout = get_option($timeout_key);
        if ($timeout !== false) {
            $logger->info("[Transient Timeout] {$timeout_key} => {$timeout}", ['source' => $log_source]);
        }
    }

    // === 2. DELETE `bytenft` TRANSIENTS ===
    foreach ($bytenft_transients as $option_name) {
        $transient_key = str_replace('_transient_', '', $option_name);
        
        // Delete the transient and its timeout
        delete_transient($transient_key);
        
        // Delete the timeout record as well
        $timeout_key = str_replace('_transient_', '_transient_timeout_', $option_name);
        delete_option($timeout_key);

        // Clear any cached transient data
        wp_cache_delete('bytenft_transients', 'myplugin');
        
        $logger->info("Deleted Transient and Timeout: {$transient_key}", ['source' => $log_source]);
    }

    // === 3. POST-DELETE: Check if any `bytenft` transients remain ===
    $remaining_bytenft_transients = wp_cache_get('bytenft_transients', 'myplugin');
    if ( false === $remaining_bytenft_transients ) {
        // Direct query
        $remaining_bytenft_transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bytenft%' 
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
    
        // Cache the result
        wp_cache_set('bytenft_transients', $remaining_bytenft_transients, 'myplugin', 3600); // Cache for 1 hour
    }
    
    if (empty($remaining_bytenft_transients)) {
        $logger->info("No remaining BYTENFT transients found after deletion.", ['source' => $log_source]);
    } else {
        foreach ($remaining_bytenft_transients as $option_name) {
            $logger->warning(" Remaining Transient: {$option_name}", ['source' => $log_source]);
        }
    }

    $logger->info("===== [BYTENFT] CACHE CLEAR COMPLETE =====", ['source' => $log_source]);
}




