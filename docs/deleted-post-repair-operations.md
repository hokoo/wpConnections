# Deleted-post repair operations

This is the canonical runbook for inspecting and retrying deleted-post cleanup
after synchronous recovery could not be confirmed. WP-Cron is only a
best-effort wake-up. The site-local repair ledger is authoritative, and the
supported deterministic operator path is the existing Client-scoped PHP
service returned by `Client::getDeletedPostRepairService()`.

This procedure does not add a WP-CLI command, REST route, admin screen, network
scanner, cron setting, or destructive purge operation. Those surfaces remain
deferred. Rollback and uninstall preservation are covered separately.

## Before running repairs

Operate on one WordPress site and one canonical Client at a time. In multisite,
enter the intended blog context first. Bootstrap the same Client name, factory
filters, custom Storage class, and application configuration used by ordinary
requests on that site. Do not construct a Client from a repair key or from
ledger data, and do not scan sites with `switch_to_blog()` inside a repair
loop.

The examples below assume that application bootstrap has already produced the
correct current-site `$client`:

```php
$service = $client->getDeletedPostRepairService();
```

The getter is lazy and side-effect-free. If any service operation below throws
`iTRON\wpConnections\Exceptions\DeletedPostRepairUnavailable`, stop. Confirm
the selected site, database prefix, Client initialization, and repair schema
readiness. The exception is deliberately safe and does not include the
underlying database or adapter message.

## Inventory one site and Client

Inventory all five states with bounded keyset pagination. Preserve the returned
repair key as the cursor; do not derive or edit it.

```php
$statuses = [ 'armed', 'running', 'retry_wait', 'needs_attention', 'resolved' ];

foreach ($statuses as $status) {
    $after = null;

    do {
        $page = $service->listRepairs($status, $after, 100);

        foreach ($page->getItems() as $repair) {
            // Send only these safe projection fields to operational telemetry.
            $row = [
                'repair_key'      => $repair->getRepairKey(),
                'post_id'         => $repair->getPostId(),
                'status'          => $repair->getStatus(),
                'attempt_count'   => $repair->getAttemptCount(),
                'failure_count'   => $repair->getFailureCount(),
                'next_attempt_at' => $repair->getNextAttemptAt(),
                'failure_category'=> $repair->getFailureCategory(),
                'failure_class'   => $repair->getFailureClass(),
                'failure_code'    => $repair->getFailureCode(),
                'failure_summary' => $repair->getFailureSummary(),
            ];
        }

        $after = $page->getNextAfterKey();
    } while (null !== $after);
}
```

Listings are scoped to the bound site and Client and ordered by repair key.
Another Client's record is not an alternate inventory source. `getRepair()`
returns `null` for both an absent key and a foreign key so that it cannot be
used as an existence oracle.

Interpret states as follows:

- `armed`: durable work exists but has not acquired its first lease; it is due
  for a bounded batch or an explicit single retry.
- `running`: do not force it. A live ten-minute lease is protected; only an
  expired lease is reclaimable.
- `retry_wait`: retry it through a due batch when `next_attempt_at` has passed,
  or use an explicit single retry when an operator has decided not to wait.
- `needs_attention`: automatic work has stopped or a capability/manual retry
  failed. Inspect and correct the runtime condition, then use a single retry.
- `resolved`: retained evidence of a repair that had a real failure. It is not
  unresolved work and normal retention owns its eventual removal.

## Inspect one repair safely

```php
$repair = $service->getRepair($repairKey);

if (null === $repair) {
    // The key is absent or belongs to another site/Client.
    return;
}

$status = $repair->getStatus();
$failureCategory = $repair->getFailureCategory();
$failureSummary = $repair->getFailureSummary();
$wakeupCategory = $repair->getWakeupFailureCategory();
$wakeupSummary = $repair->getWakeupFailureSummary();
```

Failure and wake-up summaries are bounded and redacted. Values such as
`[diagnostic details redacted]` are expected; they are not a prompt to inspect
or mutate the table directly. Never export raw ledger rows, serialized values,
SQL text, exception messages, or traces. Use the safe projection, application
logs under the site's normal access controls, and the category/class/code
attribution to investigate.

## Retry order

Use this order within each site and Client:

1. Leave a live `running` repair alone.
2. Correct Client bootstrap, custom adapter, database, or application failures
   before retrying affected work.
3. Use `retryDueRepairs()` for ordinary due `armed`, `retry_wait`, and expired
   `running` work.
