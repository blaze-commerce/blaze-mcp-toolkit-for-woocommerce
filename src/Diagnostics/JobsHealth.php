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
	 * @param int $days Look-back window for failures.
	 * @return array{available:bool,failed:int,past_due:int,money_related_failed:int,by_hook:array<int,array{hook:string,failed:int,past_due:int,money_related:bool}>,by_hook_complete:bool}
	 */
	public static function summary( int $days = 7 ): array {
		$empty = array(
			'available'            => false,
			'failed'               => 0,
			'past_due'             => 0,
			'money_related_failed' => 0,
			'by_hook'              => array(),
			'by_hook_complete'     => true,
		);
		if ( ! self::available() ) {
			return $empty;
		}

		$days = max( 1, $days );
		$now  = time();

		$failed_query   = array(
			'status'           => 'failed',
			'modified'         => $now - $days * DAY_IN_SECONDS,
			'modified_compare' => '>=',
		);
		$past_due_query = array(
			'status'       => 'pending',
			'date'         => $now,
			'date_compare' => '<',
		);

		$failed_actions   = self::sample( $failed_query );
		$past_due_actions = self::sample( $past_due_query );
		$failed_total     = self::count( $failed_query, count( $failed_actions ) );
		$past_due_total   = self::count( $past_due_query, count( $past_due_actions ) );

		$hooks = array();
		foreach ( array( 'failed' => $failed_actions, 'past_due' => $past_due_actions ) as $key => $actions ) {
			foreach ( $actions as $action ) {
				$hook = is_object( $action ) && method_exists( $action, 'get_hook' ) ? (string) $action->get_hook() : '';
				if ( '' === $hook ) {
					continue;
				}
				$hook = substr( $hook, 0, self::MAX_HOOK_CHARS );
				if ( ! isset( $hooks[ $hook ] ) ) {
					$hooks[ $hook ] = array(
						'hook'          => $hook,
						'failed'        => 0,
						'past_due'      => 0,
						'money_related' => self::is_money_related( $hook ),
					);
				}
				++$hooks[ $hook ][ $key ];
			}
		}

		$money_failed = 0;
		foreach ( $hooks as $row ) {
			if ( $row['money_related'] ) {
				$money_failed += $row['failed'];
			}
		}

		$hooks = array_values( $hooks );
		usort(
			$hooks,
			static fn( array $a, array $b ): int => array( (int) ( $b['money_related'] && $b['failed'] > 0 ), $b['failed'], $b['past_due'], $a['hook'] )
				<=> array( (int) ( $a['money_related'] && $a['failed'] > 0 ), $a['failed'], $a['past_due'], $b['hook'] )
		);

		return array(
			'available'            => true,
			'failed'               => $failed_total,
			'past_due'             => $past_due_total,
			'money_related_failed' => $money_failed,
			'by_hook'              => array_slice( $hooks, 0, self::MAX_HOOKS ),
			'by_hook_complete'     => count( $failed_actions ) >= $failed_total && count( $past_due_actions ) >= $past_due_total && count( $hooks ) <= self::MAX_HOOKS,
		);
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
