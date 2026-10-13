<?php
/**
 * Product-only live checks. Restores source stock/status and Shopify content.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! UltraLove_Shopify_Supplier::supported() ) {
    throw new RuntimeException( 'Run only through WP-CLI on the development supplier.' );
}
require_once WP_PLUGIN_DIR . '/ultralove-shopify-supplier/includes/class-test-bridge.php';
$bridge = new UltraLove_Shopify_Test_Bridge( 'dev' );
$settings = UltraLove_Shopify_Supplier::settings();
$id = $settings['product_ids'][0];
$product = wc_get_product( $id );
$stock = $product->get_stock_quantity();
$status = $product->get_status();
$checks = 0;
$assert = static function ( bool $passed, string $label ) use ( &$checks ): void {
    if ( ! $passed ) {
        throw new RuntimeException( 'Failed: ' . $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};
$bridge->sync_catalog();
$state = get_option( 'ul_dev_shopify_feed_state' );
$mapping = $state['mappings'][$id];
$remote_id = $mapping['id'];
$variant_id = $mapping['variants']['nodes'][0]['id'];
$query = static function () use ( $bridge, $remote_id ): array {
    $data = $bridge->graphql( 'query($id:ID!) {product(id:$id) {id title descriptionHtml status handle variants(first:2) {nodes {id sku price inventoryQuantity}}}}', array( 'id' => $remote_id ) );
    return $data['product'];
};
$baseline = $query();
$credential = json_decode( file_get_contents( '/root/.ultralove-shopify-dev/feed-client.json' ), true, 512, JSON_THROW_ON_ERROR );
$user = get_user_by( 'id', $credential['user_id'] );
if ( ! $user || get_user_meta( $user->ID, '_ul_shopify_feed_fixture', true ) !== 'yes' ) {
    throw new RuntimeException( 'Dedicated feed fixture identity is required.' );
}
$client = new UltraLove_Shopify_Feed_Client( $credential );
$revocation_uuid = null;
try {
    $assert( count( $state['products'] ) === count( $settings['product_ids'] ), 'All selected products consumed through authenticated feed.' );
    $assert( $baseline['variants']['nodes'][0]['sku'] === $state['products'][$id]['sku'], 'Feed and Shopify SKU identity match.' );
    $assert( $baseline['variants']['nodes'][0]['inventoryQuantity'] === $stock, 'Initial feed stock matches Shopify.' );
    $assert( ! str_contains( wp_json_encode( $state ), $credential['password'] ), 'Checkpoint contains no credential.' );

    $bridge->graphql( 'mutation($product:ProductUpdateInput!) {productUpdate(product:$product) {userErrors {field message}}}',
        array( 'product' => array( 'id' => $remote_id, 'title' => 'Retailer edit - feed verification', 'descriptionHtml' => '<p>Retailer-owned verification content.</p>' ) ) );
    wc_update_product_stock( $product, max( 0, $stock - 1 ), 'set' );
    $bridge->sync_catalog();
    $edited = $query();
    $assert( $edited['id'] === $remote_id && $edited['variants']['nodes'][0]['id'] === $variant_id, 'Repeated feed sync preserves product/variant mapping.' );
    $assert( $edited['variants']['nodes'][0]['inventoryQuantity'] === max( 0, $stock - 1 ), 'Live supplier stock change reaches Shopify.' );
    $assert( $edited['title'] === 'Retailer edit - feed verification' && str_contains( $edited['descriptionHtml'], 'Retailer-owned verification content.' ), 'Retailer title and description survive stock sync.' );
    $assert( $edited['status'] === $baseline['status'], 'Existing retailer publication state survives stock sync.' );
    $assert( $edited['variants']['nodes'][0]['price'] === $baseline['variants']['nodes'][0]['price'], 'Retailer price survives stock sync.' );

    $product = wc_get_product( $id );
    $product->set_status( 'draft' );
    $product->save();
    $bridge->sync_catalog();
    $withdrawn = $query();
    $assert( $withdrawn['variants']['nodes'][0]['inventoryQuantity'] === 0 && $withdrawn['status'] === $baseline['status'], 'Supplier withdrawal zeroes availability without deleting or changing retailer publication state.' );
    $assert( ! in_array( $state['products'][$id]['sku'], $bridge->skus(), true ), 'Withdrawn SKU is excluded from the forwarding allowlist.' );
    $product->set_status( $status );
    $product->save();
    wc_update_product_stock( $product, $stock, 'set' );
    $bridge->sync_catalog();
    $assert( $query()['variants']['nodes'][0]['inventoryQuantity'] === $stock, 'Republishing restores availability on the same mapping.' );

    $checkpoint = get_option( 'ul_dev_shopify_feed_state' );
    $before_denial = $query();
    $checkpoint['generation'] = '11111111-1111-4111-8111-111111111111';
    update_option( 'ul_dev_shopify_feed_state', $checkpoint, false );
    $bridge->sync_catalog();
    $resynced = get_option( 'ul_dev_shopify_feed_state' );
    $assert( ! empty( $resynced['resynced'] ) && $resynced['generation'] !== $checkpoint['generation'], 'Live 409 forces full snapshot plus change replay.' );
    $assert( $resynced['mappings'][$id]['id'] === $remote_id && $query()['title'] === $edited['title'], 'Full resync preserves mappings and retailer edits.' );

    $checkpoint = get_option( 'ul_dev_shopify_feed_state' );
    $user->remove_cap( UltraLove_Product_Feed::CAPABILITY );
    $denied = false;
    try {
        $bridge->sync_catalog();
    } catch ( RuntimeException $error ) {
        if ( $error->getCode() !== 403 ) { throw $error; }
        $denied = true;
    } finally {
        $user->add_cap( UltraLove_Product_Feed::CAPABILITY );
    }
    $assert( $denied && get_option( 'ul_dev_shopify_feed_state' ) === $checkpoint, 'Revoked scope fails explicitly without advancing checkpoint.' );
    $assert( $query() === $before_denial, 'Denied feed access cannot change Shopify product data.' );
    $temporary = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'Temporary feed revocation verification' ) );
    if ( is_wp_error( $temporary ) ) {
        throw new RuntimeException( 'Could not provision revocation probe: ' . $temporary->get_error_code() );
    }
    $revocation_uuid = $temporary[1]['uuid'];
    $probe = new UltraLove_Shopify_Feed_Client( array( 'username' => $credential['username'], 'password' => $temporary[0] ) );
    $assert( isset( $probe->request( '/products', array( 'ids' => array( $id ) ) )['products'][0] ), 'Separate revocation probe authenticates successfully.' );
    if ( ! WP_Application_Passwords::delete_application_password( $user->ID, $revocation_uuid ) ) {
        throw new RuntimeException( 'Could not revoke temporary application password.' );
    }
    $revocation_uuid = null;
    $denied = false;
    try {
        $probe->request( '/products', array( 'ids' => array( $id ) ) );
    } catch ( RuntimeException $error ) {
        if ( $error->getCode() !== 401 ) { throw $error; }
        $denied = true;
    }
    $assert( $denied, 'A revoked application password fails actual HTTP authentication.' );
} finally {
    $user->add_cap( UltraLove_Product_Feed::CAPABILITY );
    if ( $revocation_uuid !== null && ! WP_Application_Passwords::delete_application_password( $user->ID, $revocation_uuid ) ) {
        throw new RuntimeException( 'Could not clean up temporary application password.' );
    }
    $product = wc_get_product( $id );
    $product->set_status( $status );
    $product->save();
    wc_update_product_stock( $product, $stock, 'set' );
    $bridge->graphql( 'mutation($product:ProductUpdateInput!) {productUpdate(product:$product) {userErrors {field message}}}',
        array( 'product' => array( 'id' => $remote_id, 'title' => $baseline['title'], 'descriptionHtml' => $baseline['descriptionHtml'] ) ) );
    $bridge->sync_catalog();
    $restored = $query();
    $assert( $restored['title'] === $baseline['title'] && $restored['descriptionHtml'] === $baseline['descriptionHtml']
        && $restored['variants']['nodes'][0]['inventoryQuantity'] === $stock
        && $restored['variants']['nodes'][0]['price'] === $baseline['variants']['nodes'][0]['price'], 'Source status/stock, retailer content, price and dedicated credential restored.' );
}
WP_CLI::success( $checks . ' live product-only Shopify feed assertions passed.' );
