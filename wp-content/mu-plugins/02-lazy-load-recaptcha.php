<?php
/**
 * Plugin Name: Lazy Load reCAPTCHA (Perf Fix)
 * Description: The footer contact form (Gravity Forms) has a reCAPTCHA field, so Google's
 *              recaptcha/api.js and its gstatic chunks load on every single page view site-wide,
 *              even though almost nobody uses that form on any given page. This defers fetching
 *              that script until the visitor scrolls near the footer or otherwise interacts with
 *              the page, instead of loading it unconditionally on every request.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'script_loader_tag',
	function ( $tag, $handle, $src ) {
		if ( 'gform_recaptcha' !== $handle ) {
			return $tag;
		}

		return sprintf(
			'<script type="text/plain" data-lazy-recaptcha-src="%s" id="gform_recaptcha-js-lazy"></script>',
			esc_url( $src )
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

			function loadRecaptcha() {
				if ( started ) {
					return;
				}
				var placeholder = document.getElementById( 'gform_recaptcha-js-lazy' );
				if ( ! placeholder ) {
					return;
				}
				started = true;

				var script = document.createElement( 'script' );
				script.src = placeholder.getAttribute( 'data-lazy-recaptcha-src' );
				script.defer = true;
				script.id = 'gform_recaptcha-js';
				document.head.appendChild( script );

				window.removeEventListener( 'scroll', loadRecaptcha );
				window.removeEventListener( 'touchstart', loadRecaptcha );
				window.removeEventListener( 'mousemove', loadRecaptcha );
				document.removeEventListener( 'focusin', onFocusIn );
				if ( observer ) {
					observer.disconnect();
				}
			}

			function onFocusIn( e ) {
				if ( e.target.closest && e.target.closest( '.gform_wrapper' ) ) {
					loadRecaptcha();
				}
			}

			var observer;
			var footer = document.getElementById( 'colophon' );
			if ( 'IntersectionObserver' in window && footer ) {
				observer = new IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( entry ) {
							if ( entry.isIntersecting ) {
								loadRecaptcha();
							}
						} );
					},
					{ rootMargin: '300px' }
				);
				observer.observe( footer );
			}

			window.addEventListener( 'scroll', loadRecaptcha, { passive: true } );
			window.addEventListener( 'touchstart', loadRecaptcha, { passive: true } );
			window.addEventListener( 'mousemove', loadRecaptcha );
			document.addEventListener( 'focusin', onFocusIn );
		} )();
		</script>
		<?php
	},
	100
);
