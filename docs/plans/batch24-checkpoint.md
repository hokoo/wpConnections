# Batch 24 — REST-06 relation selectors

Status: completed. REST-06 is delivered through PR #112; independent E4 Epic QA
returned `pass_with_notes`, accepted by the delivery owner. This same-batch
closeout records the accepted result; no next product batch has started.

Execution boundary: on 2026-09-22 the owner requested completion of this batch
and then a stop. Finish REST-06 delivery and the E4 boundary QA; do not start
API-03 or another delivery batch without a new continuation request.

Base: accepted REST-05 merge `2b4a1cd553bb1813a21e6615c76237a2fb38f6e8`
and completion record `70ce926a7eacfd75d4e0819cfb42c3ba4e16f6a1`.
Contract: REST-06 in [the hardening plan](02-library-hardening.md) and
DG-API20-01/B in [the related-entities contract](../api-01-related-entities-contract.md).

- Implement positive scalar query selectors `from`, `to`, `both`; combine
  distinct selectors with AND and the two sides of `both` with OR. Preserve
  unfiltered v1 representation and client/relation ownership.
- Prove native validation, no-match results, combinations and isolation through
  full REST dispatch. Characterize raw duplicate query keys separately from
  observable array values before making a transport-level claim.
- Pinned-runtime discovery: PHP collapses repeated scalar query keys before
  WordPress builds its request. Root approved a narrow internal ownership
  expansion: a context-owned `parse_request` action before core REST loading
  can preserve repeated selector values as arrays on managed GET relation
  routes, allowing native validation to reject them. No exception to the
  approved selector contract is accepted. Implementation must prove cleanup,
  rollback, site/route isolation, direct dispatch and late registration bounds,
  including clients created during `rest_api_init`.
- Exclude pagination, ordering, entity expansion, new storage/public data
  contracts, dependency changes and release/tag actions.
- Required sequence: saved red regression, focused implementation checks,
  root review, serial local gates, protected PR and exact-merge verification,
  issue #21 closure, then fresh independent E4 Epic QA. API-03 waits for E4
  acceptance; its read-only refinement does not authorize an early writer.
- Root owns this concise delivery record; implementation, tests and substantial
  documentation remain delegated. No exception or waiver is recorded.
- Initial writer stopped with a stable candidate at base `70ce926`: focused
  relevant suites passed 159 / 1639 with two expected environment skips and no
  errors/warnings; changed source/new-selector-test PHPCS passed 4 / 4. Logs:
  `/tmp/wpconnections-rest06-final.log`, `.xml`, and `-summary.log`.
  Saved pre-fix evidence contains 36 feature failures and one separately
  identified fixture error; the fixture error is not product-defect evidence.
- Root review withheld acceptance after a pinned probe proved that
  `from[0]junk=11&from=22` becomes scalar `from=22` in PHP but bypasses the
  candidate's raw-key regex. A fresh bounded worker owns canonical key handling,
  real-request regressions for both orders, and verification that the newly
  added lifecycle test blocks do not add PHPCS debt. Existing unrelated test
  formatting remains outside this repair. Full serial gates are still pending.
- A separate read-only contract review found no requirement to reconstruct raw
  keys after a request was already parsed before its Client existed. The
  documented input-timing boundary preserves immediate late binding and native
  validation of parsed requests; root accepts that interpretation without an
  exception. It does not excuse the confirmed PHP key-normalization defect.
- The fresh repair now uses PHP's own per-component top-level key parsing with
  locally contained malformed-input warnings. Literal and encoded suffix-key
  cases fail before correction (51 / 310, two failures, one expected skip) and
  pass afterward with selectors/lifecycle: 74 / 1140, two expected skips.
  Original-output excerpts are explicitly labelled under
  `/tmp/wpconnections-rest06-key-repair-{red,green}-excerpt.log`.
- Repair PHPCS passed the two changed registry/selector-test paths. Original
  final four-path PHPCS included the shared `ConnectionIdNormalizer` refactor;
  it and the 159-test run both preceded that writer's final stop. Existing
  lifecycle PHPCS debt changed from 1801 to 1800 errors (zero warnings); CSV
  confirms zero findings on every newly added test/helper and inventory line.
  Evidence: `/tmp/wpconnections-rest06-phpcs/`. No unrelated reformat occurred.
