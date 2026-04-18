<?php

/**
 * Class for all Vendor template modification
 *
 * @version 1.0
 */
class Martfury_Dokan {

	/**
	 * Construction function
	 *
	 * @since  1.0
	 * @return Martfury_Vendor
	 */
	function __construct() {
		if ( ! class_exists( 'WeDevs_Dokan' ) ) {
			return;
		}

		// Define all hook
		add_filter( 'dokan_settings_fields', array( $this, 'dokan_settings_fields' ) );

		switch ( martfury_get_option( 'catalog_vendor_name' ) ) {
			case 'display':
				// Always Display sold by
				add_action( 'woocommerce_shop_loop_item_title', array( $this, 'template_loop_display_sold_by' ), 6 );

				// Display sold by in product list
				add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'template_loop_sold_by' ), 7 );

				// Display sold by on hover
				add_action( 'martfury_product_loop_details_hover', array( $this, 'template_loop_sold_by' ), 15 );

				// Display sold by in product deals
				add_action( 'martfury_woo_after_shop_loop_item_title', array( $this, 'template_loop_sold_by' ), 20 );
				break;

			case 'hover':

				if ( martfury_get_option( 'product_loop_hover' ) == '3' ) {
					// Always Display sold by
					add_action( 'woocommerce_shop_loop_item_title', array(
						$this,
						'template_loop_display_sold_by'
					), 6 );
				}

