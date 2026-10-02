<?php
/**
 * Translation provenance and source-change tracking.
 *
 * One row per language and source text (md5 of the original) in the table
 * {prefix}bp_translation_meta, next to the engine's own tables (which stay unchanged):
 * status (machine_translated, manually_edited, outdated), provider, model, type (regular or
 * gettext), the source hash, who edited it and when.
 *
 * - Machine translations are recorded by the Beaver AI engine as they are made.
 * - An edit in the visual editor is recorded as manually_edited (edited_after_ai when the
 *   text had been machine translated first). Clearing a translation removes the row.
 * - A changed original: the engine sees a new text. When a page has a new text to translate,
 *   and a manually edited text of the same page has disappeared from it while being similar to
 *   the new one, that manual translation is carried to the new text instead of a machine
 *   translation, and marked outdated with the old source, so the owner sees what changed and
 *   decides: keep it, retranslate it with the AI (explicit action), or edit it.
 * - Automatic translation only fills empty translations, so a manually edited translation is
 *   never overwritten by it.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provenance.
 */
final class BP_Provenance {

	/** Schema version (option beaver_press_meta_db). */
	const DB_VERSION = 1;

	/** Statuses. */
	const MACHINE  = 'machine_translated';
	const MANUAL   = 'manually_edited';
	const OUTDATED = 'outdated';

	/** Similarity (percent) for "the same text, changed". */
	const SIMILAR = 70;

	/** The current machine request is the owner's explicit retranslation (no carrying over). */
	public static $explicit = false;

