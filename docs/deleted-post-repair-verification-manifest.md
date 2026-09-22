# Deleted-post recovery verification manifest

Status: completed DB-04-Q qualification manifest. B21-01—B21-Q, Batch 21,
DB-04-I and DB-04-Q are complete. The local candidate, protected PR #108,
closeout PR #109 and their exact merge/post-merge checks are green; fresh final
QA accepted exact closeout merge `ba0b546` with `pass_with_notes`.

The owner resumed execution on 2026-09-22. The historical pause and the exact
resumed evidence are preserved in [the checkpoint](plans/batch21-checkpoint.md).

## Evidence identity

- Batch 21 base: `f7e94af0e9b039da90260162db44ea30635fc3ec`
  (Batch 20 documentation closeout merge).
- Real-flow fixture commit:
  `0a15b4d224358ac84dbb6aa117ba60b368001097`.
- Real data-cascade matrix commit:
  `ac6361fde37121eed1c4a652efdd17fa91d47c0f`.
- Pre-commit/arm/wake-up matrix commit:
  `01daf040cccc183b713f4f31d7dccbbdae155aea`.
- Post-commit/crash-window matrix commit:
  `7587271fe87adbdf2a7a4a89fedb72f6cdff399c`.
- Real-flow due-retry bridge commit:
  `e039db0a8d50bb280e21872993d636e27b033359`.
- True-multisite routing matrix commits:
  `6a9726b` (same-name/same-ID behavior) and
  `39189ffdb047b366ba88837bee57f86f9a77658f` (repeat-safe fixture teardown).
- Custom-adapter real-flow matrix commit:
  `89dbbe78075dd978d224ee0b997a706512ca8a98`.
- Operator/degraded-cron tests and canonical runbook: `65c53e8`.
- Lifecycle preservation rehearsal and rollback/uninstall runbook: `85dfa8d`.
- Primary fixtures: `DeletedPostRecoveryRealFlowTest` and
  `AtomicMutationTest`, with deterministic lease-boundary evidence in
  `DeletedPostRepairExecutorTest`.
- Production delta through B21-09: none. The observed paths were committed as
  characterization because the approved behavior was already correct.
- Frozen local qualification candidate: `062b7fefb3e4cec6261b3a9b101958f47219f2b1`.
- Complete local technical candidate: `062b7fefb3e4cec6261b3a9b101958f47219f2b1`.
- Protected PR #108 head:
  `1e8a4c9dce91aa805fb2f26733d81c47044ec1f2`.
- Exact protected merge:
  `a341f9b89427e64c66dd4ab03d8d3f664e18fd2a` at
  `2026-09-22T09:33:19Z`.

An `existing` row below is retained evidence only for the observation named in
that row. A `planned` row is not accepted evidence; it names the task and test
target that must replace that status. Aggregate coverage cannot complete a
planned row.

## B21-01 fixture evidence

Environment for all PHPUnit commands below: PHP 8.1.34, WordPress 7.1-src,
Ramsey Collection 1.3.0 and MariaDB 11.8.6. Commands were run from
`local-dev/` against the exact fixture content in commit `0a15b4d`.

| Lane | Command | Result |
| --- | --- | --- |
| Focused single-site WP | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest` | PASS — 2 tests, 18 assertions |
| Focused true multisite WP | `docker compose -p wpconnections run --rm phpunit test:multisite --filter DeletedPostRecoveryRealFlowTest` | PASS — 2 tests, 18 assertions, no skip |
| Reverse isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest --order-by=reverse --repeat=2` | PASS — 4 tests, 36 assertions |
| Seeded random isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest --order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 4 tests, 36 assertions; seed `20260922` |
| Fixture coding standard | `docker compose -p wpconnections run --rm phpunit vendor/bin/phpcs tests/iTRON/wpConnections/WP/DeletedPostRecoveryRealFlowTest.php --standard=phpcs.xml` | PASS — 1 file; the pre-existing PHPCS ruleset deprecation remains non-blocking |

The permanent-delete fixture uses real `wp_delete_post($id, true)` and
observes the removed post, zero physical connection/meta rows, exactly one
attempt hook, exactly one committed-success hook and no repair row. The
trash-only fixture uses real `wp_trash_post()` and observes a trashed post,
zero cleanup attempts, preserved connection/meta rows and no retry record.

## B21-02 data-cascade evidence

The following commands were run on exact commit `ac6361f` in the same runtime
environment. The reverse/random evidence was run on the exact committed test
content before commit and is content-identical to `ac6361f`.

| Lane | Command | Result |
| --- | --- | --- |
| Focused single-site WP | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest` | PASS — 5 tests, 60 assertions |
| Focused true multisite WP | `docker compose -p wpconnections run --rm phpunit test:multisite --filter DeletedPostRecoveryRealFlowTest` | PASS — 5 tests, 60 assertions, no skip |
| Reverse isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest --order-by=reverse --repeat=2` | PASS — 10 tests, 120 assertions |
| Seeded random isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest --order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 10 tests, 120 assertions; seed `20260922` |
| Full unit and WP integration | `docker compose -p wpconnections run --rm phpunit test:all` | PASS — unit 141 tests / 518 assertions; integration 493 tests / 4331 assertions / 8 pre-existing skips |
| Fixture coding standard | `docker compose -p wpconnections run --rm phpunit vendor/bin/phpcs tests/iTRON/wpConnections/WP/DeletedPostRecoveryRealFlowTest.php --standard=phpcs.xml` | PASS — 1 file; the pre-existing PHPCS ruleset deprecation remains non-blocking |

## B21-03 pre-commit, arm and wake-up evidence

