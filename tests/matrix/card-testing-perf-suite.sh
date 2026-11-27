#!/usr/bin/env bash
# Card-testing cold performance budget (1.3 FEATURE-PLAN item 9, contract C10). Runs on the HOST
# against one wp-env (a matrix state's work dir); in-container work goes through `wp-env run cli`.
# Disposable test environments only: it seeds and removes 70,000 card orders per storage. It never
# replaces or changes the payment-health workload (perf-suite.sh) or its Q6 rule.
#
#   WPE=<wp-env> BLZMCP_T=wp-content/blzmcp-tests BLZMCP_CARD_PERF_OUT=<file.json> \
#     BLZMCP_PERF_CANDIDATE=<zip> BLZMCP_PERF_SOURCE_SHA=<40-hex commit> BLZMCP_MATRIX_ATTEMPT=<id> \
#     [BLZMCP_PERF_PORT=.. BLZMCP_PERF_LOGIN=admin BLZMCP_PERF_APP_PASSWORD=..] [BLZMCP_PERF_REQUIRE_DBSTORE=1] \
#     bash tests/matrix/card-testing-perf-suite.sh
#
# For each authoritative storage (legacy, then HPOS; sync off, each populated on its own after the
# other's orders are removed): set the 3 instant methods (stripe, woocommerce_payments,
# square_credit_card) enabled and live and core attribution on (saved first, restored by the
# cleanup), seed 50,000 card orders over the 28-day history (3 real failure bursts, accepted and
# failed outcomes with a usable baseline, attribution and documentation-range IPv4/IPv6 IPs,
# checkout drafts, store-api, missing-origin and other-origin orders, every 100th order with no
# method) plus a 20,000-order tail 30-400 days back (seed-payment-volume.php card-*, CRUD, chunked,
# in parallel processes; seed time is excluded), verify them in the authoritative store with a
# CRUD sample against each order's own spec, then for the file logger and the database logger
# (near-budget diagnostics: 7 of 7 files and 5.04 of 5.24 MB, or 2,001 error rows over the 2,000-row
# cap) and for since_hours 48 and 168 run 5 uncached full executions of the real ability, each in
# a fresh PHP request (measure-card-testing.php trial). After each logger's trials a forced orders
# reader failure and a forced logs reader failure run once each and must come back failed and
# detected. Then HTTP latency cold and warm per window through the abilities REST route (when
# BLZMCP_PERF_PORT, BLZMCP_PERF_LOGIN and BLZMCP_PERF_APP_PASSWORD are set; the secret stays in
# memory and reaches curl as a config on stdin), the Analytics readiness of the workflow (untimed,
# after every trial: the card orders the plugin's Analytics guard counts, created in the 28-day
# history, imported into WooCommerce Analytics synchronously in chunks, seed-payment-volume.php
# card-analytics-import; then the guard asked, with its gap cache cleared, about exactly the
# workflow's period with compare, card-analytics-ready; one analytics_ready record per storage),
# the combined workflow cost (the weekly review's own plan: its first 3 steps in plan order, payment
# health, card testing, then the order totals with subject orders, each called with the plan's
# arguments, cold, reported on its own; a missing, reordered, substituted, unplanned or failed
# step fails it, and it is never measured on a storage whose Analytics is not ready), the fixture
# cleanup (its Analytics rows, import jobs and customers included), then the bound cases on their
# own fixtures: 1,000 and 1,001 distinct method IDs (MAX_METHODS) and 5,000 and
# 5,001 recorded networks among in-window failed orders (MAX_NETWORKS), each with its own
# assertions and never a timing trial.
#
# Budget: one rule for every partition, decided per partition on its own 5 raw trials: every trial
# valid and strictly below 1500 ms ("5of5<1500"). No relaxed rule, no median, no pooling, no
# dropped trial and no retry: every attempt is kept.
#
# Writes BLZMCP_CARD_PERF_OUT (JSON, schema blzmcp-card-performance/1): candidate, source and
# attempt, environment identity, the seed and its per-storage verification, every trial, the
# partitions, HTTP, the Analytics readiness, the workflow cost, the bound cases, the forced
# failures, every cleanup and the storage restore. It then runs card-testing-perf-validate.php on
# the receipt and decides its exit on that verdict too. Prints one "partition <storage>/<window>h/<logger> trials_ms=<a,b,c,d,e>
# max=<n> pass=<true|false>" line per partition and ends with "CARD PERF PASSED" (exit 0) or
# "CARD PERF FAILED" (exit 1). The original order storage is restored and every fixture row removed
# on every exit path, an interrupted run included.
#
# Env (smoke sizes; any receipt below the full size or partitions is marked "smoke": true and the
# validator refuses it for the gate): BLZMCP_CARD_PERF_ORDERS (50000) BLZMCP_CARD_PERF_OLD (20000)
# BLZMCP_CARD_PERF_TRIALS (5) BLZMCP_CARD_PERF_STORAGES ("legacy hpos") BLZMCP_CARD_PERF_WINDOWS
# ("48 168") BLZMCP_CARD_PERF_LOGGERS ("file db") BLZMCP_CARD_PERF_BOUNDS (1; 0 skips the bound
# cases) BLZMCP_CARD_PERF_JOBS (4 parallel seed processes) BLZMCP_CARD_PERF_CHUNK (2500) PHP (php).
# Runs under macOS /bin/bash 3.2: no associative arrays, no mapfile, no ${x,,}, no arrays at all.
set -uo pipefail

