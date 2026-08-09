<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * No API key ships with this plugin. Each site supplies its own, either in the
 * settings screen (stored in the database, server side only) or by defining
 * BAC_API_KEY in wp-config.php, which always wins and is never written to the
 * database or rendered back into any form.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Settings
 */
class BAC_Settings {

	/**
	 * Every setting with its default. Everything here is site-agnostic; nothing
	 * is specific to any one business, theme or post type.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(

			/* ---- Connection -------------------------------------------- */
			'enabled'          => 0,
			'provider'         => 'claude',
			'api_key'          => '',
			'model'            => '',
			'custom_endpoint'  => '',
			'max_tokens'       => 1024,
			'temperature'      => '',
			'claude_thinking'  => 'off',
			'timeout'          => 60,

			/* ---- Identity ---------------------------------------------- */
			'assistant'        => 'Aria',
			'tagline'          => 'Online now, here to help',
			'greeting'         => "Hi there! I'm {assistant} from {site}. Ask me anything, and if you share your name and email I can have the team follow up with details.",
			'avatar_url'       => '',
			'placeholder'      => 'Write your message…',
			'chips'            => "What do you offer?\nHow much does it cost?\nHow do I get in touch?",
			'nudge_enabled'    => 1,
			'nudge_text'       => 'Need a hand? Ask me anything.',
			'nudge_delay'      => 1800,
			'footer_note'      => 'Your details stay private and go straight to our team.',

			/* ---- What the assistant knows ------------------------------ */
			'business_name'    => '',
			'business_type'    => '',
			'business_desc'    => '',
			'tone'             => 'warm',
			'reply_length'     => 'about 2 to 5 sentences',
			'no_emoji'         => 1,
			'no_dashes'        => 0,
			'ask_contact'      => 1,
			'scope_lock'       => 1,
			'multilingual'     => 1,
			'context'          => '',
			'prompt_extra'     => '',
			'prompt_override'  => '',

			/* ---- Spend -------------------------------------------------- */
			'monthly_cap'      => 0,
			'price_in'         => '',
			'price_out'        => '',

			/* ---- Site knowledge ---------------------------------------- */
			'kb_enabled'       => 1,
			'kb_mode'          => 'relevant',
			'kb_top_k'         => 12,
			'kb_post_types'    => array( 'page' ),
			'kb_per_type'      => 20,
			'kb_taxonomies'    => array(),
			'kb_item_chars'    => 400,
			'kb_budget'        => 14000,
			'kb_cache_hours'   => 6,
			'kb_price_meta'    => 'price, from_price, _price, _regular_price',
			'kb_extra_meta'    => 'duration, location, sku',
			'kb_exclude'       => 'privacy-policy, terms, terms-and-conditions, terms-of-service, cookie-policy, disclaimer, cart, checkout, my-account',

			/* ---- Contact details & links ------------------------------- */
			'phone'            => '',
			'whatsapp'         => '',
			'contact_email'    => '',
			'links'            => '',

			/* ---- Leads & hand-off -------------------------------------- */
			'leads_enabled'    => 1,
			'lead_capture_mode' => 'all',
			'lead_ai_summary'  => 1,
			'cta_enabled'      => 1,
			'cta_label'        => 'Ask the team to contact me',
			'wa_enabled'       => 1,
			'wa_message'       => 'Hi! I was chatting with your assistant on your website and would like to continue.',

			/* ---- Team alerts ------------------------------------------- */
			'notify_enabled'      => 1,
			'notify_email'        => '',
			'notify_when'         => 'contact',
			'notify_timing'       => 'settled',
			'notify_delay'        => 5,
			'notify_min_turns'    => 2,
			'notify_transcript'   => 1,
			'notify_subject'      => '',
			'notify_digest_every' => 24,
			'notify_digest_hour'  => 8,
			'notify_digest_empty' => 0,
			'notify_share_links'  => 0,
			'notify_link_days'    => 7,

			/* ---- Where else alerts go ---------------------------------- */
			'slack_webhook'    => '',
			'telegram_token'   => '',
			'telegram_chat'    => '',
			'wa_token'         => '',
			'wa_phone_id'      => '',
			'wa_to'            => '',
			'wa_api_mode'      => 'template',
			'wa_template'      => '',
			'wa_language'      => 'en',
			'webhook_url'      => '',
			'webhook_secret'   => '',

