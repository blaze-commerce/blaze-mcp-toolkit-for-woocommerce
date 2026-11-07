<?php
/**
 * Workflow tools integration test.
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-workflows.php
 *
 * Set BLZMCP_SAMPLE=1 to print one admin sample per workflow.
 *
 * Stand-aside run (WooCommerce for Claude stub; only the skipped-step and store-name rules are checked,
 * since with the stub no wc-analytics/* abilities exist):
 *   npx wp-env run cli wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-wcfc.php eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-workflows.php
 *
 * Easy MCP AI stub run (the `both` state; this file loads the stub Tool_State itself and seeds
 * Easy MCP's ticked-abilities option, restoring it afterwards):
 *   npx wp-env run cli wp --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-workflows.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Connectors\Connectors;
use Blaze\McpToolkit\Connectors\EasyMcpNaming;
use Blaze\McpToolkit\Connectors\RequestContext;
use Blaze\McpToolkit\Settings;
use Blaze\McpToolkit\Workflows\WeeklyStoreReview;

global $wpdb;

$workflows = array(
	'blaze-mcp-toolkit/weekly-store-review' => 'last_7_days',
	'blaze-mcp-toolkit/revenue-drop-triage' => 'last_30_days',
	'blaze-mcp-toolkit/failed-order-triage' => 'last_30_days',
	'blaze-mcp-toolkit/refund-triage'       => 'last_30_days',
);
$periods   = array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' );
$health    = 'blaze-mcp-toolkit/health-summary';
$history   = 'blaze-mcp-toolkit/update-history';

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admins[0]->ID );

$tools_of = static function ( $result ): array {
	return is_array( $result ) && isset( $result['steps'] ) ? array_column( $result['steps'], 'tool' ) : array();
};

$step_for = static function ( $result, string $tool, ?string $subject = null ) {
	foreach ( (array) ( $result['steps'] ?? array() ) as $step ) {
		$args = (array) $step['arguments'];
		if ( $step['tool'] === $tool && ( null === $subject || ( $args['subject'] ?? null ) === $subject ) ) {
			return $step;
		}
	}
	return null;
};

$profile = 'blaze-mcp-toolkit/store-profile';

// Connector naming (1.1): the run sentence 1.0 shipped, and helpers shared by the default and
// Easy MCP stub runs.
$easy_stub     = defined( 'EASY_MCP_AI_VERSION' );
$run_line_10   = 'This tool does not read store data itself. It returns the steps to run. Each step is run with the connector\'s execute-ability tool, using the step\'s "tool" as the ability name and its "arguments" as the parameters, exactly as given. Arguments are already valid, so you don\'t need to look up tool info first.';
$abilities_of  = static function ( $result ): array {
	return is_array( $result ) && isset( $result['steps'] ) ? array_column( $result['steps'], 'ability' ) : array();
};
$run_workflows = static function ( string $period = 'last_7_days' ) use ( $workflows ): array {
	$out = array();
	foreach ( array_keys( $workflows ) as $name ) {
		$ability      = wp_get_ability( $name );
		$out[ $name ] = null === $ability ? null : $ability->execute( array( 'period' => $period ) );
	}
	return $out;
};
// Every step's tool is the given connector's name for its ability.
$names_match = static function ( $result, $connector ): bool {
	if ( ! is_array( $result ) || array() === ( $result['steps'] ?? array() ) ) {
		return false;
	}
	foreach ( $result['steps'] as $step ) {
		if ( ! isset( $step['ability'] ) || $connector->tool_name( $step['ability'] ) !== $step['tool'] ) {
			return false;
		}
	}
	return true;
};

/**
 * Checks run in both the default and Easy MCP stub modes: Enable Abilities' denylist wins from any
 * active connector, a /mcp/ route uses recommended names, and a top-level exception thrown inside
 * a connector-route dispatch leaves no stale frame for a later direct call.
 */
