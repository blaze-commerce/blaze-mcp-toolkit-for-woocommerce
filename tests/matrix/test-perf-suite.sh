#!/usr/bin/env bash
# Self-test for perf-suite.sh's gate (FEATURE-PLAN item 10, REVIEW-pass4 #10), against the
# stand-in tests/matrix/fake-wp-env.sh: no Docker, no WordPress, writes only to a temporary
# directory. A faithful run passes; a timed trial whose order, log or job reader failed, a forced
# reader failure that is not detected, or (in the matrix mode) jobs on HybridStore each fail it.
# The budget rule per partition (ruling Q6) is driven through a generated timing wrapper.
#
#   bash tests/matrix/test-perf-suite.sh
#
# perf-suite.sh and the stand-in run under the same bash as this script ($BASH), so
# `/bin/bash tests/matrix/test-perf-suite.sh` on macOS checks them under bash 3.2.
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-perf.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
# The stand-in starts through `#!/usr/bin/env bash`: put this script's bash first on PATH for it.
mkdir -p "$WORK/bin" && ln -s "$BASH" "$WORK/bin/bash"
export PATH="$WORK/bin:$PATH"
pass=0
fail=0

# run <label> <expected exit> <mode> [extra env...]
run() {
	local label=$1 want=$2 mode=$3 got
	shift 3
	rm -rf "$WORK/state" && mkdir -p "$WORK/state"
	env FAKE_DIR="$WORK/state" FAKE_MODE="$mode" WPE="$REPO/tests/matrix/fake-wp-env.sh" BLZMCP_PERF_OUT="$WORK/perf.json" \
		BLZMCP_PERF_RECENT=40 BLZMCP_PERF_OLD=20 BLZMCP_PERF_OUTSIDE=20 BLZMCP_PERF_CHUNK=20 BLZMCP_PERF_JOBS=2 "$@" \
		"$BASH" "$REPO/tests/matrix/perf-suite.sh" >"$WORK/log" 2>&1
	got=$?
	if [ "$got" -eq "$want" ]; then
		echo "PASS  $label (exit $got)"
		pass=$((pass + 1))
	else
		echo "FAIL  $label (expected exit $want, got $got)"
		grep -E '^(FAIL|partition|forced|jobs)' "$WORK/log" | head -8 | sed 's/^/      /'
		fail=$((fail + 1))
	fi
}
assert() { if [ "$2" == true ]; then echo "PASS  $1"; pass=$((pass + 1)); else echo "FAIL  $1"; fail=$((fail + 1)); fi; }

run 'faithful run with every reader valid passes' 0 good BLZMCP_PERF_REQUIRE_DBSTORE=1
assert 'receipt: 8 partitions, 8 forced probes detected, exact jobs path required and seen' "$(jq -r '(.partitions | length) == 8 and .all_pass and .forced_failures_detected and (.forced_failure_checks | length) == 8 and .exact_jobs_required and .jobs_store == ["ActionScheduler_DBStore"] and (.jobs_path | startswith("exact"))' "$WORK/perf.json")"
assert 'receipt keeps every trial with its reader validation' "$(jq -r '(.trials | length) == 40 and (.trials | all(.validation.readers.orders == true and .validation.readers.outside == true))' "$WORK/perf.json")"
assert 'receipt: schema 2, the excluded-origin population counted separately, verified per storage' "$(jq -r '.schema == "blzmcp-payment-performance/2" and .seed.outside_orders == 20 and (.seed.outside_methods | length) == 10 and ([.seed.storages[] | .seeded_outside == 20 and .verify.outside == 20 and .verify.checkout == 60] == [true, true])' "$WORK/perf.json")"
assert 'receipt: 4 method-cap cases (1,000 and 1,001 IDs per storage), all ok, separate from the trials' "$(jq -r '.cap_ok and (.cap_cases | length) == 4 and ([.cap_checks[] | .runs == 1 and .ok] | all) and ([.cap_cases[] | select(.distinct_ids == 1001) | .methods_truncated] == [true, true]) and (.trials | all(has("distinct_ids") | not))' "$WORK/perf.json")"
assert 'receipt: every trial has rows examined and selected, and partitions carry their maxima' "$(jq -r '(.trials | all((.rows_examined | type) == "number" and (.rows_selected | type) == "number")) and (.partitions | all(.rows_examined_max == 61000))' "$WORK/perf.json")"
assert 'the log ends PERF PASSED' "$(tail -1 "$WORK/log" | grep -qx 'PERF PASSED' && echo true)"
assert 'receipt records every cleanup (5 per storage + final), all orders removed, storage restored' "$(jq -r '.cleanup_ok and (.cleanup | length) == 11 and (.cleanup_checks | all(.passed)) and ([.cleanup[] | select(.phase == "after") | .orders_removed == .expected_removed and .expected_removed == 80 and .tagged_remaining == 0] == [true, true]) and ([.cleanup[] | select(.phase == "cap") | .orders_removed == .expected_removed and .expected_removed == 1025] == [true, true]) and .storage_restored == {storage:"legacy", sync:"no", ok:true}' "$WORK/perf.json")"
assert 'the perf log prints each cleanup and the cleanup summary' "$(grep -q '^  cleanup after (hpos): orders removed 80, tagged fixture orders remaining 0' "$WORK/log" && grep -q '^cleanup: complete: legacy removed 80/80, 0 tagged left; hpos removed 80/80, 0 tagged left; order storage restored to legacy sync=no: true' "$WORK/log" && echo true)"

