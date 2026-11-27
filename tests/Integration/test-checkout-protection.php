<?php
/**
 * CheckoutProtection integration test (FEATURE-PLAN item 5, contract C7): the real passive reads
 * against real options and real pages created through wp_insert_post.
 *
 * Run: npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-checkout-protection.php
 *
 * The test changes only woocommerce_checkout_page_id and woocommerce_feature_rate_limit_checkout_enabled
 * and its own tagged pages. The original option values (and whether they existed, with their
 * autoload flag) are saved to a temporary option before the first change; they are restored on
 * success, on failure, after a fatal error (the shared cleanup stack) and, if a run was killed,
 * at the start of the next run. Order storage is never changed.
 *
 * Interrupted-run recovery, two fresh processes (the first leaves changes behind on purpose):
 *   T=wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration
 *   npx wp-env run cli env BLZMCP_SKIP_HTTP=1 BLZMCP_CP_STEP=interrupt wp eval-file $T/test-checkout-protection.php
 *   npx wp-env run cli env BLZMCP_SKIP_HTTP=1 wp eval-file $T/test-checkout-protection.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Diagnostics\CardTestingOrders;
use Blaze\McpToolkit\Diagnostics\CheckoutProtection;

const BLZMCP_CP_STATE = 'blzmcp_it_cp_restore';
const BLZMCP_CP_TAG   = '_blzmcp_cp';
const BLZMCP_CP_OPTS  = array( 'woocommerce_checkout_page_id', 'woocommerce_feature_rate_limit_checkout_enabled' );

/** Restore the saved options and remove every tagged page (this run's and any killed run's). */
function blzmcp_cp_restore(): void {
	global $wpdb;
	$state = get_option( BLZMCP_CP_STATE, null );
	if ( is_array( $state ) ) {
		foreach ( (array) ( $state['options'] ?? array() ) as $name => $saved ) {
			if ( ! in_array( $name, BLZMCP_CP_OPTS, true ) ) {
				continue;
			}
			delete_option( $name );
			if ( ! empty( $saved['exists'] ) ) {
				$autoload = (string) ( $saved['autoload'] ?? '' );
				add_option( $name, $saved['value'], '', in_array( $autoload, array( 'on', 'yes', 'auto-on' ), true ) ? true : ( in_array( $autoload, array( 'off', 'no', 'auto-off' ), true ) ? false : null ) );
			}
		}
	}
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, BLZMCP_CP_TAG ) );
	foreach ( (array) $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	delete_option( BLZMCP_CP_STATE );
	wp_cache_flush();
}

/** A run tag for this process. */
function blzmcp_cp_tag(): string {
	static $tag = null;
	if ( null === $tag ) {
		$tag = 'cp_' . wp_generate_password( 8, false );
	}
	return $tag;
}

/**
 * Create one tagged page; fails the test if WordPress changed the content on save.
 *
 * @param string $content Content.
 * @param string $status  Post status.
 */
function blzmcp_cp_page( string $content, string $status = 'publish' ): int {
	global $wpdb;
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => 'Blzmcp checkout fixture',
			'post_content' => wp_slash( $content ),
			'meta_input'   => array( BLZMCP_CP_TAG => blzmcp_cp_tag() ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		blzmcp_it_assert( false, 'fixture page created: ' . $id->get_error_code() );
		return 0;
	}
	$stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_content FROM %i WHERE ID = %d', $wpdb->posts, $id ) );
	blzmcp_it_assert( $stored === $content, 'fixture page ' . $id . ' stored byte-for-byte (' . strlen( $content ) . ' bytes)' );
	return (int) $id;
}

/** Point WooCommerce's checkout page setting at a value. */
function blzmcp_cp_set_checkout( $value ): void {
	update_option( 'woocommerce_checkout_page_id', $value );
	wp_cache_flush();
}

/**
 * Run $work while counting every hook that rendering, executing, filtering, dispatching, sending
 * or writing would fire, and capturing every SQL statement.
 *
 * @param callable $work Work.
 * @return array{0:mixed,1:array<string,int>,2:list<string>}
 */
