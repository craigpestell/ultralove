<?php
/**
 * Splits supplier markup out of a product title.
 *
 * Pure PHP (no WordPress calls) so ops/retailer-notes/test-parser.php can run it with plain `php`.
 *
 * Titles look like "SKU<br> Name<br> Variant<br> <b><font color=red><font size="3">SALE PRICED</font></FONT></b>".
 * Every <b>...</b> and <font>...</font> element is cut out of the title, whole, and its text is returned
 * instead: text containing TESTER goes to "important", everything else to "info".
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
	 * Bold words that are part of the product's name (a variant or edition), not a retailer note. Their tags
	 * are removed but the words stay in the name. Whole-text match, case-insensitive.
	 */
	function ultralove_rn_keep_in_name(): array {
		return array( 'ELECTRIC', 'RECHARGEABLE', 'STANDARD', 'EACH', 'WITH APP', 'TRAVEL SIZE', 'PRIDE EDITION' );
	}

	/**
	 * @return array{title:string,info:string[],important:string[],segments:string[],changed:bool,malformed:bool,other_tags:bool}
	 *   title      the title with every <b>/<font> element removed (lines still joined with <br>)
	 *   segments   the text of each removed element, in order
	 *   changed    false when the title had no <b>/<font> markup (title is then returned untouched)
	 *   malformed  an unclosed <b>/<font>, or a closing tag with no opening one, was found: where the note starts is
	 *              unknown, so its text stays in the title and apply skips the product
	 *   other_tags the cleaned title still has tags other than <br> (e.g. <strong>)
	 */
	function ultralove_rn_split_title( string $title ): array {
		$segments  = array();
		$unwrapped = false;
		$keep      = ultralove_rn_keep_in_name();
		$token    = static function ( string $text ) use ( &$segments ): string {
			$segments[] = $text;
			return "\x01" . ( count( $segments ) - 1 ) . "\x02";
		};

		// <b> first (the usual wrapper), whole element, any attributes.
		$work = preg_replace_callback( '/<b\b[^>]*>(.*?)<\/b\s*>/is', static function ( array $m ) use ( $token, $keep, &$unwrapped ): string {
			$text = ultralove_rn_clean_text( $m[1] );
			if ( in_array( mb_strtoupper( $text ), $keep, true ) ) {
				$unwrapped = true; // part of the name: drop the tags, keep the words
				return ' ' . $text . ' ';
			}
			return ' ' . $token( $text ) . ' ';
		}, $title );

		// Then <font>, innermost first, until none are left. A font that only wraps already-extracted
		// elements is unwrapped; one with text of its own becomes a segment beside them.
		$font = '/<font\b[^>]*>((?:(?!<font\b).)*?)<\/font\s*>/is';
		for ( $i = 0; $i < 50 && preg_match( $font, $work ); $i++ ) {
			$work = preg_replace_callback( $font, static function ( array $m ) use ( $token, $keep, &$unwrapped ): string {
				preg_match_all( '/\x01\d+\x02/', $m[1], $inner );
				$own = ultralove_rn_clean_text( preg_replace( '/\x01\d+\x02/', ' ', $m[1] ) );
				if ( in_array( mb_strtoupper( $own ), $keep, true ) ) {
					$unwrapped = true;
					return ' ' . $own . ' ' . implode( ' ', $inner[0] ) . ' ';
				}
				return ' ' . ( '' !== $own ? $token( $own ) : '' ) . ' ' . implode( ' ', $inner[0] ) . ' ';
			}, $work );
		}

		// A closing tag straight after an extracted element is a harmless duplicate (<b>X</b></b>).
		$work      = preg_replace( '/(\x02)(?:\s*<\/(?:b|font)\s*>)+/i', '$1', $work );
		$malformed = (bool) preg_match( '/<\/?(?:b|font)\b[^>]*>/i', $work );
		$work      = preg_replace( '/<\/?(?:b|font)\b[^>]*>/i', ' ', $work );
		$changed   = $segments || $malformed || $unwrapped;

		if ( ! $changed ) {
			return array( 'title' => $title, 'info' => array(), 'important' => array(), 'segments' => array(), 'changed' => false, 'malformed' => false, 'other_tags' => false );
		}

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
		$clean = implode( '<br>', $lines );

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
			'title'      => $clean,
			'info'       => $info,
			'important'  => $important,
			'segments'   => array_values( array_filter( $segments, static fn( $t ) => '' !== $t ) ),
			'changed'    => true,
			'malformed'  => $malformed,
			'other_tags' => (bool) preg_match( '/<(?!br\b)[a-z\/]/i', $clean ),
		);
	}
}
