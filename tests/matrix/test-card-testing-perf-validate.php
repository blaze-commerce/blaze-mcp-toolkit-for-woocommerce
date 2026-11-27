<?php
/**
 * Self-test for the card-testing performance gate (1.3 FEATURE-PLAN item 9, C10). Plain PHP, no
 * WordPress, no Docker:
 *
 *   php tests/matrix/test-card-testing-perf-validate.php
 *
 * Covers the card workload arithmetic (payment-volume-cycle.php), the trial and bound-case
 * validators (card-testing-perf-trial.php) and the receipt validator (card-testing-perf-validate.php,
 * through its CLI too): a good receipt passes, and each rejection fails with its own reason. Prints
 * "<n> passed, <m> failed" and exits 0 only when nothing failed.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions
// Each rejection case is one line with a one-statement mutation closure, so the case list reads as a table.
// phpcs:disable Generic.Formatting.DisallowMultipleStatements.SameLine, Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace, Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace, WordPress.WhiteSpace.ControlStructureSpacing.BlankLineAfterEnd

require __DIR__ . '/payment-volume-cycle.php';
require __DIR__ . '/card-testing-perf-trial.php';
require __DIR__ . '/card-testing-perf-validate.php';
require __DIR__ . '/card-testing-perf-sample.php';

$pass = 0;
$fail = 0;
$is   = static function ( string $label, bool $cond ) use ( &$pass, &$fail ): void {
	if ( $cond ) {
		echo "PASS  $label\n";
		++$pass;
	} else {
		echo "FAIL  $label\n";
		++$fail;
	}
};

// ---------------------------------------------------------------- the card workload arithmetic.

$seed = 1794700000;
$is( 'payment fixture unchanged: 40,000 accepted orders store-wide', 40000 === blzmcp_perf_expect_orders( $seed, 50000, 10000, $seed, 24 )['store_wide']['taken_28d'] );
$is( 'full card workload: bursts of 150, 150 and 60 orders', array( 'a' => 150, 'b' => 150, 'c' => 60 ) === blzmcp_card_perf_bursts( 50000 ) );
$e48  = blzmcp_card_perf_expect( $seed, 50000, 20000, $seed, 48 );
$e168 = blzmcp_card_perf_expect( $seed, 50000, 20000, $seed, 168 );
$is( '48 hours: burst A and C inside, burst B (5 days back) outside', array( 'a' => 150, 'b' => 0, 'c' => 60 ) === $e48['bursts'] );
$is( '168 hours: every burst inside', array( 'a' => 150, 'b' => 150, 'c' => 60 ) === $e168['bursts'] );
$sum = static fn( array $t, array $keys ): int => array_sum( array_intersect_key( $t, array_flip( $keys ) ) );
$is( 'classes and origins each partition the window totals', $sum( $e168['totals'], BLZMCP_CARD_PERF_CLASSES ) === $sum( $e168['totals'], array( 'checkout_origin', 'store_api_origin', 'missing_origin', 'other_origin' ) ) && $sum( $e168['totals'], BLZMCP_CARD_PERF_CLASSES ) > 12000 );
$is( 'the window holds failed, accepted, cancelled, pending and draft orders, unassigned and other-origin context', $e48['totals']['failed_unpaid'] > 210 && $e48['totals']['accepted'] > 2000 && $e48['totals']['cancelled_unpaid'] > 0 && $e48['totals']['pending_unpaid'] > 0 && $e48['totals']['checkout_draft'] > 0 && $e48['totals']['unassigned_method'] > 0 && $e48['totals']['other_origin'] > 0 && 0 === $e48['totals']['invalid_rows'] );
$is( 'older orders (30-400 days) never enter a window', blzmcp_card_perf_expect( $seed, 50000, 0, $seed, 168 )['totals'] === $e168['totals'] );
$is( 'verified and missing-origin scopes for all 3 methods', array( 'square_credit_card', 'stripe', 'woocommerce_payments' ) === array_keys( $e48['scopes']['verified_checkout'] ) && array( 'square_credit_card', 'stripe', 'woocommerce_payments' ) === array_keys( $e48['scopes']['missing_origin'] ) );
$later = blzmcp_card_perf_expect( $seed, 50000, 20000, $seed + 7200, 48 );
$is( 'two hours later the window has moved (fewer orders at its start, none newer)', $sum( $later['totals'], BLZMCP_CARD_PERF_CLASSES ) < $sum( $e48['totals'], BLZMCP_CARD_PERF_CLASSES ) );

// Baseline per verified scope (B = [now-28d, now-48h)): >= 100 attempts spanning >= 7 days.
$base = array();
$nets = array();
$ips  = true;
foreach ( array( 'r' => 50000, 'o' => 20000 ) as $kind => $total ) {
	for ( $i = 0; $i < $total; $i++ ) {
		$o = blzmcp_card_perf_spec( $kind, $i, $seed, $total );
		if ( '' !== $o['ip'] && 1 !== preg_match( '/^(192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|::ffff:192\.0\.2\.|2001:db8:)/', $o['ip'] ) ) {
			$ips = false;
		}
		$c = blzmcp_card_perf_class( $o['status'], $o['paid'] );
		if ( 'r' === $kind && '' !== $o['method'] && in_array( $o['via'], array( 'checkout', 'store-api' ), true ) && $o['created'] < $seed - 48 * 3600 && $o['created'] >= $seed - 28 * 86400 && in_array( $c, array( 'failed_unpaid', 'accepted' ), true ) ) {
			$b                 = $base[ $o['method'] ] ?? array( 'n' => 0, 'first' => PHP_INT_MAX, 'last' => 0, 'present' => 0 );
			$b['n']           += 1;
			$b['first']        = min( $b['first'], $o['created'] );
			$b['last']         = max( $b['last'], $o['created'] );
			$b['present']     += null !== $o['attr'] && ! in_array( $o['attr'], array( '(none)', 'unknown' ), true ) ? 1 : 0;
			$base[ $o['method'] ] = $b;
		}
		if ( 'failed' === $o['status'] && '' !== $o['ip'] && $o['created'] >= $seed - 168 * 3600 ) {
			$bin = inet_pton( $o['ip'] );
			$key = 16 === strlen( $bin ) && str_starts_with( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ? substr( $bin, 12, 3 ) : ( 4 === strlen( $bin ) ? substr( $bin, 0, 3 ) : substr( $bin, 0, 8 ) );
			$nets[ bin2hex( $key ) ] = true;
		}
	}
}
$is( 'every saved IP is a documentation-range address', $ips );
$ok_base = 3 === count( $base );
foreach ( $base as $b ) {
	$ok_base = $ok_base && $b['n'] >= 100 && $b['last'] - $b['first'] >= 7 * 86400 && 2 * $b['present'] >= $b['n'];
}
$is( 'each method has a usable verified baseline (>= 100 attempts, >= 7 days, >= 50% attribution present)', $ok_base );
$is( 'recorded networks among failed orders in 168 hours stay well below the 5,000 cap (' . count( $nets ) . ')', count( $nets ) > 100 && count( $nets ) < 2500 );
$dist = blzmcp_card_perf_distribution( 50000, 20000, $seed );
$is( 'distribution: drafts, missing origins and attribution rows are seeded', $dist['drafts'] > 0 && $dist['missing_origin'] > 0 && $dist['attribution_rows'] > 0 && $dist['ip']['ipv6'] > 0 && $dist['ip']['ipv4'] > 0 && $dist['ip']['ipv4_mapped'] > 0 && $dist['ip']['missing'] > 0 );
$cap_nets = array();
$cap_ok   = true;
for ( $n = 0; $n < 5001; $n++ ) {
	$o                                       = blzmcp_card_perf_cap_network_spec( $n, $seed );
	$cap_nets[ bin2hex( substr( inet_pton( $o['ip'] ), 0, 8 ) ) ] = true;
	$cap_ok                                  = $cap_ok && $o['created'] >= $seed - 48 * 3600 && $o['created'] < $seed && 'failed' === $o['status'] && str_starts_with( $o['ip'], '2001:db8:' );
}
$is( 'network-cap fixture: 5,001 failed orders inside 48 hours, each its own documentation /64', $cap_ok && 5001 === count( $cap_nets ) );
$cap_ids = array();
for ( $n = 0; $n < 1001; $n++ ) {
	$o                       = blzmcp_card_perf_cap_method_spec( $n, $seed );
	$cap_ids[ $o['method'] ] = $o['created'] >= $seed - 48 * 3600;
}
$is( 'method-cap fixture: 1,001 distinct method IDs inside 48 hours', 1001 === count( $cap_ids ) && ! in_array( false, $cap_ids, true ) );

// ---------------------------------------------------------------- the trial validator.

$small = blzmcp_card_perf_expect( $seed, 3000, 200, $seed + 120, 48 );

/**
 * A faithful card-testing response for an expectation.
 *
 * @param array<string,mixed> $exp    blzmcp_card_perf_expect().
 * @param string              $logger file|db.
 */
