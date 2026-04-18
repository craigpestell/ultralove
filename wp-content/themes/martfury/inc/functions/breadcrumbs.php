<?php
/**
 * Display breadcrumbs for posts, pages, archive page with the microdata that search engines understand
 *
 * @see http://support.google.com/webmasters/bin/answer.py?hl=en&answer=185417
 *
 * @param array|string $args
 */

if ( ! function_exists( 'martfury_breadcrumbs' ) ) :
	function martfury_breadcrumbs( $args = '' ) {
		$args = wp_parse_args(
			$args, array(
				'separator'         => '<span class="sep">/</span>',
				'home_class'        => 'home',
				'before'            => '<span class="before">' . esc_html__( 'You are here: ', 'martfury' ) . '</span>',
				'before_item'       => '',
				'after_item'        => '',
				'taxonomy'          => 'category',
				'display_last_item' => true,
				'show_on_front'     => true,
				'labels'            => array(
					'home'      => esc_html__( 'Home', 'martfury' ),
					'archive'   => esc_html__( 'Archives', 'martfury' ),
					'blog'      => esc_html__( 'Blog', 'martfury' ),
					'search'    => esc_html__( 'Search results for', 'martfury' ),
					'not_found' => esc_html__( 'Not Found', 'martfury' ),
					'portfolio' => esc_html__( 'Portfolio', 'martfury' ),
					'author'    => esc_html__( 'Author:', 'martfury' ),
					'day'       => esc_html__( 'Daily:', 'martfury' ),
					'month'     => esc_html__( 'Monthly:', 'martfury' ),
					'year'      => esc_html__( 'Yearly:', 'martfury' ),
				),
			)
		);

		$args = apply_filters( 'martfury_breadcrumbs_args', $args );

		if ( is_front_page() && ! $args['show_on_front'] ) {
			return;
		}

		$items = array();

		// HTML template for each item
		$item_tpl = $args['before_item'] . '
		 <li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">
			<a href="%s" itemprop="item"><span itemprop="name">%s</span><meta itemprop="position" content="%s"></a>
		</li>
	' . $args['after_item'];

		// Home
		if ( ! $args['home_class'] ) {
			$items[] = sprintf( $item_tpl, get_home_url(), $args['labels']['home'] );
		} else {
			$items[] = sprintf(
				'%s<li itemprop="itemListElement" itemscope itemtype="http://schema.org/ListItem">
				<a class="%s" href="%s" itemprop="item">
					<span itemprop="name">%s </span>
					<meta itemprop="position" content="1">
				</a>
				</li>%s',
				$args['before_item'],
				$args['home_class'],
				apply_filters( 'martfury_breadcrumbs_home_url', get_home_url() ),
				$args['labels']['home'],
				$args['after_item']
			);

		}

		$item_position = 2;

		// Front page
		if ( is_front_page() ) {
			$items = array();
		} elseif ( function_exists( 'wcfm_is_store_page' ) && wcfm_is_store_page() ) {
			$author    = get_user_by( 'id', get_query_var( 'author' ) );
			$shop_name = $author->display_name;
			if ( function_exists( 'wcfmmp_get_store' ) ) {
				$store_user = wcfmmp_get_store( get_query_var( 'author' ) );
				$store_info = $store_user->get_shop_info();
				$shop_name  = $store_info['store_name'];
			}

			$store_url = function_exists( 'wcfmmp_get_store_url' ) ? wcfmmp_get_store_url( get_query_var( 'author' ) ) : get_home_url();
			$items[]   = sprintf( $item_tpl, esc_url( $store_url ), $shop_name, esc_attr( $item_position ) );

		} // Blog
		elseif ( is_home() && ! is_front_page() ) {
			$items[] = sprintf(
				$item_tpl,
				esc_url( get_the_permalink( get_option( 'page_for_posts' ) ) ),
				get_the_title( get_option( 'page_for_posts' ) ),
				esc_attr( $item_position )
			);
		} // Single
		elseif ( is_single() ) {
			// Terms

			$taxonomy = $args['taxonomy'];

			if ( is_singular( 'product' ) ) {
				$taxonomy = 'product_cat';
				if ( apply_filters( 'martfury_breadcrumb_get_shop_page', true ) && $page_id = get_option( 'woocommerce_shop_page_id' ) ) {
					$items[] = sprintf( $item_tpl, esc_url( get_permalink( $page_id ) ), get_the_title( $page_id ), esc_attr( $item_position ) );
					$item_position ++;
				}
			}

			$primary_term_id = false;
			if ( apply_filters( 'martfury_yoast_get_primary_term_id', function_exists( 'yoast_get_primary_term_id' ) ) ) {
				$primary_term_id = yoast_get_primary_term_id( 'product_cat' );

			}

			if ( ! $primary_term_id && function_exists( 'wc_get_product_terms' ) ) {
				$terms = wc_get_product_terms(
					get_the_ID(), 'product_cat', apply_filters(
						'woocommerce_product_categories_widget_product_terms_args', array(
							'orderby' => 'parent',
							'order'   => 'desc'
						)
					)
				);

				if ( ! empty( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( $term->parent != 0 ) {
							$primary_term_id = $term->term_id;
							break;
						}
					}

					$primary_term_id = $primary_term_id ? $primary_term_id : $terms[0]->term_id;
				}

			}


			if ( $primary_term_id ) {
				$cat_ancestors = get_ancestors( $primary_term_id, 'product_cat' );
				if ( $cat_ancestors ) {
					$cat_ancestors = array_reverse( $cat_ancestors );
				}
				array_push( $cat_ancestors, $primary_term_id );

				foreach ( $cat_ancestors as $term_id ) {
					$parent_term = get_term_by( 'id', $term_id, 'product_cat' );
					if ( is_wp_error( $parent_term ) || ! $parent_term ) {
						continue;
					}
					$items[] = sprintf( $item_tpl, get_term_link( $parent_term, $taxonomy ), $parent_term->name, esc_attr( $item_position ) );
					$item_position ++;
				}
			}


			if ( $args['display_last_item'] ) {
				$items[] = sprintf( $item_tpl, esc_url( get_the_permalink() ), get_the_title(), esc_attr( $item_position ) );
			}

		} // Page
		elseif ( is_page() ) {
			if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
				if ( apply_filters( 'martfury_breadcrumb_get_shop_page', true ) && $page_id = get_option( 'woocommerce_shop_page_id' ) ) {
					$items[] = sprintf( $item_tpl, esc_url( get_permalink( $page_id ) ), get_the_title( $page_id ), esc_attr( $item_position ) );
					$item_position ++;
				}

			} else {
				$pages = martfury_get_post_parents( get_queried_object_id() );
				foreach ( $pages as $page ) {
					$items[] = sprintf( $item_tpl, esc_url( get_permalink( $page ) ), get_the_title( $page ), esc_attr( $item_position ) );
					$item_position ++;
				}
			}


			if ( $args['display_last_item'] ) {
				$items[] = sprintf( $item_tpl, esc_url( get_the_permalink() ), get_the_title(), esc_attr( $item_position ) );
			}
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {

			if ( martfury_is_wc_vendor_page() && class_exists( 'WCV_Vendors' ) ) {
				$vendor_shop = urldecode( get_query_var( 'vendor_shop' ) );
				$vendor_id   = WCV_Vendors::get_vendor_id( $vendor_shop );
				$items[]     = sprintf( $item_tpl, esc_url( WCV_Vendors::get_vendor_shop_page( $vendor_id ) ), WCV_Vendors::get_vendor_shop_name( $vendor_id ), esc_attr( $item_position ) );
			} else {
				$title = get_the_title( get_option( 'woocommerce_shop_page_id' ) );
				$link  = get_permalink( get_option( 'woocommerce_shop_page_id' ) );
				if ( $args['display_last_item'] ) {
					$items[] = sprintf( $item_tpl, esc_url( $link ), $title, esc_attr( $item_position ) );
				}
			}


		} elseif ( is_tax() || is_category() || is_tag() ) {
			if ( function_exists( 'is_product_category' ) && is_product_category() ) {
				if ( apply_filters( 'martfury_breadcrumb_get_shop_page', true ) && $page_id = get_option( 'woocommerce_shop_page_id' ) ) {
					$items[] = sprintf( $item_tpl, esc_url( get_permalink( $page_id ) ), get_the_title( $page_id ), esc_attr( $item_position ) );
					$item_position ++;
				}
			}

			$current_term = get_queried_object();

			if( ! is_wp_error( $current_term ) && $current_term) {
				$terms        = martfury_get_term_parents( get_queried_object_id(), $current_term->taxonomy );
				if ( $terms ) {
					foreach ( $terms as $term_id ) {
						$term = get_term( $term_id, $current_term->taxonomy );
						if ( is_wp_error( $term ) || ! $term ) {
							continue;
						}
						$items[] = sprintf( $item_tpl, get_term_link( $term, $current_term->taxonomy ), $term->name, esc_attr( $item_position ) );
						$item_position ++;
					}
				}

				if ( $args['display_last_item'] ) {
					$items[] = sprintf( $item_tpl, get_term_link( $current_term, $current_term->taxonomy ), $current_term->name, esc_attr( $item_position ) );
				}
			}


		} elseif ( function_exists( 'dokan_is_store_page' ) && dokan_is_store_page() ) {
			$author    = get_user_by( 'id', get_query_var( 'author' ) );
			$shop_info = get_user_meta( get_query_var( 'author' ), 'dokan_profile_settings', true );
			$shop_name = $author->display_name;
			if ( $shop_info && isset( $shop_info['store_name'] ) && $shop_info['store_name'] ) {
				$shop_name = $shop_info['store_name'];
			}
			$shop_url = function_exists( 'dokan_get_store_url' ) ? dokan_get_store_url( get_query_var( 'author' ) ) : home_url();
			$items[]  = sprintf( $item_tpl, esc_url( $shop_url ), $shop_name, esc_attr( $item_position ) );

		} // Search
		elseif ( is_search() ) {
			$items[] = sprintf( $item_tpl, esc_url( get_the_permalink() ), $args['labels']['search'] . ' &quot;' . get_search_query() . '&quot;', esc_attr( $item_position ) );
		} // 404
		elseif ( is_404() ) {
			$items[] = sprintf( $item_tpl, esc_url( get_the_permalink() ), $args['labels']['not_found'], esc_attr( $item_position ) );
		} // Author archive
		elseif ( is_author() ) {
			// Queue the first post, that way we know what author we're dealing with (if that is the case).
			the_post();
			$items[] = sprintf(
				$item_tpl,
				esc_url( get_the_permalink() ),
				$args['labels']['author'] . ' <span class="vcard"><a class="url fn n" href="' . get_author_posts_url( get_the_author_meta( 'ID' ) ) . '" title="' . esc_attr( get_the_author() ) . '" rel="me">' . get_the_author() . '</a></span>',
				esc_attr( $item_position )
			);
			rewind_posts();
		} // Day archive
		elseif ( is_day() ) {
			$items[] = sprintf(
				$item_tpl,
				esc_url( get_the_permalink() ),
				sprintf( esc_html__( '%s %s', 'martfury' ), $args['labels']['day'], get_the_date() ),
				esc_attr( $item_position )
			);
		} // Month archive
		elseif ( is_month() ) {
			$items[] = sprintf(
				$item_tpl,
				esc_url( get_the_permalink() ),
				sprintf( esc_html__( '%s %s', 'martfury' ), $args['labels']['month'], get_the_date( 'F Y' ) ),
				esc_attr( $item_position )
			);
		} // Year archive
		elseif ( is_year() ) {
			$items[] = sprintf(
				$item_tpl,
				esc_url( get_the_permalink() ),
				sprintf( esc_html__( '%s %s', 'martfury' ), $args['labels']['year'], get_the_date( 'Y' ) ),
				esc_attr( $item_position )
			);
		} // Archive
		else {
			$items[] = sprintf(
				$item_tpl,
				esc_url( get_the_permalink() ),
				$args['labels']['archive'],
				esc_attr( $item_position )
			);

		}

		echo implode( $args['separator'], $items );
	}
