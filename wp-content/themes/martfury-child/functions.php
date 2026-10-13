<?php

require_once get_stylesheet_directory() . '/inc/product-card-wishlist.php';

// WooCommerce caches where each template was found (the object cache is persistent here - Redis) and
// only clears that on a theme switch, so a newly added (or removed) template override in this theme's
// woocommerce/ folder would go unused until the cache happened to expire. Clear it whenever the set of
// overrides changes.
add_action( 'init', 'ultralove_clear_wc_template_cache_on_change' );
function ultralove_clear_wc_template_cache_on_change() {
	if ( ! function_exists( 'wc_clear_template_cache' ) ) {
		return;
	}
	$dir       = get_stylesheet_directory() . '/woocommerce/';
	$overrides = array_merge( (array) glob( $dir . '*.php' ), (array) glob( $dir . '*/*.php' ), (array) glob( $dir . '*/*/*.php' ) );
	$hash      = md5( implode( '|', $overrides ) );
	if ( get_option( 'ultralove_wc_template_overrides' ) !== $hash ) {
		wc_clear_template_cache();
		update_option( 'ultralove_wc_template_overrides', $hash, true );
	}
}

/**
 * One page container for every page type: Martfury's wide .martfury-container (1650px, or full width
 * with 30px padding below 1680px, 15px on phones) for the header rows, the breadcrumbs and the content, so
 * they share their left/right edges on every page. The related products / upsells templates and the recently viewed block use
 * the same class directly.
 */
function martfury_header_container_classes() {
	return 'martfury-container';
}

/**
 * The page structure every template shares: #content > main#main.site-main, with the sidebar (catalog pages)
 * beside <main>. #content is full width and insets its content to the page width itself (style.css, "Page
 * width"), so Martfury's container wrapper inside it isn't printed; the child's templates (page.php,
 * single.php, archive.php, index.php, search.php, template-parts/content-404.php,
 * woocommerce/global/wrapper-start.php, front-page.php) print <main> straight into #content.
 */
add_action( 'after_setup_theme', 'ultralove_no_site_content_container' );
function ultralove_no_site_content_container() {
	remove_action( 'martfury_after_site_content_open', 'martfury_open_site_content_container' );
	remove_action( 'martfury_before_site_content_close', 'martfury_close_site_content_container' );
}

add_filter( 'martfury_page_header_container_class', 'ultralove_wide_container_class' );
add_filter( 'martfury_catalog_page_header_container', 'ultralove_wide_container_class', 20 );
function ultralove_wide_container_class( $class ) {
	return 'martfury-container';
}

// Disable WordPress fuzzy redirect
add_filter( 'do_redirect_guess_404_permalink', function () { return false; } );

// Hide updates for all users except AtmLogin
add_filter( 'hide_updates_allowed_users', function () { return [ 'AtmLogin' ]; } );

