<?php
/**
 * wp eval-file /private/normalize-notes.php preview|apply|verify|restore /private/notes.json [IDs]
 * Optional preview IDs are comma-separated. Review the plan and back up before apply.
 * Only the two note metadata keys are written; restore accepts partially applied plans.
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
	WP_CLI::error( 'Use preview|apply|verify|restore and a private absolute plan outside the webroot.' );
}
global $wpdb;
$read = static function ( string $sql ) use ( $wpdb ): array {
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Could not read migration state: ' . $wpdb->last_error );
	}
	return $rows;
};
$guard = static function () use ( $wpdb, $read ): string {
	return hash( 'sha256', serialize( array(
		$read( "SELECT ID,post_title,post_name,post_content,post_excerpt,post_type,post_status,post_parent FROM {$wpdb->posts} WHERE post_type = 'product' ORDER BY ID" ),
		$read( "SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_ul_original_title','_ul_description_cleanup_version','_wp_old_slug','_ul_previous_slug') ORDER BY meta_id" ),
		$read( "SELECT id,url,match_url,action_data,action_type,match_type,action_code,group_id,status,regex,match_data,title,position FROM {$wpdb->prefix}redirection_items ORDER BY id" ),
	) ) );
};
$keys = array( 'info' => ULTRALOVE_RN_INFO, 'important' => ULTRALOVE_RN_IMPORTANT );
$snapshot = static function ( int $id ) use ( $keys ): array {
	clean_post_cache( $id );
	$state = array();
	foreach ( $keys as $field => $key ) {
		$state[ $field ] = get_post_meta( $id, $key, false );
	}
	return $state;
};
$normalize = static function ( array $before ): array {
	$have = array();
	foreach ( array( 'info', 'important' ) as $field ) {
		if ( count( $before[ $field ] ) > 1 || ( $before[ $field ] && ! is_string( $before[ $field ][0] ) ) ) {
			return array( 'after' => $before, 'review' => array( 'Multiple or non-text metadata values: manual review required.' ) );
		}
		$have[ $field ] = preg_split( '/\R/', $before[ $field ][0] ?? '', -1, PREG_SPLIT_NO_EMPTY );
	}
	$notes = ultralove_rn_merge_notes( $have );
	$after = $before;
	if ( ! $notes['review'] ) {
		foreach ( array( 'info', 'important' ) as $field ) {
			$text = implode( "\n", $notes[ $field ] );
			$after[ $field ] = '' === $text && ! $before[ $field ] ? array() : array( $text );
		}
	}
	return array( 'after' => $after, 'review' => $notes['review'] );
};
if ( 'preview' === $mode ) {
	$products = $read( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID" );
	$ids = array_map( 'intval', array_column( $products, 'ID' ) );
	if ( isset( $args[2] ) ) {
		if ( ! preg_match( '/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/', $args[2] ) ) {
			WP_CLI::error( 'Preview IDs must be comma-separated positive integers.' );
		}
		$selected = array_values( array_unique( array_map( 'intval', explode( ',', $args[2] ) ) ) );
		if ( array_diff( $selected, $ids ) ) {
			WP_CLI::error( 'Selected products are missing or excluded.' );
		}
		$ids = $selected;
	}
	$baseline = $guard();
	$rows = $review = array();
	foreach ( $ids as $id ) {
		$before = $snapshot( $id );
		$result = $normalize( $before );
		if ( $result['review'] ) {
			$review[] = array( 'id' => $id, 'before' => $before, 'reasons' => $result['review'] );
		} elseif ( $before !== $result['after'] ) {
			$rows[] = array( 'id' => $id, 'before' => $before, 'after' => $result['after'] );
		}
	}
	if ( $baseline !== $guard() ) {
		WP_CLI::error( 'Catalog changed during preview; retry with a fresh plan.' );
	}
	$plan = array( 'version' => 1, 'site' => home_url(), 'guard' => $baseline, 'rows' => $rows, 'review' => $review );
	$json = wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$mask = umask( 0077 );
	try {
		$handle = fopen( $path, 'x' );
	} finally {
		umask( $mask );
	}
	if ( false === $handle ) {
		WP_CLI::error( 'Cannot create plan; existing plans must not be overwritten.' );
	}
	$written = fwrite( $handle, $json );
	fclose( $handle );
	if ( $written !== strlen( $json ) ) {
		WP_CLI::error( 'Incomplete plan write; do not apply.' );
	}
	WP_CLI::success( count( $rows ) . ' note-only changes; ' . count( $review ) . ' products excluded for review. Plan: ' . $path );
	return;
}
if ( ! is_file( $path ) || ( fileperms( $path ) & 0077 ) ) {
	WP_CLI::error( 'Plan must exist and be private (0600).' );
}
$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
if ( 1 !== ( $plan['version'] ?? null ) || home_url() !== ( $plan['site'] ?? null )
	|| ! is_array( $plan['rows'] ?? null ) || $guard() !== ( $plan['guard'] ?? null ) ) {
	WP_CLI::error( 'Plan site, version or catalog guard changed; no writes made.' );
}
$target = 'restore' === $mode ? 'before' : 'after';
$preflight = static function ( array $row ) use ( $snapshot, $normalize, $mode ): array {
	if ( ! is_int( $row['id'] ?? null ) || 'product' !== get_post_type( $row['id'] )
		|| $normalize( $row['before'] ) !== array( 'after' => $row['after'], 'review' => array() ) ) {
		WP_CLI::error( 'Invalid or outdated computed plan; no further writes.' );
	}
	$now = $snapshot( $row['id'] );
	foreach ( array( 'info', 'important' ) as $field ) {
		if ( 'verify' === $mode ? $now[ $field ] !== $row['after'][ $field ]
			: ( $now[ $field ] !== $row['before'][ $field ] && $now[ $field ] !== $row['after'][ $field ] ) ) {
			WP_CLI::error( 'Concurrent or unexpected note edit on product ' . $row['id'] . '; stopped.' );
		}
	}
	return $now;
};
foreach ( $plan['rows'] as $row ) {
	$preflight( $row );
}
if ( 'verify' === $mode ) {
	WP_CLI::success( 'Verified ' . count( $plan['rows'] ) . ' normalized products and unchanged names, descriptions, URLs, provenance and redirects.' );
	return;
}
foreach ( $plan['rows'] as $row ) {
	$now = $preflight( $row );
	foreach ( $keys as $field => $key ) {
		if ( $now[ $field ] === $row[ $target ][ $field ] ) {
			continue;
		}
		$values = $row[ $target ][ $field ];
		if ( ! $values ) {
			delete_post_meta( $row['id'], $key );
		} else {
			update_post_meta( $row['id'], $key, wp_slash( $values[0] ) );
		}
		if ( $snapshot( $row['id'] )[ $field ] !== $values ) {
			WP_CLI::error( 'Write verification failed on ' . $row['id'] . '; retain plan and use restore for scoped recovery.' );
		}
	}
}
if ( $guard() !== $plan['guard'] ) {
	WP_CLI::error( 'Unexpected non-note change; retain plan and investigate.' );
}
foreach ( $plan['rows'] as $row ) {
	if ( $snapshot( $row['id'] ) !== $row[ $target ] ) {
		WP_CLI::error( 'Final note readback failed for ' . $row['id'] );
	}
}
WP_CLI::success( ucfirst( $mode ) . ': ' . count( $plan['rows'] ) . ' products; only the two retailer-note keys changed.' );
