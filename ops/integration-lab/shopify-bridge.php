<?php
/**
 * Dedicated lab entry point; the shared core is also deployed as supplier code.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}
$core = WP_PLUGIN_DIR . '/ultralove-shopify-supplier/includes/class-test-bridge.php';
if ( ! is_readable( $core ) ) {
    $core = __DIR__ . '/class-test-bridge.php';
}
require_once $core;
class_alias( UltraLove_Shopify_Test_Bridge::class, 'UltraLove_Lab_Shopify_Bridge' );
if ( empty( $GLOBALS['ul_lab_shopify_library_only'] ) ) {
    try {
        ( new UltraLove_Lab_Shopify_Bridge() )->run();
    } catch ( Throwable $error ) {
        WP_CLI::error( $error->getMessage() );
    }
}
