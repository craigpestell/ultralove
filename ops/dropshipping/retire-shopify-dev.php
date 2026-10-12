<?php
/**
 * Retire only the legacy development bridge; retain shared feed code and all data.
 * Run with wp eval-file outside the web root; pass dry-run to inspect first.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'UltraLove_Shopify_Supplier' )
    || ! method_exists( 'UltraLove_Shopify_Supplier', 'retired' )
    || ! UltraLove_Shopify_Supplier::supported() || gethostname() !== 'ultralove-dev-managed'
    || ! function_exists( 'posix_geteuid' ) || posix_geteuid() !== 0 ) {
    throw new RuntimeException( 'Retirement requires root WP-CLI on the verified development supplier.' );
}
if ( count( $args ) > 1 || ( $args && $args[0] !== 'dry-run' ) ) {
    WP_CLI::error( 'Pass only the optional dry-run argument.' );
}
$cron = '/etc/cron.d/ultralove-shopify-dev';
$directory = '/root/.ultralove-shopify-dev';
if ( ! is_dir( $directory ) || is_link( $directory ) || fileowner( $directory ) !== 0
    || ( fileperms( $directory ) & 0777 ) !== 0700 || is_link( $cron ) ) {
    WP_CLI::error( 'Legacy worker paths must have safe root-only ownership and permissions.' );
}
$settings = get_option( UltraLove_Shopify_Supplier::OPTION, array() );
if ( ! is_array( $settings ) ) {
    WP_CLI::error( 'Legacy settings are malformed; operator review is required before retirement.' );
}
WP_CLI::log( 'Target: legacy development bridge only; shared catalog proxy and Shopify app remain active.' );
WP_CLI::log( 'Legacy cron: ' . ( file_exists( $cron ) ? 'present' : 'absent' )
    . '; retirement guard: ' . ( UltraLove_Shopify_Supplier::retired() ? 'set' : 'not set' ) . '.' );
if ( $args ) {
    WP_CLI::success( 'Dry run: no options, files, products, orders or credentials changed.' );
    return;
}
$old_umask = umask( 0077 );
$backup = $directory . '/retirement-' . gmdate( 'Ymd\THis\Z' ) . '-' . bin2hex( random_bytes( 4 ) );
try {
    if ( ! mkdir( $backup, 0700 ) ) {
        throw new RuntimeException( 'Cannot create the protected retirement backup.' );
    }
    $snapshot = array();
    foreach ( array( UltraLove_Shopify_Supplier::OPTION, UltraLove_Shopify_Supplier::RETIRED_OPTION ) as $option ) {
        $snapshot[$option] = get_option( $option, null );
    }
    $encoded = json_encode( $snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
    if ( file_put_contents( $backup . '/options.json', $encoded, LOCK_EX ) !== strlen( $encoded )
        || ! chmod( $backup . '/options.json', 0600 ) ) {
        throw new RuntimeException( 'Cannot persist the protected legacy state backup.' );
    }
    if ( file_exists( $cron ) ) {
        if ( ! is_file( $cron ) || fileowner( $cron ) !== 0
            || ! rename( $cron, $backup . '/ultralove-shopify-dev.cron' )
            || ! chmod( $backup . '/ultralove-shopify-dev.cron', 0600 ) ) {
            throw new RuntimeException( 'Cannot archive the legacy cron entry; inspect the protected backup before retrying.' );
        }
    }
    $disabled = array_replace( $settings, array( 'enabled' => false ) );
    update_option( UltraLove_Shopify_Supplier::OPTION, $disabled, false );
    update_option( UltraLove_Shopify_Supplier::RETIRED_OPTION, true, false );
    if ( get_option( UltraLove_Shopify_Supplier::OPTION ) !== $disabled || ! UltraLove_Shopify_Supplier::retired()
        || UltraLove_Shopify_Supplier::enabled() || file_exists( $cron ) ) {
        throw new RuntimeException( 'Legacy retirement did not persist. Review options and the protected cron backup.' );
    }
    WP_CLI::log( 'Protected retirement backup: ' . $backup );
    $lock = fopen( $directory . '/shopify-bridge.lock', 'c' );
    if ( ! $lock ) {
        throw new RuntimeException( 'Cannot inspect the legacy worker lock after disabling new runs.' );
    }
    try {
        $deadline = microtime( true ) + 150;
        while ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            if ( microtime( true ) >= $deadline ) {
                throw new RuntimeException( 'Legacy scheduling is retired, but an existing worker has not finished. Verify its process before declaring retirement complete.' );
            }
            usleep( 500000 );
        }
        foreach ( array( 'ul_dev_shopify_feed_state', 'ul_dev_shopify_status', 'ul_dev_shopify_last_success',
            'ul_dev_shopify_feed_last_success' ) as $option ) {
            $snapshot[$option] = get_option( $option, null );
        }
        $encoded = json_encode( $snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
        if ( file_put_contents( $backup . '/options.json', $encoded, LOCK_EX ) !== strlen( $encoded ) ) {
            throw new RuntimeException( 'Cannot save the final drained legacy state to the protected backup.' );
        }
        WP_CLI::success( 'Legacy bridge retired and idle. Configuration identities, feed state, mappings, orders and credentials are retained; no Shopify requests were made.' );
    } finally {
        fclose( $lock );
    }
} finally {
    umask( $old_umask );
}
