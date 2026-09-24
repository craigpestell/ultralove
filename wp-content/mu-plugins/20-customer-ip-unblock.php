<?php
/**
 * Plugin Name: Ultralove Customer IP Unblock
 * Description: Logged-in page for users to view their IP and add it to the API machine allow list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ULTRALOVE_CF_CUSTOMER_LIST_NAME' ) ) {
	define( 'ULTRALOVE_CF_CUSTOMER_LIST_NAME', 'customers_ip' );
}

/**
 * Resolve the current visitor IP, preferring Cloudflare header when present.
 *
 * @return string
 */
function ultralove_cipu_get_current_ip() {
	$candidates = array();

	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	}

	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$forwarded_for = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$parts         = array_map( 'trim', explode( ',', $forwarded_for ) );
		foreach ( $parts as $part ) {
			if ( '' !== $part ) {
				$candidates[] = $part;
			}
		}
	}

	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	foreach ( $candidates as $candidate ) {
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Get Cloudflare account ID and API token from constants/options.
 *
 * @return array|WP_Error
 */
function ultralove_cipu_get_cloudflare_credentials() {
	$account_id = '';
	$api_token  = '';

	if ( defined( 'ULTRALOVE_CLOUDFLARE_ACCOUNT_ID' ) ) {
		$account_id = ULTRALOVE_CLOUDFLARE_ACCOUNT_ID;
	} elseif ( defined( 'CLOUDFLARE_ACCOUNT_ID' ) ) {
		$account_id = CLOUDFLARE_ACCOUNT_ID;
	} elseif ( getenv( 'ULTRALOVE_CLOUDFLARE_ACCOUNT_ID' ) ) {
		$account_id = (string) getenv( 'ULTRALOVE_CLOUDFLARE_ACCOUNT_ID' );
	} else {
		$account_id = get_option( 'ultralove_cloudflare_account_id', '' );
	}

	if ( defined( 'ULTRALOVE_CLOUDFLARE_API_TOKEN' ) ) {
		$api_token = ULTRALOVE_CLOUDFLARE_API_TOKEN;
	} elseif ( defined( 'CLOUDFLARE_API_TOKEN' ) ) {
		$api_token = CLOUDFLARE_API_TOKEN;
	} elseif ( getenv( 'ULTRALOVE_CLOUDFLARE_API_TOKEN' ) ) {
		$api_token = (string) getenv( 'ULTRALOVE_CLOUDFLARE_API_TOKEN' );
	} else {
		$api_token = get_option( 'ultralove_cloudflare_api_token', '' );
	}

	$account_id = trim( (string) apply_filters( 'ultralove_cipu_cloudflare_account_id', $account_id ) );
	$api_token  = trim( (string) apply_filters( 'ultralove_cipu_cloudflare_api_token', $api_token ) );

	if ( '' === $account_id || '' === $api_token ) {
		return new WP_Error(
			'ultralove_cipu_missing_cloudflare_credentials',
			'Allow list credentials are not configured. Set account ID and API token constants or options.'
		);
	}

	return array(
		'account_id' => $account_id,
		'api_token'  => $api_token,
	);
}

/**
 * Perform a Cloudflare API request.
 *
 * @param string $method HTTP method.
 * @param string $path API path after base URL.
 * @param array  $credentials Cloudflare credentials.
 * @param array|null $body Optional request body.
 *
 * @return array|WP_Error
 */
function ultralove_cipu_cloudflare_request( $method, $path, $credentials, $body = null ) {
	$url = 'https://api.cloudflare.com/client/v4' . $path;

	$args = array(
		'method'  => strtoupper( $method ),
		'headers' => array(
			'Authorization' => 'Bearer ' . $credentials['api_token'],
			'Content-Type'  => 'application/json',
		),
		'timeout' => 20,
	);

	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code      = (int) wp_remote_retrieve_response_code( $response );
	$raw_body  = (string) wp_remote_retrieve_body( $response );
	$decoded   = json_decode( $raw_body, true );
	$is_json   = is_array( $decoded );

	if ( $code < 200 || $code >= 300 || ! $is_json ) {
		return new WP_Error(
			'ultralove_cipu_cloudflare_request_failed',
			'Allow list API request failed.',
			array(
				'http_code' => $code,
				'body'      => $raw_body,
			)
		);
	}

	return $decoded;
}

/**
 * Find and cache the Cloudflare list ID by list name.
 *
 * @param array $credentials Cloudflare credentials.
 *
 * @return string|WP_Error
 */
function ultralove_cipu_get_customer_list_id( $credentials ) {
	$cache_key = 'ultralove_cipu_cf_list_id_' . md5( $credentials['account_id'] . '|' . ULTRALOVE_CF_CUSTOMER_LIST_NAME );
	$cached    = get_transient( $cache_key );
	if ( is_string( $cached ) && '' !== $cached ) {
		return $cached;
	}

	$path     = '/accounts/' . rawurlencode( $credentials['account_id'] ) . '/rules/lists';
	$response = ultralove_cipu_cloudflare_request( 'GET', $path, $credentials );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! empty( $response['success'] ) && ! empty( $response['result'] ) && is_array( $response['result'] ) ) {
		foreach ( $response['result'] as $list ) {
			if ( ! empty( $list['name'] ) && ULTRALOVE_CF_CUSTOMER_LIST_NAME === $list['name'] && ! empty( $list['id'] ) ) {
				$list_id = (string) $list['id'];
				set_transient( $cache_key, $list_id, 10 * MINUTE_IN_SECONDS );
				return $list_id;
			}
		}
	}

	return new WP_Error(
		'ultralove_cipu_list_not_found',
		'Configured allow list "' . ULTRALOVE_CF_CUSTOMER_LIST_NAME . '" was not found in this account.'
	);
}

