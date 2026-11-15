<?php
/**
 * Failed and past-due Action Scheduler jobs.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only summary of the store's background job queue (all groups).
 * Never loads more than SAMPLE actions per status.
 */
final class JobsHealth {

	/** Hooks that touch money, stock or customer email. */
	public const MONEY_PATTERN = '/woocommerce_.*email|woocommerce_scheduled_subscription|woocommerce_deliver_webhook|stock|renewal/i';

	/** Actions loaded per status to build the per-hook breakdown. */
	private const SAMPLE = 200;

	/** Max rows in by_hook. */
	private const MAX_HOOKS = 50;

	/** Max characters of a hook name. */
	private const MAX_HOOK_CHARS = 191;

	/** A pending job only counts as past due once it is this late (normal queue-runner lag). */
	public const PAST_DUE_GRACE = HOUR_IN_SECONDS;

	/**
	 * Whether a hook name counts as money-related.
	 *
	 * @param string $hook Hook name.
	 */
	public static function is_money_related( string $hook ): bool {
		return 1 === preg_match( self::MONEY_PATTERN, $hook );
	}

	/**
	 * Failed jobs in the last $days and pending jobs more than an hour past their scheduled time.
	 *
	 * With the default database store, per-hook figures come from one grouped query and
	 * are exact. Otherwise they come from the newest SAMPLE actions per status and
	 * by_hook_exact is false. A failed database read gives available false and read_failed true
	 * (never zero counts that look healthy).
	 *
	 * @param int $days Look-back window for failures.
	 * @return array{available:bool,read_failed:bool,failed:int,past_due:int,money_related_failed:int,by_hook:array<int,array{hook:string,failed:int,past_due:int,money_related:bool}>,by_hook_exact:bool}
	 */
	public static function summary( int $days = 7 ): array {
		$unavailable = array(
			'available'            => false,
			'read_failed'          => false,
			'failed'               => 0,
			'past_due'             => 0,
			'money_related_failed' => 0,
			'by_hook'              => array(),
			'by_hook_exact'        => true,
		);
		if ( ! self::available() ) {
			return $unavailable;
		}

		$days  = max( 1, $days );
		$now   = time();
		$since = $now - $days * DAY_IN_SECONDS;

		$failed_query   = array(
			'status'           => 'failed',
			'modified'         => $since,
			'modified_compare' => '>=',
		);
		$past_due_query = self::past_due_query( $now );

		$rows = self::grouped_counts( $since, $now - self::PAST_DUE_GRACE );
		if ( false === $rows ) {
			return array( 'read_failed' => true ) + $unavailable;
		}
		$exact = null !== $rows;
		if ( null === $rows ) {
			$rows = self::sampled_counts( $failed_query, $past_due_query );
			if ( false === $rows ) {
				return array( 'read_failed' => true ) + $unavailable;
			}
		}

		$hooks = array();
		foreach ( $rows as $row ) {
			$hook = substr( $row['hook'], 0, self::MAX_HOOK_CHARS );
			if ( '' === $hook ) {
				continue;
			}
			if ( ! isset( $hooks[ $hook ] ) ) {
				$hooks[ $hook ] = array(
					'hook'          => $hook,
					'failed'        => 0,
					'past_due'      => 0,
					'money_related' => self::is_money_related( $hook ),
				);
			}
			$hooks[ $hook ][ $row['key'] ] += $row['count'];
		}

		$money_failed = 0;
		$failed_sum   = 0;
		$past_due_sum = 0;
		foreach ( $hooks as $row ) {
			$failed_sum   += $row['failed'];
			$past_due_sum += $row['past_due'];
			if ( $row['money_related'] ) {
				$money_failed += $row['failed'];
			}
		}

		$failed_total   = $exact ? $failed_sum : self::count( $failed_query, $failed_sum );
		$past_due_total = $exact ? $past_due_sum : self::count( $past_due_query, $past_due_sum );
		if ( null === $failed_total || null === $past_due_total ) {
			return array( 'read_failed' => true ) + $unavailable;
		}

		$hooks = array_values( $hooks );
		usort(
			$hooks,
			static fn( array $a, array $b ): int => array( (int) ( $b['money_related'] && $b['failed'] > 0 ), (int) $b['money_related'], $b['failed'] + $b['past_due'], $a['hook'] )
				<=> array( (int) ( $a['money_related'] && $a['failed'] > 0 ), (int) $a['money_related'], $a['failed'] + $a['past_due'], $b['hook'] )
		);

		return array(
			'available'            => true,
			'read_failed'          => false,
			'failed'               => $failed_total,
			'past_due'             => $past_due_total,
			'money_related_failed' => $money_failed,
			'by_hook'              => array_slice( $hooks, 0, self::MAX_HOOKS ),
			'by_hook_exact'        => $exact,
		);
	}

