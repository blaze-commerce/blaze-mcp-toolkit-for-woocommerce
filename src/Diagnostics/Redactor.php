<?php
/**
 * Strips secrets and personal data from log lines before they reach an AI.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Pure string transform. Ordered rules, one fixed token per class:
 * [secret] [email] [card] [phone] [nonce] [path] [ip] [pii].
 *
 * Order matters: structured secrets first, then personal data, then paths.
 * Redaction is idempotent: tokens never match a later rule.
 */
final class Redactor {

	/** Name fragments that mark a key/value pair as a credential. */
	private const SECRET_NAMES = '[\w-]*(?:secret|passw(?:or)?d|pwd|token|api[_-]?key|apikey|auth_key|auth_salt|logged_in_key|logged_in_salt|nonce_key|nonce_salt|private_key|access_key|consumer_key|signing_key|encryption_key|client_key|user_pass|signature)[\w-]*';

	/** PII keys safe to match as key=value, key: value or [key] => value. */
	private const PII_LOOSE = '(?:billing_|shipping_|customer_)?(?:first_name|last_name|full_name|given_name|family_name|surname|address_1|address_2|address_line_\d|address|street|line1|line2|postcode|postal_code|zip|city|company|phone|ip_address|user_agent|user_login|display_name|nickname|user_nicename|admin_area_\d)';

	/** PII keys too generic for free text: only as a quoted or bracketed key ("name": / [name] =>). */
	private const PII_STRUCTURED = '(?:billing_|shipping_|customer_)?(?:name|state)';

	/** A Cookie / Set-Cookie key, optionally $_SERVER-style (HTTP_COOKIE), quoted or bracketed. */
	private const COOKIE_KEY = '(?:(?:\\\\?["\']|\[)?(?<![\w-])(?:HTTP_)?(?:Set[-_]Cookie|Cookie)(?![\w-])(?:\\\\?["\']\]?|\])?|(?:\\\\?["\']|\[)(?<![\w-])cookies(?![\w-])(?:\\\\?["\']\]?|\]))';

	/** A dumped cookie object: WP_Http_Cookie, WpOrg\Requests\Cookie, Requests_Cookie (print_r, var_dump, var_export). */
	private const COOKIE_OBJECT = '(?:[\w\\\\]*Cookie\s+Object\b|object\(\\\\?[\w\\\\]*Cookie\)|[\w\\\\]*Cookie::__set_state)';

	/** A bare structural token that opens a dumped array or object: "(", "[", "{", "array (", "array(2) {". */
	private const BLOCK_OPENER = '(?:\(|\[|\{|array\s*\(|array\(\d+\)\s*\{|object\([^)]*\)#\d+\s*\(\d+\)\s*\{)';

	/** One balanced bracket group, skipping brackets inside quoted strings (recursive). */
	private const BALANCED = '(?<bal>[\[{(](?:"(?:[^"\\\\]|\\\\.)*+"|\'(?:[^\'\\\\]|\\\\.)*+\'|[^\[\]{}()"\'\r\n]++|(?&bal))*+[\]})])';

	/** Longest input redacted; callers pass one line at a time. */
	private const MAX_BYTES = 4096;

	/** Returned when a regex fails (backtrack/JIT limit). Fails closed and visibly. */
	public const FAILED = '[redaction failed]';

	/**
	 * Redact one log line.
	 *
	 * Callers must pass one line at a time. Input is truncated to 4096 bytes first. If any
	 * regex fails (PCRE error), the result is exactly '[redaction failed]', never the
	 * partly redacted text and never an empty string.
	 *
	 * @param string $line        Raw log line.
	 * @param string $abspath     Filesystem ABSPATH; paths under it become relative.
	 * @param string $content_dir Filesystem WP_CONTENT_DIR; mapped to "wp-content/".
	 * @return string
	 */
	public static function redact( string $line, string $abspath = ABSPATH, string $content_dir = WP_CONTENT_DIR ): string {
		try {
			return self::run( substr( $line, 0, self::MAX_BYTES ), $abspath, $content_dir );
		} catch ( \RuntimeException $e ) {
			return self::FAILED;
		}
	}

