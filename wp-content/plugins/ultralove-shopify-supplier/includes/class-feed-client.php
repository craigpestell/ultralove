<?php
/**
 * Authenticated, CLI-only consumer of the private dev feed.
 */
if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

final class UltraLove_Shopify_Feed_Client {
    private array $credentials;
    private $transport;

    public function __construct( array $credentials, ?callable $transport = null ) {
        if ( $transport === null && ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' )
            || wp_get_environment_type() !== 'development'
            || home_url() !== 'https://dev.ultralove.ca' || DB_NAME !== 'wordpress_dev' ) ) {
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
        $this->transport = $transport;
    }

    public function request( string $route, array $query = array() ): array {
        if ( ! in_array( $route, array( '/products', '/inventory', '/changes', '/categories' ), true ) ) {
            throw new InvalidArgumentException( 'Unsupported read-only feed route.' );
        }
        // Loopback exercises real REST authentication without opening private dev to the internet.
        $url = 'http://localhost/wp-json/ultralove-feed/v1' . $route;
        if ( $query ) {
            $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }
        $arguments = array(
            'timeout' => 30, 'redirection' => 0,
            'headers' => array(
                'Host' => 'dev.ultralove.ca', 'X-Forwarded-Proto' => 'https', 'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( $this->credentials['username'] . ':' . $this->credentials['password'] ),
            ),
        );
        $response = $this->transport ? ( $this->transport )( $url, $arguments ) : wp_remote_get( $url, $arguments );
        if ( ! $this->transport && is_wp_error( $response ) ) {
            throw new RuntimeException( 'Feed transport failed: ' . $response->get_error_code() );
        }
        $status = $this->transport ? $response['status'] : wp_remote_retrieve_response_code( $response );
        $header = $this->transport ? static fn( $name ) => $response['headers'][$name] ?? '' : static fn( $name ) => wp_remote_retrieve_header( $response, $name );
        $body = $this->transport ? $response['body'] : wp_remote_retrieve_body( $response );
        if ( $status !== 200 ) {
            $retry = $status === 429 ? '; retry after ' . $header( 'retry-after' ) . ' seconds' : '';
            throw new RuntimeException( 'Feed HTTP ' . $status . $retry . '; no checkpoint advanced.', $status );
        }
        if ( ! str_starts_with( strtolower( $header( 'content-type' ) ), 'application/json' ) ) {
            throw new RuntimeException( 'Feed returned a non-JSON response.' );
        }
        try {
            $data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $error ) {
            throw new RuntimeException( 'Feed returned invalid JSON.', 0, $error );
        }
        if ( ! is_array( $data ) ) {
            throw new RuntimeException( 'Feed response must be an object.' );
        }
        return $data;
    }

