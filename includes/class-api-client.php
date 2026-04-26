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
	 * Cached OAuth2 token for the lifetime of this instance.
	 *
	 * get_all_reviews() may call get_reviews() 78+ times for large accounts.
	 * Fetching a new token per page multiplies token-request overhead by the
	 * page count. The token is valid for 1 hour — safe to reuse within one
	 * sync run (a single TP_API_Client instance).
	 *
	 * @var string|null
	 */
	private ?string $cached_token = null;

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
		// Return cached token if already fetched this instance lifetime.
		if ( null !== $this->cached_token ) {
			return $this->cached_token;
		}

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
		$this->cached_token = $body['access_token'];
		return $this->cached_token;
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

		// Extract profile URL — try links array first, then webUrl, then construct from name.
		$profile_url = '';
		if ( ! empty( $body['links'] ) && is_array( $body['links'] ) ) {
			foreach ( $body['links'] as $link ) {
				if ( isset( $link['rel'], $link['href'] ) && 'profileUrl' === $link['rel'] ) {
					$profile_url = $link['href'];
					break;
				}
			}
		}
		// Fallback: name.referring can be a string or array.
		if ( empty( $profile_url ) && ! empty( $body['name']['referring'] ) ) {
			$referring   = is_array( $body['name']['referring'] )
				? (string) reset( $body['name']['referring'] )
				: (string) $body['name']['referring'];
			$profile_url = 'https://www.trustpilot.com/review/' . rawurlencode( $referring );
		}
		// Ensure absolute URL (API sometimes returns protocol-relative //trustpilot.com/...).
		if ( ! empty( $profile_url ) && str_starts_with( $profile_url, '//' ) ) {
			$profile_url = 'https:' . $profile_url;
		}

		return [
			'trust_score'  => (float) ( $body['score']['trustScore'] ?? 0.0 ),
			'review_count' => (int)   ( $body['numberOfReviews']['total'] ?? 0 ),
			'profile_url'  => $profile_url,
		];
	}

	/**
	 * Fetches one page of reviews from the /all-reviews cursor-based endpoint.
	 *
	 * Uses the /all-reviews endpoint which supports pageToken pagination — the
	 * only reliable way to walk all 7,000+ reviews without hitting plan limits.
	 * Reviews are returned newest-first (createdat.desc) so the sync engine can
	 * stop as soon as it encounters a review that already exists in the DB.
	 *
	 * Sanitizes all API response fields at the boundary (T-02-03).
	 *
	 * @param string|null $page_token Cursor token from a previous response, or null for the first page.
	 * @return array|WP_Error On success:
	 *   [
	 *     'reviews'         => array,       // sanitized review items for this page
	 *     'next_page_token' => string|null, // null when no more pages
	 *   ]
	 */
	public function get_reviews_page( ?string $page_token = null ): array|WP_Error {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$query_args = [
			'perPage' => 100,
			'orderBy' => 'createdat.desc',
		];

		if ( null !== $page_token ) {
			$query_args['pageToken'] = $page_token;
		}

		$url = add_query_arg(
			$query_args,
			self::API_BASE . '/business-units/' . rawurlencode( $this->business_unit_id ) . '/all-reviews'
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
				sprintf( 'Reviews request failed with HTTP %d (pageToken: %s)', $code, $page_token ?? 'none' )
			);
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
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
				// all-reviews uses isVerified (bool); legacy /reviews used reviewVerificationLevel (string).
				'is_verified'  => ( ! empty( $item['isVerified'] )
					|| ( isset( $item['reviewVerificationLevel'] )
						&& in_array( $item['reviewVerificationLevel'], [ 'VERIFIED', 'SEMI_VERIFIED' ], true ) ) ) ? 1 : 0,
				'raw_json'     => wp_json_encode( $item ),
			];
		}

		$next = ! empty( $body['nextPageToken'] ) ? (string) $body['nextPageToken'] : null;

		return [
			'reviews'         => $reviews,
			'next_page_token' => $next,
		];
	}
}
