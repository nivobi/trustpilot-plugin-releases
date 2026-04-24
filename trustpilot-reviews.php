<?php
/**
 * Plugin Name:       Trustpilot Reviews
 * Plugin URI:        https://github.com/yourorg/trustpilot-reviews
 * Description:       Syncs Trustpilot reviews to a local database via WP-Cron and serves them through a Preset Manager shortcode system.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trustpilot-reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TP_PLUGIN_VERSION', '1.0.0' );
define( 'TP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TP_PLUGIN_FILE', __FILE__ );

require_once TP_PLUGIN_DIR . 'includes/class-activator.php';
require_once TP_PLUGIN_DIR . 'includes/class-api-client.php';
require_once TP_PLUGIN_DIR . 'includes/class-sync-engine.php';

register_activation_hook( __FILE__, [ 'TP_Activator', 'activate' ] );

register_deactivation_hook( __FILE__, [ 'TP_Activator', 'deactivate' ] );

add_action( 'init', function() {
	// Wire the daily sync cron hook to the Sync Engine callback.
	add_action( 'tp_daily_sync', [ 'TP_Sync_Engine', 'run' ] );

	// WP-CLI activation fallback (Pitfall P3): if the table doesn't exist, create it.
	TP_Activator::maybe_create_table();
} );
