<?php

// Disable WordPress fuzzy redirect
add_filter( 'do_redirect_guess_404_permalink', function () { return false; } );

// Hide updates for all users except AtmLogin
add_filter( 'hide_updates_allowed_users', function () { return [ 'AtmLogin' ]; } );

add_action( 'wp_enqueue_scripts', 'martfury_child_enqueue_scripts', 20 );
function martfury_child_enqueue_scripts() {
	wp_enqueue_style( 'martfury-child-style', get_stylesheet_uri() );
	if ( is_rtl() ) {
		wp_enqueue_style( 'martfury-rtl', get_template_directory_uri() . '/rtl.css', array(), '20180105' );
	}

    if ( class_exists( 'WooCommerce' ) ) {
        $script_path = get_stylesheet_directory() . '/js/recently-viewed.js';
        $script_url  = get_stylesheet_directory_uri() . '/js/recently-viewed.js';
        $version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : null;

        wp_enqueue_script( 'ultralove-recently-viewed', $script_url, array( 'jquery', 'martfury' ), $version, true );
        wp_localize_script(
            'ultralove-recently-viewed',
            'ulRecentlyViewed',
            array(
                'productId'  => is_singular( 'product' ) ? get_queried_object_id() : 0,
                'storageKey' => 'ultralove_recently_viewed',
                'maxItems'   => 100,
            )
        );
    }
}

add_action( 'init', 'ultralove_bootstrap_recently_viewed', 30 );
function ultralove_bootstrap_recently_viewed() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    remove_action( 'template_redirect', 'wc_track_product_view', 20 );

    global $martfury_woocommerce;

    if ( isset( $martfury_woocommerce ) && is_object( $martfury_woocommerce ) ) {
        remove_action( 'template_redirect', array( $martfury_woocommerce, 'martfury_track_product_view' ) );
        remove_action( 'wc_ajax_martfury_footer_recently_viewed', array( $martfury_woocommerce, 'martfury_footer_recently_viewed' ) );
        remove_action( 'wc_ajax_martfury_header_recently_viewed', array( $martfury_woocommerce, 'martfury_header_recently_viewed' ) );
    }

    add_action( 'wc_ajax_martfury_footer_recently_viewed', 'ultralove_martfury_footer_recently_viewed' );
    add_action( 'wc_ajax_martfury_header_recently_viewed', 'ultralove_martfury_header_recently_viewed' );
}

function ultralove_get_recently_viewed_request_ids() {
    $raw_ids = '';

    if ( isset( $_POST['viewed_products'] ) ) {
        $raw_ids = wp_unslash( $_POST['viewed_products'] );
    }

    if ( is_array( $raw_ids ) ) {
        $raw_ids = implode( '|', $raw_ids );
    }

    if ( ! is_string( $raw_ids ) || $raw_ids === '' ) {
        return array();
    }

    $viewed_products = array_map( 'absint', explode( '|', sanitize_text_field( $raw_ids ) ) );
    $viewed_products = array_values( array_unique( array_filter( $viewed_products ) ) );

    return $viewed_products;
}

function ultralove_render_recently_viewed_from_request( $atts ) {
    $viewed_products = ultralove_get_recently_viewed_request_ids();
    $cookie_name     = 'woocommerce_recently_viewed';
    $had_cookie      = array_key_exists( $cookie_name, $_COOKIE );
    $previous_cookie = $had_cookie ? $_COOKIE[ $cookie_name ] : null;

    if ( empty( $viewed_products ) ) {
        unset( $_COOKIE[ $cookie_name ] );
    } else {
        $_COOKIE[ $cookie_name ] = implode( '|', $viewed_products );
    }

    $output = martfury_recently_viewed_products( $atts );

    if ( $had_cookie ) {
        $_COOKIE[ $cookie_name ] = $previous_cookie;
    } else {
        unset( $_COOKIE[ $cookie_name ] );
    }

    return $output;
}

