# Batch 22 — REST-04 permissions

Status: review; implementation complete, broad verification and delivery pending.

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
- Root review requested a precise distinction between managed-boundary rejection
  and earlier WordPress callback validation; a separate bounded worker owns that
  documentation correction. No root-authored implementation exception.
- Required next checks: unit, integration, true multisite, reverse/random isolation
  with seed `20260922`, and PR coverage, run serially on the frozen candidate.
- Independent QA, protected PR with all 20 contexts, merge and post-merge evidence
  remain pending. Standing project delivery authorization applies; no release/tag.
- Next ready task after acceptance: REST-05 metadata dispatch semantics. Preserve
  existing supported selector forms and characterize exact runtime argument handling
  before choosing the DELETE schema; no new product decision is currently required.