add_action( 'wp_enqueue_scripts', 'martfury_child_enqueue_scripts', 20 );
function martfury_child_enqueue_scripts() {
	// Version by file mtime so CSS edits bust browser/Cloudflare caches (static assets are cached for a year).
	wp_enqueue_style( 'martfury-child-style', get_stylesheet_uri(), array(), filemtime( get_stylesheet_directory() . '/style.css' ) );

	// Linearicons: swap the parent's full icon font (146 KB, 1,097 icons) for a subset of the ones the
	// site uses (~3.5 KB). Same handle, so the parent's stylesheet that depends on it still loads.
	$icons_css = get_stylesheet_directory() . '/css/linearicons-subset.css';
	wp_deregister_style( 'linearicons' );
	wp_register_style( 'linearicons', get_stylesheet_directory_uri() . '/css/linearicons-subset.css', array(), filemtime( $icons_css ) );

	// Tooltips: js/tooltips.js in place of jQuery UI's tooltip (~27 KB with the jQuery UI core it needs).
	// Same handle, so the parent's script that depends on it still loads.
	$tooltips_js = get_stylesheet_directory() . '/js/tooltips.js';
	wp_deregister_script( 'jquery-ui-tooltip' );
	wp_register_script( 'jquery-ui-tooltip', get_stylesheet_directory_uri() . '/js/tooltips.js', array( 'jquery' ), (string) filemtime( $tooltips_js ), true );

	ultralove_use_trimmed_styles();

	if ( is_rtl() ) {
		wp_enqueue_style( 'martfury-rtl', get_template_directory_uri() . '/rtl.css', array(), '20180105' );
	}

	// The logged-in account menu's desktop/mobile switch at the header's 992px, not Martfury's 1200px (at
	// 992-1199px Log out opened the user panel). Only signed-in visitors have that menu.
	if ( is_user_logged_in() ) {
		$account_menu_path = get_stylesheet_directory() . '/js/account-menu-breakpoint.js';
		wp_enqueue_script( 'ultralove-account-menu-breakpoint', get_stylesheet_directory_uri() . '/js/account-menu-breakpoint.js', array( 'jquery', 'martfury' ), (string) filemtime( $account_menu_path ), true );
	}

    if ( class_exists( 'WooCommerce' ) ) {
        // Product carousel (related products, upsells, recently viewed - .ul-carousel in style.css).
        $carousel_path = get_stylesheet_directory() . '/js/product-carousel.js';
        wp_enqueue_script( 'ultralove-product-carousel', get_stylesheet_directory_uri() . '/js/product-carousel.js', array(), (string) filemtime( $carousel_path ), true );

        // Whole product card (padding included) clicks through to the product page.
        $card_link_path = get_stylesheet_directory() . '/js/product-card-link.js';
        wp_enqueue_script( 'ultralove-product-card-link', get_stylesheet_directory_uri() . '/js/product-card-link.js', array(), (string) filemtime( $card_link_path ), true );

        // Product cards: the checkmark on an Add to cart button after adding goes back to the cart after a moment.
        $card_cart_path = get_stylesheet_directory() . '/js/product-card-cart.js';
        wp_enqueue_script( 'ultralove-product-card-cart', get_stylesheet_directory_uri() . '/js/product-card-cart.js', array( 'jquery' ), (string) filemtime( $card_cart_path ), true );

        // Cards render cache-safe hearts in PHP; YITH still owns wishlist mutations and the wishlist page.
        if ( function_exists( 'YITH_WCWL_Frontend' ) && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
            YITH_WCWL_Frontend()->enqueue_scripts();
            YITH_WCWL_Frontend()->enqueue_styles_and_stuffs();

            $card_wishlist_path = get_stylesheet_directory() . '/js/product-card-wishlist.js';
            $yith_handle        = wp_script_is( 'jquery-yith-wcwl-user', 'registered' ) ? 'jquery-yith-wcwl-user' : 'jquery-yith-wcwl';
            wp_enqueue_script( 'ultralove-product-card-wishlist', get_stylesheet_directory_uri() . '/js/product-card-wishlist.js', array( 'jquery', $yith_handle ), (string) filemtime( $card_wishlist_path ), true );
            wp_localize_script( 'ultralove-product-card-wishlist', 'ulWishlistHearts', array(
                'action'         => 'ultralove_wishlist_state',
                'wishlistUrl'    => YITH_WCWL()->get_wishlist_url(),
                'addLabel'       => get_option( 'yith_wcwl_add_to_wishlist_text', __( 'Add to wishlist', 'yith-woocommerce-wishlist' ) ),
                'browseLabel'    => get_option( 'yith_wcwl_browse_wishlist_text', __( 'Browse wishlist', 'yith-woocommerce-wishlist' ) ),
                'errorLabel'     => __( 'Unable to load favourites status. Please reload the page.', 'martfury-child' ),
            ) );
        }

        // Checkout: the customer chooses the payment method (none chosen in advance).
        if ( is_checkout() ) {
            $payment_choice_path = get_stylesheet_directory() . '/js/checkout-payment-choice.js';
            wp_enqueue_script( 'ultralove-checkout-payment-choice', get_stylesheet_directory_uri() . '/js/checkout-payment-choice.js', array( 'jquery', 'wc-checkout' ), (string) filemtime( $payment_choice_path ), true );
        }

        // Product page: no vertical thumbnail slider at phone width (the thumbnails are a row there).
        if ( is_singular( 'product' ) ) {
            $thumbs_path = get_stylesheet_directory() . '/js/product-gallery-thumbs.js';
            wp_enqueue_script( 'ultralove-product-gallery-thumbs', get_stylesheet_directory_uri() . '/js/product-gallery-thumbs.js', array( 'jquery', 'martfury' ), (string) filemtime( $thumbs_path ), true );
        }

        $script_path = get_stylesheet_directory() . '/js/recently-viewed.js';
        $script_url  = get_stylesheet_directory_uri() . '/js/recently-viewed.js';
        $version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : null;

        wp_enqueue_script( 'ultralove-recently-viewed', $script_url, array( 'jquery', 'martfury', 'ultralove-product-carousel' ), $version, true );
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

/*
 * Big stylesheets swapped for copies trimmed to the selectors the site's pages and scripts use: Martfury's
 * style.css (660 KB -> ~240 KB, ~96% styled layouts, widgets and marketplaces this site doesn't use), its icon
 * fonts' CSS (Ionicons, ElegantIcons, Font Awesome: a few dozen icons used of ~1,500) and WooCommerce's
 * woocommerce.css. Generated by ops/css (see its README) - regenerate after a Martfury or WooCommerce update
 * or when a new page type or feature appears; don't edit them by hand. Each handle, its dependencies and the
 * inline styles attached to it (Martfury's header styles) stay as they are.
 *
 * A vendor plugin's copy carries the plugin version it was made from in its name and is only used while that
 * version is installed: after a plugin update the original is served until ops/css is rerun.
 */
function ultralove_use_trimmed_styles() {
	$dir    = get_stylesheet_directory() . '/css/';
	$styles = wp_styles();
	$swap   = array(
		'martfury'     => 'martfury-trimmed.css',
		'ionicons'     => 'ionicons-trimmed.css',
		'eleganticons' => 'eleganticons-trimmed.css',
		'font-awesome' => 'font-awesome-trimmed.css',
	);
	if ( defined( 'WC_VERSION' ) ) {
		$swap['woocommerce-general'] = 'woocommerce-' . WC_VERSION . '-trimmed.css';
	}
	foreach ( $swap as $handle => $file ) {
		if ( isset( $styles->registered[ $handle ] ) && file_exists( $dir . $file ) ) {
			$styles->registered[ $handle ]->src = get_stylesheet_directory_uri() . '/css/' . $file;
			$styles->registered[ $handle ]->ver = (string) filemtime( $dir . $file );
		}
	}
}

// Preload the fonts every page draws with - the icon font and Work Sans' two Latin files (300 and 500; see
// the @font-face rules at the top of style.css) - so they download alongside the HTML, ahead of the deferred
// full stylesheet. Each URL must match its @font-face src exactly (including any ?v=), and crossorigin is
// required for fonts even on the same origin, or the browser fetches them twice.
add_action( 'wp_head', 'ultralove_preload_fonts', 1 );
function ultralove_preload_fonts() {
	foreach ( array( 'linearicons-subset.woff2?v=1', 'work-sans-300-latin.woff2', 'work-sans-500-latin.woff2' ) as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_stylesheet_directory_uri() . '/fonts/' . $font )
		);
	}
}