function blzmcp_cp_observe( callable $work ): array {
	$hooks = array(
		'the_content',
		'render_block',
		'pre_render_block',
		'render_block_data',
		'do_shortcode_tag',
		'pre_do_shortcode_tag',
		'woocommerce_payment_gateways',
		'wc_payment_gateways_initialized',
		'rest_api_init',
		'rest_authentication_errors',
		'rest_pre_dispatch',
		'woocommerce_checkout_process',
		'woocommerce_after_checkout_validation',
		'woocommerce_before_pay_action',
		'pre_http_request',
		'pre_wp_mail',
		'added_option',
		'updated_option',
		'deleted_option',
		'setted_transient',
		'pre_option_active_plugins',
		'option_active_plugins',
	);
	// The watched filters are not counted here: a callback on them is exactly what the reader
	// reports as present. The filter section below adds them on purpose and counts their calls.
	$counts = array_fill_keys( $hooks, 0 );
	$cbs    = array();
	foreach ( $hooks as $hook ) {
		$cbs[ $hook ] = static function ( ...$args ) use ( &$counts, $hook ) {
			++$counts[ $hook ];
			return $args[0] ?? null;
		};
		add_filter( $hook, $cbs[ $hook ], PHP_INT_MAX, 99 );
	}
	$sql   = array();
	$qcb   = static function ( $query ) use ( &$sql ) {
		$sql[] = (string) $query;
		return $query;
	};
	add_filter( 'query', $qcb, PHP_INT_MAX );
	try {
		$out = $work();
	} finally {
		remove_filter( 'query', $qcb, PHP_INT_MAX );
		foreach ( $cbs as $hook => $cb ) {
			remove_filter( $hook, $cb, PHP_INT_MAX );
		}
	}
	return array( $out, $counts, $sql );
}

/**
 * Snapshot, rows, limitations, cache facts and attribution support from one snapshot.
 *
 * @return array<string,mixed>
 */
function blzmcp_cp_all(): array {
	$snap = CheckoutProtection::snapshot();
	return array(
		'snap'  => $snap,
		'rows'  => CheckoutProtection::paths( $snap ),
		'lims'  => CheckoutProtection::limitations( $snap ),
		'cache' => CheckoutProtection::cache_facts( $snap ),
		'attr'  => CheckoutProtection::attribution_support( $snap ),
	);
}

/**
 * Rows keyed by ID.
 *
 * @param list<array<string,mixed>> $rows Rows.
 * @return array<string,array<string,mixed>>
 */
function blzmcp_cp_by_id( array $rows ): array {
	$out = array();
	foreach ( $rows as $row ) {
		$out[ $row['id'] ] = $row;
	}
	return $out;
}

/**
 * Assert a run fired nothing it must not and only read.
 *
 * @param array<string,int> $counts Hook counts.
 * @param list<string>      $sql    Statements.
 * @param string            $label  Label.
 */
function blzmcp_cp_assert_passive( array $counts, array $sql, string $label ): void {
	$fired = array_filter( $counts );
	blzmcp_it_equals( array(), $fired, "$label: no block render, shortcode, filter result, gateway, REST, checkout, HTTP, mail, option, transient or plugin-list hook ran" );
	$writes = array_filter( $sql, static fn( string $q ): bool => 1 !== preg_match( '/^\s*SELECT\s/i', $q ) );
	blzmcp_it_equals( array(), array_values( $writes ), "$label: every query is a SELECT" );
	$wide = array_filter( $sql, static fn( string $q ): bool => 1 === preg_match( '/post_password|SELECT\s+\*/i', $q ) );
	blzmcp_it_equals( array(), array_values( $wide ), "$label: no page password and no SELECT *" );
}

// ------------------------------------------------------------------------------- setup.

global $wpdb; // wp eval-file runs this file inside a function.
add_filter( 'pre_wp_mail', '__return_false', 1 );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'blzmcp_cp_blocked', 'blocked in checkout protection fixtures' ), 1 );
$absent = "\0absent";
$step   = (string) getenv( 'BLZMCP_CP_STEP' );

