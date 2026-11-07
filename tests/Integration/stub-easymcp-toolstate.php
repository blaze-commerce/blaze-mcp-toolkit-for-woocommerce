<?php
/**
 * Loaded with `wp --require=` before WordPress boots, after stub-easymcp.php: a minimal Easy MCP AI
 * Tool_State whose resolve() answers from a test global.
 *
 * $GLOBALS['blzmcp_toolstate_return'] is what resolve() returns (default array( true, 'default' )).
 * The sentinel 'throw:ArgumentCountError' makes it throw instead. Every resolve() call is logged
 * to $GLOBALS['blzmcp_toolstate_calls']; is_enabled() counts to $GLOBALS['blzmcp_toolstate_is_enabled']
 * so a test can prove it is never called.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Generic.CodeAnalysis.UnusedFunctionParameter

namespace Easy_MCP_AI\Tools;

if ( ! class_exists( Tool_State::class, false ) ) {
	/**
	 * Test double of Easy MCP AI's Tool_State.
	 */
	final class Tool_State {

		/**
		 * Effective state of a tool, from the test global.
		 *
		 * @param string $name        Tool name.
		 * @param bool   $default_off Whether the tool is off by default.
		 * @return mixed
		 */
		public static function resolve( $name, $default_off ) {
			$GLOBALS['blzmcp_toolstate_calls'][] = array( $name, $default_off );
			$value                               = array_key_exists( 'blzmcp_toolstate_return', $GLOBALS ) ? $GLOBALS['blzmcp_toolstate_return'] : array( true, 'default' );
			if ( 'throw:ArgumentCountError' === $value ) {
				throw new \ArgumentCountError( 'stub resolve() threw' );
			}
			return $value;
		}

		/**
		 * Must never be called by the toolkit (the real one can persist state).
		 *
		 * @param mixed $tool Tool.
		 */
		public static function is_enabled( $tool ): bool {
			$GLOBALS['blzmcp_toolstate_is_enabled'] = ( $GLOBALS['blzmcp_toolstate_is_enabled'] ?? 0 ) + 1;
			return true;
		}
	}
}
