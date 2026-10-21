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
	 * Statuses WooCommerce Analytics reports on that must appear in wc_order_stats.
	 */
	private const COUNTED_STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' );

	/**
	 * Add the filter (core WordPress 7.1+; on older cores the filter never fires).
	 */
	public static function register(): void {
		add_filter( 'wp_pre_execute_ability', array( self::class, 'pre_execute' ), 10, 4 );
	}

	/**
	 * Short-circuit wc-analytics/* when the figures would be incomplete.
	 *
	 * @param mixed  $pre     Sentinel, or a value from an earlier filter.
	 * @param string $name    Ability name.
	 * @param mixed  $input   Raw input.
	 * @param mixed  $ability The WP_Ability instance.
	 * @return mixed
	 */
	public static function pre_execute( $pre, $name = '', $input = null, $ability = null ) {
		if ( ! is_string( $name ) || ! str_starts_with( $name, 'wc-analytics/' ) || in_array( $name, self::EXEMPT, true ) ) {
			return $pre;
		}

		// This filter runs before the permission check: never tell an unauthorised caller anything.
		if ( ! $ability instanceof \WP_Ability || true !== $ability->check_permissions( $input ) ) {
			return $pre;
		}

		$reason = self::unavailable_reason();
		if ( null === $reason ) {
			return $pre;
		}

		return new \WP_Error(
			self::ERROR_CODE,
			__( 'WooCommerce Analytics is turned off or hasn\'t finished importing historical orders, so these figures would be incomplete. Turn on Analytics under WooCommerce > Settings > Advanced > Features, or finish the import under Analytics > Settings, then try again.', 'blaze-mcp-toolkit-for-woocommerce' ),
			array(
				'status' => 503,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Why analytics data is unreliable right now, or null when it is fine.
	 *
	 * @return string|null One of analytics_disabled, import_in_progress, orders_not_imported.
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

		return self::has_unimported_orders() ? 'orders_not_imported' : null;
	}

	/**
	 * Whether any reportable order older than the sync grace period is missing from wc_order_stats.
	 * One indexed anti-join with LIMIT 1, cached for 15 minutes.
	 */
	public static function has_unimported_orders(): bool {
		$cached = get_transient( self::GAP_TRANSIENT );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		global $wpdb;

		$stats  = $wpdb->prefix . 'wc_order_stats';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::SYNC_GRACE_SECONDS );
		$hpos   = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		list( $s1, $s2, $s3, $s4 ) = self::COUNTED_STATUSES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in a transient above.
		if ( $hpos ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT o.id FROM %i o LEFT JOIN %i s ON s.order_id = o.id WHERE s.order_id IS NULL AND o.type = %s AND o.status IN (%s, %s, %s, %s) AND o.date_created_gmt < %s LIMIT 1',
					$wpdb->prefix . 'wc_orders',
					$stats,
					'shop_order',
					$s1,
					$s2,
					$s3,
					$s4,
					$cutoff
				)
			);
		} else {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT p.ID FROM %i p LEFT JOIN %i s ON s.order_id = p.ID WHERE s.order_id IS NULL AND p.post_type = %s AND p.post_status IN (%s, %s, %s, %s) AND p.post_date_gmt < %s LIMIT 1',
					$wpdb->posts,
					$stats,
					'shop_order',
					$s1,
					$s2,
					$s3,
					$s4,
					$cutoff
				)
			);
		}
		// phpcs:enable

		// A failed query (e.g. wc_order_stats missing) also means the figures can't be trusted.
		$gap = null !== $found || '' !== $wpdb->last_error;
		set_transient( self::GAP_TRANSIENT, $gap ? 'yes' : 'no', 15 * MINUTE_IN_SECONDS );

		return $gap;
	}
}
