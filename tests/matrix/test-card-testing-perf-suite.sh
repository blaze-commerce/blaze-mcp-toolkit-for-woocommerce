#!/usr/bin/env bash
# Self-test for card-testing-perf-suite.sh's gate (1.3 FEATURE-PLAN item 9, C10), against the
# stand-in tests/matrix/fake-wp-env.sh plus stand-in docker and curl on PATH: no Docker, no
# WordPress, no network, writes only to a temporary directory. A clean run passes; a failed seed, a
# failed or slow trial, an unverified fixture, an undetected forced failure, a failed bound case, a
# cleanup that leaves rows, a storage restore failure, a validator refusal and an interrupted run
# each end CARD PERF FAILED, and each case asserts its own failure line, so removing that check
# fails this test. Workflow cost (1.3 wave 3, R1): Analytics is made ready after the trials and
# before the workflow; a store still not ready, a short import, a plan without the order totals, a
# failed or reordered step the measurement calls ok, and Analytics rows left by the cleanup each
# fail with their own line from the suite and from the validator.
#
#   /bin/bash tests/matrix/test-card-testing-perf-suite.sh
#
# The suite and the stand-in run under the same bash as this script ($BASH), so
# `/bin/bash tests/matrix/test-card-testing-perf-suite.sh` on macOS checks them under bash 3.2.
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-card-perf.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/bin" && ln -s "$BASH" "$WORK/bin/bash"
# Stand-in docker (container limits) and curl: the MCP Adapter route's JSON-RPC answer carrying a
# valid card-testing result for the requested since_hours (an integer in the request body), HTTP
# 200, 250 ms; a session header on every reply; the credentials config arrives on stdin and is
# discarded. BLZMCP_STANDIN_HTTP=string-hours answers like the abilities REST route did for a
# string look-back (ability_invalid_input, HTTP 400).
# shellcheck disable=SC2016 # The stand-ins' lines are written literally; they expand when they run.
printf '%s\n' '#!/bin/sh' '[ "$1" = info ] || exit 1' 'echo "{\"ncpu\":8,\"mem_total_bytes\":8589934592,\"server_version\":\"stand-in\"}"' >"$WORK/bin/docker"
# shellcheck disable=SC2016
printf '%s\n' '#!/bin/sh' 'out=' 'hdr=' 'data=' 'while [ $# -gt 0 ]; do case "$1" in -o) out=$2; shift ;; -D) hdr=$2; shift ;; --data-binary) data=$2; shift ;; esac; shift; done' 'cat >/dev/null' \
	'[ -n "$hdr" ] && printf "Mcp-Session-Id: standin-session\r\n" >"$hdr"' \
	'if [ "${BLZMCP_STANDIN_HTTP:-}" = string-hours ]; then [ -n "$out" ] && printf "%s" "{\"code\":\"ability_invalid_input\",\"message\":\"since_hours must be a whole number\",\"data\":{\"status\":400}}" >"$out"; printf "400 0.250"; exit 0; fi' \
	'h=$(printf "%s" "$data" | sed -n "s/.*\"since_hours\":\([0-9]*\).*/\1/p")' \
	'[ -n "$out" ] && printf "%s" "{\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"structuredContent\":{\"success\":true,\"data\":{\"schema_version\":1,\"since_hours\":${h:-0},\"verdict\":\"likely_attack\",\"totals\":{},\"protection\":[1,2,3,4,5]}}}}" >"$out"' 'printf "200 0.250"' >"$WORK/bin/curl"
chmod +x "$WORK/bin/docker" "$WORK/bin/curl"
export PATH="$WORK/bin:$PATH"
printf 'stand-in candidate zip\n' >"$WORK/candidate.zip"
SRC=0123456789abcdef0123456789abcdef01234567
pass=0
fail=0

