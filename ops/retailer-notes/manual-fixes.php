<?php
/**
 * One-off fixes for the four products the notes migration leaves for review.
 * wp eval-file /private/manual-fixes.php preview|apply|restore /private/manual-fixes-backup.json --allow-root
 * Each product is only changed when its current notes still match the expected "before" text.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through wp eval-file.' );
}
$mode = $args[0] ?? '';
$path = $args[1] ?? '';
if ( ! in_array( $mode, array( 'preview', 'apply', 'restore' ), true ) || '/' !== substr( $path, 0, 1 ) ) {
	WP_CLI::error( 'Use preview|apply|restore and an absolute backup path outside the webroot.' );
}
$tester = "LIMIT 1 PER STORE\nFREE WITH TESTER & 3 UNITS BOUGHT";
$fixes  = array(
	27463 => array( 'before' => array( '', 'ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT' ), 'after' => array( '', $tester ) ),
	30692 => array( 'before' => array( '', 'ONE PER STORE ONLY FREE WITH TESTER & 3 UNITS BOUGHT' ), 'after' => array( '', $tester ) ),
	59459 => array(
		'before' => array( 'DISPLAY KIT ONLY ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT', 'LIMIT 1 PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ),
		'after'  => array( '', "DISPLAY KIT ONLY\nLIMIT 1 PER STORE\nFREE WITH 2 UNITS BOUGHT" ),
	),
);
$current = static fn( int $id ): array => array(
	(string) get_post_meta( $id, ULTRALOVE_RN_INFO, true ),
	(string) get_post_meta( $id, ULTRALOVE_RN_IMPORTANT, true ),
);
$norm = static fn( array $pair ): array => array_map( static fn( $v ) => trim( (string) preg_replace( '/\s+/', ' ', is_array( $v ) ? implode( ' ', $v ) : $v ) ), $pair );
if ( 'restore' === $mode ) {
	foreach ( json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) as $id => $pair ) {
		update_post_meta( (int) $id, ULTRALOVE_RN_INFO, $pair[0] );
		update_post_meta( (int) $id, ULTRALOVE_RN_IMPORTANT, $pair[1] );
	}
	WP_CLI::success( 'Restored from ' . $path );
	return;
}
$backup = array();
foreach ( $fixes as $id => $fix ) {
	$now = $current( $id );
	if ( $norm( $now ) !== $norm( $fix['before'] ) ) {
		WP_CLI::warning( "$id: notes differ from expected, skipped. Current: " . wp_json_encode( $now ) );
		continue;
	}
	$backup[ $id ] = $now;
	WP_CLI::log( "$id: " . wp_json_encode( $now ) . ' -> ' . wp_json_encode( $fix['after'] ) );
	if ( 'apply' === $mode ) {
		update_post_meta( $id, ULTRALOVE_RN_INFO, $fix['after'][0] );
		update_post_meta( $id, ULTRALOVE_RN_IMPORTANT, $fix['after'][1] );
	}
}
if ( 'apply' === $mode && $backup ) {
	file_put_contents( $path, wp_json_encode( $backup ) );
	chmod( $path, 0600 );
}
WP_CLI::success( ucfirst( $mode ) . ': ' . count( $backup ) . ' of ' . count( $fixes ) . ' products' . ( 'apply' === $mode ? ' updated; backup at ' . $path : ' would change.' ) );
