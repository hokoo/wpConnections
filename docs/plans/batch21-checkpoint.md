# Batch 21: delivered qualification checkpoint — 2026-09-22

The repository owner resumed execution on 2026-09-22 and authorized the
protected delivery recorded below. B21-01—B21-10 are `completed`: the local
candidate, protected PR head, exact merge and post-merge checks are all green.
B21-Q and Batch 21 / DB-04-Q are `review` pending fresh independent closure QA
of the synchronized closeout documentation and its required committed/merged
delivery. This checkpoint records qualification evidence; it does not
pre-empt that final acceptance.

## Candidate and boundary

Branch: `batch21-deleted-post-qualification`.

- Batch base: `f7e94af0e9b039da90260162db44ea30635fc3ec`.
- Frozen source/test candidate:
  `062b7fefb3e4cec6261b3a9b101958f47219f2b1`.
- Protected PR #108 head:
  `1e8a4c9dce91aa805fb2f26733d81c47044ec1f2`.
- Exact protected merge:
  `a341f9b89427e64c66dd4ab03d8d3f664e18fd2a` at
  `2026-09-22T09:33:19Z`.
- Historical pause-only documentation commit: `a45d68219fdc75d9695a5de5d7a1d45f889e8f6f`.

All technical results below belong to the tracked content of `062b7fe`. They
must not be attributed to `a45d682` or to this later evidence update. The whole
Batch 21 candidate changes tests and documentation only; it changes no
production source, Composer contract, workflow, public API or schema. The
user's untracked `AGENTS.md` and `.codex/` remained unchanged and excluded.

The remaining qualification was run from a clean `git archive` export at
`/tmp/wpconnections-b21-export`. Its archive SHA-256 is
`1c864943534ded101431a6183d98d36be30ea984f7cd53450cf2aea48130cbf5`.
Every tracked path was content-hashed against `062b7fe`: zero mismatches are
recorded in `/tmp/wpconnections-b21-run/tracked-content-mismatches.txt`.
`composer.lock` remained identical, with SHA-256
`321283585dd86f7e273c8d26aacaabc0ef3553603fa9f0f9cccf8aac319045a7`.
Only transient `vendor/`, `wordpress/` and build content was generated inside
the disposable export. The original checkout remained unchanged, and all
disposable database containers and networks were removed.

## Exact local evidence for `062b7fe`

The current-runtime lanes used PHP 8.1.34, WordPress 7.1-src (requested
7.1.0), Ramsey Collection 1.3.0 and embedded MariaDB 11.8.6. Commands ran from
the repository root. The fixed-floor and vendor lanes used PHP 8.1.34,
WordPress 6.7.7-src and Ramsey Collection 1.3.0 from
`/tmp/wpconnections-b21-export`. Every row exited 0.

| Command / lane | Result | Saved log |
| --- | --- | --- |
| `make tests.phpunit` | current unit: 141 tests / 518 assertions | `/tmp/wpconnections-b21-uQxgT6/01-tests.phpunit-escalated.log` |
| `make tests.integration` | current single-site: 516 tests / 4733 assertions / 10 expected multisite-only skips | `/tmp/wpconnections-b21-uQxgT6/02-tests.integration.log` |
| `make tests.multisite` | current true multisite: 516 tests / 4831 assertions / no skips | `/tmp/wpconnections-b21-uQxgT6/03-tests.multisite.log` |
| `make tests.isolation ISOLATION_SEED=20260922` | unit reverse and random repeat 2: each 282 / 1036; integration reverse and random repeat 2: each 1032 / 9466 with 20 skips; 261.56 s | `/tmp/wpconnections-b21-uQxgT6/04-tests.isolation.log` |
| `make lint.phpcs` | PHP 8.1.34 / WordPress 7.1-src / Ramsey 1.3.0; 100/100 files, no violations; 1.43 s | `/tmp/wpconnections-b21-run/06-lint.phpcs-confirmation.log` |
| `make tests.coverage` | fixed floor: 657 tests / 5249 assertions / 10 skips; 3833/4172 statements (91.87%); 50.17 s | `/tmp/wpconnections-b21-run/02-tests.coverage.log` |
| `docker run --rm -v "$PWD:/srv/web" wpconnections-coverage:php8.1.34-wp6.7.7 test:multisite` | fixed-floor true multisite: 516 tests / 4831 assertions / no skips; 38.88 s | `/tmp/wpconnections-b21-run/03-tests.multisite-fixedfloor.log` |
| Digest-pinned MySQL 8.0.46 setup and integration command below | 516 tests / 4733 assertions / 10 expected multisite-only skips; 128.94 s | `/tmp/wpconnections-b21-run/04-mysql-8.0.46.log` |
| Digest-pinned MariaDB 10.11.16 setup and integration command below | 516 tests / 4733 assertions / 10 expected multisite-only skips; 39.10 s | `/tmp/wpconnections-b21-run/05-mariadb-10.11.16.log` |

The first `make tests.phpunit` and first `make lint.phpcs` attempts were denied
Docker-socket access before their tests started. PASS is attributed only to the
saved successful logs named above. The PHPCS ruleset deprecation remains a
known non-blocking warning.

The vendor commands below use only static disposable test credentials. They
follow the digest-pinned workflow topology and ran from the clean export:

