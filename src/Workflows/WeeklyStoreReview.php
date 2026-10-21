<?php
/**
 * `wc-prompts/weekly-store-review` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * Ported from WooCommerce for Claude by Automattic (https://github.com/woocommerce/woocommerce-claude),
 * tag v0.4.5, commit cdab921aab8d07ef813121ff78b3558af28f39fa, file
 * plugins/woocommerce-for-claude/includes/abilities/class-weekly-store-review-ability.php
 * (@package WooCommerce\Claude). Licensed GPL-3.0-or-later.
 *
 * Modified by Blaze Commerce: delivered as a tool returning instructions plus ordered steps instead
 * of an MCP prompt; order totals first (Analytics check); site health and update history steps and
 * the rule linking dips to updates or errors in the same window.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Workflows;

use Blaze\McpToolkit\Abilities\HealthSummary;
use Blaze\McpToolkit\Abilities\UpdateHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Weekly store review workflow.
 */
final class WeeklyStoreReview extends AbstractWorkflow {

	public const NAME = 'blaze-mcp-toolkit/weekly-store-review';

	protected const DEFAULT_PERIOD = 'last_7_days';

	/**
	 * Label.
	 */
	protected static function label(): string {
		return __( 'Weekly store review', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description the AI reads in ability discovery.
	 */
	protected static function description(): string {
		return __( 'Weekly store review: produce a weekly WooCommerce performance review covering revenue, orders, customers, products, channels, refunds, and next actions. Use this first when the merchant asks how the store did this week (or over another recent period), wants a weekly review or report, or asks how sales are going. Returns a plan, not data: instructions plus an ordered list of other tools to run with their arguments, including site errors and recent plugin or theme updates. Run the steps, then write the review. Read-only.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Steps.
	 *
	 * @param string $period One of PERIODS.
	 * @return array<int, array{tool: string, arguments: array<string, mixed>, why: string}>
	 */
	protected static function plan( string $period ): array {
		return array(
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'orders',
					'period'  => $period,
					'compare' => true,
				),
				'Orders, status mix and pipeline versus the previous period. Also confirms Analytics data is complete: if its error text starts with [ANALYTICS_UNAVAILABLE], explain the rest of that message to the merchant in plain words and stop.'
			),
			self::store_profile_step( 'Store name, currency, locale, payment methods and shipping context. Read once.' ),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'revenue',
					'period'  => $period,
					'compare' => true,
				),
				'Collected revenue, average order value and comparison fields.'
			),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'customers',
					'period'  => $period,
					'compare' => true,
				),
				'Customer count and new/returning mix versus the previous period.'
			),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'refunds',
					'period'  => $period,
					'compare' => true,
				),
				'Refund amount and rate versus the previous period. Do not skip this.'
			),
			self::step(
				'wc-analytics/breakdown',
				array(
					'subject'   => 'products',
					'dimension' => 'product',
					'limit'     => 5,
					'period'    => $period,
					'compare'   => true,
				),
				'Top five products and their movement.'
			),
			self::step(
				'wc-analytics/breakdown',
				array(
					'subject'            => 'attribution',
					'dimension'          => 'channel',
					'limit'              => 6,
					'include_unassigned' => true,
					'period'             => $period,
					'compare'            => true,
				),
				'Sales channel mix, including unassigned/direct orders.'
			),
			self::step(
				HealthSummary::NAME,
				array( 'since_hours' => self::window_hours( $period ) ),
				'Site errors and failed background jobs in the same window (error history covers at most the last 7 days).'
			),
			self::step(
				UpdateHistory::NAME,
				array( 'days' => self::window_days( $period ) ),
				'Plugin, theme and core updates in the same window.'
			),
		);
	}

	/**
	 * Upstream prompt text (data list replaced by the steps) plus Blaze rules.
	 *
	 * @param string $period One of PERIODS.
	 */
	protected static function guidance( string $period ): string {
		return implode(
			"\n",
			array(
				'You are a WooCommerce store operations analyst. Produce a merchant-friendly weekly store review.',
				'',
				'Use period "' . $period . '" unless the merchant explicitly asked for exact dates. Always compare with the previous period.',
				'',
				'Use the tools quietly. Do not tell the merchant you are loading schemas, selecting tools, making parallel calls, or pulling data through a named connector.',
				'',
				'Required data: the steps read order totals, the store profile, revenue, customer and refund totals (all compared with the previous period), the top five products, the channel mix, site errors and recent updates.',
				'',
				'Rules:',
				'- Only report numbers returned by tools. Do not invent targets, forecasts, margins, conversion rates, sessions, ad spend, ROAS, or customer identities.',
				'- Use returned comparison fields for movement. Do not hand-calculate deltas or percentages unless the exact field is present in the response.',
				'- Keep customer details pseudonymised.',
				'- Treat small samples carefully. If a percentage is driven by 5 or fewer events, say the sample is small before interpreting it.',
				'- Do not describe failed or on-hold order value as lost revenue. It is checkout risk or payment pipeline unless the data says it is unrecoverable.',
				'- Attribution is revenue source context, not ROAS. If the merchant asks about ROAS, say ad cost is needed from a connected ad platform.',
				'- Do not mention tool names, ability names, parameter names, database tables, or internal slugs in the final answer.',
				'- Site errors and updates: if revenue, orders or customers dipped, check whether a plugin, theme or core update, or a rise in site errors or failed background jobs, happened in the same window, and mention any overlap as something worth checking. Never claim an update or error caused a dip unless both the dip and the update or error are in the returned data, and even then call it a possible link, not a proven cause. If neither shows anything notable, do not mention them beyond one line in the Watch List.',
				'',
				'Output exactly these sections:',
				'',
				'### Weekly Store Review',
				'',
				'**Store:** [store name]',
				'',
				'**Period:** [date range]',
				'',
				'**Compared with:** [comparison range]',
				'',
				'#### 1. Headline',
				'',
				'Two or three sentences covering collected revenue, orders, average order value, customer count, and refund rate. Lead with the most important movement.',
				'',
				'#### 2. What changed',
				'',
				'Three bullets maximum. Focus on material changes in revenue, order volume, AOV, customer mix, pipeline/on-hold orders, or refunds.',
				'',
				'#### 3. Products',
				'',
				'Name the top products and meaningful movement. Flag concentration risk if one product dominates the week.',
				'',
				'#### 4. Channels',
				'',
				'Summarise the leading channels and any notable mix shift. If unassigned/direct traffic is large, explain that tracking may need attention without overstating the cause.',
				'',
				'#### 5. Watch List',
				'',
				'List up to three issues worth checking, including refunds or low refund risk, and site errors or recent updates that overlap a dip.',
				'',
				'#### 6. Next Actions',
				'',
				'Give three concrete merchant-actionable steps. Do not replace this section with a follow-up question.',
			)
		);
	}
}