$leftover = get_option( BLZMCP_CP_STATE, false );
if ( false !== $leftover ) {
	echo "NOTE  restoring options and pages left by an interrupted run\n";
	blzmcp_cp_restore();
	foreach ( (array) ( $leftover['options'] ?? array() ) as $name => $saved ) {
		blzmcp_it_equals( ! empty( $saved['exists'] ) ? $saved['value'] : $absent, get_option( $name, $absent ), "$name restored after an interrupted run" );
	}
	blzmcp_it_equals( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', $wpdb->postmeta, BLZMCP_CP_TAG ) ), 'pages left by an interrupted run removed' );
} elseif ( '' === $step ) {
	blzmcp_it_assert( true, 'no interrupted run to recover' );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) ( $admins[0] ?? 0 );
blzmcp_it_assert( $admin > 0, 'an administrator exists' );
wp_set_current_user( $admin );

// Register the restore before the first change, then save the originals.
blzmcp_it_cleanup( 'blzmcp_cp_restore' );
$originals = array();
foreach ( BLZMCP_CP_OPTS as $name ) {
	$value              = get_option( $name, $absent );
	$originals[ $name ] = array(
		'exists'   => $absent !== $value,
		'value'    => $absent !== $value ? $value : null,
		'autoload' => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ) ),
	);
}
add_option( BLZMCP_CP_STATE, array( 'options' => $originals ), '', false );
blzmcp_it_assert( is_array( get_option( BLZMCP_CP_STATE ) ), 'originals saved before any change' );

if ( 'interrupt' === $step ) {
	// Simulate a process killed mid-run: change both options and leave a page, with no cleanup.
	update_option( 'woocommerce_feature_rate_limit_checkout_enabled', 'enabled' );
	blzmcp_cp_set_checkout( (string) blzmcp_cp_page( '[woocommerce_checkout]' ) );
	$GLOBALS['blzmcp_it_cleanups'] = array();
	blzmcp_it_assert( true, 'left changed options and a page behind, as a killed run would' );
	blzmcp_it_finish();
	return;
}

// ------------------------------------------------------------------------------- baseline.

list( $base, $counts, $sql ) = blzmcp_cp_observe( 'blzmcp_cp_all' );
blzmcp_cp_assert_passive( $counts, $sql, 'baseline' );
blzmcp_it_equals( true, $base['snap']['permitted'], 'administrator may read' );
blzmcp_it_equals( WC_VERSION, $base['snap']['wc_version'], 'WooCommerce version read from the constant' );
blzmcp_it_equals( CheckoutProtection::PATH_IDS, array_column( $base['rows'], 'id' ), 'the 5 fixed rows, in order' );
blzmcp_it_assert( count( $sql ) <= 6, 'baseline reads stay small (' . count( $sql ) . ' queries)' );
$env_support = CheckoutProtection::version_support( $base['snap']['wc_version'] );
echo 'NOTE  environment: WooCommerce ' . WC_VERSION . ', supported range ' . ( $env_support['supported'] ? 'yes' : 'no' ) . ', source pin ' . ( $env_support['pinned'] ? 'yes' : 'no' ) . "\n";

// Ruling Q3 provenance: the installed version, the source pins and the range, on the real read.
$prov = $base['lims'][1] ?? array();
blzmcp_it_equals( array( 'protection', 'protection_source_versions', null ), array( $prov['scope'] ?? null, $prov['code'] ?? null, array_key_exists( 'check_next', $prov ) ? $prov['check_next'] : 'missing' ), 'source-version limitation follows the enforcement limitation' );
blzmcp_it_equals( CheckoutProtection::reason( 'protection_source_versions' ) . ' ' . CheckoutProtection::installed_version_text( WC_VERSION ), $prov['what'] ?? null, 'it names the pins, the range and the installed WooCommerce version' );
blzmcp_it_assert( false !== strpos( (string) ( $prov['what'] ?? '' ), 'WooCommerce ' . WC_VERSION . ' is installed' ), 'installed version ' . WC_VERSION . ' disclosed' );
blzmcp_it_equals( array( WC_VERSION, $env_support['supported'], $env_support['pinned'] ), array( $base['cache']['wc_version'], $base['cache']['wc_supported'], $base['cache']['wc_source_pinned'] ), 'cache facts carry the installed version, support and pin flags' );
blzmcp_it_equals( CardTestingOrders::version_supported( WC_VERSION ), $env_support['supported'], 'the order reader and the protection facts agree on the installed version' );

