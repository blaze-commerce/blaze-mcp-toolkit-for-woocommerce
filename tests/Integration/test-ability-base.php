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

/**
 * Test-only ability with a settable cache context. The context can also derive from the current
 * user's effective capability, or throw.
 */
final class Blzmcp_Test_Context_Ability extends AbstractAbility {
	public const NAME = 'blaze-mcp-toolkit/test-context';

	public static int $runs = 0;

	public static int $context_calls = 0;

	/** @var array<string, mixed> */
	public static array $context = array();

	public static bool $with_capability = false;

	public static bool $throw = false;

	protected static function label(): string {
		return 'Test context';
	}

	protected static function description(): string {
		return 'Integration-test ability with a cache context.';
	}

	protected static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'message' => array( 'type' => 'string' ) ),
			'additionalProperties' => false,
		);
	}

	protected static function output_schema(): array {
		return array( 'type' => 'object' );
	}

	protected static function can(): bool {
		return current_user_can( 'manage_options' );
	}

	protected static function cache_context(): array {
		++self::$context_calls;
		if ( self::$throw ) {
			throw new \RuntimeException( 'context secret /var/www/html/wp-config.php' );
		}
		$context = self::$context;
		if ( self::$with_capability ) {
			$context['diagnostics'] = current_user_can( 'blzmcp_test_diagnostics' );
		}
		return $context;
	}

	public static function key_for( array $input ): string {
		return self::cache_key( $input );
	}

	protected static function run( array $input ): array|\WP_Error {
		++self::$runs;
		return array(
			'checked_at' => 'run-' . self::$runs,
			'context'    => self::$context,
			'items'      => array( 1 ),
		);
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
add_action( 'wp_abilities_api_init', array( Blzmcp_Test_Context_Ability::class, 'register' ) );
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
$logged     = array();
$log_filter = static function ( $message, $level, $context ) use ( &$logged ) {
	if ( 'blzmcp' === ( $context['source'] ?? '' ) ) {
		$logged[] = array( $level, (string) $message );
	}
	return $message;
};
add_filter( 'woocommerce_logger_log_message', $log_filter, 10, 3 );
$thrown = wp_get_ability( Blzmcp_Test_Throw_Ability::NAME )->execute( array() );
remove_filter( 'woocommerce_logger_log_message', $log_filter, 10 );
$trace = implode( "\n", array_column( $logged, 1 ) );
blzmcp_it_assert( array() !== $logged && 'error' === $logged[0][0], 'throwing run() logs an error to the blzmcp WooCommerce log source' );
blzmcp_it_assert( str_contains( $trace, 'RuntimeException' ) && str_contains( $trace, Blzmcp_Test_Throw_Ability::NAME ) && 1 === preg_match( '/:\d+/', $trace ), 'log entry names the ability, exception class and file:line' );
blzmcp_it_assert( ! str_contains( $trace, 'wp-config' ) && ! str_contains( $trace, 'secret path' ) && ! str_contains( $trace, ABSPATH ), 'log entry has no exception message and no absolute ABSPATH' );
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

// Cache context: an empty context keeps the original key bytes.
wp_set_current_user( $admins[0]->ID );
$legacy_roles = array_map( 'strval', (array) wp_get_current_user()->roles );
sort( $legacy_roles );
$legacy_key = AbstractAbility::CACHE_PREFIX . md5( Blzmcp_Test_Echo_Ability::NAME . wp_json_encode( AuditLog::normalise( array( 'message' => $unique ) ) ) . implode( ',', $legacy_roles ) );
blzmcp_it_equals( $legacy_key, Blzmcp_Test_Echo_Ability::key_for( array( 'message' => $unique ) ), 'empty cache context keeps the 1.1 key bytes' );

$ctx_ability = wp_get_ability( Blzmcp_Test_Context_Ability::NAME );
blzmcp_it_assert( $ctx_ability instanceof WP_Ability, 'test-context ability is registered' );
$ctx_input = array( 'message' => $unique );
$ctx_keys  = array();
$ctx_key   = static function ( array $context ) use ( $ctx_input, &$ctx_keys ): string {
	Blzmcp_Test_Context_Ability::$context = $context;
	$key                                  = Blzmcp_Test_Context_Ability::key_for( $ctx_input );
	$ctx_keys[]                           = $key;
	return $key;
};
blzmcp_it_cleanup(
	static function () use ( &$ctx_keys ) {
		foreach ( array_unique( $ctx_keys ) as $k ) {
			delete_transient( $k );
		}
	}
);
$empty_ctx_key = $ctx_key( array() );
$on_key        = $ctx_key( array( 'diag' => true, 'blog' => 1 ) );
blzmcp_it_assert( $on_key !== $empty_ctx_key, 'a non-empty cache context changes the key' );
blzmcp_it_equals( $on_key, $ctx_key( array( 'blog' => 1, 'diag' => true ) ), 'cache context key order does not change the key' );
blzmcp_it_assert( $on_key !== $ctx_key( array( 'diag' => false, 'blog' => 1 ) ), 'a changed context value changes the key' );
blzmcp_it_assert( $on_key !== $ctx_key( array( 'diag' => true, 'blog' => 2 ) ), 'a different blog ID changes the key' );
blzmcp_it_assert( 1 === preg_match( '/^' . AbstractAbility::CACHE_PREFIX . '[0-9a-f]{32}$/', $on_key ), 'context keys keep the blzmcp_c_ prefix and md5 shape' );

// Late-static dispatch through execute(): context resolved per call, changes miss without a flush.
foreach ( array( $on_key, $ctx_key( array( 'diag' => false, 'blog' => 1 ) ) ) as $k ) {
	delete_transient( $k );
}
Blzmcp_Test_Context_Ability::$runs          = 0;
Blzmcp_Test_Context_Ability::$context_calls = 0;
Blzmcp_Test_Context_Ability::$context       = array( 'diag' => true, 'blog' => 1 );
$first = $ctx_ability->execute( $ctx_input );
$again = $ctx_ability->execute( $ctx_input );
blzmcp_it_equals( 1, Blzmcp_Test_Context_Ability::$runs, 'identical context and input is a cache hit' );
blzmcp_it_equals( 'run-1', is_array( $again ) ? ( $again['checked_at'] ?? null ) : null, 'cache hit reuses the original checked_at' );
blzmcp_it_assert( Blzmcp_Test_Context_Ability::$context_calls >= 2, 'cache context is resolved by execute() through static dispatch' );
Blzmcp_Test_Context_Ability::$context = array( 'diag' => false, 'blog' => 1 );
$down = $ctx_ability->execute( $ctx_input );
blzmcp_it_equals( 2, Blzmcp_Test_Context_Ability::$runs, 'context on-to-off misses the cache without a flush' );
blzmcp_it_equals( array( 'diag' => false, 'blog' => 1 ), is_array( $down ) ? ( $down['context'] ?? null ) : null, 'downgraded context gets its own result, not the privileged one' );
Blzmcp_Test_Context_Ability::$context = array( 'diag' => true, 'blog' => 1 );
$up = $ctx_ability->execute( $ctx_input );
blzmcp_it_equals( 2, Blzmcp_Test_Context_Ability::$runs, 'context off-to-on reuses the still-valid on entry' );
blzmcp_it_equals( 'run-1', is_array( $up ) ? ( $up['checked_at'] ?? null ) : null, 'restored context returns its own original checked_at' );
delete_transient( $on_key );
$regen = $ctx_ability->execute( $ctx_input );
blzmcp_it_equals( 3, Blzmcp_Test_Context_Ability::$runs, 'expired or absent cache regenerates' );
blzmcp_it_equals( 'run-3', is_array( $regen ) ? ( $regen['checked_at'] ?? null ) : null, 'regenerated result has a new checked_at' );
blzmcp_it_equals( is_array( $first ) ? $first['checked_at'] : 'x', 'run-1', 'first call ran fresh' );

// Identical roles, different effective capability: context derived from the capability splits the key.
Blzmcp_Test_Context_Ability::$context         = array();
Blzmcp_Test_Context_Ability::$with_capability = true;
wp_set_current_user( $admins[0]->ID );
$cap_user = wp_get_current_user(); // The live current-user object, so cap changes apply at once.
blzmcp_it_cleanup(
	static function () use ( $cap_user ) {
		$cap_user->remove_cap( 'blzmcp_test_diagnostics' );
	}
);
$cap_user->remove_cap( 'blzmcp_test_diagnostics' );
$roles_before = $cap_user->roles;
$no_cap_key   = Blzmcp_Test_Context_Ability::key_for( $ctx_input );
$cap_user->add_cap( 'blzmcp_test_diagnostics' );
blzmcp_it_assert( current_user_can( 'blzmcp_test_diagnostics' ) && $roles_before === $cap_user->roles, 'capability fixture: same roles, capability granted' );
$cap_key = Blzmcp_Test_Context_Ability::key_for( $ctx_input );
$cap_user->remove_cap( 'blzmcp_test_diagnostics' );
$ctx_keys[] = $no_cap_key;
$ctx_keys[] = $cap_key;
blzmcp_it_assert( $no_cap_key !== $cap_key, 'same roles with different effective capability get different keys' );
Blzmcp_Test_Context_Ability::$with_capability = false;

// Denied caller: never executes, so the context is not resolved and nothing is served from cache.
Blzmcp_Test_Context_Ability::$context = array( 'diag' => true, 'blog' => 1 );
$ctx_ability->execute( $ctx_input ); // Re-seed the privileged entry.
wp_set_current_user( $sub_id );
$runs_before  = Blzmcp_Test_Context_Ability::$runs;
$calls_before = Blzmcp_Test_Context_Ability::$context_calls;
$denied_ctx   = $ctx_ability->execute( $ctx_input );
blzmcp_it_assert( is_wp_error( $denied_ctx ), 'denied caller of a context ability gets a WP_Error' );
blzmcp_it_equals( $runs_before, Blzmcp_Test_Context_Ability::$runs, 'denied caller never reaches run()' );
blzmcp_it_equals( $calls_before, Blzmcp_Test_Context_Ability::$context_calls, 'denied caller never resolves the cache context' );
blzmcp_it_equals( 1, $count( Blzmcp_Test_Context_Ability::NAME, 'denied' ), 'denied context call is audited once' );
wp_set_current_user( $admins[0]->ID );

// Throwing context: generic error, one error row, no run, no cache read or write, no leak.
set_transient( $ctx_key( array() ), array( 'checked_at' => 'poisoned' ), 300 );
$errors_before                      = $count( Blzmcp_Test_Context_Ability::NAME, 'error' );
$runs_before                        = Blzmcp_Test_Context_Ability::$runs;
Blzmcp_Test_Context_Ability::$throw = true;
$logged                             = array();
add_filter( 'woocommerce_logger_log_message', $log_filter, 10, 3 );
$ctx_thrown = $ctx_ability->execute( $ctx_input );
remove_filter( 'woocommerce_logger_log_message', $log_filter, 10 );
Blzmcp_Test_Context_Ability::$throw = false;
blzmcp_it_assert( is_wp_error( $ctx_thrown ) && 'blzmcp_ability_failed' === $ctx_thrown->get_error_code(), 'throwing cache context returns the generic WP_Error' );
blzmcp_it_assert( is_wp_error( $ctx_thrown ) && ! str_contains( $ctx_thrown->get_error_message(), 'wp-config' ), 'throwing cache context does not leak its message' );
blzmcp_it_equals( $runs_before, Blzmcp_Test_Context_Ability::$runs, 'throwing cache context never runs the ability' );
blzmcp_it_equals( $errors_before + 1, $count( Blzmcp_Test_Context_Ability::NAME, 'error' ), 'throwing cache context writes one error row' );
$ctx_trace = implode( "\n", array_column( $logged, 1 ) );
blzmcp_it_assert( str_contains( $ctx_trace, 'RuntimeException' ) && ! str_contains( $ctx_trace, 'context secret' ), 'throwing cache context is logged by class only' );

// Invalid context values (object, non-finite float) fail the same way and never fall back to a shared key.
foreach ( array( 'object' => array( 'o' => new stdClass() ), 'nested object' => array( 'a' => array( 'b' => new ArrayObject() ) ), 'NAN' => array( 'f' => NAN ), 'INF' => array( 'f' => INF ) ) as $label => $bad ) {
	Blzmcp_Test_Context_Ability::$context = $bad;
	$errors_before                        = $count( Blzmcp_Test_Context_Ability::NAME, 'error' );
	$runs_before                          = Blzmcp_Test_Context_Ability::$runs;
	$bad_result                           = $ctx_ability->execute( $ctx_input );
	blzmcp_it_assert( is_wp_error( $bad_result ) && 'blzmcp_ability_failed' === $bad_result->get_error_code(), "invalid cache context ({$label}) returns the generic WP_Error, not the poisoned shared entry" );
	blzmcp_it_equals( $runs_before, Blzmcp_Test_Context_Ability::$runs, "invalid cache context ({$label}) never runs the ability" );
	blzmcp_it_equals( $errors_before + 1, $count( Blzmcp_Test_Context_Ability::NAME, 'error' ), "invalid cache context ({$label}) writes one error row" );
}
Blzmcp_Test_Context_Ability::$context = array( 'ok' => 1.5, 'n' => null, 'list' => array( 'a', 2, false ) );
$valid_mixed                          = $ctx_ability->execute( $ctx_input );
$ctx_key( Blzmcp_Test_Context_Ability::$context );
blzmcp_it_assert( is_array( $valid_mixed ), 'finite floats, null and nested lists are a valid context' );
blzmcp_it_equals( array( 'checked_at' => 'poisoned' ), get_transient( $ctx_key( array() ) ), 'failed context calls leave other cache entries untouched' );

// Cleanup.
foreach ( array_unique( $ctx_keys ) as $k ) {
	delete_transient( $k );
}
foreach ( array( array( 'message' => $unique ), array( 'extra' => 'x', 'message' => $unique ) ) as $cached_input ) {
	delete_transient( Blzmcp_Test_Echo_Ability::key_for( $cached_input ) );
}
blzmcp_it_assert( false === get_transient( Blzmcp_Test_Echo_Ability::key_for( array( 'message' => $unique ) ) ), 'test cache transients removed' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ability LIKE %s', $table, 'blaze-mcp-toolkit/test-%' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sub_id );

blzmcp_it_finish();
