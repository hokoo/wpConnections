# Основной план стабилизации wpConnections

## Условие запуска

Milestone M0 достигнут 2026-09-10: инфраструктурная ветка влита в `master`,
clean test flow воспроизводим, coverage baseline доступен в CI. Основной план
активен; `TEST-01` завершена, следующие regression slices — `TEST-02A` и
`TEST-02E`.

DG-M1—DG-M9 утверждены владельцем 2026-09-10. Зависимые задачи переведены из
`needs_design` только там, где их остальные DoR и dependencies действительно
выполнены.

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

**Решение:** approved C владельцем репозитория 2026-09-10. Все поддерживаемые
domain mutation paths строго проверяют существование endpoint и соответствие
`relation.from`/`relation.to` по умолчанию; non-post entities допускаются только
через явную extension strategy.

**Блокирует:** CORE-04, REST validation и документацию relation contract.

### DG-M2. Семантика relation `type`

**Вопрос:** поле `type` (`from`, `to`, `both`) сейчас хранится и сериализуется,
но не влияет на поведение. Что оно должно контролировать?

- A: направление допустимого поиска/traversal и REST representation.
- B: направление создания/изменения связи.
- C: deprecated metadata; удалить в следующей major version.

**Рекомендация:** C после дополнительного исследования истории, consumers и
фактических write/read paths. Физическая связь остаётся направленной
`from -> to`, её endpoint types задаются `relation.from`/`relation.to`, а
`relation.type` не участвует ни в validation, ни в traversal.

**Решение:** approved C владельцем репозитория 2026-09-10. В REST v1 поле
сохраняется как deprecated no-op ради совместимости и удаляется в следующей
major version. Новые traversal/filter contracts задают направление явно и не
используют это поле.

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

**Решение:** approved A владельцем репозитория 2026-09-10.

**Блокирует:** CORE-03, REST-03 и OpenAPI.

### DG-M4. REST response/versioning policy

**Вопрос:** сохранять текущий v1 response shape или нормализовать его breaking
change?

- A: исправлять v1 только обратно совместимо; новые shapes — opt-in parameters.
- B: зафиксировать v1 и разработать чистый v2.
- C: изменить v1 in place.

**Рекомендация:** A для bug fixes и фильтров; если entity expansion существенно
меняет shape, использовать v2 или явно opt-in representation.

**Решение:** approved A владельцем репозитория 2026-09-10. Default REST v1
response shape сохраняется; новые representations включаются явно. Deprecated
`relation.type` остаётся принимаемым/сериализуемым no-op в v1 и отмечается в
документации/OpenAPI без REST runtime warning.

**Блокирует:** REST-03, REST-06, API-01 и DOC-01.

### DG-M5. Судьба `Connection::load()`

**Вопрос:** реализовать пустой `Connection::load()` или вывести его из public
API?

- A: реализовать refresh текущего объекта по уже установленным `id` и client.
- B: объявить deprecated в текущей major version, документировать существующий
  relation query flow как замену и удалить метод в следующей major version.
- C: оставить пустой метод ради формальной совместимости.

**Рекомендация:** B. У метода нет определённого identity/lifecycle contract и
подтверждённого consumer use case; придумывать новое поведение для старой пустой
сигнатуры рискованнее контролируемой deprecation.

**Решение:** approved B владельцем репозитория 2026-09-10.
`ConnectionCollection::getPosts()` исключён из этого gate: его реализация или
deprecation определяется отдельным исследованием REST issue #20, потому что
задача включает connection filters, traversal, entity filters, permissions,
ordering/pagination и representation, а не только direction.
Deprecation `load()` в текущей major version сигнализируется PHPDoc и
документацией без нового runtime notice/behavior; удаление — в следующей major
version.

**Блокирует:** API-02.

### DG-M6. Database tenancy model

**Вопрос:** сохранять отдельную пару таблиц на client или переходить к общим
таблицам с `client` column?

- A: сохранить table-per-client и определить collision/length rules.
- B: спроектировать shared tables и migration.

**Рекомендация:** A для hardening release. B — отдельная major-version
инициатива, поскольку требует migration/rollback и меняет операционную модель.

**Решение:** approved A владельцем репозитория 2026-09-10. Hardening release
сохраняет table-per-client и добавляет явные canonical-name, collision, empty и
identifier-length rules; shared-table migration остаётся за scope релиза.

**Блокирует:** CORE-05 и DB-06.

### DG-M7. Transaction и failure semantics

**Вопрос:** какой уровень атомарности требуется create/update/delete + meta?

- A: транзакции обязательны для составных операций; failure откатывает всё.
- B: best effort с cleanup/retry и документированными partial failures.

**Рекомендация:** A на transactional engines, с явной capability check и
предсказуемым fallback/error на неподдерживаемом storage.

**Решение:** approved A владельцем репозитория 2026-09-10. Составная операция
либо commit целиком, либо полностью rollback; unsupported transactional
capability должна приводить к явной ошибке до mutation, а не к silent best
effort.

**Блокирует:** DB-05.

### DG-M8. Финальный quality target

**Вопрос:** какой coverage threshold нужен для release candidate?

- A: только глобальный процент.
- B: глобально не менее 70% строк плюс 100% согласованных critical scenarios.
- C: только critical scenarios без глобального процента.

**Рекомендация:** B. Critical scenarios: invariants, all delete paths, schema
recovery, REST CRUD/errors/permissions и multi-client isolation.

**Решение:** approved B владельцем репозитория 2026-09-10. До RC действует
монотонный coverage baseline; RC требует не менее 70% statements и прохождения
100% утверждённых critical scenarios.

**Блокирует:** TEST-03A—TEST-03C и REL-02.

### DG-M9. Граница domain API и Storage SPI

**Вопрос:** считается ли публично достижимый storage равноправным consumer API
для mutation или низкоуровневой extension boundary?

- A: storage является SPI для persistence adapters; гарантии invariants даёт
  high-level domain API, через общий validation path для всех его mutations.
  `getStorage()` сохраняется в текущей major version ради совместимости, но
  прямые storage writes не считаются поддерживаемым consumer flow.
- B: каждый storage implementation обязан самостоятельно реализовать все domain
  invariants.
- C: сохранить и документировать прямой unsafe mutation bypass как обычный API.

**Рекомендация:** A. Перенос relation/cardinality/entity rules в каждый custom
storage создаёт дублирование и несовместимые реализации, а вариант C не
позволяет библиотеке обещать целостность.

**Решение:** approved A владельцем репозитория 2026-09-10. В текущей major
version все high-level mutation paths, включая `Connection::update()`, должны
проходить общий domain validation path; storage остаётся совместимой SPI для
implementers. Сужение PHP visibility или новый command service возможно только
в следующей major version.

**Блокирует:** CORE-04, DB-05 и REL-02.

## Реестр решений

| Gate | Решение | Владелец | Дата | Следствие |
|---|---|---|---|---|
| DG-M1 | approved C | repository owner | 2026-09-10 | Strict entity validation + extension strategy |
| DG-M2 | approved C | repository owner | 2026-09-10 | `type` deprecated no-op; remove next major |
| DG-M3 | approved A | repository owner | 2026-09-10 | Stable domain codes отдельно от HTTP status |
| DG-M4 | approved A | repository owner | 2026-09-10 | Backward-compatible REST v1; opt-in representations |
| DG-M5 | approved B | repository owner | 2026-09-10 | `Connection::load()` deprecated; issue #20 отдельно |
| DG-M6 | approved A | repository owner | 2026-09-10 | Table-per-client hardening без shared-table migration |
| DG-M7 | approved A | repository owner | 2026-09-10 | Atomic compound operations или pre-mutation error |
| DG-M8 | approved B | repository owner | 2026-09-10 | RC: 70% statements + all critical scenarios |
| DG-M9 | approved A | repository owner | 2026-09-10 | Storage — SPI; invariants на domain boundary |

## Execution batches

