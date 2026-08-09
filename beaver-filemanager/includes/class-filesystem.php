<?php
/**
 * Sandboxed filesystem access.
 *
 * @package BeaverFileManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every filesystem operation the plugin performs, jailed to one root folder.
 *
 * The single rule this class enforces: a caller hands in a path *relative to
 * the configured root*, and gets back an absolute path only if that path really
 * resolves inside the root. `..` is stripped before the filesystem is touched
 * at all, and the result is then re-checked with `realpath()` so a symlink
 * pointing out of the jail is caught too.
 *
 * @since 1.0.0
 */
class Beaver_FM_Filesystem {

	/**
	 * Extensions the editor will open as text.
	 *
	 * @var string[]
	 */
	const TEXT_EXTENSIONS = array(
		'php', 'phtml', 'php5', 'php7', 'phps', 'inc', 'module', 'install', 'theme',
		'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'svelte', 'coffee',
		'css', 'scss', 'sass', 'less', 'styl',
		'html', 'htm', 'xhtml', 'xml', 'svg', 'twig', 'blade', 'tpl', 'liquid', 'hbs', 'mustache',
		'json', 'jsonc', 'yml', 'yaml', 'toml', 'ini', 'cfg', 'conf', 'config', 'env', 'properties',
		'md', 'markdown', 'txt', 'text', 'rst', 'log', 'csv', 'tsv',
		'sql', 'sh', 'bash', 'zsh', 'fish', 'bat', 'cmd', 'ps1',
		'htaccess', 'htpasswd', 'gitignore', 'gitattributes', 'gitmodules', 'editorconfig',
		'po', 'pot', 'lock', 'dist', 'example', 'sample', 'patch', 'diff',
		'py', 'rb', 'pl', 'go', 'rs', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'swift', 'kt', 'lua',
		'dockerfile', 'nginx', 'service', 'plist', 'map', 'srt', 'vtt',
	);

	/**
	 * Extensions previewed as images.
	 *
	 * @var string[]
	 */
	const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg', 'apng' );

	/**
	 * Extensions previewed with a media player.
	 *
	 * @var string[]
	 */
	const VIDEO_EXTENSIONS = array( 'mp4', 'm4v', 'webm', 'ogv', 'mov' );

	/**
	 * Extensions previewed with an audio player.
	 *
	 * @var string[]
	 */
	const AUDIO_EXTENSIONS = array( 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'weba' );

	/**
	 * Extensions treated as archives.
	 *
	 * @var string[]
	 */
	const ARCHIVE_EXTENSIONS = array( 'zip', 'tar', 'gz', 'tgz', 'bz2', 'rar', '7z', 'xz' );

	/**
	 * Extensions treated as fonts.
	 *
	 * @var string[]
	 */
	const FONT_EXTENSIONS = array( 'woff', 'woff2', 'ttf', 'otf', 'eot' );

	/**
	 * Runtime cache of the resolved root.
	 *
	 * @var string|null
	 */
	private static $root = null;

	/*
	 * -----------------------------------------------------------------------
	 * The jail
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Normalizes a path to forward slashes with no trailing separator.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	public static function norm( $path ) {
		return untrailingslashit( wp_normalize_path( (string) $path ) );
	}

	/**
	 * The uploads base directory.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function uploads_dir() {
		$uploads = wp_upload_dir( null, false );

		return isset( $uploads['basedir'] ) ? self::norm( $uploads['basedir'] ) : self::norm( WP_CONTENT_DIR . '/uploads' );
	}

	/**
	 * The absolute path everything is jailed inside.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty string when the configured root cannot be reached.
	 */
	public static function root_path() {
		if ( null !== self::$root ) {
			return self::$root;
		}

		switch ( Beaver_FM_Settings::value( 'root', 'abspath' ) ) {
			case 'wp-content':
				$path = WP_CONTENT_DIR;
				break;

			case 'uploads':
				$path = self::uploads_dir();
				break;

			case 'custom':
				$path = Beaver_FM_Settings::value( 'custom_root', '' );
				break;

			case 'abspath':
			default:
				$path = ABSPATH;
				break;
		}

		$real = '' === (string) $path ? false : realpath( $path );

		self::$root = ( $real && is_dir( $real ) ) ? self::norm( $real ) : '';

		return self::$root;
	}

	/**
	 * Case-correct prefix test — Windows paths compare case-insensitively.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path   Path to test.
	 * @param string $prefix Expected prefix.
	 * @return bool
	 */
	private static function starts_with( $path, $prefix ) {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 0 === stripos( $path, $prefix );
		}