// ------------------------------------------------------------------------------- pages.

$max   = CheckoutProtection::MAX_PAGE_BYTES;
$block = "<!-- wp:woocommerce/checkout -->\n<div class=\"wp-block-woocommerce-checkout\"></div>\n<!-- /wp:woocommerce/checkout -->";
$canary = 'BLZCANARY-' . wp_generate_password( 10, false );
$cp_pages  = array(
	'classic'       => array( blzmcp_cp_page( "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->" ), 'published', 'classic', 'classic' ),
	'block'         => array( blzmcp_cp_page( $block ), 'published', 'block', 'block' ),
	'mixed'         => array( blzmcp_cp_page( $block . "\n[woocommerce_checkout]" ), 'published', 'mixed', 'mixed' ),
	'classic_block' => array( blzmcp_cp_page( '<!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout"} /-->' ), 'published', 'classic', 'classic' ),
	'builder'       => array( blzmcp_cp_page( '[builder_section id="12"]Checkout here[/builder_section]' ), 'published', 'none', 'unknown' ),
	'draft'         => array( blzmcp_cp_page( '[woocommerce_checkout]', 'draft' ), 'unpublished', null, 'unknown' ),
	'private'       => array( blzmcp_cp_page( '[woocommerce_checkout]', 'private' ), 'unpublished', null, 'unknown' ),
	'at_limit'      => array( blzmcp_cp_page( str_repeat( 'x', $max - 22 ) . '[woocommerce_checkout]' ), 'published', 'classic', 'classic' ),
	'over_limit'    => array( blzmcp_cp_page( str_repeat( 'x', $max - 21 ) . '[woocommerce_checkout]' ), 'oversize', null, 'unknown' ),
	'canary'        => array( blzmcp_cp_page( '<p>' . $canary . ' <a href="https://shop.example/checkout/order-pay/12/?pay_for_order=true&key=wc_order_' . $canary . '">pay</a></p>[woocommerce_checkout]' ), 'published', 'classic', 'classic' ),
);
$missing_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(ID) FROM %i', $wpdb->posts ) ) + 100000;

$other_rows = array();
foreach ( $cp_pages as $name => list( $page_id, $state, $marker, $want_type ) ) {
	blzmcp_cp_set_checkout( (string) $page_id );
	list( $all, $counts, $sql ) = blzmcp_cp_observe( 'blzmcp_cp_all' );
	blzmcp_cp_assert_passive( $counts, $sql, "page $name" );
	$c   = $all['snap']['checkout'];
	$row = blzmcp_cp_by_id( $all['rows'] )['checkout_page'];
	blzmcp_it_equals( array( $page_id, $state, $marker, $want_type ), array( $c['id'], $c['state'], $c['marker'], $row['checkout_type'] ), "page $name: id, state, marker and type" );
	blzmcp_it_equals( 'published' === $state || 'oversize' === $state ? 'configured' : 'unknown', $row['exposure'], "page $name: exposure" );
	if ( 'published' === $state ) {
		$stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_content FROM %i WHERE ID = %d', $wpdb->posts, $page_id ) );
		blzmcp_it_equals( hash( 'sha256', $stored ), $all['cache']['checkout_content_sha256'], "page $name: cache digest is the SHA-256 of the bounded content" );
		blzmcp_it_equals( strlen( $stored ), $all['cache']['checkout_page_bytes'], "page $name: byte length" );
	} else {
		blzmcp_it_equals( null, $all['cache']['checkout_content_sha256'], "page $name: content not read" );
	}
	$by = blzmcp_cp_by_id( $all['rows'] );
	unset( $by['checkout_page'] );
	$other_rows[ $name ] = (string) wp_json_encode( $by );
	$json                = (string) wp_json_encode( array( $all['rows'], $all['lims'], $all['cache'], $all['attr'] ) );
	blzmcp_it_assert( false === strpos( $json, $canary ) && false === strpos( $json, 'key=' ) && false === strpos( $json, 'shop.example' ) && false === strpos( $json, 'http' ), "page $name: no page text, URL or query token in any output" );
}
blzmcp_it_equals( 1, count( array_unique( $other_rows ) ), 'no checkout page type or state changes the Store API, order payment or saved-method rows' );
$over = $wpdb->get_var( $wpdb->prepare( 'SELECT LENGTH(post_content) FROM %i WHERE ID = %d', $wpdb->posts, $cp_pages['over_limit'][0] ) );
blzmcp_it_equals( (string) ( $max + 1 ), (string) $over, 'the over-limit page is one byte over' );

