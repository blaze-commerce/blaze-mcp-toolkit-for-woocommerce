<?php
/**
 * Finds the PHP and WooCommerce error logs and reads only their tail.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * The only file access in the plugin. It reads from three fixed sources
 * (WP_DEBUG_LOG, ini error_log, newest WC fatal-errors log) and never
 * from a caller-supplied path.
 */
final class LogSources {

	/** Hard ceiling for any tail read: 5 MB. */
	public const MAX_TAIL_BYTES = 5242880;

	/**
	 * The three log sources as configured on this site.
	 *
	 * Always returns three entries (wp_debug, php_error_log, wc_fatal). Each has
	 * readable=false and a reason when it can't be used. Never throws.
	 *
	 * @return array<int,array{id:string,label:string,path:?string,readable:bool,reason:?string}>
	 */
	public static function sources(): array {
		$debug = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null;
		$ini   = ini_get( 'error_log' );
		$wc    = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : null;
		$wp    = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
		return self::resolve( $debug, is_string( $ini ) ? $ini : '', is_string( $wc ) ? $wc : null, $wp );
	}

	/**
	 * Resolve sources from raw values (injectable for tests).
	 *
	 * @param mixed       $wp_debug_log WP_DEBUG_LOG value: null (undefined), bool or string path.
	 * @param string      $ini_error_log ini_get( 'error_log' ).
	 * @param string|null $wc_log_dir   WC_LOG_DIR, null when WooCommerce isn't loaded.
	 * @param string      $content_dir  WP_CONTENT_DIR.
	 * @return array<int,array{id:string,label:string,path:?string,readable:bool,reason:?string}>
	 */
	public static function resolve( $wp_debug_log, string $ini_error_log, ?string $wc_log_dir, string $content_dir ): array {
		if ( true === $wp_debug_log ) {
			$debug = self::check( rtrim( $content_dir, '/\\' ) . '/debug.log' );
		} elseif ( is_string( $wp_debug_log ) ) {
			$debug = self::check( $wp_debug_log );
		} else {
			$debug = self::unavailable( 'WP_DEBUG_LOG is not enabled' );
		}

		if ( null === $wc_log_dir || '' === $wc_log_dir ) {
			$wc = self::unavailable( 'WooCommerce log directory is not available' );
		} else {
			$wc = self::newest_fatal( $wc_log_dir );
		}

		return array(
			self::entry( 'wp_debug', 'WordPress debug.log', $debug ),
			self::entry( 'php_error_log', 'PHP error_log', self::check( $ini_error_log ) ),
			self::entry( 'wc_fatal', 'WooCommerce fatal errors', $wc ),
		);
	}

	/**
	 * Last lines of a file, at most $max_bytes (never more than 5 MB) from the end.
	 *
	 * The first line is dropped when the read didn't start at byte 0, because it
	 * is probably cut in half. Returns an empty array on any failure.
	 *
	 * @param string $path      Path obtained from sources(); internal use only.
	 * @param int    $max_bytes Bytes to read from the end.
	 * @return string[]
	 */
	public static function tail( string $path, int $max_bytes = self::MAX_TAIL_BYTES ): array {
		$max_bytes = min( $max_bytes, self::MAX_TAIL_BYTES );
		if ( $max_bytes <= 0 || '' === $path || self::is_stream( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}
		$size = filesize( $path );
		if ( false === $size ) {
			return array();
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array();
		}

		$partial = false;
		$data    = false;
		if ( $size > $max_bytes ) {
			$partial = true;
			if ( 0 !== fseek( $handle, $size - $max_bytes ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return array();
			}
		}
		$data = stream_get_contents( $handle, $max_bytes );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! is_string( $data ) || '' === $data ) {
			return array();
		}
		$lines = explode( "\n", $data );
		if ( $partial ) {
			array_shift( $lines );
		}
		$lines = array_map( static fn( string $l ): string => rtrim( $l, "\r" ), $lines );
		while ( array() !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return array_values( $lines );
	}

	/**
	 * Build a source entry.
	 *
	 * @param string                                                  $id    Source id.
	 * @param string                                                  $label Label.
	 * @param array{path:?string,readable:bool,reason:?string}        $state Check result.
	 * @return array{id:string,label:string,path:?string,readable:bool,reason:?string}
	 */
	private static function entry( string $id, string $label, array $state ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'path'     => $state['path'],
			'readable' => $state['readable'],
			'reason'   => $state['reason'],
		);
	}

	/**
	 * Unavailable state.
	 *
	 * @param string $reason Why.
	 * @return array{path:?string,readable:bool,reason:?string}
	 */
	private static function unavailable( string $reason ): array {
		return array(
			'path'     => null,
			'readable' => false,
			'reason'   => $reason,
		);
	}

	/**
	 * Whether a path is a stream, device or other non-file target.
	 *
	 * @param string $path Path.
	 */
	private static function is_stream( string $path ): bool {
		$p = strtolower( trim( $path ) );
		return '' === $p
			|| 'syslog' === $p
			|| str_contains( $p, '://' )
			|| str_starts_with( $p, 'php:' )
			|| str_starts_with( $p, 'data:' )
			|| str_starts_with( $p, '/dev/' )
			|| str_starts_with( $p, '/proc/' )
			|| str_contains( $p, "\0" );
	}

	/**
	 * Resolve and validate one candidate path.
	 *
	 * @param string $path Candidate.
	 * @return array{path:?string,readable:bool,reason:?string}
	 */
	private static function check( string $path ): array {
		if ( '' === trim( $path ) ) {
			return self::unavailable( 'No log path is configured' );
		}
		if ( self::is_stream( $path ) ) {
			return self::unavailable( 'Log goes to a stream or system logger, not a file' );
		}
		$real = realpath( $path );
		if ( false === $real ) {
			return self::unavailable( 'Log file does not exist' );
		}
		if ( self::is_stream( $real ) ) {
			return self::unavailable( 'Log goes to a stream or system logger, not a file' );
		}
		if ( ! is_file( $real ) ) {
			return self::unavailable( 'Log path is not a regular file' );
		}
		if ( ! is_readable( $real ) ) {
			return self::unavailable( 'Log file is not readable' );
		}
		return array(
			'path'     => $real,
			'readable' => true,
			'reason'   => null,
		);
	}

	/**
	 * Newest fatal-errors-*.log in the WooCommerce log directory.
	 *
	 * @param string $dir WC_LOG_DIR.
	 * @return array{path:?string,readable:bool,reason:?string}
	 */
	private static function newest_fatal( string $dir ): array {
		$real_dir = realpath( $dir );
		if ( false === $real_dir || ! is_dir( $real_dir ) ) {
			return self::unavailable( 'WooCommerce log directory does not exist' );
		}
		$files = glob( rtrim( $real_dir, '/\\' ) . '/fatal-errors-*.log' );
		if ( ! is_array( $files ) || array() === $files ) {
			return self::unavailable( 'No WooCommerce fatal-errors log found' );
		}
		$best      = null;
		$best_time = -1;
		foreach ( $files as $file ) {
			$mtime = is_file( $file ) ? filemtime( $file ) : false;
			if ( false !== $mtime && $mtime > $best_time ) {
				$best      = $file;
				$best_time = $mtime;
			}
		}
		if ( null === $best ) {
			return self::unavailable( 'No WooCommerce fatal-errors log found' );
		}
		return self::check( $best );
	}
}
