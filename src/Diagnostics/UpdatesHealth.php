<?php
/**
 * Pending core, plugin and theme updates from WordPress's cached update checks.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the update_core / update_plugins / update_themes site transients only.
 * Never calls wp_version_check(), wp_update_plugins() or wp_update_themes(), so
 * no request leaves the site; data is as fresh as WordPress's last scheduled check.
 */
final class UpdatesHealth {

	/** Max plugins and themes listed (totals cover everything). */
	private const MAX_ITEMS = 50;

	/** Max characters of a plugin or theme name. */
	private const MAX_NAME_CHARS = 100;

	/**
	 * Update summary.
	 *
	 * @return array{core:array{current:string,available:?string,update_status:string},plugins:array<int,array<string,mixed>>,themes:array<int,array<string,mixed>>,totals:array<string,int>}
	 */
	public static function summary(): array {
		self::load_admin_functions();

		$plugins = self::plugins();
		$themes  = self::themes();

		return array(
			'core'    => self::core(),
			'plugins' => self::cap( $plugins ),
			'themes'  => self::cap( $themes ),
			'totals'  => array(
				'plugins'         => count( $plugins ),
				'plugin_updates'  => self::count_status( $plugins, 'available' ),
				'plugins_unknown' => self::count_status( $plugins, 'unknown' ),
				'themes'          => count( $themes ),
				'theme_updates'   => self::count_status( $themes, 'available' ),
				'themes_unknown'  => self::count_status( $themes, 'unknown' ),
			),
		);
	}

	/**
	 * Classify one item against a cached update transient.
	 *
	 * @param string $key       Plugin file or theme stylesheet.
	 * @param string $current   Installed version.
	 * @param mixed  $transient update_plugins / update_themes value.
	 * @return array{available:?string,update_status:string}
	 */
	public static function classify( string $key, string $current, $transient ): array {
		$response  = is_object( $transient ) && isset( $transient->response ) ? (array) $transient->response : array();
		$no_update = is_object( $transient ) && isset( $transient->no_update ) ? (array) $transient->no_update : array();

		if ( isset( $response[ $key ] ) ) {
			$entry = $response[ $key ];
			$new   = is_object( $entry ) ? ( $entry->new_version ?? '' ) : ( is_array( $entry ) ? ( $entry['new_version'] ?? '' ) : '' );
			$new   = is_scalar( $new ) ? (string) $new : '';
			if ( '' !== $new && version_compare( $new, $current, '>' ) ) {
				return array(
					'available'     => self::clean( $new, 40 ),
					'update_status' => 'available',
				);
			}
			// The cached offer is not newer than what is installed now.
			return array(
				'available'     => null,
				'update_status' => '' === $new ? 'unknown' : 'current',
			);
		}
		if ( isset( $no_update[ $key ] ) ) {
			return array(
				'available'     => null,
				'update_status' => 'current',
			);
		}
		return array(
			'available'     => null,
			'update_status' => 'unknown',
		);
	}

	/**
	 * Core: installed version and the newest cached 'upgrade' offer.
	 *
	 * @return array{current:string,available:?string,update_status:string}
	 */
	private static function core(): array {
		$current = (string) get_bloginfo( 'version' );
		$updates = function_exists( 'get_core_updates' ) ? get_core_updates( array( 'dismissed' => true ) ) : false; // Include dismissed offers: a dismissed update is still pending.
		if ( ! is_array( $updates ) || array() === $updates ) {
			return array(
				'current'       => $current,
				'available'     => null,
				'update_status' => 'unknown',
			);
		}
		$best = null;
		foreach ( $updates as $offer ) {
			if ( ! is_object( $offer ) || 'upgrade' !== ( $offer->response ?? '' ) || ! isset( $offer->current ) || ! is_scalar( $offer->current ) ) {
				continue;
			}
			$version = (string) $offer->current;
			if ( version_compare( $version, $current, '>' ) && ( null === $best || version_compare( $version, $best, '>' ) ) ) {
				$best = $version;
			}
		}
		return array(
			'current'       => $current,
			'available'     => null === $best ? null : self::clean( $best, 40 ),
			'update_status' => null === $best ? 'current' : 'available',
		);
	}

	/**
	 * All installed plugins with their update status.
	 *
	 * @return array<int,array{slug:string,name:string,current:string,available:?string,update_status:string}>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}
		$transient = get_site_transient( 'update_plugins' );
		$out       = array();
		foreach ( (array) get_plugins() as $file => $data ) {
			$file    = (string) $file;
			$current = (string) ( $data['Version'] ?? '' );
			$slug    = str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$out[]   = array_merge(
				array(
					'slug'    => self::clean( $slug, 100 ),
					'name'    => self::clean( (string) ( $data['Name'] ?? $slug ), self::MAX_NAME_CHARS ),
					'current' => self::clean( $current, 40 ),
				),
				self::classify( $file, $current, $transient )
			);
		}
		return $out;
	}

	/**
	 * All installed themes with their update status.
	 *
	 * @return array<int,array{slug:string,name:string,current:string,available:?string,update_status:string}>
	 */
	private static function themes(): array {
		$transient = get_site_transient( 'update_themes' );
		$out       = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$current    = (string) $theme->get( 'Version' );
			$out[]      = array_merge(
				array(
					'slug'    => self::clean( $stylesheet, 100 ),
					'name'    => self::clean( (string) $theme->get( 'Name' ), self::MAX_NAME_CHARS ),
					'current' => self::clean( $current, 40 ),
				),
				self::classify( $stylesheet, $current, $transient )
			);
		}
		return $out;
	}

	/**
	 * Updates first, then unknown, then current; at most MAX_ITEMS.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cap( array $items ): array {
		$rank = array(
			'available' => 0,
			'unknown'   => 1,
			'current'   => 2,
		);
		usort(
			$items,
			static fn( array $a, array $b ): int => array( $rank[ $a['update_status'] ] ?? 3, $a['slug'] ) <=> array( $rank[ $b['update_status'] ] ?? 3, $b['slug'] )
		);
		return array_slice( $items, 0, self::MAX_ITEMS );
	}

	/**
	 * Number of items with a status.
	 *
	 * @param array<int,array<string,mixed>> $items  Items.
	 * @param string                         $status Status.
	 */
	private static function count_status( array $items, string $status ): int {
		return count( array_filter( $items, static fn( array $i ): bool => $status === $i['update_status'] ) );
	}

	/**
	 * Plain text, single line, truncated.
	 *
	 * @param string $text Text from plugin/theme headers or the update transient.
	 * @param int    $max  Max characters.
	 */
	private static function clean( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Admin-only helpers (get_plugins, get_core_updates) are not loaded outside wp-admin.
	 * These files only define functions; they do not run update checks.
	 */
	private static function load_admin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
	}
}
