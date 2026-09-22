# Deleted-post cleanup: 1.x to 2.0 upgrade

Status: Batch 20 lifecycle boundary and Batch 21 / DB-04-Q operational
qualification are completed. Closeout PR #109 merged as `ba0b546`, all 20
post-merge contexts passed, and fresh final QA returned `pass_with_notes`.
The final release-wide consumer scan and 2.0 release notes remain HOOK-04 work.

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
// Works in the 1.x transition release and in 2.0.
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

Search private consumers, plugins and mu-plugins for all of the following, not
only an exact pasted expression:

- `remove_action()` calls using `deleteByObjectID`;
- stored callback arrays built from `$client->getStorage()`;
- wrappers that assume the Storage method is present at priority 10;
- code that re-enables cleanup with a direct `add_action()`.

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
per-site inventory/retry procedure and Batch 21 preservation rehearsal.
The final 2.0 release
procedure and known-consumer repository scan remain gated by HOOK-04 after
that qualification.
