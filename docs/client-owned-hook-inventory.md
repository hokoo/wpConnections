# Client-owned WordPress hook inventory

Status: completed audit; all recorded gates approved; no runtime behavior changed

Repository baseline: `cf8caa6aa4cd61afc592161092492914f12eb25d`
(HOOK-00, PR #78).

Audit snapshot: 2026-09-11.

## Outcome

Construction of one `Client` reaches five library-owned WordPress action
registrations when `WP_DEBUG` is enabled, or two when it is disabled. There are
no library-owned filter registrations:

- one `deleted_post` callback owned by `Client`;
- one `rest_api_init` callback owned by the factory-created `ClientRestApi`;
- three debug callbacks owned by a transient `Settings` instance.

The registrations do not have one common migration answer. `deleted_post` is a
direct fit for the planned context-aware manager. `rest_api_init` also needs a
context gate, but WordPress's separate global REST route registry makes a hook
wrapper alone insufficient. The `Settings` listeners should not be copied into
the manager: they fan every matching event out to every Client logger, including
within the same site, and one event does not identify its originating Client.

The audit therefore creates six additional decision gates:

- DG-HOOK-REST-01 for REST route/context architecture;
- DG-HOOK-REST-02 for duplicate Client identity within one site;
- DG-HOOK-REST-03 for late binding and unavailable-route behavior;
- DG-HOOK-REST-04 for the custom `ClientRestApi` factory boundary;
- DG-HOOK-LOG-01 for library-owned debug routing and custom Storage SPI;
- DG-HOOK-LIFE-01 for Client disposal and failed-initialization rollback.

It also resolves the deferred manager feature-scope question. wpConnections
owns action subscriptions only, so approved DG-HOOK-SCOPE-01/A selects an
action-only first stable manager release. Filter subscription semantics can be
added later without expanding the first integration batch.

## Audit method and completeness boundary

The inventory used four complementary passes:

1. Static registration search:
   `rg 'add_(action|filter)|remove_(action|filter)' src --glob '*.php'`.
2. Construction graph review from `Client::__construct()` through `init()`,
   `Factory`, `ClientRestApi`, `Settings`, the selected storage and logger.
3. Dispatch search: 30 `do_action()`/`apply_filters()` call sites were reviewed
   separately so public extension emissions were not mistaken for registrations
   owned by the library.
4. Temporary single-site and true-multisite probes replaced storage, REST and
   logger collaborators through the documented factory filters, recorded hook
   delivery, and were removed after execution. The probes changed no committed
   source or test file.

The result covers registrations reachable from the bundled implementation.
Factory-selected third-party classes can register additional hooks internally;
those remain the implementer's responsibility unless a future SPI explicitly
transfers lifecycle ownership to wpConnections.

## Construction and ownership graph

`Client::init()` performs these relevant steps in order:

1. dispatch the client capability filter;
2. create storage and logger through `Factory` filters;
3. create a REST API object and call `init()`, which registers
   `rest_api_init`;
4. create a local `Settings` object and call `init()`, which conditionally
   registers three closures;
5. create the relation collection;
6. register the storage callback on `deleted_post`;
7. dispatch the two Client-initialized actions.

`Client` retains storage and logger, but does not retain the REST API or
`Settings` objects. Their callbacks in WordPress's global hook registry are what
keep those objects reachable. There is no general Client disposal path.

## Registration matrix

| Owner / source | Hook and callback | Registration metadata | Retention and removal | Context finding | 2.0 action |
| --- | --- | --- | --- | --- | --- |
| `Client`, [`Client.php`](../src/Client.php#L63) | `deleted_post` → `[$storage, 'deleteByObjectID']` | priority 10; 1 accepted argument; auto-enabled in construction | `Client` retains storage. Exact direct removal and semantic `disablePostDeletionCleanup()` both work in 1.x; enable/disable are idempotent. | Site-sensitive. The default storage has the temporary 1.x prefix guard, but custom storage receives stale delivery. | HOOK-03: manager-required at the 2.0 boundary; preserve semantic API, intentionally break direct callback identity. |
| `ClientRestApi`, [`ClientRestApi.php`](../src/ClientRestApi.php#L31) | `rest_api_init` → `[$restApi, 'registerRestRoutes']` | priority 10; default 1 accepted argument | Client does not retain the REST object; the hook does. No remove or semantic lifecycle API exists. | Site-sensitive registration plus a second global registry. Two site-bound same-name clients both execute and both place object handlers into one REST server. | REST-HOOK-01 after DG-HOOK-REST-01—DG-HOOK-REST-04; not folded silently into HOOK-03. |
| `Settings`, [`Settings.php`](../src/Settings.php#L18) | `wpConnections/storage/findConnections/dbQuery` → one shared closure | priority 10; 2 accepted arguments | The local closure identity is discarded; the hook retains closure → Settings → logger. No unregister path exists. | Cross-client and cross-site fanout. Arguments are SQL string and result only, so the origin Client cannot be selected from the event. | LOG-HOOK-01 after DG-HOOK-LOG-01 and DG-SPI-06; recommendation is one origin-routed singleton observer, not manager wrapping. |
| `Settings`, [`Settings.php`](../src/Settings.php#L18) | `wpConnections/storage/removeConnectionMeta/after` → the same closure | priority 10; 5 accepted arguments | Same lost identity and no unregister path. | First argument identifies Client, but the callback ignores it; every logger receives the event. | LOG-HOOK-01; route one record to the originating Client while keeping the public hook emission. |
| `Settings`, [`Settings.php`](../src/Settings.php#L18) | `wpConnections/storage/deletedSpecificConnections` → the same closure | priority 10; 3 accepted arguments | Same lost identity and no unregister path. | First argument identifies Client, but the callback ignores it; every logger receives the event. | LOG-HOOK-01; route one record to the originating Client while keeping the public hook emission. |

`Settings::init()` evaluates `WP_DEBUG` once during Client construction. A
later constant/configuration change cannot add or remove the listeners. Because
PHP constants cannot normally change during a request, this is not a dynamic
state bug; it is relevant to lifecycle ownership and test setup.

### Indirect REST route registrations

`registerRestRoutes()` writes four patterns and twelve method/callback
combinations into the active `WP_REST_Server`. Every handler and every
`permission_callback` is an object callback on the same `ClientRestApi`; the
handler boundary therefore retains and exposes the same site-bound Client even
after the original `rest_api_init` callback is removed.

| Route pattern | Methods and handlers |
| --- | --- |
| `/wp-connections/v1/client/{client}` | `GET → getTheClient` |
| `/wp-connections/v1/client/{client}/relation/(?P<relation>[\w-]+)` | `GET → getRelation`; `POST → createConnection` |
| `/wp-connections/v1/client/{client}/relation/(?P<relation>[\w-]+)/(?P<connectionID>[\d]+)` | `GET → getConnection`; `POST`, `PUT`, `PATCH → updateConnection`; `DELETE → deleteConnection` |
| `/wp-connections/v1/client/{client}/relation/(?P<relation>[\w-]+)/(?P<connectionID>[\d]+)/meta` | `POST`, `PUT`, `PATCH → updateConnectionMeta`; `DELETE → deleteConnectionMeta` |

All twelve combinations use `checkPermissions` on the same object. Route
registration uses WordPress's default non-override behavior, so repeated or
same-path registration appends/merges endpoint groups rather than providing an
owned unregister token. Removing `rest_api_init` later does not remove routes
already present in the server.

## What is not a Client-owned registration

The 30 dispatch sites in `Client`, `Factory`, `Relation`, `WPStorage` and
`Logger` are public or internal extension events, not callbacks registered by
wpConnections. Moving their third-party listeners into a manager would require
control the library does not have and would change the public extension model.

In particular:

- the three `Factory` filters select implementation classes but register
  nothing;
- the per-client capability filter is dispatched before collaborators are
  created and is consumer-owned;
- relation/storage lifecycle actions remain public emissions whose callback
  ownership belongs to consumers;
- `Logger::log()` emits the compatibility action `logger`; it does not subscribe
  to it;
- bundled `WPStorage` registers no hooks itself. A custom storage may do so, but
  its private registrations are outside the Client-owned lifecycle contract.

HOOK-01 therefore needs action-subscription support for current wpConnections
integration. It does not need filter-subscription support merely because the
library emits filters.

## Runtime evidence

### Single-site, PHP 8.1.34 / WordPress 6.7.7 / Ramsey 1.3.0

Paired isolated `Settings::init()` probes recorded zero `add_action()` calls
with `WP_DEBUG=false` and exactly three with `WP_DEBUG=true`.

The temporary probe constructed two Clients with instrumented factory
collaborators. With `WP_DEBUG=true`, each of the five hooks gained exactly two
callbacks. Dispatching `findConnections/dbQuery` once delivered one record to
each of the two Client loggers.

A Client constructed after `rest_api_init` had already run did not appear in
the current REST server immediately. Its route appeared only after a second
manual `rest_api_init` dispatch. This proves that constructor auto-registration
currently has an undocumented timing precondition.

The probe then made `wpConnections/client/inited` throw. Although construction
failed, each of the five hooks retained one new callback. The partial Client,
REST API, Settings, storage and logger object graph remained reachable through
those callbacks. The successful probe completed with 11 assertions; the
multisite-only case was skipped in that run.

### True multisite on the same compatibility floor

The multisite probe constructed same-name Clients on blog 1 (`wptests_`) and
blog 2 (`wptests_2_`), then dispatched events on blog 2. Its instrumented custom
storage recorded two `deleted_post` calls: both the blog-1 and blog-2 storage
callbacks ran in blog 2. This is expected evidence for the hook defect: custom
storage does not inherit `WPStorage`'s temporary prefix guard.

One debug event on blog 2 was likewise delivered to both loggers. Creating a
fresh REST server dispatched `rest_api_init`; a deliberate repeat then proved
that both REST objects run on each dispatch and that route registration is not
idempotent. After those two dispatches, the same REST path contained callbacks
created on both sites in the observed sequence `[1, 2, 1, 2]`. The probe
completed with 5 assertions.

This evidence distinguishes two layers:

- a context-aware hook manager prevents the site-1 callback from running on
  site 2;
- REST-HOOK-01 must additionally prevent already-registered route handlers from
  being selected in a different current context.

## Existing test coverage and remaining tests

| Area | Existing protection | Missing implementation tests |
| --- | --- | --- |
| `deleted_post` registration | Exact 1.x callback identity, priority/args, semantic enable/disable, idempotence, real cleanup, stale/fresh default-storage multisite behavior | HOOK-03 manager-level proof that a stale callback is not invoked at all; custom storage; active/inactive/restored contexts; same-name clients; ordering; failure propagation |
| REST behavior | Four path patterns, twelve method/callback combinations, permissions, request validation and representative full dispatch; test server reset per fixture | Multiple clients/sites in one process; stale handler selection; same route identity; late Client initialization; repeated activation/rebinding; disposal and failed-construction cleanup |
| Debug logging | No focused tests | Disabled/enabled setup, one record per originating operation, same-site multiple clients, multisite isolation, bundled and custom Storage origin payload, exact level/message/context, public-hook priority/order, disposal and construction rollback |
| Client lifecycle | Semantic cleanup lifecycle only | General idempotent disposal, terminal-state behavior, subscription collection, reverse-order rollback after an initialization exception |

Tests that assert today's duplicate delivery or leaked callbacks should not be
committed as desired-behavior regressions. The temporary probe is discovery
evidence; each implementation task must start with a red test for its approved
target contract.

## Known-consumer direct-removal search

Authenticated GitHub code search was repeated on 2026-09-11 with these queries:

- `"deleted_post" "deleteByObjectID" language:PHP` — 5 results: current
  wpConnections source/tests plus one bundled mirror of the library;
- `"remove_action" "deleted_post" "deleteByObjectID" language:PHP` — 3
  results, all in the wpConnections repository;
- `remove_action repo:hokoo/cf7-telegram language:PHP` — 0;
- `remove_action repo:hokoo/cf7-vk language:PHP` — 0;
- `remove_action repo:hokoo/neuralseo language:PHP` — 0.

No public consumer-owned direct removal was found. This is a lower bound, not
proof that private consumers do not exist. HOOK-04 must repeat the search at the
release snapshot and keep the direct-`remove_action()` warning prominent.

## Migration map

| Current responsibility | Owner task | Gate/dependencies | Compatibility boundary |
| --- | --- | --- | --- |
| `deleted_post` delivery | HOOK-03 | HOOK-01, DB-04, DG-DELETE-06 | 2.0 changes callback identity; semantic enable/disable remains the migration API and exposes its handle to final Client lifecycle |
| REST hook and route lifecycle | REST-HOOK-01 | HOOK-01, REST-01, DG-HOOK-REST-01—DG-HOOK-REST-04, DG-RESTERR-03; REL-02 hand-off | Preserve v1 request URLs/methods and factory-selected handler delegates; define duplicate ownership, late initialization, unavailable dispatch/route-index visibility and a revocable Client mapping before final lifecycle integration |
| Automatic debug routing | LOG-HOOK-01 | DG-HOOK-LOG-01, DG-SPI-06; REL-02 hand-off | Preserve event names, existing argument order and priority-10 logging; add a trailing origin Client to the query event and document the custom Storage obligation instead of retaining duplicate/wrong-client logging |
| Subscription retention, disposal and rollback | LIFE-HOOK-01 | HOOK-03, REST-HOOK-01, LOG-HOOK-01, DG-HOOK-LIFE-01 | Final 2.0 lifecycle surface; failed/disposed Client must be unreachable from hooks and routes |
| Direct callback migration documentation | HOOK-04 | All applicable integration tasks, REL-02/REL-03 | Red-flag direct `remove_action()` break and repeat known-consumer scan |
| Public extension emissions | REL-02 | Existing SPI/release gates | No manager ownership; document/test names, arguments and timing |

## Decision gates

<a id="dg-hook-scope-01"></a>
### DG-HOOK-SCOPE-01 — first stable manager feature scope

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** the standalone/internal manager can initially expose actions only,
or also commit to filter-specific inactive behavior. wpConnections owns no
filter subscriptions, so filter support is not required for its 2.0 migration.

- **A — action subscriptions first:** ship the explicit action subscription,
  context predicate and unsubscribe contract required by this repository.
  Reserve filter support for a later compatible release with separate tests.
- **B — actions and filters together:** also expose filter subscriptions whose
  inactive path returns the original first argument unchanged.
- **C — one generic hook abstraction:** treat actions and filters through one
  API and one inactive return rule.

**Recommendation:** A. It is the smallest contract supported by observed need.
B is viable but expands the first release and conformance matrix without a
current consumer. C is unsafe because inactive action and filter semantics are
not interchangeable.

**Rollback:** before wpConnections integration, a package release can be
superseded without application data impact. After integration, the action API
must remain stable; later filter support can be additive.

<a id="dg-hook-rest-01"></a>
### DG-HOOK-REST-01 — REST context and route-registry lifecycle

**Status:** approved B by the repository owner on 2026-09-11.

**Problem:** guarding `rest_api_init` stops an inactive Client from registering
new routes, but it cannot remove stale object callbacks already stored in a
reused `WP_REST_Server`. Late Client construction currently also misses route
registration until the action runs again.

- **A — hook guard plus timing contract:** manage only `rest_api_init`; require
  each site's Client to exist before that site's REST initialization and require
  a fresh REST server after context changes. Route-server correctness remains a
  host/consumer responsibility.
- **B — managed registration plus route boundary:** guard `rest_api_init`, own
  deterministic activation/rebinding of the current site's route set, and
  validate context again before a handler reaches Client code. Duplicate
  identity, late/unavailable behavior and factory-delegate ownership are
  resolved by DG-HOOK-REST-02/03/04.
- **C — explicit REST opt-in:** stop automatic Client REST registration in 2.0;
  require the consumer to enable/rebind REST for each current site context.

**Recommendation:** B. It preserves automatic behavior for normal consumers
while providing ownership boundaries for both registries. The implementation
must preserve the four route paths and twelve method/callback combinations and
must never dispatch to another site's Client. This architecture is not
implementation-ready until DG-HOOK-REST-02/03/04 and the applicable REST error
shape are also approved.

**Compatibility:** A preserves the smallest code diff but exposes operational
preconditions. B changes internal callback identity and duplicate-route
behavior in 2.0 without changing route URLs. C is the largest public behavior
break and needs an explicit migration API.

**Rollback:** before 2.0 release, revert the REST registrar/handler boundary and
manager subscription together. No stored connection data or route URL changes.

<a id="dg-hook-rest-02"></a>
### DG-HOOK-REST-02 — duplicate live Client identity within one site

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** two live Clients with the same canonical name in one blog/prefix
produce the same REST route identity but may have different in-memory relation,
capability and collaborator state. Current endpoint merging makes the selected
Client depend on registration/handler order.

- **A — reject the second live owner:** allow exactly one live REST Client per
  `(blog ID, database prefix, canonical client name)`. Reject another before it
  gains hook/route ownership; allow a replacement only after the previous
  mapping is explicitly revoked. LIFE-HOOK-01 later wires that revocation to
  public Client disposal.
- **B — explicit latest-owner replacement:** a later Client atomically revokes
  the previous REST mapping and becomes the route owner; the earlier Client may
  remain usable outside REST.
- **C — retain first owner:** later same-key Clients remain usable outside REST
  but do not replace or extend the first route owner.

**Recommendation:** A. It turns an ambiguous collision into an early, visible
configuration error and enforces one live REST owner per identity/site. B can
silently change API behavior during a request; C leaves a newly constructed
Client partially active without a clear signal. Same canonical name on
different sites remains valid because blog ID and prefix are part of the key.

**Compatibility:** current code permits duplicates and merges endpoints. A is
an intentional 2.0 validation change and needs a stable `ClientRegisterFail`
reason plus a REST-HOOK-01 test that internal mapping revocation permits
replacement. LIFE-HOOK-01 owns the end-to-end `dispose()` replacement test.

**Rollback:** remove the uniqueness claim and restore endpoint merging before
2.0 release. No stored relation data is changed.

<a id="dg-hook-rest-03"></a>
### DG-HOOK-REST-03 — late binding, unavailable dispatch and route visibility

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** a Client created after `rest_api_init` currently has no route until
another dispatch. A context-neutral route callback may also outlive a disposed
or failed Client mapping. The no-owner dispatch outcome must therefore be
defined separately from route discovery: WordPress can retain an unavailable
concrete path in `get_routes()` and the REST index even when dispatch is denied.

- **A — bind late, native 404 at the dispatch boundary:** if REST initialization
  has already run, atomically bind a unique current-context Client before
  construction succeeds. A matched route with no live current-context mapping
  returns the native `rest_no_route` shape with HTTP 404 before either a stale
  `permission_callback` or handler runs. On a deliberately reused REST server,
  an unavailable concrete path may remain visible in route discovery.
- **B — reject late activation:** construction/REST activation after
  `rest_api_init` fails with a stable `ClientRegisterFail`; absent/disposed
  mappings return the same native 404 before permission or handler code. Stale
  concrete paths may likewise remain visible in a reused server.
- **C — bind late and hide unavailable discovery:** use an additional owned
  `rest_endpoints` filter (with its own removal/lifecycle contract) so paths
  without a current-context owner are absent from the REST index as well as
  returning native 404 on dispatch. This expands the REST integration to a
  filter subscription; it does not require the general manager's first release
  to support filters.
- **D — bind late, expose an availability error:** bind a late Client
  immediately, but return a new explicit library error and separately approved
  HTTP status when a route has no live mapping. Route discovery remains visible.

**Recommendation:** A. It preserves automatic REST availability for normal and
late consumers, guarantees that no permission or handler code from another
site executes, and avoids a new filter and response contract. It intentionally
does not promise that a REST server reused outside the normal WordPress request
lifecycle conceals stale route names in its index.

**Compatibility:** A fixes current late initialization and changes stale-route
dispatch to a deterministic native 404 while leaving WordPress route discovery
semantics intact. The response must follow DG-RESTERR-03's WordPress-native
gateway shape. B is stricter and can make previously successful late
construction fail. C changes the discoverable route index and adds filter
ownership. D adds a new public REST error contract.

**Rollback:** before release, disable late binding and restore constructor-only
listener registration. There is no persisted-data effect.

<a id="dg-hook-rest-04"></a>
### DG-HOOK-REST-04 — custom ClientRestApi factory boundary

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** `wpConnections/factory/getRestApi/class` is an existing public
replacement surface. A selected subclass can override built-in handlers and
permissions, but can also override `init()` or `registerRestRoutes()` and place
arbitrary object callbacks directly into WordPress's global registries. A
library-owned shared route boundary cannot safely discover, rewrite or revoke
arbitrary private registrations.

- **A — managed built-in transport, custom delegate:** preserve the factory
  filter name/arguments, subclass requirement and construction. Invoke the
  selected object's `init()` once in its construction context; successful
  initialization requires a subclass override to delegate to the base managed
  activation. Retain the object as the current-context delegate, including its
  public `$namespace`/`$base` route identity and built-in permission/handler
  overrides. The library exclusively owns lifecycle registration for those
  four built-in route patterns. Overridden `registerRestRoutes()` is no longer
  a built-in registration ownership point. Additional `init()` side effects or
  private hooks/routes remain implementer-owned and require their own
  context/lifecycle guards.
- **B — preserve arbitrary registration overrides:** continue invoking custom
  `init()`/`registerRestRoutes()` and manage only the bundled implementation.
  Custom handler routes can therefore remain retained and cross-context unless
  their implementer independently fixes them.
- **C — replace the class filter:** deprecate/remove the subclass factory and
  introduce a new REST delegate/route-provider interface with a complete
  managed registration contract.

**Recommendation:** A. It preserves the existing factory selection and the
useful handler/permission customization boundary while making the library's
four routes context-safe. It also states the unavoidable limit: arbitrary hooks
registered privately by a replacement class cannot become library-owned by
inspection. B weakens the central safety promise; C is a much larger public SPI
break than the evidence justifies.

**Compatibility:** no public replacement use was found by REL-00, but private
use remains possible. A preserves one `init()` call and custom
`$namespace`/`$base`, handler and permission behavior when an override delegates
to base activation. It is an intentional 2.0 break for subclasses that used
`registerRestRoutes()` to register/replace routes or whose `init()` skipped the
parent lifecycle. Arbitrary custom `init()` side effects still run but are not
made revocable by the library. HOOK-04 must flag those boundaries, and REL-02
must test the retained factory/route/delegate behavior. Unmanaged additional
effects and routes remain consumer/implementer-owned.

**Rollback:** before release, restore direct selected-object initialization and
route registration. This restores legacy extensibility together with its stale
callback risk; no persisted relation data changes.

<a id="dg-hook-log-01"></a>
### DG-HOOK-LOG-01 — ownership of automatic debug logging

**Status:** approved B by the repository owner on 2026-09-11.

**Problem:** every WP_DEBUG Client creates three global closures. A storage
event is logged by every Client logger, not only its origin; the query event has
no Client argument, so a context wrapper cannot fix same-site fanout. Moving
the call directly into bundled `WPStorage` would stop automatic logging for a
custom Storage that currently emits the same public hooks, and moving it outside
the public action would change relative callback timing.

- **A — manager-wrap current listeners:** retain one listener set per Client and
  suppress only cross-site delivery. Same-site duplicate/wrong-client logging
  remains, and the query event still cannot be routed by origin.
- **B — one origin-routed singleton observer:** replace the per-Client
  `Settings` closures with one process-global library observer at priority 10.
  The two mutation events already pass their originating Client first; append
  the originating Client as the third argument of
  `findConnections/dbQuery`. The observer uses the Client only to select its
  logger and logs the legacy payload, then `Logger::log()` keeps emitting the
  existing `logger` compatibility action.
- **C — remove automatic debug logging:** keep public events, but require
  consumers to subscribe and select a logger themselves.

**Recommendation:** B. It provides exactly one record through the correct
logger for bundled storage and for a conforming custom Storage, while retaining
logging inside the public hook dispatch at priority 10. It avoids putting
same-site origin selection into the context manager.

**Compatibility:** multiple-client installations that currently receive
duplicate records will receive one correct record. Existing hook names and
existing argument order remain stable; `findConnections/dbQuery` gains one
trailing Client argument, which WordPress does not pass to callbacks that still
declare two accepted arguments. A custom Storage gets automatic debug routing
only when it emits the documented origin Client payload (third on the query
event; existing first argument on the two mutation events). Otherwise its
public event still runs, but the library observer skips automatic logging
instead of broadcasting to every Client logger. Logging remains a priority-10
callback inside the public action. Equal-priority ordering relative to consumer
callbacks, and the post-commit timing of mutation events, must be frozen by
REL-02 after DG-SPI-06. Custom `Settings` replacement is not a factory surface
today.

**Rollback:** restore the three internal listeners before release. There is no
persisted-data effect; log multiplicity is the observable boundary.

<a id="dg-hook-life-01"></a>
### DG-HOOK-LIFE-01 — Client disposal and initialization rollback

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** WordPress callbacks strongly retain the Client object graph. There
is no complete unsubscribe path, and an exception late in construction leaves
all five owned registrations alive even though no Client was returned. The
final lifecycle can only be assembled after deletion and REST integrations
each provide their own revocable ownership boundary.

- **A — explicit terminal integration disposal plus transactional
  initialization:** Client retains every owned subscription, exposes
  `Client::dispose(): void`, and revokes already-created
  subscriptions/mappings in reverse order if initialization fails. Disposal is
  idempotent. A later semantic cleanup enable or REST activation/rebind fails
  with existing `ClientRegisterFail` code `4` and a stable disposed-Client
  reason. Context-neutral shared infrastructure may remain, but it must not
  retain or resolve the failed/disposed Client.
- **B — process-lifetime ownership:** retain subscriptions and context guards,
  but provide no Client disposal; only constructor rollback is added.
- **C — destructor cleanup:** rely on `__destruct()` to unsubscribe when the
  Client is no longer referenced.

**Recommendation:** A. Long-running workers, tests and dynamic application
composition need a deterministic boundary. C cannot work reliably because the
hook registry itself retains the callback object and can prevent destruction.
B fixes partial construction but not intentional replacement or teardown.

**Compatibility:** this is additive public integration lifecycle in 2.0.
Disposal is terminal for hook/REST activation so a retired Client cannot
silently resubscribe. Direct domain calls through an already-held Client,
Relation or Storage reference remain governed by their existing validation and
site-prefix contracts; `dispose()` neither revokes those references nor adds a
new use-after-dispose guard to every domain method. The consumer remains
responsible for creating one Client per site and disposing one it intentionally
retires; `switch_to_blog()` does not auto-create or auto-dispose clients.

**Rollback:** before 2.0 release, remove the public disposal surface and retain
process-lifetime subscriptions. Initialization rollback has no consumer-visible
data effect and should be retained even if disposal is reconsidered.

## Completion criteria

HOOK-02 was complete when this artifact and the executable-plan hand-off passed
independent traceability review and all protected repository checks. Completing
the audit itself did not approve any gate or authorize a dependency, external
repository or runtime behavior change. The repository owner subsequently
approved every gate recorded here on 2026-09-11. The separate HOOK-01 task later
published `hokoo/wp-hooks-dispatcher` `v1.0.1`; LOG-HOOK-01 is now the active
Batch 9 implementation task.

## Independent QA evidence

Independent QA returned an unconditional PASS on content head
`06b07a73225ccecce9225bda2bfca99ee6cfb12d`. It independently verified the
source inventory, construction graph, runtime-probe interpretation, consumer
search lower bound, all decision alternatives and compatibility boundaries,
the non-cyclic task DAG, status vocabulary and local links/anchors. Review
findings were resolved before that verdict: REST outcomes and custom
collaborator boundaries were made explicit, lifecycle integration was ordered
after revocable deletion/REST hand-offs, HOOK-04 gained its missing lifecycle
dependency, and every task status now uses the plan's canonical vocabulary.

Delivery-owner verification of that candidate passed unit `12 / 58`,
integration `108 / 756`, PHPCS `45 / 45`, and `git diff --check`. PR #79
candidate head `d7ab4bd` then passed all 17 protected jobs. HOOK-02 is complete;
the closure head and post-merge protected runs remain delivery evidence rather
than undiscovered task scope.
