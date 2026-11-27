#!/usr/bin/env bash
# Stand-in for wp-env, used only by tests/matrix/test-perf-suite.sh: answers the `wp-env run cli
# wp ...` calls perf-suite.sh makes, so its gate (trial validity, forced reader failures, jobs
# store, receipt) runs without Docker. No WordPress, no network; state lives in $FAKE_DIR.
#
# FAKE_MODE: good | invalid-<orders|logs|jobs|outside> (one timed hpos 24h db trial reports that reader
# failed) | cap-fail (the 1,001-ID method-cap case on hpos fails its assertions) | cap-silent (no
# CAP line for it) | no-rows (the trials carry no rows examined/selected) | undetected-<orders|logs|jobs> (that forced probe comes back as a success) |
# hybrid (the measuring process sees ActionScheduler_HybridStore) | cleanup-leftover (the
# post-storage cleanup leaves 3 tagged orders) | cleanup-short (it removes one order fewer than
# were seeded) | cleanup-silent (no CLEANUP result) | diag-leftover (diagnostics cleanup leaves 2
# fixture jobs) | restore-fail (the final switch back to the original storage fails).
#
# 1.3 card workload (tests/matrix/test-card-testing-perf-suite.sh; the modes above that are about
# cleanup and the storage restore apply to it too): card-seed-fail (the first hpos card-orders
# chunk fails) | card-verify-fail (card-verify on hpos is not ok) | card-undetected-<orders|logs>
# (that forced probe comes back as a success) | card-bound-fail (the hpos 5,001-network bound case
# fails its assertions) | card-interrupt (the first hpos trial touches $FAKE_DIR/interrupt-ready
# and waits 2 s, so the test can interrupt the suite there). FAKE_CARD_TIMES, a list of
# <storage>/<window>/<logger>=<t1>,<t2>,... entries, sets the n-th normal trial's elapsed_ms of
# that partition ("invalid" makes it fail its orders reader, "null" drops its elapsed_ms).
set -uo pipefail

FAKE_DIR=${FAKE_DIR:?FAKE_DIR}
MODE=${FAKE_MODE:-good}
[ "${1:-}" == run ] && [ "${2:-}" == cli ] || { echo "fake-wp-env: only 'run cli' is supported" >&2; exit 2; }
shift 2
set_storage=''
if [ "${1:-}" == env ]; then
	shift
	while [ $# -gt 0 ] && [ "$1" != wp ]; do
		case "$1" in BLZMCP_PF_SET=*) set_storage=${1#BLZMCP_PF_SET=} ;; esac
		shift
	done
fi
[ "${1:-}" == wp ] && shift
storage=$(cat "$FAKE_DIR/storage" 2>/dev/null || echo legacy)
logger=$(cat "$FAKE_DIR/logger" 2>/dev/null || echo file)

case "${1:-} ${2:-}" in
"option get") echo no; exit 0 ;;
"action-scheduler migrate") echo "Success: migration complete"; exit 0 ;;
esac
[ "${1:-}" == eval-file ] || { echo "fake-wp-env: unsupported wp $*" >&2; exit 2; }
script=$(basename "$2")
shift 2
case "$script" in
test-payment-orders.php)
	# restore-fail: the only hpos -> legacy switch in "legacy hpos" order is the final restore.
	if [ "$MODE" == restore-fail ] && [ "$storage" == hpos ] && [ "$set_storage" == legacy ]; then
		echo "0 passed, 1 failed"
		exit 1
	fi
	[ -n "$set_storage" ] && echo "$set_storage" >"$FAKE_DIR/storage"
	echo "1 passed, 0 failed"
	;;