// Setting states that are not a published page.
$settings = array(
	'absent'  => array( null, 'not_set', 'checkout_page_not_set' ),
	'empty'   => array( '', 'not_set', 'checkout_page_not_set' ),
	'zero'    => array( '0', 'not_set', 'checkout_page_not_set' ),
	'invalid' => array( 'abc', 'invalid_setting', 'checkout_page_invalid_setting' ),
	'missing' => array( (string) $missing_id, 'missing', 'checkout_page_missing' ),
);
foreach ( $settings as $name => list( $value, $state, $code ) ) {
	if ( null === $value ) {
		delete_option( 'woocommerce_checkout_page_id' );
		wp_cache_flush();
	} else {
		blzmcp_cp_set_checkout( $value );
	}
	list( $all, $counts, $sql ) = blzmcp_cp_observe( 'blzmcp_cp_all' );
	blzmcp_cp_assert_passive( $counts, $sql, "setting $name" );
	$lims = array_column( $all['lims'], 'code', 'scope' );
	blzmcp_it_equals( array( $state, 'unknown', 'unknown', $code ), array( $all['snap']['checkout']['state'], blzmcp_cp_by_id( $all['rows'] )['checkout_page']['checkout_type'], blzmcp_cp_by_id( $all['rows'] )['checkout_page']['exposure'], $lims['checkout_page'] ?? null ), "setting $name" );
	$by = blzmcp_cp_by_id( $all['rows'] );
	unset( $by['checkout_page'] );
	blzmcp_it_equals( $other_rows['classic'], (string) wp_json_encode( $by ), "setting $name: the other rows are unchanged" );
}

// A failed page read is unreadable, never a missing or marker-free page. The real read runs; only
// its statement is swapped for one that fails.
blzmcp_cp_set_checkout( (string) $cp_pages['classic'][0] );
$break = static fn( $q ) => false !== strpos( (string) $q, 'LENGTH(post_content)' ) ? 'SELECT blzmcp_no_such_column FROM ' . $GLOBALS['wpdb']->posts . ' LIMIT 1' : $q;
add_filter( 'query', $break, 1 );
$all = blzmcp_cp_all();
remove_filter( 'query', $break, 1 );
$lims = array_column( $all['lims'], 'code', 'scope' );
blzmcp_it_equals( array( 'unreadable', null, 'unknown', 'checkout_page_unreadable' ), array( $all['snap']['checkout']['state'], $all['snap']['checkout']['marker'], blzmcp_cp_by_id( $all['rows'] )['checkout_page']['checkout_type'], $lims['checkout_page'] ?? null ), 'a failed page read fails closed' );
blzmcp_it_equals( null, $all['cache']['checkout_content_sha256'], 'a failed read has no digest' );

// ------------------------------------------------------------------------------- rate limit setting.

