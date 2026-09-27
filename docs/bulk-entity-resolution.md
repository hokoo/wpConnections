# Bulk PHP entity resolution

`ConnectionCollection::resolveEntities(EndpointTarget $target)` resolves the
requested physical sides of each connection. It returns one
`ConnectionResolutionResult` for every input occurrence, in collection order.
`getConnection()` is the original connection; `getEndpoints()` is keyed by
`from` and/or `to`. Each `ResolvedEndpoint` exposes its role, stored ID,
expected entity type, `resolved` or `unavailable` status, and an entity object
when available. An unavailable slot gives no reason; missing IDs, wrong post
types, absent adapters and adapter failures have the same status.

```php
use iTRON\wpConnections\EndpointTarget;

$connections = $client->getRelation('related')->findConnections();

foreach ($connections->resolveEntities(EndpointTarget::both()) as $row) {
    $connection = $row->getConnection();
    foreach ($row->getEndpoints() as $role => $endpoint) {
        if ('resolved' === $endpoint->getStatus()) {
            $entity = $endpoint->getEntity();
        }
    }
}
```

Choose `EndpointTarget::from()`, `to()` or `both()` for absolute roles. For
traversal, use `EndpointTarget::opposite('from', $id)`, `opposite('to', $id)` or
`opposite('both', $id)` after selecting connections with exactly that one
selector. A row that does not contain the specified anchor contributes no
opposite endpoint. A self-connection contributes both roles to `both()` and
neither to `opposite()`. The deprecated `relation.type` does not control
projection. The method preserves the collection's current order; it does not
sort rows or promise storage order.

`getPosts('from')` and `getPosts('to')` are post-only compatibility shortcuts.
They return `WP_Post[]` in connection order, including repeated occurrences,
and omit unavailable or non-post endpoints. Other directions throw
`InvalidArgumentException`, including on an empty collection.

## Non-post types

The existing client-scoped `EntityResolverInterface` remains the mutation
validator and sole owner of a non-post type. Batch reads are an additive
capability. A resolver may also implement `BatchEntityResolverInterface`, or
an application may attach a companion after registering its mutation resolver:

```php
$client->registerEntityResolver($mutationResolver);
$client->registerEntityBatchResolver('external_record', $batchResolver);
```

Both registrations happen before the client's first connection mutation.
The companion requires that exact type to be owned by a mutation resolver;
it cannot claim a WordPress post type or replace another batch resolver. It
implements:

```php
public function resolveMany(array $entityIds, string $entityType): array;
```

The call receives unique positive IDs for one exact type and returns a map of
requested IDs to available entity objects. Omit missing or wrong-type IDs.
The library calls it once per client/type group and treats omitted IDs,
invalid values or an adapter exception as generic unavailable slots. A
mutation-only resolver has no batch fallback, so its endpoints are unavailable
on this PHP read path until a batch capability is registered. If WordPress
registers a client-owned type as a post type later, that type remains
unavailable during the collision; a companion cannot register then.
Resolution results are local to each invocation and current WordPress site;
there is no cross-client or cross-site result cache.

This PHP API reads entities without current-user authorization or REST field
preparation. Applications exposing entities over REST must separately check
entity permissions and prepare fields for the requested context. Expanded REST
representation, entity filters and pagination belong to API-04.
