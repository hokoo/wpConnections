# First-release hook consumer compatibility

Status: Batch 20 lifecycle boundary and Batch 21 / DB-04-Q operational
qualification are completed. Closeout PR #109 merged as `ba0b546`, all 20
post-merge contexts passed, and fresh final QA returned `pass_with_notes`.
The [HOOK-04 known-public-consumer refresh](compatibility-inventory.md#hook-04-public-refresh)
was performed on 2026-10-02. Private and deployed consumers remain outside
that source snapshot. wpConnections has not had an official tagged release;
the 1.x/2.0 labels below describe historical compatibility stages.

## Release red flags

- Direct `remove_action( 'deleted_post', [ $client->getStorage(),
  'deleteByObjectID' ], 10 )` no longer disables cleanup in the current
  manager-backed runtime. Replace it with
  `$client->disablePostDeletionCleanup()`.
- A custom `ClientRestApi` subclass must call `parent::init()` from an
  overridden `init()`. The library registers its four built-in routes, so an
  overridden `registerRestRoutes()` is not called automatically. The subclass
  owner must register and retire extra hooks/routes for each site context.

## Breaking change

In 1.x, each Client registered its concrete Storage method on `deleted_post`.
Consumer code could therefore disable library cleanup by reconstructing that
callback:

```php
remove_action(
    'deleted_post',
    [ $client->getStorage(), 'deleteByObjectID' ],
    10
);
```

The 2.0 runtime registers an internal context-aware manager callback instead.
The Storage callback above is not present, so `remove_action()` returns `false`
and cleanup remains enabled. This is intentional: callback identity is an
implementation detail, while cleanup lifecycle is the public Client contract.

## Required consumer migration

Replace direct hook manipulation before upgrading:

```php
// Works in the historical transition stage and current manager-backed stage.
$client->disablePostDeletionCleanup();

// Re-enable when the consumer is ready for automatic cleanup and repair.
$client->enablePostDeletionCleanup();
```

Both calls are idempotent. An `inited` action may disable cleanup before the
manager subscription is activated:

```php
add_action(
    'wpConnections/client/my-app/inited',
    static function ( \iTRON\wpConnections\Client $client ): void {
        $client->disablePostDeletionCleanup();
    }
);
```

<a id="consumer-checklist"></a>

## Consumer checklist

Search each deployed consumer, plugin and mu-plugin, including private code,
for all of the following, not only an exact pasted expression:

- `remove_action()` calls using `deleteByObjectID`;
- stored callback arrays built from `$client->getStorage()`;
- wrappers that assume the Storage method is present at priority 10;
- code that re-enables cleanup with a direct `add_action()`.
- a `ClientRestApi` factory replacement or subclass that overrides `init()` or
  `registerRestRoutes()`; make `init()` call `parent::init()` and explicitly own
  any extra routes/hooks across disposal and site switches.

For a direct-removal match, the consumer's maintainer owns replacing it with
`disablePostDeletionCleanup()` and verifying that a matching post deletion
leaves its connection intact. Re-enable through
`enablePostDeletionCleanup()` only when automatic cleanup is desired. For a
custom REST override, the subclass maintainer owns the review and any needed
route registration change. Record the deployed revision and migration outcome
before adopting the first official release; a public default-branch scan
cannot certify private or modified installations.

The 2026-10-02 [public-source refresh](compatibility-inventory.md#hook-04-public-refresh)
found no incompatible lifecycle pattern in the four known snapshots. Thus no
new representative consumer fixture is warranted. Existing
[`DeletedPostRepairHookMigrationTest`](../tests/iTRON/wpConnections/WP/DeletedPostRepairHookMigrationTest.php)
cases verify that direct Storage callback
removal returns false while cleanup runs, and that semantic disable/enable
controls delivery.

Do not register the Storage callback alongside the semantic API. That bypasses
the durable arm/claim boundary and can duplicate cleanup outside the managed
recovery contract.

## Multisite responsibility

A Client belongs to the blog ID and database prefix in which it was
constructed. After `switch_to_blog()`, the consumer must construct and
initialize a fresh Client for that site before it expects deletion cleanup or
automatic retries there. The library does not switch sites, clone Clients or
reconstruct custom Storage factories.

Manager wrappers registered for other site contexts remain process-global
WordPress callbacks, but they return before entering the inactive Client's
coordinator. A command sent directly to a stale Client remains an error; the
context gate is not an automatic routing service.

## Failure and operator behavior

The coordinator writes and claims a deterministic site-local repair record
before it invokes Storage cleanup. Ordinary atomic cleanup failures become
durable retry state and return normally from that Client's WordPress callback,
so later current-site Client subscriptions still run. Failure to establish or
verify durable recovery propagates and performs no connection/meta cleanup.

WP-Cron is a best-effort wake-up mechanism, not ledger truth. Sites with
`DISABLE_WP_CRON` or insufficient traffic need an operator-driven runner. Use
`Client::getDeletedPostRepairService()` to list, inspect and retry the current
Client's records; diagnostics exposed by the service are redacted.

## Rollback

Rolling application code back does not make unresolved 2.0 repair work cease
to exist and does not reconstruct the old direct callback identity.

1. Inventory non-resolved records for every affected site and Client before
   changing code.
2. Stop new automatic delivery with `disablePostDeletionCleanup()` where the
   running version supports it.
3. Roll back code and dependency versions together; recreate one Client per
   active site context.
4. Preserve `<site-prefix>wpconnections_repair` and the
   `wpconnections_repair_schema_owner` option. Never drop, truncate or silently
   mark unresolved rows resolved as part of rollback.
5. Keep a forward-recovery path capable of reading the ledger, or complete
   operator-reviewed retries before retiring the 2.0 runtime.

The [operations runbook](deleted-post-repair-operations.md) provides the
per-site inventory/retry procedure and Batch 21 preservation rehearsal. The
[dated known-public-consumer refresh](compatibility-inventory.md#hook-04-public-refresh)
is documented; REL-03 candidate verification and independent E7 acceptance
remain pending before the first official release.
