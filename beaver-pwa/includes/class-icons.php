<?php
/**
 * App icon generation.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the icon set a browser needs before it will offer to install a site.
 *
 * The source is the WordPress site icon unless a custom image is chosen. Every
 * required size is rendered with the GD extension bundled with PHP, so the set
 * is always complete and correctly sized instead of depending on whichever
 * intermediate sizes happen to exist in the media library.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Icons {

	const OPTION    = 'beaver_pwa_icons';
	const DIRECTORY = 'beaver-pwa';

	/**
	 * Sizes rendered for the `any` purpose.
	 */
	const SIZES = array( 192, 512 );

	/**
	 * Padding applied to the maskable icon, as a share of the canvas.
	 *
	 * Android crops maskable icons to a circle or squircle; the safe zone is
	 * the middle 80 per cent, so the artwork is inset to fit inside it.
	 */
	const MASKABLE_SAFE_ZONE = 0.8;

	/**
	 * Runtime cache of the stored set.
	 *
	 * @var array|null
	 */
	private static $runtime = null;

	/**
	 * Whether this server can render icons.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' );
	}

	/**
	 * Attachment used as the icon source.
	 *
	 * @since 1.0.0
	 *
	 * @return int Attachment ID, or 0 when the site has no icon at all.
	 */
	public static function source_id() {
		$custom = (int) Beaver_PWA_Settings::get( 'icon_id' );

		if ( $custom && 'attachment' === get_post_type( $custom ) ) {
			return $custom;
		}

		return (int) get_option( 'site_icon', 0 );
	}

	/**
	 * Absolute path to the source image.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty when unavailable.
	 */
	public static function source_path() {
		$id = self::source_id();

		if ( ! $id ) {
			return '';
		}

		$path = get_attached_file( $id );

		return ( $path && is_readable( $path ) ) ? $path : '';
	}

	/**
	 * Signature of everything the rendered files depend on.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function signature() {
		$path = self::source_path();

		$parts = array(
			BEAVER_PWA_VERSION,
			(string) self::source_id(),
			$path ? (string) @filemtime( $path ) : '0',
			(string) Beaver_PWA_Settings::get( 'background_color' ),
			(string) (int) Beaver_PWA_Settings::get( 'maskable' ),
		);

		return substr( md5( implode( '|', $parts ) ), 0, 10 );
	}

	/**
	 * Upload directory the icons are written to.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     @type string $path Absolute directory path.
	 *     @type string $url  Directory URL.
	 * }
	 */
	private static function target_dir() {
		$uploads = wp_upload_dir();

		return array(
			'path' => trailingslashit( $uploads['basedir'] ) . self::DIRECTORY,
			'url'  => trailingslashit( $uploads['baseurl'] ) . self::DIRECTORY,
		);
	}

	/**
	 * Returns the manifest icon list, rendering the files when needed.
	 *
	 * @since 1.0.0
	 *
	 * @return array List of manifest icon entries.
	 */
	public static function manifest_icons() {
		$set  = self::maybe_generate();
		$list = array();

		if ( empty( $set['files'] ) ) {
			return self::fallback_icons();
		}

		foreach ( self::SIZES as $size ) {
			if ( empty( $set['files'][ $size ] ) ) {
				continue;
			}

			$list[] = array(
				'src'     => $set['files'][ $size ],
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any',
			);
		}

		if ( ! empty( $set['files']['maskable'] ) ) {
			$list[] = array(
				'src'     => $set['files']['maskable'],
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			);
		}

		return $list ? $list : self::fallback_icons();
	}

	/**
	 * Icon list built from core's site icon sizes.
	 *
	 * Used when GD is missing so the manifest still advertises something.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function fallback_icons() {
		$list = array();

		foreach ( self::SIZES as $size ) {
			$url = get_site_icon_url( $size );

			if ( ! $url ) {
				continue;
			}

			$type = wp_check_filetype( $url );

			$list[] = array(
				'src'     => $url,
				'sizes'   => $size . 'x' . $size,
				'type'    => empty( $type['type'] ) ? 'image/png' : $type['type'],
				'purpose' => 'any',
			);
		}

		return $list;
	}

	/**
	 * URL of the icon used for the Apple touch icon and admin previews.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function apple_icon_url() {
		$set = self::maybe_generate();

		if ( ! empty( $set['files']['apple'] ) ) {
			return $set['files']['apple'];
		}

		return (string) get_site_icon_url( 180 );
	}

	/**
	 * URL of the largest square icon available.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function preview_url() {
		$set = self::maybe_generate();

		if ( ! empty( $set['files'][512] ) ) {
			return $set['files'][512];
		}

		return (string) get_site_icon_url( 512 );
	}

	/**
	 * URLs that should be precached by the service worker.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function precache_urls() {
		$set = self::maybe_generate();

		if ( empty( $set['files'] ) ) {
			return array();
		}

		return array_values( array_filter( (array) $set['files'] ) );
	}

	/**
	 * Renders the icon set when the stored one is stale.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Rebuild even when the signature matches.
	 * @return array Stored icon set.
	 */
	public static function maybe_generate( $force = false ) {
		if ( ! $force && null !== self::$runtime ) {
			return self::$runtime;
		}

		$stored    = get_option( self::OPTION, array() );
		$signature = self::signature();

		if ( ! $force && is_array( $stored ) && isset( $stored['signature'] ) && $stored['signature'] === $signature ) {
			self::$runtime = $stored;

			return $stored;
		}

		$set = self::generate( $signature );

		// Autoloaded: the front end reads this on every page for the manifest
		// icons and the Apple touch icon.
		update_option( self::OPTION, $set, true );

		self::$runtime = $set;

		return $set;
	}

	/**
	 * Forgets the rendered set so the next request rebuilds it.
	 *
	 * @since 1.0.0
	 */
	public static function flush() {
		self::$runtime = null;

		delete_option( self::OPTION );
	}

	/**
	 * Renders every icon size from the source image.
	 *
	 * @since 1.0.0
	 *
	 * @param string $signature Signature to stamp on the files.
	 * @return array
	 */
	private static function generate( $signature ) {
		$set = array(
			'signature' => $signature,
			'files'     => array(),
			'error'     => '',
		);

		$source_path = self::source_path();

		if ( ! $source_path ) {
			$set['error'] = __( 'No site icon has been set, so there is nothing to render.', 'beaver-pwa' );

			return $set;
		}

		if ( ! self::is_supported() ) {
			$set['error'] = __( 'The GD image library is not available on this server.', 'beaver-pwa' );

			return $set;
		}

		$source = self::load( $source_path );

		if ( ! $source ) {
			$set['error'] = __( 'The source image could not be read.', 'beaver-pwa' );

			return $set;
		}

		$dir = self::target_dir();

		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			imagedestroy( $source );

			$set['error'] = __( 'The uploads folder is not writable.', 'beaver-pwa' );

			return $set;
		}

		self::protect_directory( $dir['path'] );

		$targets = array();

		foreach ( self::SIZES as $size ) {
			$targets[ $size ] = array(
				'size'     => $size,
				'file'     => sprintf( 'icon-%d-%s.png', $size, $signature ),
				'maskable' => false,
			);
		}

		$targets['apple'] = array(
			'size'     => 180,
			'file'     => sprintf( 'apple-touch-icon-%s.png', $signature ),
			'maskable' => false,
		);

		if ( Beaver_PWA_Settings::get( 'maskable' ) ) {
			$targets['maskable'] = array(
				'size'     => 512,
				'file'     => sprintf( 'maskable-512-%s.png', $signature ),
				'maskable' => true,
			);
		}

		foreach ( $targets as $key => $target ) {
			$path = trailingslashit( $dir['path'] ) . $target['file'];

			if ( ! file_exists( $path ) && ! self::render( $source, $path, $target['size'], $target['maskable'] ) ) {
				continue;
			}

			$set['files'][ $key ] = trailingslashit( $dir['url'] ) . $target['file'];
		}

		imagedestroy( $source );

		if ( empty( $set['files'] ) ) {
			$set['error'] = __( 'The icons could not be written. Check the permissions on the uploads folder.', 'beaver-pwa' );
		}

		self::clean_directory( $dir['path'], $signature );

		return $set;
	}

	/**
	 * Renders one square PNG.
	 *
	 * @since 1.0.0
	 *
	 * @param resource|GdImage $source   Source image.
	 * @param string           $path     Destination path.
	 * @param int              $size     Canvas edge in pixels.
	 * @param bool             $maskable Whether to inset the artwork and paint a background.
	 * @return bool
	 */
	private static function render( $source, $path, $size, $maskable ) {
		$canvas = imagecreatetruecolor( $size, $size );

		if ( ! $canvas ) {
			return false;
		}

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		if ( $maskable ) {
			$rgb = self::hex_to_rgb( (string) Beaver_PWA_Settings::get( 'background_color' ) );
			$fill = imagecolorallocate( $canvas, $rgb[0], $rgb[1], $rgb[2] );
		} else {
			$fill = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
		}

		imagefilledrectangle( $canvas, 0, 0, $size, $size, $fill );
		imagealphablending( $canvas, true );

		$source_w = imagesx( $source );
		$source_h = imagesy( $source );
		$edge     = $maskable ? (int) round( $size * self::MASKABLE_SAFE_ZONE ) : $size;

		// Contain rather than crop, so a non-square source keeps its proportions.
		$ratio  = min( $edge / max( 1, $source_w ), $edge / max( 1, $source_h ) );
		$draw_w = max( 1, (int) round( $source_w * $ratio ) );
		$draw_h = max( 1, (int) round( $source_h * $ratio ) );
		$draw_x = (int) round( ( $size - $draw_w ) / 2 );
		$draw_y = (int) round( ( $size - $draw_h ) / 2 );

		imagecopyresampled( $canvas, $source, $draw_x, $draw_y, 0, 0, $draw_w, $draw_h, $source_w, $source_h );

		$written = imagepng( $canvas, $path, 9 );

		imagedestroy( $canvas );

		return (bool) $written;
	}

	/**
	 * Loads an image into GD.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @return resource|GdImage|false
	 */
	private static function load( $path ) {
		$type = wp_check_filetype( $path );
		$mime = empty( $type['type'] ) ? '' : $type['type'];

		$loaders = array(
			'image/png'  => 'imagecreatefrompng',
			'image/jpeg' => 'imagecreatefromjpeg',
			'image/gif'  => 'imagecreatefromgif',
			'image/webp' => 'imagecreatefromwebp',
		);

		if ( ! isset( $loaders[ $mime ] ) || ! function_exists( $loaders[ $mime ] ) ) {
			return false;
		}

		$image = @call_user_func( $loaders[ $mime ], $path );

		if ( ! $image ) {
			return false;
		}

		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $image );
		}

		imagealphablending( $image, true );
		imagesavealpha( $image, true );

		return $image;
	}

	/**
	 * Removes icons left behind by an earlier signature.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path      Directory path.
	 * @param string $signature Signature to keep.
	 */
	private static function clean_directory( $path, $signature ) {
		$files = glob( trailingslashit( $path ) . '*.png' );

		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( false === strpos( basename( $file ), $signature ) ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Drops an index file so the folder cannot be listed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Directory path.
	 */
	private static function protect_directory( $path ) {
		$index = trailingslashit( $path ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Converts a hex colour to RGB components.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hex Hex colour.
	 * @return array
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 255, 255, 255 );
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Deletes every rendered file. Used on uninstall.
	 *
	 * @since 1.0.0
	 */
	public static function delete_all() {
		$dir   = self::target_dir();
		$files = glob( trailingslashit( $dir['path'] ) . '*' );

		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
		}

		@rmdir( $dir['path'] );

		delete_option( self::OPTION );
	}
}