    public static function catalog_transport( string $url, array $arguments ): array {
        if ( ! preg_match( '~^http://localhost/wp-json/ultralove-feed/v1/(products|categories|inventory)\?~', $url ) ) {
            throw new RuntimeException( 'Catalog app may only GET the configured loopback feed.' );
        }
        $headers = array();
        foreach ( array_merge( $arguments['headers'], array( 'X-UltraLove-Catalog-Read-Only' => '1' ) ) as $name => $value ) {
            $headers[] = $name . ': ' . $value;
        }
        $response_headers = array();
        $curl = curl_init( $url );
        curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ( $handle, string $line ) use ( &$response_headers ): int {
                if ( str_contains( $line, ':' ) ) {
                    list( $name, $value ) = explode( ':', $line, 2 );
                    $response_headers[strtolower( trim( $name ) )] = trim( $value );
                }
                return strlen( $line );
            },
        ) );
        $body = curl_exec( $curl );
        $status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
        if ( $body === false ) {
            throw new RuntimeException( 'Product feed transport failed: curl ' . curl_errno( $curl ) . '.' );
        }
        if ( ( $response_headers['x-ultralove-catalog-read-only'] ?? '' ) !== '1'
            || ( $response_headers['x-ultralove-catalog-database-writes'] ?? '' ) !== '0' ) {
            throw new RuntimeException( 'Feed request did not confirm its database-write guard; refusing import.' );
        }
        return array( 'status' => $status, 'headers' => $response_headers, 'body' => $body );
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

    public static function product( array $product, bool $full_catalog = false ): array {
        foreach ( array( 'sku', 'name', 'type', 'description', 'currency', 'price', 'global_unique_id', 'stock_status' ) as $key ) {
            if ( ! isset( $product[$key] ) || ! is_string( $product[$key] ) ) {
                throw new RuntimeException( 'Invalid feed product field: ' . $key );
            }
        }
        if ( ! isset( $product['id'] ) || ! is_int( $product['id'] ) || $product['id'] < 1 || $product['currency'] !== 'CAD' ) {
            throw new RuntimeException( 'Invalid supplier product identity/currency.' );
        }
        if ( $full_catalog ) {
            if ( $product['type'] === '' || ! is_bool( $product['manage_stock'] ?? null )
                || ! array_key_exists( 'stock_quantity', $product )
                || ( $product['stock_quantity'] !== null && ! is_int( $product['stock_quantity'] ) )
                || ! is_int( $product['parent_id'] ?? null ) || $product['parent_id'] < 0
                || ( $product['type'] === 'variation' && $product['parent_id'] < 1 )
                || ! is_int( $product['stock_owner_id'] ?? null ) || $product['stock_owner_id'] < 1
                || ( $product['price'] !== '' && ( ! preg_match( '/^\d+(?:\.\d+)?$/D', $product['price'] ) || ! is_finite( (float) $product['price'] ) ) )
                || ! is_array( $product['attributes'] ?? null ) ) {
                throw new RuntimeException( 'Invalid full-catalog product fields.' );
            }
            foreach ( $product['attributes'] as $attribute ) {
                if ( ! is_array( $attribute ) || ! is_string( $attribute['name'] ?? null )
                    || ! is_array( $attribute['options'] ?? null ) ) {
                    throw new RuntimeException( 'Invalid full-catalog attribute.' );
                }
                foreach ( $attribute['options'] as $value ) {
                    if ( ! is_string( $value ) ) {
                        throw new RuntimeException( 'Invalid full-catalog attribute value.' );
                    }
                }
            }
        } elseif ( $product['type'] !== 'simple' || $product['sku'] === ''
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

    public function all_products(): array {
        $after = 0;
        $state = array( 'products' => array() );
        do {
            $page = $this->request( '/products', array( 'after_id' => $after, 'per_page' => 100 ) );
            self::snapshot_page( $page, 'products' );
            if ( count( $page['products'] ) > 100 ) {
                throw new RuntimeException( 'Feed exceeded the hard catalog request cap.' );
            }
            if ( ! isset( $state['generation'] ) ) {
                $state['generation'] = $page['generation'];
                $state['cursor'] = $page['change_cursor'];
            } elseif ( $state['generation'] !== $page['generation'] || $page['change_cursor'] < $state['cursor'] ) {
                throw new RuntimeException( 'Catalog generation/cursor changed during full import.' );
            }
            $last = $after;
            foreach ( $page['products'] as $record ) {
                if ( ! is_array( $record ) ) {
                    throw new RuntimeException( 'Invalid full-catalog record.' );
                }
                $product = self::product( $record, true );
                if ( $product['id'] <= $last || isset( $state['products'][$product['id']] ) ) {
                    throw new RuntimeException( 'Unordered or duplicate full-catalog ID.' );
                }
                $state['products'][$product['id']] = $product;
                $last = $product['id'];
            }
            if ( $page['next_after_id'] !== $last || ( $page['has_more'] && $last <= $after ) ) {
                throw new RuntimeException( 'Full-catalog pagination did not advance correctly.' );
            }
            $after = $last;
        } while ( $page['has_more'] );
        if ( ! $state['products'] ) {
            throw new RuntimeException( 'Refusing an empty full-catalog import.' );
        }
        foreach ( $state['products'] as $product ) {
            if ( $product['parent_id'] && ! isset( $state['products'][$product['parent_id']] ) ) {
                throw new RuntimeException( 'Full catalog contains a variation without its parent.' );
            }
        }
        return $state;
    }

    public function products_page( array $filters = array() ): array {
        $query = array_merge( array( 'after_id' => 0, 'per_page' => 50, 'parents_only' => true ), $filters );
        foreach ( $query as $key => $value ) {
            if ( ! in_array( $key, array( 'after_id', 'per_page', 'parents_only', 'category_id', 'search' ), true ) ) {
                throw new InvalidArgumentException( 'Unsupported catalog browse filter.' );
            }
        }
        if ( ! is_int( $query['per_page'] ) || $query['per_page'] < 1 || $query['per_page'] > 100
            || ! is_int( $query['after_id'] ) || $query['after_id'] < 0
            || ! is_bool( $query['parents_only'] )
            || ( isset( $query['category_id'] ) && ( ! is_int( $query['category_id'] ) || $query['category_id'] < 1 ) )
            || ( isset( $query['search'] ) && ( ! is_string( $query['search'] ) || $query['search'] === '' || mb_strlen( $query['search'] ) > 100 ) ) ) {
            throw new InvalidArgumentException( 'Invalid catalog browse filters.' );
        }
        $page = $this->request( '/products', $query );
        self::snapshot_page( $page, 'products' );
        if ( count( $page['products'] ) > $query['per_page'] ) {
            throw new RuntimeException( 'Feed exceeded the requested catalog page size.' );
        }
        $last = $query['after_id'];
        foreach ( $page['products'] as &$record ) {
            if ( ! is_array( $record ) ) {
                throw new RuntimeException( 'Invalid catalog browse product.' );
            }
            $record = self::product( $record, true );
            if ( $record['id'] <= $last || ( $query['parents_only'] && $record['parent_id'] ) ) {
                throw new RuntimeException( 'Invalid catalog browse page ordering/parent selection.' );
            }
            $last = $record['id'];
        }
        unset( $record );
        if ( $page['next_after_id'] !== $last || ( $page['has_more'] && $last <= $query['after_id'] ) ) {
            throw new RuntimeException( 'Catalog browse page did not advance correctly.' );
        }
        return $page;
    }

    public function selected_products( array $ids ): array {
        if ( ! $ids || count( $ids ) > 50 || count( array_unique( $ids ) ) !== count( $ids ) ) {
            throw new InvalidArgumentException( 'Select between 1 and 50 distinct products per import batch.' );
        }
        foreach ( $ids as $id ) {
            if ( ! is_int( $id ) || $id < 1 ) {
                throw new InvalidArgumentException( 'Selected supplier IDs must be positive integers.' );
            }
        }
        $state = array( 'products' => array() );
        $read = function ( array $batch ) use ( &$state ): array {
            $page = $this->request( '/products', array( 'ids' => $batch, 'per_page' => 100 ) );
            self::snapshot_page( $page, 'products' );
            if ( $page['has_more'] || count( $page['products'] ) !== count( $batch ) ) {
                throw new RuntimeException( 'Selected product/variation is missing or unavailable; no partial import.' );
            }
            if ( ! isset( $state['generation'] ) ) {
                $state['generation'] = $page['generation'];
                $state['cursor'] = $page['change_cursor'];
            } elseif ( $state['generation'] !== $page['generation'] || $page['change_cursor'] < $state['cursor'] ) {
                throw new RuntimeException( 'Feed changed while reading the selected batch.' );
            }
            $products = array();
            foreach ( $page['products'] as $record ) {
                if ( ! is_array( $record ) ) {
                    throw new RuntimeException( 'Invalid selected catalog product.' );
                }
                $product = self::product( $record, true );
                if ( ! in_array( $product['id'], $batch, true ) || isset( $products[$product['id']] ) || isset( $state['products'][$product['id']] ) ) {
                    throw new RuntimeException( 'Unexpected or duplicate selected product.' );
                }
                $products[$product['id']] = $product;
                $state['products'][$product['id']] = $product;
            }
            return $products;
        };
        $roots = $read( $ids );
        $children = array();
        foreach ( $roots as $product ) {
            if ( $product['parent_id'] ) {
                throw new InvalidArgumentException( 'Select the parent product; published variants are included automatically.' );
            }
            if ( ! is_array( $product['variations'] ?? null ) ) {
                throw new RuntimeException( 'Selected product has invalid variation IDs.' );
            }
            foreach ( $product['variations'] as $id ) {
                if ( ! is_int( $id ) || $id < 1 || isset( $children[$id] ) ) {
                    throw new RuntimeException( 'Invalid or duplicate published variation ID.' );
                }
                $children[$id] = $product['id'];
            }
        }
        foreach ( array_chunk( array_keys( $children ), 100 ) as $batch ) {
            foreach ( $read( $batch ) as $product ) {
                if ( $product['parent_id'] !== $children[$product['id']] || $product['type'] !== 'variation' ) {
                    throw new RuntimeException( 'Selected variation has changed parent/type.' );
                }
            }
        }
        ksort( $state['products'] );
        return $state;
    }

    public function categories_page( array $filters = array() ): array {
        $query = array_merge( array( 'after_id' => 0, 'per_page' => 50 ), $filters );
        if ( array_diff( array_keys( $query ), array( 'after_id', 'per_page' ) )
            || ! is_int( $query['after_id'] ) || $query['after_id'] < 0
            || ! is_int( $query['per_page'] ) || $query['per_page'] < 1 || $query['per_page'] > 100 ) {
            throw new InvalidArgumentException( 'Invalid category pagination.' );
        }
        $page = $this->request( '/categories', $query );
        self::snapshot_page( $page, 'categories' );
        if ( count( $page['categories'] ) > $query['per_page'] ) {
            throw new RuntimeException( 'Category feed exceeded the requested page size.' );
        }
        $last = $query['after_id'];
        foreach ( $page['categories'] as $category ) {
            if ( ! is_array( $category ) || ! is_int( $category['id'] ?? null ) || $category['id'] <= $last
                || ! is_string( $category['name'] ?? null ) || ! is_string( $category['slug'] ?? null )
                || ! is_int( $category['parent_id'] ?? null ) || $category['parent_id'] < 0 ) {
                throw new RuntimeException( 'Invalid/unordered category feed item.' );
            }
            $last = $category['id'];
        }
        if ( $page['next_after_id'] !== $last || ( $page['has_more'] && $last <= $query['after_id'] ) ) {
            throw new RuntimeException( 'Category pagination did not advance correctly.' );
        }
        return $page;
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
