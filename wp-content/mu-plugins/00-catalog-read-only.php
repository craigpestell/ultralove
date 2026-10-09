<?php
/**
 * Reject database writes during explicitly requested loopback catalog reads.
 * Uses the normal WordPress connection; never changes accounts or DB modes.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class UltraLove_Catalog_Read_Only {
    private static bool $active = false;
    private static int $queries = 0;

    public static function active(): bool {
        return self::$active;
    }

    public static function query( string $sql ): string {
        $statement = preg_replace( '~^(?:\s|/\*.*?\*/|--[^\r\n]*[\r\n]|#[^\r\n]*[\r\n])*~s', '', $sql );
        if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE)\b/i', $statement )
            || preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $statement ) ) {
            error_log( '[ultralove-catalog-read-only] Database write rejected; catalog request failed.' );
            throw new RuntimeException( 'Catalog-only request attempted a database write; write rejected.' );
        }
        ++self::$queries;
        return $sql;
    }

    public static function allow_request( int $user_id, int $limit ): bool {
        if ( ! wp_using_ext_object_cache() ) {
            throw new RuntimeException( 'Catalog-only request requires the existing persistent object cache.' );
        }
        $key = $user_id . ':' . (int) floor( time() / 60 );
        $group = 'ultralove_catalog_read_only';
        if ( wp_cache_add( $key, 1, $group, 120 ) ) {
            return true;
        }
        $count = wp_cache_incr( $key, 1, $group );
        if ( $count === false ) {
            throw new RuntimeException( 'Catalog-only rate limiter is unavailable.' );
        }
        return $count <= $limit;
    }

    public static function headers( $response, $server, $request ) {
        $response = rest_ensure_response( $response );
        $response->header( 'X-UltraLove-Catalog-Read-Only', '1' );
        $response->header( 'X-UltraLove-Catalog-Database-Writes', '0' );
        $response->header( 'X-UltraLove-Catalog-Selects', (string) self::$queries );
        return $response;
    }

    public static function init(): void {
        if ( ( $_SERVER['HTTP_X_ULTRALOVE_CATALOG_READ_ONLY'] ?? '' ) !== '1'
            || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET'
            || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true )
            || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || ! empty( $_SERVER['HTTP_CF_RAY'] )
            || ! empty( $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] )
            || ( $_SERVER['HTTP_HOST'] ?? '' ) !== 'dev.ultralove.ca'
            || ! defined( 'WP_ENVIRONMENT_TYPE' ) || WP_ENVIRONMENT_TYPE !== 'development'
            || ! defined( 'DB_NAME' ) || DB_NAME !== 'wordpress_dev'
            || ! str_starts_with( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/wp-json/ultralove-feed/v1/' ) ) {
            return;
        }
        self::$active = true;
        add_filter( 'query', array( __CLASS__, 'query' ), PHP_INT_MAX );
        add_filter( 'rest_post_dispatch', array( __CLASS__, 'headers' ), PHP_INT_MAX, 3 );
    }
}
UltraLove_Catalog_Read_Only::init();