function blzmcp_test_card_result( array $exp, string $logger ): array {
	$count = static fn( $v ) => array(
		'value'       => $v,
		'lower_bound' => false,
	);
	$check = static fn( string $cov = 'complete', $code = null ) => array(
		'checked'     => true,
		'applicable'  => true,
		'coverage'    => $cov,
		'reason_code' => $code,
		'reason'      => null,
	);
	$counts = static function ( array $values ) use ( $count, $check ): array {
		$out = $check();
		foreach ( array_merge( BLZMCP_CARD_PERF_TOTAL_KEYS, array( 'test_mode_orders' ) ) as $k ) {
			$out[ $k ] = $count( (int) ( $values[ $k ] ?? 0 ) );
		}
		return $out;
	};
	$share = array(
		'numerator'   => 0,
		'denominator' => 0,
		'value'       => null,
	);
	$burst = $check() + array(
		'start'                => '2026-11-27T10:00:00+00:00',
		'end'                  => '2026-11-27T11:00:00+00:00',
		'failed_orders'        => $count( 0 ),
		'accepted_orders'      => $count( 4 ),
		'failed_share'         => $share,
		'relative_burst'       => false,
		'absolute_burst'       => false,
		'networks'             => $check() + array(
			'valid_ip_orders'   => 0,
			'missing_ip_orders' => 0,
			'invalid_ip_orders' => 0,
			'groups'            => 0,
			'top_three_share'   => $share,
			'signal'            => false,
		),
		'attribution'          => $check( 'complete', 'no_failed_orders' ) + array(
			'unknown_share'          => $share,
			'baseline_unknown_share' => $share,
			'baseline_present_share' => $share,
			'signal'                 => null,
		),
		'first_created_at'     => null,
		'last_created_at'      => null,
		'recent_failed_orders' => $count( 0 ),
		'verdict'              => 'no_sign',
	);
	$assessments = array();
	foreach ( array( 'verified_checkout', 'missing_origin' ) as $scope ) {
		foreach ( array_merge( array( null ), BLZMCP_CARD_PERF_METHODS_LIST ) as $m ) {
			$partial       = 'db' === $logger && ( null === $m || 'stripe' === $m );
			$assessments[] = $check( $partial ? 'partial' : 'complete', $partial ? 'logs_unavailable' : null ) + array(
				'method_id'                => $m,
				'origin_scope'             => $scope,
				'verdict'                  => $partial ? 'unknown' : 'no_sign',
				'activity'                 => 'not_established',
				'counts'                   => $counts( null === $m ? array() : (array) ( $exp['scopes'][ $scope ][ $m ] ?? array() ) ),
				'baseline_attempt_orders'  => 500,
				'baseline_failed_orders'   => 60,
				'baseline_failed_per_hour' => 0.1,
				'baseline_p95_failed_hour' => 1.0,
				'baseline_sufficient'      => true,
				'latest_hour'              => $burst,
				'episodes'                 => array(),
				'episodes_truncated'       => false,
			);
		}
	}
	$summary = static fn( string $family, bool $applicable, string $cov, $code, $source, $value, bool $lower, int $cat ) => array(
		'checked'     => $applicable,
		'applicable'  => $applicable,
		'coverage'    => $cov,
		'reason_code' => $code,
		'reason'      => null,
		'family'      => $family,
		'source'      => $source,
		'count'       => array(
			'value'       => $value,
			'lower_bound' => $lower,
		),
		'categories'  => $cat > 0 ? array(
			array(
				'category'   => 'gateway_error',
				'count'      => $cat,
				'first_seen' => null,
				'last_seen'  => null,
			),
		) : array(),
	);
	$limitations = array();
	foreach ( array( 'store/order_state_basis', 'store/unsaved_and_retried_orders', 'store/cause_unverified', 'store/order_signal_versions', 'store/recorded_network_limits', 'store/missing_origin_orders', 'store/other_origin_orders', 'store/unassigned_method_orders', 'method:woocommerce_payments/test_mode_unknown', 'method:square_credit_card/test_mode_unknown', 'logs:square/no_supported_log_mapping', 'logs:woopayments/no_supported_log_mapping', 'logs/decline_detection_unsupported', 'attribution/attribution_evidence_only', 'order_pay/protection_unknown', 'display/examples_display_cap' ) as $l ) {
		list( $scope, $code ) = explode( '/', $l );
		$limitations[]        = array(
			'scope'      => $scope,
			'code'       => $code,
			'what'       => 'x',
			'check_next' => null,
		);
	}
	if ( 'db' === $logger ) {
		foreach ( array( array( 'logs:stripe', 'log_row_cap' ), array( 'store', 'logs_unavailable' ) ) as $l ) {
			$limitations[] = array(
				'scope'      => $l[0],
				'code'       => $l[1],
				'what'       => 'x',
				'check_next' => null,
			);
		}
	}
	return array(
		'schema_version'        => 1,
		'rule_version'          => 1,
		'checked_at'            => '2026-11-27T11:00:00+00:00',
		'since_hours'           => 48,
		'timezone'              => 'UTC',
		'window'                => array( 'start' => '', 'end' => '' ),
		'baseline'              => array( 'start' => '', 'end' => '' ),
		'verdict'               => 'likely_attack',
		'activity'              => 'earlier_in_window',
		'coverage'              => 'partial',
		'summary'               => 'x',
		'totals'                => $counts( $exp['totals'] ),
		'methods'               => array_map(
			static fn( $id ) => array(
				'id'         => $id,
				'title'      => $id,
				'family'     => BLZMCP_CARD_PERF_FAMILIES[ $id ],
				'registered' => null,
				'enabled'    => true,
				'test_mode'  => 'stripe' === $id ? false : null,
				'kind'       => 'instant',
			),
			array( 'square_credit_card', 'stripe', 'woocommerce_payments' )
		),
		'methods_total'         => 3,
		'methods_truncated'     => false,
		'assessments'           => $assessments,
		'assessments_total'     => count( $assessments ),
		'assessments_truncated' => false,
		'logs'                  => array(
			$summary( 'square', false, 'not_applicable', 'no_supported_log_mapping', null, null, false, 0 ),
			'db' === $logger ? $summary( 'stripe', true, 'partial', 'log_row_cap', 'woocommerce_log_db', 2000, true, 2000 ) : $summary( 'stripe', true, 'complete', null, 'woocommerce_log_file', 304, false, 304 ),
			$summary( 'woopayments', false, 'not_applicable', 'no_supported_log_mapping', null, null, false, 0 ),
		),
		'protection'            => array_map(
			static fn( $id ) => array(
				'id'               => $id,
				'protection_state' => 'order_pay' === $id ? 'unknown' : 'not_configured',
			),
			BLZMCP_CARD_PERF_PATHS
		),
		'examples'              => array(),
		'examples_truncated'    => true,
		'findings'              => array(),
		'findings_total'        => 0,
		'findings_truncated'    => false,
		'limitations'           => $limitations,
	);
}

