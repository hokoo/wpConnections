# Connection delete result and failure contract

Status: DG-DELETE-01—DG-DELETE-04 approved by the repository owner on
2026-09-12; implementation refinement DG-DELETE-04-R2/A approved on
2026-09-13; DB-03B-A merged as `2d52f087`; DB-03B-B merged by PR #93 as
`2348d5a`; DG-DELETE-05/A and DG-DELETE-06/A are approved. The decision-ready
[`deleted-post repair contract`](deleted-post-repair-contract.md) defines the
approved callback-boundary, ledger, and retry/operator refinements for DB-04
production implementation.

Source snapshot: `0db202e7d4a794fd21d82d5305f51f40cb583b92`
(the merge of CORE-00 after SPI-01 into `master`, 2026-09-10).

This is the canonical DB-03A artifact. It inventories the existing connection
delete behavior and makes the remaining public choices reviewable before
DB-03B-A/DB-03B-B change production code. The six `DG-DELETE-*` sections record
the delete decision gates. DG-DELETE-01 and DG-DELETE-04 were refined after an
owner-requested reread of `Relation::detachConnections()` and its introduction
history: the branch order is a deterministic compatibility rule, not an
inherently ambiguous query. All six delete gates are now approved. Their
decision text does not implicitly authorize downstream implementation:
REST-03 consumes DG-DELETE-05/A, while DB-04-I remains gated on the technical
repair refinements required by DG-DELETE-06/A.

## DB-03B-A/DB-03B-B implementation status

The successful connection-delete contract was implemented by PR #87 and merged
to `master` as `2d52f087a1417b1fbab164a9606df4fc96906ccf` on
2026-09-13. The source snapshot below remains the historical pre-implementation
inventory used to make the decisions; current production behavior is governed
by the approved matrix and protected regressions.

- DB-03B-A established exact relation ownership for relation-level ID deletion.
  Batch 15 additionally gives default `WPStorage` one atomic relation-scoped
  membership-and-lock boundary before the client-wide cascade.
- Explicit presence selects `id → both → from+to → from → to`; invalid selected
  values throw before SQL and never fall through.
- Query selectors retain raw constructor, setter and repeated direct-write
  values through the approved virtual-property ledger.
- Default storage normalizes positive integer IDs, parameterizes exact relation
  identity and returns logical connection-row counts after successful cascades.
- Successful ID, pair, from, to and both-side deletions remove all matching
  metadata; valid no-match remains `0`.
- DB-05 keeps relation lookup and client-wide ID deletion inside one atomic
  boundary. Batch 15 removes the remaining default-adapter TOCTOU window with
  `RelationScopedDeleteStorageInterface`; custom adapters without that optional
  capability retain a v1 compatibility fallback owned by REL-02.

Exact candidate `9e88eef` received independent QA PASS, 17/17 protected
checks, combined coverage `237 tests / 2030 assertions` and statement coverage
`1335/1453 (91.88%)`. Exact merge `2d52f087` passed all 17 post-merge jobs.

DB-03B-B candidate `1568007` adds strict selector-read failure attribution,
actual-locked-row ID write sets, valid no-match/no-DML behavior, exhaustive
selector/meta/connection fault injection, delete-specific commit and hook
timing coverage, and deterministic two-session relation/endpoint race proof.
Local verification is green: unit `19/96`, integration `337/2988`, pinned
MariaDB 10.11.16 and MySQL 8.0.46 each `337/2988`, reverse/random isolation,
PHPCS `59/59`, and combined coverage `356 tests / 3082 assertions` with PR
statements `1745/1910 (91.36%)`. Independent audits returned PASS and
PASS_WITH_NOTES without blockers; their failure-hook/custom-adapter notes are
recorded in the contract docs. Final head `e4142c2` and merge `2348d5a` each
passed 19/19 protected/post-merge checks; DB-03B-B is completed.

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
  records its current boundary and the approved failure/recovery policy in
  `DG-DELETE-06/A`; DB-04-D's durable mechanism refinements were approved as
  A on 2026-09-14, and its verified design closeout now makes DB-04-I1 ready.

