<?php
/**
 * Uninstall integration test: activate, leave data behind, uninstall, check
 * nothing is left, then re-activate so the environment stays usable.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-uninstall.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$plugin = 'blaze-mcp-toolkit-for-woocommerce/blaze-mcp-toolkit-for-woocommerce.php';

if ( ! is_plugin_active( $plugin ) ) {
	activate_plugin( $plugin );
}
blzmcp_it_assert( is_plugin_active( $plugin ), 'plugin is active before uninstall' );

// Leave the kind of data a live store accumulates.
set_transient( 'blzmcp_c_test_uninstall', array( 'x' => 1 ), HOUR_IN_SECONDS );
set_transient( 'blzmcp_anon_test_uninstall', 1, 60 );
set_transient( 'blzmcp_analytics_gap', 'none', HOUR_IN_SECONDS );
set_transient( 'blzmcp_missing_pkg_logged', 1, HOUR_IN_SECONDS );
set_transient( 'blzmcp_schedule_pending', 1 );
update_user_meta( 1, 'blzmcp_wcfc_notice_dismissed', 1 );
$wpdb->insert(
	$wpdb->prefix . 'blzmcp_audit',
	array(
		'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		'user_id'     => 1,
		'ability'     => 'blaze-mcp-toolkit/test',
		'params_hash' => str_repeat( '0', 64 ),
		'status'      => 'ok',
	)
);

// A finished run of the job too, so rows the scheduler API keeps are removed as well.
if ( function_exists( 'as_schedule_single_action' ) ) {
	$done_id = as_schedule_single_action( time() - 60, 'blzmcp_daily_cleanup', array( 'test' => 1 ), 'blzmcp' );
	$wpdb->update( $wpdb->prefix . 'actionscheduler_actions', array( 'status' => 'complete' ), array( 'action_id' => $done_id ) );
}

$as_count = static function () use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE hook = %s', $wpdb->prefix . 'actionscheduler_actions', 'blzmcp_daily_cleanup' ) );
};
blzmcp_it_assert( $as_count() >= 1, 'daily cleanup job rows exist before uninstall (' . $as_count() . ')' );

$job_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT action_id FROM %i WHERE hook = %s', $wpdb->prefix . 'actionscheduler_actions', 'blzmcp_daily_cleanup' ) ) );

deactivate_plugins( $plugin );
uninstall_plugin( $plugin );

// Options and transients.
$left = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'%' . $wpdb->esc_like( 'blzmcp' ) . '%',
		'%' . $wpdb->esc_like( 'blaze-mcp-toolkit' ) . '%'
	)
);
blzmcp_it_equals( array(), $left, 'no blzmcp option or transient left' );
wp_cache_flush();
blzmcp_it_equals( false, get_option( 'blzmcp_settings' ), 'blzmcp_settings is gone' );
blzmcp_it_equals( false, get_transient( 'blzmcp_c_test_uninstall' ), 'cached tool result transient is gone' );
blzmcp_it_equals( false, get_transient( 'blzmcp_anon_test_uninstall' ), 'anonymous-denial transient is gone' );

// Tables.
foreach ( array( 'blzmcp_audit', 'blzmcp_events' ) as $table ) {
	blzmcp_it_equals( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ), "table {$table} dropped" );
}

// Capability.
wp_roles()->for_site();
$with_cap = array();
foreach ( wp_roles()->role_objects as $name => $role_obj ) {
	if ( $role_obj->has_cap( 'blzmcp_view_diagnostics' ) ) {
		$with_cap[] = $name;
	}
}
blzmcp_it_equals( array(), $with_cap, 'no role has blzmcp_view_diagnostics' );

// User meta.
blzmcp_it_equals( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'blzmcp_' ) . '%' ) ), 'no blzmcp user meta left' );

// Scheduled job: no rows at all, in any status, and no blzmcp group.
blzmcp_it_equals( 0, $as_count(), 'no blzmcp_daily_cleanup rows left in actionscheduler_actions' );
blzmcp_it_equals( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE slug = %s', $wpdb->prefix . 'actionscheduler_groups', 'blzmcp' ) ), 'blzmcp scheduler group removed' );

$log_rows = 0;
foreach ( $job_ids as $job_id ) {
	$log_rows += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_logs', $job_id ) );
}
blzmcp_it_equals( 0, $log_rows, 'no scheduler log rows left for the removed jobs' );

// Put the environment back.
activate_plugin( $plugin );
blzmcp_it_assert( is_plugin_active( $plugin ), 'plugin re-activated after the test' );
blzmcp_it_assert( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'blzmcp_audit' ) ) ), 're-activation recreates the audit table' );

blzmcp_it_finish();
