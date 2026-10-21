<?php
/**
 * Ability: store-profile (bundled from WooCommerce for Claude).
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities\Store;

use WooCommerce\CommerceAbilities\Abilities\Store\GetStoreProfileAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Upstream GetStoreProfileAbilityTrait, registered as blaze-mcp-toolkit/store-profile.
 * Label and description are upstream's text; permission and behaviour are upstream's code.
 */
final class StoreProfile extends AbstractStoreAbility {

	use GetStoreProfileAbilityTrait {
		execute as private upstream_execute;
		permission_check as private upstream_permission_check;
	}

	public const NAME = 'blaze-mcp-toolkit/store-profile';

	/**
	 * Name the upstream trait reads (static::ABILITY_NAME).
	 */
	public const ABILITY_NAME = self::NAME;

	/**
	 * Execute callback: AbstractAbility's audited execute, not the trait's. Not a useless override:
	 * without it the trait's execute() would replace the inherited one and skip audit and cache.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		return parent::execute( $input );
	}

	/**
	 * Label (upstream text).
	 */
	protected static function label(): string {
		return __( 'Get store profile', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description read by the AI client (upstream text).
	 */
	protected static function description(): string {
		return __( "Get the store's identity, configuration, payment methods, shipping zones, and features. This goes beyond WooCommerce core MCP by providing structured store knowledge for AI reasoning. Use this first to understand the store context.", 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Input schema: none.
	 *
	 * @return array<string, mixed>
	 */
	protected static function input_schema(): array {
		return self::empty_input_schema();
	}

	/**
	 * Version value upstream puts in the store profile payload (ours, not WooCommerce for Claude's).
	 */
	private static function profile_version(): string {
		return BLZMCP_VERSION;
	}

	/**
	 * Upstream permission check.
	 */
	protected static function can(): bool {
		return (bool) self::upstream_permission_check( null );
	}

	/**
	 * Run the upstream implementation.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	protected static function run( array $input ): array|\WP_Error {
		$result = self::normalise( self::upstream_execute( $input ) );

		// Our outputs never carry email addresses: drop the site admin email upstream includes.
		if ( is_array( $result ) && isset( $result['data']['identity'] ) && is_array( $result['data']['identity'] ) ) {
			unset( $result['data']['identity']['admin_email'] );
		}

		return $result;
	}
}
