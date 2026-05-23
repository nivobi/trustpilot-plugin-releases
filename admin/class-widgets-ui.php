<?php
/**
 * Widgets Admin Sub-page
 *
 * Renders a gallery of available frontend widgets. Each tile shows a live
 * preview rendered against current synced data and exposes a copy-to-
 * clipboard shortcode input.
 *
 * @package TrustpilotReviews
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Widgets_UI {

    /**
     * Hook suffix returned by add_submenu_page() — assigned by the bootstrap
     * so enqueue_styles() can gate CSS loading to this page only.
     *
     * @var string
     */
    public static string $widgets_hook = '';

    public static function register_hooks(): void {
        add_action( 'admin_enqueue_scripts', [ 'TP_Widgets_UI', 'enqueue_styles' ] );
    }

    /**
     * Enqueue admin CSS only on the Widgets sub-page.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueue_styles( string $hook ): void {
        if ( empty( self::$widgets_hook ) || $hook !== self::$widgets_hook ) {
            return;
        }
        wp_enqueue_style(
            'tp-admin',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/tp-admin.css',
            [],
            TP_PLUGIN_VERSION
        );
        // The badge preview needs the frontend CSS too — enqueue it here.
        wp_enqueue_style(
            'tp-reviews',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/tp-reviews.css',
            [],
            TP_PLUGIN_VERSION
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $score = (float) get_option( 'tp_trust_score', 0 );
        $has_data = $score > 0;
        $intro = tp_t(
            'Drop these widgets anywhere on your site. Copy the shortcode and paste it into a page, post, or Elementor Shortcode widget.',
            'Brug disse widgets hvor som helst på dit websted. Kopiér kortkoden og indsæt den i en side, et indlæg eller i Elementors Shortcode-widget.'
        );

        $tile_title    = tp_t( 'TrustScore Badge', 'TrustScore-mærke' );
        $copy_label    = tp_t( 'Copy', 'Kopiér' );
        $copied_label  = tp_t( 'Copied!', 'Kopieret!' );

        $empty_state = tp_t(
            'Run a sync from Dashboard to see a live preview.',
            'Kør en synkronisering fra Oversigten for at se en live-visning.'
        );

        // Each size variant: visible label, shortcode string, hint about use.
        $variants = [
            'small'  => [
                'label'     => tp_t( 'Small (for headers)', 'Lille (til headers)' ),
                'shortcode' => '[tp_score_badge size="small"]',
            ],
            'medium' => [
                'label'     => tp_t( 'Medium (default)', 'Mellem (standard)' ),
                'shortcode' => '[tp_score_badge]',
            ],
            'large'  => [
                'label'     => tp_t( 'Large', 'Stor' ),
                'shortcode' => '[tp_score_badge size="large"]',
            ],
        ];

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( tp_t( 'Widgets', 'Widgets' ) ); ?></h1>
            <p><?php echo esc_html( $intro ); ?></p>

            <div class="tp-widget-card">
                <h2 class="tp-widget-card__title"><?php echo esc_html( $tile_title ); ?></h2>

                <?php if ( ! $has_data ) : ?>
                    <div class="tp-widget-card__preview">
                        <p class="tp-widget-card__empty"><?php echo esc_html( $empty_state ); ?></p>
                    </div>
                <?php else : ?>
                    <?php $i = 0; foreach ( $variants as $size => $v ) : $i++; $input_id = 'tp-shortcode-score-badge-' . $size; ?>
                        <div class="tp-widget-card__variant">
                            <h3 class="tp-widget-card__variant-label"><?php echo esc_html( $v['label'] ); ?></h3>
                            <div class="tp-widget-card__preview">
                                <?php echo TP_Score_Badge::render( [ 'size' => $size ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                            <div class="tp-widget-card__shortcode-row">
                                <input
                                    type="text"
                                    class="tp-widget-card__shortcode-input"
                                    value="<?php echo esc_attr( $v['shortcode'] ); ?>"
                                    readonly
                                    id="<?php echo esc_attr( $input_id ); ?>"
                                >
                                <button type="button" class="button button-primary tp-widget-card__copy-btn" data-target="<?php echo esc_attr( $input_id ); ?>">
                                    <?php echo esc_html( $copy_label ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <script>
            (function(){
                var copiedLabel = <?php echo wp_json_encode( $copied_label ); ?>;
                document.querySelectorAll('.tp-widget-card__copy-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var input = document.getElementById( btn.dataset.target );
                        if ( ! input ) return;
                        var orig = btn.textContent;
                        var done = function(){
                            btn.textContent = copiedLabel;
                            setTimeout(function(){ btn.textContent = orig; }, 1500);
                        };
                        if ( navigator.clipboard && navigator.clipboard.writeText ) {
                            navigator.clipboard.writeText( input.value ).then( done, function(){
                                input.select();
                                document.execCommand( 'copy' );
                                done();
                            } );
                        } else {
                            input.select();
                            document.execCommand( 'copy' );
                            done();
                        }
                    });
                });
            })();
            </script>
        </div>
        <?php
    }
}
