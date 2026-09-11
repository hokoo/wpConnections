# Storage SPI and mutation boundary

Status: partial approved decision contract; DG-SPI-01, DG-SPI-02, DG-SPI-06
and DG-SPI-07 approved A, while DG-SPI-03 through DG-SPI-05 remain pending

Source snapshot: `3f8bc3918fb0eea7888b071a5d7402335b8335ff`.

## Purpose and authority

This document is the canonical inventory and decision record for the storage
extension boundary. It describes the source as it exists at the snapshot above,
the already approved constraints from DG-M7 and DG-M9, the A decisions recorded
for DG-SPI-01/02/06/07 on 2026-09-11, and the decisions still required before
other production signatures or behavior change. Shared result gate
DG-UPDATE-04/A was separately approved on 2026-09-11; neither it nor
DG-SPI-06/A approves the still-pending adapter-failure signal in DG-SPI-03.

The words **current** and **observed** describe compatibility evidence, not a
promise that defective behavior should be retained. A **recommended** option is
not approved until the corresponding gate is resolved by the repository owner.

Primary sources:

- [`Abstracts\Storage`](../src/Abstracts/Storage.php) declares the eight methods
  a replacement adapter must implement.
- [`WPStorage`](../src/WPStorage.php) is the only in-repository implementation.
- [`Factory::getStorage()`](../src/Factory.php#L15) selects and constructs an
  implementation.
- [`Client::getStorage()`](../src/Client.php#L39) exposes the selected adapter.
- [`Relation`](../src/Relation.php#L27) and
  [`Connection::update()`](../src/Connection.php#L30) are the current domain
  callers.
- [`compatibility-inventory.md`](compatibility-inventory.md) records public
  consumer evidence and its limitations.

## Boundary model

| Layer | Supported role in the hardening release | Compatibility position |
| --- | --- | --- |
| Consumer domain API | `Client::getRelation()`, `Relation::createConnection()`, `Relation::findConnections()`, `Relation::detachConnections()`, relation meta operations, and `Connection::update()` | Domain validation, invariants and mutation orchestration belong here under approved DG-M9/A. |
| Storage implementer SPI | `Abstracts\Storage` and the factory replacement filter | Supported extension boundary. Implementers need a stable, tested contract for inputs, results, failures and capabilities. |
| Concrete WordPress adapter | `WPStorage`, its WordPress tables, SQL and low-level hooks | Default implementation, not the definition of every possible storage backend. Concrete-only methods are not automatically SPI requirements. |
| Legacy direct coupling | Public `Client::getStorage()` calls, direct mutations, and `WPStorage` table-name getters | Visibility is retained in the current major version by DG-M9/A, but direct writes are not a supported consumer mutation flow. Observed public uses still require migration notes and compatibility handling. |

The boundary is therefore public in two different senses: applications should
mutate through the domain API, while adapter authors implement the SPI. Public
reachability of `getStorage()` does not move validation responsibility into the
adapter and does not make every `WPStorage` helper a portable SPI method.

## Declared operation inventory

The table separates the declared abstract surface from observed `WPStorage`
behavior. Ambiguities are routed to gates below; they are not silently made
normative here.

| Operation | Declared input/result | Observed `WPStorage` behavior and side effects | Observed failures and contract gaps |
| --- | --- | --- | --- |
| `createConnection` | [`Query\Connection -> int`](../src/Abstracts/Storage.php#L18); documented as a connection ID | Inserts `from`, `to`, `order`, `relation`, `title`; on the first insert failure attempts private schema install and retries; writes the generated ID back into the input query; then inserts every meta item through `addConnectionMeta`; returns the ID. | Throws `ConnectionWrongData` only after the connection insert retry fails. A later meta failure can leave the connection and earlier meta rows committed. Input mutation is required by the current `Relation` result construction but is absent from the abstract contract. See DG-SPI-02 and DG-SPI-04. |
| `updateConnection` | [`Abstracts\Connection -> bool`](../src/Abstracts/Storage.php#L23) | Sends all five persisted scalar fields from the object directly to `wpdb::update`; does not update meta and emits no hook. PHP coerces `wpdb`'s `int|false` result to `bool`, so both a valid unchanged row (`0`) and failure (`false`) become `false`. | `Relation::updateConnection()` passes a sparse `Query\Connection`, while `Connection::update()` passes a materialized `Connection`. Uninitialized typed properties can fail before SQL and patch versus replacement semantics are undefined. See DG-SPI-01 and shared DG-UPDATE-04. |
| `deleteSpecificConnections` | [untyped `int|int[]` input, `int` result](../src/Abstracts/Storage.php#L32) | Filters numeric IDs, deletes matching meta first and connection rows second, emits global and client-scoped before/after actions, and returns connection rows affected. | Invalid input throws `ConnectionWrongData`; nonnumeric members are silently dropped. SQL failures are not checked, the two deletes are not atomic, and an after hook can report success after a partial failure. See DG-SPI-03, DG-SPI-04 and DG-SPI-06. |
| `deleteByObjectID` | [untyped `int|int[]`, optional relation/direction flags, `int`](../src/Abstracts/Storage.php#L44) | Emits before hooks, resolves connection IDs for either/both endpoint sides, deletes meta then connections, emits after hooks only when IDs were found, and returns connection rows affected. It is also registered directly on WordPress `deleted_post`. | Both direction flags return `0` after the before hook; no match returns `0` without an after hook. SQL errors and partial deletion are not surfaced. The relation predicate is concrete SQL behavior rather than an abstract guarantee. See DG-SPI-03, DG-SPI-04 and DG-SPI-06. |
| `deleteDirectedConnections` | [implicitly nullable `from`/`to`, optional relation, `int`](../src/Abstracts/Storage.php#L56) | Emits before hooks, returns `0` for a missing endpoint or no matches, otherwise deletes meta then matching connection rows and emits after hooks. Duplicates are all selected. | Invalid/no-op/not-found are conflated; SQL failures and partial deletion are not surfaced. The implicitly nullable parameter declaration is compatibility-sensitive. See DG-SPI-03 and DG-SPI-04. |
| `findConnections` | [`Query\Connection -> ConnectionCollection`](../src/Abstracts/Storage.php#L58) | Treats nonzero ID as the priority selector plus optional relation; otherwise combines relation/from/to/both with `AND`; empty selectors return an empty collection without SQL. Hydrates metadata, places the originating `Client` into each item, and emits raw-query/result plus transformed-data actions. Ordering is intentionally unspecified. | Adapter read failure has no declared error contract. Client attachment is a hidden requirement needed by returned `Connection::update()`, and raw SQL/result hook payloads are `WPStorage`-specific. See DG-SPI-02, DG-SPI-03 and DG-SPI-06. |
| `addConnectionMeta` | [`int, MetaCollection -> void`](../src/Abstracts/Storage.php#L69); its PHPDoc incorrectly names `Query\MetaCollection` | Rejects empty collections and empty IDs with `ConnectionWrongData`; emits a before hook; inserts each item; emits an after hook containing collected DB errors; then throws `ConnectionWrongData` if any insert failed. | Earlier inserts survive a later failure. The after hook runs before the exception and does not mean commit success. Base `MetaCollection` in the PHP signature is wider than the PHPDoc. See DG-SPI-03, DG-SPI-04 and DG-SPI-06. |
| `removeConnectionMeta` | [`int, Query\MetaCollection`](../src/Abstracts/Storage.php#L74), with no declared return type | An empty collection deletes all meta for the connection; otherwise it deletes matching key/value predicates. It emits before/after actions including the concrete SQL and returns `wpdb::query()`'s `int|false`. | Empty ID throws `ConnectionWrongData`; DB `false` is returned rather than normalized. Selective input currently also encounters the separate `Query\Meta` autoload defect tracked by TEST-02F/CORE-07. See DG-SPI-03 and DG-SPI-06. |

### Security and data-integrity findings

- `deleteByObjectID()` and `deleteDirectedConnections()` interpolate `relation`
  directly into concrete SQL, unlike the prepared relation predicate in
  `findConnections()`. Direct SPI reachability makes this a real adapter
  hardening requirement for DB-03B-A even though supported domain/REST flows may
  constrain relation names.
- `removeConnectionMeta()` concatenates an `int`-typed ID and prepares metadata
  key/value predicates, but a DB failure can still become `false` and then `0`
  when [`Relation::removeConnectionMeta()`](../src/Relation.php#L108) casts the
  result. That is failure masking, not input injection.
- Raw SQL, raw rows and database error text can reach hooks, logs or exception
  messages. REL-02 and REST-00A must decide their public/debug exposure; a
  portable non-SQL adapter cannot be required to manufacture SQL payloads.
- Direct writes through `getStorage()` bypass relation/cardinality/entity
  validation. DG-M9 classifies that path as unsupported consumer mutation, but
  public visibility remains in v1 and should not be mistaken for a security
  boundary.
- Factory filters are executable WordPress plugin code and therefore already
  trusted at code-execution level. The relevant factory risk is that an
  incompatible class constructor runs before the factory return type rejects
  it, allowing side effects during a partially initialized `Client`.

### Concrete-only `WPStorage` surface

The following observable behavior is not declared by `Abstracts\Storage`:

- Construction requires exactly one `Client`, stores it through
  `ClientInterface`, derives two table names and may install schema through the
  `wpConnections/storage/installOnInit` filter.
- `get_connections_table()` and `get_meta_table()` expose physical table
  suffixes. Public consumers use both reconstructed names and the former getter,
  but a non-table adapter cannot meaningfully implement them.
- Private `install()` and protected `prepareIDs()` are implementation details,
  despite their effects being visible through retry and validation behavior.
- `getClient()` is supplied by a concrete trait; the abstract SPI neither
  declares a constructor nor requires client access.

This difference matters to a replacement: the factory imposes requirements
that the abstract class alone does not reveal. DG-SPI-05 owns that contract;
DG-SPI-07 owns the concrete table-introspection compatibility choice.

## The `updateConnection` mismatch

There is no PHP variance violation between the two declarations:
`Connection` inside the `iTRON\wpConnections\Abstracts` namespace and the fully
qualified `Abstracts\Connection` used by `WPStorage` are the same type.
`Query\Connection` extends that type, so PHP accepts the value passed by
[`Relation::updateConnection()`](../src/Relation.php#L99).

The mismatch is semantic:

1. [`Relation::updateConnection()`](../src/Relation.php#L99) accepts a
   `Query\Connection`, sets only `relation`, and forwards it directly.
2. REST builds that query from omitted/defaulted request fields; REST-00B is
   separately defining their patch/replace meaning.
3. [`WPStorage::updateConnection()`](../src/WPStorage.php#L396) directly reads
   `id`, `from`, `to`, `order`, `relation`, and `title` as if every property were
   initialized and the operation were a full replacement.
4. [`Connection::update()`](../src/Connection.php#L30) passes a materialized
   domain `Connection`, then separately replaces all metadata.

Reflection at the source snapshot confirms both declared parameters resolve to
`iTRON\wpConnections\Abstracts\Connection`, while a newly constructed query is
an instance of that type but has uninitialized `title` and `order` properties.

CORE-02 may enforce cardinality around updates, but it must not repair this SPI
contract incidentally. Approved DG-SPI-01/A is coordinated with REST-00B's
approved method and value-state gates. The separate approved `DG-UPDATE-04/A` is the canonical shared gate
for changed versus valid no-op versus not-found/storage-failure results.

## Factory replacement baseline

The current filter is
`wpConnections/factory/getStorage/class`. It receives the default
`WPStorage::class` and the `Client`, and is expected to return a class string.
The factory checks `class_exists()`, invokes `new $storageClass($client)`, and
relies on its own `Storage` return type. A missing class and a caught `TypeError`
are converted to `ClientRegisterFail`; other constructor failures escape.

Construction occurs early in [`Client::init()`](../src/Client.php#L129), before
logger, REST API, settings and relation collection initialization. Therefore a
replacement currently cannot safely assume a fully initialized `Client` even
though it receives that object. `WPStorage` itself performs table registration
and optional schema installation during construction.

No public replacement implementation was found by REL-00, but private
implementers remain possible. The absence of evidence does not permit changing
the filter's arguments, return shape, construction timing or error behavior
without DG-SPI-05 and conformance coverage in REL-02.

## Transaction capability required by DG-M7

DG-M7/A and DG-M9/A are already approved and impose outcomes, regardless of the
pending API mechanism:

- A compound domain mutation is all-or-nothing across connection and metadata
  writes.
- Capability is checked before the first mutation. An adapter that cannot meet
  the required atomic boundary returns an explicit stable error; it never falls
  back silently to best effort.
- The domain layer retains validation and invariant ownership. A transaction
  capability does not make direct storage writes supported consumer operations.
- Rollback must cover any thrown `Throwable`, not only expected domain
  exceptions, and the original failure must remain attributable.
- Success results and success hooks are not observable before commit. Rollback
  must not produce a false success notification.
- Create plus metadata, scalar update plus metadata replacement, and every
  connection-plus-metadata delete variant are compound boundaries.
- Schema creation/recovery may cause implicit commits on some engines. DB-00
  must determine supported engine behavior before DB-05 combines retry and data
  transactions.

The public capability shape and the owner of begin/commit/rollback are still
pending in DG-SPI-04.

## Hook and side-effect inventory

These are observed action/filter contracts for REL-02 to classify and test.
Raw SQL payloads are concrete-adapter details and are not automatically portable
SPI requirements.

| Operation/lifecycle | Current hook/filter and arguments | Current timing/gap |
| --- | --- | --- |
| Storage selection | `wpConnections/factory/getStorage/class($defaultClass, $client)` | Before the rest of `Client` initialization. |
| Default adapter init | `wpConnections/storage/installOnInit(false, $client)` | During `WPStorage` construction. Strict `true` triggers install. |
| Domain create | `wpConnections/relation/creating($query)`; `wpConnections/relation/created($connection)` | Around `Storage::createConnection`; `created` currently follows all successful adapter work but no transaction exists. |
| WP create retry | `iTRON/wpConnections/storage/createConnection/attempt($attempt)`; `.../attempt/result($result, $lastError)` | Concrete retry telemetry; inconsistent `iTRON/` prefix and no client argument. |
| Delete by IDs | `wpConnections/storage/deleteSpecificConnections($client, $inputIDs)` and client-scoped variant; `.../deletedSpecificConnections($client, $normalizedIDs, $rows)` and client-scoped variant | Before hook precedes validation. After hook reports connection-delete rows even if meta deletion failed. |
| Delete by object | `wpConnections/storage/deleteByObjectID($client, $objectIDs, $relation, $onlyFrom, $onlyTo)` and client-scoped variant; `.../deletedByObjectID($client, $resolvedIDs)` and variant | No after hook for invalid flags/no match; no failure distinction. |
| Directed delete | `wpConnections/storage/deleteDirectedConnections($client, $from, $to, $relation)` and client-scoped variant; `.../deletedDirectedConnections($client, $resolvedIDs)` and variant | No after hook for invalid endpoints/no match; no failure distinction. |
| Find | `wpConnections/storage/findConnections/dbQuery($sql, $rawRows, $client)`; `.../dbQuery/data($sql, $rawRows, $data, $serializedCollection)` | Only when SQL runs; leaks concrete query/result representation. The trailing origin Client is additive for legacy two-argument listeners and lets the singleton debug observer select the correct logger. |
| Add meta | `wpConnections/storage/addConnectionMeta/before($client, $id, $collection)`; `.../after($client, $id, $collection, $errors)` | `after` fires before collected errors are thrown and can follow partial insertion. |
| Remove meta | `wpConnections/storage/removeConnectionMeta/before($client, $id, $query, $sql)`; `.../after($client, $id, $query, $sql, $rowsOrFalse)` | Exposes SQL and reports raw failure value. |
| Post cascade | WordPress `deleted_post` invokes the adapter's `deleteByObjectID` directly | Bypasses a relation domain object; client isolation comes from the selected adapter/table. |

The three automatically logged public events require a valid originating
`Client`: the trailing third argument above for `findConnections/dbQuery`, and
the existing first argument for `removeConnectionMeta/after` and
`deletedSpecificConnections`. A conforming custom Storage emits the same
origin positions. Missing or invalid origin data does not suppress the public
action, but the library-owned automatic logger skips it rather than fanning it
out to unrelated Client loggers. Logging stays at priority 10 and retains the
pre-existing logged context; the Client added to the query action is routing
metadata only.

DG-SPI-06 decides commit-aware mutation hook semantics. REL-02 still owns the
complete public/internal classification and compatibility tests; SPI-01 does
not rename or remove hooks.

## Decision gates

### DG-SPI-01 — update payload at the domain/SPI boundary

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** the abstract type accepts either materialized or query subclasses,
but current callers require incompatible replacement and sparse-patch meanings.

- A: in v1 retain `updateConnection(Abstracts\Connection): bool`, but require
  the domain layer to load, merge, validate and pass one fully initialized
  materialized object. Query/REST omission semantics stop at the domain boundary.
- B: specify that adapters must accept sparse `Query\Connection` instances and
  implement omitted/null/falsy patch semantics themselves.
- C: introduce explicit patch/replace command DTOs and new SPI methods in the
  next major version, with a v1 adapter bridge.

**Recommendation:** A for the hardening release, then evaluate C for the next
major. It follows DG-M9, avoids duplicating invariants across adapters, and does
not change the current abstract signature.

**Compatibility impact:** A changes what `Relation::updateConnection()` sends
to custom adapters; an adapter that currently inspects `Query\Connection` needs
a compatibility fixture or bridge. B preserves the observed caller shape but
makes every adapter reproduce REST/domain logic. C is an explicit breaking SPI.

**Implementation consequences:** REST-00B, TEST-02D, CORE-04, DB-02, REST-02,
REL-02.
CORE-02 is explicitly not the implementation owner.

### DG-SPI-02 — create ID and read hydration ownership

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** the abstract create method returns an ID, yet `WPStorage` also
mutates the query's ID; reads attach `Client` inside the adapter. Neither hidden
side effect is declared, but current domain objects rely on both outcomes.

- A: adapters return the new ID and persistence data; the domain layer assigns
  ID/client context and constructs domain `Connection` objects.
- B: require every adapter to mutate the create query and attach the originating
  client to every hydrated connection, matching current `WPStorage` behavior.
- C: introduce adapter-neutral records/results and a mapper in the next major
  version, with a v1 bridge.

**Recommendation:** A in v1 without signature changes; C is the cleaner future
shape. Hidden mutation/context injection should not be an undocumented adapter
obligation.

**Compatibility impact:** A can affect custom adapters or consumers that observe
the mutated query immediately after a direct SPI call. B freezes WordPress-
specific object construction into every adapter. C is breaking.

**Implementation consequences:** DB-02, CORE-04, DB-05 and REL-02.

### DG-SPI-03 — non-update result and failure protocol

**Problem:** declared returns mix ID, row count, collection, void and an untyped
meta-delete result. `WPStorage` sometimes throws, sometimes returns `false`, and
sometimes converts an invalid/no-match/failure condition to `0` or an empty
collection.

- A: retain v1 signatures; define positive ID for create, collection for a
  successful read (including empty), nonnegative affected-connection counts for
  deletes, `void` for successful add-meta, and an observed integer count for
  remove-meta until a major-version return type can be added. Adapter failures
  throw a stable domain exception; `0`/empty are reserved for valid no-match or
  no-op states explicitly approved by the owning domain gate.
- B: retain raw current ambiguity, including `false`, `0`, empty and silent SQL
  failures as operation-specific outcomes.
- C: replace all mutation returns with a common result object in the current
  major version.

**Recommendation:** A. It avoids abstract signature breaks while making failure
distinguishable and testable. Exact delete no-op/not-found meanings remain with
DB-03A; update results remain solely with shared DG-UPDATE-04.

**Compatibility impact:** A converts previously silent/raw failures to
exceptions and may expose defective custom adapters. B cannot satisfy DG-M7.
C breaks implementers and consumers.

**Blocked/refined tasks:** DB-03A, DB-03B-B, DB-05, REST-00A, REST-03 and REL-02.

### DG-SPI-04 — transaction capability and orchestration shape

**Problem:** DG-M7 requires preflighted atomic compound operations, while the
abstract SPI exposes neither capability discovery nor transaction scope.

- A: add an optional transaction-capability interface with one guarded atomic
  callback/unit-of-work operation; the domain layer detects it before mutation
  and owns the compound operation inside that boundary.
- B: add begin/commit/rollback and support-query methods directly to
  `Abstracts\Storage`.
- C: add separate atomic compound create/update/delete methods to every adapter.

**Recommendation:** A. An optional capability does not force existing adapters
to implement new abstract methods merely to fail preflight, keeps transaction
mechanics adapter-owned, and keeps domain orchestration/invariants under DG-M9.

**Compatibility impact:** A adds a public optional SPI and causes non-capable
adapters to receive the approved pre-mutation error for compound writes. B is a
breaking abstract-class expansion. C duplicates domain semantics and expands
the SPI substantially.

**Blocked/refined tasks:** DB-00 must validate backend feasibility; DB-05 owns
implementation; REL-02 owns custom-adapter conformance and compatibility.

### DG-SPI-05 — factory replacement construction and failure contract

**Problem:** the filter promises only a name, while runtime also assumes a
class string, concrete `Storage` subtype, one-`Client` constructor and safe early
construction. Error normalization is incomplete and can misclassify constructor
`TypeError` as an inheritance failure.

- A: retain the class-string filter and its two callback arguments in v1;
  explicitly require a concrete `Storage` subtype constructible with the given
  `Client`, validate before construction, and normalize selection/construction
  failure to an attributable `ClientRegisterFail` contract.
- B: expand the existing filter to accept a `Storage` object or callable factory
  as well as a class string.
- C: introduce a dedicated storage-factory interface and new filter in the next
  major version, retaining the old filter through a migration period.

**Recommendation:** A for v1 and C for the next major. B makes one existing hook
polymorphic and harder to validate without a migration boundary.

**Compatibility impact:** A preserves observed hook arguments and class-string
implementers but can change exact error messages/exception chaining. B/C add
public input shapes; replacing the existing hook outright would be breaking.

**Blocked/refined tasks:** REL-02 and release compatibility documentation.

### DG-SPI-06 — mutation hook meaning across commit/rollback

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** several current “after”/“deleted” hooks fire with raw failure data
or after only the last non-atomic statement. Atomic DB-05 cannot treat these as
committed-success hooks without choosing a compatibility policy.

- A: preserve existing names and argument order in v1; classify “before” as an
  attempt notification and emit success-named “after”/“deleted” hooks only once
  after commit. Preserve failure attribution through exceptions/logging rather
  than a false success hook.
- B: preserve exact current timing even inside a transaction and document that
  after/deleted does not imply commit.
- C: add explicit transaction committed/rolled-back hooks, deprecating ambiguous
  hooks only in the next major version.

**Recommendation:** A for corrected success semantics, with C considered for a
future richer lifecycle. It satisfies DG-M7 while minimizing hook-name churn.

**Compatibility impact:** A changes timing and suppresses hooks on failure;
callbacks that relied on attempt-level timing may observe a difference. B
conflicts with the no-false-success requirement. C expands the public hook API.

**Blocked/refined tasks:** DB-05 and REL-02.

### DG-SPI-07 — legacy concrete storage introspection

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** public consumers reach `WPStorage` table names through
`getStorage()` or reconstruct them, but table names are not portable storage
SPI and direct writes are excluded by DG-M9.

- A: retain `Client::getStorage()` and the two `WPStorage` table getters in v1,
  document them as legacy concrete introspection, and provide high-level
  migration alternatives before any later deprecation.
- B: promote table-name getters to abstract SPI requirements.
- C: deprecate/remove the getters without a high-level maintenance/orphan-
  cleanup replacement.

**Recommendation:** A. It respects observed CF7 consumer coupling without
pretending every adapter has SQL tables. B makes non-table adapters artificial;
C breaks known maintenance/orphan-cleanup flows.

**Compatibility impact:** A postpones visibility reduction and requires
migration docs. B breaks existing custom adapters. C breaks observed public
consumers.

**Implementation consequences:** CORE-05/CORE-06, REL-02, DOC-01 and REL-03.

## Conformance-test contract

The test fixture should be an instrumented replacement derived from
`Abstracts\Storage`, plus a transaction-capable variant selected after
DG-SPI-04. It records ordered calls and arguments, returns configured outcomes,
and injects failures at each write boundary. It must not depend on WordPress
tables so the tests distinguish portable SPI obligations from `WPStorage`
behavior.

| Area | Required scenario and assertion | Primary owner |
| --- | --- | --- |
| Factory selection | Default class and exact `Client` reach the filter; a valid replacement is constructed once and becomes `Client::getStorage()`. Missing, incompatible and unconstructable replacements follow the DG-SPI-05 error contract without partially initialized client hooks. | REL-02 |
| Domain boundary | Invalid relation/cardinality/entity input performs zero adapter writes; valid input reaches one adapter only after common validation. A supported non-post entity strategy does not require storage-specific validation. | CORE-04 |
| Update payload | Omitted/null/falsy REST states are resolved before the SPI call according to REST-00B and DG-SPI-01/A. The recording adapter receives one fully initialized materialized shape, never sparse input or an accidental mixture. | TEST-02D / DB-02 / REST-02 |
| Update result | Changed, valid no-op, not-found and adapter failure remain distinguishable through the shared approved DG-UPDATE-04/A contract; the SPI and REST assertions use the same fixture outcomes. | CORE-04 / DB-02 / REST-02 |
| Create identity/hydration | The returned ID, query observability and client attachment follow DG-SPI-02; a returned domain connection can subsequently update through the same selected adapter. | DB-02 / REL-02 |
| Read | Empty success differs from adapter failure; ID priority and relation/from/to/both filtering keep DB-01 semantics; ordering remains unspecified until DG-API20-04. Returned duplicates/meta multiplicity survive adapter-neutral hydration. | REL-02; DB-01 is the behavior baseline |
| Delete results | ID, directed and object-side variants cover invalid input, no match, duplicates and affected-connection counts according to DB-03A/DG-SPI-03. No direct adapter error becomes a misleading `0`. | DB-03A / DB-03B-A / DB-03B-B |
| WP adapter safety | Relation, IDs and metadata selectors are parameterized in `WPStorage`; malformed direct-SPI input cannot broaden a delete, and database failure retains attributable error context without leaking it through REST by default. | DB-03B-A / DB-03B-B / REST-00A |
| Atomic create | Failure on any meta write rolls back connection and prior meta writes; unsupported capability fails before the first call; committed result and hooks occur once. | DB-05 |
| Atomic update | Scalar update plus metadata clear/add is one boundary. Failure restores all previous scalar/meta values and emits no committed-success hook. | DB-05 |
| Atomic delete | Failure between meta and connection deletion rolls back every selected ID for each delete variant; row-count semantics remain those approved by DB-03A. | DB-03B-B / DB-05 |
| Metadata | Duplicate keys and allowed falsy values survive add/read; selective and delete-all behavior is covered after CORE-07; partial add/remove failures follow DG-SPI-03/04. | DB-02 / DB-05 |
| Hooks | Global/client variants preserve accepted names, argument order/count and once-only behavior. Attempt, commit and rollback observations match DG-SPI-06. Raw SQL hooks are tested only for `WPStorage` unless REL-02 promotes them. | REL-02 / DB-05 |
| Legacy access | `getStorage()` stays callable in v1, but consumer documentation directs writes to the domain API. `WPStorage` table getter compatibility and an orphan-cleanup migration path follow DG-SPI-07. | REL-02 / DOC-01 / REL-03 |

## Downstream task refinements

### CORE-04

- Treat adapter call recording as the proof that entity validation occurs before
  all high-level writes and is shared by PHP and REST domain paths.
- Do not require a custom adapter to reproduce entity/cardinality rules.
- Include `Connection::update()` and `Relation::updateConnection()` through the
  approved DG-SPI-01/A and REST-00B materialized common update boundary.

### DB-05

- Depend on accepted DG-SPI-03, DG-SPI-04 and DG-SPI-06 in addition to DB-00,
  DB-02 and DB-03B-A.
- Preflight transaction capability before every compound write.
- Fault-inject after every statement, assert persisted state after rollback, and
  assert no success hook/result escaped before commit.
- Keep schema recovery outside a data transaction unless DB-00 proves the
  supported backend can preserve the required boundary.

### REL-02

- Build the replacement fixture against all eight abstract operations, not only
  factory construction.
- Freeze the existing storage-filter callback arguments before evaluating a
  future factory interface.
- Classify each low-level hook as portable SPI, `WPStorage`-specific telemetry,
  or internal; raw SQL cannot be a requirement for non-SQL adapters by default.
- Cover known direct `getStorage()` and table-introspection compatibility while
  still documenting domain operations as the supported mutation path.

## Residual risks

- No public custom storage implementation was found, but private adapters are
  invisible; every abstract signature or constructor change remains potentially
  breaking.
- Existing public consumers are pinned to older commits and may not exercise the
  same surface as current master.
- Current delete and metadata methods can expose partial state or misleading
  counts. This artifact records those defects; it does not authorize fixes
  before the result, transaction and hook gates are accepted.
- Approved DG-QMETA-01/A remains an independent Query metadata repair; SPI
  conformance must not conceal it inside DB-02.
- This task adds no production API, signature, factory behavior, hook behavior or
  test implementation.
