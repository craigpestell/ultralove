<?php
/** Shared semantic merging for retailer fields, independent of their source. */
require_once __DIR__ . '/parser.php';

/** @return string[]|null Whole recognized conditions only; never strip a partial instruction. */
function ultralove_rn_note_clauses( string $note ): ?array {
	$text = rtrim( ultralove_rn_clean_text( $note ), ' .;' );
	$text = preg_replace( '/^TESTER:\s*/i', '', $text );
	$text = preg_replace( '/\bA MINIMUM OF ([1-9][0-9]*) UNITS MUST BE (?:PURCHASED|PURHCASED) TO RECEIVE A FREE TESTER\b/i', 'FREE WITH $1 UNITS BOUGHT', $text );
	$text = str_replace( ' - ', ' ', $text );
	if ( '' === $text ) {
		return null;
	}
	if ( preg_match( '/^(?:MUST ORDER [1-9][0-9]* (?:OF EACH COLOR|ASSORTED COLORS) IN ORDER TO RECEIVE A FREE DISPLAY|FREE COCK RING WITH PURCHASE)$/i', $text ) ) {
		return array( mb_strtoupper( $text ) );
	}
	$store = '(?:LIMIT )?(?:ONE|1)(?: (?:COLOU?R|BOTTLE|STAND))? PER (?:STORE|TESTER)(?: ONLY)?';
	$free = 'FREE WITH (?:[1-9][0-9]* UNITS BOUGHT(?: PER FLAVOR)?|[1-9][0-9]*(?: \([1-9][0-9]* EACH\))? PRODUCTS BOUGHT|[1-9][0-9]* EACH OF THE [A-Z0-9]+ LINE PURCHASED|PURCHASE OF [1-9][0-9]* ESSENTIAL BULLETS)';
	$clauses = array();
	$offset = 0;
	while ( $offset < strlen( $text ) ) {
		if ( ! preg_match( '/\G(?:' . $store . '|' . $free . '|NO EXCEPTIONS)(?= |$) */i', $text, $match, 0, $offset ) ) {
			return null;
		}
		$part = mb_strtoupper( trim( $match[0] ) );
		if ( preg_match( '/^(?:LIMIT )?(?:ONE|1)(?: (COLOU?R|BOTTLE|STAND))? PER (STORE|TESTER)(?: ONLY)?$/', $part, $limit ) ) {
			$unit = $limit[1] ?? '';
			$unit = 'COLOUR' === $unit ? 'COLOR' : $unit;
			$part = 'LIMIT 1' . ( '' !== $unit ? ' ' . $unit : '' ) . ' PER ' . $limit[2] . ' ONLY';
		}
		if ( 'NO EXCEPTIONS' !== $part ) {
			$clauses[] = $part;
		}
		$offset += strlen( $match[0] );
	}
	return $clauses ?: null;
}

function ultralove_rn_regular_note( string $note ): ?string {
	$text = mb_strtoupper( rtrim( ultralove_rn_clean_text( $note ), ' .;' ) );
	if ( 'NO DISCOUNTS APPLY' === $text ) {
		$text = 'DISCOUNTS DO NOT APPLY';
	}
	return preg_match( '/^(?:DISCOUNTS DO NOT APPLY|NO FURTHER DISCOUNTS APPLY|SPECIAL ORDER ONLY|WHILE STOCK LASTS|SALE PRICED|\(SEASONAL - LIMITED SUPPLY\)|\(LIMITED EDITION\))$/', $text ) ? $text : null;
}

/**
 * Equivalent conditions collapse across both fields; recognized quantity/offer
 * clauses belong in Important. Unknown prose stays verbatim in its original field.
 * Different purchase offers are not flattened into a newly invented combined offer.
 *
 * @return array{info:string[],important:string[],review:string[]}
 */
function ultralove_rn_merge_notes( array $have, array $add = array() ): array {
	$merged = array( 'info' => array(), 'important' => array(), 'review' => array() );
	$parsed = $offers = array();
	foreach ( array( 'info', 'important' ) as $field ) {
		foreach ( array_merge( $have[ $field ] ?? array(), $add[ $field ] ?? array() ) as $note ) {
			$note = trim( $note );
			if ( '' === $note ) {
				continue;
			}
			$key = mb_strtoupper( $note );
			$merged[ $field ][ $key ] = $merged[ $field ][ $key ] ?? $note;
			$parsed[ $key ] = ultralove_rn_note_clauses( $note );
			foreach ( $parsed[ $key ] ?? array() as $clause ) {
				if ( str_starts_with( $clause, 'FREE WITH ' ) || str_starts_with( $clause, 'MUST ORDER ' ) || 'FREE COCK RING WITH PURCHASE' === $clause ) {
					$offers[ $clause ] = true;
				}
			}
			if ( null === $parsed[ $key ] && preg_match( '/\b(?:PER STORE|PER TESTER|FREE WITH|MUST ORDER)\b/i', $note ) ) {
				$merged['review'][] = 'Unrecognized combined condition: ' . $note;
			}
		}
	}
	$conflicting = count( $offers ) > 1;
	if ( $conflicting ) {
		$merged['review'][] = 'Different purchase conditions: preserve whole offers for manual review.';
	}
	$result = array( 'info' => array(), 'important' => array(), 'review' => array_values( array_unique( $merged['review'] ) ) );
	foreach ( array( 'important', 'info' ) as $field ) {
		foreach ( $merged[ $field ] as $key => $note ) {
			$clauses = $conflicting ? null : $parsed[ $key ];
			$regular = ultralove_rn_regular_note( $note );
			$destination = null !== $regular ? 'info' : ( null === $clauses ? $field : 'important' );
			foreach ( null !== $regular ? array( $regular ) : ( $clauses ?? array( $note ) ) as $clause ) {
				$clause_key = mb_strtoupper( $clause );
				if ( 'info' === $destination && isset( $result['important'][ $clause_key ] ) ) {
					continue;
				}
				$result[ $destination ][ $clause_key ] = $result[ $destination ][ $clause_key ] ?? $clause;
			}
		}
	}
	foreach ( array( 'info', 'important' ) as $field ) {
		$result[ $field ] = array_values( $result[ $field ] );
	}
	return $result;
}
