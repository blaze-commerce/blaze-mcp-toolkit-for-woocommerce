#!/usr/bin/env bash
# Stand-in for wp-env, used only by tests/matrix/test-perf-suite.sh: answers the `wp-env run cli
# wp ...` calls perf-suite.sh makes, so its gate (trial validity, forced reader failures, jobs
# store, receipt) runs without Docker. No WordPress, no network; state lives in $FAKE_DIR.
#
# FAKE_MODE: good | invalid-<orders|logs|jobs> (one timed hpos 24h db trial reports that reader
# failed) | undetected-<orders|logs|jobs> (that forced probe comes back as a success) |
# hybrid (the measuring process sees ActionScheduler_HybridStore).
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
	[ -n "$set_storage" ] && echo "$set_storage" >"$FAKE_DIR/storage"
	echo "1 passed, 0 failed"
	;;
payment-fixture.php) echo "CLEARED=1" ;;
seed-payment-volume.php)
	case "$1" in
	orders | old) echo "SEEDED=$3" ;;
	verify) echo "VERIFY={\"ok\":true,\"authority\":\"$storage\",\"orders\":$(($2 + $3)),\"untagged\":0}" ;;
	diag)
		echo "$2" >"$FAKE_DIR/logger"
		echo "DIAG={\"handler\":\"$2\",\"failed_jobs\":40}"
		;;
	cleanup | diag-cleanup) echo "REMOVED=0" ;;
	distribution) echo 'DISTRIBUTION={"cycle":20}' ;;
	esac
	;;
measure-payment-health.php)
	window=$1 exact=$5 force=${6:-}
	store=ActionScheduler_DBStore
	[ "$MODE" == hybrid ] && store=ActionScheduler_HybridStore
	readers='{"orders":true,"inventory":true,"logs":true,"jobs":true}'
	ok=true
	if [ "$MODE" == hybrid ] && [ "$exact" == 1 ]; then
		ok=false
		readers='{"orders":true,"inventory":true,"logs":true,"jobs":false}'
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
	echo "MEASURE={\"ok\":$ok,\"forced\":null,\"detected\":null,\"elapsed_ms\":120,\"warm_ms\":1,\"queries\":130,\"coverage\":\"partial\",\"action_store\":\"$store\",\"validation\":{\"readers\":$readers,\"failures\":$([ "$ok" == true ] && echo '[]' || echo '["reader failed"]')},\"versions\":{\"php\":\"8.3\"}}"
	;;
*) echo "fake-wp-env: unsupported script $script" >&2; exit 2 ;;
esac
