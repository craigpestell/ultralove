<?php
/**
 * Product-only fresh import. Retains the new draft but restores dev selection.
 * Pause /etc/cron.d/ultralove-shopify-dev before wp eval-file - <product-id>.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'UltraLove_Shopify_Supplier' )
    || ! UltraLove_Shopify_Supplier::supported() || ! UltraLove_Shopify_Supplier::enabled() ) {
    throw new RuntimeException( 'Run only through WP-CLI on the enabled development supplier.' );
}
if ( file_exists( '/etc/cron.d/ultralove-shopify-dev' ) ) {
    throw new RuntimeException( 'Pause the scheduled Shopify worker before temporarily changing selection.' );
}
if ( count( $args ) !== 1 || ! ctype_digit( $args[0] ) || (int) $args[0] < 1 ) {
    throw new InvalidArgumentException( 'Provide one existing, unmapped supplier product ID.' );
}
$id = (int) $args[0];
$settings = UltraLove_Shopify_Supplier::settings();
$state = get_option( 'ul_dev_shopify_feed_state', array() );
$product = wc_get_product( $id );
if ( ! UltraLove_Shopify_Supplier::eligible( $product )
    || $product->get_meta( '_ul_dev_shopify_product' ) || isset( $state['mappings'][$id] )
    || in_array( $id, $settings['product_ids'], true ) ) {
    throw new RuntimeException( 'Fresh verification requires an eligible, unselected and unmapped supplier product.' );
}
require_once WP_PLUGIN_DIR . '/ultralove-shopify-supplier/includes/class-test-bridge.php';
$bridge = new UltraLove_Shopify_Test_Bridge( 'dev' );
$checks = 0;
$assert = static function ( bool $passed, string $label ) use ( &$checks ): void {
    if ( ! $passed ) {
        throw new RuntimeException( 'Failed: ' . $label );
    }
    ++$checks;
    WP_CLI::log( 'PASS: ' . $label );
};
$query = static function ( string $remote_id ) use ( &$bridge ): array {
    $data = $bridge->graphql( 'query($id:ID!) {product(id:$id) {id title descriptionHtml handle status publishedAt vendor tags options {name values} variants(first:2) {nodes {id sku barcode price inventoryQuantity inventoryItem {id tracked inventoryLevels(first:10) {nodes {location {id name} quantities(names:["available"]) {name quantity}}}}}} media(first:100) {pageInfo {hasNextPage} nodes {id alt status mediaContentType ... on MediaImage {image {url width height}}}}}}',
        array( 'id' => $remote_id ) );
    if ( ! $data['product'] ) {
        throw new RuntimeException( 'Mapped Shopify test product is missing.' );
    }
    return $data['product'];
};
$handle = sanitize_title( 'ultralove-dev-' . $product->get_sku() );
$matches = $bridge->graphql( 'query($query:String!) {products(first:10,query:$query) {nodes {id handle}}}',
    array( 'query' => 'sku:' . $product->get_sku() . ' OR handle:' . $handle ) );
$assert( $matches['products']['nodes'] === array(), 'No existing Shopify SKU or handle; creation is genuinely fresh.' );
$assert( apply_filters( 'pre_wp_mail', null, array(
    'to' => 'verification@example.invalid', 'subject' => 'Draft import guard check',
    'message' => 'Not sent', 'headers' => array(), 'attachments' => array(),
) ) === true, 'Development email suppression remains active.' );
$gateway_baseline = array_map( static fn( $gateway ) => $gateway->enabled, WC()->payment_gateways()->payment_gateways() );
$source_baseline = array( $product->get_status(), $product->get_stock_quantity(), $product->get_price() );
$existing = array();
foreach ( $settings['product_ids'] as $selected_id ) {
    if ( ! isset( $state['mappings'][$selected_id]['id'] ) ) {
        throw new RuntimeException( 'Baseline selected products must already be mapped.' );
    }
    $existing[$selected_id] = $query( $state['mappings'][$selected_id]['id'] );
}
$baseline = null;
$remote_id = null;
$temporary = $settings;
$temporary['product_ids'][] = $id;
$temporary['markup'] = 50.0;
$temporary = UltraLove_Shopify_Supplier::validate( $temporary );
try {
    $bridge = null;
    update_option( UltraLove_Shopify_Supplier::OPTION, $temporary, false );
    if ( UltraLove_Shopify_Supplier::settings() !== $temporary ) {
        throw new RuntimeException( 'Could not persist temporary dev selection.' );
    }
    $bridge = new UltraLove_Shopify_Test_Bridge( 'dev' );
    $bridge->sync_catalog();
    $imported = get_option( 'ul_dev_shopify_feed_state' );
    $record = $imported['products'][$id];
    $remote_id = $imported['mappings'][$id]['id'];
    $baseline = $query( $remote_id );
    $variant = $baseline['variants']['nodes'][0];
    $assert( $baseline['status'] === 'DRAFT' && $baseline['publishedAt'] === null, 'New product is an unpublished Shopify draft.' );
    $assert( $baseline['handle'] === $handle && $baseline['vendor'] === 'UltraLove'
        && in_array( 'ultralove-dev-supplier', $baseline['tags'], true ), 'Dev handle, supplier vendor and test tag are correct.' );
    $text = static fn( string $html ): string => trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    $assert( $baseline['title'] === $record['name'] && $text( $baseline['descriptionHtml'] ) === $text( $record['description'] ), 'Feed title and description transfer correctly.' );
    $assert( count( $baseline['variants']['nodes'] ) === 1 && $variant['sku'] === $record['sku']
        && $baseline['options'] === array( array( 'name' => 'Title', 'values' => array( 'Default Title' ) ) ), 'Simple-product SKU and default variant are correct.' );
    $assert( ( $variant['barcode'] ?? '' ) === $record['global_unique_id'], 'Barcode matches the feed, including an explicitly absent barcode.' );
    $assert( $record['currency'] === 'CAD' && $variant['price'] === number_format( (float) $record['price'] * 1.5, 2, '.', '' ), 'Initial CAD selling price applies the temporary 50 percent markup.' );
    $levels = array_values( array_filter( $variant['inventoryItem']['inventoryLevels']['nodes'],
        static fn( array $level ): bool => $level['location']['name'] === 'Shop location' ) );
    $assert( $variant['inventoryItem']['tracked'] && count( $levels ) === 1
        && $levels[0]['quantities'][0]['quantity'] === $record['stock_quantity'], 'Tracked stock matches the feed at the designated Shopify location.' );
    $assert( $record['images'] !== array() && $record['data_warnings'] === array(), 'Selected feed product has media and no missing-image warnings.' );
    $deadline = time() + 180;
    do {
        $media = $query( $remote_id )['media'];
        if ( array_filter( $media['nodes'], static fn( array $item ): bool => $item['status'] === 'FAILED' ) ) {
            throw new RuntimeException( 'Shopify image processing failed.' );
        }
        $ready = count( $media['nodes'] ) === count( $record['images'] ) && ! $media['pageInfo']['hasNextPage']
            && ! array_filter( $media['nodes'], static fn( array $item ): bool =>
                $item['status'] !== 'READY' || $item['mediaContentType'] !== 'IMAGE'
                || empty( $item['image']['url'] ) || $item['image']['width'] < 1 || $item['image']['height'] < 1 );
        if ( ! $ready ) {
            sleep( 5 );
        }
    } while ( ! $ready && time() < $deadline );
    $assert( $ready, 'Every feed image is READY in Shopify with a URL and nonzero dimensions.' );
    foreach ( $media['nodes'] as $index => $image ) {
        $assert( $image['alt'] === ( $record['images'][$index]['alt'] ?: $record['name'] ), 'Imported image alt text matches the feed.' );
    }
    $media_ids = array_column( $media['nodes'], 'id' );
    $bridge->sync_catalog();
    $repeated = $query( $remote_id );
    $assert( $repeated['variants']['nodes'][0]['id'] === $variant['id']
        && get_option( 'ul_dev_shopify_feed_state' )['mappings'][$id]['id'] === $remote_id
        && array_column( $repeated['media']['nodes'], 'id' ) === $media_ids, 'Repeated import preserves product, variant and media mappings.' );
    $matches = $bridge->graphql( 'query($query:String!) {products(first:10,query:$query) {nodes {id handle}}}',
        array( 'query' => 'sku:' . $record['sku'] . ' OR handle:' . $handle ) );
    $assert( count( $matches['products']['nodes'] ) === 1 && $matches['products']['nodes'][0]['id'] === $remote_id, 'Repeated import creates no duplicate Shopify product.' );
    $retail_price = number_format( (float) $variant['price'] + 2, 2, '.', '' );
    $bridge->graphql( 'mutation($product:ProductUpdateInput!) {productUpdate(product:$product) {userErrors {field message}}}',
        array( 'product' => array( 'id' => $remote_id, 'title' => 'Retailer edit - fresh draft verification', 'descriptionHtml' => '<p>Retailer-owned draft verification content.</p>' ) ) );
    $bridge->graphql( 'mutation($product:ID!,$variants:[ProductVariantsBulkInput!]!) {productVariantsBulkUpdate(productId:$product,variants:$variants) {userErrors {field message}}}',
        array( 'product' => $remote_id, 'variants' => array( array( 'id' => $variant['id'], 'price' => $retail_price ) ) ) );
    $bridge->sync_catalog();
    $edited = $query( $remote_id );
    $assert( $edited['title'] === 'Retailer edit - fresh draft verification'
        && str_contains( $edited['descriptionHtml'], 'Retailer-owned draft verification content.' )
        && $edited['variants']['nodes'][0]['price'] === $retail_price, 'Subsequent sync preserves retailer-edited title, description and selling price.' );
    $assert( $edited['status'] === 'DRAFT' && $edited['publishedAt'] === null
        && array_column( $edited['media']['nodes'], 'id' ) === $media_ids, 'Retailer-edit sync leaves the draft unpublished and media intact.' );
} finally {
    try {
        if ( $bridge !== null && $baseline !== null ) {
            $bridge->graphql( 'mutation($product:ProductUpdateInput!) {productUpdate(product:$product) {userErrors {field message}}}',
                array( 'product' => array( 'id' => $remote_id, 'title' => $baseline['title'], 'descriptionHtml' => $baseline['descriptionHtml'] ) ) );
            $bridge->graphql( 'mutation($product:ID!,$variants:[ProductVariantsBulkInput!]!) {productVariantsBulkUpdate(productId:$product,variants:$variants) {userErrors {field message}}}',
                array( 'product' => $remote_id, 'variants' => array( array( 'id' => $baseline['variants']['nodes'][0]['id'], 'price' => $baseline['variants']['nodes'][0]['price'] ) ) ) );
        }
    } finally {
        $bridge = null;
        update_option( UltraLove_Shopify_Supplier::OPTION, $settings, false );
        if ( UltraLove_Shopify_Supplier::settings() !== $settings ) {
            throw new RuntimeException( 'Could not restore original dev supplier settings.' );
        }
    }
    $bridge = new UltraLove_Shopify_Test_Bridge( 'dev' );
    $bridge->sync_catalog();
    $assert( ! in_array( $product->get_sku(), $bridge->skus(), true ), 'Original selection and markup restored; new draft excluded from forwarding and ongoing sync.' );
    $source = wc_get_product( $id );
    $assert( array( $source->get_status(), $source->get_stock_quantity(), $source->get_price() ) === $source_baseline, 'Supplier status, stock and price were not changed.' );
    foreach ( $existing as $selected_id => $before ) {
        $assert( $query( $before['id'] ) === $before, 'Existing selected Shopify product ' . $selected_id . ' remains unchanged.' );
    }
    $assert( array_map( static fn( $gateway ) => $gateway->enabled, WC()->payment_gateways()->payment_gateways() ) === $gateway_baseline, 'Payment configuration remains unchanged.' );
    if ( $baseline !== null ) {
        $final = $query( $remote_id );
        $assert( $final['status'] === 'DRAFT' && $final['publishedAt'] === null
            && $final['title'] === $baseline['title'] && $final['descriptionHtml'] === $baseline['descriptionHtml']
            && $final['variants']['nodes'][0]['price'] === $baseline['variants']['nodes'][0]['price']
            && $final['variants']['nodes'][0]['inventoryQuantity'] === $baseline['variants']['nodes'][0]['inventoryQuantity'],
            'New draft retained for review with original imported content, price and stock restored.' );
        WP_CLI::log( 'Draft review: ' . $remote_id . '; supplier ' . $id . '; SKU ' . $product->get_sku() . '; price CAD ' . $final['variants']['nodes'][0]['price'] );
    }
}
WP_CLI::success( $checks . ' fresh draft product-only assertions passed.' );
