<?php
/**
 * Pure card-testing rules: baselines, bursts, corroborators, verdicts and plain-word findings.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Contract C5 as plain functions over PaymentOrders::read_card_testing() output, safe method facts
 * and the log coverage summary, with the captured now, window and store timezone injected. Never
 * calls SQL, the clock, HTTP or the filesystem. Translation goes through __()/_n() with literal
 * strings and numbers through number_format_i18n() when WordPress has loaded it.
 *
 * Thresholds are screening heuristics, not calibrated probabilities. No merchant setting or
 * public filter changes them. Every share decision is an exact integer comparison; rounding is
 * for presentation only.
 *
 * Intervals are start-inclusive and end-exclusive UTC instants. Buckets are anchored to now:
 * bucket i covers [now - (i + 1) * 900, now - i * 900). Hour k (k = 0, 1, ...) covers buckets
 * k..k+3, that is [now - k * 900 - 3600, now - k * 900), while the whole hour lies in the window.
 * The baseline [history start, window start) is exactly 672 - since_hours whole hours, so it has
 * no partial trailing hour to omit.
 */
final class CardTestingRules {

	/** Rule version (C1); a change of any constant or rule below bumps it. */
	public const RULE_VERSION = 1;

	/** C5 named constants. */
	public const HISTORY_DAYS                    = 28;
	public const BUCKET_SECONDS                  = 900;
	public const BURST_SECONDS                   = 3600;
	public const MIN_BASELINE_ATTEMPTS           = 100;
	public const MIN_BASELINE_SPAN_DAYS          = 7;
	public const MIN_BURST_FAILED                = 20;
	public const BASELINE_MULTIPLIER             = 5;
	public const BASELINE_P95_MULTIPLIER         = 2;
	public const ABSOLUTE_BURST_FAILED           = 100;
	public const MIN_POSSIBLE_FAILED_SHARE       = 0.50;
	public const MIN_FAILED_SHARE                = 0.80;
	public const MIN_IP_COMPLETENESS             = 0.80;
	public const MIN_NETWORK_ORDERS              = 20;
	public const TOP_NETWORKS                    = 3;
	public const MIN_TOP_NETWORK_SHARE           = 0.80;
	public const MIN_ATTR_UNKNOWN_SHARE          = 0.90;
	public const MIN_ATTR_BASELINE_PRESENT_SHARE = 0.50;
	public const MIN_ATTR_SHIFT                  = 0.30;
	public const RECENT_SECONDS                  = 900;
	public const MIN_RECENT_FAILED               = 5;

	/** C4/C8 output bounds (the ability applies the display caps). */
	public const MAX_EXAMPLES           = 5;
	public const MAX_FINDINGS           = 10;
	public const MAX_EPISODES           = 3;
	public const MAX_OUTPUT_ASSESSMENTS = 20;

	/** Verdicts, strongest first (rollup precedence). */
	public const VERDICTS = array( 'likely_attack', 'possible_attack', 'unknown', 'no_sign' );

	/** Activity values. */
	public const ACTIVITIES = array( 'recent', 'earlier_in_window', 'not_established', 'unknown' );

	/**
	 * Reason codes these rules emit (Check.reason_code and limitation codes), beyond the reader's.
	 * The catalogue is tests/fixtures/card-testing-contracts.json.
	 */
	public const REASON_CODES = array(
		'reader_mismatch',
		'empty_store',
		'no_applicable_scope',
		'method_manual',
		'method_test_mode',
		'method_kind_unknown',
		'test_mode_unknown',
		'baseline_incomplete',
		'baseline_insufficient_volume',
		'baseline_insufficient_span',
		'attribution_value_unsupported',
		'attribution_baseline_insufficient',
		'no_failed_orders',
		'logs_unavailable',
		'share_unknown',
		'origin_unverified',
	);

	/** Finding codes. */
	public const FINDING_CODES = array(
		'likely_card_testing',
		'possible_card_testing',
		'failed_order_volume_context',
		'unassessed_method_failed_orders',
	);

	/** Limitation-only codes (reason codes are also used as limitation codes). */
	public const LIMITATION_CODES = array(
		'order_state_basis',
		'unsaved_and_retried_orders',
		'cause_unverified',
		'recorded_network_limits',
		'missing_origin_orders',
		'other_origin_orders',
		'unassigned_method_orders',
		'low_history',
		'order_signal_versions',
	);

	/** Reader reason codes the rules translate (PaymentOrders and CardTestingOrders). */
	public const READER_CODES = array(
		'orders_unavailable',
		'unsupported_data_store',
		'tables_missing',
		'read_failed',
		'operational_data_missing',
		'row_cap',
		'order_scan_budget',
	);

	/** Method ID grammar (PaymentRules::safe_method_id output). */
	private const FAMILY = '/^[a-z0-9][a-z0-9_\-]{0,39}$/';

	/**
	 * Evaluate one call. See FIX-LOG-core.md "Interface" for the exact shapes.
	 *
	 * @param array<string,mixed>                $orders      PaymentOrders::read_card_testing() result.
	 * @param array<string,array<string,mixed>>  $methods     Safe method facts by ID: kind, test_mode, enabled, registered, family.
	 * @param array<string,mixed>                $logs        PaymentErrors::logs_for_families() result, or array() when not read.
	 * @param int                                $now         Captured UTC now.
	 * @param int                                $since_hours Current window, 1-168.
	 * @param \DateTimeZone                      $tz          Store timezone.
	 * @return array<string,mixed>
	 */
	public static function evaluate( array $orders, array $methods, array $logs, int $now, int $since_hours, \DateTimeZone $tz ): array {
		$since = max( 1, min( 168, $since_hours ) );
		$ctx   = array(
			'now'     => $now,
			'since'   => $since,
			'h_start' => $now - self::HISTORY_DAYS * 86400,
			'w_start' => $now - $since * 3600,
			'b_hours' => self::HISTORY_DAYS * 24 - $since,
			'tz'      => $tz,
			'caps'    => array(
				'network_ids' => true === ( $orders['caps']['network_ids'] ?? false ),
			),
		);
		$frame = array(
			'window'   => array(
				'start' => PaymentRules::iso( $ctx['w_start'], $tz ),
				'end'   => PaymentRules::iso( $now, $tz ),
			),
			'baseline' => array(
				'start' => PaymentRules::iso( $ctx['h_start'], $tz ),
				'end'   => PaymentRules::iso( $ctx['w_start'], $tz ),
			),
		);

		$ok = true === ( $orders['ok'] ?? false ) && is_array( $orders['totals'] ?? null );
		if ( $ok && ( ( $orders['now'] ?? null ) !== $now || ( $orders['since_hours'] ?? null ) !== $since ) ) {
			return self::unavailable( $frame, 'reader_mismatch', $orders );
		}
		if ( ! $ok ) {
			$reason = is_string( $orders['reason'] ?? null ) && in_array( $orders['reason'], self::READER_CODES, true ) ? $orders['reason'] : 'orders_unavailable';
			return self::unavailable( $frame, $reason, $orders );
		}

		$signals = array(
			'ip'          => self::signal( $orders['signals']['ip'] ?? null ),
			'attribution' => self::signal( $orders['signals']['attribution'] ?? null ),
		);
		$scan    = true === ( $orders['scan_complete'] ?? false );
		$stop    = is_string( $orders['reason'] ?? null ) && in_array( $orders['reason'], array( 'row_cap', 'order_scan_budget' ), true ) ? $orders['reason'] : null;
		$scopes  = array();
		foreach ( (array) ( $orders['scopes'] ?? array() ) as $raw ) {
			if ( is_array( $raw ) ) {
				$scopes[] = self::norm_scope( $raw, $ctx, $scan, $stop );
			}
		}
		$invalid_only = array();
		foreach ( (array) ( $orders['invalid_only'] ?? array() ) as $raw ) {
			if ( is_array( $raw ) && in_array( $raw['origin_scope'] ?? null, array( 'verified_checkout', 'missing_origin' ), true ) ) {
				$invalid_only[] = array(
					'method_id'    => self::id( $raw['method_id'] ?? '' ),
					'origin_scope' => $raw['origin_scope'],
					'count'        => self::int( $raw['count'] ?? 0 ),
				);
			}
		}

		$ids = array();
		foreach ( (array) ( $orders['method_ids'] ?? array() ) as $id ) {
			$id = self::id( $id );
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
		}
		foreach ( array_merge( $scopes, $invalid_only ) as $s ) {
			$ids[ $s['method_id'] ] = true;
		}
		$ids = array_map( 'strval', array_keys( $ids ) );
		sort( $ids, SORT_STRING );
		$truncated = true === ( $orders['methods_truncated'] ?? false );
		$overflow  = true === ( $orders['invalid_overflow'] ?? false );
		if ( count( $ids ) > CardTestingOrders::MAX_METHODS ) {
			// The reader never exceeds its one shared identity budget; a result that does is cut to
			// the budget and treated as truncated, so method facts (and every cache dependency built
			// from them) stay within MAX_METHODS and no exact distinct total is published.
			$ids          = array_slice( $ids, 0, CardTestingOrders::MAX_METHODS );
			$keep         = array_flip( $ids );
			$scopes       = array_values( array_filter( $scopes, static fn( array $s ): bool => isset( $keep[ $s['method_id'] ] ) ) );
			$invalid_only = array_values( array_filter( $invalid_only, static fn( array $s ): bool => isset( $keep[ $s['method_id'] ] ) ) );
			$truncated    = true;
		}
		$facts = array();
		foreach ( $ids as $id ) {
			$facts[ $id ] = self::facts( $id, $methods[ $id ] ?? null );
		}
		$blocks = self::log_blocks( $logs );

		$evals = array();
		foreach ( $scopes as $s ) {
			$evals[] = self::assess( $s, $facts[ $s['method_id'] ], $signals, $ctx, $blocks, false );
		}
		foreach ( array( 'verified_checkout', 'missing_origin' ) as $origin ) {
			$members = array();
			foreach ( $scopes as $s ) {
				$f = $facts[ $s['method_id'] ];
				if ( $origin === $s['origin_scope'] && 'instant' === $f['kind'] && true !== $f['test_mode'] ) {
					$members[] = $s;
				}
			}
			$gap = $truncated ? 'method_cap' : ( $overflow ? 'invalid_timestamps' : null );
			foreach ( $invalid_only as $inv ) {
				$f = $facts[ $inv['method_id'] ];
				if ( null === $gap && $origin === $inv['origin_scope'] && 'manual' !== $f['kind'] && true !== $f['test_mode'] ) {
					$gap = 'invalid_timestamps';
				}
			}
			if ( array() === $members && null === $gap ) {
				continue;
			}
			if ( array() === $members && 'missing_origin' === $origin ) {
				continue;
			}
			$agg = self::merge( $members, $origin, $ctx, $gap );
			$fam = array();
			foreach ( $members as $m ) {
				if ( null !== $facts[ $m['method_id'] ]['family'] ) {
					$fam[ $facts[ $m['method_id'] ]['family'] ] = true;
				}
			}
			$evals[] = self::assess( $agg, array( 'families' => array_keys( $fam ) ), $signals, $ctx, $blocks, true );
		}
		usort( $evals, array( self::class, 'eval_order' ) );

		// Rollup (C5): likely, possible, unknown, no_sign; not-applicable scopes cast no vote.
		$votes = array();
		foreach ( $evals as $e ) {
			if ( $e['applicable'] ) {
				$votes[] = $e['verdict'];
			}
		}
		$verdict = 'unknown';
		foreach ( self::VERDICTS as $v ) {
			if ( in_array( $v, $votes, true ) ) {
				$verdict = $v;
				break;
			}
		}
		$global_reason = null;
		$totals_sum    = 0;
		foreach ( CardTestingOrders::CLASSES as $c ) {
			$totals_sum += self::int( $orders['totals'][ $c ] ?? 0 );
		}
		if ( array() === $votes ) {
			$any_history   = array() !== $scopes || array() !== $invalid_only || $totals_sum > 0 || self::int( $orders['rows_read'] ?? 0 ) > 0;
			$global_reason = $any_history ? 'no_applicable_scope' : 'empty_store';
		}

		$positive = array_values( array_filter( $evals, static fn( array $e ): bool => $e['applicable'] && in_array( $e['verdict'], array( 'likely_attack', 'possible_attack' ), true ) ) );
		$activity = 'not_established';
		if ( array() !== $positive ) {
			$acts     = array_map( static fn( array $e ): string => $e['activity'], $positive );
			$activity = in_array( 'recent', $acts, true ) ? 'recent' : ( in_array( 'unknown', $acts, true ) ? 'unknown' : 'earlier_in_window' );
		}

		$coverage = 'complete';
		if ( 'complete' !== ( $orders['coverage'] ?? null ) || $truncated || $overflow ) {
			$coverage = 'partial';
		}
		// A log gap lowers coverage only through the scopes it affects (assess() marks them partial).
		foreach ( $evals as $e ) {
			if ( $e['applicable'] && 'complete' !== $e['coverage'] ) {
				$coverage = 'partial';
			}
		}

		$assessments = array_map( array( self::class, 'public_assessment' ), $evals );
		$findings    = self::findings( $evals, $ctx );
		$examples    = self::example_candidates( $positive, $scopes );

		$result = array(
			'rule_version'       => self::RULE_VERSION,
			'verdict'            => $verdict,
			'activity'           => $activity,
			'coverage'           => $coverage,
			'summary'            => '',
			'window'             => $frame['window'],
			'baseline'           => $frame['baseline'],
			'totals'             => self::totals( $orders, $facts, $scan, $stop, $truncated ),
			'methods'            => array_values( array_map( array( self::class, 'public_facts' ), $facts ) ),
			// C8: no exact distinct total once any identity was omitted (shared method budget).
			'methods_total'      => $truncated || $overflow ? null : count( $facts ),
			'methods_truncated'  => $truncated,
			'assessments'        => $assessments,
			// C8: an exact total of distinct scopes only when no identity map was capped.
			'assessments_total'  => $truncated || $overflow ? null : count( $assessments ),
			'findings'           => $findings,
			'findings_total'     => count( $findings ),
			'limitations'        => self::limitations( $orders, $evals, $facts, $signals, $blocks, $global_reason, $stop, $truncated, $overflow ),
			'example_candidates' => $examples['candidates'],
			'examples_truncated' => $examples['truncated'],
		);
		$result['summary'] = self::summary( $verdict, $activity, $evals, $global_reason, $ctx );
		return $result;
	}

