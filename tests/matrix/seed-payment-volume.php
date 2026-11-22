<?php
/**
 * Volume fixture for the payment-health cold performance budget (FEATURE-PLAN item 10).
 * Disposable test environments only (local wp-env or the matrix); never run it on a member store.
 *
 *   wp eval-file .../seed-payment-volume.php orders <offset> <count> <now>   Recent checkout orders (28 days)
 *   wp eval-file .../seed-payment-volume.php old <offset> <count> <now>      Older orders (30-400 days ago)
 *   wp eval-file .../seed-payment-volume.php outside <offset> <count> <now> <total>
 *                                                                            Excluded-origin orders (missing or non-checkout origin, 27 days)
 *   wp eval-file .../seed-payment-volume.php cap <offset> <count> <now>      Method-cap stress: one distinct outside method ID per order
 *                                                                            (offset 0 also seeds the cod checkout control orders)
 *   wp eval-file .../seed-payment-volume.php verify <recent> <old> [outside] Counts in the authoritative store (checkout and excluded
 *                                                                            origin separately), plus a CRUD sample
 *   wp eval-file .../seed-payment-volume.php diag <file|db> <now>            Near-budget Stripe logs and failed jobs, logger switched
 *   wp eval-file .../seed-payment-volume.php cleanup                         Remove every order, log and job this fixture made
 *   wp eval-file .../seed-payment-volume.php diag-cleanup                    Remove only the logs, jobs and logger settings
 *                                                                            (both print CLEANUP=<json>: what was removed and what remains)
 *   wp eval-file .../seed-payment-volume.php distribution                   Print the seed distribution as JSON
 *
 * Orders go through WooCommerce CRUD (new WC_Order, one save), origin `checkout`, tagged with
 * meta `_blzmcp_perf=1`. Order i of N is created at now - 60 s - i * (28 days / N), so the recent
 * orders cover the whole 28-day history evenly. Methods and statuses come from a fixed cycle of 20
 * (DISTRIBUTION below). The excluded-origin population (1.2.1) and the method-cap fixture use the
 * same tag and come from payment-volume-cycle.php (BLZMCP_PERF_OUTSIDE, blzmcp_perf_cap_id()); they
 * never replace the checkout volume. Seeding is chunked so each PHP process stays small; seed time is never part
 * of a measurement. Analytics import and order emails are switched off in the seeding process only.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

// The cycle, the timeline and the expected counts are shared with measure-payment-health.php.
require_once __DIR__ . '/payment-volume-cycle.php';

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
	$order->set_date_paid( $paid ? $created + BLZMCP_PERF_PAID_AFTER : null );
	if ( $txn ) {
		$order->set_transaction_id( 'pi_synthetic_' . $i );
	}
	$order->update_meta_data( BLZMCP_PERF_META, '1' );
	$order->save();
}

/**
 * One paid, processing order outside verified checkout activity, through CRUD with a single save.
 *
 * @param string $method  Payment method ID.
 * @param string $via     Recorded origin ('' = empty).
 * @param int    $created Created time (paid 30 s later).
 * @param int    $i       Sequence number.
 */
