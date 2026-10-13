<?php
/**
 * Plugin Name: Product Images via Cloudflare
 * Description: Serves WooCommerce product images (cards, single-product gallery, thumbnails, zoom) through
 *              Cloudflare transformations or account-bound hosted Images copies instead of Cloudinary.
 *              Off by default. Turn on with:  wp option update ul_product_cdn cloudflare
 *              Back to Cloudinary:            wp option update ul_product_cdn cloudinary
 *              An editor (edit_others_posts) can preview either one on any page with
 *              ?ul_product_cdn=cloudflare-images (or =cloudflare / =cloudinary); logged-in pages are not cached.
 * Version: 1.1.3
 *
 * How it works: the local file (_wp_attached_file) is always the source. The Cloudinary plugin in
 * dual_full mode keeps it, and WordPress's own resized copies may not exist, so every size is cut from
 * the original or its verified hosted copy. Widths are deliberately bounded: remote-source
 * transformations bill unique (image, parameters) pairs; hosted copies bill storage and delivery.
 * Edit ul_pcdn_profiles() (or filter
 * 'ul_pcdn_profiles') to change them.
 *
 * Cutover (do these when switching production, see documentation/ARCHITECTURE.md 6.2):
 *   1. Cloudinary plugin: turn image delivery off and auto-sync off, or it rewrites these tags too.
 *   2. wp option update ul_product_cdn cloudflare
 *   3. Purge Cloudflare (cached HTML holds the old image URLs); restart PHP-FPM (opcache).
 *
 * Scope: front end only. Not applied in wp-admin screens, REST (so the retailer product feed keeps its
 * original upload URLs), WP-CLI or cron. Only JPEG, PNG and WebP; GIF and SVG are left untouched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 'cloudflare-images', 'cloudflare', or 'cloudinary' (the default: this file does nothing).
 */
function ul_pcdn_mode() {
	if ( isset( $_GET['ul_product_cdn'] ) && is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$preview = sanitize_key( wp_unslash( $_GET['ul_product_cdn'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $preview, array( 'cloudinary', 'cloudflare', 'cloudflare-images' ), true ) ) {
			return $preview;
		}
	}
	$mode = get_option( 'ul_product_cdn', 'cloudinary' );
	return in_array( $mode, array( 'cloudflare', 'cloudflare-images' ), true ) ? $mode : 'cloudinary';
}

/**
 * Whether this request should get Cloudflare URLs.
 */
function ul_pcdn_active() {
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
		return false;
	}
	return in_array( ul_pcdn_mode(), array( 'cloudflare', 'cloudflare-images' ), true );
}

add_filter(
	'woocommerce_gallery_thumbnail_size',
	static function ( $size ) {
		return ! is_admin() && ul_pcdn_active() ? 'woocommerce_gallery_thumbnail' : $size;
	},
	20
);

/**
 * The image sizes this file handles.
 *   sizes   WordPress/WooCommerce size names that use the profile.
 *   widths  Candidate widths for srcset (a width above the original is clamped to it).
 *   src     The width used for the plain src.
 *   square  true: cut to a square with the focal point found automatically (as Cloudinary's c_fill,g_auto
 *           did for cards); false: keep the image's shape.
 *   srcset  false: a single URL, no srcset.
 *   attr    The sizes attribute to send with the srcset ('' leaves WordPress's).
 * Cards offer the requested 200 / 400px square variants. Keep the rendered-width hint in step with
 * 30-woocommerce-loop-image-sizes.php so browsers choose between them for the actual layout.
 */
function ul_pcdn_profiles() {
	return apply_filters(
		'ul_pcdn_profiles',
		array(
			'card'   => array(
				'sizes'  => array( 'shop_catalog', 'woocommerce_thumbnail' ),
				'widths' => array( 200, 400 ),
				'src'    => 200,
				'square' => true,
				'srcset' => true,
				'attr'   => '(max-width: 575px) calc(50vw - 50px), 250px',
			),
			'single' => array(
				'sizes'  => array( 'shop_single', 'woocommerce_single' ),
				'widths' => array( 600, 900, 1200 ),
				'src'    => 600,
				'square' => false,
				'srcset' => true,
				'attr'   => '',
			),
			'thumb'  => array(
				'sizes'  => array( 'shop_thumbnail', 'woocommerce_gallery_thumbnail' ),
				'widths' => array( 100, 200 ),
				'src'    => 100,
				'square' => true,
				'srcset' => true,
				'attr'   => '',
			),
			// The zoom / lightbox image. Only for images that belong to a product (the 'full' size is
			// also used for logos and banners, which are not ours to touch).
			'full'   => array(
				'sizes'  => array( 'full' ),
				'widths' => array( 1600 ),
				'src'    => 1600,
				'square' => false,
				'srcset' => false,
				'attr'   => '',
			),
		)
	);
}