### Batch 1. Активировать решения и нормализовать backlog

Status: completed

Scope:

- Зафиксировать DG-M1—DG-M9 и их compatibility consequences.
- Ограничить DG-M5 deprecation `Connection::load()` и отделить issue #20.
- Разбить TEST-02 на пять mergeable red-first vertical slices.
- Добавить недостающие design/inventory tasks и честно пересчитать readiness.
- Проверить все epic/task attributes независимым planning QA.

Exit criteria:

- Plan/README не содержат pending/противоречащих решений.
- Каждая execution task имеет обязательные `$decompose-work` attributes.
- Первый functional batch содержит только `todo` tasks с выполненным DoR.
- Docs-only PR проходит checks и влит до functional branches.

Verification:

- Independent planning QA: pass, blocking findings отсутствуют.
- 6/6 epics и 43/43 execution tasks имеют обязательные attributes; task IDs
  уникальны, `git diff --check` проходит.
- Baseline `make tests.run`: unit 4/7, integration 5/24.
- Commit: `6a3a336` (`Activate library hardening decisions`).

### Batch 2. Green foundation и contract discovery

Status: completed

Tasks:

- TEST-03A — quality/critical-scenario contract; automation остаётся в
  последующих TEST-03B/TEST-03C.
- REST-01 — full-dispatch REST harness без handler fixes.
- REL-00 — public consumer/compatibility inventory.
- API-01 — исследование REST issue #20 без production/API change.

Execution model:

- Четыре независимых workstreams с раздельными file ownership и зелёным CI.
- TEST-03A владеет quality contract/critical registry и PR checklist; REST-01 —
  только REST test base и новый harness test; REL-00 — отдельным compatibility
  inventory artifact; API-01 — отдельным issue #20 design/ADR artifact.
- После merge/QA выполняется readiness sweep; следующие design tasks — CORE-00,
  SPI-01, DB-00, REST-00A и REST-00B.
- Первые production vertical slices после contract tasks:
  TEST-02A/CORE-01 и TEST-02E/DB-01.
- В утверждённых vertical slices regression task после наблюдаемого red
  переходит в `review`; это достаточно, чтобы взять paired fix в том же batch.
  Обе задачи получают `completed` только после общего зелёного commit/PR.

Verification:

- TEST-03A: PR #52; independent QA pass; 17/17 required checks pass.
- API-01: PR #53; independent QA pass; 17/17 required checks pass после
  strict-base update.
- REL-00: PR #54; independent QA pass; 17/17 required checks pass после
  strict-base update.
- REST-01: PR #55; independent QA pass; 17/17 required checks pass после
  strict-base update.
- API-01 завершила research, но DG-API20-01—DG-API20-09 остаются pending и не
  считаются принятыми в результате merge ADR.

### Batch 3. Automated guardrails и первые vertical fixes

Status: planned

Tasks:

- TEST-03B + TEST-03C — автоматизировать отдельные PR/RC coverage profiles,
  reverse/repeat и seeded-random isolation checks; один workstream из-за общего
  ownership CI/runbook файлов, но раздельные task evidence/status.
- TEST-02A + CORE-01 — red-first vertical: воспроизвести missing-`to`, затем
  исправить relation-definition validation и всю enum/default/duplicate matrix.
- TEST-02E + DB-01 — red-first vertical: воспроизвести broken `both`, затем
  исправить placeholders и покрыть query matrix.
- API-02 — только PHPDoc/docs deprecation пустого `Connection::load()` и
  документированный relation-query replacement без runtime notice.

Execution model:

- Четыре изолированных workstreams стартуют от завершённого Batch 2.
- В двух vertical PR сначала записывается наблюдаемый red и regression task
  переводится в `review`; paired fix выполняется в той же утверждённой ветке;
  оба task получают `completed` только после итогового green.
- API-02 не расширяет issue #20/`getPosts()` и не зависит от pending
  DG-API20-01—DG-API20-09.
- Каждый workstream проходит independent QA, полный релевантный suite и
  protected-branch CI; ветки обновляются последовательно при strict-base rule.

Exit criteria:

- PR и RC coverage policy, isolation и flaky policy имеют исполняемые команды.
- Missing-`to` и `both` defects имеют red evidence и финально зелёные fixes.
- `Connection::load()` имеет утверждённый deprecation path без нового runtime
  behavior.
- После merge выполняется readiness sweep для Batch 4: CORE-00, SPI-01, DB-00,
  REST-00A и REST-00B остаются первыми design workstreams; CORE-05 и DB-03A
  также готовы, но планируются с учётом доступного ownership.

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

- При создании или переразбиении execution tasks повторно применять
  `$decompose-work` и сохранять Status, Goal, Scope, Out of Scope, DoR, DoD, AC,
  Dependencies и Notes/Risks.
- После TEST-01 выполнять TEST-03A и затем TEST-03B/TEST-03C независимо, а
  TEST-02A—TEST-02E поставлять red-first vertical slices вместе с
  соответствующими production fixes.
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

### TEST-02A. Зафиксировать missing-`to` relation regression

Status: completed

Priority: P0

Goal: доказать, что relation без обязательного `to` ошибочно регистрируется до
CORE-01 и предсказуемо отклоняется после исправления.

Scope:

- Один атомарный integration test для отсутствующего только `to`.
- Assertions на exception type и точный список missing parameters.
- Отдельная команда/filter для red/green evidence.

Out of Scope:

- Production fix CORE-01.
- Полная validation matrix.

DoR:

- TEST-01 завершена.

DoD:

- Test наблюдался красным на исходном production-коде по ожидаемой причине.
- Test зелёный вместе с CORE-01 в финальном vertical PR.
- Red/green команды и результаты записаны в task notes/PR.

AC:

- Given relation содержит `name` и `from`, но не `to`, when её регистрируют,
  then возникает `MissingParameters`, перечисляющий `to` один раз.

Dependencies:

- TEST-01.

Notes/Risks:

- Regression task не мержится отдельно с красным required CI; она поставляется
  одним зелёным vertical batch с CORE-01.
- Red evidence 2026-09-10 на неизменённом production-коде:
  `docker run --rm -v /tmp/wpconnections-core01:/srv/web
  wpconnections-final-clean:latest test:integration --filter
  test_rejects_relation_missing_only_to` — ожидаемый fail `1 test / 1 assertion`:
  relation зарегистрировалась без `to`, поэтому `MissingParameters` не возник.
- Green evidence после CORE-01 той же командой: `1 test / 2 assertions`, passed;
  exception содержит ровно `["to"]` и сообщение `Missing required fields: to `.

### TEST-02B. Зафиксировать cardinality `1-m` regression

Status: waiting_dependency

Priority: P0

Goal: доказать правильное ограничение занятой `to` стороны для relation `1-m`.

Scope:

- Один integration test: второй отличный `from` к уже занятому `to` запрещён.
- Assertion на утверждённый cardinality domain error.
- Отдельная команда/filter для red/green evidence.

Out of Scope:

- Production fix CORE-02.
- Остальные cardinality combinations.

DoR:

- CORE-01 завершена и relation definition validation стабильна.
- Семантика issue #33 подтверждена утверждённой cardinality matrix CORE-02.

DoD:

- Test наблюдался красным на исходной реализации cardinality.
- Test зелёный вместе с CORE-02 в финальном vertical PR.
- Red/green evidence записан.

AC:

- Given `A -> X` в relation `1-m`, when создаётся `B -> X`, then операция
  отклоняется cardinality error; `A -> Y` остаётся допустимой.

Dependencies:

- TEST-01.
- CORE-01.

Notes/Risks:

- Выполняется в одном зелёном vertical batch с TEST-02C и CORE-02.

### TEST-02C. Зафиксировать cardinality `m-1` regression

Status: waiting_dependency

Priority: P0

Goal: доказать правильное ограничение занятой `from` стороны для relation
`m-1`.

Scope:

- Один integration test: второй отличный `to` для уже занятого `from` запрещён.
- Assertion на утверждённый cardinality domain error.
- Отдельная команда/filter для red/green evidence.

Out of Scope:

- Production fix CORE-02.
- Остальные cardinality combinations.

DoR:

- CORE-01 завершена и relation definition validation стабильна.
- Семантика issue #33 подтверждена утверждённой cardinality matrix CORE-02.

DoD:

- Test наблюдался красным на исходной реализации cardinality.
- Test зелёный вместе с CORE-02 в финальном vertical PR.
- Red/green evidence записан.

AC:

- Given `A -> X` в relation `m-1`, when создаётся `A -> Y`, then операция
  отклоняется cardinality error; `B -> X` остаётся допустимой.

Dependencies:

- TEST-01.
- CORE-01.

Notes/Risks:

- Выполняется в одном зелёном vertical batch с TEST-02B и CORE-02.

### TEST-02D. Зафиксировать REST update без `title`

Status: waiting_dependency

Priority: P0

Goal: воспроизвести typed-property failure обычного update payload без `title`
через WordPress REST dispatch.

Scope:

- Full-dispatch regression с существующей connection и omitted `title`.
- Assertions на отсутствие fatal error и утверждённую preserve semantics.
- Отдельная команда/filter для red/green evidence.

Out of Scope:

- Production fix REST-02.
- Полная CRUD matrix.

DoR:

- REST-01 завершена.
- Утверждён partial-update contract для omitted/null/falsy fields.

DoD:

- Test наблюдался красным на исходном handler по ожидаемой причине.
- Test зелёный вместе с REST-02 в финальном vertical PR.
- Red/green evidence записан.

AC:

- Given connection с title, when update меняет другое поле и не передаёт title,
  then request не падает и прежний title сохраняется.

Dependencies:

- REST-01.
- REST-00B.

Notes/Risks:

- Нельзя подменять full-dispatch test прямым вызовом handler.

### TEST-02E. Зафиксировать поиск по `both`

Status: todo

Priority: P0

Goal: воспроизвести SQL placeholder defect при поиске connection по endpoint с
любой стороны.

Scope:

- Один integration test для `both=from` и `both=to` одной connection.
- Assertions на отсутствие SQL warning и правильный result set.
- Отдельная команда/filter для red/green evidence.

Out of Scope:

- Production fix DB-01.
- Полная query combination matrix.

DoR:

- TEST-01 завершена.

DoD:

- Test наблюдался красным на исходном SQL path по ожидаемой причине.
- Test зелёный вместе с DB-01 в финальном vertical PR.
- Red/green evidence записан.

AC:

- Given connection `A -> B`, when поиск выполняется с `both=A` и `both=B`, then
  оба запроса возвращают connection без `wpdb::prepare` warning.

Dependencies:

- TEST-01.

Notes/Risks:

- Regression task поставляется одним зелёным vertical batch с DB-01.

### TEST-03A. Зафиксировать test quality и critical-scenario contract

Status: completed

Priority: P1

Goal: определить reviewable quality contract и каталог critical scenarios до
изменения CI enforcement.

Scope:

- Реализовать DG-M8.
- Canonical список critical scenario IDs и per-component expectations: relation definition,
  deprecated `type`, entity validation/extension strategy, cardinality,
  error precedence, query directions, atomic create/update/delete/meta, schema
  recovery, multi-client isolation, REST CRUD/errors/permissions/meta/filters и
  hook/factory compatibility.
- PR checklist для затронутых scenarios и test evidence.
- Политика flaky/quarantine/exception: owner, reason, issue и expiry.
- Разделение PR no-regression baseline и финального RC target 70% statements.

Out of Scope:

- Написание всех тестов остальных эпиков.
- Изменение coverage checker/workflow и isolation commands.

DoR:

- DG-M8 решён.
- INFRA-04 публикует baseline.

DoD:

- Quality contract и critical registry записаны в репозитории.
- PR template ссылается на scenario IDs и требует evidence/exception metadata.
- TEST-03B/TEST-03C имеют execution-ready inputs.

AC:

- Given PR в critical component, then checklist требует соответствующий
  scenario test независимо от глобального coverage.
- Given временное исключение, then оно не существует без owner, причины, issue и
  expiry condition; RC не принимает active critical exceptions.

Dependencies:

- DG-M8.
- INFRA-04.

Notes/Risks:

- Высокий процент без branch/scenario coverage не гарантирует корректность
  cardinality или data integrity.

Verification:

- `docs/test-quality.md` содержит canonical trigger map и 39 уникальных
  critical scenario IDs для всех утверждённых component families.
- PR и RC profiles разделены: PR сохраняет exact monotonic baseline `365/786`,
  RC требует не менее 70% statements и 100% passing critical scenarios.
- PR template требует scenario-to-test evidence независимо от coverage и полную
  exception metadata; active critical exception явно блокирует RC.
- Structural contract check подтвердил 39/39 уникальных IDs, обязательные
  exception fields и M8 targets; `git diff --check` прошёл.

### TEST-03B. Автоматизировать PR baseline и RC coverage profiles

Status: completed

Priority: P1

Goal: сохранить зелёный monotonic PR gate и добавить явную проверку RC threshold
70% без преждевременной блокировки feature PR.

Scope:

- Добавить RC target в coverage configuration без изменения exact baseline.
- Разделить default PR и explicit RC modes coverage checker.
- Проверить baseline regression, RC below/equal/above 70% и malformed inputs.
- Добавить локальную RC command и обновить runbook.

Out of Scope:

- Довести product coverage до RC target 70%; эта задача автоматизирует policy,
  а не закрывает coverage gaps.
- Добавить новый required GitHub check без branch-protection review.
- Isolation/flaky enforcement TEST-03C.

DoR:

- TEST-03A завершена.
- INFRA-04 coverage gate остаётся зелёным на current baseline.

DoD:

- Default PR mode не допускает coverage regression и проходит на baseline.
- Explicit RC mode требует не менее 70% statements.
- Checker имеет automated synthetic tests и различает policy failure/malformed
  input exit codes.

AC:

- Given accepted baseline fixture `365/786` (46,44%), when работает PR mode,
  then gate проходит без регрессии и сообщает RC not ready.
- Given synthetic RC reports ниже/ровно/выше 70%, then результаты соответственно
  fail/pass/pass; malformed input даёт отдельный configuration error.

Dependencies:

- TEST-03A.
- INFRA-04.

Notes/Risks:

- Сравнение threshold выполняется exact integer counts, чтобы округление не
  скрывало regression.

Verification:

- Default `test:coverage` сохранил PR no-regression profile и accepted baseline
  `365/786`; explicit `test:coverage:rc`/`make tests.coverage.rc` требует 70% по
  exact integer counts.
- Synthetic cases: PR baseline pass `0`, PR regression `1`, RC ниже/ровно/выше
  70% — `1/0/0`, malformed input — configuration exit `2`.
- Реальный PR profile прошёл на `549/786 (69,85%)` и сообщил RC not ready;
  direct RC profile ожидаемо завершился checker policy exit `1`, а
  `make tests.coverage.rc` передал этот failure как non-zero target без изменения
  baseline.

### TEST-03C. Автоматизировать isolation и flaky policy

Status: completed

Priority: P1

Goal: проверять order independence/repeat и не позволять retry/quarantine
скрывать нестабильный critical test.

Scope:

- Локальная isolation command для reverse и seeded random repeat.
- CI integration без переименования существующих required checks.
- Seed/reproduction output и documented triage flow.
- Проверяемая exception metadata согласно TEST-03A.

Out of Scope:

- Исправление конкретного flaky теста, если он обнаружен.
- Глобальное включение strict PHPUnit mode без отдельного аудита suite.

DoR:

- TEST-03A завершена.
- TEST-01 isolation fixture остаётся зелёной.

DoD:

- Reverse/random repeat воспроизводимы локально и в CI.
- Failure сохраняет seed и не становится зелёным через silent retry.
- Runbook описывает owner/expiry/triage policy.

AC:

- Given order-dependent probe, when запускается isolation command, then command
  завершается ошибкой и показывает воспроизводимый seed/order.
- Given обычный suite, then reverse и seeded random repeat проходят без state
  leakage.

Dependencies:

- TEST-03A.
- TEST-01.

Notes/Risks:

- Repeat увеличивает CI time; измерение выполняется в существующем Coverage job,
  пока не появится отдельное решение о required-check topology.

Verification:

- `make tests.isolation ISOLATION_SEED=20260910` прошёл reverse и seeded-random
  phases по два повтора: unit `8 tests / 14 assertions` на phase, integration
  `20 / 138` на phase; seed и точная reproduction command напечатаны.
- Warm-image isolation run на PHP 8.1 / WordPress 6.7 занял `8,06 s`; существующий
  `Coverage PHP 8.1.34 / WordPress 6.7.7` required-check name не изменён.
- Synthetic order-dependent probe завершился ошибкой на первом failing phase и
  сохранил в output order/repeat/seed; silent retry отсутствует.
- Machine-readable exception registry проверяет точные TEST-03A fields,
  incomplete/expired records дают configuration exit `2`, active critical
  exception блокирует RC policy exit `1`.
- Полный suite `4/7 + 10/69`, combined coverage `14/76`, PR gate `549/786` и
  PHPCS `35/35` прошли.

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
- DG-M1, DG-M2, DG-M3, DG-M6 и DG-M9 для соответствующих задач.

Risks/Open Questions:

- Исправление cardinality может начать отклонять данные, которые старый код уже
  разрешал; перед release нужен аудит существующих consumers/data.

Tasking Guidance:

- При создании или переразбиении execution tasks повторно применять
  `$decompose-work` и сохранять все обязательные task attributes.
- CORE-00 design может идти параллельно CORE-01—CORE-03; production CORE-04
  начинается только после обоих потоков, client contract — после REL-00.
- Каждый production fix начинается с соответствующего TEST-02A—TEST-02E
  regression и поставляется с ним одним финально зелёным vertical PR.

### CORE-00. Спроектировать entity validation strategy и rollout

Status: todo

Priority: P1

Goal: превратить DG-M1/C и DG-M9/A в implementation-ready contract без
неявного breaking rollout.

Scope:

- Единая validation boundary для Relation create/update и Connection update.
- Default `WP_Post` existence/post-type resolver и extension strategy для
  разрешённых non-post entities.
- Domain errors/codes согласно DG-M3.
- Поведение legacy rows, bulk/import flows и rollout/migration guidance.
- Decision-ready описание публичных hooks/interfaces и downstream refinement
  CORE-04.

Out of Scope:

- Production implementation CORE-04.
- Перенос domain invariants внутрь каждого storage implementation.

DoR:

- DG-M1, DG-M3 и DG-M9 решены.

DoD:

- Validation contract перечисляет все поддерживаемые mutation entrypoints.
- Extension и rollout semantics достаточно точны для тестовых AC CORE-04.
- Material public extension choices оформлены как human decision gates.

AC:

- Given relation `page -> post`, when проектируется create/update validation,
  then contract однозначно определяет missing, deleted и wrong-type endpoints.
- Given custom entity adapter, then contract определяет регистрацию, результат и
  error behavior без изменения storage SPI.

Dependencies:

- DG-M1, DG-M3, DG-M9.

Notes/Risks:

- Strict validation может отклонить legacy data/import flows; rollout должен
  отделять чтение существующих rows от новых mutations.

### CORE-01. Валидировать relation definition

Status: completed

Priority: P0

Goal: нельзя зарегистрировать неполную или неизвестную relation configuration.

Scope:

- Исправить повторную проверку `name` вместо `to`.
- Required: `name`, `from`, `to`.
- Валидировать allowed cardinality.
- Не добавлять новую validation или behavioral semantics для legacy `type`:
  v1 продолжает принимать/сериализовать его как deprecated no-op.
- Проверить duplicate relation name и defaults.

Out of Scope:

- Проверка существования конкретных connection endpoints.

DoR:

- TEST-02A находится в `review`: red воспроизведён и зафиксирован в том же
  утверждённом vertical batch.
- DG-M2 решён.

DoD:

- Полная validation matrix покрыта unit/integration tests.
- Missing parameter message перечисляет только реально отсутствующие поля без
  дублей.
- Неизвестные cardinality values дают документированный exception/code.

AC:

- Given отсутствует только `to`, when relation регистрируется, then exception
  содержит только `to` как missing field.
- Given duplicate name, when relation регистрируется повторно, then возвращается
  стабильный `RelationWrongData` contract.
- Given валидный minimal query, then применяются документированные defaults.
- Given legacy `type` передан в v1 relation definition, then он принимается и
  сериализуется как deprecated metadata, не меняя validation или traversal.

Dependencies:

- TEST-02A (`review` с red evidence достаточно для paired vertical batch).
- DG-M2.

Notes/Risks:

- Ужесточение validation может выявить некорректные definitions у consumers.
- `Client::registerRelation()` проверяет обязательные `name`, `from`, `to`
  единым проходом и допускает только `1-1`, `1-m`, `m-1`, `m-m`; unknown value
  возвращает `RelationWrongData` code `400` с offending value.
- Legacy `type=from|to|both` остаётся сериализуемым no-op: physical
  `from -> to` create/query direction от него не зависит.

Verification:

- Targeted relation matrix: `21 tests / 52 assertions`; missing-field matrix,
  cardinality allowlist/rejections, duplicate contract, defaults и legacy type.
- Full default run: unit `4 / 7`, integration `31 / 121`; compatibility floor
  PHP 8.1.34 / WordPress 6.7.7: integration `31 / 121`.
- Reverse `--repeat=2` и random seed `20260910 --repeat=2`: `62 / 242` каждый.
- Combined coverage: `35 / 128`, gate passed at `568/787 (72.17%)` against
  baseline `365/786`; PHPCS passed `35/35`.

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

- TEST-02B и TEST-02C находятся в `review`: red воспроизведён и зафиксирован в
  том же утверждённом vertical batch.
- Семантика из issue #33 соответствует утверждённой AC matrix этой задачи.

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

- TEST-02B, TEST-02C (`review` с red evidence достаточно для paired vertical
  batch).
- CORE-01 для валидных enum values.

Notes/Risks:

- Нужен migration/audit report для уже существующих нарушений перед включением
  строгого update поведения.

### CORE-03. Зафиксировать duplicatable, closurable и error precedence

Status: waiting_dependency

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

Status: waiting_dependency

Priority: P1

Goal: connection endpoints соответствуют утверждённому WordPress entity/post
type contract.

Scope:

- Реализовать DG-M1 для create и всех поддерживаемых update paths.
- Tests для missing/deleted/wrong-type endpoints.
- Extension hook/strategy, если выбран вариант C.
- Провести high-level mutations через общий validation path согласно DG-M9;
  direct storage writes остаются SPI и не являются consumer API.

Out of Scope:

- Возврат полных entities через REST.
- Поддержка произвольных entity types без отдельного adapter.

DoR:

- DG-M1 решён.
- DG-M9 решён.
- CORE-00 завершила extension и backward-compatibility/rollout contract.

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
- DG-M9.
- CORE-00.
- CORE-03.

Notes/Risks:

- Строгая проверка может быть breaking для consumers, использующих IDs не из
  `wp_posts`.

### CORE-05. Зафиксировать client naming и migration contract

Status: todo

Priority: P1

Goal: определить canonical client identifier, table-name mapping и legacy
migration до изменения production naming behavior.

Scope:

- Применить REL-00 inventory к collision examples и существующим identifiers.
- Canonical alphabet/case, empty-name и maximum-byte-length rules с учётом
  `$wpdb->prefix` и MySQL identifier limit.
