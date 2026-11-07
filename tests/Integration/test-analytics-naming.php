<?php
/**
 * Analytics naming integration test: connector-correct tool names in the large-range recovery
 * error and in the wc-analytics/* descriptions.
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-analytics-naming.php
 *
 * Easy MCP AI stub (the `both` state):
 *   npx wp-env run cli wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-analytics-naming.php
 *
 * Stand-aside plus Easy MCP AI stub. The WooCommerce for Claude stub only defines its version
 * constant and registers no abilities, so this file registers the analytics abilities itself
 * from the same commerce-abilities classes WooCommerce for Claude ships (the bundled copy's
 * Loader is not run while standing aside):
 *   npx wp-env run cli wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-analytics-naming.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Abilities\AnalyticsNaming;
use Blaze\McpToolkit\Abilities\Store\StoreProfile;
use Blaze\McpToolkit\Bundled\AnalyticsGuard;
use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Connectors\Connectors;
use Blaze\McpToolkit\Connectors\RequestContext;

global $wp_filter;

$easy_stub   = defined( 'EASY_MCP_AI_VERSION' );
$stand_aside = Commerce::is_standing_aside();
$an_mode        = ( $stand_aside ? 'stand-aside+' : '' ) . ( $easy_stub ? 'easy-stub' : 'default' );
echo "Mode: {$an_mode}\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admins[0]->ID );

$aliases = array(
	'wc-analytics-confirm-large-range' => 'wc-analytics/confirm-large-range',
	'wc-analytics-totals'              => 'wc-analytics/totals',
	'wc-analytics-breakdown'           => 'wc-analytics/breakdown',
	'wc-analytics-series'              => 'wc-analytics/series',
	'wc-analytics-rows'                => 'wc-analytics/rows',
);
$classes = array(
	'wc-analytics/confirm-large-range' => \WooCommerce\CommerceAbilities\Abilities\ConfirmLargeRangeAbility::class,
	'wc-analytics/totals'              => \WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::class,
	'wc-analytics/breakdown'           => \WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility::class,
	'wc-analytics/series'              => \WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility::class,
	'wc-analytics/rows'                => \WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility::class,
);

// Whole-token presence, independent of the implementation's matcher.
$has_token = static function ( string $text, string $token ): bool {
	return 1 === preg_match( '#(?<![A-Za-z0-9_/-])' . preg_quote( $token, '#' ) . '(?![A-Za-z0-9_/-])#', $text );
};
$dual      = static function ( string $slug ): string {
	return 'Recommended connector: execute ability `' . $slug . '`; Easy MCP AI: call `' . Connectors::get( 'easy-mcp' )->tool_name( $slug ) . '`';
};

// Wiring.
blzmcp_it_equals( 30, has_filter( 'wp_register_ability_args', array( AnalyticsNaming::class, 'filter_args' ) ), 'naming filter on wp_register_ability_args at priority 30' );
blzmcp_it_equals( $aliases, AnalyticsNaming::ALIASES, 'ALIASES is the binding alias map' );
// The stub (and the real `both` matrix state) keep Enable Abilities active; the real easy-only
// matrix states do not.
$rec_active = Connectors::get( 'recommended' )->is_active();
blzmcp_it_equals( $easy_stub ? ( $rec_active ? 'both' : 'easy-mcp' ) : 'recommended', Connectors::state(), "{$an_mode}: connector state" );
if ( $stand_aside ) {
	blzmcp_it_equals( false, class_exists( StoreProfile::class, false ), 'stand-aside: our store tools are not loaded' );
}

// Rendering, for every state (easy-mcp alone is not reachable here with Enable Abilities active).
$sample = 'A wc-analytics-totals, B `wc-analytics/series`. C wc-analytics/totals-foo D xwc-analytics-rows E wc-analytics-rows-extra F wc-analytics-confirm-large-range.';
blzmcp_it_equals(
	'A wc-analytics/totals, B `wc-analytics/series`. C wc-analytics/totals-foo D xwc-analytics-rows E wc-analytics-rows-extra F wc-analytics/confirm-large-range.',
	AnalyticsNaming::render_description( $sample, 'recommended' ),
	'render recommended: aliases become canonical slugs, partial tokens untouched'
);
blzmcp_it_equals( AnalyticsNaming::render_description( $sample, 'recommended' ), AnalyticsNaming::render_description( $sample, 'none' ), 'render none: same as recommended' );
$easy_render = 'A ' . $dual( 'wc-analytics/totals' ) . ', B `' . $dual( 'wc-analytics/series' ) . '`. C wc-analytics/totals-foo D xwc-analytics-rows E wc-analytics-rows-extra F ' . $dual( 'wc-analytics/confirm-large-range' ) . '.';
foreach ( array( 'easy-mcp', 'both' ) as $state ) {
	blzmcp_it_equals( $easy_render, AnalyticsNaming::render_description( $sample, $state ), "render {$state}: labelled dual form for aliases and slash slugs, partial tokens untouched" );
}
blzmcp_it_assert( ! str_contains( $dual( 'wc-analytics/totals' ), 'ewpa' ) && ! str_contains( $dual( 'wc-analytics/totals' ), 'easy_mcp_ai' ), 'dual form names no vendor class or option' );

// Error passthrough is identity for anything that is not the large-range error.
$recommended = Connectors::get( 'recommended' );
$other_error = new WP_Error( 'no_pending_query', 'call wc-analytics-confirm-large-range again', array( 'status' => 400 ) );
blzmcp_it_assert( AnalyticsNaming::translate_result( $other_error, Connectors::get( 'easy-mcp' ) ) === $other_error, 'other error codes pass through untouched (same instance)' );
$plain = array( 'note' => 'wc-analytics-totals' );
blzmcp_it_equals( $plain, AnalyticsNaming::translate_result( $plain, Connectors::get( 'easy-mcp' ) ), 'non-error results pass through untouched' );
$no_alias = new WP_Error( 'extended_range_required', 'nothing to rename', array( 'status' => 400 ) );
blzmcp_it_assert( AnalyticsNaming::translate_result( $no_alias, Connectors::get( 'easy-mcp' ) ) === $no_alias, 'large-range error without an alias passes through (same instance)' );
$multi = new WP_Error( 'extended_range_required', 'call wc-analytics-confirm-large-range', array( 'status' => 400 ) );
$multi->add( 'extended_range_required', 'second wc-analytics-totals' );
$multi->add( 'other', 'wc-analytics-rows stays', array( 'status' => 418 ) );
$multi_out = AnalyticsNaming::translate_result( $multi, $recommended );
blzmcp_it_equals( array( 'call wc-analytics/confirm-large-range', 'second wc-analytics/totals' ), $multi_out->get_error_messages( 'extended_range_required' ), 'every large-range message renamed' );
blzmcp_it_equals( array( 'wc-analytics-rows stays' ), $multi_out->get_error_messages( 'other' ), 'other codes in the same error keep their text' );
blzmcp_it_equals( array( 'status' => 418 ), $multi_out->get_error_data( 'other' ), 'other codes keep their data' );

/*
 * Re-register the analytics abilities with test filters around ours: priority 1 captures the
 * unwrapped callback, priority 25 counts calls to the callback our wrapper (30) invokes and can
 * open a nested connector frame inside it.
 */