			/* ---- Appearance -------------------------------------------- */
			'accent'           => '#1b7f5a',
			'secondary'        => '#c9a24a',
			'position'         => 'right',
			'theme'            => 'auto',
			'corner_style'     => 'rounded',
			'bubble_style'     => 'rounded',
			'mobile_display'   => 'fullscreen',
			'launcher_size'    => 64,
			'z_index'          => 99997,

			/* ---- Where it shows ---------------------------------------- */
			'display'          => 'all',
			'display_ids'      => '',
			'devices'          => 'all',
			'logged_in_only'   => 0,

			/* ---- Limits ------------------------------------------------ */
			'rate_limit'       => 30,
			'history_turns'    => 14,
			'msg_max_chars'    => 1500,
		);
	}

	/**
	 * Providers this plugin can talk to.
	 *
	 * `api` tells the dispatcher which wire format to use: Anthropic's Messages
	 * API, Google's generateContent, or the widely-cloned OpenAI chat/completions
	 * shape that OpenAI, DeepSeek, Groq, OpenRouter, Together and most local
	 * runtimes all speak.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'claude'     => array(
				'label'   => 'Claude (Anthropic)',
				'api'     => 'anthropic',
				'url'     => 'https://api.anthropic.com/v1/messages',
				'model'   => 'claude-opus-5',
				'alt'     => 'claude-sonnet-5 (balanced) or claude-haiku-4-5 (fastest and cheapest for busy chat)',
				'keys_at' => 'console.anthropic.com &rarr; API keys',
				'prefix'  => 'sk-ant-…',
			),
			'openai'     => array(
				'label'   => 'ChatGPT (OpenAI)',
				'api'     => 'openai',
				'url'     => 'https://api.openai.com/v1/chat/completions',
				'model'   => 'gpt-4o-mini',
				'alt'     => 'gpt-4o (higher quality)',
				'keys_at' => 'platform.openai.com/api-keys',
				'prefix'  => 'sk-…',
			),
			'deepseek'   => array(
				'label'   => 'DeepSeek',
				'api'     => 'openai',
				'url'     => 'https://api.deepseek.com/chat/completions',
				'model'   => 'deepseek-chat',
				'alt'     => 'deepseek-reasoner (deeper reasoning, slower)',
				'keys_at' => 'platform.deepseek.com &rarr; API keys',
				'prefix'  => 'sk-…',
			),
			'gemini'     => array(
				'label'   => 'Gemini (Google)',
				'api'     => 'gemini',
				'url'     => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'model'   => 'gemini-2.0-flash',
				'alt'     => 'gemini-2.0-pro (higher quality)',
				'keys_at' => 'aistudio.google.com/apikey',
				'prefix'  => 'AIza…',
			),
			'groq'       => array(
				'label'   => 'Groq',
				'api'     => 'openai',
				'url'     => 'https://api.groq.com/openai/v1/chat/completions',
				'model'   => 'llama-3.3-70b-versatile',
				'alt'     => 'any model listed in your Groq console',
				'keys_at' => 'console.groq.com/keys',
				'prefix'  => 'gsk_…',
			),
			'openrouter' => array(
				'label'   => 'OpenRouter',
				'api'     => 'openai',
				'url'     => 'https://openrouter.ai/api/v1/chat/completions',
				'model'   => 'openai/gpt-4o-mini',
				'alt'     => 'any model slug from openrouter.ai/models',
				'keys_at' => 'openrouter.ai/keys',
				'prefix'  => 'sk-or-…',
			),
			'custom'     => array(
				'label'   => 'Custom (OpenAI-compatible endpoint)',
				'api'     => 'openai',
				'url'     => '',
				'model'   => '',
				'alt'     => 'whatever your endpoint serves',
				'keys_at' => 'your own provider',
				'prefix'  => '',
			),
		);
	}

	/**
	 * Stored settings merged with the defaults, held for the length of one
	 * request so repeated reads do not re-merge the array. The filter below is
	 * still applied on every call, so anything that varies per page keeps
	 * working.
	 *
	 * @var array|null
	 */
	private static $raw = null;

	/** Keep the cache honest when the option changes by any route. */
	public static function init() {
		add_action( 'added_option', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'updated_option', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'deleted_option', array( __CLASS__, 'maybe_flush' ) );
	}

	/**
	 * Drop the cache when our own option is written.
	 *
	 * @param string $option Option name.
	 */
	public static function maybe_flush( $option ) {
		if ( BAC_OPTION === $option ) {
			self::flush();
		}
	}

	/** Drop the per-request cache. */
	public static function flush() {
		self::$raw = null;
	}

	/**
	 * Read the whole settings array, or one key.
	 *
	 * @param string|null $key     Optional single key.
	 * @param mixed       $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key = null, $default = null ) {
		if ( null === self::$raw ) {
			self::$raw = wp_parse_args( (array) get_option( BAC_OPTION, array() ), self::defaults() );
		}

		/**
		 * Filter the resolved settings array.
		 *
		 * @param array $opts Settings.
		 */
		$opts = apply_filters( 'bac_settings', self::$raw );

		if ( null === $key ) {
			return $opts;
		}

		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	/**
	 * The API key actually used for requests.
	 *
	 * A BAC_API_KEY constant in wp-config.php always wins, which lets you keep
	 * the key out of the database entirely and out of any settings export.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'BAC_API_KEY' ) && '' !== trim( (string) BAC_API_KEY ) ) {
			return trim( (string) BAC_API_KEY );
		}
		return trim( (string) self::get( 'api_key' ) );
	}

	/** Whether the key comes from wp-config.php rather than the database. */
	public static function key_is_constant() {
		return defined( 'BAC_API_KEY' ) && '' !== trim( (string) BAC_API_KEY );
	}

	/**
	 * Config for the selected provider, merged with any custom endpoint.
	 *
	 * @param array|null $s Settings (read fresh when omitted).
	 * @return array
	 */
	public static function provider_config( $s = null ) {
		$s         = null === $s ? self::get() : $s;
		$providers = self::providers();
		$key       = isset( $providers[ $s['provider'] ] ) ? $s['provider'] : 'claude';
		$cfg       = $providers[ $key ];
		$cfg['id'] = $key;

		if ( 'custom' === $key && '' !== trim( (string) $s['custom_endpoint'] ) ) {
			$cfg['url'] = trim( (string) $s['custom_endpoint'] );
		}

		$cfg['model'] = '' !== trim( (string) $s['model'] ) ? trim( (string) $s['model'] ) : $cfg['model'];

		return $cfg;
	}

	/**
	 * Sanitise on save.
	 *
	 * A blank API key field preserves the stored key, so the key never has to be
	 * retyped and is never echoed back into the form.
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$def = self::defaults();
		$out = array();

		/* ---- Connection ------------------------------------------------ */
		$out['enabled'] = empty( $in['enabled'] ) ? 0 : 1;

		$provider         = isset( $in['provider'] ) ? sanitize_key( $in['provider'] ) : 'claude';
		$out['provider']  = array_key_exists( $provider, self::providers() ) ? $provider : 'claude';

		$key = isset( $in['api_key'] ) ? trim( wp_unslash( $in['api_key'] ) ) : '';
		if ( '' === $key ) {
			$key = (string) self::get( 'api_key' ); // Preserve what is already stored.
		}
		$out['api_key'] = sanitize_text_field( $key );

		$out['model']           = isset( $in['model'] ) ? sanitize_text_field( $in['model'] ) : '';
		$out['custom_endpoint'] = isset( $in['custom_endpoint'] ) ? esc_url_raw( trim( (string) $in['custom_endpoint'] ) ) : '';
		$out['max_tokens']      = self::clamp_int( $in, 'max_tokens', 128, 8192, $def['max_tokens'] );
		$out['timeout']         = self::clamp_int( $in, 'timeout', 10, 180, $def['timeout'] );

		$temp = isset( $in['temperature'] ) ? trim( (string) $in['temperature'] ) : '';
		if ( '' === $temp || ! is_numeric( $temp ) ) {
			$out['temperature'] = '';
		} else {
			$out['temperature'] = (string) max( 0, min( 2, (float) $temp ) );
		}

		$thinking               = isset( $in['claude_thinking'] ) ? sanitize_key( $in['claude_thinking'] ) : 'off';
		$out['claude_thinking'] = in_array( $thinking, array( 'off', 'adaptive', 'default' ), true ) ? $thinking : 'off';

		/* ---- Identity -------------------------------------------------- */
		$out['assistant']     = self::text_or_default( $in, 'assistant', $def['assistant'] );
		$out['tagline']       = self::text( $in, 'tagline' );
		$out['greeting']      = self::textarea_or_default( $in, 'greeting', $def['greeting'] );
		$out['avatar_url']    = isset( $in['avatar_url'] ) ? esc_url_raw( trim( (string) $in['avatar_url'] ) ) : '';
		$out['placeholder']   = self::text_or_default( $in, 'placeholder', $def['placeholder'] );
		$out['chips']         = self::textarea( $in, 'chips' );
		$out['nudge_enabled'] = empty( $in['nudge_enabled'] ) ? 0 : 1;
		$out['nudge_text']    = self::text( $in, 'nudge_text' );
		$out['nudge_delay']   = self::clamp_int( $in, 'nudge_delay', 0, 120000, $def['nudge_delay'] );
		$out['footer_note']   = self::text( $in, 'footer_note' );

		/* ---- Brain ------------------------------------------------------ */
		$out['business_name'] = self::text( $in, 'business_name' );
		$out['business_type'] = self::text( $in, 'business_type' );
		$out['business_desc'] = self::textarea( $in, 'business_desc' );

		$tone        = isset( $in['tone'] ) ? sanitize_key( $in['tone'] ) : 'warm';
		$out['tone'] = array_key_exists( $tone, self::tones() ) ? $tone : 'warm';

		$out['reply_length']    = self::text_or_default( $in, 'reply_length', $def['reply_length'] );
		$out['no_emoji']        = empty( $in['no_emoji'] ) ? 0 : 1;
		$out['no_dashes']       = empty( $in['no_dashes'] ) ? 0 : 1;
		$out['ask_contact']     = empty( $in['ask_contact'] ) ? 0 : 1;
		$out['scope_lock']      = empty( $in['scope_lock'] ) ? 0 : 1;
		$out['multilingual']    = empty( $in['multilingual'] ) ? 0 : 1;
		$out['context']         = self::textarea( $in, 'context' );
		$out['prompt_extra']    = self::textarea( $in, 'prompt_extra' );
		$out['prompt_override'] = self::textarea( $in, 'prompt_override' );

		/* ---- Spend ------------------------------------------------------ */
		$cap                  = isset( $in['monthly_cap'] ) ? trim( (string) $in['monthly_cap'] ) : '';
		$out['monthly_cap']   = ( '' === $cap || ! is_numeric( $cap ) ) ? 0 : max( 0, round( (float) $cap, 2 ) );
		$out['price_in']      = self::decimal( isset( $in['price_in'] ) ? $in['price_in'] : '' );
		$out['price_out']     = self::decimal( isset( $in['price_out'] ) ? $in['price_out'] : '' );

		/* ---- Knowledge -------------------------------------------------- */
		$out['kb_enabled'] = empty( $in['kb_enabled'] ) ? 0 : 1;

		$mode           = isset( $in['kb_mode'] ) ? sanitize_key( $in['kb_mode'] ) : 'relevant';
		$out['kb_mode'] = in_array( $mode, array( 'relevant', 'all' ), true ) ? $mode : 'relevant';

		$out['kb_top_k'] = self::clamp_int( $in, 'kb_top_k', 1, 60, $def['kb_top_k'] );

		$types = isset( $in['kb_post_types'] ) ? (array) $in['kb_post_types'] : array();
		$out['kb_post_types'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );

		$taxes = isset( $in['kb_taxonomies'] ) ? (array) $in['kb_taxonomies'] : array();
		$out['kb_taxonomies'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $taxes ) ) ) );

		$out['kb_per_type']     = self::clamp_int( $in, 'kb_per_type', 1, 200, $def['kb_per_type'] );
		$out['kb_item_chars']   = self::clamp_int( $in, 'kb_item_chars', 80, 4000, $def['kb_item_chars'] );
		$out['kb_budget']       = self::clamp_int( $in, 'kb_budget', 1000, 60000, $def['kb_budget'] );
		$out['kb_cache_hours']  = self::clamp_int( $in, 'kb_cache_hours', 0, 168, $def['kb_cache_hours'] );
		$out['kb_price_meta']   = self::text( $in, 'kb_price_meta' );
		$out['kb_extra_meta']   = self::text( $in, 'kb_extra_meta' );
		$out['kb_exclude']      = self::textarea( $in, 'kb_exclude' );

		/* ---- Contact ---------------------------------------------------- */
		$out['phone']         = self::text( $in, 'phone' );
		$out['whatsapp']      = self::text( $in, 'whatsapp' );
		$out['contact_email'] = isset( $in['contact_email'] ) ? sanitize_email( $in['contact_email'] ) : '';
		$out['links']         = self::textarea( $in, 'links' );

		/* ---- Leads ------------------------------------------------------ */
		$out['leads_enabled'] = empty( $in['leads_enabled'] ) ? 0 : 1;

		$mode                        = isset( $in['lead_capture_mode'] ) ? sanitize_key( $in['lead_capture_mode'] ) : 'all';
		$out['lead_capture_mode']    = in_array( $mode, array( 'all', 'contact' ), true ) ? $mode : 'all';
		$out['lead_ai_summary']      = empty( $in['lead_ai_summary'] ) ? 0 : 1;
		$out['cta_enabled']     = empty( $in['cta_enabled'] ) ? 0 : 1;
		$out['cta_label']       = self::text_or_default( $in, 'cta_label', $def['cta_label'] );
		$out['wa_enabled']      = empty( $in['wa_enabled'] ) ? 0 : 1;
		$out['wa_message']      = self::text_or_default( $in, 'wa_message', $def['wa_message'] );

		/* ---- Team alerts ------------------------------------------------- */
		$out['notify_enabled'] = empty( $in['notify_enabled'] ) ? 0 : 1;
		$out['notify_email']   = self::emails( isset( $in['notify_email'] ) ? $in['notify_email'] : '' );

		$when               = isset( $in['notify_when'] ) ? sanitize_key( $in['notify_when'] ) : 'contact';
		$out['notify_when'] = in_array( $when, array( 'contact', 'all' ), true ) ? $when : 'contact';

		$timing               = isset( $in['notify_timing'] ) ? sanitize_key( $in['notify_timing'] ) : 'settled';
		$out['notify_timing'] = in_array( $timing, array( 'instant', 'settled', 'digest' ), true ) ? $timing : 'settled';

		$out['notify_delay']      = self::clamp_int( $in, 'notify_delay', 1, 240, $def['notify_delay'] );
		$out['notify_min_turns']  = self::clamp_int( $in, 'notify_min_turns', 1, 20, $def['notify_min_turns'] );
		$out['notify_transcript'] = empty( $in['notify_transcript'] ) ? 0 : 1;
		$out['notify_subject']    = self::text( $in, 'notify_subject' );

		$every                       = isset( $in['notify_digest_every'] ) ? (int) $in['notify_digest_every'] : 24;
		$out['notify_digest_every']  = in_array( $every, array( 1, 4, 12, 24 ), true ) ? $every : 24;
		$out['notify_digest_hour']   = self::clamp_int( $in, 'notify_digest_hour', 0, 23, $def['notify_digest_hour'] );
		$out['notify_digest_empty']  = empty( $in['notify_digest_empty'] ) ? 0 : 1;
		$out['notify_share_links']   = empty( $in['notify_share_links'] ) ? 0 : 1;
		$out['notify_link_days']     = self::clamp_int( $in, 'notify_link_days', 1, 90, $def['notify_link_days'] );

		/* ---- Where else alerts go ---------------------------------------- */
		$out['slack_webhook']  = isset( $in['slack_webhook'] ) ? esc_url_raw( trim( (string) $in['slack_webhook'] ) ) : '';
		$out['telegram_token'] = self::text( $in, 'telegram_token' );
		$out['telegram_chat']  = self::text( $in, 'telegram_chat' );
		$out['wa_token']       = self::text( $in, 'wa_token' );
		$out['wa_phone_id']    = preg_replace( '/[^\d]/', '', self::text( $in, 'wa_phone_id' ) );
		$out['wa_to']          = implode( ', ', BAC_Channels::numbers( isset( $in['wa_to'] ) ? $in['wa_to'] : '' ) );

		$wa_mode              = isset( $in['wa_api_mode'] ) ? sanitize_key( $in['wa_api_mode'] ) : 'template';
		$out['wa_api_mode']   = in_array( $wa_mode, array( 'template', 'text' ), true ) ? $wa_mode : 'template';
		$out['wa_template']   = preg_replace( '/[^a-z0-9_]/', '', strtolower( self::text( $in, 'wa_template' ) ) );
		$out['wa_language']   = preg_replace( '/[^a-zA-Z_]/', '', self::text( $in, 'wa_language' ) );
		$out['wa_language']   = '' === $out['wa_language'] ? $def['wa_language'] : $out['wa_language'];

		$out['webhook_url']    = isset( $in['webhook_url'] ) ? esc_url_raw( trim( (string) $in['webhook_url'] ) ) : '';
		$out['webhook_secret'] = self::text( $in, 'webhook_secret' );

		/* ---- Appearance ------------------------------------------------- */
		$out['accent']        = self::hex( isset( $in['accent'] ) ? $in['accent'] : '', $def['accent'] );
		$out['secondary']     = self::hex( isset( $in['secondary'] ) ? $in['secondary'] : '', $def['secondary'] );
		$position             = isset( $in['position'] ) ? sanitize_key( $in['position'] ) : 'right';
		$out['position']      = in_array( $position, array( 'right', 'left' ), true ) ? $position : 'right';
		$theme                = isset( $in['theme'] ) ? sanitize_key( $in['theme'] ) : 'auto';
		$out['theme']         = in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ? $theme : 'auto';

		$corner               = isset( $in['corner_style'] ) ? sanitize_key( $in['corner_style'] ) : 'rounded';
		$out['corner_style']  = array_key_exists( $corner, self::corner_styles() ) ? $corner : 'rounded';

		$bubble               = isset( $in['bubble_style'] ) ? sanitize_key( $in['bubble_style'] ) : 'rounded';
		$out['bubble_style']  = array_key_exists( $bubble, self::bubble_styles() ) ? $bubble : 'rounded';

		$mobile                 = isset( $in['mobile_display'] ) ? sanitize_key( $in['mobile_display'] ) : 'fullscreen';
		$out['mobile_display']  = in_array( $mobile, array( 'fullscreen', 'panel' ), true ) ? $mobile : 'fullscreen';

		$out['launcher_size'] = self::clamp_int( $in, 'launcher_size', 44, 96, $def['launcher_size'] );
		$out['z_index']       = self::clamp_int( $in, 'z_index', 1, 2147483000, $def['z_index'] );

		/* ---- Visibility -------------------------------------------------- */
		$display            = isset( $in['display'] ) ? sanitize_key( $in['display'] ) : 'all';
		$out['display']     = in_array( $display, array( 'all', 'include', 'exclude' ), true ) ? $display : 'all';
		$out['display_ids'] = self::text( $in, 'display_ids' );
		$devices            = isset( $in['devices'] ) ? sanitize_key( $in['devices'] ) : 'all';
		$out['devices']     = in_array( $devices, array( 'all', 'desktop', 'mobile' ), true ) ? $devices : 'all';
		$out['logged_in_only'] = empty( $in['logged_in_only'] ) ? 0 : 1;

		/* ---- Limits ------------------------------------------------------ */
		$out['rate_limit']    = self::clamp_int( $in, 'rate_limit', 1, 500, $def['rate_limit'] );
		$out['history_turns'] = self::clamp_int( $in, 'history_turns', 2, 40, $def['history_turns'] );
		$out['msg_max_chars'] = self::clamp_int( $in, 'msg_max_chars', 200, 8000, $def['msg_max_chars'] );

		// Anything the assistant knows may have changed, so rebuild on next use.
		self::flush();
		BAC_Knowledge::flush_cache();

		/**
		 * Filter sanitised settings just before they are saved.
		 *
		 * @param array $out Clean settings.
		 * @param array $in  Raw input.
		 */
		return apply_filters( 'bac_sanitize_settings', $out, $in );
	}

	/**
	 * Message-bubble shape, kept separate from the window.
	 *
	 * Squaring the chat window is a common brand choice; squaring the speech
	 * bubbles inside it rarely is, so the two are controlled independently and
	 * bubbles stay rounded by default whatever the window does.
	 *
	 * @return array
	 */
	public static function bubble_styles() {
		return array(
			'rounded' => array(
				'label'  => __( 'Rounded (recommended)', 'beaver-ai-chat' ),
				'radius' => '16px',
				'tail'   => '5px',
			),
			'soft'    => array(
				'label'  => __( 'Slightly rounded', 'beaver-ai-chat' ),
				'radius' => '7px',
				'tail'   => '3px',
			),
			'square'  => array(
				'label'  => __( 'Square', 'beaver-ai-chat' ),
				'radius' => '0px',
				'tail'   => '0px',
			),
			'match'   => array(
				'label'  => __( 'Match the chat window', 'beaver-ai-chat' ),
				'radius' => '',
				'tail'   => '',
			),
		);
	}

	/**
	 * The bubble shape currently in use, resolved against the window when the
	 * admin has chosen to match it.
	 *
	 * @param array|null $s Settings.
	 * @return array array( radius, tail )
	 */
	public static function bubbles( $s = null ) {
		$s      = null === $s ? self::get() : $s;
		$styles = self::bubble_styles();
		$key    = isset( $styles[ $s['bubble_style'] ] ) ? $s['bubble_style'] : 'rounded';

		if ( 'match' === $key ) {
			$corner = self::corners( $s );

			return array(
				'radius' => $corner['bubble'],
				'tail'   => ( '0px' === $corner['bubble'] ) ? '0px' : '5px',
			);
		}

		return array(
			'radius' => $styles[ $key ]['radius'],
			'tail'   => $styles[ $key ]['tail'],
		);
	}

	/**
	 * Corner treatments for the chat window and its controls: the panel itself,
	 * the buttons, the inputs and the chips.
	 *
	 * The floating chat button is deliberately not included: it stays a circle
	 * whatever the window does, because that is what reads as "chat".
	 *
	 * Message bubbles are deliberately NOT driven from here, see bubble_styles().
	 *
	 * @return array
	 */
	public static function corner_styles() {
		return array(
			'pill'    => array(
				'label'    => __( 'Pill - very rounded', 'beaver-ai-chat' ),
				'panel'    => '28px',
				'bubble'   => '20px',
				'control'  => '999px',
				'button'   => '999px',
				'pillish'  => '999px',
			),
			'rounded' => array(
				'label'    => __( 'Rounded (recommended)', 'beaver-ai-chat' ),
				'panel'    => '20px',
				'bubble'   => '16px',
				'control'  => '14px',
				'button'   => '13px',
				'pillish'  => '999px',
			),
			'soft'    => array(
				'label'    => __( 'Soft - barely rounded', 'beaver-ai-chat' ),
				'panel'    => '8px',
				'bubble'   => '7px',
				'control'  => '6px',
				'button'   => '6px',
				'pillish'  => '6px',
			),
			'square'  => array(
				'label'    => __( 'Square - no rounded corners', 'beaver-ai-chat' ),
				'panel'    => '0px',
				'bubble'   => '0px',
				'control'  => '0px',
				'button'   => '0px',
				'pillish'  => '0px',
			),
		);
	}

	/**
	 * The corner set currently in use.
	 *
	 * @param array|null $s Settings.
	 * @return array
	 */
	public static function corners( $s = null ) {
		$s      = null === $s ? self::get() : $s;
		$styles = self::corner_styles();
		$key    = isset( $styles[ $s['corner_style'] ] ) ? $s['corner_style'] : 'rounded';

		return $styles[ $key ];
	}

	/** Selectable tones, and the prompt fragment each one produces. */
	public static function tones() {
		return array(
			'warm'         => array(
				'label'  => 'Warm and friendly',
				'prompt' => 'Warm, friendly and genuinely helpful, like a knowledgeable person who enjoys helping.',
			),
			'professional' => array(
				'label'  => 'Professional',
				'prompt' => 'Polished, professional and precise, without being stiff or cold.',
			),
			'casual'       => array(
				'label'  => 'Casual and relaxed',
				'prompt' => 'Relaxed and conversational, plain spoken and easy going.',
			),
			'luxury'       => array(
				'label'  => 'Refined and premium',
				'prompt' => 'Refined, calm and understated, the way a concierge at a fine hotel would speak.',
			),
			'expert'       => array(
				'label'  => 'Expert and direct',
				'prompt' => 'Direct and expert. Lead with the answer, then add only the detail that changes what the visitor should do next.',
			),
		);
	}

	/**
	 * Keys that are credentials rather than configuration. These never leave
	 * the site in an export and are never overwritten by an import, so a config
	 * file can be shared or pasted without carrying a secret with it.
	 *
	 * @return array
	 */
	public static function secrets() {
		return array( 'api_key', 'slack_webhook', 'telegram_token', 'wa_token', 'webhook_url', 'webhook_secret' );
	}

	/**
	 * Settings safe to export or import between sites.
	 *
	 * @return array
	 */
	public static function exportable() {
		$s = self::get();

		foreach ( self::secrets() as $key ) {
			unset( $s[ $key ] );
		}

		return $s;
	}

	/**
	 * Merge an imported config over the current one, ignoring unknown keys and
	 * never touching the stored API key.
	 *
	 * @param array $incoming Decoded JSON.
	 * @return int Number of settings applied.
	 */
	public static function import( $incoming ) {
		if ( ! is_array( $incoming ) ) {
			return 0;
		}

		$current = self::get();
		$known   = self::defaults();
		$secrets = self::secrets();
		$applied = 0;

		foreach ( $incoming as $k => $v ) {
			if ( in_array( $k, $secrets, true ) || ! array_key_exists( $k, $known ) ) {
				continue;
			}
			$current[ $k ] = $v;
			$applied++;
		}

		if ( $applied > 0 ) {
			update_option( BAC_OPTION, self::sanitize( $current ) );
		}

		return $applied;
	}

	/* ---------------------------------------------------------------------
	 * Small sanitising helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitise a plain text field.
	 *
	 * @param array  $in  Input.
	 * @param string $key Key.
	 * @return string
	 */
	private static function text( $in, $key ) {
		return isset( $in[ $key ] ) ? sanitize_text_field( wp_unslash( $in[ $key ] ) ) : '';
	}

	/**
	 * Sanitise a text field, falling back to a default when blank.
	 *
	 * @param array  $in      Input.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	private static function text_or_default( $in, $key, $default ) {
		$v = self::text( $in, $key );
		return '' === trim( $v ) ? $default : $v;
	}

	/**
	 * Sanitise a textarea.
	 *
	 * @param array  $in  Input.
	 * @param string $key Key.
	 * @return string
	 */
	private static function textarea( $in, $key ) {
		return isset( $in[ $key ] ) ? sanitize_textarea_field( wp_unslash( $in[ $key ] ) ) : '';
	}

	/**
	 * Sanitise a textarea, falling back to a default when blank.
	 *
	 * @param array  $in      Input.
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	private static function textarea_or_default( $in, $key, $default ) {
		$v = self::textarea( $in, $key );
		return '' === trim( $v ) ? $default : $v;
	}

	/**
	 * Clamp an integer field into range.
	 *
	 * @param array  $in      Input.
	 * @param string $key     Key.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @param int    $default Default when missing or non numeric.
	 * @return int
	 */
	private static function clamp_int( $in, $key, $min, $max, $default ) {
		if ( ! isset( $in[ $key ] ) || '' === trim( (string) $in[ $key ] ) || ! is_numeric( $in[ $key ] ) ) {
			return (int) $default;
		}
		return (int) max( $min, min( $max, (int) $in[ $key ] ) );
	}

	/**
	 * A positive decimal, or an empty string when nothing usable was typed.
	 * Kept as a string so "not set" and "set to zero" stay different things.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function decimal( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return '';
		}

		return (string) round( (float) $raw, 4 );
	}

	/**
	 * Normalise a hex colour, with a fallback.
	 *
	 * @param string $value   Raw value.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function hex( $value, $default ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $default;
		}
		$clean = sanitize_hex_color( '#' === substr( $value, 0, 1 ) ? $value : '#' . $value );
		return $clean ? $clean : $default;
	}

	/**
	 * Sanitise a comma separated list of email addresses.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private static function emails( $raw ) {
		$parts = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		$clean = array();
		foreach ( $parts as $p ) {
			$email = sanitize_email( $p );
			if ( $email && is_email( $email ) ) {
				$clean[] = $email;
			}
		}
		return implode( ', ', array_unique( $clean ) );
	}

	/**
	 * Split a comma or newline separated string into a clean list.
	 *
	 * @param string $raw Raw value.
	 * @return array
	 */
	public static function to_list( $raw ) {
		$parts = preg_split( '/[\r\n,]+/', (string) $raw );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}
}
