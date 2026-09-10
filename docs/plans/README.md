# Планы развития wpConnections

Этот каталог фиксирует исполняемый план, подготовленный после аудита репозитория,
тестов, GitHub issues и текущей CI-ветки 2026-09-09.

## Порядок исполнения

1. Инфраструктурный план завершён: [PR #48](https://github.com/hokoo/wpConnections/pull/48)
   влит в `master` 2026-09-10.
2. Batch 1—4 [основного плана](./02-library-hardening.md) завершены; PR
   #51—#65 последовательно зафиксировали решения, test foundation/contracts,
   первые production fixes и исполняемые quality gates. PR #66 активировал
   Batch 5, PR #67 завершил CORE-03 и закрыл issue #31.
3. Выполнять активный Batch 5: CORE-03 завершён и issue #31 закрыт; DB-00
   завершил decision-ready compatibility discovery. Текущие contract
   workstreams — `REST-00A`, `DB-03A` и `CORE-05`. Подтверждённый
   `TEST-02F/CORE-07` остаётся за pending DG-QMETA-01.

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

- Unit: 6 тестов, 14 assertions.
- WordPress integration: 67 тестов, 345 assertions.
- Совместный coverage run: 73 теста, 359 assertions и `588/790` statements
  (`74,43%`) на CORE-03 `master` `b36fa85`.
- Глобальный RC threshold 70% достигнут, но release candidate остаётся
  неготовым до прохождения всех 39 critical scenarios.
- Post-merge `master` имеет 17/17 успешных required jobs; пять первоначальных
  Composer-download HTTP 504 failures были pre-test transient и прошли selective
  rerun.
- `master` защищён: strict required checks для всех 17 jobs, enforcement для
  администраторов, force-push и удаление ветки запрещены.
- `TEST-01`, `TEST-02A`, `TEST-02B`, `TEST-02C` и `TEST-02E` завершены.
  `TEST-02F` имеет независимо проверенное red evidence и остаётся `review`;
  failing tests мержатся только вместе с CORE-07 после решения DG-QMETA-01.
- DG-M1—DG-M9 утверждены владельцем 2026-09-10. M5 ограничен deprecation
  `Connection::load()`; `getPosts()` перенесён в отдельное исследование REST
  issue #20 вместе с filtering/traversal/representation contract.
- DG-API20-01—DG-API20-09, DG-QMETA-01, DG-UPDATE-01—DG-UPDATE-05,
  DG-SPI-01—DG-SPI-07, DG-ENT-01—DG-ENT-05 и DG-DB-01—DG-DB-04 остаются pending
  и блокируют только явно перечисленные downstream tasks. Полные тексты DG-ENT
  и DG-DB находятся в [entity validation contract](../entity-validation-contract.md)
  и [database compatibility contract](../db-compatibility-contract.md);
  основной registry хранит canonical decision/status. REST-00B, SPI-01,
  CORE-00 и DB-00 завершили decision-ready discovery; это не означает
  неявного утверждения их рекомендаций.
- Штатные regressions уже защищают missing-`to`, broken `both` и полную
  cardinality matrix; REST update без `title` и `Query\Meta` fatal ожидают свои
  явно перечисленные решения/dependencies.

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
