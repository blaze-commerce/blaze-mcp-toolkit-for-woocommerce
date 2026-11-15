#!/usr/bin/env bash
# Self-test for perf-suite.sh's gate (FEATURE-PLAN item 10, REVIEW-pass4 #10), against the
# stand-in tests/matrix/fake-wp-env.sh: no Docker, no WordPress, writes only to a temporary
# directory. A faithful run passes; a timed trial whose order, log or job reader failed, a forced
# reader failure that is not detected, or (in the matrix mode) jobs on HybridStore each fail it.
#
#   bash tests/matrix/test-perf-suite.sh
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-perf.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
pass=0
fail=0

# run <label> <expected exit> <mode> [extra env...]
run() {
	local label=$1 want=$2 mode=$3 got
	shift 3
	rm -rf "$WORK/state" && mkdir -p "$WORK/state"
	env FAKE_DIR="$WORK/state" FAKE_MODE="$mode" WPE="$REPO/tests/matrix/fake-wp-env.sh" BLZMCP_PERF_OUT="$WORK/perf.json" \
		BLZMCP_PERF_RECENT=40 BLZMCP_PERF_OLD=20 BLZMCP_PERF_CHUNK=20 BLZMCP_PERF_JOBS=2 "$@" \
		bash "$REPO/tests/matrix/perf-suite.sh" >"$WORK/log" 2>&1
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
assert 'receipt keeps every trial with its reader validation' "$(jq -r '(.trials | length) == 40 and (.trials | all(.validation.readers.orders == true))' "$WORK/perf.json")"
assert 'the log ends PERF PASSED' "$(tail -1 "$WORK/log" | grep -qx 'PERF PASSED' && echo true)"

for r in orders logs jobs; do
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

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
