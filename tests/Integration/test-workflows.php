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
require_once __DIR__ . '/card-testing-fixtures.php';

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
$payment = 'blaze-mcp-toolkit/payment-health';
$card    = 'blaze-mcp-toolkit/card-testing';
// Workflows that list payment health first and card testing second (RefundTriage gets neither).
$payment_first = array( 'blaze-mcp-toolkit/weekly-store-review', 'blaze-mcp-toolkit/revenue-drop-triage', 'blaze-mcp-toolkit/failed-order-triage' );
// Position of the first step running an ability (selected by ability, never assumed by position).
$index_of = static function ( $result, string $ability, ?string $subject = null ): ?int {
	foreach ( (array) ( is_array( $result ) ? $result['steps'] : array() ) as $i => $step ) {
		$args = (array) $step['arguments'];
		if ( ( $step['ability'] ?? null ) === $ability && ( null === $subject || ( $args['subject'] ?? null ) === $subject ) ) {
			return (int) $i;
		}
	}
	return null;
};
// Clauses from 1.1 that would contradict the Analytics-only stop or the payment-first lead (F03, F17),
// and (1.3) every form of the removed failed > paid card-testing shortcut and its exceptions.
$contradictions = static function ( string $text ): array {
	$found = array();
	foreach ( array( 'stop there', 'do not run the other steps', 'and stop.', 'do not run the steps below', 'report any numbers or write the review', 'The first step reads order totals', 'Lead with collected revenue:', 'Treat card-gateway on-hold orders as abnormal', 'Lead with the most important movement.', 'failed orders exceed paid orders', 'greater than the number of paid orders', 'versus 15 paid orders', 'The only exception is the card-testing check', 'except for the card-testing check below', 'this overrides the paid-order rules', 'likely sign of card testing', 'or possible card testing' ) as $clause ) {
		if ( str_contains( $text, $clause ) ) {
			$found[] = $clause;
		}
	}
	return $found;
};
$whys_of = static function ( $result ): string {
	return implode( "\n", array_column( (array) ( is_array( $result ) ? $result['steps'] : array() ), 'why' ) );
};

// 1.3 wave 2 (REVIEW-pass4 item 7): the card tool's own missing-origin possible_attack must be reported
// and led with, while card testing is never inferred from payment-health inventory alone. Checked
// sentence by sentence, so the directions agree, not merely that some wording is present: every
// sentence that ties card testing to inventory_only methods, orders outside verified checkout or
// orders with no recorded origin may only forbid inferring it, never forbid reporting it.
$direction_problems = static function ( string $text ): array {
	$problems = array();
	foreach ( array( 'If its verdict is likely_attack or possible_attack, lead the answer with it', 'When that result is a possible_attack for orders with no record of where they came from, report and lead with it', 'never infer card testing from payment health' ) as $needed ) {
		if ( ! str_contains( $text, $needed ) ) {
			$problems[] = 'missing direction: ' . $needed;
		}
	}
	foreach ( (array) preg_split( '/(?<=[.!?])\s+/', $text ) as $sentence ) {
		$s          = strtolower( (string) $sentence );
		$card_s     = str_contains( $s, 'card testing' ) || str_contains( $s, 'card-testing' );
		$unverified = str_contains( $s, 'inventory_only' ) || str_contains( $s, 'outside verified checkout' ) || str_contains( $s, 'no record of where' );
		$forbids    = 1 === preg_match( '/never (be )?(described|called|call|present|count|report|mention|treat)|never as\b|not as card testing/', $s );
		if ( $card_s && $unverified && $forbids && ! str_contains( $s, 'infer' ) ) {
			$problems[] = 'conflicting direction: ' . trim( (string) $sentence );
		}
	}
	return $problems;
};
// The checker catches the wave 1 wording the lead rejected (AbstractWorkflow.php:478, FailedOrderTriage.php:140).
$wave1_lines = array(
	'- Keep it apart from payment health: a card-testing result never changes payment health\'s counts or statuses, and payment health\'s inventory_only methods and orders outside verified checkout are never described as card testing.',
	'A payment method marked inventory_only was not assessed: mention it as a method to confirm, never as a failed checkout, a declined payment or card testing.',
);
foreach ( $wave1_lines as $i => $line ) {
	blzmcp_it_assert( array() !== array_filter( $direction_problems( $line ), static fn( $p ) => str_starts_with( $p, 'conflicting direction' ) ), "direction checker: the rejected wave 1 sentence {$i} is reported as a conflict" );
}

/**
 * A real missing-origin burst seen by both tools (wave 2): 100 failed orders in the last half hour with
 * no recorded origin, for a method the kind filter marks instant and that is neither registered nor
 * switched on. The card tool returns a qualified possible_attack for that missing-origin scope; payment
 * health lists the same method as inventory_only. Then every payment workflow, under the connector this
 * process runs, must direct the assistant to lead with the card tool's own result and its caveat and
 * never to infer card testing from the inventory row. Orders are CRUD fixtures removed at finish.
 */