/**
 * Add an IP address to Cloudflare list customers_ip.
 *
 * @param string $ip The IP to add.
 *
 * @return array|WP_Error
 */
function ultralove_cipu_add_ip_to_cloudflare_list( $ip ) {
	$credentials = ultralove_cipu_get_cloudflare_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$list_id = ultralove_cipu_get_customer_list_id( $credentials );
	if ( is_wp_error( $list_id ) ) {
		return $list_id;
	}

	$path = '/accounts/' . rawurlencode( $credentials['account_id'] ) . '/rules/lists/' . rawurlencode( $list_id ) . '/items';
	$current_user = wp_get_current_user();
	$user_label   = '';
	if ( $current_user instanceof WP_User && ! empty( $current_user->user_email ) ) {
		$user_label = sanitize_email( (string) $current_user->user_email );
	}
	if ( '' === $user_label ) {
		$user_label = 'user-id:' . get_current_user_id();
	}
	$body = array(
		array(
			'ip'      => $ip,
			'comment' => 'Self-unblock for API product list machine by ' . $user_label . ' at ' . gmdate( 'c' ),
		),
	);

	$response = ultralove_cipu_cloudflare_request( 'POST', $path, $credentials, $body );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! empty( $response['success'] ) ) {
		return array(
			'ok'      => true,
			'message' => 'This machine IP was added to the allow list successfully.',
			'raw'     => $response,
		);
	}

	$errors  = ! empty( $response['errors'] ) && is_array( $response['errors'] ) ? $response['errors'] : array();
	$message = '';
	foreach ( $errors as $error ) {
		if ( ! empty( $error['message'] ) ) {
			$message .= ' ' . $error['message'];
		}
	}
	$message = trim( $message );

	if ( '' !== $message && false !== stripos( $message, 'already exists' ) ) {
		return array(
			'ok'      => true,
			'message' => 'This machine IP is already in the allow list.',
			'raw'     => $response,
		);
	}

	return new WP_Error(
		'ultralove_cipu_add_failed',
		'' !== $message ? $message : 'Could not add the IP to Cloudflare list.'
	);
}

/**
 * Check whether an IP already exists in Cloudflare list customers_ip.
 *
 * @param string $ip The IP to check.
 *
 * @return bool|WP_Error
 */
function ultralove_cipu_is_ip_in_customer_list( $ip ) {
	if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return new WP_Error( 'ultralove_cipu_invalid_ip', 'Invalid IP address.' );
	}

	$credentials = ultralove_cipu_get_cloudflare_credentials();
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$list_id = ultralove_cipu_get_customer_list_id( $credentials );
	if ( is_wp_error( $list_id ) ) {
		return $list_id;
	}

	$encoded_account_id = rawurlencode( $credentials['account_id'] );
	$encoded_list_id    = rawurlencode( $list_id );
	$cursor             = '';
	$page               = 1;
	$max_requests       = 50;

	for ( $request_count = 0; $request_count < $max_requests; $request_count++ ) {
		$path = '/accounts/' . $encoded_account_id . '/rules/lists/' . $encoded_list_id . '/items?per_page=500';
		if ( '' !== $cursor ) {
			$path .= '&cursor=' . rawurlencode( $cursor );
		} else {
			$path .= '&page=' . (int) $page;
		}

		$response = ultralove_cipu_cloudflare_request( 'GET', $path, $credentials );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = ! empty( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
		foreach ( $items as $item ) {
			$item_ip = '';
			if ( ! empty( $item['ip'] ) ) {
				$item_ip = (string) $item['ip'];
			} elseif ( ! empty( $item['value'] ) ) {
				$item_ip = (string) $item['value'];
			}

			if ( '' !== $item_ip && 0 === strcasecmp( $item_ip, $ip ) ) {
				return true;
			}
		}

		$result_info = ! empty( $response['result_info'] ) && is_array( $response['result_info'] ) ? $response['result_info'] : array();
		if ( ! empty( $result_info['cursor'] ) && is_string( $result_info['cursor'] ) ) {
			$cursor = $result_info['cursor'];
			continue;
		}

		if ( ! empty( $result_info['total_pages'] ) && isset( $result_info['page'] ) ) {
			$current_page = (int) $result_info['page'];
			$total_pages  = (int) $result_info['total_pages'];
			if ( $current_page < $total_pages ) {
				$page = $current_page + 1;
				continue;
			}
		}

		break;
	}

	return false;
}

