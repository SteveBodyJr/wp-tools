<?php
/**
 * Orchestrates one post's translation: prompts, the provider call, and the
 * draft it produces.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a source post into a draft translation, always left for a human to
 * review before it can go live.
 *
 * Nothing here ever sets a translated post's status to 'publish'. That line
 * does not exist in this class on purpose — approval is a deliberate,
 * separate action a signed-in reviewer takes, never a side effect of an AI
 * call succeeding.
 *
 * The same guarantee runs the other way: nothing here ever sets an already-
 * published translation's status to 'draft' either. Regenerating one writes
 * the new content into a separate, not-yet-live post instead — see
 * Beaver_Translate_Links::mark_pending_review() — so clicking Regenerate on
 * a live page can never be the reason it goes offline.
 *
 * @since 1.0.0
 */
class Beaver_Translate_Translator {

	const META_SOURCE_ID = '_beaver_translate_source_id';
	const META_LANGUAGE  = '_beaver_translate_language';

	/**
	 * Translates a post into a target language.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $source_post_id Post being translated.
	 * @param string $language_code  Target language, already configured in settings.
	 * @param bool   $force          Regenerate even when a draft already exists.
	 * @return int|WP_Error Draft post ID, or the failure.
	 */
	public static function translate( $source_post_id, $language_code, $force = false ) {
		$source = get_post( (int) $source_post_id );

		if ( ! $source instanceof WP_Post ) {
			return new WP_Error( 'beaver_translate_no_source', __( 'The source post could not be found.', 'beaver-translate' ) );
		}

		if ( ! Beaver_Translate_Languages::is_valid( $language_code ) ) {
			return new WP_Error( 'beaver_translate_bad_language', __( 'That language is not configured.', 'beaver-translate' ) );
		}

		$existing = Beaver_Translate_Links::find( $source->ID, $language_code );

		if ( $existing && ! $force && in_array( $existing->status, array( Beaver_Translate_Links::STATUS_REVIEW, Beaver_Translate_Links::STATUS_PUBLISHED ), true ) ) {
			return (int) $existing->translated_post_id;
		}

		/*
		 * Whether the live post — the one a visitor is actually served — is
		 * already published decides where the new content is allowed to go.
		 * A first-time translation, or a regeneration of something that
		 * never made it past review, can be written straight into
		 * translated_post_id: nothing is live yet, so there is nothing to
		 * protect. Regenerating something already published cannot touch
		 * that post directly — doing so would mean setting it to draft the
		 * moment Regenerate is clicked, taking a live page offline for
		 * however long review takes. That version writes into a separate
		 * pending post instead, reusing one left over from an earlier
		 * incomplete regeneration rather than piling up abandoned drafts.
		 */
		$live_post_id      = $existing ? (int) $existing->translated_post_id : 0;
		$regenerating_live = $live_post_id > 0 && 'publish' === get_post_status( $live_post_id );

		$reuse_post_id = $regenerating_live
			? ( $existing ? (int) $existing->pending_post_id : 0 )
			: $live_post_id;

		$link_id = Beaver_Translate_Links::start( $source->ID, $language_code );

		$language = Beaver_Translate_Languages::get( $language_code );
		$settings = Beaver_Translate_Settings::all();

		$title = self::translate_field(
			self::system_prompt_plain( $language ),
			$source->post_title,
			$settings
		);

		if ( is_wp_error( $title ) ) {
			Beaver_Translate_Links::mark_failed( $link_id, $title->get_error_message() );

			return $title;
		}

		$excerpt = '';

		if ( '' !== trim( $source->post_excerpt ) ) {
			$excerpt = self::translate_field( self::system_prompt_plain( $language ), $source->post_excerpt, $settings );

			if ( is_wp_error( $excerpt ) ) {
				Beaver_Translate_Links::mark_failed( $link_id, $excerpt->get_error_message() );

				return $excerpt;
			}
		}

		$content = self::translate_field(
			self::system_prompt_content( $language ),
			$source->post_content,
			$settings
		);

		if ( is_wp_error( $content ) ) {
			Beaver_Translate_Links::mark_failed( $link_id, $content->get_error_message() );

			return $content;
		}

		$post_data = array(
			'post_type'    => $source->post_type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_author'  => $source->post_author,
		);

		// The linked draft should always still exist — on_delete_post() clears
		// the link the moment either side is deleted — but falling back to a
		// fresh insert here costs nothing and avoids a WP_Error from
		// wp_update_post() if that cleanup was ever bypassed (a direct
		// database delete, for instance).
		if ( $reuse_post_id > 0 && false !== get_post_type( $reuse_post_id ) ) {
			$post_data['ID'] = $reuse_post_id;
			$result           = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			Beaver_Translate_Links::mark_failed( $link_id, $result->get_error_message() );

			return $result;
		}

		$draft_id = (int) $result;

		update_post_meta( $draft_id, self::META_SOURCE_ID, $source->ID );
		update_post_meta( $draft_id, self::META_LANGUAGE, $language_code );

		$thumbnail_id = get_post_thumbnail_id( $source->ID );

		if ( $thumbnail_id ) {
			set_post_thumbnail( $draft_id, $thumbnail_id );
		}

		self::copy_structured_fields( $source->ID, $draft_id );

		if ( ! empty( $settings['translate_custom_fields'] ) ) {
			self::translate_custom_fields( $source->ID, $draft_id, $language, $settings );
		}

		$cfg = Beaver_Translate_Settings::provider_config( $settings );

		if ( $regenerating_live ) {
			Beaver_Translate_Links::mark_pending_review( $link_id, $draft_id, $cfg['id'], $cfg['model'] );
		} else {
			Beaver_Translate_Links::mark_ready( $link_id, $draft_id, $cfg['id'], $cfg['model'] );
		}

		/**
		 * Fires once a draft translation is ready for review.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $draft_id       The new or updated draft post.
		 * @param int    $source_post_id The post it was translated from.
		 * @param string $language_code  Target language.
		 */
		do_action( 'beaver_translate_draft_ready', $draft_id, $source->ID, $language_code );

		return $draft_id;
	}