# run <label> <expected exit> <mode> [extra env...]
run() {
	local label=$1 want=$2 mode=$3 got
	shift 3
	rm -rf "$WORK/state" && mkdir -p "$WORK/state"
	rm -f "$WORK/card.json"
	env FAKE_DIR="$WORK/state" FAKE_MODE="$mode" WPE="$REPO/tests/matrix/fake-wp-env.sh" BLZMCP_CARD_PERF_OUT="$WORK/card.json" \
		BLZMCP_CARD_PERF_CHUNK=25000 BLZMCP_CARD_PERF_JOBS=2 BLZMCP_PERF_CANDIDATE="$WORK/candidate.zip" BLZMCP_PERF_SOURCE_SHA="$SRC" \
		BLZMCP_MATRIX_ATTEMPT=selftest-1 BLZMCP_PERF_PORT=65535 BLZMCP_PERF_LOGIN=admin BLZMCP_PERF_APP_PASSWORD=not-a-real-secret "$@" \
		"$BASH" "$REPO/tests/matrix/card-testing-perf-suite.sh" >"$WORK/log" 2>&1
	got=$?
	if [ "$got" -eq "$want" ]; then
		echo "PASS  $label (exit $got)"
		pass=$((pass + 1))
	else
		echo "FAIL  $label (expected exit $want, got $got)"
		grep -E '^(FAIL|partition|CARD|INTERRUPTED)' "$WORK/log" | head -8 | sed 's/^/      /'
		fail=$((fail + 1))
	fi
}
# assert <label> <checks>: every check prints true or false; all of them must be true.
assert() {
	if [ -n "$2" ] && [ -z "${2//true/}" ]; then
		echo "PASS  $1"
		pass=$((pass + 1))
	else
		echo "FAIL  $1 ($2)"
		fail=$((fail + 1))
	fi
}
tf() { if "$@"; then echo true; else echo false; fi; }
line() { tf grep -qxF -- "$1" "$WORK/log"; }                        # an exact log line
has() { tf grep -qF -- "$1" "$WORK/log"; }                          # a fragment of some log line
last() { tf test "$(tail -1 "$WORK/log")" == "$1"; }                # the final line
nofail() { if grep -q '^FAIL' "$WORK/log"; then echo false; else echo true; fi; } # no FAIL line of the suite
rj() { # <jq filter> [jq args]: the receipt's answer, true only when jq prints exactly true
	local filter=$1 v
	shift
	v=$(jq -r "$@" "$filter" "$WORK/card.json" 2>/dev/null)
	tf test "$v" == true
}
after() { # <later fragment> <earlier fragment>: the first line with the first comes after the last line with the second
	local a b
	a=$(grep -nF -- "$1" "$WORK/log" | head -1 | cut -d: -f1)
	b=$(grep -nF -- "$2" "$WORK/log" | tail -1 | cut -d: -f1)
	if [ -n "$a" ] && [ -n "$b" ] && [ "$a" -gt "$b" ]; then echo true; else echo false; fi
}