$shared_connector_checks = static function ( string $mode ) use ( $names_match ): void {
	$health = 'blaze-mcp-toolkit/health-summary';

	// REMOVED_GLOBALLY: health-summary on Enable Abilities' denylist (read at call time).
	$dl_had  = false !== get_option( 'ewpa_thirdparty_disabled', false );
	$dl_prev = get_option( 'ewpa_thirdparty_disabled', array() );
	$dl_new  = is_array( $dl_prev ) ? array_values( $dl_prev ) : array();
	$dl_new[] = $health;
	update_option( 'ewpa_thirdparty_disabled', $dl_new );
	$dl_restore = static function () use ( $dl_had, $dl_prev ): void {
		if ( $dl_had ) {
			update_option( 'ewpa_thirdparty_disabled', $dl_prev );
		} else {
			delete_option( 'ewpa_thirdparty_disabled' );
		}
	};
	blzmcp_it_cleanup( $dl_restore );
	$weekly = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
	$dl_restore();
	$text = is_array( $weekly ) ? $weekly['instructions'] : '';
	blzmcp_it_assert( is_array( $weekly ) && ! in_array( $health, array_column( $weekly['steps'], 'ability' ), true ), "{$mode} denylist: health step left out" );
	blzmcp_it_assert( str_contains( $text, 'Enable Abilities for MCP' ) && str_contains( $text, admin_url( 'options-general.php?page=ewpa-settings' ) ), "{$mode} denylist: instructions name Enable Abilities for MCP and its settings link" );
	$not_on_store = (string) strstr( $text, 'NOT ON THIS STORE' );
	blzmcp_it_assert( ! str_contains( substr( $not_on_store, 0, (int) strpos( $not_on_store . 'SKIPPED STEPS: SWITCHED OFF', 'SKIPPED STEPS: SWITCHED OFF' ) ), $health ), "{$mode} denylist: health not listed as not on this store" );
	blzmcp_it_assert( str_contains( $text, 'SWITCHED OFF' ), "{$mode} denylist: diagnosed skip has its own section" );

	// Throwaway routes: /mcp/... (production lookup: recommended) and a throw route mapped by a test resolver.
	$GLOBALS['blzmcp_wf_route_result'] = null;
	$register                          = static function (): void {
		register_rest_route(
			'mcp',
			'/blzmcp-wf-test',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$GLOBALS['blzmcp_wf_route_result'] = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
					return array( 'ok' => true );
				},
			)
		);
		register_rest_route(
			'blzmcp-wf-test',
			'/throw',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$GLOBALS['blzmcp_wf_route_result'] = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
					throw new \RuntimeException( 'blzmcp-wf-top-level' );
				},
			)
		);
	};
	RequestContext::set_resolver( static fn( string $route ): ?string => '/blzmcp-wf-test/throw' === $route ? 'recommended' : Connectors::id_for_route( $route ) );
	add_action( 'rest_api_init', $register );
	$GLOBALS['wp_rest_server'] = null;
	blzmcp_it_cleanup(
		static function () use ( $register ): void {
			RequestContext::set_resolver( null );
			remove_action( 'rest_api_init', $register );
			$GLOBALS['wp_rest_server'] = null;
		}
	);
	$recommended = Connectors::get( 'recommended' );

	rest_do_request( new WP_REST_Request( 'GET', '/mcp/blzmcp-wf-test' ) );
	blzmcp_it_assert( $names_match( $GLOBALS['blzmcp_wf_route_result'], $recommended ), "{$mode}: a workflow run inside a /mcp/ route uses recommended names" );

	$GLOBALS['blzmcp_wf_route_result'] = null;
	$top                               = null;
	try {
		rest_do_request( new WP_REST_Request( 'GET', '/blzmcp-wf-test/throw' ) );
	} catch ( \RuntimeException $e ) {
		$top = $e;
	}
	blzmcp_it_assert( null !== $top && 'blzmcp-wf-top-level' === $top->getMessage(), "{$mode}: top-level exception propagates out of rest_do_request" );
	blzmcp_it_assert( $names_match( $GLOBALS['blzmcp_wf_route_result'], $recommended ), "{$mode}: inside the throwing route the workflow used the route's connector" );
	blzmcp_it_equals( null, RequestContext::current(), "{$mode}: no frame left after the exception" );
	$after = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
	blzmcp_it_assert( $names_match( $after, Connectors::signin_owner() ), "{$mode}: a later direct call uses the sign-in owner's names (no stale frame)" );
};

