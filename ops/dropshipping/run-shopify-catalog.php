<?php
/**
 * Existing Shopify app: authenticated WooCommerce feed -> Shopify catalog.
 * No WordPress bootstrap, database connection, options or postmeta writes.
 */
if ( PHP_SAPI !== 'cli' || defined( 'ABSPATH' ) || posix_geteuid() !== 0 ) {
    throw new RuntimeException( 'Run directly as root with PHP CLI, outside WordPress.' );
}
umask( 0077 );

try {
    $mode = 'browse';
    $ids = array();
    $filters = array();
    $mode_seen = false;
    foreach ( array_slice( $argv, 1 ) as $argument ) {
        if ( in_array( $argument, array( '--browse', '--categories' ), true ) ) {
            if ( $mode_seen ) { throw new InvalidArgumentException( 'Choose exactly one app operation.' ); }
            $mode = substr( $argument, 2 );
            $mode_seen = true;
        } elseif ( preg_match( '/^--(import|cleanup)=(\d+(?:,\d+)*)$/D', $argument, $match ) ) {
            if ( $mode_seen ) { throw new InvalidArgumentException( 'Choose exactly one app operation.' ); }
            $mode = $match[1];
            $mode_seen = true;
            $ids = array_map( 'intval', explode( ',', $match[2] ) );
            if ( count( $ids ) > 50 || count( array_unique( $ids ) ) !== count( $ids ) || in_array( 0, $ids, true ) ) {
                throw new InvalidArgumentException( 'Select at most 50 distinct positive product IDs.' );
            }
            sort( $ids );
        } elseif ( preg_match( '/^--(after-id|category-id|per-page)=(\d+)$/D', $argument, $match ) ) {
            $filters[str_replace( '-', '_', $match[1] )] = (int) $match[2];
        } elseif ( str_starts_with( $argument, '--search=' ) ) {
            $filters['search'] = substr( $argument, 9 );
        } else {
            throw new InvalidArgumentException( 'Usage: php run-shopify-catalog.php [--browse|--categories|--import=ID,ID|--cleanup=ID,ID] [--after-id=N --per-page=N --category-id=N --search=TEXT]' );
        }
    }
    $cleanup = $mode === 'cleanup';
    if ( $ids && $filters ) { throw new InvalidArgumentException( 'Browse filters cannot change an explicit import/cleanup selection.' ); }
    if ( $mode === 'categories' && array_diff( array_keys( $filters ), array( 'after_id', 'per_page' ) ) ) {
        throw new InvalidArgumentException( 'Category navigation only accepts pagination filters.' );
    }
    $includes = '/var/www/html/wp-content/plugins/ultralove-shopify-supplier/includes/';
    require $includes . 'class-feed-client.php';
    require $includes . 'class-catalog-import.php';
    $pages = 0;
    $queries = 0;
    $transport = static function ( string $url, array $arguments ) use ( &$pages, &$queries ): array {
        $response = UltraLove_Shopify_Feed_Client::catalog_transport( $url, $arguments );
        if ( $response['status'] === 200 ) {
            ++$pages;
            $queries += (int) ( $response['headers']['x-ultralove-catalog-selects'] ?? 0 );
        }
        return $response;
    };
    $credentials = json_decode( UltraLove_Shopify_Catalog_Import::private_file(
        '/root/.ultralove-shopify-dev/feed-client.json' ), true, 512, JSON_THROW_ON_ERROR );
    $client = new UltraLove_Shopify_Feed_Client( $credentials, $transport );
    if ( $mode === 'browse' || $mode === 'categories' ) {
        $page = $mode === 'browse' ? $client->products_page( $filters ) : $client->categories_page( $filters );
        echo json_encode( $page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
        exit( 0 );
    }
    $directory = '/root/.ultralove-shopify-dev/catalog-' . substr( hash( 'sha256', implode( ',', $ids ) ), 0, 16 );
    if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700 ) ) {
        throw new RuntimeException( 'Could not create root-only app catalog directory.' );
    }
    if ( fileowner( $directory ) !== 0 || ( fileperms( $directory ) & 0077 ) !== 0 || is_link( $directory ) ) {
        throw new RuntimeException( 'App catalog directory must be root-owned, private and not a symlink.' );
    }
    $lock = fopen( $directory . '/feed.lock', 'c' );
    if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
        throw new RuntimeException( 'Another catalog app worker is running.' );
    }
    $checkpoint = $directory . '/feed-checkpoint.json';
    if ( ! is_file( $checkpoint ) ) {
        if ( $cleanup ) {
            throw new RuntimeException( 'Cleanup requires the original feed checkpoint and ownership ledger.' );
        }
        $feed = $client->selected_products( $ids );
        $feed['read_evidence'] = array( 'pages' => $pages, 'guarded_selects' => $queries, 'database_writes' => 0, 'fetched_at' => gmdate( 'c' ) );
        $json = json_encode( $feed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
        if ( file_put_contents( $checkpoint . '.tmp', $json, LOCK_EX ) !== strlen( $json )
            || ! rename( $checkpoint . '.tmp', $checkpoint ) ) {
            throw new RuntimeException( 'Could not persist the app feed checkpoint.' );
        }
        echo 'Existing app fetched ' . count( $feed['products'] ) . ' selected supplier records from the product feed; '
            . $pages . " authenticated pages; no WordPress database writes.\n";
    }
    $json = UltraLove_Shopify_Catalog_Import::private_file( $checkpoint );
    $feed = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
    $app = new UltraLove_Shopify_Catalog_Import( $directory, hash( 'sha256', $json ) );
    if ( $cleanup ) {
        $app->cleanup();
    } else {
        $app->run( $feed );
    }
} catch ( Throwable $error ) {
    fwrite( STDERR, 'Catalog app failed: ' . $error->getMessage() . "\n" );
    exit( 1 );
}