run 'clean stubbed run at full size passes' 0 good
assert '  ... ends CARD PERF PASSED after the validator accepts 40 trials in 8 partitions' "$(last 'CARD PERF PASSED')$(line 'CARD PERF VALID (40 trials, 8 partitions)')$(nofail)"
assert '  ... prints one partition line per partition in the fixed format' "$(tf test "$(grep -cE '^partition (hpos|legacy)/(48|168)h/(file|db) trials_ms=120,120,120,120,120 max=120 pass=true$' "$WORK/log")" == 8)"
assert '  ... receipt schema, binding, budget and rule, full size, not smoke' "$(rj '.schema == "blzmcp-card-performance/1" and .source_sha == $s and .attempt == "selftest-1" and (.candidate_sha256 | test("^[0-9a-f]{64}$")) and .budget_ms == 1500 and .rule == "5of5<1500" and .smoke == false and .seed.orders == 50000 and .seed.old_orders == 20000' --arg s "$SRC")"
assert '  ... 40 raw trials, 8 passing partitions, every trial with its readers and metrics' "$(rj '(.trials | length) == 40 and (.partitions | length) == 8 and (.partitions | all(.pass and .rule == "5of5<1500" and .trials_ms == [120,120,120,120,120])) and (.trials | all((.validation.readers | length) == 6 and (.meta_rows | type) == "number" and (.slowest[0].plan | length) > 0))')"
assert '  ... 8 forced probes detected, 8 bound cases apart from the trials' "$(rj '(.forced_failure_checks | length) == 8 and .forced_failures_detected and (.bound_cases | length) == 8 and (.bound_checks | all(.ok)) and (.trials | all(has("case") | not))')"
assert '  ... cleanup records for every phase (6 per storage + final), 70,000 removed per storage, storage restored' "$(rj '.cleanup_ok and (.cleanup | length) == 13 and ([.cleanup[] | select(.phase == "after") | .orders_removed == .expected_removed and .expected_removed == 70000] == [true, true]) and ([.cleanup[] | select(.phase == "bound-methods") | .expected_removed] == [1001, 1001]) and ([.cleanup[] | select(.phase == "bound-networks") | .expected_removed] == [5001, 5001]) and .storage_restored == {storage:"legacy", sync:"no", ok:true}')"
assert '  ... HTTP cold and warm per storage and window, workflow cost per storage, environment identity' "$(rj '(.http | length) == 8 and (.http | all(.http_code == "200" and .valid_body and .total_ms == 250)) and (.workflow_cost | length) == 2 and .environment.container_limits.ncpu == 8 and (.environment.host.name | length) > 0 and (.environment.versions | length) == 1 and .validator.exit == 0 and .all_pass')"
assert '  ... the application password never reaches the log or the receipt' "$(if grep -q 'not-a-real-secret' "$WORK/log" "$WORK/card.json"; then echo false; else echo true; fi)"
assert '  ... Analytics ready per storage: every eligible order imported, guard reason null for last_7_days with compare, gap cache cleared' "$(rj '.workflow_period == "last_7_days" and (.analytics_ready | length) == 2 and ([.analytics_ready[].storage] == ["legacy", "hpos"]) and (.analytics_ready | all(.ok and has("reason") and .reason == null and .cache_cleared and .period == "last_7_days" and .compare and .eligible == 34720 and .imported == 34720 and .missing == 0))')$(has '  analytics (hpos): 34720 of 34720 eligible orders imported in ')"
assert '  ... the import runs after every trial and HTTP figure of its storage, and before its workflow cost' "$(after '  analytics (legacy):' '    legacy 168h db trial 5:')$(after '  analytics (legacy):' '    HTTP legacy 168h warm')$(after '  workflow cost (legacy, cold' '  analytics (legacy):')$(after '  analytics (hpos):' '    hpos 168h db trial 5:')$(after '  workflow cost (hpos, cold' '  analytics (hpos):')"
assert '  ... the workflow cost is the plan'"'"'s own 3 steps in plan order, each planned, with its arguments and successful' "$(rj '(.workflow_cost | length) == 2 and (.workflow_cost | all(.ok and .period == "last_7_days" and ([.steps[].ability] == ["blaze-mcp-toolkit/payment-health", "blaze-mcp-toolkit/card-testing", "wc-analytics/totals"]) and .steps[2].arguments.subject == "orders" and (.steps | all(.planned and .success and .error_code == null and .arguments == .plan_arguments))))')"
assert '  ... the cleanup after the trials removed the imported Analytics rows, nothing left' "$(rj '[.cleanup[] | select(.phase == "after") | .analytics_stats_removed == 34720 and .analytics_rows_remaining == 0 and .import_jobs_remaining == 0 and .analytics_customers_remaining == 0] == [true, true]')"

run 'a strictly-below-budget run (5 x 1499.9 ms) passes' 0 good FAKE_CARD_TIMES='legacy/168/file=1499.9,1499.9,1499.9,1499.9,1499.9'
assert '  ... the partition line shows the raw trials' "$(line 'partition legacy/168h/file trials_ms=1499.9,1499.9,1499.9,1499.9,1499.9 max=1499.9 pass=true')$(last 'CARD PERF PASSED')"

