<?php
/**
 * The recommended connector: MCP Adapter plus Enable Abilities for MCP.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Admin\SetupChecks;

defined( 'ABSPATH' ) || exit;

/**
 * MCP Adapter serves the tools, Enable Abilities for MCP handles sign-in.
 */
final class RecommendedConnector implements Connector {

	/**
	 * Customer list and order-detail tools from Enable Abilities and WooCommerce core. These return
	 * customer names, emails and addresses.
	 */
	private const PII_ABILITIES = array(
		'ewpa/wc-get-customers',
		'ewpa/wc-get-order',
		'ewpa/wc-get-orders',
		'ewpa/get-users',
		'ewpa/get-comments',
		'ewpa/obtener-usuarios',
		'woocommerce/orders-query',
	);

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
		if ( in_array( $ability, self::denylist(), true ) ) {
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
	 * The 1.0 setup rows this connector owns, with the 1.0 copy: mcp_adapter, enable_abilities,
	 * oauth_on, chatgpt_connector, pii_tools and write_tools. Read-only.
	 *
	 * @return array<string, array{id:string,status:string,label:string,fix:string}>
	 */
	public function checks(): array {
		return array(
			'mcp_adapter'       => $this->mcp_adapter_row(),
			'enable_abilities'  => $this->enable_abilities_row(),
			'oauth_on'          => $this->oauth_on_row(),
			'chatgpt_connector' => $this->chatgpt_connector_row(),
			'pii_tools'         => $this->pii_tools_row(),
			'write_tools'       => $this->write_tools_row(),
		);
	}

	/**
	 * Whether an ability returns customer personal data.
	 *
	 * @param string $name Ability name.
	 */
	public static function is_pii_name( string $name ): bool {
		return in_array( $name, self::PII_ABILITIES, true );
	}

	/**
	 * MCP Adapter is loaded (as its own plugin or a copy bundled by another plugin).
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function mcp_adapter_row(): array {
		if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			return SetupChecks::result( 'mcp_adapter', 'pass', __( 'MCP Adapter is loaded', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return SetupChecks::result( 'mcp_adapter', 'fail', __( 'MCP Adapter is not loaded', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Install and activate the MCP Adapter plugin. AI assistants reach your store through it.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Enable Abilities for MCP is active.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function enable_abilities_row(): array {
		if ( $this->is_active() ) {
			return SetupChecks::result( 'enable_abilities', 'pass', __( 'Enable Abilities for MCP is active', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return SetupChecks::result( 'enable_abilities', 'fail', __( 'Enable Abilities for MCP is not active', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Install and activate Enable Abilities for MCP. It provides the connector URL and sign-in that Claude and ChatGPT use.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Enable Abilities' OAuth sign-in is on.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function oauth_on_row(): array {
		if ( (bool) get_option( 'ewpa_oauth_enabled', false ) ) {
			return SetupChecks::result( 'oauth_on', 'pass', __( 'OAuth sign-in is on in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return SetupChecks::result( 'oauth_on', 'warn', __( 'OAuth sign-in is off in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Open Settings > WP Abilities and turn on OAuth. Claude connects with it.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Enable Abilities' ChatGPT and other third-party OAuth connectors are on.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function chatgpt_connector_row(): array {
		if ( (bool) get_option( 'ewpa_oauth_connectors_enabled', false ) ) {
			return SetupChecks::result( 'chatgpt_connector', 'pass', __( 'ChatGPT and other OAuth connectors are on in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return SetupChecks::result( 'chatgpt_connector', 'warn', __( 'ChatGPT and other OAuth connectors are off in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Open Settings > WP Abilities and turn on "ChatGPT & Other OAuth Connectors". Needed for ChatGPT and other OAuth clients.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Other tools that expose customer names, emails or addresses.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function pii_tools_row(): array {
		$found = array();
		foreach ( $this->exposed_abilities() as $name => $ability ) {
			if ( self::is_pii_name( $name ) ) {
				$found[] = $name;
			}
		}

		if ( ! $found ) {
			return SetupChecks::result( 'pii_tools', 'pass', __( 'No customer or order-detail tools are exposed by other plugins', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return SetupChecks::result(
			'pii_tools',
			'warn',
			__( 'Other plugins expose tools that return customer personal data', 'blaze-mcp-toolkit-for-woocommerce' ),
			sprintf(
				/* translators: %s: comma separated tool names. */
				__( 'These tools can return customer names, emails and addresses to your AI provider: %s. Our tools never do. To turn the tools off, open Settings > WP Abilities and switch them off (WooCommerce core tools are in the third-party list).', 'blaze-mcp-toolkit-for-woocommerce' ),
				implode( ', ', $found )
			)
		);
	}

	/**
	 * Other tools that can change the store or site.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function write_tools_row(): array {
		$found = array();
		foreach ( $this->exposed_abilities() as $name => $ability ) {
			if ( SetupChecks::is_write( $name, $ability->get_meta()['annotations'] ?? array() ) ) {
				$found[] = $name;
			}
		}

		if ( ! $found ) {
			return SetupChecks::result( 'write_tools', 'pass', __( 'No other plugins expose tools that can change your store', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}

		$shown = array_slice( $found, 0, 12 );
		$more  = count( $found ) - count( $shown );
		return SetupChecks::result(
			'write_tools',
			'warn',
			__( 'Other plugins expose tools that can change your store', 'blaze-mcp-toolkit-for-woocommerce' ),
			sprintf(
				/* translators: 1: number of tools, 2: comma separated tool names, 3: extra count text. */
				__( '%1$d tools from WooCommerce core or Enable Abilities can create, edit or delete content and orders: %2$s%3$s. Our own tools are read-only, but these are not. If you want a read-only connection, turn them off under Settings > WP Abilities.', 'blaze-mcp-toolkit-for-woocommerce' ),
				count( $found ),
				implode( ', ', $shown ),
				$more > 0 ? sprintf( /* translators: %d: number of further tools. */ __( ' and %d more', 'blaze-mcp-toolkit-for-woocommerce' ), $more ) : ''
			)
		);
	}

	/**
	 * Enable Abilities' third-party denylist.
	 *
	 * @return array<int, string>
	 */
	private static function denylist(): array {
		$list = get_option( 'ewpa_thirdparty_disabled', array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * Registered, MCP-public abilities from other plugins that Enable Abilities has not switched off.
	 * Ours (blaze-mcp-toolkit/*) and the adapter's own tools are left out. Enable Abilities only
	 * registers its own ewpa/* abilities when enabled, so registered means enabled.
	 *
	 * @return array<string, \WP_Ability>
	 */
	private function exposed_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$denylist = self::denylist();
		$out      = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( str_starts_with( $name, Registrar::CATEGORY . '/' ) || str_starts_with( $name, 'mcp-adapter/' ) || in_array( $name, $denylist, true ) ) {
				continue;
			}
			$meta = $ability->get_meta();
			if ( empty( $meta['mcp']['public'] ) ) {
				continue;
			}
			$out[ $name ] = $ability;
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'MCP Adapter and Enable Abilities for MCP';
	}
}