- Root inspected the validator, query-only handler, registration/teardown and
  repaired key parser, accepted this bounded repair, and retained the explicit
  input-timing documentation. No implementation was authored by root and no
  criterion was waived. Full unit/integration/multisite/isolation/coverage/lint
  verification follows on a frozen revision.

## Frozen local verification

Candidate: `8dd5941b654710afcb469b510305c5fbb1d24ca6`.
All commands exited 0; logs are in `/tmp/wpconnections-b24-local/`.
Current integration used PHP 8.1.34, WordPress 7.1-src (requested 7.1.0),
Ramsey Collection 1.3.0 and MariaDB 11.8.6. Coverage used the pinned WordPress
6.7.7 floor with the same PHP, Ramsey and database versions.

| Command | Actual result |
| --- | --- |
| `make tests.phpunit` | 141 tests / 518 assertions |
| `make tests.integration` | 645 / 5521; 11 expected environment skips |
| `make tests.multisite` | 645 / 5620 |
| `make tests.isolation ISOLATION_SEED=20260922` | Reverse and random: unit 282 / 1036 each; integration 1290 / 11042 each, 22 expected skips each |
| `make tests.coverage` | 786 / 6037; 11 expected skips; 3937 / 4275 lines = 92.09%; PR coverage gate passed |
| `make lint.phpcs` | All 100 source files passed |

Existing WordPress font warnings and the PHPCS configuration deprecation did
not fail these commands. `build/coverage/clover.xml` is the coverage artifact.
Tracked files remained unchanged; only the expected untracked `.codex/` and
`AGENTS.md` remain. No release-candidate or clean-rebuild command was run.
Root accepts the task's technical evidence and proceeds only with protected
delivery and final E4 acceptance within the owner's current-batch stop boundary.

## Protected delivery and E4 acceptance

- [PR #112](https://github.com/hokoo/wpConnections/pull/112) head
  `6cdc9feddcbad49eb77c95ec2882017c19bd1169` passed all 20 required contexts.
  It adds only local-evidence bookkeeping after the tested implementation.
- Exact merge `93bea9b57b5dcdb3c1d82e2d03da1cb7e920531d`, merged on
  2026-09-22 at 14:05:40 UTC, also passed all 20 required contexts.
  Root independently matched required names, SHA and completed/success state
  for both boundaries and confirmed strict checks, admin enforcement and
  disabled force-push/deletion. Evidence directories:
  `/tmp/wpconnections-b24-pr112-ci/` and `/tmp/wpconnections-b24-postmerge-ci/`.
  The latter's early workflow summary is superseded by the final exact-SHA
  `check-runs.json`; the early database workflow snapshot is not final proof.
- [Issue #21](https://github.com/hokoo/wpConnections/issues/21) closed at
  14:05:41 UTC. Public selector guidance is the verified input for DOC-01;
  OpenAPI publication remains outside this batch.
- Fresh independent `epic_qa` reviewed the frozen merge and E4's REST-00A/B
  through REST-06 contracts, task criteria, named scenario coverage and actual
  verification evidence. Gate: `pass_with_notes`; no blocking finding,
  accepted exception, waiver or new product decision. Root accepts E4.
- Nonblocking P3 test-depth note: the defensive catch that removes the first
  subscription if acquiring the second throws is source-audited but has no
  injected second-subscription failure test. Existing failed-activation and
  teardown tests must not be described as exercising that exact catch. Add
  focused fault injection if that path changes or the dispatcher becomes
  injectable; this is not an unmet current E4 criterion or a new active batch.
- The P3 status-bookkeeping note is addressed by this same-batch closeout.
  Existing lifecycle formatting debt, non-failing runtime warnings and the
  documented raw-input timing boundary remain explicit notes. DB-06R remains
  a separate nonblocking `needs_design` follow-up.

Changed artifacts are the three REST source files, selector/lifecycle tests,
public selector and metadata guidance, hook inventory, test-quality references
and plan/checkpoint records. Implementation and substantive documentation were
delegated; root authored only concise delivery bookkeeping. No entity expansion,
storage-model change, dependency/matrix change, release, deployment or package
publication occurred.

The owner's stop instruction now applies: API-03 is dependency-ready but not
started; API-04 and DOC-01 retain their downstream dependencies. Finish protected
publication of this acceptance record and stop. A new product batch requires a
new continuation request.
