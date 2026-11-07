<?php
/**
 * Easy MCP AI tool-name rule.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the tool name Easy MCP AI gives an ability (identical in 2.3.1 and 2.4.0).
 */
final class EasyMcpNaming {

	private const PREFIX     = 'wp_ability_';
	private const MAX_LENGTH = 64;

	/**
	 * Tool name for an ability slug.
	 *
	 * @param string $slug Ability name, e.g. "wc-analytics/totals".
	 */
	public static function tool_name( string $slug ): string {
		$vendor = '\Easy_MCP_AI\Tools\Dynamic_Tool_Registrar';
		if ( class_exists( $vendor ) && method_exists( $vendor, 'build_tool_name' ) ) {
			return (string) $vendor::build_tool_name( $slug );
		}

		$normalized = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( $slug ) ), '_' );
		$tool_name  = self::PREFIX . $normalized;
		if ( strlen( $tool_name ) <= self::MAX_LENGTH ) {
			return $tool_name;
		}

		$hash   = substr( md5( $slug ), 0, 8 );
		$budget = self::MAX_LENGTH - strlen( self::PREFIX ) - 1 - strlen( $hash );

		return self::PREFIX . rtrim( substr( $normalized, 0, $budget ), '_' ) . '_' . $hash;
	}
}
