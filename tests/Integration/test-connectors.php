<?php
/**
 * Connector registry integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-connectors.php
 * Stub: add --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php before eval-file.
 * Real plugins (tests/matrix): expectations follow Connectors::state() (recommended, easy-mcp, both).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DevelopmentFunctions

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Connectors\Connectors;

blzmcp_it_assert( class_exists( Connectors::class ), 'Connectors class exists' );
if ( ! class_exists( Connectors::class ) ) {
	blzmcp_it_finish();
}

$stub       = defined( 'EASY_MCP_AI_VERSION' );
$state      = Connectors::state();
$rec_active = Connectors::get( 'recommended' )->is_active();

blzmcp_it_equals( array( 'recommended', 'easy-mcp' ), array_keys( Connectors::all() ), 'all() is keyed by id' );
blzmcp_it_equals( 'recommended', Connectors::get( 'recommended' )->id(), 'get(recommended)' );
blzmcp_it_equals( null, Connectors::get( 'nope' ), 'get(unknown) is null' );
blzmcp_it_equals( 'recommended', Connectors::id_for_route( '/mcp/mcp-adapter-default-server' ), 'route /mcp/ is recommended' );
blzmcp_it_equals( 'easy-mcp', Connectors::id_for_route( '/easy-mcp-ai/v1/mcp' ), 'route /easy-mcp-ai/ is easy-mcp' );
blzmcp_it_equals( null, Connectors::id_for_route( '/wc/v3/orders' ), 'other route is null' );
blzmcp_it_equals( 'MCP Adapter and Enable Abilities for MCP', Connectors::get( 'recommended' )->label(), 'recommended label' );
blzmcp_it_equals( 'Easy MCP AI', Connectors::get( 'easy-mcp' )->label(), 'easy label' );
blzmcp_it_equals( 'wc-analytics/totals', Connectors::get( 'recommended' )->tool_name( 'wc-analytics/totals' ), 'recommended tool_name is the slug' );
blzmcp_it_equals( rest_url( 'mcp/mcp-oauth-server' ), Connectors::get( 'recommended' )->connect_url(), 'recommended connect_url' );
blzmcp_it_equals( null, Connectors::get( 'recommended' )->connect_screen_url(), 'recommended has no connect screen' );
blzmcp_it_equals( admin_url( 'options-general.php?page=ewpa-settings' ), Connectors::get( 'recommended' )->settings_url(), 'recommended settings_url' );
blzmcp_it_equals( rest_url( 'easy-mcp-ai/v1/mcp' ), Connectors::get( 'easy-mcp' )->connect_url(), 'easy connect_url' );
blzmcp_it_assert( str_contains( Connectors::get( 'recommended' )->run_instruction(), 'execute-ability tool' ), 'recommended run instruction' );
blzmcp_it_assert( str_contains( Connectors::get( 'easy-mcp' )->run_instruction(), 'call the step\'s "tool" with its "arguments"' ), 'easy run instruction' );
blzmcp_it_assert( in_array( '/wp-json/mcp/*', Connectors::get( 'recommended' )->firewall_paths(), true ), 'recommended firewall paths' );
blzmcp_it_equals( array( '/.well-known/oauth-*', '/.well-known/openid-configuration', '/easy-mcp-ai/*', '/wp-json/easy-mcp-ai/*', '/authorize', '/token', '/register' ), Connectors::get( 'easy-mcp' )->firewall_paths(), 'easy firewall paths include the root OAuth fallbacks (/authorize, /token, /register)' );

// Production resolver: the active connector's real route has its callback wrapped in that
// connector's frame (the MCP Adapter route under /mcp/ when Enable Abilities is active; Easy MCP
// AI's own route when only it is active, as WooCommerce's bundled adapter registers no route).
Blaze\McpToolkit\Connectors\RequestContext::set_resolver( null );
$blzmcp_route    = $rec_active ? '/mcp/mcp-adapter-default-server' : '/easy-mcp-ai/v1/mcp';
$blzmcp_frame_id = $rec_active ? 'recommended' : 'easy-mcp';
$blzmcp_removed = remove_filter( 'rest_endpoints', array( Blaze\McpToolkit\Connectors\RequestContext::class, 'wrap_endpoints' ), PHP_INT_MAX );
$blzmcp_raw = rest_get_server()->get_routes()[ $blzmcp_route ][0]['callback'] ?? null;
if ( $blzmcp_removed ) {
	add_filter( 'rest_endpoints', array( Blaze\McpToolkit\Connectors\RequestContext::class, 'wrap_endpoints' ), PHP_INT_MAX );
}
$blzmcp_wrapped = rest_get_server()->get_routes()[ $blzmcp_route ][0]['callback'] ?? null;
blzmcp_it_assert( null !== $blzmcp_raw, "{$blzmcp_route} route registered" );
blzmcp_it_assert( $blzmcp_wrapped instanceof \Closure && $blzmcp_wrapped !== $blzmcp_raw, "production resolver wraps the {$blzmcp_route} route callback" );
blzmcp_it_equals( $blzmcp_frame_id, $blzmcp_wrapped instanceof \Closure ? ( ( new \ReflectionFunction( $blzmcp_wrapped ) )->getClosureUsedVariables()['connector_id'] ?? null ) : null, "wrapped {$blzmcp_route} callback runs in the {$blzmcp_frame_id} frame" );
$blzmcp_wc = rest_get_server()->get_routes()['/wc/v3/orders'][0]['callback'] ?? null;
blzmcp_it_assert( null !== $blzmcp_wc, '/wc/v3/orders route callback exists' );
blzmcp_it_assert( ! ( $blzmcp_wc instanceof \Closure ), 'production resolver leaves other routes alone' );

if ( 'easy-mcp' === $state ) {
	// Real Easy MCP AI alone (matrix): Enable Abilities is not active.
	blzmcp_it_assert( $stub, 'easy-mcp: EASY_MCP_AI_VERSION is defined' );
	blzmcp_it_equals( 'easy-mcp', Connectors::signin_owner()->id(), 'easy-mcp: signin owner is easy-mcp' );
	blzmcp_it_equals( 'wp_ability_wc_analytics_totals', Connectors::get( 'easy-mcp' )->tool_name( 'wc-analytics/totals' ), 'easy-mcp: easy tool name' );
	blzmcp_it_assert( ! Connectors::get( 'recommended' )->is_active(), 'easy-mcp: recommended inactive' );
	blzmcp_it_assert( ! Connectors::get( 'recommended' )->owns_sign_in(), 'easy-mcp: recommended does not own sign-in' );
	blzmcp_it_assert( Connectors::get( 'easy-mcp' )->owns_sign_in(), 'easy-mcp: easy owns sign-in' );
	blzmcp_it_equals( 'easy-mcp', Connectors::execution()->id(), 'easy-mcp: execution outside REST is easy-mcp' );
} elseif ( ! $stub ) {
	blzmcp_it_equals( 'recommended', Connectors::state(), 'state is recommended' );
	blzmcp_it_equals( 'recommended', Connectors::signin_owner()->id(), 'signin owner is recommended' );
	blzmcp_it_equals( 'recommended', Connectors::execution()->id(), 'execution outside REST is recommended' );
	blzmcp_it_assert( Connectors::get( 'recommended' )->owns_sign_in(), 'recommended owns sign-in' );
	blzmcp_it_assert( ! Connectors::get( 'easy-mcp' )->is_active(), 'easy inactive' );
	blzmcp_it_assert( ! Connectors::get( 'easy-mcp' )->owns_sign_in(), 'easy does not own sign-in' );
} else {
	blzmcp_it_equals( 'both', $state, 'stub or real both: state is both' );
	blzmcp_it_equals( 'easy-mcp', Connectors::signin_owner()->id(), 'stub: signin owner is easy-mcp' );
	blzmcp_it_equals( 'wp_ability_wc_analytics_totals', Connectors::get( 'easy-mcp' )->tool_name( 'wc-analytics/totals' ), 'stub: easy tool name' );
	blzmcp_it_equals( rest_url( 'easy-mcp-ai/v1/mcp' ), Connectors::get( 'easy-mcp' )->connect_url(), 'stub: easy connect_url' );
	blzmcp_it_assert( ! Connectors::get( 'recommended' )->owns_sign_in(), 'stub: recommended does not own sign-in' );
	blzmcp_it_assert( Connectors::get( 'easy-mcp' )->owns_sign_in(), 'stub: easy owns sign-in' );
	blzmcp_it_equals( 'easy-mcp', Connectors::execution()->id(), 'stub: execution outside REST is the sign-in owner' );
}

// execution() follows the request context when that connector is active.
Blaze\McpToolkit\Connectors\RequestContext::run(
	'recommended',
	static function () use ( $rec_active ): void {
		blzmcp_it_equals( $rec_active ? 'recommended' : 'easy-mcp', Connectors::execution()->id(), $rec_active ? 'execution follows the request context' : 'execution ignores an inactive recommended context' );
	}
);
Blaze\McpToolkit\Connectors\RequestContext::run(
	'easy-mcp',
	static function () use ( $stub ): void {
		blzmcp_it_equals( $stub ? 'easy-mcp' : 'recommended', Connectors::execution()->id(), 'execution ignores an inactive context connector' );
	}
);

blzmcp_it_finish();
