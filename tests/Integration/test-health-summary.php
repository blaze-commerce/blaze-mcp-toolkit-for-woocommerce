<?php
/**
 * Health summary ability integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-health-summary.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Abilities\HealthSummary;
use Blaze\McpToolkit\Settings;

global $wpdb;

$clear_cache = static function () use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%', $wpdb->esc_like( '_transient_timeout_blzmcp_c_' ) . '%' ) );
	wp_cache_flush();
};

$ability = wp_get_ability( HealthSummary::NAME );
blzmcp_it_assert( $ability instanceof WP_Ability, 'health-summary ability is registered' );
if ( ! $ability instanceof WP_Ability ) {
	blzmcp_it_finish();
}
$meta = $ability->get_meta();
blzmcp_it_equals( true, $meta['mcp']['public'] ?? null, 'meta.mcp.public is true' );
blzmcp_it_equals( true, $meta['annotations']['readonly'] ?? null, 'annotation readonly is true' );
blzmcp_it_assert( str_contains( $ability->get_description(), 'untrusted_text' ), 'description warns about untrusted_text' );
blzmcp_it_assert( str_contains( strtolower( $ability->get_description() ), 'unknown, not healthy' ), 'description says unreadable means unknown, not healthy' );

// Append a fatal line (with an email in it) to wp-content/debug.log; restore the file afterwards.
$log      = WP_CONTENT_DIR . '/debug.log';
$existed  = file_exists( $log );
$old_size = $existed ? (int) filesize( $log ) : 0;
$marker   = 'blzmcp_it_' . wp_generate_password( 8, false );
$line     = '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] PHP Fatal error:  Uncaught Exception: ' . $marker . ' payment failed for jane.doe@example.com in /var/www/html/wp-content/plugins/fake-gateway/gateway.php:42' . "\n";
file_put_contents( $log, $line, FILE_APPEND );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$clear_cache();

// Count (and block) any outbound HTTP request made while the ability runs.
$http_calls = 0;
$http_spy   = static function ( $pre ) use ( &$http_calls ) {
	++$http_calls;
	return new WP_Error( 'blzmcp_it_blocked', 'blocked' );
};
add_filter( 'pre_http_request', $http_spy );

$started = microtime( true );
$result  = $ability->execute( array( 'since_hours' => 24 ) );
$elapsed = microtime( true ) - $started;
remove_filter( 'pre_http_request', $http_spy );
blzmcp_it_assert( is_array( $result ), 'administrator gets a result' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ': ' . $result->get_error_message() . ')' : '' ) );
$result = is_array( $result ) ? $result : array();
blzmcp_it_assert( $elapsed < 3.0, sprintf( 'returns within 3 s (%.2f s)', $elapsed ) );
blzmcp_it_equals( 'red', $result['php_errors']['status'] ?? null, 'php_errors.status is red after a fatal' );
blzmcp_it_equals( 'red', $result['overall'] ?? null, 'overall is red' );

$found = null;
foreach ( (array) ( $result['php_errors']['groups'] ?? array() ) as $group ) {
	if ( str_contains( (string) ( $group['untrusted_text'] ?? '' ), $marker ) ) {
		$found = $group;
	}
}
blzmcp_it_assert( null !== $found, 'the fatal shows up as a group' );
blzmcp_it_equals( 'fatal', $found['severity'] ?? null, 'the group is fatal' );
blzmcp_it_assert( null !== $found && ! array_key_exists( 'message', $found ), 'group text is under untrusted_text, not message' );

$json = (string) wp_json_encode( $result );
blzmcp_it_equals( 0, preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json ), 'output JSON has no email address' );
blzmcp_it_assert( ! str_contains( $json, '"path"' ), 'output JSON has no path key' );
blzmcp_it_assert( ! str_contains( $json, '/var/www' ) && ! str_contains( $json, '\/var\/www' ), 'output JSON has no absolute filesystem path' );

// Sections are deduplicated: wp_debug and php_error_log both point at debug.log here.
$ids = array_column( (array) ( $result['php_errors']['sources'] ?? array() ), 'id' );
blzmcp_it_equals( array( 'wp_debug', 'php_error_log', 'wc_fatal' ), $ids, 'three sources reported' );
$marker_groups = 0;
foreach ( (array) ( $result['php_errors']['groups'] ?? array() ) as $group ) {
	if ( str_contains( (string) ( $group['untrusted_text'] ?? '' ), $marker ) ) {
		$marker_groups += (int) $group['count'];
	}
}
blzmcp_it_equals( 1, $marker_groups, 'the same file is read once (fatal counted once)' );

blzmcp_it_assert( in_array( $result['background_jobs']['status'] ?? null, array( 'red', 'amber', 'green' ), true ), 'background_jobs has a status' );
blzmcp_it_assert( is_int( $result['background_jobs']['failed'] ?? null ) && is_int( $result['background_jobs']['past_due'] ?? null ), 'background_jobs has counts' );
blzmcp_it_assert( in_array( $result['updates']['status'] ?? null, array( 'red', 'amber', 'green' ), true ), 'updates has a status' );
blzmcp_it_assert( is_array( $result['updates']['plugins'] ?? null ) && count( $result['updates']['plugins'] ) <= 50, 'updates.plugins is capped at 50' );
blzmcp_it_equals( get_bloginfo( 'version' ), $result['versions']['wordpress'] ?? null, 'versions.wordpress' );
blzmcp_it_equals( WC()->version, $result['versions']['woocommerce'] ?? null, 'versions.woocommerce' );
blzmcp_it_equals( 1, preg_match( '/^\d+\.\d+\.\d+$/', (string) ( $result['versions']['php'] ?? '' ) ), 'versions.php is major.minor.patch' );
blzmcp_it_assert( is_array( $result['notes'] ?? null ), 'notes is a list' );

// No remote update checks (or any other HTTP request) were triggered.
blzmcp_it_equals( 0, $http_calls, 'no outbound HTTP request was made' );

// A failed money-related job turns background_jobs red; a failed ordinary job only amber.
$job_id = as_schedule_single_action( time() - 120, 'blzmcp_it_stock_sync', array(), 'blzmcp-it' );
ActionScheduler::store()->mark_failure( $job_id );
$clear_cache();
$jobs_result = $ability->execute( array( 'since_hours' => 24 ) );
$jobs        = is_array( $jobs_result ) ? $jobs_result['background_jobs'] : array();
blzmcp_it_equals( 'red', $jobs['status'] ?? null, 'failed money-related job makes background_jobs red' );
blzmcp_it_assert( ( $jobs['failed'] ?? 0 ) >= 1 && ( $jobs['money_related_failed'] ?? 0 ) >= 1, 'failed job is counted as money-related' );
$hook_row = array_values( array_filter( (array) ( $jobs['by_hook'] ?? array() ), static fn( $r ) => 'blzmcp_it_stock_sync' === $r['hook'] ) );
blzmcp_it_assert( isset( $hook_row[0] ) && 1 === $hook_row[0]['failed'] && true === $hook_row[0]['money_related'], 'by_hook lists the failed hook' );
ActionScheduler::store()->delete_action( $job_id );

$job_id = as_schedule_single_action( time() - 120, 'blzmcp_it_plain_job', array(), 'blzmcp-it' );
ActionScheduler::store()->mark_failure( $job_id );
$clear_cache();
$jobs_result = $ability->execute( array( 'since_hours' => 24 ) );
$jobs        = is_array( $jobs_result ) ? $jobs_result['background_jobs'] : array();
$expected    = ( $jobs['money_related_failed'] ?? 0 ) > 0 ? 'red' : 'amber';
blzmcp_it_equals( $expected, $jobs['status'] ?? null, 'failed ordinary job makes background_jobs amber (unless other money jobs failed)' );
ActionScheduler::store()->delete_action( $job_id );
$clear_cache();

// Shop manager with the setting off: WP_Error.
$settings = Settings::get();
Settings::update( array_merge( $settings, array( 'shop_manager_diagnostics' => false ) ) );
$created = array();
foreach ( array( 'shop_manager', 'subscriber' ) as $role ) {
	$created[ $role ] = wp_insert_user(
		array(
			'user_login' => 'blzmcp_' . $role . '_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
		)
	);
}
wp_set_current_user( $created['shop_manager'] );
$denied = $ability->execute( array( 'since_hours' => 24 ) );
blzmcp_it_assert( is_wp_error( $denied ), 'shop_manager with the setting off gets a WP_Error' );

Settings::update( array_merge( $settings, array( 'shop_manager_diagnostics' => true ) ) );
$clear_cache();
$allowed = $ability->execute( array( 'since_hours' => 24 ) );
blzmcp_it_assert( is_array( $allowed ), 'shop_manager with the setting on gets data' );
Settings::update( $settings );

wp_set_current_user( $created['subscriber'] );
$denied = $ability->execute( array( 'since_hours' => 24 ) );
blzmcp_it_assert( is_wp_error( $denied ), 'subscriber gets a WP_Error' );

// Input bounds are enforced by the schema.
wp_set_current_user( $admins[0]->ID );
blzmcp_it_assert( is_wp_error( $ability->execute( array( 'since_hours' => 0 ) ) ), 'since_hours 0 is rejected' );
blzmcp_it_assert( is_wp_error( $ability->execute( array( 'since_hours' => 169 ) ) ), 'since_hours 169 is rejected' );
blzmcp_it_assert( is_wp_error( $ability->execute( array( 'path' => '/etc/passwd' ) ) ), 'unknown input keys are rejected' );
$clear_cache();
blzmcp_it_assert( is_array( $ability->execute() ), 'no input uses the default window' );

// Print a sample for the report when asked.
if ( getenv( 'BLZMCP_SAMPLE' ) ) {
	echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
}

// Cleanup: restore debug.log, users, cache, audit rows from this run.
if ( $existed ) {
	$fh = fopen( $log, 'r+' );
	if ( $fh ) {
		ftruncate( $fh, $old_size );
		fclose( $fh );
	}
} else {
	unlink( $log );
}
blzmcp_it_assert( ! file_exists( $log ) || ! str_contains( (string) file_get_contents( $log ), $marker ), 'test line removed from debug.log' );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $created as $user_id ) {
	wp_delete_user( $user_id );
}
$clear_cache();

blzmcp_it_finish();