# Wave 4 (REVIEW-pass4b gap 2): cleanup is evidence, and an incomplete one fails closed.
run 'a cleanup that leaves fixture orders behind fails the gate' 1 cleanup-leftover BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded: tagged orders remaining, cleanup_ok false, all_pass false' "$(jq -r '.cleanup_ok == false and .all_pass == false and ([.cleanup[] | select(.phase == "after") | .tagged_remaining] == [3, 3])' "$WORK/perf.json")"
run 'a cleanup that removes fewer orders than were seeded fails the gate' 1 cleanup-short BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded with the expected count' "$(jq -r '.cleanup_ok == false and ([.cleanup[] | select(.phase == "after") | "\(.orders_removed)/\(.expected_removed)"] == ["79/80", "79/80"])' "$WORK/perf.json")"
run 'a cleanup with no result fails the gate' 1 cleanup-silent BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded as no cleanup result' "$(jq -r '.cleanup_ok == false and (.cleanup | all(.error == "no cleanup result" or .mode == "diagnostics"))' "$WORK/perf.json")"
run 'a diagnostics cleanup that leaves fixture jobs fails the gate' 1 diag-leftover BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded per logger' "$(jq -r '.cleanup_ok == false and ([.cleanup_checks[] | select(.passed == false) | .phase] | unique) == ["diag-db", "diag-file"]' "$WORK/perf.json")"
run 'a failed order storage restore fails the gate' 1 restore-fail BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded as storage_restored ok false' "$(jq -r '.cleanup_ok == false and .storage_restored.ok == false and (.partitions | all(.pass))' "$WORK/perf.json")"

# 1.2.1 item 5: the method-cap stress cases have their own assertions and gate the suite.
run 'a 1,001-ID method-cap case that fails its assertions fails the gate' 1 cap-fail BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded as that cap case not ok, trials untouched' "$(jq -r '.cap_ok == false and .all_pass == false and ([.cap_checks[] | select(.ok == false) | "\(.storage)/\(.distinct_ids)"] == ["hpos/1001"]) and (.partitions | all(.pass))' "$WORK/perf.json")"
run 'a method-cap case with no result fails the gate' 1 cap-silent BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... recorded as no output' "$(jq -r '.cap_ok == false and ([.cap_cases[] | select(.storage == "hpos" and .distinct_ids == 1001) | .failures] == [["no output"]])' "$WORK/perf.json")"
for r in orders logs jobs outside; do
	run "a timed trial whose $r reader failed fails the gate" 1 "invalid-$r" BLZMCP_PERF_REQUIRE_DBSTORE=1
	assert "  ... hpos 24h db is the failed partition, with the $r failure kept" "$(jq -r --arg r "$r" '[.partitions[] | select(.pass == false) | "\(.storage)/\(.window)/\(.logger)"] == ["hpos/24/db"] and (.trials | map(select(.ok == false)) | length) == 5 and (.all_pass == false)' "$WORK/perf.json")"
done
for r in orders logs jobs; do
	run "a forced $r-reader failure that is not detected fails the gate" 1 "undetected-$r" BLZMCP_PERF_REQUIRE_DBSTORE=1
	assert "  ... recorded as not detected" "$(jq -r --arg r "$r" '.forced_failures_detected == false and ([.forced_failure_checks[] | select(.detected == false) | .force] | unique) == [$r]' "$WORK/perf.json")"
done
run 'jobs on HybridStore fail the gate where the exact store is required (matrix)' 1 hybrid BLZMCP_PERF_REQUIRE_DBSTORE=1
assert '  ... jobs path recorded as sampled' "$(jq -r '.jobs_store == ["ActionScheduler_HybridStore"] and (.jobs_path | startswith("sampled"))' "$WORK/perf.json")"
run 'jobs on HybridStore pass locally when the exact store is not required' 0 hybrid
assert '  ... and the receipt says the exact store was not required' "$(jq -r '.exact_jobs_required == false' "$WORK/perf.json")"