$paired_case = static function ( string $mode ) use ( $admins, $payment_first, $card, $payment, $index_of, $direction_problems ): void {
	global $wpdb;
	wp_set_current_user( $admins[0]->ID );
	$mo   = blzmcp_ct_method( 'mo' );
	$kind = static fn( $k, $id ) => $mo === $id ? 'instant' : $k;
	add_filter( 'blaze_mcp_toolkit_payment_method_kind', $kind, 20, 2 );
	add_filter( 'pre_wp_mail', '__return_false', 1 );
	$now = time();
	for ( $i = 0; $i < 100; $i++ ) {
		blzmcp_ct_order(
			array(
				'method'  => $mo,
				'via'     => '',
				'status'  => 'failed',
				'created' => $now - 1800 + 15 * $i,
			)
		);
	}
	remove_filter( 'pre_wp_mail', '__return_false', 1 );
	// Results cached by earlier calls in this run would predate the fixtures: start cold.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_blzmcp_c_' ) . '%', $wpdb->esc_like( '_transient_timeout_blzmcp_c_' ) . '%' ) );
	wp_cache_flush();
	$card_r = wp_get_ability( $card )->execute( array( 'since_hours' => 48 ) );
	$pay_r  = wp_get_ability( $payment )->execute( array( 'since_hours' => 24 ) );
	$mo_card = null;
	foreach ( (array) ( is_array( $card_r ) ? $card_r['assessments'] : array() ) as $a ) {
		if ( $mo === $a['method_id'] && 'missing_origin' === $a['origin_scope'] ) {
			$mo_card = $a;
		}
	}
	$mo_find = '';
	foreach ( (array) ( is_array( $card_r ) ? $card_r['findings'] : array() ) as $f ) {
		if ( $mo === $f['method_id'] ) {
			$mo_find = (string) $f['what'];
		}
	}
	$mo_pay = null;
	foreach ( (array) ( is_array( $pay_r ) ? $pay_r['methods'] : array() ) as $m ) {
		if ( $mo === $m['id'] ) {
			$mo_pay = $m;
		}
	}
	blzmcp_it_equals( array( 'possible_attack', 'origin_unverified' ), array( $mo_card['verdict'] ?? null, $mo_card['reason_code'] ?? null ), "{$mode} paired case: the card tool returns its own qualified possible_attack for the missing-origin orders" );
	blzmcp_it_assert( str_contains( $mo_find, 'whether they came through the checkout could not be established' ), "{$mode} paired case: the card finding carries the could-not-be-established caveat the instructions refer to" );
	blzmcp_it_equals( 'inventory_only', $mo_pay['assessment'] ?? null, "{$mode} paired case: payment health lists the same method as inventory_only (not assessed)" );
	foreach ( $payment_first as $wf ) {
		$r    = wp_get_ability( $wf )->execute();
		$text = is_array( $r ) ? $r['instructions'] : '';
		blzmcp_it_equals( 1, $index_of( $r, $card ), "{$mode} paired case {$wf}: the card step is in the plan" );
		blzmcp_it_equals( array(), $direction_problems( $text ), "{$mode} paired case {$wf}: the directions agree (lead with the card tool's own result, never infer card testing from inventory)" );
		// The two real results meet the directions: the card verdict is one the lead rule covers, and the
		// inventory row is one the inference ban covers.
		blzmcp_it_assert( in_array( $mo_card['verdict'] ?? null, array( 'likely_attack', 'possible_attack' ), true ) && 'inventory_only' === ( $mo_pay['assessment'] ?? null ) && str_contains( $text, 'never infer card testing from payment health\'s inventory_only methods' ), "{$mode} paired case {$wf}: the card result is led with and the inventory row is never turned into card testing" );
	}
	remove_filter( 'blaze_mcp_toolkit_payment_method_kind', $kind, 20 );
};

// Connector naming (1.1): the run sentence 1.0 shipped, and helpers shared by the default and
// Easy MCP stub runs.
$easy_stub     = defined( 'EASY_MCP_AI_VERSION' );
// Real Easy MCP AI (tests/matrix) rather than the stub constant: its own Tool_State answers.
$easy_real     = $easy_stub && ! str_ends_with( (string) constant( 'EASY_MCP_AI_VERSION' ), '-stub' );
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
	$health      = 'blaze-mcp-toolkit/health-summary';
	$recommended = Connectors::get( 'recommended' );
	// With Easy MCP AI alone (real matrix state) there is no Enable Abilities denylist, and a /mcp/
	// route falls back to the sign-in owner because the recommended connector is inactive.
	$route_connector = $recommended->is_active() ? $recommended : Connectors::signin_owner();

	// REMOVED_GLOBALLY: health-summary on Enable Abilities' denylist (read at call time).
	if ( ! $recommended->is_active() ) {
		echo "NOTE  {$mode}: Enable Abilities inactive: denylist checks skipped\n";
	}
	if ( $recommended->is_active() ) {
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
	}

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

	rest_do_request( new WP_REST_Request( 'GET', '/mcp/blzmcp-wf-test' ) );
	blzmcp_it_assert( $names_match( $GLOBALS['blzmcp_wf_route_result'], $route_connector ), "{$mode}: a workflow run inside a /mcp/ route uses " . $route_connector->id() . ' names' );

	$GLOBALS['blzmcp_wf_route_result'] = null;
	$top                               = null;
	try {
		rest_do_request( new WP_REST_Request( 'GET', '/blzmcp-wf-test/throw' ) );
	} catch ( \RuntimeException $e ) {
		$top = $e;
	}
	blzmcp_it_assert( null !== $top && 'blzmcp-wf-top-level' === $top->getMessage(), "{$mode}: top-level exception propagates out of rest_do_request" );
	blzmcp_it_assert( $names_match( $GLOBALS['blzmcp_wf_route_result'], $route_connector ), "{$mode}: inside the throwing route the workflow used the route's connector (or the sign-in owner when that one is inactive)" );
	blzmcp_it_equals( null, RequestContext::current(), "{$mode}: no frame left after the exception" );
	$after = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
	blzmcp_it_assert( $names_match( $after, Connectors::signin_owner() ), "{$mode}: a later direct call uses the sign-in owner's names (no stale frame)" );
};

