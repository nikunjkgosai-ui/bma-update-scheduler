<?php
/**
 * GitHub release update support.
 *
 * @package BMA_Update_Scheduler
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BMA_US_Private_Updater {
 const UPDATE_URI = 'https://github.com/nikunjkgosai-ui/bma-update-scheduler';
 const API_ROOT = 'https://api.github.com/repos/nikunjkgosai-ui/bma-update-scheduler';
 const CACHE_KEY = 'bma_us_github_release_4';
 const CACHE_TTL = 21600;

 public static function boot() {
  add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
  add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
  add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_release_cache' ), 10, 0 );
  add_action( 'admin_post_bma_us_check_private_updates', array( __CLASS__, 'check_now' ) );
  add_action( 'admin_notices', array( __CLASS__, 'check_notice' ) );
  add_action( 'network_admin_notices', array( __CLASS__, 'check_notice' ) );
 }

 public static function clear_release_cache() {
  delete_site_transient( self::CACHE_KEY );
 }

 public static function check_now_url() {
  $url = add_query_arg(
   array(
    'action'      => 'bma_us_check_private_updates',
    'bma_network' => is_network_admin() ? 1 : 0,
   ),
   admin_url( 'admin-post.php' )
  );
  return wp_nonce_url( $url, 'bma_us_check_private_updates' );
 }

 public static function check_now() {
  if ( ! current_user_can( 'update_plugins' ) ) {
   wp_die( 'You do not have permission to check for plugin updates.' );
  }
  check_admin_referer( 'bma_us_check_private_updates' );
  $network = isset( $_GET['bma_network'] ) && is_string( $_GET['bma_network'] ) && '1' === sanitize_key( wp_unslash( $_GET['bma_network'] ) );
  self::clear_release_cache();
  delete_site_transient( 'update_plugins' );
  require_once ABSPATH . 'wp-admin/includes/update.php';
  wp_update_plugins();
  $release = self::get_latest_release();
  $status = is_wp_error( $release ) ? 'error' : 'current';
  if ( is_array( $release ) && version_compare( self::installed_version(), $release['version'], '<' ) ) {
   $status = 'available';
  }
  $target = $network ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
  wp_safe_redirect( add_query_arg( 'bma_us_update_check', $status, $target ) );
  exit;
 }

 public static function check_notice() {
  if ( ! current_user_can( 'update_plugins' ) || empty( $_GET['bma_us_update_check'] ) || ! is_string( $_GET['bma_us_update_check'] ) ) {
   return;
  }
  $status = sanitize_key( wp_unslash( $_GET['bma_us_update_check'] ) );
  if ( 'available' === $status ) {
   echo '<div class="notice notice-success is-dismissible"><p><strong>BMA Update Scheduler:</strong> A newer version is available below.</p></div>';
  } elseif ( 'current' === $status ) {
   echo '<div class="notice notice-success is-dismissible"><p><strong>BMA Update Scheduler:</strong> You are using the latest version.</p></div>';
  } elseif ( 'error' === $status ) {
   echo '<div class="notice notice-error is-dismissible"><p><strong>BMA Update Scheduler:</strong> The GitHub release could not be checked. Verify that the site can connect to GitHub and try again.</p></div>';
  }
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
  $changelog = '' !== $release['body'] ? nl2br( esc_html( $release['body'] ) ) : '<p>See the GitHub release for this version.</p>';
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

  $response = wp_safe_remote_get(
   self::API_ROOT . '/releases/latest',
   array(
    'headers'     => self::api_headers(),
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

  $tag = isset( $data['tag_name'] ) ? sanitize_text_field( $data['tag_name'] ) : '';
  $version = ltrim( $tag, "vV" );
  if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
   return new WP_Error( 'bma_us_github_version_invalid', 'The GitHub release tag is not a supported version.' );
  }

  $expected_asset = 'bma-update-scheduler-' . $version . '.zip';
  $package = '';
  foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
   if ( ! is_array( $asset ) || $expected_asset !== ( $asset['name'] ?? '' ) ) { continue; }
   $candidate = isset( $asset['browser_download_url'] ) ? esc_url_raw( $asset['browser_download_url'], array( 'https' ) ) : '';
   if ( self::is_release_download_url( $candidate, $tag, $expected_asset ) ) { $package = $candidate; break; }
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

 private static function api_headers() {
  return array(
   'Accept'               => 'application/vnd.github+json',
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

 private static function is_release_download_url( $url, $tag, $asset ) {
  if ( ! is_string( $url ) || ! is_string( $tag ) || ! is_string( $asset ) || ! wp_http_validate_url( $url ) ) { return false; }
  $expected = 'https://github.com/nikunjkgosai-ui/bma-update-scheduler/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( $asset );
  return hash_equals( $expected, $url );
 }
}
