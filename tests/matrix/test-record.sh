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
# Ruling Q6: set one partition's raw trial timings ($1 storage, $2 window, $3 logger, $4 = 5
# comma-separated values; "invalid" fails that trial's order reader, "null" drops its elapsed_ms,
# "drop" removes the trial). The partition aggregate (trials_ms, max_ms, pass) and all_pass are left
# as the receipt claims them, so every failing case here is also a forged passing aggregate.
trial_times() {
	jq --arg s "$1" --argjson w "$2" --arg l "$3" --arg v "$4" '($v | split(",")) as $vs
		| .trials |= [.[] | if .storage == $s and .window == $w and .logger == $l then
			$vs[.trial - 1] as $x
			| if $x == "invalid" then (.ok = false | .validation.readers.orders = false | .validation.failures = ["orders: forced"])
			  elif $x == "null" then del(.elapsed_ms)
			  elif $x == "drop" then empty
			  else .elapsed_ms = ($x | tonumber) end
		  else . end]'
}
q6_mut() {
	case "$1" in
	q6-run1) trial_times legacy 24 db 1177.4,1248.8,1191.9,1172,1605.8 | trial_times legacy 168 db 1325.4,1192.6,1215.6,1291.2,1630.1 ;;
	q6-2000) trial_times legacy 24 db 1100,1100,1100,1100,2000 ;;
	q6-2000.1) trial_times legacy 24 db 1100,1100,1100,1100,2000.1 ;;
	q6-1500-one) trial_times legacy 168 db 1100,1500,1100,1100,1100 ;;
	q6-1500-two) trial_times legacy 168 db 1100,1500,1100,1500,1100 ;;
	q6-second-slow) trial_times legacy 24 db 1177,1606,1192,1172,1546 ;;
	q6-over-2000) trial_times legacy 168 db 1100,1100,2400,1100,1100 ;;
	q6-invalid) trial_times legacy 24 db 1100,1100,1100,invalid,1100 ;;
	q6-null) trial_times legacy 168 db 1100,null,1100,1100,1100 ;;
	q6-four) trial_times legacy 24 db 1100,1100,1100,1100,drop ;;
	q6-hpos-slow) trial_times hpos 24 db 900,900,900,900,1600 ;;
	q6-legacy-file-slow) trial_times legacy 168 file 900,900,900,900,1501 ;;
	q6-5of5-1500) trial_times hpos 168 file 900,1500,900,900,900 ;;
	q6-5of5-1499) trial_times hpos 168 file 1499.9,1499.9,1499.9,1499.9,1499.9 ;;
	# Every partition claims pass with max 900 and the 4of5 rule label; the hpos raw trials do not.
	q6-forged) jq '.partitions[].rule = "4of5<1500,max<=2000"' | trial_times hpos 24 file 900,900,900,1600,900 ;;
	# The preserved wave 4 shape: raw trials within Q6, but the suite's own verdict was FAILED.
	q6-receipt-failed) trial_times legacy 24 db 1177.4,1248.8,1191.9,1172,1605.8 | jq '.all_pass = false | (.partitions[] | select(.storage == "legacy" and .logger == "db") | .pass) = false' ;;
	*) cat ;;
	esac
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

# The card-testing rows for one state (CARD= lines, 1.3 item 8), with mutations: card-missing
# (drops one recommended legacy:yes row), card-dup (repeats a both row), card-failed (one easy-2.5.2
# row FAILED), card-unknown (an extra case), card-none (no card rows at all), card-auth-label (an
# existing-style hpos authority label instead of a storage:sync one).
card_rows() {
	local state=$1 mut=$2 routes r a c
	case "$state" in recommended) routes=rec ;; easy-*) routes=easy ;; *) routes='rec easy' ;; esac
	[ "$mut" == card-none ] && return 0
	for r in $routes; do
		if [ "$state" == none ]; then
			echo "CARD=$state|$r|any|admin|card_no_tool|PASSED"
			continue
		fi
		for c in 'admin|card_discovery' 'admin|card_workflows' 'admin|card_analytics_off' 'admin|card_tool_off' 'manager|card_roles'; do echo "CARD=$state|$r|any|$c|PASSED"; done
		for a in hpos:no hpos:yes legacy:yes legacy:no; do
			for c in 'admin|card_recent' 'admin|card_earlier' 'admin|card_low_volume' 'admin|card_gaps' 'admin|card_protection' 'admin|card_diag_off' 'admin|card_diag_on' 'admin|card_diag_off_again' 'manager|card_diag_off' 'manager|card_diag_on' 'manager|card_diag_off_again' 'customer|card_denied' 'subscriber|card_denied' 'anonymous|card_denied' 'manager|card_warm_downgrade'; do
				[ "$mut" == card-missing ] && [ "$state" == recommended ] && [ "$a" == legacy:yes ] && [ "$c" == 'admin|card_gaps' ] && continue
				if [ "$mut" == card-failed ] && [ "$state" == easy-2.5.2 ] && [ "$a" == hpos:yes ] && [ "$c" == 'manager|card_diag_off' ]; then
					echo "CARD=$state|$r|$a|$c|FAILED"
					continue
				fi
				if [ "$mut" == card-auth-label ] && [ "$state" == both ] && [ "$a" == hpos:no ]; then
					echo "CARD=$state|$r|hpos|$c|PASSED"
					continue
				fi
				echo "CARD=$state|$r|$a|$c|PASSED"
				[ "$mut" == card-dup ] && [ "$state" == both ] && [ "$r" == easy ] && [ "$a" == legacy:no ] && [ "$c" == 'admin|card_recent' ] && echo "CARD=$state|$r|$a|$c|PASSED"
			done
		done
		[ "$mut" == card-unknown ] && [ "$state" == both ] && echo "CARD=$state|$r|legacy:no|admin|card_extra_case|PASSED"
	done
	return 0
}

