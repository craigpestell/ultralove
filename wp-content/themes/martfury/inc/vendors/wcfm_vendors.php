<?php

/**
 * Class for all Vendor template modification
 *
 * @version 1.0
 */
class Martfury_WCFMVendors {

	/**
	 * Construction function
	 *
	 * @since  1.0
	 * @return Martfury_Vendor
	 */
	function __construct() {
		// Check if Woocomerce plugin is actived
		if ( ! class_exists( 'WCFMmp' ) ) {
			return;
		}

		//remove display vendor by plugin
		add_filter( 'wcfmmp_is_allow_archive_product_sold_by', '__return_false' );

		switch ( martfury_get_option( 'catalog_vendor_name' ) ) {
			case 'display':
				// Always Display sold by
				add_action( 'woocommerce_shop_loop_item_title', array( $this, 'product_loop_display_sold_by' ), 6 );

				// Display sold by in product list
				add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'product_loop_sold_by' ), 7 );

				// Display sold by on hover
				add_action( 'martfury_product_loop_details_hover', array( $this, 'product_loop_sold_by' ), 15 );

				// Display sold by in product deals
				add_action( 'martfury_woo_after_shop_loop_item_title', array( $this, 'product_loop_sold_by' ), 20 );
				break;

			case 'hover':

				if ( martfury_get_option( 'product_loop_hover' ) == '3' ) {
					// Always Display sold by
					add_action( 'woocommerce_shop_loop_item_title', array(
						$this,
						'product_loop_display_sold_by'
					), 6 );
				}

