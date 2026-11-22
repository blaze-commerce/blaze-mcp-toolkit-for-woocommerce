<?php
/**
 * Self-test for the performance trial validity gate (FEATURE-PLAN item 10, REVIEW-pass4 #10):
 * the fixture expectation arithmetic and blzmcp_perf_validate(). Plain PHP, no WordPress:
 *
 *   php tests/matrix/test-perf-validate.php
 *
 * Forced order-reader, log-reader and job-reader failures must each fail the trial; documented
 * bounded truncation (log row/byte/file caps, sampled jobs where the exact store is not required)
 * must not.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

// The public `reason` is the plugin's own text (PaymentHealth::reason_text()), so the validator
// reads its accepted bound texts from the plugin. Load the plugin classes without WordPress: the
// only WordPress call reason_text() makes is __(), stubbed here to return the English source.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once __DIR__ . '/../../vendor/autoload.php';
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WordPress signature.
		return $text;
	}
}
use Blaze\McpToolkit\Abilities\PaymentHealth;

require __DIR__ . '/payment-volume-cycle.php';
require __DIR__ . '/perf-validate.php';

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

// ---------------------------------------------------------------- expectation arithmetic.

$seed = 1794700000;
$full = blzmcp_perf_expect_orders( $seed, 50000, 10000, $seed, 24 );
$is( 'full fixture at the seed time: 40,000 accepted orders store-wide', 40000 === $full['store_wide']['taken_28d'] );
$is(
	'full fixture: stripe 22,500, cod 10,000, cheque 7,500 accepted in 28 days',
	22500 === $full['methods']['stripe']['taken_28d'] && 10000 === $full['methods']['cod']['taken_28d'] && 7500 === $full['methods']['cheque']['taken_28d']
);
$is( 'older orders (30-400 days) never count in the 28-day history', blzmcp_perf_expect_orders( $seed, 50000, 0, $seed, 24 ) === $full );
$later = blzmcp_perf_expect_orders( $seed, 50000, 10000, $seed + 3600, 24 );
$is( 'an hour later the oldest recent orders have left the history', $later['store_wide']['taken_28d'] < 40000 && $later['store_wide']['taken_28d'] > 39900 );

// 40 orders over 28 days: 60,477 s apart. At since_hours=168 the mature cohort is i=1..9.
$small = blzmcp_perf_expect_orders( $seed, 40, 0, $seed, 168 );
$is(
	'small fixture: stripe cohort taken 4, pending 1, cancelled 1, no reference',
	array( 4, 1, 1, 0 ) === array( $small['methods']['stripe']['taken'], $small['methods']['stripe']['pending'], $small['methods']['stripe']['cancelled_unpaid'], $small['methods']['stripe']['transaction_recorded_unpaid'] )
);
$is( 'small fixture: cod cohort taken 2 (processing paid, on-hold), cheque 1', 2 === $small['methods']['cod']['taken'] && 1 === $small['methods']['cheque']['taken'] );
$is( 'small fixture: the first order (60 s old) is not mature, so not in the cohort', 0 === blzmcp_perf_expect_orders( $seed, 40, 0, $seed, 1 )['methods']['stripe']['taken'] );
$is( 'small fixture: no failed order inside 168 hours (i=14 is 9.8 days old)', 0 === $small['methods']['cod']['failed'] );
// 20 orders, 120,954 s apart, evaluated 1,272,462 s before the seed time: the cohort is i=11..15.
$mid = blzmcp_perf_expect_orders( $seed, 20, 0, $seed - 1272462, 168 );
$is(
	'pending with a reference (i=13) is transaction_recorded_unpaid; stripe taken 2',
	array( 2, 1, 0, 1 ) === array( $mid['methods']['stripe']['taken'], $mid['methods']['stripe']['pending'], $mid['methods']['stripe']['cancelled_unpaid'], $mid['methods']['stripe']['transaction_recorded_unpaid'] )
);
$is( 'the failed cod order (i=14) is counted in the full window, not in the cohort', 1 === $mid['methods']['cod']['failed'] && 0 === $mid['methods']['cod']['taken'] );
$is( 'cheque cohort taken 1 (i=12, completed paid)', 1 === $mid['methods']['cheque']['taken'] );

// ---------------------------------------------------------------- validator.

/**
 * A faithful response for the given expectation (what a fully successful call reports).
 *
 * @param array<string,mixed> $orders Expected order counts.
 * @param int                 $logs   Stripe log count.
 * @param int                 $jobs   Stripe failed jobs.
 * @param array<string,mixed> $outside Expected excluded-origin IDs (blzmcp_perf_expect_outside()).
 */
