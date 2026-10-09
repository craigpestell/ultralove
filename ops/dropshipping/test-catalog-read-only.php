<?php
/**
 * Isolated catalog read mode and complete feed traversal; no DB or network.
 */
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'wordpress_dev' );
define( 'WP_ENVIRONMENT_TYPE', 'development' );
$hooks = array();
$cache = array();
$persistent = true;
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$hook] = $callback; }
function wp_using_ext_object_cache() { return $GLOBALS['persistent']; }
function wp_cache_add( $key, $value, $group, $expiry ) {
    if ( isset( $GLOBALS['cache'][$group][$key] ) ) { return false; }
    $GLOBALS['cache'][$group][$key] = $value;
    return true;
}
function wp_cache_incr( $key, $value, $group ) {
    if ( ! isset( $GLOBALS['cache'][$group][$key] ) ) { return false; }
    return $GLOBALS['cache'][$group][$key] += $value;
}
require __DIR__ . '/../../wp-content/mu-plugins/00-catalog-read-only.php';
require __DIR__ . '/../../wp-content/plugins/ultralove-shopify-supplier/includes/class-feed-client.php';
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
$assert( ! UltraLove_Catalog_Read_Only::active() && ! $hooks, 'Normal frontend/worker requests unchanged.' );
$_SERVER = array( 'HTTP_X_ULTRALOVE_CATALOG_READ_ONLY' => '1', 'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR' => '198.51.100.1', 'HTTP_HOST' => 'dev.ultralove.ca',
    'REQUEST_URI' => '/wp-json/ultralove-feed/v1/products?per_page=100' );
UltraLove_Catalog_Read_Only::init();
$assert( ! UltraLove_Catalog_Read_Only::active(), 'External requests cannot enable local catalog mode.' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
UltraLove_Catalog_Read_Only::init();
$assert( ! UltraLove_Catalog_Read_Only::active(), 'Writes/methods cannot enable catalog mode.' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'ultralove.ca';
UltraLove_Catalog_Read_Only::init();
$assert( ! UltraLove_Catalog_Read_Only::active(), 'Production is never enabled.' );
$_SERVER['HTTP_HOST'] = 'dev.ultralove.ca';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
UltraLove_Catalog_Read_Only::init();
$assert( ! UltraLove_Catalog_Read_Only::active(), 'Cloudflare tunnel/proxy requests are not trusted as local readers even when their socket is loopback.' );
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$_SERVER['REQUEST_URI'] = '/shop/';
UltraLove_Catalog_Read_Only::init();
$assert( ! UltraLove_Catalog_Read_Only::active(), 'Unrelated pages unchanged.' );
$_SERVER['REQUEST_URI'] = '/wp-json/ultralove-feed/v1/products';
UltraLove_Catalog_Read_Only::init();
$assert( UltraLove_Catalog_Read_Only::active() && isset( $hooks['query'], $hooks['rest_post_dispatch'] ), 'Explicit loopback catalog mode installs guard before normal plugins/authentication.' );
foreach ( array( 'SELECT ID FROM wp_posts', " \n/* read */ SELECT meta_value FROM wp_postmeta", 'SHOW TABLES', 'DESCRIBE wp_posts' ) as $sql ) {
    $assert( UltraLove_Catalog_Read_Only::query( $sql ) === $sql, 'Ordinary reads use the existing connection.' );
}
foreach ( array( 'INSERT INTO wp_options VALUES (1)', 'UPDATE wp_posts SET post_status=\'draft\'', 'DELETE FROM wp_postmeta',
    'REPLACE INTO wp_options VALUES (1)', 'CREATE USER x', 'ALTER TABLE wp_posts ADD x INT', 'DROP TABLE wp_posts',
    'TRUNCATE wp_posts', 'START TRANSACTION', "SELECT ID INTO OUTFILE '/tmp/catalog' FROM wp_posts" ) as $sql ) {
    $reject( static fn() => UltraLove_Catalog_Read_Only::query( $sql ), 'Database write explicitly rejected before execution.' );
}
for ( $i = 0; $i < 300; ++$i ) {
    $assert( UltraLove_Catalog_Read_Only::allow_request( 1, 300 ), 'Request inside exact Redis quota permitted.' );
}
$assert( ! UltraLove_Catalog_Read_Only::allow_request( 1, 300 ), '301st request rejected without a DB counter write.' );
$assert( UltraLove_Catalog_Read_Only::allow_request( 2, 300 ), 'Separate authenticated account quota.' );
$persistent = false;
$reject( static fn() => UltraLove_Catalog_Read_Only::allow_request( 1, 300 ), 'Unavailable persistent cache fails explicitly, never falls back to DB.' );
$persistent = true;
$record = array( 'id' => 1, 'parent_id' => 0, 'sku' => 'SKU-1', 'type' => 'simple', 'name' => 'Feed product',
    'description' => '<p>From WooCommerce feed</p>', 'currency' => 'CAD', 'price' => '12.34',
    'global_unique_id' => '', 'manage_stock' => true, 'stock_quantity' => 7, 'stock_owner_id' => 1,
    'stock_status' => 'instock', 'images' => array(), 'data_warnings' => array(), 'attributes' => array(), 'variations' => array() );
$pages = 0;
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ),
    static function ( string $url, array $arguments ) use ( $record, &$pages ): array {
        ++$pages;
        parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
        if ( isset( $query['ids'] ) || $query['per_page'] !== '100' ) {
            throw new RuntimeException( 'Whole catalog must not be filtered by selection.' );
        }
        $after = (int) $query['after_id'];
        $items = array();
        for ( $id = $after + 1; $id <= min( $after + 100, 6430 ); ++$id ) {
            $items[] = array_replace( $record, array( 'id' => $id, 'sku' => 'SKU-' . $id, 'stock_owner_id' => $id ) );
        }
        $last = min( $after + 100, 6430 );
        return array( 'status' => 200, 'headers' => array( 'content-type' => 'application/json' ),
            'body' => json_encode( array( 'generation' => '00000000-0000-4000-8000-000000000001', 'change_cursor' => 42,
                'next_after_id' => $last, 'has_more' => $last < 6430, 'products' => $items ) ) );
    } );