function ultralove_martfury_footer_recently_viewed() {
    if ( apply_filters( 'martfury_check_ajax_referer', true ) ) {
        check_ajax_referer( '_martfury_nonce', 'nonce' );
    }

    $atts              = array();
    $atts['numbers']   = martfury_get_option( 'footer_recently_viewed_number' );
    $atts['title']     = martfury_get_option( 'footer_recently_viewed_title' );
    $atts['link_text'] = martfury_get_option( 'footer_recently_viewed_link_text' );
    $atts['link_url']  = martfury_get_option( 'footer_recently_viewed_link_url' );

    wp_send_json_success( ultralove_render_recently_viewed_from_request( $atts ) );
}

function ultralove_martfury_header_recently_viewed() {
    if ( apply_filters( 'martfury_check_ajax_referer', true ) ) {
        check_ajax_referer( '_martfury_nonce', 'nonce' );
    }

    $atts              = array();
    $atts['numbers']   = martfury_get_option( 'header_recently_viewed_number' );
    $atts['link_text'] = martfury_get_option( 'header_recently_viewed_link_text' );
    $atts['link_url']  = martfury_get_option( 'header_recently_viewed_link_url' );

    wp_send_json_success( ultralove_render_recently_viewed_from_request( $atts ) );
}

add_filter( 'woocommerce_email_styles', 'bbloomer_add_css_to_emails', 9999, 2 );
 
function bbloomer_add_css_to_emails( $css, $email ) { 
   $css .= '
   
     table {width:100% !important;}
   ';
   return $css;
}

add_filter( 'woocommerce_prevent_admin_access', '__return_false' );
/**
 * Hide loop read more buttons for out of stock items 
 */
if (!function_exists('woocommerce_template_loop_add_to_cart')) {
	function woocommerce_template_loop_add_to_cart() {
		global $product;
		if ( ! $product->is_in_stock() || ! $product->is_purchasable() ) return;
		wc_get_template('loop/add-to-cart.php');
	}
}

add_filter( 'wc_order_statuses', 'wc_renaming_order_status' );
function wc_renaming_order_status( $order_statuses ) {
    foreach ( $order_statuses as $key => $status ) {
        if ( 'wc-completed' === $key ) 
            $order_statuses['wc-completed'] = _x( 'Shipped', 'Order status', 'woocommerce' );
    }
    return $order_statuses;
}

function ultralove_exportstock_shortcode() {
	$a = '<div class="hourslist">
	<table id="exportMe" style="height:0px !important; display: block; overflow: hidden;" class="table">
		<tr>
        	<th>SKU</th>
            <th data-type="number">Stock</th>
            <th>UPC</th>
		</tr>
	';

	$products = wc_get_products( [ 'limit' => -1 ] );
	foreach ( $products as $product ) {
		$sku     = $product->get_sku();
		$stock   = $product->get_stock_quantity();
		$upc     = get_post_meta( $product->get_id(), 'hwp_product_gtin', true );
		
		$a .= '<tr>';
		$a .= "<td>{$sku}</td>";
		$a .= "<td>{$stock}</td>";
		$a .= "<td>{$upc}</td>";
		$a .= '</tr>';
	}

	$a .= '</table>
	</div>
	<button class="button" id="export">Export</button>';

	return $a;
}

add_shortcode('exportstock', 'ultralove_exportstock_shortcode');


