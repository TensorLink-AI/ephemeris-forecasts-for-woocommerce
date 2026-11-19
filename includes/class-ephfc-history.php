<?php
/**
 * Daily unit sales per product from WooCommerce Analytics lookup tables.
 *
 * Reading wc_order_product_lookup + wc_order_stats keeps the plugin independent of how orders
 * are stored (posts or HPOS tables).
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sales history queries.
 */
class EPHFC_History {

	/**
	 * Order statuses counted as sales.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		/**
		 * Filter the order statuses counted as demand.
		 *
		 * @param string[] $statuses Status slugs with the wc- prefix.
		 */
		return (array) apply_filters( 'ephfc_order_statuses', array( 'wc-completed', 'wc-processing' ) );
	}

	/**
	 * Window of days to read: [start, end] in the site's timezone, ending yesterday.
	 *
	 * @param int $days History length.
	 * @return array{0:string,1:string}
	 */
	public static function window( $days ) {
		$today = current_datetime()->setTime( 0, 0 );
		$end   = $today->modify( '-1 day' );
		$start = $today->modify( '-' . max( 1, (int) $days ) . ' days' );
		return array( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
	}

	/**
	 * Whether the Analytics lookup table exists and has any rows.
	 *
	 * @return bool
	 */
	public static function has_analytics_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );
	}

	/**
	 * Products and variations that sold in the window, with how far back their sales go.
	 *
	 * @param int $days     History length.
	 * @param int $min_days Minimum span from first sale to yesterday.
	 * @return array[] Each: product_id, variation_id, first_day.
	 */
	public static function items( $days, $min_days ) {
		global $wpdb;
		list( $start, $end ) = self::window( $days );
		$statuses = self::statuses();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge(
			array( $wpdb->prefix . 'wc_order_product_lookup', $wpdb->prefix . 'wc_order_stats', $start . ' 00:00:00', self::until( $end ) ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in is only %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id, l.variation_id, MIN(DATE(l.date_created)) AS first_day
				 FROM %i l
				 INNER JOIN %i s ON s.order_id = l.order_id
				 WHERE l.date_created >= %s AND l.date_created < %s
				   AND s.status IN ({$in})
				 GROUP BY l.product_id, l.variation_id",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$end_dt = new DateTimeImmutable( $end );
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$span = ( new DateTimeImmutable( $r['first_day'] ) )->diff( $end_dt )->days + 1;
			if ( $span >= (int) $min_days ) {
				$out[] = array(
					'product_id'   => (int) $r['product_id'],
					'variation_id' => (int) $r['variation_id'],
					'first_day'    => $r['first_day'],
				);
			}
		}
		return $out;
	}

	/**
	 * Dense daily unit series for a set of items.
	 *
	 * @param array[] $items Items from items().
	 * @param int     $days  History length.
	 * @return array Map of "product_id:variation_id" => float[] (oldest first).
	 */
	public static function series( array $items, $days ) {
		global $wpdb;
		if ( empty( $items ) ) {
			return array();
		}
		list( $start, $end ) = self::window( $days );
		$statuses    = self::statuses();
		$product_ids = array_map( 'intval', array_values( array_unique( wp_list_pluck( $items, 'product_id' ) ) ) );
		$in_status   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$in_ids      = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$args        = array_merge(
			array( $wpdb->prefix . 'wc_order_product_lookup', $wpdb->prefix . 'wc_order_stats', $start . ' 00:00:00', self::until( $end ) ),
			$statuses,
			$product_ids
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in_* are only placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.product_id, l.variation_id, DATE(l.date_created) AS day, SUM(l.product_qty) AS units
				 FROM %i l
				 INNER JOIN %i s ON s.order_id = l.order_id
				 WHERE l.date_created >= %s AND l.date_created < %s
				   AND s.status IN ({$in_status})
				   AND l.product_id IN ({$in_ids})
				 GROUP BY l.product_id, l.variation_id, day",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable

		$wanted = array();
		foreach ( $items as $it ) {
			$wanted[ self::key( $it['product_id'], $it['variation_id'] ) ] = true;
		}
		$by_item = array();
		foreach ( (array) $rows as $r ) {
			$k = self::key( $r['product_id'], $r['variation_id'] );
			if ( isset( $wanted[ $k ] ) ) {
				// Refund rows carry negative quantities, so the day's sum is net units.
				$by_item[ $k ][ $r['day'] ] = (float) $r['units'];
			}
		}

		$out = array();
		foreach ( $by_item as $k => $by_day ) {
			$out[ $k ] = EPHFC_Math::dense_series( $by_day, $end );
		}
		return $out;
	}

	/**
	 * Exclusive upper bound: midnight after the last day.
	 *
	 * @param string $end Last day ('Y-m-d').
	 * @return string
	 */
	private static function until( $end ) {
		return ( new DateTimeImmutable( $end ) )->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );
	}

	/**
	 * Item key.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation or 0.
	 * @return string
	 */
	public static function key( $product_id, $variation_id ) {
		return (int) $product_id . ':' . (int) $variation_id;
	}
}