```sh
docker network create --subnet=10.253.0.0/16 wpconnections-b21-mysql-net-20260922
docker run --rm -d --name wpconnections-b21-mysql-20260922 --network wpconnections-b21-mysql-net-20260922 -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress_test -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress mysql:8.0.46@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b
docker run --rm --network wpconnections-b21-mysql-net-20260922 -v "$PWD:/srv/web" -e DB_START_MODE=external -e DB_HOST=wpconnections-b21-mysql-20260922 -e DB_NAME=wordpress_test -e DB_USER=wordpress -e DB_PASSWORD=wordpress -e EXPECTED_DB_VERSION_PREFIX=8.0.46 -e RAMSEY_VERSION=1.3.0 wpconnections-coverage:php8.1.34-wp6.7.7 test:integration

docker network create --subnet=10.254.0.0/16 wpconnections-b21-mariadb-net-20260922
docker run --rm -d --name wpconnections-b21-mariadb-20260922 --network wpconnections-b21-mariadb-net-20260922 -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress_test -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress mariadb:10.11.16@sha256:4045aba619003d93b5dc834e89e6815ba078d2cb3ff0a26f316ab5d7eab35093
docker run --rm --network wpconnections-b21-mariadb-net-20260922 -v "$PWD:/srv/web" -e DB_START_MODE=external -e DB_HOST=wpconnections-b21-mariadb-20260922 -e DB_NAME=wordpress_test -e DB_USER=wordpress -e DB_PASSWORD=wordpress -e EXPECTED_DB_VERSION_PREFIX=10.11.16-MariaDB -e RAMSEY_VERSION=1.3.0 wpconnections-coverage:php8.1.34-wp6.7.7 test:integration
```

`/tmp/wpconnections-b21-run/verification-summary.txt` records the exact
working directories, durations, cleanup and the successful explicit Compose
recipe behind the saved PHPCS confirmation. The manifest maps every R1—R6 and
`HOOK-CASCADE-01` named test to the relevant passing local lanes.

## Independent QA history and remaining review

The first independent final gate returned `fail`. At that time its failure was
limited to the missing durable evidence/status record and missing external
protected CI, protection, merge and post-merge criteria. Review of the changed
source/test mapping and operations runbook found no P0—P3 technical or
operational finding and no new decision gate. That remediation closed the
documentary gap only; it was not a fresh QA run or epic acceptance.

Fresh independent QA then reviewed documentation commit
`a03d59818eee2abe6b1da25d24511001627241fd` and confirmed that the durable
evidence gap is closed: exact-candidate attribution, saved results, named
R1–R6/`HOOK-CASCADE-01` mappings and task statuses are accurate. Its overall
gate remained `fail` at that point solely for the external delivery criteria.
No local technical rerun or repair was indicated. This later root-authored
checkpoint records that historical gate; it does not change the reviewed
implementation.

Before merge, fresh independent exact-candidate QA confirmed all pre-merge
requirements, found no P0—P3 finding or new decision gate, and found the
candidate technically ready. The earlier overall `fail`, limited to missing
merge/post-merge evidence and synchronized closeout documentation, is
superseded by the successful delivery below. Fresh independent closure QA of
this synchronized closeout remains pending, so B21-Q and DB-04-Q remain in
`review` rather than `completed`.

One P4 warning-attribution note remains non-blocking: retained migration tests
call `deleted_post` with `null`, after which WordPress core
`_wp_after_delete_font_family` reads `post_type`. The base has 25 such calls
and the candidate 24, so Batch 21 did not add the pattern; all new real flows
supply a `WP_Post`. No risk waiver was requested, recommended or accepted.

## Protected delivery completed

Historical preflight on 2026-09-22 found no PR, `master` at Batch 21 base
`f7e94af0e9b039da90260162db44ea30635fc3ec`, and 19 strict required contexts
without the existing dedicated multisite job. That preflight was subsequently
resolved under the repository owner's continuing delivery authority.

PR [#108](https://github.com/hokoo/wpConnections/pull/108) used exact head
`1e8a4c9dce91aa805fb2f26733d81c47044ec1f2` and passed all 20 required
contexts across runs
[unit](https://github.com/hokoo/wpConnections/actions/runs/35710174099),
[integration/multisite](https://github.com/hokoo/wpConnections/actions/runs/35710174082),
[database](https://github.com/hokoo/wpConnections/actions/runs/35710174039),
[coverage](https://github.com/hokoo/wpConnections/actions/runs/35710174129) and
[styles](https://github.com/hokoo/wpConnections/actions/runs/35710174076).
Branch protection required those 20 contexts with `strict: true`, GitHub
Actions app id `15368`, admin enforcement enabled, and force pushes and
deletions disabled.

PR #108 merged at `2026-09-22T09:33:19Z` as exact merge
`a341f9b89427e64c66dd4ab03d8d3f664e18fd2a`. All 20 post-merge contexts
completed successfully, with no failed, skipped, cancelled or stale result, in
runs
[unit](https://github.com/hokoo/wpConnections/actions/runs/35711052082),
[integration/multisite](https://github.com/hokoo/wpConnections/actions/runs/35711052219),
[database](https://github.com/hokoo/wpConnections/actions/runs/35711052099),
[coverage](https://github.com/hokoo/wpConnections/actions/runs/35711052256) and
[styles](https://github.com/hokoo/wpConnections/actions/runs/35711052168).
No acceptance criterion was waived. HOOK-04, REL-02 and REL-03 remain release
dependencies, and Batch 21 creates no tag or release. REST-04 is the next ready
batch under its existing task contract.
