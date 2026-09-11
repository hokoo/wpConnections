# REST v1 error contract discovery

Status: decision-ready; DG-RESTERR-03/A approved, while DG-RESTERR-01,
DG-RESTERR-02 and DG-RESTERR-04 remain pending; this document changes no
production behavior.

Source snapshot: `b36fa85c62fc5984674a1bdf04b7648ff6065d8d`. The temporary
full-dispatch failure probe was originally recorded on `752362b`; the post-rebase
source audit includes the merged CORE-03 missing-parameter correction, which
does not change the status/code classifications below.

Owner task: `REST-00A`.

## Purpose and authority

This artifact records what the public WordPress REST dispatcher actually emits,
then separates that compatibility evidence from the choices needed by
`REST-03`, `REST-04`, `REST-05`, `DOC-01`, and `REL-02`. The dispatcher evidence
comes from requests sent through `WP_REST_Server::dispatch()`, not direct calls
to handler methods.

Approved `DG-M3/A` makes a stable numeric domain code independent from HTTP
status. Approved `DG-M4/A` requires the default v1 representation to remain
backward compatible. Neither decision selects the mappings or serialization in
this document. A recommendation is not accepted until its gate is approved by
the repository owner.

The numbers `301`, `302`, `303`, and `304` are library domain identifiers. They
are never HTTP redirect statuses and must not be passed to WordPress as the
response status.

Primary source evidence:

- [`src/Exceptions`](../src/Exceptions) defines the exception hierarchy and
  numeric codes.
- [`ClientRestApi`](../src/ClientRestApi.php) registers the routes, performs
  permission checks, catches a subset of failures, and currently converts an
  exception with `new WP_Error($exception->getCode(), $exception->getMessage())`.
- [`Relation`](../src/Relation.php),
  [`CardinalityValidation`](../src/CardinalityValidation.php), and
  [`Connection`](../src/Connection.php) originate invariant errors `301`--`304`.
- [`WPStorage`](../src/WPStorage.php) originates some code-`300` validation and
  database errors and can return ambiguous `false`/`0` results.
- [`ClientRestApiTest`](../tests/iTRON/wpConnections/WP/ClientRestApiTest.php)
  supplies the isolated, authenticated full-dispatch harness established by
  `REST-01`.
- [`rest-partial-update-contract.md`](rest-partial-update-contract.md) owns
  method roles (`DG-UPDATE-01`), scalar presence/null/falsy (`DG-UPDATE-02/02R`),
  metadata (`DG-UPDATE-03`), mutation results (`DG-UPDATE-04`) and REST success
  representation (`DG-UPDATE-05`); [`storage-spi-contract.md`](storage-spi-contract.md) owns
  read hydration in `DG-SPI-02` and storage failure signaling in `DG-SPI-03`.

## Current exception inventory

The base `iTRON\wpConnections\Exceptions\Exception` extends PHP's exception and
defaults to code `4`. The current subclasses do not carry an HTTP status or a
machine-readable reason separate from the integer code.

| Exception/source | Current code | Current meaning and request reachability | Contract gap |
| --- | ---: | --- | --- |
| `RelationNotFound` | `1` | A route relation is not registered; reached by relation, connection, mutation, and meta handlers. | Currently serialized as HTTP 500 rather than not-found. |
| `ConnectionNotFound` | `2` | An ID lookup is empty, or a connection delete reports zero rows; reached by single GET, DELETE, and meta mutation handlers. | A real absence and an adapter failure can be misclassified as the same error. |
| `MissingParameters` | `4` | Request-reachable domain create validation finds an empty `from` or `to`. | WordPress route validation and domain validation produce unrelated body shapes for equivalent bad input. |
| `MissingParameters` | `4` | `Client::registerRelation()` finds a missing `name`, `from`, or `to` during bootstrap. No registered REST handler calls this method. | Bootstrap failure shares a number with request validation but is not a current REST response. |
| `ConnectionWrongData` | `300` | Overloaded across invalid IDs/empty metadata and raw database failure messages. | The number alone cannot safely select 4xx versus 500. Raw `$wpdb->last_error` can be public. |
| `ConnectionWrongData` | `301` | A self-connection violates `closurable=false`. | Domain identifier is currently emitted with HTTP 500; it must not become HTTP 301. |
| `ConnectionWrongData` | `302` | A create/update violates relation cardinality. | Domain identifier is currently emitted with HTTP 500; it must not become HTTP 302. |
| `ConnectionWrongData` | `303` | A duplicate violates `duplicatable=false`. | Domain identifier is currently emitted with HTTP 500; it must not become HTTP 303. |
| `ConnectionWrongData` | `304` | `Connection::update()` is invoked on an aggregate with an empty ID. The current REST meta update handler reaches this method after `findConnections()->first()`, but default `WPStorage` hydrates the database row ID and excludes rows without `ID`, so a successful non-empty default lookup normally cannot trigger the guard. | Approved `DG-SPI-02/A` makes valid ID/client hydration domain-owned; malformed custom data still cannot make code `304` an HTTP 304. |
| `RelationWrongData` | `400` | Invalid/duplicate relation definition at client registration. | No current request handler registers relations, so this is not a normal per-request REST error. The integer is a domain code, not an implied HTTP status. |
| `ClientRegisterFail` | default `4` | Storage/logger/REST factory or route registration fails during client bootstrap. | This normally occurs outside request handling and must not be mistaken for user validation code `4`. |
| non-library `Throwable` | varies | Runtime hook/adapter failures and native typed-property errors are not caught by current handlers. | Full dispatch can terminate without a `WP_REST_Response`. |

