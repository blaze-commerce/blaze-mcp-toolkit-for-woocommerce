<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Diagnostics\ErrorGrouper;
use PHPUnit\Framework\TestCase;

final class ErrorGrouperTest extends TestCase {

	private const KEYS = array( 'severity', 'message', 'count', 'first_seen', 'last_seen', 'component', 'source' );

	public function test_groups_and_counts_repeated_fatals(): void {
		$lines = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$lines[] = sprintf( '[21-Oct-2026 10:4%d:00 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in wp-content/plugins/woo-foo/x.php:%d', $i, 10 + $i );
		}
		$groups = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		$this->assertCount( 1, $groups );
		$g = $groups[0];
		$this->assertSame( self::KEYS, array_keys( $g ) );
		$this->assertSame( 5, $g['count'] );
		$this->assertSame( 'fatal', $g['severity'] );
		$this->assertSame( 'wp_debug', $g['source'] );
		$this->assertSame( gmdate( 'c', strtotime( '2026-10-21 10:41:00 UTC' ) ), $g['first_seen'] );
		$this->assertSame( gmdate( 'c', strtotime( '2026-10-21 10:45:00 UTC' ) ), $g['last_seen'] );
		$this->assertSame( 'plugin:woo-foo', $g['component'] );
		$this->assertStringNotContainsString( 'PHP Fatal error', $g['message'] );
	}

	public function test_component_attribution(): void {
		$lines = array(
			'[21-Oct-2026 10:00:00 UTC] PHP Warning:  bad in wp-content/plugins/woo-foo/x.php on line 3',
			'[21-Oct-2026 10:00:01 UTC] PHP Warning:  worse in wp-content/themes/blocksy/x.php on line 3',
			'[21-Oct-2026 10:00:02 UTC] PHP Notice:  mu in wp-content/mu-plugins/loader.php on line 3',
			'[21-Oct-2026 10:00:03 UTC] PHP Deprecated:  core in wp-includes/class-wp.php on line 3',
			'[21-Oct-2026 10:00:04 UTC] PHP Deprecated:  admin in wp-admin/edit.php on line 3',
			'[21-Oct-2026 10:00:05 UTC] PHP Notice:  nowhere at all',
		);
		$by = array();
		foreach ( ErrorGrouper::group( $lines, 0, 'wp_debug' ) as $g ) {
			$by[ $g['severity'] . '|' . substr( $g['message'], 0, 4 ) ] = $g['component'];
		}
		$this->assertSame( 'plugin:woo-foo', $by['warning|bad '] );
		$this->assertSame( 'theme:blocksy', $by['warning|wors'] );
		$this->assertSame( 'mu-plugin:loader.php', $by['notice|mu i'] );
		$this->assertSame( 'core', $by['deprecated|core'] );
		$this->assertSame( 'core', $by['deprecated|admi'] );
		$this->assertSame( 'unknown', $by['notice|nowh'] );
	}

	public function test_since_filter(): void {
		$lines = array(
			'[21-Oct-2026 09:00:00 UTC] PHP Warning:  old thing',
			'    #0 continuation of old',
			'[21-Oct-2026 11:00:00 UTC] PHP Warning:  new thing',
		);
		$since = strtotime( '2026-10-21 10:00:00 UTC' );
		$g     = ErrorGrouper::group( $lines, $since, 'wp_debug' );
		$this->assertCount( 1, $g );
		$this->assertSame( 'new thing', $g[0]['message'] );
	}

	public function test_severity_map(): void {
		$lines = array(
			'[21-Oct-2026 10:00:00 UTC] PHP Fatal error:  a',
			'[21-Oct-2026 10:00:00 UTC] PHP Parse error:  b',
			'[21-Oct-2026 10:00:00 UTC] PHP Warning:  c',
			'[21-Oct-2026 10:00:00 UTC] PHP Notice:  d',
			'[21-Oct-2026 10:00:00 UTC] PHP Deprecated:  e',
			'[21-Oct-2026 10:00:00 UTC] WordPress database error Something for query',
		);
		$sev = array_column( ErrorGrouper::group( $lines, 0, 'wp_debug' ), 'severity', 'message' );
		$this->assertSame( 'fatal', $sev['a'] );
		$this->assertSame( 'fatal', $sev['b'] );
		$this->assertSame( 'warning', $sev['c'] );
		$this->assertSame( 'notice', $sev['d'] );
		$this->assertSame( 'deprecated', $sev['e'] );
		$this->assertSame( 'error', $sev['WordPress database error Something for query'] );
	}

	public function test_woocommerce_log_format(): void {
		$lines = array(
			'2026-10-21T10:42:00+00:00 CRITICAL Uncaught Error: Call to undefined function bar() in wp-content/plugins/woo-bar/b.php:7',
			'Stack trace:',
			'#0 wp-includes/class-wp-hook.php(324): woo_bar()',
			'2026-10-21T10:43:00+00:00 CRITICAL Uncaught Error: Call to undefined function bar() in wp-content/plugins/woo-bar/b.php:9',
			'2026-10-21T10:44:00+00:00 ERROR Something failed 123',
			'2026-10-21T10:45:00+00:00 INFO ignore me',
			'2026-10-21T10:46:00+00:00 EMERGENCY boom',
		);
		$g = ErrorGrouper::group( $lines, 0, 'wc_fatal' );
		$this->assertCount( 3, $g );
		$this->assertSame( 2, $g[0]['count'] );
		$this->assertSame( 'fatal', $g[0]['severity'] );
		$this->assertSame( 'plugin:woo-bar', $g[0]['component'] );
		$sev = array_column( $g, 'severity' );
		sort( $sev );
		$this->assertSame( array( 'error', 'fatal', 'fatal' ), $sev );
	}

	public function test_messages_are_redacted_and_capped_with_continuations(): void {
		$lines = array(
			'[21-Oct-2026 10:00:00 UTC] PHP Fatal error:  Failed for jane@example.com ' . str_repeat( 'y', 400 ),
			'#0 contact bob@example.com Authorization: Bearer abcdef1234567890abcdef',
			'[21-Oct-2026 10:00:01 UTC] PHP Warning:  short',
			'#1 bob@example.com',
			'orphan continuation before nothing',
		);
		$g = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		foreach ( $g as $row ) {
			$this->assertLessThanOrEqual( 300, mb_strlen( $row['message'] ) );
			$this->assertStringNotContainsString( '@example.com', $row['message'] );
			$this->assertStringNotContainsString( 'abcdef1234567890', $row['message'] );
		}
		$short = array_values( array_filter( $g, fn( $r ) => 'warning' === $r['severity'] ) )[0];
		$this->assertStringStartsWith( 'short', $short['message'] );
		$this->assertStringNotContainsString( 'bob@', $short['message'] );
	}

	public function test_leading_continuation_is_dropped_and_blank_lines_ignored(): void {
		$this->assertSame( array(), ErrorGrouper::group( array( '', 'stray', '   ' ), 0, 'wp_debug' ) );
	}

	public function test_limits_to_50_groups_sorted_by_count(): void {
		$lines = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$word    = chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + ( $i % 26 ) );
			$lines[] = "[21-Oct-2026 10:00:00 UTC] PHP Warning:  unique {$word} word";
		}
		$lines[] = '[21-Oct-2026 10:00:00 UTC] PHP Notice:  frequent';
		$lines[] = '[21-Oct-2026 10:00:01 UTC] PHP Notice:  frequent';
		$g       = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		$this->assertCount( 50, $g );
		$this->assertSame( 'frequent', $g[0]['message'] );
		$counts = array_column( $g, 'count' );
		$sorted = $counts;
		rsort( $sorted );
		$this->assertSame( $sorted, $counts );
	}

	public function test_normalisation_groups_numbers_hex_and_quoted_values(): void {
		$lines = array(
			'[21-Oct-2026 10:00:00 UTC] PHP Warning:  Undefined array key "foo" in order 123 id 0xdeadbeef',
			'[21-Oct-2026 10:00:01 UTC] PHP Warning:  Undefined array key "bar" in order 456 id 0xcafebabe',
		);
		$this->assertCount( 1, ErrorGrouper::group( $lines, 0, 'wp_debug' ) );
	}

	public function test_contractions_do_not_merge_distinct_messages(): void {
		$lines = array(
			"[21-Oct-2026 10:00:00 UTC] PHP Warning:  Can't connect to Stripe; plugin isn't ready",
			"[21-Oct-2026 10:00:01 UTC] PHP Warning:  Can't load template foo; theme isn't ready",
		);
		$this->assertCount( 2, ErrorGrouper::group( $lines, 0, 'wp_debug' ) );
	}

	public function test_pem_body_in_continuations_is_dropped(): void {
		$lines = array(
			'[21-Oct-2026 10:00:00 UTC] PHP Fatal error:  boom in wp-content/plugins/woo-foo/x.php:1',
			'-----BEGIN RSA PRIVATE KEY-----',
			'MIIEowIBAAKCAQEAsupersecretkeymaterialAAAA',
			'bW9yZXNlY3JldGtleW1hdGVyaWFs',
			'-----END RSA PRIVATE KEY-----',
			'#0 after key',
		);
		$g = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		$this->assertStringNotContainsString( 'supersecret', $g[0]['message'] );
		$this->assertStringNotContainsString( 'bW9yZX', $g[0]['message'] );
		$this->assertStringContainsString( 'after key', $g[0]['message'] );
	}

	public function test_fatal_survives_group_cap(): void {
		$lines = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$word = chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + ( $i % 26 ) );
			$lines[] = "[21-Oct-2026 10:00:00 UTC] PHP Deprecated:  old {$word} thing";
			$lines[] = "[21-Oct-2026 10:00:01 UTC] PHP Deprecated:  old {$word} thing";
		}
		$lines[] = '[21-Oct-2026 10:00:02 UTC] PHP Fatal error:  the one fatal';
		$g       = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		$this->assertCount( 50, $g );
		$this->assertContains( 'the one fatal', array_column( $g, 'message' ) );
		$counts = array_column( $g, 'count' );
		$sorted = $counts;
		rsort( $sorted );
		$this->assertSame( $sorted, $counts );
	}

	public function test_digits_inside_paths_are_not_normalised(): void {
		$lines = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$lines[] = "[21-Oct-2026 10:00:00 UTC] PHP Warning:  bad in wp-content/plugins/p{$i}/x.php on line 4{$i}";
			$lines[] = "[21-Oct-2026 10:00:00 UTC] PHP Warning:  bad in wp-content/plugins/q/x{$i}.php:1{$i}";
		}
		$g = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		$this->assertCount( 5 + 5, $g ); // p0..p4 stay apart; q/x0..x4 stay apart.
	}

	public function test_xdebug_trace_lines_are_continuations(): void {
		$lines = array(
			'[21-Oct-2026 10:00:00 UTC] PHP Warning:  boom',
			'[21-Oct-2026 10:00:00 UTC] PHP Stack trace:',
			'[21-Oct-2026 10:00:00 UTC] PHP   1. {main}() /www/wp-content/plugins/woo-foo/x.php:0',
		);
		$g = ErrorGrouper::group( $lines, 0, 'wp_debug' );
		$this->assertCount( 1, $g );
		$this->assertSame( 'warning', $g[0]['severity'] );
	}
	/**
	 * @return string[]
	 */
	private static function distinct_lines( string $level, string $prefix, int $n, int $repeat = 1 ): array {
		$stamp = '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] ';
		$lines = array();
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $r = 0; $r < $repeat; $r++ ) {
				$lines[] = $stamp . 'PHP ' . $level . ': ' . $prefix . '_' . chr( 65 + intdiv( $i, 26 ) ) . chr( 65 + $i % 26 );
			}
		}
		return $lines;
	}

	public function test_group_all_keeps_every_group_and_group_caps_at_50(): void {
		$lines = self::distinct_lines( 'Warning', 'component', 60 );
		$this->assertCount( 60, ErrorGrouper::group_all( $lines, 0, 'wp_debug' ) );
		$this->assertCount( 50, ErrorGrouper::group( $lines, 0, 'wp_debug' ) );
	}

	public function test_limit_groups_keeps_fatals_and_errors_first(): void {
		$lines = array_merge(
			self::distinct_lines( 'Deprecated', 'dep', 50, 3 ),
			self::distinct_lines( 'Fatal error', 'fatal', 2 ),
			self::distinct_lines( 'Warning', 'warn', 5, 2 )
		);
		$kept  = ErrorGrouper::limit_groups( ErrorGrouper::group_all( $lines, 0, 'wp_debug' ), 50 );
		$sev   = array_count_values( array_column( $kept, 'severity' ) );
		$this->assertCount( 50, $kept );
		$this->assertSame( 2, $sev['fatal'] ?? 0 );
	}
	/**
	 * Multi-line cookie arrays in one log entry (review I2).
	 *
	 * @return array<string,array{0:array<int,string>}>
	 */
	public static function multiline_cookie_dumps(): array {
		$h = '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] PHP Warning: API response ';
		return array(
			'print_r'      => array(
				array(
					$h . 'Array',
					'(',
					'    [headers] => Array',
					'        (',
					'            [set-cookie] => Array',
					'                (',
					'                    [0] => zz_auth=SYNTH_A; path=/; HttpOnly',
					'                    [1] => other=SYNTH_B',
					'                )',
					'',
					'            [content-type] => text/html',
					'        )',
					')',
				),
			),
			'var_export'   => array(
				array(
					$h . 'array (',
					"  'headers' => ",
					'  array (',
					"    'Set-Cookie' => ",
					'    array (',
					"      0 => 'zz_auth=SYNTH_A; path=/',",
					"      1 => 'other=SYNTH_B',",
					'    ),',
					"    'content-type' => 'text/html',",
					'  ),',
					')',
				),
			),
			'pretty json'  => array(
				array(
					$h . '{',
					'    "headers": {',
					'        "set-cookie": [',
					'            "zz_auth=SYNTH_A; path=/; HttpOnly",',
					'            "other=SYNTH_B"',
					'        ],',
					'        "content-type": "text/html"',
					'    }',
					'}',
				),
			),
			'json object'  => array(
				array(
					$h . '{',
					'    "cookies": 1,',
					'    "Cookie": {',
					'        "zz_auth": "SYNTH_A",',
					'        "other": "SYNTH_B"',
					'    },',
					'    "content-type": "text/html"',
					'}',
				),
			),
			'server dump'  => array(
				array(
					$h . 'Array',
					'(',
					'    [HTTP_COOKIE] => zz_auth=SYNTH_A; other=SYNTH_B',
					'    [HTTP_HOST] => example.com',
					')',
				),
			),
			'unclosed'     => array(
				array(
					$h . '"set-cookie": [',
					'    "zz_auth=SYNTH_A",',
					'    "other=SYNTH_B"',
				),
			),
		);
	}

	/**
	 * @dataProvider multiline_cookie_dumps
	 * @param array<int,string> $lines Lines.
	 */
	public function test_multiline_cookie_values_are_redacted( array $lines ): void {
		$groups = ErrorGrouper::group_all( $lines, 0, 'wp_debug' );
		$this->assertCount( 1, $groups );
		$this->assertStringNotContainsString( 'SYNTH_', $groups[0]['message'] );
		$this->assertStringContainsString( '[secret]', $groups[0]['message'] );
	}

	public function test_multiline_cookie_block_keeps_following_fields(): void {
		$groups = ErrorGrouper::group_all( self::multiline_cookie_dumps()['print_r'][0], 0, 'wp_debug' );
		$this->assertStringContainsString( '[content-type] => text/html', $groups[0]['message'] );
	}
}
