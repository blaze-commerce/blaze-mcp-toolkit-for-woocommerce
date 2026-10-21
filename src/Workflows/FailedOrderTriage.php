<?php
/**
 * `wc-prompts/failed-order-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * Ported from WooCommerce for Claude by Automattic (https://github.com/woocommerce/woocommerce-claude),
 * tag v0.4.5, commit cdab921aab8d07ef813121ff78b3558af28f39fa, file
 * plugins/woocommerce-for-claude/includes/abilities/class-failed-order-triage-ability.php
 * (@package WooCommerce\Claude). Licensed GPL-3.0-or-later.
 *
 * Modified by Blaze Commerce: delivered as a tool returning instructions plus ordered steps instead
 * of an MCP prompt; order totals first (Analytics check); failed orders exceeding paid orders is led
 * with as a likely card-testing or monitoring signal.
 *
 * @package Blaze\McpToolkit
 */

declare( strict_types=1 );

namespace Blaze\McpToolkit\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * Failed and on-hold order triage workflow.
 */
final class FailedOrderTriage extends AbstractWorkflow {

	public const NAME = 'blaze-mcp-toolkit/failed-order-triage';

	protected const DEFAULT_PERIOD = 'last_30_days';

	/**
	 * Label.
	 */
	protected static function label(): string {
		return __( 'Failed order triage', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Description the AI reads in ability discovery.
	 */
	protected static function description(): string {
		return __( 'Failed and on-hold order triage: separate on-hold payment pipeline from failed checkout risk and list the orders to chase first. Use this first when the merchant asks about failed orders or failed payments, stuck, unpaid or on-hold orders, checkout failures, declined cards or possible card testing. Returns a plan, not data: instructions plus an ordered list of other tools to run with their arguments. Run the steps, then write the triage. Read-only.', 'blaze-mcp-toolkit-for-woocommerce' );
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
				'Status breakdown, pipeline diagnostic, comparison dates and the paid-order count. Also confirms Analytics data is complete: if its error text starts with [ANALYTICS_UNAVAILABLE], explain the rest of that message to the merchant in plain words and stop.'
			),
			self::store_profile_step( 'Store name, currency, locale and payment setup context. Read once.' ),
			self::step( 'wc-analytics/rows', self::status_args( $period, 'on-hold', 'aggregate' ), 'On-hold order count and value for the period.' ),
			self::step( 'wc-analytics/rows', self::status_args( $period, 'failed', 'aggregate' ), 'Failed order count and value for the period.' ),
			self::step(
				'wc-analytics/rows',
				self::status_args( $period, 'on-hold', 'rows' ) + array( 'order' => 'ASC' ),
				'Only if on-hold orders exist: the ten oldest on-hold orders for the priority queue.'
			),
			self::step(
				'wc-analytics/rows',
				self::status_args( $period, 'failed', 'rows' ) + array( 'order' => 'DESC' ),
				'Only if failed orders exist: the ten most recent failed orders for the priority queue.'
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
				'Only if on-hold value is material, a card gateway appears in the on-hold payment-method diagnostic, or the merchant asks where stuck orders came from: channel mix and pipeline over-index.'
			),
		);
	}

	/**
	 * Arguments for an order-rows call filtered on one status.
	 *
	 * @param string $period Period.
	 * @param string $status Order status (merchant-friendly, no wc- prefix).
	 * @param string $mode   aggregate or rows.
	 * @return array<string, mixed>
	 */
	private static function status_args( string $period, string $status, string $mode ): array {
		$args = array(
			'entity'  => 'orders',
			'mode'    => $mode,
			'period'  => $period,
			'filters' => array(
				array(
					'field'    => 'status',
					'operator' => 'is',
					'value'    => $status,
				),
			),
		);
		if ( 'rows' === $mode ) {
			$args['limit']   = 10;
			$args['orderby'] = 'date_created';
		}

		return $args;
	}

