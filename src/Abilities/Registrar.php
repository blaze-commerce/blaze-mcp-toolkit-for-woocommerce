<?php
/**
 * Ability category and registration.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blaze-mcp-toolkit category and every ability class in ABILITIES.
 */
final class Registrar {

	public const CATEGORY = 'blaze-mcp-toolkit';

	/**
	 * Ability classes (subclasses of AbstractAbility). Later tasks append here.
	 *
	 * @var array<int, class-string<AbstractAbility>>
	 */
	public const ABILITIES = array(
		HealthSummary::class,
		UpdateHistory::class,
		AccessCheck::class,
	);

	/**
	 * Ability classes added at runtime (the bundled store tools, unless standing aside).
	 *
	 * @var array<int, class-string<AbstractAbility>>
	 */
	private static array $extra = array();

	/**
	 * Add an ability class to register alongside ABILITIES. Must run before wp_abilities_api_init.
	 *
	 * @param class-string<AbstractAbility> $class_name Ability class.
	 */
	public static function add( string $class_name ): void {
		if ( ! in_array( $class_name, self::$extra, true ) ) {
			self::$extra[] = $class_name;
		}
	}

	/**
	 * Every ability class this request will register.
	 *
	 * @return array<int, class-string<AbstractAbility>>
	 */
	public static function abilities(): array {
		return array_merge( self::ABILITIES, self::$extra );
	}

	/**
	 * Hook into the Abilities API.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Shared ability metadata: REST-visible, public over MCP, read-only.
	 *
	 * @return array<string, mixed>
	 */
	public static function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	/**
	 * Register the category. Runs on wp_abilities_api_categories_init.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Blaze MCP Toolkit', 'blaze-mcp-toolkit-for-woocommerce' ),
				'description' => __( 'Read-only WooCommerce store health and insight tools.', 'blaze-mcp-toolkit-for-woocommerce' ),
			)
		);
	}

	/**
	 * Register every ability. Runs on wp_abilities_api_init.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( self::abilities() as $class ) {
			if ( is_subclass_of( $class, AbstractAbility::class ) ) {
				$class::register();
			}
		}
	}
}