function blzmcp_test_response( array $orders, int $logs, int $jobs, array $outside = array() ): array {
	$check   = static fn( string $coverage, ?string $reason = null, bool $applicable = true ) => array(
		'status'     => 'amber',
		'checked'    => 'unavailable' !== $coverage,
		'applicable' => $applicable,
		'coverage'   => $coverage,
		'reason'     => $reason,
	);
	$methods = array();
	foreach ( $orders['methods'] as $id => $c ) {
		$manual    = 'stripe' !== $id;
		$methods[] = array(
			'id'         => $id,
			'title'      => 'Payment method',
			'plugin'     => null,
			'registered' => null,
			'enabled'    => null,
			'test_mode'  => null,
			'kind'       => $manual ? 'manual' : 'instant',
			'status'     => 'amber',
			'assessment' => 'checkout_health',
			'activity'   => array(
				'checkout_observed'         => true,
				'outside_checkout_observed' => in_array( $id, (array) ( $outside['mixed'] ?? array() ), true ),
				'coverage'                  => 'complete',
				'reason'                    => null,
			),
			'reasons'    => array(),
			'quiet'      => $check( 'not_applicable', 'method_not_registered', false ) + array( 'taken_28d' => $c['taken_28d'] ),
			'stuck'      => $check( $manual ? 'not_applicable' : 'complete' ) + array_diff_key( $c, array( 'taken_28d' => 0 ) ),
			'errors'     => array(
				'status'  => 'amber',
				'logs'    => $manual ? $check( 'not_applicable', 'manual_method', false ) + array( 'count' => null, 'count_is_lower_bound' => false ) : $check( 'complete' ) + array( 'count' => $logs, 'count_is_lower_bound' => false ),
				'jobs'    => $manual ? $check( 'not_applicable', 'manual_method', false ) + array( 'failed' => null, 'count_is_lower_bound' => false ) : $check( 'complete' ) + array( 'failed' => $jobs, 'count_is_lower_bound' => false ),
				'webhook' => $check( 'not_applicable', 'no_supported_record', false ),
			),
		);
	}
	$un = array(
		'status'     => 'unknown',
		'checked'    => false,
		'applicable' => false,
		'coverage'   => 'not_applicable',
		'reason'     => 'Observed only outside verified checkout activity; checkout health not assessed.',
	);
	foreach ( (array) ( $outside['inventory_only'] ?? array() ) as $id ) {
		$methods[] = array(
			'id'         => $id,
			'title'      => 'Payment method',
			'plugin'     => null,
			'registered' => null,
			'enabled'    => null,
			'test_mode'  => null,
			'kind'       => 'unknown',
			'status'     => 'unknown',
			'assessment' => 'inventory_only',
			'activity'   => array(
				'checkout_observed'         => false,
				'outside_checkout_observed' => true,
				'coverage'                  => 'complete',
				'reason'                    => null,
			),
			'reasons'    => array( $un['reason'] ),
			'quiet'      => $un + array( 'taken_28d' => null ),
			'stuck'      => $un + array( 'examples' => array() ),
			'errors'     => array(
				'status'  => 'unknown',
				'logs'    => $un + array( 'count' => null ),
				'jobs'    => $un + array( 'failed' => null ),
				'webhook' => $un,
			),
		);
	}
	return array(
		'schema_version' => 2,
		'inventory'      => array(
			'checked'           => true,
			'coverage'          => 'partial',
			'reason'            => 'registry not loaded',
			'methods_truncated' => false,
		),
		'findings'       => array(),
		'checked_at'     => '2026-11-15T10:00:00+11:00',
		'overall'        => 'amber',
		'coverage'       => 'partial',
		'store_wide'     => $check( 'complete' ) + array( 'taken_28d' => $orders['store_wide']['taken_28d'] ),
		'methods'        => $methods,
	);
}

