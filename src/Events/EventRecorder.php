<?php
/**
 * Records plugin, theme and core changes made through WordPress.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks fire only in admin, cron and CLI requests (upgrades, activation, theme switch),
 * so registering them is a no-op on the front end. Changes made outside WordPress are not seen.
 */
final class EventRecorder {

	/**
	 * Subject of core events.
	 */
	public const CORE_SUBJECT = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- Slug, not prose.

	/**
	 * Versions captured before an upgrade, keyed "plugin:<file>", "theme:<stylesheet>" or "core".
	 *
	 * @var array<string, string>
	 */
	private static array $before = array();

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'upgrader_pre_install', array( self::class, 'capture' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( self::class, 'on_upgrade_complete' ), 10, 2 );
		add_action( 'activated_plugin', array( self::class, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( self::class, 'on_plugin_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( self::class, 'on_switch_theme' ), 10, 2 );
		add_action( '_core_updated_successfully', array( self::class, 'on_core_updated' ), 10, 1 );
	}

	/**
	 * Slug for a plugin file: its folder, or the file name without .php for single-file plugins.
	 *
	 * @param string $file Plugin file relative to the plugins folder, e.g. "hello.php".
	 */
	public static function plugin_slug( string $file ): string {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}

	/**
	 * Items an upgrader hook_extra refers to (single or bulk).
	 *
	 * @param array<string,mixed> $extra Hook extra.
	 * @return array<int, array{string,string}> Pairs of kind (plugin, theme) and plugin file or stylesheet.
	 */
	public static function targets( array $extra ): array {
		$out  = array();
		$type = (string) ( $extra['type'] ?? '' );
		if ( '' === $type ) {
			// Bulk pre_install (WP-CLI, "update selected") sends only the item key.
			if ( isset( $extra['plugin'] ) || isset( $extra['plugins'] ) ) {
				$type = 'plugin';
			} elseif ( isset( $extra['theme'] ) || isset( $extra['themes'] ) ) {
				$type = 'theme';
			}
		}
		if ( 'plugin' === $type ) {
			$list = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		} elseif ( 'theme' === $type ) {
			$list = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
		} else {
			return array();
		}
		foreach ( $list as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = array( $type, $item );
			}
		}
		return $out;
	}

	/**
	 * Installed plugin versions by plugin file.
	 *
	 * @return array<string,string>
	 */
	private static function plugin_versions(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[ (string) $file ] = (string) ( $data['Version'] ?? '' );
		}
		return $out;
	}

	/**
	 * Installed version of a theme, '' when absent.
	 *
	 * @param string $stylesheet Theme folder.
	 */
	private static function theme_version( string $stylesheet ): string {
		$theme = wp_get_theme( $stylesheet );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}

