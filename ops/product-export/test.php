<?php
/**
 * Standalone catalog CSV regression tests: php ops/product-export/test.php.
 * WordPress/WooCommerce doubles never connect to a site or database.
 */
if ( defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI ) {
    throw new RuntimeException( 'Run this standalone test through PHP CLI.' );
}
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$directory = sys_get_temp_dir() . '/ultralove-csv-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
$checks = 0;
$hooks = $menus = $options = $transients = $cron = $product_queries = array();
$allowed = true;
$nonce_valid = true;
function add_action( $hook, $callback, ...$rest ) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function add_submenu_page( $parent, $title, $label, $capability, $slug, $callback ) {
    $GLOBALS['menus'][$parent][$slug] = array( $title, $capability, $callback );
    return $parent . '_page_' . $slug;
}
function remove_submenu_page( $parent, $slug ) { unset( $GLOBALS['menus'][$parent][$slug] ); }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['directory'], 'baseurl' => 'https://example.test/uploads' ); }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_get_attachment_url( $id ) { return 'https://example.test/uploads/' . $id . '.jpg'; }
function wp_get_post_terms( $id, $taxonomy, $args ) { return array( 'product_cat' === $taxonomy ? 'Category' : 'Brand' ); }
function is_wp_error( $value ) { return false; }
function get_post_meta( $id, $key, $single ) { return 'UPC-' . $id; }
function current_user_can( $capability ) { return $GLOBALS['allowed']; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="fixture">'; }
function check_admin_referer( $action ) {
    if ( ! $GLOBALS['nonce_valid'] || 'ultralove_export_sync_run_now' !== $action ) {
        throw new RuntimeException( 'Invalid nonce.' );
    }
}
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function wp_safe_redirect( $url ) { throw new RuntimeException( 'redirect:' . $url ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, ...$rest ) { $GLOBALS['options'][$key] = $value; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function current_time( $format ) { return '2026-10-09 12:00:00'; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function size_format( $bytes ) { return $bytes . ' bytes'; }
function _get_cron_array() { return $GLOBALS['cron']; }
function wp_next_scheduled( $hook ) {
    foreach ( $GLOBALS['cron'] as $timestamp => $events ) {
        if ( isset( $events[$hook][md5( serialize( array() ) )] ) ) { return $timestamp; }
    }
    return false;
}
function wp_unschedule_event( $timestamp, $hook, $args ) {
    unset( $GLOBALS['cron'][$timestamp][$hook][md5( serialize( $args ) )] );
    if ( empty( $GLOBALS['cron'][$timestamp][$hook] ) ) { unset( $GLOBALS['cron'][$timestamp][$hook] ); }
}
function wp_schedule_single_event( $timestamp, $hook ) { wp_schedule_event( $timestamp, '', $hook ); }
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
    $GLOBALS['cron'][$timestamp][$hook][md5( serialize( $args ) )] = array( 'args' => $args, 'schedule' => $recurrence );
}
function wc_get_products( $args ) {
    $GLOBALS['product_queries'][] = $args;
    return $GLOBALS['pages'][$args['page']] ?? array();
}
class WooCommerce {}
class CSV_Product {
    public function __construct( public int $id, public bool $variable = false ) {}
    public function get_id() { return $this->id; }
    public function is_type( $type ) { return $this->variable && 'variable' === $type; }
    public function get_children() { return array( 3 ); }
    public function get_sku() { return 'SKU-' . $this->id; }
    public function get_name() { return 'Brand<br>Product "' . $this->id . '", title'; }
    public function get_description() { return "<p>Description,\nwith quotes \"here\"</p>"; }
    public function get_regular_price() { return '20.00'; }
    public function get_sale_price() { return '15.00'; }
    public function get_stock_quantity() { return 3 === $this->id ? null : 0; }
    public function get_image_id() { return $this->id; }
    public function get_gallery_image_ids() { return array( 10, 11 ); }
}
class WC_Product_Variation extends CSV_Product {}

require __DIR__ . '/../../wp-content/plugins/ultralove-product-export-sync/ultralove-product-export-sync.php';
$assert = function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$read_csv = function ( $name ) use ( $directory ) {
    $stream = fopen( $directory . '/' . $name, 'r' );
    $rows = array();
    while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) { $rows[] = $row; }
    fclose( $stream );
    return $rows;
};

try {
    UltraLove_Product_Export_Sync::register_admin_page();
    $assert( count( $menus['woocommerce'] ) === 1 && isset( $menus['woocommerce']['ultralove-export-sync'] ), 'Exactly one visible catalog CSV submenu.' );
    $assert( empty( $menus['tools.php'] ), 'Duplicate Tools page is hidden.' );
    $assert( isset( $hooks['load-tools.php_page_ultralove-export-generations'], $hooks['load-woocommerce_page_am-export-products'] ), 'Both legacy pages retain redirect handlers.' );
    try {
        UltraLove_Product_Export_Sync::redirect_legacy_page();
        throw new RuntimeException( 'Expected redirect.' );
    } catch ( RuntimeException $error ) {
        $assert( $error->getMessage() === 'redirect:https://example.test/wp-admin/admin.php?page=ultralove-export-sync', 'Legacy pages redirect to unified controls.' );
    }
    $pages = array( 1 => array( new CSV_Product( 1 ), new CSV_Product( 2, true ) ) );
    $assert( UltraLove_Product_Export_Sync::export_products_to_csv() === 2, 'Standard CSV includes simple products and variations.' );
    $standard = $read_csv( 'ultralove-product-export.csv' );
    $assert( $standard[0] === array( 'SKU', 'Title', 'Content', 'Excerpt', 'Regular Price', 'Sale Price', 'Stock', 'Image URL', 'Image Filename', 'Additional Image URLs', 'Categories', 'Brands', 'UPC' ), 'Existing standard columns and order preserved.' );
    foreach ( $standard as $row ) { $assert( count( $row ) === 13, 'All standard rows match header width.' ); }
    $assert( $standard[1][2] === $pages[1][0]->get_description(), 'CSV preserves commas, quotes and multiline content.' );
    $assert( $standard[1][6] === '0' && $standard[2][6] === '', 'Zero stock and unmanaged stock remain distinct.' );
    $assert( $standard[2][9] === 'https://example.test/uploads/10.jpg,https://example.test/uploads/11.jpg', 'Variation row retains parent gallery.' );
    $assert( UltraLove_Product_Export_Sync::export_products_to_csv( 'shopify' ) === 4, 'Shopify CSV retains gallery-only rows.' );
    $shopify = $read_csv( 'ultralove-product-export-shopify.csv' );
    $assert( $shopify[0] === array( 'Handle', 'Title', 'Body (HTML)', 'Variant SKU', 'Variant Price', 'Variant Compare At Price', 'Variant Inventory Qty', 'Image Src', 'Image Alt Text', 'Product Category', 'Vendor', 'Variant Barcode', 'Status' ), 'Existing Shopify columns preserved.' );
    foreach ( $shopify as $row ) { $assert( count( $row ) === 13, 'All Shopify rows match header width.' ); }
    $assert( $shopify[1][12] === 'draft' && $shopify[4][12] === 'draft', 'Simple and variation Shopify rows stay drafts.' );
    $assert( $shopify[2][0] === 'SKU-1' && $shopify[2][7] === 'https://example.test/uploads/10.jpg' && $shopify[2][1] === '', 'Gallery rows retain product handle and image.' );
    $assert( ! file_exists( $directory . '/ultralove-product-export.csv.tmp' ), 'Temporary CSV is moved into place.' );
    $pages = array( 1 => array_fill( 0, 200, new CSV_Product( 1 ) ), 2 => array( new CSV_Product( 4 ) ) );
    $product_queries = array();
    $assert( UltraLove_Product_Export_Sync::export_products_to_csv() === 201, 'Catalog generation visits subsequent pages.' );
    $assert( count( $product_queries ) === 2 && $product_queries[1]['page'] === 2 && $product_queries[0]['limit'] === 200 && $product_queries[0]['status'] === 'publish', 'Published catalog remains paginated at 200 per query.' );
    wp_schedule_event( 100, 'hourly', UltraLove_Product_Export_Sync::LEGACY_HOURLY_HOOK );
    wp_schedule_event( 101, 'hourly', UltraLove_Product_Export_Sync::LEGACY_HOURLY_HOOK, array( 'legacy' ) );
    wp_schedule_event( 102, 'hourly', 'stock_export_unrelated' );
    UltraLove_Product_Export_Sync::clear_legacy_schedules();
    $assert( ! wp_next_scheduled( UltraLove_Product_Export_Sync::LEGACY_HOURLY_HOOK ) && empty( $cron[101] ), 'Obsolete hourly schedules are removed, including argument variants.' );
    wp_schedule_event( 103, 'hourly', UltraLove_Product_Export_Sync::LEGACY_HOURLY_HOOK, array( 'args_only' ) );
    UltraLove_Product_Export_Sync::clear_legacy_schedules();
    $assert( empty( $cron[103] ), 'Argument-only legacy schedules are removed without a no-argument event.' );
    $assert( wp_next_scheduled( 'stock_export_unrelated' ) === 102, 'Other export schedules are untouched.' );
    $allowed = false;
    ob_start();
    UltraLove_Product_Export_Sync::render_admin_page();
    $assert( ob_get_clean() === '', 'Unauthorized user cannot render export controls.' );
    $allowed = true;
    $_POST['ultralove_export_sync_run_now'] = '1';
    $nonce_valid = false;
    try {
        UltraLove_Product_Export_Sync::render_admin_page();
        throw new RuntimeException( 'Expected nonce rejection.' );
    } catch ( RuntimeException $error ) {
        $assert( $error->getMessage() === 'Invalid nonce.', 'Manual generation rejects an invalid nonce.' );
    }
    $nonce_valid = true;
    ob_start();
    UltraLove_Product_Export_Sync::render_admin_page();
    $html = ob_get_clean();
    $assert( wp_next_scheduled( UltraLove_Product_Export_Sync::WP_SINGLE_HOOK ) !== false, 'Manual generation queues the background writer.' );
    $assert( str_contains( $html, 'Download Standard CSV' ) && str_contains( $html, 'Download Shopify CSV' ) && str_contains( $html, 'Queue Export Now' ), 'One page exposes both downloads, regeneration and status.' );
    unset( $_POST['ultralove_export_sync_run_now'] );
    UltraLove_Product_Export_Sync::run_export_job();
    $status = $options[UltraLove_Product_Export_Sync::STATUS_OPTION];
    $assert( $status['last_rows_default'] === 201 && $status['last_rows_shopify'] === 603 && $status['last_error'] === '', 'Background job updates both CSVs and shared status.' );
    $assert( ! get_transient( UltraLove_Product_Export_Sync::LOCK_KEY ), 'Background job releases its lock.' );
    $theme = file_get_contents( __DIR__ . '/../../wp-content/themes/martfury-child/functions.php' );
    $assert( ! str_contains( $theme, 'function ultralove_export_products_to_csv' ) && ! str_contains( $theme, 'am_add_export_page' ), 'Theme no longer owns a duplicate catalog writer or menu.' );
    $assert( str_contains( $theme, "add_shortcode('exportstock'" ) && str_contains( $theme, 'ultralove-product-export-shopify.csv' ), 'Separate stock shortcode and retailer download links remain intact.' );
    echo "PASS: $checks catalog CSV assertions.\n";
} finally {
    foreach ( array( 'ultralove-product-export.csv', 'ultralove-product-export.csv.tmp', 'ultralove-product-export-shopify.csv', 'ultralove-product-export-shopify.csv.tmp' ) as $name ) {
        $path = $directory . '/' . $name;
        if ( file_exists( $path ) ) { unlink( $path ); }
    }
    rmdir( $directory );
}
