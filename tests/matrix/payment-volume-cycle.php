<?php
/**
 * The payment-health volume fixture's order cycle and timeline, plus the counts the ability must
 * report for it (FEATURE-PLAN item 10). Pure PHP: no WordPress call, so the expectation can be
 * unit-tested with plain `php` (tests/matrix/test-perf-validate.php) and shared by the seeder
 * (seed-payment-volume.php) and the measurement (measure-payment-health.php).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

defined( 'BLZMCP_PERF_META' ) || define( 'BLZMCP_PERF_META', '_blzmcp_perf' );
defined( 'BLZMCP_PERF_PREFIX' ) || define( 'BLZMCP_PERF_PREFIX', 'BLZMCP-PERF ' );
defined( 'BLZMCP_PERF_SAVED' ) || define( 'BLZMCP_PERF_SAVED', 'blzmcp_perf_saved_settings' );

/*
 * Cycle of 20: [method, status, paid?, transaction reference?]. Per 20 orders: stripe 12 (9 paid,
 * 1 pending, 1 pending with a reference, 1 cancelled unpaid), cod 5 (3 processing paid, 1 on-hold,
 * 1 failed), cheque 3 (2 on-hold, 1 completed paid).
 */
defined( 'BLZMCP_PERF_CYCLE' ) || define(
	'BLZMCP_PERF_CYCLE',
	array(
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
	)
);

/** Seconds between creation and the paid timestamp of a paid fixture order. */
defined( 'BLZMCP_PERF_PAID_AFTER' ) || define( 'BLZMCP_PERF_PAID_AFTER', 30 );

/*
 * 1.2.1 (FEATURE-PLAN item 5): the separately counted excluded-origin population, a cycle of 10
 * [method, recorded origin] pairs. Origin '' is an empty (missing) origin; every other origin is a
 * literal that is not checkout or store-api, never a claim about where such orders come from. 8
 * methods are seen only outside verified checkout activity (bacs among them, a registered core
 * gateway); cod and stripe are mixed with the checkout cycle above, so their checkout counts must
 * stay exactly the cycle's. All paid and processing, created inside the last 27 days.
 */
defined( 'BLZMCP_PERF_OUTSIDE' ) || define(
	'BLZMCP_PERF_OUTSIDE',
	array(
		array( 'blzmcp_ext_admin', 'admin' ),
		array( 'blzmcp_ext_none', '' ),
		array( 'blzmcp_ext_phone', 'phone-order' ),
		array( 'blzmcp_ext_pos', 'pos' ),
		array( 'blzmcp_ext_import', 'import' ),
		array( 'blzmcp_ext_rest', 'rest-api' ),
		array( 'bacs', '' ),
		array( 'blzmcp_ext_sub', 'subscription' ),
		array( 'cod', 'admin' ),
		array( 'stripe', '' ),
	)
);

/** Checkout control orders (cod) seeded with the method-cap stress fixture. */
defined( 'BLZMCP_PERF_CAP_CONTROL' ) || define( 'BLZMCP_PERF_CAP_CONTROL', 24 );

if ( ! function_exists( 'blzmcp_perf_outside_created' ) ) {
	/**
	 * Creation time of excluded-origin order $i of $total: evenly over [now - 27 days, now - 1 hour],
	 * so every one stays inside the 28-day history for the whole run.
	 *
	 * @param int $i     Sequence number.
	 * @param int $now   Seed reference time.
	 * @param int $total Excluded-origin orders.
	 */
	function blzmcp_perf_outside_created( int $i, int $now, int $total ): int {
		return $now - 3600 - (int) floor( $i * ( 26 * 86400 ) / max( 1, $total ) );
	}

	/**
	 * The method IDs the excluded-origin population must show (C3): inventory-only IDs (no
	 * checkout-cycle orders) and mixed IDs (also in the checkout cycle), for $count orders.
	 *
	 * @param int $count Excluded-origin orders seeded.
	 * @return array{inventory_only:string[],mixed:string[],orders:int}
	 */
	function blzmcp_perf_expect_outside( int $count ): array {
		$checkout = array();
		foreach ( BLZMCP_PERF_CYCLE as $row ) {
			$checkout[ $row[0] ] = true;
		}
		$seen  = array();
		$first = min( $count, count( BLZMCP_PERF_OUTSIDE ) );
		for ( $i = 0; $i < $first; $i++ ) {
			$seen[ BLZMCP_PERF_OUTSIDE[ $i ][0] ] = true;
		}
		$ids = array_keys( $seen );
		sort( $ids );
		return array(
			'inventory_only' => array_values( array_filter( $ids, static fn( $id ) => ! isset( $checkout[ $id ] ) ) ),
			'mixed'          => array_values( array_filter( $ids, static fn( $id ) => isset( $checkout[ $id ] ) ) ),
			'orders'         => $count,
		);
	}

	/** Method ID of the n-th distinct ID of the method-cap stress fixture (0-based). */
	function blzmcp_perf_cap_id( int $n ): string {
		return sprintf( 'blzmcp_cap_%04d', $n );
	}
}

