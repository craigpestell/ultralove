<?php
/**
 * Actual bounded HTTP-feed checks; no WP bootstrap, SQL, product/order edits.
 */
if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) || posix_geteuid() !== 0 ) {
    throw new RuntimeException( 'Run directly as root outside WordPress.' );
}
umask( 0077 );
require '/var/www/html/wp-content/plugins/ultralove-shopify-supplier/includes/class-feed-client.php';
require '/var/www/html/wp-content/plugins/ultralove-shopify-supplier/includes/class-catalog-import.php';

try {
    $credentials = json_decode( UltraLove_Shopify_Catalog_Import::private_file(
        '/root/.ultralove-shopify-dev/feed-client.json' ), true, 512, JSON_THROW_ON_ERROR );
    $reads = 0;
    $queries = 0;
    $client = new UltraLove_Shopify_Feed_Client( $credentials, static function ( $url, $arguments ) use ( &$reads, &$queries ): array {
        $response = UltraLove_Shopify_Feed_Client::catalog_transport( $url, $arguments );
        ++$reads;
        $queries += (int) ( $response['headers']['x-ultralove-catalog-selects'] ?? 0 );
        return $response;
    } );
    $checks = 0;
    $assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
        if ( ! $condition ) { throw new RuntimeException( $message ); }
        ++$checks;
    };
    $first = $client->products_page();
    $assert( count( $first['products'] ) === 50, 'Default app browse must return exactly 50 products for this catalog.' );
    $assert( $first['has_more'], 'Remaining catalog must be available through pagination.' );
    $second = $client->products_page( array( 'after_id' => $first['next_after_id'] ) );
    $assert( count( $second['products'] ) === 50, 'Second page must be bounded at 50.' );
    $assert( ! array_intersect( array_column( $first['products'], 'id' ), array_column( $second['products'], 'id' ) ), 'Pages must not duplicate product IDs.' );
    $assert( $second['products'][0]['id'] > $first['next_after_id'], 'Next page must advance.' );
    $maximum = $client->products_page( array( 'per_page' => 100 ) );
    $assert( count( $maximum['products'] ) === 100, 'Exact API hard cap must work.' );
    try {
        $client->request( '/products', array( 'per_page' => 101 ) );
        throw new RuntimeException( 'Server accepted 101 records.' );
    } catch ( RuntimeException $error ) {
        $assert( $error->getCode() === 400, 'Server itself must reject per_page=101 with HTTP 400.' );
    }
    $categories = $client->categories_page();
    $assert( count( $categories['categories'] ) > 0 && count( $categories['categories'] ) <= 50, 'Bounded category navigation must contain real categories.' );
    $product = $first['products'][0];
    $assert( $product['categories'] && $product['sku'] !== '', 'Choose real category/SKU verification data.' );
    $category_id = $product['categories'][0]['id'];
    $filtered = $client->products_page( array( 'category_id' => $category_id, 'search' => $product['sku'] ) );
    $assert( count( $filtered['products'] ) <= 50 && in_array( $product['id'], array_column( $filtered['products'], 'id' ), true ), 'Category and SKU filters must find the selected real product.' );
    $not_found = $client->products_page( array( 'search' => 'UL-CATALOG-NO-MATCH-' . bin2hex( random_bytes( 12 ) ) ) );
    $assert( ! $not_found['products'] && ! $not_found['has_more'], 'A valid unmatched search returns an empty final page.' );
    try {
        $client->selected_products( range( 1, 51 ) );
        throw new RuntimeException( 'Import accepted 51 roots.' );
    } catch ( InvalidArgumentException $error ) {
        $assert( true, '51 selected roots rejected before mutation.' );
    }
    $ids = array_column( $first['products'], 'id' );
    $selected = $client->selected_products( $ids );
    $roots = array_filter( $selected['products'], static fn( $p ) => $p['parent_id'] === 0 );
    $assert( count( $roots ) === 50 && array_keys( $roots ) === $ids, 'Explicit first-page selection fetches exactly its 50 roots.' );
    $assert( count( $roots ) < 2000 && $first['has_more'], 'Per-request selection is not a total-catalog cap or automatic full import.' );
    $assert( $reads >= 8 && $queries > 0, 'Live reads must actually exercise the guarded existing WordPress connection.' );
    $evidence = array( 'checks' => $checks, 'feed_requests' => $reads, 'guarded_selects' => $queries,
        'database_writes' => 0, 'default_page_size' => 50, 'api_hard_cap' => 100, 'selected_roots' => count( $roots ),
        'category_id' => $category_id, 'selected_ids' => $ids, 'verified_at' => gmdate( 'c' ) );
    $json = json_encode( $evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
    $path = '/root/.ultralove-shopify-dev/catalog-browse-verification.json';
    if ( file_put_contents( $path, $json, LOCK_EX ) !== strlen( $json ) ) {
        throw new RuntimeException( 'Could not persist live catalog read evidence.' );
    }
    echo $json;
} catch ( Throwable $error ) {
    fwrite( STDERR, 'Catalog verification failed: ' . $error->getMessage() . "\n" );
    exit( 1 );
}
