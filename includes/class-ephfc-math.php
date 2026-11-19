<?php
/**
 * Pure functions: series building, response parsing and reorder maths.
 * No WordPress calls here, so it can be unit-tested on its own.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
class EPHFC_Math {

	/**
	 * Quantile as the decimal string the API keys its response by.
	 *
	 * @param float|string $q Quantile, e.g. 0.9.
	 * @return string e.g. "0.9".
	 */
	public static function qkey( $q ) {
		$s = rtrim( rtrim( sprintf( '%.4F', (float) $q ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * Turn sparse day => units rows into a dense daily series.
	 * Days with no row are days with no recorded sales (0); days before the first sale are not padded.
	 *
	 * @param array  $by_day Map of 'Y-m-d' => units.
	 * @param string $end    Last day to include ('Y-m-d').
	 * @return array List of floats, oldest first. Empty if no sales.
	 */
	public static function dense_series( array $by_day, $end ) {
		if ( empty( $by_day ) ) {
			return array();
		}
		ksort( $by_day );
		$first = new DateTimeImmutable( (string) array_key_first( $by_day ) );
		$last  = new DateTimeImmutable( $end );
		$out   = array();
		for ( $d = $first; $d <= $last; $d = $d->modify( '+1 day' ) ) {
			$key   = $d->format( 'Y-m-d' );
			$out[] = isset( $by_day[ $key ] ) ? max( 0.0, (float) $by_day[ $key ] ) : 0.0;
		}
		return $out;
	}

	/**
	 * Stable idempotency key for an API body, so a retry replays instead of billing twice.
	 *
	 * @param array $body Request body without the key.
	 * @return string
	 */
	public static function idempotency_key( array $body ) {
		return 'wc-' . substr( hash( 'sha256', wp_json_encode( $body ) ), 0, 40 );
	}

	/**
	 * Pull per-series quantile arrays out of an Ephemeris response.
	 *
	 * @param mixed $data     Decoded JSON.
	 * @param int   $expected Number of series sent.
	 * @return array|null List of [quantile_string => float[]], or null if the shape is unexpected.
	 */
	public static function parse_forecasts( $data, $expected ) {
		if ( ! is_array( $data ) || ! isset( $data['forecasts'] ) || ! is_array( $data['forecasts'] ) ) {
			return null;
		}
		if ( count( $data['forecasts'] ) !== (int) $expected ) {
			return null;
		}
		$out = array();
		foreach ( $data['forecasts'] as $f ) {
			if ( ! is_array( $f ) ) {
				return null;
			}
			$q = ( isset( $f['quantiles'] ) && is_array( $f['quantiles'] ) ) ? $f['quantiles'] : $f;
			$clean = array();
			foreach ( $q as $k => $vals ) {
				if ( is_array( $vals ) ) {
					$clean[ self::qkey( $k ) ] = array_map( 'floatval', array_values( $vals ) );
				}
			}
			if ( ! isset( $clean['0.5'] ) ) {
				return null;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Reorder suggestion for one item.
	 *
	 * The cover figure adds up per-day upper quantiles: a cautious buffer, not a calibrated
	 * interval for total demand.
	 *
	 * @param array      $fc      [quantile_string => float[]].
	 * @param int        $window  Days to cover (lead time + review period).
	 * @param string     $cover_q Quantile key for the buffer, e.g. "0.9".
	 * @param float|null $stock   Units on hand, or null if not tracked.
	 * @return array expected, cover, days_of_cover (null if no demand), reorder_qty.
	 */
	public static function suggestion( array $fc, $window, $cover_q, $stock ) {
		$window   = max( 1, (int) $window );
		$median   = array_slice( $fc['0.5'], 0, $window );
		$upper    = isset( $fc[ $cover_q ] ) ? array_slice( $fc[ $cover_q ], 0, $window ) : $median;
		$expected = max( 0.0, array_sum( array_map( array( __CLASS__, 'nonneg' ), $median ) ) );
		$cover    = max( $expected, array_sum( array_map( array( __CLASS__, 'nonneg' ), $upper ) ) );
		$daily    = $expected / $window;

		// Without stock tracking there is nothing to compare against: report demand only.
		if ( null === $stock ) {
			return array(
				'expected'      => round( $expected, 1 ),
				'cover'         => round( $cover, 1 ),
				'days_of_cover' => null,
				'reorder_qty'   => 0,
			);
		}

		// Negative stock (backorders) counts against the position, so it raises the order.
		$position = (float) $stock;

		return array(
			'expected'      => round( $expected, 1 ),
			'cover'         => round( $cover, 1 ),
			'days_of_cover' => $daily > 0 ? round( max( 0.0, $position ) / $daily, 1 ) : null,
			// Round before ceil so float noise (12.0000001) doesn't add a unit.
			'reorder_qty'   => (int) max( 0, ceil( round( $cover - $position, 6 ) ) ),
		);
	}

	/**
	 * Clamp negatives to zero (forecasts of unit sales can dip below 0).
	 *
	 * @param float $v Value.
	 * @return float
	 */
	public static function nonneg( $v ) {
		return max( 0.0, (float) $v );
	}
}
