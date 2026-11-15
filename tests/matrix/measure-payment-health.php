<?php
/**
 * One uncached payment-health measurement in this fresh PHP request (FEATURE-PLAN item 10).
 *
 *   wp eval-file .../measure-payment-health.php <since_hours> <seed_now> <recent> <old> <exact_jobs> [force]
 *
 * Deletes only the toolkit's own result cache entries (blzmcp_c_ transients, one by one; no
 * whole-cache flush), then times one end-to-end ability execute() as an administrator: permission,
 * cache context, the readers, response validation and the cache and audit writes. Then times one
 * warm (cached) call.
 *
 * A timing counts only when the call observed the seeded workload and every reader reported its
 * expected success and counts (tests/matrix/perf-validate.php, REVIEW-pass4 #10): the order counts
 * the volume fixture must give at the ability's own now (payment-volume-cycle.php, from
 * checked_at), the gateway log errors and failed jobs present in the window (counted here, after
 * the timed call, straight from the fixture's sources), and the inventory rows. Only documented
 * bounded truncation passes as partial. <exact_jobs>=1 (the matrix) also requires the exact
 * Action Scheduler database-store path for jobs. A call that returns an error, or any failed
 * reader, is reported as ok:false, never as a fast success.
 *
 * [force] = orders|logs|jobs breaks that one reader for the timed call only (its source query
 * points at a missing table, or the log directory at a missing path) to prove the gate fails:
 * the line then carries forced and detected (true when the validator flagged that reader).
 *
 * Prints one line: MEASURE=<json> with ok, validation, expected, elapsed_ms, warm_ms, queries, the
 * slowest queries with their EXPLAIN rows, Handler_read deltas (rows examined), peak memory delta,
 * the Action Scheduler store class and versions.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/payment-volume-cycle.php';
require_once __DIR__ . '/perf-validate.php';

global $wpdb;
$blzmcp_args  = array_values( (array) ( $args ?? array() ) );
$hours        = (int) ( $blzmcp_args[0] ?? 24 );
$seed_now     = (int) ( $blzmcp_args[1] ?? 0 );
$seed_recent  = (int) ( $blzmcp_args[2] ?? 0 );
$seed_old     = (int) ( $blzmcp_args[3] ?? 0 );
$exact_jobs   = '1' === (string) ( $blzmcp_args[4] ?? '0' );
$force        = (string) ( $blzmcp_args[5] ?? '' );
if ( '' !== $force && ! in_array( $force, array( 'orders', 'logs', 'jobs' ), true ) ) {
	echo 'MEASURE=' . wp_json_encode( array( 'ok' => false, 'error' => 'unknown force mode' ) ) . "\n";
	return;
}
add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_measure_blocked', 'no HTTP while measuring' ), 1 );
wp_set_current_user( 1 );

$blzmcp_names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%' ) );
foreach ( $blzmcp_names as $name ) {
	delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
}
$ability = wp_get_ability( 'blaze-mcp-toolkit/payment-health' );
if ( ! $ability instanceof WP_Ability ) {
	echo 'MEASURE=' . wp_json_encode( array( 'ok' => false, 'error' => 'ability not registered' ) ) . "\n";
	return;
}
$action_store = class_exists( 'ActionScheduler' ) ? get_class( ActionScheduler::store() ) : null;

$blzmcp_handler_status = static function () use ( $wpdb ): array {
	$rows = $wpdb->get_results( "SHOW SESSION STATUS LIKE 'Handler_read%'", ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['Variable_name'] ] = (int) $r['Value'];
	}
	return $out;
};

// Forced reader failure (test-only): the one reader's source becomes unreadable for the timed call.
$blzmcp_force_query = static function ( $sql ) use ( $wpdb, $force ) {
	$s       = (string) $sql;
	$p       = preg_quote( $wpdb->prefix, '/' );
	$matches = array(
		'orders' => preg_match( '/\b' . $p . 'wc_orders\b/', $s ) || ( preg_match( '/\b' . preg_quote( $wpdb->posts, '/' ) . '\b/', $s ) && false !== strpos( $s, 'shop_order' ) ),
		'logs'   => (bool) preg_match( '/\b' . $p . 'woocommerce_log\b/', $s ),
		'jobs'   => (bool) preg_match( '/\b' . $p . 'actionscheduler_actions\b/', $s ),
	);
	return ! empty( $matches[ $force ] ) && preg_match( '/^\s*SELECT/i', $s ) ? 'SELECT * FROM ' . $wpdb->prefix . 'blzmcp_forced_missing_table' : $sql;
};
$blzmcp_force_dir   = static fn() => '/nonexistent-blzmcp-forced-log-dir/';
$suppressed         = null;
if ( '' !== $force ) {
	add_filter( 'query', $blzmcp_force_query, PHP_INT_MAX );
	if ( 'logs' === $force ) {
		add_filter( 'woocommerce_log_directory', $blzmcp_force_dir, PHP_INT_MAX );
	}
	$suppressed = $wpdb->suppress_errors( true );
}

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}
$wpdb->queries = array();
$before_status = $blzmcp_handler_status();
$q0            = $wpdb->num_queries;
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$mem0      = memory_get_usage();
$before_ts = time();
$t0        = hrtime( true );
$result    = $ability->execute( array( 'since_hours' => $hours ) );
$ms        = ( hrtime( true ) - $t0 ) / 1e6;
$after_ts  = time();
$peak      = memory_get_peak_usage() - $mem0;
$q1        = $wpdb->num_queries;
$saved     = $wpdb->queries;
$after_status = $blzmcp_handler_status();

$t1   = hrtime( true );
$warm = $ability->execute( array( 'since_hours' => $hours ) );
$wms  = ( hrtime( true ) - $t1 ) / 1e6;

if ( '' !== $force ) {
	remove_filter( 'query', $blzmcp_force_query, PHP_INT_MAX );
	remove_filter( 'woocommerce_log_directory', $blzmcp_force_dir, PHP_INT_MAX );
	$wpdb->suppress_errors( (bool) $suppressed );
}

usort( $saved, static fn( $a, $b ) => $b[1] <=> $a[1] );
$slow = array();
foreach ( array_slice( $saved, 0, 5 ) as $q ) {
	$sql   = (string) $q[0];
	$plan  = null;
	if ( '' === $force && preg_match( '/^\s*SELECT/i', $sql ) ) {
		$plan = array_map(
			static fn( $r ) => array(
				'table' => $r['table'] ?? null,
				'type'  => $r['type'] ?? null,
				'key'   => $r['key'] ?? null,
				'rows'  => isset( $r['rows'] ) ? (int) $r['rows'] : null,
				'extra' => $r['Extra'] ?? null,
			),
			(array) $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A )
		);
	}
	// Query shape only: table prefix kept, literal values stripped.
	$shape  = preg_replace( array( "/'[^']*'/", '/\b\d+\b/' ), array( "'?'", '?' ), $sql );
	$slow[] = array(
		'ms'    => round( (float) $q[1] * 1000, 1 ),
		'sql'   => substr( (string) $shape, 0, 400 ),
		'plan'  => $plan,
	);
}
$deltas = array();
foreach ( $after_status as $k => $v ) {
	$deltas[ $k ] = $v - ( $before_status[ $k ] ?? 0 );
}

// The ability's own now (C4: one captured timestamp, shown as checked_at), bounded by the call.
$now = is_array( $result ) && is_string( $result['checked_at'] ?? null ) ? strtotime( $result['checked_at'] ) : false;
if ( false === $now || $now < $before_ts - 1 || $now > $after_ts + 1 ) {
	$now = $before_ts;
}
$since = $now - $hours * 3600;

// What the fixture's sources hold in the window, counted after the timed call (not part of it).
$handler       = (string) get_option( 'woocommerce_logs_default_handler', '' );
$expected_logs = 0;
if ( false !== strpos( $handler, 'WC_Log_Handler_DB' ) ) {
	$expected_logs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source = %s AND level >= %d AND timestamp >= %s AND timestamp < %s', $wpdb->prefix . 'woocommerce_log', 'woocommerce-gateway-stripe', 500, gmdate( 'Y-m-d H:i:s', $since ), gmdate( 'Y-m-d H:i:s', $now ) ) );
} else {
	$dir = class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' ) ? \Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory( false ) : '';
	foreach ( '' === $dir ? array() : (array) scandir( $dir ) as $name ) {
		if ( ! preg_match( '/^woocommerce-gateway-stripe(\.\d+)?-\d{4}-\d{2}-\d{2}-[A-Za-z0-9]+\.log$/', (string) $name ) ) {
			continue;
		}
		$lines = file( rtrim( $dir, '/' ) . '/' . $name, FILE_IGNORE_NEW_LINES );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( preg_match( '/^(\S+)\s+(ERROR|CRITICAL|ALERT|EMERGENCY)\s/', $line, $hit ) ) {
				$ts = strtotime( $hit[1] );
				if ( false !== $ts && $ts >= $since && $ts < $now ) {
					++$expected_logs;
				}
			}
		}
	}
}
// Failed Stripe-family jobs by last attempt (the reader's window), from the Action Scheduler tables.
$expected_jobs = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE a.status = %s AND a.last_attempt_gmt >= %s AND a.last_attempt_gmt < %s AND ( a.hook IN (%s, %s, %s) OR g.slug = %s )',
		$wpdb->prefix . 'actionscheduler_actions',
		$wpdb->prefix . 'actionscheduler_groups',
		'failed',
		gmdate( 'Y-m-d H:i:s', $since ),
		gmdate( 'Y-m-d H:i:s', $now ),
		'wc_stripe_deferred_webhook',
		'wc_stripe_process_payment_intent_metadata',
		'wc_stripe_process_checkout_session_metadata',
		'woocommerce_stripe'
	)
);

$expect = array(
	'orders' => blzmcp_perf_expect_orders( $seed_now, $seed_recent, $seed_old, (int) $now, $hours ),
	'logs'   => array( 'stripe' => $expected_logs ),
	'jobs'   => array( 'stripe' => $expected_jobs ),
);
$valid  = blzmcp_perf_validate( $result, $expect, array( 'require_exact_jobs' => $exact_jobs ) );
if ( $exact_jobs && 'ActionScheduler_DBStore' !== $action_store ) {
	$valid['ok']              = false;
	$valid['readers']['jobs'] = false;
	$valid['failures'][]      = 'jobs: Action Scheduler store ' . ( $action_store ?? 'none' ) . ', the exact ActionScheduler_DBStore path is required';
}
$ok   = is_array( $result ) && $valid['ok'];
$rows = is_array( $result ) ? array_column( (array) ( $result['methods'] ?? array() ), null, 'id' ) : array();

echo 'MEASURE=' . wp_json_encode(
	array(
		'ok'             => $ok,
		'forced'         => '' === $force ? null : $force,
		'detected'       => '' === $force ? null : ( false === $valid['readers'][ $force ] ),
		'validation'     => array(
			'readers'  => $valid['readers'],
			'failures' => array_slice( $valid['failures'], 0, 12 ),
		),
		'expected'       => array(
			'now'                  => (int) $now,
			'store_wide_taken_28d' => $expect['orders']['store_wide']['taken_28d'],
			'logs'                 => $expect['logs'],
			'jobs'                 => $expect['jobs'],
			'exact_jobs_required'  => $exact_jobs,
		),
		'error'          => is_wp_error( $result ) ? $result->get_error_code() : null,
		'since_hours'    => $hours,
		'elapsed_ms'     => round( $ms, 1 ),
		'warm_ms'        => round( $wms, 1 ),
		'warm_same_checked_at' => is_array( $warm ) && is_array( $result ) && ( $warm['checked_at'] ?? null ) === ( $result['checked_at'] ?? null ),
		'queries'        => $q1 - $q0,
		'handler_read'   => $deltas,
		'peak_memory_delta_bytes' => $peak,
		'action_store'   => $action_store,
		'overall'        => is_array( $result ) ? ( $result['overall'] ?? null ) : null,
		'coverage'       => is_array( $result ) ? ( $result['coverage'] ?? null ) : null,
		'store_wide_taken_28d' => is_array( $result ) ? ( $result['store_wide']['taken_28d'] ?? null ) : null,
		'methods'        => count( $rows ),
		'examples'       => array_sum( array_map( static fn( $m ) => count( (array) ( $m['stuck']['examples'] ?? array() ) ), $rows ) ),
		'log_count'      => array_map( static fn( $m ) => $m['errors']['logs']['count'] ?? null, $rows ),
		'job_failed'     => array_map( static fn( $m ) => $m['errors']['jobs']['failed'] ?? null, $rows ),
		'slowest'        => $slow,
		'versions'       => array(
			'php'   => PHP_VERSION,
			'wp'    => get_bloginfo( 'version' ),
			'wc'    => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'db'    => $wpdb->db_server_info(),
			'cache' => wp_using_ext_object_cache() ? 'external' : 'none',
		),
	)
) . "\n";
