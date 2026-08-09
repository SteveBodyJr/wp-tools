<?php
/**
 * Resumable bulk queue.
 *
 * @package BeaverAltText
 */

defined( 'ABSPATH' ) || exit;

/**
 * Walks the media library in batches that fit inside one request.
 *
 * Every request has one execution limit to spend, and a single API call can
 * take many seconds, so the budget is shared across the batch and checked
 * before another image is started rather than after. A request that dies
 * anyway leaves a marker behind, which the next request turns into a reported
 * failure instead of retrying the same image forever.
 *
 * @since 1.0.0
 */
class Beaver_Alt_Queue {

	const OPTION_QUEUE    = 'beaver_alt_queue';
	const OPTION_INFLIGHT = 'beaver_alt_inflight';
	const OPTION_LOCK     = 'beaver_alt_lock';

	const TRANSIENT_PENDING = 'beaver_alt_pending_count';
	const TRANSIENT_REVIEW  = 'beaver_alt_review_count';

	/**
	 * Seconds that must remain before another image is started.
	 *
	 * @var float
	 */
	const MIN_ITEM_BUDGET = 8.0;

	/**
	 * Seconds this request may spend on conversion work.
	 *
	 * @since 1.0.0
	 *
	 * @return float Budget in seconds.
	 */
	public static function time_budget() {
		$max = (int) ini_get( 'max_execution_time' );

		if ( $max < 1 ) {
			$max = 30;
		}

		/**
		 * Filters the per-request time budget.
		 *
		 * @since 1.0.0
		 *
		 * @param float $budget Seconds available.
		 * @param int   $max    The configured max_execution_time.
		 */
		return (float) apply_filters( 'beaver_alt_time_budget', min( 25, $max * 0.7 ), $max );
	}

	/**
	 * Returns the stored queue.
	 *
	 * @since 1.0.0
	 *
	 * @return array Queue state.
	 */
	public static function get() {
		$queue = get_option( self::OPTION_QUEUE, array() );

		return wp_parse_args(
			is_array( $queue ) ? $queue : array(),
			array(
				'ids'   => '',
				'total' => 0,
				'done'  => 0,
				'force' => 0,
			)
		);
	}

	/**
	 * Removes the queue.
	 *
	 * @since 1.0.0
	 */
	public static function clear() {
		delete_option( self::OPTION_QUEUE );
	}

	/**
	 * Clears the in-flight marker without reporting anything.
	 *
	 * Stopping a run is a decision, not a crash — leaving the marker would let
	 * the next run blame whichever image happened to be in progress.
	 *
	 * @since 1.0.0
	 */
	public static function clear_inflight() {
		delete_option( self::OPTION_INFLIGHT );
	}

	/**
	 * Builds a queue from the images that still need alt text.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Include images that already have generated alt text.
	 * @return array The new queue state.
	 */
	public static function build( $force = false ) {
		$ids = self::pending_ids( $force );

		$queue = array(
			'ids'   => implode( ',', $ids ),
			'total' => count( $ids ),
			'done'  => 0,
			'force' => $force ? 1 : 0,
		);

		if ( empty( $ids ) ) {
			self::clear();
		} else {
			update_option( self::OPTION_QUEUE, $queue, false );
		}

		return $queue;
	}

	/**
	 * Returns attachment IDs that need alt text.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Include images this plugin has already described.
	 * @param int  $limit Maximum IDs to return. 0 for no limit.
	 * @return int[] Attachment IDs.
	 */
	public static function pending_ids( $force = false, $limit = 0 ) {
		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => Beaver_Alt_Provider::SUPPORTED_MIME_TYPES,
			'posts_per_page'         => $limit > 0 ? (int) $limit : -1,
			'fields'                 => 'ids',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);

