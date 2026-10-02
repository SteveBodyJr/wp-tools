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
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
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
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
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
		$tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'pages', 'instructions' ), true ) ? sanitize_key( $_GET['tab'] ) : 'run'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- view switch only.
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
			?>

			<div class="bp-card">
				<h2><?php esc_html_e( 'Set-up', 'beaver-press' ); ?></h2>
				<ul class="bp-checks">
					<li class="<?php echo $engine_on ? 'is-ok' : 'is-no'; ?>"><?php esc_html_e( 'Automatic translation switched on (Beaver Press -> Languages -> Automatic Translation)', 'beaver-press' ); ?></li>
					<li class="<?php echo $key ? 'is-ok' : 'is-no'; ?>">
						<?php
						/* translators: %s: provider name */
						echo esc_html( sprintf( __( 'API key saved for %s', 'beaver-press' ), $preset['label'] ) );
						?>
					</li>
					<li class="<?php echo $test ? ( $test['ok'] ? 'is-ok' : 'is-no' ) : 'is-wait'; ?>"><?php echo esc_html( $test ? $test['message'] : __( 'Connection not tested yet', 'beaver-press' ) ); ?></li>
					<li class="<?php echo $languages ? 'is-ok' : 'is-no'; ?>">
						<?php
						echo esc_html(
							$languages
								/* translators: %s: language names */
								? sprintf( __( 'Languages: %s', 'beaver-press' ), implode( ', ', $languages ) )
								: __( 'No second language yet', 'beaver-press' )
						);
						?>
					</li>
					<li class="<?php echo BP_Guard::enabled( $mt ) ? 'is-ok' : 'is-wait'; ?>"><?php echo esc_html( BP_Guard::enabled( $mt ) ? __( 'Visitor guard on: visitors never cause paid requests', 'beaver-press' ) : __( 'Visitor guard off: any page view may send new text to the provider', 'beaver-press' ) ); ?></li>
									<?php
					$bp_started = BP_Auto::started_languages();
					$bp_new     = array_diff_key( $languages, array_flip( $bp_started ) );
					?>
					<li class="<?php echo $bp_new ? 'is-no' : 'is-ok'; ?>">
						<?php
						echo esc_html(
							$bp_new
								/* translators: %s: language names */
								? sprintf( __( 'Not translated yet: %s. Tick only these below and press Translate site.', 'beaver-press' ), implode( ', ', $bp_new ) )
								: __( 'Every language has translations', 'beaver-press' )
						);
						?>
					</li>
				</ul>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ); ?>"><?php esc_html_e( 'Engine settings', 'beaver-press' ); ?></a></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-quiet">
					<input type="hidden" name="action" value="bp_quiet" />
					<?php wp_nonce_field( 'bp_quiet' ); ?>
					<label><input type="checkbox" name="auto" value="yes" <?php checked( BP_Auto::enabled() ); ?> /> <?php esc_html_e( 'Translate updated content automatically', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="topup" value="yes" <?php checked( BP_Topup::enabled() ); ?> /> <?php esc_html_e( 'Finish almost-translated pages once a day', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="alerts" value="yes" <?php checked( BP_Alerts::email_enabled() ); ?> /> <?php esc_html_e( 'Email me when translation stops (key refused, no credit)', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="cache" value="yes" <?php checked( BP_Cache::enabled() ); ?> /> <?php esc_html_e( 'Keep finished translated pages ready (faster)', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="slugs" value="yes" <?php checked( BP_Slugs::enabled() ); ?> /> <?php esc_html_e( 'Automatically translate slugs (page addresses)', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="complete" value="yes" <?php checked( BP_Complete::enabled() ); ?> /> <?php esc_html_e( 'Show a translated page only when it is complete', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="suggest" value="yes" <?php checked( BP_Suggest::enabled() ); ?> /> <?php esc_html_e( 'Suggest the visitor\'s language', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="redirect" value="yes" <?php checked( BP_Suggest::redirect_enabled() ); ?> /> <?php esc_html_e( 'Send first-time visitors to their language automatically', 'beaver-press' ); ?></label>
					<label><input type="checkbox" name="quiet" value="yes" <?php checked( BP_Quiet::enabled() ); ?> /> <?php esc_html_e( 'Hide the engine\'s extras', 'beaver-press' ); ?></label>
					<button class="button button-small"><?php esc_html_e( 'Save', 'beaver-press' ); ?></button>
					<span class="description"><?php esc_html_e( 'Automatic: a page, post, tour, destination or stay you publish or update is translated in the background about a minute later, in the languages already started; only new or changed text is sent.', 'beaver-press' ); ?> <strong><?php echo esc_html( BP_Auto::status() ); ?></strong></span>
					<span class="description"><?php esc_html_e( 'Daily: pages that lost their complete state without an edit (a new text in a widget, menu or theme box) are finished at night; only the missing texts are sent, each page is tried once a day, and the daily limit applies.', 'beaver-press' ); ?> <strong><?php echo esc_html( BP_Topup::status() ); ?></strong></span>
					<span class="description">
						<?php esc_html_e( 'Faster: a translated page with every text translated is kept ready for visitors for up to 6 hours and served without building it again; any edit or translation change starts afresh. Visitors also get the page in the other languages fetched the moment they reach for the language switcher.', 'beaver-press' ); ?>
						<strong>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of pages */
								_n( '%s page ready', '%s pages ready', BP_Cache::count(), 'beaver-press' ),
								number_format_i18n( BP_Cache::count() )
							)
						);
						?>
						</strong>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bp_cache_clear' ), 'bp_cache_clear' ) ); ?>"><?php esc_html_e( 'Clear', 'beaver-press' ); ?></a>
					</span>
					<span class="description">
						<?php esc_html_e( 'Only complete pages: while a page still has texts without a translation, visitors on that language get the original page (marked noindex, with a short "not available in Français yet" note) instead of a mix of two languages; it is left out of the hreflang links and the language suggestion until complete. You and translators still see the translated page, and the Translate-site run completes it.', 'beaver-press' ); ?>
						<?php
						$bp_counts = BP_Complete::counts();
						$bp_names  = BP_Run::languages();
						$bp_parts  = array();
						foreach ( $bp_names as $bp_code => $bp_name ) {
							if ( isset( $bp_counts[ $bp_code ] ) ) {
								/* translators: 1: language, 2: complete pages, 3: pages seen */
								$bp_parts[] = sprintf( __( '%1$s %2$s of %3$s pages complete', 'beaver-press' ), $bp_name, number_format_i18n( $bp_counts[ $bp_code ][0] ), number_format_i18n( $bp_counts[ $bp_code ][0] + $bp_counts[ $bp_code ][1] ) );
							}
						}
						if ( $bp_parts ) {
							echo '<strong>' . esc_html( implode( ' · ', $bp_parts ) ) . '</strong>';
						}
						?>
					</span>
					<span class="description"><?php esc_html_e( 'Page addresses: pages and archives get an address in each language (/fr/a-propos/), drafted from the translated title as the run translates them and editable on each edit screen ("Translated addresses"); the old address in that language moves there with a 301. Post-type bases such as /tours/ stay; the original language is never changed.', 'beaver-press' ); ?></span>
					<span class="description"><?php esc_html_e( 'Language suggestion: a visitor whose browser prefers another language offered here sees "View this page in Français?" in that language, once; the answer, or a language picked in the switcher, is remembered for a year. Automatic sending (off by default) takes first-time visitors on the original pages straight to their language instead; search engines and logged-in users are never sent, but automatic redirects can still make search engines index fewer original pages.', 'beaver-press' ); ?></span>
					<span class="description"><?php esc_html_e( 'Hide extras: support chat, review requests, sales notices, the Addons and AI tabs and upgrade boxes. Languages, the switcher, the visual editor and error notices stay.', 'beaver-press' ); ?></span>
				</form>
			</div>

			<div class="bp-card" id="bp-run">
				<h2><?php esc_html_e( 'Translate site', 'beaver-press' ); ?></h2>
				<p>
					<?php
					/* translators: %d: number of pages */
					echo esc_html( sprintf( _n( 'Visits the %d public page once per language and translates everything new on it. Text already translated is not sent again.', 'Visits all %d public pages once per language and translates everything new on them. Text already translated is not sent again.', $pages, 'beaver-press' ), $pages ) );
					?>
				</p>
				<?php if ( '' !== $blocker ) : ?>
					<p class="bp-status bp-status--error"><?php echo esc_html( $blocker ); ?></p>
				<?php endif; ?>
				<fieldset class="bp-langs">
					<?php foreach ( $languages as $code => $name ) : ?>
						<label><input type="checkbox" name="bp_languages[]" value="<?php echo esc_attr( $code ); ?>" checked /> <?php echo esc_html( $name ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="bp-scope">
					<strong><?php esc_html_e( 'All pages', 'beaver-press' ); ?></strong> ·
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=pages' ) ); ?>"><?php esc_html_e( 'Choose pages instead, and see each page\'s progress', 'beaver-press' ); ?></a>
				</p>
				<?php self::render_estimate( $languages, $engine, $preset ); ?>
				<p class="bp-budget">
					<label for="bp-run-budget"><?php esc_html_e( 'Stop this run after', 'beaver-press' ); ?></label>
					<input type="number" id="bp-run-budget" min="0" step="10000" placeholder="<?php esc_attr_e( 'no limit', 'beaver-press' ); ?>" />
					<span><?php esc_html_e( 'characters sent (optional; Resume continues past it).', 'beaver-press' ); ?></span>
				</p>
				<p class="bp-actions">
					<button type="button" class="button button-primary" id="bp-run-start" <?php disabled( '' !== $blocker ); ?>><?php esc_html_e( 'Translate site', 'beaver-press' ); ?></button>
					<button type="button" class="button" id="bp-run-pause"><?php esc_html_e( 'Pause', 'beaver-press' ); ?></button>
					<button type="button" class="button" id="bp-run-resume"><?php esc_html_e( 'Resume', 'beaver-press' ); ?></button>
					<button type="button" class="button-link button-link-delete" id="bp-run-cancel"><?php esc_html_e( 'Cancel run', 'beaver-press' ); ?></button>
				</p>
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

			<?php self::render_usage(); ?>
			<?php BP_Forms::render(); ?>
			<?php BP_Slug_Bases::render(); ?>
			<?php BP_Transfer::render(); ?>
			<?php BP_History::render(); ?>
			<?php BP_Backup::render(); ?>
			<?php self::render_credit(); ?>
		</div>
		<?php
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
			<p class="description"><?php esc_html_e( 'Each page per language: complete, texts still missing, or not visited yet (counted the last time the page was shown in that language). Tick pages and languages, then Translate: they are added to the run.', 'beaver-press' ); ?></p>
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
		<?php if ( ! $deepl ) : ?>
			<?php $price = BP_Usage::price( $engine['model'] ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-price">
				<input type="hidden" name="action" value="bp_price" />
				<input type="hidden" name="model" value="<?php echo esc_attr( $engine['model'] ); ?>" />
				<?php wp_nonce_field( 'bp_price' ); ?>
				<span>
					<?php
					/* translators: %s: model */
					echo esc_html( sprintf( __( 'Price of %s per million tokens (US$):', 'beaver-press' ), $engine['model'] ) );
					?>
				</span>
				<label><?php esc_html_e( 'in', 'beaver-press' ); ?> <input type="number" name="price_in" min="0" step="0.001" value="<?php echo esc_attr( null !== $price ? (string) $price[0] : '' ); ?>" class="small-text" /></label>
				<label><?php esc_html_e( 'out', 'beaver-press' ); ?> <input type="number" name="price_out" min="0" step="0.001" value="<?php echo esc_attr( null !== $price ? (string) $price[1] : '' ); ?>" class="small-text" /></label>
				<button class="button button-small"><?php esc_html_e( 'Save price', 'beaver-press' ); ?></button>
				<span class="description"><?php esc_html_e( 'From the provider\'s price page; used for these estimates and the usage costs. Empty both to remove.', 'beaver-press' ); ?></span>
			</form>
		<?php endif; ?>
		<?php BP_Budget::render( $engine ); ?>
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
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '#bp-run' ) );
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
		<div class="bp-credit">
			<img class="bp-credit__logo" width="300" height="152" src="<?php echo esc_url( BP_URL . 'assets/digital-beaver-logo.png' ); ?>" alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-press' ); ?>" />
			<div class="bp-credit__text">
				<strong><?php esc_html_e( 'Beaver Press - designed & built by Digital Beaver', 'beaver-press' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new language or a site as fast as this one?', 'beaver-press' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
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
			'run'          => array( __( 'Translate site', 'beaver-press' ), admin_url( 'options-general.php?page=' . self::SLUG ) ),
			'pages'        => array( __( 'Pages', 'beaver-press' ), admin_url( 'options-general.php?page=' . self::SLUG . '&tab=pages' ) ),
			'instructions' => array( __( 'Instructions', 'beaver-press' ), admin_url( 'options-general.php?page=' . self::SLUG . '&tab=instructions' ) ),
			'languages'    => array( __( 'Languages', 'beaver-press' ), admin_url( 'options-general.php?page=translate-press' ) ),
		);
		$html  = '<h1 class="bp-title">' . esc_html__( 'Beaver Press', 'beaver-press' ) . ' <span class="bp-title__version">' . esc_html( BP_VERSION ) . '</span></h1>';
		$html .= '<p class="description">' . esc_html__( 'Translation with Claude, ChatGPT, DeepSeek, Gemini or DeepL. Each text is translated once per language and stored; visitors are then served the stored translation.', 'beaver-press' ) . '</p>';
		$html .= '<h2 class="nav-tab-wrapper bp-tabs">';
		foreach ( $tabs as $id => list( $label, $url ) ) {
			$html .= '<a class="nav-tab' . ( $id === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		return $html . '</h2>';
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
