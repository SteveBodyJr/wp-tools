<?php
/**
 * Single-file WebP conversion.
 *
 * @package BeaverImageOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts one image file to WebP using the WordPress image editor abstraction.
 *
 * This class knows nothing about attachments, metadata or settings storage. It
 * takes an absolute path in and reports what happened, which keeps the risky
 * parts (memory limits, GD quirks) in one testable place.
 *
 * @since 1.0.0
 */
class Beaver_Image_Converter {

	/**
	 * Source MIME types this plugin is able to convert.
	 */
	const SUPPORTED_MIME_TYPES = array( 'image/jpeg', 'image/png' );

	/**
	 * Extension appended to the source filename to build the sidecar name.
	 */
	const SIDECAR_EXTENSION = '.webp';

	/**
	 * Cached result of the WebP support probe.
	 *
	 * @var bool|null
	 */
	private static $supported = null;

	/**
	 * Returns the selectable quality presets.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,int> Preset key => quality value between 1 and 100.
	 */
	public static function quality_presets() {
		/**
		 * Filters the available WebP quality presets.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,int> $presets Preset key => quality value.
		 */
		return apply_filters(
			'beaver_io_quality_presets',
			array(
				'high'     => 85,
				'balanced' => 75,
				'max'      => 60,
			)
		);
	}

	/**
	 * Resolves a preset key to a numeric quality value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $preset Preset key.
	 * @return int Quality between 1 and 100.
	 */
	public static function preset_quality( $preset ) {
		$presets = self::quality_presets();

		return isset( $presets[ $preset ] ) ? (int) $presets[ $preset ] : 75;
	}

	/**
	 * Determines whether this server can write WebP images.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when an image editor implementation supports WebP output.
	 */
	public static function is_supported() {
		if ( null === self::$supported ) {
			self::$supported = (bool) wp_image_editor_supports(
				array(
					'mime_type' => 'image/webp',
					'methods'   => array( 'resize', 'save' ),
				)
			);
		}

		return self::$supported;
	}

	/**
	 * Reports which imaging library WordPress will use.
	 *
	 * @since 1.0.0
	 *
	 * @return string One of 'imagick', 'gd' or an empty string when neither is usable.
	 */
	public static function engine() {
		if ( ! self::is_supported() ) {
			return '';
		}

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			$formats = array_map( 'strtoupper', (array) Imagick::queryFormats( 'WEBP' ) );

			if ( in_array( 'WEBP', $formats, true ) ) {
				return 'imagick';
			}
		}

