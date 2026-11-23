#!/usr/bin/env bash
# Offline self-test for the matrix host admission (ruling Q8, REVIEW-pass4e):
# tests/matrix/host-admission.sh and its use in tests/matrix/run-matrix.sh. No Docker, no
# WordPress, no network: sysctl, nproc, docker and hostname are stubs on PATH, /proc/loadavg is a
# stub file, and the admission wait's sleep is a stub that records its calls. Writes only to a
# temporary directory.
#
#   bash tests/matrix/test-host-admission.sh
#
# Runs under the bash that runs it (/bin/bash 3.2 on macOS from scripts/run-integration.sh).
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-admission.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
pass=0
fail=0
ok() { pass=$((pass + 1)); echo "PASS  $*"; }
no() { fail=$((fail + 1)); echo "FAIL  $*"; }
eq() { if [ "$2" == "$3" ]; then ok "$1"; else no "$1 (expected '$2', got '$3')"; fi; }
yes() { if "${@:2}"; then ok "$1"; else no "$1"; fi; }
not() { if "${@:2}"; then no "$1"; else ok "$1"; fi; }

# --- stubs ---------------------------------------------------------------------------------------
STUB="$WORK/stub"
BIN="$WORK/bin"
mkdir -p "$STUB" "$BIN"
export STUB
# sysctl: hw.ncpu from $STUB/ncpu; vm.loadavg pops the first line of $STUB/loads (the last line
# repeats); a missing file is a failing key, as on Linux.
printf '%s\n' '#!/bin/sh' \
	'case "$2" in' \
	'hw.ncpu) [ -f "$STUB/ncpu" ] || exit 1; cat "$STUB/ncpu" ;;' \
	'vm.loadavg)' \
	'	[ -f "$STUB/loads" ] || exit 1' \
	'	head -1 "$STUB/loads"' \
	'	if [ "$(wc -l <"$STUB/loads")" -gt 1 ]; then tail -n +2 "$STUB/loads" >"$STUB/loads.next"; mv "$STUB/loads.next" "$STUB/loads"; fi ;;' \
	'machdep.cpu.brand_string) echo "Stub CPU" ;;' \
	'*) exit 1 ;;' \
	'esac' >"$BIN/sysctl"
