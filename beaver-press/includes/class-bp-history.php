<?php
/**
 * Settings history: the last copies of the Beaver Press and engine settings, so a bad save
 * (or anything else that changed them) can be undone from the Set-up page.
 *
 * A copy is kept after each wp-admin save by an administrator that changed something, with
 * the time and who saved. Never kept: API keys (BP_Keys), translations, the language list
 * (restoring it would hide translations), the engine's daily counter, and working data such
 * as AI tags, run state or usage.
 *
 * Restore writes the copy back. The translated addresses switch is left as it is when it
 * differs (turning it on starts work that the Set-up card's own Save handles).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * History.
 */
final class BP_History {

	/** Copies, newest first: [ [ time, user, data ] ]. */
	const OPTION = 'beaver_press_history';

	/** Copies kept. */
	const KEEP = 10;

	/** Engine settings that are working data, not settings. */
	const ENGINE_SKIP = array( 'machine_translation_counter_date' );

	/**
	 * Engine settings left out of a copy and kept as they are on restore: working data and
	 * any key field of the engine's own (google-translate-key, deepl-api-key...).
	 *
	 * @param array $mt Engine settings.
	 * @return array The part that goes in a copy.
	 */
	private static function engine_part( array $mt ) {
		foreach ( array_keys( $mt ) as $key ) {
			if ( in_array( $key, self::ENGINE_SKIP, true ) || preg_match( '/key$/i', (string) $key ) ) {
				unset( $mt[ $key ] );
			}
		}
		ksort( $mt );
		return $mt;
	}

