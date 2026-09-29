<?php
/**
 * Plugin Name: UltraLove Banners
 * Description: Lightweight in-house replacement for RevSlider's homepage/header rotating banners.
 *              Manages banner slides as a custom post type (image, link, group, order) and renders
 *              them with a small CSS/JS carousel. Uses standard wp_get_attachment_image() calls so
 *              the existing Cloudinary plugin's own responsive image handling (srcset generation
 *              per registered image size) applies automatically - no custom width-measuring JS
 *              needed, unlike the RevSlider integration this replaces. The carousel is a sliding
 *              track (transform: translateX), not an opacity crossfade, so the active slide can be
 *              "attached" to the pointer during a drag/swipe - matching RevSlider's own click-drag
 *              and touch-swipe navigation. A clone of the last slide is prepended and a clone of the
 *              first is appended so dragging past either end loops seamlessly.
 * Version: 2.1.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UB_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

if ( is_admin() ) {
	require_once UB_PLUGIN_PATH . 'admin-pages.php';
}
define( 'UB_ASSET_VERSION', '2.0.12' );

/**
 * Banner slot aspect ratio (width / height) per group. Slides are cropped to this in the admin.
 */
function ub_group_ratio( $group ) {
	$ratios = array(
		'menu-bar'   => 728 / 90,
		'home'       => 2.4,
	);
	return isset( $ratios[ $group ] ) ? $ratios[ $group ] : null;
}

/**
 * How the home banner fills the space around a slide that doesn't exactly match the slot's shape:
 * plain black, a blurred/enlarged copy of the slide itself, or the same purple/teal aurora
 * gradient used behind #topcta/#bottomcta (see .ub-banner-bg-aurora in banner.css). Menu-bar
 * slides always just center (see .ub-banner-menu-bar .ub-banner-img in banner.css), so this only
 * applies to 'home'.
 */
function ub_home_bg_style() {
	$style = get_option( 'ub_home_bg_style', 'black' );
	return in_array( $style, array( 'blur', 'aurora' ), true ) ? $style : 'black';
}

/**
 * Saved crop for a slide as array( x, y, w, h ) in original-image pixels, or null. Stored as
 * "attachment_id,x,y,w,h" and ignored if the featured image has since changed.
 */
/**
 * Factor from the attachment's WordPress pixels to its original upload's pixels. Crops are saved in
 * WordPress's pixels (the admin cropper shows the 'full' image), but WordPress shrinks uploads wider
 * than 2560px to a "-scaled" copy while Cloudinary holds - and crops - the original, so a saved crop
 * has to be scaled up by this before it goes into a Cloudinary c_crop. 1 when the image wasn't
 * scaled or the original's size can't be read (dev has no uploads); cached per file in post meta.
 */
function ub_original_scale( $thumb_id, $meta ) {
	if ( empty( $meta['original_image'] ) || empty( $meta['width'] ) ) {
		return 1;
	}
	$cached = get_post_meta( $thumb_id, '_ub_original_size', true );
	if ( is_array( $cached ) && isset( $cached['file'], $cached['width'] ) && $cached['file'] === $meta['original_image'] ) {
		$orig_w = (int) $cached['width'];
	} else {
		$path   = wp_get_original_image_path( $thumb_id );
		$size   = $path && is_readable( $path ) ? wp_getimagesize( $path ) : false;
		$orig_w = $size ? (int) $size[0] : 0;
		if ( $orig_w ) {
			update_post_meta( $thumb_id, '_ub_original_size', array( 'file' => $meta['original_image'], 'width' => $orig_w, 'height' => (int) $size[1] ) );
		}
	}
	return $orig_w > $meta['width'] ? $orig_w / $meta['width'] : 1;
}

function ub_get_crop( $post_id, $thumb_id ) {
	$raw = get_post_meta( $post_id, '_ub_crop', true );
	if ( empty( $raw ) ) {
		return null;
	}
	$p = array_map( 'intval', explode( ',', $raw ) );
	if ( 5 !== count( $p ) || $p[0] !== (int) $thumb_id || $p[3] < 1 || $p[4] < 1 ) {
		return null;
	}
	return array(
		'x' => $p[1],
		'y' => $p[2],
		'w' => $p[3],
		'h' => $p[4],
	);
}

