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
	},
	100 // After Martfury has registered it.
);

/**
 * Block styles come back after wp_enqueue_scripts: WooCommerce enqueues its block styles while rendering
 * product shortcodes, and core re-enqueues global styles at wp_footer (priority 1), so they are dequeued
 * again just before the late styles print.
 */
function ultralove_dequeue_unused_block_styles() {
	$post = is_singular() ? get_queried_object() : null; // Not get_post(): product loops can leave it changed.
	if ( $post instanceof WP_Post && has_blocks( $post ) ) {
		return;
	}
	foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'wc-blocks-style', 'classic-theme-styles', 'global-styles' ) as $handle ) {
		wp_dequeue_style( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'ultralove_dequeue_unused_block_styles', 100 ); // After WooCommerce and core.
add_action( 'wp_footer', 'ultralove_dequeue_unused_block_styles', 2 );