blzmcp_cp_set_checkout( (string) $cp_pages['block'][0] );
$rl_cases = array(
	'yes'     => array( 'yes', 'configured', true ),
	'no'      => array( 'no', 'not_configured', false ),
	'absent'  => array( null, 'not_configured', false ),
	'garbage' => array( 'enabled', 'unknown', null ),
);
foreach ( $rl_cases as $name => list( $value, $state, $configured ) ) {
	if ( null === $value ) {
		delete_option( 'woocommerce_feature_rate_limit_checkout_enabled' );
	} else {
		update_option( 'woocommerce_feature_rate_limit_checkout_enabled', $value );
	}
	wp_cache_flush();
	list( $all, $counts, $sql ) = blzmcp_cp_observe( 'blzmcp_cp_all' );
	blzmcp_cp_assert_passive( $counts, $sql, "rate limit $name" );
	$by = blzmcp_cp_by_id( $all['rows'] );
	foreach ( array( 'checkout_page', 'store_api_checkout', 'store_api_order_pay' ) as $path_id ) {
		$entry = $by[ $path_id ]['protections'][0] ?? array();
		blzmcp_it_equals( array( $state, $configured, 'unverified' ), array( $by[ $path_id ]['protection_state'], array_key_exists( 'configured', $entry ) ? $entry['configured'] : 'no entry', $by[ $path_id ]['enforcement'] ), "rate limit $name: $path_id" );
	}
	blzmcp_it_equals( array( 'unknown', 'unknown' ), array( $by['order_pay']['protection_state'], $by['add_payment_method']['protection_state'] ), "rate limit $name: order payment page and saved methods never inherit it" );
	blzmcp_it_equals( null === $value ? 'absent' : ( 'garbage' === $name ? 'unrecognised' : $value ), $all['cache']['rate_limit_checkout'], "rate limit $name: cache fact" );
}
$lims = array_column( CheckoutProtection::limitations(), 'code', 'scope' );
blzmcp_it_equals( 'rate_limit_setting_unrecognised', $lims['store_api_checkout'] ?? null, 'an unexpected saved value is disclosed, never read as off' );

// Ruling Q3 on the real reads: block page, Rate limit Checkout on. WC_VERSION is a constant, so
// only the version in the real snapshot is swapped; every other fact comes from this store.
update_option( 'woocommerce_feature_rate_limit_checkout_enabled', 'yes' );
wp_cache_flush();
$real = CheckoutProtection::snapshot();
$at   = static function ( $display ) use ( $real ): array {
	$s = array_merge( $real, array( 'wc_version' => $display ) );
	return array(
		'rows' => blzmcp_cp_by_id( CheckoutProtection::paths( $s ) ),
		'lims' => CheckoutProtection::limitations( $s ),
	);
};
$pin   = $at( '11.3.1' );
$patch = $at( '11.3.2' );
blzmcp_it_equals( array( 'configured', true, true ), array( $pin['rows']['store_api_checkout']['protection_state'], $pin['rows']['store_api_checkout']['protections'][0]['configured'], $pin['rows']['store_api_checkout']['protections'][0]['scope_known'] ), 'pin 11.3.1: Store API checkout configured, scope known' );
blzmcp_it_equals( array( 'configured', true, false ), array( $patch['rows']['store_api_checkout']['protection_state'], $patch['rows']['store_api_checkout']['protections'][0]['configured'], $patch['rows']['store_api_checkout']['protections'][0]['scope_known'] ), 'later patch 11.3.2: supported by policy, the letter-case fact stays not known' );
blzmcp_it_equals( $pin['rows']['store_api_checkout']['facts'], $patch['rows']['store_api_checkout']['facts'], 'later patch 11.3.2: the shared source facts are used' );
blzmcp_it_assert( in_array( 'store_api_checkout|rate_limit_scope_unverified', array_map( static fn( $l ) => $l['scope'] . '|' . $l['code'], $patch['lims'] ), true ), 'later patch 11.3.2: the unknown letter-case scope is a disclosed gap' );
foreach ( array( '11.4.0', '10.8.9', '11.3.1-beta.1', '11.3.2-rc.1', null ) as $outside ) {
	$o     = $at( $outside );
	$codes = array_map( static fn( $l ) => $l['scope'] . '|' . $l['code'], $o['lims'] );
	$label = null === $outside ? 'unreadable version' : $outside;
	blzmcp_it_equals( array( 'unknown', 'unknown', null ), array( $o['rows']['store_api_checkout']['exposure'], $o['rows']['store_api_checkout']['protection_state'], $o['rows']['store_api_checkout']['protections'][0]['configured'] ), "$label: unsupported, no guessed value, even with the setting saved on" );
	blzmcp_it_equals( 'unknown', $o['rows']['checkout_page']['checkout_type'], "$label: the block page is not typed outside the range" );
	blzmcp_it_assert( in_array( 'store_api_checkout|wc_version_unsupported', $codes, true ) && in_array( 'protection|protection_source_versions', $codes, true ), "$label: unsupported and the source versions are disclosed" );
}
blzmcp_it_assert( false !== strpos( $at( '11.3.1-beta.1' )['lims'][1]['what'], 'WooCommerce 11.3.1-beta.1 is installed. Test and pre-release versions are outside the supported range.' ), 'a prerelease is named and rejected, never stripped to 11.3.1' );

