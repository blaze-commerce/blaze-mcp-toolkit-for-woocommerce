<?php
/**
 * Which connector served the current REST call.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * A stack of connector ids. Only the callback of a route a connector serves is wrapped, so the frame
 * is pushed when the callback starts and restored in a finally block when it unwinds, whether it
 * returns or throws. Routes served by no connector are left untouched, as are permission callbacks.
 */
final class RequestContext {

	/**
	 * Frames, innermost last. A frame is a connector id or null.
	 *
	 * @var array<int,?string>
	 */
	private static array $stack = array();

	/**
	 * Route to connector id resolver. Null means the production lookup, which does not exist until the registry lands.
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Wrap the callbacks of connector routes.
	 */
	public static function register(): void {
		add_filter( 'rest_endpoints', array( self::class, 'wrap_endpoints' ), PHP_INT_MAX );
	}

	/**
	 * Test seam: how a route maps to a connector id.
	 *
	 * @param callable $fn Receives the route, returns the connector id or null.
	 */
	public static function set_resolver( callable $fn ): void {
		self::$resolver = $fn;
	}

	/**
	 * The innermost connector id, or null when no connector route is running.
	 */
	public static function current(): ?string {
		return array() === self::$stack ? null : self::$stack[ array_key_last( self::$stack ) ];
	}

	/**
	 * Run a function with a connector id on top of the stack.
	 *
	 * @param ?string  $connector_id Connector id.
	 * @param callable $fn           Called exactly once. Its exceptions propagate unchanged.
	 * @return mixed
	 */
	public static function run( ?string $connector_id, callable $fn ) {
		$depth         = count( self::$stack );
		self::$stack[] = $connector_id;
		try {
			return $fn();
		} finally {
			self::$stack = array_slice( self::$stack, 0, $depth );
		}
	}

	/**
	 * Wrap a REST callback so it runs inside the given connector frame.
	 *
	 * @param callable $callback     Original callback.
	 * @param ?string  $connector_id Connector id.
	 */
	public static function wrap( callable $callback, ?string $connector_id ): \Closure {
		return static fn( ...$a ) => self::run( $connector_id, static fn() => $callback( ...$a ) );
	}

	/**
	 * `rest_endpoints` filter.
	 *
	 * @param mixed $endpoints Route map.
	 * @return mixed
	 */
	public static function wrap_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		foreach ( $endpoints as $route => $handlers ) {
			$id = self::resolve( (string) $route );
			if ( null === $id || ! is_array( $handlers ) ) {
				continue;
			}
			// A route is either one endpoint (has 'callback') or a list of them.
			if ( isset( $handlers['callback'] ) ) {
				if ( is_callable( $handlers['callback'] ) ) {
					$endpoints[ $route ]['callback'] = self::wrap( $handlers['callback'], $id );
				}
				continue;
			}
			foreach ( $handlers as $i => $handler ) {
				if ( is_array( $handler ) && isset( $handler['callback'] ) && is_callable( $handler['callback'] ) ) {
					$endpoints[ $route ][ $i ]['callback'] = self::wrap( $handler['callback'], $id );
				}
			}
		}
		return $endpoints;
	}

	/**
	 * Connector id for a route, or null.
	 *
	 * @param string $route REST route.
	 */
	private static function resolve( string $route ): ?string {
		return null === self::$resolver ? null : ( self::$resolver )( $route );
	}
}
