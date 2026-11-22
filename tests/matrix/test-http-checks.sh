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
#    the known refusals must produce a PASSED row.
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
SUITE=${BLZMCP_TEST_HTTP_SUITE:-$REPO/tests/matrix/http-suite.sh} # override: run the full-path cases against another copy
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-http.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
T_PASS=0
T_FAIL=0
t_ok() { T_PASS=$((T_PASS + 1)); echo "PASS  $*"; }
t_no() { T_FAIL=$((T_FAIL + 1)); echo "FAIL  $*"; }
t_eq() { if [ "$2" == "$3" ]; then t_ok "$1"; else t_no "$1 (expected $2, got $3)"; fi; }

# shellcheck source=tests/matrix/http-checks.sh
. "$REPO/tests/matrix/http-checks.sh"
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

# --- 2. full path through the suite's own functions ---------------------------------------------
extract() { # function name -> its definition from http-suite.sh
	awk -v n="$1" '!p && $0 ~ "^" n "\\(\\) \\{" {p=1; print; if ($0 ~ /\}[[:space:]]*$/) exit; next} p {print} p && /^}/ {exit}' "$SUITE"
}
for f in row_begin row_end deny_case diag_checks; do
	[ -n "$(extract "$f")" ] && t_ok "http-suite.sh defines $f" || t_no "http-suite.sh defines $f"
done
leaks_def=$(grep -m1 '^leaks() ' "$SUITE")
[ -n "$leaks_def" ] && t_ok 'http-suite.sh defines leaks' || t_no 'http-suite.sh defines leaks'

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
		. '"'$REPO/tests/matrix/http-checks.sh'"'
		'"$leaks_def"'
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
		. '"'$REPO/tests/matrix/http-checks.sh'"'
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

echo "$T_PASS passed, $T_FAIL failed"
[ "$T_FAIL" -eq 0 ]
