<?php
/**
 * Read-only order aggregates for payment health, from the authoritative WooCommerce store only.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow, documented SQL exception (FEATURE-PLAN R6): scans compact scalar columns of eligible
 * `shop_order` rows in id-keyset chunks and folds them into counts, timestamps and 15-minute UTC
 * bucket aggregates. Never materialises WC_Order objects across the history, never joins
 * customer data, never reads both stores and never falls back to Analytics tables, notes or a
 * shadow store. Only a bounded example set is revalidated through wc_get_order().
 *
 * Field and precedence choices are pinned by item 1 (tests/fixtures/payment-gateway-contracts.json):
 * HPOS reads wc_orders + wc_order_operational_data; legacy reads posts + the first (lowest
 * meta_id) postmeta row of each key, `_date_paid` before `_paid_date`.
 */
final class PaymentOrders {

	/** Rows per keyset chunk. */
	private const CHUNK = 5000;

	/** Safety bound on rows scanned in one call; beyond it the result is partial. */
	public const MAX_ROWS = 500000;

	/** Examples per method. */
	public const MAX_EXAMPLES = 5;

	/** Accepted origins (C4). */
	private const ORIGINS = array( 'checkout', 'store-api' );

	/** Current statuses that count as accepted without a paid timestamp. */
	private const TAKEN_STATUSES = array( 'processing', 'completed', 'on-hold' );

	private const HPOS_STORE   = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore';
	private const LEGACY_STORE = 'WC_Order_Data_Store_CPT';