endif;

/**
 * Searches for term parents' IDs of hierarchical taxonomies, including current term.
 * This function is similar to the WordPress function get_category_parents() but handles any type of taxonomy.
 * Modified from Hybrid Framework
 *
 * @param int|string $term_id The term ID
 * @param object|string $taxonomy The taxonomy of the term whose parents we want.
 *
 * @return array Array of parent terms' IDs.
 */
function martfury_get_term_parents( $term_id = '', $taxonomy = 'category' ) {
	// Set up some default arrays.
	$list = array();

	// If no term ID or taxonomy is given, return an empty array.
	if ( empty( $term_id ) || empty( $taxonomy ) ) {
		return $list;
	}

	do {
		$list[] = $term_id;

		// Get next parent term
		$term    = get_term( $term_id, $taxonomy );
		$term_id = $term->parent;
	} while ( $term_id );

	// Reverse the array to put them in the proper order for the trail.
	$list = array_reverse( $list );
	array_pop( $list );


	return $list;
}

/**
 * Gets parent posts' IDs of any post type, include current post
 * Modified from Hybrid Framework
 *
 * @param int|string $post_id ID of the post whose parents we want.
 *
 * @return array Array of parent posts' IDs.
 */
function martfury_get_post_parents( $post_id = '' ) {
	// Set up some default array.
	$list = array();

	// If no post ID is given, return an empty array.
	if ( empty( $post_id ) ) {
		return $list;
	}

	do {
		$list[] = $post_id;

		// Get next parent post
		$post    = get_post( $post_id );
		$post_id = $post->post_parent;
	} while ( $post_id );

	// Reverse the array to put them in the proper order for the trail.
	$list = array_reverse( $list );
	array_pop( $list );

	return $list;
}