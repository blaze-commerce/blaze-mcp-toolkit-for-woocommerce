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
use Blaze\McpToolkit\Bundled\AnalyticsGuard;
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
	blzmcp_it_equals( 20, has_filter( 'wp_register_ability_args', array( AnalyticsGuard::class, 'filter_args' ) ), 'analytics guard registered when bundled' );

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
	$totals = wp_get_ability( 'wc-analytics/totals' );
	$before = get_option( 'woocommerce_analytics_enabled', null );
	update_option( 'woocommerce_analytics_enabled', 'yes' );
	delete_transient( AnalyticsGuard::GAP_TRANSIENT );
	blzmcp_it_equals( null, AnalyticsGuard::unavailable_reason(), 'normal store: analytics guard finds nothing wrong' );
	$clear_upstream_cache();
	$result = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	blzmcp_it_assert( is_array( $result ) && ! is_wp_error( $result ), 'analytics_totals_runs: wc-analytics/totals returns data, not WP_Error' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	$synced_count = is_array( $result ) ? (int) ( $result['metrics']['orders_count'] ?? 0 ) : 0;
	blzmcp_it_assert( $synced_count >= 3, "analytics_totals_runs: the three paid orders are counted (orders_count {$synced_count})" );

	$error_code = static function ( $value ): string {
		return is_wp_error( $value ) ? $value->get_error_code() : 'none';
	};

	// analytics_import_incomplete_is_reported (1): Analytics switched off. WooCommerce then never
	// starts ReportsSync, so new orders never reach wc_order_stats.
	update_option( 'woocommerce_analytics_enabled', 'no' );
	$clear_upstream_cache();
	$disabled = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	blzmcp_it_equals( AnalyticsGuard::ERROR_CODE, $error_code( $disabled ), 'analytics_import_incomplete_is_reported: Analytics off returns blzmcp_analytics_unavailable' );
	blzmcp_it_assert( is_wp_error( $disabled ) && str_contains( $disabled->get_error_message(), 'turned off' ) && str_contains( $disabled->get_error_message(), 'Features' ), 'analytics off: plain turn-on message for the owner' );
	blzmcp_it_assert( is_wp_error( $disabled ) && str_starts_with( $disabled->get_error_message(), AnalyticsGuard::MESSAGE_TOKEN . ' ' ), 'analytics off: message starts with the stable token' );
	blzmcp_it_equals( 'analytics_disabled', is_wp_error( $disabled ) ? ( $disabled->get_error_data()['reason'] ?? null ) : null, 'analytics off: reason analytics_disabled' );
	$valid_inputs = array(
		'wc-analytics/breakdown' => array( 'subject' => 'revenue' ),
		'wc-analytics/series'    => array(
			'subject'  => 'customers',
			'interval' => 'day',
		),
		'wc-analytics/rows'      => array(),
	);
	foreach ( $valid_inputs as $name => $valid_input ) {
		$ability = wp_get_ability( $name );
		$out     = $ability instanceof WP_Ability ? $ability->execute( $valid_input ) : null;
		blzmcp_it_equals( AnalyticsGuard::ERROR_CODE, $error_code( $out ), "analytics off: {$name} is blocked too" );
	}
	$confirm = wp_get_ability( 'wc-analytics/confirm-large-range' );
	$out     = $confirm instanceof WP_Ability ? $confirm->execute( array( 'date_start' => '2026-01-01', 'date_end' => '2026-01-02', 'type' => 'x' ) ) : null;
	blzmcp_it_assert( AnalyticsGuard::ERROR_CODE !== $error_code( $out ), 'analytics off: confirm-large-range (reads no data) is not blocked' );

	// Core runs execute_callback only after its permission check, so unauthorised callers never see the analytics status.
	$sub_id = wp_insert_user(
		array(
			'user_login' => 'blzmcp_sub_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'subscriber',
		)
	);
	wp_set_current_user( $sub_id );
	$denied = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	blzmcp_it_assert( is_wp_error( $denied ) && AnalyticsGuard::ERROR_CODE !== $denied->get_error_code(), 'analytics off: subscriber gets the permission error, not the analytics status (' . $error_code( $denied ) . ')' );
	wp_delete_user( $sub_id );
	wp_set_current_user( $admins[0]->ID );

	// analytics_import_incomplete_is_reported (2): Analytics on. Anchor the synced window three days
	// back by moving one synced order there and re-importing it.
	update_option( 'woocommerce_analytics_enabled', 'yes' );
	$stats_table = $wpdb->prefix . 'wc_order_stats';
	$anchor      = wc_get_order( $order_ids[1] );
	$anchor->set_date_created( time() - 3 * DAY_IN_SECONDS );
	$anchor->save();
	\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $order_ids[1] );

	// Partial history: an order from before the oldest synced order (outside the historical
	// import's window by design) is not a gap, so complete recent queries are not blocked.
	$pre_window = wc_create_order();
	$pre_window->add_product( $product, 1 );
	$pre_window->calculate_totals();
	$pre_window->payment_complete();
	$pre_window->set_date_created( time() - 20 * DAY_IN_SECONDS );
	$pre_window->save();
	$order_ids[] = $pre_window->get_id();
	$wpdb->delete( $stats_table, array( 'order_id' => $pre_window->get_id() ) );
	$oldest_synced = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(date_created) FROM %i', $stats_table ) );
	if ( strtotime( $oldest_synced ) < time() - 20 * DAY_IN_SECONDS ) {
		echo "SKIP  partial-history check: this env already has wc_order_stats rows older than 20 days ({$oldest_synced})\n";
	} else {
		delete_transient( AnalyticsGuard::GAP_TRANSIENT );
		$clear_upstream_cache();
		$partial = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
		blzmcp_it_assert( is_array( $partial ), 'partial history: an unsynced order older than the synced window does not block (' . $error_code( $partial ) . ')' );
	}

	// Recent gap: an order two days old (inside the synced window, past the 1 h grace) missing from stats.
	$recent = wc_get_order( $order_ids[0] );
	$recent->set_date_created( time() - 2 * DAY_IN_SECONDS );
	$recent->save();
	$wpdb->delete( $stats_table, array( 'order_id' => $order_ids[0] ) );
	delete_transient( AnalyticsGuard::GAP_TRANSIENT );
	$clear_upstream_cache();
	$incomplete = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	blzmcp_it_equals( AnalyticsGuard::ERROR_CODE, $error_code( $incomplete ), 'analytics_import_incomplete_is_reported: recent unsynced order returns blzmcp_analytics_unavailable' );
	blzmcp_it_equals( 'orders_not_imported', is_wp_error( $incomplete ) ? ( $incomplete->get_error_data()['reason'] ?? null ) : null, 'recent gap: reason orders_not_imported' );
	blzmcp_it_assert( is_wp_error( $incomplete ) && str_contains( $incomplete->get_error_message(), 'Scheduled Actions' ), 'recent gap: message points to Scheduled Actions' );
	blzmcp_it_equals( 'orders_not_imported', get_transient( AnalyticsGuard::GAP_TRANSIENT ), 'recent gap: result cached' );
	blzmcp_it_equals( 999, has_action( 'wc-admin_import_batch_orders', array( AnalyticsGuard::class, 'clear_cache' ) ), 'import batch hook clears the cached probe' );
	blzmcp_it_equals( 999, has_action( 'wc-admin_import_batch_init_orders', array( AnalyticsGuard::class, 'clear_cache' ) ), 'import batch init hook clears the cached probe' );
	update_option( 'woocommerce_analytics_enabled', 'yes' === get_option( 'woocommerce_analytics_enabled' ) ? 'no' : 'yes' );
	blzmcp_it_equals( false, get_transient( AnalyticsGuard::GAP_TRANSIENT ), 'toggling Analytics clears the cached probe' );
	update_option( 'woocommerce_analytics_enabled', 'yes' );

	// wc_order_stats empty with recent orders: the import never ran (rolled back afterwards).
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $stats_table ) );
	AnalyticsGuard::clear_cache();
	$never = AnalyticsGuard::unavailable_error();
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush(); // The probe's transient write was rolled back too; drop the in-memory copy.
	blzmcp_it_equals( 'import_not_run', is_wp_error( $never ) ? ( $never->get_error_data()['reason'] ?? null ) : null, 'empty wc_order_stats with recent orders: reason import_not_run' );
	blzmcp_it_assert( is_wp_error( $never ) && str_contains( $never->get_error_message(), 'importing' ), 'import not run: message asks to finish the import' );
	blzmcp_it_assert( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id = %d', $stats_table, $order_ids[1] ) ), 'stats rows restored after rollback' );
	AnalyticsGuard::clear_cache();

	// A young order (inside the 1 h grace) missing from stats is not a gap.
	$recent->set_date_created( time() - 10 * MINUTE_IN_SECONDS );
	$recent->save();
	$wpdb->delete( $stats_table, array( 'order_id' => $order_ids[0] ) );
	AnalyticsGuard::clear_cache();
	blzmcp_it_equals( null, AnalyticsGuard::sync_gap(), 'order inside the 1 h sync grace is not a gap' );
	blzmcp_it_equals( 'none', get_transient( AnalyticsGuard::GAP_TRANSIENT ), 'no-gap result cached' );
	AnalyticsGuard::clear_cache();

	// Historical import queued: reported as in progress.
	as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wc-admin_import_batch_orders', array( 'blzmcp-test' ), 'wc-admin-data' );
	blzmcp_it_equals( 'import_in_progress', AnalyticsGuard::unavailable_reason(), 'queued historical import batch: reason import_in_progress' );
	as_unschedule_all_actions( 'wc-admin_import_batch_orders', array( 'blzmcp-test' ), 'wc-admin-data' );

	// Once imported again, numbers come back.
	\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $order_ids[0] );
	delete_transient( AnalyticsGuard::GAP_TRANSIENT );
	$clear_upstream_cache();
	$again = $totals instanceof WP_Ability ? $totals->execute( $totals_input ) : null;
	blzmcp_it_assert( is_array( $again ), 'after import: totals return numbers again (' . $error_code( $again ) . ')' );

	if ( null === $before ) {
		delete_option( 'woocommerce_analytics_enabled' );
	} else {
		update_option( 'woocommerce_analytics_enabled', $before );
	}
	delete_transient( AnalyticsGuard::GAP_TRANSIENT );
	$clear_upstream_cache();

	// Missing-package notice (shown when the bundled Loader class is absent).
	ob_start();
	Commerce::missing_package_notice();
	$missing_html = (string) ob_get_clean();
	blzmcp_it_assert( str_contains( $missing_html, 'notice-error' ) && str_contains( $missing_html, 'reinstall' ), 'missing-package notice renders for store managers' );

	// Search clamp and strict empty schema.
	$search_schema = $search instanceof WP_Ability ? $search->get_input_schema() : array();
	blzmcp_it_equals( 50, $search_schema['properties']['per_page']['maximum'] ?? null, 'search-products per_page maximum is 50' );
	blzmcp_it_assert( is_wp_error( $search instanceof WP_Ability ? $search->execute( array( 'per_page' => 100 ) ) : null ), 'search-products rejects per_page 100' );
	$profile_schema = $profile instanceof WP_Ability ? $profile->get_input_schema() : array();
	blzmcp_it_equals( false, $profile_schema['additionalProperties'] ?? null, 'no-input store tools reject extra properties' );

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
	blzmcp_it_equals( false, has_filter( 'wp_register_ability_args', array( AnalyticsGuard::class, 'filter_args' ) ), 'standaside: analytics guard not registered' );

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
