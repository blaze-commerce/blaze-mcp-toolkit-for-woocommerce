<?php
/**
 * Connector-correct tool names in the analytics abilities' instruction text.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Abilities;

use Blaze\McpToolkit\Bundled\AnalyticsGuard;
use Blaze\McpToolkit\Connectors\Connector;
use Blaze\McpToolkit\Connectors\Connectors;

defined( 'ABSPATH' ) || exit;

/**
 * The commerce-abilities package (our bundled copy and WooCommerce for Claude's) names other
 * analytics tools by legacy aliases such as `wc-analytics-confirm-large-range`, in its
 * descriptions and in the extended_range_required recovery error. Neither connector shows a tool
 * by that name, so this filter renders those references per connector at runtime. It changes text
 * only: never codes, data, permissions or the gate itself, and it never queries the registry.
 */
final class AnalyticsNaming {

	/**
	 * Ability name prefix this class translates.
	 */
	public const PREFIX = 'wc-analytics/';

	/**
	 * The gate's error code; only this error's message is rewritten.
	 */
	public const ERROR_CODE = 'extended_range_required';

	/**
	 * Bytes kept free under a connector's error-message limit.
	 */
	public const ERROR_MARGIN_BYTES = 10;

	/**
	 * Legacy executable alias => canonical ability slug. Bounded: nothing else is rewritten.
	 */
	public const ALIASES = array(
		'wc-analytics-confirm-large-range' => 'wc-analytics/confirm-large-range',
		'wc-analytics-totals'              => 'wc-analytics/totals',
		'wc-analytics-breakdown'           => 'wc-analytics/breakdown',
		'wc-analytics-series'              => 'wc-analytics/series',
		'wc-analytics-rows'                => 'wc-analytics/rows',
	);

	/**
	 * Add the filter. Priority 30: after McpExposure (10) and AnalyticsGuard (20), so the
	 * guard's wrapper is the callback this one calls.
	 */
	public static function register(): void {
		add_filter( 'wp_register_ability_args', array( self::class, 'filter_args' ), 30, 2 );
	}

	/**
	 * Render the description and wrap the execute callback of wc-analytics/* abilities.
	 *
	 * @param mixed  $args Ability registration args.
	 * @param string $name Ability name.
	 * @return mixed
	 */
	public static function filter_args( $args, $name = '' ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || ! str_starts_with( $name, self::PREFIX ) ) {
			return $args;
		}

		// Descriptions are fixed at registration (a connector may capture them before any request
		// context exists), so they follow the installed connectors, not the current request.
		if ( isset( $args['description'] ) && is_string( $args['description'] ) ) {
			$args['description'] = self::render_description( $args['description'], Connectors::state() );
		}

		if ( isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
			$original                 = $args['execute_callback'];
			$args['execute_callback'] = static function ( ...$call_args ) use ( $original ) {
				// Resolve before the call, so no frame opened inside it can change the recovery text.
				$connector = Connectors::execution();
				return self::translate_result( call_user_func_array( $original, $call_args ), $connector );
			};
		}

