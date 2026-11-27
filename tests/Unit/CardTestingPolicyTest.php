<?php
/**
 * Release copy for the card-testing check (FEATURE-PLAN 1.3 item 10, contract C11).
 *
 * The suggested privacy policy text says what the check reads and sends (in-memory network
 * grouping, counts and shares only, marketing-source kind only, log summaries as counts, no new
 * tracking, no outside requests), keeps the existing caveats, and makes no claim the code does not
 * support. The readme and the plugin header agree on the version, keep WordPress.org's limits, drop
 * the old failed-versus-paid shortcut and quote the check's limits from the code's own constants.
 *
 * No WordPress is loaded: __() and esc_html() are passthrough stubs defined once in
 * setUpBeforeClass(), as CheckoutProtectionTest does (tests/bootstrap.php stubs only options,
 * transients, capabilities and filters).
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Tests\Unit;

use Blaze\McpToolkit\Abilities\CardTesting;
use Blaze\McpToolkit\Diagnostics\CardTestingRules;
use Blaze\McpToolkit\Diagnostics\PaymentRules;
use Blaze\McpToolkit\Privacy\PolicyText;
use PHPUnit\Framework\TestCase;

final class CardTestingPolicyTest extends TestCase {

	/** WordPress.org's limit for one upgrade notice, in characters. */
	private const UPGRADE_NOTICE_MAX = 300;

	/** WordPress.org's limit for the readme short description, in characters. */
	private const SHORT_DESCRIPTION_MAX = 150;

	/** The existing store health caveat (free-text log lines), which must stay as it is. */
	private const HEALTH_CAVEAT = 'Error log lines are sent with email addresses, phone numbers, card numbers and passwords or keys removed, but a log line is free text and may still contain a name.';

	/** The owner-question FAQ heading. */
	private const FAQ_QUESTION = 'Is someone running card-testing attacks against my checkout?';

	/** Translation and escaping passthrough for PolicyText::content() (WordPress is not loaded). */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! function_exists( '__' ) ) {
			eval( 'function __( $text, $domain = "default" ) { return $text; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- global test stub, defined once.
		}
		if ( ! function_exists( 'esc_html' ) ) {
			eval( 'function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, "UTF-8" ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- global test stub, defined once.
		}
	}

	/**
	 * The policy paragraphs as plain text (escaping undone).
	 *
	 * @return string[]
	 */
	private static function paragraphs(): array {
		$html = PolicyText::content();
		preg_match_all( '#<p>(.*?)</p>#s', $html, $m );
		return array_map( static fn( string $p ): string => html_entity_decode( $p, ENT_QUOTES, 'UTF-8' ), $m[1] );
	}

	/**
	 * The card-testing paragraph, or ''.
	 */
	private static function card_paragraph(): string {
		foreach ( self::paragraphs() as $p ) {
			if ( str_starts_with( $p, 'The card-testing check' ) ) {
				return $p;
			}
		}
		return '';
	}

	/**
	 * A file at the repository root, line endings normalised.
	 *
	 * @param string $name File name.
	 */
	private static function root_file( string $name ): string {
		return str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $name ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repository file.
	}

	/**
	 * The readme's header block and short description (before the first section).
	 */
	private static function readme_head(): string {
		return explode( "\n== ", self::root_file( 'readme.txt' ), 2 )[0];
	}

	/**
	 * One readme header field, or null.
	 *
	 * @param string $field Field name.
	 */
	private static function readme_field( string $field ): ?string {
		return 1 === preg_match( '/^' . preg_quote( $field, '/' ) . ':[ \t]*(.+?)[ \t]*$/m', self::readme_head(), $m ) ? $m[1] : null;
	}

	/**
	 * One readme section's body, or ''.
	 *
	 * @param string $name Section name.
	 */
	private static function readme_section( string $name ): string {
		$parts = explode( "\n== " . $name . " ==\n", self::root_file( 'readme.txt' ), 2 );
		return isset( $parts[1] ) ? explode( "\n== ", $parts[1], 2 )[0] : '';
	}

	/**
	 * The "= x =" entries of a section, keyed by heading.
	 *
	 * @param string $section Section body.
	 * @return array<string,string>
	 */
	private static function entries( string $section ): array {
		preg_match_all( '/^= (.+?) =\n(.*?)(?=^= |\z)/ms', $section, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $row ) {
			$out[ $row[1] ] = trim( $row[2] );
		}
		return $out;
	}

	/**
	 * One plugin header field from the main plugin file, or null.
	 *
	 * @param string $field Field name.
	 */
	private static function plugin_field( string $field ): ?string {
		return 1 === preg_match( '/^[ \t]*\*[ \t]*' . preg_quote( $field, '/' ) . ':[ \t]*(.+?)[ \t]*$/m', self::root_file( 'blaze-mcp-toolkit-for-woocommerce.php' ), $m ) ? $m[1] : null;
	}

	/**
	 * The readme with Markdown bold markers removed.
	 */
	private static function readme_text(): string {
		return str_replace( '**', '', self::root_file( 'readme.txt' ) );
	}

	/**
	 * The readme sections as the WordPress.org readme parser builds them before trimming (mirrors
	 * the parser Plugin Check bundles, includes/Lib/Readme/Parser.php): a line starting "==" opens a
	 * section, the FAQ and changelog aliases apply, and an unknown section such as External Services
	 * is kept under an h3 title in other notes, which the parser then appends to the description.
	 *
	 * @return array<string,string>
	 */
	private static function parser_sections(): array {
		$expected = array( 'description', 'installation', 'faq', 'screenshots', 'changelog', 'upgrade_notice', 'other_notes' );
		$aliases  = array(
			'frequently_asked_questions' => 'faq',
			'change_log'                 => 'changelog',
			'screenshot'                 => 'screenshots',
		);
		$sections = array_fill_keys( $expected, '' );
		$body     = explode( "\n== ", self::root_file( 'readme.txt' ), 2 );
		$name     = '';
		$current  = '';
		foreach ( explode( "\n", '== ' . ( $body[1] ?? '' ) ) as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				$current .= "\n";
				continue;
			}
			if ( str_starts_with( $trimmed, '==' ) ) {
				if ( '' !== $name ) {
					$sections[ $name ] .= trim( $current );
				}
				$current = '';
				$title   = trim( $line, "#= \t" );
				$name    = strtolower( str_replace( ' ', '_', $title ) );
				$name    = $aliases[ $name ] ?? $name;
				if ( ! in_array( $name, $expected, true ) ) {
					$current .= '<h3>' . $title . '</h3>';
					$name     = 'other_notes';
				}
				continue;
			}
			$current .= $line . "\n";
		}
		if ( '' !== $name ) {
			$sections[ $name ] .= trim( $current );
		}
		if ( '' !== $sections['other_notes'] ) {
			$sections['description'] .= "\n" . $sections['other_notes'];
		}
		unset( $sections['other_notes'], $sections['upgrade_notice'] );
		return array_filter( $sections );
	}

	// ------------------------------------------------------------------ Privacy policy text.

	public function test_policy_keeps_its_html_shape_and_escapes_every_paragraph(): void {
		$html = PolicyText::content();
		$this->assertCount( 6, self::paragraphs(), 'five existing paragraphs plus the card-testing paragraph' );
		$inner = str_replace( array( '<p>', '</p>' ), '', $html );
		$this->assertStringNotContainsString( '<', $inner, 'no markup inside a paragraph: every paragraph goes through esc_html()' );
		$this->assertStringNotContainsString( '>', $inner );
		$this->assertStringStartsWith( '<p>', $html );
		$this->assertStringEndsWith( '</p>', $html );
	}

	public function test_card_paragraph_says_what_is_read_and_how_networks_are_reduced(): void {
		$card = self::card_paragraph();
		$this->assertNotSame( '', $card, 'a paragraph describes the card-testing check' );
		foreach (
			array(
				'The card-testing check looks for bursts of failed orders.',
				'It reads what WooCommerce already saved on recent orders',
				'the kind of marketing source recorded',
				'never which site, search engine or campaign',
				'the customer\'s IP address',
				'While the check runs, each IP address is reduced in memory to the network it belongs to',
				'only counts and shares of those networks are sent',
				'No IP address, network or code made from one is sent to the AI provider, kept in a cache or logged',
				'email addresses are not used',
				'Payment plugin log entries are sent only as counts and times of fixed error categories.',
				'It does not send customer names, email addresses, phone numbers or addresses.',
				'It adds no tracking, collects nothing new from shoppers and makes no requests to any outside service',
			) as $statement
		) {
			$this->assertStringContainsString( $statement, $card );
		}
	}

	public function test_card_paragraph_example_cap_matches_the_rules(): void {
		$this->assertStringContainsString(
			sprintf( 'up to %d example failed orders (order number, date, status, payment method, total, currency and a link to the order in the store\'s admin)', CardTestingRules::MAX_EXAMPLES ),
			self::card_paragraph(),
			'the policy quotes the same example cap the rules apply, and only the fields an example carries'
		);
	}

	public function test_card_paragraph_never_claims_it_sends_log_text(): void {
		$card = strtolower( self::card_paragraph() );
		foreach ( array( 'log line', 'log message', 'log text', 'excerpt' ) as $phrase ) {
			$this->assertStringNotContainsString( $phrase, $card, "card-testing paragraph does not mention sending {$phrase}s" );
		}
	}

	public function test_existing_policy_statements_and_caveats_stay(): void {
		$all = implode( "\n", self::paragraphs() );
		$this->assertStringContainsString( self::HEALTH_CAVEAT, $all, 'the store health free-text caveat is unchanged' );
		$this->assertStringContainsString( 'The plugin does not contact any server itself.', $all );
		$this->assertStringContainsString( 'The payment health check sends counts of orders per payment method', $all );
		$this->assertStringContainsString( 'up to 5 example orders per payment method', $all, 'payment health keeps its own per-method example cap' );
		$this->assertStringContainsString( 'for 30 days, and a log of store changes such as plugin updates for 180 days', $all, 'audit and change-log retention unchanged' );
		$this->assertStringContainsString( 'Enable Abilities for MCP or Easy MCP AI', $all );
	}

	/**
	 * Claims the policy must never make: personal network or email data leaving or staying,
	 * protection or verdict guarantees, tracking or outside lookups, and the old shortcut.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function forbidden_policy_claims(): array {
		$out = array();
		foreach (
			array(
				'ip addresses are sent',
				'sends ip addresses',
				'sends the ip',
				'ip addresses are stored',
				'stores ip addresses',
				'ip addresses are logged',
				'logs ip addresses',
				'hashed ip',
				'hash of the ip',
				'network label',
				'email pattern',
				'groups email',
				'hashes email',
				'blocks',
				'prevents card testing',
				'stops card testing',
				'confirms card testing',
				'confirmed',
				'protects your checkout',
				'checkout is safe',
				'cookie',
				'geolocat',
				'fingerprint',
				'outnumber paid',
				'exceed paid',
			) as $phrase
		) {
			$out[ $phrase ] = array( $phrase );
		}
		return $out;
	}

	/**
	 * The policy makes none of the forbidden claims.
	 *
	 * @dataProvider forbidden_policy_claims
	 *
	 * @param string $phrase Forbidden phrase (lower case).
	 */
	public function test_policy_makes_no_forbidden_claim( string $phrase ): void {
		$this->assertStringNotContainsString( $phrase, strtolower( implode( "\n", self::paragraphs() ) ) );
	}

	public function test_policy_has_no_em_or_en_dash(): void {
		$all = implode( "\n", self::paragraphs() );
		$this->assertStringNotContainsString( "\u{2014}", $all );
		$this->assertStringNotContainsString( "\u{2013}", $all );
	}

	// ------------------------------------------------------------------ Version identifiers.

	public function test_header_constant_and_stable_tag_agree(): void {
		$header = self::plugin_field( 'Version' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', (string) $header, 'a plain release version, no pre-release suffix' );
		$this->assertSame( 1, preg_match( "/define\(\s*'BLZMCP_VERSION',\s*'([^']+)'\s*\);/", self::root_file( 'blaze-mcp-toolkit-for-woocommerce.php' ), $m ) );
		$this->assertSame( $header, $m[1], 'BLZMCP_VERSION equals the plugin header Version' );
		$this->assertSame( $header, self::readme_field( 'Stable tag' ), 'readme Stable tag equals the plugin header Version' );
	}

	public function test_floors_match_between_readme_and_plugin_header(): void {
		$this->assertSame( self::plugin_field( 'Requires at least' ), self::readme_field( 'Requires at least' ) );
		$this->assertSame( self::plugin_field( 'Requires PHP' ), self::readme_field( 'Requires PHP' ) );
		$this->assertSame( 'woocommerce', self::plugin_field( 'Requires Plugins' ), 'WooCommerce stays the only required plugin' );
		$this->assertNotNull( self::plugin_field( 'WC requires at least' ) );
		$this->assertNotNull( self::plugin_field( 'WC tested up to' ) );
	}

	// ------------------------------------------------------------------ Readme limits and copy.

	public function test_current_version_has_a_changelog_entry_and_an_upgrade_notice(): void {
		$version   = (string) self::readme_field( 'Stable tag' );
		$changelog = self::entries( self::readme_section( 'Changelog' ) );
		$this->assertArrayHasKey( $version, $changelog );
		$this->assertArrayHasKey( $version, self::entries( self::readme_section( 'Upgrade Notice' ) ) );
		$this->assertArrayHasKey( '1.2.1', $changelog, 'older changelog entries are kept' );
		$this->assertArrayHasKey( '1.0.0', $changelog );
	}

	public function test_changelog_lists_the_shared_log_change_that_payment_health_makes(): void {
		$entry = self::entries( self::readme_section( 'Changelog' ) )[ (string) self::readme_field( 'Stable tag' ) ] ?? '';
		$this->assertStringContainsString( 'Payment health no longer reports a payment plugin\'s log as error-free, or gives all of its entries to one plugin, when the same log is also linked to another payment plugin', $entry, 'ruling Q2(b) is in the 1.3.0 changelog' );
		$this->assertStringContainsString( 'both plugins\' log checks now read unknown, not checked, and say why', $entry );
		$this->assertContains( 'shared_log_source', PaymentRules::REASONS, 'the changelog claim holds only while payment health has its shared-log reason' );
	}

	public function test_every_upgrade_notice_is_within_the_wordpress_org_limit(): void {
		$notices = self::entries( self::readme_section( 'Upgrade Notice' ) );
		$this->assertNotEmpty( $notices );
		foreach ( $notices as $version => $text ) {
			$this->assertLessThanOrEqual( self::UPGRADE_NOTICE_MAX, mb_strlen( $text, 'UTF-8' ), "upgrade notice {$version} is at most 300 characters" );
		}
	}

	public function test_current_upgrade_notice_says_to_refresh_tools_and_switch_the_new_tool_on(): void {
		$notice = self::entries( self::readme_section( 'Upgrade Notice' ) )[ (string) self::readme_field( 'Stable tag' ) ] ?? '';
		$this->assertStringContainsString( 'card-testing check', $notice );
		$this->assertStringContainsString( 'Easy MCP AI users: tick card testing under Easy MCP AI', $notice );
		$this->assertStringContainsString( 'Refresh tools', $notice );
	}

	public function test_readme_sections_fit_the_wordpress_org_parser_without_being_trimmed(): void {
		$limits   = array(
			'faq'       => 5000,
			'changelog' => 5000,
		);
		$sections = self::parser_sections();
		$this->assertArrayHasKey( 'description', $sections );
		$this->assertStringContainsString( '<h3>External Services</h3>', $sections['description'], 'the unknown External Services section counts towards the description, as on WordPress.org' );
		foreach ( $sections as $name => $content ) {
			$words  = $limits[ $name ] ?? 2500;
			$pieces = preg_split( '/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
			$this->assertLessThan( $words * 2, count( (array) $pieces ), "readme section {$name} stays under the parser's {$words}-word limit, so WordPress.org shows it whole" );
		}
	}

	public function test_short_description_is_within_the_wordpress_org_limit(): void {
		$chunks = array_values( array_filter( array_map( 'trim', explode( "\n\n", self::readme_head() ) ) ) );
		$this->assertCount( 2, $chunks, 'header fields, then the short description' );
		$this->assertLessThanOrEqual( self::SHORT_DESCRIPTION_MAX, mb_strlen( $chunks[1], 'UTF-8' ) );
	}

	public function test_new_release_copy_has_no_em_or_en_dash(): void {
		$version = (string) self::readme_field( 'Stable tag' );
		$copy    = ( self::entries( self::readme_section( 'Changelog' ) )[ $version ] ?? '' ) . "\n"
			. ( self::entries( self::readme_section( 'Upgrade Notice' ) )[ $version ] ?? '' ) . "\n"
			. ( self::entries( self::readme_section( 'Frequently Asked Questions' ) )[ self::FAQ_QUESTION ] ?? '' );
		$this->assertStringNotContainsString( "\u{2014}", $copy );
		$this->assertStringNotContainsString( "\u{2013}", $copy );
	}

	public function test_readme_drops_the_failed_versus_paid_shortcut(): void {
		$readme = strtolower( self::readme_text() );
		foreach ( array( 'outnumber paid', 'failed orders exceed paid', 'a common sign of card testing', 'likely sign of card testing' ) as $phrase ) {
			$this->assertStringNotContainsString( $phrase, $readme );
		}
		$this->assertStringContainsString( 'Only the card-testing check says whether failed orders look like card testing', self::readme_text() );
	}

	public function test_readme_lists_the_tool_the_faq_and_the_privacy_reduction(): void {
		$this->assertStringContainsString( '* Card testing (`card-testing`): answers "' . self::FAQ_QUESTION . '"', self::readme_text(), 'tool list entry in the existing style' );
		$faq = self::entries( self::readme_section( 'Frequently Asked Questions' ) );
		$this->assertArrayHasKey( self::FAQ_QUESTION, $faq, 'owner-question FAQ entry' );
		$answer = str_replace( '**', '', $faq[ self::FAQ_QUESTION ] );
		foreach (
			array(
				'not that an attack is confirmed',
				'It counts saved orders, not payment attempts',
				'not that your checkout is safe',
				'It can\'t tell whether they stop bots',
				'unknown, not unprotected',
				'Payment plugin logs (Stripe) are only read for people allowed to see store diagnostics',
				'A result is kept for up to a minute',
				'tick card testing under Easy MCP AI › Tools › Abilities',
				'choose Refresh tools',
				'in memory and only while it runs: no IP address, network or code made from one is sent to the AI, cached or logged, and email addresses are not used',
				'never which site, search engine or campaign',
				'It adds no tracking and collects nothing new from your shoppers.',
				'Only the card-testing check says whether failed orders look like card testing',
			) as $statement
		) {
			$this->assertStringContainsString( $statement, $answer );
		}
		$description = self::readme_section( 'Description' );
		$this->assertStringContainsString( 'Payment health and the card-testing check return counts, times, fixed summary categories and a few example order numbers', $description, 'the Privacy list covers the new check' );
		$this->assertStringContainsString( 'They send no customer names, emails, phone numbers, addresses or IP addresses', $description );
		$this->assertStringContainsString( 'Card testing groups order IP addresses by network in memory only, and never sends, caches or logs them.', $description );
	}

	public function test_readme_quotes_the_checks_limits_from_the_code(): void {
		$readme = self::readme_text();
		$this->assertSame( 60, CardTesting::CACHE_TTL, 'the readme says results are kept for up to a minute' );
		$this->assertSame( 900, CardTestingRules::RECENT_SECONDS, 'the readme says the last 15 minutes' );
		foreach (
			array(
				sprintf( '(%d to %d hours, %d by default)', CardTesting::MIN_HOURS, CardTesting::MAX_HOURS, CardTesting::DEFAULT_HOURS ),
				sprintf( 'with the store\'s own earlier orders from the last %d days', CardTestingRules::HISTORY_DAYS ),
				sprintf( 'fewer than %d accepted or failed orders before the window, or less than %d days of them', CardTestingRules::MIN_BASELINE_ATTEMPTS, CardTestingRules::MIN_BASELINE_SPAN_DAYS ),
				sprintf( 'only bursts of %d or more failed orders in one hour are flagged', CardTestingRules::ABSOLUTE_BURST_FAILED ),
				sprintf( 'up to %d example failed orders', CardTestingRules::MAX_EXAMPLES ),
				sprintf( 'the last %d hours unless you ask for another window, up to %d days', CardTesting::DEFAULT_HOURS, intdiv( CardTesting::MAX_HOURS, 24 ) ),
			) as $statement
		) {
			$this->assertStringContainsString( $statement, $readme );
		}
	}
}
