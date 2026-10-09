<?php
/**
 * Provision a separate, revocable feed credential for the root-only dev worker.
 * wp eval-file - --allow-root < ops/dropshipping/setup-shopify-feed.php
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'development'
    || home_url() !== 'https://dev.ultralove.ca' || DB_NAME !== 'wordpress_dev'
    || ! class_exists( 'UltraLove_Product_Feed' ) || ! UltraLove_Product_Feed::supported() ) {
    throw new RuntimeException( 'Run only on the development supplier with the feed active.' );
}
$directory = '/root/.ultralove-shopify-dev/';
$path = $directory . 'feed-client.json';
if ( ! is_dir( $directory ) || fileowner( $directory ) !== 0 || ( fileperms( $directory ) & 0077 ) !== 0 ) {
    throw new RuntimeException( 'The existing root-only Shopify worker directory is required.' );
}
$login = 'shopify-feed-consumer';
$user = get_user_by( 'login', $login );
if ( $user && get_user_meta( $user->ID, '_ul_shopify_feed_fixture', true ) !== 'yes' ) {
    throw new RuntimeException( 'Refusing to reuse an unmarked account.' );
}
if ( ! $user ) {
    $id = wp_insert_user( array(
        'user_login' => $login, 'user_pass' => wp_generate_password( 64, true, true ),
        'user_email' => 'shopify-feed-consumer@example.invalid', 'role' => 'customer',
    ) );
    if ( is_wp_error( $id ) ) {
        throw new RuntimeException( 'Cannot create feed fixture account: ' . $id->get_error_code() );
    }
    update_user_meta( $id, '_ul_shopify_feed_fixture', 'yes' );
    $user = get_user_by( 'id', $id );
}
$user->add_cap( UltraLove_Product_Feed::CAPABILITY );
if ( file_exists( $path ) ) {
    if ( fileowner( $path ) !== 0 || ( fileperms( $path ) & 0077 ) !== 0 ) {
        throw new RuntimeException( 'Existing feed credential file is not root-only.' );
    }
    $saved = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
    if ( ( $saved['user_id'] ?? null ) !== $user->ID || ( $saved['username'] ?? null ) !== $login
        || ! is_string( $saved['password'] ?? null ) || trim( $saved['password'] ) === ''
        || ! WP_Application_Passwords::get_user_application_password( $user->ID, $saved['uuid'] ?? '' ) ) {
        throw new RuntimeException( 'Existing feed credential is invalid or revoked; explicitly remove the obsolete root file before reprovisioning.' );
    }
    WP_CLI::success( 'Dedicated dev feed credential already provisioned; no password rotated.' );
    return;
}
$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'UltraLove dev Shopify feed consumer' ) );
if ( is_wp_error( $created ) ) {
    throw new RuntimeException( 'Cannot create scoped application password: ' . $created->get_error_code() );
}
list( $password, $record ) = $created;
$data = wp_json_encode( array( 'user_id' => $user->ID, 'username' => $login, 'password' => $password, 'uuid' => $record['uuid'] ) );
$temporary = $path . '.tmp';
$persisted = false;
$mask = umask( 0077 );
try {
    if ( file_exists( $temporary ) || file_put_contents( $temporary, $data, LOCK_EX ) !== strlen( $data )
        || ! chmod( $temporary, 0600 ) || ! rename( $temporary, $path ) ) {
        throw new RuntimeException( 'Could not securely persist the dev feed credential.' );
    }
    $persisted = true;
} finally {
    umask( $mask );
    if ( ! $persisted ) {
        WP_Application_Passwords::delete_application_password( $user->ID, $record['uuid'] );
    }
}
WP_CLI::success( 'Dedicated read-only dev feed credential provisioned outside WordPress; password not displayed.' );
