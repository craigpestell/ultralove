<?php
/**
 * Bounded, explicitly selected batch write side of the existing UltraLove HTTP-feed connector.
 *
 * Intentionally has no WordPress bootstrap, database, order or fulfillment dependency.
 * State and the immutable feed checkpoint belong to the root-only operations runner.
 */
final class UltraLove_Shopify_Catalog_Import {
    private const SHOP = 'ultralove-integration-lab.myshopify.com';
    private const BASE = '/root/.ultralove-shopify-dev/';
    private const MAX_ROOTS = 50;
    private const LEGACY = array( 17186 => '1513', 17188 => '2455', 17192 => '70072', 17194 => '70081' );
    private string $directory;
    private string $checkpoint;
    private string $token = '';
    private string $location = '';
    private float $nextQueryAt = 0;
    private array $state = array();
    private $lock;

    public function __construct( string $runDirectory, string $feedCheckpointSha256 ) {
        self::runtime();
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $feedCheckpointSha256 ) ) {
            throw new InvalidArgumentException( 'Invalid immutable feed checkpoint hash.' );
        }
        $this->directory = rtrim( $runDirectory, '/' );
        if ( ! str_starts_with( $this->directory . '/', self::BASE )
            || str_contains( $this->directory, '/../' ) || str_contains( $this->directory, '/./' ) ) {
            throw new RuntimeException( 'Catalog state must be under the root-only connector directory.' );
        }
        self::secure_directory( $this->directory );
        $this->checkpoint = $feedCheckpointSha256;
        $path = $this->directory . '/catalog.lock';
        if ( file_exists( $path ) || is_link( $path ) ) {
            self::private_file( $path );
        }
        $old = umask( 0077 );
        try {
            $this->lock = fopen( $path, 'c+' );
        } finally {
            umask( $old );
        }
        if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
            throw new RuntimeException( 'Another catalog import or cleanup holds the connector lock.' );
        }
        self::private_file( $path );
        $path = $this->directory . '/catalog-state.json';
        if ( file_exists( $path ) || is_link( $path ) ) {
            $this->state = json_decode( self::private_file( $path ), true, 512, JSON_THROW_ON_ERROR );
            if ( ( $this->state['checkpoint_sha256'] ?? null ) !== $this->checkpoint
                || ( $this->state['version'] ?? null ) !== 1
                || ! preg_match( '/^[a-f0-9]{32}$/D', $this->state['run_id'] ?? '' ) ) {
                throw new RuntimeException( 'State does not belong to this immutable feed checkpoint.' );
            }
        }
    }

    private static function runtime(): void {
        if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) || ! function_exists( 'posix_geteuid' )
            || posix_geteuid() !== 0 ) {
            throw new RuntimeException( 'Catalog connector requires standalone root PHP CLI, without WordPress.' );
        }
    }

    private static function secure_directory( string $path ): void {
        $parts = explode( '/', trim( $path, '/' ) );
        $current = '';
        foreach ( $parts as $part ) {
            if ( $part === '' || $part === '.' || $part === '..' ) {
                throw new RuntimeException( 'Unsafe connector directory.' );
            }
            $current .= '/' . $part;
            clearstatcache( true, $current );
            $stat = lstat( $current );
            if ( ! $stat || ( $stat['mode'] & 0170000 ) !== 0040000
                || $stat['uid'] !== 0 || ( $stat['mode'] & 0022 ) !== 0 ) {
                throw new RuntimeException( 'Connector directory has unsafe ownership or permissions.' );
            }
        }
        if ( ( fileperms( $path ) & 0777 ) !== 0700 ) {
            throw new RuntimeException( 'Catalog run directory must have mode 0700.' );
        }
    }

    public static function private_file( string $path ): string {
        clearstatcache( true, $path );
        $stat = lstat( $path );
        if ( ! $stat || ( $stat['mode'] & 0170000 ) !== 0100000 || $stat['uid'] !== 0
            || ( $stat['mode'] & 0777 ) !== 0600 || $stat['nlink'] !== 1 ) {
            throw new RuntimeException( 'Expected a root-owned, single-link, mode-0600 regular file.' );
        }
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            throw new RuntimeException( 'Cannot open private connector file.' );
        }
        try {
            $opened = fstat( $handle );
            if ( $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'] ) {
                throw new RuntimeException( 'Private connector file changed during opening.' );
            }
            $value = stream_get_contents( $handle );
            if ( $value === false ) {
                throw new RuntimeException( 'Cannot read private connector file.' );
            }
            return $value;
        } finally {
            fclose( $handle );
        }
    }

    private function atomic( string $name, string $contents ): void {
        $path = $this->directory . '/' . $name;
        if ( file_exists( $path ) || is_link( $path ) ) {
            self::private_file( $path );
        }
        $scratch = $path . '.pending-' . bin2hex( random_bytes( 8 ) );
        $old = umask( 0077 );
        try {
            $file = fopen( $scratch, 'xb' );
        } finally {
            umask( $old );
        }
        if ( ! $file ) {
            throw new RuntimeException( 'Cannot persist catalog checkpoint.' );
        }
        try {
            $offset = 0;
            while ( $offset < strlen( $contents ) ) {
                $written = fwrite( $file, substr( $contents, $offset ) );
                if ( ! $written ) {
                    throw new RuntimeException( 'Incomplete catalog checkpoint write.' );
                }
                $offset += $written;
            }
            if ( ! fflush( $file ) || ! fsync( $file ) ) {
                throw new RuntimeException( 'Cannot flush catalog checkpoint.' );
            }
        } finally {
            fclose( $file );
        }
        if ( ! rename( $scratch, $path ) ) {
            unlink( $scratch );
            throw new RuntimeException( 'Cannot replace catalog checkpoint.' );
        }
    }

    private function save(): void {
        $this->atomic( 'catalog-state.json', self::json( $this->state ) . "\n" );
    }

    private static function json( mixed $value ): string {
        return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    private static function run_tag( string $runId ): string {
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $runId ) ) {
            throw new InvalidArgumentException( 'Invalid connector run identity.' );
        }
        return 'ultralove-catalog-run-' . $runId;
    }

    private static function media_url( string $url ): string {
        $url = str_replace( 'https://dev.ultralove.ca/wp-content/uploads/', 'https://ultralove.ca/wp-content/uploads/', $url );
        $parsed = parse_url( $url );
        if ( ! is_array( $parsed ) || ( $parsed['scheme'] ?? '' ) !== 'https'
            || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['port'] )
            || isset( $parsed['fragment'] ) ) {
            throw new InvalidArgumentException( 'Unsafe source media URL.' );
        }
        $host = strtolower( $parsed['host'] ?? '' );
        if ( $host !== 'res.cloudinary.com'
            && ! ( in_array( $host, array( 'ultralove.ca', 'dev.ultralove.ca' ), true )
                && str_starts_with( $parsed['path'] ?? '', '/wp-content/uploads/' ) ) ) {
            throw new InvalidArgumentException( 'Source image is not on an approved public media host.' );
        }
        return $url;
    }

    private static function variant( array $record, string $location, float $markup, array &$reviews ): array {
        $id = (int) ( $record['id'] ?? 0 );
        $price = $record['price'] ?? null;
        $validPrice = is_numeric( $price ) && is_finite( (float) $price ) && (float) $price >= 0;
        $sku = trim( (string) ( $record['sku'] ?? '' ) );
        $quantity = $record['stock_quantity'] ?? null;
        $owner = (int) ( $record['stock_owner_id'] ?? 0 );
        $safe = true;
        foreach ( array(
            'missing-price' => ! $validPrice,
            'missing-sku' => $sku === '',
            'unmanaged-stock' => ( $record['manage_stock'] ?? null ) !== true,
            'unknown-stock' => ! is_int( $quantity ) || $quantity < 0 || $owner <= 0
                || ! in_array( $record['stock_status'] ?? '', array( 'instock', 'outofstock', 'onbackorder' ), true ),
            'shared-stock' => $owner > 0 && $owner !== $id,
            'unsupported-type' => ! in_array( $record['type'] ?? '', array( 'simple', 'variation' ), true ),
        ) as $code => $problem ) {
            if ( $problem ) {
                $reviews[] = array( 'id' => $id, 'code' => $code );
                $safe = false;
            }
        }
        $markedPrice = $validPrice ? (float) $price * ( 1 + $markup / 100 ) : 0;
        if ( ! is_finite( $markedPrice ) ) {
            throw new RuntimeException( 'Source price exceeds a finite Shopify price.' );
        }
        $result = array(
            'sku' => $sku,
            'price' => number_format( $markedPrice, 2, '.', '' ),
            'inventoryPolicy' => 'DENY',
            'inventoryItem' => array( 'tracked' => true, 'requiresShipping' => (bool) ( $record['requires_shipping'] ?? true ) ),
            'inventoryQuantities' => array( array(
                'locationId' => $location, 'name' => 'available',
                'quantity' => $safe && ( $record['stock_status'] ?? '' ) === 'instock' ? $quantity : 0,
            ) ),
            'metafields' => array( array(
                'namespace' => 'ultralove_catalog', 'key' => 'supplier_id', 'type' => 'single_line_text_field', 'value' => (string) $id,
            ) ),
        );
        if ( ! empty( $record['global_unique_id'] ) ) {
            $result['barcode'] = (string) $record['global_unique_id'];
        }
        return $result;
    }

    public static function payload( array $record, array $catalog, string $location, string $runId, float $markup = 0 ): array {
        $rootCount = 0;
        foreach ( $catalog as $candidate ) {
            if ( (int) ( $candidate['parent_id'] ?? 0 ) === 0 && ++$rootCount > self::MAX_ROOTS ) {
                throw new RuntimeException( 'Payload planning is limited to 50 explicitly selected roots.' );
            }
        }
        $id = (int) ( $record['id'] ?? 0 );
        if ( $id <= 0 || ! is_finite( $markup ) || $markup < 0 || $markup > 1000
            || ! preg_match( '~^gid://shopify/Location/[0-9]+$~D', $location ) ) {
            throw new InvalidArgumentException( 'Invalid supplier payload identity, location or markup.' );
        }
        $tag = self::run_tag( $runId );
        $reviews = array();
        $children = array();
        if ( ( $record['type'] ?? '' ) === 'variable' ) {
            foreach ( $record['variations'] ?? array() as $childId ) {
                if ( ! isset( $catalog[$childId] ) || (int) ( $catalog[$childId]['parent_id'] ?? 0 ) !== $id
                    || (int) ( $catalog[$childId]['id'] ?? 0 ) !== (int) $childId ) {
                    throw new RuntimeException( 'Variable product references a missing or unrelated feed variant.' );
                }
                if ( isset( $children[$childId] ) ) {
                    throw new RuntimeException( 'Repeated source variation identity.' );
                }
                $children[$childId] = $catalog[$childId];
            }
            // Also retain published feed children absent from the parent's variation list.
            foreach ( $catalog as $childId => $child ) {
                if ( (int) ( $child['parent_id'] ?? 0 ) === $id ) {
                    $children[$childId] = $child;
                }
            }
            ksort( $children, SORT_NUMERIC );
            if ( ! $children ) {
                $reviews[] = array( 'id' => $id, 'code' => 'empty-variable' );
            }
        }
        $sources = $children ?: array( $id => $record );
        if ( count( $sources ) > 2048 ) {
            throw new RuntimeException( 'Shopify productSet variant limit (2048) exceeded for supplier ' . $id . '.' );
        }
        $native = ! empty( $children );
        $names = array();
        $values = array();
        $combos = array();
        foreach ( $sources as $sourceId => $source ) {
            $attributes = array();
            foreach ( $source['attributes'] ?? array() as $attribute ) {
                $name = trim( (string) ( $attribute['name'] ?? '' ) );
                $options = $attribute['options'] ?? array();
                if ( $name === '' || count( $options ) !== 1 || trim( (string) $options[0] ) === '' || isset( $attributes[$name] ) ) {
                    $native = false;
                    continue;
                }
                $attributes[$name] = (string) $options[0];
            }
            ksort( $attributes, SORT_STRING );
            $sourceNames = array_keys( $attributes );
            if ( ! $names ) {
                $names = $sourceNames;
            }
            if ( $sourceNames !== $names || count( $names ) < 1 || count( $names ) > 3 ) {
                $native = false;
            }
            $combo = self::json( $attributes );
            if ( isset( $combos[$combo] ) ) {
                $native = false;
            }
            $combos[$combo] = true;
            $values[$sourceId] = $attributes;
        }
        $optionValues = array();
        $variants = array();
        foreach ( $sources as $sourceId => $source ) {
            $variant = self::variant( $source, $location, $markup, $reviews );
            if ( ( $record['type'] ?? '' ) === 'variable' && ! $children ) {
                $variant['inventoryQuantities'][0]['quantity'] = 0;
            }
            $variant['optionValues'] = array();
            $selection = $native ? $values[$sourceId] : array(
                empty( $children ) ? 'Title' : 'Variant' => empty( $children ) ? 'Default Title'
                    : implode( ' / ', array_map( static fn( $name, $value ) => $name . ': ' . $value,
                        array_keys( $values[$sourceId] ), array_values( $values[$sourceId] ) ) ) . ' [supplier ' . $sourceId . ']',
            );
            foreach ( $selection as $name => $value ) {
                if ( strlen( $name ) > 255 || strlen( $value ) > 255 ) {
                    throw new RuntimeException( 'Shopify option length limit exceeded; no variant was omitted.' );
                }
                $optionValues[$name][$value] = $value;
                $variant['optionValues'][] = array( 'optionName' => $name, 'name' => $value );
            }
            $variants[] = $variant;
        }
        $files = array();
        foreach ( array( $record ) + $children as $source ) {
            if ( empty( $source['images'] ) ) {
                $reviews[] = array( 'id' => (int) $source['id'], 'code' => 'missing-image' );
            }
            foreach ( $source['data_warnings'] ?? array() as $warning ) {
                $reviews[] = array( 'id' => (int) $source['id'], 'warning' => $warning, 'code' => 'feed-warning' );
            }
            foreach ( $source['images'] ?? array() as $image ) {
                $url = self::media_url( (string) ( $image['src'] ?? '' ) );
                $files[$url] = array( 'originalSource' => $url, 'contentType' => 'IMAGE',
                    'alt' => (string) ( ( $image['alt'] ?? '' ) ?: ( $source['name'] ?? $record['name'] ) ) );
            }
        }
        if ( count( $files ) > 250 ) {
            throw new RuntimeException( 'Shopify product media limit (250) exceeded; no media was omitted.' );
        }
        $tags = array( 'ultralove-catalog-import', $tag );
        foreach ( $reviews as $review ) {
            $tags[] = 'ultralove-review-' . $review['code'];
        }
        if ( $reviews ) {
            $tags[] = 'ultralove-needs-review';
        }
        $options = array();
        foreach ( $optionValues as $name => $option ) {
            $options[] = array( 'name' => $name, 'position' => count( $options ) + 1,
                'values' => array_map( static fn( $value ) => array( 'name' => $value ), array_values( $option ) ) );
        }
        $input = array(
            'title' => (string) ( $record['name'] ?? '' ), 'descriptionHtml' => (string) ( $record['description'] ?? '' ),
            'handle' => 'ultralove-catalog-' . $id, 'status' => 'DRAFT', 'vendor' => 'UltraLove',
            'tags' => array_values( array_unique( $tags ) ), 'productOptions' => $options, 'variants' => $variants,
            'metafields' => array( array( 'namespace' => 'ultralove_catalog', 'key' => 'supplier_id',
                'type' => 'single_line_text_field', 'value' => (string) $id ) ),
        );
        if ( $input['title'] === '' ) {
            throw new RuntimeException( 'Supplier product has no title.' );
        }
        if ( $files ) {
            $input['files'] = array_values( $files );
        }
        if ( $reviews ) {
            $input['metafields'][] = array( 'namespace' => 'ultralove_catalog', 'key' => 'review',
                'type' => 'json', 'value' => self::json( $reviews ) );
        }
        return $input;
    }

    private static function allowed_url( string $url, bool $shop ): void {
        $parts = parse_url( $url );
        $hosts = $shop ? array( self::SHOP ) : array( 'shopify-staged-uploads.storage.googleapis.com', 'storage.googleapis.com' );
        if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https'
            || ! in_array( $parts['host'] ?? '', $hosts, true )
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
            throw new RuntimeException( 'Shopify returned an unapproved HTTPS endpoint.' );
        }
    }

    private function http( string $url, ?array $body = null, bool $authenticated = false, bool $multipart = false ): string {
        self::allowed_url( $url, $authenticated || str_starts_with( $url, 'https://' . self::SHOP . '/' ) );
        $curl = curl_init( $url );
        $headers = array();
        if ( $authenticated ) {
            $headers[] = 'X-Shopify-Access-Token: ' . $this->token;
        }
        $options = array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 900, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2 );
        if ( $body !== null ) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $multipart ? $body : self::json( $body );
            if ( ! $multipart ) {
                $headers[] = 'Content-Type: application/json';
            }
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array( $curl, $options );
        $response = curl_exec( $curl );
        $status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        $error = curl_errno( $curl );
        curl_close( $curl );
        if ( $response === false || $status < 200 || $status >= 300 ) {
            // Never echo a signed storage URL, token, credentials or an OAuth response.
            throw new RuntimeException( 'Connector HTTP failure: status ' . $status . ', curl code ' . $error . '. No mutation was retried.' );
        }
        return $response;
    }

    private function graphql( string $query, array $variables = array() ): array {
        $wait = $this->nextQueryAt - microtime( true );
        if ( $wait > 0 ) {
            $seconds = (int) floor( $wait );
            if ( $seconds > 0 ) {
                sleep( $seconds );
            }
            usleep( (int) ceil( ( $wait - $seconds ) * 1000000 ) );
        }
        $result = json_decode( $this->http( 'https://' . self::SHOP . '/admin/api/2026-10/graphql.json',
            array( 'query' => $query, 'variables' => (object) $variables ), true ), true, 512, JSON_THROW_ON_ERROR );
        $cost = $result['extensions']['cost'] ?? null;
        $this->nextQueryAt = microtime( true ) + self::throttle_delay( $cost );
        if ( ! empty( $result['errors'] ) || ! isset( $result['data'] ) ) {
            throw new RuntimeException( 'Shopify GraphQL failure: ' . self::json( $result['errors'] ?? array( 'missing data' ) ) );
        }
        foreach ( $result['data'] as $field => $value ) {
            if ( is_array( $value ) && ! empty( $value['userErrors'] ) ) {
                throw new RuntimeException( 'Shopify ' . $field . ' rejected: ' . self::json( $value['userErrors'] ) );
            }
        }
        return $result['data'];
    }

    private static function throttle_delay( ?array $cost ): float {
        if ( ! isset( $cost['throttleStatus'] ) ) {
            return 0;
        }
        $throttle = $cost['throttleStatus'];
        foreach ( array( 'maximumAvailable', 'currentlyAvailable', 'restoreRate' ) as $field ) {
            if ( ! is_numeric( $throttle[$field] ?? null ) || ! is_finite( (float) $throttle[$field] )
                || (float) $throttle[$field] < 0 ) {
                throw new RuntimeException( 'Shopify returned invalid query throttle metadata; no request was retried.' );
            }
        }
        $reserve = min( (float) $throttle['maximumAvailable'], max( 500, (float) ( $cost['requestedQueryCost'] ?? 0 ) ) );
        $deficit = max( 0, $reserve - (float) $throttle['currentlyAvailable'] );
        if ( $deficit > 0 && (float) $throttle['restoreRate'] <= 0 ) {
            throw new RuntimeException( 'Shopify query capacity cannot recover; no request was retried.' );
        }
        return $deficit > 0 ? $deficit / (float) $throttle['restoreRate'] : 0;
    }

    private function connect(): void {
        $response = json_decode( $this->http( 'https://' . self::SHOP . '/admin/oauth/access_token', array(
            'grant_type' => 'client_credentials',
            'client_id' => trim( self::private_file( self::BASE . 'shopify-client-id' ) ),
            'client_secret' => trim( self::private_file( self::BASE . 'shopify-client-secret' ) ),
        ) ), true, 512, JSON_THROW_ON_ERROR );
        if ( ! is_string( $response['access_token'] ?? null ) || $response['access_token'] === '' ) {
            throw new RuntimeException( 'Existing connector OAuth credentials did not yield an access token.' );
        }
        $this->token = $response['access_token'];
        $data = $this->graphql( '{shop {myshopifyDomain currencyCode}}' );
        if ( ( $data['shop']['myshopifyDomain'] ?? '' ) !== self::SHOP || ( $data['shop']['currencyCode'] ?? '' ) !== 'CAD' ) {
            throw new RuntimeException( 'Catalog writes require the exact CAD integration-lab shop.' );
        }
        $locations = $this->connection( 'locations', 'id name isActive fulfillsOnlineOrders fulfillmentService {id}', 100 );
        $eligible = array_values( array_filter( $locations, static fn( $l ) => $l['isActive']
            && $l['name'] === 'Shop location' && $l['fulfillsOnlineOrders'] && $l['fulfillmentService'] === null ) );
        if ( count( $eligible ) !== 1 ) {
            throw new RuntimeException( 'Exactly one active online Shop location without a fulfillment service is required.' );
        }
        $this->location = $eligible[0]['id'];
        if ( isset( $this->state['location'] ) && $this->state['location'] !== $this->location ) {
            throw new RuntimeException( 'Shop location changed since the import checkpoint.' );
        }
    }

    private function connection( string $field, string $selection, int $first, ?string $parent = null ): array {
        $nodes = array();
        $cursor = null;
        do {
            $query = 'query($after:String){' . $field . '(first:' . $first . ',after:$after){nodes{' . $selection . '} pageInfo{hasNextPage endCursor}}}';
            if ( $parent !== null ) {
                $query = 'query($after:String,$id:ID!){product(id:$id){' . $field . '(first:' . $first
                    . ',after:$after){nodes{' . $selection . '} pageInfo{hasNextPage endCursor}}}}';
            }
            $variables = array( 'after' => $cursor );
            if ( $parent !== null ) {
                $variables['id'] = $parent;
            }
            $data = $this->graphql( $query, $variables );
            $page = $parent === null ? ( $data[$field] ?? null ) : ( $data['product'][$field] ?? null );
            if ( ! is_array( $page ) || ! isset( $page['nodes'], $page['pageInfo']['hasNextPage'] ) ) {
                throw new RuntimeException( 'Missing Shopify paginated connection.' );
            }
            array_push( $nodes, ...$page['nodes'] );
            $next = $page['pageInfo']['endCursor'] ?? null;
            if ( $page['pageInfo']['hasNextPage'] && ( ! $next || $next === $cursor ) ) {
                throw new RuntimeException( 'Shopify pagination did not advance.' );
            }
            $cursor = $next;
        } while ( $page['pageInfo']['hasNextPage'] );
        return $nodes;
    }

    private function remote_catalog(): array {
        return $this->connection( 'products',
            'id handle title descriptionHtml status tags supplierId:metafield(namespace:"ultralove_catalog",key:"supplier_id"){value} variants(first:1){nodes{sku}}', 100 );
    }

    private static function catalog_identity( array $product, int $supplierId ): bool {
        return ( $product['handle'] ?? '' ) === 'ultralove-catalog-' . $supplierId
            && ( $product['supplierId']['value'] ?? '' ) === (string) $supplierId
            && in_array( 'ultralove-catalog-import', $product['tags'] ?? array(), true )
            && count( array_filter( $product['tags'] ?? array(),
                static fn( $tag ) => is_string( $tag ) && preg_match( '/^ultralove-catalog-run-[a-f0-9]{32}$/D', $tag ) ) ) > 0;
    }

    private static function identity( array $product, int $supplierId, string $runId ): bool {
        return self::catalog_identity( $product, $supplierId )
            && in_array( self::run_tag( $runId ), $product['tags'] ?? array(), true );
    }

    private static function match( array $remote, int $id, string $runId ): ?array {
        $matches = array();
        foreach ( $remote as $product ) {
            $sameHandle = $product['handle'] === 'ultralove-catalog-' . $id;
            $sameSupplier = ( $product['supplierId']['value'] ?? null ) === (string) $id;
            $legacySku = self::LEGACY[$id] ?? null;
            $sameLegacy = $legacySku !== null && in_array( 'ultralove-dev-supplier', $product['tags'], true )
                && ( $product['variants']['nodes'][0]['sku'] ?? null ) === $legacySku;
            if ( $sameHandle || $sameSupplier ) {
                if ( ! self::catalog_identity( $product, $id ) ) {
                    throw new RuntimeException( 'Catalog handle or supplier identity collision for ' . $id . '.' );
                }
                $matches[$product['id']] = array( 'product' => $product, 'created' => self::identity( $product, $id, $runId ) );
            }
            if ( $sameLegacy ) {
                if ( $product['handle'] !== 'ultralove-dev-' . $legacySku ) {
                    throw new RuntimeException( 'Legacy supplier SKU has an unexpected handle.' );
                }
                $matches[$product['id']] = array( 'product' => $product, 'created' => false );
            }
        }
        if ( count( $matches ) > 1 ) {
            throw new RuntimeException( 'Duplicate remote supplier identities for ' . $id . '.' );
        }
        return $matches ? array_values( $matches )[0] : null;
    }

    private function snapshot( string $id ): array {
        $data = $this->graphql( 'query($id:ID!){product(id:$id){id handle title descriptionHtml status tags supplierId:metafield(namespace:"ultralove_catalog",key:"supplier_id"){value}}}', array( 'id' => $id ) );
        $product = $data['product'] ?? null;
        if ( ! $product ) {
            throw new RuntimeException( 'Mapped Shopify product is missing: ' . $id . '.' );
        }
        $product['variants'] = $this->connection( 'variants',
            'id sku price barcode selectedOptions{name value} supplierId:metafield(namespace:"ultralove_catalog",key:"supplier_id"){value} inventoryItem{id tracked requiresShipping inventoryLevel(locationId:"'
                . $this->location . '"){quantities(names:["available"]){name quantity}}}', 50, $id );
        return $product;
    }

    private static function baseline( array $product ): array {
        // Only normalize order, not field values: preserve the existing retailer's exact fields.
        unset( $product['tags'], $product['supplierId'] );
        usort( $product['variants'], static fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );
        return $product;
    }

    private static function selected_roots( array $feedState ): array {
        $catalog = $feedState['products'] ?? null;
        if ( ! is_array( $catalog ) || ! $catalog || ! is_string( $feedState['generation'] ?? null )
            || ! is_int( $feedState['cursor'] ?? null ) ) {
            throw new InvalidArgumentException( 'Expected the retained HTTP-feed checkpoint for an explicit selected batch.' );
        }
        $roots = array();
        foreach ( $catalog as $id => $record ) {
            if ( ! ctype_digit( (string) $id ) || (int) $id <= 0
                || (int) ( $record['id'] ?? 0 ) !== (int) $id || ( $record['currency'] ?? '' ) !== 'CAD' ) {
                throw new RuntimeException( 'Invalid supplier feed identity or currency.' );
            }
            if ( (int) ( $record['parent_id'] ?? 0 ) === 0 ) {
                $roots[(int) $id] = $record;
            } elseif ( ! isset( $catalog[$record['parent_id']] )
                || ( $catalog[$record['parent_id']]['type'] ?? '' ) !== 'variable' || ( $record['type'] ?? '' ) !== 'variation' ) {
                throw new RuntimeException( 'Orphan or unsupported variation cannot be silently omitted.' );
            }
        }
        ksort( $roots, SORT_NUMERIC );
        if ( ! $roots ) {
            throw new RuntimeException( 'Feed contains no catalog roots.' );
        }
        if ( count( $roots ) > self::MAX_ROOTS ) {
            throw new RuntimeException( 'Explicit connector import batches are limited to 50 selected root products; variants do not count toward this limit.' );
        }
        return $roots;
    }

    private static function bind_feed( array $state, string $digest ): void {
        if ( ( $state['feed_digest'] ?? null ) !== $digest || ! empty( $state['cleanup_started'] ) ) {
            throw new RuntimeException( 'Selected feed changed, or this batch entered cleanup; never recreate deleted products.' );
        }
    }

    private static function plan( array $roots, array $catalog, array $remote, array $state, string $location,
        callable $snapshot, callable $checkpoint ): array {
        if ( count( $roots ) > self::MAX_ROOTS ) {
            throw new RuntimeException( 'Planning requires at most 50 explicitly selected roots.' );
        }
        $jsonl = '';
        $state['lines'] = array();
        foreach ( $roots as $id => $record ) {
            $found = self::match( $remote, $id, $state['run_id'] );
            if ( $found ) {
                $product = $found['product'];
                if ( isset( $state['mappings'][$id] ) && $state['mappings'][$id]['id'] !== $product['id'] ) {
                    throw new RuntimeException( 'Saved supplier mapping was replaced during planning.' );
                }
                $state['mappings'][$id] = array( 'id' => $product['id'], 'created' => $found['created'] );
                if ( ! $found['created'] ) {
                    $existing = $snapshot( $product['id'] );
                    if ( isset( self::LEGACY[$id] ) && ( ( $record['sku'] ?? null ) !== self::LEGACY[$id]
                        || count( $existing['variants'] ) !== 1 || $existing['variants'][0]['sku'] !== self::LEGACY[$id] ) ) {
                        throw new RuntimeException( 'Legacy source or single-variant SKU does not match the approved identity.' );
                    }
                    $baseline = self::baseline( $existing );
                    if ( isset( $state['baseline'][$id] ) && $state['baseline'][$id] !== $baseline ) {
                        throw new RuntimeException( 'Existing retailer listing changed during planning; baseline will not be rewritten.' );
                    }
                    $state['baseline'][$id] = $baseline;
                }
                $checkpoint( $state );
                continue;
            }
            if ( isset( $state['mappings'][$id] ) ) {
                throw new RuntimeException( 'Saved supplier mapping disappeared during planning; do not recreate.' );
            }
            // The four existing bridge listings are prerequisites, never replacement creations.
            if ( isset( self::LEGACY[$id] ) ) {
                throw new RuntimeException( 'Required legacy supplier listing is missing: ' . $id . '.' );
            }
            $input = self::payload( $record, $catalog, $location, $state['run_id'] );
            $state['lines'][] = $id;
            $jsonl .= self::json( array( 'input' => $input ) ) . "\n";
        }
        return array( 'state' => $state, 'jsonl' => $jsonl );
    }

    public function run( array $feedState ): void {
        self::runtime();
        // Reject an unbounded selection before OAuth, uploads or any Shopify mutation.
        $roots = self::selected_roots( $feedState );
        $catalog = $feedState['products'];
        $digest = hash( 'sha256', self::json( $feedState ) );
        if ( ! $this->state ) {
            $this->state = array( 'version' => 1, 'run_id' => bin2hex( random_bytes( 16 ) ),
                'checkpoint_sha256' => $this->checkpoint, 'feed_digest' => $digest, 'phase' => 'planning',
                'mappings' => array(), 'created_products' => array(), 'baseline' => array(), 'lines' => array(), 'errors' => array() );
            $this->save();
        }
        self::bind_feed( $this->state, $digest );
        $this->connect();
        $this->state['location'] = $this->location;
        $this->save();
        if ( $this->state['phase'] === 'planning' ) {
            $plan = self::plan( $roots, $catalog, $this->remote_catalog(), $this->state, $this->location,
                fn( $id ) => $this->snapshot( $id ),
                function ( $state ): void {
                    $this->state = $state;
                    $this->save();
                } );
            $this->state = $plan['state'];
            $jsonl = $plan['jsonl'];
            if ( strlen( $jsonl ) > 100000000 ) {
                throw new RuntimeException( 'Catalog JSONL exceeds the Shopify 100 MB bulk upload limit.' );
            }
            $this->atomic( 'catalog.jsonl', $jsonl );
            $this->state['jsonl_sha256'] = hash( 'sha256', $jsonl );
            $this->state['mutation'] = 'mutation UltraLoveCatalog_' . $this->state['run_id']
                . '($input:ProductSetInput!){productSet(input:$input,synchronous:true){product{id handle status} userErrors{field message}}}';
            $this->state['phase'] = $this->state['lines'] ? 'prepared' : 'verifying';
            $this->save();
        }
        if ( $this->state['phase'] === 'prepared' ) {
            $jsonl = self::private_file( $this->directory . '/catalog.jsonl' );
            if ( ! hash_equals( $this->state['jsonl_sha256'], hash( 'sha256', $jsonl ) ) ) {
                throw new RuntimeException( 'Catalog JSONL no longer matches its immutable line ledger.' );
            }
            $staged = $this->graphql( 'mutation($input:[StagedUploadInput!]!){stagedUploadsCreate(input:$input){stagedTargets{url parameters{name value}} userErrors{field message}}}',
                array( 'input' => array( array( 'resource' => 'BULK_MUTATION_VARIABLES', 'filename' => 'catalog.jsonl',
                    'mimeType' => 'text/jsonl', 'httpMethod' => 'POST' ) ) ) );
            $targets = $staged['stagedUploadsCreate']['stagedTargets'] ?? array();
            if ( count( $targets ) !== 1 ) {
                throw new RuntimeException( 'Shopify did not return exactly one staged catalog upload.' );
            }
            self::allowed_url( $targets[0]['url'], false );
            $fields = array();
            foreach ( $targets[0]['parameters'] as $parameter ) {
                if ( isset( $fields[$parameter['name']] ) || $parameter['name'] === 'file' ) {
                    throw new RuntimeException( 'Unexpected staged upload fields.' );
                }
                $fields[$parameter['name']] = $parameter['value'];
            }
            if ( empty( $fields['key'] ) ) {
                throw new RuntimeException( 'Staged upload has no storage key.' );
            }
            $fields['file'] = new CURLFile( $this->directory . '/catalog.jsonl', 'text/jsonl', 'catalog.jsonl' );
            $this->http( $targets[0]['url'], $fields, false, true );
            $this->state['phase'] = 'launching';
            $this->save(); // Persist ambiguity BEFORE the non-idempotent creation request.
            $data = $this->graphql( 'mutation($mutation:String!,$path:String!,$identifier:String!){bulkOperationRunMutation(mutation:$mutation,stagedUploadPath:$path,clientIdentifier:$identifier){bulkOperation{id status} userErrors{field message}}}',
                array( 'mutation' => $this->state['mutation'], 'path' => $fields['key'], 'identifier' => $this->state['run_id'] ) );
            $operation = $data['bulkOperationRunMutation']['bulkOperation'] ?? null;
            if ( ! $operation || empty( $operation['id'] ) ) {
                throw new RuntimeException( 'Bulk launch is uncertain; resume must recover its exact query, not launch again.' );
            }
            $this->state['operation'] = $operation['id'];
            $this->state['phase'] = 'running';
            $this->save();
        }
        if ( $this->state['phase'] === 'launching' ) {
            $this->recover_launch();
        }
        if ( in_array( $this->state['phase'], array( 'running', 'results' ), true ) ) {
            $this->wait_and_results();
        }
        if ( ! empty( $this->state['errors'] ) ) {
            throw new RuntimeException( 'Catalog bulk results contain errors; ownership is saved. Reconcile before any further import.' );
        }
        if ( in_array( $this->state['phase'], array( 'verifying', 'complete' ), true ) ) {
            $this->verify( $roots, $catalog );
        }
    }

    private function recover_launch(): void {
        $cursor = null;
        $matches = array();
        do {
            $data = $this->graphql( 'query($after:String){bulkOperations(first:50,after:$after,query:"operation_type:mutation"){nodes{id query} pageInfo{hasNextPage endCursor}}}', array( 'after' => $cursor ) );
            $page = $data['bulkOperations'] ?? null;
            if ( ! isset( $page['nodes'], $page['pageInfo']['hasNextPage'] ) ) {
                throw new RuntimeException( 'Cannot recover uncertain bulk launch.' );
            }
            foreach ( $page['nodes'] as $operation ) {
                if ( ( $operation['query'] ?? '' ) === $this->state['mutation'] ) {
                    $matches[] = $operation;
                }
            }
            $next = $page['pageInfo']['endCursor'] ?? null;
            if ( $page['pageInfo']['hasNextPage'] && ( ! $next || $cursor === $next ) ) {
                throw new RuntimeException( 'Bulk recovery pagination did not advance.' );
            }
            $cursor = $next;
        } while ( $page['pageInfo']['hasNextPage'] );
        $this->state['operation'] = self::recovered_operation( $matches, $this->state['mutation'] );
        $this->state['phase'] = 'running';
        $this->save();
    }

    private static function recovered_operation( array $operations, string $mutation ): string {
        $matches = array_values( array_filter( $operations, static fn( $operation ) => ( $operation['query'] ?? null ) === $mutation ) );
        if ( count( $matches ) !== 1 || ! preg_match( '~^gid://shopify/BulkOperation/[0-9]+$~D', $matches[0]['id'] ?? '' ) ) {
            throw new RuntimeException( 'Uncertain launch has zero or duplicate exact-query matches. Do not relaunch; reconcile with Shopify.' );
        }
        return $matches[0]['id'];
    }

    private function operation(): array {
        $data = $this->graphql( 'query($id:ID!){bulkOperation(id:$id){id status objectCount errorCode url partialDataUrl}}',
            array( 'id' => $this->state['operation'] ) );
        $operation = $data['bulkOperation'] ?? null;
        if ( ! $operation || $operation['id'] !== $this->state['operation'] ) {
            throw new RuntimeException( 'Saved bulk operation is missing or changed.' );
        }
        return $operation;
    }

    private static function terminal( string $status ): bool {
        return in_array( $status, array( 'COMPLETED', 'FAILED', 'CANCELED', 'EXPIRED' ), true );
    }

    private static function result( array $row, array $lines ): array {
        $number = $row['__lineNumber'] ?? null;
        if ( ! is_int( $number ) || ! array_key_exists( $number, $lines ) ) {
            throw new RuntimeException( 'Bulk result has an unknown source line identity.' );
        }
        $set = $row['data']['productSet'] ?? $row['productSet'] ?? null;
        $product = is_array( $set ) ? ( $set['product'] ?? null ) : null;
        if ( $product !== null && ( ! preg_match( '~^gid://shopify/Product/[0-9]+$~D', $product['id'] ?? '' )
            || ( $product['handle'] ?? '' ) !== 'ultralove-catalog-' . $lines[$number] ) ) {
            throw new RuntimeException( 'Bulk created-product identity does not match its immutable source line.' );
        }
        $errors = array_merge( $row['errors'] ?? array(), is_array( $set ) ? ( $set['userErrors'] ?? array() ) : array() );
        if ( ! $set || ! $product ) {
            $errors[] = array( 'message' => 'Missing productSet created-product result.' );
        }
        if ( $product && ( $product['status'] ?? '' ) !== 'DRAFT' ) {
            $errors[] = array( 'message' => 'Created product is not DRAFT.' );
        }
        return array( 'line' => $number, 'supplier' => $lines[$number], 'product' => $product, 'errors' => $errors );
    }

    private function wait_and_results(): void {
        do {
            $operation = $this->operation();
            $status = $operation['status'];
            if ( ! self::terminal( $status ) && ! in_array( $status, array( 'CREATED', 'RUNNING', 'CANCELING' ), true ) ) {
                throw new RuntimeException( 'Unknown bulk operation status.' );
            }
            $this->state['bulk_status'] = $status;
            $this->save();
            echo 'Catalog bulk: ' . $status . ', objects ' . (int) $operation['objectCount'] . ".\n";
            if ( ! self::terminal( $status ) ) {
                sleep( 15 );
            }
        } while ( ! self::terminal( $status ) );
        $this->state['phase'] = 'results';
        $this->save();
        $url = $status === 'COMPLETED' ? $operation['url'] : ( $operation['partialDataUrl'] ?: $operation['url'] );
        $seen = array();
        if ( $url ) {
            $contents = $this->http( $url );
            $this->atomic( 'catalog-results.jsonl', $contents );
            foreach ( explode( "\n", $contents ) as $line ) {
                if ( trim( $line ) === '' ) {
                    continue;
                }
                try {
                    $result = self::result( json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), $this->state['lines'] );
                    if ( isset( $seen[$result['line']] ) ) {
                        throw new RuntimeException( 'Duplicate bulk output line.' );
                    }
                    $seen[$result['line']] = true;
                    $id = $result['supplier'];
                    if ( $result['product'] ) {
                        $this->state['created_products'][$result['product']['id']] = array(
                            'id' => $result['product']['id'], 'created' => true, 'supplier_id' => $id,
                        );
                        $prior = $this->state['mappings'][$id]['id'] ?? $result['product']['id'];
                        if ( $prior !== $result['product']['id'] ) {
                            throw new RuntimeException( 'Bulk output replaced an existing mapped product.' );
                        }
                        $this->state['mappings'][$id] = array( 'id' => $result['product']['id'], 'created' => true );
                    }
                    if ( $result['errors'] ) {
                        $this->state['errors']['line-' . $result['line']] = $result['errors'];
                    }
                } catch ( Throwable $error ) {
                    $this->state['errors']['result-' . count( $seen )] = $error->getMessage();
                }
                $this->save();
            }
        }
        // Query run-owned handles as well, including creations whose result line was lost.
        foreach ( $this->remote_catalog() as $product ) {
            $id = (int) ( $product['supplierId']['value'] ?? 0 );
            if ( in_array( self::run_tag( $this->state['run_id'] ), $product['tags'], true ) ) {
                $this->state['created_products'][$product['id']] = array(
                    'id' => $product['id'], 'created' => true, 'supplier_id' => $id,
                );
                if ( $id <= 0 || ! self::identity( $product, $id, $this->state['run_id'] ) ) {
                    $this->state['errors']['unidentified-' . $product['id']] = 'Run-tagged creation requires manual identity reconciliation.';
                    $this->save();
                    continue;
                }
                $prior = $this->state['mappings'][$id]['id'] ?? $product['id'];
                if ( $prior !== $product['id'] || ! in_array( $id, $this->state['lines'], true ) ) {
                    $this->state['errors']['identity-' . $id] = 'Unexpected duplicate or unplanned run-owned product.';
                } else {
                    $this->state['mappings'][$id] = array( 'id' => $product['id'], 'created' => true );
                }
                $this->save();
            }
        }
        foreach ( $this->state['lines'] as $number => $id ) {
            if ( ! isset( $seen[$number] ) ) {
                $this->state['errors']['missing-' . $number] = 'Missing bulk result for supplier ' . $id . '.';
            }
        }
        if ( $status !== 'COMPLETED' || ! empty( $operation['errorCode'] ) ) {
            $this->state['errors']['operation'] = array( 'status' => $status, 'errorCode' => $operation['errorCode'] );
        }
        $this->state['phase'] = 'verifying';
        $this->save();
    }

    private function verify( array $roots, array $catalog ): void {
        $this->state['verification_errors'] = array();
        $this->save();
        foreach ( $roots as $id => $record ) {
            try {
                $mapping = $this->state['mappings'][$id] ?? null;
                if ( ! $mapping || ! empty( $mapping['deleted'] ) ) {
                    throw new RuntimeException( 'Supplier root has no live Shopify mapping.' );
                }
                $product = $this->snapshot( $mapping['id'] );
                if ( ! $mapping['created'] ) {
                    if ( self::baseline( $product ) !== $this->state['baseline'][$id] ) {
                        throw new RuntimeException( 'Existing retailer listing changed from its preserved baseline; no corrective write performed.' );
                    }
                } else {
                    $input = self::payload( $record, $catalog, $this->location, $this->state['run_id'] );
                    if ( ! self::identity( $product, $id, $this->state['run_id'] )
                        || $product['status'] !== 'DRAFT' || $product['title'] !== $input['title']
                        || $product['descriptionHtml'] !== $input['descriptionHtml']
                        || count( $product['variants'] ) !== count( $input['variants'] ) ) {
                        throw new RuntimeException( 'New draft fields or variant coverage differ; retailer edits will not be overwritten.' );
                    }
                    $expected = array();
                    foreach ( $input['variants'] as $variant ) {
                        $expected[$variant['metafields'][0]['value']] = $variant;
                    }
                    foreach ( $product['variants'] as $variant ) {
                        $sourceId = $variant['supplierId']['value'] ?? '';
                        $wanted = $expected[$sourceId] ?? null;
                        $inventory = $variant['inventoryItem'];
                        $available = $inventory['inventoryLevel']['quantities'][0]['quantity'] ?? null;
                        $actualOptions = array_column( $variant['selectedOptions'], 'value', 'name' );
                        $wantedOptions = $wanted ? array_column( $wanted['optionValues'], 'name', 'optionName' ) : array();
                        ksort( $actualOptions );
                        ksort( $wantedOptions );
                        if ( ! $wanted || $variant['sku'] !== $wanted['sku']
                            || number_format( (float) $variant['price'], 2, '.', '' ) !== $wanted['price']
                            || (string) $variant['barcode'] !== ( $wanted['barcode'] ?? '' )
                            || ! $inventory['tracked'] || $inventory['requiresShipping'] !== $wanted['inventoryItem']['requiresShipping']
                            || $available !== $wanted['inventoryQuantities'][0]['quantity'] || $actualOptions !== $wantedOptions ) {
                            throw new RuntimeException( 'Draft source variant, SKU, price, barcode, options or stock verification failed.' );
                        }
                        unset( $expected[$sourceId] );
                    }
                    if ( $expected ) {
                        throw new RuntimeException( 'Source variant was omitted.' );
                    }
                    $media = $this->connection( 'media', 'id status mediaErrors{code message}', 100, $mapping['id'] );
                    if ( count( $media ) !== count( $input['files'] ?? array() ) ) {
                        throw new RuntimeException( 'Draft media coverage differs from the immutable feed.' );
                    }
                    foreach ( $media as $item ) {
                        if ( $item['status'] !== 'READY' || $item['mediaErrors'] ) {
                            throw new RuntimeException( 'Draft media not READY: ' . self::json( $item ) . '. Resume verification after reconciliation; never recreate.' );
                        }
                    }
                }
            } catch ( Throwable $error ) {
                $this->state['verification_errors'][$id] = $error->getMessage();
            }
            if ( count( $this->state['verification_errors'] ) || $id % 100 === 0 ) {
                $this->save();
            }
        }
        if ( count( $this->state['mappings'] ) !== count( $roots ) ) {
            $this->state['verification_errors']['coverage'] = 'Mapping/root coverage mismatch.';
        }
        $this->state['phase'] = $this->state['verification_errors'] ? 'verifying' : 'complete';
        $this->save();
        if ( $this->state['verification_errors'] ) {
            throw new RuntimeException( 'Catalog verification failed for ' . count( $this->state['verification_errors'] )
                . ' roots; details retained in the private state file. No existing listings were modified.' );
        }
        echo 'Catalog verified: ' . count( $roots ) . " roots, all source variants retained, existing listings unchanged.\n";
    }

    private static function deletable( array $mapping, array $product, int $id, string $runId ): bool {
        return ( $mapping['created'] ?? false ) === true && empty( $mapping['deleted'] )
            && ( $mapping['id'] ?? '' ) === ( $product['id'] ?? null )
            && ( $product['status'] ?? '' ) === 'DRAFT' && self::identity( $product, $id, $runId );
    }

    public function cleanup(): void {
        self::runtime();
        if ( ! $this->state ) {
            throw new RuntimeException( 'Cleanup requires a saved catalog run.' );
        }
        $this->connect();
        if ( $this->state['phase'] === 'launching' ) {
            $this->recover_launch();
        }
        if ( isset( $this->state['operation'] ) ) {
            if ( ! self::terminal( $this->operation()['status'] ) ) {
                throw new RuntimeException( 'Cleanup requires a terminal bulk operation.' );
            }
            if ( in_array( $this->state['phase'], array( 'running', 'results' ), true ) ) {
                $this->wait_and_results();
            }
        }
        $this->state['cleanup_started'] = true;
        $this->save();
        $owned = $this->state['created_products'] ?? array();
        foreach ( $this->state['mappings'] as $id => $mapping ) {
            if ( $mapping['created'] && ! isset( $owned[$mapping['id']] ) ) {
                $owned[$mapping['id']] = $mapping + array( 'supplier_id' => (int) $id );
            }
        }
        $this->state['created_products'] = $owned;
        $this->save();
        foreach ( $owned as $remoteId => $mapping ) {
            $id = $mapping['supplier_id'];
            if ( ! $mapping['created'] || ! empty( $mapping['deleted'] ) ) {
                continue;
            }
            if ( ! in_array( (int) $id, $this->state['lines'], true ) ) {
                throw new RuntimeException( 'Cleanup refused ownership not present in the immutable creation line ledger.' );
            }
            $data = $this->graphql( 'query($id:ID!){product(id:$id){id handle status tags supplierId:metafield(namespace:"ultralove_catalog",key:"supplier_id"){value}}}',
                array( 'id' => $mapping['id'] ) );
            if ( ! $data['product'] && ! empty( $mapping['deleting'] ) ) {
                $this->state['created_products'][$remoteId]['deleted'] = true;
                if ( ( $this->state['mappings'][$id]['id'] ?? null ) === $remoteId ) {
                    $this->state['mappings'][$id]['deleted'] = true;
                }
                $this->save();
                continue;
            }
            if ( ! $data['product'] || ! self::deletable( $mapping, $data['product'], (int) $id, $this->state['run_id'] ) ) {
                throw new RuntimeException( 'Cleanup refused reused, ACTIVE, missing or unrelated product ' . $id . '.' );
            }
            $this->state['created_products'][$remoteId]['deleting'] = true;
            $this->save();
            $data = $this->graphql( 'mutation($input:ProductDeleteInput!){productDelete(input:$input){deletedProductId userErrors{field message}}}',
                array( 'input' => array( 'id' => $mapping['id'] ) ) );
            if ( ( $data['productDelete']['deletedProductId'] ?? null ) !== $mapping['id'] ) {
                throw new RuntimeException( 'Cleanup deletion identity was not confirmed.' );
            }
            $this->state['created_products'][$remoteId]['deleted'] = true;
            if ( ( $this->state['mappings'][$id]['id'] ?? null ) === $remoteId ) {
                $this->state['mappings'][$id]['deleted'] = true;
            }
            $this->save();
        }
        $this->state['phase'] = 'cleaned';
        $this->save();
        echo "Catalog cleanup completed; reused and retailer-owned listings were retained.\n";
    }
}
