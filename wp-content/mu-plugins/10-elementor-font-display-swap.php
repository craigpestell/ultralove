<?php
/**
 * Plugin Name: Elementor Font Display Swap Override
 * Description: Forces a safe default font-display for Elementor Google Fonts and appends display=swap to Elementor Roboto stylesheet URLs when missing.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change Elementor's default font display from auto to swap without overriding a saved setting.
 */
add_filter(
	'default_option_elementor_font_display',
	static function ( $default_value ) {
		return 'swap';
	}
);

/**
 * Ensure Elementor's Roboto Google Fonts URL includes display=swap when enqueued.
 */
add_filter(
	'style_loader_src',
	static function ( $src, $handle ) {
		if ( 'google-font-roboto' !== $handle ) {
			return $src;
		}

		$parts = wp_parse_url( $src );
		if ( empty( $parts['host'] ) || false === strpos( $parts['host'], 'fonts.googleapis.com' ) ) {
			return $src;
		}

		$query_args = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_args );
		}

		if ( ! empty( $query_args['display'] ) ) {
			return $src;
		}

		return add_query_arg( 'display', 'swap', $src );
	},
	20,
	2
);
