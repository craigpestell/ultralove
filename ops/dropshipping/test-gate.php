<?php
/**
 * Standalone eligibility tests: php ops/dropshipping/test-gate.php.
 * The WordPress API doubles below never connect to a site or database.
 */

if ( defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
	throw new RuntimeException( 'Run this standalone test with PHP CLI, not through WordPress.' );
}

define( 'ABSPATH', __DIR__ . '/' );

$environment = 'development';
$option      = null;
$user_id     = 1;
$approval    = 'yes';
$checks      = 0;

function add_action( ...$args ) {}
function wp_get_environment_type() {
	return $GLOBALS['environment'];
}
function get_option( $key, $default = false ) {
	return $GLOBALS['option'] ?? $default;
}
function get_current_user_id() {
	return $GLOBALS['user_id'];
}
function get_user_meta( $id, $key, $single ) {
	return $GLOBALS['approval'];
}

require __DIR__ . '/../../wp-content/plugins/ultralove-dropshipping/ultralove-dropshipping.php';

$assert = function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};

foreach ( array( 'development', 'production', 'staging', 'local' ) as $environment ) {
	$supported = in_array( $environment, array( 'development', 'production' ), true );
	$assert( $supported === UltraLove_Dropshipping::supported_environment(), $environment . ' support policy.' );
	foreach ( array( null, 'no', 'yes', '1' ) as $option ) {
		$expected = $supported && 'yes' === $option;
		$assert( $expected === UltraLove_Dropshipping::enabled(), $environment . ' explicit enablement policy.' );
		$assert( $expected === UltraLove_Dropshipping::approved(), $environment . ' approved account policy.' );
	}
}

$environment = 'production';
$option      = 'yes';
$approval    = 'no';
$assert( ! UltraLove_Dropshipping::approved(), 'Enabled production still requires account approval.' );
$approval = 'yes';
$user_id  = 0;
$assert( ! UltraLove_Dropshipping::approved(), 'Guests cannot use dropshipping.' );

echo $checks . " environment and eligibility assertions passed.\n";
