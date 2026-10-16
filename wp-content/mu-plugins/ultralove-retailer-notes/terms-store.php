<?php
/**
 * Reading and saving a product's retailer terms (WordPress side of terms.php).
 *
 * Terms live in one meta value, _ul_retailer_terms (JSON). A product that has not been migrated yet still
 * has the old two fields; reading falls back to converting them on the fly, so deploying the code before
 * running the migration shows the same notes. Saving writes the JSON and drops the old fields, so a stale
 * old value can never come back after the terms are cleared.
 */
require_once __DIR__ . '/terms.php';

/** One line per entry of an old free-text field. @return string[] */
function ultralove_rn_legacy_field_lines( int $product_id, string $key ): array {
	$raw = (string) get_post_meta( $product_id, $key, true );
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $raw ) ), 'strlen' ) );
}

/**
 * The old two fields as lines. Only for the one-off scripts in ops/retailer-notes that work on the old fields
 * directly; the site itself reads ultralove_rn_get_terms().
 *
 * @return array{info:string[],important:string[]}
 */
function ultralove_rn_get( int $product_id ): array {
	return array(
		'info'      => ultralove_rn_legacy_field_lines( $product_id, ULTRALOVE_RN_INFO ),
		'important' => ultralove_rn_legacy_field_lines( $product_id, ULTRALOVE_RN_IMPORTANT ),
	);
}

/** Whether this product already has its terms in the new field. */
function ultralove_rn_has_terms_meta( int $product_id ): bool {
	return null !== ultralove_rn_terms_decode( (string) get_post_meta( $product_id, ULTRALOVE_RN_TERMS, true ) );
}

function ultralove_rn_get_terms( int $product_id ): array {
	$decoded = ultralove_rn_terms_decode( (string) get_post_meta( $product_id, ULTRALOVE_RN_TERMS, true ) );
	if ( null !== $decoded ) {
		return $decoded;
	}
	return ultralove_rn_terms_from_legacy(
		ultralove_rn_legacy_field_lines( $product_id, ULTRALOVE_RN_INFO ),
		ultralove_rn_legacy_field_lines( $product_id, ULTRALOVE_RN_IMPORTANT )
	);
}

/** Store the terms (or clear them) and drop the old fields. */
function ultralove_rn_save_terms( int $product_id, array $terms ): void {
	$json = ultralove_rn_terms_encode( $terms );
	if ( '' === $json ) {
		delete_post_meta( $product_id, ULTRALOVE_RN_TERMS );
	} else {
		update_post_meta( $product_id, ULTRALOVE_RN_TERMS, wp_slash( $json ) );
	}
	delete_post_meta( $product_id, ULTRALOVE_RN_INFO );
	delete_post_meta( $product_id, ULTRALOVE_RN_IMPORTANT );
}

/** The terms as the old two newline-joined strings (what the description cleanup works on). @return array{info:string,important:string} */
function ultralove_rn_legacy_strings( int $product_id ): array {
	$legacy = ultralove_rn_terms_to_legacy( ultralove_rn_get_terms( $product_id ) );
	return array( 'info' => implode( "\n", $legacy['info'] ), 'important' => implode( "\n", $legacy['important'] ) );
}

/** Store terms given as the old two strings. */
function ultralove_rn_save_legacy_strings( int $product_id, string $info, string $important ): void {
	$lines = static fn( string $text ): array => array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ), 'strlen' ) );
	ultralove_rn_save_terms( $product_id, ultralove_rn_terms_from_legacy( $lines( $info ), $lines( $important ) ) );
}

/** What ultralove_rn_legacy_strings() returns after these strings have been saved and read back. */
function ultralove_rn_canonical_legacy_strings( string $info, string $important ): array {
	$lines  = static fn( string $text ): array => array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ), 'strlen' ) );
	$legacy = ultralove_rn_terms_to_legacy( ultralove_rn_terms_from_legacy( $lines( $info ), $lines( $important ) ) );
	return array( 'info' => implode( "\n", $legacy['info'] ), 'important' => implode( "\n", $legacy['important'] ) );
}
