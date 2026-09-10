# Test quality and critical-scenario contract

This document is the canonical test-quality contract for wpConnections. It
defines the behavior that must be demonstrated by tests; the coverage runbook
defines how to execute the current tooling.

The contract applies to production changes, test changes, and release
candidates. A passing global coverage percentage never substitutes for a test
of an affected critical scenario.

## Quality profiles

### Pull-request profile

Every pull request must keep the exact statement-coverage ratio at or above the
accepted ratio in [`coverage-baseline.json`](../coverage-baseline.json). The
current baseline is `365/786` statements. It may move upward after a reviewed
source or test change; it must not be lowered merely to make a check pass.

The pull-request profile does not require 70% coverage. Until the release
candidate, its global coverage gate is monotonic no-regression. In addition, a
change that affects a critical component must identify every affected scenario
ID from the registry below and provide a relevant automated test even when the
global coverage gate is green.

Changes to the covered source set, PHPUnit coverage configuration, or the
baseline denominator require explicit reviewer attention. Removing or
excluding source is not an acceptable way to improve the ratio.

### Release-candidate profile

A release-candidate commit is acceptable only when both conditions are true:

1. statement coverage is at least 70%; and
2. every scenario in the critical registry has passing automated evidence on
   that exact commit.

An active exception for any critical scenario makes the release candidate
unacceptable. TEST-03B will automate the separate pull-request and explicit
release-candidate coverage profiles; the 70% target must not become an
ordinary feature-PR gate. REL-03 owns the final release-candidate decision and
evidence snapshot.

## What counts as test evidence

Evidence for a critical scenario records:

- the scenario ID;
- the exact test identifier, normally `path::testMethod` or an equivalent
  stable filter;
- the command and environment/matrix lane used;
- the passing result for the pull-request head commit.

A test must exercise the public domain API or extension boundary named by the
scenario and assert its observable contract. Depending on the scenario, that
means exact result data, exception type and stable domain code, persisted rows
and absence of orphan rows, HTTP status and response shape, or hook name,
arguments, order, and call count. Fixture creation, absence of a PHP fatal, and
an increased assertion count are not by themselves evidence.

Negative and failure-path tests must prove that rejected work leaves no partial
mutation. REST evidence must dispatch through `WP_REST_Server`; a direct call
to a handler does not cover route registration, argument validation,
permissions, serialization, or HTTP status. Transaction scenarios require a
controlled fault between persistence steps. Query scenarios assert the exact
result identities and multiplicity and the absence of WordPress database
warnings.

One test may provide evidence for several scenario IDs when it independently
asserts every listed expectation. One scenario may use several tests. The pull
request must make that mapping explicit rather than relying on filename or
coverage inference.

## Critical-component trigger map

The paths are reviewer aids, not an exhaustive allowlist. A change elsewhere
that alters the listed behavior still triggers the corresponding scenarios.

| Component | Typical production paths | Required scenario families |
| --- | --- | --- |
| Relation definition and compatibility | `src/Relation.php`, `src/Abstracts/Relation.php`, `src/Query/Relation.php`, `src/RelationCollection.php`, `src/Client.php` | `REL-*`, applicable `ENT-*`, `CARD-*`, and `ERR-*` |
| Endpoint validation and domain mutation | `src/Relation.php`, `src/Connection.php`, `src/Abstracts/Connection.php`, `src/Client.php` | `ENT-*`, `CARD-*`, `ERR-*`, and applicable `STORE-*` |
| Queries, persistence, and metadata | `src/WPStorage.php`, `src/Abstracts/Storage.php`, `src/Query/*`, `src/Meta*`, `src/ConnectionCollection.php` | `QUERY-*`, `STORE-*`, `SCHEMA-*`, and `CLIENT-*` |
| Client identity and schema naming | `src/Client.php`, `src/WPStorage.php`, `src/Helpers/Database.php`, `src/Settings.php` | `CLIENT-*` and `SCHEMA-*` |
| REST API | `src/ClientRestApi.php`, `src/RestResponse/*`, `src/Capabilities.php` | `REST-*` plus any domain/storage scenario whose behavior is exposed by the changed route |
| WordPress hooks and factories | `src/Client.php`, `src/Relation.php`, `src/WPStorage.php`, `src/Factory.php`, `src/Logger.php`, extension interfaces and abstract classes | `HOOK-*`, `FACTORY-*`, and affected domain/storage scenarios |

