( function () {
	'use strict';
	var root = document.querySelector( '.ul-ml__zipform' );
	if ( ! root ) {
		return;
	}
	var picks = Array.prototype.slice.call( root.querySelectorAll( '.ul-ml__pick' ) );
	var all = root.querySelector( '.ul-ml__all' );
	var button = root.querySelector( '.ul-ml__zipbtn' );
	var count = root.querySelector( '.ul-ml__count' );

	function update() {
		var n = picks.filter( function ( p ) {
			return p.checked;
		} ).length;
		count.textContent = '(' + n + ')';
		button.disabled = n === 0;
		all.checked = n > 0 && n === picks.length;
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

	// Clicking a product image toggles that product's checkbox.
	// Clicking anywhere on a card (image, name, SKU, empty space in the text box) toggles its
	// checkbox. Download links, the checkbox itself and form controls keep their normal behaviour.
	// Capture phase, so theme scripts that stop propagation on images cannot swallow the click.
	root.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target.closest ) {
			return;
		}
		var card = target.closest( '.ul-ml__card' );
		if ( ! card || target.closest( 'a, input, label, button, select, textarea' ) ) {
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