if ( $easy_stub ) {
	echo "Mode: easy-stub (both)\n";
	require_once __DIR__ . '/stub-easymcp-toolstate.php';
	$easy = Connectors::get( 'easy-mcp' );
	blzmcp_it_equals( 'both', Connectors::state(), 'easy-stub: state is both' );
	blzmcp_it_assert( class_exists( '\Easy_MCP_AI\Tools\Tool_State' ), 'easy-stub: stub Tool_State loaded' );

	$opt      = 'easy_mcp_ai_enabled_abilities';
	$opt_had  = false !== get_option( $opt, false );
	$opt_prev = get_option( $opt, array() );
	blzmcp_it_cleanup(
		static function () use ( $opt, $opt_had, $opt_prev ): void {
			if ( $opt_had ) {
				update_option( $opt, $opt_prev );
			} else {
				delete_option( $opt );
			}
			unset( $GLOBALS['blzmcp_toolstate_return'] );
		}
	);
	$all_names = array_map( static fn( $a ) => $a->get_name(), wp_get_abilities() );

	// All abilities ticked, Tool_State says on: Easy MCP names everywhere.
	update_option( $opt, $all_names );
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	$easy_run_line                      = 'This tool does not read store data itself. It returns the steps to run. ' . $easy->run_instruction();
	foreach ( $run_workflows() as $name => $result ) {
		$text = is_array( $result ) ? $result['instructions'] : '';
		blzmcp_it_assert( is_array( $result ) && array() !== $result['steps'], "easy-stub {$name}: runs and has steps" );
		$ok = is_array( $result );
		foreach ( is_array( $result ) ? $result['steps'] : array() as $step ) {
			$ok = $ok && str_starts_with( $step['tool'], 'wp_ability_' ) && EasyMcpNaming::tool_name( $step['ability'] ) === $step['tool'];
		}
		blzmcp_it_assert( $ok, "easy-stub {$name}: every tool is wp_ability_ + the Easy MCP name of its ability" );
		blzmcp_it_assert( str_contains( $text, "HOW TO USE THIS PLAN\n" . $easy_run_line . "\n" ), "easy-stub {$name}: instructions carry the Easy MCP run sentence" );
		blzmcp_it_assert( str_contains( $text, 'call the step\'s "tool" with its "arguments"' ) && ! str_contains( $text, 'execute-ability tool' ), "easy-stub {$name}: no execute-ability wording" );
		blzmcp_it_assert( ! str_contains( $text, '{tool:' ), "easy-stub {$name}: no {tool: token left" );
		blzmcp_it_assert( ! str_contains( $text, 'wc-analytics-confirm-large-range' ), "easy-stub {$name}: no hyphenated confirm-large-range name" );
		blzmcp_it_assert( ! str_contains( $text, 'NOT ON THIS STORE' ) && ! str_contains( $text, 'SWITCHED OFF' ), "easy-stub {$name}: nothing skipped" );
		if ( 'blaze-mcp-toolkit/failed-order-triage' === $name ) {
			blzmcp_it_assert( str_contains( $text, 'wp_ability_wc_analytics_rows' ) && ! str_contains( $text, 'wc-analytics/rows' ), 'easy-stub failed-order: instructions name wp_ability_wc_analytics_rows' );
		}
		if ( 'blaze-mcp-toolkit/revenue-drop-triage' === $name ) {
			blzmcp_it_assert( str_contains( $text, 'wp_ability_wc_analytics_breakdown' ) && ! str_contains( $text, 'wc-analytics/breakdown' ), 'easy-stub revenue-drop: instructions name wp_ability_wc_analytics_breakdown' );
		}
		// Same plan as the recommended connector, only the names differ.
		$rec = RequestContext::run( 'recommended', static fn() => wp_get_ability( $name )->execute( array( 'period' => 'last_7_days' ) ) );
		blzmcp_it_equals( $abilities_of( $rec ), $abilities_of( $result ), "easy-stub {$name}: same abilities as the recommended plan" );
		blzmcp_it_equals( wp_json_encode( array_column( $rec['steps'] ?? array(), 'arguments' ) ), wp_json_encode( array_column( $result['steps'] ?? array(), 'arguments' ) ), "easy-stub {$name}: same arguments as the recommended plan" );
	}

	// Seeded before 1.1: only our own abilities ticked, so the analytics steps are not reachable.
	update_option( $opt, array_values( array_filter( $all_names, static fn( $n ) => str_starts_with( $n, 'blaze-mcp-toolkit/' ) ) ) );
	foreach ( $run_workflows() as $name => $result ) {
		$text = is_array( $result ) ? $result['instructions'] : '';
		blzmcp_it_assert( is_array( $result ) && array() === array_filter( $abilities_of( $result ), static fn( $a ) => str_starts_with( (string) $a, 'wc-analytics/' ) ), "easy-stub seeded {$name}: analytics steps absent" );
		blzmcp_it_assert( str_contains( $text, 'Easy MCP AI › Tools › Abilities' ) && str_contains( $text, 'wp_ability_wc_analytics_totals' ), "easy-stub seeded {$name}: instructions list the analytics tools with Easy MCP AI › Tools › Abilities" );
		blzmcp_it_assert( str_contains( $text, $easy->settings_url() ), "easy-stub seeded {$name}: instructions link Easy MCP AI's settings" );
		blzmcp_it_assert( ! str_contains( (string) strstr( $text, 'NOT ON THIS STORE' ), 'wp_ability_wc_analytics' ), "easy-stub seeded {$name}: analytics not called missing from the store" );
	}

	// Ticked but switched off with a reason code: plain words, never the raw code.
	update_option( $opt, $all_names );
	$GLOBALS['blzmcp_toolstate_return'] = array( false, 'held' );
	$held                               = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
	$text                               = is_array( $held ) ? $held['instructions'] : '';
	blzmcp_it_assert( is_array( $held ) && array() === $held['steps'], 'easy-stub held: every step left out' );
	blzmcp_it_assert( str_contains( $text, 'SWITCHED OFF' ) && str_contains( $text, 'Easy MCP AI' ), 'easy-stub held: switched-off section names Easy MCP AI' );
	blzmcp_it_assert( ! str_contains( $text, '(held)' ) && ! str_contains( $text, 'held)' ), 'easy-stub held: raw reason code not shown' );

	// Effective state unreadable: "couldn't confirm it is switched on".
	$GLOBALS['blzmcp_toolstate_return'] = 'not-an-array';
	$unknown                            = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
	$text                               = is_array( $unknown ) ? $unknown['instructions'] : '';
	blzmcp_it_assert( is_array( $unknown ) && array() === $unknown['steps'], 'easy-stub unknown: every step left out' );
	blzmcp_it_assert( str_contains( $text, "couldn't confirm it is switched on" ), 'easy-stub unknown: says it could not confirm the tool is switched on' );

	// Back to all on for the shared checks.
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	$shared_connector_checks( 'easy-stub' );

	blzmcp_it_finish();
	return;
}