	/**
	 * Whether a raw log line opens a multi-line Cookie / Set-Cookie value (an array or object
	 * dumped over several lines by print_r, var_export, var_dump or pretty JSON).
	 *
	 * Returns null when it does not, otherwise the block state for cookie_block_line(): the
	 * indentation of the opening key (the dump formats indent the value's children deeper) and
	 * whether the value's opening bracket was already on this line.
	 *
	 * @param string $line    Raw line.
	 * @param bool   $is_head Whether this is an entry's first line (its key indentation counts as 0).
	 * @return array{indent:int,opened:bool}|null
	 */
	public static function cookie_block_open( string $line, bool $is_head = false ): ?array {
		$m      = array();
		$indent = $is_head ? 0 : strlen( $line ) - strlen( ltrim( $line ) );
		// A cookie object dumped over several lines: "[0] => WP_Http_Cookie Object" then "(".
		if ( 1 === preg_match( '/(?:^|=>|[:=])\s*(' . self::COOKIE_OBJECT . ')(?:\s*\(array\(|#\d+\s*\(\d+\)\s*\{)?\s*$/i', trim( $line ), $m ) ) {
			return array(
				'indent' => $indent,
				'opened' => 1 !== preg_match( '/Object$/i', $m[1] ),
			);
		}
		if ( 1 !== preg_match( '/' . self::COOKIE_KEY . '\s*(?:=>|=(?!>)|:(?!:))\s*(.*)$/i', $line, $m ) ) {
			return null;
		}
		$rest = trim( $m[1] );
		if ( '' === $rest || 1 === preg_match( '/^(?:Array|[\w\\\\]*Cookie\s+Object)$/i', $rest ) ) {
			// The opening bracket (or a scalar value) is on the next line.
			return array(
				'indent' => $indent,
				'opened' => false,
			);
		}
		if ( 1 === preg_match( '/^' . self::BLOCK_OPENER . '$/i', $rest ) ) {
			return array(
				'indent' => $indent,
				'opened' => true,
			);
		}
		// A value that starts a bracket but does not close it on this line (quote-aware): fail closed.
		if ( 1 === preg_match( '/^(?:array\s*|Array\s*)?[\[{(]/i', $rest ) && 1 !== preg_match( '/^(?:array\s*|Array\s*)?' . self::BALANCED . '$/i', $rest ) ) {
			return array(
				'indent' => $indent,
				'opened' => true,
			);
		}
		return null;
	}

	/**
	 * Redact one continuation line inside a multi-line cookie value.
	 *
	 * The block ends by indentation, which print_r, var_export, var_dump and pretty JSON all use:
	 * children sit deeper than the opening key, so the first line indented at or below the key
	 * ends it. Brackets inside values are never counted. Fail closed: every line inside the
	 * block becomes "[secret]" except bare structural lines ("(", ")", "array (", "],", ...).
	 *
	 * @param string                         $line  Raw line.
	 * @param array{indent:int,opened:bool}  $state State from cookie_block_open() or a previous call.
	 * @return array{0:?string,1:?array{indent:int,opened:bool}} The redacted line (null when the block
	 *         ended before this line, which the caller then handles as an ordinary line) and the new state.
	 */
	public static function cookie_block_line( string $line, array $state ): array {
		$trim   = trim( $line );
		$indent = strlen( $line ) - strlen( ltrim( $line ) );
		if ( $indent > $state['indent'] ) {
			$structural = 1 === preg_match( '/^(?:' . self::BLOCK_OPENER . '|[)\]}][,;]?|Array)$/i', $trim );
			return array(
				$structural ? $trim : '[secret]',
				array(
					'indent' => $state['indent'],
					'opened' => true,
				),
			);
		}
		// var_export / var_dump put the value's opener and closer at the key's own indentation.
		if ( ! $state['opened'] && 1 === preg_match( '/^' . self::BLOCK_OPENER . '$/i', $trim ) ) {
			return array(
				$trim,
				array(
					'indent' => $state['indent'],
					'opened' => true,
				),
			);
		}
		if ( $state['opened'] && 1 === preg_match( '/^[)\]}][,;]?$/', $trim ) ) {
			return array( $trim, null );
		}
		return array( null, null );
	}

	/**
	 * Preg replace that throws on a PCRE error instead of returning null.
	 *
	 * @param string|array<int,string> $pattern     Pattern(s).
	 * @param string                   $replacement Replacement.
	 * @param string                   $subject     Subject.
	 * @return string
	 * @throws \RuntimeException On PCRE failure.
	 */
	private static function sub( $pattern, string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		if ( null === $result ) {
			throw new \RuntimeException( 'pcre' );
		}
		return $result;
	}

