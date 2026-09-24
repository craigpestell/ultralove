( function () {
	var wrap = document.getElementById( 'ub-crop-wrap' );
	if ( ! wrap || ! window.Cropper ) {
		return;
	}

	var img = document.getElementById( 'ub-crop-img' );
	var input = document.getElementById( 'ub_crop' );
	var enabled = document.getElementById( 'ub_crop_enabled' );
	var group = document.getElementById( 'ub_group' );
	var ratios = JSON.parse( wrap.getAttribute( 'data-ratios' ) );
	var id = wrap.getAttribute( 'data-id' );
	var origW = parseInt( wrap.getAttribute( 'data-w' ), 10 );
	var saved = wrap.getAttribute( 'data-crop' );
	var cropper;

	function ratio() {
		return ratios[ group.value ] || NaN;
	}

	// Cropper reports coordinates in the loaded image's pixels; the server stores them in the
	// original attachment's pixels (Cloudinary crops the original).
	function scale() {
		return origW / img.naturalWidth;
	}

	function write() {
		var d = cropper.getData( true );
		var s = scale();
		input.value = [ id, Math.round( d.x * s ), Math.round( d.y * s ), Math.round( d.width * s ), Math.round( d.height * s ) ].join( ',' );
	}

	function init() {
		cropper = new Cropper( img, {
			aspectRatio: ratio(),
			viewMode: 1,
			autoCropArea: 1,
			zoomable: false,
			background: false,
			ready: function () {
				if ( saved ) {
					var p = saved.split( ',' ).map( Number );
					var k = 1 / scale();
					cropper.setData( { x: p[ 0 ] * k, y: p[ 1 ] * k, width: p[ 2 ] * k, height: p[ 3 ] * k } );
				}
			},
			crop: write,
			cropend: function () {
				enabled.checked = true;
			},
		} );
	}

	if ( img.complete && img.naturalWidth ) {
		init();
	} else {
		img.addEventListener( 'load', init );
	}

	group.addEventListener( 'change', function () {
		if ( cropper ) {
			cropper.setAspectRatio( ratio() );
			enabled.checked = true;
		}
	} );
} )();
