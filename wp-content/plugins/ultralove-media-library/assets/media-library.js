( function () {
	'use strict';
	var root = document.querySelector( '.ul-ml__zipform' );
	if ( ! root ) {
		return;
	}
	var picks = Array.prototype.slice.call( root.querySelectorAll( '.ul-ml__pick' ) );
	var all = root.querySelector( '.ul-ml__all' );
	var dock = root.querySelector( '.ul-ml__dock' );
	var button = root.querySelector( '.ul-ml__zipbtn' );
	var count = root.querySelector( '.ul-ml__count' );
	var label = root.querySelector( '.ul-ml__count-label' );
	var clear = root.querySelector( '.ul-ml__clear' );
	var status = root.querySelector( '.ul-ml__sr' );
	var announceTimer = null;
	var lastAnnounced = 0;

	// Announce the selection to screen readers only after the user pauses (so ticking several
	// products quickly gives one message, not one per click), and only when the count changed.
	function announce( n ) {
		if ( ! status ) {
			return;
		}
		window.clearTimeout( announceTimer );
		announceTimer = window.setTimeout( function () {
			if ( n === lastAnnounced ) {
				return;
			}
			lastAnnounced = n;
			status.textContent = n === 0 ? dock.getAttribute( 'data-label-none' ) : n + ' ' + ( n === 1 ? dock.getAttribute( 'data-label-one' ) : dock.getAttribute( 'data-label-many' ) );
		}, 700 );
	}

	function update() {
		var n = 0;
		picks.forEach( function ( p ) {
			var card = p.closest( '.ul-ml__card' );
			if ( p.checked ) {
				n++;
			}
			if ( card ) {
				card.classList.toggle( 'is-selected', p.checked );
			}
		} );
		count.textContent = String( n );
		label.textContent = n === 1 ? dock.getAttribute( 'data-label-one' ) : dock.getAttribute( 'data-label-many' );
		button.disabled = n === 0;
		dock.classList.toggle( 'is-visible', n > 0 );
		all.checked = n > 0 && n === picks.length;
		announce( n );
	}

	all.addEventListener( 'change', function () {
		picks.forEach( function ( p ) {
			p.checked = all.checked;
		} );
		update();
	} );
	picks.forEach( function ( p ) {
		p.addEventListener( 'change', update );
	} );
	clear.addEventListener( 'click', function () {
		picks.forEach( function ( p ) {
			p.checked = false;
		} );
		update();
		// The dock is about to be hidden; move focus to "Select all" so keyboard focus is not lost.
		all.focus( { preventScroll: true } );
	} );

	// Clicking the product image or the card padding around it toggles the checkbox. The details box
	// below the image (name, SKU, download links) does not, and links, the checkbox itself and
	// form controls keep their normal behaviour.
	// Capture phase, so theme scripts that stop propagation on images cannot swallow the click.
	root.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target.closest ) {
			return;
		}
		var card = target.closest( '.ul-ml__card' );
		if ( ! card || target.closest( '.ul-ml__info, a, input, label, button, select, textarea' ) ) {
			return;
		}
		var pick = card.querySelector( '.ul-ml__pick' );
		if ( pick ) {
			if ( target.closest( 'img' ) ) {
				event.preventDefault();
			}
			pick.checked = ! pick.checked;
			update();
		}
	}, true );
	update();
}() );
