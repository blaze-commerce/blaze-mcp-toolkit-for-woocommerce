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

// ---------------------------------------------------------------- 1.2.1 item 1: outside observations.
// Invariance: adding outside-checkout orders leaves every checkout output identical.
$checkout_view = static function ( int $hours ) use ( $now, $tz, $auth ): array {
	wp_cache_flush();
	$r = PaymentOrders::read( $now, $hours, $tz );
	$s = $r['methods']['stripe']['example_ids'] ?? array();
	return array(
		'ok'       => $r['ok'],
		'coverage' => $r['coverage'],
		'reason'   => $r['reason'],
		'store'    => $r['store'],
		'methods'  => $r['methods'],
		'profile'  => is_array( $r['store'] ) ? PaymentRules::fit_profile( $r['store']['series'], $now, $tz ) : null,
		'examples' => PaymentOrders::examples( $s, (string) $auth['authority'], $tz ),
	);
};
$inv_before = array(
	24  => $checkout_view( 24 ),
	168 => $checkout_view( 168 ),
);
$outside_ids = blzmcp_pf_seed_outside( $now );
foreach ( array( 24, 168 ) as $hours ) {
	$after = $checkout_view( $hours );
	foreach ( array( 'ok', 'coverage', 'reason', 'store', 'methods', 'profile', 'examples' ) as $part ) {
		blzmcp_it_assert( serialize( $inv_before[ $hours ][ $part ] ) === serialize( $after[ $part ] ), "outside fixtures leave checkout {$part} identical ({$hours}h)" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- exact structural comparison.
	}
}
blzmcp_it_assert( isset( $inv_before[24]['store']['series']['count'], $inv_before[24]['methods']['stripe']['accepted_7d'], $inv_before[24]['methods']['stripe']['failed'] ), 'invariance covered series, accepted_7d and failed counts' );

// Legacy duplicate metadata: first (lowest meta_id) row wins for the method and the origin.
if ( ! $hpos ) {
	$dupm = blzmcp_pf_order( array( 'method' => 'blzpf_dupm_first', 'via' => 'admin', 'status' => 'processing', 'created' => $now - 3 * DAY_IN_SECONDS, 'paid' => $now - 3 * DAY_IN_SECONDS ) );
	add_post_meta( $dupm, '_payment_method', 'blzpf_dupm_second' );
	$dupv_c = blzmcp_pf_order( array( 'method' => 'blzpf_dupv_checkout', 'via' => 'checkout', 'status' => 'processing', 'created' => $now - 3 * DAY_IN_SECONDS, 'paid' => $now - 3 * DAY_IN_SECONDS ) );
	add_post_meta( $dupv_c, '_created_via', 'admin' );
	$dupv_a = blzmcp_pf_order( array( 'method' => 'blzpf_dupv_admin', 'via' => 'admin', 'status' => 'processing', 'created' => $now - 3 * DAY_IN_SECONDS, 'paid' => $now - 3 * DAY_IN_SECONDS ) );
	add_post_meta( $dupv_a, '_created_via', 'checkout' );
	wp_cache_flush();
}

// Conflicting shadow rows in the non-authoritative store must be ignored.
$shadow_id = $ids['paid_recent'];
if ( $hpos ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d AND post_type = %s', $wpdb->posts, $shadow_id, 'shop_order' ) ) ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-pending' ), array( 'ID' => $shadow_id ) );
		update_post_meta( $shadow_id, '_payment_method', 'shadow_method' );
		update_post_meta( $shadow_id, '_created_via', 'admin' );
		echo "NOTE  conflicting posts shadow written for {$shadow_id}\n";
	}
} elseif ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) ) ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET payment_method = %s, status = %s WHERE id = %d', $wpdb->prefix . 'wc_orders', 'shadow_method', 'wc-pending', $shadow_id ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_via = %s WHERE order_id = %d', $wpdb->prefix . 'wc_order_operational_data', 'admin', $shadow_id ) );
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

