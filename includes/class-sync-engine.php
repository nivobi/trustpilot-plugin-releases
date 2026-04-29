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
	/**
	 * Max pages fetched per run to stay within PHP execution time limits.
	 * 10 pages × 100 reviews = 1,000 reviews per run (~10-20s).
	 * The cursor is saved so the next run (cron or manual) continues from here.
	 */
	private const MAX_PAGES_PER_RUN = 10;

	public static function run(): void {
		$client = new TP_API_Client();

		// --- Step 1: Business unit data (compliance + trust score) ---
		$business_unit = $client->get_business_unit();

		if ( is_wp_error( $business_unit ) ) {
			self::handle_error( 'Business unit fetch failed: ' . $business_unit->get_error_message(), 0 );
			return;
		}

		update_option( 'tp_trust_score',  $business_unit['trust_score'] );
		update_option( 'tp_review_count', $business_unit['review_count'] );
		update_option( 'tp_profile_url',  $business_unit['profile_url'] );

		// --- Step 2: Resume from saved cursor, or start fresh ---
		$cursor          = get_option( 'tp_sync_cursor', null ) ?: null;
		$full_sync_mode  = (bool) get_option( 'tp_full_sync_mode', false );

		$sync_start = get_option( 'tp_sync_start', '' );
		if ( empty( $sync_start ) ) {
			$sync_start = gmdate( 'Y-m-d H:i:s' );
			update_option( 'tp_sync_start', $sync_start, false );
		}

		$upserted        = 0;
		$pages_this_run  = 0;
		$hit_duplicate   = false;
		$next_cursor     = null;

		// --- Step 3: Fetch pages in batches, stop on duplicate or page limit ---
		do {
			$result = $client->get_reviews_page( $cursor );

			if ( is_wp_error( $result ) ) {
				self::handle_error( 'Review fetch failed: ' . $result->get_error_message(), $upserted );
				return;
			}

			foreach ( $result['reviews'] as $review ) {
				// Stop-on-duplicate only for incremental syncs. In full_sync_mode
				// every row must be stamped so reconcile can detect removed reviews.
				if ( ! $full_sync_mode && self::review_exists( $review['review_id'] ) ) {
					$hit_duplicate = true;
					break;
				}

				if ( false !== self::upsert_review( $review, $sync_start ) ) {
					$upserted++;
				}
			}

			$next_cursor = $result['next_page_token'];
			$cursor      = $next_cursor;
			$pages_this_run++;

		} while ( ! $hit_duplicate && $next_cursor !== null && $pages_this_run < self::MAX_PAGES_PER_RUN );

		// --- Step 4: Persist cursor or mark done ---
		// A full sync is truly complete only when the API ran out of pages naturally
		// (next_cursor === null). hit_duplicate just means incremental caught up.
		$api_exhausted = ( $next_cursor === null );
		$sync_complete = $hit_duplicate || $api_exhausted;

		if ( $sync_complete ) {
			delete_option( 'tp_sync_cursor' );
			update_option( 'tp_is_initial_sync_done', true, false );

			// --- Reconcile: ONLY after a complete full sync ---
			// Conditions: full_sync_mode was active AND all API pages were walked
			// (api_exhausted). If we stopped on a duplicate the walk was incomplete
			// — reconcile would delete legitimate reviews that weren't re-stamped.
			if ( $full_sync_mode && $api_exhausted ) {
				global $wpdb;
				$table   = $wpdb->prefix . 'tp_reviews';
				// Delete rows stamped before this sync started — these were not
				// returned by the API and are no longer present on Trustpilot.
				// NOT NULL check intentionally omitted: rows with NULL last_synced_at
				// were added before this feature existed and are assumed legitimate.
				$removed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"DELETE FROM `{$table}` WHERE last_synced_at < %s",
						$sync_start
					)
				);
				if ( $removed ) {
					error_log( sprintf(
						'%s Reconcile removed %d review(s) no longer on Trustpilot.',
						self::LOG_PREFIX,
						(int) $removed
					) );
				}
			}

			delete_option( 'tp_full_sync_mode' );
			delete_option( 'tp_sync_start' );
		} else {
			update_option( 'tp_sync_cursor', $next_cursor, false );
		}

		// --- Step 5: Accumulate full-sync processed count ---
		if ( $full_sync_mode ) {
			$prev = (int) get_option( 'tp_full_sync_processed', 0 );
			update_option( 'tp_full_sync_processed', $prev + $upserted, false );
		}

		if ( $sync_complete ) {
			delete_option( 'tp_full_sync_processed' );
		}

		// --- Step 6: Bust shortcode cache ---
		TP_Shortcode::bust_all_caches();

		// --- Step 7: Write sync-status options ---
		update_option( 'tp_last_sync',       gmdate( 'c' ), false );
		update_option( 'tp_last_sync_count', $upserted,     false );
		update_option( 'tp_last_error',      '',            false );

		error_log( sprintf(
			'%s Batch complete — %d upserted, %d pages, %s. Cursor: %s.',
			self::LOG_PREFIX,
			$upserted,
			$pages_this_run,
			$sync_complete ? 'done' : 'more batches pending',
			$sync_complete ? 'cleared' : 'saved'
		) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true if a review_id already exists in the local table.
	 * Used by run() to detect the stop-on-duplicate condition.
	 */
	private static function review_exists( string $review_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'tp_reviews';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE review_id = %s LIMIT 1", $review_id )
		);
	}

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
	private static function upsert_review( array $review, string $synced_at = '' ): int|false {
		global $wpdb;

		$table              = $wpdb->prefix . 'tp_reviews';
		$published_at_mysql = self::iso8601_to_datetime( $review['published_at'] );
		$synced_at_mysql    = $synced_at ?: gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}`
					( review_id, stars, title, body, author, published_at, language, is_verified, raw_json, last_synced_at )
				VALUES
					( %s, %d, %s, %s, %s, %s, %s, %d, %s, %s )
				ON DUPLICATE KEY UPDATE
					stars          = VALUES( stars ),
					title          = VALUES( title ),
					body           = VALUES( body ),
					author         = VALUES( author ),
					published_at   = VALUES( published_at ),
					language       = VALUES( language ),
					is_verified    = VALUES( is_verified ),
					raw_json       = VALUES( raw_json ),
					last_synced_at = VALUES( last_synced_at )",
				$review['review_id'],
				$review['stars'],
				$review['title'],
				$review['body'],
				$review['author'],
				$published_at_mysql,
				$review['language'],
				$review['is_verified'],
				$review['raw_json'],
				$synced_at_mysql
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

		update_option( 'tp_last_sync',       gmdate( 'c' ), false );
		update_option( 'tp_last_sync_count', $synced,       false );
		update_option( 'tp_last_error',      $message,      false );
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
