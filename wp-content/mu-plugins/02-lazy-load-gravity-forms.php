<?php
/**
 * Plugin Name: Lazy Load Gravity Forms + reCAPTCHA (Perf Fix)
 * Description: The footer contact form (Gravity Forms, with a reCAPTCHA field) is on every page, so
 *              Gravity Forms' scripts (~175 KB) and Google's recaptcha/api.js loaded on every page view
 *              even though almost nobody uses the form on any given page. This holds all of them back
 *              until a form is near the viewport or the visitor interacts with one, then loads them in
 *              their original order (reCAPTCHA last). A form that's visible on load - Contact Us, Buy
 *              Wholesale - loads them straight away. Gravity Forms supports this: its form set-up code
 *              waits (gform.initializeOnLoaded) for the "scripts loaded" events its files fire. Without
 *              JavaScript the forms still submit as normal page posts.
 *
 *              Gravity Forms' stylesheets (~90 KB) are held back the same way, as rel="ul-lazy-stylesheet"
 *              links (which browsers and Autoptimize leave alone, so they stay out of the combined stylesheet)
 *              switched to real stylesheets just before the scripts load. Not on pages that show a form as
 *              part of their content (Contact Us, Become a Retailer) or on account / cart / checkout pages:
 *              there a form is on screen from the start and must be styled from the first paint.
 * Version: 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Gravity Forms' CSS must load with the page: a form is part of the page's own content, or it's an
 * account / cart / checkout page. Elsewhere the only form is the footer's, far below the first screen.
 */
function ultralove_gform_css_needed_at_load() {
	if ( function_exists( 'is_account_page' ) && ( is_account_page() || is_cart() || is_checkout() ) ) {
		return true;
	}
	$post = is_singular() ? get_queried_object() : null;
	return $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'gravityform' ) || has_block( 'gravityforms/form', $post ) );
}

add_filter(
	'style_loader_tag',
	function ( $tag, $handle, $href ) {
		if ( false === strpos( (string) $href, '/plugins/gravityforms/' ) || ultralove_gform_css_needed_at_load() ) {
			return $tag;
		}
		return preg_replace( '#\brel=(["\'])stylesheet\1#', 'rel="ul-lazy-stylesheet"', $tag, 1 );
	},
	10,
	3
);

add_filter(
	'script_loader_tag',
	function ( $tag, $handle, $src ) {
		if ( 'gform_recaptcha' !== $handle && false === strpos( (string) $src, '/plugins/gravityforms/' ) ) {
			return $tag;
		}

		// Only the external file is held back; the handle's inline before/after scripts (settings and
		// translations) stay as they are.
		return preg_replace(
			'#<script\b[^>]*\ssrc=(["\'])[^"\']*\1[^>]*>\s*</script>#',
			sprintf(
				'<script type="text/plain" data-ul-lazy-src="%s" data-ul-lazy-id="%s"></script>',
				esc_url( $src ),
				esc_attr( $handle . '-js' )
			),
			$tag,
			1
		);
	},
	10,
	3
);

add_action(
	'wp_footer',
	function () {
		?>
		<script>
		( function () {
			var started = false;

			function load() {
				if ( started ) {
					return;
				}
				// Stylesheets first, so the form is styled by the time its scripts set it up.
				Array.prototype.forEach.call( document.querySelectorAll( 'link[rel="ul-lazy-stylesheet"]' ), function ( link ) {
					link.rel = 'stylesheet';
				} );
				var held = document.querySelectorAll( 'script[data-ul-lazy-src]' );
				if ( ! held.length ) {
					return;
				}
				started = true;
				if ( observer ) {
					observer.disconnect();
				}
				document.removeEventListener( 'focusin', onInteract );
				document.removeEventListener( 'pointerdown', onInteract );

				// Gravity Forms' files first, in page order, then reCAPTCHA (its render callback is in them).
				var list = Array.prototype.slice.call( held ).sort( function ( a, b ) {
					var ar = a.getAttribute( 'data-ul-lazy-id' ) === 'gform_recaptcha-js' ? 1 : 0;
					var br = b.getAttribute( 'data-ul-lazy-id' ) === 'gform_recaptcha-js' ? 1 : 0;
					return ar - br;
				} );
				list.forEach( function ( placeholder ) {
					var script = document.createElement( 'script' );
					script.src = placeholder.getAttribute( 'data-ul-lazy-src' );
					script.id = placeholder.getAttribute( 'data-ul-lazy-id' );
					script.async = false; // Run in insertion order, like the original tags.
					document.body.appendChild( script );
				} );
			}

			function onInteract( e ) {
				if ( e.target.closest && e.target.closest( '.gform_wrapper' ) ) {
					load();
				}
			}

			var observer;
			var forms = document.querySelectorAll( '.gform_wrapper' );
			if ( 'IntersectionObserver' in window ) {
				observer = new IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								load();
							}
						} );
					},
					{ rootMargin: '400px' }
				);
				Array.prototype.forEach.call( forms, function ( form ) {
					observer.observe( form );
				} );
			} else {
				load();
			}

			document.addEventListener( 'focusin', onInteract );
			document.addEventListener( 'pointerdown', onInteract );
		} )();
		</script>
		<?php
	},
	100
);
