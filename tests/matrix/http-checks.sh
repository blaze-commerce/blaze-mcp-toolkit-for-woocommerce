# shellcheck shell=bash
# Reply classifiers and payload checks for tests/matrix/http-suite.sh (1.2.1 item 5, REVIEW-pass4a
# R4). Sourced, never run. Pure functions over the last reply ($RPC_CODE, $RPC_BODY, $RPC_ID) or a
# call result file, so tests/matrix/test-http-checks.sh can feed them recorded and injected replies
# without WordPress.
#
# The safety cases fail closed: a refusal counts only when it is the route's KNOWN answer for the
# case. HTTP 000 (no reply), 5xx, a reply that is not the JSON-RPC answer to this request, an
# unexpected error text and a successful result are all failures, never a "denial".
#
# Known answers (read from the pinned connector sources, and seen in the wave 2 matrix logs):
#   authentication refusal at initialize  HTTP 401 or 403 with an empty or null body, or a JSON error
#                                          object (WordPress REST {code,message}, Easy MCP AI
#                                          {"error":...} with a WWW-Authenticate challenge); never a result
#   no MCP route (none state)             HTTP 404 with WordPress REST code rest_no_route
#   permission refusal of a tool call     rec:  "Permission denied" (mcp-adapter ToolsHandler, the
#                                               ability's permission_callback returned false)
#                                         easy: "Error: Permission denied for ability: <slug>" (Easy MCP
#                                               AI Dynamic_Tool_Registrar), or JSON-RPC -32007
#                                               "Insufficient WordPress permissions for this tool"
#   tool switched off                     rec:  "Ability '<slug>' not found ..." (Enable Abilities
#                                               unregisters a denylisted ability; mcp-adapter
#                                               execute-ability answers not found)
#                                         easy: JSON-RPC -32007 "This tool ..." or -32602 "Unknown tool"

# init_refusal: classifies a refused initialize. Prints auth, no_route or bad:<why>.
init_refusal() {
	case "$RPC_CODE" in
	401 | 403)
		if [ ! -s "$RPC_BODY" ]; then
			echo auth
		elif jq -e 'type == "null" or (type == "object" and (has("result") | not) and ((.code | type) == "string" or has("error")))' "$RPC_BODY" >/dev/null 2>&1; then
			echo auth
		else
			echo "bad:http $RPC_CODE with an unrecognised body"
		fi
		;;
	404)
		if jq -e 'type == "object" and .code == "rest_no_route"' "$RPC_BODY" >/dev/null 2>&1; then
			echo no_route
		else
			echo "bad:http 404 without rest_no_route"
		fi
		;;
	'') echo 'bad:no request was made' ;;
	*) echo "bad:http $RPC_CODE" ;;
	esac
}

