<?php
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
// Loaded only by the standalone plugin bootstrap.
if ( ! defined( 'ABSPATH' ) || ! defined( 'BMA_US_PLUGIN_LOADING' ) ) {
    exit;
    
}

/** BMA Update Scheduler - Stage 4D.1, existing-backup gate and email alerts. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
    
}
define( 'BMA_US_OPTION', 'bma_us_settings' );
define( 'BMA_US_CRON_HOOK', 'bma_us_scheduled_update_event' );
define( 'BMA_US_LOCK', 'bma_us_update_lock' );
if ( ! defined( 'BMA_US_MAX_BACKUP_AGE' ) ) {
    define( 'BMA_US_MAX_BACKUP_AGE', 30 * DAY_IN_SECONDS );
    
}
add_action( 'admin_menu', 'bma_us_add_admin_menu' );


function bma_us_add_admin_menu() {
    add_management_page( 'BMA Update Scheduler', 'BMA Update Scheduler', 'update_core', 'bma-update-scheduler', 'bma_us_render_dashboard' );
    
}


function bma_us_load_update_files() {
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    
}


function bma_us_refresh_updates() {
    bma_us_load_update_files();
    wp_version_check();
    wp_update_plugins();
    wp_update_themes();
    
}


function bma_us_get_settings() {
    $defaults = array( 'enabled' => 0, 'plugins' => 1, 'themes' => 1, 'core' => 0, 'frequency' => 'weekly', 'day' => 'sunday', 'time' => '02:00', 'selected_plugins' => array(), 'selected_themes' => array(), 'notification_email' => '', );
    $saved = get_option( BMA_US_OPTION, array() );
    return wp_parse_args( $saved, $defaults );
    
}


function bma_us_is_protected_plugin( $plugin_file ) {
    if ( BMA_US_PLUGIN_BASENAME === $plugin_file ) {
        return true;
        
    }
    if ( false !== strpos( strtolower( $plugin_file ), 'code-snippets' ) ) {
        return true;
        
    }
    return false;
    
}


function bma_us_get_lock() {
    global $wpdb;
    if ( get_transient( BMA_US_LOCK ) ) {
        return true;
        
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-owned advisory locks require live uncached database operations; the options/cache APIs cannot provide these semantics.
    $free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', bma_us_lock_name() ) );
    return '1' !== (string) $free;
    
}

function bma_us_lock_name() {
    global $wpdb;
    return 'bma_us_' . md5( DB_NAME . '|' . $wpdb->base_prefix );
    
}

function bma_us_check_lock_ownership( $response ) {
    global $wpdb;
    if ( empty( $GLOBALS['bma_us_owned_lock'] ) ) {
        return $response;
        
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-owned advisory locks require live uncached database operations; the options/cache APIs cannot provide these semantics.
    $owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $GLOBALS['bma_us_owned_lock'] ) );
    if ( '1' !== (string) $owned ) {
        return new WP_Error( 'bma_lock_lost', 'Update stopped because database lock ownership was lost.' );
        
    }
    return $response;
    
}


function bma_us_set_lock() {
    global $wpdb;
    if ( ! empty( $GLOBALS['bma_us_owned_lock'] ) || get_transient( BMA_US_LOCK ) ) {
        return false;
        
    }
    $name = bma_us_lock_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-owned advisory locks require live uncached database operations; the options/cache APIs cannot provide these semantics.
    $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
    if ( '1' !== (string) $acquired ) {
        return false;
        
    }
    $GLOBALS['bma_us_owned_lock'] = $name;
    add_filter( 'upgrader_pre_install', 'bma_us_check_lock_ownership', PHP_INT_MAX );
    register_shutdown_function( 'bma_us_release_lock' );
    return true;
    
}


function bma_us_release_lock() {
    global $wpdb;
    if ( empty( $GLOBALS['bma_us_owned_lock'] ) ) {
        return;
        
    }
    $name = $GLOBALS['bma_us_owned_lock'];
    unset( $GLOBALS['bma_us_owned_lock'] );
    remove_filter( 'upgrader_pre_install', 'bma_us_check_lock_ownership', PHP_INT_MAX );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Session-owned advisory locks require live uncached database operations; the options/cache APIs cannot provide these semantics.
    $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    
}


function bma_us_calculate_next_run( $settings ) {
    $timezone = wp_timezone();
    $now = new DateTimeImmutable( 'now', $timezone );
    $time_parts = explode( ':', $settings['time'] );
    $hour = isset( $time_parts[0] ) ? absint( $time_parts[0] ) : 2;
    $minute = isset( $time_parts[1] ) ? absint( $time_parts[1] ) : 0;
    $hour = min( 23, $hour );
    $minute = min( 59, $minute );
    if ( 'daily' === $settings['frequency'] ) {
        $next = $now->setTime( $hour, $minute, 0 );
        if ( $next <= $now ) {
            $next = $next->modify( '+1 day' );
            
        }
        return $next->getTimestamp();
        
    }
    $allowed_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', );
    $day = strtolower( $settings['day'] );
    if ( ! in_array( $day, $allowed_days, true ) ) {
        $day = 'sunday';
        
    }
    $today = strtolower( $now->format( 'l' ) );
    if ( $today === $day ) {
        $next = $now->setTime( $hour, $minute, 0 );
        if ( $next <= $now ) {
            $next = $next->modify( '+1 week' );
            
        }
        
    }
    else {
        $next = $now ->modify( 'next ' . $day ) ->setTime( $hour, $minute, 0 );
        
    }
    return $next->getTimestamp();
    
}


function bma_us_reschedule_event( $settings ) {
    
 $old = wp_next_scheduled( BMA_US_CRON_HOOK );
    
 if ( empty( $settings['enabled'] ) ) {
        $result = wp_clear_scheduled_hook( BMA_US_CRON_HOOK, array(), true );
        
    }
    
 else {
        $timestamp = bma_us_calculate_next_run( $settings );
        $result = $timestamp ? ( (int) $old === (int) $timestamp ? true : wp_schedule_single_event( $timestamp, BMA_US_CRON_HOOK, array(), true ) ) : new WP_Error( 'bma_schedule_time', 'The next scheduled time could not be calculated.' );
        
  if ( true === $result && $old && (int) $old !== (int) $timestamp ) {
            $removed = wp_unschedule_event( $old, BMA_US_CRON_HOOK, array(), true );
            if ( is_wp_error( $removed ) || false === $removed ) {
                wp_unschedule_event( $timestamp, BMA_US_CRON_HOOK, array(), true );
                $result = new WP_Error( 'bma_schedule_replace', 'The previous event could not be removed. Review the schedule before retrying.' );
                
            }
            
        }
        
 
    }
    
 if ( is_wp_error( $result ) || false === $result ) {
        $message = is_wp_error( $result ) ? $result->get_error_message() : 'WordPress could not save the scheduled event.';
        $previous = get_option( 'bma_us_schedule_error', '' );
        update_option( 'bma_us_schedule_error', $message, false );
        if ( $previous !== $message ) {
            bma_us_send_update_alert( array( 'failed' => 1, 'details' => array( array( 'type' => 'schedule', 'item' => 'Next scheduled run', 'status' => 'failed', 'message' => $message ) ) ), 'scheduled' );
            
        }
        return false;
        
    }
    
 delete_option( 'bma_us_schedule_error' );
    return true;
    

}
add_action( 'admin_post_bma_us_save_schedule', 'bma_us_save_schedule' );


function bma_us_save_schedule() {
    if ( ! current_user_can( 'update_core' ) ) {
        wp_die( 'You do not have permission to perform this action.' );
        
    }
    check_admin_referer( 'bma_us_save_schedule' );
    foreach ( array( 'plugins' => 'update_plugins', 'themes' => 'update_themes', 'core' => 'update_core' ) as $field => $capability ) {
        if ( isset( $_POST[ $field ] ) && ! current_user_can( $capability ) ) {
            wp_die( 'You do not have permission to schedule the selected update categories.' );
            
        }
        
    }
    $email_input = isset( $_POST['notification_email'] ) && is_string( $_POST['notification_email'] ) ? sanitize_text_field( wp_unslash( $_POST['notification_email'] ) ) : '';
    if ( '' !== $email_input && ! is_email( $email_input ) ) {
        wp_die( 'Please enter a valid notification email address, or leave it blank to disable alerts.' );
        
    }
    $notification_email = sanitize_email( $email_input );
    $frequency = isset( $_POST['frequency'] ) && is_string( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'weekly';
    if ( ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
        $frequency = 'weekly';
        
    }
    $day = isset( $_POST['day'] ) && is_string( $_POST['day'] ) ? sanitize_key( wp_unslash( $_POST['day'] ) ) : 'sunday';
    $allowed_days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', );
    if ( ! in_array( $day, $allowed_days, true ) ) {
        $day = 'sunday';
        
    }
    $time = isset( $_POST['time'] ) && is_string( $_POST['time'] ) ? sanitize_text_field( wp_unslash( $_POST['time'] ) ) : '02:00';
    if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
        $time = '02:00';
        
    }
    $selected_plugins = array();
    if ( isset( $_POST['selected_plugins'] ) && is_array( $_POST['selected_plugins'] ) ) {
        foreach ( map_deep( wp_unslash( $_POST['selected_plugins'] ), 'sanitize_text_field' ) as $plugin_file ) {
            if ( ! is_string( $plugin_file ) ) {
                continue;
                
            }
            $plugin_file = sanitize_text_field( $plugin_file );
            if ( ! bma_us_is_protected_plugin( $plugin_file ) ) {
                $selected_plugins[] = $plugin_file;
                
            }
            
        }
        
    }
    $selected_themes = array();
    if ( isset( $_POST['selected_themes'] ) && is_array( $_POST['selected_themes'] ) ) {
        foreach ( map_deep( wp_unslash( $_POST['selected_themes'] ), 'sanitize_text_field' ) as $theme ) {
            if ( ! is_string( $theme ) ) {
                continue;
                
            }
            $theme = sanitize_text_field( $theme );
            if ( $theme !== '' ) {
                $selected_themes[] = $theme;
                
            }
            
        }
        
    }
    $settings = array( 'scheduled_by' => get_current_user_id(), 'notification_email' => $notification_email, 'enabled' => isset( $_POST['enabled'] ) ? 1 : 0, 'plugins' => isset( $_POST['plugins'] ) ? 1 : 0, 'themes' => isset( $_POST['themes'] ) ? 1 : 0, 'core' => isset( $_POST['core'] ) ? 1 : 0, 'frequency' => $frequency, 'day' => $day, 'time' => $time, 'selected_plugins' => array_values( array_unique( $selected_plugins ) ), 'selected_themes' => array_values( array_unique( $selected_themes ) ), );
    $saved_schedule = bma_us_reschedule_event( $settings );
    if ( $saved_schedule ) {
        update_option( BMA_US_OPTION, $settings, false );
        
    }
    bma_us_notice_redirect( add_query_arg( 'bma_notice', $saved_schedule ? 'schedule_saved' : 'schedule_error', admin_url( 'tools.php?page=bma-update-scheduler' ) ) );
    exit;
    
}
add_action( 'admin_post_bma_us_update_now', 'bma_us_update_selected_now' );


function bma_us_update_selected_now() {
    if ( ! current_user_can( 'update_core' ) ) {
        wp_die( 'You do not have permission to perform updates.' );
        
    }
    check_admin_referer( 'bma_us_update_now' );
    bma_us_load_update_files();
    $plugins = array();
    if ( isset( $_POST['update_plugins'] ) && is_array( $_POST['update_plugins'] ) ) {
        foreach ( map_deep( wp_unslash( $_POST['update_plugins'] ), 'sanitize_text_field' ) as $plugin_file ) {
            if ( ! is_string( $plugin_file ) ) {
                continue;
                
            }
            $plugin_file = sanitize_text_field( $plugin_file );
            if ( $plugin_file !== '' && ! bma_us_is_protected_plugin( $plugin_file ) ) {
                $plugins[] = $plugin_file;
                
            }
            
        }
        
    }
    $themes = array();
    if ( isset( $_POST['update_themes'] ) && is_array( $_POST['update_themes'] ) ) {
        foreach ( map_deep( wp_unslash( $_POST['update_themes'] ), 'sanitize_text_field' ) as $theme ) {
            if ( ! is_string( $theme ) ) {
                continue;
                
            }
            $theme = sanitize_text_field( $theme );
            if ( $theme !== '' ) {
                $themes[] = $theme;
                
            }
            
        }
        
    }
    $plugins = array_values( array_unique( $plugins ) );
    $themes = array_values( array_unique( $themes ) );
    if ( empty( $plugins ) && empty( $themes ) ) {
        bma_us_notice_redirect( add_query_arg( 'bma_notice', 'nothing_selected', admin_url( 'tools.php?page=bma-update-scheduler' ) ) );
        exit;
        
    }
    $results = bma_us_execute_guarded_updates( $plugins, $themes, false, 'manual' );
    bma_us_notice_redirect( add_query_arg( array( 'bma_notice' => ! empty( $results['blocked'] ) ? 'updates_blocked' : 'updates_finished', 'bma_blocked' => $results['blocked'] ?? 0, 'bma_ok' => $results['success'] ?? 0, 'bma_failed' => $results['failed'] ?? 0, 'bma_skipped' => $results['skipped'] ?? 0, ), admin_url( 'tools.php?page=bma-update-scheduler' ) ) );
    exit;
    
}
define( 'BMA_US_HISTORY_OPTION', 'bma_us_update_history' );
define( 'BMA_US_HISTORY_LIMIT', 200 );
define( 'BMA_US_LAST_RUN_OPTION', 'bma_us_last_run' );


function bma_us_get_history() {
    $history = get_option( BMA_US_HISTORY_OPTION, array() );
    if ( ! is_array( $history ) ) {
        $history = array();
        
    }
    return $history;
    
}


function bma_us_get_component_name( $type, $item ) {
    bma_us_load_update_files();
    if ( 'plugin' === $type ) {
        $plugins = get_plugins();
        if ( isset( $plugins[ $item ] ) && ! empty( $plugins[ $item ]['Name'] ) ) {
            return $plugins[ $item ]['Name'];
            
        }
        return $item;
        
    }
    if ( 'theme' === $type ) {
        $theme = wp_get_theme( $item );
        if ( $theme->exists() ) {
            $name = $theme->get( 'Name' );
            if ( $name ) {
                return $name;
                
            }
            
        }
        return $item;
        
    }
    if ( 'core' === $type ) {
        return 'WordPress';
        
    }
    return $item;
    
}


function bma_us_add_history_record( $record ) {
    $defaults = array( 'timestamp' => time(), 'component' => '', 'item' => '', 'type' => '', 'old_version' => '', 'new_version' => '', 'trigger' => 'manual', 'status' => '', 'message' => '', );
    $record = wp_parse_args( $record, $defaults );
    $record['timestamp'] = absint( $record['timestamp'] );
    $record['component'] = sanitize_text_field( $record['component'] );
    $record['item'] = sanitize_text_field( $record['item'] );
    $record['type'] = sanitize_key( $record['type'] );
    $record['old_version'] = sanitize_text_field( $record['old_version'] );
    $record['new_version'] = sanitize_text_field( $record['new_version'] );
    $record['trigger'] = sanitize_key( $record['trigger'] );
    $record['status'] = sanitize_key( $record['status'] );
    $record['message'] = sanitize_text_field( $record['message'] );
    $history = bma_us_get_history();
    array_unshift( $history, $record );
    if ( count( $history ) > BMA_US_HISTORY_LIMIT ) {
        $history = array_slice( $history, 0, BMA_US_HISTORY_LIMIT );
        
    }
    update_option( BMA_US_HISTORY_OPTION, $history, false );
    
}


function bma_us_record_update_results( $results, $trigger = 'manual' ) {
    if ( empty( $results['details'] ) || ! is_array( $results['details'] ) ) {
        return;
        
    }
    foreach ( $results['details'] as $detail ) {
        $type = isset( $detail['type'] ) ? sanitize_key( $detail['type'] ) : '';
        $item = isset( $detail['item'] ) ? sanitize_text_field( $detail['item'] ) : '';
        $component = bma_us_get_component_name( $type, $item );
        bma_us_add_history_record( array( 'timestamp' => time(), 'component' => $component, 'item' => $item, 'type' => $type, 'old_version' => isset( $detail['old_version'] ) ? $detail['old_version'] : '', 'new_version' => isset( $detail['new_version'] ) ? $detail['new_version'] : '', 'trigger' => $trigger, 'status' => isset( $detail['status'] ) ? $detail['status'] : '', 'message' => isset( $detail['message'] ) ? $detail['message'] : '', ) );
        
    }
    
}


function bma_us_save_last_run( $results, $trigger = 'manual' ) {
    $last_run = array( 'timestamp' => time(), 'trigger' => sanitize_key( $trigger ), 'success' => isset( $results['success'] ) ? absint( $results['success'] ) : 0, 'failed' => isset( $results['failed'] ) ? absint( $results['failed'] ) : 0, 'skipped' => isset( $results['skipped'] ) ? absint( $results['skipped'] ) : 0, 'blocked' => isset( $results['blocked'] ) ? absint( $results['blocked'] ) : 0, );
    update_option( BMA_US_LAST_RUN_OPTION, $last_run, false );
    
}


function bma_us_process_update_reporting( $results, $trigger = 'manual' ) {
    update_option( BMA_US_RESULT_CONTEXT_OPTION, array( 'fingerprint' => md5( serialize( $results ) ), 'trigger' => $trigger, 'checked' => time(), ), false );
    bma_us_record_update_results( $results, $trigger );
    bma_us_save_last_run( $results, $trigger );
    
}
define( 'BMA_US_MIN_FREE_SPACE', 100 * MB_IN_BYTES );
define( 'BMA_US_LAST_SAFETY_CHECK', 'bma_us_last_safety_check' );


function bma_us_run_safety_checks( $plugins = array(), $themes = array(), $update_core = false ) {
    bma_us_load_update_files();
    $result = array( 'passed' => true, 'blockers' => array(), 'warnings' => array(), );
    if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
        $result['blockers'][] = 'WordPress file modification policy currently forbids updates.';
        
    }
    $maintenance_file = ABSPATH . '.maintenance';
    if ( file_exists( $maintenance_file ) ) {
        $result['blockers'][] = 'WordPress is currently in maintenance mode.';
        
    }
    $filesystem_method = get_filesystem_method();
    if ( empty( $filesystem_method ) ) {
        $result['blockers'][] = 'WordPress could not determine a filesystem method.';
        
    }
    if ( 'direct' === $filesystem_method && ! wp_is_writable( WP_CONTENT_DIR ) ) {
        $result['blockers'][] = 'The wp-content directory is not writable.';
        
    }
    $free_space = false;
    if ( function_exists( 'disk_free_space' ) && is_dir( WP_CONTENT_DIR ) ) {
        $free_space = @disk_free_space( WP_CONTENT_DIR );
        
    }
    if ( false !== $free_space && is_numeric( $free_space ) ) {
        if ( $free_space < BMA_US_MIN_FREE_SPACE ) {
            $result['blockers'][] = sprintf( 'Low disk space. Only %s is available. At least %s is required before updates.', size_format( $free_space ), size_format( BMA_US_MIN_FREE_SPACE ) );
            
        }
        
    }
    else {
        $result['warnings'][] = 'Available disk space could not be determined.';
        
    }
    if ( ! empty( $plugins ) ) {
        $installed_plugins = get_plugins();
        $plugin_updates = get_site_transient( 'update_plugins' );
        foreach ( $plugins as $plugin_file ) {
            if ( ! is_string( $plugin_file ) ) {
                continue;
                
            }
            $plugin_file = sanitize_text_field( $plugin_file );
            if ( bma_us_is_protected_plugin( $plugin_file ) ) {
                $result['blockers'][] = sprintf( 'The protected plugin "%s" cannot be updated by BMA Update Scheduler.', bma_us_get_component_name( 'plugin', $plugin_file ) );
                continue;
                
            }
            if ( ! isset( $installed_plugins[ $plugin_file ] ) ) {
                $result['blockers'][] = sprintf( 'Plugin "%s" could not be found.', $plugin_file );
                continue;
                
            }
            if ( ! is_object( $plugin_updates ) || empty( $plugin_updates ->response[ $plugin_file ] ) ) {
                $result['blockers'][] = sprintf( 'The update for "%s" is no longer available.', bma_us_get_component_name( 'plugin', $plugin_file ) );
                continue;
                
            }
            $update_data = $plugin_updates ->response[ $plugin_file ];
            if ( empty( $update_data->package ) ) {
                $result['warnings'][] = sprintf( 'No update package URL is currently exposed for "%s". The plugin updater may supply it later.', bma_us_get_component_name( 'plugin', $plugin_file ) );
                
            }
            
        }
        
    }
    if ( ! empty( $themes ) ) {
        $theme_updates = get_site_transient( 'update_themes' );
        foreach ( $themes as $stylesheet ) {
            $stylesheet = sanitize_text_field( $stylesheet );
            $theme = wp_get_theme( $stylesheet );
            if ( ! $theme->exists() ) {
                $result['blockers'][] = sprintf( 'Theme "%s" could not be found.', $stylesheet );
                continue;
                
            }
            if ( ! is_object( $theme_updates ) || empty( $theme_updates ->response[ $stylesheet ] ) ) {
                $result['blockers'][] = sprintf( 'The update for "%s" is no longer available.', bma_us_get_component_name( 'theme', $stylesheet ) );
                continue;
                
            }
            $update_data = $theme_updates ->response[ $stylesheet ];
            if ( empty( $update_data['package'] ) ) {
                $result['warnings'][] = sprintf( 'No update package URL is currently exposed for "%s". The theme updater may supply it later.', bma_us_get_component_name( 'theme', $stylesheet ) );
                
            }
            
        }
        
    }
    if ( $update_core ) {
        $core_updates = get_site_transient( 'update_core' );
        $core_offer = false;
        if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) ) {
            foreach ( $core_updates->updates as $update ) {
                if ( isset( $update->response ) && 'upgrade' === $update->response ) {
                    $core_offer = $update;
                    break;
                    
                }
                
            }
            
        }
        if ( ! $core_offer ) {
            $result['blockers'][] = 'The WordPress core update is no longer available.';
            
        }
        
    }
    if ( ! empty( $result['blockers'] ) ) {
        $result['passed'] = false;
        
    }
    set_transient( BMA_US_LAST_SAFETY_CHECK, $result, 30 * MINUTE_IN_SECONDS );
    return $result;
    
}


function bma_us_create_blocked_result( $safety, $plugins = array(), $themes = array(), $update_core = false ) {
    $results = array( 'success' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0, 'details' => array(), );
    $message = ! empty( $safety['blockers'] ) ? implode( ' ', $safety['blockers'] ) : 'Update blocked by a pre-update safety check.';
    foreach ( $plugins as $plugin_file ) {
        $results['blocked']++;
        $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'blocked', 'message' => $message, );
        
    }
    foreach ( $themes as $stylesheet ) {
        $results['blocked']++;
        $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'blocked', 'message' => $message, );
        
    }
    if ( $update_core ) {
        $results['blocked']++;
        $results['details'][] = array( 'type' => 'core', 'item' => 'wordpress', 'status' => 'blocked', 'message' => $message, );
        
    }
    return $results;
    
}


function bma_us_detect_backup_system() {
    bma_us_load_update_files();
    $plugins = get_plugins();
    $result = array( 'provider' => '', 'provider_name' => '', 'installed' => false, 'active' => false, 'history_detected' => false, 'latest_backup' => 0, 'backup_age' => 0, 'backup_count' => 0, 'status' => 'not_detected', 'status_label' => 'No Backup System Detected', 'message' => '', );
    $updraft_plugin_files = array( 'updraftplus/updraftplus.php', 'updraftplus-premium/updraftplus.php', );
    $updraft_file = '';
    foreach ( $updraft_plugin_files as $plugin_file ) {
        if ( isset( $plugins[ $plugin_file ] ) ) {
            $updraft_file = $plugin_file;
            break;
            
        }
        
    }
    if ( ! $updraft_file ) {
        foreach ( $plugins as $plugin_file => $plugin_data ) {
            $name = isset( $plugin_data['Name'] ) ? strtolower( $plugin_data['Name'] ) : '';
            if ( false !== strpos( $name, 'updraftplus' ) ) {
                $updraft_file = $plugin_file;
                break;
                
            }
            
        }
        
    }
    if ( $updraft_file ) {
        $result['provider'] = 'updraftplus';
        $result['provider_name'] = 'UpdraftPlus';
        $result['installed'] = true;
        $result['active'] = is_plugin_active( $updraft_file );
        $history = bma_us_get_updraft_option( 'updraft_backup_history', array() );
        if ( is_array( $history ) && ! empty( $history ) ) {
            $result['history_detected'] = true;
            $result['backup_count'] = count( $history );
            $timestamps = array();
            foreach ( $history as $key => $backup ) {
                if ( is_numeric( $key ) && absint( $key ) > 0 ) {
                    $timestamps[] = absint( $key );
                    
                }
                if ( is_array( $backup ) && ! empty( $backup['timestamp'] ) ) {
                    $timestamps[] = absint( $backup['timestamp'] );
                    
                }
                
            }
            if ( ! empty( $timestamps ) ) {
                $result['latest_backup'] = max( $timestamps );
                $result['backup_age'] = max( 0, time() - $result['latest_backup'] );
                
            }
            
        }
        if ( ! $result['active'] ) {
            $result['status'] = 'inactive';
            $result['status_label'] = 'Attention Required';
            $result['message'] = 'UpdraftPlus is installed but is not currently active.';
            
        }
        elseif ( ! $result['history_detected'] ) {
            $result['status'] = 'unverified';
            $result['status_label'] = 'Backup Not Verified';
            $result['message'] = 'UpdraftPlus is active, but BMA Update Scheduler could not confirm backup history.';
            
        }
        elseif ( empty( $result['latest_backup'] ) ) {
            $result['status'] = 'unverified';
            $result['status_label'] = 'Backup History Detected';
            $result['message'] = 'Backup history was found, but the latest backup time could not be determined.';
            
        }
        else {
            $verification = bma_us_verify_updraft_backup();
            $result['verification'] = $verification;
            $result['status'] = $verification['passed'] ? 'protected' : ( $verification['age'] > BMA_US_MAX_BACKUP_AGE ? 'stale' : 'unverified' );
            $result['status_label'] = $verification['passed'] ? 'Protected' : 'Backup Gate Blocked';
            $result['message'] = $verification['message'];
            
        }
        return $result;
        
    }
    $result['message'] = 'No supported backup plugin was detected.';
    return $result;
    
}


function bma_us_format_backup_age( $seconds ) {
    $seconds = absint( $seconds );
    if ( $seconds < MINUTE_IN_SECONDS ) {
        return 'Less than a minute';
        
    }
    if ( $seconds < HOUR_IN_SECONDS ) {
        $minutes = floor( $seconds / MINUTE_IN_SECONDS );
        return sprintf( '%d minute%s', $minutes, 1 === $minutes ? '' : 's' );
        
    }
    if ( $seconds < DAY_IN_SECONDS ) {
        $hours = floor( $seconds / HOUR_IN_SECONDS );
        return sprintf( '%d hour%s', $hours, 1 === $hours ? '' : 's' );
        
    }
    $days = floor( $seconds / DAY_IN_SECONDS );
    return sprintf( '%d day%s', $days, 1 === $days ? '' : 's' );
    
}


function bma_us_render_backup_safety() {
    $backup = bma_us_detect_backup_system();
    $verification = $backup['verification'] ?? array();
    $status_class = 'bma-backup-neutral';
    switch ( $backup['status'] ) {
        case 'protected': $status_class = 'bma-backup-good';
        break;
        case 'stale': case 'inactive': case 'unverified': $status_class = 'bma-backup-warning';
        break;
        case 'not_detected': $status_class = 'bma-backup-danger';
        break;
        
    }
    ?>

	<div class="bma-section bma-backup-safety-section">
<div class="bma-section-header">
<div>
<h2>
					Update Safety
				</h2>
<p>
					Backup protection status before WordPress updates.
				</p>
</div>
<span
				class="bma-backup-status <?php echo esc_attr( $status_class );
    ?>"
			>
<?php
 echo esc_html( $backup['status_label'] );
    ?>

			</span>
</div>
<div class="bma-backup-safety-grid">
<div class="bma-backup-stat">
<small>
					Backup System
				</small>
<strong>
<?php
 echo esc_html( $backup['provider_name'] ? $backup['provider_name'] : 'Not Detected' );
    ?>

				</strong>
</div>
<div class="bma-backup-stat">
<small>
					Plugin Status
				</small>
<?php if ( $backup['installed'] && $backup['active'] ) : ?>

					<strong class="bma-backup-good-text">
						● Active
					</strong>
<?php elseif ( $backup['installed'] ) : ?>

					<strong class="bma-backup-danger-text">
						● Inactive
					</strong>
<?php else : ?>

					<strong class="bma-backup-neutral-text">
						— Not Detected
					</strong>
<?php endif;
    ?>

			</div>
<div class="bma-backup-stat">
<small>
					Backup History
				</small>
<?php if ( $backup['history_detected'] ) : ?>

					<strong class="bma-backup-good-text">
● Detected
<?php
 if ( ! empty( $backup['backup_count'] ) ) {
        echo ' (' . esc_html( $backup['backup_count'] ) . ')';
        
    }
    ?>

					</strong>
<?php else : ?>

					<strong class="bma-backup-warning-text">
						● Not Verified
					</strong>
<?php endif;
    ?>

			</div>
<div class="bma-backup-stat">
<small>
					Latest Backup
				</small>
<strong>
<?php
 if ( ! empty( $backup['latest_backup'] ) ) {
        echo esc_html( wp_date( 'M j, Y \a\t g:i A', $backup['latest_backup'], wp_timezone() ) );
        
    }
    else {
        echo 'Not verified';
        
    }
    ?>

				</strong>
</div>
<div class="bma-backup-stat">
<small>
					Backup Age
				</small>
<strong>
<?php
 if ( ! empty( $backup['latest_backup'] ) ) {
        echo esc_html( bma_us_format_backup_age( $backup['backup_age'] ) );
        
    }
    else {
        echo '—';
        
    }
    ?>

				</strong>
</div>
<div class="bma-backup-stat">
<small>
					Backup Safety
				</small>
<strong class="<?php echo esc_attr( $status_class );
    ?>">
<?php
 echo esc_html( $backup['status_label'] );
    ?>

				</strong>
</div>
</div>
<div class="bma-backup-message <?php echo esc_attr( $status_class );
    ?>">
<span class="dashicons dashicons-shield"></span>
<div>
<strong>
<?php
 echo esc_html( $backup['status_label'] );
    ?>

				</strong>
<p>
<?php
 echo esc_html( $backup['message'] );
    ?>

				</p>
</div>
</div>
<?php
 if ( ! empty( $verification ) && ! empty( $verification['components'] ) ) : ?>

			<div class="bma-backup-verification">
<div class="bma-backup-verification-header">
<span class="dashicons dashicons-yes-alt"></span>
<div>
<strong>
							Latest Existing Backup Verification
						</strong>
<p>
							<?php
 echo esc_html( $verification['message'] );
    ?>
						</p>
</div>
</div>
<div class="bma-backup-components">
<?php
 foreach ( $verification['components'] as $component ) : ?>

						<div class="bma-backup-component">
<?php
 if ( isset( $component['expected'] ) && ! $component['expected'] ) : echo '<span class="bma-muted">Not expected</span>';
    elseif ( ! empty( $component['verified'] ) ) : ?>

								<span class="bma-component-ok">
									✓
								</span>
<?php else : ?>

								<span class="bma-component-failed">
									✕
								</span>
<?php endif;
    ?>

<strong>
								<?php
 echo esc_html( $component['label'] );
    ?>
							</strong>
</div>
<?php endforeach;
    ?>

				</div>
</div>
<?php endif;
    ?>

<div class="bma-stage-note">
<span class="dashicons dashicons-shield-alt"></span>
<div>
<strong>
			Existing-backup safety gate enabled
		</strong>
<p>
			BMA checks existing UpdraftPlus backups before manual and scheduled updates. Maximum age: <?php echo esc_html( bma_us_format_backup_age( BMA_US_MAX_BACKUP_AGE ) );
    ?>. BMA does not create backups.
		</p>
</div>
</div>
</div>
<?php
 
}


function bma_us_get_latest_updraft_backup_set() {
    $history = bma_us_get_updraft_option( 'updraft_backup_history', array() );
    if ( ! is_array( $history ) || empty( $history ) ) {
        return false;
        
    }
    $latest_timestamp = 0;
    $latest_backup = false;
    foreach ( $history as $key => $backup ) {
        if ( ! is_array( $backup ) ) {
            continue;
            
        }
        $timestamp = 0;
        if ( is_numeric( $key ) && absint( $key ) > 0 ) {
            $timestamp = absint( $key );
            
        }
        if ( ! empty( $backup['timestamp'] ) ) {
            $internal_timestamp = absint( $backup['timestamp'] );
            if ( $internal_timestamp > $timestamp ) {
                $timestamp = $internal_timestamp;
                
            }
            
        }
        if ( $timestamp > $latest_timestamp ) {
            $latest_timestamp = $timestamp;
            $latest_backup = $backup;
            
        }
        
    }
    if ( ! $latest_timestamp || ! is_array( $latest_backup ) ) {
        return false;
        
    }
    return array( 'timestamp' => $latest_timestamp, 'backup' => $latest_backup, );
    
}


function bma_us_updraft_component_exists( $backup, $component ) {
    
 if ( ! is_array( $backup ) || ! in_array( $component, array( 'db', 'plugins', 'themes', 'uploads', 'others' ), true ) || empty( $backup[ $component ] ) ) {
        return false;
        
    }
    
 $remote = false;
    foreach ( (array) ( $backup['service'] ?? array() ) as $service ) {
        if ( is_string( $service ) && '' !== $service && 'none' !== $service ) {
            $remote = true;
            
        }
        
    }
    $directory = bma_us_backup_directory();
    
 $files = is_string( $backup[ $component ] ) ? array( $backup[ $component ] ) : $backup[ $component ];
    
 if ( ! is_array( $files ) || ! $files ) {
        return false;
        
    }
    ksort( $files, SORT_NUMERIC );
    $part = 0;
    $prefix = null;
    
 foreach ( $files as $index => $file ) {
        
  if ( (string) $index !== (string) $part || ! is_string( $file ) || basename( $file ) !== $file || false !== strpos( $file, chr( 92 ) ) || false !== strpos( $file, chr( 0 ) ) ) {
            return false;
            
        }
        
  $suffix = 'db' === $component ? '-db\.gz(?:\.crypt)?' : '-' . $component . ( $part ? (string) ( $part + 1 ) : '' ) . '\.zip(?:\.crypt)?';
        
  if ( ! preg_match( '/^(backup_.+)' . $suffix . '$/D', $file, $matches ) || ( null !== $prefix && $prefix !== $matches[1] ) ) {
            return false;
            
        }
        
  $prefix = $matches[1];
        $size_key = $component . ( $part ? $part : '' ) . '-size';
        
  if ( isset( $backup[ $size_key ] ) && ( ! is_numeric( $backup[ $size_key ] ) || $backup[ $size_key ] <= 0 ) ) {
            return false;
            
        }
        
  $path = $directory ? trailingslashit( $directory ) . $file : '';
        if ( $path && is_file( $path ) ) {
            $actual_size = filesize( $path );
            if ( ! is_readable( $path ) || false === $actual_size || $actual_size <= 0 || ( isset( $backup[ $size_key ] ) && (float) $actual_size !== (float) $backup[ $size_key ] ) ) {
                return false;
                
            }
            
        }
        elseif ( ! $remote || empty( $backup[ $size_key ] ) ) {
            return false;
            
        }
        
  $part++;
        
 
    }
    
 foreach ( array_keys( $backup ) as $key ) {
        if ( preg_match( '/^' . $component . '([0-9]+)-size$/D', (string) $key, $size_match ) && (int) $size_match[1] >= $part ) {
            return false;
            
        }
        
    }
    
 /* Validate recorded parts only: remote archive availability and restoration require an independent restore test. */
 return true;
    

}


