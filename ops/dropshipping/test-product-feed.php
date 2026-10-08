<?php
/**
 * Isolated feed policy and inventory-shape tests; no products or network calls.
 */
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
    throw new RuntimeException( 'Run directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'wordpress_dev' );
$environment = 'development';
$home = 'https://dev.ultralove.ca';
$logged_in = false;
$caps = array();
$routes = array();
$checks = 0;
function wp_get_environment_type() { return $GLOBALS['environment']; }
function home_url() { return $GLOBALS['home']; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function register_activation_hook( ...$args ) {}
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $cap, ...$args ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function get_current_user_id() { return 1; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][$route] = $args; }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
class WooCommerce {}
class WP_REST_Server { const READABLE = 'GET'; }
class WP_Error {
    public function __construct( public string $code, public string $message, public array $data ) {}
}
class WC_Product {
    public function __construct( public int $id, public ?int $quantity, public bool $managed, public string $status = 'instock', public int $parent = 0 ) {}
    public function get_parent_id() { return $this->parent; }
    public function get_sku() { return 'EXISTING-' . $this->id; }
    public function get_manage_stock() { return $this->managed; }
    public function get_stock_quantity() { return $this->quantity; }
    public function get_stock_status() { return $this->status; }
    public function get_backorders() { return 'no'; }
    public function get_stock_managed_by_id() { return $this->parent ?: $this->id; }
}
function wc_get_product( $id ) { return $GLOBALS['products'][$id] ?? false; }
$wpdb = new class {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $requests = 0;
    public function prepare( $sql, ...$args ) { return $sql; }
    public function query( $sql ) { ++$this->requests; return 1; }
    public function get_var( $sql ) { return $this->requests; }
};
require __DIR__ . '/../../wp-content/plugins/ultralove-product-feed/ultralove-product-feed.php';
$assert = static function ( bool $pass, string $label ) use ( &$checks ): void {
    if ( ! $pass ) { throw new RuntimeException( $label ); }
    ++$checks;
};
$assert( UltraLove_Product_Feed::permissions()->data['status'] === 401, 'Anonymous feed access fails.' );
$logged_in = true;
$assert( UltraLove_Product_Feed::permissions()->data['status'] === 403, 'Ordinary customer cannot read supplier feed.' );
$caps = array( UltraLove_Product_Feed::CAPABILITY );
$assert( UltraLove_Product_Feed::permissions() === true, 'Explicit catalog capability grants read access.' );
$caps = array( 'manage_woocommerce' );
$assert( UltraLove_Product_Feed::permissions() === true, 'Existing store staff can preview feed.' );
$caps = array( UltraLove_Product_Feed::CAPABILITY );
$wpdb->requests = 0;
for ( $i = 0; $i < UltraLove_Product_Feed::REQUESTS_PER_MINUTE; ++$i ) {
    $assert( UltraLove_Product_Feed::permissions() === true, 'Request within exact quota succeeds.' );
}
$assert( UltraLove_Product_Feed::permissions()->data['status'] === 429, 'First request above quota fails.' );
foreach ( array( 'production', 'staging', 'local' ) as $environment ) {
    $assert( ! UltraLove_Product_Feed::supported() && UltraLove_Product_Feed::permissions()->data['status'] === 503, 'Feed stays isolated from other environments.' );
}
$environment = 'development';
$home = 'https://ultralove.ca';
$assert( ! UltraLove_Product_Feed::supported(), 'Production hostname is refused.' );
$home = 'https://dev.ultralove.ca';
UltraLove_Product_Feed::routes();
$assert( count( $routes ) === 5, 'All five documented read routes registered.' );
foreach ( $routes as $route ) {
    $assert( $route['methods'] === 'GET' && $route['permission_callback'] === array( 'UltraLove_Product_Feed', 'permissions' ), 'Every route is read-only and authenticated.' );
}
$assert( $routes['/products']['args']['per_page']['maximum'] === 100, 'Catalog pagination has exact bound.' );
$assert( $routes['/changes']['args']['generation']['required'], 'Change consumer must identify dataset generation.' );
$posts = array(
    1 => (object) array( 'post_type' => 'product', 'post_status' => 'publish', 'post_parent' => 0 ),
    2 => (object) array( 'post_type' => 'product', 'post_status' => 'draft', 'post_parent' => 0 ),
    3 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 1 ),
    4 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 2 ),
    5 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'private', 'post_parent' => 1 ),
    6 => (object) array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 0 ),
);
foreach ( array( 1 => true, 2 => false, 3 => true, 4 => false, 5 => false, 6 => false, 7 => false ) as $id => $visible ) {
    $assert( UltraLove_Product_Feed::visible( $id ) === $visible, 'Published catalog visibility: ' . $id );
}
$products = array( 1 => new WC_Product( 1, 0, true, 'outofstock' ), 3 => new WC_Product( 3, 12, true, 'instock', 1 ), 8 => new WC_Product( 8, null, false ) );
$assert( UltraLove_Product_Feed::inventory( 1 )['stock_quantity'] === 0 && UltraLove_Product_Feed::visible( 1 ), 'Zero stock does not remove a published listing.' );
$assert( UltraLove_Product_Feed::inventory( 8 )['stock_quantity'] === null, 'Unknown/unmanaged quantity is not zero.' );
$assert( UltraLove_Product_Feed::inventory( 3 )['stock_owner_id'] === 1, 'Shared variation stock identifies parent owner.' );
$products[1]->quantity = -2;
$assert( UltraLove_Product_Feed::inventory( 1 )['stock_quantity'] === -2, 'Backorder quantities are not silently clamped.' );
$rejected = false;
try { UltraLove_Product_Feed::inventory( 99 ); }
catch ( RuntimeException $error ) { $rejected = true; }
$assert( $rejected, 'Missing product is explicitly rejected, not a success-shaped stock default.' );
echo $checks . " product feed assertions passed.\n";
