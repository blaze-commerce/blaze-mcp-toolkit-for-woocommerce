<?php
/**
 * Storage for plugin, theme and core change events.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Events;

defined( 'ABSPATH' ) || exit;

/**
 * One row per change made through WordPress. Stores the user id, never the name.
 */
final class EventStore {

	public const TABLE = 'blzmcp_events';

	public const TYPES = array(
		'plugin_updated',
		'plugin_installed',
		'plugin_activated',
		'plugin_deactivated',
		'theme_updated',
		'theme_installed',
		'theme_switched',
		'core_updated',
	);

	public const PURGE_BATCH       = 1000;
	public const PURGE_MAX_BATCHES = 50;
	public const MAX_LIMIT         = 50;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Record one event as the current user (0 for cron and CLI).
	 *
	 * @param string      $type    One of TYPES.
	 * @param string      $subject Plugin slug, theme stylesheet or the core subject.
	 * @param string|null $from    Version before, if known.
	 * @param string|null $to      Version after, if known.
	 */
	public static function add( string $type, string $subject, ?string $from, ?string $to ): void {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) || '' === $subject ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table, write-only log.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (created_at, event_type, subject, from_version, to_version, user_id) VALUES (%s, %s, %s, %s, %s, %d)',
				self::table(),
				gmdate( 'Y-m-d H:i:s' ),
				$type,
				substr( $subject, 0, 191 ),
				substr( (string) $from, 0, 40 ),
				substr( (string) $to, 0, 40 ),
				max( 0, get_current_user_id() )
			)
		);
	}

	/**
	 * Events in a window, newest first.
	 *
	 * @param int         $since_ts Window start (unix time, inclusive).
	 * @param int         $until_ts Window end (unix time, inclusive).
	 * @param string|null $type     Optional event type.
	 * @param int         $limit    Max rows (capped at 50).
	 * @return array<int, array{created_at:string,event_type:string,subject:string,from_version:?string,to_version:?string,user_id:int}>
	 */
	public static function list( int $since_ts, int $until_ts, ?string $type = null, int $limit = self::MAX_LIMIT ): array {
		global $wpdb;

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$since = gmdate( 'Y-m-d H:i:s', $since_ts );
		$until = gmdate( 'Y-m-d H:i:s', $until_ts );

		if ( null !== $type && in_array( $type, self::TYPES, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, event_type, subject, from_version, to_version, user_id FROM %i WHERE created_at >= %s AND created_at <= %s AND event_type = %s ORDER BY created_at DESC, id DESC LIMIT %d', self::table(), $since, $until, $type, $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, event_type, subject, from_version, to_version, user_id FROM %i WHERE created_at >= %s AND created_at <= %s ORDER BY created_at DESC, id DESC LIMIT %d', self::table(), $since, $until, $limit ), ARRAY_A );
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'created_at'   => (string) $row['created_at'],
					'event_type'   => (string) $row['event_type'],
					'subject'      => (string) $row['subject'],
					'from_version' => '' === (string) $row['from_version'] ? null : (string) $row['from_version'],
					'to_version'   => '' === (string) $row['to_version'] ? null : (string) $row['to_version'],
					'user_id'      => (int) $row['user_id'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Delete events older than $days days, in batches. Hooked to the daily cleanup.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public static function purge( int $days = 180 ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$total  = 0;

		for ( $i = 0; $i < self::PURGE_MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table retention.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT %d', self::table(), $cutoff, self::PURGE_BATCH ) );
			$total  += max( 0, $deleted );
			if ( $deleted < self::PURGE_BATCH ) {
				break;
			}
		}

		return $total;
	}
}
