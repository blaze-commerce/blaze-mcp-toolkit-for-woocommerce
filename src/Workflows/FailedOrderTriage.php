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
 * of an MCP prompt; payment health first, then the card-testing check, then order totals (Analytics
 * check). Whether failed orders look like card testing comes only from the card-testing check's own
 * verdict, never from comparing failed and paid order counts (1.3).
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
		return __( 'Failed and on-hold order triage: separate on-hold payment pipeline from failed checkout risk and list the orders to chase first. Use this first when the merchant asks about failed orders or failed payments, stuck, unpaid or on-hold orders, checkout failures or declined cards. Returns a plan, not data: instructions plus an ordered list of other tools to run with their arguments, starting with a payment health check and then a card-testing check. Run the steps, then write the triage. Read-only.', 'blaze-mcp-toolkit-for-woocommerce' );
	}

	/**
	 * Steps.
	 *
	 * @param string $period One of PERIODS.
	 * @return array<int, array{tool: string, ability: string, arguments: array<string, mixed>, why: string}>
	 */
	protected static function plan( string $period ): array {
		return array(
			self::payment_health_step( $period ),
			self::card_testing_step( $period ),
			self::step(
				'wc-analytics/totals',
				array(
					'subject' => 'orders',
					'period'  => $period,
					'compare' => true,
				),
				'Status breakdown, pipeline diagnostic and comparison dates. Also confirms Analytics data is complete: if its error text starts with [ANALYTICS_UNAVAILABLE], explain the rest of that message to the merchant in plain words and skip the other Analytics steps; still run the rest.'
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
	 * Upstream prompt text (data list replaced by the steps) plus the Blaze card-testing and payment
	 * rules.
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
				'Card testing and payment health come first. If the card-testing check returned likely_attack or possible_attack, open the Snapshot with it, as the CARD TESTING CHECK above says. Then, if payment health returned red or amber findings (a payment method that stopped taking orders, unpaid checkouts above the method\'s usual share, payment references on unpaid orders, or payment errors), report them before the on-hold and failed buckets, as separate findings. An unknown card-testing or payment check is a gap to mention, never a sign that checkout or payments are fine. Payment health counts are orders accepted, unpaid or failed at checkout, not collected revenue or confirmed charges. A payment method marked inventory_only was not assessed by payment health: mention it as a method to confirm, never as a failed checkout or a declined payment, and never infer card testing from it. Only the card-testing check\'s own result can say that failed orders look like card testing, including its possible result for orders with no record of where they came from, which is reported as the CARD TESTING CHECK above says. If Analytics figures are unavailable, still report the card-testing and payment findings and say the on-hold and failed buckets could not be read.',
				'',
				'Required data: the steps read payment health, the card-testing check, the order overview (status breakdown and pipeline diagnostic; treat the current paid-order count as background only), the store profile, on-hold and failed order aggregates, the oldest on-hold and most recent failed orders, and, when relevant, the channel mix.',
				'If the order overview returns a comparison range, also run {tool:wc-analytics/rows} twice more with the same arguments as the two aggregate steps, but with date_start and date_end set to the exact comparison dates (YYYY-MM-DD) in place of period: once with the on-hold status filter and once with the failed status filter. Use these only for current-vs-prior count/value wording; do not compute percentage changes, rates, or shares unless a tool returned them.',
				'',
				'Card testing: only the card-testing check says whether failed orders look like card testing. Never decide it yourself from the failed and paid order counts, the status breakdown or the failed-order rows, and never call failed orders card testing when that check returned no_sign, unknown or did not run. If it returned likely_attack or possible_attack, make its check_next and the check_next of each checkout path whose protection is not_configured or unknown part of Next Actions.',
				'',
				'Rules:',
				'- Treat on-hold orders as payment pipeline, not failed revenue. Manual methods such as bank transfer, BACS, cheque, and invoice can legitimately sit on-hold while payment clears.',
				'- Treat card-gateway on-hold orders as worth checking: most card gateways complete or fail at checkout, but some legitimately hold orders while a payment is authorised and captured later. Check the gateway\'s capture setting before calling it a fault.',
				'- Treat failed orders as checkout risk, not confirmed lost revenue.',
				'- Use returned comparison fields, age buckets, payment-method diagnostics, and pipeline over-index fields directly. For status-specific on-hold and failed comparisons, read the current and prior aggregate calls side by side and state count/value movement plainly. Do not hand-calculate percentage changes, rates, shares, averages, or deltas.',
				'- When comparing current and prior status buckets, use neutral wording such as "larger", "smaller", or "similar" and quote both values. Avoid magnitude labels such as "slight", "modest", "meaningfully", "roughly flat", "broadly flat", "sharp", or "surging" unless a returned comparison field supplies that judgement.',
				'- Do not turn failed/on-hold counts or values into a share of paid orders or paid revenue by combining separate calls. Say "43 failed orders versus 39 last period" rather than "the failed rate fell to 10%" unless that rate is returned directly.',
				'- Do not compare failed/on-hold movement against paid-order growth. Avoid claims like "failed orders are a smaller share of the order book", "the failed-order share improved", "10% of paid orders versus 14.7%", "paid orders grew", "paid orders grew alongside this", "paid orders also grew", or "paid orders grew sharply, so the issue is less severe". The triage is about the failed/on-hold buckets themselves.',
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
				'Two or three sentences covering on-hold orders, failed orders, and whether those buckets are growing, shrinking, or flat. You may mention only the current paid-order count as neutral background, never paid-order growth, a denominator, a share, or a reason to downgrade the issue. Open with a likely or possible card-testing result, if the card-testing check returned one; then any red or amber payment health findings.',
				'',
				'#### 2. Severity',
				'',
				'Low, Medium, or High with one sentence explaining why, based on the card-testing verdict as returned, payment health findings, counts, value, movement, stale age buckets, and card-gateway on-hold signals.',
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
