<?php
/**
 * Spending limit in money: stop sending text to the provider once this day's or this
 * month's cost (UTC days, as in the usage table) reaches the owner's limit.
 *
 * Costs come from BP_Usage (tokens x the model's price), so the limit only works for priced
 * models; the card says so when the model has no price. DeepL bills characters and has no
 * price here: its own monthly quota (and the engine's character limit) applies instead.
 *
 * Checked before every request in BP_Providers::request_json(). A stopped request is an
 * ordinary provider error with the code bp_budget: runs stop (BP_Run::FATAL), the daily
 * top-up waits, and the alert notice and email say why. It clears itself with the first
 * request after the day or month turns, or when the owner raises the limit.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Budget.
 */
final class BP_Budget {

	/** Limits in US dollars: [ 'day' => float, 'month' => float ] (0 = none). */
	const OPTION = 'beaver_press_budget';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bp_budget', array( __CLASS__, 'save' ) );
	}

	/**
	 * The owner's limits.
	 *
	 * @return array{day: float, month: float}
	 */
	public static function limits() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'day'   => max( 0.0, (float) ( $saved['day'] ?? 0 ) ),
			'month' => max( 0.0, (float) ( $saved['month'] ?? 0 ) ),
		);
	}

	/**
	 * Spent today and this month (UTC), at known prices.
	 *
	 * @return array{day: float, month: float}
	 */
	public static function spent() {
		$today = gmdate( 'Y-m-d' );
		$month = gmdate( 'Y-m' );
		$out   = array(
			'day'   => 0.0,
			'month' => 0.0,
		);
		foreach ( BP_Usage::days() as $day => $row ) {
			$cost = (float) ( $row['cost'] ?? 0 );
			if ( $day === $today ) {
				$out['day'] += $cost;
			}
			if ( 0 === strpos( (string) $day, $month ) ) {
				$out['month'] += $cost;
			}
		}
		return $out;
	}

	/**
	 * Why sending must stop now, or ''.
	 *
	 * @return string
	 */
	public static function reached() {
		$limits = self::limits();
		if ( $limits['day'] <= 0 && $limits['month'] <= 0 ) {
			return '';
		}
		$spent = self::spent();
		if ( $limits['day'] > 0 && $spent['day'] >= $limits['day'] ) {
			/* translators: %s: amount in US dollars */
			return sprintf( __( 'The spending limit of $%s per day is reached; translation starts again tomorrow (UTC).', 'beaver-press' ), self::money( $limits['day'] ) );
		}
		if ( $limits['month'] > 0 && $spent['month'] >= $limits['month'] ) {
			/* translators: %s: amount in US dollars */
			return sprintf( __( 'The spending limit of $%s per month is reached; translation starts again next month.', 'beaver-press' ), self::money( $limits['month'] ) );
		}
		return '';
	}

	/**
	 * Amount for people.
	 *
	 * @param float $amount US dollars.
	 * @return string
	 */
	public static function money( $amount ) {
		return number_format_i18n( (float) $amount, (float) $amount < 10 ? 2 : 0 );
	}

	/**
	 * The form, under the price on the Translate-site card.
	 *
	 * @param array $engine Engine settings.
	 */
	public static function render( array $engine ) {
		$limits = self::limits();
		$spent  = self::spent();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-price bp-budget">
			<input type="hidden" name="action" value="bp_budget" />
			<?php wp_nonce_field( 'bp_budget' ); ?>
			<span><?php esc_html_e( 'Stop at (US$, 0 = no limit):', 'beaver-press' ); ?></span>
			<label><input type="number" name="budget_day" min="0" step="0.01" value="<?php echo esc_attr( $limits['day'] > 0 ? (string) $limits['day'] : '0' ); ?>" class="small-text" /> <?php esc_html_e( 'per day', 'beaver-press' ); ?></label>
			<label><input type="number" name="budget_month" min="0" step="0.01" value="<?php echo esc_attr( $limits['month'] > 0 ? (string) $limits['month'] : '0' ); ?>" class="small-text" /> <?php esc_html_e( 'per month', 'beaver-press' ); ?></label>
			<button class="button button-small"><?php esc_html_e( 'Save limit', 'beaver-press' ); ?></button>
			<span class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: spent today, 2: spent this month */
						__( 'Spent $%1$s today, $%2$s this month (UTC, at known prices). Runs, the daily top-up and the AI buttons stop when a limit is reached.', 'beaver-press' ),
						number_format_i18n( $spent['day'], 2 ),
						number_format_i18n( $spent['month'], 2 )
					)
				);
				if ( 'deepl' === $engine['api'] ) {
					echo ' ' . esc_html__( 'DeepL bills characters, not tokens: this limit does not apply to it; use its monthly quota and the daily character limit.', 'beaver-press' );
				} elseif ( null === BP_Usage::price( (string) $engine['model'] ) ) {
					/* translators: %s: model */
					echo ' <strong>' . esc_html( sprintf( __( 'No price is known for %s, so its cost counts as $0: enter its price above for the limit to work.', 'beaver-press' ), $engine['model'] ) ) . '</strong>';
				}
				?>
			</span>
		</form>
		<?php
	}

	/**
	 * Save the limits.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_budget' );
		update_option(
			self::OPTION,
			array(
				'day'   => max( 0.0, round( (float) ( $_POST['budget_day'] ?? 0 ), 2 ) ),
				'month' => max( 0.0, round( (float) ( $_POST['budget_month'] ?? 0 ), 2 ) ),
			),
			false
		);
		// A raised limit: let the next request try instead of waiting on the old stop.
		$error = BP_Providers::last_error();
		if ( $error && 'bp_budget' === ( $error['code'] ?? '' ) && '' === self::reached() ) {
			delete_option( BP_Providers::ERROR_OPTION );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '#bp-run' ) );
		exit;
	}
}
