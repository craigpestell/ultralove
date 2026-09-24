/**
 * Sizes #ub-hero-combo-bg (appended to the end of <body>, see PHP) to span from the top of the
 * home banner to the bottom of #topcta, so the one gradient behind it reads as a single backdrop
 * across both instead of two independent copies with a seam between them. Only runs when that
 * combined mode is on (body.ub-hero-combo - see banner.css and ub_home_bg_style() in
 * ultralove-banners.php).
 */
(function () {
	function position() {
		var backdrop = document.getElementById( 'ub-hero-combo-bg' );
		var slider = document.querySelector( '.ub-banner-home' );
		var topcta = document.getElementById( 'topcta' );
		if ( ! backdrop || ! slider || ! topcta ) {
			return;
		}
		var top = slider.getBoundingClientRect().top + window.pageYOffset;
		var bottom = topcta.getBoundingClientRect().bottom + window.pageYOffset;
		backdrop.style.top = top + 'px';
		backdrop.style.height = Math.max( 0, bottom - top ) + 'px';
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', position );
	} else {
		position();
	}
	window.addEventListener( 'load', position );
	window.addEventListener( 'resize', position );

	if ( 'ResizeObserver' in window ) {
		var ro = new ResizeObserver( position );
		var slider = document.querySelector( '.ub-banner-home' );
		var topcta = document.getElementById( 'topcta' );
		if ( slider ) {
			ro.observe( slider );
		}
		if ( topcta ) {
			ro.observe( topcta );
		}
	}
})();
