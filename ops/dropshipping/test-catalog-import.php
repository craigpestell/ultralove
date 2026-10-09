<?php
/**
 * Isolated bounded-import policy tests for the existing Shopify HTTP-feed connector.
 * Run: php ops/dropshipping/test-catalog-import.php
 * No WordPress bootstrap, database, credentials or network requests are used.
 */
if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) ) {
    throw new RuntimeException( 'Run directly using standalone PHP CLI.' );
}
require __DIR__ . '/../../wp-content/plugins/ultralove-shopify-supplier/includes/class-catalog-import.php';

$checks = 0;
$assert = static function ( bool $condition, string $label ) use ( &$checks ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'FAIL: ' . $label );
    }
    ++$checks;
};
$reject = static function ( callable $operation, string $label ) use ( $assert ): void {
    try {
        $operation();
    } catch ( RuntimeException | InvalidArgumentException $error ) {
        $assert( true, $label );
        return;
    }
    $assert( false, $label );
};
$call = static function ( string $method, ...$arguments ): mixed {
    return ( new ReflectionMethod( UltraLove_Shopify_Catalog_Import::class, $method ) )->invoke( null, ...$arguments );
};
$run = str_repeat( 'a', 32 );
$location = 'gid://shopify/Location/1';
$source = static function ( int $id ): array {
    return array(
        'id' => $id, 'parent_id' => 0, 'sku' => 'SKU-' . $id, 'name' => 'Supplier product ' . $id,
        'type' => 'simple', 'description' => '<p>Public supplier description</p>', 'short_description' => '',
        'price' => '12.50', 'currency' => 'CAD', 'global_unique_id' => '123456789',
        'manage_stock' => true, 'stock_quantity' => 4, 'stock_status' => 'instock', 'stock_owner_id' => $id,
        'images' => array( array( 'id' => $id, 'src' => 'https://dev.ultralove.ca/wp-content/uploads/image-' . $id . '.jpg', 'alt' => 'Public image' ) ),
        'data_warnings' => array(), 'attributes' => array(), 'variations' => array(),
    );
};
$feed = static fn( array $records ) => array(
    'generation' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cursor' => 42, 'products' => $records,
);
$payload = static fn( array $record, ?array $catalog = null, float $markup = 0 ) =>
    UltraLove_Shopify_Catalog_Import::payload( $record, $catalog ?? array( $record['id'] => $record ), $location, $run, $markup );

// Discovery and mutation selection are separate: 6,430 public records can be browsed,
// but an explicitly retained selection contains at most 50 roots.
$discovery = array();
for ( $id = 1; $id <= 6430; ++$id ) {
    $discovery[$id] = $source( $id );
}
$discovered = 0;
foreach ( array_chunk( $discovery, 100, true ) as $page ) {
    $assert( count( $page ) <= 100, 'Discovery pages stay bounded.' );
    $discovered += count( $page );
}
$assert( $discovered === 6430, 'Large catalogs are discoverable without an all-catalog mutation.' );
$selected = array_slice( $discovery, 0, 50, true );
$assert( count( $call( 'selected_roots', $feed( $selected ) ) ) === 50, 'Exactly 50 explicit roots are accepted.' );
$reject( fn() => $call( 'selected_roots', $feed( $discovery ) ), '6,430 roots cannot enter import planning.' );
$reject( fn() => $call( 'selected_roots', $feed( array_slice( $discovery, 0, 51, true ) ) ), '51 roots are rejected.' );
$reject( fn() => $payload( $discovery[1], $discovery ), 'Direct payload planning also refuses an unbounded catalog.' );
$assert( count( $payload( $selected[1], $selected )['variants'] ) === 1, '50-root payload planning is accepted.' );

