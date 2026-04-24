<?php
/**
 * Sync Engine
 *
 * WP-Cron callback that fetches reviews from the Trustpilot API and upserts
 * them into the local database. Also writes compliance data (TrustScore,
 * review count, profile URL) and sync-status options after every run.
 *
 * Key design constraints:
 *   - NEVER runs DELETE or TRUNCATE on the reviews table (FOUND-04).
 *   - Uses INSERT ... ON DUPLICATE KEY UPDATE on review_id (FOUND-03).
 *   - Incremental sync: first run fetches all; subsequent runs use startDateTime (D-01).
 *   - Errors logged to WP_DEBUG_LOG with [TrustpilotReviews] prefix (FOUND-05).
 *   - tp_last_sync, tp_last_sync_count, tp_last_error written after every run (D-05).
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TP_Sync_Engine
 *
 * All methods are static. The class is stateless — all persistence happens
 * through wp_options and wpdb.
 */
class TP_Sync_Engine {

	/**
	 * Log prefix for all WP_DEBUG_LOG entries.
	 */
	private const LOG_PREFIX = '[TrustpilotReviews]';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Main WP-Cron callback registered to the 'tp_daily_sync' action.
	 *
	 * Execution flow:
	 *   1. Fetch business unit data (TrustScore, review count, profile URL).
	 *   2. Determine since_date for incremental sync (D-01).
	 *   3. Fetch all reviews (full or incremental).
	 *   4. Upsert each review into wp_tp_reviews.
	 *   5. Write compliance options to wp_options (D-04).
	 *   6. Write sync-status options to wp_options (D-05).
	 *
	 * On any API failure, existing DB rows are left untouched and tp_last_error
	 * is written with the error message (FOUND-04).
	 */
	public static function run(): void {
		$client = new TP_API_Client();

		// --- Step 1: Fetch business unit data (D-04) ---
		$business_unit = $client->get_business_unit();

		if ( is_wp_error( $business_unit ) ) {
			self::handle_error(
				'Business unit fetch failed: ' . $business_unit->get_error_message(),
				0
			);
			return;
		}

		// Write compliance data immediately — even if the review fetch fails,
		// the TrustScore snapshot is valid and should be persisted (D-04).
		update_option( 'tp_trust_score',  $business_unit['trust_score'] );
		update_option( 'tp_review_count', $business_unit['review_count'] );
		update_option( 'tp_profile_url',  $business_unit['profile_url'] );

		// --- Step 2: Determine since_date for incremental sync (D-01) ---
		$is_initial_done = (bool) get_option( 'tp_is_initial_sync_done', false );
		$since_date      = '';

		if ( $is_initial_done ) {
			// Use the ISO 8601 timestamp recorded at the end of the last successful
			// sync run as the startDateTime filter for the API request (D-01).
			$since_date = (string) get_option( 'tp_last_sync', '' );
		}

		// --- Step 3: Fetch reviews (full or incremental) ---
		$reviews = $client->get_all_reviews( $since_date );

		if ( is_wp_error( $reviews ) ) {
			self::handle_error(
				'Review fetch failed: ' . $reviews->get_error_message(),
				0
			);
			return;
		}

		// --- Step 4: Upsert each review ---
		$upserted = 0;

		foreach ( $reviews as $review ) {
			$result = self::upsert_review( $review );
			if ( false !== $result ) {
				$upserted++;
			}
		}

		// --- Step 5: Mark initial sync complete (D-01) ---
		if ( ! $is_initial_done ) {
			update_option( 'tp_is_initial_sync_done', true );
		}

		// --- Step 6: Write sync-status options (D-05) ---
		update_option( 'tp_last_sync',       gmdate( 'c' ) ); // ISO 8601 UTC timestamp.
		update_option( 'tp_last_sync_count', $upserted );
		update_option( 'tp_last_error',      '' );            // Clear any previous error.

		error_log( sprintf(
			'%s Sync complete — upserted %d review(s). Trust score: %s, total reviews: %d.',
			self::LOG_PREFIX,
			$upserted,
			$business_unit['trust_score'],
			$business_unit['review_count']
		) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Upserts a single review into the wp_tp_reviews table.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE so that:
	 *   - New reviews are inserted.
	 *   - Existing reviews (matched by the UNIQUE KEY on review_id) are updated
	 *     in place — no duplicates, no data loss (FOUND-03, FOUND-04).
	 *
	 * The reviews table is NEVER truncated or deleted from here (FOUND-04).
	 *
	 * @param array $review Sanitized review data from TP_API_Client::get_all_reviews().
	 *
	 * @return int|false Number of affected rows on success, false on DB error.
	 */
	private static function upsert_review( array $review ): int|false {
		global $wpdb;

		$table = $wpdb->prefix . 'tp_reviews';

		// Convert the ISO 8601 published_at string from the API to MySQL DATETIME.
		$published_at_mysql = self::iso8601_to_datetime( $review['published_at'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}`
					( review_id,  stars,  title,  body,  author,  published_at,  language,  is_verified,  raw_json )
				VALUES
					( %s,         %d,     %s,     %s,    %s,      %s,            %s,        %d,           %s )
				ON DUPLICATE KEY UPDATE
					stars        = VALUES( stars ),
					title        = VALUES( title ),
					body         = VALUES( body ),
					author       = VALUES( author ),
					published_at = VALUES( published_at ),
					language     = VALUES( language ),
					is_verified  = VALUES( is_verified ),
					raw_json     = VALUES( raw_json )",
				$review['review_id'],
				$review['stars'],
				$review['title'],
				$review['body'],
				$review['author'],
				$published_at_mysql,
				$review['language'],
				$review['is_verified'],
				$review['raw_json']
			)
		);

		if ( false === $result ) {
			error_log( sprintf(
				'%s DB upsert failed for review_id %s — %s',
				self::LOG_PREFIX,
				$review['review_id'],
				$wpdb->last_error
			) );
		}

		return $result;
	}

	/**
	 * Logs an error, writes tp_last_error and tp_last_sync options, then returns.
	 *
	 * Called whenever a fatal error (API failure, DB setup problem) prevents the
	 * sync from completing. Existing rows in the reviews table are never touched
	 * (FOUND-04) — the method only writes to wp_options.
	 *
	 * @param string $message  Human-readable error description. Must not contain
	 *                         secrets (OAuth tokens, API keys) — only HTTP status
	 *                         codes and error codes (T-02-02).
	 * @param int    $synced   Number of rows successfully upserted before the error
	 *                         (0 if failure occurred before any upserts).
	 */
	private static function handle_error( string $message, int $synced ): void {
		error_log( sprintf( '%s %s', self::LOG_PREFIX, $message ) );

		update_option( 'tp_last_sync',       gmdate( 'c' ) );
		update_option( 'tp_last_sync_count', $synced );
		update_option( 'tp_last_error',      $message );
	}

	/**
	 * Converts an ISO 8601 timestamp string to a MySQL DATETIME string.
	 *
	 * The Trustpilot API returns timestamps such as "2024-03-15T14:32:10Z" or
	 * "2024-03-15T14:32:10+00:00". MySQL DATETIME expects "Y-m-d H:i:s".
	 *
	 * Falls back to the MySQL zero-date string if parsing fails, so a malformed
	 * timestamp from the API never causes a DB error.
	 *
	 * @param string $iso8601 ISO 8601 datetime string from the Trustpilot API.
	 *
	 * @return string MySQL DATETIME string ("Y-m-d H:i:s"), e.g. "2024-03-15 14:32:10".
	 */
	private static function iso8601_to_datetime( string $iso8601 ): string {
		if ( empty( $iso8601 ) || '0000-00-00 00:00:00' === $iso8601 ) {
			return '0000-00-00 00:00:00';
		}

		try {
			$dt = new DateTimeImmutable( $iso8601, new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			error_log( sprintf(
				'%s iso8601_to_datetime: could not parse "%s" — %s',
				self::LOG_PREFIX,
				$iso8601,
				$e->getMessage()
			) );
			return '0000-00-00 00:00:00';
		}
	}
}
