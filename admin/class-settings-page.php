<?php
/**
 * Settings Page
 *
 * Registers the Trustpilot Reviews settings sub-page, the WordPress Settings API
 * fields for API credentials, and the Business Unit ID resolver sanitize_callback.
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TP_Settings_Page {
    const OPTION_GROUP = 'tp_credentials';
    const PAGE_SLUG    = 'tp_settings';

    /** @var string Hook suffix returned by add_submenu_page() for the Settings sub-page */
    public string $settings_hook = '';

    /** @var string Hook suffix returned by add_submenu_page() for the Dashboard sub-page (stored here for CSS enqueue) */
    public string $dashboard_hook = '';

    /**
     * Register WordPress hooks for this class.
     *
     * Called from bootstrap after instantiation. Does NOT register admin_menu —
     * that is handled by the bootstrap wiring plan (02-03) so hook suffixes can
     * be stored back into $this->settings_hook and $this->dashboard_hook.
     */
    public function register_hooks(): void {
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
    }

    /**
     * Register all three credential options with the WordPress Settings API.
     *
     * All options belong to the same group ('tp_credentials') and page slug
     * ('tp_settings') to avoid the option-group / page-slug mismatch pitfall (P1).
     */
    public function register_settings(): void {
        register_setting( self::OPTION_GROUP, 'tp_api_key', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_api_key' ],
        ] );

        register_setting( self::OPTION_GROUP, 'tp_api_secret', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_api_secret' ],
        ] );

        register_setting( self::OPTION_GROUP, 'tp_business_domain', [
            'type'              => 'string',
            'sanitize_callback' => [ $this, 'sanitize_and_resolve_domain' ],
        ] );

        add_settings_section(
            'tp_api_section',
            __( 'API Credentials', 'trustpilot-reviews' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field( 'tp_api_key',        __( 'API Key',         'trustpilot-reviews' ), [ $this, 'render_api_key_field'    ], self::PAGE_SLUG, 'tp_api_section' );
        add_settings_field( 'tp_api_secret',      __( 'API Secret',      'trustpilot-reviews' ), [ $this, 'render_api_secret_field' ], self::PAGE_SLUG, 'tp_api_section' );
        add_settings_field( 'tp_business_domain', __( 'Business Domain', 'trustpilot-reviews' ), [ $this, 'render_domain_field'     ], self::PAGE_SLUG, 'tp_api_section' );

        register_setting( self::OPTION_GROUP, 'tp_date_format', [
            'type'              => 'string',
            'default'           => 'month_year',
            'sanitize_callback' => [ $this, 'sanitize_date_format' ],
        ] );

        add_settings_field(
            'tp_date_format',
            __( 'Review date format', 'trustpilot-reviews' ),
            [ $this, 'render_date_format_field' ],
            self::PAGE_SLUG,
            'tp_api_section'
        );
    }

    /**
     * Render the API Key text field.
     *
     * Output is escaped via esc_attr() — T-02-06.
     */
    public function render_api_key_field(): void {
        printf(
            '<input type="password" id="tp_api_key" name="tp_api_key" value="%s" class="regular-text" autocomplete="new-password" />',
            esc_attr( get_option( 'tp_api_key', '' ) )
        );
    }

    /**
     * Render the API Secret password field.
     *
     * Uses type="password" and autocomplete="new-password" to prevent browser
     * autofill caching — T-02-02. Value is escaped via esc_attr() — T-02-06.
     */
    public function render_api_secret_field(): void {
        printf(
            '<input type="password" id="tp_api_secret" name="tp_api_secret" value="%s" class="regular-text" autocomplete="new-password" />',
            esc_attr( get_option( 'tp_api_secret', '' ) )
        );
    }

    /**
     * Render the Business Domain text field.
     *
     * Output is escaped via esc_attr() — T-02-06.
     */
    public function render_domain_field(): void {
        printf(
            '<input type="text" id="tp_business_domain" name="tp_business_domain" value="%s" class="regular-text" placeholder="example.trustpilot.com" />',
            esc_attr( get_option( 'tp_business_domain', '' ) )
        );
    }

    /**
     * Sanitize the API secret, preserving the existing DB value when POST is empty.
     *
     * If the admin saves the form without re-entering the secret (type="password"
     * fields are typically not pre-filled by browsers), the empty POST value would
     * overwrite the stored secret with an empty string. This callback detects the
     * empty case and returns the existing option value instead — Pitfall P5.
     *
     * @param string $value Raw value from the POST submission.
     * @return string Sanitized secret, or the existing DB value if $value is empty.
     */
    public function sanitize_api_secret( string $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) {
            return (string) get_option( 'tp_api_secret', '' );
        }
        return $value;
    }

    /**
     * Sanitize the API key, preserving the existing DB value when POST is empty.
     *
     * Same empty-guard pattern as sanitize_api_secret() — type="password" fields
     * are not pre-filled by browsers, so an empty POST must not overwrite the key.
     *
     * @param string $value Raw value from the POST submission.
     * @return string Sanitized key, or the existing DB value if $value is empty.
     */
    public function sanitize_api_key( string $value ): string {
        $value = sanitize_text_field( $value );
        if ( '' === $value ) {
            return (string) get_option( 'tp_api_key', '' );
        }
        return $value;
    }

    /**
     * Sanitize the tp_date_format option value.
     *
     * Allowlist pattern — only the three declared format keys are accepted.
     * Any other value (e.g. empty string, arbitrary input) falls back to 'month_year'.
     *
     * @param string $value Raw value from POST submission.
     * @return string One of: 'month_year', 'relative', 'full_date'.
     */
    public function sanitize_date_format( string $value ): string {
        $allowed = [ 'month_year', 'relative', 'full_date' ];
        return in_array( $value, $allowed, true ) ? $value : 'month_year';
    }

    /**
     * Render the Review date format select field.
     *
     * Uses get_option('tp_date_format', 'month_year') for the saved value.
     * Uses the selected() WP helper to mark the active option.
     * All option labels are translatable via __() (D-14).
     */
    public function render_date_format_field(): void {
        $current = (string) get_option( 'tp_date_format', 'month_year' );
        $options = [
            'month_year' => __( 'Month and year (e.g. March 2024)', 'trustpilot-reviews' ),
            'relative'   => __( 'Relative (e.g. 3 months ago)',      'trustpilot-reviews' ),
            'full_date'  => __( 'Full date (e.g. 15 March 2024)',    'trustpilot-reviews' ),
        ];
        echo '<select id="tp_date_format" name="tp_date_format">';
        foreach ( $options as $value => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $value ),
                selected( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    /**
     * Sanitize the business domain and resolve the Trustpilot Business Unit ID.
     *
     * Runs as the sanitize_callback for 'tp_business_domain'. Makes a live HTTP
     * call to the Trustpilot public endpoint and writes the resolved ID to
     * 'tp_business_unit_id' in wp_options on success.
     *
     * Reads the API key from $_POST rather than get_option() to avoid the
     * resolution order problem: when both fields are submitted together,
     * update_option('tp_api_key') has not fired yet when this callback runs
     * (sanitize callbacks fire before update_option) — Pitfall P2.
     *
     * On failure, adds a settings error but still returns the sanitized domain
     * so the other credentials are saved normally — T-02-01.
     *
     * @param string $domain Raw value from the POST submission.
     * @return string Sanitized domain string.
     */
    public function sanitize_and_resolve_domain( string $domain ): string {
        $domain = sanitize_text_field( $domain );

        if ( empty( $domain ) ) {
            return $domain;
        }

        // Read from $_POST to get the value just submitted, not the stale DB value (Pitfall P2).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $api_key = isset( $_POST['tp_api_key'] )
            ? sanitize_text_field( wp_unslash( $_POST['tp_api_key'] ) )
            : (string) get_option( 'tp_api_key', '' );

        $url = add_query_arg(
            [ 'name' => $domain ],
            'https://api.trustpilot.com/v1/business-units/find'
        );

        $response = wp_remote_get( $url, [
            'headers' => [ 'apikey' => $api_key ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            add_settings_error(
                'tp_business_domain',
                'tp_bu_resolve_failed',
                __( 'Could not resolve Business Unit ID from domain. Check domain and API key.', 'trustpilot-reviews' ),
                'error'
            );
            return $domain;
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        $bu_id = isset( $body['id'] ) ? (string) $body['id'] : '';

        if ( ! empty( $bu_id ) ) {
            update_option( 'tp_business_unit_id', sanitize_text_field( $bu_id ) );
        } else {
            add_settings_error(
                'tp_business_domain',
                'tp_bu_empty',
                __( 'Business Unit ID not found in API response.', 'trustpilot-reviews' ),
                'error'
            );
        }

        return $domain;
    }

    /**
     * Render the Settings page HTML.
     *
     * Gated by current_user_can('manage_options') — T-02-05. Form targets
     * options.php (not admin-post.php) per the WordPress Settings API contract.
     * settings_fields() emits the CSRF nonce and option-page fields — T-02-01.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button( __( 'Save Settings', 'trustpilot-reviews' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue tp-admin.css only on the two plugin admin pages.
     *
     * Uses hook suffix comparison (Pattern 7 from RESEARCH.md) — the $hook
     * parameter matches the return values of add_menu_page() / add_submenu_page(),
     * stored in $this->settings_hook and $this->dashboard_hook by the bootstrap.
     * Filters out empty strings via array_filter() so an un-wired instance is safe.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_styles( string $hook ): void {
        $allowed = array_filter( [ $this->settings_hook, $this->dashboard_hook ] );
        if ( empty( $allowed ) || ! in_array( $hook, $allowed, true ) ) {
            return;
        }
        wp_enqueue_style(
            'tp-admin',
            plugin_dir_url( TP_PLUGIN_FILE ) . 'assets/tp-admin.css',
            [],
            TP_PLUGIN_VERSION
        );
    }
}
