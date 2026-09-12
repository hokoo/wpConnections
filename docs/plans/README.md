# Планы развития wpConnections

Этот каталог фиксирует исполняемый план, подготовленный после аудита репозитория,
тестов, GitHub issues и текущей CI-ветки 2026-09-09.

## Порядок исполнения

1. Инфраструктурный план завершён: [PR #48](https://github.com/hokoo/wpConnections/pull/48)
   влит в `master` 2026-09-10.
2. Batch 1—5 [основного плана](./02-library-hardening.md) завершены; PR
   #51—#65 последовательно зафиксировали решения, test foundation/contracts,
   первые production fixes и исполняемые quality gates. PR #66 активировал
   Batch 5, PR #67 завершил CORE-03 и закрыл issue #31.
3. Batch 5 завершён и закрыт PR #72 на `master` `5b60682`. Batch 6 завершён:
   TEST-02F/CORE-07 завершены, CORE-04 влит PR #75 как `7ec7643`, CORE-06R
   влит PR #76 как `2371ed2`; post-merge 17/17 jobs зелёные.
4. Batch 7 завершён: HOOK-TRANS-01 влит PR #77 как `5c2fc26`, final head и
   post-merge `master` прошли по 17/17 jobs. HOOK-00 влит PR #78 как
   `cf8caa6`, final head и post-merge также прошли 17/17. HOOK-02 получил
   independent QA PASS; candidate head `d7ab4bd` PR #79 прошёл 17/17 protected
   jobs, merge `cf67eee` и post-merge также прошли 17/17. Batch 8 завершён.
5. Все hook-transition gates, известные до implementation discovery Batch 10,
   а также DG-SPI-06/A и DG-RESTERR-03/A утверждены владельцем. HOOK-01 завершён:
   standalone package
   `hokoo/wp-hooks-dispatcher` (`iTRON\wpHooksDispatcher\`) опубликован в
   Packagist как `v1.0.1`. LOG-HOOK-01 завершён отдельным PR #82: exact
   candidate `234216e` получил independent QA PASS и 17/17 protected checks.
   Final head `6554089`, merge `73bc71f` и post-merge `master` также прошли
   17/17 checks. Batch 9 завершён.
6. DG-HOOK-REST-05/A утверждён владельцем: WordPress сохраняет native
   validation precedence, а stale Client code не вызывается ни на 400, ни на
   no-owner 404 path. REST-HOOK-01 completed на exact implementation head
   `9d5b74e`: independent QA PASS и 17/17 protected checks PR #83. Batch 10
   ожидает merge и post-merge verification.

Инфраструктурный task list находится в
[отдельном плане](./01-infrastructure-ci.md); его milestone M0 закрыт.
Staged 1.x-to-2.0 hook lifecycle contract, manager selection gate и delivery
map находятся в
[`docs/hook-lifecycle-transition.md`](../hook-lifecycle-transition.md).

## Правила ведения планов

- Статусы задач: `needs_design`, `waiting_dependency`, `todo`, `in_progress`,
  `blocked`, `review`, `completed`, `deferred`.
- Решение каждого decision gate заносится в таблицу решений соответствующего
  документа до перевода зависимых задач в `todo`.
- Одна задача должна помещаться в один reviewable PR. Объединять задачи можно
  только если у них общий риск, общий путь проверки и одна точка отката.
- Исправление обнаруженного дефекта начинается с воспроизводящего теста. PR
  должен показать красный тест до исправления и зелёный после него.
- Каждый PR обновляет статус своих задач и прикладывает команды проверки.
- Новые публичные контракты, изменения REST shape, error codes или схемы БД
  требуют явного решения gate и документации обратной совместимости.

## Текущий baseline

- Последний merged baseline после CORE-06R PR #76 (`2371ed2`) на PHP 8.1.34 /
  Ramsey 1.3.0: WordPress 7.1.0 и
  fixed-floor WordPress 6.7.7 дают unit `12 / 58`, integration `106 / 741`;
  true multisite focused lane `13 / 144`; isolation unit `24 / 116` и
  integration `212 / 1482`; PHPCS `45/45`. Fixed-floor PR/RC coverage —
  combined `118 / 799`, `957/1059` statements (`90.37%`), baseline `365/786`
  не изменён. Newest PHP 8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1 integration —
  `106 / 741` с только известными deprecation warnings.
- CORE-06R получил independent QA PASS; final head `93d09c6` и merge
  `2371ed2` прошли все 17 required jobs.
  Operator-facing naming inventory/attestation остаётся downstream
  DB-06/REL-03 work.
- HOOK-TRANS-01 добавил idempotent semantic cleanup API:
  current/fixed-floor unit `12/58`, integration `108/756`; true multisite
  `15/159`; isolation unit `24/116`, integration `216/1512`; combined coverage
  `968/1070 (90.47%)`; newest PHP 8.5 / WP 7.1 / Ramsey 2.1 integration
  `108/756`; PHPCS `45/45`. Independent QA на head `632da3a` — PASS без
  замечаний. Closure head PR #77 `1e98cf7` и merge `5c2fc26` прошли по 17/17
  protected/post-merge jobs; HOOK-TRANS-01 завершён.
- HOOK-00 decision packet не нашёл полностью conforming dependency;
  DG-HOOK-01/B утверждён владельцем 2026-09-11. HOOK-01 впоследствии опубликовал
  отдельный project-owned package `hokoo/wp-hooks-dispatcher` с PSR-4 namespace
  `iTRON\wpHooksDispatcher\`; координаты подтверждены 2026-09-12. Decision
  record не устанавливает dependency. Independent QA после remediation дала
  unconditional PASS; PR #78 и merge `cf8caa6` прошли по 17/17 jobs.
- HOOK-01 опубликован отдельным MIT package без Composer runtime dependencies:
  package PR #1 merge `ca0040f`, independent QA PASS, protected/post-merge CI
  `5/5`; clarity patch PR #2 merge `7f449c4` также прошёл independent QA и
  `5/5` protected/post-merge jobs. GitHub release/tag `v1.0.1` стал
  immutable после включения repository-level policy и in-place
  republication (`immutable: true` по API). Packagist отдаёт эту версию;
  clean PHP 8.1 install разрешил точный commit `7f449c4` и
  подтвердил PSR-4 autoload.
- LOG-HOOK-01 заменил три per-Client debug closures одним idempotent
  process-global observer и маршрутизирует запись через logger исходного
  Client без dependency на dispatcher. Exact candidate `234216e` прошёл
  independent QA без замечаний и 17/17 protected jobs. Fixed-floor combined
  run: `126 / 847`, coverage `991/1093 (90.67%)`; PHPCS `46/46`. Первый CI
  прогон обнаружил неоднозначную проверку combined bootstrap в новом тесте;
  после runtime-based remediation полный контур зелёный.
- [HOOK-02 audit](../client-owned-hook-inventory.md) нашёл пять Client-owned
  action registrations при `WP_DEBUG` и ни одного owned filter:
  `deleted_post`, `rest_api_init` и три debug callbacks. Runtime probes
  подтвердили cross-site delivery, REST route mixing, late-init gap,
  cross-client logging и leaked callbacks после failed construction.
  DG-HOOK-SCOPE-01/A, DG-HOOK-REST-01/B, DG-HOOK-REST-02/A,
  DG-HOOK-REST-03/A, DG-HOOK-REST-04/A, DG-HOOK-LOG-01/B и
  DG-HOOK-LIFE-01/A утверждены владельцем 2026-09-11.
  REST recommendation гарантирует native 404 до stale permission/handler
  callback, но осознанно не скрывает stale route name в index намеренно reused
  REST server; custom REST object сохраняется как current-context delegate.
  Independent QA дала unconditional PASS на content head `06b07a7`, а PR #79
  candidate head `d7ab4bd` прошёл все 17 protected jobs.
- Глобальный RC threshold 70% достигнут, но release candidate остаётся
  неготовым до прохождения всех 39 critical scenarios.
- Post-merge `master` имеет 17/17 успешных required jobs; пять первоначальных
  Composer-download HTTP 504 failures были pre-test transient и прошли selective
  rerun.
- `master` защищён: strict required checks для всех 17 jobs, enforcement для
  администраторов, force-push и удаление ветки запрещены.
- `TEST-01`, `TEST-02A`, `TEST-02B`, `TEST-02C`, `TEST-02E` и `TEST-02F`
  завершены. `TEST-02F` и `CORE-07` завершены одним paired vertical по
  утверждённому DG-QMETA-01/A: независимо проверенное red evidence сохранено,
  unit `7/19`, integration `68/353`, coverage `589/790 (74.56%)` и PHPCS
  `36/36` зелёные.
- `CORE-03` завершён PR #67: merge `b36fa85`, 17/17 required checks успешны,
  issue #31 закрыт; стабильные ошибки 301—304 и missing-endpoint matrix покрыты.
- DG-M1—DG-M9 утверждены владельцем 2026-09-10. M5 ограничен deprecation
  `Connection::load()`; `getPosts()` перенесён в отдельное исследование REST
  issue #20 вместе с filtering/traversal/representation contract.
- DP-1—DP-3 и их review refinements утверждены владельцем 2026-09-11:
  DG-QMETA-01/A, DG-UPDATE-01/02/02R/A, DG-SPI-01/02/07/A,
  DG-ENT-01—DG-ENT-06/A, DG-NAME-01—DG-NAME-06/A и staged
  DG-NAME-06R/A-to-D. В DP-4 утверждены DG-UPDATE-04/A и DG-SPI-06/A;
  остальной packet остаётся pending. DG-RESTERR-03/A также утверждён
  2026-09-11. Pending остаются DG-API20-01—DG-API20-09,
  DG-UPDATE-03/05, DG-SPI-03—DG-SPI-05,
  DG-DB-01—DG-DB-04,
  DG-RESTERR-01/02/04 и DG-DELETE-01—DG-DELETE-06; они блокируют только
  явно перечисленные downstream tasks. Полные тексты находятся в
  [related-entities/API issue #20 contract](../api-01-related-entities-contract.md),
  [partial update contract](../rest-partial-update-contract.md),
  [storage SPI contract](../storage-spi-contract.md),
  [entity validation contract](../entity-validation-contract.md),
  [database compatibility contract](../db-compatibility-contract.md),
  [REST error contract](../rest-error-contract.md),
  [delete result/failure contract](../delete-result-contract.md) и
  [client naming contract](../client-naming-contract.md); основной registry
  хранит canonical decision/status. REST-00A, REST-00B, SPI-01, CORE-00,
  DB-00, DB-03A и CORE-05
  завершили decision-ready discovery; это не означает неявного утверждения их
  рекомендаций.
- Canonical DB-03A
  [delete result/failure contract](../delete-result-contract.md) отделяет
  logical connection counts от metadata rows, relation-scoped domain/REST
  deletion от legacy client-wide SPI и внутреннюю atomic boundary от
  `deleted_post` recovery. DB-03B-A/DB-03B-B/DB-04/REST-03 остаются waiting до
  решений.
- Штатные regressions уже защищают missing-`to`, broken `both`, полную
  cardinality matrix и Query-meta materialization; REST update без `title`
  ожидает оставшиеся явно перечисленные dependencies.

## Контрольные точки

- **M0 — Infrastructure ready — completed 2026-09-10:** выполнен DoD
  инфраструктурного плана, PR #48 влит в `master`, post-merge CI зелёный.
- **M1 — Regression harness ready:** изолированные integration fixtures и шесть
  подтверждённых regression-тестов находятся в `master` вместе с fixes.
- **M2 — Core invariants stable:** validation/cardinality/duplicatable/
  closurable исправлены и защищены матрицей тестов.
- **M3 — Storage stable:** поиск, update, delete, meta cascade и schema recovery
  защищены integration-тестами.
- **M4 — REST v1 stable:** routes, permissions, CRUD, errors и meta проверяются
  через WordPress REST server.
- **M5 — Release ready:** утверждён compatibility contract, документация
  синхронизирована, release candidate проходит всю матрицу.
