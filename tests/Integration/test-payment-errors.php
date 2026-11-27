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

// ---------------------------------------------------------------- for_methods() golden bytes (1.3 card logs).
// The card-testing log summaries share this reader. for_methods() must keep returning exactly the
// bytes captured before that change, for the same fixtures: a fixed clock (the log fixture's own,
// 2026-11-15T00:00:00Z), a fixed timezone and a log source token used by nothing else. Run with
// BLZMCP_PE_CAPTURE=1 to print the current bytes instead of asserting; regenerate them only for a
// reviewed, intentional change to payment health's output.
$g_capture = '1' === (string) getenv( 'BLZMCP_PE_CAPTURE' );
$g_now     = 1794700800;
$g_tz      = new DateTimeZone( 'Australia/Melbourne' );
$g_token   = 'blzmcp-golden-' . strtolower( wp_generate_password( 8, false ) );
$g_fam     = $fam;
$g_fam['stripe']['log_sources'] = array( $g_token );
// No job tokens: the jobs block (sampled or exact, by Action Scheduler store) would otherwise depend
// on the site rather than on the code. Jobs keep their own assertions above; this change never
// touches them.
$g_fam['stripe']['hooks']       = array();
$g_fam['stripe']['hook_groups'] = array();
$g_file    = rtrim( (string) $dir, '/' ) . '/' . $g_token . '-2026-11-14-blzmcpgolden.log';
$g_enabled = get_option( 'woocommerce_logs_logging_enabled', null );
blzmcp_it_cleanup( static fn() => file_exists( $g_file ) && unlink( $g_file ) );
blzmcp_it_cleanup( static fn() => null === $g_enabled ? delete_option( 'woocommerce_logs_logging_enabled' ) : update_option( 'woocommerce_logs_logging_enabled', $g_enabled ) );
blzmcp_it_cleanup( static fn() => $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s', $wpdb->prefix . 'woocommerce_log', $g_token ) ) );
update_option( 'woocommerce_stripe_settings', array( 'enabled' => 'yes', 'testmode' => 'no' ) );
update_option( 'wc_stripe_wh_monitor_began_at', (string) ( $g_now - 30 * DAY_IN_SECONDS ) );
update_option( 'wc_stripe_wh_last_success_at', (string) ( $g_now - 10 * HOUR_IN_SECONDS ) );
update_option( 'wc_stripe_wh_last_failure_at', (string) ( $g_now - 2 * HOUR_IN_SECONDS ) );
update_option( 'wc_stripe_wh_test_last_success_at', (string) ( $g_now - 60 ) );
update_option( 'wc_stripe_wh_test_last_failure_at', '0' );
$g_file_handler = 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2';
update_option( 'woocommerce_logs_default_handler', $g_file_handler );
$g_got = array();
$g_run = static function ( string $name, int $hours = 24 ) use ( &$g_got, $inv, $g_fam, $g_now, $g_tz ): void {
	$g_got[ $name ] = (string) wp_json_encode( PaymentErrors::for_methods( $inv, $g_fam, array( 'stripe' => 3 ), $g_now, $hours, $g_tz ) );
};
// Ruling Q2(b): a second family mapped to Stripe's (fixture) log source through the tokens map,
// listed after Stripe, so the 1.2.1 database reader gave all rows to it and a complete zero to Stripe.
$g_fam_shared              = $g_fam;
$g_fam_shared['blzshared'] = array(
	'plugin'          => array(),
	'method_ids'      => array( 'blzshared' ),
	'method_prefixes' => array(),
	'log_sources'     => array( $g_token ),
	'hooks'           => array(),
	'hook_groups'     => array(),
);
$g_inv_shared              = $inv;
$g_inv_shared['blzshared'] = array( 'id' => 'blzshared', 'family' => 'blzshared', 'kind' => 'unknown', 'webhook_adapter' => null, 'webhook_required' => null );
$g_run_shared              = static function ( string $name ) use ( &$g_got, $g_inv_shared, $g_fam_shared, $g_now, $g_tz ): void {
	$g_got[ $name ] = (string) wp_json_encode( PaymentErrors::for_methods( $g_inv_shared, $g_fam_shared, array( 'stripe' => 3 ), $g_now, 24, $g_tz ) );
};
// File handler: the shared fixture lines, unshifted (boundary lines included).
file_put_contents( $g_file, (string) file_get_contents( __DIR__ . '/../fixtures/payment-log-lines.txt' ) );
$g_run( 'file_fixture' );
$g_run_shared( 'shared_file' );
file_put_contents( $g_file, gmdate( 'c', $g_now - 600 ) . " INFO Webhook received\n" );
$g_run( 'file_clean' );
file_put_contents( $g_file, '[' . gmdate( 'd-M-Y H:i:s', $g_now - 900 ) . " UTC] PHP Fatal error: gateway exploded\nStack trace:\n" );
$g_run( 'file_unparsed' );
file_put_contents( $g_file, gmdate( 'c', $g_now - 900 ) . " ERROR huge entry\n" . str_repeat( "    continuation line padding the entry past the byte cap\n", intdiv( PaymentErrors::MAX_BYTES, 50 ) + 2000 ) . gmdate( 'c', $g_now - 800 ) . " CRITICAL last entry\n" );
$g_run( 'file_byte_cap' );
file_put_contents( $g_file, gmdate( 'c', $g_now - 40 * HOUR_IN_SECONDS ) . " ERROR older entry\n" );
$g_retain = static fn() => 1;
add_filter( 'woocommerce_logger_days_to_retain_logs', $g_retain );
$g_run( 'file_stale_retention', 48 );
remove_filter( 'woocommerce_logger_days_to_retain_logs', $g_retain );
update_option( 'woocommerce_logs_logging_enabled', 'no' );
$g_run( 'logging_disabled' );
update_option( 'woocommerce_logs_logging_enabled', 'yes' );
unlink( $g_file );
$g_run( 'file_none' );
// Diagnostics denied (shop manager without the toggle).
wp_set_current_user( (int) $manager );
$g_run( 'denied' );
wp_set_current_user( 1 );
// DB handler: three error-level rows of the source, lower levels and another source ignored; then
// the 2,000-row cap and its metadata-only sentinel.
if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
	update_option( 'woocommerce_logs_default_handler', 'WC_Log_Handler_DB' );
	foreach ( array( 500, 600, 800, 400, 100 ) as $i => $level ) {
		$wpdb->insert( $table, array( 'timestamp' => gmdate( 'Y-m-d H:i:s', $g_now - 300 - 60 * $i ), 'level' => $level, 'source' => $g_token, 'message' => 'Synthetic decline for jane.citizen@example.com', 'context' => '{"secret":"x"}' ) );
	}
	$wpdb->insert( $table, array( 'timestamp' => gmdate( 'Y-m-d H:i:s', $g_now - 200 ), 'level' => 500, 'source' => $g_token . '-other', 'message' => 'not ours' ) );
	$g_other = (int) $wpdb->insert_id;
	$g_run( 'db_rows' );
	$g_run_shared( 'shared_db' );
	$wpdb->delete( $table, array( 'log_id' => $g_other ) );
	$g_rows = PaymentErrors::MAX_DB_ROWS + 1; // 3 qualifying rows above, then up to 2,001.
	for ( $done = 3; $done < $g_rows; $done += 200 ) {
		$vals = array();
		$upto = min( $g_rows, $done + 200 );
		for ( $i = $done; $i < $upto; $i++ ) {
			$vals[] = $wpdb->prepare( '(%s, %d, %s, %s)', gmdate( 'Y-m-d H:i:s', $g_now - 3600 - $i ), 500, $g_token, 'blzmcp golden row' );
		}
		$wpdb->query( "INSERT INTO `{$table}` (timestamp, level, source, message) VALUES " . implode( ', ', $vals ) );
	}
	$g_run( 'db_row_cap' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source = %s', $table, $g_token ) );
}
update_option( 'woocommerce_logs_default_handler', $saved['woocommerce_logs_default_handler'] ?? $g_file_handler );
if ( $g_capture ) {
	foreach ( $g_got as $name => $json ) {
		echo 'GOLDEN' . "\t" . $name . "\t" . hash( 'sha256', $json ) . "\t" . $json . "\n";
	}
} else {
	$golden = blzmcp_pe_golden();
	blzmcp_it_equals( array_keys( $golden ), array_keys( $g_got ), 'golden: every captured scenario ran' );
	foreach ( $golden as $name => $want ) {
		$got_hash = isset( $g_got[ $name ] ) ? hash( 'sha256', $g_got[ $name ] ) : null;
		blzmcp_it_equals( $want, $got_hash, "golden: for_methods() returns the captured bytes for {$name}" );
		if ( $got_hash !== $want ) {
			echo 'GOT ' . $name . ' ' . ( $g_got[ $name ] ?? 'missing' ) . "\n";
		}
	}
}

// ---------------------------------------------------------------- shared log source (1.3, ruling Q2(b)).
// Stripe and a second family share one source token; a third family shares one of its two tokens;
// a fourth has its own source. The ambiguous families are unknown and unread, never a complete zero
// and never each other's entries; the unshared family is read as before; jobs are unchanged.
$q_sfx  = strtolower( wp_generate_password( 8, false ) );
$q_tok  = 'blzmcp-q2s-' . $q_sfx;
$q_own  = 'blzmcp-q2o-' . $q_sfx;
$q_solo = 'blzmcp-q2c-' . $q_sfx;
$q_list = static fn( string $id, array $sources, array $hooks = array() ): array => array(
	'plugin'          => array(),
	'method_ids'      => array( $id ),
	'method_prefixes' => array(),
	'log_sources'     => $sources,
	'hooks'           => $hooks,
	'hook_groups'     => array(),
);
$q_fam                   = $fam;
$q_fam['stripe']['log_sources'] = array( $q_tok );
$q_fam['blzq2part']      = $q_list( 'blzq2part', array( $q_own, $q_tok ), array( 'blzq2_part_job' ) );
$q_fam['blzq2solo']      = $q_list( 'blzq2solo', array( $q_solo ) );
$q_row                   = static fn( string $id, string $family, string $kind = 'unknown' ): array => array( 'id' => $id, 'family' => $family, 'kind' => $kind, 'webhook_adapter' => null, 'webhook_required' => null );
$q_inv                   = array(
	'stripe'     => $q_row( 'stripe', 'stripe', 'instant' ),
	'stripe_ach' => $q_row( 'stripe_ach', 'stripe' ),
	'blzq2part'  => $q_row( 'blzq2part', 'blzq2part' ),
	'blzq2solo'  => $q_row( 'blzq2solo', 'blzq2solo' ),
);
$q_unshared              = $q_fam;
$q_unshared['blzq2part']['log_sources'] = array( $q_own ); // The same families without the shared token.
$q_files                 = array();
blzmcp_it_cleanup(
	static function () use ( &$q_files ): void {
		foreach ( $q_files as $p ) {
			if ( file_exists( $p ) ) {
				unlink( $p );
			}
		}
	}
);
blzmcp_it_cleanup( static fn() => $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source IN (%s, %s, %s)', $table, $q_tok, $q_own, $q_solo ) ) );
$q_unknown = array( 'unknown', false, true, 'unavailable', 'shared_log_source', null, false, null, array() );
$q_brief   = static fn( array $l ): array => array( $l['status'], $l['checked'], $l['applicable'], $l['coverage'], $l['reason'], $l['count'], $l['count_is_lower_bound'], $l['groups_total'], $l['groups'] );
$q_assert  = static function ( string $handler ) use ( $q_inv, $q_fam, $q_unshared, $now, $tz, $q_unknown, $q_brief, $q_tok, $q_own, $q_solo ): void {
	$queries = array();
	$q_spy   = static function ( $q ) use ( &$queries ) {
		$queries[] = (string) $q;
		return $q;
	};
	add_filter( 'query', $q_spy );
	$res = PaymentErrors::for_methods( $q_inv, $q_fam, array(), $now, 24, $tz );
	remove_filter( 'query', $q_spy );
	foreach ( array( 'stripe', 'stripe_ach', 'blzq2part' ) as $id ) {
		blzmcp_it_equals( $q_unknown, $q_brief( $res[ $id ]['logs'] ), "{$handler}: {$id} shares a log source, so its logs are unknown and unread, never a complete zero" );
	}
	blzmcp_it_equals( array( 'amber', true, 'complete', null, 1 ), array( $res['blzq2solo']['logs']['status'], $res['blzq2solo']['logs']['checked'], $res['blzq2solo']['logs']['coverage'], $res['blzq2solo']['logs']['reason'], $res['blzq2solo']['logs']['count'] ), "{$handler}: the unshared family beside them is read as before" );
	blzmcp_it_equals( 1, array_sum( array_map( static fn( $r ) => (int) $r['logs']['count'], $res ) ), "{$handler}: no entry is attributed to two families (only the unshared family's entry is counted)" );
	blzmcp_it_equals( 'unknown', $res['blzq2part']['status'], "{$handler}: a method whose only evidence is a shared log is unknown, not green" );
	$read = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q, 'woocommerce_log' ) && ! str_contains( $q, 'SHOW TABLES' ) ) );
	blzmcp_it_assert( array() === array_filter( $read, static fn( $q ) => str_contains( $q, $q_tok ) || str_contains( $q, $q_own ) ), "{$handler}: no log query names a shared family's source" );
	if ( 'db' === $handler ) {
		blzmcp_it_assert( 1 === count( array_filter( $read, static fn( $q ) => str_contains( $q, $q_solo ) ) ), 'db: the unshared source is still read in one query' );
	}
	$plain = PaymentErrors::for_methods( $q_inv, $q_unshared, array(), $now, 24, $tz );
	blzmcp_it_equals( wp_json_encode( array_column( $plain, 'jobs' ) ), wp_json_encode( array_column( $res, 'jobs' ) ), "{$handler}: jobs are the same with or without the shared log source" );
	blzmcp_it_equals( 'shared_log_source', $res['stripe']['logs']['reason'], "{$handler}: fixed reason code" );
	blzmcp_it_assert( null === $plain['stripe']['logs']['reason'] || 'shared_log_source' !== $plain['stripe']['logs']['reason'], "{$handler}: without the shared token Stripe is read again" );
};
// File handler.
update_option( 'woocommerce_logs_default_handler', $g_file_handler );
foreach ( array( $q_tok => 2, $q_own => 1, $q_solo => 1 ) as $source => $n ) {
	$p = rtrim( (string) $dir, '/' ) . '/' . $source . '-' . gmdate( 'Y-m-d', $now ) . '-blzmcpq2.log';
	file_put_contents( $p, implode( '', array_map( static fn( $i ) => gmdate( 'c', $now - 600 - $i ) . " ERROR entry {$i}\n", range( 1, $n ) ) ) );
	$q_files[] = $p;
}
$q_assert( 'file' );
wp_set_current_user( (int) $manager );
$q_denied = PaymentErrors::for_methods( $q_inv, $q_fam, array(), $now, 24, $tz );
wp_set_current_user( 1 );
blzmcp_it_equals( array( 'diagnostics_not_permitted', 'diagnostics_not_permitted' ), array( $q_denied['stripe']['logs']['reason'], $q_denied['blzq2part']['logs']['reason'] ), 'denied: the permission reason still comes first' );
// DB handler.
if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
	update_option( 'woocommerce_logs_default_handler', 'WC_Log_Handler_DB' );
	foreach ( array( $q_tok => 3, $q_own => 2, $q_solo => 1 ) as $source => $n ) {
		for ( $i = 0; $i < $n; $i++ ) {
			$wpdb->insert( $table, array( 'timestamp' => gmdate( 'Y-m-d H:i:s', $now - 300 - $i ), 'level' => 500, 'source' => $source, 'message' => 'blzmcp q2 row' ) );
		}
	}
	$q_assert( 'db' );
	update_option( 'woocommerce_logs_default_handler', $saved['woocommerce_logs_default_handler'] ?? $g_file_handler );
} else {
	echo "NOTE  woocommerce_log table absent; DB shared-source case skipped\n";
}
echo 'NOTE  payment health text for shared_log_source: ' . (string) \Blaze\McpToolkit\Abilities\PaymentHealth::reason_text( 'shared_log_source' ) . "\n";

