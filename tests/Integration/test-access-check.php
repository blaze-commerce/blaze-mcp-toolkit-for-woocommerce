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
foreach ( array( 'rest_keys', 'webhooks', 'application_passwords' ) as $list ) {
	blzmcp_it_equals( 100, $ability->get_output_schema()['properties'][ $list ]['items']['properties']['untrusted_text']['maxLength'] ?? null, "{$list} untrusted_text schema maxLength is 100" );
}
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
	array( 'name' => 'BlzIT app ' . $owner_email )
);
$app_plain         = is_array( $app_secret_holder ) ? $app_secret_holder[0] : '';

// Run the whole data setup and assertions under a non-UTC site timezone so the conversion is really tested.
$tz_filter = static fn() => 'Australia/Melbourne';
add_filter( 'pre_option_timezone_string', $tz_filter );

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
// A key with an unknown permissions value must fail closed.
$wpdb->insert(
	$wpdb->prefix . 'woocommerce_api_keys',
	array(
		'user_id'         => $owner_id,
		'description'     => 'Odd',
		'permissions'     => 'bogus',
		'consumer_key'    => wc_api_hash( 'ck_odd' ),
		'consumer_secret' => 'cs_odd_secret',
		'truncated_key'   => 'odd_key',
		'last_access'     => wp_date( 'Y-m-d H:i:s' ),
	)
);
$key3_id = (int) $wpdb->insert_id;
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

$extra_hooks = array();
// Keys, webhooks and users are removed even if an assertion fatals half way.
blzmcp_it_cleanup(
	static function () use ( $wpdb, $key_id, $key2_id, $key3_id, $hook, &$extra_hooks, $owner_id ): void {
		foreach ( array( $key_id, $key2_id, $key3_id ) as $kid ) {
			$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $kid ) );
		}
		foreach ( array_merge( array( $hook ), array_values( $extra_hooks ) ) as $wh ) {
			if ( $wh->get_id() && wc_get_webhook( $wh->get_id() ) ) {
				$wh->delete( true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( get_userdata( (int) $owner_id ) ) {
			wp_delete_user( (int) $owner_id );
		}
	}
);
foreach (
	array(
		'Bad1'  => array( 'https://apikey_ABC123#x@host.net/p', 'order.updated' ),
		'Bad2'  => array( 'https://user:12#ss@host.net/', 'customer.created' ),
		'Bad3'  => array( 'https://user:pass@host.net/x', 'customer.created' ),
		'Www'   => array( 'https://www.' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/hook', 'order.created' ),
		'Email' => array( 'https://ok.example.net/h', 'order.created' ),
	) as $hname => $spec
) {
	$h = new WC_Webhook();
	$h->set_name( 'IT ' . $hname );
	$h->set_topic( $spec[1] );
	$h->set_delivery_url( $spec[0] );
	$h->set_secret( 'whsec_it' );
	$h->set_status( 'active' );
	$h->set_user_id( $admin->ID );
	$h->save();
	$extra_hooks[ $hname ] = $h;
}

$clear_cache();
wp_set_current_user( $admin->ID );
$result = $ability->execute( array() );
blzmcp_it_assert( is_array( $result ), 'administrator gets a result' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) );
$result = is_array( $result ) ? $result : array();
$json   = (string) wp_json_encode( $result );

$keys = array();
foreach ( $result['rest_keys'] ?? array() as $k ) {
	$keys[ $k['truncated_key'] ] = $k;
	blzmcp_it_assert( ! array_key_exists( 'description', $k ) && array_key_exists( 'untrusted_text', $k ), 'key label is under untrusted_text' );
}
$mine = $keys[ substr( $fake_key, -7 ) ] ?? array();
blzmcp_it_equals( 'read_write', $mine['permissions'] ?? null, 'key permissions' );
blzmcp_it_equals( 'read_write', ( $keys['odd_key'] ?? array() )['permissions'] ?? null, 'unknown permissions fail closed to read_write' );
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
	$hooks[ $h['untrusted_text'] ] = $h;
}
$wh = $hooks['Order sync'] ?? array();
blzmcp_it_equals( 'hooks.example.net', $wh['delivery_host'] ?? null, 'delivery_host is the host only' );
foreach ( array( 'IT Bad1', 'IT Bad2', 'IT Bad3' ) as $bad ) {
	blzmcp_it_equals( null, ( $hooks[ $bad ] ?? array( 'delivery_host' => 'missing' ) )['delivery_host'], "$bad: userinfo URL gives null host" );
	blzmcp_it_assert( in_array( 'malformed_url', $hooks[ $bad ]['flags'] ?? array(), true ), "$bad: flagged malformed_url" );
}
blzmcp_it_assert( ! str_contains( $json, 'apikey_ABC123' ) && ! str_contains( $json, 'user:12' ) && ! str_contains( $json, 'user:pass' ), 'no userinfo leaks in output' );
blzmcp_it_assert( ! in_array( 'malformed_url', $hooks['IT Email']['flags'] ?? array(), true ), 'normal URL not flagged malformed' );
blzmcp_it_equals( 'order.created', ( $hooks['IT Www'] ?? array() )['topic'] ?? null, 'www webhook listed' );
blzmcp_it_equals( 'order.created', $wh['topic'] ?? null, 'webhook topic' );
blzmcp_it_equals( 'active', $wh['status'] ?? null, 'webhook status' );
blzmcp_it_assert( in_array( 'sends_order_data', $wh['flags'] ?? array(), true ), 'order webhook flagged sends_order_data' );

