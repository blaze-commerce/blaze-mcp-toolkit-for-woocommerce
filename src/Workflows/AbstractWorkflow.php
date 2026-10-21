<?php
/**
 * Base class for workflow tools: guided store-review recipes.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Workflows;

use Blaze\McpToolkit\Abilities\AbstractAbility;
use Blaze\McpToolkit\Abilities\Store\StoreProfile;

defined( 'ABSPATH' ) || exit;

/**
 * A workflow is a read-only tool that returns instructions plus an ordered list of other tools
 * (with arguments) for the AI to run. It reads no store data itself. It is a tool rather than an
 * MCP prompt because ChatGPT does not show MCP prompts, and because Enable Abilities' gateway only
 * exposes abilities.
 *
 * Steps whose tool is not registered, or whose permission the current user fails, are dropped and
 * named in the instructions, so the AI never wastes a turn on a call that will be denied.
 */
abstract class AbstractWorkflow extends AbstractAbility {

	/**
	 * Upstream's period enum (WooCommerce for Claude workflow prompts).
	 */
	public const PERIODS = array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' );

	/**
	 * Default period. Overridden per workflow to match upstream.
	 */
	protected const DEFAULT_PERIOD = 'last_30_days';

	/**
	 * Store profile tool registered by WooCommerce for Claude itself, used when we stand aside.
	 */
	protected const WCFC_STORE_PROFILE = 'woocommerce-claude/get-store-profile';

	/**
	 * Error code our analytics guard returns when Analytics is off or its import is incomplete.
	 */
	protected const ANALYTICS_UNAVAILABLE = 'blzmcp_analytics_unavailable';

	/**
	 * Health summary look-back cap (its input maximum).
	 */
	private const MAX_HEALTH_HOURS = 168;

	/**
	 * Update history look-back cap (its input maximum).
	 */
	private const MAX_HISTORY_DAYS = 180;

	/**
	 * Ordered steps for this workflow, before availability filtering.
	 *
	 * @param string $period One of PERIODS.
	 * @return array<int, array{tool: string, arguments: array<string, mixed>, why: string}>
	 */
	abstract protected static function plan( string $period ): array;

	/**
	 * Workflow-specific instructions (upstream's prompt text plus Blaze additions).
	 *
	 * @param string $period One of PERIODS.
	 */
	abstract protected static function guidance( string $period ): string;

