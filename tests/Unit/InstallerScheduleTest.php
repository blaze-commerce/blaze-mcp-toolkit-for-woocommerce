<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Installer;
use PHPUnit\Framework\TestCase;

final class InstallerScheduleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['blzmcp_test_scheduled']   = array();
		$GLOBALS['blzmcp_test_transients']  = array();
		$GLOBALS['blzmcp_test_did_action']  = array();
	}

	public function test_schedules_immediately_when_scheduler_is_ready(): void {
		$GLOBALS['blzmcp_test_did_action']['action_scheduler_init'] = 1;
		Installer::schedule_cleanup();
		$this->assertSame( array( DAY_IN_SECONDS, 'blzmcp' ), $GLOBALS['blzmcp_test_scheduled']['blzmcp_daily_cleanup'] );
	}

	public function test_defers_until_scheduler_init_when_not_ready(): void {
		Installer::schedule_cleanup();
		$this->assertArrayNotHasKey( 'blzmcp_daily_cleanup', $GLOBALS['blzmcp_test_scheduled'] );

		$GLOBALS['blzmcp_test_did_action']['action_scheduler_init'] = 1;
		Installer::maybe_schedule();
		$this->assertArrayHasKey( 'blzmcp_daily_cleanup', $GLOBALS['blzmcp_test_scheduled'] );
		$this->assertFalse( get_transient( Installer::PENDING_FLAG ) );
	}

	public function test_maybe_schedule_does_nothing_without_flag(): void {
		Installer::maybe_schedule();
		$this->assertArrayNotHasKey( 'blzmcp_daily_cleanup', $GLOBALS['blzmcp_test_scheduled'] );
	}
}