$GLOBALS['blzmcp_an_orig']  = array();
$GLOBALS['blzmcp_an_calls'] = array();
$GLOBALS['blzmcp_an_nest']  = null;
$capture                    = static function ( $args, $name ) {
	if ( is_array( $args ) && is_string( $name ) && str_starts_with( $name, 'wc-analytics/' ) ) {
		$GLOBALS['blzmcp_an_orig'][ $name ] = $args['execute_callback'];
	}
	return $args;
};
$counter                    = static function ( $args, $name ) {
	if ( ! is_array( $args ) || ! is_string( $name ) || ! str_starts_with( $name, 'wc-analytics/' ) ) {
		return $args;
	}
	$inner                    = $args['execute_callback'];
	$args['execute_callback'] = static function ( ...$call_args ) use ( $inner, $name ) {
		$GLOBALS['blzmcp_an_calls'][ $name ] = ( $GLOBALS['blzmcp_an_calls'][ $name ] ?? 0 ) + 1;
		$run                                 = static fn() => call_user_func_array( $inner, $call_args );
		return null === $GLOBALS['blzmcp_an_nest'] ? $run() : RequestContext::run( $GLOBALS['blzmcp_an_nest'], $run );
	};
	return $args;
};
add_filter( 'wp_register_ability_args', $capture, 1, 2 );
add_filter( 'wp_register_ability_args', $counter, 25, 2 );
blzmcp_it_cleanup(
	static function () use ( $capture, $counter ): void {
		remove_filter( 'wp_register_ability_args', $capture, 1 );
		remove_filter( 'wp_register_ability_args', $counter, 25 );
	}
);

