<?php
/**
 * Plugin settings: one non-autoloaded option.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single `blzmcp_settings` option.
 */
final class Settings {

	public const OPTION = 'blzmcp_settings';

	/**
	 * Default values for every key.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'shop_manager_diagnostics' => false,
			'db_version'               => '1',
			'activated_at'             => 0,
		);
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Merge the given values into the stored settings (never autoloaded).
	 *
	 * @param array<string, mixed> $values Keys to change.
	 */
	public static function update( array $values ): void {
		$merged = array_merge( self::get(), array_intersect_key( $values, self::defaults() ) );
		update_option( self::OPTION, $merged, false );
	}

	/**
	 * Whether Shop Managers may use the diagnostics tools.
	 */
	public static function shop_manager_diagnostics(): bool {
		return (bool) self::get()['shop_manager_diagnostics'];
	}
}