Entity-resolution domain codes `305`—`310` are approved by `DG-ENT-03/A` in the
merged `CORE-00` contract. Their exact HTTP mapping remains pending
DG-RESTERR-01 and must consume that taxonomy rather than create a second one.

## Observed full-dispatch matrix

The following results were recorded with the normal registered route callbacks,
argument validation, permission callbacks, and final REST serialization. They
are current-state evidence, not desired behavior.

| Scenario | Observed HTTP/body | Classification or risk | Owning decision |
| --- | --- | --- | --- |
| Anonymous protected request | `401`, string code `rest_forbidden`, WordPress message, `data.status=401` | Authentication failure is generated before the handler. | `DG-RESTERR-03`; capability selection stays in `REST-04`. |
| Authenticated subscriber denied | `403`, string code `rest_forbidden`, same WordPress message, `data.status=403` | WordPress differentiates unauthenticated and authenticated denial. | `DG-RESTERR-03`; capability selection stays in `REST-04`. |
| Missing required route args | `400`, `rest_missing_callback_param`, `data.params` names `from` and `to` | Native pre-handler validation; handler/domain is not invoked. | `DG-RESTERR-03`; update method/presence semantics consume approved `DG-UPDATE-01/02`, while executable route schema stays with `REST-02`. |
| Wrong route arg types | `400`, `rest_invalid_param`, with per-field `rest_invalid_type` details | Native pre-handler validation. | `DG-RESTERR-03`. |
| Unsupported method/path | `404`, `rest_no_route` | Native route matching. | `DG-RESTERR-03`. |
| Unknown relation | `500`, numeric code `1`, current message, `data=null` | Semantically not-found, but no status is attached to `WP_Error`. | `DG-RESTERR-01`, `DG-RESTERR-02`. |
| Missing connection: GET, DELETE, or meta mutation | `500`, numeric code `2`, current message, `data=null` | Semantically not-found; DELETE may also mask adapter failure. | `DG-RESTERR-01`, `DG-RESTERR-02`, `DG-SPI-03`. |
| Domain missing endpoint (`from=0`) | `500`, numeric code `4`, message includes missing fields, `data=null` | Semantically invalid input after route validation. | `DG-RESTERR-01`, `DG-RESTERR-02`. |
| Closure/cardinality/duplicate conflict | `500`, numeric code `301`/`302`/`303`, invariant message, `data=null` | Stable domain identifiers exist, but HTTP status is absent. | `DG-RESTERR-01`, `DG-RESTERR-02`. |
| Generic code-`300` validation | `500`, numeric code `300`, current message, `data=null` | Some instances are client errors; the code is overloaded. | `DG-RESTERR-01`, `DG-RESTERR-02`. |
| Injected create/meta database failure | `500`, numeric code `300`, raw database error in `message`, `data=null` | Internal schema/SQL detail is disclosed. | `DG-SPI-03`, `DG-RESTERR-04`. |
| Injected connection-delete failure | `500`, numeric code `2` (`ConnectionNotFound`) | A storage fault is reported as resource absence. | `DG-SPI-03`, `DG-RESTERR-04`. |
| Injected meta-delete failure | `200`, `{ "deleted": 0 }` | A storage fault is reported as success. | `DG-SPI-03`, `DG-UPDATE-04`, `DG-UPDATE-05`, `DG-RESTERR-04`. |
| Storage update returns `false` | `200`, `{ "updated": false }` | Valid no-op, not-found, and database failure are indistinguishable. | `DG-SPI-03`, `DG-UPDATE-04`; REST body choice in `DG-UPDATE-05`. |
| Injected `WPStorage::findConnections()` read failure | Relation list can become `200` with `[]`; single-resource GET can become numeric code `2`/HTTP 500 | `$wpdb->get_results()` failure is consumed as an empty result, so current code cannot attribute the read failure and mutation guards may also treat it as no match. | `DG-SPI-03`, `DG-RESTERR-04`; mutation integrity remains `DG-M7`. |
| Native `Error` or hook `RuntimeException` | No REST response; throwable escapes dispatch | Unknown failures have no stable boundary response. | `DG-RESTERR-04`. |

