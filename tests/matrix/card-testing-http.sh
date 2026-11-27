# shellcheck shell=bash
# Card-testing cases for tests/matrix/http-suite.sh (1.3 FEATURE-PLAN item 8, contract C10).
# Sourced by the suite right after http-checks.sh, never run on its own. It defines functions and
# constants only: no request, credential or option change happens at source time, and it never
# creates, reads or changes a credential itself (the suite's own sessions, make_cred and the
# deny-case credentials are used as they are).
#
# Rows (one machine-readable line each, run-matrix.sh collects them as CARD= lines and record.sh
# checks exact membership against its frozen required set):
#   CARD_ROW=<state>|<route>|<storage:sync or any>|<principal>|<case>|PASSED|FAILED
# Per route, authority-independent (storage "any"): admin card_discovery, card_workflows,
# card_analytics_off, card_tool_off; manager card_roles. Per route and each storage:sync
# combination (hpos:no hpos:yes legacy:yes legacy:no): admin card_recent, card_earlier,
# card_low_volume, card_gaps, card_protection, card_diag_off, card_diag_on, card_diag_off_again;
# manager card_diag_off, card_diag_on, card_diag_off_again, card_warm_downgrade; customer,
# subscriber and anonymous card_denied. none: rec and easy admin card_no_tool.
# A row is PASSED only when no check failed between row_begin and card_row_end; a case that never
# reaches card_row_end leaves no row, which record.sh reports as missing.
#
# The card fixture (tests/matrix/card-testing-fixture.php) is seeded after every fixed Analytics
# comparison and the payment-health cases, in each storage combination after it was switched in its
# own process and read back, and removed again before the next switch and before the original
# storage comes back. Its scenarios are fixed per method: stripe a recent likely burst, woocommerce_payments
# an earlier likely burst, square_credit_card too little history, ppcp-gateway no saved IP on its
# burst plus failed orders with no recorded origin. The pure checkers below (card_*_checks) read a
# call result file ({ok: <result>} or {err: <text>}) and print "label<TAB>true|false" lines, so
# tests/matrix/test-http-checks.sh can feed them recorded and injected payloads offline.

CARD=blaze-mcp-toolkit/card-testing
CARD_KEYS='activity,assessments,assessments_total,assessments_truncated,baseline,checked_at,coverage,examples,examples_truncated,findings,findings_total,findings_truncated,limitations,logs,methods,methods_total,methods_truncated,protection,rule_version,schema_version,since_hours,summary,timezone,totals,verdict,window'
CARD_PATHS='["checkout_page","store_api_checkout","store_api_order_pay","order_pay","add_payment_method"]'
CARD_STORAGE_COMBOS=${BLZMCP_CARD_STORAGE_COMBOS:-hpos:no hpos:yes legacy:yes legacy:no}
# Synthetic values the fixture saves that must never come back: saved IPs (documentation ranges),
# network prefixes, the fixture log text and the raw attribution key.
CARD_CANARY='203\.0\.113\.|198\.51\.100\.|192\.0\.2\.|2001:db8|example\.invalid|card fixture gateway error|_wc_order_attribution|blzmcp_cardfx'
# Removed 1.2 failed > paid card-testing shortcut clauses (FEATURE-PLAN item 7, C9).
CARD_SHORTCUT='failed orders exceed paid orders|greater than the number of paid orders|likely sign of card testing|The only exception is the card-testing check|except for the card-testing check below|or possible card testing'
CARD_FX_RECENT=30
CARD_FX_EARLIER=24
CARD_FX_NO_IP=25
CARD_FX_MISSING=22

# card_row_end route storage principal case: the card receipt row (same pass rule as row_end).
card_row_end() {
	local r=PASSED
	[ "$fail" -gt "$ROW_F0" ] && r=FAILED
	echo "CARD_ROW=$STATE|$1|$2|$3|$4|$r"
}

# card_checks label checker file [args...]: one ok/no per printed check; no line at all (an
# unparseable or missing result) is a failure, never a vacuous pass.
card_checks() {
	local prefix=$1 lbl okv n=0
	shift
	while IFS=$'\t' read -r lbl okv; do
		[ -n "$lbl" ] || continue
		n=$((n + 1))
		if [ "$okv" == true ]; then ok "$prefix: $lbl"; else no "$prefix: $lbl"; fi
	done < <("$@" 2>/dev/null)
	[ "$n" -gt 0 ] || no "$prefix: card checks could not be evaluated ($(jq -r '.err // "no result"' "${CALL_OUT:-/nonexistent}" 2>/dev/null | head -c 120))"
}