// Outside observations against the independent CRUD oracle.
$oids = $got['outside']['ids'];
blzmcp_it_equals( $exp['outside'], $oids, 'outside observation IDs match the CRUD oracle (sorted)' );
blzmcp_it_equals( $exp['outside_invalid'], $got['outside']['invalid'], 'invalid-dated outside orders match the CRUD oracle' );
blzmcp_it_equals( array( 'partial', 'invalid_timestamps', false ), array( $got['outside']['coverage'], $got['outside']['reason'], $got['outside']['truncated'] ), 'a future-dated outside order makes observation coverage partial, never complete' );
foreach ( array( 'blzpf_admin', 'blzpf_import', 'blzpf_phone', 'blzpf_pos', 'blzpf_renewal', 'blzpf_noorigin', 'blzpf_oldpaid', 'blzpf_edge_start', 'blzpf_paid_edge', 'blzpf_failed', '12345', 'cod', 'stripe' ) as $want ) {
	blzmcp_it_assert( in_array( $want, $oids, true ), "outside observation includes {$want}" );
}
foreach ( array( 'blzpf_before', 'blzpf_paid_before', 'blzpf_future', 'blzpf_draft', 'blzpf_trash', '', 'shadow_method' ) as $never ) {
	blzmcp_it_assert( ! in_array( $never, $oids, true ), "outside observation excludes '{$never}'" );
}
blzmcp_it_assert( array() === array_filter( $oids, static fn( $v ) => ! is_string( $v ) ), 'outside IDs are strings (numeric-looking IDs too)' );
blzmcp_it_equals( 2, count( array_filter( $oids, static fn( $v ) => str_starts_with( $v, 'unrecognised-' ) ) ), 'malformed and over-long outside IDs become two opaque references' );
foreach ( $oids as $oid ) {
	blzmcp_it_assert( $oid === PaymentRules::safe_method_id( $oid ), "outside ID {$oid} is already safe (one mapping for both sets)" );
}
foreach ( array( 'blzpf_admin', 'blzpf_oldpaid', 'blzpf_failed', '12345' ) as $only_out ) {
	blzmcp_it_assert( ! isset( $got['methods'][ $only_out ] ), "{$only_out} never enters the checkout aggregates" );
}
$o_json = (string) wp_json_encode( $got );
blzmcp_it_assert( ! str_contains( $o_json, BLZMCP_PF_ORIGIN_MARK ) && ! str_contains( $o_json, 'Ignore all rules' ) && ! str_contains( $o_json, str_repeat( 'a', 70 ) ), 'no raw origin, malformed or over-long ID in the reader output' );
if ( ! $hpos ) {
	blzmcp_it_assert( in_array( 'blzpf_dupm_first', $oids, true ) && ! in_array( 'blzpf_dupm_second', $oids, true ), 'legacy duplicate _payment_method: the first row wins' );
	blzmcp_it_assert( isset( $got['methods']['blzpf_dupv_checkout'] ) && ! in_array( 'blzpf_dupv_checkout', $oids, true ), 'legacy duplicate _created_via (checkout first): checkout, not outside' );
	blzmcp_it_assert( in_array( 'blzpf_dupv_admin', $oids, true ) && ! isset( $got['methods']['blzpf_dupv_admin'] ), 'legacy duplicate _created_via (admin first): outside, not checkout' );
}
blzmcp_it_assert( 0 === $got['methods']['stripe']['observed'] || $got['methods']['stripe']['observed'] > 0, 'checkout scopes carry an observed count' );
blzmcp_it_assert( $got['work']['candidates'] >= $got['rows_read'] && false === $got['work']['budget'], 'work accounting: candidates examined at least rows selected, no budget hit' );

