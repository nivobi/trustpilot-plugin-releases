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
     * Increments per render() call to generate unique carousel IDs.
     *
     * @var int
     */
    private static int $instance_count = 0;

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

        wp_enqueue_style( 'tp-reviews' );

        $carousel_id = 'tp-carousel-' . ( ++self::$instance_count );

        ob_start();
        ?>
        <div class="tp-reviews-wrapper">
            <div class="tp-carousel">
                <button class="tp-carousel-btn tp-carousel-prev"
                        aria-label="<?php esc_attr_e( 'Previous', 'trustpilot-reviews' ); ?>"
                        disabled>
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="12" cy="12" r="11.5" fill="none" stroke="#8C8C8C"/>
                        <path fill="#8C8C8C" d="M10.5088835 12l3.3080582-3.02451041c.2440777-.22315674.2440777-.5849653 0-.80812204-.2440776-.22315673-.6398058-.22315673-.8838834 0L9.18305826 11.595939c-.24407768.2231567-.24407768.5849653 0 .808122l3.75000004 3.4285714c.2440776.2231568.6398058.2231568.8838834 0 .2440777-.2231567.2440777-.5849653 0-.808122L10.5088835 12z"/>
                    </svg>
                </button>

                <div class="tp-carousel-track" id="<?php echo esc_attr( $carousel_id ); ?>">
                    <?php if ( empty( $reviews ) ) : ?>
                        <p class="tp-no-reviews"><?php esc_html_e( 'No reviews found.', 'trustpilot-reviews' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $reviews as $review ) : ?>
                            <?php echo self::render_card( $review ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button class="tp-carousel-btn tp-carousel-next"
                        aria-label="<?php esc_attr_e( 'Next', 'trustpilot-reviews' ); ?>">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="transform: rotate(180deg)">
                        <circle cx="12" cy="12" r="11.5" fill="none" stroke="#8C8C8C"/>
                        <path fill="#8C8C8C" d="M10.5088835 12l3.3080582-3.02451041c.2440777-.22315674.2440777-.5849653 0-.80812204-.2440776-.22315673-.6398058-.22315673-.8838834 0L9.18305826 11.595939c-.24407768.2231567-.24407768.5849653 0 .808122l3.75000004 3.4285714c.2440776.2231568.6398058.2231568.8838834 0 .2440777-.2231567.2440777-.5849653 0-.808122L10.5088835 12z"/>
                    </svg>
                </button>
            </div>

            <?php echo self::render_compliance_block( $preset ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::render_json_ld(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <script>
        (function(){
            var track = document.getElementById('<?php echo esc_js( $carousel_id ); ?>');
            if ( ! track ) return;
            var wrap  = track.closest('.tp-carousel');
            var prev  = wrap.querySelector('.tp-carousel-prev');
            var next  = wrap.querySelector('.tp-carousel-next');

            function update() {
                // First card's offsetLeft accounts for track padding so snap
                // points (which can leave scrollLeft > 0 at start) still register.
                var startOffset = track.firstElementChild ? track.firstElementChild.offsetLeft : 0;
                prev.disabled = track.scrollLeft <= startOffset + 1;
                next.disabled = track.scrollLeft + track.offsetWidth >= track.scrollWidth - 1;
            }

            // Custom smooth scroll — tunable duration + ease-in-out curve.
            var DURATION = 700; // ms — raise for slower, lower for snappier
            var animFrame = null;
            function easeInOutCubic(t) {
                return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            }
            function animateTo(target) {
                if ( animFrame ) cancelAnimationFrame( animFrame );
                var max   = track.scrollWidth - track.offsetWidth;
                target    = Math.max( 0, Math.min( target, max ) );
                var start = track.scrollLeft;
                var diff  = target - start;
                if ( Math.abs( diff ) < 1 ) return;
                // Suspend scroll-snap so each rAF write isn't re-snapped to the
                // nearest card (which collapses the animation into a card-swap).
                track.style.scrollSnapType = 'none';
                var t0 = performance.now();
                function step(now) {
                    var p = Math.min( 1, ( now - t0 ) / DURATION );
                    track.scrollLeft = start + diff * easeInOutCubic( p );
                    if ( p < 1 ) {
                        animFrame = requestAnimationFrame( step );
                    } else {
                        animFrame = null;
                        track.style.scrollSnapType = '';
                    }
                }
                animFrame = requestAnimationFrame( step );
            }

            // Step exactly N visible cards per click — never leaves a half card.
            function getCardStep() {
                var card = track.querySelector('.tp-review-card');
                if ( ! card ) return track.offsetWidth * 0.75;
                var trackStyle = getComputedStyle( track );
                var gap        = parseFloat( trackStyle.columnGap || trackStyle.gap ) || 0;
                var carouselCS = getComputedStyle( wrap );
                var visible    = parseFloat( carouselCS.getPropertyValue( '--tp-cards-visible' ) ) || 1;
                visible        = Math.max( 1, Math.floor( visible ) );
                return ( card.getBoundingClientRect().width + gap ) * visible;
            }

            prev.addEventListener('click', function(){
                animateTo( track.scrollLeft - getCardStep() );
            });
            next.addEventListener('click', function(){
                animateTo( track.scrollLeft + getCardStep() );
            });
            track.addEventListener('scroll', update, { passive: true });
            update();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Transient key prefix for preset result caches.
     */
    private const CACHE_PREFIX = 'tp_preset_cache_';

    /**
     * Delete the cached results for a single preset slug.
     *
     * Call after updating or deleting a preset so the next shortcode render
     * rebuilds the result from the DB with the new filters.
     *
     * @param string $slug Preset slug.
     */
    public static function bust_cache( string $slug ): void {
        delete_transient( self::CACHE_PREFIX . $slug );
    }

    /**
     * Delete cached results for every known preset plus the fallback cache.
     *
     * Call after a sync run so all shortcodes reflect newly upserted reviews.
     */
    public static function bust_all_caches(): void {
        delete_transient( self::CACHE_PREFIX . '_fallback' );
        foreach ( TP_Preset_Manager::get_all() as $preset ) {
            if ( ! empty( $preset['slug'] ) ) {
                self::bust_cache( $preset['slug'] );
            }
        }
    }

    /**
     * Query the local DB using preset filters. No live API calls. (SC-02)
     *
     * Results are cached in a WP transient with no expiry — cache is busted
     * explicitly by bust_cache() / bust_all_caches() on sync and preset CRUD.
     *
     * Uses $wpdb->esc_like() before building LIKE wildcards (RESEARCH.md Pitfall 3).
     * Keywords are OR-joined across body AND title (D-08).
     * Empty keywords list means no keyword clause (D-09).
     *
     * @param array $preset Preset array with keys: slug, min_stars, keywords, limit.
     * @return array Array of stdClass row objects.
     */
    private static function query_reviews( array $preset ): array {
        $slug      = (string) ( $preset['slug'] ?? '' );
        $cache_key = self::CACHE_PREFIX . $slug;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (array) $cached;
        }

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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT review_id, stars, title, body, author, published_at, is_verified
                   FROM `{$table}` {$where_sql} ORDER BY published_at DESC LIMIT %d",
                $params
            )
        );

        set_transient( $cache_key, $results, 0 ); // 0 = no expiry; busted explicitly on sync/CRUD.
        return $results;
    }

    /**
     * Return the 10 most recent reviews regardless of filters. (SC-03, D-13)
     * Called when [tp_reviews id="slug"] resolves to no matching preset.
     * No error output — silent fallback per D-13.
     *
     * @return array Array of stdClass row objects.
     */
    private static function fallback_query(): array {
        $cache_key = self::CACHE_PREFIX . '_fallback';
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (array) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tp_reviews';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT review_id, stars, title, body, author, published_at, is_verified
                   FROM `{$table}` ORDER BY published_at DESC LIMIT %d",
                10
            )
        );

        set_transient( $cache_key, $results, 0 );
        return $results;
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
        $stars = min( 5, max( 1, (int) $review->stars ) );
        $stars_html = self::render_stars( $stars );

        $verified_html = '';
        if ( (int) $review->is_verified === 1 ) {
            $verified_html = '<a class="tp-review-verified" href="https://help.trustpilot.com/s/article/How-do-reviews-get-on-Trustpilot?language=da" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Inviteret anmeldelse. Klik for at få mere at vide om anmeldelsestyper', 'trustpilot-reviews' ) . '">'
                . '<span class="tp-verified-icon" aria-hidden="true">'
                . '<svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" width="14" height="14">'
                . '<path fill-rule="evenodd" clip-rule="evenodd" d="M7 14C10.866 14 14 10.866 14 7C14 3.13401 10.866 0 7 0C3.13401 0 0 3.13401 0 7C0 10.866 3.13401 14 7 14ZM6.09217 7.81401L9.20311 4.7031C9.44874 4.45757 9.84688 4.45757 10.0923 4.7031C10.338 4.94864 10.338 5.34673 10.0923 5.59226L6.62009 9.06448C6.59573 9.10283 6.56682 9.13912 6.53333 9.17256C6.28787 9.41821 5.88965 9.41821 5.64402 9.17256L3.7059 7.11031C3.46046 6.86464 3.46046 6.46669 3.7059 6.22102C3.95154 5.97548 4.34968 5.97548 4.59512 6.22102L6.09217 7.81401Z" fill="currentColor"/>'
                . '</svg>'
                . '</span>'
                . esc_html( __( 'Inviteret', 'trustpilot-reviews' ) )
                . '</a>';
        }

        $date_str = self::format_date( (string) $review->published_at );

        $review_url = 'https://dk.trustpilot.com/reviews/' . rawurlencode( (string) $review->review_id );

        return sprintf(
            '<article class="tp-review-card">'
            . '<div class="tp-review-top">%s%s</div>'
            . '<a class="tp-review-link" href="%s" target="_blank" rel="nofollow noopener">'
            .   '<h3 class="tp-review-title">%s</h3>'
            .   '<p class="tp-review-body">%s</p>'
            .   '<div class="tp-review-footer">'
            .     '<span class="tp-review-author">%s</span>'
            .     '<span class="tp-review-date">. %s</span>'
            .   '</div>'
            . '</a>'
            . '</article>',
            $stars_html,
            $verified_html,
            esc_url( $review_url ),
            esc_html( (string) $review->title ),
            esc_html( (string) $review->body ),
            esc_html( (string) $review->author ),
            esc_html( $date_str )
        );
    }

    /**
     * Render an inline SVG star row matching the official Trustpilot widget structure.
     * Uses tp-star--filled class on filled stars so CSS hover can retarget fill color.
     *
     * @param int $stars Integer rating 1–5 from DB column.
     * @return string Inline SVG HTML.
     */
    private static function render_stars( int $stars ): string {
        $stars = min( 5, max( 1, $stars ) );

        $filled_colors = [
            1 => '#ff3722',
            2 => '#ff8622',
            3 => '#ffce00',
            4 => '#73cf11',
            5 => '#00b67a',
        ];
        $filled_color = $filled_colors[ $stars ];
        $empty_color  = '#dcdce6';

        $canvases = [
            1 => [ 'M0 46.330002h46.375586V0H0z',                    null ],
            2 => [ 'M51.24816 46.330002h46.375587V0H51.248161z',      'M51.24816 46.330002h23.187793V0H51.248161z' ],
            3 => [ 'M102.532209 46.330002h46.375586V0h-46.375586z',   'M102.532209 46.330002h23.187793V0h-23.187793z' ],
            4 => [ 'M153.815458 46.330002h46.375586V0h-46.375586z',   'M153.815458 46.330002h23.187793V0h-23.187793z' ],
            5 => [ 'M205.064416 46.330002h46.375587V0h-46.375587z',   'M205.064416 46.330002h23.187793V0h-23.187793z' ],
        ];

        $shapes = [
            1 => 'M39.533936 19.711433L13.230239 38.80065l3.838216-11.797827L7.02115 19.711433h12.418975l3.837417-11.798624 3.837418 11.798624h12.418975zM23.2785 31.510075l7.183595-1.509576 2.862114 8.800152L23.2785 31.510075z',
            2 => 'M74.990978 31.32991L81.150908 30 84 39l-9.660206-7.202786L64.30279 39l3.895636-11.840666L58 19.841466h12.605577L74.499595 8l3.895637 11.841466H91L74.990978 31.329909z',
            3 => 'M142.066994 19.711433L115.763298 38.80065l3.838215-11.797827-10.047304-7.291391h12.418975l3.837418-11.798624 3.837417 11.798624h12.418975zM125.81156 31.510075l7.183595-1.509576 2.862113 8.800152-10.045708-7.290576z',
            4 => 'M193.348355 19.711433L167.045457 38.80065l3.837417-11.797827-10.047303-7.291391h12.418974l3.837418-11.798624 3.837418 11.798624h12.418974zM177.09292 31.510075l7.183595-1.509576 2.862114 8.800152-10.045709-7.290576z',
            5 => 'M244.597022 19.711433l-26.3029 19.089218 3.837419-11.797827-10.047304-7.291391h12.418974l3.837418-11.798624 3.837418 11.798624h12.418975zm-16.255436 11.798642l7.183595-1.509576 2.862114 8.800152-10.045709-7.290576z',
        ];

        $label = esc_html( sprintf(
            /* translators: %d: star count 1-5 */
            __( '%d ud af 5 stjerner', 'trustpilot-reviews' ),
            $stars
        ) );

        $svg = '<div class="tp-review-stars">'
            . '<svg class="tp-stars tp-stars--' . $stars . '" role="img" viewBox="0 0 251 46" xmlns="http://www.w3.org/2000/svg">'
            . '<title>' . $label . '</title>';

        for ( $i = 1; $i <= 5; $i++ ) {
            $filled      = $i <= $stars;
            $color       = $filled ? $filled_color : $empty_color;
            $extra_class = $filled ? ' tp-star--filled' : '';
            [ $full_d, $half_d ] = $canvases[ $i ];

            $svg .= '<g class="tp-star' . $extra_class . '">';
            $svg .= '<path class="tp-star__canvas" fill="' . $color . '" d="' . $full_d . '"/>';
            if ( $half_d ) {
                $svg .= '<path class="tp-star__canvas--half" fill="' . $color . '" d="' . $half_d . '"/>';
            }
            $svg .= '<path class="tp-star__shape" fill="#FFF" d="' . $shapes[ $i ] . '"/>';
            $svg .= '</g>';
        }

        $svg .= '</svg></div>';
        return $svg;
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
                    __( 'For %s siden', 'trustpilot-reviews' ),
                    human_time_diff( $ts, time() )
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
        $min_stars = isset( $preset['min_stars'] ) ? (int) $preset['min_stars'] : 1;

        // Filter disclosure (D-07) — required by Trustpilot TOS when showing a star-filtered subset.
        $disclosure = '';
        if ( $min_stars >= 2 && $min_stars <= 5 ) {
            $star_map = [
                2 => __( 'Viser vores 2-, 3-, 4- og 5-stjernede anmeldelser.', 'trustpilot-reviews' ),
                3 => __( 'Viser vores 3-, 4- og 5-stjernede anmeldelser.', 'trustpilot-reviews' ),
                4 => __( 'Viser vores 4- og 5-stjernede anmeldelser.', 'trustpilot-reviews' ),
                5 => __( 'Viser kun vores 5-stjernede anmeldelser.', 'trustpilot-reviews' ),
            ];
            $disclosure = ' ' . esc_html( $star_map[ $min_stars ] );
        }

        // "Bedømt til 4.6 / 5 baseret på 7.775 anmeldelser."
        $text = sprintf(
            /* translators: 1: score e.g. "4.6", 2: formatted review count e.g. "7.775" */
            __( 'Bed&oslash;mt til <strong>%1$s / 5</strong> baseret p&aring; <a href="%3$s" target="_blank" rel="noopener noreferrer">%2$s anmeldelser</a>.', 'trustpilot-reviews' ),
            esc_html( (string) $score ),
            esc_html( number_format( $count, 0, ',', '.' ) ),
            esc_url( $profile_url )
        );

        $logo_svg = '<svg role="img" viewBox="0 0 126 31" width="126" height="31" xmlns="http://www.w3.org/2000/svg" aria-label="Trustpilot">'
            . '<path class="tp-logo__text" d="M33.074774 11.07005H45.81806v2.364196h-5.010656v13.290316h-2.755306V13.434246h-4.988435V11.07005h.01111zm12.198892 4.319629h2.355341v2.187433h.04444c.077771-.309334.222203-.60762.433295-.894859.211092-.287239.466624-.56343.766597-.79543.299972-.243048.633276-.430858.999909-.585525.366633-.14362.744377-.220953 1.12212-.220953.288863 0 .499955.011047.611056.022095.1111.011048.222202.033143.344413.04419v2.408387c-.177762-.033143-.355523-.055238-.544395-.077333-.188872-.022096-.366633-.033143-.544395-.033143-.422184 0-.822148.08838-1.199891.254096-.377744.165714-.699936.41981-.977689.740192-.277753.331429-.499955.729144-.666606 1.21524-.166652.486097-.244422 1.03848-.244422 1.668195v5.39125h-2.510883V15.38968h.01111zm18.220567 11.334883H61.02779v-1.579813h-.04444c-.311083.574477-.766597 1.02743-1.377653 1.369908-.611055.342477-1.233221.51924-1.866497.51924-1.499864 0-2.588654-.364573-3.25526-1.104765-.666606-.740193-.999909-1.856005-.999909-3.347437V15.38968h2.510883v6.948968c0 .994288.188872 1.701337.577725 2.1101.377744.408763.922139.618668 1.610965.618668.533285 0 .96658-.077333 1.322102-.243048.355524-.165714.644386-.37562.855478-.65181.222202-.265144.377744-.596574.477735-.972194.09999-.37562.144431-.784382.144431-1.226288v-6.573349h2.510883v11.323836zm4.27739-3.634675c.07777.729144.355522 1.237336.833257 1.535623.488844.287238 1.06657.441905 1.744286.441905.233312 0 .499954-.022095.799927-.055238.299973-.033143.588836-.110476.844368-.209905.266642-.099429.477734-.254096.655496-.452954.166652-.198857.244422-.452953.233312-.773335-.01111-.320381-.133321-.585525-.355523-.784382-.222202-.209906-.499955-.364573-.844368-.497144-.344413-.121525-.733267-.232-1.17767-.320382-.444405-.088381-.888809-.18781-1.344323-.287239-.466624-.099429-.922138-.232-1.355432-.37562-.433294-.14362-.822148-.342477-1.166561-.596573-.344413-.243048-.622166-.56343-.822148-.950097-.211092-.386668-.311083-.861716-.311083-1.436194 0-.618668.155542-1.12686.455515-1.54667.299972-.41981.688826-.75124 1.14434-1.005336.466624-.254095.97769-.430858 1.544304-.541334.566615-.099429 1.11101-.154667 1.622075-.154667.588836 0 1.15545.066286 1.688736.18781.533285.121524 1.02213.320381 1.455423.60762.433294.276191.788817.640764 1.07768 1.08267.288863.441905.466624.98324.544395 1.612955h-2.621984c-.122211-.596572-.388854-1.005335-.822148-1.204193-.433294-.209905-.933248-.309334-1.488753-.309334-.177762 0-.388854.011048-.633276.04419-.244422.033144-.466624.088382-.688826.165715-.211092.077334-.388854.198858-.544395.353525-.144432.154667-.222203.353525-.222203.60762 0 .309335.111101.552383.322193.740193.211092.18781.488845.342477.833258.475048.344413.121524.733267.232 1.177671.320382.444404.088381.899918.18781 1.366542.287239.455515.099429.899919.232 1.344323.37562.444404.14362.833257.342477 1.17767.596573.344414.254095.622166.56343.833258.93905.211092.37562.322193.850668.322193 1.40305 0 .673906-.155541 1.237336-.466624 1.712385-.311083.464001-.711047.850669-1.199891 1.137907-.488845.28724-1.04435.508192-1.644295.640764-.599946.132572-1.199891.198857-1.788727.198857-.722156 0-1.388762-.077333-1.999818-.243048-.611056-.165714-1.14434-.408763-1.588745-.729144-.444404-.33143-.799927-.740192-1.05546-1.226289-.255532-.486096-.388853-1.071621-.411073-1.745528h2.533103v-.022095zm8.288135-7.700208h1.899828v-3.402675h2.510883v3.402675h2.26646v1.867052h-2.26646v6.054109c0 .265143.01111.486096.03333.684954.02222.18781.07777.353524.155542.486096.07777.132572.199981.232.366633.298287.166651.066285.377743.099428.666606.099428.177762 0 .355523 0 .533285-.011047.177762-.011048.355523-.033143.533285-.077334v1.933338c-.277753.033143-.555505.055238-.811038.088381-.266642.033143-.533285.04419-.811037.04419-.666606 0-1.199891-.066285-1.599855-.18781-.399963-.121523-.722156-.309333-.944358-.552381-.233313-.243049-.377744-.541335-.466625-.905907-.07777-.364573-.13332-.784383-.144431-1.248384v-6.683825h-1.899827v-1.889147h-.02222zm8.454788 0h2.377562V16.9253h.04444c.355523-.662858.844368-1.12686 1.477644-1.414098.633276-.287239 1.310992-.430858 2.055369-.430858.899918 0 1.677625.154667 2.344231.475048.666606.309335 1.222111.740193 1.666515 1.292575.444405.552382.766597 1.193145.9888 1.92229.222202.729145.333303 1.513527.333303 2.3421 0 .762288-.099991 1.50248-.299973 2.20953-.199982.718096-.499955 1.347812-.899918 1.900194-.399964.552383-.911029.98324-1.533194 1.31467-.622166.33143-1.344323.497144-2.18869.497144-.366634 0-.733267-.033143-1.0999-.099429-.366634-.066286-.722157-.176762-1.05546-.320381-.333303-.14362-.655496-.33143-.933249-.56343-.288863-.232-.522175-.497144-.722157-.79543h-.04444v5.656393h-2.510883V15.38968zm8.77698 5.67849c0-.508193-.06666-1.005337-.199981-1.491433-.133321-.486096-.333303-.905907-.599946-1.281527-.266642-.37562-.599945-.673906-.988799-.894859-.399963-.220953-.855478-.342477-1.366542-.342477-1.05546 0-1.855387.364572-2.388672 1.093717-.533285.729144-.799928 1.701337-.799928 2.916578 0 .574478.066661 1.104764.211092 1.59086.144432.486097.344414.905908.633276 1.259432.277753.353525.611056.629716.99991.828574.388853.209905.844367.309334 1.355432.309334.577725 0 1.05546-.121524 1.455423-.353525.399964-.232.722157-.541335.97769-.905907.255531-.37562.444403-.79543.555504-1.270479.099991-.475049.155542-.961145.155542-1.458289zm4.432931-9.99812h2.510883v2.364197h-2.510883V11.07005zm0 4.31963h2.510883v11.334883h-2.510883V15.389679zm4.755124-4.31963h2.510883v15.654513h-2.510883V11.07005zm10.210184 15.963847c-.911029 0-1.722066-.154667-2.433113-.452953-.711046-.298287-1.310992-.718097-1.810946-1.237337-.488845-.530287-.866588-1.160002-1.12212-1.889147-.255533-.729144-.388854-1.535622-.388854-2.408386 0-.861716.133321-1.657147.388853-2.386291.255533-.729145.633276-1.35886 1.12212-1.889148.488845-.530287 1.0999-.93905 1.810947-1.237336.711047-.298286 1.522084-.452953 2.433113-.452953.911028 0 1.722066.154667 2.433112.452953.711047.298287 1.310992.718097 1.810947 1.237336.488844.530287.866588 1.160003 1.12212 1.889148.255532.729144.388854 1.524575.388854 2.38629 0 .872765-.133322 1.679243-.388854 2.408387-.255532.729145-.633276 1.35886-1.12212 1.889147-.488845.530287-1.0999.93905-1.810947 1.237337-.711046.298286-1.522084.452953-2.433112.452953zm0-1.977528c.555505 0 1.04435-.121524 1.455423-.353525.411074-.232.744377-.541335 1.01102-.916954.266642-.37562.455513-.806478.588835-1.281527.12221-.475049.188872-.961145.188872-1.45829 0-.486096-.066661-.961144-.188872-1.44724-.122211-.486097-.322193-.905907-.588836-1.281527-.266642-.37562-.599945-.673907-1.011019-.905907-.411074-.232-.899918-.353525-1.455423-.353525-.555505 0-1.04435.121524-1.455424.353525-.411073.232-.744376.541334-1.011019.905907-.266642.37562-.455514.79543-.588835 1.281526-.122211.486097-.188872.961145-.188872 1.447242 0 .497144.06666.98324.188872 1.458289.12221.475049.322193.905907.588835 1.281527.266643.37562.599946.684954 1.01102.916954.411073.243048.899918.353525 1.455423.353525zm6.4883-9.66669h1.899827v-3.402674h2.510883v3.402675h2.26646v1.867052h-2.26646v6.054109c0 .265143.01111.486096.03333.684954.02222.18781.07777.353524.155541.486096.077771.132572.199982.232.366634.298287.166651.066285.377743.099428.666606.099428.177762 0 .355523 0 .533285-.011047.177762-.011048.355523-.033143.533285-.077334v1.933338c-.277753.033143-.555505.055238-.811038.088381-.266642.033143-.533285.04419-.811037.04419-.666606 0-1.199891-.066285-1.599855-.18781-.399963-.121523-.722156-.309333-.944358-.552381-.233313-.243049-.377744-.541335-.466625-.905907-.07777-.364573-.133321-.784383-.144431-1.248384v-6.683825h-1.899827v-1.889147h-.02222z" fill="#191919"/>'
            . '<path class="tp-logo__star" fill="#00B67A" d="M30.141707 11.07005H18.63164L15.076408.177071l-3.566342 10.892977L0 11.059002l9.321376 6.739063-3.566343 10.88193 9.321375-6.728016 9.310266 6.728016-3.555233-10.88193 9.310266-6.728016z"/>'
            . '<path class="tp-logo__star-notch" fill="#005128" d="M21.631369 20.26169l-.799928-2.463625-5.755033 4.153914z"/>'
            . '</svg>';

        return '<div class="tp-compliance-block">'
            . '<p class="tp-compliance-text">' . $text . $disclosure . '</p>'
            . '<a class="tp-compliance-logo-link" href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener noreferrer">'
            .   $logo_svg
            . '</a>'
            . '</div>';
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

        $score = get_option( 'tp_trust_score',  '' );
        $count = get_option( 'tp_review_count', '' );

        // No schema output until sync has run and populated compliance options.
        // Flag is NOT set here — options may be populated on a subsequent render.
        if ( '' === (string) $score || '' === (string) $count ) {
            return '';
        }

        self::$schema_rendered = true;  // only latch after all early returns

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) $score,
            'reviewCount' => (string) (int) $count,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];

        $json = wp_json_encode( $schema );
        if ( false === $json ) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }

}   // end class TP_Shortcode