/**
 * Applies a Cloudinary transform (a crop, a white pad, or a plain scale-to-fit) to a
 * Cloudinary-served <img>, always leading with q_auto,f_auto so every banner image is compressed
 * and served in the browser's best format - not just the ones with a saved crop. The Cloudinary
 * plugin only swaps local URLs for res.cloudinary.com URLs when the page is output, and for a
 * slide with no local WP intermediate generated for the requested size, falls back to serving the
 * full, unresized original with no transform at all - so this runs on the final HTML rather than
 * at render time. $seg is an optional geometry transform segment (e.g. a crop or pad; '' for a
 * plain slide) and $out_w x $out_h the size it produces. Not-yet-synced (non-Cloudinary) images,
 * and tags with no usable dimensions, are left untouched.
 */
function ub_apply_transform( $html, $seg, $out_w, $out_h ) {
	if ( ! $out_w || ! $out_h || ! preg_match( '#(https://res\.cloudinary\.com/[^/]+/images/)(?:[^/?"\s]+/)*?(v\d+/[^?"\s]+)(\?[^"\s]*)?#', $html, $m ) ) {
		return $html;
	}
	$base  = $m[1];
	$path  = $m[2];
	$query = isset( $m[3] ) ? $m[3] : '';
	$pre   = $seg ? $seg . '/' : '';

	$url_for = function ( $n ) use ( $base, $path, $query, $pre, $out_w, $out_h ) {
		return sprintf( '%sq_auto,f_auto/%sc_scale,w_%d,h_%d/%s%s', $base, $pre, $n, round( $n * $out_h / $out_w ), $path, $query );
	};

	$widths = array();
	foreach ( array( 370, 600, 768, 1024, 1536, 1920 ) as $n ) {
		if ( $n < $out_w ) {
			$widths[] = $n;
		}
	}
	$widths[] = $out_w;

	$srcset = array();
	foreach ( $widths as $n ) {
		$srcset[] = $url_for( $n ) . ' ' . $n . 'w';
	}
	$src_w = min( $out_w, 1920 );

	$html = preg_replace( '/\ssrc="[^"]*"/', ' src="' . esc_url( $url_for( $src_w ) ) . '"', $html, 1 );
	if ( preg_match( '/\ssrcset="/', $html ) ) {
		$html = preg_replace( '/\ssrcset="[^"]*"/', ' srcset="' . esc_attr( implode( ', ', $srcset ) ) . '"', $html, 1 );
	} else {
		$html = preg_replace( '/<img/', '<img srcset="' . esc_attr( implode( ', ', $srcset ) ) . '"', $html, 1 );
	}
	$html = preg_replace( '/\swidth="\d+"/', ' width="' . $src_w . '"', $html, 1 );
	$html = preg_replace( '/\sheight="\d+"/', ' height="' . round( $src_w * $out_h / $out_w ) . '"', $html, 1 );
	return $html;
}

/**
 * CSS version of a saved crop, for a slide image Cloudinary can't crop (dev has no Cloudinary; on
 * prod, an image not synced yet). Wraps the local <img> in a .ub-crop box of the crop's shape,
 * fitted into the $slot_ratio slide the way object-fit: contain fits a Cloudinary-cropped image,
 * and sizes and offsets the whole image inside it so only the crop area shows. The image stays in
 * normal flow (negative margins, not absolute positioning) so it still gives the banner its
 * natural width - the header strip is sized by its content. $crop is array( x, y, w, h ) in the
 * pixels of the $full_w x $full_h original. `sizes` is scaled by the same factors so the browser
 * still picks a sharp srcset candidate for the larger, partly hidden image.
 */
function ub_css_crop( $html, $crop, $full_w, $full_h, $slot_ratio ) {
	list( $x, $y, $w, $h ) = $crop;
	if ( $w < 1 || $h < 1 ) {
		return $html;
	}
	$pct   = function ( $v ) {
		return round( $v, 4 ) . '%';
	};
	$ratio = $w / $h;
	// Box width as a share of the slide: full width unless the crop is taller than the slot.
	$box   = $slot_ratio && $ratio < $slot_ratio ? $ratio / $slot_ratio : 1;
	$scale = $full_w / $w * $box;
	// Percentage margins are measured against the box's width in both directions, so the vertical
	// offset is also y / w.
	$style = sprintf( 'width:%s;margin-left:%s;margin-top:%s', $pct( $full_w / $w * 100 ), $pct( -$x / $w * 100 ), $pct( -$y / $w * 100 ) );
	$html  = preg_replace( '/<img\b/', '<img style="' . esc_attr( $style ) . '"', $html, 1 );

	if ( abs( $scale - 1 ) > 0.001 && preg_match( '/\ssizes="([^"]*)"/', $html, $sizes_match ) ) {
		$parts = array();
		foreach ( explode( ',', html_entity_decode( $sizes_match[1], ENT_QUOTES ) ) as $part ) {
			$part    = trim( $part );
			$parts[] = preg_match( '/^(.*?)(\S+)$/', $part, $p ) && 'auto' !== $p[2] ? $p[1] . 'calc(' . $p[2] . ' * ' . round( $scale, 4 ) . ')' : $part;
		}
		$html = str_replace( $sizes_match[0], ' sizes="' . esc_attr( implode( ', ', $parts ) ) . '"', $html );
	}

	return '<span class="ub-crop" style="aspect-ratio:' . round( $ratio, 5 ) . ';width:' . $pct( $box * 100 ) . '">' . $html . '</span>';
}

