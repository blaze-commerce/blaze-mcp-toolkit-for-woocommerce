<?php
/**
 * Base class for every Blaze MCP Toolkit ability.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities;

use Blaze\McpToolkit\Audit\AuditLog;

defined( 'ABSPATH' ) || exit;

/**
 * Handles registration, permission, caching, timing and auditing. Subclasses supply
 * the schema, the capability check and run().
 *
 * Core order (WP_Ability::execute): normalise input, validate input, permission_callback,
 * then execute_callback only if permission returned true. So a denied call is audited
 * here in permission(), and execute() is only reached by permitted users.
 */
abstract class AbstractAbility {

	/**
	 * Ability name, e.g. blaze-mcp-toolkit/store-health. Overridden by subclasses.
	 */
	public const NAME = '';

	/**
	 * Transient prefix for cached results.
	 */
	public const CACHE_PREFIX = 'blzmcp_c_';

	/**
	 * Human-readable label.
	 */
	abstract protected static function label(): string;

	/**
	 * Description shown to the AI client.
	 */
	abstract protected static function description(): string;

	/**
	 * JSON Schema for input.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected static function input_schema(): array;

	/**
	 * JSON Schema for output.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected static function output_schema(): array;

	/**
	 * Whether the current user may call this ability.
	 */
	abstract protected static function can(): bool;

	/**
	 * Do the work. Input has passed schema validation and the permission check.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	abstract protected static function run( array $input ): array|\WP_Error;

	/**
	 * Seconds to cache a successful result. 0 disables caching.
	 */
	protected static function cache_ttl(): int {
		return 300;
	}

	/**
	 * Register with the Abilities API. Must run on wp_abilities_api_init.
	 */
	public static function register(): void {
		wp_register_ability(
			static::NAME,
			array(
				'label'               => static::label(),
				'description'         => static::description(),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => static::input_schema(),
				'output_schema'       => static::output_schema(),
				'permission_callback' => array( static::class, 'permission' ),
				'execute_callback'    => array( static::class, 'execute' ),
				'meta'                => Registrar::meta(),
			)
		);
	}

	/**
	 * Permission callback. Audits denials, since core never calls execute() after one.
	 *
	 * @param mixed $input Ability input.
	 */
	public static function permission( $input = null ): bool {
		try {
			$allowed = (bool) static::can();
		} catch ( \Throwable $e ) {
			$allowed = false; // A broken check denies.
		}

		if ( ! $allowed ) {
			AuditLog::record( get_current_user_id(), static::NAME, self::to_array( $input ), 0, 0, 'denied' );
		}

		return $allowed;
	}

	/**
	 * Execute callback: cache, time, audit. Never throws.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ): array|\WP_Error {
		$input   = self::to_array( $input );
		$user_id = get_current_user_id();
		$ttl     = static::cache_ttl();
		$key     = $ttl > 0 ? self::cache_key( $input ) : '';
		$start   = hrtime( true );

		if ( '' !== $key ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				AuditLog::record( $user_id, static::NAME, $input, self::row_count( $cached ), self::elapsed_ms( $start ), 'ok' );
				return $cached;
			}
		}

		try {
			$result = static::run( $input );
		} catch ( \Throwable $e ) {
			AuditLog::record( $user_id, static::NAME, $input, 0, self::elapsed_ms( $start ), 'error' );
			return new \WP_Error(
				'blzmcp_ability_failed',
				__( 'This tool could not complete. Please try again later.', 'blaze-mcp-toolkit-for-woocommerce' )
			);
		}

		$duration = self::elapsed_ms( $start );

		if ( is_wp_error( $result ) ) {
			AuditLog::record( $user_id, static::NAME, $input, 0, $duration, 'error' );
			return $result;
		}

		if ( '' !== $key ) {
			set_transient( $key, $result, $ttl );
		}

		AuditLog::record( $user_id, static::NAME, $input, self::row_count( $result ), $duration, 'ok' );

		return $result;
	}

	/**
	 * Cache key: name + normalised input + the current user's sorted roles.
	 *
	 * @param array $input Input.
	 */
	protected static function cache_key( array $input ): string {
		$roles = array_map( 'strval', (array) wp_get_current_user()->roles );
		sort( $roles );

		return self::CACHE_PREFIX . md5( static::NAME . wp_json_encode( AuditLog::normalise( $input ) ) . implode( ',', $roles ) );
	}

	/**
	 * Count of the first list-valued key in the output, or 0.
	 *
	 * @param array $output Ability output.
	 */
	protected static function row_count( array $output ): int {
		foreach ( $output as $value ) {
			if ( is_array( $value ) && array_values( $value ) === $value ) {
				return count( $value );
			}
		}
		return 0;
	}

	/**
	 * Coerce input to an array.
	 *
	 * @param mixed $input Input.
	 */
	private static function to_array( $input ): array {
		if ( is_array( $input ) ) {
			return $input;
		}
		if ( is_object( $input ) ) {
			return (array) json_decode( (string) wp_json_encode( $input ), true );
		}
		return array();
	}

	/**
	 * Milliseconds since $start (hrtime nanoseconds).
	 *
	 * @param int $start hrtime(true) at start.
	 */
	private static function elapsed_ms( int $start ): int {
		return (int) round( ( hrtime( true ) - $start ) / 1e6 );
	}
}
