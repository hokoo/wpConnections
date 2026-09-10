#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 3 ]; then
  echo "Usage: docker/run-isolation.sh <suite-label> <phpunit-config> <seed> [phpunit arguments...]" >&2
  exit 2
fi

suite_label="$1"
phpunit_config="$2"
random_seed="$3"
shift 3

repeat_count="${ISOLATION_REPEAT:-2}"

if [ ! -r "${phpunit_config}" ]; then
  echo "Isolation configuration is not readable: ${phpunit_config}" >&2
  exit 2
fi

if [[ ! "${random_seed}" =~ ^[0-9]+$ ]]; then
  echo "Isolation seed must be a non-negative integer; got ${random_seed}" >&2
  exit 2
fi

if [[ ! "${repeat_count}" =~ ^[0-9]+$ ]] || [ "${repeat_count}" -lt 2 ]; then
  echo "ISOLATION_REPEAT must be an integer greater than or equal to 2; got ${repeat_count}" >&2
  exit 2
fi

run_phase() {
  local order="$1"
  shift
  local -a command=(
    vendor/bin/phpunit
    -c "${phpunit_config}"
    --colors=never
    --do-not-cache-result
    "--order-by=${order}"
    "--repeat=${repeat_count}"
  )

  if [ "${order}" = "random" ]; then
    command+=("--random-order-seed=${random_seed}")
  fi

  command+=("$@")

  echo
  echo "Isolation phase: suite=${suite_label} order=${order} repeats=${repeat_count} seed=${random_seed}"
  printf 'Command:'
  printf ' %q' "${command[@]}"
  printf '\n'

  # A failing phase is returned immediately. Repeats are deliberate state-leak
  # probes, never retries that replace a failure with a later green result.
  "${command[@]}"
}

echo "Isolation seed: ${random_seed}"
run_phase reverse "$@"
run_phase random "$@"
