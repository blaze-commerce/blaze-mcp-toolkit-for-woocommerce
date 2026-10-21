<?php
/**
 * Tiny assertion helpers for wp-env integration scripts (run via `wp eval-file`).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

$GLOBALS['blzmcp_it_failures'] = 0;
$GLOBALS['blzmcp_it_passes']   = 0;

function blzmcp_it_assert( $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['blzmcp_it_passes'];
		echo "PASS  {$label}\n";
		return;
	}
	++$GLOBALS['blzmcp_it_failures'];
	echo "FAIL  {$label}\n";
}

function blzmcp_it_equals( $expected, $actual, string $label ): void {
	blzmcp_it_assert( $expected === $actual, $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

function blzmcp_it_finish(): void {
	echo "\n{$GLOBALS['blzmcp_it_passes']} passed, {$GLOBALS['blzmcp_it_failures']} failed\n";
	if ( $GLOBALS['blzmcp_it_failures'] > 0 ) {
		exit( 1 );
	}
}
