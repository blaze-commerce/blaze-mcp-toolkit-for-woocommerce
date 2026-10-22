<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Diagnostics\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

	private const ABSPATH     = '/www/site_123/public/';
	private const CONTENT_DIR = '/www/site_123/public/wp-content';

	private function redact( string $line ): string {
		return Redactor::redact( $line, self::ABSPATH, self::CONTENT_DIR );
	}

	/**
	 * @return array<int,string>
	 */
	private function fixture_lines(): array {
		$lines = file( __DIR__ . '/../fixtures/log-lines.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertIsArray( $lines );
		return $lines;
	}

	/**
	 * Patterns that must never survive redaction.
	 *
	 * @return array<string,string>
	 */
	private function sensitive_patterns(): array {
		return array(
			'email'            => '/[A-Z0-9._%+-]+(?:@|%40)[A-Z0-9.-]+\.[A-Z]{2,}/i',
			'card'             => '/\b(?:\d[ -]?){13,19}\b/',
			'sk'               => '/sk_(live|test)_/',
			'pk'               => '/pk_(live|test)_/',
			'rk'               => '/rk_(live|test)_/',
			'whsec'            => '/whsec_/',
			'bearer'           => '/Bearer\s+\S{8,}/',
			'basic'            => '/Basic\s+[A-Za-z0-9+\/]{8,}/',
			'jwt'              => '/eyJ[\w-]+\.[\w-]+\.[\w-]+/',
			'wp cookie'        => '/wordpress_[a-z_]*[0-9a-f]{6,}[^=\s]*=\S+/i',
			'logged in cookie' => '/wordpress_logged_in_[^=]*=\S+/',
			'wc session'       => '/wp_woocommerce_session_\w*=\S+/',
			'cvv'              => '/cvv=?\d/i',
			'cvc'              => '/cvc"?\s*:\s*"?\d/i',
			'auth key'         => "/AUTH_KEY'\\s*,\\s*'[^']+/",
			'define value'     => '/define\(\s*[\'"][A-Z_]+[\'"]\s*,\s*[\'"][^\'"\[]/',
			'db password'      => '/DB_PASSWORD[\'"]?\s*(?:,|=>|=|:)\s*[\'"]?[^\s\'"\[]/',
			'wpnonce'          => '/_wpnonce=\w+/',
			'ajax nonce'       => '/_ajax_nonce=\w+/',
			'secret kv'        => '/(?:client_secret|api_key|password|token)["\']?\s*[:=]\s*["\']?[^\s"\'\[&,]/i',
			'ipv4'             => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
			'ipv6'             => '/[0-9a-f]{1,4}::[0-9a-f]{1,4}|::1\b/i',
			'wc order key'     => '/wc_order_\w+/',
			'phone'            => '/\+\d[\d ()-]{7,}\d|\(\d{2,3}\)\s?\d{3,4}[ -]\d{4}/',
			'aws'              => '/AKIA[0-9A-Z]{12,}/',
			'github'           => '/ghp_\w{10,}/',
			'slack'            => '/xox[bap]-\d/',
			'private key'      => '/BEGIN [A-Z ]*PRIVATE KEY-----\S/',
			'stream path'      => '#(?:phar|file)://(?:/|[A-Za-z]:)#i',
			'abs path'         => '/(?<![\w:\/.])\/(?:home|www|Users)\//',
			'windows path'     => '/[A-Z]:\\\\Users/',
			'wc cs key'        => '/cs_[a-f0-9]{40}/',
			'card after colon' => '/\b[2-6]\d{15}\b/',
			'card dotted'      => '/\d{4}\.\d{4}\.\d{4}\.\d{4}/',
			'gateway pii'      => '/Melbourne|Jane|Doe\b|jdoe|janed|jane-doe|Example St|Unit 4|"postal_code":"3|"state":"VIC/',
			'multi-word value' => '/Der Berg|\bBerg\b|horse|battery|staple|Example Street/',
			'escaped quote'    => '/ ghi"|def ghi|abc def/',
			'frame db creds'   => "/hunter2xyz|'wp_user'/",
			'keyed basic'      => '/dXNlcjpwYXNz/',
			'user_pass hash'   => '/\$P\$B/',
			'var_dump value'   => '/string\(\d+\) "/',
			'dsn slash'        => '/pa\/ss/',
			'url credentials'  => '/:\/\/[^\s\/]*:[^\s\/]*@|s3cretPass|p!ss/',
			'square'           => '/EAAA[\w-]{10,}/',
			'braintree'        => '/access_token\$(?:production|sandbox)\$/',
			'mollie'           => '/\b(?:live|test)_[A-Za-z0-9]{20,}/',
			'signature'        => '/signature["\']?\s*[:=]\s*["\']?[^\s"\'\[&,]/i',
			'card_cvc'         => '/card_cvc\s*=\s*\d/',
			'cvv spaced'       => '/\b(?:cvv|cvc)\s+\d{3}/i',
			'pass1 pass2'      => '/\bpass[12]=[^\[]/',
			'shopify'          => '/shp(?:at|ss)_\w/',
			'twilio'           => '/\bSK[0-9a-f]{32}/i',
		);
	}

	public function test_fixture_has_no_sensitive_patterns_after_redaction(): void {
		$lines = $this->fixture_lines();
		$this->assertGreaterThanOrEqual( 25, count( $lines ) );
		foreach ( $lines as $line ) {
			$out = $this->redact( $line );
			foreach ( $this->sensitive_patterns() as $name => $regex ) {
				$this->assertSame( 0, preg_match( $regex, $out ), "Pattern '$name' survived in: $out (from: $line)" );
			}
		}
	}

	public function test_fixture_actually_triggers_each_pattern(): void {
		// Guard against a fixture that passes only because it never contained the data.
		$raw = implode( "\n", $this->fixture_lines() );
		foreach ( $this->sensitive_patterns() as $name => $regex ) {
			$this->assertSame( 1, preg_match( $regex, $raw ), "Fixture never exercises '$name'" );
		}
	}

	public function test_wp_content_paths_kept_relative(): void {
		$this->assertSame(
			'in wp-content/plugins/foo/bar.php:45',
			$this->redact( 'in /www/site_123/public/wp-content/plugins/foo/bar.php:45' )
		);
	}

	public function test_wp_content_relative_even_when_host_path_differs(): void {
		$this->assertSame(
			'in wp-content/plugins/foo/bar.php on line 3',
			$this->redact( 'in /srv/other/root/wp-content/plugins/foo/bar.php on line 3' )
		);
	}

	public function test_core_paths_relative_to_abspath(): void {
		$this->assertSame(
			'#0 wp-includes/class-wp-hook.php(324): foo()',
			$this->redact( '#0 /www/site_123/public/wp-includes/class-wp-hook.php(324): foo()' )
		);
	}

	public function test_paths_outside_wp_content_masked(): void {
		$this->assertSame( '[path]', $this->redact( '/home/user/secret.txt' ) );
		$this->assertSame( 'open [path]: failed', $this->redact( 'open /var/lib/mysql/data.sock: failed' ) );
	}

	public function test_stream_wrapper_paths_masked(): void {
		$this->assertSame(
			"in phar://[path](85) : eval()'d code:21",
			$this->redact( "in phar:///usr/local/bin/wp/vendor/wp-cli/eval-command/src/EvalFile_Command.php(85) : eval()'d code:21" )
		);
		$this->assertSame( 'read file://[path] failed', $this->redact( 'read file:///etc/passwd failed' ) );
		$this->assertSame( 'x PHAR://[path] y', $this->redact( 'x PHAR://C:/tools/wp.phar/a.php y' ) );
	}

	public function test_windows_paths(): void {
		$this->assertSame(
			'x wp-content/plugins/foo/bar.php y',
			$this->redact( 'x C:\\Users\\jane\\Sites\\shop\\wp-content\\plugins\\foo\\bar.php y' )
		);
		$this->assertSame( 'x [path] y', $this->redact( 'x C:\\Users\\jane\\notes.txt y' ) );
	}

	public function test_urls_and_url_paths_survive(): void {
		$url = 'GET https://shop.example.com/wp-content/uploads/2026/10/a.png and /wp-json/wc/v3/orders failed';
		$this->assertSame( $url, $this->redact( $url ) );
	}

	public function test_plain_error_text_unchanged(): void {
		$line = 'PHP Warning: Undefined array key "x"';
		$this->assertSame( $line, $this->redact( $line ) );
	}

	public function test_realistic_fatal_keeps_timestamp_message_and_line_number(): void {
		$line = '[21-Oct-2026 10:42:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo_init() in /www/site_123/public/wp-content/plugins/foo/bar.php on line 123';
		$this->assertSame(
			'[21-Oct-2026 10:42:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo_init() in wp-content/plugins/foo/bar.php on line 123',
			$this->redact( $line )
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function safe_lines(): array {
		return array(
			'versions'            => array( 'WooCommerce 11.0.1 requires PHP 8.2.31 or newer (WP 6.9.4)' ),
			'iso timestamp'       => array( '2026-10-21T10:42:00+00:00 INFO cron ran' ),
			'timestamp with zone' => array( '[21-Oct-2026 10:42:00 +0000] PHP Notice: done' ),
			'order id'            => array( 'Order #1234 status changed to processing' ),
			'line number'         => array( 'Error in thing on line 123' ),
			'ms epoch'            => array( 'microtime 1729507320123 elapsed 0.35s' ),
			'unix time'           => array( 'next run 1729507320 in 3600s' ),
			'method call'         => array( 'Call to undefined method WC_Cart::add_fee() in Cache::get' ),
			'prose basic'         => array( 'Basic configuration saved; Basic information missing' ),
			'prose bearer'        => array( 'Bearer token missing from request' ),
			'money'               => array( 'Total 1,234,567.89 AUD for 12 items' ),
			'time only'           => array( 'took 10:42:00 to finish' ),
			'slash prose'         => array( 'and/or 10/21/2026 read/write failed' ),
			'live_ and test_ hook' => array( 'do_action live_preview_init then test_mode_enabled_for_checkout and live_site_url' ),
			'short sk word'       => array( 'SKU SK123 skipped; Task completed' ),
		);
	}

	/**
	 * @dataProvider safe_lines
	 */
	public function test_safe_text_unchanged( string $line ): void {
		$this->assertSame( $line, $this->redact( $line ) );
	}

	public function test_tokens_per_class(): void {
		$this->assertSame( 'k [secret]', $this->redact( 'k sk_live_51Habc' ) );
		$this->assertSame( 'e [email].', $this->redact( 'e jane@example.com.' ) );
		$this->assertSame( 'c [card]', $this->redact( 'c 4111111111111111' ) );
		$this->assertSame( 'c [card]', $this->redact( 'c 4111 1111 1111 1111' ) );
		$this->assertSame( 'p [phone]', $this->redact( 'p +61 412 345 678' ) );
		$this->assertSame( 'p [phone]', $this->redact( 'p +1 (555) 123-4567' ) );
		$this->assertSame( 'n _wpnonce=[nonce]', $this->redact( 'n _wpnonce=abc123def4' ) );
		$this->assertSame( 'i [ip] and [ip]', $this->redact( 'i 203.0.113.42 and 2001:db8::1' ) );
	}

	public function test_define_keeps_name_hides_value(): void {
		$this->assertSame( "define('AUTH_KEY', [secret]);", $this->redact( "define('AUTH_KEY','abc def');" ) );
	}

	public function test_idempotent(): void {
		foreach ( $this->fixture_lines() as $line ) {
			$once = $this->redact( $line );
			$this->assertSame( $once, $this->redact( $once ), "Not idempotent: $line" );
		}
	}

	public function test_defaults_use_constants(): void {
		$this->assertSame( 'a [email]', Redactor::redact( 'a x@example.com' ) );
	}

	public function test_print_r_values_consume_to_next_key(): void {
		$this->assertSame( '[last_name] => [pii] [city] => [pii]', $this->redact( '[last_name] => Van Der Berg [city] => Melbourne' ) );
		$this->assertSame( '(Array ([password] => [secret]))', $this->redact( '(Array ([password] => correct horse battery staple))' ) );
		$this->assertSame( '{"password":[secret]}', $this->redact( '{"password":"abc\"def ghi"}' ) );
	}

	public function test_bare_name_and_state_only_as_keys(): void {
		$this->assertSame( 'Plugin name loaded; state saved; name=foo', $this->redact( 'Plugin name loaded; state saved; name=foo' ) );
		$this->assertSame( '{"name":[pii]}', $this->redact( '{"name":"Jane Doe"}' ) );
	}

	public function test_stack_frame_strings_masked_but_structure_kept(): void {
		$this->assertSame(
			"#0 wp-includes/x.php(5): wpdb->__construct('[secret]', '[secret]', 12)",
			$this->redact( "#0 /www/site_123/public/wp-includes/x.php(5): wpdb->__construct('wp_user', 'hunter2', 12)" )
		);
	}

	public function test_url_credentials(): void {
		$this->assertSame( 'mysql://[secret]@localhost/wp', $this->redact( 'mysql://wp_user:hunter2@localhost/wp' ) );
		$this->assertSame( 'redis://[secret]@[ip]:6379', $this->redact( 'redis://:s3cret@127.0.0.1:6379' ) );
		$this->assertSame( 'https://shop.example.com/?a=1', $this->redact( 'https://shop.example.com/?a=1' ) );
	}

	public function test_oversized_input_is_truncated_and_does_not_leak(): void {
		$line = 'sk_live_' . str_repeat( 'A', 1048576 );
		$out  = $this->redact( $line );
		$this->assertNotSame( '', $out );
		$this->assertLessThanOrEqual( 4096, strlen( $out ) );
		$this->assertSame( 0, preg_match( '/sk_live_|AAAA/', $out ) );
		$this->assertNotSame( '', $this->redact( str_repeat( 'a', 1048576 ) ) );
	}

	public function test_pcre_failure_fails_closed(): void {
		$old_limit = ini_get( 'pcre.backtrack_limit' );
		$old_jit   = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$out = $this->redact( 'Password=hunter2 and sk_live_51Habc and jane@example.com ' . str_repeat( 'x', 500 ) );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $old_limit );
			ini_set( 'pcre.jit', (string) $old_jit );
		}
		$this->assertSame( '[redaction failed]', $out );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function boundary_cuts(): array {
		return array(
			'password'  => array( '"password":"hunter2xyz and more words"', 'hun' ),
			'last_name' => array( '"last_name":"Van Der Berg"', 'Van' ),
			'cs key'    => array( 'cs_0123456789abcdef0123456789abcdef01234567', 'cs_0123456789a' ),
			'single'    => array( "'password' => 'hunter2xyz'", 'hun' ),
		);
	}

	/**
	 * Truncation at 4096 can cut inside a value; the leading part must still be masked.
	 *
	 * @dataProvider boundary_cuts
	 */
	public function test_value_cut_at_truncation_boundary_is_masked( string $payload, string $leak ): void {
		$cut  = (int) strpos( $payload, $leak ) + strlen( $leak );
		$line = str_repeat( ' ', 4096 - $cut ) . $payload . str_repeat( ' tail', 10 );
		$line = substr( $line, 0, 4096 + 0 );
		$this->assertSame( 4096, strlen( $line ) );
		$out = $this->redact( $line . 'ZZZ' );
		$this->assertStringNotContainsString( $leak, $out );
	}

	public function test_unterminated_value_masked(): void {
		$this->assertSame( '{"password":[secret]', $this->redact( '{"password":"hunter2 more words' ) );
	}

	public function test_user_pass_pass_and_var_dump(): void {
		$this->assertSame( '[user_pass] => [secret]', $this->redact( '[user_pass] => $P$Babcdefghijk' ) );
		$this->assertSame( '["last_name"]=> string(12) [pii]', $this->redact( '["last_name"]=> string(12) "Van Der Berg"' ) );
		$this->assertSame( 'Test pass: ok bypass=1', $this->redact( 'Test pass: ok bypass=1' ) );
	}

	public function test_dsn_password_with_slash(): void {
		$this->assertSame( 'mysql://[secret]@h/db', $this->redact( 'mysql://u:pa/ss@h/db' ) );
		$this->assertSame( 'https://shop.example.com/x?e=[email]', $this->redact( 'https://shop.example.com/x?e=a@b.com' ) );
	}
	/**
	 * Cookie headers in structured dumps (audit F01). Generic session cookie names.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function structured_cookie_headers(): array {
		return array(
			'plain'                  => array( 'PHP Warning: Cookie: sessionid=SYNTH_SESSION_VALUE; csrftoken=SYNTH_CSRF', 'PHP Warning: Cookie: [secret]' ),
			'json'                   => array( '{"Cookie":"sessionid=SYNTH_SESSION_VALUE","x":1}', '{"Cookie":[secret],"x":1}' ),
			'json spaced lowercase'  => array( '{"cookie" : "PHPSESSID=SYNTH_SESSION_VALUE; other=SYNTH_OTHER", "ok": "kept"}', '{"cookie" : [secret], "ok": "kept"}' ),
			'escaped json'           => array( '{\"Cookie\":\"sessionid=SYNTH_SESSION_VALUE; b=SYNTH_OTHER\",\"x\":1}', '{\"Cookie\":[secret],\"x\":1}' ),
			'print_r'                => array( '[Cookie] => sessionid=SYNTH_SESSION_VALUE; b=SYNTH_OTHER', '[Cookie] => [secret]' ),
			'print_r array'          => array( '[Set-Cookie] => Array ( [0] => sessionid=SYNTH_SESSION_VALUE [1] => b=SYNTH_OTHER )', '[Set-Cookie] => [secret]' ),
			'var_export'             => array( "'Cookie' => 'sessionid=SYNTH_SESSION_VALUE; b=SYNTH_OTHER', 'Host' => 'kept'", "'Cookie' => [secret], 'Host' => 'kept'" ),
			'set-cookie json'        => array( '{"Set-Cookie":"sessionid=SYNTH_SESSION_VALUE; HttpOnly; Path=/"}', '{"Set-Cookie":[secret]}' ),
			'set-cookie json array'  => array( '{"Set-Cookie":["sessionid=SYNTH_SESSION_VALUE; HttpOnly","b=SYNTH_OTHER"],"ok":1}', '{"Set-Cookie":[secret],"ok":1}' ),
			'json array, ] inside a value'     => array( '{"Set-Cookie":["a=x]y","zz_auth=SYNTH_A"],"ok":1}', '{"Set-Cookie":[secret],"ok":1}' ),
			'json object value'                => array( '{"Cookie":{"zz_auth":"SYNTH_A","b":"}"},"ok":1}', '{"Cookie":[secret],"ok":1}' ),
			'set-cookie single-quoted escaped' => array( "{'set-cookie': 'JSESSIONID=SYNTH_SESSION_VALUE; Secure'}", "{'set-cookie': [secret]}" ),
		);
	}

	/**
	 * @dataProvider structured_cookie_headers
	 */
	public function test_structured_cookie_headers_redacted( string $line, string $expected ): void {
		$out = $this->redact( $line );
		$this->assertStringNotContainsString( 'SYNTH_', $out );
		$this->assertSame( $expected, $out );
	}

	public function test_generic_session_cookie_pairs_redacted_without_header_name(): void {
		foreach ( array( 'PHPSESSID', 'sessionid', 'JSESSIONID', 'session_id', 'laravel_session' ) as $name ) {
			$out = $this->redact( "[0] => {$name}=SYNTH_SESSION_VALUE; path=/" );
			$this->assertStringNotContainsString( 'SYNTH_', $out, $name );
		}
	}

	public function test_cookie_redaction_keeps_ordinary_error_useful(): void {
		$this->assertSame(
			'PHP Warning: Undefined index: cookie_consent in wp-content/plugins/foo/bar.php on line 3',
			$this->redact( 'PHP Warning: Undefined index: cookie_consent in /www/site_123/public/wp-content/plugins/foo/bar.php on line 3' )
		);
	}
	/**
	 * $_SERVER-style cookie keys (review I1).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function server_cookie_keys(): array {
		return array(
			'print_r'          => array( '[HTTP_COOKIE] => zz_auth=SYNTH_A; other=SYNTH_B', '[HTTP_COOKIE] => [secret]' ),
			'json'             => array( '{"HTTP_COOKIE":"zz_auth=SYNTH_A","x":1}', '{"HTTP_COOKIE":[secret],"x":1}' ),
			'escaped json'     => array( '{\"HTTP_COOKIE\":\"zz_auth=SYNTH_A\"}', '{\"HTTP_COOKIE\":[secret]}' ),
			'server array'     => array( "\$_SERVER['HTTP_COOKIE'] = zz_auth=SYNTH_A; b=SYNTH_B", "\$_SERVER['HTTP_COOKIE'] = [secret]" ),
			'var_export'       => array( "'HTTP_COOKIE' => 'zz_auth=SYNTH_A', 'HTTP_HOST' => 'kept'", "'HTTP_COOKIE' => [secret], 'HTTP_HOST' => 'kept'" ),
			'env plain'        => array( 'HTTP_COOKIE=zz_auth=SYNTH_A; b=SYNTH_B', 'HTTP_COOKIE=[secret]' ),
			'set cookie key'   => array( '[HTTP_SET_COOKIE] => zz_auth=SYNTH_A', '[HTTP_SET_COOKIE] => [secret]' ),
			'cookie equals'    => array( 'Cookie = zz_auth=SYNTH_A', 'Cookie = [secret]' ),
			'lowercase header' => array( 'cookie: zz_auth=SYNTH_A', 'cookie: [secret]' ),
		);
	}

	/**
	 * @dataProvider server_cookie_keys
	 */
	public function test_server_cookie_keys_redacted( string $line, string $expected ): void {
		$out = $this->redact( $line );
		$this->assertStringNotContainsString( 'SYNTH_', $out );
		$this->assertSame( $expected, $out );
	}

	public function test_cookie_class_references_are_not_redacted(): void {
		$this->assertSame(
			'PHP Fatal error: Uncaught Error: Call to undefined method Cookie::set() in wp-content/plugins/x/y.php:5',
			$this->redact( 'PHP Fatal error: Uncaught Error: Call to undefined method Cookie::set() in /www/site_123/public/wp-content/plugins/x/y.php:5' )
		);
		$this->assertSame(
			'Symfony\Component\HttpFoundation\Cookie::create(): Argument #1 ($name) must be of type string',
			$this->redact( 'Symfony\Component\HttpFoundation\Cookie::create(): Argument #1 ($name) must be of type string' )
		);
		$this->assertSame( 'use_cookie: true, cookies: 3', $this->redact( 'use_cookie: true, cookies: 3' ) );
	}
}