/*
 * Header search dropdown (Martfury's AJAX search): let Relevanssi run its text search. Relevanssi blanks the SQL of
 * every front-end search query ("WHERE 1=2") but only answers the main query or one flagged 'relevanssi' => true, so
 * Martfury's own get_posts() search always came back "Nothing found" (only SKU matches got through). Flagged, it
 * uses the same index and ranking as the search results page.
 */
add_filter( 'martfury_get_search_products_query', 'ultralove_search_query_use_relevanssi' );
add_filter( 'martfury_get_search_posts_query', 'ultralove_search_query_use_relevanssi' );
function ultralove_search_query_use_relevanssi( $args ) {
    if ( function_exists( 'relevanssi_query' ) ) {
        $args['relevanssi'] = true;
    }
    return $args;
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

    wp_send_json_success( ultralove_render_recently_viewed_cards( $atts ) );
}

/**
 * Footer "Recently viewed" block: the standard product card (content-product.php - the same card as
 * the shop grid and related products) instead of Martfury's image-only list, in the child theme's
 * product carousel (js/product-carousel.js; recently-viewed.js starts it once the block has loaded). The viewed IDs come from the browser (oldest first); the product being
 * viewed is left out, since it's the page itself.
 */
function ultralove_render_recently_viewed_cards( $atts ) {
    $current  = isset( $_POST['current_product'] ) ? absint( $_POST['current_product'] ) : 0;
    $products = array();

    foreach ( array_reverse( ultralove_get_recently_viewed_request_ids() ) as $product_id ) {
        if ( $product_id === $current ) {
            continue;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_visible() ) {
            continue;
        }

        $products[] = $product;
        if ( count( $products ) >= 10 ) {
            break;
        }
    }

    ob_start();

    echo '<div class="recently-header">';
    if ( ! empty( $atts['title'] ) ) {
        printf( '<h4 class="title">%s</h4>', esc_html( $atts['title'] ) );
    }
    if ( ! empty( $atts['link_text'] ) ) {
        printf( '<a href="%s" class="link">%s</a>', esc_url( $atts['link_url'] ), esc_html( $atts['link_text'] ) );
    }
    echo '</div>';

    if ( empty( $products ) ) {
        // Martfury's empty state; the block is hidden when empty (footer_recently_viewed_empty).
        printf(
            '<ul class="product-list no-products"><li class="text-center">%s <br><a href="%s" class="btn-secondary ul-btn ul-btn--secondary ul-btn--sm">%s</a></li></ul>',
            esc_html__( 'Recently Viewed Products is a function which helps you keep track of your recent viewing history.', 'martfury' ),
            esc_url( get_permalink( get_option( 'woocommerce_shop_page_id' ) ) ),
            esc_html__( 'Shop Now', 'martfury' )
        );

        return ob_get_clean();
    }

    // The same scrollable carousel as related products (.ul-carousel - js/product-carousel.js).
    printf( '<div class="ul-carousel" data-ul-carousel aria-label="%s">', esc_attr( ! empty( $atts['title'] ) ? $atts['title'] : __( 'Recently viewed products', 'martfury' ) ) );
    wc_setup_loop( array( 'name' => 'recently_viewed', 'columns' => 5 ) );
    woocommerce_product_loop_start();
    foreach ( $products as $product ) {
        $post_object = get_post( $product->get_id() );
        setup_postdata( $GLOBALS['post'] =& $post_object );
        wc_get_template_part( 'content', 'product' );
    }
    woocommerce_product_loop_end();
    echo '</div>';
    wp_reset_postdata();
    wc_reset_loop();

    return ob_get_clean();
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
 * The Back In Stock Notifier plugin adds its "Notify Me When Available" button to product cards on
 * woocommerce_after_shop_loop_item (priority 999) - below the card's details, outside the card layout.
 * Take that callback off the hook and keep it, so woocommerce_template_loop_add_to_cart() below can
 * show the button in the cart row's place instead (the plugin's own visibility rules still apply).
 */
