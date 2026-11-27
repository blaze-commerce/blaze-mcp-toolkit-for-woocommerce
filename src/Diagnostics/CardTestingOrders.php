<?php
/**
 * Card-testing order accumulator: folds compact order rows into bounded, aggregate-only counts.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Separate from payment health's fold (contracts C3/C4, 1.3): PaymentOrders::read_card_testing()
 * scans the authoritative store once and passes each compact row here. Nothing here touches the
 * payment-health aggregates, and nothing here runs SQL.
 *
 * Every cohort uses the order's creation time. Classes per row are mutually exclusive:
 * failed_unpaid, accepted, cancelled_unpaid, pending_unpaid, checkout_draft, other_status. A row
 * creates a scope only after its dates pass validation (positive evidence only); invalid rows are
 * tallied separately and make their scope partial.
 *
 * Privacy reduction (C4): the saved IP of a failed order in the current window is validated as one
 * literal address, reduced to its /24 (IPv4) or /64 (IPv6) network, keyed with a random HMAC key
 * that exists only in this object, and replaced by a per-call sequence number. The raw IP, the
 * prefix, the digest and the key are never returned, stored, logged or compared across calls.
 * Only the source type of order attribution is classified (present, unknown or unsupported); no
 * attribution value is kept.
 */
final class CardTestingOrders {

	/** History length (C3/C5). */
	public const HISTORY_DAYS = 28;

	/** Bucket width, anchored to the captured now (C5). */
	public const BUCKET_SECONDS = 900;

	/** C4 work bounds owned by the accumulator (the scan bounds live in PaymentOrders). */
	public const MAX_METHODS              = 1000;
	public const MAX_NETWORKS             = 5000;
	public const MAX_BUCKET_CELLS         = 50000;
	public const MAX_NETWORK_BUCKET_CELLS = 50000;

	/** Example candidates kept per scope; the response shows at most this many in total. */
	public const MAX_EXAMPLES = 5;

	/** Input scalar bounds (bytes): longer values are flagged, never truncated into valid ones. */
	public const IP_MAX_BYTES          = 100;
	public const ATTRIBUTION_MAX_BYTES = 64;

	/** Legacy customer IP meta key (HPOS reads the wc_orders.ip_address column). */
	public const IP_META_KEY = '_customer_ip_address';

	/** Default attribution source-type meta key (default prefix only; overrides are unassessed). */
	public const ATTRIBUTION_KEY = '_wc_order_attribution_source_type';

	/** Source types that mean a marketing source was recorded. */
	public const PRESENT_SOURCE_TYPES = array( 'utm', 'organic', 'referral', 'typein', 'mobile_app', 'admin', 'pos' );

	/** Source types that mean no source was recorded. */
	public const UNKNOWN_SOURCE_TYPES = array( '', '(none)', 'unknown' );

	/**
	 * WooCommerce versions whose IP and attribution storage was source-verified for this reader
	 * (tests/fixtures/card-testing-contracts.json): from the 10.9.0 floor up to, not including, 11.4.0.
	 */
	public const SUPPORTED_WC_MIN   = '10.9.0';
	public const SUPPORTED_WC_BELOW = '11.4.0';

	/** Mutually exclusive outcome classes (C3). */
	public const CLASSES = array( 'failed_unpaid', 'accepted', 'cancelled_unpaid', 'pending_unpaid', 'checkout_draft', 'other_status' );

	/** Overlapping origin context dimensions (C8 Counts). */
	public const ORIGINS = array( 'checkout_origin', 'store_api_origin', 'missing_origin', 'other_origin' );

	/** Statuses that count as accepted without a paid date. On-hold is accepted, never "paid". */
	private const TAKEN_STATUSES = array( 'processing', 'completed', 'on-hold' );

