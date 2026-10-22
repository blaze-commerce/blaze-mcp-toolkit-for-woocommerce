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
	/**
	 * @return string[]
	 */
	private static function lines( string $level, string $prefix, int $n, int $repeat = 1 ): array {
		$stamp = '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] ';
		$out   = array();
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $r = 0; $r < $repeat; $r++ ) {
				$out[] = $stamp . 'PHP ' . $level . ': ' . $prefix . '_' . chr( 65 + intdiv( $i, 26 ) ) . chr( 65 + $i % 26 );
			}
		}
		return $out;
	}

	/**
	 * Audit F04: totals and status come from every group, not the 50 shown.
	 */
	public function test_sixty_distinct_warnings_report_sixty(): void {
		$sum = HealthSummary::summarise_groups( \Blaze\McpToolkit\Diagnostics\ErrorGrouper::group_all( self::lines( 'Warning', 'component', 60 ), 0, 'wp_debug' ) );
		$this->assertSame( 60, $sum['counts']['warning'] );
		$this->assertSame( 60, $sum['groups_total'] );
		$this->assertTrue( $sum['groups_truncated'] );
		$this->assertCount( 50, $sum['groups'] );
		$this->assertSame( 'amber', HealthSummary::status_for( array( 'kind' => 'php_errors', 'sources' => array( self::source( true ) ) ) + $sum ) );
	}

	public function test_warnings_crowded_out_by_deprecations_stay_amber(): void {
		$lines = array_merge( self::lines( 'Deprecated', 'component', 50, 2 ), self::lines( 'Warning', 'warning', 21 ) );
		$sum   = HealthSummary::summarise_groups( \Blaze\McpToolkit\Diagnostics\ErrorGrouper::group_all( $lines, 0, 'wp_debug' ) );
		$this->assertSame( 21, $sum['counts']['warning'] );
		$this->assertSame( 100, $sum['counts']['deprecated'] );
		$this->assertSame( 71, $sum['groups_total'] );
		$this->assertCount( 50, $sum['groups'] );
		$this->assertSame( 'amber', HealthSummary::status_for( array( 'kind' => 'php_errors', 'sources' => array( self::source( true ) ) ) + $sum ) );
	}

	public function test_multi_source_totals_survive_the_cap(): void {
		$grouper = \Blaze\McpToolkit\Diagnostics\ErrorGrouper::class;
		$all     = array_merge(
			$grouper::group_all( self::lines( 'Warning', 'debug', 40 ), 0, 'wp_debug' ),
			$grouper::group_all( self::lines( 'Notice', 'ini', 30 ), 0, 'php_error_log' ),
			$grouper::group_all( self::lines( 'Fatal error', 'wc', 5 ), 0, 'wc_fatal' )
		);
		$sum = HealthSummary::summarise_groups( $all );
		$this->assertSame( 40, $sum['counts']['warning'] );
		$this->assertSame( 30, $sum['counts']['notice'] );
		$this->assertSame( 5, $sum['counts']['fatal'] );
		$this->assertSame( 75, $sum['groups_total'] );
		$this->assertTrue( $sum['groups_truncated'] );
		$this->assertCount( 50, $sum['groups'] );
		$this->assertSame( 5, count( array_filter( $sum['groups'], static fn( $g ) => 'fatal' === $g['severity'] ) ) );
	}

	public function test_small_logs_are_not_truncated(): void {
		$sum = HealthSummary::summarise_groups( \Blaze\McpToolkit\Diagnostics\ErrorGrouper::group_all( self::lines( 'Warning', 'w', 3 ), 0, 'wp_debug' ) );
		$this->assertSame( 3, $sum['groups_total'] );
		$this->assertFalse( $sum['groups_truncated'] );
		$this->assertSame( 'green', HealthSummary::status_for( array( 'kind' => 'php_errors', 'sources' => array( self::source( true ) ) ) + $sum ) );
	}
}
