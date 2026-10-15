<?php
/** Conservative, byte-preserving cleanup of reviewed description blocks. */
require_once __DIR__ . '/parser.php';

/**
 * Only whole, unambiguous instructions qualify. Mixed paragraphs stay untouched.
 * @return string|null Destination retailer field, or null for unreviewed text.
 */
function ultralove_rn_description_note_field( string $text ): ?string {
	if ( preg_match( '/^(?:DISCOUNTS DO NOT APPLY|NO (?:FURTHER )?DISCOUNTS APPLY|SPECIAL ORDER ONLY|WHILE STOCK LASTS|SALE PRICED)$/i', $text ) ) {
		return 'info';
	}
	$store = '(?:(?:LIMIT )?(?:ONE|1)(?: COLOU?R)? PER STORE(?: ONLY)?)';
	$free = 'FREE WITH [0-9]+ UNITS BOUGHT';
	if ( preg_match( '/^(?:' . $store . ')(?: ' . $free . ')?$/i', $text ) ) {
		return 'important';
	}
	return null;
}

/**
 * Preserve unreviewed text/attributes, malformed fields and layout/media verbatim.
 * @return array{html:string,info:string[],important:string[],changes:int,review:string[]}
 */
function ultralove_rn_clean_description( string $html ): array {
	$result = array( 'html' => $html, 'info' => array(), 'important' => array(), 'changes' => 0, 'review' => array() );
	if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
		throw new RuntimeException( 'Invalid UTF-8 in product description.' );
	}
	if ( '' === trim( $html ) ) {
		return $result;
	}
	$previous = libxml_use_internal_errors( true );
	libxml_clear_errors();
	$document = new DOMDocument();
	$loaded = $document->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NONET );
	$errors = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( ! $loaded || $errors || preg_match( '/<(?:script|style|textarea|ul|ol|table|div)\b/i', $html ) ) {
		$result['review'][] = 'Malformed, active-content or structured-layout field: left unchanged.';
		return $result;
	}
	$replace = static function ( string $body ) use ( &$result ): ?string {
		$text = ultralove_rn_clean_text( $body );
		$field = ultralove_rn_description_note_field( $text );
		$classes = array(
			'The Essential Clitoral Stimulator' => 'ul-product-callout',
			'Incredibly Personal' => 'ul-product-callout',
			'Sensual Comfort!' => 'ul-product-callout',
			'SMART PHONE NOT INCLUDED' => 'ul-product-notice',
			'PLEASE NOTE: CABLE CHARGERS ARE NOT INCLUDED' => 'ul-product-notice',
		);
		$class = $classes[ $text ] ?? null;
		$red = false;
		$safe = true;
		$stack = array();
		preg_match_all( '/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', $body, $tags, PREG_SET_ORDER );
		foreach ( $tags as $tag ) {
			$name = strtolower( $tag[1] );
			if ( preg_match( '/^<\//', $tag[0] ) ) {
				$safe = $safe && array_pop( $stack ) === $name;
				continue;
			}
			if ( 'br' !== $name ) {
				$stack[] = $name;
			}
			if ( 'b' === $name || 'br' === $name ) {
				$safe = $safe && (bool) preg_match( '/^<(?:b|br)\s*\/?>$/i', $tag[0] );
			} elseif ( 'font' === $name ) {
				if ( preg_match( '/^<font\s+color\s*=\s*(?:"red"|\'red\'|red)\s*>$/i', $tag[0] ) ) {
					$red = true;
				} elseif ( ! preg_match( '/^<font\s+size\s*=\s*(?:"(?:[1-6](?:\.5)?|8|10)"|\'(?:[1-6](?:\.5)?|8|10)\'|[1-6])\s*>$/i', $tag[0] ) ) {
					$safe = false;
				}
			} elseif ( 'span' === $name ) {
				if ( preg_match( '/^<span\s+style\s*=\s*(["\'])\s*color\s*:\s*red\s*;?\s*\1\s*>$/i', $tag[0] ) ) {
					$red = true;
				} elseif ( ! preg_match( '/^<span\s+style\s*=\s*(["\'])\s*font-size\s*:\s*(?:small|medium|large|x-large|xx-large)\s*;?\s*\1\s*>$/i', $tag[0] ) ) {
					$safe = false;
				}
			} else {
				$safe = false;
			}
		}
		if ( ! $safe || $stack || str_contains( $body, '<!' ) ) {
			return null;
		}
		if ( null !== $field ) {
			$result[ $field ][] = $text;
			$result['changes']++;
			return '';
		}
		if ( null !== $class && $red ) {
			$result['changes']++;
			return '<span class="' . $class . '">' . trim( strip_tags( $body, '<br>' ) ) . '</span>';
		}
		if ( $red ) {
			$result['review'][] = $text;
		}
		return null;
	};
	// Replace offsets in the source, never serialize the DOM back over unrelated HTML.
	$parts = preg_split( '/(<\/?p\b[^>]*>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	foreach ( $parts as $index => $part ) {
		if ( 1 === $index % 2 ) {
			continue;
		}
		$body = $replace( $part );
		if ( null !== $body ) {
			$parts[ $index ] = $body;
		}
	}
	$result['html'] = implode( '', $parts );
	return $result;
}

/** @return array{post_content:string,post_excerpt:string,info:string,important:string} */
function ultralove_rn_description_after( array $before ): array {
	$after = $before;
	$notes = array();
	foreach ( array( 'info', 'important' ) as $field ) {
		$notes[ $field ] = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $before[ $field ] ) ), 'strlen' ) );
	}
	foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
		$clean = ultralove_rn_clean_description( $before[ $field ] );
		$after[ $field ] = $clean['html'];
		foreach ( array( 'info', 'important' ) as $destination ) {
			foreach ( $clean[ $destination ] as $note ) {
				if ( ! in_array( mb_strtoupper( $note ), array_map( 'mb_strtoupper', $notes[ $destination ] ), true ) ) {
					$notes[ $destination ][] = $note;
				}
				if ( 'important' === $destination ) {
					$notes['info'] = array_values( array_filter( $notes['info'], static fn( string $info ): bool => mb_strtoupper( $info ) !== mb_strtoupper( $note ) ) );
				}
			}
		}
	}
	foreach ( array( 'info', 'important' ) as $field ) {
		$original = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $before[ $field ] ) ), 'strlen' ) );
		if ( $original !== $notes[ $field ] ) {
			$after[ $field ] = implode( "\n", $notes[ $field ] );
		}
	}
	return $after;
}