				// Display sold by in product list
				add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'template_loop_sold_by' ), 7 );

				// Display sold by on hover
				add_action( 'martfury_product_loop_details_hover', array( $this, 'template_loop_sold_by' ), 15 );

				// Display sold by in product deals
				add_action( 'martfury_woo_after_shop_loop_item_title', array( $this, 'template_loop_sold_by' ), 20 );
		}


		// Display sold by in single product
		add_action( 'martfury_single_product_header', array( $this, 'template_single_sold_by' ) );


		add_filter( 'dokan_dashboard_nav_common_link', array( $this, 'dashboard_nav_common_link' ) );

		add_filter( 'body_class', array( $this, 'body_classes' ) );

		add_action( 'dokan_enqueue_scripts', array( $this, 'enqueue_scripts' ), 30 );

		add_filter( 'martfury_site_content_container_class', array( $this, 'vendor_dashboard_container_class' ) );
		add_filter( 'martfury_page_header_container_class', array( $this, 'vendor_dashboard_container_class' ) );

		// Settings

		add_action( 'dokan_new_product_after_product_tags', array( $this, 'add_product_brand_field' ) );
		add_action( 'dokan_new_product_added', array( $this, 'new_product_brand_added' ), 20, 2 );
		add_action( 'dokan_product_updated', array( $this, 'new_product_brand_added' ), 20, 2 );

		add_action( 'dokan_product_edit_after_product_tags', array( $this, 'edit_product_brand_field' ), 20, 2 );

	}

	function enqueue_scripts() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			wp_enqueue_style( 'dokan-social-style' );
			wp_enqueue_style( 'dokan-social-theme-flat' );
		}
		wp_enqueue_style( 'martfury-dokan', get_template_directory_uri() . '/css/vendors/dokan.css', array(), '20201126' );
	}

	/**
	 * Adds custom classes to the array of body classes.
	 *
	 * @since 1.0
	 *
	 * @param array $classes Classes for the body element.
	 *
	 * @return array
	 */
	function body_classes( $classes ) {
		// Adds a class of group-blog to blogs with more than 1 published author.
		if ( martfury_is_vendor_page() ) {
			$shop_view = isset( $_COOKIE['shop_view'] ) ? $_COOKIE['shop_view'] : martfury_get_option( 'catalog_view_12' );
			$classes[] = 'shop-view-' . $shop_view;
			$classes[] = 'woocommerce';
		}

		return $classes;
	}

	function template_loop_display_sold_by() {
		echo '<div class="mf-vendor-name">';
		$this->template_loop_sold_by();
		echo '</div>';
	}

	/**
	 * Add sold by
	 */
	function template_loop_sold_by() {
		get_template_part( 'template-parts/vendor/loop', 'sold-by' );
	}

	function template_single_sold_by() {
		if ( ! intval( martfury_get_option( 'product_vendor_name' ) ) ) {
			return;
		}

		echo '<div class="mf-summary-meta">';
		get_template_part( 'template-parts/vendor/loop', 'sold-by' );
		echo '</div>';
	}


	/**
	 * dashboard_nav_common_link
	 *
	 * @param $common_links
	 */
	function dashboard_nav_common_link( $common_links ) {
		if ( ! function_exists( 'dokan_get_store_url' ) && ! function_exists( 'dokan_get_navigation_url' ) ) {
			return $common_links;
		}

		if ( martfury_get_option( 'dokan_dashboard_layout' ) == '2' ) {
			return $common_links;
		}

		$common_links = sprintf(
			'<li class="dokan-common-links dokan-clearfix">' .
			'<a href="%s" ><i class="fa fa-external-link"></i> <span>%s</span></a >' .
			'<a href="%s" ><i class="fa fa-user"></i><span>%s</span></a >' .
			'<a href="%s" ><i class="fa fa-power-off"></i><span>%s</span></a >' .
			'</li>',
			esc_url( dokan_get_store_url( get_current_user_id() ) ),
			esc_html__( 'Visit Store', 'martfury' ),
			esc_url( dokan_get_navigation_url( 'edit-account' ) ),
			esc_html__( 'Edit Account', 'martfury' ),
			esc_url( wp_logout_url( home_url() ) ),
			esc_html__( 'Log out', 'martfury' )

		);


		return $common_links;
	}

	/**
	 * Dokan Settings Fields
	 */
	function dokan_settings_fields( $settings_fields ) {
		$settings_fields['dokan_appearance']['store_header_template']['options']['mf_custom'] = get_template_directory_uri() . '/images/vendor.jpg';

		return $settings_fields;
	}

	function vendor_dashboard_container_class( $container ) {
		if ( ! function_exists( 'dokan_get_option' ) ) {
			return $container;
		}
		$page_id = dokan_get_option( 'dashboard', 'dokan_pages' );

		if ( empty( $page_id ) ) {
			return $container;
		}

		if ( is_page( $page_id ) || ( get_query_var( 'edit' ) && is_singular( 'product' ) ) ) {
			if ( intval( martfury_get_option( 'vendor_dashboard_full_width' ) ) ) {
				$container = 'martfury-container';
			}
		}

		return $container;


	}

	function new_product_brand_added( $product_id, $data ) {
		if ( isset( $data['product_brand'] ) && ! empty( $data['product_brand'] ) ) {
			$brand_ids = array_map( 'absint', (array) $data['product_brand'] );
			wp_set_object_terms( $product_id, $brand_ids, 'product_brand' );
		}
	}

	function add_product_brand_field() {
		if ( ! taxonomy_exists('product_brand' ) ) {
			return;
		}

		?>
		<div class="dokan-form-group">
			<?php
			$drop_down_brands = wp_dropdown_categories( array(
				'show_option_none' => __( '- Select a brand -', 'martfury' ),
				'hierarchical'     => 1,
				'hide_empty'       => 0,
				'name'             => 'product_brand[]',
				'id'               => 'product_brand',
				'taxonomy'         => 'product_brand',
				'title_li'         => '',
				'class'            => 'product_cat dokan-form-control dokan-select2',
				'exclude'          => '',
				'selected'         => '',
				'echo'             => 0
			) );

			echo str_replace( '<select', '<select data-placeholder="' . esc_html__( 'Select product brand', 'martfury' ) . '"', $drop_down_brands ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
			?>
		</div>

		<?php
	}


	function edit_product_brand_field( $post, $post_id ) {
		if ( ! taxonomy_exists('product_brand' ) ) {
			return;
		}
		$term     = wp_get_post_terms( $post_id, 'product_brand', array( 'fields' => 'ids' ) );
		$selected = $term ? $term[0] : '';
		?>
		<label for="product_brand" class="form-label"><?php esc_html_e( 'Brands', 'martfury' ); ?></label>
		<div class="dokan-form-group dokan-form-brand-group">
			<?php
			$drop_down_brands = wp_dropdown_categories( array(
				'show_option_none' => __( '- Select a brand -', 'martfury' ),
				'hierarchical'     => 1,
				'hide_empty'       => 0,
				'name'             => 'product_brand[]',
				'id'               => 'product_brand',
				'taxonomy'         => 'product_brand',
				'title_li'         => '',
				'class'            => 'product_brand dokan-form-control dokan-select2',
				'exclude'          => '',
				'selected'         => $selected,
				'echo'             => 0
			) );

			echo str_replace( '<select', '<select data-placeholder="' . esc_html__( 'Select product brand', 'martfury' ) . '" ', $drop_down_brands ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
			?>
		</div>

		<?php
	}

}