/**
 * The profile for a size name, or null.
 */
function ul_pcdn_profile_for_size( $size ) {
	if ( ! is_string( $size ) ) {
		return null;
	}
	foreach ( ul_pcdn_profiles() as $name => $profile ) {
		if ( in_array( $size, $profile['sizes'], true ) ) {
			$profile['name'] = $name;
			return $profile;
		}
	}
	return null;
}

/**
 * Attachment belongs to a product or variation.
 */
function ul_pcdn_is_product_image( $attachment_id ) {
	global $product;
	if ( $product instanceof WC_Product
		&& ( (int) $attachment_id === $product->get_image_id()
			|| in_array( (int) $attachment_id, $product->get_gallery_image_ids(), true ) ) ) {
		return true;
	}
	static $cache = array();
	if ( ! isset( $cache[ $attachment_id ] ) ) {
		$parent                  = (int) wp_get_post_parent_id( $attachment_id );
		$cache[ $attachment_id ] = $parent && in_array( get_post_type( $parent ), array( 'product', 'product_variation' ), true );
	}
	return $cache[ $attachment_id ];
}

/**
 * A hosted-copy URL, or a /cdn-cgi/image/ URL for the local file. $height is only used for a square (fit=cover).
 * Absolute, so it also works in feeds, structured data and emails; on the same host as the upload, so
 * Cloudflare reads the file from this zone. onerror=redirect serves the original if a transformation
 * fails (for example the monthly free quota is used up).
 */
function ul_pcdn_url( $attachment_id, $width, $height = 0 ) {
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( ! $file ) {
		return '';
	}
	if ( 'cloudflare-images' === ul_pcdn_mode() ) {
		$options = array( 'quality' => 80, 'fit' => $height ? 'cover' : 'scale-down', 'width' => (int) $width );
		if ( $height ) {
			$options['gravity'] = 'auto';
			$options['height'] = (int) $height;
		}
		$hosted = ul_cf_images_url( (int) $attachment_id, $options );
		if ( $hosted ) {
			return $hosted;
		}
	}
	$uploads = wp_get_upload_dir();
	$opts    = array( 'format=auto', 'quality=80', $height ? 'fit=cover' : 'fit=scale-down' );
	if ( $height ) {
		$opts[] = 'gravity=auto';
	}
	$opts[] = 'onerror=redirect';
	$opts[] = 'width=' . (int) $width;
	if ( $height ) {
		$opts[] = 'height=' . (int) $height;
	}
	$path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) . '/' . ltrim( $file, '/' );
	$path = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	return home_url( '/cdn-cgi/image/' . implode( ',', $opts ) . $path );
}

/**
 * The candidate images for a profile, keyed by the width actually served: array( w => array( url, w, h ) ),
 * plus 'src' => the one to use as the plain src. Empty when the attachment can't be served this way
 * (not a JPEG/PNG/WebP, or no size in its metadata). Widths are clamped to the original so that
 * nothing is enlarged and the width and height written on the tag are the real ones.
 */
function ul_pcdn_candidates( $attachment_id, $profile ) {
	if ( ! in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return array();
	}
	$meta = wp_get_attachment_metadata( $attachment_id );
	$ow   = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
	$oh   = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
	if ( $ow < 1 || $oh < 1 ) {
		return array();
	}
	$serve = function ( $n ) use ( $profile, $ow, $oh ) {
		if ( $profile['square'] ) {
			$side = max( 1, min( $n, $ow, $oh ) );
			return array( $side, $side );
		}
		$w = max( 1, min( $n, $ow ) );
		return array( $w, (int) round( $w * $oh / $ow ) );
	};
	$out = array();
	foreach ( $profile['widths'] as $n ) {
		list( $w, $h ) = $serve( $n );
		$url           = ul_pcdn_url( $attachment_id, $w, $profile['square'] ? $h : 0 );
		if ( ! $url ) {
			return array();
		}
		$out[ $w ] = array(
			'url' => $url,
			'w'   => $w,
			'h'   => $h,
		);
	}
	ksort( $out );
	list( $src_w ) = $serve( $profile['src'] );
	$out['src']    = $out[ $src_w ];
	return $out;
}

/**
 * Plain URL: wp_get_attachment_image_src() is behind wp_get_attachment_image(), the theme's lazy-load
 * markup (data-original / data-src), the gallery's data-large_image and most plugins.
 */
