<?php
/**
 * Per-form on/off settings.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which forms the two traps actually guard.
 *
 * The list of *available* forms is detected, not hand-maintained: WordPress's
 * own comment form and login page are always present, and an integration
 * like att-core's inquiry forms only appears here when that plugin's own
 * functions do — the same way Beaver_Chameleon_Forms only registers its
 * hooks when they exist. Comment and login default on, matching this
 * plugin's behaviour from before this screen existed; anything detected
 * afterward defaults off, so activating another plugin never silently starts
 * blocking its forms.
 *
 * @since 1.1.0
 */
class Beaver_Chameleon_Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'beaver_chameleon_forms';

	/**
	 * Runtime cache of the stored on/off values.
	 *
	 * @var array<string,bool>|null
	 */
	private static $cache = null;

	/**
	 * Every form this install could possibly guard, and what it ships with.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string,array{label:string,default:bool}>
	 */
	public static function available_forms() {
		$forms = array(
			'comment' => array(
				'label'   => __( 'Comment form', 'beaver-chameleon' ),
				'default' => true,
			),
			'login'   => array(
				'label'   => __( 'Login form (wp-login.php)', 'beaver-chameleon' ),
				'default' => true,
			),
		);

		// ATT_CORE_FORM_EXTENSIBLE, not function_exists(), is the accurate
		// signal here: att_core_form_security_fields() has existed on every
		// site in the att-core fleet for a while, long before the hooks this
		// integration actually needs did — see the constant's definition in
		// att-core.php. Checking the function alone would offer this toggle,
		// looking identical to a working one, on a site whose att-core is
		// too old to ever call the hooks Beaver_Chameleon_Forms relies on.
		if ( defined( 'ATT_CORE_FORM_EXTENSIBLE' ) && ATT_CORE_FORM_EXTENSIBLE ) {
			$forms['att_core:tour_inquiry']    = array(
				'label'   => __( 'Tour inquiry form (att-core)', 'beaver-chameleon' ),
				'default' => false,
			);
			$forms['att_core:contact_message'] = array(
				'label'   => __( 'Contact form (att-core)', 'beaver-chameleon' ),
				'default' => false,
			);
		}

		/**
		 * Lets another integration register itself as a form Chameleon can
		 * guard, the same way the att-core forms above register themselves
		 * only when att-core is actually present.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string,array{label:string,default:bool}> $forms Detected forms so far.
		 */
		return apply_filters( 'beaver_chameleon_available_forms', $forms );
	}

	/**
	 * Whether a given form is currently guarded.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key Form key, e.g. 'comment', 'login', 'att_core:tour_inquiry'.
	 * @return bool
	 */
	public static function is_enabled( $key ) {
		$stored = self::all();

		if ( array_key_exists( $key, $stored ) ) {
			return (bool) $stored[ $key ];
		}

		$forms = self::available_forms();

		return isset( $forms[ $key ]['default'] ) ? (bool) $forms[ $key ]['default'] : false;
	}

	/**
	 * All explicitly stored on/off values, keyed by form.
	 *
	 * A form never saved yet — including every form on a site that has never
	 * opened this screen — has no entry here; is_enabled() is what falls
	 * back to its default in that case.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string,bool>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored       = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}

		return self::$cache;
	}

	/**
	 * Saves the on/off state for every currently detected form.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $enabled Keys of the forms left switched on — an
	 *                          unchecked checkbox never reaches $_POST, so
	 *                          anything not in this list is saved as off.
	 */
	public static function update( array $enabled ) {
		$clean = array();

		foreach ( array_keys( self::available_forms() ) as $key ) {
			$clean[ $key ] = in_array( $key, $enabled, true );
		}

		update_option( self::OPTION, $clean, 'yes' );

		self::$cache = $clean;
	}
}
