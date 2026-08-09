<?php
/**
 * Hand-over from the older theme-based "AI Trip Concierge".
 *
 * Several sites run an earlier concierge that lived inside the theme and stored
 * its configuration in the `att_concierge_settings` option. This imports that
 * configuration in one click, so the API key, provider and persona never have to
 * be retyped, and warns if both chats are still switched on at once.
 *
 * The old option is never deleted, so the move is reversible.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Import
 */
class BAC_Import {

	/** Where the retired theme concierge kept its settings. */
	const LEGACY_OPTION = 'att_concierge_settings';

	/** Set once an import has run, so the card can report it. */
	const DONE_OPTION = 'bac_imported_from_concierge';

	/** Wire up hooks. */
	public static function init() {
		add_action( 'admin_post_bac_import_concierge', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'duplicate_notice' ) );
	}

	/**
	 * The retired concierge's saved settings, if any.
	 *
	 * @return array
	 */
	public static function legacy() {
		$legacy = get_option( self::LEGACY_OPTION, array() );

		return is_array( $legacy ) ? $legacy : array();
	}

	/** Whether there is anything worth importing. */
	public static function available() {
		$legacy = self::legacy();

		return ! empty( $legacy ) && ( ! empty( $legacy['api_key'] ) || ! empty( $legacy['assistant'] ) );
	}

	/** Whether the theme's concierge is still rendering its own widget. */
	public static function legacy_widget_active() {
		return function_exists( 'att_concierge_render_widget' );
	}

	/**
	 * Map the retired concierge's settings onto this plugin's.
	 *
	 * Only the fields that genuinely correspond are carried across. Everything
	 * else keeps this plugin's defaults, so the result is a working assistant the
	 * admin can then refine on the Assistant and Knowledge tabs.
	 *
	 * @param array $legacy Retired settings.
	 * @return array Clean settings ready to save.
	 */
	public static function map( $legacy ) {
		$current = BAC_Settings::get();

		$direct = array(
			'provider'  => 'provider',
			'api_key'   => 'api_key',
			'model'     => 'model',
			'assistant' => 'assistant',
			'tagline'   => 'tagline',
			'greeting'  => 'greeting',
			'context'   => 'context',
		);

		foreach ( $direct as $from => $to ) {
			if ( isset( $legacy[ $from ] ) && '' !== trim( (string) $legacy[ $from ] ) ) {
				$current[ $to ] = $legacy[ $from ];
			}
		}

		if ( isset( $legacy['enabled'] ) ) {
			$current['enabled'] = empty( $legacy['enabled'] ) ? 0 : 1;
		}

		if ( ! empty( $legacy['accent'] ) ) {
			$accent            = trim( (string) $legacy['accent'] );
			$current['accent'] = ( '#' === substr( $accent, 0, 1 ) ) ? $accent : '#' . $accent;
		}

		/**
		 * Filter the mapped settings before they are saved, for sites whose
		 * retired concierge stored extra fields worth carrying across.
		 *
		 * @param array $current Mapped settings.
		 * @param array $legacy  The retired concierge's settings.
		 */
		$current = (array) apply_filters( 'bac_import_map', $current, $legacy );

		return BAC_Settings::sanitize( $current );
	}

	/** Run the import from the Tools tab. */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-ai-chat' ) );
		}
		check_admin_referer( 'bac_import_concierge' );

		$legacy = self::legacy();

		if ( empty( $legacy ) ) {
			self::redirect( 'concierge-none' );
		}

		update_option( BAC_OPTION, self::map( $legacy ) );
		update_option( self::DONE_OPTION, current_time( 'mysql' ) );

		self::redirect( self::legacy_widget_active() ? 'concierge-done-dupe' : 'concierge-done' );
	}

	/**
	 * Redirect back to the settings page carrying a notice.
	 *
	 * @param string $code Notice code.
	 */
	private static function redirect( $code ) {
		$messages = array(
			'concierge-done'      => array(
				'type' => 'success',
				'text' => __( 'Imported your AI Trip Concierge settings, including the API key. Have a look at the Assistant and Knowledge tabs to finish setting it up.', 'beaver-ai-chat' ),
			),
			'concierge-done-dupe' => array(
				'type' => 'warning',
				'text' => __( 'Imported your AI Trip Concierge settings, including the API key. The old concierge is still switched on in your theme, so two chat windows will appear until you remove it: comment out the line that requires inc/ai-concierge.php in your theme\'s functions.php.', 'beaver-ai-chat' ),
			),
			'concierge-none'      => array(
				'type' => 'error',
				'text' => __( 'There was nothing to import: no AI Trip Concierge settings were found on this site.', 'beaver-ai-chat' ),
			),
		);

		$notice = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['concierge-none'];

		add_settings_error( 'bac_notices', $code, $notice['text'], $notice['type'] );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( array( 'settings-updated' => 'true' ), admin_url( 'admin.php?page=beaver-ai-chat' ) ) );
		exit;
	}

	/**
	 * Warn when both chats are live, which is the one way this hand-over goes
	 * visibly wrong: the visitor sees two chat buttons.
	 */
	public static function duplicate_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::legacy_widget_active() || ! BAC_Settings::get( 'enabled' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$here   = $screen && false !== strpos( (string) $screen->id, 'beaver-ai-chat' );

		echo '<div class="notice notice-warning' . ( $here ? '' : ' is-dismissible' ) . '"><p><strong>';
		esc_html_e( 'Beaver AI Chat: two chat windows are showing.', 'beaver-ai-chat' );
		echo '</strong> ';
		esc_html_e( 'Your theme\'s older AI Trip Concierge is still running alongside this plugin. In your theme\'s functions.php, comment out the line that requires inc/ai-concierge.php, then reload the site.', 'beaver-ai-chat' );
		echo '</p></div>';
	}

	/** The import card shown on the Tools tab. */
	public static function render_card() {
		$legacy    = self::legacy();
		$available = self::available();
		$done      = get_option( self::DONE_OPTION, '' );

		echo '<h2 class="bac-h2">' . esc_html__( 'Import from the older AI Trip Concierge', 'beaver-ai-chat' ) . '</h2>';

		if ( ! $available ) {
			echo '<p class="description">'
				. esc_html__( 'Nothing to import: this site has no AI Trip Concierge settings. This section only appears on sites that ran the older theme-based chat.', 'beaver-ai-chat' )
				. '</p>';
			return;
		}

		$provider  = isset( $legacy['provider'] ) ? $legacy['provider'] : '';
		$assistant = isset( $legacy['assistant'] ) ? $legacy['assistant'] : '';
		$has_key   = ! empty( $legacy['api_key'] );

		echo '<p class="description">'
			. esc_html__( 'This site has settings from the older theme-based chat. Import them and the API key, provider and persona all carry across, so nothing needs retyping. Your old settings are left untouched, so you can go back at any time.', 'beaver-ai-chat' )
			. '</p>';

		echo '<table class="bac-lead-table" style="max-width:520px;">';
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Provider', 'beaver-ai-chat' ),
			esc_html( $provider ? $provider : '—' )
		);
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'API key', 'beaver-ai-chat' ),
			$has_key
				? '<span class="bac-ok"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'found, will be carried over', 'beaver-ai-chat' ) . '</span>'
				: esc_html__( 'not set', 'beaver-ai-chat' )
		);
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Assistant name', 'beaver-ai-chat' ),
			esc_html( $assistant ? $assistant : '—' )
		);
		echo '</table>';

		if ( $done ) {
			echo '<p class="bac-ok"><span class="dashicons dashicons-yes-alt"></span> ';
			printf(
				/* translators: %s: date and time of the previous import. */
				esc_html__( 'Already imported on %s. Running it again will overwrite the settings below with the old ones.', 'beaver-ai-chat' ),
				esc_html( $done )
			);
			echo '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bac-tool-form">';
		wp_nonce_field( 'bac_import_concierge' );
		echo '<input type="hidden" name="action" value="bac_import_concierge" />';
		submit_button(
			$done
				? __( 'Import again', 'beaver-ai-chat' )
				: __( 'Import these settings', 'beaver-ai-chat' ),
			'secondary',
			'submit',
			false
		);
		echo '</form>';

		if ( self::legacy_widget_active() ) {
			echo '<p class="description" style="color:#996800;"><strong>'
				. esc_html__( 'Heads up:', 'beaver-ai-chat' ) . '</strong> '
				. esc_html__( 'the old concierge is still switched on in your theme, so two chat windows will show until you comment out the line requiring inc/ai-concierge.php in functions.php.', 'beaver-ai-chat' )
				. '</p>';
		}
	}
}