# The C8 contract every card result keeps (kind: rec|easy; Easy MCP AI 2.3.1/2.4.0 may deliver an
# enum integer as a string, as they do for payment health's schema_version; only those two fields).
card_contract_checks() { # file kind
	jq -r --arg kind "${2:-}" --arg keys "$CARD_KEYS" --argjson paths "$CARD_PATHS" --arg canary "$CARD_CANARY" '
		(.ok // {}) as $r
		| [
			["top-level keys are the C8 card-testing contract", (($r | keys | join(",")) == $keys)],
			["schema_version 1 and rule_version 1 (received \($r.schema_version | tojson), \($r.rule_version | tojson))", (($r.schema_version == 1 and $r.rule_version == 1) or ($kind == "easy" and ([$r.schema_version, $r.rule_version] | map(tostring)) == ["1", "1"]))],
			["since_hours 48; the window ends at checked_at and the baseline ends where it starts", ($r.since_hours == 48 and ($r.checked_at | type) == "string" and $r.window.end == $r.checked_at and $r.baseline.end == $r.window.start)],
			["verdict, activity and coverage come from their enums", ((["likely_attack", "possible_attack", "no_sign", "unknown"] | index($r.verdict)) != null and (["recent", "earlier_in_window", "not_established", "unknown"] | index($r.activity)) != null and (["complete", "partial", "unavailable"] | index($r.coverage)) != null)],
			["every one of the 13 totals counts is {value, lower_bound}", ([($r.totals // {}) | to_entries[] | select(.value | type == "object") | .value | ((keys == ["lower_bound", "value"]) and ((.value | type) == "number" or .value == null) and (.lower_bound | type) == "boolean")] | (length == 13 and all))],
			["protection lists the 5 fixed payment paths in order", ([($r.protection // [])[] | .id] == $paths)],
			["the mandatory limitations are present (order state basis, unsaved and retried orders, cause unverified, no decline detection)", ([($r.limitations // [])[] | "\(.scope)|\(.code)"] as $l | ["store|order_state_basis", "store|unsaved_and_retried_orders", "store|cause_unverified", "logs|decline_detection_unsupported"] | all(. as $x | $l | index($x) != null))],
			["no saved IP, network prefix, synthetic address or log text anywhere in the response", (($r | tostring) | test($canary) | not)]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

card_recent_checks() { # file failed-count
	jq -r --argjson n "${2:-0}" '
		(.ok // {}) as $r
		| ([($r.assessments // [])[] | select(.method_id == "stripe" and .origin_scope == "verified_checkout")][0] // {}) as $a
		| [
			["store verdict likely_attack, still recent", ($r.verdict == "likely_attack" and $r.activity == "recent")],
			["stripe: likely, recent, complete, usable baseline", ($a.verdict == "likely_attack" and $a.activity == "recent" and $a.coverage == "complete" and $a.baseline_sufficient == true)],
			["stripe: the latest hour holds exactly the \($n) failed orders (not a lower bound)", ($a.latest_hour.failed_orders == {value: $n, lower_bound: false})],
			["stripe: the \($n) failed orders sit in 3 recorded networks, all with a valid saved IP", (($a.latest_hour.networks // {}) | .signal == true and .groups == 3 and .valid_ip_orders == $n and .missing_ip_orders == 0)],
			["stripe: no marketing source on the burst against a populated baseline", ($a.latest_hour.attribution.signal == true)],
			["summary: the fixed likely wording, a payment fault still possible, still being created", (($r.summary // "") | startswith("This pattern is consistent with likely card testing") and contains("A payment fault is still possible.") and contains("Suspicious orders were still being created in the last 15 minutes as of"))],
			["a likely_card_testing warning for stripe", ([($r.findings // [])[] | "\(.severity)|\(.code)|\(.method_id)"] | index("warning|likely_card_testing|stripe") != null)],
			["examples: at most 5 failed orders with an admin edit link and nothing else", (($r.examples // []) | length > 0 and length <= 5 and all((keys == ["created", "currency", "edit_url", "method_id", "number", "status", "total"]) and .status == "failed" and (.number | test("^[0-9]+$")) and (.edit_url | test("/wp-admin/(post\\.php\\?post=|admin\\.php\\?page=wc-orders)"))))],
			["stripe method facts: canonical, instant, not registered", ((($r.methods // []) | map(select(.id == "stripe")) | .[0] // {}) | .kind == "instant" and .registered == false)]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

card_earlier_checks() { # file failed-count
	jq -r --argjson n "${2:-0}" '
		(.ok // {}) as $r
		| ([($r.assessments // [])[] | select(.method_id == "woocommerce_payments" and .origin_scope == "verified_checkout")][0] // {}) as $a
		| [
			["woocommerce_payments: likely, earlier in the window", ($a.verdict == "likely_attack" and $a.activity == "earlier_in_window")],
			["woocommerce_payments: nothing in the latest hour", ($a.latest_hour.failed_orders.value == 0)],
			["woocommerce_payments: one likely episode of exactly \($n) failed orders", ([($a.episodes // [])[] | select(.verdict == "likely_attack" and .failed_orders == {value: $n, lower_bound: false})] | length >= 1)],
			["a likely_card_testing warning for woocommerce_payments", ([($r.findings // [])[] | "\(.code)|\(.method_id)"] | index("likely_card_testing|woocommerce_payments") != null)]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

card_low_volume_checks() { # file
	jq -r '
		(.ok // {}) as $r
		| ([($r.assessments // [])[] | select(.method_id == "square_credit_card" and .origin_scope == "verified_checkout")][0] // {}) as $a
		| [
			["square_credit_card: too little history gives unknown, never no_sign", ($a.verdict == "unknown" and $a.baseline_sufficient == false and $a.reason_code == "baseline_insufficient_volume")],
			["square_credit_card: no likely or possible finding", ([($r.findings // [])[] | select(.method_id == "square_credit_card" and (.code == "likely_card_testing" or .code == "possible_card_testing"))] | length == 0)],
			["the low-history limitation is present", ([($r.limitations // [])[] | "\(.scope)|\(.code)"] | index("store|low_history") != null)]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

card_gaps_checks() { # file no-ip-count missing-origin-count
	jq -r --argjson ni "${2:-0}" --argjson mo "${3:-0}" '
		(.ok // {}) as $r
		| ([($r.assessments // [])[] | select(.method_id == "ppcp-gateway" and .origin_scope == "verified_checkout")][0] // {}) as $a
		| ([($r.assessments // [])[] | select(.method_id == "ppcp-gateway" and .origin_scope == "missing_origin")][0] // {}) as $m
		| [
			["ppcp-gateway: a burst without saved IPs is possible at most, never likely", ($a.verdict == "possible_attack")],
			["ppcp-gateway: the \($ni) failed orders without a saved IP are counted apart, never one network", (($a.latest_hour.networks // {}) | .missing_ip_orders == $ni and .valid_ip_orders == 0 and .signal == false)],
			["a possible_card_testing warning for ppcp-gateway", ([($r.findings // [])[] | "\(.severity)|\(.code)|\(.method_id)"] | index("warning|possible_card_testing|ppcp-gateway") != null)],
			["ppcp-gateway: the \($mo) failed orders with no recorded origin are their own scope, never likely", ($m.origin_scope == "missing_origin" and $m.latest_hour.failed_orders.value == $mo and $m.verdict != "likely_attack")],
			["no missing-origin scope is ever likely", ([($r.assessments // [])[] | select(.origin_scope == "missing_origin" and .verdict == "likely_attack")] | length == 0)],
			["the missing-origin limitation is present and totals count those orders", (([($r.limitations // [])[] | "\(.scope)|\(.code)"] | index("store|missing_origin_orders") != null) and (($r.totals.missing_origin.value // 0) >= $mo))],
			["top-level coverage is partial while protection paths are unknown", ($r.coverage == "partial")]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

card_protection_checks() { # file
	jq -r '
		(.ok // {}) as $r | (($r.protection // []) | map({key: .id, value: .}) | from_entries) as $p
		| [
			["every path enforcement is unverified or not applicable, never verified", (($r.protection // []) | length == 5 and all(.enforcement == "unverified" or .enforcement == "not_applicable"))],
			["no protected or unprotected flag on any path", (($r.protection // []) | all((keys | map(test("protected")) | any) | not))],
			["Store API checkout: Rate limit Checkout configured, enforcement unverified", ($p.store_api_checkout.protection_state == "configured" and $p.store_api_checkout.enforcement == "unverified" and ([$p.store_api_checkout.protections[]? | select(.id == "core_checkout_rate_limit" and .configured == true and .scope_known == true)] | length == 1))],
			["paying an existing order does not inherit the Store API setting", ($p.order_pay.protection_state != "configured" and ([$p.order_pay.protections[]? | select(.id == "core_checkout_rate_limit")] | length == 0))],
			["saving a payment method: the core delay is never asserted as the value in use", ([$p.add_payment_method.protections[]? | select(.id == "core_saved_payment_delay" and .configured == null)] | length == 1)],
			["the protection limitations name unverified enforcement and the unknown paths", ([($r.limitations // [])[] | "\(.scope)|\(.code)"] as $l | ($l | index("protection|protection_enforcement_unverified") != null) and ($l | index("order_pay|no_readable_protection") != null))],
			["unknown protection never lowers the verdict (still likely)", ($r.verdict == "likely_attack")]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

# Logs for a caller with diagnostics (exposed) or without them (withheld). The fixture's stripe log
# file has 2 error entries; withheld means no count, source or category for any family, the role
# reason on the stripe row, and the positive order evidence still standing.
card_logs_checks() { # file exposed|withheld
	jq -r --arg mode "${2:-}" '
		(.ok // {}) as $r | ([($r.logs // [])[] | select(.family == "stripe")][0] // {}) as $s
		| ([($r.limitations // [])[] | "\(.scope)|\(.code)"]) as $l
		| if $mode == "exposed" then [
			["stripe logs read: checked, applicable, complete", ($s.checked == true and $s.applicable == true and $s.coverage == "complete" and $s.source == "woocommerce_log_file")],
			["stripe logs: the 2 fixture errors as gateway_error, an exact count", ($s.count == {value: 2, lower_bound: false} and ([$s.categories[]? | select(.category == "gateway_error" and .count == 2)] | length == 1))],
			["no diagnostics role limitation", ($l | index("logs|diagnostics_denied") == null)]
		] else [
			["stripe logs withheld: not checked, applicable, unavailable, the role reason", ($s.checked == false and $s.applicable == true and $s.coverage == "unavailable" and $s.reason_code == "diagnostics_denied")],
			["no log count, source or category for any family", (($r.logs // []) | length > 0 and all(.count.value == null and .source == null and .categories == []))],
			["the diagnostics role limitation is present", ($l | index("logs|diagnostics_denied") != null)],
			["the positive order evidence survives the log gap (still likely)", ($r.verdict == "likely_attack")],
			["no log category text anywhere in the response", (($r | tostring) | test("gateway_error|gateway_fatal|webhook_signature_failed") | not)]
		] end | .[] | "\(.[0])\t\(.[1])"' "$1"
}

# Workflow plan checks (kind rec|easy): payment health first, card testing second with a capped
# integer look-back, order totals third; the CARD TESTING CHECK paragraph and no shortcut clause.
card_plan_checks() { # plan-file kind card-tool payment-tool
	jq -r --arg card "$CARD" --arg ct "${3:-}" --arg pt "${4:-}" --arg short "$CARD_SHORTCUT" '
		. as $p | [
			["payment health first", ($p.steps[0].ability == "blaze-mcp-toolkit/payment-health" and $p.steps[0].tool == $pt)],
			["card testing second, as \($ct)", ($p.steps[1].ability == $card and $p.steps[1].tool == $ct)],
			["the card step looks back a whole number of hours, 1 to 168", (($p.steps[1].arguments.since_hours | type) == "number" and $p.steps[1].arguments.since_hours >= 1 and $p.steps[1].arguments.since_hours <= 168 and ($p.steps[1].arguments | keys) == ["since_hours"])],
			["order totals third", ($p.steps[2].ability == "wc-analytics/totals" and $p.steps[2].arguments.subject == "orders")],
			["the CARD TESTING CHECK paragraph is present", (($p.instructions // "") | contains("CARD TESTING CHECK"))],
			["no failed > paid card-testing shortcut clause", (($p.instructions // "") | test($short) | not)]
		] | .[] | "\(.[0])\t\(.[1])"' "$1"
}

# ---------------------------------------------------------------------------------------------
# Cases (run inside http-suite.sh; they use its sessions, helpers and ok/no/eq/truthy).
# ---------------------------------------------------------------------------------------------
card_call() { # session [clear]: card testing with its default look-back; clear=1 empties the toolkit cache first
	[ "${2:-0}" == 1 ] && helper card-testing-fixture.php clear-cache >/dev/null
	call_ability "$1" "$CARD" '{"since_hours":48}'
}
card_seed() { # label
	local out
	out=$(helper card-testing-fixture.php seed)
	if [ "$(sed -n 's/^SEEDED=//p' <<<"$out")" -gt 0 ] 2>/dev/null && [ "$(sed -n 's/^LOG_FILE=//p' <<<"$out")" == 1 ] \
		&& [ "$(sed -n 's/^STRIPE_RECENT_FAILED=//p' <<<"$out")" == "$CARD_FX_RECENT" ] && [ "$(sed -n 's/^PPCP_MISSING_ORIGIN_FAILED=//p' <<<"$out")" == "$CARD_FX_MISSING" ]; then
		ok "card fixture seeded [$1] ($(sed -n 's/^SEEDED=//p' <<<"$out") orders, 1 stripe log file)"
	else
		no "card fixture seeded [$1] ($(head -c 200 <<<"$out"))"
	fi
}
card_unseed() { # label
	local out
	out=$(helper card-testing-fixture.php cleanup)
	if [ "$(sed -n 's/^REMOVED=//p' <<<"$out")" -gt 0 ] 2>/dev/null && [ "$(sed -n 's/^SETTINGS_RESTORED=//p' <<<"$out")" == 1 ]; then
		eq "card fixture removed [$1]: nothing of it left (orders, log files, saved settings)" 'ORDERS_LEFT=0 LOGS_LEFT=0 SAVED_LEFT=0' "$(helper card-testing-fixture.php status | grep -E '^(ORDERS|LOGS|SAVED)_LEFT=' | tr '\n' ' ' | sed 's/ $//')"
	else
		no "card fixture removed [$1] ($(head -c 200 <<<"$out"))"
	fi
}
card_ok_or_no() { # label: the last call ran
	if call_ok; then ok "$1"; else no "$1 ($(call_err | head -c 200))"; fi
}
# A denial passes only on the route's known refusal without method or card facts (REVIEW-pass4a R4).
card_deny_case() { # route principal
	local cred=${DENY_CRED[$2_$1]:-} sname="card_deny_$1_$2"
	if [ -z "$cred" ] || [ "$cred" == refused ]; then
		no "$1: $2 has a credential for this route (the credential helper failed, so the card denial is unproven)"
		return
	fi
	if ! open_session "$sname" "$1" "$cred" quiet; then
		expect_init_refusal "$1: $2 refused at initialize with the known authentication refusal, no card facts" auth
		return
	fi
	card_call "$sname" # no flush: a privileged result is warm in the cache
	expect_call_refusal "$1: $2 is denied card testing with the known permission refusal" "$1" "$CARD" perm
	expect_no_facts "$1: $2 receives no method or card facts"
}

# Every storage combination: switch, read back, seed, every case on every route, clean, then the
# original storage comes back (pf_switch/authority_now/set_sm_diag/pf_orig_* are the suite's own).
card_storage_cases() {
	local combo st sy s sm phase lbl clear first_at
	UNDO+=("helper card-testing-fixture.php cleanup")
	for combo in $CARD_STORAGE_COMBOS; do
		st=${combo%%:*}
		sy=${combo##*:}
		if ! pf_switch "$st" "$sy" || [ "$(authority_now)" != "$st $sy" ]; then
			no "order storage switched to $st sync=$sy for the card cases (now: $(authority_now))"
			continue
		fi
		ok "order storage is $st sync=$sy in a fresh process (card cases)"
		card_seed "$combo"
		set_sm_diag false
		for s in "${TRANSPORTS[@]}"; do
			row_begin
			card_call "$s" 1
			card_ok_or_no "$s [$combo]: card testing runs over HTTP as $(tool_of "$s" "$CARD")"
			card_checks "$s [$combo] contract" card_contract_checks "$CALL_OUT" "${S_KIND[$s]}"
			card_checks "$s [$combo] recent" card_recent_checks "$CALL_OUT" "$CARD_FX_RECENT"
			first_at=$(call_get '.checked_at')
			card_call "$s"
			eq "$s [$combo]: a warm repeat is served from the 60-second cache with its original checked_at" "$first_at" "$(call_get '.checked_at')"
			card_row_end "$s" "$combo" admin card_recent
			row_begin
			card_call "$s"
			card_checks "$s [$combo] earlier" card_earlier_checks "$CALL_OUT" "$CARD_FX_EARLIER"
			card_row_end "$s" "$combo" admin card_earlier
			row_begin
			card_call "$s"
			card_checks "$s [$combo] low volume" card_low_volume_checks "$CALL_OUT"
			card_row_end "$s" "$combo" admin card_low_volume
			row_begin
			card_call "$s"
			card_checks "$s [$combo] gaps" card_gaps_checks "$CALL_OUT" "$CARD_FX_NO_IP" "$CARD_FX_MISSING"
			card_row_end "$s" "$combo" admin card_gaps
			row_begin
			card_call "$s"
			card_checks "$s [$combo] protection" card_protection_checks "$CALL_OUT"
			card_row_end "$s" "$combo" admin card_protection
		done
		# Diagnostics permission: off, on, then off again after the on result is warm (no flush). The
		# administrator always reads the logs; the manager's logs are withheld with diagnostics off.
		for phase in card_diag_off card_diag_on card_diag_off_again; do
			case "$phase" in card_diag_on) set_sm_diag true ;; *) set_sm_diag false ;; esac
			for s in "${TRANSPORTS[@]}"; do
				row_begin
				card_call "$s"
				card_ok_or_no "$s [$combo] $phase: administrator runs card testing"
				card_checks "$s [$combo] $phase: administrator logs" card_logs_checks "$CALL_OUT" exposed
				card_row_end "$s" "$combo" admin "$phase"
				row_begin
				sm="sm_$s"
				if [ -z "${S_URL[$sm]:-}" ]; then
					no "$s [$combo] $phase: shop manager session open"
				else
					clear=0
					[ "$phase" == card_diag_off ] && clear=1
					card_call "$sm" "$clear"
					card_ok_or_no "$s [$combo] $phase: shop manager runs card testing (manage_woocommerce)"
					if [ "$phase" == card_diag_on ]; then
						card_checks "$s [$combo] $phase: shop manager logs" card_logs_checks "$CALL_OUT" exposed
					else
						card_checks "$s [$combo] $phase: shop manager logs" card_logs_checks "$CALL_OUT" withheld
					fi
				fi
				card_row_end "$s" "$combo" manager "$phase"
			done
		done
		set_sm_diag false
		# Customers, subscribers and anonymous callers are denied, with a privileged result warm.
		for s in "${TRANSPORTS[@]}"; do
			card_call "$s"
			for lbl in customer subscriber anonymous; do
				row_begin
				card_deny_case "$s" "$lbl"
				card_row_end "$s" "$combo" "$lbl" card_denied
			done
		done
		# Warm downgrade: the manager's privileged result is warm, then the role loses manage_woocommerce.
		for s in "${TRANSPORTS[@]}"; do
			row_begin
			sm="sm_$s"
			if [ -z "${S_URL[$sm]:-}" ]; then
				no "$s [$combo]: card warm downgrade needs the shop manager session"
			else
				set_sm_diag true
				card_call "$sm" 1
				card_ok_or_no "$s [$combo]: shop manager with diagnostics warms a privileged card result"
				card_checks "$s [$combo]: the warmed manager card result" card_logs_checks "$CALL_OUT" exposed
				helper credentials.php user ensure blzmcp_matrix_sm customer >/dev/null
				card_call "$sm"
				expect_call_refusal "$s [$combo]: after the downgrade to customer the warm card result is refused with the known permission refusal" "${S_KIND[$sm]}" "$CARD" perm
				expect_no_facts "$s [$combo]: the downgraded user receives no method or card facts"
				helper credentials.php user ensure blzmcp_matrix_sm shop_manager >/dev/null
				set_sm_diag false
			fi
			card_row_end "$s" "$combo" manager card_warm_downgrade
		done
		card_unseed "$combo"
	done
	if pf_switch "$pf_orig_set" "$pf_orig_sync" && [ "$(authority_now)" == "$pf_orig_set $pf_orig_sync" ]; then
		ok "order storage restored to $pf_orig_set (sync=$pf_orig_sync) after the card cases"
	else
		no "order storage restored to $pf_orig_set (sync=$pf_orig_sync) after the card cases, now $(authority_now)"
	fi
}

# The tool object an Easy MCP AI session lists for a name (tools/list, every page).
card_easy_tool() { # session name -> JSON object or empty
	local cursor='' params found=''
	while :; do
		params='{}'
		[ -n "$cursor" ] && params=$(jq -cn --arg c "$cursor" '{cursor:$c}')
		rpc "$1" tools/list "$params"
		found=$(jq -c --arg n "$2" '[.result.tools[]? | select(.name == $n)][0] // empty' "$RPC_BODY" 2>/dev/null)
		[ -n "$found" ] && { printf '%s' "$found"; return 0; }
		cursor=$(jq -r '.result.nextCursor // ""' "$RPC_BODY" 2>/dev/null)
		[ -n "$cursor" ] || return 1
	done
}

# Authority-independent rows, with the fixture seeded in the original storage.
card_any_cases() {
	local s sm w plan prev ct pt info row
	card_seed any
	set_sm_diag false
	# Discovery: listed, read-only annotations, the Easy name of contract C1.
	for s in "${TRANSPORTS[@]}"; do
		row_begin
		if [ "${S_KIND[$s]}" == rec ]; then
			grep -qxF "$CARD" <<<"$(list_names "$s")" && ok "$s: discover-abilities lists $CARD" || no "$s: discover-abilities lists $CARD"
			rpc "$s" tools/call "$(jq -cn --arg a "$CARD" '{name:"mcp-adapter-get-ability-info",arguments:{ability_name:$a}}')"
			info=$(jq -c '(.result.structuredContent // ((.result.content // [])[0].text | fromjson? // {})) | (.data // .)' "$RPC_BODY" 2>/dev/null)
			truthy "$s: get-ability-info shows card testing read-only, not destructive, idempotent" "$(jq -r '((.meta.annotations // .annotations // {}) | .readonly == true and .destructive == false and .idempotent == true)' <<<"${info:-null}" 2>/dev/null)"
			truthy "$s: get-ability-info input is since_hours only (integer 1-168, default 48)" "$(jq -r '(.input_schema // .inputSchema // {}) | (.properties | keys) == ["since_hours"] and .properties.since_hours.type == "integer" and .properties.since_hours.minimum == 1 and .properties.since_hours.maximum == 168 and .properties.since_hours.default == 48' <<<"${info:-null}" 2>/dev/null)"
		else
			eq "$s: the Easy MCP AI name of card testing is the contract's" wp_ability_blaze_mcp_toolkit_card_testing "$(easy_name "$CARD")"
			info=$(card_easy_tool "$s" "$(easy_name "$CARD")")
			[ -n "$info" ] && ok "$s: tools/list has $(easy_name "$CARD")" || no "$s: tools/list has $(easy_name "$CARD")"
			truthy "$s: $(easy_name "$CARD") is read-only, not destructive, idempotent" "$(jq -r '(.annotations // {}) | .readOnlyHint == true and .destructiveHint == false and .idempotentHint == true' <<<"${info:-null}" 2>/dev/null)"
			truthy "$s: $(easy_name "$CARD") input is since_hours only" "$(jq -r '(.inputSchema // {}) | (.properties // {} | keys) == ["since_hours"]' <<<"${info:-null}" 2>/dev/null)"
		fi
		card_row_end "$s" any admin card_discovery
	done
	# Workflows: the 3 payment workflows put card testing second and its step runs; refund triage has none.
	for s in "${TRANSPORTS[@]}"; do
		row_begin
		ct=$(tool_of "$s" "$CARD")
		pt=$(tool_of "$s" "$PAY")
		for w in weekly-store-review revenue-drop-triage failed-order-triage; do
			call_ability "$s" "blaze-mcp-toolkit/$w" '{"period":"last_7_days"}'
			if ! call_ok; then
				no "$s: workflow $w returned a plan ($(call_err | head -c 200))"
				continue
			fi
			plan="$WORK/card-plan-$s-$w.json"
			jq '.ok' "$CALL_OUT" >"$plan"
			card_checks "$s: $w" card_plan_checks "$plan" "${S_KIND[$s]}" "$ct" "$pt"
			call_tool "$s" "$(jq -r '.steps[1].tool' "$plan")" "$(jq -c '.steps[1].arguments' "$plan")"
			truthy "$s: $w card step runs and returns its verdict and protection paths" "$(call_get '((.verdict == "likely_attack") and ((.protection // []) | length == 5))')"
		done
		call_ability "$s" blaze-mcp-toolkit/refund-triage '{"period":"last_7_days"}'
		truthy "$s: refund triage has no card-testing step and no CARD TESTING CHECK" "$(jq -r --arg c "$CARD" '.ok | ([.steps[].ability] | index($c) == null) and (.instructions | contains("CARD TESTING CHECK") | not)' "$CALL_OUT" 2>/dev/null)"
		card_row_end "$s" any admin card_workflows
	done
	# Analytics off: only the Analytics work stops; card evidence and protection advice stay.
	wpc option update woocommerce_analytics_enabled no >/dev/null
	for s in "${TRANSPORTS[@]}"; do
		row_begin
		call_ability "$s" blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
		if call_ok; then
			jq '.ok' "$CALL_OUT" >"$WORK/card-plan-off-$s.json"
			truthy "$s: Analytics off: payment health then card testing, no Analytics step" "$(jq -r --arg c "$CARD" '(.steps[0].ability == "blaze-mcp-toolkit/payment-health") and (.steps[1].ability == $c) and ([.steps[].ability | startswith("wc-analytics/")] | any | not)' "$WORK/card-plan-off-$s.json")"
			truthy "$s: Analytics off: the plan stops only the Analytics work and keeps the card result" "$(jq -r '(.instructions | contains("STOP THE ANALYTICS WORK")) and (.instructions | contains("the card-testing result and its protection advice included"))' "$WORK/card-plan-off-$s.json")"
			call_tool "$s" "$(jq -r '.steps[1].tool' "$WORK/card-plan-off-$s.json")" "$(jq -c '.steps[1].arguments' "$WORK/card-plan-off-$s.json")"
			truthy "$s: Analytics off: card testing still reports its verdict and protection paths" "$(call_get '((.verdict == "likely_attack") and ((.protection // []) | length == 5))')"
		else
			no "$s: Analytics off: weekly review returns a plan ($(call_err | head -c 200))"
		fi
		card_row_end "$s" any admin card_analytics_off
	done
	wpc option update woocommerce_analytics_enabled yes >/dev/null
	# Card testing switched off: recommended through Enable Abilities' list, Easy MCP AI by leaving it
	# unticked (an existing 1.2 install). A direct call is refused; setup warns and never ticks it.
	if has_transport rec; then
		row_begin
		save_option ewpa_thirdparty_disabled
		prev=$(option_json ewpa_thirdparty_disabled)
		wpc option update ewpa_thirdparty_disabled "$(jq -c --arg s "$CARD" '(if type == "array" then . elif type == "object" then [.[]] else [] end) + [$s] | unique' <<<"${prev:-[]}")" --format=json >/dev/null
		open_session card_off_rec rec admin_app || no "card off: recommended session"
		grep -qxF "$CARD" <<<"$(list_names card_off_rec)" && no "card off: absent from recommended discovery" || ok "card off: absent from recommended discovery"
		card_call card_off_rec
		expect_call_refusal "card off (recommended): a direct call gets the known not-found answer without card facts" rec "$CARD" tool_off
		expect_no_facts "card off (recommended): the refusal carries no method or card facts"
		call_ability card_off_rec blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
		truthy "card off (recommended): weekly review leaves the step out and says card testing was not checked" "$(jq -r --arg c "$CARD" '(.ok | ([.steps[].ability] | index($c) == null) and (.instructions | contains("The card-testing check is not in this plan")))' "$CALL_OUT")"
		wpc option update ewpa_thirdparty_disabled "${prev:-[]}" --format=json >/dev/null
		card_row_end rec any admin card_tool_off
	fi
	if has_transport easy; then
		row_begin
		save_option easy_mcp_ai_enabled_abilities
		prev=$(option_json easy_mcp_ai_enabled_abilities)
		wpc option update easy_mcp_ai_enabled_abilities "$(jq -c --arg s "$CARD" 'map(select(. != $s))' <<<"${prev:-[]}")" --format=json >/dev/null
		open_session card_off_easy easy admin_easy || no "card off: Easy MCP AI session"
		grep -qxF "$(easy_name "$CARD")" <<<"$(list_names card_off_easy)" && no "card off: absent from Easy MCP AI tools/list" || ok "card off: absent from Easy MCP AI tools/list"
		card_call card_off_easy
		expect_call_refusal "card off (Easy MCP AI): a direct call gets the known switched-off answer without card facts" easy "$CARD" tool_off
		expect_no_facts "card off (Easy MCP AI): the refusal carries no method or card facts"
		call_ability card_off_easy blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
		truthy "card off (Easy MCP AI): weekly review leaves the step out and says card testing was not checked" "$(jq -r --arg c "$CARD" '(.ok | ([.steps[].ability] | index($c) == null) and (.instructions | contains("The card-testing check is not in this plan")))' "$CALL_OUT")"
		row=$(setup_row easy_tools_on)
		eq "card off (Easy MCP AI): easy_tools_on warns" warn "$(jq -r '.status' <<<"$row")"
		truthy "card off (Easy MCP AI): easy_tools_on names card testing" "$(jq -r --arg c "$CARD" '(.label + " " + .fix) | contains($c)' <<<"$row")"
		eq "card off (Easy MCP AI): the setup check never ticks it for the owner" false "$(jq -r --arg c "$CARD" 'index($c) != null' <<<"$(option_json easy_mcp_ai_enabled_abilities)")"
		wpc option update easy_mcp_ai_enabled_abilities "${prev:-[]}" --format=json >/dev/null
		card_row_end easy any admin card_tool_off
	fi
	# Roles: the shop manager runs card testing and its review includes it (diagnostics off).
	for s in "${TRANSPORTS[@]}"; do
		row_begin
		sm="sm_$s"
		if [ -z "${S_URL[$sm]:-}" ]; then
			no "$s: card roles need the shop manager session"
		else
			card_call "$sm" 1
			card_ok_or_no "$s: shop manager runs $(tool_of "$sm" "$CARD")"
			card_checks "$s: shop manager card result" card_contract_checks "$CALL_OUT" "${S_KIND[$sm]}"
			card_checks "$s: shop manager card logs" card_logs_checks "$CALL_OUT" withheld
			call_ability "$sm" blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
			truthy "$s: shop manager's weekly review runs card testing second, without the diagnostics steps" "$(jq -r --arg c "$CARD" '.ok | (.steps[1].ability == $c) and ([.steps[].ability] | (index("blaze-mcp-toolkit/health-summary") == null and index("blaze-mcp-toolkit/update-history") == null))' "$CALL_OUT" 2>/dev/null)"
		fi
		card_row_end "$s" any manager card_roles
	done
	card_unseed any
}

# none: no route exposes card testing (an absent route answers rest_no_route; a server that does
# answer neither lists nor runs it).
card_none_cases() { # needs the admin_app credential
	local r
	for r in rec easy; do
		row_begin
		if open_session "card_none_$r" "$r" admin_app quiet; then
			grep -qxF "$(tool_of "card_none_$r" "$CARD")" <<<"$(list_names "card_none_$r")" && no "$r route answers in none but lists card testing" || ok "$r route answers in none (http 200) and does not list card testing"
			call_ability "card_none_$r" "$CARD" '{"since_hours":48}'
			expect_call_refusal "$r route refuses card testing in none with the known tool-off answer, no card facts" "$r" "$CARD" tool_off
		else
			expect_init_refusal "$r route has no MCP server for card testing in none (http 404 rest_no_route)" no_route
		fi
		card_row_end "$r" any admin card_no_tool
	done
}
