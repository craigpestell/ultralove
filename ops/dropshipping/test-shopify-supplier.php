<?php
/**
 * Isolated configuration-policy tests; no database, network or product creation.
 */
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
    throw new RuntimeException( 'Run this test directly with PHP CLI.' );
}
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'wordpress_dev' );
$environment = 'development';
$home = 'https://dev.ultralove.ca';
$option = array();
$approved = 'yes';
$dropshipping = true;
$checks = 0;
$actions = array();
$caps = array();
$nonce_valid = true;
$menu = null;
function add_action( $hook, $callback, ...$args ) { $GLOBALS['actions'][$hook] = $callback; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['caps'], true ); }
function add_submenu_page( ...$args ) { $GLOBALS['menu'] = $args; }
function wp_die( $message, $title, $args ) { throw new RuntimeException( $message, $args['response'] ); }
function check_admin_referer( $action ) {
    if ( ! $GLOBALS['nonce_valid'] ) { throw new RuntimeException( 'Invalid nonce.', 403 ); }
}
function wp_unslash( $value ) { return $value; }
function update_option( $key, $value, $autoload ) { $GLOBALS['option'] = $value; }
function admin_url( $path ) { return 'https://dev.ultralove.ca/wp-admin/' . $path; }
function wp_safe_redirect( $url ) { throw new RuntimeException( $url, 302 ); }
function wp_get_environment_type() { return $GLOBALS['environment']; }
function home_url() { return $GLOBALS['home']; }
function get_option( $key, $default = false ) { return $GLOBALS['option']; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function absint( $value ) { return abs( (int) $value ); }
function get_user_by( $field, $id ) { return $id === 1 ? (object) array( 'ID' => 1 ) : false; }
function get_user_meta( ...$args ) { return $GLOBALS['approved']; }
class UltraLove_Dropshipping {
    const APPROVAL = '_ultralove_dropshipping_approved';
    public static function enabled() { return $GLOBALS['dropshipping']; }
}
class WC_Product {
    public bool $managed = true;
    public function is_type( $type ) { return $type === 'simple'; }
    public function get_status() { return 'publish'; }
    public function get_sku() { return 'REAL-EXISTING-SKU'; }
    public function get_manage_stock() { return $this->managed; }
    public function get_stock_quantity() { return 10; }
    public function get_price() { return '17.65'; }
}
$product = new WC_Product();
function wc_get_product( $id ) { return $id === 17186 ? $GLOBALS['product'] : false; }
require __DIR__ . '/../../wp-content/plugins/ultralove-shopify-supplier/ultralove-shopify-supplier.php';
$assert = static function ( bool $passed, string $label ) use ( &$checks ): void {
    if ( ! $passed ) { throw new RuntimeException( $label ); }
    ++$checks;
};
$assert( ! UltraLove_Shopify_Supplier::enabled(), 'Absent settings default off.' );
foreach ( array( true, false, 'yes', 'no', 1, '1' ) as $value ) {
    $option = array( 'enabled' => $value );
    $assert( UltraLove_Shopify_Supplier::enabled() === ( $value === true ), 'Only explicit boolean enablement is accepted.' );
}
$option = array( 'enabled' => true );
foreach ( array( 'production', 'staging', 'local' ) as $environment ) {
    $assert( ! UltraLove_Shopify_Supplier::supported() && ! UltraLove_Shopify_Supplier::enabled(), 'Other environments stay disabled.' );
}
$environment = 'development';
$home = 'https://ultralove.ca';
$assert( ! UltraLove_Shopify_Supplier::enabled(), 'Production hostname cannot enable the test connector.' );
$home = 'https://dev.ultralove.ca';
$dropshipping = false;
$assert( ! UltraLove_Shopify_Supplier::enabled(), 'Manual dropshipping disablement also stops API forwarding.' );
$dropshipping = true;
$valid = array( 'enabled' => true, 'product_ids' => array( 17186 ), 'retailer_id' => 1, 'markup' => 0 );
$assert( UltraLove_Shopify_Supplier::validate( $valid ) === array( 'enabled' => true, 'product_ids' => array( 17186 ), 'retailer_id' => 1, 'markup' => 0.0 ), 'Existing product and approved retailer validate.' );
foreach ( array( 'missing-product', 'negative-id', 'empty-selection', 'unapproved', 'unmanaged', 'negative-markup', 'excess-markup' ) as $case ) {
    $input = $valid;
    $approved = $case === 'unapproved' ? 'no' : 'yes';
    $product->managed = $case !== 'unmanaged';
    if ( $case === 'missing-product' ) { $input['product_ids'] = array( 999 ); }
    if ( $case === 'negative-id' ) { $input['product_ids'] = array( -17186 ); }
    if ( $case === 'empty-selection' ) { $input['product_ids'] = array(); }
    if ( $case === 'negative-markup' ) { $input['markup'] = -1; }
    if ( $case === 'excess-markup' ) { $input['markup'] = 1001; }
    $rejected = false;
    try { UltraLove_Shopify_Supplier::validate( $input ); }
    catch ( InvalidArgumentException $error ) { $rejected = true; }
    $assert( $rejected, 'Invalid configuration rejected: ' . $case );
}
$approved = 'yes';
$product->managed = true;
$GLOBALS['actions']['admin_menu']();
$assert( $menu[3] === 'manage_options', 'Shopify Supplier menu remains administrator-only.' );
$post = array( 'enabled' => '1', 'product_ids' => '17186', 'retailer_id' => '1', 'markup' => '50' );
foreach ( array(
    array( 'caps' => array(), 'nonce' => true, 'environment' => 'development', 'status' => 403, 'label' => 'Customer cannot change the connection.' ),
    array( 'caps' => array( 'manage_woocommerce' ), 'nonce' => true, 'environment' => 'development', 'status' => 403, 'label' => 'Shop-manager feed access does not grant supplier configuration.' ),
    array( 'caps' => array( 'manage_options' ), 'nonce' => false, 'environment' => 'development', 'status' => 403, 'label' => 'Administrator still requires a valid settings nonce.' ),
    array( 'caps' => array( 'manage_options' ), 'nonce' => true, 'environment' => 'production', 'status' => 403, 'label' => 'Administrator configuration remains dev-only.' ),
    array( 'caps' => array( 'manage_options' ), 'nonce' => true, 'environment' => 'development', 'status' => 302, 'label' => 'Administrator can save validated dev configuration.' ),
    array( 'caps' => array( 'manage_options', 'manage_woocommerce' ), 'nonce' => true, 'environment' => 'development', 'status' => 302, 'label' => 'Administrator retains configuration access.' ),
) as $case ) {
    $caps = $case['caps'];
    $nonce_valid = $case['nonce'];
    $environment = $case['environment'];
    $option = array();
    $_POST = $post;
    $status = null;
    try { UltraLove_Shopify_Supplier::save(); }
    catch ( RuntimeException $error ) { $status = $error->getCode(); }
    $assert( $status === $case['status'], $case['label'] );
    $assert( $case['status'] === 302
        ? $option === array( 'enabled' => true, 'product_ids' => array( 17186 ), 'retailer_id' => 1, 'markup' => 50.0 )
        : $option === array(), 'Denied requests cannot mutate supplier settings.' );
}
$environment = 'development';
$caps = array();
$denied = false;
try { UltraLove_Shopify_Supplier::page(); }
catch ( RuntimeException $error ) { $denied = $error->getCode() === 403; }
$assert( $denied, 'Customer cannot render the supplier configuration page.' );
$caps = array( 'manage_woocommerce' );
$denied = false;
try { UltraLove_Shopify_Supplier::page(); }
catch ( RuntimeException $error ) { $denied = $error->getCode() === 403; }
$assert( $denied, 'Shop manager cannot render the administrator configuration page.' );
$environment = 'production';
$menu = null;
$GLOBALS['actions']['admin_menu']();
$assert( $menu === null, 'Supplier menu remains absent on production.' );
echo $checks . " supplier policy assertions passed.\n";
