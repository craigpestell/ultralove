<?php
/**
 * Plugin Name: RevSlider Cloudinary Optimization (Perf Fix)
 * Description: RevSlider slide backgrounds hosted on Cloudinary are stored as raw, untransformed
 *              URLs - plain JPEGs up to ~1.6MB each, with no format/quality optimization applied.
 *              RevSlider is embedded through multiple different code paths on this site (a direct
 *              shortcode call in the header template, and Elementor shortcode widgets elsewhere),
 *              which don't all flow through the same internal RevSlider methods, so the fix hooks
 *              WordPress's own do_shortcode_tag filter instead - it wraps every [rev_slider]
 *              invocation's rendered HTML regardless of which internal path produced it. Any raw
 *              Cloudinary URL found in that HTML gets Cloudinary's q_auto,f_auto transformation
 *              injected, cutting per-image size by roughly 50-90% (Cloudinary auto-picks WebP/AVIF
 *              per browser and tunes JPEG quality) with no visible quality loss and no change to
 *              displayed dimensions. RevSlider's stored slide data in the database is untouched.
 *              Runs at priority 30: the child theme's ultralove_rewrite_revslider_to_cloudinary()
 *              (functions.php, priority 20) rewrites some slides' local wp-content/uploads URLs to
 *              Cloudinary ones at render time - this must run after that, or it never sees the
 *              Cloudinary URL to optimize.
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects Cloudinary's automatic quality + format transformation into raw, untransformed
 * Cloudinary delivery URLs found anywhere in the given HTML. Only matches URLs where a version
 * segment (vNNNNNNN) immediately follows the delivery-type segment ("image/upload" or "images"),
 * since that's how a raw, not-yet-transformed Cloudinary URL looks - this avoids
 * double-transforming a URL that already carries one.
 */
function copilot_optimize_cloudinary_urls_in_html( $html ) {
	if ( empty( $html ) || ! is_string( $html ) || false === strpos( $html, 'res.cloudinary.com' ) ) {
		return $html;
	}

	return preg_replace(
		'#(res\.cloudinary\.com/[^/"\']+/(?:image/upload|images))/(v\d+/)#',
		'$1/q_auto,f_auto/$2',
		$html
	);
}

add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( 'rev_slider' !== $tag ) {
			return $output;
		}

		return copilot_optimize_cloudinary_urls_in_html( $output );
	},
	30,
	2
);
