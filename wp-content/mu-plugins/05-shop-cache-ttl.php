<?php
/**
 * Plugin Name: Shop Cache TTL
 * Description: Sends a 4-hour Cache-Control TTL on the /shop/ (WooCommerce shop archive) endpoint.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ULTRALOVE_SHOP_CACHE_TTL = 4 * HOUR_IN_SECONDS;

/**
 * Add cache TTL headers to the shop page for anonymous visitors.
 */
function ultralove_shop_cache_headers() {
	if ( is_admin() || is_user_logged_in() || ! function_exists( 'is_shop' ) || ! is_shop() ) {
		return;
	}

	// Keep carts, checkout flows, and personalised responses uncached.
	if ( ! empty( $_GET ) && count( array_diff( array_keys( $_GET ), array( 'paged' ) ) ) ) {
		return;
	}

	$ttl = ULTRALOVE_SHOP_CACHE_TTL;
	header_remove( 'Cache-Control' );
	header_remove( 'Expires' );
	header_remove( 'Pragma' );
	header( 'Cache-Control: public, max-age=' . $ttl . ', s-maxage=' . $ttl );
	header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $ttl ) . ' GMT' );
}
// Late priority so this overrides headers set by WordPress/WooCommerce nocache logic.
add_action( 'send_headers', 'ultralove_shop_cache_headers', PHP_INT_MAX );
