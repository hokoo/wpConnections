# Batch 26 — API-04 opt-in REST related entities

Status: completed. API-04 was delivered through PR #116, verified on its exact
head and merge SHA, and issue #20 is closed. The owner authorized this batch on
2026-09-27. Stop after API-04; DOC-01 requires separate execution.

Base: `ec020a9a46d0d6dd21a51b5a9992fd6a89548a01`.
Branch: `batch26-api04-rest-entities`.
Contract: API-04 in [the hardening plan](02-library-hardening.md) and approved
[API-01 related-entities decisions](../api-01-related-entities-contract.md).

- Scope: opt-in `representation=expanded`, target projection, permission-safe
  post/custom REST preparation, validated entity filters, deterministic opt-in
  ordering, pagination/totals, full-dispatch and query-budget evidence, public
  guidance and DOC-01 OpenAPI input.
- Dependencies: API-01, API-03, REST-03, REST-06 and DG-API20-02—07/09 approvals
  are complete. Legacy/default v1 shape and unbounded behavior remain intact.
- AC/DoD: all target/duplicate/self/unavailable cases; private/draft/context
  safety; filter AND/OR before totals/pages; strict invalid-input responses;
  non-post adapter authorization/preparation; 1-versus-50 budget; unit,
  integration, multisite, seeded isolation, coverage and PHPCS gates, then
  protected PR/merge and issue #20 closure.
- Exclusions: DOC-01 OpenAPI artifact, dashboard, storage SPI/schema changes,
  implicit legacy pagination, release/tag/deployment/publication.

## Candidate review boundary

One bounded worker implemented the REST request/response path, additive
client-owned `RestEntityAdapterInterface`, full-dispatch tests and public
guidance. A separate read-only review found that WordPress could validate
API-04 body/JSON parameters while the handler ignored them and returned a
broader result. A fresh repair worker saved 14 failing full-dispatch cases,
then made all six API-04 arguments query-only with native
`rest_invalid_param` rejection before storage selection. No product criterion
or validation gate was waived.

Focused API-04/REST-06 integration after repair passed 77 tests / 481
assertions with one expected single-site multisite skip. The saved regression
changed from 14 failures to 14 tests / 56 assertions passed. Changed-path
PHPCS, PHP lint and `git diff --check` passed. The initial candidate's broader
focused run passed 63 / 424, one expected skip. Writers have stopped; root
reviewed the touched route/handler, adapter and permission/filter symbols and
accepts this repaired candidate for serial broad verification.

The full SQL delta for 1 versus 50 custom-adapter connections was 1 versus 1;
batch resolve and eligibility remained one call per type group. For posts it
was 13 versus 59, with one per-post WordPress REST controller revision lookup
accounting for the linear component; the remaining queries stayed bounded.
The approved flow prepares posts through their registered controller. Existing
storage has no count/page API, so pagination materializes selected rows before
filtering and slicing; large selections use memory proportional to those rows.

## Verification and repair boundary

The first frozen candidate passed `make tests.phpunit` (143 tests / 533
assertions). `make tests.integration` stopped with eight failures in the
existing `ClientRestApiLifecycleTest` route inventory: its strict GET arg list
still expected only `relation/from/to/both`. No source/test/doc file changed
during the run; logs and manifests are in `/tmp/wpconnections-b26-local/`.

A fresh tests-only worker updated that exact expected list to include the six
approved API-04 args without weakening route identity, method or equality
checks. Focused lifecycle, REST-06 and API-04 integration then passed 100 tests
/ 1305 assertions with two expected skips; `git diff --check` passed. Root
reviewed the one-line test change and accepts it. The broad integration gate
and remaining serial gates must be rerun on this repaired candidate.

## Accepted local verification

All commands on the repaired candidate exited 0. Logs, before/after status
and unchanged-file hash manifests are in `/tmp/wpconnections-b26-final/`.

| Command | Actual result |
| --- | --- |
| `make tests.phpunit` | 143 tests / 533 assertions |
| `make tests.integration` | 681 / 5745; 12 expected single-site skips |
| `make tests.multisite` | 681 / 5845; no skips |
| `make tests.isolation ISOLATION_SEED=20260927` | Reverse/random unit 286 / 1066 each; reverse/random integration 1362 / 11490 each, 24 skips each |
| `make tests.coverage` | 824 / 6276; 12 skips; 4329 / 4693 statements = 92.24%; PR gate passed |
| `make lint.phpcs` | All 108 source files passed |

The initial sandbox PHPUnit invocation could not access Docker; the approved
Docker-access run passed. WordPress core font warnings and the existing PHPCS
configuration deprecation did not fail their gates. Coverage artifacts are in
`build/coverage/`. Root accepts API-04's local AC/DoD evidence. No source,
test or documentation file changed during verification, and no unexpected
generated file appeared; user-owned `.codex/` and `AGENTS.md` remain untouched.

At this local acceptance boundary, protected PR/head and exact-merge checks,
then issue #20 closure, were still pending. DOC-01 and E5 Epic QA were outside
the batch; release/publication were not claimed.

## Protected delivery and batch closeout

- [PR #116](https://github.com/hokoo/wpConnections/pull/116) had exact head
  `6b81ab14062e5c5d5b3af0a6eb90fe060f703ff0`. All 20 required contexts
  completed successfully: 10 unit, 5 integration, 1 multisite, 2 database
  compatibility, 1 coverage and 1 PHPCS. No required context was missing,
  skipped, cancelled, stale or failed.
- The repository owner confirmed live `master` protection on 2026-09-27:
  strict checks for all 20 contexts, administrator enforcement, and force-push
  and deletion disabled. The connected GitHub app could not read the admin
  endpoint (403); this records owner attestation, not an independent API read.
- PR #116 merged as `0ce93fcb5dbc95081711c88ce87a0236898bcd66` on
  2026-09-27. The exact merge SHA passed all 20 required post-merge contexts;
  root independently matched the names, SHA and success states. GitHub closed
  [issue #20](https://github.com/hokoo/wpConnections/issues/20) at merge.
- API-04 and Batch 26 are completed. DOC-01 now has satisfied DoR and moves to
  `todo`, but execution was not authorized in this batch. E5 is incomplete, so
  no E5 Epic QA is claimed. No release or publication occurred. The documented
  all-rows pagination memory cost and WordPress controller query component
  remain residual performance limits, not waived AC or failed gates.
