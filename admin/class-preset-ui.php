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
     * Enqueue preset admin CSS — gated to Presets page only (D-13).
     *
     * Hook suffix is stored by bootstrap wiring in self::$presets_hook after
     * add_submenu_page() returns (03-02 plan).
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueue_styles( string $hook ): void {
        if ( empty( self::$presets_hook ) || $hook !== self::$presets_hook ) {
            return;
        }
        wp_enqueue_style(
            'tp-admin',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/tp-admin.css',
            [],
            TP_PLUGIN_VERSION
        );
    }

    /**
     * Render the Presets admin page.
     *
     * Outputs: capability gate → admin notices → list table → add/edit form.
     * Pre-fills form when ?action=edit&preset={slug} is present.
     *
     * @return void
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Load presets via shared Preset Manager (D-10).
        $presets = TP_Preset_Manager::get_all();

        // Detect edit mode: ?action=edit&preset={slug}
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display, no state change.
        $edit_mode    = isset( $_GET['action'] ) && 'edit' === $_GET['action']
                        && isset( $_GET['preset'] ) && ! empty( $_GET['preset'] );
        $editing_slug = $edit_mode ? sanitize_title( wp_unslash( $_GET['preset'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Find preset being edited (if any).
        $edit_preset  = null;
        if ( $edit_mode ) {
            foreach ( $presets as $preset ) {
                if ( $preset['slug'] === $editing_slug ) {
                    $edit_preset = $preset;
                    break;
                }
            }
            // If slug in URL no longer exists, fall back to create mode.
            if ( null === $edit_preset ) {
                $edit_mode    = false;
                $editing_slug = '';
            }
        }

        // Detect admin notice from ?result= query arg.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = isset( $_GET['result'] ) ? sanitize_key( $_GET['result'] ) : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Presets', 'trustpilot-reviews' ); ?></h1>
            <?php TP_Dashboard::render_panel(); ?>

            <?php
            // Admin notices (UI-SPEC: Admin Notices table).
            if ( 'saved' === $result ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Preset saved successfully.', 'trustpilot-reviews' ); ?></p>
                </div>
            <?php elseif ( 'deleted' === $result ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Preset deleted.', 'trustpilot-reviews' ); ?></p>
                </div>
            <?php elseif ( 'error_slug_exists' === $result ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'A preset with that slug already exists. Choose a different slug.', 'trustpilot-reviews' ); ?></p>
                </div>
            <?php elseif ( 'error_invalid' === $result ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'Could not save preset. Check all fields and try again.', 'trustpilot-reviews' ); ?></p>
                </div>
            <?php endif; ?>

            <?php /* ── List Table (D-07, UI-SPEC) ── */ ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:20%"><?php esc_html_e( 'Slug', 'trustpilot-reviews' ); ?></th>
                        <th style="width:40%"><?php esc_html_e( 'Keywords', 'trustpilot-reviews' ); ?></th>
                        <th style="width:12%"><?php esc_html_e( 'Min Stars', 'trustpilot-reviews' ); ?></th>
                        <th style="width:10%"><?php esc_html_e( 'Limit', 'trustpilot-reviews' ); ?></th>
                        <th style="width:18%"><?php esc_html_e( 'Actions', 'trustpilot-reviews' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $presets ) ) : ?>
                        <tr>
                            <td colspan="5">
                                <p class="tp-empty-state"><?php esc_html_e( 'No presets yet. Use the form below to create one.', 'trustpilot-reviews' ); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $presets as $preset ) : ?>
                            <tr>
                                <td><?php echo esc_html( $preset['slug'] ); ?></td>
                                <td><?php echo esc_html( $preset['keywords'] ); ?></td>
                                <td><?php echo esc_html( (string) $preset['min_stars'] ); ?></td>
                                <td><?php echo esc_html( (string) $preset['limit'] ); ?></td>
                                <td class="tp-actions-cell">
                                    <a href="<?php echo esc_url( add_query_arg( [
                                        'page'   => 'tp-reviews',
                                        'action' => 'edit',
                                        'preset' => $preset['slug'],
                                    ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'trustpilot-reviews' ); ?></a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <input type="hidden" name="action"      value="tp_delete_preset">
                                        <input type="hidden" name="preset_slug" value="<?php echo esc_attr( $preset['slug'] ); ?>">
                                        <?php wp_nonce_field( "tp_delete_preset_{$preset['slug']}" ); ?>
                                        <button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'trustpilot-reviews' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php /* ── Add / Edit Form (D-03, UI-SPEC) ── */ ?>
            <div class="tp-preset-form-card">
                <?php if ( $edit_mode && null !== $edit_preset ) : ?>
                    <h2><?php printf(
                        /* translators: %s: preset slug */
                        esc_html__( 'Edit Preset: %s', 'trustpilot-reviews' ),
                        esc_html( $edit_preset['slug'] )
                    ); ?></h2>
                    <div class="notice notice-info inline">
                        <p><?php esc_html_e( 'Slug cannot be changed after creation — doing so would break deployed shortcodes.', 'trustpilot-reviews' ); ?></p>
                    </div>
                <?php else : ?>
                    <h2><?php esc_html_e( 'Add Preset', 'trustpilot-reviews' ); ?></h2>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="tp_save_preset">
                    <?php wp_nonce_field( 'tp_save_preset' ); ?>

                    <?php if ( $edit_mode && null !== $edit_preset ) : ?>
                        <?php /* Edit mode: slug is read-only; original slug passed as hidden field (D-09). */ ?>
                        <input type="hidden" name="preset_original_slug" value="<?php echo esc_attr( $edit_preset['slug'] ); ?>">
                    <?php endif; ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="tp_slug"><?php esc_html_e( 'Slug', 'trustpilot-reviews' ); ?></label>
                                </th>
                                <td>
                                    <?php if ( $edit_mode && null !== $edit_preset ) : ?>
                                        <span class="tp-slug-readonly"><?php echo esc_html( $edit_preset['slug'] ); ?></span>
                                    <?php else : ?>
                                        <input
                                            name="tp_slug"
                                            id="tp_slug"
                                            type="text"
                                            class="regular-text"
                                            required
                                            pattern="[a-z0-9\-]+"
                                            maxlength="100"
                                            value="">
                                        <p class="description"><?php esc_html_e( 'Unique identifier used in the shortcode: [tp_reviews id="your-slug"]. Lowercase, numbers, and hyphens only.', 'trustpilot-reviews' ); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tp_keywords"><?php esc_html_e( 'Keywords', 'trustpilot-reviews' ); ?></label>
                                </th>
                                <td>
                                    <input
                                        name="tp_keywords"
                                        id="tp_keywords"
                                        type="text"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'e.g. excellent, fast delivery, great service', 'trustpilot-reviews' ); ?>"
                                        value="<?php echo esc_attr( $edit_mode && null !== $edit_preset ? $edit_preset['keywords'] : '' ); ?>">
                                    <p class="description"><?php esc_html_e( 'Comma-separated list. A review matches if its text contains any of these words. Leave blank to match all reviews.', 'trustpilot-reviews' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tp_min_stars"><?php esc_html_e( 'Min Stars', 'trustpilot-reviews' ); ?></label>
                                </th>
                                <td>
                                    <select name="tp_min_stars" id="tp_min_stars">
                                        <?php
                                        $saved_stars = $edit_mode && null !== $edit_preset ? (int) $edit_preset['min_stars'] : 1;
                                        $star_options = [
                                            1 => __( '1 Star (any rating)', 'trustpilot-reviews' ),
                                            2 => __( '2 Stars', 'trustpilot-reviews' ),
                                            3 => __( '3 Stars', 'trustpilot-reviews' ),
                                            4 => __( '4 Stars', 'trustpilot-reviews' ),
                                            5 => __( '5 Stars only', 'trustpilot-reviews' ),
                                        ];
                                        foreach ( $star_options as $val => $label ) :
                                            printf(
                                                '<option value="%d"%s>%s</option>',
                                                $val,
                                                selected( $saved_stars, $val, false ),
                                                esc_html( $label )
                                            );
                                        endforeach;
                                        ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Only include reviews with this star rating or higher. Select 1 to include all star ratings.', 'trustpilot-reviews' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="tp_limit"><?php esc_html_e( 'Limit', 'trustpilot-reviews' ); ?></label>
                                </th>
                                <td>
                                    <input
                                        name="tp_limit"
                                        id="tp_limit"
                                        type="number"
                                        class="small-text"
                                        min="1"
                                        max="100"
                                        required
                                        value="<?php echo esc_attr( (string) ( $edit_mode && null !== $edit_preset ? $edit_preset['limit'] : 10 ) ); ?>">
                                    <p class="description"><?php esc_html_e( 'Maximum number of reviews to return for this preset. Between 1 and 100.', 'trustpilot-reviews' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php
                    if ( $edit_mode ) {
                        submit_button( __( 'Update Preset', 'trustpilot-reviews' ), 'primary', 'submit', true );
                    } else {
                        submit_button( __( 'Add Preset', 'trustpilot-reviews' ), 'primary', 'submit', true );
                    }
                    ?>
                </form>
            </div><!-- .tp-preset-form-card -->
        </div><!-- .wrap -->
        <?php
    }
}