// Requested-period analytics check (audit F02), in both modes: a paid order 5 days ago missing from
// wc_order_stats stops a last_7_days review but not one for yesterday.
$wf_mode    = Commerce::is_standing_aside() ? 'standaside' : 'bundled';
blzmcp_it_sync_analytics();
$wf_product = new WC_Product_Simple();
$wf_product->set_name( 'Blzmcp workflow gap product' );
$wf_product->set_regular_price( '10' );
$wf_product->save();
$wf_order = wc_create_order();
blzmcp_it_cleanup(
	static function () use ( $wf_order, $wf_product ): void {
		blzmcp_it_delete_order( (int) $wf_order->get_id() );
		if ( wc_get_product( $wf_product->get_id() ) ) {
			$wf_product->delete( true );
		}
		\Blaze\McpToolkit\Bundled\AnalyticsGuard::clear_cache();
	}
);
$wf_order->add_product( $wf_product, 1 );
$wf_order->calculate_totals();
$wf_order->payment_complete();
$wf_order->set_date_created( time() - 5 * DAY_IN_SECONDS );
$wf_order->set_date_paid( time() - 5 * DAY_IN_SECONDS ); // Reports date paid orders by date paid (default Date type).
$wf_order->save();
$wpdb->delete( $wpdb->prefix . 'wc_order_stats', array( 'order_id' => $wf_order->get_id() ) );
\Blaze\McpToolkit\Bundled\AnalyticsGuard::clear_cache();
$wf_weekly = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' );
$wf_week   = null === $wf_weekly ? null : $wf_weekly->execute( array( 'period' => 'last_7_days' ) );
$wf_text   = is_array( $wf_week ) ? $wf_week['instructions'] : '';
blzmcp_it_assert( str_starts_with( $wf_text, '[ANALYTICS_UNAVAILABLE] ' ) && str_contains( substr( $wf_text, 0, 800 ), 'STOP' ), "{$wf_mode}: weekly review for last_7_days stops on a missing 5-day-old order" );
blzmcp_it_assert( array() === array_filter( $tools_of( $wf_week ), static fn( $t ) => str_starts_with( (string) $t, 'wc-analytics/' ) ), "{$wf_mode}: no wc-analytics steps while that week is incomplete" );
$wf_day  = null === $wf_weekly ? null : $wf_weekly->execute( array( 'period' => 'yesterday' ) );
$wf_text = is_array( $wf_day ) ? $wf_day['instructions'] : '';
blzmcp_it_assert( ! str_starts_with( $wf_text, '[ANALYTICS_UNAVAILABLE]' ), "{$wf_mode}: weekly review for yesterday (and the day before) is not stopped by it" );
$wf_order->delete( true );
$wf_product->delete( true );
\Blaze\McpToolkit\Bundled\AnalyticsGuard::clear_cache();

