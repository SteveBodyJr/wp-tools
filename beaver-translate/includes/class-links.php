<?php
/**
 * Translation link storage.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Links a source-language post to its translation in another language.
 *
 * A dedicated table rather than post meta alone, because the interesting
 * queries here are "which of this post's languages still need attention"
 * and "what is waiting for review across the whole site" — both awkward and
 * slow to answer from meta alone once a library has any real size.
 *
 * @since 1.0.0
 */
class Beaver_Translate_Links {

	const DB_VERSION = '2';

	const STATUS_PENDING   = 'pending';
	const STATUS_REVIEW    = 'needs_review';
	const STATUS_PUBLISHED = 'published';
	const STATUS_FAILED    = 'failed';

	/**
	 * Returns the table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'beaver_translate_links';
	}

	/**
	 * Creates the table.
	 *
	 * @since 1.0.0
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				translated_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				pending_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				language_code varchar(12) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				provider varchar(20) NOT NULL DEFAULT '',
				model varchar(100) NOT NULL DEFAULT '',
				error text NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				reviewed_at datetime NULL DEFAULT NULL,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY source_language (source_post_id, language_code),
				KEY translated_post_id (translated_post_id),
				KEY pending_post_id (pending_post_id),
				KEY status (status)
			) {$collate};"
		);

		update_option( 'beaver_translate_db_version', self::DB_VERSION, false );
	}

	/**
	 * Finds the link row for a source post in a given language, if any.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $language_code  Target language code.
	 * @return object|null Row, or null.
	 */
	public static function find( $source_post_id, $language_code ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_post_id = %d AND language_code = %s", // phpcs:ignore WordPress.DB
				(int) $source_post_id,
				$language_code
			)
		);
	}

	/**
	 * Finds the link row a translated post belongs to.
	 *
	 * Checks both columns a post can be found under: a first-time or
	 * never-published translation is `translated_post_id`, but a pending
	 * regeneration of an already-live translation is `pending_post_id` — the
	 * live post being regenerated keeps its own `translated_post_id` row
	 * untouched throughout, so a caller with only a post ID in hand (an edit
	 * screen, for instance) cannot know in advance which column it lives in.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id A translated or pending post's ID.
	 * @return object|null Row, or null.
	 */
	public static function find_by_translated_id( $post_id ) {
		global $wpdb;

		$table   = self::table();
		$post_id = (int) $post_id;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE translated_post_id = %d OR pending_post_id = %d", // phpcs:ignore WordPress.DB
				$post_id,
				$post_id
			)
		);
	}

	/**
	 * Returns every language row for a source post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_post_id Source post ID.
	 * @return object[] Rows, keyed numerically.
	 */
	public static function for_source( $source_post_id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_post_id = %d ORDER BY language_code ASC", // phpcs:ignore WordPress.DB
				(int) $source_post_id
			)
		);
	}

	/**
	 * Starts (or restarts) a translation job, replacing any earlier attempt
	 * for the same source post and language.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $language_code  Target language code.
	 * @return int Link ID.
	 */
	public static function start( $source_post_id, $language_code ) {
		global $wpdb;

		$existing = self::find( $source_post_id, $language_code );
		$table    = self::table();

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB
				$table,
				array(
					'status'     => self::STATUS_PENDING,
					'error'      => null,
					'created_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return (int) $existing->id;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'source_post_id' => (int) $source_post_id,
				'language_code'  => $language_code,
				'status'         => self::STATUS_PENDING,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Records a finished translation, ready for review.
	 *
	 * Only for a language with no *published* translation yet — a brand new
	 * one, or a regeneration of a draft/failed attempt nothing has approved.
	 * Regenerating an already-published translation goes through
	 * self::mark_pending_review() instead, which never touches this column,
	 * because this is the column that decides what a visitor is served.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $link_id            Link ID from self::start().
	 * @param int    $translated_post_id The draft post that was created.
	 * @param string $provider           Provider key used.
	 * @param string $model              Model name used.
	 */
	public static function mark_ready( $link_id, $translated_post_id, $provider, $model ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'translated_post_id' => (int) $translated_post_id,
				'status'             => self::STATUS_REVIEW,
				'provider'           => $provider,
				'model'              => $model,
				'error'              => null,
			),
			array( 'id' => (int) $link_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Records a finished re-translation of an already-published post.
	 *
	 * Deliberately leaves `translated_post_id` — the post a visitor is
	 * actually served — completely untouched. The new content lives in a
	 * separate post tracked by `pending_post_id` until a reviewer approves
	 * it, at which point self::table()'s caller copies that content into the
	 * live post and discards this one. Without this split, regenerating a
	 * live translation would have nowhere to put the new draft except the
	 * live post itself, taking it offline for however long review takes.
	 *
	 * @since 1.1.1
	 *
	 * @param int    $link_id         Link ID from self::start().
	 * @param int    $pending_post_id The new draft, not yet live.
	 * @param string $provider        Provider key used.
	 * @param string $model           Model name used.
	 */
	public static function mark_pending_review( $link_id, $pending_post_id, $provider, $model ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'pending_post_id' => (int) $pending_post_id,
				'status'          => self::STATUS_REVIEW,
				'provider'        => $provider,
				'model'           => $model,
				'error'           => null,
			),
			array( 'id' => (int) $link_id ),
			array( '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Records a failed translation attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $link_id Link ID from self::start().
	 * @param string $message Error message.
	 */
	public static function mark_failed( $link_id, $message ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status' => self::STATUS_FAILED,
				'error'  => $message,
			),
			array( 'id' => (int) $link_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a translation approved and published.
	 *
	 * If the post approved was a pending regeneration, the caller is
	 * responsible for having already copied its content into
	 * `translated_post_id` and passing that same ID here — this only records
	 * the approval and clears `pending_post_id` back to empty.
	 *
	 * @since 1.0.0
	 *
	 * @param int $link_id            Link ID.
	 * @param int $user_id            Reviewer's user ID.
	 * @param int $translated_post_id The post now live. Omit to leave the
	 *                                existing value unchanged.
	 */
	public static function mark_published( $link_id, $user_id, $translated_post_id = 0 ) {
		global $wpdb;

		$data   = array(
			'status'          => self::STATUS_PUBLISHED,
			'reviewed_at'     => current_time( 'mysql' ),
			'reviewed_by'     => (int) $user_id,
			'pending_post_id' => 0,
		);
		$format = array( '%s', '%s', '%d', '%d' );

		if ( $translated_post_id > 0 ) {
			$data['translated_post_id'] = (int) $translated_post_id;
			$format[]                   = '%d';
		}

		$wpdb->update( self::table(), $data, array( 'id' => (int) $link_id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Reverts a rejected regeneration back to its last published state.
	 *
	 * The live post this link points at was never touched by the rejected
	 * attempt — only `pending_post_id` referenced it — so there is nothing
	 * to undo on the post itself, only the link row's bookkeeping.
	 *
	 * @since 1.1.1
	 *
	 * @param int $link_id Link ID.
	 */
	public static function clear_pending( $link_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'pending_post_id' => 0,
				'status'          => self::STATUS_PUBLISHED,
				'error'           => null,
			),
			array( 'id' => (int) $link_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns links awaiting review, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum rows.
	 * @return object[] Rows.
	 */
	public static function awaiting_review( $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB
				self::STATUS_REVIEW,
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * Counts links awaiting review.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	public static function count_awaiting_review() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB
				self::STATUS_REVIEW
			)
		);
	}

	/**
	 * Counts every eligible source post: published, one of the configured
	 * translatable post types, and not itself a translation.
	 *
	 * The one number every per-language coverage figure is measured against.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $post_types Post types to count.
	 * @return int Count.
	 */
	public static function count_eligible_sources( array $post_types ) {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type IN ( {$placeholders} )
				   AND p.post_status = 'publish'
				   AND m.post_id IS NULL",
				array_merge( array( Beaver_Translate_Translator::META_SOURCE_ID ), $post_types )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Counts eligible source posts in one language, broken down by status.
	 *
	 * `not_started` is derived rather than queried directly — every eligible
	 * source post either has exactly one link row for this language (the
	 * table's own unique key guarantees that) or none at all, so it is
	 * always the total less every status that does have a row.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $language_code Language code.
	 * @param string[] $post_types    Post types to count.
	 * @return array {
	 *     @type int $total        Every eligible source post.
	 *     @type int $published    Live in this language.
	 *     @type int $needs_review Draft or pending regeneration, awaiting a reviewer.
	 *     @type int $pending      An AI call is currently in flight for these.
	 *     @type int $failed       The last attempt errored.
	 *     @type int $not_started  No attempt has ever been made.
	 * }
	 */
	public static function coverage( $language_code, array $post_types ) {
		global $wpdb;

		$out = array(
			'total'        => self::count_eligible_sources( $post_types ),
			'published'    => 0,
			'needs_review' => 0,
			'pending'      => 0,
			'failed'       => 0,
			'not_started'  => 0,
		);

		if ( 0 === $out['total'] || empty( $post_types ) ) {
			return $out;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$links_table  = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.status, COUNT(*) AS total
				 FROM {$wpdb->posts} p
				 INNER JOIN {$links_table} l ON l.source_post_id = p.ID AND l.language_code = %s
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type IN ( {$placeholders} )
				   AND p.post_status = 'publish'
				   AND m.post_id IS NULL
				 GROUP BY l.status",
				array_merge( array( $language_code, Beaver_Translate_Translator::META_SOURCE_ID ), $post_types )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$accounted_for = 0;

		foreach ( $rows as $row ) {
			// The STATUS_* constants' own values already match these array
			// keys (STATUS_REVIEW is literally the string 'needs_review'),
			// so the status column can be used as the key directly.
			if ( array_key_exists( $row->status, $out ) ) {
				$out[ $row->status ] = (int) $row->total;
				$accounted_for      += (int) $row->total;
			}
		}

		$out['not_started'] = max( 0, $out['total'] - $accounted_for );

		return $out;
	}

	/**
	 * Finds eligible source posts with no attempt yet, or a failed one, in
	 * one language — exactly what a "Generate remaining" action should act
	 * on. Deliberately excludes needs_review, published and pending: those
	 * already have something in flight or waiting on a human, and re-queuing
	 * them here would either waste an API call or interrupt a live run.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $language_code Language code.
	 * @param string[] $post_types    Post types to search.
	 * @param int      $limit         Maximum IDs to return.
	 * @return int[] Source post IDs.
	 */
	public static function queue_ids( $language_code, array $post_types, $limit = 500 ) {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$links_table  = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 LEFT JOIN {$links_table} l ON l.source_post_id = p.ID AND l.language_code = %s
				 WHERE p.post_type IN ( {$placeholders} )
				   AND p.post_status = 'publish'
				   AND m.post_id IS NULL
				   AND ( l.id IS NULL OR l.status = %s )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				array_merge(
					array( Beaver_Translate_Translator::META_SOURCE_ID, $language_code ),
					$post_types,
					array( self::STATUS_FAILED, max( 1, (int) $limit ) )
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', $ids );
	}

	/**
	 * Deletes a link row.
	 *
	 * Does not touch the translated post itself — that is a normal
	 * WordPress post and is removed the normal way (trash, then delete),
	 * which already fires the hook that cleans up its link row.
	 *
	 * @since 1.0.0
	 *
	 * @param int $link_id Link ID.
	 */
	public static function delete( $link_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'id' => (int) $link_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Deletes the link row for a translated post, when that post is deleted.
	 *
	 * Also trashes a lingering pending post, if this row had a regeneration
	 * in progress — the live post the pending draft was written against is
	 * gone, so there is nothing left for that draft to be approved into.
	 *
	 * @since 1.0.0
	 *
	 * @param int $translated_post_id Deleted post ID.
	 */
	public static function delete_by_translated_id( $translated_post_id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE translated_post_id = %d", (int) $translated_post_id ) ); // phpcs:ignore WordPress.DB

		if ( $row && $row->pending_post_id ) {
			wp_trash_post( (int) $row->pending_post_id );
		}

		$wpdb->delete( $table, array( 'translated_post_id' => (int) $translated_post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Recovers a link row when its pending post is deleted directly —
	 * emptied from the trash, say — rather than through the normal Reject
	 * action.
	 *
	 * @since 1.1.1
	 *
	 * @param int $pending_post_id Deleted post ID.
	 */
	public static function clear_pending_by_post_id( $pending_post_id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'pending_post_id' => 0,
				'status'          => self::STATUS_PUBLISHED,
				'error'           => null,
			),
			array( 'pending_post_id' => (int) $pending_post_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Deletes every link row for a source post, when that post is deleted.
	 *
	 * Translated posts it links to are left alone — deleting the English
	 * original is not a reason to also delete the French translation, which
	 * may be the only copy a French visitor ever reads.
	 *
	 * @since 1.0.0
	 *
	 * @param int $source_post_id Deleted post ID.
	 */
	public static function delete_by_source_id( $source_post_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'source_post_id' => (int) $source_post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}
}
