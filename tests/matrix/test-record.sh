#!/usr/bin/env bash
# Self-test for tests/matrix/record.sh's zip inventory and byte-identity check. Builds small zips
# from the files at HEAD (no wp-env, no network) and checks that record.sh accepts a faithful one
# and refuses each broken one with exit 3. Writes only to a temporary directory.
#
#   bash tests/matrix/test-record.sh
set -uo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
PKG=blaze-mcp-toolkit-for-woocommerce
COMMIT=$(git -C "$REPO" rev-parse HEAD)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/blzmcp-test-record.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
pass=0
fail=0

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
			| {storage:$s, window:$w, logger:$l, trials_ms:[$ms, $ms, $ms, $ms, $ms], max_ms:$ms, warm_ms_max:1, complete:true, pass:($ms < 1500)}] as $p
		| [("legacy", "hpos") as $s | (24, 168) as $w | ("cold", "warm") as $k
			| {storage:$s, window:$w, kind:$k, method:"GET", http_code:"200", valid_body:true, total_ms:(if $k == "cold" then $ms + 40 else 30 end)}] as $h
		| [("legacy", "hpos") as $s | (24, 168) as $w | ("file", "db") as $l | (1, 2, 3, 4, 5) as $t
			| {storage:$s, window:$w, logger:$l, trial:$t, ok:true, elapsed_ms:$ms, action_store:"ActionScheduler_DBStore",
			   validation:{readers:{orders:true, inventory:true, logs:true, jobs:true}, failures:[]}}] as $tr
		| [("legacy", "hpos") as $s | ({l:"file", f:"logs"}, {l:"db", f:"logs"}, {l:"db", f:"orders"}, {l:"db", f:"jobs"}) as $p
			| {storage:$s, logger:$p.l, force:$p.f, ok:false, forced:$p.f, detected:true}] as $f
		| {schema:"blzmcp-payment-performance/1", candidate_sha256:$sha, budget_ms:1500, meaning:"application-cache cold",
		   validity:"a trial counts only with the seeded workload observed and every reader at its expected counts",
		   seed:{recent_orders:50000, horizon_days:28, older_orders:10000, methods:["stripe", "cod", "cheque"]},
		   exact_jobs_required:true, jobs_store:["ActionScheduler_DBStore"], jobs_path:"exact database-store path (one grouped query)",
		   all_pass:($p | all(.pass)), partitions:$p, http:$h, trials:$tr, forced_failures:$f}'
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

# check <label> <expected exit> <stage dir> [mutation]
# Mutations: bad-sha, stale-version, no-storage, failed-storage, no-perf, perf-unbound, perf-over,
# perf-short, state-not-run, log-leak, log-path.
check() {
	local label=$1 want=$2 dir=$3 mut=${4:-} zip="$WORK/candidate-test.zip" out="$WORK/out-$RANDOM.md" sha version state combo suite
	rm -f "$zip" "$WORK"/*.log
	(cd "$dir" && zip -qr "$zip" .)
	sha=$(shasum -a 256 "$zip" | awk '{print $1}')
	version=$HEAD_VERSION
	[ "$mut" == bad-sha ] && sha=0000000000000000000000000000000000000000000000000000000000000000
	[ "$mut" == stale-version ] && version=1.0.0
	{
		printf 'ZIP=%s\nZIP_SHA256=%s\nVERSION=%s\nCOMMIT=%s\nWINDOW_FILE=\nPERF_FILE=%s\n' "$zip" "$sha" "$version" "$COMMIT" "$([ "$mut" == no-perf ] || echo "$WORK/perf.json")"
		for state in recommended easy-2.3.1 easy-2.4.0 both none; do
			if [ "$mut" == state-not-run ] && [ "$state" == both ]; then
				echo "STATE=$state|NOT RUN|0|1|||[]"
				continue
			fi
			echo "STATE=$state|PASSED|10|0|6.9|8.3|[]"
			[ "$state" == none ] && continue
			for combo in hpos:no hpos:yes legacy:yes legacy:no; do
				for suite in test-payment-orders.php test-payment-health.php; do
					[ "$mut" == no-storage ] && [ "$combo" == legacy:yes ] && continue
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
	perf-jobs-not-required) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 | jq '.exact_jobs_required = false' >"$WORK/perf.json" ;;
	*) perf_receipt "$(shasum -a 256 "$zip" | awk '{print $1}')" 900 >"$WORK/perf.json" ;;
	esac
	[ "$mut" == log-leak ] && echo 'FAIL  [both] a check that echoed jane.citizen@realmail.com' >"$WORK/both-http.log"
	[ "$mut" == log-path ] && echo "FAIL  [both] wp-env start (log: $HOME/private/blzmcp-matrix-logs.XYZ/both-start.log)" >"$WORK/both-http.log"
	LAST_OUT=$out
	BLZMCP_MATRIX_OUT=$out BLZMCP_REPORTS_DIR=$WORK bash "$REPO/tests/matrix/record.sh" "$WORK/summary.txt" PASSED >"$WORK/log" 2>&1
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
BLZMCP_REPORTS_DIR=$WORK bash "$REPO/tests/matrix/record.sh" "$WORK/summary.txt" PASSED >"$WORK/log" 2>&1
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
check 'a state that did not run is refused' 5 "$WORK/good" state-not-run

# Privacy.
check 'an e-mail address in a copied log line refuses the record' 5 "$WORK/good" log-leak
assert 'a refused record is not written' "$([ ! -e "$LAST_OUT" ] && echo true)"
check 'a local path in a copied log line is replaced, then accepted' 0 "$WORK/good" log-path
assert 'the record shows <path>, never the home directory' "$(grep -q '<path>' "$LAST_OUT" && ! grep -qF "$HOME" "$LAST_OUT" && echo true)"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