if ( Commerce::is_standing_aside() ) {
	echo "Mode: stand-aside\n";
	$settings = Settings::get();
	Settings::update( array_merge( $settings, array( 'shop_manager_diagnostics' => false ) ) );
	$sm_user = wp_insert_user(
		array(
			'user_login' => 'blzmcp_wf_sa_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'shop_manager',
		)
	);
	foreach ( array_keys( $workflows ) as $name ) {
		$ability = wp_get_ability( $name );
		blzmcp_it_assert( null !== $ability, "standaside: {$name} still registered" );
		if ( null === $ability ) {
			continue;
		}

		// Administrator: the store profile is missing, not forbidden.
		wp_set_current_user( $admins[0]->ID );
		$result = $ability->execute();
		$text   = is_array( $result ) ? $result['instructions'] : '';
		blzmcp_it_assert( ! in_array( $profile, $tools_of( $result ), true ), "standaside admin {$name}: no store-profile step" );
		blzmcp_it_assert( str_contains( $text, 'NOT ON THIS STORE' ) && str_contains( $text, $profile ), "standaside admin {$name}: store profile listed as not on this store" );
		blzmcp_it_assert( ! str_contains( $text, 'not available for your role' ) && ! str_contains( $text, 'administrator can' ), "standaside admin {$name}: nothing blamed on the admin's role" );
		blzmcp_it_assert( str_contains( $text, 'STORE NAME' ) && str_contains( $text, 'leave the **Store:** line out' ), "standaside admin {$name}: store-name rule present" );
		if ( 'blaze-mcp-toolkit/weekly-store-review' === $name ) {
			blzmcp_it_assert( in_array( $health, $tools_of( $result ), true ) && in_array( $history, $tools_of( $result ), true ), 'standaside admin weekly: diagnostics steps kept' );
		}

		// Shop manager, diagnostics off: both reasons, each in its own section.
		wp_set_current_user( $sm_user );
		$sm   = $ability->execute();
		$text = is_array( $sm ) ? $sm['instructions'] : '';
		blzmcp_it_assert( str_contains( $text, 'NOT ON THIS STORE' ), "standaside shop_manager {$name}: missing section present" );
		if ( in_array( $name, array( 'blaze-mcp-toolkit/weekly-store-review', 'blaze-mcp-toolkit/revenue-drop-triage' ), true ) ) {
			$denied_part = (string) strstr( $text, 'NOT AVAILABLE FOR YOUR ROLE' );
			blzmcp_it_assert( str_contains( $denied_part, $health ), "standaside shop_manager {$name}: health listed under not available for your role" );
			blzmcp_it_assert( ! str_contains( substr( $denied_part, 0, (int) strpos( $denied_part . 'STORE NAME', 'STORE NAME' ) ), $profile ), "standaside shop_manager {$name}: store profile not blamed on the role" );
		}
	}
	wp_set_current_user( $admins[0]->ID );

	// Analytics off while standing aside: every workflow still runs the Analytics check itself.
	$saved_enabled = get_option( 'woocommerce_analytics_enabled', 'yes' );
	update_option( 'woocommerce_analytics_enabled', 'no' );
	$expected_head = '[ANALYTICS_UNAVAILABLE] ' . \Blaze\McpToolkit\Bundled\AnalyticsGuard::message( 'analytics_disabled' );
	foreach ( array_keys( $workflows ) as $name ) {
		$ability = wp_get_ability( $name );
		$result  = null === $ability ? null : $ability->execute();
		$text    = is_array( $result ) ? $result['instructions'] : '';
		blzmcp_it_assert( str_starts_with( $text, $expected_head ), "standaside analytics off {$name}: instructions start with the analytics-unavailable message" );
		blzmcp_it_assert( str_contains( substr( $text, 0, 800 ), 'stop' ), "standaside analytics off {$name}: tells the AI to explain and stop" );
		$analytics_steps = array_filter( $tools_of( $result ), static fn( $t ) => str_starts_with( (string) $t, 'wc-analytics/' ) );
		blzmcp_it_assert( is_array( $result ) && array() === $analytics_steps, "standaside analytics off {$name}: no wc-analytics steps" );
		blzmcp_it_assert( ! str_contains( $text, '- wc-analytics/' ), "standaside analytics off {$name}: analytics steps not listed as skipped" );
	}
	update_option( 'woocommerce_analytics_enabled', $saved_enabled );

	// No store-tool class of ours loads while standing aside (WooCommerce for Claude owns the trait).
	blzmcp_it_assert( ! class_exists( 'Blaze\McpToolkit\Abilities\Store\StoreProfile', false ), 'standaside: running every workflow loads no Store\StoreProfile class' );
	blzmcp_it_assert( ! class_exists( 'Blaze\McpToolkit\Abilities\Store\AbstractStoreAbility', false ), 'standaside: no Store\AbstractStoreAbility class loaded' );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sm_user );
	Settings::update( $settings );
	blzmcp_it_finish();
	return;
}

$samples = array();
$gateway = wp_get_ability( 'mcp-adapter/execute-ability' );
blzmcp_it_assert( null !== $gateway, 'MCP Adapter execute-ability is registered' );

