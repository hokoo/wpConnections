# Основной план стабилизации wpConnections

## Условие запуска

Milestone M0 достигнут 2026-09-10: инфраструктурная ветка влита в `master`,
clean test flow воспроизводим, coverage baseline доступен в CI. Основной план
активен; `TEST-01` завершена, следующей исполняемой задачей является `TEST-02`.

## Цель

Сделать поведение связей, storage и REST API явно определённым, защитить
регрессии автоматическими тестами, закрыть подтверждённые дефекты и подготовить
документированный compatibility/release contract без неявного breaking change.

## Рекомендуемый порядок эпиков

1. E1 — Test foundation и подтверждённые regression cases.
2. E2 — Domain invariants и error model.
3. E3 — Storage/query/data integrity.
4. E4 — REST v1 contract и permissions.
5. E5 — Незавершённый API, open issues и документация.
6. E6 — Compatibility и release readiness.

E2 и E3 могут частично идти параллельно после E1. E4 зависит от согласованного
error model E2 и стабильных storage operations E3. E5 API tasks зависят от
решений о публичном контракте; dashboard application не входит в hardening
release и выделяется в отдельную инициативу.

## Decision gates

### DG-M1. Что считается допустимой WordPress entity

**Вопрос:** должен ли relation проверять существование объектов и соответствие
`from`/`to` post types?

- A: принимать любые положительные IDs; `from`/`to` types — только metadata.
- B: строго проверять существование `WP_Post` и post type при создании/update.
- C: strict by default с фильтром/strategy для других WordPress entity types.

**Рекомендация:** C. Она соответствует заявленному контракту post-to-post и
оставляет расширяемость без молчаливого нарушения целостности.

**Блокирует:** CORE-04, REST validation и документацию relation contract.

### DG-M2. Семантика relation `type`

**Вопрос:** поле `type` (`from`, `to`, `both`) сейчас хранится и сериализуется,
но не влияет на поведение. Что оно должно контролировать?

- A: направление допустимого поиска/traversal и REST representation.
- B: направление создания/изменения связи.
- C: deprecated metadata; удалить в следующей major version.

**Рекомендация:** A и не использовать `type` для запрета записи: физическая
связь остаётся направленной `from -> to`, а `type` описывает доступный traversal.

**Блокирует:** CORE-01, API-01 и OpenAPI.

### DG-M3. Error contract

**Вопрос:** как соотносятся существующие numeric codes 301—304, exception types
и HTTP statuses?

- A: сохранить numeric codes как публичные domain codes и отдельно маппить их на
  HTTP 4xx.
- B: заменить коды строковыми identifiers и выпустить REST v2.
- C: считать текущие коды внутренними и документировать только messages/status.

**Рекомендация:** A для обратной совместимости; новый payload должен содержать
стабильный domain code, а HTTP status не должен выводиться из числа 301—304.

**Блокирует:** CORE-03, REST-03 и OpenAPI.

### DG-M4. REST response/versioning policy

**Вопрос:** сохранять текущий v1 response shape или нормализовать его breaking
change?

- A: исправлять v1 только обратно совместимо; новые shapes — opt-in parameters.
- B: зафиксировать v1 и разработать чистый v2.
- C: изменить v1 in place.

**Рекомендация:** A для bug fixes и фильтров; если entity expansion существенно
меняет shape, использовать v2 или явно opt-in representation.

**Блокирует:** REST-03, REST-06, API-01 и DOC-01.

### DG-M5. Судьба пустых public methods

**Вопрос:** реализовать `Connection::load()` и `ConnectionCollection::getPosts()`
или убрать/депрекейтить их?

- A: реализовать оба как часть поддерживаемого API.
- B: реализовать `getPosts()` для issue #20, `load()` deprecated/remove, потому
  что существующий flow создаёт загруженные объекты через storage.
- C: убрать оба в следующей major version.

**Рекомендация:** B. Для `getPosts()` есть подтверждённый use case; контракт
`load()` сейчас не определён.

**Блокирует:** API-01 и API-02.

### DG-M6. Database tenancy model

**Вопрос:** сохранять отдельную пару таблиц на client или переходить к общим
таблицам с `client` column?

- A: сохранить table-per-client и определить collision/length rules.
- B: спроектировать shared tables и migration.

**Рекомендация:** A для hardening release. B — отдельная major-version
инициатива, поскольку требует migration/rollback и меняет операционную модель.

**Блокирует:** CORE-05 и DB-06.

### DG-M7. Transaction и failure semantics

**Вопрос:** какой уровень атомарности требуется create/update/delete + meta?

- A: транзакции обязательны для составных операций; failure откатывает всё.
- B: best effort с cleanup/retry и документированными partial failures.

**Рекомендация:** A на transactional engines, с явной capability check и
предсказуемым fallback/error на неподдерживаемом storage.

**Блокирует:** DB-05.

### DG-M8. Финальный quality target

**Вопрос:** какой coverage threshold нужен для release candidate?

- A: только глобальный процент.
- B: глобально не менее 70% строк плюс 100% согласованных critical scenarios.
- C: только critical scenarios без глобального процента.

**Рекомендация:** B. Critical scenarios: invariants, all delete paths, schema
recovery, REST CRUD/errors/permissions и multi-client isolation.

**Блокирует:** TEST-03 и REL-02.

## Реестр решений

| Gate | Решение | Владелец | Дата | Следствие |
|---|---|---|---|---|
| DG-M1 | pending; recommended C | unassigned | — | Entity validation |
| DG-M2 | pending; recommended A | unassigned | — | Relation direction contract |
| DG-M3 | pending; recommended A | unassigned | — | Exceptions и REST errors |
| DG-M4 | pending; recommended A | unassigned | — | REST compatibility |
| DG-M5 | pending; recommended B | unassigned | — | Empty public methods |
| DG-M6 | pending; recommended A | unassigned | — | Client table model |
| DG-M7 | pending; recommended A | unassigned | — | Atomicity |
| DG-M8 | pending; recommended B | unassigned | — | Release quality gate |

