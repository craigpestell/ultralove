<?php
/**
 * Plugin Name: Reviewed Product Description Cleanup
 * Description: Keeps explicitly migrated products' reviewed description blocks clean on import.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/ultralove-retailer-notes/descriptions.php';

const ULTRALOVE_DESCRIPTION_CLEANUP_VERSION = '1';
const ULTRALOVE_DESCRIPTION_CLEANUP_META = '_ul_description_cleanup_version';

function ultralove_description_snapshot( int $id ): array {
	return array(
		'post_content' => (string) get_post_field( 'post_content', $id, 'raw' ),
		'post_excerpt' => (string) get_post_field( 'post_excerpt', $id, 'raw' ),
		'info' => (string) get_post_meta( $id, ULTRALOVE_RN_INFO, true ),
		'important' => (string) get_post_meta( $id, ULTRALOVE_RN_IMPORTANT, true ),
	);
}

/** Throws on stale inputs or failed persistence; never changes names or URLs. */
function ultralove_description_write( int $id, array $before, array $after ): void {
	if ( 'product' !== get_post_type( $id ) || ultralove_description_snapshot( $id ) !== $before ) {
		throw new RuntimeException( 'Description state changed for product ' . $id . '; stopped.' );
	}
	$post = array( 'ID' => $id );
	foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
		if ( $before[ $field ] !== $after[ $field ] ) {
			$post[ $field ] = $after[ $field ];
		}
	}
	if ( count( $post ) > 1 ) {
		$saved = wp_update_post( wp_slash( $post ), true );
		if ( is_wp_error( $saved ) ) {
			throw new RuntimeException( $saved->get_error_message() );
		}
	}
	foreach ( array( 'info' => ULTRALOVE_RN_INFO, 'important' => ULTRALOVE_RN_IMPORTANT ) as $field => $key ) {
		if ( $before[ $field ] !== $after[ $field ] ) {
			update_post_meta( $id, $key, wp_slash( $after[ $field ] ) );
		}
	}
	clean_post_cache( $id );
	if ( ultralove_description_snapshot( $id ) !== $after ) {
		throw new RuntimeException( 'Description verification failed for product ' . $id . '; retain the private plan for recovery.' );
	}
}

add_action( 'pmxi_saved_post', 'ultralove_description_after_import', 30, 1 );
function ultralove_description_after_import( $post_id ): void {
	$id = (int) $post_id;
	if ( 'product' !== get_post_type( $id ) || ULTRALOVE_DESCRIPTION_CLEANUP_VERSION !== get_post_meta( $id, ULTRALOVE_DESCRIPTION_CLEANUP_META, true ) ) {
		return;
	}
	$before = ultralove_description_snapshot( $id );
	$after = ultralove_rn_description_after( $before );
	if ( $before !== $after ) {
		ultralove_description_write( $id, $before, $after );
	}
}
