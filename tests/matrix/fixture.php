<?php
/**
 * Deterministic order fixture for the real-plugin matrix.
 *
 *   wp eval-file wp-content/blzmcp-tests/matrix/fixture.php seed      Remove any old fixture, then seed.
 *   wp eval-file wp-content/blzmcp-tests/matrix/fixture.php cleanup   Remove every fixture order and product.
 *
 * Orders are created at site-local noon on day offsets from today (site timezone):
 * - current period, -1 … -6: three processing orders a day at 10.00, 25.00 and 40.00;
 * - previous period, -8 … -13: two processing orders a day at 10.00 and 20.00;
 * - two failed orders a day (15.00) on -1 … -3, one on-hold order (7.00) on -2;
 * - a 5.00 refund, dated -2 noon, on one -2 processing order.
 * Every order (and the refund) carries meta _blzmcp_fixture=1 and is imported into the Analytics
 * tables straight away. `seed` writes the run date, timezone and windows to tests/matrix/.window
 * and prints them as WINDOW_* lines; it also prints PRODUCT_ID.
 *
 * Customer data is synthetic (example.invalid addresses).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions, WordPress.DB

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;

const BLZMCP_FIXTURE_META = '_blzmcp_fixture';
const BLZMCP_FIXTURE_SKU  = 'blzmcp-matrix-fixture';

$blzmcp_fixture_cmd = (string) ( ( $args ?? array() )[0] ?? '' );
// No store emails for fixture orders (wp-env has no mail server).
add_filter( 'pre_wp_mail', '__return_false' );

/**
 * Removes every fixture order, refund and product, and their Analytics rows.
 */
function blzmcp_fixture_cleanup(): int {
	global $wpdb;
	$removed = 0;
	// Both order stores, whichever is authoritative (meta_query is not supported on the posts store).
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->postmeta, BLZMCP_FIXTURE_META, '1' ) ) );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s AND meta_value = %s', $wpdb->prefix . 'wc_orders_meta', BLZMCP_FIXTURE_META, '1' ) ) ) );
	}
	$ids = array_values( array_unique( $ids ) );
	rsort( $ids );
	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			foreach ( $order instanceof WC_Order ? $order->get_refunds() : array() as $refund ) {
				$wpdb->delete( $wpdb->prefix . 'wc_order_stats', array( 'order_id' => $refund->get_id() ) );
				$refund->delete( true );
			}
			$order->delete( true );
		}
		foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'order_id' => (int) $id ) );
		}
		++$removed;
	}
	$product_id = wc_get_product_id_by_sku( BLZMCP_FIXTURE_SKU );
	if ( $product_id ) {
		wp_delete_post( $product_id, true );
	}
	foreach ( get_users( array( 'search' => 'blzmcp-fixture-*', 'search_columns' => array( 'user_email' ) ) ) as $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
	\WC_Cache_Helper::get_transient_version( 'woocommerce_reports', true );
	return $removed;
}

/**
 * Local noon, $days_ago days before today, as a Unix timestamp.
 *
 * @param int $days_ago Days before today.
 */
function blzmcp_fixture_noon( int $days_ago ): int {
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	return $today->modify( '-' . $days_ago . ' days' )->setTime( 12, 0 )->getTimestamp();
}

/**
 * Creates one fixture order at local noon $days_ago days ago.
 *
 * @param int    $days_ago   Days before today.
 * @param float  $amount     Order total.
 * @param string $status     Order status (without wc-).
 * @param int    $product_id Fixture product.
 * @param int    $n          Sequence number (customer variety).
 */
