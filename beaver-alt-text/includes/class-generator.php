<?php
/**
 * Per-attachment generation, storage and publication.
 *
 * @package BeaverAltText
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides what needs alt text, asks for it, and stores the answer.
 *
 * Nothing here writes to an attachment's alt text without going through
 * {@see Beaver_Alt_Generator::apply()}, which is the single place the
 * never-overwrite rule is enforced.
 *
 * @since 1.0.0
 */
class Beaver_Alt_Generator {

	const OPTION_SETTINGS = 'beaver_alt_settings';
	const OPTION_STATS    = 'beaver_alt_stats';

	const META_ALT       = '_wp_attachment_image_alt';
	const META_PROPOSAL  = '_beaver_alt_proposal';
	const META_GENERATED = '_beaver_alt_generated';
	const META_ERROR     = '_beaver_alt_error';

	/**
	 * Runtime settings cache.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Returns the shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function default_settings() {
		return array(
			'provider'       => 'claude',
			'api_key'        => '',
			'endpoint'       => '',
			'model'          => '',
			'site_context'   => '',
			'auto_apply'     => 0,
			'apply_below'    => 'high',
			'use_context'    => 1,
			'batch_size'     => 3,
			'max_edge'       => 768,
			'language'       => '',
			'price_input'    => 5,
			'price_output'   => 25,
		);
	}

	/**
	 * Returns the current settings merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	public static function get_settings() {
		if ( null === self::$settings ) {
			$stored = get_option( self::OPTION_SETTINGS, array() );

			self::$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::default_settings() );
		}

		return self::$settings;
	}

	/**
	 * Reads a single setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed Setting value.
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Drops the runtime settings cache.
	 *
	 * @since 1.0.0
	 */
	public static function flush_settings_cache() {
		self::$settings = null;
	}

