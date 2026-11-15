<?php
/**
 * Volume fixture for the payment-health cold performance budget (FEATURE-PLAN item 10).
 * Disposable test environments only (local wp-env or the matrix); never run it on a member store.
 *
 *   wp eval-file .../seed-payment-volume.php orders <offset> <count> <now>   Recent checkout orders (28 days)
 *   wp eval-file .../seed-payment-volume.php old <offset> <count> <now>      Older orders (30-400 days ago)
 *   wp eval-file .../seed-payment-volume.php verify <recent> <old>           Counts in the authoritative store, plus a CRUD sample
 *   wp eval-file .../seed-payment-volume.php diag <file|db> <now>            Near-budget Stripe logs and failed jobs, logger switched
 *   wp eval-file .../seed-payment-volume.php cleanup                         Remove every order, log and job this fixture made
 *   wp eval-file .../seed-payment-volume.php distribution                   Print the seed distribution as JSON
 *
 * Orders go through WooCommerce CRUD (new WC_Order, one save), origin `checkout`, tagged with
 * meta `_blzmcp_perf=1`. Order i of N is created at now - 60 s - i * (28 days / N), so the recent
 * orders cover the whole 28-day history evenly. Methods and statuses come from a fixed cycle of 20
 * (DISTRIBUTION below). Seeding is chunked so each PHP process stays small; seed time is never part
 * of a measurement. Analytics import and order emails are switched off in the seeding process only.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

const BLZMCP_PERF_META   = '_blzmcp_perf';
const BLZMCP_PERF_PREFIX = 'BLZMCP-PERF ';
const BLZMCP_PERF_SAVED  = 'blzmcp_perf_saved_settings';

/*
 * Cycle of 20: [method, status, paid?, transaction reference?]. Per 20 orders: stripe 12 (9 paid,
 * 1 pending, 1 pending with a reference, 1 cancelled unpaid), cod 5 (3 processing paid, 1 on-hold,
 * 1 failed), cheque 3 (2 on-hold, 1 completed paid).
 */
const BLZMCP_PERF_CYCLE = array(
	array( 'stripe', 'processing', true, true ),
	array( 'stripe', 'completed', true, true ),
	array( 'cod', 'processing', true, false ),
	array( 'stripe', 'processing', true, true ),
	array( 'cheque', 'on-hold', false, false ),
	array( 'stripe', 'pending', false, false ),
	array( 'stripe', 'completed', true, true ),
	array( 'cod', 'on-hold', false, false ),
	array( 'stripe', 'processing', true, true ),
	array( 'stripe', 'cancelled', false, false ),
	array( 'cod', 'processing', true, false ),
	array( 'stripe', 'processing', true, true ),
	array( 'cheque', 'completed', true, false ),
	array( 'stripe', 'pending', false, true ),
	array( 'cod', 'failed', false, false ),
	array( 'stripe', 'completed', true, true ),
	array( 'cheque', 'on-hold', false, false ),
	array( 'stripe', 'processing', true, true ),
	array( 'cod', 'processing', true, false ),
	array( 'stripe', 'processing', true, true ),
);

$blzmcp_perf_args = array_values( (array) ( $args ?? array() ) );
$blzmcp_perf_cmd  = (string) ( $blzmcp_perf_args[0] ?? '' );

/**
 * Seeding process only: no mail, no HTTP, no Analytics import scheduling, no object cache growth.
 */
function blzmcp_perf_quiet(): void {
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_perf_blocked', 'blocked in the volume fixture' ), 1 );
	foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_order_status_changed', 'woocommerce_payment_complete' ) as $hook ) {
		remove_all_actions( $hook );
	}
	wp_suspend_cache_addition( true );
}

/**
 * Create one order through CRUD with a single save.
 *
 * @param int $i       Sequence number.
 * @param int $created Created (and paid) time.
 */
function blzmcp_perf_order( int $i, int $created ): void {
	list( $method, $status, $paid, $txn ) = BLZMCP_PERF_CYCLE[ $i % 20 ];
	$order                                = new WC_Order();
	$order->set_created_via( 'checkout' );
	$order->set_payment_method( $method );
	$order->set_currency( 'AUD' );
	$order->set_total( (string) ( 10 + ( $i % 90 ) ) . '.95' );
	$order->set_billing_email( 'blzmcp-perf-' . ( $i % 50 ) . '@example.invalid' );
	$order->set_date_created( $created );
	$order->set_status( $status );
	$order->set_date_paid( $paid ? $created + 30 : null );
	if ( $txn ) {
		$order->set_transaction_id( 'pi_synthetic_' . $i );
	}
	$order->update_meta_data( BLZMCP_PERF_META, '1' );
	$order->save();
}

/**
 * Tagged order IDs in either order store.
 *
 * @return array<int,int>
 */