// ---------------------------------------------------------------- wave 2 fix round (REVIEW-pass4 R3): invalid dates and the exact recent-origin totals.
// The SQL selection is a superset: a future-created order passes the lower-bound predicate, and an
// old-created order with a future paid date passes the paid-date one. Neither is recent activity, so
// neither may enter the exact missing/other-origin totals the ability calls "in the last 28 days";
// both are tallied as invalid observations instead (observation coverage partial). Expected deltas
// are written out here, independent of the CRUD oracle, which is checked as well.
wp_cache_flush();
$r3_base = PaymentOrders::read( $now, 24, $tz );
$r3_ids  = array(
	'future_created'  => blzmcp_pf_order( array( 'method' => 'blzpf_r3_future_created', 'via' => '', 'status' => 'pending', 'created' => $now + DAY_IN_SECONDS ) ),
	'at_now'          => blzmcp_pf_order( array( 'method' => 'blzpf_r3_at_now', 'via' => '', 'status' => 'pending', 'created' => $now ) ),
	'old_future_paid' => blzmcp_pf_order( array( 'method' => 'blzpf_r3_old_future_paid', 'via' => '', 'status' => 'completed', 'created' => $now - 40 * DAY_IN_SECONDS, 'paid' => $now + DAY_IN_SECONDS ) ),
	'other_future'    => blzmcp_pf_order( array( 'method' => 'blzpf_r3_other_future', 'via' => 'admin', 'status' => 'pending', 'created' => $now + DAY_IN_SECONDS ) ),
	'start_edge'      => blzmcp_pf_order( array( 'method' => 'blzpf_r3_start_edge', 'via' => '', 'status' => 'pending', 'created' => $now - 28 * DAY_IN_SECONDS ) ),
	'end_edge'        => blzmcp_pf_order( array( 'method' => 'blzpf_r3_end_edge', 'via' => '', 'status' => 'pending', 'created' => $now - 1 ) ),
	'before_start'    => blzmcp_pf_order( array( 'method' => 'blzpf_r3_before_start', 'via' => '', 'status' => 'pending', 'created' => $now - 28 * DAY_IN_SECONDS - 1 ) ),
);
wp_cache_flush();
$r3     = PaymentOrders::read( $now, 24, $tz );
$r3_exp = blzmcp_pf_expected( $now, 24 );
blzmcp_it_equals( $r3_base['excluded']['unknown_origin'] + 2, $r3['excluded']['unknown_origin'], 'R3: only the valid start-boundary and end-boundary missing-origin controls join the exact missing-origin total' );
blzmcp_it_equals( $r3_base['excluded']['other_origin'], $r3['excluded']['other_origin'], 'R3: a future-created other-origin order is not added to the exact other-origin total' );
blzmcp_it_equals( $r3_base['outside']['invalid'] + 4, $r3['outside']['invalid'], 'R3: future-created, created-at-now, old-created/future-paid and future other-origin orders are tallied as invalid observations' );
foreach ( array( 'blzpf_r3_start_edge', 'blzpf_r3_end_edge' ) as $r3_want ) {
	blzmcp_it_assert( in_array( $r3_want, $r3['outside']['ids'], true ), "R3: valid boundary control {$r3_want} is an observation" );
}
foreach ( array( 'blzpf_r3_future_created', 'blzpf_r3_at_now', 'blzpf_r3_old_future_paid', 'blzpf_r3_other_future', 'blzpf_r3_before_start' ) as $r3_never ) {
	blzmcp_it_assert( ! in_array( $r3_never, $r3['outside']['ids'], true ), "R3: {$r3_never} is not an observation" );
}
blzmcp_it_equals( array( 'partial', 'invalid_timestamps' ), array( $r3['outside']['coverage'], $r3['outside']['reason'] ), 'R3: the invalid-date exclusions keep observation coverage partial' );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- exact structural comparison.
blzmcp_it_assert( serialize( array( $r3_base['store'], $r3_base['methods'], $r3_base['excluded']['invalid_timestamps'] ) ) === serialize( array( $r3['store'], $r3['methods'], $r3['excluded']['invalid_timestamps'] ) ), 'R3: checkout aggregates and the checkout invalid-timestamp count unchanged' );
foreach ( array( 'unknown_origin', 'other_origin' ) as $k ) {
	blzmcp_it_equals( $r3_exp['excluded'][ $k ], $r3['excluded'][ $k ], "R3: excluded {$k} matches the corrected CRUD oracle" );
}
blzmcp_it_equals( $r3_exp['outside_invalid'], $r3['outside']['invalid'], 'R3: invalid outside tally matches the corrected CRUD oracle' );
blzmcp_it_equals( $r3_exp['outside'], $r3['outside']['ids'], 'R3: outside IDs match the corrected CRUD oracle' );
foreach ( $r3_ids as $r3_id ) {
	blzmcp_it_delete_order( $r3_id );
}
wp_cache_flush();

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

