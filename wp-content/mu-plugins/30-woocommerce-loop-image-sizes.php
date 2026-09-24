<?php
/**
 * Plugin Name: WooCommerce Loop Image Sizes Correction
 * Description: Corrects the responsive "sizes" attribute on WooCommerce product loop thumbnails (shop, category/tag archives, search results, and the homepage product grids) to match the Martfury theme's actual grid column widths, instead of WordPress core's default full-viewport-width assumption. Reduces oversized image fetches on Cloudinary-delivered srcset images.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Martfury product grid breakpoints (see wp-content/themes/martfury/css/responsive.scss
 * and bootstrap.min.css): 2 columns below 768px (full-bleed, no fixed container), 3 columns
 * from 768px-1199px (Bootstrap .container width 750-970px), 5 columns at 1200px+
 * (.container width 1170px, ~230px per column after gutters).
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

		return '(max-width: 767px) 50vw, (max-width: 1199px) 33vw, 230px';
	},
	10,
	2
);
