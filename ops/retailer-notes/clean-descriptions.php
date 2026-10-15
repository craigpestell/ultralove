<?php
/**
 * Run outside the webroot via wp eval-file:
 * clean-descriptions.php preview|apply|verify|restore /root/private-plan.json [comma-separated IDs]
 * Back up the database and review the exact before/after plan before apply.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through wp eval-file.' );
}
$mode = $args[0] ?? '';
$path = $args[1] ?? '';
if ( ! in_array( $mode, array( 'preview', 'apply', 'verify', 'restore' ), true )
	|| '/' !== substr( $path, 0, 1 ) || ! is_dir( dirname( $path ) )
	|| str_starts_with( realpath( dirname( $path ) ) . '/', realpath( ABSPATH ) . '/' )
	|| is_link( $path ) ) {
	WP_CLI::error( 'Use preview|apply|verify|restore and a private absolute path outside the webroot.' );
}
$version = static fn( int $id ): string => (string) get_post_meta( $id, ULTRALOVE_DESCRIPTION_CLEANUP_META, true );
if ( 'preview' === $mode ) {
	if ( file_exists( $path ) ) {
		WP_CLI::error( 'Refusing to overwrite a plan.' );
	}
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID" );
	if ( isset( $args[2] ) ) {
		if ( ! preg_match( '/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/', $args[2] ) ) {
			WP_CLI::error( 'IDs must be positive, comma-separated integers.' );
		}
		$selected = array_map( 'intval', explode( ',', $args[2] ) );
		if ( array_diff( $selected, array_map( 'intval', $ids ) ) ) {
			WP_CLI::error( 'Selection includes missing or excluded products.' );
		}
		$ids = $selected;
	}
	$rows = $review = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		$before = ultralove_description_snapshot( $id );
		$after = ultralove_rn_description_after( $before, $reasons );
		if ( $after !== $before ) {
			$rows[] = array(
				'id' => $id,
				'title' => (string) get_post_field( 'post_title', $id, 'raw' ),
				'slug' => (string) get_post_field( 'post_name', $id, 'raw' ),
				'before_version' => $version( $id ),
				'before' => $before,
				'after' => $after,
			);
		}
		if ( $reasons ) {
			$review[] = array( 'id' => $id, 'reasons' => $reasons );
		}
	}
	$plan = array( 'site' => home_url(), 'version' => ULTRALOVE_DESCRIPTION_CLEANUP_VERSION, 'rows' => $rows, 'review' => $review );
	$old_mask = umask( 0077 );
	$handle = fopen( $path, 'x' );
	umask( $old_mask );
	if ( false === $handle ) {
		WP_CLI::error( 'Cannot create private plan.' );
	}
	$json = wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$written = fwrite( $handle, $json );
	fclose( $handle );
	if ( strlen( $json ) !== $written ) {
		WP_CLI::error( 'Incomplete plan write; do not apply.' );
	}
	WP_CLI::success( count( $rows ) . ' planned products; ' . count( $review ) . ' fields flagged for review.' );
	return;
}
if ( ! is_file( $path ) ) {
	WP_CLI::error( 'Plan does not exist.' );
}
$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
if ( ( $plan['site'] ?? '' ) !== home_url() || ( $plan['version'] ?? '' ) !== ULTRALOVE_DESCRIPTION_CLEANUP_VERSION || ! is_array( $plan['rows'] ?? null ) ) {
	WP_CLI::error( 'Plan site, version or rows are invalid.' );
}
$expected = 'apply' === $mode ? 'before' : 'after';
foreach ( $plan['rows'] as $row ) {
	$id = (int) $row['id'];
	$expected_version = 'apply' === $mode ? $row['before_version'] : ULTRALOVE_DESCRIPTION_CLEANUP_VERSION;
	if ( 'product' !== get_post_type( $id ) || ultralove_description_snapshot( $id ) !== $row[ $expected ]
		|| $version( $id ) !== $expected_version
		|| get_post_field( 'post_title', $id, 'raw' ) !== $row['title']
		|| get_post_field( 'post_name', $id, 'raw' ) !== $row['slug']
		|| ultralove_rn_description_after( $row['before'] ) !== $row['after'] ) {
		WP_CLI::error( 'Stale or modified plan for product ' . $id . '; stopped before writes.' );
	}
}
if ( 'verify' === $mode ) {
	WP_CLI::success( 'Verified descriptions, notes, import opt-in, titles and slugs for ' . count( $plan['rows'] ) . ' products.' );
	return;
}
foreach ( $plan['rows'] as $row ) {
	$id = (int) $row['id'];
	$after = 'apply' === $mode ? $row['after'] : $row['before'];
	ultralove_description_write( $id, $row[ $expected ], $after );
	$new_version = 'apply' === $mode ? ULTRALOVE_DESCRIPTION_CLEANUP_VERSION : $row['before_version'];
	if ( '' === $new_version ) {
		delete_post_meta( $id, ULTRALOVE_DESCRIPTION_CLEANUP_META );
	} else {
		update_post_meta( $id, ULTRALOVE_DESCRIPTION_CLEANUP_META, $new_version );
	}
	if ( $version( $id ) !== $new_version ) {
		WP_CLI::error( 'Import opt-in verification failed for product ' . $id . '; stopped.' );
	}
	WP_CLI::log( $mode . ': ' . $id );
}
WP_CLI::success( ucfirst( $mode ) . ': ' . count( $plan['rows'] ) . ' products. Names and URLs unchanged.' );
