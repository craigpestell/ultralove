<?php
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
	throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
$hooks = array();
$mode = 'cloudflare-images';
$admin = false;
$cron = false;
function add_filter( $name, $callback, $priority = 10, $arguments = 1 ) { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function add_action( $name, $callback, $priority = 10, $arguments = 1 ) { add_filter( $name, $callback, $priority, $arguments ); }
function get_option( $name, $default = false ) { return $name === 'ul_product_cdn' ? $GLOBALS['mode'] : $default; }
function is_admin() { return $GLOBALS['admin']; }
function is_user_logged_in() { return false; }
function wp_doing_cron() { return $GLOBALS['cron']; }
function apply_filters( $name, $value ) { return $value; }
require __DIR__ . '/../../wp-content/mu-plugins/38-product-images-cloudflare.php';
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
};
$filter = $hooks['woocommerce_gallery_thumbnail_size'][20][0];
$requested = array( 100, 100 );
$assert( $filter( $requested ) === 'woocommerce_gallery_thumbnail', 'Hosted frontend must convert array-sized gallery navigation to the named profile.' );
$profile = ul_pcdn_profile_for_size( $filter( $requested ) );
$assert( $profile['name'] === 'thumb' && $profile['src'] === 100 && $profile['square'], 'Gallery navigation must use the 100-pixel square profile.' );
$mode = 'cloudflare';
$assert( $filter( $requested ) === 'woocommerce_gallery_thumbnail', 'Remote-source Cloudflare mode must share the gallery navigation fix.' );
$mode = 'cloudinary';
$assert( $filter( $requested ) === $requested, 'Cloudinary rollback must retain its original size request.' );
$mode = 'cloudflare-images';
$admin = true;
$assert( $filter( $requested ) === $requested, 'Admin must retain its original size request.' );
$admin = false;
$cron = true;
$assert( $filter( $requested ) === $requested, 'Cron must retain its original size request.' );
$cron = false;
define( 'REST_REQUEST', true );
$assert( $filter( $requested ) === $requested, 'REST must retain its original size request.' );
echo $checks . " gallery-navigation frontend and context assertions passed.\n";
