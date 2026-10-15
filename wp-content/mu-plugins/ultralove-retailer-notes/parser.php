<?php
/**
 * Splits supplier markup out of a product title.
 *
 * Pure PHP (no WordPress calls) so ops/retailer-notes/test-parser.php can run it with plain `php`.
 *
 * Titles look like "SKU<br> Name<br> Variant<br> <b><font color=red><font size="3">SALE PRICED</font></FONT></b>".
 * Retailer text in <b>...</b> and <font>...</font> elements becomes notes. Product identity
 * (including tester variants, kits and displays) stays in the name without its wrappers.
 * Recognized terminal retailer instructions also work without wrappers.
 */

if ( ! function_exists( 'ultralove_rn_clean_text' ) ) {

	/** Plain text of an HTML fragment: tags and entities gone, whitespace collapsed, surrounding "*" and spaces trimmed. */
	function ultralove_rn_clean_text( string $html ): string {
		$text = preg_replace( '/<br\s*\/?>/i', ' ', $html );
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\s\x{00a0}]+/u', ' ', $text );
		if ( null === $text ) {
			throw new RuntimeException( 'Cannot read invalid UTF-8 in a product title.' );
		}
		return trim( $text, " \t\n\r\0\x0B*" );
	}

	/**
	 * Historical URLs can predate the saved title (different SKU, spelling or note wording).
	 * Remove only an explicit retailer-instruction suffix, never regenerate the product-name prefix.
	 */
	function ultralove_rn_slug_remove_note_suffix( string $old_slug, string $kept, string $notes ): ?string {
		$free = 'free-with-(?:[0-9]+-units-bought|purchase-of-[0-9]+-essential-bullets)';
		$store = '(?:one|1)(?:-(?:colou?r|bottle))?-per-store(?:-only)?(?:-?' . $free . ')?';
		$instruction = '(?:discounts-do-not-apply|no-(?:further-)?discounts-apply|special-order-only|while-stock-lasts|sale-priced|seasonal-limited-supply|' . $store . '|' . $free . ')';
		$pattern = '/(' . $instruction . '(?:-?' . $instruction . ')*)(-[0-9]+)?$/';
		if ( ! preg_match( $pattern, $old_slug, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$removed = $match[1][0];
		preg_match_all( '/' . $instruction . '/', $removed, $parts );
		foreach ( $parts[0] as $part ) {
			if ( str_contains( str_replace( '-', '', $kept ), str_replace( '-', '', $part ) ) ) {
				return null;
			}
		}
		$prefix = rtrim( substr( $old_slug, 0, $match[1][1] ), '-' );
		if ( strlen( $prefix ) < 4 || ! preg_match( '/[a-z]/', $prefix ) || preg_match( '/^testers?(?:-only)?$/', $prefix ) || 1 !== substr_count( $old_slug, $removed ) ) {
			return null;
		}
		return $prefix . ( $match[2][0] ?? '' );
	}

	/**
	 * Bold words that are part of the product's name (a variant or edition), not a retailer note. Their tags
	 * are removed but the words stay in the name. Whole-text match, case-insensitive.
	 */
	function ultralove_rn_keep_in_name(): array {
		return array( 'ELECTRIC', 'RECHARGEABLE', 'STANDARD', 'EACH', 'WITH APP', 'TRAVEL SIZE', 'PRIDE EDITION', 'WITH TESTER', 'WITH TESTERS', 'DISPLAY FOR TESTER' );
	}

	/** Only whole store/purchase instructions qualify; unrelated notes on tester products stay regular. */
	function ultralove_rn_is_tester_offer( string $title, string $note ): bool {
		if ( ! preg_match( '/\bTESTERS?\b/i', ultralove_rn_clean_text( $title ) ) ) {
			return false;
		}
		$store = '(?:LIMIT )?(?:ONE|1)(?: (?:COLOU?R|BOTTLE))? PER STORE(?: ONLY)?';
		$free  = 'FREE WITH (?:[0-9]+ UNITS BOUGHT(?: PER FLAVOR)?|[0-9]+(?: \([0-9]+ EACH\))? PRODUCTS BOUGHT|[0-9]+ EACH OF THE [A-Z0-9]+ LINE PURCHASED|PURCHASE OF [0-9]+ ESSENTIAL BULLETS)';
		$part  = '(?:' . $store . '|' . $free . ')';
		return (bool) preg_match( '/^' . $part . '(?: ' . $part . ')*$/i', ultralove_rn_clean_text( $note ) );
	}

	/**
	 * Split a tester identity from a whole recognized offer; never infer identity from
	 * a tester mentioned only inside the offer (e.g. "FREE WITH TESTER & 3 UNITS BOUGHT").
	 *
	 * @return array{identity:string,note:string}|null
	 */
	function ultralove_rn_split_tester_identity( string $text ): ?array {
		$text = ultralove_rn_clean_text( $text );
		if ( preg_match( '/^ESTER (.+)$/i', $text, $typo ) && ultralove_rn_is_tester_offer( 'TESTER', $typo[1] ) ) {
			$text = 'TESTER ' . $typo[1];
		}
		if ( ! preg_match( '/\bTESTERS?\b/i', $text ) ) {
			return null;
		}
		if ( preg_match( '/^(.*?)\s*:?\s*\b((?:LIMIT )?(?:ONE|1)(?: (?:COLOU?R|BOTTLE))? PER STORE\b.*|FREE WITH\b.*)$/i', $text, $match )
			&& ultralove_rn_is_tester_offer( $match[1], $match[2] ) ) {
			return array( 'identity' => rtrim( $match[1], ' :' ), 'note' => $match[2] );
		}
		if ( preg_match( '/\b(?:PER STORE|FREE WITH|DISCOUNTS|SPECIAL ORDER|WHILE STOCK|SALE PRICED|LIMITED SUPPLY)\b/i', $text ) ) {
			return null;
		}
		return array( 'identity' => $text, 'note' => '' );
	}

	/**
	 * @return array{residual:string,title:string,info:string[],important:string[],segments:string[],changed:bool,malformed:bool,other_tags:bool,review:bool}
	 *   title      the title without retailer instructions or <b>/<font> wrappers (lines joined with "<br> ")
	 *   residual   the original title with only the extracted elements cut out, no padding (for slug work)
	 *   segments   the text of each removed element, in order
	 *   changed    false when no notes, name wrappers or duplicate markup were found
	 *   malformed  an unclosed <b>/<font>, or a closing tag with no opening one, was found: where the note starts is
	 *              unknown, so its text stays in the title and apply skips the product
	 *   other_tags the cleaned title still has tags other than <br> (e.g. <strong>)
	 *   review     a tester identity is mixed with an unrecognized instruction; do not apply automatically
	 */
	function ultralove_rn_split_title( string $title ): array {
		$segments  = array();
		$unwrapped = false;
		$review = false;
		$source    = preg_replace( '/<+(?=<br\s*\/?>)/i', '', $title );
		$repaired  = $source !== $title;
		$keep      = ultralove_rn_keep_in_name();
		$token    = static function ( string $text ) use ( &$segments, &$unwrapped, &$review ): string {
			$identity = '';
			$tester = ultralove_rn_split_tester_identity( $text );
			if ( null !== $tester ) {
				$unwrapped = true;
				$identity = $tester['identity'];
				$text = $tester['note'];
				if ( '' === $text ) {
					return $identity;
				}
				$identity .= ' ';
			} elseif ( preg_match( '/\b(?:TESTERS?|ESTER)\b/i', $text, $tester_word, PREG_OFFSET_CAPTURE )
				&& preg_match( '/\b(?:PER STORE|FREE WITH|DISCOUNTS|SPECIAL ORDER|WHILE STOCK|SALE PRICED|LIMITED SUPPLY)\b/i', $text, $instruction_word, PREG_OFFSET_CAPTURE )
				&& $tester_word[0][1] < $instruction_word[0][1] ) {
				$review = $unwrapped = true;
				return $text;
			}
			$segments[] = $text;
			return $identity . "\x01" . ( count( $segments ) - 1 ) . "\x02";
		};

		// <b> first (the usual wrapper), whole element, any attributes.
		$work = preg_replace_callback( '/<b\b[^>]*>(.*?)<\/b\s*>/is', static function ( array $m ) use ( $token, $keep, &$unwrapped ): string {
			$text = ultralove_rn_clean_text( $m[1] );
			if ( in_array( mb_strtoupper( $text ), $keep, true ) ) {
				$unwrapped = true; // part of the name: drop the tags, keep the words
				return "\x03" . $text . "\x03";
			}
			return "\x03" . $token( $text ) . "\x03";
		}, $source );

		// Then <font>, innermost first, until none are left. A font that only wraps already-extracted
		// elements is unwrapped; one with text of its own becomes a segment beside them.
		$font = '/<font\b[^>]*>((?:(?!<font\b).)*?)<\/font\s*>/is';
		for ( $i = 0; $i < 50 && preg_match( $font, $work ); $i++ ) {
			$work = preg_replace_callback( $font, static function ( array $m ) use ( $token, $keep, &$unwrapped ): string {
				preg_match_all( '/\x01\d+\x02/', $m[1], $inner );
				$own = ultralove_rn_clean_text( preg_replace( '/\x01\d+\x02|\x03/', ' ', $m[1] ) );
				if ( in_array( mb_strtoupper( $own ), $keep, true ) ) {
					$unwrapped = true;
					return "\x03" . $own . "\x03" . implode( "\x03", $inner[0] ) . "\x03";
				}
				return "\x03" . ( '' !== $own ? $token( $own ) : '' ) . "\x03" . implode( "\x03", $inner[0] ) . "\x03";
			}, $work );
		}

		// A closing tag straight after an extracted element is a harmless duplicate (<b>X</b></b>).
		$work      = preg_replace( '/(\x02)(?:[\s\x03]*<\/(?:b|font)\s*>)+/i', '$1', $work );
		// Only a known terminal instruction proves where an unwrapped note starts.
		$instruction = '(?:DISCOUNTS DO NOT APPLY|NO (?:FURTHER )?DISCOUNTS APPLY|SPECIAL ORDER ONLY|WHILE STOCK LASTS|SALE PRICED|SEASONAL LIMITED SUPPLY|(?:LIMIT )?(?:ONE|1)(?: (?:COLOU?R|BOTTLE))? PER STORE(?: ONLY)?|FREE WITH (?:[0-9]+ UNITS BOUGHT|PURCHASE OF [0-9]+ ESSENTIAL BULLETS))';
		$instruction = str_replace( ' ', '\s+', $instruction );
		$separator   = '(?:\s|<br\s*\/?>)+';
		$plain_note  = '/(' . $separator . ')(' . $instruction . '(?:' . $separator . $instruction . ')*)(?:\s*<\/(?:b|font)\s*>)*\s*$/i';
		$work = preg_replace_callback( $plain_note, static function ( array $m ) use ( $token ): string {
			return $m[1] . "\x03" . $token( ultralove_rn_clean_text( $m[2] ) ) . "\x03";
		}, $work );
		$malformed = (bool) preg_match( '/<\/?(?:b|font)\b[^>]*>/i', $work );
		$work      = preg_replace( '/<\/?(?:b|font)\b[^>]*>/i', "\x03", $work );
		$changed   = $segments || $malformed || $unwrapped || $repaired;

		if ( ! $changed ) {
			return array( 'residual' => $title, 'title' => $title, 'info' => array(), 'important' => array(), 'segments' => array(), 'changed' => false, 'malformed' => false, 'other_tags' => false, 'review' => false );
		}

		// What the title would read as with the extracted elements cut out and nothing added in their place:
		// this is what the product's old URL slug was made from, so the slug tool needs it exactly.
		$residual = preg_replace( '/\x01\d+\x02|\x03/', '', $work );
		$work     = str_replace( "\x03", ' ', $work );

		// Classify the segments in the order they appear. A segment is "important" when it mentions TESTER
		// (or the source's typo ESTER), or when it directly follows an important one and continues the same
		// instruction ("TESTER" <br> "ONE PER STORE ONLY" <br> "FREE WITH 3 UNITS BOUGHT").
		preg_match_all( '/\x01(\d+)\x02/', $work, $found, PREG_OFFSET_CAPTURE );
		$info = $important = array();
		$prev_end = null;
		$prev_important = false;
		foreach ( $found[1] as $n => $hit ) {
			$start = $found[0][ $n ][1];
			$text  = $segments[ (int) $hit[0] ];
			$gap   = null === $prev_end ? 'x' : ultralove_rn_clean_text( substr( $work, $prev_end, $start - $prev_end ) );
			$prev_end = $start + strlen( $found[0][ $n ][0] );
			if ( '' === $text ) {
				continue;
			}
			$is_important = (bool) preg_match( '/\b(?:TESTERS?|ESTER)\b/i', $text )
				|| ultralove_rn_is_tester_offer( $residual, $text )
				|| ( $prev_important && '' === $gap && preg_match( '/\b(?:PER (?:STORE|COLOU?R)|FREE WITH)\b/i', $text ) );
			$prev_important = $is_important;
			if ( $is_important ) {
				$important[] = preg_replace( '/^ESTER\b/i', 'TESTER', $text );
			} else {
				$info[] = $text;
			}
		}

		// Title: drop the tokens, tidy each <br>-separated line, drop empty lines.
		$lines = array();
		foreach ( preg_split( '/<br\s*\/?>/i', preg_replace( '/\x01\d+\x02/', '', $work ) ) as $line ) {
			$line = trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		$clean = implode( '<br> ', $lines ); // "<br> " keeps SKU and name apart wherever tags are stripped (SEO titles, search, emails)

		$dedupe = static function ( array $list ): array {
			$seen = array();
			foreach ( $list as $text ) {
				$seen[ mb_strtoupper( $text ) ] ??= $text;
			}
			return array_values( $seen );
		};
		$important = $dedupe( $important );
		$info      = array_values( array_filter( $dedupe( $info ), static fn( $t ) => ! in_array( mb_strtoupper( $t ), array_map( 'mb_strtoupper', $important ), true ) ) );

		return array(
			'residual'   => $residual,
			'title'      => $clean,
			'info'       => $info,
			'important'  => $important,
			'segments'   => array_values( array_filter( $segments, static fn( $t ) => '' !== $t ) ),
			'changed'    => true,
			'malformed'  => $malformed,
			'other_tags' => (bool) preg_match( '/<(?!br\b)[a-z\/]/i', $clean ),
			'review'     => $review,
		);
	}
}

if ( ! function_exists( 'ultralove_rn_slug_remove' ) ) {

	/**
	 * Take the part of a product's old URL slug that came from the removed notes out of it, leaving the rest
	 * (SKU, brand and so on, which the slug has but the name may not) as it is.
	 *
	 * $removed is the slug of the original title, $kept the slug of the same title with the notes cut out (both
	 * from sanitize_title( strip_tags( ... ) ), so they read the way the old slug was made: "...Book<br><b>SALE"
	 * becomes "...booksale", with no hyphen between the kept word and the note). What $removed has and $kept
	 * hasn't is looked up in $old_slug; it must be there exactly once and every word of $kept must still be in
	 * what remains, in order. Anything else returns null: the caller leaves that product's URL alone and reports it.
	 */
	function ultralove_rn_slug_remove( string $old_slug, string $removed, string $kept ): ?string {
		$limit = min( strlen( $removed ), strlen( $kept ) );
		$p     = 0;
		while ( $p < $limit && $removed[ $p ] === $kept[ $p ] ) {
			$p++;
		}
		$s = 0;
		while ( $s < $limit - $p && $removed[ strlen( $removed ) - 1 - $s ] === $kept[ strlen( $kept ) - 1 - $s ] ) {
			$s++;
		}
		$mid = substr( $removed, $p, strlen( $removed ) - $p - $s );
		if ( '' === $mid || 1 !== substr_count( $old_slug, $mid ) ) {
			return null;
		}
		$new = trim( (string) preg_replace( '/-+/', '-', str_replace( $mid, '-', $old_slug ) ), '-' );

		// Every word of $kept must still be there, in order.
		$have = explode( '-', $new );
		$want = '' === $kept ? array() : explode( '-', $kept );
		$j    = 0;
		foreach ( $have as $word ) {
			if ( $j < count( $want ) && $word === $want[ $j ] ) {
				$j++;
			}
		}
		return '' !== $new && $j === count( $want ) ? $new : null;
	}
}
