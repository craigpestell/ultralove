<?php
/**
 * Offline hosted-image copy, identity, geometry and delivery regression tests.
 */
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
	throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
define( 'WP_CLI', true );
$hooks = array();
$options = array( 'ul_cf_images_account_hash' => 'fixture-account-hash', 'ul_product_cdn' => 'cloudflare', 'ub_banner_cdn' => 'cloudflare' );
$meta = array();
$files = array();
$dimensions = array();
$mimes = array();
$writes = 0;
$logged_in = false;
$admin = false;
$home = 'https://ultralove.ca';
$environment = 'production';
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function add_action( $name, $callback, $priority = 10, $args = 1 ) { add_filter( $name, $callback, $priority, $args ); }
function add_shortcode( $name, $callback ) {}
function apply_filters( $name, $value ) { return $value; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['options'][$name] = $value; return true; }
function get_post_meta( $id, $name, $single = false ) { return $GLOBALS['meta'][$id][$name] ?? ''; }
function update_post_meta( $id, $name, $value ) { $GLOBALS['meta'][$id][$name] = $value; ++$GLOBALS['writes']; return true; }
function delete_post_meta( $id, $name, $value = '' ) {
	if ( $value === '' || ( $GLOBALS['meta'][$id][$name] ?? '' ) === $value ) { unset( $GLOBALS['meta'][$id][$name] ); ++$GLOBALS['writes']; }
	return true;
}
function get_attached_file( $id, $unfiltered = false ) { return $GLOBALS['files'][$id] ?? false; }
function wp_get_attachment_metadata( $id ) { return $GLOBALS['dimensions'][$id] ?? array(); }
function get_post_mime_type( $id ) { return $GLOBALS['mimes'][$id] ?? ''; }
function get_post_type( $id ) { return isset( $GLOBALS['mimes'][$id] ) ? 'attachment' : false; }
function wp_get_post_parent_id( $id ) { return 0; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['directory'], 'baseurl' => 'https://ultralove.ca/wp-content/uploads', 'error' => false ); }
function wp_doing_cron() { return false; }
function is_admin() { return $GLOBALS['admin']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $permission ) { return $GLOBALS['logged_in']; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return $GLOBALS['home'] . $path; }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function wp_json_encode( $value ) { return json_encode( $value, JSON_THROW_ON_ERROR ); }
function plugin_dir_url( $path ) { return 'https://ultralove.ca/wp-content/plugins/ultralove-banners/'; }
function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
class WP_CLI {
	public static function add_command( $name, $callback ) {}
	public static function log( $message ) {}
	public static function error( $message ) { throw new RuntimeException( $message ); }
}
class WC_Product {
	public function get_image_id() { return 10; }
	public function get_gallery_image_ids() { return array( 11, 12 ); }
}
class FixtureImagesDatabase {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public string $query = '';
	public function prepare( $query, ...$arguments ) { return vsprintf( $query, $arguments ); }
	public function get_col( $query ) { $this->query = $query; return array( '1', '2' ); }
}
require __DIR__ . '/../../wp-content/mu-plugins/37-cloudflare-images.php';
require __DIR__ . '/../../wp-content/mu-plugins/38-product-images-cloudflare.php';
require __DIR__ . '/../../wp-content/plugins/ultralove-banners/ultralove-banners.php';
$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
};
$reject = static function ( callable $call, string $message ) use ( $assert ): void {
	try { $call(); } catch ( RuntimeException | InvalidArgumentException $error ) {
		$assert( $error->getMessage() !== '', $message );
		return;
	}
	throw new RuntimeException( 'Expected rejection: ' . $message );
};
$directory = sys_get_temp_dir() . '/ultralove-hosted-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
try {
	$files[1] = $directory . '/source.jpg';
	file_put_contents( $files[1], 'original fixture bytes' );
	$meta[1]['_wp_attached_file'] = 'source.jpg';
	$dimensions[1] = array( 'width' => 1000, 'height' => 800 );
	$mimes[1] = 'image/jpeg';
	$credentials = array( 'account_id' => str_repeat( 'a', 32 ), 'account_hash' => 'fixture-account-hash', 'token' => str_repeat( 'x', 40 ) );
	$sync = new UltraLove_CF_Images_Sync( $credentials );
	$wpdb = new FixtureImagesDatabase();
	$assert( UltraLove_CF_Images_Sync::ids( 5, 50, true ) === array( 1, 2 )
		&& str_contains( $wpdb->query, "m.meta_value<>''" ) && str_contains( $wpdb->query, 'p.ID>5' )
		&& str_contains( $wpdb->query, 'ORDER BY p.ID ASC LIMIT 50' ), 'Pending queue recognizes update generations and uses stable bounded cursors.' );
	$wpdb->last_error = 'fixture database failure';
	$reject( static fn() => UltraLove_CF_Images_Sync::ids( 0, 50 ), 'Database failures never appear as an empty successful queue.' );
	$wpdb->last_error = '';
	$source = $sync->source( 1 );
	$assert( $source['image_id'] === 'ultralove/' . hash_file( 'sha256', $files[1] ), 'Content-addressed IDs are deterministic.' );
	$assert( ! $sync->mapped( $source ), 'Uncopied images are explicitly pending.' );
	$before = $writes;
	$dry = $sync->sync( array( 1 ), true );
	$assert( $dry['pending'] === 1 && $dry['uploaded'] === 0 && $dry['dry_run'] && $writes === $before, 'Dry run neither uploads nor changes attachment metadata.' );
	$reply = array( 'status' => 200, 'data' => array( 'success' => true, 'result' => array(
		'id' => $source['image_id'], 'requireSignedURLs' => false, 'meta' => array( 'source_sha256' => $source['source_sha256'] ),
		'variants' => array( 'https://imagedelivery.net/fixture-account-hash/' . $source['image_id'] . '/public' ) ) ) );
	$sync->confirm( $source, $reply );
	$assert( $sync->mapped( $source ), 'Verified uploads save an exact source/account mapping.' );
	$meta[1]['_ul_cf_images_dirty'] = 'first-update';
	$previous = $sync->source( 1 );
	$meta[1]['_ul_cf_images_dirty'] = 'concurrent-update';
	$sync->confirm( $previous, $reply );
	$assert( $meta[1]['_ul_cf_images_dirty'] === 'concurrent-update', 'Confirmation cannot clear a newer media-update generation.' );
	$latest = $sync->source( 1 );
	$sync->confirm( $latest, $reply );
	$assert( ! isset( $meta[1]['_ul_cf_images_dirty'] ), 'Confirmation clears only its own completed generation.' );
	$again = $sync->sync( array( 1 ) );
	$assert( $again['unchanged'] === 1 && $again['uploaded'] === 0 && $again['pending'] === 0, 'Repeat synchronization requires no network and does not upload another image.' );
	$assert( file_get_contents( $files[1] ) === 'original fixture bytes', 'Upload confirmation never edits the local original.' );
	$url = ul_cf_images_url( 1, array( 'width' => 450, 'height' => 450, 'fit' => 'cover', 'gravity' => 'auto', 'quality' => 80 ) );
	$assert( str_starts_with( $url, 'https://imagedelivery.net/fixture-account-hash/' . $source['image_id'] . '/' ), 'Delivery uses the configured hosted account, not remote-source transformations.' );
	$assert( str_contains( $url, 'width=450,height=450,fit=cover,gravity=auto,quality=80' ), 'Existing crop, gravity and quality are retained.' );
	$assert( ul_cf_images_is_url( $url ) && ! ul_cf_images_is_url( str_replace( 'fixture-account-hash', 'other-account-hash', $url ) ), 'Hosted srcset recognition is account-bound.' );
	foreach ( array( array(), array( 'width' => -1 ), array( 'width' => '450' ), array( 'quality' => 101 ),
		array( 'trim.left' => -1 ), array( 'fit' => 'invalid' ), array( 'gravity' => 'center' ), array( 'url' => 'https://example.com' ) ) as $invalid ) {
		$reject( static fn() => ul_cf_images_url( 1, $invalid ), 'Invalid rendering options rejected.' );
	}
	$options['ul_product_cdn'] = 'cloudflare-images';
	$options['ub_banner_cdn'] = 'cloudflare-images';
	$assert( ! ul_pcdn_active(), 'WP-CLI and retailer-feed contexts retain their existing source behavior.' );
	$gallery_size_filter = $hooks['woocommerce_gallery_thumbnail_size'][20][0] ?? null;
	$assert( is_callable( $gallery_size_filter ) && $gallery_size_filter( array( 100, 100 ) ) === array( 100, 100 ),
		'Gallery navigation size hook is registered without changing CLI/feed image requests.' );
	$gallery_profile = ul_pcdn_profile_for_size( 'woocommerce_gallery_thumbnail' );
	$assert( $gallery_profile['name'] === 'thumb' && $gallery_profile['widths'] === array( 100, 200 )
		&& $gallery_profile['src'] === 100 && $gallery_profile['square'],
		'Named gallery navigation requests use the existing bounded square thumbnail profile.' );
	$assert( ! ul_pcdn_is_product_image( 11 ), 'Unattached image is not assumed to be a product image without context.' );
	$product = new WC_Product();
	$assert( ul_pcdn_is_product_image( 10 ) && ul_pcdn_is_product_image( 11 ) && ul_pcdn_is_product_image( 12 ),
		'Current product featured and gallery images support hosted zoom even without attachment parents or after an earlier negative lookup.' );
	$assert( ! ul_pcdn_is_product_image( 13 ), 'Current product context does not rewrite unrelated full-size images.' );
	$product = null;
	$card = ul_pcdn_candidates( 1, ul_pcdn_profile_for_size( 'woocommerce_thumbnail' ) );
	$assert( array_keys( $card ) === array( 300, 450, 600, 'src' ) && $card['src']['w'] === 450 && $card['src']['h'] === 450, 'Hosted card widths and square geometry match existing profiles.' );
	$assert( ul_cf_images_is_url( $card['src']['url'] ), 'Product candidates use stored originals.' );
	$dimensions[1] = array( 'width' => 200, 'height' => 150 );
	$small = ul_pcdn_candidates( 1, ul_pcdn_profile_for_size( 'woocommerce_thumbnail' ) );
	$assert( array_keys( $small ) === array( 150, 'src' ) && $small['src']['w'] === 150 && str_contains( $small['src']['url'], 'width=150' ), 'Small product images are clamped without upscaling or duplicate srcset widths.' );
	$dimensions[1] = array( 'width' => 1000, 'height' => 800 );
	$crop_url = ub_cf_image_url( 1, 600, 150, array( 0, 12, 800, 200 ) );
	$assert( str_contains( $crop_url, 'trim.left=0,trim.top=12,trim.width=800,trim.height=200' ), 'Banner saved crops remain pixel-exact.' );
	$banner = ub_apply_cloudflare( '<img class="ub-banner-img" src="old.jpg" width="1000" height="800">', 1,
		array( 'x' => 0, 'y' => 12, 'w' => 800, 'h' => 200 ), 1000, 800 );
	$assert( str_contains( $banner, 'imagedelivery.net' ) && str_contains( $banner, 'width="800" height="200"' )
		&& str_contains( $banner, 'data-ub-cdn="cloudflare"' ) && str_contains( $banner, 'srcset=' ), 'Banner markup, output-pass marker, geometry and responsive sources survive hosted delivery.' );
	$options['ul_product_cdn'] = 'cloudflare';
	$options['ub_banner_cdn'] = 'cloudflare';
	$assert( str_contains( ul_pcdn_url( 1, 450, 450 ), '/cdn-cgi/image/' ) && str_starts_with( ub_cf_image_url( 1, 600 ), '/cdn-cgi/image/' ), 'Existing transformation mode remains unchanged and is the rollback path.' );
	$_GET['ul_product_cdn'] = 'cloudflare-images';
	$assert( ul_pcdn_mode() === 'cloudflare', 'Anonymous visitors cannot enable a preview.' );
	$logged_in = true;
	$assert( ul_pcdn_mode() === 'cloudflare-images', 'Authorized editors can preview hosted delivery before cutover.' );
	$logged_in = false;
	$_GET = array();
	$options['ul_product_cdn'] = 'cloudflare-images';
	$meta[1]['_ul_cf_images_dirty'] = 1;
	$assert( ul_cf_images_url( 1, array( 'width' => 450 ) ) === '' && str_contains( ul_pcdn_url( 1, 450 ), '/cdn-cgi/image/' ), 'Pending changes are logged and retain the working delivery path.' );
	unset( $meta[1]['_ul_cf_images_dirty'] );
	$valid_mapping = $meta[1]['_ul_cf_images'];
	$meta[1]['_ul_cf_images']['image_id'] = array( 'malformed' );
	$assert( ul_cf_images_url( 1, array( 'width' => 450 ) ) === '', 'Corrupt mapping types use the logged fallback instead of crashing the page.' );
	$meta[1]['_ul_cf_images'] = $valid_mapping;
	$preview_id = 3;
	$files[$preview_id] = $directory . '/absent-preview.jpg';
	$meta[$preview_id]['_wp_attached_file'] = 'preview.jpg';
	$dimensions[$preview_id] = array( 'width' => 1000, 'height' => 800 );
	$mimes[$preview_id] = 'image/jpeg';
	$preview_mapping = array_replace( $valid_mapping, array( 'source_file' => 'preview.jpg',
		'source_width' => 1000, 'source_height' => 800, 'source_mime' => 'image/jpeg' ) );
	$meta[$preview_id]['_ul_cf_images_dev_preview'] = $preview_mapping;
	$assert( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) === '', 'Remote preview is disabled by default.' );
	$options['ul_cf_images_dev_remote_preview'] = true;
	$assert( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) === '', 'Production cannot use remote preview even when its option is enabled.' );
	$environment = 'development';
	$assert( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) === '', 'Remote preview is restricted to the exact dev hostname.' );
	$home = 'https://dev.ultralove.ca';
	$assert( ul_cf_images_is_url( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) ), 'Opted-in dev can deliver a source-identity-matched preview without copying originals.' );
	foreach ( array( 'account_hash' => 'other-account-hash', 'source_file' => 'different.jpg',
		'source_sha256' => str_repeat( '0', 64 ), 'source_width' => 999, 'source_height' => 799,
		'source_mime' => 'image/png' ) as $key => $value ) {
		$meta[$preview_id]['_ul_cf_images_dev_preview'] = array_replace( $preview_mapping, array( $key => $value ) );
		$assert( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) === '', 'Foreign or stale preview ' . $key . ' cannot deliver.' );
	}
	$meta[$preview_id]['_ul_cf_images_dev_preview'] = $preview_mapping;
	$meta[$preview_id]['_ul_cf_images_dirty'] = 'new-generation';
	$assert( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) === '', 'Dirty preview sources retain fallback.' );
	unset( $meta[$preview_id]['_ul_cf_images_dirty'] );
	$files[$preview_id] = $files[1];
	$assert( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) === '', 'A newly present local source cannot bypass normal local synchronization checks.' );
	$files[$preview_id] = $directory . '/absent-preview.jpg';
	$options['ul_cf_images_sync_enabled'] = false;
	ul_cf_images_mark_dirty( $preview_id );
	$assert( ! isset( $meta[$preview_id]['_ul_cf_images_dev_preview'] ), 'Media updates invalidate remote previews even when automatic uploads are disabled.' );
	$snapshot_path = $directory . '/preview-snapshot.json';
	$snapshot = array( 'version' => 1, 'source_host' => 'ultralove.ca', 'created_at' => 'fixture-time',
		'account_hash' => 'fixture-account-hash', 'records' => array( array_replace( $preview_mapping, array( 'id' => $preview_id ) ) ) );
	$import = static function ( $path ): void {
		$args = array( 'import', $path );
		require __DIR__ . '/dev-preview.php';
	};
	try {
		file_put_contents( $snapshot_path, wp_json_encode( $snapshot ) );
		$environment = 'production';
		$before = $meta;
		$reject( static fn() => $import( $snapshot_path ), 'Preview import is denied on production before any metadata changes.' );
		$assert( $meta === $before, 'Denied import preserves all attachment mappings.' );
		$environment = 'development';
		$import( $snapshot_path );
		$assert( ul_cf_images_is_url( ul_cf_images_url( $preview_id, array( 'width' => 450 ) ) )
			&& file_exists( $snapshot_path . '.before.json' ) && file_exists( $snapshot_path . '.result.json' ),
			'Matching preview import enables real delivery and persists rollback/report evidence.' );
		$before = $meta;
		$reject( static fn() => $import( $snapshot_path ), 'Repeat import cannot overwrite its recovery snapshot.' );
		$assert( $meta === $before, 'Refused repeat import does not overwrite metadata.' );
		$invalid_path = $directory . '/invalid-preview.json';
		$invalid_snapshot = $snapshot;
		$invalid_snapshot['records'][] = $snapshot['records'][0];
		file_put_contents( $invalid_path, wp_json_encode( $invalid_snapshot ) );
		$reject( static fn() => $import( $invalid_path ), 'Duplicate snapshot IDs fail before import.' );
		$invalid_snapshot = $snapshot;
		$invalid_snapshot['records'][0]['source_file'] = 'wrong-source.jpg';
		file_put_contents( $invalid_path, wp_json_encode( $invalid_snapshot ) );
		$reject( static fn() => $import( $invalid_path ), 'Unmatched dev filenames cannot enable an empty preview import.' );
		$assert( ! file_exists( $invalid_path . '.before.json' ) && $meta === $before,
			'Malformed or unmatched snapshots make no mapping writes.' );
	} finally {
		foreach ( array( $snapshot_path, $snapshot_path . '.before.json', $snapshot_path . '.result.json', $directory . '/invalid-preview.json' ) as $temporary ) {
			if ( is_file( $temporary ) ) { unlink( $temporary ); }
		}
	}
	unset( $options['ul_cf_images_dev_remote_preview'] );
	$home = 'https://ultralove.ca';
	$environment = 'production';
	foreach ( array( 'id' => 'another-image', 'requireSignedURLs' => true,
		'variants' => array( 'https://imagedelivery.net/another-account/' . $source['image_id'] . '/public' ),
		'meta' => array( 'source_sha256' => str_repeat( '0', 64 ) ) ) as $key => $value ) {
		$invalid = $reply;
		$invalid['data']['result'][$key] = $value;
		$before = $meta;
		$reject( static fn() => $sync->confirm( $source, $invalid ), 'Partial, foreign or mismatched uploads cannot commit mappings.' );
		$assert( $meta === $before, 'Rejected response preserves the prior mapping.' );
	}
	foreach ( array( 409, 429, 500 ) as $status ) {
		$invalid = $reply;
		$invalid['status'] = $status;
		$reject( static fn() => $sync->confirm( $source, $invalid ), 'HTTP errors cannot look like accepted uploads.' );
	}
	file_put_contents( $files[1], 'changed fixture bytes' );
	$reject( static fn() => $sync->confirm( $source, $reply ), 'A source change during upload cannot publish a stale mapping.' );
	$changed = $sync->source( 1 );
	$assert( $changed['image_id'] !== $source['image_id'], 'Replacing an original creates a new cache-safe identity.' );
	$mimes[1] = 'image/gif';
	$reject( static fn() => $sync->source( 1 ), 'Animated GIFs are preserved outside the still-image migration, never flattened.' );
	$mimes[1] = 'image/jpeg';
	$dimensions[1]['width'] = 12001;
	$reject( static fn() => $sync->source( 1 ), 'Hosted dimension limits enforced before upload.' );
	$dimensions[1]['width'] = 1000;
	$dimensions[1] = array( 'width' => 11000, 'height' => 11000 );
	$reject( static fn() => $sync->source( 1 ), 'Hosted 100 MP limit enforced independently of side lengths.' );
	$dimensions[1] = array( 'width' => 1000, 'height' => 800 );
	$outside = tempnam( sys_get_temp_dir(), 'ultralove-image-outside-' );
	file_put_contents( $outside, 'outside fixture' );
	try {
		symlink( $outside, $directory . '/outside.jpg' );
		$original_path = $files[1];
		$files[1] = $directory . '/outside.jpg';
		$reject( static fn() => $sync->source( 1 ), 'Symlinks outside uploads cannot expose other server files to Cloudflare.' );
		$files[1] = $outside;
		$reject( static fn() => $sync->source( 1 ), 'Direct paths outside uploads cannot expose other server files.' );
		$files[1] = $original_path;
	} finally {
		unlink( $directory . '/outside.jpg' );
		unlink( $outside );
	}
	$files[2] = $directory . '/missing.jpg';
	$meta[2]['_wp_attached_file'] = 'missing.jpg';
	$mimes[2] = 'image/jpeg';
	$failed = $sync->sync( array( 2 ), true );
	$assert( count( $failed['failed'] ) === 1 && $failed['failed'][0]['id'] === 2 && $failed['pending'] === 0, 'Missing originals are explicit failures, not successful migrations.' );
	$reject( static fn() => $sync->sync( array( 1, 1 ), true ), 'Duplicate attachment IDs rejected.' );
	$reject( static fn() => $sync->sync( array( '1' ), true ), 'Noninteger attachment IDs rejected.' );
	$reject( static fn() => new UltraLove_CF_Images_Sync( array_replace( $credentials, array( 'account_hash' => 'foreign-account-hash' ) ) ), 'Another Cloudflare account cannot reuse existing mappings.' );
	$options['ul_cf_images_sync_enabled'] = false;
	$before = $writes;
	ul_cf_images_mark_dirty( 1 );
	$assert( $writes === $before, 'Disabled synchronization does not change upload bookkeeping.' );
	$options['ul_cf_images_sync_enabled'] = true;
	ul_cf_images_mark_dirty( 1 );
	$generation = $meta[1]['_ul_cf_images_dirty'];
	$assert( is_string( $generation ) && strlen( $generation ) === 32, 'Future WordPress media replacements are queued for synchronization.' );
	ul_cf_images_mark_dirty( 1 );
	$assert( $meta[1]['_ul_cf_images_dirty'] !== $generation, 'Each media change has a distinct retry generation.' );
	echo $checks . " hosted-image migration and delivery assertions passed; no network or real credentials.\n";
} finally {
	if ( is_file( $directory . '/source.jpg' ) ) { unlink( $directory . '/source.jpg' ); }
	rmdir( $directory );
}
