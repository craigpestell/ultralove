<?php
/**
 * Plugin Name: WooCommerce Deprecation Bypass
 * Description: Suppress wc_enqueue_js deprecation notice (WooCommerce 10.4+).
 * Version: 1.0
 */

// Set error handler to suppress wc_enqueue_js deprecation errors
// Must stay active throughout the entire request lifecycle
set_error_handler( function ( $errno, $errstr, $errfile = '', $errline = 0, $errcontext = array() ) {
	// Suppress deprecation warnings about wc_enqueue_js
	if ( ( $errno & E_USER_DEPRECATED ) && false !== strpos( $errstr, 'wc_enqueue_js' ) ) {
		// Return true to indicate we handled the error (suppress stderr)
		return true;
	}

	// Let all other errors pass to the default handler
	return false;
} );