blzmcp_it_equals( 0, $http_calls, 'no HTTP request from diagnostics' );
blzmcp_it_finish();

/**
 * SHA-256 of the exact wp_json_encode() bytes of for_methods(), captured from the 1.2.1 reader
 * (before the 1.3 card-logs change) with BLZMCP_PE_CAPTURE=1. A mismatch prints the new bytes.
 * The two shared_* scenarios are the one intended 1.3 change (ruling Q2(b)); their 1.2.1 digests
 * are noted beside them.
 *
 * @return array<string,string>
 */
function blzmcp_pe_golden(): array {
	return array(
		'file_fixture'         => '4e10c755c1d94de697bc892a4172f494db9de4b94a3d1239e23a14846c778a7e',
		// Ruling Q2(b), the only intended change: both families sharing the source are unknown
		// (1.2.1 gave Stripe all 6 file entries: 377bfb2c...a4ae).
		'shared_file'          => 'f1d4f73378b7f7cd6feb999470d6553ef770a7c45a6487df89291e256819a2ac',
		'file_clean'           => '6aa83f26bcf73ac1a15421f99a7023842526c8585cc81e7ad91c435a75e82ccc',
		'file_unparsed'        => 'bbbb4a3c24fdd13828e3ec7b6cb78718c91694444186b9c4270139768d9a6417',
		'file_byte_cap'        => '9d468fb06e9a6f68e7173f29253753e341d23464189b82a567d7a8909e4cc30d',
		'file_stale_retention' => 'ff97a572c592051a5ecdaed557c66e825760430c1fbf1c9fc58da1ed47ec7a22',
		'logging_disabled'     => '8be26eb3404feafca94fbc1d4af7403c2c087273dd61054cc89dc8a1160692cc',
		'file_none'            => '94b92f7a71a581c90125e6b44ae584e5e1dc150001126869a4d3a50261f158b0',
		'denied'               => '594a21b2d0e7cf59a088a1ac6406a3582cadf3650e569ba0ffcbf853d91c3cee',
		'db_rows'              => '491e26954d9c681b56150017d71ff2623fff934e4593a602fde5db6f8718d46a',
		// Ruling Q2(b): same unknown bytes (1.2.1 gave Stripe a complete zero, the other family all 3 rows: f55aae06...e93b).
		'shared_db'            => 'f1d4f73378b7f7cd6feb999470d6553ef770a7c45a6487df89291e256819a2ac',
		'db_row_cap'           => 'ba00280cb2305f0ab2050d73881d740de7c58dc94b23b0fae639ac135c01aee5',
	);
}
