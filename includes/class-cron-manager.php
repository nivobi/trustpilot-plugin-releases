<?php
/**
 * Cron Manager
 *
 * Centralizes all WP-Cron interaction for the daily sync. Reads
 * `tp_sync_frequency` and `tp_sync_time` options to compute the next
 * scheduled run, registers a custom 'weekly' interval (the others —
 * hourly, twicedaily, daily — ship with WP-Cron), and handles
 * re-scheduling whenever an admin changes the settings.
 *
 * @package TrustpilotReviews
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TP_Cron_Manager {

	/**
	 * The shared WP-Cron hook name. Bound to TP_Sync_Engine::run() in the
	 * plugin bootstrap.
	 */
	public const HOOK = 'tp_daily_sync';

	/**
	 * Allowed values for the `tp_sync_frequency` option. Each maps to a
	 * WP-Cron schedule slug. 'weekly' is registered by this class via the
	 * cron_schedules filter.
	 *
	 * @var string[]
	 */
	public const ALLOWED_FREQUENCIES = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];

	/**
	 * Register hooks. Called from the plugin bootstrap at file include time
	 * so the cron_schedules filter is in place before any wp_schedule_event
	 * call (including the activation hook).
	 */
	public static function register_hooks(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'register_weekly_schedule' ] );

		// Reschedule whenever frequency or time changes.
		foreach ( [ 'tp_sync_frequency', 'tp_sync_time' ] as $opt ) {
			add_action( 'update_option_' . $opt, [ __CLASS__, 'on_setting_changed' ] );
			add_action( 'add_option_' . $opt,    [ __CLASS__, 'on_setting_changed' ] );
		}
	}

	/**
	 * Add the 'weekly' interval. Hourly / twicedaily / daily already exist
	 * in WP core so we don't redefine them.
	 *
	 * @param array $schedules Existing schedules array.
	 * @return array Modified schedules array.
	 */
	public static function register_weekly_schedule( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = [];
		}
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'trustpilot-reviews' ),
			];
		}
		return $schedules;
	}

	/**
	 * Settings change callback — defer to reschedule().
	 */
	public static function on_setting_changed(): void {
		self::reschedule();
	}

	/**
	 * Unschedule any existing run and schedule a new one using the current
	 * tp_sync_frequency and tp_sync_time options. Idempotent.
	 */
	public static function reschedule(): void {
		$existing = wp_next_scheduled( self::HOOK );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::HOOK );
		}
		$slug    = self::get_frequency_slug();
		$next_ts = self::compute_next_run( $slug );
		wp_schedule_event( $next_ts, $slug, self::HOOK );
	}

	/**
	 * Sanitize and return the active frequency slug. Falls back to 'daily'.
	 */
	public static function get_frequency_slug(): string {
		$val = (string) get_option( 'tp_sync_frequency', 'daily' );
		return in_array( $val, self::ALLOWED_FREQUENCIES, true ) ? $val : 'daily';
	}

	/**
	 * Get the configured time-of-day as an HH:MM string. Falls back to '03:00'.
	 */
	public static function get_sync_time(): string {
		$val = (string) get_option( 'tp_sync_time', '03:00' );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $val ) ? $val : '03:00';
	}

	/**
	 * Compute the next-run unix timestamp.
	 *
	 * For hourly / twicedaily we let the next interval govern; first run
	 * fires within a minute so the admin sees activity immediately.
	 *
	 * For daily / weekly we honor `tp_sync_time` in the site timezone
	 * (`wp_timezone()`) so admins can pin the run to e.g. 03:30 local.
	 *
	 * @param string $slug Frequency slug.
	 * @return int Unix timestamp for first run.
	 */
	public static function compute_next_run( string $slug ): int {
		if ( 'hourly' === $slug || 'twicedaily' === $slug ) {
			return time() + MINUTE_IN_SECONDS;
		}

		$time_str = self::get_sync_time();

		try {
			$tz   = wp_timezone();
			$now  = new DateTimeImmutable( 'now', $tz );
			$next = new DateTimeImmutable( $now->format( 'Y-m-d' ) . ' ' . $time_str . ':00', $tz );
			if ( $next <= $now ) {
				$next = $next->modify( '+1 day' );
			}
			return $next->getTimestamp();
		} catch ( \Exception $e ) {
			return time() + HOUR_IN_SECONDS;
		}
	}

	/**
	 * Get the Unix timestamp of the next scheduled run, or 0 if none.
	 */
	public static function get_next_run(): int {
		return (int) wp_next_scheduled( self::HOOK );
	}

	/**
	 * Human-readable label for a frequency slug. Used in the dashboard.
	 */
	public static function frequency_label( string $slug ): string {
		switch ( $slug ) {
			case 'hourly':     return __( 'Hourly',       'trustpilot-reviews' );
			case 'twicedaily': return __( 'Twice daily',  'trustpilot-reviews' );
			case 'weekly':     return __( 'Weekly',       'trustpilot-reviews' );
			case 'daily':
			default:           return __( 'Daily',        'trustpilot-reviews' );
		}
	}
}
