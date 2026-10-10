<?php
/**
 * Optional interop with beaver-seo: translated posts keep their SEO fields.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Carries a source post's beaver-seo fields across to its translation,
 * translating the two that are actual prose and copying the rest as-is.
 *
 * Entirely optional and entirely one-directional: nothing here assumes
 * beaver-seo is installed, requires it, or is required by it. Every method
 * checks for `Beaver_SEO_Meta` before doing anything, so a site running
 * beaver-translate alone behaves exactly as if this file did not exist.
 *
 * Without this, a hand-tuned SEO title or description on a source page
 * would silently not exist on its translation — not carried over, not
 * translated, just absent — and beaver-seo would fall back to
 * auto-generating one from the translated post's own title and content
 * instead. That fallback is reasonable on its own, but it throws away
 * deliberate SEO work an editor already did on the original.
 *
 * @since 1.3.0
 */
class Beaver_Translate_SEO_Bridge {

	/**
	 * Registers the hook.
	 *
	 * @since 1.3.0
	 */
	public static function init() {
		add_action( 'beaver_translate_draft_ready', array( __CLASS__, 'sync' ), 10, 3 );
	}

	/**
	 * Copies and translates a source post's beaver-seo fields onto its
	 * freshly generated (or regenerated) translation.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $draft_id       The post that just received new content —
	 *                                a first-time draft, or a pending
	 *                                regeneration of an already-live one.
	 * @param int    $source_post_id The post it was translated from.
	 * @param string $language_code  Target language.
	 */
	public static function sync( $draft_id, $source_post_id, $language_code ) {
		if ( ! class_exists( 'Beaver_SEO_Meta' ) ) {
			return;
		}

		$language = Beaver_Translate_Languages::get( $language_code );

		if ( ! $language ) {
			return;
		}

		self::sync_translatable_field( $draft_id, $source_post_id, Beaver_SEO_Meta::META_TITLE, $language );
		self::sync_translatable_field( $draft_id, $source_post_id, Beaver_SEO_Meta::META_DESCRIPTION, $language );

		// Neither a boolean flag nor an attachment ID needs translating —
		// only carrying over. A noindex'd original almost always means the
		// translation should start the same way, and the social image is
		// the same photograph regardless of language.
		self::copy_field( $draft_id, $source_post_id, Beaver_SEO_Meta::META_NOINDEX );
		self::copy_field( $draft_id, $source_post_id, Beaver_SEO_Meta::META_NOFOLLOW );
		self::copy_field( $draft_id, $source_post_id, Beaver_SEO_Meta::META_IMAGE );

		/*
		 * Canonical is deliberately never copied. It exists for one
		 * purpose — telling search engines this content is also published
		 * somewhere else — and blindly duplicating a source post's
		 * canonical onto its translation would point the French page at
		 * whatever URL the English one names, which is specific to the
		 * English page and almost certainly wrong for the French one.
		 */
	}

	/**
	 * Translates one plain-text SEO field, if the source has one set.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $draft_id       Destination post.
	 * @param int    $source_post_id Source post.
	 * @param string $meta_key       One of the Beaver_SEO_Meta::META_* constants.
	 * @param array  $language       Target language, from Beaver_Translate_Languages::get().
	 */
	private static function sync_translatable_field( $draft_id, $source_post_id, $meta_key, $language ) {
		$value = trim( (string) get_post_meta( $source_post_id, $meta_key, true ) );

		if ( '' === $value ) {
			// Nothing hand-tuned on the source — leave the destination
			// alone too, so beaver-seo's own auto-generated fallback (built
			// from the translated title and content) is what applies,
			// exactly as it would for a page that was never given an
			// override to begin with.
			return;
		}

		$system = sprintf(
			'Translate the text into %s. It may contain %%placeholder%% tokens such as ' .
			'%%sitename%%, %%sep%% or %%title%% — leave every one of those exactly as ' .
			'written, in the same position, since they are replaced automatically later ' .
			'and are not part of the sentence to translate. Reply with the translation ' .
			'only — no preamble, no quotation marks, no explanation.',
			$language['name']
		) . Beaver_Translate_Translator::glossary_instruction();

		$translated = Beaver_Translate_Provider::complete( $system, $value );

		// A failed call here should not fail the whole translation — the
		// content itself already succeeded by the time this runs. Leaving
		// the field unset is the same safe fallback as never having set an
		// override at all.
		if ( is_wp_error( $translated ) || '' === trim( $translated ) ) {
			return;
		}

		update_post_meta( $draft_id, $meta_key, $translated );
	}

	/**
	 * Copies one field verbatim, clearing the destination when the source
	 * does not have it set either.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $draft_id       Destination post.
	 * @param int    $source_post_id Source post.
	 * @param string $meta_key       One of the Beaver_SEO_Meta::META_* constants.
	 */
	private static function copy_field( $draft_id, $source_post_id, $meta_key ) {
		$value = get_post_meta( $source_post_id, $meta_key, true );

		if ( '' === $value || '0' === (string) $value ) {
			delete_post_meta( $draft_id, $meta_key );

			return;
		}

		update_post_meta( $draft_id, $meta_key, $value );
	}
}
