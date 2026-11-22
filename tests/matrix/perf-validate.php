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
 *   sampled lower bound (sampled_store) only when the exact database store is not required;
 * - outside (1.2.1): the excluded-origin population was observed and kept apart (C3/C4/C7): every
 *   inventory-only ID has an inventory_only row, status unknown, outside activity true, checkout
 *   activity false, no quantities, no diagnostics read and no finding; every mixed ID stays
 *   checkout_health with both activities true (its checkout counts are the orders reader's exact
 *   ones); the inventory block was read and is not truncated.
 *
 * blzmcp_perf_validate_cap() checks one method-cap stress result (1,000 or 1,001 distinct outside
 * IDs, C4): the identity map is cut at 1,000 with methods_truncated and the cap reason only above
 * it, rows stay one per safe ID in ID order, and the checkout control is unchanged.
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
			'outside'   => true,
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

		// Outside verified checkout activity (1.2.1): observed, listed, never assessed or counted.
		$out = (array) ( $expect['outside'] ?? array() );
		$io  = array_values( (array) ( $out['inventory_only'] ?? array() ) );
		$mx  = array_values( (array) ( $out['mixed'] ?? array() ) );
		if ( (int) ( $out['orders'] ?? 0 ) <= 0 || count( $io ) + count( $mx ) < 10 ) {
			$fail( 'outside', 'the fixture expects no excluded-origin population across 10 methods (workload not seeded)' );
		} else {
			$inv = (array) ( $result['inventory'] ?? array() );
			if ( true !== ( $inv['checked'] ?? null ) || false !== ( $inv['methods_truncated'] ?? null ) ) {
				$fail( 'outside', 'inventory checked ' . $show( $inv['checked'] ?? null ) . ', methods_truncated ' . $show( $inv['methods_truncated'] ?? null ) );
			}
			$finding_ids = array();
			foreach ( (array) ( $result['findings'] ?? array() ) as $f ) {
				if ( is_array( $f ) && is_string( $f['method_id'] ?? null ) ) {
					$finding_ids[ $f['method_id'] ] = true;
				}
			}
			foreach ( $io as $id ) {
				$m = $rows[ $id ] ?? null;
				if ( null === $m ) {
					$fail( 'outside', "inventory-only method $id missing" );
					continue;
				}
				$act    = (array) ( $m['activity'] ?? array() );
				$checks = array( $m['quiet'] ?? array(), $m['stuck'] ?? array(), $m['errors']['logs'] ?? array(), $m['errors']['jobs'] ?? array(), $m['errors']['webhook'] ?? array() );
				$read   = array_filter( $checks, static fn( $c ) => false !== ( $c['checked'] ?? null ) );
				if ( 'inventory_only' !== ( $m['assessment'] ?? null ) || 'unknown' !== ( $m['status'] ?? null ) ) {
					$fail( 'outside', "method $id assessment " . $show( $m['assessment'] ?? null ) . ', status ' . $show( $m['status'] ?? null ) );
				} elseif ( true !== ( $act['outside_checkout_observed'] ?? null ) || false !== ( $act['checkout_observed'] ?? null ) ) {
					$fail( 'outside', "method $id activity checkout " . $show( $act['checkout_observed'] ?? null ) . ', outside ' . $show( $act['outside_checkout_observed'] ?? null ) );
				} elseif ( null !== ( $m['quiet']['taken_28d'] ?? null ) || array() !== $read || array() !== (array) ( $m['stuck']['examples'] ?? array() ) ) {
					$fail( 'outside', "method $id has a quantity, an example or a check that was read" );
				} elseif ( isset( $finding_ids[ $id ] ) ) {
					$fail( 'outside', "method $id has a finding" );
				}
			}
			foreach ( $mx as $id ) {
				$m   = $rows[ $id ] ?? null;
				$act = (array) ( $m['activity'] ?? array() );
				if ( null === $m || 'checkout_health' !== ( $m['assessment'] ?? null ) || true !== ( $act['checkout_observed'] ?? null ) || true !== ( $act['outside_checkout_observed'] ?? null ) ) {
					$fail( 'outside', "mixed method $id assessment " . $show( $m['assessment'] ?? null ) . ', activity checkout ' . $show( $act['checkout_observed'] ?? null ) . ', outside ' . $show( $act['outside_checkout_observed'] ?? null ) );
				}
			}
		}

		return array(
			'ok'       => array() === $failures,
			'readers'  => $readers,
			'failures' => $failures,
		);
	}

	/**
	 * Validate one method-cap stress result (C4: at most 1,000 distinct outside IDs are kept).
	 *
	 * @param mixed               $result   The ability's result.
	 * @param int                 $k        Distinct outside IDs seeded (blzmcp_perf_cap_id( 0 .. k-1 )).
	 * @param array<string,mixed> $control  {method: string, taken_28d: int} checkout control.
	 * @param string|null         $cap_text Public reason text of outside_checkout_method_cap (null: read from the plugin).
	 * @param int                 $cap      The identity-map cap.
	 * @return array{ok:bool,failures:string[],listed:int,methods_truncated:mixed,inventory_coverage:mixed}
	 */
	function blzmcp_perf_validate_cap( $result, int $k, array $control, ?string $cap_text = null, int $cap = 1000 ): array {
		$failures = array();
		if ( null === $cap_text ) {
			$cls      = '\\Blaze\\McpToolkit\\Abilities\\PaymentHealth';
			$cap_text = class_exists( $cls ) && method_exists( $cls, 'reason_text' ) ? (string) $cls::reason_text( 'outside_checkout_method_cap' ) : '';
		}
		if ( ! is_array( $result ) ) {
			return array(
				'ok'                 => false,
				'failures'           => array( 'no response' ),
				'listed'             => 0,
				'methods_truncated'  => null,
				'inventory_coverage' => null,
			);
		}
		$methods = array_values( array_filter( (array) ( $result['methods'] ?? array() ), 'is_array' ) );
		$ids     = array_map( static fn( $m ) => (string) ( $m['id'] ?? '' ), $methods );
		$sorted  = $ids;
		sort( $sorted, SORT_STRING );
		if ( $ids !== $sorted || count( $ids ) !== count( array_unique( $ids ) ) ) {
			$failures[] = 'rows are not one per ID in ID order';
		}
		foreach ( $ids as $id ) {
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/', $id ) ) {
				$failures[] = 'unsafe method ID in output';
				break;
			}
		}
		$cap_rows = array_values( array_filter( $methods, static fn( $m ) => str_starts_with( (string) ( $m['id'] ?? '' ), 'blzmcp_cap_' ) ) );
		$listed   = count( $cap_rows );
		$want     = min( $k, $cap );
		if ( $listed !== $want ) {
			$failures[] = "listed $listed cap IDs, expected $want";
		}
		foreach ( $cap_rows as $m ) {
			if ( 'inventory_only' !== ( $m['assessment'] ?? null ) || 'unknown' !== ( $m['status'] ?? null ) || true !== ( $m['activity']['outside_checkout_observed'] ?? null ) ) {
				$failures[] = 'cap ID ' . (string) $m['id'] . ' is not an unassessed inventory-only row';
				break;
			}
		}
		foreach ( (array) ( $result['findings'] ?? array() ) as $f ) {
			if ( str_starts_with( (string) ( $f['method_id'] ?? '' ), 'blzmcp_cap_' ) ) {
				$failures[] = 'a cap ID has a finding';
				break;
			}
		}
		$inv       = (array) ( $result['inventory'] ?? array() );
		$truncated  = $inv['methods_truncated'] ?? null;
		$inv_reason = $inv['reason'] ?? null;
		$cap_shown  = '' !== $cap_text && $inv_reason === $cap_text;
		if ( $k > $cap ) {
			if ( true !== $truncated || 'partial' !== ( $inv['coverage'] ?? null ) || ! $cap_shown ) {
				$failures[] = 'above the cap: methods_truncated ' . var_export( $truncated, true ) . ', coverage ' . var_export( $inv['coverage'] ?? null, true ) . ', cap reason ' . ( $cap_shown ? 'yes' : 'no' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		} elseif ( false !== $truncated || $cap_shown ) {
			$failures[] = 'at or below the cap: methods_truncated ' . var_export( $truncated, true ) . ' or the cap reason is shown'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		if ( 'complete' === ( $result['coverage'] ?? null ) ) {
			$failures[] = 'top-level coverage is complete while inventory-only rows are unassessed';
		}
		$cm = null;
		foreach ( $methods as $m ) {
			if ( ( $control['method'] ?? '' ) === ( $m['id'] ?? null ) ) {
				$cm = $m;
			}
		}
		$sw = (array) ( $result['store_wide'] ?? array() );
		if ( null === $cm || 'checkout_health' !== ( $cm['assessment'] ?? null ) || ( $cm['quiet']['taken_28d'] ?? null ) !== (int) ( $control['taken_28d'] ?? -1 ) ) {
			$failures[] = 'checkout control ' . (string) ( $control['method'] ?? '?' ) . ' changed or missing';
		}
		if ( ( $sw['taken_28d'] ?? null ) !== (int) ( $control['taken_28d'] ?? -1 ) || 'complete' !== ( $sw['coverage'] ?? null ) ) {
			$failures[] = 'store-wide taken_28d ' . var_export( $sw['taken_28d'] ?? null, true ) . ', coverage ' . var_export( $sw['coverage'] ?? null, true ) . ' (control ' . (int) ( $control['taken_28d'] ?? -1 ) . ')'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return array(
			'ok'                 => array() === $failures,
			'failures'           => $failures,
			'listed'             => $listed,
			'methods_truncated'  => $truncated,
			'inventory_coverage' => $inv['coverage'] ?? null,
		);
	}
}
