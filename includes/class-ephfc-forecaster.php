<?php
/**
 * Background forecast runs on Action Scheduler (bundled with WooCommerce).
 *
 * A run lists eligible items once, then processes them 64 at a time. Each batch queues the
 * next, so batches never overlap and a large catalogue never hits a PHP time limit.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run orchestration.
 */
class EPHFC_Forecaster {

	const GROUP       = 'ephfc';
	const START_HOOK  = 'ephfc_start_run';
	const BATCH_HOOK  = 'ephfc_run_batch';
	const ITEMS_OPT   = 'ephfc_run_items';
	const APPLIED_OPT = 'ephfc_schedule_applied';
	const STALE_AFTER = 7200;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::START_HOOK, array( __CLASS__, 'start_run' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ), 10, 2 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_apply_schedule' ) );
	}

	/**
	 * Keep the recurring action in line with the schedule setting. One option read per request.
	 */
	public static function maybe_apply_schedule() {
		$wanted = (string) EPHFC_Settings::get( 'schedule' );
		if ( get_option( self::APPLIED_OPT ) === $wanted ) {
			return;
		}
		as_unschedule_all_actions( self::START_HOOK, array(), self::GROUP );
		if ( 'manual' !== $wanted ) {
			$interval = 'daily' === $wanted ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
			// First run at 3am site time tomorrow, when stores are quiet.
			$first = current_datetime()->setTime( 3, 0 )->modify( '+1 day' )->getTimestamp();
			as_schedule_recurring_action( $first, $interval, self::START_HOOK, array(), self::GROUP );
		}
		update_option( self::APPLIED_OPT, $wanted );
	}

	/**
	 * Deactivation: remove every scheduled action.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::START_HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::BATCH_HOOK, null, self::GROUP );
		}
		delete_option( self::APPLIED_OPT );
	}

	/**
	 * Queue a run now (from the admin button).
	 *
	 * @return bool False if a run is already going.
	 */
	public static function queue_now() {
		if ( self::is_running() ) {
			return false;
		}
		as_enqueue_async_action( self::START_HOOK, array(), self::GROUP );
		$run           = EPHFC_Store::run();
		$run['queued'] = time();
		EPHFC_Store::save_run( $run );
		return true;
	}

	/**
	 * Whether a run is in progress (and not stuck).
	 *
	 * @return bool
	 */
	public static function is_running() {
		$run = EPHFC_Store::run();
		if ( isset( $run['status'] ) && 'running' === $run['status'] && ( time() - (int) $run['started'] ) < self::STALE_AFTER ) {
			return true;
		}
		return ! empty( $run['queued'] ) && ( time() - (int) $run['queued'] ) < 600;
	}

	/**
	 * Start a run: list eligible items and queue the first batch.
	 */
	public static function start_run() {
		$run = EPHFC_Store::run();
		if ( isset( $run['status'] ) && 'running' === $run['status'] && ( time() - (int) $run['started'] ) < self::STALE_AFTER ) {
			return;
		}

		$settings = EPHFC_Settings::all();
		$run_id   = gmdate( 'YmdHis' ) . wp_generate_password( 6, false, false );
		$base     = array(
			'id'         => $run_id,
			'started'    => time(),
			'finished'   => null,
			'horizon'    => EPHFC_Settings::horizon(),
			'items'      => 0,
			'done'       => 0,
			'rows'       => 0,
			'billing_mc' => 0,
			'errors'     => array(),
		);

		if ( '' === (string) $settings['api_key'] ) {
			EPHFC_Store::save_run( array_merge( $base, array( 'status' => 'failed', 'finished' => time(), 'errors' => array( __( 'No API key saved.', 'ephemeris-forecasts-for-woocommerce' ) ) ) ) );
			return;
		}
		if ( ! EPHFC_History::has_analytics_data() ) {
			EPHFC_Store::save_run( array_merge( $base, array( 'status' => 'failed', 'finished' => time(), 'errors' => array( __( 'WooCommerce Analytics has no order data yet. Import historical data under Analytics > Settings.', 'ephemeris-forecasts-for-woocommerce' ) ) ) ) );
			return;
		}

		$items = EPHFC_History::items( (int) $settings['history_days'], (int) $settings['min_days'] );
		/**
		 * Filter the items to forecast.
		 *
		 * @param array[] $items Each: product_id, variation_id, first_day.
		 */
		$items = (array) apply_filters( 'ephfc_items', $items );

		$base['items'] = count( $items );
		if ( empty( $items ) ) {
			EPHFC_Store::save_run( array_merge( $base, array( 'status' => 'failed', 'finished' => time(), 'errors' => array( __( 'No products have enough sales history yet.', 'ephemeris-forecasts-for-woocommerce' ) ) ) ) );
			return;
		}

		update_option( self::ITEMS_OPT, $items, false );
		EPHFC_Store::save_run( array_merge( $base, array( 'status' => 'running' ) ) );
		as_enqueue_async_action( self::BATCH_HOOK, array( $run_id, 0 ), self::GROUP );
	}

	/**
	 * Forecast one batch, save it, queue the next or finish.
	 *
	 * @param string $run_id Run.
	 * @param int    $offset Index of the first item in this batch.
	 */
	public static function run_batch( $run_id, $offset ) {
		$run = EPHFC_Store::run();
		if ( ! isset( $run['id'] ) || $run['id'] !== $run_id || 'running' !== $run['status'] ) {
			return; // Superseded or already finished.
		}

		$settings = EPHFC_Settings::all();
		$all      = (array) get_option( self::ITEMS_OPT, array() );
		$batch    = array_slice( $all, (int) $offset, EPHFC_Client::MAX_SERIES );
		$horizon  = (int) $run['horizon'];
		$cover_q  = EPHFC_Math::qkey( $settings['cover_quantile'] );

		$series_map = EPHFC_History::series( $batch, (int) $settings['history_days'] );
		$keys       = array();
		$series     = array();
		foreach ( $batch as $it ) {
			$k = EPHFC_History::key( $it['product_id'], $it['variation_id'] );
			if ( ! empty( $series_map[ $k ] ) ) {
				$keys[]   = $it;
				$series[] = $series_map[ $k ];
			}
		}

		if ( ! empty( $series ) ) {
			$result = EPHFC_Client::forecast( $settings['api_key'], $series, $horizon, array( 0.1, 0.5, (float) $cover_q ) );

			if ( is_wp_error( $result ) ) {
				$run['errors'][] = $result->get_error_message();
				// A bad key or an empty account will fail every batch: stop and keep the last good results.
				if ( in_array( $result->get_error_code(), array( 'ephfc_auth', 'ephfc_credits', 'ephfc_no_key' ), true ) ) {
					$run['status']   = 'failed';
					$run['finished'] = time();
					EPHFC_Store::save_run( $run );
					delete_option( self::ITEMS_OPT );
					return;
				}
			} else {
				$rows = array();
				foreach ( $result['forecasts'] as $i => $fc ) {
					$it      = $keys[ $i ];
					$stock   = self::stock( $it['product_id'], $it['variation_id'] );
					$s       = EPHFC_Math::suggestion( $fc, $horizon, $cover_q, $stock );
					$rows[]  = array_merge(
						$s,
						array(
							'product_id'   => $it['product_id'],
							'variation_id' => $it['variation_id'],
							'history_days' => count( $series[ $i ] ),
							'stock'        => $stock,
						)
					);
				}
				EPHFC_Store::insert_rows( $run_id, $rows );
				$run['rows']       += count( $rows );
				$run['billing_mc'] += (int) $result['billing_mc'];
			}
		}

		$next        = (int) $offset + EPHFC_Client::MAX_SERIES;
		$run['done'] = min( count( $all ), $next );

		if ( $next < count( $all ) ) {
			EPHFC_Store::save_run( $run );
			as_enqueue_async_action( self::BATCH_HOOK, array( $run_id, $next ), self::GROUP );
			return;
		}

		// Finished: show this run if it produced anything, otherwise keep the previous one.
		$run['finished'] = time();
		$run['status']   = $run['rows'] > 0 ? 'complete' : 'failed';
		if ( $run['rows'] > 0 ) {
			update_option( 'ephfc_last_completed_run', $run_id, false );
			EPHFC_Store::prune_except( $run_id );
		}
		EPHFC_Store::save_run( $run );
		delete_option( self::ITEMS_OPT );
	}

	/**
	 * Units on hand, or null when stock isn't managed.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation or 0.
	 * @return float|null
	 */
	private static function stock( $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product || ! $product->managing_stock() ) {
			return null;
		}
		return (float) $product->get_stock_quantity();
	}
}
