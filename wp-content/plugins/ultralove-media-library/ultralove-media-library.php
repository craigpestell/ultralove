<?php
/**
 * Plugin Name: UltraLove Media Library
 * Description: Logged-in customers browse product images in My Account and download the originals, singly or as a bulk ZIP.
 * Version: 1.0.0
 * Author: UltraLove
 * Requires Plugins: woocommerce
 * Text Domain: ultralove-media-library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UltraLove_Media_Library {
	const VERSION      = '1.0.0';
	const ENDPOINT     = 'media-library';
	const PER_PAGE     = 50;
	const ZIP_MAX_PRODUCTS = 50;
	const ZIP_MAX_FILES    = 300;
	const ZIP_MAX_BYTES    = 314572800; // 300 MB of source files.
	const MIME_ALLOWED = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	public static function init() {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_ultralove_ml_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_ultralove_ml_zip', array( __CLASS__, 'zip' ) );
		add_action( 'admin_post_ultralove_ml_zip_product', array( __CLASS__, 'zip_product' ) );
		// Deploys rsync files and do not run activation hooks, so refresh rewrite rules once per version.
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
	}

	public static function maybe_flush_rewrites() {
		if ( get_option( 'ultralove_ml_rewrite_version' ) !== self::VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'ultralove_ml_rewrite_version', self::VERSION, false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public static function menu_items( $items ) {
		if ( ! self::can_access() ) {
			return $items;
		}
		$label = __( 'Media Library', 'ultralove-media-library' );
		$out   = array();
		foreach ( $items as $key => $text ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = $label;
			}
			$out[ $key ] = $text;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = $label;
		}
		return $out;
	}

	public static function title() {
		return __( 'Media Library', 'ultralove-media-library' );
	}

	/**
	 * Any logged-in account by default. Filter to narrow, e.g. to approved retailers.
	 */
	public static function can_access() {
		return (bool) apply_filters( 'ultralove_media_library_can_access', is_user_logged_in(), get_current_user_id() );
	}

	public static function enqueue() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ );
		// Version by file time: static assets are served with a one-year immutable cache header.
		$dir = plugin_dir_path( __FILE__ ) . 'assets/';
		$css = (string) @filemtime( $dir . 'media-library.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$js  = (string) @filemtime( $dir . 'media-library.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'ultralove-media-library', $url . 'assets/media-library.css', array(), self::VERSION . '.' . $css );
		wp_enqueue_script( 'ultralove-media-library', $url . 'assets/media-library.js', array(), self::VERSION . '.' . $js, true );
	}

	/* ---------- Browsing ---------- */

	public static function render() {
		if ( ! self::can_access() ) {
			echo '<p>' . esc_html__( 'The media library is not available for this account.', 'ultralove-media-library' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$search = isset( $_GET['ml_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ml_search'] ) ) : '';
		$cat    = isset( $_GET['ml_cat'] ) ? absint( $_GET['ml_cat'] ) : 0;
		$paged  = isset( $_GET['ml_page'] ) ? max( 1, absint( $_GET['ml_page'] ) ) : 1;
		// phpcs:enable

		$query = self::product_query( $search, $cat, $paged, self::PER_PAGE );
		$base  = wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );

		echo '<div class="ul-ml">';
		self::render_filters( $base, $search, $cat );

		if ( ! $query->have_posts() ) {
			echo '<p class="ul-ml__empty">' . esc_html__( 'No products with images matched.', 'ultralove-media-library' ) . '</p></div>';
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'ml_page', '%#%', $base ),
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'add_args'  => array_filter( array( 'ml_search' => $search, 'ml_cat' => $cat ) ),
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		// Page links appear above and below the grid so users need not scroll to change page.
		if ( $links ) {
			echo '<nav class="ul-ml__pages ul-ml__pages--top">' . wp_kses_post( $links ) . '</nav>';
		}

		echo '<form class="ul-ml__zipform" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ultralove_ml_zip">';
		wp_nonce_field( 'ultralove_ml_zip', 'ul_ml_nonce' );
		echo '<div class="ul-ml__bar">';
		echo '<label><input type="checkbox" class="ul-ml__all"> ' . esc_html__( 'Select all on this page', 'ultralove-media-library' ) . '</label> ';
		echo '</div><ul class="ul-ml__grid">';

		while ( $query->have_posts() ) {
			$query->the_post();
			self::render_card( get_the_ID() );
		}
		wp_reset_postdata();
		echo '</ul>';
		// Floating action bar: sticks to the bottom of the screen while the grid is in view and
		// slides in once something is selected (see assets/media-library.js).
		echo '<div class="ul-ml__dock" role="region" aria-label="' . esc_attr__( 'Selected products', 'ultralove-media-library' ) . '" data-label-one="' . esc_attr__( 'product selected', 'ultralove-media-library' ) . '" data-label-many="' . esc_attr__( 'products selected', 'ultralove-media-library' ) . '" data-label-none="' . esc_attr__( 'Selection cleared', 'ultralove-media-library' ) . '"><div class="ul-ml__dock-inner">';
		echo '<span class="ul-ml__dock-text"><strong class="ul-ml__count">0</strong> <span class="ul-ml__count-label">' . esc_html__( 'products selected', 'ultralove-media-library' ) . '</span></span>';
		echo '<button type="button" class="ul-ml__clear">' . esc_html__( 'Clear', 'ultralove-media-library' ) . '</button>';
		echo '<button type="submit" class="button ul-ml__zipbtn" disabled><svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v8M4.5 7 8 10.5 11.5 7M2.5 13.5h11"/></svg> ' . esc_html__( 'Download ZIP', 'ultralove-media-library' ) . '</button>';
		echo '</div></div>';
		// Debounced screen-reader announcement; the visible count above is deliberately not a live region.
		echo '<span class="ul-ml__sr" role="status" aria-live="polite" aria-atomic="true"></span>';
		echo '</form>';

		if ( $links ) {
			echo '<nav class="ul-ml__pages">' . wp_kses_post( $links ) . '</nav>';
		}
		echo '</div>';
	}

	private static function render_filters( $base, $search, $cat ) {
		echo '<form class="ul-ml__filters" method="get" action="' . esc_url( $base ) . '">';
		echo '<input type="search" name="ml_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name or SKU', 'ultralove-media-library' ) . '"> ';
		wp_dropdown_categories(
			array(
				'taxonomy'        => 'product_cat',
				'name'            => 'ml_cat',
				'selected'        => $cat,
				'show_option_all' => __( 'All categories', 'ultralove-media-library' ),
				'hide_empty'      => true,
				'hierarchical'    => true,
				'orderby'         => 'name',
			)
		);
		echo ' <button type="submit" class="button">' . esc_html__( 'Filter', 'ultralove-media-library' ) . '</button></form>';
	}

	private static function render_card( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$images = self::product_image_ids( $product_id );
		if ( ! $images ) {
			return;
		}
		$sku  = $product->get_sku();
		// Names can contain inline markup: render a safe subset for display, plain text for attributes.
		$name_html = wp_kses(
			$product->get_name(),
			array(
				'b'      => array(),
				'strong' => array(),
				'i'      => array(),
				'em'     => array(),
				'u'      => array(),
				'small'  => array(),
				'sub'    => array(),
				'sup'    => array(),
				'span'   => array(),
				'br'     => array(),
			)
		);
		$name      = trim( html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ) );

		echo '<li class="ul-ml__card">';
		echo '<label class="ul-ml__select"><input type="checkbox" name="product_ids[]" value="' . esc_attr( $product_id ) . '" class="ul-ml__pick"><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: product */ __( 'Select %s', 'ultralove-media-library' ), $name ) ) . '</span></label>';
		$zip_label = sprintf( /* translators: %s: product */ __( 'Download all images for %s (ZIP)', 'ultralove-media-library' ), $name );
		echo '<a class="ul-ml__zip1" href="' . esc_url( self::zip_url( $product_id ) ) . '" title="' . esc_attr( $zip_label ) . '"><svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v8M4.5 7 8 10.5 11.5 7M2.5 13.5h11"/></svg><span class="screen-reader-text">' . esc_html( $zip_label ) . '</span></a>';
		echo wp_get_attachment_image( $images[0], 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="ul-ml__info">';
		echo '<div class="ul-ml__name">' . $name_html . '</div>'; // Filtered through wp_kses above.
		if ( $sku ) {
			echo '<div class="ul-ml__sku">' . esc_html( $sku ) . '</div>';
		}
		echo '<ul class="ul-ml__files">';
		foreach ( $images as $i => $attachment_id ) {
			echo '<li><a href="' . esc_url( self::download_url( $product_id, $attachment_id ) ) . '">' . esc_html( sprintf( /* translators: %d: position */ __( 'Image %d', 'ultralove-media-library' ), $i + 1 ) ) . '</a></li>';
		}
		echo '</ul></div></li>';
	}

	private static function zip_url( $product_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'ultralove_ml_zip_product',
					'product' => $product_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ultralove_ml_zip_product_' . $product_id,
			'ul_ml_nonce'
		);
	}

	private static function download_url( $product_id, $attachment_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'ultralove_ml_download',
					'product' => $product_id,
					'image'   => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ultralove_ml_download_' . $product_id . '_' . $attachment_id,
			'ul_ml_nonce'
		);
	}

	/* ---------- Data ---------- */

	private static function product_query( $search, $cat, $paged, $per_page, $fields = '' ) {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		if ( '' !== $fields ) {
			$args['fields'] = $fields;
		}
		if ( $cat ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat, 'include_children' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( '' !== $search ) {
			// WooCommerce's own product search covers name, description and SKU.
			$ids              = WC_Data_Store::load( 'product' )->search_products( $search, '', false );
			$args['post__in'] = $ids ? array_map( 'absint', $ids ) : array( 0 );
		}
		return new WP_Query( $args );
	}

	/**
	 * Featured image followed by gallery images, images only, in display order.
	 */
	private static function product_image_ids( $product_id ) {
		$ids   = array();
		$thumb = (int) get_post_thumbnail_id( $product_id );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
		if ( is_string( $gallery ) && '' !== $gallery ) {
			foreach ( explode( ',', $gallery ) as $id ) {
				$ids[] = (int) $id;
			}
		}
		$out = array();
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			if ( in_array( get_post_mime_type( $id ), self::MIME_ALLOWED, true ) && self::source_path( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Local original for an attachment, only if it exists inside the uploads directory.
	 */
	private static function source_path( $attachment_id ) {
		$path = get_attached_file( $attachment_id, true );
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			return '';
		}
		$base = realpath( wp_get_upload_dir()['basedir'] );
		$real = realpath( $path );
		if ( ! $base || ! $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return '';
		}
		return $real;
	}

	private static function file_name( $product, $position, $path ) {
		$stem = $product->get_sku() ? $product->get_sku() : $product->get_slug();
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return sanitize_file_name( $stem . '-' . ( $position + 1 ) . '.' . $ext );
	}

	/* ---------- Downloads ---------- */

	private static function guard( $nonce_action, $nonce_field, $source ) {
		if ( ! self::can_access() ) {
			wp_die( esc_html__( 'You must be logged in to download images.', 'ultralove-media-library' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $source[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $source[ $nonce_field ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'This download link has expired. Reload the page and try again.', 'ultralove-media-library' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Allow $limit actions per $window seconds for the current user.
	 */
	private static function throttle( $bucket, $limit, $window ) {
		$key   = 'ul_ml_' . $bucket . '_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			wp_die( esc_html__( 'Too many downloads in a short time. Please wait a minute and try again.', 'ultralove-media-library' ), '', array( 'response' => 429 ) );
		}
		set_transient( $key, $count + 1, $window );
	}

	public static function download() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$product_id    = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		$attachment_id = isset( $_GET['image'] ) ? absint( $_GET['image'] ) : 0;
		// phpcs:enable
		self::guard( 'ultralove_ml_download_' . $product_id . '_' . $attachment_id, 'ul_ml_nonce', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		self::throttle( 'dl', 60, MINUTE_IN_SECONDS );

		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			wp_die( esc_html__( 'Product not found.', 'ultralove-media-library' ), '', array( 'response' => 404 ) );
		}
		$images   = self::product_image_ids( $product_id );
		$position = array_search( $attachment_id, $images, true );
		if ( false === $position ) {
			wp_die( esc_html__( 'Image not found.', 'ultralove-media-library' ), '', array( 'response' => 404 ) );
		}
		$source = self::source_path( $attachment_id );
		$name   = self::file_name( $product, $position, $source );
		error_log( sprintf( '[ultralove-media-library] user %d downloaded %s', get_current_user_id(), $name ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		self::stream( $source, $name, wp_check_filetype( $name )['type'] );
		exit;
	}

	public static function zip() {
		self::guard( 'ultralove_ml_zip', 'ul_ml_nonce', $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		self::throttle( 'zip', 5, 10 * MINUTE_IN_SECONDS );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP downloads are not available on this server.', 'ultralove-media-library' ), '', array( 'response' => 500 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$ids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) : array();
		// phpcs:enable
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( ! $ids ) {
			wp_die( esc_html__( 'Select at least one product.', 'ultralove-media-library' ), '', array( 'response' => 400 ) );
		}
		if ( count( $ids ) > self::ZIP_MAX_PRODUCTS ) {
			wp_die( esc_html( sprintf( /* translators: %d: max products */ __( 'Please choose at most %d products per ZIP (select fewer).', 'ultralove-media-library' ), self::ZIP_MAX_PRODUCTS ) ), '', array( 'response' => 400 ) );
		}

		self::send_zip( $ids, 'ultralove-images-' . gmdate( 'Ymd-His' ) . '.zip' );
	}

	/**
	 * ZIP of every image of one product, from the per-product button on its card.
	 */
	public static function zip_product() {
		$product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		self::guard( 'ultralove_ml_zip_product_' . $product_id, 'ul_ml_nonce', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		self::throttle( 'zip1', 20, 10 * MINUTE_IN_SECONDS );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP downloads are not available on this server.', 'ultralove-media-library' ), '', array( 'response' => 500 ) );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			wp_die( esc_html__( 'Product not found.', 'ultralove-media-library' ), '', array( 'response' => 404 ) );
		}
		$stem = $product->get_sku() ? $product->get_sku() : $product->get_slug();
		self::send_zip( array( $product_id ), sanitize_file_name( $stem . '-images.zip' ) );
	}

	/**
	 * Build a ZIP of the original images of the given products, stream it and exit.
	 */
	private static function send_zip( array $ids, $filename ) {
		$entries = array();
		$bytes   = 0;
		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}
			foreach ( self::product_image_ids( $product_id ) as $position => $attachment_id ) {
				$source    = self::source_path( $attachment_id );
				$bytes    += (int) filesize( $source );
				$entries[] = array( $source, $product->get_sku() ? $product->get_sku() : $product->get_slug(), self::file_name( $product, $position, $source ) );
			}
		}
		if ( ! $entries || count( $entries ) > self::ZIP_MAX_FILES || $bytes > self::ZIP_MAX_BYTES ) {
			wp_die( esc_html__( 'That selection is empty or too large for one ZIP. Select fewer products.', 'ultralove-media-library' ), '', array( 'response' => 400 ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$zip_path = wp_tempnam( 'ul-media-' );
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $zip_path );
			wp_die( esc_html__( 'The ZIP could not be created.', 'ultralove-media-library' ), '', array( 'response' => 500 ) );
		}
		foreach ( $entries as $entry ) {
			$zip->addFile( $entry[0], $entry[2] );
		}
		$zip->close();
		error_log( sprintf( '[ultralove-media-library] user %d downloaded ZIP of %d products, %d files', get_current_user_id(), count( $ids ), count( $entries ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		self::stream( $zip_path, $filename, 'application/zip' );
		wp_delete_file( $zip_path );
		exit;
	}

	private static function stream( $path, $filename, $mime ) {
		nocache_headers();
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

UltraLove_Media_Library::init();