## E1. Test foundation и regression harness

Outcome: integration-тесты изолированы, воспроизводимы и способны надёжно
зафиксировать подтверждённые дефекты до их исправления.

Scope:

- WordPress-aware base test case и fixtures.
- Очистка таблиц/постов между тестами.
- Перенос пяти диагностических probes в штатный suite.
- Coverage/test naming и quality rules.

Out of Scope:

- Исправление production-кода в том же PR, что создаёт foundation.

Success Criteria:

- Тесты не зависят от порядка и проходят с random order/repeat.
- Каждый подтверждённый дефект имеет отдельный падающий regression test.
- Fixture assertions не маскируют число domain assertions.

Dependencies:

- M0 / INFRA-08.

Risks/Open Questions:

- Нужно решить, использовать `WP_UnitTestCase` напрямую или собственный base
  class поверх него.

Tasking Guidance:

- Выполнять TEST-01—TEST-03 по порядку.
- Не переводить regression tasks в `completed`, пока тест не наблюдался красным
  на старом коде и зелёным после соответствующего исправления.

### TEST-01. Изолировать WordPress integration fixtures

Status: completed

Priority: P0

Goal: каждый integration test получает чистые posts, client tables и hooks.

Scope:

- Перевести suite на WordPress test base или эквивалентную isolation fixture.
- Удалять обе client tables и зарегистрированные hooks после теста.
- Убрать вывод IDs и assertions, проверяющие только создание fixture.
- Удалить фактически неиспользуемый `@depends`.

Out of Scope:

- Новые production fixes.
- Переписывание unit-suite.

DoR:

- M0 достигнут.
- Clean integration command доступен.

DoD:

- Каждый тест проходит отдельно, в полном suite, в обратном/random порядке и
  повторно в одном процессе.
- Нет накопления connection/meta rows между тестами.
- `tests/drop.php` удалён как мёртвый код или включён в единый fixture lifecycle.

AC:

- Given два теста с одинаковым client/relation name, when они выполняются в
  любом порядке, then состояние первого не видно второму.
- Given failed test, when начинается следующий, then его fixtures остаются
  чистыми.

Dependencies:

- INFRA-08.

Notes/Risks:

- Suite переведён с обычного PHPUnit `TestCase` на общий
  `WPConnectionsTestCase` поверх `WP_UnitTestCase`.
- WordPress factory и transaction lifecycle очищают posts; client hooks
  восстанавливаются WordPress test framework, client temporary tables и
  `$wpdb` registry очищаются в `tear_down()` даже после failed test body.
- M0 и clean integration command подтверждены post-merge CI на `a978bd2`.

Verification:

- `make tests.integration`: 5/5 tests, 24 domain assertions.
- Reverse order с `--repeat=2`: 10/10 tests, 48 assertions.
- Seeded random order с `--repeat=3`: 15/15 tests, 72 assertions.
- Каждый из пяти integration tests прошёл отдельным `--filter` run.
- Временный negative probe дал ожидаемый `F.`: после намеренно упавшего теста
  следующий тест в том же процессе подтвердил нулевые connection/meta rows;
  probe удалён и не входит в repository suite.
- `make tests.run`, `make tests.coverage` и `make lint.phpcs` прошли; coverage
  gate остался `365/786` statements (46,44%).

### TEST-02. Перенести подтверждённые defects в штатные regression tests

Status: todo

Priority: P0

Goal: воспроизводимо зафиксировать пять обнаруженных дефектов до исправления.

Scope:

- `1-m`: запрет второго `from` для уже занятого `to`.
- `m-1`: запрет второго `to` для уже занятого `from`.
- relation без `to`.
- REST connection update без `title`.
- поиск connection по `both` endpoint.

Out of Scope:

- Исправление production-кода.
- Полная cardinality/REST/query matrix.

DoR:

- TEST-01 завершена.

DoD:

- Пять атомарных тестов добавлены с описанием ожидаемого контракта.
- На исходном production-коде каждый тест падает по ожидаемой причине.
- Tests классифицированы так, чтобы их можно было запускать отдельно.

AC:

- Given текущий код до fixes, when запускается regression group, then видны две
  cardinality failures, missing validation failure, typed-property error и
  `wpdb::prepare` placeholder error.

Dependencies:

- TEST-01.

Notes/Risks:

- Cardinality ожидания основаны на формулировке закрытого issue #33.
- Задача разблокирована после завершения и проверки TEST-01; production fixes
  по-прежнему остаются out of scope этого regression-only шага.

### TEST-03. Установить правила test quality и финальные thresholds

Status: needs_design

Priority: P1

Goal: новые тесты оцениваются по рисковым сценариям, isolation и coverage, а не
только по числу assertions.

Scope:

- Реализовать DG-M8.
- Список critical scenarios и per-component expectations.
- Random order/repeat или эквивалентный isolation check.
- Политика flaky tests и временных exceptions к threshold.

Out of Scope:

- Написание всех тестов остальных эпиков.

DoR:

- DG-M8 решён.
- INFRA-04 публикует baseline.

DoD:

- Quality contract записан и проверяется CI там, где возможно.
- Исключения имеют owner, причину и expiry condition.

AC:

- Given PR в critical component, then checklist требует соответствующий
  scenario test независимо от глобального coverage.
- Given coverage regression, then CI применяет утверждённую policy.

Dependencies:

- DG-M8.
- INFRA-04.

Notes/Risks:

- Высокий процент без branch/scenario coverage не гарантирует корректность
  cardinality или data integrity.

## E2. Domain invariants и error model

