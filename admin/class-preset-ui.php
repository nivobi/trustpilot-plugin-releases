<?php
/**
 * Preset UI
 *
 * Admin CRUD interface for named review filter presets stored in wp_options.
 * Handles list table rendering, add/edit form, and admin-post.php action handlers.
 * All methods are static — this class is stateless (mirrors TP_Dashboard pattern).
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Preset_UI {

    /**
     * Hook suffix for the Presets sub-page, stored by bootstrap wiring (03-02).
     * Used by enqueue_styles() to gate CSS loading.
     *
     * @var string
     */
    public static string $presets_hook = '';

    /**
     * Register WordPress hooks.
     *
     * Called from bootstrap (03-02) inside the is_admin() block.
     * Registers admin-post.php handlers for save and delete.
     */
    public static function register_hooks(): void {
        add_action( 'admin_post_tp_save_preset',   [ 'TP_Preset_UI', 'handle_save' ] );
        add_action( 'admin_post_tp_delete_preset', [ 'TP_Preset_UI', 'handle_delete' ] );
        add_action( 'admin_enqueue_scripts',       [ 'TP_Preset_UI', 'enqueue_styles' ] );
    }

    /**
     * Handle POST admin-post.php?action=tp_save_preset.
     *
     * Covers both create (no preset_original_slug) and update (preset_original_slug present).
     * Security order: nonce → capability → validate → save → redirect.
     */
    public static function handle_save(): void {
        check_admin_referer( 'tp_save_preset' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'trustpilot-reviews' ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified above.
        $original_slug = isset( $_POST['preset_original_slug'] )
            ? sanitize_title( wp_unslash( $_POST['preset_original_slug'] ) )
            : '';

        $new_slug  = isset( $_POST['tp_slug'] )
            ? sanitize_title( wp_unslash( $_POST['tp_slug'] ) )
            : '';

        $keywords  = isset( $_POST['tp_keywords'] )
            ? sanitize_text_field( wp_unslash( $_POST['tp_keywords'] ) )
            : '';

        $min_stars = isset( $_POST['tp_min_stars'] )
            ? (int) $_POST['tp_min_stars']
            : 1;

        $limit     = isset( $_POST['tp_limit'] )
            ? (int) $_POST['tp_limit']
            : 10;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Validate slug is non-empty after sanitize_title().
        if ( empty( $new_slug ) && empty( $original_slug ) ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => 'tp-reviews', 'result' => 'error_invalid' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        // Clamp min_stars 1–5, limit 1–100.
        $min_stars = max( 1, min( 5, $min_stars ) );
        $limit     = max( 1, min( 100, $limit ) );

        // Load existing presets via shared Preset Manager (D-10 storage format).
        $presets = TP_Preset_Manager::get_all();

        $is_update = ! empty( $original_slug );

        if ( $is_update ) {
            // Edit path: find by original_slug, replace in-place.
            $found = false;
            foreach ( $presets as &$preset ) {
                if ( $preset['slug'] === $original_slug ) {
                    $preset['keywords']  = $keywords;
                    $preset['min_stars'] = $min_stars;
                    $preset['limit']     = $limit;
                    // Slug is read-only on edit (D-09); do not update it.
                    $found = true;
                    break;
                }
            }
            unset( $preset );

            if ( ! $found ) {
                // Preset to update no longer exists — treat as invalid.
                wp_safe_redirect( add_query_arg(
                    [ 'page' => 'tp-reviews', 'result' => 'error_invalid' ],
                    admin_url( 'admin.php' )
                ) );
                exit;
            }
        } else {
            // Create path: validate slug is non-empty.
            if ( empty( $new_slug ) ) {
                wp_safe_redirect( add_query_arg(
                    [ 'page' => 'tp-reviews', 'result' => 'error_invalid' ],
                    admin_url( 'admin.php' )
                ) );
                exit;
            }

            // Check for slug collision (D-09).
            foreach ( $presets as $existing ) {
                if ( $existing['slug'] === $new_slug ) {
                    wp_safe_redirect( add_query_arg(
                        [ 'page' => 'tp-reviews', 'result' => 'error_slug_exists' ],
                        admin_url( 'admin.php' )
                    ) );
                    exit;
                }
            }

            $presets[] = [
                'slug'      => $new_slug,
                'keywords'  => $keywords,
                'min_stars' => $min_stars,
                'limit'     => $limit,
            ];
        }

        if ( ! TP_Preset_Manager::save( $presets ) ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => 'tp-reviews', 'result' => 'error_invalid' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tp-reviews', 'result' => 'saved' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Handle POST admin-post.php?action=tp_delete_preset.
     *
     * Uses per-preset nonce action: tp_delete_preset_{slug} (D-06).
     */
    public static function handle_delete(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer below.
        $slug = isset( $_POST['preset_slug'] )
            ? sanitize_title( wp_unslash( $_POST['preset_slug'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( empty( $slug ) ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => 'tp-reviews', 'result' => 'error_invalid' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        // Per-preset nonce: tp_delete_preset_{slug} (D-06).
        check_admin_referer( "tp_delete_preset_{$slug}" );

        // Capability check immediately after nonce — consistent with handle_save (security order: nonce → capability).
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'trustpilot-reviews' ) );
        }

        // Remove preset with matching slug and persist via Preset Manager.
        if ( ! TP_Preset_Manager::delete( $slug ) ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => 'tp-reviews', 'result' => 'error_invalid' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tp-reviews', 'result' => 'deleted' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Enqueue React admin app — gated to Dashboard page only.
     *
     * Loads the @wordpress/scripts build output (assets/build/index.js) which
     * depends on wp-element, wp-components, and wp-api-fetch. The generated
     * index.asset.php supplies the dependency array and content-hash version.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueue_styles( string $hook ): void {
        if ( empty( self::$presets_hook ) || $hook !== self::$presets_hook ) {
            return;
        }

        $asset_file = TP_PLUGIN_DIR . 'assets/build/index.asset.php';
        if ( ! file_exists( $asset_file ) ) {
            return;
        }

        $asset = include $asset_file;

        wp_enqueue_script(
            'tp-admin-app',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'tp-admin-app',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/build/style-index.css',
            [ 'wp-components' ],
            $asset['version']
        );

        // Wire the REST nonce into @wordpress/api-fetch before the app boots.
        wp_add_inline_script(
            'tp-admin-app',
            sprintf(
                'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
                wp_json_encode( wp_create_nonce( 'wp_rest' ) )
            ),
            'before'
        );
    }

    /**
     * Render the Dashboard page — React app mounts into #tp-admin-root.
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Trustpilot Reviews', 'trustpilot-reviews' ); ?></h1>
            <div id="tp-admin-root">
                <noscript>
                    <p><?php esc_html_e( 'This admin dashboard requires JavaScript.', 'trustpilot-reviews' ); ?></p>
                </noscript>
            </div>
        </div>
        <?php
    }
}