	/** Every reason code this accumulator emits (catalogue: tests/fixtures/card-testing-contracts.json). */
	public const REASON_CODES = array(
		'unsupported_source_version',
		'ip_storage_unavailable',
		'attribution_storage_unavailable',
		'attribution_feature_disabled',
		'attribution_override_present',
		'method_cap',
		'bucket_cell_cap',
		'network_cap',
		'network_bucket_cell_cap',
		'invalid_timestamps',
		'draft_paid_anomaly',
	);

	/** Captured now. */
	private int $now;

	/** Current window in hours. */
	private int $since;

	/** History start. */
	private int $h_start;

	/** Current window start. */
	private int $w_start;

	/**
	 * Signal support for this call.
	 *
	 * @var array<string,array{supported:bool,reason:?string}>
	 */
	private array $signals;

	/**
	 * Bounds for this call.
	 *
	 * @var array{methods:int,networks:int,bucket_cells:int,network_cells:int}
	 */
	private array $limits;

	/** Random per-call HMAC key; never leaves this object. */
	private string $key;

	/**
	 * HMAC digest => per-call sequence number.
	 *
	 * @var array<string,int>
	 */
	private array $net_ids = array();

	/**
	 * Scopes: origin scope => method ID => aggregate.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $scopes = array(
		'verified_checkout' => array(),
		'missing_origin'    => array(),
	);

	/**
	 * Distinct method IDs tracked (any origin).
	 *
	 * @var array<string,bool>
	 */
	private array $method_index = array();

	/**
	 * Window orders per tracked method (any origin), for the test-mode total.
	 *
	 * @var array<string,int>
	 */
	private array $method_orders = array();

	/**
	 * Invalid rows by origin scope and method (separate bounded map, not scopes).
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $invalid = array(
		'verified_checkout' => array(),
		'missing_origin'    => array(),
	);

	/**
	 * Window totals over every valid retained shop order (any origin or method).
	 *
	 * @var array<string,int>
	 */
	private array $totals;

	/**
	 * Truncation and anomaly flags.
	 *
	 * @var array<string,mixed>
	 */
	private array $flags = array(
		'methods_truncated' => false,
		'methods_omitted'   => 0,
		'invalid_overflow'  => false,
		'bucket_capped'     => false,
		'network_capped'    => false,
		'net_cells_capped'  => false,
		'draft_anomalies'   => 0,
	);

	/** Bucket cells allocated. */
	private int $cells = 0;

	/** Network bucket cells allocated. */
	private int $net_cells = 0;

	/**
	 * Accumulator for one uncached call.
	 *
	 * @param int                                                       $now         Captured UTC now.
	 * @param int                                                       $since_hours Current window, 1-168.
	 * @param array<string,array{supported:bool,reason:?string}>        $signals     signal_support() result.
	 * @param array<string,int>                                         $limits      methods, networks, bucket_cells, network_cells.
	 */
	public function __construct( int $now, int $since_hours, array $signals, array $limits = array() ) {
		$this->now     = $now;
		$this->since   = max( 1, min( 168, $since_hours ) );
		$this->h_start = $now - self::HISTORY_DAYS * 86400;
		$this->w_start = $now - $this->since * 3600;
		$this->signals = array(
			'ip'          => self::signal( $signals['ip'] ?? null ),
			'attribution' => self::signal( $signals['attribution'] ?? null ),
		);
		$this->limits  = array(
			'methods'       => self::positive( $limits['methods'] ?? null, self::MAX_METHODS ),
			'networks'      => self::positive( $limits['networks'] ?? null, self::MAX_NETWORKS ),
			'bucket_cells'  => self::positive( $limits['bucket_cells'] ?? null, self::MAX_BUCKET_CELLS ),
			'network_cells' => self::positive( $limits['network_cells'] ?? null, self::MAX_NETWORK_BUCKET_CELLS ),
		);
		$this->key     = random_bytes( 32 );
		$this->totals  = array_fill_keys( array_merge( self::CLASSES, self::ORIGINS, array( 'unassigned_method', 'invalid_rows' ) ), 0 );
	}

