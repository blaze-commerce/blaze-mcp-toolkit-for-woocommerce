<?php
/**
 * Checkout-path protection facts for the card-testing check: which payment paths WooCommerce
 * offers and which readable settings cover each one.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Passive reads only (FEATURE-PLAN item 5, contract C7). Reads four allowlisted options, the
 * WooCommerce version constant, whether nine named filters have callbacks (never applies them)
 * and at most MAX_PAGE_BYTES of the published checkout page's content. It never renders blocks,
 * runs shortcodes, builds the REST server, dispatches a request, calls a rate limiter, form
 * handler or gateway, reads a page password or secret, or outputs page text, IDs or URLs.
 *
 * Source facts and their version:path:line citations are pinned in
 * tests/fixtures/checkout-protection-contracts.json. Facts that are the same in both verified
 * WooCommerce versions hold for every plain release between them; a fact that differs holds only
 * at the exact version where it was read. Outside the range nothing is assumed.
 *
 * Every result is built from fixed PHP text. Enforcement is never verified: a configured setting
 * means the saved setting is on, not that it works.
 */
final class CheckoutProtection {

	/** Version of the cache-fact shape. */
	public const FACTS_VERSION = 1;

	/** Fixed path IDs, in output order (C7). */
	public const PATH_IDS = array( 'checkout_page', 'store_api_checkout', 'store_api_order_pay', 'order_pay', 'add_payment_method' );

	/** Most checkout-page bytes read (C7). A larger page is not read. */
	public const MAX_PAGE_BYTES = 262144;

	/** Oldest and newest WooCommerce releases whose source was read. */
	public const WC_MIN_VERIFIED = '10.9.0';
	public const WC_MAX_VERIFIED = '11.3.1';

	/**
	 * Whether the Rate limit Checkout route pattern ignores letter case, per exact verified
	 * version. The two ends differ, so any version between them is not verified for this fact.
	 */
	public const ROUTE_CASE_INSENSITIVE = array(
		'10.9.0' => false,
		'11.3.1' => true,
	);

	/** Allowlisted options (C7). No other option is read. */
	public const CHECKOUT_PAGE_OPTION  = 'woocommerce_checkout_page_id';
	public const MYACCOUNT_PAGE_OPTION = 'woocommerce_myaccount_page_id';
	public const RATE_LIMIT_OPTION     = 'woocommerce_feature_rate_limit_checkout_enabled';
	public const ATTRIBUTION_OPTION    = 'woocommerce_feature_order_attribution_enabled';

	/** Source defaults inside the verified range. */
	public const RATE_LIMIT_DEFAULT       = 'no';
	public const ATTRIBUTION_DEFAULT      = 'yes';
	public const SOURCE_RATE_LIMIT        = 3;
	public const SOURCE_RATE_SECONDS      = 60;
	public const SOURCE_SAVED_METHOD_WAIT = 20;

	/** Literal markers (C7). */
	public const CHECKOUT_BLOCK   = 'woocommerce/checkout';
	public const CLASSIC_BLOCK    = 'woocommerce/classic-shortcode';
	public const CLASSIC_SHORTCODE = 'woocommerce_checkout';

	/** Filters whose presence (never their result) qualifies a fact. */
	public const WATCHED_FILTERS = array(
		'rate_limit_options'         => 'woocommerce_store_api_rate_limit_options',
		'rate_limit_id'              => 'woocommerce_store_api_rate_limit_id',
		'saved_method_delay'         => 'woocommerce_payment_gateway_add_payment_method_delay',
		'checkout_page_id'           => 'woocommerce_get_checkout_page_id',
		'myaccount_page_id'          => 'woocommerce_get_myaccount_page_id',
		'checkout_shortcode_tag'     => 'woocommerce_checkout_shortcode_tag',
		'attribution_prefix'         => 'wc_order_attribution_tracking_field_prefix',
		'attribution_fields'         => 'wc_order_attribution_tracking_fields',
		'attribution_allow_tracking' => 'wc_order_attribution_allow_tracking',
	);

	/** Every reason code this class emits (plain words come from reason()). */
	public const REASON_CODES = array(
		'protection_enforcement_unverified',
		'wc_version_unverified',
		'checkout_page_not_set',
		'checkout_page_invalid_setting',
		'checkout_page_missing',
		'checkout_page_unpublished',
		'checkout_page_oversize',
		'checkout_page_unreadable',
		'checkout_page_no_marker',
		'checkout_page_mixed',
		'checkout_shortcode_renamed',
		'no_readable_protection',
		'rate_limit_checkout_off',
		'rate_limit_setting_unrecognised',
		'rate_limit_filter_present',
		'rate_limit_scope_unverified',
		'attribution_disabled',
		'attribution_setting_unrecognised',
		'attribution_prefix_override',
		'attribution_fields_override',
	);

	/** Sentinel for a missing option. */
	private const ABSENT = "\0blzmcp-absent";

	/** Post statuses kept by name in cache facts; anything else is 'other'. */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit' );

	/** Block delimiter whitespace, as WordPress's block parser defines it. */
	private const WS = '[ \t\f\r\n]';

	/** Bytes read after a classic-shortcode block name to find its attributes. */
	private const ATTR_WINDOW = 1024;

	// ------------------------------------------------------------------ public entry points.