$record = $source( 1 );
$input = $payload( $record );
$assert( $input['status'] === 'DRAFT' && $input['handle'] === 'ultralove-catalog-1', 'New supplier copies are drafts with stable handles.' );
$assert( $input['title'] === $record['name'] && $input['descriptionHtml'] === $record['description'], 'Public content copied verbatim.' );
$assert( $input['variants'][0]['sku'] === 'SKU-1' && $input['variants'][0]['barcode'] === '123456789', 'SKU and barcode retained.' );
$assert( $input['variants'][0]['price'] === '12.50' && $input['variants'][0]['inventoryQuantities'][0]['quantity'] === 4, 'Source price and own available stock copied.' );
$assert( $input['variants'][0]['inventoryItem']['requiresShipping'] === true, 'Absent physical flag retains legacy shipping behavior.' );
$assert( $input['files'][0]['originalSource'] === 'https://ultralove.ca/wp-content/uploads/image-1.jpg', 'Dev uploads rewritten to production public uploads.' );
$assert( in_array( 'ultralove-catalog-run-' . $run, $input['tags'], true ), 'Unique run ownership tag included.' );
$assert( $input['metafields'][0]['value'] === '1', 'Stable source root identity included.' );
$assert( $call( 'same_html', "<img src=\"https://ultralove.ca/wp-content/uploads/logo.png\" />\r\n\r\nPublic description",
    '<img src="https://ultralove.ca/wp-content/uploads/logo.png"><p>Public description</p>' ), 'Shopify void-tag/paragraph normalization is not a supplier content mismatch.' );
$assert( $call( 'same_html', 'First paragraph' . "\n\n" . 'Second paragraph', '<p>First paragraph</p><p>Second paragraph</p>' ), 'Generated paragraph boundaries preserve source content.' );
$assert( ! $call( 'same_html', '<p>Supplier description</p>', '<p>Retailer description</p>' ), 'Changed retailer description remains a mismatch.' );
$assert( ! $call( 'same_html', '<img src="https://ultralove.ca/wp-content/uploads/a.png">',
    '<img src="https://ultralove.ca/wp-content/uploads/b.png">' ), 'Changed source image references are not normalized away.' );
