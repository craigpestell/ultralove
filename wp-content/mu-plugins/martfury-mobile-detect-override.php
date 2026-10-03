<?php
/**
 * Turn off Martfury's separate phone version.
 *
 * This file loads as an MU-plugin before themes, so Martfury's pluggable
 * function declaration is skipped and this implementation is used.
 *
 * Martfury sends phones (user-agent detected) different markup: its own header,
 * navigation, footer and `body.mobile-version`, plus collapsed product tabs and a
 * collapsed catalog filter. The site is one responsive design instead - the same
 * markup and the same CSS classes at every width, with the breakpoints in the CSS -
 * so nothing is chosen by user agent. Always false also means every page can be
 * cached once, not once per device type, and it doesn't depend on the
 * `enable_mobile_version` theme option.
 */

if ( ! function_exists( 'martfury_is_mobile' ) ) {
	function martfury_is_mobile() {
		return false;
	}
}
