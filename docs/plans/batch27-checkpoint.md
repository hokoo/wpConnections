# Batch 27 — DOC-01 OpenAPI contract

Status: review. The owner authorized DOC-01 after Batch 26. Local implementation
and required gates are accepted; protected delivery, issue #27 closure and E5
Epic QA remain pending.

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

Next: scoped commit and protected PR; exact head/merge CI; issue #27 closure;
fresh E5 Epic QA; then plan closeout. No exception or release is claimed.
