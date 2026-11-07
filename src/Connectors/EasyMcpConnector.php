<?php
/**
 * The Easy MCP AI connector.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

use Blaze\McpToolkit\Abilities\AnalyticsNaming;
use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Admin\SetupChecks;

defined( 'ABSPATH' ) || exit;

/**
 * Easy MCP AI serves the tools and handles sign-in itself.
 */
final class EasyMcpConnector implements Connector {

	/**
	 * Whole plain-English reasons for the short codes Tool_State::resolve() returns with a
	 * switched-off state. Each reads on its own after "cannot see X:". Unknown codes are dropped
	 * rather than shown (the generic reason is used).
	 */
	private const REASON_WORDS = array(
		'disabled' => 'switched off in Easy MCP AI on its tools screen',
		'held'     => 'new in Easy MCP AI and waiting to be switched on',
		'policy'   => 'switched off by Easy MCP AI\'s tool settings',
		'override' => "switched off by the site's configuration",
		'default'  => 'off in Easy MCP AI unless switched on',
	);

	/**
	 * Easy MCP AI's general REST read tool (2.4.0 includes/tools/rest/).
	 */
	private const REST_READ_TOOL = 'wp_rest_read';

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
			$reason = self::REASON_WORDS[ $state[1] ] ?? 'switched off in Easy MCP AI';
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
	 * Easy MCP's effective state for the ability's tool.
	 *
	 * @param string $slug Ability name.
	 * @return array{0:bool,1:string}|null
	 */
	private function effective_state( string $slug ): ?array {
		return $this->resolve_state( $this->tool_name( $slug ), false );
	}

	/**
	 * Easy MCP's effective state for a tool, from the read-only Tool_State::resolve() (never
	 * is_enabled(), which can persist). Null when unknown: the method is missing, throws, or
	 * returns anything but an array with a bool first.
	 *
	 * @param string $tool        Easy MCP tool name.
	 * @param bool   $default_off Whether the tool is off unless switched on.
	 * @return array{0:bool,1:string}|null
	 */
	private function resolve_state( string $tool, bool $default_off ): ?array {
		$vendor = '\Easy_MCP_AI\Tools\Tool_State';
		if ( ! method_exists( $vendor, 'resolve' ) ) {
			return null;
		}
		try {
			$result = $vendor::resolve( $tool, $default_off );
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
	 * OAuth fallbacks /authorize, /token and /register (includes/oauth/class-root-fallback.php).
	 */
	public function firewall_paths(): array {
		return array( '/.well-known/oauth-*', '/.well-known/openid-configuration', '/easy-mcp-ai/*', '/wp-json/easy-mcp-ai/*', '/authorize', '/token', '/register' );
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
	 * Easy MCP AI's setup rows: easy_tools_on, easy_signin_access, easy_rest_read, easy_write_tools
	 * and easy_woo_group. Read-only: options and Tool_State::resolve() only.
	 *
	 * @return array<string, array{id:string,status:string,label:string,fix:string}>
	 */
	public function checks(): array {
		return array(
			'easy_tools_on'      => $this->tools_on_row(),
			'easy_signin_access' => $this->signin_access_row(),
			'easy_rest_read'     => $this->rest_read_row(),
			'easy_write_tools'   => $this->write_tools_row(),
			'easy_woo_group'     => $this->woo_group_row(),
		);
	}

	/**
	 * Our registered tools and the wc-analytics tools are all ticked under Tools › Abilities.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function tools_on_row(): array {
		$unticked = array();
		$names    = function_exists( 'wp_get_abilities' ) ? array_map( static fn( $a ) => $a->get_name(), wp_get_abilities() ) : array();
		foreach ( $names as $name ) {
			if ( ( str_starts_with( $name, Registrar::CATEGORY . '/' ) || str_starts_with( $name, AnalyticsNaming::PREFIX ) ) && ! $this->is_ticked( $name ) ) {
				$unticked[] = $name;
			}
		}
		if ( ! $unticked ) {
			return SetupChecks::result( 'easy_tools_on', 'pass', __( 'All Blaze MCP Toolkit tools are ticked in Easy MCP AI', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		return SetupChecks::result(
			'easy_tools_on',
			'warn',
			__( 'Some Blaze MCP Toolkit tools are not ticked in Easy MCP AI', 'blaze-mcp-toolkit-for-woocommerce' ),
			sprintf(
				/* translators: 1: comma separated tool names, 2: settings screen URL. */
				__( 'AI assistants connected through Easy MCP AI cannot see these until you tick them under Easy MCP AI › Tools › Abilities (%2$s): %1$s.', 'blaze-mcp-toolkit-for-woocommerce' ),
				implode( ', ', $unticked ),
				$this->settings_url()
			)
		);
	}