function blzmcp_perf_ids(): array {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, BLZMCP_PERF_META ) ) );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders_meta' ) ) ) ) {
		$ids = array_merge( $ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM %i WHERE meta_key = %s', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META ) ) ) );
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Delete tagged rows from both stores in chunks (test-only, scoped to the fixture's own IDs).
 */
function blzmcp_perf_cleanup( bool $orders = true ): int {
	global $wpdb;
	$ids  = $orders ? blzmcp_perf_ids() : array();
	$hpos = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_orders' ) ) );
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		$wpdb->query( "DELETE im FROM {$wpdb->prefix}woocommerce_order_itemmeta im JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = im.order_item_id WHERE oi.order_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type IN ('shop_order', 'shop_order_placehold')" );
		if ( $hpos ) {
			foreach ( array( 'wc_orders_meta' => 'order_id', 'wc_order_addresses' => 'order_id', 'wc_order_operational_data' => 'order_id', 'wc_orders' => 'id' ) as $table => $col ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE {$col} IN ({$in})" );
			}
		}
		foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table} WHERE order_id IN ({$in})" );
		}
	}
	// Logs and failed jobs this fixture made (message prefix / args marker), then the logger setting.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'woocommerce_log' ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE message LIKE %s', $wpdb->prefix . 'woocommerce_log', $wpdb->esc_like( BLZMCP_PERF_PREFIX ) . '%' ) );
	}
	$dir = blzmcp_perf_log_dir();
	foreach ( null === $dir ? array() : (array) glob( $dir . '/woocommerce-gateway-stripe-*-blzmcpperf*.log' ) as $file ) {
		unlink( $file );
	}
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l JOIN %i a ON a.action_id = l.action_id WHERE a.args LIKE %s', $wpdb->prefix . 'actionscheduler_logs', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE args LIKE %s', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) );
	}
	$saved = get_option( BLZMCP_PERF_SAVED, null );
	if ( is_array( $saved ) ) {
		foreach ( $saved as $name => $value ) {
			null === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		delete_option( BLZMCP_PERF_SAVED );
	}
	wp_cache_flush_group( 'orders' );
	return count( $ids );
}

/** WooCommerce's resolved log directory, or null. */
function blzmcp_perf_log_dir(): ?string {
	$dir = class_exists( '\Automattic\WooCommerce\Utilities\LoggingUtil' ) ? \Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory() : null;
	return is_string( $dir ) && '' !== $dir ? rtrim( $dir, '/' ) : null;
}

/**
 * Near-budget Stripe logs for the chosen handler, plus failed Stripe jobs inside the window.
 *
 * @param string $handler file|db.
 * @param int    $now     Reference time.
 */