	/**
	 * Upstream prompt text (data list replaced by the steps) plus the Blaze card-testing rule.
	 *
	 * @param string $period One of PERIODS.
	 */
	protected static function guidance( string $period ): string {
		return implode(
			"\n",
			array(
				'You are a WooCommerce store operations analyst. Triage failed and on-hold orders for the merchant.',
				'',
				'Use period "' . $period . '" unless the merchant explicitly asked for exact dates. Use the headline order overview with comparison enabled only to get the comparison dates plus status/pipeline diagnostics; do not quote its paid-order or paid-revenue comparison in the final answer.',
				'',
				'Use the tools quietly. If you need a progress sentence before the final answer, say only: "I\'ll check the period and separate on-hold payment pipeline from failed checkout risk." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.',
				'',
				'Required data: the steps read the order overview (status breakdown and pipeline diagnostic; treat the current paid-order count as background only, except for the card-testing check below), the store profile, on-hold and failed order aggregates, the oldest on-hold and most recent failed orders, and, when relevant, the channel mix.',
				'If the order overview returns a comparison range, also run wc-analytics/rows twice more with the same arguments as the two aggregate steps, but with date_start and date_end set to the exact comparison dates (YYYY-MM-DD) in place of period: once with the on-hold status filter and once with the failed status filter. Use these only for current-vs-prior count/value wording; do not compute percentage changes, rates, or shares unless a tool returned them.',
				'',
				'Card-testing check (this overrides the paid-order rules below): if the number of failed orders in the period is greater than the number of paid orders in the period, lead the Snapshot with that, quote both counts side by side (for example "120 failed orders versus 15 paid orders"), and say it is a likely sign of card testing (bots trying stolen card numbers) or of a checkout or payment-gateway fault worth checking now, not proof of either. Make reviewing the gateway\'s fraud and decline logs and turning on checkout protection (for example a CAPTCHA or the gateway\'s fraud rules) part of Next Actions.',
				'',
				'Rules:',
				'- Treat on-hold orders as payment pipeline, not failed revenue. Manual methods such as bank transfer, BACS, cheque, and invoice can legitimately sit on-hold while payment clears.',
				'- Treat card-gateway on-hold orders as abnormal because card gateways should usually complete or fail at checkout.',
				'- Treat failed orders as checkout risk, not confirmed lost revenue.',
				'- Use returned comparison fields, age buckets, payment-method diagnostics, and pipeline over-index fields directly. For status-specific on-hold and failed comparisons, read the current and prior aggregate calls side by side and state count/value movement plainly. Do not hand-calculate percentage changes, rates, shares, averages, or deltas.',
				'- When comparing current and prior status buckets, use neutral wording such as "larger", "smaller", or "similar" and quote both values. Avoid magnitude labels such as "slight", "modest", "meaningfully", "roughly flat", "broadly flat", "sharp", or "surging" unless a returned comparison field supplies that judgement.',
				'- Do not turn failed/on-hold counts or values into a share of paid orders or paid revenue by combining separate calls. Say "43 failed orders versus 39 last period" rather than "the failed rate fell to 10%" unless that rate is returned directly.',
				'- Do not compare failed/on-hold movement against paid-order growth. Avoid claims like "failed orders are a smaller share of the order book", "the failed-order share improved", "10% of paid orders versus 14.7%", "paid orders grew", "paid orders grew alongside this", "paid orders also grew", or "paid orders grew sharply, so the issue is less severe". The triage is about the failed/on-hold buckets themselves. The only exception is the card-testing check above.',
				'- Do not compute differences such as "up $24.7k", "+4 orders", or "average basket is 33 items" from separate returned fields. Say "28 orders / $77,990 versus 22 orders / $53,300 last period" instead of doing the arithmetic in prose. Only quote averages that are explicitly returned as average fields.',
				'- Do not sum or calculate shares from the Priority Queue rows. Avoid claims like "these four orders account for $17,200" or "22% of stuck value sits in four rows" unless the tool returned that exact aggregate. The queue is for action ordering, not extra arithmetic.',
				'- Do not sum paid revenue, on-hold value, failed order value, and dashboard-matching figures.',
				'- Keep customer details pseudonymised. Order refs and admin links are OK; customer names, emails, phone numbers, and addresses are not.',
				'- If a finding rests on 5 or fewer orders, state the count before interpreting it.',
				'- Failed order rows do not expose payment method by default. Do not claim a gateway caused failed orders unless the data shows it or the merchant supplied a gateway-specific filter.',
				'- Do not call failed orders "routine", "normal", "background checkout noise", or "expected checkout attrition" unless a returned comparison or merchant-supplied baseline supports that wording. Prefer "no single cause is visible in the available data" when the signal is thin.',
				'- Treat store-profile payment methods as setup context only. An empty configured-methods list does not prove no payment methods are configured and does not explain unassigned order payment methods by itself. Phrase it as "the profile did not expose configured payment methods, so confirm settings manually" rather than a cause.',
				'- Treat analytics "unassigned payment method" as a visibility gap until the merchant checks actual order pages. Do not tell the merchant to "fix payment-method labelling", "ensure each order has a payment method recorded", or "label it correctly" as a conclusion. Say "verify the order page, then troubleshoot the gateway/manual method if it is blank there too."',
				'- If every on-hold payment method is blank, do not treat "no card gateway is visible" as reassuring. Say the gateway/manual-method split cannot be read until at least one payment method is visible on the actual order page or in gateway logs.',
				'- Do not assume a standard clearing window for manual payments. Phrase stale on-hold orders as "worth checking against your payment terms" rather than "past the point a bank transfer would normally clear" unless the merchant supplied that policy.',
				'- Do not mention tool names, ability names, parameter names, database tables, status slugs, or internal field paths in the final answer.',
				'',
				'Output exactly these sections:',
				'',
				'### Failed and On-Hold Order Triage',
				'',
				'**Store:** [store name]',
				'',
				'**Period:** [date range]',
				'',
				'**Compared with:** [comparison range, or "Not compared" if unavailable]',
				'',
				'#### 1. Snapshot',
				'',
				'Two or three sentences covering on-hold orders, failed orders, and whether those buckets are growing, shrinking, or flat. You may mention only the current paid-order count as neutral background, never paid-order growth, a denominator, a share, or a reason to downgrade the issue. If failed orders exceed paid orders, lead with the card-testing check above.',
				'',
				'#### 2. Severity',
				'',
				'Low, Medium, or High with one sentence explaining why, based on counts, value, movement, stale age buckets, and card-gateway on-hold signals.',
				'',
				'#### 3. On-Hold Pipeline',
				'',
				'Summarise count/value, oldest age, age buckets, and payment-method signals. Separate expected manual-payment clearing from abnormal gateway behaviour.',
				'',
				'#### 4. Failed Orders',
				'',
				'Summarise failed order count/value and whether it changed from the comparison period. Keep cause language cautious unless data supports it.',
				'',
				'#### 5. Priority Queue',
				'',
				'List up to five orders to chase first. If both on-hold and failed rows exist, list up to four oldest on-hold rows first, preserving the row order returned by the oldest-on-hold rows call, then one most recent failed row. If fewer than four on-hold rows exist, fill the remaining slots with the most recent failed rows. Do not skip an older on-hold row to include a second failed row, and do not reshuffle same-day on-hold rows by value or item count. Use order links, dates, statuses, and values. Do not call failed rows "highest-value" unless you explicitly sorted them by value. Do not include customer names or addresses. Do not add a calculated total or percentage beneath the queue. The specific orders named later in Next Actions must come from this queue, unless you explicitly say you are expanding beyond the queue.',
				'',
				'#### 6. Likely Checks',
				'',
				'List two or three checks grounded in the data: payment gateway logs, bank-transfer instructions, webhook health, fraud rules, stock holds, abandoned customer follow-up, or channel-specific tracking.',
				'',
				'#### 7. Next Actions',
				'',
				'Give three concrete merchant-actionable steps. Do not replace this section with a follow-up question.',
			)
		);
	}
}
