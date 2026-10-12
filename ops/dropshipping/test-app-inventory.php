<?php
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
    throw new RuntimeException( 'Run directly with PHP CLI.' );
}
require __DIR__ . '/../../wp-content/plugins/ultralove-shopify-supplier/includes/class-feed-client.php';
$checks = 0;
$generation = '00000000-0000-4000-8000-000000000001';
$assert = static function ( bool $condition, string $label ) use ( &$checks ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
    ++$checks;
};
$response = static fn( array $data, int $status = 200 ) => array(
    'status' => $status, 'headers' => array( 'content-type' => 'application/json' ), 'body' => json_encode( $data ),
);
$snapshot = static fn( array $rows, string $key = 'products', string $gen = '', int $cursor = 8 ) => array(
    'generation' => $gen ?: $generation, 'change_cursor' => $cursor, 'next_after_id' => 0, 'has_more' => false, $key => $rows,
);
$changes = static fn( int $cursor = 8, array $rows = array(), bool $more = false ) => array(
    'generation' => $generation, 'cursor' => $cursor, 'latest_cursor' => $cursor, 'has_more' => $more, 'changes' => $rows,
);
$parent = array( 'id' => 100, 'parent_id' => 0, 'currency' => 'CAD', 'type' => 'simple', 'variations' => array() );
$stock = array( 'id' => 100, 'sku' => 'TEST-100', 'manage_stock' => true, 'stock_quantity' => 7, 'stock_owner_id' => 100, 'stock_status' => 'instock' );
$requests = array();
$reader = static function ( array $responses ) use ( &$requests, $assert ): UltraLove_Shopify_Feed_Client {
    $requests = array();
    return new UltraLove_Shopify_Feed_Client( array( 'username' => 'fixture', 'password' => 'not-a-real-password' ),
        static function ( string $url, array $arguments ) use ( &$responses, &$requests, $assert ): array {
            $assert( str_starts_with( $url, 'http://localhost/wp-json/ultralove-feed/v1/' ), 'Uses only existing loopback feed.' );
            $assert( $arguments['redirection'] === 0, 'Feed redirects remain disabled.' );
            parse_str( parse_url( $url, PHP_URL_QUERY ) ?: '', $query );
            $assert( ( $query['per_page'] ?? 0 ) <= 100, 'Each feed request stays within 100 records.' );
            $requests[] = array( 'route' => parse_url( $url, PHP_URL_PATH ), 'query' => $query );
            if ( ! $responses ) {
                throw new RuntimeException( 'Unexpected inventory feed request.' );
            }
            return array_shift( $responses );
        } );
};
$reject = static function ( callable $call, string $label ) use ( $assert ): void {
    try {
        $call();
    } catch ( RuntimeException | InvalidArgumentException $error ) {
        $assert( true, $label );
        return;
    }
    throw new RuntimeException( 'Expected rejection: ' . $label );
};
$result = $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $changes() ),
    $response( $snapshot( array( $stock ), 'inventory', '', 10 ) ) ) )->inventory_state( 100 );
$assert( $result['source_ids'] === array( 100 ) && $result['inventory'][0]['stock_quantity'] === 7, 'Simple stock is returned without catalog changes.' );
$assert( $result['generation'] === $generation && $result['cursor'] === 8, 'Initial journal checkpoint is returned.' );
$variable = array_replace( $parent, array( 'type' => 'variable', 'variations' => range( 101, 301 ) ) );
$responses = array( $response( $snapshot( array( $variable ) ) ), $response( $changes() ) );
foreach ( array_chunk( $variable['variations'], 100 ) as $batch ) {
    $responses[] = $response( $snapshot( array_map( static fn( $id ) => array_replace( $stock, array( 'id' => $id, 'stock_owner_id' => $id ) ), $batch ), 'inventory' ) );
}
$result = $reader( $responses )->inventory_state( 100 );
$assert( count( $result['inventory'] ) === 201 && count( $requests ) === 5, 'Variable inventory uses bounded 100/100/1 batches.' );
$assert( $result['source_ids'] === range( 101, 301 ), 'Variants retain source identities without duplicating parent stock.' );
$result = $reader( array( $response( $snapshot( array() ) ), $response( $changes() ) ) )->inventory_state( 100 );
$assert( ! $result['published'] && ! $result['source_ids'] && ! $result['inventory'] && count( $requests ) === 2, 'Withdrawal is explicit, not an authentication or transport fallback.' );
$result = $reader( array( $response( $snapshot( array( $parent ) ) ), $response( array( 'code' => 'resync_required' ), 409 ),
    $response( $changes() ), $response( $snapshot( array( $stock ), 'inventory' ) ) ) )
    ->inventory_state( 100, array( 'generation' => $generation, 'cursor' => 1 ) );
