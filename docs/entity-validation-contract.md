# CORE-00: entity validation and extension contract

Status: proposed; design complete, human decisions pending

Date: 2026-09-10

Traceability: CORE-00 and CORE-04 in the
[library hardening plan](plans/02-library-hardening.md#core-00-спроектировать-entity-validation-strategy-и-rollout),
the [public compatibility inventory](compatibility-inventory.md), and critical
scenarios [`ENT-VAL-01` and `ENT-EXT-01`](test-quality.md#relation-definition-endpoint-validation-and-cardinality).

## Purpose and decision status

This document defines where endpoint validation belongs, inventories every
current high-level mutation entrypoint, and refines CORE-04 into verifiable
work. It does not approve a new public resolver API, new domain error, hook
timing, update merge rule, rollout policy or relation-identity rule.

The constraints inherited from DG-M1, DG-M3 and DG-M9 are normative. Every
remaining material choice is a pending `DG-ENT-*` gate below. Recommended
behavior and example signatures are proposals only: CORE-04 must remain
`waiting_dependency` until the repository owner records all five decisions in
the main plan.

This document is the canonical body for DG-ENT-01 through DG-ENT-05. The main
plan's linked registry is canonical for their recorded decision/status, owner
and date; a recommendation here never changes a registry status by itself.

## Evidence and inherited constraints

### Approved constraints

- **DG-M1/C:** supported domain mutation paths validate endpoint existence and
  exact `relation.from`/`relation.to` type by default. A non-post entity is valid
  only through an explicit extension strategy.
- **DG-M3/A:** numeric domain codes remain distinct from REST HTTP status. New
  endpoint failures need an explicit stable domain contract before REST maps
  them.
- **DG-M9/A:** invariants belong above storage. `Client::getStorage()` remains
  callable in the current major version for compatibility, but direct storage
  writes are not a supported consumer mutation flow and do not acquire domain
  validation guarantees.
- **DG-M2/C:** deprecated `relation.type` is serialized metadata only. It never
  changes the physical `from -> to` roles used for validation.

### REL-00 evidence

The public inventory found three independent consumers. Their observed relation
definitions use concrete WordPress post-type strings, including custom post
types and WooCommerce `product`, and all use the high-level relation API for
ordinary connection creation. This is evidence that exact post-type matching
must preserve registered CPTs; it is not evidence that every private consumer
uses posts.

REL-00 also found direct storage coupling in the CF7 integrations, including one
direct delete and WPStorage table-name inspection. That coupling must remain
source-compatible in 1.x, but it must not become a documented way to bypass
entity validation. No public factory-replacement use was found. Private plugins,
Composer installations and non-default branches remain unknown, so absence of a
public non-post resolver is not permission to close the extension boundary.

See the [immutable consumer snapshots and limits](compatibility-inventory.md#evidence-set)
and [SPI consequences](compatibility-inventory.md#spi-01).

## Current mutation inventory

No current endpoint-bearing create/update path calls `get_post()`, compares
`post_type`, or invokes an entity resolver before persistence.

| Entry point | Current input/delegation | Current checks | Gap and CORE-04 boundary |
| --- | --- | --- | --- |
| `Relation::createConnection(Query\Connection)` | Receives candidate `from`/`to`; sets the owning relation name; calls `Storage::createConnection()`; constructs a `Connection`. | Non-empty endpoints, closure, duplicate and cardinality checks run before the `relation/creating` action. | Validate both physical endpoint roles before storage. Ordering relative to required/closure/duplicate/cardinality checks and the lifecycle action is conditional on DG-ENT-03. |
| `Relation::updateConnection(Query\Connection)` | Overwrites query `relation` with the receiver's name and passes the query object to `Storage::updateConnection()`. | No connection lookup, endpoint validation, closure, duplicate or cardinality check. Omitted-field semantics are not defined. | Apply the DG-ENT-04 effective-state/validation breadth and DG-ENT-05 relation-identity rule, coordinated with REST-00B; required validation must finish before storage. |
| `Connection::update()` | Requires a non-zero connection ID; sends the mutable connection object to storage, then replaces metadata through separate calls. | Code `304` for an uninitialized object; no relation lookup or endpoint invariant. | Use the governing relation selected by DG-ENT-05 and the update breadth selected by DG-ENT-04 before the first storage/meta call. The caller's in-memory mutation cannot be rolled back; persisted row/meta must remain unchanged on rejection. |
| `Relation::removeConnectionMeta(Query\Connection)` | Passes connection ID and meta selectors directly to `Storage::removeConnectionMeta()`; the receiver relation is not forwarded. | Storage rejects an empty object ID; Relation coerces the rows-affected result to `int`. It does not load or validate endpoint entities. | This is cleanup, not an endpoint-bearing create/update. It must be able to remove metadata from a legacy connection whose endpoint is missing or wrong-type. Ownership, selector and result/error rules belong to DB-03A/DB-03B and REST-05, not CORE-04 entity resolution. |
| `Relation::detachConnections(Query\Connection)` | Selects the ID, `both`, directed pair, `from` or `to` branch and delegates to the corresponding storage delete method. | It does not resolve endpoint entities and currently converts caught `ConnectionWrongData` to `0`. | Do not require endpoint existence before delete: that would make dangling rows impossible to clean. DB-03A/DB-03B own selector, relation-isolation, result and orphan-meta behavior. |
| REST create | `ClientRestApi::createConnection()` converts request parameters to `Query\Connection`, then delegates to `Relation::createConnection()`. | WordPress route args require integer `from` and `to`; permission and argument validation occur before the handler. | Keep REST thin. PHP callers can bypass route validation, so the domain boundary remains authoritative. |
| REST connection update | The POST/PUT/PATCH `EDITABLE` route delegates to `Relation::updateConnection()`. | Route defaults/required fields and handler construction are incomplete; no entity check. | REST-00B defines omitted/null/falsy merge semantics. Apply the DG-ENT-04 validation breadth and DG-ENT-05 identity rule before storage; REST-00A/REST-03 coordinate error mapping. |
| REST metadata update | Handler loads a `Connection`, changes metadata and calls `Connection::update()`. | Existing endpoint IDs are carried by the loaded object but are not revalidated. | Whether unchanged endpoints on every update must be revalidated is pending DG-ENT-04; the path may not bypass the selected rule. |
| REST connection delete | `ClientRestApi::deleteConnection()` delegates a route connection ID to `Relation::detachConnections()`. | Permission/route ID checks precede the handler; no endpoint entity is resolved. A zero rows result becomes `ConnectionNotFound`. | Preserve deletion of a legacy/dangling connection without requiring either endpoint to exist. REST-03 and DB-03A/DB-03B own response/result and relation-isolation rules. |
| REST metadata delete | `ClientRestApi::deleteConnectionMeta()` delegates connection ID/meta selectors to `Relation::removeConnectionMeta()`. | Permission/route handling precedes the handler; no endpoint entity is resolved. | Preserve selective/all metadata cleanup even when the connection has a missing or wrong-type endpoint. REST-05 and DB-03A/DB-03B own selector/result semantics. |
| WordPress `deleted_post` cascade | `Client::init()` registers `Storage::deleteByObjectID()` directly on `deleted_post`. | The post is already deleted when the hook runs, so successful endpoint-existence validation is impossible. | This path must remove matching from/to rows and orphan metadata without an entity resolver. DB-04 owns hook lifecycle, client isolation and cascade evidence. |
| Direct `Abstracts\Storage`/`WPStorage` calls | Persistence SPI accepts query or connection data and writes tables. | Storage-specific shape/SQL checks only. | Remains outside the supported consumer domain contract under DG-M9. CORE-04 must not duplicate resolver logic in WPStorage or require custom storage adapters to resolve entities. |

The endpoint invariant applies to high-level operations that create or update a
persisted endpoint pair. Explicit delete, metadata-delete and post-deletion
cascade paths are intentionally cleanup operations: requiring a currently
resolvable endpoint would prevent removal of exactly the legacy, dangling and
orphan state that those paths must clean. They still require their own selector,
ownership, result and atomicity contracts; exclusion from entity validation is
not an unrestricted storage bypass.

Relevant source: [`Relation`](../src/Relation.php),
[`Connection`](../src/Connection.php),
[`Client`](../src/Client.php),
[`ClientRestApi`](../src/ClientRestApi.php), and
[`Abstracts\Storage`](../src/Abstracts/Storage.php) with the default
[`WPStorage`](../src/WPStorage.php).

## Required validation model

The following layers are fixed by approved decisions; class names and public
extension shapes are not:

```text
PHP caller or REST handler
  -> identify candidate relation/effective state under approved gates
  -> run endpoint validation and existing invariants in DG-ENT-03 precedence
  -> emit or suppress lifecycle hooks under DG-ENT-03
  -> after required validation succeeds, call storage SPI
```

For relation `page -> post`, the candidate in `connection.from` is always
validated as `page` and `connection.to` as `post`. Deprecated `relation.type`
cannot swap, omit or broaden either role.

The minimum no-mutation guarantee is independent of the pending gates:

- endpoint validation finishes before the first storage write;
- a rejected create leaves no connection or metadata row;
- a rejected update leaves the persisted connection and metadata byte-for-byte
  logically unchanged;
- a REST rejection delegates to the same domain result and never retries through
  direct storage;
- validation may read the relation, connection and entity stores, but may not
  perform repair or deletion as a side effect.

Only the validation-before-write/no-persisted-mutation guarantee above is
unconditional. Which validation or invariant failure wins, the order of
physical sides, and whether a pre-mutation lifecycle action is emitted for a
rejected candidate are pending public choices in DG-ENT-03.

### Endpoint matrix

These outcomes follow DG-M1. Exact exception classes/codes, post-status
eligibility and update post-state rules are pending gates.

| Candidate for one physical side | Expected type | Required domain outcome before storage |
| --- | --- | --- |
| Endpoint omitted or `0` on create | relation-side type | Reject as missing required input; preserve the existing `MissingParameters` compatibility path unless DG-ENT-03 approves a replacement. |
| Negative or otherwise invalid ID supplied through PHP | relation-side type | Reject as invalid endpoint input; do not coerce it to another entity. |
| Positive ID has no resolvable entity or was hard-deleted | relation-side type | Reject as missing/deleted entity. Never create a dangling row. |
| Existing `WP_Post` has a different `post_type` | relation-side type | Reject as wrong type and identify the physical `from` or `to` side. |
| Existing `WP_Post` has exactly the expected `post_type` | relation-side type | Entity validation passes; other relation invariants still decide the mutation. |
| Expected type is not handled by the built-in post resolver and no extension claims it | custom type | Reject as unsupported entity type; never fall back to positive-ID-only acceptance. |
| Exactly one registered extension handles the expected type and accepts the ID | custom type | Entity validation passes without changing storage SPI. |
| Extension reports missing, mismatch or operational failure | custom type | Reject before storage using the approved domain error mapping; do not silently fall through to another result. |

Both sides are required to pass. A valid `from` never compensates for an invalid
`to`, or vice versa. Self-connections still validate the ID independently
against both declared side types; which failure is reported relative to the
closure invariant follows DG-ENT-03.

### Default WordPress post resolution

The narrow default algorithm proposed in DG-ENT-01 is:

1. require a positive integer endpoint ID;
2. call `get_post($id)` in the current site context;
3. require a `WP_Post` result;
4. compare `$post->post_type` to the exact relation-side type with no aliasing;
5. return validation facts only; authorization and REST representation remain
   outside mutation validation.

This supports ordinary posts and registered CPTs used by REL-00 consumers. It
does not use current-user visibility, publication status or the REST controller
as a persistence invariant. The treatment of `trash` and other stored statuses
is intentionally pending DG-ENT-01. Multisite cross-blog resolution is out of
scope: the resolver uses the current WordPress site unless a separately approved
non-post/multisite adapter says otherwise.

## Non-post extension lifecycle

DG-M1 requires an explicit non-post strategy, but does not choose its public PHP
shape. DG-ENT-02 compares the credible options. The recommended narrow contract
would have the following lifecycle if option A is approved:

1. A resolver is registered on one `Client` during application bootstrap and
   before the client's first connection mutation.
2. It declares the exact entity-type strings it supports. A type claimed by two
   resolvers, or by both a custom resolver and the built-in WordPress-post
   resolver, is a deterministic registration error rather than order-dependent
   first/last-wins behavior.
3. For each mutation, the domain validator asks the resolver for a structured
   `accepted`, `missing`, `wrong_type` or `failed` result. A boolean is
   insufficient because it cannot support stable error behavior.
4. Resolver registration and lookup are client-scoped. No resolver or cached
   result leaks between clients or requests.
5. Validation has no cross-mutation cache. A resolver may cache internally for
   one operation, but deletion between operations must be observable.
6. Resolver exceptions are converted to the approved domain failure before
   storage and retain the previous exception for logging; they are never treated
   as an accepted or missing entity.

Candidate signatures, included only to make the gate concrete, are:

```php
interface EntityResolverInterface
{
    public function supports(string $entityType): bool;
    public function resolve(int $entityId, string $entityType): EntityResolution;
}

$client->registerEntityResolver($resolver);
```

Names, signatures, the result object and late-registration behavior are not
public commitments until DG-ENT-02 is approved. This mutation resolver is
deliberately narrower than the batch filtering, authorization and REST
preparation adapter proposed by API-01. API-03 may compose the approved resolver
with a richer adapter; CORE-04 must not silently freeze API-01's pending public
surface.

## Update post-state and rollout

### Effective update state

What entity validation inspects on an update is conditional on DG-ENT-04. A
full-state option must first assemble the effective persisted state; a
transition option may choose narrower validation only by explicitly reopening
the strict DG-M1 guarantee. Relation ownership is a separate DG-ENT-05 choice.
REST omission/replace semantics remain owned by REST-00B.

| Path | State available now | State needed for validation |
| --- | --- | --- |
| `Relation::updateConnection(Query\Connection)` | ID plus an ambiguously complete/partial query; relation is forced from the receiver. | The state selected by DG-ENT-04 after applying REST-00B-equivalent omission semantics; governing relation comes from DG-ENT-05. |
| `Connection::update()` | Mutable object normally loaded from storage, but callers can construct or alter it. | The persisted identity and candidate state required by DG-ENT-04; governing relation comes from DG-ENT-05. |
| REST POST/PUT/PATCH update | Route parameters and defaults may erase omission information. | REST-00B's approved effective state, then the DG-ENT-04 validation breadth through the PHP domain path. |
| REST metadata update | Loaded endpoints are unchanged by the handler. | Complete existing endpoints under DG-ENT-04/A, or the explicitly approved transition behavior if DG-M1 is reopened. |

CORE-04 must not change public storage parameter types as a side effect. SPI-01
owns the `Abstracts\Storage::updateConnection()` implementer contract; CORE-02
owns cardinality on the effective endpoint pair; REST-00B owns partial-field
semantics. The validator belongs before that existing storage boundary.

### Legacy rows and imports

No approved decision authorizes an automatic rewrite or deletion of existing
rows. All rollout options therefore share these rules:

- reads and explicit deletes do not become entity-validation migrations;
- library/client initialization performs no table scan;
- a missing or wrong-type legacy endpoint remains visible through existing read
  APIs until an explicitly authorized repair/delete operation handles it;
- `Relation::detachConnections()`, `Relation::removeConnectionMeta()`, their
  REST delegates and `deleted_post` cleanup do not require endpoint resolution;
  they must remain able to remove legacy/dangling connection and orphan-meta
  state under the separate DB-03A/DB-03B/DB-04/REST-05 contracts;
- direct storage access stays source-compatible in 1.x but is neither a
  sanctioned import bypass nor covered by domain invariant guarantees;
- bulk/import code that uses Relation or Connection APIs follows the same
  validator as an individual mutation;
- non-post importers register the approved client-scoped resolver before their
  first mutation;
- release notes must provide a read-only preflight inventory of missing,
  wrong-type and unsupported-type rows. Automated repair, destructive cleanup
  and a privileged bypass require separate scope and approval.

DG-ENT-04 decides the effective-state breadth and rollout. DG-ENT-05 separately
decides which relation owns validation and whether identity may change. Options
that narrow or phase strict update validation are not compatible refinements of
DG-M1: they require the owner to reopen that approved decision. No option in
either gate permits a rejected update to partially mutate the persisted row or
metadata.

## Decision gates

<a id="dg-ent-01"></a>

### DG-ENT-01. Which stored `WP_Post` states are valid endpoints?

Question: after `get_post()` returns a post with the exact relation-side
`post_type`, should publication lifecycle state affect connection validity?

- A: any extant `WP_Post` with the exact type is valid, including private,
  draft and trashed rows; only an absent/hard-deleted row fails.
- B: reject trash and WordPress internal states, but accept other statuses.
- C: require a configurable status allowlist per relation/client.

Recommendation: **A**. DG-M1 approved existence and type, not visibility or
publication policy. Authorization belongs to REST/entity representation, and a
status rule would unexpectedly break editorial workflows. DB-04 can continue
to own actual deletion/cascade behavior.

Compatibility impact: A adds no status-sensitive rejection but permits a
connection to a trashed post until it is hard-deleted. B/C create new mutation
failures and require relation configuration/migration rules.

Status: pending repository-owner decision.

Blocks: CORE-04; the chosen lifecycle meaning refines DB-04 and documentation.

Decision required: choose A, B or C and, for B/C, enumerate the accepted and
rejected WordPress statuses.

<a id="dg-ent-02"></a>

### DG-ENT-02. What is the public non-post resolver extension?

Question: how does a client declare existence/type validation for a non-post
entity without moving the invariant into storage?

- A: add a narrow typed, client-scoped `EntityResolverInterface` registry with
  structured results, unique type ownership and registration before mutation.
- B: add a client-scoped WordPress filter returning a documented tri-state or
  result object for every endpoint resolution.
- C: use one Factory-selected resolver class for the whole client and combine
  mutation validation with API-01's broader representation adapter.

Recommendation: **A**. It is deterministic, independently testable and does not
force a mutation validator to own REST authorization/filtering. B matches
existing WordPress style but callback order and malformed return values expand
the failure surface. C couples two different contracts and makes composition of
post and non-post types harder.

Compatibility impact: every option adds public extension surface. A adds a
Client registration method/interface but leaves existing constructors, factory
filters and storage adapters unchanged. B adds a permanent hook contract. C
expands the Factory SPI and couples CORE-04 to pending API-01 gates.

Status: pending repository-owner decision.

Blocks: CORE-04 and ENT-EXT-01 evidence; API-03 must align rather than invent a
second incompatible resolver.

Decision required: choose A, B or C and approve registration timing, duplicate
type ownership, structured result states and client isolation semantics.

<a id="dg-ent-03"></a>

### DG-ENT-03. What errors and hook precedence represent validation failure?

Question: how are invalid IDs, missing entities, type/relation-identity
mismatches, unsupported types and resolver failures exposed relative to current
relation invariants and lifecycle hooks?

- A: add domain `ConnectionWrongData` subtypes/codes `305` invalid ID, `306`
  missing entity, `307` type mismatch, `308` unsupported type and `309` resolver
  failure, plus `310` relation-identity mismatch when DG-ENT-05/A rejects a
  move; validate identity, then `from`, then `to`, before closure, duplicate and
  cardinality. Emit `relation/creating` only after all checks accept.
- B: reuse generic `ConnectionWrongData` code `300` with stable messages and
  preserve the current closure/duplicate/cardinality checks before entity
  resolution; entity validation still finishes before storage and rejected
  candidates do not emit `relation/creating`.
- C: keep required domain validation before storage, but expose WordPress
  `WP_Error` distinctions from REST only while leaving PHP failures generic.
  Preserve the current pre-write hook position after existing relation checks,
  but allow the hook before endpoint validation, so an endpoint rejection may
  have emitted it without persisting data.
  This collapses the approved domain/HTTP separation and therefore requires the
  owner to reopen DG-M3/A before it can be selected.

Recommendation: **A**. Stable reasons are actionable for PHP and REST callers,
early validation avoids persistence reads for impossible endpoint candidates,
and current `creating` already runs only after existing checks. The ordering and
hook behavior are part of this recommendation, not an unconditional rule.

Compatibility impact: A adds public exception classes/codes and changes which
failure wins when an invalid entity also violates closure/duplicate/cardinality.
B changes fewer types but leaves machine handling less specific. C cannot be
implemented under the recorded DG-M3/A decision. Existing codes `301`-`304` and
`MissingParameters` code `4` are not renumbered by A/B; exact HTTP mapping
remains a separate REST-00A decision.

Status: pending repository-owner decision.

Blocks: CORE-04, REST-03 production mapping and DOC-01; lifecycle-hook evidence
must be coordinated with REL-02. REST-00A may complete decision-ready exception
inventory/mapping alternatives while this gate is pending, then refine its
recommended mapping after the owner decides DG-ENT-03.

Decision required: choose A, B or C and explicitly approve code allocation,
side order, precedence and whether rejected validation emits any lifecycle hook.

<a id="dg-ent-04"></a>

### DG-ENT-04. Which update post-state and rollout policy applies in 1.x?

Question: which effective endpoint state is validated on update, and how is
strict enforcement introduced for legacy rows in 1.x?

- A: assemble the complete effective post-state using the approved REST-00B/PHP
  omission semantics and validate both endpoints on every Relation or
  Connection update. Legacy-invalid rows remain readable/deletable but cannot be
  updated until repaired; enforcement starts when CORE-04 ships.
- B: during a bounded 1.x transition validate only an endpoint explicitly
  changed by the caller, allowing title/order/meta updates to retain an invalid
  legacy endpoint. This weakens strict validation on every high-level mutation
  and requires the owner to reopen DG-M1/C and approve a sunset release.
- C: introduce an opt-in/compatibility flag and warning phase before strict
  full-state validation becomes the default in a later release. This postpones
  DG-M1/C and likewise requires that approved gate to be reopened with an exact
  default-switch release and behavior for callers that never opt in.

Recommendation: **A**. It is the only option currently compatible with the
approved DG-M1/DG-M9 guarantee for every high-level mutation path and prevents
metadata-only updates from perpetuating invalid state. The release must provide
preflight guidance because this can make legacy-invalid rows temporarily
read-only.

Compatibility impact: A adds a lookup to update paths and rejects updates of
stale legacy rows. B/C reduce immediate disruption but are unavailable without
changing DG-M1 and add temporal/configuration semantics. Relation mutability is
not decided here; DG-ENT-05 owns it. None changes direct-storage source
compatibility, and none promises safety for direct writes.

Status: pending repository-owner decision.

Blocks: CORE-04; REST-00B/REST-02 and DB-02 must use the same effective-state
rule, while REL-03 owns release/preflight communication.

Decision required: choose A, or explicitly reopen DG-M1/C before selecting B/C;
also decide whether a 1.x repair/bypass API is explicitly in scope or deferred.

<a id="dg-ent-05"></a>

### DG-ENT-05. Is a connection's owning relation immutable?

Question: when `Relation::updateConnection()` or a mutable `Connection` object
names a relation different from the persisted row, which relation owns the
updated connection and its endpoint/invariant validation?

- A: persisted relation identity is immutable. The receiver of
  `Relation::updateConnection()` must match the stored row, and mutation of
  `Connection::$relation` is rejected before storage using the stable
  DG-ENT-03 relation-identity failure. Validate endpoints and relation
  invariants against the persisted relation.
- B: relation identity is mutable in 1.x. A target relation must exist, and the
  complete candidate endpoints must pass entity, closure, duplicate and
  cardinality validation against that target before one atomic update moves the
  row.
- C: preserve validated relation moves for 1.x compatibility as in B, but mark
  them deprecated and make relation immutable in 2.0; release notes and runtime
  behavior must identify the transition without changing current signatures.

Recommendation: **A**. Relation is the owner of physical from/to types and
cardinality, while the public API exposes no explicit move operation. Rejecting
an incidental public-property change is safer than hiding a cross-relation move
inside a general update. A dedicated future move API can define atomicity and
conflict handling explicitly.

Compatibility impact: A rejects `Connection::update()` or cross-receiver
Relation updates that currently can rewrite the relation column, so private
consumers may need to recreate a connection deliberately. B preserves that
capability but expands update validation and conflict behavior. C preserves it
temporarily while adding a major-version transition commitment. Every option
continues to enforce DG-M1 endpoint validation and DG-M9 domain ownership before
storage.

Status: pending repository-owner decision.

Blocks: CORE-04; REST-00B/REST-02 and DB-02 must use the same identity rule,
CORE-02 update invariants need matching evidence, and REL-03 owns compatibility
communication.

Decision required: choose A, B or C and, for B/C, approve target-relation
selection, atomic move/conflict semantics and the 2.0 transition if applicable.

## CORE-04 implementation refinement

CORE-04 remains `waiting_dependency`. It becomes `todo` only after DG-ENT-01
through DG-ENT-05 and any material follow-up gate from REST-00B are approved.
CORE-02 must provide the shared create/update cardinality path; SPI-01 is a
cross-review input but may not move entity resolution into storage.

### Implementation scope after approval

- Add one internal validation coordinator invoked by
  `Relation::createConnection()`, `Relation::updateConnection()` and
  `Connection::update()` before persistence.
- Implement the selected built-in WordPress resolver and approved non-post
  extension without changing `Abstracts\Storage` signatures.
- Preserve physical role/type mapping and deprecated `relation.type` no-op
  behavior.
- Implement the approved error, precedence, hook, effective-state and relation
  identity rules.
- Add migration/release documentation for the approved rollout; do not add
  automatic repair or a bypass unless DG-ENT-04 explicitly authorizes it.
- Do not add endpoint resolution to `Relation::detachConnections()`,
  `Relation::removeConnectionMeta()`, their REST delegates or the `deleted_post`
  cascade. Add boundary evidence that these cleanup paths can remove
  legacy/dangling state; DB-03A/DB-03B/DB-04/REST-05 retain detailed ownership.
- Keep REST handlers as delegates. Error-to-HTTP serialization belongs to
  REST-00A/REST-03.

### Exact ENT-VAL-01 evidence

Add WordPress integration coverage, suggested as
`tests/iTRON/wpConnections/WP/EntityValidationTest.php`, with stable filters for:

1. `Relation::createConnection()` accepts matching `page -> post` endpoints and
   registered CPT endpoints.
2. Each physical side independently rejects `0`/invalid, hard-deleted and
   wrong-post-type IDs with the approved exception/code and side context.
3. Both-invalid input follows the approved canonical side/aggregation rule.
4. Self-connection with different declared side types follows the approved
   entity-versus-closure precedence.
5. `Relation::updateConnection()` validates the DG-ENT-04 effective state for
   unchanged, from-changed and to-changed cases using the DG-ENT-05 governing
   relation.
6. `Connection::update()` cannot bypass the same matrix by mutating public
   properties; relation mutation follows the approved DG-ENT-05 outcome.
7. A rejected create persists no connection/meta; a rejected update preserves
   the original row, endpoints, title, order and meta and emits hooks according
   to DG-ENT-03.
8. REST create/update representative failures reach the domain validator through
   full `WP_REST_Server` dispatch after REST-00B/REST-03, use the approved HTTP
   mapping, and cause no mutation. CORE-04 supplies the domain fixture/assertion;
   REST-03 owns final response-shape evidence.
9. Reads of a legacy dangling row do not trigger implicit repair. Explicit
   `detachConnections()`/`removeConnectionMeta()`, REST delete delegates and the
   `deleted_post` cascade do not invoke endpoint resolution and remain capable
   of cleaning dangling connection/orphan-meta state; detailed delete results
   and cascade coverage remain with DB-03A/DB-03B/DB-04/REST-05.

Map these tests explicitly to `ENT-VAL-01`, plus affected `CARD-MUT-01`,
`ERR-CODE-01`, `REST-ERROR-01` and `HOOK-CONTRACT-01` where the test actually
asserts the complete scenario expectation.

### Exact ENT-EXT-01 evidence

Add a deterministic fake non-post resolver and cover:

1. accepted non-post IDs on both physical roles;
2. resolver-reported missing and wrong-type results;
3. an unsupported entity type with no resolver;
4. a resolver exception/failure result and preserved no-mutation state;
5. duplicate type claims and registration after the approved lifecycle cutoff;
6. two clients with different resolver sets and no cross-client result/cache
   leakage;
7. a custom resolver cannot weaken matching `WP_Post` validation or claim a
   built-in post type under the approved conflict rule;
8. a custom storage test double receives a write only after domain validation,
   proving that storage implementers do not own the entity invariant.

Map the complete set to `ENT-EXT-01`, `CLIENT-ISO-01` and `FACTORY-EXT-01` only
when each registry expectation is independently asserted. A passing resolver
unit test alone is not ENT-EXT-01 evidence.

### Definition of done for CORE-04

- Every approved entrypoint and matrix row has targeted red/green evidence.
- Domain rejection precedes storage and leaves persisted state unchanged.
- Default post and non-post extension behavior match the approved gates.
- PHP and REST use the same domain result; REST does not reimplement entity
  rules.
- Full unit/integration, reverse/repeat, seeded isolation, coverage, PHPCS and
  supported compatibility lanes pass.
- Critical scenario mapping and release/preflight notes are recorded without
  claiming incomplete tests as evidence.

## Verification of this design artifact

The CORE-00 review must verify:

- every source entrypoint above still matches the linked implementation;
- all five gates remain explicitly pending and appear in the main registry;
- CORE-04 remains waiting and no public signature is presented as approved;
- `ENT-VAL-01` and `ENT-EXT-01` expectations remain canonical in
  `docs/test-quality.md`;
- relative links resolve and task/gate IDs are unique;
- the diff contains no production, test, workflow, dependency or secret change;
- the unchanged `master` baseline remains unit `6/14`, integration `39/169`,
  combined `45/183`, and `574/791` statements (`72.57%`).

## Residual risks

- Public search cannot exclude private non-post consumers or direct storage
  mutations.
- Mutable public connection properties make effective-state validation harder;
  the extra-read and relation-identity consequences depend on DG-ENT-04/05.
- Validation and storage are not one transaction with WordPress entity deletion;
  an endpoint can disappear after validation. DB-04/DB-05 own cascade and
  transaction behavior; CORE-04 must document this race rather than claim
  impossible cross-table atomicity.
- If DG-ENT-04/A is approved, strict full-state validation exposes legacy
  dangling rows on their next high-level update.
- A new public resolver/error surface is a long-lived compatibility commitment;
  recommendations in this document cannot be implemented before approval.
