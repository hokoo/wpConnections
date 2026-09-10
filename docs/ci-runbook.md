# CI and local verification runbook

This runbook is the contribution contract for automated verification in
wpConnections. The executable sources of truth are the repository
[`makefile`](../makefile), Docker test entrypoint
[`docker/phpunit-entrypoint.sh`](../docker/phpunit-entrypoint.sh), and workflows
under [`.github/workflows`](../.github/workflows/).

## Prerequisites and first run

Install Git, GNU Make, Docker Engine or Docker Desktop, and Docker Compose v2.
The supported Compose command is `docker compose`; the legacy
`docker-compose` binary is not part of the contributor contract.

From a fresh clone, initialize the ignored local environment file and run the
complete merge-readiness set:

```bash
bash ./local-dev/init.sh
make tests.clean
make tests.coverage
make lint.phpcs
```

`local-dev/.env` is local-only configuration. Never commit it or put real
credentials in documentation, test output, or pull requests.

`make tests.clean` rebuilds the test image without Docker layer cache and then
runs both test suites. The first build downloads the selected WordPress test
library, and the first container run installs Composer dependencies, so network
access is required.

## Test suites and local commands

| Command | Purpose | When to use it |
| --- | --- | --- |
| `make tests.run` | Run unit and WordPress integration suites | Fast local loop before a push |
| `make tests.phpunit` | Run isolated unit tests from `phpunit.xml` | Changes that do not require WordPress bootstrap or MariaDB |
| `make tests.integration` | Run WordPress integration tests from `php-wp-unit.xml` | Storage, WordPress hooks, database, and entity integration changes |
| `make tests.coverage` | Run both suites in one instrumented process and apply the coverage gate | Before requesting review and after source/test changes |
| `make lint.phpcs` | Run the PHP CodeSniffer rules from `phpcs.xml` | Before requesting review and after PHP changes |
| `make tests.build` | Rebuild the Compose test image with normal Docker cache | Dockerfile or image-input changes |
| `make tests.rebuild` | Rebuild the Compose test image without Docker cache | Diagnose image or Docker cache problems |
| `make tests.clean` | Perform `tests.rebuild`, then run both suites | Clean-checkout and final local verification |

The test entrypoint runs `composer install` on every normal local invocation.
This is idempotent and synchronizes the bind-mounted `vendor/` directory with
`composer.lock`; the Docker image is reused in the fast loop. The integration
suite starts and stops its own MariaDB process inside the test container. It
does not depend on the `mysql` service in the local development stack.

`make tests.integration` is the canonical integration command. The old
`tests.wpunit` alias is intentionally not supported.

## Blocking compatibility matrix

Pull requests are expected to pass the following pinned jobs. Exact pins keep
reruns reproducible; updating them is an intentional compatibility-policy
change, not routine dependency drift.

### Unit tests

The `Unit Tests` workflow runs the Cartesian product below: ten jobs in total.
The image uses WordPress `6.7.7`, although the unit suite itself does not load
WordPress.

| Input | Exact versions |
| --- | --- |
| PHP | `8.1.34`, `8.2.33`, `8.3.33`, `8.4.25`, `8.5.10` |
| Ramsey Collection | `1.3.0`, `2.1.1` |

### WordPress integration tests

The `WP Integration Tests` workflow runs five pairwise jobs:

| PHP | WordPress | Ramsey Collection |
| --- | --- | --- |
| `8.1.34` | `6.7.7` | `1.3.0` |
| `8.2.33` | `7.1.0` | `1.3.0` |
| `8.3.33` | `7.1.0` | `2.1.1` |
| `8.4.25` | `6.7.7` | `2.1.1` |
| `8.5.10` | `7.1.0` | `2.1.1` |

WordPress `6.7.7` is the compatibility-floor pin, not a statement that its
upstream branch is still maintained. WordPress `7.1.0` is the stable pin for
this matrix. Production WordPress versions should follow current upstream
security guidance.

### Coverage and code style

The `Coverage` job uses PHP `8.1.34` and WordPress `6.7.7`. The `PHP Code
Styles` job builds the same pinned environment and runs `composer run phpcs`.
Both are part of the expected pull-request checks.

To reproduce a particular matrix lane, use the same build arguments and
runtime Ramsey pin as its workflow. For example, the minimum integration lane
is:

