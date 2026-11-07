#!/usr/bin/env bash
# Real-transport suite for one matrix state. Runs on the HOST with curl against the state's
# published port; in-container work (fixture, credentials, options) goes through `wp-env run cli`.
#
#   WPE=/path/to/wp-env bash tests/matrix/http-suite.sh <state> <port>
#
# Run from the state's matrix work directory (the one holding its .wp-env.json). The fixture must
# already be seeded (tests/matrix/fixture.php seed); its WINDOW_* values and PRODUCT_ID are read
# from tests/matrix/.window and the environment (BLZMCP_PRODUCT_ID).
#
# Credentials (an application password for Basic auth; an Easy MCP AI API key made by Easy MCP's
# own Token_Manager) live only in shell variables and reach curl on stdin (`curl -K -`), never on
# its command line or in a file. Every credential, test user, option and wp-config constant this
# suite changes is put back by the EXIT trap.
#
# There is no fallback to the internal dispatcher: each transport is preflighted with a JSON-RPC
# `initialize`, and anything but a JSON-RPC result stops the suite with
# "TRANSPORT UNREACHABLE: <url> <status>".
#
# Ends with "<n> passed, <m> failed"; exit status 0 only when nothing failed.
set -uo pipefail

STATE=${1:?state}
PORT=${2:?port}
WPE=${WPE:-wp-env}
BASE="http://localhost:$PORT"
REC_URL="$BASE/wp-json/mcp/mcp-adapter-default-server"
EASY_URL="$BASE/wp-json/easy-mcp-ai/v1/mcp"
PROTO='2025-06-18'
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-http.XXXXXX")

pass=0
fail=0
ok() { pass=$((pass + 1)); echo "PASS  [$STATE] $*"; }
no() { fail=$((fail + 1)); echo "FAIL  [$STATE] $*"; }
note() { echo "NOTE  [$STATE] $*"; }
eq() { if [ "$2" == "$3" ]; then ok "$1"; else no "$1 (expected $2, got $3)"; fi; }
truthy() { if [ "$2" == "true" ]; then ok "$1"; else no "$1 (got $2)"; fi; }

wpc() { "$WPE" run cli wp "$@" 2>/dev/null; }
helper() { wpc eval-file "wp-content/blzmcp-tests/matrix/$1" "${@:2}"; }

# ---------------------------------------------------------------------------------------------
# Fixture window and constants.
# ---------------------------------------------------------------------------------------------
# BLZMCP_PREFLIGHT=1: only create credentials, initialize each transport and clean up (run
# before the fixture exists).
PREFLIGHT=${BLZMCP_PREFLIGHT:-0}
if [ "$PREFLIGHT" != 1 ]; then
	[ -f tests/matrix/.window ] || { echo "FAIL  [$STATE] no tests/matrix/.window (fixture not seeded)"; exit 1; }
	# shellcheck disable=SC1091 # Written by fixture.php: KEY=value lines only.
	. tests/matrix/.window
	PRODUCT_ID=${BLZMCP_PRODUCT_ID:?BLZMCP_PRODUCT_ID}
fi
# Expected figures (fixture rule N6), established against WooCommerce › Analytics on the first run:
# wc-analytics/totals subject=revenue metrics.orders_count / metrics.net_sales / metrics.refunds,
# subject=refunds metrics.paid_gross_revenue (gross before refunds); date basis date_paid.
CUR_ORDERS=18
CUR_GROSS=450
CUR_NET=445
CUR_REFUNDS=5
PREV_ORDERS=12
PREV_GROSS=180
PREV_NET=180

BLAZE_ABILITIES=(
	blaze-mcp-toolkit/health-summary blaze-mcp-toolkit/update-history blaze-mcp-toolkit/access-check
	blaze-mcp-toolkit/weekly-store-review blaze-mcp-toolkit/revenue-drop-triage
	blaze-mcp-toolkit/failed-order-triage blaze-mcp-toolkit/refund-triage
	blaze-mcp-toolkit/store-profile blaze-mcp-toolkit/search-products blaze-mcp-toolkit/product-details
	blaze-mcp-toolkit/readiness-score blaze-mcp-toolkit/recommendations
	blaze-mcp-toolkit/suggest-improvements
)
WORKFLOWS=(weekly-store-review revenue-drop-triage failed-order-triage refund-triage)
ANALYTICS=(wc-analytics/confirm-large-range wc-analytics/totals wc-analytics/breakdown wc-analytics/series wc-analytics/rows)

# Easy MCP AI tool name rule (2.3.1 = 2.4.0; no slug here is over 64 characters).
easy_name() { printf 'wp_ability_%s' "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//')"; }

