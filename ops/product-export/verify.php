<?php
/**
 * Dev-only verification against existing catalog products; creates no fixtures.
 * Run: wp eval-file - --allow-root < ops/product-export/verify.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
    || 'development' !== wp_get_environment_type()
    || home_url() !== 'https://dev.ultralove.ca'
    || ! class_exists( 'UltraLove_Product_Export_Sync' ) ) {
    throw new RuntimeException( 'Run through WP-CLI on dev with Product CSV Export active.' );
}

$checks = 0;
$assert = function ( $condition, $message ) use ( &$checks ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
    ++$checks;
};
$previous_user = get_current_user_id();
$previous_post = $_POST;

try {
    $administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
    $assert( ! empty( $administrators ), 'An existing dev administrator is required.' );
    wp_set_current_user( (int) $administrators[0] );
    $_POST = array();
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    UltraLove_Product_Export_Sync::register_admin_page();
    global $submenu;
    $catalog_menus = array_filter( $submenu['woocommerce'], function ( $entry ) {
        return in_array( $entry[2], array( 'ultralove-export-sync', 'am-export-products', 'ultralove-export-generations' ), true );
    } );
    $assert( count( $catalog_menus ) === 1, 'Exactly one visible catalog export submenu.' );
    foreach ( array( 'ultralove-export-generations' => 'tools.php', 'am-export-products' => 'woocommerce' ) as $slug => $parent ) {
        $hook = get_plugin_page_hook( $slug, $parent );
        $assert( $hook && has_action( 'load-' . $hook, array( 'UltraLove_Product_Export_Sync', 'redirect_legacy_page' ) ) !== false, 'Legacy page has its real WordPress redirect handler: ' . $slug );
    }
    $assert( ! function_exists( 'ultralove_export_products_to_csv' ) && ! function_exists( 'am_add_export_page' ), 'No duplicate theme writer or menu remains.' );
    $assert( shortcode_exists( 'exportstock' ) && class_exists( 'UltraLove_Product_Stock_Export' ), 'Distinct stock shortcode and plugin remain available.' );
    $assert( class_exists( 'UltraLove_Product_Feed' ) && class_exists( 'UltraLove_Dropshipping' ) && class_exists( 'UltraLove_Shopify_Supplier' ), 'Current feed and dropshipping plugins remain loaded.' );

    $assert( ! get_transient( UltraLove_Product_Export_Sync::LOCK_KEY ), 'No catalog export is currently running.' );
    UltraLove_Product_Export_Sync::run_export_job( array( 'source' => 'dev_consolidation_verification' ) );
    $status = get_option( UltraLove_Product_Export_Sync::STATUS_OPTION );
    $assert( $status['last_source'] === 'dev_consolidation_verification' && $status['last_error'] === '', 'Unified background job completes without error.' );
    $uploads = wp_upload_dir();
    foreach ( array( 'default' => 'ultralove-product-export.csv', 'shopify' => 'ultralove-product-export-shopify.csv' ) as $format => $filename ) {
        $path = trailingslashit( $uploads['basedir'] ) . $filename;
        $stream = fopen( $path, 'r' );
        if ( false === $stream ) {
            throw new RuntimeException( 'Missing generated CSV: ' . $filename );
        }
        $rows = 0;
        try {
            $header = fgetcsv( $stream, 0, ',', '"', '\\' );
            $assert( is_array( $header ) && count( $header ) === 13, 'Expected 13-column schema: ' . $filename );
            while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) {
                if ( count( $row ) !== count( $header ) ) {
                    throw new RuntimeException( 'Malformed row in ' . $filename );
                }
                if ( 'shopify' === $format && '' !== $row[1] && 'draft' !== $row[12] ) {
                    throw new RuntimeException( 'Shopify product row is not a draft.' );
                }
                ++$rows;
            }
        } finally {
            fclose( $stream );
        }
        $assert( $rows > 0 && $rows === (int) $status['last_rows_' . $format], 'Live row count matches job status: ' . $filename );
        $assert( ! file_exists( $path . '.tmp' ), 'No temporary CSV remains: ' . $filename );
        WP_CLI::log( $filename . ': ' . $rows . ' rows, sha256=' . hash_file( 'sha256', $path ) );
    }
    UltraLove_Product_Export_Sync::clear_legacy_schedules();
    $legacy_events = array();
    foreach ( _get_cron_array() as $events ) {
        if ( isset( $events[UltraLove_Product_Export_Sync::LEGACY_HOURLY_HOOK] ) ) {
            $legacy_events[] = $events[UltraLove_Product_Export_Sync::LEGACY_HOURLY_HOOK];
        }
    }
    $assert( empty( $legacy_events ), 'Obsolete hourly events are removed.' );
    $assert( wp_next_scheduled( UltraLove_Product_Export_Sync::WP_RECURRING_HOOK ) !== false, 'Fallback export schedule remains available.' );
    ob_start();
    UltraLove_Product_Export_Sync::render_admin_page();
    $html = ob_get_clean();
    $assert( str_contains( $html, 'Download Standard CSV' ) && str_contains( $html, 'Download Shopify CSV' ) && str_contains( $html, 'Queue Export Now' ), 'Unified page exposes both files and manual regeneration.' );
    WP_CLI::success( "$checks live catalog CSV checks passed. No source products, inventory, users or orders edited." );
} finally {
    $_POST = $previous_post;
    wp_set_current_user( $previous_user );
}
