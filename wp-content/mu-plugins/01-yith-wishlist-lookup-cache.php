<?php
/**
 * Plugin Name: YITH Wishlist Lookup Cache (Perf Fix)
 * Description: Memoizes yith_wcwl_get_wishlist() per request so checking "is this product in my
 *              wishlist?" on a product-listing page doesn't reconstruct the whole wishlist once per
 *              product shown, and breaks a recursive self-reload during wishlist-item construction
 *              that made a single wishlist read O(items^2). Customers with large wishlists (1000+
 *              items) were seeing page timeouts / 502s as a result.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-scoped memoization for yith_wcwl_get_wishlist(). Uses static class properties (not
 * function-local statics) so the mutation hooks below can flush it when a wishlist actually changes.
 */
final class Copilot_YITH_Wishlist_Cache {
	private static $cache       = array();
	private static $in_progress = array();

	private static function key( $wishlist_id ) {
		if ( false === $wishlist_id ) {
			// "false" means "the current context's default wishlist" — key it by who that
			// context actually is, so a mid-request user switch (wp_set_current_user() in a
			// WP-CLI script, cron batch, or "act as customer" admin feature) can't serve one
			// account's cached wishlist back for a different account.
			return '__default__:' . self::current_identity();
		}
		return is_array( $wishlist_id ) ? reset( $wishlist_id ) : $wishlist_id;
	}

	private static function current_identity() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return 'user:' . $user_id;
		}
		if ( function_exists( 'WC' ) && WC() && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			return 'session:' . WC()->session->get_customer_id();
		}
		return 'anon';
	}

	public static function has( $wishlist_id ) {
		return array_key_exists( self::key( $wishlist_id ), self::$cache );
	}

	public static function get( $wishlist_id ) {
		return self::$cache[ self::key( $wishlist_id ) ];
	}

	public static function set( $wishlist_id, $wishlist ) {
		self::$cache[ self::key( $wishlist_id ) ] = $wishlist;
	}

	public static function is_in_progress( $wishlist_id ) {
		return ! empty( self::$in_progress[ self::key( $wishlist_id ) ] );
	}

	public static function start( $wishlist_id ) {
		self::$in_progress[ self::key( $wishlist_id ) ] = true;
	}

	public static function finish( $wishlist_id ) {
		unset( self::$in_progress[ self::key( $wishlist_id ) ] );
	}

	public static function flush() {
		self::$cache       = array();
		self::$in_progress = array();
	}
}

if ( ! function_exists( 'yith_wcwl_get_wishlist' ) ) {
	/**
	 * Retrieves wishlist by ID. Overrides the plugin's own pluggable function (see
	 * wp-content/plugins/yith-woocommerce-wishlist/includes/functions-yith-wcwl.php, which wraps its
	 * definition in the same function_exists() guard) purely to add request-scoped caching around it.
	 *
	 * @param int|string|false $wishlist_id Wishlist ID or token, or false for the current default.
	 * @return \YITH_WCWL_Wishlist|bool
	 */
	function yith_wcwl_get_wishlist( $wishlist_id = false ) {
		if ( Copilot_YITH_Wishlist_Cache::has( $wishlist_id ) ) {
			return Copilot_YITH_Wishlist_Cache::get( $wishlist_id );
		}

		/*
		 * YITH_WCWL_Wishlist_Item::set_wishlist_id() (class-yith-wcwl-wishlist-item.php) is called
		 * once per item while a wishlist's item list is being read, and it re-fetches the whole
		 * wishlist just to compare owner user IDs. That turns a single wishlist read into
		 * O(items^2): reading a wishlist with n items triggers n additional full wishlist rebuilds.
		 * While a fetch for this id is already under way, skip the redundant re-fetch instead of
		 * recursing — `false` is this function's documented failure return, and set_wishlist_id()
		 * already treats it as "nothing to compare against" and leaves user_id untouched.
		 */
		if ( Copilot_YITH_Wishlist_Cache::is_in_progress( $wishlist_id ) ) {
			return false;
		}

		Copilot_YITH_Wishlist_Cache::start( $wishlist_id );
		$wishlist = YITH_WCWL_Wishlist_Factory::get_wishlist( $wishlist_id );
		Copilot_YITH_Wishlist_Cache::finish( $wishlist_id );

		Copilot_YITH_Wishlist_Cache::set( $wishlist_id, $wishlist );

		return $wishlist;
	}
}