payment-fixture.php) echo "CLEARED=1" ;;
seed-payment-volume.php)
	case "$1" in
	orders | old | outside)
		echo "$3" >"$FAKE_DIR/seeded.$1.$2"
		echo "SEEDED=$3"
		;;
	cap)
		n=$3
		[ "$2" == 0 ] && n=$(($3 + 24))
		echo "$n" >"$FAKE_DIR/seeded.$1.$2"
		echo "SEEDED=$n"
		;;
	verify) echo "VERIFY={\"ok\":true,\"authority\":\"$storage\",\"orders\":$(($2 + $3 + ${4:-0})),\"checkout\":$(($2 + $3)),\"outside\":${4:-0},\"untagged\":0}" ;;
	diag)
		echo "$2" >"$FAKE_DIR/logger"
		echo "DIAG={\"handler\":\"$2\",\"failed_jobs\":40}"
		;;
	cleanup)
		seeded=0
		if compgen -G "$FAKE_DIR/seeded.*" >/dev/null; then
			seeded=$(cat "$FAKE_DIR"/seeded.* | paste -sd+ - | bc)
			rm -f "$FAKE_DIR"/seeded.*
		fi
		left=0
		removed=$seeded
		[ "$MODE" == cleanup-leftover ] && [ "$seeded" -gt 0 ] && left=3 && removed=$((seeded - 3))
		[ "$MODE" == cleanup-short ] && [ "$seeded" -gt 0 ] && removed=$((seeded - 1))
		echo "REMOVED=$removed"
		[ "$MODE" == cleanup-silent ] && exit 0
		echo "CLEANUP={\"mode\":\"orders\",\"orders_removed\":$removed,\"tagged_remaining\":$left,\"diag_log_rows_remaining\":0,\"diag_log_files_remaining\":0,\"diag_jobs_remaining\":0,\"settings_restored\":true,\"ok\":$([ "$left" == 0 ] && echo true || echo false)}"
		;;
	diag-cleanup)
		jobs=0
		[ "$MODE" == diag-leftover ] && jobs=2
		echo "REMOVED=diagnostics"
		echo "CLEANUP={\"mode\":\"diagnostics\",\"orders_removed\":null,\"tagged_remaining\":0,\"diag_log_rows_remaining\":0,\"diag_log_files_remaining\":0,\"diag_jobs_remaining\":$jobs,\"settings_restored\":true,\"ok\":$([ "$jobs" == 0 ] && echo true || echo false)}"
		;;
	distribution) echo 'DISTRIBUTION={"cycle":20}' ;;
	card-settings) echo 'CARD_SETTINGS={"ok":true,"options":["woocommerce_stripe_settings","woocommerce_woocommerce_payments_settings","woocommerce_square_credit_card_settings","woocommerce_feature_order_attribution_enabled"],"kinds":{"stripe":"instant","woocommerce_payments":"instant","square_credit_card":"instant"},"stripe_mode":"live"}' ;;
	card-orders | card-old | card-cap-methods | card-cap-networks)
		if [ "$MODE" == card-seed-fail ] && [ "$1" == card-orders ] && [ "$2" == 0 ] && [ "$storage" == hpos ]; then
			echo "seeding failed" >&2
			exit 1
		fi
		echo "$3" >"$FAKE_DIR/seeded.$1.$2"
		echo "SEEDED=$3"
		;;
	card-verify)
		ok=true
		[ "$MODE" == card-verify-fail ] && [ "$storage" == hpos ] && ok=false
		echo "CARD_VERIFY={\"ok\":$ok,\"authority\":\"$storage\",\"orders\":$(($2 + $3)),\"expected\":$(($2 + $3)),\"recent\":$2,\"old\":$3,\"crud_sample\":35,\"crud_ok\":35,\"crud_mismatch\":[],\"untagged\":0,\"checks\":{\"orders\":true,\"crud\":$ok}}"
		;;
	card-distribution) echo "CARD_DISTRIBUTION={\"orders\":$2,\"old_orders\":$3,\"methods\":[\"stripe\",\"woocommerce_payments\",\"square_credit_card\"],\"drafts\":3486,\"missing_origin\":2848,\"store_api_origin\":4200,\"other_origin\":1400,\"bursts\":{\"a\":150,\"b\":150,\"c\":60}}" ;;
	esac
	;;
measure-card-testing.php)
	case "$1" in
	trial)
		window=$2 force=${7:-}
		readers='{"response":true,"workload":true,"orders":true,"methods":true,"signals":true,"logs":true}'
		if [ -n "$force" ]; then
			if [ "$MODE" == "card-undetected-$force" ]; then
				echo "MEASURE={\"ok\":true,\"forced\":\"$force\",\"detected\":false,\"elapsed_ms\":90,\"validation\":{\"readers\":$readers,\"failures\":[]}}"
			else
				echo "MEASURE={\"ok\":false,\"forced\":\"$force\",\"detected\":true,\"elapsed_ms\":90,\"validation\":{\"readers\":$(jq -c --arg r "$force" '.[$r] = false' <<<"$readers"),\"failures\":[\"$force: forced\"]}}"
			fi
			exit 0
		fi
		cf="$FAKE_DIR/count.$storage.$window.$logger"
		n=$(($(cat "$cf" 2>/dev/null || echo 0) + 1))
		echo "$n" >"$cf"
		if [ "$MODE" == card-interrupt ] && [ "$storage" == hpos ] && [ ! -f "$FAKE_DIR/interrupt-ready" ]; then
			: >"$FAKE_DIR/interrupt-ready"
			sleep 2
		fi
		v=''
		for e in ${FAKE_CARD_TIMES:-}; do [ "${e%%=*}" == "$storage/$window/$logger" ] && v=$(cut -d, -f"$n" <<<"${e#*=}"); done
		m="{\"ok\":true,\"forced\":null,\"detected\":null,\"since_hours\":$window,\"elapsed_ms\":120,\"warm_ms\":1.5,\"queries\":90,\"rows_examined\":64000,\"rows_selected\":12000,\"meta_rows\":63000,\"peak_memory_delta_bytes\":9000000,\"verdict\":\"likely_attack\",\"coverage\":\"partial\",\"slowest\":[{\"ms\":40,\"sql\":\"SELECT ?\",\"plan\":[{\"table\":\"o\",\"type\":\"range\",\"key\":\"k\",\"rows\":64000,\"extra\":null}]}],\"validation\":{\"readers\":$readers,\"failures\":[]},\"versions\":{\"php\":\"8.3.0\",\"wp\":\"7.1.5\",\"wc\":\"11.3.1\",\"db\":\"11.4.0-MariaDB\",\"cache\":\"none\"}}"
		case "$v" in
		'') ;;
		invalid) m=$(jq -c '.ok = false | .validation.readers.orders = false | .validation.failures = ["orders: totals.failed_unpaid 3, expected 4"]' <<<"$m") ;;
		null) m=$(jq -c 'del(.elapsed_ms)' <<<"$m") ;;
		*) m=$(jq -c --argjson v "$v" '.elapsed_ms = $v' <<<"$m") ;;
		esac
		echo "MEASURE=$m"
		;;
	bound)
		ok=true
		[ "$MODE" == card-bound-fail ] && [ "$storage" == hpos ] && [ "$2" == networks ] && [ "$3" == 5001 ] && ok=false
		echo "BOUND={\"case\":\"$2\",\"k\":$3,\"ok\":$ok,\"failures\":$([ "$ok" == true ] && echo '[]' || echo '["above the cap: network_cap limitation no"]'),\"observed\":{\"coverage\":\"partial\"},\"elapsed_ms\":800,\"queries\":1100,\"counted_as_trial\":false}"
		;;
	workflow) echo 'WORKFLOW={"ok":true,"plan_ok":true,"steps":[{"ability":"blaze-mcp-toolkit/payment-health","elapsed_ms":300,"ok":true},{"ability":"blaze-mcp-toolkit/card-testing","elapsed_ms":400,"ok":true},{"ability":"wc-analytics/totals","elapsed_ms":50,"ok":false,"error":"analytics_unavailable"}],"total_ms":750,"pooled_budget":false}' ;;
	esac
	;;
