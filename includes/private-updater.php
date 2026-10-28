<?php
/**
 * Authenticated update support for the privately distributed edition.
 *
 * @package BMA_Update_Scheduler
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BMA_US_Private_Updater {
 const UPDATE_URI = 'https://github.com/nikunjkgosai-ui/bma-update-scheduler';
 const API_ROOT = 'https://api.github.com/repos/nikunjkgosai-ui/bma-update-scheduler';
 const CACHE_KEY = 'bma_us_private_release_3';
 const CACHE_TTL = 21600;

 public static function boot() {
  add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
  add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
  add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_private_package' ), 10, 4 );
  add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_release_cache' ), 10, 0 );
 }

 public static function clear_release_cache() {
  delete_site_transient( self::CACHE_KEY );
 }

 public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
  unset( $locales );
  if ( BMA_US_PLUGIN_BASENAME !== $plugin_file || self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
   return $update;
  }

  $release = self::get_latest_release();
  if ( is_wp_error( $release ) || ! is_array( $release ) ) {
   return false;
  }

  return array(
   'id'           => self::UPDATE_URI,
   'slug'         => dirname( BMA_US_PLUGIN_BASENAME ),
   'version'      => $release['version'],
   'url'          => $release['url'],
   'package'      => $release['package'],
   'requires'     => $release['requires'],
   'requires_php' => $release['requires_php'],
   'tested'       => $release['tested'],
   'icons'        => self::icons(),
   'autoupdate'   => false,
  );
 }

 public static function plugin_information( $result, $action, $args ) {
  if ( 'plugin_information' !== $action || ! is_object( $args ) || dirname( BMA_US_PLUGIN_BASENAME ) !== ( $args->slug ?? '' ) ) {
   return $result;
  }
  $release = self::get_latest_release();
  if ( is_wp_error( $release ) || ! is_array( $release ) ) {
   return $result;
  }
  $changelog = '' !== $release['body'] ? nl2br( esc_html( $release['body'] ) ) : '<p>See the private GitHub release for this version.</p>';
  return (object) array(
   'name'              => 'BMA Update Scheduler',
   'slug'              => dirname( BMA_US_PLUGIN_BASENAME ),
   'version'           => $release['version'],
   'author'            => '<a href="https://www.brandingmarketingagency.com/">Branding Marketing Agency</a>',
   'homepage'          => self::UPDATE_URI,
   'requires'          => $release['requires'],
   'tested'            => $release['tested'],
   'requires_php'      => $release['requires_php'],
   'last_updated'      => $release['published_at'],
   'download_link'     => $release['package'],
   'external'          => true,
   'short_description' => 'Manual and scheduled WordPress updates with an existing-backup safety gate, history, health checks and email alerts.',
   'sections'          => array(
    'description' => '<p>BMA Update Scheduler provides manual and scheduled plugin, theme and core updates with an existing UpdraftPlus backup safety gate, update history, post-update health checks, locking and optional email alerts.</p>',
    'changelog'   => $changelog,
   ),
   'icons'             => self::icons(),
  );
 }

 private static function get_latest_release() {
  $cached = get_site_transient( self::CACHE_KEY );
  if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
   return $cached;
  }

  $token = self::get_token();
  if ( '' === $token ) {
   return new WP_Error( 'bma_us_github_token_missing', 'The private GitHub update token is not configured.' );
  }

  $response = wp_safe_remote_get(
   self::API_ROOT . '/releases/latest',
   array(
    'headers'     => self::api_headers( $token ),
    'redirection' => 0,
    'timeout'     => 15,
   )
  );
  if ( is_wp_error( $response ) ) {
   return $response;
  }
  if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
   return new WP_Error( 'bma_us_github_release_request_failed', 'GitHub did not return an accessible release.' );
  }

  $data = json_decode( wp_remote_retrieve_body( $response ), true );
  if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
   return new WP_Error( 'bma_us_github_release_invalid', 'GitHub returned an invalid production release.' );
  }

  $version = isset( $data['tag_name'] ) ? ltrim( sanitize_text_field( $data['tag_name'] ), "vV" ) : '';
  if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
   return new WP_Error( 'bma_us_github_version_invalid', 'The GitHub release tag is not a supported version.' );
  }

  $expected_asset = 'bma-update-scheduler-' . $version . '.zip';
  $package = '';
  foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
   if ( ! is_array( $asset ) || $expected_asset !== ( $asset['name'] ?? '' ) ) { continue; }
   $candidate = isset( $asset['url'] ) ? esc_url_raw( $asset['url'], array( 'https' ) ) : '';
   if ( self::is_asset_api_url( $candidate ) ) { $package = $candidate; break; }
  }
  if ( '' === $package ) {
   return new WP_Error( 'bma_us_github_asset_missing', 'The release does not contain the expected plugin ZIP.' );
  }

  $release = array(
   'version'      => $version,
   'url'          => esc_url_raw( $data['html_url'] ?? self::UPDATE_URI, array( 'https' ) ),
   'package'      => $package,
   'requires'     => '6.3',
   'requires_php' => '7.4',
   'tested'       => '7.1.3',
   'body'         => sanitize_textarea_field( $data['body'] ?? '' ),
   'published_at' => sanitize_text_field( $data['published_at'] ?? '' ),
  );
  set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
  return $release;
 }

 public static function download_private_package( $reply, $package, $upgrader, $hook_extra ) {
  unset( $upgrader );
  if ( false !== $reply || ! self::is_asset_api_url( $package ) ) { return $reply; }
  if ( empty( $hook_extra['plugin'] ) || BMA_US_PLUGIN_BASENAME !== $hook_extra['plugin'] ) { return $reply; }

  $token = self::get_token();
  if ( '' === $token ) {
   return new WP_Error( 'bma_us_github_token_missing', 'The private GitHub update token is not configured.' );
  }

  require_once ABSPATH . 'wp-admin/includes/file.php';
  $temporary = wp_tempnam( 'bma-update-scheduler-' . time() . '.zip' );
  if ( ! $temporary ) {
   return new WP_Error( 'bma_us_github_package_write_failed', 'WordPress could not create a temporary update file.' );
  }
  $response = wp_safe_remote_get(
   $package,
   array(
    'headers'     => array_merge( self::api_headers( $token ), array( 'Accept' => 'application/octet-stream' ) ),
    'redirection' => 0,
    'stream'      => true,
    'filename'    => $temporary,
    'timeout'     => 30,
   )
  );
  if ( is_wp_error( $response ) ) { wp_delete_file( $temporary ); return $response; }

  $code = wp_remote_retrieve_response_code( $response );
  if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
   $location = wp_remote_retrieve_header( $response, 'location' );
   wp_delete_file( $temporary );
   if ( ! self::is_trusted_download_url( $location ) ) {
    return new WP_Error( 'bma_us_github_redirect_invalid', 'GitHub returned an untrusted package location.' );
   }
   return download_url( $location, 300 );
  }

  if ( 200 !== $code ) {
   wp_delete_file( $temporary );
   return new WP_Error( 'bma_us_github_download_failed', 'GitHub did not return the private update package.' );
  }

  $handle = fopen( $temporary, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
  $signature = false !== $handle ? fread( $handle, 4 ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
  if ( false !== $handle ) { fclose( $handle ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
  if ( "PK\x03\x04" !== $signature ) {
   wp_delete_file( $temporary );
   return new WP_Error( 'bma_us_github_package_invalid', 'The downloaded update package is not a valid ZIP archive.' );
  }
  return $temporary;
 }

 private static function get_token() {
  if ( ! defined( 'BMA_US_GITHUB_TOKEN' ) || ! is_string( BMA_US_GITHUB_TOKEN ) ) { return ''; }
  $token = trim( BMA_US_GITHUB_TOKEN );
  return preg_match( '/^[A-Za-z0-9_]+$/', $token ) ? $token : '';
 }

 private static function api_headers( $token ) {
  return array(
   'Accept'               => 'application/vnd.github+json',
   'Authorization'        => 'Bearer ' . $token,
   'X-GitHub-Api-Version' => '2022-11-28',
   'User-Agent'           => 'BMA-Update-Scheduler/' . self::installed_version(),
  );
 }

 private static function installed_version() {
  static $version = null;
  if ( null === $version ) {
   $data = get_file_data( BMA_US_PLUGIN_FILE, array( 'Version' => 'Version' ), 'plugin' );
   $version = sanitize_text_field( $data['Version'] ?? '0.0.0' );
  }
  return $version;
 }

 private static function icons() {
  return array( 'svg' => plugins_url( 'assets/icon.svg', BMA_US_PLUGIN_FILE ) );
 }

 private static function is_asset_api_url( $url ) {
  return is_string( $url ) && 1 === preg_match( '#^https://api\.github\.com/repos/nikunjkgosai-ui/bma-update-scheduler/releases/assets/[1-9][0-9]*$#D', $url );
 }

 private static function is_trusted_download_url( $url ) {
  if ( ! is_string( $url ) || ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) { return false; }
  $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
  return 'github.com' === $host || self::ends_with( $host, '.github.com' ) || self::ends_with( $host, '.githubusercontent.com' );
 }

 private static function ends_with( $value, $suffix ) {
  return '' === $suffix || ( strlen( $value ) >= strlen( $suffix ) && substr( $value, -strlen( $suffix ) ) === $suffix );
 }
}