The following commands were run on exact commit `01daf04`. The isolation
commands were run on the exact committed content before commit.

| Lane | Command | Result |
| --- | --- | --- |
| Focused new real-flow failures | `docker compose -p wpconnections run --rm phpunit test:integration --filter test_deleted_post_real_flow` | PASS — 8 tests/data sets, 133 assertions |
| Full atomic-mutation contour | `docker compose -p wpconnections run --rm phpunit test:integration --filter AtomicMutationTest` | PASS — 84 tests/data sets, 832 assertions |
| Focused true multisite WP | `docker compose -p wpconnections run --rm phpunit test:multisite --filter 'test_deleted_post_(callback_uses_atomic_delete_boundary|real_flow)'` | PASS — 9 tests/data sets, 142 assertions, no skip |
| Reverse isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter 'test_deleted_post_(callback_uses_atomic_delete_boundary|real_flow)' --order-by=reverse --repeat=2` | PASS — 18 tests/data sets, 284 assertions |
| Seeded random isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter 'test_deleted_post_(callback_uses_atomic_delete_boundary|real_flow)' --order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 18 tests/data sets, 284 assertions; seed `20260922` |
| Full unit and WP integration | `docker compose -p wpconnections run --rm phpunit test:all` | PASS — unit 141 tests / 518 assertions; integration 501 tests / 4464 assertions / 8 pre-existing skips |
| Project coding standard | `docker compose -p wpconnections run --rm phpunit cs:phpcs` | PASS — 100 source files; the pre-existing PHPCS ruleset deprecation remains non-blocking |

The first discovery run was 7/8 because the test used same-request
`get_post()` as proof of physical post state after a deliberately propagated
ledger-arm exception. WordPress deletes the row before `deleted_post`, but its
later `clean_post_cache()` is not reached when that hook throws, so the object
cache can retain the old `WP_Post`. The corrected test asserts the posts table
directly and cleans the fixture cache afterward. This was an observer defect,
not red production behavior and not a new decision gate.

## B21-04 post-commit and simulated crash evidence

The following commands were run on exact commit `7587271`. The tests model
durable states at process boundaries; they do not claim OS-level kill or
exactly-once consumer-effect coverage.

| Lane | Command | Result |
| --- | --- | --- |
| Focused single-site WP | `docker compose -p wpconnections run --rm phpunit test:integration --filter 'test_(deleted_post_real_flow_(arm_survives_claim_failure_and_is_immediately_retryable\|post_commit_hook_failure_resolves_on_no_match_retry\|commit_before_resolve_deduplicates_and_converges)\|simulated_death_during_cleanup_keeps_lease_until_expiry_then_reclaims)'` | PASS — 4 tests, 102 assertions |
| Focused true multisite WP | same filter with `test:multisite` | PASS — 4 tests, 102 assertions, no skip |
| Reverse isolation | focused single-site command plus `--order-by=reverse --repeat=2` | PASS — 8 tests, 204 assertions |
| Seeded random isolation | focused single-site command plus `--order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 8 tests, 204 assertions; seed `20260922` |
| Full unit and WP integration | `docker compose -p wpconnections run --rm phpunit test:all` | PASS — unit 141 tests / 518 assertions; integration 505 tests / 4566 assertions / 8 pre-existing skips |
| Project coding standard | `docker compose -p wpconnections run --rm phpunit cs:phpcs` | PASS — 100 source files; the pre-existing PHPCS ruleset deprecation remains non-blocking |

The first discovery run passed three scenarios and failed only the duplicate
delivery test because the test invoked WordPress's `deleted_post` action with
one argument. WordPress core observers require the real two-argument signature:
post ID plus deleted `WP_Post`. The corrected test preserves that snapshot,
passes both arguments and keeps storage-hook counters attached through retry.
This was a test-fixture defect, not red production behavior and not a new
decision gate.

## B21-05 concurrency, time and due-retry evidence

The following commands were run on exact commit `e039db0`. The real-flow
bridge adds only the missing cross-layer observation; the named ledger,
policy and worker tests remain the authority for their narrower invariants.