WPE=${WPE:-wp-env}
T=${BLZMCP_T:-wp-content/blzmcp-tests}
OUT=${BLZMCP_CARD_PERF_OUT:?BLZMCP_CARD_PERF_OUT}
HERE=$(cd "$(dirname "$0")" && pwd)
PHP_BIN=${PHP:-php}
ORDERS=${BLZMCP_CARD_PERF_ORDERS:-50000}
OLD=${BLZMCP_CARD_PERF_OLD:-20000}
TRIALS=${BLZMCP_CARD_PERF_TRIALS:-5}
STORAGES=${BLZMCP_CARD_PERF_STORAGES:-legacy hpos}
WINDOWS=${BLZMCP_CARD_PERF_WINDOWS:-48 168}
LOGGERS=${BLZMCP_CARD_PERF_LOGGERS:-file db}
BOUNDS=${BLZMCP_CARD_PERF_BOUNDS:-1}
JOBS=${BLZMCP_CARD_PERF_JOBS:-4}
CHUNK=${BLZMCP_CARD_PERF_CHUNK:-2500}
SOURCE_SHA=${BLZMCP_PERF_SOURCE_SHA:-}
ATTEMPT=${BLZMCP_MATRIX_ATTEMPT:-}
EXACT_JOBS=${BLZMCP_PERF_REQUIRE_DBSTORE:-0}
[ "$EXACT_JOBS" == 1 ] || EXACT_JOBS=0
BUDGET=1500
RULE='5of5<1500'
# The measured workflow's period (the weekly review's default; card-testing-perf-trial.php's
# BLZMCP_CARD_PERF_WORKFLOW_PERIOD, which the validator requires) and its first 3 planned steps.
WORKFLOW_PERIOD=last_7_days
WORKFLOW_STEPS='["blaze-mcp-toolkit/payment-health","blaze-mcp-toolkit/card-testing","wc-analytics/totals"]'
SEED="$T/matrix/seed-payment-volume.php"
MEASURE="$T/matrix/measure-card-testing.php"
SWITCH="$T/Integration/test-payment-orders.php"

# Settings are checked before anything changes, so a bad value stops with nothing to restore.
bad=''
for v in "ORDERS=$ORDERS" "OLD=$OLD" "TRIALS=$TRIALS" "JOBS=$JOBS" "CHUNK=$CHUNK" "BOUNDS=$BOUNDS"; do
	case "${v#*=}" in '' | *[!0-9]*) bad="$bad ${v%%=*}" ;; esac
done
for s in $STORAGES; do case "$s" in hpos | legacy) ;; *) bad="$bad STORAGES" ;; esac; done
for w in $WINDOWS; do case "$w" in 48 | 168) ;; *) bad="$bad WINDOWS" ;; esac; done
for l in $LOGGERS; do case "$l" in file | db) ;; *) bad="$bad LOGGERS" ;; esac; done
if [ -n "$bad" ] || [ "${JOBS:-0}" -lt 1 ] 2>/dev/null || [ "${CHUNK:-0}" -lt 1 ] 2>/dev/null; then
	echo "FAIL  invalid settings:${bad:- JOBS or CHUNK}"
	echo "CARD PERF FAILED"
	exit 1
fi
words() { printf '%s\n' $1 | sort | paste -sd' ' -; } # shellcheck disable=SC2086 # Word splitting intended.
smoke=false
smoke_reasons=''
add_smoke() { smoke=true; smoke_reasons="${smoke_reasons:+$smoke_reasons,}$1"; }
[ "$ORDERS" -ge 50000 ] || add_smoke "orders=$ORDERS"
[ "$OLD" -ge 20000 ] || add_smoke "old=$OLD"
[ "$TRIALS" == 5 ] || add_smoke "trials=$TRIALS"
[ "$(words "$STORAGES")" == 'hpos legacy' ] || add_smoke "storages=$STORAGES"
[ "$(words "$WINDOWS")" == '168 48' ] || add_smoke "windows=$WINDOWS"
[ "$(words "$LOGGERS")" == 'db file' ] || add_smoke "loggers=$LOGGERS"
[ "$BOUNDS" == 1 ] || add_smoke 'bounds=0'

WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-card-perf.XXXXXX")
wpc() { "$WPE" run cli wp "$@" 2>/dev/null; }
opt() { wpc option get "$1" | tr -d '\r' | grep -E '^(yes|no)$' | tail -1; }
orig_hpos=$(opt woocommerce_custom_orders_table_enabled)
orig_sync=$(opt woocommerce_custom_orders_table_data_sync_enabled)
[ "$orig_hpos" == yes ] && orig_set=hpos || orig_set=legacy
[ -n "$orig_sync" ] || orig_sync=no
switch_to() { # storage sync
	"$WPE" run cli env BLZMCP_PF_SET="$1" BLZMCP_PF_SYNC="$2" wp eval-file "$SWITCH" 2>&1 | grep -qE '^[0-9]+ passed, 0 failed'
}
final_done=0
on_exit() {
	# Safety net for an interrupted or stopped run; a completed run cleaned up and restored before
	# its receipt. Either way the run ends CARD PERF FAILED: it has no complete receipt.
	if [ "$final_done" != 1 ]; then
		echo "INTERRUPTED  removing the card fixture and restoring order storage to $orig_set sync=$orig_sync"
		wpc eval-file "$SEED" cleanup >/dev/null
		if switch_to "$orig_set" "$orig_sync"; then
			echo "  order storage restored to $orig_set sync=$orig_sync"
		else
			echo "CLEANUP ERROR  order storage not restored to $orig_set sync=$orig_sync"
		fi
		echo "CARD PERF FAILED"
	fi
	rm -rf "$WORK"
}
trap on_exit EXIT
trap 'exit 130' INT TERM

