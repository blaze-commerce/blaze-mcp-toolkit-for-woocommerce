<?php
/**
 * Shared adapter for the bundled WooCommerce for Claude store-knowledge tools.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities\Store;

use Blaze\McpToolkit\Abilities\AbstractAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Each subclass composes one upstream trait from WooCommerce\CommerceAbilities\Abilities\Store,
 * aliasing the trait's execute(), permission_check() and schema methods so they do not replace
 * AbstractAbility's audited execute() and permission(). run() and can() then delegate to the
 * upstream code unchanged, so calls get our audit log, cache and denial logging.
 */
abstract class AbstractStoreAbility extends AbstractAbility {

	/**
	 * Input schema for tools that take no input (upstream's empty schema, plus an empty default
	 * so a call with no arguments passes validation).
	 *
	 * @return array<string, mixed>
	 */
	protected static function empty_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
			'default'              => new \stdClass(),
		);
	}

	/**
	 * Output schema for tools upstream registers without one.
	 *
	 * @return array<string, mixed>
	 */
	protected static function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Upstream may return null when a knowledge provider has nothing; the ability contract needs
	 * an array or a WP_Error.
	 *
	 * @param mixed $result Upstream result.
	 * @return array|\WP_Error
	 */
	protected static function normalise( $result ): array|\WP_Error {
		if ( is_array( $result ) || is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_Error(
			'blzmcp_store_data_unavailable',
			__( 'This store information is not available right now.', 'blaze-mcp-toolkit-for-woocommerce' )
		);
	}
}