	/**
	 * Which core store is authoritative in this process, verified against the loaded data store.
	 *
	 * @return array{ok:bool,authority:?string,reason:?string}
	 */
	public static function authority(): array {
		$fail = static fn( string $reason ): array => array(
			'ok'        => false,
			'authority' => null,
			'reason'    => $reason,
		);
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) || ! class_exists( '\WC_Data_Store' ) ) {
			return $fail( 'orders_unavailable' );
		}
		try {
			$hpos  = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$class = \WC_Data_Store::load( 'order' )->get_current_class_name();
		} catch ( \Throwable $e ) {
			return $fail( 'orders_unavailable' );
		}
		$class = ltrim( (string) $class, '\\' );
		if ( $hpos && self::HPOS_STORE !== $class ) {
			return $fail( 'unsupported_data_store' );
		}
		if ( ! $hpos && self::LEGACY_STORE !== $class ) {
			return $fail( 'unsupported_data_store' );
		}
		global $wpdb;
		$tables = $hpos ? array( $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_order_operational_data' ) : array( $wpdb->posts, $wpdb->postmeta );
		foreach ( $tables as $table ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only existence check; the ability caches its result.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== $wpdb->last_error ) {
				return $fail( 'read_failed' );
			}
			if ( $table !== $found ) {
				return $fail( 'tables_missing' );
			}
		}
		return array(
			'ok'        => true,
			'authority' => $hpos ? 'hpos' : 'legacy',
			'reason'    => null,
		);
	}

	/**
	 * Empty counts for one cohort.
	 *
	 * @return array{pending:int,cancelled_unpaid:int,taken:int,transaction_recorded_unpaid:int}
	 */
	private static function cohort(): array {
		return array(
			'pending'                     => 0,
			'cancelled_unpaid'            => 0,
			'taken'                       => 0,
			'transaction_recorded_unpaid' => 0,
		);
	}

	/**
	 * Empty aggregate for one scope (store-wide or one method).
	 *
	 * @return array<string,mixed>
	 */
	private static function scope(): array {
		return array(
			'series'      => PaymentRules::empty_series(),
			'current'     => self::cohort(),
			'baseline'    => self::cohort(),
			'failed'      => 0,
			'accepted_7d' => 0,
			'invalid'     => 0,
			'complete'    => true,
			'candidates'  => array(),
			'stuck_total' => 0,
		);
	}

	/**
	 * Aggregates for one uncached call.
	 *
	 * @param int           $now         Captured UTC now.
	 * @param int           $since_hours Rolling window (1-168).
	 * @param \DateTimeZone $tz          Store timezone (for legacy site-local paid dates).
	 * @return array<string,mixed> See FIX-LOG-core.md for the shape.
	 */
	public static function read( int $now, int $since_hours, \DateTimeZone $tz ): array {
		$windows = PaymentRules::cohorts( $now, $since_hours );
		$auth    = self::authority();
		$out     = array(
			'ok'        => false,
			'authority' => $auth['authority'],
			'reason'    => $auth['reason'],
			'coverage'  => 'unavailable',
			'windows'   => $windows,
			'store'     => null,
			'methods'   => array(),
			'excluded'  => array(
				'unknown_origin'     => 0,
				'other_origin'       => 0,
				'invalid_timestamps' => 0,
				'drafts'             => 0,
				'empty_method'       => 0,
			),
			'rows_read' => 0,
		);
		if ( ! $auth['ok'] ) {
			return $out;
		}

		if ( 'hpos' === $auth['authority'] ) {
			$probe = self::hpos_missing_operational_row( $windows['baseline_start'] );
			if ( null === $probe ) {
				$out['reason'] = 'read_failed';
				return $out;
			}
			if ( $probe ) {
				$out['reason'] = 'operational_data_missing';
				return $out;
			}
		}

		$store   = self::scope();
		$methods = array();
		$last_id = 0;
		$rows    = 0;
		$capped  = false;
		try {
			do {
				$chunk = 'hpos' === $auth['authority'] ? self::hpos_chunk( $last_id, $windows['baseline_start'], $tz ) : self::legacy_chunk( $last_id, $windows['baseline_start'], $tz );
				if ( false === $chunk ) {
					$out['reason'] = 'read_failed';
					return $out;
				}
				foreach ( $chunk as $row ) {
					if ( ! empty( $row['op_missing'] ) ) {
						// HPOS writes an operational row for every order. Its absence is missing
						// required data (paid date, origin), not an unknown origin: fail closed
						// rather than drop the order from a complete-looking aggregate. Never
						// recover it from the posts shadow.
						$out['reason'] = 'operational_data_missing';
						return $out;
					}
					$last_id = max( $last_id, $row['id'] );
					++$rows;
					self::fold( $row, $now, $windows, $store, $methods, $out['excluded'] );
				}
				$more = count( $chunk ) >= self::CHUNK;
				if ( $more && $rows >= self::MAX_ROWS ) {
					$capped = true;
					break;
				}
			} while ( $more );
		} catch ( \Throwable $e ) {
			$out['reason'] = 'read_failed';
			return $out;
		}

		foreach ( $methods as $id => $scope ) {
			$methods[ $id ] = self::finish( $scope, $capped );
		}
		ksort( $methods, SORT_STRING );
		$store = self::finish( $store, $capped );

		$out['ok']        = true;
		$out['reason']    = $capped ? 'row_cap' : ( $store['complete'] ? null : 'invalid_timestamps' );
		$out['coverage']  = $store['complete'] ? 'complete' : 'partial';
		$out['store']     = $store;
		$out['methods']   = $methods;
		$out['rows_read'] = $rows;
		return $out;
	}

	/**
	 * Final shape of one scope: capped example IDs and truncation flag.
	 *
	 * @param array<string,mixed> $scope  Scope.
	 * @param bool                $capped Row cap reached.
	 * @return array<string,mixed>
	 */
	private static function finish( array $scope, bool $capped ): array {
		$examples                   = PaymentRules::order_examples( $scope['candidates'], self::MAX_EXAMPLES );
		$scope['example_ids']       = array_map( static fn( array $c ): int => $c['id'], $examples );
		$scope['examples_truncated'] = $scope['stuck_total'] > count( $examples );
		$scope['complete']          = $scope['complete'] && ! $capped;
		unset( $scope['candidates'] );
		return $scope;
	}

	/**
	 * Fold one compact order row into the store-wide and method aggregates.
	 *
	 * @param array{id:int,method:string,status:string,created:?int,paid:?int,valid:bool,via:?string,txn:bool} $row Row.
	 * @param int                                 $now      Now.
	 * @param array<string,int>                   $w        Windows.
	 * @param array<string,mixed>                 $store    Store scope (by reference).
	 * @param array<string,array<string,mixed>>   $methods  Method scopes (by reference).
	 * @param array<string,int>                   $excluded Exclusion counts (by reference).
	 */
	private static function fold( array $row, int $now, array $w, array &$store, array &$methods, array &$excluded ): void {
		$status = str_starts_with( $row['status'], 'wc-' ) ? substr( $row['status'], 3 ) : $row['status'];
		if ( 'checkout-draft' === $status ) {
			++$excluded['drafts'];
			return;
		}
		if ( in_array( $status, array( 'trash', 'auto-draft', 'draft' ), true ) ) {
			return;
		}
		if ( null === $row['via'] || '' === $row['via'] ) {
			++$excluded['unknown_origin'];
			return;
		}
		if ( ! in_array( $row['via'], self::ORIGINS, true ) ) {
			++$excluded['other_origin'];
			return;
		}

		$id     = PaymentRules::safe_method_id( $row['method'] );
		$scopes = array( &$store );
		if ( '' === $id ) {
			++$excluded['empty_method'];
		} else {
			if ( ! isset( $methods[ $id ] ) ) {
				$methods[ $id ] = self::scope();
			}
			$scopes[] = &$methods[ $id ];
		}

		$created = $row['created'];
		$paid    = $row['paid'];
		if ( ! $row['valid'] || null === $created || $created >= $now || ( null !== $paid && ( $paid <= 0 || $paid >= $now ) ) ) {
			++$excluded['invalid_timestamps'];
			foreach ( $scopes as &$s ) {
				++$s['invalid'];
				$s['complete'] = false;
			}
			unset( $s );
			return;
		}

		$accepted = null !== $paid || in_array( $status, self::TAKEN_STATUSES, true );
		$event    = null !== $paid ? $paid : $created;
		$cohort   = null;
		if ( $created >= $w['window_start'] && $created < $w['mature_before'] ) {
			$cohort = 'current';
		} elseif ( $created >= $w['baseline_start'] && $created < $w['baseline_end'] ) {
			$cohort = 'baseline';
		}
		$kind = null;
		if ( $accepted ) {
			$kind = 'taken';
		} elseif ( 'pending' === $status ) {
			$kind = 'pending';
		} elseif ( 'cancelled' === $status ) {
			$kind = 'cancelled_unpaid';
		}

		foreach ( $scopes as &$s ) {
			if ( $accepted && $event >= $w['baseline_start'] && $event < $now ) {
				PaymentRules::add_event( $s['series'], $event );
				if ( $event >= $now - 7 * 86400 ) {
					++$s['accepted_7d'];
				}
			}
			if ( 'failed' === $status && $created >= $w['failed_start'] && $created < $now ) {
				++$s['failed'];
			}
			if ( null !== $cohort && null !== $kind ) {
				++$s[ $cohort ][ $kind ];
				if ( 'current' === $cohort && 'taken' !== $kind ) {
					if ( $row['txn'] ) {
						++$s['current']['transaction_recorded_unpaid'];
					}
					++$s['stuck_total'];
					$s['candidates'][] = array(
						'id'      => $row['id'],
						'created' => $created,
						'txn'     => $row['txn'],
					);
					if ( count( $s['candidates'] ) > 4 * self::MAX_EXAMPLES ) {
						$s['candidates'] = PaymentRules::order_examples( $s['candidates'], self::MAX_EXAMPLES );
					}
				}
			}
		}
		unset( $s );
	}

	/**
	 * Wave 3 (REVIEW-pass4a #3): is any shop_order that could belong to the history missing its
	 * operational-data row? The main scan selects on creation OR the joined paid date, so an order
	 * created before the history start and paid inside it is invisible when that row (which holds
	 * the paid date) is missing. This probe does not use the paid date.
	 *
	 * Bound: `date_updated_gmt >= history start`, on its own `date_updated` index (WC 10.9.0 and
	 * 11.2.2 `OrdersTableDataStore` schema, byte-identical between the tags). Every CRUD save of an
	 * existing order sets date_modified when the caller did not (`persist_updates()`), and create
	 * sets it when empty, so recording a payment inside the history moves `date_updated_gmt` inside
	 * it too. One LIMIT 1 anti-join on the unique `order_id`; refunds are excluded by type.
	 *
	 * @param int $start History start (UTC).
	 * @return bool|null True when a missing row is found, null on a read failure.
	 */
	private static function hpos_missing_operational_row( int $start ): ?bool {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded one-row read-only probe (R6).
		$hit = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT o.id FROM %i o LEFT JOIN %i d ON d.order_id = o.id WHERE o.type = %s AND o.date_updated_gmt >= %s AND d.order_id IS NULL LIMIT 1',
				$wpdb->prefix . 'wc_orders',
				$wpdb->prefix . 'wc_order_operational_data',
				'shop_order',
				gmdate( 'Y-m-d H:i:s', $start )
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		return null !== $hit;
	}

	/**
	 * One HPOS keyset chunk, or false on a read failure.
	 *
	 * @param int           $after History rows with id > $after.
	 * @param int           $start History start (UTC).
	 * @param \DateTimeZone $tz    Store timezone (unused: HPOS dates are UTC).
	 * @return array<int,array<string,mixed>>|false
	 */
	private static function hpos_chunk( int $after, int $start, \DateTimeZone $tz ): array|false {
		unset( $tz );
		global $wpdb;
		$from             = gmdate( 'Y-m-d H:i:s', $start );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only aggregate scan (R6); the ability caches the result.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.id, o.payment_method AS m, o.status AS s, o.date_created_gmt AS c, d.date_paid_gmt AS p, d.created_via AS v, d.order_id AS op, CASE WHEN o.transaction_id IS NULL OR o.transaction_id = %s THEN 0 ELSE 1 END AS t FROM %i o LEFT JOIN %i d ON d.order_id = o.id WHERE o.type = %s AND o.id > %d AND ( o.date_created_gmt >= %s OR d.date_paid_gmt >= %s ) ORDER BY o.id ASC LIMIT %d',
				'',
				$wpdb->prefix . 'wc_orders',
				$wpdb->prefix . 'wc_order_operational_data',
				'shop_order',
				$after,
				$from,
				$from,
				self::CHUNK
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		$out = array();
		foreach ( $rows as $r ) {
			$created = PaymentRules::utc_datetime( null === $r['c'] ? null : (string) $r['c'] );
			$paid    = PaymentRules::utc_datetime( null === $r['p'] ? null : (string) $r['p'] );
			$out[]   = array(
				'id'      => (int) $r['id'],
				'method'  => (string) $r['m'],
				'status'  => (string) $r['s'],
				'created' => $created['ts'],
				'paid'    => $paid['ts'],
				'valid'   => $created['valid'] && $paid['valid'],
				'via'        => null === $r['v'] ? null : (string) $r['v'],
				'txn'        => 1 === (int) $r['t'],
				'op_missing' => null === $r['op'],
			);
		}
		return $out;
	}

	/**
	 * One legacy (posts) keyset chunk, or false on a read failure.
	 *
	 * The SQL predicate is a superset (any paid-looking meta row in range); the exact first-row
	 * precedence is applied in PHP by PaymentRules::legacy_paid().
	 *
	 * @param int           $after Rows with ID > $after.
	 * @param int           $start History start (UTC).
	 * @param \DateTimeZone $tz    Store timezone.
	 * @return array<int,array<string,mixed>>|false
	 */
	private static function legacy_chunk( int $after, int $start, \DateTimeZone $tz ): array|false {
		global $wpdb;
		$from = gmdate( 'Y-m-d H:i:s', $start );
		// Site-local paid strings: widen by the largest possible offset (14 hours) and filter exactly in PHP.
		$local_from = gmdate( 'Y-m-d H:i:s', $start - 14 * 3600 );
		$pm         = $wpdb->postmeta;
		$wpdb->last_error = '';
		// Step 1: the chunk's order rows (same predicate as before). Step 2: the five meta keys for
		// exactly those IDs in one indexed read, keeping the first meta_id row per key in PHP, so the
		// precedence is that of the former correlated `ORDER BY meta_id LIMIT 1` subqueries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only aggregate scan (R6); the ability caches the result.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.ID AS id, p.post_status AS s, p.post_date_gmt AS c FROM %i p WHERE p.post_type = %s AND p.ID > %d AND ( p.post_date_gmt >= %s OR EXISTS ( SELECT 1 FROM %i pm WHERE pm.post_id = p.ID AND ( ( pm.meta_key = %s AND pm.meta_value REGEXP %s AND CAST( pm.meta_value AS UNSIGNED ) >= %d ) OR ( pm.meta_key = %s AND pm.meta_value >= %s ) ) ) ) ORDER BY p.ID ASC LIMIT %d',
				$wpdb->posts,
				'shop_order',
				$after,
				$from,
				$pm,
				'_date_paid',
				'^[0-9]+$',
				$start,
				'_paid_date',
				$local_from,
				self::CHUNK
			),
			ARRAY_A
		);
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		$meta = array();
		if ( array() !== $rows ) {
			$ids    = array_map( static fn( $r ) => (int) $r['id'], $rows );
			$keys   = array( '_payment_method', '_created_via', '_transaction_id', '_date_paid', '_paid_date' );
			$id_ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$key_ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- only generated %d/%s placeholder lists are interpolated; every value is a placeholder.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read-only aggregate scan (R6); the ability caches the result.
			$mrows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM %i WHERE post_id IN ({$id_ph}) AND meta_key IN ({$key_ph}) ORDER BY post_id ASC, meta_id ASC",
					array_merge( array( $pm ), $ids, $keys )
				),
				ARRAY_A
			);
			// phpcs:enable
			if ( '' !== $wpdb->last_error || ! is_array( $mrows ) ) {
				return false;
			}
			foreach ( $mrows as $mr ) {
				$pid = (int) $mr['post_id'];
				$key = (string) $mr['meta_key'];
				if ( ! isset( $meta[ $pid ] ) || ! array_key_exists( $key, $meta[ $pid ] ) ) {
					$meta[ $pid ][ $key ] = null === $mr['meta_value'] ? null : (string) $mr['meta_value'];
				}
			}
		}
		$out = array();
		foreach ( $rows as $r ) {
			$id      = (int) $r['id'];
			$m       = $meta[ $id ] ?? array();
			$created = PaymentRules::utc_datetime( null === $r['c'] ? null : (string) $r['c'] );
			$paid    = PaymentRules::legacy_paid( $m['_date_paid'] ?? null, $m['_paid_date'] ?? null, $tz );
			$out[]   = array(
				'id'      => $id,
				'method'  => (string) ( $m['_payment_method'] ?? '' ),
				'status'  => (string) $r['s'],
				'created' => $created['ts'],
				'paid'    => $paid['paid'],
				'valid'   => $created['valid'] && $paid['valid'],
				'via'     => $m['_created_via'] ?? null,
				'txn'     => isset( $m['_transaction_id'] ) && '' !== $m['_transaction_id'],
			);
		}
		return $out;
	}

	/**
	 * Revalidate example IDs through wc_get_order() and build safe Example rows (C7). Orders that
	 * vanished or changed since aggregation are skipped; counts are not touched.
	 *
	 * @param int[]         $ids       Example IDs from read().
	 * @param string        $authority hpos|legacy.
	 * @param \DateTimeZone $tz        Store timezone.
	 * @return array<int,array{number:string,created:string,status:string,total:string,currency:string,edit_url:string}>
	 */
	public static function examples( array $ids, string $authority, \DateTimeZone $tz ): array {
		$out = array();
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $out;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		foreach ( array_slice( $ids, 0, self::MAX_EXAMPLES ) as $id ) {
			$id    = (int) $id;
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			$status = $order->get_status();
			if ( ! in_array( $status, array( 'pending', 'cancelled' ), true ) || null !== $order->get_date_paid() ) {
				continue;
			}
			$created = $order->get_date_created();
			if ( null === $created ) {
				continue;
			}
			$currency = (string) $order->get_currency();
			$path     = 'hpos' === $authority ? 'admin.php?page=wc-orders&action=edit&id=' . $id : 'post.php?post=' . $id . '&action=edit';
			$out[]    = array(
				'number'   => (string) $id,
				'created'  => PaymentRules::iso( $created->getTimestamp(), $tz ),
				'status'   => $status,
				'total'    => number_format( (float) $order->get_total(), max( 0, $decimals ), '.', '' ),
				'currency' => 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '',
				'edit_url' => admin_url( $path ),
			);
		}
		return $out;
	}

	/**
	 * Hold-stock configuration from WooCommerce's own options (pinned item 1 semantics).
	 *
	 * @return array{hold_stock_minutes:?int,hold_stock_configured:bool}
	 */
	public static function hold_stock(): array {
		return PaymentRules::hold_stock( get_option( 'woocommerce_hold_stock_minutes', null ), get_option( 'woocommerce_manage_stock', null ) );
	}
}
