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

# This suite needs bash 4 or later (associative arrays). macOS /bin/bash is 3.2: re-run under a
# newer bash when there is one, otherwise fail with a clear reason.
if [ "${BASH_VERSINFO[0]}" -lt 4 ]; then
	for b in "$(command -v bash 2>/dev/null)" /opt/homebrew/bin/bash /usr/local/bin/bash; do
		[ -n "$b" ] && [ -x "$b" ] || continue
		[ "$("$b" -c 'echo "${BASH_VERSINFO[0]}"' 2>/dev/null)" -ge 4 ] 2>/dev/null && exec "$b" "$0" "$@"
	done
	echo "FAIL  http-suite.sh needs bash 4 or later (this is bash $BASH_VERSION; on macOS: brew install bash)"
	echo "0 passed, 1 failed"
	exit 1
fi

STATE=${1:?state}
PORT=${2:?port}
WPE=${WPE:-wp-env}
BASE="http://localhost:$PORT"
REC_URL="$BASE/wp-json/mcp/mcp-adapter-default-server"
EASY_URL="$BASE/wp-json/easy-mcp-ai/v1/mcp"
PROTO='2025-06-18'
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-http.XXXXXX")
# Reply classifiers and payload checks (fail closed; self-tested by tests/matrix/test-http-checks.sh).
# shellcheck source=tests/matrix/http-checks.sh
. "$(dirname "${BASH_SOURCE[0]}")/http-checks.sh"

pass=0
fail=0
ok() { pass=$((pass + 1)); echo "PASS  [$STATE] $*"; }
no() { fail=$((fail + 1)); echo "FAIL  [$STATE] $*"; }
note() { echo "NOTE  [$STATE] $*"; }
eq() { if [ "$2" == "$3" ]; then ok "$1"; else no "$1 (expected $2, got $3)"; fi; }
truthy() { if [ "$2" == "true" ]; then ok "$1"; else no "$1 (got $2)"; fi; }
# Transport receipt rows (1.2.1 item 5): one per route x authority x principal x case, PASSED only
# when no check failed between row_begin and row_end. A case that never reaches row_end leaves no
# row, which record.sh reports as a missing partition.
ROW_F0=0
row_begin() { ROW_F0=$fail; }
row_end() { # route authority principal case
	local r=PASSED
	[ "$fail" -gt "$ROW_F0" ] && r=FAILED
	echo "TRANSPORT_ROW=$STATE|$1|$2|$3|$4|$r"
}

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
	blaze-mcp-toolkit/suggest-improvements blaze-mcp-toolkit/payment-health
)
PAY=blaze-mcp-toolkit/payment-health
WORKFLOWS=(weekly-store-review revenue-drop-triage failed-order-triage refund-triage)
# The sales workflows list payment health first; refund triage has no payment step.
PAYMENT_FIRST=(weekly-store-review revenue-drop-triage failed-order-triage)
ANALYTICS=(wc-analytics/confirm-large-range wc-analytics/totals wc-analytics/breakdown wc-analytics/series wc-analytics/rows)

# Easy MCP AI tool name rule (2.3.1 = 2.4.0; no slug here is over 64 characters).
easy_name() { printf 'wp_ability_%s' "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//')"; }