/**
 * The expectation the measurement builds for a logger.
 *
 * @param array<string,mixed> $exp    blzmcp_card_perf_expect().
 * @param string              $logger file|db.
 */
function blzmcp_test_card_expect( array $exp, string $logger ): array {
	return array(
		'since_hours' => 48,
		'orders'      => $exp,
		'logger'      => $logger,
		'logs'        => 'db' === $logger ? array(
			'handler_ok'     => true,
			'in_window'      => 2001,
			'files'          => 0,
			'bytes'          => 0,
			'max_file_bytes' => 0,
		) : array(
			'handler_ok'     => true,
			'in_window'      => 304,
			'files'          => 7,
			'bytes'          => 5041050,
			'max_file_bytes' => 720150,
		),
		'limits'      => BLZMCP_CARD_PERF_LOG_LIMITS,
	);
}

/**
 * Untimed reader accounting that agrees with an expectation.
 *
 * @param array<string,mixed> $exp blzmcp_card_perf_expect().
 */
function blzmcp_test_card_reader( array $exp ): array {
	return array(
		'ok'                => true,
		'scan_complete'     => true,
		'reason'            => null,
		'coverage'          => 'complete',
		'methods_truncated' => false,
		'invalid_overflow'  => false,
		'draft_anomalies'   => 0,
		'caps'              => array(
			'bucket_cells'  => false,
			'network_ids'   => false,
			'network_cells' => false,
		),
		'totals'            => $exp['totals'],
		'signals'           => array(
			'ip'          => array(
				'supported' => true,
				'reason'    => null,
			),
			'attribution' => array(
				'supported' => true,
				'reason'    => null,
			),
		),
		'work'              => array( 'meta_rows' => 2900 ),
	);
}

// $tv( label, logger, mutate(result, expect, reader), reader that must fail or null for "valid" ).
$tv = static function ( string $label, string $logger, ?callable $mutate, ?string $reader ) use ( $is, $small ): void {
	$r = blzmcp_test_card_result( $small, $logger );
	$x = blzmcp_test_card_expect( $small, $logger );
	$a = blzmcp_test_card_reader( $small );
	if ( null !== $mutate ) {
		$mutate( $r, $x, $a );
	}
	$v = blzmcp_card_perf_validate_trial( $r, $x, array( 'reader' => $a ) );
	if ( null === $reader ) {
		$is( $label, true === $v['ok'] && array() === $v['failures'] && ! in_array( false, $v['readers'], true ) );
		if ( true !== $v['ok'] ) {
			echo '      ' . implode( "\n      ", $v['failures'] ) . "\n";
		}
		return;
	}
	$is( $label . " (fails the $reader reader)", false === $v['ok'] && false === $v['readers'][ $reader ] );
};
$lim = static function ( string $scope, string $code ): callable {
	return static function ( array &$r ) use ( $scope, $code ): void {
		$r['limitations'][] = array(
			'scope'      => $scope,
			'code'       => $code,
			'what'       => 'x',
			'check_next' => null,
		);
	};
};
$find = static function ( array &$r, $m, string $scope ): ?int {
	foreach ( $r['assessments'] as $i => $a ) {
		if ( $m === $a['method_id'] && $scope === $a['origin_scope'] ) {
			return $i;
		}
	}
	return null;
};
$stripe_log = static function ( array &$r ): int {
	foreach ( $r['logs'] as $i => $s ) {
		if ( 'stripe' === $s['family'] ) {
			return $i;
		}
	}
	return -1;
};