seed_range() { # cmd total now [result key, SEEDED]: the sum of the chunks' <key>= lines
	local cmd=$1 total=$2 now=$3 key=${4:-SEEDED} off=0 running=0 pids='' n
	while [ "$off" -lt "$total" ]; do
		n=$CHUNK
		[ $((off + n)) -gt "$total" ] && n=$((total - off))
		"$WPE" run cli wp eval-file "$SEED" "$cmd" "$off" "$n" "$now" "$total" >"$WORK/seed-$cmd-$off.log" 2>&1 &
		pids="$pids $!"
		running=$((running + 1))
		off=$((off + n))
		if [ "$running" -ge "$JOBS" ]; then
			# Wait for the oldest job (bash 3.2 has no `wait -n`).
			# shellcheck disable=SC2086 # $pids is a list of job ids.
			set -- $pids
			wait "$1"
			shift
			pids="$*"
			running=$((running - 1))
		fi
	done
	wait
	cat "$WORK"/seed-"$cmd"-*.log 2>/dev/null | sed -n "s/^$key=//p" | awk '{s += $1} END {print s + 0}'
	rm -f "$WORK"/seed-"$cmd"-*.log
}
sha256_of() {
	if command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1"; else sha256sum "$1"; fi | awk '{print $1}'
}
json_or() { # <json candidate> <fallback json>: the candidate when it is one JSON object.
	if [ -n "$1" ] && jq -e 'type == "object"' >/dev/null 2>&1 <<<"$1"; then printf '%s\n' "$1"; else printf '%s\n' "$2"; fi
}