Outcome: relation и connection отвергают некорректные данные предсказуемо;
cardinality, duplicatable и closurable соответствуют документированному
контракту.

Scope:

- Relation validation.
- Cardinality matrix.
- Error codes и precedence.
- Entity types и client isolation.

Out of Scope:

- SQL delete mechanics и REST response shape.

Success Criteria:

- Полная invariant matrix проходит тесты.
- Закрытый issue #33 больше не воспроизводится.
- Open issue #31 закрыт тестами стабильных codes.

Dependencies:

- E1.
- DG-M1, DG-M2, DG-M3 и DG-M6 для соответствующих задач.

Risks/Open Questions:

- Исправление cardinality может начать отклонять данные, которые старый код уже
  разрешал; перед release нужен аудит существующих consumers/data.

Tasking Guidance:

- Сначала CORE-01—CORE-03, затем entity/client contracts.
- Каждый production fix делается после соответствующего TEST-02 regression.

### CORE-01. Валидировать relation definition

Status: needs_design

Priority: P0

Goal: нельзя зарегистрировать неполную или неизвестную relation configuration.

Scope:

- Исправить повторную проверку `name` вместо `to`.
- Required: `name`, `from`, `to`.
- Валидировать allowed cardinality и, после DG-M2, allowed type.
- Проверить duplicate relation name и defaults.

Out of Scope:

- Проверка существования конкретных connection endpoints.

DoR:

- TEST-02 содержит regression для missing `to`.
- DG-M2 решён.

DoD:

- Полная validation matrix покрыта unit/integration tests.
- Missing parameter message перечисляет только реально отсутствующие поля без
  дублей.
- Неизвестные enum values дают документированный exception/code.

AC:

- Given отсутствует только `to`, when relation регистрируется, then exception
  содержит только `to` как missing field.
- Given duplicate name, when relation регистрируется повторно, then возвращается
  стабильный `RelationWrongData` contract.
- Given валидный minimal query, then применяются документированные defaults.

Dependencies:

- TEST-02.
- DG-M2.

Notes/Risks:

- Ужесточение validation может выявить некорректные definitions у consumers.

### CORE-02. Исправить и полностью проверить cardinality

Status: waiting_dependency

Priority: P0

Goal: `1-1`, `1-m`, `m-1`, `m-m` ограничивают правильную сторону связи.

Scope:

- Исправить направления запросов `1-m` и `m-1`.
- Добавить allowed/forbidden matrix для всех четырёх вариантов.
- Проверить cardinality после update endpoints, а не только create.

Out of Scope:

- Database-level unique indexes до отдельного design решения.
- Entity post type validation.

DoR:

- TEST-02 cardinality tests добавлены и воспроизводят дефект.
- Семантика из issue #33 подтверждена владельцем.

DoD:

- Все matrix scenarios зелёные.
- Update не может обойти invariant.
- Issue #33 переоткрыт/заменён новым issue и закрыт исправлением с regression.

AC:

- Given `1-m`, then один `from` может иметь много разных `to`, но один `to` не
  может принадлежать двум `from`.
- Given `m-1`, then много `from` могут указывать на один `to`, но один `from` не
  может указывать на два `to`.
- Given `1-1`, then обе стороны уникальны.
- Given `m-m`, then cardinality не ограничивает стороны.

Dependencies:

- TEST-02.
- CORE-01 для валидных enum values.

Notes/Risks:

- Нужен migration/audit report для уже существующих нарушений перед включением
  строгого update поведения.

### CORE-03. Зафиксировать duplicatable, closurable и error precedence

Status: needs_design

Priority: P0

Goal: все причины отклонения связи имеют стабильный тип/code и детерминированный
приоритет.

Scope:

- Tests и contracts для codes 301, 302, 303, 304.
- Duplicate check прежде cardinality, regression issue #29.
- Self-connection при `closurable=false/true`.
- Missing endpoints и update connection без ID.

Out of Scope:

- HTTP status mapping, который выполняется в REST-03.

DoR:

- DG-M3 решён.
- CORE-02 определяет cardinality semantics.

DoD:

- Open issue #31 закрыт.
- Code/message/type assertions присутствуют для каждой ошибки.
- Сценарий, нарушающий несколько invariants, возвращает утверждённый priority
  error.

AC:

- Given duplicate, одновременно нарушающий cardinality, when создаётся связь,
  then domain code равен 303.
- Given запрещённая self-connection, then code равен 301.
- Given cardinality violation без duplicate, then code равен 302.
- Given update объекта без ID, then code равен 304.

Dependencies:

- DG-M3.
- CORE-02.

Notes/Risks:

- Messages можно улучшать, но стабильность должна опираться на code/type.

### CORE-04. Реализовать endpoint entity validation

Status: needs_design

Priority: P1

Goal: connection endpoints соответствуют утверждённому WordPress entity/post
type contract.

Scope:

- Реализовать DG-M1 для create и update.
- Tests для missing/deleted/wrong-type endpoints.
- Extension hook/strategy, если выбран вариант C.

Out of Scope:

- Возврат полных entities через REST.
- Поддержка произвольных entity types без отдельного adapter.

DoR:

- DG-M1 решён.
- Определён backward-compatibility/rollout plan.

DoD:

- Validation единообразна для PHP и REST paths.
- Ошибки имеют стабильный domain contract.
- Документация relation `from`/`to` соответствует реализации.

AC:

- Given relation `page -> post`, when передан `post -> page`, then операция
  отклоняется предсказуемо.
- Given несуществующий ID, then запись в storage не создаётся.
- Given разрешающий extension strategy, then поддерживаемый non-post endpoint
  проходит без изменения core storage.

Dependencies:

- DG-M1.
- CORE-03.

Notes/Risks:

- Строгая проверка может быть breaking для consumers, использующих IDs не из
  `wp_posts`.

