#!/usr/bin/env bash
# Offline self-test for the HTTP suite's fail-closed safety checks (1.2.1 item 5, REVIEW-pass4a R4).
# No Docker, no WordPress, no network: injected replies only, written to a temporary directory.
#
#   bash tests/matrix/test-http-checks.sh
#
# 1. The classifiers in tests/matrix/http-checks.sh on known and injected replies.
# 2. Full path: deny_case, row_begin/row_end and diag_checks extracted from tests/matrix/http-suite.sh
#    (the lead's pass4a probe method: the original functions with only their I/O boundary stubbed).
#    HTTP 000, 500 and a failed HTTP-200 initialise, a credential helper failure, a 5xx, malformed,
#    unrelated or unknown-text call reply and a successful result must each produce a FAILED row;
#    the known refusals must produce a PASSED row. Only http-checks.sh is loaded beside them, as in
#    the suite: no helper is predefined for the extracted functions.
# 2b. Early paths in their real order (REVIEW-pass4b R4): the suite's none branch and its Easy MCP
#    AI override block run as written, after http-checks.sh and the suite functions defined above
#    them, with only the WordPress/HTTP boundary stubbed. Known refusals carrying method facts and
#    a method-fact checker error must fail; clean known refusals must pass; no checker may print
#    an execution error.
# 3. Diagnostics: a payload with the expected role note but leaked diagnostics fails; a vacuous
#    payload (no applicable check) fails; the on phase must show the applicable failed job.
# 4. Structure: every safety case in http-suite.sh goes through the fail-closed helpers.
#
# Needs bash 4 (associative arrays, as http-suite.sh) and jq.
set -uo pipefail

if [ "${BASH_VERSINFO[0]}" -lt 4 ]; then
	for b in "$(command -v bash 2>/dev/null)" /opt/homebrew/bin/bash /usr/local/bin/bash; do
		[ -n "$b" ] && [ -x "$b" ] || continue
		[ "$("$b" -c 'echo "${BASH_VERSINFO[0]}"' 2>/dev/null)" -ge 4 ] 2>/dev/null && exec "$b" "$0" "$@"
	done
	echo "FAIL  test-http-checks.sh needs bash 4 or later (this is bash $BASH_VERSION)"
	echo "0 passed, 1 failed"
	exit 1
fi
command -v jq >/dev/null || { echo "FAIL  jq is required"; echo "0 passed, 1 failed"; exit 1; }

REPO=$(cd "$(dirname "$0")/../.." && pwd)
SUITE=${BLZMCP_TEST_HTTP_SUITE:-$REPO/tests/matrix/http-suite.sh} # override: run every case against another copy
# The helper the suite sources is the one beside it (http-suite.sh sources its own directory's copy).
CHECKS=$(cd "$(dirname "$SUITE")" && pwd)/http-checks.sh
# The card-testing cases (1.3 item 8), sourced by the suite right after http-checks.sh.
CARD_HTTP=$(cd "$(dirname "$SUITE")" && pwd)/card-testing-http.sh
CARD_SAMPLE=$(cd "$(dirname "$SUITE")" && pwd)/card-testing-sample.json
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-http.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
T_PASS=0
T_FAIL=0
t_ok() { T_PASS=$((T_PASS + 1)); echo "PASS  $*"; }
t_no() { T_FAIL=$((T_FAIL + 1)); echo "FAIL  $*"; }
t_eq() { if [ "$2" == "$3" ]; then t_ok "$1"; else t_no "$1 (expected $2, got $3)"; fi; }

# shellcheck source=tests/matrix/http-checks.sh
. "$CHECKS"
RPC_BODY="$WORK/body.json"
PAY=blaze-mcp-toolkit/payment-health
reply() { # code body [id]: inject the last reply
	RPC_CODE=$1
	printf '%s' "$2" >"$RPC_BODY"
	RPC_ID=${3:-42}
}

# --- 1. classifiers ------------------------------------------------------------------------------
reply 000 ''
t_eq 'init: HTTP 000 (no reply) is not a refusal' 'bad:http 000' "$(init_refusal)"
reply 500 '{"code":"internal_server_error","message":"critical error"}'
t_eq 'init: HTTP 500 is not a refusal' 'bad:http 500' "$(init_refusal)"
reply 502 '<html>Bad gateway</html>'
t_eq 'init: HTTP 502 is not a refusal' 'bad:http 502' "$(init_refusal)"
reply 200 '{"jsonrpc":"2.0","id":42,"error":{"code":-32603,"message":"Internal error"}}'
t_eq 'init: a failed HTTP-200 initialise is not a refusal' 'bad:http 200' "$(init_refusal)"
reply 401 ''
t_eq 'init: HTTP 401 with an empty body (Easy MCP AI challenge) is an authentication refusal' auth "$(init_refusal)"
reply 401 'null'
t_eq 'init: HTTP 401 with a null body is an authentication refusal' auth "$(init_refusal)"
reply 401 '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}'
t_eq 'init: HTTP 401 rest_forbidden is an authentication refusal' auth "$(init_refusal)"
reply 401 '{"error":"Authentication required"}'
t_eq 'init: HTTP 401 {"error":...} is an authentication refusal' auth "$(init_refusal)"
reply 401 '<html>login</html>'
t_eq 'init: HTTP 401 with a malformed body is not a refusal' 'bad:http 401 with an unrecognised body' "$(init_refusal)"
reply 401 '{"jsonrpc":"2.0","id":42,"result":{"protocolVersion":"2025-06-18"}}'
t_eq 'init: HTTP 401 carrying a result is not a refusal' 'bad:http 401 with an unrecognised body' "$(init_refusal)"
reply 404 '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}'
t_eq 'init: HTTP 404 rest_no_route is the absent route' no_route "$(init_refusal)"
reply 404 '<html>Not found</html>'
t_eq 'init: an HTTP 404 page that is not rest_no_route is not the absent route' 'bad:http 404 without rest_no_route' "$(init_refusal)"
reply 401 ''
t_eq 'init: an authentication refusal is not the absent route' auth "$(init_refusal)"

