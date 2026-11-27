<?php
/**
 * Pure payment-health rules: time windows, the quiet model, unpaid cohorts and check rollup.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Plain arithmetic over arrays, numbers and an injected DateTimeZone. Never calls WordPress,
 * SQL, the filesystem, HTTP, translation or the clock: the caller captures `now` once and passes
 * it in. Reasons and finding codes are fixed machine codes; the ability turns them into text.
 *
 * Intervals are start-inclusive and end-exclusive. Timestamps are UTC Unix seconds.
 */
final class PaymentRules {

	public const HISTORY_DAYS           = 28;
	public const MIN_HISTORY            = 10;
	public const MIN_OBSERVED_SPAN_DAYS = 7;
	public const SHAPE_PSEUDOCOUNT      = 0.5;
	public const RED_EXPECTED           = 4.6;
	public const AMBER_EXPECTED         = 3.0;

	/** Every considered unpaid attempt is at least this old. */
	public const MATURITY_SECONDS = 3600;

	/** Prior-cohort taken orders needed before an unpaid-share verdict. */
	public const MIN_BASELINE_TAKEN = 10;

	/** Stuck orders needed before any unpaid-share alert. */
	public const MIN_STUCK = 3;

	/** Seconds in one order bucket: every current time-zone offset is a multiple of 15 minutes. */
	public const BUCKET_SECONDS = 900;

	/** History length in hours (28 days). */
	public const HISTORY_HOURS = 672;

	/** The 4 check statuses, most severe first. */
	public const STATUSES = array( 'red', 'amber', 'unknown', 'green' );

	/**
	 * The single complete allowlist of reason, note and category codes that the payment-health
	 * diagnostics (PaymentRules, PaymentOrders, PaymentMethods, PaymentErrors and
	 * LogSources::source_files()) can emit. PaymentRulesTest scans those sources and fails on any
	 * emitted code missing here. Finding codes are separate (PaymentHealth::FINDING_CODES).
	 */
	public const REASONS = array(
		// Quiet model (PaymentRules).
		'insufficient_history',
		'insufficient_span',
		'invalid_exposure',
		'invalid_timestamps',
		'store_profile_unavailable',
		'method_disabled',
		'method_not_registered',
		'enabled_state_unknown',
		// Unpaid cohorts (PaymentRules).
		'cohort_empty',
		'no_attempts',
		'insufficient_baseline',
		'manual_method',
		'kind_unknown',
		'unpaid_share_high',
		'transaction_recorded_unpaid',
		// Order reads (PaymentOrders, PaymentRules).
		'orders_unavailable',
		'orders_partial',
		'unsupported_data_store',
		'tables_missing',
		'read_failed',
		'operational_data_missing',
		'row_cap',
		'order_scan_budget',
		// Inventory (PaymentMethods), a note only.
		'registry_not_loaded',
		// Outside-checkout observations (1.2.1, C3/C4/C7): inventory-only disclosure and its limits.
		'outside_checkout_only',
		'outside_checkout_observed_partial',
		'outside_checkout_method_cap',
		// Diagnostics access and mapping (PaymentErrors).
		'diagnostics_not_permitted',
		'no_verified_mapping',
		'source_read_failed',
		'jobs_unavailable',
		'sampled_store',
		// Log reads (PaymentErrors, LogSources::source_files()).
		'logging_unavailable',
		'logging_disabled',
		'unsupported_log_handler',
		'logging_threshold_above_error',
		'stale_retention',
		'log_directory_unavailable',
		'no_matching_log_source',
		'file_unreadable',
		'file_cap',
		'byte_cap',
		'log_row_cap',
		'unparsed_entries',
		'shared_log_source',
		// Log and job categories (PaymentErrors::LOG_CATEGORIES, ::JOB_CATEGORIES).
		'gateway_error',
		'gateway_fatal',
		'webhook_signature_failed',
		'gateway_job_failed',
		// Webhook (PaymentErrors::WEBHOOK_CATEGORIES and the unsupported-adapter reason).
		'no_supported_webhook_record',
		'delivery_ok',
		'delivery_failed',
		'order_unclear',
		'record_malformed',
		'record_missing',
		'no_events_recorded',
		'no_success_recorded',
		'success_stale',
		'webhook_stale_unverified',
		'mode_unknown',
	);

	/** Valid gateway ID grammar (bounded plugin-ID characters). */
	private const METHOD_ID = '/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/';

	/**
	 * First second of the 28-day history.
	 *
	 * @param int $now Captured UTC now.
	 */
	public static function history_start( int $now ): int {
		return $now - self::HISTORY_DAYS * 86400;
	}

