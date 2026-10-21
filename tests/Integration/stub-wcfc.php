<?php
/**
 * Loaded with `wp --require=` before WordPress boots: pretends WooCommerce for Claude is active.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( ! defined( 'WOOCOMMERCE_CLAUDE_VERSION' ) ) {
	define( 'WOOCOMMERCE_CLAUDE_VERSION', '0.0.0-stub' );
}
