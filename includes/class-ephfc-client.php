<?php
/**
 * Ephemeris API client.
 *
 * Sends only numbers: one daily unit-sales series per product, plus the horizon and quantiles.
 * No product names, SKUs, prices, customer or order details leave the store.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wp_remote_post.
 */
class EPHFC_Client {

	const MAX_SERIES = 64;

	/**
	 * Forecast up to 64 series.
	 *
	 * @param string  $api_key   Bearer key.
	 * @param array[] $series    List of float[] (oldest first).
	 * @param int     $horizon   Days ahead.
	 * @param float[] $quantiles Quantile levels.
	 * @return array|WP_Error ['forecasts' => list of [qkey => float[]], 'billing_mc' => int|null]
	 */
	public static function forecast( $api_key, array $series, $horizon, array $quantiles ) {
		if ( '' === (string) $api_key ) {
			return new WP_Error( 'ephfc_no_key', __( 'Add your Ephemeris API key in Forecasts > Settings.', 'ephemeris-forecasts-for-woocommerce' ) );
		}
		if ( count( $series ) < 1 || count( $series ) > self::MAX_SERIES ) {
			return new WP_Error( 'ephfc_batch', 'Batch must hold 1 to 64 series.' );
		}

		$payload = array();
		foreach ( $series as $values ) {
			$values    = array_values( array_map( 'floatval', $values ) );
			$payload[] = array(
				'values' => $values,
				'freq'   => 'D',
			);
		}

		$body = array(
			'mode'      => 'route',
			'series'    => $payload,
			'horizon'   => (int) $horizon,
			'quantiles' => array_map( 'floatval', $quantiles ),
		);
		$body['idempotency_key'] = EPHFC_Math::idempotency_key( $body );

		$response = wp_remote_post(
			/**
			 * Filter the forecast endpoint.
			 *
			 * @param string $url Endpoint URL.
			 */
			apply_filters( 'ephfc_api_url', EPHFC_API_URL ),
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'EphemerisForecastsWoo/' . EPHFC_VERSION . '; ' . home_url( '/' ),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'ephfc_auth', __( 'Ephemeris rejected the API key. Check it in Forecasts > Settings.', 'ephemeris-forecasts-for-woocommerce' ) );
		}
		if ( 402 === $code ) {
			return new WP_Error( 'ephfc_credits', __( 'Your Ephemeris account is out of credits.', 'ephemeris-forecasts-for-woocommerce' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error'] ) ? ( is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] ) ) : '';
			/* translators: 1: HTTP status code, 2: error message from the API. */
			return new WP_Error( 'ephfc_http', sprintf( __( 'Ephemeris returned HTTP %1$d. %2$s', 'ephemeris-forecasts-for-woocommerce' ), $code, $msg ) );
		}

		$forecasts = EPHFC_Math::parse_forecasts( $data, count( $series ) );
		if ( null === $forecasts ) {
			return new WP_Error( 'ephfc_shape', __( 'Unexpected response from Ephemeris.', 'ephemeris-forecasts-for-woocommerce' ) );
		}

		$billing = null;
		if ( isset( $data['meta']['billing']['settled_mc'] ) ) {
			$billing = (int) $data['meta']['billing']['settled_mc'];
		}

		return array(
			'forecasts'  => $forecasts,
			'billing_mc' => $billing,
		);
	}
}
