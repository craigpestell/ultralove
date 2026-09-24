<?php
/**
 * Admin screens: a group overview (live previews) and a per-group slide manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const UB_CAP = 'edit_others_posts';

function ub_group_url( $group, $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'ub-group', 'group' => $group ), $args ), admin_url( 'admin.php' ) );
}

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Banner Slides', 'Banner Slides', UB_CAP, 'ub-banners', 'ub_render_overview', 'dashicons-images-alt2', 30 );
		// Re-label the auto-created first submenu (same slug as the parent).
		add_submenu_page( 'ub-banners', 'Banner Groups', 'Groups', UB_CAP, 'ub-banners', 'ub_render_overview' );
		// Hidden page (no menu entry): reached from the overview.
		add_submenu_page( null, 'Banner Group', 'Banner Group', UB_CAP, 'ub-group', 'ub_render_group' );
	}
);

// The group pages replace the plain slide list and "Add New" (edit screens are still reached from
// the group pages, for cropping).
add_action(
	'admin_menu',
	function () {
		remove_submenu_page( 'ub-banners', 'edit.php?post_type=ub_banner_slide' );
		remove_submenu_page( 'ub-banners', 'post-new.php?post_type=ub_banner_slide' );
	},
	99
);

add_filter(
	'parent_file',
	function ( $parent ) {
		global $submenu_file;
		if ( isset( $_GET['page'] ) && 'ub-group' === $_GET['page'] ) {
			$submenu_file = 'ub-banners';
			return 'ub-banners';
		}
		return $parent;
	}
);

add_action(
	'admin_enqueue_scripts',
	function () {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( ! in_array( $page, array( 'ub-banners', 'ub-group' ), true ) ) {
			return;
		}
		// The previews are the real front-end banner, so load its CSS/JS here too.
		wp_enqueue_style( 'ub-banner', UB_PLUGIN_URL . 'assets/banner.css', array(), UB_ASSET_VERSION );
		wp_enqueue_script( 'ub-banner', UB_PLUGIN_URL . 'assets/banner.js', array(), UB_ASSET_VERSION, true );
		wp_enqueue_style( 'ub-admin-pages', UB_PLUGIN_URL . 'assets/admin-pages.css', array(), UB_ASSET_VERSION );

		if ( 'ub-group' === $page ) {
			wp_enqueue_media();
			wp_enqueue_script( 'ub-admin-group', UB_PLUGIN_URL . 'assets/admin-group.js', array( 'jquery', 'jquery-ui-sortable' ), UB_ASSET_VERSION, true );
			ub_enqueue_link_search();
		}
	}
);

/**
 * Overview: one live preview card per group; clicking a card opens that group's page.
 */