## Critical-scenario registry

Every row below is release-critical. `Delivery task` identifies where the
automated evidence is expected to be completed; it is not evidence by itself.
Until an exact passing test is recorded in a pull request and the owning task,
the scenario remains pending for release-candidate purposes.

### Relation definition, endpoint validation, and cardinality

| ID | Minimum observable expectation | Delivery task |
| --- | --- | --- |
| `REL-DEF-01` | Missing or invalid required relation fields are rejected before registration; the exception identifies each offending field exactly once. | TEST-02A / CORE-01 |
| `REL-DEF-02` | A valid relation preserves its name, directed `from` and `to` endpoint types, cardinality, and compatibility fields through registration and lookup. | CORE-01 |
| `REL-TYPE-01` | Deprecated `relation.type` remains accepted and serialized in REST v1 as a no-op; changing it cannot alter validation, mutation, query, or traversal direction. | CORE-01 / REST-03 |
| `ENT-VAL-01` | Every high-level create and endpoint-changing update rejects a missing entity or a post whose type does not match the relation, before persistence is mutated. | CORE-04 |
| `ENT-EXT-01` | A configured extension strategy accepts its supported non-post entity and rejects unsupported or mismatched entities without weakening the default post validation. | CORE-00 / CORE-04 |
| `CARD-MM-01` | `m-m` accepts multiple distinct connections on both sides while duplicate/closure rules remain enforced. | CORE-02 / CORE-03 |
| `CARD-1M-01` | `1-m` allows one `from` to connect to many `to` entities and rejects a second distinct `from` for an occupied `to`. | TEST-02B / CORE-02 |
| `CARD-M1-01` | `m-1` allows many `from` entities to connect to one `to` and rejects a second distinct `to` for an occupied `from`. | TEST-02C / CORE-02 |
| `CARD-11-01` | `1-1` rejects occupation of either endpoint by a different connection. | CORE-02 |
| `CARD-MUT-01` | Endpoint updates pass through the same cardinality checks as creates and leave the original connection unchanged when rejected. | CORE-02 / CORE-04 |
| `ERR-PREC-01` | A duplicate that also violates cardinality returns duplicate code `303`; a forbidden self-connection returns `301`, a standalone cardinality violation `302`, and an update without ID `304`. | CORE-03 |
| `ERR-CODE-01` | Domain failures preserve their exception type and stable numeric domain code independently of any REST HTTP status. | CORE-03 / REST-00A |

### Query, persistence, schema, and client isolation

