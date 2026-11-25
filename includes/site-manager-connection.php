<?php
/**
 * Secure, read-only connection for BMA Site Manager.
 *
 * This module does not execute updates or alter scheduler, backup, lock, or
 * reporting behavior.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BMA_US_PLUGIN_LOADING' ) ) {
	exit;
}

final class BMA_US_Site_Manager_Connection {
	const OPTION       = 'bma_us_site_manager_connection';
	const PAGE_SLUG    = 'bma-us-site-manager-connection';
	const ROUTE        = '/bma-us/v1/site-status';
	const SIGNED_PATH  = '/wp-json/bma-us/v1/site-status';
	const MAX_SKEW     = 300;
	const RATE_LIMIT   = 30;
	const CACHE_TTL    = 60;
	const SECRET_PREFIX = 'bmaus1:';

	private static $authenticated = false;

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_bma_us_sm_generate', array( __CLASS__, 'generate' ) );
		add_action( 'admin_post_bma_us_sm_complete_rotation', array( __CLASS__, 'complete_rotation' ) );
		add_action( 'admin_post_bma_us_sm_cancel_rotation', array( __CLASS__, 'cancel_rotation' ) );
		add_action( 'admin_post_bma_us_sm_revoke', array( __CLASS__, 'revoke' ) );
	}

	public static function admin_menu() {
		add_management_page(
			'BMA Site Manager Connection',
			'Site Manager Connection',
			'update_core',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_route() {
		register_rest_route(
			'bma-us/v1',
			'/site-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'authenticate' ),
			)
		);
	}

	private static function key() {
		$material = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $material . '|bma-update-scheduler-site-manager', true );
	}

	private static function encrypt( $secret ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'bma_us_sm_crypto_unavailable', 'OpenSSL is required to store the Site Manager credential securely.' );
		}
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( (string) $secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'bma-update-scheduler' );
		if ( false === $ciphertext ) {
			return new WP_Error( 'bma_us_sm_crypto_failed', 'The Site Manager credential could not be encrypted.' );
		}
		return self::SECRET_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	private static function decrypt( $encrypted ) {
		if ( ! function_exists( 'openssl_decrypt' ) || 0 !== strpos( (string) $encrypted, self::SECRET_PREFIX ) ) {
			return '';
		}
		$raw = base64_decode( substr( $encrypted, strlen( self::SECRET_PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$result = openssl_decrypt(
			substr( $raw, 28 ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, 12 ),
			substr( $raw, 12, 16 ),
			'bma-update-scheduler'
		);
		return false === $result ? '' : $result;
	}

	private static function settings() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function authenticate( WP_REST_Request $request ) {
		self::$authenticated = false;
		if ( ! is_ssl() ) {
			return new WP_Error( 'bma_us_sm_https_required', 'HTTPS is required.', array( 'status' => 403 ) );
		}

		$settings = self::settings();
		$secrets = array_filter(
			array(
				self::decrypt( $settings['secret'] ?? '' ),
				self::decrypt( $settings['pending_secret'] ?? '' ),
			)
		);
		if ( empty( $settings['enabled'] ) || empty( $secrets ) ) {
			return new WP_Error( 'bma_us_sm_disabled', 'Site Manager access is disabled.', array( 'status' => 403 ) );
		}

		$timestamp = $request->get_header( 'x-bma-timestamp' );
		$nonce     = $request->get_header( 'x-bma-nonce' );
		$signature = strtolower( $request->get_header( 'x-bma-signature' ) );
		$site_id   = $request->get_header( 'x-bma-site-id' );

		if ( ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return new WP_Error( 'bma_us_sm_expired', 'The signed request timestamp is invalid or expired.', array( 'status' => 401 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{16,64}$/D', (string) $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
			return new WP_Error( 'bma_us_sm_invalid_headers', 'The authentication headers are invalid.', array( 'status' => 401 ) );
		}
		if ( '' !== $site_id && ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', (string) $site_id ) ) {
			return new WP_Error( 'bma_us_sm_invalid_site_id', 'The manager site identifier is invalid.', array( 'status' => 401 ) );
		}

		$payload  = 'GET|' . self::SIGNED_PATH . '|' . (int) $timestamp . '|' . $nonce . '|' . hash( 'sha256', '' );
		$valid = false;
		foreach ( $secrets as $secret ) {
			if ( hash_equals( hash_hmac( 'sha256', $payload, $secret ), $signature ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return new WP_Error( 'bma_us_sm_invalid_signature', 'The request signature is invalid.', array( 'status' => 401 ) );
		}

		$nonce_key = 'bma_us_sm_nonce_' . hash( 'sha256', $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'bma_us_sm_replay', 'This signed request has already been used.', array( 'status' => 409 ) );
		}
		set_transient( $nonce_key, 1, self::MAX_SKEW + 60 );

		$bucket_key = 'bma_us_sm_rate_' . floor( time() / MINUTE_IN_SECONDS );
		$requests   = absint( get_transient( $bucket_key ) );
		if ( $requests >= self::RATE_LIMIT ) {
			return new WP_Error( 'bma_us_sm_rate_limited', 'Too many Site Manager requests.', array( 'status' => 429 ) );
		}
		set_transient( $bucket_key, $requests + 1, 2 * MINUTE_IN_SECONDS );

		$settings['last_request'] = time();
		$settings['last_site_id'] = sanitize_text_field( $site_id );
		update_option( self::OPTION, $settings, false );
		self::$authenticated = true;
		return true;
	}

	public static function status( WP_REST_Request $request ) {
		if ( ! self::$authenticated ) {
			return new WP_Error( 'bma_us_sm_unauthorized', 'Authentication is required.', array( 'status' => 401 ) );
		}

		$cache_key = 'bma_us_sm_status_' . get_current_blog_id();
		$force     = '1' === $request->get_header( 'x-bma-refresh' );
		$data      = $force ? false : get_transient( $cache_key );
		if ( ! is_array( $data ) ) {
			$data = self::build_status();
			set_transient( $cache_key, $data, self::CACHE_TTL );
		}

		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function build_status() {
		global $wp_version;
		bma_us_load_update_files();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$core_updates   = get_site_transient( 'update_core' );
		$installed_plugins = get_plugins();
		$plugin_count   = is_object( $plugin_updates ) && is_array( $plugin_updates->response ?? null ) ? count( $plugin_updates->response ) : 0;
		$theme_count    = is_object( $theme_updates ) && is_array( $theme_updates->response ?? null ) ? count( $theme_updates->response ) : 0;
		$core_count     = 0;
		$update_items   = array();
		if ( is_object( $plugin_updates ) && is_array( $plugin_updates->response ?? null ) ) {
			foreach ( array_slice( $plugin_updates->response, 0, 50, true ) as $basename => $update ) {
				$update_items[] = array(
					'type'              => 'plugin',
					'name'              => sanitize_text_field( $installed_plugins[ $basename ]['Name'] ?? $basename ),
					'installed_version' => sanitize_text_field( $installed_plugins[ $basename ]['Version'] ?? '' ),
					'available_version' => sanitize_text_field( $update->new_version ?? '' ),
				);
			}
		}
		if ( is_object( $theme_updates ) && is_array( $theme_updates->response ?? null ) ) {
			foreach ( array_slice( $theme_updates->response, 0, 50, true ) as $stylesheet => $update ) {
				$theme          = wp_get_theme( $stylesheet );
				$update_items[] = array(
					'type'              => 'theme',
					'name'              => sanitize_text_field( $theme->exists() ? $theme->get( 'Name' ) : $stylesheet ),
					'installed_version' => sanitize_text_field( $theme->exists() ? $theme->get( 'Version' ) : '' ),
					'available_version' => sanitize_text_field( $update['new_version'] ?? '' ),
				);
			}
		}
		if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( 'upgrade' === ( $update->response ?? '' ) ) {
					$core_count = 1;
					$update_items[] = array(
						'type'              => 'core',
						'name'              => 'WordPress',
						'installed_version' => sanitize_text_field( $wp_version ),
						'available_version' => sanitize_text_field( $update->current ?? '' ),
					);
					break;
				}
			}
		}

		$backup_system  = bma_us_get_backup_protection_status();
		$external_mode  = 'external' === ( $backup_system['provider'] ?? '' );
		$backup         = $backup_system['verification'] ?? array();
		if ( ! $external_mode && empty( $backup ) ) {
			$backup = bma_us_verify_updraft_backup();
		}
		$backup_passed  = $external_mode || ( 'updraftplus' === ( $backup_system['provider'] ?? '' ) && ! empty( $backup_system['active'] ) && ! empty( $backup['passed'] ) );
		$backup_message = $external_mode ? ( $backup_system['message'] ?? '' ) : ( $backup_passed ? ( $backup['message'] ?? '' ) : ( $backup_system['message'] ?? ( $backup['message'] ?? '' ) ) );
		$last_check = get_option( BMA_US_LAST_CHECK_OPTION, array() );
		$health     = get_option( BMA_US_HEALTH_OPTION, array() );
		$backup_components = array();
		foreach ( array_slice( (array) ( $backup['components'] ?? array() ), 0, 5, true ) as $key => $component ) {
			$backup_components[ sanitize_key( $key ) ] = array(
				'label'         => sanitize_text_field( $component['label'] ?? $key ),
				'expected'      => ! empty( $component['expected'] ),
				'verified'      => ! empty( $component['verified'] ),
				'archive_found' => ! empty( $component['verified'] ),
			);
		}
		$health_checks = array();
		foreach ( array_slice( (array) ( $health['checks'] ?? array() ), 0, 20 ) as $check ) {
			$health_checks[] = array(
				'name'    => sanitize_text_field( $check['name'] ?? '' ),
				'status'  => sanitize_key( $check['status'] ?? '' ),
				'message' => sanitize_text_field( $check['message'] ?? '' ),
			);
		}
		$history = array();
		foreach ( array_slice( bma_us_get_history(), 0, 20 ) as $record ) {
			$history[] = array(
				'timestamp'   => absint( $record['timestamp'] ?? 0 ),
				'component'   => sanitize_text_field( $record['component'] ?? '' ),
				'type'        => sanitize_key( $record['type'] ?? '' ),
				'old_version' => sanitize_text_field( $record['old_version'] ?? '' ),
				'new_version' => sanitize_text_field( $record['new_version'] ?? '' ),
				'trigger'     => sanitize_key( $record['trigger'] ?? '' ),
				'status'      => sanitize_key( $record['status'] ?? '' ),
				'message'     => sanitize_text_field( $record['message'] ?? '' ),
			);
		}
		$settings   = bma_us_get_settings();
		$headers    = get_file_data( BMA_US_PLUGIN_FILE, array( 'version' => 'Version' ), 'plugin' );
		$status     = 'healthy';
		if ( ! empty( $health ) && in_array( $health['status'] ?? '', array( 'failed', 'critical' ), true ) ) {
			$status = 'critical';
		} elseif ( ! $backup_passed || ( ! empty( $health ) && 'healthy' !== ( $health['status'] ?? 'healthy' ) ) ) {
			$status = 'warning';
		}

		return array(
			'api_version'    => '1.3',
			'site'           => sanitize_text_field( get_bloginfo( 'name' ) ),
			'checked_at'     => time(),
			'status'         => $status,
			'wordpress'      => sanitize_text_field( $wp_version ),
			'php'            => sanitize_text_field( PHP_VERSION ),
			'plugin_version' => sanitize_text_field( $headers['version'] ?? '' ),
			'updates'        => array(
				'total'   => $plugin_count + $theme_count + $core_count,
				'plugins' => $plugin_count,
				'themes'  => $theme_count,
				'core'    => $core_count,
				'items'   => array_slice( $update_items, 0, 50 ),
			),
			'backup'         => array(
				'status'       => $external_mode ? 'external' : ( $backup_passed ? 'protected' : 'warning' ),
				'provider'     => sanitize_key( $backup_system['provider'] ?? '' ),
				'provider_name'=> sanitize_text_field( $backup_system['provider_name'] ?? '' ),
				'state_reported'=> ! $external_mode,
				'installed'    => ! empty( $backup_system['installed'] ),
				'active'       => ! empty( $backup_system['active'] ),
				'timestamp'    => absint( $backup['timestamp'] ?? 0 ),
				'age_days'     => isset( $backup['age'] ) ? absint( floor( $backup['age'] / DAY_IN_SECONDS ) ) : 0,
				'successful'   => ! empty( $backup['successful'] ),
				'components'   => $backup_components,
				'missing'      => array_map( 'sanitize_text_field', array_slice( (array) ( $backup['missing'] ?? array() ), 0, 5 ) ),
				'message'      => sanitize_text_field( $backup_message ),
			),
			'schedule'       => array(
				'enabled'  => ! empty( $settings['enabled'] ),
				'next_run' => absint( wp_next_scheduled( BMA_US_CRON_HOOK ) ),
			),
			'last_check'     => array(
				'status'    => sanitize_key( $last_check['status'] ?? '' ),
				'timestamp' => absint( $last_check['timestamp'] ?? 0 ),
				'message'   => sanitize_text_field( $last_check['message'] ?? '' ),
			),
			'health'         => array(
				'status'  => sanitize_key( $health['status'] ?? '' ),
				'checked' => absint( $health['checked'] ?? 0 ),
				'trigger' => sanitize_key( $health['trigger'] ?? '' ),
				'message' => sanitize_text_field( $health['message'] ?? '' ),
				'checks'  => $health_checks,
			),
			'history'        => $history,
		);
	}

	private static function authorize( $action ) {
		if ( ! current_user_can( 'update_core' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this connection.', 'bma-update-scheduler' ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect( $notice ) {
		wp_safe_redirect( add_query_arg( 'bma_sm_notice', sanitize_key( $notice ), admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	public static function generate() {
		self::authorize( 'bma_us_sm_generate' );
		$secret    = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$encrypted = self::encrypt( $secret );
		if ( is_wp_error( $encrypted ) ) {
			self::redirect( 'crypto_failed' );
		}
		$settings = self::settings();
		if ( ! empty( $settings['enabled'] ) && ! empty( $settings['secret'] ) ) {
			$settings['pending_secret']      = $encrypted;
			$settings['pending_created']     = time();
			$settings['pending_fingerprint'] = substr( hash( 'sha256', $secret ), 0, 12 );
			update_option( self::OPTION, $settings, false );
		} else {
			update_option(
				self::OPTION,
				array(
				'enabled'      => 1,
				'secret'       => $encrypted,
				'created'      => time(),
				'fingerprint'  => substr( hash( 'sha256', $secret ), 0, 12 ),
				'last_request' => 0,
				'last_site_id' => '',
				),
				false
			);
		}
		set_transient( 'bma_us_sm_reveal_' . get_current_user_id(), $secret, 5 * MINUTE_IN_SECONDS );
		delete_transient( 'bma_us_sm_status_' . get_current_blog_id() );
		self::redirect( 'generated' );
	}

	public static function complete_rotation() {
		self::authorize( 'bma_us_sm_complete_rotation' );
		$settings = self::settings();
		if ( empty( $settings['pending_secret'] ) ) {
			self::redirect( 'no_pending_rotation' );
		}
		$settings['secret']      = $settings['pending_secret'];
		$settings['created']     = $settings['pending_created'] ?? time();
		$settings['fingerprint'] = $settings['pending_fingerprint'] ?? '';
		unset( $settings['pending_secret'], $settings['pending_created'], $settings['pending_fingerprint'] );
		update_option( self::OPTION, $settings, false );
		delete_transient( 'bma_us_sm_status_' . get_current_blog_id() );
		self::redirect( 'rotation_completed' );
	}

	public static function cancel_rotation() {
		self::authorize( 'bma_us_sm_cancel_rotation' );
		$settings = self::settings();
		unset( $settings['pending_secret'], $settings['pending_created'], $settings['pending_fingerprint'] );
		update_option( self::OPTION, $settings, false );
		delete_transient( 'bma_us_sm_reveal_' . get_current_user_id() );
		self::redirect( 'rotation_cancelled' );
	}

	public static function revoke() {
		self::authorize( 'bma_us_sm_revoke' );
		delete_option( self::OPTION );
		delete_transient( 'bma_us_sm_reveal_' . get_current_user_id() );
		delete_transient( 'bma_us_sm_status_' . get_current_blog_id() );
		self::redirect( 'revoked' );
	}

	public static function render() {
		if ( ! current_user_can( 'update_core' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bma-update-scheduler' ) );
		}
		$settings = self::settings();
		$secret   = get_transient( 'bma_us_sm_reveal_' . get_current_user_id() );
		if ( $secret ) {
			delete_transient( 'bma_us_sm_reveal_' . get_current_user_id() );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selector; no state is changed.
		$notice = sanitize_key( wp_unslash( $_GET['bma_sm_notice'] ?? '' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BMA Site Manager Connection', 'bma-update-scheduler' ); ?></h1>
			<p><?php esc_html_e( 'Enable secure, read-only monitoring from one BMA Site Manager installation.', 'bma-update-scheduler' ); ?></p>
			<?php if ( 'crypto_failed' === $notice ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'The connection secret could not be encrypted. Confirm that OpenSSL is available.', 'bma-update-scheduler' ); ?></p></div><?php endif; ?>
			<?php if ( 'revoked' === $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site Manager access was revoked.', 'bma-update-scheduler' ); ?></p></div><?php endif; ?>
			<?php if ( 'rotation_completed' === $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Credential rotation completed. The previous secret is no longer accepted.', 'bma-update-scheduler' ); ?></p></div><?php endif; ?>
			<?php if ( 'rotation_cancelled' === $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Credential rotation cancelled. The existing secret remains active.', 'bma-update-scheduler' ); ?></p></div><?php endif; ?>
			<?php if ( $secret ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Copy this secret now. It will not be displayed again.', 'bma-update-scheduler' ); ?></strong></p><p><input id="bma-us-sm-secret" type="text" class="large-text code" readonly value="<?php echo esc_attr( $secret ); ?>"></p><p><button type="button" class="button" id="bma-us-sm-copy"><?php esc_html_e( 'Copy secret', 'bma-update-scheduler' ); ?></button></p></div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width:900px;margin-top:20px"><tbody>
				<tr><th style="width:220px"><?php esc_html_e( 'Connection status', 'bma-update-scheduler' ); ?></th><td><?php echo ! empty( $settings['enabled'] ) ? '<strong style="color:#008a20">' . esc_html__( 'Enabled', 'bma-update-scheduler' ) . '</strong>' : '<strong>' . esc_html__( 'Not configured', 'bma-update-scheduler' ) . '</strong>'; ?></td></tr>
				<tr><th><?php esc_html_e( 'Website URL', 'bma-update-scheduler' ); ?></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Status endpoint', 'bma-update-scheduler' ); ?></th><td><code><?php echo esc_html( rest_url( ltrim( self::ROUTE, '/' ) ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Credential fingerprint', 'bma-update-scheduler' ); ?></th><td><code><?php echo esc_html( $settings['fingerprint'] ?? '—' ); ?></code></td></tr>
				<?php if ( ! empty( $settings['pending_secret'] ) ) : ?><tr><th><?php esc_html_e( 'Staged credential', 'bma-update-scheduler' ); ?></th><td><strong style="color:#996800"><?php esc_html_e( 'Awaiting verification', 'bma-update-scheduler' ); ?></strong> <code><?php echo esc_html( $settings['pending_fingerprint'] ?? '' ); ?></code></td></tr><?php endif; ?>
				<tr><th><?php esc_html_e( 'Last authenticated request', 'bma-update-scheduler' ); ?></th><td><?php echo ! empty( $settings['last_request'] ) ? esc_html( wp_date( 'M j, Y \a\t g:i A', $settings['last_request'], wp_timezone() ) ) : esc_html__( 'Never', 'bma-update-scheduler' ); ?></td></tr>
			</tbody></table>
			<div style="display:flex;gap:8px;margin-top:18px">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bma_us_sm_generate"><?php wp_nonce_field( 'bma_us_sm_generate' ); ?><button class="button button-primary" type="submit"><?php echo ! empty( $settings['enabled'] ) ? esc_html__( 'Generate rotation secret', 'bma-update-scheduler' ) : esc_html__( 'Generate connection secret', 'bma-update-scheduler' ); ?></button></form>
				<?php if ( ! empty( $settings['pending_secret'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bma_us_sm_complete_rotation"><?php wp_nonce_field( 'bma_us_sm_complete_rotation' ); ?><button class="button" type="submit"><?php esc_html_e( 'Complete verified rotation', 'bma-update-scheduler' ); ?></button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="bma_us_sm_cancel_rotation"><?php wp_nonce_field( 'bma_us_sm_cancel_rotation' ); ?><button class="button" type="submit"><?php esc_html_e( 'Cancel rotation', 'bma-update-scheduler' ); ?></button></form><?php endif; ?>
				<?php if ( ! empty( $settings['enabled'] ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke BMA Site Manager access?', 'bma-update-scheduler' ) ); ?>');"><input type="hidden" name="action" value="bma_us_sm_revoke"><?php wp_nonce_field( 'bma_us_sm_revoke' ); ?><button class="button" type="submit"><?php esc_html_e( 'Revoke access', 'bma-update-scheduler' ); ?></button></form><?php endif; ?>
			</div>
			<p class="description" style="margin-top:16px;max-width:900px"><?php esc_html_e( 'This connection is read-only. It exposes update counts, existing-backup status, schedule status, plugin and WordPress versions, and the latest BMA check result. It cannot start updates, create backups, change settings, or access WordPress users.', 'bma-update-scheduler' ); ?></p>
			<?php if ( ! empty( $settings['pending_secret'] ) ) : ?><p class="description" style="max-width:900px"><strong><?php esc_html_e( 'Rotation order:', 'bma-update-scheduler' ); ?></strong> <?php esc_html_e( 'Save and verify the staged secret in BMA Site Manager first. Return here and complete the rotation only after verification succeeds.', 'bma-update-scheduler' ); ?></p><?php endif; ?>
		</div>
		<?php if ( $secret ) : ?><script>document.getElementById('bma-us-sm-copy').addEventListener('click',function(){var field=document.getElementById('bma-us-sm-secret');field.select();navigator.clipboard.writeText(field.value);this.textContent='Copied';});</script><?php endif; ?>
		<?php
	}
}

BMA_US_Site_Manager_Connection::boot();
