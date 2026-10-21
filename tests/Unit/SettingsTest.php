<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['blzmcp_test_options']         = array();
		$GLOBALS['blzmcp_test_option_autoload'] = array();
	}

	public function test_defaults_when_nothing_stored(): void {
		$this->assertSame(
			array(
				'shop_manager_diagnostics' => false,
				'db_version'               => '1',
				'activated_at'             => 0,
			),
			Settings::get()
		);
		$this->assertFalse( Settings::shop_manager_diagnostics() );
	}

	public function test_update_merges_and_is_never_autoloaded(): void {
		Settings::update( array( 'shop_manager_diagnostics' => true ) );
		$this->assertTrue( Settings::shop_manager_diagnostics() );
		$this->assertSame( '1', Settings::get()['db_version'] );
		$this->assertFalse( $GLOBALS['blzmcp_test_option_autoload']['blzmcp_settings'] );
	}

	public function test_update_ignores_unknown_keys(): void {
		Settings::update( array( 'evil' => 'x' ) );
		$this->assertArrayNotHasKey( 'evil', Settings::get() );
	}

	public function test_corrupt_stored_value_falls_back_to_defaults(): void {
		$GLOBALS['blzmcp_test_options']['blzmcp_settings'] = 'garbage';
		$this->assertFalse( Settings::shop_manager_diagnostics() );
	}
}
