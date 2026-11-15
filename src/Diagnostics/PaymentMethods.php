<?php
/**
 * Payment method inventory for payment health: registered, enabled, mode, kind and family.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Passive inventory (FEATURE-PLAN item 4, contract C3). Never constructs gateways, never calls a
 * gateway method, availability check, processing method, remote API or secret getter: Stripe's
 * UPE gateway can fetch from api.stripe.com when constructed (item 1 receipt). Registered IDs come
 * only from the already-initialised registry (WooCommerce fires `wc_payment_gateways_initialized`
 * at the end of WC_Payment_Gateways::init(), since 8.5.0; verified at the 10.9.0 tag). Enabled
 * and mode come from allowlisted keys of `woocommerce_<id>_settings`; whole options are never
 * returned, stored or hashed. The merchant-editable gateway title is never read.
 */
final class PaymentMethods {

	/** Fixed manual method IDs (WooCommerce core). */
	public const MANUAL_IDS = array( 'bacs', 'cheque', 'cod' );

	/** Verified family mappings (item 1). Other candidate families ship with no mapping. */
	private const FAMILIES = array(
		'stripe' => array(
			'plugin'          => 'woocommerce-gateway-stripe',
			'method_ids'      => array( 'stripe' ),
			'method_prefixes' => array( 'stripe_' ),
			'log_sources'     => array( 'woocommerce-gateway-stripe' ),
			'hooks'           => array( 'wc_stripe_deferred_webhook', 'wc_stripe_process_payment_intent_metadata', 'wc_stripe_process_checkout_session_metadata' ),
			'hook_groups'     => array( 'woocommerce_stripe' ),
		),
		// Wave 2, Q8 interim: tagged PayPal Payments 4.2.2, WooPayments 11.3.0 and Square 5.5.3
		// (tests/fixtures/payment-gateway-contracts.json). Method IDs and plugin slugs only. No log
		// sources (Q6: these plugins log errors only with debug logging on, or never at error level,
		// and no webhook-failure pattern is verified) and no webhook record.
		'paypal'      => array(
			'plugin'          => 'woocommerce-paypal-payments',
			'method_ids'      => array( 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway', 'ppcp-googlepay', 'ppcp-applepay', 'ppcp-axo-gateway', 'ppcp-bancontact', 'ppcp-blik', 'ppcp-eps', 'ppcp-ideal', 'ppcp-mybank', 'ppcp-p24', 'ppcp-trustly', 'ppcp-pwc', 'ppcp-multibanco', 'ppcp-oxxo-gateway', 'ppcp-pay-upon-invoice-gateway' ),
			'method_prefixes' => array(),
			'log_sources'     => array(),
			'hooks'           => array(), // Q8 interim: no payment-job mapping for PayPal.
			'hook_groups'     => array(),
		),
		'woopayments' => array(
			'plugin'          => 'woocommerce-payments',
			'method_ids'      => array( 'woocommerce_payments' ),
			'method_prefixes' => array( 'woocommerce_payments_' ),
			'log_sources'     => array(),
			// Only the hooks the fixture marks payment_related. The shared `woocommerce_payments`
			// group is not mapped: it also holds order-tracking and store-sync jobs.
			'hooks'           => array( 'wcpay_webhook_fetch_events', 'wcpay_webhook_process_event', 'wcpay_add_fee_breakdown_to_order_notes', 'wcpay_update_saved_payment_method' ),
			'hook_groups'     => array(),
		),
		'square'      => array(
			'plugin'          => 'woocommerce-square',
			'method_ids'      => array( 'square_credit_card', 'square_cash_app_pay', 'gift_cards_pay' ),
			'method_prefixes' => array(),
			'log_sources'     => array(),
			'hooks'           => array(), // No Square job is a payment job.
			'hook_groups'     => array(),
		),
	);

	/**
	 * Source-verified instant IDs (item 1 and wave 2 receipts): each charges in the checkout
	 * request and marks the order paid, or on-hold for authorise-only. Every other ID of these
	 * families stays unknown.
	 */
	public const INSTANT_IDS = array(
		'stripe',
		'ppcp-gateway',
		'ppcp-credit-card-gateway',
		'ppcp-card-button-gateway',
		'ppcp-googlepay',
		'ppcp-applepay',
		'ppcp-axo-gateway',
		'woocommerce_payments',
		'square_credit_card',
		'square_cash_app_pay',
	);

	/**
	 * Per instant ID: does a transaction reference on an unpaid order imply a completed or
	 * authorised charge? Only true where the receipt shows the reference is written after the
	 * charge or authorisation succeeds; otherwise false (no guess), so the reference count is
	 * context only (C6 transaction_recorded_unpaid).
	 * - stripe: charge ID set only for an authorised, pending-captured or succeeded charge
	 *   (receipt, abstract-wc-stripe-payment-gateway.php:714-786).
	 * - PayPal processor path: capture or authorisation ID stored after capture/authorise
	 *   (OrderProcessor.php:129-140); 3D Secure and redirect fallbacks return before it.
	 * - woocommerce_payments: intent ID stored even while the intent still needs action
	 *   (class-wc-payments-order-service.php:1875, before :2499), so a pending 3D Secure
	 *   order already has one.
	 * - Square card and Cash App Pay: the receipt shows the ID stored before payment_complete,
	 *   but not whether a declined or unfinished charge leaves one, so not established.
	 */
	public const REFERENCE_IMPLIES_CHARGE = array(
		'stripe'                   => true,
		'ppcp-gateway'             => true,
		'ppcp-credit-card-gateway' => true,
		'ppcp-card-button-gateway' => true,
		'ppcp-googlepay'           => true,
		'ppcp-applepay'            => true,
		'ppcp-axo-gateway'         => true,
		'woocommerce_payments'     => false,
		'square_credit_card'       => false,
		'square_cash_app_pay'      => false,
	);

	/**
	 * Whether a recorded transaction reference implies a charge for this method (fixed map;
	 * false for every unlisted ID).
	 *
	 * @param string $id Method ID.
	 */
	public static function reference_implies_charge( string $id ): bool {
		return true === ( self::REFERENCE_IMPLIES_CHARGE[ $id ] ?? false );
	}

	/** Source-verified replacements (item 1). */
	private const REPLACEMENTS = array( 'stripe_sepa' => array( 'stripe_sepa_debit' ) );

	/** Allowed token characters: literals only, no regex or path syntax. */
	private const TOKEN = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{1,99}$/';

	/** Family key grammar. */
	private const FAMILY = '/^[a-z0-9][a-z0-9_\-]{0,39}$/';

	/** Plugin slug grammar. */
	private const SLUG = '/^[a-z0-9][a-z0-9_\-]{0,99}$/';

	/** Max tokens per list after validation. */
	private const MAX_TOKENS = 20;

	/**
	 * Inventory: every enabled registered method plus every valid method ID with eligible order
	 * activity. One row per method ID.
	 *
	 * @param string[] $activity_ids Safe method IDs with activity in the 28-day horizon (from PaymentOrders).
	 * @return array{registry_known:bool,methods:array<string,array<string,mixed>>,families:array<string,array<string,string[]>>}
	 */
	public static function inventory( array $activity_ids ): array {
		$families = self::token_map();
		$registry = self::registry();
		$ids      = array();
		foreach ( $registry['gateways'] as $id => $info ) {
			if ( true === self::enabled( $id ) ) {
				$ids[ $id ] = true;
			}
		}
		foreach ( $activity_ids as $id ) {
			$id = (string) $id;
			if ( '' !== $id && PaymentRules::safe_method_id( $id ) === $id ) {
				$ids[ $id ] = true;
			}
		}
		ksort( $ids, SORT_STRING );

		$replacements = self::replacements();
		$methods      = array();
		foreach ( array_keys( $ids ) as $id ) {
			$id     = (string) $id;
			$family = self::method_family( $id, $families );
			$slug   = null;
			if ( null !== $family ) {
				$slug = $families[ $family ]['plugin'][0] ?? null;
			} elseif ( isset( $registry['gateways'][ $id ] ) ) {
				$slug = $registry['gateways'][ $id ]['plugin'];
			}
			// Unknown (null), never false, when the registry has not been built in this request.
			$registered = $registry['known'] ? isset( $registry['gateways'][ $id ] ) : null;
			// A known-absent gateway (built registry without the ID) keeps its old settings after
			// deactivation; they describe a plugin that is not running, so enabled and test mode
			// are unknown rather than read from the retained option. Q5: an unknown registry still
			// uses the safe allowlisted settings.
			$absent         = false === $registered;
			$kind           = self::kind( $id );
			$methods[ $id ] = array(
				'id'                       => $id,
				'title'                    => self::title( $id ),
				'plugin'                   => $slug,
				'family'                   => $family,
				'registered'               => $registered,
				'enabled'                  => $absent ? null : self::enabled( $id ),
				'test_mode'                => $absent ? null : self::test_mode( $id, $family ),
				'kind'                     => $kind,
				'reference_implies_charge' => 'instant' === $kind && self::reference_implies_charge( $id ),
				'replacements'             => $replacements[ $id ] ?? array(),
				// A known-absent plugin's retained webhook records describe a plugin that is not
				// running, so no webhook verdict is drawn from them (same rule as enabled/test mode).
				'webhook_adapter'          => 'stripe' === $family && ! $absent ? 'stripe' : null,
				'webhook_required'         => 'stripe' === $id ? false : null,
			);
		}
		return array(
			'registry_known'  => $registry['known'],
			'registry_reason' => $registry['known'] ? null : 'registry_not_loaded',
			'methods'         => $methods,
			'families'       => $families,
		);
	}

	/**
	 * Registered gateway IDs and plugin slugs from the already-built registry, without building it.
	 *
	 * @return array{known:bool,gateways:array<string,array{plugin:?string}>}
	 */
	private static function registry(): array {
		$out = array(
			'known'    => false,
			'gateways' => array(),
		);
		if ( ! class_exists( '\WC_Payment_Gateways' ) || ! function_exists( 'did_action' ) || did_action( 'wc_payment_gateways_initialized' ) < 1 ) {
			return $out;
		}
		$list = \WC_Payment_Gateways::instance()->payment_gateways; // Already initialised: instance() returns it, init() is not re-run.
		if ( ! is_array( $list ) ) {
			return $out;
		}
		$out['known'] = true;
		foreach ( $list as $gateway ) {
			if ( ! is_object( $gateway ) || ! isset( $gateway->id ) || ! is_string( $gateway->id ) ) {
				continue;
			}
			$id = $gateway->id;
			if ( '' === $id || PaymentRules::safe_method_id( $id ) !== $id ) {
				continue;
			}
			$out['gateways'][ $id ] = array( 'plugin' => self::plugin_slug( $gateway ) );
		}
		return $out;
	}

	/**
	 * Safe plugin slug for a gateway object's class: the first directory under the plugins
	 * folder, or null. Never returns a path.
	 *
	 * @param object $gateway Gateway.
	 */
	private static function plugin_slug( object $gateway ): ?string {
		try {
			$file = ( new \ReflectionClass( $gateway ) )->getFileName();
		} catch ( \Throwable $e ) {
			return null;
		}
		$root = defined( 'WP_PLUGIN_DIR' ) ? realpath( WP_PLUGIN_DIR ) : false;
		$real = is_string( $file ) ? realpath( $file ) : false;
		if ( false === $root || false === $real || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		$rest = substr( $real, strlen( $root ) + 1 );
		$slug = strtolower( (string) strtok( $rest, '/\\' ) );
		return 1 === preg_match( self::SLUG, $slug ) && str_contains( $rest, DIRECTORY_SEPARATOR ) ? $slug : null;
	}

	/**
	 * Enabled from the `enabled` key of `woocommerce_<id>_settings`: true/false only when known.
	 *
	 * @param string $id Method ID.
	 */
	public static function enabled( string $id ): ?bool {
		$settings = get_option( 'woocommerce_' . $id . '_settings', null );
		if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) || ! is_string( $settings['enabled'] ) ) {
			return null;
		}
		if ( 'yes' === $settings['enabled'] ) {
			return true;
		}
		return 'no' === $settings['enabled'] ? false : null;
	}

