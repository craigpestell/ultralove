<?php
/**
 * Plugin Name: Product Retailer Notes
 * Description: Two product fields - "Retailer info" and "Important retailer info" - filled from the <b>/<font> notes that used to sit inside product names. Shown on product cards and product pages. Includes the WP-CLI migration (wp ul-retailer-notes preview|apply|restore|slug-preview|slug-apply|slug-restore|tidy-titles).
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

// Product page: original summary position and above the main description in its tab.
add_action( 'woocommerce_single_product_summary', 'ultralove_rn_show_single', 11 );
add_filter( 'woocommerce_product_tabs', 'ultralove_rn_description_tab', 98 );
function ultralove_rn_description_tab( array $tabs ): array {
	global $product;
	if ( ! $product instanceof WC_Product || '' === ultralove_rn_markup( $product->get_id(), 'single' ) ) {
		return $tabs;
	}
	if ( ! isset( $tabs['description'] ) ) {
		$tabs['description'] = array(
			'title' => __( 'Description', 'woocommerce' ),
			'priority' => 10,
			'callback' => 'woocommerce_product_description_tab',
		);
	}
	$callback = $tabs['description']['callback'] ?? null;
	if ( is_callable( $callback ) ) {
		$tabs['description']['callback'] = static function ( $key, $tab ) use ( $callback ) {
			ultralove_rn_show_single();
			call_user_func( $callback, $key, $tab );
		};
	}
	return $tabs;
}
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
 * (new notes are added to it). Returns false when no safe cleanup is available or the name would end up empty.
 */