/**
 * Output pass for every banner slide image (cropped or not), plus a <head> preload for the
 * header's first (LCP) slide. Started at init priority 0 so it wraps (runs after) the Cloudinary
 * plugin's own output buffer.
 */
add_action(
	'init',
	function () {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		ob_start(
			function ( $html ) {
				if ( false === strpos( $html, 'ub-banner-img' ) ) {
					return $html;
				}

				$html = preg_replace_callback(
					'/<img\b[^>]*\bub-banner-img\b[^>]*>/',
					function ( $m ) {
						$tag   = $m[0];
						$out_w = null;
						$out_h = null;
						$seg   = '';

						if ( preg_match( '/\sdata-ub-crop="(\d+),(\d+),(\d+),(\d+)"/', $tag, $crop_match ) ) {
							$full = preg_match( '/\sdata-ub-full="(\d+),(\d+),([\d.]*)(?:,([\d.]+))?"/', $tag, $full_match ) ? array( (int) $full_match[1], (int) $full_match[2], (float) $full_match[3], isset( $full_match[4] ) ? (float) $full_match[4] : 1.0 ) : null;
							$tag  = preg_replace( '/\sdata-ub-(crop|full)="[^"]*"/', '', $tag );
							// Cloudinary crops the original upload, which can be larger than the image the
							// crop was drawn on - scale the crop into its pixels.
							$k    = $full && $full[3] > 0 ? $full[3] : 1;
							$c    = array_map(
								function ( $v ) use ( $k ) {
									return (int) round( $v * $k );
								},
								array_slice( $crop_match, 1 )
							);
							$out  = ub_apply_transform( $tag, sprintf( 'c_crop,x_%d,y_%d,w_%d,h_%d', $c[0], $c[1], $c[2], $c[3] ), $c[2], $c[3] );
							// Unchanged means the image isn't served by Cloudinary (dev, or not synced yet):
							// show the crop with CSS on the local image instead.
							if ( $out === $tag && $full ) {
								$out = ub_css_crop( $tag, array_map( 'intval', array_slice( $crop_match, 1 ) ), $full[0], $full[1], $full[2] );
							}
							return $out;
						} else {
							// No saved crop: scale from the dimensions WordPress already wrote on the tag -
							// accurate even when it fell back to the full original because no local
							// intermediate exists for the requested size.
							if ( preg_match( '/\swidth="(\d+)"/', $tag, $w_match ) ) {
								$out_w = (int) $w_match[1];
							}
							if ( preg_match( '/\sheight="(\d+)"/', $tag, $h_match ) ) {
								$out_h = (int) $h_match[1];
							}
						}

						return ub_apply_transform( $tag, $seg, $out_w, $out_h );
					},
					$html
				);

				// Preload each banner's first slide image (the ones marked fetchpriority=high - the header
				// strip's, and on the home page the home banner's, which is the larger LCP image there) so
				// the browser's preload scanner finds them right in <head>, ahead of where the banners
				// actually sit in <body>. One preload per distinct image.
				if ( preg_match_all( '/<img\b(?=[^>]*\bub-banner-img\b)(?=[^>]*\bfetchpriority="high")[^>]*>/', $html, $lcp_matches ) ) {
					$preloads = array();
					foreach ( $lcp_matches[0] as $tag ) {
						preg_match( '/\ssrc="([^"]*)"/', $tag, $src_match );
						preg_match( '/\ssrcset="([^"]*)"/', $tag, $srcset_match );
						preg_match( '/\ssizes="([^"]*)"/', $tag, $sizes_match );
						if ( empty( $src_match[1] ) || isset( $preloads[ $src_match[1] ] ) ) {
							continue;
						}
						$preload = '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( html_entity_decode( $src_match[1], ENT_QUOTES ) ) . '"';
						if ( ! empty( $srcset_match[1] ) ) {
							$preload .= ' imagesrcset="' . esc_attr( html_entity_decode( $srcset_match[1], ENT_QUOTES ) ) . '"';
						}
						if ( ! empty( $sizes_match[1] ) ) {
							$preload .= ' imagesizes="' . esc_attr( html_entity_decode( $sizes_match[1], ENT_QUOTES ) ) . '"';
						}
						$preloads[ $src_match[1] ] = $preload . '>' . "\n";
					}
					if ( $preloads ) {
						$html = preg_replace( '/<head\b[^>]*>/', '$0' . str_replace( '$', '\\$', implode( '', $preloads ) ), $html, 1 );
					}
				}

				return $html;
			}
		);
	},
	0
);