	/**
	 * A positive bound or its default.
	 *
	 * @param mixed $value    Candidate.
	 * @param int   $fallback Default.
	 */
	private static function positive( $value, int $fallback ): int {
		return is_int( $value ) && $value > 0 ? $value : $fallback;
	}

	/**
	 * Normalised signal entry.
	 *
	 * @param mixed $raw Raw entry.
	 * @return array{supported:bool,reason:?string}
	 */
	private static function signal( $raw ): array {
		$supported = is_array( $raw ) && true === ( $raw['supported'] ?? null );
		$reason    = is_array( $raw ) && is_string( $raw['reason'] ?? null ) ? $raw['reason'] : null;
		return array(
			'supported' => $supported,
			'reason'    => $supported ? null : ( $reason ?? 'unsupported_source_version' ),
		);
	}

	/**
	 * Whether this WooCommerce version's IP and attribution storage is source-verified.
	 *
	 * @param string|null $version WooCommerce version string.
	 */
	public static function version_supported( ?string $version ): bool {
		if ( null === $version || 1 !== preg_match( '/^(\d{1,4})\.(\d{1,4})\.(\d{1,4})(?![0-9])/', $version, $m ) ) {
			return false;
		}
		$v = $m[1] . '.' . $m[2] . '.' . $m[3];
		return version_compare( $v, self::SUPPORTED_WC_MIN, '>=' ) && version_compare( $v, self::SUPPORTED_WC_BELOW, '<' );
	}

	/**
	 * Whether the card-only signals can be read here (C4). Storage facts come from the reader's
	 * own existence checks. Attribution also needs the core feature on (absent option: the
	 * source-verified default, on) and no prefix or field-list filter, which would move or drop
	 * the source type and so manufacture missing attribution.
	 *
	 * @param array{ip:bool,meta:bool} $storage IP storage and attribution meta storage present.
	 * @return array{wc_version:?string,ip:array{supported:bool,reason:?string},attribution:array{supported:bool,reason:?string}}
	 */
	public static function signal_support( array $storage ): array {
		$version  = defined( 'WC_VERSION' ) && is_string( constant( 'WC_VERSION' ) ) ? (string) constant( 'WC_VERSION' ) : null;
		$verified = self::version_supported( $version );

		$ip = array(
			'supported' => false,
			'reason'    => 'unsupported_source_version',
		);
		if ( $verified ) {
			$ip = true === ( $storage['ip'] ?? false ) ? array(
				'supported' => true,
				'reason'    => null,
			) : array(
				'supported' => false,
				'reason'    => 'ip_storage_unavailable',
			);
		}

		$attr = array(
			'supported' => false,
			'reason'    => 'unsupported_source_version',
		);
		if ( $verified ) {
			$feature  = function_exists( 'get_option' ) ? get_option( 'woocommerce_feature_order_attribution_enabled', null ) : null;
			$override = function_exists( 'has_filter' ) && ( false !== has_filter( 'wc_order_attribution_tracking_field_prefix' ) || false !== has_filter( 'wc_order_attribution_tracking_fields' ) );
			if ( true !== ( $storage['meta'] ?? false ) ) {
				$attr['reason'] = 'attribution_storage_unavailable';
			} elseif ( null !== $feature && 'yes' !== $feature ) {
				$attr['reason'] = 'attribution_feature_disabled';
			} elseif ( $override ) {
				$attr['reason'] = 'attribution_override_present';
			} else {
				$attr = array(
					'supported' => true,
					'reason'    => null,
				);
			}
		}

		return array(
			'wc_version'  => $verified ? $version : null,
			'ip'          => $ip,
			'attribution' => $attr,
		);
	}