	/**
	 * Test mode from verified adapters only (Stripe: `testmode` of `woocommerce_stripe_settings`).
	 *
	 * @param string      $id     Method ID.
	 * @param string|null $family Family.
	 */
	private static function test_mode( string $id, ?string $family ): ?bool {
		unset( $id );
		if ( 'stripe' !== $family ) {
			return null;
		}
		$mode = self::stripe_mode();
		return null === $mode ? null : 'test' === $mode;
	}

	/**
	 * Stripe mode: 'test', 'live' or null when the settings option is absent or not an array.
	 * Reads only the allowlisted `testmode` key.
	 */
	public static function stripe_mode(): ?string {
		$settings = get_option( 'woocommerce_stripe_settings', null );
		if ( ! is_array( $settings ) ) {
			return null;
		}
		return isset( $settings['testmode'] ) && 'yes' === $settings['testmode'] ? 'test' : 'live';
	}

	/**
	 * Settlement kind: manual for the fixed core IDs, instant for verified mappings, else unknown;
	 * then the kind filter, whose result must be one of the 3 values.
	 *
	 * @param string $id Method ID.
	 */
	public static function kind( string $id ): string {
		$kind = in_array( $id, self::MANUAL_IDS, true ) ? 'manual' : ( in_array( $id, self::INSTANT_IDS, true ) ? 'instant' : 'unknown' );
		/**
		 * Filters a payment method's settlement kind for payment health.
		 *
		 * @param string $kind      instant, manual or unknown.
		 * @param string $method_id Gateway ID.
		 */
		$filtered = apply_filters( 'blaze_mcp_toolkit_payment_method_kind', $kind, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- public hook name fixed by contract C3.
		return is_string( $filtered ) && in_array( $filtered, array( 'instant', 'manual', 'unknown' ), true ) ? $filtered : 'unknown';
	}

	/**
	 * Canonical translated label; never the merchant-editable gateway title.
	 *
	 * @param string $id Method ID.
	 */
	private static function title( string $id ): string {
		switch ( $id ) {
			case 'bacs':
				return __( 'Direct bank transfer', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'cheque':
				return __( 'Check payments', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'cod':
				return __( 'Cash on delivery', 'blaze-mcp-toolkit-for-woocommerce' );
			case 'stripe':
				return __( 'Stripe', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		/* translators: %s: payment method ID, for example stripe_klarna. */
		return sprintf( __( 'Payment method (%s)', 'blaze-mcp-toolkit-for-woocommerce' ), $id );
	}

	/**
	 * Validated family token map: verified defaults through the tokens filter.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function token_map(): array {
		$defaults = array();
		foreach ( self::FAMILIES as $family => $map ) {
			$defaults[ $family ] = $map;
			$defaults[ $family ]['plugin'] = array( $map['plugin'] );
		}
		/**
		 * Filters the payment family token map (family => list => literal tokens).
		 *
		 * Lists: plugin, method_ids, method_prefixes, log_sources, hooks, hook_groups. Tokens are
		 * literal strings (2-100 of A-Z a-z 0-9 _ . -), never regexes or paths.
		 *
		 * @param array<string,array<string,string[]>> $families Family map.
		 */
		$raw = apply_filters( 'blaze_mcp_toolkit_payment_gateway_tokens', $defaults ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- public hook name fixed by contract C3.
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $family => $lists ) {
			if ( ! is_string( $family ) || 1 !== preg_match( self::FAMILY, $family ) || ! is_array( $lists ) ) {
				continue;
			}
			$clean = array();
			foreach ( array( 'plugin', 'method_ids', 'method_prefixes', 'log_sources', 'hooks', 'hook_groups' ) as $list ) {
				$clean[ $list ] = self::tokens( $lists[ $list ] ?? array() );
			}
			if ( array() === $clean['method_ids'] && array() === $clean['method_prefixes'] ) {
				continue;
			}
			$out[ $family ] = $clean;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Bounded, deduplicated literal tokens.
	 *
	 * @param mixed $raw Raw list.
	 * @return string[]
	 */
	private static function tokens( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $token ) {
			if ( is_string( $token ) && 1 === preg_match( self::TOKEN, $token ) ) {
				$out[ $token ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, self::MAX_TOKENS );
	}

	/**
	 * The one family a method ID belongs to, or null when none or more than one match.
	 *
	 * @param string                               $id       Method ID.
	 * @param array<string,array<string,string[]>> $families Family map.
	 */
	public static function method_family( string $id, array $families ): ?string {
		$hits = array();
		foreach ( $families as $family => $lists ) {
			if ( in_array( $id, $lists['method_ids'], true ) ) {
				$hits[ $family ] = true;
				continue;
			}
			foreach ( $lists['method_prefixes'] as $prefix ) {
				if ( strlen( $id ) > strlen( $prefix ) && str_starts_with( $id, $prefix ) ) {
					$hits[ $family ] = true;
				}
			}
		}
		return 1 === count( $hits ) ? (string) array_key_first( $hits ) : null;
	}

	/**
	 * Validated replacement map (old ID => replacement IDs).
	 *
	 * @return array<string,string[]>
	 */
	public static function replacements(): array {
		/**
		 * Filters explicit payment method replacements (old method ID => list of replacement IDs).
		 * Raw order grouping is not changed.
		 *
		 * @param array<string,string[]> $map Map.
		 */
		$raw = apply_filters( 'blaze_mcp_toolkit_payment_method_replacements', self::REPLACEMENTS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- public hook name fixed by contract C3.
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $old => $new ) {
			if ( ! is_string( $old ) || '' === $old || PaymentRules::safe_method_id( $old ) !== $old || ! is_array( $new ) ) {
				continue;
			}
			$ids = array();
			foreach ( $new as $id ) {
				if ( is_string( $id ) && '' !== $id && $id !== $old && PaymentRules::safe_method_id( $id ) === $id ) {
					$ids[ $id ] = true;
				}
			}
			if ( array() !== $ids ) {
				$out[ $old ] = array_keys( $ids );
			}
		}
		return $out;
	}
}