| ID | Minimum observable expectation | Delivery task |
| --- | --- | --- |
| `QUERY-DIR-01` | `from` and `to` filters preserve the physical `from -> to` direction and return only exact matching connections. | DB-01 |
| `QUERY-BOTH-01` | `both` finds a connection from either endpoint exactly once and emits no `wpdb::prepare` warning. | TEST-02E / DB-01 |
| `QUERY-COMB-01` | ID priority, relation/direction combinations, empty results, ordering contract, and repeated meta keys produce the documented rows without cross-relation leakage. | DB-01 |
| `STORE-CREATE-01` | Creating a connection with metadata commits all connection/meta rows or rolls them all back after an injected intermediate failure. | DB-02 / DB-05 |
| `STORE-UPDATE-01` | Updating endpoints, title, order (including `0`), and metadata preserves omitted fields and is fully rolled back after an injected intermediate failure. | DB-02 / DB-05 |
| `STORE-META-01` | Append, replace, selective delete, and delete-all metadata preserve duplicate keys and allowed falsy values, with no partial result after failure. | DB-02 / DB-05 |
| `STORE-DELETE-ID-01` | Single- and multiple-ID deletion removes exactly the selected connections and their metadata; invalid and not-found inputs follow the approved result/error contract. | DB-03A / DB-03B / DB-05 |
| `STORE-DELETE-DIR-01` | Directed-pair deletion removes every matching duplicate and its metadata without changing an unrelated direction, relation, or client. | DB-03A / DB-03B / DB-05 |
| `STORE-DELETE-OBJECT-01` | Object deletion for both/from-only/to-only and relation-filtered variants removes all and only matching rows and metadata; conflicting flags are rejected safely. | DB-03A / DB-03B / DB-05 |
| `STORE-DELETE-ATOMIC-01` | An injected failure between connection and metadata deletion rolls back the complete ID, directed-pair, or object delete operation and emits no false success result or hook. | DB-03B / DB-05 |
| `STORE-TX-CAP-01` | Unsupported transactional storage is detected before a compound mutation and returns the approved explicit error rather than silently using best effort. | SPI-01 / DB-00 / DB-05 |
| `SCHEMA-INSTALL-01` | Clean and repeated installation creates or preserves both client tables and required indexes without losing data. | DB-06 |
| `SCHEMA-RECOVER-01` | Removing either or both client tables is recovered by the bounded retry path, after which the requested operation succeeds. | DB-06 |
| `SCHEMA-FAIL-01` | An unrecoverable schema error returns the original informative failure without an infinite retry or partial schema/data state. | DB-06 |
| `CLIENT-ISO-01` | Read, create, update, and every delete path for one client cannot observe or mutate another client's connection or metadata rows. | CORE-06 / DB-03B |
| `CLIENT-NAME-01` | Empty, colliding, and overlong normalized client names are handled by the approved rule before unsafe SQL; valid and legacy names retain their documented table identities. | CORE-05 / CORE-06 |

### REST v1

| ID | Minimum observable expectation | Delivery task |
| --- | --- | --- |
| `REST-ROUTES-01` | Every declared route/method is registered and dispatches through `WP_REST_Server` with its argument and permission callbacks. | REST-01 / REST-03 |
| `REST-CRUD-01` | Connection create, read, partial update, and delete return the compatible v1 shape; omitted fields are preserved and rejected requests do not mutate storage. | TEST-02D / REST-00B / REST-02 / REST-03 |
| `REST-ERROR-01` | Validation, not-found, cardinality, duplicate, closed, and storage failures map stable domain codes to the approved HTTP statuses and v1 error shape. | REST-00A / REST-03 |
| `REST-PERM-01` | Each route distinguishes anonymous, default authenticated, and explicitly granted/denied clients; denial occurs before handler mutation. | REST-04 |
| `REST-META-01` | POST, PATCH, PUT, and DELETE metadata semantics cover duplicate keys, allowed falsy values, omitted input, selective removal, and delete-all through full dispatch. | REST-05 |
| `REST-FILTER-01` | Relation-list filters for relation and explicit `from`/`to`/`both` directions have the approved combination semantics, permission/client isolation, empty results, and backward-compatible unfiltered output. | REST-06 |
| `REST-V1-01` | Default response fields and nesting remain backward compatible; new representations are opt-in and deprecated `type` remains a serialized no-op. | REST-03 / REST-06 |

### WordPress hooks and extension factories

| ID | Minimum observable expectation | Delivery task |
| --- | --- | --- |
| `HOOK-CASCADE-01` | A real `wp_delete_post`/`deleted_post` flow removes incoming, outgoing, and self-connections plus metadata for each registered client without cross-client deletion or duplicate hook execution. | DB-04 |
| `HOOK-CONTRACT-01` | Public actions and filters retain their documented names, argument order/count, timing, and once-only behavior; a rolled-back operation cannot emit a false success hook. | DB-05 / REL-02 |
| `FACTORY-EXT-01` | Valid custom storage, REST API, and logger implementations supplied through the public factory filters are selected and invoked through their supported extension contracts. | SPI-01 / REL-02 |
| `FACTORY-INVALID-01` | Missing or incompatible factory replacements fail with the stable exception contract and do not leave a partially registered client. | REL-02 |