add_filter(
	'wp_get_attachment_image_src',
	static function ( $image, $attachment_id, $size, $icon ) {
		if ( ! $image || $icon || ! ul_pcdn_active() ) {
			return $image;
		}
		$profile = ul_pcdn_profile_for_size( $size );
		if ( ! $profile ) {
			return $image;
		}
		// Zoom image: only product images, and never in wp-admin (it would be saved into post content).
		if ( 'full' === $profile['name'] && ( is_admin() || ! ul_pcdn_is_product_image( $attachment_id ) ) ) {
			return $image;
		}
		$c = ul_pcdn_candidates( $attachment_id, $profile );
		if ( ! $c ) {
			return $image;
		}
		return array( $c['src']['url'], $c['src']['w'], $c['src']['h'], false );
	},
	99,
	4
);

/**
 * srcset and sizes. WordPress only builds a srcset from resized copies it has in the attachment's
 * metadata, and with Cloudinary delivering there are none, so it is written here.
 */
add_filter(
	'wp_get_attachment_image_attributes',
	static function ( $attr, $attachment, $size ) {
		if ( ! ul_pcdn_active() || empty( $attr['src'] )
			|| ( false === strpos( $attr['src'], '/cdn-cgi/image/' ) && ! ul_cf_images_is_url( $attr['src'] ) ) ) {
			return $attr;
		}
		$profile = ul_pcdn_profile_for_size( $size );
		if ( ! $profile || ! $profile['srcset'] ) {
			return $attr;
		}
		$c = ul_pcdn_candidates( $attachment->ID, $profile );
		if ( ! $c ) {
			return $attr;
		}
		unset( $c['src'] );
		$parts = array();
		foreach ( $c as $w => $item ) {
			$parts[] = $item['url'] . ' ' . $w . 'w';
		}
		$attr['srcset'] = implode( ', ', $parts );
		if ( $profile['attr'] ) {
			// Keep the 'auto, ' WordPress puts in front of a lazy-loaded image's sizes.
			$auto          = ( isset( $attr['sizes'] ) && 0 === strpos( $attr['sizes'], 'auto,' ) ) ? 'auto, ' : '';
			$attr['sizes'] = $auto . $profile['attr'];
		}
		return $attr;
	},
	20,
	3
);

/**
 * Safety net while the Cloudinary plugin is still delivering: it builds its own tag from the
 * attachment ID (class wp-image-N) and would put its res.cloudinary.com URL back. If it still
 * hands over a Cloudinary tag for a product image, replace the URLs. The proper fix is turning its
 * image delivery off (see the header). The hook and the 'atts' array are the ones
 * 30-woocommerce-loop-image-sizes.php already uses; the attachment ID argument and the class lookup
 * are a best effort and have not been run against the Cloudinary plugin.
 */
add_filter(
	'cloudinary_pre_image_tag',
	static function ( $tag_element, $attachment_id = 0 ) {
		if ( ! ul_pcdn_active() || empty( $tag_element['atts']['src'] ) || false === strpos( $tag_element['atts']['src'], 'res.cloudinary.com' ) ) {
			return $tag_element;
		}
		$atts  = $tag_element['atts'];
		$class = isset( $atts['class'] ) ? (string) $atts['class'] : '';
		$id    = (int) $attachment_id;
		if ( ! $id && preg_match( '/wp-image-(\d+)/', $class, $m ) ) {
			$id = (int) $m[1];
		}
		if ( ! $id ) {
			return $tag_element;
		}
		$size = preg_match( '/attachment-([\w-]+)/', $class, $m ) ? $m[1] : null;
		if ( ! $size && (int) ( $atts['width'] ?? 0 ) >= 700 && (int) $atts['width'] === (int) ( $atts['height'] ?? 0 ) ) {
			$size = 'shop_catalog'; // The card's square image (it has lost its size class by now).
		}
		$profile = ul_pcdn_profile_for_size( $size );
		if ( ! $profile || ! $profile['srcset'] || ( 'card' !== $profile['name'] && ! ul_pcdn_is_product_image( $id ) ) ) {
			return $tag_element;
		}
		$c = ul_pcdn_candidates( $id, $profile );
		if ( ! $c ) {
			return $tag_element;
		}
		$src = $c['src'];
		unset( $c['src'] );
		$parts = array();
		foreach ( $c as $w => $item ) {
			$parts[] = $item['url'] . ' ' . $w . 'w';
		}
		$tag_element['atts']['src']    = $src['url'];
		$tag_element['atts']['srcset'] = implode( ', ', $parts );
		$tag_element['atts']['width']  = $src['w'];
		$tag_element['atts']['height'] = $src['h'];
		if ( $profile['attr'] ) {
			$auto                         = ( isset( $atts['sizes'] ) && 0 === strpos( $atts['sizes'], 'auto,' ) ) ? 'auto, ' : '';
			$tag_element['atts']['sizes'] = $auto . $profile['attr'];
		}
		return $tag_element;
	},
	30,
	2
);
