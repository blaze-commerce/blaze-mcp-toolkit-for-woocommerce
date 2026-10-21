<?php
/**
 * Setup checks: is everything needed for an AI connection in place?
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Admin;

use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Bundled\AnalyticsGuard;
use Blaze\McpToolkit\Bundled\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of checks shown on the setup screen. Read-only: it only reads options and the
 * Abilities registry.
 */
final class SetupChecks {

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
	 * Name parts (after the namespace) that mark an un-annotated ability as read-only.
	 */
	private const READ_PREFIXES = array( 'get-', 'list-', 'search-' );

	/**
	 * Name endings that mark an un-annotated ability as read-only.
	 */
	private const READ_SUFFIXES = array( '-query', '-info' );

	/**
	 * Run every check.
	 *
	 * @return array<int, array{id: string, status: string, label: string, fix: string}>
	 */
	public static function run(): array {
		return array(
			self::woocommerce(),
			self::mcp_adapter(),
			self::enable_abilities(),
			self::oauth_on(),
			self::chatgpt_connector(),
			self::tools_visible(),
			self::wcfc_standaside(),
			self::analytics_import(),
			self::hpos_mode(),
			self::pii_tools(),
			self::write_tools(),
			self::edge_guidance(),
		);
	}

	/**
	 * Where Enable Abilities' own settings live.
	 */
	public static function enable_abilities_url(): string {
		return admin_url( 'options-general.php?page=ewpa-settings' );
	}