# reply_shape route: the last tools/call reply as "<kind><US><code><US><text>" (US = the 0x1f unit
# separator, so an empty code survives `read`), kind one of
# result (a successful answer), rpc_error, is_error, rec_false (execute-ability success=false),
# bad (not the JSON-RPC answer to request $RPC_ID, or not HTTP 200).
reply_shape() {
	if [ "$RPC_CODE" != 200 ]; then
		printf 'bad\037\037http %s\n' "${RPC_CODE:-none}"
		return
	fi
	jq -r --arg route "$1" --arg id "${RPC_ID:-}" '
		if (type == "object" and .jsonrpc == "2.0" and ((.id | tostring) == $id)) | not then "bad\u001f\u001fnot the JSON-RPC answer to request \($id)"
		elif has("error") then "rpc_error\u001f\(.error.code // "")\u001f\(.error.message // "")"
		elif (.result | type) != "object" then "bad\u001f\u001fno result object"
		elif .result.isError == true then "is_error\u001f\u001f\((.result.content // []) | map(.text // "") | join(" "))"
		else (.result.structuredContent // ((.result.content // [])[0].text // "null" | fromjson? // null)) as $sc
			| if $route == "rec" and ($sc | type) == "object" and $sc.success == false then "rec_false\u001f\u001f\($sc.error // "" | tostring)"
			else "result\u001f\u001f" end
		end' "$RPC_BODY" 2>/dev/null | head -1 | grep . || printf 'bad\037\037malformed reply\n'
}

# call_refusal route slug: classifies the last tools/call reply. Prints result, perm, tool_off or
# bad:<why> (an unknown refusal text is bad too: it is not the known answer for any case).
call_refusal() {
	local route=$1 slug=$2 kind code text pat
	IFS=$'\037' read -r kind code text <<<"$(reply_shape "$route")"
	pat="^Ability '$slug' not found"
	case "$kind" in
	result) echo result; return ;;
	bad) echo "bad:$text"; return ;;
	esac
	if [ "$route" == rec ]; then
		if [[ "$text" =~ ^Permission\ denied ]]; then
			echo perm
		elif [[ "$text" =~ $pat ]]; then
			echo tool_off
		else
			echo "bad:$kind ${code:+$code }$(head -c 160 <<<"$text")"
		fi
	else
		# Easy MCP AI may append a details part after the message (joined with a space).
		if [ "$kind" == is_error ] && { [ "$text" == "Error: Permission denied for ability: $slug" ] || [ "${text#"Error: Permission denied for ability: $slug "}" != "$text" ]; }; then
			echo perm
		elif [ "$kind" == rpc_error ] && [ "$code" == -32007 ] && [ "$text" == 'Insufficient WordPress permissions for this tool' ]; then
			echo perm
		elif [ "$kind" == rpc_error ] && { { [ "$code" == -32007 ] && [ "${text#This tool }" != "$text" ]; } || { [ "$code" == -32602 ] && [ "$text" == 'Unknown tool' ]; }; }; then
			echo tool_off
		else
			echo "bad:$kind ${code:+$code }$(head -c 160 <<<"$text")"
		fi
	fi
}

# Method facts of the payment fixture, and (1.3) the card-testing result's verdicts, counts,
# protection and log facts, that a denied caller must never receive, in a body or an error.
BLZMCP_METHOD_FACTS='blzmcp_phone|blzmcp_gone|taken_28d|outside_checkout_observed|inventory_only|gateway_jobs_failed|gateway_job_failed|likely_attack|possible_attack|failed_unpaid|baseline_attempt_orders|store_api_checkout|core_checkout_rate_limit|gateway_error|recorded networks'

# blzmcp_leaks file: the method-fact check (REVIEW-pass4b R4). Defined here, in the helper the suite
# sources before any case runs, so the none branch and the Easy override reach it too; the
# blzmcp_ prefix keeps it clear of system commands (macOS ships an unrelated /usr/bin/leaks).
# Returns 0 when the file carries a method fact, 1 when it was read and carries none, and 2 when it
# could not be checked (no path, a missing or unreadable file, a grep error). Never negate it:
# callers go through blzmcp_leak_state, where 2 is "error", never "clean".
blzmcp_leaks() {
	[ -n "${1:-}" ] && [ -f "$1" ] && [ -r "$1" ] || return 2
	grep -qE -e "$BLZMCP_METHOD_FACTS" -- "$1"
	case $? in
	0) return 0 ;;
	1) return 1 ;;
	*) return 2 ;;
	esac
}

# blzmcp_leak_state file: prints leak, clean or error.
blzmcp_leak_state() {
	blzmcp_leaks "$1"
	case $? in
	0) echo leak ;;
	1) echo clean ;;
	*) echo error ;;
	esac
}

# leak_why state: the failure note for a leak state other than clean.
leak_why() {
	case "$1" in
	clean) ;;
	leak) echo ', method facts in the body' ;;
	*) echo ', the method-fact check could not read the body' ;;
	esac
}

# expect_no_facts label [file]: one check that the reply (default $RPC_BODY) was read and carries no
# method facts. A checker error fails the check.
expect_no_facts() {
	local st
	st=$(blzmcp_leak_state "${2:-$RPC_BODY}")
	if [ "$st" == clean ]; then
		ok "$1"
	else
		no "$1 (got $st$(leak_why "$st"))"
	fi
}

