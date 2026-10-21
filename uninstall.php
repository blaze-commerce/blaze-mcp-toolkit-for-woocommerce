<?php
/**
 * Removes everything the plugin created: tables, option, capability, job.
 *
 * @package Blaze\McpToolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedIdentifierPlaceholder
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'blzmcp_audit' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'blzmcp_events' ) );
// phpcs:enable

delete_option( 'blzmcp_settings' );
delete_transient( 'blzmcp_schedule_pending' );
delete_metadata( 'user', 0, 'blzmcp_wcfc_notice_dismissed', '', true );
delete_transient( 'blzmcp_analytics_gap' );
delete_transient( 'blzmcp_missing_pkg_logged' );

foreach ( wp_roles()->role_objects as $blzmcp_role ) {
	$blzmcp_role->remove_cap( 'blzmcp_view_diagnostics' );
}

if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
	as_unschedule_all_actions( 'blzmcp_daily_cleanup', array(), 'blzmcp' );
}
