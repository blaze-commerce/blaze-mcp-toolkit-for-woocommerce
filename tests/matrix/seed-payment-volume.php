<?php
/**
 * Volume fixture for the payment-health cold performance budget (FEATURE-PLAN item 10).
 * Disposable test environments only (local wp-env or the matrix); never run it on a member store.
 *
 *   wp eval-file .../seed-payment-volume.php orders <offset> <count> <now>   Recent checkout orders (28 days)
 *   wp eval-file .../seed-payment-volume.php old <offset> <count> <now>      Older orders (30-400 days ago)
 *   wp eval-file .../seed-payment-volume.php outside <offset> <count> <now> <total>
 *                                                                            Excluded-origin orders (missing or non-checkout origin, 27 days)
 *   wp eval-file .../seed-payment-volume.php cap <offset> <count> <now>      Method-cap stress: one distinct outside method ID per order
 *                                                                            (offset 0 also seeds the cod checkout control orders)
 *   wp eval-file .../seed-payment-volume.php verify <recent> <old> [outside] Counts in the authoritative store (checkout and excluded
 *                                                                            origin separately), plus a CRUD sample
 *   wp eval-file .../seed-payment-volume.php diag <file|db> <now>            Near-budget Stripe logs and failed jobs, logger switched
 *   wp eval-file .../seed-payment-volume.php cleanup                         Remove every order, log and job this fixture made
 *   wp eval-file .../seed-payment-volume.php diag-cleanup                    Remove only the logs, jobs and logger settings
 *                                                                            (both print CLEANUP=<json>: what was removed and what remains)
 *   wp eval-file .../seed-payment-volume.php distribution                   Print the seed distribution as JSON
 *
 * Card-testing performance workload (1.3 FEATURE-PLAN item 9; independent of the payment cycle,
 * cleaned by the same cleanup, see payment-volume-cycle.php):
 *
 *   wp eval-file .../seed-payment-volume.php card-settings                  Save, then set, the 3 instant methods enabled and live
 *                                                                            and core order attribution on (CARD_SETTINGS=<json>)
 *   wp eval-file .../seed-payment-volume.php card-orders <offset> <count> <now> <total>
 *                                                                            Recent card orders over the 28-day history (bursts first)
 *   wp eval-file .../seed-payment-volume.php card-old <offset> <count> <now> <total>
 *                                                                            Older card orders (30-400 days ago, outside the history)
 *   wp eval-file .../seed-payment-volume.php card-cap-methods <offset> <count> <now>
 *                                                                            Method-cap stress: one distinct method ID per order
 *   wp eval-file .../seed-payment-volume.php card-cap-networks <offset> <count> <now>
 *                                                                            Network-cap stress: one IPv6 /64 per failed order
 *   wp eval-file .../seed-payment-volume.php card-verify <orders> <old> <now> Counts in the authoritative store plus a CRUD sample
 *                                                                            checked against each order's own spec (CARD_VERIFY=<json>)
 *   wp eval-file .../seed-payment-volume.php card-distribution <orders> <old> <now>
 *                                                                            Print the card seed distribution as JSON
 *   wp eval-file .../seed-payment-volume.php card-analytics-eligible <now>   Card orders WooCommerce Analytics must hold for the
 *                                                                            measured workflow (CARD_ANALYTICS_ELIGIBLE=<n>)
 *   wp eval-file .../seed-payment-volume.php card-analytics-import <offset> <count> <now>
 *                                                                            Import that slice synchronously (IMPORTED=<n>,
 *                                                                            FAILED_IMPORTS=<n>)
 *   wp eval-file .../seed-payment-volume.php card-analytics-ready <now> <period>
 *                                                                            The guard's readiness for that period with compare, its
 *                                                                            gap cache cleared first (CARD_ANALYTICS_READY=<json>)
 *
 * The full cleanup (cleanup) also restores the card settings; diag-cleanup leaves them.
 *
 * Analytics for the combined workflow cost (1.3 wave 3, REVIEW-pass4a R1). The weekly review plans
 * its order totals step only when the plugin's Analytics guard finds every counted order in
 * WooCommerce Analytics, and WooCommerce's own per-order import never runs in wp-env. So before
 * the workflow is measured, untimed and after every card trial, the card orders the guard counts
 * (its COUNTED_STATUSES, read from the guard) created in the 28-day history are imported
 * synchronously with OrdersScheduler::import() (card-analytics-import, chunked like the seeding).
 * card-analytics-ready then clears the guard's gap cache, asks the guard about exactly the
 * workflow's period with compare, and clears the cache again. Only the card workload is imported;
 * the payment-health workload never is. The orders cleanup also removes what an import leaves for
 * the fixture's orders: their Analytics rows (stats and lookups), WooCommerce's per-order import
 * jobs for them (hook OrdersScheduler::get_action( 'import' ), args array( $id ), group
 * wc-admin-data), their failed-import entries and the guest Analytics customers of the fixtures'
 * synthetic emails. It clears the guard's gap cache, and CLEANUP reports each of these, removed and
 * remaining.
 *
 * Orders go through WooCommerce CRUD (new WC_Order, one save), origin `checkout`, tagged with
 * meta `_blzmcp_perf=1`. Order i of N is created at now - 60 s - i * (28 days / N), so the recent
 * orders cover the whole 28-day history evenly. Methods and statuses come from a fixed cycle of 20
 * (DISTRIBUTION below). The excluded-origin population (1.2.1) and the method-cap fixture use the
 * same tag and come from payment-volume-cycle.php (BLZMCP_PERF_OUTSIDE, blzmcp_perf_cap_id()); they
 * never replace the checkout volume. Seeding is chunked so each PHP process stays small; seed time is never part
 * of a measurement. Analytics import and order emails are switched off in the seeding process only.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

// The cycle, the timeline and the expected counts are shared with measure-payment-health.php.
require_once __DIR__ . '/payment-volume-cycle.php';

$blzmcp_perf_args = array_values( (array) ( $args ?? array() ) );
$blzmcp_perf_cmd  = (string) ( $blzmcp_perf_args[0] ?? '' );

/**
 * Seeding process only: no mail, no HTTP, no Analytics import scheduling, no object cache growth.
 */
function blzmcp_perf_quiet(): void {
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_perf_blocked', 'blocked in the volume fixture' ), 1 );
	foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_status_changed', 'woocommerce_payment_complete' ) as $hook ) {
		remove_all_actions( $hook );
	}
	wp_suspend_cache_addition( true );
}

/**
 * Create one order through CRUD with a single save.
 *
 * @param int $i       Sequence number.
 * @param int $created Created (and paid) time.
 */
