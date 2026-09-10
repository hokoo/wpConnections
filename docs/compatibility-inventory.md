# Public consumer and compatibility inventory

Snapshot date: 2026-09-10

This document is the evidence artifact for `REL-00`. It records the public
usage that can be found before compatibility-sensitive changes are designed.
It is a lower bound, not a registry of every installation.

## Executive summary

- Three independent public repositories declare `hokoo/wpconnections` as a
  dependency: `hokoo/cf7-telegram`, `hokoo/cf7-vk` and `hokoo/neuralseo`.
- The two currently active CF7 integrations use hyphenated client identifiers;
  NeuralSEO uses an underscore. All define relations with explicit `from` and
  `to` endpoint types and omit the legacy `type` field.
- Public consumers do depend on storage details. `cf7-vk` invokes a mutation
  through `Client::getStorage()`, while both CF7 integrations derive or inspect
  physical table names. This is direct evidence that SPI/storage hardening needs
  compatibility notes and a migration path even though direct storage writes
  are not a supported high-level consumer API under DG-M9.
- The per-client capability filter is used by both CF7 integrations. No public
  use was found for factory replacement filters or relation/storage lifecycle
  actions outside copies of the library itself.
- No public call was found to the empty `Connection::load()` or
  `ConnectionCollection::getPosts()` methods. Consumers that need entities
  project the `from`/`to` columns and hydrate their own domain collections.
- A public mirror containing a bundled copy of `cf7-telegram` was found. It is
  distribution evidence, not a fourth independent consumer.

## Evidence set

The default branches were cloned and inspected at the following immutable
commits. Dependency discovery was cross-checked with authenticated GitHub code
search, filtering its output to public repositories.