// generalize filter by custom taxonomy
$GLOBALS['wt_custom_taxonomy_name'] = 'product_brand';
add_filter('wt_iew_exporter_alter_filter_fields',  'wt_add_additional_exporter_alter_filter_fields', 10, 3);
if(!function_exists('wt_add_additional_exporter_alter_filter_fields')){
    function wt_add_additional_exporter_alter_filter_fields($fields, $base, $filter_form_data){
        if ('product' != $base) {
            return $fields;
        }
        
        $fields[$GLOBALS['wt_custom_taxonomy_name']] = array(
            'label' => __('Product '. ucwords(str_replace('_', ' ', $GLOBALS['wt_custom_taxonomy_name'])), 'wt-import-export-for-woo'),
            'placeholder' => __('All '.ucwords(str_replace('_', ' ', $GLOBALS['wt_custom_taxonomy_name'])), 'wt-import-export-for-woo'),
            'field_name' => $GLOBALS['wt_custom_taxonomy_name'],
            'sele_vals' => get_custom_taxonomy(),
            'help_text' => __('Filter products by their '.ucwords(str_replace('_', ' ', $GLOBALS['wt_custom_taxonomy_name'])).'. You can export multiple together.', 'wt-import-export-for-woo'),
            'type' => 'multi_select',
            'css_class' => 'wc-enhanced-select',
            'validation_rule' => array('type'=>'text_arr')
        );
        
        return $fields;
        
    }

}
if(!function_exists('get_custom_taxonomy')){
    function get_custom_taxonomy(){
        $out = array();
        $product_categories = get_terms(array(
            'taxonomy' => $GLOBALS['wt_custom_taxonomy_name'],
            'hide_empty' => false,
            ) );    
        if (!is_wp_error($product_categories)) {
            $version = get_bloginfo('version');
            foreach ($product_categories as $category) {
                $out[$category->slug] = (( $version < '4.8') ? $category->name : get_term_parents_list($category->term_id, $GLOBALS['wt_custom_taxonomy_name'], array('separator' => ' -> ')));
            }
        }
        return $out;
    }
}


add_filter('woocommerce_csv_product_export_args','wt_woocommerce_csv_product_export_args');
if(!function_exists('wt_woocommerce_csv_product_export_args')){
    function wt_woocommerce_csv_product_export_args($args){   
        $form_data=(isset($_POST['form_data']) ? Wt_Import_Export_For_Woo_Common_Helper::process_formdata(maybe_unserialize(($_POST['form_data']))) : array());    
        $prod_brand = !empty($form_data['filter_form_data']['wt_iew_'.$GLOBALS['wt_custom_taxonomy_name']]) ? $form_data['filter_form_data']['wt_iew_'.$GLOBALS['wt_custom_taxonomy_name']] : array();         
        if(!empty($prod_brand)){
            $args['filter_by_custom_taxonomy'] = $prod_brand;
        }    
        return $args;    
    }
}

if(!function_exists('wt_handle_custom_query_var')){
    function wt_handle_custom_query_var( $query, $query_vars ) {
        
            if ( ! empty( $query_vars['filter_by_custom_taxonomy'] ) ) {                
                    $query['tax_query'][] = array(
                            'taxonomy' => $GLOBALS['wt_custom_taxonomy_name'],
                            'field'    => 'slug',
                            'terms'    => $query_vars['filter_by_custom_taxonomy'],
                    );
            }

            return $query;
    }
}
add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'wt_handle_custom_query_var', 10, 2 );

// Add text above payment options at checkout
add_action( 'woocommerce_review_order_before_payment', function() {
	echo '<h3 class="payments-title">Payment Options</h3>';
} );

// Add terms and condition text
add_action( 'woocommerce_checkout_terms_and_conditions', function() {
	echo '<p class="extra-terms">Although we try and keep our inventory as accurate as possible, there will be times when our stock says we have the item available but it may actually be out of stock. If this is the case, we do apologize and will update our inventory as soon as possible.</p>';
} );

// Replace text in email template: custom-on-hold-order.php
add_filter( 'gettext', 'ultralove_custom_text_replace', 10, 3 );
function ultralove_custom_text_replace( $translated, $original, $domain ) {
    if ( $translated == "Thanks for your order. It’s on-hold until we confirm that payment has been received. In the meantime, here’s a reminder of what you ordered:" ) {
        $translated = "Thank you for your order. The order will ship within 1-3 business days. In the meantime, here’s a reminder of what you ordered:";
    }

    return $translated;
}

