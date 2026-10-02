# Extension compatibility for the first release

The filter and action names below are the supported extension surface. A
`Client` is tied to its canonical name and WordPress site context; create one
per site. Hook callbacks should use the supplied `Client` instead of a global
current client. The linked [storage SPI](storage-spi-contract.md) specifies the
eight adapter methods and atomic capability.

## Factory filters and Client setup

Each filter receives `(default class string, exact Client)` once during Client
construction and returns a **class string**. Construction order is capability
filter, storage, logger, REST delegate, then Client initialized actions. The
selected classes receive that same Client as their sole constructor argument:

| Filter | Required class | Result |
| --- | --- | --- |
| `wpConnections/factory/getStorage/class` | Concrete `Abstracts\Storage` subtype implementing all eight methods | `Client::getStorage()` |
| `wpConnections/factory/getLogger/class` | Concrete `Psr\Log\LoggerInterface` implementation | `Client::getLogger()` |
| `wpConnections/factory/getRestApi/class` | Concrete `ClientRestApi` subtype | Managed REST delegate |

An absent class, non-string value, incompatible or abstract class, failed filter,
or failed replacement constructor raises `ClientRegisterFail` with code `4` and
the relevant filter name. Selection and construction failures retain the
original Throwable as `getPrevious()`. The built-in `WPStorage` keeps its
established `ClientRegisterFail` reasons for table ownership, identifier length
and site prefix errors during construction. Factory failure does not publish
partially initialized Client-owned hooks. The filter accepts neither an object
nor a callable factory in this release; a dedicated factory interface is a
next-major candidate.

`wpConnections/client/{canonical-client-name}/clientDefaultCapabilities` runs
first with the default empty string. Return a capability string. Empty resolves
to `manage_options`; the selected value becomes the default for Client
capability checks. The global `wpConnections/client/inited` action then the
client-specific `wpConnections/client/{name}/inited` action each receive the
exact `Client` once in that order. They run before deleted-post repair
activation completes, so an `inited` observer must not assume every later
integration is already active.