$assert( ! $call( 'same_html', '<b>First</b> second', 'First <b>second</b>' ), 'Meaningful inline formatting changes remain detectable.' );
$assert( ! $call( 'same_html', 'Hello<b>world</b>', 'Hello <b>world</b>' ), 'Meaningful inline whitespace is preserved.' );
$assert( $call( 'same_html', '<p>Caf&#233; &amp; tea<br>Next</p>', "Caf\xc3\xa9 &amp; tea\nNext" ), 'Entity encoding and generated line boundaries normalize equivalently.' );
$assert( ! $call( 'same_html', '<a href="https://ultralove.ca/a">Link</a>', '<a href="https://ultralove.ca/b">Link</a>' ), 'Changed link targets remain detectable.' );
$assert( ! $call( 'same_html', '<p style="color:red">Text</p>', '<p style="color:blue">Text</p>' ), 'Styled paragraph changes remain detectable.' );
$assert( ! $call( 'same_html', '<img src="a" alt="Supplier">', '<img src="a" alt="Retailer">' ), 'Changed image attributes remain detectable.' );
$with_inline_image = $record;
$with_inline_image['description'] = '<img src="https://dev.ultralove.ca/wp-content/uploads/logo.png" />' . "\n\n" . 'Public description';
$assert( str_contains( $payload( $with_inline_image )['descriptionHtml'], 'https://ultralove.ca/wp-content/uploads/logo.png' ), 'New Shopify descriptions use public production upload references.' );
$assert( $payload( $record, null, 10 )['variants'][0]['price'] === '13.75', 'Explicit price markup applied.' );
$record['requires_shipping'] = false;
$assert( ! $payload( $record )['variants'][0]['inventoryItem']['requiresShipping'], 'Optional digital-product shipping flag honored.' );
$record['images'][0]['src'] = 'https://res.cloudinary.com/ultralove/image/upload/public.jpg';
$assert( $payload( $record )['files'][0]['originalSource'] === $record['images'][0]['src'], 'Cloudinary public media unchanged.' );
foreach ( array(
    'https://example.com/image.jpg', 'http://ultralove.ca/wp-content/uploads/image.jpg',
    'https://ultralove.ca.evil.example/wp-content/uploads/a.jpg',
    'https://res.cloudinary.com:443/a.jpg', 'https://user:password@res.cloudinary.com/a.jpg',
    'https://ultralove.ca/private.jpg',
) as $url ) {
    $record['images'][0]['src'] = $url;
    $reject( fn() => $payload( $record ), 'Unapproved media rejected.' );
}
$record = $source( 1 );
foreach ( array(
    array( 'price', null, 'missing-price' ), array( 'sku', '', 'missing-sku' ),
    array( 'stock_quantity', null, 'unknown-stock' ), array( 'manage_stock', false, 'unmanaged-stock' ),
    array( 'stock_owner_id', 99, 'shared-stock' ), array( 'stock_owner_id', null, 'unknown-stock' ),
    array( 'stock_status', 'unknown', 'unknown-stock' ), array( 'type', 'external', 'unsupported-type' ),
    array( 'type', 'grouped', 'unsupported-type' ),
) as [ $field, $value, $review ] ) {
    $bad = $record;
    $bad[$field] = $value;
    $result = $payload( $bad );
    $assert( $result['variants'][0]['inventoryQuantities'][0]['quantity'] === 0, 'Ambiguous source inventory stays zero: ' . $review );
    $assert( in_array( 'ultralove-review-' . $review, $result['tags'], true ) && $result['status'] === 'DRAFT', 'Review reason retained: ' . $review );
    if ( $field === 'price' ) {
        $assert( $result['variants'][0]['price'] === '0.00', 'Missing price retained as reviewed zero price.' );
    }
}
$record['stock_status'] = 'outofstock';
$assert( $payload( $record )['variants'][0]['inventoryQuantities'][0]['quantity'] === 0, 'Out-of-stock supply not made available.' );
$record = $source( 1 );
$record['images'] = array();
$record['data_warnings'] = array( array( 'code' => 'missing_image', 'attachment_id' => 123 ) );
$input = $payload( $record );
$assert( in_array( 'ultralove-review-missing-image', $input['tags'], true ), 'Image-less products retained for review.' );
$assert( str_contains( $input['metafields'][1]['value'], '"attachment_id":123' ), 'Missing attachment warning retained, not silently dropped.' );

$parent = $source( 1 );
$parent['type'] = 'variable';
$parent['variations'] = array( 101, 102 );
$child = $source( 101 );
$child['parent_id'] = 1;
$child['type'] = 'variation';
$child['attributes'] = array( array( 'name' => 'Size', 'options' => array( 'Small' ) ) );
$child2 = $child;
$child2['id'] = 102;
$child2['sku'] = 'SKU-102';
$child2['stock_owner_id'] = 1;
$child2['images'] = $source( 102 )['images'];
$child2['attributes'][0]['options'] = array( 'Large' );
$catalog = array( 1 => $parent, 101 => $child, 102 => $child2 );
$assert( count( $call( 'selected_roots', $feed( $catalog ) ) ) === 1, 'Variations do not consume selected-root slots.' );
$input = $payload( $parent, $catalog );
$assert( $input['productOptions'][0]['name'] === 'Size' && count( $input['variants'] ) === 2, 'Native variant options used when feasible.' );
$assert( array_column( $input['files'], 'originalSource' ) === array(
    'https://ultralove.ca/wp-content/uploads/image-1.jpg',
    'https://ultralove.ca/wp-content/uploads/image-101.jpg',
    'https://ultralove.ca/wp-content/uploads/image-102.jpg',
), 'All root and child media are included in the actual payload.' );
$assert( $input['variants'][0]['inventoryQuantities'][0]['quantity'] === 4
    && $input['variants'][1]['inventoryQuantities'][0]['quantity'] === 0, 'Shared-parent stock never duplicated onto children.' );
