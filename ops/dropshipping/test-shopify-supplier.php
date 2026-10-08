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
function add_action( ...$args ) {}
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
echo $checks . " supplier policy assertions passed.\n";
