<?php
/**
 * Synthetic card-testing fixture for the real-plugin matrix HTTP suite (1.3 item 8). Disposable
 * matrix environments only; never run it on a member store.
 *
 *   wp eval-file wp-content/blzmcp-tests/matrix/card-testing-fixture.php seed         Remove any old card fixture, then seed.
 *   wp eval-file wp-content/blzmcp-tests/matrix/card-testing-fixture.php cleanup      Remove the card fixture orders and log file, restore the saved settings.
 *   wp eval-file wp-content/blzmcp-tests/matrix/card-testing-fixture.php clear-cache  Delete the toolkit's own result cache (blzmcp_c_ transients) only.
 *   wp eval-file wp-content/blzmcp-tests/matrix/card-testing-fixture.php status       Print what of the fixture is left (orders, log files, saved settings).
 *
 * Seeded through WooCommerce CRUD relative to the moment of seeding, tagged with meta
 * `_blzmcp_cardfx=1`, using source-verified instant method IDs (none of their plugins is installed,
 * so each is a known instant method that is not registered, with enabled and test mode unknown; no
 * gateway setting is written):
 * - `stripe` (recent burst, card_recent): 98 accepted orders over days -20 … -3 (a quarter with no
 *   marketing source), 3 earlier failed orders, then 30 failed orders in the last 5 minutes from 3
 *   recorded networks with no marketing source. Likely card testing, still recent. Its verified log
 *   family gets one fixture log file with 2 error entries 30 minutes ago, so the diagnostics cases
 *   have something to withhold or show.
 * - `woocommerce_payments` (earlier burst, card_earlier): 101 accepted orders over days -21 … -4
 *   with a marketing source, then 24 failed orders about 6 hours ago from 2 recorded networks with
 *   the source `(none)`. Likely card testing, earlier in the window.
 * - `square_credit_card` (low volume, card_low_volume): 8 accepted orders over days -10 … -2, then 22
 *   failed orders about 3 hours ago from 22 networks. Too little history: unknown, never no_sign.
 * - `ppcp-gateway` (evidence gaps, card_gaps): 100 accepted orders over days -20 … -3 with a
 *   marketing source, then 25 failed checkout orders in the last 30 minutes with no saved IP (the
 *   network sign cannot be checked: possible at most), and 22 failed orders with no recorded origin
 *   in the last 40 minutes (their own unverified-origin scope, never likely).
 * Protection settings for card_protection: Rate limit Checkout on and order attribution on (both
 * saved and restored). WooCommerce logging is set to the file handler, on, with no threshold
 * (saved and restored).
 *
 * `seed` prints SEEDED (all orders) and the per-scenario counts the suite asserts. `cleanup` prints
 * REMOVED (orders), LOGS_REMOVED (fixture log files) and SETTINGS_RESTORED (1 or 0). These orders
 * are created after the fixed Analytics comparisons have run and `cleanup` also removes their
 * Analytics rows, so the fixture.php figures are unaffected. Saved IPs are documentation ranges
 * only (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32); there is no customer data.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

const BLZMCP_CARDFX_META     = '_blzmcp_cardfx';
const BLZMCP_CARDFX_SAVED    = 'blzmcp_cardfx_saved_settings';
const BLZMCP_CARDFX_ATTR_KEY = '_wc_order_attribution_source_type';
const BLZMCP_CARDFX_LOG_TAG  = 'blzmcpcardfx';
const BLZMCP_CARDFX_SETTINGS = array(
	'woocommerce_feature_rate_limit_checkout_enabled' => 'yes',
	'woocommerce_feature_order_attribution_enabled'   => 'yes',
	'woocommerce_logs_default_handler'                => 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2',
	'woocommerce_logs_logging_enabled'                => 'yes',
	'woocommerce_logs_level_threshold'                => null,
);

$blzmcp_cardfx_cmd = (string) ( ( $args ?? array() )[0] ?? '' );
add_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_cardfx_blocked', 'blocked in the card-testing fixture' ), 1 );

/**
 * Every card fixture order ID, from both order stores.
 *
 * @return int[]
 */
function blzmcp_cardfx_ids(): array {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, BLZMCP_CARDFX_META, '1' ) ) );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->prefix . 'wc_orders_meta', BLZMCP_CARDFX_META, '1' ) ) ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * The fixture's own log files (name tag only; nothing else in the log folder is touched).
 *
 * @return string[]
 */
