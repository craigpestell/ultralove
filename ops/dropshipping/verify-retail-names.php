<?php
/**
 * Read-only dev title audit and authenticated feed/import-plan verification.
 * Run: wp --allow-root eval-file - < ops/dropshipping/verify-retail-names.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'UltraLove_Product_Feed' )
    || ! UltraLove_Product_Feed::supported() ) {
    throw new RuntimeException( 'Run through WP-CLI on the development supplier only.' );
}
require_once ABSPATH . 'wp-content/plugins/ultralove-shopify-supplier/includes/class-feed-client.php';
require_once ABSPATH . 'wp-content/plugins/ultralove-shopify-supplier/includes/class-catalog-import.php';
global $wpdb;
$query = "SELECT ID,post_title FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID";
$titles = $wpdb->get_results( $query, ARRAY_A );
UltraLove_Feed_Journal::check_database();
$selected = array();
$changed = 0;
$preserved = 0;
$tester = null;
foreach ( $titles as $row ) {
    $original = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ' ', $row['post_title'] ) );
    $clean = UltraLove_Product_Feed::retail_name( $row['post_title'] );
    if ( $clean !== $original ) {
        ++$changed;
        if ( $tester === null && stripos( $row['post_title'], '3 UNITS BOUGHT' ) !== false ) {
            $tester = (int) $row['ID'];
        }
        if ( count( $selected ) < 8 ) {
            $selected[] = (int) $row['ID'];
        }
    } elseif ( stripos( $row['post_title'], '<b' ) !== false ) {
        ++$preserved;
    }
}
if ( ! $changed || ! $preserved ) {
    throw new RuntimeException( 'Existing catalog must contain both removable and preserved bold titles.' );
}
if ( $tester !== null && ! in_array( $tester, $selected, true ) ) {
    $selected[] = $tester;
}
foreach ( $titles as $row ) {
    if ( stripos( $row['post_title'], '<b' ) !== false
        && UltraLove_Product_Feed::retail_name( $row['post_title'] )
            === wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ' ', $row['post_title'] ) ) ) {
        $selected[] = (int) $row['ID'];
        break;
    }
}
$credentials = json_decode( UltraLove_Shopify_Catalog_Import::private_file(
    '/root/.ultralove-shopify-dev/feed-client.json' ), true, 512, JSON_THROW_ON_ERROR );
$client = new UltraLove_Shopify_Feed_Client( $credentials, array( UltraLove_Shopify_Feed_Client::class, 'catalog_transport' ) );
$feed = $client->selected_products( $selected );
$checks = 0;
foreach ( $selected as $id ) {
    $record = $feed['products'][$id];
    $product = wc_get_product( $id );
    if ( ! $product ) {
        throw new RuntimeException( 'Existing verification product disappeared.' );
    }
    $original = $product->get_name();
    if ( $record['name'] !== wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ' ', $original ) )
        || $record['retail_name'] !== UltraLove_Product_Feed::retail_name( $original ) ) {
        throw new RuntimeException( 'Authenticated feed title mismatch for supplier ' . $id );
    }
    $payload = UltraLove_Shopify_Catalog_Import::payload( $record, $feed['products'],
        'gid://shopify/Location/1', str_repeat( 'a', 32 ) );
    if ( $payload['title'] !== $record['retail_name'] || $payload['status'] !== 'DRAFT' ) {
        throw new RuntimeException( 'Import-plan title/status mismatch for supplier ' . $id );
    }
    $checks += 2;
}
$after = $wpdb->get_results( $query, ARRAY_A );
UltraLove_Feed_Journal::check_database();
if ( $titles !== $after ) {
    throw new RuntimeException( 'Stored product titles changed during read-only verification.' );
}
WP_CLI::success( sprintf(
    '%d feed/import-plan checks; %d published parents audited, %d cleaned, %d bold titles preserved; source titles unchanged. No Shopify requests or mutations.',
    $checks, count( $titles ), $changed, $preserved
) );