function blzmcp_perf_diag( string $handler, int $now ): array {
	global $wpdb;
	if ( ! is_array( get_option( BLZMCP_PERF_SAVED, null ) ) ) {
		update_option(
			BLZMCP_PERF_SAVED,
			array(
				'woocommerce_logs_default_handler'   => get_option( 'woocommerce_logs_default_handler', null ),
				'woocommerce_logs_logging_enabled'   => get_option( 'woocommerce_logs_logging_enabled', null ),
				'woocommerce_logs_level_threshold'   => get_option( 'woocommerce_logs_level_threshold', null ),
				'woocommerce_logs_retention_period_days' => get_option( 'woocommerce_logs_retention_period_days', null ),
			),
			false
		);
	}
	update_option( 'woocommerce_logs_logging_enabled', 'yes' );
	update_option( 'woocommerce_logs_level_threshold', 'none' );
	update_option( 'woocommerce_logs_retention_period_days', 30 );
	update_option( 'woocommerce_logs_default_handler', 'db' === $handler ? 'WC_Log_Handler_DB' : 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2' );
	$out = array( 'handler' => $handler );
	if ( 'file' === $handler ) {
		$dir = blzmcp_perf_log_dir();
		if ( null === $dir ) {
			return array( 'error' => 'no log directory' );
		}
		wp_mkdir_p( $dir );
		$bytes = 0;
		// 7 files of about 720 KB each: 5.04 MB of the 5,242,880-byte shared budget.
		for ( $f = 0; $f < 7; $f++ ) {
			$day  = $now - $f * DAY_IN_SECONDS;
			$path = $dir . '/woocommerce-gateway-stripe-' . gmdate( 'Y-m-d', $day ) . '-blzmcpperf' . $f . '.log';
			$buf  = '';
			for ( $l = 0; strlen( $buf ) < 720000; $l++ ) {
				$ts    = gmdate( 'Y-m-d\TH:i:s', $day - $l * 20 ) . '+00:00';
				$level = 0 === $l % 25 ? 'ERROR' : 'INFO';
				$buf  .= $ts . ' ' . $level . ' ' . BLZMCP_PERF_PREFIX . 'synthetic gateway line ' . $l . ' ' . str_repeat( 'x', 120 ) . "\n";
			}
			file_put_contents( $path, $buf );
			$bytes += strlen( $buf );
		}
		$out['log_files'] = 7;
		$out['log_bytes'] = $bytes;
	} else {
		$table = $wpdb->prefix . 'woocommerce_log';
		$rows  = 0;
		// 2,000 error rows of about 2 KB (the row cap) plus 1 more for the sentinel, and info noise.
		for ( $r = 0; $r < 2600; $r++ ) {
			$wpdb->insert(
				$table,
				array(
					'timestamp' => gmdate( 'Y-m-d H:i:s', $now - 60 - $r * 30 ),
					'level'     => $r < 2001 ? 500 : 200,
					'source'    => 'woocommerce-gateway-stripe',
					'message'   => BLZMCP_PERF_PREFIX . 'synthetic gateway error ' . $r . ' ' . str_repeat( 'y', 2000 ),
				)
			);
			++$rows;
		}
		$out['log_rows'] = $rows;
	}
	// 40 failed Stripe jobs inside the last 24 hours (marked so cleanup finds them).
	$jobs = 0;
	if ( class_exists( 'ActionScheduler' ) ) {
		$store = ActionScheduler::store();
		for ( $j = 0; $j < 40; $j++ ) {
			$id = $store->save_action( new ActionScheduler_Action( 'wc_stripe_deferred_webhook', array( 'blzmcp_perf' => $j ), new ActionScheduler_SimpleSchedule( new ActionScheduler_DateTime( '@' . ( $now - 3600 - $j * 600 ) ) ), 'woocommerce_stripe' ) );
			$store->mark_failure( $id );
			++$jobs;
		}
		// mark_failure() leaves the attempt time empty; the reader windows by last attempt.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_attempt_gmt = scheduled_date_gmt, last_attempt_local = scheduled_date_local WHERE args LIKE %s', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) );
		$out['failed_jobs'] = $jobs;
		$out['action_store'] = get_class( $store );
	}
	return $out;
}

switch ( $blzmcp_perf_cmd ) {
	case 'orders':
	case 'old':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$total  = (int) ( $blzmcp_perf_args[4] ?? 50000 );
		for ( $i = $offset; $i < $offset + $count; $i++ ) {
			$created = 'orders' === $blzmcp_perf_cmd
				? $now - 60 - (int) floor( $i * ( 28 * DAY_IN_SECONDS - 120 ) / max( 1, $total ) )
				: $now - 30 * DAY_IN_SECONDS - (int) floor( $i * ( 370 * DAY_IN_SECONDS ) / max( 1, $total ) );
			blzmcp_perf_order( $i, $created );
		}
		echo 'SEEDED=' . $count . "\n";
		break;
	case 'verify':
		global $wpdb;
		$want_recent = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$want_old    = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$hpos        = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$ids         = $hpos
			? $wpdb->get_col( $wpdb->prepare( 'SELECT o.id FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s WHERE o.type = %s', $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META, 'shop_order' ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s', $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, 'shop_order' ) );
		$sample      = array_slice( $ids, 0, 25 );
		$crud_ok     = 0;
		foreach ( $sample as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o instanceof WC_Order && '1' === (string) $o->get_meta( BLZMCP_PERF_META ) && 'checkout' === $o->get_created_via() ) {
				++$crud_ok;
			}
		}
		echo 'VERIFY=' . wp_json_encode(
			array(
				'authority'   => $hpos ? 'hpos' : 'legacy',
				'sync'        => get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ),
				'orders'      => count( $ids ),
				'expected'    => $want_recent + $want_old,
				'crud_sample' => count( $sample ),
				'crud_ok'     => $crud_ok,
				'ok'          => count( $ids ) === $want_recent + $want_old && count( $sample ) === $crud_ok,
			)
		) . "\n";
		break;
	case 'diag':
		echo 'DIAG=' . wp_json_encode( blzmcp_perf_diag( (string) ( $blzmcp_perf_args[1] ?? 'file' ), (int) ( $blzmcp_perf_args[2] ?? time() ) ) ) . "\n";
		break;
	case 'cleanup':
		echo 'REMOVED=' . blzmcp_perf_cleanup() . "\n";
		break;
	case 'diag-cleanup':
		blzmcp_perf_cleanup( false );
		echo "REMOVED=diagnostics\n";
		break;
	case 'distribution':
		$per = array();
		foreach ( BLZMCP_PERF_CYCLE as $row ) {
			$key         = $row[0] . ' ' . $row[1] . ( $row[2] ? ' paid' : ' unpaid' ) . ( $row[3] ? ' with reference' : '' );
			$per[ $key ] = ( $per[ $key ] ?? 0 ) + 1;
		}
		echo 'DISTRIBUTION=' . wp_json_encode( array( 'cycle' => 20, 'per_cycle' => $per ) ) . "\n";
		break;
	default:
		echo "ERROR=usage: seed-payment-volume.php orders|old|verify|diag|cleanup|distribution\n";
		exit( 1 );
}