for f in trials storage forced cleanup bound http analytics workflow; do : >"$WORK/$f.jsonl"; done
fail=0
# cleanup_step <phase> <storage> <cleanup|diag-cleanup> [expected orders removed]: runs one fixture
# cleanup, keeps its CLEANUP result for the receipt and fails the suite (fail closed) when it is
# missing, leaves tagged fixture orders, diagnostics or settings behind, or removed a different
# number of orders than were seeded.
cleanup_step() {
	local c
	c=$(json_or "$(wpc eval-file "$SEED" "$3" | sed -n 's/^CLEANUP=//p' | tail -1)" '{"ok":false,"error":"no cleanup result"}')
	c=$(jq -c --arg p "$1" --arg s "$2" --arg e "${4:-}" '{phase:$p, storage:$s} + .
		+ (if $e == "" then {} else {expected_removed: ($e | tonumber)} end)
		| .passed = (.ok == true and .settings_restored == true and (if has("expected_removed") then .orders_removed == .expected_removed else true end))' <<<"$c")
	printf '%s\n' "$c" >>"$WORK/cleanup.jsonl"
	if [ "$(jq -r '.passed' <<<"$c")" == true ]; then
		echo "  cleanup $1 ($2): $(jq -r '"orders removed \(.orders_removed // "n/a"), tagged fixture orders remaining \(.tagged_remaining), diagnostics remaining \((.diag_log_rows_remaining // 0) + (.diag_log_files_remaining // 0) + (.diag_jobs_remaining // 0)), settings restored \(.settings_restored)"
			+ (if .mode == "orders" then ", Analytics rows removed \(.analytics_stats_removed)+\(.analytics_lookup_rows_removed) and customers \(.analytics_customers_removed), import jobs removed \(.import_jobs_removed); left: rows \(.analytics_rows_remaining), customers \(.analytics_customers_remaining), import jobs \(.import_jobs_remaining), failed imports \(.failed_imports_remaining)" else "" end)' <<<"$c")"
	else
		echo "FAIL  cleanup $1 ($2) incomplete: $(jq -c 'del(.phase, .storage, .passed)' <<<"$c")"
		fail=1
	fi
}
# measure <window> <logger> [force]: one trial line (JSON), or a failed placeholder.
measure() {
	json_or "$(wpc eval-file "$MEASURE" trial "$1" "$now" "$ORDERS" "$OLD" "$2" ${3:+"$3"} | sed -n 's/^MEASURE=//p' | tail -1)" '{"ok":false,"error":"no output"}'
}
# bound_case <storage> <methods|networks>: the stress fixture at the cap, then one past it.
bound_case() {
	local base k c got extra=0 bnow
	[ "$2" == methods ] && base=1000 || base=5000
	bnow=$(date +%s)
	got=$(seed_range "card-cap-$2" "$base" "$bnow")
	for k in "$base" "$((base + 1))"; do
		if [ "$k" != "$base" ]; then
			extra=$(wpc eval-file "$SEED" "card-cap-$2" "$base" 1 "$bnow" | sed -n 's/^SEEDED=//p' | tail -1)
			extra=${extra:-0}
		fi
		if [ "$((got + extra))" != "$k" ]; then
			c="{\"case\":\"$2\",\"k\":$k,\"ok\":false,\"failures\":[\"seeded $((got + extra)) of $k\"],\"counted_as_trial\":false}"
		else
			c=$(json_or "$(wpc eval-file "$MEASURE" bound "$2" "$k" | sed -n 's/^BOUND=//p' | tail -1)" "{\"case\":\"$2\",\"k\":$k,\"ok\":false,\"failures\":[\"no output\"],\"counted_as_trial\":false}")
		fi
		jq -c --arg s "$1" '{storage:$s} + .' <<<"$c" >>"$WORK/bound.jsonl"
		if [ "$(jq -r '.ok == true and .counted_as_trial == false and (.failures | length) == 0' <<<"$c")" == true ]; then
			echo "  bound $2 $k ($1): $(jq -r '"\(.observed // {} | tojson), \(.elapsed_ms) ms, \(.queries) queries (stress case, not a trial)"' <<<"$c")"
		else
			echo "FAIL  bound $2 $k ($1): $(jq -c '.failures' <<<"$c")"
			fail=1
		fi
	done
	cleanup_step "bound-$2" "$1" cleanup "$((got + extra))"
}
# analytics_ready <storage> <seed now>: the workflow's Analytics precondition, untimed. Imports every
# eligible card order into WooCommerce Analytics (chunked, in parallel), then has the guard probe
# exactly the workflow's period with compare after clearing its gap cache. Records one
# analytics.jsonl line (ok only when the guard is ready and every eligible order was imported) and
# sets ready_ok=1 only then; otherwise a FAIL line, and the workflow is not measured.
ready_ok=0
analytics_ready() {
	local eligible imported t0 secs r
	ready_ok=0
	eligible=$(wpc eval-file "$SEED" card-analytics-eligible "$2" | sed -n 's/^CARD_ANALYTICS_ELIGIBLE=//p' | tail -1)
	case "$eligible" in '' | *[!0-9]*) eligible=0 ;; esac
	t0=$(date +%s)
	imported=0
	[ "$eligible" -gt 0 ] && imported=$(seed_range card-analytics-import "$eligible" "$2" IMPORTED)
	secs=$(($(date +%s) - t0))
	r=$(json_or "$(wpc eval-file "$SEED" card-analytics-ready "$2" "$WORKFLOW_PERIOD" | sed -n 's/^CARD_ANALYTICS_READY=//p' | tail -1)" '{"ok":false,"reason":"no readiness result"}')
	r=$(jq -c --arg s "$1" --argjson i "${imported:-0}" --argjson e "$eligible" --argjson secs "$secs" '{storage:$s} + . + {imported:$i, eligible_listed:$e, import_seconds_excluded:$secs}
		| .ok = (.ok == true and has("reason") and .reason == null and .cache_cleared == true and (.eligible | type) == "number" and .eligible > 0 and .imported == .eligible and .missing == 0 and .failed_imports == 0)' <<<"$r")
	printf '%s\n' "$r" >>"$WORK/analytics.jsonl"
	if [ "$(jq -r '.ok' <<<"$r")" == true ]; then
		ready_ok=1
		echo "  analytics ($1): $(jq -r '"\(.imported) of \(.eligible) eligible orders imported in \(.import_seconds_excluded)s (excluded); guard ready for \(.period) with compare after clearing its gap cache"' <<<"$r")"
	else
		echo "FAIL  analytics not ready ($1): $(jq -c '{reason, period, cache_cleared, eligible, imported, missing, failed_imports}' <<<"$r")"
		fail=1
	fi
}

seed_now0=''
for storage in $STORAGES; do
	cleanup_step before "$storage" cleanup
	if ! switch_to "$storage" no; then
		echo "FAIL  could not switch order storage to $storage (sync off)"
		fail=1
		continue
	fi
	now=$(date +%s)
	[ -n "$seed_now0" ] || seed_now0=$now
	settings=$(json_or "$(wpc eval-file "$SEED" card-settings | sed -n 's/^CARD_SETTINGS=//p' | tail -1)" '{"ok":false}')
	t0=$(date +%s)
	got_orders=$(seed_range card-orders "$ORDERS" "$now")
	got_old=$(seed_range card-old "$OLD" "$now")
	seed_s=$(($(date +%s) - t0))
	verify=$(json_or "$(wpc eval-file "$SEED" card-verify "$ORDERS" "$OLD" "$now" | sed -n 's/^CARD_VERIFY=//p' | tail -1)" '{"ok":false,"error":"no verify result"}')
	echo "storage $storage: seeded $got_orders of $ORDERS recent + $got_old of $OLD older card orders in ${seed_s}s (excluded); settings $(jq -c '.ok' <<<"$settings"); verify $(jq -c '{ok, authority, orders, crud_ok, crud_sample, untagged}' <<<"$verify")"
	jq -cn --arg s "$storage" --argjson v "$verify" --argjson set "$settings" --argjson secs "$seed_s" --argjson r "${got_orders:-0}" --argjson o "${got_old:-0}" --argjson now "$now" \
		'{storage:$s, seed_now:$now, verify:$v, settings:$set, seed_seconds_excluded:$secs, seeded_orders:$r, seeded_old:$o}' >>"$WORK/storage.jsonl"
	if [ "$(jq -r '.ok' <<<"$settings")" != true ]; then
		echo "FAIL  storage $storage: card method and attribution settings not applied"
		fail=1
		cleanup_step after "$storage" cleanup
		continue
	fi
	if [ "${got_orders:-0}" != "$ORDERS" ] || [ "${got_old:-0}" != "$OLD" ]; then
		echo "FAIL  storage $storage: seeded $got_orders of $ORDERS recent and $got_old of $OLD older card orders"
		fail=1
		cleanup_step after "$storage" cleanup
		continue
	fi
	if [ "$(jq -r '.ok == true' <<<"$verify")" != true ] || [ "$(jq -r '.authority' <<<"$verify")" != "$storage" ]; then
		echo "FAIL  storage $storage: card fixture not verified in the authoritative store: $(jq -c '{authority, checks, crud_mismatch, error}' <<<"$verify")"
		fail=1
		cleanup_step after "$storage" cleanup
		continue
	fi
	for logger in $LOGGERS; do
		[ "$EXACT_JOBS" == 1 ] && wpc action-scheduler migrate >/dev/null
		diag=$(json_or "$(wpc eval-file "$SEED" diag "$logger" "$now" | sed -n 's/^DIAG=//p' | tail -1)" '{"error":"no diagnostics result"}')
		echo "  logger $logger: $diag"
		for window in $WINDOWS; do
			trial=0
			while [ "$trial" -lt "$TRIALS" ]; do
				trial=$((trial + 1))
				m=$(measure "$window" "$logger")
				jq -c --arg s "$storage" --arg l "$logger" --argjson w "$window" --argjson t "$trial" --argjson d "$diag" \
					'{storage:$s, window:$w, logger:$l, trial:$t, diag:$d} + .' <<<"$m" >>"$WORK/trials.jsonl"
				echo "    $storage ${window}h $logger trial $trial: $(jq -r '"\(.elapsed_ms) ms, warm \(.warm_ms) ms, \(.queries) queries, rows examined \(.rows_examined) / selected \(.rows_selected), meta rows \(.meta_rows), ok \(.ok), verdict \(.verdict), coverage \(.coverage)"' <<<"$m")"
				if [ "$(jq -r '.ok == true' <<<"$m")" != true ]; then
					echo "FAIL  trial $storage/${window}h/$logger #$trial invalid: $(jq -r '((.validation.failures // []) + [.error // empty]) | .[0:3] | join("; ")' <<<"$m")"
					fail=1
				elif [ "$(jq -r --argjson b "$BUDGET" '(.elapsed_ms | type) == "number" and .elapsed_ms < $b' <<<"$m")" != true ]; then
					echo "FAIL  trial $storage/${window}h/$logger #$trial took $(jq -r '.elapsed_ms' <<<"$m") ms, not strictly below $BUDGET ms"
					fail=1
				fi
			done
		done
		# Forced reader failures: each must come back failed, with that reader flagged.
		for force in orders logs; do
			m=$(measure 48 "$logger" "$force")
			jq -c --arg s "$storage" --arg l "$logger" --arg f "$force" '{storage:$s, logger:$l, force:$f} + del(.slowest, .handler_read, .reader, .expected)' <<<"$m" >>"$WORK/forced.jsonl"
			if [ "$(jq -r '.ok == false and .detected == true' <<<"$m")" == true ]; then
				echo "    forced $force-reader failure ($storage, $logger logger): trial failed and detected"
			else
				echo "FAIL  forced $force-reader failure ($storage, $logger logger) was not detected: ok $(jq -r '.ok' <<<"$m"), detected $(jq -r '.detected' <<<"$m")"
				fail=1
			fi
		done
		cleanup_step "diag-$logger" "$storage" diag-cleanup
	done
	if [ -n "${BLZMCP_PERF_PORT:-}" ] && [ -n "${BLZMCP_PERF_LOGIN:-}" ] && [ -n "${BLZMCP_PERF_APP_PASSWORD:-}" ]; then
		# Through the MCP Adapter route (the recommended state's transport) with an integer
		# since_hours. The WordPress abilities REST route cannot carry it: read-only abilities run only
		# over GET there, a query value is a string, and card testing rejects a string look-back by
		# contract (C1), so only the default 48 could be asked that way (wave 3 pre-review smoke).
		url="http://localhost:$BLZMCP_PERF_PORT/wp-json/mcp/mcp-adapter-default-server"
		mcp_post() { # session-id body -> "<http code> <seconds>"; reply in $WORK/http.raw, headers in $WORK/http.headers
			printf 'user = "%s:%s"\n' "$BLZMCP_PERF_LOGIN" "$BLZMCP_PERF_APP_PASSWORD" | curl -s -K - -o "$WORK/http.raw" -D "$WORK/http.headers" -w '%{http_code} %{time_total}' \
				-X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-06-18' \
				${1:+-H "Mcp-Session-Id: $1"} --data-binary "$2" "$url"
		}
		for window in $WINDOWS; do
			wpc eval-file "$T/matrix/payment-fixture.php" clear-cache >/dev/null
			for kind in cold warm; do
				# One session per figure (initialize is not timed), then the timed tools/call.
				: >"$WORK/http.headers"
				mcp_post '' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"blzmcp-card-perf","version":"1.3"}}}' >/dev/null
				sid=$(grep -i '^mcp-session-id:' "$WORK/http.headers" 2>/dev/null | head -1 | awk '{print $2}' | tr -d '\r')
				[ -n "$sid" ] && mcp_post "$sid" '{"jsonrpc":"2.0","method":"notifications/initialized"}' >/dev/null
				ms=$(mcp_post "$sid" "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"blaze-mcp-toolkit/card-testing\",\"parameters\":{\"since_hours\":$window}}}}")
				method=MCP
				# A text/event-stream reply carries the JSON-RPC message on its data: lines.
				if head -c 6 "$WORK/http.raw" 2>/dev/null | grep -q '^event:\|^data:'; then sed -n 's/^data: \{0,1\}//p' "$WORK/http.raw" >"$WORK/http.rpc"; else cp "$WORK/http.raw" "$WORK/http.rpc" 2>/dev/null; fi
				jq -c 'if (type == "object" and .jsonrpc == "2.0" and .id == 2 and (.result | type) == "object" and (.result.isError // false) == false) then (.result.structuredContent // ((.result.content // [])[0].text // "null" | fromjson? // null)) | if (type == "object" and has("success")) then (if .success == true then .data else null end) else . end else null end' "$WORK/http.rpc" >"$WORK/http.json" 2>/dev/null || echo null >"$WORK/http.json"
				valid=$(jq -r --argjson w "$window" '(type == "object" and .schema_version == 1 and .since_hours == $w and (.verdict | type) == "string" and (.totals | type) == "object" and ((.protection // []) | length) == 5)' "$WORK/http.json" 2>/dev/null || echo false)
				jq -cn --arg s "$storage" --argjson w "$window" --arg k "$kind" --arg r "${ms:-000 0}" --arg m "$method" --arg v "$valid" \
					'{storage:$s, window:$w, kind:$k, method:$m, http_code:($r | split(" ")[0]), valid_body:($v == "true"), total_ms:((($r | split(" ")[1]) // "0" | tonumber) * 1000 | round)}' >>"$WORK/http.jsonl"
				if [ "${ms%% *}" != 200 ] || [ "$valid" != true ]; then
					echo "FAIL  HTTP $storage ${window}h $kind: http ${ms%% *}, valid body $valid"
					fail=1
				else
					echo "    HTTP $storage ${window}h $kind ($method): $(awk '{printf "%d", $2 * 1000}' <<<"$ms") ms (reported apart from the trials)"
				fi
			done
		done
	else
		echo "  HTTP not measured: BLZMCP_PERF_PORT, BLZMCP_PERF_LOGIN and BLZMCP_PERF_APP_PASSWORD are not all set (the gate requires it)"
	fi
	# The workflow's Analytics precondition (after every trial and the HTTP figures, untimed), then
	# the combined workflow cost, which is never measured on a store whose Analytics is not ready.
	analytics_ready "$storage" "$now"
	if [ "$ready_ok" == 1 ]; then
		w=$(json_or "$(wpc eval-file "$MEASURE" workflow "$WORKFLOW_PERIOD" | sed -n 's/^WORKFLOW=//p' | tail -1)" '{"ok":false,"error":"no output"}')
	else
		w='{"ok":false,"error":"analytics_not_ready","steps":[],"pooled_budget":false}'
	fi
	jq -c --arg s "$storage" '{storage:$s} + .' <<<"$w" >>"$WORK/workflow.jsonl"
	# The measurement's own ok, and the suite's check of the step records: the plan's first 3
	# steps, in plan order, each planned, called with the plan's arguments and successful.
	if [ "$(jq -r --argjson want "$WORKFLOW_STEPS" '.ok == true and ((.steps // []) | length) == 3 and ([.steps[].ability] == $want)
		and (.steps[2].arguments.subject == "orders") and (.steps | all(.planned == true and .success == true and .error_code == null and .arguments == .plan_arguments and (.elapsed_ms | type) == "number"))' <<<"$w")" == true ]; then
		echo "  workflow cost ($storage, cold, reported on its own): $(jq -r '"\([.steps[] | "\(.ability) \(.elapsed_ms) ms"] | join(", ")); total \(.total_ms) ms"' <<<"$w")"
	else
		echo "FAIL  workflow cost ($storage): $(jq -c '{error, failures, steps: [(.steps // [])[] | {ability, planned, success, error_code}]}' <<<"$w")"
		fail=1
	fi
	cleanup_step after "$storage" cleanup "$((got_orders + got_old))"
	if [ "$BOUNDS" == 1 ]; then
		bound_case "$storage" methods
		bound_case "$storage" networks
	fi
done

# Final cleanup and the original order storage restored, both before the receipt so it records them.
cleanup_step final "$orig_set" cleanup
if switch_to "$orig_set" "$orig_sync"; then
	restored=true
	echo "  order storage restored to $orig_set sync=$orig_sync"
else
	restored=false
	echo "FAIL  order storage not restored to $orig_set sync=$orig_sync"
	fail=1
fi
final_done=1
restore_json=$(jq -cn --arg s "$orig_set" --arg y "$orig_sync" --argjson ok "$restored" '{storage:$s, sync:$y, ok:$ok}')

limits=$(json_or "$(docker info --format '{"ncpu":{{.NCPU}},"mem_total_bytes":{{.MemTotal}},"server_version":"{{.ServerVersion}}"}' 2>/dev/null)" null)
host_cpus=$(sysctl -n hw.ncpu 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null || echo 0)
case "$host_cpus" in '' | *[!0-9]*) host_cpus=0 ;; esac
host_json=$(jq -cn --arg n "$(hostname 2>/dev/null || uname -n)" --arg o "$(uname -srm)" --argjson c "$host_cpus" '{name:$n, os:$o, cpus:$c}')
dist=$(json_or "$(wpc eval-file "$SEED" card-distribution "$ORDERS" "$OLD" "${seed_now0:-0}" | sed -n 's/^CARD_DISTRIBUTION=//p' | tail -1)" null)
cand_sha=null
[ -n "${BLZMCP_PERF_CANDIDATE:-}" ] && [ -f "$BLZMCP_PERF_CANDIDATE" ] && cand_sha="\"$(sha256_of "$BLZMCP_PERF_CANDIDATE")\""
jq -n --slurpfile trials "$WORK/trials.jsonl" --slurpfile storage "$WORK/storage.jsonl" --slurpfile http "$WORK/http.jsonl" \
	--slurpfile forced "$WORK/forced.jsonl" --slurpfile bound "$WORK/bound.jsonl" --slurpfile cleanup "$WORK/cleanup.jsonl" \
	--slurpfile workflow "$WORK/workflow.jsonl" --slurpfile analytics "$WORK/analytics.jsonl" --arg wperiod "$WORKFLOW_PERIOD" \
	--argjson restore "$restore_json" --argjson limits "$limits" --argjson host "$host_json" \
	--argjson dist "$dist" --argjson cand "$cand_sha" --arg source "$SOURCE_SHA" --arg attempt "$ATTEMPT" \
	--argjson budget "$BUDGET" --arg rule "$RULE" --argjson trials_n "$TRIALS" --argjson orders "$ORDERS" --argjson old "$OLD" \
	--arg storages "$STORAGES" --arg windows "$WINDOWS" --arg loggers "$LOGGERS" --argjson smoke "$smoke" --arg smoke_reasons "$smoke_reasons" \
	--argjson bounds "$BOUNDS" --argjson exact_jobs "$EXACT_JOBS" '
	($storages | split(" ") | map(select(. != ""))) as $st
	| ($windows | split(" ") | map(select(. != "") | tonumber)) as $ws
	| ($loggers | split(" ") | map(select(. != ""))) as $ls
	| [ $st[] as $s | $ws[] as $w | $ls[] as $l | {storage:$s, window:$w, logger:$l} ] as $want
	| ($want | map(. as $p | ($trials | map(select(.storage == $p.storage and .window == $p.window and .logger == $p.logger)) | sort_by(.trial)) as $t
		| $p + {
			trials_ms: ($t | map(.elapsed_ms)),
			max_ms: ($t | map(.elapsed_ms // 1e9) | max),
			valid_trials: ($t | map(select(.ok == true and ((.validation.readers // {}) | length == 6 and all(.[]; . == true)) and ((.validation.failures // ["none"]) | length) == 0)) | length),
			trials_under_budget: ($t | map(select(.ok == true and (.elapsed_ms | type) == "number" and .elapsed_ms < $budget)) | length),
			trial_numbers_ok: (($t | map(.trial) | sort) == [range(1; 6)]),
			warm_ms_max: ($t | map(.warm_ms // null) | max),
			queries: ($t | map(.queries) | unique),
			rows_examined_max: ($t | map(.rows_examined // null) | max),
			rows_selected_max: ($t | map(.rows_selected // null) | max),
			meta_rows_max: ($t | map(.meta_rows // null) | max),
			peak_memory_delta_bytes_max: ($t | map(.peak_memory_delta_bytes // 0) | max),
			rule: $rule
		} | . + {pass: (($t | length) == 5 and .trial_numbers_ok and .valid_trials == 5 and .trials_under_budget == 5 and .max_ms < $budget)})) as $parts
	| [ $st[] as $s | $ls[] as $l | ("orders", "logs") as $f | {storage:$s, logger:$l, force:$f} ] as $want_forced
	| ($want_forced | map(. as $p | ($forced | map(select(.storage == $p.storage and .logger == $p.logger and .force == $p.force))) as $f
		| $p + {probes: ($f | length), detected: (($f | length) == 1 and ($f | all(.ok == false and .detected == true)))})) as $forced_check
	| (if $bounds == 1 then [ $st[] as $s | ({case:"methods", k:1000}, {case:"methods", k:1001}, {case:"networks", k:5000}, {case:"networks", k:5001}) | . + {storage:$s} ] else [] end) as $want_bound
	| ($want_bound | map(. as $p | ($bound | map(select(.storage == $p.storage and .case == $p.case and .k == $p.k))) as $b
		| $p + {runs: ($b | length), ok: (($b | length) == 1 and ($b | all(.ok == true and .counted_as_trial == false)))})) as $bound_check
	| ([ $st[] as $s | (["before"] + ($ls | map("diag-" + .)) + ["after"] + (if $bounds == 1 then ["bound-methods", "bound-networks"] else [] end))[] as $ph | {storage:$s, phase:$ph} ] + [{phase:"final"}]) as $want_cleanup
	| ($want_cleanup | map(. as $p | ($cleanup | map(select(.phase == $p.phase and ($p.phase == "final" or .storage == $p.storage)))) as $c
		| $p + {runs: ($c | length), passed: (($c | length) >= 1 and ($c | all(.passed == true)))})) as $cleanup_check
	| (($cleanup_check | all(.passed)) and ($cleanup | all(.passed == true)) and $restore.ok == true) as $cleanup_ok
	| {
		schema: "blzmcp-card-performance/1",
		measured_at_utc: (now | todate),
		candidate_sha256: $cand,
		source_sha: (if $source == "" then null else $source end),
		attempt: (if $attempt == "" then null else $attempt end),
		smoke: $smoke,
		smoke_reasons: ($smoke_reasons | split(",") | map(select(. != ""))),
		budget_ms: $budget,
		rule: $rule,
		rule_meaning: "every partition: all 5 raw trials valid and strictly below \($budget) ms, decided per partition on its own trials; no relaxed rule, no median, no pooling, no dropped trial, no retry",
		meaning: "application-cache cold (toolkit result cache cleared per trial, fresh PHP request, the full ability execute); not a claim about cold OS or database buffers",
		validity: "a trial counts only with the seeded workload observed and every reader (response, workload, orders, methods, signals, logs) at its one expected state (card-testing-perf-trial.php); the near-budget log states are validated explicitly, never accepted as a generic reader failure",
		environment: {host: $host, container_limits: $limits, versions: ($trials | map(.versions) | map(select(. != null)) | unique)},
		seed: {orders: $orders, old_orders: $old, horizon_days: 28, older_span_days: "30-400",
			methods: ["stripe", "woocommerce_payments", "square_credit_card"],
			drafts: ($dist.drafts // null), missing_origin: ($dist.missing_origin // null), store_api_origin: ($dist.store_api_origin // null),
			other_origin: ($dist.other_origin // null), bursts: ($dist.bursts // null), distribution: $dist,
			ip_ranges: ["192.0.2.0/24", "198.51.100.0/24", "203.0.113.0/24", "2001:db8::/32"], storages: $storage},
		exact_jobs_required: ($exact_jobs == 1),
		trials: $trials,
		partitions: $parts,
		card_testing_cold_ms: ($parts | map({key: "\(.storage)/\(.window)h/\(.logger)", value: .max_ms}) | from_entries),
		http: $http,
		http_latency_ms: ($http | map({key: "\(.storage)/\(.window)h/\(.kind)", value: .total_ms}) | from_entries),
		workflow_period: $wperiod,
		analytics_ready: $analytics,
		workflow_cost: $workflow,
		bound_cases: $bound,
		bound_checks: $bound_check,
		forced_failures: $forced,
		forced_failure_checks: $forced_check,
		forced_failures_detected: ($forced_check | all(.detected)),
		cleanup: $cleanup,
		cleanup_checks: $cleanup_check,
		cleanup_ok: $cleanup_ok,
		storage_restored: $restore,
		all_pass: ((($parts | length) == ($want | length)) and ($parts | all(.pass)) and ($forced_check | all(.detected)) and ($bound_check | all(.ok)) and $cleanup_ok and ($smoke | not)
			and ($analytics | length) == ($st | length) and ($analytics | all(.ok == true)) and ($workflow | length) == ($st | length) and ($workflow | all(.ok == true)))
	}' >"$OUT" || { echo "FAIL  the receipt could not be written"; fail=1; }
jq -r '.partitions[] | "partition \(.storage)/\(.window)h/\(.logger) trials_ms=\(.trials_ms | map(tostring) | join(",")) max=\(.max_ms // "none") pass=\(.pass)"' "$OUT" 2>/dev/null
jq -r '.forced_failure_checks[] | "forced \(.force)-reader failure \(.storage) \(.logger): probes \(.probes), detected \(.detected)"' "$OUT" 2>/dev/null
jq -r '.bound_checks[] | "bound \(.case) \(.k) \(.storage): runs \(.runs), ok \(.ok)"' "$OUT" 2>/dev/null
jq -r '.analytics_ready[] | "analytics \(.storage): ready \(.ok), reason \(.reason // "none"), \(.period) with compare, gap cache cleared \(.cache_cleared), imported \(.imported) of \(.eligible)"' "$OUT" 2>/dev/null
jq -r '.workflow_cost[] | "workflow \(.storage): ok \(.ok), steps \([(.steps // [])[] | "\(.ability)\(if .success == true then "" else " FAILED" end)"] | join(", ")), total \(.total_ms // "none") ms"' "$OUT" 2>/dev/null
echo "cleanup: $(jq -r '"\(if .cleanup_ok then "complete" else "INCOMPLETE" end): \([.cleanup[] | select(.phase == "after") | "\(.storage) removed \(.orders_removed)/\(.expected_removed // "n/a"), \(.tagged_remaining) tagged left"] | join("; ")); order storage restored to \(.storage_restored.storage) sync=\(.storage_restored.sync): \(.storage_restored.ok)"' "$OUT" 2>/dev/null)"
[ "$smoke" == true ] && echo "SMOKE  this receipt is a smoke run ($smoke_reasons): it can never pass the gate"
# The gate's own validator decides too (fail closed on its refusal).
"$PHP_BIN" "$HERE/card-testing-perf-validate.php" "$OUT" --candidate-sha256="$(jq -r '.candidate_sha256 // "none"' "$OUT" 2>/dev/null || echo none)" \
	--source-sha="${SOURCE_SHA:-none}" --attempt="${ATTEMPT:-none}" >"$WORK/validate.out" 2>&1
vcode=$?
vline=$(tail -1 "$WORK/validate.out")
echo "$vline"
if [ -f "$OUT" ] && jq -e 'type == "object"' "$OUT" >/dev/null 2>&1; then
	jq --arg line "$vline" --argjson code "$vcode" '.validator = {exit: $code, line: $line} | .all_pass = (.all_pass and $code == 0)' "$OUT" >"$WORK/receipt.json" && cat "$WORK/receipt.json" >"$OUT"
fi
echo "Receipt: $OUT"
if [ "$fail" -eq 0 ] && [ "$vcode" -eq 0 ]; then echo "CARD PERF PASSED"; exit 0; fi
echo "CARD PERF FAILED"
exit 1
