<?php
/**
 * Configure existing dev catalog products and an isolated test retailer.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'UltraLove_Shopify_Supplier' )
    || ! UltraLove_Shopify_Supplier::supported() ) {
    exit( 1 );
}
if ( UltraLove_Shopify_Supplier::retired() ) {
    WP_CLI::error( 'The legacy development Shopify connector is retired; setup cannot reactivate it.' );
}
$retailer = get_user_by( 'login', 'shopify-dev-retailer' );
if ( $retailer && get_user_meta( $retailer->ID, '_ul_shopify_dev_fixture', true ) !== 'yes' ) {
    WP_CLI::error( 'The chosen test login belongs to an unrelated user; refusing to modify it.' );
}
if ( ! $retailer ) {
    $id = wp_insert_user( array(
        'user_login' => 'shopify-dev-retailer', 'user_pass' => wp_generate_password( 40, true, true ),
        'user_email' => 'retailer@example.invalid', 'display_name' => 'Shopify integration test retailer',
        'role' => 'customer',
    ) );
    if ( is_wp_error( $id ) ) {
        WP_CLI::error( 'Unable to create test retailer: ' . $id->get_error_code() );
    }
    update_user_meta( $id, '_ul_shopify_dev_fixture', 'yes' );
    $retailer = get_user_by( 'id', $id );
}
$customer = new WC_Customer( $retailer->ID );
$customer->set_props( array(
    'billing_first_name' => 'Test', 'billing_last_name' => 'Retailer', 'billing_email' => 'retailer@example.invalid',
    'billing_address_1' => '100 Retailer Test Street', 'billing_city' => 'Vancouver',
    'billing_country' => 'CA', 'billing_state' => 'BC', 'billing_postcode' => 'V6B 1A1',
) );
$customer->save();
update_user_meta( $retailer->ID, UltraLove_Dropshipping::APPROVAL, 'yes' );
$settings = UltraLove_Shopify_Supplier::validate( array(
    'enabled' => true, 'product_ids' => array( 17186, 17188, 17192 ),
    'retailer_id' => $retailer->ID, 'markup' => 0,
) );
update_option( UltraLove_Shopify_Supplier::OPTION, $settings, false );
WP_CLI::success( 'Dev supplier configured with existing products 17186, 17188, 17192; no products created.' );
