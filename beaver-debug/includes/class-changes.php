<?php
/**
 * Change correlation and the fleet digest.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records what changed, and reports the site's state home.
 *
 * "Errors started three hours ago" is a clue. "Errors started immediately
 * after WooCommerce updated" is a diagnosis. Recording updates and activations
 * into the same timeline as the failures is what turns one into the other.
 *
 * @since 1.1.0
 */
class Beaver_Debug_Changes {

	/**
	 * Registers hooks.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrade' ), 10, 2 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ) );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme_switch' ) );

		add_action( 'beaver_debug_digest', array( __CLASS__, 'send_digest' ) );
	}

	/**
	 * Records a completed update.
	 *
	 * @since 1.1.0
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $data     What was updated.
	 */
	public static function on_upgrade( $upgrader, $data ) {
		unset( $upgrader );

		if ( ! is_array( $data ) || empty( $data['type'] ) ) {
			return;
		}

		$items = array();

		if ( 'plugin' === $data['type'] ) {
			$items = isset( $data['plugins'] ) ? (array) $data['plugins'] : array();
			$items = array_map( 'dirname', $items );
		} elseif ( 'theme' === $data['type'] ) {
			$items = isset( $data['themes'] ) ? (array) $data['themes'] : array();
		} elseif ( 'core' === $data['type'] ) {
			$items = array( 'WordPress ' . get_bloginfo( 'version' ) );
		}

		if ( empty( $items ) ) {
			return;
		}

		self::record(
			sprintf(
				/* translators: 1: what was updated, 2: the names. */
				__( 'Updated %1$s: %2$s', 'beaver-debug' ),
				$data['type'],
				implode( ', ', array_slice( $items, 0, 8 ) )
			)
		);
	}

	/**
	 * Records a plugin being switched on.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_activated( $plugin ) {
		/* translators: %s: plugin folder name. */
		self::record( sprintf( __( 'Activated plugin: %s', 'beaver-debug' ), dirname( (string) $plugin ) ) );
	}

	/**
	 * Records a plugin being switched off.
	 *
	 * @since 1.1.0
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_deactivated( $plugin ) {
		/* translators: %s: plugin folder name. */
		self::record( sprintf( __( 'Deactivated plugin: %s', 'beaver-debug' ), dirname( (string) $plugin ) ) );
	}

	/**
	 * Records a theme change.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name New theme name.
	 */
	public static function on_theme_switch( $name ) {
		/* translators: %s: theme name. */
		self::record( sprintf( __( 'Switched theme to: %s', 'beaver-debug' ), (string) $name ) );
	}

	/**
	 * Writes a change into the same log as the failures.
	 *
	 * @since 1.1.0
	 *
	 * @param string $message What changed.
	 */
	private static function record( $message ) {
		Beaver_Debug_Logger::write(
			array(
				// Changes are never grouped with each other: each one is its own
				// moment on the timeline, which is the entire value.
				'signature' => md5( 'change|' . $message . '|' . microtime() ),
				'time'      => time(),
				'type'      => 'change',
				'severity'  => 'change',
				'message'   => $message,
				'file'      => '',
				'line'      => 0,
				'source'    => __( 'site change', 'beaver-debug' ),
				'context'   => array(
					'where' => 'admin',
					'user'  => get_current_user_id(),
				),
			)
		);
	}

	/**
	 * Posts a summary of this site to a central endpoint.
	 *
	 * Eleven sites means eleven logins to find out which one is unhappy. A
	 * small daily payload to one place turns that into a single page.
	 *
	 * @since 1.1.0
	 */
	public static function send_digest() {
		$url = (string) Beaver_Debug_Settings::get( 'hub_url', '' );

		if ( '' === $url ) {
			return;
		}

		$summary = Beaver_Debug_Logger::summary( time() - DAY_IN_SECONDS );
		$top     = array();

		foreach ( Beaver_Debug_Logger::read( 200 ) as $group ) {
			if ( ! in_array( $group['severity'], array( 'fatal', 'db' ), true ) ) {
				continue;
			}

			$top[] = array(
				'severity' => $group['severity'],
				'message'  => mb_substr( $group['message'], 0, 200 ),
				'source'   => $group['source'],
				'count'    => $group['count'],
				'last'     => $group['last'],
			);

			if ( count( $top ) >= 5 ) {
				break;
			}
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 10,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'key'         => (string) Beaver_Debug_Settings::get( 'hub_key', '' ),
						'site'        => home_url( '/' ),
						'name'        => get_bloginfo( 'name' ),
						'generated'   => time(),
						'wp'          => get_bloginfo( 'version' ),
						'php'         => PHP_VERSION,
						'memory'      => (string) ini_get( 'memory_limit' ),
						'plugins'     => count( (array) get_option( 'active_plugins', array() ) ),
						'theme'       => (string) get_stylesheet(),
						'counts'      => $summary,
						'problems'    => $top,
						'plugin_ver'  => BEAVER_DEBUG_VERSION,
					)
				),
			)
		);
	}
}