	/**
	 * Preg replace with callback that throws on a PCRE error.
	 *
	 * @param string   $pattern  Pattern.
	 * @param callable $callback Callback.
	 * @param string   $subject  Subject.
	 * @return string
	 * @throws \RuntimeException On PCRE failure.
	 */
	private static function subcb( string $pattern, callable $callback, string $subject ): string {
		$result = preg_replace_callback( $pattern, $callback, $subject );
		if ( null === $result ) {
			throw new \RuntimeException( 'pcre' );
		}
		return $result;
	}

	/**
	 * Replace the value after a key: handles "=>" (print_r, multi-word to the next " [" / ")" / end),
	 * and ":" / "=" (quoted with escapes, escaped-quote JSON, or a bare run).
	 *
	 * @param string $key   Regex for the key including its closing quote/bracket (no separator).
	 * @param string $token Replacement token.
	 * @param string $text  Input.
	 * @return string
	 */
	private static function replace_values( string $key, string $token, string $text ): string {
		$skip   = '(?![{\[])(?!\[(?:secret|card|nonce|pii)\])';
		$quoted = '(?:\\\\"(?:(?!\\\\").)*(?:\\\\"|$)|"(?:[^"\\\\]|\\\\.)*(?:"|$)|\'(?:[^\'\\\\]|\\\\.)*(?:\'|$))';
		$text   = self::sub( '/(' . $key . '\s*=>\s*+(?:[a-z]+\(\d+\)\s++)?+)' . $skip . '(?:' . $quoted . '|.+?(?=\s+\[|\s*\)|\s*$))/im', '${1}' . $token, $text );
		return self::sub( '/(' . $key . '\s*[:=](?!>)\s*+)' . $skip . '(?:' . $quoted . '|[^\s"\'&,;})\]]+)/im', '${1}' . $token, $text );
	}

