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
	private const SECRET_NAMES = '[\w-]*(?:secret|passw(?:or)?d|pwd|token|api[_-]?key|apikey|auth_key|auth_salt|logged_in_key|logged_in_salt|nonce_key|nonce_salt|private_key|access_key|consumer_key|signing_key|encryption_key|client_key)[\w-]*';

	/** Key/value value: quoted string or bare run. Never an existing token. */
	private const VALUE = '(?!\[(?:secret|card|nonce|pii)\])(?:"[^"]*"|\'[^\']*\'|[^\s"\'&,;})\]]+)';

	/**
	 * Redact one log line (or a block of lines).
	 *
	 * @param string $line        Raw log text.
	 * @param string $abspath     Filesystem ABSPATH; paths under it become relative.
	 * @param string $content_dir Filesystem WP_CONTENT_DIR; mapped to "wp-content/".
	 * @return string
	 */
	public static function redact( string $line, string $abspath = ABSPATH, string $content_dir = WP_CONTENT_DIR ): string {
		$out = $line;

		// 1. Private key blocks, PHP constants holding credentials, DB error users.
		$out = (string) preg_replace( '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[secret]', $out );
		$out = (string) preg_replace(
			'/\bdefine\s*\(\s*([\'"])([A-Za-z0-9_]*(?:KEY|SALT|PASSWORD|PASSWD|SECRET|TOKEN)[A-Za-z0-9_]*|DB_USER|DB_HOST|DB_NAME)\1\s*,\s*(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/i',
			"define('$2', [secret]",
			$out
		);
		$out = (string) preg_replace( "/for user '[^']*'@'[^']*'/i", 'for user [secret]', $out );

		// 2. Vendor tokens with a known shape.
		$out = (string) preg_replace(
			array(
				'/\b[spr]k_(?:live|test)_\w*/i',
				'/\bpk_(?:live|test)_\w*/i',
				'/\bek_(?:live|test)_\w*/i',
				'/\bwhsec_\w*/i',
				'/\bpi_\w+_secret_\w+/',
				'/\b(?:seti|cs)_\w+_secret_\w+/',
				'/\b[cs]k_[a-f0-9]{40}\b/',
				'/\bwc_order_\w{6,}/',
				'/\bAKIA[0-9A-Z]{12,}\b/',
				'/\bAIza[\w-]{30,}/',
				'/\bgh[pousr]_\w{20,}/',
				'/\bxox[baprs]-[\w-]+/',
				'/\bA21AA[\w-]{20,}/',
				'/\bsq0[a-z]{3}-[\w-]{20,}/',
			),
			'[secret]',
			$out
		);

		// 3. JWTs, auth headers, cookies.
		$out = (string) preg_replace( '/\beyJ[\w-]{8,}\.[\w-]{4,}\.[\w-]*/', '[secret]', $out );
		$out = (string) preg_replace( '/\b(Authorization|Proxy-Authorization)(\s*[:=]\s*)(?:(?:Bearer|Basic|Token|Digest|OAuth)\s+)?[^\s"\',;]+/i', '$1$2[secret]', $out );
		$out = (string) preg_replace( '/\bBearer\s+[^\s"\',;]{8,}/', '[secret]', $out );
		$out = (string) preg_replace( '/\bBasic\s+(?=[A-Za-z0-9+\/]*[\d+\/=])[A-Za-z0-9+\/]{8,}={0,2}/', '[secret]', $out );
		$out = (string) preg_replace( '/\b(Set-Cookie|Cookie)(\s*:\s*)[^\r\n]*/i', '$1$2[secret]', $out );
		$out = (string) preg_replace(
			'/\b(?:wordpress_[\w-]*|wp_woocommerce_session_[\w-]*|woocommerce_(?:items_in_cart|cart_hash|recently_viewed)|wp-settings(?:-time)?-\d+)=[^;\s"\',]+/',
			'[secret]',
			$out
		);

		// 4. Nonces and credential-named key/value pairs.
		$out = (string) preg_replace( '/\b((?:_wpnonce|_ajax_nonce|security|[\w-]*nonce))(\s*=\s*)[\w-]+/i', '$1$2[nonce]', $out );
		$out = (string) preg_replace(
			'/(' . self::SECRET_NAMES . ')(["\'\]]*\s*(?:=>|[:=](?!>))\s*)' . self::VALUE . '/i',
			'$1$2[secret]',
			$out
		);
		$out = (string) preg_replace( '/(?<=[?&])(key|order_key)(=)[^\s&"\']+/i', '$1$2[secret]', $out );
		$out = (string) preg_replace_callback(
			'/\b([\w\\\\:>-]*(?:authenticate|signon|check_password|set_password|password|secret|api_key|token)\w*)\(([^()]+)\)/i',
			static function ( array $m ): string {
				$args = trim( $m[2] );
				return in_array( $args, array( 'Array', 'Object', 'NULL', 'null', '' ), true ) ? $m[0] : $m[1] . '([secret])';
			},
			$out
		);

		// 5. Personal data keys in payload dumps.
		$out = (string) preg_replace(
			'/(["\']?\b(?:billing_|shipping_|customer_)?(?:first_name|last_name|full_name|address_1|address_2|address|street|postcode|zip|company|phone|ip_address|user_agent)["\'\]]*\s*(?:=>|[:=](?!>))\s*)(?!\[)(?:"[^"]*"|\'[^\']*\'|[^\s,;}\]]+)/i',
			'$1[pii]',
			$out
		);

		// 6. Card security codes, then card numbers.
		$out = (string) preg_replace( '/\b(cvv2?|cvc2?|csc|security_code)(["\']?\s*[:=]\s*["\']?)\d{3,4}\b/i', '$1$2[card]', $out );
		$out = self::redact_cards( $out );

		// 7. Emails.
		$out = (string) preg_replace( '/[A-Za-z0-9._%+-]+(?:@|%40|\\\\u0040)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '[email]', $out );

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
		return (string) preg_replace_callback(
			'/(?<![\w.:\/-])(?:\d{4}([ -])\d{4}\1\d{4}\1\d{1,7}|\d{4}([ -])\d{6}\2\d{4,5}|\d{13,19})(?![\w-]|\.\d)/',
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
		$text = (string) preg_replace_callback(
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
		return (string) preg_replace(
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
		$text = (string) preg_replace_callback(
			'/(?<![\w+])\+\d{1,3}(?:[ .-]?(?:\(\d{1,4}\)|\d{2,4})){2,5}(?![\w])/',
			static function ( array $m ): string {
				$n = strlen( (string) preg_replace( '/\D/', '', $m[0] ) );
				return ( $n >= 8 && $n <= 15 ) ? '[phone]' : $m[0];
			},
			$text
		);
		return (string) preg_replace(
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
		$text = (string) preg_replace( '#(?<![\w:/.])/(?:[^\s/\'"()<>|]+/)*?wp-content/#', 'wp-content/', $text );
		$text = (string) preg_replace_callback(
			'#(?<![\w:/.\\\\])[A-Za-z]:[\\\\/](?:[^\s\\\\/\'"()<>|:*?]+[\\\\/])*?wp-content[\\\\/]([^\s\'"()<>|:*?]*)#',
			static function ( array $m ): string {
				return 'wp-content/' . str_replace( '\\', '/', $m[1] );
			},
			$text
		);

		// Remaining absolute paths (Windows, then Unix). Short URL-like paths are left alone.
		$text = (string) preg_replace( '#(?<![\w:/.\\\\])[A-Za-z]:\\\\(?:[^\s\\\\/\'"()<>|:*?]+\\\\?)+#', '[path]', $text );
		return (string) preg_replace_callback(
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
