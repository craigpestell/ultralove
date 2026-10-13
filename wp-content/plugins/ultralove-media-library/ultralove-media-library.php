<?php
/**
 * Plugin Name: UltraLove Media Library
 * Description: Logged-in customers browse product images in My Account and download originals, web-size copies or a bulk ZIP.
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
	const WEB_WIDTH    = 1200;
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
		wp_enqueue_style( 'ultralove-media-library', $url . 'assets/media-library.css', array(), self::VERSION );
		wp_enqueue_script( 'ultralove-media-library', $url . 'assets/media-library.js', array(), self::VERSION, true );
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

		echo '<form class="ul-ml__zipform" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ultralove_ml_zip">';
		wp_nonce_field( 'ultralove_ml_zip', 'ul_ml_nonce' );
		echo '<div class="ul-ml__bar">';
		echo '<label><input type="checkbox" class="ul-ml__all"> ' . esc_html__( 'Select all on this page', 'ultralove-media-library' ) . '</label> ';
		echo '<label>' . esc_html__( 'Size', 'ultralove-media-library' ) . ' <select name="size"><option value="original">' . esc_html__( 'Original', 'ultralove-media-library' ) . '</option><option value="web">' . esc_html( sprintf( /* translators: %d: pixels */ __( 'Web (%d px)', 'ultralove-media-library' ), self::WEB_WIDTH ) ) . '</option></select></label> ';
		echo '<button type="submit" class="button ul-ml__zipbtn" disabled>' . esc_html__( 'Download selected as ZIP', 'ultralove-media-library' ) . ' <span class="ul-ml__count">(0)</span></button>';
		if ( $cat ) {
			echo ' <button type="submit" class="button" name="whole_category" value="' . esc_attr( $cat ) . '">' . esc_html( sprintf( /* translators: %d: max products */ __( 'Download whole category (max %d products)', 'ultralove-media-library' ), self::ZIP_MAX_PRODUCTS ) ) . '</button>';
		}
		echo '</div><ul class="ul-ml__grid">';

		while ( $query->have_posts() ) {
			$query->the_post();
			self::render_card( get_the_ID() );
		}
		wp_reset_postdata();
		echo '</ul></form>';

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
		echo wp_get_attachment_image( $images[0], 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="ul-ml__name">' . $name_html . '</div>'; // Filtered through wp_kses above.
		if ( $sku ) {
			echo '<div class="ul-ml__sku">' . esc_html( $sku ) . '</div>';
		}
		echo '<ul class="ul-ml__files">';
		foreach ( $images as $i => $attachment_id ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: position */ __( 'Image %d', 'ultralove-media-library' ), $i + 1 ) ) . ' ';
			echo '<a href="' . esc_url( self::download_url( $product_id, $attachment_id, 'original' ) ) . '">' . esc_html__( 'Original', 'ultralove-media-library' ) . '</a> · ';
			echo '<a href="' . esc_url( self::download_url( $product_id, $attachment_id, 'web' ) ) . '">' . esc_html( sprintf( /* translators: %d: pixels */ __( 'Web %d px', 'ultralove-media-library' ), self::WEB_WIDTH ) ) . '</a></li>';
		}
		echo '</ul></li>';
	}

	private static function download_url( $product_id, $attachment_id, $size ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'ultralove_ml_download',
					'product' => $product_id,
					'image'   => $attachment_id,
					'size'    => $size,
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
		$size          = isset( $_GET['size'] ) && 'web' === $_GET['size'] ? 'web' : 'original';
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
		$temp   = '';
		$path   = $source;
		if ( 'web' === $size ) {
			$temp = self::make_web_copy( $source );
			if ( '' === $temp ) {
				wp_die( esc_html__( 'The web-size copy could not be created.', 'ultralove-media-library' ), '', array( 'response' => 500 ) );
			}
			$path = $temp;
		}
		error_log( sprintf( '[ultralove-media-library] user %d downloaded %s (%s)', get_current_user_id(), $name, $size ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		self::stream( $path, $name, wp_check_filetype( $name )['type'] );
		if ( $temp ) {
			wp_delete_file( $temp );
		}
		exit;
	}

	public static function zip() {
		self::guard( 'ultralove_ml_zip', 'ul_ml_nonce', $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		self::throttle( 'zip', 5, 10 * MINUTE_IN_SECONDS );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP downloads are not available on this server.', 'ultralove-media-library' ), '', array( 'response' => 500 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$size = isset( $_POST['size'] ) && 'web' === $_POST['size'] ? 'web' : 'original';
		$cat  = isset( $_POST['whole_category'] ) ? absint( $_POST['whole_category'] ) : 0;
		if ( $cat ) {
			$query = self::product_query( '', $cat, 1, self::ZIP_MAX_PRODUCTS + 1, 'ids' );
			$ids   = array_map( 'absint', $query->posts );
		} else {
			$ids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) : array();
		}
		// phpcs:enable
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( ! $ids ) {
			wp_die( esc_html__( 'Select at least one product.', 'ultralove-media-library' ), '', array( 'response' => 400 ) );
		}
		if ( count( $ids ) > self::ZIP_MAX_PRODUCTS ) {
			wp_die( esc_html( sprintf( /* translators: %d: max products */ __( 'Please choose at most %d products per ZIP (narrow the category or select fewer).', 'ultralove-media-library' ), self::ZIP_MAX_PRODUCTS ) ), '', array( 'response' => 400 ) );
		}

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
		$temps = array();
		foreach ( $entries as $entry ) {
			$file = $entry[0];
			if ( 'web' === $size ) {
				$copy = self::make_web_copy( $file );
				if ( '' !== $copy ) {
					$temps[] = $copy;
					$file    = $copy;
				}
			}
			$zip->addFile( $file, $entry[2] );
		}
		$zip->close();
		error_log( sprintf( '[ultralove-media-library] user %d downloaded ZIP of %d products, %d files (%s)', get_current_user_id(), count( $ids ), count( $entries ), $size ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		self::stream( $zip_path, 'ultralove-images-' . gmdate( 'Ymd-His' ) . '.zip', 'application/zip' );
		wp_delete_file( $zip_path );
		foreach ( $temps as $temp ) {
			wp_delete_file( $temp );
		}
		exit;
	}

	/**
	 * Resized copy (longest side WEB_WIDTH, never enlarged) in a temp file; '' on failure.
	 */
	private static function make_web_copy( $source ) {
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$ext  = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$size = $editor->get_size();
		if ( $size && max( $size['width'], $size['height'] ) > self::WEB_WIDTH ) {
			$editor->resize( self::WEB_WIDTH, self::WEB_WIDTH, false );
		}
		$editor->set_quality( 85 );
		$base  = wp_tempnam( 'ul-web-' );
		$saved = $editor->save( $base . '.' . $ext );
		wp_delete_file( $base );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return '';
		}
		return $saved['path'];
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
