<?php
/**
 * Score Badge Widget
 *
 * Registers and renders the [tp_score_badge] shortcode. Produces a linked
 * Trustpilot logo (reusing assets/trustpilot-logo.svg) plus a localized
 * "based on X reviews." caption. Reads already-synced wp_options — never
 * makes network calls.
 *
 * @package TrustpilotReviews
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Score_Badge {

    /**
     * Register hooks — called from bootstrap inside the init action, outside
     * the is_admin() block so the shortcode is available on frontend requests.
     */
    public static function register_hooks(): void {
        add_shortcode( 'tp_score_badge', [ 'TP_Score_Badge', 'render' ] );
    }

    /**
     * Render the badge HTML. Public so TP_Widgets_UI can call it directly
     * for the admin live preview.
     *
     * @param array|string $atts Shortcode attributes (none used today).
     * @return string HTML markup.
     */
    public static function render( $atts = [] ): string {
        wp_enqueue_style( 'tp-reviews' );

        $atts = shortcode_atts(
            [
                'size' => 'medium', // small | medium | large
            ],
            (array) $atts,
            'tp_score_badge'
        );
        $size       = in_array( $atts['size'], [ 'small', 'medium', 'large' ], true ) ? $atts['size'] : 'medium';
        $size_class = 'tp-score-badge--' . $size;

        $score       = (float)  get_option( 'tp_trust_score',  0 );
        $count       = (int)    get_option( 'tp_review_count', 0 );
        $profile_url = (string) get_option( 'tp_profile_url',  '' );

        // Logo: reuse the existing wordmark SVG bundled in /assets. The version
        // query string busts CDN / browser caches when the SVG file changes
        // between plugin releases — WP only versions CSS/JS handles, not raw
        // image src in shortcode output.
        $logo_src = add_query_arg(
            'ver',
            TP_PLUGIN_VERSION,
            plugins_url( 'assets/trustpilot-logo.svg', TP_PLUGIN_FILE )
        );

        $aria_label = $score > 0
            ? sprintf(
                tp_t( 'See Trustpilot reviews (%s out of 5)', 'Se Trustpilot-anmeldelser (%s ud af 5)' ),
                tp_decimal( $score, 1 )
            )
            : tp_t( 'See Trustpilot reviews', 'Se Trustpilot-anmeldelser' );

        $logo_html = sprintf(
            '<img class="tp-score-badge__logo" src="%s" alt="Trustpilot" width="130" height="31" loading="lazy">',
            esc_url( $logo_src )
        );

        $score_html = '';
        if ( $score > 0 ) {
            $score_text = sprintf(
                tp_t( '%s out of 5', '%s ud af 5' ),
                tp_decimal( $score, 1 )
            );
            $score_html = '<span class="tp-score-badge__score">' . esc_html( $score_text ) . '</span>';
        }

        // Score + logo share a single horizontal row. The whole row is the
        // clickable target so users can hit either to open the Trustpilot
        // profile (falls back to a non-link span if no profile URL is set).
        $row_inner = $score_html . $logo_html;
        if ( $profile_url !== '' ) {
            $row_html = sprintf(
                '<a class="tp-score-badge__link" href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s">%s</a>',
                esc_url( $profile_url ),
                esc_attr( $aria_label ),
                $row_inner
            );
        } else {
            $row_html = '<span class="tp-score-badge__link">' . $row_inner . '</span>';
        }

        $caption_html = '';
        if ( $count > 0 ) {
            $caption_text = sprintf(
                tp_t( 'based on %s reviews.', 'baseret på %s anmeldelser.' ),
                tp_number( $count )
            );
            $caption_html = '<p class="tp-score-badge__caption">' . esc_html( $caption_text ) . '</p>';
        }

        return '<div class="tp-score-badge ' . esc_attr( $size_class ) . '">'
            . $row_html
            . $caption_html
            . '</div>';
    }
}
