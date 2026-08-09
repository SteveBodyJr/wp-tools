<?php
/**
 * The blackout itself.
 *
 * @package BeaverShutter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Closes the front end and draws the holding page.
 *
 * Everything here happens on `template_redirect`, which fires only for normal
 * front-end requests. wp-admin, the login screen, WP-CLI, cron, admin-ajax and
 * the REST API are never reached by this code, so the site's owner keeps every
 * bit of control they had — they simply cannot be seen by the public while the
 * shutter is down. Nothing is written, deleted or altered; the page is replaced
 * in the response and nowhere else.
 *
 * @since 1.0.0
 */
class Beaver_Shutter_Lockdown {

	const PREVIEW_ARG = 'bs_preview';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		// Priority 0: decide before the theme has done any work.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_close' ), 0 );
	}

	/**
	 * Whether the shutter should act on this request at all.
	 *
	 * The wp-config constant is checked first and on purpose: it is the switch
	 * that works when nothing else does. Setting `BEAVER_SHUTTER_OFF` to true in
	 * wp-config.php reopens the site whatever the database says, so a site can
	 * never be stuck dark.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True to enforce.
	 */
	public static function should_enforce() {
		if ( defined( 'BEAVER_SHUTTER_OFF' ) && BEAVER_SHUTTER_OFF ) {
			return false;
		}

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		return Beaver_Shutter_Settings::is_closed();
	}

	/**
	 * Closes the front end when it should be closed.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_close() {
		$preview = isset( $_GET[ self::PREVIEW_ARG ] ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $preview ) {
			self::render_holding_page( true );
			exit;
		}

		if ( ! self::should_enforce() ) {
			return;
		}

		/**
		 * Filters whether this particular request bypasses the shutter.
		 *
		 * Lets a theme or a companion plugin keep, say, a status endpoint
		 * reachable while everything else is dark.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $bypass Whether to leave this request alone.
		 */
		if ( apply_filters( 'beaver_shutter_bypass', false ) ) {
			return;
		}

		// "Closed to visitors" lets signed-in users straight through to the
		// real site; "Dark" shows the holding page to everyone.
		if ( Beaver_Shutter_Settings::VISITORS === Beaver_Shutter_Settings::get( 'level' ) && is_user_logged_in() ) {
			return;
		}

		self::render_holding_page( false );
		exit;
	}

	/**
	 * Prints the holding page.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $preview Whether an administrator asked to see it.
	 */
	private static function render_holding_page( $preview ) {
		$settings = Beaver_Shutter_Settings::all();

		$title = '' !== $settings['title']
			? $settings['title']
			: __( 'We will be right back', 'beaver-shutter' );

		$body = '' !== $settings['body']
			? $settings['body']
			: __( 'This site is temporarily closed for maintenance. Please check back shortly.', 'beaver-shutter' );

		$contact = (string) $settings['contact'];
		$staff   = current_user_can( 'manage_options' );

		nocache_headers();

		if ( ! $preview ) {
			/*
			 * 503, not 403 or 200. A 503 with Retry-After tells search engines
			 * the site is temporarily unavailable and to come back, so a closed
			 * site does not lose its place in the index the way a 200 holding
			 * page or a 404 would.
			 */
			status_header( 503 );
			header( 'Retry-After: ' . (int) $settings['retry_after'] );
		}

		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex" />
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		html,body{margin:0;padding:0;min-height:100%}
		body{display:flex;align-items:center;justify-content:center;padding:24px;
			background:#f6f7f7;color:#1d2327;
			font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
		.bs-card{max-width:34em;width:100%;padding:36px 32px;background:#fff;
			border:1px solid #dcdcde;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
		.bs-card h1{margin:0 0 12px;font-size:24px;line-height:1.3}
		.bs-card p{margin:0 0 12px}
		.bs-card p:last-child{margin-bottom:0}
		.bs-contact{margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f1;font-size:14px;color:#50575e}
		.bs-staff{margin-top:20px;padding:14px 16px;background:#fcf9e8;border-left:4px solid #dba617;font-size:14px}
		.bs-staff a{color:#2271b1}
	</style>
</head>
<body>
	<div class="bs-card">
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo wpautop( wp_kses_post( $body ) ); ?>

		<?php if ( '' !== $contact ) : ?>
			<p class="bs-contact">
				<?php
				printf(
					/* translators: %s: contact details. */
					esc_html__( 'Need to reach us? %s', 'beaver-shutter' ),
					esc_html( $contact )
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( $staff ) : ?>
			<div class="bs-staff">
				<?php if ( $preview ) : ?>
					<strong><?php esc_html_e( 'Preview.', 'beaver-shutter' ); ?></strong>
					<?php esc_html_e( 'This is what visitors see while the shutter is down. The site itself is unchanged.', 'beaver-shutter' ); ?>
				<?php else : ?>
					<strong><?php esc_html_e( 'Your visitors are seeing this page.', 'beaver-shutter' ); ?></strong>
					<?php esc_html_e( 'The site returns to normal the moment you reopen it.', 'beaver-shutter' ); ?>
				<?php endif; ?>
				<br />
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . BEAVER_SHUTTER_SLUG ) ); ?>">
					<?php esc_html_e( 'Open the shutter controls', 'beaver-shutter' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</body>
</html>
		<?php
	}
}
