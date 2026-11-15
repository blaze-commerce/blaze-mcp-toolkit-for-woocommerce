<?php
/**
 * PaymentErrors integration test (FEATURE-PLAN item 5): file and DB loggers, jobs, webhook records, permission gate.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-payment-errors.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/payment-fixtures.php';

use Blaze\McpToolkit\Diagnostics\JobsHealth;
use Blaze\McpToolkit\Diagnostics\LogSources;
use Blaze\McpToolkit\Diagnostics\PaymentErrors;
use Blaze\McpToolkit\Diagnostics\PaymentMethods;
use Blaze\McpToolkit\Settings;

global $wpdb;

$http_calls = 0;
add_filter(
	'pre_http_request',
	static function () use ( &$http_calls ) {
		++$http_calls;
		return new WP_Error( 'blzmcp_blocked', 'blocked' );
	},
	0
);
$queries = array();
$spy     = static function ( $q ) use ( &$queries ) {
	$queries[] = $q;
	return $q;
};

// Options touched, restored afterwards.
$names = array( 'woocommerce_stripe_settings', 'woocommerce_logs_default_handler', 'wc_stripe_wh_monitor_began_at', 'wc_stripe_wh_last_success_at', 'wc_stripe_wh_last_failure_at', 'wc_stripe_wh_test_monitor_began_at', 'wc_stripe_wh_test_last_success_at', 'wc_stripe_wh_test_last_failure_at' );
$saved = array();
foreach ( $names as $n ) {
	$saved[ $n ] = get_option( $n, null );
}
blzmcp_it_cleanup(
	static function () use ( $saved ): void {
		foreach ( $saved as $n => $v ) {
			null === $v ? delete_option( $n ) : update_option( $n, $v );
		}
	}
);

$now  = time();
$tz   = wp_timezone();
$fam  = PaymentMethods::token_map();
$inv  = array(
	'stripe'     => array( 'id' => 'stripe', 'family' => 'stripe', 'kind' => 'instant', 'webhook_adapter' => 'stripe', 'webhook_required' => false ),
	'stripe_ach' => array( 'id' => 'stripe_ach', 'family' => 'stripe', 'kind' => 'unknown', 'webhook_adapter' => 'stripe', 'webhook_required' => null ),
	'bacs'       => array( 'id' => 'bacs', 'family' => null, 'kind' => 'manual', 'webhook_adapter' => null, 'webhook_required' => null ),
	'othergw'    => array( 'id' => 'othergw', 'family' => null, 'kind' => 'unknown', 'webhook_adapter' => null, 'webhook_required' => null ),
);
update_option( 'woocommerce_stripe_settings', array( 'enabled' => 'yes', 'testmode' => 'no', 'secret_key' => 'sk_live_SYNTHETIC_NEVER' ) );
update_option( 'wc_stripe_wh_monitor_began_at', (string) ( $now - 30 * DAY_IN_SECONDS ) );
update_option( 'wc_stripe_wh_last_success_at', (string) ( $now - 10 * HOUR_IN_SECONDS ) );
update_option( 'wc_stripe_wh_last_failure_at', (string) ( $now - 2 * HOUR_IN_SECONDS ) );
update_option( 'wc_stripe_wh_test_last_success_at', (string) ( $now - 60 ) );
update_option( 'wc_stripe_wh_test_last_failure_at', '0' );

// ---------------------------------------------------------------- no diagnostics permission: no log, table or job read.
$manager = wp_insert_user( array( 'user_login' => 'blzmcp_pf_mgr_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
blzmcp_it_cleanup( static fn() => wp_delete_user( (int) $manager ) );
wp_set_current_user( (int) $manager );
$settings_backup = get_option( Settings::OPTION, null );
blzmcp_it_cleanup( static fn() => null === $settings_backup ? delete_option( Settings::OPTION ) : update_option( Settings::OPTION, $settings_backup ) );
blzmcp_it_assert( ! Settings::can_view_diagnostics(), 'shop manager without the diagnostics toggle cannot view diagnostics' );
add_filter( 'query', $spy );
$denied = PaymentErrors::for_methods( $inv, $fam, array( 'stripe' => 3 ), $now, 24, $tz );
remove_filter( 'query', $spy );
$touched = array_filter( $queries, static fn( $q ) => str_contains( $q, 'woocommerce_log' ) || str_contains( $q, 'actionscheduler' ) );
blzmcp_it_equals( array(), array_values( $touched ), 'no log-table or Action Scheduler query without permission' );
blzmcp_it_equals( array( false, 'unavailable', 'diagnostics_not_permitted', null ), array( $denied['stripe']['logs']['checked'], $denied['stripe']['logs']['coverage'], $denied['stripe']['logs']['reason'], $denied['stripe']['logs']['count'] ), 'logs unchecked with null count' );
blzmcp_it_equals( null, $denied['stripe']['jobs']['failed'], 'jobs count null, not zero' );
blzmcp_it_equals( 'red', $denied['stripe']['webhook']['status'], 'webhook status still visible at the entry permission (newer failure inside 72 hours)' );
blzmcp_it_equals( 'delivery_failed', $denied['stripe']['webhook']['category'], 'fixed webhook category' );
blzmcp_it_equals( 'red', $denied['stripe']['status'], 'errors status rolls up' );

// ---------------------------------------------------------------- administrator: file handler.
wp_set_current_user( 1 );
blzmcp_it_assert( Settings::can_view_diagnostics(), 'administrator can view diagnostics' );
$dir = LogSources::wc_log_dir();
blzmcp_it_assert( is_string( $dir ) && is_dir( $dir ), 'WooCommerce log directory exists' );
$file  = rtrim( (string) $dir, '/' ) . '/woocommerce-gateway-stripe-' . gmdate( 'Y-m-d', $now ) . '-blzmcpfixture.log';
$lines = array();
foreach ( (array) file( __DIR__ . '/../fixtures/payment-log-lines.txt', FILE_IGNORE_NEW_LINES ) as $line ) {
	// Shift the synthetic 2026-11-14T20:xx entries to just before now; drop the fixed-date boundary
	// lines (the unit test covers them against a fixed clock); keep continuation and malformed lines.
	if ( 1 === preg_match( '/^2026-11-1[35]T/', (string) $line ) ) {
		continue;
	}
	$lines[] = preg_replace_callback( '/^2026-11-14T20:(\d{2}):00\+00:00/', static fn( $m ) => gmdate( 'c', $now - 3600 + (int) $m[1] * 60 ), (string) $line );
}
file_put_contents( $file, implode( "\n", $lines ) . "\n" );
blzmcp_it_cleanup( static fn() => file_exists( $file ) && unlink( $file ) );

$res = PaymentErrors::for_methods( $inv, $fam, array( 'stripe' => 3 ), $now, 24, $tz );
$log = $res['stripe']['logs'];
blzmcp_it_equals( 'red', $log['status'], 'verified webhook-delivery failure in the file log is red' );
blzmcp_it_equals( 6, $log['count'], 'six error-level entries observed (entries, not lines)' );
blzmcp_it_equals( 'partial', $log['coverage'], 'a malformed entry head makes coverage partial' );
blzmcp_it_assert( $log['count_is_lower_bound'], 'partial count is a lower bound' );
blzmcp_it_equals( 3, $log['groups_total'], 'three fixed category groups' );
foreach ( $log['groups'] as $g ) {
	blzmcp_it_assert( in_array( $g['category'], PaymentErrors::LOG_CATEGORIES, true ) && 'woocommerce_log_file' === $g['source'] && 'plugin' === $g['scope'], 'group uses fixed category, source label and shared scope' );
}
blzmcp_it_equals( $log['count'], $res['stripe_ach']['logs']['count'], 'shared family evidence is the same, not summed per method' );
blzmcp_it_equals( array( false, 'not_applicable' ), array( $res['bacs']['logs']['applicable'], $res['bacs']['logs']['coverage'] ), 'manual method: logs not applicable' );
blzmcp_it_equals( array( false, false, 'not_applicable', 'no_verified_mapping', null ), array( $res['othergw']['logs']['checked'], $res['othergw']['logs']['applicable'], $res['othergw']['logs']['coverage'], $res['othergw']['logs']['reason'], $res['othergw']['logs']['count'] ), 'Q6: unmapped gateway logs are not applicable, no count' );
blzmcp_it_equals( array( false, false, 'not_applicable', 'no_verified_mapping', null ), array( $res['othergw']['jobs']['checked'], $res['othergw']['jobs']['applicable'], $res['othergw']['jobs']['coverage'], $res['othergw']['jobs']['reason'], $res['othergw']['jobs']['failed'] ), 'Q6: unmapped gateway jobs are not applicable, no count' );
blzmcp_it_equals( 'no_verified_mapping', $denied['othergw']['logs']['reason'], 'Q6: unmapped stays not applicable without diagnostics permission too' );
blzmcp_it_equals( array( false, 'not_applicable', 'no_supported_webhook_record' ), array( $res['othergw']['webhook']['applicable'], $res['othergw']['webhook']['coverage'], $res['othergw']['webhook']['reason'] ), 'unsupported webhook adapter is not applicable' );
$json = wp_json_encode( $res );
foreach ( array( 'jane', 'Jane', '+61', 'Example Street', 'pi_SYNTHETIC', 'Ignore previous', 'signature_mismatch', 'sk_live', 'blzmcpfixture', '/var/www', 'wc-logs', 'CONTEXT' ) as $needle ) {
	blzmcp_it_assert( ! str_contains( $json, $needle ), "output never contains {$needle}" );
}

// No matching source file is unavailable, not an empty healthy log.
unlink( $file );
$none = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz );
blzmcp_it_equals( array( 'unknown', 'unavailable', 'no_matching_log_source', null ), array( $none['stripe']['logs']['status'], $none['stripe']['logs']['coverage'], $none['stripe']['logs']['reason'], $none['stripe']['logs']['count'] ), 'no matching file: unavailable' );

// A clean, complete file read gives an exact zero and green.
file_put_contents( $file, gmdate( 'c', $now - 600 ) . " INFO Webhook received\n" );
$clean = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz );
blzmcp_it_equals( array( 'green', 'complete', 0 ), array( $clean['stripe']['logs']['status'], $clean['stripe']['logs']['coverage'], $clean['stripe']['logs']['count'] ), 'INFO mentioning webhook is not a failure; complete empty read is green' );

// Pass 4 #5: parse coverage. A non-empty source with no recognised entry head is unavailable, not zero.
$ts_a = gmdate( 'c', $now - 900 );
$ts_b = gmdate( 'c', $now - 800 );
file_put_contents( $file, '[' . gmdate( 'd-M-Y H:i:s', $now - 900 ) . " UTC] PHP Fatal error: gateway exploded\nStack trace:\n#0 {main}\n" . gmdate( 'Y-m-d H:i:s', $now - 800 ) . " ERROR space-separated format\n" );
$unsup = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
blzmcp_it_equals( array( 'unknown', 'unavailable', 'unparsed_entries', null ), array( $unsup['status'], $unsup['coverage'], $unsup['reason'], $unsup['count'] ), 'only unsupported entry heads: unavailable, never a complete zero' );
// An orphan continuation before the first head, then valid multi-line entries: partial, entries still counted.
file_put_contents( $file, "orphan line with no entry head\n{$ts_a} ERROR first entry\n    its continuation\n{$ts_b} ERROR second entry\n" );
$orph = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
blzmcp_it_equals( array( 'amber', 'partial', 'unparsed_entries', 2, true ), array( $orph['status'], $orph['coverage'], $orph['reason'], $orph['count'], $orph['count_is_lower_bound'] ), 'orphan continuation: partial lower bound, valid entries counted' );
// Valid multi-line entries only: complete.
file_put_contents( $file, "{$ts_a} ERROR first entry\n    its continuation\n{\"context\":\"more\"}\n{$ts_b} INFO second entry\n" );
$multi = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
blzmcp_it_equals( array( 'amber', 'complete', 1 ), array( $multi['status'], $multi['coverage'], $multi['count'] ), 'valid continuations are kept: complete, one entry' );
// Fix round 3 (pre-review issue 3): a byte-capped tail that starts inside a multi-line entry
// reports byte_cap, not unparsed_entries; its leading lines are the cut entry's continuation.
$big = "{$ts_a} ERROR huge multi-line entry\n" . str_repeat( "    continuation line padding the entry past the byte cap\n", intdiv( PaymentErrors::MAX_BYTES, 50 ) + 2000 ) . "{$ts_b} ERROR last entry\n";
file_put_contents( $file, $big );
blzmcp_it_assert( strlen( $big ) > PaymentErrors::MAX_BYTES, 'fixture exceeds the per-file byte cap' );
$tail = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
blzmcp_it_equals( array( 'amber', 'partial', 'byte_cap', 1, true ), array( $tail['status'], $tail['coverage'], $tail['reason'], $tail['count'], $tail['count_is_lower_bound'] ), 'cut-off tail: byte_cap partial lower bound, not unparsed' );
// The same tail made only of continuation lines (no head inside the read) stays partial byte_cap.
file_put_contents( $file, "{$ts_a} ERROR huge entry\n" . str_repeat( "    continuation line padding the entry past the byte cap\n", intdiv( PaymentErrors::MAX_BYTES, 50 ) + 2000 ) );
$only = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
blzmcp_it_equals( array( 'unknown', 'partial', 'byte_cap', 0 ), array( $only['status'], $only['coverage'], $only['reason'], $only['count'] ), 'tail inside one entry: unknown partial byte_cap, never a complete zero' );
// An empty file is an exact, complete zero.
file_put_contents( $file, '' );
$empty = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
blzmcp_it_equals( array( 'green', 'complete', 0 ), array( $empty['status'], $empty['coverage'], $empty['count'] ), 'empty source file: exact zero' );
unlink( $file );

// ---------------------------------------------------------------- DB handler.
$table = $wpdb->prefix . 'woocommerce_log';
if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
	update_option( 'woocommerce_logs_default_handler', 'WC_Log_Handler_DB' );
	$ids = array();
	foreach ( array( 500, 500, 600, 400, 200 ) as $i => $level ) {
		$wpdb->insert( $table, array( 'timestamp' => gmdate( 'Y-m-d H:i:s', $now - 300 - $i ), 'level' => $level, 'source' => 'woocommerce-gateway-stripe', 'message' => 'Synthetic decline for jane.citizen@example.com', 'context' => '{"secret":"x"}' ) );
		$ids[] = (int) $wpdb->insert_id;
	}
	$wpdb->insert( $table, array( 'timestamp' => gmdate( 'Y-m-d H:i:s', $now - 200 ), 'level' => 500, 'source' => 'some-other-plugin', 'message' => 'not ours' ) );
	$ids[] = (int) $wpdb->insert_id;
	blzmcp_it_cleanup(
		static function () use ( $wpdb, $table, $ids ): void {
			foreach ( $ids as $id ) {
				$wpdb->delete( $table, array( 'log_id' => $id ) );
			}
		}
	);
	$db = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
	blzmcp_it_equals( array( 'amber', 3, 'complete' ), array( $db['status'], $db['count'], $db['coverage'] ), 'DB logger: three error-level entries for the source, amber' );
	blzmcp_it_equals( 'woocommerce_log_db', $db['groups'][0]['source'] ?? null, 'DB source label' );
	blzmcp_it_assert( ! str_contains( wp_json_encode( $db ), 'jane' ), 'DB message text never returned' );

	// Pass 4 #5: 4-byte messages against the byte budget, row cap and a metadata-only sentinel.
	blzmcp_it_cleanup( static fn() => $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND message LIKE %s', $table, 'woocommerce-gateway-stripe', 'blzmcp-mb %' ) ) );
	$mb_msg = 'blzmcp-mb ' . str_repeat( "\u{1F600}", 1000 ); // 4,010 bytes, 1,010 characters.
	$mb_add = static function ( int $n, int $offset ) use ( $wpdb, $table, $now, $mb_msg ): void {
		for ( $done = 0; $done < $n; $done += 100 ) {
			$vals = array();
			for ( $i = $done; $i < min( $n, $done + 100 ); $i++ ) {
				$vals[] = $wpdb->prepare( '(%s, %d, %s, %s)', gmdate( 'Y-m-d H:i:s', $now - 400 - ( ( $offset + $i ) % 3600 ) ), 500, 'woocommerce-gateway-stripe', $mb_msg );
			}
			$wpdb->query( "INSERT INTO `{$table}` (timestamp, level, source, message) VALUES " . implode( ', ', $vals ) );
		}
	};
	// 3 qualifying rows exist already; 1,997 more makes exactly the 2,000-row cap.
	$mb_add( PaymentErrors::MAX_DB_ROWS - 3, 0 );
	$seen = array();
	$spy  = static function ( $q ) use ( &$seen ) {
		if ( str_contains( (string) $q, 'woocommerce_log' ) ) {
			$seen[] = (string) $q;
		}
		return $q;
	};
	add_filter( 'query', $spy );
	$at_cap = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
	remove_filter( 'query', $spy );
	blzmcp_it_equals( array( 'red', 'complete', PaymentErrors::MAX_DB_ROWS, false ), array( $at_cap['status'], $at_cap['coverage'], $at_cap['count'], $at_cap['count_is_lower_bound'] ), 'exactly 2,000 rows: complete (the sentinel finds no more)' );
	$main  = array_values( array_filter( $seen, static fn( $q ) => str_contains( $q, 'AS msg' ) ) );
	$probe = array_values( array_filter( $seen, static fn( $q ) => str_contains( $q, 'log_id <' ) ) );
	blzmcp_it_equals( array( 1, 1 ), array( count( $main ), count( $probe ) ), 'one bounded message query and one sentinel probe' );
	blzmcp_it_assert( isset( $probe[0] ) && ! str_contains( $probe[0], 'message' ) && ! str_contains( $probe[0], 'context' ), 'sentinel is metadata-only' );
	if ( isset( $main[0] ) ) {
		$fetched = $wpdb->get_col( 'SELECT LENGTH(t.msg) FROM ( ' . $main[0] . ' ) t' ); // Re-run the exact captured query, measuring bytes in SQL.
		$bytes   = array_sum( array_map( 'intval', $fetched ) );
		blzmcp_it_assert( max( array_map( 'intval', $fetched ) ) <= PaymentErrors::MAX_DB_MSG, 'each 4-byte message is cut to 2,048 bytes by the database, not 2,048 characters' );
		blzmcp_it_assert( $bytes <= PaymentErrors::MAX_BYTES, "message bytes handed to PHP ({$bytes}) stay within 5,242,880" );
	}
	$mb_add( 1, PaymentErrors::MAX_DB_ROWS );
	$over = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['logs'];
	// Wave 3 fix4: the log row cap has its own code; the orders reader's row_cap text says "Orders".
	blzmcp_it_equals( array( 'red', 'partial', 'log_row_cap', PaymentErrors::MAX_DB_ROWS, true ), array( $over['status'], $over['coverage'], $over['reason'], $over['count'], $over['count_is_lower_bound'] ), '2,001 rows: log row cap (not the orders row_cap), partial lower bound from the sentinel' );
	$cap_text = \Blaze\McpToolkit\Abilities\PaymentHealth::reason_text( (string) $over['reason'] );
	blzmcp_it_assert( is_string( $cap_text ) && \Blaze\McpToolkit\Abilities\PaymentHealth::reason_text( 'row_cap' ) !== $cap_text && ! str_contains( $cap_text, 'Orders' ) && str_contains( $cap_text, 'log' ), 'capped DB log read: the owner sees the log text, not the orders text' );
	blzmcp_it_assert( ! str_contains( wp_json_encode( $over ), "\u{1F600}" ) && ! str_contains( wp_json_encode( $over ), 'blzmcp-mb' ), 'no message text returned' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s AND message LIKE %s', $table, 'woocommerce-gateway-stripe', 'blzmcp-mb %' ) );

	update_option( 'woocommerce_logs_default_handler', $saved['woocommerce_logs_default_handler'] ?? 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2' );
} else {
	echo "NOTE  woocommerce_log table absent; DB handler case skipped\n";
}

// DB handler with a family that has hook tokens but no log sources: no query, no `IN ()` error.
update_option( 'woocommerce_logs_default_handler', 'WC_Log_Handler_DB' );
$hooks_only = array(
	'acme' => array(
		'plugin'          => array(),
		'method_ids'      => array( 'acme' ),
		'method_prefixes' => array(),
		'log_sources'     => array(),
		'hooks'           => array( 'acme_job' ),
		'hook_groups'     => array(),
	),
);
$queries = array();
add_filter( 'query', $spy );
$wpdb->last_error = '';
$acme = PaymentErrors::for_methods( array( 'acme' => array( 'id' => 'acme', 'family' => 'acme', 'kind' => 'unknown', 'webhook_adapter' => null, 'webhook_required' => null ) ), $hooks_only, array(), $now, 24, $tz )['acme'];
remove_filter( 'query', $spy );
update_option( 'woocommerce_logs_default_handler', $saved['woocommerce_logs_default_handler'] ?? 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2' );
blzmcp_it_equals( array(), array_values( array_filter( $queries, static fn( $q ) => str_contains( $q, 'woocommerce_log' ) ) ), 'no log-table query when no family has log sources' );
blzmcp_it_equals( '', $wpdb->last_error, 'no database error' );
blzmcp_it_equals( array( false, 'no_verified_mapping' ), array( $acme['logs']['applicable'], $acme['logs']['reason'] ), 'hooks-only family: logs not applicable' );
blzmcp_it_assert( $acme['jobs']['applicable'], 'hooks-only family: jobs are a mapped check' );

// ---------------------------------------------------------------- jobs.
$aid = as_schedule_single_action( $now + 3600, 'wc_stripe_deferred_webhook', array( 'blzmcp' => 'fixture' ), 'woocommerce_stripe' );
$oid = as_schedule_single_action( $now + 3600, 'blzmcp_unrelated_hook', array(), 'blzmcp-fixture' );
blzmcp_it_cleanup(
	static function () use ( $aid, $oid ): void {
		foreach ( array( $aid, $oid ) as $id ) {
			try {
				ActionScheduler::store()->delete_action( $id );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
);
ActionScheduler::store()->mark_failure( $aid );
ActionScheduler::store()->mark_failure( $oid );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_attempt_gmt = %s WHERE action_id IN (%d, %d)', $wpdb->prefix . 'actionscheduler_actions', gmdate( 'Y-m-d H:i:s', $now - 600 ), $aid, $oid ) );

$exact = JobsHealth::payment_failures_db( $fam['stripe']['hooks'], $fam['stripe']['hook_groups'], $now - DAY_IN_SECONDS, $now );
blzmcp_it_assert( is_array( $exact ) && 1 === array_sum( array_column( $exact, 'count' ) ), 'grouped DB query counts exactly the matching failed action' );
$old = JobsHealth::payment_failures_db( $fam['stripe']['hooks'], $fam['stripe']['hook_groups'], $now - 300, $now );
blzmcp_it_equals( 0, is_array( $old ) ? array_sum( array_column( $old, 'count' ) ) : -1, 'window uses last attempt time' );

$jobs = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['jobs'];
$db_store = ActionScheduler::store() instanceof ActionScheduler_DBStore;
echo 'NOTE  Action Scheduler store: ' . get_class( ActionScheduler::store() ) . "\n";
blzmcp_it_equals( 'amber', $jobs['status'], 'matching failed job is amber' );
blzmcp_it_assert( $jobs['failed'] >= 1, 'failed count observed' );
blzmcp_it_equals( ! $db_store, $jobs['count_is_lower_bound'], 'sampled stores are a lower bound; DB store is exact' );
blzmcp_it_equals( $db_store ? 'complete' : 'partial', $jobs['coverage'], 'coverage follows the store' );
blzmcp_it_equals( array( 'category', 'count', 'scope' ), array_keys( $jobs['hooks'][0] ?? array() ), 'hook rows carry a fixed category, never a hook name' );
blzmcp_it_assert( ! str_contains( wp_json_encode( $jobs ), 'wc_stripe_deferred_webhook' ) && ! str_contains( wp_json_encode( $jobs ), 'blzmcp' ), 'no hook names or arguments returned' );

// ---------------------------------------------------------------- Q8 interim: PayPal, WooPayments, Square (default token map).
$q8_ids = array(
	'ppcp-gateway'         => 'paypal',
	'ppcp-ideal'           => 'paypal',
	'woocommerce_payments' => 'woopayments',
	'square_credit_card'   => 'square',
);
$q8_inv = array();
foreach ( $q8_ids as $id => $family ) {
	$q8_inv[ $id ] = array( 'id' => $id, 'family' => PaymentMethods::method_family( $id, $fam ), 'kind' => PaymentMethods::kind( $id ), 'webhook_adapter' => null, 'webhook_required' => null );
	blzmcp_it_equals( $family, $q8_inv[ $id ]['family'], "{$id} belongs to {$family}" );
}
$wp_pay   = as_schedule_single_action( $now + 3600, 'wcpay_webhook_process_event', array( 'blzmcp' => 'q8' ), 'woocommerce_payments' );
$wp_track = as_schedule_single_action( $now + 3600, 'wcpay_track_new_order', array( 'blzmcp' => 'q8' ), 'woocommerce_payments' );
$sq_sync  = as_schedule_single_action( $now + 3600, 'wc_square_sync', array( 'blzmcp' => 'q8' ), 'square' );
blzmcp_it_cleanup(
	static function () use ( $wp_pay, $wp_track, $sq_sync ): void {
		foreach ( array( $wp_pay, $wp_track, $sq_sync ) as $id ) {
			try {
				ActionScheduler::store()->delete_action( $id );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
);
foreach ( array( $wp_pay, $wp_track, $sq_sync ) as $id ) {
	ActionScheduler::store()->mark_failure( $id );
}
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_attempt_gmt = %s WHERE action_id IN (%d, %d, %d)', $wpdb->prefix . 'actionscheduler_actions', gmdate( 'Y-m-d H:i:s', $now - 600 ), $wp_pay, $wp_track, $sq_sync ) );
$wp_exact = JobsHealth::payment_failures_db( $fam['woopayments']['hooks'], $fam['woopayments']['hook_groups'], $now - DAY_IN_SECONDS, $now );
blzmcp_it_assert( is_array( $wp_exact ) && 1 === array_sum( array_column( $wp_exact, 'count' ) ), 'WooPayments: only the payment-related failed hook counts, not order tracking in the same group' );
$q8 = PaymentErrors::for_methods( $q8_inv, $fam, array(), $now, 24, $tz );
blzmcp_it_equals( array( true, 'amber', 1 ), array( $q8['woocommerce_payments']['jobs']['applicable'], $q8['woocommerce_payments']['jobs']['status'], $q8['woocommerce_payments']['jobs']['failed'] ), 'WooPayments jobs applicable: one payment job failure, amber' );
foreach ( array( 'ppcp-gateway', 'ppcp-ideal', 'square_credit_card' ) as $id ) {
	blzmcp_it_equals( array( false, 'not_applicable', 'no_verified_mapping' ), array( $q8[ $id ]['jobs']['applicable'], $q8[ $id ]['jobs']['coverage'], $q8[ $id ]['jobs']['reason'] ), "{$id}: jobs not applicable (a failed Square sync is not a payment job)" );
}
foreach ( $q8_ids as $id => $unused ) {
	blzmcp_it_equals( array( false, 'no_verified_mapping', null ), array( $q8[ $id ]['logs']['applicable'], $q8[ $id ]['logs']['reason'], $q8[ $id ]['logs']['count'] ), "{$id}: logs not applicable (Q6)" );
	blzmcp_it_equals( array( false, 'no_supported_webhook_record' ), array( $q8[ $id ]['webhook']['applicable'], $q8[ $id ]['webhook']['reason'] ), "{$id}: webhook not applicable" );
}
blzmcp_it_assert( ! str_contains( wp_json_encode( $q8 ), 'wcpay_' ) && ! str_contains( wp_json_encode( $q8 ), 'q8' ), 'no WooPayments hook names or arguments returned' );

// ---------------------------------------------------------------- webhook mode separation.
update_option( 'woocommerce_stripe_settings', array( 'enabled' => 'yes', 'testmode' => 'yes' ) );
$test = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['webhook'];
blzmcp_it_equals( array( 'green', 'delivery_ok' ), array( $test['status'], $test['category'] ), 'test mode reads only test records' );
delete_option( 'woocommerce_stripe_settings' );
$unk = PaymentErrors::for_methods( array( 'stripe' => $inv['stripe'] ), $fam, array(), $now, 24, $tz )['stripe']['webhook'];
blzmcp_it_equals( array( 'unknown', 'mode_unknown' ), array( $unk['status'], $unk['category'] ), 'absent settings: mode unknown' );

blzmcp_it_equals( 0, $http_calls, 'no HTTP request from diagnostics' );
blzmcp_it_finish();
