# Deleted-post cleanup repair contract

Status: `DB-04-D` and `DB-04-I1` completed; `DG-DELETE-06R1` through
`DG-DELETE-06R3` were approved as A by the repository owner on 2026-09-14,
and `DG-DELETE-06R4` through `DG-DELETE-06R6` were approved as A by the
repository owner on 2026-09-21.
`DB-04-I2` is completed as Batch 18. B18-01 through B18-07 are delivered;
B18-08 remains explicitly deferred. The first exact-candidate reviews found a
cross-site first-registration defect plus lower-severity scheduler observation,
schema-query amplification and plan-consistency findings. Regression commit
`bc08a31` and corrective implementation `e23cd38` closed those findings.
Exact candidate `4c18fde9934a6d74f143faf0acc6dc97650a1a93` received three
independent closure PASS results with no open P0—P3 findings and passed 19/19
protected jobs. PR #102 merged as
`66f6fd3413d316081f431ff7bcc5d8ae6beefc9c`; the exact merge passed all 19
post-merge jobs. DB-04-I3 subsequently completed in Batch 19 / PR #104, and
the terminal Client lifecycle completed in Batch 20 / PR #106. DB-04-Q is now
the executable Batch 21 qualification.

Source snapshot: `9d627598fa30cd75a8f13b119af4611ca6af346f`.

