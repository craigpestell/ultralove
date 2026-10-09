<?php
/**
 * Isolated authenticated-feed/Shopify catalog tests: no network or real credentials.
 */
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
    throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
define( 'WP_CLI', true );
define( 'DB_NAME', 'wordpress_dev' );
$environment = 'development';
$home = 'https://dev.ultralove.ca';
$queue = array();
$requests = array();
$options = array();
$checks = 0;
$mutations = array();
$available = 4;
$remote_exists = false;
$remote = array( 'id' => 'gid://shopify/Product/10', 'handle' => 'ultralove-dev-feed-1',
    'variants' => array( 'nodes' => array( array( 'id' => 'gid://shopify/ProductVariant/11', 'sku' => 'FEED-1',
        'price' => '99.00', 'inventoryItem' => array( 'id' => 'gid://shopify/InventoryItem/12' ) ) ) ) );
function wp_get_environment_type() { return $GLOBALS['environment']; }
function home_url() { return $GLOBALS['home']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][$header] ?? ''; }
function wp_remote_get( $url, $arguments ) {
    $GLOBALS['requests'][] = array( $url, $arguments );
    if ( ! $GLOBALS['queue'] ) { throw new RuntimeException( 'Unexpected feed request.' ); }
    return array_shift( $GLOBALS['queue'] );
}
function wp_json_encode( $data ) { return json_encode( $data ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][$key] = $value; }
function sanitize_title( $value ) { return strtolower( $value ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function wc_get_product( $id ) { return $id === 1 ? $GLOBALS['local'] : false; }
class WP_Error { public function get_error_code() { return 'http_request_failed'; } }
class WP_CLI {
    public static array $warnings = array();
    public static function log( $message ) {}
    public static function warning( $message ) { self::$warnings[] = $message; }
}
class WC_Product {
    public array $meta = array();
    public function get_meta( $key ) { return $this->meta[$key] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
    public function save() {}
    public function __call( $method, $args ) { throw new RuntimeException( 'Dev catalog must not read WooCommerce content: ' . $method ); }
}
$local = new WC_Product();
function wp_remote_post( $url, $arguments ) {
    $call = json_decode( $arguments['body'], true );
    $query = $call['query'];
    $variables = $call['variables'];
    if ( str_contains( $query, 'products(first:' ) ) {
        $data = array( 'products' => array( 'nodes' => $GLOBALS['remote_exists'] ? array( $GLOBALS['remote'] ) : array() ) );
    } elseif ( str_contains( $query, 'productSet(' ) ) {
        $GLOBALS['mutations'][] = $variables['input'];
        $GLOBALS['remote_exists'] = true;
        $data = array( 'productSet' => array( 'product' => $GLOBALS['remote'], 'userErrors' => array() ) );
    } elseif ( str_contains( $query, 'inventorySetQuantities(' ) ) {
        $GLOBALS['mutations'][] = $variables['input'];
        $GLOBALS['available'] = $variables['input']['quantities'][0]['quantity'];
        $data = array( 'inventorySetQuantities' => array( 'userErrors' => array() ) );
    } elseif ( str_contains( $query, 'inventoryItem(id:' ) ) {
        $data = array( 'inventoryItem' => array( 'id' => 'gid://shopify/InventoryItem/12', 'sku' => 'FEED-1',
            'inventoryLevel' => array( 'quantities' => array( array( 'quantity' => $GLOBALS['available'] ) ) ) ) );
    } else {
        throw new RuntimeException( 'Unexpected Shopify operation: ' . $query );
    }
    return array( 'status' => 200, 'headers' => array(), 'body' => json_encode( array( 'data' => $data ) ) );
}
require __DIR__ . '/../../wp-content/plugins/ultralove-shopify-supplier/includes/class-feed-client.php';
require __DIR__ . '/../../wp-content/plugins/ultralove-shopify-supplier/includes/class-test-bridge.php';
$assert = static function ( bool $passed, string $label ) use ( &$checks ): void {
    if ( ! $passed ) { throw new RuntimeException( $label ); }
    ++$checks;
};
$reject = static function ( callable $call, string $label, int $code = 0 ) use ( $assert ): void {
    try { $call(); } catch ( RuntimeException | InvalidArgumentException $error ) {
        $assert( $error->getCode() === $code, $label . ' has explicit failure code.' );
        return;
    }
    throw new RuntimeException( $label . ' was not rejected.' );
};
$response = static fn( $body, $status = 200, $headers = array( 'content-type' => 'application/json' ) ) =>
    array( 'status' => $status, 'headers' => $headers, 'body' => json_encode( $body ) );
$generation = '00000000-0000-4000-8000-000000000001';
$product = array( 'id' => 1, 'sku' => 'FEED-1', 'type' => 'simple', 'name' => 'Feed title', 'description' => '<p>Feed description</p>',
    'currency' => 'CAD', 'price' => '10.00', 'global_unique_id' => '123456789012', 'manage_stock' => true,
    'stock_quantity' => 7, 'stock_status' => 'instock', 'stock_owner_id' => 1,
    'images' => array( array( 'id' => 10, 'src' => 'https://dev.ultralove.ca/wp-content/uploads/image.jpg', 'alt' => 'Feed alt' ) ),
    'data_warnings' => array( array( 'code' => 'missing_image', 'attachment_id' => 99 ) ) );
$snapshot = static fn( $products = null, $cursor = 1 ) => array(
    'generation' => $generation, 'change_cursor' => $cursor, 'next_after_id' => 1, 'has_more' => false,
    'products' => $products ?? array( $product ),
);
$changes = static fn( $rows = array(), $cursor = 1 ) => array(
    'generation' => $generation, 'cursor' => $cursor, 'latest_cursor' => $cursor, 'has_more' => false, 'changes' => $rows,
);
$inventory = static fn( $products = null ) => array(
    'generation' => $generation, 'change_cursor' => 1, 'next_after_id' => 1, 'has_more' => false,
    'inventory' => $products ?? array( array_intersect_key( $product, array_flip( array( 'id', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'stock_owner_id' ) ) ) ),
);
$credentials = array( 'username' => 'fixture', 'password' => 'not-a-real-password' );
$client = new UltraLove_Shopify_Feed_Client( $credentials );
$queue = array( $response( $snapshot() ), $response( $changes() ), $response( $inventory() ) );
$state = $client->read( array( 1 ) );
$assert( $state['products'][1] === $product && $state['cursor'] === 1, 'First snapshot and change replay produce a complete checkpoint.' );
foreach ( $requests as list( $url, $args ) ) {
    $assert( str_starts_with( $url, 'http://localhost/wp-json/ultralove-feed/v1/' ), 'Transport fixed to local REST origin.' );
    $assert( $args['redirection'] === 0 && $args['headers']['Host'] === 'dev.ultralove.ca', 'Redirects disabled and explicit dev vhost.' );
    $assert( $args['headers']['Authorization'] === 'Basic ' . base64_encode( 'fixture:not-a-real-password' ), 'Uses scoped application-password authentication.' );
}
$queue = array( $response( $changes( array( array( 'cursor' => 2, 'id' => 1, 'action' => 'delete', 'product' => null ) ), 2 ) ), $response( $inventory( array() ) ) );
$removed = $client->read( array( 1 ), $state );
$assert( $removed['products'] === array() && $removed['cursor'] === 2, 'Supplier removal preserved, not replaced with local WooCommerce data.' );
$queue = array( $response( array(), 409 ), $response( $snapshot( null, 9 ) ), $response( $changes( array(), 9 ) ), $response( $inventory() ) );
$resynced = $client->read( array( 1 ), $state );
$assert( $resynced['cursor'] === 9 && $resynced['resynced'] === true, '409 rebuilds snapshot and replays changes.' );
$updated = $product;
$updated['name'] = 'Updated feed title';
$queue = array( $response( $changes( array(
    array( 'cursor' => 2, 'id' => 500, 'action' => 'delete', 'product' => null ),
    array( 'cursor' => 3, 'id' => 1, 'action' => 'upsert', 'product' => $updated ),
), 3 ) ), $response( $inventory() ) );
$assert( $client->read( array( 1 ), $state )['products'][1]['name'] === 'Updated feed title', 'Global journal advances across unrelated records and applies selected upserts.' );
$second = $product;
$second['id'] = 2;
$second['sku'] = 'FEED-2';
$second['stock_owner_id'] = 2;
$first_page = $snapshot();
$first_page['has_more'] = true;
$last_page = $snapshot( array( $second ), 99 );
$last_page['next_after_id'] = 2;
$two_inventory = $inventory( array( $product, $second ) );
$two_inventory['next_after_id'] = 2;
$queue = array( $response( $first_page ), $response( $last_page ),
    $response( $changes( array( array( 'cursor' => 2, 'id' => 2, 'action' => 'upsert', 'product' => $second ) ), 2 ) ),
    $response( $two_inventory ) );
$offset = count( $requests );
$paged = $client->read( array( 2, 1 ) );
$assert( count( $paged['products'] ) === 2, 'Snapshot follows stable ID pagination.' );
parse_str( parse_url( $requests[$offset + 2][0], PHP_URL_QUERY ), $change_query );
$assert( $change_query['after'] === '1' && $paged['cursor'] === 2, 'Replay starts at first snapshot cursor, never later page watermark.' );
$invalid_page = $first_page;
$invalid_page['next_after_id'] = 0;
$queue = array( $response( $invalid_page ) );
$reject( fn() => $client->read( array( 1 ) ), 'Nonadvancing snapshot cursor' );
$invalid_inventory = $inventory();
$invalid_inventory['generation'] = '11111111-1111-4111-8111-111111111111';
$queue = array( $response( $changes() ), $response( $invalid_inventory ) );
$reject( fn() => $client->read( array( 1 ), $state ), 'Inventory generation mismatch' );
$invalid_change = $changes( array( array( 'cursor' => 2, 'id' => 1, 'action' => 'upsert', 'product' => $second ) ), 2 );
$queue = array( $response( $invalid_change ) );
$reject( fn() => $client->read( array( 1 ), $state ), 'Upsert identity mismatch' );
foreach ( array( 401, 403, 429, 500 ) as $status ) {
    $queue = array( $response( array(), $status, array( 'retry-after' => '20' ) ) );
    $reject( fn() => $client->read( array( 1 ), $state ), 'HTTP ' . $status, $status );
    $assert( $state['cursor'] === 1, 'Failed read cannot mutate caller checkpoint.' );
}
$queue = array( new WP_Error() );
$reject( fn() => $client->read( array( 1 ), $state ), 'Network failure' );
$queue = array( array( 'status' => 200, 'headers' => array( 'content-type' => 'application/json' ), 'body' => '{' ) );
$reject( fn() => $client->read( array( 1 ), $state ), 'Invalid JSON' );
$queue = array( $response( array(), 200, array( 'content-type' => 'text/html' ) ) );
$reject( fn() => $client->read( array( 1 ), $state ), 'Login HTML' );
$pending = $changes();
$pending['has_more'] = true;
$queue = array( $response( $pending ) );
$reject( fn() => $client->read( array( 1 ), $state ), 'Pending publication does not spin or checkpoint' );
foreach ( array( 'stock_quantity' => null, 'price' => '-1', 'currency' => 'USD', 'type' => 'variable', 'manage_stock' => false, 'stock_owner_id' => 99 ) as $key => $value ) {
    $invalid = $product;
    $invalid[$key] = $value;
    $reject( fn() => UltraLove_Shopify_Feed_Client::product( $invalid ), 'Unsupported DTO ' . $key );
}
$reject( fn() => $client->read( array( -1 ) ), 'Negative supplier ID' );
$reject( fn() => $client->request( '/orders' ), 'Write/order route forbidden' );
$environment = 'production';
$reject( fn() => new UltraLove_Shopify_Feed_Client( $credentials ), 'Production guard' );
$environment = 'development';
$home = 'https://ultralove.ca';
$reject( fn() => new UltraLove_Shopify_Feed_Client( $credentials ), 'Production hostname guard' );
$home = 'https://dev.ultralove.ca';

$reflection = new ReflectionClass( UltraLove_Shopify_Test_Bridge::class );
$bridge = $reflection->newInstanceWithoutConstructor();
foreach ( array( 'source' => 'dev', 'feed' => $client, 'selected_ids' => array( 1 ), 'skus' => array( 'FEED-1' ),
    'product_meta' => '_ul_dev_shopify_product', 'tag' => 'ultralove-dev-supplier', 'markup' => 50.0,
    'token' => 'not-a-real-token', 'location' => 'gid://shopify/Location/1' ) as $key => $value ) {
    $reflection->getProperty( $key )->setValue( $bridge, $value );
}
$queue = array( $response( $snapshot() ), $response( $changes() ), $response( $inventory() ) );
$bridge->sync_catalog();
$input = $mutations[0];
$assert( $input['title'] === $product['name'] && $input['descriptionHtml'] === $product['description'], 'Actual bridge import uses feed content; WooCommerce content getters throw.' );
$assert( $input['status'] === 'DRAFT' && $input['variants'][0]['sku'] === 'FEED-1', 'Draft and SKU guards retained.' );
$assert( $input['variants'][0]['price'] === '15.00' && $input['variants'][0]['barcode'] === '123456789012', 'Initial markup and barcode derive from DTO.' );
$assert( $input['files'][0]['originalSource'] === 'https://ultralove.ca/wp-content/uploads/image.jpg' && $input['files'][0]['alt'] === 'Feed alt', 'Feed media and alt text mapped to public mirror.' );
$assert( $available === 7 && count( WP_CLI::$warnings ) === 1, 'Stock from authenticated feed; media warnings surfaced.' );
$saved = $options['ul_dev_shopify_feed_state'];
$assert( $saved['mappings'][1]['id'] === $remote['id'], 'Mapping checkpoint persists only after successful Shopify writes.' );
$before = count( $mutations );
$queue = array( $response( $changes() ), $response( $inventory() ) );
$bridge->sync_catalog();
$assert( count( $mutations ) === $before, 'Repeat sync creates nothing and leaves retailer content/price alone.' );
$queue = array( $response( $changes( array( array( 'cursor' => 2, 'id' => 1, 'action' => 'delete', 'product' => null ) ), 2 ) ), $response( $inventory( array() ) ) );
$bridge->sync_catalog();
$assert( $available === 0 && $options['ul_dev_shopify_feed_state']['products'] === array(), 'Withdrawal zeroes mapped availability and removes order allowlist.' );
$assert( $options['ul_dev_shopify_feed_state']['mappings'][1]['id'] === $remote['id'], 'Withdrawn retailer product/mapping retained for recovery.' );
$before = $options;
$queue = array( $response( array(), 403 ) );
$reject( fn() => $bridge->sync_catalog(), 'Revocation stops catalog writes', 403 );
$assert( $options === $before, 'Rejected access preserves exact saved checkpoint and last success.' );
$remote_exists = false;
$restored_state = $saved;
$options['ul_dev_shopify_feed_state'] = $restored_state;
$before = $options;
$queue = array( $response( $changes() ), $response( $inventory() ) );
$mutation_count = count( $mutations );
$reject( fn() => $bridge->sync_catalog(), 'Missing mapped Shopify product requires explicit reconciliation' );
$assert( $options === $before && count( $mutations ) === $mutation_count, 'Failed remote reconciliation neither recreates product nor advances checkpoint.' );
echo $checks . " Shopify feed consumer assertions passed.\n";
