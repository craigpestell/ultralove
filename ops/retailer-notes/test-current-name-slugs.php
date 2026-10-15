<?php
/** Offline preview/apply/restore tests with realistic alias-producing update hooks. */
define( 'WP_CLI', true );
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_CLI {
	public static function error( string $message ): never { throw new RuntimeException( $message ); }
	public static function success( string $message ): void {}
}
class Red_Item {}
class Red_Module {
	public static function flush( int $group ): void { ++$GLOBALS['flushes']; }
}
function home_url(): string { return 'https://dev.example.test'; }
function wp_json_encode( ...$args ) { return json_encode( ...$args ); }
function wp_parse_url( ...$args ) { return parse_url( ...$args ); }
function sanitize_title( string $title ): string {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
}
function wp_unique_post_slug( string $slug, int $id, ...$args ): string {
	foreach ( $GLOBALS['state']['products'] as $post ) {
		if ( (int) $post['ID'] !== $id && $post['post_name'] === $slug ) {
			return $slug . '-2';
		}
	}
	return $GLOBALS['collision'] ? $slug . '-2' : $slug;
}
function get_post( int $id ): ?object {
	foreach ( $GLOBALS['state']['products'] as $post ) {
		if ( (int) $post['ID'] === $id ) {
			return (object) $post;
		}
	}
	return null;
}
function get_post_field( string $field, int $id, string $context ): string { return get_post( $id )->$field; }
function get_permalink( int $id ): string { return home_url() . '/product/' . get_post( $id )->post_name . '/'; }
function clean_post_cache( int $id ): void {}
function is_wp_error( $result ): bool { return false; }
function has_action( ...$args ) { return $GLOBALS['old_slug_hook']; }
function remove_action( ...$args ): void { $GLOBALS['old_slug_hook'] = false; }
function add_action( string $hook, string $callback, int $priority, int $argc ): void { $GLOBALS['old_slug_hook'] = $priority; }
function add_filter( string $hook, $callback, int $priority ): void { $GLOBALS['no_redirect'] = $callback; }
function remove_filter( ...$args ): void { $GLOBALS['no_redirect'] = null; }
function wp_update_post( array $data, bool $error ): int {
	++$GLOBALS['writes'];
	if ( $GLOBALS['fail_product'] === $data['ID'] ) {
		throw new RuntimeException( 'Injected product write failure' );
	}
	if ( false !== $GLOBALS['old_slug_hook'] || ! is_callable( $GLOBALS['no_redirect'] ) || false !== ( $GLOBALS['no_redirect'] )() ) {
		throw new RuntimeException( 'Automatic intermediate aliases were not suppressed.' );
	}
	foreach ( $GLOBALS['state']['products'] as &$post ) {
		if ( (int) $post['ID'] === $data['ID'] ) {
			$post['post_name'] = $data['post_name'];
		}
	}
	unset( $post );
	foreach ( $GLOBALS['state']['slugs'] as &$post ) {
		if ( (int) $post['ID'] === $data['ID'] ) {
			$post['post_name'] = $data['post_name'];
		}
	}
	unset( $post );
	return $data['ID'];
}
require __DIR__ . '/../../wp-content/mu-plugins/ultralove-retailer-notes/parser.php';
$wpdb = new class {
	public string $posts = 'posts';
	public string $postmeta = 'postmeta';
	public string $prefix = 'wp_';
	public string $last_error = '';
	public function get_results( string $sql, string $format ): array {
		if ( str_contains( $sql, 'redirection_items' ) ) {
			return $GLOBALS['state']['redirects'];
		}
		if ( str_contains( $sql, "meta_key IN ('_wp_old_slug'" ) ) {
			return $GLOBALS['state']['aliases'];
		}
		if ( str_contains( $sql, 'FROM postmeta' ) ) {
			return $GLOBALS['state']['notes'];
		}
		if ( str_contains( $sql, 'SELECT ID,post_name' ) ) {
			return $GLOBALS['state']['slugs'];
		}
		return $GLOBALS['state']['products'];
	}
	public function update( string $table, array $values, array $where ): int {
		if ( $GLOBALS['fail_redirect'] === (int) $where['id'] ) {
			return 0;
		}
		foreach ( $GLOBALS['state']['redirects'] as &$rule ) {
			if ( ! array_diff_assoc( $where, $rule ) ) {
				$rule = array_replace( $rule, $values );
				return 1;
			}
		}
		return 0;
	}
};
$state = array( 'slugs' => array(), 'redirects' => array(), 'aliases' => array( array( 'post_id' => '1', 'meta_value' => 'original-retailer-info' ) ), 'products' => array(), 'notes' => array( array( 'meta_value' => 'Retain important note' ) ) );
foreach ( array( 'A0339T<br>Lelo Ina Thrust<br>Purple TESTER', 'W637718<br/>Womanizer Liberty Leopard Tester', 'SKU<br>Display For Tester', 'R3271 Romp Pink Peach TESTER' ) as $index => $title ) {
	$id = $index + 1;
	$old = array( 'a0339t-lelo-ina-thrust-purple', 'w637718-womanizer-liberty', 'sku-display', 'r3271-romp-pink-peach' )[ $index ];
	$state['products'][] = array( 'ID' => (string) $id, 'post_title' => $title, 'post_name' => $old, 'post_content' => '<p>Unchanged</p>', 'post_excerpt' => 'Unchanged', 'post_type' => 'product', 'post_status' => 'publish', 'post_parent' => '0' );
	$state['slugs'][] = array( 'ID' => (string) $id, 'post_name' => $old );
	if ( $id < 4 ) {
		$state['redirects'][] = array( 'id' => (string) $id, 'url' => '/product/' . $old . '-retailer-info/', 'action_data' => '/product/' . $old . '/', 'regex' => '0', 'match_type' => 'url', 'action_type' => 'url', 'action_code' => '301', 'status' => 'enabled', 'group_id' => '1', 'match_data' => '{"source":{"flag_query":"exact"}}', 'position' => '42' );
	}
}
$state['redirects'][2]['url'] = '/product/sku-display-for-tester/';
$initial = $state;
$collision = false;
$writes = $flushes = 0;
$old_slug_hook = 12;
$no_redirect = null;
$fail_product = $fail_redirect = 0;
$dir = sys_get_temp_dir() . '/ul-name-slugs-' . bin2hex( random_bytes( 5 ) );
if ( ! mkdir( $dir, 0700 ) ) {
	throw new RuntimeException( 'Cannot create private test directory.' );
}
$scope = $dir . '/ids.json';
file_put_contents( $scope, '[1,2,3,4]' );
$run = static function ( string $mode, string $path ) use ( $scope ): void {
	$args = array( $mode, $path, $scope );
	require __DIR__ . '/current-name-slugs.php';
};
$expect_error = static function ( callable $fn, string $text ): void {
	try {
		$fn();
	} catch ( RuntimeException $error ) {
		if ( str_contains( $error->getMessage(), $text ) ) {
			return;
		}
		throw $error;
	}
	throw new RuntimeException( 'Expected rejection: ' . $text );
};
try {
	$path = $dir . '/plan.json';
	$run( 'preview', $path );
	$plan = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( $plan['rows'][0]['new'] !== 'a0339t-lelo-ina-thrust-purple-tester'
		|| $plan['rows'][1]['new'] !== 'w637718-womanizer-liberty-leopard-tester'
		|| count( $plan['rows'] ) !== 4 || count( $plan['redirects'] ) !== 3
		|| $state !== $initial || ( fileperms( $path ) & 0777 ) !== 0600 ) {
		throw new RuntimeException( 'Preview did not preserve name, scope or privacy.' );
	}
	$state['notes'][0]['meta_value'] = 'Concurrent edit';
	$expect_error( static fn() => $run( 'apply', $path ), 'State differs' );
	$state = $initial;
	$collision = true;
	$expect_error( static fn() => $run( 'apply', $path ), 'Slug collision' );
	$expect_error( static fn() => $run( 'preview', $dir . '/collision.json' ), 'colliding' );
	$collision = false;
	if ( $writes ) {
		throw new RuntimeException( 'Preflight rejection wrote data.' );
	}
	$run( 'apply', $path );
	$run( 'verify', $path );
	$run( 'apply', $path );
	if ( $writes !== 4 || $flushes !== 3 || $old_slug_hook !== 12 || null !== $no_redirect
		|| 'disabled' !== $state['redirects'][2]['status']
		|| '/product/w637718-womanizer-liberty-leopard-tester/' !== $state['redirects'][1]['action_data']
		|| $state['aliases'] !== $initial['aliases'] || $state['notes'] !== $initial['notes'] ) {
		throw new RuntimeException( 'Apply/idempotence/alias preservation failed.' );
	}
	$run( 'preview', $dir . '/repeat.json' );
	$repeat = json_decode( file_get_contents( $dir . '/repeat.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( $repeat['rows'] || $repeat['redirects'] ) {
		throw new RuntimeException( 'Repeat preview was not empty.' );
	}
	$run( 'restore', $path );
	$run( 'restore', $path );
	if ( $state !== $initial ) {
		throw new RuntimeException( 'Scoped restoration failed.' );
	}
	$fail_product = 2;
	$expect_error( static fn() => $run( 'apply', $path ), 'Injected product write failure' );
	$fail_product = 0;
	$expect_error( static fn() => $run( 'verify', $path ), 'State differs' );
	if ( $old_slug_hook !== 12 || null !== $no_redirect ) {
		throw new RuntimeException( 'Failure did not restore automatic hooks.' );
	}
	$run( 'restore', $path );
	if ( $state !== $initial ) {
		throw new RuntimeException( 'Partial product apply did not restore exactly.' );
	}
	$fail_redirect = 2;
	$expect_error( static fn() => $run( 'apply', $path ), 'Redirect write failed' );
	$fail_redirect = 0;
	$partial = $state;
	$state['redirects'][0]['position'] = '99';
	$writes_before = $writes;
	$expect_error( static fn() => $run( 'restore', $path ), 'State differs' );
	if ( $writes !== $writes_before ) {
		throw new RuntimeException( 'Partial-state preflight wrote data on an unrelated edit.' );
	}
	$state = $partial;
	$run( 'apply', $path );
	$run( 'verify', $path );
	$fail_product = 2;
	$expect_error( static fn() => $run( 'restore', $path ), 'Injected product write failure' );
	$fail_product = 0;
	$run( 'restore', $path );
	if ( $state !== $initial ) {
		throw new RuntimeException( 'Interrupted restore did not recover exactly.' );
	}
	$fail_redirect = 2;
	$expect_error( static fn() => $run( 'apply', $path ), 'Redirect write failed' );
	$fail_redirect = 0;
	$run( 'restore', $path );
	if ( $state !== $initial ) {
		throw new RuntimeException( 'Partial redirect apply did not restore exactly.' );
	}
	$state['redirects'][0]['url'] = '/product/a0339t-lelo-ina-thrust-purple-tester/';
	$state['redirects'][0]['action_data'] = '/somewhere-else/';
	$expect_error( static fn() => $run( 'preview', $dir . '/conflict.json' ), 'conflicting redirect' );
	$state = $initial;
	$state['redirects'][0]['url'] = '/product/a0339t-lelo-ina-thrust-purple/';
	$expect_error( static fn() => $run( 'preview', $dir . '/intermediate.json' ), 'intermediate URL' );
	echo "PASS: name-derived URLs, markup, collisions, stale state, direct redirects, self-rule disabling, no new aliases, interrupted apply/restore recovery, exact restore and idempotence.\n";
} finally {
	foreach ( array( 'ids.json', 'plan.json', 'repeat.json', 'collision.json', 'conflict.json', 'intermediate.json' ) as $file ) {
		if ( file_exists( $dir . '/' . $file ) ) {
			unlink( $dir . '/' . $file );
		}
	}
	rmdir( $dir );
}
