# Connection delete result and failure contract

Status: decision-ready; no decision in this document is approved.

Source snapshot: `0db202e7d4a794fd21d82d5305f51f40cb583b92`
(the merge of CORE-00 after SPI-01 into `master`, 2026-09-10).

This is the canonical DB-03A artifact. It inventories the existing connection
delete behavior and makes the remaining public choices reviewable before
DB-03B-A/DB-03B-B change production code. The six `DG-DELETE-*` sections are
pending human decision gates. Recommendations describe a coherent implementation
candidate; they are not the current contract and do not authorize code, REST,
SPI, hook, or schema changes.

## Ownership and boundaries

Approved `DG-M9/A` separates the supported consumer domain API from the public
storage implementer SPI:

- Applications delete connections through
  [`Relation::detachConnections()`](../src/Relation.php#L101).
- Adapter authors implement the three connection-delete methods declared by
  [`Abstracts\Storage`](../src/Abstracts/Storage.php#L25). Legacy direct calls to
  `Client::getStorage()` remain reachable in v1, but are not the preferred
  consumer mutation flow.
- [`Connection`](../src/Connection.php) has no delete method. A hydrated object
  cannot delete itself; its owning relation and connection ID must be used.
- The default adapter is [`WPStorage`](../src/WPStorage.php). Its SQL, physical
  tables, and low-level hooks are concrete behavior, not automatically portable
  SPI obligations.
- Connection deletion is DB-03A/DB-03B-A/DB-03B-B scope. Selective metadata deletion via
  `removeConnectionMeta()` and the REST `/meta` subresource is inventoried below
  because it shares result/failure problems, but its value semantics and route
  contract remain DB-02/REST-05 work.
- Automatic `deleted_post` behavior remains DB-04 implementation scope. DB-03A
  records its current boundary and exposes the unresolved failure policy in
  `DG-DELETE-06`.

Generic adapter failures, transaction capability, and commit-aware hook meaning
remain owned by pending
[`DG-SPI-03`](./storage-spi-contract.md#dg-spi-03--non-update-result-and-failure-protocol),
[`DG-SPI-04`](./storage-spi-contract.md#dg-spi-04--transaction-capability-and-orchestration-shape),
and
[`DG-SPI-06`](./storage-spi-contract.md#dg-spi-06--mutation-hook-meaning-across-commitrollback).
This artifact refines their delete scenarios without selecting their options.
Approved DG-M7/A already requires an atomic connection-plus-metadata outcome or
an explicit capability error before mutation.

## Public and extension surface inventory

| Surface | Declared/current input | Current result | Current selection and side effects | Gap owned or refined here |
| --- | --- | --- | --- | --- |
| `Storage::deleteSpecificConnections()` | Untyped value documented as `int\|int[]` | `int`, documented only as rows affected | Client-wide connection IDs; metadata delete, then connection delete | Relation isolation, input normalization, logical count, no-match, failure |
| `Storage::deleteByObjectID()` | Untyped `int\|int[]`; optional relation; `onlyFrom`/`onlyTo` flags | `int` | Resolves IDs on either or one endpoint side, then deletes metadata and connections | Direction truth table, relation identity, invalid flags, count, no-match, failure |
| `Storage::deleteDirectedConnections()` | Implicitly nullable integer `from`/`to`; optional relation | `int` | Resolves every matching directed row, then deletes metadata and connections | Required endpoints, exact relation, duplicates, count, no-match, failure |
| `Relation::detachConnections()` | One typed `Query\Connection` | `int` | Chooses one storage method by field priority and forces its relation only on endpoint selectors | Cross-relation ID deletion, ambiguous selector priority, swallowed invalid-input errors |
| REST connection `DELETE` | Authenticated `/relation/{relation}/{connectionID}` | `200 {"deleted":true}` for positive count; otherwise `WP_Error` | Delegates to `Relation::detachConnections()` with ID only | Relation path currently does not scope the ID; success and not-found representation |
| `Storage::removeConnectionMeta()` | Positive-looking connection ID and a meta selector collection | No abstract return type; `WPStorage` returns `int\|false` | Deletes all metadata for an empty selector, or matching key/value rows | Generic SPI failure plus DB-02/REST-05 metadata semantics, not a connection count |
| REST meta `DELETE` | `/relation/{relation}/{connectionID}/meta`; body is used although route args omit `meta` | `{ "deleted": <int> }`; `false` is cast to `0` by the relation wrapper | Does not verify that ID belongs to the route relation; selective path currently meets the separate `Query\Meta` fatal | REST-05/DB-02 own metadata behavior; relation ownership must align with DG-DELETE-01 |
| WordPress `deleted_post` | WordPress passes one post ID to each client adapter callback | Return value is ignored by WordPress | Calls `Storage::deleteByObjectID()` directly for both endpoint sides and all relations in that client's tables | DB-04 success coverage and DG-DELETE-06 failure/recovery policy |

The only committed integration coverage at this snapshot verifies that both
DELETE routes are registered. No test asserts connection delete selection,
counts, metadata cascade, hook timing, SQL failure, REST success/not-found, or
the real `deleted_post` flow.

## Current domain dispatch and selector precedence

`Relation::detachConnections()` currently selects exactly one branch in this
order:

1. non-empty `id` -> `deleteSpecificConnections(id)`;
2. non-empty `both` -> `deleteByObjectID(both, relation)`;
3. non-empty `from` and `to` ->
   `deleteDirectedConnections(from, to, relation)`;
4. non-empty `from` -> `deleteByObjectID(from, relation, true, false)`;
5. non-empty `to` -> `deleteByObjectID(to, relation, false, true)`;
6. no selector -> return `0` without calling storage.

Lower-priority populated fields are silently ignored. The ID branch does not
pass the owning relation at all, so calling `relation-A->detachConnections()`
with an ID owned by relation B deletes B's row. The REST route contains a
relation path parameter but inherits the same behavior. A fixed-floor
integration probe against the source snapshot reproduced a cross-relation
delete with result `1`.

`Relation::detachConnections()` catches `ConnectionWrongData` from the adapter
and returns `0`. It therefore makes malformed storage input indistinguishable
from a valid selector with no matching connection. Other `Throwable` values are
not normalized.

## Current adapter selection and SQL

`WPStorage` keeps connections and metadata in separate per-client tables. The
metadata table has an indexed `connection_id`, but no foreign key or database
`ON DELETE CASCADE`; every cascade below is implemented by two application SQL
statements. The schema declaration does not pin an engine, so DB-00 must verify
transaction capability before DB-05 can satisfy DG-M7.

### Delete by connection ID

`WPStorage::deleteSpecificConnections()` emits both before hooks with the raw
input, then `prepareIDs()`:

- wraps any `is_numeric()` scalar in an array;
- silently removes nonnumeric members from an array;
- throws `ConnectionWrongData` only for a non-array or if no numeric value
  remains;
- accepts values that are numeric but are not positive integer IDs, including
  zero, negatives, decimals, exponent notation, and numeric strings;
- preserves duplicate input IDs.

The normalized values are interpolated into an `IN (...)` expression. Metadata
rows are deleted first, connection rows second. The method ignores both query
return values, emits both success-named hooks, and returns `$wpdb->rows_affected`
from the second statement. Under normal success this happens to be the number of
distinct connection rows deleted: metadata multiplicity is excluded and a
duplicate input ID is not counted twice. A missing ID runs both deletes, emits
the success-named hooks with count `0`, and returns `0`.

### Delete by object side

`WPStorage::deleteByObjectID()` emits before hooks before validating anything.
The direction matrix is:

| `onlyFrom` | `onlyTo` | Current selector |
| --- | --- | --- |
| `false` | `false` | `to IN (...) OR from IN (...)` |
| `true` | `false` | `from IN (...)` |
| `false` | `true` | `to IN (...)` |
| `true` | `true` | immediate `0`; no validation, SQL, or after hook |

An empty relation means every relation. A non-empty relation is interpolated as
SQL `LIKE`, so `%` and `_` act as wildcards and untrusted direct-SPI input is not
parameterized. The initial `SELECT ID` result is treated as empty on both a real
no-match and a database read failure. A matching self-connection is one logical
row even though both OR terms match it. Each matching row has a unique ID;
duplicate directed rows remain distinct logical connections and are all
selected. On a real no-match the method returns `0` without delete statements or
after hooks.

For a non-empty selection it deletes metadata first and connections second,
ignores both return values, emits success-named hooks containing resolved IDs,
and returns the second statement's `$wpdb->rows_affected`.

### Delete by directed pair

`WPStorage::deleteDirectedConnections()` emits before hooks and then returns `0`
for an `empty()` endpoint. Otherwise it selects every row with exact `from` and
`to` values and an optional relation `LIKE` predicate. Empty relation means all
relations. The same unparameterized relation, read-failure/no-match collapse,
metadata-first deletion, unchecked writes, success-hook, and final connection
row count behavior applies. If duplicate rows exist for the pair, all are
selected and each distinct connection row contributes one to the count.

## Current behavior matrix

| Entry/scenario | Current observable result | Mutation and hooks | Contract problem |
| --- | --- | --- | --- |
| ID scalar exists | Positive connection-row count | Raw before hooks; meta then connection; success hooks | Relation ownership is not checked |
| Several existing IDs | Count of distinct matching connection rows | One set-based pair of deletes | High-level API cannot express bulk, direct SPI can |
| Duplicate input IDs | Each stored row counted once | SQL `IN` collapses repeated selectors | Not documented |
| Some IDs missing | Count only existing rows | Missing IDs are silent | Partial match versus strict set semantics undefined |
| No IDs match | `0` | Both deletes and both success hooks still run | No-match and failure share `0` |
| Mixed valid/nonnumeric ID array | Invalid members silently discarded; valid rows deleted | Probe reproduced result `1` for `[valid, "not-an-id"]` | Destructive partial acceptance is surprising |
| Empty/all-invalid ID array | Storage throws code-300 `ConnectionWrongData`; relation returns `0` | Storage before hook has already fired | Invalid input becomes no-match at domain/REST layer |
| Object selector, default flags | All incoming/outgoing rows in optional relation | Resolved IDs; meta then connections | Correct both-side union is undocumented |
| Object selector, one direction flag | Only selected endpoint side | Same | Flag names are public but error contract is absent |
| Object selector, both flags | `0` | Before hook only | Invalid flags look like no-match |
| Directed pair with duplicates | Count of all distinct stored rows for the pair | All their metadata then all rows | Duplicate multiplicity is undocumented |
| Directed pair with missing/zero side | `0` | Before hook only | Invalid selector looks like no-match |
| Non-empty relation selector | SQL `LIKE`; wildcard values can broaden selection | Raw interpolation | Identity versus pattern matching and SQL safety |
| Initial selector query fails | `0` | No delete and no after hook | Storage failure looks like no-match |
| Metadata delete fails, connection delete succeeds | Positive connection count | Connection is removed; orphan meta can remain; success hook fires | False atomic success |
| Metadata delete succeeds, connection delete fails | `0`/adapter-dependent integer coercion | Connection can remain without metadata; success hook can fire | Destructive partial failure masked as no-match |
| REST connection delete, positive count | HTTP 200 with `{deleted:true}` | Same domain/storage behavior | Count is intentionally not exposed but not contracted |
| REST connection delete, zero or swallowed invalid input | `ConnectionNotFound` `WP_Error` | No distinction among causes | Exact HTTP mapping belongs REST-00A; cause must remain attributable |
| REST meta delete | `{deleted: int}`; failure may become `0` | Meta-only delete and its own hooks | DB-02/REST-05 scope; not a logical connection count |
| `deleted_post` | Callback result ignored | Adapter deletes both sides/all relations for one client | Post is already deleted; its transaction cannot be rolled back here |

## Conditional implementation matrix

This matrix is executable only after its cited gates are approved. It shows the
recommended A path so reviewers can judge a complete contract rather than
isolated choices.

| Scenario | Recommended observable outcome | Required proof | Gate/owner |
| --- | --- | --- | --- |
| Relation delete by existing ID owned by that relation | Logical count `1` | Only that row and all its metadata disappear | DG-DELETE-01/02; DB-03B-A |
| Relation delete by ID owned by another relation | Valid no-match `0`; REST converts to its approved not-found response | Foreign row/meta remain | DG-DELETE-01/03; REST-00A/REST-03 |
| Direct SPI delete by one/several IDs | Client-wide primitive; count distinct matching connection rows | Missing IDs do not inflate count; duplicate input IDs do not double-count | DG-DELETE-02/03/04; DB-03B-A/REL-02 |
| Directed pair | All exact-pair rows in exact optional relation | Duplicate rows count separately; unrelated relation remains | DG-DELETE-01/02; DB-03B-A |
| Object, neither direction flag | Incoming union outgoing in exact optional relation | Self-row selected once; other relation remains | DG-DELETE-01/02; DB-03B-A |
| Object, one direction flag | Only the named endpoint side | Mirrored from/to fixtures | DG-DELETE-01/04; DB-03B-A |
| Valid selector, no rows | `0`, never an adapter failure | No writes; no committed-success hook | DG-DELETE-03 plus DG-SPI-03/06 |
| Empty, invalid, mixed-invalid, or ambiguous selector | Stable attributable domain error before SQL | No storage mutation or committed-success hook | DG-DELETE-01/04 plus DG-SPI-03/06 |
| Any selector read/write failure | Stable adapter/domain failure, never `0` or partial success | Original state restored; failure context retained outside default REST body | DG-SPI-03/04/06; DB-03B-B/DB-05/REST-00A |
| Connection plus any number of metadata rows | One logical connection contributes `1` | All-or-nothing rollback at every fault point | Approved DG-M7; pending DG-SPI-04; DB-03B-B/DB-05 |
| REST existing connection delete | Preserve default v1 HTTP 200 `{deleted:true}` | Full dispatch plus persisted-state assertion | DG-DELETE-05; REST-03 |
| WordPress post cascade failure | Post deletion is not claimed rolled back; cleanup failure is observable and repairable | Real hook flow plus injected adapter failure | DG-DELETE-06; DB-04/REL-03 |

## Atomic boundary and failure attribution

DG-M7/A already fixes these requirements; they are not a new DB-03A decision:

1. One delete invocation is one atomic connection-plus-metadata unit for all IDs
   selected by that invocation. Per-row commits are not sufficient.
2. Transaction capability is checked before the first mutation. Unsupported
   storage fails explicitly instead of attempting best effort.
3. Selection and deletion must not allow a concurrent change to turn a scoped
   selector into deletion of a different set. The implementation must use the
   adapter's approved atomic/locking mechanism without exporting SQL as SPI.
4. A selector-read failure, metadata-delete failure, connection-delete failure,
   commit failure, or thrown `Throwable` cannot be represented as `0` or a
   positive count. The pre-call state is restored and the original failure stays
   attributable.
5. Result and committed-success hooks cannot escape before commit. Exact legacy
   hook timing/name compatibility is conditional on DG-SPI-06.

The transaction API and backend feasibility are still pending DG-SPI-04 and
DB-00. DB-03B-A adds selector/count/SQL-safety regressions after the
`DG-DELETE-*` decisions; DB-05 owns the reusable transaction implementation and
fault-injection infrastructure; DB-03B-B then proves delete failure and hook
conformance against that boundary.

WordPress `deleted_post` is an external boundary: it fires after WordPress has
deleted the post. Even an atomic connection/meta cleanup cannot restore that
post. DB-04 must test the real hook and implement the policy selected in
DG-DELETE-06; it must not describe a thrown callback error as a post rollback.

## Hook inventory and conformance refinement

The following are observations, not approval of hook compatibility:

| Method | Attempt hooks | Success-named hooks | Early/no-match behavior |
| --- | --- | --- | --- |
| `deleteSpecificConnections` | Global hook receives client and raw IDs; client hook receives raw IDs | Global receives client, normalized IDs, final rows; client hook receives normalized IDs and rows | Invalid input: attempts fire, then exception. No match: attempts and success hooks fire with `0` |
| `deleteByObjectID` | Global receives client, raw IDs, relation, flags; client hook omits client | Both receive resolved connection IDs | Conflicting flags/no match: attempt hooks only |
| `deleteDirectedConnections` | Global receives client, from, to, relation; client hook omits client | Both receive resolved connection IDs | Empty endpoint/no match: attempt hooks only |
| `removeConnectionMeta` | Global `before` receives client, ID, selector and SQL | Global `after` receives client, ID, selector, SQL and `int\|false` | Failure is exposed raw to the after hook |

DG-SPI-06 owns whether existing names and argument order remain public, and
whether success-named hooks move to after commit. DB-03B-B must capture the
current arguments before refactoring; REL-02 supplies adapter-neutral hook conformance.
DB-03A does not rename hooks, require a SQL payload from custom adapters, or
choose attempt/commit/rollback notifications.

## REST and metadata-delete boundary

The connection DELETE route requires a numeric path ID, authenticates through
the callback capability, looks up the route relation, and passes only the ID to
the relation. A positive domain count becomes the default v1 boolean success
body. Any `0`, including a swallowed invalid-input error or masked adapter
failure, becomes `ConnectionNotFound`. `ClientRestApi::getError()` supplies a
body code/message without status data; REST-00A owns the exact HTTP mapping and
must never interpret numeric domain codes as redirects.

The `/meta` DELETE route is adjacent, not the same contract:

- it uses the route connection ID without confirming relation ownership;
- its route does not declare a `meta` argument although the handler reads it;
- an empty meta collection deletes every metadata row for the ID;
- a non-empty collection selects key/value pairs but currently hits the separate
  TEST-02F/CORE-07 `Query\Meta` materialization defect;
- the response exposes a metadata-row count, not a logical connection count;
- an adapter `false` is cast to `0` by `Relation::removeConnectionMeta()`.

REST-05/DB-02 retain ownership of selected/all metadata semantics and response
shape. They must reuse the approved relation-ownership and generic failure
rules, without treating DG-DELETE-02's connection count as a metadata count.

## Security and compatibility findings

- The REST relation path is currently not an authorization or data-isolation
  boundary for deletion by ID. A valid ID from another relation in the same
  client can be deleted. DG-DELETE-01 must be decided before DB-03B-A/REST-03.
- `relation LIKE '<raw>'` in object/directed deletes is both unparameterized and
  pattern-capable. DB-03B-A must use a parameterized exact identity if
  DG-DELETE-01/A is approved. Empty relation remains an explicit all-relations
  direct-SPI selector, never an accidental fallback from invalid domain input.
- `is_numeric()` prevents simple SQL text injection but admits non-ID numeric
  forms and silently filters mixed arrays. Parameterization and explicit
  normalization remain required.
- Returning `0` for read/write failure can make REST report not-found and can
  make callers treat partial deletion as harmless. This conflicts with approved
  DG-M7 regardless of the selected public result representation.
- Public-consumer inventory found CF7 VK directly invoking
  `getStorage()->deleteSpecificConnections()` for orphan cleanup. Removing the
  direct method or making it relation-scoped without a replacement is a breaking
  change. The recommended gates retain the client-wide direct SPI primitive and
  harden its input/result/failure contract.
- Private consumers and hook callbacks cannot be enumerated. Exact input
  permissiveness, return values, hook timing, and exception behavior remain
  compatibility-sensitive even where public evidence is absent.

## Pending decision gates

### DG-DELETE-01 — relation ownership and selector composition

**Problem:** a relation-scoped domain call and REST URL can currently delete an
ID belonging to another relation. A query containing several selector families
silently uses the first non-empty one. Object/directed storage relation filters
use wildcard-capable `LIKE` rather than identity.

- A: the domain relation is authoritative. Require exactly one selector family;
  scope ID deletion to the receiving relation; use exact optional relation
  identity for endpoint deletes. Retain direct
  `Storage::deleteSpecificConnections()` as an explicitly client-wide legacy
  SPI primitive because its signature has no relation.
- B: preserve current priority and client-wide ID behavior; document that the
  domain relation and REST path relation do not constrain an ID delete, and keep
  `LIKE` pattern semantics for direct endpoint methods.
- C: add a new typed delete command/result service with explicit scope and match
  mode, then deprecate the existing domain/storage entrypoints in a major-version
  migration.

**Recommendation:** A for v1 hardening, with C as a possible next-major API.
Relation identity is already present at the supported domain/REST boundary, and
silently deleting across it is a data-integrity/security defect. A retains the
observed CF7 VK client-wide orphan-cleanup primitive at the explicitly direct SPI
surface.

**Compatibility impact:** A rejects ambiguous multi-selector domain queries,
changes cross-relation ID calls from deletion to no-match, and removes
undocumented wildcard relation matching. Direct ID deletion remains client-wide.
B preserves dangerous behavior. C breaks or deprecates public surfaces.

**Consequences:** DB-03B-A, REST-03, REST-05 relation ownership, and REL-02 are
blocked until this gate is approved. DOC-01 is a nonblocking downstream
refinement: it consumes the verified implementation contract through its
existing REST dependencies and must not document the current cross-relation
behavior as supported.

### DG-DELETE-02 — logical affected-count semantics

**Problem:** all connection-delete methods declare `int`, but “rows affected”
does not say whether the value counts input IDs, connection rows, metadata rows,
both SQL statements, or unique endpoint pairs. Duplicate input IDs, duplicate
stored connections, self-connections, and partial matches make these differ.

- A: preserve the v1 `int` signatures and define the value as the number of
  distinct stored connection rows committed as deleted. Metadata rows never
  contribute; repeated input IDs and a self-row are counted once; duplicate
  stored connection rows have distinct IDs and each counts once.
- B: return the sum of physical connection and metadata rows affected by all
  statements.
- C: replace the integer with a result object containing requested, matched,
  deleted-connection, deleted-meta, skipped, and failure fields.

**Recommendation:** A. It matches the abstract methods' connection-delete
purpose and normal successful `WPStorage` result without a signature break. A
future major version may add C without redefining the v1 integer.

**Compatibility impact:** A makes counts deterministic and excludes meta-row
multiplicity; callers relying on raw database-row totals would need migration,
although current code normally returns only the final connection delete count.
B leaks adapter schema. C breaks domain/SPI consumers and implementers.

**Consequences:** DB-03B-A, DB-03B-B, REST-03, and REL-02 are blocked until this
gate is approved. DB-05 delete assertions are a nonblocking refinement here
because DB-05 already waits for DB-03B-A's approved and implemented count
semantics.

### DG-DELETE-03 — valid no-match and partial-match semantics

**Problem:** `0` currently represents a valid selector with no row, invalid or
empty input after domain coercion, selector-query failure, write failure, and
sometimes a partial match. A valid bulk list may contain existing and missing
IDs, but the caller cannot tell whether missing members should invalidate the
whole request. REST converts every zero to not-found.

- A: reserve domain/SPI `0` for a valid selector that committed deletion of no
  connection rows. A valid bulk list deletes/counts its existing rows without
  treating missing IDs as invalid; all-missing returns `0`. Invalid input and
  adapter failure are attributable exceptions. Preserve the REST single-resource
  behavior of converting valid `0` to `ConnectionNotFound`; REST-00A decides its
  HTTP status/body mapping.
- B: require every requested ID to exist within scope; any missing member throws
  `ConnectionNotFound` and the atomic invocation deletes none.
- C: return a result object with deleted and missing IDs; for REST, treat a
  missing single resource as idempotent success.

**Recommendation:** A. It preserves the useful v1 integer/no-match behavior for
PHP, current partial-match cleanup, and the current REST distinction, while
ending failure masking. B changes bulk PHP behavior; C breaks the integer return
and changes the existing REST error contract.

**Compatibility impact:** A makes previously swallowed invalid/failure cases
throw instead of returning `0`; valid no-match and partial-match callers remain
compatible. B can break idempotent/best-effort cleanup. C changes PHP/SPI and
observable REST responses.

**Consequences:** DB-03B-A, DB-03B-B, REST-03, and REL-02 are blocked until this gate is
approved. REST-00A is a nonblocking mapping refinement: it may complete a
decision-ready error taxonomy while leaving delete no-match classification
conditional on this gate.

### DG-DELETE-04 — ID normalization and invalid or ambiguous input

**Problem:** the untyped SPI accepts `is_numeric()` values, silently drops bad
members from mixed arrays, permits non-positive/non-integral forms, and returns
`0` for conflicting direction flags or missing directed endpoints. Destructive
partial acceptance and no-op coercion hide caller defects.

- A: retain v1 signatures but accept only positive PHP integers or losslessly
  normalizable decimal digit strings, and a non-empty array composed entirely of
  those values. Normalize to unique integers. Reject zero, negatives, floats,
  exponent notation, booleans, null, empty/all-invalid/mixed-invalid arrays,
  missing required endpoints, conflicting flags, and multiple domain selector
  families with a stable domain error before SQL.
- B: preserve current `is_numeric()` filtering and `0` results, changing only SQL
  construction to use placeholders.
- C: add typed ID-list and selector value objects/new methods, then deprecate the
  untyped signatures in the next major version.

**Recommendation:** A for current signatures, with C as a next-major cleanup.
It preserves common integer/numeric-path usage while preventing destructive
partial acceptance. SQL must be parameterized under every option.

**Compatibility impact:** A breaks callers passing mixed lists, floats,
scientific notation, non-positive values, or relying on invalid input as no-op.
The documented `int|int[]` surface and observed CF7 VK integer ID remain valid.
B preserves ambiguity; C is an explicit SPI/API migration.

**Consequences:** DB-03B-A, DB-04's destructive-input contract, REST-03, and
REL-02 are blocked until this gate is approved.

### DG-DELETE-05 — REST connection-delete success representation

**Problem:** the v1 connection DELETE route currently returns HTTP 200 with
`{"deleted":true}` for any positive count and exposes neither the connection ID
nor bulk count. Changing to `204`, a count, or a richer result is public REST
behavior governed by approved DG-M4/A.

- A: preserve the exact default v1 success body and status: HTTP 200 with
  `{"deleted":true}` for one existing route resource. Keep logical counts at
  the PHP/SPI boundary and use the REST error mapping for no-match/failure.
- B: return HTTP 200 with `{"deleted": <logical-count>}` and optionally the
  deleted ID.
- C: return HTTP 204 with no response body.

**Recommendation:** A for the hardening release. The route identifies one
resource and the existing body is adequate once relation isolation, no-match,
and failure are correct. B/C can be an opt-in or next-major representation.

**Compatibility impact:** A preserves default consumers. B changes the type of
`deleted`; C removes the response body and changes status. Exact error status and
body remain REST-00A decisions, not this gate.

**Consequences:** REST-03 is blocked until this gate is approved. DOC-01 is a
nonblocking downstream refinement because it already waits for the implemented
REST tasks and must then document their approved success representation.

### DG-DELETE-06 — `deleted_post` cleanup failure and recovery

**Problem:** every initialized client registers its adapter's object-delete
method directly on WordPress `deleted_post`. The post is already deleted before
the callback runs, WordPress ignores the integer result, and a connection/meta
transaction cannot roll back the post. Silent failure leaves orphans; throwing
can turn the request into an error even though the post deletion committed.

- A: perform synchronous atomic connection/meta cleanup per client; on failure,
  persist a repair record keyed by client/post/operation, schedule an idempotent
  retry, and keep failure/retry status observable until cleanup succeeds. Never
  claim that the WordPress post was rolled back. DB-04 must refine the durable
  record and scheduler mechanism before implementation.
- B: propagate the adapter exception synchronously from `deleted_post` and
  document that the post may already be gone and manual repair may be required.
- C: log the failure and continue without a required retry/repair contract.

**Recommendation:** A. It acknowledges the real external transaction boundary
and gives operators a deterministic way to repair orphans. B produces a
misleading failed request without restoring the post; C can lose integrity
silently.

**Compatibility impact:** A adds operational state/API or scheduling behavior
that must be designed by DB-04/REL-03 and may execute cleanup later. B can expose
new exceptions to post-deletion callers. C preserves weak behavior but conflicts
with the release integrity goal.

**Consequences:** DB-04 is blocked until this gate is approved. REL-03 and
DOC-01 are nonblocking downstream refinements that consume DB-04's verified
recovery/operational contract. DB-05 supplies only the inner connection/meta
atomic primitive.

## Downstream acceptance matrix

### DB-03B-A and DB-03B-B

- Cover each storage method plus every `Relation::detachConnections()` branch.
- Assert exact relation isolation and selector-family behavior selected by
  DG-DELETE-01.
- Test single ID, multiple IDs, duplicate inputs, partial ID matches, duplicate
  stored pairs, self-connections, from/to/both sides, exact relation and empty
  direct-SPI relation.
- Apply DG-DELETE-04 to zero, negative, float, exponent, numeric string, boolean,
  null, scalar garbage, empty, all-invalid and mixed arrays, missing directed
  endpoints, conflicting flags, and ambiguous domain queries.
- Assert the DG-DELETE-02 logical count independently of metadata multiplicity.
- Parameterize IDs and relation; prove wildcard/metacharacter input cannot
  broaden selection or produce a `wpdb::prepare` warning.
- In DB-03B-B, fault-inject selector read, metadata delete, connection delete,
  commit, and thrown-`Throwable` boundaries. Under DG-M7, state is unchanged and
  failure is never `0`/success. Reuse the transaction boundary from DB-05.
- In DB-03B-B, capture existing hook names/arguments before refactoring and
  assert the DG-SPI-06-approved attempt/commit behavior after refactoring.

### REST-00A and REST-03

- Map valid connection no-match separately from invalid selector, relation
  mismatch, adapter read/write failure, and permission denial.
- Keep numeric domain/body codes separate from HTTP status.
- Through full dispatch, assert that a route relation cannot delete another
  relation's ID and that rejected/failing requests leave rows/meta unchanged.
- Assert the DG-DELETE-05-approved success status/body and do not expose raw SQL
  or database error text by default.

### DB-04

- Exercise a real `wp_delete_post()`/`deleted_post` flow for incoming, outgoing,
  self, multiple relations, and multiple clients.
- Assert connection/meta atomicity within each client and the DG-DELETE-06
  failure/recovery policy without claiming post rollback.
- Verify repeated client/test initialization does not create duplicate callbacks.

### DB-05 and REL-02

- DB-05 provides the DG-SPI-04-approved atomic capability and fault-injection
  mechanism; every delete variant uses one invocation-wide boundary.
- REL-02 verifies custom-adapter results/failures and public hook compatibility,
  including the observed direct `deleteSpecificConnections()` consumer.
- Neither task promotes raw SQL hook payloads to portable SPI without a separate
  approved compatibility decision.

## Reproduction evidence

Source/history inspection:

- `git show 0e72bdc -- src/Relation.php src/Storage.php` — introduced the current
  relation dispatch and its `ConnectionWrongData -> 0` catch in 2021.
- `git show b636c4a -- src/Storage.php` and
  `git show a361950 -- src/Storage.php` — added relation/meta behavior to directed
  and object-side deletes.
- `git show 40d7a16 -- src/Storage.php`, `git show e8fe6a6 -- src/Storage.php`, and
  `git show 73741ee -- src/Storage.php` — added the three delete hook families.
- `git show 5fd6448 -- src/ClientRestApi.php` — introduced REST connection DELETE
  with positive success versus zero not-found.
- `git show 0681035 -- src` — introduced metadata mutation/delete paths.

Temporary fixed-floor probe, intentionally not committed:

```text
PHP 8.1.34 / WordPress 6.7.7 / Ramsey Collection 1.3.0 / MariaDB 11.8.6
DeleteContractProbeTest: 1 test, 5 assertions
cross_relation_id_delete=1
mixed_input_delete=1
missing_id_delete=0
conflicting_flags=0
object_hook_events=["object-before"]
```

The probe confirms source-observed behavior only. Failure injection and the full
matrix belong in DB-03B-A/DB-05/DB-03B-B after the gates are approved.
