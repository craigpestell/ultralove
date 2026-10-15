<?php
/**
 * wp eval-file /private/current-name-slugs.php preview /private/urls.json /private/ids.json
 * wp eval-file /private/current-name-slugs.php apply|verify|restore /private/urls.json
 * ids.json is an explicit array of product IDs. Back up the database before apply.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through wp eval-file.' );
}
$mode = $args[0] ?? '';
$path = $args[1] ?? '';
if ( ! in_array( $mode, array( 'preview', 'apply', 'verify', 'restore' ), true )
	|| ! str_starts_with( $path, '/' ) || ! is_dir( dirname( $path ) )
	|| str_starts_with( realpath( dirname( $path ) ) . '/', realpath( ABSPATH ) . '/' )
	|| is_link( $path ) ) {
	WP_CLI::error( 'Use preview|apply|verify|restore and a private absolute plan path outside the webroot.' );
}
if ( ! class_exists( 'Red_Item' ) || ! class_exists( 'Red_Module' ) ) {
	WP_CLI::error( 'The Redirection plugin must be active.' );
}
global $wpdb;
$read = static function ( string $sql ) use ( $wpdb ): array {
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error ) {
		WP_CLI::error( 'Could not read migration state: ' . $wpdb->last_error );
	}
	return $rows;
};
$snapshot = static function ( array $ids ) use ( $wpdb, $read ): array {
	$list = implode( ',', $ids );
	return array(
		'slugs' => $read( "SELECT ID,post_name FROM {$wpdb->posts} WHERE post_type = 'product' ORDER BY ID" ),
		'redirects' => $read( "SELECT id,url,match_url,action_data,action_type,match_type,action_code,group_id,status,regex,match_data,title,position FROM {$wpdb->prefix}redirection_items ORDER BY id" ),
		'aliases' => $read( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_old_slug','_ul_previous_slug') ORDER BY meta_id" ),
		'products' => $read( "SELECT ID,post_title,post_name,post_content,post_excerpt,post_type,post_status,post_parent FROM {$wpdb->posts} WHERE ID IN ($list) ORDER BY ID" ),
		'notes' => $read( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($list) AND meta_key IN ('_ul_original_title','_ul_retailer_info','_ul_retailer_important','_ul_description_cleanup_version') ORDER BY meta_id" ),
	);
};
$save = static function ( array $plan ) use ( $path ): void {
	$handle = fopen( $path, 'x' );
	if ( false === $handle ) {
		WP_CLI::error( 'Refusing to overwrite a plan.' );
	}
	try {
		if ( ! chmod( $path, 0600 ) ) {
			WP_CLI::error( 'Cannot secure private plan.' );
		}
		$json = wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		if ( fwrite( $handle, $json ) !== strlen( $json ) ) {
			WP_CLI::error( 'Could not save complete plan.' );
		}
	} finally {
		fclose( $handle );
	}
};
if ( 'preview' === $mode ) {
	$scope = $args[2] ?? '';
	if ( ! is_readable( $scope ) ) {
		WP_CLI::error( 'Preview requires a JSON array of explicit product IDs.' );
	}
	$ids = json_decode( file_get_contents( $scope ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $ids ) || ! array_is_list( $ids ) || ! $ids
		|| array_filter( $ids, static fn( $id ) => ! is_int( $id ) || $id < 1 ) ) {
		WP_CLI::error( 'Scope must be a nonempty array of positive integer IDs.' );
	}
	$ids = array_values( array_unique( $ids ) );
	sort( $ids );
	$before = $after = $snapshot( $ids );
	if ( count( $before['products'] ) !== count( $ids ) ) {
		WP_CLI::error( 'Some scoped products do not exist.' );
	}
	$rows = $changes = $destinations = $slug_changes = array();
	foreach ( $before['products'] as $key => $post ) {
		$id = (int) $post['ID'];
		if ( 'product' !== $post['post_type'] || in_array( $post['post_status'], array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			WP_CLI::error( 'Invalid product status/type for ' . $id );
		}
		$slug = sanitize_title( ultralove_rn_clean_text( $post['post_title'] ) );
		if ( '' === $slug || strlen( $slug ) > 200
			|| wp_unique_post_slug( $slug, $id, 'publish', 'product', (int) $post['post_parent'] ) !== $slug
			|| isset( $destinations[ $slug ] ) ) {
			WP_CLI::error( 'Empty, oversized or colliding name-derived slug for ' . $id );
		}
		$destinations[ $slug ] = $id;
		if ( $slug === $post['post_name'] ) {
			continue;
		}
		$old_path = '/product/' . $post['post_name'] . '/';
		$new_path = '/product/' . $slug . '/';
		if ( wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) !== $old_path ) {
			WP_CLI::error( 'Unexpected permalink structure for ' . $id );
		}
		$rows[] = array( 'id' => $id, 'title' => $post['post_title'], 'old' => $post['post_name'], 'new' => $slug );
		$changes[ $old_path ] = $new_path;
		$slug_changes[ $id ] = $slug;
		$after['products'][ $key ]['post_name'] = $slug;
	}
	foreach ( $after['slugs'] as &$entry ) {
		if ( isset( $slug_changes[ (int) $entry['ID'] ] ) ) {
			$entry['post_name'] = $slug_changes[ (int) $entry['ID'] ];
		}
	}
	unset( $entry );
	$redirects = array();
	$final_paths = array_values( $changes );
	foreach ( $before['redirects'] as $key => $rule ) {
		if ( 'enabled' !== $rule['status'] ) {
			continue;
		}
		$target = $rule['action_data'];
		if ( str_starts_with( $target, home_url() . '/' ) ) {
			$target = substr( $target, strlen( home_url() ) );
		}
		$incoming = isset( $changes[ $target ] );
		$final_source = in_array( $rule['url'], $final_paths, true );
		if ( ! $incoming && ! $final_source ) {
			continue;
		}
		if ( '0' !== $rule['regex'] || 'url' !== $rule['match_type'] || 'url' !== $rule['action_type']
			|| '301' !== $rule['action_code'] || ! $incoming ) {
			WP_CLI::error( 'Unsafe or conflicting redirect ' . $rule['id'] );
		}
		$new = $rule;
		if ( $final_source ) {
			if ( $rule['url'] !== $changes[ $target ] ) {
				WP_CLI::error( 'Redirect source belongs to another final product: ' . $rule['id'] );
			}
			$new['status'] = 'disabled';
		} else {
			$new['action_data'] = $changes[ $target ];
		}
		$after['redirects'][ $key ] = $new;
		$redirects[] = array( 'before' => $rule, 'after' => $new );
	}
	foreach ( $after['redirects'] as $rule ) {
		if ( 'enabled' === $rule['status'] && isset( $changes[ $rule['url'] ] ) ) {
			WP_CLI::error( 'An intermediate URL already has a redirect: ' . $rule['id'] );
		}
	}
	$plan = array( 'version' => 1, 'site' => home_url(), 'ids' => $ids, 'rows' => $rows, 'redirects' => $redirects, 'before' => $before, 'after' => $after );
	$save( $plan );
	WP_CLI::success( count( $rows ) . ' name-derived slugs; ' . count( $redirects ) . ' existing rules to retarget/disable; no new redirects or aliases. Review ' . $path );
	return;
}
$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
if ( 1 !== $plan['version'] || home_url() !== $plan['site'] ) {
	WP_CLI::error( 'Unsupported plan or different site.' );
}
$to = 'restore' === $mode ? 'before' : 'after';
$current = $snapshot( $plan['ids'] );
if ( $current === $plan[ $to ] ) {
	WP_CLI::success( 'Verified exact ' . $to . ' state; no writes needed.' );
	return;
}
if ( 'verify' === $mode ) {
	WP_CLI::error( 'State differs from the plan. No writes made; investigate before proceeding.' );
}
// Accept only whole planned records in either state, including interrupted runs.
$baseline = $current;
foreach ( array( 'slugs', 'products', 'redirects' ) as $section ) {
	foreach ( $baseline[ $section ] as $index => $entry ) {
		if ( $entry === ( $plan['after'][ $section ][ $index ] ?? null ) ) {
			$baseline[ $section ][ $index ] = $plan['before'][ $section ][ $index ];
		}
	}
}
if ( $baseline !== $plan['before'] ) {
	WP_CLI::error( 'State differs from the plan. No writes made; investigate before proceeding.' );
}
foreach ( $plan['rows'] as $row ) {
	$slug = 'restore' === $mode ? $row['old'] : $row['new'];
	$post = get_post( $row['id'] );
	if ( ! $post || wp_unique_post_slug( $slug, $row['id'], 'publish', 'product', (int) $post->post_parent ) !== $slug ) {
		WP_CLI::error( 'Slug collision before writing product ' . $row['id'] );
	}
}
$old_slug_priority = has_action( 'post_updated', 'wp_check_for_changed_slugs' );
if ( false !== $old_slug_priority ) {
	remove_action( 'post_updated', 'wp_check_for_changed_slugs', $old_slug_priority );
}
$no_redirect = static fn() => false;
add_filter( 'redirection_permalink_changed', $no_redirect, PHP_INT_MAX );
try {
	foreach ( $plan['rows'] as $row ) {
		$slug = 'restore' === $mode ? $row['old'] : $row['new'];
		$expected = 'restore' === $mode ? $row['new'] : $row['old'];
		$actual = get_post_field( 'post_name', $row['id'], 'raw' );
		if ( ! in_array( $actual, array( $expected, $slug ), true )
			|| get_post_field( 'post_title', $row['id'], 'raw' ) !== $row['title'] ) {
			WP_CLI::error( 'Concurrent edit; stopped at ' . $row['id'] . '. Retain the plan for scoped recovery.' );
		}
		if ( $actual === $slug ) {
			continue;
		}
		$result = wp_update_post( array( 'ID' => $row['id'], 'post_name' => $slug ), true );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() . '; retain the plan for scoped recovery.' );
		}
		clean_post_cache( $row['id'] );
		if ( get_post_field( 'post_name', $row['id'], 'raw' ) !== $slug ) {
			WP_CLI::error( 'Slug readback failed for ' . $row['id'] . '; retain the plan for scoped recovery.' );
		}
	}
	foreach ( $plan['redirects'] as $change ) {
		$old = 'restore' === $mode ? $change['after'] : $change['before'];
		$new = 'restore' === $mode ? $change['before'] : $change['after'];
		$rule_id = (int) $old['id'];
		$rules = $read( "SELECT id,url,match_url,action_data,action_type,match_type,action_code,group_id,status,regex,match_data,title,position FROM {$wpdb->prefix}redirection_items WHERE id = $rule_id" );
		$actual = null;
		foreach ( $rules as $rule ) {
			if ( $rule['id'] === $old['id'] ) {
				$actual = $rule;
				break;
			}
		}
		if ( $actual === $new ) {
			Red_Module::flush( (int) $new['group_id'] );
			continue;
		}
		if ( $actual !== $old ) {
			WP_CLI::error( 'Concurrent redirect edit on ' . $old['id'] . '; retain the plan for scoped recovery.' );
		}
		// Preserve raw match settings exactly; the plugin's full sanitizer normalizes them.
		$result = $wpdb->update(
			$wpdb->prefix . 'redirection_items',
			array( 'action_data' => $new['action_data'], 'status' => $new['status'] ),
			array( 'id' => $old['id'], 'action_data' => $old['action_data'], 'status' => $old['status'] )
		);
		if ( 1 !== $result ) {
			WP_CLI::error( 'Redirect write failed or concurrent edit on ' . $old['id'] . '; retain the plan for scoped recovery.' );
		}
		Red_Module::flush( (int) $new['group_id'] );
	}
} finally {
	remove_filter( 'redirection_permalink_changed', $no_redirect, PHP_INT_MAX );
	if ( false !== $old_slug_priority ) {
		add_action( 'post_updated', 'wp_check_for_changed_slugs', $old_slug_priority, 3 );
	}
}
if ( $snapshot( $plan['ids'] ) !== $plan[ $to ] ) {
	WP_CLI::error( 'Global readback differs from the expected exact delta; retain the plan for scoped recovery.' );
}
WP_CLI::success( 'Verified ' . count( $plan['rows'] ) . ' name-derived URLs and exact global redirect/alias/content state (' . $to . ').' );