# Arguments for a direct call of each Blaze ability.
args_for() {
	case "$1" in
	blaze-mcp-toolkit/health-summary) echo '{"since_hours":24}' ;;
	blaze-mcp-toolkit/update-history) echo '{"days":7}' ;;
	blaze-mcp-toolkit/search-products) echo '{"query":"Matrix"}' ;;
	blaze-mcp-toolkit/product-details | blaze-mcp-toolkit/suggest-improvements) echo "{\"product_id\":$PRODUCT_ID}" ;;
	blaze-mcp-toolkit/*-triage | blaze-mcp-toolkit/weekly-store-review) echo '{"period":"last_7_days"}' ;;
	*) echo '{}' ;;
	esac
}

case "$STATE" in
recommended) TRANSPORTS=(rec) ;;
easy-*) TRANSPORTS=(easy) ;;
both) TRANSPORTS=(rec easy) ;;
none) TRANSPORTS=() ;;
*) echo "FAIL  unknown state $STATE"; exit 1 ;;
esac
has_transport() { [[ " ${TRANSPORTS[*]} " == *" $1 "* ]]; }

# ---------------------------------------------------------------------------------------------
# Cleanup: every change below registers its undo here.
# ---------------------------------------------------------------------------------------------
UNDO=()
on_exit() {
	local i
	for ((i = ${#UNDO[@]} - 1; i >= 0; i--)); do
		eval "${UNDO[$i]}" >/dev/null 2>&1 || echo "CLEANUP ERROR  [$STATE] ${UNDO[$i]%% *}"
	done
	rm -rf "$WORK"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

# Credentials: KEY_<name> holds "<id>" and SECRET_<name> the secret (memory only).
declare -A CRED_ID CRED_SECRET CRED_KIND CRED_LOGIN
make_cred() { # name kind(app|easy) login
	local out id secret
	if [ "$2" == app ]; then
		out=$(helper credentials.php app-password create "$3")
	else
		out=$(helper credentials.php easy-token create "$3")
	fi
	id=$(printf '%s\n' "$out" | sed -n 's/^ID=//p' | head -1)
	secret=$(printf '%s\n' "$out" | sed -n 's/^SECRET=//p' | head -1)
	if [ -z "$id" ] || [ -z "$secret" ]; then
		no "credential $1 ($2 for $3) created: $(printf '%s\n' "$out" | grep '^ERROR=' | head -1)"
		return 1
	fi
	CRED_ID[$1]=$id
	CRED_SECRET[$1]=$secret
	CRED_KIND[$1]=$2
	CRED_LOGIN[$1]=$3
	if [ "$2" == app ]; then
		UNDO+=("helper credentials.php app-password revoke '$3' '$id'")
	else
		UNDO+=("helper credentials.php easy-token revoke '$id'")
	fi
	return 0
}
auth_cfg() { # cred name -> curl config line on stdout (piped straight into curl -K -)
	if [ "${CRED_KIND[$1]}" == app ]; then
		printf 'header = "Authorization: Basic %s"\n' "$(printf '%s:%s' "${CRED_LOGIN[$1]}" "${CRED_SECRET[$1]}" | base64 | tr -d '\n')"
	else
		printf 'header = "Authorization: Bearer %s"\n' "${CRED_SECRET[$1]}"
	fi
}

# ---------------------------------------------------------------------------------------------
# JSON-RPC over HTTP.
# ---------------------------------------------------------------------------------------------
declare -A S_URL S_CRED S_SID S_KIND
RPC_ID=10
RPC_CODE=''
RPC_BODY="$WORK/body.json"
rpc_raw() { # url cred sid body -> $RPC_CODE, $RPC_BODY
	RPC_CODE=$(auth_cfg "$2" | curl -sS -K - -X POST "$1" --max-time 120 \
		-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
		-H "MCP-Protocol-Version: $PROTO" ${3:+-H "Mcp-Session-Id: $3"} \
		-D "$WORK/headers" -o "$WORK/raw" -w '%{http_code}' --data-binary "$4" 2>"$WORK/curl.err") || RPC_CODE="curl:$(head -c 120 "$WORK/curl.err")"
	# A text/event-stream reply carries the JSON-RPC message on its data: lines.
	if head -c 6 "$WORK/raw" | grep -q '^event:\|^data:'; then
		sed -n 's/^data: \{0,1\}//p' "$WORK/raw" >"$RPC_BODY"
	else
		cp "$WORK/raw" "$RPC_BODY"
	fi
}
# Open a session: name kind(rec|easy) cred. Preflight: anything but a JSON-RPC result is fatal.
open_session() {
	local url
	[ "$2" == rec ] && url=$REC_URL || url=$EASY_URL
	RPC_ID=$((RPC_ID + 1))
	rpc_raw "$url" "$3" '' "{\"jsonrpc\":\"2.0\",\"id\":$RPC_ID,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"$PROTO\",\"capabilities\":{},\"clientInfo\":{\"name\":\"blzmcp-matrix\",\"version\":\"1.1\"}}}"
	if [ "$RPC_CODE" != 200 ] || [ "$(jq -r 'if (type == "object" and has("result") and (.result | type) == "object") then "ok" else "no" end' "$RPC_BODY" 2>/dev/null)" != ok ]; then
		echo "TRANSPORT UNREACHABLE: $url $RPC_CODE"
		return 1
	fi
	S_URL[$1]=$url
	S_CRED[$1]=$3
	S_KIND[$1]=$2
	S_SID[$1]=$(grep -i '^mcp-session-id:' "$WORK/headers" | head -1 | awk '{print $2}' | tr -d '\r')
	rpc_raw "$url" "$3" "${S_SID[$1]}" '{"jsonrpc":"2.0","method":"notifications/initialized"}'
	return 0
}
rpc() { # session method params-json
	RPC_ID=$((RPC_ID + 1))
	rpc_raw "${S_URL[$1]}" "${S_CRED[$1]}" "${S_SID[$1]}" "$(jq -cn --argjson id "$RPC_ID" --arg m "$2" --argjson p "$3" '{jsonrpc:"2.0",id:$id,method:$m,params:$p}')"
}
# Call an ability as the session's transport exposes it: execute-ability on the recommended
# route, the direct wp_ability_* tool on Easy MCP AI. $CALL_OUT gets {ok:<data>} or {err:<text>}.
CALL_OUT="$WORK/call.json"
call_tool() { # session tool-name args-json
	if [ "${S_KIND[$1]}" == rec ]; then
		rpc "$1" tools/call "$(jq -cn --arg a "$2" --argjson p "$3" '{name:"mcp-adapter-execute-ability",arguments:{ability_name:$a,parameters:$p}}')"
	else
		rpc "$1" tools/call "$(jq -cn --arg n "$2" --argjson p "$3" '{name:$n,arguments:$p}')"
	fi
	jq -c --arg kind "${S_KIND[$1]}" '
		if has("error") then {err: ("jsonrpc " + (.error.code | tostring) + ": " + (.error.message // ""))}
		elif (.result.isError // false) then {err: ((.result.content // []) | map(.text // "") | join(" "))}
		else (.result.structuredContent // ((.result.content // [])[0].text // "null" | fromjson? // null)) as $sc
			| if $kind == "rec" then
				(if ($sc | type) == "object" and ($sc | has("success")) then
					(if $sc.success then {ok: $sc.data} else {err: ($sc.error // "success=false" | tostring)} end)
				else {ok: $sc} end)
			else {ok: $sc} end
		end' "$RPC_BODY" >"$CALL_OUT" 2>/dev/null || echo "{\"err\":\"unparseable reply (http $RPC_CODE)\"}" >"$CALL_OUT"
}
call_ok() { jq -e 'has("ok")' "$CALL_OUT" >/dev/null 2>&1; }
call_err() { jq -r '.err // ""' "$CALL_OUT"; }
call_get() { jq -r "$1" <(jq '.ok' "$CALL_OUT"); }
# The name a session calls an ability by.
tool_of() { [ "${S_KIND[$1]}" == rec ] && printf '%s' "$2" || easy_name "$2"; }
call_ability() { call_tool "$1" "$(tool_of "$1" "$2")" "$3"; }
# Names visible through a session: easy tools/list names; recommended discover-abilities names.
list_names() { # session -> one name per line
	if [ "${S_KIND[$1]}" == rec ]; then
		rpc "$1" tools/call '{"name":"mcp-adapter-discover-abilities","arguments":{}}'
		jq -r '(.result.structuredContent // ((.result.content // [])[0].text | fromjson? // {})) | (.data.abilities // .abilities // [])[] | .name' "$RPC_BODY" 2>/dev/null
	else
		local cursor='' params
		while :; do
			params='{}'
			[ -n "$cursor" ] && params=$(jq -cn --arg c "$cursor" '{cursor:$c}')
			rpc "$1" tools/list "$params"
			jq -r '.result.tools[]?.name' "$RPC_BODY"
			cursor=$(jq -r '.result.nextCursor // ""' "$RPC_BODY")
			[ -n "$cursor" ] || break
		done
	fi
}
setup_row() { # id -> JSON row (or null)
	wpc eval 'wp_set_current_user( 1 ); echo wp_json_encode( Blaze\McpToolkit\Admin\SetupChecks::run(), JSON_UNESCAPED_SLASHES );' | jq -c --arg id "$1" '[.[] | select(.id == $id)][0]'
}
option_json() { wpc option get "$1" --format=json; }
# Snapshot an option now and register its restore.
save_option() {
	local val
	if val=$(option_json "$1") && [ -n "$val" ]; then
		UNDO+=("wpc option update '$1' '$(printf '%s' "$val" | sed "s/'/'\\\\''/g")' --format=json")
	else
		UNDO+=("wpc option delete '$1'")
	fi
}

# ---------------------------------------------------------------------------------------------
# none: no transport; activation, no fatal, connection row fails.
# ---------------------------------------------------------------------------------------------
if [ "$STATE" == none ]; then
	eq "Blaze MCP Toolkit is active with WooCommerce alone" active "$(wpc plugin get blaze-mcp-toolkit-for-woocommerce --field=status)"
	eq "connector state is none" none "$(wpc eval 'echo Blaze\McpToolkit\Connectors\Connectors::state();')"
	row=$(setup_row connection)
	eq "connection row status is fail" fail "$(jq -r '.status' <<<"$row")"
	truthy "connection row recommends MCP Adapter + Enable Abilities and mentions Easy MCP AI" "$(jq -r '(.label + " " + .fix) | (test("Enable Abilities") and test("Easy MCP AI"))' <<<"$row")"
	hits=$(wpc eval 'echo (int) preg_match_all( "/PHP Fatal error/", (string) @file_get_contents( WP_CONTENT_DIR . "/debug.log" ) );')
	eq "no PHP fatal in debug.log" 0 "${hits:-missing}"
	for t in mcp; do
		code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$REC_URL" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}')
		note "WooCommerce's bundled adapter route $REC_URL answers http $code without Enable Abilities ($t)"
	done
	echo "$pass passed, $fail failed"
	[ "$fail" -eq 0 ]
	exit $?
fi

# ---------------------------------------------------------------------------------------------
# Easy MCP AI: fresh activation vs a store seeded before 1.1 (before any key exists, since this
# deactivates and reactivates Easy MCP AI).
# ---------------------------------------------------------------------------------------------
if has_transport easy && [ "$PREFLIGHT" != 1 ]; then
	save_option easy_mcp_ai_enabled_abilities
	wpc plugin deactivate easy-mcp-ai >/dev/null
	wpc option delete easy_mcp_ai_enabled_abilities easy_mcp_ai_abilities_seed_pending >/dev/null
	wpc plugin activate easy-mcp-ai >/dev/null
	wpc eval 'echo 1;' >/dev/null # the first request after activation runs Easy MCP AI's seed
	seeded=$(option_json easy_mcp_ai_enabled_abilities)
	missing=()
	for a in "${BLAZE_ABILITIES[@]}" "${ANALYTICS[@]}"; do
		jq -e --arg a "$a" 'index($a) != null' <<<"${seeded:-[]}" >/dev/null 2>&1 || missing+=("$a")
	done
	eq "fresh activation after Blaze: Easy MCP AI seeds all 13 Blaze tools and 5 wc-analytics tools (missing: ${missing[*]:-none})" 0 "${#missing[@]}"
	FRESH_SEED=$seeded
fi

# ---------------------------------------------------------------------------------------------
# Credentials and preflight.
# ---------------------------------------------------------------------------------------------
# Easy MCP AI allows 60 calls a minute by default; this suite makes a few hundred. Raise it to
# Easy MCP AI's own maximum (1000) for the run (restored on exit).
if has_transport easy; then
	save_option easy_mcp_ai_rate_limit_per_minute
	wpc option update easy_mcp_ai_rate_limit_per_minute 1000 >/dev/null
fi
make_cred admin_app app admin || exit 1
has_transport easy && { make_cred admin_easy easy admin || exit 1; }
preflight_ok=1
has_transport rec && { open_session rec rec admin_app || preflight_ok=0; }
has_transport easy && { open_session easy easy admin_easy || preflight_ok=0; }
if [ "$preflight_ok" -ne 1 ]; then
	echo "$pass passed, $((fail + 1)) failed"
	exit 1
fi
ok "preflight: initialize answered on ${TRANSPORTS[*]}"
if [ "$PREFLIGHT" == 1 ]; then
	echo "$pass passed, $fail failed"
	exit 0
fi

# ---------------------------------------------------------------------------------------------
# Discovery.
# ---------------------------------------------------------------------------------------------
if has_transport rec; then
	rpc rec tools/list '{}'
	eq "recommended tools/list is the 3 gateway tools" 'mcp-adapter-discover-abilities,mcp-adapter-execute-ability,mcp-adapter-get-ability-info' "$(jq -r '[.result.tools[]?.name] | sort | join(",")' "$RPC_BODY")"
	names=$(list_names rec)
	missing=()
	for a in "${BLAZE_ABILITIES[@]}" "${ANALYTICS[@]}"; do grep -qxF "$a" <<<"$names" || missing+=("$a"); done
	eq "recommended discover-abilities lists every Blaze and wc-analytics ability (missing: ${missing[*]:-none})" 0 "${#missing[@]}"
fi
if has_transport easy; then
	names=$(list_names easy)
	missing=()
	for a in "${BLAZE_ABILITIES[@]}" "${ANALYTICS[@]}"; do grep -qxF "$(easy_name "$a")" <<<"$names" || missing+=("$(easy_name "$a")"); done
	eq "Easy MCP AI tools/list has every Blaze and wc-analytics tool (missing: ${missing[*]:-none})" 0 "${#missing[@]}"
fi

# ---------------------------------------------------------------------------------------------
# Every Blaze ability, then every workflow and each step it names, through each transport.
# ---------------------------------------------------------------------------------------------
declare -A FIG
for s in "${TRANSPORTS[@]}"; do
	for a in "${BLAZE_ABILITIES[@]}"; do
		call_ability "$s" "$a" "$(args_for "$a")"
		if call_ok; then ok "$s: $(tool_of "$s" "$a") runs"; else no "$s: $(tool_of "$s" "$a") runs ($(call_err | head -c 200))"; fi
	done
	for w in "${WORKFLOWS[@]}"; do
		call_ability "$s" "blaze-mcp-toolkit/$w" '{"period":"last_7_days"}'
		if ! call_ok; then
			no "$s: workflow $w returned a plan ($(call_err | head -c 200))"
			continue
		fi
		jq '.ok' "$CALL_OUT" >"$WORK/plan-$s-$w.json"
		plan="$WORK/plan-$s-$w.json"
		nsteps=$(jq '.steps | length' "$plan")
		[ "$nsteps" -gt 0 ] && ok "$s: workflow $w has $nsteps steps" || no "$s: workflow $w has steps"
		if [ "$s" == rec ]; then
			eq "$s: $w step tools are the ability slugs (request-specific naming)" true "$(jq -r '[.steps[] | .tool == .ability] | all' "$plan")"
			truthy "$s: $w instruction is the execute-ability sentence" "$(jq -r '.instructions | test("execute-ability")' "$plan")"
		else
			bad=$(jq -r '.steps[] | "\(.tool) \(.ability)"' "$plan" | while read -r t a; do [ "$t" == "$(easy_name "$a")" ] || echo "$t"; done)
			eq "$s: $w step tools are the wp_ability_* names (request-specific naming) (bad: ${bad:-none})" '' "$bad"
			truthy "$s: $w instruction is the direct-call sentence" "$(jq -r '.instructions | test("call the step.s \"tool\" with its \"arguments\"")' "$plan")"
		fi
		truthy "$s: $w has no unrendered {tool:…} placeholder" "$(jq -r '(.instructions | test("\\{tool:")) | not' "$plan")"
		truthy "$s: $w names no obsolete alias" "$(jq -r '(.instructions | test("wc-analytics-confirm-large-range")) | not' "$plan")"
		i=0
		while [ "$i" -lt "$nsteps" ]; do
			tool=$(jq -r ".steps[$i].tool" "$plan")
			args=$(jq -c ".steps[$i].arguments" "$plan")
			call_tool "$s" "$tool" "$args"
			if call_ok; then ok "$s: $w step $((i + 1)) $tool runs"; else no "$s: $w step $((i + 1)) $tool $args ($(call_err | head -c 200))"; fi
			i=$((i + 1))
		done
	done

	# Figures: explicit windows, every transport, and WooCommerce › Analytics itself.
	call_ability "$s" wc-analytics/totals "{\"subject\":\"revenue\",\"date_start\":\"$WINDOW_CURRENT_START\",\"date_end\":\"$WINDOW_CURRENT_END\"}"
	FIG[$s.cur]=$(call_get '[.metrics.orders_count, .metrics.net_sales, .metrics.refunds] | map(tostring) | join(" ")')
	eq "$s: current period orders_count net_sales refunds" "$CUR_ORDERS $CUR_NET $CUR_REFUNDS" "${FIG[$s.cur]}"
	call_ability "$s" wc-analytics/totals "{\"subject\":\"refunds\",\"date_start\":\"$WINDOW_CURRENT_START\",\"date_end\":\"$WINDOW_CURRENT_END\"}"
	eq "$s: current period gross before refunds (refunds.paid_gross_revenue)" "$CUR_GROSS" "$(call_get '.metrics.paid_gross_revenue')"
	call_ability "$s" wc-analytics/totals "{\"subject\":\"revenue\",\"date_start\":\"$WINDOW_PREVIOUS_START\",\"date_end\":\"$WINDOW_PREVIOUS_END\"}"
	FIG[$s.prev]=$(call_get '[.metrics.orders_count, .metrics.net_sales] | map(tostring) | join(" ")')
	eq "$s: previous period orders_count net_sales" "$PREV_ORDERS $PREV_NET" "${FIG[$s.prev]}"
	call_ability "$s" wc-analytics/totals "{\"subject\":\"refunds\",\"date_start\":\"$WINDOW_PREVIOUS_START\",\"date_end\":\"$WINDOW_PREVIOUS_END\"}"
	eq "$s: previous period gross (refunds.paid_gross_revenue)" "$PREV_GROSS" "$(call_get '.metrics.paid_gross_revenue')"

	# F01: failed-order comparison, revenue breakdown, large range -> confirm -> retry.
	plan="$WORK/plan-$s-failed-order-triage.json"
	rows_name=$(tool_of "$s" wc-analytics/rows)
	if [ -f "$plan" ]; then
		truthy "$s: failed-order guidance names $rows_name for the comparison period" "$(jq -r --arg n "$rows_name" '.instructions | contains("run " + $n + " twice more")' "$plan")"
		call_tool "$s" "$(jq -r '.steps[0].tool' "$plan")" "$(jq -c '.steps[0].arguments' "$plan")"
		cmp_start=$(call_get '.comparison.period.start // empty')
		cmp_end=$(call_get '.comparison.period.end // empty')
		failed_step=$(jq -c '[.steps[] | select(.ability == "wc-analytics/rows" and ((.arguments | tostring) | test("failed")))][0] // empty' "$plan")
		if [ -n "$cmp_start" ] && [ -n "$failed_step" ]; then
			cur_failed=$(jq -c '.arguments' <<<"$failed_step")
			call_tool "$s" "$rows_name" "$cur_failed"
			call_ok && ok "$s: failed-order aggregate for the period runs" || no "$s: failed-order aggregate ($(call_err | head -c 200))"
			cmp_args=$(jq -c --arg a "$cmp_start" --arg b "$cmp_end" 'del(.period) + {date_start:$a, date_end:$b}' <<<"$cur_failed")
			call_tool "$s" "$rows_name" "$cmp_args"
			call_ok && ok "$s: failed-order comparison ($cmp_start..$cmp_end) runs through $rows_name" || no "$s: failed-order comparison $cmp_args ($(call_err | head -c 200))"
		else
			no "$s: failed-order comparison inputs (comparison=$cmp_start..$cmp_end, failed step=${failed_step:-none})"
		fi
	fi
	plan="$WORK/plan-$s-revenue-drop-triage.json"
	bd_name=$(tool_of "$s" wc-analytics/breakdown)
	if [ -f "$plan" ]; then
		truthy "$s: revenue-drop guidance names $bd_name for the optional revenue breakdown" "$(jq -r --arg n "$bd_name" '.instructions | contains($n + " with subject=revenue")' "$plan")"
	fi
	call_tool "$s" "$bd_name" '{"subject":"revenue","dimension":"country","period":"last_7_days","compare":true}'
	call_ok && ok "$s: optional revenue breakdown by country runs" || no "$s: revenue breakdown ($(call_err | head -c 200))"
	big_start=$(date -u -v-420d +%Y-%m-%d 2>/dev/null || date -u -d '420 days ago' +%Y-%m-%d)
	big_args="{\"subject\":\"revenue\",\"date_start\":\"$big_start\",\"date_end\":\"$WINDOW_CURRENT_END\"}"
	totals_name=$(tool_of "$s" wc-analytics/totals)
	confirm_name=$(tool_of "$s" wc-analytics/confirm-large-range)
	call_tool "$s" "$totals_name" "$big_args"
	big_err=$(call_err)
	# What the client receives is the test, not what the ability returned: Easy MCP AI (2.3.1 and
	# 2.4.0) cuts tool error text to 200 bytes (Detailed_Tool_Error::MAX_MESSAGE), so the
	# confirmation instruction must survive that cut to count.
	grep -q '\[truncated\]' <<<"$big_err" && note "$s: the client got a truncated large-range error (${#big_err} chars)"
	if [ -n "$big_err" ] && grep -q "$confirm_name" <<<"$big_err"; then ok "$s: >365-day request asks for confirmation through $confirm_name"; else no "$s: >365-day request names $confirm_name ($(head -c 300 <<<"$big_err"))"; fi
	grep -q 'wc-analytics-confirm-large-range' <<<"$big_err" && no "$s: large-range error still names wc-analytics-confirm-large-range" || ok "$s: large-range error has no obsolete alias"
	# The merchant says yes: confirm with only what the client received (tool name, dates, type).
	got_tool=$(grep -oE "$confirm_name" <<<"$big_err" | head -1)
	got_start=$(grep -oE "date_start='?[0-9]{4}-[0-9]{2}-[0-9]{2}" <<<"$big_err" | head -1 | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}')
	got_end=$(grep -oE "date_end='?[0-9]{4}-[0-9]{2}-[0-9]{2}" <<<"$big_err" | head -1 | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}')
	got_type=$(grep -oE "type='?[a-z_]+:[a-z_]+" <<<"$big_err" | head -1 | sed -E "s/^type='?//")
	eq "$s: the received error carries the confirm tool, dates and type" "$confirm_name $big_start $WINDOW_CURRENT_END totals:revenue" "${got_tool:-?} ${got_start:-?} ${got_end:-?} ${got_type:-?}"
	call_tool "$s" "${got_tool:-$confirm_name}" "$(jq -cn --arg a "$got_start" --arg b "$got_end" --arg t "$got_type" '{date_start:$a,date_end:$b,type:$t,description:"Revenue for the last 14 months (matrix)"}')"
	call_ok && ok "$s: merchant says yes: $confirm_name runs with the received values" || no "$s: $confirm_name ($(call_err | head -c 200))"
	call_tool "$s" "$totals_name" "$big_args"
	call_ok && ok "$s: retried >365-day request runs after confirmation" || no "$s: retry after confirmation ($(call_err | head -c 200))"
done

# Analytics guard firing (Analytics turned off): every transport's client gets the token and the
# fix path whole (Easy MCP AI passes at most 200 bytes of an error message).
save_option woocommerce_analytics_enabled
wpc option update woocommerce_analytics_enabled no >/dev/null
for s in "${TRANSPORTS[@]}"; do
	call_ability "$s" wc-analytics/totals "{\"subject\":\"revenue\",\"date_start\":\"$WINDOW_CURRENT_START\",\"date_end\":\"$WINDOW_CURRENT_END\"}"
	g_err=$(call_err)
	if grep -qF '[ANALYTICS_UNAVAILABLE]' <<<"$g_err" && grep -qF 'WooCommerce > Settings > Advanced > Features' <<<"$g_err" && ! grep -qF '[truncated]' <<<"$g_err"; then
		ok "$s: Analytics off: the client gets [ANALYTICS_UNAVAILABLE] and the fix path whole (${#g_err} chars)"
	else
		no "$s: Analytics off: token and fix path received ($(head -c 300 <<<"$g_err"))"
	fi
done
wpc option update woocommerce_analytics_enabled yes >/dev/null

# The same figures whichever connector answers, and the same as WooCommerce › Analytics.
if has_transport rec && has_transport easy; then
	eq "both: current figures match across transports" "${FIG[rec.cur]}" "${FIG[easy.cur]}"
	eq "both: previous figures match across transports" "${FIG[rec.prev]}" "${FIG[easy.prev]}"
fi
wc_stats() { # start end -> "orders_count net_revenue gross_sales"
	auth_cfg admin_app | curl -sS -K - --max-time 60 -G "$BASE/wp-json/wc-analytics/reports/revenue/stats" \
		--data-urlencode "after=${1}T00:00:00" --data-urlencode "before=${2}T23:59:59" --data-urlencode 'interval=day' \
		| jq -r '.totals | [.orders_count, .net_revenue, .gross_sales] | map(tostring) | join(" ")'
}
read -r wc_orders wc_net wc_gross <<<"$(wc_stats "$WINDOW_CURRENT_START" "$WINDOW_CURRENT_END")"
eq "WooCommerce › Analytics current period orders_count / net_revenue" "$CUR_ORDERS $CUR_NET" "$wc_orders $wc_net"
note "WooCommerce › Analytics current period gross_sales=$wc_gross"
read -r wc_orders wc_net wc_gross <<<"$(wc_stats "$WINDOW_PREVIOUS_START" "$WINDOW_PREVIOUS_END")"
eq "WooCommerce › Analytics previous period orders_count / net_revenue" "$PREV_ORDERS $PREV_NET" "$wc_orders $wc_net"

# ---------------------------------------------------------------------------------------------
# Role permissions: a shop manager through each transport (shop-manager diagnostics off).
# ---------------------------------------------------------------------------------------------
helper credentials.php user ensure blzmcp_matrix_sm shop_manager >/dev/null
UNDO+=("helper credentials.php user delete blzmcp_matrix_sm")
sm_sessions=()
if has_transport rec && make_cred sm_app app blzmcp_matrix_sm && open_session sm_rec rec sm_app; then sm_sessions+=(sm_rec); fi
if has_transport easy && make_cred sm_easy easy blzmcp_matrix_sm && open_session sm_easy easy sm_easy; then sm_sessions+=(sm_easy); fi
[ "${#sm_sessions[@]}" -eq "${#TRANSPORTS[@]}" ] && ok "shop manager sessions open" || no "shop manager sessions open (${sm_sessions[*]:-none})"
for s in "${sm_sessions[@]}"; do
	for a in "${BLAZE_ABILITIES[@]}"; do
		case "$a" in
		*/health-summary | */update-history | */access-check) want=deny ;;
		*) want=allow ;;
		esac
		call_ability "$s" "$a" "$(args_for "$a")"
		if call_ok; then got=allow; else got=deny; fi
		eq "$s: shop manager $want $(tool_of "$s" "$a")" "$want" "$got"
	done
	call_ability "$s" wc-analytics/totals "{\"subject\":\"revenue\",\"date_start\":\"$WINDOW_CURRENT_START\",\"date_end\":\"$WINDOW_CURRENT_END\"}"
	eq "$s: shop manager gets the same current figures" "$CUR_ORDERS $CUR_NET $CUR_REFUNDS" "$(call_get '[.metrics.orders_count, .metrics.net_sales, .metrics.refunds] | map(tostring) | join(" ")')"
	call_ability "$s" blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	truthy "$s: shop manager's weekly review leaves out the diagnostics steps" "$(call_get '[.steps[].ability] | (index("blaze-mcp-toolkit/health-summary") == null and index("blaze-mcp-toolkit/update-history") == null)')"