$expect = array(
	'orders'  => blzmcp_perf_expect_orders( $seed, 50000, 10000, $seed + 600, 24 ),
	'logs'    => array( 'stripe' => 150 ),
	'jobs'    => array( 'stripe' => 40 ),
	'outside' => blzmcp_perf_expect_outside( 1000 ),
);
$good   = blzmcp_test_response( $expect['orders'], 150, 40, $expect['outside'] );
$exact  = array( 'require_exact_jobs' => true );
$stripe = static fn( array $r ): int => (int) array_search( 'stripe', array_column( $r['methods'], 'id' ), true );

$v = blzmcp_perf_validate( $good, $expect, $exact );
$is( 'a faithful trial with every reader at its expected counts is valid', true === $v['ok'] && array() === $v['failures'] );

// Forced order-reader failure: the order source could not be read.
$r                           = $good;
$r['store_wide']['checked']  = false;
$r['store_wide']['coverage'] = 'unavailable';
$r['store_wide']['reason']   = 'orders_unavailable';
$r['store_wide']['taken_28d'] = null;
foreach ( $r['methods'] as &$row ) {
	$row['quiet']['taken_28d'] = null;
	foreach ( array( 'pending', 'cancelled_unpaid', 'taken', 'transaction_recorded_unpaid', 'failed' ) as $k ) {
		$row['stuck'][ $k ] = null;
	}
}
unset( $row );
$v = blzmcp_perf_validate( $r, $expect, $exact );
// REVIEW-pass4 #10 counterexample: the 61170fd rule (coverage complete or partial) accepted this.
$is( 'counterexample: the old success rule accepted an order-reader failure', in_array( $r['coverage'], array( 'complete', 'partial' ), true ) );
$is( 'forced order-reader failure (readable diagnostics) fails the trial', false === $v['ok'] && false === $v['readers']['orders'] && true === $v['readers']['logs'] && true === $v['readers']['jobs'] );

// Forced log-reader failure: orders readable, the log source failed.
$r = $good;
$r['methods'][ $stripe( $r ) ]['errors']['logs'] = array(
	'status'               => 'unknown',
	'checked'              => false,
	'applicable'           => true,
	'coverage'             => 'unavailable',
	'reason'               => 'log_directory_unavailable',
	'count'                => null,
	'count_is_lower_bound' => false,
);
$v = blzmcp_perf_validate( $r, $expect, $exact );
$is( 'forced log-reader failure (readable orders) fails the trial', false === $v['ok'] && false === $v['readers']['logs'] && true === $v['readers']['orders'] );

// Forced job-reader failure.
$r = $good;
$r['methods'][ $stripe( $r ) ]['errors']['jobs'] = array(
	'status'               => 'unknown',
	'checked'              => false,
	'applicable'           => true,
	'coverage'             => 'unavailable',
	'reason'               => 'jobs_unavailable',
	'failed'               => null,
	'count_is_lower_bound' => false,
);
$v = blzmcp_perf_validate( $r, $expect, $exact );
$is( 'forced job-reader failure (readable orders and logs) fails the trial', false === $v['ok'] && false === $v['readers']['jobs'] && true === $v['readers']['orders'] && true === $v['readers']['logs'] );

$v = blzmcp_perf_validate( null, $expect, $exact );
$is( 'an error result (no response) fails every reader', false === $v['ok'] && array( false ) === array_values( array_unique( $v['readers'] ) ) );

$r             = $good;
$r['coverage'] = 'unavailable';
$is( 'response coverage unavailable fails the trial', false === blzmcp_perf_validate( $r, $expect, $exact )['ok'] );