	/**
	 * An unavailable read: no counts, no scopes, never an exact empty result.
	 *
	 * @param int                 $now         Captured now.
	 * @param int                 $since_hours Window.
	 * @param string|null         $authority   hpos|legacy|null.
	 * @param string              $reason      Reason code.
	 * @param array<string,mixed> $signals     signal_support() result.
	 * @return array<string,mixed>
	 */
	public static function unavailable( int $now, int $since_hours, ?string $authority, string $reason, array $signals ): array {
		$since = max( 1, min( 168, $since_hours ) );
		return array(
			'ok'                => false,
			'authority'         => $authority,
			'reason'            => $reason,
			'coverage'          => 'unavailable',
			'scan_complete'     => false,
			'now'               => $now,
			'since_hours'       => $since,
			'history_start'     => $now - self::HISTORY_DAYS * 86400,
			'window_start'      => $now - $since * 3600,
			'signals'           => array(
				'ip'          => self::signal( $signals['ip'] ?? null ),
				'attribution' => self::signal( $signals['attribution'] ?? null ),
			),
			'totals'            => null,
			'method_orders'     => array(),
			'method_ids'        => array(),
			'methods_truncated' => false,
			'methods_omitted'   => 0,
			'scopes'            => array(),
			'invalid_only'      => array(),
			'invalid_overflow'  => false,
			'caps'              => array(
				'bucket_cells'  => false,
				'network_ids'   => false,
				'network_cells' => false,
			),
			'networks_seen'     => null,
			'draft_anomalies'   => 0,
			'rows_read'         => 0,
			'usage'             => array(
				'methods'       => 0,
				'networks'      => 0,
				'bucket_cells'  => 0,
				'network_cells' => 0,
			),
			'work'              => array(
				'candidates' => 0,
				'meta_rows'  => 0,
				'budget'     => false,
			),
		);
	}

