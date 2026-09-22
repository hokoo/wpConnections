# Batch 25 — API-03 bulk entity resolution

Status: review. API-03 implementation and local verification are accepted;
scoped commit and external PR/merge delivery remain distinct stages. On
2026-09-23 the owner authorized exactly the next batch after accepted
REST-06/E4. Stop at this batch boundary; API-04 is not authorized here.

Base: `2469eae85ee5010e1aa5688c6851c63fd393a35d`.
Branch: `batch25-api03-bulk-resolution`.
Contract: API-03 in [the hardening plan](02-library-hardening.md), approved
[API-01 decisions](../api-01-related-entities-contract.md), and DG-ENT-02 in
[the entity validation contract](../entity-validation-contract.md).

- Goal/scope: PHP bulk resolution with explicit targets and structured results;
  preserve input connection order, duplicate occurrences and endpoint roles;
  implement the post-only `getPosts('from'|'to')` compatibility facade.
- DoR/dependencies: API-01, CORE-00, CORE-04, DB-01, REST-06 and the task's
  recorded public-contract approvals are complete. This continuation authorizes
  the previously stopped API-03 work.
- AC: absolute/opposite projection and self-connection semantics match API-01;
  missing/wrong-type/unsupported endpoints retain generic unavailable slots;
  custom adapters remain client-scoped and compatible with DG-ENT-02;
  unique IDs are resolved once per adapter/type group, with 1-versus-50
  query-growth evidence; `getPosts()` preserves duplicate order, omits
  unavailable posts and rejects unsupported directions.
- DoD: meaningful unit/integration coverage, public usage/extension guidance,
  root review and serial unit, integration, multisite, seeded isolation,
  coverage and PHPCS checks; scoped verified commit. Preserve the project's
  PR/merge delivery stage separately and do not claim remote checks or merge
  from local evidence.
- Exclusions: REST formatting, permission/context/filter preparation and
  pagination (API-04), OpenAPI (DOC-01), new entity types, storage/schema changes,
  release/tag/deployment and publication.
- Execution: one implementation writer, one delegation level. A bounded
  read-only mapper informs the worker contract; a test monitor owns the broad
  serial gates after the writer stops. Root edits delivery bookkeeping only.
- Risks: existing custom mutation resolvers must remain source-compatible;
  PHP entity access is not a REST authorization boundary; no cross-client or
  cross-site result cache; untracked `.codex/` and `AGENTS.md` are user-owned.

API-04 and DOC-01 remain dependent; E5 is not at its epic QA boundary.

## Review boundary

The first writer stopped with focused unit `2/15`, integration `8/50` (one
expected multisite skip), multisite `8/51`, and changed-source PHPCS `8/8`.
Saved `getPosts()` red evidence proves the old null return. Cold-cache full
database deltas are `1 -> 1` for one versus 50 existing IDs and also for missing
IDs. Logs: `/tmp/wpconnections-b25-implementation/`.

Root and a separate read-only reviewer withheld acceptance for one ownership
defect: registering a WordPress post type after a custom resolver claims that
type makes batch reads choose posts, while mutation validation rejects the
conflict. A fresh bounded worker must retain generic unavailable slots for
this conflict and prove red/green coverage. Broad gates await the repaired
stable diff. No criterion is waived and no root implementation exception exists.

The repair is accepted by root: mutation-only, inline batch and companion
ownership now remain unavailable during a late post-type collision, and new
companion registration rejects that collision. Saved red tests show two
incorrectly resolved slots; repaired focused integration passes `10/59` with
one expected single-site skip, changed-source PHPCS `3/3`, and diff checks pass.
Evidence: `/tmp/wpconnections-b25-ownership-repair/`. All writers have stopped;
the repaired source/test diff is frozen for the serial verification ladder.

## Verification boundary