function bma_us_backup_directory() {
    
 $directory = bma_us_get_updraft_option( 'updraft_dir', '' );
    if ( ! is_string( $directory ) ) {
        return false;
        
    }
    if ( '' === $directory ) {
        return WP_CONTENT_DIR . '/updraft';
        
    }
    
 $directory = wp_normalize_path( $directory );
    if ( '/' === substr( $directory, 0, 1 ) || preg_match( '/^[A-Za-z]:/', $directory ) ) {
        return $directory;
        
    }
    
 if ( 0 === strpos( $directory, 'wp-content/' ) && ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
        return ABSPATH . $directory;
        
    }
    
 $legacy = ABSPATH . $directory;
    if ( is_dir( $legacy ) && is_file( $legacy . '/index.html' ) && is_file( $legacy . '/.htaccess' ) && ! is_file( $legacy . '/index.php' ) ) {
        return $legacy;
        
    }
    
 return trailingslashit( WP_CONTENT_DIR ) . $directory;
    

}


function bma_us_get_updraft_option( $name, $default = false ) {
    if ( is_callable( array( 'UpdraftPlus_Options', 'get_updraft_option' ) ) ) {
        return UpdraftPlus_Options::get_updraft_option( $name, $default );
        
    }
    return get_option( $name, $default );
    
}