/**
 * Admin list: show each slide's group.
 */
add_filter(
	'manage_ub_banner_slide_posts_columns',
	function ( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['ub_group'] = 'Group';
			}
		}
		return $out;
	}
);

add_action(
	'manage_ub_banner_slide_posts_custom_column',
	function ( $column, $post_id ) {
		if ( 'ub_group' !== $column ) {
			return;
		}
		$labels = array(
			'home'     => 'Home',
			'menu-bar' => 'Menu Bar',
		);
		$group  = get_post_meta( $post_id, '_ub_group', true );
		echo esc_html( isset( $labels[ $group ] ) ? $labels[ $group ] : ( $group ? $group : '—' ) );
	},
	10,
	2
);

/**
 * Banner groups: slug => label.
 */
function ub_groups() {
	return array(
		'menu-bar' => 'Menu Bar (header)',
		'home'     => 'Home (2 slides at a time)',
	);
}

/**
 * How many slides a group shows side by side on a wide screen (narrow screens show one; see
 * banner.css). Each slide keeps its group's aspect ratio, so the slot is wider for more per view.
 */
function ub_group_per_view( $group ) {
	return 'home' === $group ? 2 : 1;
}

/**
 * Queue an attachment for upload to Cloudinary if it isn't there yet (the crop is applied by
 * Cloudinary, so until then the image would show uncropped).
 */
function ub_queue_cloudinary_sync( $attachment_id ) {
	if ( $attachment_id && function_exists( 'Cloudinary\\get_plugin_instance' ) ) {
		$sync = \Cloudinary\get_plugin_instance()->get_component( 'sync' );
		if ( $sync && ! $sync->is_synced( $attachment_id ) ) {
			$sync->maybe_prepare_sync( $attachment_id );
		}
	}
}

/**
 * A slide's target post ID if it links to a published post, else 0.
 */
function ub_slide_post( $slide_id ) {
	$post_id = (int) get_post_meta( $slide_id, '_ub_post', true );
	return $post_id && 'publish' === get_post_status( $post_id ) ? $post_id : 0;
}

/**
 * Where a slide's banner links: the chosen post's permalink (so it follows the post if its address
 * changes), otherwise the custom URL (for links that aren't a post: search results, brand pages,
 * external), otherwise '#'.
 */
function ub_slide_link( $slide_id ) {
	$post_id = ub_slide_post( $slide_id );
	if ( $post_id ) {
		return get_permalink( $post_id );
	}
	$link = trim( (string) get_post_meta( $slide_id, '_ub_link', true ) );
	return '' !== $link ? $link : '#';
}

/**
 * Whether a slide has anything to link to.
 */
function ub_slide_has_link( $slide_id ) {
	return (bool) ub_slide_post( $slide_id ) || '' !== trim( (string) get_post_meta( $slide_id, '_ub_link', true ) );
}

/**
 * Custom post type: one post per banner slide.
 */
