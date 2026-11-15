#!/usr/bin/env bash
# Payment-health cold performance budget (FEATURE-PLAN item 10). Runs on the HOST against one
# wp-env (the matrix recommended state, or the local development wp-env); in-container work goes
# through `wp-env run cli`. Disposable test environments only: it seeds and removes 60k orders.
#
#   WPE=/path/to/wp-env BLZMCP_T=wp-content/blzmcp-tests BLZMCP_PERF_OUT=/path/perf.json \
#     bash tests/matrix/perf-suite.sh
#
# For each authoritative storage (legacy, then HPOS; sync off, each populated on its own after the
# other's orders are removed): seed 50,000 recent checkout orders over 28 days across 3 methods
# plus 10,000 older ones (tests/matrix/seed-payment-volume.php, CRUD, chunked, in parallel
# processes; seed time is excluded), verify the counts in the authoritative store and a CRUD
# sample, then for the file logger and the database logger (near-budget Stripe logs and 40 failed
# Stripe jobs) and for since_hours 24 and 168 run 5 uncached calls, each in a fresh PHP request
# (tests/matrix/measure-payment-health.php). HTTP latency (cold and warm, per storage and window)
# through the abilities REST route when BLZMCP_PERF_PORT, BLZMCP_PERF_LOGIN and
# BLZMCP_PERF_APP_PASSWORD are set (run-matrix.sh creates a temporary application password with
# credentials.php and revokes it after). The matrix record refuses a receipt with no HTTP figure.
# The receipt names the Action Scheduler store the jobs check ran on (jobs_store, jobs_path).
#
# Writes BLZMCP_PERF_OUT (JSON): every trial, the per-partition maximum as payment_health_cold_ms,
# the 1500 ms budget verdict, warm timings, query counts, plans, memory, versions, the seed
# distribution and the container limits. A failed or unavailable call is a failed trial, never a
# fast one. The original order storage is restored and every fixture row removed on every exit.
# Ends with "PERF PASSED" or "PERF FAILED".
#
# Env: BLZMCP_PERF_RECENT (50000) BLZMCP_PERF_OLD (10000) BLZMCP_PERF_TRIALS (5)
#      BLZMCP_PERF_JOBS (4 parallel seed processes) BLZMCP_PERF_CHUNK (2500)
#      BLZMCP_PERF_STORAGES ("legacy hpos") BLZMCP_PERF_CANDIDATE (zip path, hashed into the record)
set -uo pipefail

WPE=${WPE:-wp-env}
T=${BLZMCP_T:-wp-content/blzmcp-tests}
OUT=${BLZMCP_PERF_OUT:?BLZMCP_PERF_OUT}
RECENT=${BLZMCP_PERF_RECENT:-50000}
OLD=${BLZMCP_PERF_OLD:-10000}
TRIALS=${BLZMCP_PERF_TRIALS:-5}
JOBS=${BLZMCP_PERF_JOBS:-4}
CHUNK=${BLZMCP_PERF_CHUNK:-2500}
STORAGES=${BLZMCP_PERF_STORAGES:-legacy hpos}
BUDGET=1500
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-perf.XXXXXX")
SEED="$T/matrix/seed-payment-volume.php"
MEASURE="$T/matrix/measure-payment-health.php"
SWITCH="$T/Integration/test-payment-orders.php"

