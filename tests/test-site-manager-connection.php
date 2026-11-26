<?php
// Standalone regression tests. WordPress APIs are replaced with in-memory fixtures.
define( 'ABSPATH', __DIR__ );
define( 'BMA_US_PLUGIN_LOADING', true );
define( 'AUTH_KEY', 'isolated-test-key' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'BMA_US_LAST_CHECK_OPTION', 'last_check' );
define( 'BMA_US_HEALTH_OPTION', 'health' );
define( 'BMA_US_PLUGIN_FILE', __FILE__ );
define( 'BMA_US_CRON_HOOK', 'scheduled_update' );

class WP_Error {
	public $code;
	public $data;
	public function __construct( $code, $message, $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}
}
class WP_REST_Server {
	const READABLE = 'GET';
}
class WP_REST_Request {
	private $headers;
	public function __construct( $headers ) {
		$this->headers = $headers;
	}
	public function get_header( $key ) {
		return $this->headers[ $key ] ?? '';
	}
}
class WP_REST_Response {
	public $data;
	public $headers = array();
	public function __construct( $data ) {
		$this->data = $data;
	}
	public function header( $key, $value ) {
		$this->headers[ strtolower( $key ) ] = $value;
	}
}
class TestRedirect extends RuntimeException {}

function add_action( ...$args ) {}
function register_rest_route( $namespace, $path, $args ) { $GLOBALS['routes'][ $namespace . $path ] = $args; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
function get_transient( $key ) {
	$entry = $GLOBALS['transients'][ $key ] ?? null;
	return $entry && $entry['expires'] > time() ? $entry['value'] : false;
}
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = array( 'value' => $value, 'expires' => time() + $ttl, 'ttl' => $ttl ); }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
function is_ssl() { return $GLOBALS['https']; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return 7; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $data ) { return new WP_REST_Response( $data ); }
function bma_us_load_update_files() {}
function get_site_transient( $key ) { return $GLOBALS['update_transients'][ $key ] ?? false; }
function get_plugins() { return array(); }
function wp_get_theme( $stylesheet ) {
	return new class {
		public function exists() { return false; }
	};
}
function bma_us_get_backup_protection_status() { return array( 'provider' => 'external' ); }
function bma_us_get_history() { return array(); }
function bma_us_get_settings() { return array( 'enabled' => true ); }
function get_file_data( ...$args ) { return array( 'version' => '1.0.27' ); }
function wp_next_scheduled( $hook ) { return 1700000000; }
function get_bloginfo( $key ) { return $GLOBALS['site_name']; }
function current_user_can( $capability ) { return 'update_core' === $capability; }
function check_admin_referer( $action ) { expect( $GLOBALS['admin_action'], $action ); }
function admin_url( $path ) { return $path; }
function add_query_arg( $key, $value, $url ) { return $url . '&' . $key . '=' . $value; }
function wp_safe_redirect( $url ) { throw new TestRedirect( $url ); }

require dirname( __DIR__ ) . '/includes/site-manager-connection.php';

use BMA_US_Site_Manager_Connection as Connection;

if ( ! extension_loaded( 'openssl' ) ) {
	fwrite( STDERR, "The Site Manager tests require the PHP OpenSSL extension.\n" );
	exit( 1 );
}

