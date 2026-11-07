<?php
/**
 * Registry of supported MCP connectors.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * Looks connectors up by id or route and works out which are active.
 */
final class Connectors {

	/**
	 * Built once per request.
	 *
	 * @var array<string,Connector>|null
	 */
	private static ?array $all = null;

	/**
	 * Every supported connector, keyed by id.
	 *
	 * @return array<string,Connector>
	 */
	public static function all(): array {
		if ( null === self::$all ) {
			self::$all = array(
				'recommended' => new RecommendedConnector(),
				'easy-mcp'    => new EasyMcpConnector(),
			);
		}
		return self::$all;
	}

	/**
	 * One connector by id.
	 *
	 * @param string $id Connector id.
	 */
	public static function get( string $id ): ?Connector {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * 'none', 'recommended', 'easy-mcp' or 'both'.
	 */
	public static function state(): string {
		$rec  = self::all()['recommended']->is_active();
		$easy = self::all()['easy-mcp']->is_active();
		if ( $rec && $easy ) {
			return 'both';
		}
		if ( $easy ) {
			return 'easy-mcp';
		}
		return $rec ? 'recommended' : 'none';
	}

	/**
	 * The connector that handles sign-in: Easy MCP if active, else recommended if active.
	 */
	public static function signin_owner(): ?Connector {
		foreach ( array( 'easy-mcp', 'recommended' ) as $id ) {
			if ( self::all()[ $id ]->is_active() ) {
				return self::all()[ $id ];
			}
		}
		return null;
	}

	/**
	 * Id of the connector serving a REST route, or null.
	 *
	 * @param string $route REST route.
	 */
	public static function id_for_route( string $route ): ?string {
		foreach ( self::all() as $id => $connector ) {
			if ( $connector->serves_route( $route ) ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * The connector to describe or run against: the one serving this REST call if active, else the
	 * sign-in owner, else the recommended one.
	 */
	public static function execution(): Connector {
		$current = RequestContext::current();
		if ( null !== $current ) {
			$connector = self::get( $current );
			if ( null !== $connector && $connector->is_active() ) {
				return $connector;
			}
		}
		return self::signin_owner() ?? self::all()['recommended'];
	}
}
