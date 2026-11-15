<?php
/**
 * WP-CLI `--require` helper for test-payment-methods.php (registry-unbuilt step). Loaded before
 * WordPress. WooCommerce's CLI runner fires `rest_api_init`, and Store API route registration then
 * builds the payment gateway registry; a plain request that never registers REST routes does not.
 * This removes every `rest_api_init` callback for this one CLI process, so the registry is
 * genuinely never built. Test-only; never loaded by the plugin.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$GLOBALS['wp_filter']['rest_api_init'][ PHP_INT_MIN ][] = array(
	'function'      => static function (): void {
		remove_all_actions( 'rest_api_init' );
	},
	'accepted_args' => 0,
);
