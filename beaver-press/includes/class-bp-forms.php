<?php
/**
 * Forms in the visitor's language: the messages after sending, and the confirmation email.
 *
 * att-core's forms post to admin-post.php, save the enquiry, email the operator (stays in the
 * original language), may email the visitor a confirmation, and send the visitor back to the
 * page with ?att_form=...&att_status=... where a message is shown. Nothing of that is changed
 * here (form handling stays att-core's); Beaver Press only:
 * - translates the messages: "Prepare forms" opens every form page, as the signed run, with
 *   each status and error reason in each language, so TranslatePress stores their translation
 *   (visitors never start translations, and these texts only appear after a submission);
 * - shows a page with a form result in the visitor's language even if one text is missing
 *   (never the original page instead, see BP_Complete);
 * - writes the visitor's confirmation email in their language: the texts (Theme Settings or
 *   att-core's defaults) are translated once in "Prepare forms" and given to att-core through
 *   ACF's load_value filter during a submission from a translated page; {name} and {site} stay
 *   for att-core to fill in. Without a stored translation the email stays as it was.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form messages and the visitor's confirmation email.
 */
final class BP_Forms {

	/** Option: language => [ md5(original) => translation ] (not autoloaded). */
	const OPTION = 'beaver_press_form_texts';

	/** Email fields of Theme Settings and att-core's default for each. */
	const EMAIL_FIELDS = array(
		'inquiry_autoreply_subject'     => 'Thank you for contacting {site}',
		'inquiry_autoreply_heading'     => 'Thank you, {name}',
		'inquiry_autoreply_message'     => "We've received your message and our team will be in touch very soon.\n\nWe look forward to helping you plan your journey.",
		'inquiry_autoreply_button_text' => '',
	);

