<?php
/**
 * The recommended connector: MCP Adapter plus Enable Abilities for MCP.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * MCP Adapter serves the tools, Enable Abilities for MCP handles sign-in.
 */
final class RecommendedConnector implements Connector {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'recommended';
	}

	/**
	 * Active when Enable Abilities is. MCP Adapter alone does not count.
	 */
	public function is_active(): bool {
		return defined( 'EWPA_OPTION_KEY' ) || function_exists( 'ewpa_render_settings_page' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function owns_sign_in(): bool {
		$easy = Connectors::get( 'easy-mcp' );
		return $this->is_active() && ! ( null !== $easy && $easy->is_active() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $route REST route.
	 */
	public function serves_route( string $route ): bool {
		return str_starts_with( $route, '/mcp/' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function tool_name( string $ability ): string {
		return $ability;
	}

	/**
	 * Whether MCP Adapter exposes the ability to MCP clients: its own check when available, else
	 * the ability's `mcp.public` meta. Read-only.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public function is_exposed( \WP_Ability $ability ): bool|null {
		if ( class_exists( '\WP\MCP\Abilities\McpAbilityExposure' ) ) {
			return (bool) \WP\MCP\Abilities\McpAbilityExposure::is_public( $ability );
		}
		$meta = $ability->get_meta();

		return true === ( $meta['mcp']['public'] ?? null );
	}

	/**
	 * Removed for every connector when Enable Abilities for MCP's third-party denylist holds the
	 * ability (it unregisters those at `wp_abilities_api_init`). Read-only.
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function diagnose( string $ability ): ?Availability {
		$disabled = get_option( 'ewpa_thirdparty_disabled', array() );
		if ( is_array( $disabled ) && in_array( $ability, $disabled, true ) ) {
			return new Availability( Availability::REMOVED_GLOBALLY, $this->id(), 'switched off in Enable Abilities for MCP', $this->settings_url() );
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run_instruction(): string {
		return 'Each step is run with the connector\'s execute-ability tool, using the step\'s "tool" as the ability name and its "arguments" as the parameters, exactly as given. Arguments are already valid, so you don\'t need to look up tool info first.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function firewall_paths(): array {
		return array( '/.well-known/oauth-*', '/oauth/*', '/wp-json/mcp/*' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_url(): string {
		return admin_url( 'options-general.php?page=ewpa-settings' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function connect_url(): string {
		return rest_url( 'mcp/mcp-oauth-server' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function connect_screen_url(): ?string {
		return null;
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
		return 'MCP Adapter and Enable Abilities for MCP';
	}
}