	/**
	 * Reminder about the access level chosen when connecting. Easy MCP AI does not keep the granted
	 * level anywhere this plugin can read, so this is always a reminder.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function signin_access_row(): array {
		return SetupChecks::result(
			'easy_signin_access',
			'warn',
			__( 'Choose the right access level when you connect', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'When you click Connect, Easy MCP AI asks for an access level. Choose Custom with Abilities ticked: under Customize permissions, tick the Abilities entries for this plugin and for WooCommerce Analytics. Full access also works, but it also turns on Easy MCP AI\'s own tools that can change your site. The default level hides this plugin\'s tools.', 'blaze-mcp-toolkit-for-woocommerce' )
		);
	}

	/**
	 * Easy MCP AI's general read tool, which can return customer details, is switched off.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function rest_read_row(): array {
		$state = $this->resolve_state( self::REST_READ_TOOL, false );
		$where = admin_url( 'admin.php?page=easy-mcp-ai-settings' );
		if ( null !== $state && false === $state[0] ) {
			return SetupChecks::result( 'easy_rest_read', 'pass', __( 'Easy MCP AI\'s general "read any data" tool (wp_rest_read) is switched off', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		if ( null === $state ) {
			return SetupChecks::result(
				'easy_rest_read',
				'warn',
				__( 'Couldn\'t check Easy MCP AI\'s general "read any data" tool', 'blaze-mcp-toolkit-for-woocommerce' ),
				sprintf(
					/* translators: %s: settings screen URL. */
					__( 'Easy MCP AI did not say whether its wp_rest_read tool is switched on. That tool can return customer names and addresses even with its WooCommerce tools off. To keep customer details out of AI chats, make sure wp_rest_read is switched off under Easy MCP AI › Settings › Advanced › Disabled tools (%s).', 'blaze-mcp-toolkit-for-woocommerce' ),
					$where
				)
			);
		}
		return SetupChecks::result(
			'easy_rest_read',
			'warn',
			__( 'Easy MCP AI\'s general "read any data" tool can return customer details', 'blaze-mcp-toolkit-for-woocommerce' ),
			sprintf(
				/* translators: %s: settings screen URL. */
				__( 'Easy MCP AI\'s general "read any data" tool (wp_rest_read) can return customer names and addresses even with its WooCommerce tools off. To keep customer details out of AI chats, switch off wp_rest_read under Easy MCP AI › Settings › Advanced › Disabled tools (%s).', 'blaze-mcp-toolkit-for-woocommerce' ),
				$where
			)
		);
	}

	/**
	 * Easy MCP AI's own write tools, and its approval setting. Counting which write tools are on,
	 * or which of them would really ask, would mean loading Easy MCP AI's tool registry, which is
	 * not read-only. Its approval setting (approval_required in includes/class-config.php) is also
	 * not a guarantee: Approval_Gate::requires_approval() exempts approval_never matches and tools
	 * with a "never" policy (2.3.1 and 2.4.0) and honours a per-tool requireApproval=false choice
	 * (2.4.0). So this reports only the setting it checked, as a warning either way.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function write_tools_row(): array {
		$settings  = admin_url( 'admin.php?page=easy-mcp-ai-settings' );
		$read_only = __( 'Our tools are read-only, but these are not. For a read-only connection, choose Custom when you connect and tick only the Abilities entries for this plugin and WooCommerce Analytics.', 'blaze-mcp-toolkit-for-woocommerce' );
		if ( $this->approval_required() ) {
			return SetupChecks::result(
				'easy_write_tools',
				'warn',
				__( 'Easy MCP AI\'s approval setting is on, but some tools may run without asking', 'blaze-mcp-toolkit-for-woocommerce' ),
				sprintf(
					/* translators: 1: sentence about read-only connections, 2: settings screen URL. */
					__( 'Easy MCP AI comes with its own tools that can create, edit or delete posts, products, orders and settings. Its approval setting is on, but it allows exceptions per tool, so some tools may make changes without asking. %1$s You can review its approval settings under Easy MCP AI › Settings (%2$s).', 'blaze-mcp-toolkit-for-woocommerce' ),
					$read_only,
					$settings
				)
			);
		}
		return SetupChecks::result(
			'easy_write_tools',
			'warn',
			__( 'Easy MCP AI\'s approval setting is off, so its tools can make changes without asking', 'blaze-mcp-toolkit-for-woocommerce' ),
			sprintf(
				/* translators: 1: sentence about read-only connections, 2: settings screen URL. */
				__( 'Easy MCP AI comes with its own tools that can create, edit or delete posts, products, orders and settings, and its approval setting is off. %1$s Turning approval on under Easy MCP AI › Settings (%2$s) adds a prompt for most changes.', 'blaze-mcp-toolkit-for-woocommerce' ),
				$read_only,
				$settings
			)
		);
	}

	/**
	 * Easy MCP AI's WooCommerce tool group is off.
	 *
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private function woo_group_row(): array {
		$groups = get_option( 'easy_mcp_ai_enabled_plugin_groups', array() );
		if ( ! is_array( $groups ) || ! in_array( 'woocommerce', $groups, true ) ) {
			return SetupChecks::result( 'easy_woo_group', 'pass', __( 'Easy MCP AI\'s WooCommerce tools are off', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}
		$util = \Automattic\WooCommerce\Utilities\OrderUtil::class;
		$hpos = class_exists( $util ) && $util::custom_orders_table_usage_is_enabled();
		return SetupChecks::result(
			'easy_woo_group',
			'warn',
			__( 'Easy MCP AI\'s WooCommerce tools are on', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'Its order and customer tools return customer names, emails and addresses to your AI provider. Our tools never do.', 'blaze-mcp-toolkit-for-woocommerce' )
			. ( $hpos ? ' ' . __( 'Its sales reports use WooCommerce\'s older report tables and may be incomplete when orders are stored in the newer order tables (HPOS) and not fully synced back. Use this plugin\'s sales figures.', 'blaze-mcp-toolkit-for-woocommerce' ) : '' )
			. ' ' . sprintf(
				/* translators: %s: settings screen URL. */
				__( 'To turn them off, switch off the WooCommerce group under Easy MCP AI › Tools (%s).', 'blaze-mcp-toolkit-for-woocommerce' ),
				$this->settings_url()
			)
		);
	}

	/**
	 * Easy MCP AI's approval_required setting, read through its Config::get() (option, constant or
	 * environment; read-only), else the stored option.
	 */
	private function approval_required(): bool {
		$config = '\Easy_MCP_AI\Config';
		if ( method_exists( $config, 'get' ) ) {
			try {
				return true === $config::get( 'approval_required' );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		return in_array( get_option( 'easy_mcp_ai_approval_required', false ), array( true, 1, '1', 'true' ), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Easy MCP AI';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Easy MCP AI 2.3.1 and 2.4.0 turn an ability's WP_Error into an exception and cut its message
	 * to Detailed_Tool_Error::MAX_MESSAGE (200 bytes) in Server::sanitize_error_message(), adding
	 * "…[truncated]". Abilities have no other channel to the client.
	 */
	public function max_error_message_bytes(): ?int {
		return 200;
	}
}
