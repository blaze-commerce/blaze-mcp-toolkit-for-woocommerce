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
	 * Transient caching the "orders missing from wc_order_stats" probe.
	 */
	public const GAP_TRANSIENT = 'blzmcp_analytics_gap';

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
		$args['execute_callback'] = static function ( ...$call_args ) use ( $original ) {
			$error = self::unavailable_error();
			if ( null !== $error ) {
				return $error;
			}
			return call_user_func_array( $original, $call_args );
		};

		return $args;
	}

	/**
	 * The error to return instead of incomplete figures, or null when analytics data is fine.
	 */
	public static function unavailable_error(): ?\WP_Error {
		$reason = self::unavailable_reason();
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
			case 'import_in_progress':
			case 'import_not_run':
				return __( 'WooCommerce Analytics hasn\'t finished importing your orders, so these figures would be incomplete. Wait for the import to finish (or start it under Analytics > Settings > Import historical data), then try again.', 'blaze-mcp-toolkit-for-woocommerce' );
			default:
				return __( 'Recent orders haven\'t synced to WooCommerce Analytics, so these figures would be incomplete. WooCommerce\'s background tasks may be stalled: check WooCommerce > Status > Scheduled Actions for pending wc-admin jobs, then try again.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
	}

	/**
	 * Why analytics data is unreliable right now, or null when it is fine.
	 *
	 * @return string|null One of analytics_disabled, import_in_progress, import_not_run, orders_not_imported.
	 */
	public static function unavailable_reason(): ?string {
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

		return self::sync_gap();
	}

	/**
	 * Clear the cached gap probe (import batches ran, Analytics toggled).
	 */
	public static function clear_cache(): void {
		delete_transient( self::GAP_TRANSIENT );
	}

	/**
	 * Probe for orders that should be in wc_order_stats but are not.
	 *
	 * Only looks at a bounded, recent window: orders created in the last 30 days, at least an hour
	 * ago (new orders sync asynchronously), and not before the oldest order Analytics has synced.
	 * Older orders are outside the historical import's window by design and are ignored. Uses the
	 * type/status/date indexes (wc_orders.type_status_date or posts.type_status_date) with LIMIT 1.
	 * Cached: "no gap" for 6 hours, a gap for 15 minutes; cleared when import batches run.
	 *
	 * @return string|null import_not_run, orders_not_imported, or null.
	 */
	public static function sync_gap(): ?string {
		$cached = get_transient( self::GAP_TRANSIENT );
		if ( false !== $cached ) {
			return 'none' === $cached ? null : (string) $cached;
		}

		global $wpdb;

		$now   = time();
		$upper = $now - self::SYNC_GRACE_SECONDS;
		$lower = $now - self::WINDOW_DAYS * DAY_IN_SECONDS;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a transient.
		// Oldest synced order. date_created is indexed (site-local time); date_created_gmt is not.
		$oldest_local = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(date_created) FROM %i', $wpdb->prefix . 'wc_order_stats' ) );
		$query_failed = '' !== $wpdb->last_error;
		// phpcs:enable

		if ( $query_failed ) {
			// wc_order_stats unreadable: the figures can't be trusted.
			$reason = 'orders_not_imported';
		} elseif ( null === $oldest_local ) {
			// Nothing synced at all: only a problem if there are recent orders to report on.
			$reason = self::recent_order_missing( $lower, $upper ) ? 'import_not_run' : null;
		} else {
			$oldest = strtotime( get_gmt_from_date( (string) $oldest_local ) . ' UTC' );
			$lower  = max( $lower, (int) $oldest );
			$reason = $lower < $upper && self::recent_order_missing( $lower, $upper ) ? 'orders_not_imported' : null;
		}

		set_transient( self::GAP_TRANSIENT, null === $reason ? 'none' : $reason, null === $reason ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

		return $reason;
	}

	/**
	 * Whether a counted order created between two GMT timestamps is missing from wc_order_stats.
	 * (With wc_order_stats empty this is simply "any counted order in the window".)
	 *
	 * @param int $from_gmt Lower bound, Unix timestamp (inclusive).
	 * @param int $to_gmt   Upper bound, Unix timestamp (exclusive).
	 */
	private static function recent_order_missing( int $from_gmt, int $to_gmt ): bool {
		global $wpdb;

		$stats = $wpdb->prefix . 'wc_order_stats';
		$hpos  = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		list( $s1, $s2, $s3, $s4 ) = self::COUNTED_STATUSES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached by the caller.
		if ( $hpos ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT o.id FROM %i o LEFT JOIN %i s ON s.order_id = o.id WHERE s.order_id IS NULL AND o.type = %s AND o.status IN (%s, %s, %s, %s) AND o.date_created_gmt >= %s AND o.date_created_gmt < %s LIMIT 1',
					$wpdb->prefix . 'wc_orders',
					$stats,
					'shop_order',
					$s1,
					$s2,
					$s3,
					$s4,
					gmdate( 'Y-m-d H:i:s', $from_gmt ),
					gmdate( 'Y-m-d H:i:s', $to_gmt )
				)
			);
		} else {
			// post_date (site-local) is the indexed column in posts.type_status_date.
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT p.ID FROM %i p LEFT JOIN %i s ON s.order_id = p.ID WHERE s.order_id IS NULL AND p.post_type = %s AND p.post_status IN (%s, %s, %s, %s) AND p.post_date >= %s AND p.post_date < %s LIMIT 1',
					$wpdb->posts,
					$stats,
					'shop_order',
					$s1,
					$s2,
					$s3,
					$s4,
					get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $from_gmt ) ),
					get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $to_gmt ) )
				)
			);
		}
		// phpcs:enable

		return null !== $found;
	}
}