$tv( 'trial: a faithful file-logger result is valid', 'file', null, null );
$tv( 'trial: a faithful DB-logger result (row cap, Stripe scopes partial) is valid', 'db', null, null );
$v = blzmcp_card_perf_validate_trial( null, blzmcp_test_card_expect( $small, 'file' ) );
$is( 'trial: no response fails every reader', false === $v['ok'] && ! in_array( true, $v['readers'], true ) );
$tv( 'trial: schema version 2', 'file', static function ( &$r ) { $r['schema_version'] = 2; }, 'response' );
$tv( 'trial: another window', 'file', static function ( &$r ) { $r['since_hours'] = 168; }, 'response' );
$tv( 'trial: 4 protection paths', 'file', static function ( &$r ) { array_pop( $r['protection'] ); }, 'response' );
$tv( 'trial: a missing top-level key', 'file', static function ( &$r ) { unset( $r['findings_total'] ); }, 'response' );
$tv( 'trial: an empty workload', 'file', static function ( &$r, &$x ) { $x['orders']['totals']['failed_unpaid'] = 0; }, 'workload' );
$tv( 'trial: no failure burst in the window', 'file', static function ( &$r, &$x ) { $x['orders']['bursts']['a'] = 0; }, 'workload' );
$tv( 'trial: a verified scope without a usable baseline', 'file', static function ( &$r ) use ( $find ) { $r['assessments'][ $find( $r, 'stripe', 'verified_checkout' ) ]['baseline_sufficient'] = false; }, 'workload' );
$tv( 'trial: order scan partial', 'file', static function ( &$r ) { $r['totals']['coverage'] = 'partial'; $r['totals']['reason_code'] = 'order_scan_budget'; }, 'orders' );
$tv( 'trial: an order scan without a reason_code key (missing is not null)', 'file', static function ( &$r ) { unset( $r['totals']['reason_code'] ); }, 'orders' );
$tv( 'trial: reader accounting without a reason key', 'file', static function ( &$r, &$x, &$a ) { unset( $a['reason'] ); }, 'orders' );
$tv( 'trial: a failed count one short', 'file', static function ( &$r ) { --$r['totals']['failed_unpaid']['value']; }, 'orders' );
$tv( 'trial: a lower-bound total', 'file', static function ( &$r ) { $r['totals']['accepted']['lower_bound'] = true; }, 'orders' );
$tv( 'trial: a scope count changed', 'file', static function ( &$r ) use ( $find ) { ++$r['assessments'][ $find( $r, 'woocommerce_payments', 'verified_checkout' ) ]['counts']['failed_unpaid']['value']; }, 'orders' );
$tv( 'trial: a missing method scope', 'file', static function ( &$r ) use ( $find ) { array_splice( $r['assessments'], $find( $r, 'square_credit_card', 'verified_checkout' ), 1 ); $r['assessments_total'] = count( $r['assessments'] ); }, 'orders' );
$tv( 'trial: method map capped (methods_truncated)', 'file', static function ( &$r ) { $r['methods_truncated'] = true; $r['methods_total'] = null; }, 'orders' );
$tv( 'trial: assessments_total null', 'file', static function ( &$r ) { $r['assessments_total'] = null; }, 'orders' );
$tv( 'trial: reader accounting scan incomplete', 'file', static function ( &$r, &$x, &$a ) { $a['scan_complete'] = false; $a['reason'] = 'row_cap'; }, 'orders' );
$tv( 'trial: reader accounting network map capped', 'file', static function ( &$r, &$x, &$a ) { $a['caps']['network_ids'] = true; }, 'signals' );
$tv( 'trial: reader accounting bucket cells capped', 'file', static function ( &$r, &$x, &$a ) { $a['caps']['bucket_cells'] = true; }, 'orders' );
$tv( 'trial: reader accounting totals disagree', 'file', static function ( &$r, &$x, &$a ) { ++$a['totals']['accepted']; }, 'orders' );
$tv( 'trial: no reader accounting', 'file', static function ( &$r, &$x, &$a ) { $a = null; }, 'orders' );
$tv( 'trial: reader accounting attribution unsupported', 'file', static function ( &$r, &$x, &$a ) { $a['signals']['attribution'] = array( 'supported' => false, 'reason' => 'attribution_feature_disabled' ); }, 'signals' );
foreach ( array( 'method_cap' => 'orders', 'row_cap' => 'orders', 'order_scan_budget' => 'orders', 'invalid_timestamps' => 'orders', 'draft_paid_anomaly' => 'orders', 'read_failed' => 'orders', 'network_cap' => 'signals', 'network_bucket_cell_cap' => 'signals', 'bucket_cell_cap' => 'signals', 'ip_storage_unavailable' => 'signals', 'attribution_feature_disabled' => 'signals', 'attribution_override_present' => 'signals', 'unsupported_source_version' => 'signals', 'attribution_value_unsupported' => 'signals', 'method_facts_unavailable' => 'methods' ) as $code => $reader ) {
	$tv( "trial: a store/$code limitation", 'file', $lim( 'store', $code ), $reader );
}
$tv( 'trial: logs_unavailable with the file logger', 'file', $lim( 'store', 'logs_unavailable' ), 'logs' );
$tv( 'trial: diagnostics denied', 'db', $lim( 'logs', 'diagnostics_denied' ), 'logs' );
$tv( 'trial: a log source read failure limitation', 'file', $lim( 'logs', 'source_read_failed' ), 'logs' );
$tv( 'trial: a log family unknown', 'file', $lim( 'logs', 'log_family_unknown' ), 'logs' );
$tv( 'trial: a Stripe byte cap (not the near-budget file state)', 'file', $lim( 'logs:stripe', 'byte_cap' ), 'logs' );
$tv( 'trial: a Stripe row cap with the file logger', 'file', $lim( 'logs:stripe', 'log_row_cap' ), 'logs' );
$tv( 'trial: a Stripe file cap with the DB logger', 'db', $lim( 'logs:stripe', 'file_cap' ), 'logs' );
$tv( 'trial: changed examples', 'file', $lim( 'display', 'examples_changed' ), 'orders' );
$tv( 'trial: an assessments display cap (extra scopes)', 'file', $lim( 'display', 'assessments_display_cap' ), 'response' );
$tv( 'trial: a method in test mode (limitation)', 'file', $lim( 'method:stripe', 'method_test_mode' ), 'methods' );
$tv( 'trial: an unknown limitation scope', 'file', $lim( 'elsewhere', 'something' ), 'response' );
$tv( 'trial: attribution disabled (attribution scope)', 'file', $lim( 'attribution', 'attribution_disabled' ), 'signals' );
$tv( 'trial: a method in test mode (facts)', 'file', static function ( &$r ) { $r['methods'][1]['test_mode'] = true; }, 'methods' );
$tv( 'trial: a method of unknown kind', 'file', static function ( &$r ) { $r['methods'][0]['kind'] = 'unknown'; }, 'methods' );
$tv( 'trial: an extra method', 'file', static function ( &$r ) { $r['methods'][] = array( 'id' => 'zz', 'kind' => 'instant' ); }, 'methods' );
$tv( 'trial: latest hour networks capped', 'file', static function ( &$r ) { $r['assessments'][0]['latest_hour']['networks']['coverage'] = 'partial'; $r['assessments'][0]['latest_hour']['networks']['reason_code'] = 'network_cap'; }, 'signals' );
$tv( 'trial: latest hour attribution not read', 'file', static function ( &$r ) { $r['assessments'][3]['latest_hour']['attribution']['checked'] = false; }, 'signals' );
$tv( 'trial: no recorded-network limitation (IPs not read)', 'file', static function ( &$r ) { $r['limitations'] = array_values( array_filter( $r['limitations'], static fn( $l ) => 'recorded_network_limits' !== $l['code'] ) ); }, 'signals' );
$tv( 'trial: an unexpected partial scope (file logger)', 'file', static function ( &$r ) use ( $find ) { $r['assessments'][ $find( $r, 'woocommerce_payments', 'verified_checkout' ) ]['coverage'] = 'partial'; }, 'orders' );
$tv( 'trial: a non-Stripe scope partial with the DB logger', 'db', static function ( &$r ) use ( $find ) { $r['assessments'][ $find( $r, 'square_credit_card', 'missing_origin' ) ]['coverage'] = 'partial'; }, 'orders' );
$tv( 'trial: the Stripe scope complete with the DB logger (row cap not applied)', 'db', static function ( &$r ) use ( $find ) { $r['assessments'][ $find( $r, 'stripe', 'verified_checkout' ) ]['coverage'] = 'complete'; }, 'logs' );
$tv( 'trial: file log read partial (byte cap)', 'file', static function ( &$r ) use ( $stripe_log ) { $i = $stripe_log( $r ); $r['logs'][ $i ]['coverage'] = 'partial'; $r['logs'][ $i ]['reason_code'] = 'byte_cap'; }, 'logs' );
$tv( 'trial: file log count differs from the fixture', 'file', static function ( &$r ) use ( $stripe_log ) { --$r['logs'][ $stripe_log( $r ) ]['count']['value']; }, 'logs' );
$tv( 'trial: file log unavailable (a generic reader failure)', 'file', static function ( &$r ) use ( $stripe_log ) { $i = $stripe_log( $r ); $r['logs'][ $i ]['checked'] = false; $r['logs'][ $i ]['coverage'] = 'unavailable'; $r['logs'][ $i ]['reason_code'] = 'log_directory_unavailable'; }, 'logs' );
$tv( 'trial: file fixture with 6 files (not at the file cap)', 'file', static function ( &$r, &$x ) { $x['logs']['files'] = 6; }, 'logs' );
$tv( 'trial: file fixture far below the byte budget', 'file', static function ( &$r, &$x ) { $x['logs']['bytes'] = 1000000; }, 'logs' );
$tv( 'trial: file fixture with one file over its per-file share', 'file', static function ( &$r, &$x ) { $x['logs']['max_file_bytes'] = 800000; }, 'logs' );
$tv( 'trial: the log handler is not the file logger', 'file', static function ( &$r, &$x ) { $x['logs']['handler_ok'] = false; }, 'logs' );
$tv( 'trial: DB log read complete (row cap not reached)', 'db', static function ( &$r ) use ( $stripe_log ) { $i = $stripe_log( $r ); $r['logs'][ $i ]['coverage'] = 'complete'; $r['logs'][ $i ]['reason_code'] = null; }, 'logs' );
$tv( 'trial: DB log count not exactly the 2,000-row cap', 'db', static function ( &$r ) use ( $stripe_log ) { $r['logs'][ $stripe_log( $r ) ]['count']['value'] = 1999; }, 'logs' );
$tv( 'trial: DB log count not a lower bound', 'db', static function ( &$r ) use ( $stripe_log ) { $r['logs'][ $stripe_log( $r ) ]['count']['lower_bound'] = false; }, 'logs' );
$tv( 'trial: DB fixture not over the row cap', 'db', static function ( &$r, &$x ) { $x['logs']['in_window'] = 2000; }, 'logs' );
$tv( 'trial: DB log source failed (a generic reader failure)', 'db', static function ( &$r ) use ( $stripe_log ) { $i = $stripe_log( $r ); $r['logs'][ $i ]['checked'] = false; $r['logs'][ $i ]['coverage'] = 'unavailable'; $r['logs'][ $i ]['reason_code'] = 'source_read_failed'; }, 'logs' );
$tv( 'trial: DB row cap not disclosed as a limitation', 'db', static function ( &$r ) { $r['limitations'] = array_values( array_filter( $r['limitations'], static fn( $l ) => 'log_row_cap' !== $l['code'] ) ); }, 'logs' );
$tv( 'trial: a family log summary missing', 'file', static function ( &$r ) { array_pop( $r['logs'] ); }, 'logs' );
$tv( 'trial: a no-mapping family reported as applicable', 'file', static function ( &$r ) { $r['logs'][0]['applicable'] = true; }, 'logs' );
$tv( 'trial: partial top-level coverage with every path known (file logger)', 'file', static function ( &$r ) { $r['protection'][3]['protection_state'] = 'configured'; }, 'response' );
$forced = blzmcp_test_card_result( $small, 'file' );
$forced['totals'] = array( 'checked' => false, 'applicable' => true, 'coverage' => 'unavailable', 'reason_code' => 'read_failed' ) + $forced['totals'];
$v = blzmcp_card_perf_validate_trial( $forced, blzmcp_test_card_expect( $small, 'file' ), array( 'reader' => blzmcp_test_card_reader( $small ) ) );
$is( 'trial: a forced orders-reader failure is detected on the orders reader', false === $v['ok'] && false === $v['readers']['orders'] );

