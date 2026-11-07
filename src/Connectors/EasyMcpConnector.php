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
	 * False when the ability is not ticked in Easy MCP (or not registered), null when it is ticked
	 * but the effective state can't be read, else Easy MCP's effective state. Read-only.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public function is_exposed( \WP_Ability $ability ): bool|null {
		$slug = $ability->get_name();
		if ( ! $this->is_ticked( $slug ) || ! wp_has_ability( $slug ) ) {
			return false;
		}
		$state = $this->effective_state( $slug );

		return null === $state ? null : $state[0];
	}

	/**
	 * Disabled here when not ticked or switched off, unknown when the effective state can't be
	 * read, null otherwise. Read-only.
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function diagnose( string $ability ): ?Availability {
		if ( ! $this->is_ticked( $ability ) ) {
			return new Availability( Availability::DISABLED_HERE, $this->id(), 'not ticked in Easy MCP AI › Tools › Abilities', $this->settings_url() );
		}
		$state = $this->effective_state( $ability );
		if ( null === $state ) {
			return new Availability( Availability::UNKNOWN, $this->id(), 'Easy MCP AI did not say whether this is switched on', $this->settings_url() );
		}
		if ( false === $state[0] ) {
			$reason = 'switched off in Easy MCP AI' . ( '' !== $state[1] ? ' (' . $state[1] . ')' : '' );
			return new Availability( Availability::DISABLED_HERE, $this->id(), $reason, $this->settings_url() );
		}
		return null;
	}

	/**
	 * Whether the ability is in Easy MCP's list of ticked abilities.
	 *
	 * @param string $slug Ability name.
	 */
	private function is_ticked( string $slug ): bool {
		$enabled = get_option( 'easy_mcp_ai_enabled_abilities', array() );

		return is_array( $enabled ) && in_array( $slug, $enabled, true );
	}

	/**
	 * Easy MCP's effective state for the ability's tool, from the read-only
	 * Tool_State::resolve() (never is_enabled(), which can persist). Null when unknown: the method
	 * is missing, throws, or returns anything but an array with a bool first.
	 *
	 * @param string $slug Ability name.
	 * @return array{0:bool,1:string}|null
	 */
	private function effective_state( string $slug ): ?array {
		$vendor = '\Easy_MCP_AI\Tools\Tool_State';
		if ( ! method_exists( $vendor, 'resolve' ) ) {
			return null;
		}
		try {
			$result = $vendor::resolve( $this->tool_name( $slug ), false );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $result ) || ! is_bool( $result[0] ?? null ) ) {
			return null;
		}
		$reason = $result[1] ?? '';

		return array( $result[0], is_string( $reason ) ? $reason : '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_instruction(): string {
		return 'Each step is a tool you can call directly: call the step\'s "tool" with its "arguments" as the parameters, exactly as given. Arguments are already valid, so you don\'t need to look up tool info first.';
	}

	/**
	 * Paths a firewall must allow. Checked against 2.4.0: OAuth discovery under /.well-known/, the
	 * authorize page at /easy-mcp-ai/authorize, the REST namespace easy-mcp-ai/v1, and the root
	 * OAuth fallbacks /authorize and /token (includes/oauth/class-root-fallback.php).
	 */
	public function firewall_paths(): array {
		return array( '/.well-known/oauth-*', '/.well-known/openid-configuration', '/easy-mcp-ai/*', '/wp-json/easy-mcp-ai/*', '/authorize', '/token' );
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
