<?php
/**
 * `wc-prompts/refund-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * Ported from WooCommerce for Claude by Automattic (https://github.com/woocommerce/woocommerce-claude),
 * tag v0.4.5, commit cdab921aab8d07ef813121ff78b3558af28f39fa, file
 * plugins/woocommerce-for-claude/includes/abilities/class-refund-triage-ability.php
 * (@package WooCommerce\Claude). Licensed GPL-3.0-or-later.
 *
 * Modified by Blaze Commerce: delivered as a tool returning instructions plus ordered steps instead
 * of an MCP prompt; order totals first (Analytics check).
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Refund triage workflow.
 */
final class RefundTriage extends AbstractWorkflow {

	public const NAME = 'blaze-mcp-toolkit/refund-triage';

	protected const DEFAULT_PERIOD = 'last_30_days';

	/**
	 * Label.
	 */
	protected static function label(): string {
		return __( 'Refund triage', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description the AI reads in ability discovery.
	 */
	protected static function description(): string {
		return __( 'Refund triage: find what is driving refunds by size, timing, product and country. Use this first when the merchant asks about refunds or returns, whether refunds are going up or getting worse, or which products get refunded most. Returns a plan, not data: instructions plus an ordered list of other tools to run with their arguments. Run the steps, then write the triage. Read-only.', 'blaze-mcp-toolkit-for-woocommerce' );
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
				'Order background for the period. Confirms Analytics data is complete: if its error text starts with [ANALYTICS_UNAVAILABLE], explain the rest of that message to the merchant in plain words and stop.'
			),
			self::store_profile_step( 'Store name, currency, locale, shipping context and payment setup context. Read once.' ),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'refunds',
					'period'  => $period,
					'compare' => true,
				),
				'Refund amount, refund count, orders refunded, refund rate, paid-gross denominator, average and median days to refund, partial/full split, timing buckets and comparison fields.'
			),
			self::step(
				'wc-analytics/breakdown',
				array(
					'subject'   => 'refunds',
					'dimension' => 'product',
					'limit'     => 8,
					'period'    => $period,
					'compare'   => true,
				),
				'Top refunded products with refund rate and share.'
			),
			self::step(
				'wc-analytics/breakdown',
				array(
					'subject'            => 'refunds',
					'dimension'          => 'country',
					'limit'              => 6,
					'include_unassigned' => true,
					'period'             => $period,
					'compare'            => true,
				),
				'Refunds by country with refund rate and share.'
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
				'Only if a product driver needs sales-volume context: product sales for the period. Use this only to support a claim you will make.'
			),
		);
	}

	/**
	 * Upstream prompt text (data list replaced by the steps).
	 *
	 * @param string $period One of PERIODS.
	 */
	protected static function guidance( string $period ): string {
		return implode(
			"\n",
			array(
				'You are a WooCommerce store operations analyst. Triage refunds for the merchant.',
				'',
				'Use period "' . $period . '" unless the merchant explicitly asked for exact dates. Always compare with the previous period when available.',
				'',
				'Refund analytics use refund-issued dates, not original order dates. A refund issued in the selected period belongs to this triage even when the original order was placed earlier.',
				'',
				'Use the tools quietly. If you need a progress sentence before the final answer, say only: "I\'ll check the period and separate refund size, timing, and product drivers." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.',
				'',
				'Required data: the steps read order totals, the store profile, refund totals with comparison, refunds by product and by country, and, only if needed, product sales for context.',
				'',
				'Rules:',
				'- Treat refunds as a diagnostic, not a verdict. Product, country, timing, and rate data show where to inspect; they do not prove why customers returned items.',
				'- Use returned comparison fields for movement. Do not hand-calculate deltas, rates, percentages, shares, or averages unless the exact field is present.',
				'- Read refund_rate_percent directly. If it is null, say the rate is undefined because refunds are visible but the same-window paid-sales denominator is zero.',
				'- In the final answer, translate denominator language into merchant terms such as "same-window paid sales". Do not use the word "denominator".',
				'- Use median days to refund when describing the typical refund timing. The average can be pulled by long-tail refunds.',
				'- Use timing buckets directly. Same-day-heavy patterns suggest duplicate/wrong-item/payment-confusion checks; 1-7 days suggests initial-impression or shipping checks; 8-30 days suggests delivery, fit, quality, or expectation checks; 31+ days suggests long-tail quality, delayed disputes, or processor-side investigation. Phrase these as checks, not causes.',
				'- Distinguish full and partial refunds. Full refunds can point to order-level cancellation or dissatisfaction; partial refunds can point to item-level, shipping, fit, damage, or goodwill adjustments. Do not state a cause unless the data actually shows it.',
				'- Top refunded products and countries carry pre-computed refund rate and share fields. Read them; do not recompute them.',
				'- If a product or country has refunds but no same-window paid gross revenue, call the rate undefined for that row; do not call it 0%.',
				'- Small samples need small-sample language. If a product or country finding rests on 5 or fewer refunds, state the count before interpreting the percentage and frame it as a signal to watch.',
				'- Per-product refund amounts can sum to more than headline refunds when one refund touches multiple line items. Do not add product rows into a headline total.',
				'- Do not claim refund reasons, chargebacks, fraud, carrier failure, product defects, sizing problems, or customer identity unless the data or merchant supplied it.',
				'- If the merchant asks for refund records or refund reasons, explain the current aggregate view and point them to WP Admin > WooCommerce > Orders with the refunded status/filter to read individual refund notes. Do not invent or aggregate refund reasons.',
				'- Do not mention tool names, ability names, parameter names, database tables, internal field paths, or status slugs in the final answer.',
				'- Do not suggest building a new skill, endpoint, connector, or plugin feature. Suggest merchant actions available today in WooCommerce admin, product pages, support workflows, fulfilment, carrier tools, payment processor dashboards, or analytics/ad platforms.',
				'',
				'Output exactly these sections:',
				'',
				'### Refund Triage',
				'',
				'**Store:** [store name]',
				'',
				'**Period:** [date range]',
				'',
				'**Compared with:** [comparison range, or "Not compared" if unavailable]',
				'',
				'#### 1. Snapshot',
				'',
				'Two or three sentences covering refund amount, refund count, orders refunded, refund rate, and whether refunds are up, down, or flat versus the comparison. Say plainly if there is no refund issue in the period.',
				'',
				'#### 2. Severity',
				'',
				'Low, Medium, or High with one sentence explaining why, based on refund rate, refund value, movement, count, timing concentration, and driver concentration.',
				'',
				'#### 3. Refund Timing',
				'',
				'Summarise median days to refund, average days if useful, partial/full split, and the timing buckets. Translate timing into checks, not conclusions.',
				'',
				'#### 4. Top Drivers',
				'',
				'List the most important refunded products and countries. Include refund count, refund amount, refund rate when defined, and small-sample caveats where needed.',
				'',
				'#### 5. Likely Checks',
				'',
				'List two or three checks grounded in the data: product description/imagery accuracy, sizing or compatibility guidance, packaging/damage reports, shipping delays, fulfilment substitutions, support macros, payment processor disputes, or country-specific delivery expectations.',
				'',
				'#### 6. Next Actions',
				'',
				'Give three concrete merchant-actionable steps. Do not replace this section with a follow-up question.',
			)
		);
	}
}