done

# ---------------------------------------------------------------------------------------------
# Easy MCP AI: a store seeded before 1.1, and an EASY_MCP_AI_TOOLS override.
# ---------------------------------------------------------------------------------------------
if has_transport easy; then
	pre11=$(jq -c '[.[] | select(startswith("wc-analytics/") | not)]' <<<"${FRESH_SEED:-[]}")
	wpc option update easy_mcp_ai_enabled_abilities "$pre11" --format=json >/dev/null
	open_session easy_pre easy admin_easy || no "pre-1.1 session"
	names=$(list_names easy_pre)
	listed=$(grep -c '^wp_ability_wc_analytics_' <<<"$names")
	eq "pre-1.1 seed: Easy MCP AI lists no wc-analytics tool" 0 "$listed"
	row=$(setup_row easy_tools_on)
	eq "pre-1.1 seed: easy_tools_on warns" warn "$(jq -r '.status' <<<"$row")"
	truthy "pre-1.1 seed: easy_tools_on names the wc-analytics tools to tick" "$(jq -r '(.label + " " + .fix) | test("totals")' <<<"$row")"
	call_ability easy_pre blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	truthy "pre-1.1 seed: weekly review leaves out the analytics steps" "$(call_get '[.steps[].ability | startswith("wc-analytics/")] | any | not')"
	after=$(option_json easy_mcp_ai_enabled_abilities)
	if jq -e 'map(select(startswith("wc-analytics/"))) | length > 0' <<<"$after" >/dev/null; then
		note "pre-1.1 seed: Easy MCP AI auto-ticked the annotated wc-analytics tools after tools/list"
	else
		note "pre-1.1 seed: Easy MCP AI did NOT auto-tick the wc-analytics tools (its tool policy is not recording yet on this store); the owner ticks them, as the easy_tools_on row says"
	fi
	wpc option update easy_mcp_ai_enabled_abilities "${FRESH_SEED:-[]}" --format=json >/dev/null

	off_tool=$(easy_name blaze-mcp-toolkit/update-history)
	wpc config set EASY_MCP_AI_TOOLS "$off_tool=off" --type=constant >/dev/null
	UNDO+=("wpc config has EASY_MCP_AI_TOOLS --type=constant && wpc config delete EASY_MCP_AI_TOOLS --type=constant; true")
	open_session easy_ovr easy admin_easy || no "override session"
	names=$(list_names easy_ovr)
	grep -qxF "$off_tool" <<<"$names" && no "EASY_MCP_AI_TOOLS override hides $off_tool from tools/list" || ok "EASY_MCP_AI_TOOLS override hides $off_tool from tools/list"
	call_tool easy_ovr "$off_tool" '{"days":7}'
	call_ok && no "EASY_MCP_AI_TOOLS override refuses $off_tool" || ok "EASY_MCP_AI_TOOLS override refuses $off_tool ($(call_err | head -c 80))"
	call_ability easy_ovr blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	truthy "override: weekly review leaves out the update-history step" "$(call_get '[.steps[].ability] | index("blaze-mcp-toolkit/update-history") == null')"
	truthy "override: weekly review says the site's configuration switched it off" "$(call_get '.instructions | test("configuration")')"
	wpc config delete EASY_MCP_AI_TOOLS --type=constant >/dev/null
