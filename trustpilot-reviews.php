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
		// Top-level menu item — appears in sidebar alongside Pages, Posts (D-02).
		add_menu_page(
			__( 'Trustpilot Reviews', 'trustpilot-reviews' ), // page_title
			__( 'Trustpilot', 'trustpilot-reviews' ),          // menu_title
			'manage_options',                                   // capability
			'tp-reviews',                                       // menu_slug
			[ $tp_settings, 'render' ],                        // callback (Settings page is default landing)
			'dashicons-star-filled',                            // icon
			80                                                  // position (after Settings)
		);

		// First sub-page uses SAME slug as parent — becomes default landing page (D-03, Pitfall P4).
		$settings_hook = add_submenu_page(
			'tp-reviews',
			__( 'Settings', 'trustpilot-reviews' ),
			__( 'Settings', 'trustpilot-reviews' ),
			'manage_options',
			'tp-reviews',                                       // SAME as parent slug (required for D-03)
			[ $tp_settings, 'render' ]
		);

		// Second sub-page — Dashboard.
		$dashboard_hook = add_submenu_page(
			'tp-reviews',
			__( 'Dashboard', 'trustpilot-reviews' ),
			__( 'Dashboard', 'trustpilot-reviews' ),
			'manage_options',
			'tp-dashboard',
			[ 'TP_Dashboard', 'render' ]
		);

		// Store hook suffixes back into the TP_Settings_Page instance so
		// enqueue_admin_styles() can gate CSS loading to plugin pages only.
		$tp_settings->settings_hook  = (string) $settings_hook;
		$tp_settings->dashboard_hook = (string) $dashboard_hook;

		// Third sub-page — Presets (D-01, Phase 3).
		$presets_hook = add_submenu_page(
			'tp-reviews',
			__( 'Presets', 'trustpilot-reviews' ),
			__( 'Presets', 'trustpilot-reviews' ),
			'manage_options',
			'tp-presets',
			[ 'TP_Preset_UI', 'render' ]
		);

		// Store hook suffix into TP_Preset_UI static property for CSS enqueue gate (D-02).
		TP_Preset_UI::$presets_hook = (string) $presets_hook;
	} );
}