$assert( $result['resynced'] && $result['cursor'] === 8, 'Expired journal cursor rebuilds stock while retaining caller mappings.' );
$assert( $requests[1]['query']['after'] === '1' && $requests[2]['query']['after'] === '8', 'Only the verified snapshot cursor is used after 409.' );
$result = $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $changes() ),
    $response( $snapshot( array( $stock ), 'inventory' ) ) ) )
    ->inventory_state( 100, array( 'generation' => '00000000-0000-4000-8000-000000000002', 'cursor' => 900 ) );
$assert( $result['resynced'] && $requests[1]['query']['after'] === '8', 'A new generation resets only the feed checkpoint.' );
$events = array( array( 'id' => 777, 'cursor' => 9, 'action' => 'delete', 'product' => null ) );
$result = $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $changes( 9, $events, true ) ),
    $response( $changes( 10, array( array( 'id' => 778, 'cursor' => 10, 'action' => 'delete', 'product' => null ) ) ) ),
    $response( $snapshot( array( $stock ), 'inventory', '', 10 ) ) ) )->inventory_state( 100 );
$assert( $result['cursor'] === 10 && count( $requests ) === 4, 'Journal pages advance in order before inventory reconciliation.' );
$reject( static fn() => $reader( array( $response( array( 'code' => 'denied' ), 401 ) ) )->inventory_state( 100 ), 'Revoked authentication cannot look like withdrawal.' );
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $changes() ),
    $response( $snapshot( array( $stock, $stock ), 'inventory' ) ) ) )->inventory_state( 100 ), 'Duplicate inventory is rejected.' );
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $changes() ),
    $response( $snapshot( array( $stock ), 'inventory', '00000000-0000-4000-8000-000000000002' ) ) ) )->inventory_state( 100 ), 'Mixed generations cannot advance checkpoints.' );
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $changes() ),
    $response( $snapshot( array( $stock ), 'inventory', '', 7 ) ) ) )->inventory_state( 100 ), 'Stock snapshots older than the consumed journal are rejected.' );
$reject( static fn() => $reader( array() )->inventory_state( 100, array( 'generation' => $generation, 'cursor' => -1 ) ), 'Invalid checkpoints fail before transport.' );

$second = array_replace( $parent, array( 'id' => 200 ) );
$second_stock = array_replace( $stock, array( 'id' => 200, 'stock_owner_id' => 200, 'stock_quantity' => null ) );
$result = $reader( array( $response( $snapshot( array( $second, $parent ) ) ), $response( $changes() ),
    $response( $snapshot( array( $second_stock, $stock ), 'inventory' ) ) ) )->inventory_states( array( 100, 200 ) );
$assert( count( $requests ) === 3 && $requests[0]['query']['ids'] === array( '100', '200' )
    && $requests[2]['query']['ids'] === array( '200', '100' ), 'Multiple parents share parent, journal, and inventory requests.' );