add_action( 'wp_loaded', 'ultralove_capture_instock_notifier_catalog_button' );
function ultralove_capture_instock_notifier_catalog_button() {
	global $wp_filter;

	if ( empty( $wp_filter['woocommerce_after_shop_loop_item'] ) ) {
		return;
	}

	foreach ( $wp_filter['woocommerce_after_shop_loop_item']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$fn = $callback['function'];
			if ( is_array( $fn ) && is_object( $fn[0] ) && 'CWG_Instock_Notifier_Product' === get_class( $fn[0] ) && 'display_popup_button_in_catalog_page' === $fn[1] ) {
				remove_action( 'woocommerce_after_shop_loop_item', $fn, $priority );
				$GLOBALS['ultralove_instock_notifier_catalog_button'] = $fn;
				return;
			}
		}
	}
}

/**
 * Loop add to cart. Non-purchasable items get nothing. Out of stock items get the Back In Stock
 * Notifier's "Notify Me When Available" button where the cart row goes, when the plugin shows it
 * (logged-in users); otherwise the same quantity + button row as in-stock ones (see the Quantity
 * Field on Shop Page plugin), but disabled, with the button reading "Out of stock".
 */
/**
 * The card's price box (Martfury's product_loop_footer_buttons(), on woocommerce_after_shop_loop_item_title)
 * holds a second copy of the cart form, and style.css hides it. Flag that hook
 * so woocommerce_template_loop_add_to_cart() below skips building the copy.
 */
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	$GLOBALS['ultralove_in_card_price_box'] = true;
}, 9 );
add_action( 'woocommerce_after_shop_loop_item_title', function () {
	$GLOBALS['ultralove_in_card_price_box'] = false;
}, 11 );

if (!function_exists('woocommerce_template_loop_add_to_cart')) {
	function woocommerce_template_loop_add_to_cart() {
		global $product;
		if ( ! $product->is_purchasable() ) return;
		// In-stock items give the price box a cart form that is always hidden; only the notify row can show there.
		if ( ! empty( $GLOBALS['ultralove_in_card_price_box'] ) && $product->is_in_stock() ) return;
		if ( ! $product->is_in_stock() ) {
			$notify = '';
			if ( ! empty( $GLOBALS['ultralove_instock_notifier_catalog_button'] ) ) {
				ob_start();
				call_user_func( $GLOBALS['ultralove_instock_notifier_catalog_button'] );
				$notify = trim( ob_get_clean() );
			}
			if ( '' !== $notify ) {
				// A div, not a form: the plugin's button is an <input type="submit"> handled by its own JS.
				echo '<div class="cart ul-notify-row">' . $notify . '</div>';
				return;
			}
			echo '<form class="cart ul-out-of-stock" onsubmit="return false;">'
				. '<div class="quantity"><div class="qty-box">'
				. '<span class="decrease icon_minus-06" aria-hidden="true"></span>'
				. '<input type="number" class="input-text qty text" value="1" disabled aria-label="' . esc_attr__( 'Quantity', 'woocommerce' ) . '" />'
				. '<span class="increase icon_plus" aria-hidden="true"></span>'
				. '</div></div>'
				. '<button type="button" class="button alt ul-out-of-stock-button ul-btn ul-btn--sm" disabled>' . esc_html__( 'Out of stock', 'woocommerce' ) . '</button>'
				. '</form>';
			return;
		}
		wc_get_template('loop/add-to-cart.php');
	}
}

/**
 * Loop Add to cart buttons carry a cart icon beside their label: on phones the card shows only the icon
 * (a round button beside the quantity, on one row) and the label stays for screen readers; wider cards
 * show only the label (style.css, "Cart row on phones"). After the Quantity Field on Shop Page plugin,
 * which rebuilds the button at priority 10.
 */
add_filter( 'woocommerce_loop_add_to_cart_link', 'ultralove_loop_add_to_cart_icon', 20 );
function ultralove_loop_add_to_cart_icon( $html ) {
	return preg_replace(
		'#(<(button|a)\b[^>]*\badd_to_cart_button\b[^>]*>)(.*?)(</\2>)#s',
		'$1<span class="ul-cart-icon" aria-hidden="true"></span><span class="ul-cart-label">$3</span>$4',
		$html,
		1
	);
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

	add_action( 'wp_footer', 'ultralove_exportstock_footer_script', 100 );

	return $a;
}

// The [exportstock] page's "Export" button: downloads the table as CSV. Printed only when the shortcode renders.
function ultralove_exportstock_footer_script() {
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		const table = document.getElementById('exportMe');
		const exportBtn = document.getElementById('export');

		const toCsv = function (table) {
			return [].slice
				.call(table.querySelectorAll('tr'))
				.map(function (row) {
					return [].slice
						.call(row.querySelectorAll('th,td'))
						.map(function (cell) {
							return cell.textContent;
						})
						.join(',');
				})
				.join('\n');
		};

		const download = function (text, fileName) {
			const link = document.createElement('a');
			link.setAttribute('href', `data:text/csv;charset=utf-8,${encodeURIComponent(text)}`);
			link.setAttribute('download', fileName);
			link.style.display = 'none';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
		};

		exportBtn.addEventListener('click', function () {
			download(toCsv(table), 'download.csv');
		});
	});
	</script>
	<?php
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
	$uploads    = trailingslashit( $upload_dir['baseurl'] );

	ob_start();