function bma_us_verify_updraft_backup() {
    $result = array( 'passed' => false, 'timestamp' => 0, 'age' => null, 'max_age' => BMA_US_MAX_BACKUP_AGE, 'successful' => false, 'components' => array(), 'missing' => array(), 'message' => '' );
    $latest = bma_us_get_latest_updraft_backup_set();
    if ( ! $latest ) {
        $result['message'] = 'No existing UpdraftPlus backup could be verified.';
        return $result;
        
    }
    $result['timestamp'] = (int) $latest['timestamp'];
    $result['age'] = time() - $result['timestamp'];
    $backup = $latest['backup'];
    $reasons = array();
    if ( $result['age'] < 0 ) {
        $reasons[] = 'The latest backup timestamp is in the future and cannot be trusted.';
        
    }
    elseif ( $result['age'] > BMA_US_MAX_BACKUP_AGE ) {
        $reasons[] = sprintf( 'The latest backup is %s old; the maximum allowed age is %s.', bma_us_format_backup_age( $result['age'] ), bma_us_format_backup_age( BMA_US_MAX_BACKUP_AGE ) );
        
    }
    $last = bma_us_get_updraft_option( 'updraft_last_backup', array() );
    $matches = is_array( $last ) && isset( $last['backup_time'] ) && (int) $last['backup_time'] === $result['timestamp'];
    if ( $matches && ! empty( $backup['nonce'] ) ) {
        $matches = isset( $last['backup_nonce'] ) && $last['backup_nonce'] === $backup['nonce'];
        
    }
    $result['successful'] = $matches && isset( $last['success'] ) && 1 === (int) $last['success'];
    if ( ! $result['successful'] ) {
        $reasons[] = $matches ? 'UpdraftPlus did not mark the latest backup as successful.' : 'A matching successful completion record for the latest backup is unavailable. It may still be running or unverified.';
        
    }
    $components = array( 'db' => 'Database', 'plugins' => 'Plugins', 'themes' => 'Themes', 'uploads' => 'Uploads', 'others' => 'Other Files' );
    foreach ( $components as $key => $label ) {
        $expected = 'db' === $key || (bool) bma_us_get_updraft_option( 'updraft_include_' . $key, true );
        $exists = bma_us_updraft_component_exists( $backup, $key );
        $result['components'][ $key ] = array( 'label' => $label, 'expected' => $expected, 'verified' => $exists );
        if ( $expected && ! $exists ) {
            $result['missing'][] = $label;
            
        }
        
    }
    if ( $result['missing'] ) {
        $reasons[] = 'The latest backup is incomplete. Missing or invalid expected components: ' . implode( ', ', $result['missing'] ) . '.';
        
    }
    $result['passed'] = ! $reasons;
    $result['message'] = $reasons ? implode( ' ', $reasons ) : 'The latest backup has a matching successful completion record, is within the allowed age, and lists valid expected archive components. Local archives are checked for readability and size; remote archives rely on UpdraftPlus records. Restorability is not verified.';
    return $result;
    
}


function bma_us_check_existing_backup() {
    $backup = bma_us_detect_backup_system();
    if ( 'updraftplus' !== $backup['provider'] || empty( $backup['active'] ) ) {
        $verification = array( 'passed' => false, 'components' => array(), 'message' => 'An active UpdraftPlus installation is required before updates.' );
        
    }
    else {
        $verification = $backup['verification'] ?? bma_us_verify_updraft_backup();
        
    }
    set_transient( 'bma_us_last_backup_verification', $verification, 30 * MINUTE_IN_SECONDS );
    return array( 'passed' => ! empty( $verification['passed'] ), 'blockers' => empty( $verification['passed'] ) ? array( $verification['message'] ) : array(), 'warnings' => array() );
    
}


function bma_us_execute_guarded_updates( $plugins, $themes, $core, $trigger ) {
    
 $health = null;
    $locked = false;
    $safety = array( 'passed' => true, 'blockers' => array(), 'warnings' => array() );
    
 $settings = bma_us_get_settings();
    $user_id = 'scheduled' === $trigger ? absint( $settings['scheduled_by'] ?? 0 ) : get_current_user_id();
    
 foreach ( array( 'update_plugins' => ! empty( $plugins ), 'update_themes' => ! empty( $themes ), 'update_core' => (bool) $core ) as $capability => $needed ) {
        if ( $needed && ( ! $user_id || ! user_can( $user_id, $capability ) ) ) {
            $safety['blockers'][] = 'The requesting user is missing permission: ' . $capability . '. For scheduled updates, an authorized administrator must save the schedule again.';
            
        }
        
    }
    
 try {
        
  if ( ! $safety['blockers'] ) {
            $locked = bma_us_set_lock();
            if ( ! $locked ) {
                $safety['blockers'][] = 'Another BMA update is running, or the database could not grant the required update lock.';
                
            }
            
        }
        
  if ( ! $safety['blockers'] ) {
            $safety = bma_us_run_safety_checks( $plugins, $themes, $core );
            if ( ! empty( $safety['passed'] ) ) {
                $safety = bma_us_check_existing_backup();
                
            }
            
        }
        
  $safety['passed'] = empty( $safety['blockers'] ) && ! empty( $safety['passed'] );
        
  if ( ! $safety['passed'] ) {
            $results = bma_us_create_blocked_result( $safety, $plugins, $themes, $core );
            
        }
        
  else {
            $previous_health = get_option( BMA_US_HEALTH_OPTION, array() );
            $results = bma_us_run_updates_with_health( $plugins, $themes, $core, $trigger );
            $current_health = get_option( BMA_US_HEALTH_OPTION, array() );
            if ( ! empty( $current_health['run_id'] ) && $current_health['run_id'] !== ( $previous_health['run_id'] ?? '' ) && ( $current_health['results'] ?? null ) === $results ) {
                $health = $current_health;
                
            }
            
        }
        
 
    }
    catch ( Throwable $error ) {
        if ( isset( $results ) ) {
            $GLOBALS['bma_us_inflight_results'] = $results;
            
        }
        $results = bma_us_interrupted_results( $plugins, $themes, $core );
        
    }
    
 finally {
        if ( $locked ) {
            bma_us_release_lock();
            
        }
        
    }
    
 bma_us_process_update_reporting( $results, $trigger );
    set_transient( 'bma_us_last_update_result', $results, 30 * MINUTE_IN_SECONDS );
    bma_us_send_update_alert( $results, $trigger, $health );
    return $results;
    

}

function bma_us_interrupted_results( $plugins, $themes, $core ) {
    
 $results = $GLOBALS['bma_us_inflight_results'] ?? array( 'success' => 0, 'failed' => 0, 'skipped' => 0, 'details' => array() );
    
 foreach ( array( 'plugin' => $plugins, 'theme' => $themes, 'core' => $core ? array( 'wordpress' ) : array() ) as $type => $items ) {
        foreach ( $items as $item ) {
            $recorded = false;
            foreach ( $results['details'] as $detail ) {
                if ( $type === $detail['type'] && $item === $detail['item'] ) {
                    $recorded = true;
                    break;
                    
                }
                
            }
            if ( ! $recorded ) {
                $results['failed']++;
                $results['details'][] = array( 'type' => $type, 'item' => $item, 'status' => 'failed', 'message' => 'Update execution was interrupted. This component may have completed or may not have been attempted; inspect its installed version before retrying.' );
                
            }
            
        }
        
    }
    
 if ( empty( $results['failed'] ) ) {
        $results['failed']++;
        $results['details'][] = array( 'type' => 'operation', 'item' => 'Update completion', 'status' => 'failed', 'message' => 'Update processing was interrupted after component results were recorded. Review health and installed versions.' );
        
    }
    
 unset( $GLOBALS['bma_us_inflight_results'] );
    return $results;
    

}


