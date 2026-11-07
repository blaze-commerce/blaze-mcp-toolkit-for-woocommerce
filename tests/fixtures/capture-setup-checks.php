<?php
/**
 * Captures tests/fixtures/setup-checks-1.0.json: the setup rows the 1.0 SetupChecks (commit
 * 8972431) returns under fixed inputs. The 1.1 test compares the recommended screen against it.
 *
 * Captured ONCE, from the unmodified 1.0 SetupChecks.php:
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/fixtures/capture-setup-checks.php
 *
 * Never re-run it on 1.1 code to make a test pass: the fixture is the 1.0 baseline. If the
 * environment changes (plugin versions, registered abilities), the test reports "baseline inputs
 * changed"; recapture only by checking out SetupChecks.php from 8972431 first.
 *
 * tests/Integration/test-setup-checks.php requires this file with BLZMCP_FX_LIBRARY defined, to
 * set the same inputs and describe them; nothing is captured then.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions

require_once dirname( __DIR__ ) . '/Integration/assert.php';

use Blaze\McpToolkit\Admin\SetupChecks;
use Blaze\McpToolkit\Bundled\AnalyticsGuard;

/**
 * The options the fixture is captured under.
 *
 * @return array<string, mixed>
 */
function blzmcp_fx_option_inputs(): array {
	return array(
		'ewpa_oauth_enabled'            => 1,
		'ewpa_oauth_connectors_enabled' => 0,
		'ewpa_thirdparty_disabled'      => array(),
		'woocommerce_analytics_enabled' => 'yes',
	);
}

/**
 * Set the controlled inputs: the options above and HPOS on. Returns a callable restoring the
 * previous values (deleting options that did not exist).
 */
function blzmcp_fx_set_inputs(): callable {
	$prev = array();
	foreach ( blzmcp_fx_option_inputs() as $name => $value ) {
		$prev[ $name ] = get_option( $name, null );
		update_option( $name, $value );
	}
	$util     = \Automattic\WooCommerce\Utilities\OrderUtil::class;
	$hpos_was = class_exists( $util ) && $util::custom_orders_table_usage_is_enabled();
	blzmcp_it_set_hpos( true );
	AnalyticsGuard::clear_cache();

	return static function () use ( $prev, $hpos_was ): void {
		blzmcp_it_set_hpos( $hpos_was );
		foreach ( $prev as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		AnalyticsGuard::clear_cache();
	};
}

/**
 * What the rows depend on, recorded next to them: the inputs as read back, versions and the
 * registered ability names. Our own plugin's version is left out (it is what changes).
 *
 * @return array<string, mixed>
 */
function blzmcp_fx_inputs(): array {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$options = array();
	foreach ( array_keys( blzmcp_fx_option_inputs() ) as $name ) {
		$options[ $name ] = get_option( $name, null );
	}
	$plugins = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( is_plugin_active( $file ) && ! str_starts_with( $file, 'blaze-mcp-toolkit-for-woocommerce/' ) ) {
			$plugins[ $file ] = $data['Version'];
		}
	}
	ksort( $plugins );
	$abilities = function_exists( 'wp_get_abilities' ) ? array_map( static fn( $a ) => $a->get_name(), wp_get_abilities() ) : array();
	sort( $abilities );
	$util = \Automattic\WooCommerce\Utilities\OrderUtil::class;

	return array(
		'options'          => $options,
		'hpos'             => class_exists( $util ) && $util::custom_orders_table_usage_is_enabled(),
		'analytics_reason' => AnalyticsGuard::unavailable_reason( array( 'compare' => false ) ),
		'wordpress'        => get_bloginfo( 'version' ),
		'plugins'          => $plugins,
		'abilities'        => array_values( $abilities ),
	);
}

/**
 * Rows reduced to the compared fields, in order.
 *
 * @param array<int, array<string, mixed>> $rows Setup rows.
 * @return array<int, array{id: mixed, status: mixed, label: mixed, fix: mixed}>
 */
function blzmcp_fx_rows( array $rows ): array {
	return array_values(
		array_map(
			static fn( $r ) => array(
				'id'     => $r['id'] ?? null,
				'status' => $r['status'] ?? null,
				'label'  => $r['label'] ?? null,
				'fix'    => $r['fix'] ?? null,
			),
			$rows
		)
	);
}

if ( ! defined( 'BLZMCP_FX_LIBRARY' ) ) {
	$restore = blzmcp_fx_set_inputs();
	try {
		$fixture = array(
			'_about'  => 'SetupChecks::run() from 1.0 (commit 8972431) under _inputs. Captured once; never regenerate from 1.1 code.',
			'_inputs' => blzmcp_fx_inputs(),
			'rows'    => blzmcp_fx_rows( SetupChecks::run() ),
		);
		// The 1.0 tools_visible row with health-summary on Enable Abilities' denylist.
		update_option( 'ewpa_thirdparty_disabled', array( 'blaze-mcp-toolkit/health-summary' ) );
		$fixture['denylist_tools_visible'] = blzmcp_fx_rows( array( array_column( SetupChecks::run(), null, 'id' )['tools_visible'] ) )[0];
	} finally {
		$restore();
	}
	$fx_file = __DIR__ . '/setup-checks-1.0.json';
	file_put_contents( $fx_file, wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo 'Wrote ' . $fx_file . ' (' . count( $fixture['rows'] ) . " rows)\n";
}
