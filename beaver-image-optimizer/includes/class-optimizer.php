<?php
/**
 * Attachment level orchestration, delivery and statistics.
 *
 * @package BeaverImageOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ties the converter to the media library.
 *
 * Owns settings, per-attachment processing, the Apache delivery rules, lazy
 * loading behaviour and the aggregate statistics.
 *
 * @since 1.0.0
 */
class Beaver_Image_Optimizer {

	const OPTION_SETTINGS = 'beaver_io_settings';
	const OPTION_STATS    = 'beaver_io_stats';
	const OPTION_QUEUE    = 'beaver_io_queue';
	const OPTION_INFLIGHT = 'beaver_io_inflight';

	/**
	 * Seconds that must remain before another attachment is started.
	 *
	 * @var float
	 */
	const MIN_ATTACHMENT_BUDGET = 3.0;

	const META_STATUS = '_beaver_io_status';
	const META_STATS  = '_beaver_io_stats';
	const META_ERROR  = '_beaver_io_error';

	const HTACCESS_MARKER = 'Beaver Image Optimizer';

	/**
	 * Runtime settings cache.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Registers the runtime hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'filter_generate_metadata' ), 9999, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );

		// The runtime cache has to be dropped once the new value is on disk, not
		// while it is still being sanitized, or the next read in the same request
		// simply re-caches the superseded settings.
		add_action( 'add_option_' . self::OPTION_SETTINGS, array( __CLASS__, 'flush_settings_cache' ) );
		add_action( 'update_option_' . self::OPTION_SETTINGS, array( __CLASS__, 'flush_settings_cache' ) );

		$settings = self::get_settings();

		if ( empty( $settings['lazy_load'] ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_false' );
		} else {
			add_filter( 'wp_omit_loading_attr_threshold', array( __CLASS__, 'filter_lazy_threshold' ) );
		}

		if ( ! empty( $settings['picture_fallback'] ) ) {
			add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img_tag' ), 20, 3 );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default settings.
	 */
	public static function default_settings() {
		return array(
			'quality_preset'   => 'balanced',
			'auto_optimize'    => 1,
			'keep_originals'   => 1,
			'file_types'       => array( 'image/jpeg', 'image/png' ),
			'max_width'        => 2560,
			'max_height'       => 2560,
			'min_gain'         => 3,
			'lazy_load'        => 1,
			'lazy_skip_first'  => 1,
			'picture_fallback' => 0,
			'batch_size'       => 3,
		);
	}

	/**
	 * Returns the current settings, merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	public static function get_settings() {
		if ( null === self::$settings ) {
			$stored = get_option( self::OPTION_SETTINGS, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$settings = wp_parse_args( $stored, self::default_settings() );
		}

		return self::$settings;
	}

	/**
	 * Reads a single setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is unknown.
	 * @return mixed Setting value.
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Clears the runtime settings cache.
	 *
	 * @since 1.0.0
	 */
	public static function flush_settings_cache() {
		self::$settings = null;
	}