?>
<div class="ul-dash">
	<p class="ul-dash__thanks">Thank you for choosing Ultra Love as your adult product supplier. We truly appreciate your business.</p>

	<?php if ( class_exists( 'UltraLove_Media_Library' ) ) : ?>
	<section class="ul-dash__feature">
		<div class="ul-dash__feature-text">
			<h3>Product Image Library</h3>
			<p>Browse our catalog and download original product images: one image at a time, or every image for a product as a ZIP.</p>
		</div>
		<a class="button" href="<?= esc_url( wc_get_account_endpoint_url( 'media-library' ) ) ?>">Open the Media Library</a>
	</section>
	<?php endif; ?>

	<h3 class="ul-dash__heading">Stock &amp; product data</h3>
	<div class="ul-dash__cards">
		<div class="ul-dash__card">
			<h4>Latest stock levels</h4>
			<p>The most recent stock level export file.</p>
			<a class="button" target="_blank" rel="noopener" href="https://ultralove.ca/export-stock">Download</a>
		</div>
		<div class="ul-dash__card">
			<h4>Product inventory (CSV)</h4>
			<p>Our product inventory as a CSV spreadsheet.</p>
			<a class="button" href="<?= esc_url( $uploads . 'ultralove-product-export.csv' ) ?>" download>Download CSV</a>
		</div>
		<div class="ul-dash__card">
			<h4>Product inventory (Shopify)</h4>
			<p>The same inventory in the format Shopify imports.</p>
			<a class="button" href="<?= esc_url( $uploads . 'ultralove-product-export-shopify.csv' ) ?>" download>Download CSV</a>
		</div>
	</div>
</div>
<?php
	echo ob_get_clean();
} );

// My Account "Downloads" (WooCommerce downloadable products) is not used on this site: hide the menu
// item and send anyone who opens /my-account/downloads/ to the dashboard.
add_filter( 'woocommerce_account_menu_items', function( $items ) {
	unset( $items['downloads'] );
	return $items;
}, 30 );

