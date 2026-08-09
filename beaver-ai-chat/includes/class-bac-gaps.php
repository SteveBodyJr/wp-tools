<?php
/**
 * The questions your site does not answer.
 *
 * The assistant is told to say plainly when it is unsure rather than invent an
 * answer. Every time it does that, a visitor asked something the site does not
 * cover — and that is the single most useful thing a chat assistant can tell
 * you, because it is a content plan written by real demand instead of guesswork.
 *
 * Those moments are picked out by the same background call that already writes
 * each conversation's summary, so they cost nothing extra. They are grouped by
 * what was actually being asked, counted, and listed newest and most frequent
 * first. Answering one writes the answer into the assistant's team notes, so
 * the gap closes for the next visitor who asks.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Gaps
 */
class BAC_Gaps {

	/** Meta holding the unanswered questions found in one conversation. */
	const META = '_bac_unanswered';

	/** Option holding the signatures of questions already dealt with. */
	const CLOSED = 'bac_gaps_closed';

	/** Cached report. */
	const CACHE = 'bac_gaps_report';

	/** Admin page slug. */
	const PAGE = 'beaver-ai-chat-gaps';

	/** How many conversations to read when building the report. */
	const SCAN = 500;

	/** Wire up hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_bac_gap_answer', array( __CLASS__, 'handle_answer' ) );
		add_action( 'admin_post_bac_gap_close', array( __CLASS__, 'handle_close' ) );
		add_action( 'admin_post_bac_gap_reopen', array( __CLASS__, 'handle_reopen' ) );
	}

	/** The report screen, under the plugin's own menu. */
	public static function menu() {
		add_submenu_page(
			BAC_Admin::PAGE,
			__( 'Answer gaps', 'beaver-ai-chat' ),
			__( 'Answer gaps', 'beaver-ai-chat' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/* -------------------------------------------------------------- Recording */

	/**
	 * Store the unanswered questions found in one conversation.
	 *
	 * Replaces rather than appends: the extraction call reads the whole
	 * conversation each time, so its list is the current truth. Appending would
	 * count the same question again on every refresh.
	 *
	 * @param int   $lead_id   Lead post ID.
	 * @param array $questions Questions the assistant could not answer.
	 */
	public static function store( $lead_id, $questions ) {
		$clean = array();

		foreach ( (array) $questions as $question ) {
			if ( ! is_scalar( $question ) ) {
				continue;
			}

			$text = sanitize_text_field( (string) $question );
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

			if ( mb_strlen( $text ) < 6 || mb_strlen( $text ) > 300 ) {
				continue; // Neither a fragment nor a pasted essay is a question.
			}

			$clean[] = $text;

			if ( count( $clean ) >= 5 ) {
				break; // One conversation cannot reasonably raise more than this.
			}
		}

		if ( empty( $clean ) ) {
			delete_post_meta( $lead_id, self::META );
		} else {
			update_post_meta( $lead_id, self::META, wp_json_encode( array_values( array_unique( $clean ) ) ) );
		}

		delete_transient( self::CACHE );
	}

	/**
	 * A signature that groups the same question asked different ways.
	 *
	 * Built from the significant words only, sorted, so "do you have wifi" and
	 * "is there wifi in the rooms" land together. Short questions with nothing
	 * significant left fall back to their own text.
	 *
	 * @param string $question Question.
	 * @return string
	 */
	public static function signature( $question ) {
		$terms = BAC_Knowledge::terms( $question );

		if ( count( $terms ) < 2 ) {
			return mb_strtolower( trim( preg_replace( '/[^\p{L}\p{N}\s]+/u', '', (string) $question ) ) );
		}

		sort( $terms );

		return implode( ' ', array_slice( $terms, 0, 6 ) );
	}

	/* ---------------------------------------------------------------- Report */

	/**
	 * Every gap, grouped and counted.
	 *
	 * @param bool $include_closed Include the ones already dealt with.
	 * @return array
	 */
	public static function report( $include_closed = false ) {
		$cached = get_transient( self::CACHE );

		if ( ! is_array( $cached ) ) {
			$cached = self::build();
			set_transient( self::CACHE, $cached, HOUR_IN_SECONDS );
		}

		$closed = self::closed();
		$out    = array();

		foreach ( $cached as $signature => $gap ) {
			$gap['closed'] = isset( $closed[ $signature ] );

			if ( $gap['closed'] && ! $include_closed ) {
				continue;
			}

			$out[ $signature ] = $gap;
		}

		return $out;
	}

	/**
	 * Read the conversations and group what they asked.
	 *
	 * @return array
	 */
	private static function build() {
		$ids = get_posts(
			array(
				'post_type'      => BAC_LEAD_CPT,
				'post_status'    => 'any',
				'posts_per_page' => self::SCAN,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$gaps = array();

		foreach ( $ids as $id ) {
			$questions = json_decode( (string) get_post_meta( $id, self::META, true ), true );

			if ( ! is_array( $questions ) ) {
				continue;
			}

			$when = (int) get_post_modified_time( 'U', true, $id );

			foreach ( $questions as $question ) {
				$signature = self::signature( $question );

				if ( '' === $signature ) {
					continue;
				}

				if ( ! isset( $gaps[ $signature ] ) ) {
					$gaps[ $signature ] = array(
						'question' => $question, // The most recent phrasing wins.
						'asked'    => 0,
						'last'     => $when,
						'leads'    => array(),
					);
				}

				$gaps[ $signature ]['asked']++;

				if ( count( $gaps[ $signature ]['leads'] ) < 8 ) {
					$gaps[ $signature ]['leads'][] = (int) $id;
				}
			}
		}

		// Most asked first, then most recent: what to write about next.
		uasort(
			$gaps,
			static function ( $a, $b ) {
				if ( $a['asked'] === $b['asked'] ) {
					return $b['last'] - $a['last'];
				}
				return $b['asked'] - $a['asked'];
			}
		);

		return $gaps;
	}

	/**
	 * Signatures already answered or dismissed.
	 *
	 * @return array
	 */
	private static function closed() {
		$closed = get_option( self::CLOSED, array() );

		return is_array( $closed ) ? $closed : array();
	}

	/**
	 * Mark a signature as dealt with, or not.
	 *
	 * @param string $signature Signature.
	 * @param bool   $closed    Whether it is dealt with.
	 */
	private static function set_closed( $signature, $closed ) {
		$all = self::closed();

		if ( $closed ) {
			$all[ $signature ] = current_time( 'mysql' );
		} else {
			unset( $all[ $signature ] );
		}

		// Keep the option bounded on a busy site.
		if ( count( $all ) > 500 ) {
			$all = array_slice( $all, -500, null, true );
		}

		update_option( self::CLOSED, $all, false );
	}

	/* ----------------------------------------------------------------- Screen */

	/** Render the report. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$show_closed = isset( $_GET['closed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view toggle.
		$gaps        = self::report( $show_closed );
		$summary     = BAC_Settings::get( 'lead_ai_summary' );
		?>
		<div class="wrap bac-admin">
			<h1 class="bac-title">
				<span class="dashicons dashicons-editor-help"></span>
				<?php esc_html_e( 'Answer gaps', 'beaver-ai-chat' ); ?>
			</h1>

			<p class="description bac-lede">
				<?php esc_html_e( 'Questions visitors asked that the assistant could not answer from your site. Every one of these is a person who wanted something and did not get it, and a page or a note that would fix it for the next person who asks.', 'beaver-ai-chat' ); ?>
			</p>

			<?php settings_errors( 'bac_gaps' ); ?>

			<?php if ( empty( $summary ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'This report is filled in by the same background call that writes conversation summaries, and summaries are switched off. Turn on "Use the AI to name each lead and write a short summary" on the Leads tab to start collecting gaps.', 'beaver-ai-chat' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" <?php echo $show_closed ? '' : 'class="current"'; ?>>
					<?php esc_html_e( 'Open', 'beaver-ai-chat' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&closed=1' ) ); ?>" <?php echo $show_closed ? 'class="current"' : ''; ?>>
					<?php esc_html_e( 'Everything, including answered', 'beaver-ai-chat' ); ?></a></li>
			</ul>

			<?php if ( empty( $gaps ) ) : ?>
				<div class="bac-gap-empty">
					<p><strong><?php esc_html_e( 'Nothing here yet.', 'beaver-ai-chat' ); ?></strong></p>
					<p class="description">
						<?php esc_html_e( 'Either the assistant has been answering everything, or there have not been enough conversations yet. Come back after a week of chats.', 'beaver-ai-chat' ); ?>
					</p>
				</div>
			<?php else : ?>
				<table class="widefat striped bac-gaps">
					<thead>
						<tr>
							<th><?php esc_html_e( 'What they asked', 'beaver-ai-chat' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Times', 'beaver-ai-chat' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Last asked', 'beaver-ai-chat' ); ?></th>
							<th style="width:340px;"><?php esc_html_e( 'Answer it', 'beaver-ai-chat' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $gaps as $signature => $gap ) : ?>
							<tr<?php echo $gap['closed'] ? ' class="bac-gap-closed"' : ''; ?>>
								<td>
									<strong><?php echo esc_html( $gap['question'] ); ?></strong>
									<?php if ( $gap['closed'] ) : ?>
										<span class="bac-gap-tag"><?php esc_html_e( 'answered', 'beaver-ai-chat' ); ?></span>
									<?php endif; ?>
									<div class="row-actions">
										<?php foreach ( $gap['leads'] as $i => $lead_id ) : ?>
											<span>
												<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' ) ); ?>">
													<?php
													/* translators: %d: number of the conversation in a short list. */
													printf( esc_html__( 'conversation %d', 'beaver-ai-chat' ), (int) $i + 1 );
													?>
												</a>
												<?php echo ( $i < count( $gap['leads'] ) - 1 ) ? ' | ' : ''; ?>
											</span>
										<?php endforeach; ?>
									</div>
								</td>
								<td><?php echo esc_html( number_format_i18n( $gap['asked'] ) ); ?></td>
								<td>
									<?php
									/* translators: %s: human readable time difference, for example "2 days". */
									echo esc_html( sprintf( __( '%s ago', 'beaver-ai-chat' ), human_time_diff( $gap['last'] ) ) );
									?>
								</td>
								<td>
									<?php if ( $gap['closed'] ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'bac_gap' ); ?>
											<input type="hidden" name="action" value="bac_gap_reopen" />
											<input type="hidden" name="signature" value="<?php echo esc_attr( $signature ); ?>" />
											<?php submit_button( __( 'Put it back', 'beaver-ai-chat' ), 'small', 'submit', false ); ?>
										</form>
									<?php else : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bac-gap-form">
											<?php wp_nonce_field( 'bac_gap' ); ?>
											<input type="hidden" name="action" value="bac_gap_answer" />
											<input type="hidden" name="signature" value="<?php echo esc_attr( $signature ); ?>" />
											<input type="hidden" name="question" value="<?php echo esc_attr( $gap['question'] ); ?>" />
											<label class="screen-reader-text" for="bac-gap-<?php echo esc_attr( md5( $signature ) ); ?>">
												<?php esc_html_e( 'The answer', 'beaver-ai-chat' ); ?>
											</label>
											<textarea name="answer" id="bac-gap-<?php echo esc_attr( md5( $signature ) ); ?>" rows="2" class="large-text"
												placeholder="<?php esc_attr_e( 'Type the answer and the assistant will use it from now on', 'beaver-ai-chat' ); ?>"></textarea>
											<?php submit_button( __( 'Teach it this', 'beaver-ai-chat' ), 'primary small', 'submit', false ); ?>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bac-gap-form">
											<?php wp_nonce_field( 'bac_gap' ); ?>
											<input type="hidden" name="action" value="bac_gap_close" />
											<input type="hidden" name="signature" value="<?php echo esc_attr( $signature ); ?>" />
											<?php submit_button( __( 'Not worth answering', 'beaver-ai-chat' ), 'link-delete small', 'submit', false ); ?>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the Assistant tab. */
						esc_html__( 'Answers are added to the team notes the assistant treats as authoritative. You can edit or remove them any time under %s.', 'beaver-ai-chat' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . BAC_Admin::PAGE . '#assistant' ) ) . '">' . esc_html__( 'Assistant', 'beaver-ai-chat' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Actions */

	/** Write an answer into the assistant's team notes. */
	public static function handle_answer() {
		self::guard();

		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		$question  = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
		$answer    = isset( $_POST['answer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) : '';

		if ( '' === $signature || '' === trim( $answer ) ) {
			self::back( 'gap-empty' );
		}

		$settings = BAC_Settings::get();
		$context  = trim( (string) $settings['context'] );
		$addition = 'Q: ' . $question . "\nA: " . trim( $answer );

		$settings['context'] = '' === $context ? $addition : $context . "\n\n" . $addition;

		update_option( BAC_OPTION, BAC_Settings::sanitize( $settings ) );

		self::set_closed( $signature, true );
		delete_transient( self::CACHE );

		self::back( 'gap-answered' );
	}

	/** Dismiss a gap without answering it. */
	public static function handle_close() {
		self::guard();

		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';

		if ( '' !== $signature ) {
			self::set_closed( $signature, true );
		}

		self::back( 'gap-closed' );
	}

	/** Put a dismissed gap back on the list. */
	public static function handle_reopen() {
		self::guard();

		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';

		if ( '' !== $signature ) {
			self::set_closed( $signature, false );
		}

		self::back( 'gap-reopened' );
	}

	/** Refuse anyone who should not be here. */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-ai-chat' ) );
		}
		check_admin_referer( 'bac_gap' );
	}

	/**
	 * Back to the report with a message.
	 *
	 * @param string $code Notice code.
	 */
	private static function back( $code ) {
		$messages = array(
			'gap-answered' => array( 'success', __( 'Answer saved. The assistant will use it from now on.', 'beaver-ai-chat' ) ),
			'gap-closed'   => array( 'success', __( 'Taken off the list.', 'beaver-ai-chat' ) ),
			'gap-reopened' => array( 'success', __( 'Back on the list.', 'beaver-ai-chat' ) ),
			'gap-empty'    => array( 'error', __( 'Type an answer first, or use "Not worth answering".', 'beaver-ai-chat' ) ),
		);

		$notice = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['gap-empty'];

		add_settings_error( 'bac_gaps', $code, $notice[1], $notice[0] );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&settings-updated=true' ) );
		exit;
	}
}