measure-outside-cap.php)
	k=$1
	[ "$MODE" == cap-silent ] && [ "$storage" == hpos ] && [ "$k" == 1001 ] && exit 0
	ok=true
	[ "$MODE" == cap-fail ] && [ "$storage" == hpos ] && [ "$k" == 1001 ] && ok=false
	listed=$k
	[ "$k" -gt 1000 ] && listed=1000
	echo "CAP={\"ok\":$ok,\"distinct_ids\":$k,\"failures\":$([ "$ok" == true ] && echo '[]' || echo '["above the cap: methods_truncated false"]'),\"listed\":$listed,\"methods_truncated\":$([ "$k" -gt 1000 ] && echo true || echo false),\"inventory_coverage\":\"partial\",\"elapsed_ms\":300,\"queries\":40}"
	;;
measure-payment-health.php)
	window=$1 exact=$6 force=${7:-}
	store=ActionScheduler_DBStore
	[ "$MODE" == hybrid ] && store=ActionScheduler_HybridStore
	readers='{"orders":true,"inventory":true,"logs":true,"jobs":true,"outside":true}'
	ok=true
	if [ "$MODE" == hybrid ] && [ "$exact" == 1 ]; then
		ok=false
		readers='{"orders":true,"inventory":true,"logs":true,"jobs":false,"outside":true}'
	fi
	if [ -z "$force" ] && [ "$MODE" == "invalid-${MODE#invalid-}" ] && [ "$storage" == hpos ] && [ "$window" == 24 ] && [ "$logger" == db ]; then
		ok=false
		readers=$(jq -c --arg r "${MODE#invalid-}" '.[$r] = false' <<<"$readers")
	fi
	if [ -n "$force" ]; then
		if [ "$MODE" == "undetected-$force" ]; then
			echo "MEASURE={\"ok\":true,\"forced\":\"$force\",\"detected\":false,\"elapsed_ms\":90,\"action_store\":\"$store\",\"validation\":{\"readers\":$readers,\"failures\":[]}}"
		else
			echo "MEASURE={\"ok\":false,\"forced\":\"$force\",\"detected\":true,\"elapsed_ms\":90,\"action_store\":\"$store\",\"validation\":{\"readers\":$(jq -c --arg r "$force" '.[$r] = false' <<<"$readers"),\"failures\":[\"$force: forced\"]}}"
		fi
		exit 0
	fi
	rows='"rows_examined":61000,"rows_selected":2100,'
	[ "$MODE" == no-rows ] && rows=''
	echo "MEASURE={\"ok\":$ok,\"forced\":null,\"detected\":null,\"elapsed_ms\":120,\"warm_ms\":1,\"queries\":130,$rows\"peak_memory_delta_bytes\":4000000,\"slowest\":[{\"ms\":40,\"sql\":\"SELECT ?\",\"plan\":[{\"table\":\"o\",\"type\":\"range\",\"key\":\"k\",\"rows\":61000,\"extra\":null}]}],\"coverage\":\"partial\",\"action_store\":\"$store\",\"validation\":{\"readers\":$readers,\"failures\":$([ "$ok" == true ] && echo '[]' || echo '["reader failed"]')},\"versions\":{\"php\":\"8.3\"}}"
	;;
*) echo "fake-wp-env: unsupported script $script" >&2; exit 2 ;;
esac
