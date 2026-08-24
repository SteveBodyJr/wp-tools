<?php
/**
 * Metrics storage.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every number the dashboard shows, and nothing more than that.
 *
 * Three rows, total, and none of them grow without bound:
 *
 * - `beaver_chameleon_stats` — one small option, three integers, autoloaded
 *   `no` because nothing on the front end ever reads it.
 * - `beaver_chameleon_today_{Y-m-d}` — a transient with a one-day expiry, so
 *   "blocks today" needs no daily cron to reset it and leaves nothing behind
 *   once the day it describes has passed.
 * - `beaver_chameleon_log` — one option holding the last ten blocks. The
 *   eleventh push evicts the oldest, so this table never grows past ten rows
 *   no matter how long the plugin runs.
 *
 * @since 1.0.0
 */
class Beaver_Chameleon_Stats {

	/**
	 * Option key for the running totals.
	 */
	const OPTION_TOTALS = 'beaver_chameleon_stats';

	/**
	 * Option key for the recent-blocks log.
	 */
	const OPTION_LOG = 'beaver_chameleon_log';

	/**
	 * Transient key prefix for the self-expiring daily counter.
	 */
	const TRANSIENT_TODAY = 'beaver_chameleon_today_';

	/**
	 * How many rows the recent-blocks log keeps.
	 */
	const LOG_LIMIT = 10;

	/**
	 * Records one block: bumps the totals, the daily counter, and the log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason Which trap caught it — 'honeypot' or 'behavior'.
	 */
	public static function record( $reason ) {
		$reason = in_array( $reason, array( 'honeypot', 'behavior' ), true ) ? $reason : 'behavior';

		$totals            = self::totals();
		$totals['total']  += 1;
		$totals[ $reason ] += 1;
		update_option( self::OPTION_TOTALS, $totals, false );

		$today_key = self::TRANSIENT_TODAY . gmdate( 'Y-m-d' );
		set_transient( $today_key, ( (int) get_transient( $today_key ) ) + 1, DAY_IN_SECONDS );

		$log = self::recent_log();
		array_unshift(
			$log,
			array(
				'time'   => time(),
				'ip'     => self::masked_ip(),
				'reason' => $reason,
			)
		);
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * Running totals: total, honeypot, behavior.
	 *
	 * @since 1.0.0
	 *
	 * @return array{total:int,honeypot:int,behavior:int}
	 */
	public static function totals() {
		$defaults = array(
			'total'    => 0,
			'honeypot' => 0,
			'behavior' => 0,
		);

		$stored = get_option( self::OPTION_TOTALS, array() );

		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * How many blocks happened today.
	 *
	 * Reads the self-expiring transient rather than filtering the log, so the
	 * count stays correct even once a day's blocks have scrolled off the
	 * ten-row log.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function today() {
		return (int) get_transient( self::TRANSIENT_TODAY . gmdate( 'Y-m-d' ) );
	}

	/**
	 * The last ten blocks, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{time:int,ip:string,reason:string}>
	 */
	public static function recent_log() {
		$log = get_option( self::OPTION_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clears every number this plugin has stored. Used by the dashboard's
	 * reset action and by uninstall.
	 *
	 * @since 1.0.0
	 */
	public static function reset() {
		delete_option( self::OPTION_TOTALS );
		delete_option( self::OPTION_LOG );
		delete_transient( self::TRANSIENT_TODAY . gmdate( 'Y-m-d' ) );
	}

	/**
	 * The requester's IP with the identifying part removed.
	 *
	 * IPv4 loses its last octet (`203.0.113.xxx`); IPv6 keeps only its first
	 * two groups (`2001:db8:****`). Enough to spot a repeat offender's network
	 * without the log becoming a list of full addresses.
	 *
	 * Reads REMOTE_ADDR only — the one part of the request a client cannot
	 * spoof by sending a header — rather than trusting a forwarded-for header
	 * that a proxy did not actually set.
	 *
	 * @since 1.0.0
	 *
	 * @return string Masked IP, or 'unknown'.
	 */
	private static function masked_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return __( 'unknown', 'beaver-chameleon' );
		}

		if ( false !== strpos( $ip, ':' ) ) {
			$groups = array_slice( explode( ':', $ip ), 0, 2 );
			return implode( ':', $groups ) . ':****';
		}

		$octets = explode( '.', $ip );
		if ( 4 === count( $octets ) ) {
			$octets[3] = 'xxx';
			return implode( '.', $octets );
		}

		return __( 'unknown', 'beaver-chameleon' );
	}
}
