# REST v1 relation connection selectors

Status: `REST-06` completed through
[PR #112](https://github.com/hokoo/wpConnections/pull/112). Exact protected
head `6cdc9feddcbad49eb77c95ec2882017c19bd1169` and merge
`93bea9b57b5dcdb3c1d82e2d03da1cb7e920531d` each passed all 20 required
contexts, issue #21 is closed, and the fresh E4 Epic QA gate returned the
accepted `pass_with_notes`. Full delivery evidence is in the
[Batch 24 checkpoint](plans/batch24-checkpoint.md).

The relation-list endpoint accepts three optional query selectors:

```text
GET /wp-connections/v1/client/{client}/relation/{relation}?from=4
GET /wp-connections/v1/client/{client}/relation/{relation}?to=10
GET /wp-connections/v1/client/{client}/relation/{relation}?both=4
```

`from=A` matches the physical `from` endpoint, `to=B` matches the physical
`to` endpoint, and `both=C` matches `from=C OR to=C`. Different supplied
selectors are combined with AND. For example, `from=4&to=10` selects the exact
directed pair, while `from=4&both=10` requires both the `from=4` predicate and
an incident endpoint equal to `10`.

Each selector is one positive decimal endpoint ID in the PHP integer range
`1..PHP_INT_MAX`. Query-string decimal values, including values with leading
zeroes, are sanitized to integers. Empty, zero, negative, fractional,
non-decimal, non-finite, overflow, null, array and repeated values return the
WordPress-native `rest_invalid_param` response with HTTP 400 before relation
storage is queried. Repeated plain keys and bracket forms are rejected:

```text
?from=4&from=5
?to[]=10&to[]=11
```

PHP normally collapses repeated plain query keys before WordPress builds a
`WP_REST_Request`. The managed REST lifecycle therefore preserves duplicates
as an observable array at `parse_request` for an exact live-owner GET relation
route. A Client created during the normal `rest_api_init` phase receives the
same normalization before `WP_REST_Server::serve_request()` constructs the
request. Internal direct dispatch does not consult ambient raw query state;
callers constructing a request directly must represent multiple values as an
array, which the route schema rejects.

The handler reads selector values only from the request query-parameter bag.
A same-named JSON or form body value does not select rows and cannot mask an
invalid query selector: the query value is validated independently before the
handler runs.

The existing late-binding Client contract remains unchanged. The raw-ingress
normalization can preserve repeated plain keys only when the Client and route
exist before `WP_REST_Request` is constructed. If a Client is first created
from `rest_pre_dispatch`, PHP's discarded duplicate value cannot be recovered
from the already-built request. That timing boundary affects only recovery of
raw repeated keys; late route binding and native validation of the parsed
request remain supported.

Omitting all selectors keeps the existing unbounded v1 top-level array and
connection item wire shape. A valid selector with no matches returns HTTP 200
and an empty array. The path client and relation remain authoritative over
same-named body or query parameters. Selector filtering never crosses the
matched Client's storage or the matched relation.

REST-06 does not add ordering, pagination, entity filters, endpoint projection
or expanded entity representation. Those remain in the downstream API-03 and
API-04 work described by the
[related-entities contract](api-01-related-entities-contract.md).

The focused current-runtime evidence is `RestRelationSelectorsTest` plus the
relevant `ClientRestApiLifecycleTest` cases. It covers full server dispatch,
the actual `serve_request()` request-construction path, combination semantics,
self/incident edges, invalid input before SQL, unfiltered wire compatibility,
path/client/relation isolation, custom route identity, site context and owned
subscription teardown.

The defensive rollback path that removes the first subscription when acquiring
the second subscription throws was source-audited but was not exercised by
fault injection. Existing failed-activation and teardown tests do not claim to
cover that exact catch. Add focused fault injection if this path changes or the
dispatcher becomes injectable; this accepted nonblocking test-depth note does
not waive a REST-06 or E4 criterion.
