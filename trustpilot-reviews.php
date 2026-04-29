<?php
/**
 * Plugin Name:       Nivobi Trustpilot Reviews
 * Plugin URI:        https://nivobi.com
 * Description:       Syncs Trustpilot reviews to a local database via WP-Cron and serves them through a Preset Manager shortcode system.
 * Version:           1.2.2
 * Requires at least: 6.4
 * Tested up to:      6.8
 * Requires PHP:      8.1
 * Author:            nivobi
 * Author URI:        https://nivobi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trustpilot-reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TP_PLUGIN_VERSION', '1.2.2' );
define( 'TP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TP_PLUGIN_FILE', __FILE__ );

require_once TP_PLUGIN_DIR . 'includes/class-activator.php';
require_once TP_PLUGIN_DIR . 'includes/class-api-client.php';
require_once TP_PLUGIN_DIR . 'includes/class-sync-engine.php';
require_once TP_PLUGIN_DIR . 'includes/class-preset-manager.php';
require_once TP_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once TP_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once TP_PLUGIN_DIR . 'includes/class-cron-manager.php';

TP_REST_API::register_hooks();
// Registers cron_schedules filter early so 'weekly' is known before any
// wp_schedule_event() call (activation hook fires after this include).
TP_Cron_Manager::register_hooks();

// -----------------------------------------------------------------------
// Update channel — Plugin Update Checker against the public release repo.
// New versions are published as GitHub Releases on the dist repo with the
// trustpilot-reviews.zip attached as an asset; PUC downloads the asset
// and lets WP install it via the standard "update available" flow.
// -----------------------------------------------------------------------
require_once TP_PLUGIN_DIR . 'lib/plugin-update-checker.php';

// Stored as a global so it can be reached for manual `checkForUpdates()` from
// WP-CLI / debug contexts; PUC's static factory keeps its own internal handle
// for normal scheduled checks regardless.
global $tp_update_checker;
$tp_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/nivobi/trustpilot-plugin-releases',
	__FILE__,
	'trustpilot-reviews'
);
$tp_update_checker->setBranch( 'master' );
// Use the .zip uploaded to each Release as the update payload (vs. building
// a tarball of repo source). Keeps the repo source tree free of release-only
// build artifacts and lets us strip dev-only files from the asset.
$tp_update_checker->getVcsApi()->enableReleaseAssets();

register_activation_hook( __FILE__, [ 'TP_Activator', 'activate' ] );

register_deactivation_hook( __FILE__, [ 'TP_Activator', 'deactivate' ] );

add_action( 'init', function() {
	// Wire the daily sync cron hook to the Sync Engine callback.
	add_action( 'tp_daily_sync', [ 'TP_Sync_Engine', 'run' ] );

	// WP-CLI activation fallback (Pitfall P3): if the table doesn't exist, create it.
	TP_Activator::maybe_create_table();

	// Register the [tp_reviews] shortcode and frontend CSS (D-16, RESEARCH.md Pattern 1).
	TP_Shortcode::register_hooks();
} );

/**
 * Admin layer — only loaded in the WordPress admin context.
 *
 * Registers the Trustpilot menu and sub-pages (D-01, D-02, D-03),
 * the Settings API hooks, and the Sync Now admin-post handler.
 */
if ( is_admin() ) {
	require_once TP_PLUGIN_DIR . 'admin/class-settings-page.php';
	require_once TP_PLUGIN_DIR . 'admin/class-dashboard.php';
	require_once TP_PLUGIN_DIR . 'admin/class-preset-ui.php';

	$tp_settings = new TP_Settings_Page();
	$tp_settings->register_hooks();
	TP_Dashboard::register_hooks();
	TP_Preset_UI::register_hooks();

	add_action( 'admin_menu', function() use ( $tp_settings ) {
		// Top-level menu item — Dashboard is the default landing page.
		add_menu_page(
			__( 'Trustpilot Reviews', 'trustpilot-reviews' ),
			__( 'Trustpilot', 'trustpilot-reviews' ),
			'manage_options',
			'tp-reviews',
			[ 'TP_Preset_UI', 'render' ],
			'dashicons-star-filled',
			80
		);

		// First sub-page uses SAME slug as parent — becomes default landing page (Dashboard).
		$presets_hook = add_submenu_page(
			'tp-reviews',
			__( 'Dashboard', 'trustpilot-reviews' ),
			__( 'Dashboard', 'trustpilot-reviews' ),
			'manage_options',
			'tp-reviews',
			[ 'TP_Preset_UI', 'render' ]
		);
		TP_Preset_UI::$presets_hook = (string) $presets_hook;

		// Second sub-page — Settings.
		$settings_hook = add_submenu_page(
			'tp-reviews',
			__( 'Settings', 'trustpilot-reviews' ),
			__( 'Settings', 'trustpilot-reviews' ),
			'manage_options',
			'tp-settings',
			[ $tp_settings, 'render' ]
		);
		$tp_settings->settings_hook = (string) $settings_hook;
	} );
}
