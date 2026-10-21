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

	// 2. No enqueued assets from the plugin (handles or URLs).
	global $wp_scripts, $wp_styles;
	foreach ( array( $wp_scripts, $wp_styles ) as $deps ) {
		foreach ( $deps ? $deps->registered : array() as $handle => $dep ) {
			blzmcp_it_assert( ! str_contains( (string) $handle . (string) $dep->src, 'blaze-mcp-toolkit' ) && 0 !== strpos( (string) $handle, 'blzmcp' ), "asset {$handle} is not ours" );
		}
	}

	// 3. No callback from our namespace on any front-end output/render hook.
	$ours = static function ( $cb ): bool {
		try {
			if ( is_string( $cb ) ) {
				return str_starts_with( ltrim( $cb, '\\' ), 'Blaze\\McpToolkit\\' ) || str_starts_with( $cb, 'blzmcp_' );
			}
			if ( is_array( $cb ) ) {
				$cls = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
				return str_starts_with( ltrim( $cls, '\\' ), 'Blaze\\McpToolkit\\' );
			}
			if ( $cb instanceof Closure ) {
				$ref   = new ReflectionFunction( $cb );
				$scope = $ref->getClosureScopeClass();
				$file  = (string) $ref->getFileName();
				return ( $scope && str_starts_with( $scope->getName(), 'Blaze\\McpToolkit\\' ) ) || str_contains( $file, 'blaze-mcp-toolkit-for-woocommerce/src/' ) || str_contains( $file, 'blaze-mcp-toolkit-for-woocommerce/blaze-mcp-toolkit-for-woocommerce.php' );
			}
			if ( is_object( $cb ) ) {
				return str_starts_with( get_class( $cb ), 'Blaze\\McpToolkit\\' );
			}
		} catch ( Throwable $e ) {
			return false;
		}
		return false;
	};

	$front_hooks = array(
		'wp_enqueue_scripts',
		'wp_print_scripts',
		'wp_print_styles',
		'wp_print_footer_scripts',
		'wp_head',
		'wp_footer',
		'wp_body_open',
		'wp',
		'template_redirect',
		'template_include',
		'send_headers',
		'parse_request',
		'pre_get_posts',
		'the_content',
		'body_class',
		'wp_resource_hints',
		'script_loader_tag',
		'style_loader_tag',
		'login_enqueue_scripts',
		'login_head',
		'rest_api_init',
		'woocommerce_before_main_content',
		'woocommerce_after_main_content',
		'woocommerce_before_shop_loop',
		'woocommerce_after_shop_loop',
		'woocommerce_before_single_product',
		'woocommerce_after_single_product',
		'woocommerce_single_product_summary',
		'woocommerce_before_cart',
		'woocommerce_after_cart',
		'woocommerce_before_calculate_totals',
	);
	global $wp_filter;
	foreach ( $front_hooks as $hook ) {
		$found = array();
		if ( isset( $wp_filter[ $hook ] ) ) {
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $entry ) {
					if ( $ours( $entry['function'] ) ) {
						$found[] = "{$priority}";
					}
				}
			}
		}
		blzmcp_it_assert( empty( $found ), "no Blaze\\McpToolkit callback on {$hook}" . ( $found ? ' (priorities ' . implode( ',', $found ) . ')' : '' ) );
	}

	// Every hook our code does attach to, for the record.
	$attached = array();
	foreach ( $wp_filter as $hook => $obj ) {
		foreach ( $obj->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( $ours( $entry['function'] ) ) {
					$attached[ $hook ] = true;
				}
			}
		}
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
