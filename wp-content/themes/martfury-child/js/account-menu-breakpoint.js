/*
 * The logged-in account menu switches between desktop and mobile at the same width as the header: 992px.
 *
 * Martfury (scripts.js, userMobileMenu) treats screens under 1200px as mobile: it sets body.canvas-menu-mobile,
 * and while that's set, clicking any link in the account menu - Log out included - is cancelled and the user
 * panel slides in instead. But the desktop header, with that menu's dropdown, shows from 992px, so at 992-1199px
 * Log out opened the panel. Its width check also leaves out the scrollbar, unlike CSS. This sets the class from
 * the header's own media query instead, after Martfury's handler (this runs later, on load and on resize).
 */
jQuery( function ( $ ) {
	var mobile = window.matchMedia( '(max-width: 991px)' );
	var $body = $( document.body );

	function sync() {
		$body.toggleClass( 'canvas-menu-mobile', mobile.matches );
		if ( ! mobile.matches ) {
			$body.removeClass( 'display-user-mobile' );
		}
	}

	$( window ).on( 'resize', sync );
	sync();
} );
