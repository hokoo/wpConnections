# Deleted-post cleanup repair contract

Status: approved design for `DB-04-D`; `DG-DELETE-06R1` through
`DG-DELETE-06R3` were approved as A by the repository owner on 2026-09-14.
This document contains no production repair code; the downstream slices are
authorized only in their recorded dependency order and against this contract.

Source snapshot: `9d627598fa30cd75a8f13b119af4611ca6af346f`.

Approved upstream policy:
[`DG-DELETE-06/A`](delete-result-contract.md#dg-delete-06--deleted_post-cleanup-failure-and-recovery).

Owner tasks: `DB-04-D`, then gated `DB-04-I1` through `DB-04-Q`.

## Purpose

WordPress fires `deleted_post` only after the post row has been removed. The
library can make deletion of its own connection and metadata rows atomic, but
it cannot roll the WordPress post deletion back. The approved policy therefore
requires a synchronous cleanup attempt plus a durable, observable, idempotent
repair path whenever that attempt cannot be confirmed successful.

This document converts that policy into three explicit choices:

1. which callback owns the recovery boundary and at which compatibility
   version it becomes active;
2. which store is authoritative for unresolved work;
3. how work is woken, retried, observed, and manually recovered.

All three choices were explicitly approved. `DB-04-I1` becomes executable only
after the independently verified DB-04-D design candidate is merged; later
slices retain their recorded implementation dependencies.

## Current runtime and compatibility boundary

The current 1.x path is:

```text
Client::__construct()
  -> Factory::getStorage()
  -> Client::init()
  -> Client::enablePostDeletionCleanup()
  -> add_action(
       'deleted_post',
       [$storage, 'deleteByObjectID'],
       10,
       1
     )

wp_delete_post($postId, true)
  -> WordPress deletes the post row
  -> do_action('deleted_post', $postId, $post)
  -> Storage::deleteByObjectID($postId)
  -> Client::executeAtomicMutation()
  -> connection/meta transaction or savepoint
  -> commit
  -> deferred success notifications
```

Relevant implementation boundaries:

- [`Client::enablePostDeletionCleanup()`](../src/Client.php) registers the
  concrete storage callback, and `disablePostDeletionCleanup()` removes that
  exact identity.
- [`WPStorage::deleteByObjectID()`](../src/WPStorage.php) supplies the default
  idempotent connection/meta effect and current 1.x stale-site guard.
- [`AtomicStorageInterface`](../src/AtomicStorageInterface.php) is optional;
  [`Abstracts\Storage`](../src/Abstracts/Storage.php) does not promise atomic
  mutation for arbitrary custom adapters.
- [`Factory::getStorage()`](../src/Factory.php) is filter-driven. A future
  request cannot reconstruct an arbitrary consumer-configured Client or Storage
  from a persisted client name alone.
- `wp-hooks-dispatcher` filters mismatched site contexts and owns subscription
  revocation, but deliberately lets failures from an active callback propagate.
  It is not a scheduler or durable queue.

The concrete callback identity is an established 1.x compatibility surface:

```php
remove_action(
    'deleted_post',
    [ $client->getStorage(), 'deleteByObjectID' ],
    10
);
```

Replacing it with a Client/coordinator callback before 2.0 would invalidate the
approved staged migration and the documented direct-`remove_action()` escape
hatch.

## Non-negotiable invariants

Every acceptable design must satisfy all of these rules:

- The repair record is durably armed before connection/meta cleanup starts.
- Failure to arm the authoritative ledger fails closed before any connection
  DML. It is surfaced as a critical recovery-unavailable failure; it is never
  reported as successful cleanup.
- WordPress post deletion is never described as rolled back.
- Final connection/meta state is idempotent. Delivery is at least once; neither
  the library nor WordPress promises exactly-once callback execution.
- One site/client/post/operation identity has at most one unresolved logical
  repair, even under duplicate hook delivery or concurrent workers.
- Site or Client context mismatch is detected before cleanup DML.
- A retry runs only after the consumer has registered a fresh Client in the
  correct site request. The library does not deserialize factory filters or a
  custom Storage instance.
- An active custom adapter that lacks the approved atomic capability performs
  zero cleanup writes and leaves an observable `needs_attention` record.
- Lost or duplicate scheduler wake-ups cannot lose or duplicate authoritative
  repair state.
- Unresolved records are not automatically purged.
- Raw SQL, stack traces, serialized Throwables, and unbounded error payloads are
  never stored or exposed through the operator surface.

## Why the record must be pre-armed

A catch-only design has four unobservable-loss windows:

1. the PHP process dies during cleanup and never reaches `catch`;
2. rollback fails and the shared `$wpdb` connection becomes transaction-tainted;
3. cleanup commits, then a deferred success notification throws;
4. the process dies between catching the failure and inserting the record.

Pre-arming closes those windows:

- a process death leaves an expired, reclaimable lease;
- rollback failure leaves already durable work outside the failed client-table
  transaction;
- commit-before-status failure is safe because retry observes a valid no-match
  cleanup result and resolves the same record;
- duplicate delivery upserts the same deterministic identity.

No design can durably record failure when the authoritative ledger itself is
unavailable without introducing a second independent durable backend. The
contract therefore treats ledger-arm failure as an explicit catastrophic
boundary before connection mutation, not as silent success.

<a id="dg-delete-06r1"></a>
## DG-DELETE-06R1 — recovery callback owner and version boundary

**Status:** approved A by repository owner on 2026-09-14.

**Problem:** recovery must surround the storage call so it can arm work before
the call and classify the result afterwards. The current WordPress callback is
the Storage method itself. WordPress supplies no around-callback result/error
boundary, and a thrown `Throwable` stops later callbacks. Generic recovery
therefore needs a Client-owned coordinator callback, but changing callback
identity is an intentional 2.0 compatibility break.

- **A — activate a manager-backed Client coordinator at the 2.0 boundary
  (recommended).** Build and verify the ledger/retry core first, then make
  HOOK-03 subscribe the coordinator through `wp-hooks-dispatcher`. Preserve the
  exact storage callback for all 1.x releases. The complete
  `DG-DELETE-06/A` guarantee becomes active only with the coordinated 2.0
  delivery path.
- **B — add a default-`WPStorage` bridge during 1.x.** Keep callback identity by
  embedding arm/catch/resolve behavior inside `WPStorage::deleteByObjectID()`
  only when called from `deleted_post`. Default storage gains partial recovery
  earlier, but custom adapters do not receive the guarantee and the logic must
  later move into the 2.0 coordinator.
- **C — replace the direct callback with a Client proxy during 1.x.** Generic
  recovery becomes possible immediately, but existing direct
  `remove_action('deleted_post', [$storage, 'deleteByObjectID'])` consumers
  break before the promised major boundary.

**Why A:** it preserves the already approved 1.x-to-2.0 transition, provides one
generic boundary for default and custom adapters, and avoids implementing the
same recovery orchestration twice. Its cost is explicit: full automatic repair
does not ship in the remaining 1.x line.

**Compatibility and delivery impact:** under A, sequencing becomes:

```text
DB-04-I1 ledger/schema
  -> DB-04-I2 retry/operator core
  -> HOOK-03 / DB-04-I3 manager-backed coordinator
  -> DB-04-Q real-flow closure
  -> LIFE-HOOK-01
  -> HOOK-04 / 2.0 upgrade guide
```

HOOK-03 must no longer wait for the whole DB-04 umbrella. It waits for the
repair core and this approved gate; DB-04 closes only after HOOK-03 provides the
real callback boundary. Selecting B or C requires a revised slice/dependency
map before production starts.

The 2.0 upgrade guide must retain the existing red flag: consumers must replace
direct storage-callback `remove_action()` calls with
`Client::disablePostDeletionCleanup()`. A rollback of the 2.0 coordinator may
unsubscribe new delivery, but it must retain the ledger and operator path until
all unresolved records are resolved or explicitly purged.

<a id="dg-delete-06r2"></a>
## DG-DELETE-06R2 — authoritative durable ledger

**Status:** approved A by repository owner on 2026-09-14.

**Problem:** the durable record must survive failures in dynamic per-client
connection tables, support deterministic deduplication, due-record indexes,
atomic worker claims, site/client isolation, and operator listing. Scheduling
state alone is not sufficient because scheduling can itself fail.

- **A — one library-owned site-local InnoDB repair table (recommended).** Use
  one table under the current `$wpdb->prefix` for all Clients on that site,
  with a unique deterministic repair key, indexed status/due fields, and
  conditional lease claims. Preflight its schema and ownership before enabling
  automatic cleanup.
- **B — one site option per repair record.** Avoid new DDL, but require prefix
  scans for listing/due work and fragile compare-and-swap behavior for claims.
  A single aggregate option would additionally create lost-update and size
  risks.
- **C — use the scheduler's store as the only record.** Avoid a second table,
  but make durable identity and retention dependent on one transport. Failure
  before/during scheduling can mean there is no repair record at all, and a
  retry chain's action IDs are not a stable domain identity.

**Why A:** it separates integrity state from both the client tables that may be
broken and the wake-up mechanism that may be late, duplicated, disabled, or
replaced. It is the only option with a direct, testable unique/claim/index
contract on both supported database families.

**Compatibility and rollback impact:** A authorizes one new site-local table
and a non-autoloaded schema/ownership record. Reverting the feature stops new
arms and workers but leaves the table intact for inspection/manual recovery;
automatic DROP is forbidden. B adds potentially unbounded option rows and still
needs an operator index strategy. C adopts the chosen scheduler's persistence
and retention contract as a hard integrity dependency.

### Approved ledger contract

The physical table is site-local. Its final identifier is owned by DB-04-I1;
the planned logical mapping is `<site-prefix>post_connections_repair` with one
library schema/ownership version, not one table per Client.

Logical fields:

| Field | Purpose |
| --- | --- |
| `repair_key` | SHA-256 identity, unique and immutable |
| `site_id` | Captured WordPress site/blog ID |
| `site_prefix` | Captured `$wpdb->prefix` used for context verification |
| `client_name` | Canonical Client identity |
| `storage_class` | Diagnostic expected adapter class; never used to instantiate it |
| `operation` | Versioned operation, initially `delete_post_connections:v1` |
| `post_id` | Positive deleted entity ID |
| `status` | `armed`, `running`, `retry_wait`, `needs_attention`, or `resolved` |
| `attempt_count` | All claimed cleanup attempts, including synchronous |
| `failure_count` | Attempts that did not confirm cleanup success |
| `next_attempt_at` | Earliest automatic claim time |
| `lease_token`, `lease_expires_at` | Conditional worker ownership |
| failure fields | Bounded category/class/code/redacted summary and first/last failure times |
| wake-up fields | Bounded last scheduling failure category/summary and timestamp |
| timestamps | Created, updated, and resolved times in UTC |

Identity is computed over length-delimited canonical values, not ambiguous
string concatenation:

```text
SHA-256(site ID, site prefix, canonical client name,
        operation version, positive post ID)
```

The table has a unique `repair_key`, an index on
`(status, next_attempt_at)`, and an index on `(client_name, status)`. It has no
foreign key to a deleted post or dynamic Client tables. DDL stays within the
common MySQL/MariaDB subset: InnoDB, integer, character, datetime, and longtext
fields; no JSON, enum, check constraint, or vendor-only locking clause.

A claim is a conditional update against status/due/lease expiry. The claim
transaction closes before calling consumer storage, so connection cleanup does
not run inside a transaction owned by the queue claim. Releasing or expiring a
lease never deletes the record.

Schema behavior follows the existing DB-06 safety policy: validate and install
before callback activation; never issue DDL from a cleanup failure handler;
reject ambiguous ownership or incompatible/partially unsafe schema. Normal
library uninstall keeps unresolved records. Only an explicit, separately
confirmed purge operation may remove unresolved state; resolved retention is
defined by R3.

<a id="dg-delete-06r3"></a>
## DG-DELETE-06R3 — wake-up, retry lifecycle, and operator surface

**Status:** approved A by repository owner on 2026-09-14.

**Problem:** a durable ledger still needs an execution path. WP-Cron may be
late or disabled, loopback requests may fail, duplicate single events can be
suppressed, and a generic library cannot reconstruct a consumer's filtered
Client/Storage in a future request. The design must define both best-effort
automatic progress and a deterministic manual path.

- **A — WP-Cron as a wake-up only, ledger as source of truth (recommended).**
  Maintain one site-level single-event wake-up for the earliest due record.
  Run the same bounded worker from automatic delivery, a Client-scoped PHP
  service, and optional thin WP-CLI commands. A system cron may call that same
  CLI/worker path. Add no REST endpoint or admin UI in DB-04.
- **B — Action Scheduler transport.** Gain mature claims, an admin screen, and
  WP-CLI tooling, but introduce a large WordPress-runtime dependency and load
  order/version-policy surface. A separate domain ledger or equivalent adapter
  is still required to preserve wpConnections identity and indefinite
  unresolved-state retention.
- **C — external/custom scheduler only.** Publish a runner interface and make
  the consumer responsible for every invocation. This minimizes built-in
  scheduling code but provides no default automatic progress, contrary to the
  approved schedule-and-retry policy.

**Why A:** the project needs a small number of integrity repairs, not a general
job queue. WP-Cron is already present, while the ledger makes its timing and
deduplication limitations harmless. Operators retain a path when site traffic
or loopback is unavailable, and the library avoids coupling its supported
WordPress/PHP range to Action Scheduler's release policy.

WordPress documents that a single event runs only after its timestamp when the
site receives a visit, and may reject an identical event within a ten-minute
window. Therefore the cron option is never evidence that a repair exists. The
runner queries the ledger, duplicate/lost wake-ups are safe, and Client
initialization reconciles a missing wake-up for due work.

Action Scheduler remains a credible alternative, but it must be loaded before
`plugins_loaded` priority 0 and its APIs become safe only after its initialization
on `init`. Its current 4.x policy purges failed actions after three months by
default. Those behaviors require additional integration and retention controls
even if B is selected.

**Compatibility and operational impact:** A adds one site-scoped WP-Cron hook,
an additive Client-scoped PHP service, and optional WP-CLI commands, but no
runtime package dependency. It gives no wall-clock completion SLA: sites
without traffic/loopback require system cron or manual execution. Rolling the
scheduler adapter back cancels future wake-ups but retains ledger state and the
manual runner. B adds a runtime package/load-order/version obligation. C makes
operator scheduling a prerequisite for any progress after synchronous failure.

### Approved state machine

```text
durable arm
  -> armed
  -> synchronous lease claim
  -> running
       |-- confirmed success, no prior failure -> delete transient record
       |-- confirmed success after failure     -> resolved
       `-- failure                              -> retry_wait

retry_wait --due conditional claim--> running
running --expired lease-----------> reclaimable by conditional claim
retry_wait --retry budget exhausted-> needs_attention
needs_attention --manual retry-----> running
resolved --30-day retention--------> purgeable
```

Required sequence:

1. Upsert the deterministic record and obtain a synchronous lease before
   connection DML.
2. Ensure a fallback wake-up exists.
3. Execute cleanup through the active Client's approved atomic boundary.
4. On confirmed success, delete a never-failed transient record or mark a
   previously failed record `resolved`.
5. On ordinary `Throwable`, store a bounded and redacted failure, schedule the next attempt,
   log through the Client logger, and return from the coordinator so one
   Client's persisted failure does not prevent later ordinary Client callbacks.
6. On ledger-arm failure, do no cleanup and propagate the critical failure.
7. If cleanup committed but final status write or a post-commit success hook
   failed, leave the pre-armed record reclaimable. A repeated idempotent cleanup
   returning logical count `0` confirms the desired final DB state and resolves
   it.

### Retry defaults

- Initial synchronous attempt plus up to eight automatic retries.
- Retry delays: `1m`, `5m`, `15m`, `1h`, `3h`, `6h`, `12h`, `24h`.
- Ten-minute worker lease; an expired lease is reclaimable.
- Exhausted automatic work enters `needs_attention`; manual retry remains
  available and uses the same claim/cleanup path.
- Unresolved `armed`, `running`, `retry_wait`, and `needs_attention` records are
  never age-purged.
- A `resolved` record that documents at least one real failure is retained for
  30 days. A successful first attempt deletes its transient arm immediately.
- Batch size and per-run time budget are bounded configuration points; changing
  them cannot alter identity, retry count, or retention semantics.

### Runtime Client responsibility

The consumer must initialize the same canonical Client, factory filters, and
custom adapter configuration in each request that may run repairs. The runner
uses only Clients already registered for the current site. A missing Client
leaves work pending; a different adapter class than the diagnostic class stored
at arm time fails closed before mutation and moves the record to an observable
attention state.

The library does not automatically call `switch_to_blog()` to scan a network.
WP-Cron and WP-CLI execution are site-by-site. For multisite, a system operator
uses the correct `--url=<site>` or otherwise enters and initializes each site
context explicitly.

`Client::disablePostDeletionCleanup()` stops new automatic hook delivery and
automatic retries for the lifetime of that Client instance. Consumers that
construct a new Client on another request must apply their intended disabled
state again, matching the existing non-persistent lifecycle API. Existing
durable records remain visible; explicit manual retry is still permitted.
Re-enabling reconciles the next due wake-up.

### Operator contract

The implementation exposes a Client-scoped PHP repair service and optional
WP-CLI bridge. Exact class/method names are finalized in DB-04-I2, but the
stable capabilities are:

- list/get records only for the current site and Client;
- filter by status with bounded deterministic pagination;
- retry one record or one bounded due batch;
- report `already_running`, `not_found_or_foreign`, `resolved`, and
  `retry_failed` distinctly;
- never force-break a live lease;
- never expose raw SQL, trace, serialized Throwable, or another Client's record;
- never delete unresolved state through normal retention cleanup;
- require an explicit confirmation/runbook for uninstall purge.

The optional CLI surface uses the same service and does not bypass Client
registration, site context, lease, or adapter checks. REST and an admin UI are
out of scope; they require their own authorization and compatibility design.

Stored diagnostics use an allowlisted category and redacted summary. Exception
class/code may be retained for attribution, but an original exception message
is not persisted blindly because current storage messages may contain physical
table names or database details.

## Failure semantics at the hook boundary

The manager continues to propagate failures from its active callback. The
wpConnections coordinator itself handles an ordinary cleanup failure only after
the durable record exists, so the manager has no failure to swallow. This lets
later registered Clients receive `deleted_post` even when an earlier Client
requires repair.

The coordinator propagates when it cannot establish the recovery guarantee,
including ledger-arm failure or an unsafe context/ownership condition before a
record can be attributed. The request may therefore fail although WordPress has
already deleted the post. Documentation and logs must state that boundary
explicitly.

## Approved executable implementation slices

The following slice order implements the approved A options. Changing an
approved choice requires a new decision and revised plan before affected code
starts.

### DB-04-I1 — shared repair ledger and schema lifecycle

Status: `waiting_dependency` on DB-04-D verified closeout.

Scope: shared site table, ownership/version preflight, repository, deterministic
arm/upsert, conditional lease claim, status transitions, and retention queries.

Out of scope: WordPress callback migration, scheduler activation, REST/admin UI,
and destructive automatic uninstall.

DoD:

- Schema is verified before automatic callback activation.
- Concurrent arm calls create one logical record.
- Claim/lease/reclaim behavior is deterministic on MySQL and MariaDB.
- No unresolved state is purged automatically.
- Ledger failure is attributable and occurs before connection DML.

### DB-04-I2 — retry engine, WP-Cron adapter, and operator service

Status: `waiting_dependency` on I1 completion.

Scope: state machine, clock/scheduler abstractions, single site wake-up,
backoff/exhaustion/retention, Client runtime registry, PHP operator service, and
optional WP-CLI bridge.

Out of scope: `deleted_post` callback replacement, REST/admin UI, and an Action
Scheduler dependency under R3/A.

DoD:

- Scheduler failure cannot lose a ledger record.
- Duplicate workers cannot hold two live claims for one repair.
- Missing Client or adapter mismatch produces no mutation and remains visible.
- Automatic and manual execution use one cleanup path.

### HOOK-03 / DB-04-I3 — manager-backed recovery delivery

Status: `waiting_dependency` on I1/I2 and the planned 2.0 delivery boundary.

Scope: `wp-hooks-dispatcher` subscription, retained revocable handle,
pre-arm/claim/cleanup/resolve coordinator, semantic enable/disable, and removal
of the 1.x storage guard only after equivalent coverage.

Out of scope: users/terms, automatic Client reconstruction, and other hook
migrations owned by LIFE-HOOK-01.

DoD:

- Only current-site subscriptions enter the coordinator.
- One persisted Client failure does not stop later ordinary Client callbacks.
- Non-atomic adapters make zero writes and leave actionable state.
- The direct 1.x `remove_action()` break is covered by an upgrade fixture.

### DB-04-Q — real-flow, vendor, and operational closure

Status: `waiting_dependency` on I1-I3 and all approved gates.

Scope: end-to-end `wp_delete_post()` behavior, failure/crash/concurrency matrix,
true multisite lane, pinned vendors, docs, runbook, and independent QA.

Out of scope: new public contracts beyond the approved R1-R3 surface and
exactly-once callback claims.

DoD:

- The complete `DG-DELETE-06/A` promise is demonstrated through the real hook.
- Consumer responsibility, degraded cron operation, repair listing/retry,
  retention, and uninstall behavior are documented and tested.
- DB-04 closes only after exact candidate, protected checks, merge, and
  post-merge evidence.

## Required verification matrix

### Real deletion and data effect

- Incoming, outgoing, self-connection, multiple relation, and metadata cases.
- Multiple Clients delete only their own rows.
- Permanent post and attachment deletion; trash-only flow does not run the
  permanent-delete cascade.
- Successful first attempt leaves no repair record.

### Failure and commit windows

- Selector read, metadata delete, connection delete, transaction start, commit,
  and rollback failures.
- Storage attempt hook and post-commit success-hook `Throwable`.
- Death after arm, during cleanup, and after cleanup commit before resolve.
- Ledger-arm and scheduling failure are distinct.
- Repeated cleanup no-match resolves commit-before-status uncertainty.

### Concurrency and time

- Duplicate `deleted_post` delivery deduplicates one logical identity.
- Two workers race for one record; only one live lease succeeds.
- Expired lease reclaim, all backoff steps, exhaustion, manual recovery, and
  resolved retention through a fake clock.

### Context and adapters

- Same Client name and numeric post ID on two real multisite blogs.
- Active, inactive, and restored site context.
- Missing fresh Client and adapter-class mismatch.
- Default storage; custom atomic adapter success/failure/no-match; custom
  non-atomic adapter capability failure with zero writes.

### Vendor and infrastructure

- Full ledger/claim/concurrency suite on pinned MySQL 8.0.46 and MariaDB
  10.11.16 without vendor-only SQL.
- Dedicated `WP_MULTISITE=1` CI lane. A test that skips when `!is_multisite()`
  is not evidence for the multisite acceptance criteria.
- WordPress's CLI test bootstrap disables cron loopback, so tests assert stored
  event reconciliation and invoke the runner directly instead of pretending an
  HTTP loopback occurred.

## Operational risks retained after the recommended design

- WP-Cron is best-effort and traffic-driven; recovery latency is unbounded
  without a system cron or operator runner.
- Exactly-once callback side effects are impossible. Custom adapters must make
  their final cleanup effect idempotent and keep unrelated side effects outside
  the claimed guarantee.
- If the ledger is unavailable, the post is already gone and automatic repair
  cannot be promised; the library can only fail closed before connection DML
  and surface the critical condition.
- Consumer factory/custom-storage configuration must be reproduced in the
  retry request.
- Unresolved records can grow without operator intervention.
- An unmanaged external transaction around `wp_delete_post()` remains caller
  responsibility under the existing transaction contract.

## Primary-source references

- WordPress `wp_delete_post()` and hook order:
  <https://developer.wordpress.org/reference/functions/wp_delete_post/>.
- WordPress single-event execution and duplicate-window behavior:
  <https://developer.wordpress.org/reference/functions/wp_schedule_single_event/>.
- Action Scheduler usage, load order, and API availability:
  <https://github.com/woocommerce/action-scheduler/blob/trunk/docs/usage.md>.
- Action Scheduler operator/admin surface:
  <https://actionscheduler.org/admin/>.
- Action Scheduler API:
  <https://actionscheduler.org/api/>.
- Action Scheduler failed-action retention policy:
  <https://github.com/woocommerce/action-scheduler/blob/trunk/docs/perf.md#cleaning-failed-actions>.
