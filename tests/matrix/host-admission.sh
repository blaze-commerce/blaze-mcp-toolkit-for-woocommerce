# shellcheck shell=bash
# Host admission for the real-plugin matrix (ruling Q8, REVIEW-pass4e; it replaces Q7's absolute
# load < 2 bound). Sourced by tests/matrix/run-matrix.sh and tests/matrix/record.sh; it only
# defines functions. It is always on: no variable switches it on or off, and the bounds are fixed.
#
# - Logical CPU count N: `sysctl -n hw.ncpu` (macOS), else `nproc` (Linux).
# - load1/load5: `sysctl -n vm.loadavg` (macOS), else /proc/loadavg (Linux).
# - Admitted when load5 < 0.25 x N AND load1 < 0.33 x N, both strict and computed exactly (decimal
#   fixed point, never rounded; N = 12 gives load5 < 3.00 and load1 < 3.96).
# - ha_wait_admitted samples every 60 s until admitted, inside the same run, and gives up after
#   12 hours with HOST ADMISSION NOT REACHED (a failure, never a pass).
# - ha_sampler_start appends `<UTC> host=<hostname> ncpu=<N> load1=<x> load5=<y>` every 60 s to a
#   file until ha_sampler_stop (or until the matrix process is gone).
# - ha_foreign_wpenv refuses any running wp-env WordPress container on a host port outside the
#   matrix states' own 8890-8903 (Q8: the owner's 8888 environment counts; a separate checkout or
#   port is not an exemption, and a container does not say which checkout it serves).
#
# Load average divided by logical CPUs is not CPU utilisation and does not prove timing isolation;
# in-run excursions are recorded as diagnostics and never excuse a slow valid trial.
#
# The interval and give-up arguments exist for the offline self-test
# (tests/matrix/test-host-admission.sh); run-matrix.sh always uses the defaults.

HA_INTERVAL_DEFAULT=60
HA_MAX_WAIT_DEFAULT=43200 # 12 hours
HA_MATRIX_PORT_MIN=8890
HA_MATRIX_PORT_MAX=8903
# Linux load source; the self-test points it at a stub file. The recorder only accepts evidence
# whose load source is `sysctl vm.loadavg` or `/proc/loadavg`.
HA_PROC_LOADAVG=${HA_PROC_LOADAVG:-/proc/loadavg}

# Logical CPU count (positive integer) or return 1.
ha_ncpu() {
	local n
	n=$(sysctl -n hw.ncpu 2>/dev/null | tr -d '[:space:]')
	case "$n" in '' | *[!0-9]*) n=$(nproc 2>/dev/null | tr -d '[:space:]') ;; esac
	case "$n" in '' | *[!0-9]*) return 1 ;; esac
	[ "$((10#$n))" -gt 0 ] || return 1
	echo "$((10#$n))"
}

# "<load1> <load5>" or return 1.
ha_load() {
	local raw
	raw=$(sysctl -n vm.loadavg 2>/dev/null) && [ -n "$raw" ] || raw=$(cat "$HA_PROC_LOADAVG" 2>/dev/null) || return 1
	# macOS: "{ 3.76 2.62 2.40 }"; Linux: "3.76 2.62 2.40 1/234 5678".
	# shellcheck disable=SC2046 # word splitting into the fields is intended.
	set -- $(printf '%s\n' "$raw" | tr -d '{}')
	[ "$#" -ge 2 ] && ha_is_decimal "$1" && ha_is_decimal "$2" || return 1
	echo "$1 $2"
}

# Where ha_load read the load average from.
ha_load_source() {
	if [ -n "$(sysctl -n vm.loadavg 2>/dev/null)" ]; then echo 'sysctl vm.loadavg'; else echo "$HA_PROC_LOADAVG"; fi
}

ha_is_decimal() { [[ "$1" =~ ^[0-9]+(\.[0-9]+)?$ ]]; }

