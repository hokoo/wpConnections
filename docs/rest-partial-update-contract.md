# Connection update contract discovery

Status: partial approved decision contract; DG-UPDATE-01, DG-UPDATE-02,
DG-UPDATE-02R and DG-UPDATE-04 approved A, while DG-UPDATE-03 and
DG-UPDATE-05 remain pending

Date: 2026-09-10

Owner task: `REST-00B`

## Purpose

This document separates the update behaviors that are currently conflated by
the PHP domain API, storage SPI, and REST v1 routes. It defines the decisions
needed before `TEST-02D`, `DB-02`, `REST-02`, and the broader REST CRUD work can
be implemented without accidentally making an old defect part of the public
contract.

The five original material choices are recorded as `DG-UPDATE-01` through
`DG-UPDATE-05` in the main execution plan. CORE-04 review exposed one required
refinement, `DG-UPDATE-02R`, for same-value writes to legacy public endpoint
properties. The repository owner approved option A for DG-UPDATE-01,
DG-UPDATE-02, DG-UPDATE-02R and DG-UPDATE-04 on 2026-09-11; DG-UPDATE-03 and
DG-UPDATE-05 remain decision-ready rather than approved.

## Evidence and compatibility baseline

The current behavior is the result of two different designs being layered over
one another:

- `WP_REST_Server::EDITABLE` registers `POST`, `PUT`, and `PATCH` against one
  handler and one argument schema.
- That shared schema requires `from` and `to` for all three methods and supplies
  `order=0` when it is omitted. It does not declare `title`, although the
  checked-in Postman request sends it, and it does not declare `meta`.
- `ClientRestApi::obtainConnectionDataFromRequest()` copies every matching,
  non-null request parameter into a sparse `Query\Connection`, then always
  materializes a metadata collection. It does not retain field-presence
  information.
- `Relation::updateConnection()` forwards that query to storage after setting
  the route relation. `WPStorage::updateConnection()` writes all five scalar
  columns (`from`, `to`, `order`, `relation`, and `title`) and returns the
  boolean-coerced result of `$wpdb->update()`.
- Consequently, `PATCH` is not partial today: both endpoints are required,
  omitted order becomes `0`, and omitted title reaches storage as null. A
  direct handler call can additionally reach uninitialized typed properties.
- The checked-in Postman collection documents only `POST` for a connection
  update. `PUT` and `PATCH` are nevertheless live routes. Tests deliberately
  record this inventory drift rather than claiming that the collection defines
  their semantics.
