<?php
/**
 * Checkout-path protection rows (FEATURE-PLAN item 5, contract C7): pinned source contract,
 * literal page markers, read bounds, version range and the fixed C8 Path rows.
 *
 * The rows are built from the same pure functions snapshot() feeds in production; only the
 * passive WordPress reads are left to tests/Integration/test-checkout-protection.php.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Diagnostics\CheckoutProtection;
use PHPUnit\Framework\TestCase;

final class CheckoutProtectionTest extends TestCase {

	private const PATH_KEYS       = array( 'id', 'label', 'exposure', 'checkout_type', 'protection_state', 'enforcement', 'protections', 'facts', 'limitations', 'check_next' );
	private const PROTECTION_KEYS = array( 'id', 'configured', 'scope_known', 'note' );
	private const LIMITATION_KEYS = array( 'scope', 'code', 'what', 'check_next' );

	/** Translation passthrough for the fixed copy (WordPress is not loaded in unit tests). */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $text, $domain = "default" ) { return $text; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- global test stub, defined once.
		}
	}

	/**
	 * The pinned source contract.
	 *
	 * @return array<string,mixed>
	 */
	private static function contract(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/checkout-protection-contracts.json' ), true );
	}

	/**
	 * A label for a raw value.
	 *
	 * @param mixed $v Value.
	 */
	private static function show( $v ): string {
		return (string) wp_json_encode( $v );
	}

	/**
	 * A checkout page row as the reader would classify it.
	 *
	 * @param string $content Page content.
	 * @param string $status  Post status.
	 * @return array<string,mixed>
	 */
	private static function page( string $content, string $status = 'publish' ): array {
		return CheckoutProtection::page_from_row(
			array( 'setting' => 'set', 'id' => 7 ),
			array( 'ID' => '7', 'post_status' => $status, 'content_bytes' => (string) strlen( $content ), 'content' => 'publish' === $status ? $content : null ),
			false,
			true
		);
	}

	/** A published My Account page. */
	private static function myaccount(): array {
		return CheckoutProtection::page_from_row( array( 'setting' => 'set', 'id' => 8 ), array( 'ID' => '8', 'post_status' => 'publish' ), false, false );
	}

	/**
	 * A complete snapshot, as snapshot() returns it, with overrides.
	 *
	 * @param array<string,mixed> $over    Top-level overrides.
	 * @param array<string,bool>  $filters Filter-presence overrides.
	 * @return array<string,mixed>
	 */
	private static function snap( array $over = array(), array $filters = array() ): array {
		$base = array(
			'permitted'   => true,
			'wc_version'  => '11.3.1',
			'checkout'    => self::page( "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->" ),
			'myaccount'   => self::myaccount(),
			'rate_limit'  => 'no',
			'attribution' => 'yes',
			'filters'     => array_merge( array_fill_keys( array_keys( CheckoutProtection::WATCHED_FILTERS ), false ), $filters ),
		);
		return array_merge( $base, $over );
	}

	/**
	 * Rows keyed by path ID.
	 *
	 * @param array<string,mixed> $snap Snapshot.
	 * @return array<string,array<string,mixed>>
	 */
	private static function rows( array $snap ): array {
		$out = array();
		foreach ( CheckoutProtection::paths( $snap ) as $row ) {
			$out[ $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Limitations keyed by scope.
	 *
	 * @param array<string,mixed> $snap Snapshot.
	 * @return array<string,array<string,mixed>>
	 */
	private static function lims( array $snap ): array {
		$out = array();
		foreach ( CheckoutProtection::limitations( $snap ) as $l ) {
			$out[ $l['scope'] ] = $l;
		}
		return $out;
	}

	/**
	 * Page variants that must never change the other rows.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function page_variants(): array {
		$set = array( 'setting' => 'set', 'id' => 7 );
		return array(
			'classic'    => self::page( '[woocommerce_checkout]' ),
			'block'      => self::page( "<!-- wp:woocommerce/checkout -->\n<div></div>\n<!-- /wp:woocommerce/checkout -->" ),
			'mixed'      => self::page( "<!-- wp:woocommerce/checkout -->\n<div></div>\n<!-- /wp:woocommerce/checkout -->[woocommerce_checkout]" ),
			'builder'    => self::page( '[builder_section id="1"][/builder_section]' ),
			'draft'      => self::page( '[woocommerce_checkout]', 'draft' ),
			'private'    => self::page( '[woocommerce_checkout]', 'private' ),
			'not_set'    => CheckoutProtection::page_from_row( array( 'setting' => 'absent', 'id' => null ), null, false, true ),
			'empty'      => CheckoutProtection::page_from_row( array( 'setting' => 'empty', 'id' => null ), null, false, true ),
			'invalid'    => CheckoutProtection::page_from_row( array( 'setting' => 'invalid', 'id' => null ), null, false, true ),
			'missing'    => CheckoutProtection::page_from_row( $set, null, false, true ),
			'unreadable' => CheckoutProtection::page_from_row( $set, null, true, true ),
			'oversize'   => CheckoutProtection::page_from_row( $set, array( 'ID' => '7', 'post_status' => 'publish', 'content_bytes' => (string) ( CheckoutProtection::MAX_PAGE_BYTES + 1 ), 'content' => null ), false, true ),
		);
	}

	/**
	 * Every string a row or limitation can show.
	 *
	 * @param array<string,mixed> $snap Snapshot.
	 * @return list<string>
	 */
	private static function strings( array $snap ): array {
		$out = array();
		foreach ( CheckoutProtection::paths( $snap ) as $row ) {
			$out[] = $row['label'];
			$out[] = $row['check_next'];
			foreach ( array_merge( $row['facts'], $row['limitations'] ) as $s ) {
				$out[] = $s;
			}
			foreach ( $row['protections'] as $p ) {
				$out[] = $p['note'];
			}
		}
		foreach ( CheckoutProtection::limitations( $snap ) as $l ) {
			$out[] = $l['what'];
			$out[] = (string) $l['check_next'];
		}
		return $out;
	}

	/**
	 * A wide product of snapshots: every option value, version class, filter case and page.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function matrix(): array {
		$out = array();
		foreach ( array( 'yes', 'no', 'absent', 'unrecognised' ) as $rl ) {
			foreach ( array( '10.9.0', '11.0.0', '11.3.1', '11.4.0', null ) as $v ) {
				foreach ( array( array(), array( 'rate_limit_options' => true ), array( 'checkout_shortcode_tag' => true, 'rate_limit_id' => true, 'saved_method_delay' => true, 'checkout_page_id' => true ) ) as $filters ) {
					foreach ( self::page_variants() as $page ) {
						$out[] = self::snap( array( 'rate_limit' => $rl, 'wc_version' => $v, 'checkout' => $page ), $filters );
					}
				}
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------- pinned source contract.

	public function test_constants_match_the_pinned_source_contract(): void {
		$c = self::contract();
		$this->assertSame( $c['rate_limit_checkout']['option'], CheckoutProtection::RATE_LIMIT_OPTION );
		$this->assertSame( $c['rate_limit_checkout']['default'], CheckoutProtection::RATE_LIMIT_DEFAULT );
		$this->assertSame( $c['rate_limit_checkout']['opt_in_limit'], CheckoutProtection::SOURCE_RATE_LIMIT );
		$this->assertSame( $c['rate_limit_checkout']['opt_in_seconds'], CheckoutProtection::SOURCE_RATE_SECONDS );
		$this->assertSame( $c['add_payment_method']['delay_default_seconds'], CheckoutProtection::SOURCE_SAVED_METHOD_WAIT );
		$this->assertSame( $c['checkout_page']['option'], CheckoutProtection::CHECKOUT_PAGE_OPTION );
		$this->assertSame( $c['add_payment_method']['myaccount_option'], CheckoutProtection::MYACCOUNT_PAGE_OPTION );
		$this->assertSame( $c['order_attribution']['option'], CheckoutProtection::ATTRIBUTION_OPTION );
		$this->assertSame( $c['order_attribution']['default'], CheckoutProtection::ATTRIBUTION_DEFAULT );
		$this->assertSame( $c['checkout_page']['max_bytes_read'], CheckoutProtection::MAX_PAGE_BYTES );
		$this->assertSame( $c['checkout_page']['block_marker']['name'], CheckoutProtection::CHECKOUT_BLOCK );
		$this->assertSame( $c['checkout_page']['classic_markers']['shortcode'], CheckoutProtection::CLASSIC_SHORTCODE );
		$this->assertSame( array( $c['verified_range']['min'], $c['verified_range']['max'] ), array( CheckoutProtection::WC_MIN_VERIFIED, CheckoutProtection::WC_MAX_VERIFIED ) );
		$this->assertSame( $c['watched_filters'], CheckoutProtection::WATCHED_FILTERS );
		$this->assertSame( $c['path_ids'], CheckoutProtection::PATH_IDS );
		$this->assertSame( array_keys( $c['reason_codes'] ), CheckoutProtection::REASON_CODES );
		// The two verified ends differ on letter case; only they are mapped.
		$this->assertSame( array( '10.9.0' => false, '11.3.1' => true ), CheckoutProtection::ROUTE_CASE_INSENSITIVE );
		$this->assertStringEndsWith( '#', $c['rate_limit_checkout']['route_pattern']['10.9.0'] );
		$this->assertStringEndsWith( '#i', $c['rate_limit_checkout']['route_pattern']['11.3.1'] );
		foreach ( array( 'rate_limit_options' => 'options', 'rate_limit_id' => 'identity' ) as $k => $ck ) {
			$this->assertSame( $c['rate_limit_checkout']['filters'][ $ck ], CheckoutProtection::WATCHED_FILTERS[ $k ] );
		}
		$this->assertSame( $c['add_payment_method']['delay_filter'], CheckoutProtection::WATCHED_FILTERS['saved_method_delay'] );
	}

	public function test_every_reason_code_has_the_pinned_plain_words(): void {
		foreach ( self::contract()['reason_codes'] as $code => $words ) {
			$this->assertSame( $words, CheckoutProtection::reason( $code ), $code );
		}
	}

	public function test_every_cited_fact_names_a_verified_version_and_line(): void {
		$c     = self::contract();
		$cites = array();
		array_walk_recursive(
			$c,
			static function ( $v ) use ( &$cites ) {
				if ( is_string( $v ) && 1 === preg_match( '/^(10\.9\.0|11\.3\.1|wp-7\.1\.5|wp-7\.2-RC3):[A-Za-z0-9_\/.\-]+:[0-9]+$/', $v ) ) {
					$cites[] = $v;
				}
			}
		);
		$this->assertGreaterThan( 80, count( $cites ), 'every pinned fact is cited as version:path:line' );
		$both = array( 'src/StoreApi/Authentication.php', 'src/Internal/Features/FeaturesController.php', 'includes/class-wc-form-handler.php', 'src/StoreApi/Routes/V1/CheckoutOrder.php', 'src/StoreApi/Routes/V1/Checkout.php' );
		foreach ( $both as $file ) {
			foreach ( array( '10.9.0', '11.3.1' ) as $v ) {
				$hit = array_filter( $cites, static fn( string $s ): bool => str_starts_with( $s, $v . ':' . $file . ':' ) );
				$this->assertNotEmpty( $hit, "$file cited at $v" );
			}
		}
	}

	// ---------------------------------------------------------------- literal page markers.

	public function test_marker_vectors_from_the_contract(): void {
		foreach ( self::contract()['synthetic_cases']['markers'] as $case ) {
			$this->assertSame( $case['expect'], CheckoutProtection::detect_marker( $case['content'] ), $case['id'] );
		}
	}

	public function test_marker_edge_cases(): void {
		$this->assertSame( 'classic', CheckoutProtection::detect_marker( "[woocommerce_checkout\n]" ), 'whitespace after the tag' );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( '<!-- unclosed [woocommerce_checkout]' ), 'an unclosed comment runs to the end' );
		$this->assertSame( 'classic', CheckoutProtection::detect_marker( '<!-- note --> [woocommerce_checkout]' ), 'text after a closed comment still counts' );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( '<!--wp:woocommerce/checkout -->' ), 'a delimiter needs whitespace after <!--' );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( 'wp:woocommerce/checkout ' ), 'no comment opener' );
		$this->assertSame( 'block', CheckoutProtection::detect_marker( "<!--\twp:woocommerce/checkout\n-->" ), 'tab and newline delimiter whitespace' );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( '<!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout"' ), 'unterminated attributes' );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( '<!-- wp:woocommerce/classic-shortcode {"shortcode":["checkout"]} /-->' ), 'non-string attribute' );
		$this->assertSame( 'classic', CheckoutProtection::detect_marker( '<!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout","align":"wide"} -->x<!-- /wp:woocommerce/classic-shortcode -->' ) );
		$this->assertSame( 'mixed', CheckoutProtection::detect_marker( '<!-- wp:woocommerce/checkout /--><!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout"} /-->' ) );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( '<!-- wp:woocommerce/checkout-order-summary-block /-->[woocommerce_checkout_x]' ) );
		$this->assertSame( 'none', CheckoutProtection::detect_marker( '<!-- wp:woocommerce/classic-shortcode {"shortcode":"[woocommerce_checkout]"} /-->' ), 'a shortcode inside block attributes never runs' );
	}

	public function test_marker_scan_is_linear_on_adversarial_content(): void {
		$max   = CheckoutProtection::MAX_PAGE_BYTES;
		$cases = array(
			'openers'      => substr( str_repeat( '<!--', intdiv( $max, 4 ) + 1 ), 0, $max ),
			'block_prefix' => substr( str_repeat( '<!-- wp:woocommerce/classic-shortcode {', intdiv( $max, 40 ) + 1 ), 0, $max ),
			'shortcodes'   => substr( str_repeat( '[[woocommerce_checkout', intdiv( $max, 22 ) + 1 ), 0, $max ),
			'whitespace'   => str_repeat( ' ', $max - 30 ) . 'wp:woocommerce/checkout x',
		);
		$start = microtime( true );
		foreach ( $cases as $id => $content ) {
			$this->assertSame( 'none', CheckoutProtection::detect_marker( $content ), $id );
		}
		$this->assertLessThan( 5.0, microtime( true ) - $start, 'four full-size adversarial pages scan in well under the request budget' );
	}

	// ---------------------------------------------------------------- bounded page read.

	public function test_page_read_bound_one_byte_either_side(): void {
		$max     = CheckoutProtection::MAX_PAGE_BYTES;
		$content = str_repeat( 'x', $max - 22 ) . '[woocommerce_checkout]';
		$this->assertSame( $max, strlen( $content ) );
		$at = self::page( $content );
		$this->assertSame( array( 'published', $max, 'classic', hash( 'sha256', $content ) ), array( $at['state'], $at['bytes'], $at['marker'], $at['sha256'] ), 'exactly the limit is read, marker at the last byte' );

		$over = CheckoutProtection::page_from_row( array( 'setting' => 'set', 'id' => 7 ), array( 'ID' => '7', 'post_status' => 'publish', 'content_bytes' => (string) ( $max + 1 ), 'content' => null ), false, true );
		$this->assertSame( array( 'oversize', $max + 1, null, null ), array( $over['state'], $over['bytes'], $over['marker'], $over['sha256'] ), 'one byte over is not read' );

		$row = self::rows( self::snap( array( 'checkout' => $over ) ) )['checkout_page'];
		$this->assertSame( array( 'unknown', 'configured' ), array( $row['checkout_type'], $row['exposure'] ), 'an oversize page is still a published page of unknown type' );
	}

	public function test_page_states(): void {
		$set = array( 'setting' => 'set', 'id' => 7 );
		$p   = static fn( ?array $row, bool $failed = false ): array => CheckoutProtection::page_from_row( $set, $row, $failed, true );
		$this->assertSame( 'missing', $p( null )['state'] );
		$this->assertSame( 'missing', $p( array( 'ID' => '8', 'post_status' => 'publish', 'content_bytes' => '0', 'content' => '' ) )['state'], 'a different row is not this page' );
		$this->assertSame( 'unreadable', $p( null, true )['state'] );
		$this->assertSame( 'unreadable', $p( array( 'ID' => '7', 'post_status' => 'publish', 'content_bytes' => '5', 'content' => 'abc' ) )['state'], 'length mismatch' );
		$this->assertSame( 'unreadable', $p( array( 'ID' => '7', 'post_status' => 'publish', 'content_bytes' => '3', 'content' => null ) )['state'], 'content withheld within the bound' );
		$this->assertSame( 'unreadable', $p( array( 'ID' => '7', 'post_status' => 'publish', 'content_bytes' => null, 'content' => '' ) )['state'] );
		$this->assertSame( array( 'unpublished', 'other' ), array( $p( array( 'ID' => '7', 'post_status' => 'wc-custom' ) )['state'], $p( array( 'ID' => '7', 'post_status' => 'wc-custom' ) )['status'] ) );
		foreach ( array( 'draft', 'pending', 'private', 'future', 'trash' ) as $status ) {
			$row = $p( array( 'ID' => '7', 'post_status' => $status ) );
			$this->assertSame( array( 'unpublished', $status, null, null ), array( $row['state'], $row['status'], $row['marker'], $row['bytes'] ), $status );
		}
		$this->assertSame( 'published', self::myaccount()['state'] );
		$this->assertNull( self::myaccount()['sha256'], 'My Account content is never read' );
		$this->assertSame( 'not_set', CheckoutProtection::page_from_row( array( 'setting' => 'empty', 'id' => null ), null, false, true )['state'] );
		$this->assertSame( 'invalid_setting', CheckoutProtection::page_from_row( array( 'setting' => 'invalid', 'id' => null ), null, false, true )['state'] );
	}

	public function test_parse_page_setting(): void {
		$absent = "\0absent";
		$cases  = array(
			array( $absent, 'absent', null ),
			array( '', 'empty', null ),
			array( '0', 'empty', null ),
			array( 0, 'empty', null ),
			array( false, 'empty', null ),
			array( null, 'empty', null ),
			array( '7', 'set', 7 ),
			array( 7, 'set', 7 ),
			array( '1', 'set', 1 ),
			array( '007', 'set', 7 ),
			array( '-1', 'invalid', null ),
			array( -1, 'invalid', null ),
			array( '7abc', 'invalid', null ),
			array( ' 7', 'invalid', null ),
			array( 7.0, 'invalid', null ),
			array( true, 'invalid', null ),
			array( array( 7 ), 'invalid', null ),
			array( '1234567890123456789', 'invalid', null ),
			array( '123456789012345678', 'set', 123456789012345678 ),
		);
		foreach ( $cases as $i => $case ) {
			$this->assertSame( array( 'setting' => $case[1], 'id' => $case[2] ), CheckoutProtection::parse_page_setting( $case[0], $absent ), "case $i" );
		}
	}

	public function test_parse_yes_no_accepts_only_the_exact_strings(): void {
		$absent = "\0absent";
		foreach ( array( 'yes' => 'yes', 'no' => 'no' ) as $in => $out ) {
			$this->assertSame( $out, CheckoutProtection::parse_yes_no( $in, $absent ) );
		}
		$this->assertSame( 'absent', CheckoutProtection::parse_yes_no( $absent, $absent ) );
		foreach ( array( '', 'YES', 'Yes', ' yes', '1', 1, true, false, null, array( 'yes' ), 'on' ) as $raw ) {
			$this->assertSame( 'unrecognised', CheckoutProtection::parse_yes_no( $raw, $absent ), self::show( $raw ) );
		}
	}

	// ---------------------------------------------------------------- verified version range.

	public function test_version_range_one_release_either_side(): void {
		$cases = array(
			'10.8.9'        => array( false, null ),
			'10.9.0'        => array( true, false ),
			'10.9.1'        => array( true, null ),
			'11.0.0'        => array( true, null ),
			'11.3.0'        => array( true, null ),
			'11.3.1'        => array( true, true ),
			'11.3.2'        => array( false, null ),
			'11.4.0'        => array( false, null ),
			'9.9.9'         => array( false, null ),
			'11.3.1-beta.1' => array( false, null ),
			'10.9.0-rc.1'   => array( false, null ),
		);
		foreach ( $cases as $v => $want ) {
			$got = CheckoutProtection::version_support( CheckoutProtection::parse_version( (string) $v ) );
			$this->assertSame( array( 'verified' => $want[0], 'case_insensitive' => $want[1] ), $got, (string) $v );
		}
		$this->assertSame( array( 'verified' => false, 'case_insensitive' => null ), CheckoutProtection::version_support( null ) );
		foreach ( array( '11.3', 'abc', '', '11.3.1 ', 11.3, null, '11.3.1-' . str_repeat( 'x', 40 ) ) as $bad ) {
			$this->assertNull( CheckoutProtection::parse_version( $bad ), self::show( $bad ) );
		}
	}

	// ---------------------------------------------------------------- C8 Path rows.

	public function test_rows_are_the_five_fixed_paths_with_strict_shapes(): void {
		$enums = self::contract()['enums'];
		foreach ( self::matrix() as $snap ) {
			$rows = CheckoutProtection::paths( $snap );
			$this->assertSame( CheckoutProtection::PATH_IDS, array_column( $rows, 'id' ) );
			foreach ( $rows as $row ) {
				$this->assertSame( self::PATH_KEYS, array_keys( $row ) );
				$this->assertContains( $row['exposure'], $enums['exposure'] );
				$this->assertContains( $row['checkout_type'], $enums['checkout_type'] );
				$this->assertContains( $row['protection_state'], $enums['protection_state'] );
				$this->assertSame( 'unverified', $row['enforcement'], 'this passive version never verifies enforcement' );
				$this->assertSame( 'checkout_page' === $row['id'], null !== $row['checkout_type'] );
				$this->assertIsString( $row['label'] );
				$this->assertNotSame( '', $row['check_next'] );
				foreach ( array_merge( $row['facts'], $row['limitations'] ) as $s ) {
					$this->assertIsString( $s );
					$this->assertNotSame( '', $s );
				}
				$this->assertTrue( array_is_list( $row['facts'] ) && array_is_list( $row['limitations'] ) && array_is_list( $row['protections'] ) );
				foreach ( $row['protections'] as $p ) {
					$this->assertSame( self::PROTECTION_KEYS, array_keys( $p ) );
					$this->assertContains( $p['id'], $enums['protection_id'] );
					$this->assertContains( $p['configured'], array( true, false, null ) );
					$this->assertIsBool( $p['scope_known'] );
				}
				$this->assertArrayNotHasKey( 'protected', $row, 'no protected/unprotected boolean' );
			}
		}
	}

	public function test_classic_page_does_not_hide_the_store_api_or_order_payment_rows(): void {
		$rows = self::rows( self::snap( array( 'rate_limit' => 'yes' ) ) );
		$page = $rows['checkout_page'];
		$this->assertSame( array( 'configured', 'classic', 'unknown', array() ), array( $page['exposure'], $page['checkout_type'], $page['protection_state'], $page['protections'] ) );
		$this->assertContains( 'Rate limit Checkout does not cover the classic checkout form.', $page['facts'] );
		foreach ( array( 'store_api_checkout', 'store_api_order_pay' ) as $id ) {
			$this->assertSame( array( 'available_in_core', 'configured' ), array( $rows[ $id ]['exposure'], $rows[ $id ]['protection_state'] ), $id );
			$this->assertSame( array( array( 'id' => 'core_checkout_rate_limit', 'configured' => true, 'scope_known' => true ) ), array_map( static fn( $p ) => array_diff_key( $p, array( 'note' => 1 ) ), $rows[ $id ]['protections'] ) );
		}
		$this->assertSame( array( 'available_in_core', 'unknown' ), array( $rows['order_pay']['exposure'], $rows['order_pay']['protection_state'] ) );
		$this->assertSame( array( 'available_in_core', 'unknown' ), array( $rows['add_payment_method']['exposure'], $rows['add_payment_method']['protection_state'] ) );
	}

	public function test_no_page_state_changes_any_other_row(): void {
		foreach ( array( 'yes', 'no', 'absent' ) as $rl ) {
			$reference = null;
			foreach ( self::page_variants() as $name => $page ) {
				$rows = self::rows( self::snap( array( 'rate_limit' => $rl, 'checkout' => $page ) ) );
				unset( $rows['checkout_page'] );
				$json      = self::show( $rows );
				$reference = $reference ?? $json;
				$this->assertSame( $reference, $json, "page $name with setting $rl leaves the other 4 rows byte-identical" );
			}
		}
	}

	public function test_page_types(): void {
		$want = array(
			'classic'    => array( 'classic', 'configured', 'no_readable_protection' ),
			'block'      => array( 'block', 'configured', 'rate_limit_checkout_off' ),
			'mixed'      => array( 'mixed', 'configured', 'checkout_page_mixed' ),
			'builder'    => array( 'unknown', 'configured', 'checkout_page_no_marker' ),
			'draft'      => array( 'unknown', 'unknown', 'checkout_page_unpublished' ),
			'private'    => array( 'unknown', 'unknown', 'checkout_page_unpublished' ),
			'not_set'    => array( 'unknown', 'unknown', 'checkout_page_not_set' ),
			'empty'      => array( 'unknown', 'unknown', 'checkout_page_not_set' ),
			'invalid'    => array( 'unknown', 'unknown', 'checkout_page_invalid_setting' ),
			'missing'    => array( 'unknown', 'unknown', 'checkout_page_missing' ),
			'unreadable' => array( 'unknown', 'unknown', 'checkout_page_unreadable' ),
			'oversize'   => array( 'unknown', 'configured', 'checkout_page_oversize' ),
		);
		foreach ( self::page_variants() as $name => $page ) {
			$snap = self::snap( array( 'checkout' => $page ) );
			$row  = self::rows( $snap )['checkout_page'];
			$lim  = self::lims( $snap )['checkout_page'] ?? array();
			$this->assertSame( $want[ $name ], array( $row['checkout_type'], $row['exposure'], $lim['code'] ?? null ), $name );
			$this->assertContains( 'Orders can also be placed in the other ways listed here, whatever this page shows.', $row['limitations'], $name );
			if ( 'block' !== $name ) {
				$this->assertSame( array( 'unknown', array() ), array( $row['protection_state'], $row['protections'] ), "$name has no readable protection" );
			}
		}
	}

	public function test_block_page_follows_the_store_api_setting(): void {
		$block = self::page_variants()['block'];
		$cases = array(
			'yes'    => array( 'configured', true, 'Rate limit Checkout is on.' ),
			'no'     => array( 'not_configured', false, 'Rate limit Checkout is off.' ),
			'absent' => array( 'not_configured', false, 'Rate limit Checkout has never been changed, so it is off, which is WooCommerce\'s default.' ),
		);
		foreach ( $cases as $rl => $want ) {
			$rows = self::rows( self::snap( array( 'rate_limit' => $rl, 'checkout' => $block ) ) );
			$page = $rows['checkout_page'];
			$this->assertSame( array( $want[0], $want[1] ), array( $page['protection_state'], $page['protections'][0]['configured'] ), $rl );
			$this->assertSame( $rows['store_api_checkout']['protection_state'], $page['protection_state'], 'the block page places orders through the Store API checkout' );
			$this->assertContains( $want[2], $page['facts'] );
			$this->assertSame( $rows['store_api_checkout']['check_next'], $page['check_next'] );
		}
		$this->assertStringContainsString( 'Turn on WooCommerce > Settings > Advanced > Features > Rate limit Checkout.', self::rows( self::snap( array( 'checkout' => $block ) ) )['checkout_page']['check_next'] );
	}

	public function test_off_means_only_the_built_in_setting_is_off(): void {
		$snap = self::snap( array( 'rate_limit' => 'no' ) );
		$rows = self::rows( $snap );
		$lims = self::lims( $snap );
		foreach ( array( 'store_api_checkout', 'store_api_order_pay' ) as $id ) {
			$this->assertSame( 'not_configured', $rows[ $id ]['protection_state'] );
			$this->assertFalse( $rows[ $id ]['protections'][0]['configured'] );
			$this->assertSame( 'rate_limit_checkout_off', $lims[ $id ]['code'] );
			$this->assertStringContainsString( 'This does not mean nothing else protects this path.', $lims[ $id ]['what'] );
		}
		$this->assertStringContainsString( 'it cannot see protection added by other plugins, the server, a CDN or the payment provider', $lims['protection']['what'] );
	}

	public function test_on_gives_configured_scope_and_never_verified_enforcement(): void {
		$snap = self::snap( array( 'rate_limit' => 'yes' ) );
		$rows = self::rows( $snap );
		$this->assertSame( 'protection_enforcement_unverified', CheckoutProtection::limitations( $snap )[0]['code'] );
		foreach ( array( 'store_api_checkout', 'store_api_order_pay' ) as $id ) {
			$this->assertSame( array( 'configured', 'unverified' ), array( $rows[ $id ]['protection_state'], $rows[ $id ]['enforcement'] ) );
			$this->assertStringContainsString( 'cannot confirm it works', $rows[ $id ]['check_next'] );
			$this->assertContains( 'When it is on, WooCommerce\'s default allows 3 order requests per 60 seconds from each signed-in customer or visitor address.', $rows[ $id ]['facts'] );
			$this->assertContains( 'Signed-in staff who can edit posts are not limited by it.', $rows[ $id ]['facts'] );
			$this->assertContains( 'In the WooCommerce versions this check verified, checkout requests sent inside a combined Store API batch request fall outside Rate limit Checkout.', $rows[ $id ]['limitations'] );
		}
		$this->assertArrayNotHasKey( 'store_api_checkout', self::lims( $snap ), 'a configured path with a known scope needs no gap entry' );
	}

	public function test_only_rate_limited_rows_can_ever_be_configured(): void {
		foreach ( self::matrix() as $snap ) {
			$rows = self::rows( $snap );
			foreach ( array( 'order_pay', 'add_payment_method' ) as $id ) {
				$this->assertSame( 'unknown', $rows[ $id ]['protection_state'], $id );
			}
			if ( 'block' !== $rows['checkout_page']['checkout_type'] ) {
				$this->assertSame( 'unknown', $rows['checkout_page']['protection_state'] );
			}
			foreach ( $rows as $id => $row ) {
				if ( 'configured' === $row['protection_state'] ) {
					$this->assertSame( 'yes', $snap['rate_limit'], "$id configured only from a saved on value" );
					$this->assertTrue( CheckoutProtection::version_support( $snap['wc_version'] )['verified'], "$id configured only inside the verified range" );
				}
				if ( 'not_configured' === $row['protection_state'] ) {
					$this->assertContains( $snap['rate_limit'], array( 'no', 'absent' ) );
					$this->assertFalse( $snap['filters']['rate_limit_options'], 'a rate-limit filter keeps an off setting unknown' );
				}
			}
		}
	}

	public function test_unrecognised_value_and_rate_limit_filter_stay_unknown(): void {
		$rows = self::rows( self::snap( array( 'rate_limit' => 'unrecognised' ) ) );
		$this->assertSame( array( 'unknown', null ), array( $rows['store_api_checkout']['protection_state'], $rows['store_api_checkout']['protections'][0]['configured'] ) );
		$this->assertSame( 'rate_limit_setting_unrecognised', self::lims( self::snap( array( 'rate_limit' => 'unrecognised' ) ) )['store_api_checkout']['code'] );

		$off  = self::snap( array( 'rate_limit' => 'no' ), array( 'rate_limit_options' => true ) );
		$roff = self::rows( $off )['store_api_checkout'];
		$this->assertSame( array( 'unknown', false, false ), array( $roff['protection_state'], $roff['protections'][0]['configured'], $roff['protections'][0]['scope_known'] ) );
		$this->assertSame( 'rate_limit_filter_present', self::lims( $off )['store_api_checkout']['code'] );

		$on  = self::snap( array( 'rate_limit' => 'yes' ), array( 'rate_limit_options' => true ) );
		$ron = self::rows( $on )['store_api_checkout'];
		$this->assertSame( array( 'configured', true, false ), array( $ron['protection_state'], $ron['protections'][0]['configured'], $ron['protections'][0]['scope_known'] ) );
		$this->assertSame( 'rate_limit_filter_present', self::lims( $on )['store_api_checkout']['code'], 'configured with an unknown scope is still a disclosed gap' );
		$this->assertNotContains( 'When it is on, WooCommerce\'s default allows 3 order requests per 60 seconds from each signed-in customer or visitor address.', $ron['facts'] );
	}

	public function test_outside_the_verified_range_nothing_is_assumed(): void {
		foreach ( array( '11.4.0', '11.3.2', '10.8.9', null ) as $v ) {
			foreach ( array( 'yes', 'no', 'absent' ) as $rl ) {
				$snap = self::snap( array( 'wc_version' => $v, 'rate_limit' => $rl ) );
				$rows = self::rows( $snap );
				$lims = self::lims( $snap );
				foreach ( array( 'store_api_checkout', 'store_api_order_pay', 'order_pay', 'add_payment_method' ) as $id ) {
					$this->assertSame( array( 'unknown', 'unknown' ), array( $rows[ $id ]['exposure'], $rows[ $id ]['protection_state'] ), "$id at " . self::show( $v ) );
					$this->assertSame( 'wc_version_unverified', $lims[ $id ]['code'] );
					foreach ( $rows[ $id ]['protections'] as $p ) {
						$this->assertSame( array( null, false ), array( $p['configured'], $p['scope_known'] ), 'no guessed on or off value' );
					}
				}
				$this->assertSame( array( 'unknown', 'wc_version_unverified' ), array( $rows['checkout_page']['checkout_type'], $lims['checkout_page']['code'] ) );
				$all = implode( ' ', self::strings( $snap ) );
				$this->assertStringNotContainsString( 'never been changed', $all, 'an absent option is not read as the source default' );
				$this->assertStringNotContainsString( '20 seconds', $all );
				$this->assertStringNotContainsString( '3 order requests', $all );
			}
		}
	}

	public function test_letter_case_scope_is_claimed_only_at_the_exact_versions_read(): void {
		$case_1090 = 'In this WooCommerce version, a request address written in different letter case can fall outside Rate limit Checkout.';
		$unchecked = 'Which request addresses Rate limit Checkout matches was not checked for this WooCommerce version.';
		$r1090     = self::rows( self::snap( array( 'wc_version' => '10.9.0', 'rate_limit' => 'yes' ) ) )['store_api_checkout'];
		$this->assertContains( $case_1090, $r1090['limitations'] );
		$this->assertTrue( $r1090['protections'][0]['scope_known'] );

		$r1131 = self::rows( self::snap( array( 'wc_version' => '11.3.1', 'rate_limit' => 'yes' ) ) )['store_api_checkout'];
		$this->assertNotContains( $case_1090, $r1131['limitations'] );
		$this->assertNotContains( $unchecked, $r1131['limitations'] );

		$mid  = self::snap( array( 'wc_version' => '11.0.0', 'rate_limit' => 'yes' ) );
		$rmid = self::rows( $mid )['store_api_checkout'];
		$this->assertSame( array( 'configured', true, false ), array( $rmid['protection_state'], $rmid['protections'][0]['configured'], $rmid['protections'][0]['scope_known'] ) );
		$this->assertContains( $unchecked, $rmid['limitations'] );
		$this->assertSame( 'rate_limit_scope_unverified', self::lims( $mid )['store_api_checkout']['code'] );
	}

	public function test_renamed_shortcode_tag_blocks_only_the_classic_claim(): void {
		$f       = array( 'checkout_shortcode_tag' => true );
		$classic = self::snap( array(), $f );
		$this->assertSame( array( 'unknown', 'checkout_shortcode_renamed' ), array( self::rows( $classic )['checkout_page']['checkout_type'], self::lims( $classic )['checkout_page']['code'] ) );
		$block = self::snap( array( 'checkout' => self::page_variants()['block'] ), $f );
		$this->assertSame( 'block', self::rows( $block )['checkout_page']['checkout_type'] );
	}

	public function test_saved_payment_delay_is_never_asserted_as_the_value_in_use(): void {
		foreach ( array( array(), array( 'saved_method_delay' => true ) ) as $f ) {
			$row = self::rows( self::snap( array(), $f ) )['add_payment_method'];
			$this->assertSame( array( 'id' => 'core_saved_payment_delay', 'configured' => null, 'scope_known' => true ), array_diff_key( $row['protections'][0], array( 'note' => 1 ) ) );
			$this->assertSame( 'unknown', $row['protection_state'] );
			$this->assertContains( 'This check does not confirm the wait in use. The wait applies to one account at a time, so it does not slow many new accounts.', $row['limitations'] );
			$this->assertSame( ! empty( $f ), in_array( 'A plugin or theme changes this wait.', $row['facts'], true ) );
		}
	}

	public function test_my_account_page_state_is_a_fact_only(): void {
		$states = array(
			'published'   => self::myaccount(),
			'not_set'     => CheckoutProtection::page_from_row( array( 'setting' => 'absent', 'id' => null ), null, false, false ),
			'unpublished' => CheckoutProtection::page_from_row( array( 'setting' => 'set', 'id' => 8 ), array( 'ID' => '8', 'post_status' => 'draft' ), false, false ),
			'missing'     => CheckoutProtection::page_from_row( array( 'setting' => 'set', 'id' => 8 ), null, false, false ),
		);
		$ref = null;
		foreach ( $states as $name => $m ) {
			$row = self::rows( self::snap( array( 'myaccount' => $m ) ) )['add_payment_method'];
			$this->assertSame( 'available_in_core', $row['exposure'], "$name: the core form handler does not depend on the page" );
			$shape = array( $row['exposure'], $row['protection_state'], $row['protections'], $row['check_next'] );
			$ref   = $ref ?? $shape;
			$this->assertSame( $ref, $shape, $name );
		}
	}

	public function test_advice_names_the_controls_and_the_paths_to_verify(): void {
		$snap = self::snap( array( 'rate_limit' => 'no' ) );
		$rows = self::rows( $snap );
		$this->assertStringContainsString( 'WooCommerce > Settings > Advanced > Features > Rate limit Checkout', $rows['store_api_checkout']['check_next'] );
		$this->assertStringContainsString( 'It does not cover the classic checkout form, paying an existing order on the order payment page or saving a payment method.', $rows['store_api_checkout']['check_next'] );
		$this->assertStringContainsString( 'payment provider\'s fraud and decline dashboard', $rows['store_api_checkout']['check_next'] );
		foreach ( array( 'checkout_page', 'order_pay', 'add_payment_method' ) as $id ) {
			$this->assertStringContainsString( 'server-side Turnstile, reCAPTCHA or hCaptcha integration that explicitly covers', $rows[ $id ]['check_next'], $id );
			$this->assertStringContainsString( 'confirm the coverage with its vendor', $rows[ $id ]['check_next'], $id );
		}
		$this->assertStringContainsString( 'saved-card and fraud controls', $rows['add_payment_method']['check_next'] );
		$this->assertContains( 'A bot check shown on the checkout page does not by itself cover requests sent straight to the Store API.', $rows['store_api_checkout']['limitations'] );
		$this->assertContains( 'A bot check on the checkout page does not establish protection for this page.', $rows['order_pay']['limitations'] );
		$this->assertStringContainsString( 'fraud and decline dashboard', (string) CheckoutProtection::limitations( $snap )[0]['check_next'] );
		$all = implode( ' ', self::strings( $snap ) );
		$this->assertStringNotContainsString( 'disable', strtolower( $all ), 'never prescribes switching the Store API off' );
	}

	public function test_copy_is_plain_and_never_claims_protection(): void {
		$forbidden = array( '—', 'HPOS', 'wc_', 'wp_', 'post_', '/wc/store', '_enabled', 'checkout-draft', 'blaze-mcp-toolkit', 'woocommerce/', '{', '%', 'unprotected', 'is protected', 'are protected', 'safe', 'nothing is installed', 'not installed' );
		foreach ( array_slice( self::matrix(), 0, 600 ) as $snap ) {
			foreach ( self::strings( $snap ) as $s ) {
				foreach ( $forbidden as $bad ) {
					$this->assertStringNotContainsString( $bad, $s, "copy contains $bad" );
				}
				$this->assertDoesNotMatchRegularExpression( '/\b(publish|draft|trash|on-hold|pending)\b/', $s, 'no status slugs' );
			}
		}
		foreach ( CheckoutProtection::REASON_CODES as $code ) {
			$this->assertNotSame( 'This could not be checked.', CheckoutProtection::reason( $code ), $code );
			$this->assertStringNotContainsString( '—', CheckoutProtection::reason( $code ) );
		}
	}

	public function test_limitations_cover_every_gap_and_only_known_codes(): void {
		foreach ( self::matrix() as $snap ) {
			$lims = CheckoutProtection::limitations( $snap );
			$this->assertSame( 'protection_enforcement_unverified', $lims[0]['code'] );
			$this->assertSame( 'protection', $lims[0]['scope'] );
			$by = array();
			foreach ( $lims as $l ) {
				$this->assertSame( self::LIMITATION_KEYS, array_keys( $l ) );
				$this->assertContains( $l['code'], CheckoutProtection::REASON_CODES );
				$this->assertSame( CheckoutProtection::reason( $l['code'] ), $l['what'] );
				$by[ $l['scope'] ] = $l;
			}
			foreach ( CheckoutProtection::paths( $snap ) as $row ) {
				$gap = 'configured' !== $row['protection_state']
					|| array() !== array_filter( $row['protections'], static fn( $p ) => ! $p['scope_known'] );
				$this->assertSame( $gap, isset( $by[ $row['id'] ] ), $row['id'] . ' gap is disclosed exactly when it exists' );
				if ( isset( $by[ $row['id'] ] ) ) {
					$this->assertSame( $row['check_next'], $by[ $row['id'] ]['check_next'] );
				}
			}
		}
	}

	public function test_store_api_order_payment_is_a_separate_row(): void {
		$rows = self::rows( self::snap( array( 'rate_limit' => 'yes' ) ) );
		$this->assertNotSame( $rows['store_api_checkout']['label'], $rows['store_api_order_pay']['label'] );
		$this->assertNotSame( $rows['store_api_checkout']['protections'][0]['note'], $rows['store_api_order_pay']['protections'][0]['note'] );
		$this->assertContains( 'Rate limit Checkout also applies to this route when it is on.', $rows['store_api_order_pay']['facts'] );
		$this->assertContains( 'Rate limit Checkout does not cover this page.', $rows['order_pay']['facts'], 'the classic order payment page does not inherit it' );
	}

	public function test_identical_inputs_give_identical_bytes(): void {
		foreach ( array_slice( self::matrix(), 0, 120 ) as $snap ) {
			$this->assertSame( self::show( CheckoutProtection::paths( $snap ) ), self::show( CheckoutProtection::paths( $snap ) ) );
			$this->assertSame( self::show( CheckoutProtection::limitations( $snap ) ), self::show( CheckoutProtection::limitations( $snap ) ) );
			$this->assertSame( self::show( CheckoutProtection::cache_facts( $snap ) ), self::show( CheckoutProtection::cache_facts( $snap ) ) );
		}
	}

	public function test_denied_snapshot_yields_no_facts(): void {
		$denied = array( 'permitted' => false );
		$this->assertSame( array(), CheckoutProtection::paths( $denied ) );
		$this->assertSame( array(), CheckoutProtection::limitations( $denied ) );
		$this->assertSame( array(), CheckoutProtection::attribution_support( $denied ) );
		$this->assertSame( array( 'facts_version' => 1, 'permitted' => false ), CheckoutProtection::cache_facts( $denied ) );
		$this->assertSame( array(), CheckoutProtection::paths( array() ), 'a malformed snapshot fails closed' );
	}

	public function test_denied_user_triggers_no_read(): void {
		$GLOBALS['blzmcp_test_caps']    = array( 'edit_posts' );
		$GLOBALS['blzmcp_test_options'] = array();
		$this->assertSame( array( 'permitted' => false ), CheckoutProtection::snapshot(), 'without manage_woocommerce nothing is read' );
		$this->assertSame( array(), CheckoutProtection::paths(), 'the no-argument entry point fails closed too' );
		$this->assertSame( array( 'facts_version' => 1, 'permitted' => false ), CheckoutProtection::cache_facts() );
		unset( $GLOBALS['blzmcp_test_caps'], $GLOBALS['blzmcp_test_options'] );
	}

	public function test_cache_facts_are_scalars_without_page_text(): void {
		$canary  = 'BLZCANARY-' . bin2hex( random_bytes( 6 ) );
		$content = '<p>' . $canary . ' https://shop.example/checkout/order-pay/12/?key=wc_order_' . $canary . '</p>[woocommerce_checkout]';
		$snap    = self::snap( array( 'checkout' => self::page( $content ), 'rate_limit' => 'absent' ), array( 'rate_limit_id' => true ) );
		$facts   = CheckoutProtection::cache_facts( $snap );
		$this->assertSame(
			array( 'facts_version', 'permitted', 'wc_version', 'wc_verified', 'checkout_page_id', 'checkout_page_state', 'checkout_page_status', 'checkout_page_bytes', 'checkout_content_sha256', 'checkout_marker', 'checkout_type', 'myaccount_page_id', 'myaccount_page_state', 'rate_limit_checkout', 'order_attribution', 'filters' ),
			array_keys( $facts )
		);
		$this->assertSame( hash( 'sha256', $content ), $facts['checkout_content_sha256'] );
		$this->assertSame( array( 7, 'published', 'publish', strlen( $content ), 'classic', 'classic', 'absent' ), array( $facts['checkout_page_id'], $facts['checkout_page_state'], $facts['checkout_page_status'], $facts['checkout_page_bytes'], $facts['checkout_marker'], $facts['checkout_type'], $facts['rate_limit_checkout'] ) );
		$this->assertSame( array_keys( CheckoutProtection::WATCHED_FILTERS ), array_keys( $facts['filters'] ) );
		array_walk_recursive(
			$facts,
			function ( $v ) {
				$this->assertTrue( is_scalar( $v ) || null === $v );
			}
		);
		$all = self::show( array( $facts, CheckoutProtection::paths( $snap ), CheckoutProtection::limitations( $snap ), CheckoutProtection::attribution_support( $snap ) ) );
		$this->assertStringNotContainsString( $canary, $all );
		$this->assertStringNotContainsString( 'key=', $all );
		$this->assertStringNotContainsString( 'shop.example', $all );

		$changed = self::snap( array( 'checkout' => self::page( $content . ' ' ), 'rate_limit' => 'absent' ), array( 'rate_limit_id' => true ) );
		$this->assertNotSame( $facts, CheckoutProtection::cache_facts( $changed ), 'a page edit changes the cache facts' );
		$this->assertNotSame( $facts, CheckoutProtection::cache_facts( array_merge( $snap, array( 'rate_limit' => 'no' ) ) ), 'saving the default value changes the cache facts' );
	}

	public function test_attribution_support_is_reported_apart_from_protection(): void {
		$cases = array(
			array( 'yes', array(), '11.3.1', true, true, null ),
			array( 'absent', array(), '10.9.0', true, true, null ),
			array( 'no', array(), '11.3.1', false, false, 'attribution_disabled' ),
			array( 'unrecognised', array(), '11.3.1', null, false, 'attribution_setting_unrecognised' ),
			array( 'yes', array( 'attribution_prefix' => true ), '11.3.1', true, false, 'attribution_prefix_override' ),
			array( 'yes', array( 'attribution_fields' => true ), '11.3.1', true, false, 'attribution_fields_override' ),
			array( 'yes', array( 'attribution_allow_tracking' => true ), '11.3.1', true, true, null ),
			array( 'yes', array(), '11.4.0', null, false, 'wc_version_unverified' ),
			array( 'absent', array(), null, null, false, 'wc_version_unverified' ),
		);
		foreach ( $cases as $i => $c ) {
			$snap = self::snap( array( 'attribution' => $c[0], 'wc_version' => $c[2] ), $c[1] );
			$a    = CheckoutProtection::attribution_support( $snap );
			$this->assertSame( array( $c[3], $c[4], $c[5] ), array( $a['enabled'], $a['supported'], $a['reason_code'] ), "case $i" );
			$this->assertSame( array( 'option', 'enabled', 'supported', 'reason_code', 'prefix_filter', 'fields_filter', 'allow_tracking_filter' ), array_keys( $a ) );
		}
		$note = CheckoutProtection::attribution_note();
		$this->assertStringContainsString( 'it does not block bots or protect any checkout path', $note );
		$this->assertStringNotContainsString( '—', $note );
		// Attribution never changes a path row.
		$on  = self::snap( array( 'attribution' => 'yes' ) );
		$off = self::snap( array( 'attribution' => 'no' ), array( 'attribution_prefix' => true, 'attribution_fields' => true ) );
		$this->assertSame( self::show( CheckoutProtection::paths( $on ) ), self::show( CheckoutProtection::paths( $off ) ) );
	}
}
