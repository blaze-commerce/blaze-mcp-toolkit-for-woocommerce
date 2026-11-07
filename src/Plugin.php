<?php
/**
 * Plugin bootstrap.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit;

use Blaze\McpToolkit\Abilities\AnalyticsNaming;
use Blaze\McpToolkit\Abilities\McpExposure;
use Blaze\McpToolkit\Abilities\Registrar;
use Blaze\McpToolkit\Admin\SetupScreen;
use Blaze\McpToolkit\Audit\AuditLog;
use Blaze\McpToolkit\Bundled\Commerce;
use Blaze\McpToolkit\Connectors\RequestContext;
use Blaze\McpToolkit\Events\EventRecorder;
use Blaze\McpToolkit\Events\EventStore;
use Blaze\McpToolkit\Privacy\PolicyText;

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

		// Records which connector served the current REST call.
		RequestContext::register();

		// wc-analytics/* public over MCP, whether our bundled copy or WooCommerce for Claude registers them.
		McpExposure::register();

		// Connector-correct tool names in wc-analytics/* descriptions and the large-range error,
		// whoever supplies the abilities (registered here, not in Commerce::load(), to cover stand-aside).
		AnalyticsNaming::register();

		// Bundled commerce-abilities and store tools, or stand aside for WooCommerce for Claude (plugins_loaded 20).
		Commerce::register();

		// Setup screen and privacy policy text: admin only.
		if ( is_admin() ) {
			SetupScreen::register();
			PolicyText::register();
		}
	}
}
