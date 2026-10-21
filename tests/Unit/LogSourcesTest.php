<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Diagnostics\LogSources;
use PHPUnit\Framework\TestCase;

final class LogSourcesTest extends TestCase {

	/** @var string[] */
	private array $cleanup = array();

	private string $old_error_log = '';

	protected function setUp(): void {
		$this->old_error_log = (string) ini_get( 'error_log' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->old_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		foreach ( $this->cleanup as $path ) {
			if ( is_dir( $path ) ) {
				foreach ( (array) glob( $path . '/*' ) as $f ) {
					unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} elseif ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	private function tmp_file( string $content ): string {
		$f = tempnam( sys_get_temp_dir(), 'blzmcp' );
		file_put_contents( $f, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->cleanup[] = $f;
		return $f;
	}

	private function tmp_dir(): string {
		$d = sys_get_temp_dir() . '/blzmcp_' . uniqid();
		mkdir( $d ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$this->cleanup[] = $d;
		return $d;
	}

	private function by_id( array $sources, string $id ): array {
		foreach ( $sources as $s ) {
			if ( $s['id'] === $id ) {
				return $s;
			}
		}
		$this->fail( "Source $id missing" );
	}

	public function test_tail_reads_only_last_bytes(): void {
		$f = tempnam( sys_get_temp_dir(), 'blzmcp' );
		$this->cleanup[] = $f;
		$h = fopen( $f, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$line = str_repeat( 'x', 99 ) . "\n";
		for ( $i = 0; $i < 62000; $i++ ) { // About 6.2 MB.
			fwrite( $h, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		fwrite( $h, "LAST\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->assertGreaterThan( 6000000, filesize( $f ) );

		$lines = LogSources::tail( $f, 1024, array( realpath( $f ) ) );
		$this->assertNotEmpty( $lines );
		$this->assertSame( 'LAST', end( $lines ) );
		$this->assertLessThanOrEqual( 1024, strlen( implode( "\n", $lines ) ) );
		$this->assertSame( str_repeat( 'x', 99 ), $lines[0], 'First element is a whole line.' );
	}

	public function test_tail_small_file_keeps_first_line(): void {
		$f = $this->tmp_file( "one\r\ntwo\nthree" );
		$this->assertSame( array( 'one', 'two', 'three' ), LogSources::tail( $f, 5242880, array( realpath( $f ) ) ) );
	}

	public function test_tail_never_reads_more_than_5mb(): void {
		$f = $this->tmp_file( str_repeat( "abcdefghi\n", 700000 ) ); // 7 MB.
		$lines = LogSources::tail( $f, 999999999, array( realpath( $f ) ) );
		$this->assertLessThanOrEqual( 5242880, strlen( implode( "\n", $lines ) ) );
	}

	public function test_tail_failures_return_empty(): void {
		$this->assertSame( array(), LogSources::tail( '/nonexistent/blzmcp.log' ) );
		$this->assertSame( array(), LogSources::tail( sys_get_temp_dir() ) );
		$this->assertSame( array(), LogSources::tail( '' ) );
		$this->assertSame( array(), LogSources::tail( $this->tmp_file( 'x' ), 0, null ) );
	}

	public function test_unreadable_source_is_reported_not_thrown(): void {
		ini_set( 'error_log', '/dev/stderr' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$s = $this->by_id( LogSources::sources(), 'php_error_log' );
		$this->assertFalse( $s['readable'] );
		$this->assertNotNull( $s['reason'] );
	}

	public function test_resolve_handles_all_inputs_without_throwing(): void {
		$good = $this->tmp_file( "x\n" );
		$dir  = $this->tmp_dir();
		$cases = array(
			'bool true uses content dir' => array( true, '', $dir, false ),
			'false'                      => array( false, '', null, false ),
			'null'                       => array( null, '', null, false ),
			'empty string'               => array( '', '', null, false ),
			'syslog'                     => array( 'syslog', 'syslog', null, false ),
			'php stream'                 => array( 'php://stderr', 'php://stdout', null, false ),
			'dev stdout'                 => array( '/dev/stdout', '/dev/null', null, false ),
			'missing'                    => array( '/nonexistent/x.log', '/nonexistent/y.log', null, false ),
			'directory'                  => array( sys_get_temp_dir(), sys_get_temp_dir(), null, false ),
		);
		foreach ( $cases as $name => $c ) {
			$out = LogSources::resolve( $c[0], $c[1], $c[2], $dir );
			$this->assertCount( 3, $out, $name );
			foreach ( $out as $s ) {
				if ( 'wc_fatal' === $s['id'] && null === $c[2] ) {
					continue;
				}
				$this->assertFalse( $s['readable'], $name . ' ' . $s['id'] );
				$this->assertNotNull( $s['reason'], $name );
			}
		}
		$out = LogSources::resolve( $good, '', null, $dir );
		$this->assertTrue( $this->by_id( $out, 'wp_debug' )['readable'] );
		$this->assertSame( realpath( $good ), $this->by_id( $out, 'wp_debug' )['path'] );
		$this->assertNull( $this->by_id( $out, 'wp_debug' )['reason'] );
	}

	public function test_wp_debug_log_true_uses_content_dir_debug_log(): void {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/debug.log', "x\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$s = $this->by_id( LogSources::resolve( true, '', null, $dir ), 'wp_debug' );
		$this->assertTrue( $s['readable'] );
		$this->assertSame( realpath( $dir . '/debug.log' ), $s['path'] );
		$this->cleanup[] = $dir . '/debug.log';
	}

	public function test_wc_fatal_picks_newest_and_missing_dir_is_unreadable(): void {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/fatal-errors-2026-10-01-aaa.log', "old\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/fatal-errors-2026-10-20-bbb.log', "new\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $dir . '/fatal-errors-2026-10-01-aaa.log', time() - 1000 );
		touch( $dir . '/fatal-errors-2026-10-20-bbb.log', time() );
		$wc = $this->by_id( LogSources::resolve( false, '', $dir, $dir ), 'wc_fatal' );
		$this->assertTrue( $wc['readable'] );
		$this->assertSame( realpath( $dir . '/fatal-errors-2026-10-20-bbb.log' ), $wc['path'] );

		$none = $this->by_id( LogSources::resolve( false, '', null, $dir ), 'wc_fatal' );
		$this->assertFalse( $none['readable'] );
		$this->assertNotNull( $none['reason'] );
		$empty = $this->by_id( LogSources::resolve( false, '', $this->tmp_dir(), $dir ), 'wc_fatal' );
		$this->assertFalse( $empty['readable'] );
	}

	public function test_symlink_escaping_log_dir_is_rejected(): void {
		$dir = $this->tmp_dir();
		$target = $this->tmp_file( "secret\n" );
		symlink( $target, $dir . '/fatal-errors-2026-10-21-x.log' );
		$this->cleanup[] = $dir . '/fatal-errors-2026-10-21-x.log';
		$wc = $this->by_id( LogSources::resolve( false, '', $dir, $dir ), 'wc_fatal' );
		$this->assertFalse( $wc['readable'] );
		$this->assertNull( $wc['path'] );
		$this->assertNotNull( $wc['reason'] );
	}

	public function test_tail_refuses_paths_not_in_sources(): void {
		$f = $this->tmp_file( "a\nb\n" );
		$this->assertSame( array(), LogSources::tail( $f ) );
		$this->assertSame( array(), LogSources::tail( $f, 100, array( '/somewhere/else' ) ) );
	}

	public function test_vanished_file_tail_is_quiet(): void {
		$f    = $this->tmp_file( "a\nb\n" );
		$real = realpath( $f );
		unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( array(), LogSources::tail( $f, 100, array( $real ) ) );
	}

	public function test_open_basedir_failure_is_quiet(): void {
		// open_basedir can only be tightened inside a process, so run a child PHP.
		$root   = dirname( __DIR__, 2 );
		$file   = $this->tmp_file( 'x' );
		$script = $this->tmp_file(
			'<?php define( "ABSPATH", "/x/" ); require ' . var_export( $root . '/vendor/autoload.php', true ) . ';' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			. '$w = 0; set_error_handler( function () use ( &$w ) { ++$w; return true; } );'
			. '$f = ' . var_export( $file, true ) . ';' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			. '$o = Blaze\\McpToolkit\\Diagnostics\\LogSources::resolve( $f, $f, $f, $f );'
			. '$t = Blaze\\McpToolkit\\Diagnostics\\LogSources::tail( $f, 100, array( $f ) );'
			. 'echo json_encode( array( $w, count( $o ), array_column( $o, "readable" ), $t ) );'
		);
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -d open_basedir=' . escapeshellarg( $root ) . ' ' . escapeshellarg( $script ) . ' 2>&1';
		$out = shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$this->assertSame( array( 0, 3, array( false, false, false ), array() ), json_decode( (string) $out, true ), (string) $out );
	}

	public function test_truthy_wp_debug_log_values_mean_debug_log(): void {
		$dir = $this->tmp_dir();
		file_put_contents( $dir . '/debug.log', "x\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->cleanup[] = $dir . '/debug.log';
		foreach ( array( true, 1, '1', 'true', 'TRUE' ) as $v ) {
			$s = $this->by_id( LogSources::resolve( $v, '', null, $dir ), 'wp_debug' );
			$this->assertTrue( $s['readable'], var_export( $v, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
	}
}
