<?php
/**
 * The Easy MCP AI connector.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * Easy MCP AI serves the tools and handles sign-in itself.
 */
final class EasyMcpConnector implements Connector {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'easy-mcp';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return defined( 'EASY_MCP_AI_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function owns_sign_in(): bool {
		return $this->is_active();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $route REST route.
	 */
	public function serves_route( string $route ): bool {
		return str_starts_with( $route, '/easy-mcp-ai/' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function tool_name( string $ability ): string {
		return EasyMcpNaming::tool_name( $ability );
	}

	/**
	 * Placeholder until the exposure check lands.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public function is_exposed( \WP_Ability $ability ): bool|null {
		return true;
	}

	/**
	 * Placeholder until the diagnosis lands.
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function diagnose( string $ability ): ?Availability {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_instruction(): string {
		return 'Each step is a tool you can call directly: call the step\'s "tool" with its "arguments" as the parameters, exactly as given. Arguments are already valid, so you don\'t need to look up tool info first.';
	}

	/**
	 * Paths a firewall must allow. Checked against 2.4.0: OAuth discovery under /.well-known/, the
	 * authorize page at /easy-mcp-ai/authorize and the REST namespace easy-mcp-ai/v1.
	 */
	public function firewall_paths(): array {
		return array( '/.well-known/oauth-*', '/.well-known/openid-configuration', '/easy-mcp-ai/*', '/wp-json/easy-mcp-ai/*' );
	}

	/**
	 * Easy MCP's Tools page (Easy MCP AI > Tools; slug easy-mcp-ai-tools in 2.4.0).
	 */
	public function settings_url(): string {
		return admin_url( 'admin.php?page=easy-mcp-ai-tools' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function connect_url(): string {
		return rest_url( 'easy-mcp-ai/v1/mcp' );
	}

	/**
	 * Easy MCP's Connections page (slug easy-mcp-ai-connections in 2.4.0).
	 */
	public function connect_screen_url(): ?string {
		return admin_url( 'admin.php?page=easy-mcp-ai-connections' );
	}

	/**
	 * Placeholder until the setup checks move behind the connector.
	 *
	 * @return array<string, array{id:string,status:string,label:string,fix:string}>
	 */
	public function checks(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Easy MCP AI';
	}
}