	/**
	 * Filter callback: remember versions before the upgrader replaces files. Returns $response unchanged.
	 *
	 * @param mixed               $response   Filter value.
	 * @param array<string,mixed> $hook_extra Upgrader context.
	 * @return mixed
	 */
	public static function capture( $response, $hook_extra = array() ) {
		try {
			$extra = is_array( $hook_extra ) ? $hook_extra : array();
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && ! isset( self::$before['core'] ) ) {
				self::$before['core'] = $GLOBALS['wp_version'];
			}
			$targets = self::targets( $extra );
			if ( array() !== $targets ) {
				$plugins = null;
				foreach ( $targets as list( $kind, $item ) ) {
					if ( 'plugin' === $kind ) {
						$plugins                           = $plugins ?? self::plugin_versions();
						self::$before[ 'plugin:' . $item ] = $plugins[ $item ] ?? '';
					} else {
						self::$before[ 'theme:' . $item ] = self::theme_version( $item );
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Recording must never break an upgrade.
		}
		return $response;
	}

	/**
	 * Pair before and after versions into events. Pure.
	 *
	 * @param array<int, array{string,string}> $targets Output of targets().
	 * @param array<string,string>             $before  Captured versions.
	 * @param array<string,string>             $after   Current versions, same keys.
	 * @param bool                             $install Whether this was an install, not an update.
	 * @return array<int, array{string,string,?string,?string}> Type, subject, from, to.
	 */
	public static function build_events( array $targets, array $before, array $after, bool $install ): array {
		$events = array();
		foreach ( $targets as list( $kind, $item ) ) {
			$key     = $kind . ':' . $item;
			$subject = 'plugin' === $kind ? self::plugin_slug( $item ) : $item;
			$to      = $after[ $key ] ?? '';
			$from = $before[ $key ] ?? '';
			if ( '' === $to ) {
				continue; // Not installed afterwards: nothing changed.
			}
			if ( $install && '' === $from ) {
				$events[] = array( $kind . '_installed', $subject, null, '' === $to ? null : $to );
				continue;
			}
			// A real update always passed through pre_install; bulk lists also hold skipped items.
			if ( ! isset( $before[ $key ] ) ) {
				continue;
			}
			// Skip items whose version did not change.
			if ( '' !== $from && '' !== $to && $from === $to ) {
				continue;
			}
			$events[] = array( $kind . '_updated', $subject, '' === $from ? null : $from, '' === $to ? null : $to );
		}
		return $events;
	}

	/**
	 * Record plugin and theme updates and installs.
	 *
	 * @param mixed               $upgrader   Upgrader instance.
	 * @param array<string,mixed> $hook_extra Upgrader context.
	 */
	public static function on_upgrade_complete( $upgrader, $hook_extra = array() ): void {
		try {
			$extra  = is_array( $hook_extra ) ? $hook_extra : array();
			$type   = (string) ( $extra['type'] ?? '' );
			$action = (string) ( $extra['action'] ?? '' );
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $action, array( 'update', 'install' ), true ) ) {
				return;
			}
			$install = 'install' === $action;
			$targets = self::targets( $extra );
			if ( $install && array() === $targets && is_object( $upgrader ) ) {
				// A fresh install names no item in hook_extra; ask the upgrader.
				if ( 'plugin' === $type && method_exists( $upgrader, 'plugin_info' ) ) {
					$file = $upgrader->plugin_info();
					if ( is_string( $file ) && '' !== $file ) {
						$targets[] = array( 'plugin', $file );
					}
				} elseif ( 'theme' === $type && method_exists( $upgrader, 'theme_info' ) ) {
					$info = $upgrader->theme_info();
					if ( is_object( $info ) && method_exists( $info, 'get_stylesheet' ) ) {
						$targets[] = array( 'theme', (string) $info->get_stylesheet() );
					}
				}
			}
			if ( array() === $targets ) {
				return;
			}
			$after   = array();
			$plugins = null;
			foreach ( $targets as list( $kind, $item ) ) {
				if ( 'plugin' === $kind ) {
					$plugins                    = $plugins ?? self::plugin_versions();
					$after[ 'plugin:' . $item ] = $plugins[ $item ] ?? '';
				} else {
					$after[ 'theme:' . $item ] = self::theme_version( $item );
				}
			}
			foreach ( self::build_events( $targets, self::$before, $after, $install ) as list( $event, $subject, $from, $to ) ) {
				EventStore::add( $event, $subject, $from, $to );
			}
			foreach ( $targets as list( $kind, $item ) ) {
				unset( self::$before[ $kind . ':' . $item ] ); // Long-lived WP-CLI processes.
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_activated( $plugin ): void {
		self::plugin_toggle( 'plugin_activated', $plugin );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_deactivated( $plugin ): void {
		self::plugin_toggle( 'plugin_deactivated', $plugin );
	}

	/**
	 * Record an activation or deactivation, with the installed version as to_version.
	 *
	 * @param string $type   Event type.
	 * @param mixed  $plugin Plugin file.
	 */
	private static function plugin_toggle( string $type, $plugin ): void {
		try {
			if ( ! is_string( $plugin ) || '' === $plugin ) {
				return;
			}
			$versions = self::plugin_versions();
			$version  = $versions[ $plugin ] ?? '';
			EventStore::add( $type, self::plugin_slug( $plugin ), null, '' === $version ? null : $version );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Theme switched.
	 *
	 * @param mixed $new_name  New theme name.
	 * @param mixed $new_theme New theme object.
	 */
	public static function on_switch_theme( $new_name = '', $new_theme = null ): void {
		try {
			if ( ! is_object( $new_theme ) || ! method_exists( $new_theme, 'get_stylesheet' ) ) {
				return;
			}
			$version = (string) $new_theme->get( 'Version' );
			EventStore::add( 'theme_switched', (string) $new_theme->get_stylesheet(), null, '' === $version ? null : $version );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * WordPress core updated.
	 *
	 * @param mixed $new_version New version.
	 */
	public static function on_core_updated( $new_version ): void {
		try {
			if ( ! is_string( $new_version ) || '' === $new_version ) {
				return;
			}
			$from = self::$before['core'] ?? ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '' );
			EventStore::add( 'core_updated', self::CORE_SUBJECT, '' === $from ? null : $from, $new_version );
			unset( self::$before['core'] );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
