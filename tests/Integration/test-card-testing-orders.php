<?php
/**
 * Card-testing order reader integration test (FEATURE-PLAN 1.3 item 2, contracts C3/C4):
 * PaymentOrders::read_card_testing() against an independent CRUD oracle in the authoritative
 * store, both legacy metadata access paths, conflicting shadow rows, every bound at its limit and
 * one past it, failure paths, signal support, privacy, and payment health unchanged.
 *
 * Authority must be set in an earlier, separate process; this process only asserts what it finds.
 *   Switch:  wp eval-file .../tests/Integration/test-payment-orders.php with BLZMCP_PF_SET=hpos BLZMCP_PF_SYNC=no
 *   Test:    wp eval-file .../tests/Integration/test-card-testing-orders.php with BLZMCP_EXPECT_AUTHORITY=hpos BLZMCP_EXPECT_SYNC=no
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, Squiz.PHP.Eval.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

require_once __DIR__ . '/card-testing-fixtures.php';

use Blaze\McpToolkit\Abilities\PaymentHealth;
use Blaze\McpToolkit\Diagnostics\CardTestingOrders;
use Blaze\McpToolkit\Diagnostics\CardTestingRules;
use Blaze\McpToolkit\Diagnostics\PaymentOrders;

global $wpdb;

$expect_auth = (string) getenv( 'BLZMCP_EXPECT_AUTHORITY' );
$expect_sync = 'yes' === getenv( 'BLZMCP_EXPECT_SYNC' ) ? 'yes' : 'no';
blzmcp_ct_quiet();

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

$fingerprint = static fn(): string => md5( implode( ',', blzmcp_ct_all_order_ids() ) );
// This process's own view of a card read: everything the reader says about the fixture methods
// (scopes, invalid-only rows, identities), with each bucket's network map reduced to its sorted
// counts. Other suites may write orders to the same store at the same time, so determinism, path
// and shadow comparisons use this view; store-wide totals are compared separately, bracketed by an
// order-ID fingerprint.
$fixture_view = static function ( array $r, array $methods ): string {
	$keep   = array_flip( $methods );
	$scopes = array();
	foreach ( (array) $r['scopes'] as $s ) {
		if ( isset( $keep[ $s['method_id'] ] ) ) {
			$nets = array();
			foreach ( $s['networks'] as $b => $map ) {
				$vals = array_values( $map );
				sort( $vals );
				$nets[ $b ] = $vals;
			}
			$s['networks'] = $nets;
			$scopes[]      = $s;
		}
	}
	$invalid = array_values( array_filter( (array) $r['invalid_only'], static fn( array $x ): bool => isset( $keep[ $x['method_id'] ] ) ) );
	$ids     = array_values( array_intersect( (array) $r['method_ids'], $methods ) );
	return serialize( array( $r['ok'], $r['authority'], $r['reason'], $r['signals'], $scopes, $invalid, $ids ) );
};

// ---------------------------------------------------------------- source support here.
blzmcp_it_assert( defined( 'WC_VERSION' ) && CardTestingOrders::version_supported( WC_VERSION ), 'this WooCommerce version is inside the source-verified range (' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'none' ) . ')' );
blzmcp_ct_option( 'woocommerce_feature_order_attribution_enabled', null );
$sig = CardTestingOrders::signal_support(
	array(
		'ip'   => true,
		'meta' => true,
	)
);
blzmcp_it_equals( array( true, true ), array( $sig['ip']['supported'], $sig['attribution']['supported'] ), 'absent attribution option: the source default (on) applies inside the verified range' );

// ---------------------------------------------------------------- payment health unchanged by card-only data.
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admins[0]->ID );
$clear_cache = static function () use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%', $wpdb->esc_like( '_transient_timeout_blzmcp_c_' ) . '%' ) );
	wp_cache_flush();
};
blzmcp_it_cleanup( $clear_cache );
$clock_free = static function ( $v ) use ( &$clock_free ) {
	if ( ! is_array( $v ) ) {
		return $v;
	}
	foreach ( array_keys( $v ) as $k ) {
		if ( in_array( $k, array( 'checked_at', 'hours_since_last_taken', 'expected_in_gap', 'mature_before', 'baseline_start', 'baseline_end' ), true ) || str_starts_with( (string) $k, 'window_' ) ) {
			unset( $v[ $k ] );
		} else {
			$v[ $k ] = $clock_free( $v[ $k ] );
		}
	}
	return $v;
};
// Seeded 10 minutes before $now: the payment fixtures include orders exactly on the history and
// window edges, and the ability captures its own clock, so two calls seconds apart would otherwise
// see an edge order move out of its history between them.
$pf_ids    = blzmcp_pf_seed_orders( $now - 600 );
$ph        = wp_get_ability( PaymentHealth::NAME );
$ph_view   = static function () use ( $ph, $clear_cache, $clock_free ) {
	$clear_cache();
	return $clock_free( $ph->execute( array( 'since_hours' => 24 ) ) );
};
$read_view = static function () use ( $now, $tz ): array {
	wp_cache_flush();
	return array(
		24  => PaymentOrders::read( $now, 24, $tz ),
		168 => PaymentOrders::read( $now, 168, $tz ),
	);
};
$fp_before   = $fingerprint();
$ph_before   = $ph_view();
$read_before = $read_view();
// Card-only data: saved IPs and attribution source types on the payment fixtures, then card reads.
foreach ( $pf_ids as $pf_id ) {
	$o = wc_get_order( $pf_id );
	if ( $o instanceof WC_Order ) {
		$o->set_customer_ip_address( '203.0.113.201' );
		$o->update_meta_data( BLZMCP_CT_ATTR_KEY, 'utm' );
		$o->save();
	}
}
PaymentOrders::read_card_testing( $now, 48, $tz );
PaymentOrders::read_card_testing( $now, 168, $tz );
$read_after = $read_view();
$ph_after   = $ph_view();
$fp_after   = $fingerprint();
blzmcp_it_assert( $fp_before === $fp_after, 'no other process wrote orders during the payment-health invariance check (a FAIL here is concurrency, not a regression)' );
foreach ( array( 24, 168 ) as $hours ) {
	blzmcp_it_assert( serialize( $read_before[ $hours ] ) === serialize( $read_after[ $hours ] ), "payment-health reader output identical after card-only data and card reads ({$hours}h)" );
}
$first_diff = static function ( $a, $b, string $path = '' ) use ( &$first_diff ): ?string {
	if ( is_array( $a ) && is_array( $b ) ) {
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
			$d = $first_diff( $a[ $k ] ?? null, $b[ $k ] ?? null, $path . '/' . $k );
			if ( null !== $d ) {
				return $d;
			}
		}
		return null;
	}
	return $a === $b ? null : $path . ': ' . substr( var_export( $a, true ), 0, 160 ) . ' vs ' . substr( var_export( $b, true ), 0, 160 );
};
$ph_diff = $first_diff( $ph_before, $ph_after );
if ( null !== $ph_diff ) {
	echo 'NOTE  first payment-health difference ' . $ph_diff . "\n";
}
blzmcp_it_assert( is_array( $ph_before ) && null === $ph_diff, 'payment-health ability output identical apart from observation time' );
blzmcp_it_equals( 2, PaymentHealth::SCHEMA_VERSION, 'payment health stays schema 2' );

// ---------------------------------------------------------------- seed and compare with CRUD.
$seed   = blzmcp_ct_seed( $now, $hpos );
$ids    = $seed['ids'];
$meth   = $seed['methods'];
$mine   = blzmcp_ct_ids();
$r      = PaymentOrders::read_card_testing( $now, 48, $tz );
$exp    = blzmcp_ct_expected( $now, 48, $mine );
$by_key = array();
foreach ( $r['scopes'] as $s ) {
	$by_key[ $s['method_id'] . '|' . $s['origin_scope'] ] = $s;
}
blzmcp_it_equals( array( true, $auth['authority'], null, true ), array( $r['ok'], $r['authority'], $r['reason'], $r['scan_complete'] ), 'card reader succeeded on the authoritative store with a complete scan' );
foreach ( $exp['scopes'] as $key => $want ) {
	blzmcp_it_assert( isset( $by_key[ $key ] ), "scope {$key} is in the reader output" );
	if ( isset( $by_key[ $key ] ) ) {
		blzmcp_ct_compare( $by_key[ $key ], $want, "scope {$key}" );
	}
}
$fixture_methods = array_values( $meth );
foreach ( $by_key as $key => $s ) {
	if ( in_array( $s['method_id'], $fixture_methods, true ) ) {
		blzmcp_it_assert( isset( $exp['scopes'][ $key ] ), "reader scope {$key} exists in the CRUD oracle (positive evidence only)" );
	}
}
if ( $hpos ) {
	$div = array_keys( $GLOBALS['blzmcp_ct_crud_divergence'] ?? array() );
	echo 'NOTE  HPOS duplicate attribution rows: the reader takes the lowest meta ID (C4); CRUD get_meta() returned a different duplicate for ' . count( $div ) . ' fixture order(s) (' . ( in_array( $ids['last_sec'], $div, true ) ? 'including' : 'not including' ) . " the duplicate-row fixture)\n";
}
$card_v = $by_key[ $meth['card'] . '|verified_checkout' ] ?? array();
blzmcp_it_equals( $exp['invalid'][ $meth['card'] . '|verified_checkout' ] ?? null, $card_v['invalid_rows'] ?? null, 'created-at-now and future-created orders are invalid rows of their scope' );
blzmcp_it_equals( 2, $card_v['invalid_rows'] ?? null, 'exactly the 2 invalid-dated card orders' );
blzmcp_it_assert( false === ( $card_v['counts_complete'] ?? true ), 'an invalid row makes its scope partial' );
$wallet = $by_key[ $meth['wallet'] . '|verified_checkout' ] ?? array();
blzmcp_it_equals( array( 1, 1 ), array( $wallet['invalid_rows'] ?? null, $wallet['anomalies'] ?? null ), 'wallet: a future paid date is invalid; paid evidence on a draft is an anomaly' );
blzmcp_it_assert( $r['draft_anomalies'] >= 1 && 'partial' === $r['coverage'], 'draft anomaly and invalid rows make the read partial, never complete' );
blzmcp_it_assert( isset( $by_key[ $meth['card'] . '|missing_origin' ] ), 'missing origin is its own scope' );
// Wave 2 (REVIEW-pass4 item 2): one shared identity budget for valid and invalid-only methods.
blzmcp_it_assert( in_array( $meth['ghost'], $r['method_ids'], true ) && in_array( $meth['ghost'], array_column( $r['invalid_only'], 'method_id' ), true ), 'an invalid-only method is an identity and an invalid-only row' );
blzmcp_it_assert( ! isset( $by_key[ $meth['ghost'] . '|verified_checkout' ] ), 'an invalid-only method never forms a scope (positive evidence only)' );
blzmcp_it_equals( count( $r['method_ids'] ), $r['usage']['methods'], 'the identity budget counts every admitted identity, valid and invalid-only' );
// Ruling Q3: the installed version as safe text and whether it is a supported stable release.
blzmcp_it_equals( array( WC_VERSION, CardTestingOrders::version_supported( WC_VERSION ) ), array( $r['wc_version'], $r['wc_supported'] ), 'reader carries the installed WooCommerce version and its support' );
$rule_out = CardTestingRules::evaluate( $r, array(), array(), $now, 48, $tz );
$version  = array_values( array_filter( $rule_out['limitations'], static fn( array $l ): bool => 'order_signal_versions' === $l['code'] ) );
blzmcp_it_assert( 1 === count( $version ) && $version[0]['what'] === CardTestingRules::reason_text( 'order_signal_versions' ) . ' ' . CardTestingRules::installed_version_text( WC_VERSION ) && str_contains( $version[0]['what'], 'WooCommerce ' . WC_VERSION . ' is installed' ), 'the source pins, range and installed version are disclosed in the limitations' );
blzmcp_it_assert( ! isset( $by_key['|verified_checkout'] ) && ! isset( $by_key['|missing_origin'] ), 'an empty method ID never forms a scope' );
blzmcp_it_assert( isset( $by_key[ $meth['malformed'] . '|verified_checkout' ] ) && str_starts_with( $meth['malformed'], 'unrecognised-' ), 'a malformed method ID becomes one opaque reference' );
blzmcp_it_assert( isset( $by_key[ $meth['numeric'] . '|verified_checkout' ] ) && in_array( $meth['numeric'], $r['method_ids'], true ), 'a numeric method ID stays a string' );
blzmcp_it_assert( ! in_array( $ids['old_failed'], array_column( $card_v['example_candidates'] ?? array(), 'id' ), true ), 'an order created before the history and failed now is not observed (disclosed blind spot)' );
blzmcp_it_equals( 2, $card_v['buckets'][0]['f'] ?? null, 'the last second and now-900 share the last bucket; now itself is invalid' );
blzmcp_it_equals( 1, $card_v['buckets'][1]['f'] ?? null, 'now-901 is in the bucket before' );

// Totals over every order (store-wide), bracketed against concurrent writes.
$fp1  = $fingerprint();
$tr   = PaymentOrders::read_card_testing( $now, 48, $tz );
$texp = blzmcp_ct_expected( $now, 48, blzmcp_ct_all_order_ids() );
$fp2  = $fingerprint();
blzmcp_it_assert( $fp1 === $fp2, 'no concurrent order writes during the totals comparison' );
blzmcp_it_equals( $texp['totals'], $tr['totals'], 'store-wide window totals (classes, origins, unassigned, invalid rows) match CRUD' );

// ---------------------------------------------------------------- privacy and selected fields.
$json = (string) wp_json_encode( $r );
foreach ( BLZMCP_CT_CANARIES as $canary ) {
	blzmcp_it_assert( ! str_contains( $json, $canary ), "no '{$canary}' in the reader output" );
}
blzmcp_it_assert( ! str_contains( $json, BLZMCP_PF_ORIGIN_MARK ), 'no raw origin or attribution text in the reader output' );
$ints = true;
foreach ( $r['scopes'] as $s ) {
	foreach ( $s['networks'] as $map ) {
		foreach ( $map as $nid => $n ) {
			$ints = $ints && is_int( $nid ) && is_int( $n );
		}
	}
}
blzmcp_it_assert( $ints, 'network maps hold per-call sequence numbers only' );
$seen = array();
$spy  = static function ( $q ) use ( &$seen ) {
	$seen[] = (string) $q;
	return $q;
};
add_filter( 'query', $spy );
wp_cache_flush();
PaymentOrders::read_card_testing( $now, 48, $tz );
remove_filter( 'query', $spy );
$card_sql = implode( "\n", $seen );
foreach ( array( 'billing', 'transaction_id', 'user_agent', 'customer_note', 'order_key', 'wc_order_addresses', '_billing_', '_customer_user_agent', 'date_completed' ) as $field ) {
	blzmcp_it_assert( ! str_contains( $card_sql, $field ), "card mode never selects {$field}" );
}
if ( $hpos ) {
	blzmcp_it_assert( ! str_contains( $card_sql, '`' . $wpdb->posts . '`' ) && ! str_contains( $card_sql, '`' . $wpdb->postmeta . '`' ), 'HPOS card mode never reads posts or postmeta' );
	blzmcp_it_assert( str_contains( $card_sql, 'LEFT( o.ip_address, 101 )' ) && str_contains( $card_sql, "meta_key = '_wc_order_attribution_source_type'" ), 'HPOS card mode reads the bounded IP column and the default attribution key' );
} else {
	blzmcp_it_assert( ! str_contains( $card_sql, 'wc_orders' ) && ! str_contains( $card_sql, 'wc_order_operational' ), 'legacy card mode never reads HPOS tables' );
	blzmcp_it_assert( str_contains( $card_sql, "'_customer_ip_address'" ) && str_contains( $card_sql, "'_wc_order_attribution_source_type'" ) && str_contains( $card_sql, 'LEFT( meta_value, 101 )' ), 'legacy card mode reads the bounded IP and attribution keys' );
}
$seen = array();
add_filter( 'query', $spy );
wp_cache_flush();
PaymentOrders::read( $now, 24, $tz );
remove_filter( 'query', $spy );
$pay_sql = implode( "\n", $seen );
foreach ( array( 'ip_address', '_customer_ip_address', '_wc_order_attribution', 'wc_orders_meta' ) as $field ) {
	blzmcp_it_assert( ! str_contains( $pay_sql, $field ), "payment-health mode never selects {$field}" );
}

// ---------------------------------------------------------------- conflicting shadow rows.
$base = $fixture_view( PaymentOrders::read_card_testing( $now, 48, $tz ), $fixture_methods );
$sid  = $ids['shape_0'];
if ( $hpos ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d AND post_type = %s', $wpdb->posts, $sid, 'shop_order' ) ) ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-processing' ), array( 'ID' => $sid ) );
		update_post_meta( $sid, '_customer_ip_address', '198.51.100.252' );
		update_post_meta( $sid, BLZMCP_CT_ATTR_KEY, 'organic' );
		update_post_meta( $sid, '_payment_method', 'shadow_method' );
		echo "NOTE  conflicting posts shadow written for {$sid}\n";
	}
} elseif ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) ) ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, ip_address = %s, payment_method = %s WHERE id = %d', $wpdb->prefix . 'wc_orders', 'wc-processing', '198.51.100.252', 'shadow_method', $sid ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_value = %s WHERE order_id = %d AND meta_key = %s', $wpdb->prefix . 'wc_orders_meta', 'organic', $sid, BLZMCP_CT_ATTR_KEY ) );
	echo "NOTE  conflicting HPOS shadow written for {$sid} (if present)\n";
}
wp_cache_flush();
blzmcp_it_assert( $fixture_view( PaymentOrders::read_card_testing( $now, 48, $tz ), $fixture_methods ) === $base, 'conflicting rows in the non-authoritative store change nothing' );
blzmcp_it_assert( ! in_array( 'shadow_method', PaymentOrders::read_card_testing( $now, 48, $tz )['method_ids'], true ), 'no shadow-store method' );

// ---------------------------------------------------------------- determinism, chunks and both legacy paths.
blzmcp_it_assert( $fixture_view( PaymentOrders::read_card_testing( $now, 48, $tz ), $fixture_methods ) === $base, 'identical inputs give identical output for this process\'s orders' );
$limits = new ReflectionProperty( PaymentOrders::class, 'limits_override' );
$limits->setAccessible( true );
$with = static function ( ?array $l, int $hours = 48, ?int $at = null ) use ( $limits, $now, $tz ): array {
	$limits->setValue( null, $l );
	try {
		wp_cache_flush();
		return PaymentOrders::read_card_testing( $at ?? $now, $hours, $tz );
	} finally {
		$limits->setValue( null, null );
	}
};
blzmcp_it_assert( $fixture_view( $with( array( 'chunk' => 2 ) ), $fixture_methods ) === $base, 'chunks of 2 with sentinel rows: identical output (keyset is exact)' );
if ( ! $hpos ) {
	$paths = new ReflectionProperty( PaymentOrders::class, 'legacy_paths' );
	$force = new ReflectionProperty( PaymentOrders::class, 'legacy_path_override' );
	$paths->setAccessible( true );
	$force->setAccessible( true );
	foreach ( array( 'span', 'fallback' ) as $path ) {
		$paths->setValue(
			null,
			array(
				'span'     => 0,
				'fallback' => 0,
			)
		);
		$force->setValue( null, $path );
		try {
			wp_cache_flush();
			$forced = PaymentOrders::read_card_testing( $now, 48, $tz );
		} finally {
			$force->setValue( null, null );
		}
		$ran = $paths->getValue();
		blzmcp_it_assert( $ran[ $path ] >= 1 && 0 === $ran[ 'span' === $path ? 'fallback' : 'span' ], "legacy {$path} metadata path ran alone" );
		blzmcp_it_assert( $fixture_view( $forced, $fixture_methods ) === $base, "legacy {$path} metadata path: identical output (first row per key, duplicate IP and method rows)" );
	}
}

// ---------------------------------------------------------------- bounds at the limit and one past it.
$full  = $with( null );
$rows  = (int) $full['rows_read'];
$usage = $full['usage'];
blzmcp_it_assert( $rows > 10 && $usage['methods'] >= 3 && $usage['networks'] >= 3 && $usage['bucket_cells'] >= 3 && $usage['network_cells'] >= 3, 'bounds baseline: the scenario exercises every map' );
$at = $with( array( 'rows' => $rows ) );
blzmcp_it_equals( array( true, null, $rows ), array( $at['scan_complete'], $at['reason'], $at['rows_read'] ), 'rows bound at the exact row count: complete' );
$past = $with( array( 'rows' => $rows - 1 ) );
blzmcp_it_equals( array( true, false, 'row_cap', 'partial', $rows - 1 ), array( $past['ok'], $past['scan_complete'], $past['reason'], $past['coverage'], $past['rows_read'] ), 'rows bound one under: row_cap, partial, exactly the bound folded' );
$at = $with( array( 'candidates' => $rows ) );
blzmcp_it_equals( array( true, null ), array( $at['scan_complete'], $at['reason'] ), 'candidate bound at the exact count: complete' );
$past = $with( array( 'candidates' => $rows - 1 ) );
blzmcp_it_equals( array( false, 'order_scan_budget', 'partial' ), array( $past['scan_complete'], $past['reason'], $past['coverage'] ), 'candidate bound one under: order_scan_budget, partial' );
blzmcp_it_assert( $past['work']['candidates'] <= $rows - 1, 'no more candidates examined than the bound' );
foreach ( array( 'methods' => 'methods_truncated', 'networks' => 'network_ids', 'bucket_cells' => 'bucket_cells', 'network_cells' => 'network_cells' ) as $bound => $flag ) {
	$at   = $with( array( $bound => $usage[ $bound ] ) );
	$past = $with( array( $bound => $usage[ $bound ] - 1 ) );
	$get  = static fn( array $x ): bool => 'methods_truncated' === $flag ? $x['methods_truncated'] : $x['caps'][ $flag ];
	blzmcp_it_equals( array( false, true ), array( $get( $at ), $get( $past ) ), "{$bound} bound: not capped at the exact usage, capped one under" );
	blzmcp_it_equals( 'partial', $past['coverage'], "{$bound} bound one under: partial" );
	if ( 'methods' === $bound ) {
		blzmcp_it_assert( count( $past['method_ids'] ) <= $usage['methods'] - 1 && $past['usage']['methods'] <= $usage['methods'] - 1, 'methods bound one under: identities (valid and invalid-only) stay within the bound' );
		$capped_out = CardTestingRules::evaluate( $past, array(), array(), $now, 48, $tz );
		blzmcp_it_equals( array( null, null, true ), array( $capped_out['methods_total'], $capped_out['assessments_total'], $capped_out['methods_truncated'] ), 'methods bound one under: no exact method or assessment total' );
		blzmcp_it_assert( count( $capped_out['methods'] ) <= $usage['methods'] - 1, 'methods bound one under: public method facts within the bound' );
		// Whichever identity is omitted (valid or invalid-only), a tracked scope sees every one of its
		// rows, so it keeps the same counts and completeness as the unbounded read.
		$scope_of = static function ( array $x ) use ( $fixture_methods ): array {
			$out = array();
			foreach ( $x['scopes'] as $s ) {
				if ( in_array( $s['method_id'], $fixture_methods, true ) ) {
					$out[ $s['method_id'] . '|' . $s['origin_scope'] ] = array( $s['counts'], $s['invalid_rows'], $s['counts_complete'] );
				}
			}
			return $out;
		};
		$full_scopes = $scope_of( $full );
		$kept_same   = true;
		foreach ( $scope_of( $past ) as $key => $view ) {
			$kept_same = $kept_same && isset( $full_scopes[ $key ] ) && $full_scopes[ $key ] === $view;
		}
		blzmcp_it_assert( $kept_same, 'methods bound one under: every tracked scope keeps its exact counts and completeness (an omitted identity never makes it partial)' );
	}
	blzmcp_it_equals( $full['totals'], $past['totals'], "{$bound} bound one under: safe store-wide totals still exact" );
}
blzmcp_it_assert( null === $with( array( 'networks' => $usage['networks'] - 1 ) )['networks_seen'], 'network identity cap: no exact distinct-network count' );
$meta = (int) $full['work']['meta_rows'];
blzmcp_it_assert( $meta > 0, 'the scan read metadata rows' );
$at = $with( array( 'meta_call' => $meta ) );
blzmcp_it_equals( array( true, null ), array( $at['scan_complete'], $at['reason'] ), 'metadata call bound at the exact row count: complete' );
$past = $with( array( 'meta_call' => $meta - 1 ) );
blzmcp_it_equals( array( false, 'order_scan_budget' ), array( $past['scan_complete'], $past['reason'] ), 'metadata call bound one under: order_scan_budget' );
blzmcp_it_assert( $past['work']['meta_rows'] <= $meta - 1, 'no more metadata rows folded than the bound' );
$mb = $with( array( 'meta_batch' => 1 ) );
blzmcp_it_equals( array( false, 'order_scan_budget', 0, array() ), array( $mb['scan_complete'], $mb['reason'], $mb['rows_read'], $mb['scopes'] ), 'metadata batch bound: the over-limit batch is not folded at all' );
// An incomplete scan never yields an all-clear or a share from an incomplete denominator.
$facts = array();
foreach ( $full['method_ids'] as $mid ) {
	$facts[ $mid ] = array(
		'kind'      => 'instant',
		'test_mode' => false,
	);
}
foreach ( array( 'rows' => $rows - 1, 'candidates' => $rows - 1, 'meta_call' => $meta - 1, 'bucket_cells' => $usage['bucket_cells'] - 1 ) as $bound => $value ) {
	$capped = $with( array( $bound => $value ) );
	$out    = CardTestingRules::evaluate( $capped, $facts, array(), $now, 48, $tz );
	blzmcp_it_assert( 'no_sign' !== $out['verdict'] && 'complete' !== $out['coverage'], "{$bound} capped: no all-clear, coverage not complete" );
	$shares = true;
	foreach ( $out['assessments'] as $a ) {
		if ( 'complete' !== $a['counts']['coverage'] && null !== $a['latest_hour']['failed_share']['value'] ) {
			$shares = false;
		}
	}
	blzmcp_it_assert( $shares, "{$bound} capped: no failed share from an incomplete denominator" );
}

// ---------------------------------------------------------------- signal support switches.
$counts_of = static fn( array $x ): string => serialize( array_map( static fn( array $s ): array => array( $s['method_id'], $s['origin_scope'], $s['counts'], $s['baseline']['attempts'] ), $x['scopes'] ) );
blzmcp_ct_option( 'woocommerce_feature_order_attribution_enabled', 'no' );
$off = $with( null );
blzmcp_it_equals( array( false, 'attribution_feature_disabled', true ), array( $off['signals']['attribution']['supported'], $off['signals']['attribution']['reason'], $off['signals']['ip']['supported'] ), 'attribution feature off: the signal is unassessed, IP unaffected' );
blzmcp_it_assert( $counts_of( $off ) === $counts_of( $full ), 'attribution off: order counts unchanged' );
blzmcp_ct_option( 'woocommerce_feature_order_attribution_enabled', 'yes' );
blzmcp_it_assert( true === $with( null )['signals']['attribution']['supported'], 'attribution feature yes: supported' );
$prefix = static fn() => 'custom_prefix_';
add_filter( 'wc_order_attribution_tracking_field_prefix', $prefix );
$ovr = $with( null );
remove_filter( 'wc_order_attribution_tracking_field_prefix', $prefix );
blzmcp_it_equals( array( false, 'attribution_override_present' ), array( $ovr['signals']['attribution']['supported'], $ovr['signals']['attribution']['reason'] ), 'a registered attribution prefix filter leaves the signal unassessed' );
if ( $hpos ) {
	// The prepared LIKE pattern carries escaped underscores, so match on the table's tail only.
	$no_meta = static fn( $q ) => str_starts_with( $q, 'SHOW TABLES LIKE' ) && str_contains( $q, '_meta' ) ? "SHOW TABLES LIKE 'blzmcp_no_such_table'" : $q;
	add_filter( 'query', $no_meta );
	$nm = $with( null );
	remove_filter( 'query', $no_meta );
	blzmcp_it_equals( array( false, 'attribution_storage_unavailable' ), array( $nm['signals']['attribution']['supported'], $nm['signals']['attribution']['reason'] ), 'HPOS meta table missing: attribution unavailable' );
	blzmcp_it_assert( $counts_of( $nm ) === $counts_of( $full ), 'HPOS meta table missing: order counts unchanged' );
	$no_ip = static fn( $q ) => str_starts_with( $q, 'SHOW COLUMNS FROM' ) ? (string) preg_replace( "/LIKE '.*'$/", "LIKE 'blzmcp_no_such_column'", $q ) : $q;
	add_filter( 'query', $no_ip );
	$ni = $with( null );
	remove_filter( 'query', $no_ip );
	blzmcp_it_equals( array( false, 'ip_storage_unavailable' ), array( $ni['signals']['ip']['supported'], $ni['signals']['ip']['reason'] ), 'HPOS IP column missing: IP signal unavailable' );
	blzmcp_it_assert( $counts_of( $ni ) === $counts_of( $full ), 'HPOS IP column missing: order counts unchanged' );
}

// ---------------------------------------------------------------- failure paths fail closed.
$fail_on = static function ( callable $match, ?array $l = null ) use ( $with, $wpdb ): array {
	$f = static fn( $q ) => $match( (string) $q ) ? 'SELECT broken_column FROM blzmcp_no_such_table' : $q;
	add_filter( 'query', $f );
	$wpdb->suppress_errors( true );
	try {
		return $with( $l );
	} finally {
		$wpdb->suppress_errors( false );
		remove_filter( 'query', $f );
	}
};
$first = $fail_on( static fn( string $q ): bool => str_contains( $q, 'ORDER BY o.id ASC' ) || str_contains( $q, 'ORDER BY p.ID ASC' ) );
blzmcp_it_equals( array( false, 'read_failed', 'unavailable', null, array() ), array( $first['ok'], $first['reason'], $first['coverage'], $first['totals'], $first['scopes'] ), 'read failure before the first chunk: unavailable, null totals, no scopes' );
blzmcp_it_assert( ! str_contains( (string) wp_json_encode( $first ), 'blzmcp_no_such_table' ), 'no SQL error text in the output' );
$n_reads = 0;
$late    = $fail_on(
	static function ( string $q ) use ( &$n_reads ): bool {
		if ( str_contains( $q, 'ORDER BY o.id ASC' ) || str_contains( $q, 'ORDER BY p.ID ASC' ) ) {
			++$n_reads;
			return $n_reads >= 2;
		}
		return false;
	},
	array( 'chunk' => 2 )
);
blzmcp_it_assert( $n_reads >= 2, 'failure injected after the first chunk' );
blzmcp_it_equals( array( false, 'read_failed', null, array() ), array( $late['ok'], $late['reason'], $late['totals'], $late['scopes'] ), 'read failure after a chunk: no partial counts presented' );
$meta_fail = $fail_on( static fn( string $q ): bool => str_contains( $q, '_wc_order_attribution_source_type' ) && ! str_starts_with( $q, 'SHOW' ) );
blzmcp_it_equals( array( false, 'read_failed' ), array( $meta_fail['ok'], $meta_fail['reason'] ), 'a failed metadata read fails the whole call closed' );
$missing = static fn( $q ) => str_starts_with( $q, 'SHOW TABLES LIKE' ) && ( str_contains( $q, 'operational' ) || str_contains( $q, 'postmeta' ) ) ? "SHOW TABLES LIKE 'blzmcp_no_such_table'" : $q;
add_filter( 'query', $missing );
$mt = PaymentOrders::read_card_testing( $now, 48, $tz );
remove_filter( 'query', $missing );
blzmcp_it_equals( array( false, 'tables_missing', null ), array( $mt['ok'], $mt['reason'], $mt['totals'] ), 'missing required table: unavailable' );
if ( $hpos ) {
	$op_table = $wpdb->prefix . 'wc_order_operational_data';
	$gap_id   = $ids['shape_1'];
	$saved    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $op_table, $gap_id ), ARRAY_A );
	if ( is_array( $saved ) ) {
		$restore = static function () use ( $wpdb, $op_table, $saved, $gap_id ): void {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE order_id = %d', $op_table, $gap_id ) ) ) {
				$wpdb->insert( $op_table, $saved );
			}
			wp_cache_flush();
		};
		blzmcp_it_cleanup( $restore );
		$seen = array();
		try {
			$wpdb->delete( $op_table, array( 'order_id' => $gap_id ) );
			wp_cache_flush();
			add_filter( 'query', $spy );
			$gap = PaymentOrders::read_card_testing( $now, 48, $tz );
		} finally {
			remove_filter( 'query', $spy );
			$restore();
		}
		blzmcp_it_equals( array( false, 'operational_data_missing', null, array() ), array( $gap['ok'], $gap['reason'], $gap['totals'], $gap['scopes'] ), 'HPOS order without its operational row: unavailable, never a missing origin' );
		blzmcp_it_assert( array() === array_filter( $seen, static fn( $q ) => str_contains( $q, '`' . $wpdb->posts . '`' ) || str_contains( $q, '`' . $wpdb->postmeta . '`' ) ), 'missing HPOS data is never recovered from posts or postmeta' );
	}
}
if ( ! class_exists( 'Blzmcp_Test_Custom_Order_Store' ) ) {
	eval( 'class Blzmcp_Test_Custom_Order_Store extends WC_Order_Data_Store_CPT {}' );
}
$custom = static fn() => 'Blzmcp_Test_Custom_Order_Store';
add_filter( 'woocommerce_order_data_store', $custom, 999 );
$u = PaymentOrders::read_card_testing( $now, 48, $tz );
remove_filter( 'woocommerce_order_data_store', $custom, 999 );
blzmcp_it_equals( array( false, 'unsupported_data_store', null ), array( $u['ok'], $u['reason'], $u['totals'] ), 'unsupported custom data store: unavailable, no fallback' );

// ---------------------------------------------------------------- empty store.
$empty = $with( null, 48, $now + 400 * DAY_IN_SECONDS );
blzmcp_it_equals( array( true, 'complete', true, array(), 0 ), array( $empty['ok'], $empty['coverage'], $empty['scan_complete'], $empty['scopes'], array_sum( $empty['totals'] ) ), 'a history with no orders: complete, zero totals, no scopes' );
$empty_out = CardTestingRules::evaluate( $empty, array(), array(), $now + 400 * DAY_IN_SECONDS, 48, $tz );
blzmcp_it_equals( 'unknown', $empty_out['verdict'], 'an empty store is unknown, never no sign' );

// ---------------------------------------------------------------- examples revalidated through CRUD.
$cands = array();
foreach ( $card_v['example_candidates'] ?? array() as $c ) {
	$cands[] = array(
		'id'           => $c['id'],
		'method_id'    => $meth['card'],
		'origin_scope' => 'verified_checkout',
	);
}
$ex = PaymentOrders::card_examples( $cands, $auth['authority'], $now, 48, $tz );
blzmcp_it_equals( array( 5, false ), array( count( $ex['examples'] ), $ex['changed'] ), '5 examples hydrated, none changed' );
$first = $ex['examples'][0] ?? array();
blzmcp_it_equals( array( 'number', 'created', 'status', 'method_id', 'total', 'currency', 'edit_url' ), array_keys( $first ), 'example has only the contract fields' );
$fo = wc_get_order( (int) ( $first['number'] ?? 0 ) );
blzmcp_it_equals( (string) $cands[0]['id'], $first['number'] ?? null, 'number is the internal ID as text, latest failed first' );
blzmcp_it_equals( array( 'failed', $meth['card'] ), array( $first['status'] ?? null, $first['method_id'] ?? null ), 'fixed status label and safe method ID' );
blzmcp_it_equals( wc_format_decimal( $fo->get_total(), wc_get_price_decimals() ), $first['total'] ?? null, 'total uses the store decimals' );
blzmcp_it_equals( \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $fo->get_id() ), $first['edit_url'] ?? null, 'edit URL matches the storage-correct helper' );
$ex_json = (string) wp_json_encode( $ex );
foreach ( BLZMCP_CT_CANARIES as $canary ) {
	blzmcp_it_assert( ! str_contains( $ex_json, $canary ), "no '{$canary}' in examples" );
}
$changed = wc_get_order( $cands[1]['id'] );
$changed->set_status( 'processing' );
$changed->save();
blzmcp_it_delete_order( $cands[2]['id'] );
$ex2 = PaymentOrders::card_examples( $cands, $auth['authority'], $now, 48, $tz );
blzmcp_it_equals( array( 3, true ), array( count( $ex2['examples'] ), $ex2['changed'] ), 'a changed and a deleted example are omitted and disclosed as changed' );
$wrong = PaymentOrders::card_examples( array( array( 'id' => $cands[0]['id'], 'method_id' => $meth['card'], 'origin_scope' => 'missing_origin' ) ), $auth['authority'], $now, 48, $tz );
blzmcp_it_equals( array( 0, true ), array( count( $wrong['examples'] ), $wrong['changed'] ), 'an example whose origin scope no longer matches is omitted' );

blzmcp_it_finish();
