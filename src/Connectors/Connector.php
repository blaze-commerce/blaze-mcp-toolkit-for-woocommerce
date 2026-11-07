<?php
/**
 * Contract between the toolkit and an MCP connector plugin.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * One implementation per supported connector. Everything connector-specific lives behind this.
 */
interface Connector {

	/**
	 * Connector id: 'recommended' or 'easy-mcp'.
	 */
	public function id(): string;

	/**
	 * Whether this connector is installed and active.
	 */
	public function is_active(): bool;

	/**
	 * Whether this connector handles sign-in itself.
	 */
	public function owns_sign_in(): bool;

	/**
	 * Whether this connector serves the given REST route.
	 *
	 * @param string $route REST route.
	 */
	public function serves_route( string $route ): bool;

	/**
	 * The tool name this connector shows for an ability.
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function tool_name( string $ability ): string;

	/**
	 * Whether the ability is exposed. Null means unknown.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public function is_exposed( \WP_Ability $ability ): bool|null;

	/**
	 * Why an ability is unavailable, or null when it is available.
	 *
	 * @param string $ability Ability name (slug).
	 */
	public function diagnose( string $ability ): ?Availability;

	/**
	 * The instruction telling Claude how to run the toolkit through this connector.
	 */
	public function run_instruction(): string;

	/**
	 * Paths a firewall must allow for this connector.
	 *
	 * @return array<int,string>
	 */
	public function firewall_paths(): array;

	/**
	 * The connector's settings screen URL.
	 */
	public function settings_url(): string;

	/**
	 * The URL to give Claude to connect.
	 */
	public function connect_url(): string;

	/**
	 * The connector's own connect screen URL, if it has one.
	 */
	public function connect_screen_url(): ?string;

	/**
	 * Setup checks keyed by row id.
	 *
	 * @return array<string, array{id:string,status:string,label:string,fix:string}>
	 */
	public function checks(): array;

	/**
	 * Owner-facing connector name, e.g. "Easy MCP AI".
	 */
	public function label(): string;
}