// Fire an Abilities API init action with only the given callbacks attached.
$dispatch = static function ( string $hook, array $callbacks, $arg ) use ( &$wp_filter ): void {
	$saved = $wp_filter[ $hook ] ?? null;
	unset( $wp_filter[ $hook ] );
	foreach ( $callbacks as $cb ) {
		add_action( $hook, $cb[0], $cb[1] );
	}
	do_action( $hook, $arg );
	unset( $wp_filter[ $hook ] );
	if ( null !== $saved ) {
		$wp_filter[ $hook ] = $saved;
	}
};

if ( ! wp_has_ability_category( 'wc-analytics' ) ) {
	$dispatch( 'wp_abilities_api_categories_init', array( array( array( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class, 'register_category' ), 10 ) ), WP_Ability_Categories_Registry::get_instance() );
}
blzmcp_it_assert( wp_has_ability_category( 'wc-analytics' ), "{$an_mode}: wc-analytics category exists" );

$register_all = static function ( array $order, ?string $late = null ) use ( $classes, $dispatch ): void {
	foreach ( array_keys( $classes ) as $slug ) {
		if ( wp_has_ability( $slug ) ) {
			wp_unregister_ability( $slug );
		}
	}
	$callbacks = array();
	foreach ( $order as $slug ) {
		$callbacks[] = array( array( $classes[ $slug ], 'register' ), 10 );
	}
	if ( null !== $late ) {
		$callbacks[] = array(
			static function () use ( $late, $classes ): void {
				wp_unregister_ability( $late );
				$classes[ $late ]::register();
			},
			999,
		);
	}
	$dispatch( 'wp_abilities_api_init', $callbacks, WP_Abilities_Registry::get_instance() );
};

$scan_descriptions = static function ( string $label ) use ( $classes, $aliases, $has_token, $dual, $easy_stub ): void {
	$leftover = array();
	foreach ( array_keys( $classes ) as $slug ) {
		$ability = wp_get_ability( $slug );
		if ( null === $ability ) {
			$leftover[] = "{$slug} missing";
			continue;
		}
		foreach ( array_keys( $aliases ) as $alias ) {
			if ( $has_token( $ability->get_description(), $alias ) ) {
				$leftover[] = "{$slug}: {$alias}";
			}
		}
	}
	blzmcp_it_equals( array(), $leftover, "{$label}: no legacy alias left in any wc-analytics/* description" );

	$confirm = (string) wp_get_ability( 'wc-analytics/confirm-large-range' )?->get_description();
	$series  = (string) wp_get_ability( 'wc-analytics/series' )?->get_description();
	if ( $easy_stub ) {
		foreach ( array( 'wc-analytics/totals', 'wc-analytics/breakdown', 'wc-analytics/series' ) as $slug ) {
			blzmcp_it_assert( str_contains( $confirm, $dual( $slug ) ), "{$label}: confirm-large-range description has the dual form for {$slug}" );
		}
		blzmcp_it_assert( str_contains( $series, $dual( 'wc-analytics/confirm-large-range' ) ), "{$label}: series description has the dual form for confirm-large-range" );
	} else {
		blzmcp_it_assert( str_contains( $confirm, 'Call this after wc-analytics/totals, wc-analytics/breakdown, or wc-analytics/series returns' ), "{$label}: confirm-large-range description names the canonical slugs" );
		blzmcp_it_assert( str_contains( $series, 'call wc-analytics/confirm-large-range with the same date_start' ), "{$label}: series description names the canonical confirm slug" );
		blzmcp_it_assert( ! str_contains( $confirm . $series, 'Easy MCP AI' ), "{$label}: no Easy MCP wording with the recommended setup only" );
	}
};

$register_all( array_keys( $classes ) );
$scan_descriptions( "{$an_mode} natural order" );
$register_all( array_reverse( array_keys( $classes ) ), 'wc-analytics/series' );
$scan_descriptions( "{$an_mode} reversed order, series re-registered at 999" );
$register_all( array_reverse( array_keys( $classes ) ), 'wc-analytics/confirm-large-range' );
$scan_descriptions( "{$an_mode} reversed order, confirm-large-range re-registered at 999" );
blzmcp_it_assert( isset( $GLOBALS['blzmcp_an_orig']['wc-analytics/totals'] ), "{$an_mode}: unwrapped totals callback captured" );

