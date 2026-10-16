<?php
/**
 * Plugin Name: Product Retailer Notes
 * Description: Structured retailer terms on every product (limit per store, free with N units, discounts do not apply, status tags), filled from the <b>/<font> notes that used to sit inside product names. Shown on product cards and product pages. Includes the WP-CLI migrations (wp ul-retailer-notes preview|apply|restore|slug-preview|slug-apply|slug-restore|tidy-titles|terms-preview|terms-apply|terms-restore|terms-drop-legacy).
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ultralove-retailer-notes/notes.php';
require_once __DIR__ . '/ultralove-retailer-notes/terms-store.php';

const ULTRALOVE_RN_ORIGINAL = '_ul_original_title'; // the title as it was before the notes were cut out (for restore)

/* ---------------------------------------------------------------------------------------------
 * Showing the terms
 * ------------------------------------------------------------------------------------------ */

/**
 * Markup for a product's terms; '' when it has nothing to show. $context: card | single | quickview.
 * Cards show the terms only (short wording); the product page and quick view add the status tags.
 */
function ultralove_rn_markup( int $product_id, string $context ): string {
	$terms = ultralove_rn_get_terms( $product_id );
	$card  = 'card' === $context;
	$lines = ultralove_rn_terms_lines( $terms, $card ? 'card' : 'page' );
	$tags  = $card ? array() : ultralove_rn_terms_tag_labels( $terms );
	if ( ! $lines && ! $tags ) {
		return '';
	}
	$html = '<div class="ul-retailer-notes ul-retailer-notes--' . esc_attr( $context ) . '">';
	if ( $lines ) {
		$html .= '<p class="ul-retailer-notes__terms">';
		$html .= $card ? '' : '<strong class="ul-retailer-notes__label">' . esc_html__( 'Retailer terms:', 'ultralove' ) . '</strong>';
		foreach ( $lines as $line ) {
			$html .= '<span class="ul-retailer-notes__line">' . esc_html( $line ) . '</span>';
		}
		$html .= '</p>';
	}
	if ( $tags ) {
		$html .= '<p class="ul-retailer-notes__tags">';
		foreach ( $tags as $tag ) {
			$html .= '<span class="ul-retailer-notes__tag">' . esc_html( $tag ) . '</span>';
		}
		$html .= '</p>';
	}
	return $html . '</div>';
}

/**
 * Product cards (grids, carousels). A card that has terms wraps its name and the terms in one box that is
 * exactly as tall as the name's fixed title box, so the terms take lines from the name instead of making the
 * card taller. The class says how many title lines the terms need on desktop (d) and on phones (m); the
 * stylesheet turns that into the name's line clamp. Cards without terms are unchanged.
 */
