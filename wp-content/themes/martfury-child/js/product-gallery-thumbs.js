/*
 * Product page gallery on phones: the thumbnails are a row under the image that scrolls sideways (style.css,
 * "Product gallery on phones").
 *
 * Martfury (scripts.js, productThumbnail) turns the thumbnails into a vertical Slick slider when a product has
 * more images than the gallery's columns (5); its track stacks them in a column whatever the CSS says. At phone
 * width this removes the slider again, so every product gets the same scrolling row. Slick fires "init" on the
 * list once it's set up (after the images load); a window narrowed to phone width afterwards is caught too.
 */
jQuery( function ( $ ) {
	var phone = window.matchMedia( '(max-width: 767px)' );

	function unslick( $thumbs ) {
		if ( phone.matches && $thumbs.hasClass( 'slick-initialized' ) ) {
			$thumbs.slick( 'unslick' ).addClass( 'no-slick' );
		}
	}

	$( document ).on( 'init', '.woocommerce-product-gallery .flex-control-thumbs', function ( e, slick ) {
		var $thumbs = $( slick.$slider );
		// After Slick finishes setting up (it's still inside its own init here).
		setTimeout( function () {
			unslick( $thumbs );
		} );
	} );

	function unslickAll() {
		unslick( $( '.woocommerce-product-gallery .flex-control-thumbs' ) );
	}

	phone.addEventListener( 'change', unslickAll );
	unslickAll();
} );
