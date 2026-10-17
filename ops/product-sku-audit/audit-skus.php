<?php
/**
 * Read-only audit: SKU embedded in the product title vs the dedicated SKU field.
 *
 * Run outside the web root, one environment at a time:
 *   ssh ultralove-dev-app 'wp --allow-root --path=/var/www/html eval-file -' \
 *     < ops/product-sku-audit/audit-skus.php > sku-audit-dev.csv
 * CSV goes to stdout; the summary goes to stderr. Nothing is written to the site.
 */

if ( ! function_exists( 'ultralove_sku_audit_classify' ) ) {
	/** Plain text of a title: tags stripped, entities decoded, whitespace collapsed. */
	function ultralove_sku_audit_text( $s ) {
		$s = html_entity_decode( strip_tags( (string) $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = str_replace( "\xC2\xA0", ' ', $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Separators that may sit between SKU and name, plus loose-compare noise. */
	function ultralove_sku_audit_loose( $s ) {
		return strtolower( preg_replace( '/[\s\-_.\x{2013}\x{2014}]+/u', '', $s ) );
	}

	/**
	 * Compare a title with the SKU field.
	 *
	 * result: match | case_only | format_only | mismatch | no_sku_field | empty_title
	 * Returns result, name_sku (what the title starts with), stripped_name (title minus its SKU prefix).
	 */
	function ultralove_sku_audit_classify( $title, $sku ) {
		$title = ultralove_sku_audit_text( $title );
		$sku   = ultralove_sku_audit_text( $sku );
		$out   = array( 'result' => '', 'name_sku' => '', 'stripped_name' => $title );

		if ( '' === $title ) {
			$out['result'] = 'empty_title';
			return $out;
		}
		$first_token = preg_match( '/^\S+/u', $title, $m ) ? $m[0] : '';
		$strip       = static function ( $t, $len ) {
			return trim( preg_replace( '/^[\s\-\x{2013}\x{2014}:|]+/u', '', mb_substr( $t, $len ) ) );
		};

		if ( '' === $sku ) {
			$out['result']        = 'no_sku_field';
			$out['name_sku']      = $first_token;
			$out['stripped_name'] = $strip( $title, mb_strlen( $first_token ) );
			return $out;
		}

		$len  = mb_strlen( $sku );
		$head = mb_substr( $title, 0, $len );
		$next = mb_substr( $title, $len, 1 );
		$edge = ( '' === $next || ! preg_match( '/^[\p{L}\p{N}]$/u', $next ) );

		if ( $edge && $head === $sku ) {
			$out['result'] = 'match';
		} elseif ( $edge && mb_strtolower( $head ) === mb_strtolower( $sku ) ) {
			$out['result'] = 'case_only';
		}
		if ( $out['result'] ) {
			$out['name_sku']      = $head;
			$out['stripped_name'] = $strip( $title, $len );
			return $out;
		}

		// Same characters once spaces/dashes/dots are ignored (e.g. "AB-12" vs "AB12").
		$loose_sku = ultralove_sku_audit_loose( $sku );
		$acc       = '';
		$n         = mb_strlen( $title );
		for ( $i = 0; $i < $n; $i++ ) {
			$acc = ultralove_sku_audit_loose( mb_substr( $title, 0, $i + 1 ) );
			if ( strlen( $acc ) >= strlen( $loose_sku ) ) {
				break;
			}
		}
		$after = mb_substr( $title, $i + 1, 1 );
		if ( $acc === $loose_sku && ( '' === $after || ! preg_match( '/^[\p{L}\p{N}]$/u', $after ) ) ) {
			$out['result']        = 'format_only';
			$out['name_sku']      = mb_substr( $title, 0, $i + 1 );
			$out['stripped_name'] = $strip( $title, $i + 1 );
			return $out;
		}

		$out['result']        = 'mismatch';
		$out['name_sku']      = $first_token;
		$out['stripped_name'] = $strip( $title, mb_strlen( $first_token ) );
		return $out;
	}
}

if ( defined( 'ULTRALOVE_SKU_AUDIT_LIB_ONLY' ) ) {
	return;
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through wp eval-file.' );
}

global $wpdb;
$products = $wpdb->get_results(
	"SELECT p.ID, p.post_title, p.post_name, p.post_status, pm.meta_value AS sku
	 FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
	 WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft','inherit')
	 ORDER BY p.ID",
	ARRAY_A
);

$rows       = array();
$sku_counts = array();
$name_counts = array();
foreach ( $products as $p ) {
	$c   = ultralove_sku_audit_classify( $p['post_title'], (string) $p['sku'] );
	$sku = ultralove_sku_audit_text( (string) $p['sku'] );
	$row = array(
		'id'            => (int) $p['ID'],
		'status'        => $p['post_status'],
		'result'        => $c['result'],
		'sku_field'     => $sku,
		'sku_in_name'   => $c['name_sku'],
		'title'         => ultralove_sku_audit_text( $p['post_title'] ),
		'stripped_name' => $c['stripped_name'],
		'slug'          => $p['post_name'],
	);
	$rows[] = $row;
	if ( '' !== $sku ) {
		$k = strtolower( $sku );
		$sku_counts[ $k ] = ( $sku_counts[ $k ] ?? 0 ) + 1;
	}
	$k = strtolower( $c['stripped_name'] );
	$name_counts[ $k ] = ( $name_counts[ $k ] ?? 0 ) + 1;
}

$out = fopen( 'php://stdout', 'w' );
fputcsv( $out, array( 'id', 'status', 'result', 'sku_field', 'sku_in_name', 'dup_sku_field', 'dup_stripped_name', 'title', 'stripped_name', 'slug', 'edit_url' ), ',', '"', '' );
$summary = array();
foreach ( $rows as $r ) {
	$summary[ $r['result'] ] = ( $summary[ $r['result'] ] ?? 0 ) + 1;
	$dup_sku  = '' !== $r['sku_field'] && $sku_counts[ strtolower( $r['sku_field'] ) ] > 1 ? $sku_counts[ strtolower( $r['sku_field'] ) ] : '';
	$dup_name = $name_counts[ strtolower( $r['stripped_name'] ) ] > 1 ? $name_counts[ strtolower( $r['stripped_name'] ) ] : '';
	fputcsv( $out, array( $r['id'], $r['status'], $r['result'], $r['sku_field'], $r['sku_in_name'], $dup_sku, $dup_name, $r['title'], $r['stripped_name'], $r['slug'], admin_url( 'post.php?post=' . $r['id'] . '&action=edit' ) ), ',', '"', '' );
}
fclose( $out );

fwrite( STDERR, home_url() . ': ' . count( $rows ) . " products\n" );
ksort( $summary );
foreach ( $summary as $k => $v ) {
	fwrite( STDERR, sprintf( "  %-14s %d\n", $k, $v ) );
}
