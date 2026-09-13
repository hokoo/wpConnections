# REST v1 connection contract

Status: implementation candidate for `REST-03`; local focused verification is
green, independent QA and protected/post-merge CI are still required.

Approved decisions: `DG-M3/A`, `DG-M4/A`, `DG-RESTERR-01/A` through
`DG-RESTERR-04/A`, `DG-UPDATE-01/A` through `DG-UPDATE-05/A`,
`DG-DELETE-05/A`, and the entity error taxonomy `DG-ENT-03/A`.

Owner task: `REST-03`.

## Scope

This document is the canonical public contract for the default v1 connection
routes below `/wp-connections/v1/client/{client}`. It freezes the observable
success payloads, path-selector precedence, domain-to-HTTP mapping, and safe
internal-error boundary exercised through `WP_REST_Server::dispatch()`.

The `/meta` subresource is deliberately excluded. Its mutation and legacy
success representation belong to `REST-05` and the approved
[`rest-partial-update-contract.md`](rest-partial-update-contract.md). Route
capability selection belongs to `REST-04`. OpenAPI publication belongs to
`DOC-01`.

## Route and success matrix

All payloads below describe the JSON wire representation after WordPress REST
dispatch and serialization.

| Route | Method | Success status and body |
| --- | --- | --- |
| `/client/{client}` | `GET` | `200`; array of legacy collection items containing relation data and a self link |
| `/client/{client}/relation/{relation}` | `GET` | `200`; array of legacy collection items containing connection data and a self link |
| `/client/{client}/relation/{relation}` | `POST` | `200`; flat connection object |
| `/client/{client}/relation/{relation}/{connectionID}` | `GET` | `200`; flat connection object |
| same | `PATCH` | `200`; `{ "updated": true }` when changed or `{ "updated": false }` for a valid no-op |
| same | `POST` | `200`; the same update result wrapper; legacy full-replacement method semantics are retained |
| same | `PUT` | `200`; the same update result wrapper; full-replacement method semantics are retained |
| same | `DELETE` | `200`; exact `{ "deleted": true }` |

The flat connection object has this field order and shape:

```json
{
  "id": 123,
  "title": null,
  "relation": "related-posts",
  "from": 10,
  "to": 20,
  "order": 0,
  "meta": {
    "source": ["REST"]
  }
}
```

`meta` is an object keyed by metadata key, with an array of values for every
key. It is an empty JSON array when no metadata exists, preserving the current
v1 PHP-to-JSON behavior.

Relation data contains exactly `name`, `from`, `to`, deprecated no-op `type`,
`cardinality`, `duplicatable`, and `closurable`. The default representation
continues to serialize `type`; it does not make that field operational.

## Legacy collection-item representation

The two list routes do not emit a flat resource object and do not use the
normal WordPress `_links` representation. Under approved `DG-M4/A`, each item
retains the existing nested shape:

```json
{
  "data": {
    "id": 123,
    "title": null,
    "relation": "related-posts",
    "from": 10,
    "to": 20,
    "order": 0,
    "meta": []
  },
  "links": {
    "self": [
      {
        "href": "https://example.test/wp-json/wp-connections/v1/client/example/relation/related-posts/123",
        "attributes": []
      }
    ]
  }
}
```

The client relation list uses the same `data` plus `links` envelope with
relation fields in `data` and a relation URL in `links.self[0].href`. There is
no `_links` key. This representation is preserved for v1 compatibility; it is
not a recommendation for a future canonical representation. Consumers must
not infer a stable ordering of list items unless a separate query contract
explicitly provides one.

## Authoritative path selectors

The route-matched `{client}`, `{relation}`, and `{connectionID}` identify the
target. A same-named body or query parameter cannot redirect a read, create,
update, or delete to another relation or connection. The handler may use
`WP_REST_Request::get_param()` only as a compatibility fallback when a consumer
calls the PHP handler directly without route URL parameters.

This precedence is a security and isolation rule, not just parsing behavior.
In particular, a DELETE body cannot replace the path connection ID or relation.

## Numeric domain error representation

Every classified library domain failure uses its stable integer as the
top-level `code`; HTTP status remains independent from that number:

```json
{
  "code": 306,
  "message": "Connection endpoint entity not found: from=999.",
  "data": {
    "status": 404,
    "domain_code": 306
  }
}
```

