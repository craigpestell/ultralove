<?php
/**
 * Plugin Name: Reviewed Product Description Cleanup
 * Description: Keeps explicitly migrated products' reviewed description blocks clean on import.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/ultralove-retailer-notes/descriptions.php';
require_once __DIR__ . '/ultralove-retailer-notes/terms-store.php';

const ULTRALOVE_DESCRIPTION_CLEANUP_VERSION = '1';
const ULTRALOVE_DESCRIPTION_CLEANUP_META = '_ul_description_cleanup_version';

/** The retailer terms are read as the old two note strings (see terms-store.php), which this cleanup works on. */
function ultralove_description_snapshot( int $id ): array {
	$notes = ultralove_rn_legacy_strings( $id );
	return array(
		'post_content' => (string) get_post_field( 'post_content', $id, 'raw' ),
		'post_excerpt' => (string) get_post_field( 'post_excerpt', $id, 'raw' ),
		'info' => $notes['info'],
		'important' => $notes['important'],
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
	if ( $before['info'] !== $after['info'] || $before['important'] !== $after['important'] ) {
		ultralove_rn_save_legacy_strings( $id, $after['info'], $after['important'] );
	}
	clean_post_cache( $id );
	// The terms are stored in a fixed form, so compare with the notes as they read back.
	$expected = array_merge( $after, ultralove_rn_canonical_legacy_strings( $after['info'], $after['important'] ) );
	if ( ultralove_description_snapshot( $id ) !== $expected ) {
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
	$after = ultralove_rn_description_after( $before, $review );
	foreach ( $review as $reason ) {
		error_log( 'Description cleanup product ' . $id . ': ' . $reason );
	}
	if ( $before !== $after ) {
		ultralove_description_write( $id, $before, $after );
	}
}
