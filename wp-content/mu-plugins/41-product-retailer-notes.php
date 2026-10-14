<?php
/**
 * Plugin Name: Product Retailer Notes
 * Description: Two product fields - "Retailer info" and "Important retailer info" - filled from the <b>/<font> notes that used to sit inside product names. Shown on product cards and product pages. Includes the WP-CLI migration (wp ul-retailer-notes preview|apply|restore).
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ultralove-retailer-notes/parser.php';

const ULTRALOVE_RN_INFO      = '_ul_retailer_info';
const ULTRALOVE_RN_IMPORTANT = '_ul_retailer_important';
const ULTRALOVE_RN_ORIGINAL  = '_ul_original_title'; // the title as it was before the notes were cut out (for restore)

/* ---------------------------------------------------------------------------------------------
 * Reading and showing the fields
 * ------------------------------------------------------------------------------------------ */

/** @return array{info:string[],important:string[]} one entry per line of each field */
function ultralove_rn_get( int $product_id ): array {
	$lines = static function ( string $key ) use ( $product_id ): array {
		$raw = (string) get_post_meta( $product_id, $key, true );
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $raw ) ) ) );
	};
	return array( 'info' => $lines( ULTRALOVE_RN_INFO ), 'important' => $lines( ULTRALOVE_RN_IMPORTANT ) );
}

/** Markup for a product's notes; '' when it has none. $context: card | single | quickview. */
function ultralove_rn_markup( int $product_id, string $context ): string {
	$notes = ultralove_rn_get( $product_id );
	if ( ! $notes['info'] && ! $notes['important'] ) {
		return '';
	}
	$labels = 'card' !== $context; // cards are compact: no labels
	$html   = '<div class="ul-retailer-notes ul-retailer-notes--' . esc_attr( $context ) . '">';
	if ( $notes['important'] ) {
		$html .= '<p class="ul-retailer-notes__important">';
		$html .= $labels ? '<strong class="ul-retailer-notes__label">' . esc_html__( 'Important retailer info:', 'ultralove' ) . '</strong> ' : '';
		$html .= esc_html( implode( ' | ', $notes['important'] ) ) . '</p>';
	}
	if ( $notes['info'] ) {
		$html .= '<p class="ul-retailer-notes__info">';
		$html .= $labels ? '<strong class="ul-retailer-notes__label">' . esc_html__( 'Retailer info:', 'ultralove' ) . '</strong> ' : '';
		$html .= esc_html( implode( ' | ', $notes['info'] ) ) . '</p>';
	}
	return $html . '</div>';
}

