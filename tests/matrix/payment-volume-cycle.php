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

/*
 * 1.3 card-testing performance workload (FEATURE-PLAN item 9, contract C10). Independent of the
 * payment-health cycle above, which it never changes: its own orders, timeline and expected counts,
 * shared by the seeder (seed-payment-volume.php card-*), the measurement (measure-card-testing.php)
 * and the self-test (test-card-testing-perf-validate.php). Pure PHP, no WordPress call.
 *
 * Every card order carries the fixture tag BLZMCP_PERF_META with the value card:<kind>:<n> (kind r
 * recent, o older, m method-cap stress, n network-cap stress), so the shared cleanup removes it and
 * the CRUD sample can recompute the order's own spec. Saved IPs are documentation ranges only
 * (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32); emails use example.invalid.
 */

/** The 3 source-verified instant methods of the card workload (PaymentMethods::INSTANT_IDS). */
defined( 'BLZMCP_CARD_PERF_METHODS' ) || define( 'BLZMCP_CARD_PERF_METHODS', array( 'stripe', 'woocommerce_payments', 'square_credit_card' ) );

/** Option holding the gateway and feature settings saved before the card workload changed them. */
defined( 'BLZMCP_CARD_PERF_SAVED' ) || define( 'BLZMCP_CARD_PERF_SAVED', 'blzmcp_card_perf_saved_settings' );

/*
 * Settings the card workload sets (saved first, restored by the fixture cleanup): the 3 methods
 * enabled and live (Stripe's verified testmode key off), and core order attribution on.
 */
defined( 'BLZMCP_CARD_PERF_SETTINGS' ) || define(
	'BLZMCP_CARD_PERF_SETTINGS',
	array(
		'woocommerce_stripe_settings'                   => array(
			'enabled'  => 'yes',
			'testmode' => 'no',
		),
		'woocommerce_woocommerce_payments_settings'     => array(
			'enabled'   => 'yes',
			'test_mode' => 'no',
		),
		'woocommerce_square_credit_card_settings'       => array( 'enabled' => 'yes' ),
		'woocommerce_feature_order_attribution_enabled' => 'yes',
	)
);

/*
 * Status cycle of 20 per method: 8 processing and 4 completed (paid 30 s after creation), 2
 * on-hold (accepted, not paid), 2 failed, 1 cancelled, 2 pending and 1 checkout draft.
 */
defined( 'BLZMCP_CARD_PERF_STATUSES' ) || define(
	'BLZMCP_CARD_PERF_STATUSES',
	array( 'processing', 'processing', 'completed', 'failed', 'processing', 'on-hold', 'processing', 'pending', 'completed', 'processing', 'cancelled', 'processing', 'completed', 'failed', 'on-hold', 'processing', 'checkout-draft', 'completed', 'pending', 'processing' )
);

/** Attribution source types, cycle of 10: 7 present, 2 unknown values, 1 with no meta row (null). */
defined( 'BLZMCP_CARD_PERF_ATTR' ) || define( 'BLZMCP_CARD_PERF_ATTR', array( 'utm', 'organic', 'referral', 'typein', 'utm', 'organic', 'mobile_app', '(none)', 'unknown', null ) );

/** Burst size cap (orders per burst) for the full workload. */
defined( 'BLZMCP_CARD_PERF_BURST' ) || define( 'BLZMCP_CARD_PERF_BURST', 150 );

/** Method-cap and network-cap stress bounds (C4 MAX_METHODS, MAX_NETWORKS). */
defined( 'BLZMCP_CARD_PERF_MAX_METHODS' ) || define( 'BLZMCP_CARD_PERF_MAX_METHODS', 1000 );
defined( 'BLZMCP_CARD_PERF_MAX_NETWORKS' ) || define( 'BLZMCP_CARD_PERF_MAX_NETWORKS', 5000 );

