<?php
/**
 * Trustpilot API Client
 *
 * Handles OAuth2 token exchange, business unit data fetching, and paginated
 * review fetching from the Trustpilot API. All HTTP calls use WordPress HTTP
 * API functions — no Composer dependencies or cURL calls.
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TP_API_Client
 *
 * Provides four public methods consumed by TP_Sync_Engine:
 *   - get_access_token()   — OAuth2 client_credentials token (fresh every call, D-03)
 *   - get_business_unit()  — TrustScore, review count, profile URL
 *   - get_reviews()        — Single-page review fetch with Bearer token (D-06)
 *   - get_all_reviews()    — Full paginated fetch returning flat review array
 */
class TP_API_Client {

	/**
	 * Base URL for all Trustpilot API v1 requests.
	 */
	private const API_BASE = 'https://api.trustpilot.com/v1';

	/**
	 * OAuth2 token endpoint for client_credentials grant type.
	 */
	private const TOKEN_URL = 'https://api.trustpilot.com/v1/oauth/oauth-business-users-for-applications/accesstoken';

	/**
	 * Trustpilot API key (client_id for OAuth2).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Trustpilot API secret (client_secret for OAuth2).
	 *
	 * @var string
	 */
	private string $api_secret;

	/**
	 * Trustpilot Business Unit ID.
	 *
	 * @var string
	 */
	private string $business_unit_id;

	/**
	 * Constructor — reads credentials from wp_options.
	 *
	 * Credentials are stored server-side only; never output to the browser.
	 */
	public function __construct() {
		$this->api_key          = get_option( 'tp_api_key', '' );
		$this->api_secret       = get_option( 'tp_api_secret', '' );
		$this->business_unit_id = get_option( 'tp_business_unit_id', '' );
	}