wpc() { "$WPE" run cli wp "$@" 2>/dev/null; }
opt() { wpc option get "$1" | tr -d '\r' | grep -E '^(yes|no)$' | tail -1; }
orig_hpos=$(opt woocommerce_custom_orders_table_enabled)
orig_sync=$(opt woocommerce_custom_orders_table_data_sync_enabled)
[ "$orig_hpos" == yes ] && orig_set=hpos || orig_set=legacy
[ -n "$orig_sync" ] || orig_sync=no
switch_to() { # storage sync
	"$WPE" run cli env BLZMCP_PF_SET="$1" BLZMCP_PF_SYNC="$2" wp eval-file "$SWITCH" 2>&1 | grep -qE '^[0-9]+ passed, 0 failed'
}
on_exit() {
	wpc eval-file "$SEED" cleanup >/dev/null
	switch_to "$orig_set" "$orig_sync" || echo "CLEANUP ERROR  order storage not restored to $orig_set sync=$orig_sync"
	rm -rf "$WORK"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

seed_range() { # cmd total now
	local cmd=$1 total=$2 now=$3 off=0 running=0
	while [ "$off" -lt "$total" ]; do
		local n=$CHUNK
		[ $((off + n)) -gt "$total" ] && n=$((total - off))
		"$WPE" run cli wp eval-file "$SEED" "$cmd" "$off" "$n" "$now" "$total" >"$WORK/seed-$cmd-$off.log" 2>&1 &
		running=$((running + 1))
		off=$((off + n))
		if [ "$running" -ge "$JOBS" ]; then
			wait -n
			running=$((running - 1))
		fi
	done
	wait
	grep -h '^SEEDED=' "$WORK"/seed-"$cmd"-*.log | sed 's/^SEEDED=//' | paste -sd+ - | bc
}

: >"$WORK/trials.jsonl"
: >"$WORK/storage.jsonl"
fail=0
for storage in $STORAGES; do
	wpc eval-file "$SEED" cleanup >/dev/null
	if ! switch_to "$storage" no; then
		echo "FAIL  could not switch order storage to $storage (sync off)"
		fail=1
		continue
	fi
	now=$(date +%s)
	t0=$(date +%s)
	got_recent=$(seed_range orders "$RECENT" "$now")
	got_old=$(seed_range old "$OLD" "$now")
	seed_s=$(($(date +%s) - t0))
	verify=$(wpc eval-file "$SEED" verify "$RECENT" "$OLD" | sed -n 's/^VERIFY=//p')
	echo "storage $storage: seeded $got_recent recent + $got_old older in ${seed_s}s (excluded); verify $verify"
	jq -cn --arg s "$storage" --argjson v "${verify:-null}" --argjson secs "$seed_s" --argjson r "${got_recent:-0}" --argjson o "${got_old:-0}" \
		'{storage:$s, verify:$v, seed_seconds_excluded:$secs, seeded_recent:$r, seeded_old:$o}' >>"$WORK/storage.jsonl"
	if [ "$(jq -r '.ok // false' <<<"${verify:-null}")" != true ] || [ "$(jq -r '.authority' <<<"$verify")" != "$storage" ]; then
		echo "FAIL  storage $storage: fixture not verified in the authoritative store"
		fail=1
		continue
	fi
	for logger in file db; do
		diag=$(wpc eval-file "$SEED" diag "$logger" "$now" | sed -n 's/^DIAG=//p')
		echo "  logger $logger: $diag"
		for window in 24 168; do
			for trial in $(seq 1 "$TRIALS"); do
				m=$(wpc eval-file "$MEASURE" "$window" | sed -n 's/^MEASURE=//p')
				[ -n "$m" ] || m='{"ok":false,"error":"no output"}'
				jq -c --arg s "$storage" --arg l "$logger" --argjson w "$window" --argjson t "$trial" --argjson d "${diag:-null}" \
					'{storage:$s, logger:$l, window:$w, trial:$t, diag:$d} + .' <<<"$m" >>"$WORK/trials.jsonl"
				echo "    $storage $logger ${window}h trial $trial: $(jq -r '"\(.elapsed_ms) ms, warm \(.warm_ms) ms, \(.queries) queries, ok \(.ok), coverage \(.coverage)"' <<<"$m")"
			done
		done
		wpc eval-file "$SEED" diag-cleanup >/dev/null
	done
	if [ -n "${BLZMCP_PERF_PORT:-}" ] && [ -n "${BLZMCP_PERF_LOGIN:-}" ] && [ -n "${BLZMCP_PERF_APP_PASSWORD:-}" ]; then
		for window in 24 168; do
			wpc eval-file "$T/matrix/payment-fixture.php" clear-cache >/dev/null
			for kind in cold warm; do
				url="http://localhost:$BLZMCP_PERF_PORT/wp-json/wp-abilities/v1/abilities/blaze-mcp-toolkit/payment-health/run"
				# Read-only abilities run over GET; fall back to POST if this WordPress wants POST.
				ms=$(printf 'user = "%s:%s"\n' "$BLZMCP_PERF_LOGIN" "$BLZMCP_PERF_APP_PASSWORD" | curl -s -K - -o "$WORK/http.json" -w '%{http_code} %{time_total}' -G \
					"$url" --data-urlencode "input[since_hours]=$window")
				method=GET
				if [ "${ms%% *}" == 405 ]; then
					ms=$(printf 'user = "%s:%s"\n' "$BLZMCP_PERF_LOGIN" "$BLZMCP_PERF_APP_PASSWORD" | curl -s -K - -o "$WORK/http.json" -w '%{http_code} %{time_total}' \
						-X POST -H 'Content-Type: application/json' --data-binary "{\"input\":{\"since_hours\":$window}}" "$url")
					method=POST
				fi
				valid=$(jq -r '(type == "object" and has("overall") and has("store_wide"))' "$WORK/http.json" 2>/dev/null || echo false)
				jq -cn --arg s "$storage" --argjson w "$window" --arg k "$kind" --arg r "$ms" --arg m "$method" --arg v "$valid" \
					'{storage:$s, window:$w, kind:$k, method:$m, http_code:($r | split(" ")[0]), valid_body:($v == "true"), total_ms:(($r | split(" ")[1] | tonumber) * 1000 | round)}' >>"$WORK/http.jsonl"
				if [ "${ms%% *}" != 200 ] || [ "$valid" != true ]; then
					echo "FAIL  HTTP $storage ${window}h $kind: http ${ms%% *}, valid body $valid"
					fail=1
				else
					echo "    HTTP $storage ${window}h $kind ($method): $(awk '{printf "%d", $2 * 1000}' <<<"$ms") ms"
				fi
			done
		done
	fi
	wpc eval-file "$SEED" cleanup >/dev/null
done

limits=$(docker info --format '{"ncpu":{{.NCPU}},"mem_total_bytes":{{.MemTotal}},"server_version":"{{.ServerVersion}}"}' 2>/dev/null || echo null)
dist=$(wpc eval-file "$SEED" distribution | sed -n 's/^DISTRIBUTION=//p')
cand_sha=null
[ -n "${BLZMCP_PERF_CANDIDATE:-}" ] && [ -f "$BLZMCP_PERF_CANDIDATE" ] && cand_sha="\"$(shasum -a 256 "$BLZMCP_PERF_CANDIDATE" | awk '{print $1}')\""
[ -f "$WORK/http.jsonl" ] || : >"$WORK/http.jsonl"
jq -n --slurpfile trials "$WORK/trials.jsonl" --slurpfile storage "$WORK/storage.jsonl" --slurpfile http "$WORK/http.jsonl" \
	--argjson limits "${limits:-null}" --argjson dist "${dist:-null}" --argjson cand "$cand_sha" \
	--argjson budget "$BUDGET" --argjson trials_n "$TRIALS" --argjson recent "$RECENT" --argjson old "$OLD" --arg storages "$STORAGES" '
	def key: "\(.storage)/\(.window)h/\(.logger)";
	($storages | split(" ") | map(select(. != ""))) as $st
	| [ $st[] as $s | (24, 168) as $w | ("file", "db") as $l | {storage:$s, window:$w, logger:$l} ] as $want
	| ($want | map(. as $p | ($trials | map(select(.storage == $p.storage and .window == $p.window and .logger == $p.logger))) as $t
		| $p + {
			trials_ms: ($t | map(.elapsed_ms)),
			failed_trials: ($t | map(select(.ok != true)) | length),
			max_ms: ($t | map(.elapsed_ms // 1e9) | max),
			warm_ms_max: ($t | map(.warm_ms // null) | max),
			queries: ($t | map(.queries) | unique),
			peak_memory_delta_bytes_max: ($t | map(.peak_memory_delta_bytes // 0) | max),
			complete: (($t | length) == $trials_n and ($t | map(select(.ok != true)) | length) == 0)
		} | . + {pass: (.complete and .max_ms < $budget)})) as $parts
	| {
		schema: "blzmcp-payment-performance/1",
		measured_at_utc: (now | todate),
		candidate_sha256: $cand,
		budget_ms: $budget,
		meaning: "application-cache cold (toolkit result cache cleared per trial, fresh PHP request); not a claim about cold OS or database buffers",
		payment_health_cold_ms: ($parts | map({key: "\(.storage)/\(.window)h/\(.logger)", value: .max_ms}) | from_entries),
		all_pass: (($parts | length) == ($want | length) and ($parts | all(.pass))),
		partitions: $parts,
		seed: {recent_orders: $recent, older_orders: $old, horizon_days: 28, older_span_days: "30-400", methods: ["stripe", "cod", "cheque"], distribution: $dist, storages: $storage},
		environment: {container_limits: $limits, versions: ($trials | map(.versions) | unique)},
		jobs_store: ($trials | map(.diag.action_store // null) | unique),
		jobs_path: (($trials | map(.diag.action_store // null) | unique) as $j
			| if $j == ["ActionScheduler_DBStore"] then "exact database-store path (one grouped query)"
			  elif ($j | index("ActionScheduler_DBStore")) == null then "sampled path only (\($j | map(tostring) | join(", "))): the exact database-store query was not timed"
			  else "mixed: \($j | map(tostring) | join(", "))" end),
		http: $http,
		http_latency_ms: ($http | map({key: "\(.storage)/\(.window)h/\(.kind)", value: .total_ms}) | from_entries),
		trials: $trials
	}' >"$OUT"
verdict=$(jq -r '.all_pass' "$OUT" 2>/dev/null)
jq -r '.partitions[] | "partition \(.storage) \(.window)h \(.logger): max \(.max_ms) ms over \(.trials_ms | length) trials (\(.trials_ms | map(tostring) | join(", "))), warm max \(.warm_ms_max) ms, pass \(.pass)"' "$OUT"
echo "Receipt: $OUT"
if [ "$fail" -eq 0 ] && [ "$verdict" == true ]; then echo "PERF PASSED"; exit 0; fi
echo "PERF FAILED"
exit 1