// ---------------------------------------------------------------- the bound-case validator.

/**
 * A faithful bound-case response.
 *
 * @param string $which methods|networks.
 * @param int    $k     Distinct IDs or networks.
 */
function blzmcp_test_card_bound( string $which, int $k ): array {
	$cnt = static fn( $v ) => array(
		'value'       => $v,
		'lower_bound' => false,
	);
	$r   = array(
		'coverage'    => 'partial',
		'totals'      => array(
			'checked'       => true,
			'coverage'      => 'complete',
			'accepted'      => $cnt( 'methods' === $which ? $k : 0 ),
			'failed_unpaid' => $cnt( 'networks' === $which ? $k : 0 ),
		),
		'limitations' => array(),
	);
	if ( 'methods' === $which ) {
		$r['methods'] = array();
		$listed       = min( $k, 1000 );
		for ( $n = 0; $n < $listed; $n++ ) {
			$r['methods'][] = array(
				'id'   => sprintf( 'blzmcp_ctcap_%04d', $n ),
				'kind' => 'unknown',
			);
		}
		$r['methods_truncated'] = $k > 1000;
		$r['methods_total']     = $k > 1000 ? null : $k;
		$r['assessments_total'] = $k > 1000 ? null : $k;
		if ( $k > 1000 ) {
			$r['limitations'][] = array( 'scope' => 'store', 'code' => 'method_cap' );
		}
		return $r;
	}
	$capped            = $k > 5000;
	$r['assessments']  = array(
		array(
			'method_id'    => 'woocommerce_payments',
			'origin_scope' => 'verified_checkout',
			'latest_hour'  => array(
				'networks' => array(
					'coverage'    => $capped ? 'partial' : 'complete',
					'reason_code' => $capped ? 'network_cap' : null,
					'groups'      => $capped ? null : 141,
				),
			),
		),
	);
	if ( $capped ) {
		$r['limitations'][] = array( 'scope' => 'store', 'code' => 'network_cap' );
	}
	return $r;
}
foreach ( array( array( 'methods', 1000 ), array( 'methods', 1001 ), array( 'networks', 5000 ), array( 'networks', 5001 ) ) as $b ) {
	$is( "bound: a faithful {$b[0]} case at {$b[1]} passes", blzmcp_card_perf_validate_bound( blzmcp_test_card_bound( $b[0], $b[1] ), $b[0], $b[1] )['ok'] );
}
$bv = static function ( string $label, string $which, int $k, callable $mutate ) use ( $is ): void {
	$r = blzmcp_test_card_bound( $which, $k );
	$mutate( $r );
	$is( "bound: $label fails", false === blzmcp_card_perf_validate_bound( $r, $which, $k )['ok'] );
};
$bv( '1,001 IDs without methods_truncated', 'methods', 1001, static function ( &$r ) { $r['methods_truncated'] = false; } );
$bv( '1,001 IDs without the method_cap limitation', 'methods', 1001, static function ( &$r ) { $r['limitations'] = array(); } );
$bv( '1,001 IDs with an exact methods_total', 'methods', 1001, static function ( &$r ) { $r['methods_total'] = 1000; } );
$bv( '1,001 IDs listing the 1,001st ID', 'methods', 1001, static function ( &$r ) { $r['methods'][999]['id'] = 'blzmcp_ctcap_1000'; } );
$bv( '1,000 IDs reported as truncated', 'methods', 1000, static function ( &$r ) { $r['methods_truncated'] = true; } );
$bv( '1,000 IDs with the method_cap limitation', 'methods', 1000, static function ( &$r ) { $r['limitations'][] = array( 'scope' => 'store', 'code' => 'method_cap' ); } );
$bv( '1,000 IDs with one missing', 'methods', 1000, static function ( &$r ) { array_pop( $r['methods'] ); } );
$bv( 'a method-cap total that stopped at the cap', 'methods', 1001, static function ( &$r ) { $r['totals']['accepted']['value'] = 1000; } );
$bv( '5,001 networks without the network_cap limitation', 'networks', 5001, static function ( &$r ) { $r['limitations'] = array(); } );
$bv( '5,001 networks with an exact group count', 'networks', 5001, static function ( &$r ) { $r['assessments'][0]['latest_hour']['networks']['groups'] = 141; } );
$bv( '5,001 networks reported complete', 'networks', 5001, static function ( &$r ) { $r['assessments'][0]['latest_hour']['networks']['coverage'] = 'complete'; } );
$bv( '5,001 networks hitting a bucket-cell cap instead', 'networks', 5001, static function ( &$r ) { $r['limitations'][] = array( 'scope' => 'store', 'code' => 'network_bucket_cell_cap' ); } );
$bv( '5,000 networks with the network_cap limitation', 'networks', 5000, static function ( &$r ) { $r['limitations'][] = array( 'scope' => 'store', 'code' => 'network_cap' ); } );
$bv( '5,000 networks reported partial', 'networks', 5000, static function ( &$r ) { $r['assessments'][0]['latest_hour']['networks']['coverage'] = 'partial'; } );
$bv( 'a network-cap failed total that stopped at the cap', 'networks', 5001, static function ( &$r ) { $r['totals']['failed_unpaid']['value'] = 5000; } );
$bv( 'a network case without its assessment', 'networks', 5000, static function ( &$r ) { $r['assessments'] = array(); } );
$bv( 'an incomplete order scan', 'methods', 1000, static function ( &$r ) { $r['totals']['coverage'] = 'partial'; } );
$is( 'bound: an unknown case fails', false === blzmcp_card_perf_validate_bound( blzmcp_test_card_bound( 'methods', 1000 ), 'trials', 1000 )['ok'] );
$is( 'bound: no response fails', false === blzmcp_card_perf_validate_bound( null, 'networks', 5000 )['ok'] );

