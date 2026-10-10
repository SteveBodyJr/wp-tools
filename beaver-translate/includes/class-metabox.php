<?php
/**
 * The "Translations" panel on the post editor.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows, on a source post, one row per configured language with its status
 * and an action; on a translated draft, a small notice pointing back at the
 * original and an approve action.
 *
 * @since 1.0.0
 */
class Beaver_Translate_Metabox {

	const NONCE_ACTION = 'beaver_translate_action';

	/**
	 * Registers the hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );

		add_action( 'wp_ajax_beaver_translate_generate', array( __CLASS__, 'ajax_generate' ) );
		add_action( 'wp_ajax_beaver_translate_approve', array( __CLASS__, 'ajax_approve' ) );
	}

	/**
	 * Registers the meta box on every translatable post type.
	 *
	 * @since 1.0.0
	 */
	public static function add_box() {
		$types = (array) Beaver_Translate_Settings::get( 'translatable_post_types', array() );

		foreach ( $types as $post_type ) {
			add_meta_box(
				'beaver-translate',
				__( 'Translations', 'beaver-translate' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the box.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render( $post ) {
		$source_id = (int) get_post_meta( $post->ID, Beaver_Translate_Translator::META_SOURCE_ID, true );

		if ( $source_id > 0 ) {
			self::render_translation_notice( $post, $source_id );

			return;
		}

		self::render_language_list( $post );
	}

	/**
	 * Renders the panel shown on a source post: one row per language.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Source post.
	 */
	private static function render_language_list( $post ) {
		$targets = Beaver_Translate_Languages::targets();

		if ( empty( $targets ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No other languages are configured yet.', 'beaver-translate' )
			);

			return;
		}

		if ( '' === Beaver_Translate_Settings::api_key() ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No AI provider is configured yet.', 'beaver-translate' )
			);

			return;
		}

		$links = array();

		foreach ( Beaver_Translate_Links::for_source( $post->ID ) as $row ) {
			$links[ $row->language_code ] = $row;
		}

		echo '<ul class="beaver-translate-list">';

		foreach ( $targets as $language ) {
			$row = isset( $links[ $language['code'] ] ) ? $links[ $language['code'] ] : null;

			self::render_row( $post->ID, $language, $row );
		}

		echo '</ul>';
	}

	/**
	 * Renders one language's row.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $post_id  Source post ID.
	 * @param array       $language Language from Beaver_Translate_Languages::get().
	 * @param object|null $row      Link row, or null when nothing has been generated yet.
	 */
	private static function render_row( $post_id, $language, $row ) {
		$status = $row ? $row->status : '';

		echo '<li class="beaver-translate-row" data-beaver-translate-post="' . (int) $post_id . '" data-beaver-translate-language="' . esc_attr( $language['code'] ) . '">';

		printf( '<span class="beaver-translate-row__label">%s</span>', esc_html( Beaver_Translate_Languages::label( $language['code'] ) ) );

		echo '<span class="beaver-translate-row__status" data-beaver-translate-status>';
		self::render_status_badge( $status );
		echo '</span>';

		echo '<span class="beaver-translate-row__actions" data-beaver-translate-actions>';
		self::render_actions( $row, $status );
		echo '</span>';

		echo '</li>';
	}

	/**
	 * Renders a status badge.
	 *
	 * @since 1.0.0
	 *
	 * @param string $status One of Beaver_Translate_Links::STATUS_*, or empty.
	 */
	private static function render_status_badge( $status ) {
		$labels = array(
			''                                   => __( 'Not translated', 'beaver-translate' ),
			Beaver_Translate_Links::STATUS_PENDING   => __( 'Generating…', 'beaver-translate' ),
			Beaver_Translate_Links::STATUS_REVIEW    => __( 'Needs review', 'beaver-translate' ),
			Beaver_Translate_Links::STATUS_PUBLISHED => __( 'Published', 'beaver-translate' ),
			Beaver_Translate_Links::STATUS_FAILED    => __( 'Failed', 'beaver-translate' ),
		);

		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

		printf(
			'<span class="beaver-translate-badge beaver-translate-badge--%s">%s</span>',
			esc_attr( '' === $status ? 'none' : $status ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the action(s) available for a language row.
	 *
	 * @since 1.0.0
	 *
	 * @param object|null $row    Link row, or null.
	 * @param string      $status Current status.
	 */
	private static function render_actions( $row, $status ) {
		if ( in_array( $status, array( '', Beaver_Translate_Links::STATUS_FAILED ), true ) ) {
			printf(
				'<button type="button" class="button button-small" data-beaver-translate-generate>%s</button>',
				esc_html__( 'Generate', 'beaver-translate' )
			);

			if ( Beaver_Translate_Links::STATUS_FAILED === $status && $row && ! empty( $row->error ) ) {
				printf( '<p class="beaver-translate-error">%s</p>', esc_html( $row->error ) );
			}

			return;
		}

		if ( Beaver_Translate_Links::STATUS_PENDING === $status ) {
			echo '<span class="spinner is-active" style="float:none;"></span>';

			return;
		}

		if ( ! $row || ( ! $row->translated_post_id && ! $row->pending_post_id ) ) {
			return;
		}

		// A pending regeneration of an already-live translation edits the
		// *pending* post — the live one is untouched until this is approved.
		// A first-time, still-unpublished draft has no pending post at all;
		// translated_post_id is itself the thing to edit.
		$editing_id  = $row->pending_post_id ? (int) $row->pending_post_id : (int) $row->translated_post_id;
		$edit_link   = get_edit_post_link( $editing_id, '' );
		$edit_label  = $row->pending_post_id
			? __( 'Edit pending', 'beaver-translate' )
			: __( 'Edit draft', 'beaver-translate' );

		if ( $edit_link ) {
			printf(
				'<a class="button button-small" href="%s">%s</a> ',
				esc_url( $edit_link ),
				esc_html( $edit_label )
			);
		}

		if ( $row->translated_post_id && 'publish' === get_post_status( $row->translated_post_id ) ) {
			$view_link = get_permalink( $row->translated_post_id );

			if ( $view_link ) {
				printf(
					'<a class="button button-small" href="%s" target="_blank" rel="noopener noreferrer">%s</a> ',
					esc_url( $view_link ),
					esc_html__( 'View live', 'beaver-translate' )
				);
			}
		}

		printf(
			'<button type="button" class="button button-small" data-beaver-translate-generate>%s</button>',
			esc_html__( 'Regenerate', 'beaver-translate' )
		);

		if ( Beaver_Translate_Links::STATUS_REVIEW === $status ) {
			$approve_label = $row->pending_post_id
				? __( 'Approve & update live', 'beaver-translate' )
				: __( 'Approve & publish', 'beaver-translate' );

			printf(
				' <button type="button" class="button button-small button-primary" data-beaver-translate-approve>%s</button>',
				esc_html( $approve_label )
			);
		}
	}

	/**
	 * Renders the notice shown on a translated draft's own edit screen.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post      The translated post.
	 * @param int     $source_id The post it was translated from.
	 */
	private static function render_translation_notice( $post, $source_id ) {
		$language_code = get_post_meta( $post->ID, Beaver_Translate_Translator::META_LANGUAGE, true );
		$source_title  = get_the_title( $source_id );
		$source_link   = get_edit_post_link( $source_id, '' );
		$link          = Beaver_Translate_Links::find_by_translated_id( $post->ID );

		printf(
			'<p>%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: source post title, 2: target language name. */
					__( 'AI-generated draft translation of %1$s into %2$s.', 'beaver-translate' ),
					'<strong>' . esc_html( $source_title ) . '</strong>',
					esc_html( Beaver_Translate_Languages::label( (string) $language_code ) )
				),
				array( 'strong' => array() )
			)
		);

		if ( $source_link ) {
			printf( '<p><a href="%s">%s</a></p>', esc_url( $source_link ), esc_html__( 'View original', 'beaver-translate' ) );
		}

		if ( $link && Beaver_Translate_Links::STATUS_REVIEW === $link->status ) {
			printf(
				'<p><button type="button" class="button button-primary" data-beaver-translate-approve data-beaver-translate-post="%d" data-beaver-translate-language="%s">%s</button></p>',
				(int) $link->source_post_id,
				esc_attr( $link->language_code ),
				esc_html__( 'Approve & publish', 'beaver-translate' )
			);
		} elseif ( $link && Beaver_Translate_Links::STATUS_PUBLISHED === $link->status ) {
			printf( '<p><em>%s</em></p>', esc_html__( 'Published.', 'beaver-translate' ) );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * AJAX
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Generates (or regenerates) one language's translation.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_generate() {
		self::verify_request();

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? Beaver_Translate_Languages::sanitize_code( wp_unslash( $_POST['language'] ) ) : '';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'beaver-translate' ) ), 403 );
		}

		$result = Beaver_Translate_Translator::translate( $post_id, $language, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$row = Beaver_Translate_Links::find( $post_id, $language );

		wp_send_json_success(
			array(
				'status' => $row ? $row->status : '',
				'html'   => self::row_html( $post_id, $language, $row ),
			)
		);
	}

	/**
	 * Approves and publishes a draft or pending-regeneration translation.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_approve() {
		self::verify_request();

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$language = isset( $_POST['language'] ) ? Beaver_Translate_Languages::sanitize_code( wp_unslash( $_POST['language'] ) ) : '';

		$row = Beaver_Translate_Links::find( $post_id, $language );

		if ( ! $row || ( ! $row->translated_post_id && ! $row->pending_post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No draft to approve.', 'beaver-translate' ) ) );
		}

		$target_id = $row->pending_post_id ? (int) $row->pending_post_id : (int) $row->translated_post_id;

		if ( ! current_user_can( 'publish_posts' ) || ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'beaver-translate' ) ), 403 );
		}

		$result = Beaver_Translate_Translator::approve( $row, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$row = Beaver_Translate_Links::find( $post_id, $language );

		wp_send_json_success(
			array(
				'status' => $row->status,
				'html'   => self::row_html( $post_id, $language, $row ),
			)
		);
	}

	/**
	 * Verifies capability and nonce for every AJAX request.
	 *
	 * @since 1.0.0
	 */
	private static function verify_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Renders one row's markup for an AJAX response to swap in.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $post_id  Source post ID.
	 * @param string      $language Language code.
	 * @param object|null $row      Link row.
	 * @return string HTML.
	 */
	private static function row_html( $post_id, $language, $row ) {
		$language_data = Beaver_Translate_Languages::get( $language );

		if ( ! $language_data ) {
			return '';
		}

		ob_start();
		self::render_row( $post_id, $language_data, $row );

		return ob_get_clean();
	}
}