function ultralove_rn_apply_to_product( int $product_id ): bool {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return false;
	}
	$title  = (string) get_post_field( 'post_title', $product_id, 'raw' );
	$result = ultralove_rn_split_title( $title );
	if ( ! $result['changed'] || $result['malformed'] || $result['review'] || '' === $result['title'] ) {
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
		if ( 'info' === $field ) {
			$promoted = array_map( 'mb_strtoupper', $result['important'] );
			$merged = array_values( array_filter( $merged, static fn( $text ) => ! in_array( mb_strtoupper( $text ), $promoted, true ) ) );
		}
		if ( $merged !== $have[ $field ] ) {
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
 * New URLs: take the removed notes out of the product's slug and redirect the old URL
 * ------------------------------------------------------------------------------------------ */

const ULTRALOVE_RN_PREV_SLUG      = '_ul_previous_slug';      // the slug before slug-apply (for slug-restore)
const ULTRALOVE_RN_REDIRECT_GROUP = 'Retailer notes slugs';   // Redirection plugin group holding the redirects

/**
 * Work out a migrated product's new slug without changing anything.
 *
 * Old slugs are not just the name (they carry the SKU and brand too), so the new slug is the old one with
 * only the part that came from the removed notes taken out - see ultralove_rn_slug_remove().
 *
 * @return array{status:string,old:string,new:string,detail:string}
 *   status: ok | same | done (already changed) | no-original | no-match (couldn't find the notes in the slug)
 *           | collision (another product has the new slug)
 */
function ultralove_rn_plan_slug( int $product_id ): array {
	$post = get_post( $product_id );
	$plan = array( 'status' => 'no-original', 'old' => $post ? (string) $post->post_name : '', 'new' => '', 'detail' => '' );
	if ( ! $post || 'product' !== $post->post_type ) {
		return $plan;
	}
	if ( '' !== (string) get_post_meta( $product_id, ULTRALOVE_RN_PREV_SLUG, true ) ) {
		$plan['status'] = 'done';
		return $plan;
	}
	$original = (string) get_post_meta( $product_id, ULTRALOVE_RN_ORIGINAL, true );
	if ( '' === $original || '' === $plan['old'] ) {
		return $plan;
	}
	$split = ultralove_rn_split_title( $original );
	if ( ! $split['changed'] || $split['malformed'] || $split['review'] ) {
		return $plan;
	}
	// The old slug was made by sanitize_title() over the markup-carrying title, so do the same to both versions.
	$was = sanitize_title( $original );
	$now = sanitize_title( $split['residual'] );
	if ( $was === $now && ! $split['segments'] ) {
		$plan['status'] = 'same';
		return $plan;
	}
	$new = $was === $now ? null : ultralove_rn_slug_remove( $plan['old'], $was, $now );
	if ( null === $new ) {
		$new = ultralove_rn_slug_remove_note_suffix(
			$plan['old'],
			sanitize_title( $split['title'] ),
			sanitize_title( implode( ' ', $split['segments'] ) )
		);
	}
	if ( null === $new ) {
		$plan['status'] = $was === $now ? 'same' : 'no-match';
		return $plan;
	}
	if ( $new === $plan['old'] ) {
		$plan['status'] = 'same';
		return $plan;
	}
	$unique = wp_unique_post_slug( $new, $product_id, $post->post_status, $post->post_type, $post->post_parent );
	if ( $unique !== $new && str_contains( $plan['old'], 'tester' ) && ! str_contains( $new, 'tester' ) ) {
		// Tester units can share a name with the retail product; keep that meaningful distinction.
		$new .= '-tester';
		$unique = wp_unique_post_slug( $new, $product_id, $post->post_status, $post->post_type, $post->post_parent );
	}
	$plan['new'] = $new;
	if ( $unique !== $new ) {
		$plan['status'] = 'collision';
		$plan['detail'] = "taken, WordPress would use $unique";
		return $plan;
	}
	$plan['status'] = 'ok';
	return $plan;
}

/** Id of the Redirection plugin group that holds our redirects (made if missing); 0 when the plugin isn't usable. */
function ultralove_rn_redirect_group(): int {
	global $wpdb;
	if ( ! class_exists( 'Red_Item' ) ) {
		return 0;
	}
	$table = $wpdb->prefix . 'redirection_groups';
	$wpdb->suppress_errors( true );
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table` WHERE name = %s", ULTRALOVE_RN_REDIRECT_GROUP ) );
	$wpdb->suppress_errors( false );
	if ( $id ) {
		return $id;
	}
	if ( class_exists( 'Red_Group' ) ) {
		$group = Red_Group::create( ULTRALOVE_RN_REDIRECT_GROUP, 1 ); // module 1 = WordPress
		if ( $group && ! is_wp_error( $group ) && method_exists( $group, 'get_id' ) ) {
			return (int) $group->get_id();
		}
	}
	return 0;
}

/** Add a 301 from $from to $to (paths) to the Redirection plugin. Returns created | exists | error text. */
function ultralove_rn_add_redirect( string $from, string $to, int $group_id ): string {
	global $wpdb;
	if ( ! $group_id ) {
		return 'Redirection plugin not available';
	}
	$table = $wpdb->prefix . 'redirection_items';
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE url = %s", $from ) ) > 0 ) {
		return 'exists';
	}
	$item = Red_Item::create( array(
		'url'         => $from,
		'match_type'  => 'url',
		'action_type' => 'url',
		'action_code' => 301,
		'action_data' => array( 'url' => $to ),
		'group_id'    => $group_id,
		'regex'       => false,
	) );
	return is_wp_error( $item ) ? $item->get_error_message() : 'created';
}

/**
 * Give one product its new slug and redirect the old URL. WordPress itself also remembers the old slug
 * (_wp_old_slug) and redirects it, so the URL keeps working even where the Redirection plugin isn't available.
 *
 * @return array{ok:bool,from:string,to:string,redirect:string}
 */
function ultralove_rn_apply_slug( int $product_id, array $plan, int $group_id ): array {
	$out      = array( 'ok' => false, 'from' => '', 'to' => '', 'redirect' => '' );
	$old_path = (string) wp_parse_url( get_permalink( $product_id ), PHP_URL_PATH );
	update_post_meta( $product_id, ULTRALOVE_RN_PREV_SLUG, $plan['old'] );
	$saved = wp_update_post( array( 'ID' => $product_id, 'post_name' => $plan['new'] ), true );
	clean_post_cache( $product_id );
	if ( is_wp_error( $saved ) || get_post_field( 'post_name', $product_id ) !== $plan['new'] ) {
		delete_post_meta( $product_id, ULTRALOVE_RN_PREV_SLUG );
		$out['redirect'] = is_wp_error( $saved ) ? $saved->get_error_message() : 'WordPress changed the slug to ' . get_post_field( 'post_name', $product_id );
		return $out;
	}
	$out['ok']       = true;
	$out['from']     = $old_path;
	$out['to']       = (string) wp_parse_url( get_permalink( $product_id ), PHP_URL_PATH );
	$out['redirect'] = ultralove_rn_add_redirect( $out['from'], $out['to'], $group_id );
	return $out;
}

/** Whether the Redirection plugin's classes are loaded. */
function ultralove_rn_plugin_ready(): bool {
	return class_exists( 'Red_Item' ) && class_exists( 'Red_Group' );
}

/* ---------------------------------------------------------------------------------------------
 * WP-CLI:  wp ul-retailer-notes preview|apply|restore|slug-preview|slug-apply|slug-restore|tidy-titles
 * ------------------------------------------------------------------------------------------ */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Move marked-up or recognized terminal notes into the retailer info fields.
	 */
	class Ultralove_Retailer_Notes_CLI {

		/** Products with a possible title cleanup (all statuses but trash). */
		private function candidates( array $assoc ): array {
			global $wpdb;
			$rows = $wpdb->get_results(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')
				 ORDER BY ID", ARRAY_A
			);
			$rows = array_values( array_filter( $rows, static fn( $row ) => ultralove_rn_split_title( $row['post_title'] )['changed'] ) );
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
			$stats   = array( 'products' => count( $rows ), 'with_important' => 0, 'with_info' => 0, 'empty_title' => 0, 'malformed' => 0, 'other_tags_left' => 0, 'tester_review' => 0 );
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
				foreach ( array( 'empty_title' => '' === $r['title'], 'malformed' => $r['malformed'], 'other_tags_left' => $r['other_tags'], 'tester_review' => $r['review'] ) as $flag => $on ) {
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
			WP_CLI::success( "Cleaned $done products; skipped $skipped (empty name, unsafe cleanup or error). Originals are kept in the _ul_original_title field - use 'restore' to undo." );
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

		/** Products that have been through the migration (they have a saved original title). */
		private function migrated( array $assoc ): array {
			global $wpdb;
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", ULTRALOVE_RN_ORIGINAL ) ) );
			if ( ! empty( $assoc['ids'] ) ) {
				$ids = array_values( array_intersect( $ids, array_map( 'intval', explode( ',', $assoc['ids'] ) ) ) );
			}
			return ! empty( $assoc['limit'] ) ? array_slice( $ids, 0, (int) $assoc['limit'] ) : $ids;
		}

		/**
		 * Dry run for new URLs: show each migrated product's old and new slug. Changes nothing.
		 *
		 * @subcommand slug-preview
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 * [--limit=<n>]  First n products only.
		 */
		public function slug_preview( $args, $assoc ) {
			$counts = array();
			$ok     = array();
			$other  = array();
			foreach ( $this->migrated( $assoc ) as $id ) {
				$plan = ultralove_rn_plan_slug( $id );
				$counts[ $plan['status'] ] = ( $counts[ $plan['status'] ] ?? 0 ) + 1;
				$row = array( 'ID' => $id, 'status' => $plan['status'], 'old_slug' => $plan['old'], 'new_slug' => $plan['new'], 'detail' => $plan['detail'] );
				if ( 'ok' === $plan['status'] ) {
					$ok[] = $row;
				} elseif ( in_array( $plan['status'], array( 'no-match', 'collision' ), true ) ) {
					$other[] = $row;
				}
			}
			WP_CLI::log( '== Summary ==' );
			foreach ( $counts as $status => $n ) {
				WP_CLI::log( sprintf( '%-12s %d', $status, $n ) );
			}
			WP_CLI::log( "\n== Will change (first 40 of " . count( $ok ) . ') ==' );
			WP_CLI\Utils\format_items( 'table', array_slice( $ok, 0, 40 ), array( 'ID', 'old_slug', 'new_slug' ) );
			if ( $other ) {
				WP_CLI::log( "\n== Left alone - need a look (first 60 of " . count( $other ) . ') ==' );
				WP_CLI\Utils\format_items( 'table', array_slice( $other, 0, 60 ), array( 'ID', 'status', 'old_slug', 'new_slug', 'detail' ) );
			}
			WP_CLI::log( "\nRedirection plugin: " . ( ultralove_rn_plugin_ready() ? 'found - redirects will be added to the group "' . ULTRALOVE_RN_REDIRECT_GROUP . '"' : 'NOT found - only WordPress\'s own old-slug redirect would apply' ) );
			WP_CLI::success( 'Dry run only - nothing was changed.' );
		}

		/**
		 * Give migrated products their new slugs and redirect each old URL to the new one.
		 *
		 * @subcommand slug-apply
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]   Comma-separated product IDs only.
		 * [--limit=<n>]   First n products only.
		 * [--csv=<file>]  Also write old and new paths, and the redirect result, to this CSV file.
		 */
		public function slug_apply( $args, $assoc ) {
			$group = ultralove_rn_redirect_group();
			if ( ! $group ) {
				WP_CLI::warning( 'The Redirection plugin is not usable here: only WordPress\'s own old-slug redirect will cover the old URLs.' );
			}
			$csv = ! empty( $assoc['csv'] ) ? fopen( $assoc['csv'], 'w' ) : null;
			$csv && fputcsv( $csv, array( 'id', 'old_path', 'new_path', 'redirect' ) );
			$changed = $skipped = $problems = 0;
			foreach ( $this->migrated( $assoc ) as $id ) {
				$plan = ultralove_rn_plan_slug( $id );
				if ( 'ok' !== $plan['status'] ) {
					$skipped++;
					continue;
				}
				$r = ultralove_rn_apply_slug( $id, $plan, $group );
				if ( ! $r['ok'] ) {
					WP_CLI::warning( "Product $id: slug not changed - " . $r['redirect'] );
					$problems++;
					continue;
				}
				if ( ! in_array( $r['redirect'], array( 'created', 'exists' ), true ) ) {
					WP_CLI::warning( "Product $id: new slug saved, redirect not added by Redirection ({$r['redirect']}); WordPress's own old-slug redirect still applies." );
					$problems++;
				}
				$csv && fputcsv( $csv, array( $id, $r['from'], $r['to'], $r['redirect'] ) );
				$changed++;
			}
			$csv && fclose( $csv );
			WP_CLI::success( "Changed $changed slugs; left $skipped alone (see slug-preview); $problems problems. Clear the page caches (and Cloudflare for /shop/ and the sitemaps)." );
		}

		/**
		 * Undo slug-apply: put the previous slugs back and delete the redirects made for them.
		 *
		 * @subcommand slug-restore
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 */
		public function slug_restore( $args, $assoc ) {
			global $wpdb;
			$group = ultralove_rn_redirect_group();
			$n     = 0;
			foreach ( $this->migrated( $assoc ) as $id ) {
				$prev = (string) get_post_meta( $id, ULTRALOVE_RN_PREV_SLUG, true );
				if ( '' === $prev ) {
					continue;
				}
				$new_path = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
				wp_update_post( array( 'ID' => $id, 'post_name' => $prev ) );
				clean_post_cache( $id );
				delete_post_meta( $id, ULTRALOVE_RN_PREV_SLUG );
				// The redirect from the old URL to the new one must go, or the restored URL would redirect away.
				$old_path = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
				if ( $group ) {
					$rule = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$wpdb->prefix}redirection_items` WHERE url = %s AND group_id = %d", $old_path, $group ) );
					$item = $rule && method_exists( 'Red_Item', 'get_by_id' ) ? Red_Item::get_by_id( $rule ) : false;
					if ( $item && ! is_wp_error( $item ) && method_exists( $item, 'delete' ) ) {
						$item->delete();
					} elseif ( $rule ) {
						WP_CLI::warning( "Redirect $old_path -> $new_path could not be deleted automatically: delete it (or the group \"" . ULTRALOVE_RN_REDIRECT_GROUP . '") in Tools > Redirection.' );
					}
				}
				$n++;
			}
			WP_CLI::success( "Restored $n slugs." );
		}

		/**
		 * Put a space after every <br> in the names of migrated products ("SKU<br>Name" -> "SKU<br> Name"), so SKU and
		 * name don't run together where tags are stripped (page titles, search, order emails). Safe to repeat.
		 *
		 * @subcommand tidy-titles
		 *
		 * ## OPTIONS
		 * [--apply]  Do it. Without this only reports.
		 */
		public function tidy_titles( $args, $assoc ) {
			$todo = 0;
			foreach ( $this->migrated( $assoc ) as $id ) {
				$title = (string) get_post_field( 'post_title', $id, 'raw' );
				$fixed = preg_replace( '/(<br\s*\/?>)(?=[^\s<])/i', '$1 ', $title );
				if ( $fixed === $title ) {
					continue;
				}
				$todo++;
				if ( isset( $assoc['apply'] ) ) {
					$product = wc_get_product( $id );
					if ( $product ) {
						$product->set_name( $fixed );
						$product->save();
					}
				}
			}
			WP_CLI::success( isset( $assoc['apply'] ) ? "Tidied $todo names." : "$todo names need tidying. Run again with --apply." );
		}
	}
	WP_CLI::add_command( 'ul-retailer-notes', 'Ultralove_Retailer_Notes_CLI' );
}
