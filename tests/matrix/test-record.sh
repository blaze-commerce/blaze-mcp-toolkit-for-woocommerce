#!/usr/bin/env bash
# Self-test for tests/matrix/record.sh's zip inventory and byte-identity check. Builds small zips
# from the files at HEAD (no wp-env, no network) and checks that record.sh accepts a faithful one
# and refuses each broken one with exit 3. Writes only to a temporary directory.
#
#   bash tests/matrix/test-record.sh
#
# record.sh runs under the same bash as this script ($BASH), so `/bin/bash tests/matrix/test-record.sh`
# on macOS checks it under bash 3.2, the shell swamp's `bash -lc` gate gets.
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
PKG=blaze-mcp-toolkit-for-woocommerce
COMMIT=$(git -C "$REPO" rev-parse HEAD)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-record.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
pass=0
fail=0
OUT_SEQ=0

# A faithful package at HEAD: tracked src/ and top-level files, vendor-src mapped into vendor/.
stage_good() {
	local dir=$1
	rm -rf "$dir"
	mkdir -p "$dir/$PKG"
	git -C "$REPO" ls-tree -r --name-only "$COMMIT" | while IFS= read -r f; do
		local dest=''
		case "$f" in
		src/* | "$PKG.php" | uninstall.php | readme.txt | composer.json | LICENSE.txt) dest=$f ;;
		vendor-src/commerce-abilities/*/UPSTREAM | vendor-src/commerce-abilities/UPSTREAM | vendor-src/commerce-abilities/composer.lock | vendor-src/commerce-abilities/package.json) ;;
		vendor-src/commerce-abilities/*) dest="vendor/woocommerce/commerce-abilities/${f#vendor-src/commerce-abilities/}" ;;
		esac
		[ -n "$dest" ] || continue
		mkdir -p "$dir/$PKG/$(dirname "$dest")"
		git -C "$REPO" show "$COMMIT:$f" >"$dir/$PKG/$dest"
	done
	mkdir -p "$dir/$PKG/vendor/composer"
	echo '<?php // composer' >"$dir/$PKG/vendor/autoload.php"
	echo '<?php // composer' >"$dir/$PKG/vendor/composer/autoload_real.php"
}

HEAD_VERSION=$(git -C "$REPO" show "$COMMIT:$PKG.php" | sed -n 's/^ \* Version:[[:space:]]*//p' | head -1 | tr -d '\r')

