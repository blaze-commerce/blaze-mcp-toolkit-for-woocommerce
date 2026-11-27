<?php
/**
 * A deterministic, full-size card-testing performance receipt (schema blzmcp-card-performance/1)
 * that card-testing-perf-validate.php accepts, for self-tests (test-card-testing-perf-validate.php,
 * test-record.sh). Host-side CLI, plain PHP: no WordPress, and no ABSPATH guard because it is never
 * eval-file'd inside WordPress. Synthetic values only; it measures nothing.
 *
 *   php tests/matrix/card-testing-perf-sample.php <candidate_sha256> <source_sha> <attempt>
 *
 * Prints the receipt as one JSON document on stdout: all 8 partitions (hpos and legacy, 48 and 168
 * hours, file and DB logger) with 5 valid trials each, well below 1500 ms, every trial metric, the
 * partitions summary, HTTP cold and warm per storage and window, the Analytics readiness and the
 * workflow cost per storage (the plan's own 3 steps in plan order, each planned and successful),
 * the 4 bound cases per storage, the forced failures, every cleanup phase (with its Analytics
 * leftovers) and the storage restore.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions

if ( ! function_exists( 'blzmcp_card_perf_sample_receipt' ) ) {
	/**
	 * The sample receipt.
	 *
	 * @param string $cand Candidate sha256.
	 * @param string $src  Source commit SHA.
	 * @param string $att  Attempt ID.
	 * @return array<string,mixed>
	 */
	function blzmcp_card_perf_sample_receipt( string $cand, string $src, string $att ): array {
		$readers = array(
			'response' => true,
			'workload' => true,
			'orders'   => true,
			'methods'  => true,
			'signals'  => true,
			'logs'     => true,
		);
		$r       = array(
			'schema'           => 'blzmcp-card-performance/1',
			'measured_at_utc'  => '2026-11-28T01:00:00Z',
			'candidate_sha256' => $cand,
			'source_sha'       => $src,
			'attempt'          => $att,
			'smoke'            => false,
			'smoke_reasons'    => array(),
			'budget_ms'        => 1500,
			'rule'             => '5of5<1500',
			'environment'      => array(
				'host'             => array(
					'name' => 'sample-host',
					'os'   => 'Linux 6.8 x86_64',
					'cpus' => 8,
				),
				'container_limits' => array(
					'ncpu'            => 8,
					'mem_total_bytes' => 16000000000,
					'server_version'  => '27.0',
				),
				'versions'         => array(
					array(
						'php'   => '8.3.14',
						'wp'    => '7.1.5',
						'wc'    => '11.3.1',
						'db'    => '11.4.4-MariaDB',
						'cache' => 'none',
					),
				),
			),
			'seed'             => array(
				'orders'         => 50000,
				'old_orders'     => 20000,
				'horizon_days'   => 28,
				'methods'        => array( 'stripe', 'woocommerce_payments', 'square_credit_card' ),
				'drafts'         => 3500,
				'missing_origin' => 2800,
				'storages'       => array(),
			),
			'trials'           => array(),
			'partitions'       => array(),
			'http'             => array(),
			'workflow_period'  => 'last_7_days',
			'analytics_ready'  => array(),
			'workflow_cost'    => array(),
			'bound_cases'      => array(),
			'forced_failures'  => array(),
			'cleanup'          => array(),
			'storage_restored' => array(
				'storage' => 'legacy',
				'sync'    => 'no',
				'ok'      => true,
			),
			'all_pass'         => true,
		);
		foreach ( array( 'legacy', 'hpos' ) as $si => $s ) {
			$r['seed']['storages'][] = array(
				'storage'       => $s,
				'verify'        => array(
					'ok'        => true,
					'authority' => $s,
					'orders'    => 70000,
				),
				'seeded_orders' => 50000,
				'seeded_old'    => 20000,
			);
			foreach ( array( 48, 168 ) as $wi => $w ) {
				foreach ( array( 'file', 'db' ) as $li => $l ) {
					$ms = array();
					for ( $t = 1; $t <= 5; $t++ ) {
						$el            = 600 + 100 * $si + 150 * $wi + 50 * $li + $t;
						$ms[]          = $el;
						$r['trials'][] = array(
							'storage'                 => $s,
							'window'                  => $w,
							'logger'                  => $l,
							'trial'                   => $t,
							'ok'                      => true,
							'forced'                  => null,
							'detected'                => null,
							'validation'              => array(
								'readers'  => $readers,
								'failures' => array(),
							),
							'elapsed_ms'              => $el,
							'warm_ms'                 => 2.1,
							'queries'                 => 95,
							'rows_examined'           => 140000,
							'rows_selected'           => 70000,
							'meta_rows'               => 64000,
							'peak_memory_delta_bytes' => 12000000,
							'slowest'                 => array(
								array(
									'ms'   => 300,
									'sql'  => 'SELECT ?',
									'plan' => array(
										array(
											'table' => 'o',
											'type'  => 'range',
											'key'   => 'PRIMARY',
											'rows'  => 70000,
											'extra' => null,
										),
									),
								),
							),
						);
					}
					$r['partitions'][] = array(
						'storage'   => $s,
						'window'    => $w,
						'logger'    => $l,
						'trials_ms' => $ms,
						'max_ms'    => max( $ms ),
						'rule'      => '5of5<1500',
						'pass'      => true,
					);
					if ( 48 === $w ) {
						foreach ( array( 'orders', 'logs' ) as $f ) {
							$r['forced_failures'][] = array(
								'storage'  => $s,
								'logger'   => $l,
								'force'    => $f,
								'ok'       => false,
								'detected' => true,
							);
						}
					}
				}
				foreach ( array( 'cold', 'warm' ) as $k ) {
					$r['http'][] = array(
						'storage'    => $s,
						'window'     => $w,
						'kind'       => $k,
						'method'     => 'GET',
						'http_code'  => '200',
						'valid_body' => true,
						'total_ms'   => 'cold' === $k ? 1100 : 60,
					);
				}
			}
			$r['analytics_ready'][] = array(
				'storage'                 => $s,
				'period'                  => 'last_7_days',
				'compare'                 => true,
				'reason'                  => null,
				'cache_cleared'           => true,
				'eligible'                => 34720,
				'imported'                => 34720,
				'stats_rows'              => 34720,
				'missing'                 => 0,
				'failed_imports'          => 0,
				'pending_import_jobs'     => 0,
				'counted_statuses'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
				'date_basis'              => 'date_paid',
				'windows'                 => array( '2026-11-22..2026-11-28', '2026-11-15..2026-11-21' ),
				'import_seconds_excluded' => 180,
				'ok'                      => true,
			);
			$steps = array();
			foreach (
				array(
					array( 'blaze-mcp-toolkit/payment-health', array( 'since_hours' => 168 ), 700 ),
					array( 'blaze-mcp-toolkit/card-testing', array( 'since_hours' => 168 ), 1000 ),
					array(
						'wc-analytics/totals',
						array(
							'subject' => 'orders',
							'period'  => 'last_7_days',
							'compare' => true,
						),
						400.5,
					),
				) as $i => $step
			) {
				$steps[] = array(
					'position'       => $i,
					'ability'        => $step[0],
					'planned'        => true,
					'plan_arguments' => $step[1],
					'arguments'      => $step[1],
					'available'      => true,
					'elapsed_ms'     => $step[2],
					'queries'        => 40,
					'success'        => true,
					'error_code'     => null,
				);
			}
			$r['workflow_cost'][] = array(
				'storage'           => $s,
				'workflow'          => 'blaze-mcp-toolkit/weekly-store-review',
				'period'            => 'last_7_days',
				'plan_period'       => 'last_7_days',
				'plan_ok'           => true,
				'plan_ms'           => 160.2,
				'plan_steps_total'  => 10,
				'plan_abilities'    => array( 'blaze-mcp-toolkit/payment-health', 'blaze-mcp-toolkit/card-testing', 'wc-analytics/totals', 'blaze-mcp-toolkit/store-profile', 'wc-analytics/totals', 'wc-analytics/totals' ),
				'gap_cache_cleared' => true,
				'steps'             => $steps,
				'total_ms'          => 2100.5,
				'failures'          => array(),
				'ok'                => true,
				'pooled_budget'     => false,
			);
			foreach ( array( array( 'methods', 1000 ), array( 'methods', 1001 ), array( 'networks', 5000 ), array( 'networks', 5001 ) ) as $b ) {
				$r['bound_cases'][] = array(
					'storage'          => $s,
					'case'             => $b[0],
					'k'                => $b[1],
					'ok'               => true,
					'failures'         => array(),
					'counted_as_trial' => false,
					'elapsed_ms'       => 2000,
				);
			}
			foreach ( array( 'before', 'diag-file', 'diag-db', 'after', 'bound-methods', 'bound-networks' ) as $ph ) {
				$diag = 0 === strpos( $ph, 'diag' );
				$c    = array(
					'phase'             => $ph,
					'storage'           => $s,
					'mode'              => $diag ? 'diagnostics' : 'orders',
					'orders_removed'    => $diag ? null : 0,
					'tagged_remaining'  => 0,
					'settings_restored' => true,
				) + blzmcp_card_perf_sample_analytics_cleanup( $diag, 'after' === $ph ) + array(
					'ok'     => true,
					'passed' => true,
				);
				$removed = array(
					'after'          => 70000,
					'bound-methods'  => 1001,
					'bound-networks' => 5001,
				);
				if ( isset( $removed[ $ph ] ) ) {
					$c['expected_removed'] = $removed[ $ph ];
					$c['orders_removed']   = $removed[ $ph ];
				}
				$r['cleanup'][] = $c;
			}
		}
		$r['cleanup'][] = array(
			'phase'             => 'final',
			'storage'           => 'legacy',
			'mode'              => 'orders',
			'orders_removed'    => 0,
			'tagged_remaining'  => 0,
			'settings_restored' => true,
		) + blzmcp_card_perf_sample_analytics_cleanup( false, false ) + array(
			'ok'     => true,
			'passed' => true,
		);
		return $r;
	}

	/**
	 * The Analytics part of one sample cleanup record (seed-payment-volume.php's CLEANUP fields): null
	 * for a diagnostics cleanup; for an orders cleanup nothing left, and after the trials the 34,720
	 * imported Analytics rows and the 50 fixture customers removed.
	 *
	 * @param bool $diag  Diagnostics cleanup.
	 * @param bool $after The cleanup after the trials.
	 * @return array<string,mixed>
	 */
	function blzmcp_card_perf_sample_analytics_cleanup( bool $diag, bool $after ): array {
		$keys = array( 'analytics_stats_removed', 'analytics_lookup_rows_removed', 'analytics_customers_removed', 'import_jobs_removed', 'failed_imports_cleared', 'analytics_cache_cleared', 'analytics_rows_remaining', 'analytics_customers_remaining', 'import_jobs_remaining', 'failed_imports_remaining' );
		if ( $diag ) {
			return array_fill_keys( $keys, null );
		}
		return array_combine( $keys, array( $after ? 34720 : 0, 0, $after ? 50 : 0, 0, 0, true, 0, 0, 0, 0 ) );
	}
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( (string) $argv[0] ) === realpath( __FILE__ ) ) {
	if ( 4 !== count( $argv ) ) {
		fwrite( STDERR, "usage: card-testing-perf-sample.php <candidate_sha256> <source_sha> <attempt>\n" );
		exit( 2 );
	}
	echo json_encode( blzmcp_card_perf_sample_receipt( (string) $argv[1], (string) $argv[2], (string) $argv[3] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
