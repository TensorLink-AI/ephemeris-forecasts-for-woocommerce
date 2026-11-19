<?php
/**
 * WooCommerce > Forecasts screen.
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: suggestions table, settings, run button, CSV export.
 */
class EPHFC_Admin {

	const SLUG = 'ephfc-forecasts';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ephfc_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_ephfc_csv', array( __CLASS__, 'handle_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EPHFC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Forecasts', 'ephemeris-forecasts-for-woocommerce' ),
			__( 'Forecasts', 'ephemeris-forecasts-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'ephemeris-forecasts-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Settings API registration.
	 */
	public static function register_settings() {
		register_setting(
			'ephfc',
			EPHFC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'EPHFC_Settings', 'sanitize' ),
				'default'           => EPHFC_Settings::defaults(),
			)
		);
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * "Run forecast now".
	 */
	public static function handle_run() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ephemeris-forecasts-for-woocommerce' ) );
		}
		check_admin_referer( 'ephfc_run' );
		$queued = EPHFC_Forecaster::queue_now();
		wp_safe_redirect( self::url( array( 'ephfc_notice' => $queued ? 'queued' : 'busy' ) ) );
		exit;
	}

	/**
	 * CSV of the latest suggestions.
	 */
	public static function handle_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ephemeris-forecasts-for-woocommerce' ) );
		}
		check_admin_referer( 'ephfc_csv' );

		$only = ! empty( $_GET['only_reorder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=reorder-suggestions-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'product_id', 'variation_id', 'sku', 'name', 'stock', 'expected_demand', 'cautious_demand', 'days_of_cover', 'suggested_order', 'history_days' ) );
		$page = 1;
		do {
			$res = EPHFC_Store::query(
				array(
					'only_reorder' => $only,
					'per_page'     => 500,
					'page'         => $page,
				)
			);
			foreach ( $res['rows'] as $r ) {
				$p = wc_get_product( $r['variation_id'] ? $r['variation_id'] : $r['product_id'] );
				fputcsv(
					$out,
					array(
						$r['product_id'],
						$r['variation_id'],
						$p ? self::csv_safe( $p->get_sku() ) : '',
						$p ? self::csv_safe( wp_strip_all_tags( $p->get_name() ) ) : '',
						null === $r['stock'] ? '' : (float) $r['stock'],
						(float) $r['expected'],
						(float) $r['cover'],
						null === $r['days_of_cover'] ? '' : (float) $r['days_of_cover'],
						(int) $r['reorder_qty'],
						(int) $r['history_days'],
					)
				);
			}
			++$page;
		} while ( count( $res['rows'] ) === 500 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Stop spreadsheet apps treating a cell as a formula.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/**
	 * Page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'suggestions';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Forecasts', 'ephemeris-forecasts-for-woocommerce' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab <?php echo 'suggestions' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Reorder suggestions', 'ephemeris-forecasts-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'ephemeris-forecasts-for-woocommerce' ); ?></a>
			</nav>
			<?php
			self::notices();
			if ( 'settings' === $tab ) {
				self::render_settings();
			} else {
				self::render_suggestions();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Status notices.
	 */
	private static function notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['ephfc_notice'] ) ? sanitize_key( $_GET['ephfc_notice'] ) : '';
		if ( 'queued' === $notice ) {
			self::notice( 'success', __( 'Forecast queued. It runs in the background; refresh this page in a minute.', 'ephemeris-forecasts-for-woocommerce' ) );
		} elseif ( 'busy' === $notice ) {
			self::notice( 'warning', __( 'A forecast is already running.', 'ephemeris-forecasts-for-woocommerce' ) );
		}

		if ( '' === (string) EPHFC_Settings::get( 'api_key' ) ) {
			self::notice(
				'info',
				sprintf(
					/* translators: 1: sign-up link, 2: settings link. */
					__( 'To start, <a href="%1$s" target="_blank" rel="noopener">get a free Ephemeris API key</a> (new accounts include credits for about 2,000 forecasts), then paste it into <a href="%2$s">Settings</a>.', 'ephemeris-forecasts-for-woocommerce' ),
					esc_url( 'https://ephemeris.cascade.industries/sign-up' ),
					esc_url( self::url( array( 'tab' => 'settings' ) ) )
				)
			);
		}
		if ( ! EPHFC_History::has_analytics_data() ) {
			self::notice(
				'warning',
				sprintf(
					/* translators: %s: link to WooCommerce Analytics settings. */
					__( 'WooCommerce Analytics has no order data yet, so there is no sales history to forecast from. <a href="%s">Import historical data</a> first.', 'ephemeris-forecasts-for-woocommerce' ),
					esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/settings' ) )
				)
			);
		}

		$run = EPHFC_Store::run();
		if ( isset( $run['status'] ) && 'running' === $run['status'] ) {
			self::notice(
				'info',
				sprintf(
					/* translators: 1: items done, 2: items total. */
					__( 'Forecast running: %1$d of %2$d products done.', 'ephemeris-forecasts-for-woocommerce' ),
					(int) $run['done'],
					(int) $run['items']
				)
			);
		} elseif ( ! empty( $run['errors'] ) && ! empty( $run['finished'] ) ) {
			$errors = array_unique( array_map( 'strval', $run['errors'] ) );
			self::notice( 'failed' === $run['status'] ? 'error' : 'warning', __( 'Last forecast run:', 'ephemeris-forecasts-for-woocommerce' ) . ' ' . implode( ' ', $errors ) );
		}
	}

	/**
	 * One admin notice. Message may contain links built above; everything else is escaped.
	 *
	 * @param string $type Notice type.
	 * @param string $html Message.
	 */
	private static function notice( $type, $html ) {
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			wp_kses(
				$html,
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			)
		);
	}

	/**
	 * Suggestions tab.
	 */
	private static function render_suggestions() {
		require_once EPHFC_DIR . 'includes/class-ephfc-list-table.php';
		$run    = EPHFC_Store::run();
		$last   = EPHFC_Store::last_completed_run_id();
		$window = EPHFC_Settings::horizon();
		$table  = new EPHFC_List_Table();
		$table->prepare_items();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$only = ! empty( $_GET['only_reorder'] );
		?>
		<p>
			<?php
			printf(
				/* translators: 1: days, 2: lead time, 3: review period, 4: quantile percent. */
				esc_html__( 'Each row covers the next %1$d days (%2$d-day lead time + %3$d days until your next order). "Cautious demand" adds up the daily %4$s%% forecasts, a deliberately high figure to cover slow deliveries and busy weeks.', 'ephemeris-forecasts-for-woocommerce' ),
				(int) $window,
				(int) EPHFC_Settings::get( 'lead_time' ),
				(int) EPHFC_Settings::get( 'review_period' ),
				esc_html( (string) round( 100 * (float) EPHFC_Settings::get( 'cover_quantile' ) ) )
			);
			?>
		</p>

		<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ephfc_run" />
				<?php wp_nonce_field( 'ephfc_run' ); ?>
				<?php submit_button( __( 'Run forecast now', 'ephemeris-forecasts-for-woocommerce' ), 'primary', 'submit', false, EPHFC_Forecaster::is_running() ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>
			<?php if ( $last ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ephfc_csv', 'only_reorder' => $only ? 1 : 0 ), admin_url( 'admin-post.php' ) ), 'ephfc_csv' ) ); ?>"><?php esc_html_e( 'Download CSV', 'ephemeris-forecasts-for-woocommerce' ); ?></a>
			<?php endif; ?>
			<span class="description">
				<?php
				if ( ! empty( $run['finished'] ) && 'complete' === ( $run['status'] ?? '' ) ) {
					printf(
						/* translators: 1: date, 2: products, 3: credits. */
						esc_html__( 'Last run %1$s: %2$d products, %3$s credits.', 'ephemeris-forecasts-for-woocommerce' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $run['finished'] ) ),
						(int) $run['rows'],
						esc_html( number_format_i18n( (int) $run['billing_mc'] / 1000, 2 ) )
					);
				}
				?>
			</span>
		</div>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( self::url() ); ?>" class="<?php echo $only ? '' : 'current'; ?>"><?php esc_html_e( 'All', 'ephemeris-forecasts-for-woocommerce' ); ?></a> |</li>
			<li><a href="<?php echo esc_url( self::url( array( 'only_reorder' => 1 ) ) ); ?>" class="<?php echo $only ? 'current' : ''; ?>"><?php esc_html_e( 'Needs reorder', 'ephemeris-forecasts-for-woocommerce' ); ?></a></li>
		</ul>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<?php $table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Settings tab.
	 */
	private static function render_settings() {
		$s     = EPHFC_Settings::all();
		$name  = EPHFC_Settings::OPTION;
		$items = 0;
		if ( EPHFC_History::has_analytics_data() ) {
			$items = count( EPHFC_History::items( (int) $s['history_days'], (int) $s['min_days'] ) );
		}
		$blocks  = (int) ceil( EPHFC_Settings::horizon() / 64 );
		$per_run = $items * $blocks * 0.001;
		$runs    = 'daily' === $s['schedule'] ? 30.4 : ( 'weekly' === $s['schedule'] ? 4.33 : 0 );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ephfc' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ephfc_api_key"><?php esc_html_e( 'Ephemeris API key', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td>
						<input type="password" id="ephfc_api_key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $s['api_key'] ? esc_attr( '•••• ' . substr( $s['api_key'], -4 ) ) : 'pc_live_…'; ?>" />
						<?php if ( $s['api_key'] ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_api_key]" value="1" /> <?php esc_html_e( 'Remove saved key', 'ephemeris-forecasts-for-woocommerce' ); ?></label>
						<?php endif; ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: sign-up URL, 2: API keys URL. */
								wp_kses( __( '<a href="%1$s" target="_blank" rel="noopener">Sign up</a> or <a href="%2$s" target="_blank" rel="noopener">copy a key from your dashboard</a>. Leave blank to keep the saved key.', 'ephemeris-forecasts-for-woocommerce' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ),
								esc_url( 'https://ephemeris.cascade.industries/sign-up' ),
								esc_url( 'https://ephemeris.cascade.industries/dashboard/api-keys' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ephfc_lead"><?php esc_html_e( 'Supplier lead time (days)', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td><input type="number" min="1" max="180" id="ephfc_lead" name="<?php echo esc_attr( $name ); ?>[lead_time]" value="<?php echo esc_attr( $s['lead_time'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'From placing an order to stock on the shelf.', 'ephemeris-forecasts-for-woocommerce' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ephfc_review"><?php esc_html_e( 'Days between orders', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td><input type="number" min="0" max="90" id="ephfc_review" name="<?php echo esc_attr( $name ); ?>[review_period]" value="<?php echo esc_attr( $s['review_period'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'How often you place orders. The suggestion covers lead time plus this gap.', 'ephemeris-forecasts-for-woocommerce' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ephfc_q"><?php esc_html_e( 'Safety level', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td><select id="ephfc_q" name="<?php echo esc_attr( $name ); ?>[cover_quantile]">
						<?php
						foreach ( array(
							'0.8'  => __( 'Lean (80th percentile)', 'ephemeris-forecasts-for-woocommerce' ),
							'0.9'  => __( 'Balanced (90th percentile)', 'ephemeris-forecasts-for-woocommerce' ),
							'0.95' => __( 'Cautious (95th percentile)', 'ephemeris-forecasts-for-woocommerce' ),
						) as $v => $label ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $v ), selected( $s['cover_quantile'], $v, false ), esc_html( $label ) );
						}
						?>
					</select>
					<p class="description"><?php esc_html_e( 'Higher means more stock and fewer stockouts.', 'ephemeris-forecasts-for-woocommerce' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ephfc_hist"><?php esc_html_e( 'History to use (days)', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td><input type="number" min="56" max="1095" id="ephfc_hist" name="<?php echo esc_attr( $name ); ?>[history_days]" value="<?php echo esc_attr( $s['history_days'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'A year or more lets the models see seasonal patterns.', 'ephemeris-forecasts-for-woocommerce' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ephfc_min"><?php esc_html_e( 'Skip products newer than (days)', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td><input type="number" min="7" max="365" id="ephfc_min" name="<?php echo esc_attr( $name ); ?>[min_days]" value="<?php echo esc_attr( $s['min_days'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="ephfc_sched"><?php esc_html_e( 'Run automatically', 'ephemeris-forecasts-for-woocommerce' ); ?></label></th>
					<td><select id="ephfc_sched" name="<?php echo esc_attr( $name ); ?>[schedule]">
						<option value="weekly" <?php selected( $s['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'ephemeris-forecasts-for-woocommerce' ); ?></option>
						<option value="daily" <?php selected( $s['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'ephemeris-forecasts-for-woocommerce' ); ?></option>
						<option value="manual" <?php selected( $s['schedule'], 'manual' ); ?>><?php esc_html_e( 'Only when I click "Run forecast now"', 'ephemeris-forecasts-for-woocommerce' ); ?></option>
					</select></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Estimated cost', 'ephemeris-forecasts-for-woocommerce' ); ?></th>
					<td><p>
						<?php
						printf(
							/* translators: 1: products, 2: cost per run, 3: monthly cost. */
							esc_html__( '%1$d products with enough history: about $%2$s per run, about $%3$s a month on this schedule ($0.001 per product for every 64 days forecast).', 'ephemeris-forecasts-for-woocommerce' ),
							(int) $items,
							esc_html( number_format_i18n( $per_run, 3 ) ),
							esc_html( number_format_i18n( $per_run * $runs, 2 ) )
						);
						?>
					</p></td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'What is sent to Ephemeris', 'ephemeris-forecasts-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Only numbers: one list of daily units sold per product, plus how many days to forecast. Product names, SKUs, prices, customers and orders never leave your store. Forecasts come from pretrained models; nothing is trained on your data.', 'ephemeris-forecasts-for-woocommerce' ); ?></p>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
