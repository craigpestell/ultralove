<?php
/** Conservative, byte-preserving cleanup of reviewed description blocks. */
require_once __DIR__ . '/notes.php';

/** Normalize labels/separators only when the remainder is a whole known condition. */
function ultralove_rn_description_condition( string $text ): ?array {
	$text = ultralove_rn_clean_text( $text );
	$text = preg_replace( '/^(?:TESTER(?: -)?|Display Stand -) /i', '', $text );
	$text = str_replace( ' - ', ' ', $text );
	return ultralove_rn_note_clauses( $text );
}

/**
 * Only whole, unambiguous instructions qualify. Mixed paragraphs stay untouched.
 * @return string|null Destination retailer field, or null for unreviewed text.
 */
function ultralove_rn_description_note_field( string $text ): ?string {
	if ( null !== ultralove_rn_regular_note( $text ) ) {
		return 'info';
	}
	if ( null !== ultralove_rn_description_condition( $text ) ) {
		return 'important';
	}
	return null;
}

/**
 * Preserve unreviewed text/attributes and layout/media, including untouched list tails.
 * $isolated skips block discovery while validating an individual source fragment.
 * @return array{html:string,info:string[],important:string[],changes:int,review:string[]}
 */
function ultralove_rn_clean_description( string $html, bool $isolated = false ): array {
	$result = array( 'html' => $html, 'info' => array(), 'important' => array(), 'changes' => 0, 'review' => array() );
	if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
		throw new RuntimeException( 'Invalid UTF-8 in product description.' );
	}
	if ( '' === trim( $html ) ) {
		return $result;
	}
	if ( ! $isolated ) {
		$whole = ultralove_rn_clean_description( $html, true );
		if ( $whole['changes'] ) {
			$remainder = ultralove_rn_clean_description( $whole['html'] );
			foreach ( array( 'info', 'important' ) as $field ) {
				$whole[ $field ] = array_merge( $whole[ $field ], $remainder[ $field ] );
			}
			$whole['html'] = $remainder['html'];
			$whole['changes'] += $remainder['changes'];
			$whole['review'] = array_values( array_unique( array_merge( $whole['review'], $remainder['review'] ) ) );
			return $whole;
		}
	}
	$previous = libxml_use_internal_errors( true );
	libxml_clear_errors();
	$document = new DOMDocument();
	// Legacy imgsrc typos vary by libxml version; tolerate them only in the validation copy.
	$validation_html = preg_replace( '/<imgsrc\s*=/i', '<img src=', $html );
	$loaded = $document->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $validation_html . '</body></html>', LIBXML_NONET );
	$errors = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( $loaded && ! $isolated
		&& ! preg_match( '/<\/?(?!(?:p|img|imgsrc|br|b|font|span|u|ul|ol|li|iframe)\b)[a-z][a-z0-9]*\b/i', $html ) ) {
		$search_html = preg_replace_callback( '/<iframe\b(?:[^<>"\']+|"[^"]*"|\'[^\']*\')*>.*?(?:<\/iframe\s*>|\z)/is', static function ( array $match ): string {
			return str_repeat( ' ', strlen( $match[0] ) );
		}, $html );
		// Match only actual tags, not markup inside attributes or comments.
		preg_match_all( '/<!--.*?-->|<\/?[a-z][a-z0-9]*\b(?:[^<>"\']+|"[^"]*"|\'[^\']*\')*>/is', $search_html, $source_tags, PREG_OFFSET_CAPTURE );
		$starts = array();
		foreach ( $source_tags[0] as $tag ) {
			if ( preg_match( '/^<b\s*>$/i', $tag[0] ) ) {
				$starts[ $tag[1] ] = true;
			}
		}
		preg_match_all( '/(?:^|<\/?p\b[^>]*>|<br\s*\/?>|\n[ \t\r\n]*)\K<b\s*>(?:(?!<\/?b\b).)*<\/b>(?=\s*(?:$|<\/?p\b|<br\s*\/?>|\R))/is', $search_html, $blocks, PREG_OFFSET_CAPTURE );
		$removals = array();
		$nodes = $document->getElementsByTagName( 'b' );
		foreach ( $blocks[0] as [ $block, $offset ] ) {
			if ( ! isset( $starts[ $offset ] ) ) {
				continue;
			}
			// Legacy instructions use bare opening P tags as line breaks inside bold.
			$validation_block = preg_replace( '/<p\s*>/i', '<br>', $block );
			$clean = ultralove_rn_clean_description( $validation_block, true );
			if ( '' !== $clean['html'] || ! $clean['changes'] ) {
				continue;
			}
			$matched = false;
			$safe = true;
			foreach ( $nodes as $node ) {
				if ( ultralove_rn_clean_text( $document->saveHTML( $node ) ) !== ultralove_rn_clean_text( $block ) ) {
					continue;
				}
				$matched = true;
				for ( $parent = $node->parentNode; $parent && '#document' !== $parent->nodeName; $parent = $parent->parentNode ) {
					$safe = $safe && in_array( $parent->nodeName, array( 'p', 'body', 'html' ), true );
				}
			}
			if ( $matched && $safe ) {
				$removals[] = array( $offset, strlen( $block ) );
				foreach ( array( 'info', 'important' ) as $field ) {
					$result[ $field ] = array_merge( $result[ $field ], $clean[ $field ] );
				}
				$result['changes'] += $clean['changes'];
			}
		}
		if ( $removals ) {
			foreach ( array_reverse( $removals ) as [ $offset, $length ] ) {
				$html = substr_replace( $html, '', $offset, $length );
			}
			$remainder = ultralove_rn_clean_description( $html, true );
			foreach ( array( 'info', 'important', 'review' ) as $field ) {
				$result[ $field ] = array_merge( $result[ $field ], $remainder[ $field ] );
			}
			$result['html'] = $remainder['html'];
			$result['changes'] += $remainder['changes'];
			return $result;
		}
	}
	if ( $loaded && ! preg_match( '/<(?:script|style|textarea|iframe|table|div)\b/i', $html )
		&& preg_match( '/<\/?(?:ul|ol)\b/i', $html, $list, PREG_OFFSET_CAPTURE ) ) {
		$prefix = substr( $html, 0, $list[0][1] );
		$first_list = ( new DOMXPath( $document ) )->query( '//ul | //ol' )->item( 0 );
		$stack = array();
		$standalone = true;
		preg_match_all( '/<(\/?)(p|b|font|span)\b[^>]*>/i', $prefix, $prefix_tags, PREG_SET_ORDER );
		foreach ( $prefix_tags as $tag ) {
			$name = strtolower( $tag[2] );
			if ( 'p' === $name ) {
				$standalone = $standalone && ! $stack;
			} elseif ( '/' === $tag[1] ) {
				$standalone = $standalone && array_pop( $stack ) === $name;
			} else {
				$stack[] = $name;
			}
		}
		if ( 'body' === $first_list?->parentNode?->nodeName
			&& $standalone && ! $stack
			&& ! preg_match( '/<\/?(?!(?:p|img|br|b|font|span)\b)[a-z][a-z0-9]*\b/i', $prefix ) ) {
			// Validate only the standalone prefix; never parse or rewrite the list tail.
			$clean = ultralove_rn_clean_description( $prefix );
			$clean['html'] .= substr( $html, $list[0][1] );
			$clean['review'][] = 'Structured list tail: left unchanged.';
			return $clean;
		}
	}
	if ( ! $loaded || $errors || preg_match( '/<(?:script|style|textarea|iframe|ul|ol|table|div)\b/i', $html ) ) {
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
			if ( 'b' === $name || 'br' === $name || 'u' === $name ) {
				$safe = $safe && (bool) preg_match( '/^<(?:b|br|u)\s*\/?>$/i', $tag[0] );
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
			foreach ( 'important' === $field ? ultralove_rn_description_condition( $text ) : array( $text ) as $note ) {
				$result[ $field ][] = $note;
			}
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
function ultralove_rn_description_after( array $before, ?array &$review = null ): array {
	$after = $before;
	$review = array();
	$notes = array();
	foreach ( array( 'info', 'important' ) as $field ) {
		$notes[ $field ] = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $before[ $field ] ) ), 'strlen' ) );
	}
	foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
		$clean = ultralove_rn_clean_description( $before[ $field ] );
		foreach ( $clean['review'] as $reason ) {
			$review[] = $field . ': ' . $reason;
		}
		$after[ $field ] = $clean['html'];
		foreach ( array( 'info', 'important' ) as $destination ) {
			foreach ( $clean[ $destination ] as $note ) {
				$notes[ $destination ][] = $note;
			}
		}
	}
	$notes = ultralove_rn_merge_notes( $notes );
	$review = array_merge( $review, $notes['review'] );
	foreach ( array( 'info', 'important' ) as $field ) {
		$original = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $before[ $field ] ) ), 'strlen' ) );
		if ( $original !== $notes[ $field ] ) {
			$after[ $field ] = implode( "\n", $notes[ $field ] );
		}
	}
	return $after;
}
