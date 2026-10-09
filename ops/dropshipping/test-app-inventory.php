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
echo "$checks app inventory feed assertions passed.\n";
