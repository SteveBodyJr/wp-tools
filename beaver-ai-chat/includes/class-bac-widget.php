<?php
/**
 * Front end widget: visibility rules, assets, markup and runtime config.
 *
 * The API key is never enqueued and never appears in page source. The browser
 * receives only what it needs to draw the chat and call this site's own REST
 * endpoint.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Widget
 */
class BAC_Widget {

	/** Set when the shortcode has rendered, so the footer widget stands down. */
	private static $rendered = false;

	/** Wire up hooks. */
	public static function init() {
		add_shortcode( 'beaver_ai_chat', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Priority 5 so the markup is in the document before WordPress prints
		// footer scripts at priority 20. The script waits for DOMContentLoaded
		// regardless, but this keeps the source order sane.
		add_action( 'wp_footer', array( __CLASS__, 'render_footer' ), 5 );
	}

	/**
	 * Should the chat appear on the current request?
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}

		$s = BAC_Settings::get();

		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( ! empty( $s['logged_in_only'] ) && ! is_user_logged_in() ) {
			return false;
		}

		if ( 'all' !== $s['display'] ) {
			$ids     = array_map( 'absint', BAC_Settings::to_list( $s['display_ids'] ) );
			$current = (int) get_queried_object_id();
			$listed  = in_array( $current, $ids, true );

			if ( 'include' === $s['display'] && ! $listed ) {
				return false;
			}
			if ( 'exclude' === $s['display'] && $listed ) {
				return false;
			}
		}

		/**
		 * Filter whether the chat renders on this request.
		 *
		 * @param bool  $show Whether to show.
		 * @param array $s    Settings.
		 */
		return (bool) apply_filters( 'bac_should_show', true, $s );
	}

	/**
	 * Register and enqueue the widget assets.
	 *
	 * Nothing here is allowed to hold up the page. The stylesheet is fetched
	 * without blocking rendering, the script is deferred so it never blocks the
	 * parser, and a small inline shell paints the launcher correctly in the
	 * meantime so nothing jumps once the full stylesheet lands.
	 */
	public static function enqueue() {
		if ( ! self::should_show() ) {
			return;
		}

		$s = BAC_Settings::get();

		wp_enqueue_style( 'beaver-ai-chat', BAC_URL . 'assets/css/chat.css', array(), BAC_VERSION );
		wp_add_inline_style( 'beaver-ai-chat', self::critical_css( $s ) . self::inline_css( $s ) );

		wp_enqueue_script( 'beaver-ai-chat', BAC_URL . 'assets/js/chat.js', array(), BAC_VERSION, true );
		wp_localize_script( 'beaver-ai-chat', 'BAC_CONFIG', self::config( $s ) );

		if ( self::async_assets() ) {
			add_filter( 'style_loader_tag', array( __CLASS__, 'async_style_tag' ), 10, 2 );
			add_filter( 'script_loader_tag', array( __CLASS__, 'defer_script_tag' ), 10, 2 );
		}
	}

	/**
	 * Whether to load the assets without blocking. An optimisation plugin that
	 * already handles this can switch it off.
	 *
	 * @return bool
	 */
	private static function async_assets() {
		/**
		 * Filter whether the widget assets load without blocking rendering.
		 *
		 * @param bool $async Default true.
		 */
		return (bool) apply_filters( 'bac_async_assets', true );
	}

