<?php
/** Offline note-only migration, stale guard and partial-failure recovery regressions. */
define( 'WP_CLI', true );
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ULTRALOVE_RN_INFO', '_ul_retailer_info' );
define( 'ULTRALOVE_RN_IMPORTANT', '_ul_retailer_important' );
require_once __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/notes.php';
class WP_CLI {
	public static function error( string $message ): never { throw new RuntimeException( $message ); }
	public static function success( string $message ): void {}
}
function home_url(): string { return $GLOBALS['site']; }
function wp_json_encode( ...$args ) { return json_encode( ...$args ); }
function clean_post_cache( int $id ): void {}
function get_post_type( int $id ): string { return 'product'; }
function wp_slash( string $value ): string { return addslashes( $value ); }
function get_post_meta( int $id, string $key, bool $single ): array { return $GLOBALS['meta'][ $id ][ $key ] ?? array(); }
function update_post_meta( int $id, string $key, string $value ): bool {
	++$GLOBALS['writes'];
	if ( $GLOBALS['fail'] === $GLOBALS['writes'] ) {
		return false;
	}
	$GLOBALS['meta'][ $id ][ $key ] = array( stripslashes( $value ) );
	return true;
}
function delete_post_meta( int $id, string $key ): void {
	++$GLOBALS['writes'];
	unset( $GLOBALS['meta'][ $id ][ $key ] );
}
$wpdb = new class {
	public string $posts = 'posts';
	public string $postmeta = 'postmeta';
	public string $prefix = 'wp_';
	public string $last_error = '';
	public function get_results( string $sql, string $format ): array {
		if ( str_contains( $sql, 'SELECT ID FROM' ) ) {
			return array_map( static fn( $id ) => array( 'ID' => (string) $id ), array_keys( $GLOBALS['meta'] ) );
		}
		return array( array( 'guard' => $GLOBALS['guard'] ) );
	}
};
$meta = array(
	1 => array( ULTRALOVE_RN_INFO => array( 'ONE PER STORE ONLY' ), ULTRALOVE_RN_IMPORTANT => array( 'LIMIT 1 PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ) ),
	2 => array( ULTRALOVE_RN_IMPORTANT => array( "FREE WITH PURCHASE OF 12 ESSENTIAL BULLETS 1 Per Store Only" ) ),
	3 => array( ULTRALOVE_RN_INFO => array( 'A', 'B' ) ),
	4 => array( ULTRALOVE_RN_IMPORTANT => array( 'FREE WITH MYSTERY OFFER' ) ),
	5 => array( ULTRALOVE_RN_INFO => array( '' ), ULTRALOVE_RN_IMPORTANT => array( 'One Per Store Only' ) ),
);
$initial = $meta;
$site = 'https://dev.example.test';
$guard = 'unchanged posts/provenance/redirects';
$writes = $fail = 0;
$path = tempnam( sys_get_temp_dir(), 'ul-notes-' );
unlink( $path );
$run = static function ( string $mode ) use ( $path ): void {
	$args = array( $mode, $path );
	require __DIR__ . '/normalize-notes.php';
};
$reject = static function ( string $mode, string $message ) use ( $run ): void {
	$before = $GLOBALS['writes'];
	try {
		$run( $mode );
	} catch ( RuntimeException $error ) {
		if ( $before !== $GLOBALS['writes'] ) {
			throw new RuntimeException( 'Rejection wrote notes: ' . $message );
		}
		return;
	}
	throw new RuntimeException( 'Expected rejection: ' . $message );
};
try {
	$run( 'preview' );
	$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( count( $plan['rows'] ) !== 3 || count( $plan['review'] ) !== 2 || ( fileperms( $path ) & 0077 ) || $writes ) {
		throw new RuntimeException( 'Preview scope, excluded review rows or privacy failed.' );
	}
	$reject( 'verify', 'not applied' );
	$site = 'https://wrong.example.test';
	$reject( 'apply', 'wrong site' );
	$site = $plan['site'];
	$guard = 'changed slug or redirect';
	$reject( 'apply', 'non-note edit' );
	$guard = 'unchanged posts/provenance/redirects';
	$meta[2][ ULTRALOVE_RN_IMPORTANT ] = array( 'Manual edit' );
	$reject( 'apply', 'later row preflight must stop every write' );
	$meta = $initial;
	$fail = 2;
	try {
		$run( 'apply' );
		throw new LogicException( 'Expected simulated write failure.' );
	} catch ( RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), 'Write verification failed' ) ) {
			throw $error;
		}
	}
	if ( $meta === $initial ) {
		throw new RuntimeException( 'Test did not leave partially applied field state.' );
	}
	$fail = 0;
	$run( 'restore' );
	if ( $meta !== $initial ) {
		throw new RuntimeException( 'Partial recovery did not restore exact absence/empty/value state.' );
	}
	$run( 'apply' );
	$run( 'verify' );
	$before = $writes;
	$run( 'apply' );
	if ( $before !== $writes || $meta[3] !== $initial[3] || $meta[4] !== $initial[4] ) {
		throw new RuntimeException( 'Repeated apply wrote notes or touched excluded products.' );
	}
	$run( 'restore' );
	if ( $meta !== $initial ) {
		throw new RuntimeException( 'Full recovery did not restore metadata existence and values.' );
	}
	$run( 'apply' );
	unlink( $path );
	$run( 'preview' );
	$repeat = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( $repeat['rows'] || count( $repeat['review'] ) !== 2 ) {
		throw new RuntimeException( 'Repeat preview should have zero safe changes and retain review cases.' );
	}
} finally {
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}
echo "PASS: note-only migration, excluded ambiguity, private plans, stale-state guards, failed-write recovery, exact restore and repeat safety.\n";
