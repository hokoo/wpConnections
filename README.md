# WP Connections: post-to-post connections for WordPress
[![PHP CS](https://github.com/hokoo/wpConnections/actions/workflows/php-cs.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/php-cs.yml)
[![Unit Tests](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests-docker.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests-docker.yml)
[![WP Integration Tests](https://github.com/hokoo/wpConnections/actions/workflows/wp-integration-tests.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/wp-integration-tests.yml)

<!-- TOC -->
* [Why wpConnection?](#why-wpconnection)
* [Quick Start](#ok-what-should-i-do-to-start-using)
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