	/**
	 * Validates and normalises a settings payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings, typically from $_POST.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$presets                  = self::quality_preset_keys();
		$preset                   = isset( $input['quality_preset'] ) ? sanitize_key( $input['quality_preset'] ) : '';
		$clean['quality_preset']  = in_array( $preset, $presets, true ) ? $preset : $defaults['quality_preset'];

		$clean['auto_optimize']    = empty( $input['auto_optimize'] ) ? 0 : 1;
		$clean['keep_originals']   = empty( $input['keep_originals'] ) ? 0 : 1;
		$clean['lazy_load']        = empty( $input['lazy_load'] ) ? 0 : 1;
		$clean['picture_fallback'] = empty( $input['picture_fallback'] ) ? 0 : 1;

		$types                = isset( $input['file_types'] ) ? (array) $input['file_types'] : array();
		$types                = array_values( array_intersect( array_map( 'sanitize_text_field', $types ), Beaver_Image_Converter::SUPPORTED_MIME_TYPES ) );
		$clean['file_types']  = empty( $types ) ? $defaults['file_types'] : $types;

		$clean['max_width']       = self::clamp_int( $input['max_width'] ?? $defaults['max_width'], 0, 10000 );
		$clean['max_height']      = self::clamp_int( $input['max_height'] ?? $defaults['max_height'], 0, 10000 );
		$clean['min_gain']        = self::clamp_int( $input['min_gain'] ?? $defaults['min_gain'], 0, 90 );
		$clean['lazy_skip_first'] = self::clamp_int( $input['lazy_skip_first'] ?? $defaults['lazy_skip_first'], 0, 10 );
		$clean['batch_size']      = self::clamp_int( $input['batch_size'] ?? $defaults['batch_size'], 1, 20 );

		return $clean;
	}

	/**
	 * Clamps a value into an integer range.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Lower bound.
	 * @param int   $max   Upper bound.
	 * @return int Clamped integer.
	 */
	private static function clamp_int( $value, $min, $max ) {
		return (int) max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Returns the valid quality preset keys.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Preset keys.
	 */
	public static function quality_preset_keys() {
		return array_keys( Beaver_Image_Converter::quality_presets() );
	}

	/**
	 * Returns the numeric quality implied by the current preset.
	 *
	 * @since 1.0.0
	 *
	 * @return int Quality between 1 and 100.
	 */
	public static function current_quality() {
		return Beaver_Image_Converter::preset_quality( self::get_setting( 'quality_preset', 'balanced' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Upload pipeline
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Converts freshly generated attachment sizes.
	 *
	 * Runs late on `wp_generate_attachment_metadata` so that every registered
	 * size, including those added by Elementor or WooCommerce, already exists on
	 * disk.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $metadata      Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       Either 'create' or 'update'.
	 * @return array Possibly rewritten metadata.
	 */
	public static function filter_generate_metadata( $metadata, $attachment_id, $context = 'create' ) {
		unset( $context );

		if ( ! self::get_setting( 'auto_optimize' ) ) {
			return $metadata;
		}

		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		$processed = self::process( (int) $attachment_id, $metadata );

		return $processed['metadata'];
	}

	/**
	 * Optimizes one attachment and persists the resulting metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Reconvert even when already optimized.
	 * @return array Result report from self::process().
	 */
	public static function optimize_attachment( $attachment_id, $force = false, $args = array() ) {
		$attachment_id = (int) $attachment_id;
		$metadata      = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		$processed = self::process(
			$attachment_id,
			$metadata,
			array_merge( (array) $args, array( 'force' => (bool) $force ) )
		);

		if ( ! empty( $processed['metadata_changed'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $processed['metadata'] );
		}

		return $processed;
	}

	/**
	 * Converts every file belonging to an attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Attachment metadata.
	 * @param array $args          Optional. Accepts 'force' and 'time_budget'.
	 * @return array {
	 *     @type array  $metadata         Metadata, rewritten when originals were replaced.
	 *     @type bool   $metadata_changed Whether the metadata differs from the input.
	 *     @type string $status           One of 'optimized', 'partial', 'skipped' or 'failed'.
	 *     @type string $message          Translated summary.
	 *     @type array  $errors           Per-file failures, each with 'code', 'message' and 'file'.
	 *     @type int    $files            Number of files converted.
	 *     @type int    $original_bytes   Total source bytes for converted files.
	 *     @type int    $webp_bytes       Total WebP bytes written.
	 * }
	 */
	public static function process( $attachment_id, $metadata, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'force'       => false,
				'time_budget' => self::time_budget(),
			)
		);

		$result = array(
			'metadata'         => $metadata,
			'metadata_changed' => false,
			'status'           => 'skipped',
			'message'          => '',
			'errors'           => array(),
			'files'            => 0,
			'original_bytes'   => 0,
			'webp_bytes'       => 0,
		);

		$settings = self::get_settings();

		if ( ! Beaver_Image_Converter::is_supported() ) {
			/*
			 * Deliberately leaves no status meta behind. Marking the attachment
			 * would exclude it from every future scan, so images would stay
			 * unconverted even after the host enables WebP in PHP.
			 */
			$result['status']  = 'failed';
			$result['message'] = __( 'This server cannot write WebP images.', 'beaver-image-optimizer' );

			return $result;
		}

		$mime = get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, (array) $settings['file_types'], true ) ) {
			return self::finish( $attachment_id, $result, 'skipped', __( 'File type is not enabled in the settings.', 'beaver-image-optimizer' ), false );
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! is_file( $file ) ) {
			return self::finish( $attachment_id, $result, 'failed', __( 'The attachment file is missing from disk.', 'beaver-image-optimizer' ) );
		}

		if ( ! self::is_inside_uploads( $file ) ) {
			return self::finish( $attachment_id, $result, 'failed', __( 'The attachment lives outside the uploads directory.', 'beaver-image-optimizer' ) );
		}

		if ( ! $args['force'] && 'optimized' === get_post_meta( $attachment_id, self::META_STATUS, true ) && is_file( Beaver_Image_Converter::sidecar_path( $file ) ) ) {
			// Reports the state the attachment is actually in. Returning the
			// initial 'skipped' here would log a finished image as though it had
			// been passed over. No stats are touched: this path never reaches
			// self::finish(), so the earlier contribution stands unchanged.
			$result['status']  = 'optimized';
			$result['message'] = __( 'Already optimized.', 'beaver-image-optimizer' );

			return $result;
		}

		$targets = self::collect_targets( $file, $metadata );
		$quality = self::current_quality();
		$started = microtime( true );
		$failed  = 0;
		$partial = false;

		foreach ( $targets as $key => $target ) {
			if ( microtime( true ) - $started > $args['time_budget'] ) {
				$partial = true;
				break;
			}

			$is_full = ( 'full' === $key );

			$report = Beaver_Image_Converter::convert(
				$target['path'],
				array(
					'quality'    => $quality,
					'max_width'  => $is_full ? (int) $settings['max_width'] : 0,
					'max_height' => $is_full ? (int) $settings['max_height'] : 0,
					'min_gain'   => (int) $settings['min_gain'],
					'force'      => (bool) $args['force'],
				)
			);

			if ( ! $report['success'] ) {
				// 'no_gain' is a decision, not a fault: the WebP came out bigger
				// than the source, so keeping the original is the right outcome
				// and there is nothing to report.
				if ( 'no_gain' !== $report['code'] ) {
					++$failed;

					$result['errors'][] = array(
						'code'    => $report['code'],
						'message' => $report['message'],
						'file'    => wp_basename( $target['path'] ),
						'size'    => $key,
					);
				}

				continue;
			}

			++$result['files'];
			$result['original_bytes'] += $report['source_bytes'];
			$result['webp_bytes']     += $report['webp_bytes'];

			$targets[ $key ]['converted']  = true;
			$targets[ $key ]['webp_path']  = $report['path'];
			$targets[ $key ]['webp_bytes'] = $report['webp_bytes'];
		}

		if ( 0 === $result['files'] ) {
			$status = $failed > 0 ? 'failed' : 'skipped';

			return self::finish(
				$attachment_id,
				$result,
				$status,
				$failed > 0
					? self::error_summary( $result['errors'] )
					: __( 'WebP was not smaller than the original.', 'beaver-image-optimizer' ),
				'failed' === $status
			);
		}

		if ( empty( $settings['keep_originals'] ) ) {
			$replaced = self::replace_originals( $attachment_id, $result['metadata'], $targets );

			if ( $replaced['changed'] ) {
				$result['metadata']         = $replaced['metadata'];
				$result['metadata_changed'] = true;
			}
		}

		$status = ( $partial || $failed > 0 ) ? 'partial' : 'optimized';

		if ( 'optimized' === $status ) {
			$message = __( 'Optimized.', 'beaver-image-optimizer' );
		} elseif ( $failed > 0 ) {
			// Ran out of neither time nor targets — some sizes genuinely failed,
			// so report why rather than implying another pass will finish the job.
			$message = sprintf(
				/* translators: %s: reason the remaining sizes could not be converted. */
				__( 'Partially optimized. %s', 'beaver-image-optimizer' ),
				self::error_summary( $result['errors'] )
			);
		} else {
			$message = __( 'Partially optimized. Run the bulk optimizer to finish the remaining sizes.', 'beaver-image-optimizer' );
		}

		return self::finish( $attachment_id, $result, $status, $message );
	}

	/**
	 * Condenses per-file failures into one sentence for the log and the badge.
	 *
	 * Every size of an image almost always fails for the same reason, so the
	 * first message is the useful one; the rest are summarised as a count
	 * instead of repeating it verbatim.
	 *
	 * @since 1.3.0
	 *
	 * @param array $errors Errors collected during the run.
	 * @return string Translated summary.
	 */
	private static function error_summary( $errors ) {
		$errors = array_values( array_filter( (array) $errors ) );

		if ( empty( $errors ) ) {
			return __( 'No files could be converted.', 'beaver-image-optimizer' );
		}

		$first   = $errors[0];
		$message = isset( $first['message'] ) && '' !== $first['message']
			? $first['message']
			: __( 'No files could be converted.', 'beaver-image-optimizer' );

		$others = count( $errors ) - 1;

		if ( $others > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of additional image sizes that failed. */
				_n( '(%s other size failed too.)', '(%s other sizes failed too.)', $others, 'beaver-image-optimizer' ),
				number_format_i18n( $others )
			);
		}

		return $message;
	}