$assert( array_keys( $result ) === array( 'results' ) && array_column( $result['results'], 'parent_id' ) === array( 100, 200 )
    && array_keys( $result['results'][0] ) === array( 'generation', 'cursor', 'parent_id', 'published', 'source_ids', 'inventory', 'resynced' ),
    'Batch results retain the single-product contract and requested parent order.' );
$assert( $result['results'][1]['inventory'][0]['stock_quantity'] === null, 'Unknown stock is not converted to supplier zero.' );

$checkpoints = array( 100 => array( 'generation' => $generation, 'cursor' => 5 ),
    200 => array( 'generation' => $generation, 'cursor' => 7 ) );
$rows = array();
foreach ( range( 6, 8 ) as $cursor ) {
    $rows[] = array( 'id' => 777, 'cursor' => $cursor, 'action' => 'delete', 'product' => null );
}
$result = $reader( array( $response( $snapshot( array( $parent, $second ) ) ), $response( $changes( 8, $rows ) ),
    $response( $snapshot( array( $stock, $second_stock ), 'inventory' ) ) ) )->inventory_states( array( 100, 200 ), $checkpoints );
$assert( $requests[1]['query']['after'] === '5' && count( $requests ) === 3
    && array_column( $result['results'], 'cursor' ) === array( 8, 8 )
    && array_column( $result['results'], 'resynced' ) === array( false, false ), 'Differing checkpoints replay only once from the oldest current cursor.' );

$checkpoints[200]['cursor'] = 8;
$result = $reader( array( $response( $snapshot( array( $parent, $second ) ) ), $response( array(), 409 ),
    $response( $changes() ), $response( $snapshot( array( $stock, $second_stock ), 'inventory' ) ) ) )
    ->inventory_states( array( 100, 200, 300 ), $checkpoints );
$assert( array_column( $result['results'], 'resynced' ) === array( true, false, false )
    && $requests[2]['query']['after'] === '8', '409 resets older checkpoints, not fresh or uninitialized peers.' );
$assert( $result['results'][2] === array( 'generation' => $generation, 'cursor' => 8, 'parent_id' => 300,
    'published' => false, 'source_ids' => array(), 'inventory' => array(), 'resynced' => false ),
    'A withdrawn parent retains explicit empty inventory alongside published peers.' );

$checkpoints[100] = array( 'generation' => '00000000-0000-4000-8000-000000000002', 'cursor' => 900 );
$checkpoints[200]['cursor'] = 7;
$result = $reader( array( $response( $snapshot( array( $parent, $second ) ) ), $response( $changes( 8, array( end( $rows ) ) ) ),
    $response( $snapshot( array( $stock, $second_stock ), 'inventory' ) ) ) )->inventory_states( array( 100, 200 ), $checkpoints );
$assert( $requests[1]['query']['after'] === '7'
    && array_column( $result['results'], 'resynced' ) === array( true, false ), 'Previous-generation peers do not replace valid current checkpoints.' );
$checkpoints[100] = array( 'generation' => $generation, 'cursor' => 900 );
$result = $reader( array( $response( $snapshot( array( $parent, $second ) ) ), $response( $changes( 8, array( end( $rows ) ) ) ),
    $response( $snapshot( array( $stock, $second_stock ), 'inventory' ) ) ) )->inventory_states( array( 100, 200 ), $checkpoints );
$assert( $requests[1]['query']['after'] === '7'
    && array_column( $result['results'], 'resynced' ) === array( true, false ), 'Future cursors resync explicitly without skipping valid journal history.' );

$left = array_replace( $parent, array( 'type' => 'variable', 'variations' => array( 101, 102 ) ) );
$right = array_replace( $second, array( 'type' => 'variable', 'variations' => array( 102, 201 ) ) );
$shared = array_replace( $stock, array( 'id' => 102, 'stock_owner_id' => 100, 'stock_quantity' => null ) );
$result = $reader( array( $response( $snapshot( array( $left, $right ) ) ), $response( $changes() ),
    $response( $snapshot( array( $shared ), 'inventory' ) ) ) )->inventory_states( array( 100, 200 ) );
