# Batch 30 — DB-02R concurrent parent-row integrity

Status: completed; second of three user-authorized batches on 2026-10-02.
Batch owner AI model: `gpt-6.1-sol`; reasoning effort: `high`.
Rationale: atomic boundary and deterministic two-session update/delete serialization across MySQL and MariaDB require careful technical ownership.

Contract: all Goal, Scope, exclusions, DoR, DoD, AC, dependencies and risks of DB-02R in [the main plan](02-library-hardening.md#db-02r-закрыть-concurrent-deleteupdate-parent-row-race). DB-02/DB-05 completed; accepted Batch 29 / REL-02 commits 60081f0 and f181dfb provide capable-adapter conformance, including docs/extension-compatibility.md and ExtensionCompatibilityTest. Required outcome: exact parent existence/relation check and row lock within the same atomic aggregate update; no orphan metadata for delete-first/update-first schedules, valid scalar no-op with metadata replacement.

Delivery: verified scoped local commit(s); no push, merge, release or publication. Preserve pre-existing ORCH and README status edits. No foreign key, schema migration, public capability/API change or global isolation change is authorized. If approved existing capability cannot support this, stop at the explicit decision gate. Start confirmed fix with actual red regression evidence.

Verification: serial required integration plus deterministic real two-session regression on both blocking MySQL/MariaDB lanes, isolation seed 20261002 after fixture/test changes, PHP source PHPCS and coverage gate; add multisite when site/prefix behavior changes. Follow docs/ci-runbook.md, use fresh worker and serial test_monitor through owner, stop writers before verification. Required AC/DoD cannot be waived. Record exact commands/results, log paths, accepted stable boundary and residual risks.

Next proposed authorized batch: Batch 31 / HOOK-04, owner gpt-6.1-sol/medium (bounded consumer audit and migration note), followed by independent E7 QA. E6 remains open; REL-03 not selected.

## Start and readiness

DB-02, DB-05 and REL-02 dependencies confirmed from accepted commits and task artifacts. Owner selection above is explicit; effective runtime model/effort are not separately exposed. Inspected git status: pre-existing ORCH agent/AGENTS changes, both README status edits and orchestration checkpoint preserved. Actual agent states before spawn: root and Batch 30 owner running; Batch 29 owner and monitor completed with all commands stopped. Runtime allows four concurrent slots; repository config retains three-thread default. Fresh pinned worker `/root/batch30_owner/db02r_worker` successfully started; no fallback used. Worker owns bounded source/tests/contract docs, owner owns status/checkpoint; first stage is a frozen red concurrency reproducer.

## Verification capacity audit

Fresh `test_monitor` spawn for the frozen red stage failed: `agent thread limit reached`. Actual post-error states: root and owner running, worker running, Batch 29 owner completed; prior Batch 29 monitor absent. Root's direct availability probe to `/root/batch29_owner/rel02_monitor` also failed with `agent thread limit reached`; no confirmed callable eligible monitor. Available lifecycle tools expose no close/delete action, and interrupt/final is not presumed to reclaim capacity. Worker finished its red-stage turn normally with commands stopped; actual state changed running → completed. One fresh monitor retry is therefore permitted after this demonstrated state change; no specialist fallback assignment used.

Frozen red artifact: `tests/iTRON/wpConnections/WP/ConnectionUpdateConcurrencyTest.php`, SHA256 `462afdb7f9317a58e43797f3e5868490bfe85e42f7cad92ae67c4b6ad493a856`. Worker reports `php -l` and scoped `git diff --check` pass; database red command has not run. Product source remains unchanged at `f181dfb`. Fixture covers real independent-session delete-first and update-first schedules using committed rows, process-list observation and bounded asynchronous polling. No implementation acceptance or commit.

## Actual red evidence and implementation stage

The one fresh monitor retry succeeded after the confirmed worker state change; no reuse fallback. Monitor `/root/batch30_owner/db02r_monitor_retry` owns the same bounded red/final serial assignment. Initial focused Docker attempt exited 1 before tests (socket permission); authorized escalation ran after confirming no process remained. First fixture run reached PHPUnit but setup errored (2 tests / 0 assertions, missing endpoint; tee shell exit 0 was not counted as test success). Worker corrected empty post creation and teardown, without source changes.

Actual defect red: `cd local-dev && docker compose -p wpconnections run --rm phpunit test:integration --filter ConnectionUpdateConcurrencyTest`, escalated with pipefail and captured in `/tmp/wpconnections-db02r-red-corrected.log`, exited 1 (~10s), 2 tests / 18 assertions / 1 failure: deleted target was not rejected. Update-first schedule passed. Frozen fixture SHA256 `98665e8cdeaae8659a3d6c266a7124466907c769d5a2ce3364089f879cb62ca9`; source remained `f181dfb`. Monitor confirmed DB shutdown and all processes exited, stable tree/hash. Worker resumed same unfinished bounded implementation assignment only after this genuine red evidence; full gate remains pending.

## Frozen repair and review

Owner reviewed scoped source/test/doc diff: `WPStorage::updateConnection()` checks the exact parent ID with current `SELECT relation ... FOR UPDATE` inside an active atomic scope, then throws approved missing/mismatched ownership errors before scalar or metadata writes. Existing scalar-zero/no-op result survives; unsupported legacy direct storage calls retain their boundary. No API, schema, transaction-isolation or site/prefix change. Regression now asserts no orphan before expected exception and adds authoritative relation ownership race; existing aggregate metadata replacement adds unchanged-scalar assertions. Adapter contract docs distinguish portable invariant from SQL locking.

Worker completed its turn normally and stopped all processes/writes. A monitor continuation initially failed with `agent thread limit reached` while worker was still active; actual running → completed transition was confirmed before successful continuation of the same unfinished monitor assignment. No new specialist assignment or fallback reuse allowance consumed. Required serial ladder now assigned: focused green; integration; full pinned external MySQL/MariaDB lanes; isolation seed 20261002; PHPCS; coverage. Multisite not added: source site/prefix behavior unchanged. Technical acceptance and local commits remain pending.

Frozen owned SHA256 boundary: WPStorage `20b034505f7c50f4dac079e35b89ed5ee510710b940ebeee3eff79057afacf09`; ConnectionUpdateTest `ff25fc5df07b6aa2439c21989a2793e95ae1d61a31fc1621984da28a83cba1c3`; concurrency fixture `8b759f74f598fcd512c534f04e43de049c776fa835c0c6a630b4f6b87ae445d7`; extension guide `75079abb536067b00ce7a3bcccde920451cd39bb534afd37aea4041c8c4f6732`; storage SPI doc `8460ca1914badc1d528028438dc2c78e8a79d25f5c4fa0d56506a799a6c9588e`.

## Verification progress

- Focused green `cd local-dev && docker compose -p wpconnections run --rm phpunit test:integration --filter 'ConnectionUpdate(Concurrency)?Test'`: pass, 27 tests / 159 assertions; `/tmp/wpconnections-db02r-focused-green.log`.
- `make tests.integration`: pass, 691 tests / 5863 assertions / 12 expected unrelated skips; all three new concurrency tests included without skip; `/tmp/wpconnections-db02r-integration.log`.
- Fixed-floor database compatibility image build passed. Full pinned MySQL lane passed: authenticated server 8.0.46, PHP 8.1.34 / WP 6.7.7 / Ramsey 1.3.0, 691 tests / 5863 assertions / 12 unrelated skips, exit 0, test time 3:46.970; `/tmp/wpconnections-db02r-mysql.log`. Disposable resources removed. Full pinned MariaDB lane passed: authenticated server `10.11.16-MariaDB-ubu2204`, same fixed-floor runtime, 691 tests / 5863 assertions / 12 unrelated skips, exit 0, test time 1:15.739; `/tmp/wpconnections-db02r-mariadb.log`. Both external full suites include all three concurrency tests without relevant skips; both disposable container/network pairs removed. Isolation seed 20261002 passed: unit reverse/random ×2 each 286 tests / 1066 assertions; integration reverse/random ×2 each 1382 executions / 11726 assertions / 24 unrelated skips (3:23.021 and 3:17.294); `/tmp/wpconnections-db02r-isolation.log`. PHPCS passed 108/108 source files; coverage PR gate passed 4366/4724 statements (92.42%), 834 tests / 6394 assertions / 12 unrelated skips; RC advisory ready (separate RC command not run).

## Technical acceptance and local delivery

Owner accepts DB-02R: delete-first produces approved `ConnectionNotFound` before metadata writes and leaves no orphan; update-first blocks the independent delete until aggregate commit, then the cascade leaves neither parent nor metadata; existing scalar no-op still replaces/clears metadata. Exact ownership revalidation also rejects a concurrent relation reassignment without overwriting its new owner. Source review and deterministic fixture evidence cover AC/DoD; all required gates pass, no exception or waived criterion.

Implementation commit: `26e87c7` (five owned paths: `src/WPStorage.php`, `tests/iTRON/wpConnections/WP/ConnectionUpdateTest.php`, new `ConnectionUpdateConcurrencyTest.php`, `docs/extension-compatibility.md`, `docs/storage-spi-contract.md`). Initial scoped staging failed before mutation because `.git/index.lock` was read-only; authorized escalated local commit succeeded after status/diff/index checks. Both README status blocks synchronized locally and ORCH changes preserved; plan/checkpoint form separate concise delivery bookkeeping commit. No push, merge, release, publication or successor started.

| Actual check | Result / decisive evidence | Artifact |
| --- | --- | --- |
| `php -l` for all three changed PHP files; `git diff --check` | pass; worker syntax evidence and owner final whitespace/hash review | frozen hashes above |
| Focused green integration filter `ConnectionUpdate(Concurrency)?Test` | pass 27 / 159 | `/tmp/wpconnections-db02r-focused-green.log` |
| `make tests.integration` | pass 691 / 5863 / 12 unrelated skips | `/tmp/wpconnections-db02r-integration.log` |
| Fixed-floor `docker build --build-arg PHP_VERSION=8.1.34 --build-arg WP_VERSION=6.7.7 -t wpconnections-ci:database-compatibility -f Dockerfile.phpunit .` | pass | `/tmp/wpconnections-db02r-mysql-build.log` |
| Pinned MySQL 8.0.46 external full integration, workflow reproduction | pass 691 / 5863 / 12 unrelated skips; actual version 8.0.46 | `/tmp/wpconnections-db02r-mysql.log` |
| Pinned MariaDB 10.11.16 external full integration, workflow reproduction | pass 691 / 5863 / 12 unrelated skips; actual version 10.11.16-MariaDB-ubu2204 | `/tmp/wpconnections-db02r-mariadb.log` |
| `make tests.isolation ISOLATION_SEED=20261002` | pass; unit reverse/random ×2 each 286 / 1066; integration reverse/random ×2 each 1382 / 11726 / 24 unrelated skips | `/tmp/wpconnections-db02r-isolation.log` |
| `make lint.phpcs` | pass 108 / 108; existing functionWhitelist deprecation only | `/tmp/wpconnections-db02r-phpcs.log` |
| `make tests.coverage` | pass 834 / 6394 / 12 unrelated skips; 4366 / 4724 statements, PR PASS, RC advisory ready | `/tmp/wpconnections-db02r-coverage.log`, ignored `build/coverage/{clover.xml,coverage-summary.json,coverage-summary.md,coverage.txt}` |

Monitor confirmed all five frozen hashes match, no unexpected generated tracked/untracked changes, all processes exited and external disposable containers/networks removed. No multisite gate added because site/prefix behavior did not change; no separate `tests.phpunit` or RC command claimed. Previous Batch 29 evidence was not counted as this repair's verification.

Newly unblocked: DB-02R's verified-fix prerequisite for REL-03 is satisfied locally; REL-03 still waits on HOOK-04/candidate selection and release authorization. Next proposed authorized batch is Batch 31 / HOOK-04 under root selection (`gpt-6.1-sol` / `medium`, rationale in root contract); this owner does not start it. E6 stays open. Residual boundaries: direct legacy `getStorage()` writes remain unsupported; capable custom adapters must supply equivalent serialization themselves; no schema/FK/API/global isolation change. Existing suite skips/core warning/style-deprecation noise are unrelated and do not skip the three new concurrency cases. No batch blocker remains.

Exact external command appendix: `/tmp/wpconnections-db02r-commands.txt` (monitor's recorded actual build/start/run/cleanup commands, no rerun). Names were `wpconnections-db02r-mysql-net` / `wpconnections-db02r-mysql-db` and `wpconnections-db02r-mariadb-net` / `wpconnections-db02r-mariadb-db`; digest pins match `.github/workflows/db-compatibility.yml` exactly. Actual full test invocations from repository root, each with pipefail and tee to its log:

```sh
docker run --rm --network wpconnections-db02r-mysql-net -v "$PWD:/srv/web" -e DB_START_MODE=external -e DB_HOST=wpconnections-db02r-mysql-db -e DB_NAME=wordpress_test -e DB_USER=wordpress -e DB_PASSWORD=wordpress -e EXPECTED_DB_VERSION_PREFIX=8.0.46 -e RAMSEY_VERSION=1.3.0 wpconnections-ci:database-compatibility test:integration
docker run --rm --network wpconnections-db02r-mariadb-net -v "$PWD:/srv/web" -e DB_START_MODE=external -e DB_HOST=wpconnections-db02r-mariadb-db -e DB_NAME=wordpress_test -e DB_USER=wordpress -e DB_PASSWORD=wordpress -e EXPECTED_DB_VERSION_PREFIX=10.11.16-MariaDB -e RAMSEY_VERSION=1.3.0 wpconnections-ci:database-compatibility test:integration
```

Final owner status/diff/whitespace review passed after implementation commit; remaining working-tree changes are known ORCH files, local README status blocks and this delivery bookkeeping. No product/test/doc mutation after the verified frozen boundary.

## Native lifecycle recovery

New AGENTS lifecycle policy applied after accepted handoff and recorded owner/descendant command-stop evidence. CLI/daemon 0.160.0; serving socket `/home/itron/.codex/app-server-control/app-server-control.sock`. Root archived the completed owner once with `codex archive --remote unix:///home/itron/.codex/app-server-control/app-server-control.sock 01a0fd74-f2b9-7320-8e8d-5339050a2781`; exit 0. Owner disappeared from current agent tree; read-only exact-path runtime metadata confirms owner and both children archived, retained history. UUIDs obtained from `state_5.sqlite` exact agent_path plus parent source, without history scanning. Optional proxy introspection timed out; archive metadata/tree supplied decisive evidence.

- `/root/batch30_owner`: native UUID `01a0fd74-f2b9-7320-8e8d-5339050a2781`, archived metadata = true.
- `/root/batch30_owner/db02r_worker`: native UUID `01a0fd75-c95d-7223-a64a-9b1aac584261`, archived metadata = true.
- `/root/batch30_owner/db02r_monitor_retry`: native UUID `01a0fd7b-ed93-7fb1-9601-d4922bc2639f`, archived metadata = true.