	/**
	 * The redaction rules, in order.
	 *
	 * @param string $out         Input (already truncated).
	 * @param string $abspath     ABSPATH.
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @return string
	 * @throws \RuntimeException On PCRE failure.
	 */
	private static function run( string $out, string $abspath, string $content_dir ): string {
		// 1. Private key blocks, PHP constants holding credentials, DB error users, URL credentials.
		$out = self::sub( '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[secret]', $out );
		$out = self::sub(
			'/\bdefine\s*\(\s*([\'"])([A-Za-z0-9_]*(?:KEY|SALT|PASSWORD|PASSWD|SECRET|TOKEN)[A-Za-z0-9_]*|DB_USER|DB_HOST|DB_NAME)\1\s*,\s*(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/i',
			"define('$2', [secret]",
			$out
		);
		$out = self::sub( "/for user '[^']*'@'[^']*'/i", 'for user [secret]', $out );
		$out = self::sub( '#\b([a-z][a-z0-9+.-]*://)[^\s"\'?]*@#i', '${1}[secret]@', $out );

		// 2. Vendor tokens with a known shape.
		$out = self::sub(
			array(
				'/\b[spr]k_(?:live|test)_\w*/i',
				'/\bpk_(?:live|test)_\w*/i',
				'/\bek_(?:live|test)_\w*/i',
				'/\bwhsec_\w*/i',
				'/\bpi_\w+_secret_\w+/',
				'/\b(?:seti|cs)_\w+_secret_\w+/',
				'/\bc[ks]_[a-f0-9]{8,}/',
				'/\bwc_order_\w{6,}/',
				'/\bAKIA[0-9A-Z]{12,}\b/',
				'/\bAIza[\w-]{30,}/',
				'/\bgh[pousr]_\w{20,}/',
				'/\bxox[baprs]-[\w-]+/',
				'/\bA21AA[\w-]{20,}/',
				'/\bsq0[a-z]{3}-[\w-]{20,}/',
				'/\bEAAA[\w-]{20,}/',
				'/\baccess_token\$(?:production|sandbox|development|qa)\$[\w$]+/',
				'/\b(?:live|test)_[A-Za-z0-9]{20,}(?!\w)/',
				'/\bshp(?:at|ss|ca|pa)_[A-Za-z0-9]{16,}/',
				'/\bSK[0-9a-fA-F]{32}\b/',
			),
			'[secret]',
			$out
		);

		// 3. JWTs, auth headers, cookies.
		$out = self::sub( '/\beyJ[\w-]{8,}\.[\w-]{4,}\.[\w-]*/', '[secret]', $out );
		$out = self::sub( '/\b(Authorization|Proxy-Authorization)(\\\\?["\'\]]*\s*(?:=>|[:=](?!>))\s*)\\\\?["\']?(?:(?:Bearer|Basic|Token|Digest|OAuth)\s+)?(?:\[secret\]|[^\s"\',;\\\\})]+)(?:\\\\?["\'])?/i', '${1}${2}[secret]', $out );
		$out = self::sub( '/\bBearer\s+[^\s"\',;]{8,}/', '[secret]', $out );
		$out = self::sub( '/\bBasic\s+(?=[A-Za-z0-9+\/]*[\d+\/=])[A-Za-z0-9+\/]{8,}={0,2}/', '[secret]', $out );
		// Cookie / Set-Cookie (also $_SERVER's HTTP_COOKIE) with a bare, quoted, escaped-quoted or
		// bracketed name ("Cookie":, \"Cookie\":, [HTTP_COOKIE] =>, 'Cookie' =>, ['HTTP_COOKIE'] =,
		// Cookie =). A quoted value goes up to its closing quote, a JSON array or object up to its
		// matching bracket (brackets inside quoted strings skipped), anything else to the end of the
		// line. "Cookie::" (a class reference) is not a key.
		$out = self::sub(
			'/(' . self::COOKIE_KEY . '\s*(?:=>|=(?!>)|:(?!:))\s*+)(?:\\\\"(?:(?!\\\\").)*(?:\\\\"|$)|"(?:[^"\\\\]|\\\\.)*(?:"|$)|\'(?:[^\'\\\\]|\\\\.)*(?:\'|$)|(?!\[secret\])' . self::BALANCED . '|(?!\[secret\])[^\r\n]*)/i',
			'${1}[secret]',
			$out
		);
		// The value of a dumped cookie object (WP_Http_Cookie, Requests' Cookie) is the cookie itself.
		if ( 1 === preg_match( '/' . self::COOKIE_OBJECT . '/i', $out ) ) {
			$out = self::replace_values( '(?:\[\s*["\']?|\\\\?["\'])value(?::\w+)?(?:\\\\?["\'])?\s*\]?', '[secret]', $out );
		}
		$out = self::sub(
			'/\b(?:wordpress_[\w-]*|wp_woocommerce_session_[\w-]*|woocommerce_(?:items_in_cart|cart_hash|recently_viewed)|wp-settings(?:-time)?-\d+|PHPSESSID|JSESSIONID|ASP\.NET_SessionId|connect\.sid|session_?id|sess_?id|[\w-]*_session)=[^;\s"\',]+/i',
			'[secret]',
			$out
		);

		// 4. Nonces, credential-named pairs, stack-frame arguments.
		$out = self::sub( '/\b((?:_wpnonce|_ajax_nonce|security|[\w-]*nonce))(\s*=\s*)[\w-]+/i', '${1}${2}[nonce]', $out );
		$out = self::replace_values( '(?:["\'\[]+\s*pass[12]?\\\\?\s*["\'\]]+|(?<![\w-])pass[12]\b|(?<![\w-])' . self::SECRET_NAMES . '\\\\?["\'\]]*)', '[secret]', $out );
		$out = self::sub( '/(?<=[?&])(key|order_key)(=)[^\s&"\']+/i', '${1}${2}[secret]', $out );
		// Every string argument in a "#N file(line): call(args)" frame (DB credentials, passwords).
		$out = self::subcb(
			'/(#\d+\s+(?:[^\s#][^\r\n]*?\(\d+\)|\[internal function\]): )(.*?)(?=\s#\d+\s|\r?$)/m',
			static function ( array $m ): string {
				return $m[1] . (string) preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'/', "'[secret]'", $m[2] );
			},
			$out
		);
		$out = self::subcb(
			'/\b([\w\\\\:>-]*(?:authenticate|signon|check_password|set_password|password|secret|api_key|token)\w*)\(([^()]+)\)/i',
			static function ( array $m ): string {
				$args = trim( $m[2] );
				return in_array( $args, array( 'Array', 'Object', 'NULL', 'null', '' ), true ) ? $m[0] : $m[1] . '([secret])';
			},
			$out
		);

