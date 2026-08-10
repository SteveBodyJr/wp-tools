<?php
/**
 * Admin screens: one tabbed settings page, a connection tester, and settings
 * export / import so a working configuration can be carried to another site.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Admin
 */
class BAC_Admin {

	const PAGE = 'beaver-ai-chat';

	/** id of the out-of-band form the usage reset button posts to. */
	const RESET_FORM = 'bac-usage-reset';

	/** Wire up hooks. */
	public static function init() {
		// Priority 9 so the top level menu exists before WordPress attaches the
		// Chat Leads post type to it, which keeps Settings as the first item.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_bac_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_bac_test_email', array( __CLASS__, 'ajax_test_email' ) );
		add_action( 'admin_post_bac_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_bac_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_bac_usage_reset', array( __CLASS__, 'handle_usage_reset' ) );
	}

	/** Menu entries. The lead post type attaches itself to this menu. */
	public static function menu() {
		add_menu_page(
			__( 'Beaver AI Chat', 'beaver-ai-chat' ),
			__( 'AI Chat', 'beaver-ai-chat' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'beaver-ai-chat' ),
			__( 'Settings', 'beaver-ai-chat' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/** Register the option with its sanitiser. */
	public static function register() {
		register_setting(
			'bac_settings_group',
			BAC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BAC_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Load admin assets on this plugin's screens only.
	 *
	 * @param string $hook Current screen hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_style( 'bac-admin', BAC_URL . 'assets/css/admin.css', array(), BAC_VERSION );
		wp_enqueue_script( 'bac-admin', BAC_URL . 'assets/js/admin.js', array(), BAC_VERSION, true );

		wp_localize_script(
			'bac-admin',
			'BAC_ADMIN',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'bac_test' ),
				'emailNonce' => wp_create_nonce( 'bac_test_email' ),
				'providers'  => self::provider_hints(),
				'i18n'       => array(
					'testing' => __( 'Testing…', 'beaver-ai-chat' ),
					'ok'      => __( 'Connected. The model replied:', 'beaver-ai-chat' ),
					'failed'  => __( 'Failed', 'beaver-ai-chat' ),
					'network' => __( 'Network error', 'beaver-ai-chat' ),
					'sending' => __( 'Sending…', 'beaver-ai-chat' ),
				),
			)
		);
	}

	/**
	 * Per provider guidance shown live as the admin switches provider.
	 *
	 * @return array
	 */
	private static function provider_hints() {
		$hints = array();

		foreach ( BAC_Settings::providers() as $id => $p ) {
			$hints[ $id ] = array(
				'keysAt' => $p['keys_at'],
				'prefix' => $p['prefix'],
				'model'  => $p['model'],
				'alt'    => $p['alt'],
				'custom' => ( 'custom' === $id ) ? 1 : 0,
				'claude' => ( 'anthropic' === $p['api'] ) ? 1 : 0,
				'temp'   => ( 'anthropic' === $p['api'] ) ? 0 : 1,
			);
		}

		return $hints;
	}

	/** Render the settings page. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s        = BAC_Settings::get();
		$has_key  = '' !== BAC_Settings::api_key();
		$constant = BAC_Settings::key_is_constant();

		$tabs = array(
			'connection' => __( 'Connection', 'beaver-ai-chat' ),
			'assistant'  => __( 'Assistant', 'beaver-ai-chat' ),
			'knowledge'  => __( 'Knowledge', 'beaver-ai-chat' ),
			'leads'      => __( 'Leads', 'beaver-ai-chat' ),
			'appearance' => __( 'Appearance', 'beaver-ai-chat' ),
			'tools'      => __( 'Tools', 'beaver-ai-chat' ),
		);
		?>
		<div class="wrap bac-admin">
			<h1 class="bac-title">
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'Beaver AI Chat', 'beaver-ai-chat' ); ?>
			</h1>

			<p class="description bac-lede">
				<?php esc_html_e( 'A 24/7 assistant that answers visitor questions using your own site content, captures leads and hands off to your team. Your API key is stored on your server and is never exposed to visitors: the chat only ever talks to this website.', 'beaver-ai-chat' ); ?>
			</p>

			<?php settings_errors(); ?>

			<h2 class="nav-tab-wrapper bac-tabs">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab" data-bac-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php" class="bac-form">
				<?php settings_fields( 'bac_settings_group' ); ?>

				<?php
				self::tab_connection( $s, $has_key, $constant );
				self::tab_assistant( $s );
				self::tab_knowledge( $s );
				self::tab_leads( $s );
				self::tab_appearance( $s );
				?>

				<?php submit_button( __( 'Save settings', 'beaver-ai-chat' ) ); ?>
			</form>

			<?php
			// Outside the settings form on purpose: a form inside a form ends the
			// outer one. See spend_section().
			self::reset_form();
			?>

			<?php self::tab_tools(); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ Tabs */

	/**
	 * Connection tab.
	 *
	 * @param array $s        Settings.
	 * @param bool  $has_key  Whether a key is available.
	 * @param bool  $constant Whether the key comes from wp-config.php.
	 */
	private static function tab_connection( $s, $has_key, $constant ) {
		?>
		<div class="bac-panel" data-bac-panel="connection">
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Live chat', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'enabled', $s, __( 'Show the assistant on the site', 'beaver-ai-chat' ), true ); ?>
						<p class="description"><?php esc_html_e( 'Switch this off at any time to instantly hide the chat.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-provider"><?php esc_html_e( 'AI provider', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( BAC_OPTION ); ?>[provider]" id="bac-provider">
							<?php foreach ( BAC_Settings::providers() as $id => $p ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['provider'], $id ); ?>>
									<?php echo esc_html( $p['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="bac-provider-hint"></p>
					</td>
				</tr>

				<tr class="bac-row-custom">
					<th scope="row"><label for="bac-custom-endpoint"><?php esc_html_e( 'Endpoint URL', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'custom_endpoint', $s, 'https://your-host/v1/chat/completions', 'large-text' ); ?>
						<p class="description"><?php esc_html_e( 'Any endpoint that speaks the OpenAI chat/completions format, including self-hosted models.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-api-key"><?php esc_html_e( 'API key', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php if ( $constant ) : ?>
							<p class="bac-ok">
								<span class="dashicons dashicons-lock"></span>
								<?php esc_html_e( 'The key is set in wp-config.php, which overrides this field.', 'beaver-ai-chat' ); ?>
							</p>
						<?php elseif ( $has_key ) : ?>
							<?php
							/*
							 * A key is already saved, so the input starts DISABLED and stays
							 * out of the form until the admin deliberately asks to change it.
							 * Browsers and password managers autofill password inputs whatever
							 * autocomplete says; a disabled field is neither autofilled nor
							 * submitted, so saving any other setting can no longer overwrite a
							 * working key with an autofilled password.
							 */
							?>
							<div class="bac-key" data-bac-key-view>
								<span class="bac-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'A key is saved', 'beaver-ai-chat' ); ?></span>
								<code class="bac-key-mask">
									<?php
									printf(
										/* translators: %d: number of characters in the saved key. */
										esc_html__( '%d characters', 'beaver-ai-chat' ),
										(int) strlen( BAC_Settings::api_key() )
									);
									?>
								</code>
								<button type="button" class="button button-secondary" data-bac-key-change>
									<?php esc_html_e( 'Change key', 'beaver-ai-chat' ); ?>
								</button>
							</div>

							<div class="bac-key" data-bac-key-edit hidden>
								<input type="password"
									name="<?php echo esc_attr( BAC_OPTION ); ?>[api_key]"
									id="bac-api-key"
									value=""
									class="regular-text"
									autocomplete="new-password"
									spellcheck="false"
									disabled
									data-lpignore="true"
									data-1p-ignore
									data-bwignore
									placeholder="<?php esc_attr_e( 'Paste the new API key', 'beaver-ai-chat' ); ?>" />
								<button type="button" class="button button-link" data-bac-key-cancel>
									<?php esc_html_e( 'Cancel', 'beaver-ai-chat' ); ?>
								</button>
							</div>

							<p class="description">
								<?php esc_html_e( 'Your key is stored on your server only and is never sent to the browser. It cannot be changed by accident: press "Change key" first, and leaving the box blank keeps the current key.', 'beaver-ai-chat' ); ?>
								<br>
								<?php
								printf(
									/* translators: %s: PHP constant example. */
									esc_html__( 'To keep the key out of the database entirely, add %s to wp-config.php.', 'beaver-ai-chat' ),
									'<code>define( \'BAC_API_KEY\', \'your-key\' );</code>'
								);
								?>
							</p>
						<?php else : ?>
							<input type="password"
								name="<?php echo esc_attr( BAC_OPTION ); ?>[api_key]"
								id="bac-api-key"
								value=""
								class="regular-text"
								autocomplete="new-password"
								spellcheck="false"
								data-lpignore="true"
								data-1p-ignore
								data-bwignore
								placeholder="<?php esc_attr_e( 'Paste your API key', 'beaver-ai-chat' ); ?>" />

							<p class="description">
								<?php esc_html_e( 'Stored on your server only, and never sent to the browser.', 'beaver-ai-chat' ); ?>
								<br>
								<?php
								printf(
									/* translators: %s: PHP constant example. */
									esc_html__( 'To keep the key out of the database entirely, add %s to wp-config.php.', 'beaver-ai-chat' ),
									'<code>define( \'BAC_API_KEY\', \'your-key\' );</code>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-model"><?php esc_html_e( 'Model', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'model', $s, '', 'regular-text' ); ?>
						<p class="description" id="bac-model-hint"></p>
					</td>
				</tr>

				<tr class="bac-row-claude">
					<th scope="row"><label for="bac-claude-thinking"><?php esc_html_e( 'Reasoning', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'claude_thinking',
							$s,
							array(
								'off'      => __( 'Off - fastest replies (recommended for chat)', 'beaver-ai-chat' ),
								'adaptive' => __( 'Adaptive - the model thinks when it helps', 'beaver-ai-chat' ),
								'default'  => __( 'Provider default', 'beaver-ai-chat' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Claude only. Reasoning improves hard answers but adds delay and cost, and it shares the reply budget, so the plugin raises the token limit automatically when you switch it on. If your model rejects this setting, choose "Provider default".', 'beaver-ai-chat' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-max-tokens"><?php esc_html_e( 'Reply length limit', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::number( 'max_tokens', $s, 128, 8192 ); ?>
						<p class="description"><?php esc_html_e( 'Maximum tokens per reply. 1024 suits short, conversational answers.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr class="bac-row-temp">
					<th scope="row"><label for="bac-temperature"><?php esc_html_e( 'Creativity', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'temperature', $s, __( 'Provider default', 'beaver-ai-chat' ), 'small-text' ); ?>
						<p class="description"><?php esc_html_e( '0 to 2. Leave blank for the provider default. Not supported by current Claude models, so it is ignored there.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-timeout"><?php esc_html_e( 'Request timeout', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::number( 'timeout', $s, 10, 180 ); ?>
						<?php esc_html_e( 'seconds', 'beaver-ai-chat' ); ?>
						<p class="description"><?php esc_html_e( 'How long to wait for a reply before giving up. Raise it to 90 or more if replies time out: some providers are slow under load, and a longer answer takes longer to write.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Test connection', 'beaver-ai-chat' ); ?></th>
					<td>
						<button type="button" class="button button-secondary" id="bac-test"><?php esc_html_e( 'Send a test message', 'beaver-ai-chat' ); ?></button>
						<span id="bac-test-out" class="bac-test-out"></span>
						<p class="description"><?php esc_html_e( 'Save your settings first, then send a test to confirm the key and model work.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'beaver-ai-chat' ); ?></th>
					<td><?php self::diagnostics( $s, $has_key, $constant ); ?></td>
				</tr>

			</table>

			<?php self::spend_section( $s ); ?>
		</div>
		<?php
	}

	/**
	 * What it is costing, and the ceiling.
	 *
	 * @param array $s Settings.
	 */
	private static function spend_section( $s ) {
		$spend = BAC_Usage::month_spend();
		$cap   = (float) $s['monthly_cap'];
		$rate  = BAC_Usage::rate( BAC_Settings::provider_config( $s )['model'] );
		?>
		<h2 class="bac-h2"><?php esc_html_e( 'What it is costing', 'beaver-ai-chat' ); ?></h2>
		<p class="description bac-section-lede">
			<?php esc_html_e( 'Tokens are counted exactly, from what your provider reports on every call. The money is an estimate from published rates, so treat it as a close guide rather than an invoice.', 'beaver-ai-chat' ); ?>
		</p>

		<?php self::usage_panel( $s, $spend, $cap ); ?>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label for="bac-monthly-cap"><?php esc_html_e( 'Stop at', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php echo esc_html_x( '$', 'currency symbol before the spend limit field', 'beaver-ai-chat' ); ?>
					<?php self::number( 'monthly_cap', $s, 0, 100000 ); ?>
					<?php esc_html_e( 'a month', 'beaver-ai-chat' ); ?>
					<p class="description">
						<?php esc_html_e( 'Zero means no limit. When the limit is reached the assistant stops answering and points visitors at your phone and email instead, and the team is emailed once to say so. It starts again on the first of the month. This is your safety net against a script hammering the chat overnight.', 'beaver-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Your own rates', 'beaver-ai-chat' ); ?></th>
				<td>
					<p>
						<label for="bac-price-in"><?php esc_html_e( 'Input', 'beaver-ai-chat' ); ?></label>
						<?php self::text( 'price_in', $s, '0.00', 'small-text' ); ?>
						<label for="bac-price-out"><?php esc_html_e( 'Output', 'beaver-ai-chat' ); ?></label>
						<?php self::text( 'price_out', $s, '0.00', 'small-text' ); ?>
						<?php esc_html_e( 'per million tokens', 'beaver-ai-chat' ); ?>
					</p>
					<p class="description">
						<?php
						if ( $rate ) {
							printf(
								/* translators: 1: input price, 2: output price. */
								esc_html__( 'Leave blank to use the built-in estimate, currently %1$s and %2$s per million tokens for your model. Fill these in for a self-hosted endpoint, a negotiated rate, or a model this plugin does not know.', 'beaver-ai-chat' ),
								esc_html( BAC_Usage::money( $rate[0] ) ),
								esc_html( BAC_Usage::money( $rate[1] ) )
							);
						} else {
							esc_html_e( 'This plugin has no published rate for your model, so spend shows as zero until you enter one here. Tokens are still counted.', 'beaver-ai-chat' );
						}
						?>
					</p>
				</td>
			</tr>

		</table>

		<?php
		/*
		 * The button is here; its form is not, and must not be.
		 *
		 * This whole section renders inside the settings form, and HTML has no
		 * nested forms: a browser throws away the inner <form> tag and lets its
		 * </form> close the outer one instead. That is what used to happen here,
		 * and it silently cut the settings form off at this point — the
		 * Assistant, Knowledge, Leads and Appearance tabs all fell outside it and
		 * were never posted, and the Save settings button ended up belonging to
		 * no form at all, so clicking it did nothing whatsoever.
		 *
		 * The form attribute is the fix: the button can sit anywhere on the page
		 * and still belong to a form declared elsewhere. reset_form() prints that
		 * form after the settings one closes.
		 */
		submit_button(
			__( 'Clear the usage history', 'beaver-ai-chat' ),
			'link-delete',
			'submit',
			false,
			array( 'form' => self::RESET_FORM )
		);
	}

	/**
	 * The form behind the "Clear the usage history" button.
	 *
	 * Printed after the settings form has closed, never inside it. See the note
	 * in spend_section() for why that matters.
	 */
	private static function reset_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="<?php echo esc_attr( self::RESET_FORM ); ?>" class="bac-hidden-form">
			<?php wp_nonce_field( 'bac_usage_reset' ); ?>
			<input type="hidden" name="action" value="bac_usage_reset" />
		</form>
		<?php
	}

	/**
	 * This month at a glance, with a plain bar per day.
	 *
	 * @param array $s     Settings.
	 * @param float $spend Spend this month.
	 * @param float $cap   The ceiling, or 0.
	 */
	private static function usage_panel( $s, $spend, $cap ) {
		$days   = BAC_Usage::recent( 14 );
		$peak   = 0.0;
		$calls  = 0;
		$tokens = 0;

		foreach ( $days as $row ) {
			$peak    = max( $peak, (float) $row['cost'] );
			$calls  += (int) $row['calls'];
			$tokens += (int) $row['in'] + (int) $row['out'];
		}

		$data    = BAC_Usage::data();
		$reached = ( $cap > 0 && $spend >= $cap );
		?>
		<div class="bac-usage<?php echo $reached ? ' is-capped' : ''; ?>">
			<div class="bac-usage-figures">
				<div>
					<span class="bac-usage-big"><?php echo esc_html( BAC_Usage::money( $spend ) ); ?></span>
					<span class="bac-usage-label"><?php esc_html_e( 'this month', 'beaver-ai-chat' ); ?></span>
				</div>
				<div>
					<span class="bac-usage-big"><?php echo esc_html( number_format_i18n( $calls ) ); ?></span>
					<span class="bac-usage-label"><?php esc_html_e( 'calls in 14 days', 'beaver-ai-chat' ); ?></span>
				</div>
				<div>
					<span class="bac-usage-big"><?php echo esc_html( number_format_i18n( $tokens ) ); ?></span>
					<span class="bac-usage-label"><?php esc_html_e( 'tokens in 14 days', 'beaver-ai-chat' ); ?></span>
				</div>
			</div>

			<?php if ( $reached ) : ?>
				<p class="bac-usage-capped">
					<strong><?php esc_html_e( 'The limit has been reached, so the assistant is not answering.', 'beaver-ai-chat' ); ?></strong>
					<?php esc_html_e( 'Raise or clear the limit below to start it again.', 'beaver-ai-chat' ); ?>
				</p>
			<?php elseif ( $cap > 0 ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: spend so far, 2: the limit. */
						esc_html__( '%1$s of %2$s used.', 'beaver-ai-chat' ),
						esc_html( BAC_Usage::money( $spend ) ),
						esc_html( BAC_Usage::money( $cap ) )
					);
					?>
				</p>
			<?php endif; ?>

			<div class="bac-usage-chart" role="img"
				aria-label="<?php esc_attr_e( 'Daily spend over the last fourteen days', 'beaver-ai-chat' ); ?>">
				<?php foreach ( $days as $day => $row ) : ?>
					<?php $height = ( $peak > 0 ) ? max( 2, round( ( (float) $row['cost'] / $peak ) * 100 ) ) : 2; ?>
					<span class="bac-usage-bar" style="height:<?php echo (int) $height; ?>%"
						title="<?php echo esc_attr( $day . ' — ' . BAC_Usage::money( $row['cost'] ) . ' — ' . number_format_i18n( (int) $row['calls'] ) . ' calls' ); ?>"></span>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $data['models'] ) ) : ?>
				<table class="bac-lead-table bac-usage-models">
					<?php foreach ( $data['models'] as $model => $row ) : ?>
						<tr>
							<th><code><?php echo esc_html( $model ); ?></code></th>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: cost, 2: calls, 3: input tokens, 4: output tokens. */
										__( '%1$s over %2$s calls (%3$s in, %4$s out)', 'beaver-ai-chat' ),
										BAC_Usage::money( $row['cost'] ),
										number_format_i18n( $row['calls'] ),
										number_format_i18n( $row['in'] ),
										number_format_i18n( $row['out'] )
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Why the chat is failing, in plain words.
	 *
	 * Visitors only ever see a friendly "I hit a brief snag" message, which is
	 * right for them and useless for whoever has to fix it. This shows the
	 * provider's own error, plus the handful of things that actually go wrong on
	 * a fresh install, so nobody has to go reading a debug log.
	 *
	 * @param array $s        Settings.
	 * @param bool  $has_key  Whether a key is available.
	 * @param bool  $constant Whether the key comes from wp-config.php.
	 */
	private static function diagnostics( $s, $has_key, $constant ) {
		$cfg     = BAC_Settings::provider_config( $s );
		$last    = BAC_Provider::last_error();
		$blocked = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;

		if ( $last ) {
			$when = human_time_diff( (int) $last['time'] );

			echo '<div class="bac-diag bac-diag--bad"><p><strong>';
			printf(
				/* translators: %s: how long ago, for example "5 mins". */
				esc_html__( 'The last attempt failed, %s ago. Your provider said:', 'beaver-ai-chat' ),
				esc_html( $when )
			);
			echo '</strong></p><p class="bac-diag-msg">' . esc_html( $last['message'] ) . '</p>';

			$hint = self::error_hint( $last );
			if ( '' !== $hint ) {
				echo '<p class="bac-diag-hint">' . wp_kses_post( $hint ) . '</p>';
			}
			echo '</div>';
		} elseif ( $has_key ) {
			echo '<p class="bac-ok"><span class="dashicons dashicons-yes-alt"></span> '
				. esc_html__( 'No failures recorded. If the chat is misbehaving, send a test message above.', 'beaver-ai-chat' )
				. '</p>';
		}

		echo '<table class="bac-lead-table" style="max-width:560px;">';

		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'API key', 'beaver-ai-chat' ),
			$has_key
				? ( $constant
					? esc_html__( 'set in wp-config.php', 'beaver-ai-chat' )
					: esc_html__( 'saved on this site', 'beaver-ai-chat' ) )
				: '<strong style="color:#b32d2e;">' . esc_html__( 'missing — the chat cannot work without one', 'beaver-ai-chat' ) . '</strong>'
		);

		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Provider', 'beaver-ai-chat' ),
			esc_html( $cfg['label'] )
		);

		printf(
			'<tr><th>%s</th><td><code>%s</code></td></tr>',
			esc_html__( 'Model', 'beaver-ai-chat' ),
			esc_html( '' !== $cfg['model'] ? $cfg['model'] : '—' )
		);

		printf(
			'<tr><th>%s</th><td><code>%s</code></td></tr>',
			esc_html__( 'Endpoint', 'beaver-ai-chat' ),
			esc_html( '' !== $cfg['url'] ? $cfg['url'] : '—' )
		);

		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Outbound requests', 'beaver-ai-chat' ),
			$blocked
				? '<strong style="color:#b32d2e;">' . esc_html__( 'blocked by WP_HTTP_BLOCK_EXTERNAL in wp-config.php', 'beaver-ai-chat' ) . '</strong>'
				: esc_html__( 'allowed', 'beaver-ai-chat' )
		);

		echo '</table>';
	}

	/**
	 * A plain-English next step for the errors providers actually return.
	 *
	 * @param array $last Recorded failure.
	 * @return string
	 */
	private static function error_hint( $last ) {
		$message = strtolower( $last['message'] );
		$code    = $last['code'];

		if ( 'bac_no_key' === $code ) {
			return esc_html__( 'No API key is saved on this site. Keys are deliberately left out of the settings export, so each site needs its own pasted in above.', 'beaver-ai-chat' );
		}
		if ( 'bac_no_model' === $code ) {
			return esc_html__( 'Enter a model name, or clear the field to use the recommended default for this provider.', 'beaver-ai-chat' );
		}

		if ( false !== strpos( $message, 'balance' ) || false !== strpos( $message, 'quota' ) || false !== strpos( $message, 'credit' ) || false !== strpos( $message, 'billing' ) ) {
			return esc_html__( 'The account behind this key has run out of credit. Top it up with your provider and the chat will start working again on its own, no changes needed here.', 'beaver-ai-chat' );
		}
		if ( false !== strpos( $message, 'api key' ) || false !== strpos( $message, 'unauthor' ) || false !== strpos( $message, 'authentication' ) || false !== strpos( $message, 'invalid_api' ) ) {
			return esc_html__( 'The key was rejected. Paste it again above, and check it belongs to the provider selected here: a key from one provider never works with another.', 'beaver-ai-chat' );
		}
		if ( false !== strpos( $message, 'model' ) ) {
			return esc_html__( 'The provider does not recognise that model name. Clear the Model field to fall back to the recommended default.', 'beaver-ai-chat' );
		}
		if ( false !== strpos( $message, 'rate limit' ) || false !== strpos( $message, 'too many' ) ) {
			return esc_html__( 'The provider is rate limiting this key. It usually clears by itself within a minute.', 'beaver-ai-chat' );
		}
		/*
		 * A timeout that received SOME bytes is not a blocked connection: the
		 * request got through and the provider began replying, then took too
		 * long. Saying "firewall" there sends people to their host for a problem
		 * their host does not have, so the two cases are separated by whether
		 * anything came back.
		 */
		if ( false !== strpos( $message, 'timed out' ) ) {
			$answered = preg_match( '/with (\d+) bytes received/', $message, $bytes ) && (int) $bytes[1] > 0;

			if ( $answered ) {
				return esc_html__( 'Your server reached the provider and it began replying, then took longer than the time allowed. This is the provider being slow, not a blocked connection. Raise "Request timeout" below to 90 seconds or more. If it keeps happening, shorten the replies (Reply length limit) or the knowledge sent with each message (Knowledge tab, total character budget), since both add to how long a reply takes.', 'beaver-ai-chat' );
			}

			return esc_html__( 'Your server could not get a reply from the provider at all. Raise "Request timeout" below, and if that does not help, ask your host whether outbound requests to the provider are allowed.', 'beaver-ai-chat' );
		}

		if ( false !== strpos( $message, 'could not resolve' ) || false !== strpos( $message, 'failed to connect' ) || false !== strpos( $message, 'connection refused' ) || false !== strpos( $message, 'ssl' ) ) {
			return esc_html__( 'Your server could not reach the provider at all: the address did not resolve, or the connection was refused. This is usually a host firewall blocking outbound requests, so ask your host to allow them.', 'beaver-ai-chat' );
		}

		if ( false !== strpos( $message, 'curl' ) ) {
			return esc_html__( 'Your server could not complete the request. Raise "Request timeout" below, then ask your host whether outbound requests to the provider are allowed.', 'beaver-ai-chat' );
		}

		return '';
	}

	/**
	 * Assistant tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_assistant( $s ) {
		$tones = array();
		foreach ( BAC_Settings::tones() as $id => $tone ) {
			$tones[ $id ] = $tone['label'];
		}
		?>
		<div class="bac-panel" data-bac-panel="assistant">

			<h2 class="bac-h2"><?php esc_html_e( 'Who it is', 'beaver-ai-chat' ); ?></h2>
			<p class="description bac-tokens">
				<?php esc_html_e( 'You can use these placeholders in any text below:', 'beaver-ai-chat' ); ?>
				<code>{assistant}</code> <code>{business}</code> <code>{phone}</code> <code>{email}</code>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bac-assistant"><?php esc_html_e( 'Assistant name', 'beaver-ai-chat' ); ?></label></th>
					<td><?php self::text( 'assistant', $s, 'Aria', 'regular-text' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-tagline"><?php esc_html_e( 'Status line', 'beaver-ai-chat' ); ?></label></th>
					<td><?php self::text( 'tagline', $s, '', 'regular-text' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-avatar-url"><?php esc_html_e( 'Avatar image URL', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'avatar_url', $s, '', 'large-text' ); ?>
						<p class="description"><?php esc_html_e( 'Optional. Leave blank to use the first letter of the assistant name.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-greeting"><?php esc_html_e( 'Welcome message', 'beaver-ai-chat' ); ?></label></th>
					<td><?php self::textarea( 'greeting', $s, 3 ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-chips"><?php esc_html_e( 'Suggested questions', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'chips', $s, 4 ); ?>
						<p class="description"><?php esc_html_e( 'One per line, up to six. Shown as tappable buttons before the first message.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-placeholder"><?php esc_html_e( 'Input placeholder', 'beaver-ai-chat' ); ?></label></th>
					<td><?php self::text( 'placeholder', $s, '', 'regular-text' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-footer-note"><?php esc_html_e( 'Small print', 'beaver-ai-chat' ); ?></label></th>
					<td><?php self::text( 'footer_note', $s, '', 'large-text' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attention bubble', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'nudge_enabled', $s, __( 'Show a small prompt beside the button after a moment', 'beaver-ai-chat' ) ); ?>
						<p><?php self::text( 'nudge_text', $s, '', 'large-text' ); ?></p>
						<p>
							<label>
								<?php esc_html_e( 'Delay (milliseconds)', 'beaver-ai-chat' ); ?>
								<?php self::number( 'nudge_delay', $s, 0, 120000 ); ?>
							</label>
						</p>
					</td>
				</tr>
			</table>

			<h2 class="bac-h2"><?php esc_html_e( 'How it behaves', 'beaver-ai-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bac-business-name"><?php esc_html_e( 'Business name', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'business_name', $s, get_bloginfo( 'name' ), 'regular-text' ); ?>
						<p class="description"><?php esc_html_e( 'Leave blank to use your site title.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-business-type"><?php esc_html_e( 'What you do', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'business_type', $s, __( 'for example: a family law firm in Leeds', 'beaver-ai-chat' ), 'large-text' ); ?>
						<p class="description"><?php esc_html_e( 'A short phrase. This completes the sentence "the AI assistant for [your business], ...".', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-business-desc"><?php esc_html_e( 'About your business', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'business_desc', $s, 3 ); ?>
						<p class="description"><?php esc_html_e( 'A paragraph the assistant should always know: who you serve, where you operate, what makes you different.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-tone"><?php esc_html_e( 'Tone of voice', 'beaver-ai-chat' ); ?></label></th>
					<td><?php self::select( 'tone', $s, $tones ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-reply-length"><?php esc_html_e( 'Reply length', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'reply_length', $s, '', 'regular-text' ); ?>
						<p class="description"><?php esc_html_e( 'Written in plain words, for example "about 2 to 5 sentences" or "one short paragraph".', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rules', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'ask_contact', $s, __( 'Ask for the visitor\'s name and email early in the chat', 'beaver-ai-chat' ) ); ?><br>
						<?php self::checkbox( 'scope_lock', $s, __( 'Keep the conversation on your business and steer off-topic questions back', 'beaver-ai-chat' ) ); ?><br>
						<?php self::checkbox( 'multilingual', $s, __( 'Reply in whatever language the visitor writes in', 'beaver-ai-chat' ) ); ?><br>
						<?php self::checkbox( 'no_emoji', $s, __( 'Never use emojis', 'beaver-ai-chat' ) ); ?><br>
						<?php self::checkbox( 'no_dashes', $s, __( 'Never use em dashes or en dashes', 'beaver-ai-chat' ) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-prompt-extra"><?php esc_html_e( 'Extra instructions', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'prompt_extra', $s, 4 ); ?>
						<p class="description"><?php esc_html_e( 'Added to the end of the instructions and treated as authoritative. Good for rules such as "never quote a price without mentioning VAT".', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bac-prompt-override"><?php esc_html_e( 'Replace all instructions', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'prompt_override', $s, 5 ); ?>
						<p class="description"><?php esc_html_e( 'Advanced. Anything here replaces every instruction above; your site knowledge is still added underneath. Leave blank unless you know you need it.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Knowledge tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_knowledge( $s ) {
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		$skip_types = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', BAC_LEAD_CPT );
		$skip_taxes = array( 'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );
		?>
		<div class="bac-panel" data-bac-panel="knowledge">
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Site knowledge', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'kb_enabled', $s, __( 'Let the assistant read your content so it answers from your real pages', 'beaver-ai-chat' ), true ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-kb-mode"><?php esc_html_e( 'How much to send', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'kb_mode',
							$s,
							array(
								'relevant' => __( 'Only what answers the question (recommended)', 'beaver-ai-chat' ),
								'all'      => __( 'Everything, every time', 'beaver-ai-chat' ),
							)
						);
						?>
						<p class="bac-row-relevant">
							<label for="bac-kb-top-k"><?php esc_html_e( 'Send at most', 'beaver-ai-chat' ); ?></label>
							<?php self::number( 'kb_top_k', $s, 1, 60 ); ?>
							<?php esc_html_e( 'items in full', 'beaver-ai-chat' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Your knowledge is sent with every single message, so on a site of any size it is the largest part of the bill. Matching the question means the assistant gets the three pages that answer it instead of everything you publish: cheaper per message, and a sharper answer. A short list of every title still goes along, so it always knows the full range of what you offer.', 'beaver-ai-chat' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Content to read', 'beaver-ai-chat' ); ?></th>
					<td>
						<fieldset class="bac-checklist">
							<?php
							foreach ( $post_types as $type ) :
								if ( in_array( $type->name, $skip_types, true ) ) {
									continue;
								}
								?>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( BAC_OPTION ); ?>[kb_post_types][]"
										value="<?php echo esc_attr( $type->name ); ?>"
										<?php checked( in_array( $type->name, (array) $s['kb_post_types'], true ) ); ?> />
									<?php echo esc_html( $type->labels->name ); ?>
									<code><?php echo esc_html( $type->name ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Titles, links, prices and a short extract of each item are included. Products, services, courses, listings, anything you publish.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Categories to list', 'beaver-ai-chat' ); ?></th>
					<td>
						<fieldset class="bac-checklist">
							<?php
							foreach ( $taxonomies as $tax ) :
								if ( in_array( $tax->name, $skip_taxes, true ) ) {
									continue;
								}
								?>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( BAC_OPTION ); ?>[kb_taxonomies][]"
										value="<?php echo esc_attr( $tax->name ); ?>"
										<?php checked( in_array( $tax->name, (array) $s['kb_taxonomies'], true ) ); ?> />
									<?php echo esc_html( $tax->labels->name ); ?>
									<code><?php echo esc_html( $tax->name ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'The assistant will know these exist and can mention them by name.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-kb-exclude"><?php esc_html_e( 'Skip these pages', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'kb_exclude', $s, 3 ); ?>
						<p class="description"><?php esc_html_e( 'Page slugs, comma or line separated. Legal and checkout pages are skipped by default because they waste the budget.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-kb-price-meta"><?php esc_html_e( 'Price fields', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'kb_price_meta', $s, '', 'large-text' ); ?>
						<p class="description"><?php esc_html_e( 'Custom field names to check for a price, in order. The first one that has a value wins.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-kb-extra-meta"><?php esc_html_e( 'Other fields to include', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::text( 'kb_extra_meta', $s, '', 'large-text' ); ?>
						<p class="description"><?php esc_html_e( 'Any other custom fields worth knowing, such as duration, location or SKU.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-context"><?php esc_html_e( 'Extra knowledge', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'context', $s, 6 ); ?>
						<p class="description"><?php esc_html_e( 'Anything the assistant should always know that is not on the site: opening hours, current offers, policies, cut-off times, what you do not do.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Size and freshness', 'beaver-ai-chat' ); ?></th>
					<td>
						<p><label><?php esc_html_e( 'Items per content type', 'beaver-ai-chat' ); ?> <?php self::number( 'kb_per_type', $s, 1, 200 ); ?></label></p>
						<p><label><?php esc_html_e( 'Characters per item', 'beaver-ai-chat' ); ?> <?php self::number( 'kb_item_chars', $s, 80, 4000 ); ?></label></p>
						<p><label><?php esc_html_e( 'Total character budget', 'beaver-ai-chat' ); ?> <?php self::number( 'kb_budget', $s, 1000, 60000 ); ?></label></p>
						<p><label><?php esc_html_e( 'Rebuild after (hours)', 'beaver-ai-chat' ); ?> <?php self::number( 'kb_cache_hours', $s, 0, 168 ); ?></label></p>
						<p class="description"><?php esc_html_e( 'A bigger budget means a better informed assistant and a higher cost per message. Around 14000 characters is a sensible balance.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

			</table>
		</div>
		<?php
	}

	/**
	 * Leads tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_leads( $s ) {
		?>
		<div class="bac-panel" data-bac-panel="leads">
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Save conversations', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'leads_enabled', $s, __( 'Record chats under Conversations', 'beaver-ai-chat' ), true ); ?>
						<p class="description"><?php esc_html_e( 'Conversations appear the moment someone sends their first message and update on every reply, so you can read a chat while it is still happening.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-lead-capture-mode"><?php esc_html_e( 'What to save', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'lead_capture_mode',
							$s,
							array(
								'all'     => __( 'Every conversation, from the first message', 'beaver-ai-chat' ),
								'contact' => __( 'Only once the visitor gives an email address', 'beaver-ai-chat' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Saving everything shows you what people actually ask, including the visitors who never leave their details. Choose the second option if you only want qualified leads in the list.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Summaries', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'lead_ai_summary', $s, __( 'Use the AI to name each lead and write a short summary', 'beaver-ai-chat' ) ); ?>
						<p class="description"><?php esc_html_e( 'Runs in the background after the reply is sent, so the visitor waits no longer. Costs one extra call per few messages.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Hand-off button', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'cta_enabled', $s, __( 'Show a button that asks the team to get in touch', 'beaver-ai-chat' ) ); ?>
						<p><?php self::text( 'cta_label', $s, '', 'regular-text' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'WhatsApp button', 'beaver-ai-chat' ); ?></th>
					<td>
						<?php self::checkbox( 'wa_enabled', $s, __( 'Show a WhatsApp button next to it', 'beaver-ai-chat' ) ); ?>
						<p><?php self::text( 'wa_message', $s, '', 'large-text' ); ?></p>
						<p class="description"><?php esc_html_e( 'The message pre-filled when they tap through. Requires a WhatsApp number below.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Contact details', 'beaver-ai-chat' ); ?></th>
					<td>
						<p><label><?php esc_html_e( 'Phone', 'beaver-ai-chat' ); ?><br><?php self::text( 'phone', $s, '', 'regular-text' ); ?></label></p>
						<p><label><?php esc_html_e( 'WhatsApp number', 'beaver-ai-chat' ); ?><br><?php self::text( 'whatsapp', $s, '+255...', 'regular-text' ); ?></label></p>
						<p><label><?php esc_html_e( 'Contact email', 'beaver-ai-chat' ); ?><br><?php self::text( 'contact_email', $s, '', 'regular-text' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'The assistant may share these when a visitor needs a human.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-links"><?php esc_html_e( 'Helpful links', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php self::textarea( 'links', $s, 5 ); ?>
						<p class="description">
							<?php esc_html_e( 'One per line, written as Label | /path. For example:', 'beaver-ai-chat' ); ?>
							<code><?php echo esc_html( 'Book a call | /contact/' ); ?></code>
						</p>
					</td>
				</tr>

			</table>

			<?php self::alerts_section( $s ); ?>
		</div>
		<?php
	}

	/**
	 * Email alerts, inside the Leads tab.
	 *
	 * @param array $s Settings.
	 */
	private static function alerts_section( $s ) {
		?>
		<h2 class="bac-h2"><?php esc_html_e( 'Email alerts', 'beaver-ai-chat' ); ?></h2>
		<p class="description bac-section-lede">
			<?php esc_html_e( 'What the team receives about a conversation: what the visitor asked for, whatever contact details they gave, and a link straight to the chat.', 'beaver-ai-chat' ); ?>
		</p>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><?php esc_html_e( 'Send alerts', 'beaver-ai-chat' ); ?></th>
				<td>
					<?php self::checkbox( 'notify_enabled', $s, __( 'Email the team about chat conversations', 'beaver-ai-chat' ), true ); ?>
					<p><label for="bac-notify-email" class="screen-reader-text"><?php esc_html_e( 'Recipients', 'beaver-ai-chat' ); ?></label>
						<?php self::text( 'notify_email', $s, get_option( 'admin_email' ), 'regular-text' ); ?></p>
					<p class="description"><?php esc_html_e( 'Comma separated for more than one recipient. Leave blank to use the site admin email.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bac-notify-when"><?php esc_html_e( 'Tell me about', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php
					self::select(
						'notify_when',
						$s,
						array(
							'contact' => __( 'Only visitors who left an email or phone number', 'beaver-ai-chat' ),
							'all'     => __( 'Every real conversation, contact details or not', 'beaver-ai-chat' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'The second option is how you find out what people ask when they never leave their details, which is usually most of them.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bac-notify-timing"><?php esc_html_e( 'When to send', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php
					self::select(
						'notify_timing',
						$s,
						array(
							'settled' => __( 'Once the chat goes quiet (recommended)', 'beaver-ai-chat' ),
							'instant' => __( 'Straight away', 'beaver-ai-chat' ),
							'digest'  => __( 'Stay quiet and send one roundup', 'beaver-ai-chat' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'A chat keeps changing while the visitor types. Waiting for it to finish means one complete email with a real summary, instead of an alert about a conversation that had barely started. Callback requests always send immediately whatever is chosen here.', 'beaver-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr class="bac-row-settled">
				<th scope="row"><label for="bac-notify-delay"><?php esc_html_e( 'Quiet for', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php self::number( 'notify_delay', $s, 1, 240 ); ?>
					<?php esc_html_e( 'minutes', 'beaver-ai-chat' ); ?>
					<p class="description"><?php esc_html_e( 'Each new message pushes the alert back, so it only goes out once the visitor has actually stopped.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr class="bac-row-digest">
				<th scope="row"><label for="bac-notify-digest-every"><?php esc_html_e( 'Roundup every', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php
					self::select(
						'notify_digest_every',
						$s,
						array(
							1  => __( 'Hour', 'beaver-ai-chat' ),
							4  => __( '4 hours', 'beaver-ai-chat' ),
							12 => __( '12 hours', 'beaver-ai-chat' ),
							24 => __( 'Day', 'beaver-ai-chat' ),
						)
					);
					?>
					<span class="bac-row-digest-daily">
						<label for="bac-notify-digest-hour"><?php esc_html_e( 'at', 'beaver-ai-chat' ); ?></label>
						<?php self::select( 'notify_digest_hour', $s, self::hours() ); ?>
					</span>
					<p><?php self::checkbox( 'notify_digest_empty', $s, __( 'Send it even when there were no conversations', 'beaver-ai-chat' ) ); ?></p>
					<p class="description">
						<?php esc_html_e( 'Uses your site timezone and WordPress cron, so a site with no visitors may run a little late. Chats still in progress are held over to the next roundup rather than reported half finished.', 'beaver-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bac-notify-min-turns"><?php esc_html_e( 'Ignore chats under', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php self::number( 'notify_min_turns', $s, 1, 20 ); ?>
					<?php esc_html_e( 'visitor messages', 'beaver-ai-chat' ); ?>
					<p class="description"><?php esc_html_e( 'Keeps someone typing "hi" and leaving out of your inbox. They are still saved under Conversations.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Include', 'beaver-ai-chat' ); ?></th>
				<td>
					<?php self::checkbox( 'notify_transcript', $s, __( 'The whole conversation, under the summary', 'beaver-ai-chat' ) ); ?>
					<p class="description"><?php esc_html_e( 'The summary, contact details and a link are always included. Roundups always stay short.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Links in the email', 'beaver-ai-chat' ); ?></th>
				<td>
					<?php self::checkbox( 'notify_share_links', $s, __( 'Add a link that opens the conversation without a WordPress login', 'beaver-ai-chat' ) ); ?>
					<p class="bac-row-share">
						<label for="bac-notify-link-days"><?php esc_html_e( 'Links stop working after', 'beaver-ai-chat' ); ?></label>
						<?php self::number( 'notify_link_days', $s, 1, 90 ); ?>
						<?php esc_html_e( 'days', 'beaver-ai-chat' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'For a sales team who read alerts on their phones and have no account here. Each link is signed for one conversation and expires. Anyone who has the link can read that chat, so treat it like the email itself. Unticking this immediately kills every link already sent.', 'beaver-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bac-notify-subject"><?php esc_html_e( 'Subject line', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php self::text( 'notify_subject', $s, __( 'Written for you', 'beaver-ai-chat' ), 'large-text' ); ?>
					<p class="description bac-tokens">
						<?php esc_html_e( 'Leave blank and each subject says what actually happened. To fix your own, these are replaced:', 'beaver-ai-chat' ); ?>
						<code>{site}</code> <code>{name}</code> <code>{email}</code> <code>{phone}</code> <code>{interest}</code> <code>{summary}</code> <code>{messages}</code>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Check it works', 'beaver-ai-chat' ); ?></th>
				<td>
					<button type="button" class="button button-secondary" id="bac-test-email"><?php esc_html_e( 'Send a test alert', 'beaver-ai-chat' ); ?></button>
					<span class="bac-test-out" id="bac-test-email-out"></span>
					<p class="description"><?php esc_html_e( 'Sends the most recent conversation to the recipients above and to every channel below, laid out exactly as a real alert. Uses saved settings, so save first.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

		</table>

		<?php self::channels_section( $s ); ?>
		<?php
	}

	/**
	 * Alerts somewhere other than email.
	 *
	 * @param array $s Settings.
	 */
	private static function channels_section( $s ) {
		?>
		<h2 class="bac-h2"><?php esc_html_e( 'Where else to send alerts', 'beaver-ai-chat' ); ?></h2>
		<p class="description bac-section-lede">
			<?php esc_html_e( 'A one line version of the same alert, sent wherever your team actually looks. Each of these is optional and works alongside the email, not instead of it.', 'beaver-ai-chat' ); ?>
		</p>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label for="bac-slack-webhook"><?php esc_html_e( 'Slack', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php self::text( 'slack_webhook', $s, 'https://hooks.slack.com/services/…', 'large-text' ); ?>
					<p class="description"><?php esc_html_e( 'An incoming webhook URL. In Slack: create an app, turn on Incoming Webhooks, add one to the channel you want, and paste the URL here.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'WhatsApp', 'beaver-ai-chat' ); ?></th>
				<td>
					<p><label><?php esc_html_e( 'Send to', 'beaver-ai-chat' ); ?><br>
						<?php self::text( 'wa_to', $s, '255700000000, 255711111111', 'regular-text' ); ?></label></p>
					<p><label><?php esc_html_e( 'Phone number ID', 'beaver-ai-chat' ); ?><br>
						<?php self::text( 'wa_phone_id', $s, '', 'regular-text' ); ?></label></p>
					<p><label><?php esc_html_e( 'Access token', 'beaver-ai-chat' ); ?><br>
						<?php self::text( 'wa_token', $s, '', 'large-text' ); ?></label></p>
					<p>
						<?php
						self::select(
							'wa_api_mode',
							$s,
							array(
								'template' => __( 'Send an approved template (works any time)', 'beaver-ai-chat' ),
								'text'     => __( 'Send plain text (only within 24 hours of them messaging you)', 'beaver-ai-chat' ),
							)
						);
						?>
					</p>
					<p class="bac-row-wa-template">
						<label for="bac-wa-template"><?php esc_html_e( 'Template name', 'beaver-ai-chat' ); ?></label>
						<?php self::text( 'wa_template', $s, 'chat_lead_alert', 'regular-text' ); ?>
						<label for="bac-wa-language"><?php esc_html_e( 'Language', 'beaver-ai-chat' ); ?></label>
						<?php self::text( 'wa_language', $s, 'en', 'small-text' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Uses the WhatsApp Cloud API, so it needs a Meta business account: the numbers are digits only with the country code and no plus. WhatsApp does not let a business start a conversation with free text, so the default sends an approved template with the alert as its single variable. Create a template with one body variable, wait for it to be approved, and put its name here.', 'beaver-ai-chat' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Telegram', 'beaver-ai-chat' ); ?></th>
				<td>
					<p><label><?php esc_html_e( 'Bot token', 'beaver-ai-chat' ); ?><br>
						<?php self::text( 'telegram_token', $s, '', 'large-text' ); ?></label></p>
					<p><label><?php esc_html_e( 'Chat ID', 'beaver-ai-chat' ); ?><br>
						<?php self::text( 'telegram_chat', $s, '', 'regular-text' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'Talk to @BotFather to make a bot, add it to your team group, then send the group a message and read the chat id from api.telegram.org/bot<token>/getUpdates. Group ids start with a minus.', 'beaver-ai-chat' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="bac-webhook-url"><?php esc_html_e( 'Anywhere else', 'beaver-ai-chat' ); ?></label></th>
				<td>
					<?php self::text( 'webhook_url', $s, 'https://…', 'large-text' ); ?>
					<p>
						<label for="bac-webhook-secret"><?php esc_html_e( 'Signing secret', 'beaver-ai-chat' ); ?></label>
						<?php self::text( 'webhook_secret', $s, '', 'regular-text' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'The whole record as JSON, for a CRM, Zapier, Make, n8n or your own endpoint. With a secret set, the body is signed and sent as X-BAC-Signature: sha256=… so the receiver can prove it came from this site.', 'beaver-ai-chat' ); ?>
					</p>
				</td>
			</tr>

		</table>
		<?php
	}

	/**
	 * Hour choices for the daily roundup, in the site's own time format.
	 *
	 * @return array
	 */
	private static function hours() {
		$out    = array();
		$format = get_option( 'time_format', 'H:i' );

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$out[ $hour ] = wp_date( $format, mktime( $hour, 0, 0, 1, 1, 2020 ), new DateTimeZone( 'UTC' ) );
		}

		return $out;
	}

	/**
	 * Appearance tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_appearance( $s ) {
		?>
		<div class="bac-panel" data-bac-panel="appearance">
			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Colours', 'beaver-ai-chat' ); ?></th>
					<td>
						<p>
							<label><?php esc_html_e( 'Main colour', 'beaver-ai-chat' ); ?>
								<input type="color" name="<?php echo esc_attr( BAC_OPTION ); ?>[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" />
							</label>
						</p>
						<p>
							<label><?php esc_html_e( 'Highlight colour', 'beaver-ai-chat' ); ?>
								<input type="color" name="<?php echo esc_attr( BAC_OPTION ); ?>[secondary]" value="<?php echo esc_attr( $s['secondary'] ); ?>" />
							</label>
						</p>
						<p class="description"><?php esc_html_e( 'Lighter and darker shades are worked out automatically.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-position"><?php esc_html_e( 'Position', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'position',
							$s,
							array(
								'right' => __( 'Bottom right', 'beaver-ai-chat' ),
								'left'  => __( 'Bottom left', 'beaver-ai-chat' ),
							)
						);
						?>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-theme"><?php esc_html_e( 'Light or dark', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'theme',
							$s,
							array(
								'auto'  => __( 'Follow the visitor\'s device', 'beaver-ai-chat' ),
								'light' => __( 'Always light', 'beaver-ai-chat' ),
								'dark'  => __( 'Always dark', 'beaver-ai-chat' ),
							)
						);
						?>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-corner-style"><?php esc_html_e( 'Chat window corners', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						$corner_choices = array();
						foreach ( BAC_Settings::corner_styles() as $id => $corner ) {
							$corner_choices[ $id ] = $corner['label'];
						}
						self::select( 'corner_style', $s, $corner_choices );
						?>
						<p class="description"><?php esc_html_e( 'Shapes the chat window only: the window itself, its buttons and the message box. Choose Square for a completely un-rounded window. The round chat button stays round, and the message bubbles keep their own shape, set below.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-bubble-style"><?php esc_html_e( 'Message bubbles', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						$bubble_choices = array();
						foreach ( BAC_Settings::bubble_styles() as $id => $bubble ) {
							$bubble_choices[ $id ] = $bubble['label'];
						}
						self::select( 'bubble_style', $s, $bubble_choices );
						?>
						<p class="description"><?php esc_html_e( 'The shape of the individual messages, and of the small prompt bubble beside the chat button. Rounded suits almost every brand, even with a square window, because a speech bubble reads as speech. Pick "Match the chat window" only if you want the messages squared off too.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Size and layering', 'beaver-ai-chat' ); ?></th>
					<td>
						<p><label><?php esc_html_e( 'Chat button size (px)', 'beaver-ai-chat' ); ?> <?php self::number( 'launcher_size', $s, 44, 96 ); ?></label></p>
						<p><label><?php esc_html_e( 'Stacking order (z-index)', 'beaver-ai-chat' ); ?> <?php self::number( 'z_index', $s, 1, 2147483000 ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Raise the stacking order if another floating element covers the chat.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-display"><?php esc_html_e( 'Where it appears', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'display',
							$s,
							array(
								'all'     => __( 'Every page', 'beaver-ai-chat' ),
								'include' => __( 'Only these pages', 'beaver-ai-chat' ),
								'exclude' => __( 'Everywhere except these pages', 'beaver-ai-chat' ),
							)
						);
						?>
						<p><?php self::text( 'display_ids', $s, __( 'Page or post IDs, comma separated', 'beaver-ai-chat' ), 'regular-text' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-mobile-display"><?php esc_html_e( 'On mobile', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'mobile_display',
							$s,
							array(
								'fullscreen' => __( 'Full screen (recommended)', 'beaver-ai-chat' ),
								'panel'      => __( 'Floating panel, same as desktop', 'beaver-ai-chat' ),
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'On a phone, full screen gives the conversation the whole display and stops the page scrolling behind it, which is what people expect from a chat. The chat button hides while it is open: the close arrow in the header is the way out.', 'beaver-ai-chat' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="bac-devices"><?php esc_html_e( 'Devices', 'beaver-ai-chat' ); ?></label></th>
					<td>
						<?php
						self::select(
							'devices',
							$s,
							array(
								'all'     => __( 'All devices', 'beaver-ai-chat' ),
								'desktop' => __( 'Desktop only', 'beaver-ai-chat' ),
								'mobile'  => __( 'Mobile only', 'beaver-ai-chat' ),
							)
						);
						?>
						<p><?php self::checkbox( 'logged_in_only', $s, __( 'Only show to logged in users', 'beaver-ai-chat' ) ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Limits', 'beaver-ai-chat' ); ?></th>
					<td>
						<p><label><?php esc_html_e( 'Messages per minute, per visitor', 'beaver-ai-chat' ); ?> <?php self::number( 'rate_limit', $s, 1, 500 ); ?></label></p>
						<p><label><?php esc_html_e( 'Turns of history sent to the AI', 'beaver-ai-chat' ); ?> <?php self::number( 'history_turns', $s, 2, 40 ); ?></label></p>
						<p><label><?php esc_html_e( 'Maximum characters per message', 'beaver-ai-chat' ); ?> <?php self::number( 'msg_max_chars', $s, 200, 8000 ); ?></label></p>
					</td>
				</tr>

			</table>
		</div>
		<?php
	}

	/**
	 * Tools tab. Outside the settings form, because these are their own actions.
	 */
	private static function tab_tools() {
		?>
		<div class="bac-panel" data-bac-panel="tools">

			<h2 class="bac-h2"><?php esc_html_e( 'Move this setup to another site', 'beaver-ai-chat' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Export every setting except the API key, then import it on your next site and paste that site\'s own key. Nothing secret leaves this server.', 'beaver-ai-chat' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bac-tool-form">
				<?php wp_nonce_field( 'bac_export' ); ?>
				<input type="hidden" name="action" value="bac_export" />
				<?php submit_button( __( 'Download settings file', 'beaver-ai-chat' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bac-tool-form">
				<?php wp_nonce_field( 'bac_import' ); ?>
				<input type="hidden" name="action" value="bac_import" />
				<p>
					<label for="bac-import-json"><strong><?php esc_html_e( 'Paste a settings file to import', 'beaver-ai-chat' ); ?></strong></label>
					<textarea name="bac_import_json" id="bac-import-json" rows="6" class="large-text code" placeholder="{ … }"></textarea>
				</p>
				<?php submit_button( __( 'Import settings', 'beaver-ai-chat' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr>

			<?php BAC_Import::render_card(); ?>

			<hr>

			<h2 class="bac-h2"><?php esc_html_e( 'Embed the chat in a page', 'beaver-ai-chat' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Add this shortcode to any page or post to show the chat inline instead of as a floating button:', 'beaver-ai-chat' ); ?>
				<br><code>[beaver_ai_chat height="520"]</code>
			</p>

			<h2 class="bac-h2"><?php esc_html_e( 'Where your data goes', 'beaver-ai-chat' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Messages are sent from your server to the AI provider you chose, so their terms and privacy policy apply to the conversation text. Nothing is sent anywhere else. Captured conversations stay in your own database under Chat Leads.', 'beaver-ai-chat' ); ?>
			</p>

		</div>
		<?php
	}

	/* ------------------------------------------------------------- Actions */

	/** Admin only test call using the saved settings. */
	public static function ajax_test() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'bac_test', '_ajax_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-ai-chat' ) ) );
		}

		if ( '' === BAC_Settings::api_key() ) {
			wp_send_json_error( array( 'message' => __( 'Save an API key first.', 'beaver-ai-chat' ) ) );
		}

		$result = BAC_Provider::chat(
			'You are a connection test. Reply with exactly the two characters: OK',
			array(
				array(
					'role'    => 'user',
					'content' => 'Say OK',
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => mb_substr( (string) $result, 0, 160 ) ) );
	}

	/** Send a sample alert, so mail problems surface here and not on a real lead. */
	public static function ajax_test_email() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'bac_test_email', '_ajax_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-ai-chat' ) ) );
		}

		$result = BAC_Notify::send_test( BAC_Settings::get() );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/** Stream the settings back as a JSON download. */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-ai-chat' ) );
		}
		check_admin_referer( 'bac_export' );

		$payload = wp_json_encode( BAC_Settings::exportable(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$name    = 'beaver-ai-chat-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $payload ) );

		echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/** Apply a pasted settings file. */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-ai-chat' ) );
		}
		check_admin_referer( 'bac_import' );

		$raw     = isset( $_POST['bac_import_json'] ) ? wp_unslash( $_POST['bac_import_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated and each value sanitised on import.
		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			self::redirect_notice( 'import-invalid' );
		}

		$count = BAC_Settings::import( $decoded );

		self::redirect_notice( $count > 0 ? 'imported' : 'import-empty', $count );
	}

	/** Start the usage history again from zero. */
	public static function handle_usage_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-ai-chat' ) );
		}
		check_admin_referer( 'bac_usage_reset' );

		BAC_Usage::reset();

		self::redirect_notice( 'usage-reset' );
	}

	/**
	 * Redirect back to the settings page with a notice.
	 *
	 * @param string $code  Notice code.
	 * @param int    $count Optional count.
	 */
	private static function redirect_notice( $code, $count = 0 ) {
		$messages = array(
			'usage-reset'    => array(
				'type' => 'updated',
				'text' => __( 'Usage history cleared. Counting starts again from now.', 'beaver-ai-chat' ),
			),
			'imported'       => array(
				'type' => 'updated',
				/* translators: %d: number of settings applied. */
				'text' => sprintf( __( 'Imported %d settings. Your API key was left untouched.', 'beaver-ai-chat' ), (int) $count ),
			),
			'import-empty'   => array(
				'type' => 'error',
				'text' => __( 'Nothing was imported. The file did not contain any settings this plugin recognises.', 'beaver-ai-chat' ),
			),
			'import-invalid' => array(
				'type' => 'error',
				'text' => __( 'That did not look like a valid settings file.', 'beaver-ai-chat' ),
			),
		);

		$notice = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['import-invalid'];

		add_settings_error( 'bac_notices', $code, $notice['text'], 'updated' === $notice['type'] ? 'success' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( add_query_arg( array( 'settings-updated' => 'true' ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/* ------------------------------------------------------- Field helpers */

	/**
	 * Field name attribute.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return BAC_OPTION . '[' . $key . ']';
	}

	/**
	 * Field id attribute.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function id( $key ) {
		return 'bac-' . str_replace( '_', '-', $key );
	}

	/**
	 * Text input.
	 *
	 * @param string $key         Setting key.
	 * @param array  $s           Settings.
	 * @param string $placeholder Placeholder.
	 * @param string $class       CSS class.
	 */
	private static function text( $key, $s, $placeholder = '', $class = 'regular-text' ) {
		printf(
			'<input type="text" name="%s" id="%s" value="%s" class="%s" placeholder="%s" spellcheck="false" />',
			esc_attr( self::name( $key ) ),
			esc_attr( self::id( $key ) ),
			esc_attr( $s[ $key ] ),
			esc_attr( $class ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Number input.
	 *
	 * Always step="1", never a wider step. To the browser a step is not a
	 * spinner increment, it is a validation rule: with step="64" on max_tokens,
	 * typing 2000 makes the field invalid, and one invalid field blocks the
	 * whole form from submitting. The tabs hide their panels with display:none,
	 * so an invalid field on a tab you are not looking at cannot be focused to
	 * show its message either — the browser silently refuses the submit and
	 * Save stops working with no explanation at all.
	 *
	 * min and max still apply, and admin.js opens the tab holding anything the
	 * browser rejects. Either way every value is clamped again in
	 * BAC_Settings::sanitize(), which is what actually guards the range.
	 *
	 * @param string $key Setting key.
	 * @param array  $s   Settings.
	 * @param int    $min Minimum.
	 * @param int    $max Maximum.
	 */
	private static function number( $key, $s, $min, $max ) {
		printf(
			'<input type="number" name="%s" id="%s" value="%s" min="%d" max="%d" step="1" class="small-text" />',
			esc_attr( self::name( $key ) ),
			esc_attr( self::id( $key ) ),
			esc_attr( $s[ $key ] ),
			(int) $min,
			(int) $max
		);
	}

	/**
	 * Textarea.
	 *
	 * @param string $key  Setting key.
	 * @param array  $s    Settings.
	 * @param int    $rows Rows.
	 */
	private static function textarea( $key, $s, $rows = 3 ) {
		printf(
			'<textarea name="%s" id="%s" rows="%d" class="large-text">%s</textarea>',
			esc_attr( self::name( $key ) ),
			esc_attr( self::id( $key ) ),
			(int) $rows,
			esc_textarea( $s[ $key ] )
		);
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key    Setting key.
	 * @param array  $s      Settings.
	 * @param string $label  Label text.
	 * @param bool   $strong Render the label in bold.
	 */
	private static function checkbox( $key, $s, $label, $strong = false ) {
		printf(
			'<label%s><input type="checkbox" name="%s" id="%s" value="1" %s /> %s</label>',
			$strong ? ' class="bac-strong"' : '',
			esc_attr( self::name( $key ) ),
			esc_attr( self::id( $key ) ),
			checked( $s[ $key ], 1, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute.
			esc_html( $label )
		);
	}

	/**
	 * Select box.
	 *
	 * @param string $key     Setting key.
	 * @param array  $s       Settings.
	 * @param array  $choices value => label.
	 */
	private static function select( $key, $s, $choices ) {
		printf( '<select name="%s" id="%s">', esc_attr( self::name( $key ) ), esc_attr( self::id( $key ) ) );

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $s[ $key ], $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute.
				esc_html( $label )
			);
		}

		echo '</select>';
	}
}
