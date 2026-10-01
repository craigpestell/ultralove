/*
 * Product card Add to cart buttons: after an item is added, WooCommerce marks the button .added for good, and
 * style.css shows a checkmark on it (in place of the cart icon on phones). This puts the button back to Add to
 * cart after 2.5 seconds, so the checkmark means "just added" and the card is ready to add from again.
 */
jQuery( function ( $ ) {
	$( document.body ).on( 'added_to_cart', function ( e, fragments, cartHash, $button ) {
		if ( ! $button || ! $button.closest( 'ul.products' ).length ) {
			return;
		}
		clearTimeout( $button.data( 'ulAddedTimer' ) );
		$button.data( 'ulAddedTimer', setTimeout( function () {
			$button.removeClass( 'added' );
		}, 2500 ) );
	} );
} );
