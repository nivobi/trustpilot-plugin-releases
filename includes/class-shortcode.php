<?php
/**
 * Shortcode Renderer
 *
 * Registers and renders the [tp_reviews id="preset-slug"] shortcode.
 * All methods are static — this class is stateless.
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Shortcode {

    /**
     * Prevents duplicate JSON-LD output when multiple shortcodes appear on one page.
     * Set to true after the first render_json_ld() call. (CLAUDE.md Pitfall P4, D-12)
     *
     * @var bool
     */
    public static bool $schema_rendered = false;

    /**
     * Register WordPress hooks.
     * Called from bootstrap in trustpilot-reviews.php init closure (D-16).
     * CSS is registered early here; enqueued lazily inside render().
     */
    public static function register_hooks(): void {
        add_shortcode( 'tp_reviews', [ 'TP_Shortcode', 'render' ] );

        // Register (NOT enqueue) the frontend stylesheet early so the handle
        // is known to WordPress before wp_head fires. Enqueueing happens inside
        // render() so CSS loads only on pages that use [tp_reviews]. WordPress
        // flushes enqueued-after-wp_head styles via wp_print_styles() in
        // wp_footer — this is an accepted WP pattern. (D-11, RESEARCH.md Pattern 1)
        wp_register_style(
            'tp-reviews',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/tp-reviews.css',
            [],
            TP_PLUGIN_VERSION
        );
    }

    /**
     * Render the [tp_reviews id="preset-slug"] shortcode.
     * Returns HTML string. Never echoes.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public static function render( $atts ): string {
        $atts = shortcode_atts( [ 'id' => '' ], $atts, 'tp_reviews' );
        $slug = sanitize_title( (string) $atts['id'] );

        $preset  = TP_Preset_Manager::get_by_slug( $slug );
        $reviews = ( null !== $preset )
            ? self::query_reviews( $preset )
            : self::fallback_query();

        // Enqueue the pre-registered stylesheet. WordPress flushes it via
        // wp_footer when called after wp_head has already fired. (D-11)
        wp_enqueue_style( 'tp-reviews' );

        ob_start();
        ?>
        <div class="tp-reviews-wrapper">
            <div class="tp-reviews-grid">
                <?php if ( empty( $reviews ) ) : ?>
                    <p class="tp-empty-state"><?php echo esc_html( __( 'No reviews found.', 'trustpilot-reviews' ) ); ?></p>
                <?php else : ?>
                    <?php foreach ( $reviews as $review ) : ?>
                        <?php echo self::render_card( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render_card() returns fully-escaped HTML ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php echo self::render_compliance_block( $preset ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render_compliance_block() returns fully-escaped HTML ?>
            <?php echo self::render_json_ld(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render_json_ld() returns script tag with wp_json_encode ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Query the local DB using preset filters. No live API calls. (SC-02)
     *
     * Uses $wpdb->esc_like() before building LIKE wildcards (RESEARCH.md Pitfall 3).
     * Keywords are OR-joined across body AND title (D-08).
     * Empty keywords list means no keyword clause (D-09).
     *
     * @param array $preset Preset array with keys: min_stars, keywords, limit.
     * @return array Array of stdClass row objects.
     */
    private static function query_reviews( array $preset ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'tp_reviews';
        $where  = [];
        $params = [];

        // min_stars filter (D-10): WHERE stars >= N only when N > 1
        $min_stars = isset( $preset['min_stars'] ) ? (int) $preset['min_stars'] : 1;
        if ( $min_stars > 1 ) {
            $where[]  = 'stars >= %d';
            $params[] = $min_stars;
        }

        // Keyword filter: OR-join across body + title (D-08, D-09)
        $kw_raw  = isset( $preset['keywords'] ) ? trim( (string) $preset['keywords'] ) : '';
        $kw_list = array_filter( array_map( 'trim', explode( ',', $kw_raw ) ) );

        if ( ! empty( $kw_list ) ) {
            $kw_clauses = [];
            foreach ( $kw_list as $kw ) {
                // esc_like() escapes literal % and _ in the keyword; wildcards
                // are added manually as part of the $like argument string —
                // they must NOT appear as %% in the query template. (RESEARCH.md Pitfall 3)
                $like         = '%' . $wpdb->esc_like( $kw ) . '%';
                $kw_clauses[] = '(body LIKE %s OR title LIKE %s)';
                $params[]     = $like;
                $params[]     = $like;
            }
            $where[] = '(' . implode( ' OR ', $kw_clauses ) . ')';
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $limit     = isset( $preset['limit'] ) ? max( 1, (int) $preset['limit'] ) : 10;
        $params[]  = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT review_id, stars, title, body, author, published_at, is_verified
                   FROM `{$table}` {$where_sql} ORDER BY published_at DESC LIMIT %d",
                $params
            )
        );
    }

    /**
     * Return the 10 most recent reviews regardless of filters. (SC-03, D-13)
     * Called when [tp_reviews id="slug"] resolves to no matching preset.
     * No error output — silent fallback per D-13.
     *
     * @return array Array of stdClass row objects.
     */
    private static function fallback_query(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tp_reviews';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT review_id, stars, title, body, author, published_at, is_verified
                   FROM `{$table}` ORDER BY published_at DESC LIMIT %d",
                10
            )
        );
    }

    /**
     * Render a single review card. (D-01, D-02, SC-06, SC-07)
     *
     * Card layout top-to-bottom (D-01):
     *   1. .tp-review-stars    — star glyph row
     *   2. .tp-review-header   — author (left) + date (right)
     *   3. .tp-review-title    — title, semibold, max 80 chars (mb_substr)
     *   4. .tp-review-body     — body, max 150 chars (mb_substr) + ellipsis when truncated (D-02)
     *   5. .tp-review-verified — conditional Verified badge (SC-07)
     *
     * @param object $review stdClass row from wpdb::get_results().
     * @return string Fully-escaped HTML for one card.
     */
    private static function render_card( object $review ): string {
        $stars_html = self::render_stars( (int) $review->stars );

        $raw_title = (string) $review->title;
        $title     = mb_strlen( $raw_title ) > 80
            ? mb_substr( $raw_title, 0, 80 ) . "\u{2026}"
            : $raw_title;

        $raw_body = (string) $review->body;
        $body     = mb_strlen( $raw_body ) > 150
            ? mb_substr( $raw_body, 0, 150 ) . "\u{2026}"
            : $raw_body;

        $date_formatted = self::format_date( (string) $review->published_at );
        // Machine-readable ISO date for <time datetime=""> attribute
        $date_iso = gmdate( 'Y-m-d', (int) strtotime( (string) $review->published_at ) );

        $verified_html = '';
        if ( (int) $review->is_verified === 1 ) {
            $verified_html = '<span class="tp-review-verified">'
                . esc_html( __( 'Verified', 'trustpilot-reviews' ) )
                . '</span>';
        }

        return sprintf(
            '<article class="tp-review-card">'
            . '%s'
            . '<div class="tp-review-header">'
            .   '<span class="tp-review-author">%s</span>'
            .   '<time class="tp-review-date" datetime="%s">%s</time>'
            . '</div>'
            . '<h3 class="tp-review-title">%s</h3>'
            . '<p class="tp-review-body">%s</p>'
            . '%s'
            . '</article>',
            $stars_html,                          // already escaped by render_stars()
            esc_html( (string) $review->author ),
            esc_attr( $date_iso ),
            esc_html( $date_formatted ),
            esc_html( $title ),
            esc_html( $body ),
            $verified_html                        // already escaped above
        );
    }

    /**
     * Render a Unicode star row for a review card.
     * Filled: ★ (U+2605) #00b67a, Empty: ☆ (U+2606) #c8c8c8.
     *
     * @param int $stars Integer rating 1–5 from DB column.
     * @return string Fully-escaped HTML.
     */
    private static function render_stars( int $stars ): string {
        $stars  = min( 5, max( 0, $stars ) );
        $filled = str_repeat( '★', $stars );
        $empty  = str_repeat( '☆', 5 - $stars );
        return sprintf(
            '<div class="tp-review-stars">'
            . '<span aria-label="%s">'
            .   '<span aria-hidden="true" style="color:#00b67a">%s</span>'
            .   '<span aria-hidden="true" style="color:#c8c8c8">%s</span>'
            . '</span>'
            . '</div>',
            esc_attr( sprintf(
                /* translators: %d: star count (1-5) */
                __( '%d out of 5 stars', 'trustpilot-reviews' ),
                $stars
            ) ),
            esc_html( $filled ),
            esc_html( $empty )
        );
    }

    /**
     * Format a published_at DATETIME string per the tp_date_format admin option. (D-03)
     *
     * month_year (default): "March 2024"  via date_i18n('F Y')
     * full_date:            "15 March 2024" via date_i18n('j F Y')
     * relative:             "3 months ago"  via human_time_diff() + translatable "ago" suffix
     *
     * @param string $published_at DATETIME string from DB (e.g. "2024-03-15 10:30:00").
     * @return string Formatted date string.
     */
    private static function format_date( string $published_at ): string {
        $format = (string) get_option( 'tp_date_format', 'month_year' );
        $ts     = (int) strtotime( $published_at );

        switch ( $format ) {
            case 'full_date':
                return date_i18n( 'j F Y', $ts );

            case 'relative':
                return sprintf(
                    /* translators: %s: human-readable time difference e.g. "3 months" */
                    __( '%s ago', 'trustpilot-reviews' ),
                    human_time_diff( $ts, current_time( 'timestamp' ) )
                );

            case 'month_year':
            default:
                return date_i18n( 'F Y', $ts );
        }
    }

    /**
     * Render the Trustpilot compliance block below the card grid. (D-04, D-05, D-06, D-07, SC-04)
     *
     * Layout: <hr> separator → logo (linked) → TrustScore stars + "4.7/5 • 1,243 reviews" → filter disclosure (conditional).
     * Data sourced from wp_options — no DB query. (SC-02)
     *
     * @param array|null $preset Preset array or null (fallback). Used to read min_stars for disclosure.
     * @return string Fully-escaped HTML.
     */
    private static function render_compliance_block( ?array $preset ): string {
        $score       = (float)  get_option( 'tp_trust_score',  0 );
        $count       = (int)    get_option( 'tp_review_count', 0 );
        $profile_url = (string) get_option( 'tp_profile_url',  'https://www.trustpilot.com' );
        $logo_url    = plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/trustpilot-logo.svg';
        $min_stars   = isset( $preset['min_stars'] ) ? (int) $preset['min_stars'] : 1;

        // TrustScore star row: round to nearest whole star (D-05 "simpler" note)
        $filled_count = min( 5, max( 0, (int) round( $score ) ) );
        $stars        = str_repeat( '★', $filled_count ) . str_repeat( '☆', 5 - $filled_count );

        // Filter disclosure (D-07): only when min_stars >= 2
        $disclosure = '';
        if ( $min_stars >= 2 && $min_stars <= 5 ) {
            $disclosure_map = [
                2 => __( 'Showing 2, 3, 4 & 5 star reviews', 'trustpilot-reviews' ),
                3 => __( 'Showing 3, 4 & 5 star reviews',    'trustpilot-reviews' ),
                4 => __( 'Showing 4 & 5 star reviews',        'trustpilot-reviews' ),
                5 => __( 'Showing 5 star reviews only',       'trustpilot-reviews' ),
            ];
            $disclosure = '<p class="tp-compliance-disclosure">'
                . esc_html( $disclosure_map[ $min_stars ] )
                . '</p>';
        }

        return sprintf(
            '<hr class="tp-compliance-separator">'
            . '<div class="tp-compliance-block">'
            .   '<a class="tp-compliance-logo" href="%1$s" target="_blank" rel="noopener noreferrer">'
            .     '<img src="%2$s" alt="Trustpilot" width="120">'
            .   '</a>'
            .   '<span class="tp-compliance-score">'
            .     '<span aria-label="%3$s">'
            .       '<span aria-hidden="true" style="color:#00b67a">%4$s</span>'
            .     '</span>'
            .     ' %5$s'
            .   '</span>'
            .   '%6$s'
            . '</div>',
            esc_url( $profile_url ),
            esc_url( $logo_url ),
            esc_attr( sprintf(
                /* translators: %s: numeric trust score e.g. "4.7" */
                __( '%s out of 5 stars', 'trustpilot-reviews' ),
                $score
            ) ),
            esc_html( $stars ),
            esc_html( sprintf(
                /* translators: 1: numeric score e.g. "4.7", 2: formatted count e.g. "1,243" */
                __( '%1$s/5 \u{2022} %2$s reviews', 'trustpilot-reviews' ),
                $score,
                number_format( $count )
            ) ),
            $disclosure   // already escaped above
        );
    }

    /**
     * Render inline JSON-LD AggregateRating schema. (SC-05, D-12)
     *
     * Output is placed inline in the shortcode return string (not via wp_head hook).
     * wp_head fires BEFORE shortcode callbacks — any add_action('wp_head',...) call
     * inside render() would never execute for the current page load. Inline placement
     * is supported by Google Search for rich snippet eligibility.
     *
     * Static flag self::$schema_rendered prevents a second block when multiple
     * [tp_reviews] shortcodes appear on the same page. (CLAUDE.md Pitfall P4)
     *
     * @return string <script type="application/ld+json">...</script> or empty string.
     */
    private static function render_json_ld(): string {
        if ( self::$schema_rendered ) {
            return '';
        }
        self::$schema_rendered = true;

        $score = get_option( 'tp_trust_score',  '' );
        $count = get_option( 'tp_review_count', '' );

        // No schema output until sync has run and populated compliance options.
        if ( '' === (string) $score || '' === (string) $count ) {
            return '';
        }

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) $score,
            'reviewCount' => (string) (int) $count,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];

        return '<script type="application/ld+json">'
             . wp_json_encode( $schema )
             . '</script>';
    }

}   // end class TP_Shortcode