| Lane | Command | Result |
| --- | --- | --- |
| Real-flow reverse isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest --order-by=reverse --repeat=2` | PASS — 12 tests, 170 assertions |
| Real-flow seeded random isolation | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest --order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 12 tests, 170 assertions; seed `20260922` |
| Real-flow true multisite | `docker compose -p wpconnections run --rm phpunit test:multisite --filter DeletedPostRecoveryRealFlowTest` | PASS — 6 tests, 85 assertions, no skip |
| Policy and retention authority | `docker compose -p wpconnections run --rm phpunit test:phpunit --filter 'test_(retry_delay_table\|retention_runs_only_beyond_strict_boundary_and_uses_same_batch_bound)'` | PASS — 10 tests/data sets, 29 assertions |
| Pinned MySQL authority | fixed-floor test image with external `mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b`, `test:integration --filter 'test_(two_database_contenders_cannot_both_acquire_one_live_lease\|automatic_claim_ceiling_moves_expired_ninth_claim_to_attention_without_a_tenth\|manual_due_claim_bypasses_ceiling_but_not_future_or_attention_state)'` | PASS — runtime 8.0.46; 3 tests, 48 assertions |
| Pinned MariaDB authority | fixed-floor test image with external `mariadb:10.11.16@sha256:4045aba619003d93b5dc834e89e6815ba078d2cb3ff0a26f316ab5d7eab35093`, same focused filter | PASS — runtime 10.11.16-MariaDB; 3 tests, 48 assertions |
| Full unit and WP integration | `docker compose -p wpconnections run --rm phpunit test:all` | PASS — unit 141 tests / 518 assertions; integration 506 tests / 4591 assertions / 8 pre-existing skips |
| Fixture coding standard | `docker compose -p wpconnections run --rm phpunit vendor/bin/phpcs tests/iTRON/wpConnections/WP/DeletedPostRecoveryRealFlowTest.php --standard=phpcs.xml` | PASS — 1 file; the pre-existing PHPCS ruleset deprecation remains non-blocking |

`DeletedPostRecoveryRealFlowTest::
test_real_delete_failure_reaches_due_batch_retry_and_resolution` follows one
identity from a real `wp_delete_post()` cleanup failure (`retry_wait`, claim 1,
connection/meta intact), through a due public batch retry, to committed cleanup
and retained `resolved` evidence (claim 2, one failure). No production change
or new decision gate was required.

## B21-06 true-multisite context evidence

The behavior matrix was introduced in `6a9726b`; the exact verified test
candidate ends at `39189ffdb047b366ba88837bee57f86f9a77658f`. Both real blogs
use the same Client name and exact numeric page/post IDs. The fixture creates a
fresh Client in each active site context, as required by the approved consumer
responsibility boundary; it does not reconstruct or reroute a stale Client.

| Lane | Command | Result |
| --- | --- | --- |
| Focused new true-multisite scenarios | `docker compose -p wpconnections run --rm phpunit test:multisite --filter 'test_real_multisite_(delete_routes_same_name_and_post_id_to_active_client\|failure_ledgers_are_independent_for_same_name_and_post_id)'` | PASS — 2 tests, 55 assertions, no skip |
| Full real-flow true multisite | `docker compose -p wpconnections run --rm phpunit test:multisite --filter DeletedPostRecoveryRealFlowTest` | PASS — 8 tests, 140 assertions, no skip |
| Reverse true-multisite isolation | full real-flow command plus `--order-by=reverse --repeat=2` | PASS — 16 tests, 280 assertions; repeat-safe teardown emits no database errors |
| Seeded random true-multisite isolation | full real-flow command plus `--order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 16 tests, 280 assertions; seed `20260922` |
| Single-site fixture compatibility | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRecoveryRealFlowTest` | PASS — 8 tests, 85 assertions, 2 expected multisite-only skips |
| Full unit and WP integration | `docker compose -p wpconnections run --rm phpunit test:all` | PASS — unit 141 tests / 518 assertions; integration 508 tests / 4591 assertions / 10 expected skips |
| Fixture coding standard | `docker compose -p wpconnections run --rm phpunit vendor/bin/phpcs tests/iTRON/wpConnections/WP/DeletedPostRecoveryRealFlowTest.php --standard=phpcs.xml` | PASS — 1 file; the pre-existing PHPCS ruleset deprecation remains non-blocking |

The success scenario proves that deletion on site B invokes only site B's
fresh Client, removes only site B's connection/meta rows and ledger state,
then restoration to site A preserves its post and rows until site A receives
its own real deletion. The failure scenario proves independent repair keys and
`retry_wait`/resolution transitions for the two otherwise identical
identities. The first reverse-repeat run exposed teardown attempts after
WordPress had already removed a temporary blog; `39189ff` makes teardown
release global subscriptions unconditionally and touch site-local tables only
while that site database still exists. That was a fixture-lifecycle defect,
not a production routing defect. No production change or new decision gate
was required.

## B21-07 custom-adapter conformance evidence

The following commands were run against exact test candidate
`89dbbe78075dd978d224ee0b997a706512ca8a98`. The custom atomic fixture owns
seeded connection/meta state and implements rollback around its operation. A
separate mode deliberately models commit-confirmation uncertainty by retaining
the mutation and throwing after the operation, so the retry exercises the
approved idempotent no-match path.

| Lane | Command | Result |
| --- | --- | --- |
| Focused real-flow custom adapters | `docker compose -p wpconnections run --rm phpunit test:integration --filter 'test_(real_delete_custom_atomic\|real_delete_waits_for_fresh_custom_client\|real_delete_adapter_fingerprint_mismatch\|non_atomic_storage_performs_zero_writes)'` | PASS — 6 tests, 62 assertions |
| Reverse isolation | focused command plus `--order-by=reverse --repeat=2` | PASS — 12 tests, 124 assertions |
| Seeded random isolation | focused command plus `--order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 12 tests, 124 assertions; seed `20260922` |
| Full hook-migration single-site | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRepairHookMigrationTest` | PASS — 27 tests, 121 assertions, 5 expected multisite-only skips |
| Full hook-migration true multisite | same filter with `test:multisite` | PASS — 27 tests, 139 assertions, no skip |
| Fingerprint component authority | `docker compose -p wpconnections run --rm phpunit test:integration --filter test_adapter_fingerprint_mismatch_fails_closed_without_claim_or_connection_dml` | PASS — 1 test, 21 assertions |
| Full unit and WP integration | `docker compose -p wpconnections run --rm phpunit test:all` | PASS — unit 141 tests / 518 assertions; integration 513 tests / 4649 assertions / 10 expected skips |
| Project coding standard | `docker compose -p wpconnections run --rm phpunit cs:phpcs` | PASS — 100 source files; the pre-existing PHPCS ruleset deprecation remains non-blocking |
| Changed fixture syntax | `docker compose -p wpconnections run --rm phpunit php -l tests/iTRON/wpConnections/WP/DeletedPostRepairHookMigrationTest.php` | PASS — no syntax errors |

Real `wp_delete_post()` now proves custom atomic success, rollback-preserved
pre-commit failure, manual retry, committed no-match convergence, and
non-atomic rejection before any cleanup call. After the failing Client is
disposed, a scheduled runner with no fresh Client performs no storage call and
leaves the due row and seeded data intact; a fresh same-class Client can then
resolve it. Replacing the adapter class for the same Client name produces
`adapter_mismatch`/`needs_attention` before any replacement-adapter call and
preserves the seeded rows.

The call log intentionally shows that a retry can invoke an idempotent adapter
twice; unrelated adapter side effects remain outside the exactly-once contract.
Direct PHPCS of this pre-existing test file reports only its three unchanged
structural findings: its test doubles share one file and its old teardown
exceeds the configured nesting limit. No new-line style error was introduced;
canonical source PHPCS remains green. No production change or new decision
gate was required.

## B21-08 operator and degraded-cron evidence

The following commands tested the content committed in `65c53e8` on PHP
8.1.34, WordPress 7.1-src, Ramsey 1.3.0 and MariaDB 11.8.6. Docker commands
run from `local-dev/`.

| Lane | Command | Result |
| --- | --- | --- |
| Focused single-site | `docker compose -p wpconnections run --rm phpunit test:integration --filter DeletedPostRepairOperationalTest` | PASS — 2 tests, 49 assertions |
| Focused true multisite | same command with `test:multisite` | PASS — 2 tests, 49 assertions, no skip |
| Reverse isolation | focused single-site command plus `--order-by=reverse --repeat=2` | PASS — 4 tests, 98 assertions |
| Seeded random isolation | focused single-site command plus `--order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 4 tests, 98 assertions; seed `20260922` |
| Fixture coding standard | `docker compose -p wpconnections run --rm phpunit vendor/bin/phpcs tests/iTRON/wpConnections/WP/DeletedPostRepairOperationalTest.php --standard=phpcs.xml` | PASS — existing ruleset deprecation only |
| Syntax | `php -l tests/iTRON/wpConnections/WP/DeletedPostRepairOperationalTest.php` | PASS |

