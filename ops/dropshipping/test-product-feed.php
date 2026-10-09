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
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wp_kses_post( $text ) { return $text; }
function wp_get_attachment_url( $id ) { return $id === 101 ? 'https://example.invalid/existing-image.jpg' : false; }
function get_post_meta( $id, $key, $single ) { return $key === 'hwp_product_gtin' ? '00012345678905' : ''; }
function taxonomy_exists( $taxonomy ) { return false; }
function get_woocommerce_currency() { return 'CAD'; }
function wc_prices_include_tax() { return false; }
function wc_get_logger() { return new class { public function warning( ...$args ) {} }; }
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
    public function get_id() { return $this->id; }
    public function get_image_id() { return $this->parent ? 0 : 101; }
    public function get_gallery_image_ids() { return array( 102 ); }
    public function get_attributes() { return $this->parent ? array( 'colour' => 'black' ) : array(); }
    public function get_date_modified() { return new DateTimeImmutable( '2026-10-08T00:00:00Z' ); }
    public function get_name() { return 'Existing<br>catalog product'; }
    public function get_type() { return $this->parent ? 'variation' : 'simple'; }
    public function get_permalink() { return 'https://example.invalid/product/' . $this->id; }
    public function get_description() { return '<p>Existing description</p>'; }
    public function get_short_description() { return 'Existing short description'; }
    public function get_price( $context ) { return '17.65'; }
    public function get_regular_price( $context ) { return '18.00'; }
    public function get_sale_price( $context ) { return '17.65'; }
    public function get_global_unique_id() { return ''; }
    public function is_type( $type ) { return $this->get_type() === $type; }
}
function wc_get_product( $id ) { return $GLOBALS['products'][$id] ?? false; }
$wpdb = new class {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $last_error = '';
    public int $requests = 0;
    public array $prepared = array();
    public string $last_query = '';
    public function prepare( $sql, ...$args ) { $this->prepared[] = array( $sql, $args ); return $sql; }
    public function query( $sql ) { ++$this->requests; return 1; }
    public function get_var( $sql ) { return $this->requests; }
    public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
    public function get_col( $sql ) { $this->last_query = $sql; return array( '1', '3' ); }
    public function get_results( $sql ) {
        return array( (object) array( 'term_id' => 1, 'parent' => 0 ), (object) array( 'term_id' => 2, 'parent' => 1 ),
            (object) array( 'term_id' => 3, 'parent' => 2 ) );
    }
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
$assert( count( $routes ) === 6, 'All six documented read routes registered.' );
foreach ( $routes as $route ) {
    $assert( $route['methods'] === 'GET' && $route['permission_callback'] === array( 'UltraLove_Product_Feed', 'permissions' ), 'Every route is read-only and authenticated.' );
}
$assert( $routes['/products']['args']['per_page']['maximum'] === 100, 'Catalog pagination has exact bound.' );
$assert( $routes['/categories']['args']['per_page']['default'] === 50 && $routes['/categories']['args']['per_page']['maximum'] === 100, 'Category browsing defaults to 50 and has the same hard request cap.' );
$assert( $routes['/products']['args']['category_id']['minimum'] === 1 && $routes['/products']['args']['search']['maxLength'] === 100, 'Category/search filters have exact validation bounds.' );
$assert( $routes['/products']['args']['parents_only']['default'] === false, 'Existing full-feed variation visibility stays unchanged.' );
$assert( $routes['/changes']['args']['generation']['required'], 'Change consumer must identify dataset generation.' );
$posts = array(
    1 => (object) array( 'post_type' => 'product', 'post_status' => 'publish', 'post_parent' => 0 ),
    2 => (object) array( 'post_type' => 'product', 'post_status' => 'draft', 'post_parent' => 0 ),
    3 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 1 ),
    4 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 2 ),
    5 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'private', 'post_parent' => 1 ),
    6 => (object) array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 0 ),
    9 => (object) array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 99 ),
);
foreach ( array( 1 => true, 2 => false, 3 => true, 4 => false, 5 => false, 6 => false, 7 => false, 9 => false ) as $id => $visible ) {
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
$simple = UltraLove_Product_Feed::product( 1 );
$variation = UltraLove_Product_Feed::product( 3 );
$assert( $simple['name'] === 'Existing catalog product' && $simple['short_description'] === 'Existing short description', 'Standard product text is normalized without copying legacy CSV Excerpt bug.' );
$assert( $simple['global_unique_id'] === '00012345678905' && $simple['price'] === '17.65', 'Identifiers retain leading zeros and monetary values remain strings.' );
$assert( $simple['data_warnings'] === array( array( 'code' => 'missing_image', 'attachment_id' => 102 ) ) && count( $simple['images'] ) === 1, 'Missing attachment explicitly warned while valid image and product remain available.' );
$assert( $variation['type'] === 'variation' && $variation['parent_id'] === 1 && $variation['images'] === $simple['images'], 'Variation representation inherits parent images without a live synthetic product.' );
$assert( $variation['attributes'] === array( array( 'name' => 'colour', 'options' => array( 'black' ) ) ), 'Variation selected options have documented shape.' );
$assert( UltraLove_Product_Feed::catalog_ids( 0, 51, array(), array( 'parents_only' => true, 'category_id' => 1, 'search' => '50%_SKU' ) ) === array( 1, 3 ), 'Filtered catalog retains integer IDs.' );
$assert( str_contains( $wpdb->last_query, "p.post_type='product'" ) && str_contains( $wpdb->last_query, 'ct.term_id IN (1,2,3)' ), 'Parent browsing and descendant-category filters are applied together.' );
$assert( str_contains( $wpdb->last_query, "CASE WHEN p.post_type='product_variation' THEN parent.ID ELSE p.ID END" ), 'Variations inherit source-parent categorization.' );
$assert( str_contains( $wpdb->last_query, "child.post_status='publish'" ) && str_contains( $wpdb->last_query, "cm.meta_key='_sku'" ), 'Published variation SKU searches find the parent card, not private variants.' );
$search_call = $wpdb->prepared[count( $wpdb->prepared ) - 2];
$assert( $search_call[1] === array_fill( 0, 4, '%50\\%\\_SKU%' ), 'Name/SKU filters use prepared, literal wildcard-safe search values.' );
$before = $wpdb->requests;
UltraLove_Product_Feed::catalog_ids( 0, 51 );
$assert( ! str_contains( $wpdb->last_query, 'ct.term_id' ) && ! str_contains( $wpdb->last_query, "p.post_type='product' AND" ) && $wpdb->requests === $before, 'Unfiltered callers retain all-type visibility without write queries.' );
echo $checks . " product feed assertions passed.\n";
if ( in_array( '--samples', $argv, true ) ) {
    echo 'FEED-SAMPLES:' . json_encode( array( $simple, $variation ), JSON_THROW_ON_ERROR ) . "\n";
}
