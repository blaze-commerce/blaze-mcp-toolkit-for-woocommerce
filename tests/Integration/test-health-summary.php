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
blzmcp_it_assert( str_contains( $ability->get_description(), 'hook names and plugin and theme names also come from the site' ), 'description warns hook/plugin/theme names are site data' );

// Append a fatal line (with an email in it) to wp-content/debug.log; restore the file afterwards.
$log      = WP_CONTENT_DIR . '/debug.log';
$existed  = file_exists( $log );
$old_size = $existed ? (int) filesize( $log ) : 0;
$marker   = 'blzmcp_it_' . wp_generate_password( 8, false );
$line     = '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] PHP Fatal error:  Uncaught Exception: ' . $marker . ' payment failed for jane.doe@example.com in /var/www/html/wp-content/plugins/fake-gateway/gateway.php:42' . "\n"
	. '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] PHP Fatal error:  Uncaught Error: ' . $marker . '_phar in phar:///usr/local/bin/wp/vendor/wp-cli/eval-command/src/EvalFile_Command.php(85) : eval()\'d code:21' . "\n";
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
blzmcp_it_assert( ! str_contains( $json, 'usr\/local' ) && ! str_contains( $json, 'usr/local' ), 'phar:// absolute path is masked' );
$sev_ok = true;
foreach ( (array) ( $result['php_errors']['groups'] ?? array() ) as $group ) {
	$sev_ok = $sev_ok && in_array( $group['severity'], array( 'fatal', 'error', 'warning', 'notice', 'deprecated' ), true );
}
blzmcp_it_assert( $sev_ok, 'every severity is in the enum' );
$c = $result['php_errors']['counts'] ?? array();
blzmcp_it_assert( ( $c['fatal'] ?? 0 ) >= 2, 'counts.fatal covers the appended fatals' );

// Sections are deduplicated: wp_debug and php_error_log both point at debug.log here.
$ids = array_column( (array) ( $result['php_errors']['sources'] ?? array() ), 'id' );
blzmcp_it_equals( array( 'wp_debug', 'php_error_log', 'wc_fatal' ), $ids, 'three sources reported' );
$marker_groups = 0;
foreach ( (array) ( $result['php_errors']['groups'] ?? array() ) as $group ) {
	if ( str_contains( (string) ( $group['untrusted_text'] ?? '' ), $marker ) ) {
		$marker_groups += (int) $group['count'];
	}
}
blzmcp_it_equals( 2, $marker_groups, 'the same file is read once (each appended fatal counted once)' );

// Structured Cookie / Set-Cookie headers never reach the output (audit F01), generic cookie names.
$cookie_secret = 'SYNTHSESS' . wp_generate_password( 10, false );
$cookie_stamp  = '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] PHP Warning:  ' . $marker . '_cookie ';
$cookie_lines  = array(
	'plain'           => 'Cookie: sessionid=' . $cookie_secret . '; csrftoken=' . $cookie_secret,
	'json'            => '{"Cookie":"sessionid=' . $cookie_secret . '"}',
	'escaped json'    => '{\"Cookie\":\"PHPSESSID=' . $cookie_secret . '; other=' . $cookie_secret . '\"}',
	'print_r'         => '[Cookie] => sessionid=' . $cookie_secret . '; b=' . $cookie_secret,
	'var_export'      => "'Cookie' => 'sessionid=" . $cookie_secret . "'",
	'set-cookie json' => '{"Set-Cookie":"sessionid=' . $cookie_secret . '; HttpOnly"}',
);
$cookie_payload = '';
foreach ( $cookie_lines as $form => $text ) {
	$cookie_payload .= $cookie_stamp . $form . ' ' . $text . "\n";
}
file_put_contents( $log, $cookie_payload, FILE_APPEND );
$clear_cache();
$cookie_result = $ability->execute( array( 'since_hours' => 24 ) );
$cookie_json   = (string) wp_json_encode( $cookie_result );
blzmcp_it_assert( str_contains( $cookie_json, $marker . '_cookie' ), 'cookie test lines reach the health output' );
blzmcp_it_assert( ! str_contains( $cookie_json, $cookie_secret ), 'no cookie value survives in health output (plain, JSON, escaped JSON, print_r, var_export, Set-Cookie)' );
$cookie_groups = 0;
foreach ( (array) ( $cookie_result['php_errors']['groups'] ?? array() ) as $group ) {
	if ( str_contains( (string) ( $group['untrusted_text'] ?? '' ), $marker . '_cookie' ) ) {
		$cookie_groups += str_contains( (string) $group['untrusted_text'], '[secret]' ) ? 1 : 0;
	}
}
blzmcp_it_equals( count( $cookie_lines ), $cookie_groups, 'each cookie line is kept as a useful group with [secret] in place of the value' );

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
if ( ActionScheduler::store() instanceof ActionScheduler_DBStore ) {
	blzmcp_it_assert( ! preg_grep( '/not using its database store/', (array) ( $jobs_result['notes'] ?? array() ) ), 'database store: per-hook counts are exact (no sample note)' );
} else {
	blzmcp_it_assert( (bool) preg_grep( '/not using its database store/', (array) ( $jobs_result['notes'] ?? array() ) ), 'non-database store: sample note is shown' );
}
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