### CORE-05. Защитить multi-client isolation и table-name collisions

Status: needs_design

Priority: P1

Goal: обещанная README изоляция клиентов сохраняется для всех допустимых имён.

Scope:

- Реализовать DG-M6 для hardening release.
- Tests двух клиентов с разными relations/data.
- Нормализация, collision detection, length и empty-name validation.
- Документировать table naming.

Out of Scope:

- Shared-table migration, если выбран table-per-client.

DoR:

- DG-M6 решён.
- Известны ограничения имён существующих consumers.

DoD:

- Разные допустимые client names не разделяют данные неожиданно.
- Коллизирующие normalized names отклоняются или разрешаются утверждённым
  детерминированным способом.
- Empty/overlong name не создаёт опасную таблицу.

AC:

- Given два клиента, when каждый создаёт связь, then ни один не видит и не
  удаляет данные другого.
- Given имена, нормализующиеся одинаково, then система не молча использует одну
  таблицу.

Dependencies:

- DG-M6.
- TEST-01.

Notes/Risks:

- Любое изменение table postfix может потребовать migration существующих tables.

## E3. Storage, query и data integrity

Outcome: все create/find/update/delete/meta/schema-recovery операции работают
атомарно и не оставляют orphan/partial data.

Scope:

- Query matrix, включая `both`.
- Update regressions и meta semantics.
- Все delete paths и WordPress cascade.
- Schema installation/recovery и transactions.

Out of Scope:

- REST representation и dashboard UI.

Success Criteria:

- Все public storage operations имеют success, empty и error tests.
- Нет orphan meta после любого удаления.
- Closed issues #13 и #45 защищены точными regressions.

Dependencies:

- E1; часть update tests зависит от E2 invariants.

Risks/Open Questions:

- SQL changes должны проверяться на утверждённых MySQL/MariaDB versions.

Tasking Guidance:

- DB-01 и DB-02 можно выполнять первыми; delete и transaction задачи требуют
  отдельного review из-за риска потери данных.

### DB-01. Исправить поиск по `both` и покрыть query matrix

Status: waiting_dependency

Priority: P0

Goal: поиск по id/relation/from/to/both возвращает правильный набор без SQL
warnings.

Scope:

- Исправить placeholders для `both`.
- Matrix: id priority, relation, from, to, both, combinations, empty/no result.
- Meta aggregation для повторяющихся keys.
- Ordering behavior либо его явное отсутствие.

Out of Scope:

- REST query parameter design.

DoR:

- TEST-02 содержит падающий `both` regression.

DoD:

- Все query variants покрыты integration tests.
- `wpdb::prepare` не выдаёт warnings/notices.
- Query inputs параметризованы безопасно.

AC:

- Given connection `A -> B`, when ищут `both=A` или `both=B`, then связь найдена
  ровно один раз.
- Given unrelated endpoint, then result empty.
- Given relation filter, then связи другой relation не возвращаются.

Dependencies:

- TEST-02.

Notes/Risks:

- Этот path понадобится issue #21, если REST filter поддержит `both`.

### DB-02. Защитить create/update и meta regressions

Status: waiting_dependency

Priority: P0

Goal: создание и изменение сохраняют все поля и metadata без uninitialized или
no-op ambiguity.

Scope:

- Create сразу с meta, включая duplicate keys и допустимые falsy values.
- Update `from`, `to`, `title`, `order`; regression nonzero -> `0` для issue #13.
- Replace/append/delete-all meta semantics.
- Определить значение результата no-op update.

Out of Scope:

- REST response shape.
- Transactions, которые выполняются в DB-05.

DoR:

- TEST-01 завершена.
- CORE-02 определяет update invariants.

DoD:

- Issue #13 защищён точным тестом.
- Null/empty/zero values имеют документированное поведение.
- Update существующей связи и no-op различимы согласно контракту.

AC:

- Given order 10, when update устанавливает 0, then storage возвращает связь с
  order 0.
- Given meta с двумя значениями одного key, when connection создаётся и читается,
  then оба значения сохранены.
- Given replace meta, then старые значения отсутствуют и новые присутствуют.

Dependencies:

- TEST-01.
- CORE-02 для update endpoint invariants.

Notes/Risks:

- Таблица объявляет `meta_value NOT NULL`, тогда как object model допускает null;
  контракт нужно зафиксировать тестом и при необходимости schema change.

### DB-03. Покрыть все явные delete paths и meta cascade

Status: waiting_dependency

Priority: P0

Goal: удаление по ID, endpoints, direction и relation удаляет ровно нужные
connections и всю связанную meta.

Scope:

- `deleteSpecificConnections` для одного/нескольких IDs.
- `deleteByObjectID` для both/onlyFrom/onlyTo и relation filter.
- `deleteDirectedConnections`, duplicate rows и not-found.
- Все branches `Relation::detachConnections`.
- Invalid IDs и обе direction flags одновременно.

Out of Scope:

- Автоматический `deleted_post` hook — DB-04.
- Transaction implementation — DB-05.

DoR:

- TEST-01 обеспечивает isolation.
- Согласовано ожидаемое rows-affected поведение.

DoD:

- Каждая ветка delete API имеет integration test.
- Meta rows отсутствуют после успешного удаления.
- Связи других relation/client не затрагиваются.

AC:

- Given две связи с meta, when удаляется одна по ID, then вторая и её meta
  остаются без изменений.
- Given duplicate directed connections, when удаляется pair, then удалены все
  matching rows и их meta.
- Given invalid/empty identifier, then операция не формирует опасный SQL и
  возвращает документированный result/error.

Dependencies:

- TEST-01.
- CORE-05 для cross-client assertions.

Notes/Risks:

- Delete PR должен иметь отдельный review и не смешиваться с schema changes.