add_action( 'template_redirect', function() {
	if ( function_exists( 'is_account_page' ) && is_account_page() && is_wc_endpoint_url( 'downloads' ) ) {
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
} );











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



/**
 * The side menu (Martfury's off-canvas menu, opened by the header's menu button) ends with a Wishlist link
 * on phones, where the header shows only the account and cart icons (style.css, "Header below 992px"). No
 * count: logged-out pages are cached at Cloudflare.
 */
add_filter( 'wp_nav_menu_items', 'ultralove_mobile_menu_wishlist', 10, 2 );
function ultralove_mobile_menu_wishlist( $items, $args ) {
	if ( ! ( $args->walker instanceof Martfury_Mobile_Walker ) || ! function_exists( 'YITH_WCWL' ) ) {
		return $items;
	}
	$page_id = get_option( 'yith_wcwl_wishlist_page_id' );
	if ( ! $page_id ) {
		return $items;
	}
	return $items . sprintf(
		'<li class="menu-item ul-menu-wishlist hidden-sm hidden-md hidden-lg"><a href="%s">%s</a></li>',
		esc_url( get_permalink( $page_id ) ),
		esc_html__( 'Wishlist', 'martfury' )
	);
}

/**
 * The header's navigation row (Store / On Sale / ... ), printed by template-parts/headers/layout-1.php.
 */
function ultralove_main_menu_row() {
	$css_header_menu = 'col-md-9 col-sm-9';
	$show_department = true;
	$extras          = martfury_menu_extras();
	if ( empty( $extras ) || ! in_array( 'department', $extras ) ) {
		$show_department = false;
		$css_header_menu = 'col-md-12 col-sm-12';
	}
	?>
<div class="main-menu hidden-xs hidden-sm">
    <div class="<?php echo martfury_header_container_classes(); ?>">
        <div class="row header-row">
			<?php if ( $show_department ) : ?>
                <div class="col-md-3 col-sm-3 i-product-cats mr-extra-department">
					<?php martfury_extra_department( true ); ?>
                </div>
			<?php endif; ?>
            <div class="<?php echo esc_attr( $css_header_menu ); ?> mr-header-menu">
                <div class="col-header-menu">
					<?php martfury_header_menu(); ?>
					<?php martfury_header_bar(); ?>
                </div>
            </div>
        </div>
    </div>
</div>
	<?php
}


/**
 * The home page is front-page.php. Elementor's page templates (the page still has "Elementor Theme" set)
 * swap in their own template file on template_include, so pick front-page.php after they've run.
 */
add_filter( 'template_include', 'ultralove_front_page_template', 99 );
function ultralove_front_page_template( $template ) {
	if ( is_front_page() && ! is_home() ) {
		$front_page = locate_template( 'front-page.php' );
		if ( $front_page ) {
			return $front_page;
		}
	}

	return $template;
}

/**
 * Quick view popup header: the link wraps the product name's container (<a><h2 class="product_title">),
 * with the brand / rating / SKU list outside it, rather than Martfury's link inside the heading with the
 * list alongside. Replaces MF's get_product_quick_view_header(), and moves it from the top of the summary
 * to ahead of the gallery (martfury_before_single_product_summary, before the images at 20) so the popup
 * reads top to bottom: name / brand / SKU, then the image, then price, quantity, Add to cart and wishlist.
 */
add_action( 'wp_loaded', 'ultralove_replace_quick_view_header' );
function ultralove_replace_quick_view_header() {
	global $martfury_woocommerce;

	if ( ! isset( $martfury_woocommerce ) || ! is_object( $martfury_woocommerce ) || ! method_exists( $martfury_woocommerce, 'single_product_brand' ) ) {
		return;
	}
	if ( false === has_action( 'martfury_single_product_summary', array( $martfury_woocommerce, 'get_product_quick_view_header' ) ) ) {
		return;
	}

	remove_action( 'martfury_single_product_summary', array( $martfury_woocommerce, 'get_product_quick_view_header' ), 5 );
	add_action( 'martfury_before_single_product_summary', 'ultralove_quick_view_header', 5 );
}

function ultralove_quick_view_header() {
	global $product, $martfury_woocommerce;
	?>
        <div class="mf-entry-product-header">
            <div class="entry-left">
                <a class="ul-qv-title" href="<?php echo esc_url( $product->get_permalink() ); ?>"><h2 class="product_title"><?php echo $product->get_title(); // phpcs:ignore -- titles hold <br>/<b> markup, output as Martfury does ?></h2></a>
                <ul class="entry-meta">
					<?php
					$martfury_woocommerce->single_product_brand();
					if ( function_exists( 'woocommerce_template_single_rating' ) && $product->get_rating_count() ) {
						echo '<li class="meta-review">';
						woocommerce_template_single_rating();
						echo '</li>';
					}
					$martfury_woocommerce->single_product_sku();
					?>
                </ul>
            </div>
        </div>
	<?php
}

/**
 * Breadcrumbs on every page, in the same strip as the catalog pages: pages (Brands, Buy Wholesale,
 * Contact, cart, ...) get them in their page header, where the theme's page header elements leave them
 * out, and product pages get the breadcrumb bar (theme option product_breadcrumb, off). The home page
 * hides its page header altogether and keeps doing so.
 */
add_filter( 'martfury_get_page_header', 'ultralove_page_header_breadcrumbs' );
function ultralove_page_header_breadcrumbs( $page_header ) {
	if ( is_array( $page_header ) && ( is_page() || is_singular( 'product' ) ) && ! in_array( 'breadcrumb', $page_header, true ) ) {
		$page_header[] = 'breadcrumb';
	}
	return $page_header;
}

/**
 * Breadcrumbs - Martfury's martfury_get_breadcrumbs() (inc/functions/entry.php), with one change: brand
 * pages (product_brand archives, linked from the Brands We Carry page) start their trail with that page,
 * the way category trails start with Shop - "Brands We Carry / LELO" instead of just "LELO". Martfury's
 * martfury_breadcrumbs() has no hook for this, so brand trails are built here in its markup (Home first,
 * hidden by the stylesheet like on every page; schema.org positions in order); everything else is as
 * Martfury does it.
 */
function martfury_get_breadcrumbs() {
	$page_header = martfury_get_page_header();
	if ( ! $page_header || ! in_array( 'breadcrumb', $page_header ) ) {
		return;
	}

	$breadcrumbs_enabled = current_theme_supports( 'yoast-seo-breadcrumbs' );
	if ( ! $breadcrumbs_enabled && class_exists( 'WPSEO_Options' ) ) {
		$breadcrumbs_enabled = WPSEO_Options::get( 'breadcrumbs-enable', false );
	}

	if ( $breadcrumbs_enabled ) {
		yoast_breadcrumb( '<div id="breadcrumbs" class="breadcrumbs">', '</div>' );
		return;
	}

	echo '<ul class="breadcrumbs" itemscope itemtype="https://schema.org/BreadcrumbList">';
	if ( is_tax( 'product_brand' ) ) {
		ultralove_brand_breadcrumbs();
	} else {
		martfury_breadcrumbs(
			array(
				'before'   => '',
				'taxonomy' => function_exists( 'is_woocommerce' ) && is_woocommerce() ? 'product_cat' : 'category',
			)
		);
	}
	echo '</ul>';
}

function ultralove_brand_breadcrumbs() {
	$item  = '<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a href="%s" itemprop="item"><span itemprop="name">%s</span><meta itemprop="position" content="%d"></a></li>';
	$items = array(
		sprintf(
			'<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem"><a class="home" href="%s" itemprop="item"><span itemprop="name">%s </span><meta itemprop="position" content="1"></a></li>',
			esc_url( apply_filters( 'martfury_breadcrumbs_home_url', get_home_url() ) ),
			esc_html__( 'Home', 'martfury' )
		),
	);
	$position = 2;

	$brands_page = get_page_by_path( 'brands' );
	if ( $brands_page ) {
		$items[] = sprintf( $item, esc_url( get_permalink( $brands_page ) ), esc_html( get_the_title( $brands_page ) ), $position++ );
	}

	$term = get_queried_object();
	if ( $term instanceof WP_Term ) {
		foreach ( (array) martfury_get_term_parents( $term->term_id, $term->taxonomy ) as $parent_id ) {
			$parent = get_term( $parent_id, $term->taxonomy );
			if ( $parent instanceof WP_Term ) {
				$items[] = sprintf( $item, esc_url( get_term_link( $parent ) ), esc_html( $parent->name ), $position++ );
			}
		}
		$items[] = sprintf( $item, esc_url( get_term_link( $term ) ), esc_html( $term->name ), $position );
	}

	echo implode( '<span class="sep">/</span>', $items ); // phpcs:ignore -- escaped above
}

// The On Sale page (the Sale/Closeout Items category, its own item in the header navigation) leaves Shop
// out of its trail, so it reads just "Sale/Closeout Items" rather than "Shop / Sale/Closeout Items".
add_filter( 'martfury_breadcrumb_get_shop_page', 'ultralove_sale_breadcrumb_without_shop' );
function ultralove_sale_breadcrumb_without_shop( $show ) {
	return ( function_exists( 'is_product_category' ) && is_product_category( 'sale-closeout-items' ) ) ? false : $show;
}

add_filter( 'martfury_get_option', 'ultralove_product_breadcrumb_option', 10, 2 );
function ultralove_product_breadcrumb_option( $value, $option_name ) {
	return 'product_breadcrumb' === $option_name ? 1 : $value;
}

// Product titles hold <br>/<b> markup (e.g. "SKU<br>Name"), which the breadcrumb prints as-is and so
// breaks onto two lines. While the product page's breadcrumb is built, its title comes through as plain
// text (the <br> as a space); the filter removes itself after that one title, so the page's other titles
// keep their markup.
add_filter( 'martfury_breadcrumbs_args', 'ultralove_breadcrumb_plain_product_title_start' );
function ultralove_breadcrumb_plain_product_title_start( $args ) {
	if ( is_singular( 'product' ) ) {
		add_filter( 'the_title', 'ultralove_breadcrumb_plain_product_title', 10, 2 );
	}
	return $args;
}

function ultralove_breadcrumb_plain_product_title( $title, $post_id = 0 ) {
	if ( (int) $post_id !== get_queried_object_id() ) {
		return $title;
	}
	remove_filter( 'the_title', 'ultralove_breadcrumb_plain_product_title', 10 );
	$title = preg_replace( '/<br\s*\/?>/i', ' ', $title );
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ) );
}

