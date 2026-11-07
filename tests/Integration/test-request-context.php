<?php
/**
 * Request context stack integration test.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/blaze-mcp-toolkit-for-woocommerce/tests/Integration/test-request-context.php
 *
 * @package Blaze\McpToolkit
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DevelopmentFunctions

require_once __DIR__ . '/assert.php';

use Blaze\McpToolkit\Connectors\RequestContext;

blzmcp_it_assert( class_exists( RequestContext::class ), 'RequestContext class exists' );
if ( ! class_exists( RequestContext::class ) ) {
	blzmcp_it_finish();
}

$GLOBALS['blzmcp_rc_log']    = array();
$GLOBALS['blzmcp_rc_b_runs'] = 0;
$GLOBALS['blzmcp_rc_thrown'] = null;
$GLOBALS['blzmcp_rc_caught'] = null;
$GLOBALS['blzmcp_rc_orig']   = array();

$resolver = static function ( string $route ): ?string {
	if ( '/blzmcp-test/a' === $route || '/blzmcp-test/denied' === $route ) {
		return 'recommended';
	}
	if ( '/blzmcp-test/b' === $route ) {
		return 'easy-mcp';
	}
	return null;
};
RequestContext::set_resolver( $resolver );
blzmcp_it_cleanup( static fn() => RequestContext::set_resolver( null ) );

$orig = array(
	'a'       => static function ( WP_REST_Request $req ) {
		$mode = (string) $req->get_param( 'mode' );
		$log  = &$GLOBALS['blzmcp_rc_log'];
		$log[] = 'a:' . var_export( RequestContext::current(), true );
		if ( 'nested' === $mode ) {
			rest_do_request( new WP_REST_Request( 'GET', '/blzmcp-test/b' ) );
			$log[] = 'a-after-b:' . var_export( RequestContext::current(), true );
		} elseif ( 'catch' === $mode ) {
			try {
				rest_do_request( new WP_REST_Request( 'GET', '/blzmcp-test/b' ) );
			} catch ( \RuntimeException $e ) {
				$GLOBALS['blzmcp_rc_caught'] = $e;
			}
			$log[] = 'a-after-catch:' . var_export( RequestContext::current(), true );
		} elseif ( 'throw' === $mode ) {
			throw new \RuntimeException( 'top-level' );
		}
		return array( 'ok' => true );
	},
	'b'       => static function ( WP_REST_Request $req ) {
		++$GLOBALS['blzmcp_rc_b_runs'];
		$GLOBALS['blzmcp_rc_log'][] = 'b:' . var_export( RequestContext::current(), true );
		if ( 'throw' === (string) $req->get_param( 'mode' ) || 'catch' === $GLOBALS['blzmcp_rc_mode'] ) {
			$GLOBALS['blzmcp_rc_thrown'] = new \RuntimeException( 'from-b' );
			throw $GLOBALS['blzmcp_rc_thrown'];
		}
		return array( 'ok' => true );
	},
	'neither' => static function () {
		$GLOBALS['blzmcp_rc_log'][] = 'n:' . var_export( RequestContext::current(), true );
		return array( 'ok' => true );
	},
	'denied'  => static function () {
		$GLOBALS['blzmcp_rc_log'][] = 'denied-ran';
		return array( 'ok' => true );
	},
);
$GLOBALS['blzmcp_rc_mode'] = '';
$perm_allow                 = static fn() => true;
$perm_deny                  = static fn() => false;

$register = static function () use ( $orig, $perm_allow, $perm_deny ): void {
	register_rest_route( 'blzmcp-test', '/a', array( 'methods' => 'GET', 'callback' => $orig['a'], 'permission_callback' => $perm_allow ) );
	register_rest_route( 'blzmcp-test', '/b', array( 'methods' => 'GET', 'callback' => $orig['b'], 'permission_callback' => $perm_allow ) );
	register_rest_route( 'blzmcp-test', '/neither', array( 'methods' => 'GET', 'callback' => $orig['neither'], 'permission_callback' => $perm_allow ) );
	register_rest_route( 'blzmcp-test', '/denied', array( 'methods' => 'GET', 'callback' => $orig['denied'], 'permission_callback' => $perm_deny ) );
};
add_action( 'rest_api_init', $register );

// Force a fresh server so rest_api_init fires with our routes, and restore afterwards.
$GLOBALS['wp_rest_server'] = null;
blzmcp_it_cleanup(
	static function () use ( $register ): void {
		remove_action( 'rest_api_init', $register );
		$GLOBALS['wp_rest_server'] = null;
	}
);
$server = rest_get_server();

$dispatch = static function ( string $route, array $params = array() ): WP_REST_Response {
	$GLOBALS['blzmcp_rc_log'] = array();
	$req                      = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
};

// Narrow wrapping.
$routes = $server->get_routes();
blzmcp_it_assert( isset( $routes['/blzmcp-test/a'], $routes['/blzmcp-test/neither'] ), 'test routes registered' );
blzmcp_it_assert( $routes['/blzmcp-test/a'][0]['callback'] !== $orig['a'], 'wrapped route a has a wrapped callback' );
blzmcp_it_assert( $routes['/blzmcp-test/b'][0]['callback'] !== $orig['b'], 'wrapped route b has a wrapped callback' );
blzmcp_it_assert( $routes['/blzmcp-test/neither'][0]['callback'] === $orig['neither'], 'unmapped route keeps its original callback' );
blzmcp_it_assert( $routes['/blzmcp-test/denied'][0]['permission_callback'] === $perm_deny, 'wrapped denied route keeps its permission_callback' );
blzmcp_it_assert( $routes['/blzmcp-test/a'][0]['permission_callback'] === $perm_allow, 'wrapped route a keeps its permission_callback' );
blzmcp_it_assert( $routes['/blzmcp-test/b'][0]['permission_callback'] === $perm_allow, 'wrapped route b keeps its permission_callback' );

// Sequential.
$dispatch( '/blzmcp-test/a' );
$seq1 = $GLOBALS['blzmcp_rc_log'];
$dispatch( '/blzmcp-test/b' );
$seq2 = $GLOBALS['blzmcp_rc_log'];
blzmcp_it_equals( array( "a:'recommended'" ), $seq1, 'sequential: A records recommended' );
blzmcp_it_equals( array( "b:'easy-mcp'" ), $seq2, 'sequential: then B records easy-mcp' );
blzmcp_it_equals( null, RequestContext::current(), 'after dispatch: current() is null' );

// Nested A -> B -> A.
$dispatch( '/blzmcp-test/a', array( 'mode' => 'nested' ) );
blzmcp_it_equals( array( "a:'recommended'", "b:'easy-mcp'", "a-after-b:'recommended'" ), $GLOBALS['blzmcp_rc_log'], 'nested: A, B, then A again after B returns' );
blzmcp_it_equals( null, RequestContext::current(), 'after nested dispatch: current() is null' );

// Neither.
$dispatch( '/blzmcp-test/neither' );
blzmcp_it_equals( array( 'n:NULL' ), $GLOBALS['blzmcp_rc_log'], 'neither: unmapped route records null' );

// Permission denied.
$res = $dispatch( '/blzmcp-test/denied' );
blzmcp_it_assert( $res->is_error() || $res->get_status() >= 400, 'denied route is refused' );
blzmcp_it_assert( ! in_array( 'denied-ran', $GLOBALS['blzmcp_rc_log'], true ), 'denied wrapped route callback never ran' );
blzmcp_it_equals( null, RequestContext::current(), 'permission denied: current() is null afterwards' );

// Exception caught in A (round 5 a).
$GLOBALS['blzmcp_rc_mode']   = 'catch';
$GLOBALS['blzmcp_rc_b_runs'] = 0;
$dispatch( '/blzmcp-test/a', array( 'mode' => 'catch' ) );
$GLOBALS['blzmcp_rc_mode'] = '';
blzmcp_it_equals( array( "a:'recommended'", "b:'easy-mcp'", "a-after-catch:'recommended'" ), $GLOBALS['blzmcp_rc_log'], 'caught in A: current() is recommended right after the catch' );
blzmcp_it_equals( 1, $GLOBALS['blzmcp_rc_b_runs'], 'caught in A: B callback ran exactly once' );
blzmcp_it_assert( null !== $GLOBALS['blzmcp_rc_caught'] && $GLOBALS['blzmcp_rc_caught'] === $GLOBALS['blzmcp_rc_thrown'], 'caught in A: caught exception is the same object B threw' );
blzmcp_it_equals( null, RequestContext::current(), 'caught in A: current() null after dispatch' );

// Exception at top level (round 5 b; the workflow-naming half moves to Task 6).
$top = null;
try {
	$dispatch( '/blzmcp-test/a', array( 'mode' => 'throw' ) );
} catch ( \RuntimeException $e ) {
	$top = $e;
}
blzmcp_it_assert( null !== $top && 'top-level' === $top->getMessage(), 'top level: exception propagates out of rest_do_request unchanged' );
blzmcp_it_equals( null, RequestContext::current(), 'top level: current() is null after the exception' );
$dispatch( '/blzmcp-test/neither' );
blzmcp_it_equals( array( 'n:NULL' ), $GLOBALS['blzmcp_rc_log'], 'top level: a later dispatch still sees null' );
blzmcp_it_equals( null, RequestContext::current(), 'top level: still null after that dispatch' );

// run() direct: returns value, runs once, and restores the frame when the callback throws.
$n = 0;
blzmcp_it_equals( 'x', RequestContext::run( 'easy-mcp', static function () use ( &$n ) { ++$n; return 'x'; } ), 'run returns the callback value' );
blzmcp_it_equals( 1, $n, 'run calls the callback once' );
$direct = null;
try {
	RequestContext::run( 'easy-mcp', static function () { throw new \RuntimeException( 'direct' ); } );
} catch ( \RuntimeException $e ) {
	$direct = $e;
}
blzmcp_it_assert( null !== $direct && 'direct' === $direct->getMessage(), 'run: exception propagates unchanged' );
blzmcp_it_equals( null, RequestContext::current(), 'run: frame restored after a throw' );

blzmcp_it_finish();