## Pull-request checklist contract

Authors use the critical-component trigger map before review. A pull request
that changes a critical component must list all affected IDs, add or update the
corresponding tests, and provide the evidence fields defined above. If no ID is
affected, the author must state why; leaving the field blank is not an answer.

Reviewers verify behavior-to-scenario mapping, not only changed-line coverage.
A global coverage pass cannot waive scenario evidence. A production change and
its test should normally land together; the plan's explicitly approved
red-first vertical slices may record the red result and deliver the paired fix
in the same final green commit or pull request.

## Flaky tests, quarantine, and exceptions

A failure is not called flaky merely because a rerun passes. Classification
requires evidence that the same commit and environment produce different
results, or that order/seed changes expose shared state. The first failing run
remains visible; a diagnostic rerun must not replace it as the required-check
result.

Quarantine is a last-resort, temporary exclusion of the narrowest already
versioned test. It cannot replace writing a critical-scenario test. Deleting a
test, adding an unconditional skip, weakening its assertion, or silently
retrying until green is not quarantine.

Every exception or quarantine record must contain all of these stable fields:

| Field | Required value |
| --- | --- |
| `id` | Unique `TQ-EX-NNN` identifier. |
| `scenario_ids` | A non-empty JSON array of affected critical IDs, or `["none"]` for a non-critical test. |
| `test` | Exact test path and method/filter. |
| `owner` | Named person or team responsible for removal. |
| `reason` | Reproduced failure and why immediate repair is unsafe or blocked. |
| `issue` | Link to the tracking issue with reproduction evidence. |
| `expires_on` | Hard review/removal date in `YYYY-MM-DD`; an expired record is invalid. |
| `exit_condition` | Measurable condition that removes the exception. |
| `scope` | Exact command, lane, seed, or test exclusion; broad suite exclusions are invalid. |
| `approved_by` | Repository merge owner who accepted the temporary non-RC risk. |

The canonical active-exception registry is
[`test-quality-exceptions.json`](../test-quality-exceptions.json). Its schema-1
`exceptions` array contains objects with exactly the fields above; an empty
array means there are no accepted exceptions. A record becomes active only
when all fields are present and the approving change is merged. It must be
removed when its exit condition is met and may not be renewed by editing only
the date.

An expired or incomplete record fails the exception policy. Any active record
whose `scenario_ids` is not `["none"]` blocks the release-candidate profile even
if coverage is at least 70%. A non-critical exception still requires explicit
release review under REL-03.

When diagnosing an order-dependent failure, record the commit, matrix lane,
suite, order, repeat count, random seed, first failing test, and logs in the
issue. The fix removes the registry row and demonstrates that the original
seed plus fresh reverse and seeded-random runs are stable.

## Automation inputs for TEST-03B and TEST-03C

TEST-03B must preserve the default exact no-regression comparison, report
release-candidate readiness without failing an ordinary pull request at the
current baseline, and expose a separate explicit profile that requires at
least 70% statements. Its synthetic tests cover baseline pass/regression,
release-candidate below/equal/above 70%, and malformed input as a configuration
error distinct from policy failure.

TEST-03C must run both unit and WordPress integration suites in reverse order
with at least two repeats and in random order with at least two repeats and an
explicitly printed seed. The same seed must be accepted for local reproduction.
There is no silent retry-to-green path. Its exception validation uses the exact
field names and active-registry rules above, and it must not rename existing
required GitHub checks without a branch-protection review.

The executable commands and exit-code contract are maintained in the
[CI runbook](ci-runbook.md). This document remains the policy source; the
runbook does not redefine the critical scenarios or exception fields.
