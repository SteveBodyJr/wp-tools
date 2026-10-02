<?php
/**
 * Translation backup, export and import.
 *
 * The engine keeps translations in two tables per language: page texts ("regular": dictionary
 * table) and texts from themes and plugins ("gettext"). This class reads and writes them only
 * through the engine's own query API (or prepared queries on the same tables), never through
 * the original content.
 *
 * - Export: JSON (all or chosen languages, page texts and gettext) or CSV (one language's page
 *   texts). Untranslated rows and texts that come from language files are left out.
 * - Import: the file is validated first (format, languages, every row), duplicates are counted,
 *   and a dry run shows what would change. Rules: an empty translation is filled; a manual
 *   translation is never replaced unless "overwrite manual" is chosen; a machine translation is
 *   replaced by a manual one from the file, by another machine one only with "overwrite
 *   machine". Texts not on this site yet are added (page texts) or skipped (gettext).
 * - Backups: a gzipped JSON file in a private folder, made by hand or automatically before an
 *   import, a restore and "Redo a language". The last KEEP are kept. Restore writes every row
 *   back as it was and removes the rows an import added after that backup.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backup.
 */
final class BP_Backup {

	/** File format marker. */
	const FORMAT = 'beaver-press-translations';

	/** File format version. */
	const VERSION = 1;

	/** Backups kept. */
	const KEEP = 10;

	/** Option: backup list, newest first: [ id => [ time, reason, user, file, size, rows, added ] ]. */
	const OPTION = 'beaver_press_backups';

	/** Engine statuses: not translated, machine, manual, from a language file. */
	const MACHINE = 1;
	const MANUAL  = 2;

