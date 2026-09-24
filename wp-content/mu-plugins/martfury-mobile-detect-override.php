<?php
/**
 * Override Martfury mobile detection early.
 *
 * This file loads as an MU-plugin before themes, so Martfury's pluggable
 * function declaration is skipped and this implementation is used.
 */

if ( ! function_exists( 'martfury_is_mobile' ) ) {
	function martfury_is_mobile() {
		if ( is_admin() ) {
			return false;
		}

		// Use WordPress core detection for consistency with frontend behavior.
		return function_exists( 'wp_is_mobile' ) ? (bool) wp_is_mobile() : false;
	}
}
