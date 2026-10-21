<?php
/**
 * Suggested privacy policy text.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a section to Settings > Privacy > Policy Guide.
 */
final class PolicyText {

	/**
	 * Hook in (admin only).
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add' ) );
	}

	/**
	 * Register the suggested text.
	 */
	public static function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'Blaze MCP Toolkit for WooCommerce', wp_kses_post( self::content() ) );
	}

	/**
	 * The suggested policy text (HTML).
	 */
	public static function content(): string {
		$paragraphs = array(
			__( 'Suggested text: Blaze MCP Toolkit for WooCommerce lets a store owner or team member connect an AI assistant (Claude from Anthropic, or ChatGPT from OpenAI) to this store. When someone asks the assistant a question, the answer, built from this store\'s data, is sent to that AI provider and handled under the provider\'s own terms and privacy policy.', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'The tools in this plugin send aggregate figures and store health information only, such as totals, counts and error summaries. They do not send customer names, email addresses, phone numbers, postal addresses or IP addresses. The plugin does not contact any server itself.', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'Other tools connected through the same assistant, such as those from WooCommerce or Enable Abilities for MCP, may send personal data such as customer and order details to the AI provider. Review which tools are switched on in the Enable Abilities settings.', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'The plugin keeps a log of tool calls (time, user role, tool name, row count and duration, with no names or email addresses) for 30 days, and a log of store changes such as plugin updates for 180 days.', 'blaze-mcp-toolkit-for-woocommerce' ),
		);

		return '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>';
	}
}
