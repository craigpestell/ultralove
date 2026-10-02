<?php
/**
 * Plugin Name: Skip Empty AJAX Calls (Perf Fix)
 * Description: Two uncached AJAX requests ran on every page view, for every visitor - crawlers that run scripts
 *              (Baiduspider-render: up to two thirds of all requests) included - although for most visitors they
 *              have nothing to return:
 *              - Martfury's footer "Recently Viewed Products" (?wc-ajax=martfury_footer_recently_viewed), which
 *                only has something to show for a visitor with a woocommerce_recently_viewed cookie. Without one
 *                the block is already rendered hidden (hide-empty + no-products), so it's removed from the page
 *                before Martfury's script looks for it.
 *              - YITH Wishlist's "AJAX loading" (admin-ajax.php, action=load_fragments), which re-renders every
 *                wishlist button so cached pages show what's in the visitor's wishlist. A guest's wishlist lives
 *                in YITH's session cookie, which scripts can't read (HttpOnly), so a readable ul_wishlist cookie
 *                marks a guest who has one; without it the buttons stay as rendered (not in the wishlist - right
 *                for anyone without a wishlist) and the call is skipped. Signed-in visitors never get cached
 *                pages, so their buttons are rendered right in the first place.
 *              The check runs in <head>, ahead of every other script and out of Autoptimize (<!--noptimize-->).
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Mark a guest who has a YITH wishlist session with a cookie scripts can read. */
function ultralove_mark_wishlist_visitor() {
	if ( ! empty( $_COOKIE['ul_wishlist'] ) || headers_sent() ) {
		return;
	}
	setcookie(
		'ul_wishlist',
		'1',
		array(
			'expires'  => time() + 30 * DAY_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		)
	);
	$_COOKIE['ul_wishlist'] = '1';
}

// A guest adding to their wishlist, and any guest who already has a wishlist session (from before this existed).
add_action( 'yith_wcwl_added_to_wishlist', 'ultralove_mark_wishlist_visitor' );
add_action(
	'init',
	function () {
		if ( is_user_logged_in() || ! empty( $_COOKIE['ul_wishlist'] ) ) {
			return;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, 'yith_wcwl_session_' ) ) {
				ultralove_mark_wishlist_visitor();
				return;
			}
		}
	}
);

/*
 * The check, first thing in <head>. YITH's settings arrive as `var yith_wcwl_l10n = {...}` (wp_localize_script),
 * wherever Autoptimize puts it; an accessor set up beforehand turns its AJAX loading off as it's assigned. The
 * footer block is removed once the page is parsed (readyState "interactive"), before any deferred script runs.
 */
add_action(
	'wp_head',
	function () {
		if ( is_admin() ) {
			return;
		}
		?>
<!--noptimize--><script id="ul-skip-empty-ajax">
(function () {
	var c = document.cookie;
	if (!/(?:^|;\s*)ul_wishlist=1/.test(c)) {
		var l10n;
		Object.defineProperty(window, 'yith_wcwl_l10n', {
			configurable: true,
			get: function () { return l10n; },
			set: function (v) { if (v) { v.enable_ajax_loading = false; } l10n = v; }
		});
	}
	if (!/(?:^|;\s*)woocommerce_recently_viewed=/.test(c)) {
		var drop = function () {
			if (document.readyState === 'loading') { return; }
			document.removeEventListener('readystatechange', drop);
			var el = document.getElementById('footer-recently-viewed');
			if (el && el.classList.contains('no-products')) { el.parentNode.removeChild(el); }
		};
		document.addEventListener('readystatechange', drop);
	}
}());
</script><!--/noptimize-->
		<?php
	},
	1
);
