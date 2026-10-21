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

	/**
	 * Whether a hook name counts as money-related.
	 *
	 * @param string $hook Hook name.
	 */
	public static function is_money_related( string $hook ): bool {
		return 1 === preg_match( self::MONEY_PATTERN, $hook );
	}

	/**
	 * Failed jobs in the last $days and pending jobs whose scheduled time has passed.
	 *
	 * With the default database store, per-hook figures come from one grouped query and
	 * are exact. Otherwise they come from the newest SAMPLE actions per status and
	 * by_hook_exact is false.
	 *
	 * @param int $days Look-back window for failures.
	 * @return array{available:bool,failed:int,past_due:int,money_related_failed:int,by_hook:array<int,array{hook:string,failed:int,past_due:int,money_related:bool}>,by_hook_exact:bool}
	 */
	public static function summary( int $days = 7 ): array {
		if ( ! self::available() ) {
			return array(
				'available'            => false,
				'failed'               => 0,
				'past_due'             => 0,
				'money_related_failed' => 0,
				'by_hook'              => array(),
				'by_hook_exact'        => true,
			);
		}

		$days  = max( 1, $days );
		$now   = time();
		$since = $now - $days * DAY_IN_SECONDS;

		$failed_query   = array(
			'status'           => 'failed',
			'modified'         => $since,
			'modified_compare' => '>=',
		);
		$past_due_query = array(
			'status'       => 'pending',
			'date'         => $now,
			'date_compare' => '<',
		);

		$rows  = self::grouped_counts( $since, $now );
		$exact = null !== $rows;
		if ( null === $rows ) {
			$rows = self::sampled_counts( $failed_query, $past_due_query );
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

		$hooks = array_values( $hooks );
		usort(
			$hooks,
			static fn( array $a, array $b ): int => array( (int) ( $b['money_related'] && $b['failed'] > 0 ), (int) $b['money_related'], $b['failed'] + $b['past_due'], $a['hook'] )
				<=> array( (int) ( $a['money_related'] && $a['failed'] > 0 ), (int) $a['money_related'], $a['failed'] + $a['past_due'], $b['hook'] )
		);

		return array(
			'available'            => true,
			'failed'               => $exact ? $failed_sum : self::count( $failed_query, $failed_sum ),
			'past_due'             => $exact ? $past_due_sum : self::count( $past_due_query, $past_due_sum ),
			'money_related_failed' => $money_failed,
			'by_hook'              => array_slice( $hooks, 0, self::MAX_HOOKS ),
			'by_hook_exact'        => $exact,
		);
	}

	/**
	 * Exact per-hook counts from the Action Scheduler table, or null when the
	 * database store is not in use.
	 *
	 * @param int $since Failed since (Unix time).
	 * @param int $now   Now (Unix time).
	 * @return array<int,array{hook:string,key:string,count:int}>|null
	 */
	private static function grouped_counts( int $since, int $now ): ?array {
		global $wpdb;
		if ( ! class_exists( '\ActionScheduler_DBStore' ) || ! method_exists( '\ActionScheduler', 'store' ) || ! ( \ActionScheduler::store() instanceof \ActionScheduler_DBStore ) ) {
			return null;
		}
		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only existence check; result is cached by the ability.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
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
				gmdate( 'Y-m-d H:i:s', $now )
			),
			ARRAY_A
		);
		if ( ! is_array( $results ) ) {
			return null;
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
	 * Per-hook counts from the newest SAMPLE actions per status (non-database stores).
	 *
	 * @param array<string,mixed> $failed_query   Failed query.
	 * @param array<string,mixed> $past_due_query Past-due query.
	 * @return array<int,array{hook:string,key:string,count:int}>
	 */
	private static function sampled_counts( array $failed_query, array $past_due_query ): array {
		$rows = array();
		foreach ( array(
			'failed'   => $failed_query,
			'past_due' => $past_due_query,
		) as $key => $query ) {
			foreach ( self::sample( $query ) as $action ) {
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
	 * @return array<int|string,object>
	 */
	private static function sample( array $query ): array {
		$actions = as_get_scheduled_actions(
			array_merge(
				$query,
				array(
					'per_page' => self::SAMPLE,
					'orderby'  => 'date',
					'order'    => 'DESC',
				)
			)
		);
		return is_array( $actions ) ? $actions : array();
	}

	/**
	 * Exact count via the store's count query; falls back to the sample size.
	 *
	 * @param array<string,mixed> $query    Query args (timestamps).
	 * @param int                 $fallback Sample size.
	 */
	private static function count( array $query, int $fallback ): int {
		if ( ! method_exists( '\ActionScheduler', 'store' ) ) {
			return $fallback;
		}
		foreach ( array( 'date', 'modified' ) as $key ) {
			if ( isset( $query[ $key ] ) ) {
				$query[ $key ] = new \DateTime( '@' . (int) $query[ $key ], new \DateTimeZone( 'UTC' ) );
			}
		}
		$count = \ActionScheduler::store()->query_actions( $query, 'count' );
		return is_numeric( $count ) ? max( $fallback, (int) $count ) : $fallback;
	}
}
