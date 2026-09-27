# REST related entities

`GET /wp-connections/v1/client/{client}/relation/{relation}` keeps its v1
top-level array and connection item shape by default. Its optional parameters
select connections, project endpoints, filter permitted entities, and request
prepared REST data:

```text
GET /wp-connections/v1/client/my-client/relation/related?from=4&target=to&representation=expanded&entity[status]=publish&context=view&page=1&per_page=20
```

`from`, `to`, and `both` select physical connection endpoints; supplied
selectors are combined with AND. `both=4` matches a row with 4 on either side.
Each selector requires one positive ID. See the
[selector contract](rest-relation-selectors.md) for raw duplicate handling.

`target=from|to|both` projects absolute endpoint roles. `target=opposite`
requires exactly one selector: `from=4` projects `to`, `to=4` projects `from`,
and `both=4` projects the non-4 side of each selected row. A self-connection
has no distinct opposite, so its `entities` value is empty. An omitted target
projects no entities. In the current v1 JSON wire shape, empty PHP arrays
serialize as `"entities": []`; projected roles serialize as an object keyed by
`from` and/or `to`.

`representation=expanded` adds side-keyed `entities` to each v1 connection
item. Each permitted side has `{ "status": "resolved", "data": ... }`; `data`
comes from the post type's registered WordPress REST controller or the
client-owned non-post REST adapter. Missing, forbidden, unsupported, and
adapter-unavailable sides all have only `{ "status": "unavailable" }`. Numeric
connection `from`/`to` IDs remain authoritative. Duplicate connection rows
remain distinct response items; repeated endpoint IDs are resolved once per
request. The route capability is checked before entity authorization.

`context=view|embed|edit` defaults to `view`. A post's registered REST
controller checks item permission and prepares fields in that context. Client
route permission alone does not grant `edit` or private-post visibility.

## Entity filters and pagination

`entity[status]`, `entity[type]`, `entity[slug]`, and `entity[search]` filter
projected entities. A filter requires `target`. Supply repeated values as an
array, for example `entity[status][]=publish&entity[status][]=draft`. Values
within a field are ORed; different fields are ANDed. With `target=both`, one
permitted side must match all fields. Post `status`, `type`, and `slug` compare
exactly; `search` is a case-insensitive substring search of title, excerpt,
and content. An unavailable side never matches. Filtering also works without
`representation=expanded`; the returned item shape then remains v1.

Supplying either `page` or `per_page` enables pagination. Defaults are page 1
and 20 items; `per_page` is capped at 100. Expanded or paginated requests sort
by `connection.order ASC, connection.id ASC`. The response stays a top-level
array and paginated responses add `X-WP-Total` and `X-WP-TotalPages`. Filters
and entity authorization run before totals and page slicing. Without filters,
unavailable endpoints keep their connection row and count in the total.
Requests with neither pagination parameter remain unbounded. Their legacy
ordering remains unspecified unless expanded representation is requested.

Invalid target, context, filter shape/value, pagination, unsupported adapter
filter, or unknown relation GET parameter returns HTTP 400 with
`rest_invalid_param`. A valid query with no matches returns HTTP 200 and an
empty array. WordPress global REST parameters and legacy query `client` and
`relation` names retain their established handling; URL path identity wins.

## Non-post REST adapters

A non-post entity type remains owned by its client-scoped mutation resolver.
For expanded REST output, its batch resolver must also implement
`RestEntityAdapterInterface`. It may be the mutation resolver itself or a
companion registered with `Client::registerEntityBatchResolver()` before the
first connection mutation. The interface adds three methods to the existing
`resolveMany()` capability:

```php
public function getSupportedRestFilters(): array;
public function getRestEligibleIds(array $entities, array $filters, string $context): array;
public function prepareEntityForRest(object $entity, string $context): array;
```

`getSupportedRestFilters()` returns a subset of `status`, `type`, `slug`, and
`search`. `getRestEligibleIds()` receives one map of resolved objects keyed by
ID for the exact entity type. It returns only IDs authorized for the current
user and requested context and matching the complete filter set; perform
these checks in batches. `prepareEntityForRest()` returns only fields safe for
that user and context. The library calls it once per unique eligible endpoint
needed on the returned page. An unadapted non-post side is generically
unavailable; a filter unsupported by a projected type fails with HTTP 400.
Adapter exceptions are represented as unavailable without exposing details.

The existing [bulk resolution guide](bulk-entity-resolution.md) describes
client ownership and PHP-only batch reads. REST eligibility and preparation
are additional duties; the PHP resolver alone does not authorize REST data.

## OpenAPI input for DOC-01

The relation GET operation keeps an array response. Add these query
parameters to its existing `from`, `to`, and `both` parameters:

| Name | Schema | Rule |
| --- | --- | --- |
| `target` | string enum `from,to,both,opposite` | `opposite` requires exactly one selector |
| `representation` | string enum `expanded` | Adds side-keyed `entities` |
| `entity[status]` | string or string array | Exact post status |
| `entity[type]` | string or string array | Exact post type |
| `entity[slug]` | string or string array | Exact post slug |
| `entity[search]` | string or string array | Case-insensitive text substring |
| `context` | string enum `view,embed,edit` | Default `view`; item permission applies |
| `page` | positive integer | Enables paging; default 1 |
| `per_page` | integer 1–100 | Enables paging; default 20 |

Describe a 200 response as `array<ConnectionItem | ExpandedConnectionItem>`;
`ExpandedConnectionItem` adds `entities`, a map of `from` and/or `to` to a
`oneOf` resolved `{status: resolved, data: ...}` or unavailable
`{status: unavailable}` slot, or `[]` when no role is projected. Keep the
existing connection fields and links.
Document `X-WP-Total` and `X-WP-TotalPages` only when paging is enabled, and
the native `rest_invalid_param` 400 response for invalid parameters.