- Поведение имён, нормализующихся в одинаковый table postfix.
- Backward-compatible handling/migration для legacy underscores и иных имён.
- Decision-ready contract и test matrix для CORE-06/DB-06.

Out of Scope:

- Production validation/table-name changes.
- Shared-table migration, если выбран table-per-client.

DoR:

- DG-M6 решён.
- REL-00 завершил inventory существующих client names.

DoD:

- Для каждого raw/canonical/colliding/overlong case определён result/error и
  migration consequence.
- Material compatibility/migration choices оформлены как human decision gates.
- CORE-06 и DB-06 можно перевести в execution-ready после решений.

AC:

- Given `my-client` и `my_client`, when применяется contract, then однозначно
  определено, являются ли они одним client или collision error, и как защищены
  существующие tables.
- Given длинный WordPress prefix, then maximum client length рассчитывается для
  полного DB identifier, а не только postfix.

Dependencies:

- DG-M6.
- TEST-01.
- REL-00.

Notes/Risks:

- Любое изменение table postfix может потребовать migration существующих tables.

### CORE-06. Реализовать multi-client isolation и table-name rules

Status: waiting_dependency

Priority: P1

Goal: обещанная README изоляция клиентов сохраняется для всех утверждённых имён
и не допускает silent normalized collisions.

Scope:

- Реализовать утверждённый CORE-05 canonical/migration contract.
- Tests двух клиентов с разными relations/data.
- Collision, empty, length и legacy compatibility scenarios.
- Документировать фактический table naming.

Out of Scope:

- Shared-table migration.
- Naming policy, не утверждённая в CORE-05.

DoR:

- CORE-05 завершён и возникающие human gates утверждены.
- TEST-01 завершена.

DoD:

- Разные допустимые client names не разделяют данные неожиданно.
- Collision/empty/overlong inputs дают утверждённый result/error до опасного SQL.
- Legacy compatibility/migration tests и документация соответствуют contract.

AC:

- Given два допустимых разных клиента, when каждый создаёт и удаляет связь, then
  данные другого не читаются и не изменяются.
- Given коллидирующие normalized names, then система не молча использует одну
  table pair как два разных client identity.

Dependencies:

- CORE-05.
- TEST-01.

Notes/Risks:

- Любая table rename/copy операция требует отдельного destructive migration
  review и rollback; она не подразумевается этой задачей автоматически.

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

- При создании или переразбиении execution tasks повторно применять
  `$decompose-work` и сохранять все обязательные task attributes.
- SPI-01 и DB-00 можно выполнять как ранние contract tasks; DB-01 и DB-02 —
  первые production slices. Delete и transaction задачи требуют отдельного
  review из-за риска потери данных.

### SPI-01. Зафиксировать storage SPI и mutation boundary

Status: todo

Priority: P0

Goal: превратить DG-M9/A в проверяемый extension contract до domain и
transaction refactoring.

Scope:

- Разделить consumer domain API и storage implementer SPI в документации.
- Зафиксировать обязательные storage operations, inputs, results и errors.
- Определить capability signaling для atomic compound operations DG-M7.
- Инвентаризировать Factory replacement contract и conformance test fixture.
- Уточнить dependencies/AC CORE-04, DB-05 и REL-02.

Out of Scope:

- Breaking PHP visibility change `Client::getStorage()`.
- Production refactor domain mutations.
- Реализация транзакций DB-05.

DoR:

- DG-M7 и DG-M9 решены.
- REL-00 завершил inventory фактического direct storage/custom storage usage.

DoD:

- Storage SPI contract описывает compatibility promise для implementers и явно
  исключает direct writes из consumer domain API.
- Transaction capability и unsupported behavior определены достаточно точно для
  DB-05.
- Conformance test plan имеет проверяемые scenarios.

AC:

- Given custom storage implementer, when он читает contract, then понимает
  обязательные методы, result/error semantics и atomic capability expectations.
- Given обычный consumer, then documentation направляет mutation через Relation
  или Connection domain API, а не через direct storage write.

Dependencies:

- DG-M7, DG-M9.
- REL-00.

Notes/Risks:

- Storage — публичная extension SPI, поэтому изменения abstract signatures могут
  быть breaking даже при запрете direct consumer mutations.

### DB-00. Исследовать DB compatibility и transaction capabilities

Status: todo

Priority: P1

Goal: определить поддерживаемые MySQL/MariaDB versions и engines, на которых
выполнима атомарность DG-M7 и воспроизводим schema recovery.

Scope:

- Текущие CI/container DB versions и WordPress compatibility expectations.
- MySQL/MariaDB version matrix для blocking и optional checks.
- Transactional engine detection, existing non-transactional tables и migration
  consequences.
- Nested transaction/savepoint risks для `$wpdb` callers.
- Decision-ready recommendation для DB-05, DB-06 и REL-01.

Out of Scope:

- Изменение production schema или engine.
- Реализация transaction wrapper.

DoR:

- INFRA-03 завершена.
- DG-M7 решён.

DoD:

- Версии/engines и blocking status описаны с воспроизводимыми probes.
- Unsupported и existing-table behavior имеют recommendation и alternatives.
- Material migration/support choices оформлены как human decision gate.

AC:

- Given каждая рекомендованная DB combination, when планируется DB-05/DB-06,
  then известны transaction и schema capabilities и команда проверки.
- Given non-transactional existing table, then документ не предполагает silent
  atomicity и описывает migration/error alternatives.

Dependencies:

- INFRA-03.
- DG-M7.

Notes/Risks:

- Выбор blocking DB matrix и engine migration остаётся owner decision после
  исследования.

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

- TEST-02E находится в `review`: red воспроизведён и зафиксирован в том же
  утверждённом vertical batch.

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

- TEST-02E (`review` с red evidence достаточно для paired vertical batch).

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
- REST-00B утвердил shared update semantics для omitted/null/falsy/no-op.

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
- REST-00B.

Notes/Risks:

- Таблица объявляет `meta_value NOT NULL`, тогда как object model допускает null;
  контракт нужно зафиксировать тестом и при необходимости schema change.

### DB-03A. Зафиксировать delete result и failure contract

Status: todo

Priority: P0

Goal: определить rows-affected, not-found, invalid-input и partial-failure
semantics до тестирования/refactor всех delete variants.

Scope:

- Инвентаризация storage и Relation detach delete paths.
- Result semantics для single/multiple IDs, directed pair, object side и no-op.
- Invalid/empty identifiers, conflicting direction flags и relation filter.
- Domain errors/codes и граница атомарности DG-M7.
- Decision-ready matrix для DB-03B и REST delete responses.

Out of Scope:

- Production delete changes.
- Transactions DB-05.
- WordPress `deleted_post` cascade DB-04.

DoR:

- DG-M3 и DG-M7 решены.

DoD:

- Каждый delete variant имеет однозначный success/no-op/error result.
- Partial failures не маскируются как rows-affected success.
- Material public compatibility choices оформлены как human decision gates.

AC:

- Given no matching row, invalid ID или conflicting flags, when читается matrix,
  then caller result/error определён отдельно для каждого случая.
- Given несколько matching connections, then contract определяет, что именно
  считает возвращаемое rows-affected значение.

Dependencies:

- DG-M3, DG-M7.

Notes/Risks:

- Текущее `$wpdb->rows_affected` после второго SQL statement не обязательно
  отражает количество логически удалённых connections.

### DB-03B. Покрыть все явные delete paths и meta cascade

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
- DB-03A contract утверждён.
- CORE-06 завершил multi-client isolation contract.

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
- DB-03A.
- CORE-06 для cross-client assertions.

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

- DB-03B завершена.
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

- DB-03B.
- DG-M1.

Notes/Risks:

- При shared entity между клиентами каждый client hook должен обработать только
  собственные tables.

### DB-05. Сделать составные storage operations атомарными

