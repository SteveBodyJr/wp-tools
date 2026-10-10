<?php
/**
 * Settings -> Beaver Press: the "Translate site" card (branded later, B13).
 * Layout only, WordPress's own admin colours.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
final class BP_Admin {

	/** Page slug. */
	const SLUG = 'beaver-press';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bp_quiet', array( __CLASS__, 'save_quiet' ) );
		add_action( 'admin_post_bp_cache_clear', array( __CLASS__, 'clear_cache' ) );
		add_action( 'admin_post_bp_price', array( __CLASS__, 'save_price' ) );
		add_action( 'admin_post_bp_costs', array( __CLASS__, 'save_costs' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BP_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	/**
	 * Save the quiet-mode switch.
	 */
	public static function save_quiet() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_quiet' );
		update_option( BP_Quiet::OPTION, isset( $_POST['quiet'] ) ? 'yes' : 'no', false );
		update_option( BP_Auto::OPTION, isset( $_POST['auto'] ) ? 'yes' : 'no', false );
		update_option( BP_Topup::OPTION, isset( $_POST['topup'] ) ? 'yes' : 'no', false );
		update_option( BP_Alerts::OPTION, isset( $_POST['alerts'] ) ? 'yes' : 'no', false );
		BP_Topup::schedule();
		$cache = isset( $_POST['cache'] ) ? 'yes' : 'no';
		if ( get_option( BP_Cache::OPTION, 'yes' ) !== $cache ) {
			BP_Cache::clear();
		}
		update_option( BP_Cache::OPTION, $cache, false );
		$suggest  = isset( $_POST['suggest'] ) ? 'yes' : 'no';
		$redirect = isset( $_POST['redirect'] ) ? 'yes' : 'no';
		if ( get_option( BP_Suggest::OPTION, 'yes' ) !== $suggest || get_option( BP_Suggest::REDIRECT_OPTION, 'no' ) !== $redirect ) {
			BP_Cache::clear(); // Ready pages carry the old setting.
		}
		$complete = isset( $_POST['complete'] ) ? 'yes' : 'no';
		if ( get_option( BP_Complete::OPTION, 'yes' ) !== $complete ) {
			BP_Cache::clear();
		}
		update_option( BP_Complete::OPTION, $complete, false );
		$slugs = isset( $_POST['slugs'] ) ? 'yes' : 'no';
		$was   = get_option( BP_Slugs::OPTION, 'no' );
		update_option( BP_Slugs::OPTION, $slugs, false );
		if ( $was !== $slugs ) {
			BP_Slugs::forget_map();
			BP_Cache::clear();
			if ( 'yes' === $slugs ) {
				BP_Slugs::schedule_backfill(); // Every page gets its addresses in the background.
			}
		}
		update_option( BP_Suggest::OPTION, $suggest, false );
		update_option( BP_Suggest::REDIRECT_OPTION, $redirect, false );
		wp_safe_redirect( self::tab_url( 'settings', 'bp-switches' ) );
		exit;
	}

	/**
	 * "Clear" button for the ready translated pages.
	 */
	public static function clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_cache_clear' );
		BP_Cache::clear();
		wp_safe_redirect( self::tab_url( 'settings', 'bp-switches' ) );
		exit;
	}

	/**
	 * Settings -> Beaver Press.
	 */
	public static function menu() {
		add_options_page( __( 'Beaver Press', 'beaver-press' ), __( 'Beaver Press', 'beaver-press' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * "Translate site" and "Engine settings" links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Translate site', 'beaver-press' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ) . '">' . esc_html__( 'Engine settings', 'beaver-press' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Script and styles on this page only.
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::SLUG !== $hook && true ) {
			return;
		}
		wp_enqueue_style( 'beaver-press-admin', BP_URL . 'assets/css/bp-admin.css', array(), BP_VERSION );
		wp_enqueue_script( 'beaver-press-ins', BP_URL . 'assets/js/bp-instructions.js', array( 'jquery' ), BP_VERSION, true );
		wp_localize_script(
			'beaver-press-ins',
			'BP_INS',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'bp_instructions' ),
				'i18n'  => array(
					'working'     => __( 'Translating...', 'beaver-press' ),
					/* translators: %s: seconds */
					'took'        => __( '%s s', 'beaver-press' ),
					'failed'      => __( 'Request failed. Try again.', 'beaver-press' ),
					'sorting'     => __( 'Asking the AI about every name...', 'beaver-press' ),
					'finding'     => __( 'Looking through the site\'s texts...', 'beaver-press' ),
					'aiKeep'      => __( 'AI: keep', 'beaver-press' ),
					/* translators: %s: language */
					'termsAsking' => __( 'Asking for %s...', 'beaver-press' ),
					'termsDone'   => __( 'Suggestions filled in the empty boxes. Check them, then Save.', 'beaver-press' ),
					'termsNone'   => __( 'Every term already has a translation.', 'beaver-press' ),
					'aiTranslate' => __( 'AI: translate', 'beaver-press' ),
					/* translators: %s: number of names */
					'applied'     => __( '%s AI suggestions applied. Press Save names to keep them.', 'beaver-press' ),
					'noTags'      => __( 'No AI suggestions yet: press Suggest with AI first.', 'beaver-press' ),
				),
			)
		);
		wp_enqueue_script( 'beaver-press-pages', BP_URL . 'assets/js/bp-pages.js', array( 'jquery', 'beaver-press-run' ), BP_VERSION, true );
		wp_enqueue_script( 'beaver-press-run', BP_URL . 'assets/js/bp-run.js', array( 'jquery' ), BP_VERSION, true );
		wp_localize_script(
			'beaver-press-run',
			'BP_RUN',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'bp_run' ),
				'progress' => BP_Run::progress(),
				'i18n'     => array(
					'running'  => __( 'Translating...', 'beaver-press' ),
					'paused'   => __( 'Paused', 'beaver-press' ),
					'done'     => __( 'Finished', 'beaver-press' ),
					'error'    => __( 'Stopped', 'beaver-press' ),
					'idle'     => __( 'Not started', 'beaver-press' ),
					'pages'    => __( 'pages', 'beaver-press' ),
					'strings'  => __( 'strings translated', 'beaver-press' ),
					'failed'   => __( 'Pages that failed to load', 'beaver-press' ),
					'rejected' => __( 'strings kept in the original language after repeated failed checks', 'beaver-press' ),
					'confirm'  => __( 'Cancel the run? Translations already stored are kept.', 'beaver-press' ),
					'network'  => __( 'Lost contact with the site; retrying...', 'beaver-press' ),
					'closed'   => __( 'You can close this page: the run continues in the background, a little slower.', 'beaver-press' ),
					'usage'    => __( 'This run: %1$s requests, %2$s characters sent, %3$s / %4$s tokens', 'beaver-press' ),
					/* translators: 1: page address, 2: language */
					'now'        => __( 'Translating now: %1$s (%2$s)', 'beaver-press' ),
					/* translators: 1: language, 2: pages done, 3: pages queued */
					'perLang'    => __( '%1$s %2$s / %3$s', 'beaver-press' ),
					'added'      => __( 'Added to the run. You can keep working; it continues in the background.', 'beaver-press' ),
					'pick'       => __( 'Tick at least one page and one language.', 'beaver-press' ),
					/* translators: 1: pages shown, 2: all pages */
					'shown'      => __( '%1$s of %2$s pages shown', 'beaver-press' ),
					'complete'   => __( 'complete', 'beaver-press' ),
					/* translators: %s: number of texts */
					'missing'    => __( '%s texts missing', 'beaver-press' ),
					'again'      => __( 'comes back every day: a changing number or date? Wrap it in data-no-translation in the theme.', 'beaver-press' ),
					'openPage'   => __( 'Open the page', 'beaver-press' ),
					'useTranslate' => __( 'Translate on this row finishes it.', 'beaver-press' ),
					'notVisited' => __( 'not visited', 'beaver-press' ),
					'queued'     => __( 'in the run', 'beaver-press' ),
					/* translators: 1: pages checked, 2: pages to check */
					'checking'   => __( 'Checking %1$s / %2$s (nothing is sent to the provider)...', 'beaver-press' ),
					'checked'    => __( 'Progress checked.', 'beaver-press' ),
					/* translators: 1: pages done, 2: pages */
					'preparing'  => __( 'Preparing forms: %1$s / %2$s pages...', 'beaver-press' ),
					'prepared'   => __( 'Forms prepared: messages and confirmation email translated.', 'beaver-press' ),
					/* translators: 1: page number, 2: number of pages */
					'pageOf'     => __( 'Page %1$s of %2$s', 'beaver-press' ),
					'prev'       => __( 'Previous', 'beaver-press' ),
					'next'       => __( 'Next', 'beaver-press' ),
					/* translators: %s: number of pages */
					'cDone'      => __( '%s complete', 'beaver-press' ),
					/* translators: %s: number of pages */
					'cMissing'   => __( '%s with texts missing', 'beaver-press' ),
					/* translators: %s: number of pages */
					'cNone'      => __( '%s not visited', 'beaver-press' ),
					'showAll'    => __( 'Show all', 'beaver-press' ),
				),
			)
		);
	}

	/**
	 * Page.
	 */
	public static function render() {
		$blocker   = BP_Run::blocker();
		$languages = BP_Run::languages();
		$pages     = count( BP_Run::urls() );
		$mt        = get_option( 'trp_machine_translation_settings', array() );
		$mt        = is_array( $mt ) ? $mt : array();
		$engine    = BP_Engine_Settings::current( $mt );
		$preset    = BP_Providers::preset( $engine['provider'] );
		$engine_on = BP_Engine_Settings::ENGINE === ( $mt['translation-engine'] ?? '' );
		$key       = BP_Keys::source( $engine['provider'] );
		$test      = BP_Engine_Settings::cached_test( $engine );
		$tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'pages', 'instructions', 'settings', 'tools' ), true ) ? sanitize_key( $_GET['tab'] ) : 'run'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- view switch only.
		?>
		<div class="wrap bp-page">
			<?php echo self::header_html( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			<?php
			if ( 'instructions' === $tab ) {
				BP_Instructions::render();
				self::render_credit();
				echo '</div>';
				return;
			}
			if ( 'pages' === $tab ) {
				self::render_pages( $languages, $blocker );
				self::render_credit();
				echo '</div>';
				return;
			}
			if ( 'settings' === $tab ) {
				self::render_switches( $mt );
				self::render_costs( $engine );
				BP_Forms::render();
				BP_Slug_Bases::render();
				self::render_credit();
				echo '</div>';
				return;
			}
			if ( 'tools' === $tab ) {
				BP_Backup::render();
				BP_Transfer::render();
				BP_History::render();
				self::render_credit();
				echo '</div>';
				return;
			}
			// Overview (3.0.0): figures, a languages table, the run as facts, health in plain words.
			$bp_counts = BP_Complete::counts();
			$bp_done   = 0;
			$bp_seen   = 0;
			$bp_left   = 0;
			$bp_cost   = 0.0;
			$bp_priced = true;
			$bp_est    = array();
			foreach ( $languages as $bp_code => $bp_name ) {
				$bp_done            += (int) ( $bp_counts[ $bp_code ][0] ?? 0 );
				$bp_seen            += (int) ( $bp_counts[ $bp_code ][0] ?? 0 ) + (int) ( $bp_counts[ $bp_code ][1] ?? 0 );
				$bp_est[ $bp_code ]  = BP_Usage::estimate( $bp_code, $engine );
				$bp_left            += (int) $bp_est[ $bp_code ]['strings'];
				if ( null === $bp_est[ $bp_code ]['cost'] ) {
					$bp_priced = false;
				} else {
					$bp_cost += (float) $bp_est[ $bp_code ]['cost'];
				}
			}
			$bp_spent  = BP_Budget::spent();
			$bp_limits = BP_Budget::limits();
			$bp_pct    = $bp_seen ? (int) floor( 100 * $bp_done / $bp_seen ) : 0;
			?>

			<div class="bp-card bp-card--flush bp-figures">
				<div class="bp-fig">
					<span class="bp-label"><?php esc_html_e( 'Languages', 'beaver-press' ); ?></span>
					<span class="bp-fig__num"><?php echo esc_html( number_format_i18n( count( $languages ) ) ); ?></span>
					<span class="bp-fig__sub"><?php echo esc_html( implode( ' · ', array_map( static fn( $c ) => strtoupper( substr( (string) $c, 0, 2 ) ), array_keys( $languages ) ) ) ); ?></span>
				</div>
				<div class="bp-fig">
					<span class="bp-label"><?php esc_html_e( 'Pages complete', 'beaver-press' ); ?></span>
					<span class="bp-fig__num"><?php echo esc_html( number_format_i18n( $bp_done ) ); ?> <small>/ <?php echo esc_html( number_format_i18n( $bp_seen ) ); ?></small></span>
					<span class="bp-fig__sub"><?php echo esc_html( sprintf( /* translators: %s: percentage */ __( '%s%% of checked pages', 'beaver-press' ), number_format_i18n( $bp_pct ) ) ); ?></span>
				</div>
				<div class="bp-fig">
					<span class="bp-label"><?php esc_html_e( 'Texts left', 'beaver-press' ); ?></span>
					<span class="bp-fig__num"><?php echo esc_html( number_format_i18n( $bp_left ) ); ?></span>
					<span class="bp-fig__sub"><?php echo esc_html( $bp_priced && $bp_left ? sprintf( /* translators: %s: cost */ __( 'About %s to finish', 'beaver-press' ), self::money( $bp_cost ) ) : ( $bp_left ? __( 'Enter the model price for a cost', 'beaver-press' ) : __( 'Nothing waiting', 'beaver-press' ) ) ); ?></span>
				</div>
				<div class="bp-fig">
					<span class="bp-label"><?php esc_html_e( 'Spent this month', 'beaver-press' ); ?></span>
					<span class="bp-fig__num"><?php echo esc_html( self::money( (float) $bp_spent['month'] ) ); ?></span>
					<span class="bp-fig__sub">
						<?php
						if ( $bp_limits['month'] > 0 || $bp_limits['day'] > 0 ) {
							echo esc_html( $bp_limits['month'] > 0 ? sprintf( /* translators: %s: monthly limit */ __( 'Limit %s a month', 'beaver-press' ), self::money( (float) $bp_limits['month'] ) ) : sprintf( /* translators: %s: daily limit */ __( 'Limit %s a day', 'beaver-press' ), self::money( (float) $bp_limits['day'] ) ) );
						} else {
							esc_html_e( 'No limit set', 'beaver-press' );
						}
						?>
						· <a href="<?php echo esc_url( self::tab_url( 'settings', 'bp-costs' ) ); ?>"><?php esc_html_e( 'Limits', 'beaver-press' ); ?></a>
					</span>
				</div>
			</div>

			<div class="bp-grid2">
				<div class="bp-card bp-card--flush bp-langrows">
					<div class="bp-card__head"><h2><?php esc_html_e( 'Languages', 'beaver-press' ); ?></h2><span class="bp-card__meta"><?php esc_html_e( 'A page goes live in a language when every text on it is translated.', 'beaver-press' ); ?></span></div>
					<?php if ( ! $languages ) : ?>
						<p class="bp-card__body"><?php esc_html_e( 'No second language yet: add one under Languages.', 'beaver-press' ); ?></p>
					<?php else : ?>
					<table class="bp-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Language', 'beaver-press' ); ?></th><th scope="col"><?php esc_html_e( 'Complete pages', 'beaver-press' ); ?></th><th scope="col" class="bp-num"><?php esc_html_e( 'Texts left', 'beaver-press' ); ?></th><th scope="col" class="bp-num"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'beaver-press' ); ?></span></th></tr></thead>
						<tbody>
						<?php foreach ( $languages as $bp_code => $bp_name ) : ?>
							<?php
							$bp_d = (int) ( $bp_counts[ $bp_code ][0] ?? 0 );
							$bp_s = $bp_d + (int) ( $bp_counts[ $bp_code ][1] ?? 0 );
							$bp_p = $bp_s ? (int) floor( 100 * $bp_d / $bp_s ) : 0;
							?>
							<tr class="bp-langrow">
								<td><strong><?php echo esc_html( $bp_name ); ?></strong> <span class="bp-code"><?php echo esc_html( strtolower( substr( (string) $bp_code, 0, 2 ) ) ); ?></span></td>
								<td>
									<span class="bp-meter" aria-hidden="true"><span style="width:<?php echo (int) $bp_p; ?>%"></span></span>
									<?php echo esc_html( number_format_i18n( $bp_d ) . ' / ' . number_format_i18n( $bp_s ) ); ?>
									<?php if ( $bp_s && $bp_d === $bp_s ) : ?>
										<span class="bp-badge is-ok"><?php esc_html_e( 'Complete', 'beaver-press' ); ?></span>
									<?php elseif ( $bp_s ) : ?>
										<span class="bp-badge is-warn"><?php echo esc_html( sprintf( /* translators: %s: pages */ __( '%s pending', 'beaver-press' ), number_format_i18n( $bp_s - $bp_d ) ) ); ?></span>
									<?php else : ?>
										<span class="bp-badge"><?php esc_html_e( 'Not started', 'beaver-press' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="bp-num"><?php echo esc_html( number_format_i18n( (int) $bp_est[ $bp_code ]['strings'] ) ); ?></td>
								<td class="bp-num"><button type="button" class="button-link bp-langrow__go" data-lang="<?php echo esc_attr( $bp_code ); ?>" <?php disabled( '' !== $blocker ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language */ __( 'Translate %s', 'beaver-press' ), $bp_name ) ); ?>"><?php esc_html_e( 'Translate', 'beaver-press' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>

				<div class="bp-col">
					<div class="bp-card bp-card--flush" id="bp-run">
						<div class="bp-card__head"><h2><?php esc_html_e( 'Translate site', 'beaver-press' ); ?></h2><span class="bp-card__meta"><?php echo esc_html( sprintf( /* translators: %d: number of pages */ _n( '%d public page, once per language', '%d public pages, once per language', $pages, 'beaver-press' ), $pages ) ); ?></span></div>
						<div class="bp-card__body">
							<?php if ( '' !== $blocker ) : ?>
								<p class="bp-status bp-status--error"><?php echo esc_html( $blocker ); ?></p>
							<?php endif; ?>
							<fieldset class="bp-langs">
								<legend class="bp-label"><?php esc_html_e( 'Languages', 'beaver-press' ); ?></legend>
								<?php foreach ( $languages as $code => $name ) : ?>
									<label><input type="checkbox" name="bp_languages[]" value="<?php echo esc_attr( $code ); ?>" checked /> <?php echo esc_html( $name ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<dl class="bp-kv bp-estimate-sum">
								<dt><?php esc_html_e( 'Texts to send', 'beaver-press' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $bp_left ) ); ?></dd>
								<dt><?php esc_html_e( 'Requests', 'beaver-press' ); ?></dt><dd><?php echo esc_html( number_format_i18n( array_sum( array_map( static fn( $e ) => (int) $e['requests'], $bp_est ) ) ) ); ?></dd>
								<dt><?php esc_html_e( 'Estimated cost', 'beaver-press' ); ?></dt><dd><?php echo esc_html( $bp_priced ? '~' . self::money( $bp_cost ) : '-' ); ?></dd>
								<dt><label for="bp-run-budget"><?php esc_html_e( 'Character cap for this run', 'beaver-press' ); ?></label></dt>
								<dd class="bp-budget"><input type="number" id="bp-run-budget" min="0" step="10000" placeholder="<?php esc_attr_e( 'None', 'beaver-press' ); ?>" aria-describedby="bp-run-budget-help" /></dd>
							</dl>
							<p class="bp-help" id="bp-run-budget-help"><?php esc_html_e( 'Text already translated is never sent again. The cap is optional; Resume continues past it.', 'beaver-press' ); ?></p>
							<details class="bp-estimate-more">
								<summary><?php esc_html_e( 'Per language and tokens', 'beaver-press' ); ?></summary>
								<?php self::render_estimate( $languages, $engine, $preset ); ?>
							</details>
							<div class="bp-progress" aria-live="polite">
								<p class="bp-progress__status"></p>
								<div class="bp-bar"><span></span></div>
								<p class="bp-progress__pages"></p>
								<ul class="bp-progress__strings"></ul>
								<p class="bp-progress__usage"></p>
								<p class="bp-progress__message"></p>
								<details class="bp-progress__failed" hidden><summary></summary><ul></ul></details>
							</div>
						</div>
						<div class="bp-card__foot bp-actions">
							<a class="bp-foot__link" href="<?php echo esc_url( self::tab_url( 'pages' ) ); ?>"><?php esc_html_e( 'Choose pages', 'beaver-press' ); ?></a>
							<button type="button" class="button-link button-link-delete" id="bp-run-cancel"><?php esc_html_e( 'Cancel run', 'beaver-press' ); ?></button>
							<button type="button" class="button" id="bp-run-pause"><?php esc_html_e( 'Pause', 'beaver-press' ); ?></button>
							<button type="button" class="button" id="bp-run-resume"><?php esc_html_e( 'Resume', 'beaver-press' ); ?></button>
							<button type="button" class="button button-primary" id="bp-run-start" <?php disabled( '' !== $blocker ); ?>><?php esc_html_e( 'Translate site', 'beaver-press' ); ?></button>
						</div>
					</div>

					<div class="bp-card bp-card--flush">
						<?php
						$bp_started = BP_Auto::started_languages();
						$bp_new     = array_diff_key( $languages, array_flip( $bp_started ) );
						$bp_checks  = array(
							array( $engine_on, $engine_on ? __( 'Automatic translation on', 'beaver-press' ) : __( 'Automatic translation off: switch it on under Languages, Automatic translation', 'beaver-press' ) ),
							/* translators: %s: provider name */
							array( (bool) $key, $key ? sprintf( __( '%s key saved', 'beaver-press' ), $preset['label'] ) : sprintf( __( 'No %s key saved', 'beaver-press' ), $preset['label'] ) ),
							array( $test ? ( $test['ok'] ? true : false ) : null, $test ? $test['message'] : __( 'Connection not tested yet', 'beaver-press' ) ),
							array( BP_Guard::enabled( $mt ) ? true : null, BP_Guard::enabled( $mt ) ? __( 'Visitor guard on: visitors never cause paid requests', 'beaver-press' ) : __( 'Visitor guard off: any page view may send new text to the provider', 'beaver-press' ) ),
							/* translators: %s: language names */
							array( ! $bp_new, $bp_new ? sprintf( __( 'Not translated yet: %s', 'beaver-press' ), implode( ', ', $bp_new ) ) : __( 'Every language has translations', 'beaver-press' ) ),
							array( true, BP_Topup::status() ),
						);
						$bp_bad = count( array_filter( $bp_checks, static fn( $c ) => false === $c[0] ) );
						?>
						<div class="bp-card__head"><h2><?php esc_html_e( 'Health', 'beaver-press' ); ?></h2><span class="bp-card__meta"><?php echo esc_html( $bp_bad ? sprintf( /* translators: %s: number of problems */ _n( '%s thing to fix', '%s things to fix', $bp_bad, 'beaver-press' ), number_format_i18n( $bp_bad ) ) : __( 'All good', 'beaver-press' ) ); ?></span><a class="bp-card__act" href="<?php echo esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ); ?>"><?php esc_html_e( 'Engine settings', 'beaver-press' ); ?></a></div>
						<ul class="bp-checks bp-card__body">
							<?php foreach ( $bp_checks as list( $bp_ok, $bp_text ) ) : ?>
								<li class="<?php echo true === $bp_ok ? 'is-ok' : ( false === $bp_ok ? 'is-no' : 'is-wait' ); ?>"><?php echo esc_html( $bp_text ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>

			<?php self::render_usage(); ?>
			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * A Beaver Press tab's address.
	 *
	 * @since 2.7.0
	 *
	 * @param string $tab    run | pages | instructions | settings | tools.
	 * @param string $anchor Card id, without #.
	 * @return string
	 */
	public static function tab_url( $tab = 'run', $anchor = '' ) {
		$url = admin_url( 'options-general.php?page=' . self::SLUG . ( 'run' === $tab ? '' : '&tab=' . $tab ) );
		return '' === $anchor ? $url : $url . '#' . $anchor;
	}

	/**
	 * Settings tab: one row per switch, its effect in one line, the details behind "More".
	 *
	 * @since 2.7.0
	 *
	 * @param array $mt Engine settings.
	 */
	private static function render_switches( array $mt ) {
		$counts = BP_Complete::counts();
		$parts  = array();
		foreach ( BP_Run::languages() as $code => $name ) {
			if ( isset( $counts[ $code ] ) ) {
				/* translators: 1: language, 2: complete pages, 3: pages seen */
				$parts[] = sprintf( __( '%1$s %2$s of %3$s pages complete', 'beaver-press' ), $name, number_format_i18n( $counts[ $code ][0] ), number_format_i18n( $counts[ $code ][0] + $counts[ $code ][1] ) );
			}
		}
		/* translators: %s: number of pages */
		$ready  = sprintf( _n( '%s page ready', '%s pages ready', BP_Cache::count(), 'beaver-press' ), number_format_i18n( BP_Cache::count() ) );
		$clear  = ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bp_cache_clear' ), 'bp_cache_clear' ) ) . '">' . esc_html__( 'Clear', 'beaver-press' ) . '</a>';
		$rows   = array(
			array( 'auto', BP_Auto::enabled(), __( 'Translate updated content automatically', 'beaver-press' ), __( 'A page, post, tour, destination or stay you publish or update is translated in the background about a minute later.', 'beaver-press' ), __( 'Only in the languages already started; only new or changed text is sent.', 'beaver-press' ), esc_html( BP_Auto::status() ) ),
			array( 'topup', BP_Topup::enabled(), __( 'Finish almost-translated pages once a day', 'beaver-press' ), __( 'Pages that lost their complete state without an edit (a new text in a widget, menu or theme box) are finished at night.', 'beaver-press' ), __( 'Only the missing texts are sent, each page is tried once a day, and the daily limit applies.', 'beaver-press' ), esc_html( BP_Topup::status() ) ),
			array( 'alerts', BP_Alerts::email_enabled(), __( 'Email me when translation stops', 'beaver-press' ), __( 'An email to the site administrator when the provider refuses the key or has no credit left.', 'beaver-press' ), '', '' ),
			array( 'cache', BP_Cache::enabled(), __( 'Keep finished translated pages ready (faster)', 'beaver-press' ), __( 'A translated page with every text translated is kept ready for up to 6 hours and served without building it again.', 'beaver-press' ), __( 'Any edit or translation change starts afresh. Visitors also get the page in the other languages fetched the moment they reach for the language switcher.', 'beaver-press' ), esc_html( $ready ) . $clear ),
			array( 'slugs', BP_Slugs::enabled(), __( 'Automatically translate slugs (page addresses)', 'beaver-press' ), __( 'Pages and archives get an address in each language (/fr/a-propos/), drafted from the translated title.', 'beaver-press' ), __( 'Editable on each edit screen ("Translated addresses"); the old address in that language moves there with a 301. Post-type bases such as /tours/ stay (see Address prefixes below); the original language is never changed.', 'beaver-press' ), '' ),
			array( 'complete', BP_Complete::enabled(), __( 'Show a translated page only when it is complete', 'beaver-press' ), __( 'Until every text of a page is translated, visitors in that language get the original page, marked noindex, instead of a mix of two languages.', 'beaver-press' ), __( 'The page has a short "not available in Français yet" note and is left out of the hreflang links and the language suggestion until complete. You and translators still see the translated page, and the Translate-site run completes it.', 'beaver-press' ), esc_html( implode( ' · ', $parts ) ) ),
			array( 'suggest', BP_Suggest::enabled(), __( 'Suggest the visitor\'s language', 'beaver-press' ), __( 'A visitor whose browser prefers another language offered here sees "View this page in Français?" in that language, once.', 'beaver-press' ), __( 'The answer, or a language picked in the switcher, is remembered for a year.', 'beaver-press' ), '' ),
			array( 'redirect', BP_Suggest::redirect_enabled(), __( 'Send first-time visitors to their language automatically', 'beaver-press' ), __( 'First-time visitors on the original pages go straight to their language instead of being asked.', 'beaver-press' ), __( 'Search engines and logged-in users are never sent, but automatic redirects can still make search engines index fewer original pages. Off by default.', 'beaver-press' ), '' ),
			array( 'quiet', BP_Quiet::enabled(), __( 'Hide the engine\'s extras', 'beaver-press' ), __( 'Hides support chat, review requests, sales notices, the Addons and AI tabs and upgrade boxes.', 'beaver-press' ), __( 'Languages, the switcher, the visual editor and error notices stay.', 'beaver-press' ), '' ),
		);
		?>
		<div class="bp-card bp-card--flush" id="bp-switches">
			<div class="bp-card__head"><h2><?php esc_html_e( 'Switches', 'beaver-press' ); ?></h2><span class="bp-card__meta"><?php esc_html_e( 'How translation runs and what visitors see', 'beaver-press' ); ?></span></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-switches">
				<input type="hidden" name="action" value="bp_quiet" />
				<?php wp_nonce_field( 'bp_quiet' ); ?>
				<?php
				// 2.9.0: grouped by what they affect, as toggle cards (still plain checkboxes).
				$groups = array(
					__( 'Translation', 'beaver-press' )        => array( 'auto', 'topup', 'complete', 'slugs' ),
					__( 'Visitors', 'beaver-press' )           => array( 'suggest', 'redirect', 'cache' ),
					__( 'Alerts and extras', 'beaver-press' ) => array( 'alerts', 'quiet' ),
				);
				$by_name = array();
				foreach ( $rows as $row ) {
					$by_name[ $row[0] ] = $row;
				}
				foreach ( $groups as $group => $names ) :
					?>
					<h3 class="bp-section-title"><?php echo esc_html( $group ); ?></h3>
					<div class="bp-toggles">
					<?php
					foreach ( $names as $n ) :
						list( $name, $on, $label, $short, $long, $state ) = $by_name[ $n ];
						?>
						<div class="bp-switch">
							<input type="checkbox" class="bp-toggle" id="bp-sw-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="yes" <?php checked( $on ); ?> />
							<div>
								<label for="bp-sw-<?php echo esc_attr( $name ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
								<p class="description"><?php echo esc_html( $short ); ?></p>
								<?php if ( '' !== $state ) : ?>
									<p class="bp-switch__state"><?php echo wp_kses( $state, array( 'a' => array( 'href' => true ) ) ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $long ) : ?>
									<details class="bp-switch__more"><summary><?php esc_html_e( 'More', 'beaver-press' ); ?></summary><p class="description"><?php echo esc_html( $long ); ?></p></details>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<div class="bp-card__foot"><button class="button button-primary"><?php esc_html_e( 'Save switches', 'beaver-press' ); ?></button></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings tab: the model's price and the spending limits, saved together.
	 *
	 * @since 2.7.0
	 *
	 * @param array $engine Engine settings.
	 */
	private static function render_costs( array $engine ) {
		$deepl = 'deepl' === $engine['api'];
		$price = $deepl ? null : BP_Usage::price( $engine['model'] );
		?>
		<div class="bp-card bp-card--flush" id="bp-costs">
			<div class="bp-card__head"><h2><?php esc_html_e( 'Costs and limits', 'beaver-press' ); ?></h2><span class="bp-card__meta"><?php esc_html_e( 'US dollars', 'beaver-press' ); ?></span></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bp_costs" />
				<?php wp_nonce_field( 'bp_costs' ); ?>
				<?php if ( ! $deepl ) : ?>
					<input type="hidden" name="model" value="<?php echo esc_attr( $engine['model'] ); ?>" />
					<div class="bp-row">
						<div class="bp-row__label"><span class="bp-row__title"><?php esc_html_e( 'Model price', 'beaver-press' ); ?></span>
							<p class="bp-help"><?php echo esc_html( sprintf( /* translators: %s: model */ __( '%s, per million tokens, from the provider\'s price page. Used for estimates, usage and the spending limit. Empty both to remove.', 'beaver-press' ), $engine['model'] ) ); ?></p></div>
						<div class="bp-row__control bp-price">
							<label class="bp-affix"><span><?php esc_html_e( 'In $', 'beaver-press' ); ?></span><input type="number" name="price_in" min="0" step="0.001" value="<?php echo esc_attr( null !== $price ? (string) $price[0] : '' ); ?>" class="small-text" /></label>
							<label class="bp-affix"><span><?php esc_html_e( 'Out $', 'beaver-press' ); ?></span><input type="number" name="price_out" min="0" step="0.001" value="<?php echo esc_attr( null !== $price ? (string) $price[1] : '' ); ?>" class="small-text" /></label>
						</div>
					</div>
				<?php endif; ?>
				<div class="bp-row">
					<div class="bp-row__label"><span class="bp-row__title"><?php esc_html_e( 'Spending limit', 'beaver-press' ); ?></span>
						<p class="bp-help"><?php esc_html_e( 'Translation stops for the rest of the day or month. 0 = no limit.', 'beaver-press' ); ?></p></div>
					<div class="bp-row__control"><?php BP_Budget::render( $engine, false ); ?></div>
				</div>
				<div class="bp-card__foot"><button class="button button-primary"><?php esc_html_e( 'Save costs and limits', 'beaver-press' ); ?></button></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the price and the spending limits (Costs and limits card).
	 *
	 * @since 2.7.0
	 */
	public static function save_costs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_costs' );
		if ( isset( $_POST['model'] ) ) {
			BP_Usage::set_price(
				sanitize_text_field( wp_unslash( $_POST['model'] ) ),
				(float) ( $_POST['price_in'] ?? 0 ),
				(float) ( $_POST['price_out'] ?? 0 )
			);
		}
		BP_Budget::store( (float) ( $_POST['budget_day'] ?? 0 ), (float) ( $_POST['budget_month'] ?? 0 ) );
		wp_safe_redirect( self::tab_url( 'settings', 'bp-costs' ) );
		exit;
	}

	/**
	 * Pages tab: every public page with its state per language; choose pages to translate.
	 *
	 * @param array  $languages Code => name.
	 * @param string $blocker   Why a run cannot start, or ''.
	 */
	private static function render_pages( array $languages, $blocker ) {
		$pages = BP_Run::pages();
		$map   = BP_Complete::map();
		$texts = BP_Complete::texts_map();
		$types = array_values( array_unique( wp_list_pluck( $pages, 'type' ) ) );
		sort( $types );
		?>
		<div class="bp-card bp-pages" id="bp-pages">
			<h2><?php esc_html_e( 'Pages', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each page per language: complete, texts missing, or not visited yet. Tick pages and languages, then Translate.', 'beaver-press' ); ?></p>
			<?php if ( '' !== $blocker ) : ?>
				<p class="bp-status bp-status--error"><?php echo esc_html( $blocker ); ?></p>
			<?php endif; ?>
			<div class="bp-pages__now" aria-live="polite"></div>
			<div class="bp-pages__tools">
				<input type="search" class="bp-pages__search" placeholder="<?php esc_attr_e( 'Search pages', 'beaver-press' ); ?>" />
				<select class="bp-pages__type">
					<option value=""><?php esc_html_e( 'All types', 'beaver-press' ); ?></option>
					<?php foreach ( $types as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
				<select class="bp-pages__state">
					<option value=""><?php esc_html_e( 'Any state', 'beaver-press' ); ?></option>
					<option value="missing"><?php esc_html_e( 'Texts missing or not visited', 'beaver-press' ); ?></option>
					<option value="complete"><?php esc_html_e( 'Complete in every language', 'beaver-press' ); ?></option>
				</select>
				<span class="bp-pages__count"></span>
			</div>
			<div class="bp-pages__bulk">
				<span><?php esc_html_e( 'Languages:', 'beaver-press' ); ?></span>
				<?php foreach ( $languages as $code => $name ) : ?>
					<label><input type="checkbox" class="bp-pages__lang" value="<?php echo esc_attr( $code ); ?>" checked /> <?php echo esc_html( $name ); ?></label>
				<?php endforeach; ?>
				<button type="button" class="button button-primary bp-pages__go" <?php disabled( '' !== $blocker ); ?>><?php esc_html_e( 'Translate ticked pages', 'beaver-press' ); ?></button>
				<button type="button" class="button bp-pages__draft" <?php disabled( ! BP_Slugs::enabled() ); ?> title="<?php esc_attr_e( 'Drafts translated addresses for the ticked pages from their translated titles (asks the engine for titles not translated yet).', 'beaver-press' ); ?>"><?php esc_html_e( 'Draft addresses', 'beaver-press' ); ?></button>
				<button type="button" class="button bp-pages__check" title="<?php esc_attr_e( 'Opens the ticked pages (or all shown) in each ticked language as a visitor to count what is still missing. Sends nothing to the provider.', 'beaver-press' ); ?>"><?php esc_html_e( 'Check progress (free)', 'beaver-press' ); ?></button>
				<span class="bp-pages__msg" aria-live="polite"></span>
			</div>
			<div class="bp-pages__counts"></div>
			<div class="bp-scroll">
			<table class="widefat striped bp-pages__table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="bp-pages__all" aria-label="<?php esc_attr_e( 'Tick all shown', 'beaver-press' ); ?>" /></td>
						<th scope="col"><?php esc_html_e( 'Page', 'beaver-press' ); ?></th>
						<?php foreach ( $languages as $code => $name ) : ?>
							<th scope="col" class="bp-pages__lcol"><?php echo esc_html( $name ); ?></th>
						<?php endforeach; ?>
						<th scope="col" class="bp-pages__acol"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pages as $p ) : ?>
						<tr data-url="<?php echo esc_attr( $p['url'] ); ?>" data-key="<?php echo esc_attr( $p['key'] ); ?>" data-type="<?php echo esc_attr( $p['type'] ); ?>" data-title="<?php echo esc_attr( strtolower( $p['title'] ) ); ?>">
							<th scope="row" class="check-column"><input type="checkbox" class="bp-pages__pick" aria-label="<?php echo esc_attr( $p['title'] ); ?>" /></th>
							<td><a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['title'] ); ?></a> <span class="bp-pages__type-label"><?php echo esc_html( $p['type'] ); ?></span></td>
							<?php foreach ( $languages as $code => $name ) : ?>
								<td class="bp-pages__cell" data-lang="<?php echo esc_attr( $code ); ?>" data-href="<?php echo esc_url( BP_Run::url_in( $p['url'], $code ) ); ?>"><?php echo wp_kses_post( self::page_cell( $map[ $code ][ $p['key'] ] ?? null, BP_Run::url_in( $p['url'], $code ), $texts[ $code ][ $p['key'] ] ?? array() ) ); ?></td>
							<?php endforeach; ?>
							<td class="bp-pages__acol">
								<button type="button" class="button button-small bp-pages__one"><?php esc_html_e( 'Translate', 'beaver-press' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<div class="bp-pages__pager"></div>
		</div>
		<?php BP_Provenance::render(); ?>
		<?php
	}

	/**
	 * One language cell of the Pages table.
	 *
	 * @param int|null $missing Texts missing (null: not visited in that language yet).
	 * @param string   $url     Page in that language.
	 * @param array    $texts   Missing texts: [ text, keeps coming back ] (see BP_Complete::texts_map()).
	 * @return string HTML.
	 */
	public static function page_cell( $missing, $url, array $texts = array() ) {
		if ( null === $missing ) {
			return '<span class="bp-cell bp-cell--none">' . esc_html__( 'not visited', 'beaver-press' ) . '</span>';
		}
		if ( 0 === (int) $missing ) {
			return '<a class="bp-cell bp-cell--done" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">&#10003; ' . esc_html__( 'complete', 'beaver-press' ) . '</a>';
		}
		/* translators: %s: number of texts */
		$label = sprintf( _n( '%s text missing', '%s texts missing', (int) $missing, 'beaver-press' ), number_format_i18n( (int) $missing ) );
		if ( ! $texts ) {
			return '<a class="bp-cell bp-cell--missing" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
		}
		$items = '';
		foreach ( $texts as $row ) {
			$items .= '<li>' . esc_html( (string) $row[0] ) . ( empty( $row[1] ) ? '' : ' <span class="bp-miss__again">' . esc_html__( 'comes back every day: a changing number or date? Wrap it in data-no-translation in the theme.', 'beaver-press' ) . '</span>' ) . '</li>';
		}
		return '<details class="bp-miss"><summary class="bp-cell bp-cell--missing">' . esc_html( $label ) . '</summary><ul>' . $items . '</ul>'
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the page', 'beaver-press' ) . '</a> · ' . esc_html__( 'Translate on this row finishes it.', 'beaver-press' ) . '</details>';
	}

	/**
	 * What is left to translate per language and what it would roughly cost.
	 *
	 * @param array $languages Code => name.
	 * @param array $engine    Engine settings.
	 * @param array $preset    Provider preset.
	 */
	private static function render_estimate( array $languages, array $engine, array $preset ) {
		if ( ! $languages ) {
			return;
		}
		$deepl = 'deepl' === $engine['api'];
		?>
		<table class="widefat striped bp-estimate">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Language', 'beaver-press' ); ?></th>
					<th><?php esc_html_e( 'Texts left', 'beaver-press' ); ?></th>
					<th><?php esc_html_e( 'Characters', 'beaver-press' ); ?></th>
					<th><?php esc_html_e( 'Requests', 'beaver-press' ); ?></th>
					<?php if ( ! $deepl ) : ?>
						<th><?php esc_html_e( 'Tokens in / out (approx.)', 'beaver-press' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Cost (approx.)', 'beaver-press' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $languages as $code => $name ) : ?>
					<?php $e = BP_Usage::estimate( $code, $engine ); ?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $e['strings'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $e['chars'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $e['requests'] ) ); ?></td>
						<?php if ( ! $deepl ) : ?>
							<td><?php echo esc_html( number_format_i18n( $e['in'] ) . ' / ' . number_format_i18n( $e['out'] ) ); ?></td>
						<?php endif; ?>
						<td>
							<?php
							if ( $deepl ) {
								/* translators: %s: percentage */
								echo esc_html( sprintf( __( '%s%% of DeepL API Free\'s 500,000 a month', 'beaver-press' ), number_format_i18n( $e['chars'] / 5000, 1 ) ) );
							} elseif ( null !== $e['cost'] ) {
								echo esc_html( self::money( $e['cost'] ) );
							} else {
								/* translators: %s: provider name */
								echo esc_html( sprintf( __( 'see %s prices', 'beaver-press' ), $preset['label'] ) );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: model or DeepL */
					__( 'For %s, counting the texts already seen on the site; pages never visited may add more. Thinking tokens some models add are not included. Check the provider\'s current prices before large runs.', 'beaver-press' ),
					$deepl ? 'DeepL' : $engine['model']
				)
			);
			?>
		</p>
		<p class="bp-more-link"><a href="<?php echo esc_url( self::tab_url( 'settings', 'bp-costs' ) ); ?>"><?php esc_html_e( 'Model price and spending limits: Settings', 'beaver-press' ); ?></a></p>
		<?php
	}

	/**
	 * Save a model price.
	 */
	public static function save_price() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_price' );
		BP_Usage::set_price(
			sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
			(float) ( $_POST['price_in'] ?? 0 ),
			(float) ( $_POST['price_out'] ?? 0 )
		);
		wp_safe_redirect( self::tab_url( 'settings', 'bp-costs' ) );
		exit;
	}

	/**
	 * TranslatePress's daily limit and the last 7 days of usage.
	 */
	private static function render_usage() {
		$daily = BP_Usage::daily_limit();
		$days  = array_slice( BP_Usage::days(), 0, 7, true );
		?>
		<div class="bp-card">
			<h2><?php esc_html_e( 'Usage', 'beaver-press' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: characters used today, 2: daily limit */
						__( 'Daily limit: %1$s of %2$s characters used today. Runs pause when it is reached.', 'beaver-press' ),
						number_format_i18n( $daily['used'] ),
						number_format_i18n( $daily['limit'] )
					)
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ); ?>"><?php esc_html_e( 'Change the limit', 'beaver-press' ); ?></a>
			</p>
			<?php if ( $days ) : ?>
				<table class="widefat striped bp-days">
					<thead><tr><th><?php esc_html_e( 'Day (UTC)', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Requests', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Characters sent', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Tokens in / out', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Cost (known prices)', 'beaver-press' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $days as $day => $row ) : ?>
							<tr>
								<td><?php echo esc_html( $day ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['chars'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['in'] ) . ' / ' . number_format_i18n( $row['out'] ) ); ?></td>
								<td><?php echo esc_html( $row['cost'] > 0 ? self::money( (float) $row['cost'] ) : '-' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Nothing sent to a provider yet.', 'beaver-press' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The maker's mark at the foot of Beaver Press screens (same as the other Digital Beaver plugins).
	 */
	public static function render_credit() {
		?>
		<p class="bp-credit">
			<?php esc_html_e( 'Beaver Press by Digital Beaver', 'beaver-press' ); ?> ·
			<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
		</p>
		<?php
	}

	/**
	 * Beaver Press title and tabs (also shown on the Languages screens).
	 *
	 * @param string $active run | pages | instructions | languages.
	 * @return string HTML.
	 */
	public static function header_html( $active ) {
		$tabs = array(
			'run'          => array( __( 'Overview', 'beaver-press' ), self::tab_url(), 'grid' ),
			'pages'        => array( __( 'Pages', 'beaver-press' ), self::tab_url( 'pages' ), 'file' ),
			'instructions' => array( __( 'Instructions', 'beaver-press' ), self::tab_url( 'instructions' ), 'pen' ),
			'settings'     => array( __( 'Settings', 'beaver-press' ), self::tab_url( 'settings' ), 'gear' ),
			'tools'        => array( __( 'Tools', 'beaver-press' ), self::tab_url( 'tools' ), 'wrench' ),
			'languages'    => array( __( 'Languages', 'beaver-press' ), admin_url( 'options-general.php?page=translate-press' ), 'globe' ),
		);
		// Provider state from the last connection test (nothing is sent from here).
		$mt       = get_option( 'trp_machine_translation_settings', array() );
		$engine   = BP_Engine_Settings::current( is_array( $mt ) ? $mt : array() );
		$provider = (string) ( BP_Providers::preset( $engine['provider'] )['label'] ?? $engine['provider'] );
		$test     = BP_Engine_Settings::cached_test( $engine );
		$state    = $test ? ( $test['ok'] ? 'is-ok' : 'is-err' ) : '';
		if ( ! $test ) {
			/* translators: %s: provider name */
			$pill = sprintf( __( '%s not tested', 'beaver-press' ), $provider );
		} elseif ( $test['ok'] ) {
			/* translators: %s: provider name */
			$pill = sprintf( __( '%s connected', 'beaver-press' ), $provider );
		} else {
			/* translators: %s: provider name */
			$pill = sprintf( __( '%s not working', 'beaver-press' ), $provider );
		}

		// 3.0.0: title, version, provider state and the main action in one quiet row; plain tabs.
		$html  = '<div class="bp-head">';
		$html .= '<h1 class="bp-title">' . esc_html__( 'Beaver Press', 'beaver-press' ) . '</h1><span class="bp-title__version">' . esc_html( BP_VERSION ) . '</span>';
		$html .= '<div class="bp-head__right"><a class="bp-pill ' . esc_attr( $state ) . '" href="' . esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ) . '">' . esc_html( $pill ) . '</a>';
		$html .= '<a class="button button-primary bp-head__go" href="' . esc_url( self::tab_url( 'run', 'bp-run' ) ) . '">' . esc_html__( 'Translate site', 'beaver-press' ) . '</a></div>';
		$html .= '</div>';
		// WordPress puts admin notices here (after this marker) instead of inside the header row.
		$html .= '<hr class="wp-header-end">';
		$html .= '<h2 class="nav-tab-wrapper bp-tabs">';
		foreach ( $tabs as $id => list( $label, $url ) ) {
			$html .= '<a class="nav-tab' . ( $id === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $id === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $html . '</h2>';
	}

	/**
	 * A small line icon (inline SVG, decorative).
	 *
	 * @since 2.9.0
	 *
	 * @param string $name Icon name.
	 * @return string SVG.
	 */
	public static function icon( $name ) {
		$paths = array(
			'grid'   => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
			'file'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
			'pen'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
			'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
			'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z"/>',
			'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
			'text'   => '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
			'coin'   => '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
			'play'   => '<path d="M6 4l14 8-14 8z"/>',
		);
		return '<svg class="bp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * The maker's credit as HTML.
	 *
	 * @return string
	 */
	public static function credit_html() {
		ob_start();
		self::render_credit();
		return (string) ob_get_clean();
	}

	/**
	 * "$1.23", or "< $0.01".
	 *
	 * @param float $amount USD.
	 * @return string
	 */
	public static function money( $amount ) {
		return $amount > 0 && $amount < 0.01 ? '< $0.01' : '$' . number_format_i18n( $amount, 2 );
	}
}