The initial sandbox invocation could not access the Docker socket; no test
started. An approved Docker-access retry then passed unit `143/533`, integration
`655/5580` (12 skips), and true multisite `655/5680`. Isolation seed `20260923`
passed both unit orders (`286/1066` each) and reverse integration
(`1310/11160`, 24 skips), but random integration reported two failures in
`AtomicMutationTest::test_deleted_post_real_flow_scheduler_failure_preserves_retryable_work`.
The cron assertion expected no event and found a timestamp. Coverage/PHPCS
have not run. Logs: `/tmp/wpconnections-b25-local/`; all candidate hashes and
working-tree status were unchanged. Acceptance is withheld while a bounded
read-only diagnosis establishes the required repair; no failure is waived.

The subsequent diagnostic random/repeat run reproduced the failure. A bounded
three-class reproducer established the cause: the cron option had rolled back
in the database, while WordPress's object cache still exposed an event;
`wp_clear_scheduled_hook()` returned `could_not_set`. The bare PHPUnit
`AtomicMutationTest` fixture now flushes that cache at setup, consistent with
WordPress's own fixture. Only two test lines changed; production scheduler and
assertions are unchanged, and temporary diagnostics were removed. The same
seeded subset passes `131/1282` after the repair. Root accepts the causal
evidence and narrow correction. Full multisite/isolation, coverage and lint
verification now resume on the frozen candidate.

## Accepted local verification

All required local commands passed. The earlier isolated scheduler failure is
resolved by the fixture correction above; no assertion, gate or risk exception
was waived. Product/source hashes were unchanged during verification.

| Command | Actual result |
| --- | --- |
| `make tests.phpunit` | 143 tests / 533 assertions |
| `make tests.integration` | 655 / 5580; 12 single-site skips |
| `make tests.multisite` | Final repaired candidate: 655 / 5680; no skips |
| `make tests.isolation ISOLATION_SEED=20260923` | Reverse/random unit: 286 / 1066 each; reverse/random integration: 1310 / 11160 each, 24 skips each |
| `make tests.coverage` | 798 / 6111; 12 skips; 4066 / 4407 statements = 92.26%; PR gate passed |
| `make lint.phpcs` | All 105 source files passed |

Unit and ordinary integration evidence is in `/tmp/wpconnections-b25-local/`.
Final multisite/isolation/coverage/lint evidence and unchanged-file manifests
are in `/tmp/wpconnections-b25-final/`. The two-line fixture repair followed
the ordinary integration run; final isolation reran the complete unit and
integration suites in both orders. Current lanes used PHP 8.1.34, WordPress
7.1-src, Ramsey 1.3.0, MariaDB 11.8.6; coverage used WordPress 6.7.7-src.
Coverage artifacts are under `build/coverage/`, including `clover.xml`.
The existing PHPCS configuration deprecation is non-failing. Neither RC nor
clean-rebuild verification nor the full remote compatibility matrix was run.

Repair reproduction used `test:integration --filter
'(EntityValidationTest|AtomicScopeTest|AtomicMutationTest)' --order-by=random
--random-order-seed=20260923 --stop-on-failure` through the local Compose test
service: red `124/1182`, green `131/1282`. Logs and the removed diagnostic's
observations are in `/tmp/wpconnections-b25-isolation-repair/`.

Root accepts API-03's technical AC/DoD evidence. Changed artifacts are the
resolver/target/result and client registry source, resolver tests, the narrow
AtomicMutation fixture correction, public guidance and delivery records.
Implementation and repairs were delegated; root authored delivery bookkeeping
only. The review found and repaired type-ownership ambiguity; the isolation
gate exposed and repaired stale cron cache in an existing test fixture.

Public extension implementations remain responsible for their own non-post
object correctness; PHP resolution performs no REST authorization. Private
consumer usage remains the existing compatibility-inventory limitation.
No new material design decision, waiver or accepted exception remains.
Git status retained only intended changes plus user-owned `.codex/` and
`AGENTS.md`; no unexpected generated files appeared.

API-04 and DOC-01 retain their delivery dependencies. E5 is incomplete, so this
is task acceptance, not epic QA. External push/PR/merge, protected CI and issue
closure are not claimed; release/publication remain out of scope.