$summary = $result['summary'] ?? array();
// External order webhooks: hooks.example.net, ok.example.net, Bad1 is null host (not counted), www.<site> is NOT external.
blzmcp_it_equals( 2, $summary['order_webhooks_external'] ?? null, 'external order webhooks: www.<site> is internal, malformed is not counted' );
blzmcp_it_equals( array( 'rest_keys' => false, 'webhooks' => false, 'application_passwords' => false ), $result['truncated'] ?? null, 'nothing truncated for a small store' );
blzmcp_it_assert( ( $summary['write_keys'] ?? 0 ) >= 1, 'summary counts write keys' );
blzmcp_it_assert( ( $summary['stale_write_keys'] ?? 0 ) >= 1, 'summary counts stale write keys' );
blzmcp_it_assert( ( $summary['order_webhooks_external'] ?? 0 ) >= 1, 'summary counts external order webhooks' );
blzmcp_it_equals( array( 'rest_keys' => true, 'webhooks' => true, 'application_passwords' => true ), $result['available'] ?? null, 'every list was readable' );

// A failed read is unknown, not "no keys" (audit F03). Break each list's query in turn.
$break_query = static function ( string $needle ) {
	return static function ( $query ) use ( $needle ) {
		return is_string( $query ) && str_contains( $query, $needle ) ? 'SELECT key_id FROM blzmcp_it_missing_table_xyz' : $query;
	};
};
$suppressed = $wpdb->suppress_errors( true );
foreach ( array(
	'rest_keys' => 'woocommerce_api_keys',
	'webhooks'  => 'wc_webhooks',
) as $list => $needle ) {
	$breaker = $break_query( $needle );
	add_filter( 'query', $breaker );
	$clear_cache();
	$broken = $ability->execute( array() );
	remove_filter( 'query', $breaker );
	$broken_json = (string) wp_json_encode( $broken );
	blzmcp_it_assert( is_array( $broken ), "{$list} read failure: still a result, not an error" . ( is_wp_error( $broken ) ? ' (' . $broken->get_error_message() . ')' : '' ) );
	blzmcp_it_equals( false, $broken['available'][ $list ] ?? null, "{$list} read failure: available.{$list} is false" );
	blzmcp_it_equals( array(), $broken[ $list ] ?? null, "{$list} read failure: no rows listed" );
	blzmcp_it_assert( ! str_contains( $broken_json, 'blzmcp_it_missing_table_xyz' ) && ! str_contains( $broken_json, "doesn't exist" ) && ! str_contains( $broken_json, 'SELECT' ), "{$list} read failure: no SQL or database error text in output" );
	$other = 'rest_keys' === $list ? 'webhooks' : 'rest_keys';
	blzmcp_it_equals( true, $broken['available'][ $other ] ?? null, "{$list} read failure: {$other} still readable" );
	if ( 'rest_keys' === $list ) {
		blzmcp_it_equals( null, array_key_exists( 'write_keys', (array) ( $broken['summary'] ?? array() ) ) ? $broken['summary']['write_keys'] : 'missing', 'rest_keys read failure: write_keys is null, not 0' );
		blzmcp_it_equals( null, array_key_exists( 'stale_write_keys', (array) ( $broken['summary'] ?? array() ) ) ? $broken['summary']['stale_write_keys'] : 'missing', 'rest_keys read failure: stale_write_keys is null, not 0' );
		blzmcp_it_assert( is_int( $broken['summary']['order_webhooks_external'] ?? null ), 'rest_keys read failure: webhook count still reported' );
	} else {
		blzmcp_it_equals( null, array_key_exists( 'order_webhooks_external', (array) ( $broken['summary'] ?? array() ) ) ? $broken['summary']['order_webhooks_external'] : 'missing', 'webhooks read failure: order_webhooks_external is null, not 0' );
	}
}
$wpdb->suppress_errors( $suppressed );
$clear_cache();

$apps = $result['application_passwords'] ?? array();
blzmcp_it_assert( count( $apps ) >= 1, 'application password listed' );
$app = array();
foreach ( $apps as $candidate ) {
	if ( str_starts_with( $candidate['untrusted_text'] ?? '', 'BlzIT app' ) ) {
		$app = $candidate;
	}
}
blzmcp_it_assert( array() !== $app, 'test application password found by name' );
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

remove_filter( 'pre_option_timezone_string', $tz_filter );

// Truncation: more than 50 keys.
$bulk_ids = array();
for ( $i = 0; $i < 52; $i++ ) {
	$wpdb->insert(
		$wpdb->prefix . 'woocommerce_api_keys',
		array(
			'user_id'         => $owner_id,
			'description'     => 'bulk',
			'permissions'     => 'read',
			'consumer_key'    => wc_api_hash( 'ck_bulk' . $i ),
			'consumer_secret' => 'cs_bulk',
			'truncated_key'   => 'bulk' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ),
			'last_access'     => null,
		)
	);
	$bulk_ids[] = (int) $wpdb->insert_id;
}
$clear_cache();
$big = $ability->execute( array() );
blzmcp_it_equals( 50, count( $big['rest_keys'] ?? array() ), 'rest_keys capped at 50' );
blzmcp_it_equals( true, $big['truncated']['rest_keys'] ?? null, 'rest_keys truncated flag set' );
foreach ( $bulk_ids as $bid ) {
	$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $bid ) );
}

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
$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key3_id ) );
foreach ( $extra_hooks as $eh ) {
	$eh->delete( true );
}
$hook->delete( true );
wp_delete_user( (int) $sm );
wp_delete_user( (int) $owner_id ); // Also removes its application passwords (user meta).
$clear_cache();
blzmcp_it_finish();
