# Context-aware hook manager selection

Status: completed research; DG-HOOK-01 is not approved

Repository baseline: `5c2fc26289a1ee2ae55d11967c15b2562093442c`
(HOOK-TRANS-01, PR #77).

Research snapshot: 2026-09-11.

## Outcome

No maintained Composer package found in the reproducible search satisfies the
approved 2.0 contract without replacing its central lifecycle model. The
recommendation for DG-HOOK-01 is therefore **B: publish a focused standalone
package owned by this project**.

This is a recommendation, not a decision. This branch installs no dependency,
creates no external repository and changes no runtime behavior. HOOK-01 remains
blocked until the repository owner explicitly resolves DG-HOOK-01.

## Contract being evaluated

The manager is not merely a nicer syntax for `add_action()`. It must own the
callback that WordPress dispatches and decide, on every dispatch, whether the
subscription's captured site is active. A candidate passes only if it provides
all of these properties on PHP 8.1:

1. an explicit subscription with idempotent unsubscribe;
2. a stable wrapper callback whose exact name, priority and accepted-argument
   count are retained for removal;
3. captured blog ID and database prefix, both checked at dispatch time;
4. no inactive-context call into the consumer callback;
5. native deterministic ordering among callbacks at the same priority;
6. transparent exception/error propagation from an active callback;
7. no replacement or global interception of the WordPress hook registry;
8. no mutable global "current client" dependency;
9. a maintained, permissively licensed distribution compatible with the
   wpConnections PHP `^8.1` floor.

The context requirement follows WordPress behavior: `switch_to_blog()` changes
the current blog ID and `$wpdb` mapping while already-loaded plugin objects and
their process-global hooks remain alive. See the official
[`switch_to_blog()` source](https://developer.wordpress.org/reference/functions/switch_to_blog/).

WordPress itself already supplies the correct low-level ordering and delivery
semantics. [`add_action()`](https://developer.wordpress.org/reference/functions/add_action/)
preserves priority, accepted arguments and insertion order for equal priorities;
[`remove_action()`](https://developer.wordpress.org/reference/functions/remove_action/)
removes the same callback at the same priority; and
[`WP_Hook::apply_filters()`](https://developer.wordpress.org/reference/classes/wp_hook/apply_filters/)
does not catch callback failures. The missing value is the owned, context-aware
subscription boundary.

## Reproducible search scope

The Packagist API was queried with `per_page=100` for the following terms:

| Query | Total results | Results inspected |
| --- | ---: | ---: |
| `wordpress hook` | 237 | first 100 |
| `wordpress event dispatcher` | 11 | all 11 |
| `wordpress hook manager` | 10 | all 10 |
| `wordpress hook registration` | 4 | all 4 |

The search was supplemented with packages named by repository and source-code
cross-references. Metadata came from Packagist package API responses; behavior
was checked at each release's immutable source reference. Popularity was not a
selection criterion.

Prescreen removed packages that require PHP 8.2+ or 8.5, only dispatch their own
non-WordPress event bus, have no unregister path, or have no meaningful release
or repository activity for several years. Important prescreen results are:

| Package | Snapshot | Prescreen result |
| --- | --- | --- |
| [`offsetwp/hook`](https://packagist.org/packages/offsetwp/hook) | 1.1.0, 2025-12, MIT, PHP `>=8.1` | Explicitly has no `remove_action()` or `remove_filter()` support; registration-only |
| [`n5s/wp-hook-kit`](https://packagist.org/packages/n5s/wp-hook-kit) | 1.0.0, 2025-12, MIT | Requires PHP `^8.2`, above the library floor |
| [`pollora/hook`](https://packagist.org/packages/pollora/hook) | 1.1.0, 2026-06, GPL-2.0-or-later | Requires PHP `^8.2`, above the library floor |
| [`sympress/event-dispatcher`](https://packagist.org/packages/sympress/event-dispatcher) | 1.0.1, 2026-06, GPL-2.0-or-later | Requires PHP `^8.5` and a `dev-main` runtime dependency |
| [`k-t-holland/hook-manager`](https://packagist.org/packages/k-t-holland/hook-manager) | 2.0.0, 2018-09, MIT | No current maintenance evidence |
| [`dbout/wp-hooks`](https://packagist.org/packages/dbout/wp-hooks) | 1.1.0, 2022-02, MIT | No current maintenance evidence |
| [`morningtrain/wp-hooks`](https://packagist.org/packages/morningtrain/wp-hooks) | 0.3.2, 2022-09, MIT | Old 0.x release plus framework loader dependency |
| [`amphibee/hookable`](https://packagist.org/packages/amphibee/hookable) | 1.0.0, 2022-01, MIT | No current maintenance evidence |
| [`calvinalkan/better-wordpress-hooks`](https://packagist.org/packages/calvinalkan/better-wordpress-hooks) | 0.1.8, 2021-05, GPL-2.0-or-later | Old pre-1.0 release with pinned framework dependencies |

OffsetWP's limitation is explicit in its immutable
[`README`](https://github.com/offsetwp/hook/blob/4ac1bc012a59f7c30b94120cdf4ea3d25f0d8a64/README.md#faq).

## Full candidate matrix

Legend: **pass** means the release directly supplies the mandatory behavior;
**partial** means useful primitives exist but the differentiating behavior must
still be built; **fail** means a mandatory property is absent or incompatible.

| Candidate | Distribution | Priority / args / native order | Owned subscription and exact unsubscribe | Dispatch-time site + prefix guard | Active failure propagation | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| [`tombroucke/wp-fluent-hooks`](https://packagist.org/packages/tombroucke/wp-fluent-hooks) 1.0.0 | No declared license, PHP `>=8.0`, no runtime dependencies; released 2026-06-19 | **pass**: wrapper registration retains priority and args | **partial**: alias tracks the wrapper's WordPress ID, but there is no subscription handle and removal directly mutates global `WP_Hook` state | **partial**: generic `when(callable)` runs per dispatch, but captured site/prefix semantics remain adapter code | **pass**: wrapper does not catch callback failures | **fail** |
| [`heybran/wp-hook-manager`](https://packagist.org/packages/heybran/wp-hook-manager) 0.2.0 | MIT, PHP `>=8.1`, no runtime dependencies; released 2026-08-15 | **pass**: direct native registration retains priority and args | **partial**: tracks callbacks and removes exact stored callbacks, but exposes a mutable static registry and returns booleans instead of a subscription handle | **fail**: no wrapper or context predicate | **pass** through native callback | **fail** |
| [`pinkcrab/hook-loader`](https://packagist.org/packages/pinkcrab/hook-loader) 1.3.0 | MIT, PHP `>=8.0`, no runtime dependencies; released 2026-04-19 | **pass**: `Hook` carries priority and args and manager registers directly | **fail**: no subscription handle; removal rejects closures and matches object callbacks by class + method rather than object identity | **fail**: admin/front is tested only before registration, not per dispatch | **pass** through native callback | **fail** |
| [`italystrap/event`](https://packagist.org/packages/italystrap/event) 0.2.1 | MIT, PHP `>=7.4`, runtime `psr/log ^1.1`; released 2024-07, repository updated 2025-10 | **pass**: direct add/remove with priority and accepted args | **partial**: subscriber add/remove exists, but `EventSubscription` is only a value object, not an active handle | **fail**: no dispatch predicate | **pass** through native callback | **fail** |
| [`snicco/better-wp-hooks`](https://packagist.org/packages/snicco/better-wp-hooks) 2.0.0-beta.9 | LGPL-3.0-only, PHP 7.4/8.x, two Snicco runtime packages plus `ext-json`; released 2024-09 | **fail**: mapping registers `accepted_args=9999`, not the requested exact value | **fail**: `map()` returns `void`; no unmap/subscription handle | **partial**: event classes have a general `shouldDispatch()` predicate | **partial**: delivery passes through another event dispatcher | **fail** |
| [`yard/wp-hook-registrar`](https://packagist.org/packages/yard/wp-hook-registrar) 2.0.1 | MIT, PHP `>=8.1`, no runtime dependencies; released 2025-10, repository updated 2026-08 | **pass**: native registration, explicit priority, accepted args derived from method reflection | **fail**: registration only; no removal or subscription | **fail**: no dispatch predicate | **pass** through native callback | **fail** |
| [`ssnepenthe/wp-event-dispatcher`](https://packagist.org/packages/ssnepenthe/wp-event-dispatcher) 0.1.0 | MIT, PHP 7.4/8.x, no runtime dependencies; only release 2023-11 | **fail**: accepted args are always `999` | **partial**: exact add/remove and subscriber removal, but no owned token | **fail**: no dispatch predicate | **pass** through native callback | **fail** |

### Why the closest candidates still fail

`tombroucke/wp-fluent-hooks` is the closest dispatch model found. Its immutable
[`Filter`](https://github.com/tombroucke/wp-fluent-hooks/blob/e1b054b21f0d9f769eb69edf8320d9ecdd351bb8/src/Filter.php)
creates a wrapper and evaluates `when(callable)` each time WordPress dispatches
the hook, including the correct first-argument passthrough for an inactive
filter. An adapter could supply a site-and-prefix predicate. However, the
package has no declared license in its release
[`composer.json`](https://github.com/tombroucke/wp-fluent-hooks/blob/e1b054b21f0d9f769eb69edf8320d9ecdd351bb8/composer.json),
has no subscription handle, forbids combining `when()` and `deregister()` on
the same fluent object, and its singleton
[`FilterRepository`](https://github.com/tombroucke/wp-fluent-hooks/blob/e1b054b21f0d9f769eb69edf8320d9ecdd351bb8/src/FilterRepository.php)
removes the saved wrapper by directly unsetting `WP_Hook`'s global callback
array. Satisfying the mandatory lifecycle and registry-boundary rules therefore
requires upstream redesign or a fork, not a narrow adapter.

`heybran/wp-hook-manager` is the freshest close match. Its immutable
[`HookManager.php`](https://codeberg.org/heybran/wp-hook-manager/src/commit/0bf3e0fbf314d079d69c9366002c73975d401582/src/HookManager.php)
solves a different problem: it remembers first-class callable closures so a
later equivalent callable can remove the original. It registers the consumer
callback itself, has no dispatch wrapper or context predicate, and stores all
state in a public static registry. Adding our requirements would replace its
central design rather than adapt a small edge.

PinkCrab is the strongest stable Composer distribution. Its immutable
[`Hook`](https://github.com/Pink-Crab/Loader/blob/3f5babd14d868be5dcfc8db4e3f359b7eb4efebc/src/Hook.php)
and
[`Hook_Manager`](https://github.com/Pink-Crab/Loader/blob/3f5babd14d868be5dcfc8db4e3f359b7eb4efebc/src/Hook_Manager.php)
preserve WordPress registration parameters. However, context validation is
admin-versus-front and happens only during registration. Its
[`Hook_Removal`](https://github.com/Pink-Crab/Loader/blob/3f5babd14d868be5dcfc8db4e3f359b7eb4efebc/src/Hook_Removal.php)
explicitly refuses closures and removes instance callbacks by class and method,
which can remove multiple clients' callbacks at once.

ItalyStrap is the closest subscriber API. Its
[`ListenerRegisterTrait`](https://github.com/ItalyStrap/event/blob/7252f370a520d31194e8965afcfd1ca500870c5a/src/ListenerRegisterTrait.php)
uses native exact add/remove and its
[`SubscriberRegister`](https://github.com/ItalyStrap/event/blob/7252f370a520d31194e8965afcfd1ca500870c5a/src/SubscriberRegister.php)
reconstructs subscriber callbacks. But
[`EventSubscription`](https://github.com/ItalyStrap/event/blob/7252f370a520d31194e8965afcfd1ca500870c5a/src/EventSubscription.php)
only serializes callback parameters; it does not own a registered callback or
unsubscribe it, and there is no runtime context gate.

Snicco proves that inactive filter delivery must return the first argument, not
merely return nothing. Its immutable
[`EventMapper`](https://github.com/snicco/better-wp-hooks/blob/6b988dc0bf0b1d2776be96bb4a67506f1de53934/src/EventMapping/EventMapper.php)
has a useful `shouldDispatch()` concept, but also changes WordPress hooks into a
second event model, registers 9999 accepted arguments and provides no unmap
handle. It is therefore reference material, not an adapter target.

Yard's
[`Registrar`](https://github.com/yardinternet/wp-hook-registrar/blob/b1d8e24d2ec12cbca2cf9fd62d12f85633833f62/src/Registrar.php)
and ssnepenthe's
[`EventDispatcher`](https://github.com/ssnepenthe/wp-event-dispatcher/blob/74023e0d7922c50bd9b09be875c0852b614bb149/src/EventDispatcher.php)
confirm the same boundary: registration metadata alone is insufficient without
dispatch ownership and exact subscription lifetime.

## Probe disposition

The task requires a minimal integration probe for a candidate that survives the
static mandatory matrix. No candidate survived. Tombroucke provides a generic
dispatch predicate but fails the declared-license, subscription-handle and
WordPress-registry-boundary gates; every other full-matrix candidate lacks the
owned dispatch-time site-and-prefix boundary, and most also lack a stable
subscription handle. A runtime install could only reconfirm an already-proven
absence and would add no selection evidence, so no third-party package was
installed.

HOOK-01 must instead start its selected implementation with contract tests
against real `WP_Hook` behavior: equal-priority ordering, exact accepted
arguments, active/inactive/restored contexts, idempotent unsubscribe and active
callback exception propagation.

## Decision packet: DG-HOOK-01

### A — adopt an existing package

Benefits: external maintenance and no new distribution surface.

Cost and risk: no current candidate passes. Choosing A now means accepting a
behavior fork or depending on upstream changes that do not exist. That violates
the selection rule and leaves the multisite defect unsolved.

Rollback: remove the unintegrated dependency. Because no candidate qualifies,
A has no implementation-ready target.

### B — publish a focused standalone package

Benefits: the package can own one small responsibility, be tested independently
from wpConnections domain/storage behavior, and be reused by other WordPress
libraries. It can remain a thin layer over native WordPress ordering and error
semantics.

Cost and risk: a new repository needs ownership, security/update policy, CI,
semantic versioning, Composer publication and release discipline. wpConnections
must pin a released version and keep the adapter boundary narrow.

Rollback: before HOOK-03, abandoning or replacing the package has no runtime or
data effect. After integration but before a public 2.0 release, revert the
wpConnections adapter and dependency together. No persisted connection data is
owned by the manager.

### C — implement the manager inside wpConnections

Benefits: one repository, one release and the smallest immediate operational
overhead.

Cost and risk: couples reusable hook lifecycle logic to the domain library,
makes independent compatibility testing harder, and encourages future domain
shortcuts or a mutable current-client dependency. Extraction later creates the
same package/versioning work after consumers may already depend on internal
classes.

Rollback: revert the internal integration before 2.0. After exposure, class
location becomes another compatibility surface.

## Recommendation and downstream boundary

Choose **B**. A is unavailable under the mandatory selection rule; C is viable
but conflicts with the already approved goal of a small reusable manager. The
standalone implementation should:

- require PHP `^8.1` and have no runtime framework dependencies;
- expose an explicit action subscription and idempotent unsubscribe;
- retain one stable wrapper callback and the exact WordPress registration
  metadata;
- capture blog ID and database prefix when subscribing and compare both on
  every dispatch;
- no-op before the consumer callback in an inactive context;
- call an active callback without catching `Throwable`;
- delegate ordering to native WordPress;
- hide WordPress functions behind a small gateway so lifecycle behavior is
  unit-testable, while retaining real WordPress integration tests;
- contain no wpConnections `Client`, `Storage`, relation or schema classes.

Action and filter subscriptions must not silently share inactive semantics: an
inactive action returns nothing, while an inactive filter must return its first
argument. Whether filters belong in the first stable package release is deferred
until HOOK-02 proves which wpConnections-owned filters, if any, need context
routing.

If B is approved, HOOK-01 still needs an implementation preflight for package
coordinates/ownership, license and first-release action/filter scope. Those are
conditional delivery choices; they do not change this build-versus-buy result
and no external repository is created by HOOK-00.

## Verification and completion criteria

### Independent review record

The first independent documentation/source review found one major omission:
`tombroucke/wp-fluent-hooks`, whose dispatch-time `when()` predicate made it a
serious candidate despite its other contract gaps. Commit `010c5de` added it to
the full matrix, immutable-source analysis and no-probe rationale. The repeated
independent review of `010c5de514546e1f600d3878a86a0031234a40d5` returned an
unconditional PASS with no remaining findings. It also independently checked
the package metadata, immutable source links, search totals, documentation-only
scope and the fact that DG-HOOK-01 remains pending.

This closed the research and traceability portions of HOOK-00. PR #78 final
head `345e36d1794df4bba05377ebbeaf325ee894cf61` passed all 17 protected jobs,
was merged as `cf8caa6aa4cd61afc592161092492914f12eb25d`, and all 17 post-merge jobs
passed. HOOK-00 is complete; its recommended DG-HOOK-01/B remains unapproved.

HOOK-00 is complete when:

- the source links and current package metadata are independently checked;
- the Packagist search can be reproduced and every serious candidate has a
  criterion-by-criterion disposition;
- the no-probe decision is accepted because zero candidates pass static gates;
- DG-HOOK-01 retains all A/B/C options and is ready for the owner to choose;
- this documentation-only branch passes formatting/traceability QA and all
  protected repository checks.

Completing HOOK-00 does not approve DG-HOOK-01. Until the separate owner
decision is recorded, HOOK-01 remains `waiting_decision`.