function blzmcp_perf_order( int $i, int $created ): void {
	list( $method, $status, $paid, $txn ) = BLZMCP_PERF_CYCLE[ $i % 20 ];
	$order                                = new WC_Order();
	$order->set_created_via( 'checkout' );
	$order->set_payment_method( $method );
	$order->set_currency( 'AUD' );
	$order->set_total( (string) ( 10 + ( $i % 90 ) ) . '.95' );
	$order->set_billing_email( 'blzmcp-perf-' . ( $i % 50 ) . '@example.invalid' );
	$order->set_date_created( $created );
	$order->set_status( $status );
	$order->set_date_paid( $paid ? $created + BLZMCP_PERF_PAID_AFTER : null );
	if ( $txn ) {
		$order->set_transaction_id( 'pi_synthetic_' . $i );
	}
	$order->update_meta_data( BLZMCP_PERF_META, '1' );
	$order->save();
}

/**
 * One paid, processing order outside verified checkout activity, through CRUD with a single save.
 *
 * @param string $method  Payment method ID.
 * @param string $via     Recorded origin ('' = empty).
 * @param int    $created Created time (paid 30 s later).
 * @param int    $i       Sequence number.
 */
function blzmcp_perf_outside_order( string $method, string $via, int $created, int $i ): void {
	$order = new WC_Order();
	$order->set_created_via( $via );
	$order->set_payment_method( $method );
	$order->set_currency( 'AUD' );
	$order->set_total( (string) ( 10 + ( $i % 90 ) ) . '.50' );
	$order->set_billing_email( 'blzmcp-perf-out-' . ( $i % 50 ) . '@example.invalid' );
	$order->set_date_created( $created );
	$order->set_status( 'processing' );
	$order->set_date_paid( $created + BLZMCP_PERF_PAID_AFTER );
	$order->update_meta_data( BLZMCP_PERF_META, '1' );
	$order->save();
}

/**
 * Tagged order IDs in either order store.
 *
 * @return array<int,int>
 */
function blzmcp_perf_ids(): array {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, BLZMCP_PERF_META ) ) );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META ) ) ) );
	}
	return array_values( array_unique( $ids ) );
}

/** WooCommerce Analytics tables keyed by order ID that an import writes (stats first). */
const BLZMCP_PERF_ANALYTICS_TABLES = array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' );

/** WooCommerce's order importer (its per-order import job hook comes from get_action( 'import' )). */
const BLZMCP_PERF_ORDERS_SCHEDULER = 'Automattic\\WooCommerce\\Internal\\Admin\\Schedulers\\OrdersScheduler';

/** The plugin's Analytics guard, whose checks the card workload's import follows. */
const BLZMCP_PERF_ANALYTICS_GUARD = 'Blaze\\McpToolkit\\Bundled\\AnalyticsGuard';

/**
 * Does this table exist?
 *
 * @param string $table Full table name.
 */
function blzmcp_perf_table( string $table ): bool {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/**
 * Rows of these order IDs in one Analytics table (chunked).
 *
 * @param string $table Table without prefix (BLZMCP_PERF_ANALYTICS_TABLES).
 * @param int[]  $ids   Order IDs.
 */
function blzmcp_perf_analytics_count( string $table, array $ids ): int {
	global $wpdb;
	if ( array() === $ids || ! blzmcp_perf_table( $wpdb->prefix . $table ) ) {
		return 0;
	}
	$n = 0;
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$n += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE order_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')' );
	}
	return $n;
}

/**
 * WooCommerce's own per-order Analytics import jobs for these order IDs (hook
 * OrdersScheduler::get_action( 'import' ), args array( $id ), group wc-admin-data), in any status,
 * as action ID => status. One query on the hook and group; the args are matched here.
 *
 * @param int[] $ids Order IDs.
 * @return array<int,string>
 */
function blzmcp_perf_import_jobs( array $ids ): array {
	global $wpdb;
	if ( array() === $ids || ! blzmcp_perf_table( $wpdb->prefix . 'actionscheduler_actions' ) || ! blzmcp_perf_table( $wpdb->prefix . 'actionscheduler_groups' ) ) {
		return array();
	}
	$sched = BLZMCP_PERF_ORDERS_SCHEDULER;
	$hook  = class_exists( $sched ) ? (string) $sched::get_action( 'import' ) : 'wc-admin_import_orders';
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT a.action_id, a.args, a.status FROM %i a JOIN %i g ON g.group_id = a.group_id WHERE a.hook = %s AND g.slug = %s', $wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_groups', $hook, 'wc-admin-data' ), ARRAY_A );
	$set   = array_flip( array_map( 'intval', $ids ) );
	$jobs  = array();
	foreach ( (array) $rows as $row ) {
		$a = json_decode( (string) $row['args'], true );
		if ( is_array( $a ) && 1 === count( $a ) && is_numeric( $a[0] ?? null ) && isset( $set[ (int) $a[0] ] ) ) {
			$jobs[ (int) $row['action_id'] ] = (string) $row['status'];
		}
	}
	return $jobs;
}

/**
 * Order IDs on WooCommerce's failed Analytics import list (WooCommerce 11.0+; empty before).
 *
 * @return int[]
 */
function blzmcp_perf_failed_imports(): array {
	$sched = BLZMCP_PERF_ORDERS_SCHEDULER;
	if ( ! class_exists( $sched ) || ! method_exists( $sched, 'get_failed_order_imports' ) ) {
		return array();
	}
	$failed = $sched::get_failed_order_imports();
	return array_map( 'intval', (array) ( $failed['ids'] ?? array() ) );
}

/**
 * Guest Analytics customers (wc_customer_lookup, no user) of the fixtures' own synthetic emails.
 *
 * @return int[] Customer IDs.
 */
function blzmcp_perf_fixture_customers(): array {
	global $wpdb;
	$table = $wpdb->prefix . 'wc_customer_lookup';
	if ( ! blzmcp_perf_table( $table ) ) {
		return array();
	}
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT customer_id FROM %i WHERE user_id IS NULL AND ( email LIKE %s OR email LIKE %s )', $table, $wpdb->esc_like( 'blzmcp-card-' ) . '%@example.invalid', $wpdb->esc_like( 'blzmcp-perf-' ) . '%@example.invalid' ) ) );
}

/**
 * The statuses the plugin's Analytics guard requires in wc_order_stats (its COUNTED_STATUSES), read
 * from the guard itself so the import follows it; null when they cannot be read.
 *
 * @return string[]|null
 */
function blzmcp_card_perf_counted_statuses(): ?array {
	try {
		$statuses = ( new ReflectionClassConstant( BLZMCP_PERF_ANALYTICS_GUARD, 'COUNTED_STATUSES' ) )->getValue();
	} catch ( \Throwable $e ) {
		return null;
	}
	return is_array( $statuses ) && array() !== $statuses ? array_values( array_map( 'strval', $statuses ) ) : null;
}