# Ruling Q6 (REVIEW-pass4c): legacy DB-logger partitions pass 4 of 5 (all 5 valid, at least 4
# strictly below 1500 ms, none above 2000 ms); every other partition needs 5 of 5 below 1500 ms.
# A wrapper around the stand-in sets each timed trial's elapsed_ms from TIMES, a list of
# <storage>/<window>/<logger>=<t1>,<t2>,... entries (the n-th normal trial of that partition gets
# the n-th value; "invalid" makes that trial fail its order reader, "null" drops its elapsed_ms).
# Unlisted partitions keep the stand-in's 120 ms; forced probes are never rewritten.
# shellcheck disable=SC2016 # The wrapper's lines are written literally; they expand when it runs.
printf '%s\n' '#!/usr/bin/env bash' \
	'# Generated by test-perf-suite.sh: fake-wp-env.sh with per-partition trial timings (TIMES).' \
	'set -uo pipefail' \
	"FAKE='$REPO/tests/matrix/fake-wp-env.sh'" \
	'if [ "${3:-}" != wp ] || [ "${4:-}" != eval-file ] || [ "$(basename "${5:-}")" != measure-payment-health.php ] || [ -n "${12:-}" ]; then exec "$FAKE" "$@"; fi' \
	'st=$(cat "$FAKE_DIR/storage" 2>/dev/null || echo legacy)' \
	'lg=$(cat "$FAKE_DIR/logger" 2>/dev/null || echo file)' \
	'cf="$FAKE_DIR/count.$st.$6.$lg"' \
	'n=$(($(cat "$cf" 2>/dev/null || echo 0) + 1))' \
	'echo "$n" >"$cf"' \
	'v=' \
	'for e in ${TIMES:-}; do [ "${e%%=*}" == "$st/$6/$lg" ] && v=$(cut -d, -f"$n" <<<"${e#*=}"); done' \
	'out=$("$FAKE" "$@")' \
	'[ -n "$v" ] || { printf "%s\n" "$out"; exit 0; }' \
	'm=$(sed -n "s/^MEASURE=//p" <<<"$out")' \
	'case "$v" in' \
	'invalid) m=$(jq -c ".ok = false | .validation.readers.orders = false | .validation.failures = [\"orders: forced invalid\"]" <<<"$m") ;;' \
	'null) m=$(jq -c "del(.elapsed_ms)" <<<"$m") ;;' \
	'*) m=$(jq -c --argjson v "$v" ".elapsed_ms = \$v" <<<"$m") ;;' \
	'esac' \
	'echo "MEASURE=$m"' >"$WORK/timed-wp-env.sh"
chmod +x "$WORK/timed-wp-env.sh"
# q6 <label> <expected exit> <TIMES> [extra env...]
q6() {
	local label=$1 want=$2 times=$3
	shift 3
	run "$label" "$want" good BLZMCP_PERF_REQUIRE_DBSTORE=1 WPE="$WORK/timed-wp-env.sh" TIMES="$times" "$@"
}
# part <storage/window/logger>: that partition's receipt entry.
part() { jq -c --arg k "$1" '.partitions[] | select("\(.storage)/\(.window)/\(.logger)" == $k)' "$WORK/perf.json"; }

q6 'Q6: one slow trial in each legacy DB-logger partition (run 1 shape: 1605.8, 1630.1 ms) passes' 0 \
	'legacy/24/db=1177.4,1248.8,1191.9,1172,1605.8 legacy/168/db=1325.4,1192.6,1215.6,1291.2,1630.1'
