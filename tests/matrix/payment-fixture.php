<?php
/**
 * Synthetic payment-health fixture for the real-plugin matrix HTTP suite. Disposable matrix
 * environments only; never run it on a member store.
 *
 *   wp eval-file wp-content/blzmcp-tests/matrix/payment-fixture.php seed         Remove any old payment fixture, then seed.
 *   wp eval-file wp-content/blzmcp-tests/matrix/payment-fixture.php cleanup      Remove the payment fixture orders and restore the gateway settings.
 *   wp eval-file wp-content/blzmcp-tests/matrix/payment-fixture.php clear-cache  Delete the toolkit's own result cache (blzmcp_c_ transients) only.
 *
 * Seeded through WooCommerce CRUD, origin `checkout`, tagged with meta `_blzmcp_payfx=1`:
 * - `cod` (enabled): 4 accepted orders a day at 09.00, 12.00, 15.00 and 18.00 site-local over the
 *   last 28 days, up to the latest slot more than an hour before now. Store-wide orders keep coming.
 * - `cheque` (enabled): 1 accepted order a day at 11.00 on days -27 … -8, then nothing. Its own
 *   rate makes the 8-day silence red (method_quiet).
 * - `blzmcp_gone` (no gateway registers it): 12 accepted orders on days -27 … -16. It gets the
 *   "not currently registered" warning (method_not_registered).
 * The cod and cheque settings options are saved first and put back by `cleanup`; no other option is
 * written. These orders are created after the fixed Analytics comparisons have run, and `cleanup`
 * also removes their Analytics rows, so the fixture.php figures are unaffected.
 *
 * Customer data is synthetic (example.invalid addresses).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

defined( 'ABSPATH' ) || exit;

const BLZMCP_PAYFX_META  = '_blzmcp_payfx';
const BLZMCP_PAYFX_SAVED = 'blzmcp_payfx_saved_settings';

$blzmcp_payfx_cmd = (string) ( ( $args ?? array() )[0] ?? '' );
add_filter( 'pre_wp_mail', '__return_false' );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_payfx_blocked', 'blocked in the payment fixture' ), 1 );

/**
 * Removes every payment fixture order (both order stores) and its Analytics rows, then restores
 * the gateway settings saved by `seed`.
 */
function blzmcp_payfx_cleanup(): int {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, BLZMCP_PAYFX_META, '1' ) ) );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PAYFX_META, '1' ) ) ) );
	}
	$ids = array_values( array_unique( $ids ) );
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
		foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'order_id' => $id ) );
		}
	}
	$saved = get_option( BLZMCP_PAYFX_SAVED, null );
	if ( is_array( $saved ) ) {
		foreach ( $saved as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		delete_option( BLZMCP_PAYFX_SAVED );
	}
	\WC_Cache_Helper::get_transient_version( 'woocommerce_reports', true );
	return count( $ids );
}

/**
 * Deletes the toolkit's own result cache entries one by one (no whole-cache flush).
 */
function blzmcp_payfx_clear_cache(): int {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%' ) );
	foreach ( $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
	return count( $names );
}

/**
 * Site-local timestamp for $days_ago days before today at $hour:00.
 *
 * @param int $days_ago Days before today.
 * @param int $hour     Local hour.
 */
function blzmcp_payfx_at( int $days_ago, int $hour ): int {
	return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . $days_ago . ' days' )->setTime( $hour, 0 )->getTimestamp();
}

/**
 * One accepted checkout order through CRUD.
 *
 * @param string $method Payment method ID.
 * @param int    $ts     Created and paid time.
 * @param int    $n      Sequence number.
 */
function blzmcp_payfx_order( string $method, int $ts, int $n ): void {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->set_created_via( 'checkout' );
	$order->set_payment_method( $method );
	$order->set_billing_email( 'blzmcp-payfx-' . ( $n % 3 ) . '@example.invalid' );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_total( '12.50' );
	$order->set_date_created( $ts );
	$order->update_meta_data( BLZMCP_PAYFX_META, '1' );
	$order->set_status( 'processing' );
	$order->save();
	$order->set_date_paid( $ts );
	$order->save();
}

if ( 'cleanup' === $blzmcp_payfx_cmd ) {
	echo 'REMOVED=' . blzmcp_payfx_cleanup() . "\n";
	return;
}
if ( 'clear-cache' === $blzmcp_payfx_cmd ) {
	echo 'CLEARED=' . blzmcp_payfx_clear_cache() . "\n";
	return;
}
if ( 'seed' !== $blzmcp_payfx_cmd ) {
	echo "ERROR=usage: payment-fixture.php seed|cleanup|clear-cache\n";
	exit( 1 );
}

blzmcp_payfx_cleanup();
$saved = array();
foreach ( array( 'woocommerce_cod_settings', 'woocommerce_cheque_settings' ) as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
update_option( BLZMCP_PAYFX_SAVED, $saved, false );
foreach ( array( 'woocommerce_cod_settings', 'woocommerce_cheque_settings' ) as $name ) {
	$value            = is_array( $saved[ $name ] ) ? $saved[ $name ] : array();
	$value['enabled'] = 'yes';
	update_option( $name, $value );
}

$now = time();
$n   = 0;
for ( $d = 27; $d >= 0; $d-- ) {
	foreach ( array( 9, 12, 15, 18 ) as $hour ) {
		$ts = blzmcp_payfx_at( $d, $hour );
		if ( $ts < $now - HOUR_IN_SECONDS ) {
			blzmcp_payfx_order( 'cod', $ts, ++$n );
		}
	}
}
for ( $d = 27; $d >= 8; $d-- ) {
	blzmcp_payfx_order( 'cheque', blzmcp_payfx_at( $d, 11 ), ++$n );
}
for ( $d = 27; $d >= 16; $d-- ) {
	blzmcp_payfx_order( 'blzmcp_gone', blzmcp_payfx_at( $d, 10 ), ++$n );
}
blzmcp_payfx_clear_cache();
echo 'SEEDED=' . $n . "\n";
