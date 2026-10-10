<?php
/**
 * Server rules.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and removes the rules that PHP cannot enforce on its own.
 *
 * Some of what this plugin blocks is never seen by WordPress at all. A request
 * for `readme.txt` inside a plugin folder is a static file: Apache serves it and
 * PHP never runs, so no amount of hooking can stop it. Those cases need a rule
 * at the server, and on Apache that means .htaccess.
 *
 * Every rule lives inside a single marked block written with the same core
 * function WordPress uses for its own rewrite rules. The file is copied once
 * before the first write, the block is removed on deactivation, and a hand
 * edited .htaccess is never reformatted, because everything outside the markers
 * is left exactly as it was found. On nginx nothing is written at all and the
 * admin screen shows the equivalent config to paste.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Htaccess {

	const MARKER = 'Beaver Shield';

	/**
	 * Loads the core helpers this class depends on.
	 *
	 * They live in wp-admin and are already present on an admin request, but
	 * activation, WP-CLI and cron all reach this code without them.
	 *
	 * @since 1.0.0
	 */
	private static function load_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	/**
	 * Whether this server reads .htaccess.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on Apache or LiteSpeed.
	 */
	public static function is_apache() {
		$software = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );

		return false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' );
	}

	/**
	 * Why this site is not having server rules written, if it is not.
	 *
	 * Three reasons, and all three have to be said out loud rather than left as
	 * a switch that appears to be on and quietly does nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason, or an empty string when writing is allowed.
	 */
	public static function blocked_reason() {
		if ( ! self::is_apache() ) {
			return __( 'This server does not read .htaccess, so nothing is written. The equivalent config is below, to paste in by hand.', 'beaver-shield' );
		}

		// Managed hosts and hardened installs set this to declare the file
		// system off limits. Writing anyway would be ignoring the one signal the
		// host gave us.
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'DISALLOW_FILE_MODS is set in wp-config.php, which tells plugins not to write to the file system. Nothing has been written, and the switches that depend on server rules are doing nothing until the rules below are added by hand.', 'beaver-shield' );
		}

		// Every site on a multisite network shares one .htaccess at the root.
		// If each subsite wrote its own rules there, the last one to save would
		// win and the rest would silently lose theirs.
		if ( is_multisite() && ! is_main_site() ) {
			return __( 'This is a site on a multisite network, and every site on the network shares one .htaccess file. Only the main site writes it, so that one site cannot overwrite the rules of another. Everything else on these screens applies to this site normally.', 'beaver-shield' );
		}

		return '';
	}

	/**
	 * Whether the server rules may be written at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when writing is allowed.
	 */
	public static function may_write() {
		return '' === self::blocked_reason();
	}

	/**
	 * The path to the site's root .htaccess.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path.
	 */
	public static function root_file() {
		self::load_helpers();

		return get_home_path() . '.htaccess';
	}

	/**
	 * The path to the uploads .htaccess.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path, or an empty string when uploads are unavailable.
	 */
	public static function uploads_file() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . '.htaccess';
	}

	/**
	 * Whether a file can be written, creating it if need be.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute path.
	 * @return bool True when writable.
	 */
	public static function writable( $file ) {
		if ( '' === $file ) {
			return false;
		}

		return file_exists( $file ) ? is_writable( $file ) : is_writable( dirname( $file ) );
	}

	/**
	 * Brings both files in line with the current settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,bool> Which files were written.
	 */
	public static function sync() {
		$written = array(
			'root'    => false,
			'uploads' => false,
		);

		if ( ! self::may_write() ) {
			return $written;
		}

		self::load_helpers();

		$root = self::root_file();

		if ( self::writable( $root ) ) {
			self::back_up( $root );

			$rules = self::root_rules();

			// An empty rule set means the block should not be there at all.
			// Writing empty markers instead would leave a site carrying a block
			// named after a plugin that is doing nothing.
			if ( empty( $rules ) ) {
				self::strip_block( $root );

				$written['root'] = true;
			} else {
				$written['root'] = (bool) insert_with_markers( $root, self::MARKER, $rules );
			}
		}

		$uploads = self::uploads_file();

		if ( '' !== $uploads ) {
			$rules = self::uploads_rules();

			if ( empty( $rules ) ) {
				self::strip_block( $uploads, true );

				$written['uploads'] = true;
			} elseif ( self::writable( $uploads ) ) {
				$written['uploads'] = (bool) insert_with_markers( $uploads, self::MARKER, $rules );
			}
		}

		return $written;
	}

	/**
	 * Removes every block this plugin wrote.
	 *
	 * @since 1.0.0
	 */
	public static function remove_all() {
		// Deliberately not gated on DISALLOW_FILE_MODS. That constant exists to
		// stop plugins adding things, and taking our own block back out is the
		// one write that should always be attempted: a site must never be left
		// carrying rules from a plugin it has removed.
		if ( ! self::is_apache() || ( is_multisite() && ! is_main_site() ) ) {
			return;
		}

		self::strip_block( self::root_file() );
		self::strip_block( self::uploads_file(), true );
	}

	/**
	 * Takes this plugin's block out of a file and leaves the rest alone.
	 *
	 * Deliberately not `insert_with_markers()` with an empty array. That leaves
	 * the BEGIN and END comments behind, so a site that no longer has the plugin
	 * installed still carries a block naming it, which is the hardest kind of
	 * stray config to trace later. The blank line the markers were sitting on
	 * goes too, otherwise activating and deactivating repeatedly walks the rest
	 * of the file steadily down the page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file          Absolute path.
	 * @param bool   $drop_if_empty Whether to delete the file if nothing is left in it.
	 */
	private static function strip_block( $file, $drop_if_empty = false ) {
		if ( '' === $file || ! file_exists( $file ) || ! is_writable( $file ) ) {
			return;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local config file, read before rewriting it.

		if ( false === $contents ) {
			return;
		}

		$start = '# BEGIN ' . self::MARKER;
		$end   = '# END ' . self::MARKER;
		$kept  = array();
		$in    = false;

		foreach ( explode( "\n", str_replace( "\r\n", "\n", $contents ) ) as $line ) {
			if ( ! $in && 0 === strpos( trim( $line ), $start ) ) {
				$in = true;

				// Drop the blank line the block was sitting on.
				if ( ! empty( $kept ) && '' === trim( end( $kept ) ) ) {
					array_pop( $kept );
				}

				continue;
			}

			if ( $in ) {
				if ( 0 === strpos( trim( $line ), $end ) ) {
					$in = false;
				}

				continue;
			}

			$kept[] = $line;
		}

		$result = rtrim( implode( "\n", $kept ), "\n" );

		if ( $drop_if_empty && '' === trim( $result ) ) {
			wp_delete_file( $file );

			return;
		}

		file_put_contents( $file, '' === $result ? '' : $result . "\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not available on deactivation without prompting for credentials.
	}

	/**
	 * Copies the original .htaccess once, before the first write.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute path.
	 */
	private static function back_up( $file ) {
		$backup = $file . '-beaver-shield-backup';

		if ( ! file_exists( $file ) || file_exists( $backup ) ) {
			return;
		}

		@copy( $file, $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed backup must not stop the request; the caller reports writability separately.
	}

	/**
	 * The rules for the site root.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Lines.
	 */
	public static function root_rules() {
		$lines = array();

		if ( Beaver_Shield_Settings::on( 'no_directory_listing' ) ) {
			$lines[] = '# A folder with no index file should not list itself.';
			$lines[] = 'Options -Indexes';
			$lines[] = '';
		}

		if ( Beaver_Shield_Settings::on( 'block_meta_files' ) ) {
			$lines[] = '# Every plugin ships a readme.txt carrying its exact version.';
			$lines[] = '# Reading those is how a scanner turns a site into a list of';
			$lines[] = '# known vulnerabilities without sending a single attack.';
			$lines   = array_merge( $lines, self::deny_block( '^(readme|changelog|license|licence|contributing)\.(txt|html|md)$' ) );
			$lines[] = '';
			$lines   = array_merge( $lines, self::deny_block( '^(wp-config-sample\.php|error_log|debug\.log|\.user\.ini)$' ) );
			$lines[] = '';
		}

		if ( Beaver_Shield_Settings::on( 'disable_xmlrpc' ) ) {
			$lines[] = '# XML-RPC is switched off in PHP as well; this stops the';
			$lines[] = '# request reaching WordPress at all.';
			$lines   = array_merge( $lines, self::deny_block( '^xmlrpc\.php$' ) );
			$lines[] = '';
		}

		if ( Beaver_Shield_Settings::on( 'hotlink_protection' ) ) {
			$lines = array_merge( $lines, self::hotlink_rules() );
			$lines[] = '';
		}

		return self::trim_trailing_blank( $lines );
	}

	/**
	 * The rules for the uploads folder.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Lines.
	 */
	public static function uploads_rules() {
		$lines = array();

		if ( Beaver_Shield_Settings::on( 'block_php_in_uploads' ) ) {
			$lines[] = '# Nothing in the media library should ever execute. If a PHP file';
			$lines[] = '# reaches this folder, something has already gone wrong, and this is';
			$lines[] = '# what stops it becoming a shell.';
			$lines   = array_merge( $lines, self::deny_block( '\.(php|phtml|php\d|pht|phar)$' ) );
			$lines[] = '';
		}

		// The images are here, so the hotlink rule has to be here as well as at
		// the root. See hotlink_rules() for why it cannot simply live upstairs.
		if ( Beaver_Shield_Settings::on( 'hotlink_protection' ) ) {
			$lines = array_merge( $lines, self::hotlink_rules() );
		}

		return self::trim_trailing_blank( $lines );
	}

	/**
	 * The hotlink rule, written so that it actually applies.
	 *
	 * The obvious implementation is a mod_rewrite condition on the referrer at
	 * the site root, and on a normal WordPress it silently does nothing. Any
	 * directory whose own .htaccess switches `RewriteEngine On` stops inheriting
	 * the parent's rewrite rules altogether, and wp-content/uploads is very
	 * often such a directory: WebP delivery, image fallbacks and CDN plugins all
	 * put rewrite rules there. So a root level rewrite rule never sees a request
	 * for the images it was written to protect.
	 *
	 * `SetEnvIf` plus an authorisation check is not subject to that inheritance
	 * rule, so it works from wherever it is placed. The rule is written into
	 * both files regardless.
	 *
	 * An empty referrer is allowed deliberately, and that is what keeps this
	 * safe for search. Googlebot and Googlebot-Image send no referrer, and
	 * neither does a link preview, a feed reader or a browser set to withhold
	 * it. Google's renderer does send one, and it is this site's own address,
	 * which the second rule allows.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Lines.
	 */
	private static function hotlink_rules() {
		$host = preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '/' );

		return array(
			'# Images load on this site and on a direct visit. A request with no',
			'# referrer is allowed on purpose: that is what Googlebot, a link',
			'# preview and a privacy conscious browser all send.',
			'<IfModule mod_setenvif.c>',
			'SetEnvIf Referer "^$" beaver_shield_image_ok',
			'SetEnvIf Referer "^https?://(www\.)?' . $host . '" beaver_shield_image_ok',
			'<FilesMatch "(?i)\.(jpe?g|png|gif|webp|avif|svg)$">',
			'  <IfModule mod_authz_core.c>',
			'    Require env beaver_shield_image_ok',
			'  </IfModule>',
			'  <IfModule !mod_authz_core.c>',
			'    Order deny,allow',
			'    Deny from all',
			'    Allow from env=beaver_shield_image_ok',
			'  </IfModule>',
			'</FilesMatch>',
			'</IfModule>',
		);
	}

	/**
	 * A deny rule written for both Apache 2.2 and 2.4.
	 *
	 * Shared hosts run both, and a 2.4 directive on a 2.2 server is a 500 on
	 * every page of the site rather than a rule that quietly does nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $pattern FilesMatch regular expression.
	 * @return string[] Lines.
	 */
	private static function deny_block( $pattern ) {
		return array(
			'<FilesMatch "' . $pattern . '">',
			'  <IfModule mod_authz_core.c>',
			'    Require all denied',
			'  </IfModule>',
			'  <IfModule !mod_authz_core.c>',
			'    Order allow,deny',
			'    Deny from all',
			'  </IfModule>',
			'</FilesMatch>',
		);
	}

	/**
	 * Drops trailing blank lines.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $lines Lines.
	 * @return string[] Lines.
	 */
	private static function trim_trailing_blank( array $lines ) {
		while ( ! empty( $lines ) && '' === end( $lines ) ) {
			array_pop( $lines );
		}

		return array_values( $lines );
	}

	/**
	 * The same rules written for nginx.
	 *
	 * Shown rather than written. A plugin has no business editing an nginx
	 * config it cannot reload, and getting it wrong takes the whole server down
	 * rather than one site.
	 *
	 * @since 1.0.0
	 *
	 * @return string Config block.
	 */
	public static function nginx_rules() {
		$lines = array( '# Beaver Shield — paste inside the server block, then reload nginx.' );

		if ( Beaver_Shield_Settings::on( 'block_meta_files' ) ) {
			$lines[] = 'location ~* ^.+/(readme|changelog|license|licence|contributing)\.(txt|html|md)$ { deny all; }';
			$lines[] = 'location ~* ^/(wp-config-sample\.php|error_log|debug\.log)$ { deny all; }';
		}

		if ( Beaver_Shield_Settings::on( 'disable_xmlrpc' ) ) {
			$lines[] = 'location = /xmlrpc.php { deny all; }';
		}

		if ( Beaver_Shield_Settings::on( 'block_php_in_uploads' ) ) {
			$lines[] = 'location ~* /wp-content/uploads/.*\.(php|phtml|phar)$ { deny all; }';
		}

		if ( Beaver_Shield_Settings::on( 'no_directory_listing' ) ) {
			$lines[] = 'autoindex off;';
		}

		if ( Beaver_Shield_Settings::on( 'hotlink_protection' ) ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

			$lines[] = '';
			$lines[] = '# "none" allows a request with no referrer, which is what Googlebot sends.';
			$lines[] = 'location ~* \.(jpe?g|png|gif|webp|avif|svg)$ {';
			$lines[] = '    valid_referers none blocked ' . $host . ' www.' . $host . ';';
			$lines[] = '    if ($invalid_referer) { return 403; }';
			$lines[] = '}';
		}

		return implode( "\n", $lines );
	}
}