function ub_render_overview() {
	if ( ! current_user_can( UB_CAP ) ) {
		wp_die( 'Not allowed.' );
	}
	?>
	<div class="wrap">
		<h1>Banner Slides</h1>
		<p>Each card shows the banner group as it appears on the website. Click a group to manage its images and links.</p>
		<div class="ub-cards">
			<?php
			foreach ( ub_groups() as $slug => $label ) :
				$count = count(
					get_posts(
						array(
							'post_type'      => 'ub_banner_slide',
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'meta_key'       => '_ub_group',
							'meta_value'     => $slug,
						)
					)
				);
				?>
				<div class="ub-card ub-card-<?php echo esc_attr( $slug ); ?>">
					<div class="ub-card-label"><strong><?php echo esc_html( $label ); ?></strong> <span><?php echo (int) $count; ?> slide<?php echo 1 === $count ? '' : 's'; ?></span></div>
					<div class="ub-card-preview">
						<?php
						$html = $count ? do_shortcode( '[ultralove_banner group="' . esc_attr( $slug ) . '"]' ) : '';
						echo $html ? $html : '<p class="ub-empty">No slides yet. Click to add some.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
						$ratio = ub_group_ratio( $slug );
						if ( $ratio ) {
							$rec_w = 1920;
							$rec_h = (int) round( $rec_w / $ratio );
							printf( '<span class="ub-card-dims">%d&times;%dpx</span>', (int) $rec_w, (int) $rec_h ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</div>
					<a class="ub-card-link" href="<?php echo esc_url( ub_group_url( $slug ) ); ?>" aria-label="Manage <?php echo esc_attr( $label ); ?>"></a>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * One row of the group manager (also used, with empty values, as the template for new rows).
 */
function ub_render_group_row( $index, $slide = null ) {
	$id       = $slide ? $slide->ID : 0;
	$image_id = $slide ? (int) get_post_thumbnail_id( $id ) : 0;
	$thumb    = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
	$link     = $slide ? get_post_meta( $id, '_ub_link', true ) : '';
	$post_id  = $slide ? ub_slide_post( $id ) : 0;
	$note     = $post_id ? 'Linked to ' . get_post_type_object( get_post_type( $post_id ) )->labels->singular_name . ': ' . wp_strip_all_tags( get_the_title( $post_id ) ) : '';
	$status   = $slide && 'publish' !== $slide->post_status ? 'draft' : 'publish';
	?>
	<tr class="ub-row" data-index="<?php echo esc_attr( $index ); ?>">
		<td class="ub-handle" title="Drag to reorder"><span class="dashicons dashicons-menu"></span></td>
		<td class="ub-image">
			<input type="hidden" name="slides[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo (int) $id; ?>">
			<input type="hidden" class="ub-image-id" name="slides[<?php echo esc_attr( $index ); ?>][image]" value="<?php echo (int) $image_id; ?>">
			<input type="hidden" class="ub-delete" name="slides[<?php echo esc_attr( $index ); ?>][delete]" value="0">
			<div class="ub-thumb"><?php echo $thumb ? '<img src="' . esc_url( $thumb ) . '" alt="">' : '<span>No image</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<button type="button" class="button button-small ub-pick"><?php echo $thumb ? 'Change image' : 'Choose image'; ?></button>
		</td>
		<td>
			<label>Title<br><input type="text" class="widefat" name="slides[<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $slide ? $slide->post_title : '' ); ?>"></label>
			<label>Link <span class="ub-req">*</span><br><input type="text" class="widefat ub-link" name="slides[<?php echo esc_attr( $index ); ?>][link]" value="<?php echo esc_attr( $link ); ?>" placeholder="Search for a product or page, or type a URL" autocomplete="off" required></label>
			<input type="hidden" class="ub-post-id" name="slides[<?php echo esc_attr( $index ); ?>][post]" value="<?php echo (int) $post_id; ?>">
			<span class="ub-post-note description"><?php echo esc_html( $note ); ?></span>
		</td>
		<td>
			<select name="slides[<?php echo esc_attr( $index ); ?>][status]">
				<option value="publish" <?php selected( $status, 'publish' ); ?>>Published</option>
				<option value="draft" <?php selected( $status, 'draft' ); ?>>Draft (hidden)</option>
			</select>
			<?php if ( $id ) : ?>
				<p><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">Edit</a></p>
			<?php endif; ?>
		</td>
		<td><button type="button" class="button-link button-link-delete ub-remove">Delete</button></td>
	</tr>
	<?php
}

function ub_render_group() {
	if ( ! current_user_can( UB_CAP ) ) {
		wp_die( 'Not allowed.' );
	}
	$groups = ub_groups();
	$group  = isset( $_GET['group'] ) ? sanitize_key( $_GET['group'] ) : '';
	if ( ! isset( $groups[ $group ] ) ) {
		wp_die( 'Unknown banner group.' );
	}
	$slides = get_posts(
		array(
			'post_type'      => 'ub_banner_slide',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'meta_key'       => '_ub_group',
			'meta_value'     => $group,
		)
	);
	$msg = isset( $_GET['ub_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ub_msg'] ) ) : '';
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $groups[ $group ] ); ?></h1>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ub-banners' ) ); ?>">&larr; All banner groups</a></p>

		<?php
		$ratio = ub_group_ratio( $group );
		if ( $ratio ) {
			// 1920 matches the 'ub_banner' registered size (the largest this group is ever displayed
			// at), so this is the recommended size, not just a minimum - anything smaller gets
			// upscaled by the browser, and anything larger is wasted bytes.
			$rec_w = 1920;
			$rec_h = (int) round( $rec_w / $ratio );
			?>
			<p class="description">Recommended image size for this group: <strong><?php echo (int) $rec_w; ?>&times;<?php echo (int) $rec_h; ?>px</strong>. Images that don't match this shape are cropped (or letterboxed) to fit.</p>
			<?php
		}
		?>

		<?php if ( 'home' === $group ) : $bg_style = ub_home_bg_style(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ub-bg-style-form">
				<input type="hidden" name="action" value="ub_save_bg_style">
				<?php wp_nonce_field( 'ub_save_bg_style' ); ?>
				<strong>Filler for letterboxed slides:</strong>
				<label><input type="radio" name="ub_bg_style" value="black" <?php checked( $bg_style, 'black' ); ?> onchange="this.form.submit()"> Black</label>
				<label><input type="radio" name="ub_bg_style" value="blur" <?php checked( $bg_style, 'blur' ); ?> onchange="this.form.submit()"> Blurred image</label>
				<label><input type="radio" name="ub_bg_style" value="aurora" <?php checked( $bg_style, 'aurora' ); ?> onchange="this.form.submit()"> Aurora (one shared background with topCTA, no seam)</label>
			</form>
		<?php endif; ?>

		<?php if ( $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['ub_nolink'] ) ) : ?>
			<div class="notice notice-error"><p><?php echo (int) $_GET['ub_nolink']; ?> slide(s) had no link, so they were saved as drafts. Add a Link URL and publish them.</p></div>
		<?php endif; ?>

		<h2>Preview (as on the website)</h2>
		<div class="ub-card ub-card-<?php echo esc_attr( $group ); ?> ub-card-static">
			<div class="ub-card-preview">
				<?php
				$html = do_shortcode( '[ultralove_banner group="' . esc_attr( $group ) . '"]' );
				echo $html ? $html : '<p class="ub-empty">No published slides.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</div>
		</div>

		<h2>Slides</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ub-group-form">
			<input type="hidden" name="action" value="ub_save_group">
			<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
			<?php wp_nonce_field( 'ub_save_group' ); ?>
			<table class="widefat striped ub-table">
				<thead><tr><th></th><th>Image</th><th>Title &amp; link</th><th>Status</th><th></th></tr></thead>
				<tbody id="ub-rows">
					<?php foreach ( $slides as $i => $slide ) : ?>
						<?php ub_render_group_row( $i, $slide ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button" id="ub-add">Add slide</button>
			</p>
			<p class="submit"><button type="submit" class="button button-primary">Save changes</button></p>
			<p class="description">Drag rows to change the order slides appear in. Deleted slides go to the Trash. Edit opens the slide's own screen, where you can crop the image.</p>
		</form>

		<table style="display:none;"><tbody id="ub-row-template"><?php ub_render_group_row( '__INDEX__' ); ?></tbody></table>
	</div>
	<?php
}

add_action(
	'admin_post_ub_save_group',
	function () {
		if ( ! current_user_can( UB_CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'ub_save_group' );

		$groups = ub_groups();
		$group  = isset( $_POST['group'] ) ? sanitize_key( $_POST['group'] ) : '';
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Unknown banner group.' );
		}

		$rows    = isset( $_POST['slides'] ) && is_array( $_POST['slides'] ) ? wp_unslash( $_POST['slides'] ) : array();
		$order   = 0;
		$counts  = array( 'saved' => 0, 'added' => 0, 'deleted' => 0, 'nolink' => 0 );

		foreach ( $rows as $row ) {
			$id     = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$image  = isset( $row['image'] ) ? absint( $row['image'] ) : 0;
			$title  = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
			$link   = isset( $row['link'] ) ? trim( esc_url_raw( trim( $row['link'] ) ) ) : '';
			$status = isset( $row['status'] ) && 'draft' === $row['status'] ? 'draft' : 'publish';
			$target = isset( $row['post'] ) ? absint( $row['post'] ) : 0;
			if ( $target && 'publish' !== get_post_status( $target ) ) {
				$target = 0;
			}
			if ( $target ) {
				$link = wp_make_link_relative( get_permalink( $target ) ); // Kept as a fallback copy of the target's address.
			}

			if ( $id ) {
				// Only touch real slides that belong to this group.
				if ( 'ub_banner_slide' !== get_post_type( $id ) || get_post_meta( $id, '_ub_group', true ) !== $group || ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				if ( ! empty( $row['delete'] ) && '1' === (string) $row['delete'] ) {
					wp_trash_post( $id );
					++$counts['deleted'];
					continue;
				}
			} elseif ( ! $image || ( ! empty( $row['delete'] ) && '1' === (string) $row['delete'] ) ) {
				continue; // Blank or removed new row.
			}

			if ( '' === $link && ! $target && 'publish' === $status ) {
				$status = 'draft';
				++$counts['nolink'];
			}
			if ( '' === $title && $image ) {
				$title = get_the_title( $image );
			}

			$args = array(
				'post_title'  => $title,
				'post_status' => $status,
				'menu_order'  => $order++,
			);
			if ( $id ) {
				wp_update_post( array_merge( array( 'ID' => $id ), $args ) );
				++$counts['saved'];
			} else {
				$id = wp_insert_post( array_merge( $args, array( 'post_type' => 'ub_banner_slide' ) ) );
				if ( is_wp_error( $id ) || ! $id ) {
					continue;
				}
				++$counts['added'];
			}

			update_post_meta( $id, '_ub_group', $group );
			update_post_meta( $id, '_ub_link', $link );
			if ( $target ) {
				update_post_meta( $id, '_ub_post', $target );
			} else {
				delete_post_meta( $id, '_ub_post' );
			}
			if ( $image && (int) get_post_thumbnail_id( $id ) !== $image ) {
				set_post_thumbnail( $id, $image ); // A saved crop belongs to the old image, so it stops applying.
			}
			ub_queue_cloudinary_sync( $image );
		}

		$parts = array();
		foreach ( array( 'saved' => 'updated', 'added' => 'added', 'deleted' => 'deleted' ) as $k => $word ) {
			if ( $counts[ $k ] ) {
				$parts[] = $counts[ $k ] . ' ' . $word;
			}
		}
		$args = array( 'ub_msg' => $parts ? 'Slides ' . implode( ', ', $parts ) . '.' : 'No changes.' );
		if ( $counts['nolink'] ) {
			$args['ub_nolink'] = $counts['nolink'];
		}
		wp_safe_redirect( ub_group_url( $group, $args ) );
		exit;
	}
);

add_action(
	'admin_post_ub_save_bg_style',
	function () {
		if ( ! current_user_can( UB_CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'ub_save_bg_style' );
		$posted = isset( $_POST['ub_bg_style'] ) ? sanitize_key( wp_unslash( $_POST['ub_bg_style'] ) ) : 'black';
		$style  = in_array( $posted, array( 'blur', 'aurora' ), true ) ? $posted : 'black';
		update_option( 'ub_home_bg_style', $style );
		wp_safe_redirect( ub_group_url( 'home' ) );
		exit;
	}
);

/**
 * Link autocomplete: shared assets, used on the group page and the single slide edit screen.
 */
function ub_enqueue_link_search() {
	wp_enqueue_style( 'ub-link-search', UB_PLUGIN_URL . 'assets/link-search.css', array(), UB_ASSET_VERSION );
	wp_enqueue_script( 'ub-link-search', UB_PLUGIN_URL . 'assets/link-search.js', array(), UB_ASSET_VERSION, true );
	wp_localize_script(
		'ub-link-search',
		'ubLinkSearch',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ub_link_search' ),
		)
	);
}

add_action(
	'wp_ajax_ub_link_search',
	function () {
		check_ajax_referer( 'ub_link_search', 'nonce' );
		if ( ! current_user_can( UB_CAP ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$ids   = get_posts(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				's'                   => $term,
				'posts_per_page'      => 10,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
			)
		);
		// WooCommerce products are also findable by SKU (banners are usually made per SKU).
		if ( post_type_exists( 'product' ) ) {
			$ids = array_merge(
				get_posts(
					array(
						'post_type'      => array( 'product', 'product_variation' ),
						'post_status'    => 'publish',
						'posts_per_page' => 5,
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'     => '_sku',
								'value'   => $term,
								'compare' => 'LIKE',
							),
						),
					)
				),
				$ids
			);
		}

		$out = array();
		foreach ( array_slice( array_unique( $ids ), 0, 12 ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			if ( 'product_variation' === $post->post_type && $post->post_parent ) {
				$id = $post->post_parent; // Link to the parent product page.
			}
			$type  = get_post_type_object( get_post_type( $id ) );
			$sku   = get_post_meta( $post->ID, '_sku', true );
			$out[ $id ] = array(
				'id'    => (int) $id,
				'title' => trim( preg_replace( '/\\s+/', ' ', wp_strip_all_tags( html_entity_decode( get_the_title( $id ), ENT_QUOTES ) ) ) ),
				'type'  => $type ? $type->labels->singular_name : '',
				'sku'   => $sku ? $sku : '',
				'url'   => wp_make_link_relative( get_permalink( $id ) ),
			);
		}
		wp_send_json_success( array_values( $out ) );
	}
);