run 'a failed seed fails' 1 card-seed-fail
assert '  ... with its seed line, no trials for that storage, CARD PERF FAILED' "$(line 'FAIL  storage hpos: seeded 25000 of 50000 recent and 20000 of 20000 older card orders')$(has 'partition hpos/48h/file has 0 trials, not 5')$(last 'CARD PERF FAILED')"
run 'an unverified fixture fails' 1 card-verify-fail
assert '  ... with its verify line' "$(has 'FAIL  storage hpos: card fixture not verified in the authoritative store')$(last 'CARD PERF FAILED')"
run 'a failed (invalid) trial fails' 1 good FAKE_CARD_TIMES='hpos/48/db=120,120,invalid,120,120'
assert '  ... with its trial line, the partition failed, the validator refusing trial 3' "$(line 'FAIL  trial hpos/48h/db #3 invalid: orders: totals.failed_unpaid 3, expected 4')$(line 'partition hpos/48h/db trials_ms=120,120,120,120,120 max=120 pass=false')$(has 'partition hpos/48h/db trial 3 is not a valid trial (orders: totals.failed_unpaid 3, expected 4)')$(last 'CARD PERF FAILED')"
run 'a slow trial of exactly 1500 ms fails (not strictly below)' 1 good FAKE_CARD_TIMES='legacy/168/file=120,1500,120,120,120'
assert '  ... with its slow-trial line and the partition failed' "$(line 'FAIL  trial legacy/168h/file #2 took 1500 ms, not strictly below 1500 ms')$(line 'partition legacy/168h/file trials_ms=120,1500,120,120,120 max=1500 pass=false')$(has 'partition legacy/168h/file trial 2 took 1500 ms, not strictly below 1500 ms')"
run 'a slow trial in a legacy DB-logger partition fails too (no relaxed rule)' 1 good FAKE_CARD_TIMES='legacy/48/db=1177,1248,1191,1172,1605.8'
assert '  ... recorded under 5of5<1500 with 4 under budget' "$(line 'partition legacy/48h/db trials_ms=1177,1248,1191,1172,1605.8 max=1605.8 pass=false')$(rj '.partitions[] | select(.storage == "legacy" and .window == 48 and .logger == "db") | .rule == "5of5<1500" and .trials_under_budget == 4')"
run 'a trial with no elapsed_ms fails' 1 good FAKE_CARD_TIMES='hpos/168/db=120,120,120,120,null'
assert '  ... with its line' "$(line 'FAIL  trial hpos/168h/db #5 took null ms, not strictly below 1500 ms')$(has 'partition hpos/168h/db trial 5 has no elapsed_ms')"
for f in orders logs; do
	run "a forced $f-reader failure that is not detected fails" 1 "card-undetected-$f"
	assert "  ... with its forced line" "$(line "FAIL  forced $f-reader failure (legacy, file logger) was not detected: ok true, detected false")$(has "forced $f-reader failure (legacy, file logger) not shown failed and detected")"
done
run 'a bound case that fails its assertions fails' 1 card-bound-fail
assert '  ... with its bound line, the trials untouched' "$(line 'FAIL  bound networks 5001 (hpos): ["above the cap: network_cap limitation no"]')$(has 'bound case networks/5001 (hpos) missing or failed')$(rj '.partitions | all(.pass)')"
run 'a cleanup that leaves fixture orders fails' 1 cleanup-leftover
assert '  ... with its cleanup line' "$(has 'FAIL  cleanup after (hpos) incomplete:')$(has 'cleanup after (hpos) missing or unclean')$(rj '[.cleanup[] | select(.phase == "after") | .tagged_remaining] == [3, 3]')"
run 'a cleanup that removes fewer orders than were seeded fails' 1 cleanup-short
assert '  ... recorded with the expected count' "$(has 'FAIL  cleanup after (legacy) incomplete:')$(rj '[.cleanup[] | select(.phase == "after") | "\(.orders_removed)/\(.expected_removed)"] == ["69999/70000", "69999/70000"]')"
run 'a diagnostics cleanup that leaves fixture jobs fails' 1 diag-leftover
assert '  ... per logger' "$(has 'FAIL  cleanup diag-db (legacy) incomplete:')$(has 'FAIL  cleanup diag-file (hpos) incomplete:')"
run 'a cleanup with no result fails' 1 cleanup-silent
assert '  ... recorded as no cleanup result' "$(has '"error":"no cleanup result"')$(last 'CARD PERF FAILED')"
run 'a failed order storage restore fails' 1 restore-fail
assert '  ... with its restore line and the receipt says so' "$(line 'FAIL  order storage not restored to legacy sync=no')$(has 'original order storage not restored')$(rj '.storage_restored.ok == false and (.partitions | all(.pass))')"

