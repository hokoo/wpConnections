# Client identity, table naming, and migration contract

Status: approved decision contract; `CORE-06` implementation in progress

Source snapshot: `d1750731d7e94f4e3349600431a33bf6954d3106`.

Research date: 2026-09-11.

## Purpose and authority

This document separates the public logical client identity from the default
`WPStorage` table identity, records the compatibility evidence, and fixes the
approved naming and migration contract for implementation. It is the
canonical decision body for DG-NAME-01 through DG-NAME-06R. The decision
registry in [`docs/plans/02-library-hardening.md`](plans/02-library-hardening.md)
is the canonical record of status, owner, date, and selected option.

Nothing in this document by itself changes production code, creates or renames
a table, or writes an ownership record. The repository owner approved option A
for DG-NAME-01 through DG-NAME-06 and the staged A-to-D transition in
DG-NAME-06R on 2026-09-11. CORE-06 implements only the 1.x bridge after the
merged CORE-04 vertical; later tasks retain their other dependencies.

Approved DG-M6 is the fixed outer boundary: v1 hardening keeps one connections
table and one metadata table per client. A shared table with a `client` column
is out of scope and would require reopening DG-M6.

## Pre-CORE-06 identity pipeline

The legacy default path had four different values that must not be conflated:

1. **Raw input** is the value passed to [`Client::__construct()`](../src/Client.php#L28).
2. **Logical name** is `sanitize_title($raw)`, stored by `Client` and returned by
   `getName()`. It appears in REST paths, capability filters, lifecycle hooks,
   relation context, and factory input.
3. **Table postfix** is `str_replace('-', '_', sanitize_title($logical))` in
   [`Database::normalize_table_name()`](../src/Helpers/Database.php#L7).
4. **Physical table identifiers** are the effective `$wpdb->prefix` plus one of
   the two `WPStorage` prefixes plus the postfix.

`WPStorage` exposes only the unprefixed table names through
`get_connections_table()` and `get_meta_table()`. At initialization,
[`Database::register_table()`](../src/Helpers/Database.php#L12) also registers a
custom blog-table key and sets its dynamic `$wpdb` property. WordPress
`wpdb::set_blog_id()` recalculates that dynamic property because the key is in
`$wpdb->tables`; ordinary reads and writes also concatenate the then-current
`$wpdb->prefix` with the stored unprefixed name. Reusing one initialized client
across `switch_to_blog()` therefore moves both the registered property and DML
to the new site's prefix. What does *not* move or rerun is the completed
construction-time install/preflight and any future ownership claim. The new
site may have missing, incompatible, unclaimed, or differently owned tables
before the reused object issues DML. DG-NAME-06 owns that lifecycle choice.

The two fixed unprefixed table prefixes are:

| Table | Fixed prefix | Prefix length | Current full-name formula |
| --- | --- | ---: | --- |
| Connections | `post_connections_` | 17 | `$wpdb->prefix` + 17 + postfix |
| Metadata | `post_connections_meta_` | 22 | `$wpdb->prefix` + 22 + postfix |

The metadata table is always the stricter length case.

## Observed normalization matrix

The following probe ran the repository's current code with its floor WordPress
environment. The final column records the approved DG-NAME-01/A disposition;
it does not claim that current production code already enforces that outcome.

| Raw input | Current logical name | Current table postfix | Approved DG-NAME-01/A disposition |
| --- | --- | --- | --- |
| `my-client` | `my-client` | `my_client` | valid legacy-compatible name |
| `my_client` | `my_client` | `my_client` | valid alone; physical collision with `my-client` |
| `MY CLIENT` | `my-client` | `my_client` | raw alias of logical `my-client` |
| `café` | `cafe` | `cafe` | accepted normalized ASCII alias |
| `hello/world` | `hello-world` | `hello_world` | accepted normalized alias |
| `a.b` | `a-b` | `a_b` | accepted normalized alias |
| `Клиент` | `%d0%ba%d0%bb%d0%b8%d0%b5%d0%bd%d1%82` | same percent form | reject: `%` reaches unquoted SQL identifier text |
| `a%2Fb` | `a%2fb` | `a%2fb` | reject as unsafe after normalization |
| `!!!` | empty string | empty string | reject as empty after normalization |

Raw aliases that yield one logical name are already the same REST/hook/client
identity. In contrast, `my-client` and `my_client` remain different logical
identities but silently select the same physical pair today.

The approved canonical alphabet in DG-NAME-01/A is lower-case ASCII
`[a-z0-9_-]`. For that alphabet, byte length and character length are equal.
The raw input is never a database identifier and therefore has no separate
database length budget; the canonical logical name, postfix, and both complete
physical identifiers do.

No SQL-keyword blacklist is needed for the approved rule: the fixed table
prefix prevents a client name from being the complete keyword. Empty results,
percent escapes, path separators, dots, whitespace, quotes/backticks, control
characters, and anything outside the canonical alphabet are unsafe or reserved
as canonical names. Under DG-NAME-01/A compatibility normalization may
transform raw aliases first, but the resulting canonical value must pass the
approved non-empty ASCII check.

## Compatibility evidence and history

[`REL-00`](compatibility-inventory.md) found these public names and mappings:

| Consumer/evidence | Logical client | Existing default table postfix | Coupling |
| --- | --- | --- | --- |
| README/wiki example | `my-app-wpc-client` | `my_app_wpc_client` | documented REST/logical identity |
| `hokoo/cf7-telegram` | `cf7-telegram` | `cf7_telegram` | reconstructs both names and uses a `WPStorage` getter |
| `hokoo/cf7-vk` | `cf7-vk` | `cf7_vk` | reconstructs both names and directly invokes storage cleanup |
| `hokoo/neuralseo` | `neural_seo` | `neural_seo` | underscore logical identity; high-level API only |

The two active CF7 integrations are credible co-installation candidates. Their
hyphenated logical names must remain distinct from each other, and each existing
mapping must continue to find its current data.

Repository history from initial storage commit `cff8b1c7` through the source
snapshot consistently applies `sanitize_title()` and replaces hyphens with
underscores. Commit `3421a9a` extracted that exact behavior to the current
helper; it did not introduce a different mapping. There is consequently no
repository evidence for an older official `post_connections_cf7-telegram`
table. Private consumers, custom migration code, manually renamed tables, and
deployments pinned to old commits remain unknown.

REL-00 also proves that physical naming is observable in consumer maintenance
code. Approved DG-SPI-07/A separately decides that `Client::getStorage()` and
the concrete table getters remain available as legacy v1 introspection. This
contract does not promote table getters into the portable Storage SPI. CORE-05
did not itself approve DG-SPI-07; the owner approved it on 2026-09-11.

## Two-phase validation boundary

Logical identity and concrete table identity cannot be validated in one phase:
the storage factory filter must first select an adapter before the library knows
whether SQL tables exist at all. The approved DG-NAME-02/A flow is:

1. `Client` validates raw input, computes the canonical logical name once, and
   applies DG-NAME-01 before `Client::init()` invokes any factory. Rejection here
   has no factory, storage, client-lifecycle, registration, ownership, or SQL
   side effect; WordPress's standard `sanitize_title` filter remains part of
   canonicalization itself.
2. The existing storage factory selects its class. If it selects the default
   `WPStorage` table path, `WPStorage` performs the DG-NAME-03—DG-NAME-06
   postfix, complete-identifier, collision, ownership, and site-prefix
   preflight at the start of its constructor, before `Database::register_table()`
   or any connection/meta-table DDL or DML. A WordPress option read or atomic
   `add_option()` ownership claim is coordination state for that preflight; it
   must complete before the table pair is registered or accessed.
3. A custom non-table `Storage` receives the already validated logical name but
   does not receive `WPStorage` length, postfix, collision, ownership, or
   site-prefix checks and is never required to expose table getters. A custom
   adapter that deliberately reuses the concrete `WPStorage` table lifecycle is
   covered by the same concrete preflight and later REL-02 conformance.

This sequencing does not change the public storage-factory filter signature,
approve pending DG-SPI-05, or alter approved DG-SPI-07. CORE-06 places the
second phase in private `WPStorage` helpers; no new portable Storage capability
is introduced.

## Database identifier budget

The completed DB-00 coordination artifact was merged by
[PR #68](https://github.com/hokoo/wpConnections/pull/68) as
`d1750731d7e94f4e3349600431a33bf6954d3106`. Its exact `mysql:8.0.46` and
`mariadb:10.11.16` probes both accepted a 64-character table identifier and
rejected a 65-character identifier; see the canonical
[database compatibility contract](db-compatibility-contract.md). That result is
the only DB-00 input used here; it neither approves nor duplicates DG-DB-01
through DG-DB-04.

For a canonical ASCII name whose hyphens become one-for-one underscores, the
strict maximum is:

```text
max_client_characters = 64 - strlen(effective $wpdb->prefix) - 22
```

Examples:

| Effective WordPress prefix | Maximum logical/postfix length | Result |
| --- | ---: | --- |
| `wp_` (3 characters) | 39 | both complete identifiers can fit |
| `network_42_` (11 characters) | 31 | both complete identifiers can fit |
| 41 ASCII characters | 1 | only a one-character client can fit |
| 42 or more ASCII characters | 0 or less | no non-empty default-storage client can fit |

The check must apply to both complete names and must occur after `WPStorage` is
selected but before table registration or any connection/meta-table DML. The
ownership-option coordination described above is the only preflight write and
the only coordination mutation; read-only table-existence and schema
introspection remain part of the preflight. Checking only the client postfix,
truncating it, or counting raw UTF-8 bytes does not establish a safe identifier.
DG-NAME-04 owns the actual rejection or alternative mapping rule.

## Collision and ownership inventory

The connections and metadata schemas contain no client identity or ownership
marker. A physical pair alone cannot prove whether it belongs to `my-client`,
`my_client`, both through a historical collision, or a manually chosen name.
Relation names and row contents are supporting diagnostics, never proof of
ownership.

CORE-06 now performs the safe runtime subset before it claims or uses default
tables: complete-name length, pair existence/schema, ownership record, collision
and bound-site checks. Before DB-06/REL-03 creates an explicit adoption record,
the operator-facing read-only inventory still needs to report:

- raw input and resulting canonical logical name;
- current legacy postfix and any approved future postfix;
- exact effective WordPress prefix and both complete physical identifiers;
- whether neither, one, or both tables exist;
- row counts and structural/schema match for each existing table;
- every configured client name visible to the migration command that maps to
  the same postfix;
- whether a durable site-local owner record exists, its format version, and its
  canonical logical owner;
- whether the client was initialized for a different effective site prefix.

An underscore postfix is not reversible: `my_client` cannot be decoded into one
of `my-client` or `my_client`. When no owner record exists, adoption needs an
explicit administrator-supplied logical mapping. It must never be guessed from
the postfix or data.

## Migration state matrix

This matrix records the required planning outcome under approved
DG-NAME-02/A—DG-NAME-06/A.

| Detected state | Required safe disposition | Prohibited implicit action |
| --- | --- | --- |
| No table and no owner record | After name, length, site, and collision preflight, claim and create only under the approved lifecycle. | Creating tables before collision validation. |
| Complete legacy pair, no owner record, one declared logical candidate | Report the pair and require explicit administrator attestation before recording ownership, then continue in place under DG-NAME-05/A. | Guessing ownership merely because the suffix matches. |
| Complete pair and matching owner record | Use in place after schema and site-prefix checks. | Renaming or copying on ordinary client initialization. |
| Complete pair and conflicting owner record | Fail before registration/SQL and show both logical identities. | Sharing the pair or replacing the owner record. |
| Two different logical names map to one unclaimed pair | Treat as ambiguous even if only one is active now; require an operator mapping/split plan. | Letting initialization order choose the owner. |
| Only one table of the pair exists | Report partial schema; defer repair to DB-06 and the approved DB lifecycle. | Creating the missing half as a side effect of naming detection. |
| Unsafe, empty, or overlong current logical name | Keep read-only diagnostic/export access in an explicit migration tool; reject normal initialization under the approved policy. | Interpolating it into SQL or silently truncating it. |
| Custom non-table Storage selected | Apply the approved logical client-name rule and skip every `WPStorage` postfix, length, table, collision, ownership, and site-prefix check. | Requiring SQL table getters or default-adapter physical rules from every Storage adapter. |
| Client object observed after a site-prefix switch | Direct stale storage access throws the approved prefix error. Under the DG-NAME-06R 1.x bridge, inactive-prefix `deleted_post` dispatch returns `0` before storage hooks/SQL so it cannot block the fresh current-site callback; construct that fresh client before the event under the effective blog prefix. | Treating `$wpdb`'s recalculated table property as proof that install, schema, collision, or ownership preflight ran for the new site. |

Every migration alternative is non-destructive by default: it may inventory,
claim, copy, verify, or change a pointer, but it may not drop, truncate, rename,
overwrite, or delete the old tables. Any eventual cleanup is a separate,
explicit administrator operation with backup and rollback review.

### Approved migration path and separately gated follow-ups

- **Approved v1 — in-place adoption:** preserve the legacy pair and add an ownership claim
  after explicit mapping. This is the smallest compatibility change but cannot
  split a pair that two clients already used.
- **Separate future approval — copy, verify, then cut over:** create a new collision-free pair under an
  approved new mapping, copy IDs and rows, compare schemas/counts/checksums, and
  switch one client only after verification. Keep the source pair read-only for
  rollback. This needs a distinct physical mapping from DG-NAME-03.
- **Not approved for v1 — bounded dual-read migration:** write only to the approved destination, read
  destination first and legacy source second for a declared time/version, and
  report duplicate IDs/conflicts. Dual-write is excluded unless a later design
  proves atomicity under DG-M7 and the pending DB/SPI transaction gates.
- **Explicit recovery follow-up — offline export/split:** for an already shared ambiguous pair, require an
  administrator to assign rows to clients outside normal request handling,
  import each verified subset, and preserve the untouched source as rollback.

None of these alternatives may infer ownership from a relation name, silently
merge data, or make a shared table the steady state.

## Implementation evidence and downstream hand-off

Under the approved decisions, CORE-06 implements production validation and the
critical `CLIENT-NAME-01` / `CLIENT-ISO-01` scenarios from
[`docs/test-quality.md`](test-quality.md). DB-06 owns schema lifecycle on the
approved DB matrix. REL-02 owns public hooks/factory compatibility and the
DG-SPI-07 concrete-introspection path. REL-03 owns tested migration guidance.

The CORE-06 regression matrix covers its rows below; downstream owners retain
the explicitly shared or migration-only work:

| Area | Cases and assertions | Primary task |
| --- | --- | --- |
| Raw/canonical | observed valid names; upper-case/space/accent aliases; non-string; punctuation-only; percent-encoded Unicode; slash/dot; approved `ClientRegisterFail`, code `4`, and stable DG-NAME-02/A messages | CORE-06 |
| Logical identity | raw aliases resolve to one logical REST/hook identity; `my-client` and `my_client` remain distinct logical identities | CORE-06 / REL-02 |
| Physical collision | unclaimed, same-owner, conflicting-owner, and concurrent claim; factory selection may occur, but no table registration or connection/meta-table DDL/DML occurs before a rejected claim | CORE-06 |
| Length | boundary at 64 and rejection at 65 for both tables; default, long, and zero-budget WordPress prefixes; byte equals character for approved ASCII | CORE-06 / DB-06 |
| Legacy mapping | `cf7-telegram -> cf7_telegram`, `cf7-vk -> cf7_vk`, `neural_seo -> neural_seo`; complete, partial, ambiguous, and explicitly adopted pairs | CORE-06 / DB-06 |
| Isolation | two independent clients exercise read/create/update/every delete path without cross-client connection or metadata access | CORE-06 / DB-03B-A / DB-03B-B |
| Migration | DG-NAME-05/A dry-run inventory, explicit attestation, non-destructive in-place adoption, and rejection of ambiguous/shared pairs; a later copy/cutover remains separate work | DB-06 / REL-03 |
| Multisite | construction/use under one blog, `switch_to_blog()`, direct rejection of the bound object, fail-closed 1.x callback delivery, legacy callback removability and fresh per-blog client behavior under DG-NAME-06/A plus DG-NAME-06R | CORE-06 / REL-02 |
| Custom Storage | logical naming behavior is tested without assuming SQL tables; table introspection follows DG-SPI-07 only for concrete `WPStorage` | CORE-06 / REL-02 |

## Approved decision gates

<a id="dg-name-01"></a>
### DG-NAME-01 — raw input and canonical logical identity

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** `Client` currently applies `sanitize_title()` without validating
type, an empty result, percent escapes, or the characters subsequently placed in
REST paths, hooks, and SQL identifiers. Tightening it can reject private names,
while leaving it open permits unsafe or ambiguous identities.

- A: keep compatibility normalization for string inputs: compute
  `sanitize_title($raw)` once, then require a non-empty lower-case ASCII result
  matching `^[a-z0-9_-]+$`. Upper-case, spaces, common punctuation, and Latin
  accents may remain raw aliases when they normalize to a valid result. Do not
  define a separate raw byte limit because raw text is not persisted or used as
  an identifier; enforce all identifier budgets after canonicalization.
- B: require raw input itself to equal a non-empty lower-case ASCII canonical
  name matching `^[a-z0-9_-]+$`; reject every alias instead of normalizing it.
- C: support opaque UTF-8 logical identities with a new reversible REST/hook/DB
  encoding and versioned migration.

**Recommendation:** A. It preserves all observed public names and familiar
WordPress normalization while excluding empty and percent-encoded canonical
names before they reach unquoted identifier text. Raw aliases that normalize to
the same logical value are one identity, not a collision.

**Compatibility impact:** A newly rejects non-string input and raw values whose
current result is empty or contains `%`; private consumers using such values
must migrate. B also breaks benign aliases such as `MY CLIENT` and `café`. C
changes REST paths, hooks, table mapping, and migration complexity.

**Implementation consequences:** CORE-06, DB-06, REL-02, DOC-01 and REL-03.

<a id="dg-name-02"></a>
### DG-NAME-02 — registration failure surface and timing

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** naming rejection needs an attributable public result, but logical
validation must happen before storage selection while physical length,
collision, ownership, and site checks are meaningful only after the factory has
selected the default table adapter. The project already uses
`ClientRegisterFail` with default code 4 for registration failures, but has no
reason identifiers for naming failures.

- A: preserve `ClientRegisterFail` and code `4` in v1. Run adapter-neutral raw
  and canonical validation before `Client::init()` invokes any factory. After
  the storage factory selects `WPStorage`, run its physical checks at the start
  of construction before table registration or connection/meta-table SQL; an
  ownership-option read/atomic claim is the only permitted coordination I/O in
  that preflight. Custom non-table adapters receive only logical validation and
  never inherit `WPStorage` table rules. Make the following
  messages stable: `Client name must be a string.`, `Client name is empty or
  unsafe after normalization.`, `Client table mapping is already claimed by
  another client.`, `Client table identifier exceeds the 64-character database
  limit.`, `Client table ownership is ambiguous; explicit migration is
  required.`, and `Client storage is bound to a different WordPress site
  prefix.` Each phase throws before the side effects it owns: phase one before
  every factory; phase two after class selection but before `WPStorage` table
  registration, lifecycle hooks, DDL, or DML.
- B: add dedicated exception subclasses or new numeric reason codes for each
  naming failure in v1.
- C: expose a filter/result object that lets consumers accept or replace an
  invalid identity during construction.

**Recommendation:** A for v1. It keeps the existing exception family/code and
provides exact actionable outcomes without adding a public extension point.

**Compatibility impact:** A makes invalid logical names fail before the factory,
while WPStorage-only failures occur after the existing class-selection filter
but before concrete table side effects; messages become contractual. Custom
non-table adapters are not rejected for SQL identifier properties they do not
use. B expands the public error taxonomy and can break code expecting code 4. C
makes safety dependent on third-party callbacks and changes construction
semantics.

**Implementation consequences:** CORE-06, REST-03, REL-02, DOC-01 and REL-03.

**Nonblocking refinement:** DB-06 does not add DG-NAME-02 to its direct DoR or
Dependencies. It already waits for CORE-06 and reuses that implementation to
assert that a rejected concrete preflight performs no table registration, DDL,
or DML.

<a id="dg-name-03"></a>
### DG-NAME-03 — physical postfix and collision ownership

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** distinct logical identities such as `my-client` and `my_client`
currently map to one table pair. No table column or registry records which
logical client owns that pair, so initialization order can silently share data.

- A: preserve the legacy one-for-one hyphen-to-underscore postfix. Before table
  registration, atomically claim the postfix per WordPress site and reject a
  different canonical owner. Use one non-autoloaded, site-local WordPress option
  per postfix, with a deterministic hashed option key and a versioned value that
  stores the full postfix and canonical logical owner; `add_option()` provides
  the unique first claim. Existing tables follow DG-NAME-05 adoption.
- B: introduce a new reversible escaped postfix for new/unclaimed clients while
  preserving legacy mappings through an explicit adoption/cutover phase.
- C: canonicalize hyphens and underscores to one logical identity, so
  `my-client` and `my_client` are aliases at the REST/hook layer as well as in
  storage.

**Recommendation:** A. It preserves all observed table mappings and makes the
current collision a pre-write error instead of silent sharing. The exact option
key prefix is an internal CORE-06 implementation detail, but its site scope,
hash-derived uniqueness, non-autoload behavior, versioned value, and atomic
first-claim semantics are contractual.

**Compatibility impact:** A prevents a second logical client from continuing to
share an already claimed pair and adds a site option on first safe claim. B
changes table names for new clients and needs a migration tool. C changes public
REST routes, hooks, capabilities, and logical identity and would be breaking.

**Implementation consequences:** CORE-06, DB-06, DB-03B-A, REL-02 and REL-03.

<a id="dg-name-04"></a>
### DG-NAME-04 — complete physical identifier length

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** the database limit applies to the complete table name, while the
effective WordPress prefix varies by installation and multisite blog. Silent
truncation or hashing changes legacy mappings and can itself collide.

- A: allow a canonical default-storage client only when both ASCII physical
  names are at most 64 characters. Use the stricter formula
  `64 - strlen($wpdb->prefix) - 22`; reject before ownership claim, table
  registration, or SQL when the result is below one or the postfix exceeds it.
  Never truncate or hash a legacy postfix implicitly.
- B: when the legacy name does not fit, generate a versioned deterministic hash
  suffix and store the reversible logical-to-physical mapping in the ownership
  record.
- C: truncate the postfix to the available budget and reject only when two
  truncated values collide.

**Recommendation:** A. It is deterministic, directly reflects the verified
64-character limit, and cannot redirect an existing logical name to an
unexpected table.

**Compatibility impact:** A rejects overlong configurations that currently
proceed to failing or unsafe SQL; they need the migration tooling selected by
DG-NAME-05. B changes physical names and makes the registry mandatory for
lookup. C is lossy and creates a new collision class.

**Implementation consequences:** CORE-06, DB-06, REL-01 and REL-03.

<a id="dg-name-05"></a>
### DG-NAME-05 — legacy adoption and non-destructive migration

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** existing legacy table pairs predate an ownership registry and may
already be shared by colliding names. The suffix and row data cannot prove the
owner, while automatic rename/copy/delete can lose or misattribute data.

- A: use in-place adoption as the v1 default. A dry-run inventories the pair and
  all declared colliders; an administrator explicitly attests the canonical
  owner before the ownership record is created. Ambiguous/shared pairs and
  unsafe/overlong names remain read-only for export and fail normal writes until
  an offline split or separately approved copy/cutover is completed. Never
  rename or delete automatically.
- B: provide an explicit copy/verify/cutover migration to the reversible mapping
  from DG-NAME-03 B. Preserve IDs, schema, and both old tables; compare counts
  and conflicts before switching, and retain the old pair read-only for
  rollback.
- C: provide a bounded dual-read migration: write only to the new pair, read it
  first and the legacy pair second, reject duplicate-ID/content conflicts, and
  end fallback at an owner-selected release/date. Dual-write is not permitted
  without reopening this gate after DG-M7 and the DB/SPI transaction gates are
  implemented.

**Recommendation:** A for v1, with B as an explicit follow-up for installations
that must split a confirmed collision. It preserves known tables and makes
unknown ownership visible instead of guessing it.

**Compatibility impact:** A requires an administrative adoption step and can
block writes on ambiguous installations, but leaves data and names unchanged.
B requires additional storage and downtime/cutover coordination. C prolongs two
read paths and can expose historical conflicts to callers.

**Implementation consequences:** CORE-06, DB-06, REL-02 and REL-03.

<a id="dg-name-06"></a>
### DG-NAME-06 — WordPress prefix and multisite lifecycle

**Status:** approved A by the repository owner on 2026-09-11.

**Problem:** the current object stores unprefixed names, while both ordinary DML
and the custom dynamic `$wpdb` table property follow the current blog prefix:
`wpdb::set_blog_id()` recalculates registered blog-table properties. A completed
install or future ownership/preflight remains construction/site-bound and is not
rerun automatically. A long-lived object used across `switch_to_blog()` can
therefore issue DML against the new site's missing, incompatible, unclaimed, or
differently owned pair.

- A: bind a default `WPStorage` client to the effective blog prefix at
  construction. Reject direct use after the prefix changes; consumers must
  construct a fresh client after `switch_to_blog()` and restore the previous
  blog normally. Direct stale access throws the stable prefix
  `ClientRegisterFail`. The 1.x deferred-hook compatibility refinement is
  specified separately by DG-NAME-06R. Ownership records remain regular
  site-local options.
- B: make the client follow the current blog dynamically. Keep WordPress's
  recalculated dynamic property, and on every access re-run collision,
  ownership, length, and schema preflight for that blog before using its pair.
- C: use `$wpdb->base_prefix` and one network-global pair per logical client.

**Recommendation:** A. Object-to-site affinity is testable, keeps current
per-blog physical naming, and avoids hidden cross-blog reinitialization during a
mutation or callback.

**Compatibility impact:** A rejects consumers that currently reuse one client
object across blog switches; they must instantiate per blog. B adds runtime
preflight and hook lifecycle complexity. C redirects existing multisite data to
network-global tables and is a breaking data-isolation change.

**Implementation consequences:** CORE-06, DB-06, DB-04, REL-02 and REL-03.

<a id="dg-name-06r"></a>
### DG-NAME-06R — staged multisite hook lifecycle

**Status:** approved staged A-to-D by the repository owner on 2026-09-11.

**Problem:** `Client::init()` registers the default storage method directly on
WordPress's process-global `deleted_post` action. After `switch_to_blog()`, the
inactive site's older callback normally runs before a freshly constructed
current-site client. Throwing from that stale callback aborts WordPress action
dispatch before the applicable callback can clean its own tables. Replacing the
callback with a proxy immediately would fix dispatch identity but would also
break the current 1.x `remove_action('deleted_post', [$storage,
'deleteByObjectID'])` compatibility surface.

- **1.x bridge (A):** retain the exact direct callback identity and priority.
  During `deleted_post` dispatch only, an inactive-prefix default `WPStorage`
  returns `0` before wpConnections storage hooks or SQL. Direct stale storage
  use outside that dispatch continues to throw the DG-NAME-06 prefix
  `ClientRegisterFail`. A current-site client must exist before the deletion
  event; otherwise no callback owns cleanup for that site. Because the bridge
  can observe only the active WordPress filter, a manual stale
  `deleteByObjectID()` call made by third-party code from inside another
  `deleted_post` callback is also classified as cascade delivery and returns
  `0`. This fail-closed limitation is explicit and temporary.
- **1.x transition:** in a separate post-CORE-06 change, add idempotent semantic
  `Client::enablePostDeletionCleanup()` and
  `Client::disablePostDeletionCleanup()` methods. Keep the direct WordPress
  callback removable throughout 1.x, but document direct callback manipulation
  as a legacy pattern scheduled to stop working in 2.0. No runtime deprecation
  notice is added.
- **2.0 target (D):** register site-scoped callbacks through a context-aware
  subscription manager. The target callback is invoked only when the captured
  site identity and storage prefix match the effective context. Each
  subscription preserves priority, accepted argument count and ordering,
  exposes idempotent unsubscribe, and does not swallow failures from an active
  callback. Selection of an existing package or a separately published package
  owned by this project follows a build-versus-buy spike against that contract.

The manager is opt-in and does not intercept the global WordPress hook
registry. The first integration target is `deleted_post`; `rest_api_init`,
logging callbacks and indirect REST route dispatch require a separate complete
Client-owned-hook audit before any broader site-isolation claim. Manager-backed
hook delivery can cover custom adapters, but DG-NAME-06's direct stale-call
guarantee remains specific to default `WPStorage` unless a future Storage SPI
decision changes that boundary.

**Compatibility impact:** the 1.x bridge preserves callback identity and
existing `remove_action()` behavior. The semantic enable/disable API gives
consumers a non-identity-based migration path before 2.0. The manager switch is
an explicit major-version breaking change and requires an upgrade guide plus a
known-consumer search for direct `deleted_post` callback removal.

**Implementation consequences:** CORE-06 implements and verifies only the 1.x
bridge. The transition API, manager plan/package, complete hook audit, 2.0
integration and upgrade validation must be decomposed in a separate plan after
CORE-06 merges; they are out of scope for this branch.

## Decision consequences

The six approved A decisions remain independently traceable, but their
implementations must be coherent:

- any selected mapping still creates exactly one pair per client under DG-M6;
- DG-NAME-02 supplies the two-phase failure surface used by rejected outcomes in
  the other gates: logical checks before factory selection, and concrete
  `WPStorage` checks after selection but before table registration/access;
- DG-NAME-03 and DG-NAME-05 jointly determine whether a legacy pair is adopted
  or copied; no option silently shares it;
- DG-NAME-04 always budgets both complete identifiers using the site scope from
  DG-NAME-06;
- DG-NAME-06R preserves the 1.x callback identity while making inactive-site
  cascade delivery fail closed, then moves identity-independent subscriptions
  to the 2.0 major boundary;
- approved DG-SPI-07/A governs only concrete table introspection and migration
  compatibility; it does not turn SQL names into a portable adapter contract;
- DB-00 PR #68's 64-character result is feasibility evidence, not approval of
  any database support, engine, transaction, or schema-recovery gate;
- DB-06 is not a separate direct blocker of DG-NAME-02: it already waits for
  CORE-06. Its schema tests refine the implemented phase boundary by proving
  that a rejected concrete preflight performs no table registration, DDL, or
  DML. Its direct naming gates remain DG-NAME-01 and DG-NAME-03—DG-NAME-06.

CORE-05 completed the design artifact and registry entries. The repository owner
approved DG-NAME-01—DG-NAME-06/A, staged DG-NAME-06R/A-to-D, and DG-SPI-07/A
on 2026-09-11. CORE-06 locally enforces the runtime contract without a new public migration capability: fresh
pairs receive a versioned, hash-keyed, site-local non-autoloaded claim; matching
claims can use a complete compatible pair in place; and unowned, partial,
malformed or conflicting states fail without implicit repair, rename or delete.
The operator-facing dry-run, explicit attestation interface and rollout remain
owned by DB-06/REL-03. Consequently, the CORE-06 code alone is not a release
approval for installations with pre-existing unclaimed tables.