// ---------------------------------------------------------------- the receipt validator.

$cand = str_repeat( 'ab', 32 );
$src  = '0123456789abcdef0123456789abcdef01234567';
$att  = 'card-perf-attempt-1';
$bind = array(
	'candidate' => $cand,
	'source'    => $src,
	'attempt'   => $att,
);

// The good receipt is the shared sample (card-testing-perf-sample.php), so it stays in sync with
// test-record.sh, which uses the same generator.
$good = blzmcp_card_perf_sample_receipt( $cand, $src, $att );
$is( 'receipt: a complete receipt has no problem', array() === blzmcp_card_receipt_problems( $good, $bind ) );
$rv   = static function ( string $label, callable $mutate, string $reason ) use ( $is, $good, $bind ): void {
	$r = $good;
	$mutate( $r );
	$p   = blzmcp_card_receipt_problems( $r, $bind );
	$hit = array_filter( $p, static fn( $x ) => false !== strpos( $x, $reason ) );
	$is( "receipt: $label is refused ($reason)", array() !== $hit );
	if ( array() === $hit ) {
		echo '      got: ' . implode( ' | ', array_slice( $p, 0, 4 ) ) . "\n";
	}
};
$trial = static function ( array &$r, string $s, int $w, string $l, int $t ): ?int {
	foreach ( $r['trials'] as $i => $x ) {
		if ( $s === $x['storage'] && $w === $x['window'] && $l === $x['logger'] && $t === $x['trial'] ) {
			return $i;
		}
	}
	return null;
};
$rv( 'one trial at exactly 1500 ms (the receipt still says pass)', static function ( &$r ) use ( $trial ) { $r['trials'][ $trial( $r, 'hpos', 168, 'db', 3 ) ]['elapsed_ms'] = 1500; }, 'partition hpos/168h/db trial 3 took 1500 ms, not strictly below 1500 ms' );
$rv( 'a legacy DB trial above budget (no relaxed rule)', static function ( &$r ) use ( $trial ) { $r['trials'][ $trial( $r, 'legacy', 48, 'db', 5 ) ]['elapsed_ms'] = 1605.8; }, 'partition legacy/48h/db trial 5 took 1605.8 ms' );
$rv( 'a pooled/median-only receipt', static function ( &$r ) { $r['trials'] = null; foreach ( $r['partitions'] as &$p ) { unset( $p['trials_ms'] ); $p['median_ms'] = 903; } unset( $p ); }, 'no raw trials' );
$rv( 'a partition reporting a median beside its trials', static function ( &$r ) { $r['partitions'][0]['median_ms'] = 903; }, 'median or pooled figure' );
$rv( 'a 4-trial partition', static function ( &$r ) use ( $trial ) { array_splice( $r['trials'], $trial( $r, 'legacy', 168, 'file', 5 ), 1 ); }, 'partition legacy/168h/file has 4 trials, not 5' );
$rv( 'a duplicated trial', static function ( &$r ) use ( $trial ) { $r['trials'][ $trial( $r, 'hpos', 48, 'file', 3 ) ]['trial'] = 2; }, 'partition hpos/48h/file trial numbers are not 1-5 once each (1,2,2,4,5)' );
$rv( 'a sixth (retried) trial', static function ( &$r ) use ( $trial ) { $x = $r['trials'][ $trial( $r, 'hpos', 48, 'file', 5 ) ]; $x['trial'] = 6; $r['trials'][] = $x; }, 'partition hpos/48h/file has 6 trials, not 5' );
$rv( 'a cap-reached trial', static function ( &$r ) use ( $trial ) { $i = $trial( $r, 'hpos', 168, 'file', 1 ); $r['trials'][ $i ]['ok'] = false; $r['trials'][ $i ]['validation']['readers']['signals'] = false; $r['trials'][ $i ]['validation']['failures'] = array( 'signals: reader accounting: network_ids cap reached' ); }, 'partition hpos/168h/file trial 1 is not a valid trial (signals: reader accounting: network_ids cap reached)' );
$rv( 'an empty-workload trial', static function ( &$r ) use ( $trial ) { $i = $trial( $r, 'legacy', 48, 'file', 2 ); $r['trials'][ $i ]['ok'] = false; $r['trials'][ $i ]['validation']['readers']['workload'] = false; $r['trials'][ $i ]['validation']['failures'] = array( 'workload: the fixture expects no failed or no accepted orders in the window (workload not seeded)' ); }, 'partition legacy/48h/file trial 2 is not a valid trial (workload:' );
$rv( 'a trial with a reader missing from its validation', static function ( &$r ) use ( $trial ) { unset( $r['trials'][ $trial( $r, 'legacy', 48, 'db', 1 ) ]['validation']['readers']['logs'] ); }, 'partition legacy/48h/db trial 1 is not a valid trial' );
$rv( 'a smoke receipt', static function ( &$r ) { $r['smoke'] = true; $r['smoke_reasons'] = array( 'orders=600' ); }, 'smoke receipt (not a gate run: orders=600)' );
$rv( 'a receipt without a smoke flag', static function ( &$r ) { unset( $r['smoke'] ); }, 'no smoke flag' );
$rv( 'a stale candidate SHA', static function ( &$r ) { $r['candidate_sha256'] = str_repeat( 'cd', 32 ); }, 'candidate_sha256 ' . str_repeat( 'cd', 32 ) . ' is not the candidate' );
$rv( 'a stale source SHA', static function ( &$r ) { $r['source_sha'] = 'fedcba9876543210fedcba9876543210fedcba98'; }, 'source_sha fedcba9876543210fedcba9876543210fedcba98 is not the source' );
$rv( 'a different attempt', static function ( &$r ) { $r['attempt'] = 'card-perf-attempt-0'; }, 'attempt card-perf-attempt-0 is not this attempt' );
$rv( 'another schema', static function ( &$r ) { $r['schema'] = 'blzmcp-payment-performance/2'; }, 'schema is not blzmcp-card-performance/1' );
$rv( 'a relaxed rule', static function ( &$r ) { $r['rule'] = 'relaxed'; }, 'budget is not 1500 ms under rule 5of5<1500' );
$rv( 'a missing partition', static function ( &$r ) { $r['trials'] = array_values( array_filter( $r['trials'], static fn( $t ) => ! ( 'legacy' === $t['storage'] && 168 === $t['window'] && 'db' === $t['logger'] ) ) ); }, 'partition legacy/168h/db has 0 trials, not 5' );
$rv( 'a trial outside the 8 partitions', static function ( &$r ) { $x = $r['trials'][0]; $x['window'] = 24; $r['trials'][] = $x; }, 'a trial outside the 8 partitions' );
$rv( 'a trial without metadata rows', static function ( &$r ) use ( $trial ) { $r['trials'][ $trial( $r, 'hpos', 48, 'db', 4 ) ]['meta_rows'] = null; }, 'partition hpos/48h/db trial 4 is missing metadata rows' );
$rv( 'a trial without a query plan', static function ( &$r ) use ( $trial ) { $r['trials'][ $trial( $r, 'hpos', 48, 'db', 4 ) ]['slowest'] = array( array( 'ms' => 1, 'plan' => null ) ); }, 'is missing query plan' );
$rv( 'a trial without its warm time and query count', static function ( &$r ) use ( $trial ) { $i = $trial( $r, 'legacy', 168, 'db', 2 ); unset( $r['trials'][ $i ]['warm_ms'], $r['trials'][ $i ]['queries'] ); }, 'partition legacy/168h/db trial 2 is missing query count, warm time' );
$rv( 'a trial without rows examined', static function ( &$r ) use ( $trial ) { unset( $r['trials'][ $trial( $r, 'legacy', 168, 'db', 2 ) ]['rows_examined'] ); }, 'rows examined/selected' );
$rv( 'a trial without elapsed_ms', static function ( &$r ) use ( $trial ) { unset( $r['trials'][ $trial( $r, 'legacy', 168, 'file', 4 ) ]['elapsed_ms'] ); }, 'partition legacy/168h/file trial 4 has no elapsed_ms' );
$rv( 'a partition summary that disagrees with its raw trials', static function ( &$r ) { $r['partitions'][2]['trials_ms'] = array( 100, 100, 100, 100, 100 ); }, 'does not match its raw trials' );
$rv( 'a missing HTTP warm figure', static function ( &$r ) { $r['http'] = array_values( array_filter( $r['http'], static fn( $h ) => ! ( 'hpos' === $h['storage'] && 168 === $h['window'] && 'warm' === $h['kind'] ) ) ); }, 'no HTTP warm figure for hpos/168h' );
$rv( 'an HTTP figure with an invalid body', static function ( &$r ) { $r['http'][0]['valid_body'] = false; }, 'no HTTP cold figure for legacy/48h' );
$rv( 'a missing workflow cost', static function ( &$r ) { array_pop( $r['workflow_cost'] ); }, 'combined workflow cost missing or failed (hpos)' );
$rv( 'a workflow cost pooled into a budget', static function ( &$r ) { $r['workflow_cost'][0]['pooled_budget'] = true; }, 'combined workflow cost missing or failed (legacy)' );
$rv( 'a missing bound case', static function ( &$r ) { $r['bound_cases'] = array_values( array_filter( $r['bound_cases'], static fn( $b ) => ! ( 'legacy' === $b['storage'] && 1001 === $b['k'] ) ) ); }, 'bound case methods/1001 (legacy) missing or failed' );
$rv( 'a failed bound case', static function ( &$r ) { $r['bound_cases'][7]['ok'] = false; $r['bound_cases'][7]['failures'] = array( 'above the cap' ); }, 'bound case networks/5001 (hpos) missing or failed' );
$rv( 'a bound case counted as a trial', static function ( &$r ) { $r['trials'][] = $r['bound_cases'][0] + array( 'window' => 48, 'logger' => 'file', 'trial' => 6 ); }, 'a bound case is counted as a trial' );
$rv( 'a bound case flagged as counted', static function ( &$r ) { $r['bound_cases'][0]['counted_as_trial'] = true; }, 'bound case methods/1000 (legacy) missing or failed' );
$rv( 'a forced probe counted as a trial', static function ( &$r ) use ( $trial ) { $r['trials'][ $trial( $r, 'hpos', 48, 'db', 1 ) ]['forced'] = 'orders'; }, 'a forced-failure probe is counted as a trial' );
$rv( 'a forced failure not detected', static function ( &$r ) { foreach ( $r['forced_failures'] as &$f ) { if ( 'hpos' === $f['storage'] && 'db' === $f['logger'] && 'logs' === $f['force'] ) { $f['ok'] = true; $f['detected'] = false; } } unset( $f ); }, 'forced logs-reader failure (hpos, db logger) not shown failed and detected' );
$rv( 'a missing forced probe', static function ( &$r ) { array_shift( $r['forced_failures'] ); }, 'forced orders-reader failure (legacy, file logger) not shown failed and detected' );
$rv( 'an unclean cleanup (tagged orders left)', static function ( &$r ) { foreach ( $r['cleanup'] as &$c ) { if ( 'after' === $c['phase'] && 'hpos' === $c['storage'] ) { $c['tagged_remaining'] = 3; } } unset( $c ); }, 'cleanup after (hpos) missing or unclean' );
$rv( 'a cleanup that removed fewer orders than seeded', static function ( &$r ) { foreach ( $r['cleanup'] as &$c ) { if ( 'bound-networks' === $c['phase'] && 'legacy' === $c['storage'] ) { $c['orders_removed'] = 5000; } } unset( $c ); }, 'cleanup bound-networks (legacy) missing or unclean' );
$rv( 'settings not restored', static function ( &$r ) { $r['cleanup'][3]['settings_restored'] = false; }, 'cleanup after (legacy) missing or unclean' );
$rv( 'a missing cleanup phase', static function ( &$r ) { $r['cleanup'] = array_values( array_filter( $r['cleanup'], static fn( $c ) => ! ( 'diag-db' === $c['phase'] && 'hpos' === $c['storage'] ) ) ); }, 'cleanup diag-db (hpos) missing or unclean' );
$rv( 'a missing final cleanup', static function ( &$r ) { array_pop( $r['cleanup'] ); }, 'final cleanup missing or unclean' );
$rv( 'an unrestored order storage', static function ( &$r ) { $r['storage_restored']['ok'] = false; }, 'original order storage not restored' );
$rv( 'a missing host identity', static function ( &$r ) { unset( $r['environment']['host'] ); }, 'environment host identity missing' );
$rv( 'missing container limits', static function ( &$r ) { $r['environment']['container_limits'] = null; }, 'environment container limits missing' );
$rv( 'two version identities', static function ( &$r ) { $r['environment']['versions'][] = array( 'php' => '8.4.1', 'wp' => '7.1.5', 'wc' => '11.3.1', 'db' => 'x' ); }, 'environment versions missing or not one identity' );
$rv( 'a workload below full size', static function ( &$r ) { $r['seed']['orders'] = 49999; }, 'workload below full size (orders 49999, older 20000)' );
$rv( 'a smaller older tail', static function ( &$r ) { $r['seed']['old_orders'] = 10000; }, 'workload below full size (orders 50000, older 10000)' );
$rv( 'other methods', static function ( &$r ) { $r['seed']['methods'] = array( 'stripe', 'cod', 'cheque' ); }, 'workload methods are not the 3 instant methods' );
$rv( 'no drafts', static function ( &$r ) { $r['seed']['drafts'] = 0; }, 'workload has no checkout drafts or no missing-origin orders' );
$rv( 'an unverified storage', static function ( &$r ) { $r['seed']['storages'][1]['verify']['ok'] = false; }, 'workload not seeded and verified in the authoritative store (hpos)' );
$p = blzmcp_card_receipt_problems( $good, array( 'candidate' => $cand, 'source' => $src, 'attempt' => 'other' ) );
$is( 'receipt: the caller\'s attempt decides, not the receipt\'s', array() !== array_filter( $p, static fn( $x ) => false !== strpos( $x, 'is not this attempt other' ) ) );

