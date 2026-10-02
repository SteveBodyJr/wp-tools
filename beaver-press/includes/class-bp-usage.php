<?php
/**
 * What translation costs: usage per day and per run, and an estimate before a run.
 *
 * Every provider call adds the characters sent and, where the provider reports them, the
 * input and output tokens. Dollar amounts are only shown for models with a known price
 * (Claude models, from Anthropic's price list; others through the `beaver_press_prices`
 * filter), always labelled approximate.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Usage and estimates.
 */
final class BP_Usage {

	/** Owner's prices per model (not autoloaded). */
	const PRICES_OPTION = 'beaver_press_prices';

	/** Option: usage by day (KEEP_DAYS days) and for the current run. */
	const OPTION = 'beaver_press_usage';

	/** Days kept. */
	const KEEP_DAYS = 62; // Two months, so the monthly spending limit sees the whole month.

	/**
	 * USD per million tokens [input, output] for known models.
	 *
	 * @return array<string, array{0: float, 1: float}>
	 */
	public static function prices() {
		// Built in: Claude (Anthropic's published prices) and DeepSeek (peak-hour prices from
		// api-docs.deepseek.com, checked 2 Oct 2026; off-peak is half, so costs shown are an upper
		// limit; the old names are billed as Flash). Other models: the owner's own prices from
		// the Translate-site card (providers change them often), which also override these.
		$own = get_option( self::PRICES_OPTION, array() );
		return (array) apply_filters(
			'beaver_press_prices',
			array_merge(
				array(
					'claude-opus-5-5'   => array( 4.0, 20.0 ),
					'claude-sonnet-5-5' => array( 2.0, 10.0 ),
					'claude-haiku-4-5'  => array( 1.0, 5.0 ),
					'deepseek-flash'    => array( 0.3, 1.2 ),
					'deepseek-v4-flash' => array( 0.3, 1.2 ),
					'deepseek-chat'     => array( 0.3, 1.2 ),
					'deepseek-v4-pro'   => array( 1.32, 3.96 ),
				),
				is_array( $own ) ? $own : array()
			)
		);
	}

	/**
	 * Save the owner's price for a model (US dollars per million tokens, in and out).
	 *
	 * @param string $model Model ID.
	 * @param float  $in    Input price.
	 * @param float  $out   Output price.
	 */
	public static function set_price( $model, $in, $out ) {
		$own = get_option( self::PRICES_OPTION, array() );
		$own = is_array( $own ) ? $own : array();
		$model = sanitize_text_field( (string) $model );
		if ( '' === $model ) {
			return;
		}
		if ( $in <= 0 && $out <= 0 ) {
			unset( $own[ $model ] );
		} else {
			$own[ $model ] = array( max( 0, round( (float) $in, 4 ) ), max( 0, round( (float) $out, 4 ) ) );
		}
		update_option( self::PRICES_OPTION, $own, false );
	}

	/**
	 * Price for a model, or null when unknown.
	 *
	 * @param string $model Model ID.
	 * @return array{0: float, 1: float}|null
	 */
	public static function price( $model ) {
		$prices = self::prices();
		return isset( $prices[ $model ] ) ? array_map( 'floatval', $prices[ $model ] ) : null;
	}

	/**
	 * Record one provider call.
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model ID ('' for DeepL).
	 * @param int    $chars    Characters sent.
	 * @param int    $in       Input tokens reported (0 if none).
	 * @param int    $out      Output tokens reported (0 if none).
	 */
	public static function add( $provider, $model, $chars, $in = 0, $out = 0 ) {
		$data  = self::data();
		$price = self::price( (string) $model );
		$cost  = $price ? ( $in * $price[0] + $out * $price[1] ) / 1000000 : 0.0;
		$row   = array(
			'requests' => 1,
			'chars'    => (int) $chars,
			'in'       => (int) $in,
			'out'      => (int) $out,
			'cost'     => $cost,
		);

		$day                   = gmdate( 'Y-m-d' );
		$data['days'][ $day ]  = self::sum( $data['days'][ $day ] ?? array(), $row );
		$data['run']           = self::sum( $data['run'], $row );
		$data['run']['model']  = (string) $model;
		$data['run']['priced'] = null !== $price;

		ksort( $data['days'] );
		$data['days'] = array_slice( $data['days'], -self::KEEP_DAYS, null, true );
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Start counting a new run.
	 */
	public static function reset_run() {
		$data        = self::data();
		$data['run'] = self::empty_row() + array( 'started' => time() );
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Usage of the current run (read fresh: page requests write it).
	 *
	 * @return array
	 */
	public static function run() {
		return self::data( true )['run'];
	}

	/**
	 * Usage per day, newest first.
	 *
	 * @return array<string, array>
	 */
	public static function days() {
		return array_reverse( self::data( true )['days'], true );
	}

	/**
	 * Characters still to translate for a language, from TranslatePress's list of texts
	 * seen on the site (link and image addresses, phone links and symbols left out: they
	 * are never sent).
	 *
	 * @param string $language Language code.
	 * @return array{strings: int, chars: int}
	 */
	public static function untranslated( $language ) {
		global $wpdb;
		$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$text  = "original REGEXP '[A-Za-z]{2}' AND original NOT LIKE 'http%' AND original NOT LIKE 'tel:%' AND original NOT LIKE 'mailto:%'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from TranslatePress, fixed condition.
		$count = static fn( $sql ) => (array) $wpdb->get_row( "SELECT COUNT(*) AS strings, COALESCE(SUM(CHAR_LENGTH(original)), 0) AS chars FROM {$sql}", ARRAY_A );

		// Texts of the whole site (every language shares them) ...
		$originals = $query->get_table_name_for_original_strings();
		$all       = $originals === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $originals ) ) ? $count( "`{$originals}` WHERE {$text}" ) : array();

		// ... against what this language has: untranslated rows seen, and rows already translated.
		$table = $query->get_table_name( $language );
		$left  = array();
		$done  = array();
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$left = $count( "`{$table}` WHERE status = 0 AND {$text}" );
			$done = $count( "`{$table}` WHERE status <> 0 AND {$text}" );
		}
		// phpcs:enable