4. Use `retryRepair()` for a reviewed `needs_attention` record, an urgent
   not-yet-due retry, or a single record requiring a separately observed
   outcome.
5. Re-inventory until no due batch remains, then retain unresolved and resolved
   rows according to library policy. Do not delete them manually.

### Retry a bounded due batch

```php
$maximumBatches = 10;
$continuationRequired = false;

for ($batchNumber = 1; $batchNumber <= $maximumBatches; $batchNumber++) {
    $batch = $service->retryDueRepairs(20, 10);

    foreach ($batch->getResults() as $result) {
        $outcome = $result->getOutcome();
        $attempted = $result->wasCleanupAttempted();
        $current = $result->getRepair();
        // Record the safe outcome and projection in operator telemetry.
    }

    $continuationRequired = $batch->hasMoreDue();
    if (!$continuationRequired) {
        break;
    }
}

if ($continuationRequired) {
    // Persist a continuation signal for another bounded maintenance run.
}
```

Both bounds are mandatory safeguards: the record limit is 1–100 and the time
budget is 1–60 seconds. The surrounding job must also cap the number of batches
and record continuation instead of looping without a ceiling. `complete` means
that no more due work for this Client was visible at the final read; it does not
mean that future `retry_wait` or `needs_attention` records do not exist.
`batch_limit` and `time_budget` both set `hasMoreDue()` to `true`.

### Retry one reviewed repair

```php
$result = $service->retryRepair($repairKey);

switch ($result->getOutcome()) {
    case 'resolved':
        // wasCleanupAttempted() distinguishes a new retry from an already-resolved row.
        break;
    case 'already_running':
        // Leave the live lease alone and inspect again later.
        break;
    case 'not_found_or_foreign':
        // Recheck the site, Client and key; no record details are disclosed.
        break;
    case 'retry_failed':
        // Inspect the returned safe projection; do not log an underlying raw payload.
        break;
}
```

An explicit single retry can claim `armed`, any `retry_wait`,
`needs_attention`, or an expired `running` record, but never a live lease. A
cleanup or capability failure is returned as redacted `retry_failed`, not as
the original Throwable.

## Exhaustion and `needs_attention`

The unattended ceiling is the initial claim plus at most eight automatic
retries. Every acquired claim counts, including an expired/crashed claim and an
interleaved manual claim. When automatic work reaches the ceiling, the record
moves to `needs_attention` without a tenth automatic cleanup attempt.

Due batches do not select `needs_attention`. Diagnose the cause, restore the
same valid runtime configuration, and use `retryRepair()` explicitly. If that
manual attempt fails, the record stays in `needs_attention`; it does not start
a new automatic retry chain. Unresolved records are never age-purged.

## Missing Client and adapter mismatch

A future request cannot reconstruct consumer filters or a custom Storage from
persisted data. If the canonical Client is missing or disabled in a cron
request, automatic selection leaves its work unchanged and visible. Initialize
that Client in the correct site request. Disabling automatic post-deletion
cleanup does not disable explicit service calls.

If the current Storage fingerprint differs from the adapter that armed the
repair, the claim fails closed before cleanup writes. The safe result is
`retry_failed`, `wasCleanupAttempted()` is `false`, and the record is visible as
`needs_attention`. Restore the intended adapter configuration in a fresh
request and then perform a reviewed single retry. Never bypass the fingerprint
check or edit its stored value.

## Cron health and degraded operation

Check dispatch availability separately from durable inventory:

```php
$dispatchDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$event = wp_get_scheduled_event('wpConnections/deletedPostRepair/run', []);
```

The only stored event shape is the site-local hook
`wpConnections/deletedPostRepair/run` with an empty argument array. It contains
no repair key, Client name, adapter name, or diagnostic. An event shows a
best-effort wake-up, not authoritative work; an absent event is also normal
when no eligible work or retention deadline exists. Inventory through the
Client service is the source of truth.

With `DISABLE_WP_CRON`, low traffic, blocked loopback, or a late event, run the
bounded public service methods above from an authenticated, access-controlled
application maintenance process. The integration scenarios inspect the stored
event directly and invoke the registered runner directly; they do not claim
that the WordPress CLI test bootstrap performed an HTTP cron loopback.
Operational code should use the public Client service, not the internal hook or
internal ledger classes.

The library currently provides no WP-CLI bridge, admin UI, or REST repair
endpoint. Adding one requires a separate command/authentication/output
contract; none is implied by this runbook.
