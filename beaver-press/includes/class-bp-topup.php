<?php
/**
 * Daily top-up: pages that are almost translated are finished.
 *
 * A page can lose its "complete" state without anyone editing it: a theme box that prints
 * today's date, a new widget text, a menu item. Visitors never start paid translation, so such a
 * page would stay on the original-language fallback. Once a day (and a minute later again until
 * the list is done) this visits, as a signed run request, only the pages whose record says texts
 * are missing: a few short texts, a fraction of a cent. Each page is tried once per day, so a
 * text the provider refuses never loops.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Top-up.
 */
final class BP_Topup {

	/** Switch (yes|no, default yes). */
	const OPTION = 'beaver_press_topup';

	/** Day, pages tried, pages completed, last time, reason. */
	const STATE_OPTION = 'beaver_press_topup_state';

	/** Daily event. */
	const CRON = 'beaver_press_topup';

	/** Follow-up slice a minute later. */
	const MORE_CRON = 'beaver_press_topup_more';

	/** Seconds per slice. */
	const SECONDS = 20;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( self::MORE_CRON, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Whether the top-up is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_topup_enabled', 'no' !== get_option( self::OPTION, 'yes' ) );
	}

	/**
	 * Daily event while on (early morning, site time); none while off.
	 */
	public static function schedule() {
		$next = wp_next_scheduled( self::CRON );
		if ( ! self::enabled() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON );
				wp_clear_scheduled_hook( self::MORE_CRON );
			}
			return;
		}
		if ( ! $next ) {
			$at = new DateTimeImmutable( 'tomorrow 04:00', wp_timezone() );
			wp_schedule_event( $at->getTimestamp(), 'daily', self::CRON );
		}
	}

	/**
	 * Language => original URLs with texts missing (from the per-page records).
	 *
	 * @return array[] List of [ language, url ].
	 */
	public static function incomplete() {
		$map   = BP_Complete::map( true );
		$items = array();
		foreach ( BP_Run::pages() as $page ) {
			$key = BP_Complete::key( $page['url'] );
			foreach ( array_keys( BP_Run::languages() ) as $language ) {
				if ( ! empty( $map[ $language ][ $key ] ) ) {
					$items[] = array( $language, $page['url'] );
				}
			}
		}
		return $items;
	}

	/**
	 * One slice: visit untried incomplete pages for SECONDS, then a minute later the rest.
	 *
	 * @param int $seconds Time for this slice.
	 * @return array State.
	 */
	public static function tick( $seconds = self::SECONDS ) {
		$state = self::state();
		$today = wp_date( 'Y-m-d' );
		if ( $state['day'] !== $today ) {
			$state = array_merge( $state, array( 'day' => $today, 'tried' => array(), 'visited' => 0, 'completed' => 0 ) );
		}
		$reason = self::reason();
		if ( '' !== $reason ) {
			$state['reason'] = $reason;
			$state['time']   = time();
			self::save( $state );
			return $state;
		}
		$start = microtime( true );
		$left  = 0;
		$done  = false;
		foreach ( self::incomplete() as list( $language, $url ) ) {
			$id = md5( $language . '|' . $url );
			if ( isset( $state['tried'][ $id ] ) ) {
				continue;
			}
			if ( ( microtime( true ) - $start ) >= $seconds || '' !== self::reason() ) {
				$left++;
				continue;
			}
			$state['tried'][ $id ] = 1;
			$state['visited']++;
			if ( true === BP_Run::fetch( BP_Run::url_in( $url, $language ) ) ) {
				$map = BP_Complete::map( true );
				if ( empty( $map[ $language ][ BP_Complete::key( $url ) ] ) ) {
					$state['completed']++;
					$done = true;
				}
			}
		}
		$state['reason'] = '';
		$state['left']   = $left;
		$state['time']   = time();
		self::save( $state );
		if ( $done ) {
			BP_Cache::flush(); // Pages that were shown in the original language are now complete.
		}
		if ( $left && ! wp_next_scheduled( self::MORE_CRON ) ) {
			wp_schedule_single_event( time() + 60, self::MORE_CRON );
		}
		return $state;
	}

	/**
	 * Why nothing may be sent now ('' = go).
	 *
	 * @return string
	 */
	private static function reason() {
		if ( ! self::enabled() ) {
			return __( 'Switched off.', 'beaver-press' );
		}
		$blocker = BP_Run::blocker();
		if ( '' !== $blocker ) {
			return $blocker;
		}
		$run = BP_Run::state();
		if ( in_array( $run['status'], array( 'running', 'paused' ), true ) ) {
			return __( 'A Translate-site run is going; it finishes these pages itself.', 'beaver-press' );
		}
		$daily = BP_Usage::daily_limit();
		if ( $daily['limit'] > 0 && $daily['used'] >= $daily['limit'] ) {
			return __( 'The daily limit is reached; tried again tomorrow.', 'beaver-press' );
		}
		$over = BP_Budget::reached();
		if ( '' !== $over ) {
			return $over;
		}
		// The provider refused the key or has no credit (in the last 6 hours): stop, try tomorrow.
		$error = get_option( BP_Providers::ERROR_OPTION );
		if ( is_array( $error ) && in_array( $error['code'] ?? '', BP_Run::FATAL, true ) && (int) ( $error['time'] ?? 0 ) > time() - 6 * HOUR_IN_SECONDS ) {
			$mt       = get_option( 'trp_machine_translation_settings', array() );
			$provider = BP_Engine_Settings::current( is_array( $mt ) ? $mt : array() )['provider'];
			if ( '' === (string) ( $error['provider'] ?? '' ) || $error['provider'] === $provider ) {
				/* translators: %s: provider error */
				return sprintf( __( 'Stopped: %s', 'beaver-press' ), $error['message'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Stored state.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return array_merge(
			array( 'day' => '', 'tried' => array(), 'visited' => 0, 'completed' => 0, 'left' => 0, 'time' => 0, 'reason' => '' ),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Save the state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Status line for the Set-up card.
	 *
	 * @return string
	 */
	public static function status() {
		$state = self::state();
		if ( ! $state['time'] ) {
			return __( 'Runs once a day; nothing to do yet.', 'beaver-press' );
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $state['time'] );
		if ( '' !== $state['reason'] ) {
			/* translators: 1: date, 2: reason. */
			return sprintf( __( 'Last check %1$s: %2$s', 'beaver-press' ), $when, $state['reason'] );
		}
		/* translators: 1: date, 2: pages visited, 3: pages completed. */
		return sprintf( __( 'Last check %1$s: %2$d pages topped up, %3$d now complete.', 'beaver-press' ), $when, $state['visited'], $state['completed'] );
	}
}
