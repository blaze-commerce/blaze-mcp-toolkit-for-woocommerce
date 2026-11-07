<?php
/**
 * Setup checks, setup screen save and render integration test. State-aware: the rows depend on
 * which connection plugin is active (Connectors::state()).
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-setup-checks.php
 * Modes (scripts/run-integration.sh runs each):
 * - default wp-env: the recommended state; compared with tests/fixtures/setup-checks-1.0.json.
 * - Easy MCP stub (`both`): add --require=…/tests/Integration/stub-easymcp.php before eval-file;
 *   also add --require=…/tests/Integration/stub-easymcp-toolstate.php for a Tool_State whose
 *   resolve() answers from a test global.
 * - Denylist: env BLZMCP_EWPA_DENYLIST=1 with blaze-mcp-toolkit/health-summary already in
 *   ewpa_thirdparty_disabled (set by the runner), so Enable Abilities has unregistered it.
 * - None: env BLZMCP_STATE_NONE=1 with Enable Abilities for MCP deactivated by the runner.
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

require_once __DIR__ . '/assert.php';
define( 'BLZMCP_FX_LIBRARY', true );
require_once dirname( __DIR__ ) . '/fixtures/capture-setup-checks.php';

use Blaze\McpToolkit\Abilities\AnalyticsNaming;
use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Admin\SetupChecks;
use Blaze\McpToolkit\Admin\SetupScreen;
use Blaze\McpToolkit\Connectors\Connectors;
use Blaze\McpToolkit\Settings;

const BLZMCP_SC_SLUG = 'blaze-mcp-toolkit/health-summary';

$state     = Connectors::state();
$toolstate = class_exists( '\Easy_MCP_AI\Tools\Tool_State' );
// Real Easy MCP AI (tests/matrix): its own Tool_State answers from its options, not a test global.
$real_toolstate = $toolstate && ! str_contains( (string) ( new ReflectionClass( '\Easy_MCP_AI\Tools\Tool_State' ) )->getFileName(), 'stub-easymcp-toolstate' );
$denylist  = '1' === getenv( 'BLZMCP_EWPA_DENYLIST' );
$none_mode = '1' === getenv( 'BLZMCP_STATE_NONE' );
$easy      = in_array( $state, array( 'easy-mcp', 'both' ), true );

$sc_order = array(
	'recommended' => array( 'connection', 'woocommerce', 'mcp_adapter', 'enable_abilities', 'oauth_on', 'chatgpt_connector', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode', 'pii_tools', 'write_tools', 'edge_guidance' ),
	'easy'        => array( 'connection', 'woocommerce', 'easy_tools_on', 'easy_signin_access', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode', 'easy_rest_read', 'easy_write_tools', 'easy_woo_group', 'edge_guidance' ),
	'none'        => array( 'connection', 'woocommerce', 'tools_visible', 'wcfc_standaside', 'analytics_import', 'hpos_mode' ),
);

/**
 * Every easy_mcp_ai_% and ewpa_% option row, straight from the table.
 *
 * @return array<int,array<string,string>>
 */
