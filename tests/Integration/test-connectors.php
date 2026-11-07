<?php
/**
 * Connector registry integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-connectors.php
 * Stub: add --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php before eval-file.
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

$stub = defined( 'EASY_MCP_AI_VERSION' );

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
blzmcp_it_assert( in_array( '/easy-mcp-ai/*', Connectors::get( 'easy-mcp' )->firewall_paths(), true ), 'easy firewall paths' );

if ( ! $stub ) {
	blzmcp_it_equals( 'recommended', Connectors::state(), 'state is recommended' );
	blzmcp_it_equals( 'recommended', Connectors::signin_owner()->id(), 'signin owner is recommended' );
	blzmcp_it_equals( 'recommended', Connectors::execution()->id(), 'execution outside REST is recommended' );
	blzmcp_it_assert( Connectors::get( 'recommended' )->owns_sign_in(), 'recommended owns sign-in' );
	blzmcp_it_assert( ! Connectors::get( 'easy-mcp' )->is_active(), 'easy inactive' );
	blzmcp_it_assert( ! Connectors::get( 'easy-mcp' )->owns_sign_in(), 'easy does not own sign-in' );
} else {
	blzmcp_it_equals( 'both', Connectors::state(), 'stub: state is both' );
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
	static function (): void {
		blzmcp_it_equals( 'recommended', Connectors::execution()->id(), 'execution follows the request context' );
	}
);
Blaze\McpToolkit\Connectors\RequestContext::run(
	'easy-mcp',
	static function () use ( $stub ): void {
		blzmcp_it_equals( $stub ? 'easy-mcp' : 'recommended', Connectors::execution()->id(), 'execution ignores an inactive context connector' );
	}
);

blzmcp_it_finish();
