<?php
/**
 * Audit log.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records every attempt, successful or not.
 *
 * A login mechanism without an audit trail is a liability: if you cannot show
 * who came in and when, you cannot answer the only question that matters after
 * an incident.
 *
 * @since 1.0.0
 */
class Beaver_Access_Log {

	const OPTION = 'beaver_access_log';
	const MAX    = 300;

	/**
	 * Writes one entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $link_id Link involved.
	 * @param string $event   created, used, denied, revoked.
	 * @param string $ip      Address.
	 * @param string $detail  Extra detail.
	 */
	public static function record( $link_id, $event, $ip = '', $detail = '' ) {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		$log[] = array(
			'time'   => time(),
			'link'   => (int) $link_id,
			'event'  => sanitize_key( $event ),
			'ip'     => $ip,
			'detail' => sanitize_text_field( $detail ),
			'agent'  => isset( $_SERVER['HTTP_USER_AGENT'] )
				? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 )
				: '',
			'user'   => get_current_user_id(),
		);

		// Not autoloaded and hard-capped: an audit trail must not become a
		// performance problem on every page load.
		update_option( self::OPTION, array_slice( $log, -self::MAX ), false );
	}

	/**
	 * Returns entries, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array> Entries.
	 */
	public static function all( $limit = 100 ) {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? array_reverse( $log ) : array();

		return array_slice( $log, 0, (int) $limit );
	}

	/**
	 * Empties the log.
	 *
	 * @since 1.0.0
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
