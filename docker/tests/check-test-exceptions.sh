#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT

run_case() {
  local name="$1"
  local expected_exit="$2"
  local profile="$3"
  local registry="$4"
  local actual_exit=0

  php "${project_root}/docker/check-test-exceptions.php" \
    "--profile=${profile}" \
    "${registry}" > "${work_dir}/output.txt" 2>&1 || actual_exit=$?

  if [ "${actual_exit}" -ne "${expected_exit}" ]; then
    echo "Exception policy case ${name} returned ${actual_exit}; expected ${expected_exit}" >&2
    cat "${work_dir}/output.txt" >&2
    exit 1
  fi

  echo "Exception policy case ${name}: exit ${actual_exit}"
}

run_case empty-pr 0 pr "${project_root}/test-quality-exceptions.json"
run_case empty-rc 0 rc "${project_root}/test-quality-exceptions.json"

cat > "${work_dir}/critical.json" <<'JSON'
{
  "schema": 1,
  "exceptions": [
    {
      "id": "TQ-EX-001",
      "scenario_ids": ["QUERY-BOTH-01"],
      "test": "tests/example.php::testExample",
      "owner": "maintainers",
      "reason": "Reproduced environment-specific state leak blocks a safe immediate repair.",
      "issue": "https://example.com/issues/1",
      "expires_on": "2999-12-31",
      "exit_condition": "The recorded seed passes twice in every protected lane.",
      "scope": "integration lane, seed 12345, exact test filter",
      "approved_by": "merge-owner"
    }
  ]
}
JSON

run_case active-critical-pr 0 pr "${work_dir}/critical.json"
run_case active-critical-rc 1 rc "${work_dir}/critical.json"
grep -Fq 'Release candidate blocked by active critical exceptions: TQ-EX-001' "${work_dir}/output.txt"

php -r '
    $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $data["exceptions"][0]["unexpected"] = "not allowed";
    file_put_contents($argv[2], json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
' "${work_dir}/critical.json" "${work_dir}/extra-field.json"
run_case extra-field-record 2 pr "${work_dir}/extra-field.json"

cat > "${work_dir}/non-critical.json" <<'JSON'
{
  "schema": 1,
  "exceptions": [
    {
      "id": "TQ-EX-002",
      "scenario_ids": ["none"],
      "test": "tests/example.php::testExample",
      "owner": "maintainers",
      "reason": "Reproduced tooling-only instability blocks a safe immediate repair.",
      "issue": "https://example.com/issues/2",
      "expires_on": "2999-12-31",
      "exit_condition": "The tooling probe passes twice in every protected lane.",
      "scope": "tooling probe, seed 54321, exact test filter",
      "approved_by": "merge-owner"
    }
  ]
}
JSON

run_case active-non-critical-rc 0 rc "${work_dir}/non-critical.json"
grep -Fq 'Release-owner review required for non-critical exceptions: TQ-EX-002' "${work_dir}/output.txt"

cat > "${work_dir}/incomplete.json" <<'JSON'
{
  "schema": 1,
  "exceptions": [
    {
      "id": "TQ-EX-003",
      "scenario_ids": ["QUERY-BOTH-01"],
      "test": "tests/example.php::testExample",
      "reason": "Owner is intentionally missing from this invalid fixture.",
      "issue": "https://example.com/issues/3",
      "expires_on": "2999-12-31",
      "exit_condition": "Never",
      "scope": "invalid fixture",
      "approved_by": "merge-owner"
    }
  ]
}
JSON

run_case incomplete-record 2 pr "${work_dir}/incomplete.json"

cat > "${work_dir}/expired.json" <<'JSON'
{
  "schema": 1,
  "exceptions": [
    {
      "id": "TQ-EX-004",
      "scenario_ids": ["none"],
      "test": "tests/example.php::testExample",
      "owner": "maintainers",
      "reason": "Expired fixture for policy validation.",
      "issue": "https://example.com/issues/4",
      "expires_on": "2000-01-01",
      "exit_condition": "Never",
      "scope": "invalid fixture",
      "approved_by": "merge-owner"
    }
  ]
}
JSON

run_case expired-record 2 pr "${work_dir}/expired.json"

printf '%s\n' '{"schema":1,"exceptions":[' > "${work_dir}/malformed.json"
run_case malformed-json 2 pr "${work_dir}/malformed.json"

echo "Test exception policy synthetic tests passed"
