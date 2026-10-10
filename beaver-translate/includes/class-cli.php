<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates translations and manages the review queue from the command line.
 *
 * Every draft this produces still needs approving through wp-admin (or
 * `wp beaver-translate approve`) before it is visible to a site visitor —
 * that rule is enforced in Beaver_Translate_Translator, not here, so there
 * is no `--publish` flag on `generate` to bypass it with.
 *
 * @since 1.0.0
 */
class Beaver_Translate_CLI {

	/**
	 * Generates a draft translation for one post, or every post missing one.
	 *
	 * ## OPTIONS
	 *
	 * [<post_id>]
	 * : A single post to translate. Omit when using --all.
	 *
	 * --language=<code>
	 * : Target language code, already configured in settings.
	 *
	 * [--all]
	 * : Translate every source post of the configured post types that has no
	 * draft or published translation yet in this language.
	 *
	 * [--post-type=<type>]
	 * : Restrict --all to one post type.
	 *
	 * [--force]
	 * : Regenerate even when a draft or published translation already exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-translate generate 42 --language=fr
	 *     wp beaver-translate generate --all --language=fr --post-type=post
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate( $args, $assoc_args ) {
		if ( empty( $assoc_args['language'] ) ) {
			WP_CLI::error( 'Usage: wp beaver-translate generate <post_id> --language=<code> (or --all)' );
		}

		$language = Beaver_Translate_Languages::sanitize_code( $assoc_args['language'] );

		if ( ! Beaver_Translate_Languages::is_valid( $language ) ) {
			WP_CLI::error( "'{$language}' is not a configured language." );
		}

		$force = ! empty( $assoc_args['force'] );

		if ( ! empty( $assoc_args['all'] ) ) {
			$this->generate_all( $language, $force, isset( $assoc_args['post-type'] ) ? $assoc_args['post-type'] : '' );

			return;
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Provide a post ID, or pass --all.' );
		}

		$result = Beaver_Translate_Translator::translate( (int) $args[0], $language, $force );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Draft #{$result} ready for review." );
	}

	/**
	 * Runs self::generate() over every eligible source post.
	 *
	 * @since 1.0.0
	 *
	 * @param string $language Target language code.
	 * @param bool   $force    Regenerate existing drafts too.
	 * @param string $post_type Restrict to one post type, or empty for all configured.
	 */
	private function generate_all( $language, $force, $post_type ) {
		$types = '' !== $post_type
			? array( sanitize_key( $post_type ) )
			: (array) Beaver_Translate_Settings::get( 'translatable_post_types', array() );

		$ids = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => Beaver_Translate_Translator::META_SOURCE_ID,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to translate.' );

			return;
		}

		$progress = WP_CLI\Utils\make_progress_bar( 'Translating', count( $ids ) );
		$done     = 0;
		$failed   = 0;

		foreach ( $ids as $id ) {
			$result = Beaver_Translate_Translator::translate( (int) $id, $language, $force );

			if ( is_wp_error( $result ) ) {
				++$failed;
				WP_CLI::warning( "#{$id}: " . $result->get_error_message() );
			} else {
				++$done;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( "{$done} translated, {$failed} failed." );
	}

	/**
	 * Lists drafts waiting for review.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-translate queue
	 *
	 * @since 1.0.0
	 */
	public function queue() {
		$rows = array_map(
			static function ( $row ) {
				// A pending regeneration of an already-live translation is
				// waiting for review at pending_post_id — translated_post_id
				// on that same row is the unrelated, still-live post.
				$reviewing_id = $row->pending_post_id ? $row->pending_post_id : $row->translated_post_id;

				return array(
					'id'        => $row->id,
					'source'    => get_the_title( $row->source_post_id ),
					'language'  => $row->language_code,
					'review_id' => $reviewing_id,
					'is_regen'  => $row->pending_post_id ? 'yes' : 'no',
				);
			},
			Beaver_Translate_Links::awaiting_review( 200 )
		);

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Nothing is waiting for review.' );

			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'source', 'language', 'review_id', 'is_regen' ) );
	}

	/**
	 * Approves and publishes a draft translation.
	 *
	 * ## OPTIONS
	 *
	 * --id=<id>
	 * : Link ID, from `wp beaver-translate queue`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-translate approve --id=7
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function approve( $args, $assoc_args ) {
		unset( $args );

		if ( empty( $assoc_args['id'] ) ) {
			WP_CLI::error( 'Usage: wp beaver-translate approve --id=<id>' );
		}

		global $wpdb;

		$table = Beaver_Translate_Links::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $assoc_args['id'] ) ); // phpcs:ignore WordPress.DB

		if ( ! $row || ( ! $row->translated_post_id && ! $row->pending_post_id ) ) {
			WP_CLI::error( 'No such review-queue entry.' );
		}

		$result = Beaver_Translate_Translator::approve( $row, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Post #{$result} published." );
	}

	/**
	 * Translates taxonomy term names into a language.
	 *
	 * A term stays one row, one slug, one URL — this only writes a
	 * translated display name into termmeta, shown in place of the real one
	 * on a request under that language's prefix. See Beaver_Translate_Terms
	 * for why that is enough and nothing about routing changes.
	 *
	 * ## OPTIONS
	 *
	 * --language=<code>
	 * : Target language code, already configured in settings.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Restrict to one taxonomy. Every public taxonomy when omitted.
	 *
	 * [--force]
	 * : Re-translate terms that already have a stored translation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-translate terms --language=fr
	 *     wp beaver-translate terms --language=fr --taxonomy=tour_category
	 *
	 * @since 1.5.0
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function terms( $args, $assoc_args ) {
		unset( $args );

		if ( empty( $assoc_args['language'] ) ) {
			WP_CLI::error( 'Usage: wp beaver-translate terms --language=<code> [--taxonomy=<taxonomy>]' );
		}

		$language = Beaver_Translate_Languages::sanitize_code( $assoc_args['language'] );

		if ( ! Beaver_Translate_Languages::is_valid( $language ) ) {
			WP_CLI::error( "'{$language}' is not a configured language." );
		}

		$force = ! empty( $assoc_args['force'] );

		$taxonomies = ! empty( $assoc_args['taxonomy'] )
			? array( sanitize_key( $assoc_args['taxonomy'] ) )
			: Beaver_Translate_Terms::translatable_taxonomies();

		$total = 0;

		foreach ( $taxonomies as $taxonomy ) {
			$result = Beaver_Translate_Terms::translate_taxonomy( $taxonomy, $language, $force );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "{$taxonomy}: " . $result->get_error_message() );

				continue;
			}

			if ( $result > 0 ) {
				WP_CLI::log( "{$taxonomy}: {$result} term name(s) translated." );
			}

			$total += $result;
		}

		WP_CLI::success( "{$total} term name(s) translated across " . count( $taxonomies ) . ' taxonomy(ies).' );
	}
}