		// A language nobody has opened yet has no rows: count the site's texts not yet translated in it.
		return array(
			'strings' => max( (int) ( $left['strings'] ?? 0 ), (int) ( $all['strings'] ?? 0 ) - (int) ( $done['strings'] ?? 0 ) ),
			'chars'   => max( (int) ( $left['chars'] ?? 0 ), (int) ( $all['chars'] ?? 0 ) - (int) ( $done['chars'] ?? 0 ) ),
		);
	}

	/**
	 * Rough estimate for translating a language with the current engine settings.
	 *
	 * Tokens are about 4 characters of English; replies run about 25% longer in most target
	 * languages; each request also carries the instructions (~500 tokens). Thinking tokens
	 * some models add are not included.
	 *
	 * @param string $language Language code.
	 * @param array  $engine   Engine settings (BP_Engine_Settings::current()).
	 * @return array{strings: int, chars: int, requests: int, in: int, out: int, cost: float|null}
	 */
	public static function estimate( $language, array $engine ) {
		$left     = self::untranslated( $language );
		$requests = (int) ceil( $left['strings'] / max( 1, (int) $engine['chunk'] ) );
		if ( 'deepl' === $engine['api'] ) {
			return $left + array(
				'requests' => $requests,
				'in'       => 0,
				'out'      => 0,
				'cost'     => null,
			);
		}
		$text  = $left['chars'] / 4;
		$in    = (int) round( $text + $requests * 500 + $left['strings'] * 8 );
		$out   = (int) round( $text * 1.25 + $left['strings'] * 8 );
		$price = self::price( $engine['model'] );
		return $left + array(
			'requests' => $requests,
			'in'       => $in,
			'out'      => $out,
			'cost'     => $price ? ( $in * $price[0] + $out * $price[1] ) / 1000000 : null,
		);
	}

	/**
	 * TranslatePress's daily character limit and today's count (read fresh).
	 *
	 * @return array{limit: int, used: int}
	 */
	public static function daily_limit() {
		global $wpdb;
		// TranslatePress keeps today's count in its own option and resets it when the date in
		// its settings (local time, date()) is not today.
		$mt   = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'trp_machine_translation_settings' ) ) );
		$mt   = is_array( $mt ) ? $mt : array();
		$used = 0;
		if ( ( $mt['machine_translation_counter_date'] ?? '' ) === date( 'Y-m-d' ) ) { // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- same clock as TranslatePress.
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'trp_machine_translation_counter' ) );
		}
		return array(
			'limit' => (int) ( $mt['machine_translation_limit'] ?? 1000000 ),
			'used'  => $used,
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Stored data with defaults.
	 *
	 * @param bool $fresh Read the database, not this request's cache.
	 * @return array
	 */
	private static function data( $fresh = false ) {
		if ( $fresh ) {
			global $wpdb;
			$data = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ) );
		} else {
			$data = get_option( self::OPTION, array() );
		}
		$data = is_array( $data ) ? $data : array();
		return array(
			'days' => is_array( $data['days'] ?? null ) ? $data['days'] : array(),
			'run'  => wp_parse_args( is_array( $data['run'] ?? null ) ? $data['run'] : array(), self::empty_row() + array( 'started' => 0 ) ),
		);
	}

	/**
	 * Zero counters.
	 *
	 * @return array
	 */
	private static function empty_row() {
		return array(
			'requests' => 0,
			'chars'    => 0,
			'in'       => 0,
			'out'      => 0,
			'cost'     => 0.0,
			'model'    => '',
			'priced'   => false,
		);
	}

	/**
	 * Add a row to a total.
	 *
	 * @param array $total Total.
	 * @param array $row   Row.
	 * @return array
	 */
	private static function sum( array $total, array $row ) {
		$total = wp_parse_args( $total, self::empty_row() );
		foreach ( array( 'requests', 'chars', 'in', 'out' ) as $key ) {
			$total[ $key ] = (int) $total[ $key ] + (int) $row[ $key ];
		}
		$total['cost'] = (float) $total['cost'] + (float) $row['cost'];
		return $total;
	}
}
