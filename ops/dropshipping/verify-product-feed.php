<?php
/**
 * Guarded dev fixture/control helper for the HTTP verifier. Creates no products.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'UltraLove_Product_Feed' ) || ! UltraLove_Product_Feed::supported() ) {
    exit( 1 );
}
$directory = '/root/.ultralove-product-feed/';
$mode = $args[0] ?? '';
global $wpdb;
$save = static function ( string $file, array $data ) use ( $directory ): void {
    if ( file_put_contents( $directory . $file, wp_json_encode( $data, JSON_THROW_ON_ERROR ) ) === false || ! chmod( $directory . $file, 0600 ) ) {
        WP_CLI::error( 'Could not store root-only feed verifier state.' );
    }
};
$result = array();
if ( $mode === 'setup' ) {
    $retailer = get_user_by( 'login', 'shopify-dev-retailer' );
    if ( ! $retailer || get_user_meta( $retailer->ID, '_ul_shopify_dev_fixture', true ) !== 'yes' || ! in_array( 'customer', $retailer->roles, true ) ) {
        WP_CLI::error( 'The dedicated customer-role dev retailer is required.' );
    }
    $retailer->add_cap( UltraLove_Product_Feed::CAPABILITY );
    $denied = get_user_by( 'login', 'feed-denied-retailer' );
    if ( $denied && get_user_meta( $denied->ID, '_ul_feed_fixture', true ) !== 'yes' ) {
        WP_CLI::error( 'Refusing to modify an unrelated customer account.' );
    }
    if ( ! $denied ) {
        $id = wp_insert_user( array(
            'user_login' => 'feed-denied-retailer', 'user_pass' => wp_generate_password( 40, true, true ),
            'user_email' => 'feed-denied@example.invalid', 'role' => 'customer',
        ) );
        if ( is_wp_error( $id ) ) {
            WP_CLI::error( 'Could not create denied test account: ' . $id->get_error_code() );
        }
        update_user_meta( $id, '_ul_feed_fixture', 'yes' );
        $denied = get_user_by( 'id', $id );
    }
    foreach ( array( 'client.json' => $retailer, 'denied.json' => $denied ) as $file => $user ) {
        foreach ( WP_Application_Passwords::get_user_application_passwords( $user->ID ) as $old ) {
            if ( $old['app_id'] === '8c27a7cf-a47b-491d-b2c8-856abf5e8391' ) {
                WP_Application_Passwords::delete_application_password( $user->ID, $old['uuid'] );
            }
        }
        $password = WP_Application_Passwords::create_new_application_password( $user->ID, array(
            'name' => 'UltraLove feed development verification', 'app_id' => '8c27a7cf-a47b-491d-b2c8-856abf5e8391',
        ) );
        if ( is_wp_error( $password ) ) {
            WP_CLI::error( 'Could not create test application password: ' . $password->get_error_code() );
        }
        $save( $file, array( 'user_id' => $user->ID, 'username' => $user->user_login, 'password' => $password[0], 'uuid' => $password[1]['uuid'] ) );
    }
    $ids = UltraLove_Product_Feed::catalog_ids( 0, 100000 );
    $target = null;
    foreach ( $ids as $id ) {
        if ( in_array( $id, array( 17186, 17188, 17192 ), true ) ) { continue; }
        $product = wc_get_product( $id );
        if ( $product && $product->is_type( 'simple' ) && $product->get_manage_stock() && $product->get_stock_quantity() > 2 ) {
            $target = $product;
            break;
        }
    }
    if ( ! $target ) { WP_CLI::error( 'No existing non-pilot product is suitable for stock verification.' ); }
    $csv = array();
    foreach ( array( 'ultralove-product-export.csv', 'ultralove-product-export-shopify.csv' ) as $name ) {
        $path = trailingslashit( wp_upload_dir()['basedir'] ) . $name;
        if ( ! is_readable( $path ) ) { WP_CLI::error( 'Existing CSV file is unavailable: ' . $name ); }
        $csv[$name] = hash_file( 'sha256', $path );
    }
    $fixture = array(
        'id' => $target->get_id(), 'stock' => $target->get_stock_quantity(),
        'stock_status' => $target->get_stock_status(), 'status' => $target->get_status(),
        'ids' => $ids, 'csv' => $csv,
    );
    $save( 'fixture.json', $fixture );
    $result = array( 'products' => count( $ids ), 'stock_product' => $target->get_id() );
} else {
    $fixture = json_decode( file_get_contents( $directory . 'fixture.json' ), true, 512, JSON_THROW_ON_ERROR );
    $id = (int) $fixture['id'];
    $product = wc_get_product( $id );
    if ( ! $product ) { WP_CLI::error( 'Existing verifier product disappeared.' ); }
    if ( in_array( $mode, array( 'stock', 'zero', 'restore' ), true ) ) {
        $quantity = $mode === 'zero' ? 0 : $fixture['stock'] - ( $mode === 'stock' ? 1 : 0 );
        wc_update_product_stock( $product, $quantity, 'set' );
        $product = wc_get_product( $id );
        $product->set_stock_status( $mode === 'zero' ? 'outofstock' : $fixture['stock_status'] );
        $product->set_status( $fixture['status'] );
        $product->save();
        $result = array( 'quantity' => $quantity );
    } elseif ( $mode === 'unpublish' ) {
        $product->set_status( 'draft' );
        $product->save();
    } elseif ( $mode === 'raw' || $mode === 'seed' ) {
        $before = get_option( 'ultralove_feed_reconcile_after', 0 );
        if ( $mode === 'raw' ) {
            $updated = $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $fixture['stock'] - 2 ), array( 'post_id' => $id, 'meta_key' => '_stock' ) );
            if ( $updated === false ) { WP_CLI::error( 'Raw stock test update failed.' ); }
        }
        update_option( 'ultralove_feed_reconcile_after', $id - 1, false );
        try { UltraLove_Feed_Journal::reconcile( 1 ); }
        finally { update_option( 'ultralove_feed_reconcile_after', $before, false ); }
    } elseif ( $mode === 'enqueue' || $mode === 'producer' ) {
        if ( $mode === 'producer' ) {
            $wpdb->query( 'START TRANSACTION' );
            UltraLove_Feed_Journal::enqueue( $id );
            file_put_contents( $directory . 'producer-ready', 'ready' );
            chmod( $directory . 'producer-ready', 0600 );
            sleep( 8 );
            $wpdb->query( 'COMMIT' );
        } else {
            UltraLove_Feed_Journal::enqueue( 17188 );
        }
    } elseif ( $mode === 'expire' ) {
        $table = $wpdb->prefix . 'ultralove_feed_changes';
        $first = (int) $wpdb->get_var( "SELECT MIN(seq) FROM $table" );
        if ( ! $first ) { WP_CLI::error( 'No journal prefix available for dev retention verification.' ); }
        $wpdb->query( $wpdb->prepare( "UPDATE $table SET published_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 8 DAY) WHERE seq=%d", $first ) );
        $before = get_option( 'ultralove_feed_reconcile_after', 0 );
        update_option( 'ultralove_feed_reconcile_after', $id - 1, false );
        try { UltraLove_Feed_Journal::reconcile( 1 ); }
        finally { update_option( 'ultralove_feed_reconcile_after', $before, false ); }
        $result = array( 'floor' => UltraLove_Feed_Journal::floor() );
    } elseif ( $mode === 'rate' || $mode === 'rate-reset' ) {
        $client = json_decode( file_get_contents( $directory . 'client.json' ), true, 512, JSON_THROW_ON_ERROR );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ultralove_feed_rates SET requests=%d,minute=%d WHERE user_id=%d",
            $mode === 'rate' ? UltraLove_Product_Feed::REQUESTS_PER_MINUTE : 0, (int) floor( time() / 60 ), $client['user_id']
        ) );
    } elseif ( $mode === 'csv' ) {
        foreach ( $fixture['csv'] as $name => $hash ) {
            $current = hash_file( 'sha256', trailingslashit( wp_upload_dir()['basedir'] ) . $name );
            if ( $current !== $hash ) { WP_CLI::error( 'CSV changed during read-only API verification: ' . $name ); }
        }
    } elseif ( $mode === 'cleanup' ) {
        $denied = json_decode( file_get_contents( $directory . 'denied.json' ), true, 512, JSON_THROW_ON_ERROR );
        if ( ! WP_Application_Passwords::delete_application_password( $denied['user_id'], $denied['uuid'] ) ) {
            WP_CLI::error( 'Could not revoke own denied test credential.' );
        }
        unlink( $directory . 'denied.json' );
        if ( is_file( $directory . 'producer-ready' ) ) { unlink( $directory . 'producer-ready' ); }
    } else {
        WP_CLI::error( 'Unknown feed verifier control mode.' );
    }
    UltraLove_Feed_Journal::check_database();
}
echo 'FEED-RESULT:' . wp_json_encode( $result ) . "\n";