if ( ! function_exists( 'blzmcp_card_perf_spec' ) ) {
	/**
	 * Burst sizes for a recent workload of $total orders: A and B are each min(150, total/50); C
	 * (missing origin) is 2/5 of that.
	 *
	 * @param int $total Recent orders.
	 * @return array{a:int,b:int,c:int}
	 */
	function blzmcp_card_perf_bursts( int $total ): array {
		$b = max( 0, min( BLZMCP_CARD_PERF_BURST, intdiv( $total, 50 ) ) );
		return array(
			'a' => $b,
			'b' => $b,
			'c' => intdiv( $b * 2, 5 ),
		);
	}

	/**
	 * One card fixture order. Recent orders (kind r) start with 3 real failure bursts, then spread
	 * evenly over [now - 28 days + 60 s, now - 60 s]; older orders (kind o) sit 30-400 days back.
	 *
	 * - Burst A: woocommerce_payments, verified checkout, failed, no attribution, 3 IPv4 /24
	 *   networks, 10 s apart from 3 hours before the seed time (inside both windows).
	 * - Burst B: square_credit_card, verified checkout, failed, no attribution, 3 IPv6 /64
	 *   networks, 10 s apart from 5 days before (inside 168 hours, in the 48-hour baseline).
	 * - Burst C: stripe, missing origin, failed, no attribution, one /24, 15 s apart from 10 hours
	 *   before (inside both windows; can at most be a possible sign).
	 * - Spread orders: method cycle of 3 (every 100th has no method), status cycle of 20 per method,
	 *   origin per 50 (44 checkout, 3 store-api, 2 missing, 1 admin), attribution per 10, IP per 20
	 *   (15 IPv6 /64s from a bounded set, 3 IPv4 /24s, 1 IPv4-mapped IPv6, 1 missing).
	 *
	 * @param string $kind     r|o.
	 * @param int    $i        Sequence number within the kind.
	 * @param int    $seed_now Seed reference time.
	 * @param int    $total    Orders of this kind.
	 * @return array{method:string,status:string,paid:?int,via:string,ip:string,attr:?string,created:int,burst:?string}
	 */
	function blzmcp_card_perf_spec( string $kind, int $i, int $seed_now, int $total ): array {
		$bursts = 'r' === $kind ? blzmcp_card_perf_bursts( $total ) : array(
			'a' => 0,
			'b' => 0,
			'c' => 0,
		);
		$ab     = $bursts['a'] + $bursts['b'];
		if ( $i < $ab + $bursts['c'] ) {
			$j    = $i < $bursts['a'] ? $i : ( $i < $ab ? $i - $bursts['a'] : $i - $ab );
			$hits = array( '192.0.2.', '198.51.100.', '203.0.113.' );
			if ( $i < $bursts['a'] ) {
				return array(
					'method'  => 'woocommerce_payments',
					'status'  => 'failed',
					'paid'    => null,
					'via'     => 'checkout',
					'ip'      => $hits[ $j % 3 ] . ( 1 + $j % 250 ),
					'attr'    => null,
					'created' => $seed_now - 3 * 3600 + 10 * $j,
					'burst'   => 'a',
				);
			}
			if ( $i < $ab ) {
				return array(
					'method'  => 'square_credit_card',
					'status'  => 'failed',
					'paid'    => null,
					'via'     => 'checkout',
					'ip'      => sprintf( '2001:db8:ff00:%x::%x', 1 + $j % 3, 1 + $j ),
					'attr'    => null,
					'created' => $seed_now - 5 * 86400 + 10 * $j,
					'burst'   => 'b',
				);
			}
			return array(
				'method'  => 'stripe',
				'status'  => 'failed',
				'paid'    => null,
				'via'     => '',
				'ip'      => '203.0.113.' . ( 1 + $j % 250 ),
				'attr'    => null,
				'created' => $seed_now - 10 * 3600 + 15 * $j,
				'burst'   => 'c',
			);
		}
		$n      = $i - ( $ab + $bursts['c'] );
		$spread = max( 1, $total - ( $ab + $bursts['c'] ) );
		$status = BLZMCP_CARD_PERF_STATUSES[ intdiv( $n, 3 ) % 20 ];
		$o      = $n % 50;
		$via    = $o < 44 ? 'checkout' : ( $o < 47 ? 'store-api' : ( $o < 49 ? '' : 'admin' ) );
		$q      = $n % 20;
		if ( 13 === $q ) {
			$ip = '192.0.2.' . ( 1 + $n % 250 );
		} elseif ( 14 === $q ) {
			$ip = '198.51.100.' . ( 1 + $n % 250 );
		} elseif ( 15 === $q ) {
			$ip = '203.0.113.' . ( 1 + $n % 250 );
		} elseif ( 16 === $q ) {
			$ip = '::ffff:192.0.2.' . ( 1 + $n % 250 );
		} elseif ( 17 === $q ) {
			$ip = '';
		} else {
			$ip = sprintf( '2001:db8:%x:%x::%x', 1 + $n % 997, $n % 5, 1 + $n % 200 );
		}
		$created = 'r' === $kind
			? $seed_now - 60 - (int) floor( $n * ( 28 * 86400 - 120 ) / $spread )
			: $seed_now - 30 * 86400 - (int) floor( $n * ( 370 * 86400 ) / max( 1, $total ) );
		return array(
			'method'  => 99 === $n % 100 ? '' : BLZMCP_CARD_PERF_METHODS[ $n % 3 ],
			'status'  => $status,
			'paid'    => in_array( $status, array( 'processing', 'completed' ), true ) ? $created + BLZMCP_PERF_PAID_AFTER : null,
			'via'     => $via,
			'ip'      => $ip,
			'attr'    => BLZMCP_CARD_PERF_ATTR[ $n % 10 ],
			'created' => $created,
			'burst'   => null,
		);
	}

	/**
	 * Outcome class of one fixture order (C3): drafts are drafts; a paid date or processing,
	 * completed or on-hold is accepted; then failed, cancelled and pending unpaid.
	 *
	 * @param string   $status Status without the wc- prefix.
	 * @param int|null $paid   Paid time.
	 */
	function blzmcp_card_perf_class( string $status, ?int $paid ): string {
		if ( 'checkout-draft' === $status ) {
			return 'checkout_draft';
		}
		if ( null !== $paid || in_array( $status, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			return 'accepted';
		}
		$map = array(
			'failed'    => 'failed_unpaid',
			'cancelled' => 'cancelled_unpaid',
			'pending'   => 'pending_unpaid',
		);
		return $map[ $status ] ?? 'other_status';
	}

	/**
	 * Origin context key of a recorded origin (C3): checkout, store-api, missing (empty), other.
	 *
	 * @param string $via Recorded origin.
	 */
	function blzmcp_card_perf_origin( string $via ): string {
		if ( '' === $via ) {
			return 'missing_origin';
		}
		return 'checkout' === $via ? 'checkout_origin' : ( 'store-api' === $via ? 'store_api_origin' : 'other_origin' );
	}

	/**
	 * The counts the card-testing ability must report for the card workload at its own now
	 * (C3/C8): window [now - h, now) by creation; totals over every valid retained shop order;
	 * per method and origin scope (verified checkout = checkout or store-api; missing origin) the
	 * outcome classes. Other-origin and no-method orders are context only.
	 *
	 * @param int $seed_now The seeder's reference time.
	 * @param int $orders   Recent orders seeded.
	 * @param int $old      Older orders seeded.
	 * @param int $now      The ability's now (from checked_at).
	 * @param int $hours    Window.
	 * @return array{totals:array<string,int>,scopes:array<string,array<string,array<string,int>>>,bursts:array<string,int>,methods:string[]}
	 */
	function blzmcp_card_perf_expect( int $seed_now, int $orders, int $old, int $now, int $hours ): array {
		$classes = array( 'failed_unpaid', 'accepted', 'cancelled_unpaid', 'pending_unpaid', 'checkout_draft', 'other_status' );
		$totals  = array_fill_keys( array_merge( $classes, array( 'checkout_origin', 'store_api_origin', 'missing_origin', 'other_origin', 'unassigned_method', 'invalid_rows' ) ), 0 );
		$scopes  = array(
			'verified_checkout' => array(),
			'missing_origin'    => array(),
		);
		$bursts  = array(
			'a' => 0,
			'b' => 0,
			'c' => 0,
		);
		$start   = $now - $hours * 3600;
		foreach ( array( 'r' => $orders, 'o' => $old ) as $kind => $total ) {
			for ( $i = 0; $i < $total; $i++ ) {
				$s = blzmcp_card_perf_spec( $kind, $i, $seed_now, $total );
				if ( $s['created'] < $start || $s['created'] >= $now ) {
					continue;
				}
				$class  = blzmcp_card_perf_class( $s['status'], $s['paid'] );
				$origin = blzmcp_card_perf_origin( $s['via'] );
				++$totals[ $class ];
				++$totals[ $origin ];
				if ( null !== $s['burst'] ) {
					++$bursts[ $s['burst'] ];
				}
				if ( '' === $s['method'] ) {
					++$totals['unassigned_method'];
					continue;
				}
				if ( 'other_origin' === $origin ) {
					continue;
				}
				$scope = 'missing_origin' === $origin ? 'missing_origin' : 'verified_checkout';
				if ( ! isset( $scopes[ $scope ][ $s['method'] ] ) ) {
					$scopes[ $scope ][ $s['method'] ] = array_fill_keys( $classes, 0 );
				}
				++$scopes[ $scope ][ $s['method'] ][ $class ];
			}
		}
		foreach ( $scopes as &$by ) {
			ksort( $by, SORT_STRING );
		}
		unset( $by );
		$methods = BLZMCP_CARD_PERF_METHODS;
		sort( $methods, SORT_STRING );
		return array(
			'totals'  => $totals,
			'scopes'  => $scopes,
			'bursts'  => $bursts,
			'methods' => $methods,
		);
	}

	/**
	 * Seeded distribution of the card workload over every order of both kinds (what card-verify
	 * checks in the authoritative store and the receipt reports).
	 *
	 * @param int $orders   Recent orders.
	 * @param int $old      Older orders.
	 * @param int $seed_now Seed reference time.
	 * @return array<string,mixed>
	 */
	function blzmcp_card_perf_distribution( int $orders, int $old, int $seed_now ): array {
		$out = array(
			'orders'           => $orders,
			'old_orders'       => $old,
			'methods'          => BLZMCP_CARD_PERF_METHODS,
			'bursts'           => blzmcp_card_perf_bursts( $orders ),
			'statuses'         => array(),
			'drafts'           => 0,
			'failed'           => 0,
			'missing_origin'   => 0,
			'store_api_origin' => 0,
			'other_origin'     => 0,
			'unassigned'       => 0,
			'attribution_rows' => 0,
			'attribution'      => array(
				'present' => 0,
				'unknown' => 0,
				'absent'  => 0,
			),
			'ip'               => array(
				'ipv4'        => 0,
				'ipv6'        => 0,
				'ipv4_mapped' => 0,
				'missing'     => 0,
			),
			'ip_rows'          => 0,
		);
		foreach ( array( 'r' => $orders, 'o' => $old ) as $kind => $total ) {
			for ( $i = 0; $i < $total; $i++ ) {
				$s                               = blzmcp_card_perf_spec( $kind, $i, $seed_now, $total );
				$out['statuses'][ $s['status'] ] = ( $out['statuses'][ $s['status'] ] ?? 0 ) + 1;
				$out['drafts']                  += 'checkout-draft' === $s['status'] ? 1 : 0;
				$out['failed']                  += 'failed' === $s['status'] ? 1 : 0;
				$origin                          = blzmcp_card_perf_origin( $s['via'] );
				if ( 'checkout_origin' !== $origin ) {
					++$out[ $origin ];
				}
				$out['unassigned'] += '' === $s['method'] ? 1 : 0;
				if ( null === $s['attr'] ) {
					++$out['attribution']['absent'];
				} else {
					++$out['attribution_rows'];
					++$out['attribution'][ in_array( $s['attr'], array( '(none)', 'unknown' ), true ) ? 'unknown' : 'present' ];
				}
				if ( '' === $s['ip'] ) {
					++$out['ip']['missing'];
				} else {
					++$out['ip_rows'];
					++$out['ip'][ str_starts_with( $s['ip'], '::ffff:' ) ? 'ipv4_mapped' : ( str_contains( $s['ip'], ':' ) ? 'ipv6' : 'ipv4' ) ];
				}
			}
		}
		ksort( $out['statuses'] );
		return $out;
	}

	/**
	 * Method-cap stress order n (0-based): its own method ID, verified checkout, processing paid,
	 * one minute apart from an hour before the reference time (inside the 48-hour window).
	 *
	 * @param int $n   Sequence number (distinct method ID).
	 * @param int $now Reference time.
	 * @return array{method:string,status:string,paid:?int,via:string,ip:string,attr:?string,created:int,burst:?string}
	 */
	function blzmcp_card_perf_cap_method_spec( int $n, int $now ): array {
		$created = $now - 3600 - 60 * $n;
		return array(
			'method'  => sprintf( 'blzmcp_ctcap_%04d', $n ),
			'status'  => 'processing',
			'paid'    => $created + BLZMCP_PERF_PAID_AFTER,
			'via'     => 'checkout',
			'ip'      => '192.0.2.' . ( 1 + $n % 250 ),
			'attr'    => 'typein',
			'created' => $created,
			'burst'   => null,
		);
	}

	/**
	 * Network-cap stress order n (0-based): a failed woocommerce_payments checkout order from its
	 * own IPv6 /64 (2001:db8::/32), 25 s apart from 10 minutes before the reference time (inside
	 * the 48-hour window), so n orders record exactly n networks among in-window failed orders.
	 *
	 * @param int $n   Sequence number (distinct network).
	 * @param int $now Reference time.
	 * @return array{method:string,status:string,paid:?int,via:string,ip:string,attr:?string,created:int,burst:?string}
	 */
	function blzmcp_card_perf_cap_network_spec( int $n, int $now ): array {
		return array(
			'method'  => 'woocommerce_payments',
			'status'  => 'failed',
			'paid'    => null,
			'via'     => 'checkout',
			'ip'      => sprintf( '2001:db8:%x:%x::1', 0xc000 + intdiv( $n, 4096 ), $n % 4096 ),
			'attr'    => null,
			'created' => $now - 600 - 25 * $n,
			'burst'   => null,
		);
	}
}
