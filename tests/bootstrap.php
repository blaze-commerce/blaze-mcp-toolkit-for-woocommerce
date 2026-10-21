<?php
/**
 * Unit-test bootstrap: stubs the few WordPress functions the units touch.
 * Integration behaviour is covered by tests/Integration under wp-env.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['blzmcp_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['blzmcp_test_options'][ $name ]          = $value;
		$GLOBALS['blzmcp_test_option_autoload'][ $name ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $name ) {
		return $GLOBALS['blzmcp_test_transients'][ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $name, $value, int $expiration = 0 ): bool {
		$GLOBALS['blzmcp_test_transients'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $name ): bool {
		unset( $GLOBALS['blzmcp_test_transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): int {
		return (int) ( $GLOBALS['blzmcp_test_did_action'][ $hook ] ?? 0 );
	}
}
if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( string $hook, $args = null, string $group = '' ) {
		return isset( $GLOBALS['blzmcp_test_scheduled'][ $hook ] );
	}
}
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( int $ts, int $interval, string $hook, array $args = array(), string $group = '' ): int {
		$GLOBALS['blzmcp_test_scheduled'][ $hook ] = array( $interval, $group );
		return 1;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}