function blzmcp_fixture_order( int $days_ago, float $amount, string $status, int $product_id, int $n ): WC_Order {
	$ts    = blzmcp_fixture_noon( $days_ago );
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$item  = new WC_Order_Item_Product();
	$item->set_product_id( $product_id );
	$item->set_name( 'Matrix fixture item' );
	$item->set_quantity( 1 );
	$item->set_subtotal( $amount );
	$item->set_total( $amount );
	$order->add_item( $item );
	$order->set_billing_email( 'blzmcp-fixture-' . ( $n % 4 ) . '@example.invalid' );
	$order->set_billing_first_name( 'Fixture' );
	$order->set_billing_last_name( 'Customer ' . ( $n % 4 ) );
	$order->set_billing_country( 'AU' );
	$order->set_payment_method( 'bacs' );
	$order->set_payment_method_title( 'Direct bank transfer' );
	$order->set_currency( get_woocommerce_currency() );
	$order->set_total( $amount );
	$order->set_date_created( $ts );
	$order->set_status( $status );
	if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
		$order->set_date_paid( $ts );
	}
	$order->update_meta_data( BLZMCP_FIXTURE_META, '1' );
	$order->save();
	OrdersScheduler::import( $order->get_id() );
	return $order;
}

if ( 'cleanup' === $blzmcp_fixture_cmd ) {
	echo 'REMOVED=' . blzmcp_fixture_cleanup() . "\n";
	return;
}
if ( 'seed' !== $blzmcp_fixture_cmd ) {
	echo "ERROR=usage: fixture.php seed|cleanup\n";
	exit( 1 );
}

blzmcp_fixture_cleanup();

$product = new WC_Product_Simple();
$product->set_name( 'Matrix Fixture Product' );
$product->set_sku( BLZMCP_FIXTURE_SKU );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product->set_description( 'Synthetic product for the Blaze MCP Toolkit release matrix.' );
$product->save();
$product_id = $product->get_id();

$n = 0;
for ( $d = 1; $d <= 6; $d++ ) {
	foreach ( array( 10.0, 25.0, 40.0 ) as $amount ) {
		$blzmcp_order = blzmcp_fixture_order( $d, $amount, 'processing', $product_id, ++$n );
		if ( 2 === $d && 25.0 === $amount ) {
			$refund = wc_create_refund(
				array(
					'order_id' => $blzmcp_order->get_id(),
					'amount'   => 5.0,
					'reason'   => 'Matrix fixture refund',
				)
			);
			if ( $refund instanceof WC_Order_Refund ) {
				$refund->set_date_created( blzmcp_fixture_noon( 2 ) );
				$refund->update_meta_data( BLZMCP_FIXTURE_META, '1' );
				$refund->save();
				OrdersScheduler::import( $refund->get_id() );
				OrdersScheduler::import( $blzmcp_order->get_id() );
			} else {
				echo 'ERROR=refund ' . ( is_wp_error( $refund ) ? $refund->get_error_code() : 'failed' ) . "\n";
				exit( 1 );
			}
		}
	}
}
for ( $d = 8; $d <= 13; $d++ ) {
	foreach ( array( 10.0, 20.0 ) as $amount ) {
		blzmcp_fixture_order( $d, $amount, 'processing', $product_id, ++$n );
	}
}
for ( $d = 1; $d <= 3; $d++ ) {
	blzmcp_fixture_order( $d, 15.0, 'failed', $product_id, ++$n );
	blzmcp_fixture_order( $d, 15.0, 'failed', $product_id, ++$n );
}
blzmcp_fixture_order( 2, 7.0, 'on-hold', $product_id, ++$n );
\WC_Cache_Helper::get_transient_version( 'woocommerce_reports', true );

$tz    = wp_timezone();
$today = new DateTimeImmutable( 'today', $tz );
$fmt   = static fn( int $days_ago ): string => $today->modify( '-' . $days_ago . ' days' )->format( 'Y-m-d' );
$window = array(
	'WINDOW_DATE'           => $today->format( 'Y-m-d' ),
	'WINDOW_TIMEZONE'       => $tz->getName(),
	'WINDOW_CURRENT_START'  => $fmt( 6 ),
	'WINDOW_CURRENT_END'    => $fmt( 1 ),
	'WINDOW_PREVIOUS_START' => $fmt( 13 ),
	'WINDOW_PREVIOUS_END'   => $fmt( 8 ),
);
$lines = '';
foreach ( $window as $key => $value ) {
	$lines .= $key . '=' . $value . "\n";
}
file_put_contents( __DIR__ . '/.window', $lines );
echo $lines;
echo 'PRODUCT_ID=' . $product_id . "\n";
echo 'SEEDED=' . $n . "\n";
