<?php
/**
 * Removes everything the plugin created: tables, options, transients,
 * capability, user meta and the daily cleanup job. Runs for every site on a
 * multisite network.
 *
 * @package Blaze\McpToolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up the current site.
 */
function blzmcp_uninstall_site(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'blzmcp_audit' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'blzmcp_events' ) );

	// Options and transients: the settings option plus every blzmcp_ transient
	// (cached tool results blzmcp_c_*, anonymous-denial flags blzmcp_anon_*,
	// blzmcp_analytics_gap, blzmcp_missing_pkg_logged, blzmcp_schedule_pending).
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'blzmcp_' ) . '%',
			$wpdb->esc_like( '_transient_blzmcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_blzmcp_' ) . '%'
		)
	);
	// phpcs:enable

	// Also clear through the API so a persistent object cache drops them.
	delete_option( 'blzmcp_settings' );
	foreach ( array( 'blzmcp_schedule_pending', 'blzmcp_analytics_gap', 'blzmcp_missing_pkg_logged' ) as $blzmcp_transient ) {
		delete_transient( $blzmcp_transient );
	}

	foreach ( wp_roles()->role_objects as $blzmcp_role ) {
		$blzmcp_role->remove_cap( 'blzmcp_view_diagnostics' );
	}

	blzmcp_uninstall_scheduled_actions();
}

/**
 * Remove the daily cleanup job. Works whether or not WooCommerce (and so
 * Action Scheduler) is loaded during uninstall: the API is used when it is
 * ready, then any rows left in the Action Scheduler tables are deleted.
 */
function blzmcp_uninstall_scheduled_actions(): void {
	global $wpdb;

	if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
		try {
			as_unschedule_all_actions( 'blzmcp_daily_cleanup', array(), 'blzmcp' );
		} catch ( \Throwable $e ) {
			unset( $e ); // Fall through to the direct clean-up below.
		}
	}

	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$groups  = $wpdb->prefix . 'actionscheduler_groups';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) !== $actions ) {
		return;
	}

	$has_groups = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $groups ) ) ) === $groups;
	$group_id   = $has_groups ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, 'blzmcp' ) ) : 0;

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs ) ) ) === $logs ) {
		$wpdb->query(
			$wpdb->prepare(
				'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.hook = %s OR ( %d > 0 AND a.group_id = %d )',
				$logs,
				$actions,
				'blzmcp_daily_cleanup',
				$group_id,
				$group_id
			)
		);
	}

	// Every status: pending, and the complete/canceled rows the scheduler API keeps.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE hook = %s OR ( %d > 0 AND group_id = %d )',
			$actions,
			'blzmcp_daily_cleanup',
			$group_id,
			$group_id
		)
	);

	if ( $group_id > 0 ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $groups, $group_id ) );
	}
	// phpcs:enable
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $blzmcp_site_id ) {
		switch_to_blog( (int) $blzmcp_site_id );
		blzmcp_uninstall_site();
		restore_current_blog();
	}
} else {
	blzmcp_uninstall_site();
}

// User meta is network-wide: the dismissed WooCommerce for Claude notice.
delete_metadata( 'user', 0, 'blzmcp_wcfc_notice_dismissed', '', true );
