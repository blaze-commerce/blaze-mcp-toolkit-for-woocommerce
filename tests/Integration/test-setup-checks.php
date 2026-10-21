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

$orig = array(
	'oauth'  => get_option( 'ewpa_oauth_enabled', null ),
	'conn'   => get_option( 'ewpa_oauth_connectors_enabled', null ),
	'deny'   => get_option( 'ewpa_thirdparty_disabled', null ),
	'sm'     => Settings::shop_manager_diagnostics(),
);
$sm_id = 0;

try {
	// Enable Abilities state drives oauth, pii and write checks.
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

	// Denylisting one of our tools makes tools_visible warn and name it.
	$ours = array_values( array_filter( array_keys( array_map( static fn( $a ) => 1, wp_get_abilities() ) ), static fn( $n ) => str_starts_with( $n, 'blaze-mcp-toolkit/' ) ) );
	update_option( 'ewpa_thirdparty_disabled', array( $ours[0] ) );
	$by = array_column( SetupChecks::run(), null, 'id' );
	blzmcp_it_equals( 'warn', $by['tools_visible']['status'], 'tools_visible warns when one of ours is denylisted' );
	blzmcp_it_assert( str_contains( $by['tools_visible']['fix'], $ours[0] ), 'tools_visible names the disabled tool' );
	update_option( 'ewpa_thirdparty_disabled', array() );
	$by = array_column( SetupChecks::run(), null, 'id' );
	blzmcp_it_equals( 'pass', $by['tools_visible']['status'], 'tools_visible passes with empty denylist' );


	// Classifier, direct cases.
	blzmcp_it_equals( false, SetupChecks::is_write( 'x/get-thing', array() ), 'un-annotated get-* is not write' );
	blzmcp_it_equals( false, SetupChecks::is_write( 'x/list-things', array() ), 'un-annotated list-* is not write' );
	blzmcp_it_equals( false, SetupChecks::is_write( 'x/search-things', array() ), 'un-annotated search-* is not write' );
	blzmcp_it_equals( false, SetupChecks::is_write( 'woocommerce/orders-query', array() ), 'un-annotated *-query is not write' );
	blzmcp_it_equals( false, SetupChecks::is_write( 'core/get-site-info', array() ), 'un-annotated *-info is not write' );
	foreach ( array( 'ewpa/wc-get-products', 'ewpa/wc-get-product', 'ewpa/wc-get-orders', 'ewpa/wc-get-order', 'ewpa/wc-get-customers', 'ewpa/yoast-get-seo', 'ewpa/yoast-get-sitemap-index', 'ewpa/site-stats' ) as $read_name ) {
	blzmcp_it_equals( false, SetupChecks::is_write( $read_name, array() ), "{$read_name} is not write" );
}
foreach ( array( 'ewpa/wc-update-product', 'ewpa/wc-update-order-status', 'ewpa/search-replace', 'ewpa/buscar-reemplazar' ) as $write_name ) {
	blzmcp_it_equals( true, SetupChecks::is_write( $write_name, array() ), "{$write_name} is write" );
}
blzmcp_it_equals( true, SetupChecks::is_write( 'ewpa/update-thing', array() ), 'un-annotated update-* is write' );
	blzmcp_it_equals( true, SetupChecks::is_write( 'x/frobnicate', array() ), 'un-annotated unknown verb is write' );
	blzmcp_it_equals( true, SetupChecks::is_write( 'x/get-thing', array( 'readonly' => false ) ), 'readonly=false is write even if named get-*' );
	blzmcp_it_equals( true, SetupChecks::is_write( 'x/get-thing', array( 'readonly' => true, 'destructive' => true ) ), 'readonly=true + destructive=true is write' );
	blzmcp_it_equals( false, SetupChecks::is_write( 'x/update-thing', array( 'readonly' => true ) ), 'readonly=true is not write' );
	blzmcp_it_equals( true, SetupChecks::is_write( 'x/get-thing', array( 'destructive' => true ) ), 'destructive=true alone is write' );
	blzmcp_it_equals( false, SetupChecks::is_write( 'mcp-adapter/execute-ability', array() ), 'mcp-adapter tools are never flagged' );
	$never = 0;
	foreach ( wp_get_abilities() as $ability ) {
		$n = $ability->get_name();
		if ( str_starts_with( $n, 'blaze-mcp-toolkit/' ) || str_starts_with( $n, 'wc-analytics/' ) ) {
			++$never;
			blzmcp_it_equals( false, SetupChecks::is_write( $n, $ability->get_meta()['annotations'] ?? array() ), "{$n} is never flagged as write" );
		}
	}
	blzmcp_it_assert( $never >= 7, 'blaze and wc-analytics abilities were checked' );
	blzmcp_it_equals( true, SetupChecks::is_pii_name( 'ewpa/wc-get-customers' ), 'wc-get-customers is on the PII list' );
	blzmcp_it_equals( true, SetupChecks::is_pii_name( 'ewpa/get-comments' ), 'get-comments is on the PII list' );
	blzmcp_it_equals( false, SetupChecks::is_pii_name( 'blaze-mcp-toolkit/store-profile' ), 'our tools are not on the PII list' );

	// PII: an enabled ewpa/wc-get-customers is named; denylisting it removes it from the message.
	update_option( 'ewpa_thirdparty_disabled', array() );
	$by = array_column( SetupChecks::run(), null, 'id' );
	blzmcp_it_assert( str_contains( $by['pii_tools']['fix'], 'ewpa/wc-get-customers' ), 'enabled ewpa/wc-get-customers is flagged' );
	update_option( 'ewpa_thirdparty_disabled', array( 'woocommerce/orders-query', 'ewpa/wc-get-customers' ) );
	$by = array_column( SetupChecks::run(), null, 'id' );
	blzmcp_it_assert( ! str_contains( $by['pii_tools']['fix'], 'ewpa/wc-get-customers' ) && ! str_contains( $by['pii_tools']['fix'], 'woocommerce/orders-query' ), 'denylisted tools are not flagged' );

	// A denylisted wc-analytics tool makes tools_visible warn.
	update_option( 'ewpa_thirdparty_disabled', array( 'wc-analytics/totals' ) );
	$by = array_column( SetupChecks::run(), null, 'id' );
	blzmcp_it_equals( 'warn', $by['tools_visible']['status'], 'tools_visible warns when wc-analytics/totals is denylisted' );
	blzmcp_it_assert( str_contains( $by['tools_visible']['fix'], 'wc-analytics/totals' ), 'tools_visible names the denylisted analytics tool' );
	update_option( 'ewpa_thirdparty_disabled', array() );
	blzmcp_it_assert( ! str_contains( array_column( SetupChecks::run(), null, 'id' )['chatgpt_connector']['fix'], 'Claude does not need' ), 'no unverified Claude claim' );

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
	$sm_id = 0;
} finally {
	foreach ( array( 'oauth' => 'ewpa_oauth_enabled', 'conn' => 'ewpa_oauth_connectors_enabled', 'deny' => 'ewpa_thirdparty_disabled' ) as $key => $opt ) {
		if ( null === $orig[ $key ] ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $orig[ $key ] );
		}
	}
	Settings::update( array( 'shop_manager_diagnostics' => $orig['sm'] ) );
	if ( $sm_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $sm_id );
	}
	wp_set_current_user( 0 );
}

blzmcp_it_finish();