err_result() { printf '{"jsonrpc":"2.0","id":42,"result":{"isError":true,"content":[{"type":"text","text":"%s"}]}}' "$1"; }
reply 200 "$(err_result 'Permission denied')"
t_eq 'call rec: "Permission denied" is the permission refusal' perm "$(call_refusal rec "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"result":{"structuredContent":{"success":false,"error":"Permission denied"}}}'
t_eq 'call rec: execute-ability success=false "Permission denied" is the permission refusal' perm "$(call_refusal rec "$PAY")"
reply 200 "$(err_result "Ability 'blaze-mcp-toolkit/payment-health' not found on this WordPress site, and no ability you can call has a similar name.")"
t_eq 'call rec: ability not found is the tool-off answer' tool_off "$(call_refusal rec "$PAY")"
reply 200 "$(err_result "Ability 'blaze-mcp-toolkit/health-summary' not found on this WordPress site.")"
t_eq 'call rec: another ability not found is not this tool-off answer' "bad:is_error Ability 'blaze-mcp-toolkit/health-summary' not found on this WordPress site." "$(call_refusal rec "$PAY")"
reply 200 "$(err_result 'Error: Permission denied for ability: blaze-mcp-toolkit/payment-health')"
t_eq 'call easy: "Error: Permission denied for ability: <slug>" is the permission refusal' perm "$(call_refusal easy "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"result":{"isError":true,"content":[{"type":"text","text":"Error: Permission denied for ability: blaze-mcp-toolkit/payment-health"},{"type":"text","text":"Ask an administrator."}]}}'
t_eq 'call easy: the permission refusal with an appended details part is the permission refusal' perm "$(call_refusal easy "$PAY")"
reply 200 "$(err_result 'Error: Permission denied for ability: blaze-mcp-toolkit/payment-health-extra')"
t_eq 'call easy: a permission refusal for another ability is not this refusal' 'bad:is_error Error: Permission denied for ability: blaze-mcp-toolkit/payment-health-extra' "$(call_refusal easy "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"error":{"code":-32007,"message":"Insufficient WordPress permissions for this tool"}}'
t_eq 'call easy: -32007 insufficient permissions is the permission refusal' perm "$(call_refusal easy "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"error":{"code":-32007,"message":"This tool is switched off in this site'"'"'s configuration (wp-config.php)."}}'
t_eq 'call easy: -32007 "This tool ..." is the tool-off answer' tool_off "$(call_refusal easy "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"error":{"code":-32602,"message":"Unknown tool"}}'
t_eq 'call easy: -32602 Unknown tool is the tool-off answer' tool_off "$(call_refusal easy "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"error":{"code":-32603,"message":"Internal error"}}'
t_eq 'call easy: an internal JSON-RPC error is not a refusal' 'bad:rpc_error -32603 Internal error' "$(call_refusal easy "$PAY")"
reply 200 "$(err_result 'Error: Tool execution failed.')"
t_eq 'call easy: a failed tool execution is not a refusal' 'bad:is_error Error: Tool execution failed.' "$(call_refusal easy "$PAY")"
reply 000 ''
t_eq 'call: HTTP 000 is not a refusal' 'bad:http 000' "$(call_refusal rec "$PAY")"
reply 500 '{"code":"internal_server_error"}'
t_eq 'call: HTTP 500 is not a refusal' 'bad:http 500' "$(call_refusal easy "$PAY")"
reply 200 'PHP Fatal error: ...'
t_eq 'call: a malformed HTTP-200 reply is not a refusal' 'bad:malformed reply' "$(call_refusal rec "$PAY")"
reply 200 "$(err_result 'Permission denied')" 43
t_eq 'call: a reply to another request is not a refusal' 'bad:not the JSON-RPC answer to request 43' "$(call_refusal rec "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"result":{"structuredContent":{"success":true,"data":{"overall":"red"}}}}'
t_eq 'call rec: a successful result is a result' result "$(call_refusal rec "$PAY")"
reply 200 '{"jsonrpc":"2.0","id":42,"result":{"content":[{"type":"text","text":"{\"overall\":\"red\"}"}]}}'
t_eq 'call easy: a successful result is a result' result "$(call_refusal easy "$PAY")"

# The method-fact checker (REVIEW-pass4b R4): a match is a leak, a clean read is clean, and anything
# that stops the check from reading the body is an error, never clean.
printf '%s' '{"code":"rest_no_route","message":"inventory_only blzmcp_phone"}' >"$WORK/leak.json"
printf '%s' '{"code":"rest_no_route","message":"No route was found matching the URL and request method."}' >"$WORK/clean.json"
: >"$WORK/empty.json"
mkdir -p "$WORK/a-directory"
t_eq 'checker: a body carrying method facts is a leak' leak "$(blzmcp_leak_state "$WORK/leak.json")"
t_eq 'checker: a body without method facts is clean' clean "$(blzmcp_leak_state "$WORK/clean.json")"
t_eq 'checker: an empty body that was read is clean' clean "$(blzmcp_leak_state "$WORK/empty.json")"
t_eq 'checker: a missing body is a checker error' error "$(blzmcp_leak_state "$WORK/missing.json")"
t_eq 'checker: a directory instead of a body is a checker error' error "$(blzmcp_leak_state "$WORK/a-directory")"
t_eq 'checker: no path at all is a checker error' error "$(blzmcp_leak_state '')"
t_eq 'checker: a grep failure is a checker error' error "$(grep() { return 2; }; blzmcp_leak_state "$WORK/clean.json")"
t_eq 'checker: blzmcp_leaks returns 2 on a checker error' 2 "$(blzmcp_leaks "$WORK/missing.json"; echo $?)"
nf_run() { # file -> "pass fail" from expect_no_facts with counting ok/no
	(pass=0 fail=0; ok() { pass=$((pass + 1)); }; no() { fail=$((fail + 1)); }; expect_no_facts probe "$1"; echo "$pass $fail")
}
t_eq 'expect_no_facts: a clean body passes' '1 0' "$(nf_run "$WORK/clean.json")"
t_eq 'expect_no_facts: a leaking body fails' '0 1' "$(nf_run "$WORK/leak.json")"
t_eq 'expect_no_facts: a checker error fails' '0 1' "$(nf_run "$WORK/missing.json")"

# --- 2. full path through the suite's own functions ---------------------------------------------
extract() { # function name -> its definition from http-suite.sh
	awk -v n="$1" '!p && $0 ~ "^" n "\\(\\) \\{" {p=1; print; if ($0 ~ /\}[[:space:]]*$/) exit; next} p {print} p && /^}/ {exit}' "$SUITE"
}
for f in row_begin row_end deny_case diag_checks; do
	[ -n "$(extract "$f")" ] && t_ok "http-suite.sh defines $f" || t_no "http-suite.sh defines $f"
done

