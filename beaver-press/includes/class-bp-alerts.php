<?php
/**
 * Provider alerts: tell the owner when translation has stopped because the provider refuses
 * (key rejected or missing, no credit, DeepL quota used up).
 *
 * The provider's last error is already stored (BP_Providers::ERROR_OPTION) and removed by the
 * next successful request. On top of that:
 *
 * - a notice on every wp-admin screen for administrators, until the problem is gone or the
 *   administrator hides it (it comes back for a different problem);
 * - one email per problem to the site's admin address (switch on the Set-up card), sent again
 *   only after the problem was solved and came back.
 *
 * Short problems (rate limits, time-outs) are left out: they pass on their own.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alerts.
 */
final class BP_Alerts {

	/** Email switch: 'yes' | 'no'. */
	const OPTION = 'beaver_press_alerts';

	/** Current problem: [ 'sig' => provider|code, 'since' => time, 'mailed' => bool ]. */
	const STATE_OPTION = 'beaver_press_alert_state';

	/** User meta: the problem this administrator hid. */
	const HIDDEN_META = 'bp_alert_hidden';

	/** Errors that stop translation until the owner acts. */
	const CODES = array( 'bp_no_key', 'bp_http_401', 'bp_http_402', 'bp_http_403', 'bp_http_456', 'bp_budget' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_option_' . BP_Providers::ERROR_OPTION, array( __CLASS__, 'on_added' ), 10, 2 );
		add_action( 'update_option_' . BP_Providers::ERROR_OPTION, array( __CLASS__, 'on_updated' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'on_deleted' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_bp_alert_hide', array( __CLASS__, 'hide' ) );
	}

	/**
	 * Whether emails are sent.
	 *
	 * @return bool
	 */
	public static function email_enabled() {
		return 'no' !== get_option( self::OPTION, 'yes' );
	}

	/**
	 * The current blocking problem, or null.
	 *
	 * @return array|null Error (time, provider, model, code, message) plus 'sig' and 'since'.
	 */
	public static function current() {
		$error = BP_Providers::last_error();
		if ( ! $error || ! in_array( (string) ( $error['code'] ?? '' ), self::CODES, true ) ) {
			return null;
		}
		$state = get_option( self::STATE_OPTION );
		$sig   = self::sig( $error );
		return $error + array(
			'sig'   => $sig,
			'since' => is_array( $state ) && ( $state['sig'] ?? '' ) === $sig ? (int) $state['since'] : (int) ( $error['time'] ?? time() ),
		);
	}

	/**
	 * Problem signature: provider and error code.
	 *
	 * @param array $error Stored error.
	 * @return string
	 */
	private static function sig( array $error ) {
		return (string) ( $error['provider'] ?? '' ) . '|' . (string) ( $error['code'] ?? '' );
	}

	/**
	 * Error stored for the first time.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Error.
	 */
	public static function on_added( $name, $value ) {
		self::record( $value );
	}

	/**
	 * Error replaced.
	 *
	 * @param mixed $old   Previous error.
	 * @param mixed $value Error.
	 */
	public static function on_updated( $old, $value ) {
		self::record( $value );
	}

	/**
	 * A successful request removed the error: the problem is over.
	 *
	 * @param string $name Option name.
	 */
	public static function on_deleted( $name ) {
		if ( BP_Providers::ERROR_OPTION === $name ) {
			delete_option( self::STATE_OPTION );
		}
	}

	/**
	 * Remember a blocking problem and email it once.
	 *
	 * @param mixed $error Stored error.
	 */
	private static function record( $error ) {
		if ( ! is_array( $error ) || ! in_array( (string) ( $error['code'] ?? '' ), self::CODES, true ) ) {
			delete_option( self::STATE_OPTION ); // A passing problem replaced the blocking one.
			return;
		}
		$sig   = self::sig( $error );
		$state = get_option( self::STATE_OPTION );
		if ( is_array( $state ) && ( $state['sig'] ?? '' ) === $sig ) {
			return; // Same problem, already known.
		}
		$state = array(
			'sig'    => $sig,
			'since'  => time(),
			'mailed' => false,
		);
		if ( self::email_enabled() ) {
			$state['mailed'] = self::mail( $error );
		}
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Provider name for people.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function provider_label( $provider ) {
		$preset = BP_Providers::preset( (string) $provider );
		return (string) ( $preset['label'] ?? $provider );
	}

	/**
	 * What happened and what to do, in plain words.
	 *
	 * @param array $error Stored error.
	 * @return array{0: string, 1: string} Headline, what to do.
	 */
	public static function explain( array $error ) {
		$name = self::provider_label( (string) ( $error['provider'] ?? '' ) );
		switch ( (string) ( $error['code'] ?? '' ) ) {
			case 'bp_http_402':
				/* translators: %s: provider */
				return array( sprintf( __( 'Translation has stopped: the %s account has no credit left.', 'beaver-press' ), $name ), __( 'Top up the account with the provider. Translation starts again by itself with the next run or daily top-up.', 'beaver-press' ) );
			case 'bp_budget':
				return array( __( 'Translation is paused: your spending limit is reached.', 'beaver-press' ), wp_strip_all_tags( (string) ( $error['message'] ?? '' ) ) . ' ' . __( 'To go on now, raise the limit on Settings -> Beaver Press (Translate site card).', 'beaver-press' ) );
			case 'bp_http_456':
				return array( __( 'Translation has stopped: the DeepL quota for this month is used up.', 'beaver-press' ), __( 'Raise the DeepL plan or wait for next month.', 'beaver-press' ) );
			case 'bp_no_key':
				/* translators: %s: provider */
				return array( sprintf( __( 'Translation has stopped: no API key is saved for %s.', 'beaver-press' ), $name ), __( 'Paste the key in the engine settings and save.', 'beaver-press' ) );
			default:
				/* translators: %s: provider */
				return array( sprintf( __( 'Translation has stopped: %s refused the API key.', 'beaver-press' ), $name ), __( 'Check the key with the provider (it may have been deleted or replaced), paste it again in the engine settings and save.', 'beaver-press' ) );
		}
	}

	/**
	 * Email the site's admin address.
	 *
	 * @param array $error Stored error.
	 * @return bool Sent.
	 */
	private static function mail( array $error ) {
		list( $headline, $todo ) = self::explain( $error );
		$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$preset = BP_Providers::preset( (string) ( $error['provider'] ?? '' ) );
		$lines  = array(
			$headline,
			'',
			$todo,
			'',
			/* translators: %s: provider's message */
			sprintf( __( 'Provider\'s message: %s', 'beaver-press' ), wp_strip_all_tags( (string) ( $error['message'] ?? '' ) ) ),
			/* translators: %s: link */
			sprintf( __( 'Engine settings: %s', 'beaver-press' ), admin_url( 'admin.php?page=trp_machine_translation' ) ),
		);
		if ( ! empty( $preset['keys_url'] ) ) {
			/* translators: %s: link */
			$lines[] = sprintf( __( 'Provider account: %s', 'beaver-press' ), $preset['keys_url'] );
		}
		$lines[] = '';
		$lines[] = __( 'You get this email once per problem. Turn it off on Settings -> Beaver Press (Set-up card).', 'beaver-press' );
		/* translators: 1: site name, 2: headline */
		$subject = sprintf( __( '[%1$s] %2$s', 'beaver-press' ), $site, $headline );
		return (bool) wp_mail( (string) get_option( 'admin_email' ), $subject, implode( "\n", $lines ) );
	}

	/**
	 * Notice on every wp-admin screen for administrators.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$problem = self::current();
		if ( ! $problem ) {
			return;
		}
		$token = $problem['sig'] . '@' . $problem['since'];
		if ( get_user_meta( get_current_user_id(), self::HIDDEN_META, true ) === $token ) {
			return;
		}
		list( $headline, $todo ) = self::explain( $problem );
		$hide = wp_nonce_url( admin_url( 'admin-post.php?action=bp_alert_hide&token=' . rawurlencode( $token ) ), 'bp_alert_hide' );
		?>
		<div class="notice notice-error bp-alert">
			<p><strong><?php echo esc_html( 'Beaver Press: ' . $headline ); ?></strong> <?php echo esc_html( $todo ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=trp_machine_translation' ) ); ?>"><?php esc_html_e( 'Engine settings', 'beaver-press' ); ?></a>
				<a href="<?php echo esc_url( $hide ); ?>"><?php esc_html_e( 'Hide until something changes', 'beaver-press' ); ?></a>
				<span class="description">
					<?php
					/* translators: 1: time ago, 2: provider's message */
					echo esc_html( sprintf( __( 'Since %1$s ago. Provider\'s message: %2$s', 'beaver-press' ), human_time_diff( (int) $problem['since'] ), wp_strip_all_tags( (string) ( $problem['message'] ?? '' ) ) ) );
					?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Hide the notice for this administrator until the problem changes.
	 */
	public static function hide() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_alert_hide' );
		update_user_meta( get_current_user_id(), self::HIDDEN_META, sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
