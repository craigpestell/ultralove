<?php
/**
 * Plugin Name: Trim Front-End Scripts (Perf Fix)
 * Description: Stops loading scripts on pages that never use them:
 *              - PhotoSwipe (the product image lightbox, ~35 KB): Martfury loads it on every singular page,
 *                the home page and other static pages included, but only a product page's own gallery opens
 *                it (the quick view gallery doesn't). Loaded on product pages only.
 *              - Contact Form 7 (~25 KB script plus its stylesheet): the site's forms are all Gravity Forms;
 *                CF7 has only its unused default form. Loaded only on a page whose content has a CF7 form.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			foreach ( array( 'photoswipe-ui', 'photoswipe', 'wc-photoswipe', 'wc-photoswipe-ui-default' ) as $handle ) {
				wp_dequeue_script( $handle );
			}
			foreach ( array( 'photoswipe', 'photoswipe-default-skin' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
		}

		$post = get_post();
		if ( ! ( is_singular() && $post && has_shortcode( $post->post_content, 'contact-form-7' ) ) ) {
			foreach ( array( 'contact-form-7', 'swv' ) as $handle ) {
				wp_dequeue_script( $handle );
			}
			wp_dequeue_style( 'contact-form-7' );
		}
	},
	100 // After Martfury (10) and CF7 have enqueued theirs.
);
