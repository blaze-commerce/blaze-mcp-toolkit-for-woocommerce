<?php
/**
 * One uncached payment-health call on the method-cap stress fixture (1.2.1 FEATURE-PLAN item 5).
 * Disposable test environments only.
 *
 *   wp eval-file .../measure-outside-cap.php <distinct_ids>
 *
 * The fixture (seed-payment-volume.php cap) holds BLZMCP_PERF_CAP_CONTROL cod checkout orders and
 * <distinct_ids> orders outside verified checkout activity, each with its own method ID. Deletes
 * only the toolkit's own result cache entries, runs the ability once as an administrator and
 * checks it with blzmcp_perf_validate_cap(): at 1,000 IDs every one is listed and nothing is
 * truncated; at 1,001 exactly 1,000 are listed with methods_truncated, partial inventory coverage
 * and the cap reason; rows stay one per safe ID in ID order and the checkout control is unchanged.
 * This is a cap/stress case with its own assertions: its timing is recorded, never counted as a
 * performance trial.
 *
 * Prints one line: CAP=<json> with ok, failures, distinct_ids, listed, methods_truncated,
 * inventory_coverage, elapsed_ms, queries and peak memory delta.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/payment-volume-cycle.php';
require_once __DIR__ . '/perf-validate.php';

global $wpdb;
$blzmcp_cap_k = (int) ( ( $args ?? array() )[0] ?? 0 );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_measure_blocked', 'no HTTP while measuring' ), 1 );
wp_set_current_user( 1 );
foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%' ) ) as $name ) {
	delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
}
$ability = wp_get_ability( 'blaze-mcp-toolkit/payment-health' );
if ( ! $ability instanceof WP_Ability ) {
	echo 'CAP=' . wp_json_encode( array( 'ok' => false, 'distinct_ids' => $blzmcp_cap_k, 'failures' => array( 'ability not registered' ) ) ) . "\n";
	return;
}
if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}
$mem0   = memory_get_usage();
$q0     = $wpdb->num_queries;
$t0     = hrtime( true );
$result = $ability->execute( array( 'since_hours' => 24 ) );
$ms     = ( hrtime( true ) - $t0 ) / 1e6;
$valid  = blzmcp_perf_validate_cap(
	$result,
	$blzmcp_cap_k,
	array(
		'method'    => 'cod',
		'taken_28d' => BLZMCP_PERF_CAP_CONTROL,
	)
);
echo 'CAP=' . wp_json_encode(
	array(
		'ok'                      => is_array( $result ) && $valid['ok'],
		'distinct_ids'            => $blzmcp_cap_k,
		'failures'                => array_slice( is_wp_error( $result ) ? array( $result->get_error_code() ) : $valid['failures'], 0, 12 ),
		'listed'                  => $valid['listed'],
		'methods_truncated'       => $valid['methods_truncated'],
		'inventory_coverage'      => $valid['inventory_coverage'],
		'coverage'                => is_array( $result ) ? ( $result['coverage'] ?? null ) : null,
		'methods'                 => is_array( $result ) ? count( (array) ( $result['methods'] ?? array() ) ) : 0,
		'elapsed_ms'              => round( $ms, 1 ),
		'queries'                 => $wpdb->num_queries - $q0,
		'peak_memory_delta_bytes' => memory_get_peak_usage() - $mem0,
	)
) . "\n";