# A synthetic performance receipt for the zip with SHA-256 $1; $2 = max ms of every partition.
perf_receipt() {
	jq -n --arg sha "$1" --argjson ms "$2" '
		[("legacy", "hpos") as $s | (24, 168) as $w | ("file", "db") as $l
			| {storage:$s, window:$w, logger:$l, trials_ms:[$ms, $ms, $ms, $ms, $ms], max_ms:$ms, warm_ms_max:1, queries:[130], rows_examined_max:61000, rows_selected_max:2100, peak_memory_delta_bytes_max:4000000, complete:true, pass:($ms < 1500)}] as $p
		| [("legacy", "hpos") as $s | (24, 168) as $w | ("cold", "warm") as $k
			| {storage:$s, window:$w, kind:$k, method:"GET", http_code:"200", valid_body:true, total_ms:(if $k == "cold" then $ms + 40 else 30 end)}] as $h
		| [("legacy", "hpos") as $s | (24, 168) as $w | ("file", "db") as $l | (1, 2, 3, 4, 5) as $t
			| {storage:$s, window:$w, logger:$l, trial:$t, ok:true, elapsed_ms:$ms, warm_ms:1, queries:130, rows_examined:61000, rows_selected:2100,
			   peak_memory_delta_bytes:4000000, slowest:[{ms:40, sql:"SELECT ?", plan:[{table:"o", type:"range", key:"k", rows:61000, extra:null}]}],
			   action_store:"ActionScheduler_DBStore",
			   validation:{readers:{orders:true, inventory:true, logs:true, jobs:true, outside:true}, failures:[]}}] as $tr
		| [("legacy", "hpos") as $s | ({l:"file", f:"logs"}, {l:"db", f:"logs"}, {l:"db", f:"orders"}, {l:"db", f:"jobs"}) as $p
			| {storage:$s, logger:$p.l, force:$p.f, ok:false, forced:$p.f, detected:true}] as $f
		| ([("legacy", "hpos") as $s | ("before", "diag-file", "diag-db", "after", "cap") as $ph
			| {phase:$ph, storage:$s, mode:(if ($ph | startswith("diag")) then "diagnostics" else "orders" end),
			   orders_removed:(if $ph == "after" then 61000 elif $ph == "cap" then 1025 elif ($ph | startswith("diag")) then null else 0 end),
			   tagged_remaining:0, diag_log_rows_remaining:0, diag_log_files_remaining:0, diag_jobs_remaining:0, settings_restored:true, ok:true}
			| if .phase == "after" then . + {expected_removed:61000} elif .phase == "cap" then . + {expected_removed:1025} else . end | . + {passed:true}]
		  + [{phase:"final", storage:"legacy", mode:"orders", orders_removed:0, tagged_remaining:0, diag_log_rows_remaining:0, diag_log_files_remaining:0, diag_jobs_remaining:0, settings_restored:true, ok:true, passed:true}]) as $c
		| [("legacy", "hpos") as $s | (1000, 1001) as $k
			| {storage:$s, distinct_ids:$k, ok:true, failures:[], listed:(if $k > 1000 then 1000 else $k end), methods_truncated:($k > 1000), inventory_coverage:"partial", elapsed_ms:300}] as $cap
		| {schema:"blzmcp-payment-performance/2", candidate_sha256:$sha, budget_ms:1500, meaning:"application-cache cold",
		   validity:"a trial counts only with the seeded workload observed and every reader at its expected counts",
		   seed:{recent_orders:50000, horizon_days:28, older_orders:10000, outside_orders:1000, methods:["stripe", "cod", "cheque"],
		     outside_methods:["bacs", "blzmcp_ext_admin", "blzmcp_ext_import", "blzmcp_ext_none", "blzmcp_ext_phone", "blzmcp_ext_pos", "blzmcp_ext_rest", "blzmcp_ext_sub", "cod", "stripe"],
		     storages:[("legacy", "hpos") as $s | {storage:$s, seeded_recent:50000, seeded_old:10000, seeded_outside:1000, verify:{ok:true, authority:$s, checkout:60000, outside:1000}}]},
		   cap_ok:true, cap_cases:$cap,
		   exact_jobs_required:true, jobs_store:["ActionScheduler_DBStore"], jobs_path:"exact database-store path (one grouped query)",
		   all_pass:($p | all(.pass)), partitions:$p, http:$h, trials:$tr, forced_failures:$f,
		   cleanup_ok:true, cleanup:$c, storage_restored:{storage:"legacy", sync:"no", ok:true}}'
}

# A trial that failed reader $1 (orders|logs|jobs|inventory) while its partition still claims complete.
reader_failed() {
	jq --arg r "$1" '(.trials[] | select(.storage == "hpos" and .window == 24 and .logger == "db" and .trial == 3))
		|= (.ok = false | .validation.readers[$r] = false | .validation.failures = ["\($r): forced"])'
}
# A forced probe of reader $1 that came back as a success (not detected).
forced_passed() {
	jq --arg r "$1" '(.forced_failures[] | select(.storage == "legacy" and .force == $r and .logger == "db")) |= (.ok = true | .detected = false)'
}