Approved upstream policy:
[`DG-DELETE-06/A`](delete-result-contract.md#dg-delete-06--deleted_post-cleanup-failure-and-recovery).

Delivery evidence: exact design candidate
`54db7fdd90a581a9f4d587eb121205a6efb5f88b` received independent QA PASS with
no blocking findings and passed 19/19 protected checks. PR #97 merged as
`419e4d97d5958d23cc814011c77537d226782bd9`; the exact merge passed 19/19
post-merge checks.

The exact DB-04-I1 candidate
`14abfd54cbbdfd252d6afa65eacbc69f981d9cbb` received independent
exact-candidate QA and security/SQL audit PASS with no open P0/P1/P2 findings,
and passed 19/19 protected checks. PR #99 merged as
`c2f4b98cb6b2420d9b82a7f5967c780b2cabdf42`; all five post-merge workflows,
comprising the same 19 jobs, passed on the exact merge.

Owner tasks: completed `DB-04-D` and `DB-04-I1`, then `DB-04-I2` through
`DB-04-Q`.

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

All three original choices and all three Batch 18 refinements were explicitly
approved. `DB-04-I1`—`DB-04-I3` and LIFE-HOOK-01 are complete. Their component
and lifecycle evidence does not by itself mark DB-04-Q or the 2.0 release
complete; Batch 21 supplies the missing real-flow and operational proof.

## Current runtime and compatibility boundary

The Batch 19 implementation replaces the historical 1.x direct
Storage callback with this 2.0 path:

```text
Client::__construct()
  -> Factory::getStorage()
  -> Client::init()
  -> public Client inited actions complete
  -> DeletedPostRepairRuntime registers the exact site/name owner
  -> wp-hooks-dispatcher subscribes the Client coordinator
       ('deleted_post', priority 10, accepted arguments 1)
  -> site runtime subscribes the cron gateway
       ('wpConnections/deletedPostRepair/run', priority 10, accepted arguments 0)

wp_delete_post($postId, true)
  -> WordPress deletes the post row
  -> do_action('deleted_post', $postId, $post)
  -> dispatcher rejects inactive site contexts
  -> coordinator durably arms and claims the repair identity
  -> shared executor invokes atomic Storage cleanup
  -> success removes a never-failed transient record
  -> handled failure remains durable for cron/operator retry
  -> final wake-up reconciliation
```

Relevant implementation boundaries:

- [`Client::enablePostDeletionCleanup()`](../src/Client.php) and
  `disablePostDeletionCleanup()` control manager subscription and automatic
  retry eligibility without exposing callback identity.
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

The concrete callback identity was a 1.x compatibility surface:

```php
remove_action(
    'deleted_post',
    [ $client->getStorage(), 'deleteByObjectID' ],
    10
);
```

Batch 19 intentionally removes that compatibility at the approved 2.0
boundary. The call now returns `false` and does not disable cleanup. Consumers
must use the semantic Client methods; see the
[focused upgrade guide](deleted-post-cleanup-upgrade.md).

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
  -> LIFE-HOOK-01
  -> DB-04-Q real-flow closure
  -> HOOK-04 / 2.0 upgrade guide
```

HOOK-03 must no longer wait for the whole DB-04 umbrella. It waits for the
repair core and this approved gate; DB-04 closes only after HOOK-03 provides the
real callback boundary. LIFE-HOOK-01 was later deliberately completed before
DB-04-Q so the qualification exercises the final Client lifecycle. Selecting B
or C requires a revised slice/dependency map before production starts.

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

The physical table is site-local. DB-04-I1 uses the fixed basename
`wpconnections_repair`, producing `<site-prefix>wpconnections_repair`, with the
site-scoped ownership option `wpconnections_repair_schema_owner`. It is one
library schema/ownership version, not one table per Client.

The initially considered basename `post_connections_repair` is prohibited: it
is exactly the existing per-Client connections-table name for the valid Client
name `repair`. The dedicated basename keeps the shared ledger outside both
`post_connections_<client>` and `post_connections_meta_<client>` namespaces.
The complete identifier still must pass the 64-character database limit before
registration or DDL.

Logical fields:

| Field | Purpose |
| --- | --- |
| `repair_key` | SHA-256 identity, unique and immutable |
| `site_id` | Captured WordPress site/blog ID |
| `site_prefix` | Captured `$wpdb->prefix` used for context verification |
| `client_name` | Canonical Client identity |
| `storage_class` | Bounded safe diagnostic label; never used to instantiate an adapter |
| `storage_fingerprint` | SHA-256 of the complete runtime adapter class name for exact mismatch checks |
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

Adapter identity is deliberately not part of `repair_key`: changing or fixing
an adapter must not create a second logical repair for the same deleted post.
The full runtime class name is compared through `storage_fingerprint`; only a
sanitized bounded label is available for diagnostics. This also handles
anonymous-class names that may contain bytes unsuitable for direct persistence.

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
leaves work pending; a runtime adapter fingerprint different from the one
stored at arm time fails closed before mutation and moves the record to an
observable attention state.

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

<a id="dg-delete-06r4"></a>
## DG-DELETE-06R4 — public PHP operator representation

**Status:** approved A by repository owner on 2026-09-21.

**Problem:** R3 approved the operator capabilities but deliberately left exact
class and method shapes to I2. The internal ledger and its records mirror
persistence concerns, include fields that must never become an existence
oracle, and are expected to evolve with schema revisions. Returning them from a
public Client method would unintentionally make storage layout and unsafe
diagnostics part of the compatibility promise. I2 therefore needs an explicit
boundary between the stable consumer API and the internal ledger.

- **A — Client-scoped service with immutable safe projections/results
  (recommended).** A successfully initialized Client exposes a repair service.
  The service owns current-site/current-Client scoping and returns dedicated,
  bounded value objects for listing, inspection, retry outcomes and cursors.
  The ledger and persistence records stay internal. Missing and foreign keys
  intentionally share `not_found_or_foreign`; retry outcomes retain the R3
  taxonomy without returning a Throwable, SQL, trace, or arbitrary persisted
  payload.
- **B — Client-scoped service returning documented arrays and strings.** This
  preserves the security boundary and costs less code initially, but makes
  field presence, spelling and invalid combinations easier to expose
  accidentally and harder to evolve compatibly.
- **C — expose the internal ledger and record objects.** This is the smallest
  facade, but freezes a schema-oriented API, lets consumers bypass service
  invariants, and couples every persistence migration to public compatibility.

**Why A:** it preserves the approved Client-scoped authority model while
keeping schema details replaceable. The extra value objects are a small cost
for a long-lived public library API. This gate does not block the internal
policy, cleanup executor, registry or ledger-query slices; it blocks only the
public operator facade and consumers of that facade. WP-CLI remains deferred
and optional; adding it requires its own command/output/exit-code contract.

Under A, the exact public surface proposed for approval is:

- `Client::getDeletedPostRepairService()` returns one Client-bound
  `iTRON\wpConnections\DeletedPostRepairService`; the getter is lazy and
  side-effect-free, while each operation checks the bound Client's site context
  and ledger readiness before its first read or mutation;
- `getRepair(string $repairKey): ?DeletedPostRepairView`,
  `listRepairs(?string $status = null, ?string $afterKey = null, int $limit =
  20): DeletedPostRepairPage`,
  `retryRepair(string $repairKey): DeletedPostRepairRetryResult`, and
  `retryDueRepairs(int $limit = 20, int $timeBudgetSeconds = 10):
  DeletedPostRepairBatchResult` are the four service operations;
- immutable `DeletedPostRepairView`, `DeletedPostRepairPage`,
  `DeletedPostRepairRetryResult`, and `DeletedPostRepairBatchResult` objects
  carry results; no `Internal\DeletedPostRepair*` type appears in a public
  signature;
- page and due-batch defaults are 20 records with a hard maximum of 100; a due
  batch has a default 10-second monotonic budget and a hard maximum of 60
  seconds; no public filter, option, or mutable global configuration is added;
- repair keys and non-null cursors are lowercase SHA-256 strings matching
  `[a-f0-9]{64}` exactly; limits are integers from 1 through 100 and the time
  budget is an integer from 1 through 60 seconds;
- views expose repair key, post ID, status, attempt/failure counts, next-at,
  bounded failure/wake-up diagnostics, and created/updated/resolved timestamps;
  they omit site prefix, storage fingerprint, lease token and persistence
  objects;
- `DeletedPostRepairView` exposes exactly `getRepairKey(): string`,
  `getPostId(): int`, `getStatus(): string`, `getAttemptCount(): int`,
  `getFailureCount(): int`, `getNextAttemptAt(): ?DateTimeImmutable`, nullable
  `getFailureCategory(): ?string`, `getFailureClass(): ?string`,
  `getFailureCode(): ?string`, `getFailureSummary(): ?string`,
  `getFirstFailureAt(): ?DateTimeImmutable`,
  `getLastFailureAt(): ?DateTimeImmutable`,
  `getWakeupFailureCategory(): ?string`,
  `getWakeupFailureSummary(): ?string`,
  `getWakeupFailureAt(): ?DateTimeImmutable`,
  `getCreatedAt(): DateTimeImmutable`, `getUpdatedAt(): DateTimeImmutable`, and
  `getResolvedAt(): ?DateTimeImmutable`; every time is UTC;
- a page is ordered by repair key ascending and applies `afterKey` exclusively.
  It returns at most `limit` views. `nextAfterKey` is the last returned key only
  when an additional matching row existed in the same validated read; otherwise
  it is `null`. An absent or foreign `getRepair()` key returns the same `null`;
  allowed status values are exactly the five ledger states or `null` for all;
- `retryRepair()` returns exactly `resolved`, `already_running`,
  `not_found_or_foreign`, or `retry_failed`; a batch is an ordered bounded
  collection of the same per-record results, `hasMoreDue`, and one stop reason:
  `complete`, `batch_limit`, or `time_budget`;
- `DeletedPostRepairPage` exposes `getItems(): array` and
  `getNextAfterKey(): ?string`; `DeletedPostRepairRetryResult` exposes the
  echoed `getRepairKey(): string`, `getOutcome(): string`,
  `wasCleanupAttempted(): bool`, and `getRepair(): ?DeletedPostRepairView`, so
  batch results remain correlatable without another lookup;
- `retryRepair()` is the explicit override path: for this service's one bound
  site/Client it may claim `armed`, due or not-yet-due `retry_wait`,
  `needs_attention`, or expired `running`; it never breaks a live lease.
  `retryDueRepairs()` is also restricted to that one bound site/Client but
  selects only `armed`, due `retry_wait`, and expired `running`; it excludes
  future `retry_wait`, `needs_attention`, and `resolved`. A disabled Client may
  invoke both manual methods because disable controls automatic eligibility,
  not explicit operator authority;
- outcome `resolved` covers both an already-resolved record and a successful
  current retry; `wasCleanupAttempted()` distinguishes them. Adapter mismatch,
  unsupported atomic capability, unknown operation and safely classified
  claim races map to redacted `retry_failed`; the flag remains false when
  storage was not called. `not_found_or_foreign` has no view, while the other
  outcomes return the safely re-read current view when it is available;
- `DeletedPostRepairBatchResult` exposes `getResults(): array`,
  `hasMoreDue(): bool`, and `getStopReason(): string`; both arrays contain only
  their declared immutable public object type and preserve deterministic repair
  order. `complete` means no more due record for the bound Client was visible
  at the final read and requires `hasMoreDue=false`; `batch_limit` means the
  record limit was reached with more due work visible, and `time_budget` means
  the monotonic deadline was reached before another claim while due work
  remained—both require `hasMoreDue=true`. Ledger uncertainty throws instead
  of returning a potentially false completion result;
- all four DTO constructors are private; library-owned `@internal` factories
  create them. Consumers receive DTOs only through the service, and those
  construction factories are explicitly outside the compatibility surface;
- invalid key/status/cursor/limit/budget fails before SQL with
  `InvalidArgumentException`; stale context or ledger/schema uncertainty throws
  a new safe `iTRON\wpConnections\Exceptions\DeletedPostRepairUnavailable`
  exception without the original database/adapter message. A handled
  cleanup/capability failure is represented by redacted `retry_failed`, not
  leaked as the original Throwable.

**Compatibility, rollback, and affected tasks:** A is additive but stable once
released; DTO fields and method outcomes then require normal compatibility
discipline. Rolling back the facade does not delete ledger state. B18-05,
B18-06's shared batch-limit policy, and the public portion of B18-Q are governed
by this approved contract; the optional CLI is excluded from Batch 18.
B18-01—B18-04 and the single-record internal executor do not depend on public
names.

<a id="dg-delete-06r5"></a>
## DG-DELETE-06R5 — automatic eligibility and single wake-up semantics

**Status:** approved A by repository owner on 2026-09-21.

**Problem:** the ledger is shared by all Clients on one site, but a future
request can execute a repair only for Clients freshly initialized in that site
context. A global bounded due query can be filled by records for missing or
disabled Clients, starving an eligible Client beyond the limit. Rescheduling
those skipped records immediately can also create a cron hot loop. Separately,
WordPress cannot strictly maintain exactly one event at an exact timestamp:
identical single events can be rejected within ten minutes, callbacks consume
their event before invocation, and cron-option updates have no compare-and-swap
replacement primitive.

- **A — registered-and-enabled eligibility plus best-effort logical wake-up
  convergence (recommended).** Automatic selection considers only Clients
  successfully registered for the current site and enabled for automatic
  cleanup. Missing/disabled work remains unchanged and visible but neither
  consumes automatic batch capacity nor keeps cron spinning. Registration and
  re-enable reconcile the next wake-up. “One site wake-up” means one stable
  logical hook/argument set converging best-effort toward the earliest eligible
  work; harmless duplicate events are allowed and lease claims remain the
  concurrency authority.
- **B — scan all due records and skip unavailable Clients in the worker.** This
  avoids an eligibility-aware query but bounded scans can starve eligible work,
  while immediate reconciliation of the same skipped head rows can hot-loop.
- **C — move missing/disabled Client records to `needs_attention`.** This makes
  the global scan progress, but contradicts the approved lifecycle: missing
  initialization is a request condition, disable is reversible, and neither is
  evidence that the persisted repair itself failed.

**Why A:** it implements the already approved rule that consumers initialize
each Client per site without turning temporary unavailability into failure.
An existing earlier event is not moved later; replacement must not first remove
the only known wake-up; a callback establishes a safety wake-up no later than
the nearest claimed lease expiry and reconciles again after its bounded batch.
The ledger, never the cron option, remains authoritative. This gate blocks the
runtime eligibility registry, worker-oriented due/next-wakeup query and cron
adapter, but not the retry policy or direct-Client cleanup executor.

The same empty-argument site event also wakes resolved-retention work. Its next
timestamp is the earlier of the nearest eligible Client cleanup deadline and
the nearest site-wide `resolved_at + 30 days` deadline. Retention can therefore
schedule the event even when no Client is automatically eligible; the callback
may purge only validated resolved rows and never uses that exception to process
missing/disabled Client cleanup. If no handler is initialized in a cron
request, ledger state remains intact and the next Client initialization
reconciles the lost wake-up.

Under A, the persisted event contract is one single event named
`wpConnections/deletedPostRepair/run`, with an empty argument array. No repair
key, Client name, adapter name or diagnostic becomes cron-option data. I2 may
build and test the adapter and registry, but it does not subscribe this hook,
register Clients from `Client::init()`, arm repairs from `deleted_post`, or
change `enablePostDeletionCleanup()`/`disablePostDeletionCleanup()`. Those 2.0
activation steps remain I3. Automatic defaults are internal constants; adding
a public filter, option, or toggle later is a new gate.

The internal registry key is exact `(blog ID, DB prefix, canonical Client
name)`. Re-registering the same live Client is idempotent; a different live
Client for the same key is rejected rather than last-wins. A tokenized
revocation handle prevents an older owner from revoking a replacement. The
same name on another site is independent. Only successful initialization can
be activated by I3, and failure rollback releases any reservation.

**Compatibility, rollback, and affected tasks:** the 1.x callback remains
`[$storage, 'deleteByObjectID']` at priority 10 with one argument, including its
direct `remove_action()` escape hatch. Rolling back scheduling unschedules only
the known future event and retains every ledger row/manual path. B18-03,
B18-04, B18-06, B18-07 and their automatic QA are governed by this approved
contract. I3 alone owns production activation and lifecycle wiring.

<a id="dg-delete-06r6"></a>
## DG-DELETE-06R6 — automatic retry budget accounting

**Status:** approved A by repository owner on 2026-09-21.

**Problem:** R3 bounds unattended work to an initial synchronous attempt plus
up to eight automatic retries. The I1 ledger counts every acquired lease in
`attempt_count` and every recorded failure in `failure_count`, but deliberately
does not persist whether a claim was synchronous, automatic, or manual. A
process can die after acquiring a lease and before recording a failure; an
operator can also interleave manual claims. The runner must decide which
durable counter exhausts automatic work. That choice affects crash behavior,
manual recovery and potentially the ledger schema.

- **A — count every acquired claim against one nine-attempt unattended ceiling
  (recommended).** Automatic claim is allowed only while `attempt_count < 9`.
  The initial synchronous claim, expired/crashed claims and any interleaved
  manual claims all consume that conservative ceiling. An expired claim at the
  ceiling moves conditionally to `needs_attention` without a tenth automatic
  cleanup. Explicit manual retry remains available after exhaustion; a failed
  manual retry stays in `needs_attention` and never starts a new automatic
  chain.
- **B — count only recorded failures.** Automatic work stops after the eighth
  failure transition. This preserves more cleanup opportunities after manual
  work, but a process repeatedly dying while holding a lease never increments
  `failure_count` and can therefore be reclaimed automatically without a hard
  attempt bound.
- **C — add durable claim provenance and a separate automatic counter.** Store
  claim mode/counters so only synchronous/automatic claims consume the
  unattended budget while manual work does not. This models every distinction,
  but requires a schema version/migration and new crash-state invariants before
  the first runner can ship.

**Why A:** it satisfies “up to eight” with the existing schema, bounds crashes
as well as handled failures, and fails toward explicit operator control. Its
intentional cost is that manual intervention before exhaustion can reduce the
remaining automatic attempts. Failures of claims 1 through 8 select the eight
R3 delays in order and schedule claims 2 through 9; failure of claim 9 reaches
`needs_attention`.

Manual claim remains available from `armed`, `retry_wait`, `needs_attention`
and expired `running`, matching I1. Any observed manual failure goes directly
to `needs_attention`, irrespective of the remaining automatic budget; manual
success deletes a never-failed transient arm or resolves a previously failed
record. If the manual process dies without a transition, the indistinguishable
expired `running` lease becomes automatically reclaimable subject to the same
nine-claim ceiling. Specifically, expired `running` with `attempt_count >= 9`
is conditionally moved to `needs_attention` without another cleanup; this also
covers a crashed manual retry after exhaustion. An `armed` row left before its synchronous claim uses claim
1 when first recovered automatically, preserving at most nine total unattended
executions rather than inventing an extra slot. All exhaustion/lease
transitions remain conditional on the current lease so a stale worker cannot
overwrite a replacement owner.

**Compatibility, rollback, and affected tasks:** A requires no I1 schema
migration. Changing it later to provenance-aware C would require an explicit
schema/version migration and revised attempt reporting. B18-02, B18-06 and
their exhaustion/crash QA are governed by this approved contract; clock, lease
duration, the eight delay values and retention cutoff do not depend on it.

Rolling A back stops automatic claims but leaves all rows and counters valid
under I1; no counter is decremented and no unresolved row is deleted. A later
compatible runner can resume from the retained status. B also avoids a schema
migration but cannot guarantee bounded crash reclaims; C cannot be rolled back
to schema v1 until every v2 provenance field has an explicit downgrade policy.

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

## Planned executable implementation slices

The following slice order implements approved R1—R6. B18-01 through B18-07 and
B18-Q are completed; B18-08 remains deferred. HOOK-03 / DB-04-I3 and
LIFE-HOOK-01 are complete. DB-04-Q is decomposed as Batch 21. Changing an
approved choice requires a new decision and revised plan before affected code
starts.

### DB-04-I1 — shared repair ledger and schema lifecycle

Status: `completed` in PR #99; exact candidate `14abfd5` and merge `c2f4b98`
passed independent review and 19/19 protected/post-merge checks.

Scope: shared site table `<site-prefix>wpconnections_repair`, site-scoped
ownership/version preflight, repository, deterministic arm/upsert, conditional
lease claim, status transitions, and retention queries.

Out of scope: WordPress callback migration, scheduler activation, REST/admin UI,
and destructive automatic uninstall.

DoD:

- Schema is verified before automatic callback activation.
- Concurrent arm calls create one logical record.
- Claim/lease/reclaim behavior is deterministic on MySQL and MariaDB.
- No unresolved state is purged automatically.
- Ledger failure is attributable and occurs before connection DML.

### DB-04-I2 — retry engine, WP-Cron adapter, and operator service

Status: `completed` in PR #102; exact candidate `4c18fde` and merge `66f6fd3`
passed independent review and 19/19 protected/post-merge jobs. Batch 18
delivered the clock/backoff/lease/retention policy, unified cleanup executor,
dormant current-site Client registry, eligible ledger queries, bounded worker,
public operator and dormant scheduler gateway under approved R4/R5/R6.

Batch 18 decomposition baseline:
`40a36c3a2512234e0d61db564dfb1e833a8ec5d2`.

Scope: state machine, clock/scheduler abstractions, single site wake-up,
backoff/exhaustion/retention, Client runtime registry and PHP operator service.
The optional WP-CLI bridge is deferred from Batch 18.

Out of scope: `deleted_post` callback replacement, REST/admin UI, and an Action
Scheduler dependency under R3/A.

DoD:

- Scheduler failure cannot lose a ledger record.
- Duplicate workers cannot hold two live claims for one repair.
- Missing Client or adapter mismatch produces no mutation and remains visible.
- Automatic and manual execution use one cleanup path.

### HOOK-03 / DB-04-I3 — manager-backed recovery delivery

Status: `completed` in Batch 19 / PR #104; exact candidate
`ecc9d45b92fb39e9a2e0c8a5106b1f4a3d457737` received three independent PASS
results, PR head passed 20/20 protected checks, and exact merge
`7cfe684a08e2ce1e8ba5f3dec1b6f9525f1d6e01` passed 20/20 post-merge jobs.

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

Status: `in_progress` as Batch 21; B21-01—B21-04 are complete and B21-05 is
the next selected task. B21-06 and B21-07 are also dependency-ready for their
shared later review group. I1—I3, LIFE-HOOK-01 and all approved gates are
complete. No open decision gate exists at entry.

Scope: end-to-end `wp_delete_post()` behavior, failure/crash/concurrency matrix,
true multisite lane, pinned vendors, docs, runbook, and independent QA.

Out of scope: new public contracts beyond the approved R1–R6 surface and
exactly-once callback claims.

DoD:

- The complete `DG-DELETE-06/A` promise is demonstrated through the real hook.
- Consumer responsibility, degraded cron operation, repair listing/retry,
  retention, and uninstall behavior are documented and tested.
- DB-04 closes only after exact candidate, protected checks, merge, and
  post-merge evidence.

Executable decomposition:

The current suite is not empty. Real `wp_delete_post(..., true)` coverage
already proves semantic disable/enable plus one successful incoming/to-end cleanup
with no repair, one legacy outgoing row plus metadata cleanup, one
true-multisite active-site cleanup without site-A mutation, and one
default-storage connection-delete failure with rollback plus a `retry_wait`
record. Those tests are retained as baseline, not counted as the complete
incoming/outgoing/self, multi-relation/multi-Client, attachment/trash,
failure/crash and operator matrix below. The named inventory is recorded in
the Batch 21 entry criteria.

1. B21-01 freezes the real-flow fixture and complete verification manifest.
2. B21-02 proves the incoming/outgoing/self, relation, metadata, Client,
   attachment and trash/permanent-delete matrix.
3. B21-03 qualifies pre-commit, arm/readiness and scheduler failures.
4. B21-04 qualifies post-commit uncertainty and all three simulated crash
   windows: after durable arm before claim (`armed`), during cleanup
   (`running` until lease expiry), and after commit before resolve (`running`
   with cleanup possibly already durable).
5. B21-05 closes concurrency, lease, delay, exhaustion, manual and retention
   evidence without duplicating sufficient component tests.
6. B21-06 and B21-07 separately qualify true-multisite routing and custom
   adapter conformance.
7. B21-08 and B21-09 deliver the named operator runbook and rollback/uninstall
   preservation rehearsal without destructive automation.
8. B21-10 and B21-Q run the exact vendor/CI matrix, independent reviews,
   protected merge and post-merge verification.

The canonical task-level DoR, DoD and dependencies are in
[`docs/plans/02-library-hardening.md`](plans/02-library-hardening.md). Batch 21
adds no public API, WP-CLI/admin/REST operator surface, schema migration,
automatic site switching or release tag.

## DB-04-Q verification manifest baseline

B21-01 turned this baseline into the active
[`deleted-post-repair-verification-manifest.md`](deleted-post-repair-verification-manifest.md).
Every row must end with named test, lane, command and result; a retained test
is evidence only for the observation it actually makes. The baseline below is
kept as a compact contract index; the active manifest owns exact status and
evidence.

| Contract area | Retained named evidence | Missing observation / owner |
| --- | --- | --- |
| Real success cascade | `ClientIsolationTest::test_semantic_post_deletion_lifecycle_controls_real_cleanup_once`, `EntityValidationTest::test_deleted_post_cascade_cleans_legacy_row_without_endpoint_resolution` (single-site WP); the former is incoming/to-end, the latter outgoing/from-end with metadata | B21-02 completed at `ac6361f`; self, multi-relation, multi-Client, attachment/trash and isolation evidence is exact in the active manifest |
| Pre-commit recovery | `AtomicMutationTest::test_deleted_post_callback_uses_atomic_delete_boundary` (single-site WP) plus DB-05 atomic fault tests | B21-03 completed at `01daf04`; selector/meta/connection, transaction, attempt-hook, ledger-arm, scheduler and rollback-uncertainty evidence is exact in the active manifest |
| Commit/crash uncertainty | `DeletedPostRepairExecutorTest::test_post_commit_hook_failure_retries_committed_cleanup_and_zero_resolves_uncertainty` (WP executor) | B21-04 completed at `7587271`; real hook plus simulated durable states cover arm-before-claim, live/expired cleanup lease, commit-before-resolve, duplicate delivery and idempotent no-match convergence |
| Concurrency and time | `DeletedPostRepairLedgerTest::test_two_database_contenders_cannot_both_acquire_one_live_lease`, `test_automatic_claim_ceiling_moves_expired_ninth_claim_to_attention_without_a_tenth`, `test_manual_due_claim_bypasses_ceiling_but_not_future_or_attention_state`; policy `test_retry_delay_table`; worker `test_retention_runs_only_beyond_strict_boundary_and_uses_same_batch_bound` | bind the same real-flow identity from initial failure through retry/resolution and record every retained lane → B21-05 |
| Multisite context | `ClientIsolationTest::test_default_storage_is_prefix_bound_and_fresh_client_uses_new_prefix` (true multisite, one active-site incoming cleanup) plus direct-hook migration tests | real active/inactive/restored same-name/same-ID matrix → B21-06 |
| Adapter conformance | hook-migration non-atomic zero-write and persisted custom-failure tests; ledger adapter-fingerprint mismatch test | custom atomic success/failure/no-match and missing-client/fingerprint outcomes through real deletion → B21-07 |
| Operator/degraded cron | scheduler `test_dispatch_availability_reports_disabled_wp_cron_without_affecting_storage_api`; service list/retry/batch tests | executable disabled/late-cron procedure and named `docs/deleted-post-repair-operations.md` → B21-08 |
| Rollback/uninstall | current upgrade guide preserves ledger table/ownership option | repeatable preservation rehearsal, operational evidence and no-destructive-automation source audit → B21-09 |
| Vendor/infrastructure | existing true-multisite and pinned MySQL/MariaDB workflows | completed manifest on one exact candidate, all required lanes and critical-scenario mapping → B21-10/Q |

## Required verification matrix

### Real deletion and data effect

- [B21-02] Incoming, outgoing, self-connection, multiple relation, and metadata cases.
- [B21-02] Multiple Clients delete only their own rows.
- [B21-02] Permanent post and attachment deletion; trash-only flow does not run the
  permanent-delete cascade.
- [B21-02] Successful first attempt leaves no repair record.

### Failure and commit windows

- [B21-03] Selector read, metadata delete, connection delete, transaction start, commit,
  and rollback failures.
- [B21-03/B21-04] Storage attempt hook and post-commit success-hook `Throwable`.
- [B21-04] Simulated death after durable arm before claim (`armed`), during
  cleanup (`running` until lease expiry), and after cleanup commit before
  resolve (`running` with cleanup possibly already durable).
- [B21-03] Ledger-arm and scheduling failure are distinct.
- [B21-04] Repeated cleanup no-match resolves commit-before-status uncertainty.

### Concurrency and time

- [B21-04/B21-05] Duplicate `deleted_post` delivery deduplicates one logical identity.
- [B21-05] Two workers race for one record; only one live lease succeeds.
- [B21-05] Expired lease reclaim, all backoff steps, exhaustion, manual recovery, and
  resolved retention through a fake clock.

### Context and adapters

- [B21-06] Same Client name and numeric post ID on two real multisite blogs.
- [B21-06] Active, inactive, and restored site context.
- [B21-07] Missing fresh Client and adapter-fingerprint mismatch.
- [B21-07] Default storage; custom atomic adapter success/failure/no-match; custom
  non-atomic adapter capability failure with zero writes.

### Vendor and infrastructure

- [B21-10] Full ledger/claim/concurrency suite on pinned MySQL 8.0.46 and MariaDB
  10.11.16 without vendor-only SQL.
- [B21-10] Dedicated `WP_MULTISITE=1` CI lane. A test that skips when `!is_multisite()`
  is not evidence for the multisite acceptance criteria.
- [B21-08/B21-10] WordPress's CLI test bootstrap disables cron loopback, so tests assert stored
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