Status: waiting_dependency

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
- DG-M9 решён.
- SPI-01 и DB-00 завершены, owner утвердил возникающие DB/migration gates.
- DB-02 и DB-03B задают корректные success semantics.

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
- DG-M9.
- SPI-01, DB-00.
- DB-02, DB-03B.

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
- DB-00 определил поддерживаемую DB matrix.
- Владелец утвердил DB matrix и migration/error policy, предложенные DB-00.
- CORE-06 реализовал и проверил table naming rules.

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
- DB-00.
- CORE-06.

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

- При создании или переразбиении execution tasks повторно применять
  `$decompose-work` и сохранять все обязательные task attributes.
- REST-00A/REST-00B contracts и REST-01 harness могут идти параллельно. Затем
  REST-02 update defect, после чего CRUD/errors, permissions, meta и filters.

### REST-00A. Зафиксировать domain error → HTTP mapping

Status: todo

Priority: P0

Goal: превратить DG-M3/A в полную таблицу стабильных v1 error responses до
REST-03 и OpenAPI.

Scope:

- Инвентаризация domain exceptions/codes, включая 301—304 и not-found cases.
- Отдельный HTTP status для validation, conflict/invariant, not found,
  permission и storage failures.
- Backward-compatible v1 error body и serialization через WordPress REST.
- Decision-ready mapping table и test matrix для REST-03.

Out of Scope:

- Реализация handlers или REST-03 tests.
- Замена numeric domain codes строковыми identifiers.

DoR:

- DG-M3 и DG-M4 решены.

DoD:

- Каждая известная domain error имеет body code и независимый HTTP status.
- Numeric 301—304 нигде не трактуются как redirect statuses.
- Material mapping choices готовы для owner approval.

AC:

- Given invariant code 301—304, when строится REST error, then mapping однозначно
  задаёт 4xx status и сохраняет domain code в body.
- Given unknown/storage failure, then contract не выдаёт misleading success или
  redirect response.

Dependencies:

- DG-M3, DG-M4.

Notes/Risks:

- Точные HTTP statuses являются публичным REST contract и требуют утверждения
  перед production implementation.

### REST-00B. Зафиксировать connection partial-update semantics

Status: todo

Priority: P0

Goal: определить shared PHP/storage/REST поведение omitted, explicit null, falsy
и no-op update до DB-02, TEST-02D и REST-02.

Scope:

- Инвентаризация текущих PHP, storage и REST update paths.
- Различия POST/PUT/PATCH для connection fields и meta.
- Omitted field, explicit null, empty string, zero и empty collection.
- Preserve/replace semantics и результат no-op update.
- Совместимость существующих REST route args/defaults и PHP object update.
- Decision-ready contract и downstream test matrix.

Out of Scope:

- Production update fix.
- Новый REST response representation.

DoR:

- DG-M4 решён.

DoD:

- Для каждого method/value state определено persisted и response behavior.
- DB-02, TEST-02D и REST-02 имеют однозначные AC без скрытых defaults.
- Material compatibility choices оформлены как human decision gate.

AC:

- Given существующий title и omitted title, when PATCH меняет order, then
  contract однозначно определяет сохранение title и order `0`.
- Given explicit null или empty meta, then contract различает preserve, clear и
  invalid input.

Dependencies:

- DG-M4.

Notes/Risks:

- Текущие route defaults могут превращать omitted в explicit value; design
  должен опираться на full dispatch, а не только handler calls.

### REST-01. Создать end-to-end REST test harness

Status: completed

Priority: P0

Goal: тесты регистрируют routes и отправляют запросы через WordPress REST server.

Scope:

- `rest_api_init`, route discovery и `rest_get_server()->dispatch()`.
- Authenticated/unauthenticated users и nonce-independent unit context.
- Helpers для URL, payload и response assertions.
- Изоляция `$GLOBALS['wp_rest_server']`, чтобы callbacks не сохраняли client из
  предыдущего test.
- Сверка четырёх custom path patterns/двенадцати method-callback combinations с
  Postman collection; namespace root классифицируется как WordPress-generated.

Out of Scope:

- Исправление конкретных handlers.

DoR:

- TEST-01 завершена.
- Client hooks можно безопасно очищать между тестами.

DoD:

- Harness демонстрирует request validation, callback, permission и response
  serialization.
- Все четыре custom routes и двенадцать method/callback combinations
  обнаруживаются без зависимости от handler-array order.
- Direct handler test остаётся только там, где он проверяет отдельную unit logic.

AC:

- Given зарегистрированный client, when выполняется `rest_api_init`, then все
  ожидаемые routes/methods присутствуют.
- Given невалидный required arg, then WordPress validation отклоняет request до
  handler.
- Given unauthenticated request, then permission callback возвращает REST denial;
  given administrator, then representative existing connection сериализуется
  через полный dispatch.

Dependencies:

- TEST-01.

Notes/Risks:

- Namespace root в Postman может быть автоматически предоставлен WordPress и не
  должен ошибочно считаться отдельным custom route.
- `EDITABLE` регистрирует POST/PUT/PATCH, тогда как Postman перечисляет не все
  варианты; REST-01 фиксирует drift, но не меняет route contract.
- Update args/defaults, create `title` и DELETE meta drift принадлежат
  REST-00B/REST-02/REST-05, а не harness task.
- Реализован изолированный full-dispatch harness: четыре custom path patterns,
  двенадцать method/callback combinations, Postman inventory, validation,
  permission denial и authenticated serialization.
- Verification 2026-09-10: targeted REST `5 tests / 45 assertions`; полный WP
  integration на PHP 8.1 / WordPress 6.7 `10 / 69`; combined suite `14 / 76`;
  coverage gate `549/786 (69,85%)`, `+23,41 pp`; PHPCS `35/35`.

### REST-02. Исправить fatal error при update connection

Status: waiting_dependency

Priority: P0

Goal: обычный update payload без `title` не читает неинициализированное property
и корректно сохраняет заданные поля.

Scope:

- Реализовать TEST-02D regression в REST harness.
- Определить defaults/preserve semantics для omitted fields.
- Проверить POST/PUT/PATCH methods, разрешённые `EDITABLE`.
- Проверить `order=0` вместе с DB-02.

Out of Scope:

- Новый REST response format.

DoR:

- TEST-02D находится в `review`: red воспроизведён и зафиксирован в том же
  утверждённом vertical batch.
- REST-01 завершена.
- REST-00B утвердил semantics partial update для omitted/null/falsy fields.

DoD:

- Fatal error устранён.
- Omitted fields не обнуляются неожиданно.
- Full dispatch regression зелёный.

AC:

- Given connection с title, when PATCH меняет order без title, then response не
  содержит error и прежний title сохранён.
- Given order 10, when PATCH устанавливает 0, then response/storage показывают 0.

Dependencies:

- TEST-02D (`review` с red evidence достаточно для paired vertical batch).
- REST-01.
- REST-00B.
- DB-02.

Notes/Risks:

- Нужно различать omitted, explicit null и falsy value.

### REST-03. Покрыть connection CRUD и error mapping

Status: waiting_dependency

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
- REST-00A mapping утверждён.
- REST-01, REST-02, CORE-03 и DB-03B завершены.

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
- REST-00A.
- REST-01, REST-02, CORE-03, DB-03B.

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

- REST-01 и DB-02/DB-03B завершены.

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
- DB-02, DB-03B.

Notes/Risks:

- Текущий DELETE route не описывает `meta` в собственных args.

### REST-06. Реализовать filters relation list из issue #21

Status: waiting_dependency

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
- API-01 утвердил границу connection filters issue #21 и issue #20 entity
  representation.
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
- API-01.
- DB-01.
- REST-03.

Notes/Risks:

- Pagination и deterministic ordering понадобятся при больших relation lists;
  при необходимости создать отдельный follow-up issue.

## E5. Незавершённый API, related entities и документация

Outcome: пустой `load()` имеет формальный deprecation path, а REST issue #20
получает исследованный и реализованный related-entity contract, не
предопределённый старой пустой сигнатурой `getPosts()`.

