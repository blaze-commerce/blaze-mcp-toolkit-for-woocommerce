<?php
/**
 * Bounded local payment diagnostics: gateway log errors, failed gateway jobs and webhook records.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

use Blaze\McpToolkit\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * FEATURE-PLAN item 5, contract C8. Emits counts and fixed categories only: never log text,
 * redacted excerpts, source filenames, hook names, job arguments, log context or the gateway's
 * last-error value. Logs and jobs need Settings::can_view_diagnostics(), checked before any read.
 * Webhook status uses only the verified Stripe scalar timestamp options (item 1) and is visible
 * at the entry permission. No HTTP, no cron or Action Scheduler work, no repair.
 */
final class PaymentErrors {

	/** Shared budget for one call (C8). */
	public const MAX_FILES     = 7;
	public const MAX_BYTES     = 5242880;
	public const MAX_DB_ROWS   = 2000;
	public const MAX_DB_MSG    = 2048;
	public const RED_LOG_COUNT = 20;

	/** Display caps (C8). */
	public const MAX_GROUPS_METHOD = 20;
	public const MAX_GROUPS_TOTAL  = 50;
	public const MAX_HOOKS_TOTAL   = 50;

	/** Webhook windows (C8). */
	public const RED_FAILURE_WINDOW = 259200; // 72 hours.
	public const STALE_SUCCESS      = 604800; // 7 days.

	/** Fixed category allowlists. */
	public const LOG_CATEGORIES     = array( 'gateway_error', 'gateway_fatal', 'webhook_signature_failed' );
	public const JOB_CATEGORIES     = array( 'gateway_job_failed' );
	public const WEBHOOK_CATEGORIES = array( 'delivery_ok', 'delivery_failed', 'order_unclear', 'record_malformed', 'record_missing', 'no_events_recorded', 'no_success_recorded', 'success_stale', 'webhook_stale_unverified', 'mode_unknown' );
	public const SOURCE_LABELS      = array( 'woocommerce_log_file', 'woocommerce_log_db' );

