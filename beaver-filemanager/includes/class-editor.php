<?php
/**
 * Saving, syntax checking, backups and the trash.
 *
 * @package BeaverFileManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that stands between a person and an unrecoverable mistake.
 *
 * A save runs three gates in order: the file has not changed under you, the PHP
 * still parses, and the previous version is copied somewhere safe. Only then is
 * anything written.
 *
 * @since 1.0.0
 */
class Beaver_FM_Editor {

	const KEY_OPTION = 'beaver_fm_storage_key';

	/**
	 * Runtime cache of the storage directory.
	 *
	 * @var string|null
	 */
	private static $storage = null;

	/*
	 * -----------------------------------------------------------------------
	 * Private storage
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The unguessable folder name backups and trash live in.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function storage_key() {
		$key = get_option( self::KEY_OPTION );

		if ( ! $key || ! is_string( $key ) ) {
			$key = wp_generate_password( 12, false, false );
			update_option( self::KEY_OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * Absolute path of the plugin's private storage.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function storage_dir() {
		if ( null !== self::$storage ) {
			return self::$storage;
		}

		self::$storage = Beaver_FM_Filesystem::uploads_dir() . '/beaver-fm-' . self::storage_key();

		return self::$storage;
	}

	/**
	 * Absolute path of the backup store.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function backups_dir() {
		return self::storage_dir() . '/backups';
	}

	/**
	 * Absolute path of the trash.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function trash_dir() {
		return self::storage_dir() . '/trash';
	}

	/**
	 * Creates the storage folders and blocks HTTP access to them.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the storage is usable.
	 */
	public static function prepare_storage() {
		$storage = self::storage_dir();

		if ( ! wp_mkdir_p( self::backups_dir() ) || ! wp_mkdir_p( self::trash_dir() ) ) {
			return false;
		}

		$guards = array(
			'.htaccess'   => "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'   => "<?php\n// Silence is golden.\n",
		);

		foreach ( $guards as $file => $contents ) {
			$path = $storage . '/' . $file;

			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		set_transient( 'beaver_fm_storage_ready', 1, DAY_IN_SECONDS );

		return true;
	}

	/**
	 * Makes sure the storage exists before it is written to.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function ensure_storage() {
		if ( get_transient( 'beaver_fm_storage_ready' ) && is_dir( self::backups_dir() ) ) {
			return true;
		}

		return self::prepare_storage();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Saving
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Checks that a block of PHP will actually parse.
	 *
	 * `token_get_all()` with `TOKEN_PARSE` runs the real parser, so this catches
	 * exactly what would have produced a white screen — without shelling out to
	 * `php -l`, which most shared hosts disable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content File contents.
	 * @return true|WP_Error
	 */
	public static function lint_php( $content ) {
		if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
			return true;
		}

		try {
			token_get_all( $content, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return new WP_Error(
				'beaver_fm_parse_error',
				sprintf(
					/* translators: 1: line number, 2: parser message. */
					__( 'PHP syntax error on line %1$d — %2$s', 'beaver-filemanager' ),
					(int) $e->getLine(),
					$e->getMessage()
				),
				array( 'line' => (int) $e->getLine() )
			);
		} catch ( Error $e ) {
			return new WP_Error( 'beaver_fm_parse_error', $e->getMessage() );
		} catch ( Exception $e ) {
			return new WP_Error( 'beaver_fm_parse_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Whether a file should be syntax checked before saving.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ext Lower-case extension.
	 * @return bool
	 */
	public static function should_lint( $ext ) {
		if ( ! Beaver_FM_Settings::value( 'lint_php' ) ) {
			return false;
		}

		return in_array( $ext, array( 'php', 'phtml', 'php5', 'php7', 'inc', 'module', 'install', 'theme' ), true );
	}

	/**
	 * Saves new contents to a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative      File relative to the root.
	 * @param string $content       New contents.
	 * @param string $expected_hash MD5 of the contents the editor was opened with.
	 * @param array  $flags         {
	 *     Optional. Overrides.
	 *
	 *     @type bool $force         Save even though the file changed underneath.
	 *     @type bool $ignore_syntax Save even though the PHP does not parse.
	 * }
	 * @return array|WP_Error
	 */
	public static function save( $relative, $content, $expected_hash = '', $flags = array() ) {
		$flags = wp_parse_args(
			$flags,
			array(
				'force'         => false,
				'ignore_syntax' => false,
			)
		);

		$absolute = Beaver_FM_Filesystem::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		if ( ! is_file( $absolute ) ) {
			return new WP_Error( 'beaver_fm_not_file', __( 'That is a folder, not a file.', 'beaver-filemanager' ) );
		}

		$current = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $current ) {
			return new WP_Error( 'beaver_fm_read_failed', __( 'The current contents could not be read, so saving was stopped.', 'beaver-filemanager' ) );
		}

		if ( '' !== $expected_hash && ! $flags['force'] && md5( $current ) !== $expected_hash ) {
			return new WP_Error(
				'beaver_fm_conflict',
				__( 'This file changed on the server after you opened it. Saving now would throw away that change.', 'beaver-filemanager' ),
				array( 'conflict' => true )
			);
		}

		if ( md5( $current ) === md5( $content ) ) {
			$entry = Beaver_FM_Filesystem::entry( $absolute );

			return array(
				'entry'    => $entry,
				'hash'     => md5( $content ),
				'unchanged' => true,
				'backup'   => null,
			);
		}

		$ext = strtolower( (string) pathinfo( $absolute, PATHINFO_EXTENSION ) );

		if ( ! $flags['ignore_syntax'] && self::should_lint( $ext ) ) {
			$lint = self::lint_php( $content );

			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}

		$backup = null;

		if ( Beaver_FM_Settings::value( 'backups' ) ) {
			$backup = self::backup( $absolute, $current );
		}

		$written = Beaver_FM_Filesystem::put( $relative, $content );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		Beaver_FM_Logger::record(
			'save',
			Beaver_FM_Filesystem::relative( $absolute ),
			sprintf(
				/* translators: %s: formatted byte size. */
				__( 'now %s', 'beaver-filemanager' ),
				size_format( strlen( $content ) )
			)
		);

		clearstatcache( true, $absolute );

		return array(
			'entry'     => Beaver_FM_Filesystem::entry( $absolute ),
			'hash'      => md5( $content ),
			'unchanged' => false,
			'backup'    => is_wp_error( $backup ) ? null : $backup,
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Backups
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The folder holding one file's version history.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative File relative to the root.
	 * @return string
	 */
	private static function backup_bucket( $relative ) {
		return self::backups_dir() . '/' . md5( Beaver_FM_Filesystem::root_path() . '|' . $relative );
	}

	/**
	 * Stores a copy of a file's current contents.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $absolute Absolute path.
	 * @param string|null $contents Contents to store. Read from disk when null.
	 * @return array|WP_Error Description of the stored version.
	 */
	public static function backup( $absolute, $contents = null ) {
		if ( ! self::ensure_storage() ) {
			return new WP_Error( 'beaver_fm_no_storage', __( 'The backup folder could not be created inside uploads.', 'beaver-filemanager' ) );
		}

		if ( null === $contents ) {
			$contents = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $contents ) {
				return new WP_Error( 'beaver_fm_read_failed', __( 'The file could not be read, so no backup was taken.', 'beaver-filemanager' ) );
			}
		}

		$relative = Beaver_FM_Filesystem::relative( $absolute );
		$bucket   = self::backup_bucket( $relative );

		if ( ! wp_mkdir_p( $bucket ) ) {
			return new WP_Error( 'beaver_fm_no_storage', __( 'The backup folder could not be created.', 'beaver-filemanager' ) );
		}

		$manifest = $bucket . '/manifest.json';

		if ( ! file_exists( $manifest ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$manifest,
				wp_json_encode(
					array(
						'path' => $relative,
						'name' => wp_basename( $absolute ),
					)
				)
			);
		}

		$user = wp_get_current_user();
		$id   = self::version_id();
		$file = $bucket . '/' . $id . '.bak';

		if ( false === file_put_contents( $file, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'beaver_fm_backup_failed', __( 'The backup could not be written.', 'beaver-filemanager' ) );
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$bucket . '/' . $id . '.json',
			wp_json_encode(
				array(
					'time' => time(),
					'user' => $user && $user->exists() ? $user->user_login : '',
					'size' => strlen( $contents ),
				)
			)
		);

		self::prune_backups( $bucket );

		return array(
			'id'   => $id,
			'time' => time(),
			'size' => strlen( $contents ),
		);
	}

	/**
	 * Drops the oldest versions once the configured ceiling is passed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bucket Absolute path of one file's history folder.
	 */
	private static function prune_backups( $bucket ) {
		$keep  = max( 1, absint( Beaver_FM_Settings::value( 'backup_keep', 10 ) ) );
		$files = glob( $bucket . '/*.bak' );

		if ( ! is_array( $files ) || count( $files ) <= $keep ) {
			return;
		}

		sort( $files );

		$drop = array_slice( $files, 0, count( $files ) - $keep );

		foreach ( $drop as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( substr( $file, 0, -4 ) . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Lists the stored versions of a file, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative File relative to the root.
	 * @return array[]
	 */
	public static function backups( $relative ) {
		$bucket = self::backup_bucket( $relative );
		$files  = glob( $bucket . '/*.bak' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		rsort( $files );

		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$entries = array();

		foreach ( $files as $file ) {
			$id   = wp_basename( $file, '.bak' );
			$meta = self::read_json( substr( $file, 0, -4 ) . '.json' );
			$time = isset( $meta['time'] ) ? (int) $meta['time'] : (int) filemtime( $file );
			$size = isset( $meta['size'] ) ? (int) $meta['size'] : (int) filesize( $file );

			$entries[] = array(
				'id'       => $id,
				'time'     => $time,
				'when'     => wp_date( $format, $time ),
				'ago'      => sprintf(
					/* translators: %s: human time difference. */
					__( '%s ago', 'beaver-filemanager' ),
					human_time_diff( $time )
				),
				'user'     => isset( $meta['user'] ) ? $meta['user'] : '',
				'size'     => $size,
				'sizeText' => size_format( $size, 0 ),
			);
		}

		return $entries;
	}

	/**
	 * Reads the contents of one stored version.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative File relative to the root.
	 * @param string $id       Version identifier.
	 * @return string|WP_Error
	 */
	public static function backup_contents( $relative, $id ) {
		$id = preg_replace( '/[^0-9a-zA-Z\-]/', '', (string) $id );

		if ( '' === $id ) {
			return new WP_Error( 'beaver_fm_bad_backup', __( 'That version identifier is not valid.', 'beaver-filemanager' ) );
		}

		$file = self::backup_bucket( $relative ) . '/' . $id . '.bak';

		if ( ! is_file( $file ) ) {
			return new WP_Error( 'beaver_fm_no_backup', __( 'That version is no longer stored.', 'beaver-filemanager' ) );
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return false === $contents ? new WP_Error( 'beaver_fm_read_failed', __( 'That version could not be read.', 'beaver-filemanager' ) ) : $contents;
	}

	/**
	 * Puts a stored version back, after backing up what is there now.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative File relative to the root.
	 * @param string $id       Version identifier.
	 * @return array|WP_Error
	 */
	public static function restore_backup( $relative, $id ) {
		$contents = self::backup_contents( $relative, $id );

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		$result = self::save( $relative, $contents, '', array( 'force' => true, 'ignore_syntax' => true ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Beaver_FM_Logger::record( 'restore', $relative, $id );

		$result['content'] = $contents;

		return $result;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Trash
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Moves an item into the trash instead of erasing it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path inside the root.
	 * @return true|WP_Error
	 */
	public static function send_to_trash( $absolute ) {
		if ( ! self::ensure_storage() ) {
			return new WP_Error( 'beaver_fm_no_storage', __( 'The trash folder could not be created inside uploads.', 'beaver-filemanager' ) );
		}

		$relative = Beaver_FM_Filesystem::relative( $absolute );
		$id       = self::version_id();
		$bucket   = self::trash_dir() . '/' . $id;

		if ( ! wp_mkdir_p( $bucket ) ) {
			return new WP_Error( 'beaver_fm_no_storage', __( 'The trash folder could not be created.', 'beaver-filemanager' ) );
		}

		$name   = wp_basename( $absolute );
		$target = $bucket . '/' . $name;
		$user   = wp_get_current_user();

		if ( ! @rename( $absolute, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$copied = Beaver_FM_Filesystem::copy_path( $absolute, $target );

			if ( is_wp_error( $copied ) ) {
				Beaver_FM_Filesystem::erase( $bucket );

				return $copied;
			}

			$erased = Beaver_FM_Filesystem::erase( $absolute );

			if ( is_wp_error( $erased ) ) {
				return $erased;
			}
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$bucket . '/beaver-fm.json',
			wp_json_encode(
				array(
					'path' => $relative,
					'name' => $name,
					'dir'  => is_dir( $target ),
					'time' => time(),
					'user' => $user && $user->exists() ? $user->user_login : '',
				)
			)
		);

		return true;
	}

	/**
	 * Lists everything in the trash, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	public static function trash() {
		$buckets = glob( self::trash_dir() . '/*', GLOB_ONLYDIR );

		if ( ! is_array( $buckets ) ) {
			return array();
		}

		rsort( $buckets );

		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$entries = array();

		foreach ( $buckets as $bucket ) {
			$meta = self::read_json( $bucket . '/beaver-fm.json' );

			if ( ! $meta || ! isset( $meta['name'] ) ) {
				continue;
			}

			$item = $bucket . '/' . $meta['name'];
			$time = isset( $meta['time'] ) ? (int) $meta['time'] : (int) filemtime( $bucket );
			$size = is_dir( $item ) ? 0 : (int) @filesize( $item ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$entries[] = array(
				'id'       => wp_basename( $bucket ),
				'name'     => $meta['name'],
				'path'     => isset( $meta['path'] ) ? $meta['path'] : '',
				'dir'      => ! empty( $meta['dir'] ),
				'time'     => $time,
				'when'     => wp_date( $format, $time ),
				'ago'      => sprintf(
					/* translators: %s: human time difference. */
					__( '%s ago', 'beaver-filemanager' ),
					human_time_diff( $time )
				),
				'user'     => isset( $meta['user'] ) ? $meta['user'] : '',
				'size'     => $size,
				'sizeText' => is_dir( $item ) ? '—' : size_format( $size, 0 ),
				'exists'   => file_exists( $item ),
			);
		}

		return $entries;
	}

	/**
	 * Validates a trash identifier and returns its folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Trash identifier.
	 * @return string|WP_Error
	 */
	private static function trash_bucket( $id ) {
		$id = preg_replace( '/[^0-9a-zA-Z\-]/', '', (string) $id );

		if ( '' === $id ) {
			return new WP_Error( 'beaver_fm_bad_trash', __( 'That trash item is not valid.', 'beaver-filemanager' ) );
		}

		$bucket = self::trash_dir() . '/' . $id;

		if ( ! is_dir( $bucket ) ) {
			return new WP_Error( 'beaver_fm_no_trash', __( 'That item is no longer in the trash.', 'beaver-filemanager' ) );
		}

		return $bucket;
	}

	/**
	 * Puts a trashed item back where it came from.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Trash identifier.
	 * @return array|WP_Error
	 */
	public static function restore_trash( $id ) {
		$bucket = self::trash_bucket( $id );

		if ( is_wp_error( $bucket ) ) {
			return $bucket;
		}

		$meta = self::read_json( $bucket . '/beaver-fm.json' );

		if ( ! $meta || empty( $meta['path'] ) ) {
			return new WP_Error( 'beaver_fm_no_trash', __( 'This item has lost the record of where it came from.', 'beaver-filemanager' ) );
		}

		$source = $bucket . '/' . $meta['name'];

		if ( ! file_exists( $source ) ) {
			return new WP_Error( 'beaver_fm_no_trash', __( 'The trashed item itself is missing.', 'beaver-filemanager' ) );
		}

		$target = Beaver_FM_Filesystem::resolve( $meta['path'], false );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( file_exists( $target ) ) {
			$dir    = dirname( $target );
			$target = $dir . '/' . Beaver_FM_Filesystem::unique_name( $dir, wp_basename( $target ) );
		}

		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$copied = Beaver_FM_Filesystem::copy_path( $source, $target );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}

			Beaver_FM_Filesystem::erase( $source );
		}

		Beaver_FM_Filesystem::erase( $bucket );
		Beaver_FM_Logger::record( 'restore', Beaver_FM_Filesystem::relative( $target ) );

		return Beaver_FM_Filesystem::entry( $target );
	}

	/**
	 * Erases one trashed item for good.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Trash identifier.
	 * @return true|WP_Error
	 */
	public static function delete_trash( $id ) {
		$bucket = self::trash_bucket( $id );

		if ( is_wp_error( $bucket ) ) {
			return $bucket;
		}

		$result = Beaver_FM_Filesystem::erase( $bucket );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Beaver_FM_Logger::record( 'delete', '', __( 'emptied one item from the trash', 'beaver-filemanager' ) );

		return true;
	}

	/**
	 * Erases everything in the trash.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of items removed.
	 */
	public static function empty_trash() {
		$buckets = glob( self::trash_dir() . '/*', GLOB_ONLYDIR );
		$removed = 0;

		if ( ! is_array( $buckets ) ) {
			return 0;
		}

		foreach ( $buckets as $bucket ) {
			if ( ! is_wp_error( Beaver_FM_Filesystem::erase( $bucket ) ) ) {
				++$removed;
			}
		}

		Beaver_FM_Logger::record(
			'delete',
			'',
			sprintf(
				/* translators: %d: number of items. */
				__( 'emptied the trash (%d items)', 'beaver-filemanager' ),
				$removed
			)
		);

		return $removed;
	}

	/**
	 * How much space the private storage is using.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function storage_usage() {
		$backups = is_dir( self::backups_dir() ) ? Beaver_FM_Filesystem::dir_stats( self::backups_dir() ) : null;
		$trash   = is_dir( self::trash_dir() ) ? Beaver_FM_Filesystem::dir_stats( self::trash_dir() ) : null;

		return array(
			'backups' => $backups ? $backups['sizeText'] : size_format( 0 ),
			'trash'   => $trash ? $trash['sizeText'] : size_format( 0 ),
		);
	}

	/**
	 * Builds an identifier that sorts chronologically as plain text.
	 *
	 * Whole seconds are not enough: saving twice inside one second produced two
	 * identifiers whose order then came down to the random suffix, which put the
	 * history panel — and the pruning of old versions — in the wrong order. The
	 * zero-padded seconds and ten-thousandths fix that.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function version_id() {
		$now      = microtime( true );
		$seconds  = (int) $now;
		$fraction = (int) floor( ( $now - $seconds ) * 10000 );

		return sprintf( '%010d-%04d-%s', $seconds, $fraction, wp_generate_password( 6, false, false ) );
	}

	/**
	 * Reads a small JSON sidecar file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @return array
	 */
	private static function read_json( $path ) {
		if ( ! is_file( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $raw ) {
			return array();
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : array();
	}
}
