<?php
/**
 * Plugin Name: Persistent Plugin-Headers Cache (Perf Fix)
 * Description: WordPress marks the `plugins` cache group non-persistent, so get_plugins() opens every installed
 *              plugin's main file to read its header on every request that calls it. Meta Box's updater does
 *              that while it loads (~15 ms on a catalog page). The Redis object cache keeps the group in
 *              Redis instead; WordPress itself clears it (wp_clean_plugins_cache) when a plugin is installed,
 *              updated, activated, deactivated or deleted, and the object cache's WP_REDIS_MAXTTL bounds
 *              any other change (a plugin file edited by hand, or deployed by deploy/deploy.sh) to that long.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_object_cache;

if ( is_object( $wp_object_cache ) && isset( $wp_object_cache->ignored_groups ) && is_array( $wp_object_cache->ignored_groups ) ) {
	$wp_object_cache->ignored_groups = array_values( array_diff( $wp_object_cache->ignored_groups, array( 'plugins' ) ) );
}
