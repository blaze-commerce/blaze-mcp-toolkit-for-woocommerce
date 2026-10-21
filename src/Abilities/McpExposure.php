<?php
/**
 * Makes the WooCommerce analytics abilities reachable over MCP.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The bundled commerce-abilities package (and WooCommerce for Claude's own copy) registers
 * wc-analytics/* without meta.mcp.public, so MCP Adapter's default server would not list them.
 * This filter only changes their metadata; it never registers anything. It applies whether the
 * abilities come from our bundled copy or from WooCommerce for Claude, because our workflows need
 * them reachable through Enable Abilities' connector either way.
 */
final class McpExposure {

	/**
	 * Ability name prefix this class exposes.
	 */
	public const PREFIX = 'wc-analytics/';

	/**
	 * Add the filter.
	 */
	public static function register(): void {
		add_filter( 'wp_register_ability_args', array( self::class, 'filter_args' ), 10, 2 );
	}

	/**
	 * Mark wc-analytics/* abilities public over MCP and visible in REST.
	 *
	 * @param mixed  $args Ability registration args.
	 * @param string $name Ability name.
	 * @return mixed
	 */
	public static function filter_args( $args, $name = '' ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || ! str_starts_with( $name, self::PREFIX ) ) {
			return $args;
		}

		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();

		$mcp['public']        = true;
		$meta['mcp']          = $mcp;
		$meta['show_in_rest'] = true;
		$args['meta']         = $meta;

		return $args;
	}
}