### DB-04. Проверить WordPress `deleted_post` cascade

Status: waiting_dependency

Priority: P0

Goal: удаление post автоматически удаляет входящие/исходящие connections и meta
для текущего клиента без затрагивания остальных клиентов.

Scope:

- Реальный `wp_delete_post`/`deleted_post` flow.
- From, to, self-connection и multi-client cases.
- Hook registration lifecycle.

Out of Scope:

- Hooks для users/terms или других entity types.

DoR:

- DB-03 завершена.
- DG-M1 определяет поддерживаемые entities.

DoD:

- Cascade integration tests зелёные.
- Нет orphan meta.
- Hook не регистрируется многократно между tests/clients неожиданным образом.

AC:

- Given post участвует в нескольких relations, when он удалён, then все его
  connections текущего клиента и meta удалены.
- Given другой клиент использует другой endpoint, then его данные не затронуты.

Dependencies:

- DB-03.
- DG-M1.

Notes/Risks:

- При shared entity между клиентами каждый client hook должен обработать только
  собственные tables.

### DB-05. Сделать составные storage operations атомарными

Status: needs_design

Priority: P1

Goal: failure между connection и meta statements не оставляет partial state.

Scope:

- Реализовать DG-M7 для create/update/delete flows.
- Fault-injection tests между SQL steps.
- Rollback/error behavior и logging hooks.

Out of Scope:

- Миграция tenancy model.

DoR:

- DG-M7 решён.
- DB-02 и DB-03 задают корректные success semantics.
- Определены поддерживаемые DB engines.

DoD:

- Fault injection показывает полный rollback или утверждённый fallback.
- Caller получает стабильный exception, а hooks не сообщают ложный success.
- Transaction boundaries документированы.

AC:

- Given meta insert failure during create, then connection row не остаётся без
  требуемой meta.
- Given connection delete failure after meta step, then система не оставляет
  рассинхронизированное состояние.

Dependencies:

- DG-M7.
- DB-02, DB-03.

Notes/Risks:

- Таблицы и engine должны реально поддерживать выбранную transaction semantics.

### DB-06. Защитить schema install и recovery

Status: waiting_dependency

Priority: P0

Goal: clean install, повторный install и восстановление отсутствующих таблиц
работают на поддерживаемых DB versions.

Scope:

- Точный regression для issue #45/dbDelta formatting.
- Clean create обеих tables и индексов.
- Idempotent install/upgrade.
- Удаление одной/обеих tables после client init и retry create path.
- Table naming assertions из DG-M6.

Out of Scope:

- Shared-table migration.
- Новые production columns без отдельного design.

DoR:

- INFRA-03 и compatibility policy доступны.
- DG-M6 решён.

DoD:

- Issue #45 защищён regression test.
- Schema проверяется структурно, а не только косвенным create/read.
- Retry выполняется ограниченное число раз и сообщает исходную DB error при
  неуспехе.

AC:

- Given отсутствуют обе client tables, when создаётся connection, then tables
  создаются и запись сохраняется.
- Given install вызывается повторно, then данные и schema не повреждаются.
- Given schema нельзя создать, then caller получает информативный exception и
  бесконечного retry нет.

Dependencies:

- INFRA-03.
- DG-M6.

Notes/Risks:

- MariaDB-only test недостаточен, если production contract включает MySQL.

## E4. REST v1 contract, errors и permissions

Outcome: все объявленные routes проверяются через WordPress REST server;
обычные payloads не вызывают fatal errors, permissions и error responses
соответствуют документированному contract.

Scope:

- Route registration и dispatch.
- Connection/meta CRUD.
- Permissions matrix.
- Filtering issue #21.
- REST error/status mapping.

Out of Scope:

- Dashboard frontend.
- Breaking v2 без отдельного плана.

Success Criteria:

- Каждый route/method из Postman collection имеет automated test.
- `ClientRestApi` critical lines и branches покрыты scenario tests.
- Closed issue #35 и подтверждённый update fatal защищены regressions.

Dependencies:

- E1, E2 error model, E3 stable storage.
- DG-M3 и DG-M4.

Risks/Open Questions:

- Текущий direct-handler test не проверяет route args, permission callback,
  serialization и HTTP status.

Tasking Guidance:

- Сначала REST-01 harness, затем REST-02 update defect, после чего CRUD/errors,
  permissions, meta и filters.

### REST-01. Создать end-to-end REST test harness

Status: waiting_dependency

Priority: P0

Goal: тесты регистрируют routes и отправляют запросы через WordPress REST server.

Scope:

- `rest_api_init`, route discovery и `rest_get_server()->dispatch()`.
- Authenticated/unauthenticated users и nonce-independent unit context.
- Helpers для URL, payload и response assertions.
- Сверка route/method inventory с Postman collection.

Out of Scope:

- Исправление конкретных handlers.

DoR:

- TEST-01 завершена.
- Client hooks можно безопасно очищать между тестами.

DoD:

- Harness демонстрирует request validation, callback, permission и response
  serialization.
- Все объявленные routes обнаруживаются.
- Direct handler test остаётся только там, где он проверяет отдельную unit logic.

AC:

- Given зарегистрированный client, when выполняется `rest_api_init`, then все
  ожидаемые routes/methods присутствуют.
- Given невалидный required arg, then WordPress validation отклоняет request до
  handler.

Dependencies:

- TEST-01.

Notes/Risks:

- Namespace root в Postman может быть автоматически предоставлен WordPress и не
  должен ошибочно считаться отдельным custom route.

### REST-02. Исправить fatal error при update connection

Status: waiting_dependency

Priority: P0

Goal: обычный update payload без `title` не читает неинициализированное property
и корректно сохраняет заданные поля.

Scope:

- Перенести TEST-02 regression в REST harness.
- Определить defaults/preserve semantics для omitted fields.
- Проверить POST/PUT/PATCH methods, разрешённые `EDITABLE`.
- Проверить `order=0` вместе с DB-02.

Out of Scope:

- Новый REST response format.

DoR:

- TEST-02 содержит reproduction.
- REST-01 завершена.
- Согласована semantics partial update для omitted fields.

DoD:

- Fatal error устранён.
- Omitted fields не обнуляются неожиданно.
- Full dispatch regression зелёный.

AC:

- Given connection с title, when PATCH меняет order без title, then response не
  содержит error и прежний title сохранён.
- Given order 10, when PATCH устанавливает 0, then response/storage показывают 0.

Dependencies:

- TEST-02.
- REST-01.
- DB-02.

Notes/Risks:

- Нужно различать omitted, explicit null и falsy value.

### REST-03. Покрыть connection CRUD и error mapping

Status: needs_design

Priority: P0

Goal: GET/list/create/update/delete имеют стабильные payloads, domain codes и
HTTP statuses.

Scope:

- Реализовать DG-M3 и DG-M4.
- Client relation list, relation connection list, single connection.
- Create/update/delete success и not-found/validation/invariant errors.
- Link serialization и `CollectionItem` behavior.

Out of Scope:

- Full entity expansion.
- OpenAPI generation — DOC-01.

DoR:

- DG-M3 и DG-M4 решены.
- REST-01, REST-02, CORE-03 и DB-03 завершены.

DoD:

- Все connection routes имеют success/error integration tests.
- HTTP status и body code проверяются отдельно.
- Response shape задокументирован как v1 contract.

AC:

- Given неизвестная relation, when выполняется request, then status/body
  соответствуют утверждённому RelationNotFound mapping.
- Given отсутствующий connection, then возвращается стабильный not-found status
  и domain code.
- Given valid CRUD sequence, then resource можно создать, прочитать, изменить и
  удалить через REST server.

Dependencies:

- DG-M3, DG-M4.
- REST-01, REST-02, CORE-03, DB-03.

Notes/Risks:

- Текущие numeric domain codes 301—304 не должны автоматически становиться HTTP
  redirect statuses.

### REST-04. Защитить differentiated permissions

Status: waiting_dependency

Priority: P0

Goal: capability для каждого handler реально разрешает и запрещает нужные
операции.

Scope:

- Default capability и client-level filter.
- Per-callback overrides для read/create/update/delete/meta.
- Authenticated roles и unauthorized response.
- Regression для закрытого issue #35.

Out of Scope:

- Новая ролевая модель UI.

DoR:

- REST-01 завершена.
- Список REST callbacks стабилен.

DoD:

- Permissions matrix покрыта dispatch tests.
- Read-only user не может mutate, но может читать по policy.
- Неизвестный callback не получает неожиданно более широкое право.

AC:

- Given read capability without update capability, when user выполняет GET,
  then request успешен; when выполняет update/delete, then access denied.
- Given client default capability filter, then все не переопределённые callbacks
  используют её.

Dependencies:

- REST-01.
- REST-03 для ожидаемых error responses.

Notes/Risks:

- Capability key выводится из callback metadata; это нужно проверить для каждого
  зарегистрированного route variant.

### REST-05. Покрыть REST meta semantics

Status: waiting_dependency

Priority: P1

Goal: POST/PATCH/PUT/DELETE meta проверяются через полный route lifecycle.

Scope:

- Append, replace keys, replace all, delete selected/all.
- Duplicate keys, empty payload, missing connection, storage failure.
- Response serialization и persisted state.

Out of Scope:

- Изменение meta data model.

DoR:

- REST-01 и DB-02/DB-03 завершены.

DoD:

- Существующий direct test заменён или дополнен dispatch scenarios.
- Route args для DELETE явно определяют допустимый meta payload.
- Все methods документированы.

AC:

- Given existing two values under one key, when PATCH передаёт новый value того
  же key, then старые удалены и новый сохранён.
- Given PUT, then вся прежняя meta заменена.
- Given DELETE selection, then несвязанные keys остаются.

Dependencies:

- REST-01.
- DB-02, DB-03.

Notes/Risks:

- Текущий DELETE route не описывает `meta` в собственных args.

### REST-06. Реализовать filters relation list из issue #21

Status: needs_design

Priority: P1

Goal: GET relation connections принимает документированные `from`, `to` и,
если утверждено, `both` filters.

Scope:

- Реализовать query params без обхода relation/client isolation.
- Validation, combinations и empty result.
- Backward-compatible unfiltered behavior.

Out of Scope:

- Pagination/sorting, если они не выделены отдельным contract task.
- Full entity expansion issue #20.

DoR:

- DG-M4 решён.
- DB-01 завершена.
- Для combinations определена AND/OR semantics.

DoD:

- Issue #21 закрыт.
- Filters отражены в REST tests и OpenAPI.
- Unfiltered endpoint сохраняет v1 behavior.

AC:

- Given relation с несколькими connections, when GET содержит `from`, then
  возвращаются только matching rows.
- Given `both`, then endpoint находит connection независимо от стороны.
- Given invalid ID, then request получает validation error, а не raw SQL warning.

Dependencies:

- DG-M4.
- DB-01.
- REST-03.

Notes/Risks:

- Pagination и deterministic ordering понадобятся при больших relation lists;
  при необходимости создать отдельный follow-up issue.

## E5. Незавершённый API, open issues и документация

Outcome: пустые public methods и открытые API/documentation issues имеют
реализованный контракт либо явное deprecation/deferred решение.

Scope:

- `getPosts`, `load`, relation `type` traversal.
- Entity representation issue #20.
- OpenAPI issue #27.
- Dashboard issue #28 как отдельная инициатива.

Out of Scope:

- Реализация dashboard frontend в hardening release.

Success Criteria:

- В public API нет молча пустых методов.
- OpenAPI соответствует dispatch tests.
- Каждый open issue закрыт, запланирован отдельно или осознанно deferred.

Dependencies:

- E2, E3 и E4; DG-M2, DG-M4, DG-M5.

Risks/Open Questions:

- Entity expansion может создать N+1 queries и изменить response size/shape.

Tasking Guidance:

- Сначала принять gates, затем API-01/API-02; OpenAPI создаётся из стабильного
  REST contract, dashboard получает отдельный discovery epic.

### API-01. Реализовать traversal и получение WP entities

Status: needs_design

Priority: P1

Goal: закрыть issue #20 и сделать `ConnectionCollection::getPosts()` реальным,
предсказуемым API без N+1 behavior.

Scope:

- Реализовать DG-M2 и часть DG-M5 про `getPosts()`.
- `from`, `to`, `both` traversal semantics.
- Missing/deleted posts, ordering и duplicate IDs.
- Bulk-loading и opt-in REST representation согласно DG-M4.

Out of Scope:

- Non-post entities без adapter из DG-M1.
- Dashboard UI.

DoR:

- DG-M1, DG-M2, DG-M4 и DG-M5 решены.
- DB-01 и REST-06 завершены.

DoD:

- `getPosts()` больше не пуст.
- Issue #20 закрыт либо REST часть выделена в v2 task.
- Performance проверяется query-count test для коллекции.

AC:

- Given connection collection, when запрашиваются `from` posts, then возвращены
  соответствующие существующие posts в документированном порядке.
- Given deleted endpoint, then поведение skip/null/error соответствует contract.
- Given REST opt-in expansion, then default v1 shape не меняется.

Dependencies:

- DG-M1, DG-M2, DG-M4, DG-M5.
- DB-01, REST-06.

Notes/Risks:

- Не загружать каждый post отдельным SQL query.

### API-02. Решить судьбу `Connection::load()`

Status: needs_design

Priority: P2

Goal: public method не остаётся молча пустым.

Scope:

- Реализовать или deprecated/remove согласно DG-M5.
- Если реализуется: входной identity, refresh semantics, not-found behavior,
  client requirement и tests.
- Если deprecated: warning, migration docs и removal version.

Out of Scope:

- Новый ORM/data mapper.

DoR:

- DG-M5 решён.
- Инвентаризировано использование метода consumers.

DoD:

- Метод имеет тестируемое поведение либо формальный deprecation path.
- README/API docs не обещают отсутствующее поведение.

AC:

- Given принято implement решение, when существующий connection загружается,
  then object refreshes all fields/meta; not-found возвращает утверждённую ошибку.
- Given deprecation решение, then вызов сообщает deprecation и документирует
  поддерживаемую замену.

Dependencies:

- DG-M5.
- Consumer usage audit.

Notes/Risks:

- Реализация без чёткого identity lifecycle продублирует storage API.

### DOC-01. Создать OpenAPI contract из проверенных REST routes

Status: waiting_dependency

Priority: P1

Goal: закрыть issue #27 машинно-проверяемой спецификацией.

Scope:

- Paths, methods, params, request/response schemas, auth и errors.
- Examples для CRUD, meta и filters.
- CI validation OpenAPI syntax и drift check с route inventory, где возможно.

Out of Scope:

- Dashboard implementation.
- Недокументированные future v2 endpoints.

DoR:

- REST-03—REST-06 завершены.
- DG-M4 решён.

DoD:

- Valid OpenAPI file находится в репозитории.
- Issue #27 закрыт.
- Postman collection генерируется/сверяется со спецификацией либо объявлена
  вторичной и удалена при дублировании.

AC:

- Given OpenAPI validator, when проверяется файл, then ошибок нет.
- Given route inventory, then каждый поддерживаемый custom route описан.
- Given domain error, then schema содержит HTTP status и стабильный domain code.

Dependencies:

- DG-M4.
- REST-03, REST-04, REST-05, REST-06.

Notes/Risks:

- Спецификацию нельзя писать раньше стабилизации response contract: иначе она
  закрепит случайные текущие shapes.

### PROD-01. Выделить dashboard application в отдельную инициативу

Status: deferred

Priority: P2

Goal: issue #28 получает отдельный discovery/UX/security план, не блокируя
hardening библиотеки.

Scope:

- Краткий product brief, users/use cases и зависимости от REST/OpenAPI.
- Решение build/bundle/distribution architecture.
- Отдельные epics для UX, permissions и connection management.

Out of Scope:

- Реализация UI в рамках этого плана.

DoR:

- M4 REST stable.
- Есть product owner и design capacity.

DoD:

- Issue #28 связан с отдельным утверждённым plan/epic или закрыт как out of scope.
- Hardening release не зависит от dashboard delivery.

AC:

- Given начало dashboard discovery, then команда использует стабильную OpenAPI
  specification и permissions contract, а не reverse engineering PHP handlers.

Dependencies:

- M4.
- DOC-01.
- Product owner/design input.

Notes/Risks:

- Это самостоятельный продуктовый проект, а не одна library task.

## E6. Compatibility и release readiness

Outcome: поддерживаемые платформы, hooks и public API проверены; документация и
release candidate соответствуют фактическому поведению.

Scope:

- PHP/WordPress/Ramsey/DB matrix.
- Hooks and extension points.
- README/API migration notes и release checklist.

Out of Scope:

- Dashboard application.
- Shared-table major migration, если DG-M6 выбрал table-per-client.

Success Criteria:

- Blocking matrix зелёная.
- Global/critical quality gates выполнены.
- Нет незадокументированных подтверждённых breaking changes.

Dependencies:

- E1—E5 кроме deferred PROD-01.

Risks/Open Questions:

- Исправленная cardinality/entity validation может требовать migration guide и
  data audit tool.