		if ( ! $force ) {
			/*
			 * Attachments with no alt meta at all, or with an empty one. An
			 * empty string is the correct value for a decorative image, so this
			 * would re-offer those — the generated-marker check in
			 * Beaver_Alt_Generator::eligibility() is what filters them back out.
			 */
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => Beaver_Alt_Generator::META_ALT,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Beaver_Alt_Generator::META_ALT,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		$ids = array_map( 'intval', (array) get_posts( $args ) );

		if ( $force ) {
			return $ids;
		}

		/*
		 * eligibility() reads two meta keys per image. Left alone that is two
		 * queries each — several thousand on a real media library, which is why
		 * the scan used to crawl. Priming the meta cache in chunks turns the
		 * whole walk into a handful of queries; get_post_meta() then answers
		 * from memory.
		 */
		$out = array();

		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $id ) {
				if ( Beaver_Alt_Generator::eligibility( $id, false )['eligible'] ) {
					$out[] = $id;
				}
			}
		}

		return $out;
	}

	/**
	 * Counts images that still need alt text.
	 *
	 * Cached briefly: the dashboard asks for this on every load, and the answer
	 * only changes when a run writes something.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	public static function count_pending() {
		$cached = get_transient( self::TRANSIENT_PENDING );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = count( self::pending_ids( false ) );

		set_transient( self::TRANSIENT_PENDING, $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Drops the cached counts.
	 *
	 * @since 1.2.0
	 */
	public static function flush_counts() {
		delete_transient( self::TRANSIENT_PENDING );
		delete_transient( self::TRANSIENT_REVIEW );
	}

	/**
	 * Counts every image the plugin could describe.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	public static function count_total() {
		$counts = 0;

		foreach ( Beaver_Alt_Provider::SUPPORTED_MIME_TYPES as $mime ) {
			$counts += (int) ( wp_count_attachments( $mime )->inherit ?? 0 );
		}

		return $counts;
	}

	/**
	 * Processes the next slice of the queue.
	 *
	 * @since 1.0.0
	 *
	 * @param int $batch_size Maximum images to handle in this call.
	 * @return array Progress report.
	 */
	public static function run_batch( $batch_size = 0 ) {
		/*
		 * Two admins pressing Start at once would each pull from the same queue,
		 * describing images twice and billing twice. The lock is short-lived so
		 * a crashed run cannot wedge the queue: it expires well inside the time
		 * one batch can take.
		 */
		if ( ! self::acquire_lock() ) {
			return array(
				'processed' => 0,
				'done'      => 0,
				'total'     => 0,
				'complete'  => false,
				'locked'    => true,
				'items'     => array(),
			);
		}

		$queue = self::get();
		$ids   = '' === $queue['ids'] ? array() : array_map( 'intval', explode( ',', $queue['ids'] ) );

		$batch_size = $batch_size > 0 ? (int) $batch_size : (int) Beaver_Alt_Generator::get_setting( 'batch_size', 3 );
		$budget     = self::time_budget();
		$started    = microtime( true );
		$items      = array();
		$processed  = 0;

		$recovered = self::recover_inflight();

		if ( null !== $recovered ) {
			$items[] = $recovered;
		}

		while ( $processed < $batch_size && ! empty( $ids ) ) {
			$remaining = $budget - ( microtime( true ) - $started );

			// Always allow the first image through; after that, only start work
			// there is time to finish.
			if ( $processed > 0 && $remaining < self::MIN_ITEM_BUDGET ) {
				break;
			}

			$attachment_id = (int) array_shift( $ids );

			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				self::mark_inflight( $attachment_id );

				$result = Beaver_Alt_Generator::generate(
					$attachment_id,
					! empty( $queue['force'] ),
					max( self::MIN_ITEM_BUDGET, $remaining )
				);

				self::clear_inflight();

				$items[] = array(
					'id'      => $attachment_id,
					'title'   => get_the_title( $attachment_id ),
					'thumb'   => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
					'status'  => $result['status'],
					'message' => $result['message'],
					'alt'     => isset( $result['proposal']['alt'] ) ? $result['proposal']['alt'] : '',
				);
			}

			++$processed;
			++$queue['done'];
		}

		$queue['ids'] = implode( ',', $ids );

		if ( empty( $ids ) ) {
			self::clear();
		} else {
			update_option( self::OPTION_QUEUE, $queue, false );
		}

		self::flush_counts();
		self::release_lock();

		return array(
			'processed' => $processed,
			'done'      => (int) $queue['done'],
			'total'     => (int) $queue['total'],
			'complete'  => empty( $ids ),
			'items'     => $items,
		);
	}

	/**
	 * Takes the run lock, if it is free.
	 *
	 * @since 1.2.0
	 *
	 * @return bool Whether the lock was acquired.
	 */
	private static function acquire_lock() {
		$held = (int) get_option( self::OPTION_LOCK, 0 );

		if ( $held > 0 && ( time() - $held ) < 2 * MINUTE_IN_SECONDS ) {
			return false;
		}

		update_option( self::OPTION_LOCK, time(), false );

		return true;
	}

	/**
	 * Releases the run lock.
	 *
	 * @since 1.2.0
	 */
	public static function release_lock() {
		delete_option( self::OPTION_LOCK );
	}

	/**
	 * Estimates what a run over the pending images will cost.
	 *
	 * Token counts are approximated from the image size actually sent, not from
	 * the originals, because that is what the model is billed on. Prices come
	 * from the settings rather than being baked in: published rates change, and
	 * a stale number printed as fact is worse than no number.
	 *
	 * @since 1.2.0
	 *
	 * @param int $images Number of images. Defaults to the pending count.
	 * @return array Estimate with 'images', 'input', 'output' and 'cost'.
	 */
	public static function estimate( $images = null ) {
		$images = null === $images ? self::count_pending() : (int) $images;
		$edge   = (int) Beaver_Alt_Generator::get_setting( 'max_edge', 768 );

		/*
		 * A resized image is roughly edge x edge x 0.75 pixels, and vision
		 * models bill image input at about one token per 750 pixels. The prompt
		 * and the one-sentence reply are small next to that.
		 */
		$per_image_input  = (int) round( ( $edge * $edge * 0.75 ) / 750 ) + 120;
		$per_image_output = 60;

		$input  = $images * $per_image_input;
		$output = $images * $per_image_output;

		$price_in  = (float) Beaver_Alt_Generator::get_setting( 'price_input', 5 );
		$price_out = (float) Beaver_Alt_Generator::get_setting( 'price_output', 25 );

		return array(
			'images' => $images,
			'input'  => $input,
			'output' => $output,
			'cost'   => ( $input / 1000000 * $price_in ) + ( $output / 1000000 * $price_out ),
		);
	}

	/**
	 * Records the image about to be described.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
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
	 * Turns an unfinished request into a reported failure.
	 *
	 * The queue is only written once a batch finishes, so a request that died
	 * mid-image left that image at the head of the stored queue. Marking it
	 * failed is not enough on its own — the queue holds explicit ids and would
	 * serve the same image again — so it is dropped here too.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $fatal Result of error_get_last(), when one was caught.
	 * @return array|null Log row, or null when the previous request finished cleanly.
	 */
	public static function recover_inflight( $fatal = null ) {
		$marker = get_option( self::OPTION_INFLIGHT, 0 );

		$attachment_id = is_array( $marker ) ? (int) ( $marker['id'] ?? 0 ) : (int) $marker;
		$started       = is_array( $marker ) ? (int) ( $marker['time'] ?? 0 ) : 0;

		if ( $attachment_id <= 0 ) {
			return null;
		}

		delete_option( self::OPTION_INFLIGHT );
		self::drop_from_queue( $attachment_id );

		// A marker left over from hours ago belongs to a run nobody is watching.
		if ( $started > 0 && ( time() - $started ) > HOUR_IN_SECONDS ) {
			return null;
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$fatal_message = ( is_array( $fatal ) && ! empty( $fatal['message'] ) ) ? (string) $fatal['message'] : '';

		if ( '' !== $fatal_message ) {
			// PHP said exactly what happened, so repeat it rather than paraphrase.
			$message = sprintf(
				/* translators: %s: the PHP error message. */
				__( 'The server stopped with a fatal error on this image: %s It has been skipped.', 'beaver-alt-text' ),
				$fatal_message
			);
		} else {
			$message = __( 'The request handling this image did not finish, and PHP recorded no error — usually a dropped connection, a slow reply from the model, or a process stopped by the host. It has been skipped; use Retry to try it again.', 'beaver-alt-text' );
		}

		update_post_meta(
			$attachment_id,
			Beaver_Alt_Generator::META_ERROR,
			array(
				'code'      => '' !== $fatal_message ? 'fatal_error' : 'request_aborted',
				'message'   => $message,
				'timestamp' => time(),
			)
		);

		return array(
			'id'      => $attachment_id,
			'title'   => get_the_title( $attachment_id ),
			'thumb'   => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'status'  => 'failed',
			'message' => $message,
			'alt'     => '',
		);
	}

	/**
	 * Removes one attachment from the stored queue and counts it as done.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment to drop.
	 */
	private static function drop_from_queue( $attachment_id ) {
		$queue = self::get();

		if ( '' === $queue['ids'] ) {
			return;
		}

		$ids  = array_map( 'intval', explode( ',', $queue['ids'] ) );
		$kept = array_values( array_diff( $ids, array( (int) $attachment_id ) ) );

		if ( count( $kept ) === count( $ids ) ) {
			return;
		}

		if ( empty( $kept ) ) {
			self::clear();

			return;
		}

		$queue['ids']  = implode( ',', $kept );
		$queue['done'] = (int) $queue['done'] + ( count( $ids ) - count( $kept ) );

		update_option( self::OPTION_QUEUE, $queue, false );
	}
}