		return function_exists( 'imagewebp' ) ? 'gd' : '';
	}

	/**
	 * Builds the sidecar path for a source file.
	 *
	 * The original extension is kept and `.webp` is appended, so `photo.jpg`
	 * becomes `photo.jpg.webp`. That avoids collisions between `photo.jpg` and
	 * `photo.png` in the same folder and keeps the Apache rewrite rule to a
	 * single line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute path to the source image.
	 * @return string Absolute path to the WebP sidecar.
	 */
	public static function sidecar_path( $file ) {
		return $file . self::SIDECAR_EXTENSION;
	}

	/**
	 * Checks whether a filename is one of our generated sidecars.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file File name or path.
	 * @return bool True when the name ends in a converted extension pair.
	 */
	public static function is_sidecar( $file ) {
		return (bool) preg_match( '/\.(jpe?g|png)\.webp$/i', (string) $file );
	}

	/**
	 * Bounds ImageMagick's memory use so it degrades instead of crashing.
	 *
	 * Left alone, ImageMagick keeps its whole pixel cache in RAM and a large
	 * image can exhaust the container long before PHP notices — the request dies
	 * with no catchable error. Given a memory ceiling it writes the overflow to
	 * a temporary disk cache instead, which is slower but finishes. The map
	 * limit is the memory-mapped allowance above that ceiling.
	 *
	 * @since 1.3.3
	 *
	 * @return bool True when limits are in force, false when they cannot be set.
	 */
	private static function configure_imagick() {
		if ( ! class_exists( 'Imagick' ) || ! method_exists( 'Imagick', 'setResourceLimit' ) ) {
			return false;
		}

		if ( ! defined( 'Imagick::RESOURCETYPE_MEMORY' ) || ! defined( 'Imagick::RESOURCETYPE_MAP' ) ) {
			return false;
		}

		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit < 1 ) {
			return false;
		}

		/**
		 * Filters the share of the PHP memory limit ImageMagick may hold in RAM.
		 *
		 * @since 1.3.3
		 *
		 * @param float $share Fraction of the PHP memory limit. Default 0.5.
		 * @param int   $limit The PHP memory limit in bytes.
		 */
		$share = (float) apply_filters( 'beaver_io_imagick_memory_share', 0.5, $limit );
		$bytes = (int) max( 8 * MB_IN_BYTES, $limit * min( 0.9, max( 0.1, $share ) ) );

		try {
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, $bytes );
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, $bytes * 2 );
		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Estimates whether there is enough memory to decode an image with GD.
	 *
	 * GD holds an uncompressed truecolour bitmap in memory, roughly four bytes
	 * per pixel, and needs a second buffer while resizing. Guessing high here is
	 * far cheaper than a fatal error mid-upload.
	 *
	 * @since 1.0.0
	 *
	 * @param int $width  Image width in pixels.
	 * @param int $height Image height in pixels.
	 * @return bool True when the image is expected to fit in the memory limit.
	 */
	public static function has_memory_for( $width, $height ) {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit < 1 ) {
			return true;
		}

		/*
		 * Imagick used to be exempt from this check on the grounds that it
		 * allocates outside PHP's memory_limit, so memory_get_usage() cannot see
		 * it. That left the engine most shared hosts actually use with no guard
		 * at all: a large image went straight to conversion and took the whole
		 * request down with it.
		 *
		 * Capping it by pixel count would be the wrong repair, because it would
		 * refuse images ImageMagick can handle perfectly well. Given explicit
		 * resource limits it spills past them to a disk cache instead of
		 * allocating without bound, so a big image converts slowly rather than
		 * killing the request. Only when those limits cannot be applied does an
		 * estimate make sense — and then the pixel cache is four channels at the
		 * build's quantum depth, eight bytes per pixel on a normal Q16 build.
		 */
		if ( 'imagick' === self::engine() ) {
			if ( self::configure_imagick() ) {
				return true;
			}

			$quantum   = defined( 'Imagick::QUANTUM_DEPTH' ) ? (int) constant( 'Imagick::QUANTUM_DEPTH' ) : 16;
			$per_pixel = 4 * max( 1, (int) ( $quantum / 8 ) );
			$factor    = 1.6;
		} else {
			$per_pixel = 4;
			$factor    = 2.2;
		}

		$required  = (int) ( $width * $height * $per_pixel * $factor );
		$available = $limit - memory_get_usage( true );

		/**
		 * Filters the memory pre-flight verdict for an image.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $fits      Whether the image is expected to fit.
		 * @param int  $required  Estimated bytes required.
		 * @param int  $available Bytes still available.
		 */
		return (bool) apply_filters( 'beaver_io_has_memory_for', $required < $available, $required, $available );
	}

	/**
	 * Converts a single image file to a WebP sidecar.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Absolute path to the source image.
	 * @param array  $args {
	 *     Optional. Conversion arguments.
	 *
	 *     @type int  $quality    WebP quality, 1-100. Default 75.
	 *     @type int  $max_width  Constrain output width, 0 to disable. Default 0.
	 *     @type int  $max_height Constrain output height, 0 to disable. Default 0.
	 *     @type int  $min_gain   Minimum percentage saved to keep the result. Default 3.
	 *     @type bool $force      Reconvert even when a fresh sidecar exists. Default false.
	 * }
	 * @return array {
	 *     Conversion report.
	 *
	 *     @type bool   $success       Whether a usable sidecar now exists.
	 *     @type string $code          Machine readable outcome code.
	 *     @type string $message       Translated, human readable outcome.
	 *     @type string $path          Absolute path to the sidecar, empty on failure.
	 *     @type int    $source_bytes  Size of the source file.
	 *     @type int    $webp_bytes    Size of the sidecar, 0 when none was kept.
	 * }
	 */
	public static function convert( $source, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'quality'    => 75,
				'max_width'  => 0,
				'max_height' => 0,
				'min_gain'   => 3,
				'force'      => false,
			)
		);

		$report = array(
			'success'      => false,
			'code'         => 'unknown',
			'message'      => '',
			'path'         => '',
			'source_bytes' => 0,
			'webp_bytes'   => 0,
		);

		if ( ! self::is_supported() ) {
			$report['code']    = 'no_webp_support';
			$report['message'] = __( 'This server cannot write WebP images.', 'beaver-image-optimizer' );

			return $report;
		}

		if ( ! is_string( $source ) || '' === $source || ! is_file( $source ) || ! is_readable( $source ) ) {
			$report['code']    = 'missing_source';
			$report['message'] = __( 'Source file is missing or unreadable.', 'beaver-image-optimizer' );

			return $report;
		}

		$mime = wp_get_image_mime( $source );

		if ( ! in_array( $mime, self::SUPPORTED_MIME_TYPES, true ) ) {
			$report['code']    = 'unsupported_type';
			$report['message'] = __( 'File type is not a JPEG or PNG image.', 'beaver-image-optimizer' );

			return $report;
		}

		$report['source_bytes'] = (int) filesize( $source );
		$target                 = self::sidecar_path( $source );
		$report['path']         = $target;

		// Duplicate prevention: a sidecar at least as new as its source is current.
		if ( ! $args['force'] && is_file( $target ) && filemtime( $target ) >= filemtime( $source ) ) {
			$report['success']    = true;
			$report['code']       = 'already_converted';
			$report['message']    = __( 'A current WebP version already exists.', 'beaver-image-optimizer' );
			$report['webp_bytes'] = (int) filesize( $target );

			return $report;
		}

		$size = wp_getimagesize( $source );

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			$report['code']    = 'unreadable_dimensions';
			$report['message'] = __( 'Image dimensions could not be read.', 'beaver-image-optimizer' );

			return $report;
		}

		list( $width, $height ) = array( (int) $size[0], (int) $size[1] );

		wp_raise_memory_limit( 'image' );

		if ( ! self::has_memory_for( $width, $height ) ) {
			$report['code']    = 'insufficient_memory';
			$report['message'] = sprintf(
				/* translators: 1: image width in pixels, 2: image height in pixels. */
				__( 'Skipped: %1$d x %2$d pixels exceeds the available PHP memory.', 'beaver-image-optimizer' ),
				$width,
				$height
			);

			return $report;
		}

		$editor = wp_get_image_editor( $source, array( 'mime_type' => 'image/webp' ) );

		if ( is_wp_error( $editor ) ) {
			$report['code']    = 'editor_failed';
			$report['message'] = $editor->get_error_message();

			return $report;
		}

		$max_width  = (int) $args['max_width'];
		$max_height = (int) $args['max_height'];

		if ( ( $max_width > 0 && $width > $max_width ) || ( $max_height > 0 && $height > $max_height ) ) {
			$resized = $editor->resize(
				$max_width > 0 ? $max_width : null,
				$max_height > 0 ? $max_height : null,
				false
			);

			if ( is_wp_error( $resized ) ) {
				$report['code']    = 'resize_failed';
				$report['message'] = $resized->get_error_message();

				return $report;
			}
		}

		$quality = max( 1, min( 100, (int) $args['quality'] ) );

		/*
		 * WP_Image_Editor::get_output_format() calls set_quality() with no
		 * arguments whenever the output mime type differs from the source, which
		 * silently discards an explicitly configured quality. Every conversion
		 * here changes the mime type, so the value is pinned through the filter
		 * that the reset itself consults.
		 */
		$pin_quality = static function ( $default_quality, $mime_type ) use ( $quality ) {
			return 'image/webp' === $mime_type ? $quality : $default_quality;
		};

		add_filter( 'wp_editor_set_quality', $pin_quality, PHP_INT_MAX, 2 );

		$editor->set_quality( $quality );
		$saved = $editor->save( $target, 'image/webp' );

		remove_filter( 'wp_editor_set_quality', $pin_quality, PHP_INT_MAX );

		if ( is_wp_error( $saved ) ) {
			$report['code']    = 'save_failed';
			$report['message'] = $saved->get_error_message();

			return $report;
		}

		clearstatcache( true, $target );

		if ( ! is_file( $target ) ) {
			$report['code']    = 'save_failed';
			$report['message'] = __( 'The WebP file was not written to disk.', 'beaver-image-optimizer' );

			return $report;
		}

		$webp_bytes = (int) filesize( $target );

		// Flat-colour PNGs frequently grow as WebP. Keeping those would cost disk
		// space and bandwidth for no benefit, so discard the result instead.
		$threshold = $report['source_bytes'] * ( 1 - ( max( 0, (int) $args['min_gain'] ) / 100 ) );

		if ( $webp_bytes < 1 || $webp_bytes >= $threshold ) {
			wp_delete_file( $target );

			$report['code']    = 'no_gain';
			$report['path']    = '';
			$report['message'] = __( 'Skipped: the WebP version was not meaningfully smaller.', 'beaver-image-optimizer' );

			return $report;
		}

		$report['success']    = true;
		$report['code']       = 'converted';
		$report['webp_bytes'] = $webp_bytes;
		$report['message']    = __( 'Converted to WebP.', 'beaver-image-optimizer' );

		/**
		 * Fires after an image file has been converted to WebP.
		 *
		 * @since 1.0.0
		 *
		 * @param string $source Absolute path to the source image.
		 * @param string $target Absolute path to the WebP sidecar.
		 * @param array  $report Conversion report.
		 */
		do_action( 'beaver_io_file_converted', $source, $target, $report );

		return $report;
	}
}
