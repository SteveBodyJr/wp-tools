<?php
/**
 * How many languages TranslatePress may offer.
 *
 * TranslatePress manages the languages itself (Settings -> General -> languages table);
 * its only cap is the `trp_secondary_languages` filter (1 by default). Beaver Press sets
 * that cap from one field shown under TranslatePress's own table and saved with its form.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Extra languages allowed" setting.
 */
final class BP_Languages {

	/** Key inside TranslatePress's `trp_settings` option. */
	const SETTING = 'bp-extra-languages';

	/** Used until the owner saves a number (no practical cap: add or remove languages freely). */
	const DEFAULT_EXTRA = 30;

	/** Upper bound for the field. */
	const MAX_EXTRA = 30;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'trp_secondary_languages', array( __CLASS__, 'filter_limit' ), 20 );
		add_filter( 'trp_show_language_upgrade_notice', '__return_false', 20 );
		add_action( 'trp_after_language_selector', array( __CLASS__, 'render_field' ) );
		add_filter( 'trp_extra_sanitize_settings', array( __CLASS__, 'sanitize' ) );
	}

	/**
	 * Saved number of extra languages (languages besides the default one).
	 *
	 * @return int
	 */
	public static function allowed() {
		$settings = get_option( 'trp_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings[ self::SETTING ] ) ) {
			return self::DEFAULT_EXTRA;
		}
		return min( self::MAX_EXTRA, max( 1, absint( $settings[ self::SETTING ] ) ) );
	}

	/**
	 * TranslatePress asks how many secondary languages it may keep.
	 *
	 * @param int $limit Limit so far.
	 * @return int
	 */
	public static function filter_limit( $limit ) {
		return max( (int) $limit, self::allowed() );
	}

	/**
	 * Field under TranslatePress's languages table.
	 */
	public static function render_field() {
		$value = self::allowed();
		?>
		<div class="trp-settings-options-item bp-languages-limit" style="margin-top:18px">
			<label for="bp-extra-languages" class="trp-primary-text-bold"><?php esc_html_e( 'Extra languages allowed (Beaver Press)', 'beaver-press' ); ?></label>
			<div style="display:flex;align-items:center;gap:10px;margin-top:6px">
				<input type="number" id="bp-extra-languages" name="trp_settings[<?php echo esc_attr( self::SETTING ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" min="1" max="<?php echo esc_attr( (string) self::MAX_EXTRA ); ?>" step="1" style="width:90px" />
				<span class="trp-description-text"><?php esc_html_e( 'How many languages visitors can switch to, besides the default one. Save, then add them in the table above. Each language is translated once and stored.', 'beaver-press' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Keep our value valid when TranslatePress saves its settings.
	 *
	 * @param array $settings Sanitised TranslatePress settings.
	 * @return array
	 */
	public static function sanitize( $settings ) {
		if ( is_array( $settings ) && isset( $settings[ self::SETTING ] ) ) {
			$settings[ self::SETTING ] = min( self::MAX_EXTRA, max( 1, absint( $settings[ self::SETTING ] ) ) );
		}
		return $settings;
	}
}