// The gate, triggered directly with no workflow.
if ( function_exists( 'blzmcp_it_sync_analytics' ) ) {
	blzmcp_it_sync_analytics();
}
$input  = array(
	'subject'    => 'revenue',
	'period'     => 'custom',
	'date_start' => '2024-01-01',
	'date_end'   => gmdate( 'Y-m-d' ),
	'compare'    => false,
);
$totals_ability = wp_get_ability( 'wc-analytics/totals' );

$gate_call = static function ( ?string $frame = null, ?string $nest = null ) use ( $totals_ability, $input ) {
	$GLOBALS['blzmcp_an_calls'] = array();
	$GLOBALS['blzmcp_an_nest']  = $nest;
	try {
		return null === $frame ? $totals_ability->execute( $input ) : RequestContext::run( $frame, static fn() => $totals_ability->execute( $input ) );
	} finally {
		$GLOBALS['blzmcp_an_nest'] = null;
	}
};

$expected_confirm = $easy_stub ? 'wp_ability_wc_analytics_confirm_large_range' : 'wc-analytics/confirm-large-range';
$gate_error            = $gate_call();
$message          = is_wp_error( $gate_error ) ? $gate_error->get_error_message() : '';
blzmcp_it_equals( 'extended_range_required', is_wp_error( $gate_error ) ? $gate_error->get_error_code() : null, "{$an_mode}: large range returns extended_range_required" );
blzmcp_it_equals( 1, $GLOBALS['blzmcp_an_calls']['wc-analytics/totals'] ?? 0, "{$an_mode}: original callback ran exactly once" );
blzmcp_it_assert( str_contains( $message, $expected_confirm ), "{$an_mode}: message names {$expected_confirm}" );
blzmcp_it_assert( ! str_contains( $message, 'wc-analytics-confirm-large-range' ), "{$an_mode}: message has no legacy confirm alias" );
// Easy MCP AI passes at most 200 bytes of an error message, so its recovery text is the compact
// form; the recommended connector keeps the full message.
$compact = $easy_stub;
if ( $compact ) {
	blzmcp_it_assert( strlen( $message ) <= 190, "{$an_mode}: compact message fits Easy MCP AI's limit with margin (" . strlen( $message ) . ' bytes)' );
	blzmcp_it_assert( str_contains( $message, 'merchant' ) && str_starts_with( $message, 'STOP' ), "{$an_mode}: compact message says STOP and ask the merchant" );
	blzmcp_it_assert( str_contains( $message, 'date_start=' . $input['date_start'] ) && str_contains( $message, 'date_end=' . $input['date_end'] ) && str_contains( $message, 'type=totals:revenue' ), "{$an_mode}: compact message carries the three exact values" );
	blzmcp_it_assert( str_contains( $message, 're-run' ), "{$an_mode}: compact message says re-run" );
	// Every gate type fits: the longest are totals:customer_value and breakdown:attribution.
	$easy_conn = Connectors::get( 'easy-mcp' );
	foreach ( array( 'totals:customer_value', 'breakdown:attribution', 'series:customers', 'totals:tax' ) as $t ) {
		$long = "x date_start='2024-01-01', date_end='2026-11-07', type='{$t}' " . str_repeat( 'y', 900 );
		$c    = AnalyticsNaming::compact_message( $long, array( 'cost_estimate' => array( 'type' => $t, 'range_days' => 1042 ) ), $easy_conn, 190 );
		blzmcp_it_assert( strlen( $c ) <= 190 && str_contains( $c, 'type=' . $t ) && str_contains( $c, 'wp_ability_wc_analytics_confirm_large_range' ) && str_contains( $c, 'merchant' ) && str_contains( $c, 're-run' ), "compact form fits for {$t} (" . strlen( $c ) . " bytes): {$c}" );
	}
} else {
	blzmcp_it_assert( str_contains( $message, 'ANTI-SPLITTING RULE' ) && str_contains( $message, "type='totals:revenue'" ), "{$an_mode}: the rest of the message is kept" );
}