function blzmcp_cardfx_log_files(): array {
	$dir = class_exists( 'Blaze\\McpToolkit\\Diagnostics\\LogSources' ) ? (string) \Blaze\McpToolkit\Diagnostics\LogSources::wc_log_dir() : '';
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return array();
	}
	return array_values( (array) glob( rtrim( $dir, '/' ) . '/*-' . BLZMCP_CARDFX_LOG_TAG . '.log' ) );
}

/**
 * Removes every card fixture order (both stores) with its Analytics rows and the fixture's log
 * files, then restores the settings saved by `seed`.
 *
 * @return array{0:int,1:int,2:int} Orders removed, log files removed, settings restored (1/0).
 */
function blzmcp_cardfx_cleanup(): array {
	global $wpdb;
	$ids = blzmcp_cardfx_ids();
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
		foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'order_id' => $id ) );
		}
	}
	$logs = 0;
	foreach ( blzmcp_cardfx_log_files() as $file ) {
		if ( is_file( $file ) && unlink( $file ) ) {
			++$logs;
		}
	}
	$restored = 0;
	$saved    = get_option( BLZMCP_CARDFX_SAVED, null );
	if ( is_array( $saved ) ) {
		foreach ( $saved as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		delete_option( BLZMCP_CARDFX_SAVED );
		$restored = 1;
	}
	\WC_Cache_Helper::get_transient_version( 'woocommerce_reports', true );
	return array( count( $ids ), $logs, $restored );
}

/**
 * Deletes the toolkit's own result cache entries one by one (no whole-cache flush).
 */
