# Планы развития wpConnections

Этот каталог фиксирует исполняемый план, подготовленный после аудита репозитория,
тестов, GitHub issues и текущей CI-ветки 2026-09-09.

## Порядок исполнения

1. Инфраструктурный план завершён: [PR #48](https://github.com/hokoo/wpConnections/pull/48)
   влит в `master` 2026-09-10.
2. Актуализировать baseline на `master` и выполнять
   [основной план стабилизации библиотеки](./02-library-hardening.md), начиная с
   `TEST-01`.
3. Функциональные исправления из основного плана не добавлять в PR #48: сначала
   инфраструктура должна дать воспроизводимый test/coverage feedback loop.

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

- Unit: 4 теста, 7 assertions, 7,70% строк.
- WordPress integration: 5 тестов, 34 assertions.
- Совместный прогон: 9 тестов, 41 assertion, 46,44% строк, 52,68% методов.
- Наиболее слабые зоны: `ClientRestApi` — 6,49% строк; `Relation` — 50,00%;
  `WPStorage` — 53,96%.
- PR #48 merged коммитом `a978bd2`; все четыре post-merge workflow и 17 jobs
  успешны на этом коммите.
- `master` защищён: strict required checks для всех 17 jobs, enforcement для
  администраторов, force-push и удаление ветки запрещены.
- Первая исполняемая задача основного плана — `TEST-01` со статусом `todo`;
  DG-M1—DG-M8 остаются нерешёнными.
- Временные диагностические тесты подтвердили дефекты cardinality `1-m` и
  `m-1`, REST update без `title`, регистрации relation без `to` и поиска по
  `both`. Эти тесты не являются частью репозитория; их перенос в штатный suite
  включён в основной план.

## Контрольные точки

- **M0 — Infrastructure ready — completed 2026-09-10:** выполнен DoD
  инфраструктурного плана, PR #48 влит в `master`, post-merge CI зелёный.
- **M1 — Regression harness ready:** изолированные integration fixtures и пять
  подтверждённых regression-тестов находятся в `master`.
- **M2 — Core invariants stable:** validation/cardinality/duplicatable/
  closurable исправлены и защищены матрицей тестов.
- **M3 — Storage stable:** поиск, update, delete, meta cascade и schema recovery
  защищены integration-тестами.
- **M4 — REST v1 stable:** routes, permissions, CRUD, errors и meta проверяются
  через WordPress REST server.
- **M5 — Release ready:** утверждён compatibility contract, документация
  синхронизирована, release candidate проходит всю матрицу.