The mutation database-failure rows were produced by isolated test filters that
routed a statement to a missing test table. The read-failure row is a source
finding: `WPStorage::findConnections()` does not inspect `$wpdb->last_error` or
otherwise distinguish the empty `get_results()` value after a failed query, so
the empty collection continues through REST serialization. The probe and its
tables are not part of the committed suite. This evidence demonstrates reachable
behavior; it does not define how `DG-SPI-03` must represent adapter failures.

## Candidate response matrix

This is the recommended combination of all four gates. DG-RESTERR-03/A is
approved; DG-RESTERR-01, DG-RESTERR-02 and DG-RESTERR-04 remain pending. The
matrix gives downstream tasks a complete test target without silently treating
the remaining recommendations as owner decisions.

| Failure class | Recommended HTTP status | Recommended v1 body treatment |
| --- | ---: | --- |
| WordPress route/argument/authentication/authorization error | WordPress-native `400`, `401`, `403`, or `404` | Preserve the native string code and data shape. Pass through the WordPress-produced message, whose exact localized text is owned by the installed WordPress version/locale rather than this library. |
| `RelationNotFound` / `ConnectionNotFound` after a successful route match | `404` | Preserve numeric domain code `1`/`2` at top level; add `data.status` and matching `data.domain_code`. |
| `MissingParameters` and known request/domain validation represented by generic code `300` | `400` | Preserve the numeric domain code and compatible non-sensitive message; add status/domain metadata. A code-`300` storage fault is excluded from this row. |
| Invariant `301` closurable, `302` cardinality, or `303` duplicate | `409` | Preserve the numeric domain code and compatible message; add status/domain metadata. |
| Empty-ID aggregate `304` reached by the current REST meta update handler through custom/malformed hydration | `400` | Preserve numeric domain code `304` and compatible message; add status/domain metadata. Default `WPStorage` successful lookup hydrates a non-empty ID, so implementation and tests must consume the adapter/domain ownership selected by `DG-SPI-02`, not invent a new route or claim this as normal default-adapter behavior. |
| Entity validation/resolution codes `305`—`310` | Pending `DG-RESTERR-01` | Consume the approved entity domain code, then add an explicit REST mapping; do not infer status from its number. |
| Known storage failure | `500` | String code `wp_connections_internal_error`, message `An internal error occurred.`, and `data={"status":500}`. No SQL, table, stack, adapter message, or public correlation ID. The accepted `DG-SPI-03` signal remains attributable in server diagnostics. |
| Any remaining unknown `Throwable` | `500` | The same exact string code, message, and `data.status` as a storage failure; retain the cause only in server diagnostics. |

The compatibility recommendation keeps existing numeric top-level domain codes
for already reachable library errors. It does not replace them with new string
identifiers in default v1. Adding `data.status` is necessary for WordPress to
emit an HTTP status and is an additive body change from today's `data=null`.
Native WordPress errors remain string-coded and are not coerced into the library
namespace.

Code `300` cannot be mapped without context. Production implementation must
first distinguish a known validation failure from the storage-failure protocol
selected by `DG-SPI-03`; a blanket `300 -> 400` map would turn database faults
into client errors.

## Downstream test matrix

After the gates are approved, `REST-03`/`REST-04`/`REST-05` should exercise each
case through `WP_REST_Server::dispatch()` and assert HTTP status independently
from the decoded body:

