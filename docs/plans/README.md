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
3. Batch 5 завершён и закрыт PR #72 на `master` `5b60682`. CORE-03 закрыл issue #31, а DB-00,
   REST-00A, DB-03A и CORE-05 подготовили decision-ready contracts. Batch 6
   активирован после утверждения DP-1—DP-3 владельцем 2026-09-11:
   TEST-02F/CORE-07 завершены; CORE-04 влит PR #75 как `7ec7643`; CORE-06R
   выполняет утверждённый совместимый 1.x callback bridge, после чего нужны
   полная повторная проверка, independent QA и merge.

Инфраструктурный task list находится в
[отдельном плане](./01-infrastructure-ci.md); его milestone M0 закрыт.

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

- Последний merged baseline после CORE-04 PR #75 (`7ec7643`): unit `12 / 58`,
  WordPress integration `93 / 606`, combined `105 / 664` и `817/951`
  statements (`85.91%`).
- Текущая CORE-06R branch на PHP 8.1.34 / Ramsey 1.3.0: WordPress 7.1.0 и
  fixed-floor WordPress 6.7.7 дают unit `12 / 58`, integration `106 / 741`;
  true multisite focused lane `13 / 144`; isolation unit `24 / 116` и
  integration `212 / 1482`; PHPCS `45/45`. Fixed-floor PR/RC coverage —
  combined `118 / 799`, `957/1059` statements (`90.37%`), baseline `365/786`
  не изменён. Newest PHP 8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1 integration —
  `106 / 741` с только известными deprecation warnings.
- CORE-06R ожидает independent QA и protected merge checks; локальный результат
  их не подменяет. Operator-facing naming inventory/attestation остаётся
  downstream DB-06/REL-03 work.
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
  DG-NAME-06R/A-to-D. В DP-4 отдельно утверждён только DG-UPDATE-04/A;
  остальной packet остаётся pending. Pending остаются
  DG-API20-01—DG-API20-09, DG-UPDATE-03/05, DG-SPI-03—DG-SPI-06,
  DG-DB-01—DG-DB-04,
  DG-RESTERR-01—DG-RESTERR-04 и DG-DELETE-01—DG-DELETE-06; они блокируют только
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