update_option( 'woocommerce_feature_rate_limit_checkout_enabled', 'no' );
blzmcp_cp_set_checkout( (string) $cp_pages['classic'][0] );
$classic_off = blzmcp_cp_all();

// ------------------------------------------------------------------------------- filters and captcha plugins.

// A site's own callbacks on every watched filter: present, so qualified, and never applied.
$calls = 0;
$cb    = static function ( $v ) use ( &$calls ) {
	++$calls;
	return $v;
};
foreach ( CheckoutProtection::WATCHED_FILTERS as $hook ) {
	add_filter( $hook, $cb );
}
$all = CheckoutProtection::snapshot();
blzmcp_it_equals( array_fill_keys( array_keys( CheckoutProtection::WATCHED_FILTERS ), true ), $all['filters'], 'every watched filter is seen as present' );
blzmcp_it_equals( 0, $calls, 'no watched filter was applied' );
$by = blzmcp_cp_by_id( CheckoutProtection::paths( $all ) );
blzmcp_it_equals( array( 'unknown', 'unknown' ), array( $by['store_api_checkout']['protection_state'], $by['checkout_page']['checkout_type'] ), 'a rate-limit filter keeps an off setting unknown; a renamed shortcode tag keeps the classic page unknown' );
blzmcp_it_equals( 0, $calls, 'building rows applied no watched filter' );
foreach ( CheckoutProtection::WATCHED_FILTERS as $hook ) {
	remove_filter( $hook, $cb );
}

// Recognised bot-check plugins reported active and their checkout hooks: activation and hooks are
// not configured, enforcing protection, and the plugin list is never read at all.
$fake_plugins = static function ( $plugins ) {
	$plugins   = is_array( $plugins ) ? $plugins : array();
	$plugins[] = 'simple-cloudflare-turnstile/simple-cloudflare-turnstile.php';
	$plugins[] = 'advanced-google-recaptcha/advanced-google-recaptcha.php';
	$plugins[] = 'hcaptcha-for-forms-and-more/hcaptcha.php';
	return $plugins;
};
add_filter( 'option_active_plugins', $fake_plugins );
$hooked = 0;
$check  = static function ( ...$args ) use ( &$hooked ) {
	++$hooked;
	return $args[0] ?? null;
};
foreach ( array( 'woocommerce_checkout_process', 'woocommerce_after_checkout_validation', 'woocommerce_store_api_checkout_update_order_from_request', 'woocommerce_before_pay_action', 'woocommerce_add_payment_method_form_is_valid', 'rest_authentication_errors' ) as $hook ) {
	add_filter( $hook, $check, 10, 3 );
}
list( $captcha, $counts, $sql ) = blzmcp_cp_observe( 'blzmcp_cp_all' );
blzmcp_cp_assert_passive( $counts, $sql, 'captcha plugins reported active' );
blzmcp_it_equals( 0, $hooked, 'no checkout, payment or authentication hook was run' );
blzmcp_it_equals( wp_json_encode( array( $classic_off['rows'], $classic_off['lims'] ) ), wp_json_encode( array( $captcha['rows'], $captcha['lims'] ) ), 'active captcha plugins and their hooks change no row and no limitation' );
foreach ( $captcha['rows'] as $row ) {
	blzmcp_it_assert( 'configured' !== $row['protection_state'], "captcha evidence alone never makes {$row['id']} configured" );
}
remove_filter( 'option_active_plugins', $fake_plugins );
foreach ( array( 'woocommerce_checkout_process', 'woocommerce_after_checkout_validation', 'woocommerce_store_api_checkout_update_order_from_request', 'woocommerce_before_pay_action', 'woocommerce_add_payment_method_form_is_valid', 'rest_authentication_errors' ) as $hook ) {
	remove_filter( $hook, $check, 10 );
}

