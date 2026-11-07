<?php
/**
 * Connector availability and diagnosis integration test (read-only: no option may change).
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-connector-availability.php
 * Easy MCP stub (no Tool_State): add --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp.php before eval-file.
 * Easy MCP stub with Tool_State: also add --require=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/stub-easymcp-toolstate.php.
 * Denylist mode: env BLZMCP_EWPA_DENYLIST=1, with blaze-mcp-toolkit/health-summary already in
 * ewpa_thirdparty_disabled (scripts/run-integration.sh sets it with `wp option update` in an
 * earlier step and restores it afterwards), so Enable Abilities' own unregister hook has run.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Connectors\Availability;
use Blaze\McpToolkit\Connectors\Connectors;

blzmcp_it_assert( class_exists( Connectors::class ), 'Connectors class exists' );
if ( ! class_exists( Connectors::class ) || ! function_exists( 'wp_get_abilities' ) ) {
	blzmcp_it_finish();
}

const BLZMCP_AV_SLUG = 'blaze-mcp-toolkit/health-summary';

$rec       = Connectors::get( 'recommended' );
$easy      = Connectors::get( 'easy-mcp' );
$easy_stub = defined( 'EASY_MCP_AI_VERSION' );
$toolstate = class_exists( '\Easy_MCP_AI\Tools\Tool_State' );
$denylist  = '1' === getenv( 'BLZMCP_EWPA_DENYLIST' );

/**
 * Every easy_mcp_ai_% and ewpa_% option row, straight from the table.
 *
 * @return array<int,array<string,string>>
 */
function blzmcp_av_snapshot(): array {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, option_value, autoload FROM %i WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',
			$wpdb->options,
			$wpdb->esc_like( 'easy_mcp_ai_' ) . '%',
			$wpdb->esc_like( 'ewpa_' ) . '%'
		),
		ARRAY_A
	);
}

/**
 * Assert the option rows equal an earlier snapshot; on a mismatch, name the rows that differ.
 *
 * @param array<int,array<string,string>> $before Earlier snapshot.
 * @param string                          $label  Label.
 */
function blzmcp_av_unchanged( array $before, string $label ): void {
	$after = blzmcp_av_snapshot();
	if ( $before === $after ) {
		blzmcp_it_assert( true, $label );
		return;
	}
	$key     = static fn( array $rows ) => array_combine( array_column( $rows, 'option_name' ), array_map( 'serialize', $rows ) );
	$b       = $key( $before );
	$a       = $key( $after );
	$changed = array_keys( array_diff_assoc( $a, $b ) + array_diff_assoc( $b, $a ) );
	blzmcp_it_assert( false, $label . ' (changed: ' . implode( ', ', $changed ) . ')' );
}

// Initialise the registry (and Enable Abilities' own snapshot write) before any snapshot.
$blzmcp_av_names = array();
foreach ( wp_get_abilities() as $a ) {
	$blzmcp_av_names[] = $a->get_name();
}

if ( $denylist ) {
	$disabled = get_option( 'ewpa_thirdparty_disabled', array() );
	blzmcp_it_assert( is_array( $disabled ) && in_array( BLZMCP_AV_SLUG, $disabled, true ), 'denylist: option was set by the runner' );
	blzmcp_it_assert( ! wp_has_ability( BLZMCP_AV_SLUG ), 'denylist: Enable Abilities unregistered the ability at wp_abilities_api_init' );
	$before = blzmcp_av_snapshot();
	$diag   = $rec->diagnose( BLZMCP_AV_SLUG );
	blzmcp_av_unchanged( $before, 'denylist: options unchanged by diagnose' );
	blzmcp_it_assert( $diag instanceof Availability, 'denylist: recommended diagnosis returned' );
	if ( $diag instanceof Availability ) {
		blzmcp_it_equals( Availability::REMOVED_GLOBALLY, $diag->kind, 'denylist: kind' );
		blzmcp_it_equals( 'recommended', $diag->connector_id, 'denylist: connector id' );
		blzmcp_it_equals( 'switched off in Enable Abilities for MCP', $diag->reason, 'denylist: reason' );
		blzmcp_it_equals( $rec->settings_url(), $diag->settings_url, 'denylist: settings url' );
	}
	blzmcp_it_finish();
	return;
}

