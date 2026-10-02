<?php
/**
 * Auto-update: content saved in wp-admin gets its translations refreshed in the background.
 *
 * When a published post, page, tour, destination or stay is saved, its address is queued;
 * about a minute later WP-Cron visits it once per started language as the signed
 * Translate-site run, so only new or changed text is sent. A "started" language is one that
 * already has translations: editing one tour never starts a whole new language. Respects
 * the daily limit and waits while no key is saved.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Auto-update.
 */
final class BP_Auto {

	/** Option: 'yes' (default) or 'no'. */
	const OPTION = 'beaver_press_auto';

	/** Queue of URLs (not autoloaded). */
	const QUEUE_OPTION = 'beaver_press_auto_queue';

	/** Last result, for the admin page. */
	const LAST_OPTION = 'beaver_press_auto_last';

	/** Cron hook. */
	const CRON = 'beaver_press_auto_tick';

	/** Pages visited per tick (each once per started language). */
	const PER_TICK = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		if ( self::enabled() ) {
			add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 3 );
		}
	}

	/**
	 * Whether auto-update is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_auto', 'no' !== get_option( self::OPTION, 'yes' ) );
	}

	/**
	 * Queue a saved post's address.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 */
	public static function on_save( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$urls = (array) apply_filters( 'beaver_press_auto_urls', array( get_permalink( $post ) ), $post );
		self::queue( $urls );
	}

	/**
	 * Add URLs to the queue and schedule a tick.
	 *
	 * @param string[] $urls URLs in the default language.
	 */
	public static function queue( array $urls ) {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = array_values( array_unique( array_merge( is_array( $queue ) ? $queue : array(), array_filter( array_map( 'strval', $urls ) ) ) ) );
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 60, self::CRON );
		}
	}

	/**
	 * Languages that already have translations (and are offered to visitors).
	 *
	 * @return string[]
	 */
	public static function started_languages() {
		global $wpdb;
		$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$out   = array();
		foreach ( array_keys( BP_Run::languages() ) as $code ) {
			$table = $query->get_table_name( $code );
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TranslatePress.
			if ( $wpdb->get_var( "SELECT 1 FROM `{$table}` WHERE status <> 0 LIMIT 1" ) ) {
				$out[] = $code;
			}
		}
		return (array) apply_filters( 'beaver_press_auto_languages', $out );
	}

	/**
	 * Visit queued pages in every started language (a few per tick; the rest next minute).
	 */
	public static function tick() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( ! $queue ) {
			return;
		}
		$reason = '';
		if ( '' !== BP_Run::blocker() ) {
			$reason = BP_Run::blocker();
		} else {
			$daily = BP_Usage::daily_limit();
			if ( $daily['limit'] > 0 && $daily['used'] >= $daily['limit'] ) {
				$reason = __( 'The daily limit is reached; waiting for tomorrow.', 'beaver-press' );
			}
		}
		$languages = '' === $reason ? self::started_languages() : array();
		if ( '' === $reason && ! $languages ) {
			$reason = __( 'No language started yet: run "Translate site" once for a language.', 'beaver-press' );
		}
		if ( '' !== $reason ) {
			self::remember( 0, $reason );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CRON ); // Try again later; the queue is kept.
			return;
		}

		$batch = array_slice( $queue, 0, self::PER_TICK );
		$done  = 0;
		foreach ( $batch as $url ) {
			foreach ( $languages as $language ) {
				if ( true === BP_Run::fetch( BP_Run::url_in( $url, $language ) ) ) {
					$done++;
				}
			}
		}
		// Re-read: pages saved during this tick were added meanwhile.
		$now = get_option( self::QUEUE_OPTION, array() );
		update_option( self::QUEUE_OPTION, array_values( array_diff( is_array( $now ) ? $now : array(), $batch ) ), false );
		self::remember( $done, '' );
		if ( get_option( self::QUEUE_OPTION ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 60, self::CRON );
		}
	}

	/**
	 * Keep the last result for the admin page.
	 *
	 * @param int    $pages  Pages visited.
	 * @param string $reason Why nothing happened, or ''.
	 */
	private static function remember( $pages, $reason ) {
		update_option(
			self::LAST_OPTION,
			array(
				'time'   => time(),
				'pages'  => (int) $pages,
				'reason' => (string) $reason,
			),
			false
		);
	}

	/**
	 * Status line for the admin page.
	 *
	 * @return string
	 */
	public static function status() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$last  = get_option( self::LAST_OPTION );
		$parts = array();
		$parts[] = sprintf(
			/* translators: %d: pages waiting */
			_n( '%d page waiting', '%d pages waiting', count( (array) $queue ), 'beaver-press' ),
			count( (array) $queue )
		);
		if ( is_array( $last ) ) {
			$parts[] = '' !== $last['reason']
				? $last['reason']
				: sprintf(
					/* translators: 1: time ago, 2: page visits */
					__( 'last update %1$s ago (%2$d page visits)', 'beaver-press' ),
					human_time_diff( (int) $last['time'] ),
					(int) $last['pages']
				);
		}
		$langs = self::started_languages();
		if ( $langs ) {
			$names   = BP_Run::languages();
			$parts[] = sprintf(
				/* translators: %s: language names */
				__( 'kept up to date: %s', 'beaver-press' ),
				implode( ', ', array_map( static fn( $c ) => $names[ $c ] ?? $c, $langs ) )
			);
		}
		return implode( ' · ', $parts );
	}
}
