<?php
/**
 * One uncached card-testing measurement in this fresh PHP request (1.3 FEATURE-PLAN item 9, C10).
 * Disposable test environments only.
 *
 *   wp eval-file .../measure-card-testing.php trial <since_hours> <seed_now> <orders> <old> <file|db> [orders|logs]
 *   wp eval-file .../measure-card-testing.php bound <methods|networks> <k>
 *   wp eval-file .../measure-card-testing.php workflow
 *
 * trial: deletes only the toolkit's own result cache entries (blzmcp_c_ transients, one by one; no
 * whole-cache flush), then times one end-to-end ability execute() of blaze-mcp-toolkit/card-testing
 * as an administrator: input validation, permission, the cache context (authority, configuration
 * and protection reads), the bounded order scan and folding, privacy reduction, the rules, the
 * logs, the example hydrations, the protection facts, response validation and the cache and audit
 * writes. Nothing is bypassed or narrowed. Then times one warm (cached) call. After the timed calls
 * (untimed): the reader's own accounting at the call's now (PaymentOrders::read_card_testing():
 * scan completeness, caps, map usage, candidates and metadata rows; counts only, no order data),
 * and the fixture's log sources recounted for the window. The trial is valid only when
 * blzmcp_card_perf_validate_trial() accepts it (card-testing-perf-trial.php); a call that returns
 * an error, or any failed reader, is ok:false, never a fast success.
 *
 * [force] = orders|logs breaks that one reader for the timed call only (its source query points at a
 * missing table, or the log directory at a missing path) to prove the gate fails: the line then
 * carries forced and detected (true when the validator flagged that reader).
 *
 * bound: one uncached 48-hour call on a stress fixture (seed-payment-volume.php card-cap-methods or
 * card-cap-networks), checked by blzmcp_card_perf_validate_bound(). Its timing is recorded, never
 * counted as a performance trial.
 *
 * workflow: the combined cold cost of one weekly review's first steps in workflow order (payment
 * health, card testing, then the order totals step), each as the workflow plans it, reported on
 * its own and never pooled into a budget.
 *
 * Prints one line: MEASURE=<json>, BOUND=<json> or WORKFLOW=<json>. Query text is reported as its
 * shape only (literals stripped); no order, customer, IP or log content is printed.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/payment-volume-cycle.php';
require_once __DIR__ . '/card-testing-perf-trial.php';

global $wpdb;
$blzmcp_args = array_values( (array) ( $args ?? array() ) );
$blzmcp_mode = (string) ( $blzmcp_args[0] ?? '' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_measure_blocked', 'no HTTP while measuring' ), 1 );
wp_set_current_user( 1 );

$blzmcp_clear = static function () use ( $wpdb ): int {
	$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%' ) );
	foreach ( $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
	return count( $names );
};
$blzmcp_status = static function () use ( $wpdb ): array {
	$rows = $wpdb->get_results( "SHOW SESSION STATUS WHERE Variable_name LIKE 'Handler_read%' OR Variable_name IN ('Rows_read', 'Rows_sent')", ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['Variable_name'] ] = (int) $r['Value'];
	}
	return $out;
};
$blzmcp_versions = static fn() => array(
	'php'   => PHP_VERSION,
	'wp'    => get_bloginfo( 'version' ),
	'wc'    => defined( 'WC_VERSION' ) ? WC_VERSION : null,
	'db'    => $wpdb->db_server_info(),
	'cache' => wp_using_ext_object_cache() ? 'external' : 'none',
);
$blzmcp_out = static function ( string $label, array $data ): void {
	echo $label . '=' . wp_json_encode( $data ) . "\n";
};

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

if ( 'bound' === $blzmcp_mode ) {
	$case    = (string) ( $blzmcp_args[1] ?? '' );
	$k       = (int) ( $blzmcp_args[2] ?? 0 );
	$ability = wp_get_ability( 'blaze-mcp-toolkit/card-testing' );
	if ( ! in_array( $case, array( 'methods', 'networks' ), true ) || ! $ability instanceof WP_Ability ) {
		$blzmcp_out(
			'BOUND',
			array(
				'case'     => $case,
				'k'        => $k,
				'ok'       => false,
				'failures' => array( $ability instanceof WP_Ability ? 'unknown bound case' : 'ability not registered' ),
			)
		);
		return;
	}
	$blzmcp_clear();
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$mem0   = memory_get_usage();
	$q0     = $wpdb->num_queries;
	$t0     = hrtime( true );
	$result = $ability->execute( array( 'since_hours' => 48 ) );
	$ms     = ( hrtime( true ) - $t0 ) / 1e6;
	$valid  = blzmcp_card_perf_validate_bound( $result, $case, $k );
	$blzmcp_out(
		'BOUND',
		array(
			'case'                    => $case,
			'k'                       => $k,
			'cap'                     => 'methods' === $case ? BLZMCP_CARD_PERF_MAX_METHODS : BLZMCP_CARD_PERF_MAX_NETWORKS,
			'ok'                      => is_array( $result ) && $valid['ok'],
			'failures'                => array_slice( is_wp_error( $result ) ? array( $result->get_error_code() ) : $valid['failures'], 0, 12 ),
			'observed'                => $valid['observed'],
			'since_hours'             => 48,
			'elapsed_ms'              => round( $ms, 1 ),
			'queries'                 => $wpdb->num_queries - $q0,
			'peak_memory_delta_bytes' => memory_get_peak_usage() - $mem0,
			'counted_as_trial'        => false,
		)
	);
	return;
}

if ( 'workflow' === $blzmcp_mode ) {
	$plan_ability = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' );
	$blzmcp_clear();
	$t0    = hrtime( true );
	$plan  = $plan_ability instanceof WP_Ability ? $plan_ability->execute( array( 'period' => 'last_7_days' ) ) : null;
	$p_ms  = ( hrtime( true ) - $t0 ) / 1e6;
	$want    = array( 'blaze-mcp-toolkit/payment-health', 'blaze-mcp-toolkit/card-testing', 'wc-analytics/totals' );
	$picks   = array();
	$planned = array();
	foreach ( is_array( $plan ) ? (array) ( $plan['steps'] ?? array() ) : array() as $step ) {
		$step = is_object( $step ) ? json_decode( (string) wp_json_encode( $step ), true ) : $step;
		$name = is_array( $step ) ? (string) ( $step['ability'] ?? '' ) : '';
		// The executed plan's arguments arrive as objects (output schema type object).
		$argv = $step['arguments'] ?? null;
		$argv = is_object( $argv ) ? json_decode( (string) wp_json_encode( $argv ), true ) : $argv;
		$argv = is_array( $argv ) ? $argv : array();
		if ( in_array( $name, $want, true ) && ! isset( $picks[ $name ] ) && ( 'wc-analytics/totals' !== $name || 'orders' === ( $argv['subject'] ?? null ) ) ) {
			$picks[ $name ]   = $argv;
			$planned[ $name ] = true;
		}
	}
	// The plan leaves the order totals step out where WooCommerce Analytics is not available (C9).
	// Its cost is still reported, with the arguments the weekly review gives it, marked unplanned.
	if ( ! isset( $picks['wc-analytics/totals'] ) ) {
		$picks['wc-analytics/totals'] = array(
			'subject' => 'orders',
			'period'  => 'last_7_days',
			'compare' => true,
		);
	}
	$steps = array();
	$total = 0.0;
	foreach ( $want as $name ) {
		if ( ! isset( $picks[ $name ] ) ) {
			$steps[] = array(
				'ability' => $name,
				'planned' => false,
				'ok'      => false,
			);
			continue;
		}
		$ability = wp_get_ability( $name );
		$argv    = array_intersect_key( $picks[ $name ], array_flip( array( 'since_hours', 'subject', 'period', 'compare' ) ) );
		if ( ! $ability instanceof WP_Ability ) {
			$steps[] = array(
				'ability'   => $name,
				'planned'   => isset( $planned[ $name ] ),
				'available' => false,
				'arguments' => $argv,
				'ok'        => false,
			);
			continue;
		}
		$blzmcp_clear();
		$q0     = $wpdb->num_queries;
		$t0     = hrtime( true );
		$r      = $ability->execute( $picks[ $name ] );
		$ms     = ( hrtime( true ) - $t0 ) / 1e6;
		$total += $ms;
		$steps[] = array(
			'ability'    => $name,
			'planned'    => isset( $planned[ $name ] ),
			'available'  => true,
			'arguments'  => $argv,
			'elapsed_ms' => round( $ms, 1 ),
			'queries'    => $wpdb->num_queries - $q0,
			'ok'         => ! is_wp_error( $r ) && is_array( $r ),
			'error'      => is_wp_error( $r ) ? $r->get_error_code() : null,
		);
	}
	$timed = array_filter( $steps, static fn( $s ) => isset( $s['elapsed_ms'] ) );
	$blzmcp_out(
		'WORKFLOW',
		array(
			'workflow'      => 'blaze-mcp-toolkit/weekly-store-review',
			'period'        => 'last_7_days',
			'plan_ok'       => is_array( $plan ),
			'plan_ms'       => round( $p_ms, 1 ),
			'steps'         => $steps,
			'total_ms'      => round( $total, 1 ),
			'ok'            => is_array( $plan ) && 3 === count( $timed ) && true === $steps[0]['ok'] && true === $steps[1]['ok'],
			'meaning'       => 'cold sum of the three planned steps in workflow order, each after clearing the toolkit result cache; reported on its own, never pooled into a per-ability budget',
			'pooled_budget' => false,
			'versions'      => $blzmcp_versions(),
		)
	);
	return;
}

if ( 'trial' !== $blzmcp_mode ) {
	$blzmcp_out(
		'MEASURE',
		array(
			'ok'    => false,
			'error' => 'usage: measure-card-testing.php trial|bound|workflow',
		)
	);
	return;
}

$hours    = (int) ( $blzmcp_args[1] ?? 48 );
$seed_now = (int) ( $blzmcp_args[2] ?? 0 );
$orders_n = (int) ( $blzmcp_args[3] ?? 0 );
$old_n    = (int) ( $blzmcp_args[4] ?? 0 );
$logger   = (string) ( $blzmcp_args[5] ?? '' );
$force    = (string) ( $blzmcp_args[6] ?? '' );
if ( ( '' !== $force && ! in_array( $force, array( 'orders', 'logs' ), true ) ) || ! in_array( $logger, array( 'file', 'db' ), true ) || $hours < 1 || $hours > 168 ) {
	$blzmcp_out(
		'MEASURE',
		array(
			'ok'    => false,
			'error' => 'unknown force mode, logger or window',
		)
	);
	return;
}
$ability = wp_get_ability( 'blaze-mcp-toolkit/card-testing' );
if ( ! $ability instanceof WP_Ability ) {
	$blzmcp_out(
		'MEASURE',
		array(
			'ok'    => false,
			'error' => 'ability not registered',
		)
	);
	return;
}
$blzmcp_clear();

// Forced reader failure (test-only): the one reader's source becomes unreadable for the timed call.
$blzmcp_force_query = static function ( $sql ) use ( $wpdb, $force ) {
	$s       = (string) $sql;
	$p       = preg_quote( $wpdb->prefix, '/' );
	$matches = array(
		'orders' => preg_match( '/\b' . $p . 'wc_orders\b/', $s ) || ( preg_match( '/\b' . preg_quote( $wpdb->posts, '/' ) . '\b/', $s ) && false !== strpos( $s, 'shop_order' ) ),
		'logs'   => (bool) preg_match( '/\b' . $p . 'woocommerce_log\b/', $s ),
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

$wpdb->queries = array();
$before_status = $blzmcp_status();
$q0            = $wpdb->num_queries;
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$mem0         = memory_get_usage();
$before_ts    = time();
$t0           = hrtime( true );
$result       = $ability->execute( array( 'since_hours' => $hours ) );
$ms           = ( hrtime( true ) - $t0 ) / 1e6;
$after_ts     = time();
$peak         = memory_get_peak_usage() - $mem0;
$q1           = $wpdb->num_queries;
$saved        = $wpdb->queries;
$after_status = $blzmcp_status();

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
	$sql  = (string) $q[0];
	$plan = null;
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
		'ms'   => round( (float) $q[1] * 1000, 1 ),
		'sql'  => substr( (string) $shape, 0, 400 ),
		'plan' => $plan,
	);
}
$deltas = array();
foreach ( $after_status as $k => $v ) {
	$deltas[ $k ] = $v - ( $before_status[ $k ] ?? 0 );
}
// Rows examined versus selected: the session Rows_read / Rows_sent deltas, or the Handler_read sum
// when Rows_read is not available. Both include the one status query of the "after" snapshot.
$rows_examined = isset( $deltas['Rows_read'] ) ? (int) $deltas['Rows_read'] : array_sum( array_intersect_key( $deltas, array_flip( preg_grep( '/^Handler_read/', array_keys( $deltas ) ) ) ) );
$rows_selected = isset( $deltas['Rows_sent'] ) ? (int) $deltas['Rows_sent'] : null;

// The ability's own now (C1: one captured timestamp, shown as checked_at), bounded by the call.
$now = is_array( $result ) && is_string( $result['checked_at'] ?? null ) ? strtotime( $result['checked_at'] ) : false;
if ( false === $now || $now < $before_ts - 1 || $now > $after_ts + 1 ) {
	$now = $before_ts;
}
$since = $now - $hours * 3600;

// Untimed reader accounting at the same now: counts and bounds only (no order data is kept).
$reader = null;
try {
	$raw    = \Blaze\McpToolkit\Diagnostics\PaymentOrders::read_card_testing( (int) $now, $hours, wp_timezone() );
	$reader = array_intersect_key( $raw, array_flip( array( 'ok', 'authority', 'reason', 'coverage', 'scan_complete', 'totals', 'methods_truncated', 'methods_omitted', 'invalid_overflow', 'caps', 'networks_seen', 'draft_anomalies', 'rows_read', 'usage', 'work', 'signals' ) ) );
	unset( $raw );
} catch ( \Throwable $e ) {
	$reader = null;
}

// What the fixture's log sources hold in the window, counted after the timed call (not part of it).
$errors_cls = '\Blaze\McpToolkit\Diagnostics\PaymentErrors';
$limits     = class_exists( $errors_cls ) ? array(
	'max_files' => (int) $errors_cls::MAX_FILES,
	'max_bytes' => (int) $errors_cls::MAX_BYTES,
	'max_rows'  => (int) $errors_cls::MAX_DB_ROWS,
) : BLZMCP_CARD_PERF_LOG_LIMITS;
$util       = '\Automattic\WooCommerce\Utilities\LoggingUtil';
$handler    = class_exists( $util ) ? ltrim( (string) $util::get_default_handler(), '\\' ) : (string) get_option( 'woocommerce_logs_default_handler', '' );
$logs       = array(
	'handler_ok'     => 'db' === $logger ? 'WC_Log_Handler_DB' === $handler : 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2' === $handler,
	'in_window'      => 0,
	'files'          => 0,
	'bytes'          => 0,
	'max_file_bytes' => 0,
	'rows'           => null,
);
if ( 'db' === $logger ) {
	$table             = $wpdb->prefix . 'woocommerce_log';
	$logs['in_window'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source = %s AND level >= %d AND timestamp >= %s AND timestamp < %s', $table, 'woocommerce-gateway-stripe', 500, gmdate( 'Y-m-d H:i:s', $since ), gmdate( 'Y-m-d H:i:s', $now ) ) );
	$logs['rows']      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source = %s', $table, 'woocommerce-gateway-stripe' ) );
} else {
	$dir = class_exists( $util ) ? (string) $util::get_log_directory( false ) : '';
	foreach ( '' === $dir ? array() : (array) scandir( $dir ) as $name ) {
		if ( ! preg_match( '/^woocommerce-gateway-stripe(\.\d+)?-\d{4}-\d{2}-\d{2}-[A-Za-z0-9]+\.log$/', (string) $name ) ) {
			continue;
		}
		$lpath = rtrim( $dir, '/' ) . '/' . $name;
		$mtime = filemtime( $lpath );
		if ( false === $mtime || $mtime < $since ) {
			continue;
		}
		$size                   = (int) filesize( $lpath );
		$logs['files']         += 1;
		$logs['bytes']         += $size;
		$logs['max_file_bytes'] = max( $logs['max_file_bytes'], $size );
		$lines                  = file( $lpath, FILE_IGNORE_NEW_LINES );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( preg_match( '/^(\S+)\s+(ERROR|CRITICAL|ALERT|EMERGENCY)\s/', $line, $hit ) ) {
				$ts = strtotime( $hit[1] );
				if ( false !== $ts && $ts >= $since && $ts < $now ) {
					++$logs['in_window'];
				}
			}
		}
	}
}

$expect = array(
	'since_hours' => $hours,
	'orders'      => blzmcp_card_perf_expect( $seed_now, $orders_n, $old_n, (int) $now, $hours ),
	'logger'      => $logger,
	'logs'        => $logs,
	'limits'      => $limits,
);
$valid  = blzmcp_card_perf_validate_trial( $result, $expect, array( 'reader' => $reader ) );
$ok     = is_array( $result ) && $valid['ok'];
$rows   = is_array( $result ) ? (array) ( $result['assessments'] ?? array() ) : array();

$blzmcp_out(
	'MEASURE',
	array(
		'ok'                      => $ok,
		'forced'                  => '' === $force ? null : $force,
		'detected'                => '' === $force ? null : ( false === $valid['readers'][ $force ] ),
		'validation'              => array(
			'readers'  => $valid['readers'],
			'failures' => array_slice( $valid['failures'], 0, 12 ),
		),
		'expected'                => array(
			'now'     => (int) $now,
			'totals'  => $expect['orders']['totals'],
			'bursts'  => $expect['orders']['bursts'],
			'logs'    => $logs,
			'limits'  => $limits,
			'methods' => $expect['orders']['methods'],
		),
		'error'                   => is_wp_error( $result ) ? $result->get_error_code() : null,
		'since_hours'             => $hours,
		'logger'                  => $logger,
		'elapsed_ms'              => round( $ms, 1 ),
		'warm_ms'                 => round( $wms, 1 ),
		'warm_same_checked_at'    => is_array( $warm ) && is_array( $result ) && ( $warm['checked_at'] ?? null ) === ( $result['checked_at'] ?? null ),
		'queries'                 => $q1 - $q0,
		'handler_read'            => $deltas,
		'rows_examined'           => $rows_examined,
		'rows_selected'           => $rows_selected,
		'meta_rows'               => is_array( $reader ) && isset( $reader['work']['meta_rows'] ) ? (int) $reader['work']['meta_rows'] : null,
		'reader'                  => $reader,
		'peak_memory_delta_bytes' => $peak,
		'verdict'                 => is_array( $result ) ? ( $result['verdict'] ?? null ) : null,
		'activity'                => is_array( $result ) ? ( $result['activity'] ?? null ) : null,
		'coverage'                => is_array( $result ) ? ( $result['coverage'] ?? null ) : null,
		'assessments'             => array_map(
			static fn( $a ) => array(
				'method_id'    => $a['method_id'] ?? null,
				'origin_scope' => $a['origin_scope'] ?? null,
				'verdict'      => $a['verdict'] ?? null,
				'coverage'     => $a['coverage'] ?? null,
				'reason_code'  => $a['reason_code'] ?? null,
			),
			array_values( array_filter( $rows, 'is_array' ) )
		),
		'logs'                    => is_array( $result ) ? array_map(
			static fn( $s ) => array(
				'family'      => $s['family'] ?? null,
				'coverage'    => $s['coverage'] ?? null,
				'reason_code' => $s['reason_code'] ?? null,
				'count'       => $s['count'] ?? null,
			),
			array_values( array_filter( (array) ( $result['logs'] ?? array() ), 'is_array' ) )
		) : array(),
		'examples'                => is_array( $result ) ? count( (array) ( $result['examples'] ?? array() ) ) : 0,
		'findings_total'          => is_array( $result ) ? ( $result['findings_total'] ?? null ) : null,
		'slowest'                 => $slow,
		'versions'                => $blzmcp_versions(),
	)
);
