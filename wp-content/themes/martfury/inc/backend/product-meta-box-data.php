<?php
/**
 * Functions and Hooks for product meta box data
 *
 * @package Martfury
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * martfury_Meta_Box_Product_Data class.
 */
class Martfury_Meta_Box_Product_Data {

	/**
	 * Constructor.
	 */
	public function __construct() {

		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		// Add admin style
		add_filter( 'woocommerce_screen_ids', array( $this, 'brand_screen_ids' ), 20 );

		// Add form
		add_action( 'woocommerce_product_data_panels', array( $this, 'product_meta_fields' ) );
		add_action( 'woocommerce_product_data_tabs', array( $this, 'product_meta_tab' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'product_meta_fields_save' ) );

		add_action( 'wp_ajax_product_meta_fields', array( $this, 'instance_product_meta_fields' ) );
		add_action( 'wp_ajax_nopriv_product_meta_fields', array( $this, 'instance_product_meta_fields' ) );

	}

	/**
	 * Add  all WooCommerce screen ids.
	 *
	 * @since  1.0
	 *
	 * @param  array $screen_ids
	 *
	 * @return array
	 */
	function brand_screen_ids( $screen_ids ) {
		$screen_ids[] = 'edit-mf_product_brand';

		return $screen_ids;
	}


	/**
	 * Get product data fields
	 *
	 */
	public function instance_product_meta_fields() {
		$post_id = $_POST['post_id'];
		ob_start();
		$this->create_product_extra_fields( $post_id );
		$response = ob_get_clean();
		wp_send_json_success( $response );
		die();
	}

	/**
	 * Product data tab
	 */
	public function product_meta_tab( $product_data_tabs ) {

		$product_data_tabs['martfury_attributes_extra'] = array(
			'label'  => esc_html__( 'Extra', 'martfury' ),
			'target' => 'product_attributes_extra',
			'class'  => 'product-attributes-extra'
		);

		$product_data_tabs['martfury_pbt_product'] = array(
			'label'  => esc_html__( 'Frequently Bought Together', 'martfury' ),
			'target' => 'product_martfury_pbt_product',
			'class'  => array( 'hide_if_grouped', 'hide_if_external', 'hide_if_bundle' ),
		);

		return $product_data_tabs;
	}

	/**
	 * Add product data fields
	 *
	 */
	public function product_meta_fields() {
		global $post;
		$this->create_product_extra_fields( $post->ID );
	}

	/**
	 * product_meta_fields_save function.
	 *
	 * @param mixed $post_id
	 */
	public function product_meta_fields_save( $post_id ) {

		if ( isset( $_POST['custom_badges_text'] ) ) {
			$woo_data = $_POST['custom_badges_text'];
			update_post_meta( $post_id, 'custom_badges_text', $woo_data );
		}

		if ( isset( $_POST['_is_new'] ) ) {
			$woo_data = $_POST['_is_new'];
			update_post_meta( $post_id, '_is_new', $woo_data );
		} else {
			update_post_meta( $post_id, '_is_new', 0 );
		}

		if ( isset( $_POST['mf_pbt_product_ids'] ) ) {
			$woo_data = $_POST['mf_pbt_product_ids'];
			update_post_meta( $post_id, 'mf_pbt_product_ids', $woo_data );
		} else {
			update_post_meta( $post_id, 'mf_pbt_product_ids', 0 );
		}
	}

	/**
	 * Create product meta fields
	 *
	 * @param $post_id
	 */
	public function create_product_extra_fields( $post_id ) {
		global $post;

		echo '<div id="product_attributes_extra" class="panel woocommerce_options_panel">';
		woocommerce_wp_text_input(
			array(
				'id'       => 'custom_badges_text',
				'label'    => esc_html__( 'Custom Badge Text', 'martfury' ),
				'desc_tip' => esc_html__( 'Enter this optional to show your badges.', 'martfury' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_is_new',
				'label'       => esc_html__( 'New product?', 'martfury' ),
				'description' => esc_html__( 'Enable to set this product as a new product. A "New" badge will be added to this product.', 'martfury' ),
			)
		);
		echo '</div>';
		?>
        <div id="product_martfury_pbt_product" class="panel woocommerce_options_panel">
            <p class="form-field">
                <label for="mf_pbt_product_ids"><?php esc_html_e( 'Select Products', 'martfury' ); ?></label>
                <select class="wc-product-search" multiple="multiple" style="width: 50%;" id="mf_pbt_product_ids"
                        name="mf_pbt_product_ids[]"
                        data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'martfury' ); ?>"
                        data-action="woocommerce_json_search_products_and_variations"
                        data-exclude="<?php echo intval( $post->ID ); ?>">
					<?php
					$product_ids = maybe_unserialize( get_post_meta( $post->ID, 'mf_pbt_product_ids', true ) );

					if ( $product_ids && is_array( $product_ids ) ) {
						foreach ( $product_ids as $product_id ) {
							$product = wc_get_product( $product_id );
							if ( is_object( $product ) ) {
								echo '<option value="' . esc_attr( $product_id ) . '"' . selected( true, true, false ) . '>' . wp_kses_post( $product->get_formatted_name() ) . '</option>';
							}
						}
					}

					?>
                </select> <?php echo wc_help_tip( __( 'Select products for "Frequently bought together" group.', 'martfury' ) ); ?>
            </p>
        </div>
		<?php

	}
}

new Martfury_Meta_Box_Product_Data;