| Source | Snapshot | Evidence |
| --- | --- | --- |
| wpConnections README and wiki | repository `1a63d0b24f3b2aba5aeeba2ad6fdc276153c964a`; wiki `b009cd758291bd4272fd22968823b0abbb19032a` | The documented client is `my-app-wpc-client`; the example relation sets `name`, `from`, `to` and `cardinality`, but not `type`. See [README](https://github.com/hokoo/wpConnections/blob/1a63d0b24f3b2aba5aeeba2ad6fdc276153c964a/README.md#L35-L82) and [Get Started](https://github.com/hokoo/wpConnections/wiki/Get-Started). |
| `hokoo/cf7-telegram` | `10b285ce120983767181cc09ad506638a4dda25e` | Public, non-fork repository; `dev-master` dependency, client/relation definitions, capability filter and storage coupling. |
| `hokoo/cf7-vk` | `42e774d7e1630200c2d4443dbd2a413151f68353` | Public, non-fork repository; `dev-master` dependency, client/relation definitions, capability filter and direct storage mutation. |
| `hokoo/neuralseo` | `6272b6ea2cd0868e6ed1fc253647afda80ecdef8` | Public, non-fork repository; `master-dev` dependency, underscore client name and high-level relation CRUD. Last public push observed in 2023. |
| `WordPressBugBounty/plugins-cf7-telegram` | `c21d7fadb3642257743c81c307ba6e76d639bf34` from GitHub search | Bundles the CF7 Telegram plugin and its vendor tree. Counted as a mirror of the same integration, not an independent usage pattern. |

The dependency locks point at older wpConnections snapshots despite the branch
constraints: CF7 Telegram locks `9a968a092451dcc1a33de6f778ab5bd2a6db0e72`,
CF7 VK locks `97ab488e96592c73ca7368d4e280d7773a77bd94`, and
NeuralSEO locks `3e277875514265d79ed1000681b3b4aa00827813`.
Compatibility work therefore cannot assume that every consumer upgrades from
the current `master` state.

## Consumer inventory

### CF7 Telegram

- Declares `hokoo/wpconnections: dev-master` through a VCS repository
  ([composer.json](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/plugin-dir/composer.json#L18-L28)).
- Uses client name `cf7-telegram`, four named relations, explicit post types and
  `m-m`/`1-m` cardinalities. It does not set relation `type`
  ([Client.php](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/plugin-dir/lib/Client.php#L26-L106)).
- Registers the client-scoped `clientDefaultCapabilities` filter before
  constructing the client
  ([Client.php](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/plugin-dir/lib/Client.php#L144-L155)).
- Projects connection endpoint IDs with collection `column()` and hydrates its
  own entity classes instead of calling `getPosts()`
  ([Collection.php](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/plugin-dir/lib/Collections/Collection.php#L9-L25)).
- Calls `getStorage()` and conditionally invokes WPStorage-specific table getter
  methods during legacy import
  ([LegacyImporter.php](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/plugin-dir/lib/Migrations/LegacyImporter.php#L502-L518)).
- Reconstructs both physical table names from the public prefix and
  `Database::normalize_table_name()` during maintenance
  ([Maintenance.php](https://github.com/hokoo/cf7-telegram/blob/10b285ce120983767181cc09ad506638a4dda25e/plugin-dir/lib/Maintenance.php#L748-L757)).

### CF7 VK

- Declares `hokoo/wpconnections: dev-master`
  ([composer.json](https://github.com/hokoo/cf7-vk/blob/42e774d7e1630200c2d4443dbd2a413151f68353/plugin-dir/composer.json#L18-L28)).
- Uses client name `cf7-vk` and the same four relation shapes as CF7 Telegram,
  again with explicit `from`/`to` and no `type`
  ([Client.php](https://github.com/hokoo/cf7-vk/blob/42e774d7e1630200c2d4443dbd2a413151f68353/plugin-dir/lib/Client.php#L24-L104)).
- Uses the client-scoped capability filter
  ([Client.php](https://github.com/hokoo/cf7-vk/blob/42e774d7e1630200c2d4443dbd2a413151f68353/plugin-dir/lib/Client.php#L150-L162)).
- Directly calls `getStorage()->deleteSpecificConnections()` to remove an
  orphan during entity hydration
  ([Collection.php](https://github.com/hokoo/cf7-vk/blob/42e774d7e1630200c2d4443dbd2a413151f68353/plugin-dir/lib/Collections/Collection.php#L60-L68)).
- Reconstructs the physical table names during maintenance
  ([Maintenance.php](https://github.com/hokoo/cf7-vk/blob/42e774d7e1630200c2d4443dbd2a413151f68353/plugin-dir/lib/Maintenance.php#L740-L749)).

### NeuralSEO

- Declares `hokoo/wpconnections: master-dev`
  ([composer.json](https://github.com/hokoo/neuralseo/blob/6272b6ea2cd0868e6ed1fc253647afda80ecdef8/composer.json#L21-L31)).
- Uses `neural_seo` as its client identifier
  ([neuralseo.php](https://github.com/hokoo/neuralseo/blob/6272b6ea2cd0868e6ed1fc253647afda80ecdef8/neuralseo.php#L21-L25),
  [Factory.php](https://github.com/hokoo/neuralseo/blob/6272b6ea2cd0868e6ed1fc253647afda80ecdef8/src/Factory.php#L21-L29)).
- Defines two `1-m` relations from custom post types to WooCommerce `product`,
  with explicit `from`/`to` and no `type`
  ([General.php](https://github.com/hokoo/neuralseo/blob/6272b6ea2cd0868e6ed1fc253647afda80ecdef8/src/Controllers/General.php#L56-L74)).
- Uses the high-level relation/query API. No direct storage access, factory
  replacement, lifecycle hook registration, `load()` or `getPosts()` call was
  found in the inspected default-branch snapshot.

## Compatibility surface and evidence

| Surface | Public evidence | Consequence |
| --- | --- | --- |
| Client identifiers | `my-app-wpc-client`, `cf7-telegram`, `cf7-vk`, `neural_seo` | CORE-05/CORE-06 must preserve these logical names, REST paths and existing table mappings. Hyphen and underscore inputs can map to the same table suffix today. |
| Physical table mapping | Both CF7 integrations derive `post_connections_{normalized-client}` and the matching meta table; CF7 Telegram also calls WPStorage table getters. | Renaming prefixes, changing hyphen normalization, or hiding table getters can break maintenance/migration code. Treat table mapping as legacy compatibility input even if it is not the desired API. |
| Relation definitions | Six distinct relation names across the three consumers; every definition supplies `from`, `to`, `cardinality` and omits `type`. | CORE-01 can require `to`; M2's deprecated no-op `type` preserves serialized legacy data without being needed by observed source consumers. Existing cardinality strings remain compatibility-critical. |
| `Client::getStorage()` | One public mutation call and one public WPStorage introspection flow. | SPI-01 must distinguish supported custom-storage substitution from unsupported direct writes. A high-level orphan-removal alternative and migration note are needed before consumers can leave direct mutation. |
| Factory replacement filters | No external public usage found for storage, REST API or logger class filters. | Absence is not permission to remove the SPI. Test its existing signatures and document the boundary; private consumers remain unknown. |
| Client capability filter | Used by both active CF7 integrations. | Hook name construction, timing before client initialization, callback input and returned capability are release-blocking compatibility behavior for REL-02. |
| Relation/storage lifecycle actions | No external public registration found in the searched sources. | Keep them in the compatibility matrix because public search is incomplete; record current callback arguments before changing mutation internals. |
| `Connection::load()` | No documentation example or public call found. | Supports the approved PHPDoc/docs-only deprecation in API-02, but does not prove private consumers are absent. No runtime notice should be added in v1. |
| `ConnectionCollection::getPosts()` | No public call found. CF7 Telegram instead projects a column and hydrates its own entities. | Issue #20/API-01 should design selection, projection and entity filtering independently; this empty method is not evidence for a one-parameter API. |
| REST client URLs | README and both CF7 integrations construct URLs containing the logical client name. | CORE-05 must treat REST identity separately from normalized table identity and detect collisions before data is shared. |

## Inputs to downstream tasks

### CORE-05 and CORE-06

1. Preserve the observed logical identifiers and their existing table suffixes:
   `cf7-telegram -> cf7_telegram`, `cf7-vk -> cf7_vk`, and
   `neural_seo -> neural_seo`.
2. Design an explicit collision result for pairs such as `cf7-telegram` and
   `cf7_telegram`; they have different logical/REST names but the current
   storage normalizer aliases them.
3. Add compatibility fixtures for a hyphenated and an underscore client and for
   two independently initialized clients. CF7 Telegram and CF7 VK are credible
   co-installation candidates.
4. Do not change table prefixes or normalization as an incidental refactor.
   Consumers have maintenance code coupled to both.

### API-02

1. Deprecate only `Connection::load()` as approved by DG-M5.
2. Use PHPDoc and migration documentation only in v1; public evidence is absent,
   but private usage cannot be excluded.
3. Point consumers to the relation query flow. Do not conflate this with issue
   #20 or the separate `getPosts()` design.

### SPI-01

1. Preserve the existing factory filter signatures while the storage contract
   is specified and tested.
2. Classify `getStorage()` table introspection and mutations as legacy direct
   coupling, not as the preferred domain API. Document what remains compatible
   in v1.
3. Provide or identify a high-level way to remove a known connection before
   discouraging `deleteSpecificConnections()`; CF7 VK has a concrete need for
   orphan cleanup.
4. Include table getter/mapping behavior in migration analysis even though the
   getters are WPStorage-specific and absent from the abstract storage class.

### REL-02

1. Add compatibility coverage for the per-client capability filter used by the
   CF7 consumers.
2. Freeze callback arguments and ordering for factory filters and mutation
   lifecycle actions before refactoring atomic operations.
3. Test high-level create/find/update behavior with the observed relation
   definitions and both client-name styles.

## Negative evidence and search limits

The following searches returned only wpConnections itself, vendored copies, or
no public match: `ConnectionCollection getPosts`, the factory storage/REST/logger
filter names, `wpConnections/relation/creating`,
`wpConnections/relation/created`, and `wpConnections/storage/installOnInit`.
The three independent dependency repositories were also searched locally for
`load()`, `getPosts()`, custom storage subclasses, all `wpConnections/` hooks
and `getStorage()` calls.

These results have important limits:

- GitHub code search and the inspected clones cover public indexed/default-
  branch code, not released ZIP contents, deleted branches or every fork.
- Composer installs, private plugins and private repositories are invisible.
- A dependency declaration does not prove that the latest branch is deployed;
  the observed lock files explicitly pin older library commits.
- A missing match is recorded as “no public evidence found”, never as proof
  that there are no consumers.

No credentials, private repository names, private source paths or token-derived
content are included in this artifact.
