<?php
/**
 * Bundled WooCommerce for Claude commerce-abilities, with stand-aside.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Bundled;

use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Abilities\Store\ProductDetails;
use Blaze\McpToolkit\Abilities\Store\ReadinessScore;
use Blaze\McpToolkit\Abilities\Store\Recommendations;
use Blaze\McpToolkit\Abilities\Store\SearchProducts;
use Blaze\McpToolkit\Abilities\Store\StoreProfile;
use Blaze\McpToolkit\Abilities\Store\SuggestImprovements;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the bundled analytics abilities and our six store tools, unless WooCommerce for Claude
 * is active, in which case its own copy provides wc-analytics/* and its own store tools, and we
 * only show a notice. Our diagnostics abilities register either way.
 */
final class Commerce {

	/**
	 * WooCommerce for Claude's plugin file (release zip folder and main file).
	 */
	public const WCFC_ACTIVE_CHECK = 'woocommerce-claude/woocommerce-claude.php';

	/**
	 * Constant WooCommerce for Claude defines when its main file loads.
	 */
	public const WCFC_VERSION_CONSTANT = 'WOOCOMMERCE_CLAUDE_VERSION';

	/**
	 * User meta flag set when a user dismisses the stand-aside notice.
	 */
	public const NOTICE_DISMISSED_META = 'blzmcp_wcfc_notice_dismissed';

	/**
	 * Query arg and nonce action for dismissing the notice.
	 */
	public const DISMISS_ARG = 'blzmcp_dismiss_wcfc_notice';

	/**
	 * Store tools registered when not standing aside.
	 *
	 * @var array<int, class-string>
	 */
	public const STORE_ABILITIES = array(
		StoreProfile::class,
		SearchProducts::class,
		ProductDetails::class,
		ReadinessScore::class,
		Recommendations::class,
		SuggestImprovements::class,
	);

	/**
	 * Hook in. Called from Plugin::boot() on plugins_loaded (priority 10).
	 */
	public static function register(): void {
		// Priority 20: every active plugin's main file has loaded by then, whatever the load order.
		add_action( 'plugins_loaded', array( self::class, 'load' ), 20 );
	}

	/**
	 * Whether WooCommerce for Claude is active, so we leave the commerce tools to it.
	 */
	public static function is_standing_aside(): bool {
		if ( defined( self::WCFC_VERSION_CONSTANT ) ) {
			return true;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::WCFC_ACTIVE_CHECK );
	}

	/**
	 * Load the bundled package, or stand aside.
	 */
	public static function load(): void {
		if ( self::is_standing_aside() ) {
			add_action( 'admin_init', array( self::class, 'maybe_dismiss_notice' ) );
			add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
			return;
		}

		if ( ! class_exists( \WooCommerce\CommerceAbilities\Loader::class ) ) {
			// Broken install (vendor/ incomplete): say so instead of failing silently.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Bundled commerce-abilities package is missing; analytics and store tools are not registered.', array( 'source' => 'blzmcp' ) );
			}
			add_action( 'admin_notices', array( self::class, 'missing_package_notice' ) );
			return;
		}

		AnalyticsGuard::register();

		\WooCommerce\CommerceAbilities\Loader::init();

		// The store tools read from the knowledge registry; upstream's plugin registers the same
		// default providers at plugins_loaded (Plugin::register_providers()).
		\WooCommerce\CommerceAbilities\Store\StoreKnowledge::register_default_providers();

		foreach ( self::STORE_ABILITIES as $class_name ) {
			Registrar::add( $class_name );
		}
	}

	/**
	 * Notice for store managers on the Plugins and WooCommerce screens while standing aside.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || get_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META, true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::is_notice_screen( (string) $screen->id ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );

		printf(
			'<div class="notice notice-info"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Blaze MCP Toolkit: WooCommerce for Claude is active, so the store and analytics tools come from WooCommerce for Claude and this plugin does not add its own copies. Our store health, update history and access check tools still work.', 'blaze-mcp-toolkit-for-woocommerce' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice', 'blaze-mcp-toolkit-for-woocommerce' )
		);
	}

	/**
	 * Notice for store managers when the bundled package is missing from the install.
	 */
	public static function missing_package_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Blaze MCP Toolkit: part of the plugin is missing, so the analytics and store tools are not available. Please reinstall the plugin. Our store health, update history and access check tools still work.', 'blaze-mcp-toolkit-for-woocommerce' )
		);
	}

	/**
	 * Handle the dismiss link. Runs on admin_init.
	 */
	public static function maybe_dismiss_notice(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ], $_GET['_wpnonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_ARG ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META, 1 );
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Plugins screen or a WooCommerce admin screen.
	 *
	 * @param string $screen_id Current screen id.
	 */
	private static function is_notice_screen( string $screen_id ): bool {
		if ( in_array( $screen_id, array( 'plugins', 'plugins-network' ), true ) ) {
			return true;
		}
		$wc_screens = function_exists( 'wc_get_screen_ids' ) ? (array) wc_get_screen_ids() : array();
		return in_array( $screen_id, $wc_screens, true ) || str_starts_with( $screen_id, 'woocommerce_page_' );
	}
}