		return 0 === strpos( $path, $prefix );
	}

	/**
	 * Whether two paths are the same path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $a First path.
	 * @param string $b Second path.
	 * @return bool
	 */
	private static function same_path( $a, $b ) {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return 0 === strcasecmp( $a, $b );
		}

		return $a === $b;
	}

	/**
	 * Whether an absolute path sits inside a root.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @param string $root Absolute root.
	 * @return bool
	 */
	public static function within( $path, $root ) {
		$path = self::norm( $path );
		$root = self::norm( $root );

		if ( '' === $root || '' === $path ) {
			return false;
		}

		return self::same_path( $path, $root ) || self::starts_with( $path, $root . '/' );
	}

	/**
	 * Collapses `.` and `..` segments without touching the filesystem.
	 *
	 * Doing this before any `stat()` means a traversal attempt never even
	 * reaches the disk, which also keeps `open_basedir` warnings out of the log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Untrusted relative path.
	 * @return string Clean relative path, possibly empty.
	 */
	public static function clean_relative( $relative ) {
		$relative = str_replace( "\0", '', (string) $relative );
		$relative = str_replace( '\\', '/', $relative );

		$segments = array();

		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Turns a relative path into a verified absolute path inside the root.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative   Path relative to the root.
	 * @param bool   $must_exist Whether the target has to exist already.
	 * @return string|WP_Error Absolute path, or an error.
	 */
	public static function resolve( $relative, $must_exist = true ) {
		$root = self::root_path();

		if ( '' === $root ) {
			return new WP_Error(
				'beaver_fm_no_root',
				__( 'The folder Beaver FileManager is set to browse does not exist on this server. Check Settings.', 'beaver-filemanager' )
			);
		}

		$clean = self::clean_relative( $relative );
		$path  = '' === $clean ? $root : $root . '/' . $clean;
		$real  = realpath( $path );

		if ( false !== $real ) {
			$real = self::norm( $real );

			if ( ! self::within( $real, $root ) ) {
				return new WP_Error(
					'beaver_fm_outside',
					__( 'That path resolves outside the folder this manager is allowed to touch.', 'beaver-filemanager' )
				);
			}

			if ( self::is_private( $real ) ) {
				return new WP_Error(
					'beaver_fm_private',
					__( 'That is Beaver FileManager’s own backup storage. Use the Backups and Trash panels to work with it.', 'beaver-filemanager' )
				);
			}

			return $real;
		}

		if ( $must_exist ) {
			return new WP_Error(
				'beaver_fm_missing',
				/* translators: %s: path. */
				sprintf( __( '“%s” no longer exists. Refresh the list.', 'beaver-filemanager' ), $clean )
			);
		}

		$parent = realpath( dirname( $path ) );

		if ( false === $parent ) {
			return new WP_Error(
				'beaver_fm_missing_parent',
				__( 'The folder that should hold this item does not exist.', 'beaver-filemanager' )
			);
		}

		$parent = self::norm( $parent );

		if ( ! self::within( $parent, $root ) || self::is_private( $parent ) ) {
			return new WP_Error(
				'beaver_fm_outside',
				__( 'That path resolves outside the folder this manager is allowed to touch.', 'beaver-filemanager' )
			);
		}

		return $parent . '/' . wp_basename( $path );
	}

	/**
	 * Expresses an absolute path relative to the root.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @return string Empty string for the root itself.
	 */
	public static function relative( $absolute ) {
		$root     = self::root_path();
		$absolute = self::norm( $absolute );

		if ( '' === $root || self::same_path( $absolute, $root ) ) {
			return '';
		}

		if ( self::starts_with( $absolute, $root . '/' ) ) {
			return substr( $absolute, strlen( $root ) + 1 );
		}

		return '';
	}

	/**
	 * Whether a path belongs to the plugin's own private storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @return bool
	 */
	public static function is_private( $absolute ) {
		$storage = Beaver_FM_Editor::storage_dir();

		return '' !== $storage && self::within( $absolute, $storage );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Describing files
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds the description of one file or folder used throughout the UI.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path inside the root.
	 * @return array
	 */
	public static function entry( $absolute ) {
		$absolute = self::norm( $absolute );
		$name     = wp_basename( $absolute );
		$is_dir   = is_dir( $absolute );
		$is_link  = is_link( $absolute );
		$stat     = @stat( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ext      = $is_dir ? '' : strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( '' === $ext && ! $is_dir && '.' === substr( $name, 0, 1 ) ) {
			$ext = strtolower( ltrim( $name, '.' ) );
		}

		$size  = ( ! $is_dir && $stat ) ? (int) $stat['size'] : 0;
		$mtime = $stat ? (int) $stat['mtime'] : 0;
		$mode  = $stat ? (int) $stat['mode'] : 0;
		$kind  = self::kind( $ext, $is_dir );

		return array(
			'name'      => $name,
			'path'      => self::relative( $absolute ),
			'dir'       => $is_dir,
			'link'      => $is_link,
			'linkTo'    => $is_link ? (string) @readlink( $absolute ) : '', // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			'size'      => $size,
			'sizeText'  => $is_dir ? '—' : size_format( $size, $size < MB_IN_BYTES ? 0 : 1 ),
			'mtime'     => $mtime,
			'modified'  => $mtime ? wp_date( 'Y-m-d H:i', $mtime ) : '',
			'perms'     => $mode ? substr( sprintf( '%o', $mode ), -4 ) : '',
			'permsText' => $mode ? self::perms_string( $mode ) : '',
			'ext'       => $ext,
			'kind'      => $kind,
			'readable'  => is_readable( $absolute ),
			'writable'  => is_writable( $absolute ),
			'editable'  => ! $is_dir && self::is_editable( $absolute, $ext, $size ),
			'preview'   => $is_dir ? '' : self::preview_kind( $ext ),
			'url'       => $is_dir ? '' : self::public_url( $absolute ),
		);
	}

	/**
	 * Coarse category used for icons and grouping.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ext    Lower-case extension.
	 * @param bool   $is_dir Whether the entry is a folder.
	 * @return string
	 */
	public static function kind( $ext, $is_dir ) {
		if ( $is_dir ) {
			return 'folder';
		}

		if ( in_array( $ext, self::IMAGE_EXTENSIONS, true ) ) {
			return 'image';
		}

		if ( in_array( $ext, self::VIDEO_EXTENSIONS, true ) ) {
			return 'video';
		}

		if ( in_array( $ext, self::AUDIO_EXTENSIONS, true ) ) {
			return 'audio';
		}

		if ( in_array( $ext, self::ARCHIVE_EXTENSIONS, true ) ) {
			return 'archive';
		}

		if ( in_array( $ext, self::FONT_EXTENSIONS, true ) ) {
			return 'font';
		}

		if ( 'pdf' === $ext ) {
			return 'pdf';
		}

		if ( in_array( $ext, array( 'php', 'phtml', 'js', 'mjs', 'jsx', 'ts', 'tsx', 'css', 'scss', 'less', 'html', 'htm', 'xml', 'json', 'sql', 'sh', 'py', 'rb', 'go', 'rs', 'java', 'vue' ), true ) ) {
			return 'code';
		}

		if ( in_array( $ext, self::TEXT_EXTENSIONS, true ) ) {
			return 'text';
		}

		return 'file';
	}

	/**
	 * How a file should be previewed, if at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ext Lower-case extension.
	 * @return string One of image, video, audio, pdf, or an empty string.
	 */
	public static function preview_kind( $ext ) {
		if ( in_array( $ext, self::IMAGE_EXTENSIONS, true ) ) {
			return 'image';
		}

		if ( in_array( $ext, self::VIDEO_EXTENSIONS, true ) ) {
			return 'video';
		}

		if ( in_array( $ext, self::AUDIO_EXTENSIONS, true ) ) {
			return 'audio';
		}

		if ( 'pdf' === $ext ) {
			return 'pdf';
		}

		return '';
	}

	/**
	 * Whether the editor should offer to open this file.
	 *
	 * Known text extensions are trusted outright; anything else is sniffed, so
	 * an extensionless `Dockerfile` or `CHANGELOG` still opens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @param string $ext      Lower-case extension.
	 * @param int    $size     File size in bytes.
	 * @return bool
	 */
	public static function is_editable( $absolute, $ext, $size ) {
		if ( $size > Beaver_FM_Settings::max_edit_bytes() ) {
			return false;
		}

		if ( in_array( $ext, self::TEXT_EXTENSIONS, true ) ) {
			return true;
		}

		if ( in_array( $ext, self::IMAGE_EXTENSIONS, true ) || in_array( $ext, self::VIDEO_EXTENSIONS, true )
			|| in_array( $ext, self::AUDIO_EXTENSIONS, true ) || in_array( $ext, self::ARCHIVE_EXTENSIONS, true )
			|| in_array( $ext, self::FONT_EXTENSIONS, true ) || 'pdf' === $ext ) {
			return false;
		}

		return self::looks_like_text( $absolute );
	}

	/**
	 * Sniffs the first block of a file for binary bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @return bool
	 */
	public static function looks_like_text( $absolute ) {
		if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) {
			return false;
		}

		$handle = @fopen( $absolute, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return false;
		}

		$sample = (string) fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( '' === $sample ) {
			return true;
		}

		return false === strpos( $sample, "\0" );
	}

	/**
	 * Renders a mode integer as `drwxr-xr-x`.
	 *
	 * @since 1.0.0
	 *
	 * @param int $mode Mode from `stat()`.
	 * @return string
	 */
	public static function perms_string( $mode ) {
		if ( ( $mode & 0xC000 ) === 0xC000 ) {
			$out = 's';
		} elseif ( ( $mode & 0xA000 ) === 0xA000 ) {
			$out = 'l';
		} elseif ( ( $mode & 0x8000 ) === 0x8000 ) {
			$out = '-';
		} elseif ( ( $mode & 0x6000 ) === 0x6000 ) {
			$out = 'b';
		} elseif ( ( $mode & 0x4000 ) === 0x4000 ) {
			$out = 'd';
		} elseif ( ( $mode & 0x2000 ) === 0x2000 ) {
			$out = 'c';
		} elseif ( ( $mode & 0x1000 ) === 0x1000 ) {
			$out = 'p';
		} else {
			$out = '?';
		}

		$out .= ( $mode & 0x0100 ) ? 'r' : '-';
		$out .= ( $mode & 0x0080 ) ? 'w' : '-';
		$out .= ( $mode & 0x0040 ) ? ( ( $mode & 0x0800 ) ? 's' : 'x' ) : ( ( $mode & 0x0800 ) ? 'S' : '-' );

		$out .= ( $mode & 0x0020 ) ? 'r' : '-';
		$out .= ( $mode & 0x0010 ) ? 'w' : '-';
		$out .= ( $mode & 0x0008 ) ? ( ( $mode & 0x0400 ) ? 's' : 'x' ) : ( ( $mode & 0x0400 ) ? 'S' : '-' );

		$out .= ( $mode & 0x0004 ) ? 'r' : '-';
		$out .= ( $mode & 0x0002 ) ? 'w' : '-';
		$out .= ( $mode & 0x0001 ) ? ( ( $mode & 0x0200 ) ? 't' : 'x' ) : ( ( $mode & 0x0200 ) ? 'T' : '-' );

		return $out;
	}

	/**
	 * The browsable URL of a file, when it happens to be web-reachable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @return string Empty string when the file is not under a known web root.
	 */
	public static function public_url( $absolute ) {
		$map = array(
			array( self::norm( WP_CONTENT_DIR ), untrailingslashit( content_url() ) ),
			array( self::norm( ABSPATH ), untrailingslashit( site_url() ) ),
		);

		foreach ( $map as $pair ) {
			list( $dir, $url ) = $pair;

			if ( '' === $dir || ! self::starts_with( $absolute, $dir . '/' ) ) {
				continue;
			}

			$rel   = substr( $absolute, strlen( $dir ) + 1 );
			$parts = array_map( 'rawurlencode', explode( '/', $rel ) );

			return $url . '/' . implode( '/', $parts );
		}

		return '';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Reading
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Lists the contents of a folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Folder relative to the root.
	 * @return array|WP_Error
	 */
	public static function list_dir( $relative ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		if ( ! is_dir( $absolute ) ) {
			return new WP_Error( 'beaver_fm_not_dir', __( 'That is a file, not a folder.', 'beaver-filemanager' ) );
		}

		$handle = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'beaver_fm_unreadable',
				__( 'This folder cannot be read. Its permissions do not allow the web server in.', 'beaver-filemanager' )
			);
		}

		$show_hidden = (bool) Beaver_FM_Settings::value( 'show_hidden' );
		$items       = array();

		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			if ( ! $show_hidden && '.' === substr( $name, 0, 1 ) ) {
				continue;
			}

			$child = $absolute . '/' . $name;

			if ( self::is_private( $child ) ) {
				continue;
			}

			$items[] = self::entry( $child );
		}

		closedir( $handle );

		usort( $items, array( __CLASS__, 'compare_entries' ) );

		return array(
			'path'      => self::relative( $absolute ),
			'absolute'  => $absolute,
			'writable'  => is_writable( $absolute ),
			'crumbs'    => self::crumbs( self::relative( $absolute ) ),
			'items'     => $items,
			'total'     => count( $items ),
		);
	}

	/**
	 * Sorts folders first, then by name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $a First entry.
	 * @param array $b Second entry.
	 * @return int
	 */
	public static function compare_entries( $a, $b ) {
		if ( $a['dir'] !== $b['dir'] ) {
			return $a['dir'] ? -1 : 1;
		}

		return strnatcasecmp( $a['name'], $b['name'] );
	}

	/**
	 * Breadcrumb segments for a relative path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Relative path.
	 * @return array[]
	 */
	public static function crumbs( $relative ) {
		$crumbs = array(
			array(
				'name' => __( 'Root', 'beaver-filemanager' ),
				'path' => '',
			),
		);

		$relative = self::clean_relative( $relative );

		if ( '' === $relative ) {
			return $crumbs;
		}

		$walked = '';

		foreach ( explode( '/', $relative ) as $segment ) {
			$walked   = '' === $walked ? $segment : $walked . '/' . $segment;
			$crumbs[] = array(
				'name' => $segment,
				'path' => $walked,
			);
		}

		return $crumbs;
	}

	/**
	 * Lists only the sub-folders of a folder, for the sidebar tree.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Folder relative to the root.
	 * @return array|WP_Error
	 */
	public static function list_folders( $relative ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		$handle = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return array();
		}

		$show_hidden = (bool) Beaver_FM_Settings::value( 'show_hidden' );
		$folders     = array();

		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			if ( ! $show_hidden && '.' === substr( $name, 0, 1 ) ) {
				continue;
			}

			$child = $absolute . '/' . $name;

			if ( ! is_dir( $child ) || self::is_private( $child ) ) {
				continue;
			}

			$folders[] = array(
				'name'     => $name,
				'path'     => self::relative( $child ),
				'children' => self::has_subfolders( $child ),
			);
		}

		closedir( $handle );

		usort(
			$folders,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		return $folders;
	}

	/**
	 * Whether a folder contains at least one sub-folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute folder path.
	 * @return bool
	 */
	private static function has_subfolders( $absolute ) {
		$handle = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return false;
		}

		$found = false;

		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			if ( is_dir( $absolute . '/' . $name ) ) {
				$found = true;
				break;
			}
		}

		closedir( $handle );

		return $found;
	}

	/**
	 * Reads a text file for the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative File relative to the root.
	 * @return array|WP_Error
	 */
	public static function read( $relative ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		if ( ! is_file( $absolute ) ) {
			return new WP_Error( 'beaver_fm_not_file', __( 'That is a folder, not a file.', 'beaver-filemanager' ) );
		}

		$size = (int) filesize( $absolute );
		$max  = Beaver_FM_Settings::max_edit_bytes();

		if ( $size > $max ) {
			return new WP_Error(
				'beaver_fm_too_big',
				sprintf(
					/* translators: 1: file size, 2: configured limit. */
					__( 'This file is %1$s, and the editor is set to open files up to %2$s. Raise the limit in Settings or download it instead.', 'beaver-filemanager' ),
					size_format( $size ),
					size_format( $max )
				)
			);
		}

		if ( ! is_readable( $absolute ) ) {
			return new WP_Error( 'beaver_fm_unreadable', __( 'This file cannot be read. Check its permissions.', 'beaver-filemanager' ) );
		}

		$content = file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $content ) {
			return new WP_Error( 'beaver_fm_read_failed', __( 'Reading the file failed.', 'beaver-filemanager' ) );
		}

		$entry = self::entry( $absolute );

		return array(
			'entry'    => $entry,
			'content'  => $content,
			'hash'     => md5( $content ),
			'mode'     => self::editor_mode( $entry['ext'], $entry['name'] ),
			'lines'    => substr_count( $content, "\n" ) + 1,
			'writable' => is_writable( $absolute ),
		);
	}

	/**
	 * Maps a file to the editor settings key used on the JS side.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ext  Lower-case extension.
	 * @param string $name File name.
	 * @return string
	 */
	public static function editor_mode( $ext, $name = '' ) {
		$name = strtolower( $name );

		if ( 'dockerfile' === $name || 'makefile' === $name ) {
			return 'txt';
		}

		$aliases = array(
			'php'   => array( 'php', 'phtml', 'php5', 'php7', 'inc', 'module', 'install', 'theme' ),
			'js'    => array( 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'svelte' ),
			'json'  => array( 'json', 'jsonc', 'lock', 'map' ),
			'css'   => array( 'css' ),
			'scss'  => array( 'scss', 'sass', 'less', 'styl' ),
			'html'  => array( 'html', 'htm', 'xhtml', 'twig', 'blade', 'tpl', 'liquid', 'hbs', 'mustache' ),
			'xml'   => array( 'xml', 'svg', 'rss', 'plist' ),
			'md'    => array( 'md', 'markdown' ),
			'yaml'  => array( 'yml', 'yaml' ),
			'sql'   => array( 'sql' ),
			'sh'    => array( 'sh', 'bash', 'zsh', 'fish' ),
			'ini'   => array( 'ini', 'cfg', 'conf', 'config', 'env', 'htaccess', 'htpasswd', 'properties', 'toml', 'editorconfig', 'gitignore', 'gitattributes' ),
		);

		foreach ( $aliases as $mode => $extensions ) {
			if ( in_array( $ext, $extensions, true ) ) {
				return $mode;
			}
		}

		return 'txt';
	}

	/**
	 * Gathers everything the details panel shows about one item.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Path relative to the root.
	 * @return array|WP_Error
	 */
	public static function info( $relative ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		$entry = self::entry( $absolute );
		$stat  = @stat( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$info = array(
			'entry'    => $entry,
			'absolute' => $absolute,
			'owner'    => self::owner_name( $stat ),
			'group'    => self::group_name( $stat ),
			'created'  => $stat ? wp_date( 'Y-m-d H:i:s', (int) $stat['ctime'] ) : '',
			'accessed' => $stat ? wp_date( 'Y-m-d H:i:s', (int) $stat['atime'] ) : '',
			'mime'     => self::mime_of( $absolute, $entry['ext'] ),
			'contents' => null,
			'image'    => null,
			'archive'  => null,
			'checksum' => '',
		);

		if ( $entry['dir'] ) {
			$info['contents'] = self::dir_stats( $absolute );
		} else {
			if ( $entry['size'] > 0 && $entry['size'] <= 32 * MB_IN_BYTES ) {
				$info['checksum'] = (string) md5_file( $absolute );
			}

			if ( 'image' === $entry['kind'] && 'svg' !== $entry['ext'] ) {
				$dimensions = @getimagesize( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( $dimensions ) {
					$info['image'] = array(
						'width'  => (int) $dimensions[0],
						'height' => (int) $dimensions[1],
					);
				}
			}

			if ( 'zip' === $entry['ext'] ) {
				$info['archive'] = self::zip_contents( $absolute );
			}
		}

		return $info;
	}

	/**
	 * Resolves the owning user name, falling back to the numeric id.
	 *
	 * @since 1.0.0
	 *
	 * @param array|false $stat Result of `stat()`.
	 * @return string
	 */
	private static function owner_name( $stat ) {
		if ( ! $stat ) {
			return '';
		}

		if ( function_exists( 'posix_getpwuid' ) ) {
			$owner = @posix_getpwuid( (int) $stat['uid'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( isset( $owner['name'] ) ) {
				return $owner['name'];
			}
		}

		return (string) $stat['uid'];
	}

	/**
	 * Resolves the owning group name, falling back to the numeric id.
	 *
	 * @since 1.0.0
	 *
	 * @param array|false $stat Result of `stat()`.
	 * @return string
	 */
	private static function group_name( $stat ) {
		if ( ! $stat ) {
			return '';
		}

		if ( function_exists( 'posix_getgrgid' ) ) {
			$group = @posix_getgrgid( (int) $stat['gid'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( isset( $group['name'] ) ) {
				return $group['name'];
			}
		}

		return (string) $stat['gid'];
	}

	/**
	 * Best available MIME type for a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @param string $ext      Lower-case extension.
	 * @return string
	 */
	public static function mime_of( $absolute, $ext ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = @finfo_open( FILEINFO_MIME_TYPE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $finfo ) {
				$mime = @finfo_file( $finfo, $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				finfo_close( $finfo );

				if ( $mime ) {
					return $mime;
				}
			}
		}

		$checked = wp_check_filetype( 'x.' . $ext );

		if ( ! empty( $checked['type'] ) ) {
			return $checked['type'];
		}

		return in_array( $ext, self::TEXT_EXTENSIONS, true ) ? 'text/plain' : 'application/octet-stream';
	}

	/**
	 * Counts what is inside a folder, with a hard ceiling so it always returns.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute folder path.
	 * @return array
	 */
	public static function dir_stats( $absolute ) {
		$files    = 0;
		$folders  = 0;
		$bytes    = 0;
		$capped   = false;
		$ceiling  = 60000;
		$deadline = microtime( true ) + 8;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $absolute, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $item ) {
				if ( $files + $folders >= $ceiling || microtime( true ) > $deadline ) {
					$capped = true;
					break;
				}

				if ( $item->isDir() ) {
					++$folders;
					continue;
				}

				++$files;
				$bytes += (int) $item->getSize();
			}
		} catch ( Exception $e ) {
			$capped = true;
		}

		return array(
			'files'    => $files,
			'folders'  => $folders,
			'bytes'    => $bytes,
			'sizeText' => size_format( $bytes, 1 ),
			'capped'   => $capped,
		);
	}

	/**
	 * Lists the entries inside a zip archive without extracting it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path to a zip file.
	 * @return array|null
	 */
	public static function zip_contents( $absolute ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $absolute ) ) {
			return null;
		}

		$entries = array();
		$count   = $zip->numFiles;

		for ( $i = 0; $i < min( $count, 500 ); $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( ! $stat ) {
				continue;
			}

			$entries[] = array(
				'name'     => $stat['name'],
				'size'     => (int) $stat['size'],
				'sizeText' => size_format( (int) $stat['size'], 0 ),
			);
		}

		$zip->close();

		return array(
			'count'   => $count,
			'shown'   => count( $entries ),
			'entries' => $entries,
		);
	}

	/**
	 * Free and total space on the volume holding the root.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function disk() {
		$root  = self::root_path();
		$free  = 0;
		$total = 0;

		if ( '' !== $root && function_exists( 'disk_free_space' ) ) {
			$free = (float) @disk_free_space( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( '' !== $root && function_exists( 'disk_total_space' ) ) {
			$total = (float) @disk_total_space( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return array(
			'free'      => $free,
			'total'     => $total,
			'freeText'  => $free > 0 ? size_format( $free, 1 ) : '',
			'totalText' => $total > 0 ? size_format( $total, 1 ) : '',
			'usedPct'   => $total > 0 ? round( ( ( $total - $free ) / $total ) * 100 ) : 0,
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Searching
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Walks a folder tree looking for names and, optionally, file contents.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Folder to search under.
	 * @param string $query    Search term.
	 * @param array  $args     {
	 *     Optional. Search options.
	 *
	 *     @type bool   $contents  Search inside text files too.
	 *     @type bool   $sensitive Match case.
	 *     @type string $ext       Comma separated extension filter.
	 * }
	 * @return array|WP_Error
	 */
	public static function search( $relative, $query, $args = array() ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		$query = (string) $query;

		if ( '' === trim( $query ) ) {
			return new WP_Error( 'beaver_fm_empty_query', __( 'Type something to search for.', 'beaver-filemanager' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'contents'  => false,
				'sensitive' => false,
				'ext'       => '',
			)
		);

		$extensions = preg_split( '/[\s,]+/', strtolower( (string) $args['ext'] ), -1, PREG_SPLIT_NO_EMPTY );
		$extensions = is_array( $extensions ) ? array_map( static function ( $e ) {
			return ltrim( $e, '.' );
		}, $extensions ) : array();

		$max_files   = absint( Beaver_FM_Settings::value( 'search_max_files', 20000 ) );
		$max_results = 500;
		$deadline    = microtime( true ) + 20;
		$show_hidden = (bool) Beaver_FM_Settings::value( 'show_hidden' );

		$scanned = 0;
		$results = array();
		$capped  = false;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $absolute, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $item ) {
				if ( $scanned >= $max_files || count( $results ) >= $max_results || microtime( true ) > $deadline ) {
					$capped = true;
					break;
				}

				$path = self::norm( $item->getPathname() );
				$name = $item->getFilename();

				if ( self::is_private( $path ) ) {
					continue;
				}

				if ( ! $show_hidden && false !== strpos( '/' . self::relative( $path ), '/.' ) ) {
					continue;
				}

				++$scanned;

				$is_dir = $item->isDir();
				$ext    = $is_dir ? '' : strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

				if ( $extensions && ( $is_dir || ! in_array( $ext, $extensions, true ) ) ) {
					continue;
				}

				$name_hit = $args['sensitive']
					? ( false !== strpos( $name, $query ) )
					: ( false !== stripos( $name, $query ) );

				$matches = array();

				if ( ! $is_dir && $args['contents'] ) {
					$matches = self::grep( $path, $query, (bool) $args['sensitive'], $ext );
				}

				if ( ! $name_hit && ! $matches ) {
					continue;
				}

				$entry            = self::entry( $path );
				$entry['matches'] = $matches;
				$entry['nameHit'] = $name_hit;
				$entry['parent']  = self::relative( dirname( $path ) );

				$results[] = $entry;
			}
		} catch ( Exception $e ) {
			$capped = true;
		}

		return array(
			'query'   => $query,
			'root'    => self::relative( $absolute ),
			'scanned' => $scanned,
			'results' => $results,
			'capped'  => $capped,
		);
	}

	/**
	 * Finds matching lines inside one text file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute  Absolute path.
	 * @param string $query     Search term.
	 * @param bool   $sensitive Match case.
	 * @param string $ext       Lower-case extension.
	 * @return array[] Up to five matches, each with a line number and snippet.
	 */
	private static function grep( $absolute, $query, $sensitive, $ext ) {
		$size = (int) @filesize( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $size <= 0 || $size > 2 * MB_IN_BYTES ) {
			return array();
		}

		if ( ! in_array( $ext, self::TEXT_EXTENSIONS, true ) && ! self::looks_like_text( $absolute ) ) {
			return array();
		}

		$handle = @fopen( $absolute, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return array();
		}

		$matches = array();
		$line_no = 0;

		while ( false !== ( $line = fgets( $handle ) ) && count( $matches ) < 5 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			++$line_no;

			$hit = $sensitive ? strpos( $line, $query ) : stripos( $line, $query );

			if ( false === $hit ) {
				continue;
			}

			$snippet = trim( (string) $line );

			if ( strlen( $snippet ) > 240 ) {
				$start   = max( 0, $hit - 60 );
				$snippet = ( $start > 0 ? '…' : '' ) . substr( $snippet, $start, 240 ) . '…';
			}

			$matches[] = array(
				'line' => $line_no,
				'text' => $snippet,
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $matches;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Writing
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The mode new files are created with.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function file_mode() {
		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
	}

	/**
	 * The mode new folders are created with.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function dir_mode() {
		return defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
	}

	/**
	 * Validates a single path segment supplied by a person.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Proposed file or folder name.
	 * @return string|WP_Error Clean name, or an error.
	 */
	public static function clean_name( $name ) {
		$name = trim( str_replace( array( "\0", '/', '\\' ), '', (string) $name ) );

		if ( '' === $name || '.' === $name || '..' === $name ) {
			return new WP_Error( 'beaver_fm_bad_name', __( 'That is not a usable name.', 'beaver-filemanager' ) );
		}

		if ( strlen( $name ) > 240 ) {
			return new WP_Error( 'beaver_fm_long_name', __( 'That name is too long for the filesystem.', 'beaver-filemanager' ) );
		}

		return $name;
	}

	/**
	 * Finds a free name in a folder by appending `-1`, `-2` and so on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir  Absolute folder path.
	 * @param string $name Desired name.
	 * @return string Name that does not collide.
	 */
	public static function unique_name( $dir, $name ) {
		if ( ! file_exists( $dir . '/' . $name ) ) {
			return $name;
		}

		$ext  = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$base = '' === $ext ? $name : substr( $name, 0, -( strlen( $ext ) + 1 ) );
		$i    = 1;

		do {
			$candidate = $base . '-' . $i . ( '' === $ext ? '' : '.' . $ext );
			++$i;
		} while ( file_exists( $dir . '/' . $candidate ) && $i < 1000 );

		return $candidate;
	}

	/**
	 * Creates an empty file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir_relative Parent folder.
	 * @param string $name         New file name.
	 * @return array|WP_Error
	 */
	public static function create_file( $dir_relative, $name ) {
		$name = self::clean_name( $name );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$dir = self::resolve( $dir_relative, true );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'That folder does not accept new files. Check its permissions.', 'beaver-filemanager' ) );
		}

		$target = $dir . '/' . $name;

		if ( file_exists( $target ) ) {
			return new WP_Error( 'beaver_fm_exists', __( 'Something with that name is already here.', 'beaver-filemanager' ) );
		}

		if ( false === file_put_contents( $target, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'beaver_fm_create_failed', __( 'The file could not be created.', 'beaver-filemanager' ) );
		}

		@chmod( $target, self::file_mode() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		Beaver_FM_Logger::record( 'create-file', self::relative( $target ) );

		return self::entry( $target );
	}

	/**
	 * Creates a folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir_relative Parent folder.
	 * @param string $name         New folder name.
	 * @return array|WP_Error
	 */
	public static function create_folder( $dir_relative, $name ) {
		$name = self::clean_name( $name );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$dir = self::resolve( $dir_relative, true );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'That folder does not accept new items. Check its permissions.', 'beaver-filemanager' ) );
		}

		$target = $dir . '/' . $name;

		if ( file_exists( $target ) ) {
			return new WP_Error( 'beaver_fm_exists', __( 'Something with that name is already here.', 'beaver-filemanager' ) );
		}

		if ( ! wp_mkdir_p( $target ) ) {
			return new WP_Error( 'beaver_fm_create_failed', __( 'The folder could not be created.', 'beaver-filemanager' ) );
		}

		Beaver_FM_Logger::record( 'create-folder', self::relative( $target ) );

		return self::entry( $target );
	}

	/**
	 * Writes new content to an existing file.
	 *
	 * The write goes to a temporary file in the same folder and is then renamed
	 * over the target, so a crash mid-write cannot leave a half-written theme
	 * file behind. Permissions and ownership are carried across.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative File relative to the root.
	 * @param string $content  New contents.
	 * @return bool|WP_Error
	 */
	public static function put( $relative, $content ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		if ( ! is_file( $absolute ) ) {
			return new WP_Error( 'beaver_fm_not_file', __( 'That is a folder, not a file.', 'beaver-filemanager' ) );
		}

		if ( ! is_writable( $absolute ) ) {
			return new WP_Error(
				'beaver_fm_not_writable',
				__( 'This file is read-only for the web server. Change its permissions and try again.', 'beaver-filemanager' )
			);
		}

		$dir  = dirname( $absolute );
		$stat = @stat( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$temp = @tempnam( $dir, '.bfm' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $temp ) {
			// A folder that will not take a temp file still deserves a save.
			if ( false === file_put_contents( $absolute, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'beaver_fm_write_failed', __( 'Writing to the file failed.', 'beaver-filemanager' ) );
			}

			return true;
		}

		if ( false === file_put_contents( $temp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return new WP_Error( 'beaver_fm_write_failed', __( 'Writing to the file failed.', 'beaver-filemanager' ) );
		}

		if ( $stat ) {
			@chmod( $temp, $stat['mode'] & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( function_exists( 'posix_geteuid' ) && function_exists( 'chown' ) && 0 === posix_geteuid() ) {
				@chown( $temp, (int) $stat['uid'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@chgrp( $temp, (int) $stat['gid'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		} else {
			@chmod( $temp, self::file_mode() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! @rename( $temp, $absolute ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === file_put_contents( $absolute, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'beaver_fm_write_failed', __( 'Writing to the file failed.', 'beaver-filemanager' ) );
			}
		}

		clearstatcache( true, $absolute );

		if ( function_exists( 'opcache_invalidate' ) && '' !== (string) ini_get( 'opcache.enable' ) ) {
			@opcache_invalidate( $absolute, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return true;
	}

	/**
	 * Renames a file or folder in place.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Item relative to the root.
	 * @param string $new_name Desired new name.
	 * @return array|WP_Error
	 */
	public static function rename( $relative, $new_name ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		$new_name = self::clean_name( $new_name );

		if ( is_wp_error( $new_name ) ) {
			return $new_name;
		}

		$dir    = dirname( $absolute );
		$target = $dir . '/' . $new_name;

		if ( self::same_path( $target, $absolute ) ) {
			return self::entry( $absolute );
		}

		if ( file_exists( $target ) ) {
			return new WP_Error( 'beaver_fm_exists', __( 'Something with that name is already here.', 'beaver-filemanager' ) );
		}

		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'This folder is read-only for the web server.', 'beaver-filemanager' ) );
		}

		if ( ! @rename( $absolute, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'beaver_fm_rename_failed', __( 'Renaming failed.', 'beaver-filemanager' ) );
		}

		Beaver_FM_Logger::record( 'rename', self::relative( $absolute ), $new_name );

		return self::entry( $target );
	}

	/**
	 * Copies or moves items into a folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $sources      Item paths relative to the root.
	 * @param string   $dest_relative Destination folder.
	 * @param string   $mode         Either `copy` or `move`.
	 * @param bool     $overwrite    Replace items already at the destination.
	 * @return array|WP_Error Summary of what happened.
	 */
	public static function transfer( $sources, $dest_relative, $mode = 'copy', $overwrite = false ) {
		$dest = self::resolve( $dest_relative, true );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		if ( ! is_dir( $dest ) ) {
			return new WP_Error( 'beaver_fm_not_dir', __( 'The destination is not a folder.', 'beaver-filemanager' ) );
		}

		if ( ! is_writable( $dest ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'The destination folder is read-only for the web server.', 'beaver-filemanager' ) );
		}

		$done   = 0;
		$errors = array();

		foreach ( (array) $sources as $source ) {
			$absolute = self::resolve( $source, true );

			if ( is_wp_error( $absolute ) ) {
				$errors[] = $absolute->get_error_message();
				continue;
			}

			$name   = wp_basename( $absolute );
			$target = $dest . '/' . $name;

			if ( is_dir( $absolute ) && self::within( $dest, $absolute ) ) {
				$errors[] = sprintf(
					/* translators: %s: folder name. */
					__( '“%s” cannot be put inside itself.', 'beaver-filemanager' ),
					$name
				);
				continue;
			}

			if ( self::same_path( $absolute, $target ) ) {
				if ( 'move' === $mode ) {
					continue;
				}

				$target = $dest . '/' . self::unique_name( $dest, $name );
			} elseif ( file_exists( $target ) ) {
				if ( $overwrite ) {
					$removed = self::erase( $target );

					if ( is_wp_error( $removed ) ) {
						$errors[] = $removed->get_error_message();
						continue;
					}
				} else {
					$target = $dest . '/' . self::unique_name( $dest, $name );
				}
			}

			$ok = 'move' === $mode
				? self::move_path( $absolute, $target )
				: self::copy_path( $absolute, $target );

			if ( is_wp_error( $ok ) ) {
				$errors[] = $ok->get_error_message();
				continue;
			}

			++$done;

			Beaver_FM_Logger::record( $mode, self::relative( $absolute ), self::relative( $target ) );
		}

		return array(
			'done'   => $done,
			'errors' => $errors,
		);
	}

	/**
	 * Moves one path, falling back to copy-then-delete across volumes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Absolute source.
	 * @param string $to   Absolute target.
	 * @return true|WP_Error
	 */
	private static function move_path( $from, $to ) {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}

		$copied = self::copy_path( $from, $to );

		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		$erased = self::erase( $from );

		if ( is_wp_error( $erased ) ) {
			return $erased;
		}

		return true;
	}

	/**
	 * Copies a file, or a folder and everything under it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Absolute source.
	 * @param string $to   Absolute target.
	 * @return true|WP_Error
	 */
	public static function copy_path( $from, $to ) {
		if ( is_link( $from ) ) {
			$link = @readlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $link && function_exists( 'symlink' ) && @symlink( $link, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}
		}

		if ( is_file( $from ) ) {
			if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error(
					'beaver_fm_copy_failed',
					/* translators: %s: file name. */
					sprintf( __( '“%s” could not be copied.', 'beaver-filemanager' ), wp_basename( $from ) )
				);
			}

			$stat = @stat( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( $stat ) {
				@chmod( $to, $stat['mode'] & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			return true;
		}

		if ( ! is_dir( $from ) ) {
			return new WP_Error( 'beaver_fm_copy_failed', __( 'That item cannot be copied.', 'beaver-filemanager' ) );
		}

		if ( ! wp_mkdir_p( $to ) ) {
			return new WP_Error(
				'beaver_fm_copy_failed',
				/* translators: %s: folder name. */
				sprintf( __( '“%s” could not be created at the destination.', 'beaver-filemanager' ), wp_basename( $to ) )
			);
		}

		$handle = @opendir( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'beaver_fm_copy_failed',
				/* translators: %s: folder name. */
				sprintf( __( '“%s” could not be read.', 'beaver-filemanager' ), wp_basename( $from ) )
			);
		}

		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$result = self::copy_path( $from . '/' . $name, $to . '/' . $name );

			if ( is_wp_error( $result ) ) {
				closedir( $handle );

				return $result;
			}
		}

		closedir( $handle );

		return true;
	}

	/**
	 * Permanently removes a file or a folder tree.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path.
	 * @return true|WP_Error
	 */
	public static function erase( $absolute ) {
		if ( is_link( $absolute ) || is_file( $absolute ) ) {
			if ( ! @unlink( $absolute ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error(
					'beaver_fm_delete_failed',
					/* translators: %s: file name. */
					sprintf( __( '“%s” could not be deleted.', 'beaver-filemanager' ), wp_basename( $absolute ) )
				);
			}

			return true;
		}

		if ( ! is_dir( $absolute ) ) {
			return true;
		}

		$handle = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return new WP_Error(
				'beaver_fm_delete_failed',
				/* translators: %s: folder name. */
				sprintf( __( '“%s” could not be read.', 'beaver-filemanager' ), wp_basename( $absolute ) )
			);
		}

		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$result = self::erase( $absolute . '/' . $name );

			if ( is_wp_error( $result ) ) {
				closedir( $handle );

				return $result;
			}
		}

		closedir( $handle );

		if ( ! @rmdir( $absolute ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'beaver_fm_delete_failed',
				/* translators: %s: folder name. */
				sprintf( __( '“%s” could not be removed.', 'beaver-filemanager' ), wp_basename( $absolute ) )
			);
		}

		return true;
	}

	/**
	 * Deletes items, through the trash when the trash is switched on.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $relatives  Paths relative to the root.
	 * @param bool     $permanent  Skip the trash even when it is enabled.
	 * @return array|WP_Error
	 */
	public static function delete( $relatives, $permanent = false ) {
		$use_trash = ! $permanent && Beaver_FM_Settings::value( 'use_trash' );
		$done      = 0;
		$errors    = array();

		foreach ( (array) $relatives as $relative ) {
			$absolute = self::resolve( $relative, true );

			if ( is_wp_error( $absolute ) ) {
				$errors[] = $absolute->get_error_message();
				continue;
			}

			if ( self::same_path( $absolute, self::root_path() ) ) {
				$errors[] = __( 'The root folder itself cannot be deleted.', 'beaver-filemanager' );
				continue;
			}

			$result = $use_trash
				? Beaver_FM_Editor::send_to_trash( $absolute )
				: self::erase( $absolute );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			++$done;

			Beaver_FM_Logger::record( $use_trash ? 'trash' : 'delete', self::relative( $absolute ) );
		}

		return array(
			'done'    => $done,
			'trashed' => (bool) $use_trash,
			'errors'  => $errors,
		);
	}

	/**
	 * Changes permissions on items, optionally recursing.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $relatives Paths relative to the root.
	 * @param string   $mode      Octal mode such as `644`.
	 * @param bool     $recursive Apply to everything underneath too.
	 * @return array|WP_Error
	 */
	public static function chmod( $relatives, $mode, $recursive = false ) {
		$mode = trim( (string) $mode );

		if ( ! preg_match( '/^[0-7]{3,4}$/', $mode ) ) {
			return new WP_Error( 'beaver_fm_bad_mode', __( 'Permissions must be three or four octal digits, like 644 or 0755.', 'beaver-filemanager' ) );
		}

		$octal  = intval( $mode, 8 );
		$done   = 0;
		$errors = array();

		foreach ( (array) $relatives as $relative ) {
			$absolute = self::resolve( $relative, true );

			if ( is_wp_error( $absolute ) ) {
				$errors[] = $absolute->get_error_message();
				continue;
			}

			$count = self::chmod_path( $absolute, $octal, $recursive );

			if ( is_wp_error( $count ) ) {
				$errors[] = $count->get_error_message();
				continue;
			}

			$done += $count;

			Beaver_FM_Logger::record( 'chmod', self::relative( $absolute ), $mode );
		}

		return array(
			'done'   => $done,
			'errors' => $errors,
		);
	}

	/**
	 * Applies a mode to one path and, optionally, its children.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute  Absolute path.
	 * @param int    $octal     Mode as an integer.
	 * @param bool   $recursive Recurse into folders.
	 * @return int|WP_Error Number of items changed.
	 */
	private static function chmod_path( $absolute, $octal, $recursive ) {
		if ( ! @chmod( $absolute, $octal ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'beaver_fm_chmod_failed',
				/* translators: %s: file name. */
				sprintf( __( 'Permissions on “%s” could not be changed. The web server does not own it.', 'beaver-filemanager' ), wp_basename( $absolute ) )
			);
		}

		$changed = 1;

		if ( ! $recursive || ! is_dir( $absolute ) ) {
			return $changed;
		}

		$handle = @opendir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return $changed;
		}

		while ( false !== ( $name = readdir( $handle ) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$result = self::chmod_path( $absolute . '/' . $name, $octal, true );

			if ( ! is_wp_error( $result ) ) {
				$changed += $result;
			}
		}

		closedir( $handle );

		return $changed;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Uploads
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Stores one uploaded file in a folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir_relative Destination folder.
	 * @param array  $file         One entry from `$_FILES`.
	 * @param bool   $overwrite    Replace a file already using that name.
	 * @return array|WP_Error
	 */
	public static function receive_upload( $dir_relative, $file, $overwrite = false ) {
		$dir = self::resolve( $dir_relative, true );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'That folder does not accept uploads. Check its permissions.', 'beaver-filemanager' ) );
		}

		if ( ! isset( $file['tmp_name'], $file['name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'beaver_fm_bad_upload', __( 'That upload did not arrive intact.', 'beaver-filemanager' ) );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'beaver_fm_upload_error', self::upload_error_message( $error ) );
		}

		$max = Beaver_FM_Settings::max_upload_bytes();

		if ( $max > 0 && (int) $file['size'] > $max ) {
			return new WP_Error(
				'beaver_fm_too_big',
				sprintf(
					/* translators: 1: file name, 2: size limit. */
					__( '“%1$s” is larger than the %2$s upload limit.', 'beaver-filemanager' ),
					wp_basename( (string) $file['name'] ),
					size_format( $max )
				)
			);
		}

		$name = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$name = self::clean_name( $name );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, Beaver_FM_Settings::blocked_extensions(), true ) ) {
			return new WP_Error(
				'beaver_fm_blocked_ext',
				sprintf(
					/* translators: %s: file extension. */
					__( 'Uploads ending in .%s are blocked in Settings.', 'beaver-filemanager' ),
					$ext
				)
			);
		}

		$target = $dir . '/' . $name;

		if ( file_exists( $target ) ) {
			if ( $overwrite ) {
				$erased = self::erase( $target );

				if ( is_wp_error( $erased ) ) {
					return $erased;
				}
			} else {
				$name   = self::unique_name( $dir, $name );
				$target = $dir . '/' . $name;
			}
		}

		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'beaver_fm_move_failed', __( 'The upload could not be saved into that folder.', 'beaver-filemanager' ) );
		}

		@chmod( $target, self::file_mode() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		Beaver_FM_Logger::record( 'upload', self::relative( $target ), size_format( (int) $file['size'] ) );

		return self::entry( $target );
	}

	/**
	 * Turns a PHP upload error code into a sentence.
	 *
	 * @since 1.0.0
	 *
	 * @param int $code Constant from `$_FILES[...]['error']`.
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The file is larger than this server accepts in one upload.', 'beaver-filemanager' );

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was cut off before it finished.', 'beaver-filemanager' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file arrived.', 'beaver-filemanager' );

			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'PHP has no temporary folder to stage uploads in. Your host needs to fix that.', 'beaver-filemanager' );

			case UPLOAD_ERR_CANT_WRITE:
				return __( 'PHP could not write the upload to disk.', 'beaver-filemanager' );

			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension blocked the upload.', 'beaver-filemanager' );

			default:
				return __( 'The upload failed.', 'beaver-filemanager' );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Archives
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Whether this server can create zip files.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_zip() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Compresses items into a zip file inside a folder.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $relatives     Items to include.
	 * @param string   $dir_relative  Folder the archive is written to.
	 * @param string   $archive_name  Desired archive name.
	 * @return array|WP_Error
	 */
	public static function zip( $relatives, $dir_relative, $archive_name = '' ) {
		if ( ! self::can_zip() ) {
			return new WP_Error( 'beaver_fm_no_zip', __( 'This server has no ZipArchive support, so archives cannot be created here.', 'beaver-filemanager' ) );
		}

		$dir = self::resolve( $dir_relative, true );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'That folder is read-only, so the archive cannot be written there.', 'beaver-filemanager' ) );
		}

		$sources = array();

		foreach ( (array) $relatives as $relative ) {
			$absolute = self::resolve( $relative, true );

			if ( is_wp_error( $absolute ) ) {
				return $absolute;
			}

			$sources[] = $absolute;
		}

		if ( ! $sources ) {
			return new WP_Error( 'beaver_fm_nothing', __( 'Select something to compress first.', 'beaver-filemanager' ) );
		}

		$archive_name = trim( (string) $archive_name );

		if ( '' === $archive_name ) {
			$archive_name = ( 1 === count( $sources ) ? wp_basename( $sources[0] ) : wp_basename( $dir ) ) . '.zip';
		}

		if ( '.zip' !== strtolower( substr( $archive_name, -4 ) ) ) {
			$archive_name .= '.zip';
		}

		$archive_name = self::clean_name( $archive_name );

		if ( is_wp_error( $archive_name ) ) {
			return $archive_name;
		}

		$archive_name = self::unique_name( $dir, $archive_name );
		$target       = $dir . '/' . $archive_name;

		$result = self::build_zip( $sources, $target );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Beaver_FM_Logger::record( 'zip', self::relative( $target ), sprintf( '%d items', count( $sources ) ) );

		return self::entry( $target );
	}

	/**
	 * Writes a zip archive containing the given absolute paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $sources Absolute paths.
	 * @param string   $target  Absolute path of the archive to write.
	 * @return true|WP_Error
	 */
	public static function build_zip( $sources, $target ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $target, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'beaver_fm_zip_failed', __( 'The archive could not be created.', 'beaver-filemanager' ) );
		}

		foreach ( $sources as $source ) {
			if ( is_file( $source ) ) {
				$zip->addFile( $source, wp_basename( $source ) );
				continue;
			}

			if ( ! is_dir( $source ) ) {
				continue;
			}

			$base = wp_basename( $source );
			$zip->addEmptyDir( $base );

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
					RecursiveIteratorIterator::SELF_FIRST,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);

				foreach ( $iterator as $item ) {
					$path  = self::norm( $item->getPathname() );
					$local = $base . '/' . ltrim( substr( $path, strlen( self::norm( $source ) ) ), '/' );

					if ( $item->isDir() ) {
						$zip->addEmptyDir( $local );
					} elseif ( $item->isFile() ) {
						$zip->addFile( $path, $local );
					}
				}
			} catch ( Exception $e ) {
				$zip->close();

				return new WP_Error( 'beaver_fm_zip_failed', __( 'Part of that folder could not be read while building the archive.', 'beaver-filemanager' ) );
			}
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'beaver_fm_zip_failed', __( 'The archive could not be finished. There may not be enough disk space.', 'beaver-filemanager' ) );
		}

		return true;
	}

	/**
	 * Extracts a zip archive into a folder.
	 *
	 * Entry names are cleaned the same way user input is, so an archive
	 * containing `../../wp-config.php` unpacks harmlessly into the target.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative     Archive relative to the root.
	 * @param string $dir_relative Folder to extract into. Defaults to the archive's folder.
	 * @return array|WP_Error
	 */
	public static function unzip( $relative, $dir_relative = null ) {
		$absolute = self::resolve( $relative, true );

		if ( is_wp_error( $absolute ) ) {
			return $absolute;
		}

		if ( ! is_file( $absolute ) ) {
			return new WP_Error( 'beaver_fm_not_file', __( 'That is not an archive.', 'beaver-filemanager' ) );
		}

		$dir = null === $dir_relative
			? dirname( $absolute )
			: self::resolve( $dir_relative, true );

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error( 'beaver_fm_not_writable', __( 'The destination folder is read-only for the web server.', 'beaver-filemanager' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			WP_Filesystem();

			$result = unzip_file( $absolute, $dir );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			Beaver_FM_Logger::record( 'unzip', self::relative( $absolute ), self::relative( $dir ) );

			return array( 'files' => 0 );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $absolute ) ) {
			return new WP_Error( 'beaver_fm_bad_zip', __( 'That archive could not be opened. It may be corrupt or not a zip file.', 'beaver-filemanager' ) );
		}

		$written = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( false === $name ) {
				continue;
			}

			$is_dir = '/' === substr( $name, -1 );
			$clean  = self::clean_relative( $name );

			if ( '' === $clean ) {
				continue;
			}

			$target = $dir . '/' . $clean;

			if ( ! self::within( $target, $dir ) ) {
				continue;
			}

			if ( $is_dir ) {
				wp_mkdir_p( $target );
				continue;
			}

			if ( ! wp_mkdir_p( dirname( $target ) ) ) {
				continue;
			}

			$stream = $zip->getStream( $name );

			if ( ! $stream ) {
				continue;
			}

			$out = @fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( ! $out ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				continue;
			}

			while ( ! feof( $stream ) ) {
				fwrite( $out, (string) fread( $stream, 262144 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			@chmod( $target, self::file_mode() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			++$written;
		}

		$zip->close();

		Beaver_FM_Logger::record( 'unzip', self::relative( $absolute ), sprintf( '%d files', $written ) );

		return array( 'files' => $written );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Streaming
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Sends a file to the browser and stops the request.
	 *
	 * Nothing here is ever served with a type the browser will execute in the
	 * admin origin: downloads are always an attachment, and previews are locked
	 * down with a sandbox policy and `nosniff`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $absolute Absolute path to a readable file.
	 * @param bool   $inline   Display in place rather than download.
	 */
	public static function stream( $absolute, $inline = false ) {
		$size = (int) filesize( $absolute );
		$name = wp_basename( $absolute );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( $inline ) {
			$allowed = array_merge( self::IMAGE_EXTENSIONS, self::VIDEO_EXTENSIONS, self::AUDIO_EXTENSIONS, array( 'pdf' ) );

			if ( ! in_array( $ext, $allowed, true ) ) {
				$inline = false;
			}
		}

		$type = $inline ? self::mime_of( $absolute, $ext ) : 'application/octet-stream';

		// Read a range so audio and video can be scrubbed instead of buffered whole.
		$start  = 0;
		$end    = $size - 1;
		$ranged = false;
		$range  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( $inline && $range && preg_match( '/bytes=(\d*)-(\d*)/', $range, $m ) ) {
			$ranged = true;
			$start  = '' === $m[1] ? 0 : (int) $m[1];
			$end    = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );

			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
		}

		$length = $end - $start + 1;

		/*
		 * SVG is the one inline type a browser will happily run script from, so
		 * it gets the sandbox directive. The others do not, because `sandbox`
		 * also stops the built-in PDF viewer from rendering.
		 */
		$policy = "default-src 'none'; img-src 'self' data:; media-src 'self'; object-src 'self'; style-src 'unsafe-inline'";

		if ( 'svg' === $ext ) {
			$policy .= '; sandbox';
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();

		status_header( $ranged ? 206 : 200 );
		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: ' . $policy );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( $ranged ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		header(
			sprintf(
				'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
				$inline ? 'inline' : 'attachment',
				str_replace( '"', '', $name ),
				rawurlencode( $name )
			)
		);

		$handle = @fopen( $absolute, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			exit;
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 262144, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();

			$remaining -= strlen( $chunk );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