/**
 * Register REST API routes used by the unblock page.
 */
function ultralove_cipu_register_rest_routes() {
	register_rest_route(
		'ultralove/v1',
		'/current-ip',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'callback'            => function () {
				$ip = ultralove_cipu_get_current_ip();
				if ( '' === $ip ) {
					return new WP_Error( 'ultralove_cipu_ip_not_detected', 'Unable to detect your current IP address.', array( 'status' => 500 ) );
				}

				$already_listed  = null;
				$list_check_error = '';
				$list_check       = ultralove_cipu_is_ip_in_customer_list( $ip );
				if ( is_wp_error( $list_check ) ) {
					$list_check_error = $list_check->get_error_message();
				} else {
					$already_listed = (bool) $list_check;
				}

				return rest_ensure_response(
					array(
						'ip'               => $ip,
						'already_listed'   => $already_listed,
						'list_check_error' => $list_check_error,
					)
				);
			},
		)
	);

	register_rest_route(
		'ultralove/v1',
		'/unblock-ip',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$ip = $request->get_param( 'ip' );
				$ip = is_string( $ip ) ? trim( $ip ) : '';
				if ( '' === $ip ) {
					$ip = ultralove_cipu_get_current_ip();
				}

				if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return new WP_Error( 'ultralove_cipu_invalid_ip', 'Invalid or missing IP address.', array( 'status' => 400 ) );
				}

				$result = ultralove_cipu_add_ip_to_cloudflare_list( $ip );
				if ( is_wp_error( $result ) ) {
					$status = 500;
					if ( 'ultralove_cipu_missing_cloudflare_credentials' === $result->get_error_code() ) {
						$status = 500;
					} elseif ( 'ultralove_cipu_list_not_found' === $result->get_error_code() ) {
						$status = 404;
					}
					return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
				}

				return rest_ensure_response(
					array(
						'ip'             => $ip,
						'message'        => $result['message'],
						'already_listed' => true,
					)
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'ultralove_cipu_register_rest_routes' );

/**
 * Ensure a public page exists for logged-in users.
 */
function ultralove_cipu_ensure_page_exists() {
	$page_id = (int) get_option( 'ultralove_cipu_page_id', 0 );

	if ( $page_id > 0 ) {
		$page = get_post( $page_id );
		if ( $page instanceof WP_Post && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
			return;
		}
	}

	$existing = get_page_by_path( 'unblock-my-ip' );
	if ( $existing instanceof WP_Post ) {
		update_option( 'ultralove_cipu_page_id', (int) $existing->ID, false );
		return;
	}

	$new_page_id = wp_insert_post(
		array(
			'post_title'   => 'Unblock My IP',
			'post_name'    => 'unblock-my-ip',
			'post_content' => '[ultralove_customer_ip_unblock]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
		true
	);

	if ( ! is_wp_error( $new_page_id ) && $new_page_id > 0 ) {
		update_option( 'ultralove_cipu_page_id', (int) $new_page_id, false );
	}
}
add_action( 'init', 'ultralove_cipu_ensure_page_exists', 20 );

/**
 * Render page UI via shortcode.
 *
 * @return string
 */
function ultralove_cipu_render_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>You must be logged in to use this page.</p>';
	}

	$rest_base = esc_url( rest_url( 'ultralove/v1' ) );
	$nonce     = wp_create_nonce( 'wp_rest' );

	wp_register_script( 'ultralove-cipu-frontend', false, array(), '1.0.0', true );
	wp_enqueue_script( 'ultralove-cipu-frontend' );

	$inline_js = <<<'JS'
