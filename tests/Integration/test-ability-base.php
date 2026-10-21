<?php
/**
 * Ability base class, registrar and audit log integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-ability-base.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.DB, Generic.Files.OneObjectStructurePerFile

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Abilities\AbstractAbility;
use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Audit\AuditLog;

/**
 * Test-only ability: echoes its input and counts how often run() executes.
 */
final class Blzmcp_Test_Echo_Ability extends AbstractAbility {
	public const NAME = 'blaze-mcp-toolkit/test-echo';

	public static int $runs = 0;

	protected static function label(): string {
		return 'Test echo';
	}

	protected static function description(): string {
		return 'Integration-test ability.';
	}

	protected static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'message' => array( 'type' => 'string' ),
				'extra'   => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	protected static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'message' => array( 'type' => 'string' ),
				'items'   => array( 'type' => 'array' ),
			),
		);
	}

	protected static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function key_for( array $input ): string {
		return static::cache_key( $input );
	}

	protected static function run( array $input ): array|\WP_Error {
		++self::$runs;
		return array(
			'message' => (string) ( $input['message'] ?? '' ),
			'items'   => array( 1, 2, 3 ),
		);
	}
}

/**
 * Test-only ability whose run() throws with a path-like message.
 */
final class Blzmcp_Test_Throw_Ability extends AbstractAbility {
	public const NAME = 'blaze-mcp-toolkit/test-throw';

	protected static function label(): string {
		return 'Test throw';
	}

	protected static function description(): string {
		return 'Integration-test ability that throws.';
	}

	protected static function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	protected static function output_schema(): array {
		return array( 'type' => 'object' );
	}

	protected static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	protected static function cache_ttl(): int {
		return 0;
	}

	protected static function run( array $input ): array|\WP_Error {
		throw new \RuntimeException( 'secret path /var/www/html/wp-config.php' );
	}
}

global $wpdb, $wp_filter;
$table = $wpdb->prefix . 'blzmcp_audit';

// Our category is registered through Registrar on wp_abilities_api_categories_init.
blzmcp_it_assert( wp_has_ability_category( Registrar::CATEGORY ), 'blaze-mcp-toolkit category is registered' );
blzmcp_it_equals( 10, has_action( 'blzmcp_daily_cleanup', array( AuditLog::class, 'purge' ) ), 'daily cleanup hook runs AuditLog::purge' );

// The registry has already initialised under WP-CLI, so register the test abilities inside
// wp_abilities_api_init with only our callback attached, then restore the original callbacks.
$saved = $wp_filter['wp_abilities_api_init'] ?? null;
unset( $wp_filter['wp_abilities_api_init'] );
add_action( 'wp_abilities_api_init', array( Blzmcp_Test_Echo_Ability::class, 'register' ) );
add_action( 'wp_abilities_api_init', array( Blzmcp_Test_Throw_Ability::class, 'register' ) );
do_action( 'wp_abilities_api_init', WP_Abilities_Registry::get_instance() );
unset( $wp_filter['wp_abilities_api_init'] );
if ( null !== $saved ) {
	$wp_filter['wp_abilities_api_init'] = $saved;
}

$ability = wp_get_ability( Blzmcp_Test_Echo_Ability::NAME );
blzmcp_it_assert( $ability instanceof WP_Ability, 'test-echo ability is registered' );
$meta = $ability ? $ability->get_meta() : array();
blzmcp_it_equals( true, $meta['mcp']['public'] ?? null, 'meta.mcp.public is true' );
blzmcp_it_equals( true, $meta['show_in_rest'] ?? null, 'meta.show_in_rest is true' );
blzmcp_it_equals( array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), array_intersect_key( $meta['annotations'] ?? array(), array_flip( array( 'readonly', 'destructive', 'idempotent' ) ) ), 'annotations readonly/destructive/idempotent' );
blzmcp_it_equals( Registrar::CATEGORY, $ability ? $ability->get_category() : null, 'ability category is blaze-mcp-toolkit' );

$count = static function ( string $ability_name, ?string $status = null ) use ( $wpdb, $table ): int {
	if ( null === $status ) {
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s', $table, $ability_name ) );
	}
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s AND status = %s', $table, $ability_name, $status ) );
};

