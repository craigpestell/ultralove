<?php
/**
 * Plugin Name: Critical CSS (Perf Fix)
 * Description: Inlines the CSS the first screen needs and loads the full stylesheet without blocking rendering,
 *              through Autoptimize's "inline and defer CSS": the combined stylesheet is fetched as media="print"
 *              and switched to "all" once loaded (a <noscript> link covers browsers without JavaScript).
 *              The critical CSS is the child theme's css/critical.css, generated from dev by ops/critical-css
 *              (a GitHub Action regenerates it and opens a PR when it changes) - see ops/critical-css/README.md.
 *              No file (or an empty one) means pages load CSS exactly as before. `?ul_critical_css=off` shows a
 *              page without it (the generator uses this). Pages Autoptimize leaves alone (cart, checkout, my
 *              account) keep their normal stylesheets.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ultralove_critical_css() {
	static $css = null;
	if ( null !== $css ) {
		return $css;
	}
	$css = '';
	if ( isset( $_GET['ul_critical_css'] ) && 'off' === $_GET['ul_critical_css'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $css;
	}
	$file = get_stylesheet_directory() . '/css/critical.css';
	if ( is_readable( $file ) ) {
		$css = trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
	return $css;
}

add_filter(
	'autoptimize_filter_css_defer',
	function ( $defer ) {
		return '' !== ultralove_critical_css() ? true : $defer;
	}
);

add_filter(
	'autoptimize_filter_css_defer_inline',
	function ( $inline ) {
		$css = ultralove_critical_css();
		return '' !== $css ? $css : $inline;
	}
);