foreach ( $workflows as $name => $default_period ) {
	$ability = wp_get_ability( $name );
	blzmcp_it_assert( null !== $ability, "{$name} is registered" );
	if ( null === $ability ) {
		continue;
	}

	$meta = $ability->get_meta();
	blzmcp_it_assert( true === ( $meta['mcp']['public'] ?? null ), "{$name} has mcp.public" );
	blzmcp_it_assert( true === ( $meta['show_in_rest'] ?? null ), "{$name} has show_in_rest" );
	blzmcp_it_assert( true === ( $meta['annotations']['readonly'] ?? null ), "{$name} is readonly" );
	blzmcp_it_assert( str_contains( strtolower( $ability->get_description() ), 'plan' ), "{$name} description says it returns a plan" );

	$schema = $ability->get_input_schema();
	blzmcp_it_equals( $default_period, $schema['properties']['period']['default'] ?? null, "{$name} keeps upstream default period" );
	blzmcp_it_equals( $periods, $schema['properties']['period']['enum'] ?? null, "{$name} keeps upstream period enum" );
	blzmcp_it_assert( is_wp_error( $ability->execute( array( 'period' => 'custom' ) ) ), "{$name} rejects a period outside the enum" );
	blzmcp_it_assert( is_wp_error( $ability->execute( array( 'foo' => 1 ) ) ), "{$name} rejects unknown input keys" );

	$result = $ability->execute();
	blzmcp_it_assert( is_array( $result ), "{$name} runs with no input" );
	if ( ! is_array( $result ) ) {
		continue;
	}
	$samples[ $name ] = $result;

	blzmcp_it_equals( $name, $result['workflow'] ?? null, "{$name} output names the workflow" );
	blzmcp_it_equals( $default_period, $result['period'] ?? null, "{$name} output period is the default" );
	blzmcp_it_assert( is_string( $result['instructions'] ?? null ) && strlen( $result['instructions'] ) > 200, "{$name} has instructions" );

	$first = $result['steps'][0] ?? null;
	blzmcp_it_assert( null !== $first && 'wc-analytics/totals' === $first['tool'] && 'orders' === ( (array) $first['arguments'] )['subject'], "{$name} first step is totals orders" );

	$instructions = $result['instructions'];
	blzmcp_it_assert( str_contains( $instructions, '[ANALYTICS_UNAVAILABLE]' ), "{$name} instructions handle analytics unavailable" );
	blzmcp_it_assert( null !== $first && str_contains( $first['why'], '[ANALYTICS_UNAVAILABLE]' ), "{$name} first step why keys on the analytics token" );
	blzmcp_it_assert( str_contains( $instructions, 'untrusted_text' ) && str_contains( $instructions, 'read-only' ), "{$name} instructions carry the safety rules" );
	blzmcp_it_assert( str_contains( $instructions, 'Run the first step alone' ), "{$name} instructions run the first step alone" );
	blzmcp_it_assert( str_contains( $instructions, 'execute' ), "{$name} instructions say how to run steps" );
	blzmcp_it_assert( ! str_contains( $instructions, 'mcp-adapter' ), "{$name} instructions do not hard-code connector tool names" );
	blzmcp_it_assert( ! str_contains( $instructions, '{$period}' ) && str_contains( $instructions, $default_period ), "{$name} instructions carry the period" );
	blzmcp_it_assert( ! str_contains( $instructions, 'not available for your role' ), "{$name} as admin lists nothing as unavailable" );
	blzmcp_it_assert( ! str_contains( $instructions, 'NOT ON THIS STORE' ), "{$name} as admin lists nothing as missing" );
	blzmcp_it_assert( in_array( $profile, $tools_of( $result ), true ) && ! str_contains( $instructions, 'STORE NAME' ), "{$name} has the store profile step and no store-name fallback" );

	// Every step validates against its target's input schema and encodes arguments as a JSON object.
	$encoded = wp_json_encode( $result );
	blzmcp_it_assert( 0 === preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string) $encoded ), "{$name} output contains no email address" );
	$decoded = json_decode( (string) $encoded, false );
	foreach ( $result['steps'] as $i => $step ) {
		$target = wp_get_ability( $step['tool'] );
		blzmcp_it_assert( null !== $target, "{$name} step {$i} tool {$step['tool']} exists" );
		blzmcp_it_assert( is_string( $step['why'] ) && '' !== $step['why'], "{$name} step {$i} has a why" );
		blzmcp_it_assert( is_object( $decoded->steps[ $i ]->arguments ), "{$name} step {$i} arguments encode as a JSON object" );
		if ( null !== $target ) {
			$valid = rest_validate_value_from_schema( json_decode( (string) wp_json_encode( $step['arguments'] ), true ) ?? array(), $target->get_input_schema(), 'arguments' );
			blzmcp_it_assert( true === $valid, "{$name} step {$i} ({$step['tool']}) arguments validate" . ( is_wp_error( $valid ) ? ': ' . $valid->get_error_message() : '' ) );
		}
	}

	// As admin every step actually runs through the MCP gateway (analytics data is complete in wp-env).
	foreach ( $result['steps'] as $i => $step ) {
		$out = null === $gateway ? null : $gateway->execute(
			array(
				'ability_name' => $step['tool'],
				'parameters'   => json_decode( (string) wp_json_encode( $step['arguments'] ), true ),
			)
		);
		blzmcp_it_assert( is_array( $out ) && true === ( $out['success'] ?? null ), "{$name} step {$i} ({$step['tool']}) runs through execute-ability" . ( is_array( $out ) && isset( $out['error'] ) ? ': ' . $out['error'] : '' ) );
	}

	// Every period produces valid steps.
	foreach ( $periods as $period ) {
		$r  = $ability->execute( array( 'period' => $period ) );
		$ok = is_array( $r ) && $period === $r['period'];
		foreach ( is_array( $r ) ? $r['steps'] : array() as $step ) {
			$target = wp_get_ability( $step['tool'] );
			$ok     = $ok && null !== $target && true === rest_validate_value_from_schema( json_decode( (string) wp_json_encode( $step['arguments'] ), true ) ?? array(), $target->get_input_schema(), 'arguments' );
		}
		blzmcp_it_assert( $ok, "{$name} period {$period} gives valid steps" );
	}
}