// Past-due grace: a pending job only counts once it is more than an hour late (queue runner lag is normal).
$late_id   = as_schedule_single_action( time() - 2 * HOUR_IN_SECONDS, 'blzmcp_it_late_job', array(), 'blzmcp-it' );
$recent_id = as_schedule_single_action( time() - 120, 'blzmcp_it_recent_job', array(), 'blzmcp-it' );
$jobs_result = $ability->execute( array( 'since_hours' => 24 ) );
$past_due_of = static function ( $res, string $hook ): int {
	foreach ( (array) ( $res['background_jobs']['by_hook'] ?? array() ) as $r ) {
		if ( $hook === $r['hook'] ) {
			return (int) $r['past_due'];
		}
	}
	return 0;
};
blzmcp_it_equals( 1, $past_due_of( $jobs_result, 'blzmcp_it_late_job' ), 'pending job 2 hours late counts as past due' );
blzmcp_it_equals( 0, $past_due_of( $jobs_result, 'blzmcp_it_recent_job' ), 'pending job 2 minutes late is within the 1-hour grace' );
blzmcp_it_assert( str_contains( wp_get_ability( 'blaze-mcp-toolkit/health-summary' )->get_description(), 'more than an hour' ), 'description explains the past-due grace' );
// Fallback (non-database store) path uses the same grace.
$sampled = ( new ReflectionMethod( \Blaze\McpToolkit\Diagnostics\JobsHealth::class, 'sampled_counts' ) );
$pdq     = ( new ReflectionMethod( \Blaze\McpToolkit\Diagnostics\JobsHealth::class, 'past_due_query' ) );
$sampled->setAccessible( true );
$pdq->setAccessible( true );
$fallback_hooks = array_column( $sampled->invoke( null, array( 'status' => 'failed', 'modified' => time(), 'modified_compare' => '>=' ), $pdq->invoke( null, time() ) ), 'hook' );
blzmcp_it_assert( in_array( 'blzmcp_it_late_job', $fallback_hooks, true ) && ! in_array( 'blzmcp_it_recent_job', $fallback_hooks, true ), 'fallback past-due query applies the 1-hour grace' );
ActionScheduler::store()->delete_action( $late_id );
ActionScheduler::store()->delete_action( $recent_id );
$clear_cache();

