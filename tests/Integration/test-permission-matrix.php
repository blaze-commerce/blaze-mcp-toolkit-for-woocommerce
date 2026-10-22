<?php
/**
 * Permission matrix: every blaze-mcp-toolkit/* and wc-analytics/* ability, tried as each role, directly
 * and through the MCP Adapter gateway (mcp-adapter/execute-ability).
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-permission-matrix.php
 *
 * The ability list comes from wp_get_abilities(); an ability with no expectation below fails the run,
 * so a new tool cannot ship untested.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

// Analytics must be complete for the "allowed" rows, whatever earlier files left behind.
blzmcp_it_sync_analytics();

use Blaze\McpToolkit\Settings;

const BLZMCP_PM_ADMIN_ONLY = 'admin_only';
const BLZMCP_PM_DIAG       = 'diagnostics';
const BLZMCP_PM_WOO        = 'manage_woocommerce';

$expectations = array(
	'blaze-mcp-toolkit/health-summary'      => BLZMCP_PM_DIAG,
	'blaze-mcp-toolkit/update-history'      => BLZMCP_PM_DIAG,
	'blaze-mcp-toolkit/access-check'        => BLZMCP_PM_ADMIN_ONLY,
	'blaze-mcp-toolkit/store-profile'       => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/search-products'     => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/product-details'     => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/readiness-score'     => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/recommendations'     => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/suggest-improvements' => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/weekly-store-review' => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/revenue-drop-triage' => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/failed-order-triage' => BLZMCP_PM_WOO,
	'blaze-mcp-toolkit/refund-triage'       => BLZMCP_PM_WOO,
	// Anything under wc-analytics/ is manage_woocommerce.
);

// Abilities allowed to answer an authorised call with a data error under the minimal input: confirm-large-range
// needs a pending query minted by a previous gated call.
$data_error_ok = array( 'wc-analytics/confirm-large-range' );

$allowed_for = static function ( string $name, string $role, bool $sm_diag ) use ( $expectations ): bool {
	$rule = $expectations[ $name ] ?? ( str_starts_with( $name, 'wc-analytics/' ) ? BLZMCP_PM_WOO : null );
	switch ( $rule ) {
		case BLZMCP_PM_ADMIN_ONLY:
			return 'administrator' === $role;
		case BLZMCP_PM_DIAG:
			return 'administrator' === $role || ( 'shop_manager' === $role && $sm_diag );
		case BLZMCP_PM_WOO:
			return in_array( $role, array( 'administrator', 'shop_manager' ), true );
	}
	return false;
};

$minimal_input = static function ( array $schema, int $product_id ): array {
	$overrides = array(
		'subject'      => 'revenue',
		'interval'     => 'day',
		'product_id'   => $product_id,
		'date_start'   => '2025-01-01',
		'date_end'     => '2025-01-31',
		'type'         => 'orders',
		'description'  => 'permission matrix',
	);
	$input     = array();
	foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
		$prop = $schema['properties'][ $key ] ?? array();
		if ( array_key_exists( $key, $overrides ) && ( empty( $prop['enum'] ) || in_array( $overrides[ $key ], $prop['enum'], true ) ) ) {
			$input[ $key ] = $overrides[ $key ];
		} elseif ( ! empty( $prop['enum'] ) ) {
			$input[ $key ] = $prop['enum'][0];
		} else {
			$type          = (array) ( $prop['type'] ?? 'string' );
			$input[ $key ] = in_array( 'integer', $type, true ) || in_array( 'number', $type, true ) ? 1 : 'x';
		}
	}
	return $input;
};

$is_permission_error = static function ( $result ): bool {
	return is_wp_error( $result ) && str_contains( $result->get_error_code(), 'permission' );
};

$original_settings = get_option( 'blzmcp_settings', null );
$user_ids          = array();
$product_id        = 0;
$matrix            = array();

try {
	$suffix = wp_generate_password( 6, false );
	$roles  = array( 'customer', 'subscriber', 'shop_manager', 'administrator' );
	foreach ( $roles as $role ) {
		$user_ids[ $role ] = wp_insert_user(
			array(
				'user_login' => "blzmcp_pm_{$role}_{$suffix}",
				'user_pass'  => wp_generate_password(),
				'user_email' => "blzmcp-pm-{$role}-{$suffix}@example.invalid",
				'role'       => $role,
			)
		);
		blzmcp_it_assert( is_int( $user_ids[ $role ] ), "created {$role} user" );
	}
	$user_ids['anonymous'] = 0;
	$roles[]               = 'anonymous';

	$product = new WC_Product_Simple();
	$product->set_name( 'Permission matrix product' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product_id = $product->save();

	$abilities = array_filter(
		wp_get_abilities(),
		static fn( $a ) => str_starts_with( $a->get_name(), 'blaze-mcp-toolkit/' ) || str_starts_with( $a->get_name(), 'wc-analytics/' )
	);
	blzmcp_it_assert( count( $abilities ) >= 18, 'found ' . count( $abilities ) . ' abilities to test' );

	$gateway = wp_get_ability( 'mcp-adapter/execute-ability' );
	blzmcp_it_assert( null !== $gateway, 'MCP Adapter execute-ability is registered' );

	// Reverse check: every expected ability is registered (this env has no stand-aside).
	foreach ( array_keys( $expectations ) as $expected_name ) {
		blzmcp_it_assert( isset( $abilities[ $expected_name ] ), "expected ability {$expected_name} is registered" );
	}

	foreach ( $abilities as $name => $ability ) {
		$covered = isset( $expectations[ $name ] ) || str_starts_with( $name, 'wc-analytics/' );
		blzmcp_it_assert( $covered, "{$name} has a permission expectation" );
	}

	foreach ( array( false, true ) as $sm_diag ) {
		$settings                              = Settings::get();
		$settings['shop_manager_diagnostics'] = $sm_diag;
		update_option( 'blzmcp_settings', $settings, false );
		$tag = $sm_diag ? 'sm_diag_on' : 'sm_diag_off';

		foreach ( $abilities as $name => $ability ) {
			$input = $minimal_input( $ability->get_input_schema(), $product_id );
			foreach ( $roles as $role ) {
				wp_set_current_user( $user_ids[ $role ] );
				$want   = $allowed_for( $name, $role, $sm_diag );
				$result = $ability->execute( $input );
				$label  = "[{$tag}] {$role} -> {$name}";
				if ( $want ) {
					$direct_ok = ! is_wp_error( $result ) || ( in_array( $name, $data_error_ok, true ) && ! $is_permission_error( $result ) );
					blzmcp_it_assert( $direct_ok, "{$label} allowed (direct)" . ( is_wp_error( $result ) ? ' got ' . $result->get_error_code() : '' ) );
				} else {
					blzmcp_it_assert( $is_permission_error( $result ), "{$label} denied (direct)" . ( is_wp_error( $result ) ? ' code ' . $result->get_error_code() : ' got data' ) );
				}
				$denied_direct = $is_permission_error( $result );

				if ( null !== $gateway ) {
					$out = $gateway->execute(
						array(
							'ability_name' => $name,
							'parameters'   => $input,
						)
					);
					$gw_denied = is_wp_error( $out )
						? $is_permission_error( $out )
						: ( is_array( $out ) && false === ( $out['success'] ?? null ) && ! isset( $out['data'] ) );
					$gw_ok     = is_array( $out ) && true === ( $out['success'] ?? false );
					$gw_note   = is_wp_error( $out ) ? $out->get_error_code() : ( is_array( $out ) ? (string) ( $out['error'] ?? '' ) : 'unexpected' );
					if ( $want ) {
						blzmcp_it_assert( $gw_ok || ( in_array( $name, $data_error_ok, true ) && ! str_contains( strtolower( $gw_note ), 'permission' ) ), "{$label} allowed (gateway) {$gw_note}" );
					} else {
						blzmcp_it_assert( $gw_denied, "{$label} denied (gateway) {$gw_note}" );
					}
				}
				$matrix[ $tag ][ $name ][ $role ] = $want ? ( $denied_direct ? 'ERR' : 'allow' ) : ( $denied_direct ? 'deny' : 'LEAK' );
			}
		}
	}
} finally {
	wp_set_current_user( 0 );
	if ( null === $original_settings ) {
		delete_option( 'blzmcp_settings' );
	} else {
		update_option( 'blzmcp_settings', $original_settings, false );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $user_ids as $id ) {
		if ( $id ) {
			wp_delete_user( $id );
		}
	}
	if ( $product_id ) {
		wp_delete_post( $product_id, true );
	}
}

foreach ( $matrix as $tag => $rows ) {
	echo "\nMatrix ({$tag})\n";
	printf( "%-42s %-9s %-9s %-13s %-14s %-9s\n", 'ability', 'anon', 'customer', 'subscriber', 'shop_manager', 'admin' );
	foreach ( $rows as $name => $cols ) {
		printf( "%-42s %-9s %-9s %-13s %-14s %-9s\n", $name, $cols['anonymous'], $cols['customer'], $cols['subscriber'], $cols['shop_manager'], $cols['administrator'] );
	}
}

blzmcp_it_finish();