The REST factory signature is unchanged. A custom `ClientRestApi::init()` must
call `parent::init()`. The managed registrar uses the selected delegate's
namespace, base, permission methods and handlers for the four built-in route
patterns, resolving the live Client at request time. An override of
`registerRestRoutes()` is no longer called automatically for those routes;
custom extra routes remain the delegate author's responsibility. See the
[REST lifecycle transition](client-owned-hook-inventory.md#rest-hook-01-implementation-update).
The [first-release hook consumer checklist](deleted-post-cleanup-upgrade.md#consumer-checklist)
covers direct cleanup callback removal and custom REST overrides.

## Lifecycle actions

`wpConnections/relation/creating` receives the mutable `Query\Connection`
once after initial relation/entity checks and before persistence. Changing an
endpoint triggers revalidation; changing the relation is rejected.
`wpConnections/relation/created` receives the hydrated domain `Connection`
once after a successful create. For an atomic create it runs after commit;
rollback emits no `created` action. For the default adapter, metadata success
notification precedes `created` after the same commit.

The following actions are emitted by **default `WPStorage`**, with the global
action before its client-specific variant. They are concrete compatibility for
that adapter, not an obligation for a replacement adapter. A custom adapter may
emit compatible events, but consumers cannot assume it does. `C` means the
originating Client; `{name}` is its canonical name.

| Default-storage action suffix | Global arguments | Client-specific arguments |
| --- | --- | --- |
| `deleteSpecificConnections` | `C, original IDs` | `original IDs` |
| `deletedSpecificConnections` | `C, normalized requested IDs, affected connection count` | `normalized requested IDs, affected connection count` |
| `deleteByObjectID` | `C, object IDs, relation, onlyFrom, onlyTo` | Same arguments without `C` |
| `deletedByObjectID` | `C, selected IDs` | `selected IDs` |
| `deleteDirectedConnections` | `C, from, to, relation` | Same arguments without `C` |
| `deletedDirectedConnections` | `C, selected IDs` | `selected IDs` |

The global name is `wpConnections/storage/{suffix}` and the scoped name is
`wpConnections/client/{name}/storage/{suffix}`. Each selected delete attempt
emits its before pair once, before that storage method's delete work. A
relation-scoped ID delete has already entered its lock and atomic boundary when
these attempt callbacks run. A synchronous second-session mutation from such a
callback may wait on the held row lock or time out; do not use it as a
nonblocking observer. A successful deletion of one or more connections emits
its success pair once after commit, even when several rows match. No match,
rollback or failure emits no success pair.
`deleted_post` cleanup uses the same site-owned path, and calls from an
inactive site do not deliver this Client's storage work.

Default-storage metadata actions are global only:

| Action | Arguments | Timing |
| --- | --- | --- |
| `wpConnections/storage/addConnectionMeta/before` | `C, connection ID, MetaCollection` | Before insert attempt |
| `wpConnections/storage/addConnectionMeta/after` | `C, connection ID, MetaCollection, []` | After commit |
| `wpConnections/storage/removeConnectionMeta/before` | `C, connection ID, Query\MetaCollection, SQL string` | Before delete attempt |
| `wpConnections/storage/removeConnectionMeta/after` | `C, connection ID, Query\MetaCollection, SQL string, affected count` | After commit |

`wpConnections/storage/findConnections/dbQuery` delivers `(SQL string, raw
rows, C)` after a successful SQL read; the trailing Client preserves the
first two historical arguments. Its priority-10 singleton debug observer logs
once to that Client's logger when `WP_DEBUG` is enabled, with only the first
two arguments in legacy log context. Observers at priority 5 run before it;
priority 15 run after it. `wpConnections/storage/findConnections/dbQuery/data`
delivers `(SQL, raw rows, hydrated row arrays, Collection::toArray())` after
materialization. Both are `WPStorage` SQL telemetry, not portable query SPI.
`wpConnections/storage/installOnInit` is likewise a concrete `WPStorage`
filter receiving `(false, C)`. The default `Logger` emits the WordPress
`logger` action with `([message, context], level)`; custom loggers need only
implement PSR logging.

If a custom adapter chooses to emit the three actions observed by automatic
debug logging, it must supply the originating Client as the trailing third
argument of `findConnections/dbQuery`, and retain it as the first argument of
`removeConnectionMeta/after` and `deletedSpecificConnections`. The latter two
retain their existing argument order. An event without a valid Client origin
still reaches consumer callbacks, but the singleton skips automatic logging.

`iTRON/wpConnections/storage/createConnection/attempt` and its `/result`
variant are internal insertion/recovery probes and excluded from the extension
compatibility promise. The managed REST and deleted-post dispatcher hooks are
internal subscriptions, not consumer extension events.

## Migration from direct storage coupling

`Client::getStorage()` remains callable for existing integrations, and custom
adapters keep the eight-method SPI. Application writes should use
`Relation::createConnection()`, `Relation::detachConnections()` and
`Connection::update()` so validation, atomic capability, and post-commit hooks
run through the domain boundary. For a known orphan relation, query the
relation by ID and delete it through that relation instead of directly calling
`deleteSpecificConnections()`; a no-match relation delete returns zero.

For aggregate `Connection::update()`, a capable custom adapter must confirm the
exact parent ID and relation ownership inside its atomic boundary and keep that
parent protected from competing deletion through metadata replacement and
commit. A deleted target raises `ConnectionNotFound` before metadata insertion;
unchanged scalar fields remain a valid no-op while metadata changes. The
default `WPStorage` uses a row lock for this guarantee. Other adapters may use
equivalent serialization without SQL or a new public capability method.

`WPStorage` table-name getters and the established normalized table mapping
remain legacy concrete introspection for maintenance code. Non-table adapters
have no such getters. Consumers using the physical table names, especially
commit-pinned CF7 integrations, should migrate maintenance logic deliberately;
no replacement adapter should be expected to expose WordPress SQL tables.
Historical client names including hyphens and underscores retain their logical
REST identity and established table suffix. Private custom adapters are
unknown, so this guide does not imply they can be migrated automatically.

The [conformance table](storage-spi-contract.md#conformance-test-contract) and
[consumer inventory](compatibility-inventory.md) give the verification and
known usage boundaries. The deterministic memory-adapter schedules there
exercise custom capability semantics; the default adapter's two-session tests
provide separate database evidence for its locking behavior.