add_filter( 'woocommerce_order_item_name', function( $name ) {
	return wp_strip_all_tags( $name );
}, 10, 1 );

add_action( 'woocommerce_account_dashboard', function() {
    $upload_dir = wp_upload_dir();

	ob_start();
?>
<style>
button[name="collect-media"] {
  background-color: var(--mf-background-primary-color);
  color: var(--mf-background-primary-text-color);
  height: 50px;
  line-height: 50px;
  padding: 0 25px;
  transition: all 0.5s;
  font-size: 16px;
  font-weight: 600;
  text-align: center;
  border: none;
  border-radius: 0;
}
</style>
<p>Thank you for choosing Ultra Love as your adult product supplier. We truly appreciate your business.</p>
<p><a class="button" target="_blank" href="https://ultralove.ca/export-stock">Download the Latest Stock level export file</a></p>
<p><?= do_shortcode( '[am-collect-media]' ) ?></p>
<p><a class="button" href="<?= esc_url( trailingslashit( $upload_dir['baseurl'] ) . 'ultralove-product-export.csv' ) ?>" download>Download Product Inventory CSV</a></p>
<p><a class="button" href="<?= esc_url( trailingslashit( $upload_dir['baseurl'] ) . 'ultralove-product-export-shopify.csv' ) ?>" download>Download Product Inventory CSV (Shopify)</a></p>
<?php
	echo ob_get_clean();
} );