# expect_init_refusal label want(auth|no_route): one check on a refused initialize, passed only for
# the known answer and a body that was read and carries no method facts. Uses the suite's ok and no.
expect_init_refusal() {
	local cls st
	cls=$(init_refusal)
	st=$(blzmcp_leak_state "$RPC_BODY")
	if [ "$cls" == "$2" ] && [ "$st" == clean ]; then
		ok "$1 (http $RPC_CODE)"
	else
		no "$1 (expected $2 without method facts, got $cls$(leak_why "$st"))"
	fi
}

# expect_call_refusal label route slug want(perm|tool_off): one check on the last tools/call reply.
expect_call_refusal() {
	local cls st
	cls=$(call_refusal "$2" "$3")
	st=$(blzmcp_leak_state "$RPC_BODY")
	if [ "$cls" == "$4" ] && [ "$st" == clean ]; then
		ok "$1 ($(call_err | head -c 80))"
	else
		no "$1 (expected $4 without method facts, got $cls$(leak_why "$st"))"
	fi
}

# Diagnostic finding codes (PaymentHealth::FINDING_CODES drawn from logs and jobs).
DIAG_FINDINGS='["gateway_errors_logged","webhook_signature_failed","gateway_jobs_failed"]'

# diag_withheld_checks file role_note denied_text: a payment health result for a caller without
# diagnostics. Prints label<TAB>true|false lines. Every applicable log and job check is not checked,
# unavailable, carries the fixed role reason and no quantity, group or hook; no diagnostic finding;
# and at least one applicable check exists (the fixture's stripe method), so the case is not vacuous.
diag_withheld_checks() {
	jq -r --arg note "$2" --arg denied "$3" --argjson codes "$DIAG_FINDINGS" '
		.ok as $r | ($r.methods // []) as $m
		| [$m[] | . as $x | (["logs", "jobs"][] as $k | $x.errors[$k] | select(.applicable == true) | {id: $x.id, k: $k, c: .})] as $app
		| [
			["the role note is present", (($r.notes // []) | map(contains($note)) | any)],
			["an applicable diagnostic check exists (stripe logs or jobs)", ([$app[] | select(.id == "stripe")] | length) > 0],
			["every applicable log/job check is not checked and unavailable", ($app | length) > 0 and ($app | all(.c.checked == false and .c.coverage == "unavailable" and .c.status == "unknown"))],
			["every applicable log/job check carries the role reason", ($app | length) > 0 and ($app | all(.c.reason == $denied))],
			["restricted quantities are null (log count, groups_total, failed jobs)", ($app | length) > 0 and ($app | all(if .k == "logs" then (.c.count == null and .c.groups_total == null) else .c.failed == null end))],
			["groups and hooks are empty, nothing truncated or lower-bound", ($app | length) > 0 and ($app | all(if .k == "logs" then (.c.groups == [] and .c.groups_truncated == false) else (.c.hooks == [] and .c.hooks_truncated == false) end and .c.count_is_lower_bound == false))],
			["no diagnostic finding (logs or jobs)", ([($r.findings // [])[] | .code] | map(. as $c | $codes | index($c)) | all(. == null))]
		] | .[] | "\(.[0])\t\(.[1])"' "$1" 2>/dev/null
}

# diag_exposed_checks file role_note: a payment health result for a caller with diagnostics. The
# fixture's failed Stripe payment job is reported: the on phase really exposes an applicable check.
diag_exposed_checks() {
	jq -r --arg note "$2" '
		.ok as $r | ([($r.methods // [])[] | select(.id == "stripe")][0] // {}) as $s
		| [
			["no role note", (($r.notes // []) | map(contains($note)) | any | not)],
			["stripe jobs check is applicable and checked", ($s.errors.jobs | .applicable == true and .checked == true and (.coverage == "complete" or .coverage == "partial"))],
			["stripe jobs check reports the failed payment job", (($s.errors.jobs.failed // 0) >= 1 and ([$s.errors.jobs.hooks[]? | .category] | index("gateway_job_failed") != null))],
			["the gateway_jobs_failed finding for stripe is present", ([($r.findings // [])[] | "\(.code) \(.method_id)"] | index("gateway_jobs_failed stripe") != null)]
		] | .[] | "\(.[0])\t\(.[1])"' "$1" 2>/dev/null
}