// Clean slate for the test abilities.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ability LIKE %s', $table, 'blaze-mcp-toolkit/test-%' ) );
$unique = 'hello-' . wp_generate_password( 12, false );

// Administrator: exactly one ok row.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$result = $ability->execute( array( 'message' => $unique ) );
blzmcp_it_assert( is_array( $result ) && $unique === ( $result['message'] ?? null ), 'administrator gets data' );
blzmcp_it_equals( 1, $count( Blzmcp_Test_Echo_Ability::NAME ), 'administrator call writes exactly one audit row' );
blzmcp_it_equals( 1, $count( Blzmcp_Test_Echo_Ability::NAME, 'ok' ), 'administrator row has status ok' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ability = %s ORDER BY id DESC LIMIT 1', $table, Blzmcp_Test_Echo_Ability::NAME ), ARRAY_A );
blzmcp_it_equals( 3, (int) ( $row['row_count'] ?? -1 ), 'row_count counts the first list in output' );
blzmcp_it_equals( (int) $admins[0]->ID, (int) ( $row['user_id'] ?? 0 ), 'audit row records the user id' );
blzmcp_it_equals( AuditLog::hash_params( array( 'message' => $unique ) ), $row['params_hash'] ?? '', 'params_hash is the sha256 of the input' );
$raw_hits = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE CONCAT_WS(\'|\', ability, params_hash, status) LIKE %s', $table, '%' . $wpdb->esc_like( $unique ) . '%' ) );
blzmcp_it_equals( 0, $raw_hits, 'raw input is not stored' );

// Cache: second identical call (keys in another order) is served from cache but still audited.
$runs_before = Blzmcp_Test_Echo_Ability::$runs;
$ability->execute( array( 'extra' => 'x', 'message' => $unique ) );
$cached = $ability->execute( array( 'message' => $unique, 'extra' => 'x' ) );
blzmcp_it_equals( $runs_before + 1, Blzmcp_Test_Echo_Ability::$runs, 'second call with re-ordered keys is a cache hit' );
blzmcp_it_assert( is_array( $cached ) && $unique === ( $cached['message'] ?? null ), 'cache hit returns the data' );
blzmcp_it_equals( 3, $count( Blzmcp_Test_Echo_Ability::NAME, 'ok' ), 'cache hit is still audited as ok' );

// Recent(): newest first, no raw input.
$recent = AuditLog::recent( 5 );
blzmcp_it_assert( count( $recent ) >= 3 && Blzmcp_Test_Echo_Ability::NAME === $recent[0]['ability'], 'recent() returns newest first' );
blzmcp_it_equals( array( 'ability', 'created_at', 'duration_ms', 'row_count', 'status', 'user_id' ), ( static function ( $r ) {
	$k = array_keys( $r );
	sort( $k );
	return $k;
} )( $recent[0] ), 'recent() rows expose only the documented columns' );

