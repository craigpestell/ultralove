<?php
/**
 * Custom functions for header.
 *
 * @package Martfury
 */


/**
 * Get Menu extra Account
 *
 * @since  1.0.0
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_account' ) ) :
	function martfury_extra_account() {
		$extras = martfury_menu_extras();

		if ( empty( $extras ) || ! in_array( 'account', $extras ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$user_menu = martfury_nav_vendor_menu();
			$user_id   = get_current_user_id();
			if ( empty( $user_menu ) ) {
				$user_menu = martfury_nav_user_menu();
			}
			$account = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
			if ( ! empty( martfury_get_option( 'user_logged_link' ) ) ) {
				$account_link = martfury_get_option( 'user_logged_link' );

			} else {
				$account_link = $account;
			}
			$author      = get_user_by( 'id', $user_id );
			$author_name = $author->display_name;

			$logged_type = '<i class="extra-icon icon-user"></i>';
			$user_type   = 'icon';
			if ( martfury_get_option( 'user_logged_type' ) == 'avatar' ) {
				$logged_type = get_avatar( $user_id, 32 );
				$user_type   = 'avatar';
			}


			if ( class_exists( 'WeDevs_Dokan' ) && in_array( 'seller', $author->roles ) ) {
				if ( ! empty( martfury_get_option( 'vendor_logged_link' ) ) ) {
					$account_link = martfury_get_option( 'vendor_logged_link' );
				} else {
					$account_link = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url() : $account_link;
				}
				$shop_info    = get_user_meta( $user_id, 'dokan_profile_settings', true );
				if ( $shop_info && isset( $shop_info['store_name'] ) && $shop_info['store_name'] ) {
					$author_name = $shop_info['store_name'];
				}
			} elseif ( class_exists( 'WCVendors_Pro' ) && in_array( 'vendor', $author->roles ) ) {

				if ( ! empty( martfury_get_option( 'vendor_logged_link' ) ) ) {
					$account_link = martfury_get_option( 'vendor_logged_link' );
				} else {
					$dashboard_page_id = get_option( 'wcvendors_dashboard_page_id' );
					$dashboard_page_id = is_array( $dashboard_page_id ) ? $dashboard_page_id[0] : $dashboard_page_id;
					if ( $dashboard_page_id ) {
						$account_link = get_permalink( $dashboard_page_id );
					}
                }

			} elseif ( class_exists( 'WC_Vendors' ) && in_array( 'vendor', $author->roles ) ) {

				if ( ! empty( martfury_get_option( 'vendor_logged_link' ) ) ) {
					$account_link = martfury_get_option( 'vendor_logged_link' );
				} else {
					$vendor_dashboard_page = get_option( 'wcvendors_vendor_dashboard_page_id' );
					$account_link          = get_permalink( $vendor_dashboard_page );
                }


			} elseif ( class_exists( 'WCMp' ) && in_array( 'dc_vendor', $author->roles ) ) {
				if ( ! empty( martfury_get_option( 'vendor_logged_link' ) ) ) {
					$account_link = martfury_get_option( 'vendor_logged_link' );
				} else {
					if ( function_exists( 'wcmp_vendor_dashboard_page_id' ) && wcmp_vendor_dashboard_page_id() ) {
						$account_link = get_permalink( wcmp_vendor_dashboard_page_id() );
					}
                }

				if ( function_exists( 'get_wcmp_vendor' ) ) {
					$store_user  = get_wcmp_vendor( $user_id );
					$author_name = $store_user->page_title;
				}
			} elseif ( function_exists( 'wcfm_is_vendor' ) && wcfm_is_vendor() ) {
				if ( ! empty( martfury_get_option( 'vendor_logged_link' ) ) ) {
					$account_link = martfury_get_option( 'vendor_logged_link' );
				} else {
					$pages = get_option( "wcfm_page_options" );
					if ( isset( $pages['wc_frontend_manager_page_id'] ) && $pages['wc_frontend_manager_page_id'] ) {
						$account_link = get_permalink( $pages['wc_frontend_manager_page_id'] );
					}
                }

				global $WCFM;
				$author_name = $WCFM->wcfm_vendor_support->wcfm_get_vendor_store_name_by_vendor( absint( $user_id ) );

				if ( function_exists( 'wcfmmp_get_store' ) && martfury_get_option( 'user_logged_type' ) == 'avatar' ) {
					$store_user  = wcfmmp_get_store( $user_id );
					$logged_type = sprintf( '<img src="%s" alt="%s">', esc_url( $store_user->get_avatar() ), esc_html__( 'Logo', 'martfury' ) );
				}

			}



			echo sprintf(
				'<li class="extra-menu-item menu-item-account logined %s">
				<a href="%s">%s</a>
				<ul>
					<li>
						<h3>%s</h3>
					</li>
					<li>
						%s
					</li>
					<li class="line-space"></li>
					<li class="logout">
						<a href="%s">%s</a>
					</li>
				</ul>
			</li>',
				esc_attr( $user_type ),
				esc_url( $account_link ),
				$logged_type,
				esc_html__( 'Hello,', 'martfury' ) . ' ' . $author_name . '!',
				implode( ' ', $user_menu ),
				esc_url( wp_logout_url( $account ) ),
				esc_html__( 'Logout', 'martfury' )
			);
		} else {

			$register      = '';
			$register_text = esc_html__( 'Register', 'martfury' );

			if ( get_option( 'woocommerce_enable_myaccount_registration' ) === 'yes' ) {
				$register = sprintf(
					'<a href="%s" class="item-register" id="menu-extra-register">%s</a>',
					esc_url( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) ),
					$register_text
				);
			}

			echo sprintf(
				'<li class="extra-menu-item menu-item-account">
					<a href="%s" id="menu-extra-login"><i class="extra-icon icon-user"></i><span class="login-text">%s</span></a>
					%s
				</li>',
				esc_url( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) ),
				esc_html__( 'Log in', 'martfury' ),
				$register
			);
		}


	}
endif;
/**
 * Get Menu extra cart
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_vendor_navigation_url' ) ) :
	function martfury_vendor_navigation_url() {
		$author   = get_user_by( 'id', get_current_user_id() );
		$vendor   = array();
		$vendor[] = '<ul>';
		if ( function_exists( 'dokan_get_navigation_url' ) && in_array( 'seller', $author->roles ) ) {
			$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( dokan_get_navigation_url() ), esc_html__( 'Dashboard', 'martfury' ) );
			$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( dokan_get_navigation_url( 'products' ) ), esc_html__( 'Products', 'martfury' ) );
			$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( dokan_get_navigation_url( 'orders' ) ), esc_html__( 'Orders', 'martfury' ) );
			$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( dokan_get_navigation_url( 'edit-account' ) ), esc_html__( 'Settings', 'martfury' ) );
			if ( function_exists( 'dokan_get_store_url' ) ) {
				$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( dokan_get_store_url( get_current_user_id() ) ), esc_html__( 'Visit Store', 'martfury' ) );
			}
			$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( dokan_get_navigation_url( 'withdraw' ) ), esc_html__( 'Withdraw', 'martfury' ) );
		} elseif ( class_exists( 'WCVendors_Pro' ) && in_array( 'vendor', $author->roles ) ) {
			$dashboard_page_id = get_option( 'wcvendors_dashboard_page_id' );
			$dashboard_page_id = is_array( $dashboard_page_id ) ? $dashboard_page_id[0] : $dashboard_page_id;
			if ( $dashboard_page_id ) {
				$dashboard_page_url = get_permalink( $dashboard_page_id );
				$vendor[]           = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $dashboard_page_url ), esc_html__( 'Dashboard', 'martfury' ) );
				$vendor[]           = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $dashboard_page_url . 'product' ), esc_html__( 'Products', 'martfury' ) );
				$vendor[]           = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $dashboard_page_url . 'order' ), esc_html__( 'Orders', 'martfury' ) );
				$vendor[]           = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $dashboard_page_url . 'settings' ), esc_html__( 'Settings', 'martfury' ) );
			}
		} elseif ( class_exists( 'WC_Vendors' ) && in_array( 'vendor', $author->roles ) ) {
			$vendor_dashboard_page = get_option( 'wcvendors_vendor_dashboard_page_id' );
			$shop_settings_page    = get_option( 'wcvendors_shop_settings_page_id' );

			if ( ! empty( $vendor_dashboard_page ) && ! empty( $shop_settings_page ) ) {
				if ( ! empty( $vendor_dashboard_page ) ) {
					$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $vendor_dashboard_page ) ), esc_html__( 'Dashboard', 'martfury' ) );
				}
				if ( ! empty( $shop_settings_page ) ) {
					$vendor[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $shop_settings_page ) ), esc_html__( 'Shop Settings', 'martfury' ) );
				}
				if ( class_exists( 'WCV_Vendors' ) ) {
					$shop_page = WCV_Vendors::get_vendor_shop_page( get_current_user_id() );
					$vendor[]  = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $shop_page ), esc_html__( 'Visit Store', 'martfury' ) );
				}
			}

		}

		$vendor[] = '</ul>';

		return $vendor;
	}
endif;

/**
 * Get Custom Vendor
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_nav_user_menu' ) ) :
	function martfury_nav_user_menu() {
		$user_menu = array();
		if ( ! has_nav_menu( 'user_logged' ) ) {
			$orders  = get_option( 'woocommerce_myaccount_orders_endpoint', 'orders' );
			$account = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
			if ( substr( $account, - 1, 1 ) != '/' ) {
				$account .= '/';
			}
			$orders   = $account . $orders;
			$wishlist = '';
			if ( shortcode_exists( 'yith_wishlist_constructor' ) ) {
				$wishlist = sprintf(
					'<li>
						<a href="%s">%s</a>
					</li>',
					esc_url( get_permalink( get_option( 'yith_wcwl_wishlist_page_id' ) ) ),
					esc_html__( 'My Wishlist', 'martfury' )
				);
			}

			$user_menu[] = sprintf(
				'<ul>
				<li>
					<a href="%s">%s</a>
				</li>
				<li>
					<a href="%s">%s</a>
				</li>
				<li>
					<a href="%s">%s</a>
				</li>
				%s
				</ul>',
				esc_url( $account ),
				esc_html__( 'Dashboard', 'martfury' ),
				esc_url( $account . get_option( 'woocommerce_myaccount_edit_account_endpoint', 'edit-account' ) ),
				esc_html__( 'Account Settings', 'martfury' ),
				esc_url( $orders ),
				esc_html__( 'Orders History', 'martfury' ),
				$wishlist
			);
		} else {
			ob_start();
			martfury_get_nav_menu( 'user_logged' );
			$user_menu[] = ob_get_clean();
		}

		return $user_menu;
	}
endif;

/**
 * Get Custom Vendor
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_nav_vendor_menu' ) ) :
	function martfury_nav_vendor_menu() {
		$author      = get_user_by( 'id', get_current_user_id() );
		$vendor_menu = array();

		if ( ! in_array( 'vendor', $author->roles ) && ! in_array( 'seller', $author->roles )
		     && ! in_array( 'dc_vendor', $author->roles ) && ! in_array( 'wcfm_vendor', $author->roles ) ) {
			return $vendor_menu;
		}
		if ( ! has_nav_menu( 'vendor_logged' ) ) {
			$vendor_menu = martfury_vendor_navigation_url();
		} else {
			ob_start();
			martfury_get_nav_menu( 'vendor_logged' );
			$vendor_menu[] = ob_get_clean();
		}

		return $vendor_menu;
	}
endif;


/**
 * Get Menu extra cart
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_cart' ) ) :
	function martfury_extra_cart() {
		$extras = martfury_menu_extras();

		if ( empty( $extras ) || ! in_array( 'cart', $extras ) ) {
			return '';
		}

		if ( ! function_exists( 'woocommerce_mini_cart' ) ) {
			return '';
		}
		global $woocommerce;
		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();

		$mini_content = sprintf( '	<div class="widget_shopping_cart_content">%s</div>', $mini_cart );

		printf(
			'<li class="extra-menu-item menu-item-cart mini-cart woocommerce">
				<a class="cart-contents" id="icon-cart-contents" href="%s">
					<i class="icon-bag2 extra-icon"></i>
					<span class="mini-item-counter mf-background-primary">
						%s
					</span>
				</a>
				<div class="mini-cart-content">
				<span class="tl-arrow-menu"></span>
				%s
				</div>
			</li>',
			esc_url( wc_get_cart_url() ),
			intval( $woocommerce->cart->cart_contents_count ),
			$mini_content
		);

	}
endif;

/**
 * Get Menu extra wishlist
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_wislist' ) ) :
	function martfury_extra_wislist() {
		$extras = martfury_menu_extras();


		if ( empty( $extras ) || ! in_array( 'wishlist', $extras ) ) {
			return '';
		}

		if ( ! function_exists( 'YITH_WCWL' ) ) {
			return '';
		}

		$count = YITH_WCWL()->count_products();

		printf(
			'<li class="extra-menu-item menu-item-wishlist menu-item-yith">
			<a class="yith-contents" id="icon-wishlist-contents" href="%s">
				<i class="icon-heart extra-icon" rel="tooltip"></i>
				<span class="mini-item-counter mf-background-primary">
					%s
				</span>
			</a>
		</li>',
			esc_url( get_permalink( get_option( 'yith_wcwl_wishlist_page_id' ) ) ),
			intval( $count )
		);

	}
endif;

/**
 * Get Menu extra wishlist
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_compare' ) ) :
	function martfury_extra_compare() {
		$extras = martfury_menu_extras();

		if ( empty( $extras ) || ! in_array( 'compare', $extras ) ) {
			return '';
		}

		if ( ! class_exists( 'YITH_Woocompare' ) ) {
			return '';
		}

		global $yith_woocompare;

		$count = $yith_woocompare->obj->products_list;


		printf(
			'<li class="extra-menu-item menu-item-compare menu-item-yith">
				<a class="yith-contents yith-woocompare-open" href="#">
					<i class="icon-chart-bars extra-icon"></i>
					<span class="mini-item-counter mf-background-primary" id="mini-compare-counter">
						%s
					</span>
				</a>
			</li>', sizeof( $count )
		);

	}
endif;

/**
 * Get Menu extra hotline
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_hotline' ) ) :
	function martfury_extra_hotline() {
		$extras = martfury_menu_extras();


		if ( empty( $extras ) || ! in_array( 'hotline', $extras ) ) {
			return '';
		}

		$hotline_text   = martfury_get_option( 'custom_hotline_text' );
		$hotline_number = martfury_get_option( 'custom_hotline_number' );
		$hotline_link   = martfury_get_option( 'custom_hotline_link' );
		$link_before    = ! empty( $hotline_link ) ? sprintf( '<a href="%s">', esc_url( $hotline_link ) ) : '';
		$link_after     = ! empty( $hotline_link ) ? '</a>' : '';


		printf(
			'<li class="extra-menu-item menu-item-hotline">
                %s
				<i class="icon-telephone extra-icon"></i>
				<span class="hotline-content">
					<label>%s</label>
					<span>%s</span>
				</span>
				%s
		    </li>',
			$link_before,
			wp_kses( $hotline_text, wp_kses_allowed_html( 'post' ) ),
			esc_html( $hotline_number ),
			$link_after
		);

	}
endif;


/**
 * Get Menu extra search
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_search' ) ) :
	function martfury_extra_search( $show_cat = true ) {
		$extras = martfury_menu_extras();
		if ( empty( $extras ) || ! in_array( 'search', $extras ) ) {
			return;
		}

		$output = array();

		if ( martfury_get_option( 'search_form_type' ) == 'default' ) {
			$output[] = martfury_extra_search_form( $show_cat );
		} else {
			$search_form = martfury_get_option( 'search_form_shortcode' );
			$output[]    = do_shortcode( wp_kses( $search_form, wp_kses_allowed_html( 'post' ) ) );
		}

		$hot_words = array();

		if ( intval( martfury_get_option( 'header_hot_words_enable' ) ) ) {
			$hot_words = martfury_get_hot_words();
		}

		echo sprintf(
			'<div class="product-extra-search">
                %s %s
            </div>',
			implode( '', $output ),
			implode( '', $hot_words )
		);

	}
endif;

/**
 * Get Menu extra search form
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_search_form' ) ) :
	function martfury_extra_search_form( $show_cat = true ) {

		$cats_text   = martfury_get_option( 'custom_categories_text' );
		$search_text = martfury_get_option( 'custom_search_text' );
		$button_text = martfury_get_option( 'custom_search_button' );
		$search_type = martfury_get_option( 'search_content_type' );

		if ( $search_type == 'all' ) {
			$show_cat = false;
		}

		if ( ! intval( martfury_get_option( 'search_product_categories' ) ) ) {
			$show_cat = false;
		}

		$cat = '';
		if ( taxonomy_exists( 'product_cat' ) && $show_cat ) {

			$depth = 0;
			if ( intval( martfury_get_option( 'custom_categories_depth' ) ) > 0 ) {
				$depth = intval( martfury_get_option( 'custom_categories_depth' ) );
			}

			$args = array(
				'name'            => 'product_cat',
				'taxonomy'        => 'product_cat',
				'orderby'         => 'NAME',
				'hierarchical'    => 1,
				'hide_empty'      => 1,
				'echo'            => 0,
				'value_field'     => 'slug',
				'class'           => 'product-cat-dd',
				'show_option_all' => esc_html( $cats_text ),
				'depth'           => $depth,
				'id'              => 'header-search-product-cat',
			);

			$cat_include = martfury_get_option( 'custom_categories_include' );
			if ( ! empty( $cat_include ) ) {
				$cat_include     = explode( ',', $cat_include );
				$args['include'] = $cat_include;
			}

			$cat_exclude = martfury_get_option( 'custom_categories_exclude' );
			if ( ! empty( $cat_exclude ) ) {
				$cat_exclude     = explode( ',', $cat_exclude );
				$args['exclude'] = $cat_exclude;
			}

			$cat = wp_dropdown_categories( $args );
		}
		$item_class     = empty( $cat ) ? 'no-cats' : '';
		$post_type_html = '';
		if ( $search_type == 'product' ) {
			$post_type_html = '<input type="hidden" name="post_type" value="product">';
		}

		$lang = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : false;
		if ( $lang ) {
			$post_type_html .= '<input type="hidden" name="lang" value="' . $lang . '"/>';
		}

		return sprintf(
			'<form class="products-search" method="get" action="%s">
                <div class="psearch-content">
                    <div class="product-cat"><div class="product-cat-label %s">%s</div> %s</div>
                    <div class="search-wrapper">
                        <input type="text" name="s"  class="search-field" autocomplete="off" placeholder="%s">
                        %s
                        <div class="search-results woocommerce"></div>
                    </div>
                    <button type="submit" class="search-submit mf-background-primary">%s</button>
                </div>
            </form>',
			esc_url( home_url( '/' ) ),
			esc_attr( $item_class ),
			esc_html( $cats_text ),
			$cat,
			esc_html( $search_text ),
			$post_type_html,
			wp_kses( $button_text, wp_kses_allowed_html( 'post' ) )
		);

	}
endif;

/**
 * Get header menu
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_category' ) ) :
	function martfury_extra_category() {
		$extras = martfury_menu_extras();
		$items  = '';

		if ( empty( $extras ) || ! in_array( 'category', $extras ) ) {
			return $items;
		}

		echo '<a href="#" class="site-header-category--mobile" id="site-header-category--mobile"><i class="icon-menu"></i></a>';
	}
endif;


/**
 * Get header menu
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_header_menu' ) ) :
	function martfury_header_menu() {
		if ( ! has_nav_menu( 'primary' ) ) {
			return;
		}
		?>
        <div class="primary-nav nav">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'walker'         => new Martfury_Mega_Menu_Walker()
			) );
			?>
        </div>
		<?php
	}
endif;

/**
 * Get header bar
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_header_bar' ) ) :
	function martfury_header_bar() {
		if ( ! intval( martfury_get_option( 'header_bar' ) ) ) {
			return;
		}

		?>
        <div class="header-bar topbar">
			<?php
			$sidebar = 'header-bar';
			if ( is_active_sidebar( $sidebar ) ) {
				dynamic_sidebar( $sidebar );
			}
			?>
        </div>
		<?php
	}
endif;

/**
 * Get header recently products
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_header_recently_products' ) ) :
	function martfury_header_recently_products() {

		if ( ! intval( martfury_get_option( 'header_recently_viewed' ) ) ) {
			return;
		}

		$title    = martfury_get_option( 'header_recently_viewed_title' );
		$columns  = 8;
		$rv_class = "";
		if ( martfury_footer_container_classes() == 'martfury-container' ) {
			$columns  = 11;
			$rv_class = 'rv-full-width';
		}

		$pt_content = '<div class="mf-loading"></div>';
		if ( intval( martfury_get_option( 'header_recently_viewed_ajax' ) ) ) {
			$rv_class .= ' load-ajax';
		} else {
			$atts              = array();
			$atts['numbers']   = martfury_get_option( 'header_recently_viewed_number' );
			$atts['link_text'] = martfury_get_option( 'header_recently_viewed_link_text' );
			$atts['link_url']  = martfury_get_option( 'header_recently_viewed_link_url' );
			$pt_content        = martfury_recently_viewed_products( $atts );

			$rv_class .= empty( $_COOKIE['woocommerce_recently_viewed'] ) ? ' no-products' : '';
		}
		if ( $title ):
			?>
            <h3 class="recently-title">
				<?php echo esc_html( $title ); ?>
            </h3>
			<?php
			echo '<div class="mf-recently-products header-recently-viewed ' . $rv_class . '" data-columns="' . $columns . '" id="header-recently-viewed">' . $pt_content . '</div>';
		endif;
	}
endif;

/**
 * Get header exrta department
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_extra_department' ) ) :
	function martfury_extra_department( $dep_close = false, $id = '' ) {
		$extras   = martfury_menu_extras();
		$location = 'shop_department';

		if ( empty( $extras ) || ! in_array( 'department', $extras ) ) {
			return;
		}

		if ( ! has_nav_menu( $location ) ) {
			return;
		}

		$dep_text = '<i class="icon-menu"><span class="s-space">&nbsp;</span></i>';
		$c_link   = martfury_get_option( 'custom_department_link' );
		if ( ! empty( $c_link ) ) {
			$dep_text .= '<a href="' . esc_url( $c_link ) . '" class="text">' . martfury_get_option( 'custom_department_text' ) . '</a>';
		} else {
			$dep_text .= '<span class="text">' . martfury_get_option( 'custom_department_text' ) . '</span>';
		}

		$dep_open = 'mf-closed';

		if ( $dep_close && martfury_is_homepage() ) {
			$dep_open = martfury_get_option( 'department_open_homepage' ) == 'open' ? 'open' : $dep_open;
		}
		$cat_style = '';
		$space     = martfury_get_option( 'department_space_2_homepage' );
		if ( in_array( martfury_get_option( 'header_layout' ), array( '2', '3' ) ) ) {
			$space = martfury_get_option( 'department_space_homepage' );
		}
		if ( martfury_is_homepage() && $space ) {
			$cat_style = sprintf( 'style=padding-top:%s', esc_attr( $space ) );
		}

		?>
        <div class="products-cats-menu <?php echo esc_attr( $dep_open ); ?>">
            <div class="cats-menu-title"><?php echo wp_kses( $dep_text, wp_kses_allowed_html( 'post' ) ); ?></div>

            <div class="toggle-product-cats nav" <?php echo esc_attr( $cat_style ); ?>>
				<?php
				global $martfury_department_menu;

				if ( empty( $martfury_department_menu ) ) {

					$options = array(
						'theme_location' => $location,
						'container'      => false,
						'echo'           => false,
						'walker'         => new Martfury_Mega_Menu_Walker()
					);

					$martfury_department_menu = wp_nav_menu( $options );
				}

				echo ! empty( $martfury_department_menu ) ? $martfury_department_menu : '';
				?>
            </div>
        </div>
		<?php
	}
endif;


/**
 * Get header exrta department
 *
 * @since  1.0.0
 *
 *
 * @return string
 */
