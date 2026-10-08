<?php
/**
 * Live synthetic integration tests. Own test orders are removed; catalog stays.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}
$GLOBALS['ul_lab_shopify_library_only'] = true;
$dev = home_url() === 'https://dev.ultralove.ca';
if ( $dev ) {
    require_once WP_PLUGIN_DIR . '/ultralove-shopify-supplier/includes/class-test-bridge.php';
} else {
    require __DIR__ . '/shopify-bridge.php';
}
$bridge = new UltraLove_Shopify_Test_Bridge( $dev ? 'dev' : 'lab' );
$sku = $bridge->skus()[0];
$product_meta = $bridge->product_meta();
$order_meta = $bridge->order_meta();
$previous_user = get_current_user_id();
$gateway_baseline = array_map( static fn( $gateway ) => $gateway->enabled, WC()->payment_gateways()->payment_gateways() );
$assert = static function ( bool $passed, string $label ): void {
    if ( ! $passed ) {
        throw new RuntimeException( 'Failed: ' . $label );
    }
    WP_CLI::log( 'PASS: ' . $label );
};
$order_ids = array();
$supplier = null;
$initial_stock = $bridge->product( $sku )->get_stock_quantity();
$query_order = static function ( string $id ) use ( $bridge ): array {
    $data = $bridge->graphql( 'query($id:ID!) {order(id:$id) {id name tags test cancelledAt displayFinancialStatus shippingAddress {firstName lastName address1 address2 city provinceCode zip countryCodeV2} lineItems(first:100) {pageInfo {hasNextPage} nodes {id quantity unfulfilledQuantity variant {id}}} fulfillments {id trackingInfo {number}}}}', array( 'id' => $id ) );
    if ( ! $data['order'] ) {
        throw new RuntimeException( 'Synthetic Shopify test order disappeared.' );
    }
    return $data['order'];
};

try {
    if ( ! $dev && ! get_user_by( 'login', $bridge->retailer_login() ) ) {
        $id = wp_insert_user( array(
            'user_login' => 'shopify-lab-retailer', 'user_pass' => wp_generate_password( 40, true, true ),
            'user_email' => 'retailer@example.invalid', 'role' => 'customer',
        ) );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( 'Unable to create synthetic retailer: ' . $id->get_error_code() );
        }
    }
    $retailer = get_user_by( 'login', $bridge->retailer_login() );
    if ( ! $retailer ) {
        throw new RuntimeException( 'Configured approved test retailer is missing.' );
    }
    wp_set_current_user( $retailer->ID );
    $bridge->sync_catalog();
    $mapping = $bridge->product( $sku )->get_meta( $product_meta );
    $variant = $mapping['variants']['nodes'][0];
    $retail_price = number_format( (float) $bridge->product( $sku )->get_price() * ( $dev ? 1 : 1.5 ) + 2, 2, '.', '' );
    $bridge->graphql( 'mutation($product:ID!,$variants:[ProductVariantsBulkInput!]!) {productVariantsBulkUpdate(productId:$product,variants:$variants) {userErrors {field message}}}',
        array( 'product' => $mapping['id'], 'variants' => array( array( 'id' => $variant['id'], 'price' => $retail_price ) ) ) );
    wc_update_product_stock( $bridge->product( $sku ), $initial_stock - 1, 'set' );
    $bridge->sync_catalog();
    $updated = $bridge->product( $sku )->get_meta( $product_meta );
    $assert( $updated['id'] === $mapping['id'], 'catalog reimport updates the same Shopify product' );
    $assert( $updated['variants']['nodes'][0]['price'] === $retail_price, 'stock sync preserves retailer-edited markup' );
    $stock = $bridge->graphql( 'query($id:ID!) {productVariant(id:$id) {inventoryQuantity}}', array( 'id' => $variant['id'] ) );
    $assert( $stock['productVariant']['inventoryQuantity'] === $initial_stock - 1, 'actual Shopify stock matches the WooCommerce quantity change' );
    wc_update_product_stock( $bridge->product( $sku ), $initial_stock, 'set' );
    $bridge->sync_catalog();

    if ( $dev ) {
        $other_product = $bridge->product( $bridge->skus()[1] );
        $other_line = array( 'title' => wp_strip_all_tags( $other_product->get_name() ), 'sku' => $other_product->get_sku(), 'quantity' => 1,
            'priceSet' => array( 'shopMoney' => array( 'amount' => $other_product->get_price(), 'currencyCode' => 'CAD' ) ) );
    } else {
        $unrelated = $bridge->graphql( '{products(first:1,query:"handle:the-minimal-snowboard") {nodes {variants(first:1) {nodes {id}}}}}' );
        if ( ! $unrelated['products']['nodes'] ) {
            throw new RuntimeException( 'Shopify development-store minimal snowboard fixture is missing.' );
        }
        $other_line = array( 'variantId' => $unrelated['products']['nodes'][0]['variants']['nodes'][0]['id'], 'quantity' => 1 );
    }
    $create = static function ( array $lines, string $status ) use ( $bridge, &$order_ids ): string {
        $data = $bridge->graphql( 'mutation($order:OrderCreateOrderInput!) {orderCreate(order:$order) {order {id test} userErrors {field message}}}', array( 'order' => array(
            'test' => true, 'currency' => 'CAD', 'financialStatus' => $status,
            'tags' => array( $bridge->tag() ),
            'email' => 'recipient@example.invalid', 'buyerAcceptsMarketing' => false,
            'note' => 'Synthetic integration verification. No payment, shipping, or customer notification.',
            'shippingAddress' => array(
                'firstName' => 'Synthetic', 'lastName' => 'Recipient',
                'address1' => '123 Synthetic Street', 'city' => 'Vancouver',
                'provinceCode' => 'BC', 'zip' => 'V6B 1A1', 'countryCode' => 'CA',
            ),
            'lineItems' => $lines,
        ) ) );
        $remote = $data['orderCreate']['order'];
        if ( ! $remote || ! $remote['test'] ) {
            throw new RuntimeException( 'Shopify did not create an explicitly test-only order.' );
        }
        $order_ids[] = $remote['id'];
        return $remote['id'];
    };
    $mixed_id = $create( array( array( 'variantId' => $variant['id'], 'quantity' => 1 ), $other_line ), 'PAID' );
    $other_id = $create( array( $other_line ), 'PAID' );
    $unpaid_id = $create( array( array( 'variantId' => $variant['id'], 'quantity' => 1 ) ), 'PENDING' );
    $remote = $query_order( $mixed_id );
    $supplier = $bridge->import_order( $remote );
    $assert( $supplier instanceof WC_Order && count( $supplier->get_items() ) === 1, 'mixed Shopify order forwards only supplier-owned line' );
    $assert( $supplier->get_customer_id() === $retailer->ID, 'supplier order belongs to configured retailer account' );
    $assert( $supplier->get_billing_email() === 'retailer@example.invalid', 'retailer billing email is separate from recipient' );
    $assert( $supplier->get_shipping_address_1() === '123 Synthetic Street', 'synthetic recipient delivery address forwarded' );
    $assert( (float) $supplier->get_total() === (float) $bridge->product( $sku )->get_price(), 'supplier order uses wholesale price, not retail markup' );
    $assert( $supplier->get_status() === 'on-hold' && ! $supplier->get_date_paid(), 'retailer test payment does not mark supplier order paid' );
    $assert( $bridge->product( $sku )->get_stock_quantity() === $initial_stock - 1, 'supplier order reserves stock exactly once' );
    $again = $bridge->import_order( $remote );
    $assert( $again->get_id() === $supplier->get_id() && $bridge->product( $sku )->get_stock_quantity() === $initial_stock - 1, 'duplicate order retry does not duplicate order or stock reduction' );
    $assert( $bridge->import_order( $query_order( $other_id ) ) === null, 'retailer-only order is not forwarded' );
    $assert( $bridge->import_order( $query_order( $unpaid_id ) ) === null, 'unpaid retailer order is not forwarded' );
    $not_test = $remote;
    $not_test['test'] = false;
    $assert( $bridge->import_order( $not_test ) === null, 'non-test order is rejected before mutation' );
    foreach ( array( 'stock', 'address' ) as $case ) {
        $invalid = $remote;
        $invalid['id'] = 'gid://shopify/Order/0';
        if ( $case === 'stock' ) {
            $invalid['lineItems']['nodes'][0]['quantity'] = $initial_stock + 100;
        } else {
            $invalid['shippingAddress']['address1'] = 'Not the synthetic address';
        }
        $rejected = false;
        try {
            $bridge->import_order( $invalid );
        } catch ( RuntimeException $error ) {
            $expected = $case === 'stock' ? 'exceeds synthetic stock' : 'synthetic delivery address';
            $rejected = str_contains( $error->getMessage(), $expected );
            if ( ! $rejected ) {
                throw $error;
            }
        }
        $assert( $rejected, 'invalid ' . $case . ' is rejected before supplier order creation' );
    }
    $bridge->import_orders();
    $matches = wc_get_orders( array( 'limit' => -1, 'meta_key' => $order_meta, 'meta_value' => $mixed_id ) );
    $assert( count( $matches ) === 1, 'actual paginated polling remains idempotent' );
    $bridge->sync_catalog();
    $stock = $bridge->graphql( 'query($id:ID!) {productVariant(id:$id) {inventoryQuantity}}', array( 'id' => $variant['id'] ) );
    $assert( $stock['productVariant']['inventoryQuantity'] === $initial_stock - 1, 'imported order stock reduction synchronizes back to Shopify' );
    if ( $dev ) {
        $administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
        if ( ! $administrators ) {
            throw new RuntimeException( 'A dev administrator is required for admin-surface verification.' );
        }
        $saved_post = $_POST;
        wp_set_current_user( $administrators[0]->ID );
        try {
            ob_start();
            UltraLove_Shopify_Supplier::page();
            $html = ob_get_clean();
            $assert( str_contains( $html, $sku ) && str_contains( $html, 'ultralove-integration-lab.myshopify.com' ), 'WordPress supplier controls render existing catalog and test-store identity' );
            ob_start();
            UltraLove_Shopify_Supplier::tracking_field( $supplier );
            $html = ob_get_clean();
            $assert( str_contains( $html, 'ul_shopify_test_tracking' ), 'WooCommerce supplier order exposes staff tracking control' );
            $_POST = array(
                'ul_shopify_tracking_nonce' => wp_create_nonce( 'ul_shopify_tracking_' . $supplier->get_id() ),
                'ul_shopify_test_tracking' => 'LAB-TRACK-001',
            );
            UltraLove_Shopify_Supplier::save_tracking( $supplier->get_id() );
            $supplier = wc_get_order( $supplier->get_id() );
            $assert( $supplier->get_meta( '_ul_lab_tracking_number' ) === 'LAB-TRACK-001', 'nonce-protected admin tracking save persists the worker field' );
        } finally {
            $_POST = $saved_post;
            wp_set_current_user( $retailer->ID );
        }
    } else {
        $supplier->update_meta_data( '_ul_lab_tracking_number', 'LAB-TRACK-001' );
        $supplier->save();
    }
    $supplier->update_status( 'completed', 'Synthetic fulfillment only; no parcel or supplier payment.' );
    for ( $attempt = 0; $attempt < 6; ++$attempt ) {
        $bridge->return_tracking();
        $supplier = wc_get_order( $supplier->get_id() );
        if ( $supplier->get_meta( '_ul_lab_fulfillment_id' ) ) {
            break;
        }
        sleep( 5 );
    }
    $finished = $query_order( $mixed_id );
    $fulfilled = array_filter( $finished['fulfillments'], static fn( $item ) => in_array( 'LAB-TRACK-001', array_column( $item['trackingInfo'], 'number' ), true ) );
    $assert( count( $fulfilled ) === 1, 'WooCommerce synthetic tracking returns to Shopify' );
    foreach ( $finished['lineItems']['nodes'] as $line ) {
        $expected = ( $line['variant']['id'] ?? null ) === $variant['id'] ? 0 : 1;
        $assert( $line['unfulfilledQuantity'] === $expected, 'mixed-order fulfillment leaves unrelated retailer line unfulfilled' );
    }
    $bridge->return_tracking();
    $assert( count( $query_order( $mixed_id )['fulfillments'] ) === 1, 'tracking retry creates no duplicate fulfillment' );
    $fulfillment_id = $supplier->get_meta( '_ul_lab_fulfillment_id' );
    $supplier->delete_meta_data( '_ul_lab_fulfillment_id' );
    $supplier->save();
    $bridge->return_tracking();
    $assert( count( $query_order( $mixed_id )['fulfillments'] ) === 1, 'lost fulfillment acknowledgement retry does not reship mapped lines' );
    $supplier->update_meta_data( '_ul_lab_fulfillment_id', $fulfillment_id );
    $supplier->save();
    $assert( $gateway_baseline === array_map( static fn( $gateway ) => $gateway->enabled, WC()->payment_gateways()->payment_gateways() ), 'payment gateway configuration is unchanged' );
    $initialized = false;
    $mail_check = static function () use ( &$initialized ): void { $initialized = true; };
    add_action( 'phpmailer_init', $mail_check );
    $sent = wp_mail( 'recipient@example.invalid', 'Synthetic bridge check', 'Must not leave the lab.' );
    remove_action( 'phpmailer_init', $mail_check );
    $assert( $sent && ! $initialized, 'lab email is still suppressed before transport' );
    WP_CLI::success( 'Live synthetic catalog, stock, mixed order, wholesale, duplicate, and tracking tests passed.' );
} finally {
    foreach ( $order_ids as $id ) {
        foreach ( wc_get_orders( array( 'limit' => -1, 'meta_key' => $order_meta, 'meta_value' => $id ) ) as $own_order ) {
            $own_order->delete( true );
        }
    }
    wc_update_product_stock( $bridge->product( $sku ), $initial_stock, 'set' );
    foreach ( $order_ids as $id ) {
        $bridge->graphql( 'mutation($id:ID!) {orderDelete(orderId:$id) {deletedId userErrors {field message}}}', array( 'id' => $id ) );
    }
    $bridge->sync_catalog();
    wp_set_current_user( $previous_user );
    WP_CLI::log( 'Removed own test orders and restored supplier stock; existing catalog and retailer markup retained.' );
}
