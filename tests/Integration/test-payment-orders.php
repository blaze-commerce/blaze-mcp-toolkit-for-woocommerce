<?php
/**
 * PaymentOrders integration test (FEATURE-PLAN item 3): SQL aggregates against CRUD, both stores.
 *
 * Authority must be set in an earlier, separate process; this process only asserts what it finds.
 *   Switch:  npx wp-env run cli env BLZMCP_PF_SET=hpos BLZMCP_PF_SYNC=no wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-payment-orders.php
 *   Test:    npx wp-env run cli env BLZMCP_EXPECT_AUTHORITY=hpos BLZMCP_EXPECT_SYNC=no wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-payment-orders.php
 *   Restore: BLZMCP_PF_SET=legacy BLZMCP_PF_SYNC=no (the wp-env default).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/payment-fixtures.php';

use Blaze\McpToolkit\Diagnostics\PaymentOrders;
use Blaze\McpToolkit\Diagnostics\PaymentRules;

global $wpdb;

$set = getenv( 'BLZMCP_PF_SET' );
if ( is_string( $set ) && '' !== $set ) {
	$ok = blzmcp_it_set_hpos( 'hpos' === $set );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' === getenv( 'BLZMCP_PF_SYNC' ) ? 'yes' : 'no' );
	blzmcp_it_assert( $ok, "order storage switched to $set" );
	blzmcp_it_finish();
	return;
}

$expect_auth = (string) getenv( 'BLZMCP_EXPECT_AUTHORITY' );
$expect_sync = 'yes' === getenv( 'BLZMCP_EXPECT_SYNC' ) ? 'yes' : 'no';
blzmcp_pf_quiet_side_effects();

$auth = PaymentOrders::authority();
echo 'STORAGE  authority=' . ( $auth['authority'] ?? 'none' ) . ' sync=' . get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ) . "\n";
if ( '' !== $expect_auth ) {
	blzmcp_it_equals( $expect_auth, $auth['authority'], 'authority detected in this fresh process' );
	blzmcp_it_equals( $expect_sync, get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ), 'sync state as set by the earlier process' );
}
blzmcp_it_assert( $auth['ok'], 'authoritative core store is supported' );
$hpos = 'hpos' === $auth['authority'];
$tz   = wp_timezone();
$now  = time();

// ---------------------------------------------------------------- seed and compare with CRUD.
$ids = blzmcp_pf_seed_orders( $now );

// Legacy-only representations: `_paid_date` (site-local) only, and duplicate `_date_paid` rows.
if ( ! $hpos ) {
	$local_id = blzmcp_pf_order( array( 'method' => 'stripe', 'via' => 'checkout', 'status' => 'completed', 'created' => $now - 9 * DAY_IN_SECONDS, 'paid' => $now - 9 * DAY_IN_SECONDS ) );
	delete_post_meta( $local_id, '_date_paid' );
	update_post_meta( $local_id, '_paid_date', wp_date( 'Y-m-d H:i:s', $now - 8 * DAY_IN_SECONDS ) );
	$dup_id = blzmcp_pf_order( array( 'method' => 'stripe', 'via' => 'checkout', 'status' => 'completed', 'created' => $now - 12 * DAY_IN_SECONDS, 'paid' => $now - 11 * DAY_IN_SECONDS ) );
	add_post_meta( $dup_id, '_date_paid', (string) ( $now - 2 * HOUR_IN_SECONDS ) );
	wp_cache_flush();
	blzmcp_it_equals( $now - 8 * DAY_IN_SECONDS, wc_get_order( $local_id )->get_date_paid()->getTimestamp(), 'CRUD reads the local _paid_date fallback' );
	blzmcp_it_equals( $now - 11 * DAY_IN_SECONDS, wc_get_order( $dup_id )->get_date_paid()->getTimestamp(), 'CRUD reads the lowest meta_id duplicate' );
}

// Conflicting shadow rows in the non-authoritative store must be ignored.
$shadow_id = $ids['paid_recent'];
if ( $hpos ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d AND post_type = %s', $wpdb->posts, $shadow_id, 'shop_order' ) ) ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-pending' ), array( 'ID' => $shadow_id ) );
		update_post_meta( $shadow_id, '_payment_method', 'shadow_method' );
		echo "NOTE  conflicting posts shadow written for {$shadow_id}\n";
	}
} elseif ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) ) ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET payment_method = %s, status = %s WHERE id = %d', $wpdb->prefix . 'wc_orders', 'shadow_method', 'wc-pending', $shadow_id ) );
	echo "NOTE  conflicting HPOS shadow written for {$shadow_id} (if present)\n";
}
wp_cache_flush();

$got = PaymentOrders::read( $now, 24, $tz );
$exp = blzmcp_pf_expected( $now, 24 );
blzmcp_it_assert( $got['ok'], 'reader succeeded' );
blzmcp_it_equals( $auth['authority'], $got['authority'], 'reader used the authoritative store' );
blzmcp_pf_compare( $got['store'], $exp['store'], 'store-wide' );
foreach ( $exp['methods'] as $id => $want ) {
	blzmcp_it_assert( isset( $got['methods'][ $id ] ), "method {$id} is in the aggregate" );
	if ( isset( $got['methods'][ $id ] ) ) {
		blzmcp_pf_compare( $got['methods'][ $id ], $want, "method {$id}" );
	}
}
$exp_ids = array_map( 'strval', array_keys( $exp['methods'] ) );
sort( $exp_ids, SORT_STRING );
blzmcp_it_equals( $exp_ids, array_map( 'strval', array_keys( $got['methods'] ) ),'same method IDs as CRUD (shadow method ignored)' );
blzmcp_it_assert( ! isset( $got['methods']['shadow_method'] ), 'no shadow-store method' );
blzmcp_it_assert( ! isset( $got['methods'][''] ), 'never an empty-string method' );
foreach ( array( 'unknown_origin', 'other_origin', 'drafts', 'empty_method', 'invalid_timestamps' ) as $k ) {
	blzmcp_it_equals( $exp['excluded'][ $k ], $got['excluded'][ $k ], "excluded {$k} matches CRUD" );
}

// Specific semantics.
$stripe = $got['methods']['stripe'] ?? null;
blzmcp_it_assert( is_array( $stripe ), 'stripe aggregate present' );
blzmcp_it_assert( $got['methods']['failonly']['failed'] >= 1 && 0 === $got['methods']['failonly']['series']['count'], 'failed-only method stays in the aggregate with zero taken' );
blzmcp_it_equals( 1, $got['methods']['bacs']['current']['taken'], 'on-hold without a paid date is accepted, by creation' );
blzmcp_it_assert( $stripe['current']['transaction_recorded_unpaid'] >= 1, 'pending order with a transaction reference counted' );
blzmcp_it_assert( 1 === $got['methods']['cod']['series']['count'], 'event exactly at history start is included' );
blzmcp_it_assert( 'partial' === $got['coverage'] && $got['excluded']['invalid_timestamps'] >= 1, 'future paid date is rejected and disclosed as partial coverage' );
blzmcp_it_assert( false === $got['methods']['cheque']['complete'], 'the affected method is partial' );
blzmcp_it_assert( true === $got['methods']['bacs']['complete'], 'unaffected methods stay complete' );
$opaque = array_values( array_filter( array_keys( $got['methods'] ), static fn( $k ) => str_starts_with( (string) $k, 'unrecognised-' ) ) );
blzmcp_it_equals( 1, count( $opaque ), 'malformed method ID becomes one opaque reference' );
blzmcp_it_assert( ! str_contains( wp_json_encode( $got ), 'Ignore all rules' ), 'malformed method content never copied' );

// Examples: at most 5, transaction mismatch first, revalidated through CRUD, no customer fields.
blzmcp_it_assert( count( $stripe['example_ids'] ) <= PaymentOrders::MAX_EXAMPLES, 'examples capped' );
blzmcp_it_equals( $ids['pending_txn'], $stripe['example_ids'][0] ?? null, 'transaction mismatch is the first example' );
blzmcp_it_assert( ! in_array( $ids['pending_immature'], $stripe['example_ids'], true ), 'immature pending order is not an example' );
$examples = PaymentOrders::examples( $stripe['example_ids'], $auth['authority'], $tz );
blzmcp_it_equals( count( $stripe['example_ids'] ), count( $examples ), 'every example revalidated' );
$first = $examples[0] ?? array();
blzmcp_it_equals( array( 'number', 'created', 'status', 'total', 'currency', 'edit_url' ), array_keys( $first ), 'example has only the contract fields' );
$order = wc_get_order( $ids['pending_txn'] );
blzmcp_it_equals( (string) $ids['pending_txn'], $first['number'] ?? null, 'number is the internal ID as text' );
blzmcp_it_equals( wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), $first['total'] ?? null, 'total matches the order API with store decimals' );
blzmcp_it_equals( $order->get_currency(), $first['currency'] ?? null, 'currency matches the order API' );
blzmcp_it_equals( \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $ids['pending_txn'] ), $first['edit_url'] ?? null, 'edit URL matches the storage-correct helper' );
blzmcp_it_assert( ! str_contains( wp_json_encode( $examples ), 'pi_synthetic' ), 'transaction reference never returned' );

// An example deleted between aggregation and hydration is skipped; counts stay as read.
$before = $stripe['current'];
blzmcp_it_delete_order( $ids['cancelled_unpaid'] );
$after_examples = PaymentOrders::examples( $stripe['example_ids'], $auth['authority'], $tz );
blzmcp_it_equals( count( $examples ) - 1, count( $after_examples ), 'deleted example skipped' );
blzmcp_it_equals( $before, $stripe['current'], 'counts unaffected by hydration' );

// Exact boundaries of the current cohort.
$g2 = PaymentOrders::read( $now, 24, $tz );
$cur_ids = $g2['methods']['stripe']['example_ids'];
blzmcp_it_assert( in_array( $ids['at_window_start'], $cur_ids, true ) || $g2['methods']['stripe']['examples_truncated'], 'order created exactly at window start is in the current cohort' );
blzmcp_it_assert( ! in_array( $ids['at_mature_before'], $cur_ids, true ), 'order created exactly at mature-before is excluded' );
$e1 = PaymentOrders::read( $now, 1, $tz );
blzmcp_it_equals( array( 'pending' => 0, 'cancelled_unpaid' => 0, 'taken' => 0, 'transaction_recorded_unpaid' => 0 ), $e1['store']['current'], 'since_hours=1 has an empty mature cohort' );

// ---------------------------------------------------------------- failure paths fail closed.
$break = static fn( $q ) => ( str_contains( $q, '_blzmcp_never' ) || str_contains( $q, 'ORDER BY o.id ASC' ) || str_contains( $q, 'ORDER BY p.ID ASC LIMIT' ) ) ? 'SELECT broken_column FROM blzmcp_no_such_table' : $q;
add_filter( 'query', $break );
$wpdb->suppress_errors( true );
$failed = PaymentOrders::read( $now, 24, $tz );
$wpdb->suppress_errors( false );
remove_filter( 'query', $break );
blzmcp_it_assert( false === $failed['ok'] && 'unavailable' === $failed['coverage'] && 'read_failed' === $failed['reason'], 'SQL failure is unavailable, not zero' );
blzmcp_it_assert( null === $failed['store'], 'no counts on failure' );

$missing = static fn( $q ) => str_starts_with( $q, 'SHOW TABLES LIKE' ) && ( str_contains( $q, 'operational' ) || str_contains( $q, 'postmeta' ) ) ? "SHOW TABLES LIKE 'blzmcp_no_such_table'" : $q;
add_filter( 'query', $missing );
$m = PaymentOrders::read( $now, 24, $tz );
remove_filter( 'query', $missing );
blzmcp_it_equals( 'tables_missing', $m['reason'], 'missing required table is unavailable' );

// Pass 4 #3: a missing HPOS operational row fails closed; it is not an excluded unknown origin
// (that case, a present row with an empty origin, is the seeded `unknown_origin` order above).
$op_table = $wpdb->prefix . 'wc_order_operational_data';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $op_table ) ) ) ) {
	$gap_id = blzmcp_pf_order( array( 'method' => 'stripe', 'via' => 'checkout', 'status' => 'processing', 'created' => $now - 3 * HOUR_IN_SECONDS, 'paid' => $now - 3 * HOUR_IN_SECONDS + 60 ) );
	$saved  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $op_table, $gap_id ), ARRAY_A );
	if ( $hpos ) {
		blzmcp_it_assert( is_array( $saved ), 'HPOS wrote an operational row for the new order' );
	}
	if ( is_array( $saved ) ) {
		$wpdb->delete( $op_table, array( 'order_id' => $gap_id ) );
		wp_cache_flush();
		$seen = array();
		$spy  = static function ( $q ) use ( &$seen ) {
			$seen[] = (string) $q;
			return $q;
		};
		add_filter( 'query', $spy );
		$gap = PaymentOrders::read( $now, 24, $tz );
		remove_filter( 'query', $spy );
		$wpdb->insert( $op_table, $saved );
		wp_cache_flush();
		$shadow_reads = array_filter( $seen, static fn( $q ) => str_contains( $q, '`' . $wpdb->posts . '`' ) || str_contains( $q, '`' . $wpdb->postmeta . '`' ) );
		if ( $hpos ) {
			blzmcp_it_equals( array( false, 'unavailable', 'operational_data_missing' ), array( $gap['ok'], $gap['coverage'], $gap['reason'] ), 'HPOS order without its operational row: unavailable, not complete with the order omitted' );
			blzmcp_it_assert( null === $gap['store'] && array() === $gap['methods'], 'no partial counts from a store missing required data' );
			blzmcp_it_equals( 0, count( $shadow_reads ), 'missing HPOS data is never recovered from posts/postmeta' );
		} else {
			blzmcp_it_assert( true === $gap['ok'] && 'legacy' === $gap['authority'], 'legacy authority ignores a missing row in the HPOS shadow' );
		}
		blzmcp_it_assert( true === PaymentOrders::read( $now, 24, $tz )['ok'], 'restored operational row reads again' );
	} else {
		echo "NOTE  legacy with sync off: no HPOS shadow row to remove\n";
	}
}

if ( ! class_exists( 'Blzmcp_Test_Custom_Order_Store' ) ) {
	eval( 'class Blzmcp_Test_Custom_Order_Store extends WC_Order_Data_Store_CPT {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only custom store.
}
$custom = static fn() => 'Blzmcp_Test_Custom_Order_Store';
add_filter( 'woocommerce_order_data_store', $custom, 999 );
$u = PaymentOrders::read( $now, 24, $tz );
remove_filter( 'woocommerce_order_data_store', $custom, 999 );
blzmcp_it_equals( 'unsupported_data_store', $u['reason'], 'unsupported custom data store is unavailable, no fallback' );

// ---------------------------------------------------------------- hold stock.
$hs_min = get_option( 'woocommerce_hold_stock_minutes', null );
$hs_man = get_option( 'woocommerce_manage_stock', null );
blzmcp_it_cleanup(
	static function () use ( $hs_min, $hs_man ): void {
		null === $hs_min ? delete_option( 'woocommerce_hold_stock_minutes' ) : update_option( 'woocommerce_hold_stock_minutes', $hs_min );
		null === $hs_man ? delete_option( 'woocommerce_manage_stock' ) : update_option( 'woocommerce_manage_stock', $hs_man );
	}
);
update_option( 'woocommerce_hold_stock_minutes', '45' );
update_option( 'woocommerce_manage_stock', 'yes' );
blzmcp_it_equals( array( 'hold_stock_minutes' => 45, 'hold_stock_configured' => true ), PaymentOrders::hold_stock(), 'hold stock read from WooCommerce options' );
update_option( 'woocommerce_hold_stock_minutes', '0' );
blzmcp_it_equals( array( 'hold_stock_minutes' => null, 'hold_stock_configured' => false ), PaymentOrders::hold_stock(), 'zero hold stock is not configured' );

blzmcp_it_finish();