$unwrapped = call_user_func( $GLOBALS['blzmcp_an_orig']['wc-analytics/totals'], $input );
blzmcp_it_assert( is_wp_error( $unwrapped ) && 'extended_range_required' === $unwrapped->get_error_code(), "{$an_mode}: unwrapped call also returns the gate error" );
if ( is_wp_error( $unwrapped ) && is_wp_error( $gate_error ) ) {
	blzmcp_it_equals( $unwrapped->get_error_data(), $gate_error->get_error_data(), "{$an_mode}: error data (status, cost estimate) equals the unwrapped call's" );
	blzmcp_it_equals( 400, $gate_error->get_error_data()['status'] ?? null, "{$an_mode}: status 400 kept" );
	if ( ! $compact ) {
		blzmcp_it_equals( str_replace( 'wc-analytics-confirm-large-range', $expected_confirm, $unwrapped->get_error_message() ), $message, "{$an_mode}: only the alias changed in the message" );
	}
}

if ( $easy_stub && ! $rec_active ) {
	// Easy MCP AI alone: a recommended frame is inactive, so the Easy MCP name is used throughout.
	$rec_frame = $gate_call( 'recommended' );
	blzmcp_it_assert( is_wp_error( $rec_frame ) && str_contains( $rec_frame->get_error_message(), 'wp_ability_wc_analytics_confirm_large_range' ), "{$an_mode}: an inactive recommended frame falls back to the Easy MCP tool name" );
	$easy_frame = $gate_call( 'easy-mcp', 'recommended' );
	blzmcp_it_assert( is_wp_error( $easy_frame ) && str_contains( $easy_frame->get_error_message(), 'wp_ability_wc_analytics_confirm_large_range' ), "{$an_mode}: inside an easy-mcp frame the message names the Easy MCP tool" );
	blzmcp_it_equals( 1, $GLOBALS['blzmcp_an_calls']['wc-analytics/totals'] ?? 0, "{$an_mode}: framed call ran the original exactly once" );
} elseif ( $easy_stub ) {
	$rec_frame = $gate_call( 'recommended' );
	blzmcp_it_assert( is_wp_error( $rec_frame ) && str_contains( $rec_frame->get_error_message(), 'wc-analytics/confirm-large-range' ) && ! str_contains( $rec_frame->get_error_message(), 'wp_ability_' ), "{$an_mode}: inside a recommended frame the message names the canonical slug" );
	if ( is_wp_error( $rec_frame ) && is_wp_error( $unwrapped ) ) {
		blzmcp_it_equals( str_replace( 'wc-analytics-confirm-large-range', 'wc-analytics/confirm-large-range', $unwrapped->get_error_message() ), $rec_frame->get_error_message(), "{$an_mode}: inside a recommended frame the full message is unchanged apart from the alias" );
	}
	$nested = $gate_call( 'recommended', 'easy-mcp' );
	blzmcp_it_assert( is_wp_error( $nested ) && str_contains( $nested->get_error_message(), 'wc-analytics/confirm-large-range' ) && ! str_contains( $nested->get_error_message(), 'wp_ability_' ), "{$an_mode}: a nested easy-mcp frame inside the call does not leak into the recovery text" );
	$easy_frame = $gate_call( 'easy-mcp', 'recommended' );
	blzmcp_it_assert( is_wp_error( $easy_frame ) && str_contains( $easy_frame->get_error_message(), 'wp_ability_wc_analytics_confirm_large_range' ), "{$an_mode}: inside an easy-mcp frame the message names the Easy MCP tool" );
	blzmcp_it_equals( 1, $GLOBALS['blzmcp_an_calls']['wc-analytics/totals'] ?? 0, "{$an_mode}: framed call ran the original exactly once" );
}

// Easy MCP AI's error-message cut (2.3.1 = 2.4.0 Server::sanitize_error_message, minus branding):
// what a client receives from a tool error.
$easy_sanitise = static function ( string $m ): string {
	$m = (string) preg_replace( '/\s*Stack trace:.*$/s', '', $m );
	$m = (string) preg_replace( '/\s+in\s+\S+\.php(?:\(\d+\)|:\d+| on line \d+)/', '', $m );
	$m = (string) preg_replace( '#(?<![\w.\-/\\\\])(?:\\\\?/|[A-Z]:\\\\)(?:(?:[^\s\'"<>/\\\\]+(?: +[^\s\'"<>/\\\\]+)*)?[/\\\\])*[^\s\'"<>/\\\\]*\.(?:php|inc|tpl|phtml)\b#', '[file]', $m );
	$m = (string) preg_replace( '/\[?::1\]?|\blocalhost\b/i', '[internal]', $m );
	$m = trim( $m );
	return strlen( $m ) > 200 ? substr( $m, 0, 200 ) . '…[truncated]' : $m;
};