blzmcp_it_assert( wp_has_ability( BLZMCP_AV_SLUG ), 'health-summary is registered' );
$other = null;
foreach ( $blzmcp_av_names as $n ) {
	if ( BLZMCP_AV_SLUG !== $n && str_starts_with( $n, 'blaze-mcp-toolkit/' ) ) {
		$other = $n;
		break;
	}
}
blzmcp_it_assert( null !== $other, 'a second toolkit ability exists' );

// Tick health-summary only in Easy MCP's ability list (test setup; restored in cleanup).
$had_enabled  = false !== get_option( 'easy_mcp_ai_enabled_abilities', false );
$prev_enabled = get_option( 'easy_mcp_ai_enabled_abilities' );
update_option( 'easy_mcp_ai_enabled_abilities', array( BLZMCP_AV_SLUG ) );
blzmcp_it_cleanup(
	static function () use ( $had_enabled, $prev_enabled ): void {
		if ( $had_enabled ) {
			update_option( 'easy_mcp_ai_enabled_abilities', $prev_enabled );
		} else {
			delete_option( 'easy_mcp_ai_enabled_abilities' );
		}
	}
);
blzmcp_it_cleanup(
	static function (): void {
		unset( $GLOBALS['blzmcp_toolstate_return'] );
	}
);

/**
 * Call is_exposed and diagnose on both connectors for 20 slugs (registered ones, then made-up ones).
 *
 * @param array<int,string> $blzmcp_av_names Registered ability names.
 */
function blzmcp_av_sweep( array $blzmcp_av_names ): int {
	$slugs = array_slice( $blzmcp_av_names, 0, 18 );
	for ( $i = count( $slugs ); $i < 20; $i++ ) {
		$slugs[] = 'blzmcp-test/not-registered-' . $i;
	}
	$calls = 0;
	foreach ( Connectors::all() as $connector ) {
		foreach ( $slugs as $slug ) {
			$ability = wp_has_ability( $slug ) ? wp_get_ability( $slug ) : null;
			if ( $ability instanceof \WP_Ability ) {
				$connector->is_exposed( $ability );
				++$calls;
			}
			$connector->diagnose( $slug );
			++$calls;
		}
	}
	return $calls;
}

$health = wp_get_ability( BLZMCP_AV_SLUG );
$second = null === $other ? null : wp_get_ability( $other );

// Recommended: exposure follows MCP Adapter, diagnosis is clean without a denylist entry.
$before = blzmcp_av_snapshot();
blzmcp_it_assert( blzmcp_av_sweep( $blzmcp_av_names ) >= 40, 'sweep: 20 slugs x 2 connectors' );
$blzmcp_av_mismatch = 0;
foreach ( wp_get_abilities() as $a ) {
	$expected = class_exists( '\WP\MCP\Abilities\McpAbilityExposure' )
		? \WP\MCP\Abilities\McpAbilityExposure::is_public( $a )
		: true === ( $a->get_meta()['mcp']['public'] ?? null );
	if ( $expected !== $rec->is_exposed( $a ) ) {
		++$blzmcp_av_mismatch;
		blzmcp_it_equals( $expected, $rec->is_exposed( $a ), 'recommended is_exposed matches MCP Adapter for ' . $a->get_name() );
	}
}
blzmcp_it_equals( 0, $blzmcp_av_mismatch, 'recommended is_exposed matches MCP Adapter for every registered ability' );
blzmcp_it_equals( true, $rec->is_exposed( $health ), 'recommended: health-summary exposed' );
blzmcp_it_equals( null, $rec->diagnose( BLZMCP_AV_SLUG ), 'recommended: no diagnosis without a denylist entry' );
blzmcp_av_unchanged( $before, 'recommended sweep: options unchanged' );

/**
 * Assert one Easy MCP outcome, with the options snapshot around both calls.
 *
 * @param \WP_Ability $ability       Ability.
 * @param bool|null   $exposed       Expected is_exposed().
 * @param string|null $kind          Expected diagnosis kind, or null for none.
 * @param string      $label         Label.
 * @param string|null $reason_prefix Expected start of the reason.
 */
