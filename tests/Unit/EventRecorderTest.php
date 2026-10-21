<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Events\EventRecorder;
use PHPUnit\Framework\TestCase;

final class EventRecorderTest extends TestCase {

	public function test_plugin_slug_is_folder_or_basename(): void {
		$this->assertSame( 'woocommerce', EventRecorder::plugin_slug( 'woocommerce/woocommerce.php' ) );
		$this->assertSame( 'hello', EventRecorder::plugin_slug( 'hello.php' ) );
	}

	public function test_single_update_targets(): void {
		$this->assertSame(
			array( array( 'plugin', 'woocommerce/woocommerce.php' ) ),
			EventRecorder::targets(
				array(
					'type'   => 'plugin',
					'action' => 'update',
					'plugin' => 'woocommerce/woocommerce.php',
				)
			)
		);
	}

	public function test_bulk_update_targets(): void {
		$this->assertSame(
			array( array( 'plugin', 'a/a.php' ), array( 'plugin', 'b/b.php' ) ),
			EventRecorder::targets(
				array(
					'type'    => 'plugin',
					'action'  => 'update',
					'bulk'    => true,
					'plugins' => array( 'a/a.php', 'b/b.php' ),
				)
			)
		);
		$this->assertSame(
			array( array( 'theme', 'twentytwentyfive' ) ),
			EventRecorder::targets(
				array(
					'type'   => 'theme',
					'themes' => array( 'twentytwentyfive' ),
				)
			)
		);
	}

	public function test_core_and_translation_have_no_targets(): void {
		$this->assertSame( array(), EventRecorder::targets( array( 'type' => 'core' ) ) );
		$this->assertSame( array(), EventRecorder::targets( array( 'type' => 'translation' ) ) );
		$this->assertSame( array(), EventRecorder::targets( array( 'type' => 'plugin' ) ) );
	}

	public function test_build_events_pairs_before_and_after_for_bulk(): void {
		$targets = array( array( 'plugin', 'a/a.php' ), array( 'plugin', 'b/b.php' ), array( 'theme', 'tt' ) );
		$before  = array(
			'plugin:a/a.php' => '1.0',
			'plugin:b/b.php' => '2.0',
		);
		$after   = array(
			'plugin:a/a.php' => '1.1',
			'plugin:b/b.php' => '2.5',
			'theme:tt'       => '3.0',
		);
		$this->assertSame(
			array(
				array( 'plugin_updated', 'a', '1.0', '1.1' ),
				array( 'plugin_updated', 'b', '2.0', '2.5' ),
				array( 'theme_updated', 'tt', null, '3.0' ),
			),
			EventRecorder::build_events( $targets, $before, $after, false )
		);
	}

	public function test_build_events_install_has_no_from_version(): void {
		$this->assertSame(
			array( array( 'plugin_installed', 'new-plugin', null, '1.0' ) ),
			EventRecorder::build_events( array( array( 'plugin', 'new-plugin/new-plugin.php' ) ), array(), array( 'plugin:new-plugin/new-plugin.php' => '1.0' ), true )
		);
	}
}