if ( ! function_exists( 'martfury_get_nav_menu' ) ) :
	function martfury_get_nav_menu( $location, $walker = true ) {
		if ( ! has_nav_menu( $location ) ) {
			return;
		}

		$options = array(
			'theme_location' => $location,
			'container'      => false,
		);

		if ( $walker ) {
			$options['walker'] = new Martfury_Mega_Menu_Walker();
		}
		wp_nav_menu( $options );

		?>
		<?php
	}
endif;


/**
 * Get menu extra
 *
 * @since  1.0.0
 *
 *
 * @return string
 */

if ( ! function_exists( 'martfury_menu_extras' ) ) :
	function martfury_menu_extras() {
		$menu_extras = apply_filters( 'martfury_get_menu_extras', martfury_get_option( 'menu_extras' ) );

		return $menu_extras;
	}
endif;

/**
 * Get header bar
 *
 * @since  1.0.0
 *
 *
 * @return array
 */
if ( ! function_exists( 'martfury_get_hot_words' ) ) :
	function martfury_get_hot_words() {

		$words_html = array();

		$hot_words = martfury_get_option( 'header_hot_words' );
		if ( ! empty( $hot_words ) ) {

			$words_html[] = '<ul class="hot-words">';
			foreach ( $hot_words as $word ) {
				if ( isset( $word['text'] ) && ! empty( $word['text'] ) ) {
					$words_html[] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( $word['link'] ), $word['text'] );
				}
			}
			$words_html[] = '</ul>';
		}

		return $words_html;
	}