$assert( $requests[2]['query']['ids'] === array( '101', '102', '201' ) && count( $requests ) === 3
    && $result['results'][0]['inventory'] === array( $shared ) && $result['results'][1]['inventory'] === array( $shared ),
    'Shared source IDs are fetched once and shared stock owners remain intact; missing sources stay absent.' );

$left['variations'] = range( 1001, 1101 );
$right['variations'] = range( 2001, 2101 );
$responses = array( $response( $snapshot( array( $left, $right ) ) ), $response( $changes() ) );
foreach ( array_chunk( array_merge( $left['variations'], $right['variations'] ), 100 ) as $batch ) {
    $responses[] = $response( $snapshot( array_map( static fn( $id ) => array_replace( $stock, array( 'id' => $id ) ), $batch ), 'inventory' ) );
}
$result = $reader( $responses )->inventory_states( array( 100, 200 ) );
$assert( count( $requests ) === 5 && array_map( static fn( $entry ) => count( $entry['inventory'] ), $result['results'] ) === array( 101, 101 )
    && count( $requests[3]['query']['ids'] ) === 100 && count( $requests[4]['query']['ids'] ) === 2,
    'Cross-product flattening uses 100/100/2 rather than separate per-parent partial batches.' );

foreach ( array( array(), array( 100, 100 ), array( 0 ), array( -1 ), array( '100' ), array( 100.0 ),
    array( 1 => 100 ), range( 1, 51 ) ) as $invalid_ids ) {
    $reject( static fn() => $reader( array() )->inventory_states( $invalid_ids ), 'Invalid/distinct/list/size ID bounds fail before transport.' );
    $assert( ! $requests, 'Rejected IDs perform no HTTP reads.' );
}
foreach ( array( array( 999 => null ), array( 'other' => null ), array( 100 => false ),
    array( 100 => array( 'generation' => $generation, 'cursor' => 1, 'extra' => true ) ),
    array( 100 => array( 'generation' => $generation ) ), array( 100 => array( 'generation' => $generation, 'cursor' => '1' ) ),
    array( 100 => array( 'generation' => 'bad', 'cursor' => 1 ) ) ) as $invalid_checkpoints ) {
    $reject( static fn() => $reader( array() )->inventory_states( array( 100 ), $invalid_checkpoints ), 'Unknown or malformed checkpoint entries fail before transport.' );
    $assert( ! $requests, 'Rejected checkpoints perform no HTTP reads.' );
}
foreach ( array( array( $parent, $parent ), array( array_replace( $parent, array( 'id' => 999 ) ) ),
    array( array_replace( $parent, array( 'parent_id' => 99 ) ) ),
    array( array_replace( $left, array( 'variations' => array( 101, 101 ) ) ) ),
    array( array_replace( $left, array( 'variations' => array( '101' ) ) ) ),
    array( array_replace( $left, array( 'variations' => array( 100 ) ) ) ),
    array( array_replace( $left, array( 'variations' => range( 1001, 3049 ) ) ) ) ) as $invalid_parents ) {
    $reject( static fn() => $reader( array( $response( $snapshot( $invalid_parents ) ) ) )
        ->inventory_states( array( 100, 200 ) ), 'Malformed/duplicate/unrequested parents and variants fail explicitly, never as withdrawals.' );
}
$oversized = array();
foreach ( range( 1, 6 ) as $id ) {
    $oversized[] = array_replace( $left, array( 'id' => $id, 'variations' => range( 1001, 3048 ) ) );
}
$reject( static fn() => $reader( array( $response( $snapshot( $oversized ) ) ) )->inventory_states( range( 1, 6 ) ),
    'Total per-product source fanout is capped at 10240 even for shared sources.' );