// The CLI: exit codes and lines, a truncated file included.
$validator = __DIR__ . '/card-testing-perf-validate.php';
$cli       = static function ( ?string $content, array $extra = array() ) use ( $validator, $cand, $src, $att ): array {
	$file = tempnam( sys_get_temp_dir(), 'blzmcp-card-receipt-' );
	if ( null === $content ) {
		unlink( $file );
	} else {
		file_put_contents( $file, $content );
	}
	$args = array() === $extra ? array( '--candidate-sha256=' . $cand, '--source-sha=' . $src, '--attempt=' . $att ) : $extra;
	$cmd  = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $validator ) . ' ' . escapeshellarg( $file ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	$out  = array();
	exec( $cmd, $out, $code );
	if ( null !== $content ) {
		unlink( $file );
	}
	return array( $code, (string) end( $out ) );
};
$json = (string) json_encode( $good );
list( $code, $last ) = $cli( $json );
$is( 'cli: a good receipt prints CARD PERF VALID (40 trials, 8 partitions) and exits 0', 0 === $code && 'CARD PERF VALID (40 trials, 8 partitions)' === $last );
$bad = $good;
$bad['trials'][0]['elapsed_ms'] = 1500;
list( $code, $last ) = $cli( (string) json_encode( $bad ) );
$is( 'cli: a refused receipt prints CARD PERF INVALID: <reasons> and exits 1', 1 === $code && 0 === strpos( $last, 'CARD PERF INVALID: partition legacy/48h/file trial 1 took 1500 ms' ) );
list( $code, $last ) = $cli( substr( $json, 0, (int) ( strlen( $json ) / 2 ) ) );
$is( 'cli: a truncated receipt exits 2 (not JSON)', 2 === $code && false !== strpos( $last, 'is not a JSON object (truncated or not JSON)' ) );
list( $code, $last ) = $cli( '[1, 2, 3]' );
$is( 'cli: a JSON list is not a receipt (exit 2)', 2 === $code );
list( $code, $last ) = $cli( null );
$is( 'cli: a missing receipt exits 2', 2 === $code && false !== strpos( $last, 'is missing or unreadable' ) );
list( $code, $last ) = $cli( $json, array( '--candidate-sha256=' . $cand, '--source-sha=' . $src ) );
$is( 'cli: a missing --attempt is a usage error (exit 2)', 2 === $code && false !== strpos( $last, 'usage:' ) );
list( $code, $last ) = $cli( $json, array( '--candidate-sha256=' . str_repeat( '00', 32 ), '--source-sha=' . $src, '--attempt=' . $att ) );
$is( 'cli: another candidate is refused (exit 1)', 1 === $code && false !== strpos( $last, 'is not the candidate ' . str_repeat( '00', 32 ) ) );