	/** Machine requests that are not stored translations (Try it, address drafts): not recorded. */
	public static $paused = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'install' ), 5 );
		add_action( 'trp_save_editor_translations_regular_strings', array( __CLASS__, 'editor_saved' ), 10, 2 );
		add_action( 'trp_save_editor_translations_gettext_strings', array( __CLASS__, 'editor_saved_gettext' ), 10, 2 );
		add_action( 'admin_post_bp_changed', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bp_translation_meta';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		if ( (int) get_option( 'beaver_press_meta_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			language varchar(20) NOT NULL,
			source_hash char(32) NOT NULL,
			type varchar(10) NOT NULL DEFAULT 'regular',
			status varchar(20) NOT NULL,
			provider varchar(40) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			edited_after_ai tinyint(1) NOT NULL DEFAULT 0,
			editor bigint(20) unsigned NOT NULL DEFAULT 0,
			old_source longtext NULL,
			old_translation longtext NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lang_hash (language,source_hash),
			KEY status (status)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'beaver_press_meta_db', self::DB_VERSION, false );
	}

	/**
	 * Hash of a source text.
	 *
	 * @param string $original Original.
	 * @return string
	 */
	public static function hash( $original ) {
		return md5( (string) $original );
	}

	/**
	 * Provenance of a translation, or null.
	 *
	 * @param string $language Language.
	 * @param string $original Original.
	 * @return array|null
	 */
	public static function get( $language, $original ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE language = %s AND source_hash = %s', $language, self::hash( $original ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- own table.
		return $row ? $row : null;
	}

	/**
	 * Write a row (insert or replace the language/source pair).
	 *
	 * @param string $language Language.
	 * @param string $original Original.
	 * @param array  $data     Columns.
	 */
	public static function put( $language, $original, array $data ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$old  = self::get( $language, $original );
		$data = array_merge(
			array(
				'language'        => (string) $language,
				'source_hash'     => self::hash( $original ),
				'type'            => 'regular',
				'provider'        => '',
				'model'           => '',
				'edited_after_ai' => 0,
				'editor'          => 0,
				'old_source'      => null,
				'old_translation' => null,
				'post_id'         => 0,
				'created_at'      => $old['created_at'] ?? $now,
			),
			$data,
			array( 'updated_at' => $now )
		);
		$wpdb->replace( self::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
	}

	/**
	 * Remove a row.
	 *
	 * @param string $language Language.
	 * @param string $original Original.
	 */
	public static function forget( $language, $original ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'language' => $language, 'source_hash' => self::hash( $original ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
	}

	/* ------------------------------------------------------------------ */
	/* Recording                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Machine translations just made (Beaver AI engine).
	 *
	 * @param string   $language  Language.
	 * @param string[] $originals Originals translated.
	 * @param array    $engine    Engine settings (provider, model).
	 */
	public static function machine( $language, array $originals, array $engine ) {
		$originals = array_values( array_unique( $originals ) );
		// The engine translates page texts and theme/plugin texts through the same call: a text in
		// the page-text table is "regular", any other one comes from the theme or a plugin.
		$regular = array();
		foreach ( (array) TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_string_rows( array(), $originals, $language ) as $row ) {
			$regular[ (string) $row->original ] = true;
		}
		foreach ( $originals as $original ) {
			$old = self::get( $language, $original );
			if ( $old && self::MACHINE !== $old['status'] ) {
				continue; // A manual or outdated row is never turned back into "machine" silently.
			}
			self::put(
				$language,
				$original,
				array(
					'type'     => isset( $regular[ $original ] ) ? 'regular' : 'gettext',
					'status'   => self::MACHINE,
					'provider' => (string) ( $engine['provider'] ?? '' ),
					'model'    => 'deepl' === ( $engine['api'] ?? '' ) ? 'deepl' : (string) ( $engine['model'] ?? '' ),
					'post_id'  => self::current_post(),
				)
			);
		}
	}

	/**
	 * The visual editor saved page texts.
	 *
	 * @param array $update_strings Language => rows (original, translated, status).
	 * @param array $settings       Engine settings.
	 */
	public static function editor_saved( $update_strings, $settings = array() ) {
		self::edited( (array) $update_strings, 'regular' );
	}

	/**
	 * The visual editor saved theme or plugin texts.
	 *
	 * @param array $update_strings Language => rows.
	 * @param array $settings       Engine settings.
	 */
	public static function editor_saved_gettext( $update_strings, $settings = array() ) {
		self::edited( (array) $update_strings, 'gettext' );
	}

	/**
	 * Record manual edits.
	 *
	 * @param array  $update_strings Language => rows.
	 * @param string $type           regular or gettext.
	 */
	private static function edited( array $update_strings, $type ) {
		foreach ( $update_strings as $language => $rows ) {
			foreach ( (array) $rows as $row ) {
				$original = (string) ( $row['original'] ?? '' );
				if ( '' === $original ) {
					continue;
				}
				if ( 0 === (int) ( $row['status'] ?? 0 ) || '' === trim( (string) ( $row['translated'] ?? '' ) ) ) {
					self::forget( $language, $original );
					continue;
				}
				$old = self::get( $language, $original );
				self::put(
					$language,
					$original,
					array(
						'type'            => $type,
						'status'          => self::MANUAL,
						'provider'        => (string) ( $old['provider'] ?? '' ),
						'model'           => (string) ( $old['model'] ?? '' ),
						'edited_after_ai' => ( $old && '' !== (string) $old['provider'] ) ? 1 : 0,
						'editor'          => get_current_user_id(),
						'post_id'         => (int) ( $old['post_id'] ?? 0 ),
					)
				);
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Changed originals                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The post being translated in this request (0 when none).
	 *
	 * @return int
	 */
	private static function current_post() {
		return ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Plain text of a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
	}

	/**
	 * New texts about to be machine translated: carry a manual translation over when the
	 * text it belonged to has changed (see the class comment).
	 *
	 * @param string   $language  Language.
	 * @param string[] $originals New texts (key => original).
	 * @return array Key => carried translation (also recorded as outdated).
	 */
	public static function carry( $language, array $originals ) {
		global $wpdb;
		$post_id = self::current_post();
		$html    = class_exists( 'BP_Complete' ) ? BP_Complete::original_html() : '';
		if ( ! $post_id || '' === $html || ! apply_filters( 'beaver_press_carry_changed', true ) ) {
			return array();
		}
		$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$dict  = $query->get_table_name( $language );
		$meta  = $query->get_table_name_for_original_meta();
		// Manual translations of texts that belong to this post: the engine links a text to the
		// first post it was seen on only, so Beaver Press's own record (the post a translation was
		// made or edited on) is used as well.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT d.original, d.translated FROM `{$dict}` d INNER JOIN `{$meta}` m ON m.original_id = d.original_id AND m.meta_key = %s AND m.meta_value = %s WHERE d.status = %d AND d.translated <> ''", $query->get_meta_key_for_post_parent_id(), (string) $post_id, BP_Backup::MANUAL ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine tables.
		$own  = $wpdb->get_results( $wpdb->prepare( 'SELECT d.original, d.translated FROM `' . $dict . '` d INNER JOIN ' . self::table() . " p ON p.language = %s AND p.post_id = %d AND p.source_hash = MD5(d.original) WHERE d.status = %d AND d.translated <> ''", $language, $post_id, BP_Backup::MANUAL ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table name, own table.
		$seen = array();
		foreach ( array_merge( (array) $rows, (array) $own ) as $r ) {
			$seen[ (string) $r->original ] = $r;
		}
		$rows = array_values( $seen );
		if ( ! $rows ) {
			return array();
		}
		$page = self::plain( $html );
		// Only texts that are gone from the page have changed; the others are still in use.
		$gone = array_filter( $rows, static fn( $r ) => '' !== self::plain( $r->original ) && false === mb_strpos( $page, self::plain( $r->original ) ) );
		$out  = array();
		foreach ( $originals as $key => $new ) {
			$new_plain = self::plain( $new );
			$best      = null;
			$best_pct  = 0.0;
			foreach ( $gone as $r ) {
				$old_plain = self::plain( $r->original );
				$ratio     = mb_strlen( $new_plain ) / max( 1, mb_strlen( $old_plain ) );
				if ( $ratio < 0.5 || $ratio > 2 ) {
					continue;
				}
				similar_text( $old_plain, $new_plain, $pct );
				if ( $pct > $best_pct ) {
					$best_pct = $pct;
					$best     = $r;
				}
			}
			if ( $best && $best_pct >= (float) apply_filters( 'beaver_press_similar_percent', self::SIMILAR ) ) {
				$out[ $key ] = (string) $best->translated;
				self::put(
					$language,
					$new,
					array(
						'status'          => self::OUTDATED,
						'old_source'      => (string) $best->original,
						'old_translation' => (string) $best->translated,
						'post_id'         => $post_id,
						'edited_after_ai' => 0,
					)
				);
			}
		}
		return $out;
	}

	/**
	 * Outdated translations (newest first).
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function outdated( $limit = 200 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY updated_at DESC LIMIT %d', self::OUTDATED, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- own table.
	}

	/**
	 * Counts per language and status.
	 *
	 * @return array Language => status => count.
	 */
	public static function counts() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT language, status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY language, status' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- own table, no input.
			$out[ $r->language ][ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * The engine row of a translation (page texts) found by the meta row's hash.
	 *
	 * @param array $meta Meta row.
	 * @return object|null id, original, translated, status, block_type, original_id.
	 */
	private static function engine_row( array $meta ) {
		global $wpdb;
		$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $meta['language'] );
		return $wpdb->get_row( $wpdb->prepare( "SELECT id, original, translated, status, block_type, original_id FROM `{$table}` WHERE MD5(original) = %s LIMIT 1", $meta['source_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table.
	}

	/**
	 * Keep a carried translation (it becomes manual).
	 *
	 * @param int $id Meta row id.
	 * @return bool
	 */
	public static function keep( $id ) {
		global $wpdb;
		$meta = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- own table.
		$row  = $meta ? self::engine_row( $meta ) : null;
		if ( ! $row ) {
			return false;
		}
		TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->update_strings( array( array( 'id' => (int) $row->id, 'original' => $row->original, 'translated' => $row->translated, 'status' => BP_Backup::MANUAL, 'block_type' => (int) $row->block_type, 'original_id' => (int) $row->original_id ) ), $meta['language'] );
		self::put( $meta['language'], $row->original, array( 'status' => self::MANUAL, 'editor' => get_current_user_id(), 'post_id' => (int) $meta['post_id'] ) );
		return true;
	}

	/**
	 * Retranslate with the AI (the owner's explicit action; replaces the carried translation).
	 *
	 * @param int $id Meta row id.
	 * @return bool|WP_Error
	 */
	public static function retranslate( $id ) {
		global $wpdb;
		$meta = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- own table.
		$row  = $meta ? self::engine_row( $meta ) : null;
		if ( ! $row ) {
			return new WP_Error( 'bp_changed_missing', __( 'That text is no longer stored.', 'beaver-press' ) );
		}
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$settings['trp_machine_translation_settings'] = get_option( 'trp_machine_translation_settings', array() ); // Current engine choice.
		require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
		self::$explicit = true;
		$result         = ( new BP_AI_Machine_Translator( $settings ) )->translate_array( array( 'x' => (string) $row->original ), $meta['language'], $settings['default-language'] );
		self::$explicit = false;
		if ( empty( $result['x'] ) ) {
			$error = BP_Providers::last_error( true );
			return new WP_Error( 'bp_changed_failed', $error ? $error['message'] : __( 'No translation came back.', 'beaver-press' ) );
		}
		$trp->get_component( 'query' )->update_strings( array( array( 'id' => (int) $row->id, 'original' => $row->original, 'translated' => (string) $result['x'], 'status' => BP_Backup::MACHINE, 'block_type' => (int) $row->block_type, 'original_id' => (int) $row->original_id ) ), $meta['language'] );
		$engine = BP_Engine_Settings::current( $settings['trp_machine_translation_settings'] ?? array() );
		self::put( $meta['language'], $row->original, array( 'status' => self::MACHINE, 'provider' => $engine['provider'], 'model' => $engine['model'], 'post_id' => (int) $meta['post_id'] ) );
		if ( class_exists( 'BP_Cache' ) ) {
			BP_Cache::flush();
		}
		return true;
	}

	/**
	 * Keep or Retranslate pressed.
	 */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_changed' );
		$id = absint( $_POST['id'] ?? 0 );
		$r  = 'retranslate' === ( $_POST['do'] ?? '' ) ? self::retranslate( $id ) : self::keep( $id );
		set_transient( 'bp_changed_result_' . get_current_user_id(), is_wp_error( $r ) ? $r->get_error_message() : '', 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '&tab=pages#bp-changed' ) );
		exit;
	}

	/**
	 * "Changed texts" card (Pages tab).
	 */
	public static function render() {
		$rows  = self::outdated();
		$names = BP_Run::languages();
		$error = get_transient( 'bp_changed_result_' . get_current_user_id() );
		if ( false !== $error ) {
			delete_transient( 'bp_changed_result_' . get_current_user_id() );
		}
		?>
		<div class="bp-card" id="bp-changed">
			<h2><?php esc_html_e( 'Changed texts', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'When a text with a manual translation was changed in the original, the manual translation is kept on the new text (instead of a machine translation) and listed here. Compare, then keep it, retranslate it with the AI, or edit it in the visual editor.', 'beaver-press' ); ?></p>
			<?php if ( is_string( $error ) && '' !== $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'Nothing to check.', 'beaver-press' ); ?></p>
			<?php else : ?>
				<table class="widefat striped bp-changed">
					<thead><tr><th><?php esc_html_e( 'Language', 'beaver-press' ); ?></th><th><?php esc_html_e( 'What changed in the original', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Translation kept', 'beaver-press' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $m ) : ?>
							<?php $row = self::engine_row( $m ); ?>
							<?php if ( ! $row ) { continue; } ?>
							<tr>
								<td><?php echo esc_html( $names[ $m['language'] ] ?? $m['language'] ); ?></td>
								<td class="bp-changed__diff"><?php echo wp_kses_post( (string) wp_text_diff( self::plain( (string) $m['old_source'] ), self::plain( $row->original ), array( 'show_split_view' => false ) ) ); ?></td>
								<td><?php echo esc_html( self::plain( $row->translated ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="bp_changed" />
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $m['id'] ); ?>" />
										<?php wp_nonce_field( 'bp_changed' ); ?>
										<button class="button button-small" name="do" value="keep"><?php esc_html_e( 'Keep', 'beaver-press' ); ?></button>
										<button class="button button-small" name="do" value="retranslate" onclick="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?php esc_attr_e( 'Replace the kept translation with a new AI translation (one small request)?', 'beaver-press' ); ?>"><?php esc_html_e( 'Retranslate with AI', 'beaver-press' ); ?></button>
										<?php if ( (int) $m['post_id'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'trp-edit-translation', 'true', BP_Run::url_in( get_permalink( (int) $m['post_id'] ), $m['language'] ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit', 'beaver-press' ); ?></a>
										<?php endif; ?>
									</form>
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