Both scenarios use the bootstrap's real `DISABLE_WP_CRON=true`. The first
follows real deletion failure through list/get/single retry; the second checks
due-batch selection, direct registered-runner exhaustion and explicit manual
recovery from `needs_attention`. The stored event is inspected directly; no
HTTP cron loopback is claimed. Component scheduler/reconciler tests retain
authority over detailed scheduling deadlines.

The first discovery run failed an overly strict event-timestamp equality:
WordPress duplicate-event suppression can leave the previously stored lease-safety
event when an earlier retry wake-up cannot be scheduled. The final test checks
the stable hook/empty-argument/valid-timestamp contract; durable work remains
manually recoverable. This was a test assertion correction, not a production
fix. The runbook uses the existing public service only.

## B21-09 preservation evidence

The following commands tested the content committed in `85dfa8d`, in the same
PHP 8.1.34 / WordPress 7.1-src / Ramsey 1.3.0 / MariaDB 11.8.6 environment.
Docker commands run from `local-dev/`.

| Lane | Command | Result |
| --- | --- | --- |
| Operational and retained purge protection | `docker compose -p wpconnections run --rm phpunit test:integration --filter 'DeletedPostRepairOperationalTest\|test_purge_'` | PASS — 6 tests, 154 assertions |
| Operational true multisite | `docker compose -p wpconnections run --rm phpunit test:multisite --filter DeletedPostRepairOperationalTest` | PASS — 3 tests, 84 assertions, no skip |
| True-multisite random repeat | previous command plus `--order-by=random --random-order-seed=20260922 --repeat=2` | PASS — 6 tests, 168 assertions; seed `20260922` |
| Fixture coding standard | `docker compose -p wpconnections run --rm phpunit vendor/bin/phpcs tests/iTRON/wpConnections/WP/DeletedPostRepairOperationalTest.php --standard=phpcs.xml` | PASS — existing ruleset deprecation only |
| Source audit | `rg -n 'uninstall\|register_uninstall_hook\|DROP TABLE\|TRUNCATE\|delete_option\|wpconnections_repair_schema_owner' src composer.json`, followed by lifecycle/ledger/helper source inspection | No automatic repair uninstall or table/owner deletion; generic legacy `Database::install_table(delete_first)` is not used by the repair ledger |

The named preservation test inventories real failed cleanup, disables delivery,
disposes the Client, reconstructs the process-local runtime and a fresh Client,
and checks the persisted unresolved row and owner value remain unchanged. Its
manual forward retry resolves the original repair without cleaning unrelated
data for a post deleted while delivery was disabled. No production correction
was needed. The test models runtime reconstruction; it does not execute a
version downgrade, OS restart or an unknown consumer plugin's uninstaller.
Those limits and the consumer's preservation responsibility are explicit in the
operations runbook. Normal fixture cleanup remains separate from the rehearsal.

## B21-10 exact-candidate local evidence

Candidate: `062b7fefb3e4cec6261b3a9b101958f47219f2b1`. The current lanes ran
with PHP 8.1.34, WordPress 7.1-src (requested 7.1.0), Ramsey 1.3.0 and
embedded MariaDB 11.8.6. The fixed-floor and vendor lanes ran from a clean
`git archive` export with PHP 8.1.34, WordPress 6.7.7-src and Ramsey 1.3.0.
Every listed command exited 0.

