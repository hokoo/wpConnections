# Batch 23 — REST-05 metadata

Status: review; implementation and focused evidence repairs accepted by root;
broad verification and protected delivery remain pending.

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
  Root accepted the final diff; the expanded floor matrix awaits full coverage.
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
- Full integration after repair, multisite, isolation seed `20260922`, coverage
  and project PHPCS remain pending, followed by protected PR/merge verification.
  Unit evidence above remains valid because the repair changes only an
  integration-test expectation. Independent Epic QA follows REST-06 at E4.
- Next task after acceptance: REST-06 selectors. No release/tag or waiver.