| Test group | Required examples |
| --- | --- |
| Native gateway | Missing args, invalid types, unsupported method/path, anonymous denial, authenticated denial. |
| Not found | Unknown relation; absent connection through GET, DELETE, and meta mutation. |
| Validation | Domain missing endpoint; at least one non-storage code-`300` case. |
| Bootstrap-only | Missing relation fields through `Client::registerRelation()` are classified outside current dispatch; add REST assertions only if a future explicit route makes them reachable. |
| Invariants | Request-reachable codes `301`, `302`, and `303`. The current meta update handler also reaches `Connection::update()`, but default `WPStorage` hydrates a non-empty ID after a successful lookup; exercise `304` through full dispatch with a custom/malformed empty-ID result while preserving approved `DG-SPI-02/A` domain ownership. Every dispatch assertion proves a 4xx non-redirect status. |
| Storage | Create/add-meta failure, connection-delete failure, meta-delete failure, update failure, and a `get_results()` read failure that currently collapses to an empty result. Under the accepted SPI signal none may serialize as success or not-found. |
| Unknown | A non-library exception and a native `Error`; both become the approved generic 500 without internal detail. |
| Compatibility | Exact library-owned top-level code type, message, `data.status`, optional domain metadata, and native WordPress code/data shapes selected by the gates. Native message text is asserted only as pinned-runtime evidence, not as a cross-version/locale library promise. |

Tests must also assert that rejected validation/invariant requests do not mutate
state and that failure responses do not emit success hooks. Those mutation and
hook guarantees are owned by `DG-M7`, `DG-SPI-06`, and the relevant domain task,
not redefined here.

## Decision gates

<a id="dg-resterr-01"></a>

### DG-RESTERR-01. Domain failure to HTTP status taxonomy

**Status:** pending; human decision required.

**Problem:** Current library errors have numeric domain codes but no response
status, so WordPress emits HTTP 500 for not-found, validation, and invariant
conflicts. Generic code `300` mixes invalid input and database failures. The
mapping must honor `DG-M3` without deriving HTTP status from the number.

**Alternatives:**

- **A (recommended):** use the candidate matrix above: not-found `404`, known
  validation and `304` as `400`, invariants `301`--`303` as `409`, and
  storage/unknown failures as `500`; map by classified reason, not integer
  range.
- **B:** return `400` for every library-caused 4xx condition, retaining only the
  body code to distinguish not-found and conflicts.
- **C:** retain HTTP 500 for every library exception and only stabilize the
  body. This is maximally compatible but keeps server/client attribution wrong.

**Compatibility impact:** A and B change current HTTP 500 responses for known
domain failures. A gives clients standard status semantics while preserving the
numeric body identifier. Under every option, `301`--`310` remain body/domain
codes and are never redirect statuses. Entity codes are approved by
DG-ENT-03/A; their HTTP rows remain part of this pending REST mapping decision.

**Blocked tasks:** `REST-03`, the error portions of `REST-05`, `DOC-01`, and
REST error compatibility coverage in `REL-02`.

<a id="dg-resterr-02"></a>

### DG-RESTERR-02. Default v1 library error body

**Status:** pending; human decision required.

**Problem:** `WP_Error` currently serializes library integers directly as
top-level `code` and emits `data=null`. WordPress-native errors use string codes
and structured data. The exact public v1 representation must preserve the
approved stable domain identifier while allowing an independent HTTP status.

**Alternatives:**

- **A (recommended):** retain the existing numeric top-level `code` for
  already reachable library errors, retain compatible non-sensitive messages,
  and set `data={"status": <http>, "domain_code": <same integer>}`. Native
  WordPress errors keep their native shape.
- **B:** introduce a stable string top-level REST code and move the numeric
  domain identifier to `data.domain_code`; expose this only through an opt-in
  representation while A remains default v1.
- **C:** make B the default v1 representation. This is cleaner WordPress style
  but changes the type/value of a currently public field and reopens `DG-M4`.

**Compatibility impact:** A preserves the observable top-level code and message
but changes `data` from null to an object so WordPress can carry the status. B
is additive and opt-in. C is a default-v1 breaking change and cannot proceed
without explicitly reopening approved `DG-M4`.

**Blocked tasks:** `REST-03`, the library-error portions of `REST-05`, `DOC-01`,
and REST serialization/migration coverage in `REL-02`. `REST-05` needs this gate
for missing-connection and other domain/library failures. A classified storage
failure uses the separate exact non-domain shape selected by `DG-RESTERR-04`, not
the numeric-domain shape from this gate.

<a id="dg-resterr-03"></a>