	/** Names data that is working data, not the owner's choice. */
	const NAMES_SKIP = array( 'ai', 'seen' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'shutdown', array( __CLASS__, 'maybe_record' ) );
		add_action( 'admin_post_bp_history_restore', array( __CLASS__, 'restore_request' ) );
	}

	/**
	 * Options in a copy (each written back as a whole).
	 *
	 * @return string[]
	 */
	public static function options() {
		return array(
			BP_Auto::OPTION,
			BP_Topup::OPTION,
			BP_Cache::OPTION,
			BP_Complete::OPTION,
			BP_Suggest::OPTION,
			BP_Suggest::REDIRECT_OPTION,
			BP_Slugs::OPTION,
			BP_Quiet::OPTION,
			BP_Alerts::OPTION,
			BP_Budget::OPTION,
			BP_Usage::PRICES_OPTION,
			BP_Instructions::OPTION,
			BP_Profiles::OPTION,
			BP_Slug_Bases::OPTION,
		);
	}

	/**
	 * The settings now.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$mt = get_option( 'trp_machine_translation_settings', array() );
		$mt = self::engine_part( is_array( $mt ) ? $mt : array() );
		$names = get_option( BP_Glossary::NAMES_OPTION, null );
		if ( is_array( $names ) ) {
			$names = array_diff_key( $names, array_flip( self::NAMES_SKIP ) );
			ksort( $names );
		}
		$options = array();
		foreach ( self::options() as $name ) {
			$options[ $name ] = get_option( $name, null );
		}
		return array(
			'engine'  => $mt,
			'names'   => $names,
			'options' => $options,
		);
	}

	/**
	 * Copies, newest first.
	 *
	 * @return array
	 */
	public static function entries() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Keep a copy when the settings differ from the newest one.
	 *
	 * @param string $who Who saved.
	 * @return bool Whether a copy was added.
	 */
	public static function record( $who ) {
		$now  = self::snapshot();
		$list = self::entries();
		if ( $list && $list[0]['data'] === $now ) {
			return false;
		}
		array_unshift(
			$list,
			array(
				'time' => time(),
				'user' => (string) $who,
				'data' => $now,
			)
		);
		update_option( self::OPTION, array_slice( $list, 0, self::KEEP ), false );
		return true;
	}

	/**
	 * After a wp-admin save by an administrator.
	 */
	public static function maybe_record() {
		if ( ! is_admin() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || wp_doing_cron() || ! function_exists( 'wp_get_current_user' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::record( wp_get_current_user()->display_name );
	}

	/**
	 * What differs between a copy and now, as setting names for people.
	 *
	 * @param array $data Copy.
	 * @return string[]
	 */
	public static function differences( array $data ) {
		$now  = self::snapshot();
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( (array) $data['engine'] ), array_keys( $now['engine'] ) ) ) as $key ) {
			if ( ( $data['engine'][ $key ] ?? null ) !== ( $now['engine'][ $key ] ?? null ) ) {
				$diff[] = $key;
			}
		}
		if ( ( $data['names'] ?? null ) !== $now['names'] ) {
			$diff[] = __( 'names to keep', 'beaver-press' );
		}
		foreach ( (array) $data['options'] as $name => $value ) {
			if ( ( $now['options'][ $name ] ?? null ) !== $value ) {
				$diff[] = (string) preg_replace( '/^beaver_press_/', '', $name );
			}
		}
		return $diff;
	}

	/**
	 * Write a copy back.
	 *
	 * @param int $index Copy number (0 = newest).
	 * @return array|WP_Error What was restored, what was left.
	 */
	public static function restore( $index ) {
		$list = self::entries();
		if ( ! isset( $list[ $index ] ) ) {
			return new WP_Error( 'bp_history_missing', __( 'That copy no longer exists.', 'beaver-press' ) );
		}
		$data = $list[ $index ]['data'];
		$left = array();

		$mt   = get_option( 'trp_machine_translation_settings', array() );
		$mt   = is_array( $mt ) ? $mt : array();
		$keep = array_diff_key( $mt, self::engine_part( $mt ) ); // Working data and key fields stay.
		update_option( 'trp_machine_translation_settings', (array) $data['engine'] + $keep );

		$names = get_option( BP_Glossary::NAMES_OPTION, null );
		$work  = is_array( $names ) ? array_intersect_key( $names, array_flip( self::NAMES_SKIP ) ) : array();
		if ( null === $data['names'] ) {
			delete_option( BP_Glossary::NAMES_OPTION );
		} else {
			update_option( BP_Glossary::NAMES_OPTION, (array) $data['names'] + $work, false );
		}

		foreach ( (array) $data['options'] as $name => $value ) {
			if ( ! in_array( $name, self::options(), true ) ) {
				continue;
			}
			if ( BP_Slugs::OPTION === $name && get_option( $name, null ) !== $value ) {
				$left[] = __( 'Translated addresses: left as it is; change it on the Set-up card if needed.', 'beaver-press' );
				continue;
			}
			null === $value ? delete_option( $name ) : update_option( $name, $value, false );
		}
		BP_Glossary::flush();
		BP_Topup::schedule();
		BP_Cache::clear(); // Pages kept ready were built with the old settings.
		return array(
			'time' => (int) $list[ $index ]['time'],
			'left' => $left,
		);
	}

	/**
	 * Restore button.
	 */
	public static function restore_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_history_restore' );
		$result = self::restore( absint( $_POST['index'] ?? -1 ) );
		set_transient(
			'bp_history_result_' . get_current_user_id(),
			is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result,
			60
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '#bp-history' ) );
		exit;
	}

	/**
	 * Card under "Copy these settings to another site".
	 */
	public static function render() {
		$list   = self::entries();
		$result = get_transient( 'bp_history_result_' . get_current_user_id() );
		if ( false !== $result ) {
			delete_transient( 'bp_history_result_' . get_current_user_id() );
		}
		?>
		<div class="bp-card" id="bp-history">
			<h2><?php esc_html_e( 'Settings history', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A copy of the Beaver Press and engine settings is kept each time an administrator saves a change (the last 10). Restore puts a copy back. Never in a copy: API keys, translations and the language list.', 'beaver-press' ); ?></p>
			<?php if ( is_array( $result ) && isset( $result['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $result['error'] ); ?></p></div>
			<?php elseif ( is_array( $result ) && isset( $result['time'] ) ) : ?>
				<div class="notice notice-success inline"><p>
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Settings restored to the copy of %s.', 'beaver-press' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $result['time'] ) ) );
					foreach ( (array) $result['left'] as $line ) {
						echo ' ' . esc_html( $line );
					}
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( ! $list ) : ?>
				<p><?php esc_html_e( 'No copies yet: the first one is kept with the next save.', 'beaver-press' ); ?></p>
			<?php else : ?>
				<table class="widefat striped bp-history">
					<thead><tr><th><?php esc_html_e( 'Saved', 'beaver-press' ); ?></th><th><?php esc_html_e( 'By', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Different from now', 'beaver-press' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $list as $i => $entry ) : ?>
							<?php $diff = self::differences( (array) $entry['data'] ); ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( (string) $entry['user'] ); ?></td>
								<td><?php echo $diff ? esc_html( implode( ', ', $diff ) ) : '<em>' . esc_html__( 'same as now', 'beaver-press' ) . '</em>'; ?></td>
								<td>
									<?php if ( $diff ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="bp_history_restore" />
											<input type="hidden" name="index" value="<?php echo esc_attr( (string) $i ); ?>" />
											<?php wp_nonce_field( 'bp_history_restore' ); ?>
											<button class="button button-small" onclick="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?php esc_attr_e( 'Put these settings back? API keys and translations are not touched.', 'beaver-press' ); ?>"><?php esc_html_e( 'Restore', 'beaver-press' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