	/**
	 * Build one result row.
	 *
	 * @param string $id     Check id.
	 * @param string $status pass, warn or fail.
	 * @param string $label  What was checked.
	 * @param string $fix    What to do (empty when passing).
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function result( string $id, string $status, string $label, string $fix = '' ): array {
		return array(
			'id'     => $id,
			'status' => $status,
			'label'  => $label,
			'fix'    => $fix,
		);
	}

	/**
	 * WooCommerce is active.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function woocommerce(): array {
		if ( class_exists( 'WooCommerce' ) ) {
			return self::result( 'woocommerce', 'pass', __( 'WooCommerce is active', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result( 'woocommerce', 'fail', __( 'WooCommerce is not active', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Activate WooCommerce. This plugin only works on WooCommerce stores.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * MCP Adapter is loaded (as its own plugin or a copy bundled by another plugin).
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function mcp_adapter(): array {
		if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
			return self::result( 'mcp_adapter', 'pass', __( 'MCP Adapter is loaded', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result( 'mcp_adapter', 'fail', __( 'MCP Adapter is not loaded', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Install and activate the MCP Adapter plugin. AI assistants reach your store through it.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Enable Abilities for MCP is active.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function enable_abilities(): array {
		if ( defined( 'EWPA_OPTION_KEY' ) || function_exists( 'ewpa_render_settings_page' ) ) {
			return self::result( 'enable_abilities', 'pass', __( 'Enable Abilities for MCP is active', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result( 'enable_abilities', 'fail', __( 'Enable Abilities for MCP is not active', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Install and activate Enable Abilities for MCP. It provides the connector URL and sign-in that Claude and ChatGPT use.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Enable Abilities' OAuth sign-in is on.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function oauth_on(): array {
		if ( (bool) get_option( 'ewpa_oauth_enabled', false ) ) {
			return self::result( 'oauth_on', 'pass', __( 'OAuth sign-in is on in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result( 'oauth_on', 'warn', __( 'OAuth sign-in is off in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Open Settings > WP Abilities and turn on OAuth. Claude connects with it.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Enable Abilities' ChatGPT and other third-party OAuth connectors are on.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function chatgpt_connector(): array {
		if ( (bool) get_option( 'ewpa_oauth_connectors_enabled', false ) ) {
			return self::result( 'chatgpt_connector', 'pass', __( 'ChatGPT and other OAuth connectors are on in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result( 'chatgpt_connector', 'warn', __( 'ChatGPT and other OAuth connectors are off in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ), __( 'Open Settings > WP Abilities and turn on "ChatGPT & Other OAuth Connectors". Needed for ChatGPT and other OAuth clients.', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Our tools are registered and not switched off in Enable Abilities.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function tools_visible(): array {
		$denylist = self::denylist();
		$missing  = array();
		$disabled = array();

		foreach ( Registrar::abilities() as $class_name ) {
			$name = $class_name::NAME;
			if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $name ) ) {
				$missing[] = $name;
			} elseif ( in_array( $name, $denylist, true ) ) {
				$disabled[] = $name;
			}
		}

		// The workflows depend on the analytics tools, whoever registers them.
		foreach ( $denylist as $denied ) {
			if ( str_starts_with( $denied, 'wc-analytics/' ) ) {
				$disabled[] = $denied;
			}
		}

		if ( $missing ) {
			return self::result(
				'tools_visible',
				'fail',
				__( 'Some Blaze MCP Toolkit tools are not registered', 'blaze-mcp-toolkit-for-woocommerce' ),
				/* translators: %s: comma separated tool names. */
				sprintf( __( 'These tools did not register: %s. Deactivate and reactivate the plugin, and check that WordPress is 6.9 or newer.', 'blaze-mcp-toolkit-for-woocommerce' ), implode( ', ', $missing ) )
			);
		}
		if ( $disabled ) {
			return self::result(
				'tools_visible',
				'warn',
				__( 'Some Blaze MCP Toolkit tools are switched off in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ),
				/* translators: %s: comma separated tool names. */
				sprintf( __( 'AI assistants cannot see: %s. Turn them back on under Settings > WP Abilities, in the third-party tools list.', 'blaze-mcp-toolkit-for-woocommerce' ), implode( ', ', $disabled ) )
			);
		}
		return self::result( 'tools_visible', 'pass', __( 'Blaze MCP Toolkit tools are registered and visible to AI assistants', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * Whether we are leaving the store tools to WooCommerce for Claude.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function wcfc_standaside(): array {
		if ( Commerce::is_standing_aside() ) {
			return self::result(
				'wcfc_standaside',
				'warn',
				__( 'Standing aside for WooCommerce for Claude', 'blaze-mcp-toolkit-for-woocommerce' ),
				__( 'WooCommerce for Claude is active, so the analytics and store tools come from it and this plugin does not add its own copies. Our store health, update history, access check and workflow tools still work. Nothing to fix.', 'blaze-mcp-toolkit-for-woocommerce' )
			);
		}
		return self::result( 'wcfc_standaside', 'pass', __( 'No conflicting WooCommerce for Claude plugin', 'blaze-mcp-toolkit-for-woocommerce' ) );
	}

	/**
	 * WooCommerce Analytics data is complete enough to report on.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function analytics_import(): array {
		$reason = AnalyticsGuard::unavailable_reason();
		if ( null === $reason ) {
			return self::result( 'analytics_import', 'pass', __( 'WooCommerce Analytics is on and up to date', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result( 'analytics_import', 'warn', __( 'WooCommerce Analytics data is incomplete', 'blaze-mcp-toolkit-for-woocommerce' ), AnalyticsGuard::message( $reason ) );
	}

	/**
	 * Order storage mode (information only).
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function hpos_mode(): array {
		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return self::result(
			'hpos_mode',
			'pass',
			$hpos
				? __( 'Orders are stored in the high-performance order tables (HPOS)', 'blaze-mcp-toolkit-for-woocommerce' )
				: __( 'Orders are stored in the legacy posts tables (HPOS is off)', 'blaze-mcp-toolkit-for-woocommerce' ),
			''
		);
	}

	/**
	 * Other tools that expose customer names, emails or addresses.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function pii_tools(): array {
		$found = array();
		foreach ( self::exposed_abilities() as $name => $ability ) {
			if ( self::is_pii_name( $name ) ) {
				$found[] = $name;
			}
		}

		if ( ! $found ) {
			return self::result( 'pii_tools', 'pass', __( 'No customer or order-detail tools are exposed by other plugins', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return self::result(
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
	private static function write_tools(): array {
		$found = array();
		foreach ( self::exposed_abilities() as $name => $ability ) {
			if ( self::is_write( $name, $ability->get_meta()['annotations'] ?? array() ) ) {
				$found[] = $name;
			}
		}

		if ( ! $found ) {
			return self::result( 'write_tools', 'pass', __( 'No other plugins expose tools that can change your store', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}

		$shown = array_slice( $found, 0, 12 );
		$more  = count( $found ) - count( $shown );
		return self::result(
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
	 * Reminder about the edge (CDN or firewall) in front of the site.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function edge_guidance(): array {
		return self::result(
			'edge_guidance',
			'warn',
			__( 'Check your firewall or CDN allows the connection paths', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'Allow these paths through Cloudflare, Kinsta Bot Protection or any other firewall: /.well-known/oauth-*, /oauth/* and /wp-json/mcp/*. If connecting fails with a 403 the first time, the edge is blocking it: add those path exceptions. Do not bypass the block by matching a user agent. Also log in to wp-admin before clicking Connect, and click Allow once.', 'blaze-mcp-toolkit-for-woocommerce' )
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
	private static function exposed_abilities(): array {
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
	 * Whether an ability returns customer personal data.
	 *
	 * @param string $name Ability name.
	 */
	public static function is_pii_name( string $name ): bool {
		return in_array( $name, self::PII_ABILITIES, true );
	}

	/**
	 * Whether an ability can change the store or site. Our own tools and the wc-analytics tools are
	 * never flagged. Otherwise the ability's annotations decide; with no readonly annotation it is
	 * treated as write-capable unless its name says it only reads.
	 *
	 * @param string               $name        Ability name.
	 * @param array<string, mixed> $annotations The ability's meta.annotations.
	 */
	public static function is_write( string $name, array $annotations ): bool {
		if ( str_starts_with( $name, Registrar::CATEGORY . '/' ) || str_starts_with( $name, 'mcp-adapter/' ) || str_starts_with( $name, 'wc-analytics/' ) ) {
			return false;
		}

		$readonly    = $annotations['readonly'] ?? null;
		$destructive = true === ( $annotations['destructive'] ?? false );

		if ( true === $readonly ) {
			return $destructive;
		}
		if ( false === $readonly || $destructive ) {
			return true;
		}

		$part = false !== strpos( $name, '/' ) ? substr( $name, (int) strpos( $name, '/' ) + 1 ) : $name;
		foreach ( self::READ_PREFIXES as $prefix ) {
			if ( str_starts_with( $part, $prefix ) ) {
				return false;
			}
		}
		foreach ( self::READ_SUFFIXES as $suffix ) {
			if ( str_ends_with( $part, $suffix ) ) {
				return false;
			}
		}
		return true;
	}
}
