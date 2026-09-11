# WP Connections: post-to-post connections for WordPress
[![PHP CS](https://github.com/hokoo/wpConnections/actions/workflows/php-cs.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/php-cs.yml)
[![Unit Tests](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests-docker.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests-docker.yml)
[![WP Integration Tests](https://github.com/hokoo/wpConnections/actions/workflows/wp-integration-tests.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/wp-integration-tests.yml)

<!-- TOC -->
* [Why wpConnection?](#why-wpconnection)
* [Quick Start](#ok-what-should-i-do-to-start-using)
* [Deprecations](#deprecations)
* [WIKI](https://github.com/hokoo/wpConnections/wiki)
<!-- TOC -->

wpConnections allows to link posts in WordPress by graph-like connections.
The library provides such connection properties as: 

- direction (from, to)
- from post id
- to post id
- order
- meta data.

Connections belong to a Relation and never exist out.
The relation has properties:
- cardinality (1-1, 1-m, m-1, m-m)
- from post type
- to post type
- direction type (from, to, both)
- duplicatable (whether may have same connections)
- closurable (whether may have the same post on from and to).

> **EXAMPLE.** There are four CPT: `magazine`, `issue`, `article` and `author`.
> Magazine posts may have connections with some Issues (one-to-many type) so that the Issues constitute the Magazine.  
> The Issues in turn have connections with Articles (one-to-many as well).
> But an Author might have been linked with many Articles, and an Article might have many connections with Authors (many-to-many).  

## Why wpConnection?

It can be used as multiple installed library being parts of different plugins in a WordPress installation.
All you need is creating a client instance for your application. Every client has its own tables and REST API hooks, and does not influence to another clients. 

## Ok, what should I do to start using?

> Full documentation is available on [Wiki project pages](https://github.com/hokoo/wpConnections/wiki).

Add the package

```php
composer require hokoo/wpconnections
```

So, you have to create client instance...

```php
use iTRON\wpConnections\Client;

$wpc_client = new Client( 'my-app-wpc-client' );
```
...and relations for your connections.

```php
use iTRON\wpConnections\Query;
$qr = new Query\Relation();
$qr->set( 'name', 'post-to-page' );
$qr->set( 'from', 'post' );
$qr->set( 'to', 'page' );
$qr->set( 'cardinality', 'm-m' );

$wpc_client->registerRelation( $qr );
```

Ok, now you can create connections inside the relation.

```php
$qc = new Query\Connection();
$qc->set( 'from', $post_id_from );
$qc->set( 'to', $post_id_to );

$wpc_client->getRelation( 'post-to-page' )->createConnection( $qc );
```

### Endpoint validation compatibility

High-level create and update operations require both endpoint IDs to resolve to
the exact physical `from`/`to` WordPress post types declared by the relation.
Custom non-post types require a client-scoped `EntityResolverInterface` before
the client's first connection mutation. Direct storage calls remain a legacy
SPI and do not receive these domain guarantees.

Before upgrading an installation with existing data, run a read-only,
client-by-client inventory for missing IDs, wrong post types and relation types
without a registered resolver. Legacy-invalid rows remain readable and can be
deleted, including through the REST cleanup delegates and `deleted_post`
cascade, but cannot be updated through the domain API until repaired. The
library performs no automatic scan, repair or destructive migration. See the
[entity-validation contract and preflight guidance](docs/entity-validation-contract.md#release-and-read-only-preflight-guidance).

### Client identity and table isolation

Client names must be strings. They are normalized once with WordPress
`sanitize_title()` and the result must be non-empty lower-case ASCII containing
only letters, digits, `_` or `-`. The default `WPStorage` adapter preserves the
legacy table mapping by replacing hyphens with underscores; for example,
`my-app-wpc-client` uses unprefixed table names
`post_connections_my_app_wpc_client` and
`post_connections_meta_my_app_wpc_client`.

Both complete names, including the current site prefix, must fit the database's
64-character identifier limit. A versioned, non-autoloaded site-local WordPress
option claims each fresh table postfix atomically, so distinct logical names
such as `my-client` and `my_client` cannot silently share one pair. A default
storage object is bound to the WordPress site prefix used at construction and
must be recreated after `switch_to_blog()`; custom non-table Storage adapters
receive only the logical-name rules. Direct access through a stale default
storage object throws the documented prefix error. Its globally registered
`deleted_post` callback instead becomes a no-op before storage hooks or SQL, so
it cannot prevent the fresh current-site client from running its own cascade.
The 1.x callback remains the concrete storage method at priority 10, preserving
existing `remove_action()` usage. Cleanup is enabled automatically at Client
construction and can now be controlled without depending on callback identity:

```php
$client->disablePostDeletionCleanup();
$client->enablePostDeletionCleanup();
```

Both commands are idempotent. Direct callback removal remains compatible in
1.x, but consumers should migrate to these semantic methods before 2.0: the
context-aware subscription manager planned for that major version will own a
different WordPress callback identity. See the
[hook lifecycle transition contract](docs/hook-lifecycle-transition.md).

Existing complete tables without a matching ownership record, partial pairs or
malformed/conflicting records are rejected without automatic repair, rename or
delete. Operator-facing inventory and explicit attestation remain follow-up
work in DB-06/REL-03, so CORE-06 by itself is not release approval for an
existing unclaimed installation. See the
[client naming and migration contract](docs/client-naming-contract.md).

### Automatic debug logging and storage event origins

When `WP_DEBUG` is enabled, the library registers one process-global observer
for its three automatically logged storage events. The observer routes each
event to the logger owned by the originating `Client`; creating more clients
does not add more logging callbacks or broadcast an operation to other client
loggers.

Custom `Storage` implementations that emit these public actions must include
the origin in the documented position to receive automatic logging:

| Action | Arguments |
| --- | --- |
| `wpConnections/storage/findConnections/dbQuery` | SQL/query payload, raw result, trailing `Client` origin |
| `wpConnections/storage/removeConnectionMeta/after` | `Client` origin, object ID, meta selector, query payload, affected rows |
| `wpConnections/storage/deletedSpecificConnections` | `Client` origin, normalized connection IDs, affected rows |

The query action's third argument is additive: WordPress listeners registered
with an accepted-argument count of two continue to receive the original two
values. The origin is routing metadata and is not added to the PSR logger's
legacy context. If an event omits a valid origin, consumer callbacks still run,
but library-owned automatic logging safely skips that event. The default
`Logger::log()` continues to emit the `logger` compatibility action.

The observer remains a priority-10 callback inside each public action. This
change does not move the mutation actions; their approved commit-aware timing
will be implemented by DB-05 and verified by REL-02. Current mutation-event
emission remains unchanged in this task.

## Deprecations

`Connection::load()` is a deprecated legacy no-op and will be removed in
2.0.0. Use `Relation::findConnections()` to query existing connections. It
remains callable without a runtime notice during the current 1.x-compatible
line. See the [deprecation and migration guide](docs/deprecations.md).

Since you have initialized new client, its REST API endpoints are available.

`http://cf7tgdev.loc/wp-json/wp-connections/v1/client/my-app-wpc-client/`

## Local Development

### Prerequisites
- Windows 10 or later (WSL2), or Linux, or MacOS
- Docker Desktop, Docker Compose v2
- Make

### Installation
1. Clone this repo to the **Ubuntu disk space**. Location path should look like `\\wsl$\Ubuntu-20.04\home\username\path\to\the\repo`.
2. Make sure you have `make` installed in your system. If not, run `sudo apt install make`.
3. Make sure you have installed Docker Desktop with configured WSL2 support if you are using Windows.
4. Add `127.0.0.1 wpconnections.local` to the hosts file (on the host machine).
5. Run the following command in the root directory to install the project:
```bash
bash ./local-dev/init.sh && make docker.up && make dev.install
```

### Running the test suites

The project ships with a dedicated `Dockerfile.phpunit` image that bundles Composer, the WordPress test library and an embedded MariaDB server so the entire PHPUnit stack runs inside a single container locally and in CI. After the installation step you can run all tests from the project root with:

```bash
make tests.run
```

Behind the scenes this calls the `phpunit` service defined in `local-dev/docker-compose.yml` and aggregates the same entrypoint checks that GitHub Actions runs separately. The service no longer depends on any other containers: the entrypoint installs Composer dependencies when needed, spins up MariaDB only for WP integration tests, and configures the WordPress test library on demand.

`make tests.run` is the fast development loop: it reuses the existing test image,
while an idempotent `composer install` synchronizes the bind-mounted `vendor/`
directory with `composer.lock` before PHPUnit starts. Repeated runs with an
up-to-date lock file do not download the dependencies again. Before Composer
runs, the local entrypoint verifies that the image matches the current
Dockerfile, entrypoint, PHP input and WordPress input; a stale image fails with
the exact rebuild commands to use.

You can also run individual checks from the project root:

```bash
make tests.phpunit
make tests.integration
make tests.coverage
make lint.phpcs
```

See [`docs/ci-runbook.md`](docs/ci-runbook.md) for the canonical CI matrix,
local parity commands, coverage policy and failure-triage procedure.

`make tests.coverage` builds a deterministic PHP 8.1.34 / WordPress 6.7.7
image, runs the unit and WordPress integration suites in one instrumented
process, and checks the resulting statement coverage against the repository
baseline. The human-readable and machine-readable reports are written to
`build/coverage/`. The baseline stores the exact covered/total ratio rather
than a rounded percentage; update it only when a reviewed source or test change
intentionally changes the accepted baseline.

### Compatibility test matrix

Blocking CI uses exact version pins. Unit tests run the full Cartesian matrix of
PHP `8.1.34`, `8.2.33`, `8.3.33`, `8.4.25` and `8.5.10` against Ramsey
Collection `1.3.0` and `2.1.1` (ten jobs). WordPress integration tests use this
pairwise matrix:

| PHP | WordPress | Ramsey Collection |
| --- | --- | --- |
| 8.1.34 | 6.7.7 | 1.3.0 |
| 8.2.33 | 7.1.0 | 1.3.0 |
| 8.3.33 | 7.1.0 | 2.1.1 |
| 8.4.25 | 6.7.7 | 2.1.1 |
| 8.5.10 | 7.1.0 | 2.1.1 |

WordPress 6.7.7 is the pinned compatibility-floor lane, not a claim that this
older branch is still maintained upstream. Production installations should
follow the current WordPress security guidance. The exact stable pin is updated
deliberately when the supported matrix changes.

The scheduled `WP Trunk Canary` workflow runs WordPress `trunk` with PHP 8.5.10
and Ramsey Collection 2.1.1. It is not a pull-request or required check: a
failure is an upstream compatibility signal to triage, not a reason to make the
pinned blocking jobs non-reproducible. It can also be started manually with
`workflow_dispatch`.

The `johnpbloch/wordpress` package in `require-dev` is retained as a development
fixture. Docker integration tests load both core and the test library from the
same pinned `wordpress-develop` archive, so that Composer fixture neither selects
the Docker runtime nor defines this compatibility matrix.

The supported local interface uses Compose v2 (`docker compose`) consistently.
`make tests.integration` is the canonical WordPress integration-test target;
the former `make tests.wpunit` alias has been removed.

Rebuild the test image after changing `Dockerfile.phpunit` or its build inputs:

```bash
make tests.build
```

For a clean verification, rebuild the image without Docker layer cache and then
run both test suites:

```bash
make tests.clean
```

The underlying no-cache build is also available separately as
`make tests.rebuild`.

`make tests.init` is only needed for direct, non-Docker WordPress PHPUnit runs that rely on a local `wordpress-develop` checkout. The default local and CI paths use `Dockerfile.phpunit`.

The same Dockerfile is used by GitHub Actions workflows for unit tests and PHP code style checks. WordPress defaults to the exact stable pin `7.1.0`; another release must be an exact `x.y.z` tag passed with `--build-arg WP_VERSION=6.7.7`. The only symbolic input is `trunk`; ambiguous `latest` and the stale GitHub `master` branch are rejected.
