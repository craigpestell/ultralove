<?php
/**
 * Send WordPress's requests to its own site straight to this server.
 *
 * The site sits behind Cloudflare, which challenges requests from the server's own IP
 * (`cf-mitigated: challenge`). WordPress calls itself over HTTP for background work, and the
 * Cloudinary plugin starts its sync threads that way (a non-blocking POST to
 * /wp-json/cloudinary/v1/queue), so with the challenge in the way those threads never ran and
 * nothing synced in the background. WP-Cron and Site Health loopbacks hit the same wall.
 *
 * cURL is told to resolve the site's own host to 127.0.0.1 (the same as `curl --resolve`). The
 * URL and Host header stay as they are, so nginx's virtual host and the certificate still match
 * and TLS verification is unchanged. Only production's host is listed: dev has a different host
 * and is not reached this way.
 */

add_action(
	'http_api_curl',
	static function ( $handle, $parsed_args, $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( ( $parts['scheme'] ?? '' ) === 'http' ? 80 : 443 );

		if ( ! in_array( $host, array( 'ultralove.ca', 'www.ultralove.ca' ), true ) ) {
			return;
		}
		if ( 443 !== $port && 80 !== $port ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':127.0.0.1' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
	},
	10,
	3
);