```bash
docker build \
  --build-arg PHP_VERSION=8.1.34 \
  --build-arg WP_VERSION=6.7.7 \
  -t wpconnections-ci:local-matrix \
  -f Dockerfile.phpunit .
docker run --rm -v "$PWD:/srv/web" \
  -e RAMSEY_VERSION=1.3.0 \
  wpconnections-ci:local-matrix \
  test:integration
```

The container prints the requested and runtime PHP, WordPress, and Ramsey
versions. Check those lines first when diagnosing a matrix-only failure.

## WordPress trunk canary

`WP Trunk Canary` runs each Monday at 06:17 UTC and can also be started through
`workflow_dispatch`. It tests WordPress `trunk` with PHP `8.5.10` and Ramsey
Collection `2.1.1`.

The canary is intentionally scheduled/manual and non-blocking for pull
requests. Treat a failure as an early upstream-compatibility signal: confirm it
against the pinned stable lane, record the upstream error, and open a focused
follow-up. Do not replace stable pins with a moving ref to make the canary pass.

## Coverage gate

`make tests.coverage` produces combined unit and WordPress integration coverage
and compares statement coverage with
[`coverage-baseline.json`](../coverage-baseline.json). The accepted baseline is
`365/786` statements (`46.44%` when displayed to two decimal places). The gate
compares the exact covered/total ratio, so rounding cannot hide a regression.

Reports are written to `build/coverage/`:

- `coverage.txt` for a human-readable file-by-file report;
- `clover.xml` for tools;
- `coverage-summary.json` for machine-readable gate output;
- `coverage-summary.md` for the CI job summary.

GitHub Actions uploads this directory for 14 days, including on failures. If
the gate fails, run `make tests.coverage`, inspect `coverage-summary.md` and
`coverage.txt`, and identify the uncovered source change. Change the baseline
only when a reviewed source or test change intentionally establishes a new
accepted ratio; never lower it only to turn the check green.

## Expected pull-request checks

Before merge, the pull request should show all of these green:

- 10 `Unit Tests / Unit Tests PHP … / Ramsey …` jobs;
- 5 `WP Integration Tests / WP Integration PHP … / WP … / Ramsey …` jobs;
- `Coverage / Coverage PHP 8.1.34 / WordPress 6.7.7`;
- `PHP Code Styles / php-cs`.

This repository documentation does not configure GitHub branch protection.
The merge owner must manually compare the visible checks with the list above
and confirm that none are missing, skipped, cancelled, or stale for the pull
request head commit. `WP Trunk Canary` is not in the blocking list.

INFRA-05 (CI caching and runtime optimization) is intentionally deferred and
non-blocking. Until that follow-up is implemented, successful clean builds and
the complete visible check set take precedence over CI duration.

## Troubleshooting

### Composer dependency failures

- Read the `Composer dependencies` and `Runtime versions` sections in the job
  output; confirm that the installed Ramsey version matches the matrix lane.
- Run the failing suite again. Normal Make targets always execute the
  idempotent `composer install` against `composer.lock`.
- If a lock or manifest change is intentional, verify both Ramsey `1.3.0` and
  `2.1.1` lanes before review. Do not commit an incidental `composer.lock`
  rewrite produced while experimenting with a matrix lane.
- Use `make tests.clean` when a dependency error may come from an outdated test
  image rather than the bind-mounted project files.

### MariaDB or WordPress integration failures

- Reproduce with `make tests.integration`; host MySQL/MariaDB state and the
  Compose `mysql` service are not used by this suite.
- Look for the `MariaDB` log section, a startup timeout, or a missing
  `wp-tests-config.php` sample. Those indicate test-image/bootstrap trouble
  rather than an assertion failure.
- Run `make tests.rebuild`, then `make tests.integration` to reconstruct the
  embedded database and pinned WordPress test library from the Dockerfile.
- Confirm the host has free disk space and can download the exact
  `wordpress-develop` tag used by the failing lane.

### Docker image or cache failures

- Use `make tests.build` after changing `Dockerfile.phpunit` or copied entrypoint
  files.
- Use `make tests.clean` for a no-cache rebuild plus both suites. A cache miss is
  a supported path and must produce the same result as a cached run.
- If only one CI lane fails, reproduce that lane with its exact PHP, WordPress,
  and Ramsey values rather than relying on the local Compose defaults.
- Do not treat a faster cached run as proof of readiness; the clean path and all
  expected checks remain authoritative.
