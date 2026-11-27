<?php
/**
 * Fail-closed validator for the card-testing performance receipt (1.3 FEATURE-PLAN item 9, C10).
 * Plain PHP on the host, no WordPress:
 *
 *   php tests/matrix/card-testing-perf-validate.php <receipt.json> --candidate-sha256=<hex> --source-sha=<sha> --attempt=<id>
 *
 * Prints "CARD PERF VALID (40 trials, 8 partitions)" and exits 0; or "CARD PERF INVALID: <reason>;
 * <reason>..." and exits 1; or exits 2 when the receipt is missing, unreadable or not a JSON object
 * (a truncated file included) or an argument is missing.
 *
 * Decided on the raw trials only, never on the receipt's own pass, max or all_pass flags: for each
 * of the 8 partitions (hpos and legacy, 48 and 168 hours, file and DB logger) exactly 5 trials,
 * numbered 1-5 once each, every one valid (ok, all 6 readers true, no failure) and strictly below
 * 1500 ms, each with its metrics (query count, rows examined and selected, metadata rows, peak
 * memory, warm time, a query plan). One rule for every partition: 5 of 5 below 1500 ms. No median,
 * no pooling, no dropped or retried trial. Also required: the full workload (50,000 recent and
 * 20,000 older orders, the 3 instant methods, drafts, missing origins, verified per storage), no
 * smoke flag, this candidate, source and attempt, the environment identity, an HTTP cold and warm
 * figure per storage and window, the combined workflow cost per storage (reported apart), every
 * bound case passed and none counted as a trial, every forced reader failure detected, every
 * cleanup clean and the original order storage restored.
 *
 * The combined workflow cost (1.3 wave 3, REVIEW-pass4a R1) is decided on its raw step records,
 * never on its ok flag: exactly the weekly review's own first 3 planned steps in plan order
 * (payment health, card testing, then the order totals with subject orders), each planned, called
 * with the plan's own arguments, timed and successful (no WP_Error, success false or error code
 * such as blzmcp_analytics_unavailable), with the plan's Analytics probe run fresh and the total
 * equal to its steps (blzmcp_card_perf_workflow_step_problems() in card-testing-perf-trial.php).
 * Each storage also needs its Analytics readiness record (analytics_ready): every eligible order
 * imported, then the guard's probe with its gap cache cleared, for exactly the workflow's period
 * with compare, finding no gap (reason null). Every orders cleanup must show no Analytics rows,
 * import jobs, failed imports or fixture customers left, and the cleanup after the trials must
 * show the imported Analytics rows removed.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/card-testing-perf-trial.php';

if ( ! function_exists( 'blzmcp_card_receipt_problems' ) ) {
	define( 'BLZMCP_CARD_RECEIPT_SCHEMA', 'blzmcp-card-performance/1' );
	define( 'BLZMCP_CARD_RECEIPT_BUDGET', 1500 );
	define( 'BLZMCP_CARD_RECEIPT_RULE', '5of5<1500' );
	define( 'BLZMCP_CARD_RECEIPT_STORAGES', array( 'hpos', 'legacy' ) );
	define( 'BLZMCP_CARD_RECEIPT_WINDOWS', array( 48, 168 ) );
	define( 'BLZMCP_CARD_RECEIPT_LOGGERS', array( 'file', 'db' ) );
	define( 'BLZMCP_CARD_RECEIPT_TRIALS', 5 );
	define( 'BLZMCP_CARD_RECEIPT_ORDERS', 50000 );
	define( 'BLZMCP_CARD_RECEIPT_OLD', 20000 );
	define( 'BLZMCP_CARD_RECEIPT_READERS', array( 'logs', 'methods', 'orders', 'response', 'signals', 'workload' ) );
	define( 'BLZMCP_CARD_RECEIPT_FORCES', array( 'orders', 'logs' ) );
	define(
		'BLZMCP_CARD_RECEIPT_BOUNDS',
		array(
			array( 'methods', 1000 ),
			array( 'methods', 1001 ),
			array( 'networks', 5000 ),
			array( 'networks', 5001 ),
		)
	);
	define( 'BLZMCP_CARD_RECEIPT_PHASES', array( 'before', 'diag-file', 'diag-db', 'after', 'bound-methods', 'bound-networks' ) );
	define( 'BLZMCP_CARD_RECEIPT_REMOVING', array( 'after', 'bound-methods', 'bound-networks' ) );

	/**
	 * Is this a finite number (int or float, not bool)?
	 *
	 * @param mixed $v Value.
	 */
	function blzmcp_card_receipt_num( $v ): bool {
		return ( is_int( $v ) || is_float( $v ) ) && is_finite( (float) $v );
	}

	/**
	 * Does the row hold every wanted key with exactly the wanted value (strict)?
	 *
	 * @param mixed               $row  Row.
	 * @param array<string,mixed> $want Key => value.
	 */
	function blzmcp_card_receipt_match( $row, array $want ): bool {
		if ( ! is_array( $row ) ) {
			return false;
		}
		foreach ( $want as $k => $v ) {
			if ( ! array_key_exists( $k, $row ) || $row[ $k ] !== $v ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every reason the receipt cannot pass the gate (empty when it passes).
	 *
	 * @param array<string,mixed> $r    Decoded receipt.
	 * @param array<string,string> $bind {candidate, source, attempt} from the caller.
	 * @return string[]
	 */
	function blzmcp_card_receipt_problems( array $r, array $bind ): array {
		$p   = array();
		$num = 'blzmcp_card_receipt_num';

		if ( BLZMCP_CARD_RECEIPT_SCHEMA !== ( $r['schema'] ?? null ) ) {
			$p[] = 'schema is not ' . BLZMCP_CARD_RECEIPT_SCHEMA;
		}
		if ( ! array_key_exists( 'smoke', $r ) ) {
			$p[] = 'no smoke flag';
		} elseif ( false !== $r['smoke'] ) {
			$p[] = 'smoke receipt (not a gate run' . ( is_array( $r['smoke_reasons'] ?? null ) && array() !== $r['smoke_reasons'] ? ': ' . implode( ', ', array_map( 'strval', $r['smoke_reasons'] ) ) : '' ) . ')';
		}

		// Binding to this candidate, source and attempt.
		$cand = $r['candidate_sha256'] ?? null;
		if ( ! is_string( $cand ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $cand ) || $cand !== $bind['candidate'] ) {
			$p[] = 'candidate_sha256 ' . ( is_string( $cand ) ? $cand : 'missing' ) . ' is not the candidate ' . $bind['candidate'];
		}
		$src = $r['source_sha'] ?? null;
		if ( ! is_string( $src ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $src ) || $src !== $bind['source'] ) {
			$p[] = 'source_sha ' . ( is_string( $src ) ? $src : 'missing' ) . ' is not the source ' . $bind['source'];
		}
		$att = $r['attempt'] ?? null;
		if ( ! is_string( $att ) || '' === $att || $att !== $bind['attempt'] ) {
			$p[] = 'attempt ' . ( is_string( $att ) ? $att : 'missing' ) . ' is not this attempt ' . $bind['attempt'];
		}
		if ( BLZMCP_CARD_RECEIPT_BUDGET !== ( $r['budget_ms'] ?? null ) || BLZMCP_CARD_RECEIPT_RULE !== ( $r['rule'] ?? null ) ) {
			$p[] = 'budget is not 1500 ms under rule 5of5<1500';
		}

		// Environment identity.
		$env  = is_array( $r['environment'] ?? null ) ? $r['environment'] : array();
		$host = is_array( $env['host'] ?? null ) ? $env['host'] : array();
		$lim  = is_array( $env['container_limits'] ?? null ) ? $env['container_limits'] : array();
		$vers = is_array( $env['versions'] ?? null ) ? $env['versions'] : array();
		if ( ! is_string( $host['name'] ?? null ) || '' === $host['name'] || ! is_string( $host['os'] ?? null ) || ! is_int( $host['cpus'] ?? null ) || $host['cpus'] < 1 ) {
			$p[] = 'environment host identity missing';
		}
		if ( ! is_int( $lim['ncpu'] ?? null ) || $lim['ncpu'] < 1 || ! is_int( $lim['mem_total_bytes'] ?? null ) || $lim['mem_total_bytes'] < 1 ) {
			$p[] = 'environment container limits missing';
		}
		$v0 = 1 === count( $vers ) && is_array( $vers[0] ?? null ) ? $vers[0] : array();
		foreach ( array( 'wp', 'wc', 'php', 'db' ) as $k ) {
			if ( ! is_string( $v0[ $k ] ?? null ) || '' === $v0[ $k ] ) {
				$p[] = 'environment versions missing or not one identity (' . $k . ')';
				break;
			}
		}

		// Workload.
		$seed    = is_array( $r['seed'] ?? null ) ? $r['seed'] : array();
		$orders  = $seed['orders'] ?? null;
		$old     = $seed['old_orders'] ?? null;
		$methods = is_array( $seed['methods'] ?? null ) ? array_map( 'strval', $seed['methods'] ) : array();
		sort( $methods, SORT_STRING );
		if ( ! is_int( $orders ) || $orders < BLZMCP_CARD_RECEIPT_ORDERS || ! is_int( $old ) || $old < BLZMCP_CARD_RECEIPT_OLD ) {
			$p[] = 'workload below full size (orders ' . ( is_int( $orders ) ? $orders : 'missing' ) . ', older ' . ( is_int( $old ) ? $old : 'missing' ) . ')';
		}
		if ( array( 'square_credit_card', 'stripe', 'woocommerce_payments' ) !== $methods ) {
			$p[] = 'workload methods are not the 3 instant methods';
		}
		if ( ! is_int( $seed['drafts'] ?? null ) || $seed['drafts'] < 1 || ! is_int( $seed['missing_origin'] ?? null ) || $seed['missing_origin'] < 1 ) {
			$p[] = 'workload has no checkout drafts or no missing-origin orders';
		}
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			$v = array_values( array_filter( (array) ( $seed['storages'] ?? array() ), static fn( $e ) => blzmcp_card_receipt_match( $e, array( 'storage' => $s ) ) ) );
			$x = 1 === count( $v ) ? $v[0] : array();
			if ( 1 !== count( $v ) || ! blzmcp_card_receipt_match( $x['verify'] ?? null, array( 'ok' => true, 'authority' => $s ) ) || ! is_int( $orders ) || ! is_int( $old )
				|| ( $x['verify']['orders'] ?? null ) !== $orders + $old || ( $x['seeded_orders'] ?? null ) !== $orders || ( $x['seeded_old'] ?? null ) !== $old ) {
				$p[] = "workload not seeded and verified in the authoritative store ($s)";
			}
		}

		// Trials, decided on the raw rows.
		$trials = is_array( $r['trials'] ?? null ) ? array_values( $r['trials'] ) : null;
		$parts  = array();
		if ( null === $trials ) {
			$p[] = 'no raw trials';
			$trials = array();
		}
		foreach ( $trials as $t ) {
			if ( ! is_array( $t ) ) {
				$p[] = 'a trial row is not an object';
				continue;
			}
			if ( array_key_exists( 'case', $t ) || array_key_exists( 'counted_as_trial', $t ) ) {
				$p[] = 'a bound case is counted as a trial';
				continue;
			}
			if ( null !== ( $t['forced'] ?? null ) ) {
				$p[] = 'a forced-failure probe is counted as a trial';
				continue;
			}
			if ( ! in_array( $t['storage'] ?? null, BLZMCP_CARD_RECEIPT_STORAGES, true ) || ! in_array( $t['window'] ?? null, BLZMCP_CARD_RECEIPT_WINDOWS, true ) || ! in_array( $t['logger'] ?? null, BLZMCP_CARD_RECEIPT_LOGGERS, true ) ) {
				$p[] = 'a trial outside the 8 partitions';
				continue;
			}
			$parts[ $t['storage'] . '/' . $t['window'] . 'h/' . $t['logger'] ][] = $t;
		}
		$summary = array();
		foreach ( (array) ( $r['partitions'] ?? array() ) as $e ) {
			if ( is_array( $e ) ) {
				foreach ( array_keys( $e ) as $key ) {
					if ( 1 === preg_match( '/median|pooled|mean|p50/i', (string) $key ) ) {
						$p[] = 'a partition reports a median or pooled figure';
						break 2;
					}
				}
				$summary[ ( $e['storage'] ?? '?' ) . '/' . ( $e['window'] ?? '?' ) . 'h/' . ( $e['logger'] ?? '?' ) ][] = $e;
			}
		}
		$valid_n = 0;
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			foreach ( BLZMCP_CARD_RECEIPT_WINDOWS as $w ) {
				foreach ( BLZMCP_CARD_RECEIPT_LOGGERS as $l ) {
					$key = "$s/{$w}h/$l";
					$rows = $parts[ $key ] ?? array();
					if ( BLZMCP_CARD_RECEIPT_TRIALS !== count( $rows ) ) {
						$p[] = "partition $key has " . count( $rows ) . ' trials, not 5';
					}
					$nums = array_map( static fn( $t ) => $t['trial'] ?? null, $rows );
					$sorted = $nums;
					sort( $sorted );
					if ( array( 1, 2, 3, 4, 5 ) !== $sorted ) {
						$p[] = "partition $key trial numbers are not 1-5 once each (" . implode( ',', array_map( static fn( $n ) => is_scalar( $n ) ? (string) $n : '?', $nums ) ) . ')';
					}
					usort( $rows, static fn( $a, $b ) => (int) ( $a['trial'] ?? 0 ) <=> (int) ( $b['trial'] ?? 0 ) );
					$ms = array();
					foreach ( $rows as $t ) {
						$n       = is_scalar( $t['trial'] ?? null ) ? (string) $t['trial'] : '?';
						$readers = is_array( $t['validation']['readers'] ?? null ) ? $t['validation']['readers'] : array();
						$rk      = array_keys( $readers );
						sort( $rk, SORT_STRING );
						$fails = $t['validation']['failures'] ?? null;
						if ( true !== ( $t['ok'] ?? null ) || BLZMCP_CARD_RECEIPT_READERS !== $rk || in_array( false, array_map( static fn( $v ) => true === $v, $readers ), true ) || array() !== $fails ) {
							$first = is_array( $fails ) && isset( $fails[0] ) && is_string( $fails[0] ) ? ' (' . $fails[0] . ')' : '';
							$p[]   = "partition $key trial $n is not a valid trial$first";
						} else {
							++$valid_n;
						}
						$el   = $t['elapsed_ms'] ?? null;
						$ms[] = $el;
						if ( ! $num( $el ) || $el < 0 ) {
							$p[] = "partition $key trial $n has no elapsed_ms";
						} elseif ( $el >= BLZMCP_CARD_RECEIPT_BUDGET ) {
							$p[] = "partition $key trial $n took $el ms, not strictly below 1500 ms";
						}
						$plans = array_filter( (array) ( $t['slowest'] ?? array() ), static fn( $q ) => is_array( $q ) && is_array( $q['plan'] ?? null ) && array() !== $q['plan'] );
						$miss  = array();
						if ( ! is_int( $t['queries'] ?? null ) || $t['queries'] < 1 ) {
							$miss[] = 'query count';
						}
						if ( ! $num( $t['rows_examined'] ?? null ) || ! $num( $t['rows_selected'] ?? null ) ) {
							$miss[] = 'rows examined/selected';
						}
						if ( ! is_int( $t['meta_rows'] ?? null ) || $t['meta_rows'] < 1 ) {
							$miss[] = 'metadata rows';
						}
						if ( ! $num( $t['peak_memory_delta_bytes'] ?? null ) ) {
							$miss[] = 'peak memory';
						}
						if ( ! $num( $t['warm_ms'] ?? null ) ) {
							$miss[] = 'warm time';
						}
						if ( array() === $plans ) {
							$miss[] = 'query plan';
						}
						if ( array() !== $miss ) {
							$p[] = "partition $key trial $n is missing " . implode( ', ', $miss );
						}
					}
					$sum = $summary[ $key ] ?? array();
					if ( 1 !== count( $sum ) || ( $sum[0]['trials_ms'] ?? null ) !== $ms ) {
						$p[] = "partition summary for $key is missing or does not match its raw trials";
					}
				}
			}
		}
		if ( count( $summary ) !== 8 ) {
			$p[] = 'partition summaries are not exactly the 8 partitions';
		}

		// HTTP latency, reported apart from the trials.
		$http = (array) ( $r['http'] ?? array() );
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			foreach ( BLZMCP_CARD_RECEIPT_WINDOWS as $w ) {
				foreach ( array( 'cold', 'warm' ) as $kind ) {
					$hit = array_filter( $http, static fn( $h ) => blzmcp_card_receipt_match(
						$h,
						array(
							'storage'    => $s,
							'window'     => $w,
							'kind'       => $kind,
							'http_code'  => '200',
							'valid_body' => true,
						)
					) && blzmcp_card_receipt_num( $h['total_ms'] ?? null ) );
					if ( array() === $hit ) {
						$p[] = "no HTTP $kind figure for $s/{$w}h";
					}
				}
			}
		}

		// Combined workflow cost, per storage, never pooled into a budget: decided on the raw step
		// records (the plan's own first 3 steps, in plan order, each planned and successful), with
		// the storage's Analytics readiness established first.
		$period = BLZMCP_CARD_PERF_WORKFLOW_PERIOD;
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			$e = array_values( array_filter( (array) ( $r['workflow_cost'] ?? array() ), static fn( $x ) => blzmcp_card_receipt_match( $x, array( 'storage' => $s ) ) ) );
			$x = 1 === count( $e ) ? $e[0] : array();
			if ( 1 !== count( $e ) || true !== ( $x['ok'] ?? null ) || ! $num( $x['total_ms'] ?? null ) || false !== ( $x['pooled_budget'] ?? null ) ) {
				$p[] = "combined workflow cost missing or failed ($s)";
			}
			if ( 1 === count( $e ) ) {
				foreach ( blzmcp_card_perf_workflow_step_problems( $x['steps'] ?? null ) as $why ) {
					$p[] = "combined workflow cost ($s): $why";
				}
				if ( BLZMCP_CARD_PERF_WORKFLOW !== ( $x['workflow'] ?? null ) || BLZMCP_CARD_PERF_WORKFLOW_PERIOD !== ( $x['period'] ?? null ) || BLZMCP_CARD_PERF_WORKFLOW_PERIOD !== ( $x['plan_period'] ?? null ) ) {
					$p[] = "combined workflow cost ($s): not the weekly review's own $period plan";
				}
				if ( true !== ( $x['gap_cache_cleared'] ?? null ) ) {
					$p[] = "combined workflow cost ($s): the plan's Analytics probe did not run fresh (gap cache not cleared)";
				}
				$sum = 0.0;
				foreach ( is_array( $x['steps'] ?? null ) ? $x['steps'] : array() as $st ) {
					$sum += is_array( $st ) && $num( $st['elapsed_ms'] ?? null ) ? (float) $st['elapsed_ms'] : 0.0;
				}
				if ( $num( $x['total_ms'] ?? null ) && abs( (float) $x['total_ms'] - $sum ) > 0.5 ) {
					$p[] = "combined workflow cost ($s): total_ms {$x['total_ms']} is not the sum of its steps ($sum)";
				}
			}
			$a = array_values( array_filter( (array) ( $r['analytics_ready'] ?? array() ), static fn( $x ) => blzmcp_card_receipt_match( $x, array( 'storage' => $s ) ) ) );
			if ( 1 !== count( $a ) ) {
				$p[] = "analytics readiness missing or not one record ($s)";
				continue;
			}
			foreach ( blzmcp_card_perf_analytics_problems( $a[0], $period ) as $why ) {
				$p[] = "analytics readiness ($s): $why";
			}
		}

		// Bound cases: their own assertions, never trials.
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			foreach ( BLZMCP_CARD_RECEIPT_BOUNDS as $b ) {
				$e = array_values( array_filter( (array) ( $r['bound_cases'] ?? array() ), static fn( $x ) => blzmcp_card_receipt_match(
						$x,
						array(
							'storage' => $s,
							'case'    => $b[0],
							'k'       => $b[1],
						)
					) ) );
				if ( 1 !== count( $e ) || true !== ( $e[0]['ok'] ?? null ) || array() !== ( $e[0]['failures'] ?? null ) || false !== ( $e[0]['counted_as_trial'] ?? null ) ) {
					$p[] = "bound case {$b[0]}/{$b[1]} ($s) missing or failed";
				}
			}
		}

		// Forced reader failures: reported failed and detected.
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			foreach ( BLZMCP_CARD_RECEIPT_LOGGERS as $l ) {
				foreach ( BLZMCP_CARD_RECEIPT_FORCES as $f ) {
					$e = array_values( array_filter( (array) ( $r['forced_failures'] ?? array() ), static fn( $x ) => blzmcp_card_receipt_match(
							$x,
							array(
								'storage' => $s,
								'logger'  => $l,
								'force'   => $f,
							)
						) ) );
					if ( 1 !== count( $e ) || false !== ( $e[0]['ok'] ?? null ) || true !== ( $e[0]['detected'] ?? null ) ) {
						$p[] = "forced $f-reader failure ($s, $l logger) not shown failed and detected";
					}
				}
			}
		}

		// Cleanup records and the storage restore.
		$cleanup = (array) ( $r['cleanup'] ?? array() );
		$left    = array( 'tagged_remaining', 'analytics_rows_remaining', 'analytics_customers_remaining', 'import_jobs_remaining', 'failed_imports_remaining' );
		$good    = static fn( $c ) => is_array( $c ) && true === ( $c['passed'] ?? null ) && true === ( $c['ok'] ?? null ) && true === ( $c['settings_restored'] ?? null )
			&& ( 'orders' !== ( $c['mode'] ?? null ) || array() === array_filter( $left, static fn( $k ) => 0 !== ( $c[ $k ] ?? null ) ) )
			&& ( ! array_key_exists( 'expected_removed', $c ) || ( $c['orders_removed'] ?? null ) === $c['expected_removed'] );
		foreach ( BLZMCP_CARD_RECEIPT_STORAGES as $s ) {
			foreach ( BLZMCP_CARD_RECEIPT_PHASES as $ph ) {
				$e = array_filter( $cleanup, static fn( $c ) => blzmcp_card_receipt_match(
					$c,
					array(
						'storage' => $s,
						'phase'   => $ph,
					)
				) );
				$removing_ok = ! in_array( $ph, BLZMCP_CARD_RECEIPT_REMOVING, true ) || array() === array_filter( $e, static fn( $c ) => ! is_int( $c['expected_removed'] ?? null ) || $c['expected_removed'] < 1 );
				if ( array() === $e || count( array_filter( $e, $good ) ) !== count( $e ) || ! $removing_ok ) {
					$p[] = "cleanup $ph ($s) missing or unclean";
				}
			}
			// The cleanup after the trials removed the Analytics rows the readiness import wrote.
			$ready    = array_values( array_filter( (array) ( $r['analytics_ready'] ?? array() ), static fn( $x ) => blzmcp_card_receipt_match( $x, array( 'storage' => $s ) ) ) );
			$imported = 1 === count( $ready ) ? ( $ready[0]['imported'] ?? null ) : null;
			$after    = array_filter(
				$cleanup,
				static fn( $c ) => blzmcp_card_receipt_match(
					$c,
					array(
						'storage' => $s,
						'phase'   => 'after',
					)
				)
			);
			$shown    = array_filter( $after, static fn( $c ) => is_int( $imported ) && $imported > 0 && is_int( $c['analytics_stats_removed'] ?? null ) && $c['analytics_stats_removed'] >= $imported && true === ( $c['analytics_cache_cleared'] ?? null ) );
			if ( array() === $after || count( $shown ) !== count( $after ) ) {
				$p[] = "cleanup after ($s) does not show the imported Analytics rows removed";
			}
		}
		$final = array_filter( $cleanup, static fn( $c ) => is_array( $c ) && 'final' === ( $c['phase'] ?? null ) );
		if ( array() === $final || count( array_filter( $final, $good ) ) !== count( $final ) ) {
			$p[] = 'final cleanup missing or unclean';
		}
		if ( count( array_filter( $cleanup, $good ) ) !== count( $cleanup ) ) {
			$p[] = 'a cleanup record is not clean';
		}
		$rs = is_array( $r['storage_restored'] ?? null ) ? $r['storage_restored'] : array();
		if ( true !== ( $rs['ok'] ?? null ) || ! in_array( $rs['storage'] ?? null, BLZMCP_CARD_RECEIPT_STORAGES, true ) || ! in_array( $rs['sync'] ?? null, array( 'yes', 'no' ), true ) ) {
			$p[] = 'original order storage not restored';
		}

		return array_values( array_unique( $p ) );
	}

	/**
	 * CLI entry point.
	 *
	 * @param string[] $argv Arguments.
	 * @return int Exit code.
	 */
	function blzmcp_card_receipt_main( array $argv ): int {
		$file = null;
		$bind = array();
		foreach ( array_slice( $argv, 1 ) as $a ) {
			if ( 1 === preg_match( '/^--(candidate-sha256|source-sha|attempt)=(.*)$/s', (string) $a, $m ) ) {
				$bind[ array( 'candidate-sha256' => 'candidate', 'source-sha' => 'source', 'attempt' => 'attempt' )[ $m[1] ] ] = $m[2];
			} elseif ( null === $file ) {
				$file = (string) $a;
			}
		}
		if ( null === $file || 3 !== count( array_filter( $bind, static fn( $v ) => '' !== $v ) ) ) {
			echo "CARD PERF INVALID: usage: card-testing-perf-validate.php <receipt.json> --candidate-sha256=<hex> --source-sha=<sha> --attempt=<id>\n";
			return 2;
		}
		$raw = is_file( $file ) && is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( false === $raw ) {
			echo 'CARD PERF INVALID: receipt ' . basename( $file ) . " is missing or unreadable\n";
			return 2;
		}
		$obj = json_decode( $raw );
		$r   = json_decode( $raw, true );
		if ( ! is_object( $obj ) || ! is_array( $r ) ) {
			echo 'CARD PERF INVALID: receipt ' . basename( $file ) . " is not a JSON object (truncated or not JSON)\n";
			return 2;
		}
		$problems = blzmcp_card_receipt_problems( $r, $bind );
		if ( array() === $problems ) {
			echo 'CARD PERF VALID (' . ( count( BLZMCP_CARD_RECEIPT_STORAGES ) * count( BLZMCP_CARD_RECEIPT_WINDOWS ) * count( BLZMCP_CARD_RECEIPT_LOGGERS ) * BLZMCP_CARD_RECEIPT_TRIALS ) . ' trials, ' . ( count( BLZMCP_CARD_RECEIPT_STORAGES ) * count( BLZMCP_CARD_RECEIPT_WINDOWS ) * count( BLZMCP_CARD_RECEIPT_LOGGERS ) ) . " partitions)\n";
			return 0;
		}
		$shown = array_slice( $problems, 0, 25 );
		echo 'CARD PERF INVALID: ' . implode( '; ', $shown ) . ( count( $problems ) > 25 ? '; and ' . ( count( $problems ) - 25 ) . ' more' : '' ) . "\n";
		return 1;
	}
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( (string) $argv[0] ) === realpath( __FILE__ ) ) {
	exit( blzmcp_card_receipt_main( $argv ) );
}