/**
 * The card workload's orders WooCommerce Analytics must hold for the measured workflow (1.3 wave 3,
 * REVIEW-pass4a R1): every card fixture order in the authoritative store in a status the guard
 * counts, created in the 28-day history before $now (that covers the windows the weekly review's
 * guard probes, last_7_days and the 7 days before), by ID. Null when they cannot be read.
 *
 * @param int $now Seed reference time.
 * @return int[]|null
 */
function blzmcp_card_perf_analytics_ids( int $now ): ?array {
	global $wpdb;
	$statuses = blzmcp_card_perf_counted_statuses();
	if ( null === $statuses ) {
		return null;
	}
	$in   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$from = gmdate( 'Y-m-d H:i:s', $now - 28 * DAY_IN_SECONDS );
	$like = $wpdb->esc_like( 'card:' ) . '%';
	if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$sql  = "SELECT DISTINCT o.id FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s AND m.meta_value LIKE %s WHERE o.type = %s AND o.status IN ({$in}) AND o.date_created_gmt >= %s ORDER BY o.id ASC";
		$args = array_merge( array( $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META, $like, 'shop_order' ), $statuses, array( $from ) );
	} else {
		$sql  = "SELECT DISTINCT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s WHERE p.post_type = %s AND p.post_status IN ({$in}) AND p.post_date_gmt >= %s ORDER BY p.ID ASC";
		$args = array_merge( array( $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, $like, 'shop_order' ), $statuses, array( $from ) );
	}
	$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
	return '' === $wpdb->last_error ? array_map( 'intval', $ids ) : null;
}

/**
 * Import one slice of the eligible card orders into WooCommerce Analytics synchronously
 * (OrdersScheduler::import(), as tests/matrix/fixture.php does; a CRUD save only schedules an
 * asynchronous import that never runs in wp-env). Seeding-style quiet process, never timed.
 *
 * @param int $offset Slice start in the eligible ID list.
 * @param int $count  Slice size.
 * @param int $now    Seed reference time.
 * @return array{imported:int,failed:int,error:?string}
 */
function blzmcp_card_perf_analytics_import( int $offset, int $count, int $now ): array {
	$sched = BLZMCP_PERF_ORDERS_SCHEDULER;
	$ids   = blzmcp_card_perf_analytics_ids( $now );
	if ( null === $ids || ! class_exists( $sched ) ) {
		return array(
			'imported' => 0,
			'failed'   => 0,
			'error'    => null === $ids ? 'eligible orders unreadable' : 'no WooCommerce order importer',
		);
	}
	$slice = array_slice( $ids, $offset, $count );
	foreach ( $slice as $id ) {
		$sched::import( $id );
	}
	return array(
		'imported' => count( $slice ),
		'failed'   => count( array_intersect( $slice, blzmcp_perf_failed_imports() ) ),
		'error'    => null,
	);
}

/**
 * Analytics readiness for the measured workflow, after the import and outside every timed call:
 * the eligible orders and their Analytics rows, then the guard's gap cache cleared and the guard
 * asked for exactly the workflow's period with compare (AbstractWorkflow's own call), then the
 * cache cleared again so the workflow's plan probes afresh and no clean result is left behind.
 *
 * @param int    $now    Seed reference time.
 * @param string $period The measured workflow's period.
 * @return array<string,mixed>
 */
function blzmcp_card_perf_analytics_ready( int $now, string $period ): array {
	$guard = BLZMCP_PERF_ANALYTICS_GUARD;
	$ids   = blzmcp_card_perf_analytics_ids( $now );
	$out   = array(
		'period'              => $period,
		'compare'             => true,
		'reason'              => null === $ids ? 'eligible_orders_unreadable' : 'guard_unavailable',
		'cache_cleared'       => false,
		'eligible'            => null === $ids ? null : count( $ids ),
		'stats_rows'          => null,
		'missing'             => null,
		'failed_imports'      => null,
		'pending_import_jobs' => null,
		'counted_statuses'    => blzmcp_card_perf_counted_statuses(),
		'date_basis'          => null,
		'windows'             => array(),
		'ok'                  => false,
	);
	if ( null === $ids || ! class_exists( $guard ) ) {
		return $out;
	}
	$stats                      = blzmcp_perf_analytics_count( 'wc_order_stats', $ids );
	$out['stats_rows']          = $stats;
	$out['missing']             = count( $ids ) - $stats;
	$out['failed_imports']      = count( array_intersect( blzmcp_perf_failed_imports(), $ids ) );
	$out['pending_import_jobs'] = count( array_filter( blzmcp_perf_import_jobs( $ids ), static fn( $s ) => 'pending' === $s ) );
	$input                      = array(
		'period'  => $period,
		'compare' => true,
	);
	$guard::clear_cache();
	$out['cache_cleared'] = false === get_transient( $guard::GAP_TRANSIENT );
	$out['reason']        = $guard::unavailable_reason( $input );
	$out['windows']       = array_column( $guard::windows( $input ), 'key' );
	$out['date_basis']    = $guard::date_basis();
	$guard::clear_cache();
	$out['ok'] = null === $out['reason'] && $out['cache_cleared'] && count( $ids ) > 0 && 0 === $out['missing'] && 0 === $out['failed_imports'];
	return $out;
}

/**
 * Delete tagged rows from both stores in chunks (test-only, scoped to the fixture's own IDs), with
 * what a WooCommerce Analytics import of them left: their Analytics rows, WooCommerce's per-order
 * import jobs for them, the guest Analytics customers of the fixtures' synthetic emails and their
 * IDs on the failed-import list. What was removed is kept for blzmcp_perf_cleanup_result().
 */