# One scenario in a child bash: the original functions, a stubbed I/O boundary, one row printed.
# INIT_CODE/INIT_BODY: the initialise reply (INIT_CODE=ok: initialise succeeds); CALL_CODE/CALL_BODY:
# the payment health reply; CRED: the credential map entry for the principal.
scenario() { # label expected-row route principal
	local label=$1 want=$2 out row
	out=$(env ROUTE="$3" PRINCIPAL="$4" "$BASH" -c '
		set -u
		STATE=probe; pass=0; fail=0; ROW_F0=0
		ok() { pass=$((pass + 1)); }
		no() { fail=$((fail + 1)); echo "  no: $*" >&2; }
		RPC_BODY='"'$WORK'"'/s-body.json; CALL_OUT='"'$WORK'"'/s-call.json; RPC_ID=7; PAY='"'$PAY'"'
		. '"'$CHECKS'"'
		'"$(extract row_begin)"'
		'"$(extract row_end)"'
		'"$(extract deny_case)"'
		declare -A DENY_CRED S_KIND
		[ "$CRED" != unset ] && DENY_CRED[${PRINCIPAL}_$ROUTE]=$CRED
		open_session() { # the I/O boundary: the injected initialise reply
			if [ "$INIT_CODE" == ok ]; then S_KIND[$1]=$2; return 0; fi
			RPC_CODE=$INIT_CODE; printf "%s" "$INIT_BODY" >"$RPC_BODY"; return 1
		}
		pay_call() { RPC_CODE=$CALL_CODE; printf "%s" "$CALL_BODY" >"$RPC_BODY"; echo "{\"err\":\"stub\"}" >"$CALL_OUT"; }
		call_err() { echo stub; }
		row_begin
		deny_case "$ROUTE" "$PRINCIPAL"
		row_end "$ROUTE" hpos "$PRINCIPAL" denied
	' 2>"$WORK/s-err")
	row=$(grep '^TRANSPORT_ROW=' <<<"$out" | sed 's/.*|//')
	if [ "$row" == "$want" ]; then t_ok "$label -> $want"; else t_no "$label (expected $want, got ${row:-no row}; $(head -c 200 "$WORK/s-err"))"; fi
}
export CRED INIT_CODE INIT_BODY CALL_CODE CALL_BODY
run_s() { # label expected route principal cred init_code init_body call_code call_body
	CRED=$5 INIT_CODE=$6 INIT_BODY=$7 CALL_CODE=$8 CALL_BODY=$9
	scenario "$1" "$2" "$3" "$4"
}
# The lead's 3 false passes (pass4a probe), now failures.
run_s 'deny: injected failed initialise HTTP 000' FAILED rec anonymous anon 000 '' '' ''
run_s 'deny: injected failed initialise HTTP 500' FAILED rec anonymous anon 500 '' '' ''
run_s 'deny: injected failed initialise HTTP 200' FAILED rec anonymous anon 200 '' '' ''
run_s 'deny: credential helper failure (refused)' FAILED easy customer refused ok '' '' ''
run_s 'deny: no credential made at all' FAILED easy subscriber unset ok '' '' ''
run_s 'deny: initialise 401 with a malformed body' FAILED rec anonymous anon 401 '<html>' '' ''
run_s 'deny: initialise 401 whose body leaks method facts' FAILED easy anonymous anon 401 '{"error":"blzmcp_phone taken_28d"}' '' ''
run_s 'deny: call answered HTTP 000' FAILED rec customer customer_rec ok '' 000 ''
run_s 'deny: call answered HTTP 500' FAILED easy customer customer_easy ok '' 500 '{"code":"internal_server_error"}'
run_s 'deny: call answered with a malformed body' FAILED rec subscriber subscriber_rec ok '' 200 'PHP Fatal error'
run_s 'deny: call answered an internal error' FAILED easy subscriber subscriber_easy ok '' 200 '{"jsonrpc":"2.0","id":7,"error":{"code":-32603,"message":"Internal error"}}'
run_s 'deny: call answered the tool-off text instead of a permission refusal' FAILED rec customer customer_rec ok '' 200 "$(err_result "Ability 'blaze-mcp-toolkit/payment-health' not found on this WordPress site." | sed 's/"id":42/"id":7/')"
run_s 'deny: call returned a result' FAILED rec customer customer_rec ok '' 200 '{"jsonrpc":"2.0","id":7,"result":{"structuredContent":{"success":true,"data":{"overall":"red"}}}}'
run_s 'deny: permission refusal that leaks method facts' FAILED easy customer customer_easy ok '' 200 "$(err_result 'Error: Permission denied for ability: blaze-mcp-toolkit/payment-health inventory_only' | sed 's/"id":42/"id":7/')"
# Controls: the known refusals still pass.
run_s 'deny control: anonymous refused at initialise, HTTP 401, empty body' PASSED easy anonymous anon 401 '' '' ''
run_s 'deny control: anonymous refused at initialise, HTTP 401 rest_forbidden' PASSED rec anonymous anon 401 '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}' '' ''
run_s 'deny control: customer, rec permission refusal' PASSED rec customer customer_rec ok '' 200 "$(err_result 'Permission denied' | sed 's/"id":42/"id":7/')"
run_s 'deny control: subscriber, easy permission refusal' PASSED easy subscriber subscriber_easy ok '' 200 "$(err_result 'Error: Permission denied for ability: blaze-mcp-toolkit/payment-health' | sed 's/"id":42/"id":7/')"

# --- 2b. early paths in their real order (REVIEW-pass4b R4) -------------------------------------
# The none branch and the Easy MCP AI override block run before the payment health section, so
# they see only http-checks.sh and the suite functions defined above them. Each is run as written,
# preceded by exactly that: the sourced helper, then every suite function defined above the block,
# then stubs for the WordPress/HTTP boundary. A stub only replaces a suite function that already
# exists at that point (or curl / grep, the system commands at the boundary), so nothing the real
# suite lacks there is predefined.
NONE_AT=$(grep -nE '^if \[ "\$STATE" == none \]; then$' "$SUITE" | head -1 | cut -d: -f1)
OVR_AT=$(grep -nE '^if has_transport easy; then$' "$SUITE" | while IFS=: read -r n _; do
	sed -n "$n,\$p" "$SUITE" | awk '/^fi$/ {exit} {print}' | grep -q 'EASY_MCP_AI_TOOLS override refuses' && { echo "$n"; break; }
done)
[ -n "$NONE_AT" ] && t_ok "http-suite.sh has the none branch (line $NONE_AT)" || t_no 'http-suite.sh has the none branch'
[ -n "$OVR_AT" ] && t_ok "http-suite.sh has the Easy MCP AI override block (line $OVR_AT)" || t_no 'http-suite.sh has the Easy MCP AI override block'
block_text() { sed -n "${1:-1},\$p" "$SUITE" | awk 'NR > 1 && /^fi$/ {print; exit} {print}'; }
defs_before() { # line -> names of the suite's top-level functions defined above it, in order
	grep -nE '^[a-z_]+\(\) \{' "$SUITE" | awk -F: -v l="$1" '$1 < l {sub(/\(\).*/, "", $2); print $2}'
}
STUBS='wpc setup_row make_cred open_session list_names call_tool option_json save_option'
for at in "$NONE_AT" "$OVR_AT"; do
	[ -n "$at" ] || continue
	before=" $(defs_before "$at" | tr '\n' ' ') "
	missing=''
	for s in $STUBS; do [[ "$before" == *" $s "* ]] || missing+=" $s"; done
	t_eq "every stub before line $at replaces a suite function already defined there" '' "$missing"
done
early() { # label state(none|easy-*) at-line -> the child's stdout; stderr in $WORK/e-err
	local prelude n
	prelude=". '$CHECKS'"$'\n'". '$CARD_HTTP'"$'\n' # http-suite.sh sources both before any case
	for n in $(defs_before "$3"); do prelude+="$(extract "$n")"$'\n'; done
	env STATE="$2" "$BASH" -c '
		set -u
		pass=0; fail=0; ROW_F0=0; PREFLIGHT=0; FRESH_SEED="[]"; UNDO=()
		WORK='"'$WORK'"'; RPC_BODY=$WORK/e-body.json; CALL_OUT=$WORK/e-call.json; RPC_ID=7; RPC_CODE=""
		PAY='"'$PAY'"'; REC_URL=http://probe.invalid/rec; EASY_URL=http://probe.invalid/easy
		case "$STATE" in none) TRANSPORTS=() ;; *) TRANSPORTS=(easy) ;; esac
		declare -A S_URL S_CRED S_SID S_KIND
		'"$prelude"'
		# The WordPress/HTTP boundary.
		wpc() {
			case "$*" in
			"plugin get "*) echo active ;;
			*Connectors::state*) echo none ;;
			*preg_match_all*) echo 0 ;;
			esac
		}
		setup_row() {
			if [ "$1" == connection ]; then echo "{\"status\":\"fail\",\"label\":\"Connect\",\"fix\":\"MCP Adapter with Enable Abilities, or Easy MCP AI\"}"
			else echo "{\"status\":\"warn\",\"label\":\"Tick\",\"fix\":\"tick totals\"}"; fi
		}
		make_cred() { return 0; }
		save_option() { :; }
		option_json() { echo "[]"; }
		curl() { echo 404; }
		open_session() { # the injected initialise reply
			S_KIND[$1]=$2
			[ "$INIT_CODE" == ok ] && return 0
			RPC_CODE=$INIT_CODE; printf "%s" "$INIT_BODY" >"$RPC_BODY"; return 1
		}
		list_names() { :; }
		call_tool() { # the injected tools/call reply; workflows answer a plain result
			local v="CALL_BODY_${S_KIND[$1]}"
			case "$2" in
			*weekly?store?review)
				RPC_CODE=200; echo "{\"jsonrpc\":\"2.0\",\"id\":7,\"result\":{}}" >"$RPC_BODY"
				echo "{\"ok\":{\"steps\":[{\"ability\":\"blaze-mcp-toolkit/health-summary\"}],\"instructions\":\"switched off by the site configuration\"}}" >"$CALL_OUT" ;;
			*card?testing) # the same injected answer, naming the card-testing ability where it names one
				local b=${!v}
				RPC_CODE=$CALL_CODE; printf "%s" "${b//payment-health/card-testing}" >"$RPC_BODY"; echo "{\"err\":\"stub\"}" >"$CALL_OUT" ;;
			*) RPC_CODE=$CALL_CODE; printf "%s" "${!v}" >"$RPC_BODY"; echo "{\"err\":\"stub\"}" >"$CALL_OUT" ;;
			esac
		}
		if [ "$GREP_FAIL" == 1 ]; then # a checker that cannot run: grep fails on the method-fact pattern
			grep() { case "$*" in *blzmcp_phone*) return 2 ;; esac; command grep "$@"; }
		fi
		'"$(block_text "$3")"'
	' 2>"$WORK/e-err"
}
export GREP_FAIL CALL_BODY_rec CALL_BODY_easy
REST_NO_ROUTE='{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}'
REST_NO_ROUTE_FACTS='{"code":"rest_no_route","message":"No route was found. inventory_only blzmcp_phone taken_28d","data":{"status":404}}'
REC_OFF="$(err_result "Ability 'blaze-mcp-toolkit/payment-health' not found on this WordPress site." | sed 's/"id":42/"id":7/')"
REC_OFF_FACTS="$(err_result "Ability 'blaze-mcp-toolkit/payment-health' not found on this WordPress site. inventory_only blzmcp_phone" | sed 's/"id":42/"id":7/')"
EASY_OFF='{"jsonrpc":"2.0","id":7,"error":{"code":-32007,"message":"This tool is switched off on this site."}}'
EASY_OFF_FACTS='{"jsonrpc":"2.0","id":7,"error":{"code":-32007,"message":"This tool is switched off on this site. inventory_only blzmcp_phone taken_28d"}}'
# Any stderr line is an execution error (command not found, macOS /usr/bin/leaks, unbound variable).
exec_errors() { grep -c . "$WORK/e-err"; }
none_case() { # label expected(PASSED|FAILED) grep_fail init_code init_body call_rec call_easy
	local out rows
	GREP_FAIL=$3 INIT_CODE=$4 INIT_BODY=$5 CALL_CODE=200 CALL_BODY_rec=$6 CALL_BODY_easy=$7
	export INIT_CODE INIT_BODY CALL_CODE
	out=$(early "$1" none "$NONE_AT")
	rows=$(grep '^TRANSPORT_ROW=none|' <<<"$out" | sed 's/^TRANSPORT_ROW=none|\([a-z]*\)|.*|\([A-Z]*\)$/\1:\2/' | paste -sd, -)
	t_eq "$1 (rows)" "rec:$2,easy:$2" "$rows"
	rows=$(grep '^CARD_ROW=none|' <<<"$out" | sed 's/^CARD_ROW=none|\([a-z]*\)|any|admin|card_no_tool|\([A-Z]*\)$/\1:\2/' | paste -sd, -)
	t_eq "$1 (card rows)" "rec:$2,easy:$2" "$rows"
	t_eq "$1 (no checker execution error)" 0 "$(exec_errors)"
}
[ -n "$NONE_AT" ] && {
	none_case 'none early control: absent route, clean rest_no_route' PASSED 0 404 "$REST_NO_ROUTE" '' ''
	none_case 'none early: absent route whose rest_no_route body carries method facts' FAILED 0 404 "$REST_NO_ROUTE_FACTS" '' ''
	none_case 'none early: absent route, the method-fact check cannot run' FAILED 1 404 "$REST_NO_ROUTE" '' ''
	none_case 'none early control: a reachable server answers the known tool-off refusals' PASSED 0 ok '' "$REC_OFF" "$EASY_OFF"
	none_case 'none early: a reachable server tool-off refusal carries method facts' FAILED 0 ok '' "$REC_OFF_FACTS" "$EASY_OFF_FACTS"
	none_case 'none early: reachable server, the method-fact check cannot run' FAILED 1 ok '' "$REC_OFF" "$EASY_OFF"
}
ovr_case() { # label expected(PASS|FAIL) grep_fail call_easy
	local out line
	GREP_FAIL=$3 INIT_CODE=ok INIT_BODY='' CALL_CODE=200 CALL_BODY_rec='' CALL_BODY_easy=$4
	export INIT_CODE INIT_BODY CALL_CODE
	out=$(early "$1" easy-2.5.0 "$OVR_AT")
	line=$(grep 'EASY_MCP_AI_TOOLS override refuses' <<<"$out" | head -1 | cut -c1-4)
	t_eq "$1 (override refusal check)" "$2" "${line:-none}"
	t_eq "$1 (no checker execution error)" 0 "$(exec_errors)"
}
[ -n "$OVR_AT" ] && {
	ovr_case 'Easy override control: the known switched-off answer' PASS 0 "$EASY_OFF"
	ovr_case 'Easy override: the switched-off answer carries method facts' FAIL 0 "$EASY_OFF_FACTS"
	ovr_case 'Easy override: the method-fact check cannot run' FAIL 1 "$EASY_OFF"
}