if ( ! function_exists( 'blzmcp_perf_created' ) ) {
	/**
	 * Creation time of fixture order $i ('orders' = recent, over 28 days; 'old' = 30-400 days ago).
	 *
	 * @param string $kind  orders|old.
	 * @param int    $i     Sequence number.
	 * @param int    $now   Seed reference time.
	 * @param int    $total Orders of this kind.
	 */
	function blzmcp_perf_created( string $kind, int $i, int $now, int $total ): int {
		return 'orders' === $kind
			? $now - 60 - (int) floor( $i * ( 28 * 86400 - 120 ) / max( 1, $total ) )
			: $now - 30 * 86400 - (int) floor( $i * ( 370 * 86400 ) / max( 1, $total ) );
	}

	/**
	 * The counts the payment-health ability must report for the volume fixture, evaluated at the
	 * ability's own `now` with the plan's windows (C4/C6): history [now-28d, now) by event time
	 * (paid timestamp, else creation); current cohort [now-h, max(now-h, now-1h)) by creation;
	 * failed [now-h, now) by creation. Accepted = paid, or processing/completed/on-hold.
	 *
	 * @param int $seed_now    The seeder's reference time.
	 * @param int $recent      Recent orders seeded.
	 * @param int $old         Older orders seeded.
	 * @param int $now         The ability's now (from checked_at).
	 * @param int $since_hours Window.
	 * @return array{store_wide:array{taken_28d:int},methods:array<string,array<string,int>>}
	 */
	function blzmcp_perf_expect_orders( int $seed_now, int $recent, int $old, int $now, int $since_hours ): array {
		$history = $now - 28 * 86400;
		$window  = $now - $since_hours * 3600;
		$mature  = max( $window, $now - 3600 );
		$zero    = array(
			'taken_28d'                   => 0,
			'pending'                     => 0,
			'cancelled_unpaid'            => 0,
			'taken'                       => 0,
			'transaction_recorded_unpaid' => 0,
			'failed'                      => 0,
		);
		$methods = array();
		$store   = 0;
		foreach ( array( 'orders' => $recent, 'old' => $old ) as $kind => $total ) {
			for ( $i = 0; $i < $total; $i++ ) {
				list( $method, $status, $paid, $txn ) = BLZMCP_PERF_CYCLE[ $i % 20 ];
				$created                              = blzmcp_perf_created( $kind, $i, $seed_now, $total );
				if ( $created >= $now ) {
					continue; // Not yet created at the ability's now (cannot happen after seeding).
				}
				$m        = $methods[ $method ] ?? $zero;
				$accepted = $paid || in_array( $status, array( 'processing', 'completed', 'on-hold' ), true );
				$event    = $paid ? $created + BLZMCP_PERF_PAID_AFTER : $created;
				if ( $accepted && $event >= $history && $event < $now ) {
					++$m['taken_28d'];
					++$store;
				}
				if ( 'failed' === $status && $created >= $window && $created < $now ) {
					++$m['failed'];
				}
				if ( $created >= $window && $created < $mature ) {
					if ( $accepted ) {
						++$m['taken'];
					} elseif ( 'pending' === $status || 'cancelled' === $status ) {
						++$m[ 'pending' === $status ? 'pending' : 'cancelled_unpaid' ];
						if ( $txn ) {
							++$m['transaction_recorded_unpaid'];
						}
					}
				}
				$methods[ $method ] = $m;
			}
		}
		ksort( $methods );
		return array(
			'store_wide' => array( 'taken_28d' => $store ),
			'methods'    => $methods,
		);
	}
}