$assert( $input['variants'][1]['metafields'][0]['value'] === '102', 'Source variant IDs remain stable.' );
$catalog[102]['attributes'][0]['options'] = array( 'Small' );
$input = $payload( $parent, $catalog );
$assert( $input['productOptions'][0]['name'] === 'Variant'
    && $input['variants'][0]['optionValues'] !== $input['variants'][1]['optionValues'], 'Duplicate attributes use unique compound labels plus IDs.' );
$parent['variations'] = array( 101 );
$catalog[1] = $parent;
$assert( count( $payload( $parent, $catalog )['variants'] ) === 2, 'Feed children omitted from parent listing are still retained.' );
$reject( fn() => $payload( $parent, array( 1 => $parent ) ), 'Missing referenced child is an explicit failure.' );
$parent['variations'] = array();
$input = $payload( $parent, array( 1 => $parent ) );
$assert( $input['variants'][0]['inventoryQuantities'][0]['quantity'] === 0
    && in_array( 'ultralove-review-empty-variable', $input['tags'], true ), 'Empty variable parents are retained as reviewed zero-stock drafts.' );
$many = array( 1 => $parent );
for ( $id = 10000; $id < 12010; ++$id ) {
    $variant = $source( $id );
    $variant['parent_id'] = 1;
    $variant['type'] = 'variation';
    $variant['images'] = array();
    $many[$id] = $variant;
}
$assert( count( $call( 'selected_roots', $feed( $many ) ) ) === 1, 'Over 2,000 variations still count as one selected root.' );
$assert( count( $payload( $parent, $many )['variants'] ) === 2010, 'Every source variant retained below the native 2,048 limit.' );
$fiftyWithVariants = $many;
for ( $id = 2; $id <= 50; ++$id ) {
    $fiftyWithVariants[$id] = $source( $id );
}
$assert( count( $call( 'selected_roots', $feed( $fiftyWithVariants ) ) ) === 50, '50 selected roots plus 2,010 child variations are accepted.' );
$assert( count( $payload( $parent, $fiftyWithVariants )['variants'] ) === 2010, 'Child expansion does not consume root payload budget.' );
for ( $id = 12010; $id < 12049; ++$id ) {
    $many[$id] = $variant;
    $many[$id]['id'] = $id;
}
$reject( fn() => $payload( $parent, $many ), '2,049 variants fail explicitly rather than dropping variants.' );
$record = $source( 1 );
$record['images'] = array();
for ( $id = 0; $id < 251; ++$id ) {
    $record['images'][] = array( 'src' => 'https://res.cloudinary.com/ultralove/' . $id . '.jpg', 'alt' => '' );
}
$reject( fn() => $payload( $record ), '251 media fail explicitly rather than omitting media.' );

$product = array( 'id' => 'gid://shopify/Product/1', 'handle' => 'ultralove-catalog-1',
    'title' => 'Retailer title', 'descriptionHtml' => 'Retailer description', 'status' => 'DRAFT',
    'tags' => array( 'ultralove-catalog-import', 'ultralove-catalog-run-' . $run ),
    'supplierId' => array( 'value' => '1' ), 'variants' => array( 'nodes' => array( array( 'sku' => 'SKU-1' ) ) ) );