printf '%s\n' '#!/bin/sh' '[ -f "$STUB/nproc" ] || exit 1' 'cat "$STUB/nproc"' >"$BIN/nproc"
printf '%s\n' '#!/bin/sh' '[ "$1" = ps ] || exit 1' '[ -f "$STUB/docker-fail" ] && exit 1' 'cat "$STUB/docker-ps" 2>/dev/null' 'exit 0' >"$BIN/docker"
printf '%s\n' '#!/bin/sh' 'echo stubhost' >"$BIN/hostname"
chmod +x "$BIN"/*
export PATH="$BIN:$PATH"
reset_stubs() {
	rm -f "$STUB"/*
	echo 12 >"$STUB/ncpu"
	: >"$STUB/docker-ps"
}
loads() { printf '%s\n' "$@" >"$STUB/loads"; }

HA_PROC_LOADAVG="$WORK/no-proc-loadavg"
# shellcheck source=tests/matrix/host-admission.sh
. "$REPO/tests/matrix/host-admission.sh"

# The admission wait's sleep: records the interval, returns at once.
SLEEPS="$WORK/sleeps"
sleep() { echo "$1" >>"$SLEEPS"; }

# --- 1. CPU count, bounds and the strict boundary ----------------------------------------------
reset_stubs
eq 'N from sysctl hw.ncpu (macOS)' 12 "$(ha_ncpu)"
rm -f "$STUB/ncpu"
echo 8 >"$STUB/nproc"
eq 'N from nproc when sysctl has no hw.ncpu (Linux)' 8 "$(ha_ncpu)"
rm -f "$STUB/nproc"
not 'no CPU count from either source fails' ha_ncpu
echo 0 >"$STUB/ncpu"
not 'a CPU count of 0 fails' ha_ncpu
echo 'abc' >"$STUB/ncpu"
not 'a non-numeric CPU count fails' ha_ncpu

eq 'N = 12: bounds load5 < 3.00 and load1 < 3.96 (strict, not rounded to 4)' '3.00 3.96' "$(ha_bounds 12)"
eq 'N = 8: bounds 2.00 and 2.64' '2.00 2.64' "$(ha_bounds 8)"
eq 'N = 10: bounds 2.50 and 3.30' '2.50 3.30' "$(ha_bounds 10)"
eq 'N = 1: bounds 0.25 and 0.33' '0.25 0.33' "$(ha_bounds 1)"

yes 'N = 12: load5 2.99 is admitted' ha_admits 12 1.00 2.99
not 'N = 12: load5 3.00 is not admitted' ha_admits 12 1.00 3.00
not 'N = 12: load5 3 (no decimals) is not admitted' ha_admits 12 1.00 3
yes 'N = 12: load5 2.999999 is admitted' ha_admits 12 1.00 2.999999
yes 'N = 12: load1 3.95 is admitted' ha_admits 12 3.95 1.00
not 'N = 12: load1 3.96 is not admitted' ha_admits 12 3.96 1.00
yes 'N = 12: load1 3.9599999 is admitted' ha_admits 12 3.9599999 1.00
not 'N = 12: load1 3.96000001 is not admitted' ha_admits 12 3.96000001 1.00
not 'N = 12: load1 4 is not admitted (the proposal rounded 3.96 to 4)' ha_admits 12 4 1.00
yes 'N = 12: the recorded terminal sample 3.76 / 2.62 is admitted (as ruling Q8 says)' ha_admits 12 3.76 2.62
not 'N = 12: the gate start sample 4.44 / 3.83 is not admitted' ha_admits 12 4.44 3.83
yes 'N = 12: both just inside (3.95 / 2.99) is admitted' ha_admits 12 3.95 2.99
not 'a non-numeric load is not admitted' ha_admits 12 x 1.00
not 'a negative load is not admitted' ha_admits 12 -1 1.00

# --- 2. load sources and the sample line --------------------------------------------------------
reset_stubs
loads '{ 3.76 2.62 2.40 }'
eq 'macOS sysctl vm.loadavg "{ 3.76 2.62 2.40 }" reads as 3.76 2.62' '3.76 2.62' "$(ha_load)"
loads '{ 3.76 2.62 2.40 }'
eq 'load source: sysctl vm.loadavg' 'sysctl vm.loadavg' "$(ha_load_source)"
rm -f "$STUB/loads"
HA_PROC_LOADAVG="$WORK/proc-loadavg"
echo '1.50 0.75 0.50 1/234 5678' >"$HA_PROC_LOADAVG"
eq 'Linux /proc/loadavg reads as 1.50 0.75' '1.50 0.75' "$(ha_load)"
eq 'load source: the /proc file' "$HA_PROC_LOADAVG" "$(ha_load_source)"
rm -f "$HA_PROC_LOADAVG"
not 'no load source fails' ha_load
echo 'garbage here' >"$HA_PROC_LOADAVG"
not 'an unparseable load average fails' ha_load
rm -f "$HA_PROC_LOADAVG"
HA_PROC_LOADAVG="$WORK/no-proc-loadavg"
loads '{ 3.76 2.62 2.40 }'
line=$(ha_sample_line 12)
yes "sampler line format '<UTC> host=<hostname> ncpu=<N> load1=<x> load5=<y>' ($line)" \
	grep -qE '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z host=stubhost ncpu=12 load1=3\.76 load5=2\.62$' <<<"$line"
eq 'host identity: hostname|N|CPU model|load source' 'stubhost|12|Stub CPU|sysctl vm.loadavg' "$(ha_identity 12)"

# --- 3. the admission wait ----------------------------------------------------------------------
reset_stubs
: >"$SLEEPS"
loads '{ 2.00 1.00 0.50 }'
out=$(ha_wait_admitted start "$WORK/s1.txt"; echo "rc=$? HA_ADMISSION=$HA_ADMISSION")
yes 'admitted at once: rc 0' grep -q 'rc=0 ' <<<"$out"
yes '  ... prints the ADMISSION start line with the sample and bounds' grep -qE '^ADMISSION start [0-9T:-]+Z host=stubhost ncpu=12 load1=2\.00 load5=1\.00 \(admitted: load5 < 3\.00 and load1 < 3\.96; waited 0s, 1 sample\(s\)\)$' <<<"$out"
yes '  ... HA_ADMISSION is phase|UTC|host|N|load1|load5|waited|samples' grep -qE 'HA_ADMISSION=start\|[0-9T:-]+Z\|stubhost\|12\|2\.00\|1\.00\|0\|1$' <<<"$out"
eq '  ... one sample recorded, tagged admission=start' 1 "$(grep -cE ' load5=1\.00 admission=start$' "$WORK/s1.txt")"
eq '  ... no sleep' 0 "$(wc -l <"$SLEEPS" | tr -d ' ')"

reset_stubs
: >"$SLEEPS"
loads '{ 4.44 3.83 3.00 }' '{ 3.25 3.10 3.00 }' '{ 3.96 2.50 2.00 }' '{ 3.76 2.62 2.40 }'
out=$(ha_wait_admitted perf "$WORK/s2.txt"; echo "rc=$? HA_ADMISSION=$HA_ADMISSION")
yes 'waits, then admits on the 4th sample: rc 0' grep -q 'rc=0 ' <<<"$out"
eq '  ... 3 WAIT lines (4.44/3.83, 3.25/3.10, then load1 3.96 at its bound)' 3 "$(grep -c '^HOST ADMISSION WAIT (perf)' <<<"$out")"
yes '  ... the ADMISSION perf line has the admitted 3.76 / 2.62 sample, 180 s and 4 samples' grep -qE '^ADMISSION perf .* load1=3\.76 load5=2\.62 \(admitted: .*; waited 180s, 4 sample\(s\)\)$' <<<"$out"
yes '  ... HA_ADMISSION records waited 180 and 4 samples' grep -qE 'HA_ADMISSION=perf\|.*\|12\|3\.76\|2\.62\|180\|4$' <<<"$out"
eq '  ... slept 3 times, 60 s each, inside the same run' '60 60 60' "$(paste -sd' ' "$SLEEPS")"
eq '  ... every sample recorded (4 lines tagged admission=perf)' 4 "$(grep -c ' admission=perf$' "$WORK/s2.txt")"

reset_stubs
: >"$SLEEPS"
loads '{ 5.00 4.00 3.00 }'
out=$(ha_wait_admitted perf "$WORK/s3.txt" 60 180; echo "rc=$? HA_ADMISSION=[$HA_ADMISSION]")
yes 'gives up: rc 1 and HA_ADMISSION empty' grep -q 'rc=1 HA_ADMISSION=\[\]' <<<"$out"
yes '  ... prints HOST ADMISSION NOT REACHED with the time, samples, last sample and bounds' grep -qE '^HOST ADMISSION NOT REACHED \(perf\) after 180s and 4 samples: last .* load1=5\.00 load5=4\.00; needs load5 < 3\.00 and load1 < 3\.96 \(ruling Q8\)$' <<<"$out"
not '  ... never prints an ADMISSION line' grep -q '^ADMISSION ' <<<"$out"

eq 'defaults: 60 s interval' 60 "$HA_INTERVAL_DEFAULT"
eq 'defaults: give up after 12 hours (43200 s)' 43200 "$HA_MAX_WAIT_DEFAULT"
reset_stubs
: >"$SLEEPS"
loads '{ 3.96 3.00 2.00 }'
out=$(ha_wait_admitted start "$WORK/s4.txt"; echo "rc=$?")
yes 'default give-up: rc 1 after 43200 s' grep -qE '^HOST ADMISSION NOT REACHED \(start\) after 43200s and 721 samples' <<<"$out"
eq '  ... 720 sleeps of 60 s' '720 60' "$(wc -l <"$SLEEPS" | tr -d ' ') $(sort -u "$SLEEPS")"
eq '  ... 721 samples recorded' 721 "$(wc -l <"$WORK/s4.txt" | tr -d ' ')"

reset_stubs
rm -f "$STUB/ncpu"
out=$(ha_wait_admitted start "$WORK/s5.txt"; echo "rc=$?")
yes 'no CPU count: NOT REACHED, rc 1' grep -qE '^HOST ADMISSION NOT REACHED \(start\): the logical CPU count is unavailable.*' <<<"$out"
reset_stubs
out=$(ha_wait_admitted start "$WORK/s6.txt"; echo "rc=$?")
yes 'unreadable load: NOT REACHED, rc 1' grep -q 'the load average is unreadable' <<<"$out"
yes '  ... rc 1' grep -q '^rc=1$' <<<"$out"

# --- 4. sample statistics -----------------------------------------------------------------------
printf '%s\n' '2026-11-24T00:00:00Z host=h ncpu=12 load1=2.00 load5=1.50' \
	'2026-11-24T00:01:00Z host=h ncpu=12 load1=5.10 load5=3.40 admission=perf' \
	'2026-11-24T00:02:00Z host=h ncpu=12 load1=3.96 load5=2.00' \
	'2026-11-24T00:03:00Z host=h ncpu=12 load1=10.25 load5=2.10' \
	'not a sample' >"$WORK/stats.txt"
eq 'stats: 4 samples, max load1 10.25, max load5 3.40, 3 above the bounds' '4 10.25 3.40 3' "$(ha_samples_stats "$WORK/stats.txt" 12)"
eq 'stats: no file is 0 samples' '0 0 0 0' "$(ha_samples_stats "$WORK/none.txt" 12)"

# --- 5. other wp-env environments ---------------------------------------------------------------
reset_stubs
printf '%s\n' '86c7-wordpress-1|0.0.0.0:8888->80/tcp, :::8888->80/tcp' '86c7-tests-wordpress-1|0.0.0.0:8889->80/tcp' '86c7-mysql-1|0.0.0.0:55001->3306/tcp' '86c7-cli-1|' >"$STUB/docker-ps"
out=$(ha_foreign_wpenv)
rc=$?
eq "the owner's port-8888 wp-env is refused (rc 1)" 1 "$rc"
eq '  ... named with its port (and its tests container on 8889), each once' '86c7-wordpress-1:8888 86c7-tests-wordpress-1:8889' "$out"
printf '%s\n' 'a1-wordpress-1|0.0.0.0:8896->80/tcp' 'a1-tests-wordpress-1|0.0.0.0:8897->80/tcp' 'b2-wordpress-1|0.0.0.0:8890->80/tcp' 'c3-tests-wordpress-1|0.0.0.0:8901->80/tcp' 'a1-mysql-1|0.0.0.0:55001->3306/tcp' 'a1-cli-1|' 'other-db|0.0.0.0:5432->5432/tcp' >"$STUB/docker-ps"
out=$(ha_foreign_wpenv)
eq "the matrix states' own ports 8890-8901 are allowed; mysql, cli and other containers ignored" '0 []' "$? [$out]"
printf '%s\n' 'x-wordpress-1|0.0.0.0:8902->80/tcp' >"$STUB/docker-ps"
out=$(ha_foreign_wpenv)
eq 'port 8902 (just outside the matrix range) is refused' '1 x-wordpress-1:8902' "$? $out"
printf '%s\n' 'y_wordpress_1|127.0.0.1:8889->80/tcp' >"$STUB/docker-ps"
out=$(ha_foreign_wpenv)
eq 'port 8889 (just below, older underscore naming) is refused' '1 y_wordpress_1:8889' "$? $out"
: >"$STUB/docker-ps"
out=$(ha_foreign_wpenv)
eq 'no containers is allowed' '0' "$?"
touch "$STUB/docker-fail"
out=$(ha_foreign_wpenv)
eq 'docker ps failing is refused (rc 2): absence cannot be proven' 2 "$?"

# --- 6. background sampler (real sleep, 1 s interval) ------------------------------------------
unset -f sleep
reset_stubs
loads '{ 1.00 0.50 0.25 }'
ha_sampler_start "$WORK/bg.txt" 1
sp=$HA_SAMPLER_PID
command sleep 2.5
ha_sampler_stop
n1=$(wc -l <"$WORK/bg.txt" | tr -d ' ')
yes "sampler wrote at least 2 lines in 2.5 s ($n1)" [ "$n1" -ge 2 ]
eq '  ... every line in the sampler format' "$n1" "$(grep -cE '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:]+Z host=stubhost ncpu=12 load1=1\.00 load5=0\.50$' "$WORK/bg.txt")"
not '  ... stopped: its process is gone' kill -0 "$sp" 2>/dev/null
command sleep 1.5
eq '  ... and writes nothing after the stop' "$n1" "$(wc -l <"$WORK/bg.txt" | tr -d ' ')"
# A process that starts the sampler and exits without stopping it: the sampler ends on its own.
sp=$("$BASH" -c '. "$1"; ha_sampler_start "$2" 1; echo "$HA_SAMPLER_PID"' _ "$REPO/tests/matrix/host-admission.sh" "$WORK/orphan.txt")
command sleep 2.5
not 'an orphaned sampler stops once the matrix process is gone' kill -0 "$sp" 2>/dev/null

# --- 7. run-matrix.sh itself (a copy in a stand-in checkout; wp-env is a stub that must not run) -
FAKE="$WORK/repo"
mkdir -p "$FAKE/tests/matrix" "$FAKE/tests/Integration" "$FAKE/node_modules/.bin" "$WORK/tmp"
cp "$REPO/tests/matrix/run-matrix.sh" "$REPO/tests/matrix/host-admission.sh" "$FAKE/tests/matrix/"
printf '%s\n' '#!/bin/sh' 'echo "wp-env must not run in this test" >&2' 'exit 1' >"$FAKE/node_modules/.bin/wp-env"
chmod +x "$FAKE/node_modules/.bin/wp-env"
touch "$FAKE/tests/Integration/test-payment-orders.php" "$FAKE/tests/Integration/test-payment-health.php"
echo 'not a zip' >"$WORK/candidate.zip"
leftover() { ps -A -o command= 2>/dev/null | grep -F "$FAKE/tests/matrix/run-matrix.sh" | grep -v grep; }

reset_stubs
printf '%s\n' '86c7-wordpress-1|0.0.0.0:8888->80/tcp' >"$STUB/docker-ps"
loads '{ 1.00 0.50 0.25 }'
out=$(TMPDIR="$WORK/tmp" "$BASH" "$FAKE/tests/matrix/run-matrix.sh" "$WORK/candidate.zip" 2>&1)
rc=$?
eq 'run-matrix: a wp-env on 8888 refuses the start (exit 1)' 1 "$rc"
yes '  ... names the port and the ruling, then MATRIX FAILED' grep -qF 'HOST ADMISSION REFUSED (start): another wp-env WordPress environment is running: 86c7-wordpress-1:8888' <<<"$out"
yes '  ... and ends MATRIX FAILED' grep -q '^MATRIX FAILED$' <<<"$out"
not '  ... before any admission sample or state' grep -qE '^(ADMISSION|=== )' <<<"$out"
yes '  ... the bounds are printed' grep -qF 'admitted when load5 < 3.00 and load1 < 3.96 (strict)' <<<"$out"

reset_stubs
rm -f "$STUB/ncpu"
out=$(TMPDIR="$WORK/tmp" "$BASH" "$FAKE/tests/matrix/run-matrix.sh" "$WORK/candidate.zip" 2>&1)
eq 'run-matrix: no CPU count fails the start (exit 1, MATRIX FAILED)' '1 yes' "$? $(grep -q '^MATRIX FAILED$' <<<"$out" && echo yes)"

# Admitted at the start, no perf phase (a state with no config): the summary carries the host,
# the start admission and the samples; the recorder (not present in the stand-in) is not reached
# in a passing way, and the run fails.
reset_stubs
loads '{ 2.00 1.00 0.50 }'
out=$(TMPDIR="$WORK/tmp" BLZMCP_MATRIX_STATES=nostate "$BASH" "$FAKE/tests/matrix/run-matrix.sh" "$WORK/candidate.zip" 2>&1)
rc=$?
summary=$(ls -t "$WORK"/tmp/blzmcp-matrix-logs.*/summary.txt 2>/dev/null | head -1)
eq 'run-matrix: admitted start, incomplete run: exit 1, MATRIX FAILED' '1 yes' "$rc $(grep -q '^MATRIX FAILED$' <<<"$out" && echo yes)"
yes '  ... prints the ADMISSION start line' grep -qE '^ADMISSION start .* load1=2\.00 load5=1\.00 \(admitted' <<<"$out"
yes '  ... summary HOST line' grep -qx 'HOST=stubhost|12|Stub CPU|sysctl vm.loadavg' "${summary:-/dev/null}"
yes '  ... summary ADMISSION=start line' grep -qE '^ADMISSION=start\|[0-9T:-]+Z\|stubhost\|12\|2\.00\|1\.00\|0\|1$' "${summary:-/dev/null}"
not '  ... no ADMISSION=perf line (no performance phase ran)' grep -q '^ADMISSION=perf' "${summary:-/dev/null}"
yes '  ... LOAD_SAMPLES of at least 1 (sampler plus admission sample)' grep -qE '^LOAD_SAMPLES=[1-9][0-9]*$' "${summary:-/dev/null}"
yes '  ... LOAD_MAX and LOAD_OVER' grep -qE '^LOAD_MAX=[0-9.]+\|[0-9.]+$' "${summary:-/dev/null}"
yes '  ... load-samples.txt beside it' [ -s "$(dirname "${summary:-/x}")/load-samples.txt" ]
eq '  ... no sampler left running after the matrix exits' '' "$(leftover)"