# The HTTP suite's transport receipt rows for one state (TRANSPORT= lines), with mutations:
# transport-missing (drops legacy anonymous denial in easy-2.3.1), transport-dup (repeats a both
# row), transport-failed (one both row FAILED), transport-unknown (an extra case), transport-none
# (no rows at all for recommended).
transport_rows() {
	local state=$1 mut=$2 routes r a c
	case "$state" in recommended) routes=rec ;; easy-*) routes=easy ;; *) routes='rec easy' ;; esac
	[ "$mut" == transport-none ] && [ "$state" == recommended ] && return 0
	for r in $routes; do
		if [ "$state" == none ]; then
			echo "TRANSPORT=$state|$r|any|admin|no_tool|PASSED"
			continue
		fi
		for c in 'admin|discovery' 'admin|workflows' 'admin|analytics_off' 'admin|tool_off' 'manager|roles'; do echo "TRANSPORT=$state|$r|any|$c|PASSED"; done
		for a in hpos legacy; do
			for c in 'admin|disclosure' 'admin|diag_off' 'admin|diag_on' 'admin|diag_off_again' 'manager|diag_off' 'manager|diag_on' 'manager|diag_off_again' 'customer|denied' 'subscriber|denied' 'anonymous|denied' 'manager|warm_downgrade'; do
				[ "$mut" == transport-missing ] && [ "$state" == easy-2.3.1 ] && [ "$a" == legacy ] && [ "$c" == 'anonymous|denied' ] && continue
				if [ "$mut" == transport-failed ] && [ "$state" == both ] && [ "$r" == easy ] && [ "$a" == hpos ] && [ "$c" == 'manager|warm_downgrade' ]; then
					echo "TRANSPORT=$state|$r|$a|$c|FAILED"
					continue
				fi
				echo "TRANSPORT=$state|$r|$a|$c|PASSED"
				[ "$mut" == transport-dup ] && [ "$state" == both ] && [ "$r" == rec ] && [ "$a" == legacy ] && [ "$c" == 'admin|disclosure' ] && echo "TRANSPORT=$state|$r|$a|$c|PASSED"
			done
		done
		[ "$mut" == transport-unknown ] && [ "$state" == both ] && echo "TRANSPORT=$state|$r|hpos|admin|extra_case|PASSED"
	done
	return 0
}

