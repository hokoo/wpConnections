#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT

printf '%s\n' \
  '<?php' \
  "require '${project_root}/vendor/autoload.php';" > "${work_dir}/bootstrap.php"

cat > "${work_dir}/OrderDependentProbeTest.php" <<'PHP'
<?php

use PHPUnit\Framework\TestCase;

final class OrderDependentProbeTest extends TestCase
{
    private static bool $sharedState = false;

    public function testAChangesSharedState(): void
    {
        self::$sharedState = true;
        self::assertTrue(self::$sharedState);
    }

    public function testBRequiresCleanState(): void
    {
        self::assertFalse(self::$sharedState);
    }
}
PHP

cat > "${work_dir}/phpunit.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="${work_dir}/bootstrap.php" colors="false">
  <testsuites>
    <testsuite name="order-dependent probe">
      <directory suffix="Test.php">${work_dir}</directory>
    </testsuite>
  </testsuites>
</phpunit>
XML

probe_exit=0
ISOLATION_REPEAT=2 bash "${project_root}/docker/run-isolation.sh" \
  order-dependent-probe \
  "${work_dir}/phpunit.xml" \
  424242 > "${work_dir}/output.txt" 2>&1 || probe_exit=$?

if [ "${probe_exit}" -eq 0 ]; then
  echo "Order-dependent isolation probe unexpectedly passed" >&2
  cat "${work_dir}/output.txt" >&2
  exit 1
fi

if ! grep -Fq 'Isolation phase: suite=order-dependent-probe order=reverse repeats=2 seed=424242' \
  "${work_dir}/output.txt"; then
  echo "Isolation probe did not print its phase and reusable seed" >&2
  cat "${work_dir}/output.txt" >&2
  exit 1
fi

if ! grep -Eq 'There (was|were) [0-9]+ failure' "${work_dir}/output.txt"; then
  echo "Isolation probe failed for an unexpected reason" >&2
  cat "${work_dir}/output.txt" >&2
  exit 1
fi

echo "Isolation runner synthetic probe: detected failure with reusable seed 424242"
