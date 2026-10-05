/*
 * Product card wishlist hearts: the page leaves each card's image bar (.mf-product-thumbnail .footer-button)
 * empty, and this adds YITH's wishlist placeholder to it once the page has loaded, then has YITH fill the
 * placeholders in one load_fragments request (the server renders each heart from the product id and type, and
 * knows which products are already in the visitor's list). That keeps a heart's markup (about 2 KB a card)
 * out of the page's HTML, and out of the cache when pages are cached for logged-out visitors.
 */
( function ( $ ) {
	'use strict';

	function placeholder( id, type ) {
		var options = { product_id: id, parent_product_id: 0, product_type: type, item: 'add_to_wishlist' };

		return $( '<div>', {
			'class': 'yith-wcwl-add-to-wishlist add-to-wishlist-' + id + ' yith-wcwl-add-to-wishlist--link-style wishlist-fragment on-first-load',
			'data-fragment-ref': id,
			'data-fragment-options': JSON.stringify( options )
		} );
	}

	function addHearts( root ) {
		var added = $();

		$( root ).find( '.mf-product-thumbnail' ).addBack( '.mf-product-thumbnail' ).each( function () {
			var card = $( this ).closest( 'ul.products li.product' ),
				id = /(?:^|\s)post-(\d+)(?:\s|$)/.exec( card.attr( 'class' ) || '' ),
				type = /(?:^|\s)product-type-([\w-]+)(?:\s|$)/.exec( card.attr( 'class' ) || '' ),
				bar = $( this ).children( '.footer-button' );

			if ( ! id || bar.find( '.wishlist-fragment' ).length ) {
				return;
			}

			if ( ! bar.length ) {
				bar = $( '<div class="footer-button"></div>' ).appendTo( this );
			}

			added = added.add( placeholder( id[ 1 ], type ? type[ 1 ] : 'simple' ).appendTo( bar ) );
		} );

		if ( added.length ) {
			$( document ).trigger( 'yith_wcwl_reload_fragments', { fragments: added } );
		}
	}

	$( function () {
		addHearts( document.body );

		// Cards added later (filters, "load more", carousels).
		if ( 'MutationObserver' in window ) {
			new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					$( mutation.addedNodes ).filter( function () {
						return this.nodeType === 1;
					} ).each( function () {
						addHearts( this );
					} );
				} );
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	} );
} )( jQuery );
