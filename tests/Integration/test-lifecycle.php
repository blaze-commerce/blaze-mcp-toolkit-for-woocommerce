<?php
/**
 * Lifecycle integration test: activate / deactivate.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-lifecycle.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$plugin = 'blaze-mcp-toolkit-for-woocommerce/blaze-mcp-toolkit-for-woocommerce.php';

// Start from a clean, deactivated state, then activate for real.
deactivate_plugins( $plugin );
$activated = activate_plugin( $plugin );
blzmcp_it_assert( ! is_wp_error( $activated ), 'plugin activates without error' );
blzmcp_it_assert( is_plugin_active( $plugin ), 'plugin is active' );

// Capabilities.
$admin = get_role( 'administrator' );
$shop  = get_role( 'shop_manager' );
blzmcp_it_assert( $admin && $admin->has_cap( 'blzmcp_view_diagnostics' ), 'administrator has blzmcp_view_diagnostics' );
blzmcp_it_assert( $shop && ! $shop->has_cap( 'blzmcp_view_diagnostics' ), 'shop_manager does not have blzmcp_view_diagnostics' );

// Single non-autoloaded option.
$settings = get_option( 'blzmcp_settings' );
blzmcp_it_assert( is_array( $settings ), 'blzmcp_settings is an array' );
blzmcp_it_equals( false, $settings['shop_manager_diagnostics'] ?? null, 'shop_manager_diagnostics defaults to false' );
blzmcp_it_assert( ( $settings['activated_at'] ?? 0 ) > 0, 'activated_at is set' );
$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'blzmcp_settings' ) );
blzmcp_it_assert( in_array( $autoload, array( 'off', 'no' ), true ), 'blzmcp_settings autoload is off/no (got ' . var_export( $autoload, true ) . ')' );

// Tables.
foreach ( array( 'blzmcp_audit', 'blzmcp_events' ) as $table ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) );
	blzmcp_it_equals( $wpdb->prefix . $table, $found, "table {$table} exists" );
}

// Daily cleanup is scheduled after activation.
blzmcp_it_assert( function_exists( 'as_next_scheduled_action' ), 'Action Scheduler is available' );
blzmcp_it_assert( false !== as_next_scheduled_action( 'blzmcp_daily_cleanup', array(), 'blzmcp' ), 'blzmcp_daily_cleanup is scheduled after activation' );

// Deactivation unschedules it.
deactivate_plugins( $plugin );
blzmcp_it_assert( ! is_plugin_active( $plugin ), 'plugin is inactive after deactivation' );
blzmcp_it_equals( false, as_next_scheduled_action( 'blzmcp_daily_cleanup', array(), 'blzmcp' ), 'blzmcp_daily_cleanup is unscheduled after deactivation' );

// Re-activation keeps activated_at (only set when 0) and re-schedules.
$first = get_option( 'blzmcp_settings' )['activated_at'];
activate_plugin( $plugin );
blzmcp_it_equals( $first, get_option( 'blzmcp_settings' )['activated_at'], 'activated_at is preserved on re-activation' );
blzmcp_it_assert( false !== as_next_scheduled_action( 'blzmcp_daily_cleanup', array(), 'blzmcp' ), 'blzmcp_daily_cleanup is scheduled again after re-activation' );

blzmcp_it_finish();