function ultralove_export_products_to_csv( $format = 'default' ) {
    if ( ! class_exists( 'WooCommerce' ) ) return;

    $args = [
        'status' => 'publish',
        'limit'  => -1,
    ];

    $products   = wc_get_products( $args );
    $upload_dir = wp_upload_dir();
    
	// Set a dynamic filename based on the format
    $filename = ( $format === 'shopify' ) ? 'ultralove-product-export-shopify.csv' : 'ultralove-product-export.csv';
    $csv_file   = fopen( $upload_dir['basedir'] . '/' . $filename, 'w' );
	
    $default_columns = [
		'SKU',
		'Title',
		'Content',
		'Excerpt',
		'Regular Price',
		'Sale Price',
		'Stock',
		'Image URL',
		'Image Filename',
		'Categories',
		'Brands',
		'UPC', // hwp_product_gtin
	];
    
    // Shopify headers
    $shopify_columns = [
        'Handle',
		'Title',
		'Body (HTML)',
		'Variant SKU', // replaced Excerpt
		'Variant Price',
		'Variant Compare At Price',
		'Variant Inventory Qty',
		'Image Src',
		'Image Alt Text',
		'Product Category',
		'Vendor',
		'Variant Barcode', // hwp_product_gtin
		'Status',
    ];

    $columns = ( $format === 'shopify' ) ? $shopify_columns : $default_columns;	
	
    fputcsv( $csv_file, $columns );

    foreach ( $products as $i => $product ) {
        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_children();
            
			foreach ( $variations as $variation_id ) {
                $variation = new WC_Product_Variation( $variation_id );
				$line = [
					$variation->get_sku(), // SKU
					($format === 'shopify') ? ultralove_extract_shopify_title($variation->get_name()) : strip_tags($variation->get_name()), // Title
					$variation->get_description(), // Content
					$variation->get_sku(), // SKU // $variation->get_short_description(), // Excerpt
					$variation->get_price(), // Price
					$variation->get_sale_price(), // Sale Price
					$variation->get_stock_quantity(), // Stock
                    $variation->get_image_id() ? wp_get_attachment_url($variation->get_image_id()) : '', // Image URL
                    $variation->get_image_id() ? basename(wp_get_attachment_url($variation->get_image_id())) : '', // Image Filename
					implode(', ', wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names'])), // Categories
					implode(', ', wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names'])), // Brands
					get_post_meta( $variation->get_id(), 'hwp_product_gtin', true ), // UPC
				];

				if ( $format === 'shopify') array_push($line, "draft");
				else array_splice($line, 4, 1, [ $variation->get_regular_price() ]); // Replace active Price with Regular Price

                fputcsv( $csv_file, $line );

				// TODO: include shopify gallery rows. Skipped because there are no variation products
            }
        } else {
			$line = [
                $product->get_sku(), // SKU
				($format === 'shopify') ? ultralove_extract_shopify_title($product->get_name()) : strip_tags($product->get_name()), // Title
                $product->get_description(), // Content
                $product->get_sku(), // SKU // $product->get_short_description(), // Excerpt
                $product->get_price(), // Price
                $product->get_sale_price(), // Sale Price
                $product->get_stock_quantity(), // Stock
                $product->get_image_id() ? wp_get_attachment_url($product->get_image_id()) : '', // Image URL
                $product->get_image_id() ? basename(wp_get_attachment_url($product->get_image_id())) : '', // Image Filename
                implode(', ', wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names'])), // Categories
                implode(', ', wp_get_post_terms($product->get_id(), 'product_brand', ['fields' => 'names'])), // Brands
                get_post_meta( $product->get_id(), 'hwp_product_gtin', true ), // UPC
            ];
			
			if ( $format === 'shopify') array_push($line, "draft");
			else array_splice($line, 4, 1, [ $product->get_regular_price() ]); // Replace active Price with Regular Price

            fputcsv( $csv_file, $line );

            if ($format === 'shopify') {
                $gallery_image_ids = $product->get_gallery_image_ids();

                if (!empty($gallery_image_ids)) {
                    // Loop through each gallery image ID
                    foreach ($gallery_image_ids as $image_id) {
                        $gallery_line = array_fill(0, count($shopify_columns), '');
						
						$sku_column_index = 0;
						$image_column_index = 7;
						
                        $gallery_line[$sku_column_index] = $product->get_sku();
                        $gallery_line[$image_column_index] = wp_get_attachment_url($image_id);
						
                        fputcsv($csv_file, $gallery_line);
                    }
                }
            }
        }
    }

    fclose( $csv_file );
}

function ultralove_extract_shopify_title( $product_title ) {
	$parts = array_slice(explode('<br>', $product_title), 1);
	$cleaned_parts = array_map('trim', $parts);
	$extracted_title = implode(' ', $cleaned_parts);

	return strip_tags($extracted_title);
}

function ultralove_generate_all_exports() {
    ultralove_export_products_to_csv( 'default' );
    ultralove_export_products_to_csv( 'shopify' );
}

function am_add_export_page() {
    add_submenu_page(
        'woocommerce',
        'Update Product CSV',
        'Update Product CSV',
        'manage_woocommerce',
        'am-export-products',
        'ultralove_export_page_callback'
    );
}

add_action( 'admin_menu', 'am_add_export_page' );

function ultralove_export_page_callback() {
    if ( isset( $_POST['export_products'] ) ) {
		ultralove_generate_all_exports();
        echo '<div class="notice notice-success is-dismissible"><p>Export completed.</p></div>';
    }

    $upload_dir = wp_upload_dir();

    ?>
    <div class="wrap">
        <h1>Export Products</h1>
        <p>Manually run the product export.</p>
        <p>Current file location: <?= $upload_dir['basedir'] . '/ultralove-product-export.csv' ?></p>
		<p>Current URL of the CSV is <a href="https://ultralove.ca/wp-content/uploads/ultralove-product-export.csv">https://ultralove.ca/wp-content/uploads/ultralove-product-export.csv</a></p>

        <form method="post">
            <input type="submit" name="export_products" value="Export Products" class="button button-primary" />
        </form>
    </div>
    <?php
}

// Update CSV files when an order is placed

/**
 * Cloudinary URL overrides are opt-in.
 * Keep disabled by default to avoid broken storefront images if Cloudinary assets are missing.
 */
if ( get_option( 'ultralove_enable_cloudinary_overrides', 'no' ) === 'yes' ) {
    add_filter('wp_get_attachment_image_src', 'ultralove_use_cloudinary_urls', 10, 4);
}
function ultralove_use_cloudinary_urls($image, $attachment_id, $size, $icon) {
    if (!is_array($image)) {
        return $image;
    }
    
    // Check if this image is synced to Cloudinary
    $cloudinary_meta = get_post_meta($attachment_id, '_cloudinary', true);
    
    if (!empty($cloudinary_meta) && isset($cloudinary_meta['_cloudinary_url'])) {
        $cloudinary_base_url = $cloudinary_meta['_cloudinary_url'];
        
        // For sized images, we need to generate the appropriate Cloudinary transformation
        $width = null;
        $height = null;
        
        if (is_array($size)) {
            $width = $size[0];
            $height = $size[1];
        } else {
            // Get WordPress image size dimensions
            global $_wp_additional_image_sizes;
            if (isset($_wp_additional_image_sizes[$size])) {
                $width = $_wp_additional_image_sizes[$size]['width'];
                $height = $_wp_additional_image_sizes[$size]['height'];
            } else {
                // Try default sizes
                $width = get_option($size . '_size_w');
                $height = get_option($size . '_size_h');
            }
        }
        
        // Build Cloudinary transformation URL with proper sizing
        if (!empty($width) || !empty($height)) {
            $public_id = $cloudinary_meta['_public_id'];
            $version = $cloudinary_meta['_cloudinary_version'];
            $cloud_name = get_option('cloudinary_cloud_name', 'repn');
            
            $transformations = array();
            if ($width && $height) {
                $transformations[] = "c_fill,g_auto,w_" . $width . ",h_" . $height;
            } elseif ($width) {
                $transformations[] = "w_" . $width;
            } elseif ($height) {
                $transformations[] = "h_" . $height;
            }
            
            // Add auto format and quality
            $transformations[] = 'f_auto,q_auto';
            
            $transformation_str = implode(',', $transformations);
            $cloudinary_url = "https://res.cloudinary.com/" . $cloud_name . "/image/upload/" . $transformation_str . "/v" . $version . "/" . $public_id . ".jpg";
            
            // Replace the URL in the image array
            $image[0] = $cloudinary_url;
        } else {
            // Use the full Cloudinary URL for 'full' size
            $image[0] = $cloudinary_base_url;
        }
    }
    
    return $image;
}

/**
 * Filter wp_get_attachment_url to use Cloudinary URLs for synced images.
 */
if ( get_option( 'ultralove_enable_cloudinary_overrides', 'no' ) === 'yes' ) {
    add_filter('wp_get_attachment_url', 'ultralove_use_cloudinary_url', 10, 2);
}
function ultralove_use_cloudinary_url($url, $attachment_id) {
    // Check if this image is synced to Cloudinary
    $cloudinary_meta = get_post_meta($attachment_id, '_cloudinary', true);
    
    if (!empty($cloudinary_meta) && isset($cloudinary_meta['_cloudinary_url'])) {
        // Use the full Cloudinary URL with auto format and quality
        $public_id = $cloudinary_meta['_public_id'];
        $version = $cloudinary_meta['_cloudinary_version'];
        $cloud_name = get_option('cloudinary_cloud_name', 'repn');
        
        // Build URL with auto optimization
        $url = "https://res.cloudinary.com/" . $cloud_name . "/image/upload/f_auto,q_auto/v" . $version . "/" . $public_id . ".jpg";
    }
    
    return $url;
}

/**
 * Filter martfury_get_option to convert theme option image URLs to Cloudinary.
 */
if ( get_option( 'ultralove_enable_cloudinary_overrides', 'no' ) === 'yes' ) {
    add_filter('martfury_get_option', 'ultralove_cloudinary_theme_option_images', 10, 2);
}
function ultralove_cloudinary_theme_option_images($value, $option_name) {
    // Only process if the option name suggests it's an image-related option
    $image_keywords = array('image', 'banner', 'background', 'bg', 'logo', 'icon', 'thumb');
    $is_image_option = false;
    
    foreach ($image_keywords as $keyword) {
        if (stripos($option_name, $keyword) !== false) {
            $is_image_option = true;
            break;
        }
    }
    
    if (!$is_image_option || empty($value) || !is_string($value)) {
        return $value;
    }
    
    // Check if the value is a local image URL
    if (strpos($value, 'wp-content/uploads/') !== false) {
        // Extract the file path from the URL
        if (preg_match('/wp-content\/uploads\/(.+)$/', $value, $matches)) {
            $file_path = $matches[1];
            
            // Try to find the attachment ID from the URL
            global $wpdb;
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_wp_attached_file' 
                AND meta_value = %s 
                LIMIT 1",
                $file_path
            ));
            
            if ($attachment_id) {
                // Check if this image is synced to Cloudinary
                $cloudinary_meta = get_post_meta($attachment_id, '_cloudinary', true);
                
                if (!empty($cloudinary_meta) && isset($cloudinary_meta['_cloudinary_url'])) {
                    // Replace with Cloudinary URL
                    $public_id = $cloudinary_meta['_public_id'];
                    $version = $cloudinary_meta['_cloudinary_version'];
                    $cloud_name = get_option('cloudinary_cloud_name', 'repn');
                    
                    // Use auto format and quality for theme images
                    $value = "https://res.cloudinary.com/" . $cloud_name . "/image/upload/f_auto,q_auto/v" . $version . "/" . $public_id . ".jpg";
                }
            }
        }
    }
    
    return $value;
}