	/**
	 * Fold one compact row. Expected keys: id, method, status, created, created_valid, paid,
	 * paid_valid, via, ip, attr. The caller releases the row after this returns.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	public function fold( array $row ): void {
		$status = (string) ( $row['status'] ?? '' );
		if ( str_starts_with( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}
		if ( in_array( $status, array( 'trash', 'draft', 'auto-draft' ), true ) ) {
			return;
		}
		$via     = $row['via'] ?? null;
		$origin  = null === $via || '' === $via ? 'missing' : ( 'checkout' === $via ? 'checkout' : ( 'store-api' === $via ? 'store_api' : 'other' ) );
		$method  = PaymentRules::safe_method_id( (string) ( $row['method'] ?? '' ) );
		$created = is_int( $row['created'] ?? null ) ? $row['created'] : null;
		$paid    = is_int( $row['paid'] ?? null ) ? $row['paid'] : null;
		$draft   = 'checkout-draft' === $status;

		$bad_created = true !== ( $row['created_valid'] ?? false ) || null === $created || $created >= $this->now;
		$bad_paid    = true !== ( $row['paid_valid'] ?? false ) || ( null !== $paid && ( $paid <= 0 || $paid >= $this->now ) );
		if ( $bad_created || ( $bad_paid && ! $draft ) ) {
			$this->invalid_row( $origin, $method );
			return;
		}
		if ( $created < $this->h_start ) {
			return; // Outside the history; the SQL predicate already excludes it.
		}

		$class = $draft ? 'checkout_draft' : self::classify( $status, $paid );
		$in_w  = $created >= $this->w_start;
		if ( $in_w ) {
			++$this->totals[ $class ];
			++$this->totals[ $origin . '_origin' ];
			if ( '' === $method ) {
				++$this->totals['unassigned_method'];
			}
		}
		$anomaly = $draft && ( $bad_paid || null !== $paid );
		if ( $anomaly ) {
			++$this->flags['draft_anomalies'];
		}
		if ( '' === $method ) {
			return; // Unassigned context only, never a synthetic gateway.
		}
		if ( ! $this->track_method( $method ) ) {
			return;
		}
		if ( $in_w ) {
			$this->method_orders[ $method ] = ( $this->method_orders[ $method ] ?? 0 ) + 1;
		}
		if ( 'other' === $origin ) {
			return; // Known other origins are contextual counts only.
		}

		$o = 'missing' === $origin ? 'missing_origin' : 'verified_checkout';
		if ( ! isset( $this->scopes[ $o ][ $method ] ) ) {
			$this->scopes[ $o ][ $method ] = self::new_scope();
		}
		$s = &$this->scopes[ $o ][ $method ];
		if ( $anomaly ) {
			++$s['anomalies'];
		}
		$attempt = 'failed_unpaid' === $class || 'accepted' === $class;
		$failed  = 'failed_unpaid' === $class;

		if ( $in_w ) {
			++$s['w'][ $class ];
			++$s['w'][ $origin . '_origin' ];
			if ( ! $attempt ) {
				unset( $s );
				return;
			}
			$bucket = intdiv( $this->now - 1 - $created, self::BUCKET_SECONDS );
			if ( ! isset( $s['wb'][ $bucket ] ) ) {
				if ( ! $this->allocate_cell() ) {
					$s['series_complete'] = false;
					unset( $s );
					return;
				}
				$s['wb'][ $bucket ] = self::new_cell();
			}
			$cell = &$s['wb'][ $bucket ];
			if ( ! $failed ) {
				++$cell['a'];
				unset( $cell, $s );
				return;
			}
			++$cell['f'];
			$cell['first'] = null === $cell['first'] ? $created : min( $cell['first'], $created );
			$cell['last']  = null === $cell['last'] ? $created : max( $cell['last'], $created );
			$this->example( $s, (int) ( $row['id'] ?? 0 ), $created );
			if ( $this->signals['attribution']['supported'] ) {
				++$cell[ self::source_class( $row['attr'] ?? null ) ];
			}
			if ( $this->signals['ip']['supported'] ) {
				list( $ip_class, $prefix ) = self::network_prefix( $row['ip'] ?? null );
				++$cell[ 'ip_' . $ip_class ];
				if ( null !== $prefix ) {
					$this->count_network( $s, $bucket, $prefix );
				}
				unset( $prefix );
			}
			unset( $cell, $s );
			return;
		}

		// Baseline: [history start, window start).
		if ( ! $attempt ) {
			unset( $s );
			return;
		}
		++$s['b']['attempts'];
		$s['b']['first'] = null === $s['b']['first'] ? $created : min( $s['b']['first'], $created );
		$s['b']['last']  = null === $s['b']['last'] ? $created : max( $s['b']['last'], $created );
		if ( $this->signals['attribution']['supported'] ) {
			++$s['b'][ self::source_class( $row['attr'] ?? null ) ];
		}
		if ( $failed ) {
			++$s['b']['failed'];
			$hour = intdiv( $this->now - 1 - $created, 3600 );
			if ( ! isset( $s['bh'][ $hour ] ) ) {
				if ( ! $this->allocate_cell() ) {
					$s['series_complete'] = false;
					unset( $s );
					return;
				}
				$s['bh'][ $hour ] = 0;
			}
			++$s['bh'][ $hour ];
		}
		unset( $s );
	}

	/**
	 * Outcome class of a valid, non-draft row (C3): a valid paid date wins over a later failed or
	 * cancelled status.
	 *
	 * @param string   $status Status without the wc- prefix.
	 * @param int|null $paid   Valid paid timestamp or null.
	 */
	public static function classify( string $status, ?int $paid ): string {
		if ( null !== $paid || in_array( $status, self::TAKEN_STATUSES, true ) ) {
			return 'accepted';
		}
		switch ( $status ) {
			case 'failed':
				return 'failed_unpaid';
			case 'cancelled':
				return 'cancelled_unpaid';
			case 'pending':
				return 'pending_unpaid';
		}
		return 'other_status';
	}

