<?php
/**
 * Tiny assertion helpers for wp-env integration scripts (run via `wp eval-file`).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

$GLOBALS['blzmcp_it_failures'] = 0;
$GLOBALS['blzmcp_it_passes']   = 0;

function blzmcp_it_assert( $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['blzmcp_it_passes'];
		echo "PASS  {$label}\n";
		return;
	}
	++$GLOBALS['blzmcp_it_failures'];
	echo "FAIL  {$label}\n";
}

function blzmcp_it_equals( $expected, $actual, string $label ): void {
	blzmcp_it_assert( $expected === $actual, $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

/**
 * Register a fixture cleanup. Cleanups run last-in first-out from blzmcp_it_finish() and, as a
 * safety net, on shutdown (also after a fatal error), so a failing test never leaves data behind.
 */
function blzmcp_it_cleanup( callable $fn ): void {
	$GLOBALS['blzmcp_it_cleanups'][] = $fn;
}

function blzmcp_it_run_cleanups(): void {
	while ( ! empty( $GLOBALS['blzmcp_it_cleanups'] ) ) {
		$fn = array_pop( $GLOBALS['blzmcp_it_cleanups'] );
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo 'CLEANUP ERROR  ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
		}
	}
}
$GLOBALS['blzmcp_it_cleanups'] = array();
register_shutdown_function( 'blzmcp_it_run_cleanups' );
// WP-CLI's own shutdown handler exits after a fatal, before ours runs; an uncaught exception or
// Error reaches this handler first, so the fixtures are still removed.
set_exception_handler(
	static function ( \Throwable $e ): void {
		echo 'Fatal error: Uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
		blzmcp_it_run_cleanups();
		exit( 255 );
	}
);

/**
 * Delete a WooCommerce order fixture (no-op if it is already gone).
 */
function blzmcp_it_delete_order( int $id ): void {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
	if ( $order ) {
		$order->delete( true );
	}
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'wc_order_stats', array( 'order_id' => $id ) );
}

/**
 * Switch WooCommerce order storage the way the settings screen does: create the HPOS tables if
 * needed, turn on compatibility sync, sync every pending order, then change the authoritative
 * store. Returns whether the store is now in the requested mode.
 */
function blzmcp_it_set_hpos( bool $on ): bool {
	$util = \Automattic\WooCommerce\Utilities\OrderUtil::class;
	if ( ! class_exists( $util ) || ! function_exists( 'wc_get_container' ) ) {
		return false;
	}
	if ( $util::custom_orders_table_usage_is_enabled() === $on ) {
		return true;
	}
	$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
	if ( ! $sync->check_orders_table_exists() ) {
		$sync->create_database_tables();
	}
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );
	for ( $i = 0; $i < 100; $i++ ) {
		$batch = $sync->get_next_batch_to_process( 100 );
		if ( empty( $batch ) ) {
			break;
		}
		$sync->process_batch( $batch );
	}
	try {
		update_option( 'woocommerce_custom_orders_table_enabled', $on ? 'yes' : 'no' );
	} catch ( \Throwable $e ) {
		echo 'NOTE  could not switch order storage: ' . $e->getMessage() . "\n";
	}
	wp_cache_flush();
	return $util::custom_orders_table_usage_is_enabled() === $on;
}

/**
 * Import every order that is missing from wc_order_stats (left by a crashed run or a real store's
 * pending sync) so analytics checks do not depend on what earlier test files left behind.
 */
function blzmcp_it_sync_analytics(): void {
	global $wpdb;
	$util = \Automattic\WooCommerce\Utilities\OrderUtil::class;
	if ( ! class_exists( $util ) || ! class_exists( \Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::class ) ) {
		return;
	}
	$hpos  = $util::custom_orders_table_usage_is_enabled();
	$table = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
	$id    = $hpos ? 'id' : 'ID';
	$type  = $hpos ? 'type' : 'post_type';
	$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT o.%i FROM %i o LEFT JOIN %i s ON s.order_id = o.%i WHERE s.order_id IS NULL AND o.%i = %s', $id, $table, $wpdb->prefix . 'wc_order_stats', $id, $type, 'shop_order' ) );
	foreach ( (array) $ids as $order_id ) {
		\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( (int) $order_id );
	}
	if ( class_exists( \Blaze\McpToolkit\Bundled\AnalyticsGuard::class ) ) {
		\Blaze\McpToolkit\Bundled\AnalyticsGuard::clear_cache();
	}
}

function blzmcp_it_finish(): void {
	blzmcp_it_run_cleanups();
	echo "\n{$GLOBALS['blzmcp_it_passes']} passed, {$GLOBALS['blzmcp_it_failures']} failed\n";
	if ( $GLOBALS['blzmcp_it_failures'] > 0 ) {
		exit( 1 );
	}
}
