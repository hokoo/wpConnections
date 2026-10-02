# Batch 28 — REL-01 compatibility matrix

Status: completed. PR #120 merged, and the exact head and merge each passed
all 20 required GitHub checks. No release or publication is claimed.

Implementation base: `979db63` (`batch28-rel01-compatibility`). The checkout
also includes delivery-documentation commits `cf7d449` and `b5baf84`; the
verified REL-01 candidate contains the harness and alternate-lock changes
recorded here. REL-01 is the only implementation scope. Product source, tests and CI
workflows are unchanged.

## Declared blocking matrix

The executable jobs in `.github/workflows/` match `README.md` and
`docs/ci-runbook.md`:

| Gate | Blocking inputs | Jobs |
| --- | --- | ---: |
| Unit | PHP 8.1.34, 8.2.33, 8.3.33, 8.4.25, 8.5.10 × Ramsey Collection 1.3.0, 2.1.1 | 10 |
| WordPress integration | PHP/WP/Ramsey 8.1.34/6.7.7/1.3.0; 8.2.33/7.1.0/1.3.0; 8.3.33/7.1.0/2.1.1; 8.4.25/6.7.7/2.1.1; 8.5.10/7.1.0/2.1.1 | 5 |
| True multisite | 8.1.34/6.7.7/1.3.0 | 1 |
| External database integration | MySQL 8.0.46 and MariaDB 10.11.16 at 8.1.34/6.7.7/1.3.0 | 2 |
| Coverage and style | PHP 8.1.34 / WP 6.7.7 coverage; PHP 8.1.34 / WP 6.7.7 PHPCS and OpenAPI validation | 2 |

The database images are digest pinned, and their actual server versions are
checked before tests. Stable PHP, WordPress and Ramsey lanes use exact version
inputs; only the separate, nonblocking scheduled/manual WordPress canary uses
`trunk`. The entrypoint now installs from `composer.lock` for Ramsey 1.3.0 or
the alternate manifest and lock for Ramsey 2.1.1, then asserts the installed
Ramsey version. This replaces the mutable `composer update` in stable lanes.
The alternate manifest is a symlink to `composer.json`; both locks fix the
resolved packages. Their SHA-256 hashes remained unchanged through the final
local gates: main `321283585dd86f7e273c8d26aacaabc0ef3553603fa9f0f9cccf8aac319045a7`,
alternate `9fbb7d8bc4b2dc3520d471a2c2b140dcd47b5ac12c35a26cc587ad9dc8a4b208`.
WordPress 6.7.7 is explicitly a test floor, not a promise of upstream
maintenance. The public documentation makes no support claim for untested EOL
combinations.

## Evidence and completion boundary

- The prior [Batch 27 checkpoint](batch27-checkpoint.md) records local green
  unit (143/533), integration (681/5739, 12 expected skips), true multisite
  (681/5839), coverage (824/6270, 12 expected skips; 4329/4693 statements,
  92.24%), PHPCS (108 source files), and OpenAPI validation. It also records
  20/20 protected checks on PR #118's exact head and its merged SHA. Those are
  historical evidence, not a pass for this Batch 28 checkout.
- Before the lock repair, the 16 local PHP/WordPress/Ramsey lanes passed, but
  `composer update` changed a resolved polyfill from v1.27 to v1.38. That run
  did not establish immutable stable dependencies. The repaired entrypoint
  uses locked installs and asserts the runtime Ramsey version.
- After repair, the workflow-equivalent local runtime matrix passed 16/16
  lanes in 14m05s: ten unit lanes each 143 tests/533 assertions, five
  integration lanes each 681/5739 with 12 expected skips, and true multisite
  681/5839. The logs record the declared PHP, WordPress and Ramsey pins.
  Runner: `/tmp/rel01-runtime-matrix.sh`; logs:
  `/tmp/rel01-runtime-matrix-20261001/`.
- Both external database lanes passed with the actual pinned server versions:
  MySQL 8.0.46 (681/5739, 12 skips, 152s) and MariaDB
  10.11.16-MariaDB-ubu2204 (681/5739, 12 skips, 100s). Logs:
  `/tmp/wpconnections-b28-mysql.log` and
  `/tmp/wpconnections-b28-mariadb.log`.
- The final serial local ladder passed: `make tests.clean` rebuilt without
  cache and ran unit 143/533 plus integration 681/5739 with 12 skips;
  `make tests.coverage` ran combined 824/6270 with 12 skips and covered
  4329/4693 statements (92.24%, passing the PR threshold and supplying an
  RC-threshold signal); `make lint.phpcs` passed 108 files;
  `make lint.openapi` passed; and
  `make tests.isolation ISOLATION_SEED=20261001` passed reverse and randomized
  repeat-2 runs (unit 286/1066 and integration 1362/11478 with 24 skips per
  order). `make tests.coverage.rc` was not run. The working-tree status and
  both lock hashes were unchanged after these gates.
- [PR #120](https://github.com/hokoo/wpConnections/pull/120) exact head
  `992bdae49aa3d2daaa6f6a5ce75eba9bbdfe0246` passed all 20 required contexts.
  PR runs: `37016703544`, `37016703681`, `37016703711`, `37016704015`,
  `37016703520`; all succeeded on attempt 1. The owner authorized push, PR
  and merge after those checks passed, including preceding roadmap and agent
  configuration commits.
- PR #120 merged on 2026-10-02 as
  `f9f20d5e99489b0dafd2e1b4dc9f5d09f60dd7cf`. All 20 post-merge contexts
  passed on this exact SHA: push runs `37017355563`, `37017355560`,
  `37017355561`, `37017355293`, `37017355799`, each attempt 1. Root independently
  matched names, SHA and success states for both head and merge; no required
  context was missing, skipped, cancelled, stale or failed.
- Live branch metadata confirmed `master` protection with all 20 contexts and
  enforcement for everyone. The full administration endpoint remains unavailable
  to the connected app (403); strict checks, disabled force-push/deletion and
  administrator enforcement retain the owner's 2026-09-27 attestation.
- REL-01 AC and DoD are satisfied. Root authored only concise delivery
  bookkeeping during closeout. E6 is still open; no next batch or task is
  selected, and no acceptance criterion was waived.

Nonblocking warnings remain in the passing logs: PHP 8.4/8.5 deprecations,
WordPress `fonts.php` with a null `post_type`, PHPCS `functionWhitelist`
deprecation, and a deprecated Swagger CLI. Owner: repository maintainer.
Follow-up: review these in REL-03 and open a focused dependency/tooling task if
any becomes a failure. No blocking incompatibility was observed locally.
REL-02, HOOK-04, the DB-02R release decision and REL-03 remain outside this
completed batch and retain their existing readiness and release gates.
