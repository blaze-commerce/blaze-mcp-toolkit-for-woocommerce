<?php
/**
 * Audit log for ability calls.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Records one row per ability call. Stores a hash of the input, never the input itself.
 */
final class AuditLog {

	public const TABLE    = 'blzmcp_audit';
	public const STATUSES = array( 'ok', 'error', 'denied' );

	/**
	 * Write one audit row.
	 *
	 * @param int    $user_id     Calling user.
	 * @param string $ability     Ability name.
	 * @param array  $input       Ability input (hashed, never stored).
	 * @param int    $row_count   Rows returned.
	 * @param int    $duration_ms Duration in milliseconds.
	 * @param string $status      One of ok, error, denied.
	 */
	public static function record( int $user_id, string $ability, array $input, int $row_count, int $duration_ms, string $status ): void {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'error';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table, write-only log.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (created_at, user_id, ability, params_hash, row_count, duration_ms, status) VALUES (%s, %d, %s, %s, %d, %d, %s)',
				self::table(),
				gmdate( 'Y-m-d H:i:s' ),
				max( 0, $user_id ),
				substr( $ability, 0, 100 ),
				self::hash_params( $input ),
				max( 0, $row_count ),
				max( 0, $duration_ms ),
				$status
			)
		);
	}

	/**
	 * Most recent rows, newest first. No input is returned because none is stored.
	 *
	 * @param int $limit Max rows (1-50).
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = min( 50, max( 1, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, always fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, ability, row_count, duration_ms, status, created_at FROM %i ORDER BY id DESC LIMIT %d',
				self::table(),
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'user_id'     => (int) $row['user_id'],
					'ability'     => (string) $row['ability'],
					'row_count'   => (int) $row['row_count'],
					'duration_ms' => (int) $row['duration_ms'],
					'status'      => (string) $row['status'],
					'created_at'  => (string) $row['created_at'],
				);
			},
			$rows
		);
	}

	/**
	 * Delete rows older than $days days. Hooked to the daily cleanup with no arguments.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public static function purge( int $days = 30 ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table retention.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), $cutoff ) );

		return (int) $deleted;
	}

	/**
	 * SHA-256 of the input with keys sorted recursively, so key order never changes the hash.
	 *
	 * @param array $input Ability input.
	 */
	public static function hash_params( array $input ): string {
		return hash( 'sha256', (string) wp_json_encode( self::normalise( $input ) ) );
	}

	/**
	 * Recursively sort array keys. List order is preserved.
	 *
	 * @param array $input Input.
	 */
	public static function normalise( array $input ): array {
		ksort( $input );
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$input[ $key ] = self::normalise( $value );
			}
		}
		return $input;
	}

	/**
	 * Full table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}
}