| Command / phase | Result | Artifact |
| --- | --- | --- |
| `make tests.phpunit` | PASS — 141 tests, 518 assertions | `/tmp/wpconnections-b21-uQxgT6/01-tests.phpunit-escalated.log` |
| `make tests.integration` | PASS — 516 tests, 4733 assertions, 10 multisite-only skips | `/tmp/wpconnections-b21-uQxgT6/02-tests.integration.log` |
| `make tests.multisite` | PASS — 516 tests, 4831 assertions, no skip | `/tmp/wpconnections-b21-uQxgT6/03-tests.multisite.log` |
| `make tests.isolation ISOLATION_SEED=20260922`: unit reverse/random, repeat 2 | PASS — each phase 282 tests, 1036 assertions | `/tmp/wpconnections-b21-uQxgT6/04-tests.isolation.log` |
| Same isolation command: integration reverse/random, repeat 2 | PASS — each phase 1032 tests, 9466 assertions, 20 skips | same log; seed `20260922` |
| `make lint.phpcs` | PASS — 100/100 files, no violations | `/tmp/wpconnections-b21-run/06-lint.phpcs-confirmation.log` |
| `make tests.coverage` | PASS — 657 tests, 5249 assertions, 10 skips; 3833/4172 statements (91.87%) | `/tmp/wpconnections-b21-run/02-tests.coverage.log` |
| `docker run --rm -v "$PWD:/srv/web" wpconnections-coverage:php8.1.34-wp6.7.7 test:multisite` | PASS — 516 tests, 4831 assertions, no skip | `/tmp/wpconnections-b21-run/03-tests.multisite-fixedfloor.log` |
| Digest-pinned MySQL 8.0.46 full integration | PASS — 516 tests, 4733 assertions, 10 multisite-only skips | `/tmp/wpconnections-b21-run/04-mysql-8.0.46.log` |
| Digest-pinned MariaDB 10.11.16 full integration | PASS — 516 tests, 4733 assertions, 10 multisite-only skips | `/tmp/wpconnections-b21-run/05-mariadb-10.11.16.log` |

The checkpoint records exact commands, durations, static test-only environment
values and artifact identity. The export has zero tracked-content mismatches
against `062b7fe`, and its `composer.lock` is identical. These results belong
to the source/test candidate and are not assigned to the later pause or
evidence-only documentation revisions.

Independent QA returned `fail` solely because the durable evidence/status and
external protected CI/protection/merge/post-merge criteria were incomplete.
Its technical and operational review found no P0—P3 finding or new decision
gate. This evidence remediation closes the documentary gap but is not fresh QA
and does not establish epic acceptance. The retained WordPress
`fonts.php:218` null-`post_type` and PHPCS ruleset deprecation warnings remain
non-blocking P4 attribution notes; no waiver was requested or accepted.

That historical gate was followed by exact-candidate QA which confirmed all
pre-merge requirements, found no P0—P3 finding or new decision gate, and found
the candidate technically ready. PR #108 and its exact merge satisfied the
external delivery criteria below. At that historical boundary synchronized
closure QA was still pending and B21-Q / DB-04-Q remained in `review`;
closeout PR #109 subsequently merged as `ba0b546`, passed 20/20 post-merge
contexts and received fresh final QA `pass_with_notes` without P0—P3,
decision gate, exception or waiver.

## Traceability matrix

### Real deletion and data effect

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Real permanent-delete entry, physical row cleanup, attempt/commit hook timing and no repair after first-attempt success | `DeletedPostRecoveryRealFlowTest::test_permanent_delete_fixture_observes_data_hooks_and_repair_state` | single-site WP; true multisite WP | verified in B21-01 (`0a15b4d`) |
| Trash-only flow does not enter permanent-delete cleanup | `DeletedPostRecoveryRealFlowTest::test_trash_only_fixture_does_not_run_permanent_delete_cascade` | single-site WP; true multisite WP | verified in B21-01 (`0a15b4d`) |
| Incoming/to-end cleanup and semantic disable/enable | `ClientIsolationTest::test_semantic_post_deletion_lifecycle_controls_real_cleanup_once` | single-site WP integration | existing |
| Outgoing/from-end legacy row and metadata cleanup | `EntityValidationTest::test_deleted_post_cascade_cleans_legacy_row_without_endpoint_resolution` | single-site WP integration | existing |
| Incoming, outgoing and self connections in one physical-row matrix | `DeletedPostRecoveryRealFlowTest::test_permanent_delete_cascades_all_endpoint_shapes_and_preserves_unrelated_rows` | single-site WP; true multisite WP | verified in B21-02 (`ac6361f`) |
| Multiple relations preserve unrelated relation rows | `DeletedPostRecoveryRealFlowTest::test_permanent_delete_cascades_all_endpoint_shapes_and_preserves_unrelated_rows` | single-site WP; true multisite WP | verified in B21-02 (`ac6361f`) |
| Multiple live Clients remove only their own rows and emit their own hooks once | `DeletedPostRecoveryRealFlowTest::test_permanent_delete_isolates_multiple_clients_and_their_success_hooks` | single-site WP; true multisite WP | verified in B21-02 (`ac6361f`) |
| Permanent attachment deletion follows the same cascade | `DeletedPostRecoveryRealFlowTest::test_permanent_attachment_delete_uses_the_same_cascade_contract` | single-site WP; true multisite WP | verified in B21-02 (`ac6361f`) |