function blzmcp_av_easy( \WP_Ability $ability, ?bool $exposed, ?string $kind, string $label, ?string $reason_prefix = null ): void {
	$easy   = Connectors::get( 'easy-mcp' );
	$before = blzmcp_av_snapshot();
	try {
		$got_exposed = $easy->is_exposed( $ability );
		$diag        = $easy->diagnose( $ability->get_name() );
	} catch ( \Throwable $e ) {
		blzmcp_it_assert( false, "{$label}: no exception (got " . get_class( $e ) . ': ' . $e->getMessage() . ')' );
		return;
	}
	blzmcp_it_equals( $exposed, $got_exposed, "{$label}: is_exposed" );
	blzmcp_it_equals( $kind, $diag?->kind, "{$label}: diagnosis kind" );
	if ( null !== $diag ) {
		blzmcp_it_equals( 'easy-mcp', $diag->connector_id, "{$label}: connector id" );
		blzmcp_it_equals( $easy->settings_url(), $diag->settings_url, "{$label}: settings url" );
		if ( null !== $reason_prefix ) {
			blzmcp_it_assert( str_starts_with( $diag->reason, $reason_prefix ), "{$label}: reason starts '{$reason_prefix}' (got '{$diag->reason}')" );
		}
	}
	blzmcp_av_unchanged( $before, "{$label}: options unchanged" );
}

// Not ticked: off whatever Tool_State says.
if ( null !== $second ) {
	blzmcp_av_easy( $second, false, Availability::DISABLED_HERE, 'easy: not ticked', 'not ticked in Easy MCP AI › Tools › Abilities' );
}

if ( ! $toolstate ) {
	// No Tool_State class (base stub, or no Easy MCP at all): effective state unknown.
	blzmcp_av_easy( $health, null, Availability::UNKNOWN, 'easy: ticked, no Tool_State' );
} else {
	$GLOBALS['blzmcp_toolstate_calls']      = array();
	$GLOBALS['blzmcp_toolstate_is_enabled'] = 0;

	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	blzmcp_av_easy( $health, true, null, 'easy: ticked, resolve [true, default]' );
	blzmcp_it_equals( array( 'wp_ability_blaze_mcp_toolkit_health_summary', false ), $GLOBALS['blzmcp_toolstate_calls'][0] ?? null, 'easy: resolve() gets the tool name and default_off false' );

	$GLOBALS['blzmcp_toolstate_return'] = array( false, 'disabled' );
	blzmcp_av_easy( $health, false, Availability::DISABLED_HERE, 'easy: ticked, resolve [false, disabled]', 'switched off in Easy MCP AI' );
	$d = Connectors::get( 'easy-mcp' )->diagnose( BLZMCP_AV_SLUG );
	blzmcp_it_assert( null !== $d && str_contains( $d->reason, 'disabled' ), 'easy: switched-off reason carries resolve()\'s reason' );

	foreach (
		array(
			'string yes'          => 'yes',
			'empty array'         => array(),
			'array with string 1' => array( '1' ),
			'throws'              => 'throw:ArgumentCountError',
		) as $case => $value
	) {
		$GLOBALS['blzmcp_toolstate_return'] = $value;
		blzmcp_av_easy( $health, null, Availability::UNKNOWN, "easy: ticked, resolve {$case}" );
	}

	blzmcp_it_equals( 0, $GLOBALS['blzmcp_toolstate_is_enabled'], 'easy: Tool_State::is_enabled() never called' );
	unset( $GLOBALS['blzmcp_toolstate_return'] );

	// Full sweep under the stub too.
	$before = blzmcp_av_snapshot();
	blzmcp_av_sweep( $blzmcp_av_names );
	blzmcp_av_unchanged( $before, 'easy sweep: options unchanged' );
}

if ( ! $toolstate ) {
	$before = blzmcp_av_snapshot();
	blzmcp_av_sweep( $blzmcp_av_names );
	blzmcp_av_unchanged( $before, 'sweep without Tool_State: options unchanged' );
}

blzmcp_it_finish();
