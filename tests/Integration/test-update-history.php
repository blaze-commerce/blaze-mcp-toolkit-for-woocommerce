<?php
/**
 * Update history ability integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-update-history.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Blaze\McpToolkit\Abilities\UpdateHistory;
use Blaze\McpToolkit\Events\EventStore;

global $wpdb;

$ability = wp_get_ability( UpdateHistory::NAME );
blzmcp_it_assert( $ability instanceof WP_Ability, 'update-history ability is registered' );
if ( ! $ability instanceof WP_Ability ) {
	blzmcp_it_finish();
}
$meta = $ability->get_meta();
blzmcp_it_equals( true, $meta['mcp']['public'] ?? null, 'meta.mcp.public is true' );
blzmcp_it_equals( true, $meta['annotations']['readonly'] ?? null, 'annotation readonly is true' );
blzmcp_it_assert( str_contains( $ability->get_description(), 'come from the site' ), 'description says subjects come from the site' );

$clear_cache = static function () use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%', $wpdb->esc_like( '_transient_timeout_blzmcp_c_' ) . '%' ) );
	wp_cache_flush();
};

// Clean slate.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
$clear_cache();

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
$admin  = $admins[0];
$orig_display = $admin->display_name;
wp_update_user(
	array(
		'ID'           => $admin->ID,
		'display_name' => 'Zed Quuxington',
	)
);
$admin = get_userdata( $admin->ID );
wp_set_current_user( $admin->ID );

$file = 'hello.php';
blzmcp_it_assert( file_exists( WP_PLUGIN_DIR . '/' . $file ), 'Hello Dolly is installed' );
if ( is_plugin_active( $file ) ) {
	deactivate_plugins( $file );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
}
activate_plugin( $file );
deactivate_plugins( $file );
$clear_cache();

$result = $ability->execute( array( 'days' => 7 ) );
blzmcp_it_assert( is_array( $result ), 'administrator gets a result' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) );
$result = is_array( $result ) ? $result : array();
$events = $result['events'] ?? array();
blzmcp_it_equals( 2, count( $events ), 'two events recorded' );
blzmcp_it_equals( 'plugin_deactivated', $events[0]['type'] ?? null, 'newest first: deactivated' );
blzmcp_it_equals( 'plugin_activated', $events[1]['type'] ?? null, 'then activated' );
blzmcp_it_equals( 'hello', $events[0]['subject'] ?? null, 'subject is the plugin file basename for single-file plugins' );
blzmcp_it_equals( 'administrator', $events[0]['by_role'] ?? null, 'by_role is administrator' );
blzmcp_it_assert( isset( $events[0]['when'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $events[0]['when'] ), 'when is ISO8601 UTC' );
blzmcp_it_assert( str_contains( $result['note'] ?? '', 'outside WordPress' ), 'note mentions changes outside WordPress' );
blzmcp_it_assert( ! empty( $result['tracking_since'] ), 'tracking_since present' );

$json = (string) wp_json_encode( $result );
blzmcp_it_assert( ! str_contains( $json, '"' . $admin->user_login . '"' ), 'no user_login value in output' );
blzmcp_it_assert( ! str_contains( $json, 'Quuxington' ), 'no display_name value in output' );
blzmcp_it_assert( ! str_contains( $json, 'user_login' ) && ! str_contains( $json, 'display_name' ) && ! str_contains( $json, 'user_id' ), 'no user keys in output' );

// Type filter.
$clear_cache();
$filtered = $ability->execute(
	array(
		'days' => 7,
		'type' => 'plugin_activated',
	)
);
blzmcp_it_equals( 1, count( $filtered['events'] ?? array() ), 'type filter returns only that type' );

// System and unknown users.
wp_set_current_user( 0 );
EventStore::add( 'core_updated', 'wordpress', '6.9.0', '6.9.1' );
$wpdb->insert(
	EventStore::table(),
	array(
		'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		'event_type'   => 'theme_switched',
		'subject'      => 'ghost',
		'from_version' => '',
		'to_version'   => '1.0',
		'user_id'      => 999999,
	)
);
wp_set_current_user( $admin->ID );
$clear_cache();
$all   = $ability->execute( array( 'days' => 7 ) );
$roles = array_column( $all['events'] ?? array(), 'by_role', 'subject' );
blzmcp_it_equals( 'system', $roles['wordpress'] ?? null, 'user 0 is system' );
blzmcp_it_equals( 'unknown', $roles['ghost'] ?? null, 'deleted user is unknown' );

// Retention: old rows purge, new stay.
$wpdb->insert(
	EventStore::table(),
	array(
		'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ),
		'event_type'   => 'plugin_updated',
		'subject'      => 'old',
		'from_version' => '',
		'to_version'   => '',
		'user_id'      => 0,
	)
);
blzmcp_it_equals( 1, EventStore::purge( 180 ), 'purge removes only rows older than 180 days' );

// Permissions.
$clear_cache();
$sub = wp_insert_user(
	array(
		'user_login' => 'blzmcp_it_sub_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( (int) $sub );
blzmcp_it_equals( false, UpdateHistory::permission( array() ), 'subscriber is denied' );
wp_delete_user( (int) $sub );
wp_set_current_user( $admin->ID );

wp_update_user(
	array(
		'ID'           => $admin->ID,
		'display_name' => $orig_display,
	)
);
// Clean up so reruns start clean.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
$clear_cache();
blzmcp_it_finish();
