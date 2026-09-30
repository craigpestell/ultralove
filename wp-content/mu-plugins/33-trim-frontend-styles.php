<?php
/**
 * Plugin Name: Trim Front-End Styles (Perf Fix)
 * Description: Stops loading stylesheets on pages that never use them (each one blocks rendering):
 *              - Google Fonts (Work Sans + Libre Baskerville, a render-blocking request to another origin):
 *                the child theme self-hosts every Work Sans weight the site uses (see the top of its
 *                style.css) and nothing uses Libre Baskerville. Not loaded at all.
 *              - Block editor styles (WordPress's block library ~120 KB, WooCommerce blocks ~14 KB, and their
 *                global-styles / classic-theme inline CSS): the theme, widgets and shortcode pages have no
 *                blocks - only pages written in the block editor do (cart, checkout, my account, ...).
 *                Loaded only on a page whose content has blocks.
 *              Everything that's left is combined into one file by Autoptimize (CSS aggregation, inline CSS
 *              included so the cascade order stays the same).
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		// Martfury's stylesheet depends on this handle, so it stays registered, just without a file.
		$styles = wp_styles();
		if ( isset( $styles->registered['martfury-fonts'] ) ) {
			$styles->registered['martfury-fonts']->src = false;
		}

		$post = get_post();
		if ( ! ( is_singular() && $post && has_blocks( $post ) ) ) {
			foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'wc-blocks-style', 'classic-theme-styles', 'global-styles' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}
	},
	100 // After Martfury, WooCommerce and core have enqueued theirs.
);
