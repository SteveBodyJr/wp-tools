<?php
/**
 * Admin screen.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one screen: how many, how many today, which trap, and the last ten.
 *
 * A top-level menu of its own — this is a monitoring screen someone checks on
 * its own schedule, not a setting tucked under something else. Built with
 * Tailwind's Play CDN rather than a hand-rolled stylesheet: it is loaded on
 * this one authenticated wp-admin screen only, never on the front end, so the
 * "not for production" caution in Tailwind's own docs is about a public page
 * shipping the JIT compiler to every visitor — the situation this isn't.
 *
 * @since 1.0.0
 */
class Beaver_Chameleon_Admin {

	/**
	 * Capability required to view the screen or reset its data.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action for the reset-statistics form.
	 */
	const NONCE = 'beaver_chameleon_reset';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Adds the top-level menu item.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Chameleon Shield', 'beaver-chameleon' ),
			__( 'Chameleon Shield', 'beaver-chameleon' ),
			self::CAPABILITY,
			BEAVER_CHAMELEON_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-shield-alt',
			80
		);
	}

	/**
	 * Loads Tailwind and this screen's own tiny assets, on this screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, BEAVER_CHAMELEON_SLUG ) ) {
			return;
		}

		// Tailwind's Play CDN: a JIT compiler that scans the page and injects
		// exactly the utility CSS the markup below uses. No build step, no
		// bundled file to keep in sync — the price is a runtime request to
		// Tailwind's CDN and a console notice that it isn't meant for a public
		// production page, which this authenticated, on-demand screen isn't.
		wp_enqueue_script( 'beaver-chameleon-tailwind', 'https://cdn.tailwindcss.com', array(), null, false );

		wp_enqueue_style( 'beaver-chameleon-admin', BEAVER_CHAMELEON_URL . 'admin/css/admin.css', array(), BEAVER_CHAMELEON_VERSION );
		wp_enqueue_script( 'beaver-chameleon-admin', BEAVER_CHAMELEON_URL . 'admin/js/admin.js', array(), BEAVER_CHAMELEON_VERSION, true );

		wp_localize_script(
			'beaver-chameleon-admin',
			'beaverChameleon',
			array(
				'confirmReset' => __( 'Reset all Chameleon Shield statistics? This cannot be undone.', 'beaver-chameleon' ),
			)
		);
	}

	/**
	 * Handles the reset-statistics form post.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['beaver_chameleon_action'] ) || 'reset' !== $_POST['beaver_chameleon_action'] ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do this.', 'beaver-chameleon' ),
				esc_html__( '403 Forbidden', 'beaver-chameleon' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		Beaver_Chameleon_Stats::reset();

		wp_safe_redirect( add_query_arg( 'bc_reset', '1', admin_url( 'admin.php?page=' . BEAVER_CHAMELEON_SLUG ) ) );
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-chameleon' ) );
		}

		$totals = Beaver_Chameleon_Stats::totals();
		$today  = Beaver_Chameleon_Stats::today();
		$log    = Beaver_Chameleon_Stats::recent_log();

		$total    = (int) $totals['total'];
		$honeypot = (int) $totals['honeypot'];
		$behavior = (int) $totals['behavior'];
		$hp_pct   = $total > 0 ? (int) round( ( $honeypot / $total ) * 100 ) : 0;
		$bh_pct   = $total > 0 ? 100 - $hp_pct : 0;
		?>
		<div class="wrap beaver-chameleon-admin">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Chameleon Shield', 'beaver-chameleon' ); ?></h1>

			<?php if ( isset( $_GET['bc_reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state changes on this branch. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Chameleon Shield statistics were reset.', 'beaver-chameleon' ); ?></p></div>
			<?php endif; ?>

			<div class="bc-app bg-slate-50 rounded-2xl border border-slate-200 p-6 sm:p-10">
				<div class="max-w-5xl mx-auto space-y-8">

					<header class="flex items-start gap-3">
						<span class="text-3xl leading-none" aria-hidden="true">🦎</span>
						<div>
							<h2 class="text-2xl font-semibold text-slate-900 m-0"><?php esc_html_e( 'Chameleon Shield', 'beaver-chameleon' ); ?></h2>
							<p class="text-sm text-slate-500 mt-1 mb-0"><?php esc_html_e( 'A daily-mutating honeypot and a human-interaction trap, guarding the comment and login forms.', 'beaver-chameleon' ); ?></p>
						</div>
					</header>

					<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

						<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
							<p class="text-xs font-medium uppercase tracking-wide text-slate-400 m-0"><?php esc_html_e( 'Total Bots Blocked', 'beaver-chameleon' ); ?></p>
							<p class="text-3xl font-semibold text-slate-900 mt-2 mb-0"><?php echo esc_html( number_format_i18n( $total ) ); ?></p>
							<p class="text-xs text-slate-400 mt-1 mb-0"><?php esc_html_e( 'since install or last reset', 'beaver-chameleon' ); ?></p>
						</div>

						<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
							<p class="text-xs font-medium uppercase tracking-wide text-slate-400 m-0"><?php esc_html_e( 'Blocks Today', 'beaver-chameleon' ); ?></p>
							<p class="text-3xl font-semibold text-slate-900 mt-2 mb-0"><?php echo esc_html( number_format_i18n( $today ) ); ?></p>
							<p class="text-xs text-slate-400 mt-1 mb-0"><?php esc_html_e( 'resets at midnight, site time', 'beaver-chameleon' ); ?></p>
						</div>

						<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
							<p class="text-xs font-medium uppercase tracking-wide text-slate-400 m-0"><?php esc_html_e( 'By Trap', 'beaver-chameleon' ); ?></p>

							<div class="mt-3">
								<div class="flex items-center justify-between text-sm">
									<span class="text-slate-600"><?php esc_html_e( 'Honeypot', 'beaver-chameleon' ); ?></span>
									<span class="font-medium text-slate-900"><?php echo esc_html( number_format_i18n( $honeypot ) ); ?></span>
								</div>
								<div class="h-1.5 w-full bg-slate-100 rounded-full mt-1 overflow-hidden">
									<div class="h-full bg-amber-400 rounded-full" style="width:<?php echo esc_attr( $hp_pct ); ?>%"></div>
								</div>
							</div>

							<div class="mt-3">
								<div class="flex items-center justify-between text-sm">
									<span class="text-slate-600"><?php esc_html_e( 'Behavior', 'beaver-chameleon' ); ?></span>
									<span class="font-medium text-slate-900"><?php echo esc_html( number_format_i18n( $behavior ) ); ?></span>
								</div>
								<div class="h-1.5 w-full bg-slate-100 rounded-full mt-1 overflow-hidden">
									<div class="h-full bg-rose-400 rounded-full" style="width:<?php echo esc_attr( $bh_pct ); ?>%"></div>
								</div>
							</div>
						</div>

					</div>

					<div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
						<div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-200">
							<h3 class="text-base font-semibold text-slate-900 m-0"><?php esc_html_e( 'Recent Blocks', 'beaver-chameleon' ); ?></h3>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . BEAVER_CHAMELEON_SLUG ) ); ?>" id="beaver-chameleon-reset-form">
								<?php wp_nonce_field( self::NONCE ); ?>
								<input type="hidden" name="beaver_chameleon_action" value="reset" />
								<button type="submit" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
									<?php esc_html_e( 'Reset statistics', 'beaver-chameleon' ); ?>
								</button>
							</form>
						</div>

						<?php if ( empty( $log ) ) : ?>
							<p class="px-5 py-8 text-sm text-slate-400 text-center m-0"><?php esc_html_e( 'No blocks recorded yet.', 'beaver-chameleon' ); ?></p>
						<?php else : ?>
							<div class="overflow-x-auto">
								<table class="w-full text-sm">
									<thead>
										<tr class="text-left text-xs uppercase tracking-wide text-slate-400 bg-slate-50">
											<th class="px-5 py-2 font-medium"><?php esc_html_e( 'Timestamp', 'beaver-chameleon' ); ?></th>
											<th class="px-5 py-2 font-medium"><?php esc_html_e( 'IP (masked)', 'beaver-chameleon' ); ?></th>
											<th class="px-5 py-2 font-medium"><?php esc_html_e( 'Trap', 'beaver-chameleon' ); ?></th>
										</tr>
									</thead>
									<tbody class="divide-y divide-slate-100">
										<?php foreach ( $log as $entry ) : ?>
											<?php
											$is_honeypot = isset( $entry['reason'] ) && 'honeypot' === $entry['reason'];
											$badge_class = $is_honeypot
												? 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200'
												: 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200';
											$badge_text  = $is_honeypot
												? __( 'Honeypot', 'beaver-chameleon' )
												: __( 'Behavior', 'beaver-chameleon' );
											?>
											<tr>
												<td class="px-5 py-2.5 text-slate-700 whitespace-nowrap">
													<?php echo isset( $entry['time'] ) ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) : ''; ?>
												</td>
												<td class="px-5 py-2.5 text-slate-700 font-mono whitespace-nowrap"><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
												<td class="px-5 py-2.5">
													<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_text ); ?></span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>

					<?php self::render_credit(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="flex items-center gap-4 pt-2">
			<img class="w-24 h-auto opacity-80" width="300" height="152"
				src="<?php echo esc_url( BEAVER_CHAMELEON_URL . 'assets/digital-beaver-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-chameleon' ); ?>" />
			<div class="text-sm text-slate-500">
				<strong class="text-slate-700"><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-chameleon' ); ?></strong><br />
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-chameleon' ); ?>
				<a class="text-slate-700 underline" href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
