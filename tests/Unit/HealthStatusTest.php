<?php
/**
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Abilities\HealthSummary;
use PHPUnit\Framework\TestCase;

final class HealthStatusTest extends TestCase {

	private static function php( array $sources, array $groups ): array {
		return array(
			'kind'    => 'php_errors',
			'sources' => $sources,
			'groups'  => $groups,
		);
	}

	private static function source( bool $readable ): array {
		return array(
			'id'       => 'wp_debug',
			'label'    => 'WordPress debug.log',
			'readable' => $readable,
			'reason'   => $readable ? null : 'Log file does not exist',
		);
	}

	private static function group( string $severity, int $count ): array {
		return array(
			'severity' => $severity,
			'count'    => $count,
		);
	}

	private static function jobs( array $by_hook, int $failed, int $past_due, bool $available = true ): array {
		return array(
			'kind'      => 'background_jobs',
			'available' => $available,
			'failed'    => $failed,
			'past_due'  => $past_due,
			'by_hook'   => $by_hook,
		);
	}

	private static function updates( array $totals, ?string $core_current = '6.9.1', ?string $core_available = null, string $core_status = 'current' ): array {
		return array(
			'kind'   => 'updates',
			'core'   => array(
				'current'       => $core_current,
				'available'     => $core_available,
				'update_status' => $core_status,
			),
			'totals' => array_merge(
				array(
					'plugins'         => 5,
					'plugin_updates'  => 0,
					'plugins_unknown' => 0,
					'themes'          => 2,
					'theme_updates'   => 0,
					'themes_unknown'  => 0,
				),
				$totals
			),
		);
	}

	public function test_health_no_source_is_amber(): void {
		$this->assertSame( 'amber', HealthSummary::status_for( self::php( array( self::source( false ), self::source( false ) ), array() ) ) );
		$this->assertSame( 'amber', HealthSummary::status_for( self::php( array(), array() ) ) );
	}

	public function test_health_readable_and_quiet_is_green(): void {
		$this->assertSame( 'green', HealthSummary::status_for( self::php( array( self::source( true ) ), array( self::group( 'warning', 3 ), self::group( 'notice', 500 ) ) ) ) );
	}

	public function test_health_fatal_is_red(): void {
		$this->assertSame( 'red', HealthSummary::status_for( self::php( array( self::source( true ) ), array( self::group( 'warning', 1 ), self::group( 'fatal', 1 ) ) ) ) );
	}

	public function test_health_many_errors_is_amber(): void {
		$this->assertSame( 'amber', HealthSummary::status_for( self::php( array( self::source( true ) ), array( self::group( 'error', 11 ), self::group( 'warning', 10 ) ) ) ) );
		$this->assertSame( 'green', HealthSummary::status_for( self::php( array( self::source( true ) ), array( self::group( 'error', 10 ), self::group( 'warning', 10 ) ) ) ) );
	}

	public function test_health_uses_exact_counts_over_capped_groups(): void {
		$section           = self::php( array( self::source( true ) ), array( self::group( 'error', 2 ) ) );
		$section['counts'] = array( 'fatal' => 0, 'error' => 15, 'warning' => 6, 'notice' => 0, 'deprecated' => 0 );
		$this->assertSame( 'amber', HealthSummary::status_for( $section ) );
		$section['counts'] = array( 'fatal' => 1, 'error' => 0, 'warning' => 0, 'notice' => 0, 'deprecated' => 0 );
		$this->assertSame( 'red', HealthSummary::status_for( $section ) );
		$section['counts'] = array( 'fatal' => 0, 'error' => 10, 'warning' => 10, 'notice' => 99, 'deprecated' => 0 );
		$this->assertSame( 'green', HealthSummary::status_for( $section ) );
	}

	public function test_jobs_money_related_failure_is_red(): void {
		$by_hook = array(
			array( 'hook' => 'woocommerce_deliver_webhook_async', 'failed' => 1, 'past_due' => 0, 'money_related' => true ),
		);
		$this->assertSame( 'red', HealthSummary::status_for( self::jobs( $by_hook, 1, 0 ) ) );
	}

	public function test_jobs_other_failure_or_backlog_is_amber(): void {
		$by_hook = array(
			array( 'hook' => 'some_plugin_sync', 'failed' => 2, 'past_due' => 0, 'money_related' => false ),
		);
		$this->assertSame( 'amber', HealthSummary::status_for( self::jobs( $by_hook, 2, 0 ) ) );
		$this->assertSame( 'amber', HealthSummary::status_for( self::jobs( array(), 0, 11 ) ) );
		$this->assertSame( 'green', HealthSummary::status_for( self::jobs( array(), 0, 10 ) ) );
	}

	public function test_jobs_unavailable_is_amber(): void {
		$this->assertSame( 'amber', HealthSummary::status_for( self::jobs( array(), 0, 0, false ) ) );
	}

	public function test_money_related_matches_brief_pattern(): void {
		foreach ( array( 'woocommerce_send_queued_transactional_email', 'woocommerce_scheduled_subscription_payment', 'woocommerce_deliver_webhook_async', 'wc_admin_stock_sync', 'woocommerce_subscription_renewal' ) as $hook ) {
			$this->assertTrue( \Blaze\McpToolkit\Diagnostics\JobsHealth::is_money_related( $hook ), $hook );
		}
		foreach ( array( 'action_scheduler/migration_hook', 'wc-admin_import_orders', 'blzmcp_daily_cleanup' ) as $hook ) {
			$this->assertFalse( \Blaze\McpToolkit\Diagnostics\JobsHealth::is_money_related( $hook ), $hook );
		}
	}

	public function test_updates_unknown_plugins_and_themes_stay_green(): void {
		// Ruling: unknown plugin/theme update status adds a note only (custom plugins are always unknown).
		$this->assertSame( 'green', HealthSummary::status_for( self::updates( array( 'plugins_unknown' => 3, 'themes_unknown' => 2 ) ) ) );
		$this->assertSame( 'green', HealthSummary::status_for( self::updates( array( 'plugin_updates' => 10 ) ) ) );
	}

	public function test_updates_unknown_core_is_amber(): void {
		$this->assertSame( 'amber', HealthSummary::status_for( self::updates( array(), '6.9.1', null, 'unknown' ) ) );
	}

	public function test_updates_many_plugins_or_any_core_update_is_amber(): void {
		$this->assertSame( 'amber', HealthSummary::status_for( self::updates( array( 'plugin_updates' => 11 ) ) ) );
		$this->assertSame( 'amber', HealthSummary::status_for( self::updates( array(), '6.9.1', '7.0', 'available' ) ) );
		// Minor releases carry WordPress security fixes, so any core update is amber.
		$this->assertSame( 'amber', HealthSummary::status_for( self::updates( array(), '6.9.1', '6.9.2', 'available' ) ) );
		$this->assertSame( 'green', HealthSummary::status_for( self::updates( array(), '6.9.2', null, 'current' ) ) );
	}

	public function test_overall_is_worst(): void {
		$this->assertSame( 'red', HealthSummary::status_for( array( 'kind' => 'overall', 'statuses' => array( 'green', 'red', 'amber' ) ) ) );
		$this->assertSame( 'amber', HealthSummary::status_for( array( 'kind' => 'overall', 'statuses' => array( 'green', 'amber', 'green' ) ) ) );
		$this->assertSame( 'green', HealthSummary::status_for( array( 'kind' => 'overall', 'statuses' => array( 'green', 'green', 'green' ) ) ) );
		$this->assertSame( 'amber', HealthSummary::status_for( array( 'kind' => 'overall', 'statuses' => array() ) ) );
	}
}