The compatible exception message is retained when it is non-sensitive. The
exact mapping is explicit and must never be inferred from an integer range:

| Domain code | Meaning | HTTP status |
| ---: | --- | ---: |
| `1` | relation not found | `404` |
| `2` | connection not found | `404` |
| `4` | request-reachable missing domain parameter | `400` |
| `300` | classified connection validation failure | `400` |
| `301` | closurable invariant conflict | `409` |
| `302` | cardinality conflict | `409` |
| `303` | duplicate conflict | `409` |
| `304` | empty/uninitialized connection ID | `400` |
| `305` | invalid endpoint identifier | `400` |
| `306` | endpoint entity not found | `404` |
| `307` | endpoint post-type mismatch | `400` |
| `308` | unsupported endpoint entity type | `400` |
| `309` | classified endpoint resolver/domain failure | `400` |
| `310` | connection relation identity mismatch | `400` |

Code `300` is overloaded historically. It is a public 400 only for a known
validation failure. A `StorageFailure` or `StorageCapabilityUnavailable`
anywhere in its causal chain takes precedence and selects the internal 500
contract. This preserves the PHP exception type/code/message where required
without disclosing schema or adapter detail through REST.

## Internal failure boundary

A classified storage failure and every otherwise unknown `Throwable`, including
a native `Error`, produce exactly:

```json
{
  "code": "wp_connections_internal_error",
  "message": "An internal error occurred.",
  "data": {
    "status": 500
  }
}
```

The response must contain no SQL, table name, adapter message, exception class,
stack, or public correlation identifier. The original throwable is passed to
the current Client's PSR logger at error level with message
`wpConnections REST request failed.` and context key `exception`. A logger
failure is swallowed so diagnostics can never alter the public response.

The final route boundary catches `Throwable` around both the selected permission
callback and handler. It restores temporary callback attributes before mapping
the failure. A thrown permission failure uses the same generic 500/logging
contract and never invokes the handler. This does not normalize WordPress route
matching or argument validation failures, or ordinary permission callbacks that
return a boolean or `WP_Error`.

## WordPress-native gateway errors

Errors created before the handler retain the WordPress-native string code,
status, and data/detail shape. Examples include:

- `rest_missing_callback_param` and `rest_invalid_param` with HTTP 400;
- `rest_forbidden` with HTTP 401 or 403;
- `rest_no_route` with HTTP 404.

Message text from this category can be translated or changed by the installed
WordPress version and locale; it is not a library-owned cross-version string
contract. `REST-04` will determine the capability matrix without changing this
representation rule.

## Failure and mutation guarantees

- Validation, invariant, not-found, storage, and unknown failures never use a
  success wrapper.
- A request rejected before commit leaves persisted state unchanged and emits
  no success hook. This includes validation/invariant rejection and a storage
  failure rolled back by the atomic boundary.
- A pre-commit delete failure leaves the connection and its metadata unchanged
  and emits no delete-success hook.
- A missing update target is 404, never `{ "updated": false }`.
- A missing delete target is 404, never `{ "deleted": true }`.
- Storage read failure is 500, never an empty list or not-found result.

There is one important commit-boundary exception. Under approved
`DG-SPI-06R2/A`, success notifications run only after a durable commit. If a
consumer success-hook callback then throws, REST returns the same generic 500
because no success payload can be completed, but the mutation remains committed
and the throwing success hook has already run. This applies to connection create
and delete flows that emit commit-aware notifications. A consumer must not infer
rollback from that 500 or blindly retry a non-idempotent operation; it must read
current state using the resource identity and operation semantics.

These REST assertions consume the atomicity and commit-aware hook guarantees
owned by `DB-05`, `DG-SPI-03/A`, and `DG-SPI-06/A`; they do not redefine the
storage SPI.

## Verification ownership

`tests/iTRON/wpConnections/WP/RestConnectionContractTest.php` is the focused
full-dispatch executable contract. It covers the complete non-meta method
matrix, exact success/list wire shapes, URL selector authority, semantic
400/404/409 results, generic 500 sanitization and diagnostics, persisted state,
and success-hook suppression.

`REST-05` must add equivalent full-dispatch coverage for `/meta` without
changing the connection-route shapes above. `REL-02` owns cross-version,
custom-adapter, and compatibility conformance; `DOC-01` must publish this
contract in consumer-facing API documentation and OpenAPI.
