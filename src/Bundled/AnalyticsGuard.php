<?php
/**
 * Stops the bundled analytics tools from reporting incomplete figures as fact.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Bundled;

defined( 'ABSPATH' ) || exit;

/**
 * The bundled wc-analytics/* abilities read wc_order_stats directly. When WooCommerce Analytics
 * is turned off, WooCommerce never starts ReportsSync, so new orders never reach that table; when
 * the historical import has not run or is still running, older orders are missing. In both cases
 * upstream returns low or zero totals with no warning. This guard short-circuits those calls with
 * a plain error instead. Only registered when we are not standing aside (WooCommerce for Claude's
 * own copy is its responsibility).
 */
final class AnalyticsGuard {

	public const ERROR_CODE = 'blzmcp_analytics_unavailable';

	/**
	 * Stable, untranslated prefix of the error message. MCP Adapter's execute-ability passes only the
	 * message (not the code) to the AI, so workflows key on this token.
	 */
	public const MESSAGE_TOKEN = '[ANALYTICS_UNAVAILABLE]';

	/**
	 * Transient caching the "orders missing from wc_order_stats" probe: a map of
	 * hash(requested windows) => array{reason: string, expires: int}.
	 */
	public const GAP_TRANSIENT = 'blzmcp_analytics_gap';

	/**
	 * The bundled package's date resolver (WooCommerce for Claude's copy has the same name).
	 */
	private const SERVICE_CLASS = 'WooCommerce\\CommerceAbilities\\Analytics\\AnalyticsService';

	/**
	 * Abilities without a compare input (they never read a comparison period).
	 */
	private const NO_COMPARE = array( 'wc-analytics/rows' );

	/**
	 * Most cached windows kept in the gap transient.
	 */
	private const CACHE_ENTRIES = 50;

	/**
	 * Abilities that read no store data and so are never blocked.
	 */
	private const EXEMPT = array( 'wc-analytics/confirm-large-range' );

	/**
	 * Action Scheduler hooks of WooCommerce Analytics' historical order import.
	 */
	private const IMPORT_BATCH_HOOKS = array( 'wc-admin_import_batch_init_orders', 'wc-admin_import_batch_orders' );

	/**
	 * Orders younger than this may legitimately not be synced yet (Action Scheduler runs async).
	 */
	private const SYNC_GRACE_SECONDS = HOUR_IN_SECONDS;

	/**
	 * How far back the gap probe looks.
	 */
	private const WINDOW_DAYS = 30;

	/**
	 * Statuses WooCommerce Analytics reports on that must appear in wc_order_stats.
	 */
	private const COUNTED_STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' );

