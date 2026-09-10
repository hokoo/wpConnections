# API-01: connection filters and related-entity contract

Status: proposed; research complete, human decisions pending

Date: 2026-09-10

Traceability: GitHub [issue #20](https://github.com/hokoo/wpConnections/issues/20),
[issue #21](https://github.com/hokoo/wpConnections/issues/21), API-01 in the
[library hardening plan](plans/02-library-hardening.md#api-01-исследовать-и-зафиксировать-contract-issue-20)

## Purpose and decision status

This document separates three concerns that the original issues combine:

1. **Connection selection** decides which stored connection rows match.
2. **Endpoint projection** decides which endpoint role or roles are targets.
3. **Entity filtering and representation** decides which target entities match
   and whether permission-safe entity data is added to the response.

The contract is decision-ready, not accepted. Every material public API choice
is listed as `DG-API20-*` below with alternatives and a recommendation. A
recommendation does not become normative until the repository owner records an
explicit decision. API-03, REST-06 and API-04 must not implement a pending gate.

## Sources and current behavior

- Issue #20 asks for the relation-list REST route to return the posts referenced
  by `from` or `to`, rather than forcing the consumer to make follow-up requests.
- Issue #21 asks for `from` and `to` filters on that same route. Neither issue
  defines combination, traversal, pagination, authorization or response-shape
  semantics.
- `GET /wp-connections/v1/client/{client}/relation/{relation}` currently calls
  `findConnections()` without a query and returns an unpaginated top-level array
  of connection items. The issue #20 example shows every item as
  `{ "data": { "id", "title", "relation", "from", "to", "order", "meta" } }`.
  See [`ClientRestApi::getRelation()`](../src/ClientRestApi.php#L50-L63).
- The PHP query object already exposes scalar `from`, `to` and `both` fields.
  The intended storage predicate is AND between supplied fields and OR between
  the two endpoint columns inside `both`. The known `both` placeholder defect is
  owned by DB-01. See [`Query\Connection`](../src/Query/Connection.php) and
  [`WPStorage::findConnections()`](../src/WPStorage.php#L274-L338).
- `ConnectionCollection::getPosts(string $direction)` is public and documented
  as returning `WP_Post[]` for `from` or `to`, but has no implementation. See
  [`ConnectionCollection`](../src/ConnectionCollection.php#L19-L26).
- The relation route checks a client callback capability, normally
  `manage_options`, but does not apply entity-specific REST preparation or
  visibility checks. See [`ClientRestApi::checkPermissions()`](../src/ClientRestApi.php#L155-L164)
  and [`Capabilities`](../src/Capabilities.php).
- Current storage does not define list ordering, count or pagination. Entity
  resolution must therefore not infer that current row order is a stable API.

The GitHub issues and their comments were re-read on 2026-09-10. Both were open
and had no comments at that time.

## Inherited constraints

These choices are already approved in the hardening plan and are not reopened:

- **DG-M1/C:** new mutations validate `WP_Post` existence/type by default;
  non-post entities require an explicit adapter/strategy.
- **DG-M2/C:** `relation.type` is deprecated metadata. It never chooses a
  selector, traversal direction or representation.
- **DG-M4/A:** a request that does not opt into new representation behavior keeps
  the v1 connection response shape. New entity representation is opt-in.
- **DG-M5/B:** only `Connection::load()` is covered by that deprecation decision.
  It does not decide the fate of `ConnectionCollection::getPosts()`.
- **DG-M9/A:** storage remains a persistence SPI. It selects endpoint IDs, but
  does not resolve entities or enforce current-user entity permissions.

## Contract model

### 1. Connection selection

The relation path supplies the relation and client boundary. Candidate query
parameters are positive scalar endpoint IDs:

| Parameter | Candidate predicate |
|---|---|
| `from=A` | `connection.from = A` |
| `to=B` | `connection.to = B` |
| `both=C` | `connection.from = C OR connection.to = C` |

The recommended combination rule is:

```text
(!from OR connection.from = from)
AND (!to OR connection.to = to)
AND (!both OR connection.from = both OR connection.to = both)
```

This keeps `both` as one incident-endpoint predicate while allowing exact pairs
such as `from=4&to=10`. Empty, zero, negative, non-integer and repeated scalar
values are validation errors rather than silently ignored filters. Multi-ID
selectors are deferred until there is a demonstrated use case.

This recommendation is pending **DG-API20-01**.

### 2. Endpoint projection

Projection occurs after selecting connections and never changes which rows are
stored. The recommended vocabulary is a separate `target` parameter:

| `target` | Projected endpoint roles |
|---|---|
| `from` | the `from` endpoint of every selected connection |
| `to` | the `to` endpoint of every selected connection |
| `both` | both endpoint roles |
| `opposite` | the endpoint opposite a single traversal anchor |

`opposite` is valid only when exactly one selector (`from`, `to` or `both`) is
present:

- `from=A&target=opposite` projects `to`.
- `to=B&target=opposite` projects `from`.
- `both=C&target=opposite` projects the non-`C` endpoint for each row, enabling
  bidirectional traversal without consulting deprecated `relation.type`.
- A self-connection `C -> C` has no distinct opposite endpoint. It keeps its
  connection row but contributes no `opposite` projection.

Absolute targets remain valid with combined selectors. An absent `target` means
no entity projection and preserves the connection-only behavior.

This recommendation is pending **DG-API20-02**.

### 3. Entity filtering and representation

The recommended opt-in request grammar is:

```text
GET /wp-connections/v1/client/{client}/relation/{relation}
    ?from=4
    &representation=expanded
    &target=to
    &entity[status]=publish
    &context=view
    &page=1
    &per_page=20
```

The layers are independent:

- `from`/`to`/`both` select connections.
- `target` projects endpoint roles.
- `entity[...]` filters the projected entities, not connection columns.
- `representation=expanded` asks for permission-safe prepared entities.
- `context` controls WordPress REST field visibility, not selection direction.

Unknown API-01 contract parameters or adapter-unsupported entity filters fail
validation (WordPress global REST parameters remain governed by WordPress).
They are never ignored, because an ignored access/status filter can expose a
broader result than the caller intended.

The proposed default WordPress post adapter initially supports an allowlist of
portable filters: `status`, `type`, `slug` and `search`. Filters on one entity
are ANDed; multiple values of one filter are ORed. With `target=both`, a
connection matches when at least one permitted projected entity matches all
filters; after the connection matches, both requested and permitted endpoint
roles may be represented. Filtering is completed before pagination and total
calculation.

Parameter names, the initial allowlist and target matching are pending
**DG-API20-03** and **DG-API20-05**.

## Response compatibility and representation

### Legacy/default mode

If `representation` is absent, the response remains the v1 top-level array of
connection items with numeric `from` and `to` IDs. No entity object, envelope or
runtime warning for deprecated `relation.type` is added. New connection filters
may reduce the array, but do not alter an individual item shape.

Requests without new selector, representation or pagination parameters remain
unbounded and keep the currently unspecified ordering. This avoids silently
truncating an existing consumer response.

### Recommended expanded mode

`representation=expanded` keeps each connection item and adds a side-keyed
`entities` member. Numeric IDs remain authoritative connection fields:

```json
[
  {
    "data": {
      "id": 1,
      "title": "",
      "relation": "relation-name",
      "from": 4,
      "to": 10,
      "order": 0,
      "meta": []
    },
    "entities": {
      "to": {
        "status": "resolved",
        "data": {
          "id": 10,
          "type": "post"
        }
      }
    }
  }
]
```

`data` inside a resolved entity is produced by the entity's registered REST
preparer for the requested context; it is not a raw `WP_Post`. The exact fields
therefore follow the registered entity type schema.

One response item is retained for each connection row, including allowed
duplicate connections. Entity lookup de-duplicates IDs internally, while the
side-keyed representation preserves connection order and endpoint roles. With
`target=both`, a self-connection may contain the same resolved entity in both
role keys; with `target=opposite`, it contains neither because there is no
distinct opposite.

A compound `included` envelope would reduce repeated payload, but changes the
top-level shape and makes connection-to-entity correlation harder. An entity-only
response loses connection ID, metadata and duplicate-row semantics. Both remain
credible alternatives in **DG-API20-03**.

## Ordering, pagination and totals

Pagination cannot become implicit for legacy requests. The recommended v1
addition is opt-in `page`/`per_page` behavior:

- If neither parameter is supplied, legacy mode is not truncated.
- Supplying either parameter enables pagination; the missing value defaults to
  `page=1` or `per_page=20`, with `per_page` capped at 100.
- A paginated or expanded request uses deterministic connection order
  `connection.order ASC, connection.id ASC`. Duplicate rows remain separate.
- `X-WP-Total` and `X-WP-TotalPages` follow WordPress collection conventions.
- Totals count the rows remaining after connection filters, batched entity
  eligibility checks and visibility rules. Full response preparation for the
  returned page happens after counting.
- Entity-specific ordering is out of scope for v1. It can be added only with an
  explicit `orderby` contract because it changes connection ordering.

This recommendation is pending **DG-API20-04**. REST-06 may deliver connection
selectors without pagination; API-04 owns the approved entity-aware pagination
pipeline unless the owner creates a separate implementation task.

## Missing endpoints, visibility and context

Strict validation prevents new invalid references after CORE-04, but legacy
rows can still point to deleted, missing or adapter-unavailable entities.

The recommended policy is:

1. The relation-route capability is checked first.
2. Each projected entity is authorized and prepared by its registered REST
   controller/adapter for `context=view`, `embed` or `edit`; `view` is default.
3. `edit` is accepted only when the current user has the corresponding entity
   permission. Client-level permission alone is insufficient.
4. Without entity filters, an unavailable projected entity does not remove its
   connection. Its side entry is `{ "status": "unavailable" }` and does not
   distinguish deleted, missing, unsupported or forbidden states.
5. With entity filters, unavailable entities never match. Filtering and
   authorization happen before pagination and totals so a page cannot contain
   post-pagination holes or leak hidden matches through counts.
6. No response or error reveals private fields, a post status, or why a specific
   entity is unavailable beyond the numeric endpoint ID already present in the
   authorized legacy connection payload.

Dropping unavailable rows always is simpler but changes connection cardinality.
Failing the whole collection lets one legacy row deny all results. Those are the
alternatives in **DG-API20-06**. The authorization and `context` boundary is a
separate pending choice in **DG-API20-07**.

The exact HTTP/body mapping for invalid request arguments remains owned by
REST-00A/REST-03. At minimum, invalid selector/target/filter/context inputs must
produce a WordPress REST validation error, while a valid query with no matches
returns HTTP 200 and an empty array.

## Resolver and adapter boundary

Resolution sits above storage:

```text
REST request
  -> connection selector (storage IDs only)
  -> endpoint projector
  -> adapter batch resolver + authorization + entity filters
  -> REST preparer
  -> connection/expanded representation
```

The default post adapter must:

- batch-resolve unique IDs grouped by entity/post type;
- enforce the relation-side type from DG-M1;
- authorize and prepare each result with the WordPress REST controller and
  requested context;
- translate its allowlisted entity filters into eligible endpoint IDs before
  connection pagination; and
- return a generic unavailable state for missing or forbidden endpoints.

A non-post adapter must explicitly declare an entity type, batch resolution,
filter schema, authorization and REST preparation. Unsupported filters are
errors. The exact shared adapter interface is coordinated with CORE-00/SPI-01;
API-01 does not make storage responsible for entity resolution or permissions.

## PHP API and `getPosts()`

The existing `getPosts(string $direction)` signature can only express an
absolute `from` or `to` projection and promises `WP_Post[]`. It cannot represent
`opposite`, unavailable slots, a non-post adapter, current-user context or
filter metadata.

The recommendation is to introduce an explicit batch resolver API with target
and resolution-result objects, then implement `getPosts('from'|'to')` as a
WordPress-post-only compatibility facade over it:

- preserve connection order and duplicate occurrences;
- omit unavailable posts because the documented `WP_Post[]` return cannot hold
  structured unavailable slots;
- reject unsupported direction values instead of using `relation.type`;
- keep REST permission checks in the REST resolver path rather than pretending
  a general PHP collection method has a current-user security boundary.

If REL-00 finds consumers that depend on the current `null` return, a documented
deprecation plus a new resolver is safer. Implementing all new semantics directly
inside `getPosts()` is the other alternative, but its signature is too narrow.
The owner decision is **DG-API20-08**.

## Performance and query-count contract

The recommendation is an asymptotic, testable budget rather than a brittle
absolute WordPress query count:

- Storage performs the connection selection and, when enabled, one count query.
- The projector collects unique endpoint IDs before resolution.
- Each `(adapter, entity type)` group receives exactly one `resolveMany()` call
  for a page, never one resolver call per connection.
- Entity eligibility for filters/visibility is obtained in batches before
  pagination; an adapter that cannot do that rejects the filter.
- Contract tests compare 1 and 50 connections and assert that library-controlled
  resolver calls do not grow linearly. Integration tests record the full
  `$wpdb->num_queries` delta, while allowing unrelated WordPress/plugin hooks to
  be reported separately rather than hidden.
- Repeated entity IDs may repeat in the payload by connection role, but are
  loaded and prepared once per compatible context.

A fixed absolute query ceiling can be added after API-03 records a stable
baseline. No budget would permit accidental N+1. The owner decision is
**DG-API20-09**.

## End-to-end examples

| Consumer intent | Candidate request | Selected rows | Projected entities |
|---|---|---|---|
| Connections leaving post 4 | `?from=4` | `from = 4` | none; legacy shape |
| `to` posts reached from 4 | `?from=4&representation=expanded&target=to` | `from = 4` | `to` role |
| Sources pointing at post 10 | `?to=10&representation=expanded&target=from` | `to = 10` | `from` role |
| Neighbours of post 4 in either direction | `?both=4&representation=expanded&target=opposite` | `from = 4 OR to = 4` | non-anchor role per row |
| Exact edge plus incident constraint | `?from=4&to=10&both=4` | all three predicates (AND); `both` is internally OR | none unless opted in |
| Published visible targets from 4 | `?from=4&representation=expanded&target=to&entity[status]=publish&context=view&page=1&per_page=20` | rows whose permitted `to` entity matches before paging | prepared `to` role |

These request names and outputs illustrate the recommended alternatives. They
are not implementation authority until their gates are approved.

## Decision gates

### DG-API20-01. Selector cardinality and combination

Question: how do `from`, `to` and `both` combine?

- A: allow exactly one selector and reject combinations.
- B: accept positive scalar IDs; AND distinct selectors, with OR only inside
  `both`; defer arrays.
- C: OR all supplied selectors.

Recommendation: **B**, because exact-pair queries remain possible and it matches
the intended PHP/storage predicate without turning extra filters into broader
results.

Status: pending repository-owner decision. Unblocks REST-06.

### DG-API20-02. Endpoint projection vocabulary

Question: how does a caller choose returned endpoint roles?

- A: absolute `from`, `to` and `both` only.
- B: absolute roles plus `opposite` for exactly one traversal anchor.
- C: infer direction from deprecated `relation.type`.

Recommendation: **B**. It covers bidirectional traversal explicitly; C conflicts
with DG-M2.

Status: pending repository-owner decision. Unblocks API-03/API-04.

### DG-API20-03. Opt-in REST representation

Question: how are resolved entities returned while default v1 stays unchanged?

- A: `representation=expanded`, retaining each connection item and adding a
  side-keyed `entities` member.
- B: a compound `{ connections, included, pagination }` envelope.
- C: a separate entity-only route/response.

Recommendation: **A** for correlation, duplicate semantics and smallest v1
change. B is preferable only if payload de-duplication outweighs shape cost.

Status: pending repository-owner decision. Unblocks API-04 and DOC-01.

### DG-API20-04. Pagination, ordering and totals

Question: what collection semantics apply to the new behavior?

- A: defer pagination and retain unspecified ordering everywhere.
- B: pagination remains opt-in; paginated/expanded requests use
  `order ASC, id ASC`, WordPress total headers, default 20 and maximum 100.
- C: introduce cursor pagination and a new envelope.

Recommendation: **B**. It is deterministic without truncating unchanged legacy
requests.

Status: pending repository-owner decision. Unblocks API-04; may create a
separate REST collection task if the owner wants pagination before expansion.

### DG-API20-05. Entity-filter namespace and matching

Question: how are entity fields filtered?

- A: no entity-level filters in the hardening release.
- B: validated `entity[...]` filters on projected targets; fields AND, values
  within one field OR, `target=both` matches any permitted endpoint.
- C: reuse unprefixed WordPress post query parameters.

Recommendation: **B**. A namespaced allowlist prevents collisions with
connection fields and allows adapters to declare capabilities.

Status: pending repository-owner decision. Unblocks API-03/API-04.

### DG-API20-06. Missing and inaccessible projected entities

Question: what happens when a selected row cannot yield a permitted entity?

- A: retain the connection and return a generic unavailable side; when entity
  filters are active, unavailable sides do not match before totals/pagination.
- B: always remove the whole connection before pagination.
- C: fail the entire request.

Recommendation: **A**. It preserves connection cardinality, avoids one stale row
denying the collection and does not disclose the unavailable reason.

Status: pending repository-owner/security decision. Unblocks API-03/API-04.

### DG-API20-07. Entity authorization and REST context

Question: which permission and preparation boundary applies to expanded
entities?

- A: the existing client-route capability authorizes every projected entity and
  its fields.
- B: require both the client-route capability and entity-specific authorization;
  prepare through the registered REST controller/adapter for explicit
  `view`/`embed`/`edit` context, defaulting to `view`.
- C: let each adapter return raw objects after only the client-route check.

Recommendation: **B**. A client may configure a capability weaker than
`manage_options`; neither that capability nor a custom adapter is sufficient
reason to bypass post visibility or REST field-context rules.

Status: pending repository-owner/security decision. Unblocks API-04.

### DG-API20-08. Fate of `ConnectionCollection::getPosts()`

Question: should the currently empty public method be implemented or retired?

- A: implement all resolver semantics directly in the existing method.
- B: add an explicit resolver and implement `getPosts('from'|'to')` as a narrow
  `WP_Post[]` compatibility facade.
- C: document/PHPDoc-deprecate it and provide only the new resolver.

Recommendation: **B**, subject to REL-00 consumer evidence. Choose C if a
meaningful consumer depends on its current `null` return.

Status: pending repository-owner decision after REL-00 evidence. Unblocks
API-03.

### DG-API20-09. Resolver query budget

Question: what performance promise prevents N+1?

- A: no enforceable budget.
- B: one batch call per adapter/entity-type group, de-duplicated IDs and
  non-linear-growth tests; set a fixed full-query ceiling after baseline.
- C: set an absolute `$wpdb` query ceiling before implementation evidence.

Recommendation: **B**. It is enforceable across WordPress environments without
normalizing per-item lookup.

Status: pending repository-owner decision. Unblocks API-03/API-04.

## Approval and implementation handoff

Record decisions in this document by replacing each gate status with the chosen
option, owner and date. Then update the main decision registry or link its
approval evidence before pulling dependent implementation work.

- **REST-06:** DG-API20-01; implement only connection selectors and their
  validation/combinations.
- **CORE-00/SPI-01:** align the entity adapter and storage boundaries with the
  inherited DG-M1/DG-M9 constraints.
- **API-03:** DG-API20-02, DG-API20-05, DG-API20-06, DG-API20-08 and
  DG-API20-09 plus the approved CORE-00 adapter contract; implement batch
  projection/resolution and the chosen `getPosts()` path.
- **API-04:** DG-API20-02—DG-API20-07 and DG-API20-09, after API-03, REST-03 and
  REST-06; implement opt-in prepared REST representation.
- **DOC-01:** document only the approved and tested REST contract.

## Verification matrix for downstream work

- No new query parameters: byte-equivalent logical v1 item shape and numeric
  endpoint IDs; no entity fields or implicit truncation.
- `from`, `to`, `both` and every approved combination: exact matching rows,
  invalid IDs rejected, no raw SQL warnings.
- Every approved target: correct role for forward, reverse, bidirectional and
  self-connection cases; `relation.type` has no effect.
- Duplicate connection rows: one response item per row in deterministic opted-in
  order; repeated entity ID resolved once.
- Missing/deleted/private/draft entity: no protected fields or reason leaked;
  context and totals follow the approved policy.
- Entity filter: evaluated against the projected, permitted entity before
  pagination; unknown/unsupported filters fail explicitly.
- Pagination boundaries: empty page, first/last page, total headers, stable tie
  breaker and no post-pagination filtering holes.
- Default post and custom non-post adapter: batch calls, REST preparation,
  authorization and unsupported-filter behavior.
- Query-count regression: 1 versus 50 connections does not produce linear
  library-controlled resolver calls.

## Residual risks

- Public repository search cannot prove absence of private consumers of the
  empty `getPosts()` method or accidental connection ordering.
- Pre-pagination entity authorization/filtering can be expensive without an
  adapter that can return eligible IDs in batches.
- Per-connection expansion can repeat large entity payloads; response-size
  limits may be needed after measurement even when database work is batched.
- WordPress REST controllers and third-party hooks can add queries or fields;
  integration tests must distinguish the library budget from environmental work.
- Strict future mutations do not repair legacy missing endpoints; the expanded
  representation must continue to handle them safely.