$matched = $call( 'match', array( $product ), 1, $run );
$assert( $matched['created'] && $matched['product']['id'] === $product['id'], 'Resume matches exact owned copy without recreating or updating fields.' );
$assert( $call( 'match', array(), 1, $run ) === null, 'Absent product is distinguishable from collision.' );
$collision = $product;
$collision['supplierId']['value'] = '999';
$reject( fn() => $call( 'match', array( $collision ), 1, $run ), 'Handle collision explicitly refused.' );
$collision = $product;
$collision['tags'] = array( 'ultralove-catalog-import' );
$reject( fn() => $call( 'match', array( $collision ), 1, $run ), 'Copy without a valid catalog run tag cannot be adopted.' );
$duplicate = $product;
$duplicate['id'] = 'gid://shopify/Product/2';
$reject( fn() => $call( 'match', array( $product, $duplicate ), 1, $run ), 'Duplicate identities explicitly refused.' );
$previous = $product;
$previous['tags'] = array( 'ultralove-catalog-import', 'ultralove-catalog-run-' . str_repeat( 'd', 32 ) );
$previous['status'] = 'ACTIVE';
$previous['variants'] = array(
    array( 'id' => 'gid://shopify/ProductVariant/11', 'sku' => 'RETAILER-1', 'price' => '90.00',
        'inventoryItem' => array( 'inventoryLevel' => array( 'quantities' => array( array( 'name' => 'available', 'quantity' => 7 ) ) ) ) ),
    array( 'id' => 'gid://shopify/ProductVariant/12', 'sku' => 'RETAILER-2', 'price' => '95.00',
        'inventoryItem' => array( 'inventoryLevel' => array( 'quantities' => array( array( 'name' => 'available', 'quantity' => 8 ) ) ) ) ),
);
$matched = $call( 'match', array( $previous ), 1, $run );
$assert( ! $matched['created'], 'A properly identified previous-run product is reused, not owned by the current batch.' );
$assert( ! $call( 'deletable', array( 'id' => $previous['id'], 'created' => true ), $previous, 1, $run ),
    'Even an incorrect current-created flag cannot delete previous-run ownership.' );
$planningState = array( 'run_id' => $run, 'mappings' => array(), 'baseline' => array(), 'lines' => array() );
$snapshotReads = 0;
$checkpoints = array();
$readPrevious = static function ( string $id ) use ( &$snapshotReads, $previous, $assert ): array {
    ++$snapshotReads;
    $assert( $id === $previous['id'], 'Planning snapshots the exact reused product.' );
    return $previous;
};
$savePlan = static function ( array $state ) use ( &$checkpoints ): void { $checkpoints[] = $state; };
$one = array( 1 => $source( 1 ) );
$plan = $call( 'plan', $one, $one, array( $previous ), $planningState, $location, $readPrevious, $savePlan );
$assert( $snapshotReads === 1 && count( $checkpoints ) === 1, 'Actual reused planning branch snapshots and checkpoints without throwing.' );
$assert( $plan['jsonl'] === '' && $plan['state']['lines'] === array()
    && $plan['state']['mappings'][1]['created'] === false, 'Actual reused planning branch emits no creation input.' );
$assert( $plan['state']['baseline'][1] === $call( 'baseline', $previous )
    && $plan['state']['baseline'][1]['status'] === 'ACTIVE'
    && count( $plan['state']['baseline'][1]['variants'] ) === 2, 'Generic reuse preserves retailer content, prices, status, stock and all variants without legacy assumptions.' );
$again = $call( 'plan', $one, $one, array( $previous ), $plan['state'], $location, $readPrevious, $savePlan );
$assert( $again['state'] === $plan['state'] && $again['jsonl'] === '', 'Actual planning resume is idempotent for an existing mapping.' );
$changedPrevious = $previous;
$changedPrevious['title'] = 'Edited since checkpoint';
$reject( fn() => $call( 'plan', $one, $one, array( $changedPrevious ), $plan['state'], $location,
    static fn() => $changedPrevious, $savePlan ), 'Planning never rewrites a saved preservation baseline.' );
$reject( fn() => $call( 'plan', $one, $one, array(), $plan['state'], $location, $readPrevious, $savePlan ),
    'Missing saved mapping is rejected only in the not-found planning branch.' );
