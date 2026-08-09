<?php
/**
 * What the assistant is costing, and the ceiling it must not go past.
 *
 * Every provider reports how many tokens a call used. This records those
 * numbers per day and per model, prices them, and lets a site set a monthly
 * ceiling. With a bring-your-own key, an unnoticed runaway is the thing that
 * actually hurts, and it is invisible until the provider's invoice arrives.
 *
 * Prices are estimates kept in one table here, overridable per site and
 * filterable in code, because published rates change and a plugin that hard
 * codes them quietly goes wrong. Tokens are always counted exactly; only the
 * money is an estimate, and the screen says so.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Usage
 */
class BAC_Usage {

	/** Option holding the rolling record. */
	const OPTION = 'bac_usage';

	/** Option remembering that we already warned about the ceiling. */
	const CAPPED = 'bac_cap_notified';

	/** How many days of history to keep. */
	const DAYS = 62;

	/**
	 * The most recent call in this request, so the conversation it belongs to
	 * can be charged for it without the provider needing to know about leads.
	 *
	 * @var array|null
	 */
	private static $last = null;

	/**
	 * Record one provider call.
	 *
	 * @param string $model Model that served it.
	 * @param int    $in    Input tokens.
	 * @param int    $out   Output tokens.
	 */
	public static function record( $model, $in, $out ) {
		$in  = max( 0, (int) $in );
		$out = max( 0, (int) $out );

		if ( 0 === $in && 0 === $out ) {
			return; // The provider told us nothing; recording zeroes would lie.
		}

		$model = sanitize_text_field( (string) $model );
		$cost  = self::cost( $model, $in, $out );

		self::$last = array(
			'model' => $model,
			'in'    => $in,
			'out'   => $out,
			'cost'  => $cost,
		);

		$data  = self::data();
		$today = current_time( 'Y-m-d' );
		$month = current_time( 'Y-m' );

		// A new month starts the per-model breakdown over; the daily history
		// keeps running so the chart still spans the month boundary.
		if ( $month !== $data['month'] ) {
			$data['month']  = $month;
			$data['models'] = array();
			delete_option( self::CAPPED );
		}

		foreach ( array( 'days' => $today, 'models' => $model ) as $bucket => $key ) {
			if ( ! isset( $data[ $bucket ][ $key ] ) ) {
				$data[ $bucket ][ $key ] = array(
					'calls' => 0,
					'in'    => 0,
					'out'   => 0,
					'cost'  => 0.0,
				);
			}

			$data[ $bucket ][ $key ]['calls']++;
			$data[ $bucket ][ $key ]['in']  += $in;
			$data[ $bucket ][ $key ]['out'] += $out;
			$data[ $bucket ][ $key ]['cost'] = round( $data[ $bucket ][ $key ]['cost'] + $cost, 6 );
		}

		// Keep the option from growing without limit.
		if ( count( $data['days'] ) > self::DAYS ) {
			krsort( $data['days'] );
			$data['days'] = array_slice( $data['days'], 0, self::DAYS, true );
			ksort( $data['days'] );
		}

		update_option( self::OPTION, $data, false );
	}

	/**
	 * The usage from the most recent call in this request.
	 *
	 * @return array|null array( model, in, out, cost )
	 */
	public static function last() {
		return self::$last;
	}

	/**
	 * The stored record, with every key present.
	 *
	 * @return array
	 */
	public static function data() {
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();

		return wp_parse_args(
			$data,
			array(
				'days'   => array(),
				'models' => array(),
				'month'  => current_time( 'Y-m' ),
			)
		);
	}

	/** Wipe the record. */
	public static function reset() {
		delete_option( self::OPTION );
		delete_option( self::CAPPED );
	}

	/* ------------------------------------------------------------------ Money */

	/**
	 * Estimated price per million tokens, by model.
	 *
	 * Anthropic's own rates are the ones this plugin defaults to and are kept
	 * accurate here. The rest are indicative: providers change them, and some
	 * endpoints are self-hosted and free. Set your own on the Connection tab,
	 * or filter this list.
	 *
	 * @return array model => array( in, out )
	 */
	public static function prices() {
		$prices = array(
			/* Anthropic */
			'claude-opus-5'             => array( 5.00, 25.00 ),
			'claude-opus-4-8'           => array( 5.00, 25.00 ),
			'claude-sonnet-5'           => array( 3.00, 15.00 ),
			'claude-haiku-4-5'          => array( 1.00, 5.00 ),
			'claude-fable-5'            => array( 10.00, 50.00 ),

			/* OpenAI */
			'gpt-4o-mini'               => array( 0.15, 0.60 ),
			'gpt-4o'                    => array( 2.50, 10.00 ),

			/* DeepSeek */
			'deepseek-chat'             => array( 0.28, 0.42 ),
			'deepseek-reasoner'         => array( 0.55, 2.19 ),

			/* Google */
			'gemini-2.0-flash'          => array( 0.10, 0.40 ),
			'gemini-2.0-pro'            => array( 1.25, 5.00 ),

			/* Groq */
			'llama-3.3-70b-versatile'   => array( 0.59, 0.79 ),
		);

		/**
		 * Filter the price table used to estimate spend.
		 *
		 * @param array $prices model => array( input per million, output per million ).
		 */
		return (array) apply_filters( 'bac_model_prices', $prices );
	}