### Failure and commit windows

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Connection-delete DML failure rolls back connection/meta and leaves `retry_wait` | `AtomicMutationTest::test_deleted_post_callback_uses_atomic_delete_boundary` | single-site WP; true multisite WP | retained and reverified in B21-03 (`01daf04`) |
| Selector read, metadata delete, transaction-start and commit failures through real deletion | `AtomicMutationTest::test_deleted_post_real_flow_precommit_failure_is_retryable_after_confirmed_rollback` | single-site WP; true multisite WP | verified in B21-03 (`01daf04`) |
| Storage attempt-hook `Throwable` performs no cleanup writes and leaves redacted `retry_wait` | `AtomicMutationTest::test_deleted_post_real_flow_attempt_hook_throwable_is_retryable_without_writes` | single-site WP; true multisite WP | verified in B21-03 (`01daf04`) |
| Ledger-arm failure propagates before cleanup DML and creates no false repair row | `AtomicMutationTest::test_deleted_post_real_flow_arm_failure_propagates_before_cleanup_dml` | single-site WP; true multisite WP | verified in B21-03 (`01daf04`) |
| Scheduler failure cannot erase cleanup failure or durable retry state | `AtomicMutationTest::test_deleted_post_real_flow_scheduler_failure_preserves_retryable_work` | single-site WP; true multisite WP | verified in B21-03 (`01daf04`) |
| Rollback-confirmation failure retains a committed `running` identity and leaves the shared session fail-closed without asserting restoration | `AtomicMutationTest::test_deleted_post_real_flow_rollback_uncertainty_stays_durable_and_fail_closed` | single-site WP; true multisite WP; second DB observer | verified in B21-03 (`01daf04`) |
| Post-commit success-hook failure is retryable and zero-result retry resolves uncertainty | `AtomicMutationTest::test_deleted_post_real_flow_post_commit_hook_failure_resolves_on_no_match_retry` plus retained executor component test | single-site WP; true multisite WP | verified in B21-04 (`7587271`) |
| Death after durable arm before claim leaves immediately claimable `armed` state | `AtomicMutationTest::test_deleted_post_real_flow_arm_survives_claim_failure_and_is_immediately_retryable` | single-site WP; true multisite WP | verified in B21-04 (`7587271`) |
| Death during cleanup leaves `running` until lease expiry | `DeletedPostRepairExecutorTest::test_simulated_death_during_cleanup_keeps_lease_until_expiry_then_reclaims` | single-site WP; true multisite WP | verified in B21-04 (`7587271`) |
| Death after commit before resolve leaves `running`; no-match retry resolves it | `AtomicMutationTest::test_deleted_post_real_flow_commit_before_resolve_deduplicates_and_converges` | single-site WP; true multisite WP | verified in B21-04 (`7587271`) |
| Duplicate `deleted_post` delivery deduplicates one logical identity | `AtomicMutationTest::test_deleted_post_real_flow_commit_before_resolve_deduplicates_and_converges` | single-site WP; true multisite WP | verified in B21-04 (`7587271`); retained race authority — B21-05 |

### Concurrency and time

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Two database contenders cannot both acquire one live lease | `DeletedPostRepairLedgerTest::test_two_database_contenders_cannot_both_acquire_one_live_lease` | pinned MySQL 8.0.46 and MariaDB 10.11.16 WP integration | verified in B21-05 (`e039db0`); exact `062b7fe` full-vendor runs PASS locally |
| Expired lease reclaim and automatic nine-claim ceiling | `DeletedPostRepairLedgerTest::test_automatic_claim_ceiling_moves_expired_ninth_claim_to_attention_without_a_tenth` | local and both pinned-vendor WP integration | verified in B21-05 (`e039db0`) |
| Manual recovery bypasses the automatic ceiling but not future/attention state | `DeletedPostRepairLedgerTest::test_manual_due_claim_bypasses_ceiling_but_not_future_or_attention_state` | local and both pinned-vendor WP integration | verified in B21-05 (`e039db0`) |
| Every retry delay is exact | `DeletedPostRepairPolicyTest::test_retry_delay_table` | unit | verified in B21-05 (`e039db0`) |
| Strict resolved retention and shared batch bound | `DeletedPostRepairWorkerTest::test_retention_runs_only_beyond_strict_boundary_and_uses_same_batch_bound` | unit | verified in B21-05 (`e039db0`) |
| One real-flow identity is followed from failure through due retry to resolution | `DeletedPostRecoveryRealFlowTest::test_real_delete_failure_reaches_due_batch_retry_and_resolution` | single-site WP; true multisite WP | verified in B21-05 (`e039db0`) |

### Context and adapters

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Active-site cleanup does not mutate the previous site's default-storage rows | `ClientIsolationTest::test_default_storage_is_prefix_bound_and_fresh_client_uses_new_prefix` plus `DeletedPostRecoveryRealFlowTest::test_real_multisite_delete_routes_same_name_and_post_id_to_active_client` | dedicated true multisite WP | verified in B21-06 (`6a9726b`, `39189ff`) |
| Same Client name and numeric post ID stay independent on two blogs through real deletion | `DeletedPostRecoveryRealFlowTest::test_real_multisite_delete_routes_same_name_and_post_id_to_active_client` and `test_real_multisite_failure_ledgers_are_independent_for_same_name_and_post_id` | dedicated true multisite WP | verified in B21-06 (`6a9726b`, `39189ff`) |
| Active, inactive and restored contexts require a fresh per-site Client | `DeletedPostRecoveryRealFlowTest::test_real_multisite_delete_routes_same_name_and_post_id_to_active_client` | dedicated true multisite WP | verified in B21-06 (`6a9726b`, `39189ff`) |
| Non-atomic custom storage performs zero writes and exposes redacted attention | `DeletedPostRepairHookMigrationTest::test_non_atomic_storage_performs_zero_writes_and_exposes_redacted_attention` through real `wp_delete_post()` | WP integration; true multisite WP | verified in B21-07 (`89dbbe7`) |
| Adapter fingerprint mismatch fails closed before claim or connection DML | `DeletedPostRepairLedgerTest::test_adapter_fingerprint_mismatch_fails_closed_without_claim_or_connection_dml` plus `DeletedPostRepairHookMigrationTest::test_real_delete_adapter_fingerprint_mismatch_fails_closed` | WP integration | verified in B21-07 (`89dbbe7`) |
| Custom atomic success, failure and committed no-match retry follow default durable outcomes | `DeletedPostRepairHookMigrationTest::test_real_delete_custom_atomic_success_cleans_seeded_rows`, `test_real_delete_custom_atomic_precommit_failure_rolls_back_and_retries`, and `test_real_delete_custom_atomic_commit_uncertainty_resolves_on_no_match` | WP integration; true multisite WP | verified in B21-07 (`89dbbe7`) |
| Missing fresh Client remains visible and performs no mutation | `DeletedPostRepairHookMigrationTest::test_real_delete_waits_for_fresh_custom_client_before_retry` | WP integration; true multisite WP | verified in B21-07 (`89dbbe7`) |

