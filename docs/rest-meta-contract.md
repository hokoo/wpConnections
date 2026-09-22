# REST v1 connection metadata contract

Status: `REST-05` implementation candidate; the expanded focused full-dispatch
class passes on the current WordPress runtime at 36 tests / 176 assertions.
Compatibility-floor rerun, broader serial delivery gates, and task acceptance
remain pending.

Approved decisions: `DG-UPDATE-03/A`, `DG-UPDATE-04/A`, `DG-UPDATE-05/A`,
`DG-SPI-03/A`, `DG-RESTERR-01/A` through `DG-RESTERR-04/A`, and
`DG-DELETE-01/A-R`.

Owner task: `REST-05` / `REST-META-01`.

## Route and method semantics

The metadata subresource is
`/wp-connections/v1/client/{client}/relation/{relation}/{connectionID}/meta`.
The matched client, relation, and connection ID are authoritative. Body or
query parameters with the same names cannot redirect a metadata mutation to a
different target. The full-dispatch path-authority matrix covers body and query
conflicts separately for POST, PATCH, PUT, and DELETE.

| Method and `meta` input | Persisted result | Successful response |
| --- | --- | --- |
| `POST`, omitted or `[]` | Preserve every existing row | `200`, `{ "updated": <legacy connection object> }` |
| `POST`, non-empty | Append every row, including duplicate keys and values | Same |
| `PATCH`, omitted or `[]` | Preserve every existing row | Same |
| `PATCH`, non-empty | Replace all values for each supplied key and preserve unrelated keys | Same |
| `PUT`, omitted or `[]` | Delete every metadata row | Same |
| `PUT`, non-empty | Replace the complete metadata collection | Same |
| `DELETE`, omitted, `[]`, or top-level `null` | Delete every metadata row | `200`, `{ "deleted": <integer row count> }` |
| `DELETE`, selectors supplied | Delete only selected rows | Same |

The default v1 update response deliberately retains the approved legacy wire
representation. The public connection fields are nested below `updated`, and
the nested `meta` value serializes as an object containing
`collectionType: "iTRON\\wpConnections\\Meta"`. This is asserted after JSON
serialization separately from the persisted metadata state.

Metadata values `0`, `"0"`, `false`, and `""` are explicit persisted values.
The WordPress database adapter hydrates both zero forms as `"0"` and hydrates
both `false` and an empty string as `""`; duplicate rows remain duplicate.
An empty key or a persisted `null` value is a numeric-domain validation error
before mutation. Query metadata reserves a row value of `null` for DELETE: it
selects every stored value under that row's key.

## DELETE selector representation

DELETE accepts both established forms:

```json
[{"key":"label","value":"one"},{"key":"flag","value":null}]
```

```json
{"label":["one","two"],"flag":null}
```

The first is a row list. The second is an associative key/value map. Duplicate
row selectors remain representable. A row value of `null` is the per-key
wildcard; top-level `null` is the existing delete-all input.

The DELETE route declares `meta`, its default, and these forms without adding a
restrictive schema type. This is intentional compatibility behavior. On both
tested WordPress runtimes, a plain `type: array` declaration accepts row lists
but rejects associative maps and top-level `null`; it also coerces scalar
values. Leaving the argument value unsanitized preserves the previously
dispatched row-list, map, null/delete-all, and legacy scalar handling while
making the public forms explicit in the route description.

## Errors, ownership, and atomicity

A positive connection ID that is absent from the matched client/relation,
including an ID owned by another relation, returns the approved numeric domain
404 body:

```json
{"code":2,"message":"Connection not found.","data":{"status":404,"domain_code":2}}
```

An existing connection with a selector that matches no metadata is a valid
success with `{ "deleted": 0 }`. Native route validation and permission
failures retain WordPress-owned response precedence and shape.

Classified storage and unknown failures return exactly HTTP 500 with
`wp_connections_internal_error`, `An internal error occurred.`, and
`data.status=500`. SQL and adapter detail are not exposed. Compound update
mutations roll back completely, and failed validation or storage operations
emit no metadata success hook.

`RestConnectionContractTest` supplies generic connection-route read-failure
evidence and the approved numeric-domain response mapping. Its code-304 case
injects `ConnectionWrongData` from `wpConnections/relation/creating` during a
connection POST, so that case proves only the 304-to-400 mapping. REST-05 adds
the distinct metadata-route reachability evidence: a custom storage selected
through the supported factory filter returns a domain `Connection` without an
ID, and full PATCH dispatch returns the exact approved code-304 / HTTP-400 body
without invoking a storage mutation or metadata success hook. Default
`WPStorage` hydration supplies a non-empty database ID after a successful
lookup; the malformed result is intentionally confined to the custom-adapter
contract boundary.

## Runtime characterization

The schema choice was verified through `WP_REST_Server::dispatch()`, not from
the repository's host WordPress trees:

| Lane | Image provenance | Observed runtime |
| --- | --- | --- |
| Current | `wpconnections-phpunit:latest`, image `sha256:d722918362a9…`, build request `WP_VERSION=7.1.0` | PHP 8.1.34, WordPress `7.1-src`, Ramsey Collection 1.3.0 |
| Floor | `wpconnections-coverage:php8.1.34-wp6.7.7`, image `sha256:fcd07ebbabc3…`, build request `WP_VERSION=6.7.7` | PHP 8.1.34, WordPress `6.7.7-src`, Ramsey Collection 1.3.0 |

Before the route declaration, full production-route dispatch accepted and
executed row-list, associative-map, omitted, empty, and top-level-null DELETE
requests identically on both lanes. The floor result of 29 tests / 120
assertions belongs to an earlier source candidate before the lookup-failure,
selector-conflict, and malformed custom-adapter evidence additions. After those
additions and expansion of path-authority coverage to separate body and query
conflicts, only the current lane has run: it passes 36 tests / 176 assertions.
The expanded matrix has not yet run on the floor; that check is reserved for
the delivery owner's final coverage gate.
