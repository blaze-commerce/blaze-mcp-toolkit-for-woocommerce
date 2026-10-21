<?php
/**
 * Setup checks, setup screen save and render integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-setup-checks.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Admin\SetupChecks;
use Blaze\McpToolkit\Admin\SetupScreen;
use Blaze\McpToolkit\Settings;

$checks = SetupChecks::run();
$by_id  = array();
foreach ( $checks as $check ) {
	$by_id[ $check['id'] ] = $check;
}

$expected = array( 'woocommerce', 'mcp_adapter', 'enable_abilities', 'oauth_on', 'chatgpt_connector', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode', 'pii_tools', 'write_tools', 'edge_guidance' );
foreach ( $expected as $id ) {
	blzmcp_it_assert( isset( $by_id[ $id ] ), "check {$id} is present" );
	if ( isset( $by_id[ $id ] ) ) {
		blzmcp_it_assert( in_array( $by_id[ $id ]['status'], array( 'pass', 'warn', 'fail' ), true ) && is_string( $by_id[ $id ]['label'] ) && is_string( $by_id[ $id ]['fix'] ), "check {$id} has status, label and fix" );
	}
}
foreach ( array( 'woocommerce', 'mcp_adapter', 'enable_abilities' ) as $id ) {
	blzmcp_it_equals( 'pass', $by_id[ $id ]['status'] ?? null, "{$id} passes in wp-env" );
}
blzmcp_it_equals( 'pass', $by_id['hpos_mode']['status'] ?? null, 'hpos_mode is info only (pass)' );
blzmcp_it_equals( 'warn', $by_id['edge_guidance']['status'] ?? null, 'edge_guidance is warn-styled info' );
blzmcp_it_assert( str_contains( $by_id['edge_guidance']['fix'] ?? '', '/.well-known/oauth-' ) && str_contains( $by_id['edge_guidance']['fix'] ?? '', '/wp-json/mcp/' ), 'edge_guidance lists the path exceptions' );
blzmcp_it_assert( ! str_contains( strtolower( wp_json_encode( $checks ) ), 'developer mode' ), 'no developer mode claim' );

// Enable Abilities state drives oauth, pii and write checks.
$ea_before = array(
	get_option( 'ewpa_oauth_enabled', null ),
	get_option( 'ewpa_oauth_connectors_enabled', null ),
	get_option( 'ewpa_thirdparty_disabled', null ),
);
update_option( 'ewpa_oauth_enabled', true );
update_option( 'ewpa_oauth_connectors_enabled', true );
$by = array_column( SetupChecks::run(), null, 'id' );
blzmcp_it_equals( 'pass', $by['oauth_on']['status'], 'oauth_on passes when on' );
blzmcp_it_equals( 'pass', $by['chatgpt_connector']['status'], 'chatgpt_connector passes when on' );
update_option( 'ewpa_oauth_enabled', false );
update_option( 'ewpa_oauth_connectors_enabled', false );
$by = array_column( SetupChecks::run(), null, 'id' );
blzmcp_it_equals( 'warn', $by['oauth_on']['status'], 'oauth_on warns when off' );
blzmcp_it_equals( 'warn', $by['chatgpt_connector']['status'], 'chatgpt_connector warns when off' );

blzmcp_it_equals( 'warn', $by['pii_tools']['status'], 'pii_tools warns while customer/order tools are exposed' );
blzmcp_it_equals( 'warn', $by['write_tools']['status'], 'write_tools warns while write tools are exposed' );
blzmcp_it_assert( ! str_contains( strtolower( $by['write_tools']['label'] ), 'read-only' ), 'write_tools label never claims read-only' );

// Denylist everything non-ewpa that is exposed and risky, and ours: tools_visible must fail/warn.
$ours = array_values( array_filter( array_keys( array_map( static fn( $a ) => 1, wp_get_abilities() ) ), static fn( $n ) => str_starts_with( $n, 'blaze-mcp-toolkit/' ) ) );
update_option( 'ewpa_thirdparty_disabled', array( $ours[0] ) );
$by = array_column( SetupChecks::run(), null, 'id' );
blzmcp_it_equals( 'warn', $by['tools_visible']['status'], 'tools_visible warns when one of ours is denylisted' );
blzmcp_it_assert( str_contains( $by['tools_visible']['fix'], $ours[0] ), 'tools_visible names the disabled tool' );
update_option( 'ewpa_thirdparty_disabled', array() );
$by = array_column( SetupChecks::run(), null, 'id' );
blzmcp_it_equals( 'pass', $by['tools_visible']['status'], 'tools_visible passes with empty denylist' );

// Denylisting the core order/product tools clears the core part of the warnings (ewpa ones stay).
foreach ( array( 'ewpa_oauth_enabled', 'ewpa_oauth_connectors_enabled', 'ewpa_thirdparty_disabled' ) as $i => $opt ) {
	if ( null === $ea_before[ $i ] ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $ea_before[ $i ] );
	}
}

// Settings save.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin  = $admins[0];
wp_set_current_user( $admin->ID );
Settings::update( array( 'shop_manager_diagnostics' => false ) );

$ok = SetupScreen::save( array( 'blzmcp_setup_nonce' => 'bogus', 'shop_manager_diagnostics' => '1' ) );
blzmcp_it_equals( false, $ok, 'save with invalid nonce is rejected' );
blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'invalid nonce leaves setting unchanged' );

$ok = SetupScreen::save( array( 'shop_manager_diagnostics' => '1' ) );
blzmcp_it_equals( false, $ok, 'save with no nonce is rejected' );
blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'missing nonce leaves setting unchanged' );

$nonce = wp_create_nonce( SetupScreen::NONCE_ACTION );
$ok    = SetupScreen::save( array( 'blzmcp_setup_nonce' => $nonce, 'shop_manager_diagnostics' => '1' ) );
blzmcp_it_equals( true, $ok, 'valid nonce as admin saves' );
blzmcp_it_equals( true, Settings::shop_manager_diagnostics(), 'setting turned on' );

$ok = SetupScreen::save( array( 'blzmcp_setup_nonce' => $nonce ) );
blzmcp_it_equals( true, $ok, 'unchecked box saves' );
blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'setting turned off when box unchecked' );

// Shop manager cannot save.
$sm_id = wp_insert_user( array( 'user_login' => 'blzmcp_it_sm', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'blzmcp-it-sm@example.invalid' ) );
wp_set_current_user( $sm_id );
$nonce = wp_create_nonce( SetupScreen::NONCE_ACTION );
$ok    = SetupScreen::save( array( 'blzmcp_setup_nonce' => $nonce, 'shop_manager_diagnostics' => '1' ) );
blzmcp_it_equals( false, $ok, 'shop manager save is rejected' );
blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'shop manager cannot change the setting' );

// Render as shop manager (setting hidden/disabled) and as admin.
ob_start();
SetupScreen::render();
$html_sm = ob_get_clean();
blzmcp_it_assert( str_contains( $html_sm, 'WooCommerce is a trademark of Automattic Inc.' ), 'footer trademark line renders' );
blzmcp_it_assert( ! str_contains( $html_sm, 'name="shop_manager_diagnostics"' ), 'shop manager does not get the setting control' );

wp_set_current_user( $admin->ID );
ob_start();
SetupScreen::render();
$html = ob_get_clean();
blzmcp_it_assert( str_contains( $html, 'name="shop_manager_diagnostics"' ), 'admin gets the setting control' );
blzmcp_it_assert( str_contains( $html, esc_html( rest_url( 'mcp/mcp-oauth-server' ) ) ), 'connector URL shown' );
blzmcp_it_assert( str_contains( $html, 'Plugins' ) && str_contains( $html, 'Add custom MCP server' ), 'ChatGPT wording shown' );
echo "\n----- RENDERED CHECKS -----\n";
foreach ( SetupChecks::run() as $c ) {
	printf( "%-18s %-5s %s\n", $c['id'], $c['status'], $c['label'] );
}
echo "---------------------------\n";

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sm_id );
Settings::update( array( 'shop_manager_diagnostics' => false ) );

blzmcp_it_finish();
