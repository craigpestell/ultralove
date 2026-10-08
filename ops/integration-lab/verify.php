<?php
/**
 * Run with wp eval-file on the dedicated integration lab only.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 1 );
}

if ( trim( (string) file_get_contents( '/etc/ultralove-integration-lab' ) ) !== 'ultralove-integration-lab'
    || ! str_starts_with( home_url(), 'https://ultralove-lab.' ) ) {
    WP_CLI::error( 'Refusing to verify outside the dedicated integration lab.' );
}

$checks = array(
    'PHP 8.5 runtime' => PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600,
    'development environment' => wp_get_environment_type() === 'development',
    'search indexing disabled' => (string) get_option( 'blog_public' ) === '0',
    'registration disabled' => (string) get_option( 'users_can_register' ) === '0',
    'CAD supplier currency' => get_woocommerce_currency() === 'CAD',
    'local database' => DB_HOST === 'localhost' && DB_NAME === 'integration_lab',
    'system-managed cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
    'no enabled payment gateways' => count( WC()->payment_gateways()->get_available_payment_gateways() ) === 0,
    'no orders created' => count( wc_get_orders( array( 'limit' => 1, 'return' => 'ids' ) ) ) === 0,
    'guest checkout disabled' => get_option( 'woocommerce_enable_guest_checkout' ) === 'no',
);

foreach ( array( 'LAB-SIMPLE-001' => 10, 'LAB-SIMPLE-002' => 5 ) as $sku => $quantity ) {
    $product = wc_get_product( wc_get_product_id_by_sku( $sku ) );
    $checks[ 'synthetic inventory ' . $sku ] = $product
        && $product->get_manage_stock()
        && $product->get_stock_quantity() === $quantity;
}

$mailer_initialized = false;
add_action( 'phpmailer_init', function () use ( &$mailer_initialized ) {
    $mailer_initialized = true;
} );
$checks['email suppressed before mailer initialization'] = wp_mail(
    'synthetic-recipient@example.invalid',
    'Integration lab suppression check',
    'Synthetic verification message; must not leave the lab.'
) && ! $mailer_initialized;

foreach ( $checks as $label => $passed ) {
    if ( ! $passed ) {
        WP_CLI::error( 'Failed: ' . $label );
    }
    WP_CLI::log( 'PASS: ' . $label );
}
WP_CLI::success( 'Integration lab baseline verified; no orders or payments created.' );
