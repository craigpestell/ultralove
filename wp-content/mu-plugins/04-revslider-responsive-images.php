<?php
/**
 * Plugin Name: RevSlider Responsive Image Sizing (Perf Fix)
 * Description: RevSlider always requests each slide's full source-resolution Cloudinary image
 *              (often 1400-2100px wide) even when the slider itself renders much smaller - e.g.
 *              the two homepage hero sliders sit side-by-side in a 2-column layout and only render
 *              at roughly half the viewport width on desktop, yet still load full-width images.
 *              RevSlider has no public hook for "this slide is about to load", and its own lazy
 *              loader is an obfuscated internal canvas-based renderer, so this doesn't try to hook
 *              that timing. Instead, right after each [rev_slider] shortcode's own HTML, it injects
 *              a small inline script that measures that slider's actual rendered container width
 *              (which RevSlider itself already sizes correctly for its layout) and rewrites that
 *              slider's slide image URLs to request a matching Cloudinary width (times the device
 *              pixel ratio) before RevSlider's own script - loaded later, in the footer - starts
 *              reading those URLs. The script identifies its target by the slider's own wrapper
 *              element id (extracted server-side and embedded literally, e.g.
 *              getElementById('rev_slider_1_3_wrapper')) rather than any DOM-position trick such as
 *              "the script immediately before me" or "the last script tag on the page so far" -
 *              Autoptimize (active on this site) converts inline scripts to deferred data: URIs,
 *              which preserves their content exactly but changes their execution order relative to
 *              other scripts, breaking any positional assumption. An id lookup is unaffected by that.
 *              The actual measurement is deferred to DOMContentLoaded, since measuring immediately -
 *              before the page's CSS layout (columns, flexbox) has settled - under-measures the
 *              container and would request an image smaller than actually needed. Deferring further,
 *              to the window "load" event, measures more accurately but risks losing the race against
 *              RevSlider's own fetch (which can trigger as early as DOMContentLoaded) and serving the
 *              original oversized image instead - so this stays on DOMContentLoaded and instead adds
 *              a 20% safety margin to the computed width to absorb the layout that's still settling
 *              (measured empirically: Elementor's column width keeps adjusting a bit after
 *              DOMContentLoaded) without risking visible blur. Runs once per page load; does not
 *              re-measure on window resize.
 *              Some sliders on this site render as two DOM instances (e.g. a desktop header version
 *              and a hidden off-canvas mobile-menu version of the same slider) and RevSlider fetches
 *              both regardless of which one is actually visible. The hidden instance measures at
 *              (near) zero width, so rather than skip it and leave it fully uncapped - the original
 *              bug this fixes - it falls back to the viewport width as a reasonable upper-bound
 *              estimate of what it would render at if it became visible.
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( 'rev_slider' !== $tag || empty( $output ) || ! is_string( $output ) ) {
			return $output;
		}

		if ( false === strpos( $output, 'res.cloudinary.com' ) ) {
			return $output;
		}

		if ( ! preg_match( '/id="(rev_slider_[a-zA-Z0-9_]+_wrapper)"/', $output, $matches ) ) {
			return $output;
		}

		return $output . copilot_revslider_responsive_script( $matches[1] );
	},
	40,
	2
);

function copilot_revslider_responsive_script( $wrapper_id ) {
	ob_start();
	?>
	<script>
	( function () {
		var container = document.getElementById( <?php echo wp_json_encode( $wrapper_id ); ?> );
		if ( ! container ) {
			return;
		}

		function resize() {
			var rect = container.getBoundingClientRect();
			var measuredWidth = rect.width >= 50 ? rect.width : window.innerWidth;

			var dpr = window.devicePixelRatio || 1;
			var targetWidth = Math.min( 2400, Math.max( 400, Math.ceil( ( measuredWidth * dpr * 1.2 ) / 100 ) * 100 ) );

			var images = container.querySelectorAll( 'img.rev-slidebg[data-lazyload]' );
			for ( var i = 0; i < images.length; i++ ) {
				var url = images[ i ].getAttribute( 'data-lazyload' );
				if ( ! url || url.indexOf( 'res.cloudinary.com' ) === -1 ) {
					continue;
				}

				var rewritten = url.replace(
					/(res\.cloudinary\.com\/[^\/]+\/(?:image\/upload|images)\/)(?:w_\d+,c_limit,)?(q_auto,f_auto\/)/,
					'$1w_' + targetWidth + ',c_limit,$2'
				);

				images[ i ].setAttribute( 'data-lazyload', rewritten );
			}
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', resize );
		} else {
			resize();
		}
	} )();
	</script>
	<?php
	return ob_get_clean();
}