// ---------------------------------------------------------------- legacy meta read paths (wave 5).
// legacy_chunk() reads the meta rows either by a primary-key span (dense batches) or by post_id
// (fallback), chosen by density guards. Both must return identical read() output for the same
// order set: this scenario already holds an older-created order paid inside the history, a
// site-local `_paid_date`-only order, duplicate `_date_paid` rows with differing meta_id and an
// empty payment method. The path counters and override are private test instrumentation.
if ( ! $hpos ) {
	$paths_prop = new ReflectionProperty( PaymentOrders::class, 'legacy_paths' );
	$force_prop = new ReflectionProperty( PaymentOrders::class, 'legacy_path_override' );
	$paths_prop->setAccessible( true );
	$force_prop->setAccessible( true );
	$read_with  = static function ( ?string $force, int $hours ) use ( $paths_prop, $force_prop, $now, $tz ): array {
		$paths_prop->setValue( null, array( 'span' => 0, 'fallback' => 0 ) );
		$force_prop->setValue( null, $force );
		try {
			wp_cache_flush();
			$r = PaymentOrders::read( $now, $hours, $tz );
		} finally {
			$force_prop->setValue( null, null );
		}
		// Work accounting counts every fetched meta row, so it differs by access path by design;
		// every result field is compared.
		unset( $r['work'] );
		return array( $r, $paths_prop->getValue() );
	};
	$same = static function ( string $label ) use ( $read_with ): array {
		$seen = array();
		foreach ( array( 24, 168 ) as $hours ) {
			list( $natural, $p ) = $read_with( null, $hours );
			list( $span, $ps )   = $read_with( 'span', $hours );
			list( $fallback, $pf ) = $read_with( 'fallback', $hours );
			blzmcp_it_assert( true === $natural['ok'], "{$label} ({$hours}h): reader succeeded" );
			blzmcp_it_assert( $ps['span'] >= 1 && 0 === $ps['fallback'], "{$label} ({$hours}h): forced span path ran" );
			blzmcp_it_assert( $pf['fallback'] >= 1 && 0 === $pf['span'], "{$label} ({$hours}h): forced post_id path ran" );
			blzmcp_it_assert( serialize( $natural ) === serialize( $span ), "{$label} ({$hours}h): span path output identical" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- exact structural comparison.
			blzmcp_it_assert( serialize( $natural ) === serialize( $fallback ), "{$label} ({$hours}h): post_id path output identical" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- exact structural comparison.
			$seen[ $hours ] = array( $natural, $p );
		}
		return $seen;
	};
	$filler_posts = array();
	blzmcp_it_cleanup(
		static function () use ( $wpdb, &$filler_posts, $ids ): void {
			foreach ( array_chunk( $filler_posts, 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
				$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
			}
			delete_post_meta( $ids['paid_recent'], '_blzmcp_pf_span_probe' );
		}
	);
	$add_filler = static function ( int $n ) use ( $wpdb, &$filler_posts ): void {
		$base = (int) $wpdb->get_var( "SELECT COALESCE( MAX( ID ), 0 ) FROM {$wpdb->posts}" );
		for ( $i = 0; $i < $n; $i += 500 ) {
			$vals = array();
			$end  = min( $n, $i + 500 );
			for ( $j = $i; $j < $end; $j++ ) {
				$id             = $base + 1 + $j;
				$filler_posts[] = $id;
				$vals[]         = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s, %s, %s)', $id, 'blzmcp_pf_filler', 'draft', 'blzmcp-pf-filler', '', '', '', '', '' );
			}
			$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_type, post_status, post_title, post_content, post_excerpt, to_ping, pinged, post_content_filtered) VALUES " . implode( ',', $vals ) );
		}
	};
	$candidates = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'shop_order' ) );

	// 1. This scenario as seeded: consecutive order IDs and meta rows, so the guards pick the span.
	$dense = $same( 'dense scenario' );
	blzmcp_it_assert( $dense[24][1]['span'] >= 1 && 0 === $dense[24][1]['fallback'], 'dense scenario: the guards chose the primary-key span read' );

	// 2. Meta rows far apart: thousands of postmeta rows of a non-order post land between the
	// orders' meta rows and one later, unread meta row of an order, so the span guard falls back.
	$add_filler( 1 );
	$filler = end( $filler_posts );
	$rows   = 64 * $candidates() + 200;
	for ( $i = 0; $i < $rows; $i += 500 ) {
		$vals = array();
		$end  = min( $rows, $i + 500 );
		for ( $j = $i; $j < $end; $j++ ) {
			$vals[] = $wpdb->prepare( '(%d, %s, %s)', $filler, '_blzmcp_pf_filler', (string) $j );
		}
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $vals ) );
	}
	add_post_meta( $ids['paid_recent'], '_blzmcp_pf_span_probe', '1' );
	$sparse_meta = $same( 'sparse meta_id span' );
	blzmcp_it_assert( 0 === $sparse_meta[24][1]['span'] && $sparse_meta[24][1]['fallback'] >= 1, 'sparse meta_id span: the guards chose the post_id read' );
	blzmcp_it_assert( serialize( $dense[24][0] ) === serialize( $sparse_meta[24][0] ) && serialize( $dense[168][0] ) === serialize( $sparse_meta[168][0] ), 'same orders read through either path give the same output' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- exact structural comparison.

	// 3. Orders interleaved among many non-order posts: a new order after more than 4 non-order
	// posts per order, so the post-ID guard falls back before any span read.
	$add_filler( 4 * $candidates() + 50 );
	$ids['after_filler'] = blzmcp_pf_order( array( 'method' => 'stripe', 'via' => 'checkout', 'status' => 'processing', 'created' => $now - 2 * DAY_IN_SECONDS, 'paid' => $now - 2 * DAY_IN_SECONDS + 60 ) );
	$sparse_ids = $same( 'interleaved post IDs' );
	blzmcp_it_assert( 0 === $sparse_ids[24][1]['span'] && $sparse_ids[24][1]['fallback'] >= 1, 'interleaved post IDs: the guards chose the post_id read' );
	blzmcp_it_equals( $dense[24][0]['store']['series']['count'] + 1, $sparse_ids[24][0]['store']['series']['count'], 'the order after the filler is read (one more taken event)' );
}