Generic adapter failures and transaction capability are governed by approved
[`DG-SPI-03`](./storage-spi-contract.md#dg-spi-03--non-update-result-and-failure-protocol),
and
[`DG-SPI-04`](./storage-spi-contract.md#dg-spi-04--transaction-capability-and-orchestration-shape).
Commit-aware hook meaning is owned by approved
[`DG-SPI-06/A`](./storage-spi-contract.md#dg-spi-06--mutation-hook-meaning-across-commitrollback).
This artifact applies those approved SPI contracts to delete scenarios without
expanding their scope.
Approved DG-M7/A already requires an atomic connection-plus-metadata outcome or
an explicit capability error before mutation.

## Public and extension surface inventory

| Surface | Declared/current input | Current result | Current selection and side effects | Gap owned or refined here |
| --- | --- | --- | --- | --- |
| `Storage::deleteSpecificConnections()` | Untyped value documented as `int\|int[]` | `int`, documented only as rows affected | Client-wide connection IDs; metadata delete, then connection delete | Relation isolation, input normalization, logical count, no-match, failure |
| `Storage::deleteByObjectID()` | Untyped `int\|int[]`; optional relation; `onlyFrom`/`onlyTo` flags | `int` | Resolves IDs on either or one endpoint side, then deletes metadata and connections | Direction truth table, relation identity, invalid flags, count, no-match, failure |
| `Storage::deleteDirectedConnections()` | Implicitly nullable integer `from`/`to`; optional relation | `int` | Resolves every matching directed row, then deletes metadata and connections | Required endpoints, exact relation, duplicates, count, no-match, failure |
| `Relation::detachConnections()` | One typed `Query\Connection` | `int` | Chooses one storage method by the historical `id`, `both`, pair, `from`, `to` priority and forces its relation only on endpoint selectors | Cross-relation ID deletion, undocumented precedence, swallowed invalid-input errors |
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

Lower-priority populated fields are ignored after the first matching branch.
The owner-requested rerun confirmed that this order was introduced together
with the method in commit `0e72bdc` and each branch was labelled by intent. It
is therefore deterministic historical precedence, not evidence that one call
was meant to compose several delete operations. It does differ from
`findConnections()`, where non-ID endpoint predicates are combined with `AND`,
so the precedence must be documented and tested rather than inferred from read
semantics. The ID branch does not pass the owning relation at all, so calling
`relation-A->detachConnections()` with an ID owned by relation B deletes B's
row. The REST route contains a relation path parameter but inherits the same
behavior. A fixed-floor integration probe against the source snapshot
reproduced a cross-relation delete with result `1`.

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

## Implementation decision matrix

The DG-DELETE-01—04 rows below are approved and executable in DB-03B-A. Rows
that cite pending SPI, REST or recovery gates remain conditional until those
specific decisions and dependencies are complete.

| Scenario | Recommended observable outcome | Required proof | Gate/owner |
| --- | --- | --- | --- |
| Relation delete by existing ID owned by that relation | Logical count `1` | Only that row and all its metadata disappear | DG-DELETE-01/02; DB-03B-A |
| Relation delete by ID owned by another relation | Valid no-match `0`; REST converts to its approved not-found response | Foreign row/meta remain | DG-DELETE-01/03; REST-00A/REST-03 |
| Direct SPI delete by one/several IDs | Client-wide primitive; count distinct matching connection rows | Missing IDs do not inflate count; duplicate input IDs do not double-count | DG-DELETE-02/03/04; DB-03B-A/REL-02 |
| Directed pair | All exact-pair rows in exact optional relation | Duplicate rows count separately; unrelated relation remains | DG-DELETE-01/02; DB-03B-A |
| Object, neither direction flag | Incoming union outgoing in exact optional relation | Self-row selected once; other relation remains | DG-DELETE-01/02; DB-03B-A |
| Object, one direction flag | Only the named endpoint side | Mirrored from/to fixtures | DG-DELETE-01/04; DB-03B-A |
| Valid selector, no rows | `0`, never an adapter failure | No writes; no committed-success hook | DG-DELETE-03 plus DG-SPI-03/06 |
| Empty or invalid selected selector; mixed-invalid direct-SPI ID list | Stable attributable domain error before SQL | No storage mutation or committed-success hook | DG-DELETE-01/04 plus DG-SPI-03/06 |
| Any selector read/write failure | Stable adapter/domain failure, never `0` or partial success | Original state restored; failure context retained outside default REST body | DG-SPI-03/04/06; DB-03B-B/DB-05/REST-00A |
| Connection plus any number of metadata rows | One logical connection contributes `1` | All-or-nothing rollback at every fault point | Approved DG-M7 and DG-SPI-04/A; DB-03B-B/DB-05 |
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
   hook timing/name compatibility follows approved DG-SPI-06/A.

The transaction API and backend feasibility were approved by DG-SPI-04/A and
DB-00; DB-05 implements the reusable transaction boundary and fault-injection
infrastructure. DB-03B-A added selector/count/SQL-safety regressions after the
`DG-DELETE-*` decisions; completed DB-03B-B provides exhaustive delete failure
and hook conformance against the implemented boundary.

WordPress `deleted_post` is an external boundary: it fires after WordPress has
deleted the post. Even an atomic connection/meta cleanup cannot restore that
post. DB-04 must test the real hook and implement the policy selected in
DG-DELETE-06; it must not describe a thrown callback error as a post rollback.

## Hook inventory and conformance refinement

The following table records observations from the historical source snapshot,
not the current hardened behavior and not approval of hook compatibility:

| Method | Attempt hooks | Success-named hooks | Early/no-match behavior |
| --- | --- | --- | --- |
| `deleteSpecificConnections` | Global hook receives client and raw IDs; client hook receives raw IDs | Global receives client, normalized IDs, final rows; client hook receives normalized IDs and rows | At the source snapshot: invalid input fired attempts then exception; no match fired attempts and success hooks with `0` |
| `deleteByObjectID` | Global receives client, raw IDs, relation, flags; client hook omits client | Both receive resolved connection IDs | Conflicting flags/no match: attempt hooks only |
| `deleteDirectedConnections` | Global receives client, from, to, relation; client hook omits client | Both receive resolved connection IDs | Empty endpoint/no match: attempt hooks only |
| `removeConnectionMeta` | Global `before` receives client, ID, selector and SQL | Global `after` receives client, ID, selector, SQL and `int\|false` | Failure is exposed raw to the after hook |

DG-SPI-06/A preserves existing names and argument order and moves
success-named hooks to after commit. DB-03B-B captures those arguments in the
test suite; current valid no-match emits attempt hooks only, performs no DML and
emits no success-named hook. REL-02 supplies adapter-neutral hook conformance.
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

Neither `findConnections()` nor `detachConnections()` currently selects
connection rows by stored connection metadata. `Query\Connection::$meta` is a
mutation payload and a selector for removing metadata rows from one known
connection; it is not a connection-row predicate. Adding metadata-based
connection selection would require explicit key/value, duplicate, AND/OR,
indexing, adapter-capability, REST and atomic selection/delete semantics. It is
therefore recorded as the separate deferred API-05 design task and is not
implicitly added to DB-03B-A.

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
- The delete branch priority is historical and deterministic. Rejecting a
  populated lower-priority selector would introduce a new v1 failure mode with
  no consumer evidence requiring it. The approved contract preserves the order
  while requiring the selected branch to be valid and relation-safe.
- Private consumers and hook callbacks cannot be enumerated. Exact input
  permissiveness, return values, hook timing, and exception behavior remain
  compatibility-sensitive even where public evidence is absent.

## Decision gates

### DG-DELETE-01 — relation ownership and selector composition

**Status:** approved A-R by the repository owner on 2026-09-12 after an
owner-requested rerun against `Relation::detachConnections()` and commit
`0e72bdc`.

**Problem:** a relation-scoped domain call and REST URL can currently delete an
ID belonging to another relation. Object/directed storage relation filters use
wildcard-capable `LIKE` rather than identity. The original gate also called a
query with several populated selector fields ambiguous. The rerun found a
deterministic, intentional-looking historical order — `id`, `both`, `from+to`,
`from`, `to` — although that delete order is undocumented and differs from
non-ID read-query predicate composition.

- A-R: the domain relation is authoritative. Preserve and document the existing
  selector precedence; choose the highest-priority explicitly provided family,
  then validate and execute only that branch without invalid-to-lower fallback.
  Scope ID deletion to the receiving relation and use exact optional relation
  identity for endpoint deletes. Retain direct
  `Storage::deleteSpecificConnections()` as an explicitly client-wide legacy
  SPI primitive because its signature has no relation.
- B: make selector families mutually exclusive and reject a query containing a
  populated lower-priority family, while applying the same relation ownership
  and exact-match hardening as A-R.
- C: add a new typed delete command/result service with explicit scope and match
  mode, then deprecate the existing domain/storage entrypoints in a major-version
  migration.
- D: preserve current priority and client-wide ID behavior; document that the
  domain relation and REST path relation do not constrain an ID delete, and keep
  `LIKE` pattern semantics for direct endpoint methods.

**Decision:** A-R for v1 hardening, with C as a possible next-major API.
Relation identity is already present at the supported domain/REST boundary, and
silently deleting across it is a data-integrity/security defect. Preserving the
branch order respects the original method structure and the owner's clarification
that lower-priority fields have no effect after a selector is chosen. A-R also
retains the observed CF7 VK client-wide orphan-cleanup primitive at the explicitly
direct SPI surface.

**Compatibility impact:** A-R keeps the historical branch choice, changes
cross-relation ID calls from deletion to no-match, and removes undocumented
wildcard relation matching. Direct ID deletion remains client-wide. B would add
a new v1 validation failure for previously deterministic calls. C breaks or
deprecates public surfaces. D preserves dangerous cross-relation behavior.

**Consequences:** approval unblocks the relation-ownership and selector-order
parts of DB-03B-A and provides required input to REST-03, REST-05 and REL-02.
DOC-01 consumes the later verified implementation and must not document the
current cross-relation behavior as supported.

### DG-DELETE-02 — logical affected-count semantics

**Status:** approved A by the repository owner on 2026-09-12.

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

**Decision:** A. It matches the abstract methods' connection-delete
purpose and normal successful `WPStorage` result without a signature break. A
future major version may add C without redefining the v1 integer.

**Compatibility impact:** A makes counts deterministic and excludes meta-row
multiplicity; callers relying on raw database-row totals would need migration,
although current code normally returns only the final connection delete count.
B leaks adapter schema. C breaks domain/SPI consumers and implementers.

**Consequences:** approval unblocks DB-03B-A's successful-count work and
provides required input to DB-03B-B, REST-03 and REL-02. DB-05 delete assertions
remain a downstream refinement because DB-05 waits for DB-03B-A's implemented
count semantics.

### DG-DELETE-03 — valid no-match and partial-match semantics

**Status:** approved A by the repository owner on 2026-09-12.

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

**Decision:** A. It preserves the useful v1 integer/no-match behavior for
PHP, current partial-match cleanup, and the current REST distinction, while
ending failure masking. B changes bulk PHP behavior; C breaks the integer return
and changes the existing REST error contract.

**Compatibility impact:** A makes previously swallowed invalid/failure cases
throw instead of returning `0`; valid no-match and partial-match callers remain
compatible. B can break idempotent/best-effort cleanup. C changes PHP/SPI and
observable REST responses.

**Consequences:** approval unblocks DB-03B-A's no-match work and provides
required input to DB-03B-B, REST-03 and REL-02. REST-00A remains the owner of
the downstream HTTP mapping.

### DG-DELETE-04 — ID normalization and invalid or ambiguous input

**Status:** approved A-R by the repository owner on 2026-09-12, coordinated with
the preserved precedence in DG-DELETE-01/A-R.

**Problem:** the untyped SPI accepts `is_numeric()` values, silently drops bad
members from mixed arrays, permits non-positive/non-integral forms, and returns
`0` for conflicting direction flags or missing directed endpoints. Destructive
partial acceptance and no-op coercion hide caller defects. The original option A
also rejected multiple populated domain selector families; the rerun moved that
question to DG-DELETE-01 and preserved the historical priority.

- A-R: retain v1 signatures but accept only positive PHP integers or losslessly
  normalizable decimal digit strings, and a non-empty array composed entirely of
  those values. Normalize to unique integers. Reject zero, negatives, floats,
  exponent notation, booleans, null, empty/all-invalid/mixed-invalid arrays,
  missing required values for the selected branch, and conflicting direct-SPI
  direction flags with a stable domain error before SQL. At the domain boundary,
  choose the highest-priority explicitly provided selector before validation.
  An invalid chosen selector is an error and never falls through to a
  lower-priority field. Lower-priority fields are ignored only after the chosen
  selector is valid. Explicit `from+to` selects the pair; an invalid side does
  not fall back to one-sided deletion.
- B: preserve current `is_numeric()` filtering and `0` results, changing only SQL
  construction to use placeholders.
- C: add typed ID-list and selector value objects/new methods, then deprecate the
  untyped signatures in the next major version.

**Decision:** A-R for current signatures, with C as a next-major cleanup.
It preserves common integer/numeric-path usage while preventing destructive
partial acceptance. SQL must be parameterized under every option.

**Compatibility impact:** A-R breaks callers passing mixed lists, floats,
scientific notation, non-positive values, or relying on invalid input as no-op.
The documented `int|int[]` surface, historical domain selector precedence and
observed CF7 VK integer ID remain valid. B preserves unsafe coercion; C is an
explicit SPI/API migration.

**Consequences:** approval unblocks DB-03B-A's normalization work and provides
required input to DB-04, REST-03 and REL-02.

**Implementation clarification (2026-09-12):** validation must receive the
exact value supplied for the selected field, not the value after assignment to
`Query\Connection`'s typed public properties. In weak PHP typing, values such
as `1.5`, `"1e3"` and `true` can otherwise become positive integers before
`Relation::detachConnections()` sees them and can select real rows. The query
therefore records selector inputs before property materialization; relation
dispatch first chooses the historical branch and only then normalizes that
branch's recorded values. This also permits an incompatible lower-priority
value to remain ignored after a valid higher-priority selector, as A-R
requires. Regression coverage must prove every domain branch rejects raw
float, exponent/whitespace/plus strings, boolean, overflow and incompatible
values before SQL.

### DG-DELETE-04-R2 — Raw selector safety versus Query introspection

**Status:** approved A by the repository owner on 2026-09-13.

**Problem:** the first raw-value ledger fixed constructor, setter and first
direct writes, but a materialized typed public property allowed a later direct
write to bypass `__set()`. PHP could again coerce `1.5`, `true` or
`"1e3"` into a valid positive integer and make deletion target a real row.
On PHP 8.1 it is impossible both to keep these public typed properties
initialized for `get_object_vars()` and to intercept every later direct write.

- A: keep the four tracked Query selectors permanently uninitialized and store
  every provided value in the Query ledger. Preserve direct reads, `get()`,
  `set()`, `isset()`, `property_exists()`, `isProvided()`, `toArray()`
  and explicit JSON serialization. Presence introspection is supported through
  `isProvided()`; `get_object_vars()` is not a supported Query presence API.
- B: remove the shared property types from `Abstracts\Connection`, preserving
  raw object introspection but broadening the change to hydrated connections,
  reflection and consumer subclasses.
- C: keep materialized properties and document the repeated-write destructive
  hole.
- D: defer an immutable delete-command DTO to 2.0 and leave v1 incomplete.

**Decision:** A. It confines the compatibility refinement to query
introspection while making all constructor, setter and repeated direct-write
paths safe. B has a materially broader v1 compatibility cost; C violates
DG-DELETE-04/A-R; D does not satisfy Batch 12.

**Compatibility impact:** callers must use `isProvided()` rather than
`get_object_vars()` to distinguish omitted and explicitly provided selector
fields. Direct property reads retain the declared typed-property behavior;
`get()` and selector validation retain the exact recorded input. JSON
serialization explicitly includes provided selector fields and omits
unprovided ones.

### DG-DELETE-05 — REST connection-delete success representation

**Status:** approved A by the repository owner on 2026-09-14.

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

**Consequences:** REST-03 now consumes this approved representation. DOC-01 is a
downstream refinement because it waits for the implemented REST tasks and must
then document their verified success representation.

### DG-DELETE-06 — `deleted_post` cleanup failure and recovery

**Status:** approved A by the repository owner on 2026-09-14. This approves the
recovery policy, not an implicit scheduler or durable-record implementation.
DB-04-D subsequently presented those refinements, and DG-DELETE-06R1/R2/R3/A
were explicitly approved on 2026-09-14.

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

**Consequences:** DB-04-D refined the approved policy into three technical
human gates, all approved as A on 2026-09-14. Its verified design closeout is
complete, so DB-04-I1 is ready; later slices retain their implementation
dependencies. REL-03 and DOC-01 consume DB-04-I's verified
recovery/operational contract. DB-05 supplies only the inner connection/meta
atomic primitive.

DB-04-D's source/runtime audit is recorded in the
[`deleted-post repair contract`](deleted-post-repair-contract.md). Its
`DG-DELETE-06R1` through `DG-DELETE-06R3` were explicitly approved as A by the
repository owner on 2026-09-14; that approval is separate from
DG-DELETE-06/A.

## Downstream acceptance matrix

### DB-03B-A and DB-03B-B

- Cover each storage method plus every `Relation::detachConnections()` branch.
- Assert exact relation isolation and the approved historical precedence:
  `id`, `both`, `from+to`, `from`, `to`. Prove that a populated lower-priority
  field neither broadens the deletion nor changes the selected operation.
- Test single ID, multiple IDs, duplicate inputs, partial ID matches, duplicate
  stored pairs, self-connections, from/to/both sides, exact relation and empty
  direct-SPI relation.
- Apply DG-DELETE-04 to zero, negative, float, exponent, numeric string, boolean,
  null, scalar garbage, empty, all-invalid and mixed arrays, missing selected
  directed endpoints, and conflicting direct-SPI flags.
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