	/** WooCommerce FileV2 line head (time, level, message). */
	private const WC_LINE = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)) (EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG) (.*)$/s';

	/** Verified Stripe webhook option names (item 1). */
	private const STRIPE_WEBHOOK = array(
		'live' => array(
			'monitoring_began_at' => 'wc_stripe_wh_monitor_began_at',
			'last_success_at'     => 'wc_stripe_wh_last_success_at',
			'last_failure_at'     => 'wc_stripe_wh_last_failure_at',
		),
		'test' => array(
			'monitoring_began_at' => 'wc_stripe_wh_test_monitor_began_at',
			'last_success_at'     => 'wc_stripe_wh_test_last_success_at',
			'last_failure_at'     => 'wc_stripe_wh_test_last_failure_at',
		),
	);

	/** Verified webhook-delivery failure prefix (Stripe, error level). */
	private const STRIPE_WEBHOOK_FAILURE = 'Webhook validation failed (';

	// ------------------------------------------------------------------ assembly.

	/**
	 * Errors block for every inventoried method.
	 *
	 * @param array<string,array<string,mixed>>    $methods    PaymentMethods::inventory()['methods'].
	 * @param array<string,array<string,string[]>> $families   PaymentMethods::inventory()['families'].
	 * @param array<string,int>                    $accepted_7d Accepted orders in the last 7 days, by method.
	 * @param int                                  $now        Captured now.
	 * @param int                                  $since_hours Window.
	 * @param \DateTimeZone                        $tz         Store timezone.
	 * @return array<string,array{status:string,logs:array<string,mixed>,jobs:array<string,mixed>,webhook:array<string,mixed>}>
	 */
	public static function for_methods( array $methods, array $families, array $accepted_7d, int $now, int $since_hours, \DateTimeZone $tz ): array {
		$since   = $now - max( 1, min( 168, $since_hours ) ) * 3600;
		$allowed = Settings::can_view_diagnostics(); // Checked before any log or job read.
		$used    = array();
		foreach ( $methods as $m ) {
			if ( null !== ( $m['family'] ?? null ) && isset( $families[ $m['family'] ] ) ) {
				$used[ $m['family'] ] = $families[ $m['family'] ];
			}
		}

		$logs = $allowed ? self::read_logs( $used, $since, $now ) : null;
		$jobs = $allowed ? self::read_jobs( $used, $since, $now ) : null;

		$out         = array();
		$groups_left = self::MAX_GROUPS_TOTAL;
		$hooks_left  = self::MAX_HOOKS_TOTAL;
		foreach ( $methods as $id => $m ) {
			$family  = $m['family'] ?? null;
			$manual  = 'manual' === ( $m['kind'] ?? null );
			// Q6: a family counts as mapped for a check only with verified tokens for that check.
			$lists      = null !== $family ? ( $families[ $family ] ?? null ) : null;
			$log_family = is_array( $lists ) && array() !== ( $lists['log_sources'] ?? array() ) ? $family : null;
			$job_family = is_array( $lists ) && ( array() !== ( $lists['hooks'] ?? array() ) || array() !== ( $lists['hook_groups'] ?? array() ) ) ? $family : null;
			$log        = self::log_check( $allowed, $manual, $log_family, $logs, $tz, $groups_left );
			$job        = self::job_check( $allowed, $manual, $job_family, $jobs, $hooks_left );
			$webhook = self::webhook_check( $m, (int) ( $accepted_7d[ $id ] ?? 0 ), $now, $tz );
			$out[ $id ] = array(
				'status'  => PaymentRules::rollup( array( $log, $job, $webhook ) ),
				'logs'    => $log,
				'jobs'    => $job,
				'webhook' => $webhook,
			);
		}
		return $out;
	}

	/**
	 * Base check.
	 *
	 * @param string      $status     Status.
	 * @param bool        $checked    Evidence read.
	 * @param bool        $applicable Applicable.
	 * @param string      $coverage   Coverage.
	 * @param string|null $reason     Reason code.
	 * @return array<string,mixed>
	 */
	private static function check( string $status, bool $checked, bool $applicable, string $coverage, ?string $reason ): array {
		return array(
			'status'     => $status,
			'checked'    => $checked,
			'applicable' => $applicable,
			'coverage'   => $coverage,
			'reason'     => $reason,
		);
	}

	// ------------------------------------------------------------------ logs.

	/**
	 * One method's LogCheck from the shared family results.
	 *
	 * @param bool                                   $allowed     Diagnostics permission.
	 * @param bool                                   $manual      Manual kind (no gateway source).
	 * @param string|null                            $family      Family.
	 * @param array<string,array<string,mixed>>|null $logs        Per-family results.
	 * @param \DateTimeZone                          $tz          Store timezone.
	 * @param int                                    $groups_left Response-wide group budget (by reference).
	 * @return array<string,mixed>
	 */
	private static function log_check( bool $allowed, bool $manual, ?string $family, ?array $logs, \DateTimeZone $tz, int &$groups_left ): array {
		$base = array(
			'count'                => null,
			'count_is_lower_bound' => false,
			'groups_total'         => null,
			'groups_truncated'     => false,
			'groups'               => array(),
		);
		if ( $manual ) {
			return self::check( 'unknown', false, false, 'not_applicable', 'manual_method' ) + $base;
		}
		if ( null === $family ) {
			// Q6: no verified log mapping for this gateway family. Not applicable, so it is
			// omitted from the rollup: never green evidence and never an unknown.
			return self::check( 'unknown', false, false, 'not_applicable', 'no_verified_mapping' ) + $base;
		}
		if ( ! $allowed ) {
			return self::check( 'unknown', false, true, 'unavailable', 'diagnostics_not_permitted' ) + $base;
		}
		if ( ! isset( $logs[ $family ] ) ) {
			return self::check( 'unknown', false, true, 'unavailable', 'source_read_failed' ) + $base;
		}
		$r = $logs[ $family ];
		if ( ! $r['ok'] ) {
			return self::check( 'unknown', false, true, 'unavailable', $r['reason'] ) + $base;
		}
		$verdict = self::log_verdict( $r['count'], $r['webhook_failures'], $r['complete'] );
		$groups  = array();
		foreach ( $r['groups'] as $g ) {
			if ( count( $groups ) >= self::MAX_GROUPS_METHOD || $groups_left <= 0 ) {
				break;
			}
			$groups[] = array(
				'severity'   => $g['severity'],
				'category'   => $g['category'],
				'count'      => $g['count'],
				'first_seen' => PaymentRules::iso( $g['first'], $tz ),
				'last_seen'  => PaymentRules::iso( $g['last'], $tz ),
				'source'     => $r['source_label'],
				'scope'      => 'plugin',
			);
			--$groups_left;
		}
		return self::check( $verdict, true, true, $r['complete'] ? 'complete' : 'partial', $r['complete'] ? null : $r['reason'] ) + array(
			'count'                => $r['count'],
			'count_is_lower_bound' => ! $r['complete'],
			'groups_total'         => count( $r['groups'] ),
			'groups_truncated'     => count( $groups ) < count( $r['groups'] ),
			'groups'               => $groups,
		);
	}

	/**
	 * Log verdict (C8): a verified webhook-delivery failure or >= 20 observed entries is red; any
	 * observed entry is amber; zero is green only when the read was complete.
	 *
	 * @param int  $count            Observed qualifying entries.
	 * @param int  $webhook_failures Verified webhook-delivery failure entries.
	 * @param bool $complete         Complete read.
	 */
	public static function log_verdict( int $count, int $webhook_failures, bool $complete ): string {
		if ( $webhook_failures > 0 || $count >= self::RED_LOG_COUNT ) {
			return 'red';
		}
		if ( $count > 0 ) {
			return 'amber';
		}
		return $complete ? 'green' : 'unknown';
	}

	/**
	 * Classify observed log entries into fixed categories. Only ERROR and above count; text is
	 * read for the one verified prefix and then discarded.
	 *
	 * @param string[] $lines    Raw lines (oldest first).
	 * @param int      $since    Window start.
	 * @param int      $now      Window end (exclusive).
	 * @param string   $family   Family (only 'stripe' has a verified webhook prefix).
	 * @return array{count:int,webhook_failures:int,malformed:int,groups:array<string,array{severity:string,category:string,count:int,first:int,last:int}>}
	 */
	public static function classify_lines( array $lines, int $since, int $now, string $family ): array {
		$out = array(
			'count'            => 0,
			'webhook_failures' => 0,
			'malformed'        => 0,
			'groups'           => array(),
		);
		foreach ( $lines as $line ) {
			if ( 1 !== preg_match( self::WC_LINE, $line, $m ) ) {
				if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T/', $line ) ) {
					++$out['malformed']; // Looks like an entry head but does not parse.
				}
				continue; // Continuation line of the previous entry: entries are counted, not lines.
			}
			$ts = strtotime( $m[1] );
			if ( false === $ts ) {
				++$out['malformed'];
				continue;
			}
			if ( $ts < $since || $ts >= $now ) {
				continue;
			}
			self::add_entry( $out, $m[2], $m[3], $ts, $family );
		}
		return $out;
	}

	/**
	 * Count one qualifying entry.
	 *
	 * @param array<string,mixed> $out     Accumulator (by reference).
	 * @param string              $level   Upper-case level.
	 * @param string              $message Message (never stored).
	 * @param int                 $ts      Time.
	 * @param string              $family  Family.
	 */
	private static function add_entry( array &$out, string $level, string $message, int $ts, string $family ): void {
		if ( ! in_array( $level, array( 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY' ), true ) ) {
			return;
		}
		$severity = 'ERROR' === $level ? 'error' : 'fatal';
		$category = 'error' === $severity ? 'gateway_error' : 'gateway_fatal';
		if ( 'stripe' === $family && 'ERROR' === $level && str_starts_with( $message, self::STRIPE_WEBHOOK_FAILURE ) ) {
			$category = 'webhook_signature_failed';
			++$out['webhook_failures'];
		}
		++$out['count'];
		$key = $severity . '|' . $category;
		if ( ! isset( $out['groups'][ $key ] ) ) {
			$out['groups'][ $key ] = array(
				'severity' => $severity,
				'category' => $category,
				'count'    => 0,
				'first'    => $ts,
				'last'     => $ts,
			);
		}
		++$out['groups'][ $key ]['count'];
		$out['groups'][ $key ]['first'] = min( $out['groups'][ $key ]['first'], $ts );
		$out['groups'][ $key ]['last']  = max( $out['groups'][ $key ]['last'], $ts );
	}

	/**
	 * Read each used family's log entries once, within the shared file/byte/row budgets.
	 *
	 * @param array<string,array<string,string[]>> $families Families in use.
	 * @param int                                  $since    Window start.
	 * @param int                                  $now      Now.
	 * @return array<string,array<string,mixed>>
	 */
	private static function read_logs( array $families, int $since, int $now ): array {
		$out = array();
		if ( array() === $families ) {
			return $out;
		}
		$fail = static fn( string $reason ): array => array(
			'ok'     => false,
			'reason' => $reason,
		);
		$env = self::logging_environment( $since, $now );
		if ( null !== $env['reason'] && ! $env['partial_only'] ) {
			foreach ( $families as $family => $unused ) {
				$out[ $family ] = $fail( $env['reason'] );
			}
			return $out;
		}
		try {
			if ( 'db' === $env['handler'] ) {
				return self::read_db_logs( $families, $since, $now, $env );
			}
			return self::read_file_logs( $families, $since, $now, $env );
		} catch ( \Throwable $e ) {
			foreach ( $families as $family => $unused ) {
				$out[ $family ] = $fail( 'source_read_failed' );
			}
			return $out;
		}
	}

	/**
	 * Active verified logging setup: enabled, handler (file|db), error level not filtered out,
	 * retention covering the window.
	 *
	 * @param int $since Window start.
	 * @param int $now   Now.
	 * @return array{handler:?string,reason:?string,partial_only:bool}
	 */
	private static function logging_environment( int $since, int $now ): array {
		$util = \Automattic\WooCommerce\Utilities\LoggingUtil::class;
		$env  = array(
			'handler'      => null,
			'reason'       => null,
			'partial_only' => false,
		);
		if ( ! class_exists( $util ) ) {
			$env['reason'] = 'logging_unavailable';
			return $env;
		}
		if ( ! $util::logging_is_enabled() ) {
			$env['reason'] = 'logging_disabled';
			return $env;
		}
		$handler = ltrim( (string) $util::get_default_handler(), '\\' );
		if ( 'Automattic\\WooCommerce\\Internal\\Admin\\Logging\\LogHandlerFileV2' === $handler ) {
			$env['handler'] = 'file';
		} elseif ( 'WC_Log_Handler_DB' === $handler ) {
			$env['handler'] = 'db';
		} else {
			$env['reason'] = 'unsupported_log_handler';
			return $env;
		}
		$threshold = (string) $util::get_level_threshold();
		if ( 'none' !== $threshold && class_exists( '\WC_Log_Levels' ) && \WC_Log_Levels::get_level_severity( $threshold ) > \WC_Log_Levels::get_level_severity( 'error' ) ) {
			$env['reason'] = 'logging_threshold_above_error';
			return $env;
		}
		if ( (int) $util::get_retention_period() * 86400 < $now - $since ) {
			$env['reason']       = 'stale_retention';
			$env['partial_only'] = true;
		}
		return $env;
	}

	/**
	 * File handler: each family's source files, deduplicated, under one 7-file / 5 MB budget.
	 *
	 * @param array<string,array<string,string[]>> $families Families.
	 * @param int                                  $since    Start.
	 * @param int                                  $now      Now.
	 * @param array<string,mixed>                  $env      Environment.
	 * @return array<string,array<string,mixed>>
	 */
	private static function read_file_logs( array $families, int $since, int $now, array $env ): array {
		$plan = array();
		$seen = array();
		$out  = array();
		foreach ( $families as $family => $lists ) {
			$found = LogSources::source_files( $lists['log_sources'], $since );
			$out[ $family ] = array(
				'ok'               => $found['ok'],
				'reason'           => $found['ok'] ? ( $found['truncated'] ? 'file_cap' : $env['reason'] ) : $found['reason'],
				'complete'         => $found['ok'] && ! $found['truncated'] && null === $env['reason'] && null === $found['reason'],
				'count'            => 0,
				'webhook_failures' => 0,
				'groups'           => array(),
				'files'            => 0,
				'source_label'     => 'woocommerce_log_file',
			);
			foreach ( $found['files'] as $file ) {
				if ( isset( $seen[ $file['path'] ] ) ) {
					continue; // Exact deduplication across family tokens.
				}
				$seen[ $file['path'] ] = true;
				$plan[]                = array(
					'family' => $family,
					'path'   => $file['path'],
				);
			}
		}
		if ( count( $plan ) > self::MAX_FILES ) {
			foreach ( array_slice( $plan, self::MAX_FILES ) as $skipped ) {
				$out[ $skipped['family'] ]['complete'] = false;
				$out[ $skipped['family'] ]['reason']   = 'file_cap';
			}
			$plan = array_slice( $plan, 0, self::MAX_FILES );
		}
		$per_file = array() === $plan ? 0 : intdiv( self::MAX_BYTES, count( $plan ) );
		$allowed  = array_column( $plan, 'path' );
		foreach ( $plan as $item ) {
			$f    = $item['family'];
			$read = LogSources::read_bounded( $item['path'], $per_file, $allowed );
			if ( ! $read['ok'] ) {
				$out[ $f ]['complete'] = false;
				$out[ $f ]['reason']   = 'file_unreadable';
				continue;
			}
			++$out[ $f ]['files'];
			if ( $read['partial'] ) {
				$out[ $f ]['complete'] = false;
				$out[ $f ]['reason']   = 'byte_cap';
			}
			$c = self::classify_lines( $read['lines'], $since, $now, $f );
			if ( $c['malformed'] > 0 ) {
				$out[ $f ]['complete'] = false;
				$out[ $f ]['reason']   = 'unparsed_entries';
			}
			self::merge( $out[ $f ], $c );
		}
		foreach ( $out as $f => $r ) {
			if ( $r['ok'] && 0 === $r['files'] ) {
				// No matching source file: unavailable, not an empty healthy log.
				$out[ $f ] = array(
					'ok'     => false,
					'reason' => 'no_matching_log_source',
				);
				continue;
			}
			$out[ $f ]['groups'] = self::sorted_groups( $r['groups'] );
		}
		return $out;
	}

	/**
	 * DB handler: bounded rows and message bytes, one metadata-only sentinel for truncation.
	 *
	 * @param array<string,array<string,string[]>> $families Families.
	 * @param int                                  $since    Start.
	 * @param int                                  $now      Now.
	 * @param array<string,mixed>                  $env      Environment.
	 * @return array<string,array<string,mixed>>
	 */
	private static function read_db_logs( array $families, int $since, int $now, array $env ): array {
		global $wpdb;
		$table   = $wpdb->prefix . 'woocommerce_log';
		$out     = array();
		$sources = array();
		foreach ( $families as $family => $lists ) {
			foreach ( $lists['log_sources'] as $s ) {
				$sources[ $s ] = $family;
			}
			$out[ $family ] = array(
				'ok'               => true,
				'reason'           => $env['reason'],
				'complete'         => null === $env['reason'],
				'count'            => 0,
				'webhook_failures' => 0,
				'groups'           => array(),
				'source_label'     => 'woocommerce_log_db',
			);
		}
		if ( array() === $sources ) {
			// No family has verified log sources: no query (never an empty IN ()).
			return array_map(
				static fn(): array => array(
					'ok'     => false,
					'reason' => 'no_verified_mapping',
				),
				$out
			);
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only existence check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== $wpdb->last_error || $table !== $found ) {
			return array_map(
				static fn(): array => array(
					'ok'     => false,
					'reason' => 'source_read_failed',
				),
				$out
			);
		}
		$keys   = array_keys( $sources );
		$sp     = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql    = "SELECT log_id, timestamp AS ts, level, source, LEFT(message, %d) AS msg FROM %i WHERE level >= %d AND source IN ({$sp}) AND timestamp >= %s AND timestamp < %s ORDER BY log_id DESC LIMIT %d";
		$budget = self::MAX_BYTES;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- only %s placeholder lists are interpolated; $sql is prepared here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded read; the ability caches its result.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::MAX_DB_MSG, $table, 500 ), $keys, array( gmdate( 'Y-m-d H:i:s', $since ), gmdate( 'Y-m-d H:i:s', $now ), self::MAX_DB_ROWS + 1 ) ) ), ARRAY_A );
		// phpcs:enable
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			return array_map(
				static fn(): array => array(
					'ok'     => false,
					'reason' => 'source_read_failed',
				),
				$out
			);
		}
		$truncated = count( $rows ) > self::MAX_DB_ROWS;
		$rows      = array_slice( $rows, 0, self::MAX_DB_ROWS );
		$levels    = array(
			800 => 'EMERGENCY',
			700 => 'ALERT',
			600 => 'CRITICAL',
			500 => 'ERROR',
		);
		$acc       = array();
		foreach ( $rows as $r ) {
			$family = $sources[ (string) $r['source'] ] ?? null;
			$dt     = PaymentRules::utc_datetime( (string) $r['ts'] );
			$lvl    = (int) $r['level'];
			$level  = $levels[ $lvl ] ?? ( $lvl > 800 ? 'EMERGENCY' : ( $lvl >= 500 ? 'ERROR' : null ) );
			if ( null === $family || null === $dt['ts'] || null === $level ) {
				continue;
			}
			$msg     = (string) $r['msg'];
			$budget -= strlen( $msg );
			if ( $budget < 0 ) {
				$truncated = true;
				break;
			}
			if ( ! isset( $acc[ $family ] ) ) {
				$acc[ $family ] = array(
					'count'            => 0,
					'webhook_failures' => 0,
					'malformed'        => 0,
					'groups'           => array(),
				);
			}
			self::add_entry( $acc[ $family ], $level, $msg, (int) $dt['ts'], $family );
		}
		foreach ( $out as $family => $r ) {
			if ( isset( $acc[ $family ] ) ) {
				self::merge( $out[ $family ], $acc[ $family ] );
			}
			if ( $truncated ) {
				$out[ $family ]['complete'] = false;
				$out[ $family ]['reason']   = 'row_cap';
			}
			$out[ $family ]['groups'] = self::sorted_groups( $out[ $family ]['groups'] );
		}
		return $out;
	}

	/**
	 * Merge classified counts into a family result.
	 *
	 * @param array<string,mixed> $into Result (by reference).
	 * @param array<string,mixed> $c    classify_lines() output.
	 */
	private static function merge( array &$into, array $c ): void {
		$into['count']            += $c['count'];
		$into['webhook_failures'] += $c['webhook_failures'];
		foreach ( $c['groups'] as $key => $g ) {
			if ( ! isset( $into['groups'][ $key ] ) ) {
				$into['groups'][ $key ] = $g;
				continue;
			}
			$into['groups'][ $key ]['count'] += $g['count'];
			$into['groups'][ $key ]['first']  = min( $into['groups'][ $key ]['first'], $g['first'] );
			$into['groups'][ $key ]['last']   = max( $into['groups'][ $key ]['last'], $g['last'] );
		}
	}

	/**
	 * Groups: fatal first, then by count, then category.
	 *
	 * @param array<string,array<string,mixed>> $groups Groups.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sorted_groups( array $groups ): array {
		$groups = array_values( $groups );
		usort(
			$groups,
			static fn( array $a, array $b ): int => array( 'fatal' === $a['severity'] ? 0 : 1, -$a['count'], $a['category'] ) <=> array( 'fatal' === $b['severity'] ? 0 : 1, -$b['count'], $b['category'] )
		);
		return $groups;
	}

	// ------------------------------------------------------------------ jobs.

	/**
	 * Failed jobs for every used family in one read.
	 *
	 * @param array<string,array<string,string[]>> $families Families.
	 * @param int                                  $since    Start.
	 * @param int                                  $now      Now.
	 * @return array<string,array<string,mixed>>
	 */
	private static function read_jobs( array $families, int $since, int $now ): array {
		$hooks  = array();
		$groups = array();
		foreach ( $families as $lists ) {
			$hooks  = array_merge( $hooks, $lists['hooks'] );
			$groups = array_merge( $groups, $lists['hook_groups'] );
		}
		if ( array() === $families ) {
			return array();
		}
		try {
			$r = JobsHealth::payment_failures( array_values( array_unique( $hooks ) ), array_values( array_unique( $groups ) ), $since, $now );
		} catch ( \Throwable $e ) {
			$r = array(
				'available'   => false,
				'read_failed' => true,
				'exact'       => false,
				'rows'        => array(),
			);
		}
		$out = array();
		foreach ( $families as $family => $lists ) {
			if ( ! $r['available'] ) {
				$out[ $family ] = array(
					'ok'     => false,
					'reason' => $r['read_failed'] ? 'source_read_failed' : 'jobs_unavailable',
				);
				continue;
			}
			$count = 0;
			foreach ( $r['rows'] as $row ) {
				if ( in_array( $row['hook'], $lists['hooks'], true ) || in_array( $row['group'], $lists['hook_groups'], true ) ) {
					$count += $row['count'];
				}
			}
			$out[ $family ] = array(
				'ok'    => true,
				'exact' => $r['exact'],
				'count' => $count,
			);
		}
		return $out;
	}

	/**
	 * One method's JobCheck.
	 *
	 * @param bool                                   $allowed    Diagnostics permission.
	 * @param bool                                   $manual     Manual kind.
	 * @param string|null                            $family     Family.
	 * @param array<string,array<string,mixed>>|null $jobs       Per-family results.
	 * @param int                                    $hooks_left Response-wide hook-category budget (by reference).
	 * @return array<string,mixed>
	 */
	private static function job_check( bool $allowed, bool $manual, ?string $family, ?array $jobs, int &$hooks_left ): array {
		$base = array(
			'failed'               => null,
			'count_is_lower_bound' => false,
			'hooks_truncated'      => false,
			'hooks'                => array(),
		);
		if ( $manual ) {
			return self::check( 'unknown', false, false, 'not_applicable', 'manual_method' ) + $base;
		}
		if ( null === $family ) {
			// Q6: no verified job mapping for this gateway family; omitted from the rollup.
			return self::check( 'unknown', false, false, 'not_applicable', 'no_verified_mapping' ) + $base;
		}
		if ( ! $allowed ) {
			return self::check( 'unknown', false, true, 'unavailable', 'diagnostics_not_permitted' ) + $base;
		}
		if ( ! isset( $jobs[ $family ] ) ) {
			return self::check( 'unknown', false, true, 'unavailable', 'source_read_failed' ) + $base;
		}
		$r = $jobs[ $family ];
		if ( ! $r['ok'] ) {
			return self::check( 'unknown', false, true, 'unavailable', $r['reason'] ) + $base;
		}
		$status = $r['count'] > 0 ? 'amber' : ( $r['exact'] ? 'green' : 'unknown' );
		$hooks  = array();
		$cut    = false;
		if ( $r['count'] > 0 ) {
			if ( $hooks_left > 0 ) {
				$hooks[] = array(
					'category' => 'gateway_job_failed',
					'count'    => $r['count'],
					'scope'    => 'plugin',
				);
				--$hooks_left;
			} else {
				$cut = true;
			}
		}
		return self::check( $status, true, true, $r['exact'] ? 'complete' : 'partial', $r['exact'] ? null : 'sampled_store' ) + array(
			'failed'               => $r['count'],
			'count_is_lower_bound' => ! $r['exact'],
			'hooks_truncated'      => $cut,
			'hooks'                => $hooks,
		);
	}

	// ------------------------------------------------------------------ webhook.

	/**
	 * One method's WebhookCheck from verified records only.
	 *
	 * @param array<string,mixed> $m           Inventory row.
	 * @param int                 $accepted_7d Accepted orders for the method in the last 7 days.
	 * @param int                 $now         Now.
	 * @param \DateTimeZone       $tz          Store timezone.
	 * @return array<string,mixed>
	 */
	private static function webhook_check( array $m, int $accepted_7d, int $now, \DateTimeZone $tz ): array {
		if ( 'stripe' !== ( $m['webhook_adapter'] ?? null ) ) {
			return self::check( 'unknown', false, false, 'not_applicable', 'no_supported_webhook_record' ) + array(
				'last_success_at' => null,
				'last_failure_at' => null,
				'category'        => null,
				'scope'           => 'plugin',
			);
		}
		$settings = get_option( 'woocommerce_stripe_settings', null );
		$records  = array();
		foreach ( self::STRIPE_WEBHOOK as $mode => $names ) {
			foreach ( $names as $key => $option ) {
				$value = get_option( $option, null );
				if ( null !== $value ) {
					$records[ $mode ][ $key ] = $value;
				}
			}
		}
		$v = self::webhook_status( $settings, $records, $now, $accepted_7d, $m['webhook_required'] ?? null );
		if ( in_array( $v['category'], array( 'delivery_ok', 'delivery_failed', 'success_stale' ), true ) ) {
			$coverage = 'complete';
		} elseif ( 'mode_unknown' === $v['category'] ) {
			$coverage = 'unavailable'; // Nothing could be read for an unknown mode.
		} else {
			$coverage = 'partial';
		}
		return self::check( $v['status'], null !== $v['mode'], true, $coverage, $v['category'] ) + array(
			'last_success_at' => null === $v['last_success'] ? null : PaymentRules::iso( $v['last_success'], $tz ),
			'last_failure_at' => null === $v['last_failure'] ? null : PaymentRules::iso( $v['last_failure'], $tz ),
			'category'        => $v['category'],
			'scope'           => 'plugin',
		);
	}

	/**
	 * Pure Stripe webhook verdict (C8, item 1 fixture vectors, interim rulings Q1-Q4).
	 *
	 * @param mixed                              $settings         woocommerce_stripe_settings (null when absent).
	 * @param array<string,array<string,mixed>> $records          Mode => key => raw value (absent keys omitted).
	 * @param int                                $now              Now.
	 * @param int                                $accepted_7d      Eligible accepted orders in 7 days.
	 * @param bool|null                          $webhook_required Verified expectation that orders need the webhook.
	 * @return array{status:string,mode:?string,category:string,last_success:?int,last_failure:?int}
	 */
	public static function webhook_status( $settings, array $records, int $now, int $accepted_7d, ?bool $webhook_required ): array {
		$out = array(
			'status'       => 'unknown',
			'mode'         => null,
			'category'     => 'mode_unknown',
			'last_success' => null,
			'last_failure' => null,
		);
		if ( ! is_array( $settings ) ) {
			return $out;
		}
		$mode        = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'] ? 'test' : 'live';
		$out['mode'] = $mode;
		$r           = $records[ $mode ] ?? array();
		if ( ! array_key_exists( 'last_success_at', $r ) || ! array_key_exists( 'last_failure_at', $r ) ) {
			$out['category'] = 'record_missing';
			return $out;
		}
		$s = self::ts( $r['last_success_at'], $now );
		$f = self::ts( $r['last_failure_at'], $now );
		if ( null === $s || null === $f ) {
			$out['category'] = 'record_malformed';
			return $out;
		}
		$out['last_success'] = $s > 0 ? $s : null;
		$out['last_failure'] = $f > 0 ? $f : null;
		if ( 0 === $s && 0 === $f ) {
			$out['category'] = 'no_events_recorded'; // Q2: not green.
			return $out;
		}
		if ( $f > 0 && $f === $s ) {
			$out['category'] = 'order_unclear'; // Q1: equal values do not establish a newer failure.
			return $out;
		}
		if ( $f > $s ) {
			if ( $now - $f <= self::RED_FAILURE_WINDOW ) {
				$out['status']   = 'red'; // Q3: kept red; finding wording says compare with the gateway.
				$out['category'] = 'delivery_failed';
				return $out;
			}
			if ( 0 === $s ) {
				$out['category'] = 'no_success_recorded';
				return $out;
			}
		}
		if ( $now - $s > self::STALE_SUCCESS && $accepted_7d > 0 ) {
			if ( true === $webhook_required ) {
				$out['status']   = 'amber'; // Q4: only with a verified expectation.
				$out['category'] = 'success_stale';
				return $out;
			}
			if ( null === $webhook_required ) {
				// Q4 (pre-review miss 3): the expectation is unverified, so neither stale nor fine.
				$out['category'] = 'webhook_stale_unverified';
				return $out;
			}
		}
		$out['status']   = 'green';
		$out['category'] = 'delivery_ok';
		return $out;
	}

	/**
	 * Valid record value: digit-only scalar, not in the future. Null when malformed.
	 *
	 * @param mixed $value Raw.
	 * @param int   $now   Now.
	 */
	private static function ts( $value, int $now ): ?int {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || '' === $value || ! ctype_digit( $value ) || strlen( $value ) > 12 ) {
			return null;
		}
		$ts = (int) $value;
		return $ts > $now ? null : $ts;
	}
}