# --- 2c. card-testing payload checks (1.3 item 8) ---------------------------------------------
# card-testing-sample.json is an administrator's real card-testing result over the card fixture
# (recorded on the development environment, trimmed to the fields the checks read, the host
# removed from the edit links). Every checker passes on it; each injected defect must fail its
# check, an unparseable result must fail (never vacuously pass), and a denial body carrying card
# facts must fail the shared fact check.
. "$CARD_HTTP"
jq '{ok: .}' "$CARD_SAMPLE" >"$WORK/c-good.json"
c_count() { # checker file [args...] -> "<true> <false>"
	local out
	out=$("$@" 2>/dev/null | cut -f2)
	echo "$(grep -c '^true$' <<<"$out") $(grep -c '^false$' <<<"$out")"
}
c_good() { # label checker [args...]: every check true on the recorded result
	local r
	r=$(c_count "$2" "$WORK/c-good.json" "${@:3}")
	if [ "${r#* }" == 0 ] && [ "${r%% *}" -gt 0 ]; then t_ok "$1 ($r)"; else t_no "$1 (true/false: $r)"; fi
}
c_bad() { # label jq-mutation checker [args...]: at least one check false after the mutation
	local r
	jq "$2" "$WORK/c-good.json" >"$WORK/c-bad.json" 2>/dev/null || { t_no "$1 (mutation did not apply)"; return; }
	r=$(c_count "$3" "$WORK/c-bad.json" "${@:4}")
	if [ "${r#* }" -gt 0 ]; then t_ok "$1 ($r)"; else t_no "$1 (true/false: $r: the defect passed)"; fi
}
c_good 'card contract: the recorded result keeps the C8 contract' card_contract_checks rec
c_good 'card recent: the recorded stripe burst is likely and recent' card_recent_checks 30
c_good 'card earlier: the recorded woocommerce_payments burst is likely, earlier' card_earlier_checks 24
c_good 'card low volume: the recorded square_credit_card scope is unknown' card_low_volume_checks
c_good 'card gaps: the recorded ppcp-gateway gaps are disclosed' card_gaps_checks 25 22
c_good 'card protection: the recorded protection paths' card_protection_checks
c_good 'card logs: the recorded administrator logs are exposed' card_logs_checks exposed
# A manager's withheld result, as the recorded manager run differed: the stripe row denied, the
# role limitation added, the stripe scopes blocked from no_sign (verdict unchanged).
jq '.ok.logs |= map(if .family == "stripe" then .checked = false | .coverage = "unavailable" | .reason_code = "diagnostics_denied" | .source = null | .count = {value: null, lower_bound: false} | .categories = [] else . end) | .ok.limitations += [{scope: "logs", code: "diagnostics_denied"}]' "$WORK/c-good.json" >"$WORK/c-sm.json"
r=$(c_count card_logs_checks "$WORK/c-sm.json" withheld)
[ "${r#* }" == 0 ] && [ "${r%% *}" -gt 0 ] && t_ok "card logs: a manager's withheld result passes withheld ($r)" || t_no "card logs: a manager's withheld result passes withheld ($r)"
c_bad 'card logs: an administrator result does not count as withheld' '.' card_logs_checks withheld
c_bad 'card logs: a withheld result does not count as exposed' '.ok.logs |= map(if .family == "stripe" then .checked = false | .reason_code = "diagnostics_denied" | .count.value = null | .categories = [] else . end)' card_logs_checks exposed
c_bad 'card logs: a withheld result leaking the log count fails' '.ok.logs |= map(if .family == "stripe" then .checked = false | .coverage = "unavailable" | .reason_code = "diagnostics_denied" | .source = null else . end) | .ok.limitations += [{scope: "logs", code: "diagnostics_denied"}]' card_logs_checks withheld
c_bad 'card contract: an extra top-level key fails' '.ok.debug = 1' card_contract_checks rec
c_bad 'card contract: a missing top-level key fails' 'del(.ok.limitations)' card_contract_checks rec
c_bad 'card contract: schema_version as a string fails on the recommended route' '.ok.schema_version = "1"' card_contract_checks rec
c_bad 'card contract: schema_version 2 fails on either route' '.ok.schema_version = 2' card_contract_checks easy
c_bad 'card contract: a saved IP in the response fails' '.ok.summary += " 203.0.113.7"' card_contract_checks rec
c_bad 'card contract: a network prefix in the response fails' '.ok.findings[0].what = "2001:db8:aa::/64"' card_contract_checks rec
c_bad 'card contract: a count that is a bare number fails' '.ok.totals.failed_unpaid = 123' card_contract_checks rec
c_bad 'card contract: a missing protection path fails' '.ok.protection |= .[0:4]' card_contract_checks rec
c_bad 'card contract: a missing mandatory limitation fails' '.ok.limitations |= map(select(.code != "unsaved_and_retried_orders"))' card_contract_checks rec
c_bad 'card contract: a baseline overlapping the window fails' '.ok.baseline.end = .ok.window.end' card_contract_checks rec
c_bad 'card recent: possible instead of likely fails' '.ok.assessments[0].verdict = "possible_attack"' card_recent_checks 30
c_bad 'card recent: activity earlier instead of recent fails' '.ok.activity = "earlier_in_window"' card_recent_checks 30
c_bad 'card recent: a lower-bound count fails' '.ok.assessments[0].latest_hour.failed_orders.lower_bound = true' card_recent_checks 30
c_bad 'card recent: a count other than the seeded one fails' '.' card_recent_checks 29
c_bad 'card recent: missing IPs counted as networks fails' '.ok.assessments[0].latest_hour.networks.missing_ip_orders = 5' card_recent_checks 30
c_bad 'card recent: a confirmed-attack summary without the payment-fault caveat fails' '.ok.summary |= sub("A payment fault is still possible. "; "")' card_recent_checks 30
c_bad 'card recent: an example carrying a customer field fails' '.ok.examples[0].billing_email = "x"' card_recent_checks 30
c_bad 'card recent: no example at all fails' '.ok.examples = []' card_recent_checks 30
c_bad 'card earlier: recent instead of earlier fails' '.ok.assessments[1].activity = "recent"' card_earlier_checks 24
c_bad 'card earlier: a lower-bound episode fails' '.ok.assessments[1].episodes[0].failed_orders.lower_bound = true' card_earlier_checks 24
c_bad 'card low volume: no_sign instead of unknown fails' '.ok.assessments[4].verdict = "no_sign"' card_low_volume_checks
c_bad 'card low volume: a possible finding for the low-history method fails' '.ok.findings += [{severity: "warning", code: "possible_card_testing", method_id: "square_credit_card"}]' card_low_volume_checks
c_bad 'card gaps: a likely verdict without saved IPs fails' '.ok.assessments[2].verdict = "likely_attack"' card_gaps_checks 25 22
c_bad 'card gaps: missing IPs grouped as one network fails' '.ok.assessments[2].latest_hour.networks.signal = true' card_gaps_checks 25 22
c_bad 'card gaps: a likely missing-origin scope fails' '.ok.assessments[3].verdict = "likely_attack"' card_gaps_checks 25 22
c_bad 'card gaps: the missing-origin limitation dropped fails' '.ok.limitations |= map(select(.code != "missing_origin_orders"))' card_gaps_checks 25 22
c_bad 'card gaps: complete coverage with unknown protection fails' '.ok.coverage = "complete"' card_gaps_checks 25 22
c_bad 'card protection: a verified enforcement fails' '.ok.protection[1].enforcement = "verified"' card_protection_checks
c_bad 'card protection: a protected flag fails' '.ok.protection[3].protected = false' card_protection_checks
c_bad 'card protection: order payment inheriting the Store API setting fails' '.ok.protection[3].protection_state = "configured" | .ok.protection[3].protections = [{id: "core_checkout_rate_limit", configured: true, scope_known: true}]' card_protection_checks
c_bad 'card protection: the saved-payment delay asserted fails' '.ok.protection[4].protections[0].configured = true' card_protection_checks
c_bad 'card protection: the enforcement limitation dropped fails' '.ok.limitations |= map(select(.code != "protection_enforcement_unverified"))' card_protection_checks
c_bad 'card protection: unknown protection lowering the verdict fails' '.ok.verdict = "unknown"' card_protection_checks
c_bad 'card: an error result fails every checker (contract)' '{err: "Permission denied"}' card_contract_checks rec
echo 'not json' >"$WORK/c-nojson.json"
c_out=$(ok() { :; }; no() { echo "NO $*"; }; CALL_OUT=$WORK/c-nojson.json; card_checks probe card_contract_checks "$WORK/c-nojson.json" rec)
grep -q '^NO probe: card checks could not be evaluated' <<<"$c_out" && t_ok 'card checks: an unparseable result fails, never vacuously passes' || t_no "card checks: an unparseable result fails ($c_out)"
c_plan=$(jq -cn '{steps: [{ability: "blaze-mcp-toolkit/payment-health", tool: "blaze-mcp-toolkit/payment-health"}, {ability: "blaze-mcp-toolkit/card-testing", tool: "blaze-mcp-toolkit/card-testing", arguments: {since_hours: 48}}, {ability: "wc-analytics/totals", arguments: {subject: "orders"}}], instructions: "CARD TESTING CHECK\nThe card-testing step looks back"}')
printf '%s' "$c_plan" >"$WORK/c-plan.json"
r=$(c_count card_plan_checks "$WORK/c-plan.json" rec "$CARD" "$PAY")
[ "${r#* }" == 0 ] && [ "${r%% *}" -gt 0 ] && t_ok "card plan: payment, card, totals with the paragraph passes ($r)" || t_no "card plan: the good plan ($r)"
for m in '.steps[1].ability = "wc-analytics/totals"' '.steps[1].arguments.since_hours = 169' '.steps[1].arguments.since_hours = "48"' '.instructions += " If failed orders exceed paid orders, warn of card testing."' '.instructions = "no paragraph"' '.steps |= [.[1], .[0], .[2]]'; do
	jq "$m" "$WORK/c-plan.json" >"$WORK/c-plan-bad.json"
	r=$(c_count card_plan_checks "$WORK/c-plan-bad.json" rec "$CARD" "$PAY")
	[ "${r#* }" -gt 0 ] && t_ok "card plan: defect fails: $m" || t_no "card plan: defect passed: $m"