# Arguments for a direct call of each Blaze ability.
args_for() {
	case "$1" in
	blaze-mcp-toolkit/health-summary | blaze-mcp-toolkit/payment-health) echo '{"since_hours":24}' ;;
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
make_cred() { # name kind(app|easy) login [quiet: a refusal is returned and noted, not failed]
	local out id secret
	if [ "$2" == app ]; then
		out=$(helper credentials.php app-password create "$3")
	else
		out=$(helper credentials.php easy-token create "$3")
	fi
	id=$(printf '%s\n' "$out" | sed -n 's/^ID=//p' | head -1)
	secret=$(printf '%s\n' "$out" | sed -n 's/^SECRET=//p' | head -1)
	if [ -z "$id" ] || [ -z "$secret" ]; then
		if [ "${4:-}" == quiet ]; then
			note "credential $1 ($2 for $3) refused: $(printf '%s\n' "$out" | grep '^ERROR=' | head -1)"
		else
			no "credential $1 ($2 for $3) created: $(printf '%s\n' "$out" | grep '^ERROR=' | head -1)"
		fi
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
auth_cfg() { # cred name -> curl config line on stdout (piped straight into curl -K -); kind none = anonymous
	if [ "${CRED_KIND[$1]}" == none ]; then
		return 0
	elif [ "${CRED_KIND[$1]}" == app ]; then
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
	: >"$WORK/raw" # a failed request must never leave the previous reply in place
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
# Open a session: name kind(rec|easy) cred [quiet]. Preflight: anything but a JSON-RPC result is
# fatal. quiet: a refusal is expected (a denied principal, or no server in none): no TRANSPORT line.
open_session() {
	local url
	[ "$2" == rec ] && url=$REC_URL || url=$EASY_URL
	RPC_ID=$((RPC_ID + 1))
	rpc_raw "$url" "$3" '' "{\"jsonrpc\":\"2.0\",\"id\":$RPC_ID,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"$PROTO\",\"capabilities\":{},\"clientInfo\":{\"name\":\"blzmcp-matrix\",\"version\":\"1.1\"}}}"
	if [ "$RPC_CODE" != 200 ] || [ "$(jq -r 'if (type == "object" and has("result") and (.result | type) == "object") then "ok" else "no" end' "$RPC_BODY" 2>/dev/null)" != ok ]; then
		[ "${4:-}" == quiet ] || echo "TRANSPORT UNREACHABLE: $url $RPC_CODE"
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
	# No exposed tool (1.2.1 item 5): even an administrator with a valid application password
	# reaches no payment health on either route. A server that does answer must neither list it nor run it.
	if make_cred admin_app app admin; then
		for r in rec easy; do
			row_begin
			if open_session "none_$r" "$r" admin_app quiet; then
				names=$(list_names "none_$r")
				grep -qxF "$(tool_of "none_$r" "$PAY")" <<<"$names" && no "$r route answers in none but lists payment health" || ok "$r route answers in none (http 200) and does not list payment health"
				call_ability "none_$r" "$PAY" '{"since_hours":24}'
				expect_call_refusal "$r route refuses payment health in none with the known tool-off answer, no method facts" "$r" "$PAY" tool_off
			else
				# Fail closed (REVIEW-pass4a R4): only the absent-route answer proves there is no server.
				expect_init_refusal "$r route has no MCP server in none (http 404 rest_no_route)" no_route
			fi
			row_end "$r" any admin no_tool
		done
	else
		no "none: an administrator credential for the no-tool check"
	fi
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
	eq "fresh activation after Blaze: Easy MCP AI seeds all ${#BLAZE_ABILITIES[@]} Blaze tools and ${#ANALYTICS[@]} wc-analytics tools (missing: ${missing[*]:-none})" 0 "${#missing[@]}"
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
	row_begin
	rpc rec tools/list '{}'
	eq "recommended tools/list is the 3 gateway tools" 'mcp-adapter-discover-abilities,mcp-adapter-execute-ability,mcp-adapter-get-ability-info' "$(jq -r '[.result.tools[]?.name] | sort | join(",")' "$RPC_BODY")"
	names=$(list_names rec)
	missing=()
	for a in "${BLAZE_ABILITIES[@]}" "${ANALYTICS[@]}"; do grep -qxF "$a" <<<"$names" || missing+=("$a"); done
	eq "recommended discover-abilities lists every Blaze and wc-analytics ability (missing: ${missing[*]:-none})" 0 "${#missing[@]}"
	row_end rec any admin discovery
fi
if has_transport easy; then
	row_begin
	names=$(list_names easy)
	missing=()
	for a in "${BLAZE_ABILITIES[@]}" "${ANALYTICS[@]}"; do grep -qxF "$(easy_name "$a")" <<<"$names" || missing+=("$(easy_name "$a")"); done
	eq "Easy MCP AI tools/list has every Blaze and wc-analytics tool (missing: ${missing[*]:-none})" 0 "${#missing[@]}"
	row_end easy any admin discovery
fi

# ---------------------------------------------------------------------------------------------
# Every Blaze ability, then every workflow and each step it names, through each transport.
# ---------------------------------------------------------------------------------------------
declare -A FIG
for s in "${TRANSPORTS[@]}"; do
	row_begin
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
		if [[ " ${PAYMENT_FIRST[*]} " == *" $w "* ]]; then
			eq "$s: $w runs payment health first, as $(tool_of "$s" "$PAY")" "$PAY $(tool_of "$s" "$PAY")" "$(jq -r '.steps[0] | "\(.ability) \(.tool)"' "$plan")"
			eq "$s: $w order totals come straight after payment health" 'wc-analytics/totals orders' "$(jq -r '.steps[1] | "\(.ability) \(.arguments.subject)"' "$plan")"
			truthy "$s: $w has the PAYMENT CHECK paragraph" "$(jq -r '.instructions | contains("PAYMENT CHECK")' "$plan")"
		else
			truthy "$s: $w has no payment step and no PAYMENT CHECK paragraph" "$(jq -r --arg p "$PAY" '([.steps[].ability] | index($p) == null) and (.instructions | contains("PAYMENT CHECK") | not)' "$plan")"
		fi
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
		# The order totals step, selected by ability and subject (payment health is now step 1).
		totals_step=$(jq -c '[.steps[] | select(.ability == "wc-analytics/totals" and .arguments.subject == "orders")][0] // empty' "$plan")
		[ -n "$totals_step" ] && ok "$s: failed-order plan has the order totals step" || no "$s: failed-order plan has the order totals step"
		[ -n "$totals_step" ] || totals_step='{}'
		call_tool "$s" "$(jq -r '.tool // ""' <<<"$totals_step")" "$(jq -c '.arguments // {}' <<<"$totals_step")"
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
	row_end "$s" any admin workflows
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
	row_begin
	for a in "${BLAZE_ABILITIES[@]}"; do
		case "$a" in
		*/health-summary | */update-history | */access-check) want=deny ;;
		*) want=allow ;;
		esac
		call_ability "$s" "$a" "$(args_for "$a")"
		# A deny counts only as the route's known permission refusal (REVIEW-pass4a R4).
		if call_ok; then got=allow; else got=$(call_refusal "${S_KIND[$s]}" "$a"); [ "$got" == perm ] && got=deny; fi
		eq "$s: shop manager $want $(tool_of "$s" "$a")" "$want" "$got"
	done
	call_ability "$s" wc-analytics/totals "{\"subject\":\"revenue\",\"date_start\":\"$WINDOW_CURRENT_START\",\"date_end\":\"$WINDOW_CURRENT_END\"}"
	eq "$s: shop manager gets the same current figures" "$CUR_ORDERS $CUR_NET $CUR_REFUNDS" "$(call_get '[.metrics.orders_count, .metrics.net_sales, .metrics.refunds] | map(tostring) | join(" ")')"
	call_ability "$s" blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	truthy "$s: shop manager's weekly review leaves out the diagnostics steps" "$(call_get '[.steps[].ability] | (index("blaze-mcp-toolkit/health-summary") == null and index("blaze-mcp-toolkit/update-history") == null)')"
	row_end "${s#sm_}" any manager roles
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
	expect_call_refusal "EASY_MCP_AI_TOOLS override refuses $off_tool with the known switched-off answer" easy blaze-mcp-toolkit/update-history tool_off
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

# ---------------------------------------------------------------------------------------------
# Payment health over HTTP (1.2 item 9; 1.2.1 FEATURE-PLAN item 5). Seeded after every fixed
# Analytics comparison above, and removed again here (tests/matrix/payment-fixture.php), so those
# figures never move. The fixture now includes orders outside verified checkout activity (a mixed
# cod, a registered disabled bacs and an unregistered blzmcp_phone, C3/C4).
#
# Each authoritative order storage (HPOS, then legacy; sync off) is switched in its own process,
# checked, seeded, exercised through every route of this state and cleaned up, then the original
# storage comes back. Every case prints one machine-readable receipt row,
#   TRANSPORT_ROW=<state>|<route>|<authority>|<principal>|<case>|PASSED|FAILED
# (authority "any" for the cases that do not depend on the order store). run-matrix.sh collects
# them and record.sh fails the evidence when a required row is missing, duplicated or unknown.
# ---------------------------------------------------------------------------------------------
UNDO+=("helper payment-fixture.php cleanup")
ROLE_NOTE='not allowed to see store diagnostics'
PAY_KEYS='checked_at,coverage,findings,findings_total,findings_truncated,inventory,methods,notes,overall,schema_version,since_hours,store_wide,timezone'
PAY_AUTHORITIES=${BLZMCP_HTTP_AUTHORITIES:-hpos legacy}
# The fixed public disclosure text, read from the plugin (not copied here, so wording stays the plugin's).
OUTSIDE_TEXT=$(wpc eval 'echo Blaze\McpToolkit\Abilities\PaymentHealth::reason_text( "outside_checkout_only" );' | tr -d '\r' | tail -1)
[ -n "$OUTSIDE_TEXT" ] && ok "the outside-checkout disclosure text is read from the plugin" || no "the outside-checkout disclosure text is read from the plugin"
DIAG_DENIED_TEXT=$(wpc eval 'echo Blaze\McpToolkit\Abilities\PaymentHealth::reason_text( "diagnostics_not_permitted" );' | tr -d '\r' | tail -1)
[ -n "$DIAG_DENIED_TEXT" ] && ok "the diagnostics role reason text is read from the plugin" || no "the diagnostics role reason text is read from the plugin"
pay_call() { # session [clear] -> payment health with since_hours 24; clear=1 empties the toolkit result cache first
	[ "${2:-0}" == 1 ] && helper payment-fixture.php clear-cache >/dev/null
	call_ability "$1" "$PAY" '{"since_hours":24}'
}
role_note() { jq -r --arg n "$ROLE_NOTE" '[.ok.notes[]? | contains($n)] | any' "$CALL_OUT"; }
# Method facts of the fixture that a denied caller must never receive, in a body or an error.
leaks() { grep -qE 'blzmcp_phone|blzmcp_gone|taken_28d|outside_checkout_observed|inventory_only|gateway_jobs_failed|gateway_job_failed' "$1"; }
set_sm_diag() { wpc eval "Blaze\\McpToolkit\\Settings::update( array( 'shop_manager_diagnostics' => $1 ) );" >/dev/null; }
pf_switch() { # authority sync: switched in one process (the integration suite's own switch), asserted in the next
	"$WPE" run cli env BLZMCP_PF_SET="$1" BLZMCP_PF_SYNC="$2" wp eval-file wp-content/blzmcp-tests/Integration/test-payment-orders.php 2>&1 | grep -qE '^[0-9]+ passed, 0 failed'
}
authority_now() { wpc eval 'echo ( Blaze\McpToolkit\Diagnostics\PaymentOrders::authority()["authority"] ?? "none" ) . " " . get_option( "woocommerce_custom_orders_table_data_sync_enabled", "no" );' | tr -d '\r' | tail -1; }
pay_seed() { # -> PAY_SEEDED PAY_CHECKOUT PAY_COD PAY_OUTSIDE
	local out
	out=$(helper payment-fixture.php seed)
	PAY_SEEDED=$(sed -n 's/^SEEDED=//p' <<<"$out")
	PAY_CHECKOUT=$(sed -n 's/^CHECKOUT=//p' <<<"$out")
	PAY_COD=$(sed -n 's/^COD_CHECKOUT=//p' <<<"$out")
	PAY_OUTSIDE=$(sed -n 's/^OUTSIDE=//p' <<<"$out")
	PAY_JOB=$(sed -n 's/^FAILED_JOB=//p' <<<"$out")
	if [ "${PAY_SEEDED:-0}" -gt 0 ] && [ "${PAY_OUTSIDE:-0}" -gt 0 ] && [ -n "$PAY_CHECKOUT" ] && [ -n "$PAY_COD" ] && [ "${PAY_JOB:-0}" == 1 ]; then
		ok "payment fixture seeded on $1 ($PAY_CHECKOUT checkout orders, $PAY_OUTSIDE outside verified checkout activity, 1 failed Stripe payment job)"
	else
		no "payment fixture seeded on $1 ($(head -c 200 <<<"$out"))"
		PAY_CHECKOUT=${PAY_CHECKOUT:-0} PAY_COD=${PAY_COD:-0} PAY_SEEDED=${PAY_SEEDED:-0}
	fi
}
pay_unseed() {
	local out
	out=$(helper payment-fixture.php cleanup)
	eq "payment fixture removed on $1 (only its own orders and its failed job)" "${PAY_SEEDED:-0} 1" "$(sed -n 's/^REMOVED=//p' <<<"$out" | head -1) $(sed -n 's/^JOBS_REMOVED=//p' <<<"$out" | head -1)"
}
# The full disclosure contract on the last call's result (an administrator's, or a manager's).
# kind (rec|easy) is the route: Easy MCP AI 2.3.1 and 2.4.0 deliver the integer schema_version
# (declared type integer, enum [2]) as the string "2" (seen in the 1.2.1 wave 2 matrix; the 1.2
# suite's string comparison hid it). That one coercion is accepted on the Easy route and noted;
# every other field is checked with its type.
disclosure_checks() { # label kind
	local res n=0 okv lbl
	if [ "${2:-}" == easy ] && [ "$(jq -r '.ok.schema_version | type' "$CALL_OUT" 2>/dev/null)" == string ]; then
		note "$1: Easy MCP AI delivered schema_version as the string $(jq -c '.ok.schema_version' "$CALL_OUT") (the plugin declares an integer)"
	fi
	res=$(jq -c --arg kind "${2:-}" --arg keys "$PAY_KEYS" --argjson co "${PAY_CHECKOUT:-0}" --argjson cod "${PAY_COD:-0}" --arg txt "$OUTSIDE_TEXT" '
		.ok as $r | ($r.methods // []) as $m | ($m | map({key: .id, value: .}) | from_entries) as $by
		| def unassessed($id): ($by[$id] // {}) as $x
			| ($x.assessment == "inventory_only" and $x.status == "unknown"
			and $x.activity == {checkout_observed: false, outside_checkout_observed: true, coverage: "complete", reason: null}
			and ($x.quiet | .status == "unknown" and .checked == false and .applicable == false and .coverage == "not_applicable" and .taken_28d == null)
			and ([$x.stuck, $x.errors.logs, $x.errors.jobs, $x.errors.webhook] | all(.checked == false and .applicable == false and .coverage == "not_applicable"))
			and (($x.stuck.examples // []) == []) and $x.reasons == [$txt]);
		[
			["top-level keys are the schema 2 contract", (($r | keys | sort | join(",")) == $keys)],
			["schema_version 2, since_hours 24 (received \($r.schema_version | tojson), \($r.since_hours | tojson))", (($r.schema_version == 2 or ($kind == "easy" and $r.schema_version == "2")) and $r.since_hours == 24)],
			["overall red (cheque), store-wide quiet green", ($r.overall == "red" and $r.store_wide.status == "green")],
			["store-wide taken_28d counts only the \($co) checkout orders", ($r.store_wide.taken_28d == $co)],
			["silent cheque method is red on its quiet check", (($by.cheque // {}) | .status == "red" and .quiet.status == "red" and .assessment == "checkout_health")],
			["method_quiet cheque and method_not_registered blzmcp_gone still lead", ([$r.findings[] | "\(.code) \(.method_id)"] | (index("method_quiet cheque") != null and index("method_not_registered blzmcp_gone") != null))],
			["mixed cod: checkout_health, \($cod) checkout orders (outside orders not counted), seen in both", (($by.cod // {}) | .assessment == "checkout_health" and .quiet.taken_28d == $cod and .quiet.status == "green" and .activity.checkout_observed == true and .activity.outside_checkout_observed == true)],
			["bacs (registered, switched off, no-origin orders) is inventory_only and unassessed", (unassessed("bacs") and $by.bacs.registered == true and $by.bacs.enabled == false)],
			["blzmcp_phone (unregistered, phone-order origin) is inventory_only and unassessed", (unassessed("blzmcp_phone") and $by.blzmcp_phone.registered == false)],
			["no finding for an inventory-only method", ([$r.findings[] | .method_id] | (index("bacs") == null and index("blzmcp_phone") == null))],
			["inventory block complete, nothing truncated", ($r.inventory == {checked: true, coverage: "complete", reason: null, methods_truncated: false})],
			["top-level coverage is partial while inventory-only methods are unassessed", ($r.coverage == "partial")],
			["a top-level note names both inventory-only methods", (($r.notes // []) | map(contains("bacs") and contains("blzmcp_phone")) | any)],
			["one row per method ID, sorted by ID", (($m | map(.id)) as $ids | $ids == ($ids | unique))],
			["no customer address or gateway settings in the response", (($r | tostring) | (test("example\\.invalid") or test("woocommerce_(cod|bacs)_settings")) | not)]
		] | map({label: .[0], ok: .[1]})' "$CALL_OUT" 2>/dev/null)
	while IFS=$'\t' read -r okv lbl; do
		[ -n "$lbl" ] || continue
		n=$((n + 1))
		if [ "$okv" == true ]; then ok "$1: $lbl"; else no "$1: $lbl"; fi
	done < <(jq -r '.[] | "\(.ok)\t\(.label)"' <<<"${res:-[]}" 2>/dev/null)
	[ "$n" -gt 0 ] || no "$1: disclosure checks could not be evaluated ($(call_err | head -c 120))"
}
# The outside-checkout facts a manager keeps with diagnostics off, plus checkout parity.
manager_facts() { # label
	truthy "$1: inventory-only rows present, cod checkout count unchanged ($PAY_COD)" "$(jq -r --argjson cod "${PAY_COD:-0}" '.ok as $r | ($r.methods // []) | map({key: .id, value: .}) | from_entries
		| (.bacs.assessment == "inventory_only" and .blzmcp_phone.assessment == "inventory_only" and .bacs.activity.outside_checkout_observed == true and .cod.quiet.taken_28d == $cod)' "$CALL_OUT" 2>/dev/null)"
}
# Diagnostics withheld or exposed (REVIEW-pass4a R4), on the last call's result: withheld = every
# applicable log/job check not checked, unavailable, role reason, null quantities, empty groups and
# hooks, no diagnostic finding (and at least one applicable check, so it is never vacuous); exposed
# = the fixture's failed Stripe payment job is reported with its finding.
diag_checks() { # label withheld|exposed
	local okv lbl n=0
	while IFS=$'\t' read -r lbl okv; do
		[ -n "$lbl" ] || continue
		n=$((n + 1))
		if [ "$okv" == true ]; then ok "$1: $lbl"; else no "$1: $lbl"; fi
	done < <(if [ "$2" == withheld ]; then diag_withheld_checks "$CALL_OUT" "$ROLE_NOTE" "$DIAG_DENIED_TEXT"; else diag_exposed_checks "$CALL_OUT" "$ROLE_NOTE"; fi)
	[ "$n" -gt 0 ] || no "$1: diagnostics $2 checks could not be evaluated"
}
# Low-privileged principals: a customer and a subscriber with their own credential per route, and
# an anonymous caller with none.
helper credentials.php user ensure blzmcp_matrix_cu customer >/dev/null
UNDO+=("helper credentials.php user delete blzmcp_matrix_cu")
helper credentials.php user ensure blzmcp_matrix_sub subscriber >/dev/null
UNDO+=("helper credentials.php user delete blzmcp_matrix_sub")
CRED_KIND[anon]=none
declare -A DENY_CRED
for r in "${TRANSPORTS[@]}"; do
	for p in customer:blzmcp_matrix_cu subscriber:blzmcp_matrix_sub; do
		kind=app
		[ "$r" == easy ] && kind=easy
		# A credential the helper could not make is a failure, never a denial (REVIEW-pass4a R4).
		if make_cred "${p%%:*}_$r" "$kind" "${p##*:}"; then DENY_CRED[${p%%:*}_$r]=${p%%:*}_$r; else DENY_CRED[${p%%:*}_$r]=refused; fi
	done
	DENY_CRED[anonymous_$r]=anon
done
# Fail closed (REVIEW-pass4a R4): a denial passes only on the route's known authentication refusal
# at initialize or its known permission refusal of the call. No reply (HTTP 000), 5xx, a malformed
# or unrelated reply, an unknown error text and a missing credential all fail the case.
deny_case() { # route principal
	local cred=${DENY_CRED[$2_$1]:-} sname="deny_$1_$2"
	if [ -z "$cred" ] || [ "$cred" == refused ]; then
		no "$1: $2 has a credential for this route (the credential helper failed, so the denial is unproven)"
		return
	fi
	if ! open_session "$sname" "$1" "$cred" quiet; then
		expect_init_refusal "$1: $2 refused at initialize with the known authentication refusal, no method facts" auth
		return
	fi
	pay_call "$sname" # no flush: a privileged result is warm in the cache
	expect_call_refusal "$1: $2 is denied payment health with the known permission refusal" "$1" "$PAY" perm
	if leaks "$RPC_BODY"; then no "$1: $2 receives no method facts"; else ok "$1: $2 receives no method facts"; fi
}

save_option blzmcp_settings
pf_orig_hpos=$(wpc option get woocommerce_custom_orders_table_enabled | tr -d '\r' | tail -1)
pf_orig_sync=$(wpc option get woocommerce_custom_orders_table_data_sync_enabled | tr -d '\r' | tail -1)
[ "$pf_orig_hpos" == yes ] && pf_orig_set=hpos || pf_orig_set=legacy
[ "$pf_orig_sync" == yes ] || pf_orig_sync=no
UNDO+=("pf_switch '$pf_orig_set' '$pf_orig_sync'")
for auth in $PAY_AUTHORITIES; do
	if ! pf_switch "$auth" no || [ "$(authority_now)" != "$auth no" ]; then
		no "order storage switched to $auth (sync off) for the HTTP payment cases (now: $(authority_now))"
		continue
	fi
	ok "order storage is $auth (sync off) in a fresh process"
	pay_seed "$auth"
	for s in "${TRANSPORTS[@]}"; do
		row_begin
		pay_call "$s" 1
		if call_ok; then
			ok "$s [$auth]: payment health runs over HTTP as $(tool_of "$s" "$PAY")"
			disclosure_checks "$s [$auth]" "${S_KIND[$s]}"
			eq "$s [$auth]: administrator sees no diagnostics role note" false "$(role_note)"
			first_at=$(call_get '.checked_at')
			pay_call "$s"
			eq "$s [$auth]: a warm repeat is served from the 5-minute cache with its original checked_at" "$first_at" "$(call_get '.checked_at')"
		else
			no "$s [$auth]: payment health runs over HTTP ($(call_err | head -c 200))"
		fi
		row_end "$s" "$auth" admin disclosure
	done
	# Diagnostics permission: off, on, then off again after the on result is warm (no cache flush).
	# The administrator is never affected by the shop-manager setting; the manager keeps the
	# outside-checkout facts with diagnostics off.
	for phase in diag_off diag_on diag_off_again; do
		case "$phase" in diag_on) set_sm_diag true; want_note=false ;; *) set_sm_diag false; want_note=true ;; esac
		for s in "${TRANSPORTS[@]}"; do
			row_begin
			pay_call "$s"
			if call_ok; then
				eq "$s [$auth] $phase: administrator has no diagnostics role note" false "$(role_note)"
				manager_facts "$s [$auth] $phase: administrator"
				diag_checks "$s [$auth] $phase: administrator diagnostics" exposed
			else
				no "$s [$auth] $phase: administrator runs payment health ($(call_err | head -c 200))"
			fi
			row_end "$s" "$auth" admin "$phase"
			row_begin
			sm="sm_$s"
			if [ -z "${S_URL[$sm]:-}" ]; then
				no "$s [$auth] $phase: shop manager session open"
			else
				clear=0
				[ "$phase" == diag_off ] && clear=1
				pay_call "$sm" "$clear"
				if call_ok; then
					case "$phase" in
					diag_off) lbl='diagnostics off: logs and jobs reported as not checked for the role' ;;
					diag_on) lbl='diagnostics switched on: a fresh result without the role note (no flush)' ;;
					*) lbl='diagnostics switched off after warming: never served the privileged result' ;;
					esac
					eq "$s [$auth]: shop manager, $lbl" "$want_note" "$(role_note)"
					manager_facts "$s [$auth] $phase: shop manager"
					if [ "$phase" == diag_on ]; then
						diag_checks "$s [$auth] $phase: shop manager diagnostics" exposed
					else
						diag_checks "$s [$auth] $phase: shop manager diagnostics" withheld
					fi
				else
					no "$s [$auth] $phase: shop manager runs payment health (manage_woocommerce) ($(call_err | head -c 200))"
				fi
			fi
			row_end "$s" "$auth" manager "$phase"
		done
	done
	# Customers, subscribers and anonymous callers are denied, with a privileged result warm.
	for s in "${TRANSPORTS[@]}"; do
		for p in customer subscriber anonymous; do
			row_begin
			deny_case "$s" "$p"
			row_end "$s" "$auth" "$p" denied
		done
	done
	# Warm downgrade: the manager's privileged result is warm, then the role loses manage_woocommerce.
	for s in "${TRANSPORTS[@]}"; do
		row_begin
		sm="sm_$s"
		if [ -z "${S_URL[$sm]:-}" ]; then
			no "$s [$auth]: warm downgrade needs the shop manager session"
		else
			set_sm_diag true
			pay_call "$sm" 1
			if call_ok && [ "$(role_note)" == false ]; then ok "$s [$auth]: shop manager with diagnostics warms a privileged result"; else no "$s [$auth]: shop manager warms a privileged result ($(call_err | head -c 120))"; fi
			diag_checks "$s [$auth]: the warmed manager result" exposed
			helper credentials.php user ensure blzmcp_matrix_sm customer >/dev/null
			pay_call "$sm"
			expect_call_refusal "$s [$auth]: after the downgrade to customer the warm result is refused with the known permission refusal" "${S_KIND[$sm]}" "$PAY" perm
			if leaks "$RPC_BODY"; then no "$s [$auth]: the downgraded user receives no method facts"; else ok "$s [$auth]: the downgraded user receives no method facts"; fi
			helper credentials.php user ensure blzmcp_matrix_sm shop_manager >/dev/null
			set_sm_diag false
		fi
		row_end "$s" "$auth" manager warm_downgrade
	done
	pay_unseed "$auth"
done
if pf_switch "$pf_orig_set" "$pf_orig_sync" && [ "$(authority_now)" == "$pf_orig_set $pf_orig_sync" ]; then
	ok "order storage restored to $pf_orig_set (sync=$pf_orig_sync)"
else
	no "order storage restored to $pf_orig_set (sync=$pf_orig_sync), now $(authority_now)"
fi

# Analytics unavailable: payment health still first and its counts still reported.
pay_seed "$pf_orig_set"
wpc option update woocommerce_analytics_enabled no >/dev/null
for s in "${TRANSPORTS[@]}"; do
	row_begin
	call_ability "$s" blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	if call_ok; then
		jq '.ok' "$CALL_OUT" >"$WORK/plan-off-$s.json"
		eq "$s: Analytics off: weekly review still runs payment health first" "$PAY" "$(jq -r '.steps[0].ability' "$WORK/plan-off-$s.json")"
		call_tool "$s" "$(jq -r '.steps[0].tool' "$WORK/plan-off-$s.json")" "$(jq -c '.steps[0].arguments' "$WORK/plan-off-$s.json")"
		truthy "$s: Analytics off: payment health still reports its counts and findings" "$(call_get '(.overall == "red") and (([.methods[] | select(.id == "cheque")][0].quiet.taken_28d // 0) > 0)')"
	else
		no "$s: Analytics off: weekly review returns a plan ($(call_err | head -c 200))"
	fi
	row_end "$s" any admin analytics_off
done
wpc option update woocommerce_analytics_enabled yes >/dev/null

# Payment health switched off: recommended through Enable Abilities' list, Easy MCP AI by leaving
# it unticked (an existing 1.1 install). Setup warns; the review explains the skipped step once.
if has_transport rec; then
	row_begin
	save_option ewpa_thirdparty_disabled
	prev=$(option_json ewpa_thirdparty_disabled)
	wpc option update ewpa_thirdparty_disabled "$(jq -c --arg s "$PAY" '(if type == "array" then . elif type == "object" then [.[]] else [] end) + [$s] | unique' <<<"${prev:-[]}")" --format=json >/dev/null
	open_session pay_off_rec rec admin_app || no "payment off: recommended session"
	grep -qxF "$PAY" <<<"$(list_names pay_off_rec)" && no "payment off: absent from recommended discovery" || ok "payment off: absent from recommended discovery"
	call_ability pay_off_rec "$PAY" '{"since_hours":24}'
	expect_call_refusal "payment off (recommended): a direct call gets the known not-found answer without method facts" rec "$PAY" tool_off
	call_ability pay_off_rec blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	truthy "payment off (recommended): weekly review leaves the step out and says payments were not checked" "$(jq -r --arg p "$PAY" '(.ok | ([.steps[].ability] | index($p) == null) and (.instructions | contains("The payment health check is not in this plan")))' "$CALL_OUT")"
	wpc option update ewpa_thirdparty_disabled "${prev:-[]}" --format=json >/dev/null
	row_end rec any admin tool_off
fi
if has_transport easy; then
	row_begin
	save_option easy_mcp_ai_enabled_abilities
	prev=$(option_json easy_mcp_ai_enabled_abilities)
	wpc option update easy_mcp_ai_enabled_abilities "$(jq -c --arg s "$PAY" 'map(select(. != $s))' <<<"${prev:-[]}")" --format=json >/dev/null
	open_session pay_off_easy easy admin_easy || no "payment off: Easy MCP AI session"
	grep -qxF "$(easy_name "$PAY")" <<<"$(list_names pay_off_easy)" && no "payment off: absent from Easy MCP AI tools/list" || ok "payment off: absent from Easy MCP AI tools/list"
	call_ability pay_off_easy "$PAY" '{"since_hours":24}'
	expect_call_refusal "payment off (Easy MCP AI): a direct call gets the known switched-off answer without method facts" easy "$PAY" tool_off
	call_ability pay_off_easy blaze-mcp-toolkit/weekly-store-review '{"period":"last_7_days"}'
	truthy "payment off (Easy MCP AI): weekly review leaves the step out and says payments were not checked" "$(jq -r --arg p "$PAY" '(.ok | ([.steps[].ability] | index($p) == null) and (.instructions | contains("The payment health check is not in this plan")))' "$CALL_OUT")"
	row=$(setup_row easy_tools_on)
	eq "payment off (Easy MCP AI): easy_tools_on warns" warn "$(jq -r '.status' <<<"$row")"
	truthy "payment off (Easy MCP AI): easy_tools_on names payment health" "$(jq -r --arg p "$PAY" '(.label + " " + .fix) | contains($p)' <<<"$row")"
	eq "payment off (Easy MCP AI): the setup check never ticks it for the owner" false "$(jq -r --arg p "$PAY" 'index($p) != null' <<<"$(option_json easy_mcp_ai_enabled_abilities)")"
	wpc option update easy_mcp_ai_enabled_abilities "${prev:-[]}" --format=json >/dev/null
	row_end easy any admin tool_off
fi
pay_unseed "$pf_orig_set"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
