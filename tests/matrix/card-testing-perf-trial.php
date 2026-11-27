<?php
/**
 * Validity of one card-testing performance trial and of one bound (stress) case (1.3 FEATURE-PLAN
 * item 9, contract C10). Pure PHP, no WordPress call, so it is unit-tested with plain `php`
 * (tests/matrix/test-card-testing-perf-validate.php) and shared by measure-card-testing.php.
 *
 * A timing counts only when the call observed the seeded workload and every reader reported its
 * expected success and counts. Readers (each must stay true):
 *
 * - response: the C8 shape (schema and rule version 1, the requested window, enums, the 5 fixed
 *   protection paths, display caps) and no limitation outside the allowlist below;
 * - workload: the fixture puts failed and accepted orders and a failure burst in the window, and
 *   every verified-checkout assessment has a usable baseline (an empty workload is invalid);
 * - orders: the order scan is complete (totals checked, complete, no reason) and every total and
 *   every per-method, per-origin scope count equals the fixture's at the ability's own now; no
 *   method, network or bucket map was capped; the untimed reader accounting of the same now agrees;
 * - methods: exactly the 3 instant methods, none in test mode;
 * - signals: saved IP and attribution were read for every applicable scope, complete, with no
 *   signal gap (unsupported version, storage, feature, override, unsupported values, caps);
 * - logs: the near-budget diagnostics are in their one expected state. File logger: 7 of 7 files
 *   and 90-100% of the 5,242,880-byte budget, read completely with the exact count. DB logger:
 *   more error rows in the window than the 2,000-row cap, read as partial with reason
 *   log_row_cap, a lower-bound count of exactly 2,000, disclosed as a limitation, and exactly the
 *   Stripe-family scopes partial. The other two families are not applicable (no log mapping).
 *
 * Any other partial state, skipped source or reader failure is a failed trial, never a fast one.
 *
 * blzmcp_card_perf_validate_bound() checks one stress case with its own assertions (C4): the method
 * identity map at 1,000 and 1,001 distinct IDs, and the network map at 5,000 and 5,001 recorded
 * networks among in-window failed orders. Bound cases are never timing trials.
 *
 * The combined workflow cost (C9) is checked here too, once, for both the measurement
 * (measure-card-testing.php workflow) and the receipt validator (card-testing-perf-validate.php):
 * blzmcp_card_perf_workflow_step_problems() accepts only the weekly review's own first 3 planned
 * steps, in plan order (payment health, card testing, then the order totals with subject orders),
 * each called with the plan's own arguments, timed and successful. A missing, reordered,
 * substituted, unplanned or failed step (a WP_Error, success false or an error payload such as the
 * Analytics guard's blzmcp_analytics_unavailable, see blzmcp_card_perf_step_outcome()) is a
 * problem; no call is ever added to the plan. blzmcp_card_perf_analytics_problems() checks the
 * untimed Analytics precondition of that workflow: every eligible order imported, then the guard's
 * gap cache cleared and its probe for exactly the measured period with compare finding no gap.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( ! function_exists( 'blzmcp_card_perf_validate_trial' ) ) {
	/** Readers of a card trial, in the order the receipt lists them. */
	define( 'BLZMCP_CARD_PERF_READERS', array( 'response', 'workload', 'orders', 'methods', 'signals', 'logs' ) );

	/** C3 outcome classes. */
	define( 'BLZMCP_CARD_PERF_CLASSES', array( 'failed_unpaid', 'accepted', 'cancelled_unpaid', 'pending_unpaid', 'checkout_draft', 'other_status' ) );

	/** Counts keys compared exactly against the fixture. */
	define( 'BLZMCP_CARD_PERF_TOTAL_KEYS', array( 'failed_unpaid', 'accepted', 'cancelled_unpaid', 'pending_unpaid', 'checkout_draft', 'other_status', 'checkout_origin', 'store_api_origin', 'missing_origin', 'other_origin', 'unassigned_method', 'invalid_rows' ) );

	/** C7 fixed path IDs, in order. */
	define( 'BLZMCP_CARD_PERF_PATHS', array( 'checkout_page', 'store_api_checkout', 'store_api_order_pay', 'order_pay', 'add_payment_method' ) );

	/** C6 log budgets (PaymentErrors::MAX_FILES, MAX_BYTES, MAX_DB_ROWS). */
	define(
		'BLZMCP_CARD_PERF_LOG_LIMITS',
		array(
			'max_files' => 7,
			'max_bytes' => 5242880,
			'max_rows'  => 2000,
		)
	);

	/** Top-level keys of the C8 response. */
	define( 'BLZMCP_CARD_PERF_KEYS', array( 'schema_version', 'rule_version', 'checked_at', 'since_hours', 'timezone', 'window', 'baseline', 'verdict', 'activity', 'coverage', 'summary', 'totals', 'methods', 'methods_total', 'methods_truncated', 'assessments', 'assessments_total', 'assessments_truncated', 'logs', 'protection', 'examples', 'examples_truncated', 'findings', 'findings_total', 'findings_truncated', 'limitations' ) );

	/** Reader gaps that are signal coverage gaps (never acceptable on a normal trial). */
	define( 'BLZMCP_CARD_PERF_SIGNAL_GAPS', array( 'unsupported_source_version', 'ip_storage_unavailable', 'attribution_storage_unavailable', 'attribution_feature_disabled', 'attribution_override_present', 'attribution_value_unsupported', 'network_cap', 'network_bucket_cell_cap', 'bucket_cell_cap' ) );

	/*
	 * Store limitations that are always disclosed context, not a gap of this workload.
	 * order_signal_versions is the unconditional statement of which WooCommerce sources the IP and
	 * attribution reads were verified against; an unsupported version is still refused, through
	 * unsupported_source_version and the reader's own signal support.
	 */
	define( 'BLZMCP_CARD_PERF_STORE_CONTEXT', array( 'order_state_basis', 'unsaved_and_retried_orders', 'cause_unverified', 'recorded_network_limits', 'missing_origin_orders', 'other_origin_orders', 'unassigned_method_orders', 'low_history', 'order_signal_versions' ) );

	/** Family keys of the 3 methods (PaymentMethods::FAMILIES). */
	define(
		'BLZMCP_CARD_PERF_FAMILIES',
		array(
			'stripe'               => 'stripe',
			'woocommerce_payments' => 'woopayments',
			'square_credit_card'   => 'square',
		)
	);

	/**
	 * Which reader an unexpected limitation blames, or null when it is allowed for this logger.
	 * Allowlist (fail closed): anything not listed is unexpected.
	 *
	 * @param string $scope  Limitation scope.
	 * @param string $code   Limitation code.
	 * @param string $logger file|db.
	 */
	function blzmcp_card_perf_limitation_blame( string $scope, string $code, string $logger ): ?string {
		if ( 'protection' === $scope || in_array( $scope, BLZMCP_CARD_PERF_PATHS, true ) ) {
			return null; // Protection facts are disclosed gaps, never data-inspection gaps (C7/C8).
		}
		switch ( $scope ) {
			case 'attribution':
				return 'attribution_evidence_only' === $code ? null : 'signals';
			case 'display':
				if ( in_array( $code, array( 'examples_display_cap', 'episodes_display_cap', 'findings_display_cap' ), true ) ) {
					return null;
				}
				return 'examples_changed' === $code ? 'orders' : 'response';
			case 'logs':
				return 'decline_detection_unsupported' === $code ? null : 'logs';
			case 'logs:stripe':
				return 'db' === $logger && 'log_row_cap' === $code ? null : 'logs';
			case 'logs:woopayments':
			case 'logs:square':
				return 'no_supported_log_mapping' === $code ? null : 'logs';
			case 'store':
				if ( in_array( $code, BLZMCP_CARD_PERF_STORE_CONTEXT, true ) || ( 'db' === $logger && 'logs_unavailable' === $code ) ) {
					return null;
				}
				if ( in_array( $code, BLZMCP_CARD_PERF_SIGNAL_GAPS, true ) ) {
					return 'signals';
				}
				if ( 'logs_unavailable' === $code ) {
					return 'logs';
				}
				return 'method_facts_unavailable' === $code ? 'methods' : 'orders';
		}
		if ( str_starts_with( $scope, 'method:' ) ) {
			return in_array( substr( $scope, 7 ), BLZMCP_CARD_PERF_METHODS_LIST, true ) && 'test_mode_unknown' === $code ? null : 'methods';
		}
		return str_starts_with( $scope, 'logs:' ) ? 'logs' : 'response';
	}

	/** The 3 workload method IDs (the cycle file's BLZMCP_CARD_PERF_METHODS when loaded). */
	define( 'BLZMCP_CARD_PERF_METHODS_LIST', defined( 'BLZMCP_CARD_PERF_METHODS' ) ? BLZMCP_CARD_PERF_METHODS : array( 'stripe', 'woocommerce_payments', 'square_credit_card' ) );
	defined( 'BLZMCP_CARD_PERF_MAX_METHODS' ) || define( 'BLZMCP_CARD_PERF_MAX_METHODS', 1000 );
	defined( 'BLZMCP_CARD_PERF_MAX_NETWORKS' ) || define( 'BLZMCP_CARD_PERF_MAX_NETWORKS', 5000 );

	/**
	 * Is the key present with a null value? (A missing key is not null here: fail closed. `??`
	 * cannot express this, since it treats a present null as missing.)
	 *
	 * @param mixed  $arr Array.
	 * @param string $key Key.
	 */
	function blzmcp_card_perf_is_null( $arr, string $key ): bool {
		return is_array( $arr ) && array_key_exists( $key, $arr ) && null === $arr[ $key ];
	}

	/**
	 * Printable scalar.
	 *
	 * @param mixed $v Value.
	 */
	function blzmcp_card_perf_show( $v ): string {
		if ( null === $v ) {
			return 'null';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		return is_scalar( $v ) ? (string) $v : gettype( $v );
	}

	/**
	 * Validate one uncached result against the fixture.
	 *
	 * @param mixed               $result The ability's result (array, or anything else on error).
	 * @param array<string,mixed> $expect {since_hours:int, orders: blzmcp_card_perf_expect(), logger:
	 *                                    file|db, logs: {handler_ok, in_window, files, bytes,
	 *                                    max_file_bytes}, limits?: BLZMCP_CARD_PERF_LOG_LIMITS shape}.
	 * @param array<string,mixed> $opts   {reader?: untimed reader accounting at the same now (array),
	 *                                    or absent to skip that cross-check}.
	 * @return array{ok:bool,readers:array<string,bool>,failures:string[]}
	 */
	function blzmcp_card_perf_validate_trial( $result, array $expect, array $opts = array() ): array {
		$readers  = array_fill_keys( BLZMCP_CARD_PERF_READERS, true );
		$failures = array();
		$fail     = static function ( string $reader, string $why ) use ( &$readers, &$failures ): void {
			$readers[ $reader ] = false;
			$failures[]         = $reader . ': ' . $why;
		};
		$show     = 'blzmcp_card_perf_show';
		$logger   = (string) ( $expect['logger'] ?? '' );
		$limits   = (array) ( $expect['limits'] ?? BLZMCP_CARD_PERF_LOG_LIMITS );
		$want     = (array) ( $expect['orders'] ?? array() );

		if ( ! in_array( $logger, array( 'file', 'db' ), true ) ) {
			$fail( 'logs', 'unknown logger ' . $show( $logger ) );
		}
		if ( ! is_array( $result ) ) {
			foreach ( BLZMCP_CARD_PERF_READERS as $r ) {
				$fail( $r, 'no response (' . ( is_object( $result ) ? get_class( $result ) : gettype( $result ) ) . ')' );
			}
			return array(
				'ok'       => false,
				'readers'  => $readers,
				'failures' => $failures,
			);
		}

		// Response shape (C8).
		$missing = array_diff( BLZMCP_CARD_PERF_KEYS, array_keys( $result ) );
		if ( array() !== $missing ) {
			$fail( 'response', 'missing keys ' . implode( ',', $missing ) );
		}
		if ( 1 !== ( $result['schema_version'] ?? null ) || 1 !== ( $result['rule_version'] ?? null ) ) {
			$fail( 'response', 'schema ' . $show( $result['schema_version'] ?? null ) . ', rule ' . $show( $result['rule_version'] ?? null ) );
		}
		if ( ( $result['since_hours'] ?? null ) !== (int) ( $expect['since_hours'] ?? -1 ) ) {
			$fail( 'response', 'since_hours ' . $show( $result['since_hours'] ?? null ) . ', expected ' . (int) ( $expect['since_hours'] ?? -1 ) );
		}
		if ( ! in_array( $result['verdict'] ?? null, array( 'likely_attack', 'possible_attack', 'no_sign', 'unknown' ), true )
			|| ! in_array( $result['activity'] ?? null, array( 'recent', 'earlier_in_window', 'not_established', 'unknown' ), true )
			|| ! in_array( $result['coverage'] ?? null, array( 'complete', 'partial', 'unavailable' ), true ) ) {
			$fail( 'response', 'verdict ' . $show( $result['verdict'] ?? null ) . ', activity ' . $show( $result['activity'] ?? null ) . ', coverage ' . $show( $result['coverage'] ?? null ) );
		}
		$paths = array_values( array_filter( (array) ( $result['protection'] ?? array() ), 'is_array' ) );
		if ( BLZMCP_CARD_PERF_PATHS !== array_map( static fn( $p ) => $p['id'] ?? null, $paths ) ) {
			$fail( 'response', 'protection paths are not the 5 fixed IDs in order' );
		}
		if ( count( (array) ( $result['examples'] ?? array() ) ) > 5 || count( (array) ( $result['findings'] ?? array() ) ) > 10 ) {
			$fail( 'response', 'examples or findings over their display cap' );
		}

		// Workload: something to measure, and a usable baseline (an empty workload is invalid).
		$wt = (array) ( $want['totals'] ?? array() );
		if ( (int) ( $wt['failed_unpaid'] ?? 0 ) <= 0 || (int) ( $wt['accepted'] ?? 0 ) <= 0 ) {
			$fail( 'workload', 'the fixture expects no failed or no accepted orders in the window (workload not seeded)' );
		}
		if ( (int) ( $want['bursts']['a'] ?? 0 ) <= 0 ) {
			$fail( 'workload', 'the fixture expects no failure burst in the window' );
		}

		// Orders: complete scan, exact totals.
		$totals = (array) ( $result['totals'] ?? array() );
		if ( true !== ( $totals['checked'] ?? null ) || true !== ( $totals['applicable'] ?? null ) || 'complete' !== ( $totals['coverage'] ?? null ) || ! blzmcp_card_perf_is_null( $totals, 'reason_code' ) ) {
			$fail( 'orders', 'order scan checked ' . $show( $totals['checked'] ?? null ) . ', coverage ' . $show( $totals['coverage'] ?? null ) . ', reason ' . $show( $totals['reason_code'] ?? null ) );
		}
		foreach ( BLZMCP_CARD_PERF_TOTAL_KEYS as $k ) {
			$c = (array) ( $totals[ $k ] ?? array() );
			if ( ( $c['value'] ?? null ) !== (int) ( $wt[ $k ] ?? -1 ) || false !== ( $c['lower_bound'] ?? null ) ) {
				$fail( 'orders', "totals.$k " . $show( $c['value'] ?? null ) . ( true === ( $c['lower_bound'] ?? null ) ? ' (lower bound)' : '' ) . ', expected ' . (int) ( $wt[ $k ] ?? -1 ) );
			}
		}
		if ( false !== ( $result['methods_truncated'] ?? null ) || count( BLZMCP_CARD_PERF_METHODS_LIST ) !== ( $result['methods_total'] ?? null ) ) {
			$fail( 'orders', 'method map methods_truncated ' . $show( $result['methods_truncated'] ?? null ) . ', methods_total ' . $show( $result['methods_total'] ?? null ) );
		}
		$assessments = array_values( array_filter( (array) ( $result['assessments'] ?? array() ), 'is_array' ) );
		if ( false !== ( $result['assessments_truncated'] ?? null ) || count( $assessments ) !== ( $result['assessments_total'] ?? null ) ) {
			$fail( 'orders', 'assessments_total ' . $show( $result['assessments_total'] ?? null ) . ' for ' . count( $assessments ) . ' listed, truncated ' . $show( $result['assessments_truncated'] ?? null ) );
		}
		$by = array();
		foreach ( $assessments as $a ) {
			$m = $a['method_id'] ?? null;
			if ( null !== $m && ! in_array( $m, BLZMCP_CARD_PERF_METHODS_LIST, true ) ) {
				$fail( 'orders', 'assessment for an unexpected method' );
				continue;
			}
			$by[ ( null === $m ? '*' : $m ) . '|' . (string) ( $a['origin_scope'] ?? '' ) ] = $a;
		}
		foreach ( array( 'verified_checkout', 'missing_origin' ) as $scope ) {
			foreach ( BLZMCP_CARD_PERF_METHODS_LIST as $m ) {
				$exp = (array) ( $want['scopes'][ $scope ][ $m ] ?? array() );
				$a   = $by[ $m . '|' . $scope ] ?? null;
				if ( null === $a ) {
					if ( array_sum( $exp ) > 0 || 'verified_checkout' === $scope ) {
						$fail( 'orders', "no assessment for $m ($scope)" );
					}
					continue;
				}
				foreach ( BLZMCP_CARD_PERF_CLASSES as $class ) {
					$c = (array) ( $a['counts'][ $class ] ?? array() );
					if ( ( $c['value'] ?? null ) !== (int) ( $exp[ $class ] ?? 0 ) || false !== ( $c['lower_bound'] ?? null ) ) {
						$fail( 'orders', "$m ($scope) $class " . $show( $c['value'] ?? null ) . ', expected ' . (int) ( $exp[ $class ] ?? 0 ) );
					}
				}
				if ( 'complete' !== ( $a['counts']['coverage'] ?? null ) ) {
					$fail( 'orders', "$m ($scope) counts coverage " . $show( $a['counts']['coverage'] ?? null ) );
				}
			}
		}
		if ( array_key_exists( 'reader', $opts ) ) {
			$rd = $opts['reader'];
			if ( ! is_array( $rd ) ) {
				$fail( 'orders', 'no untimed reader accounting' );
			} else {
				$caps = (array) ( $rd['caps'] ?? array() );
				if ( true !== ( $rd['ok'] ?? null ) || true !== ( $rd['scan_complete'] ?? null ) || ! blzmcp_card_perf_is_null( $rd, 'reason' ) || 'complete' !== ( $rd['coverage'] ?? null ) ) {
					$fail( 'orders', 'reader accounting: scan_complete ' . $show( $rd['scan_complete'] ?? null ) . ', reason ' . $show( $rd['reason'] ?? null ) . ', coverage ' . $show( $rd['coverage'] ?? null ) );
				}
				if ( false !== ( $rd['methods_truncated'] ?? null ) || false !== ( $rd['invalid_overflow'] ?? null ) || 0 !== ( $rd['draft_anomalies'] ?? null ) ) {
					$fail( 'orders', 'reader accounting: method map or invalid-row map capped, or draft anomalies' );
				}
				foreach ( array( 'bucket_cells', 'network_ids', 'network_cells' ) as $cap ) {
					if ( false !== ( $caps[ $cap ] ?? null ) ) {
						$fail( 'bucket_cells' === $cap ? 'orders' : 'signals', "reader accounting: $cap cap reached" );
					}
				}
				foreach ( BLZMCP_CARD_PERF_TOTAL_KEYS as $k ) {
					if ( ( $rd['totals'][ $k ] ?? null ) !== (int) ( $wt[ $k ] ?? -1 ) ) {
						$fail( 'orders', "reader accounting totals.$k " . $show( $rd['totals'][ $k ] ?? null ) . ', expected ' . (int) ( $wt[ $k ] ?? -1 ) );
					}
				}
				if ( true !== ( $rd['signals']['ip']['supported'] ?? null ) || true !== ( $rd['signals']['attribution']['supported'] ?? null ) ) {
					$fail( 'signals', 'reader accounting: ip ' . $show( $rd['signals']['ip']['reason'] ?? null ) . ', attribution ' . $show( $rd['signals']['attribution']['reason'] ?? null ) );
				}
			}
		}

		// Methods: exactly the 3 instant methods, none positively in test mode.
		$methods = array_values( array_filter( (array) ( $result['methods'] ?? array() ), 'is_array' ) );
		$ids     = array_map( static fn( $m ) => (string) ( $m['id'] ?? '' ), $methods );
		$sorted  = BLZMCP_CARD_PERF_METHODS_LIST;
		sort( $sorted, SORT_STRING );
		if ( $ids !== $sorted ) {
			$fail( 'methods', 'methods ' . implode( ',', $ids ) . ', expected ' . implode( ',', $sorted ) );
		}
		foreach ( $methods as $m ) {
			if ( 'instant' !== ( $m['kind'] ?? null ) || true === ( $m['test_mode'] ?? null ) ) {
				$fail( 'methods', 'method ' . (string) ( $m['id'] ?? '?' ) . ' kind ' . $show( $m['kind'] ?? null ) . ', test_mode ' . $show( $m['test_mode'] ?? null ) );
			}
		}

		// Signals and per-assessment coverage. Expected partial (DB logger only): exactly the scopes
		// whose families include Stripe, blocked by the capped Stripe log.
		foreach ( $by as $key => $a ) {
			list( $m, $scope ) = explode( '|', $key );
			if ( true !== ( $a['applicable'] ?? null ) ) {
				$fail( 'orders', "assessment $m ($scope) is not applicable" );
				continue;
			}
			foreach ( array( 'networks', 'attribution' ) as $sig ) {
				$s = (array) ( $a['latest_hour'][ $sig ] ?? array() );
				if ( true !== ( $s['checked'] ?? null ) || 'complete' !== ( $s['coverage'] ?? null ) || in_array( $s['reason_code'] ?? null, BLZMCP_CARD_PERF_SIGNAL_GAPS, true ) ) {
					$fail( 'signals', "$m ($scope) latest hour $sig checked " . $show( $s['checked'] ?? null ) . ', coverage ' . $show( $s['coverage'] ?? null ) . ', reason ' . $show( $s['reason_code'] ?? null ) );
				}
			}
			$stripe_scope = '*' === $m || 'stripe' === $m;
			$want_cov     = 'db' === $logger && $stripe_scope ? 'partial' : 'complete';
			if ( ( $a['coverage'] ?? null ) !== $want_cov ) {
				$fail( 'db' === $logger && $stripe_scope ? 'logs' : 'orders', "assessment $m ($scope) coverage " . $show( $a['coverage'] ?? null ) . ', expected ' . $want_cov . ' (reason ' . $show( $a['reason_code'] ?? null ) . ')' );
			}
			if ( 'verified_checkout' === $scope && true !== ( $a['baseline_sufficient'] ?? null ) ) {
				$fail( 'workload', "assessment $m ($scope) has no usable baseline" );
			}
		}
		if ( ! isset( $by['*|verified_checkout'] ) ) {
			$fail( 'orders', 'no verified-checkout store aggregate assessment' );
		}

		// Logs: the near-budget state for this logger, nothing else.
		$lx   = (array) ( $expect['logs'] ?? array() );
		$sums = array();
		foreach ( (array) ( $result['logs'] ?? array() ) as $s ) {
			if ( is_array( $s ) && is_string( $s['family'] ?? null ) ) {
				$sums[ $s['family'] ] = $s;
			}
		}
		$fams = array_keys( $sums );
		sort( $fams, SORT_STRING );
		if ( array( 'square', 'stripe', 'woopayments' ) !== $fams ) {
			$fail( 'logs', 'log summaries for ' . implode( ',', $fams ) . ', expected square,stripe,woopayments' );
		}
		foreach ( array( 'square', 'woopayments' ) as $f ) {
			$s = (array) ( $sums[ $f ] ?? array() );
			if ( false !== ( $s['applicable'] ?? null ) || 'not_applicable' !== ( $s['coverage'] ?? null ) || 'no_supported_log_mapping' !== ( $s['reason_code'] ?? null ) ) {
				$fail( 'logs', "$f log summary applicable " . $show( $s['applicable'] ?? null ) . ', coverage ' . $show( $s['coverage'] ?? null ) . ', reason ' . $show( $s['reason_code'] ?? null ) );
			}
		}
		$st  = (array) ( $sums['stripe'] ?? array() );
		$cnt = (array) ( $st['count'] ?? array() );
		$cat = 0;
		foreach ( (array) ( $st['categories'] ?? array() ) as $c ) {
			$cat += is_array( $c ) ? (int) ( $c['count'] ?? 0 ) : 0;
		}
		$in_window = (int) ( $lx['in_window'] ?? -1 );
		if ( true !== ( $lx['handler_ok'] ?? null ) ) {
			$fail( 'logs', 'the WooCommerce log handler setting is not the ' . $logger . ' logger' );
		}
		if ( 'file' === $logger ) {
			$files = (int) ( $lx['files'] ?? -1 );
			$bytes = (int) ( $lx['bytes'] ?? -1 );
			$per   = $files > 0 ? intdiv( (int) $limits['max_bytes'], $files ) : 0;
			if ( $files !== (int) $limits['max_files'] || $bytes > (int) $limits['max_bytes'] || $bytes * 10 < (int) $limits['max_bytes'] * 9 || (int) ( $lx['max_file_bytes'] ?? PHP_INT_MAX ) > $per ) {
				$fail( 'logs', "file logger fixture is not at its near-budget state ($files of {$limits['max_files']} files, $bytes of {$limits['max_bytes']} bytes)" );
			}
			if ( $in_window <= 0 ) {
				$fail( 'logs', 'the file logger fixture has no error entry in the window (workload not seeded)' );
			}
			if ( true !== ( $st['checked'] ?? null ) || true !== ( $st['applicable'] ?? null ) || 'complete' !== ( $st['coverage'] ?? null ) || ! blzmcp_card_perf_is_null( $st, 'reason_code' )
				|| 'woocommerce_log_file' !== ( $st['source'] ?? null ) || ( $cnt['value'] ?? null ) !== $in_window || false !== ( $cnt['lower_bound'] ?? null ) || $cat !== $in_window ) {
				$fail( 'logs', 'stripe file log coverage ' . $show( $st['coverage'] ?? null ) . ', reason ' . $show( $st['reason_code'] ?? null ) . ', source ' . $show( $st['source'] ?? null ) . ', count ' . $show( $cnt['value'] ?? null ) . ", expected complete with $in_window" );
			}
		} elseif ( 'db' === $logger ) {
			$cap = (int) $limits['max_rows'];
			if ( $in_window <= $cap ) {
				$fail( 'logs', "DB logger fixture has $in_window error rows in the window, not more than the $cap-row cap (not near budget)" );
			}
			if ( true !== ( $st['checked'] ?? null ) || true !== ( $st['applicable'] ?? null ) || 'partial' !== ( $st['coverage'] ?? null ) || 'log_row_cap' !== ( $st['reason_code'] ?? null )
				|| 'woocommerce_log_db' !== ( $st['source'] ?? null ) || ( $cnt['value'] ?? null ) !== $cap || true !== ( $cnt['lower_bound'] ?? null ) || $cat !== $cap ) {
				$fail( 'logs', 'stripe DB log coverage ' . $show( $st['coverage'] ?? null ) . ', reason ' . $show( $st['reason_code'] ?? null ) . ', source ' . $show( $st['source'] ?? null ) . ', count ' . $show( $cnt['value'] ?? null ) . ", expected partial log_row_cap with a lower bound of $cap" );
			}
		}

		// Limitations: allowlist, and the DB logger's expected gap must be disclosed.
		$seen = array();
		foreach ( (array) ( $result['limitations'] ?? array() ) as $l ) {
			if ( ! is_array( $l ) || ! is_string( $l['scope'] ?? null ) || ! is_string( $l['code'] ?? null ) ) {
				$fail( 'response', 'malformed limitation' );
				continue;
			}
			$seen[ $l['scope'] . '/' . $l['code'] ] = true;
			$blame                                  = blzmcp_card_perf_limitation_blame( $l['scope'], $l['code'], $logger );
			if ( null !== $blame ) {
				$fail( $blame, 'unexpected limitation ' . $l['scope'] . '/' . $l['code'] );
			}
		}
		if ( ! isset( $seen['store/recorded_network_limits'] ) ) {
			$fail( 'signals', 'saved IPs were not read (no recorded-network limitation)' );
		}
		if ( 'db' === $logger && ( ! isset( $seen['logs:stripe/log_row_cap'] ) || ! isset( $seen['store/logs_unavailable'] ) ) ) {
			$fail( 'logs', 'the DB logger row cap is not disclosed as a limitation' );
		}
		if ( 'partial' === ( $result['coverage'] ?? null ) && 'file' === $logger ) {
			$unknown = array_filter( $paths, static fn( $p ) => 'unknown' === ( $p['protection_state'] ?? null ) );
			if ( array() === $unknown ) {
				$fail( 'response', 'top-level coverage partial with every data source complete and no unknown protection path' );
			}
		}

		return array(
			'ok'       => array() === $failures,
			'readers'  => $readers,
			'failures' => $failures,
		);
	}

	/**
	 * Validate one bound (stress) case. Never a timing trial.
	 *
	 * @param mixed  $result The ability's result.
	 * @param string $which  methods|networks.
	 * @param int    $k      Distinct method IDs or recorded networks seeded.
	 * @return array{ok:bool,failures:string[],observed:array<string,mixed>}
	 */
	function blzmcp_card_perf_validate_bound( $result, string $which, int $k ): array {
		$failures = array();
		$show     = 'blzmcp_card_perf_show';
		if ( ! is_array( $result ) ) {
			return array(
				'ok'       => false,
				'failures' => array( 'no response' ),
				'observed' => array(),
			);
		}
		$codes = array();
		foreach ( (array) ( $result['limitations'] ?? array() ) as $l ) {
			if ( is_array( $l ) && is_string( $l['scope'] ?? null ) && is_string( $l['code'] ?? null ) ) {
				$codes[ $l['scope'] . '/' . $l['code'] ] = true;
			}
		}
		$totals   = (array) ( $result['totals'] ?? array() );
		$observed = array(
			'coverage'      => $result['coverage'] ?? null,
			'totals_cover'  => $totals['coverage'] ?? null,
			'limitations'   => array_values( array_filter( array_keys( $codes ), static fn( $c ) => 1 === preg_match( '#/(method_cap|network_cap|network_bucket_cell_cap|bucket_cell_cap)$#', $c ) ) ),
		);
		if ( 'complete' !== ( $totals['coverage'] ?? null ) || true !== ( $totals['checked'] ?? null ) ) {
			$failures[] = 'order scan not complete (totals coverage ' . $show( $totals['coverage'] ?? null ) . ')';
		}
		if ( 'methods' === $which ) {
			$cap     = BLZMCP_CARD_PERF_MAX_METHODS;
			$rows    = array_values( array_filter( (array) ( $result['methods'] ?? array() ), static fn( $m ) => is_array( $m ) && str_starts_with( (string) ( $m['id'] ?? '' ), 'blzmcp_ctcap_' ) ) );
			$ids     = array_map( static fn( $m ) => (string) $m['id'], $rows );
			$listed  = count( $ids );
			$want    = min( $k, $cap );
			$wantids = array();
			for ( $n = 0; $n < $want; $n++ ) {
				$wantids[] = sprintf( 'blzmcp_ctcap_%04d', $n );
			}
			$observed += array(
				'listed'            => $listed,
				'methods_truncated' => $result['methods_truncated'] ?? null,
				'methods_total'     => $result['methods_total'] ?? null,
				'assessments_total' => $result['assessments_total'] ?? null,
			);
			if ( $ids !== $wantids ) {
				$failures[] = "listed $listed cap IDs, expected the first $want in ID order";
			}
			foreach ( $rows as $m ) {
				if ( 'unknown' !== ( $m['kind'] ?? null ) ) {
					$failures[] = 'a cap ID is not of unknown kind';
					break;
				}
			}
			if ( ( $totals['accepted']['value'] ?? null ) !== $k ) {
				$failures[] = 'totals.accepted ' . $show( $totals['accepted']['value'] ?? null ) . ", expected $k (safe totals continue past the cap)";
			}
			$capped = isset( $codes['store/method_cap'] );
			if ( $k > $cap ) {
				if ( true !== ( $result['methods_truncated'] ?? null ) || ! blzmcp_card_perf_is_null( $result, 'methods_total' ) || ! blzmcp_card_perf_is_null( $result, 'assessments_total' ) || ! $capped || 'partial' !== ( $result['coverage'] ?? null ) ) {
					$failures[] = 'above the cap: methods_truncated ' . $show( $result['methods_truncated'] ?? null ) . ', methods_total ' . $show( $result['methods_total'] ?? null ) . ', assessments_total ' . $show( $result['assessments_total'] ?? null ) . ', method_cap limitation ' . ( $capped ? 'yes' : 'no' ) . ', coverage ' . $show( $result['coverage'] ?? null );
				}
			} elseif ( false !== ( $result['methods_truncated'] ?? null ) || ( $result['methods_total'] ?? null ) !== $k || ! is_int( $result['assessments_total'] ?? null ) || $capped ) {
				$failures[] = 'at or below the cap: methods_truncated ' . $show( $result['methods_truncated'] ?? null ) . ', methods_total ' . $show( $result['methods_total'] ?? null ) . ', method_cap limitation ' . ( $capped ? 'yes' : 'no' );
			}
		} elseif ( 'networks' === $which ) {
			$cap = BLZMCP_CARD_PERF_MAX_NETWORKS;
			$a   = null;
			foreach ( (array) ( $result['assessments'] ?? array() ) as $row ) {
				if ( is_array( $row ) && 'woocommerce_payments' === ( $row['method_id'] ?? null ) && 'verified_checkout' === ( $row['origin_scope'] ?? null ) ) {
					$a = $row;
				}
			}
			$net       = (array) ( $a['latest_hour']['networks'] ?? array() );
			$observed += array(
				'failed'           => $totals['failed_unpaid']['value'] ?? null,
				'network_coverage' => $net['coverage'] ?? null,
				'network_reason'   => $net['reason_code'] ?? null,
				'groups'           => $net['groups'] ?? null,
			);
			if ( null === $a ) {
				$failures[] = 'no woocommerce_payments verified-checkout assessment';
			}
			if ( ( $totals['failed_unpaid']['value'] ?? null ) !== $k || false !== ( $totals['failed_unpaid']['lower_bound'] ?? null ) ) {
				$failures[] = 'totals.failed_unpaid ' . $show( $totals['failed_unpaid']['value'] ?? null ) . ", expected exactly $k (safe totals continue past the cap)";
			}
			$capped = isset( $codes['store/network_cap'] );
			if ( isset( $codes['store/network_bucket_cell_cap'] ) || isset( $codes['store/bucket_cell_cap'] ) ) {
				$failures[] = 'a bucket-cell cap was reached instead of the network identity cap';
			}
			if ( $k > $cap ) {
				if ( ! $capped || 'partial' !== ( $net['coverage'] ?? null ) || 'network_cap' !== ( $net['reason_code'] ?? null ) || ! blzmcp_card_perf_is_null( $net, 'groups' ) || 'partial' !== ( $result['coverage'] ?? null ) ) {
					$failures[] = 'above the cap: network_cap limitation ' . ( $capped ? 'yes' : 'no' ) . ', latest hour networks ' . $show( $net['coverage'] ?? null ) . '/' . $show( $net['reason_code'] ?? null ) . ', groups ' . $show( $net['groups'] ?? null ) . ', coverage ' . $show( $result['coverage'] ?? null );
				}
			} elseif ( $capped || 'complete' !== ( $net['coverage'] ?? null ) || ! blzmcp_card_perf_is_null( $net, 'reason_code' ) || ! is_int( $net['groups'] ?? null ) || $net['groups'] < 1 ) {
				$failures[] = 'at or below the cap: network_cap limitation ' . ( $capped ? 'yes' : 'no' ) . ', latest hour networks ' . $show( $net['coverage'] ?? null ) . '/' . $show( $net['reason_code'] ?? null ) . ', groups ' . $show( $net['groups'] ?? null );
			}
		} else {
			$failures[] = 'unknown bound case ' . $which;
		}
		return array(
			'ok'       => array() === $failures,
			'failures' => $failures,
			'observed' => $observed,
		);
	}

	/** The measured workflow and its period (the weekly review's default, 7 days with comparison). */
	define( 'BLZMCP_CARD_PERF_WORKFLOW', 'blaze-mcp-toolkit/weekly-store-review' );
	define( 'BLZMCP_CARD_PERF_WORKFLOW_PERIOD', 'last_7_days' );

	/** The workflow's first 3 planned steps, in plan order (C9): ability and, for totals, subject. */
	define(
		'BLZMCP_CARD_PERF_WORKFLOW_STEPS',
		array(
			array(
				'ability' => 'blaze-mcp-toolkit/payment-health',
				'subject' => null,
			),
			array(
				'ability' => 'blaze-mcp-toolkit/card-testing',
				'subject' => null,
			),
			array(
				'ability' => 'wc-analytics/totals',
				'subject' => 'orders',
			),
		)
	);

	/** The Analytics guard's error code and message token (AnalyticsGuard::ERROR_CODE, MESSAGE_TOKEN). */
	define( 'BLZMCP_CARD_PERF_ANALYTICS_ERROR', 'blzmcp_analytics_unavailable' );
	define( 'BLZMCP_CARD_PERF_ANALYTICS_TOKEN', '[ANALYTICS_UNAVAILABLE]' );

	/**
	 * Is this a finite number (int or float, not bool)?
	 *
	 * @param mixed $v Value.
	 */
	function blzmcp_card_perf_num( $v ): bool {
		return ( is_int( $v ) || is_float( $v ) ) && is_finite( (float) $v );
	}

	/**
	 * The ordered steps of a generated workflow plan, each {ability, arguments} with the arguments
	 * as an array (the plan's output schema types them as objects). Null when the value is not a
	 * plan with a list of steps. The plan is read as it is: nothing is added, dropped or reordered.
	 *
	 * @param mixed $plan The workflow ability's result.
	 * @return array<int,array{ability:string,arguments:array<string,mixed>}>|null
	 */
	function blzmcp_card_perf_plan_steps( $plan ): ?array {
		if ( is_object( $plan ) && method_exists( $plan, 'get_error_code' ) ) {
			return null;
		}
		if ( is_object( $plan ) || is_array( $plan ) ) {
			$json = json_encode( $plan ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain PHP, no WordPress here.
			$plan = false === $json ? null : json_decode( $json, true );
		}
		if ( ! is_array( $plan ) || ! is_array( $plan['steps'] ?? null ) || ! array_is_list( $plan['steps'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $plan['steps'] as $step ) {
			$out[] = array(
				'ability'   => is_array( $step ) && is_string( $step['ability'] ?? null ) ? $step['ability'] : '',
				'arguments' => is_array( $step ) && is_array( $step['arguments'] ?? null ) ? $step['arguments'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Did one executed workflow step succeed? A WP_Error, anything but a non-empty array, success
	 * false, or an error payload (a top-level code, error_code or error, or a message carrying the
	 * Analytics guard's token) is a failure with its code; otherwise success with no code.
	 *
	 * @param mixed $result The ability's result.
	 * @return array{success:bool,error_code:?string}
	 */
	function blzmcp_card_perf_step_outcome( $result ): array {
		$failed = static fn( string $code ): array => array(
			'success'    => false,
			'error_code' => '' === $code ? 'error' : $code,
		);
		if ( is_object( $result ) && method_exists( $result, 'get_error_code' ) ) {
			return $failed( (string) $result->get_error_code() );
		}
		if ( ! is_array( $result ) || array() === $result ) {
			return $failed( is_array( $result ) ? 'empty_result' : 'not_a_result_' . gettype( $result ) );
		}
		$code = null;
		foreach ( array( 'code', 'error_code' ) as $k ) {
			if ( null === $code && is_string( $result[ $k ] ?? null ) && '' !== $result[ $k ] ) {
				$code = $result[ $k ];
			}
		}
		if ( array_key_exists( 'success', $result ) && true !== $result['success'] ) {
			return $failed( $code ?? 'success_false' );
		}
		if ( null !== $code ) {
			return $failed( $code );
		}
		if ( array_key_exists( 'error', $result ) && null !== $result['error'] && false !== $result['error'] ) {
			$e = $result['error'];
			return $failed( is_array( $e ) && is_string( $e['code'] ?? null ) ? $e['code'] : ( is_string( $e ) ? $e : 'error' ) );
		}
		if ( is_string( $result['message'] ?? null ) && str_starts_with( $result['message'], BLZMCP_CARD_PERF_ANALYTICS_TOKEN ) ) {
			return $failed( BLZMCP_CARD_PERF_ANALYTICS_ERROR );
		}
		return array(
			'success'    => true,
			'error_code' => null,
		);
	}

	/**
	 * Every reason the measured workflow step records are not exactly the plan's first 3 steps
	 * (BLZMCP_CARD_PERF_WORKFLOW_STEPS), in plan order, each planned, called with the plan's own
	 * arguments, timed and successful. Empty when they are. Decided on the raw records only, never
	 * on a summary ok flag; shared by the measurement and the receipt validator.
	 *
	 * @param mixed $steps Step records {position, ability, planned, plan_arguments, arguments,
	 *                     elapsed_ms, success, error_code}.
	 * @return string[]
	 */
	function blzmcp_card_perf_workflow_step_problems( $steps ): array {
		$want = BLZMCP_CARD_PERF_WORKFLOW_STEPS;
		$show = 'blzmcp_card_perf_show';
		if ( ! is_array( $steps ) || ! array_is_list( $steps ) ) {
			return array( 'no list of step records' );
		}
		$p = array();
		if ( count( $steps ) !== count( $want ) ) {
			$p[] = count( $steps ) . ' step records, expected exactly the ' . count( $want ) . ' planned steps';
		}
		foreach ( $want as $i => $w ) {
			$n     = $i + 1;
			$label = $w['ability'] . ( null === $w['subject'] ? '' : ' ' . $w['subject'] );
			$s     = $steps[ $i ] ?? null;
			if ( null === $s ) {
				$p[] = "step $n ($label) missing";
				continue;
			}
			if ( ! is_array( $s ) ) {
				$p[] = "step $n is not a record";
				continue;
			}
			if ( ( $s['position'] ?? null ) !== $i ) {
				$p[] = "step $n is not plan position $i (position " . $show( $s['position'] ?? null ) . ')';
			}
			if ( true !== ( $s['planned'] ?? null ) ) {
				$p[] = "step $n ($label) is not a planned step (planned " . $show( $s['planned'] ?? null ) . ')';
			}
			if ( ( $s['ability'] ?? null ) !== $w['ability'] ) {
				$p[] = "step $n is " . $show( $s['ability'] ?? null ) . ", expected {$w['ability']} (substituted or reordered)";
			}
			$args = $s['arguments'] ?? null;
			if ( ! is_array( $args ) || ! is_array( $s['plan_arguments'] ?? null ) || $args !== $s['plan_arguments'] ) {
				$p[] = "step $n was not called with the plan's own arguments";
			}
			if ( null !== $w['subject'] && ( is_array( $args ) ? ( $args['subject'] ?? null ) : null ) !== $w['subject'] ) {
				$p[] = "step $n has subject " . $show( is_array( $args ) ? ( $args['subject'] ?? null ) : null ) . ", expected {$w['subject']} (substituted)";
			}
			if ( true !== ( $s['success'] ?? null ) || ! blzmcp_card_perf_is_null( $s, 'error_code' ) ) {
				$p[] = "step $n ($label) failed (success " . $show( $s['success'] ?? null ) . ', error ' . $show( $s['error_code'] ?? null ) . ')';
			}
			if ( ! blzmcp_card_perf_num( $s['elapsed_ms'] ?? null ) || $s['elapsed_ms'] < 0 ) {
				$p[] = "step $n ($label) has no elapsed_ms";
			}
		}
		return $p;
	}

	/**
	 * Every reason one storage's Analytics readiness record does not establish the measured
	 * workflow's precondition (empty when it does): every eligible order imported (eligible > 0,
	 * imported = eligible, none without its Analytics row, no failed import), then the guard's gap
	 * cache cleared and its probe for exactly the measured period with compare finding no gap.
	 *
	 * @param mixed  $a      Readiness record {period, compare, reason, cache_cleared, eligible,
	 *                       imported, missing, failed_imports, ok}.
	 * @param string $period The measured workflow's period.
	 * @return string[]
	 */
	function blzmcp_card_perf_analytics_problems( $a, string $period ): array {
		$show = 'blzmcp_card_perf_show';
		if ( ! is_array( $a ) ) {
			return array( 'no readiness record' );
		}
		$p = array();
		if ( ( $a['period'] ?? null ) !== $period || true !== ( $a['compare'] ?? null ) ) {
			$p[] = 'probed ' . $show( $a['period'] ?? null ) . ' with compare ' . $show( $a['compare'] ?? null ) . ", not the measured workflow's $period with compare";
		}
		if ( ! array_key_exists( 'reason', $a ) ) {
			$p[] = 'no guard reason recorded';
		} elseif ( null !== $a['reason'] ) {
			$p[] = 'the guard reports ' . $show( $a['reason'] );
		}
		if ( true !== ( $a['cache_cleared'] ?? null ) ) {
			$p[] = "the guard's gap cache was not cleared before the probe";
		}
		$eligible = $a['eligible'] ?? null;
		$imported = $a['imported'] ?? null;
		if ( ! is_int( $eligible ) || $eligible < 1 ) {
			$p[] = 'no eligible orders to import (eligible ' . $show( $eligible ) . ')';
		}
		if ( ! is_int( $imported ) || $imported !== $eligible ) {
			$p[] = 'imported ' . $show( $imported ) . ' of ' . $show( $eligible ) . ' eligible orders';
		}
		if ( 0 !== ( $a['missing'] ?? null ) ) {
			$p[] = $show( $a['missing'] ?? null ) . ' eligible orders without an Analytics row';
		}
		if ( 0 !== ( $a['failed_imports'] ?? null ) ) {
			$p[] = $show( $a['failed_imports'] ?? null ) . ' failed Analytics imports';
		}
		if ( true !== ( $a['ok'] ?? null ) ) {
			$p[] = 'readiness not ok';
		}
		return $p;
	}
}
