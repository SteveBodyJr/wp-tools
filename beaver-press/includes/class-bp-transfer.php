<?php
/**
 * Settings export and import: set up the next site in a minute.
 *
 * One small JSON file with the engine (provider, model, endpoint, batch size, effort), the
 * visitor guard, the names list, every Beaver Press switch, the languages limit and the
 * translator instructions. API keys are never in it (paste the key on the new site). The site
 * name inside the instructions is written as {site}, so "{site} is a safari company ..."
 * reads right on the next site. The file lists the languages for information only: languages
 * are added in TranslatePress, which creates their tables.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export and import.
 */
final class BP_Transfer {

	/** Format marker. */
	const FORMAT = 'beaver-press-settings';

	/** File format version. */
	const VERSION = 1;

	/** Engine keys copied from TranslatePress's machine translation settings. */
	const ENGINE_KEYS = array( 'bp-provider', 'bp-model', 'bp-endpoint', 'bp-chunk-size', 'bp-effort', 'bp-visitor-guard', 'bp-glossary-auto', 'bp-glossary' );

	/**
	 * Switches: file key => option name and default.
	 *
	 * @return array
	 */
	private static function switches() {
		return array(
			'auto_update'        => array( BP_Auto::OPTION, 'yes' ),
			'daily_topup'        => array( BP_Topup::OPTION, 'yes' ),
			'ready_pages'        => array( BP_Cache::OPTION, 'yes' ),
			'only_complete'      => array( BP_Complete::OPTION, 'yes' ),
			'suggest_language'   => array( BP_Suggest::OPTION, 'yes' ),
			'first_visit_redirect' => array( BP_Suggest::REDIRECT_OPTION, 'no' ),
			'translated_addresses' => array( BP_Slugs::OPTION, 'no' ),
			'quiet'              => array( BP_Quiet::OPTION, 'yes' ),
			'provider_alert_email' => array( BP_Alerts::OPTION, 'yes' ),
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bp_export', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_bp_import', array( __CLASS__, 'upload' ) );
	}

	/**
	 * The settings as an array (no keys).
	 *
	 * @return array
	 */
	public static function data() {
		$mt     = get_option( 'trp_machine_translation_settings', array() );
		$mt     = is_array( $mt ) ? $mt : array();
		$engine = array();
		foreach ( self::ENGINE_KEYS as $key ) {
			if ( isset( $mt[ $key ] ) ) {
				$engine[ $key ] = $mt[ $key ];
			}
		}
		$switches = array();
		foreach ( self::switches() as $name => list( $option, $default ) ) {
			$switches[ $name ] = 'yes' === get_option( $option, $default ) ? 'yes' : 'no';
		}
		$saved        = get_option( BP_Instructions::OPTION );
		$instructions = null; // null: the new site uses its own suggestions.
		if ( is_array( $saved ) ) {
			$name         = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$swap         = static fn( $t ) => '' !== $name ? str_replace( $name, '{site}', (string) $t ) : (string) $t;
			$instructions = array(
				'site'  => $swap( $saved['site'] ?? '' ),
				'langs' => array_map( $swap, (array) ( $saved['langs'] ?? array() ) ),
			);
		}
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		return array(
			'format'          => self::FORMAT,
			'version'         => self::VERSION,
			'plugin'          => BP_VERSION,
			'exported_from'   => home_url( '/' ),
			'exported_at'     => gmdate( 'c' ),
			'engine'          => $engine,
			'switches'        => $switches,
			'languages_limit' => BP_Languages::allowed(),
			'prices'          => (array) get_option( BP_Usage::PRICES_OPTION, array() ),
			'instructions'    => $instructions,
			'languages'       => array(
				'default'      => (string) $settings['default-language'],
				'translations' => array_values( array_keys( BP_Run::languages() ) ),
				'note'         => 'For information: add languages in Beaver Press -> Languages.',
			),
		);
	}

