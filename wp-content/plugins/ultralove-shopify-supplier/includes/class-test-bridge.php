<?php
/**
 * Shared test-store bridge. Loaded only by guarded WP-CLI entry points.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

final class UltraLove_Shopify_Test_Bridge {
    const SHOP = 'ultralove-integration-lab.myshopify.com';
    const DIRECTORY = '/root/.ultralove-integration-lab/';
    const SKUS = array( 'LAB-SIMPLE-001', 'LAB-SIMPLE-002' );
    const ORDER_META = '_ul_lab_shopify_order';
    const PRODUCT_META = '_ul_lab_shopify_product';
    private string $token;
    private string $location;
    private string $directory;
    private string $source;
    private string $product_meta;
    private string $order_meta;
    private string $tag;
    private string $retailer_login;
    private float $markup;
    private array $skus;
    private $lock;

    public function __construct( string $source = 'lab' ) {
        $this->source = $source;
        if ( wp_get_environment_type() !== 'development' || get_woocommerce_currency() !== 'CAD' ) {
            throw new RuntimeException( 'Refusing to run outside a CAD development supplier.' );
        }
        if ( $source === 'lab' ) {
            if ( ! is_readable( '/etc/ultralove-integration-lab' )
                || trim( file_get_contents( '/etc/ultralove-integration-lab' ) ) !== 'ultralove-integration-lab'
                || home_url() !== 'https://ultralove-lab.104-248-211-144.sslip.io'
                || DB_HOST !== 'localhost' || DB_NAME !== 'integration_lab' ) {
                throw new RuntimeException( 'Refusing to run outside the dedicated supplier lab.' );
            }
            $this->directory = self::DIRECTORY;
            $this->skus = self::SKUS;
            $this->product_meta = self::PRODUCT_META;
            $this->order_meta = self::ORDER_META;
            $this->tag = 'ultralove-integration-lab';
            $this->retailer_login = 'shopify-lab-retailer';
            $this->markup = 50;
        } elseif ( $source === 'dev' && home_url() === 'https://dev.ultralove.ca' && DB_NAME === 'wordpress_dev'
            && class_exists( 'UltraLove_Shopify_Supplier' ) && UltraLove_Shopify_Supplier::enabled() ) {
            $settings = UltraLove_Shopify_Supplier::settings();
            $this->directory = '/root/.ultralove-shopify-dev/';
            $this->product_meta = '_ul_dev_shopify_product';
            $this->order_meta = '_ul_dev_shopify_order';
            $this->tag = 'ultralove-dev-supplier';
            $this->markup = $settings['markup'];
            $retailer = get_user_by( 'id', $settings['retailer_id'] );
            if ( ! $retailer || get_user_meta( $retailer->ID, UltraLove_Dropshipping::APPROVAL, true ) !== 'yes' ) {
                throw new RuntimeException( 'An approved dev retailer account is required.' );
            }
            $this->retailer_login = $retailer->user_login;
            $this->skus = array();
            foreach ( $settings['product_ids'] as $id ) {
                $product = wc_get_product( $id );
                if ( ! UltraLove_Shopify_Supplier::eligible( $product ) ) {
                    throw new RuntimeException( 'Selected product is no longer eligible: ' . $id );
                }
                $this->skus[] = $product->get_sku();
            }
            if ( ! $this->skus ) {
                throw new RuntimeException( 'Select existing dev products before connecting Shopify.' );
            }
        } else {
            throw new RuntimeException( 'Unsupported supplier source or disabled dev integration.' );
        }
        $this->lock = fopen( $this->directory . 'shopify-bridge.lock', 'c' );
        if ( ! $this->lock || ! flock( $this->lock, LOCK_EX | LOCK_NB ) ) {
            throw new RuntimeException( 'Another bridge worker is running, or the lock is unavailable.' );
        }
        $response = wp_remote_post( 'https://' . self::SHOP . '/admin/oauth/access_token', array(
            'timeout' => 30,
            'redirection' => 0,
            'body' => array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->credential( 'shopify-client-id' ),
                'client_secret' => $this->credential( 'shopify-client-secret' ),
            ),
        ) );
        $body = $this->response( $response );
        if ( empty( $body['access_token'] ) ) {
            throw new RuntimeException( 'Shopify did not issue an access token.' );
        }
        $this->token = $body['access_token'];
        $data = $this->graphql( '{shop {myshopifyDomain currencyCode} locations(first:20) {nodes {id name isActive fulfillsOnlineOrders fulfillmentService {id}}}}' );
        if ( $data['shop']['myshopifyDomain'] !== self::SHOP || $data['shop']['currencyCode'] !== 'CAD' ) {
            throw new RuntimeException( 'Unexpected Shopify store or currency; refusing mutations.' );
        }
        $locations = array_values( array_filter( $data['locations']['nodes'], fn( $location ) => $location['name'] === 'Shop location' && $location['isActive'] && $location['fulfillsOnlineOrders'] && ! $location['fulfillmentService'] ) );
        if ( count( $locations ) !== 1 ) {
            throw new RuntimeException( 'Choose an explicit lab location; eligible locations: ' . wp_json_encode( array_column( $locations, 'name' ) ) );
        }
        $this->location = $locations[0]['id'];
    }

    public function __destruct() {
        if ( is_resource( $this->lock ) ) {
            flock( $this->lock, LOCK_UN );
            fclose( $this->lock );
        }
    }

    private function credential( string $name ): string {
        $path = $this->directory . $name;
        if ( ! is_readable( $path ) || ( fileperms( $path ) & 0077 ) !== 0 ) {
            throw new RuntimeException( 'Missing or insecure root-only Shopify credential file.' );
        }
        $value = trim( file_get_contents( $path ) );
        if ( $value === '' ) {
            throw new RuntimeException( 'Empty Shopify credential file.' );
        }
        return $value;
    }

    private function response( $response ): array {
        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( 'Shopify transport failed: ' . $response->get_error_code() );
        }
        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            throw new RuntimeException( 'Shopify HTTP request failed with status ' . $status );
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );
        if ( ! is_array( $body ) ) {
            throw new RuntimeException( 'Unexpected Shopify response shape.' );
        }
        return $body;
    }

    public function graphql( string $query, array $variables = array() ): array {
        $body = $this->response( wp_remote_post( 'https://' . self::SHOP . '/admin/api/2026-10/graphql.json', array(
            'timeout' => 60,
            'redirection' => 0,
            'headers' => array( 'Content-Type' => 'application/json', 'X-Shopify-Access-Token' => $this->token ),
            'body' => wp_json_encode( array( 'query' => $query, 'variables' => (object) $variables ) ),
        ) ) );
        if ( ! empty( $body['errors'] ) ) {
            throw new RuntimeException( 'Shopify GraphQL errors: ' . wp_json_encode( array_column( $body['errors'], 'message' ) ) );
        }
        if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
            throw new RuntimeException( 'Shopify response has no GraphQL data.' );
        }
        foreach ( $body['data'] as $result ) {
            if ( is_array( $result ) && ! empty( $result['userErrors'] ) ) {
                throw new RuntimeException( 'Shopify mutation rejected: ' . wp_json_encode( $result['userErrors'] ) );
            }
        }
        return $body['data'];
    }

    public function product( string $sku ): WC_Product {
        if ( ! in_array( $sku, $this->skus, true ) ) {
            throw new RuntimeException( 'SKU is not on the selected supplier allowlist.' );
        }
        $product = wc_get_product( wc_get_product_id_by_sku( $sku ) );
        if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->get_manage_stock() || (float) $product->get_price() <= 0 ) {
            throw new RuntimeException( 'A priced simple product with managed stock is required.' );
        }
        return $product;
    }

    public function sync_catalog(): void {
        foreach ( $this->skus as $sku ) {
            $product = $this->product( $sku );
            $handle = sanitize_title( 'ultralove-' . ( $this->source === 'dev' ? 'dev-' : '' ) . $sku );
            $data = $this->graphql( 'query($query:String!) {products(first:2, query:$query) {nodes {id handle variants(first:2) {nodes {id sku price inventoryItem {id}}}}}}',
                array( 'query' => 'handle:' . $handle ) );
            $matches = array_values( array_filter( $data['products']['nodes'], fn( $item ) => $item['handle'] === $handle ) );
            if ( count( $matches ) > 1 ) {
                throw new RuntimeException( 'Ambiguous Shopify lab product handle.' );
            }
            if ( ! $matches ) {
                $input = array(
                    'title' => wp_strip_all_tags( str_replace( '<br>', ' ', $product->get_name() ) ),
                    'handle' => $handle,
                    'descriptionHtml' => wp_kses_post( $product->get_description() ),
                    'vendor' => $this->source === 'dev' ? 'UltraLove' : 'UltraLove Integration Lab',
                    'status' => $this->source === 'dev' ? 'DRAFT' : 'ACTIVE',
                    'tags' => array( $this->tag ),
                    'productOptions' => array( array( 'name' => 'Title', 'values' => array( array( 'name' => 'Default Title' ) ) ) ),
                    'variants' => array( array(
                        'optionValues' => array( array( 'optionName' => 'Title', 'name' => 'Default Title' ) ),
                        'sku' => $sku,
                        'price' => number_format( (float) $product->get_price() * ( 1 + $this->markup / 100 ), 2, '.', '' ),
                        'inventoryItem' => array( 'tracked' => true, 'requiresShipping' => true ),
                        'inventoryQuantities' => array( array( 'locationId' => $this->location, 'name' => 'available', 'quantity' => $product->get_stock_quantity() ) ),
                    ) ),
                );
                if ( $this->source === 'dev' && $product->get_image_id() ) {
                    $image = wp_get_attachment_url( $product->get_image_id() );
                    if ( $image ) {
                        $image = str_replace( 'https://dev.ultralove.ca/wp-content/uploads/', 'https://ultralove.ca/wp-content/uploads/', $image );
                        $input['files'] = array( array( 'originalSource' => $image, 'contentType' => 'IMAGE', 'alt' => $input['title'] ) );
                    }
                }
                $data = $this->graphql( 'mutation($input:ProductSetInput!) {productSet(input:$input,synchronous:true) {product {id handle variants(first:2) {nodes {id sku price inventoryItem {id}}}} userErrors {field message}}}',
                    array( 'input' => $input ) );
                $remote = $data['productSet']['product'];
            } else {
                $remote = $matches[0];
            }
            if ( ! $remote || count( $remote['variants']['nodes'] ) !== 1
                || $remote['variants']['nodes'][0]['sku'] !== $sku ) {
                throw new RuntimeException( 'Shopify lab product has unexpected variants.' );
            }
            $prior = $product->get_meta( $this->product_meta );
            if ( $prior && $prior['id'] !== $remote['id'] ) {
                throw new RuntimeException( 'Mapped Shopify product was replaced; manual reconciliation required.' );
            }
            $product->update_meta_data( $this->product_meta, $remote );
            $product->save();
            $variant = $remote['variants']['nodes'][0];
            $inventory = $this->graphql( 'query($id:ID!,$location:ID!) {inventoryItem(id:$id) {inventoryLevel(locationId:$location) {quantities(names:["available"]) {quantity}}}}',
                array( 'id' => $variant['inventoryItem']['id'], 'location' => $this->location ) );
            $level = $inventory['inventoryItem']['inventoryLevel'];
            if ( ! $level ) {
                throw new RuntimeException( 'Mapped variant is not stocked at the test location.' );
            }
            $available = $level['quantities'][0]['quantity'];
            $quantity = $product->get_stock_quantity();
            if ( $available !== $quantity ) {
                $this->graphql( 'mutation($input:InventorySetQuantitiesInput!,$key:String!) {inventorySetQuantities(input:$input) @idempotent(key:$key) {userErrors {field message}}}',
                    array( 'key' => wp_generate_uuid4(), 'input' => array(
                        'name' => 'available', 'reason' => 'correction',
                        'quantities' => array( array( 'inventoryItemId' => $variant['inventoryItem']['id'], 'locationId' => $this->location, 'quantity' => $quantity, 'changeFromQuantity' => $available ) ),
                    ) ) );
            }
            WP_CLI::log( 'Synced ' . $sku . '; stock ' . $quantity . '; retailer price preserved.' );
        }
    }

    public function import_orders(): void {
        $cursor = null;
        do {
            $data = $this->graphql( 'query($cursor:String,$query:String!) {orders(first:20,after:$cursor,query:$query,sortKey:CREATED_AT) {pageInfo {hasNextPage endCursor} nodes {id name tags test cancelledAt displayFinancialStatus shippingAddress {firstName lastName address1 address2 city provinceCode zip countryCodeV2} lineItems(first:100) {pageInfo {hasNextPage} nodes {id quantity variant {id}}}}}}',
                array( 'cursor' => $cursor, 'query' => 'test:true AND financial_status:paid AND tag:' . $this->tag ) );
            foreach ( $data['orders']['nodes'] as $remote ) {
                $this->import_order( $remote );
            }
            $cursor = $data['orders']['pageInfo']['endCursor'];
        } while ( $data['orders']['pageInfo']['hasNextPage'] );
    }

    public function import_order( array $remote ): ?WC_Order {
        if ( empty( $remote['test'] ) || $remote['cancelledAt'] || $remote['displayFinancialStatus'] !== 'PAID'
            || ! in_array( $this->tag, $remote['tags'], true ) ) {
            return null;
        }
        if ( $remote['lineItems']['pageInfo']['hasNextPage'] ) {
            throw new RuntimeException( 'Test order exceeds the 100-line lab limit.' );
        }
        $existing = wc_get_orders( array( 'limit' => 2, 'meta_key' => $this->order_meta, 'meta_value' => $remote['id'] ) );
        if ( count( $existing ) > 1 ) {
            throw new RuntimeException( 'Duplicate supplier orders require manual reconciliation.' );
        }
        if ( $existing ) {
            if ( $existing[0]->get_meta( '_ul_lab_import_complete' ) !== 'yes' ) {
                throw new RuntimeException( 'Incomplete supplier import requires manual reconciliation; refusing duplicate creation.' );
            }
            return $existing[0];
        }
        $lines = array();
        foreach ( $remote['lineItems']['nodes'] as $line ) {
            foreach ( $this->skus as $sku ) {
                $product = $this->product( $sku );
                $mapping = $product->get_meta( $this->product_meta );
                if ( $mapping && ( $line['variant']['id'] ?? null ) === $mapping['variants']['nodes'][0]['id'] ) {
                    if ( ! is_int( $line['quantity'] ) || $line['quantity'] < 1 || ! $product->has_enough_stock( $line['quantity'] ) ) {
                        throw new RuntimeException( 'Supplier quantity is invalid or exceeds synthetic stock.' );
                    }
                    $lines[] = array( 'product' => $product, 'quantity' => $line['quantity'], 'id' => $line['id'] );
                }
            }
        }
        if ( ! $lines ) {
            return null;
        }
        $quantities = array();
        foreach ( $lines as $line ) {
            $id = $line['product']->get_id();
            $quantities[ $id ] = ( $quantities[ $id ] ?? 0 ) + $line['quantity'];
            if ( ! $line['product']->has_enough_stock( $quantities[ $id ] ) ) {
                throw new RuntimeException( 'Combined supplier quantities exceed synthetic stock.' );
            }
        }
        $address = $remote['shippingAddress'];
        if ( ! $address || $address['firstName'] !== 'Synthetic' || $address['lastName'] !== 'Recipient'
            || $address['address1'] !== '123 Synthetic Street' || empty( $address['city'] )
            || empty( $address['zip'] ) || $address['countryCodeV2'] !== 'CA' ) {
            throw new RuntimeException( 'Complete Canadian synthetic delivery address is required.' );
        }
        $retailer = get_user_by( 'login', $this->retailer_login );
        if ( ! $retailer ) {
            throw new RuntimeException( 'Synthetic retailer account is missing.' );
        }
        $customer = new WC_Customer( $retailer->ID );
        $billing = $customer->get_billing();
        $email = $customer->get_billing_email() ?: $customer->get_email();
        if ( ! is_email( $email ) ) {
            throw new RuntimeException( 'The approved retailer has no valid billing email.' );
        }
        $billing['email'] = $email;
        $order = wc_create_order( array( 'customer_id' => $retailer->ID, 'created_via' => 'ultralove-shopify-' . $this->source ) );
        if ( is_wp_error( $order ) ) {
            throw new RuntimeException( 'Unable to create supplier test order: ' . $order->get_error_code() );
        }
        try {
            $order->set_currency( 'CAD' );
            $order->set_address( $billing, 'billing' );
            $order->set_address( array(
                'first_name' => $address['firstName'], 'last_name' => $address['lastName'],
                'address_1' => $address['address1'], 'address_2' => $address['address2'],
                'city' => $address['city'], 'state' => $address['provinceCode'],
                'postcode' => $address['zip'], 'country' => $address['countryCodeV2'],
            ), 'shipping' );
            $order->update_meta_data( $this->order_meta, $remote['id'] );
            $order->update_meta_data( '_ultralove_dropship', 'yes' );
            $order->update_meta_data( '_ultralove_dropship_retailer_email', $email );
            $order->update_meta_data( '_ul_lab_supplier_payment', 'unpaid-test-only' );
            foreach ( $lines as $line ) {
                $total = wc_format_decimal( (float) $line['product']->get_price() * $line['quantity'] );
                $item_id = $order->add_product( $line['product'], $line['quantity'], array( 'subtotal' => $total, 'total' => $total ) );
                $item = $order->get_item( $item_id );
                $item->add_meta_data( '_ul_lab_shopify_line', $line['id'], true );
                $item->save();
            }
            $order->calculate_totals( false );
            $order->save();
            $order->update_status( 'on-hold', 'Synthetic Shopify test payment is NOT supplier payment. No real dispatch.' );
            wc_reduce_stock_levels( $order->get_id() );
            $order->update_meta_data( '_ul_lab_import_complete', 'yes' );
            $order->save();
        } catch ( Throwable $error ) {
            $order->add_order_note( 'Bridge import failed; inspect this test order before retrying.' );
            throw $error;
        }
        WP_CLI::log( 'Created wholesale supplier test order ' . $order->get_id() . ' with ' . count( $lines ) . ' mapped lines.' );
        return $order;
    }

    public function return_tracking(): void {
        $orders = wc_get_orders( array( 'limit' => -1, 'status' => 'completed', 'meta_key' => $this->order_meta, 'meta_compare' => 'EXISTS' ) );
        foreach ( $orders as $order ) {
            $tracking = $order->get_meta( '_ul_lab_tracking_number' );
            if ( ! $tracking || $order->get_meta( '_ul_lab_fulfillment_id' ) ) {
                continue;
            }
            if ( ! str_starts_with( $tracking, 'LAB-' ) ) {
                throw new RuntimeException( 'Only synthetic LAB- tracking numbers may be forwarded.' );
            }
            $data = $this->graphql( 'query($id:ID!) {order(id:$id) {test fulfillmentOrders(first:20) {pageInfo {hasNextPage} nodes {id status assignedLocation {location {id}} lineItems(first:100) {pageInfo {hasNextPage} nodes {id remainingQuantity lineItem {id}}}}}}}',
                array( 'id' => $order->get_meta( $this->order_meta ) ) );
            $remote = $data['order'];
            if ( ! $remote || ! $remote['test'] || $remote['fulfillmentOrders']['pageInfo']['hasNextPage'] ) {
                throw new RuntimeException( 'Invalid test fulfillment order or exceeded pagination limit.' );
            }
            $mapped = array();
            foreach ( $order->get_items() as $item ) {
                $mapped[ $item->get_meta( '_ul_lab_shopify_line' ) ] = $item->get_quantity();
            }
            $groups = array();
            foreach ( $remote['fulfillmentOrders']['nodes'] as $fulfillment ) {
                if ( $fulfillment['lineItems']['pageInfo']['hasNextPage'] ) {
                    throw new RuntimeException( 'Fulfillment exceeds the lab line limit.' );
                }
                $items = array();
                foreach ( $fulfillment['lineItems']['nodes'] as $line ) {
                    if ( isset( $mapped[ $line['lineItem']['id'] ] ) && $line['remainingQuantity'] > 0 ) {
                        if ( $fulfillment['assignedLocation']['location']['id'] !== $this->location ) {
                            throw new RuntimeException( 'Supplier fulfillment moved to an unexpected location.' );
                        }
                        $items[] = array( 'id' => $line['id'], 'quantity' => min( $mapped[ $line['lineItem']['id'] ], $line['remainingQuantity'] ) );
                    }
                }
                if ( $items ) {
                    $groups[] = array( 'fulfillmentOrderId' => $fulfillment['id'], 'fulfillmentOrderLineItems' => $items );
                }
            }
            if ( ! $groups ) {
                WP_CLI::warning( 'No remaining mapped Shopify lines for supplier order ' . $order->get_id() . '; no fulfillment created.' );
                continue;
            }
            $result = $this->graphql( 'mutation($input:FulfillmentInput!) {fulfillmentCreate(fulfillment:$input) {fulfillment {id} userErrors {field message}}}', array( 'input' => array(
                'notifyCustomer' => false,
                'trackingInfo' => array( 'number' => $tracking, 'company' => 'Synthetic lab carrier' ),
                'lineItemsByFulfillmentOrder' => $groups,
            ) ) );
            $order->update_meta_data( '_ul_lab_fulfillment_id', $result['fulfillmentCreate']['fulfillment']['id'] );
            $order->save();
            WP_CLI::log( 'Returned synthetic tracking for supplier order ' . $order->get_id() . '; unrelated lines untouched.' );
        }
    }

    public function run(): void {
        $this->import_orders();
        $this->sync_catalog();
        $this->return_tracking();
        update_option( $this->source === 'dev' ? 'ul_dev_shopify_last_success' : 'ul_lab_shopify_last_success', gmdate( 'c' ), false );
        WP_CLI::success( 'Test-store bridge cycle completed.' );
    }

    public function skus(): array { return $this->skus; }
    public function product_meta(): string { return $this->product_meta; }
    public function order_meta(): string { return $this->order_meta; }
    public function tag(): string { return $this->tag; }
    public function retailer_login(): string { return $this->retailer_login; }
}
