<?php
/**
 * Plugin Name: UltraLove Product Feed
 * Description: Read-only, authenticated supplier catalog and inventory API for development.
 * Version: 0.1.0
 * Author: UltraLove
 * Requires Plugins: woocommerce
 * Text Domain: ultralove-product-feed
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/includes/class-feed-journal.php';

final class UltraLove_Product_Feed {
    const NAMESPACE = 'ultralove-feed/v1';
    const CAPABILITY = 'ultralove_read_product_feed';
    const PAGE_SIZE = 100;
    const REQUESTS_PER_MINUTE = 300;

    public static function supported(): bool {
        return wp_get_environment_type() === 'development'
            && home_url() === 'https://dev.ultralove.ca' && DB_NAME === 'wordpress_dev';
    }

    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'headers' ), 10, 3 );
        add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
        foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'save_post_product', 'save_post_product_variation' ) as $hook ) {
            add_action( $hook, array( 'UltraLove_Feed_Journal', 'product_changed' ) );
        }
        foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
            add_action( $hook, static function ( $product ): void { UltraLove_Feed_Journal::product_changed( $product->get_id() ); } );
        }
        add_action( 'transition_post_status', array( 'UltraLove_Feed_Journal', 'status_changed' ), 10, 3 );
        add_action( 'before_delete_post', array( 'UltraLove_Feed_Journal', 'deleted' ), 10, 2 );
        foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
            add_action( $hook, array( 'UltraLove_Feed_Journal', 'meta_changed' ), 10, 4 );
        }
        add_action( 'set_object_terms', array( 'UltraLove_Feed_Journal', 'terms_changed' ), 10, 4 );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'ultralove feed reconcile', static function ( $args, $options ): void {
                try {
                    if ( ! self::supported() ) {
                        throw new RuntimeException( 'Product feed is restricted to the dev supplier.' );
                    }
                    $size = $options['batch-size'] ?? '500';
                    if ( ! ctype_digit( (string) $size ) || (int) $size < 1 || (int) $size > 1000 ) {
                        throw new RuntimeException( 'Batch size must be between 1 and 1000.' );
                    }
                    $count = UltraLove_Feed_Journal::reconcile( (int) $size );
                    WP_CLI::success( 'Feed reconciliation processed ' . $count . ' records; cursor ' . UltraLove_Feed_Journal::publish() . '.' );
                } catch ( RuntimeException $error ) {
                    self::log_error( $error->getMessage() );
                    WP_CLI::error( $error->getMessage() );
                }
            } );
        }
    }

    public static function activate(): void {
        if ( ! self::supported() ) {
            wp_die( 'The supplier feed can only be activated on dev.ultralove.ca.', '', array( 'response' => 403 ) );
        }
        UltraLove_Feed_Journal::install();
    }

    public static function permissions() {
        if ( ! self::supported() || ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'feed_unavailable', 'Supplier feed is unavailable.', array( 'status' => 503 ) );
        }
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'feed_authentication_required', 'Authenticate with a reseller application password.', array( 'status' => 401 ) );
        }
        if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error( 'feed_forbidden', 'This account does not have catalog API access.', array( 'status' => 403 ) );
        }
        try {
            if ( ! UltraLove_Feed_Journal::allow_request( get_current_user_id(), self::REQUESTS_PER_MINUTE ) ) {
                return new WP_Error( 'feed_rate_limit', 'Request limit reached. Retry in the next minute.', array( 'status' => 429 ) );
            }
        } catch ( RuntimeException $error ) {
            self::log_error( $error->getMessage() );
            return new WP_Error( 'feed_unavailable', 'Supplier feed is temporarily unavailable.', array( 'status' => 503 ) );
        }
        return true;
    }

    public static function routes(): void {
        if ( ! self::supported() ) {
            return;
        }
        $paging = array(
            'per_page' => array( 'type' => 'integer', 'default' => self::PAGE_SIZE, 'minimum' => 1, 'maximum' => self::PAGE_SIZE ),
            'after_id' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
            'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'maxItems' => self::PAGE_SIZE ),
        );
        foreach ( array( '/products', '/inventory', '/products/(?P<id>\d+)', '/changes', '/openapi' ) as $path ) {
            $args = $paging;
            if ( $path === '/changes' ) {
                $args = array(
                    'per_page' => $paging['per_page'],
                    'after' => array( 'type' => 'integer', 'required' => true, 'minimum' => 0 ),
                    'generation' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 36 ),
                );
            } elseif ( $path === '/products/(?P<id>\d+)' ) {
                $args = array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) );
            } elseif ( $path === '/openapi' ) {
                $args = array();
            }
            register_rest_route( self::NAMESPACE, $path, array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array( __CLASS__, 'serve' ),
                'permission_callback' => array( __CLASS__, 'permissions' ),
                'args' => $args,
            ) );
        }
    }

    public static function headers( $response, $server, $request ) {
        if ( str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
            $response = rest_ensure_response( $response );
            $response->header( 'Cache-Control', 'private, no-store' );
            $response->header( 'Vary', 'Authorization, Cookie' );
            if ( $response->get_status() === 429 ) {
                $response->header( 'Retry-After', (string) ( 60 - time() % 60 ) );
            }
        }
        return $response;
    }

    public static function serve( WP_REST_Request $request ) {
        try {
            $route = $request->get_route();
            if ( str_ends_with( $route, '/openapi' ) ) {
                return new WP_REST_Response( json_decode( file_get_contents( __DIR__ . '/openapi.json' ), true, 512, JSON_THROW_ON_ERROR ) );
            }
            $cursor = UltraLove_Feed_Journal::publish();
            $generation = UltraLove_Feed_Journal::generation();
            if ( str_ends_with( $route, '/changes' ) ) {
                $after = (int) $request['after'];
                $page = UltraLove_Feed_Journal::page( $after, (int) $request['per_page'] + 1 );
                $cursor = $page['latest'];
                if ( $request['generation'] !== $generation || $after < $page['floor'] ) {
                    return new WP_Error( 'feed_resync_required', 'Perform a full catalog import and save its generation/change cursor.', array( 'status' => 409 ) );
                }
                if ( $after > $cursor ) {
                    return new WP_Error( 'feed_invalid_cursor', 'Cursor is ahead of this feed.', array( 'status' => 400 ) );
                }
                $rows = $page['rows'];
                $more = count( $rows ) > (int) $request['per_page'];
                $changes = array();
                foreach ( array_slice( $rows, 0, (int) $request['per_page'] ) as $row ) {
                    $product = self::visible( (int) $row->product_id ) ? self::product( (int) $row->product_id ) : null;
                    $changes[] = array(
                        'cursor' => (int) $row->seq, 'id' => (int) $row->product_id,
                        'action' => $product ? 'upsert' : 'delete', 'product' => $product,
                        'changed_at' => str_replace( ' ', 'T', $row->published_at ) . 'Z',
                    );
                    $after = (int) $row->seq;
                }
                return new WP_REST_Response( array(
                    'generation' => $generation, 'cursor' => $after, 'latest_cursor' => $cursor,
                    'has_more' => $more || $page['pending'], 'changes' => $changes,
                ) );
            }
            if ( $request->get_param( 'id' ) !== null ) {
                $id = (int) $request['id'];
                if ( ! self::visible( $id ) ) {
                    return new WP_Error( 'feed_product_not_found', 'Published catalog product not found.', array( 'status' => 404 ) );
                }
                return new WP_REST_Response( self::product( $id ) );
            }
            $size = (int) $request['per_page'];
            $ids = self::catalog_ids( (int) $request['after_id'], $size + 1, $request['ids'] ?? array() );
            $more = count( $ids ) > $size;
            $items = array();
            $last = (int) $request['after_id'];
            $inventory = str_ends_with( $route, '/inventory' );
            self::prime( array_slice( $ids, 0, $size ) );
            foreach ( array_slice( $ids, 0, $size ) as $id ) {
                if ( ! self::visible( $id ) ) {
                    throw new RuntimeException( 'Catalog changed during pagination; retry this page.' );
                }
                $items[] = $inventory ? self::inventory( $id ) : self::product( $id );
                $last = $id;
            }
            return new WP_REST_Response( array(
                'generation' => $generation, 'change_cursor' => $cursor, 'next_after_id' => $last,
                'has_more' => $more, $inventory ? 'inventory' : 'products' => $items,
            ) );
        } catch ( RuntimeException | JsonException $error ) {
            self::log_error( $error->getMessage() );
            return new WP_Error( 'feed_unavailable', 'Supplier feed is temporarily unavailable; retry this request.', array( 'status' => 503 ) );
        }
    }

    public static function visible( int $id ): bool {
        $post = get_post( $id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return false;
        }
        if ( $post->post_type === 'product' ) {
            return true;
        }
        $parent = $post->post_type === 'product_variation' ? get_post( $post->post_parent ) : null;
        return $parent && $parent->post_type === 'product' && $parent->post_status === 'publish';
    }

    public static function catalog_condition(): string {
        return "(p.post_status='publish' AND (p.post_type='product' OR (p.post_type='product_variation' AND parent.ID IS NOT NULL AND parent.post_type='product' AND parent.post_status='publish')))";
    }

    public static function catalog_ids( int $after, int $limit, array $ids = array() ): array {
        global $wpdb;
        $filter = $ids ? ' AND p.ID IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')' : '';
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent WHERE "
            . self::catalog_condition() . $filter . ' AND p.ID>%d ORDER BY p.ID LIMIT %d', $after, $limit
        );
        $result = $wpdb->get_col( $query );
        UltraLove_Feed_Journal::check_database();
        return array_map( 'intval', $result );
    }

    public static function prime( array $ids ): void {
        if ( $ids ) {
            _prime_post_caches( $ids, true, true );
        }
    }

    public static function inventory( int $id ): array {
        $product = wc_get_product( $id );
        if ( ! $product ) {
            throw new RuntimeException( 'Cannot load catalog product ' . $id );
        }
        $quantity = $product->get_stock_quantity();
        return array(
            'id' => $id, 'parent_id' => $product->get_parent_id(), 'sku' => $product->get_sku(),
            'manage_stock' => (bool) $product->get_manage_stock(),
            'stock_quantity' => $quantity === null ? null : (int) $quantity,
            'stock_status' => $product->get_stock_status(), 'backorders' => $product->get_backorders(),
            'stock_owner_id' => $product->get_stock_managed_by_id(),
        );
    }

    public static function product( int $id, string $context = 'view' ): array {
        $product = wc_get_product( $id );
        if ( ! $product ) {
            throw new RuntimeException( 'Cannot load catalog product ' . $id );
        }
        $parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
        $taxonomy_id = $parent ? $parent->get_id() : $id;
        $images = array();
        $warnings = array();
        $image_id = $product->get_image_id() ?: ( $parent ? $parent->get_image_id() : 0 );
        $gallery = $parent ? $parent->get_gallery_image_ids() : $product->get_gallery_image_ids();
        foreach ( array_unique( array_filter( array_merge( array( $image_id ), $gallery ) ) ) as $attachment ) {
            $url = wp_get_attachment_url( $attachment );
            if ( ! $url ) {
                $warnings[] = array( 'code' => 'missing_image', 'attachment_id' => (int) $attachment );
                $message = 'Missing catalog image ' . $attachment . ' for product ' . $id;
                wc_get_logger()->warning( $message, array( 'source' => 'ultralove-product-feed' ) );
                error_log( '[ultralove-product-feed] ' . $message );
                continue;
            }
            $images[] = array( 'id' => (int) $attachment, 'src' => $url, 'alt' => (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
        }
        $attributes = array();
        foreach ( $product->get_attributes() as $name => $attribute ) {
            if ( $attribute instanceof WC_Product_Attribute ) {
                $options = $attribute->is_taxonomy() ? wc_get_product_terms( $id, $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
                if ( is_wp_error( $options ) ) {
                    throw new RuntimeException( 'Cannot read catalog attributes: ' . $options->get_error_code() );
                }
                $attributes[] = array( 'name' => $attribute->get_name(), 'options' => array_values( $options ) );
            } else {
                $attributes[] = array( 'name' => (string) $name, 'options' => array( (string) $attribute ) );
            }
        }
        $modified = $product->get_date_modified();
        return array_merge( self::inventory( $id ), array(
            'name' => wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ' ', $product->get_name() ) ),
            'type' => $product->get_type(), 'permalink' => $product->get_permalink(),
            'description' => wp_kses_post( $product->get_description() ),
            'short_description' => wp_kses_post( $product->get_short_description() ),
            'price' => (string) $product->get_price( $context ),
            'regular_price' => (string) $product->get_regular_price( $context ),
            'sale_price' => (string) $product->get_sale_price( $context ),
            'currency' => get_woocommerce_currency(), 'prices_include_tax' => wc_prices_include_tax(),
            'date_modified_gmt' => $modified ? gmdate( 'Y-m-d\TH:i:s\Z', $modified->getTimestamp() ) : null,
            'global_unique_id' => $product->get_global_unique_id() ?: (string) get_post_meta( $id, 'hwp_product_gtin', true ),
            'images' => $images, 'data_warnings' => $warnings, 'categories' => self::terms( $taxonomy_id, 'product_cat' ),
            'brands' => self::terms( $taxonomy_id, 'product_brand' ), 'attributes' => $attributes,
            'variations' => $product->is_type( 'variable' )
                ? array_values( array_filter( array_map( 'intval', $product->get_children() ), array( __CLASS__, 'visible' ) ) ) : array(),
        ) );
    }

    private static function terms( int $id, string $taxonomy ): array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return array();
        }
        $terms = get_the_terms( $id, $taxonomy );
        if ( is_wp_error( $terms ) ) {
            throw new RuntimeException( 'Cannot read catalog taxonomy: ' . $terms->get_error_code() );
        }
        return $terms ? array_values( array_map( static fn( $term ) => array(
            'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug,
        ), $terms ) ) : array();
    }

    public static function profile( $user ): void {
        if ( ! self::supported() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }
        wp_nonce_field( 'ultralove_feed_access_' . $user->ID, 'ultralove_feed_nonce' );
        ?>
        <h2><?php esc_html_e( 'Supplier catalog API', 'ultralove-product-feed' ); ?></h2>
        <p><label><input type="checkbox" name="ultralove_feed_access" value="1" <?php checked( user_can( $user, self::CAPABILITY ) ); ?>>
            <?php esc_html_e( 'Allow this account to read the complete published catalog and stock feed.', 'ultralove-product-feed' ); ?></label></p>
        <p><?php esc_html_e( 'Use a revocable WordPress application password over HTTPS. Catalog access does not approve dropshipping orders or grant product editing rights.', 'ultralove-product-feed' ); ?></p>
        <?php
    }

    public static function save_profile( $id ): void {
        if ( ! isset( $_POST['ultralove_feed_nonce'] ) ) {
            return;
        }
        if ( ! self::supported() || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $id )
            || ! is_string( $_POST['ultralove_feed_nonce'] )
            || ! wp_verify_nonce( wp_unslash( $_POST['ultralove_feed_nonce'] ), 'ultralove_feed_access_' . $id ) ) {
            wp_die( 'You cannot change catalog API access.', '', array( 'response' => 403 ) );
        }
        $user = get_user_by( 'id', $id );
        if ( ! $user ) {
            wp_die( 'Catalog account not found.', '', array( 'response' => 404 ) );
        }
        if ( isset( $_POST['ultralove_feed_access'] ) && $_POST['ultralove_feed_access'] !== '1' ) {
            wp_die( 'Invalid catalog access selection.', '', array( 'response' => 400 ) );
        }
        if ( isset( $_POST['ultralove_feed_access'] ) && $_POST['ultralove_feed_access'] === '1' ) {
            $user->add_cap( self::CAPABILITY );
        } else {
            $user->remove_cap( self::CAPABILITY );
        }
    }

    public static function log_error( string $message ): void {
        wc_get_logger()->error( $message, array( 'source' => 'ultralove-product-feed' ) );
        error_log( '[ultralove-product-feed] ' . $message );
    }
}
UltraLove_Product_Feed::init();
register_activation_hook( __FILE__, array( 'UltraLove_Product_Feed', 'activate' ) );