// Weekly review (admin): health and update-history steps with the 7-day window.
$weekly = $samples['blaze-mcp-toolkit/weekly-store-review'] ?? null;
if ( is_array( $weekly ) ) {
	$h = $step_for( $weekly, $health );
	$u = $step_for( $weekly, $history );
	blzmcp_it_assert( null !== $h, 'weekly review includes health-summary' );
	blzmcp_it_assert( null !== $u, 'weekly review includes update-history' );
	blzmcp_it_equals( 168, ( (array) ( $h['arguments'] ?? array() ) )['since_hours'] ?? null, 'weekly health since_hours 168' );
	blzmcp_it_equals( 7, ( (array) ( $u['arguments'] ?? array() ) )['days'] ?? null, 'weekly update-history days 7' );
	blzmcp_it_assert( null !== $step_for( $weekly, 'wc-analytics/totals', 'refunds' ), 'weekly review keeps the refunds step' );
	blzmcp_it_assert( null !== $step_for( $weekly, 'wc-analytics/breakdown', 'attribution' ), 'weekly review keeps the channel breakdown' );
	blzmcp_it_assert( str_contains( $weekly['instructions'], 'same window' ), 'weekly instructions link dips to updates/errors in the same window' );

	$year = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute( array( 'period' => 'this_year' ) );
	blzmcp_it_assert( 168 === ( (array) $step_for( $year, $health )['arguments'] )['since_hours'], 'long period caps health at 168 hours' );
	blzmcp_it_assert( ( (array) $step_for( $year, $history )['arguments'] )['days'] <= 180, 'long period caps update-history at 180 days' );
	$today = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute( array( 'period' => 'today' ) );
	blzmcp_it_equals( 24, ( (array) $step_for( $today, $health )['arguments'] )['since_hours'] ?? null, 'today maps to 24 hours of health' );
	blzmcp_it_equals( 1, ( (array) $step_for( $today, $history )['arguments'] )['days'] ?? null, 'today maps to 1 day of history' );
}

// Analytics unavailable: the first step's error text reaches the AI through the gateway with the token.
if ( null !== $gateway && isset( $samples['blaze-mcp-toolkit/weekly-store-review'] ) ) {
	$saved_enabled = get_option( 'woocommerce_analytics_enabled', 'yes' );
	update_option( 'woocommerce_analytics_enabled', 'no' );
	$first = $samples['blaze-mcp-toolkit/weekly-store-review']['steps'][0];
	$out   = $gateway->execute(
		array(
			'ability_name' => $first['tool'],
			'parameters'   => json_decode( (string) wp_json_encode( $first['arguments'] ), true ),
		)
	);
	$off = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute();
	update_option( 'woocommerce_analytics_enabled', $saved_enabled );
	blzmcp_it_assert( is_array( $off ) && str_starts_with( $off['instructions'], '[ANALYTICS_UNAVAILABLE] ' ), 'analytics off: workflow instructions lead with the analytics-unavailable message' );
	blzmcp_it_assert( is_array( $off ) && ! in_array( 'wc-analytics/totals', $tools_of( $off ), true ), 'analytics off: workflow drops the analytics steps' );
	blzmcp_it_assert( is_array( $out ) && false === ( $out['success'] ?? null ), 'tripped guard: gateway reports failure' );
	blzmcp_it_assert( is_array( $out ) && str_starts_with( (string) ( $out['error'] ?? '' ), '[ANALYTICS_UNAVAILABLE] ' ), 'tripped guard: gateway error text starts with the token' );
}

// Revenue drop (admin): health and update-history over the drop window.
$drop = $samples['blaze-mcp-toolkit/revenue-drop-triage'] ?? null;
if ( is_array( $drop ) ) {
	blzmcp_it_equals( 168, ( (array) ( $step_for( $drop, $health )['arguments'] ?? array() ) )['since_hours'] ?? null, 'revenue drop health over the window (capped 168)' );
	blzmcp_it_equals( 30, ( (array) ( $step_for( $drop, $history )['arguments'] ?? array() ) )['days'] ?? null, 'revenue drop update-history over 30 days' );
}

// Failed orders: card-testing instruction and status-filtered row steps.
$failed = $samples['blaze-mcp-toolkit/failed-order-triage'] ?? null;
if ( is_array( $failed ) ) {
	blzmcp_it_assert( str_contains( $failed['instructions'], 'card-testing' ), 'failed-order instructions carry the card-testing rule' );
	$statuses = array();
	foreach ( $failed['steps'] as $step ) {
		$args = json_decode( (string) wp_json_encode( $step['arguments'] ), true );
		if ( 'wc-analytics/rows' === $step['tool'] ) {
			$statuses[] = ( $args['filters'][0]['value'] ?? '' ) . '/' . ( $args['mode'] ?? '' );
		}
	}
	sort( $statuses );
	blzmcp_it_equals( array( 'failed/aggregate', 'failed/rows', 'on-hold/aggregate', 'on-hold/rows' ), $statuses, 'failed-order rows steps cover on-hold and failed' );
}

// Refunds: refund totals and breakdowns.
$refund = $samples['blaze-mcp-toolkit/refund-triage'] ?? null;
if ( is_array( $refund ) ) {
	blzmcp_it_assert( null !== $step_for( $refund, 'wc-analytics/totals', 'refunds' ), 'refund triage has refund totals' );
	blzmcp_it_assert( null !== $step_for( $refund, 'wc-analytics/breakdown', 'refunds' ), 'refund triage has refund breakdown' );
}

