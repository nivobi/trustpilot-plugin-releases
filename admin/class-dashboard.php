<?php
/**
 * Dashboard Page
 *
 * Renders the Trustpilot sync status dashboard and handles the "Sync Now"
 * admin-post.php form action. All methods are static — this class is stateless.
 *
 * @package TrustpilotReviews
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TP_Dashboard {

	/**
	 * Register hooks — called from bootstrap (02-03).
	 *
	 * Registers the admin_post action for the Sync Now form handler.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_tp_sync_now', [ 'TP_Dashboard', 'handle_sync_now' ] );
	}

	/**
	 * Handle the Sync Now form POST.
	 *
	 * Security order (Pitfall P3):
	 *   1. CSRF check via check_admin_referer() — terminates on failure.
	 *   2. Capability check — wp_die() on failure.
	 *   3. Run sync.
	 *   4. wp_safe_redirect() + exit (no gap — headers must not be sent before redirect).
	 */
	public static function handle_sync_now(): void {
		check_admin_referer( 'tp_sync_now' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( tp_t( 'Insufficient permissions.', 'Manglende rettigheder.' ) ) );
		}

		TP_Sync_Engine::run();

		wp_safe_redirect( add_query_arg(
			[
				'page'   => 'tp-reviews',
				'synced' => '1',
			],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Render the Dashboard status page.
	 *
	 * Reads wp_options written by TP_Sync_Engine::run() (tp_last_sync,
	 * tp_last_sync_count, tp_last_error) and runs a COUNT(*) query against
	 * the reviews table to show total reviews in the database.
	 */
	/**
	 * Render sync status panel (no page wrapper). Called from TP_Preset_UI::render().
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table      = $wpdb->prefix . 'tp_reviews';
		$last_sync  = (string) get_option( 'tp_last_sync', '' );
		$last_count = (int) get_option( 'tp_last_sync_count', 0 );
		$last_error = (string) get_option( 'tp_last_error', '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_reviews = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( empty( $last_sync ) ) {
			$sync_display = esc_html( tp_t( 'Never', 'Aldrig' ) );
		} else {
			$sync_display = esc_html(
				wp_date(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					strtotime( $last_sync )
				)
			);
		}

		$count_display = ( 0 === $last_count && empty( $last_sync ) )
			? '&mdash;'
			: esc_html( (string) $last_count );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$synced = isset( $_GET['synced'] ) && '1' === $_GET['synced'];
		?>
		<?php if ( $synced ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( tp_t( 'Sync completed successfully.', 'Synkronisering gennemført.' ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_error ) ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php echo esc_html( tp_t( 'Last sync error:', 'Seneste synkroniseringsfejl:' ) ); ?>
					<?php echo ' ' . esc_html( $last_error ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		$next_run   = TP_Cron_Manager::get_next_run();
		$freq_label = TP_Cron_Manager::frequency_label( TP_Cron_Manager::get_frequency_slug() );
		if ( $next_run > 0 ) {
			$next_display = esc_html(
				wp_date(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					$next_run
				)
			) . ' <span class="description">(' . esc_html( $freq_label ) . ')</span>';
		} else {
			$next_display = esc_html( tp_t( 'Not scheduled', 'Ikke planlagt' ) );
		}
		?>

		<div class="tp-status-card">
			<table class="tp-status-table">
				<tr>
					<td class="tp-status-label"><?php echo esc_html( tp_t( 'Last sync', 'Seneste synkronisering' ) ); ?></td>
					<td class="tp-status-value"><?php echo $sync_display; // Already escaped above. ?></td>
				</tr>
				<tr>
					<td class="tp-status-label"><?php echo esc_html( tp_t( 'Next sync', 'Næste synkronisering' ) ); ?></td>
					<td class="tp-status-value"><?php echo $next_display; // Already escaped above. ?></td>
				</tr>
				<tr>
					<td class="tp-status-label"><?php echo esc_html( tp_t( 'Reviews in database', 'Anmeldelser i databasen' ) ); ?></td>
					<td class="tp-status-value"><?php echo esc_html( (string) $total_reviews ); ?></td>
				</tr>
				<tr>
					<td class="tp-status-label"><?php echo esc_html( tp_t( 'Last sync count', 'Antal ved seneste synkronisering' ) ); ?></td>
					<td class="tp-status-value"><?php echo $count_display; // esc_html() applied above or literal &mdash;. ?></td>
				</tr>
			</table>
		</div>

		<div class="tp-sync-form">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tp_sync_now">
				<?php wp_nonce_field( 'tp_sync_now' ); ?>
				<?php submit_button( tp_t( 'Sync Now', 'Synkronisér nu' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( tp_t( 'Trustpilot Dashboard', 'Trustpilot-oversigt' ) ); ?></h1>
			<?php self::render_panel(); ?>
		</div>
		<?php
	}
}
