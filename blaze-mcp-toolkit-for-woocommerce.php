<?php
/**
 * Plugin Name:       Blaze MCP Toolkit for WooCommerce
 * Description:       Ask Claude or ChatGPT how your WooCommerce store is doing: sales, errors, failed jobs, updates and API access. Our tools are read-only.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce, mcp-adapter, enable-abilities-for-mcp
 * WC requires at least: 10.9
 * WC tested up to:   11.2
 * Author:            Blaze Commerce
 * Author URI:        https://blazecommerce.io
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       blaze-mcp-toolkit-for-woocommerce
 *
 * @package Blaze\McpToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'BLZMCP_VERSION', '1.0.0' );
define( 'BLZMCP_FILE', __FILE__ );
define( 'BLZMCP_DIR', plugin_dir_path( __FILE__ ) );

if ( ! file_exists( BLZMCP_DIR . 'vendor/autoload.php' ) ) {
	return;
}
require_once BLZMCP_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \Blaze\McpToolkit\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Blaze\McpToolkit\Installer::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BLZMCP_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \Blaze\McpToolkit\Plugin::class, 'boot' ) );