done
reply 200 "$(err_result 'Permission denied likely_attack failed_unpaid')"
t_eq 'card denial: a permission refusal carrying card facts is a leak' leak "$(blzmcp_leak_state "$RPC_BODY")"
reply 200 "$(err_result 'Error: Permission denied for ability: blaze-mcp-toolkit/card-testing')"
t_eq 'card denial: the clean Easy MCP AI refusal for card testing is a permission refusal without facts' 'perm clean' "$(call_refusal easy "$CARD") $(blzmcp_leak_state "$RPC_BODY")"
reply 200 "$(err_result "Ability 'blaze-mcp-toolkit/card-testing' not found on this WordPress site.")"
t_eq 'card off: the recommended not-found answer for card testing is tool_off' tool_off "$(call_refusal rec "$CARD")"
# R3 (REVIEW-pass4a, wave 3): Analytics readiness of the card fixture, checked on its own output.
ca_count() { # fixture-output -> "<true> <false>"
	local out
	out=$(card_analytics_lines "$1" | cut -f2)
	echo "$(grep -c '^true$' <<<"$out") $(grep -c '^false$' <<<"$out")"
}
CA_READY=$'SEEDED=433\nIMPORTED=433\nANALYTICS_GAP_HISTORY=none\nANALYTICS_GAP_WORKFLOW=none\nANALYTICS_READY=1'
t_eq 'card analytics: an imported, ready fixture passes every readiness check' '4 0' "$(ca_count "$CA_READY")"
t_eq 'card analytics: the recheck (no seed count) passes when ready' '3 0' "$(ca_count $'ANALYTICS_GAP_HISTORY=none\nANALYTICS_GAP_WORKFLOW=none\nANALYTICS_READY=1')"
for m in 'IMPORTED=0' 'IMPORTED=432' 'ANALYTICS_GAP_HISTORY=orders_not_imported' 'ANALYTICS_GAP_HISTORY=import_not_run' 'ANALYTICS_GAP_WORKFLOW=import_in_progress' 'ANALYTICS_READY=0'; do
	k=${m%%=*}
	r=$(ca_count "$(sed "s/^$k=.*/$m/" <<<"$CA_READY")")
	[ "${r#* }" -gt 0 ] && t_ok "card analytics: a fixture with $m fails ($r)" || t_no "card analytics: a fixture with $m passed ($r)"