	/**
	 * Fetch our stylesheet without blocking the first paint, with a noscript
	 * fallback so it still applies when JavaScript is off.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public static function async_style_tag( $tag, $handle ) {
		if ( 'beaver-ai-chat' !== $handle ) {
			return $tag;
		}

		$async = str_replace(
			"media='all'",
			"media='print' onload=\"this.media='all';this.onload=null;\"",
			$tag
		);

		// Older or filtered markup may use double quotes for the attribute.
		$async = str_replace(
			'media="all"',
			'media="print" onload="this.media=\'all\';this.onload=null;"',
			$async
		);

		if ( $async === $tag ) {
			return $tag; // Unexpected markup, leave it alone rather than break it.
		}

		return $async . '<noscript>' . $tag . '</noscript>' . "\n";
	}

	/**
	 * Defer our script so it never blocks parsing.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function defer_script_tag( $tag, $handle ) {
		if ( 'beaver-ai-chat' !== $handle || false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	/**
	 * The few rules needed to paint the launcher correctly before the full
	 * stylesheet arrives. Kept deliberately tiny; it ships inline, so it costs
	 * no request and cannot block anything.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function critical_css( $s ) {
		if ( ! self::async_assets() ) {
			return '';
		}

		$side = ( 'left' === $s['position'] ) ? 'left' : 'right';

		return '#bac-root{position:fixed;bottom:24px;' . $side . ':24px;}'
			. '#bac-root.bac-inline{position:static;}'
			. '#bac-launch{position:relative;width:var(--bac-launcher);height:var(--bac-launcher);'
			. 'border:0;padding:0;border-radius:var(--bac-radius-launcher);cursor:pointer;color:#fff;'
			. 'display:grid;place-items:center;'
			. 'background:linear-gradient(155deg,var(--bac-accent-light),var(--bac-accent) 52%,var(--bac-accent-dark));}'
			. '#bac-launch .bac-launch-close{opacity:0;position:absolute;}'
			. '#bac-launch .bac-launch-icon{position:absolute;}';
	}

	/**
	 * Runtime configuration handed to the browser. Contains no secrets.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	private static function config( $s ) {
		$assistant = BAC_Prompt::tokens( $s['assistant'], $s );

		$config = array(
			'rest'        => esc_url_raw( rest_url( BAC_Rest::NAMESPACE_V1 . '/chat' ) ),
			'contactRest' => esc_url_raw( rest_url( BAC_Rest::NAMESPACE_V1 . '/contact' ) ),
			'token'       => BAC_Rest::token(),
			'assistant'   => $assistant,
			'initial'     => mb_strtoupper( mb_substr( $assistant, 0, 1 ) ),
			'avatar'      => $s['avatar_url'],
			'tagline'     => BAC_Prompt::tokens( $s['tagline'], $s ),
			'greeting'    => BAC_Prompt::tokens( $s['greeting'], $s ),
			'placeholder' => $s['placeholder'],
			'footerNote'  => BAC_Prompt::tokens( $s['footer_note'], $s ),
			// One per line, so a question may contain a comma.
			'chips'       => array_slice(
				array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $s['chips'] ) ), 'strlen' ) ),
				0,
				6
			),
			'historyTurns' => (int) $s['history_turns'],
			'nudge'       => ! empty( $s['nudge_enabled'] ) ? BAC_Prompt::tokens( $s['nudge_text'], $s ) : '',
			'nudgeDelay'  => (int) $s['nudge_delay'],
			// The hand-off needs a stored lead to attach itself to, so it is
			// only offered when lead capture is on.
			'cta'         => ( ! empty( $s['cta_enabled'] ) && ! empty( $s['leads_enabled'] ) ) ? BAC_Prompt::tokens( $s['cta_label'], $s ) : '',
			'whatsapp'    => ! empty( $s['wa_enabled'] ) ? preg_replace( '/\D/', '', (string) $s['whatsapp'] ) : '',
			'waMessage'   => BAC_Prompt::tokens( $s['wa_message'], $s ),
			'i18n'        => array(
				'open'      => __( 'Open chat', 'beaver-ai-chat' ),
				'close'     => __( 'Minimise chat', 'beaver-ai-chat' ),
				'send'      => __( 'Send', 'beaver-ai-chat' ),
				'end'       => __( 'End chat', 'beaver-ai-chat' ),
				'restart'   => __( 'Start a new chat', 'beaver-ai-chat' ),
				'dismiss'   => __( 'Dismiss', 'beaver-ai-chat' ),
				'message'   => __( 'Message', 'beaver-ai-chat' ),
				'whatsapp'  => __( 'WhatsApp', 'beaver-ai-chat' ),
				'sending'   => __( 'Sending…', 'beaver-ai-chat' ),
				'requested' => __( 'Request sent', 'beaver-ai-chat' ),
				'farewell'  => __( 'Thank you for chatting with us. Whenever you are ready, our team is here to help.', 'beaver-ai-chat' ),
				'offline'   => __( 'I could not reach the server. Please check your connection and try again.', 'beaver-ai-chat' ),
				'failed'    => __( 'Sorry, I could not reply just then. Please try again.', 'beaver-ai-chat' ),
			),
		);

		/**
		 * Filter the configuration passed to the browser. Never add secrets here.
		 *
		 * @param array $config Public config.
		 * @param array $s      Settings.
		 */
		return (array) apply_filters( 'bac_widget_config', $config, $s );
	}

	/**
	 * Theme tokens and layout rules derived from the settings.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function inline_css( $s ) {
		$accent    = $s['accent'];
		$secondary = $s['secondary'];
		$side      = ( 'left' === $s['position'] ) ? 'left' : 'right';
		$corner    = BAC_Settings::corners( $s );
		$bubble    = BAC_Settings::bubbles( $s );

		$css = '#bac-root{'
			. '--bac-accent:' . $accent . ';'
			. '--bac-accent-dark:' . self::shade( $accent, -0.34 ) . ';'
			. '--bac-accent-deep:' . self::shade( $accent, -0.62 ) . ';'
			. '--bac-accent-light:' . self::shade( $accent, 0.2 ) . ';'
			. '--bac-accent-rgb:' . self::rgb( $accent ) . ';'
			. '--bac-secondary:' . $secondary . ';'
			. '--bac-secondary-rgb:' . self::rgb( $secondary ) . ';'
			. '--bac-radius:' . $corner['panel'] . ';'
			. '--bac-radius-bubble:' . $bubble['radius'] . ';'
			. '--bac-radius-bubble-tail:' . $bubble['tail'] . ';'
			. '--bac-radius-control:' . $corner['control'] . ';'
			. '--bac-radius-button:' . $corner['button'] . ';'
			. '--bac-radius-pill:' . $corner['pillish'] . ';'
			/* The floating chat button stays a circle whatever the window does.
			   A round button is what people recognise as "chat"; squaring it
			   reads as a stray box rather than a control. */
			. '--bac-radius-launcher:50%;'
			. '--bac-launcher:' . (int) $s['launcher_size'] . 'px;'
			. 'z-index:' . (int) $s['z_index'] . ';'
			. '}';

		/* Nothing else is squared off here. The corner setting shapes the chat
		   window and its controls only: message bubbles follow their own
		   setting, and the chat button and the little prompt bubble beside it
		   stay rounded because both read as speech, not as chrome. */

		if ( 'left' === $side ) {
			$css .= '#bac-root{right:auto;left:24px;}'
				. '#bac-panel,#bac-root .bac-nudge{right:auto;left:0;transform-origin:bottom left;}'
				. '@media(max-width:480px){#bac-root{right:auto;left:14px;}}';
		}

		if ( 'desktop' === $s['devices'] ) {
			$css .= '@media(max-width:781px){#bac-root{display:none!important;}}';
		} elseif ( 'mobile' === $s['devices'] ) {
			$css .= '@media(min-width:782px){#bac-root{display:none!important;}}';
		}

		return $css;
	}

	/**
	 * "r, g, b" for a hex colour, so the stylesheet can build translucent
	 * shadows and glows from the site's own accent.
	 *
	 * @param string $hex Hex colour.
	 * @return string
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '27, 127, 90';
		}

		return implode(
			', ',
			array(
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
			)
		);
	}

	/**
	 * Lighten or darken a hex colour.
	 *
	 * @param string $hex    Hex colour.
	 * @param float  $amount -1 to 1.
	 * @return string
	 */
	private static function shade( $hex, $amount ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#' . $hex;
		}

		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$channel = hexdec( substr( $hex, $i * 2, 2 ) );
			$channel = ( $amount < 0 )
				? (int) round( $channel * ( 1 + $amount ) )
				: (int) round( $channel + ( 255 - $channel ) * $amount );
			$out    .= str_pad( dechex( max( 0, min( 255, $channel ) ) ), 2, '0', STR_PAD_LEFT );
		}

		return $out;
	}

	/**
	 * Inline chat panel, for dropping into a page or a contact template.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		if ( self::$rendered || ! self::should_show() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'height' => '520',
			),
			$atts,
			'beaver_ai_chat'
		);

		self::$rendered = true;

		ob_start();
		echo '<div class="bac-embed" style="--bac-embed-height:' . absint( $atts['height'] ) . 'px;">';
		self::markup( true );
		echo '</div>';

		return ob_get_clean();
	}

	/** Floating widget in the footer, unless the shortcode already drew one. */
	public static function render_footer() {
		if ( self::$rendered || ! self::should_show() ) {
			return;
		}

		self::$rendered = true;
		self::markup( false );
	}

	/**
	 * The widget shell. JS fills in the text, so nothing here is site specific.
	 *
	 * @param bool $inline Render as an embedded panel rather than a launcher.
	 */
	private static function markup( $inline ) {
		$s = BAC_Settings::get();

		$classes = array();
		if ( $inline ) {
			$classes[] = 'bac-inline';
			$classes[] = 'bac-open';
		}
		if ( 'light' === $s['theme'] ) {
			$classes[] = 'bac-force-light';
		} elseif ( 'dark' === $s['theme'] ) {
			$classes[] = 'bac-force-dark';
		}

		// On a phone the chat takes the whole screen unless the admin opts out.
		// An embedded panel is never full screen: it belongs in the page.
		if ( ! $inline && 'panel' !== $s['mobile_display'] ) {
			$classes[] = 'bac-fullscreen';
		}

		$labels = array(
			'open'    => esc_attr__( 'Open chat', 'beaver-ai-chat' ),
			'close'   => esc_attr__( 'Minimise chat', 'beaver-ai-chat' ),
			'dismiss' => esc_attr__( 'Dismiss', 'beaver-ai-chat' ),
			'send'    => esc_attr__( 'Send', 'beaver-ai-chat' ),
			'message' => esc_attr__( 'Message', 'beaver-ai-chat' ),
			'dialog'  => esc_attr__( 'Chat assistant', 'beaver-ai-chat' ),
		);
		?>
<div id="bac-root" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-bac-inline="<?php echo $inline ? '1' : '0'; ?>">

	<?php if ( ! $inline ) : ?>
	<div class="bac-nudge" hidden>
		<button type="button" class="bac-nudge-open" data-bac-open>
			<span class="bac-nudge-av" data-bac-avatar></span>
			<span class="bac-nudge-text" data-bac-nudge-text></span>
		</button>
		<button type="button" class="bac-nudge-x" data-bac-nudge-close aria-label="<?php echo $labels['dismiss']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
			<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
		</button>
	</div>

	<button id="bac-launch" type="button" aria-expanded="false" aria-controls="bac-panel" aria-label="<?php echo $labels['open']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
		<span class="bac-launch-icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="27" height="27" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-8.5 8.5 8.6 8.6 0 0 1-3.9-.9L3 21l1.9-5.6A8.4 8.4 0 0 1 4 11.5 8.38 8.38 0 0 1 12.5 3 8.38 8.38 0 0 1 21 11.5z"/></svg>
		</span>
		<span class="bac-launch-close" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
		</span>
		<span class="bac-badge" aria-hidden="true"></span>
	</button>
	<?php endif; ?>

	<div id="bac-panel" role="dialog" aria-label="<?php echo $labels['dialog']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" <?php echo $inline ? '' : 'hidden'; ?>>
		<header class="bac-head">
			<span class="bac-head-glow" aria-hidden="true"></span>
			<span class="bac-avatar" data-bac-avatar></span>
			<span class="bac-id">
				<span class="bac-name" data-bac-name></span>
				<span class="bac-status" data-bac-tagline></span>
			</span>
			<span class="bac-actions">
				<button type="button" class="bac-end" data-bac-end></button>
				<?php if ( ! $inline ) : ?>
				<button type="button" class="bac-min" data-bac-close aria-label="<?php echo $labels['close']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
				</button>
				<?php endif; ?>
			</span>
		</header>

		<div class="bac-log" data-bac-log role="log" aria-live="polite" aria-atomic="false"></div>
		<div class="bac-chips" data-bac-chips></div>

		<div class="bac-cta" data-bac-cta hidden>
			<button type="button" class="bac-btn bac-btn-primary" data-bac-contact>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
				<span data-bac-contact-label></span>
			</button>
			<a class="bac-btn bac-btn-wa" data-bac-wa href="#" target="_blank" rel="noopener nofollow" hidden>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.13c-.24.68-1.42 1.32-1.95 1.36-.5.04-.5.4-3.15-.66-2.66-1.05-4.31-3.77-4.44-3.95-.13-.18-1.06-1.41-1.06-2.68 0-1.27.67-1.9.9-2.16.24-.26.53-.32.7-.32.18 0 .35 0 .5.01.16.01.38-.06.59.45.24.55.8 1.9.87 2.04.07.13.11.29.02.47-.09.18-.13.29-.26.45-.13.16-.28.35-.4.47-.13.13-.27.28-.12.53.16.26.7 1.15 1.5 1.86 1.03.92 1.9 1.2 2.16 1.34.26.13.4.11.55-.07.16-.18.63-.73.8-.99.16-.26.33-.21.55-.13.22.08 1.4.66 1.64.78.24.13.4.18.46.29.06.11.06.63-.18 1.31z"/></svg>
				<span data-bac-wa-label></span>
			</a>
		</div>

		<form class="bac-compose" data-bac-form>
			<textarea data-bac-input rows="1" aria-label="<?php echo $labels['message']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"></textarea>
			<button type="submit" class="bac-send" aria-label="<?php echo $labels['send']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
			</button>
		</form>

		<button type="button" class="bac-restart" data-bac-restart></button>
		<p class="bac-foot" data-bac-foot></p>
	</div>
</div>
		<?php
	}
}