function blzmcp_cardfx_clear_cache(): int {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%' ) );
	foreach ( $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
	return count( $names );
}

/**
 * One fixture order through CRUD.
 *
 * @param string      $method  Payment method ID.
 * @param string      $via     Recorded origin ('' leaves it empty).
 * @param string      $status  Status.
 * @param int         $created Created time.
 * @param string      $ip      Saved IP ('' for none).
 * @param string|null $attr    Attribution source type (null: no row).
 */
function blzmcp_cardfx_order( string $method, string $via, string $status, int $created, string $ip, ?string $attr ): void {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->set_payment_method( $method );
	$order->set_created_via( $via );
	// Always set: wc_create_order() records the request's own address, which is not the fixture's.
	$order->set_customer_ip_address( $ip );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_total( 'failed' === $status ? '1.00' : '24.00' );
	$order->set_date_created( $created );
	$order->update_meta_data( BLZMCP_CARDFX_META, '1' );
	if ( null !== $attr ) {
		$order->add_meta_data( BLZMCP_CARDFX_ATTR_KEY, $attr, false );
	}
	$order->set_status( $status );
	$order->save();
	// Status transitions may stamp a paid date; set the intended one afterwards.
	$order->set_date_paid( 'failed' === $status ? null : $created + 60 );
	$order->save();
}

/**
 * Accepted history: $count orders evenly between $from and $to seconds before $now.
 *
 * @param string            $method Method.
 * @param int               $now    Now.
 * @param int               $count  Orders.
 * @param int               $from   Seconds before now of the first.
 * @param int               $to     Seconds before now of the last.
 * @param array<int,?string> $attr  Attribution cycle (null: no row).
 */
function blzmcp_cardfx_history( string $method, int $now, int $count, int $from, int $to, array $attr ): int {
	$step = intdiv( $from - $to, max( 1, $count - 1 ) );
	for ( $i = 0; $i < $count; $i++ ) {
		blzmcp_cardfx_order( $method, 0 === $i % 3 ? 'store-api' : 'checkout', 'completed', $now - $from + $i * $step, '198.51.100.' . ( 10 + $i % 200 ), $attr[ $i % count( $attr ) ] );
	}
	return $count;
}

if ( 'cleanup' === $blzmcp_cardfx_cmd ) {
	list( $removed, $logs, $restored ) = blzmcp_cardfx_cleanup();
	echo 'REMOVED=' . $removed . "\n";
	echo 'LOGS_REMOVED=' . $logs . "\n";
	echo 'SETTINGS_RESTORED=' . $restored . "\n";
	return;
}
if ( 'clear-cache' === $blzmcp_cardfx_cmd ) {
	echo 'CLEARED=' . blzmcp_cardfx_clear_cache() . "\n";
	return;
}
if ( 'status' === $blzmcp_cardfx_cmd ) {
	echo 'ORDERS_LEFT=' . count( blzmcp_cardfx_ids() ) . "\n";
	echo 'LOGS_LEFT=' . count( blzmcp_cardfx_log_files() ) . "\n";
	echo 'SAVED_LEFT=' . ( is_array( get_option( BLZMCP_CARDFX_SAVED, null ) ) ? 1 : 0 ) . "\n";
	return;
}
if ( 'seed' !== $blzmcp_cardfx_cmd ) {
	echo "ERROR=usage: card-testing-fixture.php seed|cleanup|clear-cache|status\n";
	exit( 1 );
}

blzmcp_cardfx_cleanup();
$saved = array();
foreach ( array_keys( BLZMCP_CARDFX_SETTINGS ) as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
add_option( BLZMCP_CARDFX_SAVED, $saved, '', false );
foreach ( BLZMCP_CARDFX_SETTINGS as $name => $value ) {
	if ( null === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}

$now  = time();
$h    = HOUR_IN_SECONDS;
$d    = DAY_IN_SECONDS;
$n    = 0;
$net3 = array( '203.0.113.', '198.51.100.', '192.0.2.' );

// stripe: recent likely burst.
$n += blzmcp_cardfx_history( 'stripe', $now, 98, 20 * $d, 3 * $d, array( 'typein', 'organic', 'referral', null ) );
foreach ( array( 5, 9, 14 ) as $day ) {
	blzmcp_cardfx_order( 'stripe', 'checkout', 'failed', $now - $day * $d, '203.0.113.200', 'typein' );
	++$n;
}
for ( $i = 0; $i < 30; $i++ ) {
	blzmcp_cardfx_order( 'stripe', 0 === $i % 2 ? 'checkout' : 'store-api', 'failed', $now - 300 + 10 * $i, $net3[ $i % 3 ] . ( 1 + $i ), null );
	++$n;
}
// woocommerce_payments: earlier likely burst.
$n += blzmcp_cardfx_history( 'woocommerce_payments', $now, 101, 21 * $d, 4 * $d, array( 'typein', 'utm' ) );
for ( $i = 0; $i < 24; $i++ ) {
	blzmcp_cardfx_order( 'woocommerce_payments', 'checkout', 'failed', $now - 6 * $h - 1800 + 75 * $i, $net3[ $i % 2 ] . ( 100 + $i ), '(none)' );
	++$n;
}
// square_credit_card: too little history.
$n += blzmcp_cardfx_history( 'square_credit_card', $now, 8, 10 * $d, 2 * $d + 3 * $h, array( 'typein' ) );
for ( $i = 0; $i < 22; $i++ ) {
	blzmcp_cardfx_order( 'square_credit_card', 'checkout', 'failed', $now - 3 * $h - 1200 + 60 * $i, '2001:db8:' . dechex( 16 + $i ) . '::1', 'typein' );
	++$n;
}
// ppcp-gateway: no saved IP on the burst, and failed orders with no recorded origin.
$n += blzmcp_cardfx_history( 'ppcp-gateway', $now, 100, 20 * $d, 3 * $d, array( 'typein', 'organic' ) );
for ( $i = 0; $i < 25; $i++ ) {
	blzmcp_cardfx_order( 'ppcp-gateway', 'checkout', 'failed', $now - 1800 + 60 * $i, '', null );
	++$n;
}
for ( $i = 0; $i < 22; $i++ ) {
	blzmcp_cardfx_order( 'ppcp-gateway', '', 'failed', $now - 2400 + 90 * $i, '203.0.113.' . ( 150 + $i ), null );
	++$n;
}

// One fixture log file for the stripe family: 2 error entries 30 minutes ago.
$log_ok = 0;
$dir    = class_exists( 'Blaze\\McpToolkit\\Diagnostics\\LogSources' ) ? (string) \Blaze\McpToolkit\Diagnostics\LogSources::wc_log_dir() : '';
if ( '' !== $dir && ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) ) {
	$file  = rtrim( $dir, '/' ) . '/woocommerce-gateway-stripe-' . gmdate( 'Y-m-d', $now ) . '-' . BLZMCP_CARDFX_LOG_TAG . '.log';
	$lines = gmdate( 'c', $now - 1800 ) . " ERROR blzmcp card fixture gateway error\n" . gmdate( 'c', $now - 1790 ) . " ERROR blzmcp card fixture gateway error\n";
	$log_ok = false !== file_put_contents( $file, $lines ) ? 1 : 0;
}
blzmcp_cardfx_clear_cache();
echo 'SEEDED=' . $n . "\n";
echo 'STRIPE_RECENT_FAILED=30' . "\n";
echo 'WCPAY_EARLIER_FAILED=24' . "\n";
echo 'SQUARE_FAILED=22' . "\n";
echo 'PPCP_NO_IP_FAILED=25' . "\n";
echo 'PPCP_MISSING_ORIGIN_FAILED=22' . "\n";
echo 'LOG_FILE=' . $log_ok . "\n";