	/**
	 * Meta keys never copied between posts, either because they are
	 * specific to one post's own identity and edit history, or because this
	 * class already handles them itself through its own dedicated logic.
	 *
	 * @since 1.3.2
	 *
	 * @var string[]
	 */
	const RESERVED_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_thumbnail_id', // Copied separately, via set_post_thumbnail().
	);

	/**
	 * Carries every taxonomy term and custom field from a source post onto
	 * its translation.
	 *
	 * Title, excerpt and content are the only fields this class translates;
	 * everything else a post carries — an ACF field, a custom taxonomy like
	 * an accommodation's level or location, a gallery of attachment IDs — is
	 * structured data, not prose, and copying it verbatim is what makes it
	 * present at all. The alternative was not "the translation is in
	 * English" for these, it was empty: nothing in this class ever touched
	 * them, so a translated post used to carry none of the same
	 * taxonomy terms or custom fields the source page relied on to render
	 * at all — a "Good to know" box quietly missing its Level and Location.
	 *
	 * Values are copied as-is, not translated. Some — a short text field, a
	 * name — probably contain prose that ideally would be. This class has
	 * no way to tell those apart from a numeric field, a term ID reference,
	 * or an ACF field-key pointer without knowing a specific site's own
	 * field structure, and a field showing the *right* value in the wrong
	 * language is a strictly smaller problem than a field showing nothing.
	 *
	 * @since 1.3.2
	 *
	 * @param int $source_id      Source post ID.
	 * @param int $destination_id Post to copy onto — the new draft, or the
	 *                            pending post being generated.
	 */
	private static function copy_structured_fields( $source_id, $destination_id ) {
		$post_type = get_post_type( $source_id );

		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $term_ids ) ) {
				continue;
			}

			wp_set_object_terms( $destination_id, $term_ids, $taxonomy );
		}

		$all_meta = get_post_meta( $source_id );

		foreach ( $all_meta as $meta_key => $values ) {
			if ( in_array( $meta_key, self::RESERVED_META_KEYS, true ) ) {
				continue;
			}

			if ( 0 === strpos( $meta_key, self::META_SOURCE_ID ) || 0 === strpos( $meta_key, self::META_LANGUAGE ) ) {
				continue;
			}

			/*
			 * beaver-seo's own fields are deliberately excluded from a
			 * verbatim copy, even though this method runs before
			 * Beaver_Translate_SEO_Bridge gets a chance to translate them —
			 * that ordering would still leave the right value in place, but
			 * only by coincidence of which line happens to run last.
			 * Skipping them here says so outright instead of relying on it.
			 */
			if ( 0 === strpos( $meta_key, '_beaver_seo_' ) ) {
				continue;
			}

			delete_post_meta( $destination_id, $meta_key );

			foreach ( $values as $value ) {
				// get_post_meta() without a single-key argument returns every
				// value already serialized to a string; writing that string
				// straight back in would double-serialize it.
				add_post_meta( $destination_id, $meta_key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Translates the subset of a source post's custom field values that are
	 * actually prose, once copy_structured_fields() has already copied
	 * everything verbatim.
	 *
	 * That verbatim copy is deliberately the floor, not the ceiling: a
	 * safari package's itinerary is built almost entirely out of ACF fields
	 * and repeaters — a day's title and description, an "included" or
	 * "excluded" bullet, an FAQ's question and answer — and none of that is
	 * structural the way a term ID or an image reference is. Left as a
	 * verbatim copy, a French package page renders every one of those in
	 * English, which is a smaller failure than rendering blank but still a
	 * real one on the content a safari site is actually built around.
	 *
	 * Every candidate field is sent in a single call — one JSON object of
	 * key => text in, the same keys with translated values back — rather
	 * than one API call per field. A package with a handful of itinerary
	 * days and FAQs can easily have thirty or more translatable fields;
	 * calling out to a model that many times over would turn one
	 * translation into a slow, unnecessarily expensive sequence of round
	 * trips for something a single request already does.
	 *
	 * Deliberately opt-in (Settings > "Also translate custom field text"):
	 * unlike title/excerpt/content, which every translation already
	 * includes, this is real additional scope — not every site has custom
	 * fields worth the extra call, and the ones that do should choose that
	 * cost rather than have it appear on their next bill unannounced.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $source_id      Source post ID.
	 * @param int    $destination_id Draft or pending post already carrying a
	 *                                verbatim copy of the source's meta.
	 * @param array  $language       Target language, from Beaver_Translate_Languages::get().
	 * @param array  $settings       Plugin settings.
	 */
	private static function translate_custom_fields( $source_id, $destination_id, $language, $settings ) {
		$all_meta  = get_post_meta( $source_id );
		$candidates = array();

		foreach ( $all_meta as $meta_key => $values ) {
			if ( ! isset( $values[0] ) ) {
				continue;
			}

			$value = maybe_unserialize( $values[0] );

			if ( self::is_translatable_meta_value( $meta_key, $value ) ) {
				$candidates[ $meta_key ] = $value;
			}
		}

		if ( empty( $candidates ) ) {
			return;
		}

		$system = sprintf(
			'You will receive a JSON object. Each value is a short piece of text from a website, unrelated to the others except that they come from the same page. Translate every value into %s and reply with a JSON object using the exact same keys, each mapped to its translation. Do not translate, rename, add or remove any key. Reply with the JSON object only — no preamble, no explanation, no code fence.',
			$language['name']
		) . self::glossary_instruction();

		$payload = wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE );

		/*
		 * Settings' own max_tokens is tuned for one field at a time — a
		 * title, a paragraph of content — not for every prose field on a
		 * post at once. A package with a dozen itinerary days, each
		 * contributing several fields, can need far more room in a single
		 * reply than that; too little and the response is cut off mid-JSON,
		 * which fails json_decode() below and, correctly, discards the
		 * whole batch through the fail-soft path rather than risk applying
		 * a truncated translation — but a post that large deserves the
		 * budget to actually succeed, not just to fail safely. Scaled to
		 * the size of what is actually being sent, never below a floor
		 * generous enough for a typical multi-day package, and never past
		 * the same ceiling Settings itself enforces.
		 */
		$batch_settings                 = $settings;
		$batch_settings['max_tokens']   = min( 32000, max( (int) $settings['max_tokens'], 16000, (int) ( strlen( $payload ) / 2 ) ) );

		$response = Beaver_Translate_Provider::complete( $system, $payload, $batch_settings );

		// Fail soft: if the call errors, or the model replies with something
		// that is not the JSON object asked for, every field this method
		// would have touched is simply left as the verbatim copy
		// copy_structured_fields() already wrote — the source-language
		// value a moment ago, not an empty one.
		if ( is_wp_error( $response ) ) {
			return;
		}

		$translated = json_decode( trim( (string) $response ), true );

		if ( ! is_array( $translated ) ) {
			return;
		}

		foreach ( $candidates as $meta_key => $original ) {
			if ( ! isset( $translated[ $meta_key ] ) || ! is_string( $translated[ $meta_key ] ) ) {
				continue;
			}

			$new_value = trim( $translated[ $meta_key ] );

			if ( '' === $new_value ) {
				continue;
			}

			update_post_meta( $destination_id, $meta_key, $new_value );
		}
	}

	/**
	 * Whether a meta value looks like translatable prose rather than
	 * structural data.
	 *
	 * Deliberately conservative, and deliberately generic: this plugin has
	 * no way to know one site's field-naming convention from another's, so
	 * it never matches on a specific field name, only on the shape of the
	 * value and a small set of key suffixes ("_id", "_url"...) common
	 * enough across ACF and similar field plugins to be a safe signal
	 * anywhere. A single word without a space is skipped even when it
	 * would read as English — a location name, a short label — is
	 * indistinguishable at this point from a slug or a code, and leaving it
	 * exactly as the source post already had it (via the verbatim copy
	 * this runs after) is the safer of the two ways to be wrong.
	 *
	 * @since 1.4.0
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value, already run through maybe_unserialize().
	 * @return bool
	 */
	private static function is_translatable_meta_value( $key, $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = trim( $value );

		if ( '' === $value || is_numeric( $value ) ) {
			return false;
		}

		// Serialized arrays/objects are structure (an ACF "meals" checkbox
		// group, a link field's array shape) — never touched here even
		// though maybe_unserialize() above would already have unpacked a
		// plain one; this catches a value serialized a second time over.
		if ( preg_match( '/^[aOs]:\d+:/', $value ) ) {
			return false;
		}

		if ( preg_match( '#^https?://#i', $value ) ) {
			return false;
		}

		// ACF's own field-key reference meta ("field_...") — a pointer,
		// never prose, and always paired with the real value under the
		// field's own name, which copy_structured_fields() already
		// carried across verbatim and this method may still translate.
		if ( preg_match( '/^field_[a-z0-9_]+$/i', $value ) ) {
			return false;
		}

		// A single word, with no space at all, is left exactly as the
		// verbatim copy already has it -- see the note above.
		if ( ! preg_match( '/\s/', $value ) ) {
			return false;
		}

		$excluded_suffixes = array( '_id', '_ids', '_post', '_page', '_image', '_img', '_link', '_url', '_count', '_price', '_number', '_key' );

		foreach ( $excluded_suffixes as $suffix ) {
			if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Approves a link row, whichever of the two shapes it is in.
	 *
	 * A first-time translation has nothing live yet: the draft in
	 * `translated_post_id` is simply published in place. A regenerated
	 * translation has both — a live post the site is already serving, and a
	 * pending post holding the new content — and approving it means copying
	 * the pending content into the live post and discarding the pending one,
	 * so the translation's URL never changes, only what is served there.
	 *
	 * The single entry point both Beaver_Translate_Metabox::ajax_approve()
	 * and Beaver_Translate_CLI::approve() call, so the two shapes only ever
	 * have to be handled correctly once.
	 *
	 * @since 1.1.1
	 *
	 * @param object $row     Link row from the database.
	 * @param int    $user_id Reviewer's user ID.
	 * @return int|WP_Error The now-live post ID, or the failure.
	 */
	public static function approve( $row, $user_id ) {
		$pending_id = (int) $row->pending_post_id;
		$live_id    = (int) $row->translated_post_id;

		if ( $pending_id > 0 && $live_id > 0 ) {
			$pending = get_post( $pending_id );

			if ( ! $pending instanceof WP_Post ) {
				return new WP_Error( 'beaver_translate_no_pending', __( 'The pending draft could not be found.', 'beaver-translate' ) );
			}

			$updated = wp_update_post(
				array(
					'ID'           => $live_id,
					'post_title'   => $pending->post_title,
					'post_content' => $pending->post_content,
					'post_excerpt' => $pending->post_excerpt,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$thumbnail_id = get_post_thumbnail_id( $pending_id );

			if ( $thumbnail_id ) {
				set_post_thumbnail( $live_id, $thumbnail_id );
			}

			// The pending post already carries whatever taxonomy terms and
			// custom fields the source had at the moment it was generated —
			// carry those into the live post too, the same way its content
			// just was, rather than leaving the live post's copy stale.
			self::copy_structured_fields( $pending_id, $live_id );

			// beaver-seo's fields, if that plugin is active, were synced onto
			// the pending post when it was generated — carry them the rest
			// of the way into the live post along with everything else,
			// rather than leaving a regenerated page with an SEO title still
			// describing whatever it said before this update.
			if ( class_exists( 'Beaver_SEO_Meta' ) ) {
				foreach ( array(
					Beaver_SEO_Meta::META_TITLE,
					Beaver_SEO_Meta::META_DESCRIPTION,
					Beaver_SEO_Meta::META_NOINDEX,
					Beaver_SEO_Meta::META_NOFOLLOW,
					Beaver_SEO_Meta::META_IMAGE,
				) as $seo_meta_key ) {
					$value = get_post_meta( $pending_id, $seo_meta_key, true );

					if ( '' === $value ) {
						delete_post_meta( $live_id, $seo_meta_key );
					} else {
						update_post_meta( $live_id, $seo_meta_key, $value );
					}
				}
			}

			// Trashed, not force-deleted — the same recoverable margin every
			// other rejection or cleanup in this plugin leaves.
			wp_trash_post( $pending_id );

			Beaver_Translate_Links::mark_published( $row->id, $user_id, $live_id );

			return $live_id;
		}

		if ( $live_id <= 0 ) {
			return new WP_Error( 'beaver_translate_no_draft', __( 'No draft to approve.', 'beaver-translate' ) );
		}

		$updated = wp_update_post( array( 'ID' => $live_id, 'post_status' => 'publish' ), true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		Beaver_Translate_Links::mark_published( $row->id, $user_id );

		return $live_id;
	}

	/**
	 * Rejects a link row, discarding whichever post was actually pending.
	 *
	 * The two shapes from self::approve() apply here too, and getting this
	 * one wrong is the more dangerous direction: rejecting a regeneration of
	 * an already-live translation must trash the *pending* post, never
	 * `translated_post_id` — that is the live page a visitor is being served
	 * right now, and it was never touched by the rejected attempt.
	 *
	 * @since 1.1.1
	 *
	 * @param object $row Link row from the database.
	 */
	public static function reject( $row ) {
		if ( $row->pending_post_id ) {
			wp_trash_post( (int) $row->pending_post_id );
			Beaver_Translate_Links::clear_pending( $row->id );

			return;
		}

		// Nothing here was ever live: the draft itself, and the link
		// tracking it, are both discarded outright.
		if ( $row->translated_post_id ) {
			wp_trash_post( (int) $row->translated_post_id );
		}

		Beaver_Translate_Links::delete( $row->id );
	}

	/**
	 * Catches a translated post being published some way other than this
	 * plugin's own Approve action, and brings the link table back in sync.
	 *
	 * A draft this plugin created is, underneath, a completely normal
	 * WordPress post — nothing about it stops an editor from opening it and
	 * clicking WordPress's own Publish button directly, the same as any
	 * other draft. That is not a misuse worth blocking; disabling the core
	 * Publish button would be a strange, fragile thing to do to a normal
	 * post. But it does mean the link row never got told, which is worse
	 * than it sounds: `for_source()`-based lookups such as the language
	 * switcher fail to notice the translation exists at all, and — the
	 * genuinely dangerous part — a link stuck at `needs_review` is still
	 * listed in the review queue, where clicking Reject would trash a post
	 * that is, in fact, already live.
	 *
	 * Hooked to `transition_post_status`, which fires regardless of what
	 * caused the change — the block editor's Publish button, Quick Edit, a
	 * bulk action, the REST API — so this closes the gap completely rather
	 * than for only the one path that was actually observed.
	 *
	 * @since 1.3.1
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post transitioning.
	 */
	public static function sync_status_on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$source_id = (int) get_post_meta( $post->ID, self::META_SOURCE_ID, true );

		if ( $source_id <= 0 ) {
			return;
		}

		$language_code = (string) get_post_meta( $post->ID, self::META_LANGUAGE, true );
		$row           = Beaver_Translate_Links::find( $source_id, $language_code );

		if ( ! $row || Beaver_Translate_Links::STATUS_PUBLISHED === $row->status ) {
			return;
		}

		if ( (int) $row->pending_post_id === $post->ID ) {
			// The pending post itself was published directly, which can
			// leave two published posts for the same language sitting side
			// by side for a moment — the live one, and this one. Routing
			// through the real approve() logic resolves that the same way
			// clicking Approve would: its content is merged into the live
			// post, and this one is trashed, regardless of what status it
			// is currently sitting at when that runs.
			self::approve( $row, get_current_user_id() );

			return;
		}

		if ( (int) $row->translated_post_id === $post->ID ) {
			Beaver_Translate_Links::mark_published( $row->id, get_current_user_id() );
		}
	}

	/**
	 * Calls the provider for one field, with light retry-free error passthrough.
	 *
	 * @since 1.0.0
	 *
	 * @param string $system  System prompt.
	 * @param string $text    Text to translate.
	 * @param array  $settings Plugin settings.
	 * @return string|WP_Error
	 */
	private static function translate_field( $system, $text, $settings ) {
		if ( '' === trim( (string) $text ) ) {
			return '';
		}

		return Beaver_Translate_Provider::complete( $system, $text, $settings );
	}

	/**
	 * System prompt for a short plain-text field: a title or an excerpt.
	 *
	 * @since 1.0.0
	 *
	 * @param array $language Target language, from Beaver_Translate_Languages::get().
	 * @return string Prompt.
	 */
	private static function system_prompt_plain( $language ) {
		return sprintf(
			"Translate the text the user sends into %s. Reply with the translation only — no preamble, no quotation marks, no explanation, nothing before or after it. Preserve the tone and register of the original.",
			$language['name']
		) . self::glossary_instruction();
	}

	/**
	 * System prompt for post content: HTML and, usually, Gutenberg block
	 * comments that must survive the round trip untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param array $language Target language, from Beaver_Translate_Languages::get().
	 * @return string Prompt.
	 */
	private static function system_prompt_content( $language ) {
		return sprintf(
			"Translate the WordPress post content the user sends into %s. " .
			'The content is HTML, and may include WordPress block comments ' .
			'like <!-- wp:paragraph --> and <!-- /wp:paragraph -->. ' .
			'Preserve every HTML tag, attribute and block comment exactly as ' .
			'given, in the same positions, and translate only the human-' .
			'readable text between them. Do not translate URLs, shortcode ' .
			'names, class names or HTML attribute values. Reply with the ' .
			'translated content only — no preamble, no explanation, no code ' .
			'fence around it.',
			$language['name']
		) . self::glossary_instruction();
	}

	/**
	 * The glossary clause appended to every translation prompt this plugin
	 * sends, including beaver-seo's field translations.
	 *
	 * Some words are correct precisely because they are left alone: a place
	 * name, a property name, a brand term. A general-purpose translation
	 * instruction has no way to know that on its own — a model asked to
	 * translate "Serengeti" will often do exactly that, plausibly and
	 * completely wrongly. Combines three sources: the site's own name,
	 * protected automatically, with nothing to configure — a company is
	 * never expected to translate its own name just because a page about it
	 * did; the free-text terms typed into settings; and — resolved fresh
	 * here, never stored — the title of every published post in whichever
	 * post types Settings marks as "protect these titles". A destination's
	 * or an accommodation's name is protected as a whole category with one
	 * tick, however many a site actually has, rather than needing a
	 * checkbox maintained by hand for each property and going stale the
	 * moment a new one is added.
	 *
	 * Cached for the request: this can be built several times over a single
	 * translation — the title, the excerpt, the content, and up to two more
	 * for beaver-seo's own fields — and the underlying data has no reason
	 * to change in the middle of it.
	 *
	 * @since 1.3.0
	 *
	 * @return string Instruction clause, or an empty string when there is nothing to protect.
	 */
	public static function glossary_instruction() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$terms = array_values( array_unique( array_merge(
			self::glossary_site_name(),
			Beaver_Translate_Settings::glossary_terms(),
			self::glossary_post_type_titles()
		) ) );

		if ( empty( $terms ) ) {
			$cached = '';

			return $cached;
		}

		$cached = ' The following terms must be left exactly as written, in the original language, wherever they appear — never translated, never transliterated: ' . implode( ', ', $terms ) . '.';

		return $cached;
	}

	/**
	 * The site's own name, protected by default.
	 *
	 * Not a setting, not a checkbox — WordPress already has this in Settings
	 * > General, and a company's own name is never a case-by-case choice the
	 * way a place name or an industry term can be. Skipped only for the
	 * handful of sites that never changed WordPress's own placeholder title.
	 *
	 * @since 1.4.0
	 *
	 * @return string[] Zero or one term.
	 */
	private static function glossary_site_name() {
		$name = trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		if ( '' === $name || 'My WordPress Site' === $name ) {
			return array();
		}

		return array( $name );
	}

	/**
	 * Titles of every published post in a glossary-protected post type.
	 *
	 * Excludes a post's own translations from the query — a French
	 * accommodation post is itself of post type "accommodation", and would
	 * otherwise glossary-protect its own already-translated name, which is
	 * at best redundant and at worst confusing when generating a third
	 * language from the same source.
	 *
	 * Capped well short of anything that would meaningfully bloat a prompt.
	 * A site with more protected titles than that has a bigger problem with
	 * this approach than a capped list solves here.
	 *
	 * @since 1.3.2
	 *
	 * @return string[] Titles.
	 */
	private static function glossary_post_type_titles() {
		global $wpdb;

		$post_types = (array) Beaver_Translate_Settings::get( 'glossary_post_types', array() );

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
		$titles = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.post_title FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type IN ( {$placeholders} )
				   AND p.post_status = 'publish'
				   AND p.post_title != ''
				   AND m.post_id IS NULL
				 ORDER BY p.ID ASC
				 LIMIT 300",
				array_merge( array( self::META_SOURCE_ID ), $post_types )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values( array_filter( array_map( 'trim', (array) $titles ) ) );
	}
}