# check <label> <expected exit> <stage dir> [mutation]
# Mutations: bad-sha, stale-version, no-storage, failed-storage, no-perf, perf-unbound, perf-over,
# perf-short, state-not-run, log-leak, log-path.
check() {
	# Each check gets its own record name from a counter. It was out-$RANDOM.md: refused records
	# (exit 3) are still written, so a repeated $RANDOM made record.sh refuse an existing file
	# (exit 4) instead (seen once in CI "integration (min)" on ab5ad0a).
	OUT_SEQ=$((OUT_SEQ + 1))
	local label=$1 want=$2 dir=$3 mut=${4:-} zip="$WORK/candidate-test.zip" out="$WORK/out-$OUT_SEQ.md" sha version state combo suite
	if [ -e "$out" ]; then
		echo "FAIL  $label (test harness: $out already exists)"
		fail=$((fail + 1))
		return
	fi
	rm -f "$zip" "$WORK"/*.log
	(cd "$dir" && zip -qr "$zip" .)
	sha=$(shasum -a 256 "$zip" | awk '{print $1}')
	version=$HEAD_VERSION
	[ "$mut" == bad-sha ] && sha=0000000000000000000000000000000000000000000000000000000000000000
	[ "$mut" == stale-version ] && version=1.0.0
	{
		printf 'ZIP=%s\nZIP_SHA256=%s\nVERSION=%s\nCOMMIT=%s\nWINDOW_FILE=\nPERF_FILE=%s\n' "$zip" "$sha" "$version" "$COMMIT" "$([ "$mut" == no-perf ] || echo "$WORK/perf.json")"
		case "$mut" in
		http-checks-missing) ;;
		http-checks-failed) echo 'HTTP_CHECKS=68 passed, 3 failed' ;;
		*) echo 'HTTP_CHECKS=71 passed, 0 failed' ;;
		esac
		for state in recommended easy-2.3.1 easy-2.4.0 easy-2.5.0 both none; do
			[ "$mut" == drop-easy-2.5.0 ] && [ "$state" == easy-2.5.0 ] && continue
			if [ "$mut" == state-not-run ] && [ "$state" == both ]; then
				echo "STATE=$state|NOT RUN|0|1|||[]"
				continue
			fi
			echo "STATE=$state|PASSED|10|0|6.9|8.3|[]"
			[ "$mut" == state-dup ] && [ "$state" == easy-2.4.0 ] && echo "STATE=$state|PASSED|10|0|6.9|8.3|[]"
			transport_rows "$state" "$mut"
			[ "$state" == none ] && continue
			for combo in hpos:no hpos:yes legacy:yes legacy:no; do
				for suite in test-payment-orders.php test-payment-health.php; do
					[ "$mut" == no-storage ] && [ "$combo" == legacy:yes ] && continue
					[ "$mut" == storage-dup ] && [ "$state" == both ] && [ "$combo" == legacy:no ] && echo "STORAGE=$state|$combo|$suite|PASSED|10|0"
					if [ "$mut" == failed-storage ] && [ "$combo" == hpos:yes ]; then
						echo "STORAGE=$state|$combo|$suite|FAILED|9|1"
					else
						echo "STORAGE=$state|$combo|$suite|PASSED|10|0"
					fi
				done
			done
		done
	} >"$WORK/summary.txt"
	case "$mut" in
	perf-unbound) perf_receipt "ffff$(shasum -a 256 "$zip" | awk '{print $1}' | cut -c5-)" 900 >"$WORK/perf.json" ;;
	perf-over) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 1500 >"$WORK/perf.json" ;;
	perf-short) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.partitions[0].trials_ms = [900, 900]' >"$WORK/perf.json" ;;
	perf-no-http) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq 'del(.http)' >"$WORK/perf.json" ;;
	perf-http-error) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.http[] | select(.storage == "hpos" and .window == 168 and .kind == "cold")) |= (.http_code = "403" | .valid_body = false)' >"$WORK/perf.json" ;;
	perf-reader-orders | perf-reader-logs | perf-reader-jobs | perf-reader-inventory)
		perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | reader_failed "${mut#perf-reader-}" >"$WORK/perf.json" ;;
	perf-no-validation) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.trials[] | select(.storage == "legacy" and .window == 168 and .logger == "file" and .trial == 1)) |= del(.validation)' >"$WORK/perf.json" ;;
	perf-no-trials) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq 'del(.trials)' >"$WORK/perf.json" ;;
	perf-forced-missing) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq 'del(.forced_failures)' >"$WORK/perf.json" ;;
	perf-forced-orders | perf-forced-logs | perf-forced-jobs)
		perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | forced_passed "${mut#perf-forced-}" >"$WORK/perf.json" ;;
	perf-hybrid-jobs) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.jobs_store = ["ActionScheduler_HybridStore"] | .jobs_path = "sampled path only"' >"$WORK/perf.json" ;;
	perf-mixed-jobs) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.jobs_store = ["ActionScheduler_DBStore", "ActionScheduler_HybridStore"]' >"$WORK/perf.json" ;;
	cleanup-missing) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq 'del(.cleanup)' >"$WORK/perf.json" ;;
	cleanup-no-after) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.cleanup |= map(select(.storage != "hpos" or .phase != "after"))' >"$WORK/perf.json" ;;
	cleanup-no-final) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.cleanup |= map(select(.phase != "final"))' >"$WORK/perf.json" ;;
	cleanup-leftover) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.cleanup[] | select(.storage == "legacy" and .phase == "after")) |= (.tagged_remaining = 3 | .orders_removed = 59997)' >"$WORK/perf.json" ;;
	cleanup-short) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.cleanup[] | select(.storage == "hpos" and .phase == "after")) |= (.orders_removed = 59999)' >"$WORK/perf.json" ;;
	cleanup-diag) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.cleanup[] | select(.storage == "hpos" and .phase == "diag-db")) |= (.diag_jobs_remaining = 2 | .ok = false)' >"$WORK/perf.json" ;;
	cleanup-restore) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.storage_restored.ok = false' >"$WORK/perf.json" ;;
	perf-jobs-not-required) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.exact_jobs_required = false' >"$WORK/perf.json" ;;
	perf-schema-1) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.schema = "blzmcp-payment-performance/1"' >"$WORK/perf.json" ;;
	perf-no-outside) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.seed.outside_orders = 0' >"$WORK/perf.json" ;;
	perf-few-outside-methods) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.seed.outside_methods = ["bacs", "cod"]' >"$WORK/perf.json" ;;
	perf-replaced-volume) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.seed.recent_orders = 49000' >"$WORK/perf.json" ;;
	perf-verify-outside) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.seed.storages[] | select(.storage == "hpos") | .verify.outside) = 999' >"$WORK/perf.json" ;;
	perf-reader-outside) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | reader_failed outside >"$WORK/perf.json" ;;
	perf-reader-keys) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.trials[] | select(.trial == 2)) |= (.validation.readers |= del(.outside))' >"$WORK/perf.json" ;;
	perf-no-rows) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.trials[] | select(.storage == "legacy" and .trial == 4)) |= del(.rows_selected)' >"$WORK/perf.json" ;;
	perf-no-plan) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.trials[] | select(.storage == "hpos" and .window == 168)) |= (.slowest = [])' >"$WORK/perf.json" ;;
	perf-no-cap) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.cap_cases |= map(select(.distinct_ids != 1001 or .storage != "legacy"))' >"$WORK/perf.json" ;;
	perf-cap-failed) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '(.cap_cases[] | select(.storage == "hpos" and .distinct_ids == 1000)) |= (.ok = false | .failures = ["listed 999 cap IDs, expected 1000"])' >"$WORK/perf.json" ;;
	perf-cap-dup) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.cap_cases += [.cap_cases[0]]' >"$WORK/perf.json" ;;
	cleanup-no-cap) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.cleanup |= map(select(.storage != "legacy" or .phase != "cap"))' >"$WORK/perf.json" ;;
	*) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 >"$WORK/perf.json" ;;
	esac
	[ "$mut" == log-leak ] && echo 'FAIL  [both] a check that echoed jane.citizen@realmail.com' >"$WORK/both-http.log"
	[ "$mut" == log-path ] && echo "FAIL  [both] wp-env start (log: $HOME/private/blzmcp-matrix-logs.XYZ/both-start.log)" >"$WORK/both-http.log"
	LAST_OUT=$out
	local states_env=()
	[ "$mut" == drop-easy-2.5.0 ] && states_env=(BLZMCP_REQUIRED_STATES='recommended easy-2.3.1 easy-2.4.0 both none')
	env ${states_env[@]+"${states_env[@]}"} BLZMCP_MATRIX_OUT=$out BLZMCP_TRANSPORT_OUT="${out%.md}-t.md" BLZMCP_REPORTS_DIR=$WORK "$BASH" "$REPO/tests/matrix/record.sh" "$WORK/summary.txt" PASSED >"$WORK/log" 2>&1
	local got=$?
	if [ "$got" -eq "$want" ]; then
		echo "PASS  $label (exit $got)"
		pass=$((pass + 1))
	else
		echo "FAIL  $label (expected exit $want, got $got)"
		sed 's/^/      /' "$WORK/log" | head -5
		fail=$((fail + 1))
	fi
}

stage_good "$WORK/good"
check 'faithful package is accepted' 0 "$WORK/good"
grep -q 'byte-identical' "$WORK"/out-*.md 2>/dev/null && grep -q '^- Inventory: ' "$WORK"/out-*.md && {
	echo "PASS  evidence names byte identity and the inventory"
	pass=$((pass + 1))
} || {
	echo "FAIL  evidence names byte identity and the inventory"
	fail=$((fail + 1))
}

stage_good "$WORK/vend"
v=$(find "$WORK/vend/$PKG/vendor/woocommerce/commerce-abilities" -name '*.php' -type f | sort | head -1)
echo '// changed' >>"$v"
check 'changed vendored commerce file is refused' 3 "$WORK/vend"

stage_good "$WORK/src"
echo '// changed' >>"$WORK/src/$PKG/src/Connectors/EasyMcpConnector.php"
check 'changed src file is refused' 3 "$WORK/src"

stage_good "$WORK/miss"
rm "$WORK/miss/$PKG/src/Connectors/EasyMcpConnector.php"
check 'missing src file is refused' 3 "$WORK/miss"

stage_good "$WORK/extra"
echo 'x' >"$WORK/extra/$PKG/vendor/other.php"
echo 'x' >"$WORK/extra/$PKG/not-in-git.php"
check 'unexpected vendor/ and untracked files are refused' 3 "$WORK/extra"

mkdir -p "$WORK/empty/$PKG"
mkdir -p "$WORK/empty/$PKG/vendor/composer" && echo '<?php' >"$WORK/empty/$PKG/vendor/autoload.php"
check 'zero matched files is refused' 3 "$WORK/empty"

mkdir -p "$WORK/prefix/other"
cp -R "$WORK/good/$PKG/." "$WORK/prefix/other/"
check 'wrong top-level folder is refused' 3 "$WORK/prefix"

# Candidate identity and labelling.
check 'a run hash that is not the zip is refused' 3 "$WORK/good" bad-sha
check 'a run labelled with another version is refused' 3 "$WORK/good" stale-version
check 'faithful package with complete evidence is accepted again' 0 "$WORK/good"
assert() { if [ "$2" == true ]; then echo "PASS  $1"; pass=$((pass + 1)); else echo "FAIL  $1"; fail=$((fail + 1)); fi; }
assert "record is titled with the candidate's version ($HEAD_VERSION), not a fixed one" "$(grep -qxF "# Blaze MCP Toolkit $HEAD_VERSION - real-plugin matrix" "$LAST_OUT" && echo true)"
assert 'record has the storage table and the performance table' "$(grep -q '^## Payment health order storage' "$LAST_OUT" && grep -q '^| legacy | 168h | db | 900 |' "$LAST_OUT" && echo true)"
rm -f "$WORK"/v*-matrix-*.md
BLZMCP_REPORTS_DIR=$WORK "$BASH" "$REPO/tests/matrix/record.sh" "$WORK/summary.txt" PASSED >"$WORK/log" 2>&1
assert "default record name carries the candidate version (v$HEAD_VERSION-matrix-<date>.md)" "$(ls "$WORK"/v"$HEAD_VERSION"-matrix-*.md >/dev/null 2>&1 && echo true)"

# Evidence completeness.
check 'missing storage records are refused' 5 "$WORK/good" no-storage
check 'a failed storage combination fails the record' 0 "$WORK/good" failed-storage
assert 'a failed storage combination is recorded as MATRIX FAILED' "$(grep -q 'MATRIX FAILED' "$LAST_OUT" && echo true)"
check 'a missing performance receipt is refused' 5 "$WORK/good" no-perf
check 'a performance receipt for another zip is refused' 5 "$WORK/good" perf-unbound
check 'a performance budget miss is refused' 5 "$WORK/good" perf-over
check 'a partition with fewer than 5 trials is refused' 5 "$WORK/good" perf-short
check 'a performance receipt with no HTTP latency figure is refused' 5 "$WORK/good" perf-no-http
check 'a performance receipt whose HTTP call failed is refused' 5 "$WORK/good" perf-http-error
check 'faithful package with HTTP latency is accepted' 0 "$WORK/good"
assert 'record shows the HTTP latency and the jobs path' "$(grep -q '^- HTTP latency (abilities REST route, ms): legacy/24h/cold 940' "$LAST_OUT" && grep -q '^- Background jobs timed on: exact database-store path' "$LAST_OUT" && echo true)"
assert 'record shows the trial validity rule and the forced reader failures' "$(grep -q '^- Trial validity: a trial counts only' "$LAST_OUT" && grep -q '^- Forced reader failures (each must fail the trial): logs/legacy/file failed as required' "$LAST_OUT" && echo true)"

# REVIEW-pass4 #10: performance success requires successful work (trials checked, not the flag).
check 'a timed trial whose order reader failed is refused' 5 "$WORK/good" perf-reader-orders
check 'a timed trial whose log reader failed is refused' 5 "$WORK/good" perf-reader-logs
check 'a timed trial whose job reader failed is refused' 5 "$WORK/good" perf-reader-jobs
check 'a timed trial with a missing inventory row is refused' 5 "$WORK/good" perf-reader-inventory
check 'a timed trial with no reader validation is refused' 5 "$WORK/good" perf-no-validation
check 'a receipt with no per-trial records is refused' 5 "$WORK/good" perf-no-trials
check 'a receipt without the forced reader failures is refused' 5 "$WORK/good" perf-forced-missing
check 'a forced order-reader failure that passed is refused' 5 "$WORK/good" perf-forced-orders
check 'a forced log-reader failure that passed is refused' 5 "$WORK/good" perf-forced-logs
check 'a forced job-reader failure that passed is refused' 5 "$WORK/good" perf-forced-jobs
check 'jobs timed on HybridStore (the sampled path) are refused' 5 "$WORK/good" perf-hybrid-jobs
check 'jobs timed partly on HybridStore are refused' 5 "$WORK/good" perf-mixed-jobs
check 'a receipt that did not require the exact jobs store is refused' 5 "$WORK/good" perf-jobs-not-required

# Wave 4 (REVIEW-pass4b gap 2): the volume fixture cleanup is part of the evidence.
check 'faithful receipt with a clean cleanup record is accepted' 0 "$WORK/good"
assert 'record shows the cleanup line' "$(grep -qxF -- '- Volume fixture cleanup: legacy removed 61000/61000 orders, 0 tagged left; hpos removed 61000/61000 orders, 0 tagged left; diagnostics cleanups 4, all clean true; order storage restored to legacy sync=no: true' "$LAST_OUT" && echo true)"
check 'a receipt with no cleanup record is refused' 5 "$WORK/good" cleanup-missing
check 'a receipt missing a storage'"'"'s post-run cleanup is refused' 5 "$WORK/good" cleanup-no-after
check 'a receipt missing the final cleanup is refused' 5 "$WORK/good" cleanup-no-final
check 'a cleanup that left fixture orders behind is refused' 5 "$WORK/good" cleanup-leftover
check 'a cleanup that removed fewer orders than were seeded is refused' 5 "$WORK/good" cleanup-short
check 'a diagnostics cleanup that left fixture jobs is refused' 5 "$WORK/good" cleanup-diag
check 'a failed order storage restore is refused' 5 "$WORK/good" cleanup-restore
check 'a state that did not run is refused' 5 "$WORK/good" state-not-run

# 1.2.1 item 5: duplicated partitions, the transport rows and the excluded-origin volume.
check 'a duplicated STATE line is refused' 5 "$WORK/good" state-dup
check 'a duplicated STORAGE line is refused' 5 "$WORK/good" storage-dup
check 'faithful transport rows are accepted' 0 "$WORK/good"
assert 'the transport receipt is written with every row and a TRANSPORT PASSED result' "$(grep -q '^- Result: \*\*TRANSPORT PASSED\*\*' "${LAST_OUT%.md}-t.md" && [ "$(grep -cE '^\| [a-z0-9.-]+ \| (rec|easy) \|' "${LAST_OUT%.md}-t.md")" == 164 ] && echo true)"
assert 'the matrix record has the transport summary (required 164, received 164, passed 164)' "$(grep -q '^- Required 164 (gate 164), received 164, passed 164; missing 0' "$LAST_OUT" && grep -q '^| both | easy | hpos | 11 | 11 |' "$LAST_OUT" && grep -q '^| easy-2.5.0 | easy | legacy | 11 | 11 |' "$LAST_OUT" && echo true)"
assert 'the matrix record has 40 storage rows (gate 40) and the HTTP safety-check self-test line' "$(grep -qxF -- '- Storage rows: required 40 (gate 40), received 40' "$LAST_OUT" && grep -qxF -- '- HTTP safety-check self-test (tests/matrix/test-http-checks.sh, offline injected replies): 71 passed, 0 failed' "$LAST_OUT" && echo true)"
check 'a matrix narrowed to the 5 wave 2 states (no easy-2.5.0) is refused' 5 "$WORK/good" drop-easy-2.5.0
assert '  ... the record names both gate counts' "$(grep -qF 'required transport partitions come to 137, the release matrix needs 164' "$LAST_OUT" && grep -qF 'required storage partitions come to 32, the release matrix needs 40' "$LAST_OUT" && echo true)"
check 'a run without the HTTP safety-check self-test is refused' 5 "$WORK/good" http-checks-missing
assert '  ... recorded as not run, TRANSPORT FAILED' "$(grep -qF 'test-http-checks.sh) did not run (no HTTP_CHECKS line)' "$LAST_OUT" && grep -q 'TRANSPORT FAILED' "${LAST_OUT%.md}-t.md" && echo true)"
check 'a run whose HTTP safety-check self-test failed is refused' 5 "$WORK/good" http-checks-failed
assert '  ... recorded with its summary' "$(grep -qF 'test-http-checks.sh) did not pass: 68 passed, 3 failed' "$LAST_OUT" && echo true)"
check 'a missing transport row is refused' 5 "$WORK/good" transport-missing
assert '  ... the record names the missing partition' "$(grep -qF 'transport rows missing: 1 of 164 (first: easy-2.3.1|easy|legacy|anonymous|denied)' "$LAST_OUT" && echo true)"
check 'a duplicated transport row is refused' 5 "$WORK/good" transport-dup
check 'a transport row outside the required set is refused' 5 "$WORK/good" transport-unknown
check 'a state with no transport rows is refused' 5 "$WORK/good" transport-none
check 'a FAILED transport row fails the record' 0 "$WORK/good" transport-failed
assert '  ... recorded as MATRIX FAILED and TRANSPORT FAILED' "$(grep -q 'MATRIX FAILED' "$LAST_OUT" && grep -q 'TRANSPORT FAILED' "${LAST_OUT%.md}-t.md" && echo true)"
check 'a schema 1 performance receipt is refused' 5 "$WORK/good" perf-schema-1
check 'a receipt without the excluded-origin population is refused' 5 "$WORK/good" perf-no-outside
check 'an excluded-origin population over fewer than 10 methods is refused' 5 "$WORK/good" perf-few-outside-methods
check 'eligible volume below 50,000 recent orders is refused' 5 "$WORK/good" perf-replaced-volume
check 'excluded-origin orders not verified per storage are refused' 5 "$WORK/good" perf-verify-outside
check 'a timed trial whose outside reader failed is refused' 5 "$WORK/good" perf-reader-outside
check 'a timed trial without the outside reader is refused' 5 "$WORK/good" perf-reader-keys
check 'a trial without rows selected is refused' 5 "$WORK/good" perf-no-rows
check 'trials without a query plan are refused' 5 "$WORK/good" perf-no-plan
check 'a missing 1,001-ID cap case is refused' 5 "$WORK/good" perf-no-cap
check 'a failed 1,000-ID cap case is refused' 5 "$WORK/good" perf-cap-failed
check 'a duplicated cap case is refused' 5 "$WORK/good" perf-cap-dup
check 'a missing cap fixture cleanup is refused' 5 "$WORK/good" cleanup-no-cap
check 'faithful package with complete 1.2.1 evidence is accepted' 0 "$WORK/good"
assert 'record shows the excluded-origin seed, rows examined and the cap cases' "$(grep -q 'plus 1000 excluded-origin orders counted separately across 10 methods' "$LAST_OUT" && grep -qE '^\| legacy \| 24h \| file \| 900 \| .* \| 61000 \| 2100 \| 4000000 \| true \|' "$LAST_OUT" && grep -q 'hpos 1001 IDs: listed 1000, methods_truncated true, inventory partial, passed' "$LAST_OUT" && echo true)"

# Privacy.
check 'an e-mail address in a copied log line refuses the record' 5 "$WORK/good" log-leak
assert 'a refused record is not written' "$([ ! -e "$LAST_OUT" ] && echo true)"
check 'a local path in a copied log line is replaced, then accepted' 0 "$WORK/good" log-path
assert 'the record shows <path>, never the home directory' "$(grep -q '<path>' "$LAST_OUT" && ! grep -qF "$HOME" "$LAST_OUT" && echo true)"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