	/**
	 * Workflows need the same capability as WooCommerce admin.
	 */
	protected static function can(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Never cached: the plan depends on the user's current permissions and is cheap to build.
	 */
	protected static function cache_ttl(): int {
		return 0;
	}

	/**
	 * Input schema: upstream's period enum and default.
	 *
	 * @return array<string, mixed>
	 */
	protected static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'period' => array(
					'type'        => 'string',
					'enum'        => self::PERIODS,
					'default'     => static::DEFAULT_PERIOD,
					/* translators: %s: default period, e.g. last_7_days. */
					'description' => sprintf( __( 'Period to review. Use %s unless the merchant explicitly asks for a different range.', 'blaze-mcp-toolkit-for-woocommerce' ), static::DEFAULT_PERIOD ),
				),
			),
			'additionalProperties' => false,
			'default'              => array( 'period' => static::DEFAULT_PERIOD ),
		);
	}

	/**
	 * Output schema.
	 *
	 * @return array<string, mixed>
	 */
	protected static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'workflow', 'period', 'instructions', 'steps' ),
			'properties'           => array(
				'workflow'     => array( 'type' => 'string' ),
				'period'       => array(
					'type' => 'string',
					'enum' => self::PERIODS,
				),
				'instructions' => array( 'type' => 'string' ),
				'steps'        => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'required'             => array( 'tool', 'arguments', 'why' ),
						'properties'           => array(
							'tool'      => array( 'type' => 'string' ),
							'arguments' => array( 'type' => 'object' ),
							'why'       => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build the plan for the current user.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	protected static function run( array $input ): array|\WP_Error {
		$period = isset( $input['period'] ) && in_array( $input['period'], self::PERIODS, true ) ? $input['period'] : static::DEFAULT_PERIOD;

		$steps   = array();
		$skipped = array();
		foreach ( static::plan( $period ) as $step ) {
			if ( static::available( $step['tool'], $step['arguments'] ) ) {
				// An object, so empty arguments encode as {} rather than [].
				$step['arguments'] = (object) $step['arguments'];
				$steps[]           = $step;
			} else {
				$skipped[] = $step;
			}
		}

		return array(
			'workflow'     => static::NAME,
			'period'       => $period,
			'instructions' => self::preamble( $skipped ) . "\n\n" . static::guidance( $period ),
			'steps'        => $steps,
		);
	}

	/**
	 * One step.
	 *
	 * @param string               $tool Ability name to run.
	 * @param array<string, mixed> $args Arguments, valid against that ability's input schema.
	 * @param string               $why  Why the step is there (and its condition, if conditional).
	 * @return array{tool: string, arguments: array<string, mixed>, why: string}
	 */
	protected static function step( string $tool, array $args, string $why ): array {
		return array(
			'tool'      => $tool,
			'arguments' => $args,
			'why'       => $why,
		);
	}

	/**
	 * True when the ability exists and the current user passes its permission check. Never runs
	 * the ability, and a denial here is not audited (it is a probe, not a call).
	 *
	 * @param string               $tool Ability name.
	 * @param array<string, mixed> $args Arguments the step would run with.
	 */
	protected static function available( string $tool, array $args = array() ): bool {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $tool ) ) {
			return false;
		}
		$ability = wp_get_ability( $tool );

		return null !== $ability && self::probe_permission( $ability, $args );
	}

	/**
	 * Store profile step: ours, or WooCommerce for Claude's own when we stand aside for it.
	 *
	 * @param string $why Why the step is there.
	 * @return array{tool: string, arguments: array<string, mixed>, why: string}
	 */
	protected static function store_profile_step( string $why ): array {
		$tool = StoreProfile::NAME;
		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $tool ) && wp_has_ability( self::WCFC_STORE_PROFILE ) ) {
			$tool = self::WCFC_STORE_PROFILE;
		}

		return static::step( $tool, array(), $why );
	}

	/**
	 * Days the period covers, counted back from now in the site's timezone, capped at the
	 * update-history maximum.
	 *
	 * @param string $period One of PERIODS.
	 */
	protected static function window_days( string $period ): int {
		$now   = current_datetime();
		$today = (int) $now->format( 'j' );

		switch ( $period ) {
			case 'today':
				$days = 1;
				break;
			case 'yesterday':
				$days = 2;
				break;
			case 'last_7_days':
				$days = 7;
				break;
			case 'this_month':
				$days = $today;
				break;
			case 'last_month':
				$days = $today + (int) $now->modify( 'first day of last month' )->format( 't' );
				break;
			case 'this_quarter':
				$month = (int) $now->format( 'n' );
				$start = $now->setDate( (int) $now->format( 'Y' ), (int) ( 3 * floor( ( $month - 1 ) / 3 ) + 1 ), 1 )->setTime( 0, 0 );
				$days  = (int) $start->diff( $now->setTime( 0, 0 ) )->days + 1;
				break;
			case 'this_year':
				$days = (int) $now->format( 'z' ) + 1;
				break;
			case 'last_30_days':
			default:
				$days = 30;
				break;
		}

		return max( 1, min( self::MAX_HISTORY_DAYS, $days ) );
	}

	/**
	 * Health summary hours for the period, capped at its input maximum (7 days).
	 *
	 * @param string $period One of PERIODS.
	 */
	protected static function window_hours( string $period ): int {
		return min( self::MAX_HEALTH_HOURS, static::window_days( $period ) * 24 );
	}

	/**
	 * Common instructions: how to run the steps, analytics-unavailable handling, skipped steps.
	 *
	 * @param array<int, array{tool: string, arguments: array<string, mixed>, why: string}> $skipped Dropped steps.
	 */
	private static function preamble( array $skipped ): string {
		$text = implode(
			"\n",
			array(
				'HOW TO USE THIS PLAN',
				'This tool does not read store data itself. It returns the steps to run. Run each entry in "steps" in order: call the connector\'s execute-ability tool with the step\'s "tool" as the ability name and its "arguments" as the parameters, exactly as given. A step whose "why" starts with "Only if" is conditional: run it only when that condition holds. When the steps are done, write the answer described below.',
				'',
				'Use the tools quietly. Never tell the merchant about tools, abilities, schemas, steps, parameters or this plan; talk only about their store.',
				'',
				'ANALYTICS CHECK',
				'The first step reads order totals, which also confirms WooCommerce Analytics data is complete. If it returns an error with code blzmcp_analytics_unavailable (Analytics is turned off, or its data import is incomplete or stalled), stop there: explain the problem plainly to the merchant using that error\'s message, say that figures would be incomplete until it is fixed, and do not run the other steps or report any numbers. If an analytics step returns extended_range_required, follow that error\'s own instructions and ask the merchant before continuing. If any other step returns an error, carry on without it and never guess the missing figures.',
			)
		);

		if ( array() !== $skipped ) {
			$lines = array();
			foreach ( $skipped as $step ) {
				$lines[] = '- ' . $step['tool'] . ': ' . $step['why'];
			}
			$text .= "\n\nSKIPPED STEPS\nThese steps were left out because the tool is not available for your role or not installed on this store:\n"
				. implode( "\n", $lines )
				. "\nDo not try to run them. Work with the remaining data, and tell the merchant in one short sentence which checks were skipped and that a store administrator can see them.";
		}

		return $text;
	}
}
