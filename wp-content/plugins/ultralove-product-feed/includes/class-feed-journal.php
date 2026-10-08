<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class UltraLove_Feed_Journal {
    const RETENTION_DAYS = 7;

    private static function table( string $suffix ): string {
        global $wpdb;
        return $wpdb->prefix . 'ultralove_feed_' . $suffix;
    }

    public static function check_database(): void {
        global $wpdb;
        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'Supplier feed database operation failed: ' . $wpdb->last_error );
        }
    }

    private static function query( string $sql ): int {
        global $wpdb;
        $result = $wpdb->query( $sql );
        self::check_database();
        if ( $result === false ) {
            throw new RuntimeException( 'Supplier feed database operation failed.' );
        }
        return (int) $result;
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        $changes = self::table( 'changes' );
        $state = self::table( 'state' );
        $rates = self::table( 'rates' );
        $existing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $changes ) ) ) === $changes;
        dbDelta( "CREATE TABLE $changes (
            event_id bigint unsigned NOT NULL AUTO_INCREMENT,
            seq bigint unsigned DEFAULT NULL,
            product_id bigint unsigned NOT NULL,
            published_at datetime DEFAULT NULL,
            PRIMARY KEY  (event_id),
            UNIQUE KEY seq (seq),
            KEY published_at (published_at)
        ) $collation;" );
        dbDelta( "CREATE TABLE $state (
            product_id bigint unsigned NOT NULL,
            fingerprint char(64) NOT NULL,
            PRIMARY KEY  (product_id)
        ) $collation;" );
        dbDelta( "CREATE TABLE $rates (
            user_id bigint unsigned NOT NULL,
            minute bigint unsigned NOT NULL,
            requests int unsigned NOT NULL,
            PRIMARY KEY  (user_id)
        ) $collation;" );
        foreach ( array( $changes, $state, $rates ) as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                throw new RuntimeException( 'Supplier feed table installation failed.' );
            }
        }
        if ( ! $existing || ! get_option( 'ultralove_feed_generation' ) ) {
            update_option( 'ultralove_feed_generation', wp_generate_uuid4(), false );
            update_option( 'ultralove_feed_reconcile_after', 0, false );
        }
    }

    public static function generation(): string {
        $value = get_option( 'ultralove_feed_generation' );
        if ( ! is_string( $value ) || $value === '' ) {
            throw new RuntimeException( 'Supplier feed is not initialized.' );
        }
        return $value;
    }

    private static function locked( string $purpose, callable $operation ) {
        global $wpdb;
        $name = 'ul_feed_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix . $purpose ), 0, 40 );
        if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $name ) ) !== '1' ) {
            throw new RuntimeException( 'Supplier feed journal is busy or its lock is unavailable.' );
        }
        try {
            return $operation();
        } finally {
            if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ) !== '1' ) {
                throw new RuntimeException( 'Supplier feed journal lock release failed.' );
            }
        }
    }

    public static function enqueue( int $id ): void {
        global $wpdb;
        if ( ! UltraLove_Product_Feed::supported() ) {
            return;
        }
        self::query( $wpdb->prepare( 'INSERT INTO ' . self::table( 'changes' ) . ' (product_id) VALUES (%d)', $id ) );
    }

    public static function product_changed( $id ): void {
        if ( ! UltraLove_Product_Feed::supported() ) {
            return;
        }
        $post = get_post( $id );
        if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
            return;
        }
        self::enqueue( (int) $id );
        if ( $post->post_type === 'product' ) {
            global $wpdb;
            $children = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='product_variation'", $id ) );
            self::check_database();
            foreach ( $children as $child ) {
                self::enqueue( (int) $child );
            }
        }
    }

    public static function status_changed( $new, $old, $post ): void {
        if ( $new !== $old ) {
            self::product_changed( $post->ID );
        }
    }

    public static function deleted( $id, $post ): void {
        self::product_changed( $id );
    }

    public static function meta_changed( $meta_id, $id, $key, $value ): void {
        if ( in_array( $key, array( '_sku', '_stock', '_stock_status', '_manage_stock', '_backorders', '_price', '_regular_price', '_sale_price', '_thumbnail_id', '_product_image_gallery', '_product_attributes', '_global_unique_id', 'hwp_product_gtin' ), true )
            || str_starts_with( $key, 'attribute_' ) ) {
            self::product_changed( $id );
        }
    }

    public static function terms_changed( $id, $terms, $tt_ids, $taxonomy ): void {
        if ( in_array( $taxonomy, array( 'product_cat', 'product_brand', 'product_type' ), true ) ) {
            self::product_changed( $id );
        }
    }

    public static function publish(): int {
        return self::locked( 'publish', static function (): int {
            global $wpdb;
            $table = self::table( 'changes' );
            self::query( 'START TRANSACTION' );
            try {
                $cursor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(seq),0) FROM $table" );
                self::check_database();
                // Allocate delivery cursors only after producer transactions have committed.
                $ids = $wpdb->get_col( "SELECT event_id FROM $table WHERE seq IS NULL ORDER BY event_id LIMIT 1000" );
                self::check_database();
                if ( $ids ) {
                    $cases = array();
                    foreach ( $ids as $id ) {
                        $cases[] = 'WHEN ' . (int) $id . ' THEN ' . ++$cursor;
                    }
                    self::query( "UPDATE $table SET seq=CASE event_id " . implode( ' ', $cases )
                        . ' END, published_at=UTC_TIMESTAMP() WHERE event_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') AND seq IS NULL' );
                }
                self::query( 'COMMIT' );
                return $cursor;
            } catch ( RuntimeException $error ) {
                self::query( 'ROLLBACK' );
                throw $error;
            }
        } );
    }

    public static function floor(): int {
        global $wpdb;
        $result = (int) $wpdb->get_var( 'SELECT COALESCE(MIN(seq),1)-1 FROM ' . self::table( 'changes' ) );
        self::check_database();
        return $result;
    }

    public static function pending(): bool {
        global $wpdb;
        $value = $wpdb->get_var( 'SELECT event_id FROM ' . self::table( 'changes' ) . ' WHERE seq IS NULL LIMIT 1' );
        self::check_database();
        return $value !== null;
    }

    public static function page( int $after, int $limit ): array {
        return self::locked( 'publish', static function () use ( $after, $limit ): array {
            global $wpdb;
            $table = self::table( 'changes' );
            $latest = (int) $wpdb->get_var( "SELECT COALESCE(MAX(seq),0) FROM $table" );
            self::check_database();
            $floor = self::floor();
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT seq,product_id,published_at FROM $table WHERE seq>%d ORDER BY seq LIMIT %d", $after, $limit
            ) );
            self::check_database();
            return array( 'latest' => $latest, 'floor' => $floor, 'rows' => $rows, 'pending' => self::pending() );
        } );
    }

    public static function allow_request( int $user, int $limit ): bool {
        global $wpdb;
        $table = self::table( 'rates' );
        $minute = (int) floor( time() / 60 );
        self::query( $wpdb->prepare(
            "INSERT INTO $table (user_id,minute,requests) VALUES (%d,%d,1) ON DUPLICATE KEY UPDATE requests=IF(minute=%d,requests+1,1),minute=%d",
            $user, $minute, $minute, $minute
        ) );
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT requests FROM $table WHERE user_id=%d", $user ) );
        self::check_database();
        return (int) $count <= $limit;
    }

    public static function reconcile( int $size ): int {
        return self::locked( 'reconcile', static function () use ( $size ): int {
            global $wpdb;
            $after = (int) get_option( 'ultralove_feed_reconcile_after', 0 );
            $ids = UltraLove_Product_Feed::catalog_ids( $after, $size );
            foreach ( $ids as $id ) {
                clean_post_cache( $id );
                wc_delete_product_transients( $id );
            }
            UltraLove_Product_Feed::prime( $ids );
            $state = self::table( 'state' );
            foreach ( $ids as $id ) {
                if ( ! UltraLove_Product_Feed::visible( $id ) ) {
                    self::enqueue( $id );
                    continue;
                }
                $fingerprint = hash( 'sha256', wp_json_encode( UltraLove_Product_Feed::product( $id, 'edit' ) ) );
                $prior = $wpdb->get_var( $wpdb->prepare( "SELECT fingerprint FROM $state WHERE product_id=%d", $id ) );
                self::check_database();
                if ( $prior !== $fingerprint ) {
                    self::enqueue( $id );
                    self::query( $wpdb->prepare( "REPLACE INTO $state (product_id,fingerprint) VALUES (%d,%s)", $id, $fingerprint ) );
                }
            }
            if ( count( $ids ) < $size ) {
                $missing = $wpdb->get_col( "SELECT s.product_id FROM $state s LEFT JOIN {$wpdb->posts} p ON p.ID=s.product_id LEFT JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent WHERE p.ID IS NULL OR NOT "
                    . UltraLove_Product_Feed::catalog_condition() );
                self::check_database();
                foreach ( $missing as $id ) {
                    self::enqueue( (int) $id );
                    self::query( $wpdb->prepare( "DELETE FROM $state WHERE product_id=%d", $id ) );
                }
                update_option( 'ultralove_feed_reconcile_after', 0, false );
                update_option( 'ultralove_feed_reconciled_at', gmdate( 'c' ), false );
            } elseif ( $ids ) {
                update_option( 'ultralove_feed_reconcile_after', end( $ids ), false );
            }
            $cursor = self::publish();
            self::locked( 'publish', static function () use ( $cursor ): void {
                global $wpdb;
                $table = self::table( 'changes' );
                $first_recent = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(MIN(seq),%d) FROM $table WHERE published_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", $cursor, self::RETENTION_DAYS
                ) );
                self::check_database();
                self::query( $wpdb->prepare( "DELETE FROM $table WHERE seq<%d ORDER BY seq LIMIT 1000", min( $cursor, $first_recent ) ) );
            } );
            return count( $ids );
        } );
    }
}
