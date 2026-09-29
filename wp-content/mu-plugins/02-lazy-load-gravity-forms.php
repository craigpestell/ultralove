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
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