function bma_us_send_update_alert( $results, $trigger, $health = null ) {
    $reasons = array();
    if ( ! empty( $results['blocked'] ) ) {
        $reasons[] = 'Update blocked';
        
    }
    if ( ! empty( $results['failed'] ) ) {
        $reasons[] = 'Update failed';
        
    }
    if ( is_array( $health ) && 'failed' === ( $health['status'] ?? '' ) ) {
        $reasons[] = 'Post-update health check failed';
        
    }
    if ( ! empty( $health['inspection_incomplete'] ) ) {
        $reasons[] = 'Post-update health verification could not complete';
        
    }
    if ( ! $reasons ) {
        return;
        
    }
    $settings = bma_us_get_settings();
    $email = $settings['notification_email'] ?? '';
    if ( ! is_email( $email ) ) {
        return;
        
    }
    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $subject = '[BMA Update Scheduler] ' . implode( ' / ', $reasons ) . ' - ' . $site;
    $subject = str_replace( array( "\r", "\n" ), ' ', $subject );
    $lines = array( $site, home_url( '/' ), '', 'Trigger: ' . ucfirst( $trigger ), 'Time: ' . wp_date( 'M j, Y \a\t g:i A', time(), wp_timezone() ), 'Alert: ' . implode( '; ', $reasons ), '' );
    foreach ( $results['details'] ?? array() as $detail ) {
        $lines[] = ucfirst( $detail['type'] ?? 'component' ) . ': ' . ( $detail['item'] ?? '' );
        $lines[] = 'Status: ' . ( $detail['status'] ?? '' ) . '. ' . ( $detail['message'] ?? '' );
        
    }
    if ( is_array( $health ) ) {
        $lines[] = '';
        $lines[] = 'Post-update health: ' . $health['status'];
        foreach ( $health['checks'] ?? array() as $check ) {
            if ( 'healthy' !== $check['status'] ) {
                $lines[] = $check['name'] . ': ' . $check['status'] . ' - ' . $check['message'];
                
            }
            
        }
        
    }
    $lines[] = '';
    $lines[] = 'Review: ' . admin_url( 'tools.php?page=bma-update-scheduler' );
    $lines[] = 'No automatic rollback or activation repair was performed.';
    $accepted = false;
    try {
        $accepted = wp_mail( $email, $subject, implode( "\n", $lines ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
        
    }
    catch ( Throwable $error ) {
        $accepted = false;
        
    }
    update_option( 'bma_us_last_email_alert', array( 'checked' => time(), 'recipient' => $email, 'trigger' => $trigger, 'status' => $accepted ? 'accepted' : 'failed', ), false );
    
}
add_action( 'init', 'bma_us_remove_legacy_backup_jobs' );


function bma_us_remove_legacy_backup_jobs() {
    delete_option( 'bma_us_plugin_update_diagnostics' );
    if ( get_option( 'bma_us_existing_backup_gate_migrated' ) || bma_us_get_lock() ) {
        return;
        
    }
    $cleared = wp_unschedule_hook( 'bma_us_verify_scheduled_backup_event', true );
    if ( is_wp_error( $cleared ) || false === $cleared ) {
        return;
        
    }
    delete_transient( 'bma_us_pending_backup_update' );
    delete_option( 'bma_us_scheduled_pending_backup' );
    delete_transient( 'bma_us_last_backup_verification' );
    update_option( 'bma_us_existing_backup_gate_migrated', 1, false );
    
}
define( 'BMA_US_HEALTH_OPTION', 'bma_us_last_post_update_health' );
define( 'BMA_US_HEALTH_HISTORY_OPTION', 'bma_us_post_update_health_history' );
define( 'BMA_US_HEALTH_HISTORY_LIMIT', 50 );
define( 'BMA_US_RESULT_CONTEXT_OPTION', 'bma_us_last_result_context' );


function bma_us_capture_pre_update_state( $plugins ) {
    bma_us_load_update_files();
    $context = array( 'plugins' => array(), 'stylesheet' => get_stylesheet(), 'template' => get_template(), );
    foreach ( $plugins as $plugin ) {
        $context['plugins'][ $plugin ] = array( 'active' => is_plugin_active( $plugin ), 'network' => is_multisite() && is_plugin_active_for_network( $plugin ), );
        
    }
    return $context;
    
}


function bma_us_run_updates_with_health( $plugins, $themes, $core, $trigger ) {
    $context = bma_us_capture_pre_update_state( $plugins );
    try {
        $results = bma_us_run_updates( $plugins, $themes, $core );
        
    }
    catch ( Throwable $error ) {
        $results = bma_us_interrupted_results( $plugins, $themes, $core );
        
    }
    finally {
        unset( $GLOBALS['bma_us_inflight_results'] );
        
    }
    if ( empty( $results['success'] ) && empty( $results['failed'] ) ) {
        return $results;
        
    }
    try {
        $health = bma_us_run_post_update_health_checks( $results, $context );
        
    }
    catch ( Throwable $error ) {
        $health = array( 'status' => 'warning', 'inspection_incomplete' => true, 'checked' => time(), 'checks' => array( array( 'name' => 'Health check execution', 'status' => 'warning', 'message' => 'Health checks could not complete: ' . $error->getMessage() ) ), 'message' => 'Health could not be fully verified. Review the update result.', );
        
    }
    $health['run_id'] = wp_generate_uuid4();
    $health['trigger'] = $trigger;
    $health['results'] = $results;
    update_option( BMA_US_HEALTH_OPTION, $health, false );
    $history = get_option( BMA_US_HEALTH_HISTORY_OPTION, array() );
    $history = is_array( $history ) ? $history : array();
    array_unshift( $history, $health );
    update_option( BMA_US_HEALTH_HISTORY_OPTION, array_slice( $history, 0, BMA_US_HEALTH_HISTORY_LIMIT ), false );
    return $results;
    
}


function bma_us_health_theme_problem( $stylesheet ) {
    $theme = wp_get_theme( $stylesheet );
    $theme->cache_delete();
    if ( ! $theme->exists() ) {
        return 'Theme missing: ' . $stylesheet;
        
    }
    $errors = $theme->errors();
    if ( is_wp_error( $errors ) ) {
        return $stylesheet . ': ' . implode( '; ', $errors->get_error_messages() );
        
    }
    $parent = $theme->parent();
    if ( $parent ) {
        $parent->cache_delete();
        $errors = $parent->errors();
        if ( ! $parent->exists() || is_wp_error( $errors ) ) {
            return 'Parent theme invalid for ' . $stylesheet . ( is_wp_error( $errors ) ? ': ' . implode( '; ', $errors->get_error_messages() ) : '.' );
            
        }
        
    }
    return '';
    
}


function bma_us_run_post_update_health_checks( $results, $context ) {
    $checks = array();
    $required = array( 'get_plugins', 'wp_get_theme', 'get_stylesheet', 'get_template', 'is_plugin_active', 'is_plugin_active_for_network', 'is_multisite', 'get_option', 'update_option', 'is_wp_error', 'wp_clean_plugins_cache' );
    $missing = array();
    foreach ( $required as $function ) {
        if ( ! function_exists( $function ) ) {
            $missing[] = $function;
            
        }
        
    }
    $checks[] = array( 'name' => 'WordPress runtime', 'status' => $missing ? 'failed' : 'healthy', 'message' => $missing ? 'Required APIs missing: ' . implode( ', ', $missing ) : 'Required WordPress APIs are available in this request.' );
    clearstatcache( true, ABSPATH . '.maintenance' );
    $maintenance = file_exists( ABSPATH . '.maintenance' );
    $checks[] = array( 'name' => 'Maintenance mode', 'status' => $maintenance ? 'failed' : 'healthy', 'message' => $maintenance ? 'The .maintenance file remains after the update. Review maintenance mode.' : 'Maintenance mode cleared.' );
    if ( ! $missing ) {
        wp_clean_plugins_cache( false );
        $installed = get_plugins();
        $component_checks = 0;
        $active_checks = 0;
        foreach ( $results['details'] as $detail ) {
            if ( 'success' === $detail['status'] && in_array( $detail['type'], array( 'plugin', 'theme' ), true ) ) {
                $item = $detail['item'];
                $problem = 'plugin' === $detail['type'] ? ( isset( $installed[ $item ] ) ? '' : 'Plugin file is missing from get_plugins(): ' . $item ) : bma_us_health_theme_problem( $item );
                $checks[] = array( 'name' => 'Updated component: ' . $item, 'status' => $problem ? 'failed' : 'healthy', 'message' => $problem ?: 'Updated component is available.' );
                $component_checks++;
                
            }
            if ( 'plugin' !== $detail['type'] || ! in_array( $detail['status'], array( 'success', 'failed' ), true ) ) {
                continue;
                
            }
            $item = $detail['item'];
            $before = $context['plugins'][ $item ] ?? null;
            if ( null === $before ) {
                $checks[] = array( 'name' => 'Plugin activation: ' . $item, 'status' => 'warning', 'message' => 'Pre-update activation state is unavailable.' );
                $active_checks++;
                
            }
            elseif ( $before['active'] || $before['network'] ) {
                $active = $before['network'] ? is_plugin_active_for_network( $item ) : is_plugin_active( $item );
                $checks[] = array( 'name' => 'Plugin activation: ' . $item, 'status' => $active ? 'healthy' : 'failed', 'message' => $active ? 'Previously active plugin remains active' . ( $before['network'] ? ' across the network.' : '.' ) : 'Previously active plugin is no longer active' . ( $before['network'] ? ' across the network.' : '.' ) );
                $active_checks++;
                
            }
            
        }
        if ( ! $component_checks ) {
            $checks[] = array( 'name' => 'Updated components', 'status' => 'healthy', 'message' => 'Not applicable: no successfully updated plugins or themes.' );
            
        }
        if ( ! $active_checks ) {
            $checks[] = array( 'name' => 'Active updated plugins', 'status' => 'healthy', 'message' => 'Not applicable: no previously active plugins were updated.' );
            
        }
        $stylesheet = get_stylesheet();
        $template = get_template();
        $problem = bma_us_health_theme_problem( $stylesheet );
        if ( ! $problem && $template !== $stylesheet ) {
            $problem = bma_us_health_theme_problem( $template );
            
        }
        $changed = $stylesheet !== $context['stylesheet'] || $template !== $context['template'];
        $checks[] = array( 'name' => 'Active theme', 'status' => $problem ? 'failed' : ( $changed ? 'warning' : 'healthy' ), 'message' => $problem ?: ( $changed ? 'Active theme is valid, but changed from ' . $context['stylesheet'] . ' to ' . $stylesheet . '.' : 'Active theme and any parent theme are valid: ' . $stylesheet . '.' ) );
        
    }
    else {
        $checks[] = array( 'name' => 'Component and theme checks', 'status' => 'warning', 'message' => 'Unavailable because required WordPress APIs are missing.' );
        
    }
    if ( ! empty( $results['failed'] ) ) {
        $checks[] = array( 'name' => 'Update outcome', 'status' => 'warning', 'message' => 'One or more update attempts failed. Review Update History even if runtime checks pass.' );
        
    }
    $status = 'healthy';
    foreach ( $checks as $check ) {
        if ( 'failed' === $check['status'] ) {
            $status = 'failed';
            break;
            
        }
        if ( 'warning' === $check['status'] ) {
            $status = 'warning';
            
        }
        
    }
    $messages = array( 'healthy' => 'All performed post-update checks passed.', 'warning' => 'Post-update checks need review.', 'failed' => 'One or more post-update health checks failed.' );
    return array( 'status' => $status, 'checked' => time(), 'checks' => $checks, 'message' => $messages[ $status ] );
    
}


function bma_us_render_post_update_health() {
    $health = get_option( BMA_US_HEALTH_OPTION, array() );
    if ( empty( $health['checks'] ) ) {
        return;
        
    }
    $colors = array( 'healthy' => '#008a20', 'warning' => '#996800', 'failed' => '#d63638' );
    $color = $colors[ $health['status'] ] ?? $colors['warning'];
    echo '<section class="bma-section bma-dismissible-report" data-dismiss-key="' . esc_attr( 'bma-us-health-' . get_current_blog_id() . '-' . get_current_user_id() ) . '" data-report-id="' . esc_attr( $health['run_id'] ?? md5( serialize( $health ) ) ) . '" style="padding:20px;border-left:4px solid ' . esc_attr( $color ) . '">';
    echo '<h2>Post-Update Health Check <span style="color:' . esc_attr( $color ) . '">' . esc_html( ucfirst( $health['status'] ) ) . '</span></h2>';
    echo '<p>Latest actual update operation: <strong>' . esc_html( ucfirst( $health['trigger'] ) ) . '</strong>. Checked: ';
    echo esc_html( wp_date( 'M j, Y \a\t g:i A', $health['checked'], wp_timezone() ) ) . '</p>';
    $items = array();
    foreach ( $health['results']['details'] as $detail ) {
        if ( in_array( $detail['status'], array( 'success', 'failed' ), true ) ) {
            $items[] = $detail['item'] . ' (' . $detail['status'] . ')';
            
        }
        
    }
    echo '<p>' . esc_html( implode( ', ', $items ) ) . '</p><ul>';
    foreach ( $health['checks'] as $check ) {
        $color = $colors[ $check['status'] ] ?? $colors['warning'];
        echo '<li style="margin:10px 0"><strong style="color:' . esc_attr( $color ) . '">' . esc_html( ucfirst( $check['status'] ) ) . '</strong> &mdash; ';
        echo '<strong>' . esc_html( $check['name'] ) . ':</strong> ' . esc_html( $check['message'] ) . '</li>';
        
    }
    echo '</ul><p>' . esc_html( $health['message'] ) . '</p>';
    echo '<p class="description">Monitoring only. These checks describe the completed update request; they are not a live frontend test. No rollback was performed.</p></section>';
    
}


function bma_us_run_updates( $plugins = array(), $themes = array(), $update_core = false ) {
    bma_us_load_update_files();
    $results = array( 'success' => 0, 'failed' => 0, 'skipped' => 0, 'details' => array(), );
    $GLOBALS['bma_us_inflight_results'] =& $results;
    if ( ! empty( $plugins ) ) {
        $plugin_updates = get_site_transient( 'update_plugins' );
        $eligible_plugins = array();
        $old_versions = array();
        $all_plugins = get_plugins();
        foreach ( array_unique( $plugins ) as $plugin_file ) {
            if ( bma_us_is_protected_plugin( $plugin_file ) ) {
                $results['skipped']++;
                $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'skipped', 'message' => 'Protected plugin.', );
                continue;
                
            }
            if ( ! is_object( $plugin_updates ) || empty( $plugin_updates->response[ $plugin_file ] ) ) {
                $results['skipped']++;
                $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'skipped', 'message' => 'No update currently available.', );
                continue;
                
            }
            $eligible_plugins[] = $plugin_file;
            $old_versions[ $plugin_file ] = isset( $all_plugins[ $plugin_file ]['Version'] ) ? $all_plugins[ $plugin_file ]['Version'] : '';
            
        }
        if ( ! empty( $eligible_plugins ) ) {
            /* One batch retains the update metadata for every selected plugin until installation finishes. */ $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $bulk_results = $upgrader->bulk_upgrade( $eligible_plugins );
            wp_clean_plugins_cache( false );
            $all_plugins = get_plugins();
            foreach ( $eligible_plugins as $plugin_file ) {
                $old_version = $old_versions[ $plugin_file ];
                if ( is_wp_error( $bulk_results ) || false === $bulk_results ) {
                    $result = $bulk_results;
                    
                }
                elseif ( is_array( $bulk_results ) && array_key_exists( $plugin_file, $bulk_results ) ) {
                    $result = $bulk_results[ $plugin_file ];
                    if ( is_array( $result ) && ! empty( $result['destination'] ) ) {
                        $result = true;
                        
                    }
                    
                }
                else {
                    $result = new WP_Error( 'bma_plugin_update_result_missing', 'WordPress returned no result for this plugin; the batch may have stopped before it was attempted.' );
                    
                }
                if ( is_wp_error( $result ) ) {
                    $results['failed']++;
                    $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'failed', 'message' => $result->get_error_message(), );
                    continue;
                    
                }
                if ( false === $result ) {
                    $results['failed']++;
                    $error_message = 'WordPress updater returned false.';
                    if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
                        $error_message = $skin->result->get_error_message();
                        
                    }
                    $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'failed', 'message' => $error_message, );
                    continue;
                    
                }
                $new_version = isset( $all_plugins[ $plugin_file ]['Version'] ) ? $all_plugins[ $plugin_file ]['Version'] : '';
                if ( $old_version && $new_version && version_compare( $new_version, $old_version, '>' ) ) {
                    $results['success']++;
                    $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'success', 'old_version' => $old_version, 'new_version' => $new_version, 'message' => 'Plugin updated successfully.', );
                    
                }
                elseif ( true === $result ) {
                    $results['success']++;
                    $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'success', 'old_version' => $old_version, 'new_version' => $new_version, 'message' => 'WordPress reported a successful update.', );
                    
                }
                else {
                    $results['failed']++;
                    $results['details'][] = array( 'type' => 'plugin', 'item' => $plugin_file, 'status' => 'failed', 'old_version' => $old_version, 'new_version' => $new_version, 'message' => 'Update could not be verified.', );
                    
                }
                
            }
            
        }
        
    }
    if ( ! empty( $themes ) ) {
        $theme_updates = get_site_transient( 'update_themes' );
        foreach ( $themes as $stylesheet ) {
            $stylesheet = sanitize_text_field( $stylesheet );
            $theme = wp_get_theme( $stylesheet );
            if ( ! $theme->exists() ) {
                $results['failed']++;
                $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'failed', 'message' => 'Theme could not be found.', );
                continue;
                
            }
            if ( ! is_object( $theme_updates ) || empty( $theme_updates->response[ $stylesheet ] ) ) {
                $results['skipped']++;
                $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'skipped', 'message' => 'The update disappeared from the WordPress update transient before installation.', );
                continue;
                
            }
            $old_version = $theme->get( 'Version' );
            $target_version = '';
            if ( ! empty( $theme_updates->response[ $stylesheet ]['new_version'] ) ) {
                $target_version = $theme_updates ->response[ $stylesheet ]['new_version'];
                
            }
            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Theme_Upgrader( $skin );
            $result = $upgrader->upgrade( $stylesheet );
            if ( is_wp_error( $result ) ) {
                $results['failed']++;
                $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'failed', 'old_version' => $old_version, 'new_version' => $target_version, 'message' => $result->get_error_message(), );
                continue;
                
            }
            if ( false === $result ) {
                $message = 'WordPress theme updater returned false.';
                if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
                    $message = $skin->result->get_error_message();
                    
                }
                $results['failed']++;
                $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'failed', 'old_version' => $old_version, 'new_version' => $target_version, 'message' => $message, );
                continue;
                
            }
            wp_clean_themes_cache( true );
            $updated_theme = wp_get_theme( $stylesheet );
            $new_version = '';
            if ( $updated_theme->exists() ) {
                $new_version = $updated_theme->get( 'Version' );
                
            }
            if ( $old_version && $new_version && version_compare( $new_version, $old_version, '>' ) ) {
                $results['success']++;
                $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'success', 'old_version' => $old_version, 'new_version' => $new_version, 'message' => 'Theme updated successfully.', );
                continue;
                
            }
            if ( true === $result ) {
                $results['success']++;
                $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'success', 'old_version' => $old_version, 'new_version' => $new_version, 'message' => 'WordPress reported the theme update as successful.', );
                continue;
                
            }
            $results['failed']++;
            $results['details'][] = array( 'type' => 'theme', 'item' => $stylesheet, 'status' => 'failed', 'old_version' => $old_version, 'new_version' => $new_version, 'message' => 'The updater completed, but the installed version did not change.', );
            
        }
        
    }
    if ( $update_core ) {
        wp_version_check();
        $core_updates = get_site_transient( 'update_core' );
        if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) ) {
            $core_offer = false;
            foreach ( $core_updates->updates as $update ) {
                if ( isset( $update->response ) && 'upgrade' === $update->response ) {
                    $core_offer = $update;
                    break;
                    
                }
                
            }
            if ( $core_offer ) {
                global $wp_version;
                $old_core_version = $wp_version;
                $target_core_version = ! empty( $core_offer->current ) ? $core_offer->current : '';
                $skin = new Automatic_Upgrader_Skin();
                $upgrader = new Core_Upgrader( $skin );
                $result = $upgrader->upgrade( $core_offer );
                if ( is_wp_error( $result ) || false === $result ) {
                    $results['failed']++;
                    $message = 'WordPress core update failed.';
                    if ( is_wp_error( $result ) ) {
                        $message = $result->get_error_message();
                        
                    }
                    elseif ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
                        $message = $skin->result ->get_error_message();
                        
                    }
                    $results['details'][] = array( 'type' => 'core', 'item' => 'wordpress', 'status' => 'failed', 'old_version' => $old_core_version, 'new_version' => $target_core_version, 'message' => $message, );
                    
                }
                else {
                    $results['success']++;
                    $results['details'][] = array( 'type' => 'core', 'item' => 'wordpress', 'status' => 'success', 'old_version' => $old_core_version, 'new_version' => $target_core_version, 'message' => 'WordPress core updated successfully.', );
                    
                }
                
            }
            else {
                $results['skipped']++;
                $results['details'][] = array( 'type' => 'core', 'item' => 'wordpress', 'status' => 'skipped', 'message' => 'No WordPress core update available.', );
                
            }
            
        }
        
    }
    delete_site_transient( 'update_plugins' );
    delete_site_transient( 'update_themes' );
    delete_site_transient( 'update_core' );
    wp_version_check();
    wp_update_plugins();
    wp_update_themes();
    set_transient( 'bma_us_last_update_result', $results, 30 * MINUTE_IN_SECONDS );
    return $results;
    
}
add_action( BMA_US_CRON_HOOK, 'bma_us_run_scheduled_updates' );