Scope:

- Deprecation `Connection::load()`.
- Отдельный design issue #20: connection filters, explicit traversal side,
  entity filters, permissions, ordering/pagination и representation.
- Bulk entity resolution без N+1 после утверждения контракта.
- Opt-in REST representation согласно DG-M4.
- OpenAPI issue #27.
- Dashboard issue #28 как отдельная инициатива.

Out of Scope:

- Реализация dashboard frontend в hardening release.
- Использование deprecated `relation.type` для traversal.
- Реализация `getPosts()` до утверждения issue #20 contract.

Success Criteria:

- В public API нет молча пустых методов.
- Issue #20 отделяет selection connections от resolution/representation
  entities и закрыт проверенным REST contract.
- Default REST v1 response shape не меняется.
- OpenAPI соответствует dispatch tests.
- Каждый open issue закрыт, запланирован отдельно или осознанно deferred.

Dependencies:

- E2, E3 и E4; DG-M1, DG-M2, DG-M4, DG-M5 и DG-M9.

Risks/Open Questions:

- Entity expansion может создать N+1 queries, раскрыть недоступные posts или
  изменить response size/shape.
- Фильтрация entities после pagination connections создаёт неполные страницы;
  pipeline должен быть определён до implementation.
- Существующая сигнатура `getPosts(direction)` может оказаться недостаточной и
  не является заранее утверждённым публичным контрактом.

Tasking Guidance:

- При создании или переразбиении execution tasks повторно применять
  `$decompose-work` и сохранять все обязательные task attributes.
- Сначала API-01 design issue #20, затем отдельные API-03/API-04 implementation
  tasks после утверждения возникающих public-contract gates.
- API-02 deprecation не зависит от issue #20 и может выполняться отдельно.
- OpenAPI создаётся из стабильного REST contract, dashboard получает отдельный
  discovery epic.

### API-01. Исследовать и зафиксировать contract issue #20

Status: completed

Priority: P1

Goal: определить, как consumer выбирает connections и получает связанные
WordPress entities через REST, не сводя задачу к одному direction parameter или
к существующему пустому `getPosts()`.

Scope:

- Сценарии anchor `from`/`to`, целевой стороны и двустороннего traversal.
- Граница connection filters issue #21 и entity-level filters.
- AND/OR semantics для комбинаций `from`, `to` и `both` filters issue #21.
- Варианты response representation при сохранении default REST v1 shape.
- Ordering, pagination/total, duplicates и missing/deleted endpoints.
- Permissions/context для private, draft и недоступных posts.
- Query-count/performance expectations и adapter boundary для non-post entities.
- Решение, реализуется, заменяется или deprecated существующий `getPosts()`.
- Decision-ready ADR/contract с примерами запросов/ответов без production change.

Out of Scope:

- Реализация PHP или REST handlers.
- Проектирование dashboard.
- Возвращение raw `WP_Post` без REST preparation и permission checks.

DoR:

- DG-M1, DG-M2, DG-M4 и DG-M9 решены.
- Issues #20 и #21 доступны как traceability source.

DoD:

- Contract разделяет connection selection, endpoint projection и entity
  filtering/representation.
- Combinations `from`/`to`/`both` имеют однозначную AND/OR semantics для REST-06.
- Для response shape, pagination, ordering, duplicates, missing entities и
  permissions есть decision-ready recommendation и credible alternatives.
- Все material public API choices оформлены как явные human decision gates.
- API-03 и API-04 можно уточнить до execution-ready состояния после решений.

AC:

- Given consumer хочет получить `to` posts определённого `from`, when читается
  contract, then однозначны anchor filter, target side, entity filters, порядок,
  pagination и response shape.
- Given default v1 request без opt-in representation, then response остаётся
  массивом connections с numeric endpoint IDs.
- Given недоступный current user post, then contract не допускает его раскрытие
  через related-entity response.

Dependencies:

- DG-M1, DG-M2, DG-M4, DG-M9.
- GitHub issues #20 и #21.

Notes/Risks:

- Decision-ready contract записан в
  [`docs/api-01-related-entities-contract.md`](../api-01-related-entities-contract.md).
- Issues #20/#21 и отсутствие комментариев повторно проверены 2026-09-10;
  production-код не изменялся.
- DG-API20-01—DG-API20-09 остаются pending: completion API-01 означает
  завершённое исследование, но не утверждение рекомендаций. Implementation
  остаётся `waiting_dependency` до явных решений владельца.

Verification:

- Contract отдельно определяет connection selection, endpoint projection и
  entity filtering/representation и содержит end-to-end request matrix.
- Default v1, permissions/context, pagination/totals/order, duplicates,
  missing endpoints, adapters, query budget и `getPosts()` покрыты явными
  alternatives/recommendations без молчаливого принятия решений.

### API-02. Решить судьбу `Connection::load()`

Status: completed

Priority: P2

Goal: public method не остаётся молча пустым.

Scope:

- Объявить deprecated согласно DG-M5 без добавления нового runtime behavior.
- Инвентаризировать использование метода в доступных consumers.
- Документировать relation query flow как замену и removal version.
- Добавить contract test/documentation check для deprecation.

Out of Scope:

- Новый ORM/data mapper.

DoR:

- DG-M5 решён.
- REL-00 завершил consumer usage inventory.

DoD:

- Метод имеет формальный, тестируемый deprecation path.
- README/API docs не обещают отсутствующее поведение.

AC:

- Given consumer видит `load()`, when читает public API documentation, then
  указаны deprecated status, поддерживаемая замена и removal major version.
- Given текущая major version, when legacy code вызывает метод, then не получает
  придуманного нового load/refresh поведения.

Dependencies:

- DG-M5.
- REL-00.

Notes/Risks:

- Согласно DG-M5 deprecation остаётся documentation/PHPDoc-only в текущей major
  version; runtime notice не добавляется, чтобы не ломать consumers, которые
  превращают notices в exceptions.
- PHPDoc и `docs/deprecations.md` фиксируют no-op до удаления в 2.0.0 и
  направляют consumer к `Relation::findConnections()`; README ссылается на
  migration guide. `ConnectionCollection::getPosts()` явно остаётся вне API-02.
- REL-00 не нашла публичных вызовов `load()`, но private usage остаётся
  residual risk.

Verification:

- Unit contract проверяет `@deprecated`, removal target/replacement и то, что
  вызов в текущей major version возвращает `null`, не меняя connection.
- `make tests.phpunit`: 6 tests / 14 assertions; `make tests.run`: unit 6/14,
  integration 10/69; `make lint.phpcs`: 35/35 files.
- `make tests.coverage`: 16 tests / 83 assertions; 549/786 statements (69,85%),
  approved exact baseline 365/786 пройден.

### API-03. Реализовать bulk resolution связанных entities

Status: waiting_dependency

Priority: P1

Goal: предоставить утверждённый PHP-level resolver endpoint IDs без N+1 и
решить судьбу `ConnectionCollection::getPosts()` согласно API-01.

Scope:

- Реализовать утверждённые target-side, order, duplicate и missing semantics.
- Bulk-load WordPress entities и проверить query count.
- Поддержать adapter boundary DG-M1 для разрешённых non-post entities.
- Реализовать либо deprecated `getPosts()` в соответствии с утверждённым ADR.

Out of Scope:

- REST response formatting.
- Новые entity types без утверждённого adapter.

DoR:

- API-01 завершена; владелец утвердил DG-API20-02, DG-API20-05,
  DG-API20-06, DG-API20-08 и DG-API20-09.
- CORE-00 завершила entity adapter contract, и возникшие material gates
  утверждены.
- DB-01 и REST-06 завершены.

DoD:

- Resolver contract покрыт unit/integration tests.
- Коллекция разрешается ограниченным числом queries без запроса на каждый item.
- Пустой `getPosts()` больше не остаётся молча неработающим API.

