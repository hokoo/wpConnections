# Deprecations

## `Connection::load()`

Status: deprecated in the current 1.x-compatible line

Removal target: 2.0.0, the next major release

`Connection::load()` is an empty legacy method. It has never loaded or refreshed
connection state. During the 1.x compatibility window it remains callable,
returns `null`, does not mutate the object and emits no runtime notice. This
avoids changing behavior for applications that convert PHP notices into
exceptions.

Use the relation query flow to load stored connections. The relation supplies
the client and relation boundary; a `Query\Connection` supplies an ID or
endpoint filters:

```php
use iTRON\wpConnections\Query\Connection as ConnectionQuery;

$query = new ConnectionQuery();
$query->set('id', $connectionId);

$matches = $client
    ->getRelation('post-to-page')
    ->findConnections($query);

$connection = $matches->isEmpty() ? null : $matches->first();
```

Queries by `from`, `to` and supported combinations use the same
`Relation::findConnections()` entry point. Callers should handle an empty
collection instead of expecting `load()` to throw a not-found exception.

This deprecation does not apply to `ConnectionCollection::getPosts()`. The
related-entity use case and the future of that separate method are designed in
[the API-01 contract](api-01-related-entities-contract.md) for GitHub issues
[#20](https://github.com/hokoo/wpConnections/issues/20) and
[#21](https://github.com/hokoo/wpConnections/issues/21).

The public-consumer search completed by REL-00 found no call to `load()`, but
that is only negative public evidence; private consumers remain unknown. See
the [compatibility inventory](compatibility-inventory.md).