	/**
	 * The passive reads, as scalar facts. Without manage_woocommerce nothing is read.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'permitted' => false );
		}
		$filters = array();
		foreach ( self::WATCHED_FILTERS as $key => $hook ) {
			$filters[ $key ] = function_exists( 'has_filter' ) && false !== has_filter( $hook );
		}
		$checkout_setting  = self::parse_page_setting( get_option( self::CHECKOUT_PAGE_OPTION, self::ABSENT ), self::ABSENT );
		$myaccount_setting = self::parse_page_setting( get_option( self::MYACCOUNT_PAGE_OPTION, self::ABSENT ), self::ABSENT );
		return array(
			'permitted'   => true,
			'wc_version'  => self::parse_version( defined( 'WC_VERSION' ) ? constant( 'WC_VERSION' ) : null ),
			'checkout'    => self::read_page( $checkout_setting, true ),
			'myaccount'   => self::read_page( $myaccount_setting, false ),
			'rate_limit'  => self::parse_yes_no( get_option( self::RATE_LIMIT_OPTION, self::ABSENT ), self::ABSENT ),
			'attribution' => self::parse_yes_no( get_option( self::ATTRIBUTION_OPTION, self::ABSENT ), self::ABSENT ),
			'filters'     => $filters,
		);
	}

	/**
	 * The 5 C7 path rows as C8 Path arrays, in PATH_IDS order. Empty when the current user lacks
	 * manage_woocommerce (the caller checks first; this fails closed on its own too).
	 *
	 * @param array<string,mixed>|null $snapshot From snapshot(); read now when null.
	 * @return list<array<string,mixed>>
	 */
	public static function paths( ?array $snapshot = null ): array {
		$s = $snapshot ?? self::snapshot();
		if ( true !== ( $s['permitted'] ?? false ) ) {
			return array();
		}
		$ctx = self::context( $s );
		return array(
			self::checkout_page_row( $ctx ),
			self::store_api_row( $ctx, 'store_api_checkout' ),
			self::store_api_row( $ctx, 'store_api_order_pay' ),
			self::order_pay_row( $ctx ),
			self::add_payment_method_row( $ctx ),
		);
	}

	/**
	 * C8 Limitation entries for the protection rows: one general enforcement limitation, then one
	 * per path whose protection is unknown or whose built-in setting is off, and one per configured
	 * path whose setting's scope is not known.
	 *
	 * @param array<string,mixed>|null $snapshot From snapshot(); read now when null.
	 * @return list<array{scope:string,code:string,what:string,check_next:?string}>
	 */
	public static function limitations( ?array $snapshot = null ): array {
		$s = $snapshot ?? self::snapshot();
		if ( true !== ( $s['permitted'] ?? false ) ) {
			return array();
		}
		$ctx = self::context( $s );
		$out = array(
			array(
				'scope'      => 'protection',
				'code'       => 'protection_enforcement_unverified',
				'what'       => self::reason( 'protection_enforcement_unverified' ),
				'check_next' => __( 'Test each checkout path, ask your host what server or CDN rules apply, and check your payment provider\'s fraud and decline dashboard.', 'blaze-mcp-toolkit-for-woocommerce' ),
			),
		);
		foreach ( self::paths( $s ) as $row ) {
			$code = self::primary_reason( $row['id'], $row['protection_state'], $ctx );
			if ( null === $code ) {
				continue;
			}
			$out[] = array(
				'scope'      => $row['id'],
				'code'       => $code,
				'what'       => self::reason( $code ),
				'check_next' => $row['check_next'],
			);
		}
		return $out;
	}

	/**
	 * Only the scalar safe facts the card tool's cache key needs (C2): page IDs, mapped statuses,
	 * byte length and SHA-256 of the bounded content, detected type, allowlisted option values or
	 * their absence, WooCommerce version and filter-presence flags. No page text and no URL.
	 *
	 * @param array<string,mixed>|null $snapshot From snapshot(); read now when null.
	 * @return array<string,mixed>
	 */
	public static function cache_facts( ?array $snapshot = null ): array {
		$s = $snapshot ?? self::snapshot();
		if ( true !== ( $s['permitted'] ?? false ) ) {
			return array(
				'facts_version' => self::FACTS_VERSION,
				'permitted'     => false,
			);
		}
		$ctx = self::context( $s );
		$c   = $s['checkout'];
		$m   = $s['myaccount'];
		return array(
			'facts_version'           => self::FACTS_VERSION,
			'permitted'               => true,
			'wc_version'              => $s['wc_version'],
			'wc_verified'             => $ctx['verified'],
			'checkout_page_id'        => $c['id'],
			'checkout_page_state'     => $c['state'],
			'checkout_page_status'    => $c['status'],
			'checkout_page_bytes'     => $c['bytes'],
			'checkout_content_sha256' => $c['sha256'],
			'checkout_marker'         => $c['marker'],
			'checkout_type'           => $ctx['type'],
			'myaccount_page_id'       => $m['id'],
			'myaccount_page_state'    => $m['state'],
			'rate_limit_checkout'     => $s['rate_limit'],
			'order_attribution'       => $s['attribution'],
			'filters'                 => $s['filters'],
		);
	}

	/**
	 * Order attribution support, stated apart from path protection (C7): turning attribution on
	 * improves evidence and blocks nothing. `supported` is true only when the feature is on inside
	 * the verified range and no plugin changes how or which attribution details are stored.
	 *
	 * @param array<string,mixed>|null $snapshot From snapshot(); read now when null.
	 * @return array{option:string,enabled:?bool,supported:bool,reason_code:?string,prefix_filter:bool,fields_filter:bool,allow_tracking_filter:bool}|array{}
	 */
	public static function attribution_support( ?array $snapshot = null ): array {
		$s = $snapshot ?? self::snapshot();
		if ( true !== ( $s['permitted'] ?? false ) ) {
			return array();
		}
		$verified = self::version_support( $s['wc_version'] )['verified'];
		$option   = (string) $s['attribution'];
		$enabled  = null;
		if ( $verified ) {
			if ( 'yes' === $option || ( 'absent' === $option && 'yes' === self::ATTRIBUTION_DEFAULT ) ) {
				$enabled = true;
			} elseif ( 'no' === $option ) {
				$enabled = false;
			}
		}
		$prefix = true === ( $s['filters']['attribution_prefix'] ?? false );
		$fields = true === ( $s['filters']['attribution_fields'] ?? false );
		$reason = null;
		if ( ! $verified ) {
			$reason = 'wc_version_unverified';
		} elseif ( false === $enabled ) {
			$reason = 'attribution_disabled';
		} elseif ( null === $enabled ) {
			$reason = 'attribution_setting_unrecognised';
		} elseif ( $prefix ) {
			$reason = 'attribution_prefix_override';
		} elseif ( $fields ) {
			$reason = 'attribution_fields_override';
		}
		return array(
			'option'                => $option,
			'enabled'               => $enabled,
			'supported'             => null === $reason,
			'reason_code'           => $reason,
			'prefix_filter'         => $prefix,
			'fields_filter'         => $fields,
			'allow_tracking_filter' => true === ( $s['filters']['attribution_allow_tracking'] ?? false ),
		);
	}

