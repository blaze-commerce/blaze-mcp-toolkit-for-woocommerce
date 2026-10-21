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
			'theme:tt'       => '2.9',
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
				array( 'theme_updated', 'tt', '2.9', '3.0' ),
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

	public function test_bulk_pre_install_shape_without_type_is_inferred(): void {
		$pre = array(
			'plugin'      => 'hello-dolly/hello.php',
			'temp_backup' => array( 'dir' => 'plugins' ),
		);
		$this->assertSame( array( array( 'plugin', 'hello-dolly/hello.php' ) ), EventRecorder::targets( $pre ) );
		$this->assertSame( array( array( 'theme', 'tt' ) ), EventRecorder::targets( array( 'theme' => 'tt' ) ) );

		// Complete shape for the same bulk update, then pair versions.
		$complete = array(
			'type'    => 'plugin',
			'action'  => 'update',
			'bulk'    => true,
			'plugins' => array( 'hello-dolly/hello.php' ),
		);
		$this->assertSame(
			array( array( 'plugin_updated', 'hello-dolly', '1.0', '1.7.2' ) ),
			EventRecorder::build_events( EventRecorder::targets( $complete ), array( 'plugin:hello-dolly/hello.php' => '1.0' ), array( 'plugin:hello-dolly/hello.php' => '1.7.2' ), false )
		);
	}

	public function test_unchanged_versions_are_skipped(): void {
		$this->assertSame(
			array(),
			EventRecorder::build_events( array( array( 'plugin', 'a/a.php' ) ), array( 'plugin:a/a.php' => '1.0' ), array( 'plugin:a/a.php' => '1.0' ), false )
		);
	}

	public function test_install_over_existing_is_an_update(): void {
		$this->assertSame(
			array( array( 'plugin_updated', 'a', '1.0', '1.1' ) ),
			EventRecorder::build_events( array( array( 'plugin', 'a/a.php' ) ), array( 'plugin:a/a.php' => '1.0' ), array( 'plugin:a/a.php' => '1.1' ), true )
		);
	}

	public function test_update_without_captured_before_is_skipped(): void {
		$this->assertSame(
			array(),
			EventRecorder::build_events( array( array( 'plugin', 'hello.php' ) ), array(), array( 'plugin:hello.php' => '1.7.2' ), false )
		);
	}

	public function test_target_without_resulting_version_is_skipped(): void {
		$this->assertSame(
			array(),
			EventRecorder::build_events( array( array( 'plugin', 'nonexistent/x.php' ) ), array( 'plugin:nonexistent/x.php' => '' ), array( 'plugin:nonexistent/x.php' => '' ), false )
		);
	}
}