/**
 * [ultralove_brand_grid] - the Brands We Carry page's grid: every brand with products (A-Z), its logo and
 * name linking to the brand's products. The same markup as the martfury-addons Elementor "Brand Images"
 * widget the page used to have (so its styles still apply); Martfury's own [brand_images] shortcode leaves
 * the names out. Laid out and styled in style.css ("Brands We Carry"); columns="" only sets a class.
 */
add_shortcode( 'ultralove_brand_grid', 'ultralove_brand_grid_shortcode' );
function ultralove_brand_grid_shortcode( $atts ) {
	$atts  = shortcode_atts( array( 'columns' => 6 ), $atts, 'ultralove_brand_grid' );
	$terms = get_terms( array(
		'taxonomy' => 'product_brand',
		'orderby'  => 'name',
		'order'    => 'ASC',
	) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	$items = array();
	foreach ( $terms as $term ) {
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			continue;
		}
		$thumb_id = get_term_meta( $term->term_id, 'brand_thumbnail_id', true );
		$items[]  = sprintf(
			'<div class="image-item"><a href="%1$s">%2$s</a><h4 class="b-title"><a href="%1$s">%3$s</a></h4></div>',
			esc_url( $link ),
			$thumb_id ? wp_get_attachment_image( (int) $thumb_id, 'full', false, array( 'alt' => $term->name ) ) : '',
			esc_html( $term->name )
		);
	}

	return sprintf(
		'<div class="mf-elementor-brand-images mf-brand-images martfury-images-grid"><div class="images-list columns-%d">%s</div></div>',
		absint( $atts['columns'] ),
		implode( '', $items )
	);
}

/**
 * Header logo: the redrawn vector logo (images/ultra-love-logo.svg, no shadow) instead of the 246x100 PNG set in
 * the theme options, so it stays sharp at any size and screen density. Its purple drop shadow is CSS
 * (style.css, "Header logo"). ?ver= is the file's modification time, so a changed logo isn't served stale.
 */
add_filter( 'martfury_site_logo', 'ultralove_svg_site_logo' );
function ultralove_svg_site_logo( $logo ) {
	$file = get_stylesheet_directory() . '/images/ultra-love-logo.svg';
	if ( ! file_exists( $file ) ) {
		return $logo;
	}

	return get_stylesheet_directory_uri() . '/images/ultra-love-logo.svg?ver=' . filemtime( $file );
}

/**
 * Widget titles (the footer's "About Ultra Love" / "Become A Wholesale Buyer" / "Email Us", the shop's filter
 * sidebar) are <h2>, not Martfury's <h4>: after the page's <h1> and the product cards' / sections' <h2>s an <h4>
 * skipped a level (PageSpeed / Lighthouse "Heading elements are not in a sequentially-descending order"). Their
 * look is all class-based (.widget-title), so it doesn't change.
 */