$catalog = $client->all_products();
$assert( $pages === 65 && count( $catalog['products'] ) === 6430 && isset( $catalog['products'][6430] ), 'All 6,430 records traversed, no 25/100/2,000-product import cap.' );
$assert( $catalog['cursor'] === 42 && $catalog['products'][6430]['description'] === $record['description'], 'Feed checkpoint and supplier content preserved.' );
foreach ( array( 'price' => '', 'stock_quantity' => null, 'manage_stock' => false, 'stock_owner_id' => 99 ) as $key => $value ) {
    $changed = array_replace( $record, array( $key => $value ) );
    $assert( UltraLove_Shopify_Feed_Client::product( $changed, true ) === $changed, 'Full discovery includes ' . $key . ' without inventing sellable values.' );
    $reject( static fn() => UltraLove_Shopify_Feed_Client::product( $changed ), 'Legacy selected-contract validation retained.' );
}
$variation = array_replace( $record, array( 'type' => 'variation', 'parent_id' => 2 ) );
$assert( UltraLove_Shopify_Feed_Client::product( $variation, true ) === $variation, 'Published variants supported.' );
$bad = array_replace( $record, array( 'price' => '-1.00' ) );
$reject( static fn() => UltraLove_Shopify_Feed_Client::product( $bad, true ), 'Invalid prices rejected explicitly.' );
$bad_page = static function ( array $items, int $next, bool $more = true, string $generation = '00000000-0000-4000-8000-000000000001' ): array {
    return array( 'status' => 200, 'headers' => array( 'content-type' => 'application/json' ),
        'body' => json_encode( array( 'generation' => $generation, 'change_cursor' => 1, 'next_after_id' => $next,
            'has_more' => $more, 'products' => $items ) ) );
};
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ), static fn() => $bad_page( array(), 0 ) );
$reject( static fn() => $client->all_products(), 'Stalled pagination cannot silently truncate catalog.' );
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ), static fn() => $bad_page( array( $record, $record ), 1 ) );
$reject( static fn() => $client->all_products(), 'Duplicate/unordered records rejected.' );
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ), static fn() => $bad_page( array( $variation ), 1, false ) );
$reject( static fn() => $client->all_products(), 'Missing variation parent rejected.' );
$reject( static fn() => UltraLove_Shopify_Feed_Client::catalog_transport( 'https://example.invalid/products', array() ), 'Catalog transport cannot send credentials to a different host.' );
$browse_queries = array();
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ),
    static function ( $url ) use ( $record, $bad_page, &$browse_queries ): array {
        parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
        $browse_queries[] = $query;
        return $bad_page( array( $record ), 1, false );
    } );