	/**
	 * Failed actions for payment health: hooks named in $hooks or in a group named in $groups,
	 * whose last attempt falls in [$since, $now). Additive; summary() is unchanged.
	 *
	 * The database store gives one grouped query with exact counts. Any other store gives a
	 * bounded sample of the newest SAMPLE failed actions, flagged exact false (a lower bound).
	 * Never runs, cancels or reschedules an action and never reads arguments or log messages.
	 *
	 * @param string[] $hooks  Literal hook names.
	 * @param string[] $groups Literal group slugs.
	 * @param int      $since  Window start (Unix time).
	 * @param int      $now    Window end, exclusive.
	 * @return array{available:bool,read_failed:bool,exact:bool,rows:array<int,array{hook:string,group:string,count:int}>}
	 */
	public static function payment_failures( array $hooks, array $groups, int $since, int $now ): array {
		$out = array(
			'available'   => false,
			'read_failed' => false,
			'exact'       => false,
			'rows'        => array(),
		);
		if ( array() === $hooks && array() === $groups ) {
			$out['available'] = true;
			$out['exact']     = true;
			return $out;
		}
		if ( ! self::available() ) {
			return $out;
		}
		if ( class_exists( '\ActionScheduler_DBStore' ) && method_exists( '\ActionScheduler', 'store' ) && \ActionScheduler::store() instanceof \ActionScheduler_DBStore ) {
			$rows = self::payment_failures_db( $hooks, $groups, $since, $now );
			if ( false === $rows ) {
				return array( 'read_failed' => true ) + $out;
			}
			return array(
				'available'   => true,
				'read_failed' => false,
				'exact'       => true,
				'rows'        => $rows,
			);
		}

		$actions = self::sample(
			array(
				'status'           => 'failed',
				'modified'         => $since,
				'modified_compare' => '>=',
			)
		);
		if ( false === $actions ) {
			return array( 'read_failed' => true ) + $out;
		}
		$counts = array();
		foreach ( $actions as $action ) {
			$hook  = is_object( $action ) && method_exists( $action, 'get_hook' ) ? (string) $action->get_hook() : '';
			$group = is_object( $action ) && method_exists( $action, 'get_group' ) ? (string) $action->get_group() : '';
			if ( ! in_array( $hook, $hooks, true ) && ! in_array( $group, $groups, true ) ) {
				continue;
			}
			$key            = $hook . "\0" . $group;
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		foreach ( $counts as $key => $count ) {
			list( $hook, $group ) = explode( "\0", (string) $key, 2 );
			$out['rows'][]        = array(
				'hook'  => $hook,
				'group' => $group,
				'count' => $count,
			);
		}
		$out['available'] = true;
		return $out;
	}

	/**
	 * One grouped query over the database store's tables, or false when a read failed.
	 *
	 * @param string[] $hooks  Hook names.
	 * @param string[] $groups Group slugs.
	 * @param int      $since  Start.
	 * @param int      $now    End (exclusive).
	 * @return array<int,array{hook:string,group:string,count:int}>|false
	 */
	public static function payment_failures_db( array $hooks, array $groups, int $since, int $now ): array|false {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$table_g = $wpdb->prefix . 'actionscheduler_groups';
		$hooks   = array() === $hooks ? array( '' ) : array_values( $hooks );
		$groups  = array() === $groups ? array( '' ) : array_values( $groups );
		$hp      = implode( ', ', array_fill( 0, count( $hooks ), '%s' ) );
		$gp      = implode( ', ', array_fill( 0, count( $groups ), '%s' ) );
		$wpdb->last_error = '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- only %s placeholder lists are interpolated; values passed as one array.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped read; the ability caches its result.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.hook AS hook, COALESCE(g.slug, '') AS grp, COUNT(*) AS c FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE a.status = %s AND a.last_attempt_gmt >= %s AND a.last_attempt_gmt < %s AND ( a.hook IN ({$hp}) OR g.slug IN ({$gp}) ) GROUP BY a.hook, g.slug",
				array_merge( array( $actions, $table_g, 'failed', gmdate( 'Y-m-d H:i:s', $since ), gmdate( 'Y-m-d H:i:s', $now ) ), $hooks, $groups )
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( '' !== $wpdb->last_error || ! is_array( $results ) ) {
			return false;
		}
		$rows = array();
		foreach ( $results as $r ) {
			$rows[] = array(
				'hook'  => (string) $r['hook'],
				'group' => (string) $r['grp'],
				'count' => (int) $r['c'],
			);
		}
		return $rows;
	}

	/**
	 * Exact per-hook counts from the Action Scheduler table, null when the database store is
	 * not in use, or false when a read failed ($wpdb->last_error is set after the query).
	 *
	 * @param int $since  Failed since (Unix time).
	 * @param int $cutoff Pending jobs scheduled before this are past due (Unix time).
	 * @return array<int,array{hook:string,key:string,count:int}>|null|false
	 */
	private static function grouped_counts( int $since, int $cutoff ): array|null|false {
		global $wpdb;
		if ( ! class_exists( '\ActionScheduler_DBStore' ) || ! method_exists( '\ActionScheduler', 'store' ) || ! ( \ActionScheduler::store() instanceof \ActionScheduler_DBStore ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only existence check; result is cached by the ability.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		if ( $table !== $found ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped read; the ability caches its result.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT hook, status, COUNT(*) AS c FROM %i WHERE (status = %s AND last_attempt_gmt >= %s) OR (status = %s AND scheduled_date_gmt < %s) GROUP BY hook, status',
				$table,
				'failed',
				gmdate( 'Y-m-d H:i:s', $since ),
				'pending',
				gmdate( 'Y-m-d H:i:s', $cutoff )
			),
			ARRAY_A
		);
		// get_results() returns an empty array when a query fails, so check the error, not the type.
		if ( '' !== $wpdb->last_error || ! is_array( $results ) ) {
			return false;
		}
		$rows = array();
		foreach ( $results as $r ) {
			$rows[] = array(
				'hook'  => (string) $r['hook'],
				'key'   => 'failed' === $r['status'] ? 'failed' : 'past_due',
				'count' => (int) $r['c'],
			);
		}
		return $rows;
	}

	/**
	 * Pending jobs scheduled more than PAST_DUE_GRACE before now.
	 *
	 * @param int $now Now (Unix time).
	 * @return array<string,mixed>
	 */
	private static function past_due_query( int $now ): array {
		return array(
			'status'       => 'pending',
			'date'         => $now - self::PAST_DUE_GRACE,
			'date_compare' => '<',
		);
	}

	/**
	 * Per-hook counts from the newest SAMPLE actions per status (non-database stores).
	 *
	 * @param array<string,mixed> $failed_query   Failed query.
	 * @param array<string,mixed> $past_due_query Past-due query.
	 * @return array<int,array{hook:string,key:string,count:int}>|false False when a read failed.
	 */
	private static function sampled_counts( array $failed_query, array $past_due_query ): array|false {
		$rows = array();
		foreach ( array(
			'failed'   => $failed_query,
			'past_due' => $past_due_query,
		) as $key => $query ) {
			$actions = self::sample( $query );
			if ( false === $actions ) {
				return false;
			}
			foreach ( $actions as $action ) {
				$hook = is_object( $action ) && method_exists( $action, 'get_hook' ) ? (string) $action->get_hook() : '';
				$rows[] = array(
					'hook'  => $hook,
					'key'   => $key,
					'count' => 1,
				);
			}
		}
		return $rows;
	}

	/**
	 * Whether Action Scheduler is loaded and ready.
	 */
	private static function available(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler' ) ) {
			return false;
		}
		if ( method_exists( '\ActionScheduler', 'is_initialized' ) && ! \ActionScheduler::is_initialized() ) {
			return false;
		}
		return true;
	}

	/**
	 * Up to SAMPLE matching actions, newest first.
	 *
	 * @param array<string,mixed> $query Query args.
	 * @return array<int|string,object>|false False when the store's query failed.
	 */
	private static function sample( array $query ): array|false {
		global $wpdb;
		$wpdb->last_error = '';
		$actions          = as_get_scheduled_actions(
			array_merge(
				$query,
				array(
					'per_page' => self::SAMPLE,
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			)
		);
		// A failed query leaves an empty list; the error is what tells it apart from "no jobs".
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		return is_array( $actions ) ? $actions : array();
	}

	/**
	 * Exact count via the store's count query; falls back to the sample size. Null when the
	 * count query failed.
	 *
	 * @param array<string,mixed> $query    Query args (timestamps).
	 * @param int                 $fallback Sample size.
	 */
	private static function count( array $query, int $fallback ): ?int {
		global $wpdb;
		if ( ! method_exists( '\ActionScheduler', 'store' ) ) {
			return $fallback;
		}
		foreach ( array( 'date', 'modified' ) as $key ) {
			if ( isset( $query[ $key ] ) ) {
				$query[ $key ] = new \DateTime( '@' . (int) $query[ $key ], new \DateTimeZone( 'UTC' ) );
			}
		}
		$wpdb->last_error = '';
		$count            = \ActionScheduler::store()->query_actions( $query, 'count' );
		if ( '' !== $wpdb->last_error ) {
			return null;
		}
		return is_numeric( $count ) ? max( $fallback, (int) $count ) : $fallback;
	}
}