	// ---------------------------------------------------------------- inputs.

	/**
	 * Non-negative integer, else 0.
	 *
	 * @param mixed $v Value.
	 */
	private static function int( $v ): int {
		return is_int( $v ) && $v > 0 ? $v : 0;
	}

	/**
	 * Safe method ID (re-validated: reader output is never trusted as already safe).
	 *
	 * @param mixed $v Value.
	 */
	private static function id( $v ): string {
		return is_string( $v ) || is_int( $v ) ? PaymentRules::safe_method_id( (string) $v ) : '';
	}

	/**
	 * Normalised signal entry.
	 *
	 * @param mixed $raw Raw.
	 * @return array{supported:bool,reason:?string}
	 */
	private static function signal( $raw ): array {
		$supported = is_array( $raw ) && true === ( $raw['supported'] ?? null );
		$reason    = is_array( $raw ) && is_string( $raw['reason'] ?? null ) && in_array( $raw['reason'], CardTestingOrders::REASON_CODES, true ) ? $raw['reason'] : 'unsupported_source_version';
		return array(
			'supported' => $supported,
			'reason'    => $supported ? null : $reason,
		);
	}

	/**
	 * Safe method facts for one ID: only booleans, enums and a validated family token survive.
	 *
	 * @param string $id  Safe method ID.
	 * @param mixed  $raw Caller facts.
	 * @return array{id:string,title:string,family:?string,registered:?bool,enabled:?bool,test_mode:?bool,kind:string}
	 */
	private static function facts( string $id, $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$bool   = static fn( $v ): ?bool => is_bool( $v ) ? $v : null;
		$family = is_string( $raw['family'] ?? null ) && 1 === preg_match( self::FAMILY, $raw['family'] ) ? $raw['family'] : null;
		$kind   = in_array( $raw['kind'] ?? null, array( 'instant', 'manual', 'unknown' ), true ) ? $raw['kind'] : 'unknown';
		return array(
			'id'         => $id,
			'title'      => self::method_label( $id ),
			'family'     => $family,
			'registered' => $bool( $raw['registered'] ?? null ),
			'enabled'    => $bool( $raw['enabled'] ?? null ),
			'test_mode'  => $bool( $raw['test_mode'] ?? null ),
			'kind'       => $kind,
		);
	}

	/**
	 * Public MethodFacts row (C8).
	 *
	 * @param array<string,mixed> $f Facts.
	 * @return array<string,mixed>
	 */
	private static function public_facts( array $f ): array {
		return array(
			'id'         => $f['id'],
			'title'      => $f['title'],
			'family'     => $f['family'],
			'registered' => $f['registered'],
			'enabled'    => $f['enabled'],
			'test_mode'  => $f['test_mode'],
			'kind'       => $f['kind'],
		);
	}

	/**
	 * Which log families block an unqualified no_sign (C5): an applicable log row (a supported
	 * source) whose coverage is not complete, that is denied, unreadable, failed or capped. It
	 * blocks only the scopes whose method belongs to that family. Unsupported adapters (not
	 * applicable) never block and are never healthy evidence. A top-level denial or failure with no
	 * applicable per-family row names no supported family, so it blocks nothing.
	 *
	 * An applicable, incomplete row without a valid family token cannot be mapped to one family; it
	 * blocks every scope whose method has a payment family (fail closed for the families that could
	 * have a log), never a scope without one.
	 *
	 * @param array<string,mixed> $logs Log result.
	 * @return array{unmapped:bool,families:array<string,bool>}
	 */
	private static function log_blocks( array $logs ): array {
		$out = array(
			'unmapped' => false,
			'families' => array(),
		);
		$summaries = is_array( $logs['summaries'] ?? null ) ? $logs['summaries'] : array();
		foreach ( $summaries as $sum ) {
			if ( ! is_array( $sum ) || true !== ( $sum['applicable'] ?? null ) || 'complete' === ( $sum['coverage'] ?? null ) ) {
				continue;
			}
			$family = is_string( $sum['family'] ?? null ) && 1 === preg_match( self::FAMILY, $sum['family'] ) ? $sum['family'] : null;
			if ( null === $family ) {
				$out['unmapped'] = true;
			} else {
				$out['families'][ $family ] = true;
			}
		}
		return $out;
	}

	/**
	 * Normalised reader scope: integer counts, bucket and hour keys inside the window, safe ID.
	 *
	 * @param array<string,mixed> $raw  Reader scope.
	 * @param array<string,mixed> $ctx  Context.
	 * @param bool                $scan Whole scan complete.
	 * @param string|null         $stop Scan stop reason.
	 * @return array<string,mixed>
	 */
	private static function norm_scope( array $raw, array $ctx, bool $scan, ?string $stop ): array {
		$counts = array();
		foreach ( array_merge( CardTestingOrders::CLASSES, CardTestingOrders::ORIGINS ) as $k ) {
			$counts[ $k ] = self::int( $raw['counts'][ $k ] ?? 0 );
		}
		$max_b   = 4 * $ctx['since'];
		$buckets = array();
		foreach ( (array) ( $raw['buckets'] ?? array() ) as $i => $c ) {
			if ( ! is_int( $i ) || $i < 0 || $i >= $max_b || ! is_array( $c ) ) {
				continue;
			}
			$cell = array();
			foreach ( array( 'f', 'a', 'ip_valid', 'ip_missing', 'ip_invalid', 'present', 'unknown', 'unsupported' ) as $k ) {
				$cell[ $k ] = self::int( $c[ $k ] ?? 0 );
			}
			$cell['first'] = is_int( $c['first'] ?? null ) ? $c['first'] : null;
			$cell['last']  = is_int( $c['last'] ?? null ) ? $c['last'] : null;
			$buckets[ $i ] = $cell;
		}
		$nets = array();
		foreach ( (array) ( $raw['networks'] ?? array() ) as $i => $map ) {
			if ( ! is_int( $i ) || $i < 0 || $i >= $max_b || ! is_array( $map ) ) {
				continue;
			}
			foreach ( $map as $nid => $n ) {
				if ( is_int( $nid ) && is_int( $n ) && $n > 0 ) {
					$nets[ $i ][ $nid ] = $n;
				}
			}
		}
		$hours = array();
		foreach ( (array) ( $raw['baseline_hours'] ?? array() ) as $h => $n ) {
			if ( is_int( $h ) && $h >= $ctx['since'] && $h < self::HISTORY_DAYS * 24 && is_int( $n ) && $n > 0 ) {
				$hours[ $h ] = $n;
			}
		}
		$b    = (array) ( $raw['baseline'] ?? array() );
		$base = array(
			'attempts'    => self::int( $b['attempts'] ?? 0 ),
			'failed'      => self::int( $b['failed'] ?? 0 ),
			'first'       => is_int( $b['first'] ?? null ) ? $b['first'] : null,
			'last'        => is_int( $b['last'] ?? null ) ? $b['last'] : null,
			'present'     => self::int( $b['present'] ?? 0 ),
			'unknown'     => self::int( $b['unknown'] ?? 0 ),
			'unsupported' => self::int( $b['unsupported'] ?? 0 ),
		);
		$examples = array();
		foreach ( (array) ( $raw['example_candidates'] ?? array() ) as $c ) {
			if ( is_array( $c ) && is_int( $c['id'] ?? null ) && $c['id'] > 0 && is_int( $c['created'] ?? null ) ) {
				$examples[] = array(
					'id'      => $c['id'],
					'created' => $c['created'],
				);
			}
		}
		return array(
			'method_id'          => self::id( $raw['method_id'] ?? '' ),
			'origin_scope'       => 'missing_origin' === ( $raw['origin_scope'] ?? null ) ? 'missing_origin' : 'verified_checkout',
			'counts'             => $counts,
			'buckets'            => $buckets,
			'networks'           => $nets,
			'baseline_hours'     => $hours,
			'baseline'           => $base,
			'invalid_rows'       => self::int( $raw['invalid_rows'] ?? 0 ),
			'anomalies'          => self::int( $raw['anomalies'] ?? 0 ),
			'counts_complete'    => $scan && true === ( $raw['counts_complete'] ?? false ),
			'series_complete'    => true === ( $raw['series_complete'] ?? false ),
			'networks_complete'  => true === ( $raw['networks_complete'] ?? false ),
			'example_candidates' => $examples,
			'members'            => array(),
			'gap'                => $scan ? null : ( $stop ?? 'baseline_incomplete' ),
		);
	}