		return $args;
	}

	/**
	 * Description text with every alias and canonical slug rendered for the connector state:
	 * the labelled dual form while Easy MCP AI is active, otherwise the canonical slug.
	 *
	 * @param string $text  Description.
	 * @param string $state Connectors::state() value.
	 */
	public static function render_description( string $text, string $state ): string {
		$dual = in_array( $state, array( 'easy-mcp', 'both' ), true );
		$out  = preg_replace_callback(
			self::pattern( true ),
			static function ( array $m ) use ( $dual ): string {
				$slug = self::ALIASES[ $m[0] ] ?? $m[0];
				return $dual ? self::dual_form( $slug ) : $slug;
			},
			$text
		);
		return is_string( $out ) ? $out : $text;
	}

	/**
	 * The large-range error with its aliases named as the connector names them. Anything else
	 * (other codes, results, an error with no alias in it) is returned as is.
	 *
	 * @param mixed     $result    Execute callback result.
	 * @param Connector $connector Connector resolved before the call.
	 * @return mixed
	 */
	public static function translate_result( $result, Connector $connector ) {
		$limit = $connector->max_error_message_bytes();
		if ( ! $result instanceof \WP_Error ) {
			return $result;
		}
		$codes = $result->get_error_codes();
		if ( ! in_array( self::ERROR_CODE, $codes, true ) && ! ( null !== $limit && in_array( AnalyticsGuard::ERROR_CODE, $codes, true ) ) ) {
			return $result;
		}

		$changed = false;
		$out     = new \WP_Error();
		foreach ( $result->get_error_codes() as $code ) {
			foreach ( $result->get_error_messages( $code ) as $message ) {
				if ( self::ERROR_CODE === $code ) {
					$renamed = self::rename_aliases( (string) $message, $connector );
					if ( null !== $limit && strlen( $renamed ) > $limit ) {
						$renamed = self::compact_message( $renamed, $result->get_error_data( $code ), $connector, $limit - self::ERROR_MARGIN_BYTES );
					}
					$changed = $changed || $renamed !== $message;
					$message = $renamed;
				} elseif ( AnalyticsGuard::ERROR_CODE === $code && null !== $limit ) {
					$data    = $result->get_error_data( $code );
					$short   = self::compact_unavailable( is_array( $data ) && isset( $data['reason'] ) ? (string) $data['reason'] : '', $limit - self::ERROR_MARGIN_BYTES );
					$changed = $changed || $short !== $message;
					$message = $short;
				}
				$out->add( $code, $message );
			}
			foreach ( $result->get_all_error_data( $code ) as $data ) {
				$out->add_data( $data, $code );
			}
		}

		return $changed ? $out : $result;
	}

	/**
	 * The large-range recovery in a form that fits a connector's error-message limit. In order of
	 * priority, later parts dropped first when over the budget: stop and ask the merchant; if they
	 * say yes, call the confirm tool with the exact date_start, date_end and type; then re-run the
	 * same call; don't split the range. The first three are always kept. Returns the message
	 * unchanged when its dates or type can't be read.
	 *
	 * @param string    $message   The renamed large-range message.
	 * @param mixed     $data      The error's data (cost_estimate.type is the literal type).
	 * @param Connector $connector Connector.
	 * @param int       $budget    Bytes to fit within.
	 */
	public static function compact_message( string $message, $data, Connector $connector, int $budget ): string {
		$type = is_array( $data ) && isset( $data['cost_estimate']['type'] ) && is_string( $data['cost_estimate']['type'] ) ? $data['cost_estimate']['type'] : null;
		if ( null === $type && preg_match( "/type='([^']+)'/", $message, $m ) ) {
			$type = $m[1];
		}
		if ( ! preg_match( "/date_start='([0-9-]+)'/", $message, $start ) || ! preg_match( "/date_end='([0-9-]+)'/", $message, $end ) || null === $type ) {
			return $message;
		}
		$days    = is_array( $data ) && isset( $data['cost_estimate']['range_days'] ) ? (int) $data['cost_estimate']['range_days'] : 0;
		$confirm = $connector->tool_name( 'wc-analytics/confirm-large-range' );
		$call    = sprintf( ' If yes, call %1$s date_start=%2$s date_end=%3$s type=%4$s description=<label>, then re-run the same call.', $confirm, $start[1], $end[1], $type );
		$stop    = 'STOP: ask the merchant first.';
		$out     = $stop . $call;
		foreach ( array( " Don't split the range.", " Don't split it." ) as $tail ) {
			if ( strlen( $out . $tail ) <= $budget ) {
				$out .= $tail;
				break;
			}
		}
		if ( $days > 0 ) {
			$with_days = sprintf( 'STOP: %d-day range; ask the merchant first.', $days ) . substr( $out, strlen( $stop ) );
			if ( strlen( $with_days ) <= $budget ) {
				$out = $with_days;
			}
		}
		if ( strlen( $out ) > $budget ) {
			$out = str_replace( ', then re-run the same call.', ', then re-run.', $out );
		}
		if ( strlen( $out ) > $budget ) {
			// Last resort: keep stop, ask, the confirm tool and the exact arguments; drop the hint.
			$out = str_replace( ' description=<label>', '', $out );
		}
		return self::fit( $out, $budget );
	}

	/**
	 * The analytics-unavailable error in a form that fits a connector's error-message limit: the
	 * [ANALYTICS_UNAVAILABLE] token first, then what is wrong and where to fix it, per reason.
	 *
	 * @param string $reason AnalyticsGuard reason code (from the error data).
	 * @param int    $budget Bytes to fit within.
	 */
	public static function compact_unavailable( string $reason, int $budget ): string {
		switch ( $reason ) {
			case 'analytics_disabled':
				$text = __( 'WooCommerce Analytics is off, so figures would be incomplete. Fix: turn it on under WooCommerce > Settings > Advanced > Features, then retry.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'analytics_unreadable':
				$text = __( 'Analytics data could not be read, so figures cannot be checked. Retry in a few minutes; if it persists, ask your developer or host to check the database.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			case 'import_in_progress':
			case 'import_not_run':
				$text = __( 'Analytics has not finished importing orders. Fix: wait, or start it under WooCommerce > Analytics > Settings > Import historical data, then retry.', 'blaze-mcp-toolkit-for-woocommerce' );
				break;
			default:
				$text = __( 'Some orders in these dates are not in Analytics yet. Fix: WooCommerce > Analytics > Settings > Import historical data, or ask about a more recent period.', 'blaze-mcp-toolkit-for-woocommerce' );
		}
		return self::fit( AnalyticsGuard::MESSAGE_TOKEN . ' ' . $text, $budget );
	}

	/**
	 * Cut a message to at most $budget bytes on a UTF-8 character boundary (never exceeds it).
	 *
	 * @param string $text   Message.
	 * @param int    $budget Bytes.
	 */
	private static function fit( string $text, int $budget ): string {
		if ( strlen( $text ) <= $budget ) {
			return $text;
		}
		return function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, max( 0, $budget ), 'UTF-8' ) : substr( $text, 0, max( 0, $budget ) );
	}

	/**
	 * Replace the alias keys (only) with the connector's tool name for the canonical slug.
	 *
	 * @param string    $text      Message.
	 * @param Connector $connector Connector.
	 */
	private static function rename_aliases( string $text, Connector $connector ): string {
		$out = preg_replace_callback(
			self::pattern( false ),
			static fn( array $m ): string => $connector->tool_name( self::ALIASES[ $m[0] ] ),
			$text
		);
		return is_string( $out ) ? $out : $text;
	}

	/**
	 * "Recommended connector: execute ability `<slug>`; Easy MCP AI: call `<easy name>`".
	 *
	 * @param string $slug Canonical ability slug.
	 */
	private static function dual_form( string $slug ): string {
		$recommended = Connectors::get( 'recommended' );
		$easy        = Connectors::get( 'easy-mcp' );
		return sprintf(
			'Recommended connector: execute ability `%1$s`; %2$s: call `%3$s`',
			null !== $recommended ? $recommended->tool_name( $slug ) : $slug,
			null !== $easy ? $easy->label() : 'Easy MCP AI',
			null !== $easy ? $easy->tool_name( $slug ) : $slug
		);
	}

	/**
	 * Whole-token matcher for the aliases, and optionally the five canonical slugs. A token
	 * continued by a letter, digit, `_`, `-` or `/` (e.g. `wc-analytics/totals-foo`) is left alone.
	 *
	 * @param bool $with_slugs Also match the canonical slash-form slugs.
	 */
	private static function pattern( bool $with_slugs ): string {
		$tokens = array_keys( self::ALIASES );
		if ( $with_slugs ) {
			$tokens = array_merge( $tokens, array_values( self::ALIASES ) );
		}
		usort( $tokens, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$alternation = implode( '|', array_map( static fn( string $t ): string => preg_quote( $t, '#' ), $tokens ) );
		return '#(?<![A-Za-z0-9_/-])(?:' . $alternation . ')(?![A-Za-z0-9_/-])#';
	}
}
