# Deleted-post recovery verification manifest

Status: active DB-04-Q manifest. B21-01—B21-09 are complete; B21-10 is the
next selected task. The final exact qualification candidate and
protected CI results remain owned by B21-10/B21-Q.

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
- Final exact candidate: pending B21-10 after B21-01—B21-09 are complete.
- Final merge and post-merge identity: pending B21-Q.

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
| Two database contenders cannot both acquire one live lease | `DeletedPostRepairLedgerTest::test_two_database_contenders_cannot_both_acquire_one_live_lease` | pinned MySQL 8.0.46 and MariaDB 10.11.16 WP integration | verified in B21-05 (`e039db0`); full-vendor rerun — B21-10 |
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
| Disabled WP-Cron is observable without disabling the storage API | `DeletedPostRepairSchedulerTest::test_dispatch_availability_reports_disabled_wp_cron_without_affecting_storage_api` plus `DeletedPostRepairOperationalTest::test_disabled_cron_keeps_real_failure_inspectable_and_manually_retryable` | single-site and true-multisite WP | verified in B21-08 (`65c53e8`); retained component rerun — B21-10 |
| Per-site list, single retry, due batch, exhaustion and stored-event reconciliation are executable | `DeletedPostRepairOperationalTest::test_due_batch_and_direct_runner_expose_exhaustion_for_manual_attention`, `test_disabled_cron_keeps_real_failure_inspectable_and_manually_retryable`; retained `DeletedPostRepairServiceTest` and scheduler/reconciler tests; `docs/deleted-post-repair-operations.md` | WP integration and runbook | verified in B21-08 (`65c53e8`); full retained rerun — B21-10 |
| Unresolved work survives Client disposal/runtime reconstruction | `DeletedPostRepairOperationalTest::test_unresolved_work_survives_client_disposal_and_runtime_reconstruction` | single-site and true-multisite WP | verified in B21-09 (`85dfa8d`) |
| Rollback/consumer uninstall preserves repair table, ownership option and unresolved rows | named preservation test; retained ledger `test_purge_*` tests; operations rehearsal and source audit in `docs/deleted-post-repair-operations.md` | WP integration and source audit | verified library boundary in B21-09 (`85dfa8d`); unknown consumer uninstall remains HOOK-04 responsibility |
| Complete real-flow and ledger/claim/concurrency suite passes MySQL 8.0.46 and MariaDB 10.11.16 | exact-candidate CI jobs and commands | pinned vendor CI | planned — B21-10 |
| Dedicated `WP_MULTISITE=1` run executes without relevant skips | exact-candidate multisite job | protected CI | planned — B21-10 |
| Full regression, PHPCS, fixed-floor coverage and reverse/random isolation agree on one SHA | exact-candidate protected matrix | protected CI | planned — B21-10 |
| Independent correctness, security/data-integrity and operational reviews validate this completed manifest | exact-candidate review records | independent review | planned — B21-Q |

## Explicit R1–R6 contract mapping

Every row below is requalified by B21-10's full suites on the frozen candidate;
the earlier task evidence above remains attributed to its original revision.

| Approved gate | Exact retained/new tests | Required lane |
| --- | --- | --- |
| R1: manager ownership/version boundary | `DeletedPostRepairHookMigrationTest::test_direct_storage_callback_removal_no_longer_disables_cleanup`, `test_semantic_disable_and_enable_control_manager_delivery_idempotently`, `test_manager_subscription_uses_priority_ten_and_one_accepted_argument` | single-site and true-multisite integration |
| R2: durable owned ledger | `DeletedPostRepairSchemaTest::test_clean_install_uses_the_exact_owned_nonautoloaded_innodb_schema`, `test_unowned_existing_table_fails_closed_without_claim_alter_or_drop`; `AtomicMutationTest::test_deleted_post_real_flow_rollback_uncertainty_stays_durable_and_fail_closed`; operational preservation test above | both pinned vendors and true multisite |
| R3: wake-up/retry/operator lifecycle | `DeletedPostRepairOperationalTest`'s three named scenarios above; `DeletedPostRepairLedgerTest::test_two_database_contenders_cannot_both_acquire_one_live_lease`; `DeletedPostRepairPolicyTest::test_retry_delay_table`; retained purge tests | unit, both pinned vendors and true multisite |
| R4: safe Client-scoped service | `DeletedPostRepairServiceTest::test_getter_is_lazy_and_get_is_client_scoped_with_safe_projection`, `test_list_is_status_filtered_keyset_paginated_and_does_not_expose_foreign_rows`, `test_retry_outcomes_are_distinct_and_cleanup_failures_are_redacted`, `test_due_batch_is_bounded_uses_manual_mode_and_reports_exact_more_state`, `test_invalid_input_and_stale_context_fail_before_ledger_sql` | full integration and true multisite |
| R5: eligibility/single wake-up | `DeletedPostRepairReconcilerTest::test_reconciliation_uses_enabled_clients_and_earliest_cleanup_deadline`, `test_existing_earlier_event_is_kept_and_earlier_replacement_is_scheduled_before_old_removal`; `DeletedPostRepairSchedulerTest::test_native_adapter_persists_only_the_stable_empty_argument_site_event`; `DeletedPostRepairHookMigrationTest::test_same_name_multisite_cron_runs_only_the_active_site_worker` | unit, integration and true multisite |
| R6: claim budget | `DeletedPostRepairLedgerTest::test_automatic_claim_ceiling_moves_expired_ninth_claim_to_attention_without_a_tenth`, `test_manual_due_claim_bypasses_ceiling_but_not_future_or_attention_state`; operational direct-runner exhaustion scenario above | both pinned vendors and true multisite |

## B21-10 delivery preflight

Read-only GitHub inspection on 2026-09-22 found no PR for
`batch21-deleted-post-qualification`. `gh api
repos/hokoo/wpConnections/branches/master/protection/required_status_checks`
reported strict checks with 19 contexts; the dedicated multisite job was absent.
`gh api repos/hokoo/wpConnections/rules/branches/master` returned no additional
rules. The workflow already defines 20 jobs and the Batch 19/20 contract
requires the multisite lane. The CI runbook now includes that existing job.
No remote setting was changed. Protected CI, publication and any protection
reconciliation remain a delivery gate, separate from the local qualification.

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
- This manifest closes only with the exact B21-10 candidate and B21-Q merge
  evidence. It does not authorize a 2.0 tag or release.
