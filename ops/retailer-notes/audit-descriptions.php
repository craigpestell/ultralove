<?php
/** Read-only catalog inventory; run outside the webroot with wp eval-file. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through wp eval-file.' );
}
global $wpdb;
$products = $wpdb->get_results( "SELECT ID, post_title, post_name, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID", ARRAY_A );
$rows = array();
foreach ( $products as $product ) {
	foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
		$html = $product[ $field ];
		$text = ultralove_rn_clean_text( $html );
		if ( ! preg_match( '/(?:\b(?:one|1)\s+(?:(?:colou?r|bottle|stand)\s+)?per\s+(?:store|tester)\b|\bfree\s+with\b|\bminimum\s+of\s+\d+\s+units?\b|\bdiscounts?\s+(?:do\s+not\s+)?apply\b|\bno\s+(?:further\s+)?discounts\b|\bmust\s+order\b)/iu', $text ) ) {
			continue;
		}
		$clean = ultralove_rn_clean_description( $html );
		$rows[] = array(
			'id' => (int) $product['ID'],
			'title' => $product['post_title'],
			'slug' => $product['post_name'],
			'field' => $field,
			'html' => $html,
			'post_content' => $product['post_content'],
			'post_excerpt' => $product['post_excerpt'],
			'info' => get_post_meta( $product['ID'], ULTRALOVE_RN_INFO, true ),
			'important' => get_post_meta( $product['ID'], ULTRALOVE_RN_IMPORTANT, true ),
			'parser_changes' => $clean['changes'],
			'parser_review' => $clean['review'],
		);
	}
}
echo wp_json_encode( array( 'site' => home_url(), 'scanned' => count( $products ), 'rows' => $rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
