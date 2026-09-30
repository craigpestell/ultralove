<?php
/**
 * Plugin Name: Defer jQuery (Perf Fix)
 * Description: jQuery (~87 KB) is the only script left that blocks rendering in <head>: Autoptimize skips it, and
 *              every other script ends up in Autoptimize's deferred bundle at the end of <body>. On pages
 *              Autoptimize has optimized, this adds `defer` to jQuery. Deferred scripts run in document order,
 *              so jQuery still runs before the bundle. Inline scripts that call jQuery straight away (e.g. the
 *              `form.cart` quantity handler a plugin prints) are wrapped to wait for DOMContentLoaded, which
 *              fires after deferred scripts have run. Gravity Forms' inline scripts already wait via
 *              gform.initializeOnLoaded().
 *
 *              Fail-safe: if a page has a blocking script that might need jQuery, or an inline script using
 *              jQuery that can't be safely wrapped, the page is left untouched. Pages Autoptimize doesn't
 *              optimize (cart, checkout) never reach this filter.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'autoptimize_html_after_minify',
	function ( $html ) {
		if ( ! preg_match( '#<script\b[^>]*\bid=["\']jquery-core-js["\'][^>]*>#i', $html, $jquery_tag ) ) {
			return $html;
		}
		$jquery_tag = $jquery_tag[0];

		$wraps = array();
		preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $scripts as $script ) {
			$attrs = $script[1][0];
			$body  = $script[2][0];

			if ( preg_match( '#\btype=["\'](?!text/javascript|module)[^"\']*["\']#i', $attrs ) ) {
				continue; // JSON, text/plain (held by the lazy loader), templates, ...
			}

			if ( preg_match( '#\bsrc=["\']([^"\']+)#i', $attrs, $src ) ) {
				if ( preg_match( '#\b(defer|async)\b#i', $attrs ) || false !== strpos( $attrs, 'jquery-core-js' ) ) {
					continue;
				}
				// WordPress's own packages (hooks, i18n, a11y, dom-ready) don't use jQuery.
				if ( false !== strpos( $src[1], '/wp-includes/js/dist/' ) ) {
					continue;
				}
				return $html; // A blocking script that may need jQuery before it's deferred to.
			}

			if ( ! preg_match( '#\bjQuery\b|\$\s*\(|\$\.#', $body ) ) {
				continue;
			}
			$code = trim( preg_replace( '#^\s*/\*\s*<!\[CDATA\[\s*\*/|/\*\s*\]\]>\s*\*/\s*$#', '', $body ) );
			if ( 0 === strpos( $code, 'gform.initializeOnLoaded(' ) ) {
				continue;
			}
			// Only wrap scripts that are a single statement-style call on jQuery. Wrapping anything else
			// could turn its top-level declarations into locals.
			if ( ! preg_match( '#^(jQuery|\$)\s*[(.]#', $code ) ) {
				return $html;
			}
			$wraps[] = array( $script[2][1], strlen( $body ), $body );
		}

		// Replace from the end so earlier offsets stay valid.
		foreach ( array_reverse( $wraps ) as $wrap ) {
			$html = substr_replace(
				$html,
				"document.addEventListener('DOMContentLoaded',function(){\n" . $wrap[2] . "\n});",
				$wrap[0],
				$wrap[1]
			);
		}

		return str_replace( $jquery_tag, str_replace( '<script ', '<script defer ', $jquery_tag ), $html );
	}
);
