# Database compatibility and transaction feasibility

Status: decision-ready design artifact for `DB-00`; recommendations in pending
gates are not approved behavior.

Source snapshot: `0db202e7d4a794fd21d82d5305f51f40cb583b92`.

Research date: 2026-09-10.

## Purpose and authority

This document records what database products the repository actually exercises,
which capabilities are required by approved DG-M7 atomicity, and which choices
still require the repository owner. It is the canonical decision body for
DG-DB-01 through DG-DB-04. The decision registry and approval status remain in
[`docs/plans/02-library-hardening.md`](plans/02-library-hardening.md).

Observed behavior is evidence, not a compatibility promise. A recommended
option remains pending until the registry is updated by the owner.

## Current repository state

| Surface | Observed state | Consequence |
| --- | --- | --- |
| Protected integration and coverage CI | [`Dockerfile.phpunit`](../Dockerfile.phpunit) installs Debian's unpinned `mariadb-server` and starts it inside every PHP/WordPress image. The workflow matrix varies PHP, WordPress and Ramsey, but not the database product/version. | The green matrix currently proves one MariaDB build selected at image-build time, not a supported MySQL/MariaDB matrix. The 2026-09-10 fixed-floor image reported MariaDB 11.8.6. |
| Local development | [`local-dev/docker-compose.yml`](../local-dev/docker-compose.yml) uses floating `mysql:8` and a persistent host volume. | Two developers can receive different MySQL patches; an old volume can hide schema-install behavior. This is not reproducible compatibility evidence. |
| Table creation | [`WPStorage::install()`](../src/WPStorage.php#L45) sends two `CREATE TABLE` definitions through [`Database::install_table()`](../src/Helpers/Database.php#L24). Neither definition includes `ENGINE`. | Both tables inherit the session/server default engine. Modern defaults happen to be InnoDB, but the library does not require or verify it. |
| Schema recovery | `createConnection()` retries an insert after calling the private installer. The installer can run `dbDelta()`, which may execute `CREATE TABLE` or `ALTER TABLE`. | DDL in a mutation path can implicitly commit an outer/data transaction. Schema readiness must be separated from data atomicity. |
| Compound mutations | Create-plus-meta and all connection-plus-meta deletes issue multiple SQL statements without a transaction. Update and meta replacement are also separate operations. | Approved DG-M7 is not implementable safely until engine, schema and transaction capabilities are checked before mutation. |
| Transaction ownership | No repository code issues `START TRANSACTION`, `SAVEPOINT`, `COMMIT` or `ROLLBACK`; WordPress `$wpdb` exposes the same session used by callers. | Blindly starting a transaction can commit a caller-owned transaction. Nesting must be explicit or savepoint-aware. |
| Table identifiers | Physical names are `$wpdb->prefix` plus `post_connections_` or `post_connections_meta_` plus a normalized client name. | MySQL and MariaDB both limit table identifiers to 64 characters. CORE-05 must budget the complete physical name, not only the client postfix. |

## External compatibility facts

- WordPress currently recommends MariaDB 10.11 or newer, or MySQL 8.0 or newer;
  its legacy minimum is not a safe modern support target. See the official
  [WordPress requirements](https://wordpress.org/about/requirements/).
- InnoDB supports explicit transactions and savepoints. MySQL documents
  [`START TRANSACTION`, `COMMIT` and `ROLLBACK`](https://dev.mysql.com/doc/refman/8.0/en/commit.html)
  and [savepoints](https://dev.mysql.com/doc/refman/8.0/en/savepoint.html);
  MariaDB documents the equivalent [transaction](https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction)
  and [savepoint](https://mariadb.com/docs/server/reference/sql-statements/transactions/savepoint)
  behavior.
- Both products implicitly commit an active transaction around many DDL
  statements, including `CREATE TABLE` and `ALTER TABLE`. See the official
  [MySQL implicit-commit list](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html)
  and [MariaDB implicit-commit list](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).
- Both products limit table identifiers to 64 characters. See the official
  [MySQL identifier limits](https://dev.mysql.com/doc/refman/8.0/en/identifier-length.html)
  and [MariaDB identifier rules](https://mariadb.com/docs/server/reference/sql-structure/sql-language-structure/identifier-names).
- WordPress [`dbDelta()`](https://developer.wordpress.org/reference/functions/dbdelta/)
  compares a supplied `CREATE TABLE` statement with existing schema and can run
  schema-modifying queries. It is a schema tool, not part of a rollback-safe DML
  transaction.

The library should follow the WordPress recommended database floor rather than
claiming the much older legacy minimum. Supporting an old WordPress runtime and
supporting an end-of-life database are separate promises.

## Reproducible capability probes

The probes used disposable official images and an isolated database named
`wpconnections_probe`:

| Product | Exact image | Digest |
| --- | --- | --- |
| MySQL | `mysql:8.0.46` | `sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b` |
| MariaDB | `mariadb:10.11.16` | `sha256:4045aba619003d93b5dc834e89e6815ba078d2cb3ff0a26f316ab5d7eab35093` |

### Copy/paste MySQL 8.0.46 probe

Run the complete block. The 65-character identifier and `@@in_transaction`
commands are expected to fail; the assertions make unexpected success fail the
probe. The exit trap removes the disposable container after success or failure.

```bash
set -euo pipefail
PROBE_CONTAINER=wpconnections-db00-mysql80
PROBE_PASSWORD=wpconnections-db00
probe_cleanup() {
  docker stop "$PROBE_CONTAINER" >/dev/null 2>&1 || true
}
trap probe_cleanup EXIT

docker run --rm -d --name "$PROBE_CONTAINER" \
  -e MYSQL_ROOT_PASSWORD="$PROBE_PASSWORD" \
  mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b
for attempt in {1..60}; do
  docker exec "$PROBE_CONTAINER" mysql -uroot -p"$PROBE_PASSWORD" \
    --execute='SELECT 1;' >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$PROBE_CONTAINER" mysql -uroot -p"$PROBE_PASSWORD" \
  --execute='SELECT 1;' >/dev/null

docker exec -i "$PROBE_CONTAINER" mysql -uroot -p"$PROBE_PASSWORD" \
  --batch --raw <<'SQL'
SELECT 'runtime' AS probe, VERSION() AS version, @@version_comment AS comment,
  @@default_storage_engine AS default_engine,
  @@transaction_isolation AS isolation_level, @@autocommit AS autocommit;
DROP DATABASE IF EXISTS wpconnections_probe;
CREATE DATABASE wpconnections_probe;
USE wpconnections_probe;
CREATE TABLE default_t (id INT PRIMARY KEY);
CREATE TABLE innodb_t (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE myisam_t (id INT PRIMARY KEY) ENGINE=MyISAM;
SELECT 'engines' AS probe, table_name, engine FROM information_schema.tables
  WHERE table_schema = 'wpconnections_probe' ORDER BY table_name;
START TRANSACTION;
INSERT INTO innodb_t VALUES (1);
INSERT INTO myisam_t VALUES (1);
ROLLBACK;
SELECT 'rollback_engines' AS probe,
  (SELECT COUNT(*) FROM innodb_t) AS innodb_rows,
  (SELECT COUNT(*) FROM myisam_t) AS myisam_rows;
START TRANSACTION;
INSERT INTO innodb_t VALUES (10);
SAVEPOINT wpconnections_probe_sp;
INSERT INTO innodb_t VALUES (11);
ROLLBACK TO SAVEPOINT wpconnections_probe_sp;
RELEASE SAVEPOINT wpconnections_probe_sp;
COMMIT;
SELECT 'savepoint' AS probe, GROUP_CONCAT(id ORDER BY id) AS ids FROM innodb_t;
DELETE FROM innodb_t;
START TRANSACTION;
INSERT INTO innodb_t VALUES (20);
START TRANSACTION;
INSERT INTO innodb_t VALUES (21);
ROLLBACK;
SELECT 'nested_start_result' AS probe,
  GROUP_CONCAT(id ORDER BY id) AS ids FROM innodb_t;
DELETE FROM innodb_t;
START TRANSACTION;
INSERT INTO innodb_t VALUES (30);
CREATE TABLE ddl_t (id INT PRIMARY KEY) ENGINE=InnoDB;
ROLLBACK;
SELECT 'ddl_implicit_commit' AS probe,
  (SELECT COUNT(*) FROM innodb_t) AS rows_after_rollback,
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'wpconnections_probe' AND table_name = 'ddl_t')
    AS ddl_table_exists;
SET SESSION default_storage_engine = MyISAM;
CREATE TABLE no_engine_clause (id INT PRIMARY KEY);
SELECT 'session_default_override' AS probe, @@default_storage_engine, engine
  FROM information_schema.tables WHERE table_schema = 'wpconnections_probe'
  AND table_name = 'no_engine_clause';
CREATE TABLE tttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt
  (id INT);
SELECT 'identifier_64' AS probe, table_name, CHAR_LENGTH(table_name) AS chars
  FROM information_schema.tables WHERE table_schema = 'wpconnections_probe'
  AND CHAR_LENGTH(table_name) = 64;
SQL

if docker exec "$PROBE_CONTAINER" mysql -uroot -p"$PROBE_PASSWORD" \
  --execute="USE wpconnections_probe; CREATE TABLE ttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt (id INT);";
then
  echo 'Expected the 65-character MySQL identifier to fail' >&2
  exit 1
fi
if docker exec "$PROBE_CONTAINER" mysql -uroot -p"$PROBE_PASSWORD" \
  --execute='SELECT @@in_transaction;';
then
  echo 'Expected MySQL 8.0.46 to reject @@in_transaction' >&2
  exit 1
fi
docker exec "$PROBE_CONTAINER" mysql -uroot -p"$PROBE_PASSWORD" \
  --execute='DROP DATABASE wpconnections_probe;'
```

### Copy/paste MariaDB 10.11.16 probe

Run the complete block. It uses MariaDB's `@@tx_isolation` and requires
`@@in_transaction` to exist. The 65-character identifier is the only expected
SQL failure.

```bash
set -euo pipefail
PROBE_CONTAINER=wpconnections-db00-mariadb1011
PROBE_PASSWORD=wpconnections-db00
probe_cleanup() {
  docker stop "$PROBE_CONTAINER" >/dev/null 2>&1 || true
}
trap probe_cleanup EXIT

docker run --rm -d --name "$PROBE_CONTAINER" \
  -e MARIADB_ROOT_PASSWORD="$PROBE_PASSWORD" \
  mariadb:10.11.16@sha256:4045aba619003d93b5dc834e89e6815ba078d2cb3ff0a26f316ab5d7eab35093
for attempt in {1..60}; do
  docker exec "$PROBE_CONTAINER" mariadb -uroot -p"$PROBE_PASSWORD" \
    --execute='SELECT 1;' >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$PROBE_CONTAINER" mariadb -uroot -p"$PROBE_PASSWORD" \
  --execute='SELECT 1;' >/dev/null

docker exec -i "$PROBE_CONTAINER" mariadb -uroot -p"$PROBE_PASSWORD" \
  --batch --raw <<'SQL'
SELECT 'runtime' AS probe, VERSION() AS version, @@version_comment AS comment,
  @@default_storage_engine AS default_engine, @@tx_isolation AS isolation_level,
  @@autocommit AS autocommit, @@in_transaction AS in_transaction;
DROP DATABASE IF EXISTS wpconnections_probe;
CREATE DATABASE wpconnections_probe;
USE wpconnections_probe;
CREATE TABLE default_t (id INT PRIMARY KEY);
CREATE TABLE innodb_t (id INT PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE myisam_t (id INT PRIMARY KEY) ENGINE=MyISAM;
SELECT 'engines' AS probe, table_name, engine FROM information_schema.tables
  WHERE table_schema = 'wpconnections_probe' ORDER BY table_name;
START TRANSACTION;
INSERT INTO innodb_t VALUES (1);
INSERT INTO myisam_t VALUES (1);
ROLLBACK;
SELECT 'rollback_engines' AS probe,
  (SELECT COUNT(*) FROM innodb_t) AS innodb_rows,
  (SELECT COUNT(*) FROM myisam_t) AS myisam_rows;
START TRANSACTION;
INSERT INTO innodb_t VALUES (10);
SAVEPOINT wpconnections_probe_sp;
INSERT INTO innodb_t VALUES (11);
ROLLBACK TO SAVEPOINT wpconnections_probe_sp;
RELEASE SAVEPOINT wpconnections_probe_sp;
COMMIT;
SELECT 'savepoint' AS probe, GROUP_CONCAT(id ORDER BY id) AS ids FROM innodb_t;
DELETE FROM innodb_t;
START TRANSACTION;
INSERT INTO innodb_t VALUES (20);
SELECT 'before_nested_start' AS probe, @@in_transaction AS in_transaction;
START TRANSACTION;
SELECT 'after_nested_start' AS probe, @@in_transaction AS in_transaction;
INSERT INTO innodb_t VALUES (21);
ROLLBACK;
SELECT 'nested_start_result' AS probe,
  GROUP_CONCAT(id ORDER BY id) AS ids FROM innodb_t;
DELETE FROM innodb_t;
START TRANSACTION;
INSERT INTO innodb_t VALUES (30);
CREATE TABLE ddl_t (id INT PRIMARY KEY) ENGINE=InnoDB;
ROLLBACK;
SELECT 'ddl_implicit_commit' AS probe,
  (SELECT COUNT(*) FROM innodb_t) AS rows_after_rollback,
  (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = 'wpconnections_probe' AND table_name = 'ddl_t')
    AS ddl_table_exists;
SET SESSION default_storage_engine = MyISAM;
CREATE TABLE no_engine_clause (id INT PRIMARY KEY);
SELECT 'session_default_override' AS probe, @@default_storage_engine, engine
  FROM information_schema.tables WHERE table_schema = 'wpconnections_probe'
  AND table_name = 'no_engine_clause';
CREATE TABLE tttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt
  (id INT);
SELECT 'identifier_64' AS probe, table_name, CHAR_LENGTH(table_name) AS chars
  FROM information_schema.tables WHERE table_schema = 'wpconnections_probe'
  AND CHAR_LENGTH(table_name) = 64;
SQL

if docker exec "$PROBE_CONTAINER" mariadb -uroot -p"$PROBE_PASSWORD" \
  --execute="USE wpconnections_probe; CREATE TABLE ttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttttt (id INT);";
then
  echo 'Expected the 65-character MariaDB identifier to fail' >&2
  exit 1
fi
docker exec "$PROBE_CONTAINER" mariadb -uroot -p"$PROBE_PASSWORD" \
  --execute='SELECT @@in_transaction; DROP DATABASE wpconnections_probe;'
```

For each image, the probe performed these operations on one session:

1. Read server version/comment, default engine, isolation and autocommit.
2. Create default, explicit InnoDB and explicit MyISAM tables and inspect
   `information_schema.tables`.
3. Insert into InnoDB and MyISAM inside one transaction, then roll back.
4. Insert two InnoDB rows separated by a savepoint, roll back to the savepoint,
   release it and commit.
5. Start a transaction, insert a row, issue a second `START TRANSACTION`, insert
   another row and roll back.
6. Start a transaction, insert a row, issue `CREATE TABLE`, then roll back.
7. Override `default_storage_engine=MyISAM` for the session and create a table
   without an `ENGINE` clause.
8. Create a 64-character table identifier and attempt a 65-character one.

The containers were started with exact tags, queried through their bundled
client, and stopped with `docker stop`; `--rm` removed them. Re-run with a fresh
pull and record the resolved digest when updating the matrix.

### Probe results

| Probe | MySQL 8.0.46 | MariaDB 10.11.16 | Design consequence |
| --- | --- | --- | --- |
| Defaults | InnoDB, REPEATABLE READ, autocommit 1 | InnoDB, REPEATABLE READ, autocommit 1 | Current happy path is transactional, but only by server default. |
| InnoDB rollback | Insert removed | Insert removed | Connection/meta DML can be atomic on verified InnoDB tables. |
| MyISAM rollback | Insert remained | Insert remained | A transaction wrapper cannot satisfy DG-M7 on non-transactional tables. |
| Savepoint | Only the pre-savepoint row remained | Only the pre-savepoint row remained | Savepoints can preserve a caller-owned outer transaction on both tested families. |
| Second `START TRANSACTION` | First row committed; second rolled back | First row committed; second rolled back | Blind nesting destroys caller atomicity and is forbidden. |
| DDL then rollback | Pre-DDL insert remained and new table existed | Pre-DDL insert remained and new table existed | Installer/dbDelta cannot run inside the data transaction. |
| Session default MyISAM, no `ENGINE` | Table created as MyISAM | Table created as MyISAM | Current schema text does not guarantee atomic-capable tables. |
| Identifier length | 64 succeeds; 65 rejected | 64 succeeds; 65 rejected | Full physical-name validation is required before schema access. |

Transaction-state discovery differs. MariaDB exposes session
`@@in_transaction`; the tested MySQL 8.0 server rejects that variable. MySQL's
`performance_schema.events_transactions_current` can show the current session
when instrumentation and privileges permit, but it is not a portable library
contract. The transaction SPI must receive/own nesting context rather than
guessing it with vendor-specific SQL.

## Feasible transaction boundary

Subject to the pending gates, DB-05 can satisfy DG-M7 with this sequence:

1. Before any DML, validate that both client tables exist, both use InnoDB, and
   the selected adapter advertises the approved transaction capability.
2. Ensure schema installation/upgrades are complete outside the mutation. A
   missing/incompatible schema produces a stable pre-mutation error.
3. Enter an adapter-owned root transaction or an explicitly negotiated
   savepoint inside a caller-owned transaction. Never issue a second blind
   `START TRANSACTION`.
4. Execute connection and metadata DML. Any `false`, exception or `Throwable`
   rolls back the whole owned boundary.
5. Publish success results and success hooks only after root commit, or after a
   nested scope has been successfully released under the approved hook contract.
6. Preserve the caller's outer transaction; a nested library failure rolls back
   only to its savepoint and remains attributable.

This is capability feasibility, not approval of the public SPI shape. DG-SPI-04
still owns the capability and orchestration API; DG-SPI-06 owns commit-aware
hooks.

## CI matrix proposal

The smallest useful database matrix is orthogonal to the existing PHP/WordPress
matrix:

| Lane | Proposed role | Required evidence |
| --- | --- | --- |
| Pinned MySQL 8.0 patch | Blocking floor | Schema install/idempotence, full storage integration, engine check, transaction/savepoint/DDL probes. |
| Pinned MariaDB 10.11 patch | Blocking floor | Same evidence; this matches the current WordPress recommended MariaDB floor. |
| Newer MySQL maintained line | Non-blocking canary initially | Same DB-focused suite; promotion requires an owner decision and stable evidence. |
| Newer MariaDB maintained line | Non-blocking canary initially | Same DB-focused suite; promotion requires an owner decision and stable evidence. |

Exact patches must be pinned in workflow/service definitions and updated through
reviewed dependency maintenance. Floating `mysql:8`, distro-selected MariaDB and
`latest` are not release evidence. The existing PHP/WordPress lanes may retain
one pinned database for broad compatibility; DB-specific lanes need not multiply
every PHP, WordPress and Ramsey combination.

## Existing-table audit and migration

Before DB-05 is enabled for a client, inspect both physical tables in
`information_schema.tables` and report:

- resolved schema and table names;
- engine for each table;
- whether either table is missing;
- full identifier character length;
- whether both tables belong to the same client naming mapping;
- server product/version and transaction/savepoint probe result.

An engine migration can lock/rebuild large tables and can fail for environment,
space or privilege reasons. It must be an explicit administrator operation with
backup/rollback guidance. A normal mutation must never silently run `ALTER TABLE
... ENGINE=InnoDB`.

## Pending decision gates

<a id="dg-db-01"></a>
### DG-DB-01 — supported and blocking database matrix

**Problem:** current CI uses one unpinned MariaDB package while local development
uses floating MySQL. The project has no reviewable statement of which product
families are release-blocking.

- A: support the current WordPress recommended floors — MySQL 8.0 and MariaDB
  10.11 — with one exact pinned blocking patch lane for each. Exercise newer
  maintained families as non-blocking canaries until separately promoted.
- B: support only the MariaDB build embedded in the main test image and make
  MySQL best-effort.
- C: claim the WordPress legacy MySQL minimum and add old-server blocking lanes.

**Recommendation:** A. It covers both ecosystems already named by WordPress,
keeps the blocking cost bounded, and does not turn an untested future major into
an automatic compatibility promise.

**Compatibility impact:** A can expose MySQL-specific defects currently hidden
by MariaDB-only CI but does not change runtime behavior. B contradicts the
existing MySQL local-dev expectation. C creates a large legacy/security burden
and may prevent the transaction contract required by DG-M7.

**Blocks:** DB-05, DB-06, REL-01 and release support documentation.

<a id="dg-db-02"></a>
### DG-DB-02 — InnoDB requirement and legacy engine migration

**Problem:** schema definitions omit `ENGINE`, so existing or newly created
tables can be MyISAM. Probes show that rollback then leaves writes committed.

- A: require both wpConnections tables to be InnoDB for atomic mutations. Fail
  capability preflight before DML on missing/mixed/non-transactional tables and
  provide a separate explicit administrator migration.
- B: automatically convert tables to InnoDB when a Client initializes or the
  first mutation runs.
- C: retain any engine and document transactions as best-effort.

**Recommendation:** A. It is the only option compatible with DG-M7 without
silently running locking DDL during a request.

**Compatibility impact:** legacy MyISAM installations must migrate before using
the hardened mutation path. Existing reads and explicit cleanup can remain
available under the migration contract. B can cause unexpected locks/downtime;
C violates the approved all-or-nothing invariant.

**Blocks:** DB-05, DB-06 and REL-01 production/migration work.

<a id="dg-db-03"></a>
### DG-DB-03 — nested transaction and savepoint policy

**Problem:** issuing `START TRANSACTION` while the caller already owns a
transaction commits the caller's earlier work on both tested products. Portable
runtime discovery is unavailable through one common variable.

- A: make transaction ownership/context explicit in the approved SPI. A root
  scope uses `START TRANSACTION`; a declared nested scope uses a collision-safe
  savepoint and never commits the outer transaction. Unsupported nesting fails
  before mutation.
- B: let WPStorage inspect vendor-specific session state and choose root or
  savepoint implicitly.
- C: reject every caller-owned transaction and support root scopes only.

**Recommendation:** A, coordinated with DG-SPI-04. Explicit ownership is
portable and testable; capability detection remains adapter-specific without
leaking vendor probes into the domain API.

**Compatibility impact:** custom adapters need to declare/implement the chosen
capability before atomic compound mutations. C is simpler but breaks consumers
that legitimately compose library operations inside a larger transaction. B can
mis-detect state when performance-schema visibility or vendor behavior differs.

**Blocks:** DB-05 and REL-02 transaction conformance.

<a id="dg-db-04"></a>
### DG-DB-04 — schema recovery relative to data transactions

**Problem:** current create retry can call dbDelta after an insert fails. DDL
implicitly commits, so placing that retry inside an atomic mutation would make a
later rollback misleading.

- A: require schema readiness before the transaction. A mutation encountering
  missing/incompatible schema returns a stable pre-mutation error; installation
  and migration run through an explicit lifecycle outside DML transactions.
- B: preserve install-and-retry inside the transaction and accept DDL's implicit
  commit behavior.
- C: run schema recovery on a second connection while retaining the data
  transaction on the first.

**Recommendation:** A. It separates an administrative schema lifecycle from
request-time data atomicity and is deterministic on both supported products.

**Compatibility impact:** sites relying on lazy first-write table creation need
an activation/upgrade step or a backward-compatible preflight before DML. B
cannot meet DG-M7. C adds races, metadata locks and multi-connection complexity
without making DDL rollback-safe.

**Blocks:** DB-05, DB-06 and the create retry/migration portion of REL-01.

## Downstream acceptance matrix

| Consumer task | Input from DB-00 | Remains blocked by |
| --- | --- | --- |
| DB-05 atomic compound operations | Engine preflight, schema-before-DML ordering, root/savepoint feasibility and two-product floor | DG-DB-01—DG-DB-04, DG-SPI-03/04/06 and DG-UPDATE-03/04 |
| DB-06 schema lifecycle | Pinned DB lanes, explicit InnoDB creation/audit, no lazy DDL inside data transaction | DG-DB-01, DG-DB-02, DG-DB-04 and CORE-05 naming gates |
| REL-01 install/upgrade recovery | Existing-table engine audit, explicit administrative migration and failure evidence | DG-DB-01, DG-DB-02, DG-DB-04 |
| REL-02 custom storage conformance | Root/nested capability cases and unsupported-before-mutation behavior | DG-DB-03, DG-SPI-04, DG-SPI-06 |
| CORE-05 naming contract | Both vendors' 64-character full table-name limit | CORE-05-owned naming/migration gates; no DB gate approval implied |

## Non-gate constraints and caveats

- Do not change transaction isolation globally or per session merely to obtain
  atomicity. Both probes succeeded at the server default REPEATABLE READ; any
  isolation change needs a separate concurrency case.
- Atomic rollback of logical operations is distinct from crash durability.
  Settings such as redo-log flush policy belong to host operations and cannot be
  guaranteed by this library.
- WordPress core and other plugins share `$wpdb`. The library must restore no
  session setting it did not own and must not commit/roll back caller work.
- Savepoint release proves only the nested scope succeeded. Commit-aware public
  hooks still require DG-SPI-06 because the outer transaction may later roll
  back.
- The probes establish SQL capability, not complete wpConnections behavior.
  DB-05/DB-06/REL-01 must add application-level integration coverage on every
  approved blocking lane.

## Verification record

- Repository inventory covered all table creation, lazy recovery, multi-SQL
  mutations, transaction keywords, local Compose and protected CI workflows.
- MySQL 8.0.46 and MariaDB 10.11.16 produced the same rollback, savepoint,
  blind-nesting, DDL and identifier-boundary outcomes recorded above.
- The tested MariaDB exposes `@@in_transaction`; MySQL 8.0.46 returned error
  1193 for that variable, confirming vendor-specific detection cannot be the
  shared contract.
- No production source, schema, workflow or supported-version policy is changed
  by DB-00. All four material choices remain pending in the main registry.
