<?php
/**
 * Plugin settings.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the single settings option.
 */
class EPHFC_Settings {

	const OPTION = 'ephfc_settings';

	/**
	 * Defaults for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'        => '',
			'lead_time'      => 14,
			'review_period'  => 7,
			'cover_quantile' => '0.9',
			'history_days'   => 365,
			'min_days'       => 28,
			'schedule'       => 'weekly',
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Days forecast ahead: lead time plus the time until the next order.
	 *
	 * @return int
	 */
	public static function horizon() {
		return max( 1, (int) self::get( 'lead_time' ) + (int) self::get( 'review_period' ) );
	}

	/**
	 * Sanitise callback for register_setting.
	 *
	 * @param mixed $input Raw form values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$out     = $current;

		if ( isset( $input['api_key'] ) ) {
			$key = trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) );
			// An empty field keeps the saved key, so the key never has to be shown again.
			if ( '' !== $key ) {
				$out['api_key'] = $key;
			}
		}
		if ( ! empty( $input['clear_api_key'] ) ) {
			$out['api_key'] = '';
		}

		$out['lead_time']     = self::clamp_int( $input, 'lead_time', 1, 180, $current['lead_time'] );
		$out['review_period'] = self::clamp_int( $input, 'review_period', 0, 90, $current['review_period'] );
		$out['history_days']  = self::clamp_int( $input, 'history_days', 56, 1095, $current['history_days'] );
		$out['min_days']      = self::clamp_int( $input, 'min_days', 7, 365, $current['min_days'] );

		$quantiles = array( '0.8', '0.9', '0.95' );
		if ( isset( $input['cover_quantile'] ) && in_array( $input['cover_quantile'], $quantiles, true ) ) {
			$out['cover_quantile'] = $input['cover_quantile'];
		}

		$schedules = array( 'daily', 'weekly', 'manual' );
		if ( isset( $input['schedule'] ) && in_array( $input['schedule'], $schedules, true ) ) {
			$out['schedule'] = $input['schedule'];
		}

		return $out;
	}

	/**
	 * Integer from input, clamped to a range.
	 *
	 * @param array  $input    Raw values.
	 * @param string $key      Field.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param int    $fallback Value when missing.
	 * @return int
	 */
	private static function clamp_int( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return (int) $fallback;
		}
		return min( $max, max( $min, (int) $input[ $key ] ) );
	}
}
