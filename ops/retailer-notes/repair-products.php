<?php
/**
 * Guarded follow-up migration. Run outside the webroot with WP-CLI:
 * wp eval-file /root/repair-products.php preview /root/repairs.json --allow-root
 * wp eval-file /root/repair-products.php apply /root/repairs.json --allow-root
 * Back up the database first. Slugs are changed separately by slug-preview / slug-apply.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run through wp eval-file.' );
}
$mode = $args[0] ?? '';
$path = $args[1] ?? '';
if ( ! in_array( $mode, array( 'preview', 'apply', 'verify' ), true ) || '/' !== substr( $path, 0, 1 ) ) {
	WP_CLI::error( 'Use preview|apply|verify and an absolute private plan path outside the webroot.' );
}
if ( ! is_dir( dirname( $path ) ) || str_starts_with( realpath( dirname( $path ) ) . '/', realpath( ABSPATH ) . '/' ) ) {
	WP_CLI::error( 'The plan directory must exist outside the webroot.' );
}
$snapshot = static function ( int $id ): array {
	return array(
		'title' => (string) get_post_field( 'post_title', $id, 'raw' ),
		'slug' => (string) get_post_field( 'post_name', $id, 'raw' ),
		'original' => (string) get_post_meta( $id, ULTRALOVE_RN_ORIGINAL, true ),
		'info' => (string) get_post_meta( $id, ULTRALOVE_RN_INFO, true ),
		'important' => (string) get_post_meta( $id, ULTRALOVE_RN_IMPORTANT, true ),
	);
};
$merge = static function ( array $have, array $add ): array {
	foreach ( $add as $note ) {
		if ( ! in_array( mb_strtoupper( $note ), array_map( 'mb_strtoupper', $have ), true ) ) {
			$have[] = $note;
		}
	}
	return $have;
};
if ( 'preview' === $mode ) {
	if ( file_exists( $path ) ) {
		WP_CLI::error( 'Refusing to overwrite an existing plan.' );
	}
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID" );
	$rows = $flagged = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		$before = $after = $snapshot( $id );
		$split = ultralove_rn_split_title( $before['title'] );
		if ( $split['malformed'] || $split['review'] || '' === $split['title'] || $split['other_tags'] ) {
			$flagged[] = $id;
			continue;
		}
		$notes = ultralove_rn_get( $id );
		$before_notes = $notes;
		if ( $split['changed'] ) {
			$after['title'] = $split['title'];
			$after['original'] = $before['original'] ?: $before['title'];
			foreach ( array( 'info', 'important' ) as $field ) {
				$notes[ $field ] = $merge( $notes[ $field ], $split[ $field ] );
			}
		}
		if ( '' !== $before['original'] ) {
			$original = ultralove_rn_split_title( $before['original'] );
			if ( ! $original['malformed'] && ! $original['review'] && ! $original['other_tags'] && '' !== $original['title'] ) {
				if ( 47748 === $id && $before['title'] === $original['title'] . '<' ) {
					$after['title'] = $original['title'];
				}
				$identities = array(
					24256 => array( 'KAM12009 Oil of Love .75 oz Display of 12', 'with Testers', 'with Testers' ),
					51896 => array( 'W600160 Womanizer Duo 2', 'Display For Tester', 'Display For Tester ONE PER STORE ONLY FREE WITH 2 UNITS BOUGHT' ),
				);
				if ( isset( $identities[ $id ] ) ) {
					list( $expected, $identity, $old_note ) = $identities[ $id ];
					$without_identity = ultralove_rn_clean_text( str_ireplace( $identity, '', $original['title'] ) );
					if ( ultralove_rn_clean_text( $before['title'] ) === $expected && $without_identity === $expected && in_array( $old_note, $notes['important'], true ) ) {
						$after['title'] = $original['title'];
						$notes['important'] = array_values( array_diff( $notes['important'], array( $old_note ) ) );
						$notes['important'] = $merge( $notes['important'], $original['important'] );
					}
				}
				// Restore only an identity whose exact removal explains the current name,
				// with a matching old note proven to occur in the saved supplier title.
				$original_text = ultralove_rn_clean_text( $before['original'] );
				$original_text = preg_replace( '/\bESTER(?=\s+(?:ONE|1)\b)/i', 'TESTER', $original_text );
				foreach ( array( 'info', 'important' ) as $field ) {
					foreach ( $notes[ $field ] as $key => $note ) {
						$tester = ultralove_rn_split_tester_identity( $note );
						if ( null === $tester || ! str_contains( $original_text, $note ) ) {
							continue;
						}
						$pattern = '/(?<![\pL\pN])' . preg_quote( $tester['identity'], '/' ) . '(?![\pL\pN])/u';
						$without_identity = preg_replace( $pattern, '', ultralove_rn_clean_text( $original['title'] ), -1, $count );
						if ( 1 !== $count || ultralove_rn_clean_text( $without_identity ) !== ultralove_rn_clean_text( $before['title'] ) ) {
							continue;
						}
						$after['title'] = $original['title'];
						unset( $notes[ $field ][ $key ] );
						if ( '' !== $tester['note'] ) {
							$notes['important'] = $merge( $notes['important'], array( $tester['note'] ) );
						}
					}
				}
				if ( $before['title'] === $after['title']
					&& preg_match( '/\bTESTERS?\b/i', $original_text )
					&& ultralove_rn_clean_text( $before['title'] ) !== ultralove_rn_clean_text( $original['title'] ) ) {
					$flagged[] = $id;
				}
				// Only move whole known offers also present in the saved supplier title.
				foreach ( $notes['info'] as $key => $note ) {
					if ( ultralove_rn_is_tester_offer( $after['title'], $note ) && in_array( mb_strtoupper( $note ), array_map( 'mb_strtoupper', $original['segments'] ), true ) ) {
						$notes['important'] = $merge( $notes['important'], array( $note ) );
						unset( $notes['info'][ $key ] );
					}
				}
			} elseif ( $original['review'] ) {
				$flagged[] = $id;
			}
		}
		foreach ( array( 'info', 'important' ) as $field ) {
			if ( $notes[ $field ] !== $before_notes[ $field ] ) {
				$after[ $field ] = implode( "\n", $notes[ $field ] );
			}
		}
		if ( $before !== $after ) {
			$rows[] = array( 'id' => $id, 'before' => $before, 'after' => $after );
		}
	}
	$plan = array( 'site' => home_url(), 'rows' => $rows, 'flagged' => $flagged );
	if ( false === file_put_contents( $path, wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), LOCK_EX ) || ! chmod( $path, 0600 ) ) {
		WP_CLI::error( 'Could not save private plan.' );
	}
	WP_CLI::success( count( $rows ) . ' planned repairs; ' . count( $flagged ) . ' unsafe or unmatched titles flagged for review. Review ' . $path );
	return;
}
$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
if ( $plan['site'] !== home_url() ) {
	WP_CLI::error( 'Plan belongs to a different site.' );
}
foreach ( $plan['rows'] as $row ) {
	$expected = 'verify' === $mode ? $row['after'] : $row['before'];
	if ( $snapshot( $row['id'] ) !== $expected ) {
		WP_CLI::error( 'Product ' . $row['id'] . ' differs from the plan; no further changes made.' );
	}
}
if ( 'verify' === $mode ) {
	WP_CLI::success( 'Verified every title, slug and note field for ' . count( $plan['rows'] ) . ' products.' );
	return;
}
foreach ( $plan['rows'] as $row ) {
	$id = $row['id'];
	if ( $snapshot( $id ) !== $row['before'] ) {
		WP_CLI::error( 'Concurrent edit on product ' . $id . '; stopped.' );
	}
	$product = wc_get_product( $id );
	if ( ! $product ) {
		WP_CLI::error( 'Product ' . $id . ' no longer exists.' );
	}
	$product->set_name( $row['after']['title'] );
	$product->save();
	foreach ( array( 'original' => ULTRALOVE_RN_ORIGINAL, 'info' => ULTRALOVE_RN_INFO, 'important' => ULTRALOVE_RN_IMPORTANT ) as $field => $key ) {
		if ( $row['before'][ $field ] !== $row['after'][ $field ] ) {
			update_post_meta( $id, $key, wp_slash( $row['after'][ $field ] ) );
		}
	}
	clean_post_cache( $id );
	if ( $snapshot( $id ) !== $row['after'] ) {
		WP_CLI::error( 'Verification failed for product ' . $id . '; stopped. Retain the plan for scoped recovery.' );
	}
	WP_CLI::log( 'Repaired ' . $id );
}
WP_CLI::success( 'Applied and verified ' . count( $plan['rows'] ) . ' repairs. Slugs were not changed.' );