	/**
	 * JSON text of the settings.
	 *
	 * @return string
	 */
	public static function export_json() {
		return (string) wp_json_encode( self::data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Apply a settings file.
	 *
	 * @param string $json File contents.
	 * @return array|WP_Error Lines describing what changed.
	 */
	public static function import_json( $json ) {
		if ( strlen( (string) $json ) > 65536 ) {
			return new WP_Error( 'bp_import_size', __( 'The file is too large to be a Beaver Press settings file.', 'beaver-press' ) );
		}
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) ) {
			return new WP_Error( 'bp_import_format', __( 'This is not a Beaver Press settings file.', 'beaver-press' ) );
		}
		if ( (int) ( $data['version'] ?? 0 ) > self::VERSION ) {
			return new WP_Error( 'bp_import_version', __( 'This file comes from a newer Beaver Press; update the plugin first.', 'beaver-press' ) );
		}
		$done = array();

		// Engine: merged into TranslatePress's settings and cleaned by the engine's own sanitizer.
		if ( ! empty( $data['engine'] ) && is_array( $data['engine'] ) ) {
			$mt  = get_option( 'trp_machine_translation_settings', array() );
			$mt  = is_array( $mt ) ? $mt : array();
			$raw = array_intersect_key( $data['engine'], array_flip( self::ENGINE_KEYS ) );
			$raw = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', $raw );
			$new = BP_Engine_Settings::sanitize( array_merge( $mt, $raw ), $raw );
			$new['translation-engine'] = BP_Engine_Settings::ENGINE;
			update_option( 'trp_machine_translation_settings', $new );
			/* translators: 1: provider, 2: model */
			$done[] = sprintf( __( 'Engine: %1$s, model %2$s (automatic translation switched on).', 'beaver-press' ), $new['bp-provider'], $new['bp-model'] );
			if ( '' === BP_Keys::get( $new['bp-provider'] ) ) {
				/* translators: %s: provider */
				$done[] = sprintf( __( 'No API key saved for %s on this site yet: paste it in Beaver Press -> Languages -> Automatic Translation.', 'beaver-press' ), $new['bp-provider'] );
			}
		}

		// Switches.
		if ( ! empty( $data['switches'] ) && is_array( $data['switches'] ) ) {
			$slugs_before = get_option( BP_Slugs::OPTION, 'no' );
			foreach ( self::switches() as $name => list( $option ) ) {
				if ( isset( $data['switches'][ $name ] ) ) {
					update_option( $option, 'yes' === $data['switches'][ $name ] ? 'yes' : 'no', false );
				}
			}
			if ( 'yes' !== $slugs_before && 'yes' === get_option( BP_Slugs::OPTION, 'no' ) ) {
				BP_Slugs::schedule_backfill();
			}
			$done[] = __( 'Switches: automatic updates, ready pages, only complete pages, language suggestion, first-visit redirect, translated addresses, quiet mode.', 'beaver-press' );
		}

		// Model prices (US$ per million tokens).
		if ( ! empty( $data['prices'] ) && is_array( $data['prices'] ) ) {
			foreach ( $data['prices'] as $model => $pair ) {
				if ( is_array( $pair ) && 2 === count( $pair ) ) {
					BP_Usage::set_price( (string) $model, (float) array_values( $pair )[0], (float) array_values( $pair )[1] );
				}
			}
			$done[] = __( 'Model prices.', 'beaver-press' );
		}

		// Languages limit.
		if ( isset( $data['languages_limit'] ) ) {
			$trp = get_option( 'trp_settings', array() );
			if ( is_array( $trp ) ) {
				$trp[ BP_Languages::SETTING ] = min( BP_Languages::MAX_EXTRA, max( 1, absint( $data['languages_limit'] ) ) );
				update_option( 'trp_settings', $trp );
				/* translators: %d: number */
				$done[] = sprintf( __( 'Extra languages allowed: %d.', 'beaver-press' ), $trp[ BP_Languages::SETTING ] );
			}
		}

		// Instructions (null: this site keeps using its suggestions).
		if ( array_key_exists( 'instructions', $data ) ) {
			if ( is_array( $data['instructions'] ) ) {
				$clean = BP_Instructions::clean( array( 'site' => $data['instructions']['site'] ?? '' ) );
				// Only the file's languages are saved (also ones this site does not offer yet); the
				// others keep their suggestions.
				$clean['langs'] = array();
				foreach ( (array) ( $data['instructions']['langs'] ?? array() ) as $code => $text ) {
					if ( preg_match( '/^[A-Za-z_]+$/', (string) $code ) ) {
						$clean['langs'][ $code ] = mb_substr( trim( sanitize_textarea_field( (string) $text ) ), 0, BP_Instructions::MAX_LANG );
					}
				}
				update_option(
					BP_Instructions::OPTION,
					array(
						'site'  => $clean['site'],
						'langs' => $clean['langs'],
					),
					false
				);
				$done[] = __( 'Translator instructions.', 'beaver-press' );
			} else {
				delete_option( BP_Instructions::OPTION );
				$done[] = __( 'Translator instructions: the suggestions.', 'beaver-press' );
			}
		}

		// Languages: information only.
		$wanted  = (array) ( $data['languages']['translations'] ?? array() );
		$missing = array_diff( $wanted, array_keys( BP_Run::languages() ) );
		if ( $missing ) {
			/* translators: %s: language codes */
			$done[] = sprintf( __( 'Languages in the file that this site does not offer yet (add them in Beaver Press -> Languages): %s.', 'beaver-press' ), implode( ', ', array_map( 'sanitize_text_field', $missing ) ) );
		}
		BP_Slugs::forget_map();
		BP_Cache::clear();
		return $done;
	}