// The sample generator's CLI output, used the way test-record.sh uses it.
$sample = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/card-testing-perf-sample.php' ) . ' ' . escapeshellarg( $cand ) . ' ' . escapeshellarg( $src ) . ' ' . escapeshellarg( $att ) . ' 2>&1', $sample, $code );
$sample_json = implode( "\n", $sample );
$sample_r    = json_decode( $sample_json, true );
$is( 'sample: the CLI prints one full-size, non-smoke receipt equal to the shared builder', 0 === $code && $sample_r === $good && false === $sample_r['smoke'] && 40 === count( $sample_r['trials'] ) );
list( $code, $last ) = $cli( $sample_json );
$is( 'sample: the validator accepts it (exit 0, CARD PERF VALID)', 0 === $code && 0 === strpos( $last, 'CARD PERF VALID' ) );
$mut = static function ( array $r, string $s, int $w, string $l, int $t, float $ms ): array {
	foreach ( $r['trials'] as &$x ) {
		if ( $s === $x['storage'] && $w === $x['window'] && $l === $x['logger'] && $t === $x['trial'] ) {
			$x['elapsed_ms'] = $ms;
		}
	}
	unset( $x );
	return $r;
};
list( $code, $last ) = $cli( (string) json_encode( $mut( $sample_r, 'legacy', 168, 'db', 3, 1500 ) ) );
$is( 'sample: legacy/168h/db trial 3 at 1500 ms is refused', 1 === $code && false !== strpos( $last, 'partition legacy/168h/db trial 3 took 1500 ms' ) );
$liar             = $mut( $sample_r, 'hpos', 48, 'file', 1, 1700 );
$liar['all_pass'] = true;
foreach ( $liar['partitions'] as &$p ) {
	$p['pass'] = true;
}
unset( $p );
list( $code, $last ) = $cli( (string) json_encode( $liar ) );
$is( 'sample: a 1700 ms trial is refused although all_pass and every partition pass say true', 1 === $code && false !== strpos( $last, 'partition hpos/48h/file trial 1 took 1700 ms' ) );
list( $code, $last ) = $cli( substr( $sample_json, 0, 400 ) );
$is( 'sample: its first 400 bytes are refused (exit 2)', 2 === $code );
list( $code, $last ) = $cli( $sample_json, array( '--candidate-sha256=' . str_repeat( 'ef', 32 ), '--source-sha=' . $src, '--attempt=' . $att ) );
$is( 'sample: another candidate is refused', 1 === $code );
list( $code, $last ) = $cli( $sample_json, array( '--candidate-sha256=' . $cand, '--source-sha=0000000000000000000000000000000000000000', '--attempt=' . $att ) );
$is( 'sample: source 0000... is refused', 1 === $code && false !== strpos( $last, 'is not the source 0000000000000000000000000000000000000000' ) );
list( $code, $last ) = $cli( $sample_json, array( '--candidate-sha256=' . $cand, '--source-sha=' . $src, '--attempt=another-attempt' ) );
$is( 'sample: another attempt is refused', 1 === $code && false !== strpos( $last, 'is not this attempt another-attempt' ) );

// No relaxed rule anywhere in the card gate (Q6 is the payment workload's alone).
$no_relaxed = '/4of5|hard_?max|max\s*<=|<=\s*2000|2000\s*ms|median_ms|retry_until|BLZMCP_CARD_PERF_RELAX/i';
foreach ( array( 'card-testing-perf-validate.php', 'card-testing-perf-trial.php', 'card-testing-perf-suite.sh', 'card-testing-perf-sample.php', 'measure-card-testing.php' ) as $f ) {
	$is( "guard: $f carries no relaxed rule, median or retry", 0 === preg_match( $no_relaxed, (string) file_get_contents( __DIR__ . '/' . $f ) ) );
}
$src_v = (string) file_get_contents( $validator );
$is( 'guard: the validator decides strictly below 1500 ms on the raw trials', false !== strpos( $src_v, "define( 'BLZMCP_CARD_RECEIPT_BUDGET', 1500 )" ) && false !== strpos( $src_v, '$el >= BLZMCP_CARD_RECEIPT_BUDGET' ) && false === strpos( $src_v, "['pass']" ) && false === strpos( $src_v, "['max_ms']" ) && false === strpos( $src_v, "['all_pass']" ) );

echo "$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