# Workflow cost and its Analytics precondition (1.3 wave 3, REVIEW-pass4a R1), each on hpos only.
run 'a store whose Analytics is still not ready after the import fails, its workflow not measured' 1 card-analytics-not-ready
assert '  ... with its readiness line, no workflow call on hpos, the validator refusing both' "$(has 'FAIL  analytics not ready (hpos): {"reason":"orders_not_imported"')$(has 'FAIL  workflow cost (hpos): {"error":"analytics_not_ready"')$(rj '[.workflow_cost[] | select(.storage == "hpos") | .steps] == [[]] and ([.analytics_ready[] | select(.storage == "legacy") | .ok] == [true])')$(has 'analytics readiness (hpos): the guard reports orders_not_imported')$(has 'combined workflow cost (hpos): step 3 (wc-analytics/totals orders) missing')$(last 'CARD PERF FAILED')"
run 'an import that leaves one eligible order out fails' 1 card-import-short
assert '  ... recorded as 34719 of 34720 imported, the validator refusing it' "$(has 'FAIL  analytics not ready (hpos):')$(rj '[.analytics_ready[] | select(.storage == "hpos") | .imported, .eligible, .ok] == [34719, 34720, false]')$(has 'analytics readiness (hpos): imported 34719 of 34720 eligible orders')$(last 'CARD PERF FAILED')"
run 'a plan that leaves the order totals out fails (no call is added for it)' 1 card-workflow-unplanned
assert '  ... with its workflow line, 2 step records kept, the validator naming the missing step' "$(has 'FAIL  workflow cost (hpos):')$(rj '[.workflow_cost[] | select(.storage == "hpos") | (.steps | length), .ok] == [2, false]')$(has 'combined workflow cost (hpos): step 3 (wc-analytics/totals orders) missing')$(last 'CARD PERF FAILED')"
run 'a failed order totals step fails although the measurement says ok (the R1 hole)' 1 card-workflow-failed
assert '  ... the suite'"'"'s step check and the validator both refuse it' "$(has 'FAIL  workflow cost (hpos):')$(rj '[.workflow_cost[] | select(.storage == "hpos") | .ok] == [true]')$(has 'combined workflow cost (hpos): step 3 (wc-analytics/totals orders) failed (success false, error blzmcp_analytics_unavailable)')$(last 'CARD PERF FAILED')"
run 'reordered steps fail although the measurement says ok' 1 card-workflow-reordered
assert '  ... the suite'"'"'s step check and the validator both refuse it' "$(has 'FAIL  workflow cost (hpos):')$(has 'combined workflow cost (hpos): step 2 is wc-analytics/totals, expected blaze-mcp-toolkit/card-testing (substituted or reordered)')$(has 'combined workflow cost (hpos): step 3 is blaze-mcp-toolkit/card-testing, expected wc-analytics/totals (substituted or reordered)')$(last 'CARD PERF FAILED')"
run 'a cleanup that leaves Analytics rows of the fixture fails' 1 card-analytics-leftover
assert '  ... with its cleanup line, recorded in the receipt, the validator refusing it' "$(has 'FAIL  cleanup after (hpos) incomplete:')$(rj '[.cleanup[] | select(.phase == "after" and .storage == "hpos") | .analytics_rows_remaining] == [2]')$(has 'cleanup after (hpos) missing or unclean')$(has 'cleanup after (hpos) does not show the imported Analytics rows removed')$(last 'CARD PERF FAILED')"