endif;


/**
 * Returns CSS for the color schemes.
 *
 *
 * @param array $colors Color scheme colors.
 *
 * @return string Color scheme CSS.
 */
function martfury_get_color_scheme_css( $colors, $darken_color ) {

	if ( is_page_template( 'template-coming-soon-page.php' ) ) {
		return;
	}

	return <<<CSS
	/* Color Scheme */

	/* Color */

	body {
		--mf-primary-color: {$colors};
		--mf-background-primary-color: {$colors};
		--mf-border-primary-color: {$colors};
	}

	.widget_shopping_cart_content .woocommerce-mini-cart__buttons .checkout,
	 .header-layout-4 .topbar:not(.header-bar),
	 .header-layout-3 .topbar:not(.header-bar){
		background-color: {$darken_color};
	}


CSS;
}

function martfury_header_class() {
	$classes = array();

	if ( intval( martfury_get_option( 'header_hot_words_enable' ) ) ) {
		if ( martfury_get_option( 'header_hot_words' ) ) {
			$classes[] = 'has-hot-words';
		}
	}

	if ( intval( martfury_get_option( 'sticky_header' ) ) ) {
		if ( intval( martfury_get_option( 'sticky_header_logo' ) ) ) {
			$classes[] = 'sticky-header-logo';
		}
	}

	if ( in_array( martfury_get_option( 'header_layout' ), array( '1', '3', '7' ) ) ) {
		$classes[] = 'header-department-bot';
	} elseif ( in_array( martfury_get_option( 'header_layout' ), array( '2', '4', '5', '6', '8' ) ) ) {
		$classes[] = 'header-department-top';
	}

	if ( in_array( martfury_get_option( 'header_layout' ), array( '3', '4', '7' ) ) ) {
		$classes[] = 'header-dark';
	} elseif ( in_array( martfury_get_option( 'header_layout' ), array( '5' ) ) ) {
		$classes[] = 'header-light';
	}


	echo implode( ' ', $classes );
}

function martfury_topbar_classes() {
	$classes = array();

	if ( in_array( martfury_get_option( 'header_layout' ), array( '3', '4', '7' ) ) ) {
		$classes[] = 'topbar-dark';
	} elseif ( in_array( martfury_get_option( 'header_layout' ), array( '5' ) ) ) {
		$classes[] = 'topbar-light';
	}

	echo implode( ' ', $classes );
}