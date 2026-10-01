# Project instructions

## Repository scope

- This repository is the `hokoo/wpconnections` PHP library for WordPress post-to-post relations.
- Treat `src/`, `tests/`, `docs/`, `docker/`, `local-dev/`, `.github/workflows/`, the PHPUnit configuration files, `composer.json`, and `makefile` as the primary project surface.
- Do not broadly scan or modify `vendor/`, `wordpress/`, or `wordpress-develop/`. Enter those trees only when a task requires targeted dependency or WordPress-core evidence.
- Preserve PHP 8.1 compatibility, the supported WordPress and Ramsey Collection matrix, WordPress coding standards, public contracts, client isolation, transaction semantics, and multisite behavior.
- Never expose or commit values from `local-dev/.env`, database dumps, credentials, tokens, or local IDE files.

## Delivery workflow

- Use `$delivery-owner` when the user authorizes execution of an epic, backlog, or other multi-task scope. Use `$decompose-work` for task contracts, readiness, dependencies, acceptance criteria, and definitions of done.
- Planning or backlog approval alone does not authorize implementation. Once execution is authorized, continue through consecutive runnable batches without asking for approval at every batch boundary.
- Pull only ready tasks with satisfied dependencies. If one task is blocked, continue independent authorized work and report the blocked task separately.
- At each batch boundary, record the task status, changed artifacts, checks actually run, evidence, newly unblocked work, residual risks, and the next batch. Keep the transition concise and continue.
- Stop only when the authorized scope is complete, no useful authorized work remains, a material decision or failed QA gate requires the user, an unsafe condition arises, or the user asks to pause.
- Do not silently broaden product scope, change a public API or data model, waive acceptance criteria, deploy, publish, push, merge, or perform destructive external actions without the required authority.

## Multi-agent policy

- The root agent is the delivery owner and retains planning, sequencing, integration, decision gates, and final status ownership.
- While `$delivery-owner` is executing an authorized multi-task scope, assign every ready implementation or repair batch to a fresh `worker`. Implementation includes product code, tests, fixtures, workflows, configuration, migrations, and substantial documentation or runbook changes; a tests-only or docs-only batch is still implementation.
- The root must not absorb the next implementation batch merely because it is ready, small relative to the epic, or adjacent to work it just reviewed. Finish the current batch boundary, close completed threads, and start a fresh bounded worker.
- The root may edit only concise delivery bookkeeping or a minimal integration correction whose context cannot be transferred cleanly. If the change introduces behavior, adds or substantially rewrites a test or procedure, spans multiple artifacts, or grows beyond a local correction, stop and delegate it to a worker. Record any root-authored exception in the batch evidence.
- Use one delegation level by default. Subagents must not delegate again unless the root explicitly authorizes it for a bounded reason.
- Use fresh, bounded subagent turns instead of keeping workers alive across many batches. Close completed agent threads so long delivery runs do not exhaust concurrency slots.
- In the shared checkout, allow only one implementation writer at a time. Do not let a later worker mutate files while the current batch is under review or verification.
- Do not start a later writer until the current writer has stopped, its changes have a stable diff or revision boundary, required verification is complete, and the root has accepted or rejected the batch.
- Parallelize read-only exploration, focused review, and log analysis only when they are independent. Do not parallelize Docker, Composer, PHPUnit, coverage, isolation, or database-backed verification in the same checkout.
- Every delegated implementation task must include its task contract, AC, DoD, relevant source artifacts, owned files or modules, dependency boundaries, required checks, stopping conditions, and expected return format.
- The root agent reviews and integrates all returned work. A subagent report is evidence, not automatic acceptance.

## Root context discipline

- Keep the root context focused on requirements, task contracts, sequencing, decisions, acceptance evidence, revision boundaries, risks, and final status. Raw exploration, implementation patches, test logs, stack traces, and polling output belong in the responsible subagent thread.
- The root must not generate or replay a full implementation patch for a delegated batch. It should review the worker's concise report, `git status`, `git diff --stat`, the changed-path list, and only the specific hunks or symbols needed to decide acceptance.
- Prefer narrow root commands with bounded output: targeted `rg`, focused `sed`, `git status --short`, `git diff --stat`, `git diff --check`, and path- or hunk-scoped diffs. Do not dump complete large files, broad diffs, or full command logs into the root thread unless a material decision cannot be made without them.
- A worker return must be a distilled handoff, not a transcript or full diff: changed paths and behavior, AC/DoD mapping, exact checks and results, revision or commit boundary, blockers, and residual risks. Include only decisive failure excerpts and artifact paths.
- A worker may run the smallest targeted checks needed while implementing. After the writer stops, delegate noisy, long-running, Docker-backed, database-backed, repeated-order, coverage, compatibility-matrix, or broad regression verification to `test_monitor`; the root must not execute or poll those commands directly during a multi-batch delivery loop.
- At each accepted batch boundary, write a concise durable checkpoint in the applicable plan or evidence artifact, then use that checkpoint instead of repeatedly reconstructing the batch from full history. Do not duplicate raw command output in delivery documents.
- If an acceptance decision needs deeper evidence, ask the existing subagent for a focused clarification or start a fresh bounded read-only agent. Do not pull the entire subagent transcript into root.

