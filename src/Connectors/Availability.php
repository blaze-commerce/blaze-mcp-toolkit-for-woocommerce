<?php
/**
 * Why an ability is not reachable through the active connector.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * Value object returned by Connector::diagnose().
 */
final class Availability {

	public const REMOVED_GLOBALLY = 'removed_globally';
	public const DISABLED_HERE    = 'disabled_here';
	public const UNKNOWN          = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param string $kind         One of the class constants.
	 * @param string $connector_id Connector id that reported it.
	 * @param string $reason       Owner-facing explanation.
	 * @param string $settings_url Where the owner can change it.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $connector_id,
		public readonly string $reason,
		public readonly string $settings_url
	) {}
}
