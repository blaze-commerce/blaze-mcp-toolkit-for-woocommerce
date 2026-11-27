<?php
/**
 * Card fixture Analytics readiness (1.3 wave 3, REVIEW-pass4a R3). Disposable test sites only.
 *
 *   wp eval-file .../tests/matrix/test-card-fixture-analytics.php
 *   (scripts/run-integration.sh runs it in each authoritative storage, sync off.)
 *
 * The card HTTP cases expect the workflows' order-totals step, which the plugin's Analytics guard
 * rightly leaves out while orders are missing from WooCommerce Analytics. A CRUD save only queues an
 * asynchronous import (WooCommerce's per-order import job, or its batch import), which never runs
 * in wp-env. This test reproduces that cold-guard / pending-asynchronous-import case and shows the
 * card fixture's synchronous import closes it:
 * 1. A clean probe is cached first, so a fixture that trusted a cached result would be fooled.
 * 2. `seed-no-import` (the same orders, no import): the fixture's own fresh probe must report the
 *    gap, the import must still be pending, the guard (cache cleared) must find orders missing and
 *    the weekly review must leave the order-totals step out.
 * 3. `seed`: every order has its Analytics row, the fixture reports ready, the guard (cache cleared)
 *    finds nothing missing and the weekly review runs payment health, card testing, then order
 *    totals. Without the fixture's import this part fails.
 * 4. Each cleanup leaves no fixture order, log file, saved setting, Analytics row or pending import
 *    job behind.
 * Prints "<n> passed, <m> failed"; exits 1 on any failure.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use Blaze\McpToolkit\Bundled\AnalyticsGuard;

require_once dirname( __DIR__ ) . '/Integration/assert.php';
if ( ! defined( 'BLZMCP_CARDFX_LIBRARY' ) ) {
	define( 'BLZMCP_CARDFX_LIBRARY', true );
}
require_once __DIR__ . '/card-testing-fixture.php';

$auth = \Blaze\McpToolkit\Diagnostics\PaymentOrders::authority();
echo 'STORAGE  authority=' . ( $auth['authority'] ?? 'none' ) . ' sync=' . get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ) . "\n";
$expect_auth = (string) getenv( 'BLZMCP_EXPECT_AUTHORITY' );
if ( '' !== $expect_auth ) {
	blzmcp_it_equals( $expect_auth, $auth['authority'] ?? 'none', 'the order storage is the one this run was switched to' );
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
blzmcp_it_cleanup( static fn() => blzmcp_cardfx_run( 'cleanup' ) );
blzmcp_it_cleanup( array( AnalyticsGuard::class, 'clear_cache' ) );

/**
 * The weekly review's steps as "ability:subject".
 *
 * @return string[]
 */
function blzmcp_cfa_steps(): array {
	$ability = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' );
	if ( ! $ability ) {
		return array( 'no-workflow' );
	}
	$r = json_decode( (string) wp_json_encode( $ability->execute( array( 'period' => 'last_7_days' ) ) ), true );
	if ( ! is_array( $r ) || ! isset( $r['steps'] ) ) {
		return array( 'error' );
	}
	return array_map( static fn( $s ) => ( $s['ability'] ?? '' ) . ':' . ( $s['arguments']['subject'] ?? '' ), (array) $r['steps'] );
}

/**
 * The cleanup leaves nothing.
 *
 * @param string $label Label.
 */
function blzmcp_cfa_clean( string $label ): void {
	$c = blzmcp_cardfx_run( 'cleanup' );
	$s = blzmcp_cardfx_run( 'status' );
	blzmcp_it_equals( array( '0', '0', '1' ), array( $c['IMPORT_JOBS_LEFT'], $c['STATS_LEFT'], $c['SETTINGS_RESTORED'] ), "$label: cleanup leaves no pending import job or Analytics row and restores the settings" );
	blzmcp_it_equals( array( '0', '0', '0' ), array( $s['ORDERS_LEFT'], $s['LOGS_LEFT'], $s['SAVED_LEFT'] ), "$label: no fixture order, log file or saved settings left" );
}

