## Summary

<!-- What changed, why, and which issue or plan task does it address? -->

## Verification

<!-- List the exact local commands run and their results. -->

- [ ] I followed the [CI runbook](https://github.com/hokoo/wpConnections/blob/master/docs/ci-runbook.md).
- [ ] Relevant unit and WordPress integration tests pass locally.
- [ ] `make tests.coverage` passes, or I explained why coverage is not affected.
- [ ] `make lint.phpcs` passes, or I explained why PHPCS is not applicable.
- [ ] The pull request head commit has all 10 unit, 5 integration, coverage, and PHPCS checks green.
- [ ] No expected check is missing, skipped, cancelled, or stale; branch-protection settings were verified separately by the merge owner.

## Compatibility and coverage changes

- [ ] I did not change the pinned PHP, WordPress, or Ramsey matrices; or I documented and verified the policy change.
- [ ] I did not change `coverage-baseline.json`; or the new exact ratio is an intentional, reviewed result of source/test changes.
- [ ] A WordPress trunk-only failure is tracked separately and is not presented as a passing stable lane.

## Critical-scenario evidence

<!--
Use the trigger map and IDs in the test-quality contract:
https://github.com/hokoo/wpConnections/blob/master/docs/test-quality.md
Do not leave the IDs/evidence blank: write "none — <reason>" when no critical
behavior is affected.
-->

- Affected critical scenario IDs:
- Test evidence (`path::method` or stable filter, command/lane, result):
- [ ] I reviewed the critical-component trigger map.
- [ ] Every affected critical scenario has relevant automated test evidence, regardless of the global coverage result.
- [ ] REST evidence uses full `WP_REST_Server` dispatch when route, permission, serialization, or HTTP behavior is affected.

### Temporary exception or quarantine

<!--
Write "none" or provide every field. An exception cannot replace a required
critical-scenario test, and an active critical exception blocks an RC.
-->

- Exception ID (`TQ-EX-NNN`):
- Scenario IDs (or `none` only for a non-critical test):
- Exact test and quarantine scope:
- Owner:
- Reason and reproduction evidence:
- Tracking issue:
- Expiry date (`YYYY-MM-DD`) and exit condition:
- Approved by:

## Notes for reviewers

<!-- Include risks, omitted checks, follow-ups, and evidence reviewers need. -->

CI caching/runtime optimization (INFRA-05) is deferred and non-blocking; merge readiness depends on clean reproducibility and the complete expected check set.
