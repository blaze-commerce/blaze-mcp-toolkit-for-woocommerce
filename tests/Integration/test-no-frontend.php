<?php
/**
 * Front-end footprint: the plugin leaves no trace on the storefront.
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-no-frontend.php
 *
 * Pages are fetched with wp_remote_get(). The cli container cannot reach localhost:8888 (loopback), so
 * those fetches SKIP here and the HTTP half runs from the host: bash tests/Integration/frontend-curl.sh
 * (global-constraints R6). The hook, asset, rewrite and REST-route assertions need no HTTP.
 *
 * If no page could be fetched, the run FAILS with "HTTP CHECK NOT RUN" unless BLZMCP_SKIP_HTTP=1 is set
 * (scripts/run-integration.sh sets it and then runs frontend-curl.sh itself).
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

$product_id = 0;
$created    = false;

try {
	// Sample content: a published product; shop and cart pages from WooCommerce.
	$existing = wc_get_products(
		array(
			'limit'  => 1,
			'status' => 'publish',
			'return' => 'ids',
		)
	);
	if ( empty( $existing ) ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Front-end footprint product' );
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product_id = $product->save();
		$created    = true;
	} else {
		$product_id = (int) $existing[0];
	}
	$urls = array(
		'shop'    => wc_get_page_permalink( 'shop' ),
		'product' => get_permalink( $product_id ),
		'cart'    => wc_get_cart_url(),
		'home'    => home_url( '/' ),
	);
	foreach ( $urls as $label => $url ) {
		blzmcp_it_assert( is_string( $url ) && '' !== $url, "{$label} URL resolves ({$url})" );
	}

	// 1. No response mentions the plugin.
	$fetched = 0;
	foreach ( $urls as $label => $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			echo "SKIP  {$label} fetch: " . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ) ) . " (use the host curl step in the header)\n";
			continue;
		}
		++$fetched;
		$body = wp_remote_retrieve_body( $res ) . wp_json_encode( wp_remote_retrieve_headers( $res )->getAll() );
		blzmcp_it_assert( strlen( $body ) > 500, "{$label} returned a page" );
		blzmcp_it_assert( false === stripos( $body, 'blzmcp' ), "{$label} response has no 'blzmcp'" );
		blzmcp_it_assert( false === stripos( $body, 'blaze-mcp-toolkit' ), "{$label} response has no 'blaze-mcp-toolkit'" );
	}
	echo "fetched {$fetched} of " . count( $urls ) . " pages over HTTP\n";
	if ( 0 === $fetched ) {
		if ( '1' === getenv( 'BLZMCP_SKIP_HTTP' ) ) {
			echo "NOTE  HTTP half skipped (BLZMCP_SKIP_HTTP=1); it must be run by tests/Integration/frontend-curl.sh\n";
		} else {
			blzmcp_it_assert( false, '!!! HTTP CHECK NOT RUN !!! run tests/Integration/frontend-curl.sh (or set BLZMCP_SKIP_HTTP=1 if you run it separately)' );
		}
	}

	// 2. No enqueued assets from the plugin (handles or URLs).
	global $wp_scripts, $wp_styles;
	foreach ( array( $wp_scripts, $wp_styles ) as $deps ) {
		foreach ( $deps ? $deps->registered : array() as $handle => $dep ) {
			blzmcp_it_assert( ! str_contains( (string) $handle . (string) $dep->src, 'blaze-mcp-toolkit' ) && 0 !== strpos( (string) $handle, 'blzmcp' ), "asset {$handle} is not ours" );
		}
	}

	// 3. No callback from our namespace on any front-end output/render hook.
	$prefixes = array( 'Blaze\\McpToolkit\\', 'WooCommerce\\CommerceAbilities\\' );
	$is_ours  = static function ( string $class_name ) use ( $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( ltrim( $class_name, '\\' ), $prefix ) ) {
				return true;
			}
		}
		return false;
	};
	$ours     = static function ( $cb ) use ( $is_ours ): bool {
		try {
			if ( is_string( $cb ) ) {
				return $is_ours( $cb ) || str_starts_with( $cb, 'blzmcp_' );
			}
			if ( is_array( $cb ) ) {
				return $is_ours( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] );
			}
			if ( $cb instanceof Closure ) {
				$ref   = new ReflectionFunction( $cb );
				$scope = $ref->getClosureScopeClass();
				$file  = str_replace( '\\', '/', (string) $ref->getFileName() );
				return ( $scope && $is_ours( $scope->getName() ) )
					|| str_contains( $file, '/blaze-mcp-toolkit-for-woocommerce/src/' )
					|| str_contains( $file, '/blaze-mcp-toolkit-for-woocommerce/blaze-mcp-toolkit-for-woocommerce.php' )
					|| str_contains( $file, '/vendor/woocommerce/commerce-abilities/' )
					|| str_contains( $file, '/vendor-src/commerce-abilities/' );
			}
			if ( is_object( $cb ) ) {
				return $is_ours( get_class( $cb ) );
			}
		} catch ( Throwable $e ) {
			return false;
		}
		return false;
	};

	// Positive controls: the matcher must recognise callbacks we know are ours, or the scan proves nothing.
	global $wp_filter;
	$has_ours = static function ( string $hook ) use ( $wp_filter, $ours ): bool {
		foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( $ours( $entry['function'] ) ) {
					return true;
				}
			}
		}
		return false;
	};
	foreach ( array( 'wp_abilities_api_init', 'wp_abilities_api_categories_init', 'blzmcp_daily_cleanup', 'action_scheduler_init', 'before_woocommerce_init' ) as $known ) {
		blzmcp_it_assert( $has_ours( $known ), "matcher control: {$known} is detected as ours" );
	}
	blzmcp_it_assert( $ours( 'Blaze\\McpToolkit\\Plugin::boot' ), 'matcher control: string callable' );
	blzmcp_it_assert( $ours( array( 'Blaze\\McpToolkit\\Plugin', 'boot' ) ), 'matcher control: array callable' );
	blzmcp_it_assert( $ours( array( 'WooCommerce\\CommerceAbilities\\Plugin', 'x' ) ), 'matcher control: bundled namespace' );
	blzmcp_it_assert( ! $ours( 'wp_head' ) && ! $ours( static function () {} ), 'matcher control: core function and foreign closure are not ours' );
	$scoped = Closure::bind( static function () {}, null, Blaze\McpToolkit\Plugin::class );
	blzmcp_it_assert( $ours( $scoped ), 'matcher control: closure scoped to our class' );

	// Scan every hook. Only these admin / API / cron / lifecycle hooks may carry our callbacks.
	$allowed_hooks = array(
		'switch_theme',
		'plugins_loaded',
		'wp_abilities_api_categories_init',
		'wp_abilities_api_init',
		'wp_register_ability_args',
		'upgrader_process_complete',
		'upgrader_pre_install',
		'_core_updated_successfully',
		'deactivated_plugin',
		'activated_plugin',
		'before_woocommerce_init',
		'action_scheduler_init',
		'blzmcp_daily_cleanup',
		'update_option_woocommerce_analytics_enabled',
		'wc-admin_import_batch_init_orders',
		'wc-admin_import_batch_orders',
	);
	$attached      = array();
	foreach ( $wp_filter as $hook => $obj ) {
		foreach ( $obj->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( $ours( $entry['function'] ) ) {
					$attached[ $hook ] = true;
				}
			}
		}
	}
	foreach ( array_keys( $attached ) as $hook ) {
		$ok = in_array( $hook, $allowed_hooks, true ) || str_starts_with( $hook, 'activate_blaze-mcp-toolkit' ) || str_starts_with( $hook, 'deactivate_blaze-mcp-toolkit' );
		blzmcp_it_assert( $ok, "our callback on {$hook} is an allowed non-front-end hook" );
	}
	// And never on the render path, whatever the allowlist says.
	foreach ( array( 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'wp', 'template_redirect', 'template_include', 'wp_body_open', 'the_content', 'send_headers', 'init' ) as $hook ) {
		blzmcp_it_assert( ! isset( $attached[ $hook ] ), "no callback of ours on {$hook}" );
	}
	echo 'Hooks with our callbacks (cli, non-admin): ' . implode( ', ', array_keys( $attached ) ) . "\n";

	// 4. No rewrite rules, query vars or REST routes of our own.
	$rules = get_option( 'rewrite_rules' );
	blzmcp_it_assert( false === stripos( wp_json_encode( $rules ), 'blzmcp' ), 'no rewrite rules mention blzmcp' );
	$routes = rest_get_server()->get_routes();
	$mine   = array_filter( array_keys( $routes ), static fn( $r ) => str_contains( $r, 'blzmcp' ) || str_contains( $r, 'blaze-mcp-toolkit' ) );
	blzmcp_it_assert( empty( $mine ), 'no REST routes of our own' . ( $mine ? ': ' . implode( ',', $mine ) : '' ) );
} finally {
	if ( $created && $product_id ) {
		wp_delete_post( $product_id, true );
	}
}

blzmcp_it_finish();