fi

# ---------------------------------------------------------------------------------------------
# both: F02, Easy-ticked but on Enable Abilities' denylist, for health-summary then wc-analytics/totals.
# ---------------------------------------------------------------------------------------------
if [ "$STATE" == both ]; then
	save_option ewpa_thirdparty_disabled
	for slug in blaze-mcp-toolkit/health-summary wc-analytics/totals; do
		prev=$(option_json ewpa_thirdparty_disabled)
		new=$(jq -c --arg s "$slug" '(if type == "array" then . elif type == "object" then [.[]] else [] end) + [$s] | unique' <<<"${prev:-[]}")
		wpc option update ewpa_thirdparty_disabled "$new" --format=json >/dev/null
		jq -e --arg s "$slug" 'index($s) != null' <<<"$(option_json easy_mcp_ai_enabled_abilities)" >/dev/null && ok "F02 $slug: still ticked in Easy MCP AI" || no "F02 $slug: ticked in Easy MCP AI"
		open_session f02_rec rec admin_app || no "F02 rec session"
		open_session f02_easy easy admin_easy || no "F02 easy session"
		grep -qxF "$slug" <<<"$(list_names f02_rec)" && no "F02 $slug: absent from recommended discovery" || ok "F02 $slug: absent from recommended discovery"
		grep -qxF "$(easy_name "$slug")" <<<"$(list_names f02_easy)" && no "F02 $slug: absent from Easy MCP AI tools/list" || ok "F02 $slug: absent from Easy MCP AI tools/list"
		for s in f02_rec f02_easy; do
			call_ability "$s" blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
			if call_ok; then
				truthy "F02 $slug: $s weekly review leaves the step out" "$(call_get '[.steps[].ability] | index("'"$slug"'") == null')"
				truthy "F02 $slug: $s weekly review says Enable Abilities switched it off" "$(call_get '.instructions | test("Enable Abilities")')"
			else
				no "F02 $slug: $s weekly review ($(call_err | head -c 200))"
			fi
		done
		row=$(setup_row tools_visible)
		truthy "F02 $slug: tools_visible row names Enable Abilities" "$(jq -r '(.label + " " + .fix) | test("Enable Abilities")' <<<"$row")"
		wpc option update ewpa_thirdparty_disabled "${prev:-[]}" --format=json >/dev/null
	done
fi

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
