<?php
/**
 * Plugin Activator
 *
 * Handles plugin activation: database table creation via dbDelta(),
 * WP-Cron scheduling, and WP-CLI activation fallback.
 *
 * @package TrustpilotReviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TP_Activator
 *
 * All methods are public static so they can be called via callbacks
 * registered before the class is instantiated (activation hook, init hook).
 */
class TP_Activator {

	/**
	 * Plugin activation callback.
	 *
	 * Creates the database table and schedules the daily cron event.
	 * Called by register_activation_hook() in the plugin bootstrap file.
	 */
	public static function activate(): void {
		self::create_tables();
		if ( ! wp_next_scheduled( 'tp_daily_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'tp_daily_sync' );
		}
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * Clears the scheduled cron event. Does NOT drop the table —
	 * that is uninstall.php's responsibility (Pitfall P6).
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'tp_daily_sync' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'tp_daily_sync' );
		}
	}

	/**
	 * WP-CLI activation fallback (Pitfall P3).
	 *
	 * register_activation_hook() does not fire when activating via WP-CLI.
	 * This method is called on the 'init' hook; if the table doesn't exist,
	 * it creates it so the plugin works correctly after CLI activation.
	 */
	public static function maybe_create_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'tp_reviews';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
			self::create_tables();
		}
	}

	/**
	 * Creates the wp_tp_reviews table using dbDelta().
	 *
	 * IMPORTANT — dbDelta formatting rules (Pitfall P4):
	 *   - Two spaces between column name and data type
	 *   - PRIMARY KEY on its own line with two spaces before opening parenthesis
	 *   - UNIQUE KEY and KEY on their own lines
	 *   - No trailing comma after the last column definition
	 *
	 * After table creation, writes tp_db_version to wp_options.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'tp_reviews';

		$sql = "CREATE TABLE {$table_name} (
  id  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  review_id  VARCHAR(64) NOT NULL,
  stars  TINYINT(1) UNSIGNED NOT NULL,
  title  VARCHAR(500) NOT NULL DEFAULT '',
  body  LONGTEXT NOT NULL,
  author  VARCHAR(255) NOT NULL DEFAULT '',
  published_at  DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  language  VARCHAR(10) NOT NULL DEFAULT '',
  is_verified  TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  raw_json  LONGTEXT NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY review_id (review_id),
  KEY stars (stars),
  KEY published_at (published_at),
  FULLTEXT KEY body (body)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'tp_db_version', TP_PLUGIN_VERSION );
	}
}
