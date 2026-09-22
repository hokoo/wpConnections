# Batch 21: safe pause checkpoint — 2026-09-22

Execution is paused at the repository owner's request. Do not start another
batch until the owner resumes work. B21-01—B21-09 are completed; B21-10 remains
`in_progress` with partial verification, and B21-Q remains `waiting_dependency`.
This is an execution pause, not a technical exception or epic acceptance.

## Preserved implementation

Branch: `batch21-deleted-post-qualification`.

- `65c53e8`: B21-08 degraded-cron/operator tests and canonical operations runbook.
- `85dfa8d`: B21-09 lifecycle preservation rehearsal and rollback/uninstall guidance.
- `062b7fefb3e4cec6261b3a9b101958f47219f2b1`: frozen local qualification
  candidate, including task evidence and explicit R1–R6 mapping.

Batch 21's base is `f7e94af0e9b039da90260162db44ea30635fc3ec`. The complete
batch changes tests and documentation only; no production source, Composer
contract, workflow, public API or schema changed. This checkpoint adds only
documentation after the tested candidate.

The user's untracked `AGENTS.md` and `.codex/` were preserved and excluded from
commits. There were no unexpected generated tracked changes after verification.
No verification process or disposable external database remains active from
this work. The active isolation command was allowed to finish before pausing.

## Completed B21-10 checks on `062b7fe`

Runtime: PHP 8.1.34, WordPress 7.1-src (requested 7.1.0), Ramsey Collection
1.3.0, embedded MariaDB 11.8.6. All four Make commands exited 0.

| Command / phase | Result |
| --- | --- |
| `make tests.phpunit` | 141 tests / 518 assertions |
| `make tests.integration` | 516 tests / 4733 assertions / 10 multisite-only skips |
| `make tests.multisite` | 516 tests / 4831 assertions / no skips |
| `make tests.isolation ISOLATION_SEED=20260922`: unit reverse and random, each repeated twice | Each phase: 282 tests / 1036 assertions |
| Same isolation command: integration reverse and random, each repeated twice | Each phase: 1032 tests / 9466 assertions / 20 skips |

Isolation command duration: 261.56 seconds. The first unprivileged unit launch
could not reach Docker; the escalated command supplied the successful unit
result, not a retry of a failing test.

Raw logs remain at `/tmp/wpconnections-b21-uQxgT6/`:
`revision.txt`, `status-before.txt`, `status-after.txt`,
`01-tests.phpunit.log`, `01-tests.phpunit-escalated.log`,
`02-tests.integration.log`, `03-tests.multisite.log`, and
`04-tests.isolation.log`. Results are also preserved in the committed
[verification manifest](../deleted-post-repair-verification-manifest.md).

Observed caveat: integration output contains repeated WordPress
`wp-includes/fonts.php:218` warnings about reading `post_type` on `null`.
The commands passed, but the warning attribution was not investigated before
the pause and must be assessed during QA; no warning waiver was accepted.

## Resume point

Read this checkpoint, the [Batch 21 task contracts](02-library-hardening.md)
and the [verification manifest](../deleted-post-repair-verification-manifest.md).
Inspect working-tree changes first. Preserve the completed checks above and
their exact revision; do not attribute them to a later source/test candidate.
For strict same-SHA qualification, run the remaining checks on `062b7fe` in a
clean checkout while retaining this branch/checkpoint. If repairs change the
candidate, record the new revision and rerun affected required gates.

The next serial checks, not started before pause, are:

1. `make lint.phpcs`.
2. `make tests.coverage` (fixed PHP 8.1.34 / WordPress 6.7.7 image).
3. `docker run --rm -v "$PWD:/srv/web" wpconnections-coverage:php8.1.34-wp6.7.7 test:multisite`.
4. Full integration with external digest-pinned MySQL 8.0.46, then MariaDB
   10.11.16, using the same fixed-floor image and isolated disposable databases.
   Follow `.github/workflows/db-compatibility.yml` exactly, verify logged
   runtime versions, and preserve any unexpected Composer lock changes.

The MySQL/MariaDB networks and containers for these later phases were not
created. Do not run heavyweight commands concurrently in the shared checkout.
Record remaining results and `HOOK-CASCADE-01` named-test evidence in the
manifest before requesting the final gate.

## Independent review and external delivery remain open

The independent QA agent was stopped at the user's pause request. Its required
gate output was `fail` because review, verification and delivery evidence were
incomplete; it did not establish an implementation defect in the reviewed
portion and did not grant acceptance. It read the contracts/manifest/runbook,
the boundary/stat list and the complete `DeletedPostRecoveryRealFlowTest`.
The remaining changed test diffs (`AtomicMutationTest`,
`DeletedPostRepairExecutorTest`, `DeletedPostRepairHookMigrationTest`,
`DeletedPostRepairOperationalTest`) and full documentation consistency still
need a fresh independent QA pass with the completed actual verification logs.

No push, PR, merge, release or branch-protection mutation was performed.
Read-only GitHub preflight found no PR for this branch and 19 strict required
contexts; the workflow's dedicated multisite job is absent from protection,
with no additional branch rules. The CI runbook now lists all 20 existing jobs.
External delivery and protection reconciliation require the appropriate owner
authority. B21-10/B21-Q cannot be closed without the remaining technical gate,
protected-head, exact-merge and post-merge evidence. HOOK-04, REL-02 and REL-03
remain release dependencies; no tag/release is part of this batch.
