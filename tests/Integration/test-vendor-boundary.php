<?php
/**
 * Vendor-name boundary: other plugins' class, option, constant and route names appear only under
 * src/Connectors/. Anywhere else they are a leak of connector knowledge.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-vendor-boundary.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

$pattern = '/ewpa_|EWPA_|ewpa\/|easy_mcp_ai_|EASY_MCP_AI_|Easy_MCP_AI\\\\|WP\\\\MCP\\\\|easy-mcp-ai\//';
$root    = BLZMCP_DIR . 'src';
$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$count   = 0;
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$file_path = str_replace( '\\', '/', $file->getPathname() );
	if ( str_contains( $file_path, '/src/Connectors/' ) ) {
		continue;
	}
	++$count;
	$source = (string) file_get_contents( $file_path );
	$found  = preg_match_all( $pattern, $source, $matches );
	blzmcp_it_equals( 0, $found, 'no vendor names in ' . substr( $file_path, strlen( $root ) + 1 ) . ( $found ? ' (' . implode( ', ', array_unique( $matches[0] ) ) . ')' : '' ) );
}
blzmcp_it_assert( $count > 10, 'scanned the src/ files outside Connectors (' . $count . ')' );

blzmcp_it_finish();