// Workload not observed: a fast empty store is not a valid trial.
$empty = blzmcp_perf_expect_orders( $seed, 0, 0, $seed, 24 );
$v     = blzmcp_perf_validate( blzmcp_test_response( $empty, 150, 40, $expect['outside'] ), array( 'orders' => $empty ) + $expect, $exact );
$is( 'a fixture with no seeded orders fails (workload not observed)', false === $v['ok'] && false === $v['readers']['orders'] );
$is( 'no seeded log errors in the window fails', false === blzmcp_perf_validate( $good, array( 'logs' => array( 'stripe' => 0 ) ) + $expect, $exact )['readers']['logs'] );
$is( 'no seeded failed jobs in the window fails', false === blzmcp_perf_validate( $good, array( 'jobs' => array( 'stripe' => 0 ) ) + $expect, $exact )['readers']['jobs'] );

// Counts must be the fixture's, exactly.
$r = $good;
++$r['methods'][ $stripe( $r ) ]['stuck']['pending'];
$is( 'one pending order too many fails the order reader', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['orders'] );
$r = $good;
--$r['store_wide']['taken_28d'];
$is( 'store-wide taken_28d one short fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['orders'] );
$r                           = $good;
$r['store_wide']['coverage'] = 'partial';
$is( 'store-wide order coverage partial fails (no documented order bound)', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['orders'] );
$r = $good;
$r['methods'][ $stripe( $r ) ]['errors']['logs']['count'] = 149;
$is( 'a complete log count that is not the observed count fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['logs'] );
$r = $good;
$r['methods'][ $stripe( $r ) ]['errors']['jobs']['failed'] = 0;
$is( 'an exact job count of zero against 40 seeded failures fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['jobs'] );

// Inventory.
$r = $good;
array_splice( $r['methods'], $stripe( $r ), 1 );
$is( 'a seeded method missing from the inventory fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['inventory'] );
$r = $good;
foreach ( $r['methods'] as &$row ) {
	if ( 'cod' === $row['id'] ) {
		$row['kind'] = 'instant';
	}
}
unset( $row );
$is( 'cod reported as instant fails the inventory', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['inventory'] );

// Documented bounded truncation is allowed; anything else is not. The public `reason` is the
// translated text, never the code (candidate matrix 26df1fc: all 20 DB-logger trials were
// rejected because the validator compared this text with the code list).
$txt    = static fn( string $code ): string => (string) PaymentHealth::reason_text( $code );
$bounds = blzmcp_perf_bound_texts();
$log_codes = array_values( $bounds['logs'] );
sort( $log_codes );
$is( 'bound texts come from the plugin: the log row, byte and file caps, each its own text', array( 'byte_cap', 'file_cap', 'log_row_cap' ) === $log_codes && $txt( 'log_row_cap' ) === array_search( 'log_row_cap', $bounds['logs'], true ) );
$is( 'the orders row_cap text is not a log bound', ! isset( $bounds['logs'][ $txt( 'row_cap' ) ] ) );
$is( 'sampled_store text is the only job bound', array( $txt( 'sampled_store' ) ) === array_keys( $bounds['jobs'] ) );

$db = array( 'logs' => array( 'stripe' => 2001 ) ) + $expect;
$r  = $good;
$r['methods'][ $stripe( $r ) ]['errors']['logs'] = array( 'checked' => true, 'applicable' => true, 'coverage' => 'partial', 'reason' => $txt( 'log_row_cap' ), 'count' => 2000, 'count_is_lower_bound' => true );
$v = blzmcp_perf_validate( $r, $db, $exact );
$is( 'DB logger row cap with the real public text (2,000 of 2,001 observed, lower bound) is a valid bound', true === $v['ok'] && array() === $v['failures'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'row_cap' );
$is( 'a log partial carrying the orders reader text ("Orders could only be read in part") fails', false === blzmcp_perf_validate( $r, $db, $exact )['readers']['logs'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = 'log_row_cap';
$is( 'a bare reason code (not the public text) is not accepted', false === blzmcp_perf_validate( $r, $db, $exact )['readers']['logs'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'log_row_cap' );
$is( 'with no bound texts loaded (plugin absent) a bounded partial fails closed', false === blzmcp_perf_validate( $r, $db, $exact + array( 'bound_texts' => array( 'logs' => array(), 'jobs' => array() ) ) )['readers']['logs'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'file_cap' );
$is( 'file cap (public text) is a valid bound', true === blzmcp_perf_validate( $r, $db, $exact )['ok'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'byte_cap' );
$is( 'byte cap (public text) is a valid bound', true === blzmcp_perf_validate( $r, $db, $exact )['ok'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['count_is_lower_bound'] = false;
$is( 'a capped count not marked as a lower bound fails', false === blzmcp_perf_validate( $r, $db, $exact )['ok'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['count_is_lower_bound'] = true;
$r['methods'][ $stripe( $r ) ]['errors']['logs']['count']                = 2002;
$is( 'a capped count above the seeded errors fails', false === blzmcp_perf_validate( $r, $db, $exact )['ok'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['count']  = 2000;
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'unparsed_entries' );
$is( 'partial logs for an undocumented reason (unparsed entries) fail', false === blzmcp_perf_validate( $r, $db, $exact )['readers']['logs'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'stale_retention' );
$is( 'partial logs for stale retention fail', false === blzmcp_perf_validate( $r, $db, $exact )['readers']['logs'] );
$r['methods'][ $stripe( $r ) ]['errors']['logs']['reason'] = $txt( 'blzmcp_unlisted_code' );
$is( 'partial logs with the generic fallback text fail', false === blzmcp_perf_validate( $r, $db, $exact )['readers']['logs'] );

// An orders-reader failure is still rejected while the log read is a valid bound.
$r = $good;
$r['methods'][ $stripe( $r ) ]['errors']['logs'] = array( 'checked' => true, 'applicable' => true, 'coverage' => 'partial', 'reason' => $txt( 'log_row_cap' ), 'count' => 2000, 'count_is_lower_bound' => true );
$r['store_wide']['coverage']                     = 'partial';
$r['store_wide']['reason']                       = $txt( 'row_cap' );
$v = blzmcp_perf_validate( $r, $db, $exact );
$is( 'orders row cap (public text) fails the order reader; the bounded log read still passes', false === $v['ok'] && false === $v['readers']['orders'] && true === $v['readers']['logs'] );

$r = $good;
$r['methods'][ $stripe( $r ) ]['errors']['jobs'] = array( 'checked' => true, 'applicable' => true, 'coverage' => 'partial', 'reason' => $txt( 'sampled_store' ), 'failed' => 40, 'count_is_lower_bound' => true );
$is( 'sampled jobs fail where the exact database store is required (matrix)', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['jobs'] );
$is( 'sampled jobs (public text) are a valid lower bound where the exact store is not required (local)', true === blzmcp_perf_validate( $r, $expect, array( 'require_exact_jobs' => false ) )['ok'] );
$r['methods'][ $stripe( $r ) ]['errors']['jobs']['reason'] = 'sampled_store';
$is( 'sampled jobs with a bare code (not the public text) fail', false === blzmcp_perf_validate( $r, $expect, array( 'require_exact_jobs' => false ) )['readers']['jobs'] );
$r['methods'][ $stripe( $r ) ]['errors']['jobs']['reason'] = $txt( 'jobs_unavailable' );
$is( 'partial jobs for another reason fail', false === blzmcp_perf_validate( $r, $expect, array( 'require_exact_jobs' => false ) )['readers']['jobs'] );
$r['methods'][ $stripe( $r ) ]['errors']['jobs']['reason'] = $txt( 'sampled_store' );
$r['methods'][ $stripe( $r ) ]['errors']['jobs']['failed'] = 41;
$is( 'a sampled count above the seeded failures fails', false === blzmcp_perf_validate( $r, $expect, array() )['ok'] );

// ---------------------------------------------------------------- 1.2.1: outside verified checkout activity.

$out = $expect['outside'];
$is( 'excluded-origin expectation: 10 methods, 8 inventory-only (bacs among them), cod and stripe mixed', 8 === count( $out['inventory_only'] ) && in_array( 'bacs', $out['inventory_only'], true ) && array( 'cod', 'stripe' ) === $out['mixed'] && 1000 === $out['orders'] );
$is( 'excluded-origin orders stay inside the history for the run (1 hour to 27 days old)', blzmcp_perf_outside_created( 0, $seed, 1000 ) === $seed - 3600 && blzmcp_perf_outside_created( 999, $seed, 1000 ) > $seed - 27 * 86400 );
$is( 'the checkout expectation never counts the excluded-origin orders', blzmcp_perf_expect_orders( $seed, 50000, 10000, $seed + 600, 24 ) === $expect['orders'] );
$is( 'a faithful trial passes the outside reader', true === blzmcp_perf_validate( $good, $expect, $exact )['readers']['outside'] );
$byid = static fn( array $r, string $id ): int => (int) array_search( $id, array_column( $r['methods'], 'id' ), true );
$r    = $good;
array_splice( $r['methods'], $byid( $r, 'bacs' ), 1 );
$is( 'an inventory-only method missing from the response fails the outside reader', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r = $good;
$r['methods'][ $byid( $r, 'blzmcp_ext_phone' ) ]['assessment'] = 'checkout_health';
$is( 'an inventory-only method assessed as checkout_health fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r = $good;
$r['methods'][ $byid( $r, 'blzmcp_ext_none' ) ]['quiet']['taken_28d'] = 0;
$is( 'an inventory-only method with a synthetic zero count fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r = $good;
$r['methods'][ $byid( $r, 'blzmcp_ext_pos' ) ]['errors']['logs']['checked'] = true;
$is( 'an inventory-only method whose log check was read fails (diagnostics must not run)', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r               = $good;
$r['findings'][] = array( 'code' => 'method_disabled_with_history', 'method_id' => 'bacs' );
$is( 'a finding for an inventory-only method fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r = $good;
$r['methods'][ $byid( $r, 'cod' ) ]['activity']['outside_checkout_observed'] = false;
$is( 'a mixed method whose outside observation was lost fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r = $good;
$r['methods'][ $byid( $r, 'stripe' ) ]['assessment'] = 'inventory_only';
$is( 'a mixed method demoted to inventory_only fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$r                                   = $good;
$r['inventory']['methods_truncated'] = true;
$is( 'a truncated inventory in the normal fixture fails', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['outside'] );
$is( 'no excluded-origin population expected fails (workload not seeded)', false === blzmcp_perf_validate( $good, array( 'outside' => blzmcp_perf_expect_outside( 0 ) ) + $expect, $exact )['readers']['outside'] );
$is( 'fewer than 10 excluded-origin methods fails', false === blzmcp_perf_validate( $good, array( 'outside' => blzmcp_perf_expect_outside( 9 ) ) + $expect, $exact )['readers']['outside'] );
$r = $good;
$r['methods'][ $byid( $r, 'cod' ) ]['quiet']['taken_28d'] += 100;
$is( 'outside orders leaking into a mixed method\'s checkout count fail the order reader', false === blzmcp_perf_validate( $r, $expect, $exact )['readers']['orders'] );

// ---------------------------------------------------------------- 1.2.1: the method cap (C4).

/**
 * A faithful method-cap result for $k distinct outside IDs (cap 1,000).
 *
 * @param int $k Distinct IDs seeded.
 */
function blzmcp_test_cap( int $k ): array {
	$cap_txt = (string) PaymentHealth::reason_text( 'outside_checkout_method_cap' );
	$methods = array(
		array(
			'id'         => 'cod',
			'assessment' => 'checkout_health',
			'status'     => 'green',
			'activity'   => array( 'checkout_observed' => true, 'outside_checkout_observed' => false ),
			'quiet'      => array( 'taken_28d' => 24 ),
		),
	);
	for ( $n = 0; $n < min( $k, 1000 ); $n++ ) {
		$methods[] = array(
			'id'         => blzmcp_perf_cap_id( $n ),
			'assessment' => 'inventory_only',
			'status'     => 'unknown',
			'activity'   => array( 'checkout_observed' => false, 'outside_checkout_observed' => true ),
			'quiet'      => array( 'taken_28d' => null ),
		);
	}
	usort( $methods, static fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );
	return array(
		'coverage'   => 'partial',
		'store_wide' => array( 'taken_28d' => 24, 'coverage' => 'complete' ),
		'inventory'  => array(
			'checked'           => true,
			'coverage'          => $k > 1000 ? 'partial' : 'complete',
			'reason'            => $k > 1000 ? $cap_txt : null,
			'methods_truncated' => $k > 1000,
		),
		'findings'   => array(),
		'methods'    => $methods,
	);
}
$ctl = array( 'method' => 'cod', 'taken_28d' => 24 );
$is( 'cap: 1,000 distinct IDs, all listed, not truncated, is valid', true === blzmcp_perf_validate_cap( blzmcp_test_cap( 1000 ), 1000, $ctl )['ok'] );
$is( 'cap: 1,001 distinct IDs, 1,000 listed, truncated with the cap reason, is valid', true === blzmcp_perf_validate_cap( blzmcp_test_cap( 1001 ), 1001, $ctl )['ok'] );
$r                                   = blzmcp_test_cap( 1001 );
$r['inventory']['methods_truncated'] = false;
$is( 'cap: 1,001 IDs without methods_truncated fails (silent cap)', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r                        = blzmcp_test_cap( 1001 );
$r['inventory']['reason'] = null;
$is( 'cap: 1,001 IDs without the cap reason fails', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r                          = blzmcp_test_cap( 1001 );
$r['inventory']['coverage'] = 'complete';
$is( 'cap: 1,001 IDs claiming a complete inventory fails', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r = blzmcp_test_cap( 1000 );
$r['inventory']['methods_truncated'] = true;
$is( 'cap: 1,000 IDs reported as truncated fails (the cap is 1,000, not 999)', false === blzmcp_perf_validate_cap( $r, 1000, $ctl )['ok'] );
$r = blzmcp_test_cap( 1000 );
array_pop( $r['methods'] );
$is( 'cap: 1,000 IDs with one missing fails', false === blzmcp_perf_validate_cap( $r, 1000, $ctl )['ok'] );
$r            = blzmcp_test_cap( 1001 );
$r['methods'][] = array( 'id' => blzmcp_perf_cap_id( 1000 ), 'assessment' => 'inventory_only', 'status' => 'unknown', 'activity' => array( 'outside_checkout_observed' => true ) );
$is( 'cap: 1,001 IDs all listed (no cap, and out of order) fails', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r = blzmcp_test_cap( 1001 );
$r['methods'] = array_reverse( $r['methods'] );
$is( 'cap: rows out of ID order fail', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r                             = blzmcp_test_cap( 1001 );
$r['store_wide']['taken_28d']  = 23;
$is( 'cap: a changed checkout control (store-wide) fails', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r = blzmcp_test_cap( 1001 );
foreach ( $r['methods'] as &$row ) {
	if ( 'cod' === $row['id'] ) {
		$row['quiet']['taken_28d'] = 25;
	}
}
unset( $row );
$is( 'cap: a changed checkout control (cod) fails', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r               = blzmcp_test_cap( 1001 );
$r['findings'][] = array( 'code' => 'method_not_registered', 'method_id' => blzmcp_perf_cap_id( 3 ) );
$is( 'cap: a finding for a cap ID fails', false === blzmcp_perf_validate_cap( $r, 1001, $ctl )['ok'] );
$r             = blzmcp_test_cap( 1000 );
$r['coverage'] = 'complete';
$is( 'cap: a complete top-level coverage with unassessed rows fails', false === blzmcp_perf_validate_cap( $r, 1000, $ctl )['ok'] );
$r                    = blzmcp_test_cap( 1000 );
$r['methods'][1]['id'] = 'bad id!';
$is( 'cap: an unsafe ID in output fails', false === blzmcp_perf_validate_cap( $r, 1000, $ctl )['ok'] );
$is( 'cap: no response fails', false === blzmcp_perf_validate_cap( null, 1000, $ctl )['ok'] );

echo "$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