	/**
	 * Attribution class of a bounded raw source type: present, unknown or unsupported.
	 *
	 * @param mixed $raw Raw value (null when no meta row).
	 */
	public static function source_class( $raw ): string {
		if ( null === $raw ) {
			return 'unknown';
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > self::ATTRIBUTION_MAX_BYTES ) {
			return 'unsupported';
		}
		if ( in_array( $raw, self::UNKNOWN_SOURCE_TYPES, true ) ) {
			return 'unknown';
		}
		return in_array( $raw, self::PRESENT_SOURCE_TYPES, true ) ? 'present' : 'unsupported';
	}

	/**
	 * Validate one literal saved IP and reduce it to its network prefix (C4): IPv4-mapped IPv6 is
	 * treated as IPv4; IPv4 groups by /24 and IPv6 by /64. Lists, ports, zone IDs, brackets,
	 * prefixes, whitespace, leading-zero IPv4 octets, over-long and non-scalar values are invalid.
	 *
	 * @param mixed $raw Raw bounded value (null when absent).
	 * @return array{0:string,1:?string} [valid|missing|invalid, binary prefix or null].
	 */
	public static function network_prefix( $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return array( 'missing', null );
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > self::IP_MAX_BYTES || false !== strpbrk( $raw, " \t\r\n\0,;%[]/" ) ) {
			return array( 'invalid', null );
		}
		if ( 1 === preg_match( '/(?:^|:)(\d{1,3}(?:\.\d{1,3}){3})$/', $raw, $m ) ) {
			foreach ( explode( '.', $m[1] ) as $octet ) {
				if ( strlen( $octet ) > 1 && '0' === $octet[0] ) {
					return array( 'invalid', null );
				}
			}
		}
		$bin = inet_pton( $raw );
		if ( ! is_string( $bin ) || ( 4 !== strlen( $bin ) && 16 !== strlen( $bin ) ) ) {
			return array( 'invalid', null );
		}
		if ( 16 === strlen( $bin ) && str_starts_with( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			$bin = substr( $bin, 12 );
		}
		return array( 'valid', 4 === strlen( $bin ) ? '4' . substr( $bin, 0, 3 ) : '6' . substr( $bin, 0, 8 ) );
	}

	/**
	 * Count one valid-IP failed order in its bucket's network map. The prefix is keyed with this
	 * call's HMAC key and replaced by a sequence number; neither leaves this object as such.
	 *
	 * @param array<string,mixed> $s      Scope (by reference).
	 * @param int                 $bucket Bucket index.
	 * @param string              $prefix Binary network prefix.
	 */
	private function count_network( array &$s, int $bucket, string $prefix ): void {
		$digest = hash_hmac( 'sha256', $prefix, $this->key, true );
		if ( ! isset( $this->net_ids[ $digest ] ) ) {
			if ( count( $this->net_ids ) >= $this->limits['networks'] ) {
				$this->flags['network_capped'] = true;
				$s['networks_complete']        = false;
				return;
			}
			$this->net_ids[ $digest ] = count( $this->net_ids ) + 1;
		}
		$id = $this->net_ids[ $digest ];
		unset( $digest );
		if ( ! isset( $s['net'][ $bucket ][ $id ] ) ) {
			if ( $this->net_cells >= $this->limits['network_cells'] ) {
				$this->flags['net_cells_capped'] = true;
				$s['networks_complete']          = false;
				return;
			}
			++$this->net_cells;
			$s['net'][ $bucket ][ $id ] = 0;
		}
		++$s['net'][ $bucket ][ $id ];
	}

	/**
	 * Keep the latest failed orders of a scope as example candidates (created desc, ID desc).
	 *
	 * @param array<string,mixed> $s       Scope (by reference).
	 * @param int                 $id      Order ID.
	 * @param int                 $created Creation time.
	 */
	private function example( array &$s, int $id, int $created ): void {
		if ( $id <= 0 ) {
			return;
		}
		$s['examples'][] = array(
			'id'      => $id,
			'created' => $created,
		);
		usort( $s['examples'], static fn( array $a, array $b ): int => array( $b['created'], $b['id'] ) <=> array( $a['created'], $a['id'] ) );
		if ( count( $s['examples'] ) > self::MAX_EXAMPLES ) {
			array_pop( $s['examples'] );
		}
	}