Tasking Guidance:

- Compatibility tests можно готовить раньше, но release checklist закрывать
  только после всех blocking эпиков.

### REL-01. Проверить полную compatibility matrix

Status: waiting_dependency

Priority: P1

Goal: подтвердить библиотеку на всех заявленных runtime/dependency/database
combinations.

Scope:

- PHP, WordPress и Ramsey policy из INFRA-03.
- Утверждённые MySQL и MariaDB versions.
- Clean install, unit, integration, REST, PHPCS и coverage.

Out of Scope:

- Обещание поддержки непроверенных EOL versions.

DoR:

- INFRA-03 завершена.
- E2—E4 blocking tasks завершены.
- Владелец утвердил DB matrix.

DoD:

- Blocking combinations зелёные.
- Known non-blocking incompatibilities документированы с owner/follow-up.
- Matrix не использует mutable dependency для stable jobs.

AC:

- Given каждая заявленная combination, when запускается clean CI, then все
  blocking suites проходят.
- Given unsupported/EOL combination, then документация не обещает её поддержку.

Dependencies:

- INFRA-03.
- Blocking задачи E2—E4.

Notes/Risks:

- Полный matrix можно запускать scheduled, сохраняя representative blocking PR
  matrix.

### REL-02. Проверить hooks, factories и extension compatibility

Status: waiting_dependency

Priority: P1

Goal: hooks и factory filters либо защищены контрактными тестами, либо явно
считаются internal.

Scope:

- Factory success/error paths для storage, REST API и logger replacements.
- Lifecycle hooks creating/created/find/delete/meta/logging.
- Callback arguments, order и client-specific/global variants.
- Backward-compatibility inventory.

Out of Scope:

- Добавление новых extension systems.

DoR:

- Core/storage/REST contracts стабильны.
- Известны публично используемые hooks consumers.

DoD:

- Public hooks имеют contract tests и документацию.
- Invalid factory replacements дают стабильные errors.
- Internal hooks помечены как internal или excluded from compatibility promise.

AC:

- Given valid custom storage/logger/REST class, when filter её возвращает, then
  client использует replacement.
- Given invalid class/type, then создаётся предсказуемый `ClientRegisterFail`.
- Given create/delete lifecycle, then documented hooks вызываются один раз с
  документированными arguments.

Dependencies:

- E2, E3, E4 blocking tasks.
- Consumer usage audit.

Notes/Risks:

- Изменение hook name/arguments является breaking даже при неизменном PHP API.

### REL-03. Подготовить migration notes, release documentation и RC

Status: waiting_dependency

Priority: P0

Goal: выпустить проверяемый release candidate с понятным upgrade path.

Scope:

- README quick start и фактический namespace/API.
- Relation/cardinality/entity/error/REST contracts.
- Compatibility table, OpenAPI link и CI commands.
- Migration/data audit для ужесточённых invariants.
- Changelog, versioning и release checklist.

Out of Scope:

- Dashboard documentation.

DoR:

- Все blocking tasks E1—E5 завершены.
- REL-01 и REL-02 завершены.
- DG-M8 quality target достигнут.

DoD:

- RC tag/version выбран и проверен из clean consumer install.
- Upgrade/migration instructions протестированы на representative data.
- Open issues из scope закрыты или имеют явный deferred rationale.
- M5 отмечен в index plan.

AC:

- Given новый consumer, when он следует README, then может установить library,
  зарегистрировать relation и выполнить CRUD.
- Given existing data с cardinality violations, then migration guide позволяет
  обнаружить и исправить их до включения strict behavior.
- Given release commit, then blocking matrix, critical scenarios и утверждённый
  coverage threshold зелёные.

Dependencies:

- REL-01, REL-02, DOC-01.
- Все blocking задачи E1—E5.
- DG-M8.

Notes/Risks:

- Не выпускать strict cardinality/entity validation без data-audit guidance.

## Traceability: замечания и GitHub issues

| Источник/наблюдение | План |
|---|---|
| Confirmed: `1-m`/`m-1` violations | TEST-02, CORE-02 |
| Confirmed: relation без `to` | TEST-02, CORE-01 |
| Confirmed: REST update uninitialized `title` | TEST-02, REST-02 |
| Confirmed: broken `both` placeholder | TEST-02, DB-01 |
| Open [#31 error code tests](https://github.com/hokoo/wpConnections/issues/31) | CORE-03, REST-03 |
| Open [#21 REST filters](https://github.com/hokoo/wpConnections/issues/21) | REST-06 |
| Open [#20 entities/getPosts](https://github.com/hokoo/wpConnections/issues/20) | API-01 |
| Open [#27 OpenAPI](https://github.com/hokoo/wpConnections/issues/27) | DOC-01 |
| Open [#28 dashboard](https://github.com/hokoo/wpConnections/issues/28) | PROD-01 deferred initiative |
| Closed [#13 order zero](https://github.com/hokoo/wpConnections/issues/13) | DB-02 |
| Closed [#29 duplicate precedence](https://github.com/hokoo/wpConnections/issues/29) | CORE-03 |
| Closed [#33 cardinality](https://github.com/hokoo/wpConnections/issues/33) | CORE-02; reopen/follow-up |
| Closed [#35 permissions](https://github.com/hokoo/wpConnections/issues/35) | REST-04 |
| Closed [#45 dbDelta/schema](https://github.com/hokoo/wpConnections/issues/45) | DB-06 |
| Untested delete/meta cascade | DB-03, DB-04, DB-05 |
| Untested multi-client promise | CORE-05 |
| Empty `Connection::load()` | API-02 |
| `type` has no behavior | DG-M2, CORE-01, API-01 |
| Missing route-level REST tests | REST-01—REST-05 |
| Missing coverage in CI | INFRA-04, TEST-03 |