$noSnapshot = static function (): array { throw new RuntimeException( 'Unexpected snapshot call.' ); };
$currentPlan = $call( 'plan', $one, $one, array( $product ), $planningState, $location, $noSnapshot, $savePlan );
$assert( $currentPlan['state']['mappings'][1]['created'] === true && $currentPlan['jsonl'] === '',
    'Actual matched current-run planning branch does not falsely report a disappeared mapping.' );
$mixed = $one + array( 2 => $source( 2 ) );
$mixedPlan = $call( 'plan', $mixed, $mixed, array( $previous ), $planningState, $location, $readPrevious, $savePlan );
$assert( $mixedPlan['state']['lines'] === array( 2 )
    && json_decode( trim( $mixedPlan['jsonl'] ), true, 512, JSON_THROW_ON_ERROR )['input']['handle'] === 'ultralove-catalog-2',
    'Mixed planning creates only the newly selected product, not the reused one.' );
foreach ( array( 17186 => '1513', 17188 => '2455', 17192 => '70072', 17194 => '70081' ) as $id => $sku ) {
    $legacy = $product;
    $legacy['id'] = 'gid://shopify/Product/' . $id;
    $legacy['handle'] = 'ultralove-dev-' . $sku;
    $legacy['status'] = $id === 17194 ? 'DRAFT' : 'ACTIVE';
    $legacy['tags'] = array( 'ultralove-dev-supplier' );
    $legacy['supplierId'] = null;
    $legacy['variants']['nodes'][0]['sku'] = $sku;
    $assert( $call( 'match', array( $legacy ), $id, $run )['created'] === false, 'Existing legacy source reused, never overwritten: ' . $id );
    $assert( ! $call( 'deletable', array( 'id' => $legacy['id'], 'created' => false ), $legacy, $id, $run ), 'Legacy product never eligible for cleanup.' );
    $legacy['status'] = $id === 17194 ? 'ACTIVE' : 'ARCHIVED';
    $legacy['variants'] = array( array( 'id' => 'gid://shopify/ProductVariant/' . $id, 'sku' => $sku, 'price' => '85.00' ) );
    $legacySource = $source( $id );
    $legacySource['sku'] = $sku;
    // Preload uses a first-variant connection, whereas preservation snapshots contain all variants.
    $legacyRemote = $legacy;
    $legacyRemote['variants'] = array( 'nodes' => array( array( 'sku' => $sku ) ) );
    $legacyPlan = $call( 'plan', array( $id => $legacySource ), array( $id => $legacySource ),
        array( $legacyRemote ), $planningState, $location, static fn() => $legacy, $savePlan );
    $assert( $legacyPlan['state']['baseline'][$id]['status'] === $legacy['status'] && $legacyPlan['jsonl'] === '',
        'Legacy planning preserves retailer-chosen publication status: ' . $id );
}
$mapping = array( 'id' => $product['id'], 'created' => true );
$assert( $call( 'deletable', $mapping, $product, 1, $run ), 'Exact run-owned draft can be explicitly cleaned.' );
foreach ( array( 'active', 'unrelated', 'reused', 'deleted', 'wrong-id' ) as $case ) {
    $guarded = $product;
    $guardedMapping = $mapping;
    if ( $case === 'active' ) { $guarded['status'] = 'ACTIVE'; }
    if ( $case === 'unrelated' ) { $guarded['tags'] = array(); }
    if ( $case === 'reused' ) { $guardedMapping['created'] = false; }
    if ( $case === 'deleted' ) { $guardedMapping['deleted'] = true; }
    if ( $case === 'wrong-id' ) { $guardedMapping['id'] = 'gid://shopify/Product/9'; }
    $assert( ! $call( 'deletable', $guardedMapping, $guarded, 1, $run ), 'Cleanup protects ' . $case . ' listings.' );
}
$snapshot = $product;
$snapshot['variants'] = array( array( 'id' => 'gid://shopify/ProductVariant/1', 'sku' => 'RETAILER-SKU',
    'price' => '72.00', 'inventoryItem' => array( 'inventoryLevel' => array( 'quantities' => array( array( 'name' => 'available', 'quantity' => 17 ) ) ) ) ) );