assert '  ... both legacy db partitions pass under 4of5<1500,max<=2000 with 4 under budget; all_pass' "$(jq -r '.all_pass and ([.partitions[] | select(.storage == "legacy" and .logger == "db") | .rule == "4of5<1500,max<=2000" and .trials_under_budget == 4 and .pass] == [true, true])' "$WORK/perf.json")"
assert '  ... every other partition records 5of5<1500; the receipt names the rules and the 2000 ms bound' "$(jq -r '([.partitions[] | select(.storage != "legacy" or .logger != "db") | .rule] | unique) == ["5of5<1500"] and ([.partitions[] | select(.storage != "legacy" or .logger != "db")] | length) == 6 and .hard_max_ms == 2000 and .budget_rules == {default:"5of5<1500", "legacy/24h/db":"4of5<1500,max<=2000", "legacy/168h/db":"4of5<1500,max<=2000"}' "$WORK/perf.json")"
assert '  ... payment_health_cold_ms still reports the true maximum (1630.1 ms), not a median' "$(jq -r '.payment_health_cold_ms["legacy/168h/db"] == 1630.1 and .payment_health_cold_ms["legacy/24h/db"] == 1605.8' "$WORK/perf.json")"
assert '  ... the log prints the rule per partition' "$(grep -qF 'partition legacy 168h db: rule 4of5<1500,max<=2000, max 1630.1 ms over 5 trials' "$WORK/log" && grep -qF 'partition hpos 24h file: rule 5of5<1500,' "$WORK/log" && echo true)"
q6 'Q6 boundary: a legacy db trial of exactly 2000 ms (the other 4 below 1500) passes' 0 'legacy/24/db=1100,1100,1100,1100,2000'
q6 'Q6 boundary: a legacy db trial of 2000.1 ms fails' 1 'legacy/24/db=1100,1100,1100,1100,2000.1'
assert '  ... only legacy/24/db fails' "$(jq -r '[.partitions[] | select(.pass == false) | "\(.storage)/\(.window)/\(.logger)"] == ["legacy/24/db"] and .all_pass == false' "$WORK/perf.json")"
q6 'Q6 boundary: one legacy db trial of exactly 1500 ms is the one allowed slow trial' 0 'legacy/168/db=1100,1500,1100,1100,1100'
assert '  ... recorded as 4 under budget' "$(jq -r '(.partitions[] | select(.storage == "legacy" and .window == 168 and .logger == "db") | .trials_under_budget == 4 and .pass)' "$WORK/perf.json")"
q6 'Q6 boundary: two legacy db trials of exactly 1500 ms fail (1500 is not below the budget)' 1 'legacy/168/db=1100,1500,1100,1500,1100'
assert '  ... only legacy/168/db fails, 3 under budget' "$(jq -r '[.partitions[] | select(.pass == false) | "\(.storage)/\(.window)/\(.logger)"] == ["legacy/168/db"]' "$WORK/perf.json")$(part legacy/168/db | jq -r 'if .trials_under_budget == 3 then "" else "x" end')"
q6 'Q6: a second slow trial in a legacy db partition fails' 1 'legacy/24/db=1177,1606,1192,1172,1546'
assert '  ... recorded with 3 under budget' "$(part legacy/24/db | jq -r '.trials_under_budget == 3 and .pass == false')"
q6 'Q6: a legacy db trial above 2000 ms fails even with 4 fast trials' 1 'legacy/168/db=1100,1100,2400,1100,1100'
assert '  ... recorded with 4 under budget and max 2400 ms' "$(part legacy/168/db | jq -r '.trials_under_budget == 4 and .max_ms == 2400 and .pass == false')"
q6 'Q6: an invalid legacy db trial fails even when the other 4 are fast' 1 'legacy/24/db=1100,1100,1100,invalid,1100'
assert '  ... recorded as an invalid trial, 4 valid' "$(part legacy/24/db | jq -r '.failed_trials == 1 and .valid_timed_trials == 4 and .pass == false')"
q6 'Q6: a legacy db trial with no elapsed_ms fails even when the other 4 are fast' 1 'legacy/168/db=1100,null,1100,1100,1100'
assert '  ... recorded with 4 timed trials' "$(part legacy/168/db | jq -r '.valid_timed_trials == 4 and .pass == false')"
q6 'Q6: fewer than 5 trials fail the legacy db partitions' 1 '' BLZMCP_PERF_TRIALS=4
assert '  ... exactly the 2 legacy db partitions fail' "$(jq -r '[.partitions[] | select(.pass == false) | "\(.storage)/\(.window)/\(.logger)"] == ["legacy/24/db", "legacy/168/db"]' "$WORK/perf.json")"
q6 'Q6: one slow trial in an HPOS DB-logger partition fails (5 of 5)' 1 'hpos/24/db=120,120,120,120,1600'
assert '  ... recorded under 5of5<1500' "$(part hpos/24/db | jq -r '.rule == "5of5<1500" and .trials_under_budget == 4 and .pass == false')"
q6 'Q6: one slow trial in a legacy file-logger partition fails (5 of 5)' 1 'legacy/168/file=1100,1100,1100,1100,1501'
assert '  ... only legacy/168/file fails, under 5of5<1500' "$(jq -r '[.partitions[] | select(.pass == false) | "\(.storage)/\(.window)/\(.logger)/\(.rule)"] == ["legacy/168/file/5of5<1500"]' "$WORK/perf.json")"
q6 'Q6 boundary: a 5of5 trial of exactly 1500 ms fails' 1 'hpos/168/file=120,1500,120,120,120'
assert '  ... only hpos/168/file fails' "$(jq -r '[.partitions[] | select(.pass == false) | "\(.storage)/\(.window)/\(.logger)"] == ["hpos/168/file"]' "$WORK/perf.json")"
q6 'Q6 boundary: 5of5 trials of 1499.9 ms pass' 0 'hpos/168/file=1499.9,1499.9,1499.9,1499.9,1499.9 legacy/24/db=1499.9,1499.9,1499.9,1499.9,1499.9'

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
