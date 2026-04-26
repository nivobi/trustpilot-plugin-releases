<?php
/**
 * REST API Controller
 *
 * Registers /wp-json/tp/v1/ endpoints consumed by the React admin dashboard.
 * All endpoints require manage_options capability — never exposed publicly.
 *
 * Routes:
 *   GET    /tp/v1/status            — sync status + total review count
 *   GET    /tp/v1/presets           — list all presets
 *   POST   /tp/v1/presets           — create preset
 *   PUT    /tp/v1/presets/{slug}    — update preset (slug is immutable)
 *   DELETE /tp/v1/presets/{slug}    — delete preset
 *   POST   /tp/v1/sync              — trigger manual sync, return updated status
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TP_REST_API {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ 'TP_REST_API', 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( 'tp/v1', '/status', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ 'TP_REST_API', 'get_status' ],
			'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
		] );

		register_rest_route( 'tp/v1', '/presets', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ 'TP_REST_API', 'get_presets' ],
				'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ 'TP_REST_API', 'create_preset' ],
				'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
				'args'                => [
					'slug'      => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					],
					'name'      => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'keywords'  => [
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'min_stars' => [
						'type'     => 'integer',
						'required' => false,
						'default'  => 1,
						'minimum'  => 1,
						'maximum'  => 5,
					],
					'limit'     => [
						'type'     => 'integer',
						'required' => false,
						'default'  => 10,
						'minimum'  => 1,
						'maximum'  => 100,
					],
				],
			],
		] );

		register_rest_route( 'tp/v1', '/presets/(?P<slug>[a-z0-9\-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ 'TP_REST_API', 'update_preset' ],
				'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
				'args'                => [
					'name'      => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'keywords'  => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'min_stars' => [
						'type'     => 'integer',
						'required' => false,
						'minimum'  => 1,
						'maximum'  => 5,
					],
					'limit'     => [
						'type'     => 'integer',
						'required' => false,
						'minimum'  => 1,
						'maximum'  => 100,
					],
				],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ 'TP_REST_API', 'delete_preset' ],
				'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
			],
		] );

		register_rest_route( 'tp/v1', '/presets/(?P<slug>[a-z0-9\-]+)/preview', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ 'TP_REST_API', 'get_preset_preview' ],
			'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
			'args'                => [
				'count_only'    => [
					'type'    => 'boolean',
					'default' => false,
				],
				'preview_limit' => [
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				],
			],
		] );

		register_rest_route( 'tp/v1', '/sync', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ 'TP_REST_API', 'sync_now' ],
			'permission_callback' => [ 'TP_REST_API', 'check_permission' ],
			'args'                => [
				'force_full' => [
					'type'    => 'boolean',
					'default' => false,
				],
			],
		] );
	}

	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function get_status(): WP_REST_Response {
		return new WP_REST_Response( self::build_status(), 200 );
	}

	public static function get_presets(): WP_REST_Response {
		return new WP_REST_Response( TP_Preset_Manager::get_all(), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_preset( WP_REST_Request $request ) {
		$slug      = (string) $request->get_param( 'slug' );
		$name      = (string) $request->get_param( 'name' );
		$keywords  = (string) $request->get_param( 'keywords' );
		$min_stars = max( 1, min( 5, (int) $request->get_param( 'min_stars' ) ) );
		$limit     = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );

		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_slug', 'Slug is required.', [ 'status' => 400 ] );
		}

		$presets = TP_Preset_Manager::get_all();
		foreach ( $presets as $p ) {
			if ( $p['slug'] === $slug ) {
				return new WP_Error(
					'slug_exists',
					'A preset with that slug already exists.',
					[ 'status' => 409 ]
				);
			}
		}

		$new_preset = compact( 'slug', 'name', 'keywords', 'min_stars', 'limit' );
		$presets[]  = $new_preset;

		if ( ! TP_Preset_Manager::save( $presets ) ) {
			return new WP_Error( 'save_failed', 'Failed to save preset.', [ 'status' => 500 ] );
		}

		TP_Shortcode::bust_cache( $slug );

		return new WP_REST_Response( $new_preset, 201 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_preset( WP_REST_Request $request ) {
		$slug    = sanitize_title( (string) $request->get_param( 'slug' ) );
		$presets = TP_Preset_Manager::get_all();
		$found   = false;
		$updated = null;

		foreach ( $presets as &$preset ) {
			if ( $preset['slug'] !== $slug ) {
				continue;
			}
			if ( $request->has_param( 'name' ) ) {
				$preset['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
			}
			if ( $request->has_param( 'keywords' ) ) {
				$preset['keywords'] = sanitize_text_field( (string) $request->get_param( 'keywords' ) );
			}
			if ( $request->has_param( 'min_stars' ) ) {
				$preset['min_stars'] = max( 1, min( 5, (int) $request->get_param( 'min_stars' ) ) );
			}
			if ( $request->has_param( 'limit' ) ) {
				$preset['limit'] = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
			}
			$found   = true;
			$updated = $preset;
			break;
		}
		unset( $preset );

		if ( ! $found ) {
			return new WP_Error( 'not_found', 'Preset not found.', [ 'status' => 404 ] );
		}

		if ( ! TP_Preset_Manager::save( $presets ) ) {
			return new WP_Error( 'save_failed', 'Failed to save preset.', [ 'status' => 500 ] );
		}

		TP_Shortcode::bust_cache( $slug );

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_preset( WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );

		if ( null === TP_Preset_Manager::get_by_slug( $slug ) ) {
			return new WP_Error( 'not_found', 'Preset not found.', [ 'status' => 404 ] );
		}

		if ( ! TP_Preset_Manager::delete( $slug ) ) {
			return new WP_Error( 'delete_failed', 'Failed to delete preset.', [ 'status' => 500 ] );
		}

		TP_Shortcode::bust_cache( $slug );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_preset_preview( WP_REST_Request $request ) {
		$slug   = sanitize_title( (string) $request->get_param( 'slug' ) );
		$preset = TP_Preset_Manager::get_by_slug( $slug );

		if ( null === $preset ) {
			return new WP_Error( 'not_found', 'Preset not found.', [ 'status' => 404 ] );
		}

		global $wpdb;
		$table     = $wpdb->prefix . 'tp_reviews';
		$min_stars = max( 1, (int) ( $preset['min_stars'] ?? 1 ) );

		// Build WHERE clause dynamically — format string + params array for prepare().
		$format = 'stars >= %d';
		$params = [ $min_stars ];

		$raw_keywords = (string) ( $preset['keywords'] ?? '' );
		$keywords     = array_values( array_filter( array_map( 'trim', explode( ',', $raw_keywords ) ) ) );

		if ( ! empty( $keywords ) ) {
			$kw_placeholders = implode( ' OR ', array_fill( 0, count( $keywords ), 'body LIKE %s' ) );
			$format         .= " AND ( $kw_placeholders )";
			foreach ( $keywords as $kw ) {
				$params[] = '%' . $wpdb->esc_like( $kw ) . '%';
			}
		}

		// COUNT for badge.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$format}", ...$params )
		);

		if ( (bool) $request->get_param( 'count_only' ) ) {
			return new WP_REST_Response( [ 'count' => $count ], 200 );
		}

		$preview_limit = (int) $request->get_param( 'preview_limit' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT review_id, stars, title, body, author, published_at FROM `{$table}` WHERE {$format} ORDER BY published_at DESC LIMIT %d",
				...[ ...$params, $preview_limit ]
			),
			ARRAY_A
		);

		return new WP_REST_Response(
			[
				'count'   => $count,
				'reviews' => $rows ?? [],
			],
			200
		);
	}

	public static function sync_now( WP_REST_Request $request ): WP_REST_Response {
		if ( (bool) $request->get_param( 'force_full' ) ) {
			global $wpdb;
			delete_option( 'tp_sync_cursor' );
			delete_option( 'tp_sync_start' );
			update_option( 'tp_is_initial_sync_done', false );
			update_option( 'tp_full_sync_mode', true );
			update_option( 'tp_full_sync_processed', 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "UPDATE `{$wpdb->prefix}tp_reviews` SET last_synced_at = '2000-01-01 00:00:00'" );
		}
		TP_Sync_Engine::run();
		return new WP_REST_Response( self::build_status(), 200 );
	}

	/**
	 * Build the status payload shared by /status and /sync.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_status(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tp_reviews';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$cursor = get_option( 'tp_sync_cursor', null );

		$full_sync_mode = (bool) get_option( 'tp_full_sync_mode', false );

		return [
			'total_reviews'        => $total,
			'last_sync'            => (string) get_option( 'tp_last_sync', '' ),
			'last_sync_count'      => (int) get_option( 'tp_last_sync_count', 0 ),
			'last_error'           => (string) get_option( 'tp_last_error', '' ),
			'tp_review_count'      => (int) get_option( 'tp_review_count', 0 ),
			'sync_has_more'        => ! empty( $cursor ),
			'is_full_sync'         => $full_sync_mode,
			'full_sync_processed'  => (int) get_option( 'tp_full_sync_processed', 0 ),
		];
	}
}