function blzmcp_perf_cleanup( bool $orders = true ): int {
	global $wpdb;
	$ids       = $orders ? blzmcp_perf_ids() : array();
	$hpos      = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) );
	$stats     = $orders && blzmcp_perf_table( $wpdb->prefix . 'wc_order_stats' );
	$customers = array();
	$removed   = array(
		'stats'  => 0,
		'lookup' => 0,
	);
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		if ( $stats ) {
			// The Analytics customers these orders' rows name, read before the rows go.
			$customers = array_merge( $customers, array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_order_stats WHERE order_id IN ({$in}) AND customer_id IS NOT NULL" ) ) );
		}
		$wpdb->query( "DELETE im FROM {$wpdb->prefix}woocommerce_order_itemmeta im JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = im.order_item_id WHERE oi.order_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type IN ('shop_order', 'shop_order_placehold')" );
		if ( $hpos ) {
			foreach ( array( 'wc_orders_meta' => 'order_id', 'wc_order_addresses' => 'order_id', 'wc_order_operational_data' => 'order_id', 'wc_orders' => 'id' ) as $table => $col ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE {$col} IN ({$in})" );
			}
		}
		foreach ( BLZMCP_PERF_ANALYTICS_TABLES as $table ) {
			$n = $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE order_id IN ({$in})" );
			$removed[ 'wc_order_stats' === $table ? 'stats' : 'lookup' ] += is_int( $n ) ? $n : 0;
		}
	}
	// WooCommerce's own per-order Analytics import jobs for these orders (none is expected: the
	// seeding processes unhook the saves that schedule them), with their logs.
	$jobs = array_keys( blzmcp_perf_import_jobs( $ids ) );
	foreach ( array_chunk( $jobs, 500 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$in})" );
	}
	// Guest Analytics customers an import created: those the orders' rows named and those of the
	// fixtures' synthetic emails, never a registered user's row or one another order still names.
	$customers_removed = 0;
	$lookup            = $wpdb->prefix . 'wc_customer_lookup';
	if ( $orders && blzmcp_perf_table( $lookup ) ) {
		foreach ( array_chunk( array_values( array_unique( array_merge( $customers, blzmcp_perf_fixture_customers() ) ) ), 1000 ) as $chunk ) {
			$in                 = implode( ',', array_map( 'intval', $chunk ) );
			$n                  = $wpdb->query( "DELETE c FROM {$lookup} c LEFT JOIN {$wpdb->prefix}wc_order_stats s ON s.customer_id = c.customer_id WHERE c.customer_id IN ({$in}) AND c.user_id IS NULL AND s.order_id IS NULL" );
			$customers_removed += is_int( $n ) ? $n : 0;
		}
	}
	// These orders' IDs on WooCommerce's failed-import list (a tax lookup write that failed).
	$failed = array_values( array_intersect( blzmcp_perf_failed_imports(), $ids ) );
	$sched  = BLZMCP_PERF_ORDERS_SCHEDULER;
	foreach ( method_exists( $sched, 'clear_failed_order_import' ) ? $failed : array() as $id ) {
		$sched::clear_failed_order_import( $id );
	}
	// No Analytics result of the fixture survives: the guard's gap probe and the reports cache.
	$guard         = BLZMCP_PERF_ANALYTICS_GUARD;
	$cache_cleared = false;
	if ( $orders && class_exists( $guard ) ) {
		$guard::clear_cache();
		$cache_cleared = false === get_transient( $guard::GAP_TRANSIENT );
	}
	if ( $removed['stats'] + $removed['lookup'] > 0 && class_exists( 'WC_Cache_Helper' ) ) {
		\WC_Cache_Helper::get_transient_version( 'woocommerce_reports', true );
	}
	$GLOBALS['blzmcp_perf_last_cleanup'] = array(
		'ids'               => $ids,
		'stats_removed'     => $removed['stats'],
		'lookup_removed'    => $removed['lookup'],
		'customers_removed' => $customers_removed,
		'import_jobs'       => count( $jobs ),
		'failed_cleared'    => count( $failed ),
		'cache_cleared'     => $cache_cleared,
	);
	// Logs and failed jobs this fixture made (message prefix / args marker), then the logger setting.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'woocommerce_log' ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE message LIKE %s', $wpdb->prefix . 'woocommerce_log', $wpdb->esc_like( BLZMCP_PERF_PREFIX ) . '%' ) );
	}
	$dir = blzmcp_perf_log_dir();
	foreach ( null === $dir ? array() : (array) glob( $dir . '/woocommerce-gateway-stripe-*-blzmcpperf*.log' ) as $file ) {
		unlink( $file );
	}
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l JOIN %i a ON a.action_id = l.action_id WHERE a.args LIKE %s', $wpdb->prefix . 'actionscheduler_logs', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE args LIKE %s', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) );
	}
	$saved = get_option( BLZMCP_PERF_SAVED, null );
	if ( is_array( $saved ) ) {
		foreach ( $saved as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		delete_option( BLZMCP_PERF_SAVED );
	}
	// Card workload settings (1.3): restored only by the full cleanup, never by diag-cleanup.
	$card = $orders ? get_option( BLZMCP_CARD_PERF_SAVED, null ) : null;
	if ( is_array( $card ) ) {
		foreach ( $card as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		delete_option( BLZMCP_CARD_PERF_SAVED );
	}
	wp_cache_flush_group( 'orders' );
	return count( $ids );
}

/**
 * What is left of the fixture after a cleanup, as a machine-readable result (wave 4: the cleanup
 * outcome is part of the performance receipt).
 *
 * @param string   $mode    orders|diagnostics.
 * @param int|null $removed Orders removed (null for a diagnostics-only cleanup).
 * @return array<string,mixed>
 */
function blzmcp_perf_cleanup_result( string $mode, ?int $removed ): array {
	global $wpdb;
	$logs  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'woocommerce_log' ) ) )
		? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE message LIKE %s', $wpdb->prefix . 'woocommerce_log', $wpdb->esc_like( BLZMCP_PERF_PREFIX ) . '%' ) )
		: 0;
	$dir   = blzmcp_perf_log_dir();
	$files = null === $dir ? 0 : count( (array) glob( $dir . '/woocommerce-gateway-stripe-*-blzmcpperf*.log' ) );
	$jobs  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) )
		? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE args LIKE %s', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) )
		: 0;
	$tagged   = count( blzmcp_perf_ids() );
	$settings = null === get_option( BLZMCP_PERF_SAVED, null ) && ( 'diagnostics' === $mode || null === get_option( BLZMCP_CARD_PERF_SAVED, null ) );
	$diag_ok  = 0 === $logs && 0 === $files && 0 === $jobs && $settings;
	// Analytics leftovers (1.3 wave 3), for an orders cleanup only: rows of the removed orders in
	// the Analytics tables, WooCommerce's import jobs for them, their failed-import entries and the
	// guest Analytics customers of the fixtures' synthetic emails must all be gone.
	$last      = 'orders' === $mode && is_array( $GLOBALS['blzmcp_perf_last_cleanup'] ?? null ) ? $GLOBALS['blzmcp_perf_last_cleanup'] : null;
	$analytics = array(
		'analytics_stats_removed'       => null,
		'analytics_lookup_rows_removed' => null,
		'analytics_customers_removed'   => null,
		'import_jobs_removed'           => null,
		'failed_imports_cleared'        => null,
		'analytics_cache_cleared'       => null,
		'analytics_rows_remaining'      => null,
		'analytics_customers_remaining' => null,
		'import_jobs_remaining'         => null,
		'failed_imports_remaining'      => null,
	);
	$analytics_ok = 'diagnostics' === $mode;
	if ( null !== $last ) {
		$ids  = (array) $last['ids'];
		$rows = 0;
		foreach ( BLZMCP_PERF_ANALYTICS_TABLES as $table ) {
			$rows += blzmcp_perf_analytics_count( $table, $ids );
		}
		$analytics    = array(
			'analytics_stats_removed'       => (int) $last['stats_removed'],
			'analytics_lookup_rows_removed' => (int) $last['lookup_removed'],
			'analytics_customers_removed'   => (int) $last['customers_removed'],
			'import_jobs_removed'           => (int) $last['import_jobs'],
			'failed_imports_cleared'        => (int) $last['failed_cleared'],
			'analytics_cache_cleared'       => true === $last['cache_cleared'],
			'analytics_rows_remaining'      => $rows,
			'analytics_customers_remaining' => count( blzmcp_perf_fixture_customers() ),
			'import_jobs_remaining'         => count( blzmcp_perf_import_jobs( $ids ) ),
			'failed_imports_remaining'      => count( array_intersect( blzmcp_perf_failed_imports(), $ids ) ),
		);
		$analytics_ok = 0 === $analytics['analytics_rows_remaining'] && 0 === $analytics['analytics_customers_remaining'] && 0 === $analytics['import_jobs_remaining'] && 0 === $analytics['failed_imports_remaining'];
	}
	return array(
		'mode'                     => $mode,
		'orders_removed'           => $removed,
		'tagged_remaining'         => $tagged,
		'diag_log_rows_remaining'  => $logs,
		'diag_log_files_remaining' => $files,
		'diag_jobs_remaining'      => $jobs,
		'settings_restored'        => $settings,
	) + $analytics + array(
		'ok' => $diag_ok && $analytics_ok && ( 'diagnostics' === $mode || 0 === $tagged ),
	);
}