	/** Fixed owner wording for attribution, kept apart from path protection (C7). */
	public static function attribution_note(): string {
		return __( 'Order attribution records where orders came from. Keeping it on improves the evidence this check can use; it does not block bots or protect any checkout path.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Plain words for a reason code (fixed, translated).
	 *
	 * @param string $code Reason code from REASON_CODES.
	 */
	public static function reason( string $code ): string {
		switch ( $code ) {
			case 'protection_enforcement_unverified':
				return __( 'This check reads settings and the checkout page only. It cannot confirm that any protection works, and it cannot see protection added by other plugins, the server, a CDN or the payment provider.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'wc_version_unverified':
				return __( 'This WooCommerce version has not been checked by this tool, so its checkout routes and settings are not described.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_not_set':
				return __( 'No checkout page is set in WooCommerce.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_invalid_setting':
				return __( 'The saved checkout page setting is not a valid page.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_missing':
				return __( 'The checkout page set in WooCommerce no longer exists.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_unpublished':
				return __( 'The checkout page set in WooCommerce is not published.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_oversize':
				return __( 'The checkout page is too large for this check to read.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_unreadable':
				return __( 'The checkout page could not be read.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_no_marker':
				return __( 'The checkout page has no standard checkout block or shortcode, so a page builder, pattern or theme template may supply the checkout.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_page_mixed':
				return __( 'The checkout page contains both the checkout block and the classic checkout shortcode, so which one shoppers use is not known.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'checkout_shortcode_renamed':
				return __( 'A plugin or theme renames WooCommerce\'s checkout shortcode, so a classic checkout could not be recognised.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'no_readable_protection':
				return __( 'No setting this check can read covers this path.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'rate_limit_checkout_off':
				return __( 'WooCommerce\'s Rate limit Checkout setting is off. This does not mean nothing else protects this path.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'rate_limit_setting_unrecognised':
				return __( 'Rate limit Checkout has an unexpected saved value, so whether it is on is not known.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'rate_limit_filter_present':
				return __( 'A plugin or theme changes Store API rate limits, so the limits in use are not known.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'rate_limit_scope_unverified':
				return __( 'Which request addresses Rate limit Checkout matches was not checked for this WooCommerce version.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_disabled':
				return __( 'WooCommerce order attribution is off, so where orders came from is not recorded.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_setting_unrecognised':
				return __( 'The order attribution setting has an unexpected saved value, so whether it is on is not known.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_prefix_override':
				return __( 'A plugin or theme changes how order attribution is stored, so it could not be read.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'attribution_fields_override':
				return __( 'A plugin or theme changes which order attribution details are recorded, so they could not be read.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return __( 'This could not be checked.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	// ------------------------------------------------------------------ pure parsers (unit-tested).

	/**
	 * Which literal checkout marker the content has: none, classic, block or mixed. Scans only;
	 * nothing is parsed into blocks, rendered or run, and the work is linear in the content length.
	 * A shortcode inside an HTML comment or escaped as [[shortcode]] is not a marker, as in
	 * WordPress; a classic-shortcode block counts only with its shortcode attribute set to checkout.
	 *
	 * @param string $content Bounded page content.
	 */
	public static function detect_marker( string $content ): string {
		$block   = array() !== self::block_openers( $content, self::CHECKOUT_BLOCK );
		$classic = false;
		foreach ( self::block_openers( $content, self::CLASSIC_BLOCK ) as $after ) {
			// The attributes of this tiny block sit right after its name; read a bounded window only.
			$window = substr( $content, $after, self::ATTR_WINDOW );
			$ws     = self::WS;
			if ( 1 === preg_match( '/^' . $ws . '+(?:(\{(?:(?:[^}]+|}+(?=})|(?!}' . $ws . '+\/?-->).)*+)?})' . $ws . '+)?\/?-->/s', $window, $m ) && isset( $m[1] ) ) {
				$attrs = json_decode( $m[1], true );
				if ( is_array( $attrs ) && 'checkout' === ( $attrs['shortcode'] ?? null ) ) {
					$classic = true;
					break;
				}
			}
		}
		if ( ! $classic ) {
			$classic = self::has_classic_shortcode( self::without_comments( $content ) );
		}
		if ( $block && $classic ) {
			return 'mixed';
		}
		if ( $block ) {
			return 'block';
		}
		return $classic ? 'classic' : 'none';
	}

	/**
	 * Offsets just after each opening block delimiter `<!-- wp:{name}` whose name ends there (a
	 * closing delimiter or a longer name such as woocommerce/checkout-fields-block does not count).
	 * One forward pass; no backtracking regex over the page.
	 *
	 * @param string $content Content.
	 * @param string $name    Full block name.
	 * @return list<int>
	 */
	private static function block_openers( string $content, string $name ): array {
		$ws     = array( ' ', "\t", "\f", "\r", "\n" );
		$needle = 'wp:' . $name;
		$len    = strlen( $needle );
		$out    = array();
		for ( $at = strpos( $content, $needle ); false !== $at; $at = strpos( $content, $needle, $at + 1 ) ) {
			if ( ! in_array( $content[ $at + $len ] ?? '', $ws, true ) ) {
				continue;
			}
			$i = $at - 1;
			while ( $i >= 0 && in_array( $content[ $i ], $ws, true ) ) {
				--$i;
			}
			if ( $i === $at - 1 || $i < 3 || '<!--' !== substr( $content, $i - 3, 4 ) ) {
				continue;
			}
			$out[] = $at + $len;
		}
		return $out;
	}

	/**
	 * The content with every HTML comment removed (block delimiters included: blocks are parsed
	 * before shortcodes run, and WordPress never runs a shortcode inside a comment). An unclosed
	 * comment runs to the end, as WordPress's HTML splitter treats it.
	 *
	 * @param string $content Content.
	 */
	private static function without_comments( string $content ): string {
		$out  = '';
		$pos  = 0;
		$open = strpos( $content, '<!--' );
		while ( false !== $open ) {
			$out  .= substr( $content, $pos, $open - $pos );
			$close = strpos( $content, '-->', $open + 4 );
			if ( false === $close ) {
				return $out;
			}
			$pos  = $close + 3;
			$open = strpos( $content, '<!--', $pos );
		}
		return $out . substr( $content, $pos );
	}

	/**
	 * Whether the text has the classic checkout shortcode: `[woocommerce_checkout` not preceded by
	 * another `[` (an escaped shortcode) and not followed by a word character or hyphen.
	 *
	 * @param string $text Content without comments.
	 */
	private static function has_classic_shortcode( string $text ): bool {
		$needle = '[' . self::CLASSIC_SHORTCODE;
		$len    = strlen( $needle );
		for ( $at = strpos( $text, $needle ); false !== $at; $at = strpos( $text, $needle, $at + 1 ) ) {
			$next = $text[ $at + $len ] ?? '';
			if ( ( $at > 0 && '[' === $text[ $at - 1 ] ) || 1 === preg_match( '/^[\w\-]$/', $next ) ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Classify a page from its database row (pure; the row comes from read_page()).
	 *
	 * @param array{setting:string,id:?int} $setting      Parsed page setting.
	 * @param array<string,mixed>|null      $row          ID, post_status, content_bytes, content; null when no row.
	 * @param bool                          $failed       The read failed.
	 * @param bool                          $with_content Inspect content (checkout page only).
	 * @return array{state:string,id:?int,status:?string,bytes:?int,sha256:?string,marker:?string}
	 */
	public static function page_from_row( array $setting, ?array $row, bool $failed, bool $with_content ): array {
		$out = array(
			'state'  => 'not_set',
			'id'     => null,
			'status' => null,
			'bytes'  => null,
			'sha256' => null,
			'marker' => null,
		);
		if ( 'invalid' === $setting['setting'] ) {
			$out['state'] = 'invalid_setting';
			return $out;
		}
		if ( 'set' !== $setting['setting'] || null === $setting['id'] ) {
			return $out;
		}
		$out['id'] = $setting['id'];
		if ( $failed ) {
			$out['state'] = 'unreadable';
			return $out;
		}
		if ( null === $row || (int) ( $row['ID'] ?? 0 ) !== $setting['id'] ) {
			$out['state'] = 'missing';
			return $out;
		}
		$status        = (string) ( $row['post_status'] ?? '' );
		$out['status'] = in_array( $status, self::STATUSES, true ) ? $status : 'other';
		if ( 'publish' !== $status ) {
			$out['state'] = 'unpublished';
			return $out;
		}
		if ( ! $with_content ) {
			$out['state'] = 'published';
			return $out;
		}
		$bytes = $row['content_bytes'] ?? null;
		if ( ! is_numeric( $bytes ) || (int) $bytes < 0 ) {
			$out['state'] = 'unreadable';
			return $out;
		}
		$out['bytes'] = (int) $bytes;
		if ( $out['bytes'] > self::MAX_PAGE_BYTES ) {
			$out['state'] = 'oversize';
			return $out;
		}
		$content = $row['content'] ?? null;
		if ( ! is_string( $content ) || strlen( $content ) !== $out['bytes'] ) {
			$out['state'] = 'unreadable';
			return $out;
		}
		$out['state']  = 'published';
		$out['sha256'] = hash( 'sha256', $content );
		$out['marker'] = self::detect_marker( $content );
		return $out;
	}

	/**
	 * Parse a saved page-ID option. Positive whole numbers only, as WooCommerce's absint() reads
	 * them; '' and 0 mean not set.
	 *
	 * @param mixed  $raw    Option value.
	 * @param string $absent The sentinel get_option() returned for a missing option.
	 * @return array{setting:string,id:?int}
	 */
	public static function parse_page_setting( $raw, string $absent = self::ABSENT ): array {
		if ( $absent === $raw ) {
			return array( 'setting' => 'absent', 'id' => null );
		}
		if ( '' === $raw || '0' === $raw || 0 === $raw || false === $raw || null === $raw ) {
			return array( 'setting' => 'empty', 'id' => null );
		}
		if ( is_int( $raw ) && $raw > 0 ) {
			return array( 'setting' => 'set', 'id' => $raw );
		}
		if ( is_string( $raw ) && 1 === preg_match( '/^[0-9]{1,18}$/', $raw ) && (int) $raw > 0 ) {
			return array( 'setting' => 'set', 'id' => (int) $raw );
		}
		return array( 'setting' => 'invalid', 'id' => null );
	}

	/**
	 * Parse a yes/no feature option: yes, no, absent or unrecognised. Only the exact strings count.
	 *
	 * @param mixed  $raw    Option value.
	 * @param string $absent The sentinel get_option() returned for a missing option.
	 */
	public static function parse_yes_no( $raw, string $absent = self::ABSENT ): string {
		if ( $absent === $raw ) {
			return 'absent';
		}
		if ( 'yes' === $raw || 'no' === $raw ) {
			return $raw;
		}
		return 'unrecognised';
	}

	/**
	 * A safe WooCommerce version string, or null when missing or malformed.
	 *
	 * @param mixed $raw WC_VERSION.
	 */
	public static function parse_version( $raw ): ?string {
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(?:-[0-9A-Za-z.\-]{1,30})?$/', $raw ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Whether a version is inside the verified range, and whether its route-pattern letter-case
	 * handling is known (only at the exact versions read).
	 *
	 * @param string|null $version Parsed version.
	 * @return array{verified:bool,case_insensitive:?bool}
	 */
	public static function version_support( ?string $version ): array {
		if ( null === $version || 1 !== preg_match( '/^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$/', $version )
			|| version_compare( $version, self::WC_MIN_VERIFIED, '<' ) || version_compare( $version, self::WC_MAX_VERIFIED, '>' ) ) {
			return array( 'verified' => false, 'case_insensitive' => null );
		}
		return array(
			'verified'         => true,
			'case_insensitive' => self::ROUTE_CASE_INSENSITIVE[ $version ] ?? null,
		);
	}

	// ------------------------------------------------------------------ reads.

	/**
	 * Read one page row: ID, status, byte length and, for the checkout page, the content when it
	 * is published and within MAX_PAGE_BYTES. post_password and other columns are never selected.
	 *
	 * @param array{setting:string,id:?int} $setting      Parsed page setting.
	 * @param bool                          $with_content Read content (checkout page only).
	 * @return array{state:string,id:?int,status:?string,bytes:?int,sha256:?string,marker:?string}
	 */
	private static function read_page( array $setting, bool $with_content ): array {
		if ( 'set' !== $setting['setting'] || null === $setting['id'] ) {
			return self::page_from_row( $setting, null, false, $with_content );
		}
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		if ( $with_content ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded read-only row: get_post() would load the whole content and the page password; the card tool caches its result.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT ID, post_status, LENGTH(post_content) AS content_bytes, IF(post_status = %s AND LENGTH(post_content) <= %d, post_content, NULL) AS content FROM %i WHERE ID = %d LIMIT 1',
					'publish',
					self::MAX_PAGE_BYTES,
					$wpdb->posts,
					$setting['id']
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read-only row, ID and status only.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ID, post_status FROM %i WHERE ID = %d LIMIT 1', $wpdb->posts, $setting['id'] ), ARRAY_A );
		}
		$failed = '' !== (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		return self::page_from_row( $setting, is_array( $row ) ? $row : null, $failed, $with_content );
	}

	// ------------------------------------------------------------------ row builders.

	/**
	 * Derived facts shared by the rows.
	 *
	 * @param array<string,mixed> $s Snapshot.
	 * @return array<string,mixed>
	 */
	private static function context( array $s ): array {
		$v       = self::version_support( $s['wc_version'] ?? null );
		$filters = (array) ( $s['filters'] ?? array() );
		$f       = static fn( string $k ): bool => true === ( $filters[ $k ] ?? false );
		$c       = (array) $s['checkout'];

		$type        = 'unknown';
		$page_reason = null;
		switch ( $c['state'] ) {
			case 'not_set':
				$page_reason = 'checkout_page_not_set';
				break;
			case 'invalid_setting':
				$page_reason = 'checkout_page_invalid_setting';
				break;
			case 'missing':
				$page_reason = 'checkout_page_missing';
				break;
			case 'unpublished':
				$page_reason = 'checkout_page_unpublished';
				break;
			case 'oversize':
				$page_reason = 'checkout_page_oversize';
				break;
			case 'published':
				break;
			default:
				$page_reason = 'checkout_page_unreadable';
		}
		if ( null === $page_reason ) {
			$marker = (string) $c['marker'];
			if ( ! $v['verified'] ) {
				$page_reason = 'wc_version_unverified';
			} elseif ( 'block' === $marker ) {
				$type = 'block';
			} elseif ( $f( 'checkout_shortcode_tag' ) ) {
				$page_reason = 'checkout_shortcode_renamed';
			} elseif ( 'classic' === $marker ) {
				$type = 'classic';
			} elseif ( 'mixed' === $marker ) {
				$type        = 'mixed';
				$page_reason = 'checkout_page_mixed';
			} else {
				$page_reason = 'checkout_page_no_marker';
			}
		}

		return array(
			'verified'    => $v['verified'],
			'case'        => $v['case_insensitive'],
			'filters'     => $f,
			'checkout'    => $c,
			'myaccount'   => (array) $s['myaccount'],
			'type'        => $type,
			'page_reason' => $page_reason,
			'rate_limit'  => self::rate_limit( (string) $s['rate_limit'], $v, $f( 'rate_limit_options' ) ),
			'option'      => (string) $s['rate_limit'],
		);
	}

	/**
	 * Rate limit Checkout as a protection: whether its saved value is on, whether its route scope
	 * is known for this version, the state it gives a covered path and why.
	 *
	 * @param string                                   $option         yes, no, absent or unrecognised.
	 * @param array{verified:bool,case_insensitive:?bool} $v          Version support.
	 * @param bool                                     $options_filter A plugin changes Store API rate limits.
	 * @return array{configured:?bool,scope_known:bool,state:string,reason:?string}
	 */
	private static function rate_limit( string $option, array $v, bool $options_filter ): array {
		if ( ! $v['verified'] ) {
			return array( 'configured' => null, 'scope_known' => false, 'state' => 'unknown', 'reason' => 'wc_version_unverified' );
		}
		$scope_known = null !== $v['case_insensitive'] && ! $options_filter;
		$scope_why   = $options_filter ? 'rate_limit_filter_present' : ( null === $v['case_insensitive'] ? 'rate_limit_scope_unverified' : null );
		if ( 'unrecognised' === $option ) {
			return array( 'configured' => null, 'scope_known' => $scope_known, 'state' => 'unknown', 'reason' => 'rate_limit_setting_unrecognised' );
		}
		if ( 'yes' === $option ) {
			return array( 'configured' => true, 'scope_known' => $scope_known, 'state' => 'configured', 'reason' => $scope_why );
		}
		// Saved 'no', or absent with the verified source default 'no'.
		if ( $options_filter ) {
			// A plugin may switch Store API rate limits on for every route: not known to be off.
			return array( 'configured' => false, 'scope_known' => false, 'state' => 'unknown', 'reason' => 'rate_limit_filter_present' );
		}
		return array( 'configured' => false, 'scope_known' => $scope_known, 'state' => 'not_configured', 'reason' => 'rate_limit_checkout_off' );
	}

	/**
	 * The reason a path gets a limitation entry, or null when it needs none.
	 *
	 * @param string              $id    Path ID.
	 * @param string              $state Protection state.
	 * @param array<string,mixed> $ctx   Context.
	 */
	private static function primary_reason( string $id, string $state, array $ctx ): ?string {
		if ( 'not_applicable' === $state ) {
			return null;
		}
		$rl = $ctx['rate_limit'];
		if ( 'configured' === $state ) {
			// Only rate-limited rows can be configured; flag an unknown scope.
			return $rl['scope_known'] ? null : ( $rl['reason'] ?? 'rate_limit_scope_unverified' );
		}
		if ( 'checkout_page' === $id ) {
			if ( null !== $ctx['page_reason'] ) {
				return $ctx['page_reason'];
			}
			return 'block' === $ctx['type'] ? ( $rl['reason'] ?? 'no_readable_protection' ) : 'no_readable_protection';
		}
		if ( ! $ctx['verified'] ) {
			return 'wc_version_unverified';
		}
		if ( 'store_api_checkout' === $id || 'store_api_order_pay' === $id ) {
			return $rl['reason'] ?? 'no_readable_protection';
		}
		return 'no_readable_protection';
	}

	/**
	 * One C8 Path array.
	 *
	 * @param string               $id          Path ID.
	 * @param string               $label       Label.
	 * @param string               $exposure    Exposure.
	 * @param string|null          $type        Checkout type (checkout page only).
	 * @param string               $state       Protection state.
	 * @param list<array>          $protections Protection entries.
	 * @param list<string>         $facts       Facts.
	 * @param list<string>         $limitations Limitations.
	 * @param string               $check_next  Next action.
	 * @return array<string,mixed>
	 */
	private static function row( string $id, string $label, string $exposure, ?string $type, string $state, array $protections, array $facts, array $limitations, string $check_next ): array {
		return array(
			'id'               => $id,
			'label'            => $label,
			'exposure'         => $exposure,
			'checkout_type'    => $type,
			'protection_state' => $state,
			'enforcement'      => 'not_applicable' === $exposure ? 'not_applicable' : 'unverified',
			'protections'      => $protections,
			'facts'            => $facts,
			'limitations'      => $limitations,
			'check_next'       => $check_next,
		);
	}

	/**
	 * The Rate limit Checkout protection entry.
	 *
	 * @param array<string,mixed> $ctx  Context.
	 * @param string              $note Fixed note.
	 * @return array{id:string,configured:?bool,scope_known:bool,note:string}
	 */
	private static function rate_limit_entry( array $ctx, string $note ): array {
		return array(
			'id'          => 'core_checkout_rate_limit',
			'configured'  => $ctx['rate_limit']['configured'],
			'scope_known' => $ctx['rate_limit']['scope_known'],
			'note'        => $note,
		);
	}

	/**
	 * Facts about the saved Rate limit Checkout setting, its source defaults and filters.
	 *
	 * @param array<string,mixed> $ctx Context.
	 * @return list<string>
	 */
	private static function rate_limit_facts( array $ctx ): array {
		$out = array();
		$opt = $ctx['option'];
		if ( ! $ctx['verified'] ) {
			$out[] = 'yes' === $opt
				? __( 'Rate limit Checkout is saved as on, but this WooCommerce version has not been checked.', 'blaze-mcp-toolkit-for-woocommerce' )
				: self::reason( 'wc_version_unverified' );
			return $out;
		}
		if ( 'yes' === $opt ) {
			$out[] = __( 'Rate limit Checkout is on.', 'blaze-mcp-toolkit-for-woocommerce' );
		} elseif ( 'no' === $opt ) {
			$out[] = __( 'Rate limit Checkout is off.', 'blaze-mcp-toolkit-for-woocommerce' );
		} elseif ( 'absent' === $opt ) {
			$out[] = __( 'Rate limit Checkout has never been changed, so it is off, which is WooCommerce\'s default.', 'blaze-mcp-toolkit-for-woocommerce' );
		} else {
			$out[] = self::reason( 'rate_limit_setting_unrecognised' );
		}
		if ( ( $ctx['filters'] )( 'rate_limit_options' ) ) {
			$out[] = self::reason( 'rate_limit_filter_present' );
		} else {
			$out[] = __( 'When it is on, WooCommerce\'s default allows 3 order requests per 60 seconds from each signed-in customer or visitor address.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		$out[] = __( 'Signed-in staff who can edit posts are not limited by it.', 'blaze-mcp-toolkit-for-woocommerce' );
		if ( ( $ctx['filters'] )( 'rate_limit_id' ) ) {
			$out[] = __( 'A plugin or theme changes how WooCommerce tells visitors apart for rate limits.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * Limitations of Rate limit Checkout that hold whatever its saved value.
	 *
	 * @param array<string,mixed> $ctx Context.
	 * @return list<string>
	 */
	private static function rate_limit_limitations( array $ctx ): array {
		$out = array(
			__( 'This check cannot confirm Rate limit Checkout works. Behind a proxy or CDN, WooCommerce may see the proxy\'s address instead of each visitor\'s.', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'A bot check shown on the checkout page does not by itself cover requests sent straight to the Store API.', 'blaze-mcp-toolkit-for-woocommerce' ),
		);
		if ( ! $ctx['verified'] ) {
			return $out;
		}
		$out[] = __( 'In the WooCommerce versions this check verified, checkout requests sent inside a combined Store API batch request fall outside Rate limit Checkout.', 'blaze-mcp-toolkit-for-woocommerce' );
		if ( false === $ctx['case'] ) {
			$out[] = __( 'In this WooCommerce version, a request address written in different letter case can fall outside Rate limit Checkout.', 'blaze-mcp-toolkit-for-woocommerce' );
		} elseif ( null === $ctx['case'] ) {
			$out[] = self::reason( 'rate_limit_scope_unverified' );
		}
		return $out;
	}

	/**
	 * Next action for a rate-limited path.
	 *
	 * @param array<string,mixed> $ctx Context.
	 */
	private static function rate_limit_next( array $ctx ): string {
		if ( ! $ctx['verified'] ) {
			return self::unverified_next();
		}
		switch ( $ctx['rate_limit']['state'] ) {
			case 'not_configured':
				return __( 'Turn on WooCommerce > Settings > Advanced > Features > Rate limit Checkout. It limits order requests sent to the Store API checkout and Store API payments for existing orders. It does not cover the classic checkout form, paying an existing order on the order payment page or saving a payment method. Then check your payment provider\'s fraud and decline dashboard.', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'configured':
				return __( 'Rate limit Checkout is on, but this check cannot confirm it works. Ask your host whether visitor addresses reach WooCommerce correctly behind a proxy or CDN, and check your payment provider\'s fraud and decline dashboard.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return __( 'Check WooCommerce > Settings > Advanced > Features > Rate limit Checkout, ask whoever manages your site whether a plugin, server or CDN rule limits Store API checkout requests, and check your payment provider\'s fraud and decline dashboard.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/** Next action when the WooCommerce version is outside the verified range. */
	private static function unverified_next(): string {
		return __( 'This WooCommerce version has not been checked. Look at WooCommerce > Settings > Advanced > Features > Rate limit Checkout yourself, ask whoever manages your site what covers this path, and check your payment provider\'s fraud and decline dashboard.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * The checkout page row.
	 *
	 * @param array<string,mixed> $ctx Context.
	 * @return array<string,mixed>
	 */
	private static function checkout_page_row( array $ctx ): array {
		$c         = $ctx['checkout'];
		$type      = $ctx['type'];
		$published = in_array( $c['state'], array( 'published', 'oversize' ), true );
		$facts     = array();
		if ( $published ) {
			$facts[] = __( 'The checkout page set in WooCommerce is published.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( 'block' === $type ) {
			$facts[] = __( 'The checkout page content contains the checkout block, which places orders through the Store API.', 'blaze-mcp-toolkit-for-woocommerce' );
		} elseif ( 'classic' === $type ) {
			$facts[] = __( 'The checkout page content contains the classic checkout shortcode, which places orders through WooCommerce\'s classic checkout form.', 'blaze-mcp-toolkit-for-woocommerce' );
			$facts[] = __( 'Rate limit Checkout does not cover the classic checkout form.', 'blaze-mcp-toolkit-for-woocommerce' );
		} elseif ( null !== $ctx['page_reason'] ) {
			$facts[] = self::reason( $ctx['page_reason'] );
		}

		$protections = array();
		$state       = 'unknown';
		if ( 'block' === $type ) {
			$protections[] = self::rate_limit_entry( $ctx, __( 'WooCommerce\'s Rate limit Checkout setting, through the Store API requests the checkout block sends.', 'blaze-mcp-toolkit-for-woocommerce' ) );
			$state         = $ctx['rate_limit']['state'];
			$facts         = array_merge( $facts, self::rate_limit_facts( $ctx ) );
		}

		$limitations = array(
			__( 'This reads the checkout page content only. A theme template or page builder can change what shoppers see.', 'blaze-mcp-toolkit-for-woocommerce' ),
			__( 'Orders can also be placed in the other ways listed here, whatever this page shows.', 'blaze-mcp-toolkit-for-woocommerce' ),
		);
		if ( ( $ctx['filters'] )( 'checkout_page_id' ) ) {
			$limitations[] = __( 'A plugin or theme changes which page WooCommerce treats as the checkout page, so the page read may not be the one shoppers see.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( 'block' === $type ) {
			$limitations = array_merge( $limitations, self::rate_limit_limitations( $ctx ) );
			$next        = self::rate_limit_next( $ctx );
		} elseif ( 'classic' === $type ) {
			$next = __( 'Use a server-side Turnstile, reCAPTCHA or hCaptcha integration that explicitly covers the classic checkout form, and confirm the coverage with its vendor. Rate limit Checkout does not cover this form.', 'blaze-mcp-toolkit-for-woocommerce' );
		} else {
			$next = __( 'Find out which checkout your shoppers use, then make sure a server-side Turnstile, reCAPTCHA or hCaptcha integration explicitly covers it and the Store API checkout, and confirm the coverage with its vendor.', 'blaze-mcp-toolkit-for-woocommerce' );
		}

		return self::row(
			'checkout_page',
			__( 'Checkout page', 'blaze-mcp-toolkit-for-woocommerce' ),
			$published ? 'configured' : 'unknown',
			$type,
			$state,
			$protections,
			$facts,
			$limitations,
			$next
		);
	}

	/**
	 * A Store API row: placing an order, or paying an existing order.
	 *
	 * @param array<string,mixed> $ctx Context.
	 * @param string              $id  store_api_checkout or store_api_order_pay.
	 * @return array<string,mixed>
	 */
	private static function store_api_row( array $ctx, string $id ): array {
		$pay = 'store_api_order_pay' === $id;
		if ( ! $ctx['verified'] ) {
			$facts = array();
		} elseif ( $pay ) {
			$facts = array(
				__( 'WooCommerce includes a Store API route for paying an existing order. It checks the order\'s key and billing email, or that the signed-in customer owns the order. These are not bot checks.', 'blaze-mcp-toolkit-for-woocommerce' ),
				__( 'Rate limit Checkout also applies to this route when it is on.', 'blaze-mcp-toolkit-for-woocommerce' ),
			);
		} else {
			$facts = array(
				__( 'WooCommerce includes the Store API checkout whatever the checkout page uses. The block checkout and some apps place orders through it.', 'blaze-mcp-toolkit-for-woocommerce' ),
			);
		}
		$note = $pay
			? __( 'WooCommerce\'s Rate limit Checkout setting. It also applies to Store API requests to pay an existing order.', 'blaze-mcp-toolkit-for-woocommerce' )
			: __( 'WooCommerce\'s Rate limit Checkout setting. It applies to order requests sent to the Store API checkout.', 'blaze-mcp-toolkit-for-woocommerce' );

		return self::row(
			$id,
			$pay
				? __( 'Paying an existing order through the Store API', 'blaze-mcp-toolkit-for-woocommerce' )
				: __( 'Placing an order through the Store API', 'blaze-mcp-toolkit-for-woocommerce' ),
			$ctx['verified'] ? 'available_in_core' : 'unknown',
			null,
			$ctx['rate_limit']['state'],
			array( self::rate_limit_entry( $ctx, $note ) ),
			array_merge( $facts, self::rate_limit_facts( $ctx ) ),
			self::rate_limit_limitations( $ctx ),
			self::rate_limit_next( $ctx )
		);
	}

	/**
	 * The classic order payment page row.
	 *
	 * @param array<string,mixed> $ctx Context.
	 * @return array<string,mixed>
	 */
	private static function order_pay_row( array $ctx ): array {
		$facts = $ctx['verified']
			? array(
				__( 'WooCommerce includes a page for paying an existing order. It checks a security token and the order\'s key, which are not bot checks.', 'blaze-mcp-toolkit-for-woocommerce' ),
				__( 'Rate limit Checkout does not cover this page.', 'blaze-mcp-toolkit-for-woocommerce' ),
			)
			: array( self::reason( 'wc_version_unverified' ) );
		return self::row(
			'order_pay',
			__( 'Paying an existing order on the order payment page', 'blaze-mcp-toolkit-for-woocommerce' ),
			$ctx['verified'] ? 'available_in_core' : 'unknown',
			null,
			'unknown',
			array(),
			$facts,
			array(
				__( 'A bot check on the checkout page does not establish protection for this page.', 'blaze-mcp-toolkit-for-woocommerce' ),
			),
			$ctx['verified']
				? __( 'Use a server-side Turnstile, reCAPTCHA or hCaptcha integration that explicitly covers paying an existing order, and confirm the coverage with its vendor.', 'blaze-mcp-toolkit-for-woocommerce' )
				: self::unverified_next()
		);
	}

	/**
	 * The saving-a-payment-method row.
	 *
	 * @param array<string,mixed> $ctx Context.
	 * @return array<string,mixed>
	 */
	private static function add_payment_method_row( array $ctx ): array {
		$facts = array();
		if ( $ctx['verified'] ) {
			$facts[] = __( 'WooCommerce includes a form for saving a payment method in My Account.', 'blaze-mcp-toolkit-for-woocommerce' );
		} else {
			$facts[] = self::reason( 'wc_version_unverified' );
		}
		switch ( $ctx['myaccount']['state'] ) {
			case 'published':
				$facts[] = __( 'The My Account page set in WooCommerce is published.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'unpublished':
				$facts[] = __( 'The My Account page set in WooCommerce is not published.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'missing':
				$facts[] = __( 'The My Account page set in WooCommerce no longer exists.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'invalid_setting':
				$facts[] = __( 'The saved My Account page setting is not a valid page.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'not_set':
				$facts[] = __( 'No My Account page is set in WooCommerce.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			default:
				$facts[] = __( 'The My Account page could not be read.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( $ctx['verified'] ) {
			$facts[] = __( 'By default WooCommerce makes each account wait 20 seconds between saved payment methods.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( ( $ctx['filters'] )( 'saved_method_delay' ) ) {
			$facts[] = __( 'A plugin or theme changes this wait.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		if ( $ctx['verified'] ) {
			$facts[] = __( 'Rate limit Checkout does not cover saving a payment method.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return self::row(
			'add_payment_method',
			__( 'Saving a payment method in My Account', 'blaze-mcp-toolkit-for-woocommerce' ),
			$ctx['verified'] ? 'available_in_core' : 'unknown',
			null,
			'unknown',
			array(
				array(
					'id'          => 'core_saved_payment_delay',
					'configured'  => null,
					'scope_known' => $ctx['verified'],
					'note'        => __( 'WooCommerce\'s default wait between saved payment methods for one account. The value in use is not confirmed.', 'blaze-mcp-toolkit-for-woocommerce' ),
				),
			),
			$facts,
			array(
				__( 'This check does not confirm the wait in use. The wait applies to one account at a time, so it does not slow many new accounts.', 'blaze-mcp-toolkit-for-woocommerce' ),
				__( 'A bot check on the checkout page does not establish protection for this form.', 'blaze-mcp-toolkit-for-woocommerce' ),
			),
			$ctx['verified']
				? __( 'Use a server-side Turnstile, reCAPTCHA or hCaptcha integration that explicitly covers saving a payment method, confirm the coverage with its vendor, and review your payment provider\'s saved-card and fraud controls.', 'blaze-mcp-toolkit-for-woocommerce' )
				: self::unverified_next()
		);
	}
}
