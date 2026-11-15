<?php
/**
 * Pure payment diagnostics rules (FEATURE-PLAN item 5): webhook vectors, log classification.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Diagnostics\PaymentErrors;
use PHPUnit\Framework\TestCase;

final class PaymentErrorsTest extends TestCase {

	private const NOW = 1794700800;

	/** @return array<string,array{0:array}> */
	public function webhook_cases(): array {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/payment-gateway-contracts.json' ), true );
		$this->assertSame( self::NOW, $fixture['synthetic_cases']['now'] );
		$out = array();
		foreach ( $fixture['synthetic_cases']['webhook'] as $case ) {
			$out[ $case['id'] ] = array( $case );
		}
		return $out;
	}

	/**
	 * @dataProvider webhook_cases
	 */
	public function test_webhook_fixture_vector( array $case ): void {
		$records = array();
		foreach ( array( 'live', 'test' ) as $mode ) {
			if ( isset( $case[ $mode ] ) ) {
				$records[ $mode ] = $case[ $mode ];
			}
		}
		$got = PaymentErrors::webhook_status( $case['settings'], $records, self::NOW, $case['accepted_orders_7d'], $case['webhook_required'] );
		$this->assertSame( $case['expect']['status'], $got['status'], $case['id'] . ' status' );
		$this->assertSame( $case['expect']['mode'], $got['mode'], $case['id'] . ' mode' );
		$this->assertSame( $case['expect']['category'], $got['category'], $case['id'] . ' category' );
		$this->assertContains( $got['category'], PaymentErrors::WEBHOOK_CATEGORIES );
	}

	public function test_webhook_fixture_has_all_cases(): void {
		$this->assertCount( 21, $this->webhook_cases(), '20 item 1 vectors plus the fix round 2 unverified-stale vector' );
		$this->assertArrayHasKey( 'stale_success_recent_orders_unverified', $this->webhook_cases() );
	}

	public function test_webhook_old_failure_without_any_success_is_unknown(): void {
		$got = PaymentErrors::webhook_status( array( 'testmode' => 'no' ), array( 'live' => array( 'last_success_at' => '0', 'last_failure_at' => (string) ( self::NOW - 100 * 3600 ) ) ), self::NOW, 5, null );
		$this->assertSame( 'unknown', $got['status'] );
		$this->assertSame( 'no_success_recorded', $got['category'] );
	}

	public function test_webhook_boundary_72_hours(): void {
		$at = PaymentErrors::webhook_status( array(), array( 'live' => array( 'last_success_at' => '1', 'last_failure_at' => (string) ( self::NOW - 72 * 3600 ) ) ), self::NOW, 0, null );
		$this->assertSame( 'red', $at['status'], 'exactly 72 hours old is still inside the window' );
		$past = PaymentErrors::webhook_status( array(), array( 'live' => array( 'last_success_at' => '1', 'last_failure_at' => (string) ( self::NOW - 72 * 3600 - 1 ) ) ), self::NOW, 0, null );
		$this->assertNotSame( 'red', $past['status'] );
	}

	public function test_webhook_int_values_and_non_scalars(): void {
		$ok = PaymentErrors::webhook_status( array(), array( 'live' => array( 'last_success_at' => self::NOW - 60, 'last_failure_at' => 0 ) ), self::NOW, 1, false );
		$this->assertSame( 'green', $ok['status'] );
		$bad = PaymentErrors::webhook_status( array(), array( 'live' => array( 'last_success_at' => array( 1 ), 'last_failure_at' => 0 ) ), self::NOW, 1, false );
		$this->assertSame( 'record_malformed', $bad['category'] );
	}

	private static function lines(): array {
		return explode( "\n", rtrim( (string) file_get_contents( __DIR__ . '/../fixtures/payment-log-lines.txt' ), "\n" ) );
	}

	public function test_classify_counts_entries_not_lines_and_only_error_levels(): void {
		$c = PaymentErrors::classify_lines( self::lines(), self::NOW - 86400, self::NOW, 'stripe' );
		$this->assertSame( 6, $c['count'], 'ERROR, CRITICAL, ALERT and EMERGENCY entries in the window' );
		$this->assertSame( 1, $c['webhook_failures'], 'only the verified error-level prefix' );
		$this->assertSame( 1, $c['malformed'] );
		$by = array();
		foreach ( $c['groups'] as $g ) {
			$by[ $g['severity'] . '|' . $g['category'] ] = $g['count'];
		}
		$this->assertSame(
			array(
				'error|webhook_signature_failed' => 1,
				'error|gateway_error'            => 2,
				'fatal|gateway_fatal'            => 3,
			),
			$by
		);
	}

	public function test_classify_emits_no_source_text(): void {
		$c    = PaymentErrors::classify_lines( self::lines(), self::NOW - 86400, self::NOW, 'stripe' );
		$json = (string) json_encode( $c );
		foreach ( array( 'jane', 'Jane', '+61', 'Example Street', 'pi_SYNTHETIC', 'Ignore previous', 'signature_mismatch', 'synthetic', '401' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
		foreach ( $c['groups'] as $g ) {
			$this->assertContains( $g['category'], PaymentErrors::LOG_CATEGORIES );
			$this->assertSame( array( 'severity', 'category', 'count', 'first', 'last' ), array_keys( $g ) );
		}
	}

	public function test_webhook_prefix_is_stripe_only_and_mentions_do_not_escalate(): void {
		$other = PaymentErrors::classify_lines( self::lines(), self::NOW - 86400, self::NOW, 'paypal' );
		$this->assertSame( 0, $other['webhook_failures'], 'no verified prefix for unverified families' );
		$this->assertSame( 'amber', PaymentErrors::log_verdict( $other['count'], $other['webhook_failures'], true ), 'mentions of webhook, signature or 401 stay amber' );
	}

	/** @return array<string,array{0:int,1:int,2:bool,3:string}> */
	public function log_verdicts(): array {
		return array(
			'empty complete'        => array( 0, 0, true, 'green' ),
			'empty partial'         => array( 0, 0, false, 'unknown' ),
			'one error'             => array( 1, 0, true, 'amber' ),
			'19 errors'             => array( 19, 0, true, 'amber' ),
			'20 errors'             => array( 20, 0, true, 'red' ),
			'webhook failure'       => array( 1, 1, true, 'red' ),
			'partial still alerts'  => array( 3, 0, false, 'amber' ),
		);
	}

	/**
	 * @dataProvider log_verdicts
	 */
	public function test_log_verdict( int $count, int $webhook, bool $complete, string $want ): void {
		$this->assertSame( $want, PaymentErrors::log_verdict( $count, $webhook, $complete ) );
	}

	// ---------------------------------------------------------------- Q6: unmapped families.

	protected function tearDown(): void {
		unset( $GLOBALS['blzmcp_test_caps'], $GLOBALS['blzmcp_test_options'] );
	}

	/** Stripe-only verified token map, as PaymentMethods::token_map() returns it by default. */
	private static function families(): array {
		return array(
			'stripe' => array(
				'plugin'          => array( 'woocommerce-gateway-stripe' ),
				'method_ids'      => array( 'stripe' ),
				'method_prefixes' => array( 'stripe_' ),
				'log_sources'     => array( 'woocommerce-gateway-stripe' ),
				'hooks'           => array( 'wc_stripe_deferred_webhook' ),
				'hook_groups'     => array( 'woocommerce_stripe' ),
			),
		);
	}

	/**
	 * @return array<string,array{0:array<int,string>}>
	 */
	public function permissions(): array {
		return array(
			'diagnostics allowed' => array( array( 'blzmcp_view_diagnostics' ) ),
			'diagnostics denied'  => array( array() ),
		);
	}

	/**
	 * @dataProvider permissions
	 * @param array<int,string> $caps Capabilities of the current user.
	 */
	public function test_unmapped_gateway_logs_and_jobs_are_not_applicable( array $caps ): void {
		$GLOBALS['blzmcp_test_caps'] = $caps;
		$m   = array(
			'othergw' => array( 'id' => 'othergw', 'family' => null, 'kind' => 'instant', 'webhook_adapter' => null, 'webhook_required' => null ),
		);
		$out = PaymentErrors::for_methods( $m, self::families(), array(), self::NOW, 24, new \DateTimeZone( 'UTC' ) )['othergw'];
		foreach ( array( 'logs', 'jobs', 'webhook' ) as $k ) {
			$this->assertFalse( $out[ $k ]['checked'], $k );
			$this->assertFalse( $out[ $k ]['applicable'], $k );
			$this->assertSame( 'not_applicable', $out[ $k ]['coverage'], $k );
		}
		$this->assertSame( 'no_verified_mapping', $out['logs']['reason'] );
		$this->assertSame( 'no_verified_mapping', $out['jobs']['reason'] );
		$this->assertNull( $out['logs']['count'] );
		$this->assertNull( $out['jobs']['failed'] );
		$this->assertContains( 'no_verified_mapping', \Blaze\McpToolkit\Diagnostics\PaymentRules::REASONS );
	}

	public function test_family_without_log_tokens_is_unmapped_for_logs_only(): void {
		$GLOBALS['blzmcp_test_caps'] = array( 'blzmcp_view_diagnostics' );
		$families                    = array(
			'acme' => array(
				'plugin'          => array(),
				'method_ids'      => array( 'acme' ),
				'method_prefixes' => array(),
				'log_sources'     => array(),
				'hooks'           => array( 'acme_job' ),
				'hook_groups'     => array(),
			),
		);
		$m   = array( 'acme' => array( 'id' => 'acme', 'family' => 'acme', 'kind' => 'instant', 'webhook_adapter' => null, 'webhook_required' => null ) );
		$out = PaymentErrors::for_methods( $m, $families, array(), self::NOW, 24, new \DateTimeZone( 'UTC' ) )['acme'];
		$this->assertSame( 'no_verified_mapping', $out['logs']['reason'] );
		$this->assertTrue( $out['jobs']['applicable'], 'hook tokens make jobs a mapped check' );
		$this->assertSame( 'unavailable', $out['jobs']['coverage'], 'Action Scheduler is not loaded in unit tests' );
	}

	public function test_unverified_stale_success_is_unknown_partial(): void {
		$s = (string) ( self::NOW - 8 * 86400 );
		$r = array( 'live' => array( 'last_success_at' => $s, 'last_failure_at' => '0' ) );
		$this->assertSame( array( 'unknown', 'webhook_stale_unverified' ), array_values( array_intersect_key( PaymentErrors::webhook_status( array(), $r, self::NOW, 3, null ), array_flip( array( 'status', 'category' ) ) ) ) );
		$this->assertSame( 'green', PaymentErrors::webhook_status( array(), $r, self::NOW, 0, null )['status'], 'no recent orders: no stale allegation' );
		$this->assertSame( 'green', PaymentErrors::webhook_status( array(), $r, self::NOW, 3, false )['status'], 'verified not required: fine' );
		$this->assertSame( 'amber', PaymentErrors::webhook_status( array(), $r, self::NOW, 3, true )['status'], 'verified required: stale amber' );

		// Through the check: coverage partial for the unverified case, unavailable for an unknown mode.
		$GLOBALS['blzmcp_test_caps']    = array();
		$GLOBALS['blzmcp_test_options'] = array(
			'woocommerce_stripe_settings'  => array( 'testmode' => 'no' ),
			'wc_stripe_wh_last_success_at' => $s,
			'wc_stripe_wh_last_failure_at' => '0',
		);
		$m   = array( 'stripe_ach' => array( 'id' => 'stripe_ach', 'family' => 'stripe', 'kind' => 'unknown', 'webhook_adapter' => 'stripe', 'webhook_required' => null ) );
		$tz  = new \DateTimeZone( 'UTC' );
		$out = PaymentErrors::for_methods( $m, self::families(), array( 'stripe_ach' => 3 ), self::NOW, 24, $tz )['stripe_ach']['webhook'];
		$this->assertSame( array( 'unknown', 'partial', 'webhook_stale_unverified' ), array( $out['status'], $out['coverage'], $out['category'] ) );
		$GLOBALS['blzmcp_test_options'] = array();
		$out = PaymentErrors::for_methods( $m, self::families(), array( 'stripe_ach' => 3 ), self::NOW, 24, $tz )['stripe_ach']['webhook'];
		$this->assertSame( array( 'unknown', 'unavailable', 'mode_unknown', false ), array( $out['status'], $out['coverage'], $out['category'], $out['checked'] ) );
	}

	public function test_mapped_gateway_with_unavailable_log_read_stays_unknown(): void {
		$GLOBALS['blzmcp_test_caps'] = array( 'blzmcp_view_diagnostics' );
		$m   = array(
			'stripe' => array( 'id' => 'stripe', 'family' => 'stripe', 'kind' => 'instant', 'webhook_adapter' => 'stripe', 'webhook_required' => false ),
		);
		// No WooCommerce logging classes or Action Scheduler are loaded here: both reads are unavailable.
		$out = PaymentErrors::for_methods( $m, self::families(), array(), self::NOW, 24, new \DateTimeZone( 'UTC' ) )['stripe'];
		$this->assertTrue( $out['logs']['applicable'] );
		$this->assertSame( 'unavailable', $out['logs']['coverage'] );
		$this->assertSame( 'logging_unavailable', $out['logs']['reason'] );
		$this->assertTrue( $out['jobs']['applicable'] );
		$this->assertSame( 'unavailable', $out['jobs']['coverage'] );
		$this->assertSame( 'unknown', $out['status'] );
	}
}
