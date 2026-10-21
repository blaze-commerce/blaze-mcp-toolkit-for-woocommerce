<?php
/**
 * Bundled commerce-abilities, store tools, MCP exposure and stand-aside integration test.
 *
 * Normal run (WooCommerce for Claude not active):
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-bundled.php
 *
 * Stand-aside run with a stub constant defined before plugins load:
 *   npx wp-env run cli wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-bundled.php
 *
 * Stand-aside run with the real WooCommerce for Claude plugin active: install and activate it, then run the
 * normal command. The script picks its mode from Commerce::is_standing_aside().
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Abilities\McpExposure;
use Blaze\McpToolkit\Bundled\Commerce;

$analytics_names = array(
	'wc-analytics/totals',
	'wc-analytics/breakdown',
	'wc-analytics/series',
	'wc-analytics/rows',
	'wc-analytics/confirm-large-range',
);
$store_names     = array(
	'blaze-mcp-toolkit/store-profile',
	'blaze-mcp-toolkit/search-products',
	'blaze-mcp-toolkit/product-details',
	'blaze-mcp-toolkit/readiness-score',
	'blaze-mcp-toolkit/recommendations',
	'blaze-mcp-toolkit/suggest-improvements',
);

blzmcp_it_assert( class_exists( Commerce::class ), 'Commerce class exists' );
blzmcp_it_assert( class_exists( McpExposure::class ), 'McpExposure class exists' );
if ( ! class_exists( Commerce::class ) || ! class_exists( McpExposure::class ) ) {
	blzmcp_it_finish();
}

global $wpdb;

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admins[0]->ID );

$wcfc_constant = defined( 'WOOCOMMERCE_CLAUDE_VERSION' );
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$wcfc_plugin = is_plugin_active( Commerce::WCFC_ACTIVE_CHECK );

if ( ! $wcfc_constant && ! $wcfc_plugin ) {
	echo "Mode: bundled (WooCommerce for Claude not active)\n";

	// Our abilities cache results for five minutes; start clean.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%', $wpdb->esc_like( '_transient_timeout_blzmcp_c_' ) . '%' ) );
	wp_cache_flush();

	blzmcp_it_equals( false, Commerce::is_standing_aside(), 'not standing aside without WooCommerce for Claude' );
	blzmcp_it_equals( 20, has_action( 'plugins_loaded', array( Commerce::class, 'load' ) ), 'Commerce::load runs on plugins_loaded priority 20' );

	// bundled_tools_registered.
	foreach ( array_merge( $analytics_names, $store_names ) as $name ) {
		$ability = wp_get_ability( $name );
		blzmcp_it_assert( $ability instanceof WP_Ability, "bundled_tools_registered: {$name} is registered" );
		$meta = $ability instanceof WP_Ability ? $ability->get_meta() : array();
		blzmcp_it_equals( true, $meta['mcp']['public'] ?? null, "bundled_tools_registered: {$name} mcp.public" );
		blzmcp_it_equals( true, $meta['show_in_rest'] ?? null, "bundled_tools_registered: {$name} show_in_rest" );
	}
	foreach ( $store_names as $name ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability instanceof WP_Ability ) {
			continue;
		}
		blzmcp_it_equals( 'blaze-mcp-toolkit', $ability->get_category(), "{$name} category is ours" );
		blzmcp_it_equals( true, $ability->get_meta()['annotations']['readonly'] ?? null, "{$name} annotation readonly" );
	}
	// The upstream store tools are not registered under their own names by us.
	blzmcp_it_assert( ! wp_has_ability( 'woocommerce-claude/get-store-profile' ), 'upstream woocommerce-claude/* names are not registered' );

	// Store tools behave as upstream: profile returns data for an admin, a subscriber is denied.
	$profile = wp_get_ability( 'blaze-mcp-toolkit/store-profile' );
	if ( $profile instanceof WP_Ability ) {
		$result = $profile->execute();
		blzmcp_it_assert( is_array( $result ) && isset( $result['data'] ), 'store-profile returns upstream profile payload for admin' );
		blzmcp_it_equals( BLZMCP_VERSION, is_array( $result ) ? ( $result['version'] ?? null ) : null, 'store-profile version is ours' );
		blzmcp_it_assert( is_array( $result ) && ! str_contains( (string) wp_json_encode( $result ), (string) get_option( 'admin_email' ) ), 'store-profile output has no admin email' );
	}
	$search = wp_get_ability( 'blaze-mcp-toolkit/search-products' );
	if ( $search instanceof WP_Ability ) {
		$result = $search->execute( array( 'per_page' => 2 ) );
		blzmcp_it_assert( is_array( $result ) && ! is_wp_error( $result ), 'search-products runs for admin' );
	}
	$sub_id = wp_insert_user(
		array(
			'user_login' => 'blzmcp_sub_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	wp_set_current_user( $sub_id );
	foreach ( array( 'blaze-mcp-toolkit/store-profile', 'blaze-mcp-toolkit/readiness-score', 'wc-analytics/totals' ) as $name ) {
		$ability = wp_get_ability( $name );
		if ( $ability instanceof WP_Ability ) {
			$input = 'wc-analytics/totals' === $name ? array( 'subject' => 'revenue', 'period' => 'last_7_days' ) : null;
			blzmcp_it_assert( is_wp_error( $ability->execute( $input ) ), "subscriber is denied {$name}" );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub_id );
	wp_set_current_user( $admins[0]->ID );

	// analytics_totals_runs: three paid orders, synced into wc_order_stats, then totals as admin.

	$clear_upstream_cache = static function () use ( $wpdb ): void {
		// The bundled AnalyticsService caches results in woocommerce_claude_* transients for an hour.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_woocommerce_claude_' ) . '%', $wpdb->esc_like( '_transient_timeout_woocommerce_claude_' ) . '%' ) );
		wp_cache_flush();
	};
	$product = new WC_Product_Simple();
	$product->set_name( 'Blzmcp bundled test product' );
	$product->set_regular_price( '10' );
	$product->save();
	$order_ids = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->payment_complete();
		$order_ids[] = $order->get_id();
		// WooCommerce syncs wc_order_stats from Action Scheduler; do it now so the totals see the order.
		\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $order->get_id() );
	}
	$totals_input = array(
		'subject' => 'revenue',
		'period'  => 'last_7_days',
		'compare' => false,
	);
	$totals       = wp_get_ability( 'wc-analytics/totals' );
	$clear_upstream_cache();
	$result = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	blzmcp_it_assert( is_array( $result ) && ! is_wp_error( $result ), 'analytics_totals_runs: wc-analytics/totals returns data, not WP_Error' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	$synced_count = is_array( $result ) ? (int) ( $result['metrics']['orders_count'] ?? 0 ) : 0;
	blzmcp_it_assert( $synced_count >= 3, "analytics_totals_runs: the three paid orders are counted (orders_count {$synced_count})" );

	// analytics_import_incomplete_is_reported: Analytics switched off. WooCommerce then never starts
	// ReportsSync (FeaturePlugin::is_analytics_enabled_during_bootstrap), so new orders never reach
	// wc_order_stats. Simulate that for our three orders by removing their stats rows.
	$before = get_option( 'woocommerce_analytics_enabled', null );
	update_option( 'woocommerce_analytics_enabled', 'no' );
	$stats_table = $wpdb->prefix . 'wc_order_stats';
	foreach ( $order_ids as $order_id ) {
		$wpdb->delete( $stats_table, array( 'order_id' => $order_id ) );
	}
	$clear_upstream_cache();
	$disabled = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	$encoded  = is_wp_error( $disabled ) ? $disabled->get_error_message() : (string) wp_json_encode( $disabled );
	$reported = is_wp_error( $disabled ) || (bool) preg_match( '/analytics[^"]{0,80}(disabled|not available|unavailable|turned off|incomplete|import)/i', $encoded );
	$missing  = $synced_count - ( is_array( $disabled ) ? (int) ( $disabled['metrics']['orders_count'] ?? 0 ) : 0 );
	echo 'Observed with woocommerce_analytics_enabled=no and unsynced orders: orders_count drops by ' . $missing . '; note: ' . ( is_array( $disabled ) ? wp_json_encode( $disabled['note'] ?? null ) : $encoded ) . "\n";
	// KNOWN GAP (raised for a controller ruling, vendored code left unmodified): upstream does not
	// check the Analytics toggle or import status, so the unsynced orders silently disappear from
	// the totals and no error or not-available message is returned. This assertion records that
	// behaviour; flip it to blzmcp_it_assert( $reported, ... ) once a fix is ruled on.
	blzmcp_it_assert( ! $reported && 3 === $missing, 'analytics_import_incomplete_is_reported: KNOWN GAP, upstream silently omits unsynced orders (no error, no not-available message)' );
	if ( null === $before ) {
		delete_option( 'woocommerce_analytics_enabled' );
	} else {
		update_option( 'woocommerce_analytics_enabled', $before );
	}
	$clear_upstream_cache();

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	$product->delete( true );
} else {
	echo 'Mode: stand-aside (' . ( $wcfc_plugin ? 'real WooCommerce for Claude plugin' : 'stub WOOCOMMERCE_CLAUDE_VERSION constant' ) . ")\n";

	// standaside_when_wcfc_active.
	blzmcp_it_equals( true, Commerce::is_standing_aside(), 'standaside_when_wcfc_active: is_standing_aside() is true' );
	foreach ( $store_names as $name ) {
		blzmcp_it_assert( ! wp_has_ability( $name ), "standaside_when_wcfc_active: {$name} is not registered" );
	}
	blzmcp_it_assert( wp_has_ability( 'blaze-mcp-toolkit/health-summary' ), 'standaside_when_wcfc_active: our diagnostics still registered' );

	if ( $wcfc_plugin ) {
		// WooCommerce for Claude's own copy registers wc-analytics; McpExposure still marks it public.
		foreach ( $analytics_names as $name ) {
			$ability = wp_get_ability( $name );
			blzmcp_it_assert( $ability instanceof WP_Ability, "standaside: {$name} registered by WooCommerce for Claude" );
			$meta = $ability instanceof WP_Ability ? $ability->get_meta() : array();
			blzmcp_it_equals( true, $meta['mcp']['public'] ?? null, "standaside: {$name} mcp.public via McpExposure" );
		}
		blzmcp_it_assert( wp_has_ability( 'woocommerce-claude/get-store-profile' ), 'standaside: upstream store tools come from WooCommerce for Claude' );
		$totals = wp_get_ability( 'wc-analytics/totals' );
		$result = $totals instanceof WP_Ability ? $totals->execute(
			array(
				'subject' => 'revenue',
				'period'  => 'last_7_days',
			)
		) : null;
		blzmcp_it_assert( is_array( $result ), 'standaside: wc-analytics/totals runs with both plugins active' );
		$loader = new ReflectionClass( \WooCommerce\CommerceAbilities\Loader::class );
		echo 'Loader class file: ' . $loader->getFileName() . "\n";
	}

	// Admin notice: shown on the plugins screen to manage_woocommerce users, not to others, not elsewhere.
	$render = static function ( string $screen ): string {
		set_current_screen( $screen );
		ob_start();
		Commerce::admin_notice();
		return (string) ob_get_clean();
	};
	$html = $render( 'plugins' );
	blzmcp_it_assert( str_contains( $html, 'WooCommerce for Claude' ) && str_contains( $html, 'notice' ), 'notice shows on the plugins screen' );
	blzmcp_it_assert( str_contains( $html, '_wpnonce=' ), 'notice has a nonce-protected dismiss link' );
	blzmcp_it_equals( '', $render( 'edit-post' ), 'notice does not show on unrelated screens' );

	$sub_id = wp_insert_user(
		array(
			'user_login' => 'blzmcp_sub_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	wp_set_current_user( $sub_id );
	blzmcp_it_equals( '', $render( 'plugins' ), 'notice hidden from users without manage_woocommerce' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sub_id );

	wp_set_current_user( $admins[0]->ID );
	update_user_meta( $admins[0]->ID, Commerce::NOTICE_DISMISSED_META, 1 );
	blzmcp_it_equals( '', $render( 'plugins' ), 'notice hidden after dismissal' );
	delete_user_meta( $admins[0]->ID, Commerce::NOTICE_DISMISSED_META );
}

blzmcp_it_finish();
