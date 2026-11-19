<?php
/**
 * Suggestions table.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Rows from the latest completed run.
 */
class EPHFC_List_Table extends WP_List_Table {

	/**
	 * Setup.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'suggestion',
				'plural'   => 'suggestions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'product'       => __( 'Product', 'ephemeris-forecasts-for-woocommerce' ),
			'sku'           => __( 'SKU', 'ephemeris-forecasts-for-woocommerce' ),
			'stock'         => __( 'In stock', 'ephemeris-forecasts-for-woocommerce' ),
			'expected'      => __( 'Expected demand', 'ephemeris-forecasts-for-woocommerce' ),
			'cover'         => __( 'Cautious demand', 'ephemeris-forecasts-for-woocommerce' ),
			'days_of_cover' => __( 'Days of stock left', 'ephemeris-forecasts-for-woocommerce' ),
			'reorder_qty'   => __( 'Suggested order', 'ephemeris-forecasts-for-woocommerce' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'stock'         => array( 'stock', true ),
			'expected'      => array( 'expected', true ),
			'cover'         => array( 'cover', true ),
			'days_of_cover' => array( 'days_of_cover', false ),
			'reorder_qty'   => array( 'reorder_qty', true ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per_page = 50;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$res = EPHFC_Store::query(
			array(
				'only_reorder' => ! empty( $_GET['only_reorder'] ),
				'orderby'      => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'reorder_qty',
				'order'        => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc',
				'per_page'     => $per_page,
				'page'         => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->items           = $res['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'product' );
		$this->set_pagination_args(
			array(
				'total_items' => $res['total'],
				'per_page'    => $per_page,
			)
		);

		// Warm the product cache in one query instead of one per row.
		$ids = array();
		foreach ( $this->items as $r ) {
			$ids[] = (int) ( $r['variation_id'] ? $r['variation_id'] : $r['product_id'] );
		}
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No suggestions yet. Add your API key in Settings, then click "Run forecast now".', 'ephemeris-forecasts-for-woocommerce' );
	}

	/**
	 * Product cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_product( $item ) {
		$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
		if ( ! $product ) {
			/* translators: %d: product ID. */
			return esc_html( sprintf( __( 'Deleted product #%d', 'ephemeris-forecasts-for-woocommerce' ), $item['product_id'] ) );
		}
		$link = get_edit_post_link( (int) $item['product_id'] );
		$name = $product->get_name();
		$html = $link ? '<a href="' . esc_url( $link ) . '"><strong>' . esc_html( $name ) . '</strong></a>' : '<strong>' . esc_html( $name ) . '</strong>';
		if ( (int) $item['history_days'] < 350 ) {
			/* translators: %d: days of sales history. */
			$html .= '<br><span class="description">' . esc_html( sprintf( __( '%d days of history (no full year of seasonality)', 'ephemeris-forecasts-for-woocommerce' ), $item['history_days'] ) ) . '</span>';
		}
		return $html;
	}

	/**
	 * SKU cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_sku( $item ) {
		$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
		return $product ? esc_html( $product->get_sku() ) : '';
	}

	/**
	 * Other cells.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $item, $column ) {
		switch ( $column ) {
			case 'stock':
				return null === $item['stock'] ? '<span class="description">' . esc_html__( 'Not tracked', 'ephemeris-forecasts-for-woocommerce' ) . '</span>' : esc_html( wc_stock_amount( $item['stock'] ) );
			case 'expected':
			case 'cover':
				return esc_html( number_format_i18n( (float) $item[ $column ], 1 ) );
			case 'days_of_cover':
				if ( null === $item['days_of_cover'] ) {
					return '&mdash;';
				}
				$days  = (float) $item['days_of_cover'];
				$lead  = (int) EPHFC_Settings::get( 'lead_time' );
				$style = $days < $lead ? ' style="color:#b32d2e;font-weight:600"' : '';
				return '<span' . $style . '>' . esc_html( number_format_i18n( $days, 0 ) ) . '</span>';
			case 'reorder_qty':
				if ( null === $item['stock'] ) {
					return '&mdash;';
				}
				$q = (int) $item['reorder_qty'];
				return $q > 0 ? '<strong>' . esc_html( number_format_i18n( $q ) ) . '</strong>' : '0';
		}
		return '';
	}
}