// ---------------------------------------------------------------- failure paths fail closed.
$break = static fn( $q ) => ( str_contains( $q, '_blzmcp_never' ) || str_contains( $q, 'ORDER BY o.id ASC' ) || str_contains( $q, 'ORDER BY p.ID ASC LIMIT' ) ) ? 'SELECT broken_column FROM blzmcp_no_such_table' : $q;
add_filter( 'query', $break );
$wpdb->suppress_errors( true );
$failed = PaymentOrders::read( $now, 24, $tz );
$wpdb->suppress_errors( false );
remove_filter( 'query', $break );
blzmcp_it_assert( false === $failed['ok'] && 'unavailable' === $failed['coverage'] && 'read_failed' === $failed['reason'], 'SQL failure is unavailable, not zero' );
blzmcp_it_assert( null === $failed['store'], 'no counts on failure' );
blzmcp_it_equals( array( 'unavailable', array() ), array( $failed['outside']['coverage'], $failed['outside']['ids'] ), 'read failure before the first chunk: outside observations unavailable, never an exact empty set' );
blzmcp_it_assert( ! str_contains( (string) wp_json_encode( $failed ), 'blzmcp_no_such_table' ) && ! str_contains( (string) wp_json_encode( $failed ), 'broken_column' ), 'no SQL error text in the output' );