// Product cards (grids, carousels): inside the card's details, under the name and brand.
add_action( 'woocommerce_shop_loop_item_title', 'ultralove_rn_show_card', 20 );
function ultralove_rn_show_card() {
	global $product;
	if ( $product instanceof WC_Product ) {
		echo ultralove_rn_markup( $product->get_id(), 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ultralove_rn_markup()
	}
}

// Product page: right under the header block (name, brand, SKU, stock), before the price.
add_action( 'woocommerce_single_product_summary', 'ultralove_rn_show_single', 11 );
function ultralove_rn_show_single() {
	global $product;
	if ( $product instanceof WC_Product ) {
		echo ultralove_rn_markup( $product->get_id(), 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

// Quick view popup.
add_action( 'martfury_single_product_summary', 'ultralove_rn_show_quickview', 6 );
function ultralove_rn_show_quickview() {
	global $product;
	if ( $product instanceof WC_Product ) {
		echo ultralove_rn_markup( $product->get_id(), 'quickview' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/* ---------------------------------------------------------------------------------------------
 * Editing in the product screen (General tab)
 * ------------------------------------------------------------------------------------------ */

add_action( 'woocommerce_product_options_general_product_data', 'ultralove_rn_admin_fields' );
function ultralove_rn_admin_fields() {
	echo '<div class="options_group">';
	woocommerce_wp_textarea_input( array(
		'id'          => ULTRALOVE_RN_IMPORTANT,
		'label'       => __( 'Important retailer info', 'ultralove' ),
		'description' => __( 'Shown highlighted on product cards and the product page, e.g. TESTER ONE PER STORE ONLY. One note per line.', 'ultralove' ),
		'desc_tip'    => true,
		'rows'        => 2,
	) );
	woocommerce_wp_textarea_input( array(
		'id'          => ULTRALOVE_RN_INFO,
		'label'       => __( 'Retailer info', 'ultralove' ),
		'description' => __( 'Other notes for retailers, e.g. DISCOUNTS DO NOT APPLY, WHILE STOCK LASTS. One note per line.', 'ultralove' ),
		'desc_tip'    => true,
		'rows'        => 2,
	) );
	echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'ultralove_rn_admin_save' );
function ultralove_rn_admin_save( $post_id ) {
	// WooCommerce has already checked the nonce and the user's capability before this action.
	foreach ( array( ULTRALOVE_RN_INFO, ULTRALOVE_RN_IMPORTANT ) as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			continue;
		}
		$value = trim( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}
}

/* ---------------------------------------------------------------------------------------------
 * Moving the notes out of a product's name
 * ------------------------------------------------------------------------------------------ */

/**
 * Cut the notes out of one product's name and store them in the two fields. Existing field text is kept
 * (new notes are added to it). Returns false when the name has no <b>/<font> markup or would end up empty.
 */
function ultralove_rn_apply_to_product( int $product_id ): bool {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return false;
	}
	$title  = (string) get_post_field( 'post_title', $product_id, 'raw' );
	$result = ultralove_rn_split_title( $title );
	if ( ! $result['changed'] || '' === $result['title'] ) {
		return false;
	}
	if ( '' === (string) get_post_meta( $product_id, ULTRALOVE_RN_ORIGINAL, true ) ) {
		update_post_meta( $product_id, ULTRALOVE_RN_ORIGINAL, wp_slash( $title ) );
	}
	$have = ultralove_rn_get( $product_id );
	foreach ( array( ULTRALOVE_RN_INFO => 'info', ULTRALOVE_RN_IMPORTANT => 'important' ) as $key => $field ) {
		$merged = $have[ $field ];
		foreach ( $result[ $field ] as $text ) {
			if ( ! in_array( mb_strtoupper( $text ), array_map( 'mb_strtoupper', $merged ), true ) ) {
				$merged[] = $text;
			}
		}
		if ( $merged ) {
			update_post_meta( $product_id, $key, wp_slash( implode( "\n", $merged ) ) );
		}
	}
	$product->set_name( $result['title'] );
	$product->save();
	return true;
}

// WP All Import rewrites titles from the supplier file, markup included; clean each product as it is saved.
// (Hook name and argument per WP All Import's documented pmxi_saved_post action.)
add_action( 'pmxi_saved_post', 'ultralove_rn_after_import', 20, 1 );
function ultralove_rn_after_import( $post_id ) {
	if ( 'product' === get_post_type( $post_id ) && apply_filters( 'ultralove_retailer_notes_clean_on_import', true ) ) {
		ultralove_rn_apply_to_product( (int) $post_id );
	}
}

/* ---------------------------------------------------------------------------------------------
 * WP-CLI:  wp ul-retailer-notes preview|apply|restore
 * ------------------------------------------------------------------------------------------ */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Move <b>/<font> notes out of product names into the retailer info fields.
	 */
	class Ultralove_Retailer_Notes_CLI {

		/** Products whose name still holds <b>/<font> markup (all statuses but trash). */
		private function candidates( array $assoc ): array {
			global $wpdb;
			$rows = $wpdb->get_results(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')
				 AND (post_title LIKE '%<b>%' OR post_title LIKE '%<b %' OR post_title LIKE '%<font%' OR post_title LIKE '%</b>%' OR post_title LIKE '%</font>%')
				 ORDER BY ID", ARRAY_A
			);
			if ( ! empty( $assoc['ids'] ) ) {
				$ids  = array_map( 'intval', explode( ',', $assoc['ids'] ) );
				$rows = array_values( array_filter( $rows, static fn( $r ) => in_array( (int) $r['ID'], $ids, true ) ) );
			}
			return ! empty( $assoc['limit'] ) ? array_slice( $rows, 0, (int) $assoc['limit'] ) : $rows;
		}

		/**
		 * Dry run: change nothing, report what would happen.
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]   Comma-separated product IDs only.
		 * [--limit=<n>]   First n matching products only.
		 * [--samples=<n>] Before/after examples to print (default 12).
		 */
		public function preview( $args, $assoc ) {
			$rows    = $this->candidates( $assoc );
			$counts  = array();
			$stats   = array( 'products' => count( $rows ), 'with_important' => 0, 'with_info' => 0, 'empty_title' => 0, 'malformed' => 0, 'other_tags_left' => 0 );
			$samples = array();
			$flagged = array();
			foreach ( $rows as $row ) {
				try {
					$r = ultralove_rn_split_title( $row['post_title'] );
				} catch ( RuntimeException $e ) {
					$flagged[] = array( 'ID' => $row['ID'], 'problem' => $e->getMessage(), 'title' => $row['post_title'] );
					continue;
				}
				$stats['with_important'] += $r['important'] ? 1 : 0;
				$stats['with_info']      += $r['info'] ? 1 : 0;
				foreach ( $r['segments'] as $text ) {
					$kind = in_array( $text, $r['important'], true ) || in_array( preg_replace( '/^ESTER\b/i', 'TESTER', $text ), $r['important'], true ) ? 'IMPORTANT' : 'info';
					$counts[ $kind . "\t" . mb_strtoupper( $text ) ] = ( $counts[ $kind . "\t" . mb_strtoupper( $text ) ] ?? 0 ) + 1;
				}
				foreach ( array( 'empty_title' => '' === $r['title'], 'malformed' => $r['malformed'], 'other_tags_left' => $r['other_tags'] ) as $flag => $on ) {
					if ( $on ) {
						$stats[ $flag ]++;
						$flagged[] = array( 'ID' => $row['ID'], 'problem' => $flag, 'title' => $row['post_title'] );
					}
				}
				if ( count( $samples ) < (int) ( $assoc['samples'] ?? 12 ) && ( $r['important'] || count( $samples ) % 2 === 0 ) ) {
					$samples[] = array( 'ID' => $row['ID'], 'before' => $row['post_title'], 'after' => $r['title'], 'important' => implode( ' / ', $r['important'] ), 'info' => implode( ' / ', $r['info'] ) );
				}
			}
			arsort( $counts );
			$table = array();
			foreach ( array_slice( $counts, 0, 80, true ) as $key => $n ) {
				list( $kind, $text ) = explode( "\t", $key, 2 );
				$table[] = array( 'goes_to' => $kind, 'count' => $n, 'text' => $text );
			}
			WP_CLI::log( "== Summary ==" );
			foreach ( $stats as $k => $v ) {
				WP_CLI::log( sprintf( '%-18s %d', $k, $v ) );
			}
			WP_CLI::log( "\n== Distinct note texts (top 80 of " . count( $counts ) . ") - check nothing here is really part of a product name ==" );
			WP_CLI\Utils\format_items( 'table', $table, array( 'goes_to', 'count', 'text' ) );
			WP_CLI::log( "\n== Before / after examples ==" );
			WP_CLI\Utils\format_items( 'table', $samples, array( 'ID', 'before', 'after', 'important', 'info' ) );
			if ( $flagged ) {
				WP_CLI::log( "\n== Needs a look (apply skips products whose name would be empty) ==" );
				WP_CLI\Utils\format_items( 'table', array_slice( $flagged, 0, 50 ), array( 'ID', 'problem', 'title' ) );
			}
			WP_CLI::success( 'Dry run only - nothing was changed.' );
		}

		/**
		 * Do it: store the notes in the two fields and clean the product names.
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 * [--limit=<n>]  First n matching products only.
		 */
		public function apply( $args, $assoc ) {
			$done = $skipped = 0;
			foreach ( $this->candidates( $assoc ) as $row ) {
				try {
					$ok = ultralove_rn_apply_to_product( (int) $row['ID'] );
				} catch ( Throwable $e ) {
					WP_CLI::warning( "Product {$row['ID']}: " . $e->getMessage() );
					$ok = false;
				}
				$ok ? $done++ : $skipped++;
			}
			WP_CLI::success( "Cleaned $done products; skipped $skipped (empty name, no markup or error). Originals are kept in the _ul_original_title field - use 'restore' to undo." );
		}

		/**
		 * Undo: put back the original names and clear both fields on every product that has a saved original.
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 */
		public function restore( $args, $assoc ) {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", ULTRALOVE_RN_ORIGINAL ) );
			if ( ! empty( $assoc['ids'] ) ) {
				$ids = array_intersect( $ids, array_map( 'strval', array_map( 'intval', explode( ',', $assoc['ids'] ) ) ) );
			}
			$n = 0;
			foreach ( $ids as $id ) {
				$product = wc_get_product( (int) $id );
				if ( ! $product ) {
					continue;
				}
				$product->set_name( (string) get_post_meta( $id, ULTRALOVE_RN_ORIGINAL, true ) );
				$product->save();
				delete_post_meta( $id, ULTRALOVE_RN_ORIGINAL );
				delete_post_meta( $id, ULTRALOVE_RN_INFO );
				delete_post_meta( $id, ULTRALOVE_RN_IMPORTANT );
				$n++;
			}
			WP_CLI::success( "Restored $n products." );
		}
	}
	WP_CLI::add_command( 'ul-retailer-notes', 'Ultralove_Retailer_Notes_CLI' );
}
