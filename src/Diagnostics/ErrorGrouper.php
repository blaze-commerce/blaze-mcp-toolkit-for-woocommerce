<?php
/**
 * Groups redacted PHP and WooCommerce log lines into counted error groups.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Pure transform: lines in, groups out. Each line is redacted (one at a time)
 * before it is normalised, grouped or attributed.
 */
final class ErrorGrouper {

	private const MAX_GROUPS        = 50;
	private const MAX_MESSAGE_CHARS = 300;
	private const MAX_CONT_LINES    = 20;

	/** PHP: [21-Oct-2026 10:42:00 UTC] body. */
	private const PHP_LINE = '/^\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z0-9_\/+:\-]{1,40}))?\] (.*)$/s';

	/** WooCommerce: 2026-10-21T10:42:00+00:00 CRITICAL body (Z suffix tolerated). */
	private const WC_LINE = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)) (EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG) (.*)$/s';

	/** PHP level prefix inside the body. */
	private const PHP_LEVEL = '/^(?:PHP )?(Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated|Strict Standards):\s*/i';

	/**
	 * Group log lines, keeping at most 50 groups (fatals and errors first).
	 *
	 * Totals must not be taken from this list; use group_all() for those.
	 *
	 * @param string[] $lines     Raw log lines, oldest first.
	 * @param int      $since_ts  Ignore entries older than this Unix time.
	 * @param string   $source_id Source id echoed into each group.
	 * @return array<int,array{severity:string,message:string,count:int,first_seen:string,last_seen:string,component:string,source:string}>
	 */
	public static function group( array $lines, int $since_ts, string $source_id ): array {
		return self::limit_groups( self::group_all( $lines, $since_ts, $source_id ), self::MAX_GROUPS );
	}

	/**
	 * Keep at most $limit groups: fatals, then errors, then the rest by count; then order by count.
	 *
	 * @param array<int,array{severity:string,count:int,last_seen:string}> $groups Groups.
	 * @param int                                                          $limit  Most groups kept.
	 * @return array<int,array<string,mixed>>
	 */
	public static function limit_groups( array $groups, int $limit ): array {
		$rank = array(
			'fatal' => 0,
			'error' => 1,
		);
		usort(
			$groups,
			static fn( array $a, array $b ): int => array( $rank[ $a['severity'] ] ?? 2, -$a['count'], $b['last_seen'] ) <=> array( $rank[ $b['severity'] ] ?? 2, -$b['count'], $a['last_seen'] )
		);
		$groups = array_slice( $groups, 0, max( 0, $limit ) );
		usort(
			$groups,
			static fn( array $a, array $b ): int => array( $b['count'], $b['last_seen'] ) <=> array( $a['count'], $a['last_seen'] )
		);
		return $groups;
	}

	/**
	 * Group log lines without any display cap (every parsed entry in the bounded input).
	 *
	 * @param string[] $lines     Raw log lines, oldest first.
	 * @param int      $since_ts  Ignore entries older than this Unix time.
	 * @param string   $source_id Source id echoed into each group.
	 * @return array<int,array{severity:string,message:string,count:int,first_seen:string,last_seen:string,component:string,source:string}>
	 */
	public static function group_all( array $lines, int $since_ts, string $source_id ): array {
		$entries = self::parse( $lines, $since_ts );

		$groups = array();
		foreach ( $entries as $e ) {
			$key = $e['severity'] . '|' . self::normalise( $e['first'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'severity'   => $e['severity'],
					'message'    => self::cap( $e['text'] ),
					'count'      => 0,
					'first_ts'   => $e['ts'],
					'last_ts'    => $e['ts'],
					'component'  => self::component( $e['text'] ),
					'source'     => $source_id,
				);
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['first_ts'] = min( $groups[ $key ]['first_ts'], $e['ts'] );
			$groups[ $key ]['last_ts']  = max( $groups[ $key ]['last_ts'], $e['ts'] );
		}

		$groups = array_values( $groups );
		usort(
			$groups,
			static fn( array $a, array $b ): int => array( $b['count'], $b['last_ts'] ) <=> array( $a['count'], $a['last_ts'] )
		);

		$out = array();
		foreach ( $groups as $g ) {
			$out[] = array(
				'severity'   => $g['severity'],
				'message'    => $g['message'],
				'count'      => $g['count'],
				'first_seen' => gmdate( 'c', $g['first_ts'] ),
				'last_seen'  => gmdate( 'c', $g['last_ts'] ),
				'component'  => $g['component'],
				'source'     => $g['source'],
			);
		}
		return $out;
	}

	/**
	 * Parse lines into entries (timestamp, severity, redacted text).
	 *
	 * @param string[] $lines    Lines.
	 * @param int      $since_ts Minimum timestamp.
	 * @return array<int,array{ts:int,severity:string,first:string,text:string}>
	 */
	private static function parse( array $lines, int $since_ts ): array {
		$entries = array();
		$current = null; // Index into $entries, or null when the last entry was skipped/none.
		$cont    = 0;
		$in_pem  = false;

		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) || '' === trim( $line ) ) {
				continue;
			}
			$head = self::parse_head( $line );
			// xdebug trace lines carry a timestamp but continue the previous entry.
			if ( null !== $head && 1 === preg_match( '/^PHP\s+(?:Stack trace:|\d+\.\s)/', $head['body'] ) ) {
				$head = null;
				$line = (string) preg_replace( self::PHP_LINE, '$3', $line );
			}
			if ( null !== $head ) {
				$current = null;
				$cont    = 0;
				$in_pem  = false;
				if ( null === $head['severity'] || $head['ts'] < $since_ts ) {
					continue;
				}
				$in_pem = self::opens_pem( $head['body'] );
				$body   = Redactor::redact( $head['body'] );
				$body = (string) preg_replace( self::PHP_LEVEL, '', $body );
				$body = trim( $body );
				$entries[] = array(
					'ts'       => $head['ts'],
					'severity' => $head['severity'],
					'first'    => $body,
					'text'     => $body,
				);
				$current = array_key_last( $entries );
				continue;
			}
			// Continuation line: belongs to the previous kept entry, if any.
			if ( $in_pem ) {
				// Inside a multi-line private key: drop everything up to and including the END line.
				if ( str_contains( $line, '-----END' ) ) {
					$in_pem = false;
				}
				continue;
			}
			if ( self::opens_pem( $line ) ) {
				$in_pem = true;
			}
			if ( null === $current || $cont >= self::MAX_CONT_LINES ) {
				continue;
			}
			++$cont;
			$entries[ $current ]['text'] .= ' ' . trim( Redactor::redact( $line ) );
			// Bound memory: component scanning never needs more than a few KB.
			if ( strlen( $entries[ $current ]['text'] ) > 20000 ) {
				$entries[ $current ]['text'] = substr( $entries[ $current ]['text'], 0, 20000 );
			}
		}
		return $entries;
	}

	/**
	 * Whether a line starts a private key block that doesn't end on the same line.
	 *
	 * @param string $line Raw line.
	 */
	private static function opens_pem( string $line ): bool {
		$pos = strpos( $line, '-----BEGIN ' );
		if ( false === $pos || false === stripos( $line, 'PRIVATE KEY-----', $pos ) ) {
			return false;
		}
		return ! str_contains( substr( $line, $pos ), '-----END' );
	}

	/**
	 * Parse the timestamp and severity of a log line.
	 *
	 * @param string $line Raw line.
	 * @return array{ts:int,severity:?string,body:string}|null Null when the line has no timestamp.
	 */
	private static function parse_head( string $line ): ?array {
		if ( 1 === preg_match( self::PHP_LINE, $line, $m ) ) {
			$tz = new \DateTimeZone( 'UTC' );
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				try {
					$tz = new \DateTimeZone( $m[2] );
				} catch ( \Exception $e ) {
					$tz = new \DateTimeZone( 'UTC' );
				}
			}
			$dt = \DateTimeImmutable::createFromFormat( 'j-M-Y H:i:s', $m[1], $tz );
			if ( false === $dt ) {
				return null;
			}
			return array(
				'ts'       => $dt->getTimestamp(),
				'severity' => self::php_severity( $m[3] ),
				'body'     => $m[3],
			);
		}
		if ( 1 === preg_match( self::WC_LINE, $line, $m ) ) {
			$ts = strtotime( $m[1] );
			if ( false === $ts ) {
				return null;
			}
			$map = array(
				'EMERGENCY' => 'fatal',
				'ALERT'     => 'fatal',
				'CRITICAL'  => 'fatal',
				'ERROR'     => 'error',
				'WARNING'   => 'warning',
				'NOTICE'    => 'notice',
			);
			return array(
				'ts'       => $ts,
				'severity' => $map[ $m[2] ] ?? null, // INFO and DEBUG are skipped.
				'body'     => $m[3],
			);
		}
		return null;
	}

	/**
	 * Severity of a PHP log body.
	 *
	 * @param string $body Body after the timestamp.
	 */
	private static function php_severity( string $body ): string {
		if ( 1 !== preg_match( self::PHP_LEVEL, $body, $m ) ) {
			return 'error';
		}
		$level = strtolower( $m[1] );
		if ( str_contains( $level, 'fatal' ) || 'parse error' === $level ) {
			return 'fatal';
		}
		$map = array(
			'warning'          => 'warning',
			'notice'           => 'notice',
			'deprecated'       => 'deprecated',
			'strict standards' => 'notice',
		);
		return $map[ $level ] ?? 'error';
	}

	/**
	 * Normalise a message for grouping: quoted values, hex ids and numbers collapse.
	 *
	 * @param string $message Redacted first-line message.
	 */
	private static function normalise( string $message ): string {
		$n = $message;
		$n = preg_replace( '/(?<!\w)"[^"]*"(?!\w)|(?<!\w)\'[^\']*\'(?!\w)/', '?', $n ) ?? $n;
		$n = preg_replace( '/\b0x[0-9a-f]+\b|(?<![\w\/.-])[0-9a-f]{8,}(?![\w\/.-])/i', '?', $n ) ?? $n;
		$n = preg_replace( '/(?<=:)\d+|\bon line \d+|(?<![\w\/.-])\d+(?![\w\/-])(?!\.\w)/', 'N', $n ) ?? $n;
		return substr( $n, 0, 500 );
	}

	/**
	 * Truncate to 300 characters.
	 *
	 * @param string $text Text.
	 */
	private static function cap( string $text ): string {
		return mb_substr( $text, 0, self::MAX_MESSAGE_CHARS, 'UTF-8' );
	}

	/**
	 * Attribute an entry to a component from redacted, relative paths.
	 *
	 * Plugin, theme and mu-plugin paths win over core (earliest in the text);
	 * core frames are generic and appear in nearly every trace.
	 *
	 * @param string $text Redacted entry text.
	 */
	private static function component( string $text ): string {
		if ( 1 === preg_match( '#wp-content/(plugins|themes|mu-plugins)/([^/\s:()\'"]+)#', $text, $m ) ) {
			if ( 'plugins' === $m[1] ) {
				return 'plugin:' . $m[2];
			}
			if ( 'themes' === $m[1] ) {
				return 'theme:' . $m[2];
			}
			return 'mu-plugin:' . $m[2];
		}
		if ( 1 === preg_match( '#(?:^|[\s(\'"/])wp-(?:includes|admin)/#', $text ) ) {
			return 'core';
		}
		return 'unknown';
	}
}