$parent_page = $snapshot( array( $parent, $second ) );
$parent_page['has_more'] = true;
$reject( static fn() => $reader( array( $response( $parent_page ) ) )->inventory_states( array( 100, 200 ) ), 'Parent pagination cannot silently omit products.' );
foreach ( array( array( $stock, $stock ), array( array_replace( $stock, array( 'id' => 999 ) ) ),
    array( array_replace( $stock, array( 'stock_quantity' => '7' ) ) ),
    array( array_replace( $stock, array( 'stock_owner_id' => 0 ) ) ),
    array( array_replace( $stock, array( 'manage_stock' => 'yes' ) ) ),
    array( array_diff_key( $stock, array( 'stock_quantity' => true ) ) ) ) as $invalid_stock ) {
    $reject( static fn() => $reader( array( $response( $snapshot( array( $parent, $second ) ) ), $response( $changes() ),
        $response( $snapshot( $invalid_stock, 'inventory' ) ) ) )->inventory_states( array( 100, 200 ) ),
        'Malformed/duplicate/unrequested stock rejects the entire batch.' );
}
$stock_page = $snapshot( array( $stock ), 'inventory' );
$stock_page['has_more'] = true;
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent, $second ) ) ), $response( $changes() ),
    $response( $stock_page ) ) )->inventory_states( array( 100, 200 ) ), 'Incomplete inventory pages reject the entire batch.' );
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent ) ) ), $response( array(), 409 ), $response( array(), 409 ) ) )
    ->inventory_states( array( 100 ) ), 'Repeated 409 is not an unbounded retry or withdrawal fallback.' );
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent ) ) ),
    $response( $changes( 8, array_fill( 0, 101, end( $rows ) ) ) ) ) )->inventory_states( array( 100 ) ), 'Oversized journal pages reject the batch.' );
$bad_changes = $changes();
$bad_changes['generation'] = '00000000-0000-4000-8000-000000000002';
$reject( static fn() => $reader( array( $response( $snapshot( array( $parent ) ) ), $response( $bad_changes ) ) )
    ->inventory_states( array( 100 ) ), 'Journal generation changes cannot advance a batch checkpoint.' );
$result = $reader( array( $response( $snapshot( array() ) ), $response( $changes() ) ) )
    ->inventory_states( range( 1, 50 ), array_fill_keys( range( 1, 50 ), null ) );
$assert( count( $result['results'] ) === 50 && count( $requests ) === 2
    && array_column( $result['results'], 'published' ) === array_fill( 0, 50, false ),
    'Maximum parent batch remains bounded and preserves all withdrawn results.' );
$maximum = array();
foreach ( range( 1, 5 ) as $id ) {
    $maximum[] = array_replace( $left, array( 'id' => $id, 'variations' => range( $id * 3000, $id * 3000 + 2047 ) ) );
}
$responses = array( $response( $snapshot( $maximum ) ), $response( $changes() ) );
foreach ( array_chunk( array_merge( ...array_column( $maximum, 'variations' ) ), 100 ) as $batch ) {
    $responses[] = $response( $snapshot( array(), 'inventory' ) );
}
$result = $reader( $responses )->inventory_states( range( 1, 5 ) );
$assert( count( $requests ) === 105 && array_map( static fn( $entry ) => count( $entry['source_ids'] ), $result['results'] ) === array_fill( 0, 5, 2048 ),
    'The exact 2048-per-parent and 10240-total source limits are supported.' );
$responses = array( $response( $snapshot( array( $parent ) ) ) );
foreach ( range( 9, 108 ) as $cursor ) {
    $responses[] = $response( $changes( $cursor, array( array( 'id' => 777, 'cursor' => $cursor, 'action' => 'delete', 'product' => null ) ), true ) );
}
$reject( static fn() => $reader( $responses )->inventory_states( array( 100 ) ), 'Journal replay exceeding 100 pages fails rather than returning a partial checkpoint.' );
$assert( count( $requests ) === 101, 'Journal replay bound includes exactly one parent lookup and 100 journal pages.' );
echo "$checks app inventory feed assertions passed.\n";
