<?php
/**
 * Activation and deactivation.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, capability, settings and the daily cleanup job.
 */
final class Installer {

	public const CAPABILITY      = 'blzmcp_view_diagnostics';
	public const CLEANUP_HOOK    = 'blzmcp_daily_cleanup';
	public const SCHEDULER_GROUP = 'blzmcp';
	public const PENDING_FLAG    = 'blzmcp_schedule_pending';

	/**
	 * Plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAPABILITY );
		}

		$settings = Settings::get();
		if ( 0 === (int) $settings['activated_at'] ) {
			$settings['activated_at'] = time();
		}
		// Always write so the option exists and is never autoloaded.
		Settings::update( $settings );

		self::schedule_cleanup();
	}

	/**
	 * Plugin deactivation: stop the daily job.
	 */
	public static function deactivate(): void {
		delete_transient( self::PENDING_FLAG );

		if ( did_action( 'action_scheduler_init' ) && function_exists( 'as_unschedule_all_actions' ) ) {
			try {
				as_unschedule_all_actions( self::CLEANUP_HOOK, array(), self::SCHEDULER_GROUP );
			} catch ( \Throwable $e ) {
				unset( $e ); // A broken Action Scheduler store must not block deactivation.
			}
		}
	}

	/**
	 * Schedule the daily cleanup now if Action Scheduler is ready, otherwise
	 * flag it so action_scheduler_init does it.
	 */
	public static function schedule_cleanup(): void {
		if ( did_action( 'action_scheduler_init' ) ) {
			self::ensure_scheduled();
			return;
		}
		set_transient( self::PENDING_FLAG, 1 );
	}

	/**
	 * Hooked to action_scheduler_init: finish a schedule that activation could
	 * not do. Does nothing unless the flag is set.
	 */
	public static function maybe_schedule(): void {
		if ( ! get_transient( self::PENDING_FLAG ) ) {
			return;
		}
		self::ensure_scheduled();
		delete_transient( self::PENDING_FLAG );
	}

	/**
	 * Create the recurring action if it does not exist.
	 */
	private static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		try {
			if ( ! as_has_scheduled_action( self::CLEANUP_HOOK, array(), self::SCHEDULER_GROUP ) ) {
				as_schedule_recurring_action( strtotime( 'tomorrow 3:00am' ), DAY_IN_SECONDS, self::CLEANUP_HOOK, array(), self::SCHEDULER_GROUP );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Retried on the next activation.
		}
	}

	/**
	 * Create or update the audit and events tables.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$audit   = $wpdb->prefix . 'blzmcp_audit';
		$events  = $wpdb->prefix . 'blzmcp_events';

		dbDelta(
			"CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ability varchar(100) NOT NULL DEFAULT '',
				params_hash char(64) NOT NULL DEFAULT '',
				row_count int(11) NOT NULL DEFAULT 0,
				duration_ms int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				event_type varchar(40) NOT NULL DEFAULT '',
				subject varchar(191) NOT NULL DEFAULT '',
				from_version varchar(40) NOT NULL DEFAULT '',
				to_version varchar(40) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY event_type (event_type)
			) {$charset};"
		);
	}
}