// Subscriber: WP_Error, one denied row, no data, run() never called.
$sub_id = wp_insert_user(
	array(
		'user_login' => 'blzmcp_sub_' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( $sub_id );
$runs_before = Blzmcp_Test_Echo_Ability::$runs;
$denied      = $ability->execute( array( 'message' => $unique ) );
blzmcp_it_assert( is_wp_error( $denied ), 'subscriber gets a WP_Error' );
blzmcp_it_assert( is_wp_error( $denied ) && false === strpos( wp_json_encode( $denied->get_all_error_data() ) . $denied->get_error_message(), $unique ), 'denied response carries no data' );
blzmcp_it_equals( $runs_before, Blzmcp_Test_Echo_Ability::$runs, 'run() is not called for subscriber (cache not served either)' );
blzmcp_it_equals( 1, $count( Blzmcp_Test_Echo_Ability::NAME, 'denied' ), 'subscriber call writes one denied row' );
$ability->execute( array( 'message' => $unique ) );
blzmcp_it_equals( 2, $count( Blzmcp_Test_Echo_Ability::NAME, 'denied' ), 'logged-in subscriber: two denials write two rows' );
$denied_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ability = %s AND status = %s', $table, Blzmcp_Test_Echo_Ability::NAME, 'denied' ), ARRAY_A );
blzmcp_it_equals( (int) $sub_id, (int) ( $denied_row['user_id'] ?? 0 ), 'denied row records the subscriber id' );

// Anonymous: two denials within a minute write exactly one row.
$anon_flag = AbstractAbility::ANON_PREFIX . md5( Blzmcp_Test_Echo_Ability::NAME );
delete_transient( $anon_flag );
wp_set_current_user( 0 );
$denied_before = $count( Blzmcp_Test_Echo_Ability::NAME, 'denied' );
$anon_1        = $ability->execute( array( 'message' => $unique ) );
$anon_2        = $ability->execute( array( 'message' => $unique ) );
blzmcp_it_assert( is_wp_error( $anon_1 ) && is_wp_error( $anon_2 ), 'anonymous calls are both denied' );
blzmcp_it_equals( $denied_before + 1, $count( Blzmcp_Test_Echo_Ability::NAME, 'denied' ), 'two anonymous denials within a minute write exactly one row' );
$anon_rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s AND status = %s AND user_id = 0', $table, Blzmcp_Test_Echo_Ability::NAME, 'denied' ) );
blzmcp_it_equals( 1, $anon_rows, 'the anonymous denied row has user_id 0' );
delete_transient( $anon_flag );

// Throwing run(): generic WP_Error, error row, no exception text.
wp_set_current_user( $admins[0]->ID );
$thrown = wp_get_ability( Blzmcp_Test_Throw_Ability::NAME )->execute( array() );
blzmcp_it_assert( is_wp_error( $thrown ), 'throwing run() returns a WP_Error' );
blzmcp_it_assert( is_wp_error( $thrown ) && false === strpos( $thrown->get_error_message(), 'wp-config' ), 'error message does not leak exception text' );
blzmcp_it_equals( 1, $count( Blzmcp_Test_Throw_Ability::NAME, 'error' ), 'throwing run() writes one error row' );

// purge(): removes rows older than the cutoff, keeps newer ones.
$wpdb->insert(
	$table,
	array(
		'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ),
		'user_id'     => 0,
		'ability'     => 'blaze-mcp-toolkit/test-old',
		'params_hash' => str_repeat( '0', 64 ),
		'status'      => 'ok',
	)
);
$purged = AuditLog::purge( 30 );
blzmcp_it_assert( $purged >= 1, 'purge(30) deletes old rows' );
blzmcp_it_equals( 0, $count( 'blaze-mcp-toolkit/test-old' ), 'old row is gone' );
blzmcp_it_equals( 3, $count( Blzmcp_Test_Echo_Ability::NAME, 'ok' ), 'recent rows survive purge' );

// Batched purge: more than one batch of old rows is fully removed, fresh rows kept.
$old_at = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
$values = array();
for ( $i = 0; $i < 2345; $i++ ) {
	$values[] = $wpdb->prepare( '(%s, 0, %s, %s, 0, 0, %s)', $old_at, 'blaze-mcp-toolkit/test-bulk', str_repeat( '0', 64 ), 'ok' );
}
$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (created_at, user_id, ability, params_hash, row_count, duration_ms, status) VALUES ', $table ) . implode( ',', $values ) );
$wpdb->insert(
	$table,
	array(
		'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		'user_id'     => 0,
		'ability'     => 'blaze-mcp-toolkit/test-fresh',
		'params_hash' => str_repeat( '0', 64 ),
		'status'      => 'ok',
	)
);
blzmcp_it_equals( 2345, $count( 'blaze-mcp-toolkit/test-bulk' ), 'bulk old rows inserted' );
$purged = AuditLog::purge( 30 );
blzmcp_it_assert( $purged >= 2345, 'batched purge reports all deleted rows (got ' . $purged . ')' );
blzmcp_it_equals( 0, $count( 'blaze-mcp-toolkit/test-bulk' ), 'batched purge removes every old row' );
blzmcp_it_equals( 1, $count( 'blaze-mcp-toolkit/test-fresh' ), 'batched purge keeps the fresh row' );

// Cleanup.
foreach ( array( array( 'message' => $unique ), array( 'extra' => 'x', 'message' => $unique ) ) as $cached_input ) {
	delete_transient( Blzmcp_Test_Echo_Ability::key_for( $cached_input ) );
}
blzmcp_it_assert( false === get_transient( Blzmcp_Test_Echo_Ability::key_for( array( 'message' => $unique ) ) ), 'test cache transients removed' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ability LIKE %s', $table, 'blaze-mcp-toolkit/test-%' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub_id );

blzmcp_it_finish();
