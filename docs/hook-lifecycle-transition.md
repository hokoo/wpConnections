# WordPress hook lifecycle transition

Status: executable staged plan; 1.x transition API in independent review

Baseline: `master` merge `2371ed2f3ae01f3e2d589553cff3b79944e94981`
(CORE-06R, PR #76).

Decision date: 2026-09-11.

## Purpose

This document turns the approved DG-NAME-06R A-to-D direction into separate,
reviewable delivery steps. It does not make a package-selection decision and it
does not move the 2.0 manager into the 1.x compatibility release.

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

## Current delivery: semantic 1.x lifecycle API

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

## Next delivery: selection and complete hook audit

Batch 8 contains two independent documentation/research tasks after the 1.x API
merges:

- HOOK-00 evaluates maintained Composer candidates against the target contract
  and prepares DG-HOOK-01. If no candidate satisfies the contract without a
  compatibility fork, the preferred fallback is a separately published small
  library owned by this project.
- HOOK-02 inventories every hook registration owned by `Client`, its REST API,
  settings and logger collaborators. It records registration context,
  unregisterability, site sensitivity and 2.0 migration action. It does not
  assume all hooks need the manager.

These tasks may be researched in parallel but produce separate reviewable
artifacts. No dependency is selected or installed before DG-HOOK-01 is approved.

<a id="dg-hook-01"></a>
## DG-HOOK-01 — manager source and package boundary

**Status:** pending evidence and repository-owner decision.

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

**Required evidence:** primary-source version/license/maintenance data, supported
PHP range, dependency footprint, a contract-gap matrix, a minimal integration
probe, release/ownership implications and a rollback path.

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

## 2.0 breaking-change and upgrade boundary

When HOOK-03 enables manager-backed delivery, this legacy operation is no
longer guaranteed to work:

```php
remove_action(
    'deleted_post',
    [ $client->getStorage(), 'deleteByObjectID' ]
);
```

That is a deliberate next-major break. The upgrade path is to use
`Client::disablePostDeletionCleanup()` before upgrading and keep using the
semantic method after upgrading. HOOK-04 must place this warning prominently in
the changelog and upgrade guide and must search known consumers for the direct
callback pattern.

## Delivery map

| Phase | Task | Deliverable | Gate/dependency | Status |
| --- | --- | --- | --- | --- |
| 1.x safety | CORE-06R | Prefix-bound storage and compatible stale callback no-op | DG-NAME-06R | completed, PR #76 |
| 1.x transition | HOOK-TRANS-01 | Idempotent semantic cleanup enable/disable API | CORE-06R, DG-NAME-06R | review |
| 2.0 discovery | HOOK-00 | Build-versus-buy evidence and selection packet | HOOK-TRANS-01 | waiting dependency |
| 2.0 discovery | HOOK-02 | Complete Client-owned hook inventory and migration map | HOOK-TRANS-01 | waiting dependency |
| Manager supply | HOOK-01 | Selected/adapted or separately published manager | DG-HOOK-01, HOOK-00 | waiting decision |
| 2.0 integration | HOOK-03 | Manager-backed context-safe registrations and tests | HOOK-01, HOOK-02, DB-04 | waiting dependency |
| 2.0 release | HOOK-04 | Consumer scan, upgrade guide and compatibility verification | HOOK-03, REL-02, REL-03 | waiting dependency |

Each implementation task has its own branch, independent QA, rollback point and
protected-check run. The current branch closes only HOOK-TRANS-01.

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

HOOK-03 must later add:

- active, inactive and restored site dispatch for each manager-backed hook;
- same-name clients on multiple sites;
- equal-priority deterministic ordering and accepted-argument forwarding;
- unsubscribe during and outside dispatch as defined by the selected manager;
- active callback exception propagation;
- upgrade-path and known-consumer fixtures for the direct `remove_action()`
  break.

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
PASS with no findings. Final-head protected checks and post-merge checks remain
open; their absence is why the task is `review`, not `completed`.

## Rollback

HOOK-TRANS-01 is additive. Reverting it restores constructor-owned direct
registration without altering stored data. HOOK-03 must retain its own
next-major rollback plan; it may not depend on silently returning to the 1.x
bridge after a public 2.0 release.
