<?php
/**
 * Plugin Name: UltraLove WC Analytics Top Brands Leaderboard
 * Description: Adds a "Top brands - Items sold" leaderboard card to WooCommerce Analytics overview.
 * Version: 1.0.0
 * Author: UltraLove
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UltraLove_WC_Analytics_Top_Brands_Leaderboard {
	const TAXONOMY = 'product_brand';
	const REPORT_SLUG = 'brands';
	private static $wc_enqueue_js_deprecation_logged = false;

	public static function init() {
		add_filter( 'woocommerce_leaderboards', array( __CLASS__, 'add_brands_leaderboard' ), 10, 5 );
		add_filter( 'woocommerce_admin_reports', array( __CLASS__, 'register_brands_report' ) );
		add_filter( 'woocommerce_analytics_report_menu_items', array( __CLASS__, 'register_brands_report_menu_item' ) );
		add_filter( 'deprecated_function_trigger_error', array( __CLASS__, 'filter_deprecated_function_trigger_error' ), 10, 4 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_brands_report_script' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Suppress noisy wc_enqueue_js deprecation spam while logging a single caller trace.
	 *
	 * @param bool   $trigger Whether to trigger a PHP error for the deprecated call.
	 * @param string $function Function name.
	 * @param string $replacement Suggested replacement.
	 * @param string $version Deprecated version.
	 * @return bool
	 */
	public static function filter_deprecated_function_trigger_error( $trigger, $function, $replacement, $version ) {
		if ( 'wc_enqueue_js' !== $function ) {
			return $trigger;
		}

		if ( ! self::$wc_enqueue_js_deprecation_logged ) {
			self::$wc_enqueue_js_deprecation_logged = true;
			error_log(
				sprintf(
					'[ultralove] wc_enqueue_js deprecated call traced: replacement=%s version=%s backtrace=%s',
					(string) $replacement,
					(string) $version,
					wp_debug_backtrace_summary( null, 0, false )
				)
			);
		}

		return false;
	}

	/**
	 * Enqueue wc-admin report extension script for the custom Brands page.
	 */
	public static function enqueue_brands_report_script() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		if ( 'wc-admin' !== $page ) {
			return;
		}

		// Defensive bootstrap for environments where wcAdminFeatures is missing.
		wp_add_inline_script(
			'wc-admin-app',
			'window.wcAdminFeatures=window.wcAdminFeatures||{};window.wcAdminFeatures.analytics=window.wcAdminFeatures.analytics||false;',
			'before'
		);

		$normalized_path = '/' . ltrim( (string) $path, '/' );
		$normalized_path = untrailingslashit( $normalized_path );

		// Load the Brands report extension on all Analytics routes so SPA navigation
		// can resolve the report before users click into it from another report.
		if ( 0 !== strpos( $normalized_path, '/analytics' ) ) {
			return;
		}

		$script_path = WP_CONTENT_DIR . '/mu-plugins/ultralove-wc-analytics-brands-report.js';
		if ( ! is_readable( $script_path ) ) {
			return;
		}

		$script_url = content_url( 'mu-plugins/ultralove-wc-analytics-brands-report.js' );
		if ( empty( $script_url ) ) {
			return;
		}

		wp_register_script(
			'ultralove-wc-analytics-brands-report',
			$script_url,
			array( 'wp-hooks', 'wp-element', 'wp-api-fetch', 'wp-i18n', 'wp-components', 'wp-url' ),
			(string) filemtime( $script_path ),
			true
		);

		wp_enqueue_script( 'ultralove-wc-analytics-brands-report' );

		$scripts = wp_scripts();
		if ( $scripts && isset( $scripts->registered['wc-admin-app'] ) ) {
			$deps = $scripts->registered['wc-admin-app']->deps;
			if ( ! in_array( 'ultralove-wc-analytics-brands-report', $deps, true ) ) {
				$scripts->registered['wc-admin-app']->deps[] = 'ultralove-wc-analytics-brands-report';
			}
		}
	}

	/**
	 * Redirect /analytics/brands wc-admin path to a working brand drilldown page.
	 *
	 * The custom brands report endpoint exists, but WooCommerce core does not ship
	 * a built-in React screen for this slug, which can result in a blank page.
	 */
	public static function maybe_redirect_brands_analytics_path() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';

		if ( 'wc-admin' !== $page || '/analytics/' . self::REPORT_SLUG !== $path ) {
			return;
		}

		$brand_ids = self::normalize_brand_filter_ids( isset( $_GET['brands'] ) ? wp_unslash( $_GET['brands'] ) : array() );
		if ( empty( $brand_ids ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) );
			exit;
		}

		$brand_term = get_term( (int) $brand_ids[0], self::TAXONOMY );
		if ( ! $brand_term || is_wp_error( $brand_term ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'         => 'product',
					self::TAXONOMY      => $brand_term->slug,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Explicitly register the Brands analytics page in wc-admin.
	 *
	 * Some environments can miss the filtered report menu registration timing,
	 * which causes "Sorry, you are not allowed to access this page" on direct links.
	 */
	public static function register_brands_admin_page() {
		if ( ! function_exists( 'wc_admin_register_page' ) ) {
			return;
		}

		wc_admin_register_page(
			array(
				'id'         => 'woocommerce-analytics-brands',
				'title'      => __( 'Brands', 'ultralove' ),
				'page_title' => __( 'Brands', 'ultralove' ),
				'parent'     => 'woocommerce-analytics',
				'path'       => '/analytics/' . self::REPORT_SLUG,
				'capability' => 'view_woocommerce_reports',
			)
		);
	}

	/**
	 * Register brands report in the Analytics reports endpoint list.
	 *
	 * @param array $reports Existing reports.
	 * @return array
	 */
	public static function register_brands_report( $reports ) {
		$reports[] = array(
			'slug'        => self::REPORT_SLUG,
			'description' => __( 'Product brands detailed reports.', 'ultralove' ),
			'url'         => '/analytics/' . self::REPORT_SLUG,
		);

		return $reports;
	}

	/**
	 * Add Brands item to the WooCommerce Analytics menu list.
	 *
	 * @param array $report_pages Existing report pages.
	 * @return array
	 */
	public static function register_brands_report_menu_item( $report_pages ) {
		$brands_page = array(
			'id'     => 'woocommerce-analytics-brands',
			'title'  => __( 'Brands', 'ultralove' ),
			'parent' => 'woocommerce-analytics',
			'path'   => '/analytics/' . self::REPORT_SLUG,
		);

		if ( ! is_array( $report_pages ) ) {
			return array( $brands_page );
		}

		foreach ( $report_pages as $page ) {
			$page_id = '';
			if ( is_array( $page ) && isset( $page['id'] ) ) {
				$page_id = (string) $page['id'];
			} elseif ( is_object( $page ) && isset( $page->id ) ) {
				$page_id = (string) $page->id;
			}

			if ( 'woocommerce-analytics-brands' === $page_id ) {
				return $report_pages;
			}
		}

		$insert_after = null;
		foreach ( $report_pages as $index => $page ) {
			if ( is_array( $page ) && isset( $page['id'] ) && 'woocommerce-analytics-categories' === $page['id'] ) {
				$insert_after = $index;
				break;
			}
		}

		if ( null === $insert_after ) {
			$report_pages[] = $brands_page;
			return $report_pages;
		}

		array_splice( $report_pages, $insert_after + 1, 0, array( $brands_page ) );
		return $report_pages;
	}

	/**
	 * Register custom wc-analytics brands report route.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'wc-analytics',
			'/reports/' . self::REPORT_SLUG,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_brands_report_items' ),
					'permission_callback' => array( __CLASS__, 'brands_report_permissions_check' ),
					'args'                => self::get_brands_report_collection_params(),
				),
			)
		);

		register_rest_route(
			'wc-analytics',
			'/reports/' . self::REPORT_SLUG . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_brands_report_stats' ),
					'permission_callback' => array( __CLASS__, 'brands_report_permissions_check' ),
					'args'                => self::get_brands_report_collection_params(),
				),
			)
		);

		register_rest_route(
			'wc-analytics',
			'/reports/' . self::REPORT_SLUG . '/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_brand_products_report_items' ),
					'permission_callback' => array( __CLASS__, 'brands_report_permissions_check' ),
					'args'                => self::get_brands_report_collection_params(),
				),
			)
		);
	}

	/**
	 * Permission callback for the brands report endpoint.
	 *
	 * @return bool
	 */
	public static function brands_report_permissions_check() {
		return current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Collection params for the brands report endpoint.
	 *
	 * @return array
	 */
	private static function get_brands_report_collection_params() {
		return array(
			'page'          => array(
				'type'    => 'integer',
				'default' => 1,
			),
			'per_page'      => array(
				'type'    => 'integer',
				'default' => 150,
			),
			'after'         => array(
				'type' => 'string',
			),
			'before'        => array(
				'type' => 'string',
			),
			'interval'      => array(
				'type'    => 'string',
				'default' => 'day',
			),
			'orderby'       => array(
				'type'    => 'string',
				'default' => 'items_sold',
			),
			'order'         => array(
				'type'    => 'string',
				'default' => 'desc',
			),
			'brands'        => array(
				'type' => array( 'array', 'string' ),
			),
			'status_is'     => array(
				'type' => 'array',
			),
			'status_is_not' => array(
				'type' => 'array',
			),
		);
	}

	/**
	 * Return totals and time intervals for the Brands report graphs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_brands_report_stats( $request ) {
		$query_ctx = $request->get_params();
		$brand_ids = self::normalize_brand_filter_ids( $request->get_param( 'brands' ) );
		$interval  = self::normalize_interval( $request->get_param( 'interval' ) );

		$result = self::query_brand_interval_rows(
			$request->get_param( 'after' ),
			$request->get_param( 'before' ),
			is_array( $query_ctx ) ? $query_ctx : array(),
			$brand_ids,
			$interval
		);

		if ( ! empty( $brand_ids ) ) {
			$brand_term = get_term( (int) $brand_ids[0], self::TAXONOMY );
			if ( $brand_term && ! is_wp_error( $brand_term ) ) {
				$result['selected_brand'] = array(
					'id'   => (int) $brand_term->term_id,
					'name' => (string) $brand_term->name,
					'slug' => (string) $brand_term->slug,
				);
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Return data rows for /wc-analytics/reports/brands.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_brands_report_items( $request ) {
		$per_page  = max( 1, (int) $request->get_param( 'per_page' ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$offset    = ( $page - 1 ) * $per_page;
		$query_ctx = $request->get_params();

		$brand_ids = self::normalize_brand_filter_ids( $request->get_param( 'brands' ) );
		$order_by  = self::normalize_report_orderby( $request->get_param( 'orderby' ) );
		$order     = self::normalize_report_order( $request->get_param( 'order' ) );

		$result = self::query_brand_report_rows(
			$per_page,
			$offset,
			$request->get_param( 'after' ),
			$request->get_param( 'before' ),
			is_array( $query_ctx ) ? $query_ctx : array(),
			$brand_ids,
			$order_by,
			$order
		);

		$rows = array();
		foreach ( $result['rows'] as $brand ) {
			$rows[] = array(
				'brand_id'       => (int) $brand['brand_id'],
				'items_sold'     => (int) $brand['items_sold'],
				'net_revenue'    => (float) $brand['net_revenue'],
				'orders_count'   => (int) $brand['orders_count'],
				'products_count' => (int) $brand['products_count'],
				'extended_info'  => array(
					'name' => (string) $brand['brand_name'],
					'slug' => (string) $brand['brand_slug'],
				),
			);
		}

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', max( 1, (int) ceil( $result['total'] / $per_page ) ) );
		return $response;
	}

	/**
	 * Return product-level rows for a single brand breakdown.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_brand_products_report_items( $request ) {
		$per_page  = max( 1, (int) $request->get_param( 'per_page' ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$offset    = ( $page - 1 ) * $per_page;
		$query_ctx = $request->get_params();

		$brand_ids = self::normalize_brand_filter_ids( $request->get_param( 'brands' ) );
		$order_by  = self::normalize_product_report_orderby( $request->get_param( 'orderby' ) );
		$order     = self::normalize_report_order( $request->get_param( 'order' ) );

		$result = self::query_brand_product_report_rows(
			$per_page,
			$offset,
			$request->get_param( 'after' ),
			$request->get_param( 'before' ),
			is_array( $query_ctx ) ? $query_ctx : array(),
			$brand_ids,
			$order_by,
			$order
		);

		$rows = array();
		foreach ( $result['rows'] as $product ) {
			$rows[] = array(
				'product_id'    => (int) $product['product_id'],
				'items_sold'    => (int) $product['items_sold'],
				'net_revenue'   => (float) $product['net_revenue'],
				'orders_count'  => (int) $product['orders_count'],
				'extended_info' => array(
					'name' => (string) $product['product_name'],
				),
			);
		}

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', max( 1, (int) ceil( $result['total'] / $per_page ) ) );
		return $response;
	}

	/**
	 * Append custom brands leaderboard to WooCommerce Analytics overview leaderboards.
	 *
	 * @param array       $leaderboards Existing leaderboards.
	 * @param int         $per_page Rows to return.
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array|null  $persisted_query Persisted analytics query params.
	 * @return array
	 */
	public static function add_brands_leaderboard( $leaderboards, $per_page, $after, $before, $persisted_query ) {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return $leaderboards;
		}

		$leaderboards[] = self::build_brands_leaderboard( (int) $per_page, $after, $before, is_array( $persisted_query ) ? $persisted_query : array() );
		return $leaderboards;
	}

	/**
	 * Build leaderboard payload expected by /wc-analytics/leaderboards.
	 *
	 * @param int         $per_page Rows to return.
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array       $persisted_query Persisted analytics query params.
	 * @return array
	 */
	private static function build_brands_leaderboard( $per_page, $after, $before, $persisted_query ) {
		$rows = $per_page > 0 ? self::query_brand_rows( $per_page, $after, $before, $persisted_query ) : array();

		return array(
			'id'      => 'brands',
			'label'   => __( 'Top brands - Items sold', 'ultralove' ),
			'headers' => array(
				array(
					'label' => __( 'Brand', 'ultralove' ),
				),
				array(
					'label' => __( 'Items sold', 'ultralove' ),
				),
				array(
					'label' => __( 'Net sales', 'ultralove' ),
				),
			),
			'rows'    => $rows,
		);
	}

	/**
	 * Query sales-by-brand rows from WooCommerce analytics lookup tables.
	 *
	 * @param int         $per_page Rows to return.
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array       $persisted_query Persisted analytics query params.
	 * @return array
	 */
	private static function query_brand_rows( $per_page, $after, $before, $persisted_query ) {
		$link_query = self::sanitize_wc_admin_link_query( is_array( $persisted_query ) ? $persisted_query : array() );

		$result = self::query_brand_report_rows(
			max( 1, $per_page ),
			0,
			$after,
			$before,
			$persisted_query,
			array(),
			'items_sold',
			'DESC'
		);

		$results = $result['rows'];
		if ( empty( $results ) ) {
			return array();
		}

		$rows = array();
		foreach ( $results as $brand ) {
			$brand_name = (string) $brand['brand_name'];
			$brand_id   = (int) $brand['brand_id'];
			$brand_slug = isset( $brand['brand_slug'] ) ? (string) $brand['brand_slug'] : '';
			$brand_url  = self::build_brand_drilldown_url( $brand_slug, $brand_id, $link_query );

			$rows[] = array(
				array(
					'display' => '<a href="' . esc_url( $brand_url ) . '">' . esc_html( $brand_name ) . '</a>',
					'value'   => $brand_name,
				),
				array(
					'display' => wc_admin_number_format( (float) $brand['items_sold'] ),
					'value'   => (int) $brand['items_sold'],
					'format'  => 'number',
				),
				array(
					'display' => wc_price( (float) $brand['net_revenue'] ),
					'value'   => (float) $brand['net_revenue'],
					'format'  => 'currency',
				),
			);
		}

		return $rows;
	}

	/**
	 * Query aggregated brand rows plus total count for reports.
	 *
	 * @param int         $limit Max records.
	 * @param int         $offset Offset records.
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array       $query_ctx Query context.
	 * @param array       $brand_ids Optional brand ID filters.
	 * @param string      $order_by SQL-safe order by key.
	 * @param string      $order SQL-safe order direction.
	 * @return array
	 */
	private static function query_brand_report_rows( $limit, $offset, $after, $before, $query_ctx, $brand_ids, $order_by, $order ) {
		global $wpdb;

		$use_lookup_date_index = self::should_force_lookup_date_index( $after, $before );
		$select_sql            = $use_lookup_date_index ? 'SELECT STRAIGHT_JOIN' : 'SELECT';
		$lookup_table_sql      = self::get_order_product_lookup_table_sql( $wpdb, $use_lookup_date_index );

		$params        = array();
		$where_clauses = array( 'tt.taxonomy = %s' );
		$params[]      = self::TAXONOMY;

		$date_bound_sql = self::build_date_where( $after, $before, $params );
		if ( $date_bound_sql ) {
			$where_clauses[] = $date_bound_sql;
		}

		$status_sql = self::build_order_status_where( is_array( $query_ctx ) ? $query_ctx : array(), $params );
		if ( $status_sql ) {
			$where_clauses[] = $status_sql;
		}

		if ( ! empty( $brand_ids ) ) {
			$brand_placeholders = implode( ',', array_fill( 0, count( $brand_ids ), '%d' ) );
			$where_clauses[]    = "tt.term_id IN ({$brand_placeholders})";
			$params             = array_merge( $params, $brand_ids );
		}

		$order_column_map = array(
			'brand'          => 'brand_name',
			'items_sold'     => 'items_sold',
			'net_revenue'    => 'net_revenue',
			'orders_count'   => 'orders_count',
			'products_count' => 'products_count',
		);
		$order_column     = isset( $order_column_map[ $order_by ] ) ? $order_column_map[ $order_by ] : 'items_sold';

		$sql = "
			{$select_sql}
				t.term_id AS brand_id,
				t.name AS brand_name,
				t.slug AS brand_slug,
				SUM(opl.product_qty) AS items_sold,
				SUM(opl.product_net_revenue) AS net_revenue,
				COUNT(DISTINCT opl.order_id) AS orders_count,
				COUNT(DISTINCT opl.product_id) AS products_count
			FROM {$lookup_table_sql}
			JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
			JOIN {$wpdb->term_relationships} tr ON opl.product_id = tr.object_id
			JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE " . implode( ' AND ', $where_clauses ) . "
			GROUP BY t.term_id, t.name, t.slug
			ORDER BY {$order_column} {$order}
			LIMIT %d OFFSET %d
		";

		$data_params   = $params;
		$data_params[] = max( 1, (int) $limit );
		$data_params[] = max( 0, (int) $offset );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $data_params ), ARRAY_A );

		$count_sql = "
			SELECT COUNT(*)
			FROM (
				{$select_sql} t.term_id
				FROM {$lookup_table_sql}
				JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
				JOIN {$wpdb->term_relationships} tr ON opl.product_id = tr.object_id
				JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE " . implode( ' AND ', $where_clauses ) . "
				GROUP BY t.term_id
			) AS brands_count
		";

		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Query aggregated product rows for a single-brand report.
	 *
	 * @param int         $limit Max records.
	 * @param int         $offset Offset records.
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array       $query_ctx Query context.
	 * @param array       $brand_ids Required brand ID filters.
	 * @param string      $order_by SQL-safe order by key.
	 * @param string      $order SQL-safe order direction.
	 * @return array
	 */
	private static function query_brand_product_report_rows( $limit, $offset, $after, $before, $query_ctx, $brand_ids, $order_by, $order ) {
		global $wpdb;

		if ( empty( $brand_ids ) ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$use_lookup_date_index = self::should_force_lookup_date_index( $after, $before );
		$select_sql            = $use_lookup_date_index ? 'SELECT STRAIGHT_JOIN' : 'SELECT';
		$lookup_table_sql      = self::get_order_product_lookup_table_sql( $wpdb, $use_lookup_date_index );

		$params        = array();
		$where_clauses = array( 'tt.taxonomy = %s' );
		$params[]      = self::TAXONOMY;

		$date_bound_sql = self::build_date_where( $after, $before, $params );
		if ( $date_bound_sql ) {
			$where_clauses[] = $date_bound_sql;
		}

		$status_sql = self::build_order_status_where( is_array( $query_ctx ) ? $query_ctx : array(), $params );
		if ( $status_sql ) {
			$where_clauses[] = $status_sql;
		}

		$brand_placeholders = implode( ',', array_fill( 0, count( $brand_ids ), '%d' ) );
		$where_clauses[]    = "tt.term_id IN ({$brand_placeholders})";
		$params             = array_merge( $params, $brand_ids );

		$order_column_map = array(
			'product'      => 'product_name',
			'items_sold'   => 'items_sold',
			'net_revenue'  => 'net_revenue',
			'orders_count' => 'orders_count',
		);
		$order_column = isset( $order_column_map[ $order_by ] ) ? $order_column_map[ $order_by ] : 'items_sold';

		$sql = "
			{$select_sql}
				opl.product_id AS product_id,
				COALESCE(NULLIF(p.post_title, ''), CONCAT('Product #', opl.product_id)) AS product_name,
				SUM(opl.product_qty) AS items_sold,
				SUM(opl.product_net_revenue) AS net_revenue,
				COUNT(DISTINCT opl.order_id) AS orders_count
			FROM {$lookup_table_sql}
			JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
			JOIN {$wpdb->term_relationships} tr ON opl.product_id = tr.object_id
			JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			JOIN {$wpdb->posts} p ON p.ID = opl.product_id
			WHERE " . implode( ' AND ', $where_clauses ) . "
			GROUP BY opl.product_id, p.post_title
			ORDER BY {$order_column} {$order}
			LIMIT %d OFFSET %d
		";

		$data_params   = $params;
		$data_params[] = max( 1, (int) $limit );
		$data_params[] = max( 0, (int) $offset );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $data_params ), ARRAY_A );

		$count_sql = "
			SELECT COUNT(*)
			FROM (
				{$select_sql} opl.product_id
				FROM {$lookup_table_sql}
				JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
				JOIN {$wpdb->term_relationships} tr ON opl.product_id = tr.object_id
				JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				JOIN {$wpdb->posts} p ON p.ID = opl.product_id
				WHERE " . implode( ' AND ', $where_clauses ) . "
				GROUP BY opl.product_id
			) AS brand_products_count
		";

		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Query totals and time buckets for Brands graphs.
	 *
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array       $query_ctx Query context.
	 * @param array       $brand_ids Optional brand ID filters.
	 * @param string      $interval Interval key.
	 * @return array
	 */
	private static function query_brand_interval_rows( $after, $before, $query_ctx, $brand_ids, $interval ) {
		global $wpdb;

		$use_lookup_date_index = self::should_force_lookup_date_index( $after, $before );
		$select_sql            = $use_lookup_date_index ? 'SELECT STRAIGHT_JOIN' : 'SELECT';
		$lookup_table_sql      = self::get_order_product_lookup_table_sql( $wpdb, $use_lookup_date_index );

		$params        = array();
		$where_clauses = array( 'tt.taxonomy = %s' );
		$params[]      = self::TAXONOMY;

		$date_bound_sql = self::build_date_where( $after, $before, $params );
		if ( $date_bound_sql ) {
			$where_clauses[] = $date_bound_sql;
		}

		$status_sql = self::build_order_status_where( is_array( $query_ctx ) ? $query_ctx : array(), $params );
		if ( $status_sql ) {
			$where_clauses[] = $status_sql;
		}

		if ( ! empty( $brand_ids ) ) {
			$brand_placeholders = implode( ',', array_fill( 0, count( $brand_ids ), '%d' ) );
			$where_clauses[]    = "tt.term_id IN ({$brand_placeholders})";
			$params             = array_merge( $params, $brand_ids );
		}

		$bucket_sql = self::get_interval_bucket_sql( $interval, 'opl.date_created' );

		$sql = "
			{$select_sql}
				{$bucket_sql['group']} AS bucket_key,
				MIN(opl.date_created) AS date_start,
				MAX(opl.date_created) AS date_end,
				SUM(opl.product_qty) AS items_sold,
				SUM(opl.product_net_revenue) AS net_revenue,
				COUNT(DISTINCT opl.order_id) AS orders_count
			FROM {$lookup_table_sql}
			JOIN {$wpdb->prefix}wc_order_stats os ON os.order_id = opl.order_id
			JOIN {$wpdb->term_relationships} tr ON opl.product_id = tr.object_id
			JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE " . implode( ' AND ', $where_clauses ) . "
			GROUP BY bucket_key
			ORDER BY date_start ASC
		";

		$interval_rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$totals = array(
			'items_sold'   => 0,
			'net_revenue'  => 0.0,
			'orders_count' => 0,
		);
		$intervals = array();

		foreach ( (array) $interval_rows as $row ) {
			$items_sold   = (int) $row['items_sold'];
			$net_revenue  = (float) $row['net_revenue'];
			$orders_count = (int) $row['orders_count'];

			$totals['items_sold']   += $items_sold;
			$totals['net_revenue']  += $net_revenue;
			$totals['orders_count'] += $orders_count;

			$intervals[] = array(
				'date_start'   => (string) $row['date_start'],
				'date_end'     => (string) $row['date_end'],
				'items_sold'   => $items_sold,
				'net_revenue'  => $net_revenue,
				'orders_count' => $orders_count,
			);
		}

		return array(
			'totals'    => $totals,
			'intervals' => $intervals,
		);
	}

	/**
	 * Build SQL fragment for date bounds.
	 *
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @param array       $params Prepared statement params.
	 * @return string
	 */
	private static function build_date_where( $after, $before, &$params ) {
		$clauses = array();

		$after_sql = self::normalize_datetime_for_sql( $after );
		if ( $after_sql ) {
			$clauses[] = 'opl.date_created >= %s';
			$params[]  = $after_sql;
		}

		$before_sql = self::normalize_datetime_for_sql( $before );
		if ( $before_sql ) {
			$clauses[] = 'opl.date_created <= %s';
			$params[]  = $before_sql;
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * Convert incoming datetime to SQL format expected by lookup tables.
	 *
	 * @param string|null $value Input date string.
	 * @return string|null
	 */
	private static function normalize_datetime_for_sql( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return null;
		}

		try {
			$dt = new DateTimeImmutable( $value );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Use the date index when analytics requests provide bounded ranges.
	 *
	 * This prevents MySQL from flipping to taxonomy-first plans on wide ranges.
	 *
	 * @param string|null $after Date lower bound.
	 * @param string|null $before Date upper bound.
	 * @return bool
	 */
	private static function should_force_lookup_date_index( $after, $before ) {
		return null !== self::normalize_datetime_for_sql( $after ) || null !== self::normalize_datetime_for_sql( $before );
	}

	/**
	 * Build the order product lookup table reference with optional index hint.
	 *
	 * @param wpdb $wpdb WordPress database wrapper.
	 * @param bool $use_date_index Whether to force the date_created index.
	 * @return string
	 */
	private static function get_order_product_lookup_table_sql( $wpdb, $use_date_index ) {
		$table_sql = "{$wpdb->prefix}wc_order_product_lookup opl";

		if ( $use_date_index ) {
			$table_sql .= ' FORCE INDEX (date_created)';
		}

		return $table_sql;
	}

	/**
	 * Build SQL status filter that mirrors WooCommerce Analytics defaults.
	 *
	 * @param array $persisted_query Persisted analytics query params.
	 * @param array $params Prepared statement params.
	 * @return string
	 */
	private static function build_order_status_where( $persisted_query, &$params ) {
		$status_is     = array();
		$status_is_not = array();

		if ( isset( $persisted_query['status_is'] ) && is_array( $persisted_query['status_is'] ) ) {
			$status_is = self::normalize_statuses_for_sql( $persisted_query['status_is'] );
		}

		if ( isset( $persisted_query['status_is_not'] ) && is_array( $persisted_query['status_is_not'] ) ) {
			$status_is_not = self::normalize_statuses_for_sql( $persisted_query['status_is_not'] );
		}

		if ( empty( $status_is ) && empty( $status_is_not ) ) {
			$excluded = get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
			$excluded = is_array( $excluded ) ? $excluded : array( 'pending', 'failed', 'cancelled' );
			$excluded = array_merge( array( 'auto-draft', 'trash' ), $excluded );
			$excluded = apply_filters( 'woocommerce_analytics_excluded_order_statuses', $excluded );
			$excluded = self::normalize_statuses_for_sql( $excluded );
			if ( ! empty( $excluded ) ) {
				$status_is_not = $excluded;
			}
		}

		$clauses = array();
		if ( ! empty( $status_is ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $status_is ), '%s' ) );
			$clauses[]    = "os.status IN ({$placeholders})";
			$params       = array_merge( $params, $status_is );
		}

		if ( ! empty( $status_is_not ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $status_is_not ), '%s' ) );
			$clauses[]    = "os.status NOT IN ({$placeholders})";
			$params       = array_merge( $params, $status_is_not );
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * Normalize order statuses to wc-prefixed DB form.
	 *
	 * @param array $statuses Raw statuses.
	 * @return array
	 */
	private static function normalize_statuses_for_sql( $statuses ) {
		$normalized = array();
		foreach ( $statuses as $status ) {
			if ( ! is_string( $status ) ) {
				continue;
			}
			$status = trim( $status );
			if ( '' === $status || 'any' === $status ) {
				continue;
			}
			if ( 0 !== strpos( $status, 'wc-' ) ) {
				$status = 'wc-' . $status;
			}
			$normalized[] = $status;
		}
		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize brand filter IDs from endpoint input.
	 *
	 * @param mixed $raw_ids Raw brands param.
	 * @return array
	 */
	private static function normalize_brand_filter_ids( $raw_ids ) {
		if ( is_string( $raw_ids ) ) {
			$raw_ids = array_filter( array_map( 'trim', explode( ',', $raw_ids ) ) );
		}

		if ( ! is_array( $raw_ids ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw_ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Normalize report orderby parameter to an allowed key.
	 *
	 * @param mixed $value Raw orderby.
	 * @return string
	 */
	private static function normalize_report_orderby( $value ) {
		$allowed = array( 'brand', 'items_sold', 'net_revenue', 'orders_count', 'products_count' );
		if ( ! is_string( $value ) ) {
			return 'items_sold';
		}

		$value = strtolower( trim( $value ) );
		return in_array( $value, $allowed, true ) ? $value : 'items_sold';
	}

	/**
	 * Normalize single-brand product report orderby parameter to an allowed key.
	 *
	 * @param mixed $value Raw orderby.
	 * @return string
	 */
	private static function normalize_product_report_orderby( $value ) {
		$allowed = array( 'product', 'items_sold', 'net_revenue', 'orders_count' );
		if ( ! is_string( $value ) ) {
			return 'items_sold';
		}

		$value = strtolower( trim( $value ) );
		return in_array( $value, $allowed, true ) ? $value : 'items_sold';
	}

	/**
	 * Normalize interval parameter to a supported value.
	 *
	 * @param mixed $value Raw interval.
	 * @return string
	 */
	private static function normalize_interval( $value ) {
		$allowed = array( 'day', 'week', 'month' );
		if ( ! is_string( $value ) ) {
			return 'day';
		}

		$value = strtolower( trim( $value ) );
		return in_array( $value, $allowed, true ) ? $value : 'day';
	}

	/**
	 * Build SQL expressions for interval bucketing.
	 *
	 * @param string $interval Interval key.
	 * @param string $column SQL datetime column.
	 * @return array
	 */
	private static function get_interval_bucket_sql( $interval, $column ) {
		switch ( $interval ) {
			case 'month':
				return array(
					'group' => "DATE_FORMAT({$column}, '%Y-%m-01 00:00:00')",
				);
			case 'week':
				return array(
					'group' => "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)",
				);
			case 'day':
			default:
				return array(
					'group' => "DATE({$column})",
				);
		}
	}

	/**
	 * Normalize report order parameter.
	 *
	 * @param mixed $value Raw order direction.
	 * @return string
	 */
	private static function normalize_report_order( $value ) {
		if ( ! is_string( $value ) ) {
			return 'DESC';
		}

		$value = strtoupper( trim( $value ) );
		return 'ASC' === $value ? 'ASC' : 'DESC';
	}

	/**
	 * Remove reserved admin routing keys from wc_admin_url query args.
	 *
	 * Passing a "page" arg can override page=wc-admin and trigger
	 * WordPress admin access denial for links generated in leaderboards.
	 *
	 * @param array $query Raw query args.
	 * @return array
	 */
	private static function sanitize_wc_admin_link_query( $query ) {
		if ( ! is_array( $query ) ) {
			return array();
		}

		unset( $query['page'], $query['path'] );
		return $query;
	}

	/**
	 * Build a guaranteed working drilldown URL for a brand.
	 *
	 * @param string $brand_slug Brand slug.
	 * @param int    $brand_id Brand ID.
	 * @param array  $query Additional query context.
	 * @return string
	 */
	private static function build_brand_drilldown_url( $brand_slug, $brand_id, $query ) {
		return wc_admin_url(
			'/analytics/' . self::REPORT_SLUG,
			wp_parse_args(
				array(
					'filter' => 'single_brand',
					'brands' => (int) $brand_id,
				),
				$query
			)
		);
	}
}

UltraLove_WC_Analytics_Top_Brands_Leaderboard::init();