function blzmcp_sc_snapshot(): array {
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
 * Run SetupChecks::run() and assert no easy_mcp_ai_% or ewpa_% option changed while it ran.
 *
 * @param string $label What this run is.
 * @return array<string, array{id: string, status: string, label: string, fix: string}>
 */
function blzmcp_sc_run( string $label ): array {
	$before = blzmcp_sc_snapshot();
	$rows   = SetupChecks::run();
	blzmcp_it_assert( blzmcp_sc_snapshot() === $before, "{$label}: run() leaves easy_mcp_ai_% and ewpa_% options unchanged" );
	return array_column( $rows, null, 'id' );
}

/**
 * Set an option for this test only; the old value (or its absence) comes back at cleanup.
 *
 * @param string $name  Option.
 * @param mixed  $value Value.
 */
function blzmcp_sc_set( string $name, $value ): void {
	static $saved = array();
	if ( ! array_key_exists( $name, $saved ) ) {
		$saved[ $name ] = get_option( $name, null );
		$old            = $saved[ $name ];
		blzmcp_it_cleanup(
			static function () use ( $name, $old ): void {
				if ( null === $old ) {
					delete_option( $name );
				} else {
					update_option( $name, $old );
				}
			}
		);
	}
	update_option( $name, $value );
}

// Initialise the registry (and Enable Abilities' own snapshot write) before any snapshot.
$all_names = array_map( static fn( $a ) => $a->get_name(), function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() );

// Shape: connection first, each id once, valid fields, the state's order.
$rows = SetupChecks::run();
$ids  = array_column( $rows, 'id' );
blzmcp_it_equals( 'connection', $ids[0] ?? null, "{$state}: connection row comes first" );
blzmcp_it_equals( count( $ids ), count( array_unique( $ids ) ), "{$state}: each row id appears once" );
foreach ( $rows as $row ) {
	blzmcp_it_assert( in_array( $row['status'], array( 'pass', 'warn', 'fail' ), true ) && is_string( $row['label'] ) && '' !== $row['label'] && is_string( $row['fix'] ), "{$state}: {$row['id']} has status, label and fix" );
}
$expected_ids = $sc_order[ $easy ? 'easy' : $state ];
blzmcp_it_equals( $expected_ids, $ids, "{$state}: row ids in the binding order" );
blzmcp_it_assert( ! str_contains( strtolower( wp_json_encode( $rows ) ), 'developer mode' ), "{$state}: no developer mode claim" );
$by = array_column( $rows, null, 'id' );
blzmcp_it_equals( 'pass', $by['woocommerce']['status'] ?? null, "{$state}: woocommerce passes in wp-env" );
blzmcp_it_equals( 'pass', $by['hpos_mode']['status'] ?? null, "{$state}: hpos_mode is info only (pass)" );

// ---------------------------------------------------------------------------------------------
// None: no connection plugin.
if ( $none_mode ) {
	blzmcp_it_equals( 'none', $state, 'none: Enable Abilities for MCP is deactivated and no Easy MCP AI' );
	blzmcp_it_equals( 'fail', $by['connection']['status'] ?? null, 'none: connection fails' );
	blzmcp_it_assert( str_contains( $by['connection']['fix'] ?? '', 'Enable Abilities for MCP' ), 'none: connection fix names Enable Abilities for MCP' );
	blzmcp_it_assert( str_contains( $by['connection']['fix'] ?? '', 'Easy MCP AI' ), 'none: connection fix says Easy MCP AI also works' );
	blzmcp_it_equals( 'pass', $by['tools_visible']['status'] ?? null, 'none: our tools are registered' );
	blzmcp_sc_run( 'none' );
}

// ---------------------------------------------------------------------------------------------
// Recommended: identical to 1.0 apart from the connection row.
if ( 'recommended' === $state && ! $denylist ) {
	blzmcp_it_equals( 'pass', $by['connection']['status'] ?? null, 'recommended: connection passes' );
	blzmcp_it_assert( str_contains( $by['connection']['label'] ?? '', 'Enable Abilities for MCP' ), 'recommended: connection names Enable Abilities for MCP' );

	$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/setup-checks-1.0.json' ), true );
	blzmcp_it_assert( is_array( $fixture ) && isset( $fixture['_inputs'], $fixture['rows'] ), 'recommended: 1.0 fixture loads' );
	$restore = blzmcp_fx_set_inputs();
	try {
		$now     = json_decode( (string) wp_json_encode( blzmcp_fx_inputs() ), true );
		$changed = array();
		foreach ( (array) ( $fixture['_inputs'] ?? array() ) as $key => $value ) {
			if ( ( $now[ $key ] ?? null ) !== $value ) {
				$changed[] = $key;
			}
		}
		if ( $changed && 'min' === getenv( 'BLZMCP_FIXTURE_ENV' ) ) {
			// CI minimum-versions job only: the fixture is bound to the pinned current environment.
			echo 'NOTE  1.0 fixture comparison not applicable in the minimum-versions environment (fixture captured on WooCommerce ' . ( $fixture['_inputs']['plugins']['woocommerce/woocommerce.php'] ?? '?' ) . ")\n";
		} elseif ( $changed ) {
			blzmcp_it_assert( false, 'baseline inputs changed: ' . implode( ', ', $changed ) . ' (the environment differs from the fixture, not the copy)' );
		} else {
			$current = array_values( array_filter( blzmcp_fx_rows( SetupChecks::run() ), static fn( $r ) => 'connection' !== $r['id'] ) );
			blzmcp_it_equals( $fixture['rows'], $current, 'recommended: rows minus connection equal the 1.0 fixture (id, status, label, fix, order)' );
		}
	} finally {
		$restore();
	}
	blzmcp_it_assert( str_contains( $by['edge_guidance']['fix'] ?? '', '/.well-known/oauth-' ) && str_contains( $by['edge_guidance']['fix'] ?? '', '/wp-json/mcp/' ), 'recommended: edge_guidance lists the path exceptions' );
}

// ---------------------------------------------------------------------------------------------
// Easy MCP AI (with Enable Abilities still active in wp-env, so `both`).
if ( $easy ) {
	$ours      = array_values( array_filter( $all_names, static fn( $n ) => str_starts_with( $n, Registrar::CATEGORY . '/' ) || str_starts_with( $n, AnalyticsNaming::PREFIX ) ) );
	$wanted    = array_values( array_unique( array_merge( $ours, $denylist ? array( BLZMCP_SC_SLUG ) : array() ) ) );
	$enabled_o = 'easy_mcp_ai_enabled_abilities';
	blzmcp_sc_set( $enabled_o, $wanted );
	blzmcp_sc_set( 'easy_mcp_ai_enabled_plugin_groups', array() );
	blzmcp_sc_set( 'easy_mcp_ai_disabled_tools', array() );
	blzmcp_sc_set( 'easy_mcp_ai_approval_required', false );
	$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );

	$by = blzmcp_sc_run( "{$state} ticked" );
	if ( 'both' === $state ) {
		blzmcp_it_equals( 'warn', $by['connection']['status'] ?? null, 'both: connection warns' );
		blzmcp_it_assert( str_contains( $by['connection']['fix'] ?? '', 'for a new connection, use the Easy MCP AI connection link below' ), 'both: connection fix is the rev 3 copy' );
		blzmcp_it_assert( ! str_contains( $by['connection']['fix'] ?? '', 'keep working' ), 'both: connection makes no claim about existing connections' );
	} else {
		blzmcp_it_equals( 'pass', $by['connection']['status'] ?? null, 'easy-mcp: connection passes' );
	}
	foreach ( array( 'easy_tools_on', 'easy_signin_access', 'easy_rest_read', 'easy_write_tools', 'easy_woo_group' ) as $row_id ) {
		blzmcp_it_assert( isset( $by[ $row_id ] ), "easy: {$row_id} present" );
	}
	blzmcp_it_assert( str_contains( $by['edge_guidance']['fix'] ?? '', '/wp-json/easy-mcp-ai/' ) && str_contains( $by['edge_guidance']['fix'] ?? '', '/register' ), 'easy: edge_guidance lists Easy MCP AI paths, /register included' );
	blzmcp_it_assert( ! str_contains( $by['edge_guidance']['fix'] ?? '', '/wp-json/mcp/*' ), 'easy: edge_guidance does not list the MCP Adapter path' );
	blzmcp_it_equals( 'warn', $by['easy_signin_access']['status'] ?? null, 'easy: sign-in access is a warn reminder' );
	$access = $by['easy_signin_access']['fix'] ?? '';
	blzmcp_it_assert( false !== strpos( $access, 'Custom' ) && false !== strpos( $access, 'Full access' ) && strpos( $access, 'Custom' ) < strpos( $access, 'Full access' ), 'easy: sign-in access leads with Custom, then Full access' );
	blzmcp_it_equals( 'pass', $by['easy_tools_on']['status'] ?? null, 'easy: every tool ticked: easy_tools_on passes' );
	blzmcp_it_equals( 'warn', $by['easy_write_tools']['status'] ?? null, 'easy: approval off: easy_write_tools warns' );
	blzmcp_it_equals( 'pass', $by['easy_woo_group']['status'] ?? null, 'easy: WooCommerce group off: easy_woo_group passes' );

	if ( $real_toolstate ) {
		blzmcp_it_equals( 'warn', $by['easy_rest_read']['status'] ?? null, 'real easy: wp_rest_read on: easy_rest_read warns' );
		blzmcp_it_assert( str_contains( $by['easy_rest_read']['fix'] ?? '', 'wp_rest_read' ), 'real easy: easy_rest_read names wp_rest_read' );
		if ( ! $denylist ) {
			blzmcp_it_equals( 'pass', $by['tools_visible']['status'] ?? null, 'real easy: all ticked and on: tools_visible passes' );
		}
		// Everything switched off through Easy MCP AI's own disabled-tools list.
		blzmcp_sc_set( 'easy_mcp_ai_disabled_tools', array_merge( array( 'wp_rest_read' ), array_map( array( \Blaze\McpToolkit\Connectors\EasyMcpNaming::class, 'tool_name' ), $wanted ) ) );
		$off = blzmcp_sc_run( 'real easy switched off' );
		blzmcp_it_equals( 'pass', $off['easy_rest_read']['status'] ?? null, 'real easy: wp_rest_read switched off: easy_rest_read passes' );
		blzmcp_it_equals( 'warn', $off['tools_visible']['status'] ?? null, 'real easy: switched off in Easy MCP AI: tools_visible warns' );
		blzmcp_it_assert( str_contains( $off['tools_visible']['fix'] ?? '', 'switched off in Easy MCP AI' ) && ! str_contains( $off['tools_visible']['fix'] ?? '', '(disabled)' ), 'real easy: switched-off reason in plain words, no raw code' );
		blzmcp_sc_set( 'easy_mcp_ai_disabled_tools', array() );
		echo "NOTE  real Tool_State: the unknown-state and resolve()-call-log cases need the stub (fast tier)\n";
	} elseif ( $toolstate ) {
		blzmcp_it_equals( 'warn', $by['easy_rest_read']['status'] ?? null, 'easy: wp_rest_read on: easy_rest_read warns' );
		blzmcp_it_assert( str_contains( $by['easy_rest_read']['fix'] ?? '', 'wp_rest_read' ), 'easy: easy_rest_read names wp_rest_read' );
		if ( ! $denylist ) {
			blzmcp_it_equals( 'pass', $by['tools_visible']['status'] ?? null, 'easy: all ticked and on: tools_visible passes' );
		}

		// Everything switched off: rest read passes, tools_visible names the plain reason.
		$GLOBALS['blzmcp_toolstate_calls']  = array();
		$GLOBALS['blzmcp_toolstate_return'] = array( false, 'disabled' );
		$off                                = blzmcp_sc_run( 'easy switched off' );
		blzmcp_it_equals( 'pass', $off['easy_rest_read']['status'] ?? null, 'easy: wp_rest_read switched off: easy_rest_read passes' );
		blzmcp_it_assert( in_array( array( 'wp_rest_read', false ), $GLOBALS['blzmcp_toolstate_calls'], true ), "easy: wp_rest_read read through resolve( 'wp_rest_read', false )" );
		blzmcp_it_equals( 'warn', $off['tools_visible']['status'] ?? null, 'easy: switched off in Easy MCP AI: tools_visible warns' );
		blzmcp_it_assert( str_contains( $off['tools_visible']['fix'] ?? '', 'switched off in Easy MCP AI' ) && ! str_contains( $off['tools_visible']['fix'] ?? '', '(disabled)' ), 'easy: switched-off reason in plain words, no raw code' );
		blzmcp_it_equals( 0, $GLOBALS['blzmcp_toolstate_is_enabled'] ?? 0, 'easy: Tool_State::is_enabled() never called' );

		// Unknown effective state: "couldn't check".
		$GLOBALS['blzmcp_toolstate_return'] = 'not-an-array';
		$unk                                = blzmcp_sc_run( 'easy unknown' );
		blzmcp_it_equals( 'warn', $unk['easy_rest_read']['status'] ?? null, 'easy: unknown rest read state: warns' );
		blzmcp_it_assert( str_contains( strtolower( $unk['easy_rest_read']['label'] ?? '' ), "couldn't check" ), "easy: unknown rest read state: couldn't check" );
		blzmcp_it_assert( 'warn' === ( $unk['tools_visible']['status'] ?? null ) && str_contains( $unk['tools_visible']['fix'] ?? '', "Couldn't check" ), "easy: unknown tool state: tools_visible says it couldn't check" );
		if ( ! $denylist ) {
			// With a removal as well (denylist mode), the label leads with "switched off".
			blzmcp_it_assert( str_contains( strtolower( $unk['tools_visible']['label'] ?? '' ), "couldn't check" ), "easy: unknown tool state only: tools_visible label says couldn't check" );
		}
		$GLOBALS['blzmcp_toolstate_return'] = array( true, 'default' );
	} else {
		blzmcp_it_equals( 'warn', $by['easy_rest_read']['status'] ?? null, 'easy (no Tool_State): easy_rest_read warns' );
		blzmcp_it_assert( str_contains( strtolower( $by['easy_rest_read']['label'] ?? '' ), "couldn't check" ), "easy (no Tool_State): easy_rest_read couldn't check" );
		if ( ! $denylist ) {
			blzmcp_it_assert( 'warn' === ( $by['tools_visible']['status'] ?? null ) && str_contains( strtolower( $by['tools_visible']['label'] ?? '' ), "couldn't check" ), "easy (no Tool_State): tools_visible couldn't check" );
		}
	}

	// One tool unticked: easy_tools_on lists it; tools_visible (sign-in owner) says where to tick it.
	$unticked = 'blaze-mcp-toolkit/update-history';
	blzmcp_sc_set( $enabled_o, array_values( array_diff( $wanted, array( $unticked ) ) ) );
	$un = blzmcp_sc_run( 'easy unticked' );
	blzmcp_it_equals( 'warn', $un['easy_tools_on']['status'] ?? null, 'easy: unticked tool: easy_tools_on warns' );
	blzmcp_it_assert( str_contains( $un['easy_tools_on']['fix'] ?? '', $unticked ), 'easy: easy_tools_on names the unticked tool' );
	blzmcp_it_assert( str_contains( $un['tools_visible']['fix'] ?? '', $unticked ) && str_contains( $un['tools_visible']['fix'] ?? '', 'not ticked in Easy MCP AI' ), 'easy: tools_visible says the tool is not ticked in Easy MCP AI' );
	blzmcp_sc_set( $enabled_o, $wanted );

	// WooCommerce group on, approval on.
	blzmcp_sc_set( 'easy_mcp_ai_enabled_plugin_groups', array( 'woocommerce' ) );
	blzmcp_sc_set( 'easy_mcp_ai_approval_required', true );
	$grp = blzmcp_sc_run( 'easy woo group' );
	blzmcp_it_equals( 'warn', $grp['easy_woo_group']['status'] ?? null, 'easy: WooCommerce group on: easy_woo_group warns' );
	blzmcp_it_assert( str_contains( $grp['easy_woo_group']['fix'] ?? '', 'addresses' ), 'easy: easy_woo_group warns about personal details' );
	blzmcp_it_equals( 'pass', $grp['easy_write_tools']['status'] ?? null, 'easy: approval on: easy_write_tools passes' );
	blzmcp_sc_set( 'easy_mcp_ai_enabled_plugin_groups', array() );
	blzmcp_sc_set( 'easy_mcp_ai_approval_required', false );

	// health-summary on Enable Abilities' denylist and ticked in Easy MCP AI: reported as switched off in
	// Enable Abilities, with its settings link; never "tick it" or "reactivate". In denylist mode
	// Enable Abilities has really unregistered it; otherwise only the option says so.
	if ( 'both' === $state ) {
		if ( ! $denylist ) {
			blzmcp_sc_set( 'ewpa_thirdparty_disabled', array( BLZMCP_SC_SLUG ) );
		}
		$dl  = blzmcp_sc_run( 'both denylisted' );
		$fix = $dl['tools_visible']['fix'] ?? '';
		blzmcp_it_equals( 'warn', $dl['tools_visible']['status'] ?? null, 'both denylisted: tools_visible warns' );
		blzmcp_it_assert( str_contains( $fix, BLZMCP_SC_SLUG ) && str_contains( $fix, 'Enable Abilities' ), 'both denylisted: names the tool and Enable Abilities' );
		blzmcp_it_assert( str_contains( $fix, 'options-general.php?page=ewpa-settings' ), "both denylisted: links Enable Abilities' settings" );
		blzmcp_it_assert( ! str_contains( $fix, 'Tools › Abilities' ) && ! str_contains( $fix, 'reactivate' ), 'both denylisted: no tick-it or reactivate advice' );
		if ( ! $denylist ) {
			blzmcp_sc_set( 'ewpa_thirdparty_disabled', array() );
		}
	}
}

