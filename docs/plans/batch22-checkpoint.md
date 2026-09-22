# Batch 22 — REST-04 permissions

Status: completed; independent final QA returned `pass_with_notes` after protected
delivery and exact post-merge verification.

Base: accepted Batch 21 closeout merge `ba0b546` and acceptance record `3eac78a`.
Contract: [REST-04](02-library-hardening.md#rest-04-защитить-differentiated-permissions).
Public guidance: [REST permissions](../rest-permissions-contract.md).

- Added dispatch coverage for eight callbacks / twelve method variants: default
  capability, client filter, overrides, read-only access, native denial responses,
  unknown callback handling and client isolation. Denials preserve persisted state
  and never invoke the handler. Production source is unchanged.
- Focused integration: 40 tests / 286 assertions, exit 0; three new PHP fixtures
  passed syntax and focused PHPCS checks. Logs: `/tmp/wpconnections-rest04-focused-final.log`
  and `/tmp/wpconnections-rest04-phpcs-final.log`.
- Root accepted the separate worker's precise distinction between managed-boundary
  rejection and earlier WordPress callback validation. No root-authored
  implementation exception; QA's stale checkpoint wording was corrected here.
- Frozen candidate `a891748de684377996d798a76e7c085080e27c91` passed the serial
  local ladder: `make tests.phpunit` (141 / 518), `make tests.integration`
  (556 / 5019, 10 expected multisite-only skips), `make tests.multisite`
  (556 / 5117, no skips), `make tests.isolation ISOLATION_SEED=20260922`
  (reverse and random each unit 282 / 1036 and integration 1112 / 10038,
  20 expected skips), and `make tests.coverage` (697 / 5535, 10 expected
  skips; 3835 / 4172 statements, 91.92%, PR gate passed).
  Logs: `/tmp/wpconnections-b22-local/01-tests-phpunit.log` through
  `05-tests-coverage.log`; coverage summary: `build/coverage/coverage-summary.json`.
  Working tree remained unchanged. Existing WordPress `fonts.php` warnings persist.
- Independent candidate QA confirmed technical readiness at `c91388e`, with no
  open P0–P3 after the checkpoint correction. [PR #110](https://github.com/hokoo/wpConnections/pull/110)
  passed all 20 protected contexts at exact head
  `c91388ebb42b9c14c8b44757d6214b9ac775639f`; strict protection and context names
  were independently verified. Runs: unit `35718814944`, integration/multisite
  `35718815050`, database `35718814975`, coverage `35718814979`, PHPCS `35718814954`.
- PR #110 merged on 2026-09-22 as `5f4c54444ae9331334a74c563ac200a19d1336d0`.
  All 20 post-merge contexts passed at that exact revision: unit `35719322894`,
  integration/multisite `35719322903`, database `35719323011`, coverage
  `35719322869`, PHPCS `35719322783`. Evidence: `/tmp/wpconnections-b22-pr110-ci/`
  and `/tmp/wpconnections-b22-postmerge-ci/`. Independent final QA verified every
  REST-04 AC/DoD and returned `pass_with_notes`, authorizing completion with no
  open P0–P3, material decision, waiver or accepted exception. P4 notes are the
  preexisting WordPress warnings and PHPCS deprecation only. Standing project
  delivery authorization applies; no release/tag. Main-plan status propagation
  accompanies the next implementation batch; this record is authoritative.
- Next ready task after acceptance: REST-05 metadata dispatch semantics. Preserve
  existing supported selector forms and characterize exact runtime argument handling
  before choosing the DELETE schema; no new product decision is currently required.