// Analytics-unavailable errors (AnalyticsGuard), every reason: a compact form under Easy MCP AI,
// the full message on the recommended connector.
$easy_c = Connectors::get( 'easy-mcp' );
$rec_c  = Connectors::get( 'recommended' );
foreach ( array( 'analytics_disabled', 'analytics_unreadable', 'import_in_progress', 'import_not_run', 'orders_not_imported' ) as $reason ) {
	$full = new WP_Error( AnalyticsGuard::ERROR_CODE, AnalyticsGuard::MESSAGE_TOKEN . ' ' . AnalyticsGuard::message( $reason ), array( 'status' => 503, 'reason' => $reason ) );
	$short = AnalyticsNaming::translate_result( $full, $easy_c );
	$msg   = is_wp_error( $short ) ? $short->get_error_message() : '';
	$sent  = $easy_sanitise( $msg );
	blzmcp_it_assert( strlen( $sent ) <= 190 && $sent === $msg, "unavailable {$reason}: Easy form is at most 190 bytes and survives Easy's sanitising (" . strlen( $sent ) . ' bytes)' );
	blzmcp_it_assert( str_starts_with( $sent, AnalyticsGuard::MESSAGE_TOKEN . ' ' ), "unavailable {$reason}: Easy form starts with the token" );
	blzmcp_it_assert( 1 === preg_match( '/Fix:|Retry/', $sent ), "unavailable {$reason}: Easy form keeps the action" );
	blzmcp_it_equals( array( AnalyticsGuard::ERROR_CODE, $full->get_error_data() ), is_wp_error( $short ) ? array( $short->get_error_code(), $short->get_error_data() ) : null, "unavailable {$reason}: code and data (status, reason) unchanged" );
	blzmcp_it_assert( AnalyticsNaming::translate_result( $full, $rec_c ) === $full, "unavailable {$reason}: recommended connector gets the full message unchanged" );
}
// Large-range compact form never exceeds its budget; an over-long type drops the description hint first.
$long_type = 'totals:' . str_repeat( 'x', 30 );
$cap       = AnalyticsNaming::compact_message( "x date_start='2024-01-01', date_end='2026-11-07', type='{$long_type}' " . str_repeat( 'y', 900 ), array( 'cost_estimate' => array( 'type' => $long_type ) ), $easy_c, 190 );
blzmcp_it_assert( strlen( $cap ) <= 190 && str_contains( $cap, 'type=' . $long_type ) && ! str_contains( $cap, 'description=' ) && str_contains( $cap, 'merchant' ), 'compact large-range: long type keeps the exact args and drops the description hint (' . strlen( $cap ) . ' bytes)' );
$huge = 'totals:' . str_repeat( 'z', 300 );
$cap2 = AnalyticsNaming::compact_message( "x date_start='2024-01-01', date_end='2026-11-07', type='{$huge}'", array( 'cost_estimate' => array( 'type' => $huge ) ), $easy_c, 190 );
blzmcp_it_assert( strlen( $cap2 ) <= 190 && str_starts_with( $cap2, 'STOP' ), 'compact large-range: never exceeds the budget, even for an absurd type (' . strlen( $cap2 ) . ' bytes)' );

// Confirm, rerun: data, not an error. The gate still works.
$gate_type      = is_wp_error( $gate_error ) ? (string) ( $gate_error->get_error_data()['cost_estimate']['type'] ?? '' ) : '';
$confirmed = wp_get_ability( 'wc-analytics/confirm-large-range' )->execute(
	array(
		'date_start'  => $input['date_start'],
		'date_end'    => $input['date_end'],
		'type'        => $gate_type,
		'description' => 'Revenue since 2024 (integration test)',
	)
);
blzmcp_it_equals( true, is_array( $confirmed ) ? ( $confirmed['confirmed'] ?? null ) : $confirmed, "{$an_mode}: confirm-large-range approves the pending query" );
$rerun = $gate_call();
blzmcp_it_assert( is_array( $rerun ) && ! is_wp_error( $rerun ), "{$an_mode}: rerun after confirming returns data" . ( is_wp_error( $rerun ) ? ' (got ' . $rerun->get_error_code() . ')' : '' ) );
$again = $gate_call();
blzmcp_it_assert( is_wp_error( $again ) && 'extended_range_required' === $again->get_error_code(), "{$an_mode}: approval is one-use, the gate fires again" );

blzmcp_it_finish();
