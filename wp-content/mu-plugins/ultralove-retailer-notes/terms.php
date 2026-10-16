<?php
/**
 * Structured retailer terms.
 *
 * One product has one set of terms instead of two free-text fields:
 *   limit        none | store | color | bottle | stand | tester     ("Limit 1 color per store")
 *   free_with    units bought for a free item, 0 = none             ("Free with 3 units")
 *   free_per     '' | flavor                                        ("... per flavor")
 *   no_discounts true when discounts do not apply
 *   tags         status tags (seasonal, limited edition, ...), shown on the product page only
 *   other        verbatim lines for the rare wording that fits nothing above
 *
 * Text is generated from the structure, so wording and order are the same everywhere. The old two-field
 * text (_ul_retailer_info / _ul_retailer_important) converts both ways, which the importer, the description
 * cleanup and the migration use.
 *
 * Pure PHP (no WordPress calls) so ops/retailer-notes/test-terms.php can run it with plain `php`.
 */
require_once __DIR__ . '/notes.php';

if ( ! defined( 'ULTRALOVE_RN_TERMS' ) ) {
	define( 'ULTRALOVE_RN_TERMS', '_ul_retailer_terms' );
}
if ( ! defined( 'ULTRALOVE_RN_INFO' ) ) {
	define( 'ULTRALOVE_RN_INFO', '_ul_retailer_info' );
}
if ( ! defined( 'ULTRALOVE_RN_IMPORTANT' ) ) {
	define( 'ULTRALOVE_RN_IMPORTANT', '_ul_retailer_important' );
}

/** @return array<string,array{label:string,legacy:string}> slug => display label and the old text */
function ultralove_rn_limit_options(): array {
	return array(
		'store'  => array( 'label' => 'Limit 1 per store', 'legacy' => 'LIMIT 1 PER STORE' ),
		'color'  => array( 'label' => 'Limit 1 color per store', 'legacy' => 'LIMIT 1 COLOR PER STORE' ),
		'bottle' => array( 'label' => 'Limit 1 bottle per store', 'legacy' => 'LIMIT 1 BOTTLE PER STORE' ),
		'stand'  => array( 'label' => 'Limit 1 stand per store', 'legacy' => 'LIMIT 1 STAND PER STORE' ),
		'tester' => array( 'label' => 'Limit 1 per tester', 'legacy' => 'LIMIT 1 PER TESTER' ),
	);
}

/** Unit counts offered in the editor (a stored count outside this list is still kept and shown). */
function ultralove_rn_free_with_options(): array {
	return array( 2, 3, 5 );
}

/** @return array<string,array{label:string,legacy:string}> status tag slug => label and the old text */
function ultralove_rn_tag_options(): array {
	return array(
		'seasonal'            => array( 'label' => 'Seasonal, limited supply', 'legacy' => '(SEASONAL - LIMITED SUPPLY)' ),
		'limited_edition'     => array( 'label' => 'Limited edition', 'legacy' => '(LIMITED EDITION)' ),
		'special_order'       => array( 'label' => 'Special order only', 'legacy' => 'SPECIAL ORDER ONLY' ),
		'while_stock_lasts'   => array( 'label' => 'While stock lasts', 'legacy' => 'WHILE STOCK LASTS' ),
		'sale_priced'         => array( 'label' => 'Sale priced', 'legacy' => 'SALE PRICED' ),
		'non_retail_packaging' => array( 'label' => 'Ships in non-retail packaging', 'legacy' => 'Ships in Non-Retail Packaging' ),
		'retail_demo'         => array( 'label' => 'Retail demo unit', 'legacy' => 'Retail Demo Unit' ),
	);
}

const ULTRALOVE_RN_DISCOUNTS_LEGACY = 'DISCOUNTS DO NOT APPLY';
const ULTRALOVE_RN_DISCOUNTS_LABEL  = 'Discounts do not apply';

function ultralove_rn_terms_blank(): array {
	return array( 'limit' => '', 'free_with' => 0, 'free_per' => '', 'no_discounts' => false, 'tags' => array(), 'other' => array() );
}