// Stand-aside plus Easy MCP AI (FEATURE-PLAN item 8): payment health is ours, so it is registered and
// named for Easy MCP even while WooCommerce for Claude supplies the store and Analytics tools.
if ( $easy_stub && Commerce::is_standing_aside() ) {
	echo "Mode: stand-aside+easy\n";
	if ( ! $easy_real ) {
		require_once __DIR__ . '/stub-easymcp-toolstate.php';
	}
	$sa_opt      = 'easy_mcp_ai_enabled_abilities';
	$sa_opt_had  = false !== get_option( $sa_opt, false );
	$sa_opt_prev = get_option( $sa_opt, array() );
	blzmcp_it_cleanup(
		static function () use ( $sa_opt, $sa_opt_had, $sa_opt_prev ): void {
			$sa_opt_had ? update_option( $sa_opt, $sa_opt_prev ) : delete_option( $sa_opt );
			unset( $GLOBALS['blzmcp_toolstate_return'] );
		}
	);
	update_option( $sa_opt, array_map( static fn( $a ) => $a->get_name(), wp_get_abilities() ) );
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	blzmcp_it_assert( null !== wp_get_ability( $payment ), 'stand-aside+easy: payment health still registered' );
	foreach ( $run_workflows() as $name => $result ) {
		$text = is_array( $result ) ? $result['instructions'] : '';
		blzmcp_it_assert( is_array( $result ), "stand-aside+easy {$name}: runs" );
		blzmcp_it_assert( ! str_contains( $text, '{tool:' ), "stand-aside+easy {$name}: no {tool: token left" );
		blzmcp_it_equals( array(), $contradictions( $text . "\n" . $whys_of( $result ) ), "stand-aside+easy {$name}: no contradictory stop or lead clause" );
		if ( in_array( $name, $payment_first, true ) ) {
			$first = $result['steps'][0] ?? array();
			blzmcp_it_assert( 'blaze-mcp-toolkit/payment-health' === ( $first['ability'] ?? null ) && 'wp_ability_blaze_mcp_toolkit_payment_health' === ( $first['tool'] ?? null ), "stand-aside+easy {$name}: payment health first under its Easy MCP name" );
			$second = $result['steps'][1] ?? array();
			blzmcp_it_assert( ( $second['ability'] ?? null ) === $card && 'wp_ability_blaze_mcp_toolkit_card_testing' === ( $second['tool'] ?? null ), "stand-aside+easy {$name}: card testing second under its Easy MCP name" );
			blzmcp_it_assert( str_contains( $text, "PAYMENT CHECK\n" ) && str_contains( $text, "CARD TESTING CHECK\n" ), "stand-aside+easy {$name}: payment and card-testing paragraphs" );
		} else {
			blzmcp_it_assert( null === $step_for( $result, $payment ) && ! str_contains( $text, 'PAYMENT CHECK' ), "stand-aside+easy {$name}: no payment step" );
			blzmcp_it_assert( null === $index_of( $result, $card ) && ! str_contains( $text, 'CARD TESTING CHECK' ), "stand-aside+easy {$name}: no card-testing step" );
		}
		if ( is_array( $result ) && array() === $result['steps'] ) {
			blzmcp_it_assert( str_contains( $text, 'No step in this plan can run here' ), "stand-aside+easy {$name}: zero steps give no impossible run order" );
		}
	}
	blzmcp_it_finish();
	return;
}

