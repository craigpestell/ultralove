( function ( $ ) {
	var $rows = $( '#ub-rows' );
	var nextIndex = 1000;

	$rows.sortable( {
		handle: '.ub-handle',
		axis: 'y',
		helper: function ( e, tr ) {
			// Keep the dragged row's column widths instead of collapsing it.
			var $cells = tr.children();
			var $helper = tr.clone();
			$helper.children().each( function ( i ) {
				$( this ).width( $cells.eq( i ).width() );
			} );
			return $helper;
		},
	} );

	function pickImage( $row ) {
		var frame = wp.media( { title: 'Choose banner image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
			$row.find( '.ub-image-id' ).val( att.id );
			$row.find( '.ub-thumb' ).html( $( '<img>' ).attr( 'src', url ) );
			$row.find( '.ub-pick' ).text( 'Change image' );
			var $title = $row.find( 'input[name$="[title]"]' );
			if ( ! $title.val() ) {
				$title.val( att.title );
			}
		} );
		frame.open();
	}

	$rows.on( 'click', '.ub-pick', function () {
		pickImage( $( this ).closest( 'tr' ) );
	} );

	$rows.on( 'click', '.ub-remove', function () {
		var $row = $( this ).closest( 'tr' );
		if ( '0' === $row.find( 'input[name$="[id]"]' ).val() ) {
			$row.remove();
			return;
		}
		// Existing slide: mark for deletion on save (and stop its required link blocking the form).
		$row.find( '.ub-delete' ).val( '1' );
		$row.find( '.ub-link' ).prop( 'required', false );
		$row.addClass( 'ub-deleted' ).find( 'input, select, button' ).not( '.ub-delete, input[name$="[id]"], .ub-remove' ).prop( 'disabled', true );
		$( this ).text( 'Undo' ).removeClass( 'ub-remove' ).addClass( 'ub-undo' );
	} );

	$rows.on( 'click', '.ub-undo', function () {
		var $row = $( this ).closest( 'tr' );
		$row.find( '.ub-delete' ).val( '0' );
		$row.find( '.ub-link' ).prop( 'required', true );
		$row.removeClass( 'ub-deleted' ).find( 'input, select, button' ).prop( 'disabled', false );
		$( this ).text( 'Delete' ).removeClass( 'ub-undo' ).addClass( 'ub-remove' );
	} );

	$( '#ub-add' ).on( 'click', function () {
		var html = $( '#ub-row-template' ).html().replace( /__INDEX__/g, String( nextIndex++ ) );
		var $row = $( html ).appendTo( $rows );
		pickImage( $row );
	} );
} )( jQuery );
