/* Cards contain cache-safe hearts; only the current visitor's saved product IDs arrive over AJAX. */
( function ( $ ) {
	'use strict';

	var saved = new Set(), request = null, reload = false, hasState = false;

	function renderHearts( root ) {
		$( root ).find( '.ultralove-wishlist-heart' ).addBack( '.ultralove-wishlist-heart' ).each( function () {
			var heart = $( this ), template = heart.data( 'ul-heart-template' ),
				button = heart.children().first(), id = Number( heart.attr( 'data-product-id' ) ),
				exists = saved.has( id ), link;

			if ( ! template ) {
				template = button.clone();
				heart.data( 'ul-heart-template', template );
			}
			// YITH removes the add-button after a successful addition; restore our shared renderer.
			if ( ! button.length ) {
				button = template.clone().appendTo( heart );
			}
			link = button.find( 'a' );
			if ( link.hasClass( 'disabled_item' ) ) {
				return;
			}

			heart.toggleClass( 'exists', exists );
			button.toggleClass( 'yith-wcwl-add-button', ! exists ).toggleClass( 'yith-wcwl-wishlistaddedbrowse', exists );
			link.toggleClass( 'add_to_wishlist single_add_to_wishlist', ! exists )
				.attr( 'href', exists ? ulWishlistHearts.wishlistUrl : link.attr( 'data-ul-add-url' ) )
				.attr( 'title', exists ? ulWishlistHearts.browseLabel : ulWishlistHearts.addLabel )
				.attr( 'aria-label', exists ? ulWishlistHearts.browseLabel : ulWishlistHearts.addLabel );
			link.find( 'span' ).text( exists ? ulWishlistHearts.browseLabel : ulWishlistHearts.addLabel );
		} );
	}

	function stateError( detail ) {
		console.error( 'UltraLove wishlist state failed:', detail );
		$( '.ultralove-wishlist-heart a' ).attr( 'title', ulWishlistHearts.errorLabel );
		$( document ).trigger( 'ultralove_wishlist_state_error', [ detail ] );
	}

	function loadState() {
		if ( request ) {
			reload = true;
			return;
		}
		request = $.ajax( {
			url: yith_wcwl_l10n.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: { action: ulWishlistHearts.action, nonce: yith_wcwl_l10n.nonce.load_fragments_nonce }
		} ).done( function ( response ) {
			if ( ! response || response.success !== true || ! response.data || ! Array.isArray( response.data.product_ids )
				|| ! response.data.product_ids.every( function ( id ) { return Number.isInteger( id ) && id > 0; } ) ) {
				stateError( response );
				return;
			}
			saved = new Set( response.data.product_ids );
			hasState = true;
			renderHearts( document.body );
		} ).fail( function ( xhr, status, error ) {
			stateError( error || status );
		} ).always( function () {
			request = null;
			if ( reload ) {
				reload = false;
				loadState();
			}
		} );
	}

	$( function () {
		renderHearts( document.body );
		if ( $( '.ultralove-wishlist-heart' ).length ) {
			loadState();
		}

		$( document.body ).on( 'added_to_wishlist removed_from_wishlist', function () {
			loadState();
		} );
		$( document ).on( 'yith_wcwl_reload_after_ajax', function () {
			renderHearts( document.body );
		} );

		if ( 'MutationObserver' in window ) {
			new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					$( mutation.addedNodes ).filter( function () {
						return this.nodeType === 1;
					} ).each( function () {
						renderHearts( this );
					} );
				} );
				if ( ! hasState && ! request && $( '.ultralove-wishlist-heart' ).length ) {
					loadState();
				}
			} ).observe( document.body, { childList: true, subtree: true } );
		}
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted && $( '.ultralove-wishlist-heart' ).length ) {
				loadState();
			}
		} );
	} );
} )( jQuery );
