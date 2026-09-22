# Deleted-post recovery verification manifest

Status: active DB-04-Q manifest. B21-01 is complete; B21-02 is the next
selected task. The final exact qualification candidate and protected CI
results remain owned by B21-10/B21-Q.

## Evidence identity

- Batch 21 base: `f7e94af0e9b039da90260162db44ea30635fc3ec`
  (Batch 20 documentation closeout merge).
- Real-flow fixture commit:
  `0a15b4d224358ac84dbb6aa117ba60b368001097`.
- Fixture: `DeletedPostRecoveryRealFlowTest`.
- Production delta in B21-01: none. Both paths were committed as
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

## Traceability matrix

### Real deletion and data effect

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Real permanent-delete entry, physical row cleanup, attempt/commit hook timing and no repair after first-attempt success | `DeletedPostRecoveryRealFlowTest::test_permanent_delete_fixture_observes_data_hooks_and_repair_state` | single-site WP; true multisite WP | verified in B21-01 (`0a15b4d`) |
| Trash-only flow does not enter permanent-delete cleanup | `DeletedPostRecoveryRealFlowTest::test_trash_only_fixture_does_not_run_permanent_delete_cascade` | single-site WP; true multisite WP | verified in B21-01 (`0a15b4d`) |
| Incoming/to-end cleanup and semantic disable/enable | `ClientIsolationTest::test_semantic_post_deletion_lifecycle_controls_real_cleanup_once` | single-site WP integration | existing |
| Outgoing/from-end legacy row and metadata cleanup | `EntityValidationTest::test_deleted_post_cascade_cleans_legacy_row_without_endpoint_resolution` | single-site WP integration | existing |
| Incoming, outgoing and self connections in one physical-row matrix | `DeletedPostRecoveryRealFlowTest` data-matrix methods | single-site WP integration | planned — B21-02 |
| Multiple relations preserve unrelated relation rows | `DeletedPostRecoveryRealFlowTest` relation-isolation method | single-site WP integration | planned — B21-02 |
| Multiple live Clients remove only their own rows and emit their own hooks once | `DeletedPostRecoveryRealFlowTest` Client-isolation method | single-site WP integration | planned — B21-02 |
| Permanent attachment deletion follows the same cascade | `DeletedPostRecoveryRealFlowTest` attachment method | single-site WP integration | planned — B21-02 |

### Failure and commit windows

| Required observation | Exact evidence or target | Lane | State / owner |
| --- | --- | --- | --- |
| Connection-delete DML failure rolls back connection/meta and leaves `retry_wait` | `AtomicMutationTest::test_deleted_post_callback_uses_atomic_delete_boundary` | single-site WP integration | existing baseline; real-flow matrix extension — B21-03 |
| Selector read, metadata delete, connection delete and transaction-start failures through real deletion | `DeletedPostRecoveryRealFlowTest` semantic fault-matrix methods | single-site WP integration | planned — B21-03 |
| Commit, rollback and rollback-confirmation uncertainty through real deletion | `DeletedPostRecoveryRealFlowTest` semantic transaction-fault methods | single-site WP integration | planned — B21-03 |
| Storage attempt-hook `Throwable`, ledger-arm failure and scheduler failure have distinct data/ledger outcomes | `DeletedPostRecoveryRealFlowTest` readiness/wakeup fault methods | single-site WP integration | planned — B21-03 |
| Post-commit success-hook failure is retryable and zero-result retry resolves uncertainty | `DeletedPostRepairExecutorTest::test_post_commit_hook_failure_retries_committed_cleanup_and_zero_resolves_uncertainty` | single-site WP integration | existing component evidence; real-hook bridge — B21-04 |
| Death after durable arm before claim leaves immediately claimable `armed` state | `DeletedPostRecoveryRealFlowTest` simulated arm-boundary method | single-site WP integration | planned — B21-04 |
| Death during cleanup leaves `running` until lease expiry | `DeletedPostRecoveryRealFlowTest` simulated cleanup-boundary method | single-site WP integration | planned — B21-04 |
| Death after commit before resolve leaves `running`; no-match retry resolves it | `DeletedPostRecoveryRealFlowTest` simulated commit-boundary method | single-site WP integration | planned — B21-04 |
| Duplicate `deleted_post` delivery deduplicates one logical identity | `DeletedPostRecoveryRealFlowTest` duplicate-delivery method | single-site WP integration | planned — B21-04/B21-05 |

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
