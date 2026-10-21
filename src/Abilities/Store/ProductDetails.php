<?php
/**
 * Ability: product-details (bundled from WooCommerce for Claude).
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities\Store;

use WooCommerce\CommerceAbilities\Abilities\Store\GetProductDetailsAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Upstream GetProductDetailsAbilityTrait, registered as blaze-mcp-toolkit/product-details.
 * Label and description are upstream's text; permission and behaviour are upstream's code.
 */
final class ProductDetails extends AbstractStoreAbility {

	use GetProductDetailsAbilityTrait {
		execute as private upstream_execute;
		permission_check as private upstream_permission_check;
		input_schema as private upstream_input_schema;
		output_schema as private upstream_output_schema;
	}

	public const NAME = 'blaze-mcp-toolkit/product-details';

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
		return __( 'Get product details', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description read by the AI client (upstream text).
	 */
	protected static function description(): string {
		return __( 'Get full details for a specific product including description, attributes, images, relationships, and completeness score.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Input schema (upstream).
	 *
	 * @return array<string, mixed>
	 */
	protected static function input_schema(): array {
		return self::upstream_input_schema();
	}

	/**
	 * Output schema (upstream).
	 *
	 * @return array<string, mixed>
	 */
	protected static function output_schema(): array {
		return self::upstream_output_schema();
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
		return self::normalise( self::upstream_execute( $input ) );
	}
}