if ( $easy_stub ) {
	$easy = Connectors::get( 'easy-mcp' );
	if ( $easy_real ) {
		echo 'Mode: real Easy MCP AI ' . constant( 'EASY_MCP_AI_VERSION' ) . ' (' . Connectors::state() . ")\n";
		blzmcp_it_assert( in_array( Connectors::state(), array( 'easy-mcp', 'both' ), true ), 'real easy: state is easy-mcp or both' );
		blzmcp_it_equals( Connectors::get( 'recommended' )->is_active() ? 'both' : 'easy-mcp', Connectors::state(), 'real easy: state follows Enable Abilities' );
	} else {
		echo "Mode: easy-stub (both)\n";
		require_once __DIR__ . '/stub-easymcp-toolstate.php';
		blzmcp_it_equals( 'both', Connectors::state(), 'easy-stub: state is both' );
		blzmcp_it_assert( class_exists( '\Easy_MCP_AI\Tools\Tool_State' ), 'easy-stub: stub Tool_State loaded' );
	}

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

	// 1.3: an existing install that has not ticked the new card-testing tool. The step is left out
	// as switched off with where to switch it on, explained once, and the review carries on without
	// judging card testing from failed and paid counts. The owner's ticked list is never changed.
	$no_card_ticked = array_values( array_diff( $all_names, array( $card ) ) );
	update_option( $opt, $no_card_ticked );
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	foreach ( $payment_first as $wf ) {
		$r = wp_get_ability( $wf )->execute( array( 'period' => 'last_7_days' ) );
		$t = is_array( $r ) ? $r['instructions'] : '';
		blzmcp_it_assert( null === $index_of( $r, $card ) && 0 === $index_of( $r, $payment ) && 1 === $index_of( $r, 'wc-analytics/totals', 'orders' ), "easy-stub card unticked {$wf}: payment health first, totals second" );
		blzmcp_it_assert( str_contains( $t, 'SWITCHED OFF' ) && str_contains( $t, 'wp_ability_blaze_mcp_toolkit_card_testing' ) && str_contains( $t, 'Easy MCP AI' ), "easy-stub card unticked {$wf}: listed as switched off in Easy MCP AI" );
		blzmcp_it_assert( str_contains( $t, 'The card-testing check is not in this plan' ) && str_contains( $t, 'Never judge card testing from failed and paid order counts, or from payment health\'s inventory_only methods or orders outside verified checkout, instead.' ), "easy-stub card unticked {$wf}: explained once, no failed > paid or inventory fallback" );
		blzmcp_it_equals( array(), $contradictions( $t ), "easy-stub card unticked {$wf}: no contradictory clause" );
	}
	blzmcp_it_equals( $no_card_ticked, get_option( $opt ), 'easy-stub card unticked: the owner\'s ticked list is left as it was' );

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
	if ( $easy_real ) {
		// Real Tool_State: switch every tool off through Easy MCP AI's disabled-tools list.
		$dt_had  = false !== get_option( 'easy_mcp_ai_disabled_tools', false );
		$dt_prev = get_option( 'easy_mcp_ai_disabled_tools', array() );
		$dt_back = static function () use ( $dt_had, $dt_prev ): void {
			if ( $dt_had ) {
				update_option( 'easy_mcp_ai_disabled_tools', $dt_prev );
			} else {
				delete_option( 'easy_mcp_ai_disabled_tools' );
			}
		};
		blzmcp_it_cleanup( $dt_back );
		update_option( 'easy_mcp_ai_disabled_tools', array_merge( (array) $dt_prev, array_map( array( EasyMcpNaming::class, 'tool_name' ), $all_names ) ) );
		$held = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
		$dt_back();
		$text = is_array( $held ) ? $held['instructions'] : '';
		blzmcp_it_assert( is_array( $held ) && array() === $held['steps'], 'real easy disabled: every step left out' );
		blzmcp_it_assert( str_contains( $text, 'SWITCHED OFF' ) && str_contains( $text, 'Easy MCP AI' ), 'real easy disabled: switched-off section names Easy MCP AI' );
		blzmcp_it_assert( ! str_contains( $text, '(disabled)' ) && ! str_contains( $text, 'disabled)' ), 'real easy disabled: raw reason code not shown' );
		echo "NOTE  real Tool_State: the unreadable-state case needs the stub (fast tier)\n";
	} else {
		$GLOBALS['blzmcp_toolstate_return'] = array( false, 'held' );
		$held                               = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
		$text                               = is_array( $held ) ? $held['instructions'] : '';
		blzmcp_it_assert( is_array( $held ) && array() === $held['steps'], 'easy-stub held: every step left out' );
		blzmcp_it_assert( str_contains( $text, 'No step in this plan can run here' ) && ! str_contains( $text, 'Run the first step alone' ), 'easy-stub held: zero surviving steps give no impossible run order' );
		blzmcp_it_assert( str_contains( $text, 'SWITCHED OFF' ) && str_contains( $text, 'Easy MCP AI' ), 'easy-stub held: switched-off section names Easy MCP AI' );
		blzmcp_it_assert( ! str_contains( $text, '(held)' ) && ! str_contains( $text, 'held)' ), 'easy-stub held: raw reason code not shown' );

		// Effective state unreadable: "couldn't confirm it is switched on".
		$GLOBALS['blzmcp_toolstate_return'] = 'not-an-array';
		$unknown                            = wp_get_ability( WeeklyStoreReview::NAME )->execute( array( 'period' => 'last_7_days' ) );
		$text                               = is_array( $unknown ) ? $unknown['instructions'] : '';
		blzmcp_it_assert( is_array( $unknown ) && array() === $unknown['steps'], 'easy-stub unknown: every step left out' );
		blzmcp_it_assert( str_contains( $text, "couldn't confirm it is switched on" ), 'easy-stub unknown: says it could not confirm the tool is switched on' );
	}

	// Back to all on for the shared checks.
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	$shared_connector_checks( $easy_real ? 'real-easy' : 'easy-stub' );

	// Paired missing-origin card / payment-inventory case under the Easy MCP AI connector (wave 2).
	update_option( $opt, $all_names );
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	$paired_case( $easy_real ? 'real-easy' : 'easy-stub' );

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
blzmcp_it_assert( is_array( $wf_week ) && 'blaze-mcp-toolkit/payment-health' === ( $wf_week['steps'][0]['ability'] ?? null ), "{$wf_mode}: the incomplete week still runs payment health first" );
blzmcp_it_equals( 1, $index_of( $wf_week, $card ), "{$wf_mode}: the incomplete week still runs card testing second" );
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
		if ( in_array( $name, $payment_first, true ) ) {
			blzmcp_it_assert( 'blaze-mcp-toolkit/payment-health' === ( $result['steps'][0]['ability'] ?? null ), "standaside analytics off {$name}: payment health registered independently and still first" );
			blzmcp_it_equals( 1, $index_of( $result, $card ), "standaside analytics off {$name}: card testing registered independently and still second" );
			blzmcp_it_assert( str_contains( $text, 'likely or possible card-testing findings first, then payment health findings' ), "standaside analytics off {$name}: card evidence and protection advice still reported" );
		} else {
			blzmcp_it_assert( ! str_contains( $text, 'card-testing' ) && ! str_contains( $text, 'card testing' ), "standaside analytics off {$name}: no card-testing text without the card step" );
		}
		blzmcp_it_equals( array(), $contradictions( $text . "\n" . $whys_of( $result ) ), "standaside analytics off {$name}: no contradictory stop clause" );
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

	$first  = $result['steps'][0] ?? null;
	$totals_step = $step_for( $result, 'wc-analytics/totals', 'orders' );
	$instructions = $result['instructions'];
	if ( in_array( $name, $payment_first, true ) ) {
		blzmcp_it_assert( null !== $first && $payment === $first['ability'], "{$name} first step is payment health" );
		blzmcp_it_equals( 168, ( (array) ( $first['arguments'] ?? array() ) )['since_hours'] ?? null, "{$name} payment health over the capped window (168 hours for {$default_period})" );
		// 1.3 item 7: payment health, then card testing, then the orders totals alone, selected by ability and subject.
		blzmcp_it_equals( array( 0, 1, 2 ), array( $index_of( $result, $payment ), $index_of( $result, $card ), $index_of( $result, 'wc-analytics/totals', 'orders' ) ), "{$name} payment health, then card testing, then order totals" );
		$card_step = $result['steps'][ (int) $index_of( $result, $card ) ] ?? array();
		blzmcp_it_equals( 168, ( (array) ( $card_step['arguments'] ?? array() ) )['since_hours'] ?? null, "{$name} card testing over the shared capped window (168 hours for {$default_period})" );
		blzmcp_it_assert( str_contains( (string) ( $card_step['why'] ?? '' ), 'A current rolling look-back, not the selected period.' ), "{$name} card step is a rolling look-back" );
		blzmcp_it_assert( str_contains( $instructions, 'Then run the second step alone: it is the card-testing check. Whatever it returns, carry on. Then run the order totals step alone.' ), "{$name} run order: payment health alone, card testing alone, then totals alone" );
		blzmcp_it_assert( str_contains( $instructions, "CARD TESTING CHECK\n" ) && strpos( $instructions, "CARD TESTING CHECK\n" ) < strpos( $instructions, "PAYMENT CHECK\n" ), "{$name} card-testing guidance comes before the payment guidance" );
		foreach ( array( 'If its verdict is likely_attack or possible_attack, lead the answer with it, before any payment health finding or sales figure', 'how many failed orders were created, when, and its caveats', 'its caveat that a payment fault is still possible', 'Never call it confirmed, never say an attack is happening now and never make it stronger than returned', 'whose protection_state is not_configured or unknown', 'Never link them to card testing, or card testing to a sales movement, unless a returned result itself does', 'Unknown means not assessed, never a sign that checkout is fine', 'Never say the checkout is safe or protected', 'never build a total or a rate from its examples', 'never judge card testing by comparing failed orders with paid orders or with Analytics figures', 'enforcement is never verified, unknown does not mean unprotected', 'never for you to change', 'never infer card testing from payment health\'s inventory_only methods or its orders outside verified checkout', 'When that result is a possible_attack for orders with no record of where they came from, report and lead with it like any other possible result, keeping its caveat that whether those orders came through the checkout could not be established', 'mention once that the card-testing check could not run, and carry on' ) as $clause ) {
			blzmcp_it_assert( str_contains( $instructions, $clause ), "{$name} card-testing guidance: {$clause}" );
		}
		blzmcp_it_assert( str_contains( $instructions, "PAYMENT CHECK\n" ) && str_contains( $instructions, 'report its findings straight after any likely or possible card-testing result and before any sales figures' ) && ! str_contains( $instructions, 'lead the answer with its findings' ), "{$name} red or amber payment findings follow card testing, never a second lead" );
		blzmcp_it_assert( str_contains( $instructions, 'rolling look-back, not a reconstruction' ), "{$name} instructions say payment health is a rolling look-back" );
		blzmcp_it_assert( str_contains( $instructions, 'never present them as Analytics sales, money lost or confirmed charges' ), "{$name} instructions keep payment counts apart from sales and charges" );
		blzmcp_it_assert( str_contains( $instructions, 'Then run the order totals step alone' ), "{$name} instructions run totals alone after payment" );
		// 1.2.1 item 4: inventory observations are kept apart from checkout counts and findings.
		blzmcp_it_assert( str_contains( $instructions, 'no payment problem was found in the checks it assessed. Green never covers a method whose assessment is inventory_only.' ), "{$name} green is scoped to the assessed checks" );
		blzmcp_it_assert( ! str_contains( $instructions, 'say in one line that no payment problem was found.' ), "{$name} no unscoped all-clear" );
		blzmcp_it_assert( str_contains( $instructions, 'Only its findings are payment problems.' ) && str_contains( $instructions, 'Mention such methods separately, after any real findings' ), "{$name} real findings still lead; inventory-only methods are mentioned separately" );
		blzmcp_it_assert( str_contains( $instructions, 'Never call them a payment problem, a checkout failure, a lost sale, a charge or money at risk' ) && str_contains( $instructions, 'never count their orders as accepted, unpaid or failed checkouts or as sales' ) && str_contains( $instructions, 'never suggest switching a method back on' ), "{$name} inventory-only methods are never problems, counts or re-enable instructions" );
		blzmcp_it_assert( str_contains( $instructions, 'never say it was used only outside checkout' ) && str_contains( $instructions, 'methods_truncated' ), "{$name} partial reads and the identity cap are not described as exhaustive" );
		blzmcp_it_assert( str_contains( $instructions, 'whether the method is meant for checkout, and how those orders were created' ), "{$name} the suggested check is manual confirmation, never a repair" );
		blzmcp_it_assert( str_contains( $instructions, 'Never describe payments as healthy when a check is unknown.' ), "{$name} unknown is never reassuring" );
		$own = array(
			'blaze-mcp-toolkit/weekly-store-review' => 'list it in the Watch List as a method to confirm, never as a payment problem, a loss or a reason for a dip',
			'blaze-mcp-toolkit/revenue-drop-triage' => 'mention it as a method to confirm, never as a cause of the drop, a lost sale or money at risk',
			'blaze-mcp-toolkit/failed-order-triage' => 'mention it as a method to confirm, never as a failed checkout or a declined payment, and never infer card testing from it',
		);
		blzmcp_it_assert( str_contains( $instructions, $own[ $name ] ), "{$name} its own guidance keeps inventory-only methods out of its problem narrative" );
	} else {
		blzmcp_it_assert( null !== $first && 'wc-analytics/totals' === $first['tool'] && 'orders' === ( (array) $first['arguments'] )['subject'], "{$name} first step is totals orders" );
		blzmcp_it_assert( null === $step_for( $result, $payment ), "{$name} has no payment step" );
		blzmcp_it_assert( ! str_contains( $instructions, 'PAYMENT CHECK' ), "{$name} has no PAYMENT CHECK paragraph" );
		blzmcp_it_assert( null === $index_of( $result, $card ) && ! str_contains( $instructions, 'CARD TESTING CHECK' ) && ! str_contains( $instructions, 'card-testing' ), "{$name} gains neither the card-testing step nor its preamble" );
		blzmcp_it_assert( ! str_contains( $instructions, 'inventory_only' ), "{$name} has no payment inventory paragraph" );
	}
	blzmcp_it_equals( array(), $contradictions( $instructions . "\n" . $whys_of( $result ) ), "{$name} instructions and step reasons carry no contradictory stop or lead clause" );
	blzmcp_it_assert( str_contains( $instructions, 'stop the Analytics work only' ) && str_contains( $instructions, 'Still run the listed steps that are not Analytics' ), "{$name} analytics unavailable stops only the Analytics work" );

	blzmcp_it_assert( str_contains( $instructions, '[ANALYTICS_UNAVAILABLE]' ), "{$name} instructions handle analytics unavailable" );
	blzmcp_it_assert( null !== $totals_step && str_contains( $totals_step['why'], '[ANALYTICS_UNAVAILABLE]' ), "{$name} totals step why keys on the analytics token" );
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
	// Card hours use the shared rolling-window mapping, capped at 168 (1.3 item 7).
	$card_hours = static fn( $r ) => ( (array) ( $r['steps'][ (int) $index_of( $r, $card ) ]['arguments'] ?? array() ) )['since_hours'] ?? null;
	$yday       = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute( array( 'period' => 'yesterday' ) );
	blzmcp_it_equals( array( 24, 48, 168 ), array( $card_hours( $today ), $card_hours( $yday ), $card_hours( $year ) ), 'card testing hours: today 24, yesterday 48 (rolling), this year capped at 168' );
	blzmcp_it_assert( str_contains( (string) ( $yday['instructions'] ?? '' ), 'The card-testing step looks back over the last 48 hours from now' ) && str_contains( (string) ( $yday['instructions'] ?? '' ), 'for yesterday it covers the last 48 hours, not yesterday alone' ), 'yesterday: a rolling 48-hour observation, not a reconstruction of yesterday' );
	blzmcp_it_equals( 1, ( (array) $step_for( $today, $history )['arguments'] )['days'] ?? null, 'today maps to 1 day of history' );
}