### Operator, preservation and infrastructure

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Disabled WP-Cron is observable without disabling the storage API | `DeletedPostRepairSchedulerTest::test_dispatch_availability_reports_disabled_wp_cron_without_affecting_storage_api` plus `DeletedPostRepairOperationalTest::test_disabled_cron_keeps_real_failure_inspectable_and_manually_retryable` | single-site and true-multisite WP | verified in B21-08 (`65c53e8`); requalified by exact `062b7fe` integration and multisite lanes |
| Per-site list, single retry, due batch, exhaustion and stored-event reconciliation are executable | `DeletedPostRepairOperationalTest::test_due_batch_and_direct_runner_expose_exhaustion_for_manual_attention`, `test_disabled_cron_keeps_real_failure_inspectable_and_manually_retryable`; retained `DeletedPostRepairServiceTest` and scheduler/reconciler tests; `docs/deleted-post-repair-operations.md` | WP integration and runbook | verified in B21-08 (`65c53e8`); requalified by exact `062b7fe` full local lanes |
| Unresolved work survives Client disposal/runtime reconstruction | `DeletedPostRepairOperationalTest::test_unresolved_work_survives_client_disposal_and_runtime_reconstruction` | single-site and true-multisite WP | verified in B21-09 (`85dfa8d`) |
| Rollback/consumer uninstall preserves repair table, ownership option and unresolved rows | named preservation test; retained ledger `test_purge_*` tests; operations rehearsal and source audit in `docs/deleted-post-repair-operations.md` | WP integration and source audit | verified library boundary in B21-09 (`85dfa8d`); unknown consumer uninstall remains HOOK-04 responsibility |
| Complete real-flow and ledger/claim/concurrency suite passes MySQL 8.0.46 and MariaDB 10.11.16 | exact digest-pinned commands and logs in the checkpoint | local and protected pinned-vendor qualification | PASS locally on `062b7fe`, protected PR head `1e8a4c9` and merge `a341f9b` — B21-10 completed |
| Dedicated `WP_MULTISITE=1` run executes without relevant skips | current `make tests.multisite` and fixed-floor `test:multisite` command above | local dedicated multisite and protected CI | PASS locally on `062b7fe`, both 516/4831 with no skip; dedicated protected context passed on PR head and merge — B21-10 completed |
| Full regression, PHPCS, fixed-floor coverage and reverse/random isolation agree on one SHA | exact commands and logs above | local exact-candidate matrix and protected delivery | PASS locally on `062b7fe`; all 20 protected contexts passed on PR head `1e8a4c9` and merge `a341f9b` — B21-10 completed |
| Independent correctness, security/data-integrity and operational reviews validate this completed manifest | independent QA gate record | independent review | fresh final QA accepted closeout merge `ba0b546` with `pass_with_notes`; no P0—P3, hidden decision, active exception or waiver — B21-Q completed |

## Explicit R1–R6 contract mapping

Every row below was requalified by the named full local lanes on frozen
candidate `062b7fe`; the earlier task evidence above remains attributed to its
original revision. Protected CI reproduced the required matrix on PR head
`1e8a4c9` and exact merge `a341f9b`; those results remain separate from the
local result.

