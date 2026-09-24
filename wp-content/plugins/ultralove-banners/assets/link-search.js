( function () {
	var cfg = window.ubLinkSearch;
	if ( ! cfg ) {
		return;
	}

	var timer = null;
	var request = 0;
	var active = null; // { input, list, items, index }

	function close() {
		if ( active && active.list ) {
			active.list.remove();
		}
		active = null;
	}

	var choosing = false;

	// The hidden post-ID field and the "Linked to ..." note that sit beside a link input.
	function target( input ) {
		var box = input.closest( 'tr' ) || input.closest( '.inside' ) || document;
		return { id: box.querySelector( '.ub-post-id, #ub_post' ), note: box.querySelector( '.ub-post-note' ) };
	}

	function choose( item ) {
		var input = active.input;
		var t = target( input );
		choosing = true;
		input.value = item.url;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		choosing = false;
		if ( t.id ) {
			t.id.value = item.id;
		}
		if ( t.note ) {
			t.note.textContent = 'Linked to ' + item.type + ': ' + item.title;
		}
		close();
		input.focus();
	}

	function render( input, items ) {
		close();
		if ( ! items.length ) {
			return;
		}
		var list = document.createElement( 'ul' );
		list.className = 'ub-ac-list';
		items.forEach( function ( item, i ) {
			var li = document.createElement( 'li' );
			var title = document.createElement( 'span' );
			title.className = 'ub-ac-title';
			title.textContent = item.title || '(no title)';
			var meta = document.createElement( 'span' );
			meta.className = 'ub-ac-meta';
			meta.textContent = item.type + ( item.sku ? ' · ' + item.sku : '' ) + ' · ' + item.url;
			li.appendChild( title );
			li.appendChild( meta );
			// mousedown (not click) so it fires before the input's blur closes the list.
			li.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				active.index = i;
				choose( item );
			} );
			list.appendChild( li );
		} );
		var wrap = input.closest( '.ub-ac' );
		wrap.appendChild( list );
		active = { input: input, list: list, items: items, index: -1 };
	}

	function highlight() {
		[].forEach.call( active.list.children, function ( li, i ) {
			li.classList.toggle( 'is-active', i === active.index );
		} );
	}

	function search( input ) {
		var q = input.value.trim();
		// A typed URL or path is already a link; only search on words.
		if ( q.length < 2 || /^(https?:)?\/\//i.test( q ) || q.charAt( 0 ) === '/' ) {
			close();
			return;
		}
		var id = ++request;
		var url = cfg.ajax + '?action=ub_link_search&nonce=' + encodeURIComponent( cfg.nonce ) + '&q=' + encodeURIComponent( q );
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( id === request && res && res.success && document.activeElement === input ) {
					render( input, res.data );
				}
			} )
			.catch( function () {} );
	}

	function isLinkInput( el ) {
		return el && el.tagName === 'INPUT' && ( el.id === 'ub_link' || el.classList.contains( 'ub-link' ) );
	}

	function ensureWrap( input ) {
		if ( ! input.closest( '.ub-ac' ) ) {
			var wrap = document.createElement( 'span' );
			wrap.className = 'ub-ac';
			input.parentNode.insertBefore( wrap, input );
			wrap.appendChild( input );
		}
	}

	document.addEventListener( 'focusin', function ( e ) {
		if ( isLinkInput( e.target ) ) {
			ensureWrap( e.target );
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		if ( ! isLinkInput( e.target ) ) {
			return;
		}
		ensureWrap( e.target );
		if ( ! choosing ) {
			// Typing by hand turns the link back into a custom URL (no longer tied to a post).
			var t = target( e.target );
			if ( t.id ) {
				t.id.value = '';
			}
			if ( t.note ) {
				t.note.textContent = '';
			}
		}
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			search( e.target );
		}, 250 );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! active || ! isLinkInput( e.target ) ) {
			return;
		}
		if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
			e.preventDefault();
			var n = active.items.length;
			active.index = ( active.index + ( e.key === 'ArrowDown' ? 1 : -1 ) + n ) % n;
			highlight();
		} else if ( e.key === 'Enter' && active.index >= 0 ) {
			e.preventDefault();
			choose( active.items[ active.index ] );
		} else if ( e.key === 'Escape' ) {
			close();
		}
	} );

	document.addEventListener( 'focusout', function ( e ) {
		if ( isLinkInput( e.target ) ) {
			window.setTimeout( close, 150 );
		}
	} );
} )();
