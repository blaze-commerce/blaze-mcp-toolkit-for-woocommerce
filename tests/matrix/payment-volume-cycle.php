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