| Approved gate / quality ID | Exact retained/new tests | Relevant passing local lanes on `062b7fe` |
| --- | --- | --- |
| R1: manager ownership/version boundary | `DeletedPostRepairHookMigrationTest::test_direct_storage_callback_removal_no_longer_disables_cleanup`, `test_semantic_disable_and_enable_control_manager_delivery_idempotently`, `test_manager_subscription_uses_priority_ten_and_one_accepted_argument` | current integration 516/4733 and true multisite 516/4831; fixed-floor coverage and both pinned-vendor integration runs |
| R2: durable owned ledger | `DeletedPostRepairSchemaTest::test_clean_install_uses_the_exact_owned_nonautoloaded_innodb_schema`, `test_unowned_existing_table_fails_closed_without_claim_alter_or_drop`; `AtomicMutationTest::test_deleted_post_real_flow_rollback_uncertainty_stays_durable_and_fail_closed`; operational preservation test above | current integration and true multisite; fixed-floor coverage and multisite; MySQL 8.0.46 and MariaDB 10.11.16 integration |
| R3: wake-up/retry/operator lifecycle | `DeletedPostRepairOperationalTest`'s three named scenarios above; `DeletedPostRepairLedgerTest::test_two_database_contenders_cannot_both_acquire_one_live_lease`; `DeletedPostRepairPolicyTest::test_retry_delay_table`; retained purge tests | unit 141/518; current/fixed-floor integration and true multisite; both pinned-vendor integration runs |
| R4: safe Client-scoped service | `DeletedPostRepairServiceTest::test_getter_is_lazy_and_get_is_client_scoped_with_safe_projection`, `test_list_is_status_filtered_keyset_paginated_and_does_not_expose_foreign_rows`, `test_retry_outcomes_are_distinct_and_cleanup_failures_are_redacted`, `test_due_batch_is_bounded_uses_manual_mode_and_reports_exact_more_state`, `test_invalid_input_and_stale_context_fail_before_ledger_sql` | current integration 516/4733 and true multisite 516/4831; fixed-floor coverage and multisite |
| R5: eligibility/single wake-up | `DeletedPostRepairReconcilerTest::test_reconciliation_uses_enabled_clients_and_earliest_cleanup_deadline`, `test_existing_earlier_event_is_kept_and_earlier_replacement_is_scheduled_before_old_removal`; `DeletedPostRepairSchedulerTest::test_native_adapter_persists_only_the_stable_empty_argument_site_event`; `DeletedPostRepairHookMigrationTest::test_same_name_multisite_cron_runs_only_the_active_site_worker` | unit 141/518; current/fixed-floor integration and true multisite; reverse/random isolation seed `20260922` |
| R6: claim budget | `DeletedPostRepairLedgerTest::test_automatic_claim_ceiling_moves_expired_ninth_claim_to_attention_without_a_tenth`, `test_manual_due_claim_bypasses_ceiling_but_not_future_or_attention_state`; operational direct-runner exhaustion scenario above | current and fixed-floor true multisite; MySQL 8.0.46 and MariaDB 10.11.16 integration |
| `HOOK-CASCADE-01`: real delete cascade and isolation | `DeletedPostRecoveryRealFlowTest::test_permanent_delete_fixture_observes_data_hooks_and_repair_state`, `test_permanent_delete_cascades_all_endpoint_shapes_and_preserves_unrelated_rows`, `test_permanent_delete_isolates_multiple_clients_and_their_success_hooks`, `test_permanent_attachment_delete_uses_the_same_cascade_contract` | current/fixed-floor integration and true multisite; both pinned vendors; reverse/random isolation seed `20260922` |

## B21-10 protected delivery

Historical read-only preflight on 2026-09-22 found no PR, protected `master`
at Batch 21 base `f7e94af`, and 19 strict contexts without the already-defined
dedicated multisite job. Under the repository owner's continuing delivery
authority, that missing context was added and the full protected delivery was
completed without a waiver.

PR [#108](https://github.com/hokoo/wpConnections/pull/108) had exact head
`1e8a4c9dce91aa805fb2f26733d81c47044ec1f2`. Each of the following 20 unique
required contexts completed successfully from GitHub Actions app id `15368`;
none failed, skipped, cancelled or became stale. The same 20 contexts completed
successfully on exact merge `a341f9b89427e64c66dd4ab03d8d3f664e18fd2a`.

| Required contexts | PR run | Post-merge run |
| --- | --- | --- |
| `php-cs` | [35710174076](https://github.com/hokoo/wpConnections/actions/runs/35710174076) | [35711052168](https://github.com/hokoo/wpConnections/actions/runs/35711052168) |
| `Coverage PHP 8.1.34 / WordPress 6.7.7` | [35710174129](https://github.com/hokoo/wpConnections/actions/runs/35710174129) | [35711052256](https://github.com/hokoo/wpConnections/actions/runs/35711052256) |
| `MySQL 8.0.46`; `MariaDB 10.11.16` | [35710174039](https://github.com/hokoo/wpConnections/actions/runs/35710174039) | [35711052099](https://github.com/hokoo/wpConnections/actions/runs/35711052099) |
| `WP Integration PHP 8.1.34 / WP 6.7.7 / Ramsey 1.3.0`; `WP Integration PHP 8.2.33 / WP 7.1.0 / Ramsey 1.3.0`; `WP Integration PHP 8.3.33 / WP 7.1.0 / Ramsey 2.1.1`; `WP Integration PHP 8.4.25 / WP 6.7.7 / Ramsey 2.1.1`; `WP Integration PHP 8.5.10 / WP 7.1.0 / Ramsey 2.1.1`; `WP Multisite PHP 8.1.34 / WP 6.7.7 / Ramsey 1.3.0` | [35710174082](https://github.com/hokoo/wpConnections/actions/runs/35710174082) | [35711052219](https://github.com/hokoo/wpConnections/actions/runs/35711052219) |
| Unit Tests PHP `8.1.34`, `8.2.33`, `8.3.33`, `8.4.25`, and `8.5.10`, each with Ramsey `1.3.0` and `2.1.1` | [35710174099](https://github.com/hokoo/wpConnections/actions/runs/35710174099) | [35711052082](https://github.com/hokoo/wpConnections/actions/runs/35711052082) |

Branch protection records all 20 contexts with `strict: true` and app id
`15368`; admin enforcement is enabled, while force pushes and deletions are
disabled. PR #108 merged at `2026-09-22T09:33:19Z`. Closeout PR #109 merged as
`ba0b546`; all 20 post-merge contexts passed and fresh final QA accepted the
synchronized status/evidence as `pass_with_notes`.

## Qualification rules

- A newly observed behavior defect must have recorded red evidence before its
  production correction. An already-correct path remains characterization.
- A simulated crash seam proves only the named durable boundary; it does not
  promise OS-level process-kill coverage or exactly-once consumer side
  effects.
- WordPress's CLI test bootstrap does not provide an HTTP cron loopback.
  Qualification therefore inspects stored wake-up reconciliation and invokes
  the runner directly.
- A multisite skip, a single-vendor pass or aggregate coverage cannot replace
  the named acceptance evidence.
- This qualification closed when fresh B21-Q review accepted closeout PR #109
  and exact merge `ba0b546`. It does not authorize a 2.0 tag or release.
