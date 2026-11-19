<?php
/**
 * Plugin Name:          Ephemeris Forecasts for WooCommerce
 * Plugin URI:           https://github.com/TensorLink-AI/ephemeris-forecasts-for-woocommerce
 * Description:          Demand forecasts and reorder suggestions for every product, from pretrained forecasting models. Nothing is trained on your store's data.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Ephemeris
 * Author URI:           https://ephemeris.cascade.industries
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          ephemeris-forecasts-for-woocommerce
 * WC requires at least: 8.2
 * WC tested up to:      11.3
 *
 * @package EphemerisForecasts
 */

defined( 'ABSPATH' ) || exit;

define( 'EPHFC_VERSION', '0.1.0' );
define( 'EPHFC_FILE', __FILE__ );
define( 'EPHFC_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPHFC_API_URL', 'https://ephemeris.cascade.industries/api/v1/forecast' );

require_once EPHFC_DIR . 'includes/class-ephfc-settings.php';
require_once EPHFC_DIR . 'includes/class-ephfc-math.php';
require_once EPHFC_DIR . 'includes/class-ephfc-history.php';
require_once EPHFC_DIR . 'includes/class-ephfc-client.php';
require_once EPHFC_DIR . 'includes/class-ephfc-store.php';
require_once EPHFC_DIR . 'includes/class-ephfc-forecaster.php';

// Works with WooCommerce's order storage (HPOS) because it reads the Analytics lookup tables, not orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EPHFC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EPHFC_FILE, true );
		}
	}
);

register_activation_hook( EPHFC_FILE, array( 'EPHFC_Store', 'install' ) );
register_deactivation_hook( EPHFC_FILE, array( 'EPHFC_Forecaster', 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		EPHFC_Store::maybe_upgrade();
		EPHFC_Forecaster::init();
		if ( is_admin() ) {
			require_once EPHFC_DIR . 'includes/class-ephfc-admin.php';
			EPHFC_Admin::init();
		}
	}
);