/** Force any array into the shape above: unknown values dropped, tags in their fixed order, other lines unique. */
function ultralove_rn_terms_clean( array $terms ): array {
	$clean = ultralove_rn_terms_blank();
	$limit = (string) ( $terms['limit'] ?? '' );
	$clean['limit'] = isset( ultralove_rn_limit_options()[ $limit ] ) ? $limit : '';
	$clean['free_with'] = max( 0, (int) ( $terms['free_with'] ?? 0 ) );
	$clean['free_per'] = $clean['free_with'] && 'flavor' === ( $terms['free_per'] ?? '' ) ? 'flavor' : '';
	$clean['no_discounts'] = ! empty( $terms['no_discounts'] );
	$tags = (array) ( $terms['tags'] ?? array() );
	$clean['tags'] = array_values( array_filter( array_keys( ultralove_rn_tag_options() ), static fn( $slug ) => in_array( $slug, $tags, true ) ) );
	$seen = array();
	foreach ( (array) ( $terms['other'] ?? array() ) as $line ) {
		$line = trim( preg_replace( '/\s+/u', ' ', (string) $line ) );
		if ( '' !== $line && ! isset( $seen[ mb_strtoupper( $line ) ] ) ) {
			$seen[ mb_strtoupper( $line ) ] = true;
			$clean['other'][] = $line;
		}
	}
	return $clean;
}

function ultralove_rn_terms_empty( array $terms ): bool {
	return ultralove_rn_terms_clean( $terms ) === ultralove_rn_terms_blank();
}