	/**
	 * Decorate the wc-analytics/* execute callbacks as they register. Works on every core with the
	 * Abilities API (6.9+). Core only calls execute_callback from WP_Ability::execute() after input
	 * validation and the permission check, so the guard never answers an unauthorised caller.
	 */
	public static function register(): void {
		add_filter( 'wp_register_ability_args', array( self::class, 'filter_args' ), 20, 2 );

		// Re-probe after historical import batches run and when Analytics is toggled.
		foreach ( self::IMPORT_BATCH_HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'clear_cache' ), 999, 0 );
		}
		add_action( 'update_option_woocommerce_analytics_enabled', array( self::class, 'clear_cache' ), 10, 0 );
	}

	/**
	 * Wrap the execute callback of data-reading wc-analytics/* abilities.
	 *
	 * @param mixed  $args Ability registration args.
	 * @param string $name Ability name.
	 * @return mixed
	 */
	public static function filter_args( $args, $name = '' ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || ! str_starts_with( $name, 'wc-analytics/' ) || in_array( $name, self::EXEMPT, true ) ) {
			return $args;
		}
		if ( ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( ...$call_args ) use ( $original, $name ) {
			$input = isset( $call_args[0] ) && is_array( $call_args[0] ) ? $call_args[0] : array();
			$error = self::unavailable_error( $input, $name );
			if ( null !== $error ) {
				return $error;
			}
			return call_user_func_array( $original, $call_args );
		};

		return $args;
	}

	/**
	 * The error to return instead of incomplete figures, or null when analytics data is fine.
	 *
	 * @param array<string, mixed> $input   The analytics call's input (period, date_start, date_end, compare).
	 * @param string               $ability Ability name, when known.
	 */
	public static function unavailable_error( array $input = array(), string $ability = '' ): ?\WP_Error {
		$reason = self::unavailable_reason( $input, $ability );
		if ( null === $reason ) {
			return null;
		}

		return new \WP_Error(
			self::ERROR_CODE,
			self::MESSAGE_TOKEN . ' ' . self::message( $reason ),
			array(
				'status' => 503,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Plain message for the store owner, per reason.
	 *
	 * @param string $reason Reason code.
	 */
	public static function message( string $reason ): string {
		switch ( $reason ) {
			case 'analytics_disabled':
				return __( 'WooCommerce Analytics is turned off, so these figures would be incomplete. Turn on Analytics under WooCommerce > Settings > Advanced > Features, then try again.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'analytics_unreadable':
				return __( 'WooCommerce Analytics data couldn\'t be read just now, so these figures can\'t be checked for completeness. Try again in a few minutes; if it keeps happening, ask your developer or host to check the store\'s database.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'import_in_progress':
			case 'import_not_run':
				return __( 'WooCommerce Analytics hasn\'t finished importing your orders, so these figures would be incomplete. Wait for the import to finish (or start it under Analytics > Settings > Import historical data), then try again.', 'blaze-mcp-toolkit-for-woocommerce' );
			default:
				return __( 'The requested dates include orders that aren\'t in WooCommerce Analytics yet, so these figures would be incomplete. Import them under WooCommerce > Analytics > Settings > Import historical data (if recent orders are missing, also check WooCommerce > Status > Scheduled Actions for stalled wc-admin jobs), or ask about a more recent period.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
	}

	/**
	 * Why analytics data for the requested dates is unreliable right now, or null when it is fine.
	 *
	 * @param array<string, mixed> $input   The analytics call's input; empty uses the resolver's default period.
	 * @param string               $ability Ability name, when known (decides whether compare applies).
	 * @return string|null One of analytics_disabled, import_in_progress, import_not_run, orders_not_imported, analytics_unreadable.
	 */
	public static function unavailable_reason( array $input = array(), string $ability = '' ): ?string {
		if ( 'yes' !== get_option( 'woocommerce_analytics_enabled', 'yes' ) ) {
			return 'analytics_disabled';
		}

		// Historical import batches still queued or running. (ReportsSync::is_importing() is not used:
		// it also matches the per-order wc-admin_import_orders jobs every new order queues.)
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			foreach ( self::IMPORT_BATCH_HOOKS as $hook ) {
				if ( as_has_scheduled_action( $hook, null, 'wc-admin-data' ) ) {
					return 'import_in_progress';
				}
			}
		}

		return self::sync_gap( self::windows( $input, $ability ) );
	}

	/**
	 * The windows an analytics call reads, as GMT [from, to) Unix timestamps.
	 *
	 * Resolved with the bundled package's own resolver, so the guard checks the dates the tool reads:
	 * the requested period and, when compare is on (the tools' default), the previous period of the
	 * same length. Falls back to the last 30 days when the resolver is unavailable or fails.
	 *
	 * @param array<string, mixed> $input   The analytics call's input.
	 * @param string               $ability Ability name, when known.
	 * @return array<int, array{key: string, from: int, to: int}>
	 */
	public static function windows( array $input = array(), string $ability = '' ): array {
		$now      = time();
		$fallback = array(
			array(
				'key'  => 'fallback_' . self::WINDOW_DAYS,
				'from' => $now - self::WINDOW_DAYS * DAY_IN_SECONDS,
				'to'   => $now,
			),
		);

		$service = self::SERVICE_CLASS;
		if ( ! class_exists( $service ) || ! method_exists( $service, 'resolve_dates' ) ) {
			return $fallback;
		}

		try {
			$period = isset( $input['period'] ) && is_string( $input['period'] ) ? $input['period'] : '';
			$dates  = $service::resolve_dates( $period, $input['date_start'] ?? null, $input['date_end'] ?? null );
			$ranges = array( array( $dates['start'] ?? '', $dates['end'] ?? '' ) );

			$compare = ! in_array( $ability, self::NO_COMPARE, true )
				&& ( array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true );
			if ( $compare ) {
				if ( ! method_exists( $service, 'get_previous_period' ) ) {
					return $fallback;
				}
				$previous = $service::get_previous_period( $ranges[0][0], $ranges[0][1] );
				$ranges[] = array( $previous['start'] ?? '', $previous['end'] ?? '' );
			}

			$windows = array();
			foreach ( $ranges as $range ) {
				$from = self::local_day_to_gmt( (string) $range[0], '00:00:00' );
				$to   = self::local_day_to_gmt( (string) $range[1], '23:59:59' );
				if ( null === $from || null === $to ) {
					return $fallback;
				}
				$windows[] = array(
					'key'  => $range[0] . '..' . $range[1],
					'from' => $from,
					'to'   => $to + 1,
				);
			}
			return $windows;
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	/**
	 * A site-local Y-m-d day plus a time, as a GMT Unix timestamp.
	 *
	 * @param string $day  Y-m-d in the site's timezone.
	 * @param string $time H:i:s.
	 */
	private static function local_day_to_gmt( string $day, string $time ): ?int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return null;
		}
		$date = date_create_immutable_from_format( 'Y-m-d H:i:s', $day . ' ' . $time, wp_timezone() );
		return false === $date ? null : $date->getTimestamp();
	}

	/**
	 * Clear the cached gap probe (import batches ran, Analytics toggled).
	 */
	public static function clear_cache(): void {
		delete_transient( self::GAP_TRANSIENT );
	}

	/**
	 * Probe for orders that should be in wc_order_stats but are not, inside the requested windows.
	 *
	 * Orders are matched on the date the tools report them by (see date_basis()). Each window is
	 * checked from its start up to its end or an hour ago, whichever is earlier (new orders sync
	 * asynchronously). There is no clamp to the oldest synced order: a store that imported
	 * only recent history still works for queries inside the imported range, and a query reaching
	 * into un-imported dates is refused. Uses the type/status/date indexes with LIMIT 1. Cached per
	 * set of windows and date basis: "no gap" for 6 hours (15 minutes when a window reaches into the last hour), a
	 * gap for 15 minutes, a failed read (analytics_unreadable) for 5 minutes; cleared when import
	 * batches run.
	 *
	 * @param array<int, array{key: string, from: int, to: int}>|null $windows Windows to check; null for the default period.
	 * @return string|null import_not_run, orders_not_imported, analytics_unreadable, or null.
	 */
	public static function sync_gap( ?array $windows = null ): ?string {
		$windows = $windows ?? self::windows();
		$basis   = self::date_basis();
		$hash    = self::cache_key( $windows, $basis );
		$now     = time();

		$cache = get_transient( self::GAP_TRANSIENT );
		$cache = is_array( $cache ) ? $cache : array();
		if ( isset( $cache[ $hash ]['reason'], $cache[ $hash ]['expires'] ) && $cache[ $hash ]['expires'] > $now ) {
			return 'none' === $cache[ $hash ]['reason'] ? null : (string) $cache[ $hash ]['reason'];
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a transient.
		// Oldest synced order: only used to tell "import never ran" from "some orders missing".
		$oldest_local = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(date_created) FROM %i', $wpdb->prefix . 'wc_order_stats' ) );
		$query_failed = '' !== $wpdb->last_error;
		// phpcs:enable

		if ( $query_failed ) {
			// wc_order_stats unreadable: completeness can't be checked.
			$reason = 'analytics_unreadable';
		} else {
			$reason = null;
			$upper  = $now - self::SYNC_GRACE_SECONDS;
			foreach ( $windows as $window ) {
				$to = min( (int) $window['to'], $upper );
				if ( (int) $window['from'] >= $to ) {
					continue;
				}
				$missing = self::recent_order_missing( (int) $window['from'], $to, $basis );
				if ( null === $missing ) {
					$reason = 'analytics_unreadable';
					break;
				}
				if ( $missing ) {
					$reason = null === $oldest_local ? 'import_not_run' : 'orders_not_imported';
					break;
				}
			}
		}

		// A window reaching into the sync grace (today, last_7_days, ...) has unchecked recent orders:
		// re-check it soon rather than trusting "none" for hours.
		$reaches_grace = false;
		foreach ( $windows as $window ) {
			$reaches_grace = $reaches_grace || (int) $window['to'] > $now - self::SYNC_GRACE_SECONDS;
		}
		if ( null === $reason ) {
			$ttl = $reaches_grace ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS;
		} elseif ( 'analytics_unreadable' === $reason ) {
			$ttl = 5 * MINUTE_IN_SECONDS;
		} else {
			$ttl = 15 * MINUTE_IN_SECONDS;
		}
		self::cache_result( $cache, $hash, $reason, $ttl );

		return $reason;
	}

	/**
	 * Store one probe result in the gap map, dropping expired and oldest entries.
	 *
	 * @param array<string, array{reason: string, expires: int}> $cache  Current map.
	 * @param string                                             $hash   Windows hash.
	 * @param string|null                                        $reason Reason, null for none.
	 * @param int                                                $ttl    Seconds.
	 */
	private static function cache_result( array $cache, string $hash, ?string $reason, int $ttl ): void {
		$now   = time();
		$cache = array_filter(
			$cache,
			static function ( $entry ) use ( $now ): bool {
				return is_array( $entry ) && isset( $entry['expires'] ) && $entry['expires'] > $now;
			}
		);
		unset( $cache[ $hash ] );
		$cache[ $hash ] = array(
			'reason'  => null === $reason ? 'none' : $reason,
			'expires' => $now + $ttl,
		);
		if ( count( $cache ) > self::CACHE_ENTRIES ) {
			$cache = array_slice( $cache, -self::CACHE_ENTRIES, null, true );
		}
		set_transient( self::GAP_TRANSIENT, $cache, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * The cached probe result for an analytics call's windows (for tests and diagnostics).
	 *
	 * @param array<string, mixed> $input   The analytics call's input.
	 * @param string               $ability Ability name, when known.
	 * @return string|null 'none', a reason, or null when not cached.
	 */
	public static function cached_reason( array $input = array(), string $ability = '' ): ?string {
		$cache = get_transient( self::GAP_TRANSIENT );
		$hash  = self::cache_key( self::windows( $input, $ability ), self::date_basis() );
		return is_array( $cache ) && isset( $cache[ $hash ]['reason'] ) ? (string) $cache[ $hash ]['reason'] : null;
	}

	/**
	 * The date the analytics tools report orders on: WooCommerce Analytics' "Date type" setting,
	 * read through the bundled package's resolver so the guard and the tools always agree.
	 *
	 * @return string date_created, date_paid or date_completed.
	 */
	public static function date_basis(): string {
		$service = self::SERVICE_CLASS;
		if ( class_exists( $service ) && method_exists( $service, 'get_date_column' ) ) {
			$basis = (string) $service::get_date_column();
		} else {
			$basis = (string) get_option( 'woocommerce_date_type', 'date_paid' );
		}
		return in_array( $basis, array( 'date_created', 'date_paid', 'date_completed' ), true ) ? $basis : 'date_paid';
	}

	/**
	 * Statuses whose report date is the paid/completed date (the rest use the creation date), as
	 * the bundled package defines them: WooCommerce's paid statuses plus refunded.
	 *
	 * @return array<int, string> Prefixed statuses (wc-processing, ...).
	 */
	private static function dated_statuses(): array {
		$service = self::SERVICE_CLASS;
		if ( class_exists( $service ) && method_exists( $service, 'get_paid_statuses' ) ) {
			$paid = (array) $service::get_paid_statuses();
		} else {
			$paid = array_map(
				static function ( $status ): string {
					return 'wc-' . $status;
				},
				function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' )
			);
		}
		return array_values( array_unique( array_map( 'strval', array_merge( $paid, array( 'wc-refunded' ) ) ) ) );
	}

	/**
	 * Gap-cache key for a set of windows: the windows, the date basis and the dated statuses, so a
	 * changed "Date type" setting (or paid-status filter) is never answered from the old result.
	 * Public for tests and diagnostics.
	 *
	 * @param array<int, array{key: string, from: int, to: int}> $windows Windows.
	 * @param string                                             $basis   Date basis.
	 */
	public static function cache_key( array $windows, string $basis ): string {
		return md5( implode( '|', array_column( $windows, 'key' ) ) . '#' . $basis . '#' . implode( ',', self::dated_statuses() ) );
	}

	/**
	 * Whether a counted order whose report date falls between two GMT timestamps is missing from
	 * wc_order_stats. Matches the bundled package's date semantics: under date_paid / date_completed,
	 * paid and refunded orders are dated by that column (an order without one is not in the report)
	 * and other counted statuses (on-hold) by their creation date. Paid/completed dates have no index,
	 * so those bases scan the store's counted orders; the result is cached by the caller.
	 * (With wc_order_stats empty this is simply "any counted order in the window".)
	 *
	 * @param int    $from_gmt Lower bound, Unix timestamp (inclusive).
	 * @param int    $to_gmt   Upper bound, Unix timestamp (exclusive).
	 * @param string $basis    Date basis (see date_basis()).
	 * @return bool|null Null when the query failed (a failed get_var() also returns null).
	 */
	private static function recent_order_missing( int $from_gmt, int $to_gmt, string $basis ): ?bool {
		global $wpdb;

		$stats = $wpdb->prefix . 'wc_order_stats';
		$hpos  = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$counted   = self::COUNTED_STATUSES;
		$counted_p = implode( ', ', array_fill( 0, count( $counted ), '%s' ) );
		$from      = gmdate( 'Y-m-d H:i:s', $from_gmt );
		$to        = gmdate( 'Y-m-d H:i:s', $to_gmt );
		// post_date (site-local) is the indexed column in posts.type_status_date.
		$from_local = get_date_from_gmt( $from );
		$to_local   = get_date_from_gmt( $to );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by the caller.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- only %s placeholder lists are interpolated; $sql is prepared below.
		if ( 'date_created' === $basis ) {
			if ( $hpos ) {
				$sql  = "SELECT o.id FROM %i o LEFT JOIN %i s ON s.order_id = o.id WHERE s.order_id IS NULL AND o.type = %s AND o.status IN ({$counted_p}) AND o.date_created_gmt >= %s AND o.date_created_gmt < %s LIMIT 1";
				$args = array_merge( array( $wpdb->prefix . 'wc_orders', $stats, 'shop_order' ), $counted, array( $from, $to ) );
			} else {
				$sql  = "SELECT p.ID FROM %i p LEFT JOIN %i s ON s.order_id = p.ID WHERE s.order_id IS NULL AND p.post_type = %s AND p.post_status IN ({$counted_p}) AND p.post_date >= %s AND p.post_date < %s LIMIT 1";
				$args = array_merge( array( $wpdb->posts, $stats, 'shop_order' ), $counted, array( $from_local, $to_local ) );
			}
		} else {
			$dated   = self::dated_statuses();
			$dated_p = implode( ', ', array_fill( 0, count( $dated ), '%s' ) );
			if ( $hpos ) {
				$sql  = "SELECT o.id FROM %i o LEFT JOIN %i s ON s.order_id = o.id LEFT JOIN %i d ON d.order_id = o.id WHERE s.order_id IS NULL AND o.type = %s AND o.status IN ({$counted_p}) AND ( ( o.status IN ({$dated_p}) AND d.%i >= %s AND d.%i < %s ) OR ( o.status NOT IN ({$dated_p}) AND o.date_created_gmt >= %s AND o.date_created_gmt < %s ) ) LIMIT 1";
				$col  = $basis . '_gmt';
				$args = array_merge( array( $wpdb->prefix . 'wc_orders', $stats, $wpdb->prefix . 'wc_order_operational_data', 'shop_order' ), $counted, $dated, array( $col, $from, $col, $to ), $dated, array( $from, $to ) );
			} else {
				// _date_paid / _date_completed hold Unix timestamps (WooCommerce 3.0+).
				$sql  = "SELECT p.ID FROM %i p LEFT JOIN %i s ON s.order_id = p.ID LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = %s WHERE s.order_id IS NULL AND p.post_type = %s AND p.post_status IN ({$counted_p}) AND ( ( p.post_status IN ({$dated_p}) AND m.meta_value REGEXP '^[0-9]+$' AND CAST(m.meta_value AS UNSIGNED) >= %d AND CAST(m.meta_value AS UNSIGNED) < %d ) OR ( p.post_status NOT IN ({$dated_p}) AND p.post_date >= %s AND p.post_date < %s ) ) LIMIT 1";
				$args = array_merge( array( $wpdb->posts, $stats, $wpdb->postmeta, '_' . $basis, 'shop_order' ), $counted, $dated, array( $from_gmt, $to_gmt ), $dated, array( $from_local, $to_local ) );
			}
		}
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable

		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		return null !== $found;
	}
}
