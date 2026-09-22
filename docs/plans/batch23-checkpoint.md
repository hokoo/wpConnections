# Batch 23 — REST-05 metadata

Status: review; implementation, focused repairs and local gates accepted by root;
protected PR and merge verification remain pending.

Base: accepted REST-04 merge `5f4c544` and acceptance record `4d2e9e7`.
Contract: REST-05 in [the hardening plan](02-library-hardening.md).

- Saved red regression: 28 tests / 95 assertions / 6 failures before production
  edits (`/tmp/wpconnections-rest05-red-current.log`). Metadata mutations trusted
  body selectors over the URL; missing DELETE targets returned success; DELETE
  metadata arguments were undeclared.
- The candidate uses existing authoritative route selectors, checks DELETE target
  ownership/existence and declares metadata input without narrowing legacy forms.
  Both runtime lanes confirmed row-list/map/omitted/empty/top-level-null DELETE
  behavior (5 / 17 each). Initial focused candidate: 29 / 120 on both lanes;
  focused PHPCS 3 / 3, syntax and diff checks passed.
- Root review requested direct metadata read-failure coverage, query-parameter
  precedence cases, explicit colliding-client-ID proof and accurate distinction
  between injected code-304 mapping evidence and actual hydration reachability.
  A fresh worker completed this test/documentation repair. The read-only audit proved
  code 304 reachable through a supported custom adapter returning a zero-ID
  connection; the repair includes that real dispatch case. No contract exception
  or root-authored implementation exception is needed.
- The intermediate current-lane matrix passed 32 / 145, with real read-failure
  and custom-adapter evidence; focused PHPCS passed 4 / 4. A further bounded
  correction retained body and query conflicts as eight separate method/source
  cases. Final focused current matrix: 36 / 176; test PHPCS and diff checks pass.
  Logs: `/tmp/b23-rest-meta-focused-current.log` and `/tmp/b23-rest-meta-phpcs.log`.
  Root accepted the final diff; the expanded floor suite subsequently passed
  within the full coverage run below (not a separate focused floor invocation).
- Candidate `dc66d13`: unit passed 141 / 518. Full integration stopped at
  592 / 5056 with eight lifecycle inventory failures and ten expected skips:
  those tests still expected DELETE arguments without `meta`. A fresh worker
  changed only the shared exact expected argument list. Root accepted that
  one-line repair; focused lifecycle passed 21 / 814 with one multisite-only
  skip. The multisite case remains for the broad gate.
- Focused PHPCS on the existing lifecycle module exits 2: both the repaired
  file and frozen HEAD report the same pre-existing 1801 errors across 1063
  lines. No new violation or ruleset change; the standard project lint gate
  remains required. Evidence: `/tmp/wpconnections-b23-local/`.
- Frozen repair `5d09cf653dd7cb92f2b78879908962a43638a0e9` passed the remaining
  serial local gates: `make tests.integration` 592 / 5195 (10 expected skips),
  `make tests.multisite` 592 / 5293, `make tests.isolation ISOLATION_SEED=20260922`
  all four phases, `make tests.coverage` 733 / 5711 (10 expected skips), and
  `make lint.phpcs` 100 / 100 files. Coverage: 3851 / 4184 statements, 92.04%;
  the PR gate passed. `make tests.coverage.rc` was not run.
- Isolation reverse/random repeat phases each passed unit 282 / 1036 and
  integration 1184 / 10390 (20 expected skips). The unfiltered coverage command
  discovers the complete WP test directory through `phpunit-coverage.xml`;
  per-case JUnit output was not generated for that run.
- Current gates used PHP 8.1.34 / WP 7.1-src / Ramsey 1.3.0; coverage used
  WP 6.7.7. Final logs: `/tmp/wpconnections-b23-local-final/`. Verification
  left the working tree unchanged. Unit evidence above remains valid because
  the repair changes only an integration-test expectation. Protected PR and
  merge gates remain pending; independent Epic QA follows REST-06 at E4.
- Next task after acceptance: REST-06 selectors. No release/tag or waiver.