function bma_us_run_scheduled_updates() {
    $settings = bma_us_get_settings();
    if ( empty( $settings['enabled'] ) ) {
        return;
        
    }
    bma_us_reschedule_event( $settings );
    bma_us_refresh_updates();
    $plugins = array();
    $themes = array();
    if ( ! empty( $settings['plugins'] ) ) {
        $plugin_updates = get_site_transient( 'update_plugins' );
        if ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ) {
            if ( ! empty( $settings['selected_plugins'] ) ) {
                foreach ( $settings['selected_plugins'] as $plugin_file ) {
                    if ( isset( $plugin_updates->response[ $plugin_file ] ) && ! bma_us_is_protected_plugin( $plugin_file ) ) {
                        $plugins[] = $plugin_file;
                        
                    }
                    
                }
                
            }
            else {
                foreach ( array_keys( $plugin_updates->response ) as $plugin_file ) {
                    if ( ! bma_us_is_protected_plugin( $plugin_file ) ) {
                        $plugins[] = $plugin_file;
                        
                    }
                    
                }
                
            }
            
        }
        
    }
    if ( ! empty( $settings['themes'] ) ) {
        $theme_updates = get_site_transient( 'update_themes' );
        if ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) ) {
            if ( ! empty( $settings['selected_themes'] ) ) {
                foreach ( $settings['selected_themes'] as $theme ) {
                    if ( isset( $theme_updates->response[ $theme ] ) ) {
                        $themes[] = $theme;
                        
                    }
                    
                }
                
            }
            else {
                $themes = array_keys( $theme_updates->response );
                
            }
            
        }
        
    }
    $core = ! empty( $settings['core'] );
    if ( ! $plugins && ! $themes && ! $core ) {
        return;
        
    }
    bma_us_execute_guarded_updates( $plugins, $themes, $core, 'scheduled' );
    
}


function bma_us_notice_redirect( $url ) {
    wp_safe_redirect( add_query_arg( 'bma_us_notice_nonce', wp_create_nonce( 'bma_us_notice' ), $url ) );
}

function bma_us_get_notice_data() {
    if ( ! isset( $_GET['bma_us_notice_nonce'] ) || ! is_string( $_GET['bma_us_notice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['bma_us_notice_nonce'] ) ), 'bma_us_notice' ) ) {
        return array();
    }
    $data = array();
    $data['notice'] = isset( $_GET['bma_notice'] ) && is_string( $_GET['bma_notice'] ) ? sanitize_key( wp_unslash( $_GET['bma_notice'] ) ) : '';
    $data['blocked'] = isset( $_GET['bma_blocked'] ) && is_scalar( $_GET['bma_blocked'] ) ? absint( wp_unslash( $_GET['bma_blocked'] ) ) : 0;
    $data['success'] = isset( $_GET['bma_ok'] ) && is_scalar( $_GET['bma_ok'] ) ? absint( wp_unslash( $_GET['bma_ok'] ) ) : 0;
    $data['failed'] = isset( $_GET['bma_failed'] ) && is_scalar( $_GET['bma_failed'] ) ? absint( wp_unslash( $_GET['bma_failed'] ) ) : 0;
    $data['skipped'] = isset( $_GET['bma_skipped'] ) && is_scalar( $_GET['bma_skipped'] ) ? absint( wp_unslash( $_GET['bma_skipped'] ) ) : 0;
    return $data;
}

function bma_us_render_notices() {
    $notice_data = bma_us_get_notice_data();
    $schedule_error = get_option( 'bma_us_schedule_error', '' );
    if ( is_string( $schedule_error ) && '' !== $schedule_error ) {
        echo '<div class="notice notice-error"><p><strong>BMA Update Scheduler:</strong> ' . esc_html( 'Schedule could not be saved: ' . $schedule_error . ' Review and save the schedule again.' ) . '</p></div>';
        
    }
    $saved_settings = bma_us_get_settings();
    if ( ! empty( $saved_settings['enabled'] ) && empty( $saved_settings['scheduled_by'] ) ) {
        echo '<div class="notice notice-warning"><p>BMA scheduled updates require authorization. Save Schedule once with an authorized administrator account.</p></div>';
        
    }
    if ( empty( $notice_data['notice'] ) ) {
        return;
        
    }
    $notice = $notice_data['notice'];
    if ( 'schedule_saved' === $notice ) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>BMA Update Scheduler:</strong> Schedule saved successfully.</p>';
        echo '</div>';
        return;
        
    }
    if ( 'locked' === $notice ) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>BMA Update Scheduler:</strong> Another update process appears to be running. Please try again shortly.</p>';
        echo '</div>';
        return;
        
    }
    if ( 'nothing_selected' === $notice ) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>BMA Update Scheduler:</strong> No plugin or theme update was selected.</p>';
        echo '</div>';
        return;
        
    }
    if ( 'updates_blocked' === $notice ) {
        $blocked = $notice_data['blocked'];
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p>';
        echo '<strong>BMA Update Scheduler:</strong> ';
        echo esc_html( $blocked . ' update' . ( 1 === $blocked ? '' : 's' ) . ' blocked by pre-update safety checks.' );
        echo '</p>';
        echo '</div>';
        return;
        
    }
    if ( 'updates_finished' === $notice ) {
        $success = $notice_data['success'];
        $failed = $notice_data['failed'];
        $skipped = $notice_data['skipped'];
        if ( 0 === $failed ) {
            echo '<div class="notice notice-success is-dismissible">';
            
        }
        else {
            echo '<div class="notice notice-warning is-dismissible">';
            
        }
        echo '<p>';
        echo '<strong>BMA Update Scheduler:</strong> ';
        echo esc_html( $success . ' update' . ( 1 === $success ? '' : 's' ) . ' completed' );
        if ( $failed > 0 ) {
            echo esc_html( ', ' . $failed . ' failed' );
            
        }
        if ( $skipped > 0 ) {
            echo esc_html( ', ' . $skipped . ' skipped' );
            
        }
        echo '.</p>';
        echo '</div>';
        
    }
    if ( 'history_cleared' === $notice ) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>BMA Update Scheduler:</strong> Update history cleared successfully.</p>';
        echo '</div>';
        return;
        
    }
    
}


function bma_us_status_badge( $status ) {
    if ( 'update' === $status ) {
        echo '<span class="bma-badge bma-status-update">';
        echo '<span class="bma-status-dot"></span>';
        echo 'Update Available';
        echo '</span>';
        return;
        
    }
    echo '<span class="bma-badge bma-status-current">';
    echo '<span class="bma-status-dot"></span>';
    echo 'Up to Date';
    echo '</span>';
    
}


function bma_us_render_dashboard() {
    if ( ! current_user_can( 'update_core' ) ) {
        wp_die( 'You do not have permission to access this page.' );
        
    }
    bma_us_refresh_updates();
    $settings = bma_us_get_settings();
    $plugins = get_plugins();
    $themes = wp_get_themes();
    $plugin_updates = get_site_transient( 'update_plugins' );
    $theme_updates = get_site_transient( 'update_themes' );
    $core_updates = get_site_transient( 'update_core' );
    $active_theme = wp_get_theme();
    global $wp_version;
    $plugin_update_count = 0;
    $theme_update_count = 0;
    $core_update_count = 0;
    $core_new_version = '';
    if ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ) {
        $plugin_update_count = count( $plugin_updates->response );
        
    }
    if ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) ) {
        $theme_update_count = count( $theme_updates->response );
        
    }
    if ( is_object( $core_updates ) && ! empty( $core_updates->updates ) ) {
        foreach ( $core_updates->updates as $update ) {
            if ( isset( $update->response ) && 'upgrade' === $update->response ) {
                $core_update_count = 1;
                if ( ! empty( $update->current ) ) {
                    $core_new_version = $update->current;
                    
                }
                break;
                
            }
            
        }
        
    }
    $total_updates = $plugin_update_count + $theme_update_count + $core_update_count;
    $timezone = wp_timezone_string();
    if ( empty( $timezone ) ) {
        $timezone = 'UTC';
        
    }
    $next_timestamp = wp_next_scheduled( BMA_US_CRON_HOOK );
    ?>

	<div class="wrap bma-update-scheduler">
