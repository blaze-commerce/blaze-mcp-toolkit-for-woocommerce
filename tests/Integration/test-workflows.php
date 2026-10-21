<?php
/**
 * Workflow tools integration test.
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-workflows.php
 *
 * Set BLZMCP_SAMPLE=1 to print one admin sample per workflow. Run in normal (bundled) mode: with the
 * stand-aside stub no wc-analytics/* abilities exist, so the analytics-step assertions do not apply.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB

require_once __DIR__ . '/assert.php';

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
	update_option( 'woocommerce_analytics_enabled', $saved_enabled );
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
