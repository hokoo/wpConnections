# Batch 28 — REL-01 compatibility matrix

Status: in progress. Documentary and static inventory complete; the Ramsey
matrix install change is in the working tree, and exact-head verification is
pending. No release, merge or publication is claimed.

Base: `979db63` (`batch28-rel01-compatibility`). This checkpoint is a
work-in-progress record, not a verified candidate. REL-01 is the only
implementation scope. Product source and CI workflows are unchanged; the
test entrypoint and dedicated Ramsey manifest/lock are pending verification.

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
`trunk`. `composer.lock` fixes the other resolved Composer packages at exact
references. The working-tree entrypoint is being changed from
`composer update ramsey/collection --with` to installs from the two lock files;
the installed Ramsey version still needs confirmation from each runtime log.
WordPress 6.7.7 is explicitly a test floor,
not a promise of upstream maintenance. The public documentation makes no
support claim for untested EOL combinations.

## Evidence and completion boundary

- The prior [Batch 27 checkpoint](batch27-checkpoint.md) records local green
  unit (143/533), integration (681/5739, 12 expected skips), true multisite
  (681/5839), coverage (824/6270, 12 expected skips; 4329/4693 statements,
  92.24%), PHPCS (108 source files), and OpenAPI validation. It also records
  20/20 protected checks on PR #118's exact head and its merged SHA. Those are
  historical evidence, not a pass for this Batch 28 checkout.
- For REL-01, run the 20 blocking jobs against one frozen exact head, including
  clean install/image build, both database lanes, coverage, style and OpenAPI.
  Capture each context name, conclusion, head SHA and runtime versions. Check
  that no context is missing, stale, skipped or cancelled; then reconcile any
  nonblocking incompatibility with a named owner and follow-up. Local serial
  checks can support diagnosis but cannot establish the GitHub required-check
  state.
- At this checkpoint, no Batch 28 matrix, clean-install, PHPUnit, coverage or
  PHPCS result has been recorded. The current GitHub authentication is
  unavailable to inspect exact-head remote results. Thus the REL-01 AC and
  green-blocking DoD remain unverified; do not mark REL-01 complete on static
  inventory alone.

The prior plan and [Batch 22 checkpoint](batch22-checkpoint.md) record
non-failing WordPress/dependency warnings on newer PHP and a PHPCS ruleset
deprecation. Owner: repository maintainer. Follow-up: inspect their current
occurrence in the REL-01 logs and carry any surviving warning into REL-03
release review; open a focused dependency/tooling task if it becomes a failure.
No new incompatibility was identified by this static inventory. Any new lane
failure needs its own owner and follow-up after execution. The exact-head
evidence and disposition of warnings or failures are the next gate.
