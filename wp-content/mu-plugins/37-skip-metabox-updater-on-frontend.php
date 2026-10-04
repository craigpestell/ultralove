<?php
/**
 * Plugin Name: Skip Meta Box Updater on Front-End Requests (Perf Fix)
 * Description: Meta Box's updater hooks `init` on every request and, to decide whether to watch for updates,
 *              calls get_plugins(), which opens every installed plugin's main file to read its header (~17 ms
 *              on a catalog page, more on a cold disk cache). Its update hooks only matter where WordPress
 *              checks for or installs updates: wp-admin, WP-Cron and WP-CLI. Visitors' page loads and AJAX
 *              calls (wishlist, cart fragments) don't need it, so it's unhooked there.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		if ( defined( 'WP_CLI' ) || wp_doing_cron() ) {
			return;
		}

		if ( is_admin() ) {
			// admin-ajax.php counts as admin; keep the updater only for its plugin install/update actions.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( ! wp_doing_ajax() || preg_match( '/^(update|install|search-install|bulk-update|delete)-/', $action ) ) {
				return;
			}
		}

		global $wp_filter;
		if ( empty( $wp_filter['init']->callbacks[1] ) ) {
			return;
		}

		foreach ( $wp_filter['init']->callbacks[1] as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof \MetaBox\Updater\Checker && 'enable_update' === $function[1] ) {
				remove_action( 'init', $function, 1 );
			}
		}
	},
	0
);