<div class="bma-header">
<div>
<h1>BMA Update Scheduler</h1>
<p>
					Manage WordPress, plugin and theme updates.
				</p>
</div>
<div class="bma-header-meta">
Timezone:
				<strong>
					<?php echo esc_html( $timezone );
    ?>
				</strong>
</div>
</div>
<?php bma_us_render_notices();
    ?>
		<?php bma_us_render_last_update_debug();
    ?>
		<?php bma_us_render_post_update_health();
    ?>

<div class="bma-summary-grid">
<div class="bma-card">
<div class="bma-card-label">
					WordPress
				</div>
<div class="bma-card-number">
					<?php echo esc_html( $wp_version );
    ?>
				</div>
<div class="bma-card-description">
					Current version
				</div>
</div>
<div class="bma-card">
<div class="bma-card-label">
					Installed Plugins
				</div>
<div class="bma-card-number">
					<?php echo esc_html( count( $plugins ) );
    ?>
				</div>
<div class="bma-card-description">
<?php
 echo esc_html( $plugin_update_count . ' update' . ( 1 === $plugin_update_count ? '' : 's' ) . ' available' );
    ?>

				</div>
</div>
<div class="bma-card">
<div class="bma-card-label">
					Installed Themes
				</div>
<div class="bma-card-number">
					<?php echo esc_html( count( $themes ) );
    ?>
				</div>
<div class="bma-card-description">
<?php
 echo esc_html( $theme_update_count . ' update' . ( 1 === $theme_update_count ? '' : 's' ) . ' available' );
    ?>

				</div>
</div>
<div class="bma-card bma-card-highlight">
<div class="bma-card-label">
					Updates Available
				</div>
<div class="bma-card-number">
					<?php echo esc_html( $total_updates );
    ?>
				</div>
<div class="bma-card-description">
<?php
 echo $total_updates ? 'Attention required' : 'Everything is up to date';
    ?>

				</div>
</div>
</div>
<?php bma_us_render_backup_safety();
    ?>

<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) );
    ?>"
			id="bma-manual-update-form"
		>
<input
				type="hidden"
				name="action"
				value="bma_us_update_now"
			>
<?php
 wp_nonce_field( 'bma_us_update_now' );
    ?>
			
<div class="bma-section">
<div class="bma-section-header">
<div>
<h2>WordPress Core</h2>
<p>
							Current WordPress installation.
						</p>
</div>
</div>
<div class="bma-table-wrapper">
<table class="bma-table">
<thead>
<tr>
<th>Component</th>
								<th>Installed Version</th>
								<th>Available Version</th>
								<th>Status</th>
</tr>
</thead>
<tbody>
<tr>
<td>
<div class="bma-item">
<div class="bma-icon">
											W
										</div>
<div>
<strong>
												WordPress
											</strong>
<small>
												Core
											</small>
</div>
</div>
</td>
<td>
									<?php echo esc_html( $wp_version );
    ?>
								</td>
<td>
<?php if ( $core_update_count ) : ?>

										<strong class="bma-new-version">
											<?php echo esc_html( $core_new_version );
    ?>
										</strong>
<?php else : ?>

										<span class="bma-muted">
											—
										</span>
<?php endif;
    ?>

								</td>
<td>
<?php
 bma_us_status_badge( $core_update_count ? 'update' : 'current' );
    ?>

								</td>
</tr>
</tbody>
</table>
</div>
</div>
<div class="bma-section">
<div class="bma-section-header">
<div>
<h2>
Plugins
<span class="bma-count">
								<?php echo esc_html( count( $plugins ) );
    ?>
							</span>
</h2>
<p>
							Select available plugin updates.
						</p>
</div>
<?php if ( $plugin_update_count ) : ?>

						<div class="bma-update-count">
<?php
 echo esc_html( $plugin_update_count . ' update' . ( 1 === $plugin_update_count ? '' : 's' ) );
    ?>

						</div>
<?php endif;
    ?>

				</div>
<div class="bma-table-wrapper">
<table class="bma-table">
<thead>
<tr>
<th class="bma-checkbox-column">
									Select
								</th>
<th>Plugin</th>
								<th>Installed</th>
								<th>Available</th>
								<th>Plugin Status</th>
								<th>Update Status</th>
</tr>
</thead>
<tbody>
<?php foreach ( $plugins as $plugin_file => $plugin_data ) : ?>

							<?php
 $is_active = is_plugin_active( $plugin_file );
    $has_update = is_object( $plugin_updates ) && isset( $plugin_updates->response[ $plugin_file ] );
    $protected = bma_us_is_protected_plugin( $plugin_file );
    $new_version = '';
    if ( $has_update ) {
        $new_version = $plugin_updates ->response[ $plugin_file ] ->new_version ?? '';
        
    }
    ?>

							<tr
								class="<?php echo $has_update ? 'bma-has-update' : '';
    ?>"
							>
<td class="bma-checkbox-column">
<?php if ( $has_update && ! $protected ) : ?>

										<input
											type="checkbox"
											class="bma-update-checkbox"
											name="update_plugins[]"
											value="<?php echo esc_attr( $plugin_file );
    ?>"
										>
<?php elseif ( $protected ) : ?>

										<span
											class="dashicons dashicons-lock"
											title="Protected"
										></span>
<?php else : ?>

										<span class="bma-muted">—</span>
<?php endif;
    ?>

								</td>
<td>
<div class="bma-item">
<div class="bma-icon bma-plugin-icon">
											P
										</div>
<div>
<strong>
												<?php
 echo esc_html( $plugin_data['Name'] ?? $plugin_file );
    ?>
											</strong>
<?php if ( $protected ) : ?>

												<small class="bma-protected">
													Protected — scheduler dependency
												</small>
<?php elseif ( ! empty( $plugin_data['AuthorName'] ) ) : ?>

												<small>
by
<?php
 echo esc_html( wp_strip_all_tags( $plugin_data['AuthorName'] ) );
    ?>

												</small>
<?php endif;
    ?>

										</div>
</div>
</td>
<td>
<?php
 echo esc_html( $plugin_data['Version'] ?? '—' );
    ?>

								</td>
<td>
<?php if ( $has_update ) : ?>

										<strong class="bma-new-version">
											<?php echo esc_html( $new_version );
    ?>
										</strong>
<?php else : ?>

										<span class="bma-muted">—</span>
<?php endif;
    ?>

								</td>
<td>
<?php if ( $is_active ) : ?>

										<span class="bma-badge bma-active">
											Active
										</span>
<?php else : ?>

										<span class="bma-badge bma-inactive">
											Inactive
										</span>
<?php endif;
    ?>

								</td>
<td>
<?php
 bma_us_status_badge( $has_update ? 'update' : 'current' );
    ?>

								</td>
</tr>
<?php endforeach;
    ?>

						</tbody>
</table>
</div>
</div>
<div class="bma-section">
<div class="bma-section-header">
<div>
<h2>
Themes
<span class="bma-count">
								<?php echo esc_html( count( $themes ) );
    ?>
							</span>
</h2>
<p>
							Select available theme updates.
						</p>
</div>
<?php if ( $theme_update_count ) : ?>

						<div class="bma-update-count">
<?php
 echo esc_html( $theme_update_count . ' update' . ( 1 === $theme_update_count ? '' : 's' ) );
    ?>

						</div>
<?php endif;
    ?>

				</div>
<div class="bma-table-wrapper">
<table class="bma-table">
<thead>
<tr>
<th class="bma-checkbox-column">
									Select
								</th>
<th>Theme</th>
								<th>Installed</th>
								<th>Available</th>
								<th>Theme Status</th>
								<th>Update Status</th>
</tr>
</thead>
<tbody>
<?php foreach ( $themes as $stylesheet => $theme ) : ?>

							<?php
 $is_active = $stylesheet === $active_theme->get_stylesheet();
    $has_update = is_object( $theme_updates ) && isset( $theme_updates->response[ $stylesheet ] );
    $new_version = '';
    if ( $has_update ) {
        $new_version = $theme_updates ->response[ $stylesheet ]['new_version'] ?? '';
        
    }
    ?>

							<tr
								class="<?php echo $has_update ? 'bma-has-update' : '';
    ?>"
							>
<td class="bma-checkbox-column">
<?php if ( $has_update ) : ?>

										<input
											type="checkbox"
											class="bma-update-checkbox"
											name="update_themes[]"
											value="<?php echo esc_attr( $stylesheet );
    ?>"
										>
<?php else : ?>

										<span class="bma-muted">—</span>
<?php endif;
    ?>

								</td>
<td>
<div class="bma-item">
<div class="bma-icon bma-theme-icon">
											T
										</div>
<div>
<strong>
												<?php echo esc_html( $theme->get( 'Name' ) );
    ?>
											</strong>
<?php if ( $theme->get( 'Author' ) ) : ?>

												<small>
by
<?php
 echo esc_html( wp_strip_all_tags( $theme->get( 'Author' ) ) );
    ?>

												</small>
<?php endif;
    ?>

										</div>
</div>
</td>
<td>
									<?php echo esc_html( $theme->get( 'Version' ) );
    ?>
								</td>
<td>
<?php if ( $has_update ) : ?>

										<strong class="bma-new-version">
											<?php echo esc_html( $new_version );
    ?>
										</strong>
<?php else : ?>

										<span class="bma-muted">—</span>
<?php endif;
    ?>

								</td>
<td>
<?php if ( $is_active ) : ?>

										<span class="bma-badge bma-active">
											Active
										</span>
<?php else : ?>

										<span class="bma-badge bma-inactive">
											Inactive
										</span>
<?php endif;
    ?>

								</td>
<td>
<?php
 bma_us_status_badge( $has_update ? 'update' : 'current' );
    ?>

								</td>
</tr>
<?php endforeach;
    ?>

						</tbody>
</table>
</div>
</div>
<?php if ( $plugin_update_count || $theme_update_count ) : ?>

				<div class="bma-action-bar">
<div>
<button
							type="button"
							class="button"
							id="bma-select-all"
						>
							Select All Updates
						</button>
<button
							type="button"
							class="button"
							id="bma-clear-all"
						>
							Clear Selection
						</button>
</div>
<div class="bma-action-right">
<span id="bma-selection-count">
							0 selected
						</span>
<button
							type="submit"
							class="button button-primary"
							id="bma-update-now"
							disabled
						>
							Update Selected Now
						</button>
</div>
</div>
<?php endif;
    ?>

		</form>
<div class="bma-scheduler-section">
<div class="bma-scheduler-header">
<div>
<h2>Scheduled Updates</h2>
<p>
						Automatically install available updates at your preferred time.
					</p>
</div>
<?php if ( $settings['enabled'] ) : ?>

					<span class="bma-schedule-enabled">
						● Schedule Active
					</span>
<?php else : ?>

					<span class="bma-schedule-disabled">
						● Schedule Disabled
					</span>
<?php endif;
    ?>

			</div>
<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) );
    ?>"
			>
<input
					type="hidden"
					name="action"
					value="bma_us_save_schedule"
				>
<?php
 wp_nonce_field( 'bma_us_save_schedule' );
    ?>

<div class="bma-scheduler-grid">
<div class="bma-schedule-card">
<h3>What should be updated?</h3>
<label class="bma-toggle-row">
<span>
								<strong>Enable Scheduled Updates</strong>
								<small>
									Turn automatic scheduled updating on or off.
								</small>
							</span>
<input
								type="checkbox"
								name="enabled"
								value="1"
								<?php checked( $settings['enabled'], 1 );
    ?>
							>
</label>
<label class="bma-toggle-row">
<span>
								<strong>Plugins</strong>
								<small>
									Update available plugins.
								</small>
							</span>
<input
								type="checkbox"
								name="plugins"
								value="1"
								<?php checked( $settings['plugins'], 1 );
    ?>
							>
</label>
<label class="bma-toggle-row">
<span>
								<strong>Themes</strong>
								<small>
									Update available themes.
								</small>
							</span>
<input
								type="checkbox"
								name="themes"
								value="1"
								<?php checked( $settings['themes'], 1 );
    ?>
							>
</label>
<label class="bma-toggle-row">
<span>
								<strong>WordPress Core</strong>
								<small>
									Install available WordPress core upgrades.
								</small>
							</span>
<input
								type="checkbox"
								name="core"
								value="1"
								<?php checked( $settings['core'], 1 );
    ?>
							>
</label>
</div>
<div class="bma-schedule-card">
<h3>Schedule</h3>
<div class="bma-field">
<label for="bma-frequency">
								Frequency
							</label>
<select
								name="frequency"
								id="bma-frequency"
							>
<option
									value="daily"
									<?php selected( $settings['frequency'], 'daily' );
    ?>
								>
									Daily
								</option>
<option
									value="weekly"
									<?php selected( $settings['frequency'], 'weekly' );
    ?>
								>
									Weekly
								</option>
</select>
</div>
<div
							class="bma-field"
							id="bma-day-field"
						>
<label for="bma-day">
								Day
							</label>
<select
								name="day"
								id="bma-day"
							>
<?php
 $days = array( 'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday', );
    foreach ( $days as $value => $label ) : ?>

									<option
										value="<?php echo esc_attr( $value );
    ?>"
										<?php selected( $settings['day'], $value );
    ?>
									>
										<?php echo esc_html( $label );
    ?>
									</option>
<?php endforeach;
    ?>

							</select>
</div>
<div class="bma-field">
<label for="bma-time">
								Time
							</label>
<input
								type="time"
								name="time"
								id="bma-time"
								value="<?php echo esc_attr( $settings['time'] );
    ?>"
								required
							>
</div>
<div class="bma-timezone-note">
Timezone:
<strong>
								<?php echo esc_html( $timezone );
    ?>
							</strong>
<br>
<small>
								Uses your WordPress timezone from Settings → General.
							</small>
</div>
</div>
</div>
<div class="bma-specific-selection">
<h3>Scheduled Component Selection</h3>
<p>
						Optionally choose specific plugins or themes.
						If nothing is selected, all available updates in that category will be installed.
					</p>
<div class="bma-selection-grid">
<div>
<h4>Plugins</h4>
<div class="bma-selection-list">
<?php foreach ( $plugins as $plugin_file => $plugin_data ) : ?>

								<?php
 if ( bma_us_is_protected_plugin( $plugin_file ) ) {
        continue;
        
    }
    ?>

								<label>
<input
										type="checkbox"
										name="selected_plugins[]"
										value="<?php echo esc_attr( $plugin_file );
    ?>"
										<?php
 checked( in_array( $plugin_file, $settings['selected_plugins'], true ) );
    ?>
									>
<?php
 echo esc_html( $plugin_data['Name'] ?? $plugin_file );
    ?>

								</label>
<?php endforeach;
    ?>

							</div>
</div>
<div>
<h4>Themes</h4>
<div class="bma-selection-list">
<?php foreach ( $themes as $stylesheet => $theme ) : ?>

								<label>
