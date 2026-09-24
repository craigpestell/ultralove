<?php
/**
 * Plugin Name: Ultralove Environment Guard
 * Description: On non-production environments (WP_ENVIRONMENT_TYPE != production): suppress all outgoing email, force noindex, and label the admin bar. Does nothing on production.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_get_environment_type' ) || 'production' === wp_get_environment_type() ) {
	return;
}

// Never send email from dev/staging. Log what would have been sent instead.
add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		$to = isset( $atts['to'] ) ? ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) : '';
		error_log( sprintf( '[ultralove-env-guard] suppressed email to %s: %s', $to, isset( $atts['subject'] ) ? $atts['subject'] : '' ) );
		return true;
	},
	1,
	2
);

// Keep non-production sites out of search engines.
add_filter( 'pre_option_blog_public', '__return_zero' );
add_action(
	'send_headers',
	function () {
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
);

// Make it obvious which environment you're in.
add_action(
	'admin_bar_menu',
	function ( $bar ) {
		$bar->add_node(
			array(
				'id'    => 'ultralove-environment',
				'title' => strtoupper( wp_get_environment_type() ) . ' (emails suppressed)',
				'meta'  => array( 'html' => '<style>#wp-admin-bar-ultralove-environment{background:#b32d2e!important}</style>' ),
			)
		);
	},
	1
);
