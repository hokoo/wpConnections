# Deleted-post recovery verification manifest

Status: active DB-04-Q manifest. B21-01—B21-04 are complete; B21-05 is the
next selected task, while B21-06 and B21-07 are also dependency-ready for their
shared later review group. The final exact qualification candidate and
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
- Primary fixtures: `DeletedPostRecoveryRealFlowTest` and
  `AtomicMutationTest`, with deterministic lease-boundary evidence in
  `DeletedPostRepairExecutorTest`.
- Production delta through B21-04: none. The observed paths were committed as
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
| Two database contenders cannot both acquire one live lease | `DeletedPostRepairLedgerTest::test_two_database_contenders_cannot_both_acquire_one_live_lease` | pinned MySQL and MariaDB WP integration | existing; vendor rerun — B21-05/B21-10 |
| Expired lease reclaim and automatic nine-claim ceiling | `DeletedPostRepairLedgerTest::test_automatic_claim_ceiling_moves_expired_ninth_claim_to_attention_without_a_tenth` | WP integration | existing — B21-05 |
| Manual recovery bypasses the automatic ceiling but not future/attention state | `DeletedPostRepairLedgerTest::test_manual_due_claim_bypasses_ceiling_but_not_future_or_attention_state` | WP integration | existing — B21-05 |
| Every retry delay is exact | `DeletedPostRepairPolicyTest::test_retry_delay_table` | unit | existing — B21-05 |
| Strict resolved retention and shared batch bound | `DeletedPostRepairWorkerTest::test_retention_runs_only_beyond_strict_boundary_and_uses_same_batch_bound` | unit | existing — B21-05 |
| One real-flow identity is followed from failure through due retry to resolution | `DeletedPostRecoveryRealFlowTest` failure-to-resolution bridge | single-site WP integration | planned — B21-05 |

### Context and adapters

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Active-site cleanup does not mutate the previous site's default-storage rows | `ClientIsolationTest::test_default_storage_is_prefix_bound_and_fresh_client_uses_new_prefix` | dedicated true multisite WP | existing baseline — B21-06 |
| Same Client name and numeric post ID stay independent on two blogs through real deletion | `DeletedPostRecoveryRealFlowTest` same-name/same-ID method | dedicated true multisite WP | planned — B21-06 |
| Active, inactive and restored contexts require a fresh per-site Client | `DeletedPostRecoveryRealFlowTest` context-routing methods | dedicated true multisite WP | planned — B21-06 |
| Non-atomic custom storage performs zero writes and exposes redacted attention | `DeletedPostRepairHookMigrationTest::test_non_atomic_storage_performs_zero_writes_and_exposes_redacted_attention` | WP integration | existing component evidence; real-flow bridge — B21-07 |
| Adapter fingerprint mismatch fails closed before claim or connection DML | `DeletedPostRepairLedgerTest::test_adapter_fingerprint_mismatch_fails_closed_without_claim_or_connection_dml` | WP integration | existing component evidence; real-flow bridge — B21-07 |
| Custom atomic success, failure and committed no-match retry follow default durable outcomes | `DeletedPostRecoveryRealFlowTest` custom-adapter methods | WP integration | planned — B21-07 |
| Missing fresh Client remains visible and performs no mutation | `DeletedPostRecoveryRealFlowTest` missing-client method | WP integration | planned — B21-07 |

### Operator, preservation and infrastructure

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Disabled WP-Cron is observable without disabling the storage API | `DeletedPostRepairSchedulerTest::test_dispatch_availability_reports_disabled_wp_cron_without_affecting_storage_api` | WP integration | existing component evidence — B21-08 |
| Per-site list, single retry, due batch, exhaustion and stored-event reconciliation are executable | `DeletedPostRepairServiceTest` service scenarios plus `docs/deleted-post-repair-operations.md` | WP integration and runbook | planned — B21-08 |
| Unresolved work survives Client disposal/runtime reconstruction | `DeletedPostRepairOperationalTest::test_unresolved_work_survives_client_disposal_and_runtime_reconstruction` | WP integration | planned — B21-09 |
| Rollback/consumer uninstall preserves repair table, ownership option and unresolved rows | operations rehearsal plus source audit in `docs/deleted-post-repair-operations.md` | WP integration and source audit | planned — B21-09 |
| Complete real-flow and ledger/claim/concurrency suite passes MySQL 8.0.46 and MariaDB 10.11.16 | exact-candidate CI jobs and commands | pinned vendor CI | planned — B21-10 |
| Dedicated `WP_MULTISITE=1` run executes without relevant skips | exact-candidate multisite job | protected CI | planned — B21-10 |
| Full regression, PHPCS, fixed-floor coverage and reverse/random isolation agree on one SHA | exact-candidate protected matrix | protected CI | planned — B21-10 |
| Independent correctness, security/data-integrity and operational reviews validate this completed manifest | exact-candidate review records | independent review | planned — B21-Q |

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