function blzmcp_perf_outside_order( string $method, string $via, int $created, int $i ): void {
	$order = new WC_Order();
	$order->set_created_via( $via );
	$order->set_payment_method( $method );
	$order->set_currency( 'AUD' );
	$order->set_total( (string) ( 10 + ( $i % 90 ) ) . '.50' );
	$order->set_billing_email( 'blzmcp-perf-out-' . ( $i % 50 ) . '@example.invalid' );
	$order->set_date_created( $created );
	$order->set_status( 'processing' );
	$order->set_date_paid( $created + BLZMCP_PERF_PAID_AFTER );
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

/**
 * What is left of the fixture after a cleanup, as a machine-readable result (wave 4: the cleanup
 * outcome is part of the performance receipt).
 *
 * @param string   $mode    orders|diagnostics.
 * @param int|null $removed Orders removed (null for a diagnostics-only cleanup).
 * @return array<string,mixed>
 */
function blzmcp_perf_cleanup_result( string $mode, ?int $removed ): array {
	global $wpdb;
	$logs  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'woocommerce_log' ) ) )
		? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE message LIKE %s', $wpdb->prefix . 'woocommerce_log', $wpdb->esc_like( BLZMCP_PERF_PREFIX ) . '%' ) )
		: 0;
	$dir   = blzmcp_perf_log_dir();
	$files = null === $dir ? 0 : count( (array) glob( $dir . '/woocommerce-gateway-stripe-*-blzmcpperf*.log' ) );
	$jobs  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_actions' ) ) )
		? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE args LIKE %s', $wpdb->prefix . 'actionscheduler_actions', '%blzmcp_perf%' ) )
		: 0;
	$tagged   = count( blzmcp_perf_ids() );
	$settings = null === get_option( BLZMCP_PERF_SAVED, null );
	$diag_ok  = 0 === $logs && 0 === $files && 0 === $jobs && $settings;
	return array(
		'mode'                     => $mode,
		'orders_removed'           => $removed,
		'tagged_remaining'         => $tagged,
		'diag_log_rows_remaining'  => $logs,
		'diag_log_files_remaining' => $files,
		'diag_jobs_remaining'      => $jobs,
		'settings_restored'        => $settings,
		'ok'                       => $diag_ok && ( 'diagnostics' === $mode || 0 === $tagged ),
	);
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
			blzmcp_perf_order( $i, blzmcp_perf_created( $blzmcp_perf_cmd, $i, $now, $total ) );
		}
		echo 'SEEDED=' . $count . "\n";
		break;
	case 'outside':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$total  = (int) ( $blzmcp_perf_args[4] ?? 1000 );
		for ( $i = $offset; $i < $offset + $count; $i++ ) {
			list( $method, $via ) = BLZMCP_PERF_OUTSIDE[ $i % count( BLZMCP_PERF_OUTSIDE ) ];
			blzmcp_perf_outside_order( $method, $via, blzmcp_perf_outside_created( $i, $now, $total ), $i );
		}
		echo 'SEEDED=' . $count . "\n";
		break;
	case 'cap':
		blzmcp_perf_quiet();
		$offset = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$count  = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$now    = (int) ( $blzmcp_perf_args[3] ?? time() );
		$made   = 0;
		if ( 0 === $offset ) {
			// The checkout control: cod checkout orders the cap must never change, 12 hours apart so
			// they span more than the 7 days the store-wide quiet check needs for complete coverage.
			for ( $c = 0; $c < BLZMCP_PERF_CAP_CONTROL; $c++ ) {
				$control = new WC_Order();
				$control->set_created_via( 'checkout' );
				$control->set_payment_method( 'cod' );
				$control->set_currency( 'AUD' );
				$control->set_total( '20.00' );
				$control->set_billing_email( 'blzmcp-perf-cap@example.invalid' );
				$control->set_date_created( $now - 7200 - $c * 43200 );
				$control->set_status( 'processing' );
				$control->set_date_paid( $now - 7200 - $c * 43200 + BLZMCP_PERF_PAID_AFTER );
				$control->update_meta_data( BLZMCP_PERF_META, '1' );
				$control->save();
				++$made;
			}
		}
		for ( $n = $offset; $n < $offset + $count; $n++ ) {
			blzmcp_perf_outside_order( blzmcp_perf_cap_id( $n ), 0 === $n % 2 ? '' : 'admin', $now - 3600 - $n * 300, $n );
			++$made;
		}
		echo 'SEEDED=' . $made . "\n";
		break;
	case 'verify':
		global $wpdb;
		$want_recent = (int) ( $blzmcp_perf_args[1] ?? 0 );
		$want_old    = (int) ( $blzmcp_perf_args[2] ?? 0 );
		$want_out    = (int) ( $blzmcp_perf_args[3] ?? 0 );
		$hpos        = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$ids         = $hpos
			? $wpdb->get_col( $wpdb->prepare( 'SELECT o.id FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s WHERE o.type = %s', $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META, 'shop_order' ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = %s', $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, 'shop_order' ) );
		// Other orders would change the counts the measurement must see exactly (FEATURE-PLAN item 10).
		$untagged    = (int) ( $hpos
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i o WHERE o.type = %s AND o.status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'checkout-draft') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.order_id = o.id AND m.meta_key = %s)", $wpdb->prefix . 'wc_orders', 'shop_order', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft', 'checkout-draft') AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s)", $wpdb->posts, 'shop_order', $wpdb->postmeta, BLZMCP_PERF_META ) ) );
		// Checkout and excluded-origin orders counted separately, by the recorded origin.
		$checkout_n  = (int) ( $hpos
			? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i o JOIN %i m ON m.order_id = o.id AND m.meta_key = %s JOIN %i d ON d.order_id = o.id WHERE o.type = %s AND d.created_via = %s', $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_orders_meta', BLZMCP_PERF_META, $wpdb->prefix . 'wc_order_operational_data', 'shop_order', 'checkout' ) )
			: $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s JOIN %i v ON v.post_id = p.ID AND v.meta_key = %s WHERE p.post_type = %s AND v.meta_value = %s', $wpdb->posts, $wpdb->postmeta, BLZMCP_PERF_META, $wpdb->postmeta, '_created_via', 'shop_order', 'checkout' ) ) );
		$outside_n   = count( $ids ) - $checkout_n;
		// A CRUD sample of checkout orders and of excluded-origin orders.
		$sample      = array_merge( array_slice( $ids, 0, 25 ), array_slice( $ids, -10 ) );
		$crud_ok     = 0;
		foreach ( $sample as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o instanceof WC_Order && '1' === (string) $o->get_meta( BLZMCP_PERF_META ) ) {
				++$crud_ok;
			}
		}
		echo 'VERIFY=' . wp_json_encode(
			array(
				'authority'   => $hpos ? 'hpos' : 'legacy',
				'sync'        => get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' ),
				'orders'      => count( $ids ),
				'expected'    => $want_recent + $want_old + $want_out,
				'checkout'    => $checkout_n,
				'outside'     => $outside_n,
				'crud_sample' => count( $sample ),
				'crud_ok'     => $crud_ok,
				'untagged'    => $untagged,
				'ok'          => count( $ids ) === $want_recent + $want_old + $want_out && $checkout_n === $want_recent + $want_old && $outside_n === $want_out && count( $sample ) === $crud_ok && 0 === $untagged,
			)
		) . "\n";
		break;
	case 'diag':
		echo 'DIAG=' . wp_json_encode( blzmcp_perf_diag( (string) ( $blzmcp_perf_args[1] ?? 'file' ), (int) ( $blzmcp_perf_args[2] ?? time() ) ) ) . "\n";
		break;
	case 'cleanup':
		$removed = blzmcp_perf_cleanup();
		echo 'REMOVED=' . $removed . "\n";
		echo 'CLEANUP=' . wp_json_encode( blzmcp_perf_cleanup_result( 'orders', $removed ) ) . "\n";
		break;
	case 'diag-cleanup':
		blzmcp_perf_cleanup( false );
		echo "REMOVED=diagnostics\n";
		echo 'CLEANUP=' . wp_json_encode( blzmcp_perf_cleanup_result( 'diagnostics', null ) ) . "\n";
		break;
	case 'distribution':
		$per = array();
		foreach ( BLZMCP_PERF_CYCLE as $row ) {
			$key         = $row[0] . ' ' . $row[1] . ( $row[2] ? ' paid' : ' unpaid' ) . ( $row[3] ? ' with reference' : '' );
			$per[ $key ] = ( $per[ $key ] ?? 0 ) + 1;
		}
		echo 'DISTRIBUTION=' . wp_json_encode( array( 'cycle' => 20, 'per_cycle' => $per, 'outside_cycle' => array_map( static fn( $r ) => $r[0] . ' origin ' . ( '' === $r[1] ? '(empty)' : $r[1] ), BLZMCP_PERF_OUTSIDE ) ) ) . "\n";
		break;
	default:
		echo "ERROR=usage: seed-payment-volume.php orders|old|outside|cap|verify|diag|cleanup|diag-cleanup|distribution\n";
		exit( 1 );
}