	/**
	 * The rate for one model: the site's own override first, then the table,
	 * matching on an exact id and then on a prefix so a dated model name still
	 * finds its family.
	 *
	 * @param string $model Model id.
	 * @return array|null array( in, out ) per million, or null when unknown.
	 */
	public static function rate( $model ) {
		$model = strtolower( trim( (string) $model ) );
		if ( '' === $model ) {
			return null;
		}

		$in  = (float) BAC_Settings::get( 'price_in', 0 );
		$out = (float) BAC_Settings::get( 'price_out', 0 );

		if ( $in > 0 || $out > 0 ) {
			return array( $in, $out );
		}

		$prices = self::prices();

		if ( isset( $prices[ $model ] ) ) {
			return $prices[ $model ];
		}

		foreach ( $prices as $id => $rate ) {
			if ( 0 === strpos( $model, strtolower( $id ) ) ) {
				return $rate;
			}
		}

		return null;
	}

	/**
	 * Estimated cost of one call.
	 *
	 * @param string $model Model id.
	 * @param int    $in    Input tokens.
	 * @param int    $out   Output tokens.
	 * @return float
	 */
	public static function cost( $model, $in, $out ) {
		$rate = self::rate( $model );

		if ( ! $rate ) {
			return 0.0;
		}

		return round( ( $in / 1000000 ) * $rate[0] + ( $out / 1000000 ) * $rate[1], 6 );
	}

	/**
	 * Spend so far this month.
	 *
	 * @return float
	 */
	public static function month_spend() {
		$data  = self::data();
		$month = current_time( 'Y-m' );
		$total = 0.0;

		foreach ( $data['days'] as $day => $row ) {
			if ( 0 === strpos( $day, $month ) ) {
				$total += (float) $row['cost'];
			}
		}

		return round( $total, 4 );
	}

	/**
	 * Totals for the last N days.
	 *
	 * @param int $days How many days back, including today.
	 * @return array day => array( calls, in, out, cost ), oldest first.
	 */
	public static function recent( $days = 14 ) {
		$data = self::data();
		$out  = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $i . ' days' ) );

			$out[ $day ] = isset( $data['days'][ $day ] )
				? $data['days'][ $day ]
				: array(
					'calls' => 0,
					'in'    => 0,
					'out'   => 0,
					'cost'  => 0.0,
				);
		}

		return $out;
	}

	/* ------------------------------------------------------------------- Cap */

	/**
	 * Whether this month's spend has reached the ceiling.
	 *
	 * @param array|null $s Settings.
	 * @return bool
	 */
	public static function cap_reached( $s = null ) {
		$s   = null === $s ? BAC_Settings::get() : $s;
		$cap = (float) $s['monthly_cap'];

		if ( $cap <= 0 ) {
			return false;
		}

		return self::month_spend() >= $cap;
	}

	/**
	 * Tell the site once that the ceiling was reached, and log it. Called from
	 * the chat endpoint the first time a visitor is turned away.
	 *
	 * @param array $s Settings.
	 */
	public static function announce_cap( $s ) {
		if ( get_option( self::CAPPED, false ) ) {
			return;
		}

		update_option( self::CAPPED, current_time( 'mysql' ), false );

		$to = BAC_Notify::recipients( $s );
		if ( empty( $to ) ) {
			return;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		wp_mail(
			implode( ', ', $to ),
			/* translators: %s: site name. */
			sprintf( __( 'The chat assistant on %s has reached its monthly spend limit', 'beaver-ai-chat' ), $site ),
			sprintf(
				/* translators: 1: amount spent, 2: the limit, 3: settings URL. */
				__( "The assistant has spent about %1\$s this month, which is the limit set for it, so it has stopped answering and is pointing visitors at your contact details instead.\n\nRaise or remove the limit here: %3\$s\n\nLimit: %2\$s", 'beaver-ai-chat' ),
				self::money( self::month_spend() ),
				self::money( (float) $s['monthly_cap'] ),
				admin_url( 'admin.php?page=beaver-ai-chat#connection' )
			)
		);
	}

	/**
	 * Format an amount for display.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		$amount = (float) $amount;
		$dp     = ( $amount > 0 && $amount < 1 ) ? 4 : 2;

		return '$' . number_format_i18n( $amount, $dp );
	}

	/**
	 * Pull token counts out of a provider response body.
	 *
	 * Each of the three wire formats reports usage under its own key, and any
	 * of them may leave it out entirely, in which case nothing is recorded
	 * rather than a zero being invented.
	 *
	 * @param array  $data Decoded response body.
	 * @param string $api  anthropic, openai or gemini.
	 * @return array array( in, out )
	 */
	public static function read( $data, $api ) {
		$in  = 0;
		$out = 0;

		if ( 'gemini' === $api ) {
			$meta = isset( $data['usageMetadata'] ) ? $data['usageMetadata'] : array();
			$in   = isset( $meta['promptTokenCount'] ) ? (int) $meta['promptTokenCount'] : 0;
			$out  = isset( $meta['candidatesTokenCount'] ) ? (int) $meta['candidatesTokenCount'] : 0;

			// Reasoning tokens are billed as output but reported separately.
			if ( isset( $meta['thoughtsTokenCount'] ) ) {
				$out += (int) $meta['thoughtsTokenCount'];
			}

			return array( $in, $out );
		}

		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

		if ( 'anthropic' === $api ) {
			$in  = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
			$out = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;

			// Cached reads are cheaper than fresh input, but counting them as
			// input keeps the estimate on the safe side rather than under.
			foreach ( array( 'cache_read_input_tokens', 'cache_creation_input_tokens' ) as $key ) {
				if ( isset( $usage[ $key ] ) ) {
					$in += (int) $usage[ $key ];
				}
			}

			return array( $in, $out );
		}

		$in  = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
		$out = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;

		return array( $in, $out );
	}
}
