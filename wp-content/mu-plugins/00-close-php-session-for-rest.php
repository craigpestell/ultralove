<?php
/**
 * Plugin Name: Close PHP Session For REST/Loopback
 * Description: Closes active PHP sessions before WordPress performs HTTP, REST, and loopback-related operations.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Close an active session if one is open to avoid blocking HTTP/REST calls.
 */
function copilot_close_active_php_session() {
	if ( PHP_SESSION_ACTIVE === session_status() ) {
		session_write_close();
	}
}

// Run late so plugins that open sessions during init/admin_init are handled.
add_action( 'init', 'copilot_close_active_php_session', PHP_INT_MAX );
add_action( 'admin_init', 'copilot_close_active_php_session', PHP_INT_MAX );

// Ensure sessions are closed before REST dispatch.
add_action( 'rest_api_init', 'copilot_close_active_php_session', 0 );

// Ensure sessions are closed before outbound HTTP requests (includes loopbacks).
add_filter(
	'pre_http_request',
	static function ( $preempt, $parsed_args, $url ) {
		copilot_close_active_php_session();

		return $preempt;
	},
	0,
	3
);
