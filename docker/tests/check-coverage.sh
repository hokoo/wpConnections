#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT

make_clover() {
  local covered="$1"
  local total="$2"

  printf '%s\n' \
    '<?xml version="1.0"?>' \
    '<coverage>' \
    '  <project>' \
    "    <metrics statements=\"${total}\" coveredstatements=\"${covered}\"/>" \
    '  </project>' \
    '</coverage>' > "${work_dir}/clover.xml"
}

run_case() {
  local name="$1"
  local expected_exit="$2"
  local profile="$3"
  local covered="$4"
  local total="$5"
  local actual_exit=0
  local -a profile_argument=()

  make_clover "${covered}" "${total}"

  if [ "${profile}" != "default" ]; then
    profile_argument+=("--profile=${profile}")
  fi

  php "${project_root}/docker/check-coverage.php" \
    "${profile_argument[@]}" \
    "${work_dir}/clover.xml" \
    "${project_root}/coverage-baseline.json" \
    "${work_dir}/summary.json" \
    "${work_dir}/summary.md" > "${work_dir}/output.txt" 2>&1 || actual_exit=$?

  if [ "${actual_exit}" -ne "${expected_exit}" ]; then
    echo "Coverage synthetic case ${name} returned ${actual_exit}; expected ${expected_exit}" >&2
    cat "${work_dir}/output.txt" >&2
    exit 1
  fi

  echo "Coverage synthetic case ${name}: exit ${actual_exit}"
}

run_case pr-default-baseline-pass 0 default 365 786
grep -Fq 'Release candidate coverage: NOT READY' "${work_dir}/output.txt"
php -r '
    $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    exit($data["profile"] === "pr" && $data["status"] === "passed" && ! $data["release_candidate"]["coverage_ready"] ? 0 : 1);
' "${work_dir}/summary.json"

run_case pr-regression 1 pr 364 786
run_case rc-below-70 1 rc 69 100
run_case rc-equal-70 0 rc 70 100
run_case rc-above-70 0 rc 71 100

printf '%s\n' '<coverage><project></coverage>' > "${work_dir}/clover.xml"
invalid_exit=0
php "${project_root}/docker/check-coverage.php" \
  --profile=pr \
  "${work_dir}/clover.xml" \
  "${project_root}/coverage-baseline.json" \
  "${work_dir}/summary.json" \
  "${work_dir}/summary.md" > "${work_dir}/output.txt" 2>&1 || invalid_exit=$?

if [ "${invalid_exit}" -ne 2 ]; then
  echo "Malformed coverage input returned ${invalid_exit}; expected configuration exit 2" >&2
  cat "${work_dir}/output.txt" >&2
  exit 1
fi

echo "Coverage synthetic case malformed-input: exit ${invalid_exit}"
echo "Coverage policy synthetic tests passed"