/**
 * Flush the memoized wishlist(s) whenever one actually changes, so the cache above never serves
 * stale data back within the same request (e.g. an ajax add/remove call that also re-renders
 * button state immediately afterwards).
 */
add_action( 'yith_wcwl_added_to_wishlist', array( 'Copilot_YITH_Wishlist_Cache', 'flush' ) );
add_action( 'yith_wcwl_removed_from_wishlist', array( 'Copilot_YITH_Wishlist_Cache', 'flush' ) );
add_action( 'yith_wcwl_update_wishlist', array( 'Copilot_YITH_Wishlist_Cache', 'flush' ) );

/*
 * YITH_WCWL_Wishlists::is_product_in_wishlist() — the method every product-loop "is this in my
 * wishlist?" badge/button actually calls (via yith_wcwl_wishlists()->is_product_in_wishlist()) —
 * calls YITH_WCWL_Wishlist_Factory::get_wishlist() directly instead of going through the
 * yith_wcwl_get_wishlist() wrapper above, so on a page listing N products it still reconstructs the
 * whole wishlist N times even after the fix above. YITH_WCWL_Wishlists uses
 * YITH_WCWL_Extensible_Singleton_Trait, which is the plugin's own supported way to swap in a
 * subclass: get_instance() looks for a class named "<self>_Extended" (or "_Premium") before falling
 * back to itself, so defining this class is enough to make the whole plugin use it — no core files
 * touched, and every other inherited method behaves exactly as before.
 */
if ( class_exists( 'YITH_WCWL_Wishlists' ) && ! class_exists( 'YITH_WCWL_Wishlists_Extended' ) ) {
	class YITH_WCWL_Wishlists_Extended extends YITH_WCWL_Wishlists {
		/**
		 * Same as the parent implementation, except it resolves the wishlist through the memoized
		 * yith_wcwl_get_wishlist() wrapper instead of calling the factory directly.
		 *
		 * @param int      $product_id  Product id to check.
		 * @param int|bool $wishlist_id Wishlist where to search (false to search in default wishlist).
		 * @return bool
		 */
		public function is_product_in_wishlist( $product_id, $wishlist_id = false ) {
			$wishlist = yith_wcwl_get_wishlist( $wishlist_id );

			if ( ! $wishlist ) {
				return false;
			}

			/** This filter is documented in class-yith-wcwl-wishlists.php */
			return apply_filters( 'yith_wcwl_is_product_in_wishlist', $wishlist->has_product( $product_id ), $product_id, $wishlist_id );
		}
	}
} elseif ( ! class_exists( 'YITH_WCWL_Wishlists' ) ) {
	/*
	 * YITH_WCWL_Wishlists loads later than mu-plugins, so define the subclass once it's available.
	 */
	add_action(
		'plugins_loaded',
		static function () {
			if ( class_exists( 'YITH_WCWL_Wishlists' ) && ! class_exists( 'YITH_WCWL_Wishlists_Extended' ) ) {
				class YITH_WCWL_Wishlists_Extended extends YITH_WCWL_Wishlists {
					public function is_product_in_wishlist( $product_id, $wishlist_id = false ) {
						$wishlist = yith_wcwl_get_wishlist( $wishlist_id );

						if ( ! $wishlist ) {
							return false;
						}

						/** This filter is documented in class-yith-wcwl-wishlists.php */
						return apply_filters( 'yith_wcwl_is_product_in_wishlist', $wishlist->has_product( $product_id ), $product_id, $wishlist_id );
					}
				}
			}
		},
		1
	);
}