# Validator refusals: the suite's own checks all pass, only the validator stops the run.
run 'a smoke-size run is refused by the validator' 1 good BLZMCP_CARD_PERF_TRIALS=4
assert '  ... marked smoke, refused for its size, no FAIL line of the suite itself' "$(line 'SMOKE  this receipt is a smoke run (trials=4): it can never pass the gate')$(has 'CARD PERF INVALID: smoke receipt (not a gate run: trials=4)')$(has 'partition hpos/48h/file has 4 trials, not 5')$(nofail)$(last 'CARD PERF FAILED')"
run 'an HTTP answer refusing the look-back (as the abilities REST route did for a string since_hours) fails the run' 1 good BLZMCP_STANDIN_HTTP=string-hours
assert '  ... with an HTTP failure line per figure' "$(has 'FAIL  HTTP legacy 48h cold: http 400, valid body false')$(has 'FAIL  HTTP hpos 168h warm: http 400, valid body false')$(last 'CARD PERF FAILED')"
assert 'HTTP latency goes through the MCP Adapter route with an integer since_hours, never the abilities REST GET route' "$(S=$REPO/tests/matrix/card-testing-perf-suite.sh; grep -qF '/wp-json/mcp/mcp-adapter-default-server' "$S" && grep -qF '\"parameters\":{\"since_hours\":$window}' "$S" && ! grep -qF 'wp-abilities/v1/abilities/blaze-mcp-toolkit/card-testing/run' "$S" && echo true)"
run 'a run without HTTP credentials is refused by the validator' 1 good BLZMCP_PERF_PORT=
assert '  ... for its missing HTTP figures' "$(has 'no HTTP cold figure for legacy/48h')$(has 'no HTTP warm figure for hpos/168h')$(nofail)$(last 'CARD PERF FAILED')"
run 'a run without a candidate zip is refused by the validator' 1 good BLZMCP_PERF_CANDIDATE=
assert '  ... for its missing candidate hash' "$(has 'candidate_sha256 missing is not the candidate none')$(nofail)$(last 'CARD PERF FAILED')"
run 'a run without the source SHA is refused by the validator' 1 good BLZMCP_PERF_SOURCE_SHA=
assert '  ... for its missing source' "$(has 'source_sha missing is not the source none')$(nofail)"
run 'invalid settings stop before anything changes' 1 good BLZMCP_CARD_PERF_STORAGES='hpos mysql'
assert '  ... with its settings line, the stand-in never called' "$(line 'FAIL  invalid settings: STORAGES')$(last 'CARD PERF FAILED')$(tf test ! -f "$WORK/state/storage")"

# An interrupted run: the stand-in pauses in the first HPOS trial, this test sends SIGTERM to the
# suite, and the suite must remove the fixture and restore the original (legacy) storage.
rm -rf "$WORK/state" && mkdir -p "$WORK/state"
env FAKE_DIR="$WORK/state" FAKE_MODE=card-interrupt WPE="$REPO/tests/matrix/fake-wp-env.sh" BLZMCP_CARD_PERF_OUT="$WORK/card.json" \
	BLZMCP_CARD_PERF_CHUNK=25000 BLZMCP_CARD_PERF_JOBS=2 BLZMCP_PERF_CANDIDATE="$WORK/candidate.zip" BLZMCP_PERF_SOURCE_SHA="$SRC" \
	BLZMCP_MATRIX_ATTEMPT=selftest-1 "$BASH" "$REPO/tests/matrix/card-testing-perf-suite.sh" >"$WORK/log" 2>&1 &
pid=$!
n=0
while [ ! -f "$WORK/state/interrupt-ready" ] && [ "$n" -lt 600 ]; do
	sleep 0.1
	n=$((n + 1))
done
kill -TERM "$pid" 2>/dev/null
wait "$pid"
got=$?
assert "an interrupted run exits 130 (got $got)" "$(tf test "$got" -eq 130)"
assert '  ... it removed the fixture, restored legacy storage and ended CARD PERF FAILED' "$(line 'INTERRUPTED  removing the card fixture and restoring order storage to legacy sync=no')$(line '  order storage restored to legacy sync=no')$(last 'CARD PERF FAILED')$(tf test "$(cat "$WORK/state/storage" 2>/dev/null)" == legacy)$(if compgen -G "$WORK/state/seeded.*" >/dev/null; then echo false; else echo true; fi)"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
