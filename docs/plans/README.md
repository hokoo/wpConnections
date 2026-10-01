# wpConnections development plans

This directory contains the executable plan prepared after the repository,
test, GitHub issue, and CI branch audit on 2026-09-09.
For a concise previous/current/next batch and epic status, see the
[root README](../../README.md#development-status). The same status is recorded
here alongside the delivery history and evidence links.

## Development status

As of 2026-10-01, wpConnections is preparing its first official release.
Earlier consumers installed commit-pinned versions; no official wpConnections
release has been published.

| Level | Previous | Current | Next |
| --- | --- | --- | --- |
| Epic | E5 completed; independent QA `pass_with_notes`. | E6 compatibility and release readiness. E7 still has the HOOK-04 release gate. | No epic after E6 has been selected. |
| Batch | Batch 27 / DOC-01 completed. | No batch is active. | Proposed Batch 28 / REL-01 compatibility matrix; confirm its DoR before starting. |

Update this block and the [root README status](../../README.md#development-status)
together whenever a batch starts or changes state, or an epic changes state.

Batch 21 qualification was delivered through PR #108 on 2026-09-22. B21-01—B21-10
are complete: local candidate `062b7fe`, protected head `1e8a4c9`, and exact
merge `a341f9b` passed. Commands and exact results are in the
[Batch 21 checkpoint](batch21-checkpoint.md). Closeout PR #109 merged as
`ba0b546`, passed 20/20 post-merge contexts, and received fresh final QA
`pass_with_notes`; B21-Q, Batch 21, DB-04-I, and DB-04-Q are complete. REST-04
was completed through PR #110: exact head `c91388e` and merge `5f4c544`
passed 20/20 protected/post-merge contexts. Fresh final QA returned
`pass_with_notes` with no open P0—P3 findings. Authoritative evidence is in the
[Batch 22 checkpoint](batch22-checkpoint.md). REST-05 was completed through
PR #111: exact head `b141347` and merge `2b4a1cd` passed 20/20
protected/post-merge contexts; exact local and delivery results are in the
[Batch 23 checkpoint](batch23-checkpoint.md). REST-06 was completed through
PR #112: exact head `6cdc9fe` and merge `93bea9b` each passed 20/20 required
contexts. Issue #21 is closed, and fresh E4 Epic QA returned an accepted
`pass_with_notes`. Full provenance is in the
[Batch 24 checkpoint](batch24-checkpoint.md); the selector contract is in
[`rest-relation-selectors.md`](../rest-relation-selectors.md).
API-03 was completed as Batch 25 through PR #114. API-04 was completed as
Batch 26 through PR #116: exact head `6b81ab1` and merge `0ce93fc` each
passed 20/20 required contexts, and issue #20 is closed. Details are in the
[Batch 26 checkpoint](batch26-checkpoint.md). DOC-01 was completed as Batch 27
through PR #118: exact head `0d3adca` and merge `8a0be6d` each passed 20/20
required contexts, and issue #27 is closed. Fresh E5 Epic QA returned
`pass_with_notes` with no blocking findings or exception; evidence is in the
[Batch 27 checkpoint](batch27-checkpoint.md). DB-06R remains a separate,
non-blocking `needs_design` task.
The next stage is E6: preparing the first official release after consumers
used commit-pinned versions. The [main plan](02-library-hardening.md) records
known limitations and the DB-02R release gate.

## Delivery history

1. The infrastructure plan is complete: [PR #48](https://github.com/hokoo/wpConnections/pull/48)
   merged into `master` on 2026-09-10.
2. Batches 1—5 of the [main plan](./02-library-hardening.md) are complete.
   PRs #51—#65 recorded decisions, test foundation/contracts, initial
   production fixes, and executable quality gates. PR #66 activated Batch 5;
   PR #67 completed CORE-03 and closed issue #31.
3. Batch 5 was completed and closed by PR #72 on `master` at `5b60682`.
   Batch 6 is complete: TEST-02F/CORE-07 were completed, CORE-04 merged through
   PR #75 as `7ec7643`, and CORE-06R merged through PR #76 as `2371ed2`;
   post-merge CI passed 17/17 jobs.
4. Batch 7 is complete: HOOK-TRANS-01 merged through PR #77 as `5c2fc26`;
   both final head and post-merge passed 17/17 jobs. HOOK-00 merged through
   PR #78 as `cf8caa6`, also with 17/17 checks on final head and post-merge.
   HOOK-02 received independent QA PASS; PR #79 candidate head `d7ab4bd`
   passed 17/17 protected jobs, and merge `cf67eee` passed 17/17 post-merge
   jobs. Batch 8 is complete.
5. All hook-transition gates known before Batch 10 implementation discovery,
   together with DG-SPI-06/A and DG-RESTERR-03/A, were approved by the owner.
   HOOK-01 is complete: the standalone `hokoo/wp-hooks-dispatcher` package
   (`iTRON\wpHooksDispatcher\`) was published on Packagist as `v1.0.1`.
   LOG-HOOK-01 was completed in separate PR #82: exact candidate `234216e`
   received independent QA PASS and passed 17/17 protected checks. Final head
   `6554089`, merge `73bc71f`, and post-merge `master` also passed 17/17
   checks. Batch 9 is complete.
6. The owner approved DG-HOOK-REST-05/A: WordPress retains native validation
   precedence, and stale Client code runs on neither the 400 path nor the
   no-owner 404 path. REST-HOOK-01 was completed on exact implementation head
   `9d5b74e` with independent QA PASS. The docs-only final head `7e1addc`
   passed independent closure QA and 17/17 protected checks. PR #83 merged as
   `33b659e`; the exact merge SHA passed 17/17 post-merge checks. Batch 10
   is complete.
7. The owner approved DG-UPDATE-03/A on 2026-09-12: a scalar REST update does
   not change metadata, REST clients use `/meta`, and PHP
   `Connection::update()` retains aggregate replacement semantics. Batch 11
   (`TEST-02D + DB-02 + REST-02`) was completed by PR #85: exact head
   `6e2ffc4` received independent QA PASS_WITH_NOTES and passed 17/17
   protected checks; merge `3d954ec` passed 17/17 post-merge checks.
8. Batch 12 was completed by PR #87 and closeout PR #88. Batch 13 completed
   DB-06 through PR #89 and closeout PR #90; `master` `9d2f101` and exact
   merge candidates passed 19/19 checks, including pinned MySQL 8.0.46 and
   MariaDB 10.11.16. DG-SPI-03/A, DG-SPI-04/A, and DG-DB-03/A were approved
   on 2026-09-13. Batch 14 completed DB-05 after failure normalization,
   root/nested atomic scopes, and compound domain flow slices.
   The owner approved DG-SPI-04R/A, DG-SPI-06R/A, and DG-SPI-06R2/A on
   2026-09-13; nested transaction/hook and compound domain flow slices were
   completed locally. Three successive independent transaction audits closed
   child-scope, rollback-only, and shared-`$wpdb` uncertainty gaps. Exact local
   candidate `36ee004` received `PASS_WITH_NOTES` with no blocking findings.
   Final exact candidate `439d3a2` received closure QA `PASS` and passed 19/19
   checks; PR #91 merged as `5bd1ef6` and its post-merge matrix also passed
   19/19. Batch 14/DB-05 are complete. Batch 15 / DB-03B-B was completed by
   PR #93: implementation candidate `1568007` received independent audits;
   final head `e4142c2` passed 19/19 protected checks and merge `2348d5a`
   passed 19/19 post-merge checks. The concurrent aggregate update/delete
   parent-row race remains in follow-up DB-02R.
9. REST-03 was completed by PR #95. Exact candidate `56d5e1c` received
   independent QA PASS and passed 19/19 protected checks; merge `185bf32`
   passed 19/19 post-merge checks. The canonical v1 non-meta CRUD/error
   contract is now exercised through full WordPress REST dispatch. REST-04
   and REST-05 were unblocked.
10. Batch 16 / DB-04-D was completed by PR #97. Exact design candidate
    `54db7fd` received independent QA PASS and passed 19/19 protected checks;
    merge `419e4d9` passed 19/19 post-merge checks. DG-DELETE-06R1/R2/R3
    were approved as A; DB-04-I1 was ready for separate production Batch 17.
11. Batch 17 / DB-04-I1 was completed by PR #99. Exact candidate `14abfd5`
    received independent exact-candidate QA and security/SQL audit PASS with
    no open P0/P1/P2 findings and passed 19/19 protected checks; merge
    `c2f4b98` passed 19/19 post-merge jobs.
12. Batch 18 / DB-04-I2 was completed by PR #102. DG-DELETE-06R4—R6 were
    approved as A; B18-01—B18-07 and B18-Q are complete, while B18-08 is
    deferred. Corrective commits `bc08a31`/`e23cd38` closed findings from the
    first audits. Exact candidate `4c18fde` received three independent PASS
    results with no open P0—P3 findings and passed 19/19 protected jobs.
    Merge `66f6fd3` passed 19/19 post-merge jobs. HOOK-03/DB-04-I3 was
    unblocked as Batch 19; DB-04-Q still awaited I3 at that point.
13. Batch 19 / HOOK-03/DB-04-I3 was completed by PR #104. After all audit
    and cross-database test-integrity findings were fixed, exact candidate
    `ecc9d45b92fb39e9a2e0c8a5106b1f4a3d457737` received PASS from exact
    verification, independent QA, and security/test-integrity review, with no
    open P0—P3 findings or new decision gate. It passed 20/20 protected checks;
    merge `7cfe684a08e2ce1e8ba5f3dec1b6f9525f1d6e01` passed 20/20
    post-merge jobs. B19-01—B19-06 and B19-Q are complete; DB-04-Q and
    LIFE-HOOK-01 were unblocked. LIFE-HOOK-01 was selected next so that
    subsequent DB-04-Q would qualify the final Client lifecycle. Tag/release
    was intentionally outside Batch 19.
14. Batch 19 documentation closeout was completed by PR #105: merge
    `b0f011752e67931a90668ca8951a31d0a190afb7` passed 20/20 post-merge
    jobs. Batch 20 / LIFE-HOOK-01 was completed by PR #106. Exact candidate
    `05284155d803eb02c5c89e2052928f9d60807734` received three independent
    PASS results with no open P0—P3 findings or new decision gate; the PR
    head passed 20/20 protected checks. Merge
    `a3491c018b96b54dc03e55a850153ddc0e6db413` passed 20/20 post-merge
    jobs. Public terminal `Client::dispose()`, constructor rollback,
    REST/repair reentrancy guards, token/ABA ownership protection, retention
    release, and multisite isolation were delivered. Batch 20 created no
    tag/release.
15. DB-04-Q was decomposed and started as Batch 21: real `wp_delete_post()`
    cascade, recovery/crash windows, true multisite and custom-adapter
    qualification, operator/rollback/uninstall runbook, pinned vendors, and
    exact delivery evidence. B21-01—B21-07 are complete: fixture `0a15b4d`,
    data matrix `ac6361f`, pre-commit/arm/wake-up matrix `01daf04`,
    post-commit/crash matrix `7587271`, due-retry bridge `e039db0`, and
    true-multisite same-name/same-ID matrix `6a9726b` with repeat-safe
    teardown `39189ff` are recorded in the verification manifest. The
    custom-adapter real-flow matrix `89dbbe7` is also complete. B21-08 was
    completed in `65c53e8`: degraded-cron tests and operator runbook;
    focused single-site/multisite `2 / 49`, reverse/random repeat `4 / 98`,
    seed `20260922`. B21-09 was completed in `85dfa8d`: preservation
    rehearsal, retention evidence, and rollback/uninstall runbook;
    operational/retention `6 / 154`, multisite `3 / 84`, repeat `6 / 168`.
    Exact candidate `062b7fe` passed the full local B21-10 matrix: current
    unit/integration/multisite/isolation, PHPCS, fixed-floor
    coverage/multisite, and pinned MySQL 8.0.46 / MariaDB 10.11.16.
    PR #108 head `1e8a4c9` passed 20/20 protected checks; exact merge
    `a341f9b` passed 20/20 post-merge checks. B21-10 is complete.
    Pre-merge exact-candidate QA found no open P0—P3 finding or new decision
    gate and confirmed technical readiness. Closeout PR #109 / exact merge
    `ba0b546` also passed 20/20 post-merge contexts; fresh final QA returned
    `pass_with_notes` with no P0—P3 finding, decision gate, or exception.
    B21-Q, Batch 21, DB-04-I, and DB-04-Q are complete. Batch 21 added no
    public API and created no tag.

The infrastructure task list is in the [separate plan](./01-infrastructure-ci.md);
its M0 milestone is complete. The staged 1.x-to-2.0 hook lifecycle contract,
manager selection gate, and delivery map are in
[`docs/hook-lifecycle-transition.md`](../hook-lifecycle-transition.md).

## Plan maintenance rules

- Task statuses are `needs_design`, `waiting_dependency`, `todo`,
  `in_progress`, `blocked`, `review`, `completed`, and `deferred`.
- Record every decision gate outcome in the relevant document's decision table
  before moving dependent tasks to `todo`.
- One task should fit in one reviewable PR. Combine tasks only when they share
  the same risk, verification path, and rollback point.
- Start each confirmed defect fix with a reproducing test. The PR should show
  that the test fails before the fix and passes afterward.
- Each PR updates its task statuses and records the checks run.
- New public contracts, REST shape changes, error codes, or database schema
  changes require an explicit gate decision and compatibility documentation.

## Recorded verification baseline

- The most recent merged product baseline is Batch 27 / DOC-01 PR #118, merge
  `8a0be6dacf1c22b65071181a9ffbc499392f964d`. Exact head `0d3adca`
  and merge each passed 20/20 required contexts; issue #27 is closed. Fresh
  E5 Epic QA returned `pass_with_notes` with no blocking finding or exception.
  DB-06R remains a separate `needs_design` follow-up.
- Historical CORE-06R baseline PR #76 (`2371ed2`) on PHP 8.1.34 /
  Ramsey 1.3.0: WordPress 7.1.0 and fixed-floor WordPress 6.7.7 give unit
  `12 / 58` and integration `106 / 741`; focused true-multisite lane
  `13 / 144`; isolation unit `24 / 116` and integration `212 / 1482`;
  PHPCS `45/45`. Fixed-floor PR/RC coverage is combined `118 / 799`,
  `957/1059` statements (`90.37%`); baseline `365/786` is unchanged.
  Integration on newest PHP 8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1 is
  `106 / 741` with only known deprecation warnings.
- CORE-06R received independent QA PASS; final head `93d09c6` and merge
  `2371ed2` passed all 17 required jobs. Operator-facing naming
  inventory/attestation remains downstream REL-03 work; DB-06 schema lifecycle
  was already completed by PR #89.
- HOOK-TRANS-01 added an idempotent semantic cleanup API: current/fixed-floor
  unit `12/58`, integration `108/756`; true multisite `15/159`; isolation
  unit `24/116`, integration `216/1512`; combined coverage
  `968/1070 (90.47%)`; integration on newest PHP 8.5 / WP 7.1 /
  Ramsey 2.1 `108/756`; PHPCS `45/45`. Independent QA on head `632da3a`
  returned PASS with no findings. Closure head PR #77 `1e98cf7` and merge
  `5c2fc26` passed 17/17 protected/post-merge jobs; HOOK-TRANS-01 is
  complete.
- The HOOK-00 decision packet found no fully conforming dependency; the
  owner approved DG-HOOK-01/B on 2026-09-11. HOOK-01 subsequently published
  the separate project-owned `hokoo/wp-hooks-dispatcher` package with PSR-4
  namespace `iTRON\wpHooksDispatcher\`; coordinates were confirmed on
  2026-09-12. The decision record does not install the dependency.
  Independent QA after remediation returned unconditional PASS; PR #78 and
  merge `cf8caa6` passed 17/17 jobs.
- HOOK-01 was published as a separate MIT package with no Composer runtime
  dependencies: package PR #1 merge `ca0040f` received independent QA PASS
  and 5/5 protected/post-merge CI. Clarity patch PR #2 merge `7f449c4`
  also passed independent QA and 5/5 protected/post-merge jobs. GitHub
  release/tag `v1.0.1` became immutable after repository-level policy and
  in-place republication (`immutable: true` in the API). Packagist serves
  this version; a clean PHP 8.1 install resolved exact commit `7f449c4`
  and confirmed PSR-4 autoload.
- LOG-HOOK-01 replaced three per-Client debug closures with one idempotent
  process-global observer and routes writes through the originating Client's
  logger without a dispatcher dependency. Exact candidate `234216e` passed
  independent QA with no findings and 17/17 protected jobs. Fixed-floor
  combined run: `126 / 847`, coverage `991/1093 (90.67%)`; PHPCS `46/46`.
  The first CI run found an ambiguous combined-bootstrap assertion in the
  new test; the full suite passed after runtime-based remediation.
- The [HOOK-02 audit](../client-owned-hook-inventory.md) found five
  Client-owned action registrations under `WP_DEBUG` and no owned filters:
  `deleted_post`, `rest_api_init`, and three debug callbacks. Runtime probes
  confirmed cross-site delivery, REST route mixing, a late-init gap,
  cross-client logging, and leaked callbacks after failed construction.
  The owner approved DG-HOOK-SCOPE-01/A, DG-HOOK-REST-01/B,
  DG-HOOK-REST-02/A, DG-HOOK-REST-03/A, DG-HOOK-REST-04/A,
  DG-HOOK-LOG-01/B, and DG-HOOK-LIFE-01/A on 2026-09-11. The REST
  recommendation guarantees native 404 before a stale permission/handler
  callback, but intentionally does not hide a stale route name in the index
  of a reused REST server; a custom REST object remains a current-context
  delegate. Independent QA returned unconditional PASS on content head
  `06b07a7`, and PR #79 candidate head `d7ab4bd` passed all 17 protected
  jobs.
- The global RC threshold of 70% has been reached, but the release candidate
  remains unready until all 39 critical scenarios pass.
- Post-merge `master` after PR #95 had 19/19 successful required jobs.
  Earlier, after PR #90, five initial Composer-download HTTP 504 failures
  occurred before tests and passed on selective rerun.
- `master` is protected: strict required checks for all 19 jobs,
  administrator enforcement, and force-push and branch deletion disabled.
- `TEST-01`, `TEST-02A`, `TEST-02B`, `TEST-02C`, `TEST-02E`, and
  `TEST-02F` are complete. `TEST-02F` and `CORE-07` were completed as one
  paired vertical under approved DG-QMETA-01/A: independently verified red
  evidence is retained, and unit `7/19`, integration `68/353`, coverage
  `589/790 (74.56%)`, and PHPCS `36/36` passed.
- CORE-03 was completed by PR #67: merge `b36fa85` passed 17/17 required
  checks, issue #31 is closed, and stable errors 301—304 and the
  missing-endpoint matrix are covered.
- The owner approved DG-M1—DG-M9 on 2026-09-10. M5 limits deprecation to
  `Connection::load()`; `getPosts()` moved to separate investigation under
  REST issue #20 with its filtering/traversal/representation contract.
- The owner approved DP-1—DP-3 and review refinements on 2026-09-11:
  DG-QMETA-01/A, DG-UPDATE-01/02/02R/A, DG-SPI-01/02/07/A,
  DG-ENT-01—DG-ENT-06/A, DG-NAME-01—DG-NAME-06/A, and staged
  DG-NAME-06R/A-to-D. DP-4 is fully approved: DG-UPDATE-03/04/A,
  DG-SPI-03/04/06/A, DG-DB-01—03/A, and DG-DB-04/A-R; refinements
  DG-SPI-04R/06R/06R2 are also approved as A. DG-RESTERR-03/A was
  approved on 2026-09-11. DG-UPDATE-05/A, DG-SPI-05/A for v1 with C as
  the next-major target, DG-RESTERR-01/02/04/A, and DG-DELETE-05/06/A
  were approved on 2026-09-14. DG-DELETE-06/A additionally required a
  human-approved technical refinement of the durable repair/scheduler
  contract before DB-04 implementation. The owner approved
  DG-DELETE-06R1—DG-DELETE-06R3 as A on 2026-09-14, and
  DG-DELETE-06R4—DG-DELETE-06R6, discovered during Batch 18
  decomposition, as A on 2026-09-21. The owner also approved
  DG-API20-01—DG-API20-09 as B/B/A/B/B/A/B/B/B on 2026-09-14;
  REST-06 is complete and E4 accepted. API-03 was completed in
  Batch 25 / PR #114; API-04 was completed in Batch 26 / PR #116,
  and issue #20 is closed. At that point DOC-01 was ready for separate
  execution. Full decision texts are in the
  [related-entities/API issue #20 contract](../api-01-related-entities-contract.md),
  [partial update contract](../rest-partial-update-contract.md),
  [storage SPI contract](../storage-spi-contract.md),
  [entity validation contract](../entity-validation-contract.md),
  [database compatibility contract](../db-compatibility-contract.md),
  [REST error contract](../rest-error-contract.md),
  [REST v1 connection contract](../rest-connection-contract.md),
  [delete result/failure contract](../delete-result-contract.md),
  [deleted-post repair contract](../deleted-post-repair-contract.md), and
  [client naming contract](../client-naming-contract.md); the main registry
  holds canonical decisions and statuses. REST-00A, REST-00B, SPI-01,
  CORE-00, DB-00, DB-03A, and CORE-05 completed decision-ready discovery;
  this did not implicitly approve their recommendations.
- The canonical DB-03A
  [delete result/failure contract](../delete-result-contract.md) separates
  logical connection counts from metadata rows, relation-scoped domain/REST
  deletion from legacy client-wide SPI, and the internal atomic boundary
  from `deleted_post` recovery. DB-03B-A, DB-03B-B, DB-04-D, DB-04-I1,
  and DB-04-I2 are complete; Batch 18 was closed by PR #102 and exact
  merge `66f6fd3` with full 19/19 protected/post-merge evidence.
  HOOK-03/DB-04-I3 was completed in Batch 19 / PR #104 with exact merge
  `7cfe684` and full 20/20 protected/post-merge evidence;
  LIFE-HOOK-01 was completed in Batch 20 / PR #106; DB-04-I / DB-04-Q
  and B21-Q were completed through Batch 21 / PR #108 and closeout
  PR #109 (`ba0b546`) with fresh final QA `pass_with_notes`.
  REST-03 was completed by PR #95: exact candidate `56d5e1c` received
  independent QA PASS and passed 19/19 protected checks; merge `185bf32`
  passed 19/19 post-merge checks. The canonical default-v1 wire contract
  is in [`rest-connection-contract.md`](../rest-connection-contract.md).
  REST-04 was later completed in Batch 22 / PR #110; REST-05 was completed
  in PR #111, and its metadata contract is in
  [`rest-meta-contract.md`](../rest-meta-contract.md). REST-06 was
  completed in Batch 24 / PR #112; E4 was accepted, with full evidence
  provenance in the [Batch 24 checkpoint](batch24-checkpoint.md).
- DB-04-D was completed by PR #97: current call graph, crash windows,
  pre-armed ledger, retry state machine, operator boundary, and executable
  slices are in [`deleted-post-repair-contract.md`](../deleted-post-repair-contract.md).
  DG-DELETE-06R1—DG-DELETE-06R3 were approved as A. DB-04-I1 was
  completed by PR #99; the durable internal ledger was prepared without
  callback/scheduler activation. DB-04-I2 was completed by PR #102,
  and Batch 19 / PR #104 connected its runner/operator primitives
  through manager-backed deleted-post recovery.
- Standard regressions already protect missing-`to`, broken `both`,
  the full cardinality matrix, and Query-meta materialization. REST
  update without `title` is protected by the completed Batch 11 /
  REST-02 regression coverage.

## Milestones

- **M0 — Infrastructure ready — completed 2026-09-10:** the infrastructure
  plan DoD was met, PR #48 merged into `master`, and post-merge CI passed.
- **M1 — Regression harness ready:** isolated integration fixtures and six
  confirmed regression tests are in `master` alongside their fixes.
- **M2 — Core invariants stable:** validation, cardinality, duplicatable,
  and closurable behavior are fixed and protected by the test matrix.
- **M3 — Storage stable:** find, update, delete, metadata cascade, and schema
  recovery are protected by integration tests.
- **M4 — REST v1 stable:** routes, permissions, CRUD, errors, and metadata
  are tested through the WordPress REST server.
- **M5 — Release ready:** the compatibility contract is approved,
  documentation is synchronized, and the release candidate passes the
  full matrix.
