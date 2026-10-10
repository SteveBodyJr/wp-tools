<?php
/**
 * The filters that put these plugins into Plugins → Updates.
 *
 * @package BeaverUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hands WordPress update data for whichever Digital Beaver plugins are here.
 *
 * The work happens on `site_transient_update_plugins`, which means the nine
 * plugins already installed on a site become updatable the moment this one is
 * activated. Nothing has to be re-released, and no header has to be added to
 * anything.
 *
 * The `Update URI` route added in WordPress 5.8 is supported as well, for any
 * plugin that later carries the header, but nothing depends on it.
 *
 * @since 1.0.0
 */
final class Beaver_Updates_Updates {

	/**
	 * Update URI these plugins would declare, if they declare one.
	 */
	const UPDATE_URI = 'https://github.com/SteveBodyJr/wp-tools';

	/**
	 * Installed plugins that the channel knows about, keyed by plugin file.
	 *
	 * @var array|null
	 */
	private static $ours = null;

	/**
	 * Registers the filters.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'via_header' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'load-update-core.php', array( __CLASS__, 'on_force_check' ) );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 2 );
	}

	/**
	 * Downloads one of our packages and checks it before WordPress unpacks it.
	 *
	 * The manifest publishes a SHA-256 for every archive. The file that
	 * arrives has to match it exactly, or nothing is installed. That catches
	 * a release asset replaced after its version was published, a truncated
	 * or corrupted download, and a cache serving a stale archive under a new
	 * version's name. Each of these would otherwise be unpacked over a
	 * working plugin.
	 *
	 * Every install path goes through here: Plugins → Updates, automatic
	 * updates, WP-CLI, and this plugin's own Install button.
	 *
	 * @since 1.3.0
	 *
	 * @param bool|string|WP_Error $reply   Result so far; false means "not handled yet".
	 * @param string               $package Package URL.
	 * @return bool|string|WP_Error Path to the verified file, an error, or $reply untouched.
	 */
	public static function verified_download( $reply, $package ) {
		if ( false !== $reply ) {
			return $reply;
		}

		$entry = Beaver_Updates_Channel::entry_for_package( $package );

		// Not ours, or published before checksums: WordPress downloads it as
		// usual. empty() rather than a comparison, because a manifest cached
		// by 1.2.0 has no sha256 key at all until it is next fetched.
		if ( null === $entry || empty( $entry['sha256'] ) ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = (string) hash_file( 'sha256', $file );

		if ( ! hash_equals( $entry['sha256'], $actual ) ) {
			wp_delete_file( $file );

			return new WP_Error(
				'beaver_updates_checksum',
				sprintf(
					/* translators: 1: plugin name, 2: version. */
					__( 'The download of %1$s %2$s did not match the checksum published for it, so it was not installed. Nothing on this site was changed.', 'beaver-updates' ),
					$entry['name'],
					$entry['version']
				)
			);
		}

		return $file;
	}

	/**
	 * Makes WordPress's own "Check again" (Dashboard -> Updates) read the
	 * manifest afresh, so a newly published plugin or version shows at once
	 * instead of after the cache expires.
	 *
	 * A manifest fetched in the last minute is kept: reloading the screen
	 * should not fetch it again each time.
	 *
	 * @since 1.2.0
	 */
	public static function on_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress's own link carries no nonce; this only drops a cache.
		if ( empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$cached = get_site_transient( Beaver_Updates_Channel::TRANSIENT );

		if ( is_array( $cached ) && ! empty( $cached['fetched'] ) && time() - (int) $cached['fetched'] < MINUTE_IN_SECONDS ) {
			return;
		}

		Beaver_Updates_Channel::forget();
	}

	/**
	 * Installed plugins this channel publishes.
	 *
	 * @since 1.0.0
	 *
	 * @return array Plugin file => slug.
	 */
	public static function ours() {
		if ( null !== self::$ours ) {
			return self::$ours;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$published = Beaver_Updates_Channel::plugins();
		$ours      = array();

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			$slug = dirname( $plugin_file );

			if ( '.' !== $slug && isset( $published[ $slug ] ) ) {
				$ours[ $plugin_file ] = $slug;
			}
		}

		self::$ours = $ours;

		return $ours;
	}

	/**
	 * Adds our plugins to the update transient as it is read.
	 *
	 * Filtering on read rather than on write means the data is present however
	 * the transient was last built, including on a site whose scheduled check
	 * has not run since this plugin was activated.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		/*
		 * This filter runs every time anything reads the update transient,
		 * which is several times on every admin page. get_plugins() is cached
		 * for the request and cleared by WordPress after an install or
		 * update, so it stays accurate. Reading each plugin file again with
		 * get_plugin_data(), as 1.2.0 did, cost a file read per plugin per call.
		 */
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();

		foreach ( self::ours() as $plugin_file => $slug ) {
			$entry = Beaver_Updates_Channel::plugin( $slug );

			if ( ! $entry ) {
				continue;
			}

			$current = isset( $installed[ $plugin_file ]['Version'] ) ? (string) $installed[ $plugin_file ]['Version'] : '0';

			$update = self::build( $plugin_file, $entry );

			if ( version_compare( $entry['version'], $current, '>' ) ) {
				// Written unconditionally. If a plugin on wordpress.org ever
				// shares one of these slugs, its data must not win here.
				$transient->response[ $plugin_file ] = $update;

				unset( $transient->no_update[ $plugin_file ] );

				continue;
			}

			// Everything up to date still belongs in no_update, or the site
			// shows no version information and the auto-update toggle for this
			// plugin has nothing to act on.
			$transient->no_update[ $plugin_file ] = $update;

			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * Answers the 5.8 header route for plugins that carry an Update URI.
	 *
	 * This hook is shared by every plugin whose Update URI points at GitHub, so
	 * anything that is not ours is passed straight through untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @return array|false
	 */
	public static function via_header( $update, $plugin_data, $plugin_file ) {
		$uri = isset( $plugin_data['UpdateURI'] ) ? (string) $plugin_data['UpdateURI'] : '';

		if ( 0 !== strpos( $uri, self::UPDATE_URI ) ) {
			return $update;
		}

		$slug  = dirname( $plugin_file );
		$entry = Beaver_Updates_Channel::plugin( $slug );

		if ( ! $entry ) {
			return $update;
		}

		return (array) self::build( $plugin_file, $entry );
	}

	/**
	 * Builds one update object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_file Plugin file.
	 * @param array  $entry       Manifest entry.
	 * @return object
	 */
	private static function build( $plugin_file, array $entry ) {
		return (object) array(
			'id'           => self::UPDATE_URI . '/' . $entry['slug'],
			'slug'         => $entry['slug'],
			'plugin'       => $plugin_file,
			'new_version'  => $entry['version'],
			'version'      => $entry['version'],
			'url'          => $entry['homepage'],
			// The archives are built as slug/… so WordPress unpacks them into
			// the right directory with no rename step.
			'package'      => $entry['package'],
			'tested'       => $entry['tested'],
			'requires'     => $entry['requires'],
			'requires_php' => $entry['requires_php'],
			// Shown on Dashboard -> Updates and in the plugin rows. Checked again
			// here, like the package, however the entry got into the cache.
			'icons'        => self::safe_images( $entry['icons'] ?? array() ),
			'banners'      => self::safe_images( $entry['banners'] ?? array() ),
			'banners_rtl'  => array(),
		);
	}

	/**
	 * Icon or banner URLs that are still allowed, whatever the cache holds.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $images Size => URL.
	 * @return array
	 */
	private static function safe_images( $images ) {
		return is_array( $images )
			? array_filter( $images, array( 'Beaver_Updates_Channel', 'is_allowed_image' ) )
			: array();
	}

	/**
	 * Supplies the details modal, which would otherwise ask wordpress.org.
	 *
	 * @since 1.0.0
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$entry = Beaver_Updates_Channel::plugin( $args->slug );

		if ( ! $entry ) {
			return $result;
		}

		return (object) array(
			'name'          => $entry['name'],
			'slug'          => $entry['slug'],
			'version'       => $entry['version'],
			'author'        => $entry['author'],
			'homepage'      => $entry['homepage'],
			'requires'      => $entry['requires'],
			'requires_php'  => $entry['requires_php'],
			'tested'        => $entry['tested'],
			'download_link' => $entry['package'],
			'trunk'         => $entry['package'],
			'icons'         => self::safe_images( $entry['icons'] ?? array() ),
			'banners'       => self::safe_images( $entry['banners'] ?? array() ),
			'sections'      => array(
				'description' => sprintf(
					/* translators: 1: plugin name, 2: source URL. */
					__( '%1$s is part of the Digital Beaver WP Tools set. The full source, the readme and the release notes for every version are at %2$s.', 'beaver-updates' ),
					esc_html( $entry['name'] ),
					'<a href="' . esc_url( $entry['homepage'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $entry['homepage'] ) . '</a>'
				),
			),
		);
	}

	/**
	 * Marks our plugins on the Plugins screen so their source is obvious.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $meta        Row meta links.
	 * @param string $plugin_file Plugin file.
	 * @return array
	 */
	public static function row_meta( $meta, $plugin_file ) {
		$ours = self::ours();

		if ( ! isset( $ours[ $plugin_file ] ) ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<span class="beaver-updates-badge">%s</span>',
			esc_html__( 'Updates from Digital Beaver', 'beaver-updates' )
		);

		return $meta;
	}
}