add_action( 'woocommerce_shop_loop_item_title', 'ultralove_rn_card_open', 9 );
add_action( 'woocommerce_shop_loop_item_title', 'ultralove_rn_show_card', 12 );
add_action( 'woocommerce_shop_loop_item_title', 'ultralove_rn_card_close', 13 );
function ultralove_rn_card_lines(): array {
	global $product;
	return $product instanceof WC_Product ? ultralove_rn_terms_lines( ultralove_rn_get_terms( $product->get_id() ), 'card' ) : array();
}
function ultralove_rn_card_open() {
	$lines = ultralove_rn_card_lines();
	$GLOBALS['ultralove_rn_card_box'] = (bool) $lines;
	if ( $lines ) {
		printf(
			'<div class="ul-rn-box ul-rn-d%d ul-rn-m%d">',
			ultralove_rn_terms_reserved_lines( $lines, 24, 18 ),
			ultralove_rn_terms_reserved_lines( $lines, 17, 13 )
		);
	}
}
function ultralove_rn_show_card() {
	global $product;
	if ( $product instanceof WC_Product ) {
		echo ultralove_rn_markup( $product->get_id(), 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ultralove_rn_markup()
	}
}
function ultralove_rn_card_close() {
	if ( ! empty( $GLOBALS['ultralove_rn_card_box'] ) ) {
		echo '</div>';
	}
	$GLOBALS['ultralove_rn_card_box'] = false;
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

add_action( 'admin_enqueue_scripts', 'ultralove_rn_admin_scripts' );
function ultralove_rn_admin_scripts(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || 'product' !== $screen->post_type ) {
		return;
	}
	$file = '/ultralove-retailer-notes/admin-terms.js';
	wp_enqueue_script(
		'ultralove-retailer-terms',
		plugins_url( $file, __FILE__ ),
		array(),
		(string) filemtime( __DIR__ . $file ),
		true
	);
}

/** <option> list; $current is kept as an option even when it is not one of the usual choices. */
function ultralove_rn_admin_options( array $choices, string $current ): string {
	if ( '' !== $current && ! isset( $choices[ $current ] ) ) {
		$choices[ $current ] = $current;
	}
	$html = '';
	foreach ( $choices as $value => $label ) {
		$html .= '<option value="' . esc_attr( (string) $value ) . '"' . ( (string) $value === $current ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
	}
	return $html;
}

add_action( 'woocommerce_product_options_general_product_data', 'ultralove_rn_admin_fields' );
function ultralove_rn_admin_fields() {
	global $post;
	$terms = ultralove_rn_get_terms( $post ? (int) $post->ID : 0 );
	$limits = array( '' => __( 'No limit', 'ultralove' ) );
	foreach ( ultralove_rn_limit_options() as $slug => $option ) {
		$limits[ $slug ] = substr( $option['label'], strlen( 'Limit ' ) ); // "1 color per store"
	}
	$free = array( '0' => __( 'No free offer', 'ultralove' ) );
	foreach ( ultralove_rn_free_with_options() as $units ) {
		$free[ (string) $units ] = sprintf( /* translators: %d: number of units */ __( '%d units bought', 'ultralove' ), $units );
	}
	if ( $terms['free_with'] && ! isset( $free[ (string) $terms['free_with'] ] ) ) {
		$free[ (string) $terms['free_with'] ] = sprintf( __( '%d units bought', 'ultralove' ), $terms['free_with'] );
	}
	echo '<div class="options_group ul-rn-admin" data-ul-rn-admin>';
	echo '<input type="hidden" name="ul_rn_present" value="1">';
	echo '<p class="form-field"><strong>' . esc_html__( 'Retailer terms', 'ultralove' ) . '</strong><br><span class="description">' . esc_html__( 'Shown on product cards and the product page.', 'ultralove' ) . '</span></p>';

	echo '<p class="form-field ul_rn_limit_field"><label for="ul_rn_limit">' . esc_html__( 'Limit', 'ultralove' ) . '</label>';
	echo '<select id="ul_rn_limit" name="ul_rn_limit" class="select short">';
	foreach ( $limits as $slug => $label ) {
		$card = '' === $slug ? '' : ultralove_rn_limit_options()[ $slug ]['label'];
		echo '<option value="' . esc_attr( (string) $slug ) . '" data-card="' . esc_attr( $card ) . '"' . ( $slug === $terms['limit'] ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></p>';

	echo '<p class="form-field ul_rn_free_with_field"><label for="ul_rn_free_with">' . esc_html__( 'Free with', 'ultralove' ) . '</label>';
	echo '<select id="ul_rn_free_with" name="ul_rn_free_with" class="select short">' . ultralove_rn_admin_options( $free, (string) $terms['free_with'] ) . '</select> ';
	echo '<label class="ul-rn-inline"><input type="checkbox" class="checkbox" name="ul_rn_free_per" value="flavor"' . ( 'flavor' === $terms['free_per'] ? ' checked' : '' ) . '> ' . esc_html__( 'per flavor', 'ultralove' ) . '</label></p>';

	echo '<p class="form-field ul_rn_no_discounts_field"><label for="ul_rn_no_discounts">' . esc_html__( 'Discounts', 'ultralove' ) . '</label>';
	echo '<input type="checkbox" class="checkbox" id="ul_rn_no_discounts" name="ul_rn_no_discounts" value="1"' . ( $terms['no_discounts'] ? ' checked' : '' ) . '> ';
	echo '<span class="description">' . esc_html( ULTRALOVE_RN_DISCOUNTS_LABEL ) . '</span></p>';

	echo '<p class="form-field ul_rn_other_field"><label for="ul_rn_other">' . esc_html__( 'Other terms', 'ultralove' ) . '</label>';
	echo '<textarea id="ul_rn_other" name="ul_rn_other" rows="2" class="short" placeholder="' . esc_attr__( 'Rare wording only, one per line', 'ultralove' ) . '">' . esc_textarea( implode( "\n", $terms['other'] ) ) . '</textarea></p>';

	echo '<p class="form-field"><strong>' . esc_html__( 'Status tags', 'ultralove' ) . '</strong><br><span class="description">' . esc_html__( 'Shown on the product page only.', 'ultralove' ) . '</span></p>';
	echo '<p class="form-field ul_rn_tags_field"><label>&nbsp;</label><span class="ul-rn-tags">';
	foreach ( ultralove_rn_tag_options() as $slug => $option ) {
		echo '<label><input type="checkbox" class="checkbox" name="ul_rn_tags[]" value="' . esc_attr( $slug ) . '"' . ( in_array( $slug, $terms['tags'], true ) ? ' checked' : '' ) . '> ' . esc_html( $option['label'] ) . '</label>';
	}
	echo '</span></p>';

	echo '<p class="form-field"><label>' . esc_html__( 'Card preview', 'ultralove' ) . '</label><span class="ul-rn-preview" data-ul-rn-preview>';
	foreach ( ultralove_rn_terms_lines( $terms, 'card' ) as $line ) {
		echo '<span>' . esc_html( $line ) . '</span>';
	}
	echo '</span></p>';
	echo '<style>.ul-rn-admin .ul-rn-tags{display:inline-grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 20px;float:left;width:50%}.ul-rn-admin .ul-rn-tags label,.ul-rn-admin .ul-rn-inline{float:none;width:auto;margin:0}.ul-rn-admin .ul-rn-tags input{margin:0 6px 0 0}.ul-rn-admin .ul-rn-preview{display:inline-block;min-width:185px;max-width:230px;padding:2px 7px;border-radius:4px;background:#fbe8ef;color:#b3094b;font-size:12px;line-height:14px;font-weight:700}.ul-rn-admin .ul-rn-preview:empty::before{content:"None";font-weight:400}.ul-rn-admin .ul-rn-preview span{display:block}</style>';
	echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'ultralove_rn_admin_save' );
function ultralove_rn_admin_save( $post_id ) {
	// WooCommerce has already checked the nonce and the user's capability before this action.
	if ( ! isset( $_POST['ul_rn_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- other saves (quick edit, import) never touch the terms
		return;
	}
	$post = static fn( string $key ) => isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$terms = ultralove_rn_terms_blank();
	$terms['limit']        = sanitize_key( (string) $post( 'ul_rn_limit' ) );
	$terms['free_with']    = absint( $post( 'ul_rn_free_with' ) );
	$terms['free_per']     = sanitize_key( (string) $post( 'ul_rn_free_per' ) );
	$terms['no_discounts'] = '' !== (string) $post( 'ul_rn_no_discounts' );
	$terms['tags']         = array_map( 'sanitize_key', (array) $post( 'ul_rn_tags' ) );
	// Typed "other" lines that are really a limit or a free offer go to the matching control.
	$review = array();
	foreach ( preg_split( '/\R/', trim( sanitize_textarea_field( (string) $post( 'ul_rn_other' ) ) ) ) as $line ) {
		ultralove_rn_terms_add_line( $terms, $line, $review );
	}
	foreach ( $review as $reason ) {
		WC_Admin_Meta_Boxes::add_error( __( 'Retailer terms need review: ', 'ultralove' ) . $reason );
	}
	ultralove_rn_save_terms( (int) $post_id, $terms );
}

/* ---------------------------------------------------------------------------------------------
 * Moving the notes out of a product's name
 * ------------------------------------------------------------------------------------------ */

/**
 * Cut the notes out of one product's name and store them in the product's retailer terms. Existing terms are kept
 * (new notes are added to them). Returns false when no safe cleanup is available or the name would end up empty.
 */
function ultralove_rn_apply_to_product( int $product_id ): bool {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return false;
	}
	$title  = (string) get_post_field( 'post_title', $product_id, 'raw' );
	$result = ultralove_rn_split_title( $title );
	if ( $result['malformed'] || $result['review'] || '' === $result['title'] ) {
		return false;
	}
	$have_terms = ultralove_rn_get_terms( $product_id );
	$notes = ultralove_rn_merge_notes( ultralove_rn_terms_to_legacy( $have_terms ), $result );
	foreach ( $notes['review'] as $reason ) {
		error_log( 'Retailer notes product ' . $product_id . ': ' . $reason );
	}
	$changed = $result['changed'];
	$merged  = ultralove_rn_terms_from_legacy( $notes['info'], $notes['important'] );
	// A product still on the old fields moves to the terms field the first time it is imported.
	if ( $merged !== $have_terms || ( ! ultralove_rn_has_terms_meta( $product_id ) && ! ultralove_rn_terms_empty( $merged ) ) ) {
		$changed = true;
		ultralove_rn_save_terms( $product_id, $merged );
	}
	if ( $result['changed'] ) {
		if ( '' === (string) get_post_meta( $product_id, ULTRALOVE_RN_ORIGINAL, true ) ) {
			update_post_meta( $product_id, ULTRALOVE_RN_ORIGINAL, wp_slash( $title ) );
		}
		$product->set_name( $result['title'] );
		$product->save();
	}
	return $changed;
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
 * WP-CLI:  wp ul-retailer-notes preview|apply|restore|slug-preview|slug-apply|slug-restore|tidy-titles|terms-preview|terms-apply|terms-restore|terms-drop-legacy
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
		 * Undo: put back the original names and clear the retailer terms on every product that has a saved original.
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
				delete_post_meta( $id, ULTRALOVE_RN_TERMS );
				$n++;
			}
			WP_CLI::success( "Restored $n products." );
		}

		/** Ids of products with a value in either old field, optionally only those without terms yet. */
		private function legacy_products( array $assoc, bool $only_unmigrated ): array {
			global $wpdb;
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value <> '' ORDER BY post_id",
				ULTRALOVE_RN_INFO, ULTRALOVE_RN_IMPORTANT
			) ) );
			if ( $only_unmigrated ) {
				$ids = array_values( array_filter( $ids, static fn( $id ) => ! ultralove_rn_has_terms_meta( $id ) ) );
			}
			if ( ! empty( $assoc['ids'] ) ) {
				$ids = array_values( array_intersect( $ids, array_map( 'intval', explode( ',', $assoc['ids'] ) ) ) );
			}
			return ! empty( $assoc['limit'] ) ? array_slice( $ids, 0, (int) $assoc['limit'] ) : $ids;
		}

		/**
		 * Dry run: show how the two old fields convert to retailer terms. Changes nothing.
		 *
		 * @subcommand terms-preview
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 * [--limit=<n>]  First n products only.
		 */
		public function terms_preview( $args, $assoc ) {
			$stats    = array( 'products' => 0, 'limit' => 0, 'free_with' => 0, 'no_discounts' => 0, 'tags' => 0, 'other' => 0, 'review' => 0 );
			$mapping  = array();
			$others   = array();
			$reviews  = array();
			foreach ( $this->legacy_products( $assoc, true ) as $id ) {
				$info      = ultralove_rn_legacy_field_lines( $id, ULTRALOVE_RN_INFO );
				$important = ultralove_rn_legacy_field_lines( $id, ULTRALOVE_RN_IMPORTANT );
				$review    = array();
				$terms     = ultralove_rn_terms_from_legacy( $info, $important, $review );
				$stats['products']++;
				$stats['limit']        += '' !== $terms['limit'] ? 1 : 0;
				$stats['free_with']    += $terms['free_with'] ? 1 : 0;
				$stats['no_discounts'] += $terms['no_discounts'] ? 1 : 0;
				$stats['tags']         += $terms['tags'] ? 1 : 0;
				$stats['other']        += $terms['other'] ? 1 : 0;
				$stats['review']       += $review ? 1 : 0;
				$from = implode( ' / ', array_merge( $important, $info ) );
				$to   = implode( ' / ', ultralove_rn_terms_lines( $terms, 'card' ) ) . ( $terms['tags'] ? ' [tags: ' . implode( ', ', $terms['tags'] ) . ']' : '' );
				$mapping[ $from . "\t" . $to ] = ( $mapping[ $from . "\t" . $to ] ?? 0 ) + 1;
				foreach ( $terms['other'] as $line ) {
					$others[ $line ][] = $id;
				}
				foreach ( $review as $reason ) {
					$reviews[] = array( 'ID' => $id, 'reason' => $reason );
				}
			}
			WP_CLI::log( '== Summary (products still on the old fields) ==' );
			foreach ( $stats as $k => $v ) {
				WP_CLI::log( sprintf( '%-14s %d', $k, $v ) );
			}
			arsort( $mapping );
			$table = array();
			foreach ( array_slice( $mapping, 0, 60, true ) as $key => $n ) {
				list( $from, $to ) = explode( "\t", $key, 2 );
				$table[] = array( 'products' => $n, 'old_text' => $from, 'becomes' => $to );
			}
			WP_CLI::log( "\n== Old text -> terms (top 60 of " . count( $mapping ) . ') ==' );
			WP_CLI\Utils\format_items( 'table', $table, array( 'products', 'old_text', 'becomes' ) );
			if ( $others ) {
				$rows = array();
				foreach ( $others as $line => $ids ) {
					$rows[] = array( 'products' => count( $ids ), 'ids' => implode( ',', array_slice( $ids, 0, 5 ) ), 'kept as Other terms' => $line );
				}
				WP_CLI::log( "\n== Kept as Other terms (fits no control) ==" );
				WP_CLI\Utils\format_items( 'table', $rows, array( 'products', 'ids', 'kept as Other terms' ) );
			}
			if ( $reviews ) {
				WP_CLI::log( "\n== Needs a look ==" );
				WP_CLI\Utils\format_items( 'table', array_slice( $reviews, 0, 50 ), array( 'ID', 'reason' ) );
			}
			WP_CLI::success( 'Dry run only - nothing was changed.' );
		}

		/**
		 * Store retailer terms for every product that still has the old fields. The old fields are kept (as a
		 * backup, and so terms-restore can put them back); the terms take over as soon as they exist.
		 *
		 * @subcommand terms-apply
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 * [--limit=<n>]  First n products only.
		 */
		public function terms_apply( $args, $assoc ) {
			$done = 0;
			foreach ( $this->legacy_products( $assoc, true ) as $id ) {
				$terms = ultralove_rn_terms_from_legacy(
					ultralove_rn_legacy_field_lines( $id, ULTRALOVE_RN_INFO ),
					ultralove_rn_legacy_field_lines( $id, ULTRALOVE_RN_IMPORTANT )
				);
				$json = ultralove_rn_terms_encode( $terms );
				if ( '' === $json ) {
					continue;
				}
				update_post_meta( $id, ULTRALOVE_RN_TERMS, wp_slash( $json ) );
				$done++;
			}
			WP_CLI::success( "Stored retailer terms for $done products. The old fields are still there; run terms-drop-legacy once the result is checked. Clear the page caches." );
		}

		/**
		 * Undo terms-apply: write the old two fields back from the terms and remove the terms.
		 *
		 * @subcommand terms-restore
		 *
		 * ## OPTIONS
		 * [--ids=<ids>]  Comma-separated product IDs only.
		 */
		public function terms_restore( $args, $assoc ) {
			global $wpdb;
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", ULTRALOVE_RN_TERMS ) ) );
			if ( ! empty( $assoc['ids'] ) ) {
				$ids = array_values( array_intersect( $ids, array_map( 'intval', explode( ',', $assoc['ids'] ) ) ) );
			}
			foreach ( $ids as $id ) {
				$legacy = ultralove_rn_legacy_strings( $id );
				foreach ( array( ULTRALOVE_RN_INFO => 'info', ULTRALOVE_RN_IMPORTANT => 'important' ) as $key => $field ) {
					'' === $legacy[ $field ] ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, wp_slash( $legacy[ $field ] ) );
				}
				delete_post_meta( $id, ULTRALOVE_RN_TERMS );
			}
			WP_CLI::success( 'Restored the old fields on ' . count( $ids ) . ' products.' );
		}

		/**
		 * Delete the old two fields from every product that has retailer terms. Not undoable except from a database backup.
		 *
		 * @subcommand terms-drop-legacy
		 *
		 * ## OPTIONS
		 * [--yes]  Do it. Without this only reports how many products are affected.
		 */
		public function terms_drop_legacy( $args, $assoc ) {
			$n = 0;
			foreach ( $this->legacy_products( array(), false ) as $id ) {
				if ( ! ultralove_rn_has_terms_meta( $id ) ) {
					continue;
				}
				$n++;
				if ( isset( $assoc['yes'] ) ) {
					delete_post_meta( $id, ULTRALOVE_RN_INFO );
					delete_post_meta( $id, ULTRALOVE_RN_IMPORTANT );
				}
			}
			WP_CLI::success( isset( $assoc['yes'] ) ? "Deleted the old fields on $n products." : "$n products still carry the old fields next to their terms. Run again with --yes to delete them." );
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
