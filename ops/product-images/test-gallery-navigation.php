<?php
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
	throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
$hooks = array();
$mode = 'cloudflare-images';
$admin = false;
$cron = false;
$grid_context = 'shop';
function add_filter( $name, $callback, $priority = 10, $arguments = 1 ) { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function add_action( $name, $callback, $priority = 10, $arguments = 1 ) { add_filter( $name, $callback, $priority, $arguments ); }
function get_option( $name, $default = false ) { return $name === 'ul_product_cdn' ? $GLOBALS['mode'] : $default; }
function is_admin() { return $GLOBALS['admin']; }
function is_user_logged_in() { return false; }
function wp_doing_cron() { return $GLOBALS['cron']; }
function is_shop() { return $GLOBALS['grid_context'] === 'shop'; }
function is_product_taxonomy() { return $GLOBALS['grid_context'] === 'taxonomy'; }
function is_search() { return $GLOBALS['grid_context'] === 'search'; }
function is_front_page() { return $GLOBALS['grid_context'] === 'front'; }
function is_home() { return $GLOBALS['grid_context'] === 'home'; }
function apply_filters( $name, $value ) { return $value; }
function get_post_meta( $id, $key, $single = false ) { return $key === '_wp_attached_file' ? 'fixture.jpg' : ''; }
function wp_get_attachment_metadata( $id ) { return array( 'width' => 1000, 'height' => 800 ); }
function get_post_mime_type( $id ) { return 'image/jpeg'; }
function wp_get_upload_dir() { return array( 'baseurl' => 'https://ultralove.ca/wp-content/uploads' ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url( $path ) { return 'https://ultralove.ca' . $path; }
function ul_cf_images_url( $id, $options ) { return 'https://imagedelivery.net/fixture/account/width=' . $options['width'] . ',height=' . ( $options['height'] ?? 0 ); }
function ul_cf_images_is_url( $url ) { return str_starts_with( $url, 'https://imagedelivery.net/fixture/' ); }
require __DIR__ . '/../../wp-content/mu-plugins/38-product-images-cloudflare.php';
require __DIR__ . '/../../wp-content/mu-plugins/30-woocommerce-loop-image-sizes.php';
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
foreach ( array( 'cloudflare', 'cloudflare-images' ) as $delivery_mode ) {
	$mode = $delivery_mode;
	foreach ( array( 'shop_catalog', 'woocommerce_thumbnail' ) as $size ) {
		$candidates = ul_pcdn_candidates( 1, ul_pcdn_profile_for_size( $size ) );
		$src = $hooks['wp_get_attachment_image_src'][99][0]( array( 'original.jpg', 1000, 800, false ), 1, $size, false );
		$assert( $src === array( $candidates[200]['url'], 200, 200, false ), 'Frontend card src must use the 200-pixel square variant in both delivery modes and size aliases.' );
		$attributes = $hooks['wp_get_attachment_image_attributes'][20][0](
			array( 'src' => $src[0], 'sizes' => 'auto, 100vw', 'loading' => 'lazy' ), (object) array( 'ID' => 1 ), $size
		);
		$expected_srcset = $candidates[200]['url'] . ' 200w, ' . $candidates[400]['url'] . ' 400w';
		$assert( $attributes['srcset'] === $expected_srcset, 'Rendered card srcset must contain exactly the 200w and 400w variants.' );
		$assert( $attributes['sizes'] === 'auto, (max-width: 575px) min(200px, calc(50vw - 50px)), 200px'
				&& $attributes['loading'] === 'lazy', 'Lazy cards must use their rendered width and retain the older-browser layout hint.' );
		foreach ( array( 'lazy', 'eager', '' ) as $loading ) {
			$expected_sizes = ( $loading === 'lazy' ? 'auto, ' : '' ) . ul_pcdn_profile_for_size( $size )['attr'];
			foreach ( array( '', '100vw', 'auto, 100vw' ) as $incoming_sizes ) {
				$attributes = $hooks['wp_get_attachment_image_attributes'][20][0](
					array( 'src' => $src[0], 'sizes' => $incoming_sizes, 'loading' => $loading ),
					(object) array( 'ID' => 1 ), $size
				);
				$assert( $attributes['sizes'] === $expected_sizes && $attributes['loading'] === $loading,
					'Auto sizing must depend on native lazy loading, not on whether core previously added auto.' );
			}
			$lazy_tag = $hooks['cloudinary_pre_image_tag'][30][0]( array( 'atts' => array(
				'src' => 'https://res.cloudinary.com/fixture/image.jpg', 'width' => 1000, 'height' => 1000,
				'loading' => $loading,
			) ), 1 );
			$assert( $lazy_tag['atts']['sizes'] === $expected_sizes,
				'Cloudinary safety-net cards must share the native lazy sizing rule.' );
		}
		$tag = $hooks['cloudinary_pre_image_tag'][30][0]( array( 'atts' => array(
			'src' => 'https://res.cloudinary.com/fixture/image.jpg', 'width' => 1000, 'height' => 1000,
		) ), 1 );
		$assert( $tag['atts']['srcset'] === $expected_srcset && $tag['atts']['src'] === $src[0]
			&& $tag['atts']['width'] === 200 && $tag['atts']['height'] === 200,
			'Cloudinary safety-net markup must use the same 200/400-pixel card variants.' );
	}
	$assert( ul_pcdn_profile_for_size( 'woocommerce_single' )['widths'] === array( 600, 900, 1200 )
		&& ul_pcdn_profile_for_size( 'woocommerce_gallery_thumbnail' )['widths'] === array( 100, 200 )
		&& ul_pcdn_profile_for_size( 'full' )['widths'] === array( 1600 ),
		'Gallery, navigation and zoom widths must remain unchanged.' );
}
$mode = 'cloudinary';
$assert( $filter( $requested ) === $requested, 'Cloudinary rollback must retain its original size request.' );
$mode = 'cloudflare-images';
$admin = true;
$assert( $filter( $requested ) === $requested, 'Admin must retain its original size request.' );
$admin = false;
$cron = true;
$assert( $filter( $requested ) === $requested, 'Cron must retain its original size request.' );
$cron = false;
$sizes_filter = $hooks['wp_calculate_image_sizes'][10][0];
$cloudinary_filter = $hooks['cloudinary_pre_image_tag'][10][0];
$fallback_sizes = ul_pcdn_profile_for_size( 'shop_catalog' )['attr'];
foreach ( array( 'shop', 'taxonomy', 'search', 'front', 'home' ) as $context ) {
	$grid_context = $context;
	$assert( $sizes_filter( '100vw', array( 1000, 1000 ) ) === $fallback_sizes,
		'Core card hints must match the capped Cloudflare profile in every grid context.' );
	foreach ( array( 'lazy', 'eager' ) as $loading ) {
		$tag = $cloudinary_filter( array( 'atts' => array(
			'width' => 1000, 'height' => 1000, 'srcset' => 'fixture.jpg 1000w', 'loading' => $loading,
		) ) );
		$assert( $tag['atts']['sizes'] === ( $loading === 'lazy' ? 'auto, ' : '' ) . $fallback_sizes,
			'Cloudinary rollback must share the cap and preserve native lazy auto sizing.' );
	}
}
$grid_context = '';
$assert( $sizes_filter( '100vw', array( 1000, 1000 ) ) === '100vw',
	'Non-grid core image hints must remain unchanged.' );
$non_card = array( 'atts' => array( 'width' => 1000, 'height' => 800, 'srcset' => 'fixture.jpg 1000w' ) );
$grid_context = 'shop';
$assert( $cloudinary_filter( $non_card ) === $non_card,
	'Non-square Cloudinary images must retain their original attributes.' );
define( 'REST_REQUEST', true );
$assert( $filter( $requested ) === $requested, 'REST must retain its original size request.' );
echo $checks . " product-card, gallery-navigation frontend and context assertions passed.\n";