// Analytics unavailable: the first step's error text reaches the AI through the gateway with the token.
if ( null !== $gateway && isset( $samples['blaze-mcp-toolkit/weekly-store-review'] ) ) {
	$saved_enabled = get_option( 'woocommerce_analytics_enabled', 'yes' );
	update_option( 'woocommerce_analytics_enabled', 'no' );
	// Selected by ability and subject, not position: payment health is now the first step (F02).
	$first = $step_for( $samples['blaze-mcp-toolkit/weekly-store-review'], 'wc-analytics/totals', 'orders' );
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
	$off_text = is_array( $off ) ? $off['instructions'] : '';
	blzmcp_it_assert( is_array( $off ) && 'blaze-mcp-toolkit/payment-health' === ( $off['steps'][0]['ability'] ?? null ), 'analytics off: payment health still runs first' );
	blzmcp_it_equals( 1, $index_of( $off, $card ), 'analytics off: card testing still runs second' );
	blzmcp_it_assert( null !== $step_for( $off, $health ) && null !== $step_for( $off, $history ), 'analytics off: site error and update steps still run' );
	blzmcp_it_assert( str_contains( $off_text, 'STOP THE ANALYTICS WORK' ) && str_contains( $off_text, 'Still run the steps below' ) && str_contains( $off_text, 'the card-testing result and its protection advice included' ) && str_contains( $off_text, 'likely or possible card-testing findings first, then payment health findings' ), 'analytics off: only the Analytics work stops; card evidence, protection advice and payment findings still reported' );
	blzmcp_it_assert( str_contains( $off_text, "CARD TESTING CHECK\n" ) && str_contains( $off_text, 'Then run the second step alone: it is the card-testing check. Whatever it returns, carry on: run the remaining' ), 'analytics off: the card-testing guidance and run order survive' );
	blzmcp_it_assert( str_contains( $off_text, 'No WooCommerce Analytics step is in this plan' ) && str_contains( $off_text, 'Whatever it returns, carry on: run the remaining' ), 'analytics off: run order and analytics check built from the surviving steps' );
	blzmcp_it_equals( array(), $contradictions( $off_text . "\n" . $whys_of( $off ) ), 'analytics off: no contradictory stop clause' );
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
	blzmcp_it_assert( str_contains( $failed['instructions'], 'only the card-testing check says whether failed orders look like card testing' ) && str_contains( $failed['instructions'], 'Never decide it yourself from the failed and paid order counts' ), 'failed-order instructions defer card testing to the card-testing check, never failed > paid' );
	$fdesc = wp_get_ability( 'blaze-mcp-toolkit/failed-order-triage' )->get_description();
	blzmcp_it_assert( ! str_contains( $fdesc, 'possible card testing' ) && str_contains( $fdesc, 'starting with a payment health check and then a card-testing check' ), 'failed-order discovery description: no card-testing claim of its own, names the card-testing step' );
	blzmcp_it_assert( ! str_contains( $failed['instructions'], 'except for the card-testing check' ) && str_contains( $failed['instructions'], 'treat the current paid-order count as background only)' ), 'failed-order required data: paid-order count is background only, no card-testing exception' );
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

// Payment-first wording in each workflow's own guidance (F17).
if ( is_array( $weekly ) ) {
	blzmcp_it_assert( str_contains( $weekly['instructions'], 'If the card-testing check returned a likely or possible result, open with it. If payment health returned red or amber findings, report them next. Then cover collected revenue' ), 'weekly headline: card testing, then payment findings, then revenue' );
	blzmcp_it_assert( str_contains( $weekly['instructions'], 'Never describe card testing as the reason for a dip' ), 'weekly: no invented causal link from card testing to a dip' );
}
if ( is_array( $drop ) ) {
	blzmcp_it_assert( str_contains( $drop['instructions'], 'After any card-testing and payment findings, lead with collected revenue' ) && str_contains( $drop['instructions'], 'possible link, not a proven cause' ), 'revenue drop: card-testing and payment findings first, cautious link' );
	blzmcp_it_assert( str_contains( $drop['instructions'], 'Never call card testing a cause of the drop' ), 'revenue drop: no invented causal link from card testing' );
}
if ( is_array( $failed ) ) {
	$ft = $failed['instructions'];
	blzmcp_it_assert( str_contains( $ft, 'Open with a likely or possible card-testing result, if the card-testing check returned one; then any red or amber payment health findings.' ), 'failed orders: card testing leads the Snapshot, then payment findings' );
	blzmcp_it_assert( str_contains( $ft, 'make its check_next and the check_next of each checkout path whose protection is not_configured or unknown part of Next Actions' ), 'failed orders: protection gaps reach Next Actions' );
	blzmcp_it_assert( str_contains( $ft, 'some legitimately hold orders while a payment is authorised and captured later' ), 'failed orders: authorisation holds are not called a fault' );
	blzmcp_it_assert( null === $step_for( $failed, $health ), 'failed orders: still no health-summary step' );
}
if ( is_array( $refund ) ) {
	blzmcp_it_assert( str_contains( $refund['instructions'], 'Run the first step alone. If it succeeds, run the remaining' ), 'refund: totals-first healthy path kept' );
}

// Payment step denied or totals denied: instructions follow the surviving steps.
// The denial goes through the real capability check, not a core filter: `wp_ability_permission_result`
// only exists from WordPress 7.1 (class-wp-ability.php, "@since 7.1.0"), so on 6.9 it never fired and
// nothing was denied. `user_has_cap` exists on every supported version. It removes manage_woocommerce
// only while core's WP_Ability::check_permissions() runs for the target ability, so that ability's own
// permission_callback (current_user_can) returns false for this real user and nothing else changes.
$deny = static function ( $allcaps, $caps ) use ( &$deny_target ) {
	if ( '' === $deny_target || ! in_array( 'manage_woocommerce', (array) $caps, true ) ) {
		return $allcaps;
	}
	foreach ( debug_backtrace() as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace, PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection -- only frame objects are read.
		if ( 'check_permissions' === ( $frame['function'] ?? '' ) && ( $frame['object'] ?? null ) instanceof WP_Ability && $frame['object']->get_name() === $deny_target ) {
			$allcaps['manage_woocommerce'] = false;
			break;
		}
	}
	return $allcaps;
};
add_filter( 'user_has_cap', $deny, 10, 2 );
$deny_target = $payment;
blzmcp_it_assert( true !== wp_get_ability( $payment )->check_permissions( array() ) && current_user_can( 'manage_woocommerce' ), 'denial mechanism: payment health is denied for this user while the workflow cap still holds' );
$no_pay      = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute( array( 'period' => 'last_7_days' ) );
$deny_target = 'wc-analytics/totals';
blzmcp_it_assert( true !== wp_get_ability( 'wc-analytics/totals' )->check_permissions( array() ) && true === wp_get_ability( $payment )->check_permissions( array() ), 'denial mechanism: totals is denied, payment health is not' );
$no_totals   = wp_get_ability( 'blaze-mcp-toolkit/revenue-drop-triage' )->execute( array( 'period' => 'last_7_days' ) );
$deny_target = '';
remove_filter( 'user_has_cap', $deny, 10 );
$np = is_array( $no_pay ) ? $no_pay['instructions'] : '';
blzmcp_it_assert( null === $step_for( $no_pay, $payment ) && ( $no_pay['steps'][0]['ability'] ?? null ) === $card && 1 === $index_of( $no_pay, 'wc-analytics/totals', 'orders' ), 'payment denied: card testing becomes the first step, totals the second' );
blzmcp_it_assert( str_contains( $np, 'NOT AVAILABLE FOR YOUR ROLE' ) && str_contains( $np, $payment ), 'payment denied: listed under not available for your role' );
blzmcp_it_assert( str_contains( $np, 'The payment health check is not in this plan' ) && ! str_contains( $np, 'lead the answer with its findings' ), 'payment denied: explained once, review continues' );
blzmcp_it_assert( ! str_contains( $np, 'Green never covers a method whose assessment is inventory_only' ), 'payment denied: no inventory guidance for a step that will not run' );
blzmcp_it_assert( str_contains( $np, 'Run the first step alone: it is the card-testing check. Whatever it returns, carry on. Then run the order totals step alone.' ) && ! str_contains( $np, 'it is the payment health check' ), 'payment denied: run order names no payment step, card testing first' );
$nt = is_array( $no_totals ) ? $no_totals['instructions'] : '';
blzmcp_it_assert( null === $step_for( $no_totals, 'wc-analytics/totals', 'orders' ) && 'blaze-mcp-toolkit/payment-health' === ( $no_totals['steps'][0]['ability'] ?? null ), 'totals denied: payment health still first' );
blzmcp_it_assert( str_contains( $nt, 'Whatever it returns, carry on: run the remaining' ) && ! str_contains( $nt, 'Then run the order totals step alone' ), 'totals denied: run order names no totals step' );
blzmcp_it_assert( ! str_contains( $nt, 'The order totals step also confirms' ) && str_contains( $nt, 'stop the Analytics work only' ), 'totals denied: the remaining Analytics steps keep the Analytics-only stop' );
blzmcp_it_equals( array(), $contradictions( $np . "\n" . $nt ), 'denied steps: no contradictory stop clause' );

// 1.3 item 7: the card-testing step denied, alone and with payment health. Explained once, the
// review continues, and nothing falls back to judging card testing from failed and paid counts.
add_filter( 'user_has_cap', $deny, 10, 2 );
$deny_target = $card;
blzmcp_it_assert( true !== wp_get_ability( $card )->check_permissions( array() ) && true === wp_get_ability( $payment )->check_permissions( array() ), 'denial mechanism: card testing is denied, payment health is not' );
$no_card = array();
foreach ( $payment_first as $wf ) {
	$no_card[ $wf ] = wp_get_ability( $wf )->execute( array( 'period' => 'last_7_days' ) );
}
$deny_both = static function ( $allcaps, $caps ) use ( $payment, $card ) {
	if ( ! in_array( 'manage_woocommerce', (array) $caps, true ) ) {
		return $allcaps;
	}
	foreach ( debug_backtrace() as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace, PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection -- only frame objects are read.
		if ( 'check_permissions' === ( $frame['function'] ?? '' ) && ( $frame['object'] ?? null ) instanceof WP_Ability && in_array( $frame['object']->get_name(), array( $payment, $card ), true ) ) {
			$allcaps['manage_woocommerce'] = false;
			break;
		}
	}
	return $allcaps;
};
$deny_target = '';
remove_filter( 'user_has_cap', $deny, 10 );
add_filter( 'user_has_cap', $deny_both, 10, 2 );
$no_diag = wp_get_ability( 'blaze-mcp-toolkit/failed-order-triage' )->execute( array( 'period' => 'last_7_days' ) );
remove_filter( 'user_has_cap', $deny_both, 10 );
foreach ( $no_card as $wf => $r ) {
	$t = is_array( $r ) ? $r['instructions'] : '';
	blzmcp_it_assert( null === $index_of( $r, $card ) && 0 === $index_of( $r, $payment ) && 1 === $index_of( $r, 'wc-analytics/totals', 'orders' ), "card denied {$wf}: payment health first, totals second" );
	blzmcp_it_assert( str_contains( $t, 'The card-testing check is not in this plan' ) && 1 === substr_count( $t, 'card testing was not checked' ) && str_contains( $t, 'Never judge card testing from failed and paid order counts, or from payment health\'s inventory_only methods or orders outside verified checkout, instead.' ), "card denied {$wf}: explained once, no failed > paid or inventory fallback" );
	blzmcp_it_assert( str_contains( $t, 'NOT AVAILABLE FOR YOUR ROLE' ) && str_contains( $t, $card ), "card denied {$wf}: listed under not available for your role" );
	blzmcp_it_assert( str_contains( $t, 'Run the first step alone: it is the payment health check. Whatever it returns, carry on. Then run the order totals step alone.' ) && ! str_contains( $t, 'it is the card-testing check' ), "card denied {$wf}: run order names no card step" );
	blzmcp_it_assert( str_contains( $t, 'lead the answer with its findings' ) && ! str_contains( $t, 'If its verdict is likely_attack' ), "card denied {$wf}: payment findings lead, no guidance for a card step that will not run" );
	blzmcp_it_equals( array(), $contradictions( $t ), "card denied {$wf}: no contradictory or failed > paid clause" );
}
$nd = is_array( $no_diag ) ? $no_diag['instructions'] : '';
blzmcp_it_assert( 'wc-analytics/totals' === ( $no_diag['steps'][0]['ability'] ?? null ) && str_contains( $nd, 'Run the first step alone. If it succeeds' ), 'payment and card denied: totals first, the totals-first run order' );
blzmcp_it_assert( str_contains( $nd, 'The card-testing check is not in this plan' ) && str_contains( $nd, 'The payment health check is not in this plan' ), 'payment and card denied: each explained once' );
blzmcp_it_equals( array(), $contradictions( $nd ), 'payment and card denied: no contradictory or failed > paid clause' );

// A card-testing failure after the plan was built: the plan already tells the assistant to say so
// once and carry on, and the step's own error is the generic one (no store data).
$plan       = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute( array( 'period' => 'last_7_days' ) );
$card_step  = $plan['steps'][ (int) $index_of( $plan, $card ) ] ?? array();
$tz_breaker = static function () {
	throw new \RuntimeException( 'workflow card failure' );
};
add_filter( 'pre_option_timezone_string', $tz_breaker );
$card_out = wp_get_ability( $card )->execute( json_decode( (string) wp_json_encode( $card_step['arguments'] ?? array() ), true ) );
remove_filter( 'pre_option_timezone_string', $tz_breaker );
blzmcp_it_assert( is_wp_error( $card_out ) && 'blzmcp_ability_failed' === $card_out->get_error_code(), 'card step failing after plan generation returns the generic error' );
blzmcp_it_assert( str_contains( (string) ( $plan['instructions'] ?? '' ), 'If the step returns an error, mention once that the card-testing check could not run, and carry on with the rest of the review.' ), 'the plan handles a card-step error once and continues' );

// Wave 2 (REVIEW-pass4 item 7b): with Analytics unavailable, the stop text names only the diagnostic
// steps that survived. Refund triage (no payment or card step) and a plan whose card step was left out
// carry no card-testing text; the full plan keeps the card-first order.
$an_saved = get_option( 'woocommerce_analytics_enabled', 'yes' );
update_option( 'woocommerce_analytics_enabled', 'no' );
\Blaze\McpToolkit\Bundled\AnalyticsGuard::clear_cache();
$an_refund = wp_get_ability( 'blaze-mcp-toolkit/refund-triage' )->execute();
$an_full   = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute();
add_filter( 'user_has_cap', $deny, 10, 2 );
$deny_target = $card;
$an_no_card  = wp_get_ability( 'blaze-mcp-toolkit/weekly-store-review' )->execute();
$deny_target = $payment;
$an_no_pay   = wp_get_ability( 'blaze-mcp-toolkit/failed-order-triage' )->execute();
$deny_target = '';
remove_filter( 'user_has_cap', $deny, 10 );
update_option( 'woocommerce_analytics_enabled', $an_saved );
\Blaze\McpToolkit\Bundled\AnalyticsGuard::clear_cache();
$an_text = static fn( $r ): string => is_array( $r ) ? (string) $r['instructions'] : '';
$t_ref   = $an_text( $an_refund );
blzmcp_it_assert( str_starts_with( $t_ref, '[ANALYTICS_UNAVAILABLE] ' ) && str_contains( $t_ref, 'STOP THE ANALYTICS WORK' ), 'analytics off, refund: the Analytics-only stop applies' );
blzmcp_it_assert( ! str_contains( $t_ref, 'card-testing' ) && ! str_contains( $t_ref, 'card testing' ) && ! str_contains( $t_ref, 'payment health findings first' ), 'analytics off, refund: no card-testing or payment-health text for steps it does not have' );
blzmcp_it_assert( str_contains( $t_ref, 'Still run the steps below, if any, and report what they return; stop the review only if no step remains.' ), 'analytics off, refund: the plain report sentence' );
$t_nc = $an_text( $an_no_card );
blzmcp_it_assert( null === $index_of( $an_no_card, $card ) && 0 === $index_of( $an_no_card, $payment ), 'analytics off, card omitted: payment health is the only diagnostic step' );
blzmcp_it_assert( str_contains( $t_nc, 'report what they return, payment health findings first; stop the review only if no step remains.' ) && ! str_contains( $t_nc, 'the card-testing result and its protection advice included' ) && ! str_contains( $t_nc, 'card-testing findings first' ), 'analytics off, card omitted: the stop text names payment health only, no card findings or protection advice' );
blzmcp_it_assert( str_contains( $t_nc, 'The card-testing check is not in this plan' ) && ! str_contains( $t_nc, 'If its verdict is likely_attack' ), 'analytics off, card omitted: explained once by the skipped-card paragraph, no guidance for a step that will not run' );
$t_np = $an_text( $an_no_pay );
blzmcp_it_assert( 0 === $index_of( $an_no_pay, $card ) && str_contains( $t_np, '(the card-testing result and its protection advice included), likely or possible card-testing findings first; stop the review only if no step remains.' ) && ! str_contains( $t_np, 'then payment health findings' ), 'analytics off, payment omitted: the stop text names the card step only' );
$t_full = $an_text( $an_full );
blzmcp_it_assert( str_contains( $t_full, '(the card-testing result and its protection advice included), likely or possible card-testing findings first, then payment health findings; stop the review only if no step remains.' ), 'analytics off, full plan: card findings first, then payment health findings' );
foreach ( array( 'refund' => $t_ref, 'card omitted' => $t_nc, 'payment omitted' => $t_np, 'full' => $t_full ) as $an_label => $an_t ) {
	blzmcp_it_equals( array(), $contradictions( $an_t ), "analytics off, {$an_label}: no contradictory or failed > paid clause" );
}
blzmcp_it_equals( array(), $direction_problems( $t_full ), 'analytics off, full plan: the card and inventory directions agree' );

// Payment step permission follows the user at plan time (cold and warm): never cached.
$deny_target = $payment;
add_filter( 'user_has_cap', $deny, 10, 2 );
$cold_deny = wp_get_ability( 'blaze-mcp-toolkit/failed-order-triage' )->execute();
remove_filter( 'user_has_cap', $deny, 10 );
$deny_target = '';
$warm_ok = wp_get_ability( 'blaze-mcp-toolkit/failed-order-triage' )->execute();
blzmcp_it_assert( null === $step_for( $cold_deny, $payment ) && null !== $step_for( $warm_ok, $payment ), 'payment step appears or disappears with the current permission' );

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
	if ( in_array( $name, $payment_first, true ) ) {
		blzmcp_it_assert( 'blaze-mcp-toolkit/payment-health' === ( $sm['steps'][0]['ability'] ?? null ), "{$name} as shop_manager keeps payment health first (manage_woocommerce)" );
		blzmcp_it_equals( 1, $index_of( $sm, $card ), "{$name} as shop_manager keeps card testing second (manage_woocommerce)" );
	}
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

// Paired missing-origin card / payment-inventory case under the recommended connector (wave 2).
$paired_case( 'recommended' );

blzmcp_it_finish();