(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    var root = document.getElementById('ultralove-cipu-app');
    if (!root) {
      return;
    }

    var restBase = root.getAttribute('data-rest-base');
    var nonce = root.getAttribute('data-nonce');
    var ipText = root.querySelector('[data-role="ip"]');
    var statusText = root.querySelector('[data-role="status"]');
    var button = root.querySelector('[data-role="unblock-btn"]');

		function setButtonState(disabled, label) {
			button.disabled = !!disabled;
			if (label) {
				button.textContent = label;
			}
			button.style.opacity = disabled ? '0.65' : '1';
			button.style.cursor = disabled ? 'not-allowed' : 'pointer';
		}

    function setStatus(msg, isError) {
      statusText.textContent = msg;
      statusText.style.color = isError ? '#b42318' : '#115e59';
    }

    function loadIp() {
			setButtonState(true, 'Checking machine status...');
      setStatus('Detecting your IP address...', false);
      fetch(restBase + '/current-ip', {
        method: 'GET',
        headers: {
          'X-WP-Nonce': nonce
        },
        credentials: 'same-origin'
      })
        .then(function (res) {
          return res.json().then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (result) {
          if (!result.ok) {
            throw new Error((result.data && result.data.message) || 'Failed to load IP address.');
          }

          ipText.textContent = result.data.ip || 'Unknown';

					if (result.data && result.data.already_listed === true) {
						setButtonState(true, 'Already Allowed');
						setStatus('This machine IP is already in the allow list for product-list API calls.', false);
						return;
					}

					setButtonState(false, 'Unblock this machine');
					if (result.data && result.data.list_check_error) {
						setStatus('IP loaded, but list status could not be verified yet.', false);
					} else {
						setStatus('This machine can be unblocked for product-list API calls.', false);
					}
        })
        .catch(function (err) {
          ipText.textContent = 'Unavailable';
					setButtonState(true, 'Unblock this machine');
          setStatus(err.message, true);
        });
    }

    button.addEventListener('click', function () {
      button.disabled = true;
	  setStatus('Adding this machine IP to the allow list...', false);

      fetch(restBase + '/unblock-ip', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        credentials: 'same-origin',
				body: JSON.stringify({})
      })
        .then(function (res) {
          return res.json().then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (result) {
          if (!result.ok) {
			throw new Error((result.data && result.data.message) || 'Allow list request failed.');
          }

					if (result.data && result.data.already_listed === true) {
			setButtonState(true, 'Already Allowed');
					}
          setStatus(result.data.message || 'IP added successfully.', false);
        })
        .catch(function (err) {
          setStatus(err.message, true);
        })
        .finally(function () {
		  if (button.textContent !== 'Already Allowed') {
			setButtonState(false, 'Unblock this machine');
					}
        });
    });

    loadIp();
  });
})();
JS;

	wp_add_inline_script( 'ultralove-cipu-frontend', $inline_js );

	ob_start();
	?>
	<div id="ultralove-cipu-app" data-rest-base="<?php echo esc_attr( $rest_base ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="max-width:700px;padding:24px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;">
		<h2 style="margin-top:0;">Unblock API Machine</h2>
		<p style="margin-bottom:8px;">Use this for machines that make API calls to fetch product lists.</p>
		<p style="margin-bottom:8px;">Current detected IP:</p>
		<p style="font-size:24px;font-weight:700;margin-top:0;" data-role="ip">Loading...</p>
		<button type="button" data-role="unblock-btn" style="padding:10px 16px;background:#0f766e;color:#fff;border:none;border-radius:6px;cursor:pointer;">Unblock this machine</button>
		<p data-role="status" style="margin-top:14px;color:#0f766e;"></p>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'ultralove_customer_ip_unblock', 'ultralove_cipu_render_shortcode' );

/**
 * Register My Account endpoint for WooCommerce users.
 */
function ultralove_cipu_register_account_endpoint() {
	if ( ! function_exists( 'is_account_page' ) ) {
		return;
	}

	add_rewrite_endpoint( 'unblock-ip', EP_ROOT | EP_PAGES );

	if ( '1' !== get_option( 'ultralove_cipu_endpoint_rewrite_flushed', '0' ) ) {
		flush_rewrite_rules( false );
		update_option( 'ultralove_cipu_endpoint_rewrite_flushed', '1', false );
	}
}
add_action( 'init', 'ultralove_cipu_register_account_endpoint', 30 );

/**
 * Add menu item to WooCommerce My Account navigation.
 *
 * @param array $items Existing account menu items.
 *
 * @return array
 */
function ultralove_cipu_account_menu_item( $items ) {
	if ( ! is_array( $items ) ) {
		return $items;
	}

	$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
	if ( null !== $logout ) {
		unset( $items['customer-logout'] );
	}

	$items['unblock-ip'] = 'Unblock My IP';

	if ( null !== $logout ) {
		$items['customer-logout'] = $logout;
	}

	return $items;
}
add_filter( 'woocommerce_account_menu_items', 'ultralove_cipu_account_menu_item', 40 );

/**
 * Render endpoint content.
 */
function ultralove_cipu_render_account_endpoint_content() {
	echo do_shortcode( '[ultralove_customer_ip_unblock]' );
}
add_action( 'woocommerce_account_unblock-ip_endpoint', 'ultralove_cipu_render_account_endpoint_content' );