done
r=$(ca_count "$(grep -v '^ANALYTICS_READY=' <<<"$CA_READY")")
[ "${r#* }" -gt 0 ] && t_ok "card analytics: no readiness line fails ($r)" || t_no "card analytics: no readiness line passed ($r)"
r=$(ca_count '')
[ "${r#* }" -gt 0 ] && t_ok "card analytics: no fixture output fails, never vacuously passes ($r)" || t_no "card analytics: no fixture output passed ($r)"
t_eq 'card analytics: card_seed checks readiness on the seed output' 1 "$(sed -n '/^card_seed() {/,/^}/p' "$CARD_HTTP" | grep -cE '^[[:space:]]*card_analytics_checks "\$1" "\$out"$')"
t_eq 'card analytics: the workflow rows recheck readiness fresh before expecting the order-totals step' 1 "$(sed -n '/^card_any_cases() {/,/^}/p' "$CARD_HTTP" | awk '/row_begin/ {r = 1} r && /card_analytics_checks .*analytics-ready/ {a = 1} a && /card_plan_checks/ {print "ok"; exit}' | grep -c ok)"
t_eq 'card analytics: the cleanup must leave no pending import job and no Analytics row' 2 "$(sed -n '/^card_unseed() {/,/^}/p' "$CARD_HTTP" | grep -oE '(IMPORT_JOBS|STATS)_LEFT=//p' | wc -l | tr -d ' ')"
t_eq 'card analytics: the fixture imports every order synchronously and probes with the gap cache cleared' '1 3' "$(grep -c 'OrdersScheduler::import( \$order->get_id() );' "$(dirname "$CARD_HTTP")/card-testing-fixture.php") $(sed -n '/^function blzmcp_cardfx_analytics()/,/^}/p' "$(dirname "$CARD_HTTP")/card-testing-fixture.php" | grep -c 'AnalyticsGuard::clear_cache();')"
reply 200 "$(err_result "Ability 'blaze-mcp-toolkit/payment-health' not found on this WordPress site.")"
c_cls=$(call_refusal rec "$CARD")
[ "${c_cls#bad:}" != "$c_cls" ] && t_ok "card off: another ability's not-found answer is not card testing's ($c_cls)" || t_no "card off: another ability's not-found answer counted for card testing ($c_cls)"

