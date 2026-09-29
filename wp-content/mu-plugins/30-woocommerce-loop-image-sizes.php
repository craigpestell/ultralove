<?php
/**
 * Plugin Name: WooCommerce Loop Image Sizes Correction
 * Description: Corrects the responsive "sizes" attribute on WooCommerce product loop thumbnails (shop, category/tag archives, search results, and the homepage product grids) to match the Martfury theme's actual grid column widths, instead of WordPress core's default full-viewport-width assumption. Reduces oversized image fetches on Cloudinary-delivered srcset images.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product card widths (see "Product grids and carousels - one sizing rule" in the child theme's
 * style.css): every grid and carousel shows as many cards as fit its container at 215px or more, never
 * fewer than 2 or more than 5, in a page container of at most 1440px - so cards are half the width below
 * 576px and at most ~320px wide elsewhere (2-up tablets; ~215-290px on desktops, up to ~288px on large
 * screens).
 */
add_filter(
	'wp_calculate_image_sizes',
	static function ( $sizes, $size ) {
		$is_grid_context = is_shop() || is_product_taxonomy() || is_search() || is_front_page() || is_home();

		if ( ! $is_grid_context ) {
			return $sizes;
		}

		// Only override for images requested at typical grid-thumbnail widths; leave large/full
		// requests (e.g. a single product's main gallery image, if ever rendered in this context)
		// untouched.
		$width = is_array( $size ) ? (int) ( $size[0] ?? 0 ) : 0;
		if ( $width > 0 && $width < 700 ) {
			return $sizes;
		}

		return '(max-width: 575px) 50vw, (max-width: 1023px) 320px, 290px';
	},
	10,
	2
);

/**
 * Home page: lazy-load the product card images. The home page renders 42 product cards (the "On Sale"
 * carousel and "Latest Adult Products"), all below the banner and the call to action on a phone, and
 * WordPress adds no loading="lazy" to images outside post content here - so all 42 (~1 MB) were fetched
 * at once, competing with the banner (the page's largest contentful paint) for a phone's bandwidth.
 * Banner slides (.ub-banner-img) set their own loading and are left alone. Not applied on shop and
 * archive pages, where the first row of cards can be the largest contentful paint.
 */
add_filter(
	'wp_get_attachment_image_attributes',
	static function ( $attr ) {
		if ( ! is_front_page() || ( isset( $attr['class'] ) && false !== strpos( $attr['class'], 'ub-banner-img' ) ) ) {
			return $attr;
		}
		$attr['loading'] = 'lazy';
		unset( $attr['fetchpriority'] );

		return $attr;
	}
);
