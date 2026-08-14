<?php
/**
 * Plugin Name:       TopTex for WooCommerce
 * Plugin URI:        https://github.com/dotMavriQ/toptex-for-woocommerce
 * Description:       Import the TopTex garment wholesale catalog into WooCommerce as variable products with full color, size, price and stock variations.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            dotMavriQ
 * Author URI:        https://github.com/dotMavriQ
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       toptex-woocommerce
 * Domain Path:       /languages
 *
 * @package TopTex_WooCommerce
 */

namespace TopTexWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 *
 * Bump alongside the plugin header when releasing.
 */
const TOP_TEX_VERSION = '1.1.0';

/**
 * Absolute path to the plugin directory (with trailing slash).
 */
define( 'TOP_TEX_PLUGIN_DIR', __DIR__ . '/' );

/**
 * Public URL to the plugin directory (with trailing slash).
 */
define( 'TOP_TEX_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Loads the plugin once all plugins are ready.
 *
 * We defer so the WooCommerce dependency check is reliable and translations
 * can be registered at the right time.
 *
 * @return void
 */
function top_tex_boot() {
	if ( ! class_exists( \WooCommerce::class ) ) {
		\add_action( 'admin_notices', __NAMESPACE__ . '\top_tex_render_woocommerce_missing_notice' );
		return; // phpcs:ignore Squiz.PHP.NonExecutableCode.ReturnNotRequired
	}

	\load_plugin_textdomain( 'toptex-woocommerce', false, \dirname( \plugin_basename( __FILE__ ) ) . '/languages' );

	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-client.php';
	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-settings.php';
	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-importer.php';
	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-cron.php';

	Settings::instance();
	Importer::instance();
	Cron::instance();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\top_tex_boot' );

/**
 * Renders an admin notice when WooCommerce is not active.
 *
 * @return void
 */
function top_tex_render_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo \wp_kses_post(
				\__(
					'<strong>TopTex for WooCommerce</strong> requires WooCommerce to be installed and active. The plugin has been disabled.',
					'toptex-woocommerce'
				)
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Runs on plugin activation: creates log table and schedules sync.
 *
 * @return void
 */
function top_tex_activate() {
	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-client.php';
	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-importer.php';
	require_once TOP_TEX_PLUGIN_DIR . 'includes/class-cron.php';

	if ( class_exists( \WooCommerce::class ) ) {
		Importer::create_log_table();
		Cron::schedule();
	}
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\top_tex_activate' );

/**
 * Runs on plugin deactivation: clears the scheduled sync event.
 *
 * @return void
 */
function top_tex_deactivate() {
	Cron::unschedule();
}

register_deactivation_hook( __FILE__, __NAMESPACE__ . '\top_tex_deactivate' );