add_filter( 'dynamic_sidebar_params', 'ultralove_widget_title_h2' );
function ultralove_widget_title_h2( $params ) {
	if ( isset( $params[0]['before_title'], $params[0]['after_title'] ) ) {
		$params[0]['before_title'] = preg_replace( '/^<h4\b/i', '<h2', $params[0]['before_title'] );
		$params[0]['after_title']  = preg_replace( '/^<\/h4>/i', '</h2>', $params[0]['after_title'] );
	}
	return $params;
}

/**
 * Meta description on every page (no SEO plugin is installed; this steps aside if one is). Home: a written summary
 * (the 'ultralove_home_meta_description' filter changes it). Products: the description, else the short
 * description - whichever is real copy (60+ characters, not an all-caps note such as a tester's "A MINIMUM OF 3
 * UNITS..."), else a line with the product's name. Pages and posts: the excerpt, else the text (shortcodes
 * dropped). Product categories, brands and tags: the term's description when it's 60+ characters (some brands'
 * is just their name), else a line naming it. Shop: a catalogue line. Trimmed to ~155 characters at a word break;
 * where there's no text of its own, a line naming the page. Search results get none.
 */
add_action( 'wp_head', 'ultralove_meta_description', 1 );
function ultralove_meta_description() {
	if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) ) {
		return;
	}
	$text = ultralove_meta_description_text();
	if ( '' !== $text ) {
		echo '<meta name="description" content="' . esc_attr( $text ) . '">' . "\n";
	}
}

function ultralove_meta_description_text() {
	$brand = 'Ultra Love Products';

	if ( is_front_page() ) {
		return apply_filters(
			'ultralove_home_meta_description',
			"Ultra Love Products: one of Canada's largest wholesale distributors of adult products. Sex toys, lubricants and more from leading brands, for Canadian retailers."
		);
	}

	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return "Shop the $brand wholesale catalogue: adult products from leading brands - sex toys, lubricants, accessories and more - for retailers across Canada.";
	}

	if ( is_singular( 'product' ) ) {
		$post    = get_queried_object();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post ) : null;
		foreach ( array( $post->post_content, $product ? $product->get_short_description() : '' ) as $candidate ) {
			$text = ultralove_meta_description_trim( $candidate );
			if ( ultralove_meta_description_is_copy( $text ) ) {
				return $text;
			}
		}
		return ultralove_meta_description_trim( ultralove_product_name( $post ) . " - wholesale from $brand, adult products for retailers across Canada." );
	}

	if ( is_singular() ) {
		$post = get_queried_object();
		$text = ultralove_meta_description_trim( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content );
		if ( '' !== $text ) {
			return $text;
		}
		$title = wp_strip_all_tags( get_the_title( $post ) );
		return "$title - $brand, wholesale adult products for retailers across Canada.";
	}

	if ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();
		$text = ultralove_meta_description_trim( term_description( $term ) );
		if ( ultralove_meta_description_is_copy( $text ) ) {
			return $text;
		}
		return 'product_brand' === $term->taxonomy
			? "{$term->name} products, wholesale from $brand - adult products for retailers across Canada."
			: "Shop {$term->name} wholesale at $brand - adult products from leading brands for retailers across Canada.";
	}

	return '';
}

// Real copy: 60+ characters and not mostly capitals (the all-caps notes some products have as a short description).
function ultralove_meta_description_is_copy( $text ) {
	if ( mb_strlen( $text ) < 60 ) {
		return false;
	}
	$letters = preg_replace( '/[^\p{L}]/u', '', $text );
	$upper   = preg_replace( '/[^\p{Lu}]/u', '', $text );
	return mb_strlen( $upper ) <= 0.6 * mb_strlen( $letters );
}

// A product's name from its title, which is SKU / name / promo note joined with <br>: the first line with a space
// that isn't all capitals (else the whole title).
function ultralove_product_name( $post ) {
	$lines = array_filter( array_map( 'trim', preg_split( '/<br\s*\/?>/i', get_the_title( $post ) ) ) );
	foreach ( $lines as $line ) {
		$line = trim( wp_strip_all_tags( $line ) );
		if ( false !== strpos( $line, ' ' ) && $line !== mb_strtoupper( $line ) ) {
			return $line;
		}
	}
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( implode( ' ', $lines ) ) ) );
}

function ultralove_meta_description_trim( $text, $max = 155 ) {
	$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES, 'UTF-8' ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max );
	$space = mb_strrpos( $cut, ' ' );
	return rtrim( $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,.;:-' ) . '…';
}

/**
 * Body class for a page whose own content holds a Gravity Form (Contact Us, Buy Wholesale): style.css lays
 * those pages out as intro text beside / above a form card (.ul-form-page). The same test the Gravity Forms
 * lazy loader uses (mu-plugins/02-lazy-load-gravity-forms.php); the footer's form is a widget and doesn't count.
 */
add_filter( 'body_class', 'ultralove_form_page_body_class' );
function ultralove_form_page_body_class( $classes ) {
	$post = is_singular() ? get_queried_object() : null;
	if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'gravityform' ) || has_block( 'gravityforms/form', $post ) ) ) {
		$classes[] = 'ul-form-page';
	}
	return $classes;
}
