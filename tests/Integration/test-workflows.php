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
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Settings;

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

// Requested-period analytics check (audit F02), in both modes: a paid order 5 days ago missing from
// wc_order_stats stops a last_7_days review but not one for yesterday.
$wf_mode    = Commerce::is_standing_aside() ? 'standaside' : 'bundled';
$wf_product = new WC_Product_Simple();
$wf_product->set_name( 'Blzmcp workflow gap product' );
$wf_product->set_regular_price( '10' );
$wf_product->save();
$wf_order = wc_create_order();
$wf_order->add_product( $wf_product, 1 );
$wf_order->calculate_totals();
$wf_order->payment_complete();
$wf_order->set_date_created( time() - 5 * DAY_IN_SECONDS );
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
