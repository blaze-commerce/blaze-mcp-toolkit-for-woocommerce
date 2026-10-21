<?php
/**
 * Plugin bootstrap.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit;

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
	}
}
