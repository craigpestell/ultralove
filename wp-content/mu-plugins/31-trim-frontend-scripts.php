<?php
/**
 * Plugin Name: Trim Front-End Scripts (Perf Fix)
 * Description: Stops loading scripts on pages that never use them:
 *              - PhotoSwipe (the product image lightbox, ~35 KB): Martfury loads it on every singular page,
 *                the home page and other static pages included, but only a product page's own gallery opens
 *                it (the quick view gallery doesn't). Loaded on product pages only, together with the
 *                lightbox markup Martfury prints in the footer (without its stylesheet, that markup showed as
 *                six small empty buttons below the copyright line).
 *              - Contact Form 7 (~25 KB script plus its stylesheet): the site's forms are all Gravity Forms;
 *                CF7 has only its unused default form. Loaded only on a page whose content has a CF7 form.
 *              - WooCommerce's variation form script (~14 KB, plus underscore and wp-util ~20 KB and its HTML
 *                templates): Martfury loads it on every page so Quick View can show a variable product's
 *                options. Loaded only while the store has a published variable product.
 *              - YITH Wishlist's jquery.selectBox and prettyPhoto (~35 KB): only the wishlist page's templates
 *                use them (YITH's script checks for both before calling them). Loaded on the wishlist page only.
 *              - YITH Wishlist's settings (`yith_wcwl_l10n`): YITH's legacy button renderer re-localizes its
 *                script for every wishlist button, so the page carried one ~1.6 KB copy per product card (43
 *                on the home page). Printed once.
 * Version: 1.2.0
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
			remove_action( 'wp_footer', 'martfury_product_images_lightbox' );
		}

		$post = get_post();
		if ( ! ( is_singular() && $post && has_shortcode( $post->post_content, 'contact-form-7' ) ) ) {
			foreach ( array( 'contact-form-7', 'swv' ) as $handle ) {
				wp_dequeue_script( $handle );
			}
			wp_dequeue_style( 'contact-form-7' );
		}

		// Term counts include published products only, and are cached with the term.
		$variable = get_term_by( 'slug', 'variable', 'product_type' );
		if ( ! $variable || ! $variable->count ) {
			wp_dequeue_script( 'wc-add-to-cart-variation' );
		}
	},
	100 // After Martfury (10) and CF7 have enqueued theirs.
);

add_filter(
	'yith_wcwl_main_script_deps',
	function ( $deps ) {
		if ( function_exists( 'yith_wcwl_is_wishlist_page' ) && yith_wcwl_is_wishlist_page() ) {
			return $deps;
		}
		// YITH names the prettyPhoto handle per WooCommerce version (wc-prettyPhoto or prettyPhoto).
		return array_values(
			array_filter(
				$deps,
				function ( $handle ) {
					return 'jquery-selectBox' !== $handle && false === stripos( $handle, 'prettyPhoto' );
				}
			)
		);
	}
);

add_action(
	'wp_footer',
	function () {
		$scripts = wp_scripts();
		foreach ( array( 'jquery-yith-wcwl', 'jquery-yith-wcwl-user' ) as $handle ) {
			$data = $scripts->get_data( $handle, 'data' );
			if ( $data ) {
				// wp_localize_script() adds one line per call.
				$scripts->add_data( $handle, 'data', implode( "\n", array_unique( explode( "\n", $data ) ) ) );
			}
		}
	},
	19 // Before wp_print_footer_scripts (20).
);