# Interrupted while waiting for admission at the start: TERM stops the run and its sampler at once.
reset_stubs
loads '{ 9.00 9.00 9.00 }'
TMPDIR="$WORK/tmp" "$BASH" "$FAKE/tests/matrix/run-matrix.sh" "$WORK/candidate.zip" >"$WORK/term.log" 2>&1 &
mp=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do grep -q '^HOST ADMISSION WAIT (start)' "$WORK/term.log" && break; command sleep 0.5; done
yes 'run-matrix: waits at the start while the load is above the bounds' grep -q '^HOST ADMISSION WAIT (start).*needs load5 < 3.00 and load1 < 3.96; next sample in 60s$' "$WORK/term.log"
kill -TERM "$mp"
for _ in 1 2 3 4 5 6 7 8 9 10; do kill -0 "$mp" 2>/dev/null || break; command sleep 0.5; done
not '  ... TERM ends it within 5 s' kill -0 "$mp" 2>/dev/null
wait "$mp" 2>/dev/null
eq '  ... with exit 143' 143 "$?"
command sleep 0.5
eq '  ... and leaves no sampler running' '' "$(leftover)"
not '  ... never admitted' grep -q '^ADMISSION ' "$WORK/term.log"

# --- 8. structure: always on, before the first state and immediately before timing ----------------
RM="$REPO/tests/matrix/run-matrix.sh"
first_state=$(grep -n 'run_state "\$state"$' "$RM" | head -1 | cut -d: -f1)
start_wait=$(grep -n 'ha_wait_admitted start' "$RM" | head -1 | cut -d: -f1)
perf_wait=$(grep -n 'ha_wait_admitted perf' "$RM" | head -1 | cut -d: -f1)
perf_run=$(grep -n 'tests/matrix/perf-suite.sh"' "$RM" | head -1 | cut -d: -f1)
perf_store=$(grep -n 'perf_store" != ActionScheduler_DBStore' "$RM" | head -1 | cut -d: -f1)
before() { [ -n "$1" ] && [ -n "$2" ] && [ "$1" -lt "$2" ]; }
yes 'run-matrix waits for admission before the first state' before "$start_wait" "$first_state"
yes 'run-matrix waits for admission after the Action Scheduler store check' before "$perf_store" "$perf_wait"
yes '  ... and right before perf-suite.sh (within 12 lines, nothing timed in between)' before "$perf_wait" "$perf_run"
yes '  ... (distance check)' [ $((${perf_run:-0} - ${perf_wait:-0})) -le 12 ]
not 'host admission needs no BLZMCP_ variable (swamp unsets them)' grep -q 'BLZMCP_' "$REPO/tests/matrix/host-admission.sh"
not 'run-matrix gates no admission step behind a variable' grep -nE '^[[:space:]]*if .*\$\{?(BLZMCP|HA)_[A-Z_]*.*(ha_wait_admitted|ha_sampler_start|foreign_wpenv_check)' "$RM"
yes 'run-matrix stops the sampler on EXIT, INT and TERM' grep -q "^trap 'ha_sampler_stop' EXIT$" "$RM"
yes '  ... INT' grep -q "^trap 'ha_sampler_stop; exit 130' INT$" "$RM"
yes '  ... TERM' grep -q "^trap 'ha_sampler_stop; exit 143' TERM$" "$RM"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