$page = $client->products_page( array( 'category_id' => 2, 'search' => 'SKU-1' ) );
$assert( count( $page['products'] ) === 1 && $browse_queries[0]['per_page'] === '50' && $browse_queries[0]['parents_only'] === '1', 'Retailer browsing fetches only 50 parent cards by default, not the whole catalog.' );
$assert( $browse_queries[0]['category_id'] === '2' && $browse_queries[0]['search'] === 'SKU-1', 'Category and name/SKU query filters forwarded.' );
$client->products_page( array( 'per_page' => 100 ) );
$assert( $browse_queries[1]['per_page'] === '100', 'Exact maximum of 100 records supported.' );
$reject( static fn() => $client->products_page( array( 'per_page' => 101 ) ), 'Request above hard page cap rejected before network call.' );
$reject( static fn() => $client->products_page( array( 'per_page' => 0 ) ), 'Zero page size rejected.' );
$reject( static fn() => $client->products_page( array( 'search' => str_repeat( 'x', 101 ) ) ), 'Search length cap enforced.' );
$reject( static fn() => $client->products_page( array( 'category_id' => 0 ) ), 'Category IDs positive.' );
$reject( static fn() => $client->selected_products( range( 1, 51 ) ), '51 selected products rejected before any request.' );
$reject( static fn() => $client->selected_products( array( 1, 1 ) ), 'Duplicate selections rejected.' );
$requests = array();
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ),
    static function ( $url ) use ( $record, $bad_page, &$requests ): array {
        parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
        $requests[] = $query;
        $items = array();
        foreach ( $query['ids'] as $id ) {
            $items[] = array_replace( $record, array( 'id' => (int) $id, 'sku' => 'SKU-' . $id, 'stock_owner_id' => (int) $id ) );
        }
        return $bad_page( $items, (int) end( $query['ids'] ), false );
    } );
$selected = $client->selected_products( range( 1, 50 ) );
$assert( count( $selected['products'] ) === 50 && count( $requests ) === 1 && count( $requests[0]['ids'] ) === 50, 'Exactly 50 explicit selections fetched once, without catalog traversal.' );
$parent = array_replace( $record, array( 'type' => 'variable', 'variations' => range( 2, 151 ) ) );
$requests = array();
$client = new UltraLove_Shopify_Feed_Client( array( 'username' => 'test', 'password' => 'test' ),
    static function ( $url ) use ( $record, $parent, $bad_page, &$requests ): array {
        parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
        $requests[] = $query['ids'];
        $items = array();
        foreach ( $query['ids'] as $id ) {
            $items[] = (int) $id === 1 ? $parent : array_replace( $record,
                array( 'id' => (int) $id, 'parent_id' => 1, 'type' => 'variation', 'stock_owner_id' => (int) $id ) );
        }
        return $bad_page( $items, (int) end( $query['ids'] ), false );
    } );
$selected = $client->selected_products( array( 1 ) );
$assert( count( $selected['products'] ) === 151 && array_map( 'count', $requests ) === array( 1, 100, 50 ), 'All child variants fetched in <=100-record requests, not counted as selected parent cards.' );
echo $checks . " catalog read-only/feed traversal assertions passed.\n";
