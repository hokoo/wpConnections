# Планы развития wpConnections

Этот каталог фиксирует исполняемый план, подготовленный после аудита репозитория,
тестов, GitHub issues и текущей CI-ветки 2026-09-09.

## Порядок исполнения

1. Инфраструктурный план завершён: [PR #48](https://github.com/hokoo/wpConnections/pull/48)
   влит в `master` 2026-09-10.
2. Batch 1—3 [основного плана](./02-library-hardening.md) завершены; PR
   #51—#60 последовательно зафиксировали решения, test foundation/contracts,
   первые production fixes и исполняемые quality gates.
3. Выполнять активный Batch 4: cardinality vertical
   `TEST-02B/TEST-02C/CORE-02` и docs/design contracts `CORE-00`, `SPI-01`,
   `REST-00B`. Подтверждённый `TEST-02F/CORE-07` остаётся условным follow-on за
   pending DG-QMETA-01.

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
- WordPress integration: 39 тестов, 169 assertions.
- Совместный coverage run: 45 тестов, 183 assertions и `574/791` statements
  (`72,57%`) на `master` `6d42300`.
- Глобальный RC threshold 70% достигнут, но release candidate остаётся
  неготовым до прохождения всех 39 critical scenarios.
- Post-merge `master` имеет 17/17 успешных required jobs; пять первоначальных
  Composer-download HTTP 504 failures были pre-test transient и прошли selective
  rerun.
- `master` защищён: strict required checks для всех 17 jobs, enforcement для
  администраторов, force-push и удаление ветки запрещены.
- `TEST-01`, `TEST-02A` и `TEST-02E` завершены. Regression harness теперь
  содержит шесть red-first slices `TEST-02A`—`TEST-02F`; незавершённые slices
  мержатся только вместе со своим production fix при зелёном CI.
- DG-M1—DG-M9 утверждены владельцем 2026-09-10. M5 ограничен deprecation
  `Connection::load()`; `getPosts()` перенесён в отдельное исследование REST
  issue #20 вместе с filtering/traversal/representation contract.
- DG-API20-01—DG-API20-09, DG-QMETA-01 и DG-UPDATE-01—DG-UPDATE-05 остаются
  pending и блокируют только явно перечисленные downstream tasks. REST-00B
  завершила discovery и записала update gates в основном плане; это не означает
  их неявного утверждения.
- Штатные regressions уже защищают missing-`to` и broken `both`; cardinality,
  REST update без `title` и `Query\Meta` fatal выполняются следующими slices по
  своим dependencies/gates.

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