	/**
	 * Download the file.
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_export' );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="beaver-press-' . sanitize_file_name( $host . '-' . gmdate( 'Y-m-d' ) ) . '.json"' );
		echo self::export_json(); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON file download.
		exit;
	}

	/**
	 * Import an uploaded file.
	 */
	public static function upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_import' );
		$file = $_FILES['bp_settings_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, contents parsed as JSON only.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$result = new WP_Error( 'bp_import_file', __( 'Choose a settings file first.', 'beaver-press' ) );
		} else {
			$result = self::import_json( (string) file_get_contents( $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- uploaded temp file.
		}
		set_transient( 'bp_import_result_' . get_current_user_id(), is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'done' => $result ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '#bp-transfer' ) );
		exit;
	}

	/**
	 * Card on the Translate site tab.
	 */
	public static function render() {
		$result = get_transient( 'bp_import_result_' . get_current_user_id() );
		if ( false !== $result ) {
			delete_transient( 'bp_import_result_' . get_current_user_id() );
		}
		?>
		<div class="bp-card" id="bp-transfer">
			<h2><?php esc_html_e( 'Copy these settings to another site', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Export: engine, model and batch size, visitor guard, names to keep, every switch on this page, the languages limit and the translator instructions (your site name becomes {site}). API keys are never included. Import it on the other site, then paste that site\'s API key and add its languages in Beaver Press -> Languages.', 'beaver-press' ); ?></p>
			<?php if ( is_array( $result ) && isset( $result['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $result['error'] ); ?></p></div>
			<?php elseif ( is_array( $result ) && isset( $result['done'] ) ) : ?>
				<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Settings imported.', 'beaver-press' ); ?></strong></p><ul>
					<?php foreach ( (array) $result['done'] as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>
			<div class="bp-transfer__row">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bp_export" />
					<?php wp_nonce_field( 'bp_export' ); ?>
					<button class="button"><?php esc_html_e( 'Export settings (.json)', 'beaver-press' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="bp_import" />
					<?php wp_nonce_field( 'bp_import' ); ?>
					<input type="file" name="bp_settings_file" accept=".json,application/json" />
					<button class="button" onclick="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?php esc_attr_e( 'Replace this site\'s Beaver Press settings with the file\'s? API keys and translations are not touched.', 'beaver-press' ); ?>"><?php esc_html_e( 'Import settings', 'beaver-press' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}