	/**
	 * Records the outcome on the attachment and in the aggregate statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $result        Result being built.
	 * @param string $status        Final status.
	 * @param string $message       Translated summary.
	 * @param bool   $count         Whether to record the outcome in the totals.
	 * @return array Completed result.
	 */
	private static function finish( $attachment_id, $result, $status, $message, $count = true ) {
		$result['status']  = $status;
		$result['message'] = $message;

		// Re-processing an attachment must replace its earlier contribution to
		// the totals rather than add to it.
		if ( $count ) {
			self::subtract_attachment_stats( $attachment_id );
		}

		update_post_meta( $attachment_id, self::META_STATUS, $status );

		/*
		 * The reason has to outlive the request that produced it: a failure is
		 * usually noticed later, on the media screen or the dashboard, long
		 * after the AJAX response that carried the message has gone. Always
		 * either write or clear this key, so a successful retry never leaves a
		 * stale error behind.
		 */
		if ( ! empty( $result['errors'] ) ) {
			update_post_meta(
				$attachment_id,
				self::META_ERROR,
				array(
					'status'    => $status,
					'message'   => $message,
					// Bounded: a large media library would otherwise store one
					// row per registered thumbnail size for every failure.
					'errors'    => array_slice( array_values( $result['errors'] ), 0, 5 ),
					'timestamp' => time(),
				)
			);
		} else {
			delete_post_meta( $attachment_id, self::META_ERROR );
		}

		if ( in_array( $status, array( 'optimized', 'partial' ), true ) ) {
			update_post_meta(
				$attachment_id,
				self::META_STATS,
				array(
					'files'          => (int) $result['files'],
					'original_bytes' => (int) $result['original_bytes'],
					'webp_bytes'     => (int) $result['webp_bytes'],
					'quality'        => self::current_quality(),
					'kept_originals' => (int) self::get_setting( 'keep_originals' ),
					'version'        => BEAVER_IO_VERSION,
					'timestamp'      => time(),
				)
			);
		}

		if ( $count ) {
			self::record_stats( $status, $result );
		}

		/**
		 * Fires once an attachment has been processed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $status        Final status.
		 * @param array  $result        Full result report.
		 */
		do_action( 'beaver_io_attachment_processed', $attachment_id, $status, $result );

		return $result;
	}

	/**
	 * Builds the de-duplicated list of files belonging to an attachment.
	 *
	 * Several registered sizes routinely resolve to the same file on disk, so
	 * paths are keyed by their normalised value to avoid converting twice.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file     Absolute path to the full size file.
	 * @param array  $metadata Attachment metadata.
	 * @return array<string,array> Target key => target descriptor.
	 */
	private static function collect_targets( $file, $metadata ) {
		$targets = array(
			'full' => array(
				'path'      => $file,
				'size'      => 'full',
				'converted' => false,
			),
		);

		$seen     = array( wp_normalize_path( $file ) => true );
		$base_dir = trailingslashit( dirname( $file ) );

		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $targets;
		}

		foreach ( $metadata['sizes'] as $size => $data ) {
			if ( empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
				continue;
			}

			// Size entries store a bare filename; anything else is untrusted.
			$name = wp_basename( $data['file'] );
			$path = $base_dir . $name;

			if ( isset( $seen[ wp_normalize_path( $path ) ] ) || ! is_file( $path ) ) {
				continue;
			}

			$seen[ wp_normalize_path( $path ) ] = true;

			$targets[ 'size:' . $size ] = array(
				'path'      => $path,
				'size'      => $size,
				'converted' => false,
			);
		}

