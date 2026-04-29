<?php
/**
 * Uninstall — runs when the admin deletes the plugin (not just deactivates it).
 *
 * Removes all plugin data:
 *   - The wp_tp_reviews custom table
 *   - All tp_* wp_options entries
 *   - The tp_daily_sync WP-Cron hook
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Drop the custom reviews table.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tp_reviews' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// 2. Delete all tp_* wp_options entries.

// Phase 1 — core runtime options.
delete_option( 'tp_db_version' );
delete_option( 'tp_last_sync' );
delete_option( 'tp_last_sync_count' );
delete_option( 'tp_last_error' );
delete_option( 'tp_trust_score' );
delete_option( 'tp_review_count' );
delete_option( 'tp_profile_url' );
delete_option( 'tp_is_initial_sync_done' );

// Phase 2 — API credentials.
delete_option( 'tp_api_key' );
delete_option( 'tp_api_secret' );
delete_option( 'tp_business_unit_id' );
delete_option( 'tp_business_domain' );   // Phase 2: raw domain input (new in Phase 2)

// Phase 3 — preset manager.
delete_option( 'tp_presets' );

// Phase 4 — sync state, schedule, migration flag.
delete_option( 'tp_sync_cursor' );
delete_option( 'tp_full_sync_mode' );
delete_option( 'tp_sync_start' );
delete_option( 'tp_full_sync_processed' );
delete_option( 'tp_sync_frequency' );
delete_option( 'tp_sync_time' );
delete_option( 'tp_migrations_done' );
delete_option( 'tp_date_format' );

// 2b. Belt-and-suspenders sweep — anything else namespaced under `tp_` from
//     prior versions, plus all preset-cache transients (and their timeouts).
//     The plugin owns the `tp_` prefix in wp_options exclusively, so a
//     prefix-LIKE delete is safe and survives schema drift across versions.
//     Transients live as `_transient_<key>` and `_transient_timeout_<key>`.
$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	  WHERE option_name LIKE %s
	     OR option_name LIKE %s
	     OR option_name LIKE %s",
	$wpdb->esc_like( 'tp_' ) . '%',
	$wpdb->esc_like( '_transient_tp_preset_cache_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_tp_preset_cache_' ) . '%'
) );

// 3. Clear the scheduled cron hook.
wp_clear_scheduled_hook( 'tp_daily_sync' );