# A card performance receipt the validator accepts for zip SHA $1, source $2 and attempt $3, from
# the card suite's own sample builder; card-perf-* mutations change it afterwards.
card_perf_receipt() {
	php "$REPO/tests/matrix/card-testing-perf-sample.php" "$1" "$2" "$3"
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
	[ "$mut" == stale-name ] && zip="$WORK/candidate-0123456789abcdef0123456789abcdef01234567.zip"
	[ "$mut" == good-name ] && zip="$WORK/candidate-${COMMIT:0:12}.zip"
	rm -f "$WORK"/candidate-*.zip "$WORK"/*.log
	(cd "$dir" && zip -qr "$zip" .)
	sha=$(shasum -a 256 "$zip" | awk '{print $1}')
	version=$HEAD_VERSION
	[ "$mut" == bad-sha ] && sha=0000000000000000000000000000000000000000000000000000000000000000
	[ "$mut" == stale-version ] && version=1.0.0
	local attempt=20261127T010203Z-4242-777 states="recommended easy-2.3.1 easy-2.4.0 easy-2.5.0 easy-2.5.1 easy-2.5.2 both none"
	case "$mut" in subset-*) states="recommended easy-2.5.2" ;; esac
	[ "$mut" == subset-no-recommended ] && states="easy-2.5.2 both"
	{
		printf 'ZIP=%s\nZIP_SHA256=%s\nVERSION=%s\nCOMMIT=%s\nWINDOW_FILE=\nPERF_FILE=%s\n' "$zip" "$sha" "$version" "$COMMIT" "$([ "$mut" == no-perf ] || echo "$WORK/perf.json")"
		[ "$mut" == card-perf-missing ] || echo "CARD_PERF_FILE=$WORK/card-perf.json"
		[ "$mut" == no-attempt ] || echo "ATTEMPT=$attempt"
		case "$mut" in
		scope-missing) ;;
		subset-*) echo 'SCOPE=subset'; [ "$mut" == subset-no-states ] || echo "SCOPE_STATES=$([ "$mut" == subset-unknown-state ] && echo "$states easy-9.9.9" || echo "$states")" ;;
		*) echo 'SCOPE=full' ;;
		esac
		[ "$mut" == restart ] && echo 'WINDOW_RESTARTS=1' || echo 'WINDOW_RESTARTS=0'
		case "$mut" in
		http-checks-missing) ;;
		http-checks-failed) echo 'HTTP_CHECKS=68 passed, 3 failed' ;;
		*) echo 'HTTP_CHECKS=71 passed, 0 failed' ;;
		esac
		# Host admission (ruling Q8): N = 12, bounds load5 < 3.00 and load1 < 3.96.
		case "$mut" in
		ha-no-host) ;;
		ha-no-ncpu) echo 'HOST=testhost||Test CPU|sysctl vm.loadavg' ;;
		ha-loadsrc) echo 'HOST=testhost|12|Test CPU|/tmp/fake-loadavg' ;;
		ha-proc) echo 'HOST=testhost|12|Test CPU|/proc/loadavg' ;;
		*) echo 'HOST=testhost|12|Test CPU|sysctl vm.loadavg' ;;
		esac
		case "$mut" in
		ha-no-start) ;;
		ha-start-load5) echo 'ADMISSION=start|2026-11-24T00:00:00Z|testhost|12|2.10|3.00|0|1' ;;
		ha-start-boundary) echo 'ADMISSION=start|2026-11-24T00:00:00Z|testhost|12|3.95|2.99|0|1' ;;
		*) echo 'ADMISSION=start|2026-11-24T00:00:00Z|testhost|12|2.10|1.90|0|1' ;;
		esac
		case "$mut" in
		ha-no-perf) ;;
		ha-perf-load1) echo 'ADMISSION=perf|2026-11-24T01:02:00Z|testhost|12|3.96|2.80|120|3' ;;
		ha-perf-ncpu) echo 'ADMISSION=perf|2026-11-24T01:02:00Z|testhost|8|2.00|1.80|120|3' ;;
		*) echo 'ADMISSION=perf|2026-11-24T01:02:00Z|testhost|12|3.50|2.80|120|3' ;;
		esac
		[ "$mut" == admission-repeated ] && echo 'ADMISSION=perf|2026-11-24T02:02:00Z|testhost|12|1.50|1.80|60|1'
		[ "$mut" == ha-no-samples ] && echo 'LOAD_SAMPLES=0' || echo 'LOAD_SAMPLES=180'
		echo 'LOAD_MAX=5.10|3.40'
		echo 'LOAD_OVER=4'
		for state in $states; do
			[ "$mut" == drop-easy-2.5.1 ] && [ "$state" == easy-2.5.1 ] && continue
			{ [ "$mut" == drop-easy-2.5.2 ] || [ "$mut" == missing-easy-2.5.2 ]; } && [ "$state" == easy-2.5.2 ] && continue
			if [ "$mut" == state-not-run ] && [ "$state" == both ]; then
				echo "STATE=$state|NOT RUN|0|1|||[]"
				continue
			fi
			echo "STATE=$state|PASSED|10|0|6.9|8.3|[]"
			[ "$mut" == state-dup ] && [ "$state" == easy-2.4.0 ] && echo "STATE=$state|PASSED|10|0|6.9|8.3|[]"
			transport_rows "$state" "$mut"
			card_rows "$state" "$mut"
			[ "$state" == none ] && continue
			for combo in hpos:no hpos:yes legacy:yes legacy:no; do
				[ "$mut" == storage-unknown ] && [ "$state" == recommended ] && [ "$combo" == hpos:no ] && echo "STORAGE=$state|$combo|test-not-a-storage-suite.php|PASSED|10|0"
				for suite in test-payment-orders.php test-payment-health.php test-card-testing-orders.php test-card-testing.php; do
					[ "$mut" == no-storage ] && [ "$combo" == legacy:yes ] && continue
					[ "$mut" == no-card-storage ] && [ "$state" == easy-2.5.2 ] && [ "$combo" == hpos:yes ] && [ "$suite" == test-card-testing.php ] && continue
					if [ "$mut" == failed-card-storage ] && [ "$state" == both ] && [ "$combo" == legacy:no ] && [ "$suite" == test-card-testing-orders.php ]; then
						echo "STORAGE=$state|$combo|$suite|FAILED|9|1"
						continue
					fi
					[ "$mut" == storage-dup ] && [ "$state" == both ] && [ "$combo" == legacy:no ] && echo "STORAGE=$state|$combo|$suite|PASSED|10|0"
					if [ "$mut" == failed-storage ] && [ "$combo" == hpos:yes ]; then
						echo "STORAGE=$state|$combo|$suite|FAILED|9|1"
					else
						echo "STORAGE=$state|$combo|$suite|PASSED|10|0"
					fi
				done
			done
		done
		case "$mut" in
		truncated) ;;
		end-mismatch) echo 'END=20261127T999999Z-1-1' ;;
		*) echo "END=$attempt" ;;
		esac
	} >"$WORK/summary.txt"
	[ "$mut" == end-not-last ] && echo 'STATE=extra|PASSED|1|0|||[]' >>"$WORK/summary.txt"
	# The card performance receipt (1.3 item 9): the card suite's own sample, bound to this run.
	rm -f "$WORK/card-perf.json"
	case "$mut" in
	card-perf-missing) ;;
	card-perf-stale-sha) card_perf_receipt "ffff$(shasum -a 256 "$zip" | awk '{print $1}' | cut -c5-)" "$COMMIT" "$attempt" >"$WORK/card-perf.json" ;;
	card-perf-stale-source) card_perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 0000000000000000000000000000000000000000 "$attempt" >"$WORK/card-perf.json" ;;
	card-perf-other-attempt) card_perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" "$COMMIT" 20261126T000000Z-1-1 >"$WORK/card-perf.json" ;;
	card-perf-truncated) card_perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" "$COMMIT" "$attempt" | head -c 400 >"$WORK/card-perf.json" ;;
	card-perf-slow) card_perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" "$COMMIT" "$attempt" | jq '(.trials[] | select(.storage == "legacy" and .window == 168 and .logger == "db" and .trial == 3)).elapsed_ms = 1500' >"$WORK/card-perf.json" ;;
	card-perf-pass-flags) card_perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" "$COMMIT" "$attempt" | jq '(.trials[] | select(.storage == "hpos" and .window == 48 and .logger == "file" and .trial == 1)).elapsed_ms = 1700 | .all_pass = true | .partitions |= map(.pass = true)' >"$WORK/card-perf.json" ;;
	*) card_perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" "$COMMIT" "$attempt" >"$WORK/card-perf.json" ;;
	esac
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
	q6-*) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | q6_mut "$mut" >"$WORK/perf.json" ;;
	*) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 >"$WORK/perf.json" ;;
	esac
	[ "$mut" == log-leak ] && echo 'FAIL  [both] a check that echoed jane.citizen@realmail.com' >"$WORK/both-http.log"
	[ "$mut" == log-path ] && echo "FAIL  [both] wp-env start (log: $HOME/private/blzmcp-matrix-logs.XYZ/both-start.log)" >"$WORK/both-http.log"
	LAST_OUT=$out
	local states_env=()
	[ "$mut" == drop-easy-2.5.1 ] && states_env=(BLZMCP_REQUIRED_STATES='recommended easy-2.3.1 easy-2.4.0 easy-2.5.0 both none')
	[ "$mut" == drop-easy-2.5.2 ] && states_env=(BLZMCP_REQUIRED_STATES='recommended easy-2.3.1 easy-2.4.0 easy-2.5.0 easy-2.5.1 both none')
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
assert 'the transport receipt is written with every row and a TRANSPORT PASSED result' "$(grep -q '^- Result: \*\*TRANSPORT PASSED\*\*' "${LAST_OUT%.md}-t.md" && [ "$(grep -E '^\| [a-z0-9.-]+ \| (rec|easy) \| (any|hpos|legacy) \| [a-z]+ \| [a-z_]+ \| PASSED \|$' "${LAST_OUT%.md}-t.md" | grep -cv '| card_')" == 218 ] && echo true)"
assert '  ... and every one of the 522 card rows, each with its storage:sync label' "$(grep -cE '^\| [a-z0-9.-]+ \| (rec|easy) \| (any|hpos:no|hpos:yes|legacy:yes|legacy:no) \| [a-z]+ \| card_[a-z_]+ \| PASSED \|$' "${LAST_OUT%.md}-t.md" | grep -qx 522 && echo true)"
assert 'the matrix record has the transport summary (required 218, received 218, passed 218)' "$(grep -q '^- Required 218 (gate 218), received 218, passed 218; missing 0' "$LAST_OUT" && grep -q '^| both | easy | hpos | 11 | 11 |' "$LAST_OUT" && grep -q '^| easy-2.5.0 | easy | legacy | 11 | 11 |' "$LAST_OUT" && grep -q '^| easy-2.5.2 | easy | legacy | 11 | 11 |' "$LAST_OUT" && echo true)"
assert 'the matrix record has 112 storage rows (gate 112) and the HTTP safety-check self-test line' "$(grep -qxF -- '- Storage rows: required 112 (gate 112), received 112' "$LAST_OUT" && grep -qxF -- '- HTTP safety-check self-test (tests/matrix/test-http-checks.sh, offline injected replies): 71 passed, 0 failed' "$LAST_OUT" && echo true)"
assert 'the matrix record has the card rows (required 522, received 522, passed 522; 740 with the transport rows)' "$(grep -q '^- Required 522 (gate 522; full release set 522, SHA-256 `57170eb243eb6dcc766fdf855906380e5eece343447f18cced9e6c6b236ce4c6`), received 522, passed 522; missing 0' "$LAST_OUT" && grep -qF 'Transport and card rows together: required 740 (full release matrix 740)' "$LAST_OUT" && grep -q '^| easy-2.5.2 | easy | legacy:no | 15 | 15 |' "$LAST_OUT" && grep -q '^| none | rec | any | 1 | 1 |' "$LAST_OUT" && echo true)"
check 'a matrix narrowed to the states before easy-2.5.1 is refused' 5 "$WORK/good" drop-easy-2.5.1
assert '  ... the record names every gate count' "$(grep -qF 'required transport partitions come to 164, the release matrix needs 218' "$LAST_OUT" && grep -qF 'required storage partitions come to 80, the release matrix needs 112' "$LAST_OUT" && grep -qF 'required card partitions come to 392, the release matrix needs 522' "$LAST_OUT" && echo true)"
check 'a matrix narrowed (BLZMCP_REQUIRED_STATES) to the 7 states before easy-2.5.2 is refused' 5 "$WORK/good" drop-easy-2.5.2
assert '  ... the record names every gate count' "$(grep -qF 'required card partitions come to 457, the release matrix needs 522' "$LAST_OUT" && grep -qF 'required storage partitions come to 96, the release matrix needs 112' "$LAST_OUT" && echo true)"
check 'a run without the easy-2.5.2 state (no override) is refused' 5 "$WORK/good" missing-easy-2.5.2
assert '  ... the record names the missing state, its storage rows and its 65 card rows' "$(grep -qF 'state easy-2.5.2 did not run' "$LAST_OUT" && grep -qF 'storage easy-2.5.2 hpos:no test-card-testing.php did not run' "$LAST_OUT" && grep -qF 'card rows missing: 65 of 522' "$LAST_OUT" && echo true)"
check 'a run without the HTTP safety-check self-test is refused' 5 "$WORK/good" http-checks-missing
assert '  ... recorded as not run, TRANSPORT FAILED' "$(grep -qF 'test-http-checks.sh) did not run (no HTTP_CHECKS line)' "$LAST_OUT" && grep -q 'TRANSPORT FAILED' "${LAST_OUT%.md}-t.md" && echo true)"
check 'a run whose HTTP safety-check self-test failed is refused' 5 "$WORK/good" http-checks-failed
assert '  ... recorded with its summary' "$(grep -qF 'test-http-checks.sh) did not pass: 68 passed, 3 failed' "$LAST_OUT" && echo true)"
check 'a missing transport row is refused' 5 "$WORK/good" transport-missing
assert '  ... the record names the missing partition' "$(grep -qF 'transport rows missing: 1 of 218 (first: easy-2.3.1|easy|legacy|anonymous|denied)' "$LAST_OUT" && echo true)"
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
assert 'record shows the excluded-origin seed, rows examined and the cap cases' "$(grep -q 'plus 1000 excluded-origin orders counted separately across 10 methods' "$LAST_OUT" && grep -qE '^\| legacy \| 24h \| file \| 900 \| .* \| 61000 \| 2100 \| 4000000 \| 5of5<1500 \| true \| true \|' "$LAST_OUT" && grep -q 'hpos 1001 IDs: listed 1000, methods_truncated true, inventory partial, passed' "$LAST_OUT" && echo true)"

# Ruling Q6 (REVIEW-pass4c): the recorder decides the budget on the raw trials, per partition.
check 'Q6: one slow trial in each legacy db partition (run 1 shape, 1605.8 and 1630.1 ms) is accepted' 0 "$WORK/good" q6-run1
assert '  ... the record shows the recorder rule and decision per partition' "$(grep -qF '| legacy | 168h | db | 1630.1 | 1325.4, 1192.6, 1215.6, 1291.2, 1630.1 | 5 of 5, 4 under |' "$LAST_OUT" && grep -qE '^\| legacy \| 168h \| db \| .* \| 4of5<1500,max<=2000 \| true \| true \|$' "$LAST_OUT" && grep -qE '^\| hpos \| 24h \| db \| .* \| 5of5<1500 \| true \| true \|$' "$LAST_OUT" && grep -qF -- '- Budget rule (ruling Q6, REVIEW-pass4c), decided by this recorder' "$LAST_OUT" && echo true)"
check 'Q6 boundary: a legacy db trial of exactly 2000 ms is accepted' 0 "$WORK/good" q6-2000
check 'Q6 boundary: a legacy db trial of 2000.1 ms is refused' 5 "$WORK/good" q6-2000.1
assert '  ... named as a budget miss under its rule' "$(grep -qF 'performance budget missed: legacy/24h/db (rule 4of5<1500,max<=2000: 4 of 5 trials valid and under 1500 ms, 5 valid, max 2000.1 ms)' "$LAST_OUT" && echo true)"
check 'Q6 boundary: one legacy db trial of exactly 1500 ms is accepted as the one slow trial' 0 "$WORK/good" q6-1500-one
check 'Q6 boundary: two legacy db trials of exactly 1500 ms are refused' 5 "$WORK/good" q6-1500-two
check 'Q6: a second slow legacy db trial is refused (the receipt still claims pass)' 5 "$WORK/good" q6-second-slow
assert '  ... named with 3 under budget' "$(grep -qF 'legacy/24h/db (rule 4of5<1500,max<=2000: 3 of 5 trials valid and under 1500 ms' "$LAST_OUT" && echo true)"
check 'Q6: a legacy db trial above 2000 ms is refused' 5 "$WORK/good" q6-over-2000
check 'Q6: an invalid legacy db trial is refused even with 4 fast trials' 5 "$WORK/good" q6-invalid
assert '  ... and the recorder table marks that partition not passed' "$(grep -qE '^\| legacy \| 24h \| db \| .* \| 4 of 5, 4 under \| .* \| 4of5<1500,max<=2000 \| false \| true \|$' "$LAST_OUT" && echo true)"
check 'Q6: a legacy db trial with no elapsed_ms is refused' 5 "$WORK/good" q6-null
check 'Q6: a legacy db partition with 4 trials is refused' 5 "$WORK/good" q6-four
check 'Q6: one slow trial in an HPOS db partition is refused (5 of 5)' 5 "$WORK/good" q6-hpos-slow
assert '  ... named under 5of5<1500' "$(grep -qF 'performance budget missed: hpos/24h/db (rule 5of5<1500: 4 of 5 trials valid and under 1500 ms' "$LAST_OUT" && echo true)"
check 'Q6: one slow trial in a legacy file partition is refused (5 of 5)' 5 "$WORK/good" q6-legacy-file-slow
check 'Q6 boundary: a 5of5 trial of exactly 1500 ms is refused' 5 "$WORK/good" q6-5of5-1500
check 'Q6 boundary: 5of5 trials of 1499.9 ms are accepted' 0 "$WORK/good" q6-5of5-1499
check 'Q6: a forged passing aggregate (pass flags, max 900, 4of5 labels everywhere) over a failing hpos trial is refused' 5 "$WORK/good" q6-forged
assert '  ... the recorder applies 5of5<1500 to hpos regardless of the receipt label' "$(grep -qF 'performance budget missed: hpos/24h/file (rule 5of5<1500: 4 of 5 trials valid and under 1500 ms, 5 valid, max 1600 ms)' "$LAST_OUT" && grep -qE '^\| hpos \| 24h \| file \| 1600 \| .* \| 5of5<1500 \| false \| true \|$' "$LAST_OUT" && echo true)"
check 'Q6: raw trials within Q6 but the receipt verdict FAILED (the wave 4 shape) is refused' 5 "$WORK/good" q6-receipt-failed
assert '  ... named as the receipt verdict' "$(grep -qF 'performance receipt verdict is not all_pass (perf-suite.sh failed)' "$LAST_OUT" && echo true)"

# Host admission (ruling Q8, REVIEW-pass4e).
check 'Q8: the faithful run with both admission samples is accepted' 0 "$WORK/good"
assert '  ... the record has the Host admission section with N, the bounds and the result' "$(grep -qF '## Host admission (ruling Q8)' "$LAST_OUT" && grep -qF -- '- Result: admitted at the start and before the performance phase' "$LAST_OUT" && grep -qF 'logical CPUs N = 12; CPU Test CPU; load source sysctl vm.loadavg' "$LAST_OUT" && grep -qF 'load5 < 0.25 x N = 3.00 and load1 < 0.33 x N = 3.96' "$LAST_OUT" && echo true)"
assert '  ... both admission samples in the table' "$(grep -qF '| start | 2026-11-24T00:00:00Z | testhost | 12 | 2.10 | 1.90 | 0 | 1 |' "$LAST_OUT" && grep -qF '| perf | 2026-11-24T01:02:00Z | testhost | 12 | 3.50 | 2.80 | 120 | 3 |' "$LAST_OUT" && echo true)"
assert '  ... the sample count, run maxima and excursions as diagnostics' "$(grep -qF 'Load samples over the run (every 60 s, UTC): 180; max load1 5.10, max load5 3.40; samples above the admission bounds: 4' "$LAST_OUT" && grep -qF 'never excuse a slow valid trial' "$LAST_OUT" && echo true)"
check 'Q8: /proc/loadavg is an accepted load source' 0 "$WORK/good" ha-proc
check 'Q8 boundary: admission at load1 3.95 and load5 2.99 is accepted' 0 "$WORK/good" ha-start-boundary
check 'Q8: no ADMISSION start line is refused' 5 "$WORK/good" ha-no-start
assert '  ... named, and the record says REFUSED' "$(grep -qF 'host admission (ruling Q8): no ADMISSION start line' "$LAST_OUT" && grep -qF -- '- Result: **REFUSED**: no ADMISSION start line' "$LAST_OUT" && grep -qF 'MATRIX FAILED' "$LAST_OUT" && echo true)"
check 'Q8: no ADMISSION perf line is refused' 5 "$WORK/good" ha-no-perf
assert '  ... named' "$(grep -qF 'host admission (ruling Q8): no ADMISSION perf line' "$LAST_OUT" && echo true)"
check 'Q8: no HOST line is refused' 5 "$WORK/good" ha-no-host
check 'Q8: a HOST line without the CPU count is refused' 5 "$WORK/good" ha-no-ncpu
check 'Q8: a non-standard load source is refused' 5 "$WORK/good" ha-loadsrc
check 'Q8 boundary: a start sample at load5 3.00 (N = 12) is refused' 5 "$WORK/good" ha-start-load5
assert '  ... named with the bounds' "$(grep -qF 'ADMISSION start sample load1=2.10 load5=3.00 is not within load5 < 3.00 and load1 < 3.96' "$LAST_OUT" && echo true)"
check 'Q8 boundary: a perf sample at load1 3.96 (N = 12) is refused' 5 "$WORK/good" ha-perf-load1
check 'Q8: a perf sample taken on another CPU count is refused' 5 "$WORK/good" ha-perf-ncpu
check 'Q8: zero load samples is refused' 5 "$WORK/good" ha-no-samples
assert '  ... named' "$(grep -qF 'no 60-second load samples recorded (LOAD_SAMPLES 0)' "$LAST_OUT" && echo true)"

# 1.3 (FEATURE-PLAN items 8 and 9, C10): card rows, card storage suites, the card performance
# receipt, attempt and scope binding, truncation, date restarts and repeated admissions. Each defect
# is its own failure with its own message.
check 'faithful package with complete 1.3 evidence is accepted' 0 "$WORK/good"
assert '  ... the record names scope full, the attempt, the source and the package hash' "$(grep -qF -- "- Scope: full; attempt \`20261127T010203Z-4242-777\`; source \`$COMMIT\`; package SHA-256" "$LAST_OUT" && grep -q '^- Result: \*\*MATRIX PASSED\*\*' "$LAST_OUT" && echo true)"
assert '  ... and the card performance validator verdict' "$(grep -qE 'validator .*: CARD PERF VALID' "$LAST_OUT" && echo true)"
assert '  ... and the frozen required sets in the transport receipt (112 storage, 218 transport, 522 card keys)' "$(grep -qF -- '- Storage (112 keys)' "${LAST_OUT%.md}-t.md" && grep -qF -- '- Transport (218 keys)' "${LAST_OUT%.md}-t.md" && grep -qF -- '- Card (522 keys)' "${LAST_OUT%.md}-t.md" && echo true)"
check 'a missing card row is refused' 5 "$WORK/good" card-missing
assert '  ... the record names the missing card partition' "$(grep -qF 'card rows missing: 1 of 522 (first: recommended|rec|legacy:yes|admin|card_gaps)' "$LAST_OUT" && echo true)"
check 'a duplicated card row is refused' 5 "$WORK/good" card-dup
assert '  ... named as duplicated' "$(grep -qF 'card rows duplicated: both|easy|legacy:no|admin|card_recent' "$LAST_OUT" && echo true)"
check 'a card row outside the required set is refused' 5 "$WORK/good" card-unknown
assert '  ... named as not in the required set' "$(grep -qF 'card rows not in the required set: both|easy|legacy:no|admin|card_extra_case' "$LAST_OUT" && echo true)"
check 'card rows under an authority label instead of storage:sync are refused' 5 "$WORK/good" card-auth-label
check 'a run with no card rows at all is refused' 5 "$WORK/good" card-none
check 'a FAILED card row fails the record' 0 "$WORK/good" card-failed
assert '  ... recorded as MATRIX FAILED and TRANSPORT FAILED, naming the row' "$(grep -q 'MATRIX FAILED' "$LAST_OUT" && grep -q 'TRANSPORT FAILED' "${LAST_OUT%.md}-t.md" && grep -qF 'not PASSED easy-2.5.2|easy|hpos:yes|manager|card_diag_off' "$LAST_OUT" && echo true)"
check 'a missing card storage row is refused' 5 "$WORK/good" no-card-storage
assert '  ... the record names it' "$(grep -qF 'storage easy-2.5.2 hpos:yes test-card-testing.php did not run' "$LAST_OUT" && echo true)"
check 'a FAILED card storage row fails the record' 0 "$WORK/good" failed-card-storage
assert '  ... recorded as MATRIX FAILED' "$(grep -q 'MATRIX FAILED' "$LAST_OUT" && echo true)"
check 'a STORAGE line for a suite outside the storage set is refused' 5 "$WORK/good" storage-unknown
check 'a summary without its END line (truncated) is refused' 5 "$WORK/good" truncated
assert '  ... named as truncated' "$(grep -qF 'the summary is truncated or not this attempt' "$LAST_OUT" && echo true)"
check 'a summary whose END names another attempt is refused' 5 "$WORK/good" end-mismatch
check 'a summary with lines after its END line is refused' 5 "$WORK/good" end-not-last
check 'a summary that names no attempt is refused' 5 "$WORK/good" no-attempt
check 'a summary without a SCOPE line is refused' 5 "$WORK/good" scope-missing
check 'a run that restarted on a new date is refused (ruling Q9)' 5 "$WORK/good" restart
assert '  ... named as a date restart' "$(grep -qF 'a date restart cannot qualify (ruling Q9; WINDOW_RESTARTS 1)' "$LAST_OUT" && echo true)"
check 'a repeated performance admission is refused' 5 "$WORK/good" admission-repeated
assert '  ... named as repeated' "$(grep -qF 'repeated perf admission (2 ADMISSION lines' "$LAST_OUT" && echo true)"
check 'a candidate named for another commit (stale package) is refused' 3 "$WORK/good" stale-name
check 'a candidate named for the evidence commit is accepted' 0 "$WORK/good" good-name
check 'no card performance receipt is refused' 5 "$WORK/good" card-perf-missing
check 'a card performance receipt bound to another zip is refused' 5 "$WORK/good" card-perf-stale-sha
check 'a card performance receipt from another source commit is refused' 5 "$WORK/good" card-perf-stale-source
check 'a card performance receipt from another attempt is refused' 5 "$WORK/good" card-perf-other-attempt
check 'a truncated card performance receipt is refused' 5 "$WORK/good" card-perf-truncated
check 'a card trial at exactly 1500 ms is refused (strictly below, no relaxed rule)' 5 "$WORK/good" card-perf-slow
check 'a slow card trial is refused even when the receipt says it passed' 5 "$WORK/good" card-perf-pass-flags
assert '  ... decided by the validator, named in the record' "$(grep -qF 'card performance receipt refused (validator exit' "$LAST_OUT" && echo true)"
# Scope: a subset (owner decision D1's branch gate) is checked the same way and never reads as the matrix.
check 'a complete subset (recommended and easy-2.5.2) is accepted as a subset' 0 "$WORK/good" subset-good
assert '  ... written as a subset record, SUBSET PASSED, never MATRIX PASSED' "$(grep -q '^- Result: \*\*SUBSET PASSED\*\* (scope subset: recommended easy-2.5.2; NOT A FULL MATRIX' "$LAST_OUT" && ! grep -q 'MATRIX PASSED' "$LAST_OUT" && grep -q 'NOT A FULL MATRIX' "${LAST_OUT%.md}-t.md" && echo true)"
assert '  ... with the subset counts from the frozen sets (32 storage, 54 transport, 130 card)' "$(grep -qxF -- '- Storage rows: required 32 (gate 32), received 32' "$LAST_OUT" && grep -q '^- Required 54 (gate 54), received 54' "$LAST_OUT" && grep -q '^- Required 130 (gate 130; full release set 522' "$LAST_OUT" && echo true)"
check 'a subset without its state list is refused' 5 "$WORK/good" subset-no-states
check 'a subset naming a state outside the release matrix is refused' 5 "$WORK/good" subset-unknown-state
check 'a subset without recommended (no performance phase) is refused' 5 "$WORK/good" subset-no-recommended
# The frozen enumerations: the pinned counts and hashes are what record.sh's own lists produce, and
# dropping any one case from a list changes the hash (so the pin check would refuse the edit).
eval "$(grep -E '^(FULL_STATES|STORAGE_COMBOS|STORAGE_SUITES|GATE_[A-Z0-9_]+)=' "$REPO/tests/matrix/record.sh")"
eval "$(sed -n '/^TRANSPORT_ANY=/,/^set_sha()/p' "$REPO/tests/matrix/record.sh" | grep -v '^set_sha()')"
for k in storage transport card; do
	case $k in storage) pin=$GATE_STORAGE_SET_SHA256 want=$GATE_STORAGE_ROWS ;; transport) pin=$GATE_TRANSPORT_SET_SHA256 want=$GATE_TRANSPORT_ROWS ;; card) pin=$GATE_CARD_SET_SHA256 want=$GATE_CARD_ROWS ;; esac
	assert "frozen $k set: $want keys whose SHA-256 is the pinned one" "$([ "$("enum_$k" "$FULL_STATES" | wc -l | tr -d ' ')" == "$want" ] && [ "$("enum_$k" "$FULL_STATES" | shasum -a 256 | awk '{print $1}')" == "$pin" ] && echo true)"
done
assert 'the gate counts are 112 storage, 218 transport and 522 card rows (740 transport and card)' "$([ "$GATE_STORAGE_ROWS $GATE_TRANSPORT_ROWS $GATE_CARD_ROWS $((GATE_TRANSPORT_ROWS + GATE_CARD_ROWS))" == '112 218 522 740' ] && echo true)"
CARD_STORAGE=${CARD_STORAGE% manager|card_warm_downgrade}
assert 'dropping one card case changes the pinned card hash' "$([ "$(enum_card "$FULL_STATES" | shasum -a 256 | awk '{print $1}')" != "$GATE_CARD_SET_SHA256" ] && echo true)"
STORAGE_SUITES=${STORAGE_SUITES% test-card-testing.php}
assert 'dropping the card ability suite changes the pinned storage hash' "$([ "$(enum_storage "$FULL_STATES" | shasum -a 256 | awk '{print $1}')" != "$GATE_STORAGE_SET_SHA256" ] && echo true)"
assert 'record.sh never lets an environment variable set a gate count or pin' "$(grep -qE '^GATE_[A-Z_]+=\$\{' "$REPO/tests/matrix/record.sh" || echo true)"

# Privacy.
check 'an e-mail address in a copied log line refuses the record' 5 "$WORK/good" log-leak
assert 'a refused record is not written' "$([ ! -e "$LAST_OUT" ] && echo true)"
check 'a local path in a copied log line is replaced, then accepted' 0 "$WORK/good" log-path
assert 'the record shows <path>, never the home directory' "$(grep -q '<path>' "$LAST_OUT" && ! grep -qF "$HOME" "$LAST_OUT" && echo true)"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
