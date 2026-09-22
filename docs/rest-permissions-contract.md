# REST v1 permission contract

Status: REST-04 is in `review`. The focused dispatch matrix passes on the
current PHP 8.1 / WordPress runtime; broad regression and delivery gates remain
owned by the Batch 22 closeout.

Owner task: `REST-04` / `REST-PERM-01`.

## Configuration

Every Client starts with `manage_options` as its REST capability. A consumer
may replace that default before constructing the Client with the canonical,
client-scoped filter:

```php
add_filter(
    'wpConnections/client/example/clientDefaultCapabilities',
    static fn (): string => 'read'
);

$client = new \iTRON\wpConnections\Client('example');
```

The client name in the hook is the canonical name returned by
`Client::getName()`. The filtered value applies to every callback that has no
explicit override. A consumer may then assign a capability to one callback:

```php
$client->capabilities->getTheClient = 'read';
$client->capabilities->getRelation = 'read';
$client->capabilities->getConnection = 'read';
$client->capabilities->createConnection = 'edit_posts';
$client->capabilities->updateConnection = 'edit_posts';
$client->capabilities->deleteConnection = 'delete_posts';
$client->capabilities->updateConnectionMeta = 'edit_posts';
$client->capabilities->deleteConnectionMeta = 'delete_posts';
```

Capabilities belong to one Client instance. An override on one Client never
grants access to another Client, even when both publish routes on the same REST
server.

## Callback and method matrix

| Capability key | Registered method variants |
| --- | --- |
| `getTheClient` | client `GET` |
| `getRelation` | relation `GET` |
| `createConnection` | relation `POST` |
| `getConnection` | connection `GET` |
| `updateConnection` | connection `POST`, `PUT`, `PATCH` |
| `deleteConnection` | connection `DELETE` |
| `updateConnectionMeta` | metadata `POST`, `PUT`, `PATCH` |
| `deleteConnectionMeta` | metadata `DELETE` |

Permission callbacks run through the managed route boundary before the
selected handler. A denied valid request cannot invoke the handler or mutate
connection or metadata rows. WordPress owns the denial response: anonymous
requests receive string code `rest_forbidden`, HTTP 401 and
`data.status=401`; authenticated requests receive the same code and HTTP/data
status 403. Argument validation and the error shapes in
[`rest-error-contract.md`](rest-error-contract.md) retain their native
precedence.

## Unknown callback behavior

When the managed permission boundary executes, it accepts only the eight
callback names above. An unallowlisted handler name fails closed as
`rest_no_route` / HTTP 404 and does not reach Client code. WordPress may reject
a malformed or non-callable route callback before that boundary executes, so
native callback validation retains precedence. For direct legacy PHP calls to
`ClientRestApi::checkPermissions()`, a missing or unknown callback name uses
that Client's configured default capability. This fallback cannot grant a
broader permission than the configured default.

## Executable evidence

`RestPermissionsTest` dispatches all 12 method variants through
`WP_REST_Server`. It proves built-in `manage_options`, the client-level
filtered default, per-callback allow and deny behavior, read-only policy,
native 401/403 responses, managed and direct unknown-callback behavior,
cross-Client isolation, handler non-invocation and unchanged persistent state
on denial. The focused current-runtime result is 40 tests / 286 assertions.