AC:

- Given несколько connections с повторяющимися endpoint IDs, when выполняется
  resolution, then ordering/duplicates соответствуют утверждённому contract.
- Given missing или недоступный adapter endpoint, then поведение соответствует
  утверждённому missing/error contract.

Dependencies:

- API-01.
- CORE-00.
- DG-API20-02, DG-API20-05, DG-API20-06, DG-API20-08, DG-API20-09 approvals.
- DB-01, REST-06.

Notes/Risks:

- Реализация не должна заставлять storage отвечать за entity permissions.

### API-04. Реализовать opt-in REST representation issue #20

Status: waiting_dependency

Priority: P1

Goal: закрыть issue #20 permission-aware представлением связанных entities без
изменения default REST v1 response.

Scope:

- Реализовать утверждённый opt-in request/response contract.
- Использовать REST-06 connection filters и API-03 bulk resolver.
- Применить entity filters, permissions/context, ordering и pagination contract.
- Добавить full-dispatch tests, performance assertions и OpenAPI input.

Out of Scope:

- Breaking изменение default v1 relation response.
- Dashboard UI.

DoR:

- API-01 завершена; владелец утвердил DG-API20-02—DG-API20-07 и
  DG-API20-09.
- API-03, REST-03 и REST-06 завершены.

DoD:

- Issue #20 закрыт проверенными REST scenarios.
- Default response остаётся обратно совместимым.
- Недоступные entities не раскрываются, N+1 отсутствует.

AC:

- Given заданный `from` и opt-in target `to`, when request выполняется, then
  возвращается утверждённое представление только разрешённых matching entities.
- Given тот же request без opt-in, then возвращается прежний connection payload.
- Given entity filters и pagination, then items и totals соответствуют contract.

Dependencies:

- API-01, API-03.
- DG-API20-02, DG-API20-03, DG-API20-04, DG-API20-05, DG-API20-06,
  DG-API20-07, DG-API20-09 approvals.
- REST-03, REST-06.

Notes/Risks:

- Entity filtering и pagination должны выполняться в утверждённом порядке, иначе
  страницы и totals будут вводить consumer в заблуждение.

### DOC-01. Создать OpenAPI contract из проверенных REST routes

Status: waiting_dependency

Priority: P1

Goal: закрыть issue #27 машинно-проверяемой спецификацией.

Scope:

- Paths, methods, params, request/response schemas, auth и errors.
- Examples для CRUD, meta, filters и утверждённого opt-in issue #20 contract.
- `relation.type` отмечен deprecated no-op в v1 и не описан как traversal control.
- CI validation OpenAPI syntax и drift check с route inventory, где возможно.

Out of Scope:

- Dashboard implementation.
- Недокументированные future v2 endpoints.

DoR:

- REST-03—REST-06 завершены.
- API-04 завершена.
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
- API-04.

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

- При создании или переразбиении execution tasks повторно применять
  `$decompose-work` и сохранять все обязательные task attributes.
- REL-00 inventory выполняется ранним foundation batch и разблокирует naming,
  deprecation и SPI work. Остальные compatibility tests можно готовить раньше,
  но release checklist закрывать только после всех blocking эпиков.

### REL-00. Инвентаризировать public consumers и compatibility surface

Status: completed

Priority: P0

Goal: собрать доступные evidence использования client names, `load()`, direct
storage, hooks/factories и relation definitions до compatibility-sensitive fixes.

Scope:

- README/wiki/examples, GitHub code search и доступные public dependent repos.
- Использование нестандартных client identifiers, `Connection::load()`,
  `getStorage()` mutations, custom storage и lifecycle hooks.
- Traceability evidence с датой/источником и явные ограничения поиска.
- Compatibility inputs для CORE-05, API-02, SPI-01 и REL-02.

Out of Scope:

- Гарантия обнаружения private consumers.
- Изменение production API или публикация migration.

DoR:

- GitHub read access доступен.
- DG-M5, DG-M6 и DG-M9 решены.

DoD:

- Inventory artifact перечисляет найденные patterns и отсутствие evidence там,
  где использование не найдено.
- Для каждого compatibility-sensitive task указаны ограничения и migration risk.
- Секреты/tokens не попадают в artifact или git history.

AC:

- Given найденный consumer нестандартного client name или storage SPI, when
  планируется соответствующий fix, then source и compatibility consequence
  доступны исполнителю.
- Given private usage нельзя проверить, then это явно записано как residual risk,
  а не трактуется как доказанное отсутствие consumers.

Dependencies:

- DG-M5, DG-M6, DG-M9.
- GitHub read access.

Notes/Risks:

- Public search даёт lower bound; migration notes всё равно должны учитывать
  неизвестные private installations.
- Evidence: `docs/compatibility-inventory.md` на срезе 2026-09-10. Найдены три
  независимых public consumer repository и один distribution mirror; два
  consumer зависят от физических storage details, один выполняет direct
  storage mutation, а два используют client-scoped capability filter.
- Публичного использования `Connection::load()`, `ConnectionCollection::getPosts()`,
  custom storage/factory replacement и relation/storage lifecycle callbacks не
  найдено; это negative evidence с явно записанными ограничениями, а не
  доказательство отсутствия private consumers.

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
- DB-00 завершён, владелец утвердил DB matrix.

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
- DB-00.
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
- Зафиксировать storage как SPI согласно DG-M9: compatibility для implementers
  сохраняется, прямые writes не документируются как consumer domain API.
- Backward-compatibility inventory.

Out of Scope:

- Добавление новых extension systems.
- Breaking visibility change `Client::getStorage()` в текущей major version.

DoR:

- Core/storage/REST contracts стабильны.
- REL-00 завершил consumer inventory.
- SPI-01 завершён.

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
- DG-M9.
- REL-00, SPI-01.

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
| Confirmed: `1-m`/`m-1` violations | TEST-02B, TEST-02C, CORE-02 |
| Confirmed: relation без `to` | TEST-02A, CORE-01 |
| Confirmed: REST update uninitialized `title` | TEST-02D, REST-02 |
| Confirmed: broken `both` placeholder | TEST-02E, DB-01 |
| Open [#31 error code tests](https://github.com/hokoo/wpConnections/issues/31) | CORE-03, REST-03 |
| Open [#21 REST filters](https://github.com/hokoo/wpConnections/issues/21) | REST-06 |
| Open [#20 entities/getPosts](https://github.com/hokoo/wpConnections/issues/20) | API-01, API-03, API-04, DOC-01 |
| Open [#27 OpenAPI](https://github.com/hokoo/wpConnections/issues/27) | DOC-01 |
| Open [#28 dashboard](https://github.com/hokoo/wpConnections/issues/28) | PROD-01 deferred initiative |
| Closed [#13 order zero](https://github.com/hokoo/wpConnections/issues/13) | DB-02 |
| Closed [#29 duplicate precedence](https://github.com/hokoo/wpConnections/issues/29) | CORE-03 |
| Closed [#33 cardinality](https://github.com/hokoo/wpConnections/issues/33) | CORE-02; reopen/follow-up |
| Closed [#35 permissions](https://github.com/hokoo/wpConnections/issues/35) | REST-04 |
| Closed [#45 dbDelta/schema](https://github.com/hokoo/wpConnections/issues/45) | DB-06 |
| Untested delete/meta cascade | DB-03A, DB-03B, DB-04, DB-05 |
| Untested multi-client promise | CORE-05 |
| Empty `Connection::load()` | DG-M5, API-02 |
| `type` has no behavior | DG-M2, CORE-01, DOC-01 |
| Direct storage mutation bypasses domain invariants | DG-M9, CORE-04, DB-05, REL-02 |
| Open issue #20 related entities | API-01, API-03, API-04, DOC-01 |
| Missing route-level REST tests | REST-01—REST-05 |
| Missing coverage/quality policy in CI | INFRA-04, TEST-03A, TEST-03B, TEST-03C |