	/**
	 * Reserve one bucket cell, or record the cap.
	 */
	private function allocate_cell(): bool {
		if ( $this->cells >= $this->limits['bucket_cells'] ) {
			$this->flags['bucket_capped'] = true;
			return false;
		}
		++$this->cells;
		return true;
	}

	/**
	 * Track a method ID within the identity bound.
	 *
	 * @param string $method Safe method ID.
	 */
	private function track_method( string $method ): bool {
		if ( isset( $this->method_index[ $method ] ) ) {
			return true;
		}
		if ( count( $this->method_index ) >= $this->limits['methods'] ) {
			$this->flags['methods_truncated'] = true;
			++$this->flags['methods_omitted'];
			return false;
		}
		$this->method_index[ $method ] = true;
		return true;
	}

	/**
	 * Tally an invalid row: excluded, and its scope (if it ever exists) is partial.
	 *
	 * @param string $origin checkout|store_api|missing|other.
	 * @param string $method Safe method ID ('' when empty).
	 */
	private function invalid_row( string $origin, string $method ): void {
		++$this->totals['invalid_rows'];
		if ( 'other' === $origin || '' === $method ) {
			return;
		}
		$o = 'missing' === $origin ? 'missing_origin' : 'verified_checkout';
		if ( ! isset( $this->invalid[ $o ][ $method ] ) && count( $this->invalid['verified_checkout'] ) + count( $this->invalid['missing_origin'] ) >= $this->limits['methods'] ) {
			$this->flags['invalid_overflow'] = true;
			return;
		}
		$this->invalid[ $o ][ $method ] = ( $this->invalid[ $o ][ $method ] ?? 0 ) + 1;
	}

	/**
	 * Empty scope aggregate.
	 *
	 * @return array<string,mixed>
	 */
	private static function new_scope(): array {
		return array(
			'w'                 => array_fill_keys( array_merge( self::CLASSES, self::ORIGINS ), 0 ),
			'wb'                => array(),
			'net'               => array(),
			'bh'                => array(),
			'b'                 => array(
				'attempts'    => 0,
				'failed'      => 0,
				'first'       => null,
				'last'        => null,
				'present'     => 0,
				'unknown'     => 0,
				'unsupported' => 0,
			),
			'anomalies'         => 0,
			'series_complete'   => true,
			'networks_complete' => true,
			'examples'          => array(),
		);
	}

	/**
	 * Empty window bucket cell.
	 *
	 * @return array<string,mixed>
	 */
	private static function new_cell(): array {
		return array(
			'f'          => 0,
			'a'          => 0,
			'first'      => null,
			'last'       => null,
			'ip_valid'   => 0,
			'ip_missing' => 0,
			'ip_invalid' => 0,
			'present'    => 0,
			'unknown'    => 0,
			'unsupported' => 0,
		);
	}

