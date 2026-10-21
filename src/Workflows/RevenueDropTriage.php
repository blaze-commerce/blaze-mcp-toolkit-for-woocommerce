<?php
/**
 * `wc-prompts/revenue-drop-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * Ported from WooCommerce for Claude by Automattic (https://github.com/woocommerce/woocommerce-claude),
 * tag v0.4.5, commit cdab921aab8d07ef813121ff78b3558af28f39fa, file
 * plugins/woocommerce-for-claude/includes/abilities/class-revenue-drop-triage-ability.php
 * (@package WooCommerce\Claude). Licensed GPL-3.0-or-later.
 *
 * Modified by Blaze Commerce: delivered as a tool returning instructions plus ordered steps instead
 * of an MCP prompt; order totals first (Analytics check); update history and site health steps over
 * the drop window.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Workflows;

use Blaze\McpToolkit\Abilities\HealthSummary;
use Blaze\McpToolkit\Abilities\UpdateHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Revenue drop triage workflow.
 */
final class RevenueDropTriage extends AbstractWorkflow {

	public const NAME = 'blaze-mcp-toolkit/revenue-drop-triage';

	protected const DEFAULT_PERIOD = 'last_30_days';

	/**
	 * Label.
	 */
	protected static function label(): string {
		return __( 'Revenue drop triage', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description the AI reads in ability discovery.
	 */
	protected static function description(): string {
		return __( 'Revenue drop triage: diagnose WooCommerce revenue drops by separating order volume, AOV, customers, refunds, products, channels, and next merchant actions. Use this first when the merchant asks why sales or revenue are down, dropped or slow, or whether there is a real drop. Returns a plan, not data: instructions plus an ordered list of other tools to run with their arguments, including recent plugin or theme updates and site errors over the same window. Run the steps, then write the triage. Read-only.', 'blaze-mcp-toolkit-for-woocommerce' );
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
				'Paid orders, average order value, status breakdown, pending/on-hold pipeline, failed-order count, value distribution and comparison fields. Also confirms Analytics data is complete; stop if it returns blzmcp_analytics_unavailable.'
			),
			self::store_profile_step( 'Store name, currency, locale, payment setup, shipping context and store geography. Read once.' ),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'revenue',
					'period'  => $period,
					'compare' => true,
				),
				'Collected revenue, orders count, average order value, refunds, total customers, items sold, pending revenue, dashboard-matching revenue if needed, and comparison fields.'
			),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'customers',
					'period'  => $period,
					'compare' => true,
				),
				'Total customers, new/returning split, repeat rate, segment revenue/AOV, pipeline customers and comparison fields.'
			),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'refunds',
					'period'  => $period,
					'compare' => true,
				),
				'Decides whether refunds explain a net-sales drop.'
			),
			self::step(
				'wc-analytics/breakdown',
				array(
					'subject'   => 'products',
					'dimension' => 'product',
					'limit'     => 8,
					'period'    => $period,
					'compare'   => true,
				),
				'Top products, per-product change fields, dropped-out top products, catalogue coverage, stock status, pipeline revenue and refunds.'
			),
			self::step(
				'wc-analytics/breakdown',
				array(
					'subject'            => 'attribution',
					'dimension'          => 'channel',
					'limit'              => 8,
					'include_unassigned' => true,
					'period'             => $period,
					'compare'            => true,
				),
				'Paid revenue, order count, new/returning customers, attribution coverage, direct/unassigned share, per-channel changes, dropped-out channels and pipeline over-index fields.'
			),
			self::step(
				UpdateHistory::NAME,
				array( 'days' => self::window_days( $period ) ),
				'Plugin, theme and core updates during the drop window.'
			),
			self::step(
				HealthSummary::NAME,
				array( 'since_hours' => self::window_hours( $period ) ),
				'Site errors and failed background jobs during the drop window (error history covers at most the last 7 days).'
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
				'You are a WooCommerce store operations analyst. Triage a possible revenue drop for the merchant.',
				'',
				'Use period "' . $period . '" unless the merchant explicitly asked for exact dates. Always compare with the previous matching period when available.',
				'',
				'Use the tools quietly. If you need a progress sentence before the final answer, say only: "I\'ll compare the period with the previous one and separate volume, basket size, refunds, products, and channels." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.',
				'',
				'Required data: the steps read order, revenue, customer and refund totals (all compared with the previous period), the store profile, the top eight products, the channel mix, and recent updates and site errors over the same window.',
				'Optional only if it supports a claim you will make: revenue by country for regional shifts (wc-analytics/breakdown with subject=revenue, dimension=country), revenue by payment method for gateway/pipeline signals (subject=revenue, dimension=payment_method), or coupons if discounting/campaign cost is central (subject=coupons, dimension=code). Use the same period and compare=true.',
				'',
				'Rules:',
				'- Lead with collected revenue: paid orders, net of refunds. Keep pending/on-hold revenue separate and never describe it as lost revenue.',
				'- First decide whether there is a real drop. If collected revenue is flat or up versus the comparison, say so plainly and pivot to softer underlying signals instead of forcing a decline narrative.',
				'- Separate the levers: order volume, average order value, customer count/mix, refunds, pending pipeline, product mix, and channel mix.',
				'- Use returned comparison fields for movement. Do not hand-calculate deltas, percentages, shares, averages, or ratios unless the exact field is present.',
				'- Product and channel rows carry pre-computed change fields and dropped-out lists. Use those directly; do not infer movement from row order alone.',
				'- Treat attribution as order revenue source context, not marketing performance. Do not claim ad spend, ROAS, sessions, conversion rate, impressions, click-through rate, or campaign efficiency without a connected ad/analytics source.',
				'- If attribution coverage is low, frame channel conclusions as partial and make tracking hygiene one of the checks.',
				'- Refunds can explain a net-sales decline when refund value or refund rate moved materially. Phrase refunds as diagnostic pressure, not proof of product defects or customer dissatisfaction.',
				'- On-hold and failed orders are pipeline or checkout risk. They can explain why revenue has not landed yet, but they are not confirmed lost revenue.',
				'- Product findings are sales signals, not causal proof. A top product dropping out, low stock status, or concentration shift tells the merchant where to inspect pricing, stock, merchandising, product content, fulfilment, or promotion timing.',
				'- Customer mix shifts need plain language: fewer new customers suggests acquisition softness; fewer returning customers suggests retention or repeat-purchase softness; lower AOV suggests basket-size, discounting, or mix checks. Phrase as checks, not conclusions.',
				'- Do not describe customer movement as churn, churn risk, or no churn. This workflow has repeat-rate and customer-mix actuals, not churn prediction.',
				'- Small samples need small-sample language. If a driver rests on 5 or fewer orders/refunds/customers, state the count before interpreting the percentage.',
				'- Do not invent competitor effects, seasonality, ad budget changes, stockouts, pricing changes, email-send gaps, search ranking changes, or fulfilment problems unless the merchant supplies them or the returned data contains the signal.',
				'- Updates and site errors: if a plugin, theme or core update, or a rise in site errors or failed background jobs, falls inside the drop window, list it under Likely Checks as something that happened at the same time. Never claim it caused the drop unless both the drop and the update or error are in the returned data, and even then call it a possible link, not a proven cause.',
				'- Do not mention tool names, ability names, parameter names, database tables, internal field paths, or status slugs in the final answer.',
				'- Do not suggest building a new skill, endpoint, connector, or plugin feature. Suggest merchant actions available today in WooCommerce admin, product pages, marketing tools, fulfilment/support workflows, payment processor dashboards, carrier tools, or analytics/ad platforms.',
				'',
				'Output exactly these sections:',
				'',
				'### Revenue Drop Triage',
				'',
				'**Store:** [store name]',
				'',
				'**Period:** [date range]',
				'',
				'**Compared with:** [comparison range, or "Not compared" if unavailable]',
				'',
				'#### 1. Snapshot',
				'',
				'Two or three sentences covering collected revenue movement, order count, average order value, customers, and whether the headline is truly a drop. If revenue is not down, say that clearly.',
				'',
				'#### 2. Severity',
				'',
				'Low, Medium, or High with one sentence explaining why, based on revenue movement, order/customer movement, refund pressure, pipeline size, and concentrated product/channel drivers.',
				'',
				'#### 3. Drop Drivers',
				'',
				'List the main levers that moved: order volume, basket size, customer mix, refunds, and pending pipeline. Use only returned comparison fields and keep it to the two or three highest-signal drivers.',
				'',
				'#### 4. Product and Channel Signals',
				'',
				'Name the products and channels that most help explain the movement. Include dropped-out top products/channels when relevant, product links when available, and attribution-coverage caveats when needed.',
				'',
				'#### 5. Likely Checks',
				'',
				'List two or three checks grounded in the data: stock availability, product page/content changes, pricing or discount timing, campaign/tagging health, email cadence, payment gateway health, fulfilment delays, refund/support patterns, or site updates and errors in the same window.',
				'',
				'#### 6. Next Actions',
				'',
				'Give three concrete merchant-actionable steps. Do not replace this section with a follow-up question.',
			)
		);
	}
}
