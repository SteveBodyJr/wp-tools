<?php
/**
 * The list of what an uploads folder holds.
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads an uploads folder into a comparable list, and decides what may be
 * written into one.
 *
 * Both ends build their list with this same class, which is the point: a
 * difference between the two can only ever be a real difference in the files,
 * never two functions disagreeing about what a file is.
 *
 * @since 1.0.0
 */
class Beaver_Sync_Manifest {

	/**
	 * What counts as media.
	 *
	 * An allowlist rather than a block list, and the reason is the copy end:
	 * it writes whatever the source offers into wp-content/uploads. A source
	 * that had been tampered with could otherwise offer a .php file and have
	 * the copy save it somewhere the web server will happily execute. Listing
	 * what is allowed means a new dangerous extension cannot be missed.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Lower case extensions.
	 */
	public static function allowed_extensions() {
		/**
		 * Filters the extensions Beaver Sync will carry.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $extensions Lower case extensions, no dots.
		 */
		return (array) apply_filters(
			'beaver_sync_extensions',
			array(
				'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tiff', 'tif', 'heic',
				'mp4', 'm4v', 'mov', 'webm', 'ogv', 'avi', 'mpg', 'mpeg', '3gp',
				'mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac',
				'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'csv', 'txt', 'rtf',
				'zip',
			)
		);
	}

	/**
	 * Whether a relative path is one we are willing to carry.
	 *
	 * Rejects anything that tries to climb out of the uploads folder, anything
	 * absolute, anything with a null byte, and anything that is not media. The
	 * check is on the path as a string, before it is ever joined to a directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Relative path, forward slashes.
	 * @return bool
	 */
	public static function path_is_safe( $path ) {
		$path = (string) $path;

		if ( '' === $path || strlen( $path ) > 1024 ) {
			return false;
		}

		if ( false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) ) {
			return false;
		}

		// No absolute paths, no Windows drive letters, no climbing out.
		if ( '/' === $path[0] || preg_match( '#^[A-Za-z]:#', $path ) ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::allowed_extensions(), true );
	}

	/**
	 * The uploads directory of this site, without a trailing slash.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function base_dir() {
		$up = wp_get_upload_dir();

		return untrailingslashit( $up['basedir'] );
	}

	/**
	 * The public URL of that directory, without a trailing slash.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function base_url() {
		$up = wp_get_upload_dir();

		return untrailingslashit( $up['baseurl'] );
	}

	/**
	 * Every media file under uploads, as path => size and modified time.
	 *
	 * Size and modified time rather than a hash of every file, which is what
	 * rsync compares by default and for the same reason: hashing hundreds of
	 * megabytes on shared hosting to answer one HTTP request is a good way to
	 * be killed by the execution limit. Media is written once and never edited
	 * in place, so size is a strong signal, and a hash is available on the few
	 * files where it is actually wanted.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array{s:int,m:int}> Relative path => size and mtime.
	 */
	public static function build() {
		$base = self::base_dir();
		$out  = array();

		if ( ! is_dir( $base ) ) {
			return $out;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$cut = strlen( $base ) + 1;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$rel = str_replace( '\\', '/', substr( $file->getPathname(), $cut ) );

			if ( ! self::path_is_safe( $rel ) ) {
				continue;
			}

			$out[ $rel ] = array(
				's' => (int) $file->getSize(),
				'm' => (int) $file->getMTime(),
			);
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Compare two lists.
	 *
	 * Size decides. A modified time that differs on its own is not a change:
	 * the same file copied between two machines routinely arrives with a new
	 * timestamp, and treating that as a difference would mean re-downloading
	 * the entire library on every run for ever.
	 *
	 * @since 1.0.0
	 *
	 * @param array $there The source's list.
	 * @param array $here  This site's list.
	 * @return array{missing:array,changed:array,extra:array,bytes:int}
	 */
	public static function compare( array $there, array $here ) {
		$missing = array();
		$changed = array();
		$bytes   = 0;

		foreach ( $there as $path => $meta ) {
			$size = isset( $meta['s'] ) ? (int) $meta['s'] : 0;

			if ( ! isset( $here[ $path ] ) ) {
				$missing[ $path ] = $size;
				$bytes           += $size;
				continue;
			}

			if ( (int) $here[ $path ]['s'] !== $size ) {
				$changed[ $path ] = $size;
				$bytes           += $size;
			}
		}

		$extra = array_diff_key( $here, $there );

		return array(
			'missing' => $missing,
			'changed' => $changed,
			// Reported so you can see them, never acted on. Deleting a local
			// file because the server has not got it is how a library of work
			// in progress disappears.
			'extra'   => array_keys( $extra ),
			'bytes'   => $bytes,
		);
	}
}