	/**
	 * Creation cohorts for the unpaid check (C6).
	 *
	 * @param int $now         Captured UTC now.
	 * @param int $since_hours Rolling window, 1-168.
	 * @return array{window_start:int,mature_before:int,baseline_start:int,baseline_end:int,failed_start:int}
	 */
	public static function cohorts( int $now, int $since_hours ): array {
		$since_hours = max( 1, min( 168, $since_hours ) );
		$window      = $now - $since_hours * 3600;
		return array(
			'window_start'   => $window,
			'mature_before'  => max( $window, $now - self::MATURITY_SECONDS ),
			'baseline_start' => self::history_start( $now ),
			'baseline_end'   => $window,
			'failed_start'   => $window,
		);
	}

	/**
	 * ISO-8601 with the store's actual offset at that instant.
	 *
	 * @param int           $ts UTC timestamp.
	 * @param \DateTimeZone $tz Store timezone.
	 */
	public static function iso( int $ts, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'c' );
	}

	/**
	 * A stable public reference for a raw gateway ID. Valid IDs pass through; anything else
	 * becomes an opaque reference that never copies the raw content. Empty stays empty.
	 *
	 * @param string $raw Raw payment_method value.
	 */
	public static function safe_method_id( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		if ( 1 === preg_match( self::METHOD_ID, $raw ) ) {
			return $raw;
		}
		return 'unrecognised-' . substr( hash( 'sha256', $raw ), 0, 12 );
	}

	/**
	 * Build an event series from raw UTC timestamps (tests and small inputs).
	 *
	 * @param int[] $timestamps Event times.
	 * @return array{buckets:array<int,int>,count:int,first:?int,last:?int}
	 */
	public static function series( array $timestamps ): array {
		$series = self::empty_series();
		foreach ( $timestamps as $ts ) {
			self::add_event( $series, (int) $ts );
		}
		return $series;
	}

	/**
	 * An empty event series.
	 *
	 * @return array{buckets:array<int,int>,count:int,first:?int,last:?int}
	 */
	public static function empty_series(): array {
		return array(
			'buckets' => array(),
			'count'   => 0,
			'first'   => null,
			'last'    => null,
		);
	}

	/**
	 * Add one event to a series.
	 *
	 * @param array{buckets:array<int,int>,count:int,first:?int,last:?int} $series Series (by reference).
	 * @param int                                                          $ts     Event time.
	 */
	public static function add_event( array &$series, int $ts ): void {
		$idx                       = intdiv( $ts, self::BUCKET_SECONDS );
		$series['buckets'][ $idx ] = ( $series['buckets'][ $idx ] ?? 0 ) + 1;
		++$series['count'];
		$series['first'] = null === $series['first'] ? $ts : min( $series['first'], $ts );
		$series['last']  = null === $series['last'] ? $ts : max( $series['last'], $ts );
	}

	/**
	 * Local (day-of-week, hour) for a UTC instant. Sunday is 0.
	 *
	 * @param int $ts     UTC timestamp.
	 * @param int $offset Local offset in seconds at that instant.
	 * @return array{0:int,1:int}
	 */
	private static function local_cell( int $ts, int $offset ): array {
		$local = $ts + $offset;
		$day   = intdiv( $local - self::mod( $local, 86400 ), 86400 );
		$dow   = self::mod( $day + 4, 7 ); // 1970-01-01 was a Thursday.
		$hour  = intdiv( self::mod( $local, 86400 ), 3600 );
		return array( $dow, $hour );
	}

	/**
	 * Non-negative modulo.
	 *
	 * @param int $a Dividend.
	 * @param int $b Divisor.
	 */
	private static function mod( int $a, int $b ): int {
		$r = $a % $b;
		return $r < 0 ? $r + $b : $r;
	}

	/**
	 * Constant-offset spans of [a, b): each is [start, end, offset].
	 *
	 * @param int           $a  Start.
	 * @param int           $b  End (exclusive).
	 * @param \DateTimeZone $tz Store timezone.
	 * @return array<int,array{0:int,1:int,2:int}>
	 */
	private static function offset_spans( int $a, int $b, \DateTimeZone $tz ): array {
		if ( $b <= $a ) {
			return array();
		}
		$offset = $tz->getOffset( new \DateTimeImmutable( '@' . $a ) );
		$trans  = $tz->getTransitions( $a, $b );
		$spans  = array();
		$cursor = $a;
		if ( is_array( $trans ) ) {
			foreach ( $trans as $t ) {
				$at = (int) $t['ts'];
				if ( $at <= $cursor ) {
					$offset = (int) $t['offset'];
					continue;
				}
				if ( $at >= $b ) {
					break;
				}
				$spans[] = array( $cursor, $at, $offset );
				$cursor  = $at;
				$offset  = (int) $t['offset'];
			}
		}
		$spans[] = array( $cursor, $b, $offset );
		return $spans;
	}

	/**
	 * Real elapsed hours of [a, b) in each local (day-of-week, hour) cell, with true exposure for
	 * repeated or skipped local hours across timezone transitions.
	 *
	 * @param int           $a  Start.
	 * @param int           $b  End (exclusive).
	 * @param \DateTimeZone $tz Store timezone.
	 * @return array<int,array<int,float>> [dow][hour] => hours.
	 */
	public static function exposure_cells( int $a, int $b, \DateTimeZone $tz ): array {
		$cells = array_fill( 0, 7, array_fill( 0, 24, 0.0 ) );
		foreach ( self::offset_spans( $a, $b, $tz ) as $span ) {
			list( $t, $end, $offset ) = $span;
			while ( $t < $end ) {
				$next = $t + ( 3600 - self::mod( $t + $offset, 3600 ) );
				$stop = min( $next, $end );
				list( $dow, $hour ) = self::local_cell( $t, $offset );
				$cells[ $dow ][ $hour ] += ( $stop - $t ) / 3600;
				$t                       = $stop;
			}
		}
		return $cells;
	}

	/**
	 * Fit the shared hour-of-day and day-of-week profiles from store-wide accepted orders (C5).
	 *
	 * Training is from history start through the last store-wide accepted event, so the current
	 * silence is excluded. Each bucket rate is (n + 0.5) / (e + 0.5 / r) with r = N / E, then each
	 * profile is normalised to an exposure-weighted mean of 1.
	 *
	 * @param array{buckets:array<int,int>,count:int,first:?int,last:?int} $series Store-wide events.
	 * @param int                                                          $now    Captured now.
	 * @param \DateTimeZone                                                $tz     Store timezone.
	 * @return array{ok:bool,reason:?string,hour:array<int,float>,dow:array<int,float>,start:int,end:?int}
	 */
	public static function fit_profile( array $series, int $now, \DateTimeZone $tz ): array {
		$start = self::history_start( $now );
		$fail  = static fn( string $reason ): array => array(
			'ok'     => false,
			'reason' => $reason,
			'hour'   => array(),
			'dow'    => array(),
			'start'  => $start,
			'end'    => $series['last'],
		);
		$guard = self::evidence_guard( $series, $start, $now );
		if ( null !== $guard ) {
			return $fail( $guard );
		}
		$end   = (int) $series['last'];
		$cells = self::exposure_cells( $start, $end, $tz );
		$e_h   = array_fill( 0, 24, 0.0 );
		$e_d   = array_fill( 0, 7, 0.0 );
		foreach ( $cells as $d => $row ) {
			foreach ( $row as $h => $hours ) {
				$e_h[ $h ] += $hours;
				$e_d[ $d ] += $hours;
			}
		}
		$exposure = array_sum( $e_h );
		if ( $exposure <= 0.0 ) {
			return $fail( 'invalid_exposure' );
		}
		foreach ( array_merge( $e_h, $e_d ) as $e ) {
			if ( $e <= 0.0 ) {
				return $fail( 'invalid_exposure' );
			}
		}

		$n_h = array_fill( 0, 24, 0 );
		$n_d = array_fill( 0, 7, 0 );
		$n   = 0;
		foreach ( $series['buckets'] as $idx => $count ) {
			$ts = (int) $idx * self::BUCKET_SECONDS;
			if ( $ts < intdiv( $start, self::BUCKET_SECONDS ) * self::BUCKET_SECONDS || $ts > $end ) {
				continue;
			}
			list( $d, $h ) = self::local_cell( $ts, $tz->getOffset( new \DateTimeImmutable( '@' . $ts ) ) );
			$n_h[ $h ]    += $count;
			$n_d[ $d ]    += $count;
			$n            += $count;
		}
		if ( $n <= 0 ) {
			return $fail( 'insufficient_history' );
		}
		$r = $n / $exposure;

		return array(
			'ok'     => true,
			'reason' => null,
			'hour'   => self::normalise( $n_h, $e_h, $r ),
			'dow'    => self::normalise( $n_d, $e_d, $r ),
			'start'  => $start,
			'end'    => $end,
		);
	}

	/**
	 * Smoothed, exposure-weighted-mean-1 profile.
	 *
	 * @param array<int,int>   $n Counts per bucket.
	 * @param array<int,float> $e Exposure hours per bucket (all positive).
	 * @param float            $r Global rate per hour.
	 * @return array<int,float>
	 */
	private static function normalise( array $n, array $e, float $r ): array {
		$rates = array();
		foreach ( $n as $b => $count ) {
			$rates[ $b ] = ( $count + self::SHAPE_PSEUDOCOUNT ) / ( $e[ $b ] + self::SHAPE_PSEUDOCOUNT / $r );
		}
		$weighted = 0.0;
		foreach ( $rates as $b => $rate ) {
			$weighted += $rate * $e[ $b ];
		}
		$mean = $weighted / array_sum( $e );
		return array_map( static fn( float $rate ): float => $rate / $mean, $rates );
	}

	/**
	 * Weighted exposure E(a, b) = integral of hour[h(t)] * dow[d(t)] over real elapsed hours.
	 *
	 * @param array{hour:array<int,float>,dow:array<int,float>} $profile Fitted profile.
	 * @param int                                                $a       Start.
	 * @param int                                                $b       End (exclusive).
	 * @param \DateTimeZone                                      $tz      Store timezone.
	 */
	public static function weighted_exposure( array $profile, int $a, int $b, \DateTimeZone $tz ): float {
		$total = 0.0;
		foreach ( self::exposure_cells( $a, $b, $tz ) as $d => $row ) {
			foreach ( $row as $h => $hours ) {
				if ( $hours > 0.0 ) {
					$total += $hours * $profile['hour'][ $h ] * $profile['dow'][ $d ];
				}
			}
		}
		return $total;
	}

	/**
	 * Null when the series supports a fit, else the reason code.
	 *
	 * @param array{buckets:array<int,int>,count:int,first:?int,last:?int} $series Events.
	 * @param int                                                          $start  History start.
	 * @param int                                                          $now    Now.
	 */
	private static function evidence_guard( array $series, int $start, int $now ): ?string {
		if ( $series['count'] < self::MIN_HISTORY || null === $series['first'] || null === $series['last'] ) {
			return 'insufficient_history';
		}
		if ( $series['first'] < $start || $series['last'] >= $now ) {
			return 'invalid_timestamps';
		}
		if ( $series['last'] - $series['first'] < self::MIN_OBSERVED_SPAN_DAYS * 86400 ) {
			return 'insufficient_span';
		}
		return null;
	}

	/**
	 * Unrounded expected accepted orders in the current gap for one series, or a reason code.
	 *
	 * @param array{buckets:array<int,int>,count:int,first:?int,last:?int} $series  Method (or store) events.
	 * @param array<string,mixed>                                          $profile fit_profile() result.
	 * @param int                                                          $now     Captured now.
	 * @param \DateTimeZone                                                $tz      Store timezone.
	 * @return array{expected:?float,rate:?float,reason:?string}
	 */
	public static function expected_in_gap( array $series, array $profile, int $now, \DateTimeZone $tz ): array {
		$start = self::history_start( $now );
		$guard = self::evidence_guard( $series, $start, $now );
		if ( null === $guard && empty( $profile['ok'] ) ) {
			$guard = 'store_profile_unavailable';
		}
		if ( null !== $guard ) {
			return array(
				'expected' => null,
				'rate'     => null,
				'reason'   => $guard,
			);
		}
		$train = self::weighted_exposure( $profile, $start, (int) $series['last'], $tz );
		if ( ! is_finite( $train ) || $train <= 0.0 ) {
			return array(
				'expected' => null,
				'rate'     => null,
				'reason'   => 'invalid_exposure',
			);
		}
		$rate = $series['count'] / $train;
		return array(
			'expected' => $rate * self::weighted_exposure( $profile, (int) $series['last'], $now, $tz ),
			'rate'     => $rate,
			'reason'   => null,
		);
	}

	/**
	 * Quiet classification of an unrounded expected count.
	 *
	 * @param float $expected Expected orders in the gap.
	 * @param bool  $complete Whether every qualifying input was read completely.
	 */
	public static function classify_expected( float $expected, bool $complete ): string {
		if ( $expected >= self::RED_EXPECTED ) {
			return 'red';
		}
		if ( $expected >= self::AMBER_EXPECTED ) {
			return 'amber';
		}
		return $complete ? 'green' : 'unknown';
	}

	/**
	 * Full quiet check (C4, C5, C7).
	 *
	 * @param array{buckets:array<int,int>,count:int,first:?int,last:?int}|null $series   Events, null when orders could not be read.
	 * @param array<string,mixed>                                               $profile  fit_profile() result.
	 * @param int                                                               $now      Captured now.
	 * @param \DateTimeZone                                                     $tz       Store timezone.
	 * @param bool|null                                                         $enabled  Method enabled state; true for the store-wide check.
	 * @param bool                                                              $complete Order reads complete (false when partial).
	 * @param bool|null                                                         $registered Whether the method is currently registered; null when the registry was not built.
	 * @return array{check:array<string,mixed>,expected_raw:?float}
	 */
	public static function quiet( ?array $series, array $profile, int $now, \DateTimeZone $tz, ?bool $enabled = true, bool $complete = true, ?bool $registered = true ): array {
		$check = array(
			'status'                 => 'unknown',
			'checked'                => null !== $series,
			'applicable'             => true,
			'coverage'               => null === $series ? 'unavailable' : ( $complete ? 'complete' : 'partial' ),
			'reason'                 => null,
			'taken_28d'              => null,
			'last_taken_at'          => null,
			'hours_since_last_taken' => null,
			'typical_gap_hours'      => null,
			'expected_in_gap'        => null,
		);
		if ( null === $series ) {
			$check['reason'] = 'orders_unavailable';
			return array(
				'check'        => $check,
				'expected_raw' => null,
			);
		}
		$check['taken_28d'] = $series['count'];
		if ( null !== $series['last'] ) {
			$check['last_taken_at']          = self::iso( (int) $series['last'], $tz );
			$check['hours_since_last_taken'] = round( ( $now - (int) $series['last'] ) / 3600, 1 );
		}
		if ( $series['count'] > 0 ) {
			$check['typical_gap_hours'] = round( self::HISTORY_HOURS / $series['count'], 1 );
		}

		// Registered null means the registry was not built in this request: unknown, not absent,
		// so quiet runs from the enabled setting alone.
		if ( false === $registered || false === $enabled ) {
			$check['applicable'] = false;
			$check['coverage']   = 'not_applicable';
			$check['reason']     = false === $registered ? 'method_not_registered' : 'method_disabled';
			return array(
				'check'        => $check,
				'expected_raw' => null,
			);
		}
		if ( null === $enabled ) {
			$check['coverage'] = 'partial';
			$check['reason']   = 'enabled_state_unknown';
			return array(
				'check'        => $check,
				'expected_raw' => null,
			);
		}

		$eval = self::expected_in_gap( $series, $profile, $now, $tz );
		if ( null === $eval['expected'] ) {
			$check['reason'] = $eval['reason'];
			if ( 'complete' === $check['coverage'] ) {
				$check['coverage'] = 'partial';
			}
			return array(
				'check'        => $check,
				'expected_raw' => null,
			);
		}
		$check['status']          = self::classify_expected( $eval['expected'], $complete );
		$check['expected_in_gap'] = round( $eval['expected'], 1 );
		if ( ! $complete ) {
			$check['reason'] = 'orders_partial';
		}
		return array(
			'check'        => $check,
			'expected_raw' => $eval['expected'],
		);
	}

	/**
	 * Unpaid-share check for one method (C6).
	 *
	 * Counts arrays hold pending, cancelled_unpaid, taken (and for current also
	 * transaction_recorded_unpaid and failed); any value may be null when unread.
	 *
	 * @param array<string,?int> $current      Current mature cohort counts.
	 * @param array<string,?int> $baseline     Prior cohort counts.
	 * @param string             $kind         instant|manual|unknown.
	 * @param bool               $complete     Order reads complete.
	 * @param bool               $cohort_empty True when the mature cohort interval is empty (since_hours=1).
	 * @param bool               $reference_implies_charge PaymentMethods row fact: a transaction
	 *                           reference on an unpaid order implies a charge. Only then does
	 *                           transaction_recorded_unpaid raise amber; otherwise the count is
	 *                           context only. Default false: no unverified severity.
	 * @return array<string,mixed> Check + counts + reasons (codes).
	 */
	public static function stuck( array $current, array $baseline, string $kind, bool $complete = true, bool $cohort_empty = false, bool $reference_implies_charge = false ): array {
		$int = static fn( $v ): ?int => is_int( $v ) ? $v : null;

		$pending   = $int( $current['pending'] ?? null );
		$cancelled = $int( $current['cancelled_unpaid'] ?? null );
		$taken     = $int( $current['taken'] ?? null );
		$txn       = $int( $current['transaction_recorded_unpaid'] ?? null );
		$failed    = $int( $current['failed'] ?? null );
		$b_pending = $int( $baseline['pending'] ?? null );
		$b_cancel  = $int( $baseline['cancelled_unpaid'] ?? null );
		$b_taken   = $int( $baseline['taken'] ?? null );

		$read  = null !== $pending && null !== $cancelled && null !== $taken;
		$stuck = $read ? $pending + $cancelled : null;
		$tries = $read ? $stuck + $taken : null;
		$b_read  = null !== $b_pending && null !== $b_cancel && null !== $b_taken;
		$b_stuck = $b_read ? $b_pending + $b_cancel : null;
		$b_tries = $b_read ? $b_stuck + $b_taken : null;

		$out = array(
			'status'                      => 'unknown',
			'checked'                     => $read || $b_read,
			'applicable'                  => true,
			'coverage'                    => $read && $b_read ? ( $complete ? 'complete' : 'partial' ) : ( $read || $b_read ? 'partial' : 'unavailable' ),
			'reason'                      => null,
			'pending'                     => $pending,
			'cancelled_unpaid'            => $cancelled,
			'transaction_recorded_unpaid' => $txn,
			'taken'                       => $taken,
			'failed'                      => $failed,
			'stuck_share'                 => null !== $tries && $tries > 0 ? round( $stuck / $tries, 4 ) : null,
			'baseline_stuck_share'        => null !== $b_tries && $b_tries > 0 ? round( $b_stuck / $b_tries, 4 ) : null,
			'baseline_taken'              => $b_taken,
			'reasons'                     => array(),
		);

		if ( 'manual' === $kind ) {
			$out['applicable'] = false;
			$out['coverage']   = 'not_applicable';
			$out['reason']     = 'manual_method';
			return $out;
		}
		if ( 'instant' !== $kind ) {
			// Ruling Q7 (pass 4): an unknown kind is applicable but has no unpaid verdict until a
			// verified adapter or the kind filter establishes instant settlement. A baseline cannot
			// absorb normal settlement delay (a 48-hour settlement turns a healthy cohort red), so the
			// counts stay as context only, with a fixed reason and no share or reference severity.
			$out['reason']   = 'kind_unknown';
			$out['reasons']  = array( 'kind_unknown' );
			$out['coverage'] = 'unavailable';
			return $out;
		}
		if ( $cohort_empty ) {
			$out['reason'] = 'cohort_empty';
			if ( 'complete' === $out['coverage'] ) {
				$out['coverage'] = 'partial';
			}
			return $out;
		}

		$severity = 'green';
		$reason   = null;
		if ( $reference_implies_charge && null !== $txn && $txn > 0 ) {
			$severity          = 'amber';
			$out['reasons'][]  = 'transaction_recorded_unpaid';
			$reason            = 'transaction_recorded_unpaid';
		}

		$share = null;
		if ( ! $read ) {
			$share = 'orders_unavailable';
		} elseif ( ! $b_read || $b_taken < self::MIN_BASELINE_TAKEN || $b_tries <= 0 ) {
			$share = 'insufficient_baseline';
		} elseif ( $tries <= 0 ) {
			$share = 'no_attempts';
		} elseif ( $stuck >= self::MIN_STUCK && self::share_at_least( $stuck, $tries, 30, 3, $b_stuck, $b_tries ) ) {
			$severity          = 'red';
			$out['reasons'][]  = 'unpaid_share_high';
			$reason            = 'unpaid_share_high';
		} elseif ( $stuck >= self::MIN_STUCK && self::share_at_least( $stuck, $tries, 20, 2, $b_stuck, $b_tries ) ) {
			if ( 'red' !== $severity ) {
				$severity = 'amber';
			}
			$out['reasons'][] = 'unpaid_share_high';
			$reason           = $reason ?? 'unpaid_share_high';
		}

		if ( null !== $share ) {
			// No share verdict: amber evidence still stands; otherwise unknown.
			$out['status'] = 'amber' === $severity ? 'amber' : 'unknown';
			$out['reason'] = $reason ?? $share;
			if ( 'complete' === $out['coverage'] ) {
				$out['coverage'] = 'partial';
			}
			return $out;
		}
		if ( 'green' === $severity && ( ! $complete || 'complete' !== $out['coverage'] ) ) {
			$out['status'] = 'unknown';
			$out['reason'] = 'orders_partial';
			return $out;
		}
		$out['status'] = $severity;
		$out['reason'] = $reason;
		return $out;
	}

	/**
	 * Exact rational test: stuck/tries >= max(pct/100, mult * b_stuck/b_tries), with no float
	 * rounding at the boundaries.
	 *
	 * @param int $stuck   Stuck.
	 * @param int $tries   Attempts (> 0).
	 * @param int $pct     Floor share in percent.
	 * @param int $mult    Baseline multiple.
	 * @param int $b_stuck Baseline stuck.
	 * @param int $b_tries Baseline attempts (> 0).
	 */
	private static function share_at_least( int $stuck, int $tries, int $pct, int $mult, int $b_stuck, int $b_tries ): bool {
		return 100 * $stuck >= $pct * $tries && $stuck * $b_tries >= $mult * $b_stuck * $tries;
	}

	/**
	 * Hold-stock configuration (C6), from raw option values (null = option absent).
	 *
	 * @param mixed $minutes_raw woocommerce_hold_stock_minutes, null when absent.
	 * @param mixed $manage_raw  woocommerce_manage_stock, null when absent.
	 * @return array{hold_stock_minutes:?int,hold_stock_configured:bool}
	 */
	public static function hold_stock( $minutes_raw, $manage_raw ): array {
		if ( null === $minutes_raw ) {
			$minutes_raw = '60';
		}
		$minutes = null;
		if ( is_int( $minutes_raw ) || ( is_string( $minutes_raw ) && 1 === preg_match( '/^\s*\d+\s*$/', $minutes_raw ) ) ) {
			$minutes = (int) $minutes_raw;
		}
		if ( null === $minutes || $minutes < 1 ) {
			return array(
				'hold_stock_minutes'    => null,
				'hold_stock_configured' => false,
			);
		}
		return array(
			'hold_stock_minutes'    => $minutes,
			'hold_stock_configured' => 'yes' === $manage_raw,
		);
	}

	/**
	 * Legacy paid timestamp from the first-row `_date_paid` and `_paid_date` values (pinned item 1
	 * precedence): `_date_paid` (UTC seconds) wins when truthy; otherwise `_paid_date` (site-local
	 * `Y-m-d H:i:s`, converted with the offset in force at that local time).
	 *
	 * @param string|null   $date_paid First `_date_paid` row, null when absent.
	 * @param string|null   $paid_date First `_paid_date` row, null when absent.
	 * @param \DateTimeZone $tz        Store timezone.
	 * @return array{paid:?int,valid:bool} valid false means a malformed value was present.
	 */
	public static function legacy_paid( ?string $date_paid, ?string $paid_date, \DateTimeZone $tz ): array {
		if ( null !== $date_paid && '' !== $date_paid && '0' !== $date_paid ) {
			if ( 1 === preg_match( '/^\d{1,12}$/', $date_paid ) ) {
				return array(
					'paid'  => (int) $date_paid,
					'valid' => true,
				);
			}
			return array(
				'paid'  => null,
				'valid' => false,
			);
		}
		if ( null === $paid_date || '' === $paid_date || '0' === $paid_date ) {
			return array(
				'paid'  => null,
				'valid' => true,
			);
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $paid_date, $tz );
		if ( false === $dt || $dt->format( 'Y-m-d H:i:s' ) !== $paid_date ) {
			return array(
				'paid'  => null,
				'valid' => false,
			);
		}
		return array(
			'paid'  => $dt->getTimestamp(),
			'valid' => true,
		);
	}

	/**
	 * UTC timestamp from a UTC `Y-m-d H:i:s` datetime column (HPOS dates, post_date_gmt).
	 *
	 * @param string|null $value Column value; null or a zero date means unset.
	 * @return array{ts:?int,valid:bool}
	 */
	public static function utc_datetime( ?string $value ): array {
		if ( null === $value || '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return array(
				'ts'    => null,
				'valid' => true,
			);
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $dt || $dt->format( 'Y-m-d H:i:s' ) !== $value ) {
			return array(
				'ts'    => null,
				'valid' => false,
			);
		}
		return array(
			'ts'    => $dt->getTimestamp(),
			'valid' => true,
		);
	}

	/**
	 * Roll checks up (C7): red, then amber, then unknown, then green. Inapplicable checks are
	 * omitted; applicable checks without complete coverage give unknown unless they already show
	 * amber or red; no applicable checked evidence gives unknown.
	 *
	 * @param array<int,array<string,mixed>> $checks       Checks with status/applicable/coverage/checked.
	 * @param string[]                       $severities   Extra finding severities (red|amber|info).
	 */
	public static function rollup( array $checks, array $severities = array() ): string {
		$seen = array();
		foreach ( $checks as $check ) {
			if ( empty( $check['applicable'] ) ) {
				continue;
			}
			$status = in_array( $check['status'] ?? null, self::STATUSES, true ) ? $check['status'] : 'unknown';
			if ( 'green' === $status && ( 'complete' !== ( $check['coverage'] ?? null ) || empty( $check['checked'] ) ) ) {
				$status = 'unknown';
			}
			$seen[] = $status;
		}
		foreach ( $severities as $severity ) {
			if ( 'red' === $severity || 'amber' === $severity ) {
				$seen[] = $severity;
			}
		}
		foreach ( array( 'red', 'amber', 'unknown', 'green' ) as $status ) {
			if ( in_array( $status, $seen, true ) ) {
				return $status;
			}
		}
		return 'unknown';
	}

	/**
	 * The one check shape for a method assessed as inventory_only (C7): never evaluated, so not
	 * applicable, not checked, unknown, with the disclosure reason. It casts no vote in rollup()
	 * and no coverage in coverage(); no rule is called to produce it.
	 *
	 * @param bool $complete Whether the relevant order scan was complete (outside_checkout_only)
	 *                       or not (outside_checkout_observed_partial).
	 * @return array{status:string,checked:bool,applicable:bool,coverage:string,reason:string}
	 */
	public static function unassessed_check( bool $complete ): array {
		return array(
			'status'     => 'unknown',
			'checked'    => false,
			'applicable' => false,
			'coverage'   => 'not_applicable',
			'reason'     => $complete ? 'outside_checkout_only' : 'outside_checkout_observed_partial',
		);
	}

	/**
	 * Combined coverage of applicable checks: complete, partial or unavailable.
	 *
	 * @param array<int,array<string,mixed>> $checks Checks.
	 */
	public static function coverage( array $checks ): string {
		$any      = false;
		$complete = true;
		foreach ( $checks as $check ) {
			if ( empty( $check['applicable'] ) ) {
				continue;
			}
			if ( ! empty( $check['checked'] ) ) {
				$any = true;
			}
			if ( 'complete' !== ( $check['coverage'] ?? null ) ) {
				$complete = false;
			}
		}
		if ( ! $any ) {
			return 'unavailable';
		}
		return $complete ? 'complete' : 'partial';
	}

	/**
	 * Findings sorted red, amber, info; store-wide (null method) before method; then ID and code.
	 * Returns the first $cap plus the total and a truncation flag.
	 *
	 * @param array<int,array{severity:string,method_id:?string,code:string}> $findings Findings.
	 * @param int                                                              $cap      Display cap.
	 * @return array{findings:array<int,array<string,mixed>>,findings_total:int,findings_truncated:bool}
	 */
	public static function cap_findings( array $findings, int $cap = 10 ): array {
		$rank = array(
			'red'   => 0,
			'amber' => 1,
			'info'  => 2,
		);
		usort(
			$findings,
			static fn( array $a, array $b ): int => array( $rank[ $a['severity'] ] ?? 3, null === $a['method_id'] ? 0 : 1, (string) $a['method_id'], $a['code'] )
				<=> array( $rank[ $b['severity'] ] ?? 3, null === $b['method_id'] ? 0 : 1, (string) $b['method_id'], $b['code'] )
		);
		$total = count( $findings );
		return array(
			'findings'           => array_slice( $findings, 0, $cap ),
			'findings_total'     => $total,
			'findings_truncated' => $total > $cap,
		);
	}

	/**
	 * Example candidates ordered: transaction mismatch first, then oldest created, then ID.
	 *
	 * @param array<int,array{id:int,created:int,txn:bool}> $candidates Candidates.
	 * @param int                                           $cap        Cap (5).
	 * @return array<int,array{id:int,created:int,txn:bool}>
	 */
	public static function order_examples( array $candidates, int $cap = 5 ): array {
		usort(
			$candidates,
			static fn( array $a, array $b ): int => array( $a['txn'] ? 0 : 1, $a['created'], $a['id'] ) <=> array( $b['txn'] ? 0 : 1, $b['created'], $b['id'] )
		);
		return array_slice( $candidates, 0, $cap );
	}

	/**
	 * Method-level inventory findings (C3): disabled with history, not currently registered, test
	 * mode. Codes only; the ability formats text.
	 *
	 * @param array<string,mixed> $method       Inventory row (registered, enabled, test_mode, replacements).
	 * @param bool                $has_history  Eligible activity in the 28-day horizon.
	 * @param bool                $registry_known Whether the gateway registry was readable.
	 * @param string              $store_quiet  Store-wide quiet status.
	 * @param array<string,array{enabled:?bool,quiet:string,recent_taken:int}> $siblings Replacement IDs => facts.
	 * @return array<int,array{severity:string,code:string}>
	 */
	public static function inventory_findings( array $method, bool $has_history, bool $registry_known, string $store_quiet, array $siblings = array() ): array {
		$out = array();
		$registered = $method['registered'] ?? null;
		if ( $registry_known && true !== $registered && $has_history ) {
			// Only a built registry that lacks the ID proves the plugin is not currently registered.
			$out[] = array(
				'severity' => 'amber',
				'code'     => 'method_not_registered',
			);
		} elseif ( ( true === $registered || ( null === $registered && ! $registry_known ) ) && false === ( $method['enabled'] ?? null ) && $has_history ) {
			$replaced = false;
			if ( 'green' === $store_quiet ) {
				foreach ( (array) ( $method['replacements'] ?? array() ) as $id ) {
					$s = $siblings[ $id ] ?? null;
					if ( is_array( $s ) && true === $s['enabled'] && 'green' === $s['quiet'] && $s['recent_taken'] > 0 ) {
						$replaced = true;
						break;
					}
				}
			}
			$out[] = $replaced
				? array(
					'severity' => 'info',
					'code'     => 'method_replaced',
				)
				: array(
					'severity' => 'amber',
					'code'     => 'method_disabled_with_history',
				);
		}
		if ( true === ( $method['enabled'] ?? null ) && true === ( $method['test_mode'] ?? null ) ) {
			$out[] = array(
				'severity' => 'amber',
				'code'     => 'test_mode_enabled',
			);
		}
		return $out;
	}
}