<input
										type="checkbox"
										name="selected_themes[]"
										value="<?php echo esc_attr( $stylesheet );
    ?>"
										<?php
 checked( in_array( $stylesheet, $settings['selected_themes'], true ) );
    ?>
									>
<?php echo esc_html( $theme->get( 'Name' ) );
    ?>

								</label>
<?php endforeach;
    ?>

							</div>
</div>
</div>
</div>
<div class="bma-field bma-notification-settings">
					<label for="bma-notification-email"><strong>Notification Email</strong></label>
					<input type="email" id="bma-notification-email" name="notification_email"
						value="<?php echo esc_attr( $settings['notification_email'] );
    ?>"
						placeholder="you@example.com" class="regular-text" aria-describedby="bma-notification-help">
					<p class="description" id="bma-notification-help">Receive alerts for blocked updates, failed updates, and failed post-update health checks, for both manual and scheduled operations. Leave blank to disable. Save Schedule to apply.</p>
					<?php $last_email = get_option( 'bma_us_last_email_alert', array() );
    ?>
					<?php if ( ! empty( $last_email ) && $last_email['recipient'] === $settings['notification_email'] ) : ?>
						<p class="description"><?php echo esc_html( 'Last alert: ' . wp_date( 'M j, Y \a\t g:i A', $last_email['checked'], wp_timezone() ) . ' - ' . ( 'accepted' === $last_email['status'] ? 'Accepted by WordPress mail system; inbox delivery is not confirmed.' : 'Could not send. Check your WordPress email configuration.' ) );
    ?></p>
					<?php endif;
    ?>
				</div>
<div class="bma-next-run">
<div>
<span class="dashicons dashicons-clock"></span>
<div>
<small>Next Scheduled Run</small>
<strong>
<?php
 if ( $settings['enabled'] && $next_timestamp ) {
        echo esc_html( wp_date( 'F j, Y \a\t g:i A', $next_timestamp, wp_timezone() ) );
        
    }
    else {
        echo 'No update scheduled';
        
    }
    ?>

							</strong>
</div>
</div>
<button
						type="submit"
						class="button button-primary"
					>
						Save Schedule
					</button>
</div>
</form>
</div>
<?php bma_us_render_update_history();
    ?>

</div>
<style>
.bma-update-scheduler {
    max-width:1400px;
    margin-top:25px;
    
}
.bma-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:30px;
    margin-bottom:25px;
    
}
.bma-header h1 {
    font-size:28px;
    margin:0 0 7px;
    
}
.bma-header p {
    margin:0;
    color:#646970;
    
}
.bma-header-meta {
    background:#fff;
    border:1px solid #dcdcde;
    border-radius:8px;
    padding:10px 15px;
    
}
.bma-summary-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:18px;
    margin-bottom:25px;
    
}
.bma-card {
    background:#fff;
    border:1px solid #dcdcde;
    border-radius:10px;
    padding:20px;
    
}
.bma-card-highlight {
    border-top:3px solid #2271b1;
    
}
.bma-card-label {
    font-size:13px;
    font-weight:600;
    color:#646970;
    margin-bottom:10px;
    
}
.bma-card-number {
    font-size:30px;
    font-weight:700;
    line-height:1.1;
    
}
.bma-card-description {
    color:#646970;
    font-size:13px;
    margin-top:8px;
    
}
.bma-section,.bma-scheduler-section {
    background:#fff;
    border:1px solid #dcdcde;
    border-radius:10px;
    margin-bottom:22px;
    overflow:hidden;
    
}
.bma-section-header,.bma-scheduler-header {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    padding:20px 22px;
    border-bottom:1px solid #e2e4e7;
    
}
.bma-section-header h2,.bma-scheduler-header h2 {
    margin:0 0 5px;
    font-size:18px;
    
}
.bma-section-header p,.bma-scheduler-header p {
    margin:0;
    color:#646970;
    
}
.bma-count {
    background:#f0f0f1;
    border-radius:20px;
    padding:2px 8px;
    font-size:12px;
    
}
.bma-update-count {
    background:#fff3cd;
    color:#755c00;
    border-radius:20px;
    padding:7px 12px;
    font-size:12px;
    font-weight:600;
    
}
.bma-table-wrapper {
    overflow-x:auto;
    
}
.bma-table {
    width:100%;
    border-collapse:collapse;
    
}
.bma-table th {
    text-align:left;
    padding:12px 18px;
    background:#f6f7f7;
    color:#50575e;
    font-size:12px;
    text-transform:uppercase;
    
}
.bma-table td {
    padding:14px 18px;
    border-top:1px solid #f0f0f1;
    vertical-align:middle;
    
}
.bma-table tbody tr:hover {
    background:#fafafa;
    
}
.bma-has-update {
    background:#fffdf5;
    
}
.bma-checkbox-column {
    width:55px;
    text-align:center !important;
    
}
.bma-update-checkbox {
    width:18px;
    height:18px;
    
}
.bma-item {
    display:flex;
    align-items:center;
    gap:12px;
    
}
.bma-item strong,.bma-item small {
    display:block;
    
}
.bma-item small {
    color:#8c8f94;
    margin-top:3px;
    
}
.bma-icon {
    width:34px;
    height:34px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:#2271b1;
    color:#fff;
    font-weight:700;
    flex:0 0 34px;
    
}
.bma-plugin-icon {
    background:#3858e9;
    
}
.bma-theme-icon {
    background:#7e22ce;
    
}
.bma-badge {
    display:inline-flex;
    align-items:center;
    border-radius:20px;
    padding:5px 9px;
    font-size:12px;
    font-weight:600;
    
}
.bma-active,.bma-status-current {
    background:#edfaef;
    color:#137333;
    
}
.bma-inactive {
    background:#f0f0f1;
    color:#646970;
    
}
.bma-status-update {
    background:#fff3cd;
    color:#755c00;
    
}
.bma-status-dot {
    width:7px;
    height:7px;
    background:currentColor;
    border-radius:50%;
    margin-right:6px;
    
}
.bma-new-version {
    color:#b26200;
    
}
.bma-muted {
    color:#a7aaad;
    
}
.bma-protected {
    color:#b32d2e !important;
    
}
.bma-action-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    background:#fff;
    border:1px solid #dcdcde;
    border-radius:10px;
    padding:15px 18px;
    margin:-5px 0 25px;
    
}
.bma-action-right {
    display:flex;
    align-items:center;
    gap:15px;
    
}
#bma-selection-count {
    color:#646970;
    
}
.bma-scheduler-section {
    margin-top:30px;
    
}
.bma-schedule-enabled {
    background:#edfaef;
    color:#137333;
    padding:7px 12px;
    border-radius:20px;
    font-weight:600;
    
}
.bma-schedule-disabled {
    background:#f0f0f1;
    color:#646970;
    padding:7px 12px;
    border-radius:20px;
    font-weight:600;
    
}
.bma-scheduler-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    padding:22px;
    
}
.bma-schedule-card {
    border:1px solid #e2e4e7;
    border-radius:9px;
    padding:20px;
    
}
.bma-schedule-card h3 {
    margin-top:0;
    
}
.bma-toggle-row {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    padding:14px 0;
    border-bottom:1px solid #f0f0f1;
    
}
.bma-toggle-row:last-child {
    border-bottom:0;
    
}
.bma-toggle-row strong,.bma-toggle-row small {
    display:block;
    
}
.bma-toggle-row small {
    color:#646970;
    margin-top:3px;
    
}
.bma-toggle-row input {
    width:20px;
    height:20px;
    
}
.bma-field {
    margin-bottom:18px;
    
}
.bma-field label {
    display:block;
    font-weight:600;
    margin-bottom:7px;
    
}
.bma-field select,.bma-field input {
    width:100%;
    max-width:100%;
    
}
.bma-timezone-note {
    background:#f6f7f7;
    padding:12px;
    border-radius:7px;
    
}
.bma-specific-selection {
    padding:0 22px 22px;
    
}
.bma-specific-selection > p {
    color:#646970;
    
}
.bma-selection-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    
}
.bma-selection-list {
    border:1px solid #e2e4e7;
    border-radius:8px;
    max-height:250px;
    overflow:auto;
    
}
.bma-selection-list label {
    display:block;
    padding:10px 12px;
    border-bottom:1px solid #f0f0f1;
    
}
.bma-selection-list label:last-child {
    border-bottom:0;
    
}
.bma-selection-list input {
    margin-right:7px;
    
}
.bma-update-scheduler .bma-notification-settings {
    margin:0;
    padding:22px;
    border-top:1px solid #e2e4e7;
    background:#fafbfc;
    box-sizing:border-box;
    
}
.bma-update-scheduler .bma-notification-settings label {
    margin-bottom:10px;
    color:#1d2327;
    font-size:14px;
    
}
.bma-update-scheduler .bma-notification-settings input[type="email"] {
    display:block;
    width:100%;
    max-width:480px;
    min-height:42px;
    margin:0;
    padding:8px 12px;
    border:1px solid #c3c4c7;
    border-radius:6px;
    background:#fff;
    box-sizing:border-box;
    
}
.bma-update-scheduler .bma-notification-settings input[type="email"]:focus {
    border-color:#2271b1;
    box-shadow:0 0 0 1px #2271b1;
    outline:2px solid transparent;
    
}
.bma-update-scheduler .bma-notification-settings .description {
    max-width:680px;
    margin:10px 0 0;
    color:#646970;
    font-size:13px;
    line-height:1.6;
    
}
.bma-next-run {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    background:#f0f6fc;
    border-top:1px solid #c5d9ed;
    padding:18px 22px;
    
}
.bma-next-run > div {
    display:flex;
    align-items:center;
    gap:12px;
    
}
.bma-next-run .dashicons {
    font-size:28px;
    width:28px;
    height:28px;
    color:#2271b1;
    
}
.bma-next-run small,.bma-next-run strong {
    display:block;
    
}
.bma-next-run small {
    color:#646970;
    margin-bottom:3px;
    
}
.bma-history-section {
    margin-top:30px;
    
}
.bma-last-run-summary {
    display:grid;
    grid-template-columns:2fr 1fr 1fr 1fr 1fr 1fr;
    gap:0;
    background:#f6f7f7;
    border-bottom:1px solid #dcdcde;
    
}
.bma-last-run-summary > div {
    padding:15px 20px;
    border-right:1px solid #dcdcde;
    
}
.bma-last-run-summary > div:last-child {
    border-right:0;
    
}
.bma-last-run-summary small {
    display:block;
    color:#646970;
    font-size:11px;
    text-transform:uppercase;
    font-weight:600;
    margin-bottom:5px;
    
}
.bma-last-run-summary strong {
    display:block;
    font-size:14px;
    
}
.bma-history-success-text {
    color:#008a20;
    
}
.bma-history-failed-text {
    color:#d63638;
    
}
.bma-history-empty {
    padding:55px 20px;
    text-align:center;
    
}
.bma-history-empty .dashicons {
    width:42px;
    height:42px;
    font-size:42px;
    color:#a7aaad;
    
}
.bma-history-empty h3 {
    margin:15px 0 5px;
    
}
.bma-history-empty p {
    margin:0;
    color:#646970;
    
}
.bma-history-date {
    white-space:nowrap;
    
}
.bma-history-date small {
    display:block;
    color:#8c8f94;
    margin-top:3px;
    
}
.bma-history-type {
    display:inline-block;
    background:#f0f0f1;
    padding:4px 8px;
    border-radius:5px;
    font-size:12px;
    font-weight:600;
    
}
.bma-version-change {
    white-space:nowrap;
    font-family:monospace;
    font-size:12px;
    
}
.bma-version-change span {
    color:#8c8f94;
    padding:0 3px;
    
}
.bma-trigger {
    display:inline-flex;
    align-items:center;
    gap:4px;
    font-size:12px;
    font-weight:600;
    white-space:nowrap;
    
}
.bma-trigger .dashicons {
    font-size:15px;
    width:15px;
    height:15px;
    
}
.bma-trigger-scheduled {
    color:#3858e9;
    
}
.bma-trigger-manual {
    color:#50575e;
    
}
.bma-history-status {
    display:inline-block;
    font-size:12px;
    font-weight:600;
    white-space:nowrap;
    
}
.bma-history-success {
    color:#008a20;
    
}
.bma-history-failed {
    color:#d63638;
    
}
.bma-history-skipped {
    color:#996800;
    
}
.bma-history-message {
    max-width:300px;
    color:#50575e;
    
}
.bma-history-footer {
    padding:12px 20px;
    background:#f6f7f7;
    border-top:1px solid #dcdcde;
    color:#646970;
    font-size:12px;
    
}
.bma-history-blocked {
    color:#b32d2e;
    font-weight:700;
    
}
.bma-history-blocked-text {
    color:#b32d2e;
    
}
.bma-backup-safety-section {
    margin-bottom:22px;
    
}
.bma-backup-status {
    display:inline-flex;
    align-items:center;
    border-radius:20px;
    padding:7px 12px;
    font-size:12px;
    font-weight:700;
    
}
.bma-backup-safety-grid {
    display:grid;
    grid-template-columns:repeat(6,1fr);
    border-bottom:1px solid #dcdcde;
    
}
.bma-backup-stat {
    padding:18px 20px;
    border-right:1px solid #e2e4e7;
    
}
.bma-backup-stat:last-child {
    border-right:0;
    
}
.bma-backup-stat small {
    display:block;
    color:#646970;
    font-size:11px;
    text-transform:uppercase;
    font-weight:600;
    margin-bottom:7px;
    
}
.bma-backup-stat strong {
    display:block;
    font-size:14px;
    
}
.bma-backup-good {
    background:#edfaef;
    color:#008a20;
    
}
.bma-backup-warning {
    background:#fff8e5;
    color:#996800;
    
}
.bma-backup-danger {
    background:#fcf0f1;
    color:#b32d2e;
    
}
.bma-backup-neutral {
    background:#f0f6fc;
    color:#2271b1;
    
}
.bma-backup-good-text {
    color:#008a20;
    
}
.bma-backup-warning-text {
    color:#996800;
    
}
.bma-backup-danger-text {
    color:#b32d2e;
    
}
.bma-backup-neutral-text {
    color:#646970;
    
}
.bma-backup-message {
    display:flex;
    align-items:flex-start;
    gap:12px;
    margin:18px 20px;
    padding:14px 16px;
    border-radius:8px;
    
}
.bma-backup-message .dashicons {
    font-size:22px;
    width:22px;
    height:22px;
    margin-top:1px;
    
}
.bma-backup-message strong {
    display:block;
    margin-bottom:4px;
    
}
.bma-backup-message p {
    margin:0;
    color:#50575e;
    
}
.bma-stage-note {
    display:flex;
    align-items:flex-start;
    gap:12px;
    margin:0 20px 20px;
    padding:14px 16px;
    background:#f6f7f7;
    border:1px solid #dcdcde;
    border-radius:8px;
    
}
.bma-stage-note .dashicons {
    color:#2271b1;
    font-size:20px;
    width:20px;
    height:20px;
    
}
.bma-stage-note strong {
    display:block;
    margin-bottom:3px;
    
}
.bma-stage-note p {
    margin:0;
    color:#646970;
    
}
.bma-backup-verification {
    margin:18px 20px;
    padding:16px;
    background:#f6f7f7;
    border:1px solid #dcdcde;
    border-radius:8px;
    
}
.bma-backup-verification-header {
    display:flex;
    align-items:flex-start;
    gap:10px;
    margin-bottom:14px;
    
}
.bma-backup-verification-header .dashicons {
    color:#00a32a;
    font-size:22px;
    width:22px;
    height:22px;
    
}
.bma-backup-verification-header strong {
    display:block;
    margin-bottom:3px;
    
}
.bma-backup-verification-header p {
    margin:0;
    color:#646970;
    
}
.bma-backup-components {
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:8px;
    
}
.bma-backup-component {
    background:#fff;
    border:1px solid #dcdcde;
    border-radius:6px;
    padding:10px 12px;
    
}
.bma-component-ok {
    color:#00a32a;
    font-weight:700;
    margin-right:5px;
    
}
.bma-component-failed {
    color:#d63638;
    font-weight:700;
    margin-right:5px;
    
}
@media(max-width:1000px) {
    .bma-backup-components {
    grid-template-columns:repeat(2,1fr);
    
}

}
@media(max-width:600px) {
    .bma-backup-components {
    grid-template-columns:1fr;
    
}

}
@media(max-width:1200px) {
    .bma-backup-safety-grid {
    grid-template-columns:repeat(3,1fr);
    
}
.bma-backup-stat {
    border-bottom:1px solid #e2e4e7;
    
}

}
@media(max-width:782px) {
    .bma-backup-safety-grid {
    grid-template-columns:1fr;
    
}
.bma-backup-stat {
    border-right:0;
    
}

}
@media(max-width:900px) {
    .bma-last-run-summary {
    grid-template-columns:1fr 1fr;
    
}
.bma-last-run-summary > div {
    border-bottom:1px solid #dcdcde;
    
}

}
@media(max-width:1100px) {
    .bma-summary-grid {
    grid-template-columns:repeat(2,1fr);
    
}

}
@media(max-width:782px) {
    .bma-header,.bma-action-bar,.bma-next-run {
    align-items:flex-start;
    flex-direction:column;
    
}
.bma-summary-grid,.bma-scheduler-grid,.bma-selection-grid {
    grid-template-columns:1fr;
    
}

}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
document.querySelectorAll('.bma-dismissible-report').forEach(function (panel) {
const key = panel.dataset.dismissKey;
const reportId = panel.dataset.reportId;
try {
if (localStorage.getItem(key) === reportId) {
panel.hidden = true;
return;
}
} catch (error) {}
panel.style.position = 'relative';
panel.style.paddingRight = '48px';
const close = document.createElement('button');
close.type = 'button';
close.className = 'button-link';
close.setAttribute('aria-label', 'Dismiss this report');
close.title = 'Dismiss this report';
close.style.cssText = 'position:absolute;top:12px;right:12px;padding:6px;color:#50575e;cursor:pointer;';
const icon = document.createElement('span');
icon.className = 'dashicons dashicons-no-alt';
icon.setAttribute('aria-hidden', 'true');
close.appendChild(icon);
close.addEventListener('click', function () {
panel.hidden = true;
try { localStorage.setItem(key, reportId); } catch (error) {}
});
panel.prepend(close);
});
const checkboxes =
document.querySelectorAll('.bma-update-checkbox');
const selectAll =
document.getElementById('bma-select-all');
const clearAll =
document.getElementById('bma-clear-all');
const updateButton =
document.getElementById('bma-update-now');
const counter =
document.getElementById('bma-selection-count');
function updateCounter() {
let selected = 0;
checkboxes.forEach(function (checkbox) {
if (checkbox.checked) {
selected++;
}
});
if (counter) {
counter.textContent =
selected + ' selected';
}
if (updateButton) {
updateButton.disabled =
selected === 0;
}
}
if (selectAll) {
selectAll.addEventListener(
'click',
function () {
checkboxes.forEach(function (checkbox) {
checkbox.checked = true;
});
updateCounter();
}
);
}
if (clearAll) {
clearAll.addEventListener(
'click',
function () {
checkboxes.forEach(function (checkbox) {
checkbox.checked = false;
});
updateCounter();
}
);
}
checkboxes.forEach(function (checkbox) {
checkbox.addEventListener(
'change',
updateCounter
);
});
const updateForm =
document.getElementById('bma-manual-update-form');
if (updateForm) {
updateForm.addEventListener(
'submit',
function (event) {
const confirmed = window.confirm(
'Update the selected components now? ' +
'Do not close this browser window until the update finishes.'
);
if (!confirmed) {
event.preventDefault();
return;
}
if (updateButton) {
updateButton.disabled = true;
updateButton.textContent = 'Updating...';
}
}
);
}
const frequency =
document.getElementById('bma-frequency');
const dayField =
document.getElementById('bma-day-field');
function updateFrequencyFields() {
if (!frequency || !dayField) {
return;
}
dayField.style.display =
frequency.value === 'daily'
? 'none'
: 'block';
}
if (frequency) {
frequency.addEventListener(
'change',
updateFrequencyFields
);
updateFrequencyFields();
}
});
</script>
<?php

}