# A non-negative decimal as an integer in millionths (truncated past 6 places: exact for strict
# comparison against bounds with 2 decimal places).
ha_micro() {
	local int=${1%%.*} frac=''
	[[ "$1" == *.* ]] && frac=${1#*.}
	frac="${frac}000000"
	frac=${frac:0:6}
	echo $((10#$int * 1000000 + 10#$frac))
}

# "<load5 bound> <load1 bound>" for N, exact: 0.25 x N and 0.33 x N with 2 decimals.
ha_bounds() {
	local n=$1
	printf '%d.%02d %d.%02d\n' $((25 * n / 100)) $((25 * n % 100)) $((33 * n / 100)) $((33 * n % 100))
}

# ha_admits N load1 load5: 0 when load5 < 0.25 x N and load1 < 0.33 x N (strict).
ha_admits() {
	local n=$1
	ha_is_decimal "$2" && ha_is_decimal "$3" || return 1
	[ "$(ha_micro "$3")" -lt $((25 * n * 10000)) ] && [ "$(ha_micro "$2")" -lt $((33 * n * 10000)) ]
}

ha_hostname() {
	local h
	h=$(hostname -s 2>/dev/null || hostname 2>/dev/null)
	h=$(printf '%s' "$h" | tr -c 'A-Za-z0-9._-' '_')
	echo "${h:-unknown}"
}

ha_cpu_model() {
	local m
	m=$(sysctl -n machdep.cpu.brand_string 2>/dev/null)
	[ -n "$m" ] || m=$(awk -F': *' '/^model name/ {print $2; exit}' /proc/cpuinfo 2>/dev/null)
	printf '%s' "${m:-unknown}" | tr '|\n' '/ '
}

# "HOST=" summary value: hostname|N|CPU model|load source.
ha_identity() {
	echo "$(ha_hostname)|$1|$(ha_cpu_model)|$(ha_load_source)"
}

# ha_sample_line N: one sample line, or return 1 when the load is unreadable.
ha_sample_line() {
	local n=$1 l
	l=$(ha_load) || return 1
	echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) host=$(ha_hostname) ncpu=$n load1=${l%% *} load5=${l##* }"
}

# ha_wait_admitted PHASE SAMPLES_FILE [INTERVAL] [MAX_WAIT]
# Samples now and then every INTERVAL seconds (default 60) until admitted; every sample is appended
# to SAMPLES_FILE with " admission=PHASE". Admitted: prints the ADMISSION line, sets HA_ADMISSION
# (phase|UTC|host|N|load1|load5|waited seconds|samples) and returns 0. Gives up after MAX_WAIT
# seconds (default 12 hours) with HOST ADMISSION NOT REACHED and returns 1.
ha_wait_admitted() {
	local phase=$1 file=$2 interval=${3:-$HA_INTERVAL_DEFAULT} max=${4:-$HA_MAX_WAIT_DEFAULT}
	local n b5 b1 line l1 l5 utc host k=0 waited=0 b
	HA_ADMISSION=''
	n=$(ha_ncpu) || {
		echo "HOST ADMISSION NOT REACHED ($phase): the logical CPU count is unavailable (sysctl hw.ncpu / nproc)"
		return 1
	}
	b=$(ha_bounds "$n")
	b5=${b%% *}
	b1=${b##* }
	while :; do
		line=$(ha_sample_line "$n") || {
			echo "HOST ADMISSION NOT REACHED ($phase): the load average is unreadable (sysctl vm.loadavg / $HA_PROC_LOADAVG)"
			return 1
		}
		k=$((k + 1))
		echo "$line admission=$phase" >>"$file"
		utc=${line%% *}
		host=$(sed -n 's/.* host=\([^ ]*\).*/\1/p' <<<"$line")
		l1=$(sed -n 's/.* load1=\([^ ]*\).*/\1/p' <<<"$line")
		l5=$(sed -n 's/.* load5=\([^ ]*\).*/\1/p' <<<"$line")
		if ha_admits "$n" "$l1" "$l5"; then
			echo "ADMISSION $phase $line (admitted: load5 < $b5 and load1 < $b1; waited ${waited}s, $k sample(s))"
			# shellcheck disable=SC2034 # read by the caller (run-matrix.sh).
			HA_ADMISSION="$phase|$utc|$host|$n|$l1|$l5|$waited|$k"
			return 0
		fi
		if [ "$waited" -ge "$max" ]; then
			echo "HOST ADMISSION NOT REACHED ($phase) after ${waited}s and $k samples: last $line; needs load5 < $b5 and load1 < $b1 (ruling Q8)"
			return 1
		fi
		echo "HOST ADMISSION WAIT ($phase): $line; needs load5 < $b5 and load1 < $b1; next sample in ${interval}s"
		# In the background and waited for, so an INT/TERM trap in the caller runs at once.
		sleep "$interval" &
		HA_SLEEP_PID=$!
		wait "$HA_SLEEP_PID"
		HA_SLEEP_PID=''
		waited=$((waited + interval))
	done
}

# ha_sampler_start FILE [INTERVAL]: background sampler, one line now and every INTERVAL seconds.
# It stops on ha_sampler_stop or when the process that started it is gone. Sets HA_SAMPLER_PID.
ha_sampler_start() {
	local file=$1 interval=${2:-$HA_INTERVAL_DEFAULT} parent=$$ n
	n=$(ha_ncpu) || return 1
	(
		trap - EXIT INT
		sp=''
		trap '[ -n "$sp" ] && kill "$sp" 2>/dev/null; exit 0' TERM
		while kill -0 "$parent" 2>/dev/null; do
			ha_sample_line "$n" >>"$file"
			sleep "$interval" &
			sp=$!
			wait "$sp"
			sp=''
		done
	) >/dev/null 2>&1 &
	HA_SAMPLER_PID=$!
}

# Stops the background sampler and any admission wait's pending sleep (every exit path of the
# matrix calls it: EXIT, INT and TERM traps).
ha_sampler_stop() {
	if [ -n "${HA_SLEEP_PID:-}" ]; then
		kill "$HA_SLEEP_PID" 2>/dev/null
		HA_SLEEP_PID=''
	fi
	[ -n "${HA_SAMPLER_PID:-}" ] || return 0
	kill "$HA_SAMPLER_PID" 2>/dev/null
	wait "$HA_SAMPLER_PID" 2>/dev/null
	HA_SAMPLER_PID=''
	return 0
}

# ha_samples_stats FILE N: "<samples> <max load1> <max load5> <samples above the bounds>".
ha_samples_stats() {
	local file=$1 n=$2 count=0 over=0 m1=0 m5=0 l1 l5 line
	if [ -f "$file" ]; then
		while IFS= read -r line; do
			l1=$(sed -n 's/.* load1=\([^ ]*\).*/\1/p' <<<"$line")
			l5=$(sed -n 's/.* load5=\([^ ]*\).*/\1/p' <<<"$line")
			ha_is_decimal "$l1" && ha_is_decimal "$l5" || continue
			count=$((count + 1))
			[ "$(ha_micro "$l1")" -gt "$(ha_micro "$m1")" ] && m1=$l1
			[ "$(ha_micro "$l5")" -gt "$(ha_micro "$m5")" ] && m5=$l5
			ha_admits "$n" "$l1" "$l5" || over=$((over + 1))
		done <"$file"
	fi
	echo "$count $m1 $m5 $over"
}

# ha_foreign_wpenv: 0 when no wp-env WordPress container listens on a host port outside 8890-8903.
# 1 with "<container>:<port> ..." on stdout when one does; 2 when the containers cannot be listed.
ha_foreign_wpenv() {
	local out name ports p bad=''
	out=$(docker ps --format '{{.Names}}|{{.Ports}}' 2>/dev/null) || {
		echo 'docker ps failed (the running containers cannot be listed)'
		return 2
	}
	while IFS='|' read -r name ports; do
		case "$name" in *[-_]wordpress[-_]1) ;; *) continue ;; esac
		for p in $(printf '%s\n' "$ports" | grep -oE ':[0-9]+->' | sed 's/[^0-9]//g' | sort -u); do
			if [ "$p" -lt "$HA_MATRIX_PORT_MIN" ] || [ "$p" -gt "$HA_MATRIX_PORT_MAX" ]; then
				bad="$bad $name:$p"
			fi
		done
	done <<<"$out"
	[ -z "$bad" ] && return 0
	echo "${bad# }"
	return 1
}
