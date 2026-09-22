# WordPress hook lifecycle transition

Status: executable staged plan; Batch 19 manager-backed deletion and Batch 20
Client disposal completed, release-wide migration remains downstream

Baseline: `master` merge `a3491c018b96b54dc03e55a850153ddc0e6db413`
(Batch 20 / LIFE-HOOK-01, PR #106).

Manager release: `hokoo/wp-hooks-dispatcher` `v1.0.1`, commit
`7f449c41bd73fb40ce80ad790daeaa71fd253e36`.

Decision date: 2026-09-11; package coordinates confirmed 2026-09-12.

## Purpose

This document turns the approved DG-NAME-06R A-to-D direction into separate,
reviewable delivery steps and records the approved standalone package choice.
The historical 1.x bridge remains documented below. Batch 19 implements the
approved 2.0 deletion boundary, and Batch 20 composes it with REST ownership
into the final Client lifecycle without claiming the 2.0 release complete.

The fixed ownership rule is:

- a consumer creates and initializes a separate `Client` for every WordPress
  site context it uses;
- a default `WPStorage` instance stays bound to its construction prefix;
- direct use of that instance after `switch_to_blog()` throws the stable prefix
  `ClientRegisterFail`;
- process-global WordPress hook delivery must not let an inactive-site client
  execute current-site storage work.

## Delivered foundation: compatible 1.x bridge

CORE-06R retains the concrete callback
`[$client->getStorage(), 'deleteByObjectID']` on `deleted_post` at priority 10.
An inactive-prefix `WPStorage` callback returns before wpConnections hooks or
SQL, while a current-site client performs its normal cascade. Existing 1.x
consumer code can still remove that exact callback with `remove_action()`.

This bridge intentionally cannot distinguish the registered callback from a
manual stale `deleteByObjectID()` call made inside another `deleted_post`
callback. Both fail closed. That limitation ends only when the callback itself
is owned by a context-aware subscription.

## Delivered: semantic 1.x lifecycle API

HOOK-TRANS-01 adds these public commands to `Client`:

```php
public function enablePostDeletionCleanup(): void;
public function disablePostDeletionCleanup(): void;
```

The exact 1.x contract is:

1. Construction remains auto-enabled; there is no constructor flag and no
   behavior change for consumers that do nothing.
2. Enable and disable are idempotent.
3. Enable registers the same concrete storage callback at priority 10 with one
   accepted argument. It does not introduce a closure, proxy, token or manager.
4. Disable removes that exact callback at priority 10.
5. A legacy direct `remove_action()` remains effective throughout 1.x, and a
   later semantic enable restores the library-owned registration.
6. The methods return `void`; hook-registry internals and subscription handles
   do not become part of the 1.x public API.
7. No runtime deprecation notice is emitted in 1.x. Documentation identifies
   direct callback manipulation as a migration concern for 2.0.

The task does not change stale-prefix handling, cascade SQL, storage hooks,
factory behavior, custom-storage semantics or any REST representation.

## Delivered: selection and complete hook audit

Batch 8 contains two independent documentation/research tasks after the 1.x API
merges:

- HOOK-00 evaluates maintained Composer candidates against the target contract
  and prepares DG-HOOK-01. If no candidate satisfies the contract without a
  compatibility fork, the preferred fallback is a separately published small
  library owned by this project. Its current source-linked evidence and option
  analysis are in the
  [manager selection packet](hook-manager-selection.md).
- HOOK-02 inventories every hook registration owned by `Client`, its REST API,
  settings and logger collaborators. Its
  [audit artifact](client-owned-hook-inventory.md) finds five action
  subscriptions under `WP_DEBUG`, separates deletion, REST, logging and Client
  lifetime work, and prepares six integration decision gates plus the manager
  scope gate. It does not assume all hooks need the manager.

These tasks produced separate reviewable artifacts. Their decision-recording
follow-up selects package coordinates but installs no dependency.

<a id="dg-hook-01"></a>
## DG-HOOK-01 — manager source and package boundary

**Status:** approved B by the repository owner on 2026-09-11.

**Problem:** the 2.0 behavior is approved, but the implementation source is not.
Adopting an unsuitable abstraction could add more compatibility risk than the
small manager removes.

- **A — adopt an existing package:** select a maintained, permissively licensed
  Composer package only if its public contract covers callback identity,
  priority, accepted arguments, deterministic order, context predicates,
  idempotent unsubscribe and exception propagation on PHP 8.1+.
- **B — publish a focused standalone package:** build the smallest reusable
  manager under project ownership when no existing package passes the matrix.
  The library must not depend on wpConnections domain classes.
- **C — implement internally:** keep the manager in wpConnections only when
  package/distribution overhead is shown to outweigh reuse and independent
  lifecycle testing.

**Selection rule:** prefer A when a candidate passes every mandatory criterion
without a compatibility fork; otherwise prefer B. C is the documented fallback,
not an implicit default.

**Evidence result:** no current candidate passes every mandatory criterion.
Option B was approved; see the
[source-linked candidate matrix](hook-manager-selection.md). Delivery uses the
standalone package boundary `hokoo/wp-hooks-dispatcher` /
`iTRON\wpHooksDispatcher\`. No dependency is installed by this
decision-recording change.

**Required evidence:** primary-source version/license/maintenance data, supported
PHP range, dependency footprint, a contract-gap matrix, a minimal integration
probe, release/ownership implications and a rollback path.

## Delivered standalone manager

HOOK-01 published <https://github.com/hokoo/wp-hooks-dispatcher> under the
approved `iTRON\wpHooksDispatcher\` namespace. Package PR #1 final head
`2c3c88f` and merge `ca0040f` passed independent QA plus `5/5` protected and
post-merge jobs. README clarity PR #2 final head `1b1e59a` and merge `7f449c4`
also passed independent QA and `5/5` protected/post-merge jobs.

GitHub exposes `v1.0.1` as an immutable release after repository-level release
immutability was enabled and the existing release was republished in place;
Packagist resolves that version to the exact `7f449c4` dist. A clean PHP 8.1
Composer project loaded
`iTRON\wpHooksDispatcher\ActionDispatcher`, and reported no advisories. The
package has no Composer runtime dependencies beyond PHP `^8.1`; its native
adapters consume the loaded WordPress runtime. REST-HOOK-01 is the first
wpConnections integration and pins the compatible runtime range `^1.0.1`.

## 2.0 target contract

The selected manager or adapter must provide:

- explicit subscription and idempotent unsubscribe;
- captured site identity and storage prefix checked at dispatch time;
- exact hook name, priority and accepted-argument count;
- deterministic registration order for equal priorities;
- no execution in a mismatched context;
- no swallowing or rewriting of failures from an active callback;
- no global interception or replacement of WordPress's hook registry;
- no dependency on a mutable current client singleton.

The first integration target is `deleted_post`. Other hooks move only when the
HOOK-02 audit demonstrates a site-context problem and defines compatibility.

The audit found that wpConnections owns no filter subscriptions. Its
[DG-HOOK-SCOPE-01/A](client-owned-hook-inventory.md#dg-hook-scope-01) selects an
action-only first stable manager release. REST registration has a second global
route registry and consumes approved
[DG-HOOK-REST-01](client-owned-hook-inventory.md#dg-hook-rest-01),
[DG-HOOK-REST-02](client-owned-hook-inventory.md#dg-hook-rest-02) and
[DG-HOOK-REST-03](client-owned-hook-inventory.md#dg-hook-rest-03), while the
custom REST factory boundary consumes approved
[DG-HOOK-REST-04](client-owned-hook-inventory.md#dg-hook-rest-04). Native
validation-before-permission precedence consumes approved
[DG-HOOK-REST-05](client-owned-hook-inventory.md#dg-hook-rest-05). Automatic
debug logging needs a singleton origin-routed observer and documented custom
Storage payload under approved
[DG-HOOK-LOG-01](client-owned-hook-inventory.md#dg-hook-log-01), and complete
Client subscription lifetime follows approved
[DG-HOOK-LIFE-01](client-owned-hook-inventory.md#dg-hook-life-01). The
repository owner approved the initial options on 2026-09-11 and
DG-HOOK-REST-05/A on 2026-09-12.

## Delivered Batch 19 manager-backed deletion

HOOK-03 / DB-04-I3 now composes one process runtime, an exact-context Client
registry, one manager-backed `deleted_post` subscription per enabled Client and
one zero-argument repair cron subscription per initialized site runtime. A
Client is registered only after its public `inited` actions finish; an early
semantic disable is retained and a failed initialization leaves no repair
owner or callable subscription.

The callback durably arms and claims the repair identity before cleanup,
executes through the shared atomic repair executor, and reconciles the
site-local wake-up afterwards. An ordinary persisted cleanup failure returns
from that Client callback so later equal-priority Clients still run. Ledger or
context uncertainty remains fail-closed and propagates.

Exact Batch 19 candidate `ecc9d45b92fb39e9a2e0c8a5106b1f4a3d457737`
received exact verification, independent QA and security/test-integrity PASS
without open P0—P3 findings or new decision gates. PR #104 passed 20/20
protected checks, merged as `7cfe684a08e2ce1e8ba5f3dec1b6f9525f1d6e01`,
and that exact merge passed 20/20 post-merge jobs.

## 2.0 breaking-change and upgrade boundary

In the Batch 19 manager-backed runtime, this legacy operation no longer works:

```php
remove_action(
    'deleted_post',
    [ $client->getStorage(), 'deleteByObjectID' ]
);
```

That is a deliberate next-major break. The upgrade path is to use
`Client::disablePostDeletionCleanup()` before upgrading and keep using the
semantic method after upgrading. The focused
[deleted-post upgrade guide](deleted-post-cleanup-upgrade.md) records the
current fixture and rollback boundary. HOOK-04 must still place this warning in
the release changelog and perform the known-consumer scan.

Custom REST subclasses have a separate deliberate 2.0 boundary. An overridden
`init()` must call `parent::init()`; `$namespace`, `$base`, permission methods
and built-in handlers remain delegate extension points. The library owns the
four built-in route registrations, so an overridden `registerRestRoutes()` is
not called automatically. Extra hooks or routes created by a subclass remain
the implementer's context and lifecycle responsibility. HOOK-04 must include
this check in the consumer upgrade scan.

## Delivered Batch 20 Client lifecycle

LIFE-HOOK-01 adds public, idempotent `Client::dispose(): void`. The Client is
marked terminal before teardown; then its deleted-post activation and REST
mapping are revoked in reverse acquisition order. Constructor failure uses the
same path. Reentrant disposal during custom REST initialization, route owner
publication, repair-ledger readiness or cleanup re-enable is checked again
before an ownership handle can escape.

Disposal is terminal only for library-owned WordPress integrations. Existing
direct Client, Relation and Storage references keep their established domain,
validation and site-prefix behavior. Repeated disposal, semantic cleanup
disable and REST deactivation are harmless; cleanup enable and REST
activation/rebind fail with `ClientRegisterFail` code `4` and stable reason
`Client integrations have been disposed and cannot be reactivated.`

The shared dispatcher, REST route boundaries, site repair runtime and debug
observer may outlive one Client, but their registries no longer retain or
resolve it. PHP destruction is not a lifecycle guarantee. Consumers remain
responsible for explicitly disposing each Client they retire in each site
context; custom subclass-owned hooks/routes are outside this library-owned
boundary.

## Delivery map

| Phase | Task | Deliverable | Gate/dependency | Status |
| --- | --- | --- | --- | --- |
| 1.x safety | CORE-06R | Prefix-bound storage and compatible stale callback no-op | DG-NAME-06R | completed, PR #76 |
| 1.x transition | HOOK-TRANS-01 | Idempotent semantic cleanup enable/disable API | CORE-06R, DG-NAME-06R | completed, PR #77 |
| 2.0 discovery | HOOK-00 | Build-versus-buy evidence and selection packet | HOOK-TRANS-01 | completed, PR #78 |
| 2.0 discovery | HOOK-02 | Complete Client-owned hook inventory and migration map | HOOK-TRANS-01 | completed, PR #79 |
| Manager supply | HOOK-01 | Publish `hokoo/wp-hooks-dispatcher` | DG-HOOK-01/B, DG-HOOK-SCOPE-01/A, HOOK-00 | completed, `v1.0.1` |
| 2.0 logging | LOG-HOOK-01 | Singleton origin-routed automatic debug logging | HOOK-02, DG-HOOK-LOG-01/B, DG-SPI-06/A | completed, PR #82 |
| 2.0 deletion | HOOK-03 / DB-04-I3 | Manager-backed recovery coordinator and `deleted_post` tests | HOOK-01, HOOK-02, DB-04-I1/I2, DG-DELETE-06R1 | completed, PR #104 / `7cfe684` |
| 2.0 REST | REST-HOOK-01 | Context-safe hook plus REST route lifecycle | HOOK-01, REST-01, DG-HOOK-REST-01—05, DG-RESTERR-03 | completed, PR #83 / `33b659e` |
| Client lifetime | LIFE-HOOK-01 | Final disposal and failed-init rollback across migrated integrations | HOOK-03, REST-HOOK-01, LOG-HOOK-01, DG-HOOK-LIFE-01 | completed, PR #106 / `a3491c0` |
| 2.0 release | HOOK-04 | Consumer scan, upgrade guide and compatibility verification | DB-04-Q, HOOK-03, REST-HOOK-01, LOG-HOOK-01, LIFE-HOOK-01, REL-02, REL-03 | waiting dependency |

Each implementation task has its own branch, independent QA, rollback point and
protected-check run. HOOK-01 is delivered independently and installs no
wpConnections dependency. LOG-HOOK-01 completed on exact candidate `234216e`
with independent QA PASS and 17/17 protected checks; it changes logging only.
Batch 19 supplies the manager-backed deletion registrations. Batch 20 composes
cross-integration disposal and rollback; release-wide consumer
migration remains in HOOK-04.
REST-HOOK-01 is the completed task in Batch 10 and first wpConnections runtime
consumer of `hokoo/wp-hooks-dispatcher`. DG-HOOK-REST-05/A is approved; task
scope remains limited to the REST integration boundary. Implementation commit
`9d5b74e` received independent QA PASS; docs-only final head `7e1addc` passed
independent closure QA and all 17 protected checks. PR #83 merged as `33b659e`,
and all 17 post-merge checks passed on that exact `master` commit. Batch 10 is
closed.

## Verification matrix

HOOK-TRANS-01 must prove:

- constructor auto-registration remains the exact legacy callback at priority
  10;
- repeated disable is harmless and leaves the callback absent;
- repeated enable produces one effective callback;
- disabled cleanup leaves an otherwise matching connection untouched;
- re-enabled cleanup removes the matching connection exactly once;
- direct legacy removal still works, followed by semantic re-enable;
- current CORE-06R stale/fresh multisite tests remain green.

Batch 19 verification includes:

- active, inactive and restored site dispatch for each manager-backed hook;
- same-name clients on multiple sites;
- equal-priority deterministic ordering and accepted-argument forwarding;
- unsubscribe during and outside dispatch as defined by the selected manager;
- active callback exception propagation;
- retained, idempotently revocable deletion subscription ownership that the
  final Client lifecycle can collect without reconstructing callback identity;
- upgrade-path and known-consumer fixtures for the direct `remove_action()`
  break.

B19-06 commit `6fda0b9` supplies the dedicated `WP_TESTS_MULTISITE=1` lane and
the focused active/inactive/restored `deleted_post` and cron proofs. Final exact
candidate `ecc9d45` passed unit `139/509`, current single-site `476/4152` with
six expected skips, true multisite `476/4183`, pinned MySQL 8.0.46 and MariaDB
10.11.16 at `476/4152` with six expected skips each, PHPCS `100/100`, and
fixed-floor coverage `3783/4136` statements (`91.47%`). Both candidate and
exact merge received complete 20/20 protected/post-merge evidence.

Batch 20 verification adds:

- idempotent public disposal and same-identity replacement;
- terminal cleanup enable and REST activation/rebind failures with one stable
  `ClientRegisterFail` contract;
- disposal during custom REST init, published route registration, repair
  readiness and cleanup re-enable;
- no stale REST/deleted-post delivery while direct domain references remain
  usable;
- `WeakReference` proof that library registries do not retain a disposed
  Client;
- neighboring and same-name multisite Client isolation.

The corrected Batch 20 production head `a022c8e` passes unit `141/518`, full
single-site integration `488/4271` with eight expected multisite skips, full
true multisite `488/4314`, focused lifecycle single-site `12/119` with two
expected multisite skips, focused true-multisite lifecycle `12/131`, and PHPCS
`100/100`. Fixed-floor combined coverage passes the RC policy at `629/4787`
with `3825/4172` statements (`91.68%`). Isolation seed `20260922` passes unit
reverse/random repeat-2 at `282/1036` each and WordPress reverse/random at
`976/8542` each with 16 expected skips. Pinned MySQL 8.0.46 and MariaDB
10.11.16 each pass `488/4271` with eight expected skips; the synthetic quality
tools pass. A final lifecycle review found that a failing reentrant repair
re-enable could recreate a revoked owner or mutate its replacement; commit
`a022c8e` adds two red-first token/ABA regressions and limits catch rollback to
the original owner. Exact candidate
`05284155d803eb02c5c89e2052928f9d60807734` received three independent PASS
results without open P0—P3 findings or a new decision gate. PR #106 passed
20/20 protected checks, merged as
`a3491c018b96b54dc03e55a850153ddc0e6db413`, and that exact merge passed all
20 post-merge jobs. B20-Q and LIFE-HOOK-01 are complete; Batch 21 / DB-04-Q is
the next recovery qualification.

DB-04-D discovered that generic repair must surround the Storage call, while
HOOK-03 previously waited for the whole DB-04 task. The dependency map is now
explicit in the
[`deleted-post repair contract`](deleted-post-repair-contract.md): under
approved DG-DELETE-06R1/A, ledger/retry core I1/I2 precedes HOOK-03/I3, and
DB-04-Q closes the real flow afterwards. This approval does not activate a
repair callback in 1.x.

REST-HOOK-01 verification evidence on PHP 8.1.34 / WordPress 6.7.7 / Ramsey
Collection 1.3.0 is:

- full unit `13 / 61` and integration `124 / 1369`;
- full reverse and fixed-seed random isolation repeat-2 each: unit `26 / 122`
  and integration `248 / 2738`;
- true multisite managed REST lifecycle `11 / 590`;
- combined coverage `137 / 1428`, `1206/1328` statements (`90.81%`), with PR
  and 70% RC policies passing;
- PHPCS `51/51`, Composer locked install and advisory audit passing.

The suite covers four route patterns, twelve method/callback combinations,
same-name site A/B routing through one server, custom delegate identity and
overrides, duplicate/replacement, late binding, repeated initialization,
constructor rollback, and DG-HOOK-REST-05/A native 400/404 precedence without
stale Client callbacks. It also freezes WordPress common route-argument
inheritance, namespace/path normalization and handler-stage ABA revalidation.
Independent QA repeated the matrix on exact head
`9d5b74e421661061249bdb790fae79f4fe87a337` and returned unconditional PASS.
Newest PHP 8.5.10 / WordPress 7.1 / Ramsey Collection 2.1.1 passed integration
`124 / 1369` with only pre-existing deprecations. All 17 PR #83 protected
checks passed on final head `7e1addc686050993658fbf3a46a4d4ec2f4664c2`.
PR #83 merged as `33b659e20b69a0e3c5b94bdc508a9a88a9c25c43`; all 17
post-merge checks passed on that exact `master` commit.

## HOOK-TRANS-01 verification evidence

The implementation was developed test-first: the focused suite first failed
with two undefined-method errors (`15` tests, `135` assertions), then passed
after the minimal production change. Local verification on 2026-09-11 is:

- current PHP 8.1.34 / WordPress 7.1 / Ramsey Collection 1.3.0: unit
  `12 / 58`, integration `108 / 756`;
- fixed floor PHP 8.1.34 / WordPress 6.7.7 / Ramsey Collection 1.3.0: unit
  `12 / 58`, integration `108 / 756`;
- true multisite focused lifecycle/isolation suite: `15 / 159`;
- deterministic isolation seed `20260911`, reverse/random repeat-2: unit
  `24 / 116`, integration `216 / 1512`;
- fixed-floor combined coverage: `120 / 814`, `968/1070` statements
  (`90.47%`), with exact legacy baseline `365/786` (`46.44%`); both PR and
  70% RC policies pass;
- newest compatibility PHP 8.5.10 / WordPress 7.1 / Ramsey Collection 2.1.1:
  integration `108 / 756`, with only pre-existing deprecations;
- PHPCS `45/45` and the coverage, exception-policy and isolation quality-tool
  synthetic checks pass.

Independent QA independently repeated the focused, current, fixed-floor, true
multisite, isolation, coverage-policy, newest-compatibility and PHPCS checks on
head `632da3aae45dbc6091ba3e098eff915b2136beae`, and returned an unconditional
PASS with no findings. PR #77 passed all 17 protected jobs on closure head
`1e98cf70e7b2bb74b1cb66e8c0c960329c652c76`, was merged as
`5c2fc26289a1ee2ae55d11967c15b2562093442c`, and all 17 post-merge jobs passed.
HOOK-TRANS-01 and Batch 7 are complete.

## Rollback

HOOK-TRANS-01 is additive. Reverting it restores constructor-owned direct
registration without altering stored data. The Batch 19 rollback plan preserves
the site-local repair ledger and ownership option; it does not silently return
unresolved work to the 1.x callback bridge. Before release, Batch 20 can be
rolled back by removing public disposal and its terminal guards together while
retaining constructor-failure cleanup. See the
[deleted-post upgrade guide](deleted-post-cleanup-upgrade.md). A public 2.0
release still requires HOOK-04's consumer scan and rollback rehearsal.