/** Stored form: JSON with only the non-empty parts; '' when there is nothing to store. */
function ultralove_rn_terms_encode( array $terms ): string {
	$terms = ultralove_rn_terms_clean( $terms );
	if ( $terms === ultralove_rn_terms_blank() ) {
		return '';
	}
	$out = array( 'v' => 1 );
	foreach ( $terms as $key => $value ) {
		if ( $value !== ultralove_rn_terms_blank()[ $key ] ) {
			$out[ $key ] = $value;
		}
	}
	return (string) json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/** @return array|null null when the text is not valid terms JSON */
function ultralove_rn_terms_decode( string $json ): ?array {
	$data = json_decode( $json, true );
	return is_array( $data ) ? ultralove_rn_terms_clean( $data ) : null;
}

/* ---------------------------------------------------------------------------------------------
 * Old two-field text <-> terms
 * ------------------------------------------------------------------------------------------ */

/** Old whole-line texts that mean a flag: uppercase text => 'no_discounts' or a tag slug. */
function ultralove_rn_terms_flag_texts(): array {
	$map = array(
		'DISCOUNTS DO NOT APPLY'     => 'no_discounts',
		'NO DISCOUNTS APPLY'         => 'no_discounts',
		'NO FURTHER DISCOUNTS APPLY' => 'no_discounts',
	);
	foreach ( ultralove_rn_tag_options() as $slug => $option ) {
		$map[ mb_strtoupper( $option['legacy'] ) ] = $slug;
	}
	return $map;
}

/** Add one old-style note line to $terms. Anything that fits no field is kept verbatim in 'other'. */
function ultralove_rn_terms_add_line( array &$terms, string $line, array &$review ): void {
	$line = trim( $line );
	if ( '' === $line ) {
		return;
	}
	$upper = mb_strtoupper( rtrim( ultralove_rn_clean_text( $line ), ' .;' ) );
	$flag  = ultralove_rn_terms_flag_texts()[ $upper ] ?? null;
	if ( 'no_discounts' === $flag ) {
		$terms['no_discounts'] = true;
		return;
	}
	if ( null !== $flag ) {
		$terms['tags'][] = $flag;
		return;
	}
	// "Ships in Non-Retail Packaging DISCOUNTS DO NOT APPLY" was stored as one value.
	if ( preg_match( '/\S\s+DISCOUNTS DO NOT APPLY$/iu', $line ) ) {
		$terms['no_discounts'] = true;
		ultralove_rn_terms_add_line( $terms, (string) preg_replace( '/\s+DISCOUNTS DO NOT APPLY\s*$/iu', '', $line ), $review );
		return;
	}
	$clauses = ultralove_rn_note_clauses( $line );
	if ( null === $clauses ) {
		$terms['other'][] = $line;
		if ( preg_match( '/\b(?:PER STORE|PER TESTER|FREE WITH|MUST ORDER)\b/i', $line ) ) {
			$review[] = 'Unrecognized combined condition: ' . $line;
		}
		return;
	}
	$limits = ultralove_rn_limit_options();
	foreach ( $clauses as $clause ) {
		$limit = array_search( $clause, array_column( $limits, 'legacy' ), true );
		if ( false !== $limit ) {
			$slug = array_keys( $limits )[ $limit ];
			if ( '' !== $terms['limit'] && $slug !== $terms['limit'] ) {
				$terms['other'][] = $clause;
				$review[] = 'Two different limits: ' . $limits[ $terms['limit'] ]['legacy'] . ' and ' . $clause;
			} else {
				$terms['limit'] = $slug;
			}
		} elseif ( preg_match( '/^FREE WITH ([1-9][0-9]*) UNITS BOUGHT( PER FLAVOR)?$/', $clause, $match ) ) {
			$units = (int) $match[1];
			$per   = ! empty( $match[2] ) ? 'flavor' : '';
			if ( $terms['free_with'] && ( $units !== $terms['free_with'] || $per !== $terms['free_per'] ) ) {
				$terms['other'][] = $clause;
				$review[] = 'Two different free offers: kept both';
			} else {
				$terms['free_with'] = $units;
				$terms['free_per']  = $per;
			}
		} else {
			$terms['other'][] = $clause;
		}
	}
}

/**
 * @param string[] $info      lines of the old "Retailer info" field
 * @param string[] $important lines of the old "Important retailer info" field
 * @param string[] $review    filled with anything that needs a person's look
 */
function ultralove_rn_terms_from_legacy( array $info, array $important, ?array &$review = null ): array {
	$review = array();
	$terms  = ultralove_rn_terms_blank();
	foreach ( array_merge( $important, $info ) as $line ) {
		ultralove_rn_terms_add_line( $terms, (string) $line, $review );
	}
	return ultralove_rn_terms_clean( $terms );
}

/** The old two-field text for these terms, in the form ultralove_rn_merge_notes() produces. */
function ultralove_rn_terms_to_legacy( array $terms ): array {
	$terms = ultralove_rn_terms_clean( $terms );
	$info = $important = array();
	if ( '' !== $terms['limit'] ) {
		$important[] = ultralove_rn_limit_options()[ $terms['limit'] ]['legacy'];
	}
	if ( $terms['free_with'] ) {
		$important[] = 'FREE WITH ' . $terms['free_with'] . ' UNITS BOUGHT' . ( 'flavor' === $terms['free_per'] ? ' PER FLAVOR' : '' );
	}
	foreach ( $terms['other'] as $line ) {
		$important[] = $line;
	}
	if ( $terms['no_discounts'] ) {
		$info[] = ULTRALOVE_RN_DISCOUNTS_LEGACY;
	}
	foreach ( $terms['tags'] as $slug ) {
		$info[] = ultralove_rn_tag_options()[ $slug ]['legacy'];
	}
	return array( 'info' => $info, 'important' => $important );
}

/* ---------------------------------------------------------------------------------------------
 * Display
 * ------------------------------------------------------------------------------------------ */

/**
 * The terms as text lines, in a fixed order: limit, free offer, discounts, other.
 * $context 'card' uses the short wording; 'page' (product page, quick view) the full one.
 *
 * @return string[]
 */
function ultralove_rn_terms_lines( array $terms, string $context = 'page' ): array {
	$terms = ultralove_rn_terms_clean( $terms );
	$lines = array();
	if ( '' !== $terms['limit'] ) {
		$lines[] = ultralove_rn_limit_options()[ $terms['limit'] ]['label'];
	}
	if ( $terms['free_with'] ) {
		$lines[] = 'Free with ' . $terms['free_with'] . ' units' . ( 'card' === $context ? '' : ' bought' ) . ( 'flavor' === $terms['free_per'] ? ' per flavor' : '' );
	}
	if ( $terms['no_discounts'] ) {
		$lines[] = ULTRALOVE_RN_DISCOUNTS_LABEL;
	}
	return array_merge( $lines, $terms['other'] );
}

/** @return string[] labels of the status tags */
function ultralove_rn_terms_tag_labels( array $terms ): array {
	$options = ultralove_rn_tag_options();
	return array_map( static fn( $slug ) => $options[ $slug ]['label'], ultralove_rn_terms_clean( $terms )['tags'] );
}

/**
 * How many of the product title's lines the terms pill takes on a card, so the pill and the title together
 * still fill the same fixed title box. The pill's lines are 14px (plus 4px padding); the title's are 20.8px.
 * A line is estimated at $lower characters (mixed case) or $upper characters (all capitals) per row.
 *
 * @param string[] $lines
 */
function ultralove_rn_terms_reserved_lines( array $lines, int $lower, int $upper ): int {
	$rows = 0;
	foreach ( $lines as $line ) {
		$per   = $line === mb_strtoupper( $line ) ? $upper : $lower;
		$rows += max( 1, (int) ceil( mb_strlen( $line ) / $per ) );
	}
	return max( 1, min( 5, (int) ceil( ( 14 * $rows + 6 ) / 20.8 ) ) );
}