# --- 3. diagnostics withheld / exposed ---------------------------------------------------------
NOTE='not allowed to see store diagnostics'
DENIED='Not checked: your role is not allowed to see store diagnostics.'
withheld_check() { jq -n --arg d "$DENIED" '{status:"unknown",checked:false,applicable:true,coverage:"unavailable",reason:$d}'; }
payload() { # stripe-errors-json findings-json notes-json
	jq -n --argjson e "$1" --argjson f "$2" --argjson n "$3" '{ok: {notes: $n, findings: $f, methods: [
		{id: "cod", errors: {logs: {status:"unknown",checked:false,applicable:false,coverage:"not_applicable",count:null,groups_total:null,groups:[],groups_truncated:false,count_is_lower_bound:false}, jobs: {status:"unknown",checked:false,applicable:false,coverage:"not_applicable",failed:null,hooks:[],hooks_truncated:false,count_is_lower_bound:false}}},
		{id: "stripe", errors: $e}]}}'
}
W_LOGS=$(jq -n --argjson c "$(withheld_check)" '$c + {count:null,count_is_lower_bound:false,groups_total:null,groups_truncated:false,groups:[]}')
W_JOBS=$(jq -n --argjson c "$(withheld_check)" '$c + {failed:null,count_is_lower_bound:false,hooks_truncated:false,hooks:[]}')
E_JOBS='{"status":"amber","checked":true,"applicable":true,"coverage":"complete","reason":null,"failed":1,"count_is_lower_bound":false,"hooks_truncated":false,"hooks":[{"category":"gateway_job_failed","count":1,"scope":"plugin"}]}'
ROLE_NOTES="[\"Payment plugin logs and background jobs were not checked because your role is $NOTE. A store administrator can check them.\"]"
FIND_JOB='[{"code":"gateway_jobs_failed","method_id":"stripe","severity":"amber"}]'
diag_run() { # label expected(all_true|some_false) mode file
	local res
	res=$(env MODE="$3" FILE="$4" "$BASH" -c '
		set -u
		pass=0; fail=0; STATE=probe
		ok() { pass=$((pass + 1)); }
		no() { fail=$((fail + 1)); echo "  no: $*" >&2; }
		. '"'$CHECKS'"'
		ROLE_NOTE='"'$NOTE'"'; DIAG_DENIED_TEXT='"'$DENIED'"'; CALL_OUT=$FILE
		'"$(extract diag_checks)"'
		diag_checks probe "$MODE"
		echo "$pass $fail"
	' 2>"$WORK/d-err")
	local p f
	read -r p f <<<"$res"
	if [ "$2" == all_true ] && [ "${p:-0}" -gt 0 ] && [ "${f:-1}" -eq 0 ]; then t_ok "$1 ($p checks pass)"
	elif [ "$2" == some_false ] && [ "${f:-0}" -gt 0 ]; then t_ok "$1 ($f checks fail)"
	else t_no "$1 (expected $2, got pass=$p fail=$f; $(head -c 200 "$WORK/d-err"))"; fi
}
payload "{\"logs\":$W_LOGS,\"jobs\":$W_JOBS}" '[]' "$ROLE_NOTES" >"$WORK/p1.json"
diag_run 'diagnostics withheld: a faithful manager result passes' all_true withheld "$WORK/p1.json"
payload "{\"logs\":$W_LOGS,\"jobs\":$E_JOBS}" '[]' "$ROLE_NOTES" >"$WORK/p2.json"
diag_run 'diagnostics withheld: the role note with leaked job diagnostics fails' some_false withheld "$WORK/p2.json"
payload "{\"logs\":$W_LOGS,\"jobs\":$W_JOBS}" "$FIND_JOB" "$ROLE_NOTES" >"$WORK/p3.json"
diag_run 'diagnostics withheld: the role note with a leaked gateway_jobs_failed finding fails' some_false withheld "$WORK/p3.json"
payload "{\"logs\":$(jq -c '.count = 3' <<<"$W_LOGS"),\"jobs\":$W_JOBS}" '[]' "$ROLE_NOTES" >"$WORK/p4.json"
diag_run 'diagnostics withheld: a leaked log count fails' some_false withheld "$WORK/p4.json"
payload "{\"logs\":$(jq -c '.groups = [{"category":"gateway_error","count":2}] | .groups_total = 1' <<<"$W_LOGS"),\"jobs\":$W_JOBS}" '[]' "$ROLE_NOTES" >"$WORK/p5.json"
diag_run 'diagnostics withheld: leaked log groups fail' some_false withheld "$WORK/p5.json"
payload "{\"logs\":$(jq -c '.reason = null' <<<"$W_LOGS"),\"jobs\":$W_JOBS}" '[]' "$ROLE_NOTES" >"$WORK/p6.json"
diag_run 'diagnostics withheld: a check without the role reason fails' some_false withheld "$WORK/p6.json"
jq '.ok.methods |= map(select(.id != "stripe"))' "$WORK/p1.json" >"$WORK/p7.json"
diag_run 'diagnostics withheld: no applicable check at all (vacuous) fails' some_false withheld "$WORK/p7.json"
payload "{\"logs\":$W_LOGS,\"jobs\":$W_JOBS}" '[]' '[]' >"$WORK/p8.json"
diag_run 'diagnostics withheld: a missing role note fails' some_false withheld "$WORK/p8.json"
payload "{\"logs\":$W_LOGS,\"jobs\":$E_JOBS}" "$FIND_JOB" '[]' >"$WORK/p9.json"
diag_run 'diagnostics exposed: the failed Stripe job with its finding passes' all_true exposed "$WORK/p9.json"
diag_run 'diagnostics exposed: a withheld result does not count as exposed' some_false exposed "$WORK/p1.json"
payload "{\"logs\":$W_LOGS,\"jobs\":$E_JOBS}" '[]' '[]' >"$WORK/p10.json"
diag_run 'diagnostics exposed: the job without its finding fails' some_false exposed "$WORK/p10.json"
echo 'not json' >"$WORK/p11.json"
diag_run 'diagnostics: an unparseable result fails (never vacuously passes)' some_false withheld "$WORK/p11.json"

# --- 4. structure -------------------------------------------------------------------------------
grep -qF '. "$(dirname "${BASH_SOURCE[0]}")/http-checks.sh"' "$SUITE" && t_ok 'http-suite.sh sources http-checks.sh' || t_no 'http-suite.sh sources http-checks.sh'
t_eq 'none state: the absent route needs rest_no_route, a reachable server needs the tool-off answer' 2 "$(grep -cE 'expect_init_refusal ".*none.*" no_route|expect_call_refusal ".*in none.*" "\$r" "\$PAY" tool_off' "$SUITE")"
t_eq 'warm downgrade needs the permission refusal' 1 "$(grep -cE 'expect_call_refusal ".*downgrade.*" "\$\{S_KIND\[\$sm\]\}" "\$PAY" perm' "$SUITE")"
t_eq 'tool off needs the tool-off answer on both routes' 2 "$(grep -cE 'expect_call_refusal "payment off \((recommended|Easy MCP AI)\).*" (rec|easy) "\$PAY" tool_off' "$SUITE")"
t_eq 'diagnostics: manager off phases withheld, on phase and administrator exposed, warm-up exposed' 4 "$(grep -cE 'diag_checks ".*" (withheld|exposed)$' "$SUITE")"
t_eq 'no safety case passes on a bare unsuccessful call (call_ok || / call_ok && no)' 0 "$(grep -cE 'if call_ok \|\| leaks|call_ok && no ' "$SUITE")"
t_eq 'no denial passes when the credential helper refused' 0 "$(grep -cE 'could not obtain a credential' "$SUITE")"
# 1.3 card testing (card-testing-http.sh): sourced by the suite and run, every safety case through
# the fail-closed helpers, the row helper the only source of CARD_ROW lines.
grep -qF '. "$(dirname "${BASH_SOURCE[0]}")/card-testing-http.sh"' "$SUITE" && t_ok 'http-suite.sh sources card-testing-http.sh' || t_no 'http-suite.sh sources card-testing-http.sh'
t_eq 'http-suite.sh runs the card storage and authority-independent cases once each, after the payment cases' '2' "$(awk '/^pay_unseed "\$pf_orig_set"$/ {p=1} p && /^card_(storage|any)_cases$/ {n++} END {print n + 0}' "$SUITE")"
t_eq 'the none branch runs the card no-tool cases' 1 "$(block_text "$NONE_AT" | grep -cE '^[[:space:]]*card_none_cases$')"
t_eq 'card denial needs the permission refusal; the denied and the downgraded user get no facts' '1 2' "$(grep -cE 'expect_call_refusal ".*denied card testing with the known permission refusal" "\$1" "\$CARD" perm' "$CARD_HTTP") $(grep -cE 'expect_no_facts ".*receives no method or card facts"' "$CARD_HTTP")"
t_eq 'card warm downgrade needs the permission refusal' 1 "$(grep -cE 'expect_call_refusal ".*downgrade.*card result.*" "\$\{S_KIND\[\$sm\]\}" "\$CARD" perm' "$CARD_HTTP")"
t_eq 'card off needs the tool-off answer on both routes' 2 "$(grep -cE 'expect_call_refusal "card off \((recommended|Easy MCP AI)\).*" (rec|easy) "\$CARD" tool_off' "$CARD_HTTP")"
t_eq 'card none needs rest_no_route or the tool-off answer' 2 "$(grep -cE 'expect_init_refusal ".*card testing in none.*" no_route|expect_call_refusal ".*card testing in none.*" "\$r" "\$CARD" tool_off' "$CARD_HTTP")"
t_eq 'card logs: administrator, manager on phase and warm-up exposed; manager off phases and roles withheld' '3 2' "$(grep -cE 'card_logs_checks "\$CALL_OUT" exposed$' "$CARD_HTTP") $(grep -cE 'card_logs_checks "\$CALL_OUT" withheld$' "$CARD_HTTP")"
t_eq 'every card row is printed by card_row_end' 1 "$(grep -c 'echo "CARD_ROW=' "$CARD_HTTP")"
t_eq 'card-testing-http.sh defines no method-fact checker of its own and never negates one' '0 0' "$(grep -cE '^[[:space:]]*(blzmcp_)?leaks\(\)' "$CARD_HTTP") $(grep -hE '![[:space:]]*(blzmcp_)?leaks[[:space:]]' "$CARD_HTTP" | grep -cv '^[[:space:]]*#')"
t_eq 'card-testing-http.sh changes no credential itself (only the suite and its helpers do)' 0 "$(grep -cE 'app-password|easy-token|credentials\.php [a-z-]+ (create|revoke)' "$CARD_HTTP")"
t_eq 'the storage loop prints its 9 card row kinds, each under the combination it switched to' 9 "$(sed -n '/^card_storage_cases() {/,/^}/p' "$CARD_HTTP" | grep -cE '^[[:space:]]*card_row_end "\$s" "\$combo" ')"
t_eq 'the authority-independent cases print their 5 card row kinds' 5 "$(sed -n '/^card_any_cases() {/,/^}/p' "$CARD_HTTP" | grep -oE 'card_row_end [^ ]+ any (admin|manager) card_[a-z_]+' | awk '{print $4 "/" $5}' | sort -u | wc -l | tr -d ' ')"
# REVIEW-pass4b R4: one method-fact checker, in the sourced helper, never negated, never shadowed.
t_eq 'http-checks.sh defines blzmcp_leaks' 1 "$(grep -cE '^blzmcp_leaks\(\) \{' "$CHECKS")"
t_eq 'http-suite.sh defines no method-fact checker of its own' 0 "$(grep -cE '^[[:space:]]*(blzmcp_)?leaks\(\)' "$SUITE")"
t_eq 'nothing calls a bare leaks command' 0 "$(grep -hE '(^|[^_[:alnum:]])leaks "' "$SUITE" "$CHECKS" | grep -cv '^[[:space:]]*#')"
t_eq 'no caller negates the method-fact check' 0 "$(grep -hE '![[:space:]]*(blzmcp_)?leaks[[:space:]]' "$SUITE" "$CHECKS" | grep -cv '^[[:space:]]*#')"

echo "$T_PASS passed, $T_FAIL failed"
[ "$T_FAIL" -eq 0 ]