// ---------------------------------------------------------------------------------------------
// Denylist mode, recommended: Enable Abilities unregistered health-summary; the 1.0 strings.
if ( $denylist && 'recommended' === $state ) {
	blzmcp_it_assert( ! wp_has_ability( BLZMCP_SC_SLUG ), 'denylist: Enable Abilities unregistered health-summary' );
	$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/setup-checks-1.0.json' ), true );
	$dl      = blzmcp_sc_run( 'recommended denylisted' );
	blzmcp_it_equals( $fixture['denylist_tools_visible'] ?? null, blzmcp_fx_rows( array( $dl['tools_visible'] ?? array() ) )[0], 'denylist: tools_visible is the 1.0 warn row exactly' );
}

// ---------------------------------------------------------------------------------------------
// Classifier, direct cases (pure functions: every state).
blzmcp_it_equals( false, SetupChecks::is_write( 'x/get-thing', array() ), 'un-annotated get-* is not write' );
blzmcp_it_equals( false, SetupChecks::is_write( 'x/list-things', array() ), 'un-annotated list-* is not write' );
blzmcp_it_equals( false, SetupChecks::is_write( 'x/search-things', array() ), 'un-annotated search-* is not write' );
blzmcp_it_equals( false, SetupChecks::is_write( 'woocommerce/orders-query', array() ), 'un-annotated *-query is not write' );
blzmcp_it_equals( false, SetupChecks::is_write( 'core/get-site-info', array() ), 'un-annotated *-info is not write' );
foreach ( array( 'ewpa/wc-get-products', 'ewpa/wc-get-product', 'ewpa/wc-get-orders', 'ewpa/wc-get-order', 'ewpa/wc-get-customers', 'ewpa/yoast-get-seo', 'ewpa/yoast-get-sitemap-index', 'ewpa/site-stats' ) as $read_name ) {
	blzmcp_it_equals( false, SetupChecks::is_write( $read_name, array() ), "{$read_name} is not write" );
}
foreach ( array( 'ewpa/wc-update-product', 'ewpa/wc-update-order-status', 'ewpa/search-replace', 'ewpa/buscar-reemplazar' ) as $write_name ) {
	blzmcp_it_equals( true, SetupChecks::is_write( $write_name, array() ), "{$write_name} is write" );
}
blzmcp_it_equals( true, SetupChecks::is_write( 'ewpa/update-thing', array() ), 'un-annotated update-* is write' );
blzmcp_it_equals( true, SetupChecks::is_write( 'x/frobnicate', array() ), 'un-annotated unknown verb is write' );
blzmcp_it_equals( true, SetupChecks::is_write( 'x/get-thing', array( 'readonly' => false ) ), 'readonly=false is write even if named get-*' );
blzmcp_it_equals( true, SetupChecks::is_write( 'x/get-thing', array( 'readonly' => true, 'destructive' => true ) ), 'readonly=true + destructive=true is write' );
blzmcp_it_equals( false, SetupChecks::is_write( 'x/update-thing', array( 'readonly' => true ) ), 'readonly=true is not write' );
blzmcp_it_equals( true, SetupChecks::is_write( 'x/get-thing', array( 'destructive' => true ) ), 'destructive=true alone is write' );
blzmcp_it_equals( false, SetupChecks::is_write( 'mcp-adapter/execute-ability', array() ), 'mcp-adapter tools are never flagged' );
$never = 0;
foreach ( wp_get_abilities() as $ability ) {
	$n = $ability->get_name();
	if ( str_starts_with( $n, 'blaze-mcp-toolkit/' ) || str_starts_with( $n, 'wc-analytics/' ) ) {
		++$never;
		blzmcp_it_equals( false, SetupChecks::is_write( $n, $ability->get_meta()['annotations'] ?? array() ), "{$n} is never flagged as write" );
	}
}
blzmcp_it_assert( $never >= 7, 'blaze and wc-analytics abilities were checked' );
blzmcp_it_equals( true, SetupChecks::is_pii_name( 'ewpa/wc-get-customers' ), 'wc-get-customers is on the PII list' );
blzmcp_it_equals( true, SetupChecks::is_pii_name( 'ewpa/get-comments' ), 'get-comments is on the PII list' );
blzmcp_it_equals( false, SetupChecks::is_pii_name( 'blaze-mcp-toolkit/store-profile' ), 'our tools are not on the PII list' );
blzmcp_it_equals( 'pass', SetupChecks::result( 'x', 'pass', 'Label' )['status'], 'result() is public and builds a row' );