	/**
	 * Store-wide aggregate of instant-method scopes of one origin scope (C3): counts each order
	 * once (each order is in exactly one method scope) and never mixes origin scopes.
	 *
	 * @param array<int,array<string,mixed>> $members Member scopes.
	 * @param string                         $origin  Origin scope.
	 * @param array<string,mixed>            $ctx     Context.
	 * @param string|null                    $gap     method_cap or invalid_timestamps when a method or invalid-row gap makes the aggregate partial.
	 * @return array<string,mixed>
	 */
	private static function merge( array $members, string $origin, array $ctx, ?string $gap ): array {
		unset( $ctx );
		$out = array(
			'method_id'          => null,
			'origin_scope'       => $origin,
			'counts'             => array_fill_keys( array_merge( CardTestingOrders::CLASSES, CardTestingOrders::ORIGINS ), 0 ),
			'buckets'            => array(),
			'networks'           => array(),
			'baseline_hours'     => array(),
			'baseline'           => array(
				'attempts'    => 0,
				'failed'      => 0,
				'first'       => null,
				'last'        => null,
				'present'     => 0,
				'unknown'     => 0,
				'unsupported' => 0,
			),
			'invalid_rows'       => 0,
			'anomalies'          => 0,
			'counts_complete'    => null === $gap,
			'series_complete'    => true,
			'networks_complete'  => true,
			'example_candidates' => array(),
			'members'            => array(),
			'gap'                => $gap,
		);
		foreach ( $members as $m ) {
			$out['members'][] = $m['method_id'];
			foreach ( $m['counts'] as $k => $v ) {
				$out['counts'][ $k ] += $v;
			}
			foreach ( $m['buckets'] as $i => $c ) {
				if ( ! isset( $out['buckets'][ $i ] ) ) {
					$out['buckets'][ $i ] = $c;
					continue;
				}
				foreach ( array( 'f', 'a', 'ip_valid', 'ip_missing', 'ip_invalid', 'present', 'unknown', 'unsupported' ) as $k ) {
					$out['buckets'][ $i ][ $k ] += $c[ $k ];
				}
				foreach ( array( 'first' => 'min', 'last' => 'max' ) as $k => $fn ) {
					$out['buckets'][ $i ][ $k ] = null === $out['buckets'][ $i ][ $k ] ? $c[ $k ] : ( null === $c[ $k ] ? $out['buckets'][ $i ][ $k ] : $fn( $out['buckets'][ $i ][ $k ], $c[ $k ] ) );
				}
			}
			foreach ( $m['networks'] as $i => $map ) {
				foreach ( $map as $nid => $n ) {
					$out['networks'][ $i ][ $nid ] = ( $out['networks'][ $i ][ $nid ] ?? 0 ) + $n;
				}
			}
			foreach ( $m['baseline_hours'] as $h => $n ) {
				$out['baseline_hours'][ $h ] = ( $out['baseline_hours'][ $h ] ?? 0 ) + $n;
			}
			foreach ( array( 'attempts', 'failed', 'present', 'unknown', 'unsupported' ) as $k ) {
				$out['baseline'][ $k ] += $m['baseline'][ $k ];
			}
			foreach ( array( 'first' => 'min', 'last' => 'max' ) as $k => $fn ) {
				$out['baseline'][ $k ] = null === $out['baseline'][ $k ] ? $m['baseline'][ $k ] : ( null === $m['baseline'][ $k ] ? $out['baseline'][ $k ] : $fn( $out['baseline'][ $k ], $m['baseline'][ $k ] ) );
			}
			$out['invalid_rows']      += $m['invalid_rows'];
			$out['anomalies']         += $m['anomalies'];
			$out['counts_complete']    = $out['counts_complete'] && $m['counts_complete'];
			$out['series_complete']    = $out['series_complete'] && $m['series_complete'];
			$out['networks_complete']  = $out['networks_complete'] && $m['networks_complete'];
			foreach ( $m['example_candidates'] as $c ) {
				$out['example_candidates'][] = $c + array(
					'method_id'    => $m['method_id'],
					'origin_scope' => $origin,
				);
			}
		}
		ksort( $out['buckets'], SORT_NUMERIC );
		foreach ( $members as $m ) {
			if ( null === $out['gap'] && is_string( $m['gap'] ) ) {
				$out['gap'] = $m['gap'];
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------- assessment.

	/**
	 * Assess one scope (C5).
	 *
	 * @param array<string,mixed>                     $s         Scope.
	 * @param array<string,mixed>                     $f         Method facts, or array{families} for an aggregate.
	 * @param array<string,array{supported:bool,reason:?string}> $signals Signals.
	 * @param array<string,mixed>                     $ctx       Context.
	 * @param array{unmapped:bool,families:array<string,bool>} $blocks Log blocks.
	 * @param bool                                    $aggregate Store-wide aggregate.
	 * @return array<string,mixed> Internal evaluation.
	 */
	private static function assess( array $s, array $f, array $signals, array $ctx, array $blocks, bool $aggregate ): array {
		$failed     = $s['counts']['failed_unpaid'];
		$applicable = true;
		$na         = null;
		$kind       = $aggregate ? 'instant' : $f['kind'];
		if ( ! $aggregate ) {
			if ( 'manual' === $kind ) {
				$applicable = false;
				$na         = 'method_manual';
			} elseif ( true === $f['test_mode'] ) {
				$applicable = false;
				$na         = 'method_test_mode';
			} elseif ( 'instant' !== $kind ) {
				$applicable = $failed > 0 || ! $s['counts_complete'];
				$na         = 'method_kind_unknown';
			}
		}
		$assess   = $applicable && 'instant' === $kind && ( $aggregate || true !== $f['test_mode'] );
		$verified = 'verified_checkout' === $s['origin_scope'];
		$exact    = $s['counts_complete'] && $s['series_complete'];

		$b        = $s['baseline'];
		$span     = null !== $b['first'] && null !== $b['last'] ? $b['last'] - $b['first'] : 0;
		$b_reason = null;
		if ( ! $s['counts_complete'] ) {
			$b_reason = 'baseline_incomplete';
		} elseif ( ! $s['series_complete'] ) {
			$b_reason = 'bucket_cell_cap';
		} elseif ( $b['attempts'] < self::MIN_BASELINE_ATTEMPTS ) {
			$b_reason = 'baseline_insufficient_volume';
		} elseif ( $span < self::MIN_BASELINE_SPAN_DAYS * 86400 ) {
			$b_reason = 'baseline_insufficient_span';
		}
		$sufficient = null === $b_reason;
		$p95        = $sufficient ? self::p95( $s['baseline_hours'], $ctx ) : null;

		$hours = array();
		$last  = 4 * $ctx['since'] - 4;
		for ( $k = 0; $k <= $last; $k++ ) {
			$hours[ $k ] = self::hour( $s, $k, $ctx, $signals, $assess, $verified, $sufficient, $p95 );
		}

		$positive = array();
		$context  = false;
		$peak     = 0;
		foreach ( $hours as $k => $h ) {
			$peak = max( $peak, $h['failed'] );
			if ( in_array( $h['verdict'], array( 'likely_attack', 'possible_attack' ), true ) ) {
				$positive[ $k ] = $h;
			}
			if ( $h['low_share'] ) {
				$context = true;
			}
		}

		$blockers = array();
		if ( ! $s['counts_complete'] ) {
			$blockers[] = self::counts_reason( $s );
		}
		if ( ! $s['series_complete'] ) {
			$blockers[] = 'bucket_cell_cap';
		}
		if ( null !== $b_reason && ! in_array( $b_reason, $blockers, true ) && 'bucket_cell_cap' !== $b_reason ) {
			$blockers[] = $b_reason;
		}
		$signal_gaps = array();
		if ( ! $signals['ip']['supported'] ) {
			$signal_gaps[] = (string) $signals['ip']['reason'];
		} elseif ( ! $s['networks_complete'] ) {
			$signal_gaps[] = $ctx['caps']['network_ids'] ? 'network_cap' : 'network_bucket_cell_cap';
		}
		if ( ! $signals['attribution']['supported'] ) {
			$signal_gaps[] = (string) $signals['attribution']['reason'];
		} elseif ( $b['unsupported'] > 0 || self::window_unsupported( $s ) ) {
			$signal_gaps[] = 'attribution_value_unsupported';
		}
		if ( $failed > 0 || ! $s['counts_complete'] ) {
			foreach ( $signal_gaps as $g ) {
				if ( ! in_array( $g, $blockers, true ) ) {
					$blockers[] = $g;
				}
			}
		}
		// A log gap blocks only scopes whose method belongs to an affected family (C5).
		$families = $aggregate ? (array) ( $f['families'] ?? array() ) : ( null === $f['family'] ? array() : array( $f['family'] ) );
		$logs     = $blocks['unmapped'] && array() !== $families;
		foreach ( $families as $fam ) {
			$logs = $logs || isset( $blocks['families'][ $fam ] );
		}
		if ( $logs ) {
			$blockers[] = 'logs_unavailable';
		}
		// C5 on every public negative verdict: an hour can only read no_sign when its scope could;
		// otherwise it is unknown with the scope's first blocker as its reason. Positive hours stay.
		foreach ( $hours as $k => $h ) {
			$hours[ $k ]['gap'] = null;
			if ( 'no_sign' === $h['verdict'] && array() !== $blockers ) {
				$hours[ $k ]['verdict'] = 'unknown';
				$hours[ $k ]['gap']     = $blockers[0];
			}
		}

		$verdict = 'unknown';
		if ( $assess ) {
			$strengths = array_map( static fn( array $h ): string => $h['verdict'], $positive );
			if ( in_array( 'likely_attack', $strengths, true ) ) {
				$verdict = 'likely_attack';
			} elseif ( in_array( 'possible_attack', $strengths, true ) ) {
				$verdict = 'possible_attack';
			} elseif ( array() === $blockers ) {
				$verdict = 'no_sign';
			}
		}

		$activity = 'not_established';
		if ( in_array( $verdict, array( 'likely_attack', 'possible_attack' ), true ) ) {
			$h0 = $hours[0];
			if ( isset( $positive[0] ) && null !== $h0['recent'] && $h0['recent'] >= self::MIN_RECENT_FAILED ) {
				$activity = 'recent';
			} elseif ( $exact ) {
				$activity = 'earlier_in_window';
			} else {
				$activity = 'unknown';
			}
		}

		// Up to 3 non-overlapping representative hours: strength, then failed count, then latest end.
		$ranked = array_keys( $positive );
		usort(
			$ranked,
			static fn( int $a, int $b ): int => array( 'likely_attack' === $positive[ $b ]['verdict'] ? 1 : 0, $positive[ $b ]['failed'], -$b ) <=> array( 'likely_attack' === $positive[ $a ]['verdict'] ? 1 : 0, $positive[ $a ]['failed'], -$a )
		);
		$picked = array();
		foreach ( $ranked as $k ) {
			$clear = true;
			foreach ( $picked as $p ) {
				if ( abs( $p - $k ) < 4 ) {
					$clear = false;
					break;
				}
			}
			if ( ! $clear ) {
				continue;
			}
			if ( count( $picked ) >= self::MAX_EPISODES ) {
				break;
			}
			$picked[] = $k;
		}
		$omitted = false;
		foreach ( $ranked as $k ) {
			if ( in_array( $k, $picked, true ) ) {
				continue;
			}
			$clear = true;
			foreach ( $picked as $p ) {
				if ( abs( $p - $k ) < 4 ) {
					$clear = false;
					break;
				}
			}
			if ( $clear ) {
				$omitted = true;
				break;
			}
		}
		$lead = array() === $picked ? null : $picked[0];
		rsort( $picked );

		$coverage = 'not_applicable';
		if ( $applicable ) {
			$complete = $exact && $s['networks_complete'] && $signals['ip']['supported'] && $signals['attribution']['supported'] && ! in_array( 'attribution_value_unsupported', $signal_gaps, true ) && ! $logs;
			$coverage = $complete ? 'complete' : 'partial';
		}
		$reason = null;
		if ( ! $applicable || ( 'instant' !== $kind && ! $aggregate ) ) {
			$reason = $na;
		} elseif ( 'unknown' === $verdict ) {
			$reason = $blockers[0] ?? null;
		} elseif ( 'partial' === $coverage ) {
			$reason = $blockers[0] ?? ( $signal_gaps[0] ?? null );
		}
		if ( ! $verified && null === $reason && in_array( $verdict, array( 'likely_attack', 'possible_attack' ), true ) ) {
			$reason = 'origin_unverified';
		}

		return array(
			'tz'                 => $ctx['tz'],
			'now'                => $ctx['now'],
			'b_hours'            => $ctx['b_hours'],
			'scope'              => $s,
			'method_id'          => $s['method_id'],
			'origin_scope'       => $s['origin_scope'],
			'aggregate'          => $aggregate,
			'kind'               => $kind,
			'facts'              => $f,
			'applicable'         => $applicable,
			'assessed'           => $assess,
			'coverage'           => $coverage,
			'reason'             => $reason,
			'verdict'            => $assess ? $verdict : 'unknown',
			'activity'           => $assess ? $activity : 'not_established',
			'exact'              => $exact,
			'baseline_sufficient' => $sufficient,
			'baseline_reason'    => $b_reason,
			'p95'                => $p95,
			'hours'              => $hours,
			'episodes'           => $picked,
			'lead'               => $lead,
			'episodes_truncated' => $omitted,
			'blockers'           => $blockers,
			'signal_gaps'        => $signal_gaps,
			'low_share_context'  => $context,
			'peak_failed'        => $peak,
		);
	}

	/**
	 * Why a scope's counts are incomplete.
	 *
	 * @param array<string,mixed> $s Scope.
	 */
	private static function counts_reason( array $s ): string {
		if ( is_string( $s['gap'] ?? null ) ) {
			return $s['gap'];
		}
		if ( $s['invalid_rows'] > 0 ) {
			return 'invalid_timestamps';
		}
		return $s['anomalies'] > 0 ? 'draft_paid_anomaly' : 'baseline_incomplete';
	}

	/**
	 * Whether any failed order in the window has an unsupported attribution value.
	 *
	 * @param array<string,mixed> $s Scope.
	 */
	private static function window_unsupported( array $s ): bool {
		foreach ( $s['buckets'] as $c ) {
			if ( $c['unsupported'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Nearest-rank P95 of the baseline's hourly failed counts, zero-filled: the value at rank
	 * ceil(0.95 n) of the ascending list, n = whole baseline hours.
	 *
	 * @param array<int,int>      $hours Hour index => failed.
	 * @param array<string,mixed> $ctx   Context.
	 */
	public static function p95( array $hours, array $ctx ): int {
		$n    = (int) $ctx['b_hours'];
		$vals = array_values( $hours );
		if ( $n <= 0 ) {
			return 0;
		}
		$vals = array_merge( $vals, array_fill( 0, max( 0, $n - count( $vals ) ), 0 ) );
		sort( $vals, SORT_NUMERIC );
		$rank = intdiv( 95 * $n + 99, 100 );
		return (int) $vals[ $rank - 1 ];
	}

	/**
	 * Exact share test num/den >= pct/100 (whole-percent thresholds; no float at the boundary).
	 *
	 * @param int   $num Numerator.
	 * @param int   $den Denominator (> 0).
	 * @param float $min Threshold share.
	 */
	public static function share_at_least( int $num, int $den, float $min ): bool {
		return $den > 0 && 100 * $num >= (int) round( $min * 100 ) * $den;
	}

	/**
	 * Evaluate hour k of a scope.
	 *
	 * @param array<string,mixed>                               $s          Scope.
	 * @param int                                               $k          Hour index.
	 * @param array<string,mixed>                               $ctx        Context.
	 * @param array<string,array{supported:bool,reason:?string}> $signals    Signals.
	 * @param bool                                              $assess     Verdicts apply.
	 * @param bool                                              $verified   Verified-origin scope.
	 * @param bool                                              $sufficient Usable baseline.
	 * @param int|null                                          $p95        Baseline P95.
	 * @return array<string,mixed>
	 */
	private static function hour( array $s, int $k, array $ctx, array $signals, bool $assess, bool $verified, bool $sufficient, ?int $p95 ): array {
		$end  = $ctx['now'] - $k * self::BUCKET_SECONDS;
		$sum  = array_fill_keys( array( 'f', 'a', 'ip_valid', 'ip_missing', 'ip_invalid', 'present', 'unknown', 'unsupported' ), 0 );
		$fst  = null;
		$lst  = null;
		$nets = array();
		for ( $i = $k; $i < $k + 4; $i++ ) {
			$c = $s['buckets'][ $i ] ?? null;
			if ( null !== $c ) {
				foreach ( $sum as $key => $v ) {
					$sum[ $key ] = $v + $c[ $key ];
				}
				$fst = null === $c['first'] ? $fst : ( null === $fst ? $c['first'] : min( $fst, $c['first'] ) );
				$lst = null === $c['last'] ? $lst : ( null === $lst ? $c['last'] : max( $lst, $c['last'] ) );
			}
			foreach ( $s['networks'][ $i ] ?? array() as $nid => $n ) {
				$nets[ $nid ] = ( $nets[ $nid ] ?? 0 ) + $n;
			}
		}
		$f      = $sum['f'];
		$a      = $sum['a'];
		$exact  = $s['counts_complete'] && $s['series_complete'];
		$b      = $s['baseline'];
		$bh     = (int) $ctx['b_hours'];
		$recent = 0 === $k ? ( $s['buckets'][0]['f'] ?? 0 ) : 0;

		$relative = null;
		if ( $exact && $sufficient && null !== $p95 ) {
			$relative = $f >= self::MIN_BURST_FAILED
				&& $f * $bh >= self::BASELINE_MULTIPLIER * max( $bh, $b['failed'] )
				&& $f >= self::BASELINE_P95_MULTIPLIER * $p95;
		}
		$absolute = $f >= self::ABSOLUTE_BURST_FAILED ? true : ( $exact ? false : null );

		// Corroborator N: concentration of valid-IP failed orders in recorded networks.
		$net = array(
			'checked'    => false,
			'coverage'   => 'unavailable',
			'reason'     => $signals['ip']['reason'],
			'valid'      => null,
			'missing'    => null,
			'invalid'    => null,
			'groups'     => null,
			'top'        => null,
			'signal'     => null,
		);
		if ( $signals['ip']['supported'] ) {
			$net['checked'] = true;
			if ( ! $exact ) {
				$net['coverage'] = 'partial';
				$net['reason']   = $s['series_complete'] ? 'baseline_incomplete' : 'bucket_cell_cap';
			} else {
				$net['valid']   = $sum['ip_valid'];
				$net['missing'] = $sum['ip_missing'];
				$net['invalid'] = $sum['ip_invalid'];
				if ( ! $s['networks_complete'] ) {
					$net['coverage'] = 'partial';
					$net['reason']   = $ctx['caps']['network_ids'] ? 'network_cap' : 'network_bucket_cell_cap';
				} else {
					rsort( $nets, SORT_NUMERIC );
					$net['coverage'] = 'complete';
					$net['reason']   = null;
					$net['groups']   = count( $nets );
					$net['top']      = array_sum( array_slice( $nets, 0, self::TOP_NETWORKS ) );
					$net['signal']   = $net['valid'] >= self::MIN_NETWORK_ORDERS
						&& self::share_at_least( $net['valid'], $f, self::MIN_IP_COMPLETENESS )
						&& self::share_at_least( $net['top'], $net['valid'], self::MIN_TOP_NETWORK_SHARE );
				}
			}
		}

		// Corroborator A: unknown attribution among this hour's failed orders versus the baseline.
		$attr = array(
			'checked'  => false,
			'coverage' => 'unavailable',
			'reason'   => $signals['attribution']['reason'],
			'unknown'  => null,
			'b_unk'    => null,
			'b_pres'   => null,
			'signal'   => null,
		);
		if ( $signals['attribution']['supported'] ) {
			$attr['checked'] = true;
			if ( ! $exact ) {
				$attr['coverage'] = 'partial';
				$attr['reason']   = $s['series_complete'] ? 'baseline_incomplete' : 'bucket_cell_cap';
			} else {
				$attr['unknown']  = array( $sum['unknown'], $f );
				$attr['b_unk']    = array( $b['unknown'], $b['attempts'] );
				$attr['b_pres']   = array( $b['present'], $b['attempts'] );
				$attr['coverage'] = 'complete';
				$attr['reason']   = null;
				if ( $b['unsupported'] > 0 || $sum['unsupported'] > 0 ) {
					$attr['coverage'] = 'partial';
					$attr['reason']   = 'attribution_value_unsupported';
				} elseif ( $b['attempts'] < self::MIN_BASELINE_ATTEMPTS || ! self::share_at_least( $b['present'], $b['attempts'], self::MIN_ATTR_BASELINE_PRESENT_SHARE ) ) {
					$attr['reason'] = 'attribution_baseline_insufficient';
				} elseif ( 0 === $f ) {
					$attr['reason'] = 'no_failed_orders';
				} else {
					$shift          = 100 * $sum['unknown'] * $b['attempts'] - 100 * $b['unknown'] * $f;
					$attr['signal'] = self::share_at_least( $sum['unknown'], $f, self::MIN_ATTR_UNKNOWN_SHARE )
						&& $shift >= (int) round( self::MIN_ATTR_SHIFT * 100 ) * $f * $b['attempts'];
				}
			}
		}

		$burst     = true === $relative || true === $absolute;
		$verdict   = 'unknown';
		$low_share = false;
		$share_unk = false;
		if ( $assess && $burst ) {
			if ( $exact ) {
				if ( self::share_at_least( $f, $f + $a, self::MIN_FAILED_SHARE ) && true === $net['signal'] && true === $attr['signal'] && $verified ) {
					$verdict = 'likely_attack';
				} elseif ( self::share_at_least( $f, $f + $a, self::MIN_POSSIBLE_FAILED_SHARE ) ) {
					$verdict = 'possible_attack';
				} else {
					$low_share = true; // A fully observed burst with a low failed share: volume context only.
				}
			} else {
				// Lower-bound observed counts establish the absolute burst only; the share is unknown.
				$verdict   = 'possible_attack';
				$share_unk = true;
			}
		}
		if ( 'unknown' === $verdict && $assess && $exact && $sufficient ) {
			$verdict = 'no_sign'; // No qualifying condition in this fully read hour (a low-share burst is context only).
		}

		return array(
			'k'         => $k,
			'start'     => $end - self::BURST_SECONDS,
			'end'       => $end,
			'failed'    => $f,
			'accepted'  => $a,
			'exact'     => $exact,
			'first'     => $fst,
			'last'      => $lst,
			'recent'    => $recent,
			'relative'  => $relative,
			'absolute'  => $absolute,
			'net'       => $net,
			'attr'      => $attr,
			'verdict'   => $verdict,
			'low_share' => $low_share,
			'share_unk' => $share_unk,
		);
	}

	// ---------------------------------------------------------------- public shapes.

	/**
	 * Count (C8): observed distinct orders, lower_bound when not every relevant order was read.
	 *
	 * @param int|null $v     Value.
	 * @param bool     $lower Lower bound.
	 * @return array{value:?int,lower_bound:bool}
	 */
	private static function count( ?int $v, bool $lower ): array {
		return array(
			'value'       => $v,
			'lower_bound' => null !== $v && $lower,
		);
	}

	/**
	 * Share (C8): explicit numerator and denominator; null when the denominator is incomplete.
	 *
	 * @param int|null $num   Numerator.
	 * @param int|null $den   Denominator.
	 * @param bool     $exact Complete denominator.
	 * @return array{numerator:?int,denominator:?int,value:?float}
	 */
	private static function share( ?int $num, ?int $den, bool $exact = true ): array {
		if ( ! $exact || null === $num || null === $den ) {
			return array(
				'numerator'   => null,
				'denominator' => null,
				'value'       => null,
			);
		}
		return array(
			'numerator'   => $num,
			'denominator' => $den,
			'value'       => $den > 0 ? round( $num / $den, 4 ) : null,
		);
	}

	/**
	 * Check fields (C8).
	 *
	 * @param bool        $checked    Some data read.
	 * @param bool        $applicable Applicable.
	 * @param string      $coverage   complete|partial|unavailable|not_applicable.
	 * @param string|null $code       Reason code.
	 * @return array<string,mixed>
	 */
	private static function check( bool $checked, bool $applicable, string $coverage, ?string $code ): array {
		return array(
			'checked'     => $checked,
			'applicable'  => $applicable,
			'coverage'    => $coverage,
			'reason_code' => $code,
			'reason'      => self::reason_text( $code ),
		);
	}

	/**
	 * Counts (C8) for one scope or the totals.
	 *
	 * @param array<string,int>   $c         Class and origin counts.
	 * @param array<string,mixed> $check     Check fields.
	 * @param bool                $lower     Lower bounds.
	 * @param int|null            $unassigned Unassigned-method orders.
	 * @param int|null            $invalid   Invalid rows.
	 * @param int|null            $test      Test-mode orders.
	 * @param bool                $test_lower Test-mode lower bound.
	 * @return array<string,mixed>
	 */
	private static function counts( array $c, array $check, bool $lower, ?int $unassigned, ?int $invalid, ?int $test, bool $test_lower ): array {
		$out = $check;
		foreach ( array_merge( CardTestingOrders::CLASSES, CardTestingOrders::ORIGINS ) as $k ) {
			$out[ $k ] = self::count( $c[ $k ] ?? null, $lower );
		}
		$out['unassigned_method'] = self::count( $unassigned, $lower );
		$out['invalid_rows']      = self::count( $invalid, $lower );
		$out['test_mode_orders']  = self::count( $test, $test_lower );
		return $out;
	}

	/**
	 * Top-level totals (C8): every valid retained shop order created in the window, any origin or
	 * method. Never labelled as assessed card activity.
	 *
	 * @param array<string,mixed>                $orders    Reader result.
	 * @param array<string,array<string,mixed>>  $facts     Facts.
	 * @param bool                               $scan      Scan complete.
	 * @param string|null                        $stop      Stop reason.
	 * @param bool                               $truncated Method map capped.
	 * @return array<string,mixed>
	 */
	private static function totals( array $orders, array $facts, bool $scan, ?string $stop, bool $truncated ): array {
		$t = array();
		foreach ( array_merge( CardTestingOrders::CLASSES, CardTestingOrders::ORIGINS, array( 'unassigned_method', 'invalid_rows' ) ) as $k ) {
			$t[ $k ] = self::int( $orders['totals'][ $k ] ?? 0 );
		}
		$test       = 0;
		$test_lower = $truncated;
		foreach ( (array) ( $orders['method_orders'] ?? array() ) as $id => $n ) {
			$id = self::id( $id );
			$f  = $facts[ $id ] ?? null;
			if ( null === $f ) {
				continue;
			}
			if ( true === $f['test_mode'] ) {
				$test += self::int( $n );
			} elseif ( null === $f['test_mode'] && self::int( $n ) > 0 && 'manual' !== $f['kind'] ) {
				$test_lower = true;
			}
		}
		$lower = ! $scan || $t['invalid_rows'] > 0 || self::int( $orders['draft_anomalies'] ?? 0 ) > 0;
		$code  = $stop;
		if ( null === $code && $t['invalid_rows'] > 0 ) {
			$code = 'invalid_timestamps';
		} elseif ( null === $code && self::int( $orders['draft_anomalies'] ?? 0 ) > 0 ) {
			$code = 'draft_paid_anomaly';
		}
		$check = self::check( true, true, $lower ? 'partial' : 'complete', $code );
		return self::counts( $t, $check, $lower, $t['unassigned_method'], $t['invalid_rows'], $test, $test_lower );
	}

	/**
	 * Public Burst (C8) for hour k of an evaluation.
	 *
	 * @param array<string,mixed> $e Evaluation.
	 * @param int                 $k Hour index.
	 * @return array<string,mixed>
	 */
	private static function burst( array $e, int $k ): array {
		$h       = $e['hours'][ $k ];
		$exact   = $h['exact'];
		$tz      = $e['tz'];
		$verdict = $e['assessed'] ? $h['verdict'] : 'unknown';
		$cov     = 'not_applicable';
		$code    = $e['reason'];
		if ( $e['applicable'] ) {
			// An hour's coverage includes its scope's own gaps (signals, maps, logs), not only its own
			// network and attribution checks, so a gap never sits under a complete hour (C5/C8).
			$cov = $exact && 'complete' === $h['net']['coverage'] && 'complete' === $h['attr']['coverage'] && 'complete' === $e['coverage'] ? 'complete' : 'partial';
			if ( $h['share_unk'] ) {
				$code = 'share_unknown';
			} elseif ( ! $exact ) {
				$code = $e['scope']['counts_complete'] ? 'bucket_cell_cap' : self::counts_reason( $e['scope'] );
			} elseif ( 'unknown' === $verdict ) {
				$code = $e['assessed'] ? ( $h['gap'] ?? $e['blockers'][0] ?? $e['reason'] ) : $e['reason'];
			} elseif ( 'partial' === $cov ) {
				$code = $h['net']['reason'] ?? $h['attr']['reason'] ?? $e['reason'] ?? $e['blockers'][0] ?? $e['signal_gaps'][0] ?? null;
			} else {
				$code = null;
			}
		}
		$out = self::check( true, $e['applicable'], $cov, $code );
		$net = $h['net'];
		$att = $h['attr'];

		$out['start']           = PaymentRules::iso( $h['start'], $tz );
		$out['end']             = PaymentRules::iso( $h['end'], $tz );
		$out['failed_orders']   = self::count( $h['failed'], ! $exact );
		$out['accepted_orders'] = self::count( $h['accepted'], ! $exact );
		$out['failed_share']    = self::share( $h['failed'], $h['failed'] + $h['accepted'], $exact );
		$out['relative_burst']  = $h['relative'];
		$out['absolute_burst']  = $h['absolute'];
		$out['networks']        = self::check( $net['checked'], $e['applicable'], $e['applicable'] ? $net['coverage'] : 'not_applicable', $net['reason'] ) + array(
			'valid_ip_orders'   => $net['valid'],
			'missing_ip_orders' => $net['missing'],
			'invalid_ip_orders' => $net['invalid'],
			'groups'            => $net['groups'],
			'top_three_share'   => self::share( $net['top'], $net['valid'], null !== $net['top'] ),
			'signal'            => $net['signal'],
		);
		$out['attribution']     = self::check( $att['checked'], $e['applicable'], $e['applicable'] ? $att['coverage'] : 'not_applicable', $att['reason'] ) + array(
			'unknown_share'          => self::share( $att['unknown'][0] ?? null, $att['unknown'][1] ?? null, null !== $att['unknown'] ),
			'baseline_unknown_share' => self::share( $att['b_unk'][0] ?? null, $att['b_unk'][1] ?? null, null !== $att['b_unk'] ),
			'baseline_present_share' => self::share( $att['b_pres'][0] ?? null, $att['b_pres'][1] ?? null, null !== $att['b_pres'] ),
			'signal'                 => $att['signal'],
		);
		$out['first_created_at']     = null === $h['first'] ? null : PaymentRules::iso( $h['first'], $tz );
		$out['last_created_at']      = null === $h['last'] ? null : PaymentRules::iso( $h['last'], $tz );
		$out['recent_failed_orders'] = self::count( $h['recent'], ! $exact );
		$out['verdict']              = $verdict;
		return $out;
	}

	/**
	 * Public Assessment (C8).
	 *
	 * @param array<string,mixed> $e Evaluation.
	 * @return array<string,mixed>
	 */
	private static function public_assessment( array $e ): array {
		$s     = $e['scope'];
		$exact = $e['exact'];
		$test  = $e['aggregate'] ? 0 : ( true === $e['facts']['test_mode'] ? array_sum( array_intersect_key( $s['counts'], array_flip( CardTestingOrders::CLASSES ) ) ) : ( false === $e['facts']['test_mode'] ? 0 : null ) );
		$cc    = self::check( true, $e['applicable'], $e['applicable'] ? ( $s['counts_complete'] ? 'complete' : 'partial' ) : 'not_applicable', $s['counts_complete'] ? null : self::counts_reason( $s ) );
		$b     = $s['baseline'];
		$out   = self::check( true, $e['applicable'], $e['coverage'], $e['reason'] );

		$out['method_id']                = $e['method_id'];
		$out['origin_scope']             = $e['origin_scope'];
		$out['verdict']                  = $e['verdict'];
		$out['activity']                 = $e['activity'];
		$out['counts']                   = self::counts( $s['counts'], $cc, ! $s['counts_complete'], 0, $s['invalid_rows'], $test, false );
		$out['baseline_attempt_orders']  = $s['counts_complete'] ? $b['attempts'] : null;
		$out['baseline_failed_orders']   = $s['counts_complete'] ? $b['failed'] : null;
		$out['baseline_failed_per_hour'] = $e['baseline_sufficient'] ? round( $b['failed'] / max( 1, (int) $e['b_hours'] ), 4 ) : null;
		$out['baseline_p95_failed_hour'] = $e['baseline_sufficient'] && null !== $e['p95'] ? (float) $e['p95'] : null;
		$out['baseline_sufficient']      = $e['baseline_sufficient'];
		$out['latest_hour']              = self::burst( $e, 0 );
		$out['episodes']                 = array_values( array_map( static fn( int $k ): array => self::burst( $e, $k ), $e['episodes'] ) );
		$out['episodes_truncated']       = $e['episodes_truncated'];
		unset( $exact );
		return $out;
	}

	/**
	 * Stable assessment order (C8): likely, possible, unknown (applicable), no_sign, then
	 * not applicable; store aggregate before methods; method ID; verified before missing origin.
	 *
	 * @param array<string,mixed> $a A.
	 * @param array<string,mixed> $b B.
	 */
	private static function eval_order( array $a, array $b ): int {
		$rank = static function ( array $e ): int {
			if ( ! $e['applicable'] ) {
				return 4;
			}
			return (int) array_search( $e['verdict'], self::VERDICTS, true );
		};
		return array( $rank( $a ), null === $a['method_id'] ? 0 : 1, (string) $a['method_id'], 'verified_checkout' === $a['origin_scope'] ? 0 : 1 )
			<=> array( $rank( $b ), null === $b['method_id'] ? 0 : 1, (string) $b['method_id'], 'verified_checkout' === $b['origin_scope'] ? 0 : 1 );
	}

	/**
	 * Result when orders could not be read (no counts, no verdict).
	 *
	 * @param array<string,mixed> $frame  Window and baseline.
	 * @param string              $reason Reason code.
	 * @param array<string,mixed> $orders Reader result (for the installed version only).
	 * @return array<string,mixed>
	 */
	private static function unavailable( array $frame, string $reason, array $orders ): array {
		$check  = self::check( false, true, 'unavailable', $reason );
		$totals = $check;
		foreach ( array_merge( CardTestingOrders::CLASSES, CardTestingOrders::ORIGINS, array( 'unassigned_method', 'invalid_rows', 'test_mode_orders' ) ) as $k ) {
			$totals[ $k ] = self::count( null, false );
		}
		$limits   = self::base_limitations();
		$limits[] = self::version_limitation( $orders );
		array_unshift( $limits, self::limitation( 'store', $reason ) );
		return array(
			'rule_version'       => self::RULE_VERSION,
			'verdict'            => 'unknown',
			'activity'           => 'not_established',
			'coverage'           => 'unavailable',
			'summary'            => self::unknown_summary( $reason ),
			'window'             => $frame['window'],
			'baseline'           => $frame['baseline'],
			'totals'             => $totals,
			'methods'            => array(),
			'methods_total'      => null,
			'methods_truncated'  => false,
			'assessments'        => array(),
			'assessments_total'  => null,
			'findings'           => array(),
			'findings_total'     => 0,
			'limitations'        => $limits,
			'example_candidates' => array(),
			'examples_truncated' => false,
		);
	}

	/**
	 * Example candidates (C4): latest failed orders of positive scopes first, ID tie-break, at most
	 * 5 in total, each with its method and origin scope for revalidation.
	 *
	 * @param array<int,array<string,mixed>> $positive Positive evaluations.
	 * @param array<int,array<string,mixed>> $scopes   Method scopes.
	 * @return array{candidates:array<int,array<string,mixed>>,truncated:bool}
	 */
	private static function example_candidates( array $positive, array $scopes ): array {
		$pool   = array();
		$failed = array();
		foreach ( $positive as $e ) {
			$members = $e['aggregate'] ? $e['scope']['members'] : array( $e['method_id'] );
			foreach ( $scopes as $s ) {
				if ( $s['origin_scope'] !== $e['origin_scope'] || ! in_array( $s['method_id'], $members, true ) ) {
					continue;
				}
				$failed[ $s['origin_scope'] . '|' . $s['method_id'] ] = $s['counts']['failed_unpaid'];
				foreach ( $s['example_candidates'] as $c ) {
					$pool[ $c['id'] ] = array(
						'id'           => $c['id'],
						'created'      => $c['created'],
						'method_id'    => $s['method_id'],
						'origin_scope' => $s['origin_scope'],
					);
				}
			}
		}
		$pool = array_values( $pool );
		usort( $pool, static fn( array $a, array $b ): int => array( $b['created'], $b['id'] ) <=> array( $a['created'], $a['id'] ) );
		$out = array_slice( $pool, 0, self::MAX_EXAMPLES );
		return array(
			'candidates' => $out,
			'truncated'  => array_sum( $failed ) > count( $out ),
		);
	}

	// ---------------------------------------------------------------- findings and limitations.

	/**
	 * Findings (C8), sorted warning before info, store before method, method ID, code. Uncapped:
	 * the ability shows the first MAX_FINDINGS.
	 *
	 * @param array<int,array<string,mixed>> $evals Evaluations.
	 * @param array<string,mixed>            $ctx   Context.
	 * @return array<int,array<string,mixed>>
	 */
	private static function findings( array $evals, array $ctx ): array {
		$out     = array();
		$members = array();
		foreach ( $evals as $e ) {
			if ( ! $e['aggregate'] && $e['applicable'] && in_array( $e['verdict'], array( 'likely_attack', 'possible_attack' ), true ) ) {
				$members[ $e['origin_scope'] ][ $e['method_id'] ] = $e['verdict'];
			}
		}
		foreach ( $evals as $e ) {
			if ( $e['applicable'] && in_array( $e['verdict'], array( 'likely_attack', 'possible_attack' ), true ) ) {
				if ( $e['aggregate'] ) {
					// Store-wide only when spread across methods: no member method shows the same or a stronger verdict.
					$best = 'unknown';
					foreach ( $members[ $e['origin_scope'] ] ?? array() as $v ) {
						$best = 'likely_attack' === $v || 'likely_attack' === $best ? 'likely_attack' : $v;
					}
					if ( 'likely_attack' === $best || $best === $e['verdict'] ) {
						continue;
					}
				}
				$out[] = array(
					'severity'   => 'warning',
					'code'       => 'likely_attack' === $e['verdict'] ? 'likely_card_testing' : 'possible_card_testing',
					'method_id'  => $e['method_id'],
					'what'       => self::positive_text( $e, $ctx ),
					'check_next' => __( 'Review these orders and your payment provider\'s fraud or decline dashboard, and check that every payment path listed under protection has a server-side bot check.', 'blaze-mcp-toolkit-for-woocommerce' ),
				);
				continue;
			}
			if ( $e['applicable'] && $e['assessed'] && $e['low_share_context'] ) {
				$out[] = array(
					'severity'   => 'info',
					'code'       => 'failed_order_volume_context',
					'method_id'  => $e['method_id'],
					/* translators: %s: where the orders were paid, for example "Stripe" or "all payment methods that charge at checkout". */
					'what'       => sprintf( __( 'An hour had a high number of failed orders for %s, but most orders created in that hour were accepted, so this looks like ordinary declines during busy trading rather than card testing.', 'blaze-mcp-toolkit-for-woocommerce' ), self::scope_label( $e ) ),
					'check_next' => __( 'No action is needed for this on its own. If declines keep rising, compare with your payment provider\'s decline reasons.', 'blaze-mcp-toolkit-for-woocommerce' ),
				);
			}
			if ( ! $e['aggregate'] && $e['applicable'] && ! $e['assessed'] && $e['peak_failed'] >= self::MIN_BURST_FAILED ) {
				$out[] = array(
					'severity'   => 'info',
					'code'       => 'unassessed_method_failed_orders',
					'method_id'  => $e['method_id'],
					/* translators: 1: number of failed orders in one hour, 2: payment method label. */
					'what'       => sprintf( __( 'Up to %1$s failed orders were created in one hour for %2$s. How this payment method takes payments is not verified, so it was not assessed for card testing.', 'blaze-mcp-toolkit-for-woocommerce' ), self::num( $e['peak_failed'] ), self::scope_label( $e ) ),
					'check_next' => __( 'Check these failed orders and the payment provider\'s own fraud or decline dashboard.', 'blaze-mcp-toolkit-for-woocommerce' ),
				);
			}
		}
		$rank = array(
			'warning' => 0,
			'info'    => 1,
		);
		usort(
			$out,
			static fn( array $a, array $b ): int => array( $rank[ $a['severity'] ], null === $a['method_id'] ? 0 : 1, (string) $a['method_id'], $a['code'] )
				<=> array( $rank[ $b['severity'] ], null === $b['method_id'] ? 0 : 1, (string) $b['method_id'], $b['code'] )
		);
		return $out;
	}

	/**
	 * One limitation (C8).
	 *
	 * @param string      $scope store|method:<id>.
	 * @param string      $code  Code.
	 * @param string|null $what  Override text.
	 * @return array{scope:string,code:string,what:string,check_next:?string}
	 */
	private static function limitation( string $scope, string $code, ?string $what = null ): array {
		$next = null;
		switch ( $code ) {
			case 'cause_unverified':
			case 'unsaved_and_retried_orders':
				$next = __( 'Check your payment provider\'s fraud or decline dashboard, which sees every payment request.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'logs_unavailable':
				$next = __( 'Ask an administrator to allow diagnostics for your role, or check the payment plugin\'s logs in WooCommerce > Status > Logs.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'attribution_feature_disabled':
				$next = __( 'To give this check more evidence, turn on WooCommerce > Settings > Advanced > Features > Order Attribution. It records where orders came from; it does not block bots.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'missing_origin_orders':
				$next = __( 'Open a few of these orders and check how they were created.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
		}
		return array(
			'scope'      => $scope,
			'code'       => $code,
			'what'       => $what ?? (string) self::reason_text( $code ),
			'check_next' => $next,
		);
	}

	/**
	 * Installed WooCommerce version and source-pin provenance (ruling Q3), present on every result:
	 * the safe installed version text (or that it could not be read) and the supported range and
	 * source pins of the saved network and marketing source reads. Never raw constant text.
	 *
	 * @param array<string,mixed> $orders Reader result.
	 * @return array{scope:string,code:string,what:string,check_next:?string}
	 */
	private static function version_limitation( array $orders ): array {
		return self::limitation( 'store', 'order_signal_versions', self::reason_text( 'order_signal_versions' ) . ' ' . self::installed_version_text( CardTestingOrders::display_version( $orders['wc_version'] ?? null ) ) );
	}

	/**
	 * One fixed sentence naming the installed WooCommerce version and how the order signals treat
	 * it. Worded as lane protection's CheckoutProtection::installed_version_text() (same strings
	 * where the meaning is the same). The version is display text, never raw constant text.
	 *
	 * @param string|null $version CardTestingOrders::display_version() text.
	 */
	public static function installed_version_text( ?string $version ): string {
		if ( null === $version ) {
			return __( 'The installed WooCommerce version could not be read, so it is treated as outside the supported range.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( CardTestingOrders::version_supported( $version ) ) {
			if ( in_array( $version, CardTestingOrders::SOURCE_PINS, true ) ) {
				/* translators: %s: installed WooCommerce version, for example 11.3.1. */
				return sprintf( __( 'WooCommerce %s is installed, one of the versions whose source was checked.', 'blaze-mcp-toolkit-for-woocommerce' ), $version );
			}
			/* translators: %s: installed WooCommerce version, for example 11.3.2. */
			return sprintf( __( 'WooCommerce %s is installed. It is inside the supported range but its source was not checked separately.', 'blaze-mcp-toolkit-for-woocommerce' ), $version );
		}
		if ( false !== strpos( $version, '-' ) ) {
			/* translators: %s: installed WooCommerce version, for example 11.4.0-beta.1. */
			return sprintf( __( 'WooCommerce %s is installed. Test and pre-release versions are outside the supported range.', 'blaze-mcp-toolkit-for-woocommerce' ), $version );
		}
		/* translators: %s: installed WooCommerce version, for example 11.4.0. */
		return sprintf( __( 'WooCommerce %s is installed, which is outside the supported range.', 'blaze-mcp-toolkit-for-woocommerce' ), $version );
	}

	/**
	 * Mandatory limitations present on every result.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function base_limitations(): array {
		return array(
			self::limitation( 'store', 'order_state_basis' ),
			self::limitation( 'store', 'unsaved_and_retried_orders' ),
			self::limitation( 'store', 'cause_unverified' ),
		);
	}

	/**
	 * Limitations (C8): mandatory notes, then every gap, bound, unassessed method and context
	 * exclusion that applies. Outside the findings cap.
	 *
	 * @param array<string,mixed>                $orders        Reader result.
	 * @param array<int,array<string,mixed>>     $evals         Evaluations.
	 * @param array<string,array<string,mixed>>  $facts         Facts.
	 * @param array<string,array<string,mixed>>  $signals       Signals.
	 * @param array<string,mixed>                $blocks        Log blocks.
	 * @param string|null                        $global_reason No-vote reason.
	 * @param string|null                        $stop          Scan stop reason.
	 * @param bool                               $truncated     Method cap.
	 * @param bool                               $overflow      Invalid-row map cap.
	 * @return array<int,array<string,mixed>>
	 */
	private static function limitations( array $orders, array $evals, array $facts, array $signals, array $blocks, ?string $global_reason, ?string $stop, bool $truncated, bool $overflow ): array {
		$out   = self::base_limitations();
		$out[] = self::version_limitation( $orders );
		$add = static function ( string $scope, string $code, ?string $what = null ) use ( &$out ): void {
			foreach ( $out as $l ) {
				if ( $l['scope'] === $scope && $l['code'] === $code ) {
					return;
				}
			}
			$out[] = self::limitation( $scope, $code, $what );
		};
		if ( null !== $global_reason ) {
			$add( 'store', $global_reason );
		}
		if ( null !== $stop ) {
			$add( 'store', $stop );
		}
		if ( $truncated ) {
			$add( 'store', 'method_cap' );
		}
		if ( $overflow || self::int( $orders['totals']['invalid_rows'] ?? 0 ) > 0 ) {
			$add( 'store', 'invalid_timestamps' );
		}
		if ( self::int( $orders['draft_anomalies'] ?? 0 ) > 0 ) {
			$add( 'store', 'draft_paid_anomaly' );
		}
		foreach ( array( 'bucket_cells' => 'bucket_cell_cap', 'network_ids' => 'network_cap', 'network_cells' => 'network_bucket_cell_cap' ) as $cap => $code ) {
			if ( true === ( $orders['caps'][ $cap ] ?? false ) ) {
				$add( 'store', $code );
			}
		}
		foreach ( array( 'ip', 'attribution' ) as $sig ) {
			if ( ! $signals[ $sig ]['supported'] ) {
				$add( 'store', (string) $signals[ $sig ]['reason'] );
			}
		}
		if ( $signals['ip']['supported'] ) {
			$add( 'store', 'recorded_network_limits' );
		}
		foreach ( $evals as $e ) {
			if ( $e['applicable'] && in_array( 'logs_unavailable', $e['blockers'], true ) ) {
				$add( 'store', 'logs_unavailable' );
			}
		}
		unset( $blocks );
		$low = false;
		foreach ( $evals as $e ) {
			if ( $e['assessed'] && ! $e['baseline_sufficient'] ) {
				$low = true;
			}
			if ( in_array( 'attribution_value_unsupported', $e['signal_gaps'], true ) ) {
				$add( 'store', 'attribution_value_unsupported' );
			}
			if ( $e['aggregate'] ) {
				continue;
			}
			$scope = 'method:' . $e['method_id'];
			if ( ! $e['applicable'] || ! $e['assessed'] ) {
				if ( null !== $e['reason'] ) {
					$add( $scope, $e['reason'] );
				}
			} elseif ( null === $e['facts']['test_mode'] ) {
				$add( $scope, 'test_mode_unknown' );
			}
		}
		if ( $low ) {
			$add( 'store', 'low_history' );
		}
		$t       = $orders['totals'] ?? array();
		$missing = self::int( $t['missing_origin'] ?? 0 );
		if ( $missing > 0 ) {
			/* translators: %s: number of orders. */
			$add( 'store', 'missing_origin_orders', sprintf( _n( '%s order created in this window has no record of where it came from. Such orders are assessed separately and can at most be a possible sign of card testing.', '%s orders created in this window have no record of where they came from. Such orders are assessed separately and can at most be a possible sign of card testing.', $missing, 'blaze-mcp-toolkit-for-woocommerce' ), self::num( $missing ) ) );
		}
		$other = self::int( $t['other_origin'] ?? 0 );
		if ( $other > 0 ) {
			/* translators: %s: number of orders. */
			$add( 'store', 'other_origin_orders', sprintf( _n( '%s order in this window was created another way, for example by an administrator, an import or a subscription renewal. It is counted but not assessed.', '%s orders in this window were created another way, for example by an administrator, an import or a subscription renewal. They are counted but not assessed.', $other, 'blaze-mcp-toolkit-for-woocommerce' ), self::num( $other ) ) );
		}
		$unassigned = self::int( $t['unassigned_method'] ?? 0 );
		if ( $unassigned > 0 ) {
			/* translators: %s: number of orders. */
			$add( 'store', 'unassigned_method_orders', sprintf( _n( '%s order in this window has no payment method recorded. It is counted but not assessed.', '%s orders in this window have no payment method recorded. They are counted but not assessed.', $unassigned, 'blaze-mcp-toolkit-for-woocommerce' ), self::num( $unassigned ) ) );
		}
		unset( $facts );
		return $out;
	}

	// ---------------------------------------------------------------- text.

	/**
	 * Number for prose.
	 *
	 * @param int $n Number.
	 */
	private static function num( int $n ): string {
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $n ) : (string) $n;
	}

	/**
	 * Time for prose: store-local date and time with its offset.
	 *
	 * @param int           $ts UTC timestamp.
	 * @param \DateTimeZone $tz Store timezone.
	 */
	private static function when( int $ts, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'Y-m-d H:i P' );
	}

	/**
	 * Canonical method label: never the merchant-editable gateway title.
	 *
	 * @param string $id Safe method ID.
	 */
	public static function method_label( string $id ): string {
		switch ( $id ) {
			case 'bacs':
				return __( 'Direct bank transfer', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'cheque':
				return __( 'Check payments', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'cod':
				return __( 'Cash on delivery', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'stripe':
				return __( 'Stripe', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		/* translators: %s: payment method ID, for example stripe_klarna. */
		return sprintf( __( 'Payment method (%s)', 'blaze-mcp-toolkit-for-woocommerce' ), $id );
	}

	/**
	 * Label of an evaluation's scope.
	 *
	 * @param array<string,mixed> $e Evaluation.
	 */
	private static function scope_label( array $e ): string {
		if ( $e['aggregate'] ) {
			return __( 'all payment methods that charge at checkout', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return self::method_label( (string) $e['method_id'] );
	}

	/**
	 * A failed-order count in prose ("120 failed orders", "at least 120 failed orders").
	 *
	 * @param int  $n     Count.
	 * @param bool $lower Lower bound.
	 */
	private static function failed_orders( int $n, bool $lower ): string {
		if ( $lower ) {
			/* translators: %s: number of failed orders, a lower bound. */
			return sprintf( _n( 'at least %s failed order', 'at least %s failed orders', $n, 'blaze-mcp-toolkit-for-woocommerce' ), self::num( $n ) );
		}
		/* translators: %s: number of failed orders. */
		return sprintf( _n( '%s failed order', '%s failed orders', $n, 'blaze-mcp-toolkit-for-woocommerce' ), self::num( $n ) );
	}

	/**
	 * Text for a likely or possible evaluation, from its own strongest hour only.
	 *
	 * @param array<string,mixed> $e   Evaluation.
	 * @param array<string,mixed> $ctx Context.
	 */
	private static function positive_text( array $e, array $ctx ): string {
		$tz    = $ctx['tz'];
		$h     = $e['hours'][ (int) $e['lead'] ];
		$count = self::failed_orders( $h['failed'], ! $h['exact'] );
		$from  = self::when( (int) ( $h['first'] ?? $h['start'] ), $tz );
		$to    = self::when( (int) ( $h['last'] ?? $h['end'] ), $tz );
		$where = self::scope_label( $e );
		if ( 'likely_attack' === $e['verdict'] ) {
			$text = sprintf(
				/* translators: 1: failed orders, for example "120 failed orders", 2: start time, 3: end time, 4: where they were paid, 5: orders with no recorded marketing source, 6: orders in the 3 largest recorded networks, 7: orders with a saved network. */
				__( 'This pattern is consistent with likely card testing: %1$s were created between %2$s and %3$s for %4$s. %5$s of them had no recorded marketing source, and the 3 largest recorded networks held %6$s of the %7$s with a saved network. A payment fault is still possible.', 'blaze-mcp-toolkit-for-woocommerce' ),
				$count,
				$from,
				$to,
				$where,
				self::num( (int) $h['attr']['unknown'][0] ),
				self::num( (int) $h['net']['top'] ),
				self::num( (int) $h['net']['valid'] )
			);
		} else {
			$why = true === $h['absolute']
				? __( 'at least 100 in one hour', 'blaze-mcp-toolkit-for-woocommerce' )
				: __( 'far more than this store\'s earlier failed orders would suggest', 'blaze-mcp-toolkit-for-woocommerce' );
			$text = sprintf(
				/* translators: 1: failed orders, 2: start time, 3: end time, 4: where they were paid, 5: why this stands out. */
				__( 'This could be card testing: %1$s were created between %2$s and %3$s for %4$s, %5$s.', 'blaze-mcp-toolkit-for-woocommerce' ),
				$count,
				$from,
				$to,
				$where,
				$why
			);
			if ( $h['share_unk'] ) {
				$text .= ' ' . __( 'Not every order was read, so the share of orders that failed in that hour is unknown.', 'blaze-mcp-toolkit-for-woocommerce' );
			} else {
				$text .= ' ' . sprintf(
					/* translators: 1: failed orders in the hour, 2: accepted and failed orders in the hour. */
					__( '%1$s of the %2$s accepted or failed orders created in that hour failed.', 'blaze-mcp-toolkit-for-woocommerce' ),
					self::num( $h['failed'] ),
					self::num( $h['failed'] + $h['accepted'] )
				);
			}
			if ( 'missing_origin' === $e['origin_scope'] ) {
				$text .= ' ' . __( 'These orders have no record of where they came from, so whether they came through the checkout could not be established.', 'blaze-mcp-toolkit-for-woocommerce' );
			}
			if ( false === $h['net']['signal'] ) {
				$valid = (int) $h['net']['valid'];
				if ( $valid < self::MIN_NETWORK_ORDERS || ! self::share_at_least( $valid, $h['failed'], self::MIN_IP_COMPLETENESS ) ) {
					// Insufficient network evidence (too few orders with a usable saved network), not dispersion.
					$text .= ' ' . sprintf(
						/* translators: 1: failed orders with a usable saved network, 2: failed orders in the hour. */
						__( 'Only %1$s of these %2$s failed orders had a usable saved network, too few to judge whether they came from a few recorded networks.', 'blaze-mcp-toolkit-for-woocommerce' ),
						self::num( $valid ),
						self::num( $h['failed'] )
					);
				} else {
					$text .= ' ' . sprintf(
						/* translators: 1: failed orders in the 3 largest recorded networks, 2: failed orders with a saved network. */
						__( 'The 3 largest recorded networks held %1$s of the %2$s failed orders with a saved network, so these orders were not concentrated in a few recorded networks.', 'blaze-mcp-toolkit-for-woocommerce' ),
						self::num( (int) $h['net']['top'] ),
						self::num( $valid )
					);
				}
			} elseif ( null === $h['net']['signal'] ) {
				$text .= ' ' . __( 'Recorded networks could not be compared for these orders.', 'blaze-mcp-toolkit-for-woocommerce' );
			}
			if ( false === $h['attr']['signal'] ) {
				$text .= ' ' . __( 'The share with no recorded marketing source was not unusual for this store.', 'blaze-mcp-toolkit-for-woocommerce' );
			} elseif ( null === $h['attr']['signal'] ) {
				$text .= ' ' . __( 'Where these orders\' visitors came from could not be compared with earlier orders.', 'blaze-mcp-toolkit-for-woocommerce' );
			}
			$text .= ' ' . __( 'A checkout or payment fault can cause the same pattern.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( 'recent' === $e['activity'] ) {
			$text .= ' ' . sprintf(
				/* translators: %s: time of this check. */
				__( 'Suspicious orders were still being created in the last 15 minutes as of %s.', 'blaze-mcp-toolkit-for-woocommerce' ),
				self::when( $ctx['now'], $tz )
			);
		} elseif ( 'earlier_in_window' === $e['activity'] ) {
			$text .= ' ' . sprintf(
				/* translators: %s: time of this check. */
				__( 'This check did not find the burst still running in the last 15 minutes as of %s.', 'blaze-mcp-toolkit-for-woocommerce' ),
				self::when( $ctx['now'], $tz )
			);
		} elseif ( 'unknown' === $e['activity'] ) {
			$text .= ' ' . __( 'Whether suspicious orders were still being created in the last 15 minutes could not be established, because not every recent order was read.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return $text;
	}

	/**
	 * Unknown-verdict summary.
	 *
	 * @param string|null $code Primary reason.
	 */
	private static function unknown_summary( ?string $code ): string {
		$text = __( 'Card testing could not be fully assessed.', 'blaze-mcp-toolkit-for-woocommerce' );
		$why  = self::reason_text( $code );
		if ( null !== $why ) {
			$text .= ' ' . $why;
		}
		return $text . ' ' . __( 'Check your payment provider\'s fraud or decline dashboard for recent declines.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Owner-facing summary (C5/C8): built only from the returned evaluations.
	 *
	 * @param string                         $verdict       Overall verdict.
	 * @param string                         $activity      Overall activity.
	 * @param array<int,array<string,mixed>> $evals         Sorted evaluations.
	 * @param string|null                    $global_reason No-vote reason.
	 * @param array<string,mixed>            $ctx           Context.
	 */
	private static function summary( string $verdict, string $activity, array $evals, ?string $global_reason, array $ctx ): string {
		unset( $activity );
		if ( in_array( $verdict, array( 'likely_attack', 'possible_attack' ), true ) ) {
			foreach ( $evals as $e ) {
				if ( $e['applicable'] && $e['verdict'] === $verdict && null !== $e['lead'] ) {
					return self::positive_text( $e, $ctx );
				}
			}
		}
		if ( 'no_sign' === $verdict ) {
			return __( 'No sign of a card-testing burst in the order records checked.', 'blaze-mcp-toolkit-for-woocommerce' ) . ' '
				. __( 'This check reads saved orders only. It cannot see payment requests that never saved an order, retries of older orders, or payments tried while paying an existing order or saving a payment method.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		$code = $global_reason;
		foreach ( $evals as $e ) {
			if ( null === $code && $e['applicable'] && 'unknown' === $e['verdict'] ) {
				$code = $e['reason'];
			}
		}
		return self::unknown_summary( $code );
	}

	/**
	 * Plain words for every reason and limitation code these rules can return (C8). Never source
	 * error text.
	 *
	 * @param string|null $code Code.
	 */
	public static function reason_text( ?string $code ): ?string {
		if ( null === $code || '' === $code ) {
			return null;
		}
		switch ( $code ) {
			case 'orders_unavailable':
			case 'unsupported_data_store':
			case 'tables_missing':
			case 'read_failed':
				return __( 'Orders could not be read from the store\'s order records, so card testing could not be checked.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'operational_data_missing':
				return __( 'Some orders are missing WooCommerce\'s order details record, so order activity could not be checked.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'reader_mismatch':
				return __( 'The order counts did not match the time window of this check, so they were not used.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'row_cap':
			case 'order_scan_budget':
				return __( 'This check reached its order reading limit before reading every order in the last 28 days, so counts are lower bounds and no all-clear can be given.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'method_cap':
				return __( 'More payment methods appeared on orders than this check tracks, so some orders were counted but not assessed.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'bucket_cell_cap':
				return __( 'This check reached its limit for order times, so some hours could not be compared.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'network_cap':
			case 'network_bucket_cell_cap':
				return __( 'There were more recorded networks than this check compares at once, so network concentration could not be checked for some hours.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'invalid_timestamps':
				return __( 'Some orders have a future or malformed date and were left out, so counts for their payment method are incomplete.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'draft_paid_anomaly':
				return __( 'Some unfinished checkout orders carry a payment date, which is unexpected, so counts for their payment method are incomplete.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'unsupported_source_version':
				return __( 'This WooCommerce version is outside the range this check supports for saved network and marketing source details (stable releases from 10.9.0 up to, not including, 11.4.0), so those signs were not checked.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'ip_storage_unavailable':
				return __( 'The store does not keep the network details of orders where this check can read them, so network concentration was not checked.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_storage_unavailable':
				return __( 'The store does not keep where orders came from in a place this check can read, so the marketing source sign was not checked.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_feature_disabled':
				return __( 'WooCommerce Order Attribution is switched off, so the marketing source sign was not checked. Missing marketing sources are not treated as suspicious.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_override_present':
				return __( 'A plugin changes how WooCommerce records where orders came from, so the marketing source sign was not checked.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_value_unsupported':
				return __( 'Some orders carry a marketing source value this check does not recognise, so the marketing source sign could not be compared.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_baseline_insufficient':
				return __( 'Too few earlier orders recorded a marketing source to compare with, so missing marketing sources were not used as a sign.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'no_failed_orders':
				return __( 'No failed orders were created in this hour, so there is nothing to compare.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'empty_store':
				return __( 'No orders were created in the last 28 days, so there is nothing to assess.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'no_applicable_scope':
				return __( 'No payment method that charges at checkout had orders that could be assessed.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'method_manual':
				return __( 'A manual payment method (paid outside checkout), so card testing does not apply.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'method_test_mode':
				return __( 'This payment method is in test mode, so its orders are not real customer payments and are not assessed.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'method_kind_unknown':
				return __( 'How this payment method takes payments is not verified, so its failed orders are shown as counts only, without a card-testing verdict.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'test_mode_unknown':
				return __( 'Whether this payment method is in test mode could not be read, so test orders may be included.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'baseline_incomplete':
				return __( 'Not every order for this payment method could be read, so it could not be compared with earlier orders.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'baseline_insufficient_volume':
				return __( 'Fewer than 100 accepted or failed orders were created before this window in the last 28 days, too few to compare with, so only very large bursts can be detected.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'baseline_insufficient_span':
				return __( 'Earlier accepted or failed orders cover less than 7 days, too short to compare with, so only very large bursts can be detected.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'logs_unavailable':
				return __( 'The payment plugin\'s logs could not be fully checked, so no all-clear can be given.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'share_unknown':
				return __( 'Not every order was read, so the share of orders that failed is unknown.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'origin_unverified':
				return __( 'These orders have no record of where they came from, so whether they came through the checkout could not be established.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'order_state_basis':
				return __( 'Counts are orders created in the window and still in that state when checked. Orders that later failed, were paid or were deleted are counted by their state now.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'unsaved_and_retried_orders':
				return __( 'Older orders retried now, payment requests that failed without saving an order, and deleted orders are not measured by these counts.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'cause_unverified':
				return __( 'This check reads order records only. It cannot confirm who or what caused failed orders, and it never checks the payment provider\'s records.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'recorded_network_limits':
				return __( 'Recorded networks group the network details saved on orders. Offices, mobile carriers and proxies put many shoppers in one network, and card testers can spread over many, so networks are only supporting evidence.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'missing_origin_orders':
				return __( 'Some orders have no record of where they came from. They are assessed separately and can at most be a possible sign of card testing.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'other_origin_orders':
				return __( 'Some orders were created another way, for example by an administrator, an import or a subscription renewal. They are counted but not assessed.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'unassigned_method_orders':
				return __( 'Some orders have no payment method recorded. They are counted but not assessed.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'low_history':
				return __( 'At least one payment method has too little earlier order history to compare with, so only bursts of 100 or more failed orders in one hour can be detected for it.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'order_signal_versions':
				return __( 'The saved network and marketing source reads come from WooCommerce 10.9.0 and 11.3.1 source code. They are used for stable WooCommerce releases from 10.9.0 up to, not including, 11.4.0; other releases in that range, including later 11.3 patches, are covered by that compatibility policy, not by a separate source check.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return __( 'This check could not be completed.', 'blaze-mcp-toolkit-for-woocommerce' );
	}
}