- Before commit `2b7bacc` (the implementation for issue #22), query updates
  used `IQueryTrait::isUpdate()`: the default path updated only supplied
  values, while `setIsUpdate(false)` requested replacement. That commit removed
  the presence/mode mechanism from `Query\Connection` and changed storage to
  write the complete object, but it left the three REST methods registered.
  This is evidence that sparse update was an intended behavior, not evidence
  that today's destructive PATCH is a deliberate contract.
- Issue #13 established that integer zero is a meaningful order value. Commit
  `7f800b8` fixed the old `empty()` check specifically so an update could set
  `order` from a non-zero value to `0`.
- `Connection::update()` is a separate aggregate operation. Its PHPDoc says it
  overwrites fields and replaces all metadata. The implementation writes the
  scalar row, deletes every metadata row, and then adds the in-memory
  collection. An empty collection currently causes an exception only after
  deletion, so it cannot safely express a legitimate clear operation.
- The `/meta` subresource already describes method-specific behavior: `POST`
  appends, `PATCH` replaces values for supplied keys, and `PUT` replaces the
  complete metadata collection. Its implementation eventually invokes the
  same non-atomic `Connection::update()` path.
- The connections table permits null for `title` and `order`; endpoints and
  relation are non-null. The metadata table rejects null values. The object
  model currently permits nullable title/order and a metadata value of any
  type, so schema permission alone is not a sufficient public contract.
- A WordPress storage update returns an integer affected-row count or `false`.
  The declared `bool` return collapses unchanged existing row, missing row, and
  database failure into `false` unless higher layers distinguish them first.

Relevant repository evidence:

- `src/ClientRestApi.php`
- `src/Relation.php`
- `src/Connection.php`
- `src/Abstracts/Connection.php`
- `src/Abstracts/Storage.php`
- `src/WPStorage.php`
- `src/Query/Connection.php`
- `postman.json`
- `tests/iTRON/wpConnections/WP/ClientRestApiTest.php`
- GitHub issues [#13](https://github.com/hokoo/wpConnections/issues/13) and
  [#22](https://github.com/hokoo/wpConnections/issues/22)
- commits `7f800b8` and `2b7bacc`

## Update modes that the implementation must keep distinct

There are three externally visible operations, even though they currently
converge on one storage call.

| Operation | Available state | Approved role (`DG-UPDATE-01/A`) | Compatibility constraint |
|---|---|---|---|
| `Connection::update()` | A loaded concrete aggregate, including metadata | Replace the aggregate with its complete in-memory state | Existing method is `void` and documented as overwrite/replace |
| `Relation::updateConnection(Query\Connection)` | A query-shaped set of requested scalar fields | Sparse scalar update | Existing public return is `bool`; historical default behavior was partial |
| REST `/relation/{relation}/{id}` | Request method plus exact parameter presence | PATCH or replacement after loading/validating the target | Default v1 response shape and legacy documented POST must remain compatible |

These roles are accepted by DG-UPDATE-01/A. Restoring sparse behavior to the
public `Relation::updateConnection(Query\Connection)` path is a material
compatibility choice: it differs from the current post-`2b7bacc` complete write
even though it matches the older query contract. Rejected option B in
`DG-UPDATE-01` instead keeps that public PHP method replacement-oriented and
implements REST PATCH by loading/merging before the domain call; option C keeps
the currently conflated replacement behavior everywhere.

The storage SPI should persist already normalized domain intent. It must not
infer REST method semantics or independently reimplement relation invariants.
That boundary is shared with `SPI-01` and approved `DG-M9`.

## Approved PHP path matrix

This is the approved A contract from `DG-UPDATE-01`.

| PHP path | Recommended input meaning | Metadata meaning | Result |
|---|---|---|---|
| `Relation::updateConnection(Query\Connection)` | Sparse scalar update: initialized/supplied fields change and omitted fields preserve persisted state | No metadata mutation | `bool` according to `DG-UPDATE-04` |
| `Connection::update()` | Complete aggregate replacement after validating the object's effective relation/endpoints | Replace metadata exactly; empty collection clears | Existing `void`; changed and valid no-op return normally |

Under approved option A, both operations still reach storage as normalized,
fully initialized state under the update-payload decision owned by `SPI-01`;
“sparse” describes the public Relation input, not an obligation for every
storage adapter. The domain layer must load, merge and validate before the SPI
call. No new public parameter or return type is implied.

## Approved scalar state matrix

This matrix is the approved A contract from `DG-UPDATE-01`, `DG-UPDATE-02` and
the direct-property refinement `DG-UPDATE-02R`.
“Replacement” below means REST `PUT`, the legacy REST `POST` alias, and the
complete scalar portion of `Connection::update()`.

| Field/state | Sparse update / REST PATCH | Replacement / REST PUT and POST | Rationale |
|---|---|---|---|
| `from` omitted | Preserve | Invalid: required | A replacement must identify a valid complete directed edge |
| `to` omitted | Preserve | Invalid: required | Same as `from` |
| `from` or `to` null/`0` | Invalid, no mutation | Invalid, no mutation | Zero is an absence sentinel today and never a valid WordPress post ID |
| `from` or `to` positive integer | Replace after full relation/entity/cardinality validation | Persist after the same validation | Every high-level mutation must enforce `DG-M1`/`DG-M9` |
| `title` omitted | Preserve | Clear to null | This retains legacy replacement behavior while making PATCH safe |
| `title` explicit null | Clear to null | Clear to null | Null is a deliberate clear; it is distinct from omission in PATCH |
| `title` empty string | Persist empty string | Persist empty string | Empty string is a meaningful explicit value and must not be filtered by truthiness |
| `order` omitted | Preserve | Persist `0` | Matches the legacy route default and create default |
| `order` explicit `0` | Persist `0` | Persist `0` | Required by issue #13 |
| `order` null, negative, boolean, or empty string | Invalid, no mutation | Invalid, no mutation | No supported use case distinguishes null ordering from the canonical zero default |
| `id` or `relation` in body | Ignore as non-authoritative in v1 | Ignore as non-authoritative in v1 | Route selectors remain authoritative; strict unknown-field rejection would be a v2 choice |

REST validation may accept WordPress' normal integer wire representation, but
the normalized domain value must be an integer. It must not use `empty()` to
decide whether a supplied value exists. Validation must complete before any
storage statement.

For direct PHP calls, omission is a property-presence concept, not “value is
truthy.” If the current query object cannot preserve a required distinction,
the implementation must normalize the public input before storage; storage
must not guess. Whether that normalization reuses the existing query type or
introduces an internal command is an implementation detail unless it changes a
public signature.

Approved DG-UPDATE-02R/A retains the public `$query->from = ...` and
`$query->to = ...` syntax while tracking even a same-value assignment of `0` as
explicit input. An untouched endpoint still reads as `0`, `isset()` and
`property_exists()` retain their legacy answers, `exists_*()` remains false,
and `toArray()` materializes zero defaults. The implementation makes untouched
endpoint properties internally uninitialized so `__set()` can observe a first
direct write. Consequently, raw `get_object_vars()` and default
`json_encode()` omit untouched endpoints but include an explicitly assigned
zero. Those introspection shapes were not a documented query representation;
callers requiring a materialized array use `toArray()`.

## Approved REST method matrix

This matrix is the approved option A in `DG-UPDATE-01`.

| Method | Scalar mode | Required request fields | Successful v1 shape |
|---|---|---|---|
| `PATCH` | Sparse update | No scalar is required; an empty request is a valid no-op | HTTP 200, `{ "updated": true|false }` |
| `PUT` | Replacement | `from` and `to`; title/order use replacement defaults when omitted | HTTP 200, `{ "updated": true|false }` |
| `POST` | Compatibility alias for PUT replacement | Same as PUT | HTTP 200, `{ "updated": true|false }` |

Keeping `POST` as the replacement alias preserves the only connection-update
method documented in the current Postman collection. Removing it, silently
turning it into PATCH, or changing the response representation would violate
approved `DG-M4`. `PATCH` is the safe path for “change order but retain title.”

Under approved DG-UPDATE-04/A, an empty PATCH is a valid no-op and reports
`updated=false` after verifying the target exists. A missing positive ID is a
domain `ConnectionNotFound`, never a successful no-op.

## Proposed metadata boundary and matrix

Connection metadata is deliberately excluded from the scalar REST update
request. REST clients use the existing `/meta` subresource. In v1, a `meta`
parameter sent to the scalar endpoint remains non-authoritative and does not
mutate metadata; OpenAPI must not advertise it. Strictly rejecting legacy
unknown body fields can be considered for v2.

`Connection::update()` remains a full aggregate replacement: its current
metadata collection, including an empty collection, is the desired final
state. It therefore differs intentionally from a sparse query update.

The recommended `/meta` behavior is subject to boundary/operation
`DG-UPDATE-03` and response `DG-UPDATE-05`:

| Method/input | Persisted metadata result | Successful/no-op REST v1 response |
|---|---|---|
| `POST` omitted or `[]` | Valid no-op; preserve all existing rows | 200, exact legacy `{ "updated": <connection-object> }` wire shape |
| `POST` non-empty | Append every supplied key/value row; preserve duplicate keys and existing rows | 200, exact legacy `{ "updated": <connection-object> }` wire shape |
| `PATCH` omitted or `[]` | Valid no-op; preserve all existing rows | 200, exact legacy `{ "updated": <connection-object> }` wire shape |
| `PATCH` non-empty | Remove all existing rows for each supplied key, then add the supplied values; preserve other keys | 200, exact legacy `{ "updated": <connection-object> }` wire shape |
| `PUT` omitted or `[]` | Replace with an empty collection (clear all) | 200, exact legacy `{ "updated": <connection-object> }` wire shape |
| `PUT` non-empty | Replace the complete collection with supplied rows | 200, exact legacy `{ "updated": <connection-object> }` wire shape |
| Persisted empty key | Invalid before mutation | Mapped 4xx error; no success wrapper |
| Persisted null value | Invalid before mutation while the schema remains `NOT NULL` | Mapped 4xx error; no success wrapper |
| Persisted `0`, `"0"`, `false`, or empty string | Valid explicit values; never treated as omission | Same exact legacy success wrapper |

Under recommended `DG-UPDATE-05/A`, `<connection-object>` is the exact current
v1 serialization observed through full dispatch: public scalar connection
fields are nested below `updated`, while `meta` serializes as an object exposing
its `collectionType` rather than as the persisted metadata array. This shape is
not treated as good design, but approved `DG-M4` requires preserving the default
v1 representation. Changed and valid no-op responses therefore have the same
shape and are distinguished by the persisted state, not by a response boolean.

The current handler places a PHP `Connection` object under `updated`, and the
older direct tests inspect that object before wire serialization. Full-dispatch
tests must now freeze the exact wire shape so a later refactor cannot silently
canonicalize it. `DG-UPDATE-05/B` can add a canonical representation only as an
explicit opt-in while retaining this default. `DG-UPDATE-05/C` would reopen
approved `DG-M4` and is not executable unless that earlier decision changes.

A null metadata value already has selector-like meaning in selective deletion
(delete all values for that key), so accepting the same representation as a
persisted value would be ambiguous as well as incompatible with the schema.
Serialization type fidelity for non-string scalar values belongs to `DB-02`;
at minimum, duplicate occurrences and semantically distinct allowed falsy
values must not disappear because of truthiness checks.

All aggregate/meta replacement paths must become atomic under approved
`DG-M7`: validation first, then either the complete scalar/meta state commits or
the previous state remains. Empty metadata is a valid desired state, not an
exception after deletion.

## Approved result and failure contract

Approved `DG-UPDATE-04/A` preserves existing signatures while
removing ambiguity:

| Outcome | `Relation::updateConnection()` / storage SPI | `Connection::update()` | REST v1 |
|---|---|---|---|
| Existing target changed | `true` | returns normally | 200, `{ "updated": true }` |
| Existing target already equals desired state | `false` | returns normally | 200, `{ "updated": false }` |
| Target does not exist in the selected client/relation | Domain not-found exception | Domain not-found exception | Error, never `{ "updated": false }`; exact HTTP status/body remain pending `DG-RESTERR-01/02` |
| Invalid field, entity, relation, or invariant | Domain validation exception before mutation | Same | Mapped 4xx error |
| Storage failure | Storage/domain exception; rollback | Same | Mapped 5xx error |

The exact REST error body/status mapping remains owned by `REST-00A` and
`REST-03`; this contract only requires that missing and failed operations are
not represented as a successful no-op. Custom storage adapters must satisfy the
same distinction through the conformance contract in `SPI-01`.

Because `Connection::update()` is already `void`, it must not acquire a return
type in the hardening release. A valid no-op returns normally. A future major
version may expose a richer result object, but that is not required to make the
current interfaces unambiguous.

## Downstream acceptance matrix

After their listed gates are approved, implementation tasks must cover at least:

1. PATCH changes only title, only order, only `from`, and only `to` through full
   REST dispatch; every omitted scalar remains unchanged.
2. PATCH changes a non-zero order to `0` without losing title or endpoints.
3. PUT and legacy POST require valid endpoints and apply the approved title and
   order defaults.
4. Null, empty string, zero, and omitted are tested separately for every scalar
   where they are representable.
5. A body cannot move a connection into another route relation/client by
   supplying `id` or `relation`.
6. Every changed endpoint passes entity type, closurable, duplicatable, and
   cardinality validation before storage mutation.
7. Existing no-op, missing target, invalid target, and injected storage failure
   produce four distinguishable outcomes.
8. `Connection::update()` replaces metadata exactly; an empty collection clears
   it without an intermediate committed state.
9. `/meta` POST/PATCH/PUT cover omitted, empty, duplicate-key, allowed falsy,
   invalid null, changed/no-op response serialization, and injected-failure
   cases through full dispatch.
10. Direct handler tests may support narrow logic but cannot substitute for
    WordPress route defaults, argument validation, permissions, and response
    serialization.

Task ownership:

- `TEST-02D` and `REST-02`: sparse REST update regression and production fix.
- `DB-02`: scalar/meta persistence, zero, duplicate/falsy, and no-op outcomes.
- `CORE-02`/`CORE-04`: changed-edge invariants and entity validation.
- `REST-05`: metadata subresource method matrix.
- `DB-05`: transaction and rollback behavior.
- `REST-00A`/`REST-03`: HTTP status and error-body mapping.
- `SPI-01`: adapter signature, capability, and result conformance.

## Explicitly out of scope

- A new REST response representation or REST v2.
- Entity expansion/filtering from issue #20.
- Production code, route, schema, Postman, or test changes in `REST-00B`.
- Choosing the implementation shape of a new public command/result object. A
  new public type or a signature change would require its own approved API/SPI
  gate and is not implied by this contract.
