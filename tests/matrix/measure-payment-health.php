<?php
/**
 * One uncached payment-health measurement in this fresh PHP request (FEATURE-PLAN item 10).
 *
 *   wp eval-file .../measure-payment-health.php <since_hours>
 *
 * Deletes only the toolkit's own result cache entries (blzmcp_c_ transients, one by one; no
 * whole-cache flush), then times one end-to-end ability execute() as an administrator: permission,
 * cache context, the readers, response validation and the cache and audit writes. Then times one
 * warm (cached) call. A call that returns an error, or a result whose coverage is unavailable, is
 * reported as failed, never as a fast success.
 *
 * Prints one line: MEASURE=<json> with elapsed_ms, warm_ms, queries, the slowest queries with their
 * EXPLAIN rows, Handler_read deltas (rows examined), peak memory delta and versions.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

defined( 'ABSPATH' ) || exit;

global $wpdb;
$hours = (int) ( ( $args ?? array() )[0] ?? 24 );
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

$blzmcp_handler_status = static function () use ( $wpdb ): array {
	$rows = $wpdb->get_results( "SHOW SESSION STATUS LIKE 'Handler_read%'", ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['Variable_name'] ] = (int) $r['Value'];
	}
	return $out;
};

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}
$wpdb->queries = array();
$before_status = $blzmcp_handler_status();
$q0            = $wpdb->num_queries;
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$mem0   = memory_get_usage();
$t0     = hrtime( true );
$result = $ability->execute( array( 'since_hours' => $hours ) );
$ms     = ( hrtime( true ) - $t0 ) / 1e6;
$peak   = memory_get_peak_usage() - $mem0;
$q1     = $wpdb->num_queries;
$saved  = $wpdb->queries;
$after_status = $blzmcp_handler_status();

$t1   = hrtime( true );
$warm = $ability->execute( array( 'since_hours' => $hours ) );
$wms  = ( hrtime( true ) - $t1 ) / 1e6;

usort( $saved, static fn( $a, $b ) => $b[1] <=> $a[1] );
$slow = array();
foreach ( array_slice( $saved, 0, 5 ) as $q ) {
	$sql   = (string) $q[0];
	$plan  = null;
	if ( preg_match( '/^\s*SELECT/i', $sql ) ) {
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

$ok = is_array( $result ) && in_array( $result['coverage'] ?? null, array( 'complete', 'partial' ), true );
echo 'MEASURE=' . wp_json_encode(
	array(
		'ok'             => $ok,
		'error'          => is_wp_error( $result ) ? $result->get_error_code() : null,
		'since_hours'    => $hours,
		'elapsed_ms'     => round( $ms, 1 ),
		'warm_ms'        => round( $wms, 1 ),
		'warm_same_checked_at' => is_array( $warm ) && is_array( $result ) && ( $warm['checked_at'] ?? null ) === ( $result['checked_at'] ?? null ),
		'queries'        => $q1 - $q0,
		'handler_read'   => $deltas,
		'peak_memory_delta_bytes' => $peak,
		'overall'        => is_array( $result ) ? ( $result['overall'] ?? null ) : null,
		'coverage'       => is_array( $result ) ? ( $result['coverage'] ?? null ) : null,
		'methods'        => is_array( $result ) ? count( (array) ( $result['methods'] ?? array() ) ) : null,
		'examples'       => is_array( $result ) ? array_sum( array_map( static fn( $m ) => count( (array) ( $m['stuck']['examples'] ?? array() ) ), (array) ( $result['methods'] ?? array() ) ) ) : null,
		'log_count'      => is_array( $result ) ? array_map( static fn( $m ) => $m['errors']['logs']['count'] ?? null, array_column( (array) ( $result['methods'] ?? array() ), null, 'id' ) ) : null,
		'job_failed'     => is_array( $result ) ? array_map( static fn( $m ) => $m['errors']['jobs']['failed'] ?? null, array_column( (array) ( $result['methods'] ?? array() ), null, 'id' ) ) : null,
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
