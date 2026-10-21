<?php
/**
 * Plugin bootstrap.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit;

use Blaze\McpToolkit\Abilities\McpExposure;
use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Audit\AuditLog;
use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Events\EventRecorder;
use Blaze\McpToolkit\Events\EventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's hooks. Later tasks register their modules here.
 */
final class Plugin {

	/**
	 * Register hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'action_scheduler_init', array( Installer::class, 'maybe_schedule' ) );

		// Daily retention. Other modules add their own callbacks to the same hook.
		add_action( Installer::CLEANUP_HOOK, array( AuditLog::class, 'purge' ), 10, 0 );
		add_action( Installer::CLEANUP_HOOK, array( EventStore::class, 'purge' ), 10, 0 );

		// Change recorder. These hooks only fire in admin, cron and CLI upgrades, never on the front end.
		EventRecorder::register();

		Registrar::init();

		// wc-analytics/* public over MCP, whether our bundled copy or WooCommerce for Claude registers them.
		McpExposure::register();

		// Bundled commerce-abilities and store tools, or stand aside for WooCommerce for Claude (plugins_loaded 20).
		Commerce::register();
	}
}