add_action(
	'init',
	function () {
		register_post_type(
			'ub_banner_slide',
			array(
				'labels'       => array(
					'name'          => 'Banner Slides',
					'singular_name' => 'Banner Slide',
					'add_new_item'  => 'Add New Banner Slide',
					'edit_item'     => 'Edit Banner Slide',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'ub-banners',
				'menu_icon'    => 'dashicons-images-alt2',
				'supports'     => array( 'title', 'thumbnail', 'page-attributes' ),
				'has_archive'  => false,
			)
		);

		register_post_meta(
			'ub_banner_slide',
			'_ub_group',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		register_post_meta(
			'ub_banner_slide',
			'_ub_post',
			array(
				'type'         => 'integer',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		register_post_meta(
			'ub_banner_slide',
			'_ub_link',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		// Banner display size. Not hard-cropped (false height) so slides can keep their own aspect
		// ratio; this just gives the Cloudinary plugin a size ceiling to generate a responsive
		// srcset against, the same way it already does for WooCommerce product images.
		add_image_size( 'ub_banner', 1920, 0, false );
	}
);

/**
 * Admin meta box for the group + link fields.
 */
add_action(
	'add_meta_boxes',
	function () {
		add_meta_box(
			'ub_banner_slide_details',
			'Banner Details',
			'ub_render_meta_box',
			'ub_banner_slide',
			'side',
			'default'
		);
	}
);

add_action(
	'add_meta_boxes',
	function () {
		add_meta_box( 'ub_banner_slide_crop', 'Banner Crop', 'ub_render_crop_box', 'ub_banner_slide', 'normal', 'high' );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'ub_banner_slide' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'ub-cropper', UB_PLUGIN_URL . 'assets/vendor/cropper.min.css', array(), '1.6.2' );
		wp_enqueue_script( 'ub-cropper', UB_PLUGIN_URL . 'assets/vendor/cropper.min.js', array(), '1.6.2', true );
		wp_enqueue_script( 'ub-admin', UB_PLUGIN_URL . 'assets/admin.js', array( 'ub-cropper' ), UB_ASSET_VERSION, true );
		ub_enqueue_link_search();
	}
);

function ub_render_crop_box( $post ) {
	$thumb_id = get_post_thumbnail_id( $post->ID );
	$meta     = $thumb_id ? wp_get_attachment_metadata( $thumb_id ) : null;
	if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
		echo '<p>Set the featured image and save the slide, then crop it here.</p>';
		return;
	}
	$crop   = ub_get_crop( $post->ID, $thumb_id );
	$ratios = array();
	foreach ( array( 'menu-bar', 'home' ) as $g ) {
		$ratios[ $g ] = ub_group_ratio( $g );
	}
	?>
	<div id="ub-crop-wrap"
		data-id="<?php echo (int) $thumb_id; ?>"
		data-w="<?php echo (int) $meta['width']; ?>"
		data-h="<?php echo (int) $meta['height']; ?>"
		data-crop="<?php echo $crop ? esc_attr( implode( ',', $crop ) ) : ''; ?>"
		data-ratios="<?php echo esc_attr( wp_json_encode( $ratios ) ); ?>">
		<p>
			<label><input type="checkbox" name="ub_crop_enabled" id="ub_crop_enabled" value="1" <?php checked( (bool) $crop ); ?>> <strong>Use this crop</strong></label>
			&nbsp; Image is <?php echo (int) $meta['width']; ?>&times;<?php echo (int) $meta['height']; ?>. Drag or resize the box to choose the visible area; its shape is locked to the selected group's banner shape. Click <em>Update</em> to save. Leave unchecked to show the whole image, centered.
		</p>
		<div style="max-width:100%;"><img id="ub-crop-img" src="<?php echo esc_url( wp_get_attachment_image_url( $thumb_id, 'full' ) ); ?>" alt="" style="display:block;max-width:100%;"></div>
		<input type="hidden" name="ub_crop" id="ub_crop" value="">
	</div>
	<?php
}

function ub_render_meta_box( $post ) {
	wp_nonce_field( 'ub_save_meta', 'ub_meta_nonce' );
	$group = get_post_meta( $post->ID, '_ub_group', true );
	$link  = get_post_meta( $post->ID, '_ub_link', true );
	?>
	<p>
		<label for="ub_group"><strong>Group</strong></label><br>
		<select name="ub_group" id="ub_group" style="width:100%;">
			<option value="home" <?php selected( $group, 'home' ); ?>>Home</option>
			<option value="menu-bar" <?php selected( $group, 'menu-bar' ); ?>>Menu Bar</option>
		</select>
	</p>
	<p>
		<label for="ub_link"><strong>Link</strong> <span style="color:#b32d2e;">*</span></label><br>
		<input type="text" name="ub_link" id="ub_link" value="<?php echo esc_attr( $link ); ?>" style="width:100%;" autocomplete="off" placeholder="Search for a product or page, or type a URL" required>
	
		<input type="hidden" name="ub_post" id="ub_post" value="<?php echo (int) get_post_meta( $post->ID, '_ub_post', true ); ?>">
		<span class="ub-post-note description"><?php echo ub_slide_post( $post->ID ) ? esc_html( 'Linked to ' . get_post_type_object( get_post_type( ub_slide_post( $post->ID ) ) )->labels->singular_name . ': ' . wp_strip_all_tags( get_the_title( ub_slide_post( $post->ID ) ) ) ) : ''; ?></span>
		</p>
	<p>Use the featured image for the slide's banner image. Use the "Order" field (below the editor) to control slide order within a group - lower numbers show first.</p>
	<?php
}

add_action(
	'save_post_ub_banner_slide',
	function ( $post_id ) {
		if ( ! isset( $_POST['ub_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ub_meta_nonce'], 'ub_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( isset( $_POST['ub_group'] ) ) {
			update_post_meta( $post_id, '_ub_group', sanitize_key( $_POST['ub_group'] ) );
		}
		if ( isset( $_POST['ub_link'] ) ) {
			update_post_meta( $post_id, '_ub_link', esc_url_raw( $_POST['ub_link'] ) );
		}
		if ( isset( $_POST['ub_post'] ) ) {
			$target = absint( $_POST['ub_post'] );
			if ( $target && 'publish' === get_post_status( $target ) ) {
				update_post_meta( $post_id, '_ub_post', $target );
				update_post_meta( $post_id, '_ub_link', wp_make_link_relative( get_permalink( $target ) ) );
			} else {
				delete_post_meta( $post_id, '_ub_post' );
			}
		}

			// The link is required: a slide with no link goes back to draft rather than going live as a
			// banner that does nothing when clicked.
			if ( ! ub_slide_has_link( $post_id ) && 'publish' === get_post_status( $post_id ) ) {
				static $reverting = false;
				if ( ! $reverting ) {
					$reverting = true;
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_status' => 'draft',
						)
					);
					set_transient( 'ub_link_missing_' . get_current_user_id(), 1, 60 );
				}
			}

			$crop  = null;
			$thumb = (int) get_post_thumbnail_id( $post_id );
			$meta  = $thumb ? wp_get_attachment_metadata( $thumb ) : null;
			if ( ! empty( $_POST['ub_crop_enabled'] ) && ! empty( $_POST['ub_crop'] ) && ! empty( $meta['width'] ) ) {
				$p = array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['ub_crop'] ) ) ) );
				if ( 5 === count( $p ) && $p[0] === $thumb && $p[1] >= 0 && $p[2] >= 0 && $p[3] > 0 && $p[4] > 0
					&& $p[1] + $p[3] <= $meta['width'] + 1 && $p[2] + $p[4] <= $meta['height'] + 1 ) {
					$ratio = ub_group_ratio( get_post_meta( $post_id, '_ub_group', true ) );
					if ( ! $ratio || abs( $p[3] / $p[4] - $ratio ) / $ratio < 0.03 ) {
						$crop = implode( ',', $p );
					}
				}
			}
			if ( $crop ) {
				update_post_meta( $post_id, '_ub_crop', $crop );
			} else {
				delete_post_meta( $post_id, '_ub_crop' );
			}

			ub_queue_cloudinary_sync( $thumb );
	}
);