		// 5. Personal data keys in payload dumps (WooCommerce, Stripe, PayPal, WP users).
		$out = self::replace_values( '(?:["\'\[]+\s*' . self::PII_STRUCTURED . '\\\\?\s*["\'\]]+|["\'\[]?\b' . self::PII_LOOSE . '\b\\\\?["\'\]]*)', '[pii]', $out );

		// 6. Card security codes, then card numbers.
		$out = self::sub( '/\b((?:[a-z]+_)?(?:cvv2?|cvc2?|csc|security_code))(["\']?(?:\s*[:=]\s*|\s+)["\']?)\d{3,4}\b/i', '${1}${2}[card]', $out );
		$out = self::redact_cards( $out );

		// 7. Emails.
		$out = self::sub( '/[A-Za-z0-9._%+-]+(?:@|%40|\\\\u0040)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '[email]', $out );

		// 8. IPs before phones (dotted quads), then phones.
		$out = self::redact_ips( $out );
		$out = self::redact_phones( $out );

		// 9. Paths: content dir to wp-content/, ABSPATH to relative, the rest masked.
		return self::redact_paths( $out, $abspath, $content_dir );
	}

	/**
	 * Card numbers: grouped forms by shape, contiguous runs by Luhn plus a card prefix.
	 * Keeps timestamps, epoch values, versions and order IDs intact.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	private static function redact_cards( string $text ): string {
		return self::subcb(
			'/(?<![\w.\/-])(?:\d{4}([ .-])\d{4}\1\d{4}\1\d{1,7}|\d{4}([ .-])\d{6}\2\d{4,5}|\d{13,19})(?![\w-]|\.\d)/',
			static function ( array $m ): string {
				$digits = preg_replace( '/\D/', '', $m[0] );
				$len    = strlen( (string) $digits );
				if ( $len < 13 || $len > 19 ) {
					return $m[0];
				}
				if ( 1 === preg_match( '/^\d+$/', $m[0] ) ) {
					// Contiguous: require a plausible issuer prefix and a valid Luhn sum.
					if ( 1 !== preg_match( '/^[2-6]/', (string) $digits ) || ! self::luhn( (string) $digits ) ) {
						return $m[0];
					}
				}
				return '[card]';
			},
			$text
		);
	}

	/**
	 * Luhn checksum.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	private static function luhn( string $digits ): bool {
		$sum = 0;
		$alt = false;
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$n = (int) $digits[ $i ];
			if ( $alt ) {
				$n *= 2;
				if ( $n > 9 ) {
					$n -= 9;
				}
			}
			$sum += $n;
			$alt  = ! $alt;
		}
		return 0 === $sum % 10;
	}

	/**
	 * IPv6 (run first so an embedded IPv4 tail goes with it), then IPv4.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	private static function redact_ips( string $text ): string {
		$text = self::subcb(
			'/(?<![\w:.])(?:[0-9a-fA-F]{0,4}:){2,7}(?:[0-9a-fA-F]{1,4}|(?:\d{1,3}\.){3}\d{1,3})?(?![\w:]|\.\d)/',
			static function ( array $m ): string {
				$c      = $m[0];
				$colons = substr_count( $c, ':' );
				$double = str_contains( $c, '::' );
				$ok     = false;
				if ( ! $double ) {
					// Full form only: eight groups. Anything shorter is a clock time.
					$ok = 7 === $colons && 1 === preg_match( '/^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/', $c );
				} elseif ( str_starts_with( $c, '::' ) || $colons >= 3 || 1 === preg_match( '/\d/', $c ) ) {
					$ok = 1 === preg_match( '/^[0-9a-fA-F:.]+$/', $c );
				}
				return $ok ? '[ip]' : $c;
			},
			$text
		);
		return self::sub(
			'/(?<![\w.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?![\w]|\.\d)/',
			'[ip]',
			$text
		);
	}

	/**
	 * Phones: international (+CC ...) and common local shapes. 8-15 digits for international.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	private static function redact_phones( string $text ): string {
		$text = self::subcb(
			'/(?<![\w+])\+\d{1,3}(?:[ .-]?(?:\(\d{1,4}\)|\d{2,4})){2,5}(?![\w])/',
			static function ( array $m ): string {
				$n = strlen( (string) preg_replace( '/\D/', '', $m[0] ) );
				return ( $n >= 8 && $n <= 15 ) ? '[phone]' : $m[0];
			},
			$text
		);
		return self::sub(
			array(
				'/(?<![\w.:\/+-])\(\d{2,4}\)\s?\d{3,4}[ -]\d{3,4}(?![\w-])/',
				'/(?<![\w.:\/+-])0\d{3}[ -]\d{3}[ -]\d{3}(?![\w-])/',
				'/(?<![\w.:\/+-])04\d{8}(?![\w-])/',
				'/(?<![\w.:\/+-])\d{3}[-. ]\d{3}[-. ]\d{4}(?![\w-])/',
			),
			'[phone]',
			$text
		);
	}

	/**
	 * Paths. The content dir becomes "wp-content/", ABSPATH is dropped, and any other
	 * absolute filesystem path becomes [path].
	 *
	 * @param string $text        Input.
	 * @param string $abspath     ABSPATH.
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @return string
	 */
	private static function redact_paths( string $text, string $abspath, string $content_dir ): string {
		$content = rtrim( str_replace( '\\', '/', $content_dir ), '/' );
		if ( strlen( $content ) > 1 ) {
			$text = str_replace( array( $content . '/', str_replace( '/', '\\', $content ) . '\\' ), 'wp-content/', $text );
		}
		$root = rtrim( str_replace( '\\', '/', $abspath ), '/' );
		if ( strlen( $root ) > 1 ) {
			$text = str_replace( array( $root . '/', str_replace( '/', '\\', $root ) . '\\' ), '', $text );
		}

		// Any other host path that still passes through a wp-content directory.
		$text = self::sub( '#(?<![\w:/.])/(?:[^\s/\'"()<>|]+/)*?wp-content/#', 'wp-content/', $text );
		$text = self::subcb(
			'#(?<![\w:/.\\\\])[A-Za-z]:[\\\\/](?:[^\s\\\\/\'"()<>|:*?]+[\\\\/])*?wp-content[\\\\/]([^\s\'"()<>|:*?]*)#',
			static function ( array $m ): string {
				return 'wp-content/' . str_replace( '\\', '/', $m[1] );
			},
			$text
		);

		// Stream-wrapper URLs to absolute files (phar:///usr/local/bin/wp/..., file:///etc/...).
		$text = self::sub( '#\b(phar|file|compress\.zlib|compress\.bzip2|zip|glob)://(?:/|[A-Za-z]:[\\\\/])[^\s\'"()<>|]*#i', '$1://[path]', $text );

		// Remaining absolute paths (Windows, then Unix). Short URL-like paths are left alone.
		$text = self::sub( '#(?<![\w:/.\\\\])[A-Za-z]:\\\\(?:[^\s\\\\/\'"()<>|:*?]+\\\\?)+#', '[path]', $text );
		return self::subcb(
			'#(?<![\w:/.<>\[-])/(?:[\w.@+~-]+/)+[\w.@+~-]*|(?<![\w:/.<>\[-])/(?:home|Users|var|www|srv|opt|usr|etc|tmp|mnt|root|dev|proc|data|nas|private|Volumes)/[\w.@+~-]+#',
			static function ( array $m ): string {
				$first = explode( '/', ltrim( $m[0], '/' ) )[0];
				if ( in_array( $first, array( 'wp-json', 'wp-admin', 'wc-api', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php' ), true ) ) {
					return $m[0];
				}
				$segments = count( array_filter( explode( '/', $m[0] ), 'strlen' ) );
				$has_ext  = 1 === preg_match( '/\.[A-Za-z0-9]{1,5}$/', $m[0] );
				// Two-segment paths are filesystem-like only with a file extension or a known root.
				if ( $segments < 3 && ! $has_ext && ! in_array( $first, array( 'home', 'Users', 'var', 'www', 'srv', 'opt', 'usr', 'etc', 'tmp', 'mnt', 'root', 'dev', 'proc', 'data', 'nas', 'private', 'Volumes' ), true ) ) {
					return $m[0];
				}
				return '[path]';
			},
			$text
		);
	}
}
