# Batch 29 — REL-02 extension compatibility

Status: completed; technically accepted and delivered as scoped verified local commits. User authorized this first of three batches on 2026-10-02; no push, merge, publication or release.
Batch owner AI model: `gpt-6.1-sol`; reasoning effort: `high`.
Rationale: cross-cutting extension contracts and custom-adapter conformance require careful review; no new public API is authorized. E6 recommended root model remains separate (`gpt-6.1-sol`/medium). Runtime did not expose effective owner settings.

Contract: [REL-02](02-library-hardening.md#rel-02-проверить-hooks-factories-и-extension-compatibility), all Scope, exclusions, DoR, AC and DoD. Owner confirmed completed REL-00, SPI-01, REST-HOOK-01 and LOG-HOOK-01 plus approved REL-02 gates. Stable E2/E3/E4 contracts are available; DB-02R is the explicit downstream race residual consuming this conformance evidence.

## Changed artifacts and acceptance

- `src/Factory.php`: unchanged class-string filters and arguments; preconstruction compatibility/concreteness validation; hook-attributed replacement selection/construction `ClientRegisterFail` code 4 with cause. Established built-in WPStorage registration errors retain their reasons.
- `tests/iTRON/wpConnections/WP/ExtensionCompatibilityTest.php`: all three replacements, invalid/abstract/incompatible/throwing classes, capability/init/create payloads, eight-operation memory adapter, aggregate delete-first/update-first/scalar-no-op schedules; repeated TestCase instance state resets.
- Existing `ClientIsolationTest.php`, `StorageFailureTest.php`, `WPStorageFindConnectionsTest.php`: exact installation, metadata, query-data and logger payload evidence.
- `docs/extension-compatibility.md`, `docs/storage-spi-contract.md`, two README product-guidance hunks: public/concrete/internal classification, factory errors, lock timing, custom logging origin and migration guidance.
- Both README status blocks and the REL-02 task status were updated as delivery bookkeeping; pre-existing ORCH changes remain separate.

AC/DoD mapping: valid replacements and callback arguments are proven by `test_factory_filters_receive_defaults_and_exact_client_once`; invalid replacements and stable errors by `test_invalid_factory_replacements_fail_with_attributable_client_register_error` plus unchanged ClientIsolation reasons. Create/init payload/order/once evidence is in `test_client_capability_and_create_lifecycle_payloads_are_stable`; default delete variants use existing `AtomicMutationTest::test_delete_hooks_preserve_arguments_and_run_after_owning_commit`, rollback and FIFO tests. Exact find/meta/install tuples are covered by the three existing-suite additions; `DebugLogObserverTest` and `ClientRestApiLifecycleTest` retain origin/priority and managed-delegate evidence. Documentation identifies internal insertion/recovery probes and concrete SQL telemetry. Owner reviewed the scoped diff and all required evidence; no criteria waived.

The capable memory adapter revalidates exact parent existence/relation inside its atomic update before metadata writes, models serialized update-then-delete, and preserves scalar no-op metadata replacement. It introduces no public capability. This is deterministic adapter conformance, not real two-session SQL proof or a claim that WPStorage's race is fixed; DB-02R owns that repair and MySQL/MariaDB evidence.

## Verification actually run

| Exact command | Result |
| --- | --- |
| `make tests.phpunit` | Pass, 143 tests/533 assertions, 1.60s. |
| `make tests.integration` | Pass, 688 tests/5831 assertions, 12 single-site skips, 101.22s. |
| `make tests.multisite` | Pass, 688 tests/5931 assertions, 108.61s. |
| `make tests.isolation ISOLATION_SEED=20261002` | Final pass, exit 0, 401.30s; unit reverse/random repeats each 286/1066; integration reverse/random repeats each 1376/11662, 24 skips. |
| `make tests.isolation` | Pass, generated seed `2014195589`, exit 0, 405.30s; same repeat counts. |
| `make tests.coverage` | Pass, exit 0, 111.32s; 831 tests/6362 assertions, 12 skips; 4353/4711 statements (92.40%), PR profile passes. >=70% RC advisory ready; no RC-profile command run. |
| `make lint.phpcs` | Pass, exit 0, 108/108 source files, 1.90s; existing functionWhitelist deprecation notice. |

Standalone unit/integration/multisite passes preceded only the final test-instance setup reset (ExtensionCompatibilityTest hash `b833d7c5aeb42216b8a13ce4a056777c1c4b8cc9b63381d756a70a4e2f2d4114`). Root accepted this proportionate boundary: final isolation and coverage retest full unit/integration; no site/product behavior changed. Five changed PHP files passed `php -l`; final `git diff --check` and scoped staged checks passed. No clean rebuild, full compatibility matrix, remote CI, or RC gate claimed.

Final logs: `/tmp/wpconnections-rel02-isolation-repaired.log`, `/tmp/wpconnections-rel02-fresh-isolation.log`, `/tmp/wpconnections-rel02-coverage.log`, `/tmp/wpconnections-rel02-phpcs.log`; earlier passing standalone logs use `/tmp/wpconnections-rel02-final-*.log`. Coverage reports are under `build/coverage/`. Monitor confirmed final hashes/status unchanged, no unexpected generated repository changes, and every process exited.

## Findings corrected and process evidence

Baseline probe: `git show HEAD:src/Factory.php > /tmp/wpconnections-rel02-factory-baseline.php` at original `0a99625e`; `php /tmp/wpconnections-rel02-factory-probe.php /tmp/wpconnections-rel02-factory-baseline.php` observed array replacement escaping as TypeError and constructor TypeError losing its cause. Same probe against patched Factory observed ClientRegisterFail and retained cause. Diagnostic probes exit 0 while printing outcomes; they are not suite passes.

Initial integration failed 7 tests (686/5780, 12 skips): six built-in naming/prefix reasons were masked and one new call-order assertion omitted two domain reads. Fixed within the same worker assignment; log `/tmp/wpconnections-rel02-integration.log`. Subsequent isolation failed one lifecycle test (1376/11658, 24 skips): repeated TestCase retained its counter, creating a different client name. State reset fixed it; original and fresh seeds now pass. Failed log `/tmp/wpconnections-rel02-final-isolation.log`. No exception/waiver added.

Actual states were checked before spawns: initially only root and owner running; worker completed and commands stopped before fresh monitor. Fresh pinned worker `rel02_worker` and monitor `rel02_monitor` were used; same unfinished assignments handled scoped review repairs and resumed verification. No capacity error/fallback, new bounded assignment, or reuse allowance consumed; repository three-thread configuration unchanged. Initial Docker socket sandbox denial happened before tests; approved Docker context succeeded. Narrow cached README staging needed approved escalation for read-only `.git/index.lock`; no approval rejection/blocker.

## Delivery boundary, risks and next work

Implementation commit: `60081f0` (`Protect factory replacements and extension hook contracts`), eight owned paths; only README extension guidance was staged. This checkpoint and completed REL-02 plan status form separate concise delivery bookkeeping. Pre-existing `.codex/`, `AGENTS.md`, ORCH README guidance/status hunks and `orchestration-checkpoint.md` are preserved outside these commits. Post-commit source/test/guide hashes still match verified content.

Final hashes: Factory `9c86a2a44d30d9937a2af72561a01dee3e253c54cf83d0e820396cc9d839c7e7`; ExtensionCompatibilityTest `7f895da4730ccc942085d72026a30385d096559e6a32275c0d2662826bebe653`; ClientIsolationTest `e36520054fc313455240779512c5ce1228d2417e960defed25a7387b00b19d14`; StorageFailureTest `c91ef32e1f147657584b683939d9dc7f9c0b32557d386eb81e1c65b0861509de`; WPStorageFindConnectionsTest `30b7026a56ff7362b95eed685ba6635c35af32dfc798b419b70ff4611f82549c`; guide `06005d4cab341eba65cb17806e7dfd606fe2a9882adaa7f2785972d689821d65`.

No remaining Batch 29 blocker. Default adapter parent-row race remains DB-02R; private adapters/consumers remain unknown. DB-02R DoR now has its capable-adapter fixture; HOOK-04 DoR now has hook/factory evidence and its other dependencies were already complete. Next proposed authorized batch is Batch 30 / DB-02R (`gpt-6.1-sol`/high), then Batch 31 / HOOK-04 (`gpt-6.1-sol`/medium), each through a fresh root-selected owner. E7 QA follows HOOK-04; E6 remains open pending REL-03. This owner stops at Batch 29 handoff and does not start a successor.
