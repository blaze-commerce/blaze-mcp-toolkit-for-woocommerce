<?php
/**
 * Validity of one payment-health performance trial (FEATURE-PLAN item 10, REVIEW-pass4 #10).
 *
 * A timing counts only when the ability observed the seeded workload and each reader reported its
 * expected success and counts:
 *
 * - orders: store-wide quiet check read in full, store-wide and per-method taken_28d and the
 *   current-cohort pending / cancelled_unpaid / taken / transaction_recorded_unpaid / failed
 *   counts exactly equal to the fixture's (payment-volume-cycle.php at the ability's own now);
 * - inventory: every seeded method has a row with an enum kind (cod and cheque manual);
 * - logs: the seeded gateway's log check read; complete with the exact observed count, or partial
 *   only for a documented bound (log_row_cap, byte_cap, file_cap) with a lower-bound count in range;
 * - jobs: the seeded gateway's job check read; complete with the exact failed count, or the
 *   sampled lower bound (sampled_store) only when the exact database store is not required.
 *
 * The public `reason` is fixed translated text (PaymentHealth::reason_text()), not the code, so a
 * bound is matched by its exact text, read from the plugin in the same process (same locale, can't
 * drift). A text shared with a code that is not a bound, or the generic fallback, is never accepted;
 * without the plugin loaded no bound is accepted (fail closed).
 *
 * A seeded source the fixture expected nothing from (zero expected) is itself a failure: the
 * workload was not observed. No WordPress call besides the plugin's __() text:
 * tests/matrix/test-perf-validate.php.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( ! function_exists( 'blzmcp_perf_validate' ) ) {
	/** Documented bounded truncation of a log read (C8): the only partial log coverage allowed. */
	define( 'BLZMCP_PERF_LOG_BOUNDS', array( 'log_row_cap', 'byte_cap', 'file_cap' ) );
	/** Documented sampled lower bound of a job read: the only partial job coverage allowed. */
	define( 'BLZMCP_PERF_JOB_BOUNDS', array( 'sampled_store' ) );

	/**
	 * The public texts of the documented bounds, from the plugin.
	 *
	 * @return array{logs:array<string,string>,jobs:array<string,string>} Text => code, per reader.
	 */
	function blzmcp_perf_bound_texts(): array {
		$cls = '\\Blaze\\McpToolkit\\Abilities\\PaymentHealth';
		$out = array(
			'logs' => array(),
			'jobs' => array(),
		);
		if ( ! class_exists( $cls ) || ! method_exists( $cls, 'reason_text' ) ) {
			return $out;
		}
		$all    = class_exists( '\\Blaze\\McpToolkit\\Diagnostics\\PaymentRules' ) ? \Blaze\McpToolkit\Diagnostics\PaymentRules::REASONS : array();
		$bounds = array_merge( BLZMCP_PERF_LOG_BOUNDS, BLZMCP_PERF_JOB_BOUNDS );
		$taken  = array( (string) $cls::reason_text( 'blzmcp_perf_unlisted_code' ) => true ); // The generic fallback.
		foreach ( array_diff( $all, $bounds ) as $code ) {
			$taken[ (string) $cls::reason_text( $code ) ] = true; // Ambiguous with a non-bound code.
		}
		foreach ( array(
			'logs' => BLZMCP_PERF_LOG_BOUNDS,
			'jobs' => BLZMCP_PERF_JOB_BOUNDS,
		) as $reader => $codes ) {
			foreach ( $codes as $code ) {
				$text = $cls::reason_text( $code );
				if ( is_string( $text ) && '' !== $text && ! isset( $taken[ $text ] ) ) {
					$out[ $reader ][ $text ] = $code;
				}
			}
		}
		return $out;
	}

	/**
	 * Validate one uncached result against the fixture's expected counts.
	 *
	 * @param mixed               $result The ability's result (array, or anything else on error).
	 * @param array<string,mixed> $expect {orders: blzmcp_perf_expect_orders(), logs: {method: n},
	 *                                    jobs: {method: n}}.
	 * @param array<string,mixed> $opts   {require_exact_jobs: bool, bound_texts?: blzmcp_perf_bound_texts()
	 *                                    shape, default read from the plugin}.
	 * @return array{ok:bool,readers:array<string,bool>,failures:string[]}
	 */
	function blzmcp_perf_validate( $result, array $expect, array $opts = array() ): array {
		$texts    = isset( $opts['bound_texts'] ) && is_array( $opts['bound_texts'] ) ? $opts['bound_texts'] : blzmcp_perf_bound_texts();
		$is_bound = static fn( string $reader, $reason ): bool => is_string( $reason ) && isset( $texts[ $reader ][ $reason ] );
		$readers  = array(
			'orders'    => true,
			'inventory' => true,
			'logs'      => true,
			'jobs'      => true,
		);
		$failures = array();
		$fail     = static function ( string $reader, string $why ) use ( &$readers, &$failures ): void {
			$readers[ $reader ] = false;
			$failures[]         = $reader . ': ' . $why;
		};
		$show     = static fn( $v ): string => null === $v ? 'null' : ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : ( is_scalar( $v ) ? (string) $v : gettype( $v ) ) );

		if ( ! is_array( $result ) ) {
			foreach ( array_keys( $readers ) as $r ) {
				$fail( $r, 'no response (' . ( is_object( $result ) ? get_class( $result ) : gettype( $result ) ) . ')' );
			}
			return array(
				'ok'       => false,
				'readers'  => $readers,
				'failures' => $failures,
			);
		}
		if ( ! in_array( $result['coverage'] ?? null, array( 'complete', 'partial' ), true ) ) {
			$fail( 'orders', 'response coverage ' . $show( $result['coverage'] ?? null ) );
		}

		$orders = (array) ( $expect['orders'] ?? array() );
		$want   = (int) ( $orders['store_wide']['taken_28d'] ?? 0 );
		$sw     = (array) ( $result['store_wide'] ?? array() );
		if ( $want <= 0 ) {
			$fail( 'orders', 'the fixture expects no accepted orders (workload not seeded)' );
		}
		if ( true !== ( $sw['checked'] ?? null ) || 'complete' !== ( $sw['coverage'] ?? null ) ) {
			$fail( 'orders', 'store-wide quiet check checked ' . $show( $sw['checked'] ?? null ) . ', coverage ' . $show( $sw['coverage'] ?? null ) . ' (reason ' . $show( $sw['reason'] ?? null ) . ')' );
		}
		if ( ( $sw['taken_28d'] ?? null ) !== $want ) {
			$fail( 'orders', 'store-wide taken_28d ' . $show( $sw['taken_28d'] ?? null ) . ', expected ' . $want );
		}

		$rows = array();
		foreach ( (array) ( $result['methods'] ?? array() ) as $m ) {
			if ( is_array( $m ) && isset( $m['id'] ) && is_string( $m['id'] ) ) {
				$rows[ $m['id'] ] = $m;
			}
		}
		foreach ( (array) ( $orders['methods'] ?? array() ) as $id => $counts ) {
			$m = $rows[ $id ] ?? null;
			if ( null === $m ) {
				$fail( 'inventory', "method $id missing" );
				continue;
			}
			if ( ! in_array( $m['kind'] ?? null, array( 'instant', 'manual', 'unknown' ), true ) || ( in_array( $id, array( 'cod', 'cheque', 'bacs' ), true ) && 'manual' !== $m['kind'] ) ) {
				$fail( 'inventory', "method $id kind " . $show( $m['kind'] ?? null ) );
			}
			if ( ! is_bool( $m['registered'] ?? null ) && null !== ( $m['registered'] ?? null ) ) {
				$fail( 'inventory', "method $id registered " . $show( $m['registered'] ) );
			}
			if ( ( $m['quiet']['taken_28d'] ?? null ) !== (int) $counts['taken_28d'] ) {
				$fail( 'orders', "method $id taken_28d " . $show( $m['quiet']['taken_28d'] ?? null ) . ', expected ' . (int) $counts['taken_28d'] );
			}
			foreach ( array( 'pending', 'cancelled_unpaid', 'taken', 'transaction_recorded_unpaid', 'failed' ) as $k ) {
				if ( ( $m['stuck'][ $k ] ?? null ) !== (int) $counts[ $k ] ) {
					$fail( 'orders', "method $id stuck.$k " . $show( $m['stuck'][ $k ] ?? null ) . ', expected ' . (int) $counts[ $k ] );
				}
			}
		}

		foreach ( (array) ( $expect['logs'] ?? array() ) as $id => $n ) {
			$n = (int) $n;
			$l = (array) ( $rows[ $id ]['errors']['logs'] ?? array() );
			$c = $l['count'] ?? null;
			if ( $n <= 0 ) {
				$fail( 'logs', "the fixture has no $id log errors in the window (workload not seeded)" );
			} elseif ( true !== ( $l['checked'] ?? null ) || true !== ( $l['applicable'] ?? null ) ) {
				$fail( 'logs', "method $id log check not read (coverage " . $show( $l['coverage'] ?? null ) . ', reason ' . $show( $l['reason'] ?? null ) . ')' );
			} elseif ( 'complete' === ( $l['coverage'] ?? null ) ) {
				if ( $c !== $n || false !== ( $l['count_is_lower_bound'] ?? null ) ) {
					$fail( 'logs', "method $id complete log count " . $show( $c ) . ", expected $n" );
				}
			} elseif ( 'partial' === ( $l['coverage'] ?? null ) && $is_bound( 'logs', $l['reason'] ?? null ) ) {
				if ( ! is_int( $c ) || $c < 1 || $c > $n || true !== ( $l['count_is_lower_bound'] ?? null ) ) {
					$fail( 'logs', "method $id bounded log count " . $show( $c ) . " outside 1..$n or not a lower bound" );
				}
			} else {
				$fail( 'logs', "method $id log coverage " . $show( $l['coverage'] ?? null ) . ', reason ' . $show( $l['reason'] ?? null ) . ' (not a documented bound)' );
			}
		}

		$exact_required = ! empty( $opts['require_exact_jobs'] );
		foreach ( (array) ( $expect['jobs'] ?? array() ) as $id => $n ) {
			$n = (int) $n;
			$j = (array) ( $rows[ $id ]['errors']['jobs'] ?? array() );
			$c = $j['failed'] ?? null;
			if ( $n <= 0 ) {
				$fail( 'jobs', "the fixture has no failed $id jobs in the window (workload not seeded)" );
			} elseif ( true !== ( $j['checked'] ?? null ) || true !== ( $j['applicable'] ?? null ) ) {
				$fail( 'jobs', "method $id job check not read (coverage " . $show( $j['coverage'] ?? null ) . ', reason ' . $show( $j['reason'] ?? null ) . ')' );
			} elseif ( 'complete' === ( $j['coverage'] ?? null ) ) {
				if ( $c !== $n || false !== ( $j['count_is_lower_bound'] ?? null ) ) {
					$fail( 'jobs', "method $id exact failed count " . $show( $c ) . ", expected $n" );
				}
			} elseif ( 'partial' === ( $j['coverage'] ?? null ) && $is_bound( 'jobs', $j['reason'] ?? null ) ) {
				if ( $exact_required ) {
					$fail( 'jobs', "method $id read on the sampled store; the exact database-store path is required" );
				} elseif ( ! is_int( $c ) || $c < 0 || $c > $n || true !== ( $j['count_is_lower_bound'] ?? null ) ) {
					$fail( 'jobs', "method $id sampled failed count " . $show( $c ) . " outside 0..$n or not a lower bound" );
				}
			} else {
				$fail( 'jobs', "method $id job coverage " . $show( $j['coverage'] ?? null ) . ', reason ' . $show( $j['reason'] ?? null ) . ' (not a documented bound)' );
			}
		}

		return array(
			'ok'       => array() === $failures,
			'readers'  => $readers,
			'failures' => $failures,
		);
	}
}
