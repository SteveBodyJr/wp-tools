<?php
/**
 * Third-party form integrations.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extends both traps to a site's other forms, one integration at a time.
 *
 * Right now that is att-core's tour-inquiry and contact forms — a comment
 * and a login form are the only two things this plugin could guard on its
 * own, but neither is what actually receives spam on an att-core site,
 * where the real form is a custom lead form posting to admin-post.php.
 * att-core exposes an action and a filter for exactly this
 * (`att_core_form_security_fields_after`, `att_core_validate_submission`),
 * so this class is the only thing that needs to know att-core exists: every
 * hook here is registered only when the `ATT_CORE_FORM_EXTENSIBLE` constant
 * says that copy of att-core actually fires them, and is a no-op on a site
 * without it — including an att-core site whose copy predates those hooks,
 * since the fleet is not kept in sync (see att-core's own
 * docs/ATT-CORE-DIVERGENCE.md).
 *
 * Off by default per form — see Beaver_Chameleon_Settings — so installing
 * or updating Chameleon never starts blocking a form it has never guarded
 * before without someone turning it on.
 *
 * @since 1.1.0
 */
class Beaver_Chameleon_Forms {

	/**
	 * Registers hooks, only for the integrations actually present.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		if ( defined( 'ATT_CORE_FORM_EXTENSIBLE' ) && ATT_CORE_FORM_EXTENSIBLE ) {
			add_action( 'att_core_form_security_fields_after', array( __CLASS__, 'render_att_core_fields' ) );
			add_filter( 'att_core_validate_submission', array( __CLASS__, 'validate_att_core_submission' ), 10, 2 );
		}
	}

	/**
	 * Prints both traps' fields inside an att-core form, if that form is
	 * switched on.
	 *
	 * The honeypot's field name and the behavior token are already
	 * per-request, not per-form — the same daily field and the same nonce
	 * work whether they render once on this page or also on the login page,
	 * so no per-form state is needed here beyond the on/off switch itself.
	 *
	 * @since 1.1.0
	 *
	 * @param string $action att-core's form action, e.g. 'tour_inquiry'.
	 */
	public static function render_att_core_fields( $action ) {
		if ( ! Beaver_Chameleon_Settings::is_enabled( 'att_core:' . $action ) ) {
			return;
		}

		Beaver_Chameleon_Honeypot::render_field();
		Beaver_Chameleon_Behavior::render_fields();
	}

	/**
	 * Runs both traps against an att-core submission.
	 *
	 * Skipped entirely once att-core (or an earlier-priority integration)
	 * has already rejected the submission — there is nothing useful to add
	 * to a request that is already being turned away, and it keeps the
	 * first, most specific reason as the one att-core reports back.
	 *
	 * @since 1.1.0
	 *
	 * @param true|WP_Error $result    att-core's own verdict so far.
	 * @param string        $form_type 'tour_inquiry' or 'contact_message'.
	 * @return true|WP_Error
	 */
	public static function validate_att_core_submission( $result, $form_type ) {
		if ( is_wp_error( $result ) || ! Beaver_Chameleon_Settings::is_enabled( 'att_core:' . $form_type ) ) {
			return $result;
		}

		if ( Beaver_Chameleon_Honeypot::is_tripped() ) {
			Beaver_Chameleon_Stats::record( 'honeypot' );

			return new WP_Error( 'beaver_chameleon_honeypot', __( 'Security check failed.', 'beaver-chameleon' ) );
		}

		if ( ! Beaver_Chameleon_Behavior::is_verified() ) {
			Beaver_Chameleon_Stats::record( 'behavior' );

			return new WP_Error( 'beaver_chameleon_behavior', __( 'Security check failed.', 'beaver-chameleon' ) );
		}

		return $result;
	}
}
