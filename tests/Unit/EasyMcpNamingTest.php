<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Connectors\EasyMcpNaming;
use PHPUnit\Framework\TestCase;

final class EasyMcpNamingTest extends TestCase {

	/**
	 * Expected values computed with Easy MCP 2.4.0's own build_tool_name().
	 *
	 * @return array<int, array{0:string,1:string}>
	 */
	public static function easy_mcp_240_outputs(): array {
		return array(
			array( 'wc-analytics/totals', 'wp_ability_wc_analytics_totals' ),
			array( 'wc-analytics/confirm-large-range', 'wp_ability_wc_analytics_confirm_large_range' ),
			array( 'blaze-mcp-toolkit/health-summary', 'wp_ability_blaze_mcp_toolkit_health_summary' ),
			array( 'blaze-mcp-toolkit/weekly-store-review', 'wp_ability_blaze_mcp_toolkit_weekly_store_review' ),
			array( 'Blaze-MCP-Toolkit/Store_Profile', 'wp_ability_blaze_mcp_toolkit_store_profile' ),
			array( 'blaze-mcp-toolkit/a-very-long-ability-name-that-goes-past-the-sixty-four-limit', 'wp_ability_blaze_mcp_toolkit_a_very_long_ability_name_t_2031be6c' ),
		);
	}

	/**
	 * @dataProvider easy_mcp_240_outputs
	 */
	public function test_matches_easy_mcp_240_outputs( string $slug, string $expected ): void {
		$this->assertSame( $expected, EasyMcpNaming::tool_name( $slug ) );
	}

	public function test_never_exceeds_64_chars(): void {
		foreach ( self::easy_mcp_240_outputs() as $case ) {
			$this->assertLessThanOrEqual( 64, strlen( EasyMcpNaming::tool_name( $case[0] ) ) );
		}
		$this->assertLessThanOrEqual( 64, strlen( EasyMcpNaming::tool_name( str_repeat( 'a-', 100 ) ) ) );
	}
}
