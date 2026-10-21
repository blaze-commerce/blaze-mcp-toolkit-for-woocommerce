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

	/** Max WooCommerce fatal-errors files read for one window. */
	public const MAX_WC_FILES = 7;

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
		if ( true === $wp_debug_log || 1 === $wp_debug_log || '1' === $wp_debug_log || ( is_string( $wp_debug_log ) && 'true' === strtolower( trim( $wp_debug_log ) ) ) ) {
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
	 * @param string[]|null $allowed Resolved paths that may be read; null means the readable paths from sources().
	 * @return string[]
	 */
	public static function tail( string $path, int $max_bytes = self::MAX_TAIL_BYTES, ?array $allowed = null ): array {
		$max_bytes = min( $max_bytes, self::MAX_TAIL_BYTES );
		if ( $max_bytes <= 0 || '' === $path || self::is_stream( $path ) ) {
			return array();
		}
		if ( null === $allowed ) {
			$allowed = array();
			foreach ( self::sources() as $source ) {
				if ( $source['readable'] && null !== $source['path'] ) {
					$allowed[] = $source['path'];
				}
			}
		}

		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			return self::read_tail( $path, $max_bytes, $allowed );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * WooCommerce fatal-errors-*.log files modified since $since_ts, newest first, at most MAX_WC_FILES.
	 *
	 * Each file is realpath-resolved and must sit inside the resolved log directory and be a
	 * readable regular file (same rules as sources()). Never throws or warns.
	 *
	 * @param int         $since_ts   Unix time; older files are skipped.
	 * @param string|null $wc_log_dir Log directory; null means WC_LOG_DIR. Injectable for tests.
	 * @return string[] Resolved paths.
	 */
	public static function wc_fatal_files( int $since_ts, ?string $wc_log_dir = null ): array {
		if ( null === $wc_log_dir ) {
			$wc_log_dir = defined( 'WC_LOG_DIR' ) && is_string( WC_LOG_DIR ) ? WC_LOG_DIR : '';
		}
		if ( '' === $wc_log_dir ) {
			return array();
		}
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			$real_dir = realpath( $wc_log_dir );
			if ( false === $real_dir || ! is_dir( $real_dir ) ) {
				return array();
			}
			$files = glob( rtrim( $real_dir, '/\\' ) . '/fatal-errors-*.log' );
			if ( ! is_array( $files ) ) {
				return array();
			}
			$found = array();
			foreach ( $files as $file ) {
				$mtime = is_file( $file ) ? filemtime( $file ) : false;
				if ( false === $mtime || $mtime < $since_ts ) {
					continue;
				}
				$state = self::check_quiet( $file, $real_dir );
				if ( $state['readable'] && null !== $state['path'] ) {
					$found[ $state['path'] ] = $mtime;
				}
			}
			arsort( $found );
			return array_slice( array_keys( $found ), 0, self::MAX_WC_FILES );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Tail lines of every file from wc_fatal_files(), oldest file first. The byte budget
	 * (at most 5 MB) is split evenly across the files.
	 *
	 * @param int         $since_ts   Unix time; older files are skipped.
	 * @param string|null $wc_log_dir Log directory; null means WC_LOG_DIR. Injectable for tests.
	 * @param int         $budget     Total bytes to read across all files.
	 * @return string[]
	 */
	public static function wc_fatal_lines( int $since_ts, ?string $wc_log_dir = null, int $budget = self::MAX_TAIL_BYTES ): array {
		$files = self::wc_fatal_files( $since_ts, $wc_log_dir );
		if ( array() === $files ) {
			return array();
		}
		$per_file = intdiv( min( $budget, self::MAX_TAIL_BYTES ), count( $files ) );
		$lines    = array();
		foreach ( array_reverse( $files ) as $file ) {
			$lines = array_merge( $lines, self::tail( $file, $per_file, $files ) );
		}
		return $lines;
	}

	/**
	 * Tail read; runs with PHP warnings muted by tail().
	 *
	 * @param string   $path      Path.
	 * @param int      $max_bytes Bytes.
	 * @param string[] $allowed   Resolved paths that may be read.
	 * @return string[]
	 */
	private static function read_tail( string $path, int $max_bytes, array $allowed ): array {
		$real = realpath( $path );
		if ( false === $real || ! in_array( $real, $allowed, true ) || ! is_file( $real ) || ! is_readable( $real ) ) {
			return array();
		}
		$size = filesize( $real );
		if ( false === $size ) {
			return array();
		}
		$handle = fopen( $real, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array();
		}

		$partial = false;
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
	private static function check( string $path, ?string $root = null ): array {
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			return self::check_quiet( $path, $root );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Validate with warnings muted by check().
	 *
	 * @param string      $path Candidate.
	 * @param string|null $root Resolved directory the real path must sit inside.
	 * @return array{path:?string,readable:bool,reason:?string}
	 */
	private static function check_quiet( string $path, ?string $root ): array {
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
		if ( null !== $root && ! str_starts_with( $real, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return self::unavailable( 'Log file resolves outside the log directory' );
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
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			return self::newest_fatal_quiet( $dir );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Newest-file search with warnings muted by newest_fatal().
	 *
	 * @param string $dir WC_LOG_DIR.
	 * @return array{path:?string,readable:bool,reason:?string}
	 */
	private static function newest_fatal_quiet( string $dir ): array {
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
		return self::check( $best, $real_dir );
	}
}