	/** Error reasons att-core can send back. */
	const REASONS = array( 'invalid_date', 'invalid_email', 'invalid_form', 'invalid_message', 'invalid_nonce', 'missing_name', 'missing_phone', 'recaptcha_low_score', 'save_failed' );

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array_keys( self::EMAIL_FIELDS ) as $field ) {
			add_filter( 'acf/load_value/name=' . $field, array( __CLASS__, 'email_text' ), 20, 3 );
		}
		add_action( 'wp_ajax_bp_forms_prepare', array( __CLASS__, 'ajax_prepare' ) );
	}

	/**
	 * Whether this request shows a form result (?att_form=...&att_status=...).
	 *
	 * @return bool
	 */
	public static function is_result_page() {
		return isset( $_GET['att_form'], $_GET['att_status'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
	}

	/* ------------------------------------------------------------------ */
	/* Confirmation email                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Language of a form submission from a translated page ('' for the original language).
	 *
	 * @return string
	 */
	public static function submission_language() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- att-core checks the form; this only reads the language.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( empty( $_POST['att_form_type'] ) && ( $_POST['action'] ?? '' ) !== 'att_submit_inquiry' ) ) {
			return '';
		}
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$slug     = sanitize_key( wp_unslash( $_POST['trp-form-language'] ?? '' ) );
		if ( '' === $slug && ! empty( $_POST['_wp_http_referer'] ) ) {
			$path = trim( (string) wp_parse_url( esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ), PHP_URL_PATH ), '/' );
			$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			$rest = '' !== $home && 0 === strpos( $path, $home ) ? trim( substr( $path, strlen( $home ) ), '/' ) : $path;
			$slug = strtok( $rest, '/' );
		}
		// phpcs:enable
		foreach ( (array) $settings['url-slugs'] as $code => $url_slug ) {
			if ( (string) $url_slug === (string) $slug && $code !== $settings['default-language'] && isset( BP_Run::languages()[ $code ] ) ) {
				return (string) $code;
			}
		}
		return '';
	}

	/**
	 * ACF gives att-core an email text: during a submission from a translated page, the
	 * stored translation (att-core then fills in {name} and {site}).
	 *
	 * @param mixed      $value   Saved value.
	 * @param int|string $post_id ACF post id ('option' / 'options').
	 * @param array      $field   Field.
	 * @return mixed
	 */
	public static function email_text( $value, $post_id, $field ) {
		if ( ! is_string( $post_id ) || 0 !== strpos( $post_id, 'option' ) ) {
			return $value;
		}
		$language = self::submission_language();
		if ( '' === $language ) {
			return $value;
		}
		$name     = (string) ( $field['name'] ?? '' );
		$original = '' !== trim( (string) $value ) ? (string) $value : (string) ( self::EMAIL_FIELDS[ $name ] ?? '' );
		$stored   = self::stored( $language, $original );
		return '' !== $stored ? $stored : $value;
	}

	/**
	 * Stored translation of an email text.
	 *
	 * @param string $language Language.
	 * @param string $text     Original.
	 * @return string
	 */
	public static function stored( $language, $text ) {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? (string) ( $all[ $language ][ md5( $text ) ] ?? '' ) : '';
	}

	/**
	 * Email texts to translate: Theme Settings values, or att-core's defaults.
	 *
	 * @return string[]
	 */
	public static function email_texts() {
		$out = array();
		foreach ( self::EMAIL_FIELDS as $field => $default ) {
			// Read without our filter (it only acts during a submission anyway).
			$value = function_exists( 'get_field' ) ? trim( (string) get_field( $field, 'option', false ) ) : '';
			$text  = '' !== $value ? $value : $default;
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Translate the email texts for languages with the engine (trusted requests only).
	 *
	 * @param string[] $languages Languages.
	 * @return int Texts translated.
	 */
	public static function translate_emails( array $languages ) {
		if ( '' !== BP_Run::blocker() ) {
			return 0;
		}
		require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$engine   = new BP_AI_Machine_Translator( $settings );
		$all      = get_option( self::OPTION, array() );
		$all      = is_array( $all ) ? $all : array();
		$texts    = self::email_texts();
		$done     = 0;
		foreach ( $languages as $language ) {
			$todo = array();
			foreach ( $texts as $i => $text ) {
				if ( empty( $all[ $language ][ md5( $text ) ] ) ) {
					$todo[ 't' . $i ] = $text;
				}
			}
			if ( ! $todo ) {
				continue;
			}
			$result = $engine->translate_array( $todo, $language, $settings['default-language'] );
			foreach ( $todo as $k => $text ) {
				if ( ! empty( $result[ $k ] ) ) {
					$all[ $language ][ md5( $text ) ] = (string) $result[ $k ];
					$done++;
				}
			}
		}
		update_option( self::OPTION, $all, false );
		return $done;
	}

	/* ------------------------------------------------------------------ */
	/* Messages after sending                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Pages with a form result to open once per language: form pages x statuses.
	 *
	 * @return string[] Addresses in the original language.
	 */
	public static function result_urls() {
		$pages = array( home_url( '/' ) );
		foreach ( array( 'inquire', 'contact', 'kilimanjaro' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				$pages[] = get_permalink( $page );
			}
		}
		// One tour: the enquiry form (and its messages) is the same on every tour.
		$tour = get_posts(
			array(
				'post_type'      => 'safari_package',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $tour ) {
			$pages[] = get_permalink( $tour[0] );
		}
		$variants = array();
		foreach ( array( 'inquire', 'contact' ) as $form ) {
			$variants[] = array( 'att_form' => $form, 'att_status' => 'success' );
			$variants[] = array( 'att_form' => $form, 'att_status' => 'error' );
			foreach ( self::REASONS as $reason ) {
				$variants[] = array( 'att_form' => $form, 'att_status' => 'error', 'att_reason' => $reason );
			}
		}
		$urls = array();
		foreach ( array_unique( array_filter( $pages ) ) as $page ) {
			foreach ( $variants as $args ) {
				$urls[] = add_query_arg( $args, $page );
			}
		}
		// Newsletter (footer, same on every page).
		foreach ( array( 'success', 'error' ) as $status ) {
			$urls[] = add_query_arg( array( 'att_form' => 'newsletter', 'att_status' => $status ), home_url( '/' ) );
		}
		return (array) apply_filters( 'beaver_press_form_result_urls', $urls );
	}

	/**
	 * Prepare forms for languages, a slice at a time (the admin page and WP-CLI call again).
	 *
	 * @param string[] $languages Languages.
	 * @param int      $offset    Where to continue.
	 * @param int      $seconds   Time for this call.
	 * @return array{offset: int, total: int, emails: int}
	 */
	public static function prepare( array $languages, $offset = 0, $seconds = 20 ) {
		$emails = 0 === (int) $offset ? self::translate_emails( $languages ) : 0;
		$pairs  = array();
		foreach ( self::result_urls() as $url ) {
			foreach ( $languages as $language ) {
				$pairs[] = BP_Run::url_in( $url, $language );
			}
		}
		$started = microtime( true );
		while ( $offset < count( $pairs ) && ( microtime( true ) - $started ) < $seconds ) {
			BP_Run::fetch( $pairs[ $offset ] );
			$offset++;
		}
		return array(
			'offset' => (int) $offset,
			'total'  => count( $pairs ),
			'emails' => (int) $emails,
		);
	}

	/**
	 * Admin: one slice of "Prepare forms".
	 */
	public static function ajax_prepare() {
		check_ajax_referer( 'bp_run', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
		$blocker = BP_Run::blocker();
		if ( '' !== $blocker ) {
			wp_send_json_error( array( 'message' => $blocker ) );
		}
		$languages = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['languages'] ?? array() ) ), array_keys( BP_Run::languages() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		if ( ! $languages ) {
			$languages = array_keys( BP_Run::languages() );
		}
		wp_send_json_success( self::prepare( $languages, absint( $_POST['offset'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
	}

	/**
	 * Card on the Translate site tab.
	 */
	public static function render() {
		$all   = get_option( self::OPTION, array() );
		$ready = array();
		foreach ( BP_Run::languages() as $code => $name ) {
			$ok = true;
			foreach ( self::email_texts() as $text ) {
				$ok = $ok && ! empty( $all[ $code ][ md5( $text ) ] );
			}
			if ( $ok ) {
				$ready[] = $name;
			}
		}
		?>
		<div class="bp-card" id="bp-forms">
			<h2><?php esc_html_e( 'Forms in the visitor\'s language', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The messages shown after sending a form, and the confirmation email to the visitor (Theme Settings, when switched on), in the language of the page the form was sent from. Emails to you stay in the original language. Messages only appear after a submission, so they are translated here once: every form page is opened with each result in each language. Run it again after changing form texts.', 'beaver-press' ); ?></p>
			<p>
				<button type="button" class="button bp-forms__go" <?php disabled( '' !== BP_Run::blocker() ); ?>><?php esc_html_e( 'Prepare forms', 'beaver-press' ); ?></button>
				<span class="bp-forms__msg" aria-live="polite">
					<?php
					echo esc_html(
						$ready
							/* translators: %s: languages */
							? sprintf( __( 'Confirmation email ready in: %s.', 'beaver-press' ), implode( ', ', $ready ) )
							: __( 'Not prepared yet.', 'beaver-press' )
					);
					?>
				</span>
			</p>
		</div>
		<?php
	}
}