$hook = blzmcp_cardfx_import_hook();
blzmcp_it_assert( '' !== $hook, "WooCommerce's per-order Analytics import hook is known ($hook)" );
$batch_mode = method_exists( OrdersScheduler::class, 'is_scheduled_import_enabled' ) && OrdersScheduler::is_scheduled_import_enabled();
echo 'NOTE  WooCommerce Analytics import mode: ' . ( $batch_mode ? 'scheduled batches' : 'per-order jobs' ) . "\n";

// 1. Prime the guard's cache with a clean probe of the fixture's window (nothing seeded yet).
blzmcp_cardfx_run( 'cleanup' );
AnalyticsGuard::clear_cache();
$now    = time();
$window = array(
	array(
		'key'  => 'card_fixture_history',
		'from' => $now - 28 * DAY_IN_SECONDS,
		'to'   => $now,
	),
);
blzmcp_it_equals( null, AnalyticsGuard::sync_gap( $window ), 'before seeding: the store has no order missing from Analytics (a clean probe is now cached)' );

// 2. The cold-guard / pending-asynchronous-import case.
$cold = blzmcp_cardfx_run( 'seed-no-import' );
blzmcp_it_assert( (int) ( $cold['SEEDED'] ?? 0 ) > 0, 'seed-no-import: the fixture orders were created (' . ( $cold['SEEDED'] ?? 'none' ) . ')' );
blzmcp_it_equals( '0', $cold['ANALYTICS_READY'] ?? null, "seed-no-import: the fixture's own fresh probe is not fooled by the cached clean probe (not ready)" );
blzmcp_it_assert( in_array( $cold['ANALYTICS_GAP_HISTORY'] ?? '', array( 'orders_not_imported', 'import_not_run' ), true ), 'seed-no-import: the guard reports the missing orders (' . ( $cold['ANALYTICS_GAP_HISTORY'] ?? 'none' ) . ')' );
$first   = (int) ( $cold['FIRST_ORDER'] ?? 0 );
$pending = $batch_mode || ( $first > 0 && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, array( $first ), 'wc-admin-data' ) );
blzmcp_it_assert( $pending, "seed-no-import: the import is still pending (WooCommerce's asynchronous import has not run)" );
AnalyticsGuard::clear_cache();
blzmcp_it_assert( null !== AnalyticsGuard::sync_gap( $window ), 'seed-no-import: the guard, cache cleared, finds orders missing from Analytics' );
AnalyticsGuard::clear_cache();
$steps = blzmcp_cfa_steps();
blzmcp_it_assert( ! in_array( 'wc-analytics/totals:orders', $steps, true ), 'seed-no-import: the weekly review leaves the order-totals step out (' . implode( ', ', $steps ) . ')' );
blzmcp_cfa_clean( 'seed-no-import' );

// 3. The fixture as the HTTP suite uses it: imported synchronously, then ready.
$warm = blzmcp_cardfx_run( 'seed' );
blzmcp_it_equals( $warm['SEEDED'] ?? 'none', $warm['IMPORTED'] ?? 'none', 'seed: every fixture order has its Analytics row' );
blzmcp_it_equals( array( 'none', 'none', '1' ), array( $warm['ANALYTICS_GAP_HISTORY'] ?? null, $warm['ANALYTICS_GAP_WORKFLOW'] ?? null, $warm['ANALYTICS_READY'] ?? null ), 'seed: the fixture reports Analytics ready over its 28 days and the workflows\' windows' );
AnalyticsGuard::clear_cache();
blzmcp_it_equals( null, AnalyticsGuard::sync_gap( $window ), 'seed: the guard, cache cleared, finds nothing missing' );
AnalyticsGuard::clear_cache();
$steps = blzmcp_cfa_steps();
blzmcp_it_equals( array( 'blaze-mcp-toolkit/payment-health:', 'blaze-mcp-toolkit/card-testing:', 'wc-analytics/totals:orders' ), array_slice( $steps, 0, 3 ), 'seed: the weekly review runs payment health, card testing, then order totals' );
$ready = blzmcp_cardfx_run( 'analytics-ready' );
blzmcp_it_equals( '1', $ready['ANALYTICS_READY'] ?? null, 'analytics-ready: the recheck the HTTP suite makes before its workflow cases agrees' );
blzmcp_cfa_clean( 'seed' );
AnalyticsGuard::clear_cache();

blzmcp_it_finish();