// ------------------------------------------------------------------------------- determinism and attribution.

$one = blzmcp_cp_all();
$two = blzmcp_cp_all();
blzmcp_it_equals( wp_json_encode( $one ), wp_json_encode( $two ), 'identical store state gives identical bytes' );
blzmcp_it_equals( array( 'option', 'enabled', 'supported', 'reason_code', 'prefix_filter', 'fields_filter', 'allow_tracking_filter' ), array_keys( $one['attr'] ), 'attribution support is reported apart from the rows' );
$attr_opt = get_option( 'woocommerce_feature_order_attribution_enabled', $absent );
blzmcp_it_equals( $absent === $attr_opt ? 'absent' : CheckoutProtection::parse_yes_no( $attr_opt ), $one['attr']['option'], 'attribution option read as stored (never changed by this test)' );

// ------------------------------------------------------------------------------- permission before work.

$editor = wp_insert_user(
	array(
		'user_login' => 'blzmcp_cp_' . strtolower( wp_generate_password( 6, false ) ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
	)
);
if ( ! is_wp_error( $editor ) ) {
	$editor_id = (int) $editor;
	blzmcp_it_cleanup(
		static function () use ( $editor_id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $editor_id );
		}
	);
}
foreach ( array( 'anonymous' => 0, 'editor' => is_wp_error( $editor ) ? 0 : (int) $editor ) as $who => $uid ) {
	wp_set_current_user( $uid );
	list( $denied, $counts, $sql ) = blzmcp_cp_observe( 'blzmcp_cp_all' );
	blzmcp_it_equals( array( 'permitted' => false ), $denied['snap'], "$who: nothing read" );
	blzmcp_it_equals( array( array(), array(), array() ), array( $denied['rows'], $denied['lims'], $denied['attr'] ), "$who: no rows, limitations or attribution facts" );
	blzmcp_it_equals( array(), $sql, "$who: no query ran before the permission check" );
	blzmcp_it_equals( array(), array_filter( $counts ), "$who: no hook ran" );
}
wp_set_current_user( $admin );

// ------------------------------------------------------------------------------- restore.

$page_ids = array_column( $cp_pages, 0 );
blzmcp_cp_restore();
foreach ( BLZMCP_CP_OPTS as $name ) {
	$now = get_option( $name, $absent );
	blzmcp_it_equals( $originals[ $name ]['exists'] ? $originals[ $name ]['value'] : $absent, $now, "$name restored" );
	blzmcp_it_equals( $originals[ $name ]['autoload'], (string) $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ) ), "$name autoload restored" );
}
$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', $wpdb->postmeta, BLZMCP_CP_TAG ) );
blzmcp_it_equals( 0, $left, 'every fixture page removed' );
blzmcp_it_equals( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', array_map( 'intval', $page_ids ) ) . ')' ), 'fixture page rows gone' );
blzmcp_it_equals( false, get_option( BLZMCP_CP_STATE, false ), 'temporary restore record removed' );

blzmcp_it_finish();
