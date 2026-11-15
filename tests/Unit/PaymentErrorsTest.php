<?php
/**
 * Pure payment diagnostics rules (FEATURE-PLAN item 5): webhook vectors, log classification.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Diagnostics\PaymentErrors;
use Blaze\McpToolkit\Diagnostics\PaymentMethods;
use Blaze\McpToolkit\Diagnostics\PaymentRules;
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

	// ---------------------------------------------------------------- parse coverage (pass 4 #5).

	public function test_valid_multiline_entries_are_fully_parsed(): void {
		$c = PaymentErrors::classify_lines(
			array(
				'2026-11-14T20:00:00+00:00 ERROR First line of a multi-line message',
				'    second line of the same entry',
				'',
				'{"context":"third line"}',
				'2026-11-14T20:01:00+00:00 INFO Next entry',
			),
			self::NOW - 86400,
			self::NOW,
			'stripe'
		);
		$this->assertSame( array( 1, 0, 2 ), array( $c['count'], $c['malformed'], $c['heads'] ), 'continuations after a recognised head are part of that entry' );
	}

	public function test_stream_with_no_recognised_head_is_unparsed(): void {
		$lines = array(
			'[14-Nov-2026 20:00:00 UTC] PHP Fatal error: gateway exploded',
			'Stack trace:',
			'#0 {main}',
		);
		$c = PaymentErrors::classify_lines( $lines, self::NOW - 86400, self::NOW, 'stripe' );
		$this->assertSame( 0, $c['heads'] );
		$this->assertSame( 0, $c['count'] );
		$this->assertGreaterThan( 0, $c['malformed'], 'no unrecognised line may pass as a continuation' );
		$this->assertSame( 3, $c['lines'] );
	}

	public function test_orphan_continuation_before_first_head_is_unparsed(): void {
		$c = PaymentErrors::classify_lines(
			array(
				'line with no entry head before it',
				'2026-11-14T20:00:00+00:00 ERROR Real entry',
			),
			self::NOW - 86400,
			self::NOW,
			'stripe'
		);
		$this->assertSame( array( 1, 1, 1 ), array( $c['count'], $c['heads'], $c['malformed'] ) );
	}

	/** @return array<string,array{0:string}> */
	public function unsupported_head_cases(): array {
		return array(
			'space-separated date'  => array( '2026-11-14 20:02:00 ERROR legacy format entry' ),
			'bracketed date'        => array( '[2026-11-14 20:02:00] ERROR other plugin format' ),
			'day-first date'        => array( '14-11-2026 20:02:00 ERROR other format' ),
			'lower-case level'      => array( '2026-11-14T20:02:00+00:00 error lower-case level' ),
			'unknown level'         => array( '2026-11-14T20:02:00+00:00 FATAL not a PSR level' ),
			'impossible date'       => array( '2026-13-14T99:99:99+00:00 ERROR Malformed timestamp entry' ),
		);
	}

	/**
	 * A date-led line after a valid entry is an unsupported entry head, not a continuation.
	 *
	 * @dataProvider unsupported_head_cases
	 */
	public function test_unsupported_entry_head_after_valid_entry_is_unparsed( string $line ): void {
		$c = PaymentErrors::classify_lines(
			array( '2026-11-14T20:00:00+00:00 INFO ok', $line, '    its continuation' ),
			self::NOW - 86400,
			self::NOW,
			'stripe'
		);
		$this->assertSame( 0, $c['count'] );
		$this->assertSame( 1, $c['malformed'], 'one unsupported head; its own continuation is not counted again' );
	}

	public function test_cut_start_tail_continuation_is_not_unparsed(): void {
		$tail = array(
			'    continuation of an entry cut off by the byte cap',
			'{"context":"still the cut entry"}',
			'2026-11-14T20:00:00+00:00 ERROR Real entry',
		);
		$cut = PaymentErrors::classify_lines( $tail, self::NOW - 86400, self::NOW, 'stripe', true );
		$this->assertSame( array( 1, 0, 1 ), array( $cut['count'], $cut['malformed'], $cut['heads'] ), 'tail read: leading lines belong to the cut entry' );
		$whole = PaymentErrors::classify_lines( $tail, self::NOW - 86400, self::NOW, 'stripe' );
		$this->assertSame( 2, $whole['malformed'], 'complete read keeps the orphan rule (both leading lines)' );
		$bad = PaymentErrors::classify_lines( array( 'tail line', '2026-11-14 20:02:00 ERROR unsupported head' ), self::NOW - 86400, self::NOW, 'stripe', true );
		$this->assertSame( 1, $bad['malformed'], 'an unsupported head in a tail is still unparsed' );
	}

	public function test_empty_stream_is_exact_zero(): void {
		foreach ( array( array(), array( '' ), array( '', '   ' ) ) as $lines ) {
			$c = PaymentErrors::classify_lines( $lines, self::NOW - 86400, self::NOW, 'stripe' );
			$this->assertSame( array( 0, 0, 0, 0 ), array( $c['count'], $c['malformed'], $c['heads'], $c['lines'] ) );
		}
	}

	public function test_db_message_bounds_fit_the_global_byte_budget(): void {
		$this->assertLessThanOrEqual( PaymentErrors::MAX_BYTES, PaymentErrors::MAX_DB_ROWS * PaymentErrors::MAX_DB_MSG, 'per-message byte cap times the row cap stays within 5,242,880 bytes before any PHP budget check' );
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

	// ---------------------------------------------------------------- Q8 interim: PayPal, WooPayments, Square.

	private static function contracts(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/payment-gateway-contracts.json' ), true );
	}

	/** Explicit (non-wildcard) kind entries of the four verified families in the fixture. */
	private static function fixture_kinds(): array {
		$out = array();
		foreach ( array( 'stripe', 'paypal_payments', 'woopayments', 'square' ) as $f ) {
			foreach ( self::contracts()[ $f ]['kind'] as $id => $kind ) {
				if ( in_array( $kind, array( 'instant', 'manual', 'unknown' ), true ) && ! str_contains( $id, '*' ) ) {
					$out[ $id ] = $kind;
				}
			}
		}
		return $out;
	}

	public function test_q8_instant_ids_are_exactly_the_fixture_instant_ids(): void {
		$want = array_keys( array_filter( self::fixture_kinds(), static fn( $k ) => 'instant' === $k ) );
		$got  = PaymentMethods::INSTANT_IDS;
		sort( $want );
		sort( $got );
		$this->assertSame( $want, $got, 'no guessed IDs: the instant map is the receipt-backed list' );
		$this->assertCount( 10, $got, 'stripe plus the 9 wave 2 IDs' );
		foreach ( self::fixture_kinds() as $id => $kind ) {
			$this->assertSame( $kind, PaymentMethods::kind( (string) $id ), "kind of {$id}" );
		}
		foreach ( array( 'woocommerce_payments_klarna', 'woocommerce_payments_sepa_debit', 'ppcp-recaptcha', 'square_gift_card' ) as $id ) {
			$this->assertSame( 'unknown', PaymentMethods::kind( $id ), "{$id} stays unknown" );
		}
	}

	public function test_q8_kind_filter_still_overrides(): void {
		$GLOBALS['blzmcp_test_filters']['blaze_mcp_toolkit_payment_method_kind'] = static fn( $kind, $id ) => 'ppcp-gateway' === $id ? 'bogus' : $kind;
		try {
			$this->assertSame( 'unknown', PaymentMethods::kind( 'ppcp-gateway' ), 'invalid filter output becomes unknown' );
			$this->assertSame( 'instant', PaymentMethods::kind( 'square_credit_card' ) );
		} finally {
			unset( $GLOBALS['blzmcp_test_filters'] );
		}
	}

	public function test_q8_token_map_matches_the_fixture(): void {
		$map = PaymentMethods::token_map();
		$c   = self::contracts();
		$this->assertSame( array( 'paypal', 'square', 'stripe', 'woopayments' ), array_keys( $map ) );
		$this->assertSame( array( $c['paypal_payments']['plugin'] ), $map['paypal']['plugin'] );
		$this->assertSame( array( $c['woopayments']['plugin'] ), $map['woopayments']['plugin'] );
		$this->assertSame( array( $c['square']['plugin'] ), $map['square']['plugin'] );
		$pp = array_merge( ...array_values( array_filter( $c['paypal_payments']['method_ids'], 'is_array' ) ) );
		$this->assertSame( $pp, $map['paypal']['method_ids'], 'every PayPal gateway ID from the fixture, nothing else' );
		$this->assertSame( array_values( array_filter( $c['square']['method_ids'], 'is_string' ) ), $map['square']['method_ids'] );
		$pay_hooks = array_values(
			array_map(
				static fn( $h ) => $h['hook'],
				array_filter( $c['woopayments']['action_scheduler']['hooks'], static fn( $h ) => true === $h['payment_related'] )
			)
		);
		$this->assertSame( $pay_hooks, $map['woopayments']['hooks'], 'only payment_related WooPayments hooks' );
		$this->assertSame( array(), $map['woopayments']['hook_groups'], 'the shared group also holds non-payment jobs' );
		foreach ( array( 'paypal', 'woopayments', 'square' ) as $f ) {
			$this->assertSame( array(), $map[ $f ]['log_sources'], "{$f}: no log sources (Q6)" );
		}
		foreach ( array( 'paypal', 'square' ) as $f ) {
			$this->assertSame( array(), array_merge( $map[ $f ]['hooks'], $map[ $f ]['hook_groups'] ), "{$f}: no payment-job mapping" );
		}
		$this->assertNull( $c['paypal_payments']['webhook_state'] );
		$this->assertNull( $c['woopayments']['webhook_state'] );
		$this->assertNull( $c['square']['webhook_state'] );
		foreach ( array( 'ppcp-gateway' => 'paypal', 'ppcp-ideal' => 'paypal', 'woocommerce_payments' => 'woopayments', 'woocommerce_payments_klarna' => 'woopayments', 'square_cash_app_pay' => 'square', 'gift_cards_pay' => 'square' ) as $id => $fam ) {
			$this->assertSame( $fam, PaymentMethods::method_family( $id, $map ), $id );
		}
	}

	private static function row( string $id, string $family ): array {
		return array(
			'id'               => $id,
			'family'           => $family,
			'kind'             => PaymentMethods::kind( $id ),
			'webhook_adapter'  => null,
			'webhook_required' => null,
		);
	}

	public function test_q8_family_diagnostics_applicability(): void {
		$GLOBALS['blzmcp_test_caps'] = array( 'blzmcp_view_diagnostics' );
		$map = PaymentMethods::token_map();
		$m   = array(
			'ppcp-gateway'         => self::row( 'ppcp-gateway', 'paypal' ),
			'woocommerce_payments' => self::row( 'woocommerce_payments', 'woopayments' ),
			'square_credit_card'   => self::row( 'square_credit_card', 'square' ),
		);
		$out = PaymentErrors::for_methods( $m, $map, array(), self::NOW, 24, new \DateTimeZone( 'UTC' ) );
		foreach ( $m as $id => $unused ) {
			$this->assertSame( array( false, 'no_verified_mapping' ), array( $out[ $id ]['logs']['applicable'], $out[ $id ]['logs']['reason'] ), "{$id}: logs not applicable" );
			$this->assertSame( array( false, 'no_supported_webhook_record' ), array( $out[ $id ]['webhook']['applicable'], $out[ $id ]['webhook']['reason'] ), "{$id}: webhook not applicable" );
		}
		$this->assertSame( array( false, 'no_verified_mapping' ), array( $out['ppcp-gateway']['jobs']['applicable'], $out['ppcp-gateway']['jobs']['reason'] ), 'PayPal jobs not applicable' );
		$this->assertSame( array( false, 'no_verified_mapping' ), array( $out['square_credit_card']['jobs']['applicable'], $out['square_credit_card']['jobs']['reason'] ), 'Square jobs not applicable' );
		$this->assertTrue( $out['woocommerce_payments']['jobs']['applicable'], 'WooPayments jobs are a mapped check' );
		$this->assertSame( 'unavailable', $out['woocommerce_payments']['jobs']['coverage'], 'Action Scheduler is not loaded in unit tests' );
	}

	public function test_q8_family_unpaid_verdicts(): void {
		$cur  = static fn( int $p, int $t, int $txn = 0 ) => array( 'pending' => $p, 'cancelled_unpaid' => 0, 'taken' => $t, 'transaction_recorded_unpaid' => $txn, 'failed' => 0 );
		$base = $cur( 2, 100 );

		$st = static fn( array $c, string $id ) => PaymentRules::stuck( $c, $base, PaymentMethods::kind( $id ), true, false, PaymentMethods::reference_implies_charge( $id ) );

		$pp = $st( $cur( 30, 70 ), 'ppcp-gateway' );
		$this->assertSame( array( 'red', 'unpaid_share_high' ), array( $pp['status'], $pp['reason'] ), 'instant PayPal gets the share verdict' );
		$ppr = $st( $cur( 1, 60, 1 ), 'ppcp-gateway' );
		$this->assertSame( array( 'amber', 'transaction_recorded_unpaid' ), array( $ppr['status'], $ppr['reason'] ), 'PayPal stores the ID only after capture/authorise: a reference is amber' );

		$wp = $st( $cur( 1, 60, 1 ), 'woocommerce_payments' );
		$this->assertSame( array( 'green', null, 1 ), array( $wp['status'], $wp['reason'], $wp['transaction_recorded_unpaid'] ), 'a pending 3DS WooPayments order with an intent ID raises no amber; the count stays as context' );
		$wp2 = $st( $cur( 30, 70, 2 ), 'woocommerce_payments' );
		$this->assertSame( array( 'red', 'unpaid_share_high' ), array( $wp2['status'], $wp2['reason'] ), 'WooPayments keeps the share verdict' );

		$sq = $st( $cur( 1, 60, 1 ), 'square_credit_card' );
		$this->assertSame( array( 'green', null ), array( $sq['status'], $sq['reason'] ), 'Square: reference not established as a charge, context only' );
		$this->assertTrue( PaymentMethods::reference_implies_charge( 'stripe' ) );
		$this->assertFalse( PaymentMethods::reference_implies_charge( 'ppcp-ideal' ), 'unlisted IDs are false' );
		$this->assertSame( PaymentMethods::INSTANT_IDS, array_keys( PaymentMethods::REFERENCE_IMPLIES_CHARGE ), 'one fact per instant ID' );

		$redirect = PaymentRules::stuck( $cur( 30, 70, 3 ), $base, PaymentMethods::kind( 'ppcp-ideal' ) );
		$this->assertSame( array( 'unknown', 'kind_unknown', 'unavailable' ), array( $redirect['status'], $redirect['reason'], $redirect['coverage'] ), 'PayPal redirect ID stays unknown kind' );
	}

	public function test_q8_quiet_paypal_red_and_healthy_woopayments_green(): void {
		$tz  = new \DateTimeZone( 'UTC' );
		$now = self::NOW;
		$ev  = static function ( int $from, int $to ): array {
			$o = array();
			for ( $t = $from + 1800; $t < $to; $t += 3600 ) {
				$o[] = $t;
			}
			return $o;
		};
		$start   = PaymentRules::history_start( $now );
		$store   = PaymentRules::series( $ev( $start, $now ) );
		$profile = PaymentRules::fit_profile( $store, $now, $tz );
		$na      = array( 'status' => 'unknown', 'checked' => false, 'applicable' => false, 'coverage' => 'not_applicable', 'reason' => 'no_verified_mapping' );
		$green   = array( 'status' => 'green', 'checked' => true, 'applicable' => true, 'coverage' => 'complete', 'reason' => null );

		// PayPal card: busy hourly, then silent for the last 24 hours.
		$pp_q  = PaymentRules::quiet( PaymentRules::series( $ev( $start, $now - 86400 ) ), $profile, $now, $tz )['check'];
		$pp_s  = PaymentRules::stuck( array( 'pending' => 0, 'cancelled_unpaid' => 0, 'taken' => 0, 'transaction_recorded_unpaid' => 0, 'failed' => 0 ), array( 'pending' => 2, 'cancelled_unpaid' => 0, 'taken' => 600, 'transaction_recorded_unpaid' => 0, 'failed' => 0 ), PaymentMethods::kind( 'ppcp-gateway' ) );
		$this->assertSame( 'red', $pp_q['status'] );
		$this->assertSame( 'red', PaymentRules::rollup( array( $pp_q, $pp_s, $na, $na, $na ) ), 'quiet instant PayPal method is red' );

		// WooPayments card: steady trade, low unpaid share, jobs checked and clean.
		$wp_q = PaymentRules::quiet( $store, $profile, $now, $tz )['check'];
		$wp_s = PaymentRules::stuck( array( 'pending' => 1, 'cancelled_unpaid' => 0, 'taken' => 22, 'transaction_recorded_unpaid' => 0, 'failed' => 0 ), array( 'pending' => 10, 'cancelled_unpaid' => 0, 'taken' => 600, 'transaction_recorded_unpaid' => 0, 'failed' => 0 ), PaymentMethods::kind( 'woocommerce_payments' ) );
		$this->assertSame( array( 'green', 'green' ), array( $wp_q['status'], $wp_s['status'] ) );
		$this->assertSame( 'green', PaymentRules::rollup( array( $wp_q, $wp_s, $na, $green, $na ) ), 'healthy WooPayments: green, jobs applicable and green, logs and webhook not applicable' );
	}

	public function test_kind_unverified_is_retired(): void {
		$this->assertNotContains( 'kind_unverified', PaymentRules::REASONS );
	}
}