// Recommended connector (1.1): tool equals ability, the 1.0 run sentence byte for byte, tokens rendered.
blzmcp_it_equals( 'recommended', Connectors::execution()->id(), 'default: execution connector is recommended' );
foreach ( $run_workflows() as $name => $result ) {
	$text = is_array( $result ) ? $result['instructions'] : '';
	$ok   = is_array( $result ) && array() !== $result['steps'];
	foreach ( is_array( $result ) ? $result['steps'] : array() as $step ) {
		$ok = $ok && isset( $step['ability'] ) && $step['tool'] === $step['ability'];
	}
	blzmcp_it_assert( $ok, "recommended {$name}: every step's tool equals its ability" );
	blzmcp_it_assert( str_contains( $text, "HOW TO USE THIS PLAN\n" . $run_line_10 . "\n" ), "recommended {$name}: run sentence is byte-identical to 1.0" );
	blzmcp_it_assert( ! str_contains( $text, '{tool:' ), "recommended {$name}: no {tool: token left" );
	if ( 'blaze-mcp-toolkit/failed-order-triage' === $name ) {
		blzmcp_it_assert( str_contains( $text, 'also run wc-analytics/rows twice more' ), 'recommended failed-order: instructions name wc-analytics/rows' );
	}
	if ( 'blaze-mcp-toolkit/revenue-drop-triage' === $name ) {
		blzmcp_it_assert( str_contains( $text, '(wc-analytics/breakdown with subject=revenue' ), 'recommended revenue-drop: instructions name wc-analytics/breakdown' );
	}
}
$shared_connector_checks( 'default' );

// Shop manager, diagnostics off: health steps absent, instructions say so, no denied audit rows from probing.
$settings = Settings::get();
Settings::update( array_merge( $settings, array( 'shop_manager_diagnostics' => false ) ) );
$created = array();
foreach ( array( 'shop_manager', 'subscriber' ) as $role ) {
	$created[ $role ] = wp_insert_user(
		array(
			'user_login' => 'blzmcp_wf_' . $role . '_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
		)
	);
}
wp_set_current_user( $created['shop_manager'] );
$audit_table = $wpdb->prefix . 'blzmcp_audit';
$denied_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $audit_table, 'denied' ) );

foreach ( array_keys( $workflows ) as $name ) {
	$ability = wp_get_ability( $name );
	if ( null === $ability ) {
		continue;
	}
	$sm = $ability->execute();
	blzmcp_it_assert( is_array( $sm ), "shop_manager can run {$name}" );
	if ( ! is_array( $sm ) ) {
		continue;
	}
	$tools = $tools_of( $sm );
	blzmcp_it_assert( ! in_array( $health, $tools, true ) && ! in_array( $history, $tools, true ), "{$name} as shop_manager has no diagnostics steps" );
	blzmcp_it_assert( in_array( 'wc-analytics/totals', $tools, true ), "{$name} as shop_manager keeps analytics steps" );
	if ( in_array( $name, array( 'blaze-mcp-toolkit/weekly-store-review', 'blaze-mcp-toolkit/revenue-drop-triage' ), true ) ) {
		blzmcp_it_assert( str_contains( $sm['instructions'], 'not available for your role' ), "{$name} as shop_manager says steps are not available for your role" );
		blzmcp_it_assert( str_contains( $sm['instructions'], $health ), "{$name} as shop_manager names the dropped health tool" );
		blzmcp_it_assert( ! str_contains( $sm['instructions'], 'NOT ON THIS STORE' ), "{$name} as shop_manager does not call a role denial missing" );
	}
}
$denied_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $audit_table, 'denied' ) );
blzmcp_it_equals( $denied_before, $denied_after, 'probing step availability writes no denied audit rows' );

// The probe flag is cleared: a real denied call after the workflows is still audited, once.
$real = wp_get_ability( $health )->execute( array( 'since_hours' => 24 ) );
$denied_real = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $audit_table, 'denied' ) );
blzmcp_it_assert( is_wp_error( $real ), 'shop_manager real health-summary call is denied' );
blzmcp_it_equals( $denied_after + 1, $denied_real, 'real denied health-summary call adds exactly one denied audit row' );

// Shop manager, diagnostics on: health steps return.
Settings::update( array_merge( $settings, array( 'shop_manager_diagnostics' => true ) ) );
$weekly_ability = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' );
$sm_on          = $weekly_ability ? $weekly_ability->execute() : null;
blzmcp_it_assert( in_array( $health, $tools_of( $sm_on ), true ), 'shop_manager with diagnostics on gets the health step' );
Settings::update( $settings );

// Subscriber: denied.
wp_set_current_user( $created['subscriber'] );
foreach ( array_keys( $workflows ) as $name ) {
	$ability = wp_get_ability( $name );
	if ( null !== $ability ) {
		blzmcp_it_assert( is_wp_error( $ability->execute() ), "subscriber is denied {$name}" );
	}
}

wp_set_current_user( $admins[0]->ID );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $created as $id ) {
	wp_delete_user( $id );
}

if ( getenv( 'BLZMCP_SAMPLE' ) ) {
	foreach ( $samples as $name => $sample ) {
		echo "== {$name}\n" . wp_json_encode( $sample, JSON_PRETTY_PRINT ) . "\n";
		echo "-- description: " . wp_get_ability( $name )->get_description() . "\n";
	}
}

blzmcp_it_finish();
