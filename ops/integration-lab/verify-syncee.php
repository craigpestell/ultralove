<?php
/**
 * Verify the unconnected Syncee supplier installer on the dedicated lab only.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
    || trim( (string) file_get_contents( '/etc/ultralove-integration-lab' ) ) !== 'ultralove-integration-lab'
    || home_url() !== 'https://ultralove-lab.104-248-211-144.sslip.io' ) {
    exit( 1 );
}

if ( ! class_exists( 'RestForSynceeSupplier' ) ) {
    WP_CLI::error( 'Syncee for Suppliers must be active on the lab.' );
}

$previous_user = get_current_user_id();
try {
    wp_set_current_user( 0 );
    foreach ( array(
        'startInstallFlow' => 'POST',
        'callbackFromWoocommerce' => 'POST',
        'saveAccessTokenFromSyncee' => 'POST',
        'saveTokenFromSyncee' => 'POST',
        'uninstallEcom' => 'POST',
        'getRequirements' => 'GET',
        'getDataForFrontend' => 'GET',
    ) as $endpoint => $method ) {
        $request = new WP_REST_Request( $method, '/syncee/supplier/v1/' . $endpoint );
        $response = rest_do_request( $request );
        if ( ! in_array( $response->get_status(), array( 401, 403 ), true ) ) {
            WP_CLI::error( 'Anonymous installer request was not denied: ' . $endpoint );
        }
        WP_CLI::log( 'PASS: anonymous access denied for ' . $endpoint );
    }

    $administrator = get_user_by( 'login', 'lab-admin' );
    if ( ! $administrator ) {
        WP_CLI::error( 'Expected lab administrator is missing.' );
    }
    wp_set_current_user( $administrator->ID );
    require_once WP_PLUGIN_DIR . '/syncee-for-suppliers/includes/RequirementsForSynceeSupplier.php';
    foreach ( RequirementsForSynceeSupplier::getRequirementsStatusForSyncee() as $key => $requirement ) {
        if ( $key === 'check_ssl' ) {
            // HTTPS is verified separately over the actual public endpoint.
            continue;
        }
        if ( empty( $requirement['pass'] ) ) {
            WP_CLI::error( 'Supplier prerequisite failed: ' . $key );
        }
        WP_CLI::log( 'PASS: supplier prerequisite ' . $key );
    }

    foreach ( array(
        'syncee_access_token_supplier',
        'syncee_user_token_supplier',
        'data_to_syncee_installer_supplier',
    ) as $option ) {
        if ( get_option( $option, false ) !== false ) {
            WP_CLI::error( 'Supplier setup has already started; unconnected baseline no longer applies.' );
        }
    }
    WP_CLI::success( 'Syncee installer baseline verified; no external account or API connection created.' );
} finally {
    wp_set_current_user( $previous_user );
}
