<?php
/**
 * Plugin Name: UltraLove Product CSV Export
 * Description: Standard retailer and Shopify product CSV generation, automatic updates and export status in one admin page.
 * Version: 1.1.0
 * Author: UltraLove
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class UltraLove_Product_Export_Sync {
    const AS_HOOK           = 'ultralove_export_sync_run';
    const AS_GROUP          = 'ultralove_exports';
    const WP_SINGLE_HOOK    = 'ultralove_export_sync_wp_single';
    const WP_RECURRING_HOOK = 'ultralove_export_sync_wp_recurring';
    const LOCK_KEY          = 'ultralove_export_sync_lock';
    const QUEUE_SOURCE_KEY  = 'ultralove_export_sync_source';
    const STATUS_OPTION     = 'ultralove_export_sync_status';
    const REPAIR_OPTION     = 'ultralove_export_sync_cron_repaired';
    const DEBOUNCE_SECONDS  = 60;
    const LEGACY_HOURLY_HOOK = 'ultralove_refresh_product_exports_hourly';

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_interval' ) );
        add_action( 'init', array( __CLASS__, 'maybe_repair_schedules' ), 20 );
        add_action( 'init', array( __CLASS__, 'clear_legacy_schedules' ), 25 );

        add_action( self::AS_HOOK, array( __CLASS__, 'run_export_job' ), 10, 1 );
        add_action( self::WP_SINGLE_HOOK, array( __CLASS__, 'run_export_job' ), 10, 1 );
        add_action( self::WP_RECURRING_HOOK, array( __CLASS__, 'run_export_job' ), 10, 1 );

        add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_export_for_change' ) );
        add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_export_for_change' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'queue_export_for_deleted_product' ) );

        add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'queue_export_for_stock_change' ) );
        add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'queue_export_for_stock_change' ) );
        add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue_export_for_stock_change' ) );
        add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue_export_for_stock_change' ) );

        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
    }

    public static function activate() {
        self::clear_hook_schedules( self::WP_SINGLE_HOOK );
        self::clear_hook_schedules( self::WP_RECURRING_HOOK );
        update_option( self::REPAIR_OPTION, '1', false );

        self::schedule_fallback_recurring();
        self::queue_export( 'activation', 10 );
    }

    public static function deactivate() {
        self::unschedule_all();
        delete_option( self::REPAIR_OPTION );
        delete_transient( self::QUEUE_SOURCE_KEY );
    }

    public static function register_cron_interval( $schedules ) {
        $schedules['ultralove_15min'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 Minutes (UltraLove Exports)',
        );

        return $schedules;
    }

    public static function queue_export_for_change() {
        self::queue_export( 'product_change', self::DEBOUNCE_SECONDS );
    }

    public static function queue_export_for_deleted_product( $post_id ) {
        if ( 'product' === get_post_type( $post_id ) || 'product_variation' === get_post_type( $post_id ) ) {
            self::queue_export( 'product_delete', self::DEBOUNCE_SECONDS );
        }
    }

    public static function queue_export_for_stock_change() {
        self::queue_export( 'stock_change', self::DEBOUNCE_SECONDS );
    }

    public static function queue_export( $source = 'manual', $delay = null ) {
        if ( null === $delay ) {
            $delay = self::DEBOUNCE_SECONDS;
        }

        $timestamp = time() + max( 0, (int) $delay );

        set_transient( self::QUEUE_SOURCE_KEY, sanitize_text_field( (string) $source ), 30 * MINUTE_IN_SECONDS );

        if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
            $has_scheduled = as_has_scheduled_action( self::AS_HOOK, array(), self::AS_GROUP );
            if ( ! $has_scheduled ) {
                as_schedule_single_action( $timestamp, self::AS_HOOK, array(), self::AS_GROUP, true );
            }
        } else {
            if ( ! wp_next_scheduled( self::WP_SINGLE_HOOK ) ) {
                wp_schedule_single_event( $timestamp, self::WP_SINGLE_HOOK );
            }
        }

        self::schedule_fallback_recurring();
    }

    public static function schedule_fallback_recurring() {
        self::clear_hook_schedules_with_args( self::WP_RECURRING_HOOK );

        if ( ! wp_next_scheduled( self::WP_RECURRING_HOOK ) ) {
            wp_schedule_event( time() + 300, 'ultralove_15min', self::WP_RECURRING_HOOK );
        }
    }

    public static function clear_legacy_schedules() {
        self::clear_hook_schedules( self::LEGACY_HOURLY_HOOK );
        self::clear_hook_schedules_with_args( self::WP_SINGLE_HOOK );
        self::clear_hook_schedules_with_args( self::WP_RECURRING_HOOK );
    }

    public static function maybe_repair_schedules() {
        if ( '1' === get_option( self::REPAIR_OPTION, '0' ) ) {
            return;
        }

        self::clear_hook_schedules( self::WP_SINGLE_HOOK );
        self::clear_hook_schedules( self::WP_RECURRING_HOOK );
        self::schedule_fallback_recurring();

        update_option( self::REPAIR_OPTION, '1', false );
    }

    private static function clear_hook_schedules( $hook ) {
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) || empty( $crons ) ) {
            return;
        }

        foreach ( $crons as $timestamp => $cron ) {
            if ( empty( $cron[ $hook ] ) || ! is_array( $cron[ $hook ] ) ) {
                continue;
            }

            foreach ( $cron[ $hook ] as $event ) {
                $args = array();
                if ( is_array( $event ) && isset( $event['args'] ) && is_array( $event['args'] ) ) {
                    $args = $event['args'];
                }

                wp_unschedule_event( (int) $timestamp, $hook, $args );
            }
        }
    }

    private static function clear_hook_schedules_with_args( $hook ) {
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) || empty( $crons ) ) {
            return;
        }

        foreach ( $crons as $timestamp => $cron ) {
            if ( empty( $cron[ $hook ] ) || ! is_array( $cron[ $hook ] ) ) {
                continue;
            }

            foreach ( $cron[ $hook ] as $event ) {
                if ( ! is_array( $event ) || ! isset( $event['args'] ) || ! is_array( $event['args'] ) || empty( $event['args'] ) ) {
                    continue;
                }

                wp_unschedule_event( (int) $timestamp, $hook, $event['args'] );
            }
        }
    }

    public static function unschedule_all() {
        self::clear_hook_schedules( self::LEGACY_HOURLY_HOOK );
        while ( $ts = wp_next_scheduled( self::WP_SINGLE_HOOK ) ) {
            wp_unschedule_event( $ts, self::WP_SINGLE_HOOK );
        }

        while ( $ts = wp_next_scheduled( self::WP_RECURRING_HOOK ) ) {
            wp_unschedule_event( $ts, self::WP_RECURRING_HOOK );
        }

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::AS_HOOK, array(), self::AS_GROUP );
        }
    }

    public static function run_export_job( $args = array() ) {
        if ( get_transient( self::LOCK_KEY ) ) {
            return;
        }

        set_transient( self::LOCK_KEY, '1', 5 * MINUTE_IN_SECONDS );

        $source = 'unknown';
        if ( is_array( $args ) && isset( $args['source'] ) ) {
            $source = sanitize_text_field( (string) $args['source'] );
        } elseif ( ( $queued_source = get_transient( self::QUEUE_SOURCE_KEY ) ) ) {
            $source = sanitize_text_field( (string) $queued_source );
        }

        $status = self::get_status();
        $start  = microtime( true );

        $status['last_started'] = current_time( 'mysql' );
        $status['last_source']  = $source;
        $status['last_error']   = '';
        self::save_status( $status );

        try {
            $rows_default = self::export_products_to_csv( 'default' );
            $rows_shopify = self::export_products_to_csv( 'shopify' );

            $duration = round( microtime( true ) - $start, 3 );

            $status['last_completed']     = current_time( 'mysql' );
            $status['last_duration_sec']  = $duration;
            $status['last_rows_default']  = $rows_default;
            $status['last_rows_shopify']  = $rows_shopify;
            $status['last_error']         = '';

            self::save_status( $status );
        } catch ( Exception $e ) {
            $status['last_error'] = $e->getMessage();
            self::save_status( $status );
        }

        delete_transient( self::LOCK_KEY );
    }

    public static function export_products_to_csv( $format = 'default' ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            throw new Exception( 'WooCommerce is not active.' );
        }

        $upload_dir = wp_upload_dir();
        $filename   = ( 'shopify' === $format ) ? 'ultralove-product-export-shopify.csv' : 'ultralove-product-export.csv';
        $final_path = trailingslashit( $upload_dir['basedir'] ) . $filename;
        $tmp_path   = $final_path . '.tmp';

        $csv_file = fopen( $tmp_path, 'w' );
        if ( ! $csv_file ) {
            throw new Exception( 'Unable to open temporary CSV file for writing: ' . $tmp_path );
        }

        $default_columns = array(
            'SKU',
            'Title',
            'Content',
            'Excerpt',
            'Regular Price',
            'Sale Price',
            'Stock',
            'Image URL',
            'Image Filename',
            'Additional Image URLs',
            'Categories',
            'Brands',
            'UPC',
        );

        $shopify_columns = array(
            'Handle',
            'Title',
            'Body (HTML)',
            'Variant SKU',
            'Variant Price',
            'Variant Compare At Price',
            'Variant Inventory Qty',
            'Image Src',
            'Image Alt Text',
            'Product Category',
            'Vendor',
            'Variant Barcode',
            'Status',
        );

        $columns = ( 'shopify' === $format ) ? $shopify_columns : $default_columns;
        fputcsv( $csv_file, $columns, ',', '"', '\\' );

        $page     = 1;
        $per_page = 200;
        $rows     = 0;

        do {
            $products = wc_get_products(
                array(
                    'status'  => 'publish',
                    'limit'   => $per_page,
                    'page'    => $page,
                    'orderby' => 'ID',
                    'order'   => 'ASC',
                    'return'  => 'objects',
                )
            );

            foreach ( $products as $product ) {
                if ( $product->is_type( 'variable' ) ) {
                    $variations = $product->get_children();

                    foreach ( $variations as $variation_id ) {
                        $variation = new WC_Product_Variation( $variation_id );

                        $line = array(
                            $variation->get_sku(),
                            ( 'shopify' === $format ) ? self::extract_shopify_title( $variation->get_name() ) : wp_strip_all_tags( $variation->get_name() ),
                            $variation->get_description(),
                            $variation->get_sku(),
                            $variation->get_regular_price(),
                            $variation->get_sale_price(),
                            self::normalize_stock_quantity( $variation->get_stock_quantity() ),
                            $variation->get_image_id() ? wp_get_attachment_url( $variation->get_image_id() ) : '',
                            $variation->get_image_id() ? basename( (string) wp_get_attachment_url( $variation->get_image_id() ) ) : '',
                            self::taxonomy_names( $product->get_id(), 'product_cat' ),
                            self::taxonomy_names( $product->get_id(), 'product_brand' ),
                            get_post_meta( $variation->get_id(), 'hwp_product_gtin', true ),
                        );

                        if ( 'default' === $format ) {
                            array_splice( $line, 9, 0, array( self::gallery_image_urls( $product ) ) );
                        }

                        if ( 'shopify' === $format ) {
                            $line[] = 'draft';
                        }

                        fputcsv( $csv_file, $line, ',', '"', '\\' );
                        $rows++;
                    }
                } else {
                    $line = array(
                        $product->get_sku(),
                        ( 'shopify' === $format ) ? self::extract_shopify_title( $product->get_name() ) : wp_strip_all_tags( $product->get_name() ),
                        $product->get_description(),
                        $product->get_sku(),
                        $product->get_regular_price(),
                        $product->get_sale_price(),
                        self::normalize_stock_quantity( $product->get_stock_quantity() ),
                        $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '',
                        $product->get_image_id() ? basename( (string) wp_get_attachment_url( $product->get_image_id() ) ) : '',
                        self::taxonomy_names( $product->get_id(), 'product_cat' ),
                        self::taxonomy_names( $product->get_id(), 'product_brand' ),
                        get_post_meta( $product->get_id(), 'hwp_product_gtin', true ),
                    );

                    if ( 'default' === $format ) {
                        array_splice( $line, 9, 0, array( self::gallery_image_urls( $product ) ) );
                    }

                    if ( 'shopify' === $format ) {
                        $line[] = 'draft';
                    }

                    fputcsv( $csv_file, $line, ',', '"', '\\' );
                    $rows++;

                    if ( 'shopify' === $format ) {
                        $gallery_image_ids = $product->get_gallery_image_ids();

                        if ( ! empty( $gallery_image_ids ) ) {
                            foreach ( $gallery_image_ids as $image_id ) {
                                $gallery_line    = array_fill( 0, count( $shopify_columns ), '' );
                                $gallery_line[0] = $product->get_sku();
                                $gallery_line[7] = wp_get_attachment_url( $image_id );

                                fputcsv( $csv_file, $gallery_line, ',', '"', '\\' );
                                $rows++;
                            }
                        }
                    }
                }
            }

            $page++;
        } while ( count( $products ) === $per_page );

        fclose( $csv_file );

        if ( ! @rename( $tmp_path, $final_path ) ) {
            if ( ! @copy( $tmp_path, $final_path ) ) {
                @unlink( $tmp_path );
                throw new Exception( 'Unable to move temporary CSV file into place: ' . $final_path );
            }
            @unlink( $tmp_path );
        }

        self::apply_file_permissions( $final_path );

        return $rows;
    }

    private static function apply_file_permissions( $file_path ) {
        if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
            return;
        }

        // Best-effort ownership alignment for web server access.
        @chown( $file_path, 'www-data' );
        @chgrp( $file_path, 'www-data' );
        @chmod( $file_path, 0664 );
    }

    private static function normalize_stock_quantity( $stock_quantity ) {
        return is_null( $stock_quantity ) ? '' : $stock_quantity;
    }

    private static function gallery_image_urls( $product ) {
        $urls = array();

        foreach ( $product->get_gallery_image_ids() as $image_id ) {
            $url = wp_get_attachment_url( $image_id );
            if ( $url ) {
                $urls[] = $url;
            }
        }

        return implode( ',', $urls );
    }

    private static function taxonomy_names( $product_id, $taxonomy ) {
        $terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return '';
        }

        return implode( ', ', $terms );
    }

    private static function extract_shopify_title( $product_title ) {
        $parts           = array_slice( explode( '<br>', (string) $product_title ), 1 );
        $cleaned_parts   = array_map( 'trim', $parts );
        $extracted_title = implode( ' ', $cleaned_parts );

        return wp_strip_all_tags( $extracted_title );
    }

    public static function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            'Product CSV Export',
            'Product CSV Export',
            'manage_woocommerce',
            'ultralove-export-sync',
            array( __CLASS__, 'render_admin_page' )
        );

        foreach ( array( 'ultralove-export-generations' => 'tools.php', 'am-export-products' => 'woocommerce' ) as $slug => $parent ) {
            $hook = add_submenu_page( $parent, 'Product CSV Export', 'Product CSV Export', 'manage_woocommerce', $slug, array( __CLASS__, 'render_admin_page' ) );
            remove_submenu_page( $parent, $slug );
            add_action( 'load-' . $hook, array( __CLASS__, 'redirect_legacy_page' ) );
        }
    }

    public static function redirect_legacy_page() {
        wp_safe_redirect( admin_url( 'admin.php?page=ultralove-export-sync' ) );
        exit;
    }

    public static function render_admin_page() {
        self::render_status_page( 'Product CSV Export' );
    }

    private static function render_status_page( $page_title ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( isset( $_POST['ultralove_export_sync_run_now'] ) ) {
            check_admin_referer( 'ultralove_export_sync_run_now' );
            self::queue_export( 'manual_admin', 1 );
            echo '<div class="notice notice-success"><p>Export job queued. Refresh this page shortly to see updated status.</p></div>';
        }

        $status      = self::get_status();
        $upload_dir  = wp_upload_dir();
        $default_csv = trailingslashit( $upload_dir['basedir'] ) . 'ultralove-product-export.csv';
        $shopify_csv = trailingslashit( $upload_dir['basedir'] ) . 'ultralove-product-export-shopify.csv';

        $next_wp = wp_next_scheduled( self::WP_RECURRING_HOOK );

        $next_as = '';
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            $next_timestamp = as_next_scheduled_action( self::AS_HOOK, array(), self::AS_GROUP );
            if ( is_numeric( $next_timestamp ) && $next_timestamp > 0 ) {
                $next_as = wp_date( 'Y-m-d H:i:s', (int) $next_timestamp );
            }
        }

        $default_info = self::get_file_info( $default_csv );
        $shopify_info = self::get_file_info( $shopify_csv );

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <p>Standard retailer and Shopify catalog CSVs update automatically when products and stock change. Use Queue Export Now to regenerate both files.</p>
            <p>
                <?php if ( file_exists( $default_csv ) ) : ?>
                    <a class="button" href="<?php echo esc_url( trailingslashit( $upload_dir['baseurl'] ) . 'ultralove-product-export.csv' ); ?>" download>Download Standard CSV</a>
                <?php endif; ?>
                <?php if ( file_exists( $shopify_csv ) ) : ?>
                    <a class="button" href="<?php echo esc_url( trailingslashit( $upload_dir['baseurl'] ) . 'ultralove-product-export-shopify.csv' ); ?>" download>Download Shopify CSV</a>
                <?php endif; ?>
            </p>

            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr>
                        <th style="width: 280px;">Last Start</th>
                        <td><?php echo esc_html( isset( $status['last_started'] ) ? $status['last_started'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Last Completion</th>
                        <td><?php echo esc_html( isset( $status['last_completed'] ) ? $status['last_completed'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Last Source</th>
                        <td><?php echo esc_html( isset( $status['last_source'] ) ? $status['last_source'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Duration (seconds)</th>
                        <td><?php echo esc_html( isset( $status['last_duration_sec'] ) ? (string) $status['last_duration_sec'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Rows Exported (Default)</th>
                        <td><?php echo esc_html( isset( $status['last_rows_default'] ) ? (string) $status['last_rows_default'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Rows Exported (Shopify)</th>
                        <td><?php echo esc_html( isset( $status['last_rows_shopify'] ) ? (string) $status['last_rows_shopify'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Next Action Scheduler Run</th>
                        <td><?php echo esc_html( $next_as ); ?></td>
                    </tr>
                    <tr>
                        <th>Next Fallback Cron Run</th>
                        <td><?php echo esc_html( $next_wp ? wp_date( 'Y-m-d H:i:s', $next_wp ) : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>Last Error</th>
                        <td><?php echo esc_html( isset( $status['last_error'] ) ? $status['last_error'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th>CSV Path (Default)</th>
                        <td><?php echo esc_html( $default_csv ); ?></td>
                    </tr>
                    <tr>
                        <th>Default CSV Generated</th>
                        <td><?php echo esc_html( $default_info['generated_at'] ); ?></td>
                    </tr>
                    <tr>
                        <th>Default CSV Size</th>
                        <td><?php echo esc_html( $default_info['size'] ); ?></td>
                    </tr>
                    <tr>
                        <th>Default CSV Owner/Group</th>
                        <td><?php echo esc_html( $default_info['owner_group'] ); ?></td>
                    </tr>
                    <tr>
                        <th>CSV Path (Shopify)</th>
                        <td><?php echo esc_html( $shopify_csv ); ?></td>
                    </tr>
                    <tr>
                        <th>Shopify CSV Generated</th>
                        <td><?php echo esc_html( $shopify_info['generated_at'] ); ?></td>
                    </tr>
                    <tr>
                        <th>Shopify CSV Size</th>
                        <td><?php echo esc_html( $shopify_info['size'] ); ?></td>
                    </tr>
                    <tr>
                        <th>Shopify CSV Owner/Group</th>
                        <td><?php echo esc_html( $shopify_info['owner_group'] ); ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" style="margin-top: 16px;">
                <?php wp_nonce_field( 'ultralove_export_sync_run_now' ); ?>
                <input type="submit" name="ultralove_export_sync_run_now" class="button button-primary" value="Queue Export Now" />
            </form>
        </div>
        <?php
    }

    private static function get_file_info( $path ) {
        if ( ! file_exists( $path ) ) {
            return array(
                'generated_at' => 'Missing',
                'size'         => 'Missing',
                'owner_group'  => 'Missing',
            );
        }

        $mtime       = filemtime( $path );
        $size_bytes  = filesize( $path );
        $owner_name  = function_exists( 'posix_getpwuid' ) ? @posix_getpwuid( (int) fileowner( $path ) ) : false;
        $group_name  = function_exists( 'posix_getgrgid' ) ? @posix_getgrgid( (int) filegroup( $path ) ) : false;
        $owner       = ( is_array( $owner_name ) && isset( $owner_name['name'] ) ) ? $owner_name['name'] : (string) fileowner( $path );
        $group       = ( is_array( $group_name ) && isset( $group_name['name'] ) ) ? $group_name['name'] : (string) filegroup( $path );

        return array(
            'generated_at' => $mtime ? wp_date( 'Y-m-d H:i:s', $mtime ) : 'Unknown',
            'size'         => size_format( $size_bytes ),
            'owner_group'  => $owner . ':' . $group,
        );
    }

    private static function get_status() {
        $defaults = array(
            'last_started'      => '',
            'last_completed'    => '',
            'last_source'       => '',
            'last_duration_sec' => '',
            'last_rows_default' => '',
            'last_rows_shopify' => '',
            'last_error'        => '',
        );

        $stored = get_option( self::STATUS_OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return array_merge( $defaults, $stored );
    }

    private static function save_status( $status ) {
        update_option( self::STATUS_OPTION, $status, false );
    }
}

UltraLove_Product_Export_Sync::init();

register_activation_hook( __FILE__, array( 'UltraLove_Product_Export_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UltraLove_Product_Export_Sync', 'deactivate' ) );
