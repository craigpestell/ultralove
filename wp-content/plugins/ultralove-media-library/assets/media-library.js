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
	root.addEventListener( 'click', function ( event ) {
		var img = event.target.closest ? event.target.closest( '.ul-ml__card img' ) : null;
		if ( ! img ) {
			return;
		}
		var pick = img.closest( '.ul-ml__card' ).querySelector( '.ul-ml__pick' );
		if ( pick ) {
			pick.checked = ! pick.checked;
			update();
		}
	} );
	update();
}() );