add_action(
	'admin_notices',
	function () {
		$key = 'ub_link_missing_' . get_current_user_id();
		if ( get_transient( $key ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>A banner slide needs a link. It was saved as a draft; add a Link URL and publish again.</p></div>';
		}
	}
);

/**
 * Front-end assets.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_register_style( 'ub-banner', UB_PLUGIN_URL . 'assets/banner.css', array(), UB_ASSET_VERSION );
		wp_register_script( 'ub-banner', UB_PLUGIN_URL . 'assets/banner.js', array(), UB_ASSET_VERSION, true );
	}
);

/**
 * Combined aurora backdrop (see banner.css / hero-combo.js): a body class to scope the CSS that
 * makes #topcta transparent, and the empty div hero-combo.js sizes to span banner-top to
 * topcta-bottom. Appended at the end of <body>, not inside the Elementor content, since the
 * banner and topcta are separate top-level sections with no shared wrapper to attach it to -
 * position:absolute plus JS-measured coordinates place it correctly regardless of where in the
 * DOM it actually lives.
 */
add_filter(
	'body_class',
	function ( $classes ) {
		if ( is_front_page() && 'aurora' === ub_home_bg_style() ) {
			$classes[] = 'ub-hero-combo';
		}
		return $classes;
	}
);

/**
 * hero-combo.js is inlined here rather than wp_enqueue_script()'d - the backdrop it positions
 * starts with no top/height (CSS alone can't know them; see banner.css), so it's a 0x0 no-op
 * until this runs. A separately-requested script file only executes after its own network
 * round-trip, during which the now-transparent slide/topcta show whatever's behind them (the
 * page's own dark background) instead of the gradient - a real, visible flash of black on every
 * load. Running inline, at parse time, right after the div exists, closes that gap the same way
 * the critical-css block above inlines banner.css itself.
 */
add_action(
	'wp_footer',
	function () {
		if ( ! is_front_page() || 'aurora' !== ub_home_bg_style() ) {
			return;
		}
		echo '<div id="ub-hero-combo-bg" aria-hidden="true"></div>';

		$js = file_get_contents( UB_PLUGIN_PATH . 'assets/hero-combo.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $js ) {
			echo '<script id="ub-hero-combo-js">' . $js . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
);

/**
 * Inline banner.css in <head>, ahead of the enqueued copy. The header menu-bar banner renders
 * right after <body> opens on every page, but caching/optimization plugins (Autoptimize on this
 * site) can move the enqueued stylesheet's <link> down near the end of the document. Without this,
 * the browser paints the raw, unstyled slides (stacked as plain blocks) before the real stylesheet
 * ever applies - a flash that also risks becoming the LCP element. The enqueued stylesheet is left
 * in place as the authoritative copy; this is only a same-content safety net read from that file at
 * request time so the two can't drift apart.
 */
add_action(
	'wp_head',
	function () {
		if ( is_admin() ) {
			return;
		}

		$css = file_get_contents( UB_PLUGIN_PATH . 'assets/banner.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( $css ) {
			echo '<style id="ub-banner-critical-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	},
	1
);

/**
 * Shortcode: [ultralove_banner group="home"]
 */
add_shortcode(
	'ultralove_banner',
	function ( $atts ) {
		$atts = shortcode_atts( array( 'group' => '' ), $atts, 'ultralove_banner' );

		if ( empty( $atts['group'] ) ) {
			return '';
		}

		$slides = get_posts(
			array(
				'post_type'      => 'ub_banner_slide',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_ub_group',
						'value' => sanitize_key( $atts['group'] ),
					),
				),
			)
		);

		if ( empty( $slides ) ) {
			return '';
		}

		wp_enqueue_style( 'ub-banner' );
		wp_enqueue_script( 'ub-banner' );

		$is_home  = 'home' === $atts['group'];
		$bg_style = $is_home ? ub_home_bg_style() : 'black';
		// wp_get_attachment_image()'s default `sizes` comes from a theme-wide filter built for
		// product-grid thumbnails ("...33vw, 230px"), not a full-bleed banner - it tells the browser
		// this image will only ever be ~230px wide, so on any desktop viewport it picks a small,
		// visibly-upscaled srcset candidate regardless of the source's real resolution. Overriding it
		// here with the banner's actual responsive width (matching --ub-per-view in banner.css) fixes
		// that mismatch for every slide, not just the ones with a genuinely small source image.
		$img_sizes = $is_home ? '(min-width: 768px) 50vw, 100vw' : '100vw';

		$group_id    = 'ub-banner-' . sanitize_html_class( $atts['group'] );
		$group_class = ' ub-banner-' . sanitize_html_class( $atts['group'] );
		$is_looped   = count( $slides ) > 1;
		$group_class .= $is_looped ? ' ub-banner-looped' : '';
		if ( 'blur' === $bg_style ) {
			$group_class .= ' ub-banner-bg-blur';
		} elseif ( 'aurora' === $bg_style ) {
			$group_class .= ' ub-banner-bg-aurora';
		}
		// A looped track gets this many clones of the last slides prepended and of the first slides
		// appended (one per slide shown at a time), so dragging past either end still shows full slides.
		$clones = $is_looped ? min( ub_group_per_view( $atts['group'] ), count( $slides ) ) : 0;

		$render_slide = function ( $slide, $eager, $is_clone, $is_lcp = false ) use ( $bg_style, $img_sizes, $atts ) {
			$link     = ub_slide_link( $slide->ID );
			$thumb_id = get_post_thumbnail_id( $slide->ID );
			$bg_url   = '';
			if ( 'blur' === $bg_style ) {
				// wp_get_attachment_image_url() falls back to the full, unresized original when an
				// attachment has no local 'medium' intermediate - serving the whole multi-MB original
				// just to blur it into a backdrop. wp_get_attachment_image()'s own src, extracted the
				// same way the real <img> below is built, reliably resolves to a small derivative.
				$bg_html = wp_get_attachment_image( $thumb_id, 'medium_large' );
				if ( preg_match( '/\ssrc="([^"]*)"/', $bg_html, $bg_match ) ) {
					$bg_url = html_entity_decode( $bg_match[1], ENT_QUOTES );
				}
			}
			$crop = ub_get_crop( $slide->ID, $thumb_id );
			?>
			<a class="ub-banner-slide<?php echo $crop ? ' ub-banner-slide--cropped' : ''; ?>" href="<?php echo esc_url( $link ); ?>"<?php echo $bg_url ? ' style="' . esc_attr( "--ub-bg:url('" . esc_url_raw( $bg_url ) . "')" ) . '"' : ''; ?><?php echo $is_clone ? ' aria-hidden="true" tabindex="-1"' : ''; ?>>
				<?php
				$img_atts = array(
					'class'   => 'ub-banner-img',
					'loading' => $eager ? 'eager' : 'lazy',
					'alt'     => esc_attr( get_the_title( $slide->ID ) ),
					'sizes'   => $img_sizes,
				);
				if ( $is_lcp ) {
					// The very first (non-clone) slide is the largest-contentful-paint candidate; ask
					// the browser to fetch it ahead of lower-priority page resources.
					$img_atts['fetchpriority'] = 'high';
				}
				$full_meta = wp_get_attachment_metadata( $thumb_id );
				if ( $crop ) {
					$img_atts['data-ub-crop'] = implode( ',', $crop );
					if ( ! empty( $full_meta['width'] ) && ! empty( $full_meta['height'] ) ) {
						// The image's size and the slide's shape, for the CSS crop used when Cloudinary
						// can't apply it, and the factor to the original upload's pixels for the
						// Cloudinary crop (see ub_original_scale()).
						$img_atts['data-ub-full'] = $full_meta['width'] . ',' . $full_meta['height'] . ',' . round( (float) ub_group_ratio( $atts['group'] ), 5 ) . ',' . round( ub_original_scale( $thumb_id, $full_meta ), 6 );
					}
				}
				if ( is_admin() && $crop && ! empty( $full_meta['width'] ) && ! empty( $full_meta['height'] ) ) {
					// Admin previews aren't rewritten by the Cloudinary plugin, so show the crop with CSS.
					$dx = $full_meta['width'] - $crop['w'];
					$dy = $full_meta['height'] - $crop['h'];
					printf(
						'<span class="ub-banner-img" role="img" aria-label="%1$s" style="display:block;background:url(%2$s) no-repeat;background-size:%3$.3f%% auto;background-position:%4$.3f%% %5$.3f%%"></span>',
						esc_attr( get_the_title( $slide->ID ) ),
						esc_url( wp_get_attachment_image_url( $thumb_id, 'full' ) ),
						$full_meta['width'] / $crop['w'] * 100,
						$dx > 0 ? $crop['x'] / $dx * 100 : 0,
						$dy > 0 ? $crop['y'] / $dy * 100 : 0
					);
				} else {
					echo wp_get_attachment_image( $thumb_id, 'ub_banner', false, $img_atts ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</a>
			<?php
		};

		ob_start();
		?>
		<div class="ub-banner<?php echo esc_attr( $group_class ); ?>" id="<?php echo esc_attr( $group_id ); ?>" data-autoplay="6000" data-clones="<?php echo (int) $clones; ?>" style="--ub-clones:<?php echo (int) $clones; ?>">
			<div class="ub-banner-track">
				<?php foreach ( $clones ? array_slice( $slides, -$clones ) : array() as $slide ) : ?>
					<?php $render_slide( $slide, false, true ); ?>
				<?php endforeach; ?>
				<?php foreach ( $slides as $index => $slide ) : ?>
					<?php $render_slide( $slide, $index < ub_group_per_view( $atts['group'] ), false, 0 === $index ); ?>
				<?php endforeach; ?>
				<?php foreach ( array_slice( $slides, 0, $clones ) as $slide ) : ?>
					<?php $render_slide( $slide, false, true ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
);
