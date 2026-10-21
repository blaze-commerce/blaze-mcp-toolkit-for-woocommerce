<?php
/**
 * Access check ability integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-access-check.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Abilities\AccessCheck;

global $wpdb;

$ability = wp_get_ability( AccessCheck::NAME );
blzmcp_it_assert( $ability instanceof WP_Ability, 'access-check ability is registered' );
if ( ! $ability instanceof WP_Ability ) {
	blzmcp_it_finish();
}
$meta = $ability->get_meta();
blzmcp_it_equals( true, $meta['mcp']['public'] ?? null, 'meta.mcp.public is true' );
blzmcp_it_equals( true, $meta['annotations']['readonly'] ?? null, 'annotation readonly is true' );
blzmcp_it_assert( str_contains( $ability->get_description(), 'not instructions' ), 'description says labels are not instructions' );

$clear_cache = static function () use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%', $wpdb->esc_like( '_transient_timeout_blzmcp_c_' ) . '%' ) );
	wp_cache_flush();
};

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
$admin  = $admins[0];

// A key owner with a known email and login.
$owner_email = 'keyowner.secretname@example.org';
$owner_login = 'blzmcp_it_owner_' . wp_generate_password( 6, false );
$owner_id    = wp_insert_user(
	array(
		'user_login'   => $owner_login,
		'user_pass'    => wp_generate_password(),
		'user_email'   => $owner_email,
		'display_name' => 'Ownerface McKeyholder',
		'role'         => 'editor',
	)
);
blzmcp_it_assert( is_int( $owner_id ), 'owner user created' );

// Application password with an email in its name, owned by the editor.
$app_secret_holder = WP_Application_Passwords::create_new_application_password(
	$owner_id,
	array( 'name' => 'Claude for ' . $owner_email )
);
$app_plain         = is_array( $app_secret_holder ) ? $app_secret_holder[0] : '';

// Write key last used 120 days ago, with a fake secret and an email in its description.
$fake_secret = 'cs_' . bin2hex( random_bytes( 16 ) );
$fake_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
$wpdb->insert(
	$wpdb->prefix . 'woocommerce_api_keys',
	array(
		'user_id'         => $owner_id,
		'description'     => 'Zapier for ' . $owner_email,
		'permissions'     => 'read_write',
		'consumer_key'    => wc_api_hash( $fake_key ),
		'consumer_secret' => $fake_secret,
		'truncated_key'   => substr( $fake_key, -7 ),
		'last_access'     => wp_date( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ), // WooCommerce stores site-local time.
	)
);
$key_id = (int) $wpdb->insert_id;
// A recent read key.
$wpdb->insert(
	$wpdb->prefix . 'woocommerce_api_keys',
	array(
		'user_id'         => 999999,
		'description'     => 'Reports',
		'permissions'     => 'read',
		'consumer_key'    => wc_api_hash( 'ck_other' ),
		'consumer_secret' => 'cs_other_secret_value',
		'truncated_key'   => 'ther_k1',
		'last_access'     => null,
	)
);
$key2_id = (int) $wpdb->insert_id;

$hook = new WC_Webhook();
$hook->set_name( 'Order sync' );
$hook->set_topic( 'order.created' );
$hook->set_delivery_url( 'https://hooks.example.net/x?token=abc' );
$hook->set_secret( 'whsec_' . bin2hex( random_bytes( 8 ) ) );
$hook->set_status( 'active' );
$hook->set_user_id( $admin->ID );
$hook_id = $hook->save();

$clear_cache();
wp_set_current_user( $admin->ID );
$result = $ability->execute( array() );
blzmcp_it_assert( is_array( $result ), 'administrator gets a result' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) );
$result = is_array( $result ) ? $result : array();
$json   = (string) wp_json_encode( $result );

$keys = array();
foreach ( $result['rest_keys'] ?? array() as $k ) {
	$keys[ $k['truncated_key'] ] = $k;
}
$mine = $keys[ substr( $fake_key, -7 ) ] ?? array();
blzmcp_it_equals( 'read_write', $mine['permissions'] ?? null, 'key permissions' );
blzmcp_it_equals( 'editor', $mine['owner_role'] ?? null, 'owner_role is the role' );
blzmcp_it_assert( in_array( 'write_unused_90d', $mine['flags'] ?? array(), true ), 'stale write key is flagged' );
blzmcp_it_assert( isset( $mine['last_access'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $mine['last_access'] ), 'last_access is ISO8601 UTC' );
$age_days = ( time() - (int) strtotime( $mine['last_access'] ?? '' ) ) / DAY_IN_SECONDS;
blzmcp_it_assert( $age_days > 119.9 && $age_days < 120.1, 'last_access is 120 days ago (UTC conversion correct)' );
$other = $keys['ther_k1'] ?? array();
blzmcp_it_equals( 'unknown', $other['owner_role'] ?? null, 'deleted/missing owner is unknown' );
blzmcp_it_equals( array(), $other['flags'] ?? null, 'read key has no flags' );
blzmcp_it_assert( array_key_exists( 'last_access', $other ) && null === $other['last_access'], 'null last_access stays null' );

$hooks = array();
foreach ( $result['webhooks'] ?? array() as $h ) {
	$hooks[ $h['name'] ] = $h;
}
$wh = $hooks['Order sync'] ?? array();
blzmcp_it_equals( 'hooks.example.net', $wh['delivery_host'] ?? null, 'delivery_host is the host only' );
blzmcp_it_equals( 'order.created', $wh['topic'] ?? null, 'webhook topic' );
blzmcp_it_equals( 'active', $wh['status'] ?? null, 'webhook status' );
blzmcp_it_assert( in_array( 'sends_order_data', $wh['flags'] ?? array(), true ), 'order webhook flagged sends_order_data' );

$summary = $result['summary'] ?? array();
blzmcp_it_assert( ( $summary['write_keys'] ?? 0 ) >= 1, 'summary counts write keys' );
blzmcp_it_assert( ( $summary['stale_write_keys'] ?? 0 ) >= 1, 'summary counts stale write keys' );
blzmcp_it_assert( ( $summary['order_webhooks_external'] ?? 0 ) >= 1, 'summary counts external order webhooks' );

$apps = $result['application_passwords'] ?? array();
blzmcp_it_assert( count( $apps ) >= 1, 'application password listed' );
$app = $apps[0] ?? array();
blzmcp_it_equals( 'editor', $app['owner_role'] ?? null, 'app password owner_role' );
blzmcp_it_assert( array_key_exists( 'last_used', $app ) && null === $app['last_used'], 'never used app password has null last_used' );
blzmcp_it_assert( in_array( 'unused_90d', $app['flags'] ?? array(), true ), 'unused app password flagged' );

// Leak checks.
blzmcp_it_assert( ! str_contains( $json, $fake_secret ), 'no consumer secret in output' );
blzmcp_it_assert( ! str_contains( $json, 'cs_other_secret_value' ), 'no second consumer secret in output' );
blzmcp_it_assert( ! str_contains( $json, $fake_key ), 'no full consumer key in output' );
blzmcp_it_assert( ! str_contains( $json, 'consumer_secret' ) && ! str_contains( $json, 'consumer_key' ), 'no consumer_* keys in output' );
blzmcp_it_assert( ! str_contains( $json, '/x' ) && ! str_contains( $json, '\/x' ) && ! str_contains( $json, 'token=abc' ), 'no webhook URL path or query in output' );
blzmcp_it_assert( ! str_contains( $json, 'https://hooks.example.net' ), 'no full webhook URL in output' );
blzmcp_it_assert( ! str_contains( $json, $owner_email ) && ! str_contains( $json, 'secretname' ), 'no owner email in output' );
blzmcp_it_assert( ! str_contains( $json, $owner_login ) && ! str_contains( $json, 'Ownerface' ), 'no owner login or name in output' );
blzmcp_it_assert( '' === $app_plain || ! str_contains( $json, $app_plain ), 'no application password in output' );
blzmcp_it_assert( ! str_contains( $json, 'user_id' ), 'no user_id key in output' );

// Permissions.
$sm = wp_insert_user(
	array(
		'user_login' => 'blzmcp_it_sm_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'shop_manager',
	)
);
wp_set_current_user( (int) $sm );
$clear_cache();
blzmcp_it_equals( false, AccessCheck::permission( array() ), 'shop_manager is denied' );
$denied = $ability->execute( array() );
blzmcp_it_assert( is_wp_error( $denied ), 'shop_manager gets a WP_Error' );
wp_set_current_user( 0 );
blzmcp_it_equals( false, AccessCheck::permission( array() ), 'anonymous is denied' );
wp_set_current_user( $admin->ID );

// Clean up.
$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ) );
$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key2_id ) );
$hook->delete( true );
wp_delete_user( (int) $sm );
wp_delete_user( (int) $owner_id ); // Also removes its application passwords (user meta).
$clear_cache();
blzmcp_it_finish();