				// Display sold by in product list
				add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'product_loop_sold_by' ), 7 );

				// Display sold by on hover
				add_action( 'martfury_product_loop_details_hover', array( $this, 'product_loop_sold_by' ), 15 );

				// Display sold by in product deals
				add_action( 'martfury_woo_after_shop_loop_item_title', array( $this, 'product_loop_sold_by' ), 20 );
				break;
			case 'profile':

				// Always Display sold by
				add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'display_vendor_profile' ), 10 );

				// Display sold by on hover
				add_action( 'martfury_product_loop_details_hover', array( $this, 'display_vendor_profile' ), 45 );

				// Display sold by in product deals
				add_action( 'martfury_woo_after_shop_loop_item', array( $this, 'display_vendor_profile' ), 20 );
				break;
		}


		if ( martfury_get_option( 'wcfm_single_sold_by_template' ) == 'theme' ) {
			add_filter( 'wcfmmp_is_allow_single_product_sold_by', '__return_false' );

			add_action( 'martfury_single_product_header', array(
				$this,
				'product_loop_sold_by',
			) );
		}

		add_filter( 'body_class', array(
			$this,
			'wcfm_body_classes',
		) );

		if ( martfury_get_option( 'wcfm_store_header_layout' ) == 'theme' ) {

			add_filter( 'wcfm_is_allow_store_name_on_header', '__return_true' );
			add_filter( 'wcfm_is_allow_store_name_on_banner', '__return_false' );
		}

		add_filter( 'martfury_site_content_container_class', array( $this, 'vendor_dashboard_container_class' ) );
		add_filter( 'martfury_page_header_container_class', array( $this, 'vendor_dashboard_container_class' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 30 );

		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'catalog_mode_loop_add_to_cart' ) );

		add_filter( 'woocommerce_get_price_html', array( $this, 'catalog_mode_loop_price' ), 20, 2 );


		// Settings
		if ( class_exists( 'TAWC_Deals' ) ) {
			add_filter( 'wcfm_product_manage_fields_pricing', array( $this, 'product_manage_fields_pricing' ), 20, 2 );
		}

		add_filter( 'wcfm_product_manage_fields_linked', array( $this, 'products_custom_fields_linked' ), 100, 3 );

		add_action( 'after_wcfm_products_manage_meta_save', array( $this, 'product_meta_save' ), 500, 2 );

		add_filter( 'wcfmmp_stores_default_args', array( $this, 'stores_list_default_args' ) );

		add_action( 'after_wcfm_products_manage_linked', array( $this, 'products_custom_fields' ), 20, 2 );

	}

	/**
	 * Enqueue styles and scripts.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'martfury-wcfm', get_template_directory_uri() . '/css/vendors/wcfm-vendor.css', array(), '20201126' );
	}


	function product_loop_display_sold_by() {
		echo '<div class="mf-vendor-name">';
		$this->product_loop_sold_by();
		echo '</div>';
	}


	function product_loop_sold_by() {

		if ( ! class_exists( 'WCFM' ) ) {
			return;
		}

		global $WCFM, $post, $WCFMmp;

		if( ! $post ) {
			return;
        }

		$vendor_id = $WCFM->wcfm_vendor_support->wcfm_get_vendor_id_from_product( $post->ID );

		if ( ! $vendor_id ) {
			return;
		}

		$sold_by_text = apply_filters( 'wcfmmp_sold_by_label', esc_html__( 'Sold By:', 'martfury' ) );
		if ( $WCFMmp ) {
			$sold_by_text = $WCFMmp->wcfmmp_vendor->sold_by_label( absint( $vendor_id ) );
		}
		$store_name = $WCFM->wcfm_vendor_support->wcfm_get_vendor_store_by_vendor( absint( $vendor_id ) );

		echo '<div class="sold-by-meta">';
		echo '<span class="sold-by-label">' . $sold_by_text . ': ' . '</span>';
		echo wp_kses_post( $store_name );
		echo '</div>';
	}

	function display_vendor_profile() {
		global $WCFM, $WCFMmp, $product;

		if ( function_exists( 'wcfm_is_store_page' ) && wcfm_is_store_page() ) {
			return;
		}
		if ( ! $product ) {
			return;
		}
		if ( ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		if ( $WCFMmp->wcfmmp_vendor->is_vendor_sold_by() ) {
			$product_id = $product->get_id();

			$vendor_id = wcfm_get_vendor_id_by_post( $product_id );

			if ( apply_filters( 'wcfmmp_is_allow_archive_sold_by_advanced', false ) ) {
				$WCFMmp->template->get_template( 'sold-by/wcfmmp-view-sold-by-advanced.php', array(
					'product_id' => $product_id,
					'vendor_id'  => $vendor_id
				) );
			} else {
				$WCFMmp->template->get_template( 'sold-by/wcfmmp-view-sold-by-simple.php', array(
					'product_id' => $product_id,
					'vendor_id'  => $vendor_id
				) );
			}
		}
	}

	function wcfm_body_classes( $classes ) {
		if ( function_exists( 'wcfm_is_store_page' ) && wcfm_is_store_page() && martfury_get_option( 'wcfm_store_header_layout' ) == 'theme' ) {
			$classes[] = 'wcfm-template-themes';
		}

		if ( martfury_get_option( 'catalog_vendor_name' ) == 'profile' ) {
			$classes[] = 'mf-vendor-profile';
		}

		return $classes;
	}

	function vendor_dashboard_container_class( $container ) {

		if ( ! function_exists( 'is_wcfm_page' ) ) {
			return $container;
		}

		if ( is_wcfm_page() ) {
			if ( intval( martfury_get_option( 'vendor_dashboard_full_width' ) ) ) {
				$container = 'martfury-container';
			}
		}

		return $container;
	}

	function catalog_mode_loop_add_to_cart( $html ) {

		global $product;

		if ( get_post_meta( $product->get_id(), '_catalog', true ) == 'yes' ) {
			if ( get_post_meta( $product->get_id(), 'disable_add_to_cart', true ) == 'yes' ) {
				return false;
			}
		}

		return $html;

	}

	function catalog_mode_loop_price( $html, $product ) {

		if ( get_post_meta( $product->get_id(), '_catalog', true ) == 'yes' ) {
			if ( get_post_meta( $product->get_id(), 'disable_price', true ) == 'yes' ) {
				return false;
			}
		}

		return $html;
	}

	function product_manage_fields_pricing( $fields, $product_id ) {
		$quantity                 = get_post_meta( $product_id, '_deal_quantity', true );
		$sales_counts             = get_post_meta( $product_id, '_deal_sales_counts', true );
		$sales_counts             = intval( $sales_counts );
		$fields["_deal_quantity"] = array(
			'label'       => esc_html__( 'Sale quantity', 'martfury' ),
			'type'        => 'number',
			'class'       => 'wcfm-text wcfm_ele wcfm_half_ele sales_schedule_ele simple external non-variable-subscription non-auction non-redq_rental non-accommodation-booking',
			'label_class' => 'wcfm_ele wcfm_half_ele_title sales_schedule_ele wcfm_title simple external non-variable-subscription non-auction non-redq_rental non-accommodation-booking',
			'hints'       => esc_html__( 'Set this quantity will make the product to be a deal. The sale will end when this quantity is sold out.', 'martfury' ),
			'value'       => $quantity
		);

		$fields["_deal_sales_counts"] = array(
			'label'       => esc_html__( 'Sold Items', 'martfury' ),
			'type'        => 'number',
			'class'       => 'wcfm-text wcfm_ele wcfm_half_ele sales_schedule_ele simple external non-variable-subscription non-auction non-redq_rental non-accommodation-booking',
			'label_class' => 'wcfm_ele wcfm_half_ele_title sales_schedule_ele wcfm_title simple external non-variable-subscription non-auction non-redq_rental non-accommodation-booking',
			'hints'       => esc_html__( 'Set this sold items should be less than the sale quantity.', 'martfury' ),
			'value'       => $sales_counts
		);

		return $fields;
	}

	function product_meta_save( $new_product_id, $wcfm_products_manage_form_data ) {
		global $WCFM;

		if ( class_exists( 'TAWC_Deals' ) ) {
			$_deal_quantity     = ( isset( $wcfm_products_manage_form_data['_deal_quantity'] ) ) ? intval( $wcfm_products_manage_form_data['_deal_quantity'] ) : 0;
			$_deal_sales_counts = ( isset( $wcfm_products_manage_form_data['_deal_sales_counts'] ) ) ? intval( $wcfm_products_manage_form_data['_deal_sales_counts'] ) : 0;
			update_post_meta( $new_product_id, '_deal_quantity', $_deal_quantity );
			if ( $_deal_quantity >= $_deal_sales_counts ) {
				update_post_meta( $new_product_id, '_deal_sales_counts', $_deal_sales_counts );
			}
		}

		$pbt_product_ids = ( isset( $wcfm_products_manage_form_data['mf_pbt_product_ids'] ) ) ? array_map( 'intval', (array) $wcfm_products_manage_form_data['mf_pbt_product_ids'] ) : array();
		update_post_meta( $new_product_id, 'mf_pbt_product_ids', $pbt_product_ids );

		// Video
		$video_url = ( isset( $wcfm_products_manage_form_data['video_url'] ) ) ? $wcfm_products_manage_form_data['video_url'] : '';
		update_post_meta( $new_product_id, 'video_url', $video_url );

		$video_thumbnail_src = ( isset( $wcfm_products_manage_form_data['video_thumbnail_src'] ) ) ? $wcfm_products_manage_form_data['video_thumbnail_src'] : '';

		$video_thumbnail_id = $WCFM->wcfm_get_attachment_id( $video_thumbnail_src );

		update_post_meta( $new_product_id, 'video_thumbnail', $video_thumbnail_id );

		$video_position = ( isset( $wcfm_products_manage_form_data['video_position'] ) ) ? $wcfm_products_manage_form_data['video_position'] : '';
		update_post_meta( $new_product_id, 'video_position', $video_position );

		$product_360_ids = array();
		if ( isset( $wcfm_products_manage_form_data['product_360_view_src'] ) ) {
			foreach ( $wcfm_products_manage_form_data['product_360_view_src'] as $gallery_imgs ) {
				$product_360_src = isset( $gallery_imgs['image'] ) ? $gallery_imgs['image'] : '';
				if ( $product_360_src ) {
					$product_360_ids[] = $WCFM->wcfm_get_attachment_id( $product_360_src );
				}

			}
		}

		if ( ! empty( $product_360_ids ) ) {
			update_post_meta( $new_product_id, 'wcfm_product_360_view', implode( ',', $product_360_ids ) );
		} else {
			update_post_meta( $new_product_id, 'wcfm_product_360_view', '' );
		}

		$custom_badges_text = ( isset( $wcfm_products_manage_form_data['custom_badges_text'] ) ) ? $wcfm_products_manage_form_data['custom_badges_text'] : '';
		$_is_new            = ( isset( $wcfm_products_manage_form_data['_is_new'] ) ) ? 'yes' : 'no';
		update_post_meta( $new_product_id, 'custom_badges_text', $custom_badges_text );
		update_post_meta( $new_product_id, '_is_new', $_is_new );
	}

	function products_custom_fields_linked( $fields, $product_id, $products_array ) {

		if ( ! intval( martfury_get_option( 'product_fbt' ) ) ) {
			return $fields;
		}

		if ( ! in_array( 'fbt', martfury_get_option( 'wcfm_dashboard_custom_fields' ) ) ) {
			return $fields;
		}

		$pbt_product_ids = get_post_meta( $product_id, 'mf_pbt_product_ids', true );
		$pbt_product_ids = $pbt_product_ids ? $pbt_product_ids : array();
		if ( ! empty( $pbt_product_ids ) ) {
			foreach ( $pbt_product_ids as $pbt_product_id ) {
				$products_array[ $pbt_product_id ] = get_post( absint( $pbt_product_id ) )->post_title;
			}
		}
		$fields["mf_pbt_product_ids"] = array(
			'label'       => esc_html__( 'Frequently Bought Together', 'martfury' ),
			'type'        => 'select',
			'attributes'  => array( 'multiple' => 'multiple', 'style' => 'width: 60%;' ),
			'class'       => 'wcfm-select wcfm_ele simple variable',
			'label_class' => 'wcfm_title',
			'options'     => $products_array,
			'value'       => $pbt_product_ids,
		);

		return $fields;

	}

	function products_custom_fields( $product_id, $product_type ) {
		global $WCFM;
		if ( in_array( 'video', martfury_get_option( 'wcfm_dashboard_custom_fields' ) ) ) {
			?>
			<!-- collapsible 8 - Product Video -->
			<div class="page_collapsible products_manage_video <?php echo apply_filters( 'wcfm_pm_block_class_linked', 'simple variable external grouped' ); ?> <?php echo apply_filters( 'wcfm_pm_block_custom_class_linked', '' ); ?>"
			     id="wcfm_products_manage_form_linked_head"><label
					class="wcfmfa fa-video"></label><?php esc_html_e( 'Product Video', 'martfury' ); ?><span></span>
			</div>
			<div class="wcfm-container simple variable external grouped <?php echo apply_filters( 'wcfm_pm_block_custom_class_linked', '' ); ?>">
				<div id="wcfm_products_manage_form_linked_expander" class="wcfm-content">
					<?php

					$video_url           = get_post_meta( $product_id, 'video_url', true );
					$video_thumbnail_id  = get_post_meta( $product_id, 'video_thumbnail', true );
					$image_thumbnail     = wp_get_attachment_image_src( $video_thumbnail_id, 'full' );
					$video_thumbnail_src = $image_thumbnail ? $image_thumbnail[0] : '';
					$video_position      = get_post_meta( $product_id, 'video_position', true );
					$WCFM->wcfm_fields->wcfm_generate_form_field( apply_filters( 'wcfm_product_manage_fields_video', array(
						"video_url"           => array(
							'label'       => esc_html__( 'Video URL', 'martfury' ),
							'type'        => 'text',
							'class'       => 'wcfm-text wcfm_ele simple variable external grouped booking',
							'label_class' => 'wcfm_title',
							'value'       => $video_url
						),
						"video_thumbnail_src" => array(
							'label'       => esc_html__( 'Video Thumbnail', 'martfury' ),
							'type'        => 'upload',
							'class'       => 'wcfm-upload wcfm_ele simple variable external grouped booking',
							'label_class' => 'wcfm_title',
							'value'       => $video_thumbnail_src
						),
						"video_position"      => array(
							'label'       => esc_html__( 'Video Position', 'martfury' ),
							'type'        => 'select',
							'class'       => 'wcfm-select wcfm_ele simple variable external grouped booking',
							'label_class' => 'wcfm_title',
							'options'     => array(
								'1' => esc_html__( 'The last product gallery', 'martfury' ),
								'2' => esc_html__( 'The first product gallery', 'martfury' ),
							),
							'value'       => $video_position
						),

					), $product_id ) );
					?>
				</div>
			</div>
			<!-- end collapsible -->
			<div class="wcfm_clearfix"></div>
		<?php } ?>
		<?php
		if ( in_array( '360', martfury_get_option( 'wcfm_dashboard_custom_fields' ) ) ) {

			?>
			<!-- collapsible 8 - Product 360 -->
			<div class="page_collapsible products_manage_360_view <?php echo apply_filters( 'wcfm_pm_block_class_linked', 'simple variable external grouped' ); ?> <?php echo apply_filters( 'wcfm_pm_block_custom_class_linked', '' ); ?>"
			     id="wcfm_products_manage_form_linked_head"><label
					class="wcfmfa fa-film"></label><?php esc_html_e( 'Product 360 View', 'martfury' ); ?>
				<span></span>
			</div>
			<div class="wcfm-container simple variable external grouped <?php echo apply_filters( 'wcfm_pm_block_custom_class_linked', '' ); ?>">
				<div id="wcfm_products_manage_form_linked_expander" class="wcfm-content">
					<?php
					$images_meta = get_post_meta( $product_id, 'wcfm_product_360_view', true );
					$images_meta = $images_meta ? explode( ',', $images_meta ) : array();
					$images_360  = array();
					if ( $images_meta ) {
						foreach ( $images_meta as $image_id ) {
							$image                 = wp_get_attachment_image_src( $image_id, 'full' );
							$images_360[]['image'] = $image ? $image[0] : '';
						}
					}

					$WCFM->wcfm_fields->wcfm_generate_form_field( apply_filters( 'wcfm_product_manage_fields_video', array(
						"product_360_view_src" => array(
							'label'       => esc_html__( 'Images', 'martfury' ),
							'type'        => 'multiinput',
							'class'       => 'wcfm-text wcfm-gallery_image_upload wcfm_ele simple variable external grouped booking',
							'label_class' => 'wcfm_title',
							'value'       => $images_360,
							'options'     => array(
								"image" => array(
									'type'    => 'upload',
									'class'   => 'wcfm_gallery_upload',
									'prwidth' => 75
								),
							),
						),

					), $product_id ) );
					?>
				</div>
			</div>
			<!-- end collapsible -->
			<div class="wcfm_clearfix"></div>
			<?php
		}

		?>
		<!-- collapsible 8 - Custom  Badges -->
		<div class="page_collapsible products_badges_view <?php echo apply_filters( 'wcfm_pm_block_class_linked', 'simple variable external grouped' ); ?> <?php echo apply_filters( 'wcfm_pm_block_custom_class_linked', '' ); ?>"
		     id="wcfm_products_manage_form_linked_head"><label
				class="wcfmfa fa-globe"></label><?php esc_html_e( 'Badges', 'martfury' ); ?>
			<span></span>
		</div>
		<div class="wcfm-container simple variable external grouped <?php echo apply_filters( 'wcfm_pm_block_custom_class_linked', '' ); ?>">
			<div id="wcfm_products_manage_form_linked_expander" class="wcfm-content">
				<?php
				$custom_badges_text = get_post_meta( $product_id, 'custom_badges_text', true );
				$is_new             = get_post_meta( $product_id, '_is_new', true );
				$_is_new_enable     = $is_new === 'yes' ? 'enable' : '';
				$WCFM->wcfm_fields->wcfm_generate_form_field( apply_filters( 'wcfm_product_manage_fields_badges', array(
					"custom_badges_text" => array(
						'label'       => esc_html__( 'Custom Badge Text', 'martfury' ),
						'type'        => 'text',
						'class'       => 'wcfm-text wcfm_ele simple variable external grouped booking',
						'label_class' => 'wcfm_title',
						'value'       => $custom_badges_text
					),
					"_is_new"            => array(
						'label'       => esc_html__( 'New product?', 'martfury' ),
						'type'        => 'checkbox',
						'class'       => 'wcfm-checkbox wcfm_ele simple variable external grouped booking',
						'label_class' => 'wcfm_title',
						'hints'       => esc_html__( 'Enable to set this product as a new product. A "New" badge will be added to this product.', 'martfury' ),
						'value'       => 'enable',
						'dfvalue'     => $_is_new_enable
					),
				), $product_id ) );
				?>
			</div>
		</div>
		<!-- end collapsible -->
		<div class="wcfm_clearfix"></div>
		<?php
	}

	function stores_list_default_args( $default ) {
		$default['per_row']  = 2;
		$default['per_page'] = 8;
		$default['theme']    = '';

		return $default;
	}

}