/** WooCommerce's resolved log directory, or null. */
function blzmcp_perf_log_dir(): ?string {
	$dir = class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' ) ? \Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory() : null;
	return is_string( $dir ) && '' !== $dir ? rtrim( $dir, '/' ) : null;
}

/**
 * Near-budget Stripe logs for the chosen handler, plus failed Stripe jobs inside the window.
 *
 * @param string $handler file|db.
 * @param int    $now     Reference time.
 */
function blzmcp_perf_diag( string $handler, int $now ): array {
	global $wpdb;
	if ( ! is_array( get_option( BLZMCP_PERF_SAVED, null ) ) ) {
		update_option(
			BLZMCP_PERF_SAVED,
			array(
				'woocommerce_logs_default_handler'   => get_option( 'woocommerce_logs_default_handler', null ),
				'woocommerce_logs_logging_enabled'   => get_option( 'woocommerce_logs_logging_enabled', null ),
				'woocommerce_logs_level_threshold'   => get_option( 'woocommerce_logs_level_threshold', null ),
				'woocommerce_logs_retention_period_days' => get_option( 'woocommerce_logs_retention_period_days', null ),
			),
			false
		);
	}
	update_option( 'woocommerce_logs_logging_enabled', 'yes' );
	update_option( 'woocommerce_logs_level_threshold', 'none' );
	update_option( 'woocommerce_logs_retention_period_days', 30 );
	update_option( 'woocommerce_logs_default_handler', 'db' === $handler ? 'WC_Log_Handler_DB' : 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2' );
	$out = array( 'handler' => $handler );
	if ( 'file' === $handler ) {
		$dir = blzmcp_perf_log_dir();
		if ( null === $dir ) {
			return array( 'error' => 'no log directory' );
		}
		wp_mkdir_p( $dir );
		$bytes = 0;
		// 7 files of about 720 KB each: 5.04 MB of the 5,242,880-byte shared budget.
		for ( $f = 0; $f < 7; $f++ ) {
			$day  = $now - $f * DAY_IN_SECONDS;
			$path = $dir . '/woocommerce-gateway-stripe-' . gmdate( 'Y-m-d', $day ) . '-blzmcpperf' . $f . '.log';
			$buf  = '';
			for ( $l = 0; strlen( $buf ) < 720000; $l++ ) {
				$ts    = gmdate( 'Y-m-d\TH:i:s', $day - $l * 20 ) . '+00:00';
				$level = 0 === $l % 25 ? 'ERROR' : 'INFO';
				$buf  .= $ts . ' ' . $level . ' ' . BLZMCP_PERF_PREFIX . 'synthetic gateway line ' . $l . ' ' . str_repeat( 'x', 120 ) . "\n";
			}
			file_put_contents( $path, $buf );
			$bytes += strlen( $buf );
		}
		$out['log_files'] = 7;
		$out['log_bytes'] = $bytes;
	} else {
		$table = $wpdb->prefix . 'woocommerce_log';
		$rows  = 0;
		// 2,000 error rows of about 2 KB (the row cap) plus 1 more for the sentinel, and info noise.
		for ( $r = 0; $r < 2600; $r++ ) {
			$wpdb->insert(
				$table,
				array(
					'timestamp' => gmdate( 'Y-m-d H:i:s', $now - 60 - $r * 30 ),
					'level'     => $r < 2001 ? 500 : 200,
					'source'    => 'woocommerce-gateway-stripe',
					'message'   => BLZMCP_PERF_PREFIX . 'synthetic gateway error ' . $r . ' ' . str_repeat( 'y', 2000 ),
				)
			);
			++$rows;
		}
		$out['log_rows'] = $rows;
	}
	// 40 failed Stripe jobs inside the last 24 hours (marked so cleanup finds them).
	$jobs = 0;
	if ( class_exists( 'ActionScheduler' ) ) {
		$store = ActionScheduler::store();
		for ( $j = 0; $j < 40; $j++ ) {
			$id = $store->save_action( new ActionScheduler_Action( 'wc_stripe_deferred_webhook', array( 'blzmcp_perf' => $j ), new ActionScheduler_SimpleSchedule( new ActionScheduler_DateTime( '@' . ( $now - 3600 - $j * 600 ) ) ), 'woocommerce_stripe' ) );
			$store->mark_failure( $id );
			++$jobs;
		}
		// mark_failure() leaves the attempt time empty; the reader windows by last attempt.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_attempt_gmt = scheduled_date_gmt, last_attempt_local = scheduled_date_local WHERE args LIKE %s', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) );
		$out['failed_jobs'] = $jobs;
		$out['action_store'] = get_class( $store );
	}
	return $out;
}

/**
 * Create one card workload order through CRUD with a single save (1.3, FEATURE-PLAN item 9).
 *
 * @param array<string,mixed> $s   blzmcp_card_perf_spec() / cap spec.
 * @param string              $tag Fixture tag value (card:<kind>:<n>).
 * @param int                 $i   Sequence number.
 */
function blzmcp_card_perf_order( array $s, string $tag, int $i ): void {
	$order = new WC_Order();
	$order->set_created_via( (string) $s['via'] );
	$order->set_payment_method( (string) $s['method'] );
	$order->set_currency( 'AUD' );
	$order->set_total( (string) ( 10 + ( $i % 90 ) ) . '.25' );
	$order->set_billing_email( 'blzmcp-card-' . ( $i % 50 ) . '@example.invalid' );
	if ( '' !== $s['ip'] ) {
		$order->set_customer_ip_address( (string) $s['ip'] );
	}
	$order->set_date_created( (int) $s['created'] );
	$order->set_status( (string) $s['status'] );
	$order->set_date_paid( $s['paid'] );
	if ( null !== $s['attr'] ) {
		$order->update_meta_data( '_wc_order_attribution_source_type', (string) $s['attr'] );
	}
	$order->update_meta_data( BLZMCP_PERF_META, $tag );
	$order->save();
}

/**
 * Save the card settings once (their previous values, or null when absent), then set them.
 *
 * @return array<string,mixed>
 */
function blzmcp_card_perf_settings(): array {
	if ( ! is_array( get_option( BLZMCP_CARD_PERF_SAVED, null ) ) ) {
		$saved = array();
		foreach ( array_keys( BLZMCP_CARD_PERF_SETTINGS ) as $name ) {
			$saved[ $name ] = get_option( $name, null );
		}
		update_option( BLZMCP_CARD_PERF_SAVED, $saved, false );
	}
	foreach ( BLZMCP_CARD_PERF_SETTINGS as $name => $value ) {
		update_option( $name, $value );
	}
	$ok = true;
	foreach ( BLZMCP_CARD_PERF_SETTINGS as $name => $value ) {
		$ok = $ok && get_option( $name, null ) === $value;
	}
	$kinds = array();
	if ( class_exists( '\Blaze\McpToolkit\Diagnostics\PaymentMethods' ) ) {
		foreach ( BLZMCP_CARD_PERF_METHODS as $id ) {
			$kinds[ $id ] = \Blaze\McpToolkit\Diagnostics\PaymentMethods::kind( $id );
		}
	}
	return array(
		'ok'          => $ok && is_array( get_option( BLZMCP_CARD_PERF_SAVED, null ) ) && array( 'instant', 'instant', 'instant' ) === array_values( $kinds ),
		'options'     => array_keys( BLZMCP_CARD_PERF_SETTINGS ),
		'kinds'       => $kinds,
		'stripe_mode' => class_exists( '\Blaze\McpToolkit\Diagnostics\PaymentMethods' ) ? \Blaze\McpToolkit\Diagnostics\PaymentMethods::stripe_mode() : null,
	);
}

/**
 * The card workload in the authoritative store, checked against its own spec (seed time).
 *
 * @param int $want_orders Recent orders seeded.
 * @param int $want_old    Older orders seeded.
 * @param int $seed_now    Seed reference time.
 * @return array<string,mixed>
 */
function blzmcp_card_perf_verify( int $want_orders, int $want_old, int $seed_now ): array {
	global $wpdb;
	$hpos   = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$like   = $wpdb->esc_like( 'card:' ) . '%';
	$orders = $wpdb->prefix . 'wc_orders';
	$ometa  = $wpdb->prefix . 'wc_orders_meta';
	if ( $hpos ) {
		$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT o.id, o.status, m.meta_value AS tag FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s WHERE o.type = %s AND m.meta_value LIKE %s ORDER BY o.id ASC', $orders, $ometa, BLZMCP_PERF_META, 'shop_order', $like ), ARRAY_A );
		$untagged   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.type = %s AND o.status NOT IN ('trash', 'auto-draft') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.order_id = o.id AND m.meta_key = %s)", $orders, 'shop_order', $ometa, BLZMCP_PERF_META ) );
		$missing    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s AND m.meta_value LIKE %s LEFT JOIN %i d ON d.order_id = o.id WHERE o.type = %s AND ( d.created_via IS NULL OR d.created_via = '' )", $orders, $ometa, BLZMCP_PERF_META, $like, $wpdb->prefix . 'wc_order_operational_data', 'shop_order' ) );
		$attr_rows  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT a.order_id) FROM %i a JOIN %i m ON m.order_id = a.order_id AND m.meta_key = %s AND m.meta_value LIKE %s WHERE a.meta_key = %s', $ometa, $ometa, BLZMCP_PERF_META, $like, '_wc_order_attribution_source_type' ) );
		$ip_rows    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s AND m.meta_value LIKE %s WHERE o.type = %s AND o.ip_address IS NOT NULL AND o.ip_address <> ''", $orders, $ometa, BLZMCP_PERF_META, $like, 'shop_order' ) );
	} else {
		$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT p.ID AS id, p.post_status AS status, m.meta_value AS tag FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s AND m.meta_value LIKE %s ORDER BY p.ID ASC', $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, 'shop_order', $like ), ARRAY_A );
		$untagged   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s)", $wpdb->posts, 'shop_order', $wpdb->postmeta, BLZMCP_PERF_META ) );
		$missing    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s WHERE p.post_type = %s AND NOT EXISTS (SELECT 1 FROM %i v WHERE v.post_id = p.ID AND v.meta_key = %s AND v.meta_value <> '')", $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, $like, 'shop_order', $wpdb->postmeta, '_created_via' ) );
		$attr_rows  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT a.post_id) FROM %i a JOIN %i m ON m.post_id = a.post_id AND m.meta_key = %s AND m.meta_value LIKE %s WHERE a.meta_key = %s', $wpdb->postmeta, $wpdb->postmeta, BLZMCP_PERF_META, $like, '_wc_order_attribution_source_type' ) );
		$ip_rows    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT a.post_id) FROM %i a JOIN %i m ON m.post_id = a.post_id AND m.meta_key = %s AND m.meta_value LIKE %s WHERE a.meta_key = %s AND a.meta_value <> ''", $wpdb->postmeta, $wpdb->postmeta, BLZMCP_PERF_META, $like, '_customer_ip_address' ) );
	}
	$dist   = blzmcp_card_perf_distribution( $want_orders, $want_old, $seed_now );
	$count  = array(
		'r' => 0,
		'o' => 0,
	);
	$drafts = 0;
	$failed = 0;
	$ids    = array();
	foreach ( (array) $rows as $r ) {
		if ( 1 === preg_match( '/^card:([ro]):(\d+)$/', (string) $r['tag'], $m ) ) {
			++$count[ $m[1] ];
			$ids[] = array( (int) $r['id'], $m[1], (int) $m[2] );
		}
		$drafts += 'wc-checkout-draft' === $r['status'] ? 1 : 0;
		$failed += 'wc-failed' === $r['status'] ? 1 : 0;
	}
	// A CRUD sample of the first and last orders, each checked against its own spec. Field names
	// only are reported on a mismatch, never values.
	$sample   = array_merge( array_slice( $ids, 0, 25 ), array_slice( $ids, -10 ) );
	$crud_ok  = 0;
	$mismatch = array();
	foreach ( $sample as $row ) {
		list( $id, $kind, $n ) = $row;
		$s                     = blzmcp_card_perf_spec( $kind, $n, $seed_now, 'r' === $kind ? $want_orders : $want_old );
		$o                     = wc_get_order( $id );
		if ( ! $o instanceof WC_Order ) {
			$mismatch['order'] = true;
			continue;
		}
		$paid  = $o->get_date_paid();
		$made  = $o->get_date_created();
		$check = array(
			'method'  => $o->get_payment_method() === $s['method'],
			'status'  => $o->get_status() === $s['status'],
			'via'     => (string) $o->get_created_via() === $s['via'],
			'ip'      => (string) $o->get_customer_ip_address() === $s['ip'],
			'attr'    => (string) $o->get_meta( '_wc_order_attribution_source_type', true ) === (string) ( $s['attr'] ?? '' ),
			'created' => null !== $made && $made->getTimestamp() === $s['created'],
			'paid'    => null === $s['paid'] ? null === $paid : ( null !== $paid && $paid->getTimestamp() === $s['paid'] ),
		);
		if ( ! in_array( false, $check, true ) ) {
			++$crud_ok;
		}
		foreach ( $check as $field => $good ) {
			if ( ! $good ) {
				$mismatch[ $field ] = true;
			}
		}
	}
	$expected = $want_orders + $want_old;
	$checks   = array(
		'orders'         => count( $ids ) === $expected && $count['r'] === $want_orders && $count['o'] === $want_old,
		'drafts'         => $drafts === $dist['drafts'],
		'failed'         => $failed === $dist['failed'],
		'missing_origin' => $missing === $dist['missing_origin'],
		'attribution'    => $attr_rows === $dist['attribution_rows'],
		'ip'             => $ip_rows === $dist['ip_rows'],
		'crud'           => count( $sample ) > 0 && count( $sample ) === $crud_ok,
		'untagged'       => 0 === $untagged,
	);
	return array(
		'authority'               => $hpos ? 'hpos' : 'legacy',
		'sync'                    => get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ),
		'orders'                  => count( $ids ),
		'expected'                => $expected,
		'recent'                  => $count['r'],
		'old'                     => $count['o'],
		'drafts'                  => $drafts,
		'expected_drafts'         => $dist['drafts'],
		'failed'                  => $failed,
		'expected_failed'         => $dist['failed'],
		'missing_origin'          => $missing,
		'expected_missing_origin' => $dist['missing_origin'],
		'attribution_rows'        => $attr_rows,
		'expected_attribution'    => $dist['attribution_rows'],
		'ip_rows'                 => $ip_rows,
		'expected_ip_rows'        => $dist['ip_rows'],
		'crud_sample'             => count( $sample ),
		'crud_ok'                 => $crud_ok,
		'crud_mismatch'           => array_keys( $mismatch ),
		'untagged'                => $untagged,
		'checks'                  => $checks,
		'ok'                      => ! in_array( false, $checks, true ),
	);
}

