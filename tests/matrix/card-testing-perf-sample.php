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
 * partitions summary, HTTP cold and warm per storage and window, the workflow cost per storage, the
 * 4 bound cases per storage, the forced failures, every cleanup phase and the storage restore.
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
			$r['workflow_cost'][] = array(
				'storage'       => $s,
				'ok'            => true,
				'total_ms'      => 2100.5,
				'pooled_budget' => false,
				'steps'         => array(
					array(
						'ability'    => 'blaze-mcp-toolkit/payment-health',
						'elapsed_ms' => 700,
						'ok'         => true,
					),
					array(
						'ability'    => 'blaze-mcp-toolkit/card-testing',
						'elapsed_ms' => 1000,
						'ok'         => true,
					),
					array(
						'ability'    => 'wc-analytics/totals',
						'elapsed_ms' => 400.5,
						'ok'         => true,
					),
				),
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
				$c = array(
					'phase'             => $ph,
					'storage'           => $s,
					'mode'              => 0 === strpos( $ph, 'diag' ) ? 'diagnostics' : 'orders',
					'orders_removed'    => 0 === strpos( $ph, 'diag' ) ? null : 0,
					'tagged_remaining'  => 0,
					'settings_restored' => true,
					'ok'                => true,
					'passed'            => true,
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
			'ok'                => true,
			'passed'            => true,
		);
		return $r;
	}
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( (string) $argv[0] ) === realpath( __FILE__ ) ) {
	if ( 4 !== count( $argv ) ) {
		fwrite( STDERR, "usage: card-testing-perf-sample.php <candidate_sha256> <source_sha> <attempt>\n" );
		exit( 2 );
	}
	echo json_encode( blzmcp_card_perf_sample_receipt( (string) $argv[1], (string) $argv[2], (string) $argv[3] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
