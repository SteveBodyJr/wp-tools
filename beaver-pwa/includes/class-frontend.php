<?php
/**
 * Front end output: head tags, worker registration and the install prompt.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything a visitor's browser needs in order to offer the install.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Frontend {

	const HANDLE = 'beaver-pwa';

	/**
	 * Registers front end hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_prompt' ) );

		add_shortcode( 'beaver_pwa_install', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Whether the plugin should output anything on this request.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( ! Beaver_PWA_Settings::is_enabled() || is_admin() ) {
			return false;
		}

		// The customiser and page builder previews run the front end inside an
		// iframe; a worker installed from there would cache editor state.
		if ( is_customize_preview() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['preview'] ) || isset( $_GET['customize_changeset_uuid'] ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/**
		 * Filters whether the app is advertised on the current request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $active Whether to output the manifest link and worker.
		 */
		return (bool) apply_filters( 'beaver_pwa_is_active', true );
	}

	/**
	 * Whether the worker should be registered for the current visitor.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function should_register() {
		if ( ! Beaver_PWA_Settings::get( 'register_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		return true;
	}

	/**
	 * Prints the manifest link and the platform meta tags.
	 *
	 * @since 1.0.0
	 */
	public static function render_head() {
		if ( ! self::is_active() ) {
			return;
		}

		$manifest = Beaver_PWA_Routes::url( 'manifest' );

		printf( "<link rel=\"manifest\" href=\"%s\">\n", esc_url( $manifest ) );

		if ( Beaver_PWA_Settings::get( 'theme_color_meta' ) ) {
			printf( "<meta name=\"theme-color\" content=\"%s\">\n", esc_attr( Beaver_PWA_Settings::get( 'theme_color' ) ) );
		}

		if ( ! Beaver_PWA_Settings::get( 'apple_meta' ) ) {
			return;
		}

		// iOS ignores the manifest display mode and reads these instead.
		echo "<meta name=\"mobile-web-app-capable\" content=\"yes\">\n";

		if ( 'browser' !== Beaver_PWA_Settings::get( 'display' ) ) {
			echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
		}

		printf(
			"<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"%s\">\n",
			esc_attr( self::status_bar_style() )
		);

		printf(
			"<meta name=\"apple-mobile-web-app-title\" content=\"%s\">\n",
			esc_attr( Beaver_PWA_Settings::short_name() )
		);

		// Core prints this tag for the site icon; only add one when it will not.
		$apple_icon = Beaver_PWA_Icons::apple_icon_url();

		if ( $apple_icon && ( Beaver_PWA_Settings::get( 'icon_id' ) || ! get_option( 'site_icon' ) ) ) {
			printf( "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"%s\">\n", esc_url( $apple_icon ) );
		}
	}

	/**
	 * Picks the iOS status bar treatment that suits the theme colour.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function status_bar_style() {
		$hex = ltrim( (string) Beaver_PWA_Settings::get( 'theme_color' ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return 'default';
		}

		$luminance = (
			0.2126 * hexdec( substr( $hex, 0, 2 ) ) +
			0.7152 * hexdec( substr( $hex, 2, 2 ) ) +
			0.0722 * hexdec( substr( $hex, 4, 2 ) )
		) / 255;

		return $luminance < 0.5 ? 'black-translucent' : 'default';
	}

	/**
	 * Enqueues the registration script and prompt styles.
	 *
	 * @since 1.0.0
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		$needs_ui = Beaver_PWA_Settings::get( 'prompt_enabled' ) || Beaver_PWA_Settings::get( 'update_toast' );

		if ( $needs_ui ) {
			wp_enqueue_style(
				self::HANDLE,
				BEAVER_PWA_URL . 'public/css/pwa.css',
				array(),
				BEAVER_PWA_VERSION
			);

			wp_add_inline_style( self::HANDLE, self::inline_styles() );
		}

		wp_enqueue_script(
			self::HANDLE,
			BEAVER_PWA_URL . 'public/js/pwa.js',
			array(),
			BEAVER_PWA_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'beaverPWA', self::script_data() );
	}

	/**
	 * Theme colours exposed to the prompt stylesheet.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function inline_styles() {
		return sprintf(
			':root{--beaver-pwa-theme:%s;--beaver-pwa-surface:%s;}',
			sanitize_hex_color( Beaver_PWA_Settings::get( 'theme_color' ) ),
			sanitize_hex_color( Beaver_PWA_Settings::get( 'background_color' ) )
		);
	}

	/**
	 * Configuration handed to the front end script.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function script_data() {
		return array(
			'swUrl'       => Beaver_PWA_Routes::url( 'sw' ),
			'scope'       => Beaver_PWA_Settings::scope_path(),
			'register'    => self::should_register(),
			'updateToast' => (bool) Beaver_PWA_Settings::get( 'update_toast' ),
			'prompt'      => array(
				'enabled'     => (bool) Beaver_PWA_Settings::get( 'prompt_enabled' ),
				'delay'       => (int) Beaver_PWA_Settings::get( 'prompt_delay' ),
				'dismissDays' => (int) Beaver_PWA_Settings::get( 'prompt_dismiss_days' ),
				'iosHint'     => (bool) Beaver_PWA_Settings::get( 'ios_hint' ),
			),
			'i18n'        => array(
				'updateReady' => __( 'A new version of this app is ready.', 'beaver-pwa' ),
				'refresh'     => __( 'Refresh', 'beaver-pwa' ),
				'dismiss'     => __( 'Dismiss', 'beaver-pwa' ),
			),
		);
	}

	/**
	 * Default prompt copy.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function prompt_text() {
		$text = (string) Beaver_PWA_Settings::get( 'prompt_text' );

		if ( '' !== $text ) {
			return $text;
		}

		return sprintf(
			/* translators: %s: application name. */
			__( 'Add %s to your home screen for a faster, full screen experience.', 'beaver-pwa' ),
			Beaver_PWA_Settings::app_name()
		);
	}

	/**
	 * Default install button label.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function prompt_button() {
		$label = (string) Beaver_PWA_Settings::get( 'prompt_button' );

		return '' !== $label ? $label : __( 'Install', 'beaver-pwa' );
	}

	/**
	 * Prints the install prompt. It stays hidden until the browser confirms the
	 * app is installable, so it never advertises something that cannot happen.
	 *
	 * @since 1.0.0
	 */
	public static function render_prompt() {
		if ( ! self::is_active() || ! Beaver_PWA_Settings::get( 'prompt_enabled' ) ) {
			return;
		}

		$icon     = Beaver_PWA_Icons::preview_url();
		$position = (string) Beaver_PWA_Settings::get( 'prompt_position' );

		?>
		<div class="beaver-pwa-prompt beaver-pwa-prompt--<?php echo esc_attr( $position ); ?>" id="beaver-pwa-prompt" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'Install this app', 'beaver-pwa' ); ?>" hidden>
			<?php if ( $icon ) : ?>
				<img class="beaver-pwa-prompt__icon" src="<?php echo esc_url( $icon ); ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
			<?php endif; ?>
			<div class="beaver-pwa-prompt__body">
				<strong class="beaver-pwa-prompt__title"><?php echo esc_html( Beaver_PWA_Settings::app_name() ); ?></strong>
				<span class="beaver-pwa-prompt__text" data-beaver-pwa-role="text"><?php echo esc_html( self::prompt_text() ); ?></span>
				<span class="beaver-pwa-prompt__text beaver-pwa-prompt__text--ios" data-beaver-pwa-role="ios" hidden>
					<?php
					printf(
						/* translators: 1: opening span tag for the iOS share glyph, 2: closing span tag. */
						esc_html__( 'Tap %1$sShare%2$s, then choose "Add to Home Screen".', 'beaver-pwa' ),
						'<span class="beaver-pwa-prompt__share" aria-hidden="true">',
						'</span>'
					);
					?>
				</span>
			</div>
			<button type="button" class="beaver-pwa-prompt__action" data-beaver-pwa-install>
				<?php echo esc_html( self::prompt_button() ); ?>
			</button>
			<button type="button" class="beaver-pwa-prompt__close" data-beaver-pwa-close aria-label="<?php esc_attr_e( 'Dismiss', 'beaver-pwa' ); ?>">
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
		</div>
		<?php
	}

	/**
	 * Renders an install button anywhere in the content.
	 *
	 * @since 1.0.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		if ( ! self::is_active() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'text'  => self::prompt_button(),
				'class' => '',
			),
			$atts,
			'beaver_pwa_install'
		);

		return sprintf(
			'<button type="button" class="beaver-pwa-button %1$s" data-beaver-pwa-install hidden>%2$s</button>',
			esc_attr( $atts['class'] ),
			esc_html( $atts['text'] )
		);
	}
}