	/**
	 * Obtains an OAuth2 Bearer token via client_credentials grant.
	 *
	 * Fetches a fresh token on every call — no transient caching per D-03.
	 * The access_token string is never written to any log; only HTTP status
	 * codes appear in error messages (T-02-01).
	 *
	 * @return string|WP_Error Bearer token string on success, WP_Error on failure.
	 */
	public function get_access_token(): string|WP_Error {
		// Basic auth: base64-encode "client_id:client_secret" — T-02-01, T-02-02.
		$credentials = base64_encode( $this->api_key . ':' . $this->api_secret );

		$response = wp_remote_post( self::TOKEN_URL, [
			'headers' => [
				'Authorization' => 'Basic ' . $credentials,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => 'grant_type=client_credentials',
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'tp_token_error',
				sprintf( 'OAuth2 token request failed with HTTP %d', $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'tp_token_missing', 'OAuth2 response missing access_token field' );
		}

		// SECURITY: $credentials and $body['access_token'] are NEVER passed to error_log().
		return $body['access_token'];
	}

	/**
	 * Fetches business unit summary from the public Trustpilot endpoint.
	 *
	 * Uses apikey header (public endpoint — no OAuth2 needed here). Returns
	 * the three fields that every Trustpilot-compliant output must display
	 * (D-04) — TrustScore, review count, and profile URL.
	 *
	 * Error messages contain only HTTP codes; api_key is never logged (T-02-02).
	 *
	 * @return array|WP_Error Associative array on success:
	 *   [
	 *     'trust_score'  => float,  // e.g. 4.7
	 *     'review_count' => int,    // total published reviews
	 *     'profile_url'  => string, // Trustpilot public profile URL
	 *   ]
	 *   WP_Error on failure.
	 */
	public function get_business_unit(): array|WP_Error {
		$url = self::API_BASE . '/business-units/' . rawurlencode( $this->business_unit_id );

		$response = wp_remote_get( $url, [
			'headers' => [
				'apikey' => $this->api_key,
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'tp_business_unit_error',
				sprintf( 'Business unit request failed with HTTP %d', $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Extract profile URL from links array — find the entry where rel == 'profileUrl'.
		$profile_url = '';
		if ( ! empty( $body['links'] ) && is_array( $body['links'] ) ) {
			foreach ( $body['links'] as $link ) {
				if ( isset( $link['rel'], $link['href'] ) && 'profileUrl' === $link['rel'] ) {
					$profile_url = $link['href'];
					break;
				}
			}
		}

		return [
			'trust_score'  => (float) ( $body['score']['trustScore'] ?? 0.0 ),
			'review_count' => (int)   ( $body['numberOfReviews']['total'] ?? 0 ),
			'profile_url'  => $profile_url,
		];
	}

	/**
	 * Fetches a single page of reviews using OAuth2 Bearer token.
	 *
	 * OAuth2 Bearer token is required for the reviews endpoint regardless of
	 * account tier (D-06, Pitfall P1). Token is obtained fresh from
	 * get_access_token() on every call (D-03 — no caching).
	 *
	 * The $since_date parameter enables incremental sync (D-01): pass the
	 * ISO 8601 timestamp of the last successful sync to fetch only new reviews.
	 * The Sync Engine converts returned published_at values from ISO 8601 to
	 * MySQL DATETIME before the DB upsert.
	 *
	 * All API response fields are sanitized at the boundary (T-02-03):
	 * - Strings: sanitize_text_field()
	 * - Review body: wp_kses_post() (preserves basic formatting tags)
	 * - Integers: (int) cast
	 * - Raw JSON: wp_json_encode()
	 *
	 * @param int    $page       Page number (1-based). Default 1.
	 * @param string $since_date ISO 8601 date string or '' for all reviews.
	 *
	 * @return array|WP_Error Associative array on success:
	 *   [
	 *     'reviews'     => array,  // flat array of sanitized review items
	 *     'total_pages' => int,    // total pages available
	 *   ]
	 *   WP_Error on failure.
	 */
	public function get_reviews( int $page = 1, string $since_date = '' ): array|WP_Error {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$query_args = [
			'perPage' => 100,
			'page'    => $page,
			'orderBy' => 'createdat.asc',
		];

		// D-01: Incremental sync — pass startDateTime when fetching since last sync.
		if ( ! empty( $since_date ) ) {
			$query_args['startDateTime'] = $since_date;
		}

		$url = add_query_arg(
			$query_args,
			self::API_BASE . '/business-units/' . rawurlencode( $this->business_unit_id ) . '/reviews'
		);

		$response = wp_remote_get( $url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'apikey'        => $this->api_key,
			],
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'tp_reviews_error',
				sprintf( 'Reviews request failed with HTTP %d on page %d', $code, $page )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Sanitize all fields from the untrusted API response at the boundary (T-02-03).
		$reviews     = [];
		$raw_reviews = $body['reviews'] ?? [];

		foreach ( $raw_reviews as $item ) {
			$reviews[] = [
				'review_id'    => sanitize_text_field( $item['id'] ?? '' ),
				'stars'        => (int) ( $item['stars'] ?? 0 ),
				'title'        => sanitize_text_field( $item['title'] ?? '' ),
				'body'         => wp_kses_post( $item['text'] ?? '' ),
				'author'       => sanitize_text_field( $item['consumer']['displayName'] ?? '' ),
				'published_at' => sanitize_text_field( $item['createdAt'] ?? '0000-00-00 00:00:00' ),
				'language'     => sanitize_text_field( $item['language'] ?? '' ),
				'is_verified'  => ( isset( $item['reviewVerificationLevel'] ) && 'BUSINESS_GENERATED' === $item['reviewVerificationLevel'] ) ? 1 : 0,
				'raw_json'     => wp_json_encode( $item ),
			];
		}

		// Calculate total pages from the API-reported total (T-02-05 — not from user input).
		$total_reviews = (int) ( $body['totalNumberOfReviews'] ?? 0 );
		$total_pages   = ( $total_reviews > 0 ) ? (int) ceil( $total_reviews / 100 ) : 1;

		return [
			'reviews'     => $reviews,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * Fetches all reviews by paginating through all available pages.
	 *
	 * Calls get_reviews() in a loop, accumulating results. Aborts immediately
	 * and propagates the WP_Error if any page fetch fails. The pagination
	 * terminates when page > total_pages (T-02-05 — loop bounded by
	 * API-reported total, not user input).
	 *
	 * @param string $since_date ISO 8601 date string or '' for all reviews (D-01).
	 *
	 * @return array|WP_Error Flat array of all sanitized review items on success,
	 *                        WP_Error on any page failure.
	 */
	public function get_all_reviews( string $since_date = '' ): array|WP_Error {
		$all_reviews = [];
		$page        = 1;

		do {
			$result = $this->get_reviews( $page, $since_date );

			if ( is_wp_error( $result ) ) {
				return $result; // Abort and propagate the error.
			}

			$all_reviews = array_merge( $all_reviews, $result['reviews'] );
			$total_pages = $result['total_pages'];
			$page++;

		} while ( $page <= $total_pages );

		return $all_reviews;
	}
}
