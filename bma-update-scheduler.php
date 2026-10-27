<?php
/**
 * Plugin Name: BMA Update Scheduler
 * Description: Manual and scheduled WordPress updates with an existing-backup safety gate, history, health checks and email alerts.
 * Version: 1.0.6
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Branding Marketing Agency
 * Update URI: https://github.com/nikunjkgosai-ui/bma-update-scheduler
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: bma-update-scheduler
 */

/*
 * BMA Update Scheduler is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see https://www.gnu.org/licenses/.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'BMA_US_PLUGIN_FILE', __FILE__ );
define( 'BMA_US_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/private-updater.php';
BMA_US_Private_Updater::boot();

final class BMA_US_Plugin {
 private static $loaded = false;
 private static $conflict = false;

 public static function boot() {
  // Wait until snippets and other init callbacks have run before declaring legacy functions.
  add_action( 'wp_loaded', array( __CLASS__, 'load_engine' ), PHP_INT_MAX );
  add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
  add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
  add_filter( 'plugin_action_links_' . BMA_US_PLUGIN_BASENAME, array( __CLASS__, 'links' ) );
  register_activation_hook( BMA_US_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
  register_deactivation_hook( BMA_US_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
 }

 public static function load_engine() {
  if ( self::$loaded ) { return true; }
  if ( function_exists( 'bma_us_run_updates' ) || defined( 'BMA_US_OPTION' ) ) {
   self::$conflict = true;
   return false;
  }
  define( 'BMA_US_PLUGIN_LOADING', true );
  require_once __DIR__ . '/includes/scheduler.php';
  self::$loaded = true;
  // The engine's original init hook has already passed in this bootstrap.
  bma_us_remove_legacy_backup_jobs();
  return true;
 }

 public static function notice() {
  if ( ! current_user_can( 'activate_plugins' ) ) { return; }
  if ( self::$conflict ) {
   echo '<div class="notice notice-error"><p><strong>BMA Update Scheduler:</strong> The standalone plugin is paused because another BMA scheduler implementation is loaded. Disable the old BMA Code Snippets entry, then reload this page. Do not run both versions together.</p></div>';
  }
  $error = get_option( 'bma_us_plugin_lifecycle_error', '' );
  if ( is_string( $error ) && '' !== $error ) {
   echo '<div class="notice notice-error"><p><strong>BMA Update Scheduler:</strong> ' . esc_html( $error ) . '</p></div>';
  }
 }

 public static function links( $links ) {
  if ( current_user_can( 'update_core' ) ) {
   array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=bma-update-scheduler' ) ) . '">Settings</a>' );
  }
  return $links;
 }

 public static function activate( $network_wide ) {
  if ( ! self::load_engine() ) {
   wp_die( 'Disable the existing BMA Update Scheduler snippet before activating this plugin. Your settings and history will be retained.', 'BMA Update Scheduler', array( 'back_link' => true ) );
  }
  if ( bma_us_get_lock() ) {
   wp_die( 'BMA cannot confirm that updates are idle. Wait for any update to finish and confirm that your database supports advisory locks, then activate again.', 'BMA Update Scheduler', array( 'back_link' => true ) );
  }
  self::each_site( (bool) $network_wide, 'activate_site' );
 }

 public static function activate_site() {
  delete_option( 'bma_us_plugin_lifecycle_error' );
  $settings = bma_us_get_settings();
  // Reuse existing events and the previously authorized scheduler owner.
  if ( ! empty( $settings['enabled'] ) && ! wp_next_scheduled( BMA_US_CRON_HOOK ) ) {
   if ( ! bma_us_reschedule_event( $settings ) ) {
    update_option( 'bma_us_plugin_lifecycle_error', 'Activation completed, but the scheduled event could not be restored. Open Tools > BMA Update Scheduler and save the schedule again.', false );
   }
  }
 }

 public static function deactivate( $network_wide ) {
  // If the legacy snippet owns the engine, its events must not be removed here.
  if ( ! self::load_engine() ) { return; }
  if ( bma_us_get_lock() ) {
   wp_die( 'An update is running or its lock cannot be checked. Wait until it finishes before deactivating BMA Update Scheduler.', 'BMA Update Scheduler', array( 'back_link' => true ) );
  }
  self::each_site( (bool) $network_wide, 'deactivate_site' );
 }

 public static function deactivate_site() {
  $errors = array();
  foreach ( array( BMA_US_CRON_HOOK, 'bma_us_verify_scheduled_backup_event' ) as $hook ) {
   $result = wp_unschedule_hook( $hook, true );
   if ( is_wp_error( $result ) || false === $result ) { $errors[] = $hook; }
  }
  if ( $errors ) {
   update_option( 'bma_us_plugin_lifecycle_error', 'Cron cleanup could not complete for: ' . implode( ', ', $errors ) . '. These events have no plugin worker while deactivated. Review them before reactivation.', false );
  } else { delete_option( 'bma_us_plugin_lifecycle_error' ); }
  delete_transient( 'bma_us_pending_backup_update' );
  delete_option( 'bma_us_scheduled_pending_backup' );
  // Keep settings, authorization, notification address, reports and all UpdraftPlus data.
 }

 private static function each_site( $network_wide, $method ) {
  if ( ! is_multisite() || ! $network_wide ) { call_user_func( array( __CLASS__, $method ) ); return; }
  $offset = 0;
  do {
   $ids = get_sites( array( 'network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
   foreach ( $ids as $id ) {
    switch_to_blog( (int) $id );
    try { call_user_func( array( __CLASS__, $method ) ); }
    finally { restore_current_blog(); }
   }
   $offset += count( $ids );
  } while ( count( $ids ) === 100 );
 }
}
BMA_US_Plugin::boot();