	/**
	 * Validates a settings payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$providers         = Beaver_Alt_Provider::providers();
		$provider          = sanitize_key( $input['provider'] ?? $defaults['provider'] );
		$clean['provider'] = isset( $providers[ $provider ] ) ? $provider : $defaults['provider'];

		$clean['api_key']  = trim( sanitize_text_field( $input['api_key'] ?? '' ) );
		$clean['endpoint'] = esc_url_raw( trim( (string) ( $input['endpoint'] ?? '' ) ) );
		$clean['model']    = trim( sanitize_text_field( $input['model'] ?? '' ) );
		$clean['site_context'] = sanitize_textarea_field( $input['site_context'] ?? '' );
		$clean['auto_apply']   = empty( $input['auto_apply'] ) ? 0 : 1;
		$clean['use_context']  = empty( $input['use_context'] ) ? 0 : 1;

		$confidence           = sanitize_key( $input['apply_below'] ?? $defaults['apply_below'] );
		$clean['apply_below'] = in_array( $confidence, array( 'high', 'medium', 'low' ), true ) ? $confidence : 'high';

		$clean['batch_size'] = (int) max( 1, min( 10, (int) ( $input['batch_size'] ?? $defaults['batch_size'] ) ) );
		$clean['max_edge']   = (int) max( 320, min( 1568, (int) ( $input['max_edge'] ?? $defaults['max_edge'] ) ) );
		$clean['language']   = trim( sanitize_text_field( $input['language'] ?? '' ) );

		$clean['price_input']  = (float) max( 0, (float) ( $input['price_input'] ?? $defaults['price_input'] ) );
		$clean['price_output'] = (float) max( 0, (float) ( $input['price_output'] ?? $defaults['price_output'] ) );

		return $clean;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Eligibility
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Explains what should happen to one attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Re-describe even when alt text already exists.
	 * @return array {
	 *     @type bool   $eligible True when the attachment should be described.
	 *     @type string $reason   Machine-readable reason when it should not be.
	 *     @type string $message  Translated explanation.
	 * }
	 */
	public static function eligibility( $attachment_id, $force = false ) {
		$mime = get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, Beaver_Alt_Provider::SUPPORTED_MIME_TYPES, true ) ) {
			return array(
				'eligible' => false,
				'reason'   => 'unsupported_type',
				'message'  => __( 'Not an image the model can read.', 'beaver-alt-text' ),
			);
		}

		$alt = (string) get_post_meta( $attachment_id, self::META_ALT, true );

		if ( '' === trim( $alt ) ) {
			return array( 'eligible' => true, 'reason' => '', 'message' => '' );
		}

		/*
		 * Existing alt text is only ever replaced when this plugin wrote it and
		 * nobody has touched it since. Comparing against the stored hash is what
		 * distinguishes "our text, still untouched" from "a human edited it" —
		 * without it, a re-run would quietly discard someone's corrections.
		 */
		$generated = get_post_meta( $attachment_id, self::META_GENERATED, true );
		$is_ours   = is_array( $generated )
			&& isset( $generated['hash'] )
			&& hash_equals( (string) $generated['hash'], md5( $alt ) );

		if ( ! $is_ours ) {
			return array(
				'eligible' => false,
				'reason'   => 'human_alt',
				'message'  => __( 'This image already has alt text that was not written by this plugin. It has been left alone.', 'beaver-alt-text' ),
			);
		}

		if ( ! $force ) {
			return array(
				'eligible' => false,
				'reason'   => 'already_generated',
				'message'  => __( 'Alt text has already been generated for this image.', 'beaver-alt-text' ),
			);
		}

		return array( 'eligible' => true, 'reason' => '', 'message' => '' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Generation
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Generates and stores a proposal for one attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param bool  $force         Re-describe even when alt text already exists.
	 * @param float $timeout       Seconds available for the API call.
	 * @return array Result with 'status', 'message' and, when generated, 'proposal'.
	 */
	public static function generate( $attachment_id, $force = false, $timeout = 60 ) {
		$attachment_id = (int) $attachment_id;

		$eligibility = self::eligibility( $attachment_id, $force );

		if ( ! $eligibility['eligible'] ) {
			return array(
				'status'  => 'skipped',
				'message' => $eligibility['message'],
			);
		}

		$image = self::prepare_image( $attachment_id );

		if ( is_wp_error( $image ) ) {
			return self::fail( $attachment_id, $image );
		}

		$result = Beaver_Alt_Provider::describe( $image, $timeout );

		if ( is_wp_error( $result ) ) {
			return self::fail( $attachment_id, $result );
		}

		$result['alt'] = self::trim_alt( $result['alt'] );

		if ( $result['decorative'] ) {
			$result['alt'] = '';
		} elseif ( '' === $result['alt'] ) {
			// Not decorative but nothing written: treat as a failure rather than
			// publishing an empty alt, which would claim the image is ornamental.
			return self::fail(
				$attachment_id,
				new WP_Error( 'beaver_alt_empty_text', __( 'The model returned no description for an image it did not consider decorative.', 'beaver-alt-text' ) )
			);
		}

		$proposal = array(
			'alt'        => $result['alt'],
			'caption'    => $result['caption'],
			'decorative' => $result['decorative'],
			'confidence' => $result['confidence'],
			'reason'     => $result['reason'],
			'model'      => $result['model'],
			'timestamp'  => time(),
		);

		update_post_meta( $attachment_id, self::META_PROPOSAL, $proposal );
		delete_post_meta( $attachment_id, self::META_ERROR );
		Beaver_Alt_Queue::flush_counts();

		self::record_usage( $result['usage'] );
		self::bump_stat( 'generated' );

		$applied = false;

		if ( self::should_auto_apply( $proposal ) ) {
			$applied = ! is_wp_error( self::apply( $attachment_id ) );
		}

		return array(
			'status'   => $applied ? 'applied' : 'proposed',
			'message'  => $applied
				? __( 'Alt text written.', 'beaver-alt-text' )
				: __( 'Waiting for review.', 'beaver-alt-text' ),
			'proposal' => $proposal,
		);
	}

	/**
	 * Whether a proposal may be published without a human looking at it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $proposal Stored proposal.
	 * @return bool Whether to apply automatically.
	 */
	private static function should_auto_apply( $proposal ) {
		if ( ! self::get_setting( 'auto_apply' ) ) {
			return false;
		}

		$rank      = array( 'low' => 1, 'medium' => 2, 'high' => 3 );
		$threshold = $rank[ (string) self::get_setting( 'apply_below', 'high' ) ] ?? 3;
		$actual    = $rank[ (string) ( $proposal['confidence'] ?? 'low' ) ] ?? 1;

		return $actual >= $threshold;
	}

	/**
	 * Publishes a stored proposal to the attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error The applied proposal, or an error.
	 */
	public static function apply( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$proposal      = get_post_meta( $attachment_id, self::META_PROPOSAL, true );

		if ( ! is_array( $proposal ) || ! isset( $proposal['alt'] ) ) {
			return new WP_Error( 'beaver_alt_no_proposal', __( 'There is nothing to apply for this image.', 'beaver-alt-text' ) );
		}

		$existing = (string) get_post_meta( $attachment_id, self::META_ALT, true );
		$generated = get_post_meta( $attachment_id, self::META_GENERATED, true );
		$is_ours   = is_array( $generated )
			&& isset( $generated['hash'] )
			&& hash_equals( (string) $generated['hash'], md5( $existing ) );

		// Re-checked at write time, not just at generate time: a human may have
		// written alt text in the minutes a proposal sat in the review queue.
		if ( '' !== trim( $existing ) && ! $is_ours ) {
			return new WP_Error( 'beaver_alt_human_alt', __( 'Someone wrote alt text for this image while the proposal was waiting. It has been left alone.', 'beaver-alt-text' ) );
		}

		$alt = (string) $proposal['alt'];

		update_post_meta( $attachment_id, self::META_ALT, $alt );
		update_post_meta(
			$attachment_id,
			self::META_GENERATED,
			array(
				'hash'       => md5( $alt ),
				'decorative' => ! empty( $proposal['decorative'] ),
				'confidence' => (string) ( $proposal['confidence'] ?? 'low' ),
				'model'      => (string) ( $proposal['model'] ?? '' ),
				'timestamp'  => time(),
			)
		);

		delete_post_meta( $attachment_id, self::META_PROPOSAL );
		self::bump_stat( 'applied' );
		Beaver_Alt_Queue::flush_counts();

		/**
		 * Fires after alt text has been written to an attachment.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $alt           The alt text written.
		 * @param array  $proposal      The proposal it came from.
		 */
		do_action( 'beaver_alt_applied', $attachment_id, $alt, $proposal );

		return $proposal;
	}

	/**
	 * Discards a stored proposal without publishing it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function reject( $attachment_id ) {
		delete_post_meta( (int) $attachment_id, self::META_PROPOSAL );
		self::bump_stat( 'rejected' );
		Beaver_Alt_Queue::flush_counts();
	}

	/**
	 * Records a failure against the attachment.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param WP_Error $error         What went wrong.
	 * @return array Result array.
	 */
	private static function fail( $attachment_id, $error ) {
		update_post_meta(
			$attachment_id,
			self::META_ERROR,
			array(
				'code'      => $error->get_error_code(),
				'message'   => $error->get_error_message(),
				'timestamp' => time(),
			)
		);

		self::bump_stat( 'failed' );

		return array(
			'status'  => 'failed',
			'message' => $error->get_error_message(),
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Image preparation
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds the image payload for one attachment.
	 *
	 * Alt text needs a legible image, not a large one, so this prefers a
	 * thumbnail WordPress has already written to disk. That keeps the token
	 * cost down and, more importantly, avoids decoding a full size photograph
	 * into memory on shared hosting just to describe it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error Payload, or an error.
	 */
	private static function prepare_image( $attachment_id ) {
		$full = get_attached_file( $attachment_id );

		if ( ! $full || ! is_file( $full ) ) {
			return new WP_Error( 'beaver_alt_missing_file', __( 'The image file is missing from disk.', 'beaver-alt-text' ) );
		}

		$target = (int) self::get_setting( 'max_edge', 768 );
		$dir    = trailingslashit( dirname( $full ) );
		$meta   = wp_get_attachment_metadata( $attachment_id );

		$candidates = array();

		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( (array) $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) || empty( $size['width'] ) ) {
					continue;
				}

				$path = $dir . $size['file'];

				if ( is_file( $path ) ) {
					$candidates[] = array(
						'path'  => $path,
						'width' => (int) $size['width'],
						'mime'  => isset( $size['mime-type'] ) ? (string) $size['mime-type'] : '',
					);
				}
			}
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return $a['width'] <=> $b['width'];
			}
		);

		$chosen = null;

		foreach ( $candidates as $candidate ) {
			// The smallest rendition that is still big enough to read.
			if ( $candidate['width'] >= min( $target, 512 ) ) {
				$chosen = $candidate;
				break;
			}
		}

		if ( null === $chosen ) {
			$chosen = array(
				'path'  => $full,
				'width' => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'mime'  => (string) get_post_mime_type( $attachment_id ),
			);
		}

		$mime = '' !== $chosen['mime'] ? $chosen['mime'] : (string) wp_get_image_mime( $chosen['path'] );

		if ( ! in_array( $mime, Beaver_Alt_Provider::SUPPORTED_MIME_TYPES, true ) ) {
			return new WP_Error( 'beaver_alt_unsupported_type', __( 'Not an image the model can read.', 'beaver-alt-text' ) );
		}

		$bytes = (int) filesize( $chosen['path'] );

		/**
		 * Filters the largest image, in bytes, that is sent without resizing.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Byte ceiling. Default 2MB.
		 */
		$limit = (int) apply_filters( 'beaver_alt_max_upload_bytes', 2 * MB_IN_BYTES );

		if ( $bytes > $limit || ( $chosen['width'] > 0 && $chosen['width'] > $target * 2 ) ) {
			$resized = self::resize( $chosen['path'], $target );

			if ( ! is_wp_error( $resized ) ) {
				$chosen['path'] = $resized['path'];
				$mime           = $resized['mime'];
				$temporary      = true;
			}
		}

		$data = file_get_contents( $chosen['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! empty( $temporary ) ) {
			wp_delete_file( $chosen['path'] );
		}

		if ( false === $data || '' === $data ) {
			return new WP_Error( 'beaver_alt_unreadable', __( 'The image file could not be read.', 'beaver-alt-text' ) );
		}

		return array(
			'data'       => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'media_type' => $mime,
			'filename'   => wp_basename( (string) $full ),
			'context'    => self::get_setting( 'use_context' ) ? self::context_for( $attachment_id ) : '',
		);
	}

	/**
	 * Writes a smaller copy of an image to the temp directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path   Source path.
	 * @param int    $target Longest edge in pixels.
	 * @return array|WP_Error Path and MIME type, or an error.
	 */
	private static function resize( $path, $target ) {
		wp_raise_memory_limit( 'image' );

		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$resized = $editor->resize( $target, $target, false );

		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$saved = $editor->save( trailingslashit( get_temp_dir() ) . 'beaver-alt-' . wp_generate_password( 8, false ) . '.jpg', 'image/jpeg' );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return is_wp_error( $saved ) ? $saved : new WP_Error( 'beaver_alt_resize_failed', __( 'A smaller copy of the image could not be written.', 'beaver-alt-text' ) );
		}

		return array(
			'path' => $saved['path'],
			'mime' => 'image/jpeg',
		);
	}

	/**
	 * Returns a short excerpt of where the image is used.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Context, or an empty string.
	 */
	private static function context_for( $attachment_id ) {
		$parent = (int) wp_get_post_parent_id( $attachment_id );

		if ( $parent <= 0 ) {
			return '';
		}

		$post = get_post( $parent );

		if ( ! $post ) {
			return '';
		}

		$excerpt = wp_strip_all_tags( (string) $post->post_content );
		$excerpt = preg_replace( '/\s+/', ' ', $excerpt );

		return trim( $post->post_title . '. ' . mb_substr( (string) $excerpt, 0, 300 ) );
	}

	/**
	 * Trims alt text to a sensible length on a word boundary.
	 *
	 * @since 1.0.0
	 *
	 * @param string $alt Raw alt text.
	 * @return string Trimmed alt text.
	 */
	private static function trim_alt( $alt ) {
		$alt = trim( preg_replace( '/\s+/', ' ', (string) $alt ) );

		// Models are trained out of these openings but occasionally still use
		// them, and a screen reader already announces that this is an image.
		$alt = preg_replace( '/^(an?\s+)?(image|photo|picture|photograph|screenshot)\s+(of|showing|depicting)\s+/i', '', $alt );
		$alt = trim( $alt );

		if ( '' === $alt ) {
			return '';
		}

		if ( mb_strlen( $alt ) > 125 ) {
			$alt = mb_substr( $alt, 0, 125 );
			$cut = mb_strrpos( $alt, ' ' );

			if ( false !== $cut && $cut > 60 ) {
				$alt = mb_substr( $alt, 0, $cut );
			}

			$alt = rtrim( $alt, " ,;:-" ) . '…';
		}

		return ucfirst( $alt );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Counters
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the empty statistics shape.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty stats.
	 */
	public static function empty_stats() {
		return array(
			'generated'     => 0,
			'applied'       => 0,
			'rejected'      => 0,
			'failed'        => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
		);
	}

	/**
	 * Returns the aggregate statistics.
	 *
	 * @since 1.0.0
	 *
	 * @return array Stats.
	 */
	public static function get_stats() {
		$stats = get_option( self::OPTION_STATS, array() );

		return wp_parse_args( is_array( $stats ) ? $stats : array(), self::empty_stats() );
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
	 * Increments one counter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Counter name.
	 */
	private static function bump_stat( $key ) {
		$stats = self::get_stats();

		if ( isset( $stats[ $key ] ) ) {
			++$stats[ $key ];
			update_option( self::OPTION_STATS, $stats, false );
		}
	}

	/**
	 * Adds token usage to the running totals.
	 *
	 * @since 1.0.0
	 *
	 * @param array $usage Usage reported by the API.
	 */
	private static function record_usage( $usage ) {
		$stats = self::get_stats();

		$stats['input_tokens']  += (int) ( $usage['input'] ?? 0 ) + (int) ( $usage['cache_read'] ?? 0 ) + (int) ( $usage['cache_write'] ?? 0 );
		$stats['output_tokens'] += (int) ( $usage['output'] ?? 0 );

		update_option( self::OPTION_STATS, $stats, false );
	}
}