### DG-RESTERR-03. Native WordPress gateway errors

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** Route matching, schema validation, and permission callbacks reject
requests before the library handler and already expose established WordPress
codes and payload detail. Normalizing them into library numeric errors would
change both error attribution and default v1 shape.

**Alternatives:**

- **A (recommended):** preserve the WordPress-native status, string code, and
  data/detail shape for `rest_no_route`, argument validation, and
  `rest_forbidden`; pass through the message produced by the installed WordPress
  runtime. Only handler/domain failures use the library mapping. The exact
  localized message text is WordPress-version/locale-owned, not a stable
  wpConnections contract.
- **B:** wrap every native error in the library body while retaining the native
  code in nested data.
- **C:** expose normalized wrapping only as an opt-in representation, with A as
  default v1.

**Compatibility impact:** A preserves WordPress ownership of gateway errors,
including 401 for anonymous and 403 for authenticated-but-unauthorized requests,
without freezing translated message text across WordPress versions/locales. It
does not choose the required capability; that remains `REST-04`. B breaks
existing REST clients and WordPress tooling. C is additive but expands the
representation surface.

**Blocked tasks:** gateway assertions in `REST-03`, `REST-04`, and `REST-05`,
plus their `DOC-01` schema.

<a id="dg-resterr-04"></a>

### DG-RESTERR-04. Storage and unknown failure boundary

**Status:** pending; human decision required.

**Problem:** Known database failures can disclose raw SQL/database messages,
masquerade as not-found/success, or escape dispatch as a native throwable. A
public boundary must be safe without hiding the cause from operators. The
adapter-to-domain failure signal itself belongs to `DG-SPI-03`.

**Alternatives:**

- **A (recommended):** after consuming the accepted `DG-SPI-03` signal, map both
  a classified storage failure and the final unknown `Throwable` to exactly
  `new WP_Error('wp_connections_internal_error', 'An internal error occurred.',
  ['status' => 500])`. The public JSON is code
  `"wp_connections_internal_error"`, message `"An internal error occurred."`,
  and `data={"status":500}`. It contains no SQL, table, stack, adapter message,
  exception class, or correlation identifier. Record the original cause in the
  configured server logger, but return the same response even if diagnostics
  cannot be written.
- **B:** keep HTTP 500, message `"An internal error occurred."`, and
  `data={"status":500}`, but use code `"wp_connections_storage_error"` for an
  accepted `DG-SPI-03` storage failure and
  `"wp_connections_internal_error"` for every other `Throwable`. This exposes a
  stable failure category without exposing implementation detail.
- **C:** use A's code/message/status and add `data.error_id` as a newly generated
  opaque lowercase 32-character hexadecimal identifier; record that exact value
  with the server-side cause. This adds a default-v1 public field and generation
  contract solely for support correlation.

**Compatibility impact:** A/B/C intentionally replace raw database messages,
false-success bodies, and escaped throwables with an exact generic 500 shape. A
has the smallest public surface and does not make storage classification part of
the REST contract; B exposes that category; C adds a per-response identifier.
Logging transport must never change the selected public response.
Approved `DG-UPDATE-04/A` owns changed/no-op/not-found result semantics, while
pending `DG-UPDATE-05` owns the metadata success body; this gate only maps a
result already classified as failure.

**Blocked tasks:** storage/unknown cases in `REST-03` and `REST-05`, `DOC-01`,
and failure/hook compatibility coverage in `REL-02`. Production work also waits
for `DG-SPI-03` and, where applicable, pending `DG-UPDATE-05`; it consumes the
approved `DG-UPDATE-04/A` outcome taxonomy.

## Coordination and approval sequence

1. Consume approved `DG-ENT-03/A` codes `305`—`310` when adding exact entity
   statuses. REST-00A does not redefine them.
2. Resolve `DG-SPI-03` before implementing storage-failure mapping. A numeric
   code-`300` catch-all is not an adequate substitute.
3. Consume approved `DG-UPDATE-04/A` for mutation `false`/not-found outcomes,
   and resolve `DG-UPDATE-05` before changing meta success/no-op response bodies.
4. Consume approved `DG-RESTERR-03/A`, approve the remaining `DG-RESTERR-01`,
   `DG-RESTERR-02` and `DG-RESTERR-04`, then make `REST-03`/`04`/`05` tests fail
   against the chosen exact statuses and bodies before handler work.

No item in this sequence changes the already approved independence of domain
code and HTTP status, atomicity requirement, or backward-compatible default v1
policy.