## Agent roles

- `explorer` (`gpt-6-luna`, medium): read-only mapping and evidence gathering. Use it to trace execution paths, locate contracts and tests, and identify affected components before implementation. It must not edit or propose broad speculative rewrites.
- `worker` (`gpt-6-sol`, high): bounded implementation or repair, including tests and substantial delivery documentation. Assign explicit file or module ownership and only the checks proportionate to its change. Use no more than one worker on overlapping code in the shared checkout. It returns a concise evidence handoff rather than raw logs or a full patch.
- `test_monitor` (`gpt-6-luna`, low): execution and observation of an explicitly assigned long-running verification command or serial verification ladder. It may create normal test artifacts but must not edit product source, tests, documentation, or configuration and must not fix failures.
- `epic_qa` (`gpt-6-sol`, high): independent read-only QA at an epic boundary. It must not have implemented the epic. Do not run it after every routine batch; task-level AC and DoD verification belongs inside the delivery loop.
- Keep the pinned role model unless the user explicitly requests a different model. The root may choose a stronger model for orchestration, but subagents must not inherit that cost accidentally.

## Editing and delivery boundaries

- Inspect `git status` and the relevant diff before editing and before any commit. Preserve unrelated user changes and never discard them to simplify the task.
- Keep changes scoped to the active task. Avoid opportunistic refactors unless they are required by an acceptance criterion or to make the requested change safe.
- Follow the task's agreed delivery boundary. Create commits only when the delivery contract calls for them and authorization and repository state permit it. A commit does not authorize push, merge, deployment, or publication.
- Treat implementation, verification, independent QA, commit, merge, and publication as distinct states with distinct evidence.

## Verification

- Choose the smallest set of checks that proves the active AC and DoD, then run the broader gate required by the task or epic. Report exact commands and outcomes; never claim a check that did not run.
- Use `make tests.phpunit` for isolated unit coverage.
- Use `make tests.integration` for storage, hooks, REST, database, entity-validation, transaction, and other WordPress-integrated behavior.
- Add `make tests.multisite` for site context, prefix switching, site-local hooks, cron, storage, or multisite behavior.
- Use `make tests.isolation ISOLATION_SEED=<seed>` after changes to tests, fixtures, hooks, globals, shared registries, or order-sensitive behavior. Preserve and report the seed.
- Use `make lint.phpcs` after PHP source changes.
- Use `make tests.coverage` when the delivery contract requires the PR coverage gate or when source and test changes need coverage evidence. Reserve `make tests.coverage.rc` for explicit release-candidate verification.
- Do not run `make tests.clean` after every batch. Use it for clean-checkout/final verification or when Docker image freshness or rebuild behavior is in scope.
- Follow `docs/ci-runbook.md` for the authoritative command purposes, compatibility matrix, coverage policy, and reproduction procedures.
- Because local commands share the checkout, Compose project, `vendor/`, PHPUnit cache, and coverage artifacts, run heavyweight verification serially. Check `git status` after verification and report unexpected generated changes without deleting them automatically.

## Epic QA gate

- After all implementation tasks in an epic are complete, freeze the reviewed batch and launch a fresh `epic_qa` agent before closing the epic or starting work that depends on its acceptance.
- Give Epic QA the epic scope and exclusions, success criteria, risks, all task AC and DoD, the exact diff or revision boundary, actual verification results, and relevant artifacts.
- Epic QA returns exactly one gate state: `pass`, `pass_with_notes`, or `fail`. Any unmet required criterion, missing required evidence, or incomplete delivery is `fail`.
- On `fail`, perform a bounded repair batch and run a fresh QA pass. Do not allow an unbounded worker-reviewer loop; after repeated failure of the same condition, report the blocker and the decision required.
- A human-approved exception must identify the unmet requirement and accepted risk. Never represent an exception as a successful technical check.

## Long-running commands

- In a `$delivery-owner` multi-batch run, assign long or potentially noisy verification to `test_monitor` by default. This includes full or repeated PHPUnit suites, Docker/Compose checks, compatibility matrices, coverage, isolation, database-backed verification, and any serial ladder whose raw output is not itself a root-level decision artifact.
- Give `test_monitor` the frozen revision or diff boundary, exact command or ordered ladder, working directory, expected artifacts, stopping conditions, and the concise evidence required by the parent.
- Start each assigned command once and observe that same process. Never create polling by repeatedly launching the command.
- Prefer event-driven or reasonably spaced status checks. Do not treat quiet output as failure and do not restart, kill, or clean up a process without evidence and authority.
- If an approval or automatic review times out, first establish whether the command started or is still running. Report the condition to the root; do not blindly launch a duplicate command.
- Stop monitoring when the command exits, approval is required, cancellation is requested, or a credible unsafe or stuck condition is found. Return the exit status, duration when available, concise failure evidence, artifact paths, and before/after working-tree changes.

## Documentation and handoff

- Update the relevant file in `docs/` and README guidance when public behavior, upgrade requirements, compatibility, operational procedures, or documented contracts change.
- At final handoff, state what changed, which checks actually ran and their results, the delivery state, remaining work and exclusions, residual risks, and any accepted exceptions.