/**
 * Filter option values that contain image URLs.
 */
if ( get_option( 'ultralove_enable_cloudinary_overrides', 'no' ) === 'yes' ) {
    add_filter('option_martfury', 'ultralove_cloudinary_option_array_images', 10, 1);
}
function ultralove_cloudinary_option_array_images($option_array) {
    if (!is_array($option_array)) {
        return $option_array;
    }
    
    foreach ($option_array as $key => $value) {
        // Check if the key suggests it's an image and value is a local URL
        if (is_string($value) && 
            (stripos($key, 'image') !== false || 
             stripos($key, 'banner') !== false || 
             stripos($key, 'background') !== false ||
             stripos($key, 'bg') !== false ||
             stripos($key, 'logo') !== false) &&
            strpos($value, 'wp-content/uploads/') !== false) {
            
            // Apply the conversion
            $option_array[$key] = ultralove_convert_url_to_cloudinary($value);
        }
    }
    
    return $option_array;
}

/**
 * Helper function to convert a local image URL to Cloudinary
 */
function ultralove_convert_url_to_cloudinary($url) {
    if (empty($url) || !is_string($url) || strpos($url, 'wp-content/uploads/') === false) {
        return $url;
    }
    
    // Extract the file path from the URL
    if (preg_match('/wp-content\/uploads\/(.+)$/', $url, $matches)) {
        $file_path = $matches[1];
        
        // Try to find the attachment ID from the URL
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value = %s 
            LIMIT 1",
            $file_path
        ));
        
        if ($attachment_id) {
            // Check if this image is synced to Cloudinary
            $cloudinary_meta = get_post_meta($attachment_id, '_cloudinary', true);
            
            if (!empty($cloudinary_meta) && isset($cloudinary_meta['_cloudinary_url'])) {
                // Replace with Cloudinary URL
                $public_id = $cloudinary_meta['_public_id'];
                $version = $cloudinary_meta['_cloudinary_version'];
                $cloud_name = get_option('cloudinary_cloud_name', 'repn');
                
                // Use auto format and quality
                return "https://res.cloudinary.com/" . $cloud_name . "/image/upload/f_auto,q_auto/v" . $version . "/" . $public_id . ".jpg";
            }
        }
    }
    
    return $url;
}


