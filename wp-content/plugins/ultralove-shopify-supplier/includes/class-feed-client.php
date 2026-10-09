<?php
/**
 * Authenticated, CLI-only consumer of the private dev feed.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

final class UltraLove_Shopify_Feed_Client {
    private array $credentials;

    public function __construct( array $credentials ) {
        if ( wp_get_environment_type() !== 'development'
            || home_url() !== 'https://dev.ultralove.ca' || DB_NAME !== 'wordpress_dev' ) {
            throw new RuntimeException( 'Feed consumer is restricted to the dev supplier.' );
        }
        foreach ( array( 'username', 'password' ) as $key ) {
            if ( ! isset( $credentials[$key] ) || ! is_string( $credentials[$key] ) || $credentials[$key] === '' ) {
                throw new RuntimeException( 'Feed consumer credentials are missing.' );
            }
        }
        if ( str_contains( $credentials['username'], ':' ) ) {
            throw new RuntimeException( 'Invalid feed username.' );
        }
        $this->credentials = $credentials;
    }

    public function request( string $route, array $query = array() ): array {
        if ( ! in_array( $route, array( '/products', '/inventory', '/changes' ), true ) ) {
            throw new InvalidArgumentException( 'Unsupported read-only feed route.' );
        }
        // Loopback exercises real REST authentication without opening private dev to the internet.
        $url = 'http://localhost/wp-json/ultralove-feed/v1' . $route;
        if ( $query ) {
            $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }
        $response = wp_remote_get( $url, array(
            'timeout' => 30, 'redirection' => 0,
            'headers' => array(
                'Host' => 'dev.ultralove.ca', 'X-Forwarded-Proto' => 'https', 'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( $this->credentials['username'] . ':' . $this->credentials['password'] ),
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'Feed transport failed: ' . $response->get_error_code() );
        }
        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            $retry = $status === 429 ? '; retry after ' . wp_remote_retrieve_header( $response, 'retry-after' ) . ' seconds' : '';
            throw new RuntimeException( 'Feed HTTP ' . $status . $retry . '; no checkpoint advanced.', $status );
        }
        if ( ! str_starts_with( strtolower( wp_remote_retrieve_header( $response, 'content-type' ) ), 'application/json' ) ) {
            throw new RuntimeException( 'Feed returned a non-JSON response.' );
        }
        try {
            $data = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $error ) {
            throw new RuntimeException( 'Feed returned invalid JSON.', 0, $error );
        }
        if ( ! is_array( $data ) ) {
            throw new RuntimeException( 'Feed response must be an object.' );
        }
        return $data;
    }

    private static function snapshot_page( array $page, string $items ): void {
        if ( ! isset( $page['generation'], $page['change_cursor'], $page['next_after_id'], $page['has_more'], $page[$items] )
            || ! is_string( $page['generation'] )
            || ! preg_match( '/^[a-f0-9-]{36}$/i', $page['generation'] )
            || ! is_int( $page['change_cursor'] ) || $page['change_cursor'] < 0
            || ! is_int( $page['next_after_id'] ) || $page['next_after_id'] < 0
            || ! is_bool( $page['has_more'] ) || ! is_array( $page[$items] ) ) {
            throw new RuntimeException( 'Invalid feed snapshot envelope.' );
        }
    }

    public static function product( array $product ): array {
        foreach ( array( 'sku', 'name', 'type', 'description', 'currency', 'price', 'global_unique_id', 'stock_status' ) as $key ) {
            if ( ! isset( $product[$key] ) || ! is_string( $product[$key] ) ) {
                throw new RuntimeException( 'Invalid feed product field: ' . $key );
            }
        }
        if ( ! isset( $product['id'] ) || ! is_int( $product['id'] ) || $product['id'] < 1
            || $product['type'] !== 'simple' || $product['sku'] === '' || $product['currency'] !== 'CAD'
            || ! preg_match( '/^\d+(?:\.\d+)?$/', $product['price'] ) || (float) $product['price'] <= 0
            || ( $product['manage_stock'] ?? null ) !== true || ! is_int( $product['stock_quantity'] ?? null )
            || ! in_array( $product['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true )
            || ( $product['stock_owner_id'] ?? null ) !== $product['id'] ) {
            throw new RuntimeException( 'Selected feed product is outside the simple/CAD/managed-stock test contract.' );
        }
        foreach ( array( 'images', 'data_warnings' ) as $key ) {
            if ( ! isset( $product[$key] ) || ! is_array( $product[$key] ) ) {
                throw new RuntimeException( 'Invalid feed product field: ' . $key );
            }
        }
        foreach ( $product['images'] as $image ) {
            if ( ! is_array( $image ) || ! is_string( $image['src'] ?? null )
                || ! str_starts_with( $image['src'], 'https://' ) || ! is_string( $image['alt'] ?? null ) ) {
                throw new RuntimeException( 'Invalid feed image.' );
            }
        }
        foreach ( $product['data_warnings'] as $warning ) {
            if ( ! is_array( $warning ) || ( $warning['code'] ?? null ) !== 'missing_image'
                || ! is_int( $warning['attachment_id'] ?? null ) ) {
                throw new RuntimeException( 'Invalid feed media warning.' );
            }
        }
        return $product;
    }

    public function read( array $ids, array $state = array() ): array {
        if ( ! $ids || count( $ids ) > 100 ) {
            throw new InvalidArgumentException( 'Select between 1 and 100 supplier IDs per test consumer.' );
        }
        foreach ( $ids as $id ) {
            if ( ! is_int( $id ) || $id < 1 ) {
                throw new InvalidArgumentException( 'Supplier IDs must be positive integers.' );
            }
        }
        $ids = array_values( array_unique( $ids ) );
        sort( $ids );
        $selection = hash( 'sha256', implode( ',', $ids ) );
        if ( $state && ( ! is_string( $state['generation'] ?? null )
            || ! is_int( $state['cursor'] ?? null ) || $state['cursor'] < 0
            || ! is_array( $state['products'] ?? null ) || ! is_array( $state['mappings'] ?? null ) ) ) {
            throw new RuntimeException( 'Invalid saved feed checkpoint; explicit reset required.' );
        }
        if ( ! $state || ( $state['selection'] ?? null ) !== $selection ) {
            $state = $this->snapshot( $ids, $state['mappings'] ?? array(), $selection );
        }
        try {
            $state = $this->changes( $ids, $state );
        } catch ( RuntimeException $error ) {
            if ( $error->getCode() !== 409 ) {
                throw $error;
            }
            $state = $this->changes( $ids, $this->snapshot( $ids, $state['mappings'], $selection ) );
            $state['resynced'] = true;
        }
        // Recheck current inventory as well: reconciliation can lag writes that bypass hooks.
        $page = $this->request( '/inventory', array( 'ids' => $ids, 'per_page' => 100 ) );
        self::snapshot_page( $page, 'inventory' );
        if ( $page['generation'] !== $state['generation'] || $page['has_more'] ) {
            throw new RuntimeException( 'Inventory generation changed or pagination is incomplete; retry the sync.' );
        }
        $seen = array();
        foreach ( $page['inventory'] as $inventory ) {
            $id = $inventory['id'] ?? null;
            if ( ! is_int( $id ) || ! in_array( $id, $ids, true ) || isset( $seen[$id] ) ) {
                throw new RuntimeException( 'Unexpected or duplicate inventory ID.' );
            }
            $seen[$id] = true;
            if ( ! isset( $state['products'][$id] ) ) {
                throw new RuntimeException( 'Catalog changed during inventory read; retry the sync.' );
            }
            $product = $state['products'][$id];
            if ( ( $inventory['sku'] ?? null ) !== $product['sku'] ) {
                throw new RuntimeException( 'Supplier SKU changed during inventory read; retry the sync.' );
            }
            foreach ( array( 'manage_stock', 'stock_quantity', 'stock_status', 'stock_owner_id' ) as $key ) {
                if ( ! array_key_exists( $key, $inventory ) ) {
                    throw new RuntimeException( 'Incomplete feed inventory record.' );
                }
                $product[$key] = $inventory[$key];
            }
            $state['products'][$id] = self::product( $product );
        }
        foreach ( array_keys( $state['products'] ) as $id ) {
            if ( ! isset( $seen[$id] ) ) {
                unset( $state['products'][$id] );
            }
        }
        return $state;
    }

    private function snapshot( array $ids, array $mappings, string $selection ): array {
        $state = array( 'selection' => $selection, 'generation' => '', 'cursor' => 0, 'products' => array(), 'mappings' => $mappings );
        $after = 0;
        for ( $attempt = 0; $attempt < 100; ++$attempt ) {
            $page = $this->request( '/products', array( 'ids' => $ids, 'after_id' => $after, 'per_page' => 100 ) );
            self::snapshot_page( $page, 'products' );
            if ( $attempt === 0 ) {
                $state['generation'] = $page['generation'];
                $state['cursor'] = $page['change_cursor'];
            } elseif ( $page['generation'] !== $state['generation'] ) {
                throw new RuntimeException( 'Catalog generation changed during import.' );
            }
            foreach ( $page['products'] as $record ) {
                if ( ! is_array( $record ) ) {
                    throw new RuntimeException( 'Invalid feed product record.' );
                }
                $product = self::product( $record );
                if ( ! in_array( $product['id'], $ids, true ) || $product['id'] <= $after
                    || isset( $state['products'][$product['id']] ) ) {
                    throw new RuntimeException( 'Unexpected or duplicate catalog ID.' );
                }
                $state['products'][$product['id']] = $product;
            }
            if ( ! $page['has_more'] ) {
                return $state;
            }
            if ( $page['next_after_id'] <= $after ) {
                throw new RuntimeException( 'Catalog pagination did not advance.' );
            }
            $after = $page['next_after_id'];
        }
        throw new RuntimeException( 'Catalog pagination exceeded the bounded test cycle.' );
    }

    private function changes( array $ids, array $state ): array {
        for ( $attempt = 0; $attempt < 100; ++$attempt ) {
            $page = $this->request( '/changes', array( 'after' => $state['cursor'], 'generation' => $state['generation'], 'per_page' => 100 ) );
            if ( ( $page['generation'] ?? null ) !== $state['generation']
                || ! is_int( $page['cursor'] ?? null ) || ! is_int( $page['latest_cursor'] ?? null )
                || $page['cursor'] < $state['cursor'] || $page['latest_cursor'] < $page['cursor']
                || ! is_bool( $page['has_more'] ?? null ) || ! is_array( $page['changes'] ?? null ) ) {
                throw new RuntimeException( 'Invalid feed change envelope.' );
            }
            $cursor = $state['cursor'];
            foreach ( $page['changes'] as $change ) {
                if ( ! is_array( $change ) || ! is_int( $change['id'] ?? null ) || $change['id'] < 1
                    || ! is_int( $change['cursor'] ?? null ) || $change['cursor'] <= $cursor
                    || $change['cursor'] > $page['cursor']
                    || ! in_array( $change['action'] ?? null, array( 'upsert', 'delete' ), true ) ) {
                    throw new RuntimeException( 'Invalid or unordered feed change.' );
                }
                $cursor = $change['cursor'];
                if ( ! in_array( $change['id'], $ids, true ) ) {
                    continue;
                }
                if ( $change['action'] === 'delete' ) {
                    if ( ! array_key_exists( 'product', $change ) || $change['product'] !== null ) {
                        throw new RuntimeException( 'Invalid feed removal.' );
                    }
                    unset( $state['products'][$change['id']] );
                } else {
                    if ( ! is_array( $change['product'] ?? null ) ) {
                        throw new RuntimeException( 'Invalid feed upsert.' );
                    }
                    $product = self::product( $change['product'] );
                    if ( $product['id'] !== $change['id'] ) {
                        throw new RuntimeException( 'Feed change/product identity mismatch.' );
                    }
                    $state['products'][$product['id']] = $product;
                }
            }
            if ( $cursor !== $page['cursor'] || ( $page['has_more'] && $cursor === $state['cursor'] ) ) {
                throw new RuntimeException( 'Feed change publication is pending or its cursor did not advance.' );
            }
            $state['cursor'] = $cursor;
            if ( ! $page['has_more'] ) {
                return $state;
            }
        }
        throw new RuntimeException( 'Change backlog exceeded the bounded test cycle; retry without advancing the saved checkpoint.' );
    }
}