$baseline = $call( 'baseline', $snapshot );
$assert( $baseline['title'] === 'Retailer title' && $baseline['descriptionHtml'] === 'Retailer description'
    && $baseline['variants'][0]['price'] === '72.00', 'Baseline retains retailer content, price and stock.' );
$changed = $snapshot;
$changed['variants'][0]['price'] = '73.00';
$assert( $call( 'baseline', $changed ) !== $baseline, 'Retailer changes detected, not overwritten.' );
$digest = str_repeat( 'b', 64 );
$call( 'bind_feed', array( 'feed_digest' => $digest ), $digest );
$assert( true, 'Identical immutable selected feed can resume.' );
$reject( fn() => $call( 'bind_feed', array( 'feed_digest' => str_repeat( 'c', 64 ) ), $digest ), 'Changed feed cannot resume.' );
$reject( fn() => $call( 'bind_feed', array( 'feed_digest' => $digest, 'cleanup_started' => true ), $digest ), 'Cleaned products cannot be recreated by rerunning.' );
$mutation = 'mutation UltraLoveCatalog_' . $run . '($input:ProductSetInput!){productSet(input:$input){product{id}}}';
$operation = array( 'id' => 'gid://shopify/BulkOperation/1', 'query' => $mutation );
$assert( $call( 'recovered_operation', array( $operation ), $mutation ) === $operation['id'], 'Uncertain launch recovered by exact saved query.' );
$reject( fn() => $call( 'recovered_operation', array(), $mutation ), 'Missing uncertain launch is never blindly retried.' );
$reject( fn() => $call( 'recovered_operation', array( $operation, $operation ), $mutation ), 'Ambiguous launch recovery refused.' );
$reject( fn() => $call( 'recovered_operation', array( array( 'id' => $operation['id'], 'query' => 'different', 'clientIdentifier' => $run ) ), $mutation ), 'Client identifier is not substituted for exact-query recovery.' );
$assert( $call( 'terminal', 'FAILED' ) && ! $call( 'terminal', 'CANCELING' ), 'Cleanup distinguishes terminal bulk from in-flight cancellation.' );

$resultProduct = array( 'id' => $product['id'], 'handle' => $product['handle'], 'status' => 'DRAFT' );
foreach ( array(
    array( '__lineNumber' => 0, 'data' => array( 'productSet' => array( 'product' => $resultProduct, 'userErrors' => array() ) ) ),
    array( '__lineNumber' => 0, 'productSet' => array( 'product' => $resultProduct, 'userErrors' => array() ) ),
) as $row ) {
    $result = $call( 'result', $row, array( 1 ) );
    $assert( $result['supplier'] === 1 && $result['product']['id'] === $product['id'] && ! $result['errors'], 'Both official JSONL result shapes retain source identity.' );
}
$row['productSet']['userErrors'] = array( array( 'field' => array( 'files' ), 'message' => 'Media failure' ) );
$result = $call( 'result', $row, array( 1 ) );
$assert( $result['product']['id'] === $product['id'] && $result['errors'], 'Partial errors retain newly created IDs for ownership cleanup.' );
$reject( fn() => $call( 'result', array( '__lineNumber' => 2, 'productSet' => $row['productSet'] ), array( 1 ) ), 'Unknown JSONL line refused.' );
$row['productSet']['product']['handle'] = 'unrelated';
$reject( fn() => $call( 'result', $row, array( 1 ) ), 'Mismatched bulk result identity refused.' );
$assert( $call( 'result', array( '__lineNumber' => 0, 'errors' => array( array( 'message' => 'Failed' ) ) ), array( 1 ) )['errors'] !== array(),
    'Missing product result is an explicit error.' );