	/** Largest import accepted (bytes). */
	const MAX_IMPORT = 52428800;

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'export', 'import', 'backup', 'restore', 'delete', 'download' ) as $action ) {
			add_action( 'admin_post_bp_tr_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'beaver_press_before_reset', array( __CLASS__, 'before_reset' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Tables                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Translation languages (not the original).
	 *
	 * @return string[]
	 */
	public static function languages() {
		return array_keys( BP_Run::languages() );
	}

	/**
	 * Engine query component.
	 *
	 * @return TRP_Query
	 */
	private static function query() {
		return TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
	}

	/**
	 * Translated rows of a language.
	 *
	 * @param string $language Language.
	 * @param string $type     'regular' or 'gettext'.
	 * @return array[] Rows: o (original), t, s (status), and b (block type) or d/p (domain, plural form).
	 */
	public static function rows( $language, $type ) {
		global $wpdb;
		if ( 'gettext' === $type ) {
			$table = self::query()->get_gettext_table_name( $language );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT original AS o, translated AS t, status AS s, domain AS d, plural_form AS p FROM `{$table}` WHERE status IN (%d, %d) AND translated <> ''", self::MACHINE, self::MANUAL ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table.
		} else {
			$table = self::query()->get_table_name( $language );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT original AS o, translated AS t, status AS s, block_type AS b FROM `{$table}` WHERE status IN (%d, %d) AND translated <> ''", self::MACHINE, self::MANUAL ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table.
		}
		foreach ( (array) $rows as $i => $r ) {
			$rows[ $i ]['s'] = (int) $r['s'];
			if ( isset( $r['b'] ) ) {
				$rows[ $i ]['b'] = (int) $r['b'];
			}
			if ( isset( $r['p'] ) ) {
				$rows[ $i ]['p'] = (int) $r['p'];
			}
		}
		return (array) $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Export data.
	 *
	 * @param string[] $languages Languages (empty = all).
	 * @return array
	 */
	public static function data( array $languages = array() ) {
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$wanted   = $languages ? array_intersect( $languages, self::languages() ) : self::languages();
		$out      = array(
			'format'           => self::FORMAT,
			'version'          => self::VERSION,
			'plugin'           => BP_VERSION,
			'site'             => home_url( '/' ),
			'exported_at'      => gmdate( 'c' ),
			'default_language' => (string) $settings['default-language'],
			'languages'        => array(),
		);
		foreach ( $wanted as $language ) {
			$out['languages'][ $language ] = array(
				'regular' => self::rows( $language, 'regular' ),
				'gettext' => self::rows( $language, 'gettext' ),
			);
		}
		return $out;
	}

	/**
	 * CSV of one language's page texts (and gettext, marked by type).
	 *
	 * @param string   $language Language.
	 * @param resource $handle   Output.
	 */
	public static function csv( $language, $handle ) {
		fputcsv( $handle, array( 'type', 'original', 'translation', 'status', 'domain', 'plural_form' ) );
		foreach ( array( 'regular', 'gettext' ) as $type ) {
			foreach ( self::rows( $language, $type ) as $r ) {
				fputcsv( $handle, array( $type, $r['o'], $r['t'], self::MANUAL === $r['s'] ? 'manual' : 'machine', $r['d'] ?? '', $r['p'] ?? '' ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Validation and import                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Read a file into export data (JSON, gzipped JSON or a CSV for one language).
	 *
	 * @param string $path     File.
	 * @param string $language Language of a CSV file.
	 * @return array|WP_Error
	 */
	public static function read_file( $path, $language = '' ) {
		if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_IMPORT ) {
			return new WP_Error( 'bp_tr_file', __( 'The file cannot be read or is larger than 50 MB.', 'beaver-press' ) );
		}
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload or backup.
		if ( 0 === strpos( $raw, "\x1f\x8b" ) ) {
			$raw = (string) gzdecode( $raw );
		}
		$json = json_decode( $raw, true );
		if ( is_array( $json ) ) {
			return $json;
		}
		// CSV: header type,original,translation,status[,domain,plural_form].
		if ( '' === $language ) {
			return new WP_Error( 'bp_tr_csv_language', __( 'Choose the language of the CSV file.', 'beaver-press' ) );
		}
		$handle = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in memory.
		fwrite( $handle, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$head = fgetcsv( $handle );
		if ( ! is_array( $head ) || array( 'type', 'original', 'translation', 'status' ) !== array_slice( array_map( 'trim', $head ), 0, 4 ) ) {
			return new WP_Error( 'bp_tr_format', __( 'This is not a Beaver Press translations file (JSON) or CSV with the columns type, original, translation, status.', 'beaver-press' ) );
		}
		$data = array(
			'format'    => self::FORMAT,
			'version'   => self::VERSION,
			'languages' => array( $language => array( 'regular' => array(), 'gettext' => array() ) ),
		);
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( count( $row ) < 4 ) {
				continue;
			}
			$type = 'gettext' === trim( (string) $row[0] ) ? 'gettext' : 'regular';
			$r    = array(
				'o' => (string) $row[1],
				't' => (string) $row[2],
				's' => 'manual' === strtolower( trim( (string) $row[3] ) ) ? self::MANUAL : self::MACHINE,
			);
			if ( 'gettext' === $type ) {
				$r['d'] = (string) ( $row[4] ?? '' );
				$r['p'] = (int) ( $row[5] ?? 0 );
			}
			$data['languages'][ $language ][ $type ][] = $r;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $data;
	}

	/**
	 * Check a file's data before anything is written.
	 *
	 * @param array $data Export data.
	 * @return array|WP_Error Clean rows: language => type => original-key => row; plus counts.
	 */
	public static function validate( $data ) {
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) ) {
			return new WP_Error( 'bp_tr_format', __( 'This is not a Beaver Press translations file.', 'beaver-press' ) );
		}
		if ( (int) ( $data['version'] ?? 0 ) > self::VERSION ) {
			return new WP_Error( 'bp_tr_version', __( 'This file comes from a newer Beaver Press; update the plugin first.', 'beaver-press' ) );
		}
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		if ( ! empty( $data['default_language'] ) && $data['default_language'] !== $settings['default-language'] ) {
			/* translators: 1: file's original language, 2: this site's */
			return new WP_Error( 'bp_tr_default', sprintf( __( 'The file translates from %1$s, this site from %2$s.', 'beaver-press' ), $data['default_language'], $settings['default-language'] ) );
		}
		$clean = array();
		$count = array( 'rows' => 0, 'invalid' => 0, 'duplicates' => 0, 'unknown_languages' => array() );
		foreach ( (array) ( $data['languages'] ?? array() ) as $language => $types ) {
			if ( ! in_array( $language, self::languages(), true ) ) {
				$count['unknown_languages'][] = (string) $language;
				continue;
			}
			foreach ( array( 'regular', 'gettext' ) as $type ) {
				foreach ( (array) ( $types[ $type ] ?? array() ) as $r ) {
					$ok = is_array( $r ) && isset( $r['o'], $r['t'] ) && is_string( $r['o'] ) && is_string( $r['t'] )
						&& '' !== trim( $r['o'] ) && '' !== trim( $r['t'] ) && strlen( $r['o'] ) <= 65535 && strlen( $r['t'] ) <= 65535
						&& in_array( (int) ( $r['s'] ?? 0 ), array( self::MACHINE, self::MANUAL ), true );
					if ( ! $ok ) {
						$count['invalid']++;
						continue;
					}
					$row = array(
						'o' => $r['o'],
						't' => trp_sanitize_string( $r['t'] ),
						's' => (int) $r['s'],
					);
					if ( 'gettext' === $type ) {
						$row['d'] = sanitize_text_field( (string) ( $r['d'] ?? '' ) );
						$row['p'] = (int) ( $r['p'] ?? 0 );
						$key      = $row['o'] . "\0" . $row['d'] . "\0" . $row['p'];
					} else {
						$row['b'] = (int) ( $r['b'] ?? 0 );
						$key      = $row['o'];
					}
					if ( isset( $clean[ $language ][ $type ][ $key ] ) ) {
						$count['duplicates']++; // The last one in the file wins.
					} else {
						$count['rows']++;
					}
					$clean[ $language ][ $type ][ $key ] = $row;
				}
			}
		}
		if ( ! $clean ) {
			return new WP_Error( 'bp_tr_empty', __( 'The file has no translations for the languages of this site.', 'beaver-press' ) );
		}
		return array(
			'rows'  => $clean,
			'count' => $count,
		);
	}

	/**
	 * Apply checked rows (or only count what would change).
	 *
	 * @param array $checked From validate().
	 * @param array $opts    dry_run, overwrite_manual, overwrite_machine, exact (restore: every row as in the file).
	 * @return array Report: language => counts; 'added' => language => original ids added.
	 */
	public static function apply( array $checked, array $opts = array() ) {
		global $wpdb;
		$opts   = wp_parse_args( $opts, array( 'dry_run' => true, 'overwrite_manual' => false, 'overwrite_machine' => false, 'exact' => false ) );
		$query  = self::query();
		$report = array( 'languages' => array(), 'added' => array() );
		foreach ( $checked['rows'] as $language => $types ) {
			$c = array_fill_keys( array( 'added', 'filled', 'replaced_machine', 'replaced_manual', 'kept_manual', 'kept_machine', 'same', 'gettext_updated', 'gettext_not_on_site' ), 0 );

			// Page texts.
			$rows = $types['regular'] ?? array();
			foreach ( array_chunk( $rows, 500, true ) as $chunk ) {
				$existing = self::by_original( array_keys( $chunk ), $language );
				$missing  = array_values( array_diff( array_keys( $chunk ), array_keys( $existing ) ) );
				if ( $missing ) {
					$c['added'] += count( $missing );
					if ( ! $opts['dry_run'] ) {
						$query->insert_strings( $missing, $language );
						$existing = self::by_original( array_keys( $chunk ), $language );
						foreach ( $missing as $o ) {
							if ( isset( $existing[ $o ] ) ) {
								$report['added'][ $language ][] = (int) $existing[ $o ]->id;
							}
						}
					}
				}
				$update = array();
				foreach ( $chunk as $o => $r ) {
					$e = $existing[ $o ] ?? null;
					if ( $opts['dry_run'] && null === $e ) {
						continue; // Counted as added.
					}
					if ( null === $e ) {
						continue;
					}
					$cur_t = (string) $e->translated;
					$cur_s = (int) $e->status;
					$write = false;
					if ( in_array( $o, $missing, true ) ) {
						$write = true; // Just added (counted as added).
					} elseif ( $cur_t === $r['t'] && $cur_s === $r['s'] ) {
						$c['same']++;
					} elseif ( $opts['exact'] || '' === trim( $cur_t ) || $cur_s < self::MACHINE ) {
						$write = true;
						$c['' === trim( $cur_t ) ? 'filled' : ( self::MANUAL === $cur_s ? 'replaced_manual' : 'replaced_machine' ) ]++;
					} elseif ( self::MANUAL === $cur_s ) {
						$write = (bool) $opts['overwrite_manual'];
						$c[ $write ? 'replaced_manual' : 'kept_manual' ]++;
					} elseif ( self::MANUAL === $r['s'] || $opts['overwrite_machine'] ) {
						$write = true;
						$c['replaced_machine']++;
					} else {
						$c['kept_machine']++;
					}
					if ( $write ) {
						$update[] = array(
							'id'          => (int) $e->id,
							'original'    => $o,
							'translated'  => $r['t'],
							'status'      => $r['s'],
							'block_type'  => (int) $e->block_type,
							'original_id' => (int) $e->original_id,
						);
					}
				}
				if ( $update && ! $opts['dry_run'] ) {
					$query->update_strings( $update, $language );
					if ( class_exists( 'BP_Provenance' ) ) {
						foreach ( $update as $u ) {
							BP_Provenance::put( $language, $u['original'], array( 'status' => self::MANUAL === $u['status'] ? BP_Provenance::MANUAL : BP_Provenance::MACHINE, 'provider' => 'import' ) );
						}
					}
				}
			}

			// Theme and plugin texts: only rows this site already has.
			$table = $query->get_gettext_table_name( $language );
			foreach ( $types['gettext'] ?? array() as $r ) {
				$e = $wpdb->get_row( $wpdb->prepare( "SELECT id, translated, status FROM `{$table}` WHERE original = %s AND domain = %s AND plural_form = %d LIMIT 1", $r['o'], $r['d'], $r['p'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table.
				if ( ! $e ) {
					$c['gettext_not_on_site']++;
					continue;
				}
				$cur_s = (int) $e->status;
				if ( (string) $e->translated === $r['t'] && $cur_s === $r['s'] ) {
					$c['same']++;
					continue;
				}
				$write = $opts['exact'] || '' === trim( (string) $e->translated ) || ! in_array( $cur_s, array( self::MACHINE, self::MANUAL ), true )
					|| ( self::MANUAL === $cur_s ? $opts['overwrite_manual'] : ( self::MANUAL === $r['s'] || $opts['overwrite_machine'] ) );
				if ( ! $write ) {
					$c[ self::MANUAL === $cur_s ? 'kept_manual' : 'kept_machine' ]++;
					continue;
				}
				$c['gettext_updated']++;
				if ( ! $opts['dry_run'] ) {
					$wpdb->update( $table, array( 'translated' => $r['t'], 'status' => $r['s'] ), array( 'id' => (int) $e->id ), array( '%s', '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- engine table.
				}
			}
			$report['languages'][ $language ] = $c;
		}
		if ( ! $opts['dry_run'] ) {
			self::after_change();
		}
		return $report;
	}

	/**
	 * Page-text rows of a language for these originals (any status), by exact original.
	 *
	 * @param string[] $originals Originals.
	 * @param string   $language  Language.
	 * @return object[] Original => row (id, original, translated, status, block_type, original_id).
	 */
	private static function by_original( array $originals, $language ) {
		$out = array();
		foreach ( (array) self::query()->get_string_rows( array(), array_values( $originals ), $language ) as $row ) {
			if ( ! isset( $out[ $row->original ] ) ) {
				$out[ (string) $row->original ] = $row; // The database compares without case; keep exact matches only.
			}
		}
		return array_intersect_key( $out, array_flip( $originals ) );
	}

	/**
	 * Translations changed: ready pages and the engine's caches start afresh.
	 */
	private static function after_change() {
		wp_cache_flush();
		if ( class_exists( 'BP_Cache' ) ) {
			BP_Cache::flush();
		}
		do_action( 'beaver_press_translations_imported' );
	}

	/* ------------------------------------------------------------------ */
	/* Backups                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Private folder for backups (uploads/beaver-press-backups-<random>, not listable).
	 *
	 * @return string|WP_Error
	 */
	public static function dir() {
		$key = get_option( 'beaver_press_backup_dir' );
		if ( ! $key ) {
			$key = wp_generate_password( 24, false, false );
			update_option( 'beaver_press_backup_dir', $key, false );
		}
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'beaver-press-backups-' . $key;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'bp_tr_dir', __( 'The backup folder cannot be created in uploads.', 'beaver-press' ) );
		}
		foreach ( array( 'index.php' => "<?php\n// Silence is golden.\n", '.htaccess' => "Require all denied\nDeny from all\n" ) as $name => $body ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protection files.
			}
		}
		return $dir;
	}

	/**
	 * Backups, newest first.
	 *
	 * @return array
	 */
	public static function backups() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Make a backup of every language.
	 *
	 * @param string $reason Why.
	 * @return string|WP_Error Backup id.
	 */
	public static function create( $reason ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$data = self::data();
		$rows = 0;
		foreach ( $data['languages'] as $types ) {
			$rows += count( $types['regular'] ) + count( $types['gettext'] );
		}
		$id   = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$file = $dir . '/translations-' . $id . '-' . wp_generate_password( 16, false, false ) . '.json.gz';
		if ( false === file_put_contents( $file, gzencode( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 6 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- backup file.
			return new WP_Error( 'bp_tr_write', __( 'The backup file could not be written.', 'beaver-press' ) );
		}
		$list        = array(
			$id => array(
				'time'   => time(),
				'reason' => (string) $reason,
				'user'   => function_exists( 'wp_get_current_user' ) && get_current_user_id() ? wp_get_current_user()->display_name : 'WP-CLI / system',
				'file'   => basename( $file ),
				'size'   => (int) filesize( $file ),
				'rows'   => $rows,
				'added'  => array(),
			),
		) + self::backups();
		foreach ( array_slice( $list, self::KEEP, null, true ) as $old ) {
			wp_delete_file( $dir . '/' . $old['file'] );
		}
		update_option( self::OPTION, array_slice( $list, 0, self::KEEP, true ), false );
		return $id;
	}

	/**
	 * Path of a backup.
	 *
	 * @param string $id Backup id.
	 * @return string '' when missing.
	 */
	public static function path( $id ) {
		$list = self::backups();
		$dir  = self::dir();
		if ( ! isset( $list[ $id ] ) || is_wp_error( $dir ) ) {
			return '';
		}
		$file = $dir . '/' . basename( (string) $list[ $id ]['file'] );
		return is_file( $file ) ? $file : '';
	}

	/**
	 * Remember which rows an import added, on the backup made just before it (for restore).
	 *
	 * @param string $id    Backup id.
	 * @param array  $added Language => original ids.
	 */
	private static function note_added( $id, array $added ) {
		$list = self::backups();
		if ( isset( $list[ $id ] ) && $added ) {
			$list[ $id ]['added'] = $added;
			update_option( self::OPTION, $list, false );
		}
	}

	/**
	 * Import a file: backup first, then apply.
	 *
	 * @param array $checked From validate().
	 * @param array $opts    See apply().
	 * @return array|WP_Error Report plus 'backup'.
	 */
	public static function import( array $checked, array $opts ) {
		$opts['exact'] = false;
		if ( ! empty( $opts['dry_run'] ) ) {
			return self::apply( $checked, $opts );
		}
		$backup = self::create( __( 'Before an import', 'beaver-press' ) );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		$report = self::apply( $checked, $opts );
		self::note_added( $backup, $report['added'] );
		$report['backup'] = $backup;
		return $report;
	}

	/**
	 * Put a backup back: every row as it was, rows added after it by an import removed.
	 *
	 * @param string $id Backup id.
	 * @return array|WP_Error Report plus 'backup' (the backup made just before restoring).
	 */
	public static function restore( $id ) {
		global $wpdb;
		$path = self::path( $id );
		if ( '' === $path ) {
			return new WP_Error( 'bp_tr_missing', __( 'That backup no longer exists.', 'beaver-press' ) );
		}
		$data    = self::read_file( $path );
		$checked = is_wp_error( $data ) ? $data : self::validate( $data );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$added  = (array) ( self::backups()[ $id ]['added'] ?? array() );
		$before = self::create( __( 'Before a restore', 'beaver-press' ) );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$report = self::apply( $checked, array( 'dry_run' => false, 'exact' => true ) );
		foreach ( $added as $language => $ids ) {
			if ( ! in_array( $language, self::languages(), true ) ) {
				continue;
			}
			$table = self::query()->get_table_name( $language );
			foreach ( array_chunk( array_map( 'intval', (array) $ids ), 500 ) as $chunk ) {
				$wpdb->query( "DELETE FROM `{$table}` WHERE id IN (" . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- integers only.
			}
			$report['languages'][ $language ]['removed'] = count( (array) $ids );
		}
		$report['backup'] = $before;
		return $report;
	}

	/**
	 * Before an action that resets translations (e.g. "Redo a language").
	 *
	 * @param string $what     What is about to happen.
	 * @param string $language Language.
	 */
	public static function before_reset( $what, $language = '' ) {
		/* translators: 1: action, 2: language */
		self::create( trim( sprintf( __( 'Before %1$s %2$s', 'beaver-press' ), $what, $language ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Check an admin request.
	 *
	 * @param string $action Nonce action.
	 */
	private static function check( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the card with a message.
	 *
	 * @param array $result Message data.
	 */
	private static function back( array $result ) {
		set_transient( 'bp_tr_result_' . get_current_user_id(), $result, 300 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '#bp-translations' ) );
		exit;
	}

	/**
	 * Download an export.
	 */
	public static function handle_export() {
		self::check( 'bp_tr_export' );
		$language = sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) );
		$format   = 'csv' === ( $_POST['format'] ?? '' ) ? 'csv' : 'json';
		$name     = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) . '-translations-' . ( $language ? $language : 'all' ) . '-' . gmdate( 'Ymd' ) );
		nocache_headers();
		if ( 'csv' === $format ) {
			if ( ! in_array( $language, self::languages(), true ) ) {
				self::back( array( 'error' => __( 'Choose one language for a CSV export.', 'beaver-press' ) ) );
			}
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.csv"' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- download.
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 mark for spreadsheets.
			self::csv( $language, $out );
			exit;
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.json"' );
		echo wp_json_encode( self::data( $language ? array( $language ) : array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import an uploaded file (dry run unless "apply" was pressed).
	 */
	public static function handle_import() {
		self::check( 'bp_tr_import' );
		$file = $_FILES['bp_tr_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? 1 ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			self::back( array( 'error' => __( 'Choose a file to import.', 'beaver-press' ) ) );
		}
		$data    = self::read_file( (string) $file['tmp_name'], sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ) );
		$checked = is_wp_error( $data ) ? $data : self::validate( $data );
		if ( is_wp_error( $checked ) ) {
			self::back( array( 'error' => $checked->get_error_message() ) );
		}
		$opts   = array(
			'dry_run'           => empty( $_POST['apply'] ),
			'overwrite_manual'  => ! empty( $_POST['overwrite_manual'] ),
			'overwrite_machine' => ! empty( $_POST['overwrite_machine'] ),
		);
		$report = self::import( $checked, $opts );
		if ( is_wp_error( $report ) ) {
			self::back( array( 'error' => $report->get_error_message() ) );
		}
		self::back( array( 'report' => $report, 'count' => $checked['count'], 'dry_run' => $opts['dry_run'] ) );
	}

	/**
	 * Backup now.
	 */
	public static function handle_backup() {
		self::check( 'bp_tr_backup' );
		$id = self::create( __( 'By hand', 'beaver-press' ) );
		self::back( is_wp_error( $id ) ? array( 'error' => $id->get_error_message() ) : array( 'done' => __( 'Backup made.', 'beaver-press' ) ) );
	}

	/**
	 * Restore a backup.
	 */
	public static function handle_restore() {
		self::check( 'bp_tr_restore' );
		$report = self::restore( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ) );
		self::back( is_wp_error( $report ) ? array( 'error' => $report->get_error_message() ) : array( 'report' => $report, 'restored' => true ) );
	}

	/**
	 * Delete a backup.
	 */
	public static function handle_delete() {
		self::check( 'bp_tr_delete' );
		$id   = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$path = self::path( $id );
		if ( '' !== $path ) {
			wp_delete_file( $path );
		}
		$list = self::backups();
		unset( $list[ $id ] );
		update_option( self::OPTION, $list, false );
		self::back( array( 'done' => __( 'Backup deleted.', 'beaver-press' ) ) );
	}

	/**
	 * Download a backup file.
	 */
	public static function handle_download() {
		self::check( 'bp_tr_download' );
		$path = self::path( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ) );
		if ( '' === $path ) {
			self::back( array( 'error' => __( 'That backup no longer exists.', 'beaver-press' ) ) );
		}
		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . preg_replace( '/-[A-Za-z0-9]{16}\.json\.gz$/', '.json.gz', basename( $path ) ) . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- download.
		exit;
	}

	/**
	 * Report lines for people.
	 *
	 * @param array $report From apply().
	 * @return string[]
	 */
	public static function report_lines( array $report ) {
		$names  = BP_Run::languages();
		$labels = array(
			'added'               => __( 'texts added', 'beaver-press' ),
			'filled'              => __( 'empty translations filled', 'beaver-press' ),
			'replaced_machine'    => __( 'machine translations replaced', 'beaver-press' ),
			'replaced_manual'     => __( 'manual translations replaced', 'beaver-press' ),
			'kept_manual'         => __( 'manual translations kept', 'beaver-press' ),
			'kept_machine'        => __( 'machine translations kept', 'beaver-press' ),
			'same'                => __( 'already the same', 'beaver-press' ),
			'gettext_updated'     => __( 'theme/plugin texts updated', 'beaver-press' ),
			'gettext_not_on_site' => __( 'theme/plugin texts not on this site', 'beaver-press' ),
			'removed'             => __( 'texts removed (added after the backup)', 'beaver-press' ),
		);
		$lines = array();
		foreach ( (array) ( $report['languages'] ?? array() ) as $language => $c ) {
			$parts = array();
			foreach ( $labels as $k => $label ) {
				if ( ! empty( $c[ $k ] ) ) {
					$parts[] = number_format_i18n( (int) $c[ $k ] ) . ' ' . $label;
				}
			}
			$lines[] = ( $names[ $language ] ?? $language ) . ': ' . ( $parts ? implode( ', ', $parts ) : __( 'nothing to change', 'beaver-press' ) );
		}
		return $lines;
	}

	/**
	 * Card on the Set-up page.
	 */
	public static function render() {
		$result = get_transient( 'bp_tr_result_' . get_current_user_id() );
		if ( false !== $result ) {
			delete_transient( 'bp_tr_result_' . get_current_user_id() );
		}
		$names = BP_Run::languages();
		$form  = static function ( $action, $nonce ) {
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
			wp_nonce_field( $nonce );
		};
		?>
		<div class="bp-card" id="bp-translations">
			<h2><?php esc_html_e( 'Translations: backup, export and import', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Backups are made automatically before an import, a restore and "Redo a language" (the last 10 are kept, in a private folder). Import checks the file first and shows what would change; manual translations are never replaced unless you tick it.', 'beaver-press' ); ?></p>
			<?php if ( is_array( $result ) && isset( $result['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $result['error'] ); ?></p></div>
			<?php elseif ( is_array( $result ) && isset( $result['report'] ) ) : ?>
				<div class="notice <?php echo ! empty( $result['dry_run'] ) ? 'notice-warning' : 'notice-success'; ?> inline">
					<p><strong>
						<?php
						if ( ! empty( $result['restored'] ) ) {
							esc_html_e( 'Backup restored.', 'beaver-press' );
						} elseif ( ! empty( $result['dry_run'] ) ) {
							esc_html_e( 'Dry run: nothing was written. This is what the import would do:', 'beaver-press' );
						} else {
							esc_html_e( 'Imported. A backup was made first.', 'beaver-press' );
						}
						?>
					</strong></p>
					<ul>
						<?php foreach ( self::report_lines( $result['report'] ) as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
						<?php if ( ! empty( $result['count'] ) ) : ?>
							<li>
								<?php
								/* translators: 1: rows, 2: duplicates, 3: invalid rows */
								echo esc_html( sprintf( __( 'File: %1$s rows checked, %2$s duplicates (the last one wins), %3$s invalid rows skipped.', 'beaver-press' ), number_format_i18n( $result['count']['rows'] ), number_format_i18n( $result['count']['duplicates'] ), number_format_i18n( $result['count']['invalid'] ) ) );
								if ( ! empty( $result['count']['unknown_languages'] ) ) {
									echo ' ' . esc_html( sprintf( /* translators: %s: language codes */ __( 'Languages not on this site, skipped: %s.', 'beaver-press' ), implode( ', ', $result['count']['unknown_languages'] ) ) );
								}
								?>
							</li>
						<?php endif; ?>
					</ul>
				</div>
			<?php elseif ( is_array( $result ) && isset( $result['done'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( $result['done'] ); ?></p></div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Export', 'beaver-press' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-transfer__row">
				<?php $form( 'bp_tr_export', 'bp_tr_export' ); ?>
				<select name="language">
					<option value=""><?php esc_html_e( 'All languages', 'beaver-press' ); ?></option>
					<?php foreach ( $names as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="format">
					<option value="json">JSON</option>
					<option value="csv"><?php esc_html_e( 'CSV (one language)', 'beaver-press' ); ?></option>
				</select>
				<button class="button"><?php esc_html_e( 'Download', 'beaver-press' ); ?></button>
			</form>

			<h3><?php esc_html_e( 'Import', 'beaver-press' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bp-tr-import">
				<?php $form( 'bp_tr_import', 'bp_tr_import' ); ?>
				<input type="file" name="bp_tr_file" accept=".json,.gz,.csv,application/json,text/csv" />
				<select name="language" title="<?php esc_attr_e( 'Language of a CSV file', 'beaver-press' ); ?>">
					<option value=""><?php esc_html_e( 'CSV language...', 'beaver-press' ); ?></option>
					<?php foreach ( $names as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="overwrite_machine" value="1" /> <?php esc_html_e( 'Replace machine translations', 'beaver-press' ); ?></label>
				<label><input type="checkbox" name="overwrite_manual" value="1" /> <?php esc_html_e( 'Replace manual translations too', 'beaver-press' ); ?></label>
				<button class="button"><?php esc_html_e( 'Check (dry run)', 'beaver-press' ); ?></button>
				<button class="button button-primary" name="apply" value="1" onclick="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?php esc_attr_e( 'Import these translations? A backup is made first.', 'beaver-press' ); ?>"><?php esc_html_e( 'Import', 'beaver-press' ); ?></button>
			</form>

			<h3><?php esc_html_e( 'Backups', 'beaver-press' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $form( 'bp_tr_backup', 'bp_tr_backup' ); ?>
				<button class="button"><?php esc_html_e( 'Back up now', 'beaver-press' ); ?></button>
			</form>
			<?php if ( self::backups() ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Made', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Why', 'beaver-press' ); ?></th><th><?php esc_html_e( 'By', 'beaver-press' ); ?></th><th><?php esc_html_e( 'Translations', 'beaver-press' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( self::backups() as $id => $b ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $b['time'] ) ); ?></td>
								<td><?php echo esc_html( (string) $b['reason'] ); ?></td>
								<td><?php echo esc_html( (string) $b['user'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $b['rows'] ) . ' (' . size_format( (int) $b['size'] ) . ')' ); ?></td>
								<td>
									<?php foreach ( array( 'download' => __( 'Download', 'beaver-press' ), 'restore' => __( 'Restore', 'beaver-press' ), 'delete' => __( 'Delete', 'beaver-press' ) ) as $act => $label ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
											<?php $form( 'bp_tr_' . $act, 'bp_tr_' . $act ); ?>
											<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
											<button class="button button-small" <?php echo 'download' !== $act ? 'onclick="return window.confirm(this.getAttribute(\'data-confirm\'));"' : ''; ?> data-confirm="<?php echo esc_attr( 'restore' === $act ? __( 'Put every translation back as in this backup? A backup of the current state is made first.', 'beaver-press' ) : __( 'Delete this backup?', 'beaver-press' ) ); ?>"><?php echo esc_html( $label ); ?></button>
										</form>
									<?php endforeach; ?>
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
