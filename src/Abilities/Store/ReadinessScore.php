<?php
/**
 * Ability: readiness-score (bundled from WooCommerce for Claude).
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities\Store;

use WooCommerce\CommerceAbilities\Abilities\Store\GetReadinessScoreAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Upstream GetReadinessScoreAbilityTrait, registered as blaze-mcp-toolkit/readiness-score.
 * Label and description are upstream's text; permission and behaviour are upstream's code.
 */
final class ReadinessScore extends AbstractStoreAbility {

	use GetReadinessScoreAbilityTrait {
		execute as private upstream_execute;
		permission_check as private upstream_permission_check;
	}

	public const NAME = 'blaze-mcp-toolkit/readiness-score';

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
		return __( 'Get readiness score', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description read by the AI client (upstream text).
	 */
	protected static function description(): string {
		return __( "Get the store's AI readiness score (0-100) with breakdown by factor: product completeness, schema coverage, policy completeness, and content quality.", 'blaze-mcp-toolkit-for-woocommerce' );
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