foreach ( array( 'https://storage.googleapis.com/public/result.jsonl', 'https://shopify-staged-uploads.storage.googleapis.com/' ) as $url ) {
    $call( 'allowed_url', $url, false );
    $assert( true, 'Shopify staged public-storage host allowed without credentials.' );
}
$reject( fn() => $call( 'allowed_url', 'https://example.com/result', false ), 'Unrelated result storage rejected.' );
$reject( fn() => $call( 'allowed_url', 'https://storage.googleapis.com/result', true ), 'Access token cannot be sent to storage.' );
$assert( $call( 'throttle_delay', null ) === 0.0, 'Absent optional throttle metadata adds no delay.' );
$cost = array( 'requestedQueryCost' => 202, 'throttleStatus' => array(
    'maximumAvailable' => 1000, 'currentlyAvailable' => 100, 'restoreRate' => 100,
) );
$assert( $call( 'throttle_delay', $cost ) === 4.0, 'Response cost metadata paces the next request until a 500-point reserve is restored.' );
$cost['throttleStatus']['currentlyAvailable'] = 600;
$assert( $call( 'throttle_delay', $cost ) === 0.0, 'Sufficient query capacity does not unnecessarily sleep.' );
$cost['requestedQueryCost'] = 750;
$cost['throttleStatus']['currentlyAvailable'] = 500;
$assert( $call( 'throttle_delay', $cost ) === 2.5, 'A larger previous requested cost increases the query reserve.' );
$cost['throttleStatus']['maximumAvailable'] = 200;
$cost['throttleStatus']['currentlyAvailable'] = 100;
$assert( $call( 'throttle_delay', $cost ) === 1.0, 'Reserve never exceeds the available bucket maximum.' );
$cost['throttleStatus']['restoreRate'] = 0;
$reject( fn() => $call( 'throttle_delay', $cost ), 'Exhausted nonrecovering capacity fails without mutation retry.' );
$cost['throttleStatus']['restoreRate'] = -1;
$reject( fn() => $call( 'throttle_delay', $cost ), 'Invalid throttle metadata fails explicitly.' );
$reject( fn() => new UltraLove_Shopify_Catalog_Import( __DIR__, str_repeat( 'b', 64 ) ), 'Constructor refuses non-root execution or state outside the guarded root directory.' );

// Only locally generated public test text is read; never inspect credential paths.
$fixture = __DIR__ . '/.catalog-import-policy-' . bin2hex( random_bytes( 8 ) );
try {
    file_put_contents( $fixture, 'public test fixture' );
    chmod( $fixture, 0644 );
    $reject( fn() => UltraLove_Shopify_Catalog_Import::private_file( $fixture ), 'Nonprivate state permissions rejected.' );
    chmod( $fixture, 0600 );
    if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 ) {
        $assert( UltraLove_Shopify_Catalog_Import::private_file( $fixture ) === 'public test fixture', 'Root-owned private state accepted.' );
    } else {
        $reject( fn() => UltraLove_Shopify_Catalog_Import::private_file( $fixture ), 'Non-root-owned state rejected.' );
    }
    symlink( $fixture, $fixture . '-link' );
    $reject( fn() => UltraLove_Shopify_Catalog_Import::private_file( $fixture . '-link' ), 'Symlink state rejected.' );
    link( $fixture, $fixture . '-hardlink' );
    $reject( fn() => UltraLove_Shopify_Catalog_Import::private_file( $fixture ), 'Hard-linked state rejected.' );
} finally {
    foreach ( array( $fixture . '-hardlink', $fixture . '-link', $fixture ) as $path ) {
        if ( file_exists( $path ) || is_link( $path ) ) { unlink( $path ); }
    }
}
$assert( ! defined( 'ABSPATH' ), 'Tests and importer do not bootstrap WordPress.' );
echo 'PASS: ' . $checks . " bounded existing-connector checks; no network, credentials, WordPress or database access.\n";