switch ( $blzmcp_perf_cmd ) {
	case 'card-settings':
		echo 'CARD_SETTINGS=' . wp_json_encode( blzmcp_card_perf_settings() ) . "\n";
		break;
	case 'card-orders':
	case 'card-old':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$total  = (int) ( $blzmcp_perf_args[4] ?? 0 );
		$kind   = 'card-orders' === $blzmcp_perf_cmd ? 'r' : 'o';
		$made   = 0;
		for ( $i = $offset; $i < $offset + $count && $i < $total; $i++ ) {
			blzmcp_card_perf_order( blzmcp_card_perf_spec( $kind, $i, $now, $total ), 'card:' . $kind . ':' . $i, $i );
			++$made;
		}
		echo 'SEEDED=' . $made . "\n";
		break;
	case 'card-cap-methods':
	case 'card-cap-networks':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$net    = 'card-cap-networks' === $blzmcp_perf_cmd;
		$made   = 0;
		for ( $n = $offset; $n < $offset + $count; $n++ ) {
			blzmcp_card_perf_order( $net ? blzmcp_card_perf_cap_network_spec( $n, $now ) : blzmcp_card_perf_cap_method_spec( $n, $now ), 'card:' . ( $net ? 'n' : 'm' ) . ':' . $n, $n );
			++$made;
		}
		echo 'SEEDED=' . $made . "\n";
		break;
	case 'card-verify':
		echo 'CARD_VERIFY=' . wp_json_encode( blzmcp_card_perf_verify( (int) ( $blzmcp_perf_args[1] ?? 0 ), (int) ( $blzmcp_perf_args[2] ?? 0 ), (int) ( $blzmcp_perf_args[3] ?? 0 ) ) ) . "\n";
		break;
	case 'card-distribution':
		echo 'CARD_DISTRIBUTION=' . wp_json_encode( blzmcp_card_perf_distribution( (int) ( $blzmcp_perf_args[1] ?? 0 ), (int) ( $blzmcp_perf_args[2] ?? 0 ), (int) ( $blzmcp_perf_args[3] ?? 0 ) ) ) . "\n";
		break;
	case 'card-analytics-eligible':
		$ids = blzmcp_card_perf_analytics_ids( (int) ( $blzmcp_perf_args[1] ?? 0 ) );
		echo 'CARD_ANALYTICS_ELIGIBLE=' . ( null === $ids ? 'unreadable' : count( $ids ) ) . "\n";
		break;
	case 'card-analytics-import':
		blzmcp_perf_quiet();
		$import = blzmcp_card_perf_analytics_import( (int) ( $blzmcp_perf_args[1] ?? 0 ), (int) ( $blzmcp_perf_args[2] ?? 0 ), (int) ( $blzmcp_perf_args[3] ?? 0 ) );
		echo 'IMPORTED=' . $import['imported'] . "\n";
		echo 'FAILED_IMPORTS=' . $import['failed'] . "\n";
		if ( null !== $import['error'] ) {
			echo 'ERROR=' . $import['error'] . "\n";
		}
		break;
	case 'card-analytics-ready':
		echo 'CARD_ANALYTICS_READY=' . wp_json_encode( blzmcp_card_perf_analytics_ready( (int) ( $blzmcp_perf_args[1] ?? 0 ), (string) ( $blzmcp_perf_args[2] ?? '' ) ) ) . "\n";
		break;
	case 'orders':
	case 'old':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$total  = (int) ( $blzmcp_perf_args[4] ?? 50000 );
		for ( $i = $offset; $i < $offset + $count; $i++ ) {
			blzmcp_perf_order( $i, blzmcp_perf_created( $blzmcp_perf_cmd, $i, $now, $total ) );
		}
		echo 'SEEDED=' . $count . "\n";
		break;
	case 'outside':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$total  = (int) ( $blzmcp_perf_args[4] ?? 1000 );
		for ( $i = $offset; $i < $offset + $count; $i++ ) {
			list( $method, $via ) = BLZMCP_PERF_OUTSIDE[ $i % count( BLZMCP_PERF_OUTSIDE ) ];
			blzmcp_perf_outside_order( $method, $via, blzmcp_perf_outside_created( $i, $now, $total ), $i );
		}
		echo 'SEEDED=' . $count . "\n";
		break;
	case 'cap':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$made   = 0;
		if ( 0 === $offset ) {
			// The checkout control: cod checkout orders the cap must never change, 12 hours apart so
			// they span more than the 7 days the store-wide quiet check needs for complete coverage.
			for ( $c = 0; $c < BLZMCP_PERF_CAP_CONTROL; $c++ ) {
				$control = new WC_Order();
				$control->set_created_via( 'checkout' );
				$control->set_payment_method( 'cod' );
				$control->set_currency( 'AUD' );
				$control->set_total( '20.00' );
				$control->set_billing_email( 'blzmcp-perf-cap@example.invalid' );
				$control->set_date_created( $now - 7200 - $c * 43200 );
				$control->set_status( 'processing' );
				$control->set_date_paid( $now - 7200 - $c * 43200 + BLZMCP_PERF_PAID_AFTER );
				$control->update_meta_data( BLZMCP_PERF_META, '1' );
				$control->save();
				++$made;
			}
		}
		for ( $n = $offset; $n < $offset + $count; $n++ ) {
			blzmcp_perf_outside_order( blzmcp_perf_cap_id( $n ), 0 === $n % 2 ? '' : 'admin', $now - 3600 - $n * 300, $n );
			++$made;
		}
		echo 'SEEDED=' . $made . "\n";
		break;
	case 'verify':
		global $wpdb;
		$want_recent = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$want_old    = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$want_out    = (int) ( $blzmcp_perf_args[3] ?? 0 );
		$hpos        = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$ids         = $hpos
			? $wpdb->get_col( $wpdb->prepare( 'SELECT o.id FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s WHERE o.type = %s', $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META, 'shop_order' ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s', $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, 'shop_order' ) );
		// Other orders would change the counts the measurement must see exactly (FEATURE-PLAN item 10).
		$untagged    = (int) ( $hpos
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.type = %s AND o.status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'checkout-draft') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.order_id = o.id AND m.meta_key = %s)", $wpdb->prefix . 'wc_orders', 'shop_order', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'checkout-draft') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s)", $wpdb->posts, 'shop_order', $wpdb->postmeta, BLZMCP_PERF_META ) ) );
		// Checkout and excluded-origin orders counted separately, by the recorded origin.
		$checkout_n  = (int) ( $hpos
			? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s JOIN %i d ON d.order_id = o.id WHERE o.type = %s AND d.created_via = %s', $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META, $wpdb->prefix . 'wc_order_operational_data', 'shop_order', 'checkout' ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s JOIN %i v ON v.post_id = p.ID AND v.meta_key = %s WHERE p.post_type = %s AND v.meta_value = %s', $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, $wpdb->postmeta, '_created_via', 'shop_order', 'checkout' ) ) );
		$outside_n   = count( $ids ) - $checkout_n;
		// A CRUD sample of checkout orders and of excluded-origin orders.
		$sample      = array_merge( array_slice( $ids, 0, 25 ), array_slice( $ids, -10 ) );
		$crud_ok     = 0;
		foreach ( $sample as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o instanceof WC_Order && '1' === (string) $o->get_meta( BLZMCP_PERF_META ) ) {
				++$crud_ok;
			}
		}
		echo 'VERIFY=' . wp_json_encode(
			array(
				'authority'   => $hpos ? 'hpos' : 'legacy',
				'sync'        => get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ),
				'orders'      => count( $ids ),
				'expected'    => $want_recent + $want_old + $want_out,
				'checkout'    => $checkout_n,
				'outside'     => $outside_n,
				'crud_sample' => count( $sample ),
				'crud_ok'     => $crud_ok,
				'untagged'    => $untagged,
				'ok'          => count( $ids ) === $want_recent + $want_old + $want_out && $checkout_n === $want_recent + $want_old && $outside_n === $want_out && count( $sample ) === $crud_ok && 0 === $untagged,
			)
		) . "\n";
		break;
	case 'diag':
		echo 'DIAG=' . wp_json_encode( blzmcp_perf_diag( (string) ( $blzmcp_perf_args[1] ?? 'file' ), (int) ( $blzmcp_perf_args[2] ?? time() ) ) ) . "\n";
		break;
	case 'cleanup':
		$removed = blzmcp_perf_cleanup();
		echo 'REMOVED=' . $removed . "\n";
		echo 'CLEANUP=' . wp_json_encode( blzmcp_perf_cleanup_result( 'orders', $removed ) ) . "\n";
		break;
	case 'diag-cleanup':
		blzmcp_perf_cleanup( false );
		echo "REMOVED=diagnostics\n";
		echo 'CLEANUP=' . wp_json_encode( blzmcp_perf_cleanup_result( 'diagnostics', null ) ) . "\n";
		break;
	case 'distribution':
		$per = array();
		foreach ( BLZMCP_PERF_CYCLE as $row ) {
			$key         = $row[0] . ' ' . $row[1] . ( $row[2] ? ' paid' : ' unpaid' ) . ( $row[3] ? ' with reference' : '' );
			$per[ $key ] = ( $per[ $key ] ?? 0 ) + 1;
		}
		echo 'DISTRIBUTION=' . wp_json_encode( array( 'cycle' => 20, 'per_cycle' => $per, 'outside_cycle' => array_map( static fn( $r ) => $r[0] . ' origin ' . ( '' === $r[1] ? '(empty)' : $r[1] ), BLZMCP_PERF_OUTSIDE ) ) ) . "\n";
		break;
	default:
		echo "ERROR=usage: seed-payment-volume.php orders|old|outside|cap|verify|diag|cleanup|diag-cleanup|distribution|card-settings|card-orders|card-old|card-cap-methods|card-cap-networks|card-verify|card-distribution|card-analytics-eligible|card-analytics-import|card-analytics-ready\n";
		exit( 1 );
}
