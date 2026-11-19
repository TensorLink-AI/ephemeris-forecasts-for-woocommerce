<?php
/**
 * Storage for suggestions and run status.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom table + options.
 */
class EPHFC_Store {

	const DB_VERSION = '1';
	const RUN_OPTION = 'ephfc_run';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ephfc_suggestions';
	}

	/**
	 * Activation: create the table and schedule the first run.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(32) NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				history_days int(11) NOT NULL DEFAULT 0,
				stock decimal(19,4) NULL,
				expected decimal(19,2) NOT NULL DEFAULT 0,
				cover decimal(19,2) NOT NULL DEFAULT 0,
				days_of_cover decimal(19,1) NULL,
				reorder_qty int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY reorder_qty (reorder_qty)
			) {$charset};"
		);
		update_option( 'ephfc_db_version', self::DB_VERSION, false );
	}

	/**
	 * Create the table if an update skipped activation.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ephfc_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Save one batch of suggestion rows.
	 *
	 * @param string $run_id Run.
	 * @param array  $rows   Rows with the table's columns.
	 */
	public static function insert_rows( $run_id, array $rows ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		foreach ( $rows as $r ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				self::table(),
				array(
					'run_id'        => $run_id,
					'product_id'    => (int) $r['product_id'],
					'variation_id'  => (int) $r['variation_id'],
					'history_days'  => (int) $r['history_days'],
					'stock'         => $r['stock'],
					'expected'      => $r['expected'],
					'cover'         => $r['cover'],
					'days_of_cover' => $r['days_of_cover'],
					'reorder_qty'   => (int) $r['reorder_qty'],
					'created_at'    => $now,
				),
				array( '%s', '%d', '%d', '%d', null === $r['stock'] ? null : '%f', '%f', '%f', null === $r['days_of_cover'] ? null : '%f', '%d', '%s' )
			);
		}
	}

	/**
	 * Keep only the given run's rows.
	 *
	 * @param string $run_id Run to keep.
	 */
	public static function prune_except( $run_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id <> %s', self::table(), $run_id ) );
	}

	/**
	 * Suggestions of the last completed run.
	 *
	 * @param array $args only_reorder (bool), orderby, order, per_page, page.
	 * @return array{rows: array[], total: int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$run = self::last_completed_run_id();
		if ( ! $run ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		$table   = self::table();
		$allowed = array( 'reorder_qty', 'days_of_cover', 'expected', 'cover', 'stock' );
		$orderby = in_array( $args['orderby'] ?? '', $allowed, true ) ? $args['orderby'] : 'reorder_qty';
		$order   = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'ASC' : 'DESC';
		$where   = empty( $args['only_reorder'] ) ? '' : ' AND reorder_qty > 0';
		$limit   = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$offset  = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $limit );
		// Rows with no figure (stock not tracked) sort last.
		$nulls = in_array( $orderby, array( 'days_of_cover', 'stock' ), true ) ? '%i IS NULL, ' : '';
		$cols  = $nulls ? array( $orderby, $orderby ) : array( $orderby );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where, $nulls and $order are fixed strings.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE run_id = %s{$where}", $table, $run ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE run_id = %s{$where} ORDER BY {$nulls}%i {$order}, id ASC LIMIT %d OFFSET %d",
				...array_merge( array( $table, $run ), $cols, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Current run state.
	 *
	 * @return array
	 */
	public static function run() {
		$run = get_option( self::RUN_OPTION, array() );
		return is_array( $run ) ? $run : array();
	}

	/**
	 * Save run state.
	 *
	 * @param array $run State.
	 */
	public static function save_run( array $run ) {
		update_option( self::RUN_OPTION, $run, false );
	}

	/**
	 * Id of the run whose rows are shown.
	 *
	 * @return string
	 */
	public static function last_completed_run_id() {
		return (string) get_option( 'ephfc_last_completed_run', '' );
	}
}
