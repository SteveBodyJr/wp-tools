<?php
/**
 * Admin screen.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen, a tab per concern, and a scan that checks the work.
 *
 * The screen is written to argue with itself where that is honest. A switch
 * that can break something says so on its own row, in the row's own words,
 * rather than in a readme nobody opens at the moment they need it. That is the
 * difference between a plugin someone can hand to a client and one they cannot.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Admin {

	const NONCE      = 'beaver_shield_action';
	const CAPABILITY = 'manage_options';

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
	 * Adds the screen under Tools.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Beaver Shield', 'beaver-shield' ),
			__( 'Beaver Shield', 'beaver-shield' ),
			self::CAPABILITY,
			BEAVER_SHIELD_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads assets on this screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, BEAVER_SHIELD_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-shield-admin', BEAVER_SHIELD_URL . 'admin/css/admin.css', array( 'dashicons' ), BEAVER_SHIELD_VERSION );
		wp_enqueue_script( 'beaver-shield-admin', BEAVER_SHIELD_URL . 'admin/js/admin.js', array(), BEAVER_SHIELD_VERSION, true );

		wp_localize_script(
			'beaver-shield-admin',
			'beaverShieldAdmin',
			array(
				'i18n' => array(
					'confirmLogin'   => __( 'Change the login URL? Save this address somewhere before you continue. If you lose it, the way back is the line define( \'BEAVER_SHIELD_OFF\', true ); in wp-config.php.', 'beaver-shield' ),
					'confirmEnforce' => __( 'Enforce this policy? Anything it does not allow stops loading immediately, for Googlebot as well as for visitors, and the failure is usually a blank section rather than an error. Run it in report-only mode until the browser console is quiet, then re-run the Exposure scan.', 'beaver-shield' ),
					'confirmXmlrpc'  => __( 'Switch XML-RPC off? This breaks Jetpack and the WordPress mobile app. If you use neither, it should be off.', 'beaver-shield' ),
					'scanning'       => __( 'Reading the site as a visitor…', 'beaver-shield' ),
				),
			)
		);
	}

	/**
	 * The tabs, in the order they are worth reading.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,string>> Tab slug to label and icon.
	 */
	public static function tabs() {
		return array(
			'scan'        => array(
				'label' => __( 'Exposure', 'beaver-shield' ),
				'icon'  => 'dashicons-visibility',
			),
			'fingerprint' => array(
				'label' => __( 'Fingerprint', 'beaver-shield' ),
				'icon'  => 'dashicons-info-outline',
			),
			'hardening'   => array(
				'label' => __( 'Hardening', 'beaver-shield' ),
				'icon'  => 'dashicons-lock',
			),
			'headers'     => array(
				'label' => __( 'Headers', 'beaver-shield' ),
				'icon'  => 'dashicons-shield-alt',
			),
			'access'      => array(
				'label' => __( 'Login URL', 'beaver-shield' ),
				'icon'  => 'dashicons-admin-network',
			),
			'crawlers'    => array(
				'label' => __( 'AI & crawlers', 'beaver-shield' ),
				'icon'  => 'dashicons-search',
			),
			'content'     => array(
				'label' => __( 'Content', 'beaver-shield' ),
				'icon'  => 'dashicons-editor-paste-text',
			),
			'server'      => array(
				'label' => __( 'Server rules', 'beaver-shield' ),
				'icon'  => 'dashicons-database',
			),
		);
	}

	/**
	 * Which settings belong to which tab.
	 *
	 * Saving one tab must not silently clear the switches on another, and an
	 * unchecked box posts nothing at all, so each tab declares the fields it
	 * owns and only those are read back from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string[]> Tab slug to field names.
	 */
	private static function tab_fields() {
		return array(
			'fingerprint' => array( 'strip_generator', 'strip_versions', 'strip_discovery', 'strip_headers', 'disable_emoji', 'block_meta_files' ),
			'hardening'   => array( 'block_user_enum', 'disable_file_edit', 'generic_login_errors', 'block_php_in_uploads', 'no_directory_listing', 'disable_xmlrpc', 'hotlink_protection' ),
			'headers'     => array( 'header_frame', 'header_nosniff', 'header_referrer', 'header_permissions', 'header_hsts', 'csp_mode', 'csp_value' ),
			'access'      => array( 'login_slug' ),
			'crawlers'    => array( 'llms_txt', 'llms_txt_intro', 'block_ai_crawlers' ),
			'content'     => array( 'copy_guard', 'copy_guard_mode', 'copy_guard_exclude', 'image_guard' ),
		);
	}

	/**
	 * The tab currently being viewed.
	 *
	 * @since 1.0.0
	 *
	 * @return string Tab slug.
	 */
	private static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which read-only screen to draw.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scan';

		return array_key_exists( $tab, self::tabs() ) ? $tab : 'scan';
	}

	/**
	 * The screen URL, optionally for one tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tab Tab slug.
	 * @return string URL.
	 */
	private static function url( $tab = '' ) {
		$url = admin_url( 'tools.php?page=' . BEAVER_SHIELD_SLUG );

		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Handles form posts.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['beaver_shield_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['beaver_shield_action'] ) );

		if ( 'save' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

			self::save( $tab );

			wp_safe_redirect( add_query_arg( 'bs_saved', '1', self::url( $tab ) ) );
			exit;
		}

		if ( 'scan' === $action ) {
			Beaver_Shield_Scan::run();

			wp_safe_redirect( add_query_arg( 'bs_scanned', '1', self::url( 'scan' ) ) );
			exit;
		}

		if ( 'sync' === $action ) {
			Beaver_Shield_Htaccess::sync();

			wp_safe_redirect( add_query_arg( 'bs_saved', '1', self::url( 'server' ) ) );
			exit;
		}

		if ( 'reset_login' === $action ) {
			Beaver_Shield_Settings::update( array( 'login_slug' => '' ) );

			wp_safe_redirect( add_query_arg( 'bs_saved', '1', self::url( 'access' ) ) );
			exit;
		}
	}

	/**
	 * Saves one tab.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tab Tab slug.
	 */
	private static function save( $tab ) {
		$fields = self::tab_fields();

		if ( ! isset( $fields[ $tab ] ) ) {
			return;
		}

		$defaults = Beaver_Shield_Settings::defaults();
		$changes  = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		foreach ( $fields[ $tab ] as $key ) {
			if ( is_bool( $defaults[ $key ] ) ) {
				// An unchecked box posts nothing, so absence is the value.
				$changes[ $key ] = ! empty( $_POST[ $key ] );

				continue;
			}

			$changes[ $key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Beaver_Shield_Settings::update( $changes );

		// The server rules and the crawler map are both derived from settings
		// that have just changed, so neither is left describing the old ones.
		Beaver_Shield_Htaccess::sync();
		Beaver_Shield_Crawlers::flush_llms_txt();
	}

	/**
	 * Renders the screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-shield' ) );
		}

		$tab = self::current_tab();
		?>
		<div class="wrap beaver-shield">
			<h1><?php esc_html_e( 'Beaver Shield', 'beaver-shield' ); ?></h1>

			<?php self::render_notices(); ?>

			<div class="beaver-shield-layout">
				<nav class="beaver-shield-rail" aria-label="<?php esc_attr_e( 'Shield sections', 'beaver-shield' ); ?>">
					<?php foreach ( self::tabs() as $slug => $meta ) : ?>
						<a class="beaver-shield-rail__item<?php echo $slug === $tab ? ' is-current' : ''; ?>"
						   href="<?php echo esc_url( self::url( $slug ) ); ?>"
						   <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>>
							<span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $meta['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<div class="beaver-shield-panel">
					<?php
					$method = 'render_' . $tab;

					if ( method_exists( __CLASS__, $method ) ) {
						self::$method();
					}
					?>
				</div>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Saved, scanned and stood-down notices.
	 *
	 * @since 1.0.0
	 */
	private static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['bs_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'beaver-shield' ) . '</p></div>';
		}

		if ( isset( $_GET['bs_scanned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scan finished.', 'beaver-shield' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( Beaver_Shield_Plugin::suspended() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'BEAVER_SHIELD_OFF is set in wp-config.php, so nothing on these screens is being applied. Remove that line to switch the plugin back on.', 'beaver-shield' ) . '</p></div>';
		}
	}

	/**
	 * The exposure scan.
	 *
	 * @since 1.0.0
	 */
	private static function render_scan() {
		$scan  = Beaver_Shield_Scan::last();
		$tally = Beaver_Shield_Scan::tally( $scan );
		?>
		<h2><?php esc_html_e( 'What the outside world can see', 'beaver-shield' ); ?></h2>
		<p class="beaver-shield-lede">
			<?php esc_html_e( 'The switches on the other tabs describe an intention. This reads the site back over HTTP, signed out, the way a scanner would, and reports what actually arrived. A caching layer, a CDN or another plugin can all disagree with the settings, and this is where that shows up.', 'beaver-shield' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( self::url() ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="beaver_shield_action" value="scan" />
			<button type="submit" class="button button-primary" id="beaver-shield-scan"><?php esc_html_e( 'Scan this site', 'beaver-shield' ); ?></button>
			<?php if ( ! empty( $scan['time'] ) ) : ?>
				<span class="beaver-shield-note">
					<?php
					printf(
						/* translators: %s: human readable time difference. */
						esc_html__( 'Last run %s ago.', 'beaver-shield' ),
						esc_html( human_time_diff( (int) $scan['time'] ) )
					);
					?>
				</span>
			<?php endif; ?>
		</form>

		<?php if ( empty( $scan['checks'] ) ) : ?>
			<p class="beaver-shield-note"><?php esc_html_e( 'Nothing scanned yet.', 'beaver-shield' ); ?></p>

			<?php return; ?>
		<?php endif; ?>

		<p class="beaver-shield-tally">
			<span class="beaver-shield-pill beaver-shield-pill--pass"><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d clear', 'beaver-shield' ), $tally['pass'] ) ); ?></span>
			<span class="beaver-shield-pill beaver-shield-pill--warn"><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d worth a look', 'beaver-shield' ), $tally['warn'] ) ); ?></span>
			<span class="beaver-shield-pill beaver-shield-pill--fail"><?php echo esc_html( sprintf( /* translators: %d: count. */ __( '%d exposed', 'beaver-shield' ), $tally['fail'] ) ); ?></span>
		</p>

		<table class="widefat striped beaver-shield-scan">
			<tbody>
				<?php foreach ( $scan['checks'] as $check ) : ?>
					<tr>
						<td class="beaver-shield-scan__status">
							<span class="beaver-shield-dot beaver-shield-dot--<?php echo esc_attr( $check['status'] ); ?>" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php echo esc_html( $check['status'] ); ?></span>
						</td>
						<td>
							<strong><?php echo esc_html( $check['label'] ); ?></strong>
							<span class="beaver-shield-note"><?php echo esc_html( $check['detail'] ); ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="beaver-shield-truth">
			<h3><?php esc_html_e( 'What this plugin cannot do', 'beaver-shield' ); ?></h3>
			<p><?php esc_html_e( 'Worth stating plainly, because the opposite is sold constantly.', 'beaver-shield' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'It cannot block view source. The browser has already downloaded the page, and view-source:, curl, Reader mode and a hundred other tools read it regardless. Scripts that try to trap the developer tools only slow the site down for real visitors.', 'beaver-shield' ); ?></li>
				<li><?php esc_html_e( 'It cannot make text uncopyable. Text has to arrive to be read. The deterrent on the Content tab stops a visitor highlighting a paragraph; it does not stop anyone who wants the text.', 'beaver-shield' ); ?></li>
				<li><?php esc_html_e( 'It cannot hide that this is WordPress. The REST route, the feed, the block class names and the comment form all say so. What it removes is the version detail, which is the part a scanner turns into a list of known holes.', 'beaver-shield' ); ?></li>
				<li><?php esc_html_e( 'It does not serve search engines anything different from visitors. That is called cloaking, and it is a genuine ranking risk. Everyone gets the same page.', 'beaver-shield' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * The fingerprint tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_fingerprint() {
		self::open_form( 'fingerprint', __( 'What the site says about itself', 'beaver-shield' ), __( 'Everything here removes output. Nothing is added, and no request is inspected to decide what to send, so a visitor and a crawler receive exactly the same page. All of it is safe to leave on.', 'beaver-shield' ) );

		echo '<ul class="beaver-shield-switches">';

		self::toggle( 'strip_generator', __( 'Remove the generator tag', 'beaver-shield' ), __( 'The meta tag that names the WordPress version, and the same version stamped into every feed. The most useful line on the page to anyone looking for a version with a known hole in it.', 'beaver-shield' ), true );
		self::toggle( 'strip_versions', __( 'Replace version numbers on scripts and styles', 'beaver-shield' ), __( 'The usual advice is to delete ?ver= entirely, which is wrong: that parameter is how a browser knows its cached copy is stale. This keeps it and replaces the value with a hash of the file, so cache busting still works and the number no longer dates the plugin that loaded it.', 'beaver-shield' ), true );
		self::toggle( 'strip_discovery', __( 'Remove the legacy discovery links', 'beaver-shield' ), __( 'RSD and wlwmanifest exist for Windows Live Writer, which has no modern equivalent, and they point at xmlrpc.php by name. The shortlink, the REST link and the rel=prev/next pair go too: Google confirmed years ago that prev/next is no longer an indexing signal, and canonical is what identifies a page. The oEmbed link goes with them, which is the only real cost here, since it is what lets another WordPress site embed a post of yours.', 'beaver-shield' ), true );
		self::toggle( 'strip_headers', __( 'Remove X-Powered-By and X-Pingback', 'beaver-shield' ), __( 'X-Powered-By carries the exact PHP version and is added by PHP before WordPress runs, so it can only be removed rather than prevented.', 'beaver-shield' ), true );
		self::toggle( 'disable_emoji', __( 'Stop loading the emoji polyfill', 'beaver-shield' ), __( 'wp-emoji-release.min.js sits on a fixed path under a fixed name, which makes it a reliable detection signal, and it exists to render emoji on browsers that have supported them natively for a decade. Removing it also takes a request off every page.', 'beaver-shield' ), true );
		self::toggle( 'block_meta_files', __( 'Block readme and licence files', 'beaver-shield' ), __( 'Every plugin ships a readme.txt stating its exact version. Reading those is how a scan turns a site into a list of things to try, without sending a single attack. Needs the server rules on the last tab.', 'beaver-shield' ), true );

		echo '</ul>';

		self::close_form();
	}

	/**
	 * The hardening tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_hardening() {
		self::open_form( 'hardening', __( 'Doors a normal site never uses', 'beaver-shield' ), __( 'This is the half of the plugin that is worth more than the fingerprint work. Hiding a version number slows an attacker down. Closing enumeration and the file editor removes steps an automated attack actually depends on.', 'beaver-shield' ) );

		echo '<ul class="beaver-shield-switches">';

		self::toggle(
			'block_user_enum',
			__( 'Block username enumeration', 'beaver-shield' ),
			__( 'Closes ?author=1, the anonymous half of the REST users route, the author fields in oEmbed responses, and the users sitemap. Anyone signed in is unaffected, so the block editor keeps listing authors normally.', 'beaver-shield' ),
			true,
			Beaver_Shield_Hardening::author_query_is_redundant()
				? ''
				: __( 'This site uses plain permalinks, so ?author=1 is not a trick, it is the only URL an author archive has. The ?author= part is therefore skipped here and will stay skipped until permalinks are switched to a pretty structure. The REST, oEmbed and sitemap parts still apply.', 'beaver-shield' )
		);
		self::toggle( 'disable_file_edit', __( 'Switch off the admin file editor', 'beaver-shield' ), __( 'Appearance → Theme File Editor turns a stolen administrator password into arbitrary code on the server. Nobody should be editing production files through a browser textarea anyway.', 'beaver-shield' ), true );
		self::toggle( 'generic_login_errors', __( 'Stop login errors confirming accounts', 'beaver-shield' ), __( 'WordPress distinguishes an unknown username from a wrong password, which confirms the account exists. One message for every failure removes that.', 'beaver-shield' ), true );
		self::toggle( 'block_php_in_uploads', __( 'Stop PHP executing in the uploads folder', 'beaver-shield' ), __( 'Nothing in the media library should ever run. If a PHP file reaches that folder something has already gone wrong, and this is what stops it becoming a shell. Needs the server rules.', 'beaver-shield' ), true );
		self::toggle( 'no_directory_listing', __( 'Stop folders listing their contents', 'beaver-shield' ), __( 'A folder with no index file otherwise prints everything in it. Needs the server rules.', 'beaver-shield' ), true );
		self::toggle(
			'disable_xmlrpc',
			__( 'Switch off XML-RPC', 'beaver-shield' ),
			__( 'An old remote interface that is now mostly used for password guessing at volume and for pingback reflection. Most sites have no use for it.', 'beaver-shield' ),
			false,
			__( 'Breaks Jetpack and the WordPress mobile app. If you use either, leave this alone.', 'beaver-shield' ),
			'xmlrpc'
		);
		self::toggle(
			'hotlink_protection',
			__( 'Stop other sites loading your images', 'beaver-shield' ),
			__( 'Images load on this site and on a direct visit. An empty referrer is allowed on purpose, because that is what a link preview, a feed reader and a privacy conscious browser all send.', 'beaver-shield' ),
			false,
			__( 'Check this against your CDN before switching it on. If images are served from another domain, the rule never sees the request.', 'beaver-shield' )
		);

		echo '</ul>';

		self::close_form();
	}

	/**
	 * The headers tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_headers() {
		self::open_form( 'headers', __( 'Response headers', 'beaver-shield' ), __( 'The first four cost a visitor nothing and are what a security scan looks for first. The content security policy is a different animal and is treated as one.', 'beaver-shield' ) );

		$settings = Beaver_Shield_Settings::all();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bs-frame"><?php esc_html_e( 'X-Frame-Options', 'beaver-shield' ); ?></label></th>
				<td>
					<select id="bs-frame" name="header_frame">
						<option value="SAMEORIGIN" <?php selected( $settings['header_frame'], 'SAMEORIGIN' ); ?>><?php esc_html_e( 'SAMEORIGIN — this site may frame its own pages', 'beaver-shield' ); ?></option>
						<option value="DENY" <?php selected( $settings['header_frame'], 'DENY' ); ?>><?php esc_html_e( 'DENY — nothing may frame this site at all', 'beaver-shield' ); ?></option>
						<option value="" <?php selected( $settings['header_frame'], '' ); ?>><?php esc_html_e( 'Do not send', 'beaver-shield' ); ?></option>
					</select>
					<span class="beaver-shield-note"><?php esc_html_e( 'Stops the site being loaded in a frame on someone else\'s page and clicked through invisibly.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bs-referrer"><?php esc_html_e( 'Referrer-Policy', 'beaver-shield' ); ?></label></th>
				<td>
					<select id="bs-referrer" name="header_referrer">
						<?php foreach ( Beaver_Shield_Settings::referrer_policies() as $policy ) : ?>
							<option value="<?php echo esc_attr( $policy ); ?>" <?php selected( $settings['header_referrer'], $policy ); ?>><?php echo esc_html( $policy ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="beaver-shield-note"><?php esc_html_e( 'How much of the current URL is handed to the next site a visitor clicks through to. The default keeps analytics working while dropping the path on cross-site requests.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
		</table>

		<ul class="beaver-shield-switches">
			<?php
			self::toggle( 'header_nosniff', __( 'X-Content-Type-Options: nosniff', 'beaver-shield' ), __( 'Stops the browser second-guessing a declared content type, which is how an uploaded file gets treated as a script.', 'beaver-shield' ), true );
			self::toggle( 'header_permissions', __( 'Permissions-Policy', 'beaver-shield' ), __( 'Switches off camera, microphone, geolocation, payment and the rest of the browser features a normal WordPress page never asks for.', 'beaver-shield' ), true );
			self::toggle(
				'header_hsts',
				__( 'Strict-Transport-Security', 'beaver-shield' ),
				__( 'Tells browsers to reach this site over HTTPS only, for a year. Sent only when the request is already HTTPS.', 'beaver-shield' ),
				false,
				__( 'Hard to undo. A browser that has seen this header refuses plain HTTP for a year, so only switch it on once the certificate is permanent and every subdomain has one.', 'beaver-shield' )
			);
			?>
		</ul>

		<h3><?php esc_html_e( 'Content Security Policy', 'beaver-shield' ); ?></h3>
		<p class="beaver-shield-note">
			<?php esc_html_e( 'A policy that has not been fitted to this site will stop a page builder, a font or an analytics tag loading, and the failure looks like a blank section rather than an error. Start in report-only: the browser reports what the policy would have blocked and blocks nothing. Widen it until the console is quiet, then enforce.', 'beaver-shield' ); ?>
		</p>
		<p class="beaver-shield-warn">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<?php esc_html_e( 'This is the one setting in the plugin that can cost search traffic, and the one the Exposure scan cannot check for you. Google renders a page in headless Chromium before indexing it, and that renderer obeys this header exactly as a visitor\'s browser does, so an enforced policy that blocks the site\'s own CSS or JavaScript means Google indexes the page without them. A policy is enforced inside the browser, not at the server, so a scan that fetches files over HTTP sees nothing wrong. The browser console in report-only mode is the only thing that will tell you, which is the whole reason that mode exists.', 'beaver-shield' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'beaver-shield' ); ?></th>
				<td>
					<fieldset class="beaver-shield-modes">
						<label><input type="radio" name="csp_mode" value="off" <?php checked( $settings['csp_mode'], 'off' ); ?> /> <?php esc_html_e( 'Off', 'beaver-shield' ); ?></label>
						<label><input type="radio" name="csp_mode" value="report" <?php checked( $settings['csp_mode'], 'report' ); ?> /> <?php esc_html_e( 'Report only — nothing is blocked', 'beaver-shield' ); ?></label>
						<label><input type="radio" name="csp_mode" value="enforce" data-confirm="enforce" <?php checked( $settings['csp_mode'], 'enforce' ); ?> /> <?php esc_html_e( 'Enforce', 'beaver-shield' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bs-csp"><?php esc_html_e( 'Policy', 'beaver-shield' ); ?></label></th>
				<td>
					<textarea id="bs-csp" name="csp_value" rows="5" class="large-text code"><?php echo esc_textarea( $settings['csp_value'] ); ?></textarea>
					<span class="beaver-shield-note"><?php esc_html_e( 'Add the hosts this site genuinely loads from: fonts, maps, analytics, embeds.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
		</table>
		<?php

		self::close_form();
	}

	/**
	 * The login URL tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_access() {
		$slug = (string) Beaver_Shield_Settings::get( 'login_slug', '' );

		self::open_form( 'access', __( 'Where the login page lives', 'beaver-shield' ), __( 'Nearly all password guessing is a script posting to /wp-login.php across a list of domains. It does not look for the form and it does not follow redirects, so moving the file removes that entire volume of traffic. It is not a substitute for a strong password, and anyone who finds the new path is exactly where they were before.', 'beaver-shield' ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bs-login"><?php esc_html_e( 'Login path', 'beaver-shield' ); ?></label></th>
				<td>
					<span class="beaver-shield-prefix"><?php echo esc_html( trailingslashit( home_url() ) ); ?></span>
					<input type="text" id="bs-login" name="login_slug" class="regular-text" value="<?php echo esc_attr( $slug ); ?>"
					       placeholder="<?php esc_attr_e( 'leave empty to keep wp-login.php', 'beaver-shield' ); ?>" />
					<span class="beaver-shield-note"><?php esc_html_e( 'Letters, numbers and hyphens. With this set, wp-login.php returns the theme\'s 404 page, which is deliberate: a redirect or a refusal would confirm the file is there and has been moved.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
		</table>

		<?php if ( '' !== $slug ) : ?>
			<div class="beaver-shield-callout">
				<h3><?php esc_html_e( 'Your login URL', 'beaver-shield' ); ?></h3>
				<p><code class="beaver-shield-url"><?php echo esc_html( Beaver_Shield_Login::login_url() ); ?></code></p>
				<p><?php esc_html_e( 'Save it somewhere that is not this site. If a page cache sits in front of the site, exclude this path from it, the same way wp-login.php would already be excluded.', 'beaver-shield' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		self::close_form();

		self::render_recovery();
	}

	/**
	 * The crawlers tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_crawlers() {
		$settings = Beaver_Shield_Settings::all();

		self::open_form( 'crawlers', __( 'AI crawlers and answer engines', 'beaver-shield' ), __( 'An AI crawler fetches raw HTML over HTTP. It runs no JavaScript, so the copy deterrent is invisible to it and every word on the site is readable to it today. The only lever that exists is a robots.txt directive naming each bot, and that is honoured by choice rather than enforced.', 'beaver-shield' ) );
		?>
		<div class="beaver-shield-callout">
			<h3><?php esc_html_e( 'This is a commercial decision, not a technical one', 'beaver-shield' ); ?></h3>
			<p><?php esc_html_e( 'Blocking these crawlers takes the site out of ChatGPT, Perplexity and AI Overviews, which is a real and growing source of high intent traffic, in exchange for withholding text a competitor could copy by hand anyway. The default here takes the other side of that trade: let them read the site, and publish a map telling them what it does and how to credit it.', 'beaver-shield' ); ?></p>
		</div>

		<ul class="beaver-shield-switches">
			<?php
			self::toggle( 'llms_txt', __( 'Publish llms.txt', 'beaver-shield' ), __( 'A plain text guide at /llms.txt: what this business does, which pages matter, and the terms you expect to be quoted under. Built from the published pages and rebuilt whenever they change.', 'beaver-shield' ), true );
			self::toggle(
				'block_ai_crawlers',
				__( 'Ask AI crawlers not to read the site', 'beaver-shield' ),
				__( 'Adds a robots.txt directive for each of the bots below. Google-Extended governs Gemini training only and has no bearing on Search ranking; Googlebot and Bingbot are never touched.', 'beaver-shield' ),
				false,
				__( 'This removes the site from AI answers. For an agency, that is usually a lead channel rather than a leak.', 'beaver-shield' )
			);
			?>
		</ul>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bs-llms"><?php esc_html_e( 'Opening lines of llms.txt', 'beaver-shield' ); ?></label></th>
				<td>
					<textarea id="bs-llms" name="llms_txt_intro" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'What this business does, who it is for, and where it works.', 'beaver-shield' ); ?>"><?php echo esc_textarea( $settings['llms_txt_intro'] ); ?></textarea>
					<span class="beaver-shield-note"><?php esc_html_e( 'The site name, tagline, page list and attribution terms are added automatically.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'The bots that would be named', 'beaver-shield' ); ?></h3>
		<table class="widefat striped beaver-shield-bots">
			<tbody>
				<?php foreach ( Beaver_Shield_Crawlers::ai_agents() as $agent => $note ) : ?>
					<tr>
						<td class="beaver-shield-bots__name"><code><?php echo esc_html( $agent ); ?></code></td>
						<td><?php echo esc_html( $note ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		self::close_form();
	}

	/**
	 * The content tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_content() {
		$settings = Beaver_Shield_Settings::all();

		self::open_form( 'content', __( 'Copying', 'beaver-shield' ), __( 'The honest description first. Text has to reach the browser to be read, so it is in the page, in the source, and one curl away from anyone who wants it. What follows stops a visitor highlighting a paragraph and pressing Ctrl+C. It does not stop a competitor, a scraper or an AI crawler, none of which run JavaScript.', 'beaver-shield' ) );
		?>
		<ul class="beaver-shield-switches">
			<?php
			self::toggle(
				'copy_guard',
				__( 'Deter copying of body text', 'beaver-shield' ),
				__( 'Form fields, code samples and anything editable stay selectable, so contact forms, search boxes and checkouts behave normally. It never runs for anyone who can edit the site.', 'beaver-shield' ),
				false,
				__( 'Costs you select-to-share on mobile, translation tools and anyone quoting you legitimately. Weigh that against what it actually prevents.', 'beaver-shield' )
			);
			self::toggle( 'image_guard', __( 'Deter image dragging and saving', 'beaver-shield' ), __( 'Blocks drag and the right-click menu over images only. Right click still works everywhere else, because a page that swallows it takes away back, reload and open in new tab.', 'beaver-shield' ) );
			?>
		</ul>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'On a copy attempt', 'beaver-shield' ); ?></th>
				<td>
					<fieldset class="beaver-shield-modes">
						<label>
							<input type="radio" name="copy_guard_mode" value="block" <?php checked( $settings['copy_guard_mode'], 'block' ); ?> />
							<?php esc_html_e( 'Block it', 'beaver-shield' ); ?>
						</label>
						<label>
							<input type="radio" name="copy_guard_mode" value="attribute" <?php checked( $settings['copy_guard_mode'], 'attribute' ); ?> />
							<?php esc_html_e( 'Allow it, and append the source and a link back', 'beaver-shield' ); ?>
						</label>
					</fieldset>
					<span class="beaver-shield-note"><?php esc_html_e( 'The second is usually worth more. Someone quoting the page still can, the quote arrives carrying a link to you, and nobody is fought with.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bs-exclude"><?php esc_html_e( 'Never on these pages', 'beaver-shield' ); ?></label></th>
				<td>
					<input type="text" id="bs-exclude" name="copy_guard_exclude" class="regular-text" value="<?php echo esc_attr( $settings['copy_guard_exclude'] ); ?>" placeholder="12, 34, 56" />
					<span class="beaver-shield-note"><?php esc_html_e( 'Post or page IDs, separated by commas. Landing pages you want quoted, shared and pasted into an email belong here.', 'beaver-shield' ); ?></span>
				</td>
			</tr>
		</table>

		<div class="beaver-shield-truth">
			<h3><?php esc_html_e( 'What actually protects work from a competitor', 'beaver-shield' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Being indexed first. A correct canonical tag and fast indexing are what tell a search engine which copy is the original.', 'beaver-shield' ); ?></li>
				<li><?php esc_html_e( 'A phrase only you use, seeded in the copy. If it turns up on their site, that is evidence rather than an argument.', 'beaver-shield' ); ?></li>
				<li><?php esc_html_e( 'Watermarked images, and originals kept off the server.', 'beaver-shield' ); ?></li>
				<li><?php esc_html_e( 'A takedown notice to their host, which works, unlike anything JavaScript can do.', 'beaver-shield' ); ?></li>
			</ul>
		</div>
		<?php

		self::close_form();
	}

	/**
	 * The server rules tab.
	 *
	 * @since 1.0.0
	 */
	private static function render_server() {
		$blocked = Beaver_Shield_Htaccess::blocked_reason();
		$apache  = '' === $blocked;
		$root    = Beaver_Shield_Htaccess::root_file();
		?>
		<h2><?php esc_html_e( 'Server rules', 'beaver-shield' ); ?></h2>
		<p class="beaver-shield-lede">
			<?php esc_html_e( 'Some of what this plugin blocks is never seen by WordPress at all. A request for a plugin readme.txt is a static file: the web server answers it and PHP never runs, so no amount of hooking can stop it. Those cases need a rule at the server.', 'beaver-shield' ); ?>
		</p>

		<?php if ( $apache ) : ?>
			<table class="widefat striped beaver-shield-status">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'File', 'beaver-shield' ); ?></td>
						<td><code><?php echo esc_html( $root ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Writable', 'beaver-shield' ); ?></td>
						<td>
							<?php
							echo Beaver_Shield_Htaccess::writable( $root )
								? esc_html__( 'Yes.', 'beaver-shield' )
								: esc_html__( 'No. Nothing has been written. Either make the file writable, or paste the rules below in by hand.', 'beaver-shield' );
							?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Backup', 'beaver-shield' ); ?></td>
						<td>
							<?php
							echo file_exists( $root . '-beaver-shield-backup' )
								? esc_html__( 'A copy of the original was taken before the first write.', 'beaver-shield' )
								: esc_html__( 'None yet. One is taken before anything is written.', 'beaver-shield' );
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="beaver-shield-note">
				<?php esc_html_e( 'Everything is written inside a single marked block using the same core function WordPress uses for its own rewrite rules. Nothing outside the markers is touched, and the block is removed when the plugin is deactivated.', 'beaver-shield' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( self::url() ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_shield_action" value="sync" />
				<button type="submit" class="button"><?php esc_html_e( 'Rewrite the rules now', 'beaver-shield' ); ?></button>
			</form>

			<h3><?php esc_html_e( 'What is in the block', 'beaver-shield' ); ?></h3>
			<pre class="beaver-shield-code"><?php echo esc_html( implode( "\n", Beaver_Shield_Htaccess::root_rules() ) ); ?></pre>
		<?php else : ?>
			<div class="beaver-shield-callout">
				<h3><?php esc_html_e( 'Nothing is being written to the server', 'beaver-shield' ); ?></h3>
				<p><?php echo esc_html( $blocked ); ?></p>
				<p><?php esc_html_e( 'This is said here rather than left silent on purpose. A switch that looks on and quietly does nothing is worse than one that is off, because the site looks protected and is not.', 'beaver-shield' ); ?></p>
			</div>

			<pre class="beaver-shield-code"><?php echo esc_html( Beaver_Shield_Htaccess::nginx_rules() ); ?></pre>

			<h3><?php esc_html_e( 'The Apache equivalent', 'beaver-shield' ); ?></h3>
			<pre class="beaver-shield-code"><?php echo esc_html( implode( "\n", Beaver_Shield_Htaccess::root_rules() ) ); ?></pre>
		<?php endif; ?>
		<?php
	}

	/**
	 * The "you can always get back in" box.
	 *
	 * A plugin that can move the login page has to be impossible to get trapped
	 * behind, so the ways out are on the screen itself rather than in a readme
	 * nobody opens at the moment they need it.
	 *
	 * @since 1.0.0
	 */
	private static function render_recovery() {
		?>
		<div class="beaver-shield-recovery">
			<h3><?php esc_html_e( 'If you are ever locked out', 'beaver-shield' ); ?></h3>
			<p><?php esc_html_e( 'Any one of these undoes it, and none of them needs the login page:', 'beaver-shield' ); ?></p>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: PHP constant line. */
						esc_html__( 'Add this to wp-config.php. It stands the whole plugin down, including the moved login URL: %s', 'beaver-shield' ),
						'<code>define( \'BEAVER_SHIELD_OFF\', true );</code>'
					);
					?>
				</li>
				<li>
					<?php esc_html_e( 'WP-CLI, if you have shell access:', 'beaver-shield' ); ?>
					<code>wp beaver-shield login reset</code>
				</li>
				<li><?php esc_html_e( 'Rename the plugin folder over FTP or in the host file manager. WordPress deactivates it on the next request.', 'beaver-shield' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Opens a settings form.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tab   Tab slug.
	 * @param string $title Heading.
	 * @param string $lede  Introduction.
	 */
	private static function open_form( $tab, $title, $lede ) {
		?>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="beaver_shield_action" value="save" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />

			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="beaver-shield-lede"><?php echo esc_html( $lede ); ?></p>
		<?php
	}

	/**
	 * Closes a settings form.
	 *
	 * @since 1.0.0
	 */
	private static function close_form() {
		submit_button( __( 'Save', 'beaver-shield' ) );

		echo '</form>';
	}

	/**
	 * One switch, with the case for and against it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key         Setting name.
	 * @param string $label       Label.
	 * @param string $description What it does.
	 * @param bool   $recommended Whether to mark it recommended.
	 * @param string $warning     What it costs, if anything.
	 * @param string $confirm     Confirmation key for the browser prompt.
	 */
	private static function toggle( $key, $label, $description, $recommended = false, $warning = '', $confirm = '' ) {
		$id = 'bs-' . str_replace( '_', '-', $key );
		?>
		<li class="beaver-shield-switch<?php echo '' !== $warning ? ' is-risky' : ''; ?>">
			<div class="beaver-shield-switch__head">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1"
				       <?php checked( Beaver_Shield_Settings::on( $key ) ); ?>
				       <?php echo '' !== $confirm ? 'data-confirm="' . esc_attr( $confirm ) . '"' : ''; ?> />
				<label for="<?php echo esc_attr( $id ); ?>">
					<strong><?php echo esc_html( $label ); ?></strong>
					<?php if ( $recommended ) : ?>
						<span class="beaver-shield-tag"><?php esc_html_e( 'Recommended', 'beaver-shield' ); ?></span>
					<?php endif; ?>
				</label>
			</div>
			<p class="beaver-shield-note"><?php echo esc_html( $description ); ?></p>
			<?php if ( '' !== $warning ) : ?>
				<p class="beaver-shield-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html( $warning ); ?></p>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-shield-credit">
			<img class="beaver-shield-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_SHIELD_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-shield' ); ?>" />
			<div class="beaver-shield-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-shield' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-shield' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
