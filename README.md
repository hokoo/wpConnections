# WP Connections: post-to-post connections for WordPress
[![PHP CS](https://github.com/hokoo/wpConnections/actions/workflows/php-cs.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/phpunit.yml)
[![PHP WordPress Unit Tests](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests.yml)
[![Dockerfile Unit Tests](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests-docker.yml/badge.svg)](https://github.com/hokoo/wpConnections/actions/workflows/wp-unit-tests-docker.yml)

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
- Docker Desktop, Docker Compose
- Make

### Installation
1. Clone this repo to the **Ubuntu disk space**. Location path should look like `\\wsl$\Ubuntu-20.04\home\username\path\to\the\repo`.
2. Make sure you have `make` installed in your system. If not, run `sudo apt install make`.
3. Make sure you have installed Docker Desktop with configured WSL2 support if you are using Windows.
4. Add `127.0.0.1 wpconnections.local` to the hosts file (on the host machine).
5. Run folowing command in the root directory to install the project:
```bash
bash ./local-dev/init.sh && make tests.init && make docker.up && make dev.install
```

### Running the test suites

The project ships with a dedicated `Dockerfile.phpunit` image that bundles Composer, the WordPress test library and an embedded MariaDB server so the entire PHPUnit stack runs inside a single container locally and in CI. After the installation step you can run all tests from the project root with:

```bash
make tests.run
```

Behind the scenes this calls `docker compose` with the `phpunit` service defined in `local-dev/docker-compose.yml`. The service no longer depends on any other containers—the entrypoint spins up MariaDB and configures the WordPress test library on demand—so these commands can be executed anywhere Docker is available. You can also run the individual commands manually, for example:

```bash
docker compose -f local-dev/docker-compose.yml run --rm phpunit composer run phpunit
docker compose -f local-dev/docker-compose.yml run --rm phpunit vendor/bin/phpunit -c php-wp-unit.xml
```

The same Dockerfile is also used by the optional GitHub Actions workflow defined in `.github/workflows/wp-unit-tests-docker.yml`, allowing you to compare its output against the long-standing `wp-unit-tests.yml` pipeline before switching over entirely. You can pin WordPress to a specific release by passing `--build-arg WP_VERSION=6.5.2` (or any other version number) when building the image.