// ---------------------------------------------------------------------------------------------
// Recommended rows driven by Enable Abilities' options (the 1.0 tests).
$orig  = array(
	'oauth' => get_option( 'ewpa_oauth_enabled', null ),
	'conn'  => get_option( 'ewpa_oauth_connectors_enabled', null ),
	'deny'  => get_option( 'ewpa_thirdparty_disabled', null ),
	'sm'    => Settings::shop_manager_diagnostics(),
);
$sm_id = 0;

try {
	if ( 'recommended' === $state && ! $denylist ) {
		update_option( 'ewpa_oauth_enabled', true );
		update_option( 'ewpa_oauth_connectors_enabled', true );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'pass', $by['oauth_on']['status'], 'oauth_on passes when on' );
		blzmcp_it_equals( 'pass', $by['chatgpt_connector']['status'], 'chatgpt_connector passes when on' );
		update_option( 'ewpa_oauth_enabled', false );
		update_option( 'ewpa_oauth_connectors_enabled', false );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'warn', $by['oauth_on']['status'], 'oauth_on warns when off' );
		blzmcp_it_equals( 'warn', $by['chatgpt_connector']['status'], 'chatgpt_connector warns when off' );

		blzmcp_it_equals( 'warn', $by['pii_tools']['status'], 'pii_tools warns while customer/order tools are exposed' );
		blzmcp_it_equals( 'warn', $by['write_tools']['status'], 'write_tools warns while write tools are exposed' );
		blzmcp_it_assert( ! str_contains( strtolower( $by['write_tools']['label'] ), 'read-only' ), 'write_tools label never claims read-only' );

		// Denylisting one of our tools makes tools_visible warn and name it.
		$ours = array_values( array_filter( $all_names, static fn( $n ) => str_starts_with( $n, 'blaze-mcp-toolkit/' ) ) );
		update_option( 'ewpa_thirdparty_disabled', array( $ours[0] ) );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'warn', $by['tools_visible']['status'], 'tools_visible warns when one of ours is denylisted' );
		blzmcp_it_assert( str_contains( $by['tools_visible']['fix'], $ours[0] ), 'tools_visible names the disabled tool' );
		update_option( 'ewpa_thirdparty_disabled', array() );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'pass', $by['tools_visible']['status'], 'tools_visible passes with empty denylist' );

		// PII: an enabled ewpa/wc-get-customers is named; denylisting it removes it from the message.
		blzmcp_it_assert( str_contains( $by['pii_tools']['fix'], 'ewpa/wc-get-customers' ), 'enabled ewpa/wc-get-customers is flagged' );
		update_option( 'ewpa_thirdparty_disabled', array( 'woocommerce/orders-query', 'ewpa/wc-get-customers' ) );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_assert( ! str_contains( $by['pii_tools']['fix'], 'ewpa/wc-get-customers' ) && ! str_contains( $by['pii_tools']['fix'], 'woocommerce/orders-query' ), 'denylisted tools are not flagged' );

		// A denylisted wc-analytics tool makes tools_visible warn.
		update_option( 'ewpa_thirdparty_disabled', array( 'wc-analytics/totals' ) );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'warn', $by['tools_visible']['status'], 'tools_visible warns when wc-analytics/totals is denylisted' );
		blzmcp_it_assert( str_contains( $by['tools_visible']['fix'], 'wc-analytics/totals' ), 'tools_visible names the denylisted analytics tool' );

		// 1.0 order and status (strings from SetupChecks at 8972431): ours in Registrar order, then
		// wc-analytics in denylist order; any denylisted wc-analytics/* name counts.
		$tv_label = 'Some Blaze MCP Toolkit tools are switched off in Enable Abilities';
		$tv_fix   = static fn( string $names ): string => 'AI assistants cannot see: ' . $names . '. Turn them back on under Settings > WP Abilities, in the third-party tools list.';
		update_option( 'ewpa_thirdparty_disabled', array( 'wc-analytics/breakdown', 'wc-analytics/totals' ) );
		$tv = array_column( SetupChecks::run(), null, 'id' )['tools_visible'];
		blzmcp_it_equals( array( 'warn', $tv_label, $tv_fix( 'wc-analytics/breakdown, wc-analytics/totals' ) ), array( $tv['status'], $tv['label'], $tv['fix'] ), 'denylisted analytics: the 1.0 row, in denylist order' );
		update_option( 'ewpa_thirdparty_disabled', array( 'wc-analytics/totals', 'blaze-mcp-toolkit/update-history' ) );
		$tv = array_column( SetupChecks::run(), null, 'id' )['tools_visible'];
		blzmcp_it_equals( $tv_fix( 'blaze-mcp-toolkit/update-history, wc-analytics/totals' ), $tv['fix'], 'denylisted ours and analytics: ours first, then analytics (1.0 order)' );
		update_option( 'ewpa_thirdparty_disabled', array( 'wc-analytics/x' ) );
		$tv = array_column( SetupChecks::run(), null, 'id' )['tools_visible'];
		blzmcp_it_equals( array( 'warn', $tv_label, $tv_fix( 'wc-analytics/x' ) ), array( $tv['status'], $tv['label'], $tv['fix'] ), 'denylisted wc-analytics/* outside the known five: still the 1.0 warn' );
		update_option( 'ewpa_thirdparty_disabled', array() );
		blzmcp_it_assert( ! str_contains( array_column( SetupChecks::run(), null, 'id' )['chatgpt_connector']['fix'], 'Claude does not need' ), 'no unverified Claude claim' );
	}

	// Settings save (every state).
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$admin  = $admins[0];
	wp_set_current_user( $admin->ID );
	Settings::update( array( 'shop_manager_diagnostics' => false ) );

	$ok = SetupScreen::save( array( 'blzmcp_setup_nonce' => 'bogus', 'shop_manager_diagnostics' => '1' ) );
	blzmcp_it_equals( false, $ok, 'save with invalid nonce is rejected' );
	blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'invalid nonce leaves setting unchanged' );

	$ok = SetupScreen::save( array( 'shop_manager_diagnostics' => '1' ) );
	blzmcp_it_equals( false, $ok, 'save with no nonce is rejected' );
	blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'missing nonce leaves setting unchanged' );

	$nonce = wp_create_nonce( SetupScreen::NONCE_ACTION );
	$ok    = SetupScreen::save( array( 'blzmcp_setup_nonce' => $nonce, 'shop_manager_diagnostics' => '1' ) );
	blzmcp_it_equals( true, $ok, 'valid nonce as admin saves' );
	blzmcp_it_equals( true, Settings::shop_manager_diagnostics(), 'setting turned on' );

	$ok = SetupScreen::save( array( 'blzmcp_setup_nonce' => $nonce ) );
	blzmcp_it_equals( true, $ok, 'unchecked box saves' );
	blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'setting turned off when box unchecked' );

	// Shop manager cannot save.
	$sm_id = wp_insert_user( array( 'user_login' => 'blzmcp_it_sm', 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'blzmcp-it-sm@example.invalid' ) );
	wp_set_current_user( $sm_id );
	$nonce = wp_create_nonce( SetupScreen::NONCE_ACTION );
	$ok    = SetupScreen::save( array( 'blzmcp_setup_nonce' => $nonce, 'shop_manager_diagnostics' => '1' ) );
	blzmcp_it_equals( false, $ok, 'shop manager save is rejected' );
	blzmcp_it_equals( false, Settings::shop_manager_diagnostics(), 'shop manager cannot change the setting' );

	// Render as shop manager (setting hidden/disabled) and as admin; no other plugin's option changes.
	$before = blzmcp_sc_snapshot();
	ob_start();
	SetupScreen::render();
	$html_sm = ob_get_clean();
	blzmcp_it_assert( str_contains( $html_sm, 'WooCommerce is a trademark of Automattic Inc.' ), 'footer trademark line renders' );
	blzmcp_it_assert( ! str_contains( $html_sm, 'name="shop_manager_diagnostics"' ), 'shop manager does not get the setting control' );

	wp_set_current_user( $admin->ID );
	ob_start();
	SetupScreen::render();
	$html = ob_get_clean();
	blzmcp_it_assert( blzmcp_sc_snapshot() === $before, "{$state}: render leaves easy_mcp_ai_% and ewpa_% options unchanged" );
	blzmcp_it_assert( str_contains( $html, 'name="shop_manager_diagnostics"' ), 'admin gets the setting control' );
	if ( 'recommended' === $state ) {
		blzmcp_it_assert( str_contains( $html, esc_html( rest_url( 'mcp/mcp-oauth-server' ) ) ), 'connector URL shown' );
		blzmcp_it_assert( str_contains( $html, 'Plugins' ) && str_contains( $html, 'Add custom MCP server' ), 'ChatGPT wording shown' );
	}
	if ( 'recommended' === $state ) {
		blzmcp_it_assert( str_contains( $html, 'page=ewpa-settings' ) && str_contains( $html, 'Open Enable Abilities settings (Settings &gt; WP Abilities)' ), 'recommended: Enable Abilities settings link shown' );
	}
	if ( in_array( $state, array( 'easy-mcp', 'both' ), true ) ) {
		blzmcp_it_assert( str_contains( $html, esc_html( rest_url( 'easy-mcp-ai/v1/mcp' ) ) ), "{$state}: Easy MCP AI connector URL shown" );
		blzmcp_it_assert( ! str_contains( $html, esc_html( rest_url( 'mcp/mcp-oauth-server' ) ) ), "{$state}: recommended connector URL not shown" );
		blzmcp_it_assert( str_contains( $html, 'page=easy-mcp-ai-connections' ), "{$state}: Easy MCP AI connect screen link shown" );
		blzmcp_it_assert( str_contains( $html, 'page=easy-mcp-ai-tools' ), "{$state}: Easy MCP AI settings link shown" );
		blzmcp_it_assert( ! str_contains( $html, 'Open Enable Abilities settings' ), "{$state}: no Enable Abilities settings link under the table" );
	}
	if ( 'none' === $state ) {
		blzmcp_it_assert( str_contains( $html, 'Connect your AI assistant' ) && str_contains( $html, 'Easy MCP AI' ), 'none: install steps shown' );
		blzmcp_it_assert( ! str_contains( $html, esc_html( rest_url( 'mcp/mcp-oauth-server' ) ) ), 'none: no connector URL' );
		blzmcp_it_assert( ! str_contains( $html, 'Open Enable Abilities settings' ), 'none: no Enable Abilities settings link' );
	}
	blzmcp_it_assert( str_contains( \Blaze\McpToolkit\Privacy\PolicyText::content(), 'Easy MCP AI' ), 'privacy text names Easy MCP AI' );
	echo "\n----- RENDERED CHECKS ({$state}) -----\n";
	foreach ( SetupChecks::run() as $c ) {
		printf( "%-18s %-5s %s\n", $c['id'], $c['status'], $c['label'] );
	}
	echo "---------------------------\n";

	// Audit F06: after Enable Abilities' real unregister hook has run, a denylisted tool is
	// "switched off" (settings advice), not "not registered". Last in this file: it unregisters.
	if ( 'recommended' === $state && ! $denylist ) {
		$switched = BLZMCP_SC_SLUG;
		update_option( 'ewpa_thirdparty_disabled', array( $switched ) );
		blzmcp_it_assert( function_exists( 'ewpa_tp_enforce' ), 'Enable Abilities unregister hook is loaded' );
		ewpa_tp_enforce();
		blzmcp_it_assert( ! wp_has_ability( $switched ), 'Enable Abilities unregistered the denylisted tool' );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'warn', $by['tools_visible']['status'] ?? null, 'switched-off tool (unregistered by Enable Abilities): tools_visible warns, not fails' );
		blzmcp_it_assert( str_contains( $by['tools_visible']['fix'] ?? '', $switched ) && str_contains( $by['tools_visible']['fix'] ?? '', 'Settings > WP Abilities' ), 'switched-off tool: names it and points to Settings > WP Abilities' );
		blzmcp_it_assert( ! str_contains( $by['tools_visible']['fix'] ?? '', 'reactivate' ), 'switched-off tool: no deactivate/reactivate advice' );
		// Off the denylist but still missing: a real registration failure.
		update_option( 'ewpa_thirdparty_disabled', array() );
		$by = array_column( SetupChecks::run(), null, 'id' );
		blzmcp_it_equals( 'fail', $by['tools_visible']['status'] ?? null, 'missing tool that is not denylisted: tools_visible fails' );
		blzmcp_it_assert( str_contains( $by['tools_visible']['fix'] ?? '', $switched ) && str_contains( $by['tools_visible']['fix'] ?? '', 'reactivate' ), 'missing tool: registration advice' );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sm_id );
	$sm_id = 0;
} finally {
	foreach ( array( 'oauth' => 'ewpa_oauth_enabled', 'conn' => 'ewpa_oauth_connectors_enabled', 'deny' => 'ewpa_thirdparty_disabled' ) as $key => $opt ) {
		if ( null === $orig[ $key ] ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $orig[ $key ] );
		}
	}
	Settings::update( array( 'shop_manager_diagnostics' => $orig['sm'] ) );
	if ( $sm_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $sm_id );
	}
	wp_set_current_user( 0 );
}

blzmcp_it_finish();