// WooCommerce fatal logs: every daily file in the window is read, not just the newest.
$wc_dir    = trailingslashit( WC_LOG_DIR );
$wc_marker = 'blzmcp_it_wc_' . wp_generate_password( 8, false );
$wc_old    = $wc_dir . 'fatal-errors-' . gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) . '-blzmcpit.log';
$wc_new    = $wc_dir . 'fatal-errors-' . gmdate( 'Y-m-d' ) . '-blzmcpit.log';
file_put_contents( $wc_old, gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3 * DAY_IN_SECONDS ) . ' CRITICAL Uncaught Error: ' . $wc_marker . " old\n" );
file_put_contents( $wc_new, gmdate( 'Y-m-d\TH:i:s+00:00' ) . ' CRITICAL Uncaught Error: ' . $wc_marker . " new\n" );
touch( $wc_old, time() - 3 * DAY_IN_SECONDS );
$wc_hits = static function ( $res ) use ( $wc_marker ): array {
	$hits = array();
	foreach ( (array) ( $res['php_errors']['groups'] ?? array() ) as $g ) {
		if ( str_contains( (string) $g['untrusted_text'], $wc_marker ) && 'wc_fatal' === $g['source'] ) {
			$hits[] = substr( (string) $g['untrusted_text'], -3 );
		}
	}
	sort( $hits );
	return $hits;
};
$clear_cache();
$week = $ability->execute( array( 'since_hours' => 168 ) );
blzmcp_it_equals( array( 'new', 'old' ), $wc_hits( $week ), 'since_hours 168 reads older WooCommerce fatal files too' );
$clear_cache();
$day = $ability->execute( array( 'since_hours' => 24 ) );
blzmcp_it_equals( array( 'new' ), $wc_hits( $day ), 'since_hours 24 skips the 3-day-old WooCommerce file' );
blzmcp_it_assert( is_array( $day ) && (bool) preg_grep( '/may be counted once from the PHP log and once from the WooCommerce log/', $day['notes'] ), 'duplicate-fatal note when WooCommerce and PHP logs are both readable' );
unlink( $wc_old );
unlink( $wc_new );

// Core updates: any available core update (even a minor one) is amber; unknown plugins/themes alone are not.
$saved_core = get_site_transient( 'update_core' );
$wp_version = get_bloginfo( 'version' );
$offer      = static function ( string $response, string $version ) {
	return (object) array(
		'response' => $response,
		'download' => 'https://example.invalid/wordpress.zip',
		'locale'   => 'en_US',
		'current'  => $version,
		'version'  => $version,
		'packages' => (object) array(),
	);
};
set_site_transient(
	'update_core',
	(object) array(
		'updates'         => array( $offer( 'latest', $wp_version ) ),
		'last_checked'    => time(),
		'version_checked' => $wp_version,
	)
);
$clear_cache();
$latest = $ability->execute( array( 'since_hours' => 24 ) );
blzmcp_it_equals( 'current', $latest['updates']['core']['update_status'] ?? null, 'core is current with a latest offer' );
blzmcp_it_assert( ( $latest['updates']['totals']['plugins_unknown'] ?? 0 ) + ( $latest['updates']['totals']['themes_unknown'] ?? 0 ) > 0, 'there are unknown plugins/themes in wp-env' );
blzmcp_it_equals( 'green', $latest['updates']['status'] ?? null, 'unknown plugins/themes alone keep updates green' );
blzmcp_it_assert( (bool) preg_grep( '/does not change the updates status/', (array) ( $latest['notes'] ?? array() ) ), 'unknown plugins/themes add a note' );

$parts = explode( '.', $wp_version );
$minor = $parts[0] . '.' . ( $parts[1] ?? '0' ) . '.' . ( (int) ( $parts[2] ?? 0 ) + 1 );
set_site_transient(
	'update_core',
	(object) array(
		'updates'         => array( $offer( 'upgrade', $minor ) ),
		'last_checked'    => time(),
		'version_checked' => $wp_version,
	)
);
$clear_cache();
$upgrade = $ability->execute( array( 'since_hours' => 24 ) );
blzmcp_it_equals( $minor, $upgrade['updates']['core']['available'] ?? null, 'minor core update is reported' );
blzmcp_it_equals( 'amber', $upgrade['updates']['status'] ?? null, 'minor core update makes updates amber' );
if ( false === $saved_core ) {
	delete_site_transient( 'update_core' );
} else {
	set_site_transient( 'update_core', $saved_core );
}
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
