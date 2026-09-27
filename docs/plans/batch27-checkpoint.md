# Batch 27 — DOC-01 OpenAPI contract

Status: completed. The owner authorized DOC-01 after Batch 26. PR #118 merged,
issue #27 is closed, and fresh independent E5 Epic QA returned
`pass_with_notes` without a blocking criterion or exception.

Base: `f60ac745cf3a6e2be553e7d517768ecc46565d36`.
Branch: `batch27-doc01-openapi`.
Scope: DOC-01 only; dashboard, v2 routes, release and publication are excluded.

## Artifacts and acceptance evidence

- `docs/openapi.json` is OpenAPI 3.0.3 for the four custom v1 path patterns and
  all 12 registered methods. It covers request and response schemas, auth,
  native/domain/internal errors, CRUD, meta, selectors and opt-in expanded
  related entities. `relation.type` is marked as a deprecated no-op.
- `postman.json` was removed as a stale duplicate. README points consumers to
  the OpenAPI file. The existing protected `php-cs` CI job now runs a pinned
  syntax validator; a WordPress integration test compares OpenAPI path/method
  pairs with live registered custom routes and checks domain error fields.
- Independent read-only review found no concrete contract mismatch. The drift
  assertion checks path/method inventory, not every parameter or schema field;
  dispatch contract tests cover current wire behavior, but future schema drift
  still needs deliberate review.
- Local serial gates passed on the frozen diff: `make lint.openapi` (offline
  cached), `make tests.phpunit` (143/533), `make tests.integration` (681/5739,
  12 expected skips), `make tests.multisite` (681/5839),
  `make tests.isolation ISOLATION_SEED=20260927` (reverse/random unit 286/1066
  each; reverse/random integration 1362/11478 each, 24 skips each),
  `make tests.coverage` (824/6270, 12 skips; 4329/4693 statements = 92.24%,
  PR gate passed), and `make lint.phpcs` (108 source files). Focused inventory
  and empty-entity wire tests also passed. No unexpected tracked change appeared
  during verification; user-owned `.codex/` and `AGENTS.md` were untouched.

## Protected delivery and E5 QA

- [PR #118](https://github.com/hokoo/wpConnections/pull/118) had exact head
  `0d3adcad31e0666b9c7fca2863115f158c66dd9c`; all 20 required contexts
  completed successfully, including the new OpenAPI validation step in
  `php-cs`. No context was missing, skipped, cancelled, stale or failed.
- PR #118 merged as `8a0be6dacf1c22b65071181a9ffbc499392f964d` on
  2026-09-27. The exact merge SHA passed 20/20 required post-merge contexts;
  root independently matched names, SHA and success states. GitHub closed
  [issue #27](https://github.com/hokoo/wpConnections/issues/27) at merge.
- Fresh independent E5 Epic QA checked all E5 success criteria and task
  AC/DoD against the merged baseline, prior task evidence and issue states. It
  returned `pass_with_notes`: no blocking criterion, decision or exception.
  Issue #20 is closed; dashboard issue #28 remains open and PROD-01 is
  explicitly deferred. DOC-01 and E5 are completed.
- Residual limits: route drift automation checks path/method inventory rather
  than every parameter and schema field. Expanded REST pagination still holds
  selected rows in memory and WordPress post preparation adds per-post queries,
  as recorded in [Batch 26 checkpoint](batch26-checkpoint.md).

The repository owner confirmed live strict `master` protection for all 20
contexts, administrator enforcement, and disabled force-push/deletion on
2026-09-27. The connected GitHub app cannot read the admin endpoint (403), so
this is owner attestation rather than independent API proof. No release or
publication is claimed.