function expect( $expected, $actual ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function manage( $method, $notice ) {
	$GLOBALS['admin_action'] = 'bma_us_sm_' . $method;
	try {
		Connection::$method();
	} catch ( TestRedirect $redirect ) {
		expect( 'bma_sm_notice=' . $notice, substr( $redirect->getMessage(), -strlen( 'bma_sm_notice=' . $notice ) ) );
		return;
	}
	throw new RuntimeException( 'Expected an admin redirect.' );
}

function signed_request( $secret, $offset = 0, $extra = array() ) {
	$time = time() + $offset;
	$nonce = bin2hex( random_bytes( 16 ) );
	$payload = 'GET|/wp-json/bma-us/v1/site-status|' . $time . '|' . $nonce . '|' . hash( 'sha256', '' );
	return new WP_REST_Request( $extra + array(
		'x-bma-timestamp' => (string) $time,
		'x-bma-nonce' => $nonce,
		'x-bma-site-id' => 'test-site',
		'x-bma-signature' => hash_hmac( 'sha256', $payload, $secret ),
	) );
}

function dispatch_status( $request ) {
	$route = $GLOBALS['routes']['bma-us/v1/site-status'];
	$permission = call_user_func( $route['permission_callback'], $request );
	return true === $permission ? call_user_func( $route['callback'], $request ) : $permission;
}

function rejected( $code, $http, $request ) {
	$result = dispatch_status( $request );
	expect( true, is_wp_error( $result ) );
	expect( $code, $result->code );
	expect( $http, $result->data['status'] );
}

function status_data( $request ) {
	$result = dispatch_status( $request );
	expect( true, $result instanceof WP_REST_Response );
	return $result->data;
}

$tests = array(
	'valid HMAC and read-only route' => function ( $secret ) {
		expect( array( 'bma-us/v1/site-status' ), array_keys( $GLOBALS['routes'] ) );
		expect( 'GET', $GLOBALS['routes']['bma-us/v1/site-status']['methods'] );
		$before = $GLOBALS['options'];
		$data = status_data( signed_request( $secret ) );
		expect( '1.3', $data['api_version'] );
		expect( 'Fixture', $data['site'] );
		expect( false, strpos( json_encode( $data ), $secret ) );
		$before[ Connection::OPTION ]['last_request'] = get_option( Connection::OPTION )['last_request'];
		$before[ Connection::OPTION ]['last_site_id'] = 'test-site';
		expect( $before, $GLOBALS['options'] );
	},
	'invalid authentication and malformed headers' => function ( $secret ) {
		rejected( 'bma_us_sm_invalid_signature', 401, signed_request( 'wrong-key' ) );
		rejected( 'bma_us_sm_invalid_headers', 401, signed_request( $secret, 0, array( 'x-bma-nonce' => 'short' ) ) );
		rejected( 'bma_us_sm_invalid_site_id', 401, signed_request( $secret, 0, array( 'x-bma-site-id' => 'invalid id' ) ) );
	},
	'expired and future requests' => function ( $secret ) {
		rejected( 'bma_us_sm_expired', 401, signed_request( $secret, -310 ) );
		rejected( 'bma_us_sm_expired', 401, signed_request( $secret, 310 ) );
	},
	'duplicate nonce' => function ( $secret ) {
		$request = signed_request( $secret );
		status_data( $request );
		rejected( 'bma_us_sm_replay', 409, $request );
	},
	'rate limiting and fresh bucket' => function ( $secret ) {
		// Seed both possible minute buckets so crossing a minute boundary is harmless.
		$bucket = floor( time() / 60 );
		foreach ( array( $bucket, $bucket + 1 ) as $minute ) {
			set_transient( 'bma_us_sm_rate_' . $minute, Connection::RATE_LIMIT - 1, 120 );
		}
		status_data( signed_request( $secret ) );
		expect(
			2 * ( Connection::RATE_LIMIT - 1 ) + 1,
			get_transient( 'bma_us_sm_rate_' . $bucket ) + get_transient( 'bma_us_sm_rate_' . ( $bucket + 1 ) )
		);
		foreach ( array( $bucket, $bucket + 1 ) as $minute ) {
			set_transient( 'bma_us_sm_rate_' . $minute, Connection::RATE_LIMIT, 120 );
		}
		rejected( 'bma_us_sm_rate_limited', 429, signed_request( $secret ) );
		foreach ( array( $bucket, $bucket + 1 ) as $minute ) {
			delete_transient( 'bma_us_sm_rate_' . $minute );
		}
		status_data( signed_request( $secret ) );
	},
	'rotation overlap, cancellation and promotion' => function ( $secret ) {
		manage( 'generate', 'generated' );
		$pending = get_transient( 'bma_us_sm_reveal_7' );
		status_data( signed_request( $secret ) );
		status_data( signed_request( $pending ) );
		manage( 'cancel_rotation', 'rotation_cancelled' );
		rejected( 'bma_us_sm_invalid_signature', 401, signed_request( $pending ) );
		manage( 'generate', 'generated' );
		$pending = get_transient( 'bma_us_sm_reveal_7' );
		manage( 'complete_rotation', 'rotation_completed' );
		rejected( 'bma_us_sm_invalid_signature', 401, signed_request( $secret ) );
		status_data( signed_request( $pending ) );
	},
	'revocation clears access and cached status' => function ( $secret ) {
		status_data( signed_request( $secret ) );
		manage( 'revoke', 'revoked' );
		expect( false, get_option( Connection::OPTION ) );
		expect( false, get_transient( 'bma_us_sm_status_1' ) );
		expect( false, get_transient( 'bma_us_sm_reveal_7' ) );
		rejected( 'bma_us_sm_disabled', 403, signed_request( $secret ) );
	},
	'status cache, expiration and manual refresh' => function ( $secret ) {
		$initial = status_data( signed_request( $secret ) );
		expect( 60, $GLOBALS['transients']['bma_us_sm_status_1']['ttl'] );
		$GLOBALS['site_name'] = 'Refreshed';
		expect( $initial, status_data( signed_request( $secret ) ) );
		rejected( 'bma_us_sm_invalid_signature', 401, signed_request( 'wrong-key', 0, array( 'x-bma-refresh' => '1' ) ) );
		$refreshed = status_data( signed_request( $secret, 0, array( 'x-bma-refresh' => '1' ) ) );
		expect( 'Refreshed', $refreshed['site'] );
		expect( $refreshed, status_data( signed_request( $secret ) ) );
		$GLOBALS['transients']['bma_us_sm_status_1']['expires'] = time() - 1;
		$GLOBALS['site_name'] = 'Rebuilt';
		expect( 'Rebuilt', status_data( signed_request( $secret ) )['site'] );
	},
	'update freshness distinguishes unavailable, outdated and current data' => function ( $secret ) {
		$refresh = function () use ( $secret ) {
			return status_data( signed_request( $secret, 0, array( 'x-bma-refresh' => '1' ) ) )['updates'];
		};
		$missing = $refresh();
		foreach ( array( 'plugins', 'themes', 'core' ) as $type ) {
			expect( array( 'status' => 'unavailable', 'checked_at' => null ), $missing['freshness'][ $type ] );
			expect( 0, $missing[ $type ] );
		}
		$now = time() - 5;
		$old = $now - 86400;
		$GLOBALS['update_transients'] = array(
			'update_plugins' => (object) array( 'last_checked' => $now ),
			'update_themes' => (object) array( 'last_checked' => $old, 'response' => array( 'fixture' => array( 'new_version' => '2.0' ) ) ),
			'update_core' => (object) array( 'last_checked' => $now, 'updates' => array() ),
		);
		$mixed = $refresh();
		expect( 86400, $mixed['freshness']['max_age_seconds'] );
		expect( 1, $mixed['total'] );
		expect( 'unavailable', $mixed['freshness']['plugins']['status'] );
		expect( array( 'status' => 'outdated', 'checked_at' => $old ), $mixed['freshness']['themes'] );
		expect( 1, $mixed['themes'] );
		expect( array( 'status' => 'current', 'checked_at' => $now ), $mixed['freshness']['core'] );
		expect( 0, $mixed['core'] );
		$GLOBALS['update_transients']['update_plugins']->response = array();
		expect( 'current', $refresh()['freshness']['plugins']['status'] );
		unset( $GLOBALS['update_transients']['update_plugins']->last_checked );
		expect( 'unavailable', $refresh()['freshness']['plugins']['status'] );
	},
	'HTTPS required even with a populated status cache' => function ( $secret ) {
		status_data( signed_request( $secret ) );
		$GLOBALS['https'] = false;
		rejected( 'bma_us_sm_https_required', 403, signed_request( $secret ) );
	},
	'fresh and cached HTTP responses prohibit shared storage' => function ( $secret ) {
		foreach ( array( 'fresh', 'cached' ) as $source ) {
			$response = dispatch_status( signed_request( $secret ) );
			expect( true, $response instanceof WP_REST_Response );
			$directives = array_map( 'trim', explode( ',', strtolower( $response->headers['cache-control'] ?? '' ) ) );
			if ( ! in_array( 'no-store', $directives, true ) || ! in_array( 'private', $directives, true ) ) {
				throw new RuntimeException( "The $source response lacks a shared-cache storage restriction." );
			}
		}
	},
);

$failed = 0;
$wp_version = '6.9';
foreach ( $tests as $name => $test ) {
	try {
		$options = array( 'last_check' => array( 'status' => 'current' ) );
		$transients = $routes = array();
		$update_transients = array();
		$https = true;
		$site_name = 'Fixture';
		Connection::register_route();
		manage( 'generate', 'generated' );
		$secret = get_transient( 'bma_us_sm_reveal_7' );
		expect( true, is_string( $secret ) && strlen( $secret ) >= 32 );
		expect( 0, strpos( get_option( Connection::OPTION )['secret'], 'bmaus1:' ) );
		expect( false, strpos( get_option( Connection::OPTION )['secret'], $secret ) );
		$test( $secret );
		echo "PASS $name\n";
	} catch ( Throwable $error ) {
		++$failed;
		fwrite( STDERR, "FAIL $name: " . $error->getMessage() . "\n" );
	}
}
exit( $failed ? 1 : 0 );