	/**
	 * The folded result. Scopes are sorted by origin scope then method ID. The `networks` maps
	 * hold per-call sequence numbers for CardTestingRules only: never cache, log or return them.
	 *
	 * @param string|null          $authority hpos|legacy.
	 * @param string|null          $stopped   row_cap|order_scan_budget|null.
	 * @param int                  $rows      Rows folded.
	 * @param array<string,mixed>  $work      Work accounting.
	 * @return array<string,mixed>
	 */
	public function result( ?string $authority, ?string $stopped, int $rows, array $work ): array {
		$scan_complete = null === $stopped;
		$scopes        = array();
		foreach ( array( 'verified_checkout', 'missing_origin' ) as $o ) {
			$ids = array_map( 'strval', array_keys( $this->scopes[ $o ] ) );
			sort( $ids, SORT_STRING );
			foreach ( $ids as $id ) {
				$s        = $this->scopes[ $o ][ $id ];
				$invalid  = $this->invalid[ $o ][ $id ] ?? 0;
				ksort( $s['wb'], SORT_NUMERIC );
				ksort( $s['bh'], SORT_NUMERIC );
				ksort( $s['net'], SORT_NUMERIC );
				$scopes[] = array(
					'method_id'          => $id,
					'origin_scope'       => $o,
					'counts'             => $s['w'],
					'buckets'            => $s['wb'],
					'networks'           => $s['net'],
					'baseline_hours'     => $s['bh'],
					'baseline'           => $s['b'],
					'invalid_rows'       => $invalid,
					'anomalies'          => $s['anomalies'],
					'counts_complete'    => $scan_complete && 0 === $invalid && 0 === $s['anomalies'] && ! $this->flags['invalid_overflow'],
					'series_complete'    => $s['series_complete'],
					'networks_complete'  => $s['networks_complete'],
					'example_candidates' => $s['examples'],
				);
			}
		}
		$invalid_only = array();
		foreach ( array( 'verified_checkout', 'missing_origin' ) as $o ) {
			$ids = array_map( 'strval', array_keys( $this->invalid[ $o ] ) );
			sort( $ids, SORT_STRING );
			foreach ( $ids as $id ) {
				if ( ! isset( $this->scopes[ $o ][ $id ] ) ) {
					$invalid_only[] = array(
						'method_id'    => $id,
						'origin_scope' => $o,
						'count'        => $this->invalid[ $o ][ $id ],
					);
				}
			}
		}
		$method_ids = array_map( 'strval', array_keys( $this->method_index + array_fill_keys( array_map( 'strval', array_keys( $this->invalid['verified_checkout'] + $this->invalid['missing_origin'] ) ), true ) ) );
		sort( $method_ids, SORT_STRING );
		ksort( $this->method_orders, SORT_STRING );
		$partial = ! $scan_complete || $this->totals['invalid_rows'] > 0 || $this->flags['methods_truncated'] || $this->flags['invalid_overflow']
			|| $this->flags['bucket_capped'] || $this->flags['network_capped'] || $this->flags['net_cells_capped'] || $this->flags['draft_anomalies'] > 0;
		return array(
			'ok'                => true,
			'authority'         => $authority,
			'reason'            => $stopped,
			'coverage'          => $partial ? 'partial' : 'complete',
			'scan_complete'     => $scan_complete,
			'now'               => $this->now,
			'since_hours'       => $this->since,
			'history_start'     => $this->h_start,
			'window_start'      => $this->w_start,
			'signals'           => $this->signals,
			'totals'            => $this->totals,
			'method_orders'     => $this->method_orders,
			'method_ids'        => $method_ids,
			'methods_truncated' => $this->flags['methods_truncated'],
			'methods_omitted'   => $this->flags['methods_omitted'],
			'scopes'            => $scopes,
			'invalid_only'      => $invalid_only,
			'invalid_overflow'  => $this->flags['invalid_overflow'],
			'caps'              => array(
				'bucket_cells'  => $this->flags['bucket_capped'],
				'network_ids'   => $this->flags['network_capped'],
				'network_cells' => $this->flags['net_cells_capped'],
			),
			// Distinct recorded networks over the whole call; null once the identity map is capped.
			'networks_seen'     => $this->flags['network_capped'] ? null : count( $this->net_ids ),
			'draft_anomalies'   => $this->flags['draft_anomalies'],
			'rows_read'         => $rows,
			// Map usage against the bounds (work accounting only).
			'usage'             => array(
				'methods'       => count( $this->method_index ),
				'networks'      => count( $this->net_ids ),
				'bucket_cells'  => $this->cells,
				'network_cells' => $this->net_cells,
			),
			'work'              => $work,
		);
	}
}