		return $targets;
	}

	/**
	 * Points the attachment at its WebP files and deletes the converted sources.
	 *
	 * Only files that were verifiably converted are removed, so a partial run can
	 * never leave an attachment pointing at a file that does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Attachment metadata.
	 * @param array $targets       Target descriptors from self::collect_targets().
	 * @return array {
	 *     @type array $metadata Rewritten metadata.
	 *     @type bool  $changed  Whether anything was rewritten.
	 * }
	 */
	private static function replace_originals( $attachment_id, $metadata, $targets ) {
		$changed = false;

		foreach ( $targets as $key => $target ) {
			if ( empty( $target['converted'] ) || empty( $target['webp_path'] ) || ! is_file( $target['webp_path'] ) ) {
				continue;
			}

			if ( ! self::is_inside_uploads( $target['path'] ) || ! self::is_inside_uploads( $target['webp_path'] ) ) {
				continue;
			}

			$webp_name = wp_basename( $target['webp_path'] );

			if ( 'full' === $key ) {
				$relative = _wp_relative_upload_path( $target['webp_path'] );

				if ( ! $relative ) {
					continue;
				}

				$metadata['file']     = $relative;
				$metadata['filesize'] = (int) $target['webp_bytes'];

				$dimensions = wp_getimagesize( $target['webp_path'] );

				if ( is_array( $dimensions ) ) {
					$metadata['width']  = (int) $dimensions[0];
					$metadata['height'] = (int) $dimensions[1];
				}

				update_attached_file( $attachment_id, $relative );
			} else {
				$size = $target['size'];

				if ( ! isset( $metadata['sizes'][ $size ] ) ) {
					continue;
				}

				$metadata['sizes'][ $size ]['file']      = $webp_name;
				$metadata['sizes'][ $size ]['mime-type'] = 'image/webp';
				$metadata['sizes'][ $size ]['filesize']  = (int) $target['webp_bytes'];
			}

			wp_delete_file( $target['path'] );
			$changed = true;
		}

		if ( $changed ) {
			self::set_attachment_mime( $attachment_id, 'image/webp' );
		}

		return array(
			'metadata' => $metadata,
			'changed'  => $changed,
		);
	}

	/**
	 * Updates an attachment MIME type without re-triggering save hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $mime_type     New MIME type.
	 */
	private static function set_attachment_mime( $attachment_id, $mime_type ) {
		global $wpdb;

		if ( get_post_mime_type( $attachment_id ) === $mime_type ) {
			return;
		}

		$wpdb->update(
			$wpdb->posts,
			array( 'post_mime_type' => $mime_type ),
			array( 'ID' => (int) $attachment_id ),
			array( '%s' ),
			array( '%d' )
		);

		clean_post_cache( $attachment_id );
	}

	/**
	 * Removes generated sidecars when an attachment is deleted.
	 *
	 * WordPress already deletes anything listed in the attachment metadata, which
	 * covers the "delete originals" mode. This handles the sidecars that sit
	 * beside untouched originals.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function on_delete_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$paths    = array( $file );
		$base_dir = trailingslashit( dirname( $file ) );

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $data ) {
				if ( ! empty( $data['file'] ) && is_string( $data['file'] ) ) {
					$paths[] = $base_dir . wp_basename( $data['file'] );
				}
			}
		}

		foreach ( array_unique( $paths ) as $path ) {
			$sidecar = Beaver_Image_Converter::sidecar_path( $path );

			if ( is_file( $sidecar ) && self::is_inside_uploads( $sidecar ) ) {
				wp_delete_file( $sidecar );
			}
		}

		self::subtract_attachment_stats( $attachment_id );
	}

	/**
	 * Deletes the sidecars for one attachment, leaving originals untouched.
	 *
	 * Refuses to act when the original file is gone, because in that case the
	 * sidecar is the only surviving copy of the image.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of files deleted.
	 */
	public static function remove_sidecars( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! is_file( $file ) || Beaver_Image_Converter::is_sidecar( $file ) ) {
			return 0;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$deleted  = 0;

		foreach ( self::collect_targets( $file, $metadata ) as $target ) {
			$sidecar = Beaver_Image_Converter::sidecar_path( $target['path'] );

			if ( is_file( $sidecar ) && self::is_inside_uploads( $sidecar ) ) {
				wp_delete_file( $sidecar );
				++$deleted;
			}
		}

		self::subtract_attachment_stats( $attachment_id );

		delete_post_meta( $attachment_id, self::META_STATUS );
		delete_post_meta( $attachment_id, self::META_STATS );
		delete_post_meta( $attachment_id, self::META_ERROR );

		return $deleted;
	}

	/**
	 * Confirms a path resolves inside the uploads directory.
	 *
	 * Every delete and every write is gated on this.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path, existing or not.
	 * @return bool True when the path is contained by the uploads base directory.
	 */
	public static function is_inside_uploads( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$base = realpath( $uploads['basedir'] );
		$real = realpath( $path );

		if ( ! $real ) {
			// The file may not exist yet; validate the parent directory instead.
			$real = realpath( dirname( $path ) );
		}

		if ( ! $base || ! $real ) {
			return false;
		}

		$base = trailingslashit( wp_normalize_path( $base ) );
		$real = trailingslashit( wp_normalize_path( $real ) );

		return 0 === strpos( $real, $base );
	}

	/**
	 * Returns the seconds a single request may spend converting.
	 *
	 * @since 1.0.0
	 *
	 * @return float Seconds.
	 */
	public static function time_budget() {
		$max = (int) ini_get( 'max_execution_time' );

		if ( $max < 1 ) {
			$max = 30;
		}

		/**
		 * Filters the per-request conversion time budget in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param float $budget Seconds available for conversion work.
		 * @param int   $max    The configured max_execution_time.
		 */
		return (float) apply_filters( 'beaver_io_time_budget', min( 20, $max * 0.6 ), $max );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Statistics
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns a zeroed statistics array.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty statistics.
	 */
	public static function empty_stats() {
		return array(
			'images'         => 0,
			'files'          => 0,
			'original_bytes' => 0,
			'webp_bytes'     => 0,
			'disk_freed'     => 0,
			'skipped'        => 0,
			'failed'         => 0,
		);
	}

	/**
	 * Returns the aggregate statistics with derived values added.
	 *
	 * @since 1.0.0
	 *
	 * @return array Statistics including 'saved_bytes' and 'saved_percent'.
	 */
	public static function get_stats() {
		$stats = get_option( self::OPTION_STATS, array() );
		$stats = wp_parse_args( is_array( $stats ) ? $stats : array(), self::empty_stats() );

		$stats['saved_bytes']   = max( 0, (int) $stats['original_bytes'] - (int) $stats['webp_bytes'] );
		$stats['saved_percent'] = $stats['original_bytes'] > 0
			? round( ( $stats['saved_bytes'] / $stats['original_bytes'] ) * 100, 1 )
			: 0.0;

		return $stats;
	}

	/**
	 * Folds one attachment result into the aggregate statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Final status.
	 * @param array  $result Result report.
	 */
	private static function record_stats( $status, $result ) {
		$stats = get_option( self::OPTION_STATS, array() );
		$stats = wp_parse_args( is_array( $stats ) ? $stats : array(), self::empty_stats() );

		if ( in_array( $status, array( 'optimized', 'partial' ), true ) ) {
			++$stats['images'];
			$stats['files']          += (int) $result['files'];
			$stats['original_bytes'] += (int) $result['original_bytes'];
			$stats['webp_bytes']     += (int) $result['webp_bytes'];

			if ( ! self::get_setting( 'keep_originals' ) ) {
				$stats['disk_freed'] += max( 0, (int) $result['original_bytes'] - (int) $result['webp_bytes'] );
			}
		} elseif ( 'failed' === $status ) {
			++$stats['failed'];
		} else {
			++$stats['skipped'];
		}

		update_option( self::OPTION_STATS, $stats, false );
	}

	/**
	 * Removes one attachment's contribution from the aggregate statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function subtract_attachment_stats( $attachment_id ) {
		$saved = get_post_meta( $attachment_id, self::META_STATS, true );

		if ( ! is_array( $saved ) || empty( $saved['files'] ) ) {
			return;
		}

		// Drop the record immediately so the same contribution can never be
		// subtracted twice.
		delete_post_meta( $attachment_id, self::META_STATS );

		$stats = get_option( self::OPTION_STATS, array() );
		$stats = wp_parse_args( is_array( $stats ) ? $stats : array(), self::empty_stats() );

		$stats['images']         = max( 0, $stats['images'] - 1 );
		$stats['files']          = max( 0, $stats['files'] - (int) $saved['files'] );
		$stats['original_bytes'] = max( 0, $stats['original_bytes'] - (int) $saved['original_bytes'] );
		$stats['webp_bytes']     = max( 0, $stats['webp_bytes'] - (int) $saved['webp_bytes'] );

		if ( empty( $saved['kept_originals'] ) ) {
			$stats['disk_freed'] = max( 0, $stats['disk_freed'] - ( (int) $saved['original_bytes'] - (int) $saved['webp_bytes'] ) );
		}

		update_option( self::OPTION_STATS, $stats, false );
	}

	/**
	 * Resets the aggregate statistics.
	 *
	 * @since 1.0.0
	 */
	public static function reset_stats() {
		update_option( self::OPTION_STATS, self::empty_stats(), false );
	}

	/**
	 * Marks the in-flight image, so an unfinished request can be attributed.
	 *
	 * @since 1.3.4
	 *
	 * @param int $attachment_id Attachment about to be processed.
	 */
	private static function mark_inflight( $attachment_id ) {
		update_option(
			self::OPTION_INFLIGHT,
			array(
				'id'   => (int) $attachment_id,
				'time' => time(),
			),
			false
		);
	}

	/**
	 * Clears the in-flight marker without reporting anything.
	 *
	 * Used when a run ends by choice rather than by accident, so that stopping a
	 * job never leaves a marker behind for the next run to blame an image for.
	 *
	 * @since 1.3.4
	 */
	public static function clear_inflight() {
		delete_option( self::OPTION_INFLIGHT );
	}

	/**
	 * Turns an unfinished request into a reported failure.
	 *
	 * A request can die without running any of the plugin's own error handling —
	 * PHP hitting a limit, the process being killed, the connection dropping.
	 * The in-flight marker survives that, so the next request can say which
	 * image was being worked on, mark it failed and drop it from the queue,
	 * which is what stops a bulk run repeating the same crash forever.
	 *
	 * What it must not do is invent a cause. Earlier versions asserted "memory
	 * limit or execution time" for every unfinished request, which produced the
	 * nonsense of a 176x50 logo being blamed for exhausting memory. When PHP
	 * recorded a fatal, that text is the truth and is reported verbatim; when it
	 * did not, the honest answer is that the request was interrupted.
	 *
	 * @since 1.3.1
	 *
	 * @param array|null $fatal Optional. Result of error_get_last() when a fatal
	 *                          was caught, otherwise null.
	 * @return array|null Log row for the recovered attachment, or null when the
	 *                    previous request finished cleanly.
	 */
	public static function recover_inflight( $fatal = null ) {
		$marker = get_option( self::OPTION_INFLIGHT, 0 );

		// Pre-1.3.4 markers stored a bare id.
		if ( is_array( $marker ) ) {
			$attachment_id = isset( $marker['id'] ) ? (int) $marker['id'] : 0;
			$started       = isset( $marker['time'] ) ? (int) $marker['time'] : 0;
		} else {
			$attachment_id = (int) $marker;
			$started       = 0;
		}

		if ( $attachment_id <= 0 ) {
			return null;
		}

		delete_option( self::OPTION_INFLIGHT );

		/*
		 * A marker left over from hours ago belongs to a run nobody is watching
		 * any more. Attributing a failure to it would punish an image that has
		 * most likely been converted since, so it is discarded instead.
		 */
		if ( $started > 0 && ( time() - $started ) > HOUR_IN_SECONDS ) {
			return null;
		}

		/*
		 * The queue is only written once the batch loop finishes, so a request
		 * that died mid-image left the offending id still at the head of the
		 * stored queue. Marking the attachment failed is not enough on its own:
		 * the queue holds explicit ids and would hand back the same image on the
		 * next run, crashing again forever. Drop it here so the run can move on.
		 */
		self::drop_from_queue( $attachment_id );

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		$size = ( $file && is_file( $file ) ) ? wp_getimagesize( $file ) : false;

		$dimensions = '';

		if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
			$dimensions = sprintf(
				/* translators: 1: width in pixels, 2: height in pixels. */
				__( ' The image is %1$d x %2$d pixels.', 'beaver-image-optimizer' ),
				(int) $size[0],
				(int) $size[1]
			);
		}

		$fatal_message = ( is_array( $fatal ) && ! empty( $fatal['message'] ) ) ? (string) $fatal['message'] : '';
		$code          = 'request_aborted';

		if ( '' !== $fatal_message ) {
			// PHP told us exactly what happened. Repeat it rather than paraphrase.
			$code    = 'fatal_error';
			$message = sprintf(
				/* translators: 1: the PHP error message, 2: image dimensions sentence. */
				__( 'The server stopped with a fatal error while converting this image: %1$s%2$s It has been marked as failed and skipped so the rest of the library can finish.', 'beaver-image-optimizer' ),
				$fatal_message,
				$dimensions
			);
		} else {
			/*
			 * No fatal was recorded, so PHP did not fail — the request simply
			 * never came back. A dropped connection, a navigation away, or the
			 * host killing the process all look like this. Saying "out of
			 * memory" here would be a guess, and a wrong one for a small image.
			 */
			$message = sprintf(
				/* translators: %s: image dimensions sentence. */
				__( 'The request handling this image did not finish, and PHP recorded no error — usually a dropped connection or a process stopped by the host rather than a problem with the image itself.%s It has been skipped so the rest of the library can finish; use Retry to try it again.', 'beaver-image-optimizer' ),
				$dimensions
			);
		}

		$result = array(
			'metadata'         => array(),
			'metadata_changed' => false,
			'status'           => 'skipped',
			'message'          => '',
			'errors'           => array(
				array(
					'code'    => $code,
					'message' => $message,
					'file'    => $file ? wp_basename( $file ) : '',
					'size'    => 'full',
				),
			),
			'files'            => 0,
			'original_bytes'   => 0,
			'webp_bytes'       => 0,
		);

		self::finish( $attachment_id, $result, 'failed', $message );

		return array(
			'id'      => $attachment_id,
			'title'   => get_the_title( $attachment_id ),
			'status'  => 'failed',
			'message' => $message,
			'saved'   => 0,
		);
	}

	/**
	 * Returns the stored failure for one attachment.
	 *
	 * @since 1.3.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null Error record, or null when the attachment is healthy.
	 */
	public static function get_error( $attachment_id ) {
		$error = get_post_meta( (int) $attachment_id, self::META_ERROR, true );

		return is_array( $error ) && ! empty( $error['message'] ) ? $error : null;
	}

	/**
	 * Returns the most recently failed attachments, newest first.
	 *
	 * Reads the attachments themselves rather than a separate log, so an image
	 * drops off this list the moment it is fixed, restored or deleted.
	 *
	 * @since 1.3.0
	 *
	 * @param int $limit Optional. Maximum rows to return. Default 10.
	 * @return array<int,array> List of 'id', 'title', 'message', 'code' and 'timestamp'.
	 */
	public static function recent_errors( $limit = 10 ) {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => max( 1, (int) $limit ),
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => self::META_ERROR,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$out = array();

		foreach ( (array) $ids as $id ) {
			$error = self::get_error( $id );

			if ( null === $error ) {
				continue;
			}

			$first = isset( $error['errors'][0] ) ? $error['errors'][0] : array();

			$out[] = array(
				'id'        => (int) $id,
				'title'     => get_the_title( $id ),
				'status'    => isset( $error['status'] ) ? $error['status'] : 'failed',
				'message'   => $error['message'],
				'code'      => isset( $first['code'] ) ? $first['code'] : '',
				'timestamp' => isset( $error['timestamp'] ) ? (int) $error['timestamp'] : 0,
			);
		}

		return $out;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Media library queue
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Finds attachment IDs that still need work.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $include_processed Include attachments already marked.
	 * @param int  $limit             Maximum IDs to return. -1 for all.
	 * @return int[] Attachment IDs.
	 */
	public static function find_attachments( $include_processed = false, $limit = -1 ) {
		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => (array) self::get_setting( 'file_types', Beaver_Image_Converter::SUPPORTED_MIME_TYPES ),
			'posts_per_page'         => $limit,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);

		if ( ! $include_processed ) {
			$args['meta_query'] = array(
				array(
					'key'     => self::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Counts attachments that have never been processed.
	 *
	 * Uses a direct count so that a library with tens of thousands of images
	 * does not have to load every ID into memory just to render a number.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	public static function count_pending() {
		return self::count_attachments( true );
	}

	/**
	 * Counts every convertible attachment in the library.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	public static function count_total() {
		return self::count_attachments( false );
	}

	/**
	 * Counts convertible attachments, optionally only unprocessed ones.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $pending_only Restrict to attachments with no status meta.
	 * @return int Count.
	 */
	private static function count_attachments( $pending_only ) {
		global $wpdb;

		$mimes = array_values( array_intersect(
			(array) self::get_setting( 'file_types', Beaver_Image_Converter::SUPPORTED_MIME_TYPES ),
			Beaver_Image_Converter::SUPPORTED_MIME_TYPES
		) );

		if ( empty( $mimes ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		if ( $pending_only ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND p.post_mime_type IN ( {$placeholders} )
				   AND m.post_id IS NULL",
				array_merge( array( self::META_STATUS ), $mimes )
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 WHERE p.post_type = 'attachment'
				   AND p.post_status = 'inherit'
				   AND p.post_mime_type IN ( {$placeholders} )",
				$mimes
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Stores a work queue.
	 *
	 * IDs are kept as a comma separated string so that even a very large media
	 * library fits comfortably in one non-autoloaded option row.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $ids   Attachment IDs.
	 * @param bool  $force Whether the queue should reconvert existing WebP files.
	 * @return array Queue state.
	 */
	public static function set_queue( $ids, $force = false ) {
		$ids   = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		$queue = array(
			'ids'     => implode( ',', $ids ),
			'total'   => count( $ids ),
			'done'    => 0,
			'force'   => $force ? 1 : 0,
			'started' => time(),
		);

		update_option( self::OPTION_QUEUE, $queue, false );

		return $queue;
	}

	/**
	 * Reads the current queue state.
	 *
	 * @since 1.0.0
	 *
	 * @return array Queue state.
	 */
	public static function get_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );

		return wp_parse_args(
			is_array( $queue ) ? $queue : array(),
			array(
				'ids'     => '',
				'total'   => 0,
				'done'    => 0,
				'force'   => 0,
				'started' => 0,
			)
		);
	}

	/**
	 * Removes one attachment from the stored queue and counts it as done.
	 *
	 * Used when an image has to be abandoned rather than processed, so the
	 * progress figures still add up and the queue cannot serve it again.
	 *
	 * @since 1.3.2
	 *
	 * @param int $attachment_id Attachment to drop.
	 */
	private static function drop_from_queue( $attachment_id ) {
		$queue = self::get_queue();

		if ( '' === $queue['ids'] ) {
			return;
		}

		$ids  = array_map( 'intval', explode( ',', $queue['ids'] ) );
		$kept = array_values( array_diff( $ids, array( (int) $attachment_id ) ) );

		if ( count( $kept ) === count( $ids ) ) {
			return;
		}

		if ( empty( $kept ) ) {
			self::clear_queue();

			return;
		}

		$queue['ids']  = implode( ',', $kept );
		$queue['done'] = (int) $queue['done'] + ( count( $ids ) - count( $kept ) );

		update_option( self::OPTION_QUEUE, $queue, false );
	}

	/**
	 * Removes the queue.
	 *
	 * @since 1.0.0
	 */
	public static function clear_queue() {
		delete_option( self::OPTION_QUEUE );
	}

	/**
	 * Processes the next slice of the queue.
	 *
	 * Stops early once the request time budget is spent so that shared hosts
	 * never hit `max_execution_time` mid-conversion.
	 *
	 * @since 1.0.0
	 *
	 * @param int $batch_size Maximum attachments to process in this call.
	 * @return array {
	 *     @type int   $processed Attachments handled in this call.
	 *     @type int   $done      Attachments handled since the queue started.
	 *     @type int   $total     Queue length.
	 *     @type bool  $complete  Whether the queue is now empty.
	 *     @type array $items     Per-attachment log entries.
	 * }
	 */
	public static function run_queue_batch( $batch_size = 0 ) {
		$queue = self::get_queue();
		$ids   = '' === $queue['ids'] ? array() : array_map( 'intval', explode( ',', $queue['ids'] ) );

		$batch_size = $batch_size > 0 ? (int) $batch_size : (int) self::get_setting( 'batch_size', 3 );
		$budget     = self::time_budget();
		$started    = microtime( true );
		$items      = array();
		$processed  = 0;

		// A previous request that never returned leaves its marker behind. Turn
		// that into a reported failure before doing anything else, so the image
		// that killed the run is named instead of silently retried forever.
		$recovered = self::recover_inflight();

		if ( null !== $recovered ) {
			$items[] = $recovered;
		}

		while ( $processed < $batch_size && ! empty( $ids ) ) {
			$remaining = $budget - ( microtime( true ) - $started );

			/*
			 * Stop before starting work there is no time to finish. Without this
			 * the loop would hand a full budget to each attachment while the
			 * request itself has only one execution limit to spend, so a batch
			 * of slow images overruns max_execution_time and the whole request
			 * dies as an opaque HTTP 500. Always allow the first attachment
			 * through, otherwise a tight limit would stall the queue completely.
			 */
			if ( $processed > 0 && $remaining < self::MIN_ATTACHMENT_BUDGET ) {
				break;
			}

			$attachment_id = (int) array_shift( $ids );

			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				// Marker is written before the work and cleared after it, so if
				// the process dies mid-image the next request knows which one.
				self::mark_inflight( $attachment_id );

				$result = self::optimize_attachment(
					$attachment_id,
					! empty( $queue['force'] ),
					array( 'time_budget' => max( self::MIN_ATTACHMENT_BUDGET, $remaining ) )
				);

				self::clear_inflight();

				$items[] = array(
					'id'      => $attachment_id,
					'title'   => get_the_title( $attachment_id ),
					'status'  => $result['status'],
					'message' => $result['message'],
					'saved'   => max( 0, (int) $result['original_bytes'] - (int) $result['webp_bytes'] ),
				);
			}

			++$processed;
			++$queue['done'];
		}

		$queue['ids'] = implode( ',', $ids );

		if ( empty( $ids ) ) {
			self::clear_queue();
		} else {
			update_option( self::OPTION_QUEUE, $queue, false );
		}

		return array(
			'processed' => $processed,
			'done'      => (int) $queue['done'],
			'total'     => (int) $queue['total'],
			'complete'  => empty( $ids ),
			'items'     => $items,
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Delivery
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds the Apache / LiteSpeed rules that serve WebP transparently.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Lines for insert_with_markers().
	 */
	public static function delivery_rules() {
		return array(
			'<IfModule mod_mime.c>',
			'AddType image/webp .webp',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'# Serve the WebP sidecar when the browser advertises WebP support.',
			'RewriteCond %{HTTP_ACCEPT} image/webp',
			'RewriteCond %{REQUEST_FILENAME}\.webp -f',
			'RewriteRule (.+)\.(jpe?g|png)$ $1.$2.webp [NC,T=image/webp,L]',
			'# Originals removed: keep legacy URLs alive by falling back to the sidecar.',
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteCond %{REQUEST_FILENAME}\.webp -f',
			'RewriteRule (.+)\.(jpe?g|png)$ $1.$2.webp [NC,T=image/webp,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "(?i)\.(jpe?g|png)$">',
			'Header append Vary Accept',
			'</FilesMatch>',
			'</IfModule>',
		);
	}

	/**
	 * Returns the path of the uploads .htaccess file.
	 *
	 * Rules are scoped to the uploads folder rather than the site root so that a
	 * rejected directive can never take the whole site down.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path, or an empty string when uploads are unavailable.
	 */
	public static function htaccess_path() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . '.htaccess';
	}

	/**
	 * Writes the delivery rules into the uploads .htaccess file.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public static function write_delivery_rules() {
		/*
		 * The rules are written regardless of the detected server: every
		 * directive is wrapped in <IfModule>, and a server that does not read
		 * .htaccess simply ignores the file. Gating on detection would break
		 * activation from WP-CLI and from hosts that do not report their
		 * software, for no safety benefit.
		 */
		$path = self::htaccess_path();

		if ( '' === $path ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( ! file_exists( $path ) ) {
			$handle = @fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unwritable uploads directories are handled by the false return.

			if ( ! $handle ) {
				return false;
			}

			fclose( $handle );
		}

		if ( ! is_writable( $path ) ) {
			return false;
		}

		delete_transient( 'beaver_io_delivery_test' );

		return (bool) insert_with_markers( $path, self::HTACCESS_MARKER, self::delivery_rules() );
	}

	/**
	 * Removes the delivery rules from the uploads .htaccess file.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	public static function remove_delivery_rules() {
		$path = self::htaccess_path();

		if ( '' === $path || ! file_exists( $path ) || ! is_writable( $path ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return (bool) insert_with_markers( $path, self::HTACCESS_MARKER, array() );
	}

	/**
	 * Reports whether the delivery rules are currently installed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the marker block is present.
	 */
	public static function delivery_rules_installed() {
		$path = self::htaccess_path();

		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';

		return ! empty( extract_from_markers( $path, self::HTACCESS_MARKER ) );
	}

	/**
	 * Detects whether the web server reads .htaccess files.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True for Apache and LiteSpeed.
	 */
	public static function server_supports_htaccess() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		$supported = ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) );

		/**
		 * Filters whether the server is treated as .htaccess capable.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $supported Detection result.
		 * @param string $software  Lowercased SERVER_SOFTWARE value.
		 */
		return (bool) apply_filters( 'beaver_io_server_supports_htaccess', $supported, $software );
	}

	/**
	 * Verifies WebP delivery with two real HTTP requests.
	 *
	 * One request advertises WebP support and should receive `image/webp`; the
	 * control request does not and should receive the original type.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $refresh Bypass the cached verdict.
	 * @return array {
	 *     @type string $status  One of 'working', 'not_working' or 'untested'.
	 *     @type string $message Translated explanation.
	 *     @type string $url     The URL that was probed.
	 * }
	 */
	public static function test_delivery( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( 'beaver_io_delivery_test' );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = array(
			'status'  => 'untested',
			'message' => __( 'Optimize at least one image, then run the test again.', 'beaver-image-optimizer' ),
			'url'     => '',
		);

		$sample = self::find_sample_url();

		if ( '' === $sample ) {
			return $result;
		}

		$result['url'] = $sample;

		$response = wp_remote_get(
			$sample,
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'headers'   => array( 'Accept' => 'image/webp,image/*,*/*;q=0.8' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['status']  = 'not_working';
			$result['message'] = sprintf(
				/* translators: %s: HTTP error message. */
				__( 'The test request failed: %s', 'beaver-image-optimizer' ),
				$response->get_error_message()
			);

			set_transient( 'beaver_io_delivery_test', $result, HOUR_IN_SECONDS );

			return $result;
		}

		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( false !== strpos( $content_type, 'image/webp' ) ) {
			$result['status']  = 'working';
			$result['message'] = __( 'WebP is being served to browsers that support it.', 'beaver-image-optimizer' );
		} else {
			$result['status']  = 'not_working';
			$result['message'] = __( 'The server returned the original image. Rewrite rules are not being applied — enable the picture element fallback.', 'beaver-image-optimizer' );
		}

		set_transient( 'beaver_io_delivery_test', $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Finds the URL of an original image that has a sidecar, for testing.
	 *
	 * @since 1.0.0
	 *
	 * @return string URL, or an empty string when no candidate exists.
	 */
	private static function find_sample_url() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => self::META_STATUS,
						'value' => 'optimized',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );

			if ( $file && is_file( Beaver_Image_Converter::sidecar_path( $file ) ) ) {
				return (string) wp_get_attachment_url( $id );
			}
		}

		return '';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Front end output
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Controls how many leading images stay eagerly loaded.
	 *
	 * Lazy loading the largest contentful paint image delays it, so the first
	 * images on a page are deliberately excluded.
	 *
	 * @since 1.0.0
	 *
	 * @param int $threshold Core default.
	 * @return int Filtered threshold.
	 */
	public static function filter_lazy_threshold( $threshold ) {
		unset( $threshold );

		return max( 0, (int) self::get_setting( 'lazy_skip_first', 1 ) );
	}

	/**
	 * Wraps content images in a picture element as a non-Apache fallback.
	 *
	 * Only applied to images inside post content, where the markup is safe to
	 * restructure. Theme, Elementor and WooCommerce templates are left alone.
	 *
	 * @since 1.0.0
	 *
	 * @param string $image         The img tag markup.
	 * @param string $context       Filter context.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Possibly wrapped markup.
	 */
	public static function filter_content_img_tag( $image, $context, $attachment_id ) {
		unset( $context, $attachment_id );

		if ( false !== stripos( $image, '<picture' ) || ! preg_match( '/src=["\']([^"\']+)["\']/i', $image, $src_match ) ) {
			return $image;
		}

		$webp_src = self::sidecar_url( $src_match[1] );

		if ( '' === $webp_src ) {
			return $image;
		}

		$source_attrs = sprintf( 'srcset="%s"', esc_attr( $webp_src ) );

		if ( preg_match( '/srcset=["\']([^"\']+)["\']/i', $image, $srcset_match ) ) {
			$webp_srcset = self::sidecar_srcset( $srcset_match[1] );

			if ( '' !== $webp_srcset ) {
				$source_attrs = sprintf( 'srcset="%s"', esc_attr( $webp_srcset ) );
			}
		}

		if ( preg_match( '/sizes=["\']([^"\']+)["\']/i', $image, $sizes_match ) ) {
			$source_attrs .= sprintf( ' sizes="%s"', esc_attr( $sizes_match[1] ) );
		}

		return sprintf( '<picture><source type="image/webp" %s>%s</picture>', $source_attrs, $image );
	}

	/**
	 * Maps an image URL to its sidecar URL when the sidecar exists on disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Image URL.
	 * @return string Sidecar URL, or an empty string.
	 */
	public static function sidecar_url( $url ) {
		static $cache = array();

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$cache[ $url ] = '';

		if ( ! preg_match( '/\.(jpe?g|png)(\?.*)?$/i', $url ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$clean   = strtok( $url, '?' );

		if ( empty( $uploads['baseurl'] ) || 0 !== strpos( $clean, $uploads['baseurl'] ) ) {
			return '';
		}

		$relative = ltrim( substr( $clean, strlen( $uploads['baseurl'] ) ), '/' );
		$path     = trailingslashit( $uploads['basedir'] ) . $relative;
		$sidecar  = Beaver_Image_Converter::sidecar_path( $path );

		if ( self::is_inside_uploads( $sidecar ) && is_file( $sidecar ) ) {
			$cache[ $url ] = Beaver_Image_Converter::sidecar_path( $clean );
		}

		return $cache[ $url ];
	}

	/**
	 * Rewrites a srcset attribute to its sidecar equivalents.
	 *
	 * Returns an empty string unless every candidate could be mapped, so the
	 * browser is never offered a partial set.
	 *
	 * @since 1.0.0
	 *
	 * @param string $srcset Original srcset value.
	 * @return string Rewritten srcset, or an empty string.
	 */
	private static function sidecar_srcset( $srcset ) {
		$candidates = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );
		$rewritten  = array();

		foreach ( $candidates as $candidate ) {
			$parts = preg_split( '/\s+/', $candidate, 2 );
			$webp  = self::sidecar_url( $parts[0] );

			if ( '' === $webp ) {
				return '';
			}

			$rewritten[] = isset( $parts[1] ) ? $webp . ' ' . $parts[1] : $webp;
		}

		return implode( ', ', $rewritten );
	}
}