// 1.2.1 C4 bounds, through the private test override (production limits are the constants).
$limits = new ReflectionProperty( PaymentOrders::class, 'limits_override' );
$limits->setAccessible( true );
$with_limits = static function ( ?array $l, int $hours = 24 ) use ( $limits, $now, $tz ): array {
	$limits->setValue( null, $l );
	try {
		wp_cache_flush();
		return PaymentOrders::read( $now, $hours, $tz );
	} finally {
		$limits->setValue( null, null );
	}
};
$checkout_of = static fn( array $r ): string => serialize( array( $r['ok'], $r['coverage'], $r['reason'], $r['store'], $r['methods'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- exact structural comparison.
$full        = $with_limits( null );
blzmcp_it_assert( true === $full['ok'] && count( $full['outside']['ids'] ) > 2, 'bounds baseline: full read with several outside IDs' );
$chunked = $with_limits( array( 'chunk' => 2 ) );
blzmcp_it_assert( $checkout_of( $full ) === $checkout_of( $chunked ) && $full['outside'] === $chunked['outside'], 'chunks of 2: identical checkout aggregates and outside observations (keyset is exact)' );

$cap = $with_limits( array( 'outside_ids' => 2 ) );
blzmcp_it_equals( array( 2, true, 'partial', 'outside_checkout_method_cap' ), array( count( $cap['outside']['ids'] ), $cap['outside']['truncated'], $cap['outside']['coverage'], $cap['outside']['reason'] ), 'identity cap: 2 IDs kept, truncated, partial, cap reason' );
blzmcp_it_assert( $cap['outside']['omitted'] >= 1, 'identity cap: further outside orders are still counted' );
blzmcp_it_assert( array() === array_diff( $cap['outside']['ids'], $full['outside']['ids'] ) && $cap['outside']['ids'] === array_values( array_unique( $cap['outside']['ids'] ) ), 'identity cap: kept IDs are real observations, sorted and unique' );
blzmcp_it_assert( $checkout_of( $full ) === $checkout_of( $cap ), 'identity cap alone leaves checkout aggregates and their completeness unchanged' );

$cb = $with_limits( array( 'chunk' => 2, 'candidates' => 3 ) );
blzmcp_it_equals( array( true, 'order_scan_budget', 'partial', false ), array( $cb['ok'], $cb['reason'], $cb['coverage'], $cb['store']['complete'] ), 'candidate bound: stops, partial checkout with order_scan_budget' );
blzmcp_it_equals( array( 'partial', 'order_scan_budget' ), array( $cb['outside']['coverage'], $cb['outside']['reason'] ), 'candidate bound: outside observations partial, never exhaustive' );
blzmcp_it_assert( $cb['work']['budget'] && $cb['work']['candidates'] <= 3 && $cb['rows_read'] <= 3, 'candidate bound: no more than 3 candidates examined' );
if ( ! $hpos ) {
	$mb = $with_limits( array( 'meta_batch' => 3 ) );
	blzmcp_it_equals( array( true, 'order_scan_budget', 0, 0, array() ), array( $mb['ok'], $mb['reason'], $mb['rows_read'], $mb['store']['series']['count'], $mb['outside']['ids'] ), 'legacy meta batch bound: the over-limit batch is not folded at all' );
	blzmcp_it_equals( array( 'partial', 'partial' ), array( $mb['coverage'], $mb['outside']['coverage'] ), 'legacy meta batch bound: checkout and outside coverage partial' );
	$mc = $with_limits( array( 'chunk' => 2, 'meta_call' => 12 ) );
	blzmcp_it_assert( 'order_scan_budget' === $mc['reason'] && $mc['work']['meta_rows'] <= 12 && 'partial' === $mc['outside']['coverage'], 'legacy meta call bound: stops within 12 metadata rows, partial' );
}

// Read failure after a successful chunk: the earlier chunk's observations are not presented.
$n_reads = 0;
$late    = static function ( $q ) use ( &$n_reads ) {
	if ( str_contains( $q, 'ORDER BY o.id ASC' ) || str_contains( $q, 'ORDER BY p.ID ASC LIMIT' ) ) {
		++$n_reads;
		if ( $n_reads >= 2 ) {
			return 'SELECT broken_column FROM blzmcp_no_such_table';
		}
	}
	return $q;
};
add_filter( 'query', $late );
$wpdb->suppress_errors( true );
$late_r = $with_limits( array( 'chunk' => 2 ) );
$wpdb->suppress_errors( false );
remove_filter( 'query', $late );
blzmcp_it_assert( $n_reads >= 2, 'failure injected after the first chunk' );
blzmcp_it_equals( array( false, 'read_failed', 'unavailable', array() ), array( $late_r['ok'], $late_r['reason'], $late_r['outside']['coverage'], $late_r['outside']['ids'] ), 'read failure after a chunk: unavailable, no observation set' );

$missing = static fn( $q ) => str_starts_with( $q, 'SHOW TABLES LIKE' ) && ( str_contains( $q, 'operational' ) || str_contains( $q, 'postmeta' ) ) ? "SHOW TABLES LIKE 'blzmcp_no_such_table'" : $q;
add_filter( 'query', $missing );
$m = PaymentOrders::read( $now, 24, $tz );
remove_filter( 'query', $missing );
blzmcp_it_equals( 'tables_missing', $m['reason'], 'missing required table is unavailable' );

// Pass 4 #3: a missing HPOS operational row fails closed; it is not an excluded unknown origin
// (that case, a present row with an empty origin, is the seeded `unknown_origin` order above).
$op_table = $wpdb->prefix . 'wc_order_operational_data';
// Wave 3 (REVIEW-pass4a #3, gap 1): the lead's counterexample, an order created 40 days ago
// (before the history start) and paid 2 days ago, whose operational row is missing. The main scan
// cannot select it (its paid date lives in the missing row), so an independent probe must.
$gap_cases = array(
	'created 3 hours ago'                  => array( 'created' => $now - 3 * HOUR_IN_SECONDS, 'paid' => $now - 3 * HOUR_IN_SECONDS + 60 ),
	'created 40 days ago, paid 2 days ago' => array( 'created' => $now - 40 * DAY_IN_SECONDS, 'paid' => $now - 2 * DAY_IN_SECONDS ),
);
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $op_table ) ) ) ) {
	foreach ( $gap_cases as $label => $when ) {
		$gap_id = blzmcp_pf_order( array( 'method' => 'stripe', 'via' => 'checkout', 'status' => 'processing', 'created' => $when['created'], 'paid' => $when['paid'] ) );
		$saved  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $op_table, $gap_id ), ARRAY_A );
		if ( $hpos ) {
			blzmcp_it_assert( is_array( $saved ), "{$label}: HPOS wrote an operational row for the new order" );
		}
		if ( ! is_array( $saved ) ) {
			echo "NOTE  {$label}: legacy with sync off, no HPOS shadow row to remove\n";
			continue;
		}
		$restore = static function () use ( $wpdb, $op_table, $saved, $gap_id ): void {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id = %d', $op_table, $gap_id ) ) ) {
				$wpdb->insert( $op_table, $saved );
			}
			wp_cache_flush();
		};
		blzmcp_it_cleanup( $restore ); // Also restores if this process dies before the inline restore.
		$seen = array();
		$spy  = static function ( $q ) use ( &$seen ) {
			$seen[] = (string) $q;
			return $q;
		};
		try {
			$wpdb->delete( $op_table, array( 'order_id' => $gap_id ) );
			wp_cache_flush();
			add_filter( 'query', $spy );
			$gap = PaymentOrders::read( $now, 24, $tz );
		} finally {
			remove_filter( 'query', $spy );
			$restore();
		}
		$shadow_reads = array_filter( $seen, static fn( $q ) => str_contains( $q, '`' . $wpdb->posts . '`' ) || str_contains( $q, '`' . $wpdb->postmeta . '`' ) );
		if ( $hpos ) {
			blzmcp_it_equals( array( false, 'unavailable', 'operational_data_missing' ), array( $gap['ok'], $gap['coverage'], $gap['reason'] ), "{$label}: HPOS order without its operational row is unavailable, not complete with the order omitted" );
			blzmcp_it_assert( null === $gap['store'] && array() === $gap['methods'], "{$label}: no partial counts from a store missing required data" );
			blzmcp_it_equals( array( 'unavailable', array() ), array( $gap['outside']['coverage'], $gap['outside']['ids'] ), "{$label}: a missing operational row is unavailable, never a missing-origin observation" );
			blzmcp_it_equals( 0, count( $shadow_reads ), "{$label}: missing HPOS data is never recovered from posts/postmeta" );
		} else {
			blzmcp_it_assert( true === $gap['ok'] && 'legacy' === $gap['authority'], "{$label}: legacy authority ignores a missing row in the HPOS shadow" );
		}
		blzmcp_it_assert( true === PaymentOrders::read( $now, 24, $tz )['ok'], "{$label}: restored operational row reads again" );
	}
	if ( $hpos ) {
		// A refund object without an operational row is not an eligible order and must not trip the probe.
		$r_parent = blzmcp_pf_order( array( 'method' => 'stripe', 'via' => 'checkout', 'status' => 'completed', 'created' => $now - 40 * DAY_IN_SECONDS, 'paid' => $now - 39 * DAY_IN_SECONDS ) );
		$refund   = wc_create_refund( array( 'order_id' => $r_parent, 'amount' => 0 ) );
		$r_id     = is_wp_error( $refund ) ? 0 : (int) $refund->get_id();
		blzmcp_it_cleanup( static fn() => $r_id && blzmcp_it_delete_order( $r_id ) );
		$r_saved = $r_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $op_table, $r_id ), ARRAY_A ) : null;
		if ( is_array( $r_saved ) ) {
			blzmcp_it_cleanup(
				static function () use ( $wpdb, $op_table, $r_saved, $r_id ): void {
					if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id = %d', $op_table, $r_id ) ) ) {
						$wpdb->insert( $op_table, $r_saved );
					}
				}
			);
			$wpdb->delete( $op_table, array( 'order_id' => $r_id ) );
			wp_cache_flush();
			$r_read = PaymentOrders::read( $now, 24, $tz );
			$wpdb->insert( $op_table, $r_saved );
			wp_cache_flush();
			blzmcp_it_assert( true === $r_read['ok'], 'a refund object missing its operational row does not trip the shop_order probe' );
		} else {
			echo "NOTE  refund has no operational row to remove; refund case skipped\n";
		}
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