function bma_us_render_last_update_debug() {
    $notice_data = bma_us_get_notice_data();
    if ( 'schedule_saved' === ( $notice_data['notice'] ?? '' ) ) {
        return;
        
    }
    $result = get_transient( 'bma_us_last_update_result' );
    if ( empty( $result ) || empty( $result['details'] ) ) {
        return;
        
    }
    $has_failure = false;
    $has_success = false;
    $has_blocked = false;
    foreach ( $result['details'] as $detail ) {
        if ( isset( $detail['status'] ) && 'failed' === $detail['status'] ) {
            $has_failure = true;
            
        }
        if ( isset( $detail['status'] ) && 'success' === $detail['status'] ) {
            $has_success = true;
            
        }
        if ( isset( $detail['status'] ) && 'blocked' === $detail['status'] ) {
            $has_blocked = true;
            
        }
        
    }
    $border_color = '#2271b1';
    $background = '#f0f6fc';
    if ( $has_failure || $has_blocked ) {
        $border_color = '#d63638';
        $background = '#fcf0f1';
        
    }
    elseif ( $has_success ) {
        $border_color = '#00a32a';
        $background = '#edfaef';
        
    }
    echo '<div class="bma-last-result bma-dismissible-report" data-dismiss-key="' . esc_attr( 'bma-us-result-' . get_current_blog_id() . '-' . get_current_user_id() ) . '" data-report-id="' . esc_attr( md5( serialize( array( $result, get_option( BMA_US_RESULT_CONTEXT_OPTION, array() ) ) ) ) ) . '" style="
		background:' . esc_attr( $background ) . ';
		border:1px solid #dcdcde;
		border-left:4px solid ' . esc_attr( $border_color ) . ';
		padding:18px;
		margin:20px 0;
		border-radius:6px;
	">';
    echo '<h2 style="margin-top:0;">Last Update Result</h2>';
    $context = get_option( BMA_US_RESULT_CONTEXT_OPTION, array() );
    if ( ! empty( $context['fingerprint'] ) && $context['fingerprint'] === md5( serialize( $result ) ) ) {
        echo '<p>' . esc_html( ucfirst( $context['trigger'] ) . ' update operation - ' . wp_date( 'M j, Y \a\t g:i A', $context['checked'], wp_timezone() ) ) . '</p>';
        
    }
    else {
        echo '<p>Previous update operation. This result is unrelated to schedule settings.</p>';
        
    }
    foreach ( $result['details'] as $detail ) {
        $status = isset( $detail['status'] ) ? $detail['status'] : '';
        $item = isset( $detail['item'] ) ? $detail['item'] : '';
        $type = isset( $detail['type'] ) ? $detail['type'] : '';
        $message = isset( $detail['message'] ) ? $detail['message'] : '';
        $component = bma_us_get_component_name( $type, $item );
        echo '<div style="
			padding:12px 0;
			border-bottom:1px solid rgba(0,0,0,.08);
		">';
        echo '<strong>Component:</strong> ' . esc_html( $component ) . '<br>';
        echo '<strong>Type:</strong> ' . esc_html( ucfirst( $type ) ) . '<br>';
        echo '<strong>Status:</strong> ' . esc_html( ucfirst( $status ) ) . '<br>';
        if ( ! empty( $detail['old_version'] ) || ! empty( $detail['new_version'] ) ) {
            echo '<strong>Version:</strong> ';
            echo esc_html( ! empty( $detail['old_version'] ) ? $detail['old_version'] : '—' );
            echo ' &rarr; ';
            echo esc_html( ! empty( $detail['new_version'] ) ? $detail['new_version'] : '—' );
            echo '<br>';
            
        }
        echo '<strong>Message:</strong> ' . esc_html( $message );
        echo '</div>';
        
    }
    echo '</div>';
    
}
add_action( 'admin_post_bma_us_clear_history', 'bma_us_clear_history' );


function bma_us_clear_history() {
    if ( ! current_user_can( 'update_core' ) ) {
        wp_die( 'You do not have permission to clear update history.' );
        
    }
    check_admin_referer( 'bma_us_clear_history' );
    delete_option( BMA_US_HISTORY_OPTION );
    bma_us_notice_redirect( add_query_arg( 'bma_notice', 'history_cleared', admin_url( 'tools.php?page=bma-update-scheduler' ) ) );
    exit;
    
}


function bma_us_render_update_history() {
    $history = bma_us_get_history();
    $last_run = get_option( BMA_US_LAST_RUN_OPTION, array() );
    ?>

<div class="bma-section bma-history-section">
<div class="bma-section-header">
<div>
<h2>
					Update History
<span class="bma-count">
						<?php echo esc_html( count( $history ) );
    ?>
					</span>
				</h2>
<p>
					Recent manual and scheduled update activity.
				</p>
</div>
<?php if ( ! empty( $history ) ) : ?>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) );
    ?>"
					onsubmit="return confirm('Clear all BMA Update Scheduler history?');"
				>
<input
						type="hidden"
						name="action"
						value="bma_us_clear_history"
					>
<?php
 wp_nonce_field( 'bma_us_clear_history' );
    ?>

					<button
						type="submit"
						class="button"
					>
						Clear History
					</button>
</form>
<?php endif;
    ?>

		</div>
<?php if ( ! empty( $last_run ) ) : ?>

			<div class="bma-last-run-summary">
<div>
<small>Last Update Run</small>
<strong>
<?php
 if ( ! empty( $last_run['timestamp'] ) ) {
        echo esc_html( wp_date( 'M j, Y \a\t g:i A', $last_run['timestamp'], wp_timezone() ) );
        
    }
    else {
        echo '—';
        
    }
    ?>

					</strong>
</div>
<div>
<small>Trigger</small>
<strong>
<?php
 echo esc_html( ucfirst( $last_run['trigger'] ?? '—' ) );
    ?>

					</strong>
</div>
<div>
<small>Successful</small>
<strong class="bma-history-success-text">
<?php
 echo esc_html( $last_run['success'] ?? 0 );
    ?>

					</strong>
</div>
<div>
<small>Failed</small>
<strong class="bma-history-failed-text">
<?php
 echo esc_html( $last_run['failed'] ?? 0 );
    ?>

					</strong>
</div>
<div>
<small>Skipped</small>
<strong>
<?php
 echo esc_html( $last_run['skipped'] ?? 0 );
    ?>

					</strong>
</div>
<div>
<small>Blocked</small>
<strong class="bma-history-blocked-text">
<?php
 echo esc_html( $last_run['blocked'] ?? 0 );
    ?>

	</strong>
</div>
</div>
<?php endif;
    ?>

<?php if ( empty( $history ) ) : ?>

<div class="bma-history-empty">
<span class="dashicons dashicons-backup"></span>
<h3>No update history yet</h3>
<p>
					New manual and scheduled updates will appear here.
				</p>
</div>
<?php else : ?>

<div class="bma-table-wrapper">
<table class="bma-table bma-history-table">
<thead>
<tr>
<th>Date &amp; Time</th>
<th>Component</th>
<th>Type</th>
<th>Version</th>
<th>Trigger</th>
<th>Status</th>
<th>Details</th>
</tr>
</thead>
<tbody>
<?php foreach ( $history as $record ) : ?>

<?php
 $status = $record['status'] ?? '';
    $type = $record['type'] ?? '';
    $trigger = $record['trigger'] ?? '';
    ?>

<tr>
<td class="bma-history-date">
<?php
 echo esc_html( wp_date( 'M j, Y', $record['timestamp'], wp_timezone() ) );
    ?>

								<small>
<?php
 echo esc_html( wp_date( 'g:i A', $record['timestamp'], wp_timezone() ) );
    ?>

								</small>
</td>
<td>
<strong>
<?php
 echo esc_html( $record['component'] ?? $record['item'] ?? 'Unknown' );
    ?>

								</strong>
</td>
<td>
<span class="bma-history-type">
<?php
 echo esc_html( ucfirst( $type ) );
    ?>

								</span>
</td>
<td>
<?php
 $old = $record['old_version'] ?? '';
    $new = $record['new_version'] ?? '';
    if ( $old || $new ) {
        echo '<span class="bma-version-change">';
        echo esc_html( $old ?: '—' );
        echo ' <span>&rarr;</span> ';
        echo esc_html( $new ?: '—' );
        echo '</span>';
        
    }
    else {
        echo '<span class="bma-muted">—</span>';
        
    }
    ?>

							</td>
<td>
<?php if ( 'scheduled' === $trigger ) : ?>

									<span class="bma-trigger bma-trigger-scheduled">
<span class="dashicons dashicons-clock"></span>
Scheduled
</span>
<?php else : ?>

									<span class="bma-trigger bma-trigger-manual">
<span class="dashicons dashicons-admin-users"></span>
Manual
</span>
<?php endif;
    ?>

							</td>
<td>
<?php if ( 'success' === $status ) : ?>

	<span class="bma-history-status bma-history-success">
		● Success
	</span>
<?php elseif ( 'failed' === $status ) : ?>

	<span class="bma-history-status bma-history-failed">
		● Failed
	</span>
<?php elseif ( 'blocked' === $status ) : ?>

	<span class="bma-history-status bma-history-blocked">
		● Blocked
	</span>
<?php else : ?>

	<span class="bma-history-status bma-history-skipped">
		● Skipped
	</span>
<?php endif;
    ?>

							</td>
<td class="bma-history-message">
<?php
 echo esc_html( $record['message'] ?? '—' );
    ?>

							</td>
</tr>
<?php endforeach;
    ?>

</tbody>
</table>
</div>
<div class="bma-history-footer">
Showing the most recent
<strong>
					<?php echo esc_html( count( $history ) );
    ?>
				</strong>
of up to
<strong>
					<?php echo esc_html( BMA_US_HISTORY_LIMIT );
    ?>
				</strong>
update records.
</div>
<?php endif;
    ?>

</div>
<?php

}
