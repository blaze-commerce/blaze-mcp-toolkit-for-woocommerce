<?php
/**
 * Setup checks: is everything needed for an AI connection in place?
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Admin;

use Blaze\McpToolkit\Abilities\AnalyticsNaming;
use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Bundled\AnalyticsGuard;
use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Connectors\Availability;
use Blaze\McpToolkit\Connectors\Connector;
use Blaze\McpToolkit\Connectors\Connectors;
use Blaze\McpToolkit\Connectors\RecommendedConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of checks shown on the setup screen. Read-only: it only reads options and the
 * Abilities registry. The connection row comes first, then the rows for the connection state:
 * shared rows built here, connector rows from the sign-in owner's checks().
 */
final class SetupChecks {

	/**
	 * Row ids per connection state, after the connection row. Recommended is the 1.0 order.
	 */
	private const ORDER = array(
		'recommended' => array( 'woocommerce', 'mcp_adapter', 'enable_abilities', 'oauth_on', 'chatgpt_connector', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode', 'pii_tools', 'write_tools', 'edge_guidance' ),
		'easy-mcp'    => array( 'woocommerce', 'easy_tools_on', 'easy_signin_access', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode', 'easy_rest_read', 'easy_write_tools', 'easy_woo_group', 'edge_guidance' ),
		'both'        => array( 'woocommerce', 'easy_tools_on', 'easy_signin_access', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode', 'easy_rest_read', 'easy_write_tools', 'easy_woo_group', 'edge_guidance' ),
		'none'        => array( 'woocommerce', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode' ),
	);

	/**
	 * Name parts (after the namespace) that mark an un-annotated ability as read-only: a get, list
	 * or search verb, optionally after one vendor segment (wc-get-orders, yoast-get-seo).
	 */
	private const READ_PATTERN = '/^([a-z0-9]+-)?(get|list|search)-/';

	/**
	 * Name endings that mark an un-annotated ability as read-only.
	 */
	private const READ_SUFFIXES = array( '-query', '-info' );

	/**
	 * Read-only abilities whose names fit no pattern (stats and reports).
	 */
	private const READ_NAMES = array( 'site-stats' );

	/**
	 * Names that look like searches but change content.
	 */
	private const WRITE_NAMES = array( 'search-replace', 'buscar-reemplazar' );

	/**
	 * Run every check.
	 *
	 * @return array<int, array{id: string, status: string, label: string, fix: string}>
	 */
	public static function run(): array {
		$state = Connectors::state();
		$owner = Connectors::signin_owner();

		$rows = array(
			'woocommerce'      => self::woocommerce(),
			'tools_visible'    => self::tools_visible( $state, $owner ),
			'wcfc_standaside'  => self::wcfc_standaside(),
			'analytics_import' => self::analytics_import(),
			'hpos_mode'        => self::hpos_mode(),
		);
		if ( null !== $owner ) {
			$rows                 += $owner->checks();
			$rows['edge_guidance'] = self::edge_guidance( $owner );
		}

		$out = array( self::connection( $state ) );
		foreach ( self::ORDER[ $state ] ?? self::ORDER['none'] as $id ) {
			if ( isset( $rows[ $id ] ) ) {
				$out[] = $rows[ $id ];
			}
		}
		return $out;
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
	public static function result( string $id, string $status, string $label, string $fix = '' ): array {
		return array(
			'id'     => $id,
			'status' => $status,
			'label'  => $label,
			'fix'    => $fix,
		);
	}

	/**
	 * How AI assistants connect: which connection plugin is active.
	 *
	 * @param string $state Connectors::state().
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function connection( string $state ): array {
		$rec  = Connectors::all()['recommended']->label();
		$easy = Connectors::all()['easy-mcp']->label();
		switch ( $state ) {
			case 'recommended':
				/* translators: %s: connection plugin names. */
				return self::result( 'connection', 'pass', sprintf( __( 'Connected through %s', 'blaze-mcp-toolkit-for-woocommerce' ), $rec ) );
			case 'easy-mcp':
				/* translators: %s: connection plugin name. */
				return self::result( 'connection', 'pass', sprintf( __( 'Connected through %s', 'blaze-mcp-toolkit-for-woocommerce' ), $easy ) );
			case 'both':
				return self::result(
					'connection',
					'warn',
					__( 'Two connection plugins are active', 'blaze-mcp-toolkit-for-woocommerce' ),
					sprintf(
						/* translators: %1$s: connection plugin name (used three times). */
						__( 'New Claude or ChatGPT connections go through %1$s: for a new connection, use the %1$s connection link below. To use the other one for new connections, deactivate %1$s.', 'blaze-mcp-toolkit-for-woocommerce' ),
						$easy
					)
				);
			default:
				return self::result(
					'connection',
					'fail',
					__( 'No AI connection plugin yet', 'blaze-mcp-toolkit-for-woocommerce' ),
					sprintf(
						/* translators: 1: recommended plugin names, 2: the other supported plugin. */
						__( 'Claude and ChatGPT need a connection plugin to reach your store. We recommend %1$s: install and activate both from the Plugins screen, then come back here. If you already use %2$s, activate it instead; this plugin works with either.', 'blaze-mcp-toolkit-for-woocommerce' ),
						$rec,
						$easy
					)
				);
		}
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
	 * Our tools (and the wc-analytics tools the workflows use) are registered and reachable. Every
	 * active connector's diagnose() is asked: removal by any connector wins, a switched-off or
	 * unknown state counts only from the sign-in owner. A tool of ours that is neither registered
	 * nor removed gets the registration advice. On the recommended setup, Enable Abilities removals
	 * read exactly as in 1.0.
	 *
	 * @param string         $state Connectors::state().
	 * @param Connector|null $owner Sign-in owner.
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function tools_visible( string $state, ?Connector $owner ): array {
		$active = array_filter( Connectors::all(), static fn( Connector $c ): bool => $c->is_active() );
		$names  = array();
		foreach ( Registrar::abilities() as $class_name ) {
			$names[ $class_name::NAME ] = true;
		}
		// Denylisted wc-analytics names first, in denylist order (as 1.0 listed them, including names
		// outside the known five), then the known ones.
		foreach ( RecommendedConnector::denylist() as $denied ) {
			if ( str_starts_with( $denied, AnalyticsNaming::PREFIX ) ) {
				$names[ $denied ] ??= false;
			}
		}
		foreach ( AnalyticsNaming::ALIASES as $analytics ) {
			$names[ $analytics ] ??= false;
		}

		$missing = array();
		$groups  = array();
		foreach ( $names as $name => $ours ) {
			$why        = self::diagnosis( $name, $active, $owner );
			$registered = function_exists( 'wp_has_ability' ) && wp_has_ability( $name );
			if ( ! $registered && ( null === $why || Availability::REMOVED_GLOBALLY !== $why->kind ) ) {
				// Not on this store. Ours should be; wc-analytics may simply be absent.
				if ( $ours ) {
					$missing[] = $name;
				}
				continue;
			}
			if ( null !== $why ) {
				$key                       = $why->kind . '|' . $why->connector_id . '|' . $why->reason;
				$groups[ $key ]['why']     = $why;
				$groups[ $key ]['names'][] = $name;
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
		if ( ! $groups ) {
			return null === $owner
				? self::result( 'tools_visible', 'pass', __( 'Blaze MCP Toolkit tools are registered', 'blaze-mcp-toolkit-for-woocommerce' ) )
				: self::result( 'tools_visible', 'pass', __( 'Blaze MCP Toolkit tools are registered and visible to AI assistants', 'blaze-mcp-toolkit-for-woocommerce' ) );
		}

		$only_rec_removed = 'recommended' === $state;
		$switched_off     = false;
		foreach ( $groups as $group ) {
			$only_rec_removed = $only_rec_removed && Availability::REMOVED_GLOBALLY === $group['why']->kind && 'recommended' === $group['why']->connector_id;
			$switched_off     = $switched_off || Availability::UNKNOWN !== $group['why']->kind;
		}
		if ( $only_rec_removed ) {
			return self::result(
				'tools_visible',
				'warn',
				__( 'Some Blaze MCP Toolkit tools are switched off in Enable Abilities', 'blaze-mcp-toolkit-for-woocommerce' ),
				/* translators: %s: comma separated tool names. */
				sprintf( __( 'AI assistants cannot see: %s. Turn them back on under Settings > WP Abilities, in the third-party tools list.', 'blaze-mcp-toolkit-for-woocommerce' ), implode( ', ', array_merge( ...array_column( $groups, 'names' ) ) ) )
			);
		}

		$lines = array();
		foreach ( $groups as $group ) {
			$why     = $group['why'];
			$lines[] = Availability::UNKNOWN === $why->kind
				? sprintf(
					/* translators: 1: comma separated tool names, 2: reason, 3: settings screen URL. */
					__( 'Couldn\'t check %1$s: %2$s. Check them at %3$s.', 'blaze-mcp-toolkit-for-woocommerce' ),
					implode( ', ', $group['names'] ),
					$why->reason,
					$why->settings_url
				)
				: sprintf(
					/* translators: 1: comma separated tool names, 2: reason, 3: settings screen URL. */
					__( 'AI assistants cannot see %1$s: %2$s. Switch them on at %3$s.', 'blaze-mcp-toolkit-for-woocommerce' ),
					implode( ', ', $group['names'] ),
					$why->reason,
					$why->settings_url
				);
		}
		return self::result(
			'tools_visible',
			'warn',
			$switched_off
				? __( 'Some Blaze MCP Toolkit tools are switched off', 'blaze-mcp-toolkit-for-woocommerce' )
				: __( 'Couldn\'t check whether some Blaze MCP Toolkit tools are switched on', 'blaze-mcp-toolkit-for-woocommerce' ),
			implode( ' ', $lines )
		);
	}

	/**
	 * Why an ability is unavailable: the first removal reported by an active connector, else the
	 * sign-in owner's switched-off or unknown state, else null. diagnose() is only asked of active
	 * connectors.
	 *
	 * @param string                  $name   Ability name.
	 * @param array<string,Connector> $active Active connectors.
	 * @param Connector|null          $owner  Sign-in owner.
	 */
	private static function diagnosis( string $name, array $active, ?Connector $owner ): ?Availability {
		$own = null;
		foreach ( $active as $connector ) {
			$why = $connector->diagnose( $name );
			if ( null === $why ) {
				continue;
			}
			if ( Availability::REMOVED_GLOBALLY === $why->kind ) {
				return $why;
			}
			if ( null !== $owner && $connector->id() === $owner->id() ) {
				$own = $why;
			}
		}
		return $own;
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
		// The last 30 days, the analytics tools' default period.
		$reason = AnalyticsGuard::unavailable_reason( array( 'compare' => false ) );
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
	 * Reminder about the edge (CDN or firewall) in front of the site, with the sign-in owner's
	 * paths.
	 *
	 * @param Connector $owner Sign-in owner.
	 * @return array{id: string, status: string, label: string, fix: string}
	 */
	private static function edge_guidance( Connector $owner ): array {
		$paths = $owner->firewall_paths();
		$last  = array_pop( $paths );
		$list  = $paths
			? sprintf(
				/* translators: 1: comma separated URL paths, 2: the last URL path. */
				__( '%1$s and %2$s', 'blaze-mcp-toolkit-for-woocommerce' ),
				implode( ', ', $paths ),
				(string) $last
			)
			: (string) $last;
		$allow = 'recommended' === $owner->id() ? __( 'Allow', 'blaze-mcp-toolkit-for-woocommerce' ) : __( 'Approve & Continue', 'blaze-mcp-toolkit-for-woocommerce' );

		return self::result(
			'edge_guidance',
			'warn',
			__( 'Check your firewall or CDN allows the connection paths', 'blaze-mcp-toolkit-for-woocommerce' ),
			sprintf(
				/* translators: 1: list of URL paths, 2: the button that grants access on the sign-in screen. */
				__( 'Allow these paths through Cloudflare, Kinsta Bot Protection or any other firewall: %1$s. If connecting fails with a 403 the first time, the edge is blocking it: add those path exceptions. Do not bypass the block by matching a user agent. Also log in to wp-admin before clicking Connect, and click %2$s once.', 'blaze-mcp-toolkit-for-woocommerce' ),
				$list,
				$allow
			)
		);
	}

	/**
	 * Whether an ability returns customer personal data.
	 *
	 * @param string $name Ability name.
	 */
	public static function is_pii_name( string $name ): bool {
		return RecommendedConnector::is_pii_name( $name );
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
		if ( in_array( $part, self::WRITE_NAMES, true ) ) {
			return true;
		}
		if ( in_array( $part, self::READ_NAMES, true ) || 1 === preg_match( self::READ_PATTERN, $part ) ) {
			return false;
		}
		foreach ( self::READ_SUFFIXES as $suffix ) {
			if ( str_ends_with( $part, $suffix ) ) {
				return false;
			}
		}
		return true;
	}
}
