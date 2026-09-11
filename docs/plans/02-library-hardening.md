# Основной план стабилизации wpConnections

## Условие запуска

Milestone M0 достигнут 2026-09-10: инфраструктурная ветка влита в `master`,
clean test flow воспроизводим, coverage baseline доступен в CI. Основной план
активен; Batch 1—5 завершены. В Batch 5 production slice CORE-03 завершён и
issue #31 закрыт; DB-00, REST-00A, DB-03A и CORE-05 завершили decision-ready
discovery. DP-1—DP-3 и refinement gates DG-UPDATE-02R/DG-ENT-06 утверждены
владельцем 2026-09-11; DG-UPDATE-04/A из DP-4 также утверждён. Batch 6 завершён:
CORE-06R влит PR #76 как `2371ed2`. Batch 7 завершён: HOOK-TRANS-01 влит PR
#77 как `5c2fc26`, final head и post-merge `master` прошли по 17/17 jobs.
HOOK-00 завершён PR #78 как `cf8caa6`, также с 17/17 final-head и post-merge
jobs. HOOK-02 получил independent QA PASS; candidate head `d7ab4bd` PR #79,
merge `cf67eee` и post-merge прошли 17/17 jobs; Batch 8 завершён. Все hook gates,
DG-SPI-06/A и DG-RESTERR-03/A утверждены владельцем 2026-09-11. Координаты
standalone manager package `hokoo/wp-hooks-dispatcher` и namespace
`iTRON\wpHooksDispatcher\` подтверждены 2026-09-12. HOOK-01 завершён release
`v1.0.1`; Packagist и clean PHP 8.1 install подтверждены. Batch 9 продолжает
LOG-HOOK-01 как `in_progress`.

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

### DG-QMETA-01. Восстанавливать ли удалённый `IQuery` contract для `Query\Meta`

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** PR #26/commit `2b7bacc` удалил `Abstracts\IQuery` и
`IQueryTrait` вместе с PATCH/PUT-флагом `isUpdate` из актуального query flow, но
`Query\Meta` сохранил обе ссылки. Поэтому первая материализация
`Query\Meta` завершается fatal error до domain/REST error handling. Неясно,
считать ли старые `isUpdate()`/`setIsUpdate()` частью compatibility surface,
хотя после 2024 года они не могли работать через этот класс.

- A: удалить из `Query\Meta` остаточные interface/trait references и мёртвый
  import в `ClientRestApi`; сохранить имя класса, наследование,
  `GSInterface` и collection type.
- B: восстановить удалённые interface/trait и `isUpdate` state только для
  `Query\Meta`, хотя production flow больше не использует эту семантику.
- C: добавить deprecated compatibility shim и план удаления в следующей major
  version; применять только при подтверждённом внешнем использовании старых
  методов.

**Рекомендация:** A. Git history показывает неполную механическую очистку PR
#26, а не намеренное удаление `Query\Meta`; возвращение неиспользуемого
`isUpdate` создаст ложный public contract. Public inventory и repository search
не нашли consumer evidence, но private consumers остаются неизвестны.

**Compatibility impact:** A восстанавливает создание `Query\Meta` и
create-with-meta/selective-delete flows, не меняя storage/REST semantics. B/C
возрождают публично достижимые методы, поведение которых не определено текущей
архитектурой.

**Последствия решения:** CORE-07 production fix разрешён; TEST-02F поставляется
с ним одним red-to-green vertical.

### DG-UPDATE-01. Развести public PHP update paths и REST methods

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** public `Relation::updateConnection(Query\Connection)` до commit
`2b7bacc` был sparse/PATCH-like, но теперь storage читает его как полный объект;
`Connection::update()` отдельно документирован как aggregate replacement.
Одновременно `WP_REST_Server::EDITABLE` публикует `POST`, `PUT` и `PATCH` через
один handler/schema: каждый method требует `from`/`to`, получает `order=0` и
передаёт полный query-object. Нужно решить и public PHP semantics, и её REST
mapping, а не только исправить handler.

- A: `Relation::updateConnection(Query\Connection)` является sparse scalar
  update, а `Connection::update()` — complete aggregate replacement; `PATCH`
  использует sparse semantics, `PUT` выполняет replacement, `POST` остаётся
  compatibility alias для PUT. Domain layer нормализует полное effective state
  до Storage SPI.
- B: оба PHP entrypoint считать replacement-oriented; REST `PATCH` отдельно
  загружает и объединяет persisted state, затем вызывает replacement domain
  path; `PUT`/`POST` остаются replacement.
- C: сохранить текущую единую replacement semantics для обоих PHP paths и всех
  POST/PUT/PATCH, устранив только fatal errors.

**Рекомендация:** A. Она восстанавливает исторически задуманную sparse semantics
там, где её ожидает PATCH, сохраняет единственный документированный legacy POST
и придаёт PUT стандартный полный смысл без нового REST representation.

**Compatibility impact:** A восстанавливает историческую sparse semantics
публичного Relation method и меняет ошибочное destructive поведение PATCH, не
ломая документированный POST; custom storage получает fully normalized input
только после update-payload решения, принадлежащего SPI-01. B сохраняет текущее
значение Relation method, но создаёт две разные PHP/REST orchestration semantics.
C закрепляет потерю omitted fields как public contract.

**Последствия решения:** TEST-02D становится `todo`; DB-02, REST-02 и REST-03
используют выбранную method matrix после своих остальных dependencies.

### DG-UPDATE-02. Значение omitted, null, empty и zero для scalar fields

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** текущий код смешивает отсутствие field с `null`, а местами — с
любым falsy value. Это уже ломало `order=0` в issue #13. Объект и таблица
допускают nullable title/order, route задаёт integer order, а endpoint IDs не
могут быть нулевыми. Без field-specific contract невозможно проверить ни PHP,
ни storage, ни REST одинаково.

- A: PATCH сохраняет omitted field; replacement требует положительные
  `from`/`to`, очищает omitted/explicit-null `title` в SQL null и ставит omitted
  `order` в `0`; explicit empty title и order `0` сохраняются; null/negative/
  boolean/empty-string order и null/zero endpoints отклоняются до mutation.
- B: любой explicit null очищает nullable column, включая `order`; omission
  всегда сохраняет прежнее значение даже при PUT/POST.
- C: унифицировать все falsy values через defaults (`title=''`, `order=0`) и не
  сохранять различие между omission, null и explicit empty/zero.

**Рекомендация:** A. Она сохраняет issue #13, legacy replacement defaults и
полезное различие `title=null`/`title=''`, но не вводит новое неясное значение
nullable order. Presence определяется до storage и никогда не через `empty()`.

**Compatibility impact:** A делает PATCH безопасным и оставляет POST
replacement-compatible; запросы, передававшие невалидный null/falsy endpoint
или order, начнут получать validation error вместо неявной подстановки или
частичной записи.

**Последствия решения:** TEST-02D и downstream DB-02/REST-02/OpenAPI используют
утверждённую field-state matrix.

### DG-UPDATE-02R. Presence при прямой записи legacy endpoint property

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** `Query\Connection::$from/$to/$both` исторически публичны и
инициализируются нулём. Поэтому обычное PHP-присваивание `$query->from = 0`
неотличимо от untouched default, хотя DG-UPDATE-02/A требует считать явный
нулевой endpoint supplied-invalid, а omission — сохранить.

- A: оставить публичный синтаксис, но внутренне сделать untouched endpoint
  properties uninitialized и отслеживать первую прямую запись через magic
  access. Legacy direct/get reads, `isset`, `property_exists`, `exists_*` и
  `toArray()` сохраняют значения; raw `get_object_vars()`/default `json_encode()`
  отражают presence и опускают untouched endpoints.
- B: отслеживать presence только через constructor и `set()`, считая прямое
  присваивание нуля omission. Это создаёт разные значения для двух публичных
  способов передать одно поле и нарушает DG-UPDATE-02/A.
- C: отказаться от sparse PHP update и требовать оба endpoint на каждом update.
  Это заметный breaking change для исторического Relation API.

**Рекомендация:** A. Она единственная сохраняет прямой публичный setter syntax
и утверждённую supplied-invalid semantics без нового command type или signature.

**Compatibility impact:** A сохраняет материализованные query reads и
`toArray()`, но raw object introspection/JSON больше не показывает untouched
endpoint keys. Эти raw shapes не были документированной query representation;
явно присвоенный zero по-прежнему виден и теперь предсказуемо отклоняется до
storage.

**Последствия решения:** CORE-04 реализует и тестирует прямые from/to zero
assignments; TEST-02D/REST-02 используют тот же presence contract для handler-
normalized input.

### DG-UPDATE-03. Где и как обновлять connection metadata

**Статус:** pending human decision.

**Проблема:** scalar connection route не объявляет `meta` и фактически его не
сохраняет, но create route и concrete `Connection::update()` работают с
aggregate metadata. Отдельный `/meta` route уже обещает POST append, PATCH
replace supplied keys и PUT replace-all, однако empty PUT сейчас может удалить
данные и затем упасть при попытке добавить пустую collection.

- A: scalar REST update не изменяет meta; REST clients используют `/meta` с
  POST append, PATCH replace supplied keys и PUT replace-all (omitted/empty PUT
  очищает). `Connection::update()` остаётся полным aggregate replacement, где
  empty collection означает clear. Неизвестный `meta` на scalar v1 route не
  становится mutation input и не рекламируется в OpenAPI.
- B: разрешить `meta` и на scalar connection route, применяя к нему semantics
  HTTP method, параллельно сохранив `/meta`.
- C: перенести metadata update в connection route и начать deprecation
  отдельного `/meta` subresource.

**Рекомендация:** A. Она сохраняет существующую специализацию `/meta`, не
добавляет скрытую составную mutation в scalar REST handler и совместима с
документированным aggregate behavior PHP `Connection::update()`.

**Compatibility impact:** A не расширяет advertised v1 mutation inputs;
строгое отклонение неизвестного scalar-route `meta` откладывается до v2. B
добавляет второй публичный путь к тем же данным и требует определить
atomicity/precedence scalar+meta. C ломает существующий subresource.

**Блокирует:** DB-02 metadata matrix, REST-05 и DB-05 update atomicity.

### DG-UPDATE-04. Результат changed, no-op, not-found и storage failure

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** `$wpdb->update()` возвращает affected rows или `false`, но
текущий `bool` contract схлопывает unchanged existing row, missing row и DB
failure в одно `false`. REST поэтому не может отличить корректный no-op от
ложного success response для отсутствующей или не записанной connection.

- A: сохранить текущие signatures: `true` означает существующая connection
  изменилась, `false` — существующая connection уже имела требуемое состояние;
  not-found/invalid и storage failure выражаются разными exceptions. REST v1
  оставляет `{updated: true|false}`, а ошибки маппятся отдельно.
- B: ввести публичный result object/enum с changed/no-op/not-found/failed и
  изменить return types domain API и Storage SPI.
- C: сохранить нынешний ambiguous bool, где `false` может означать no-op,
  отсутствие строки или failure.

**Рекомендация:** A. Она делает outcomes однозначными без breaking signature
change для custom storage и без изменения v1 success shape. Уже существующий
`Connection::update(): void` возвращает нормально и для changed, и для valid
no-op; новый return type возможен только в следующей major version.

**Compatibility impact:** custom adapters должны бросать exception при storage
failure и не выдавать missing target за no-op. B является явным public/SPI
breaking change; C не позволяет выполнить error и atomicity contracts.

**Последствия решения:** CORE-04 отличает missing positive ID до update SPI;
DB-02, REST-02, REST-03, DB-05 и REL-02 должны сохранить changed/no-op/not-found/
failure distinction. DG-SPI-03 всё ещё отдельно определяет adapter-failure
signal и не считается утверждённым этим решением.

### DG-UPDATE-05. Success/no-op response REST `/meta`

**Статус:** pending human decision.

**Проблема:** текущий handler кладёт PHP `Connection` object под ключ
`updated`, а direct tests сравнивают объект до wire serialization. Full-dispatch
probe показывает текущий default JSON: scalar connection fields сериализуются,
но `meta` становится объектом с публичным `collectionType`, а не persisted meta
array. Shape неудобен, однако approved DG-M4 требует сохранить default v1 и не
позволяет молча канонизировать ответ.

- A: сохранить exact current HTTP 200 `{updated: <legacy connection-object>}`
  для changed и valid no-op, включая нынешнюю nested `meta.collectionType`
  serialization; улучшение отложить до opt-in/v2 отдельной задачи.
- B: сохранить exact current shape по умолчанию, но добавить отдельный explicit
  opt-in representation с каноническими полями `id`, `title`, `relation`,
  `from`, `to`, `order`, `meta`.
- C: заменить default in place на canonical connection representation; этот
  вариант требует явно переоткрыть и изменить уже approved DG-M4.

**Рекомендация:** A для hardening release. Это единственный вариант без нового
public input и без исключения из DG-M4. Full-dispatch tests должны отдельно
зафиксировать changed/no-op для POST/PATCH/PUT; исправление representation
следует планировать как opt-in или v2, а не маскировать внутри REST-05.

**Compatibility impact:** A фиксирует как legacy даже неудачную wire
serialization, но не ломает v1 consumers. B расширяет public REST contract и
требует отдельного design/API task. C является breaking change и недоступен без
reopening DG-M4.

**Блокирует:** REST-05 и DOC-01.
### DG-SPI-01. Какой update payload пересекает Storage SPI

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** abstract и `WPStorage` формально принимают один и тот же
`Abstracts\Connection`, поэтому `Query\Connection` является допустимым subtype.
Но `Relation::updateConnection()` передаёт sparse query с неинициализированными
полями, тогда как `WPStorage` читает его как полностью materialized replacement.

- A: в v1 сохранить signature, но domain layer до SPI загружает, объединяет,
  валидирует и передаёт полностью инициализированный `Connection`.
- B: обязать каждый adapter понимать sparse `Query\Connection` и самостоятельно
  реализовать omitted/null/falsy patch semantics.
- C: в следующей major version ввести отдельные patch/replace command DTO и SPI
  methods, оставив v1 bridge.

**Рекомендация:** A для hardening release, C — кандидат следующей major version.
Это соответствует DG-M9 и не дублирует domain/REST invariants в adapters.

**Compatibility impact:** A меняет фактическую форму аргумента для custom
adapters, которые проверяли `Query\Connection`; B сохраняет текущий caller shape,
но расширяет обязанности implementers; C является breaking SPI.

**Последствия решения:** TEST-02D и CORE-04 разрешены своими остальными
dependencies; DB-02, REST-02 и REL-02 используют fully materialized SPI input.
CORE-02 не является владельцем исправления.

### DG-SPI-02. Кто назначает create ID и client context при hydration

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** `createConnection()` возвращает ID, но `WPStorage` также молча
записывает его в query; `findConnections()` молча помещает `Client` в hydrated
items. Abstract contract не описывает ни один из side effects, хотя domain
objects зависят от результата.

- A: adapter возвращает ID/persistence data, а domain layer назначает ID/client
  и создаёт domain `Connection` без обязательной мутации adapter inputs.
- B: закрепить за всеми adapters текущую обязанность мутировать create query и
  прикреплять `Client` к каждому hydrated object.
- C: в следующей major version ввести adapter-neutral records/results и mapper,
  сохранив v1 bridge.

**Рекомендация:** A без изменения v1 signatures; C — более чистая будущая
модель. Hidden object mutation не должна оставаться неявной обязанностью SPI.

**Compatibility impact:** A заметен custom adapters/прямым SPI consumers,
наблюдающим mutated query; B навсегда переносит WordPress object lifecycle во
все adapters; C breaking.

**Последствия решения:** CORE-04 реализует domain-owned ID/client hydration;
DB-02, DB-05 и REL-02 используют тот же contract после остальных dependencies.

### DG-SPI-03. Non-update results и adapter failure contract

**Статус:** pending human decision.

**Проблема:** SPI смешивает ID, counts, collection, void и untyped meta-delete
result; `WPStorage` чередует exceptions, `false`, `0`, empty collection и silent
SQL failures. Failure нельзя надёжно отличить от valid no-match/no-op.

- A: сохранить v1 signatures; create возвращает positive ID, read — collection,
  deletes — nonnegative affected-connection count, add-meta — void, remove-meta —
  фактический integer count до major signature change; adapter failure всегда
  даёт стабильный domain exception, а `0`/empty остаются только для утверждённых
  valid no-match/no-op cases.
- B: сохранить текущую operation-specific неоднозначность и silent failures.
- C: немедленно заменить mutation returns единым result object.

**Рекомендация:** A. Она не ломает abstract signatures и делает failure
проверяемым; delete meanings остаются за DB-03A, update result — только за общим
DG-UPDATE-04 из REST-00B.

**Compatibility impact:** A превращает часть silent/raw failures в exceptions и
может выявить несовместимые adapters; B не позволяет выполнить DG-M7; C breaking.

**Блокирует:** DB-03B-B, DB-05, REST-03 и REL-02 production/conformance. DB-03A
и REST-00A могут завершить decision-ready contracts и согласуют с этим gate
точные delete/error meanings.

### DG-SPI-04. Форма transaction capability и orchestration

**Статус:** pending human decision.

**Проблема:** approved DG-M7 требует capability preflight и atomic compound
operations, но Storage SPI не имеет capability discovery или transaction scope.

- A: добавить optional transaction-capability interface с одной guarded atomic
  callback/unit-of-work операцией; domain проверяет capability до mutation и
  оркестрирует compound operation внутри неё.
- B: добавить `supports`/`begin`/`commit`/`rollback` прямо в abstract Storage.
- C: добавить отдельные atomic compound create/update/delete methods каждому
  adapter.

**Рекомендация:** A. Optional interface не заставляет старые adapters
реализовывать новые abstract methods, mechanics остаются adapter-owned, а
orchestration/invariants — domain-owned согласно DG-M9.

**Compatibility impact:** A добавляет public optional SPI и переводит incapable
adapters на approved pre-mutation error; B ломает все subclasses; C существенно
расширяет SPI и дублирует domain semantics.

**Блокирует:** DB-05 и REL-02 production/conformance. DB-00 независимо проверяет
backend feasibility и уточняет этот gate до owner decision.

### DG-SPI-05. Factory replacement construction и failures

**Статус:** pending human decision.

**Проблема:** storage filter документирует только class choice, но runtime также
предполагает class-string, concrete `Storage` subtype, constructor с одним
`Client` и безопасное раннее создание. Constructor `TypeError` сейчас может быть
ошибочно представлен как inheritance failure.

- A: сохранить filter и два callback arguments в v1; явно требовать concrete
  `Storage` class, constructible с переданным `Client`, валидировать до `new` и
  нормализовать selection/construction failure в attributable
  `ClientRegisterFail`.
- B: расширить существующий filter до class-string/object/callable factory.
- C: в следующей major version ввести StorageFactory interface и новый filter с
  migration period для старого.

**Рекомендация:** A для v1, C — для следующей major. B делает существующий hook
неоднозначным без version boundary.

**Compatibility impact:** A сохраняет observed arguments/class-string path, но
может изменить exact error message/chaining; B/C добавляют новые public shapes,
а замена старого hook была бы breaking.

**Блокирует:** REL-02 и release compatibility documentation.

### DG-SPI-06. Значение mutation hooks при commit/rollback

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** часть текущих `after`/`deleted` hooks вызывается с raw failure или
после только последнего non-atomic statement. DB-05 не может считать их
committed-success hooks без явного compatibility решения.

- A: сохранить names/argument order в v1; `before` означает attempt, а
  success-named `after`/`deleted` испускаются один раз только после commit.
- B: сохранить точный текущий timing и документировать, что `after`/`deleted` не
  означает commit.
- C: добавить transaction committed/rolled-back hooks и откладывать deprecation
  неоднозначных hooks до следующей major version.

**Рекомендация:** A, с C как будущим расширением. Это выполняет запрет DG-M7 на
false success с минимальным hook-name churn.

**Compatibility impact:** A меняет timing и подавляет success hook при failure;
callbacks, использующие attempt-level timing, заметят изменение; B конфликтует с
DG-M7; C расширяет public hook API.

**Блокирует:** DB-05 и REL-02.

### DG-SPI-07. Legacy concrete storage introspection

**Статус:** approved A владельцем репозитория 2026-09-11.

**Проблема:** public consumers получают/reconstruct `WPStorage` table names через
`getStorage()`, но table identity не является portable SPI, а direct writes уже
исключены из поддерживаемого consumer API решением DG-M9.

- A: сохранить `getStorage()` и оба table getter в v1 как legacy concrete
  introspection; до deprecation дать high-level maintenance/orphan-cleanup
  alternatives и migration notes.
- B: сделать table getters обязательными abstract SPI methods.
- C: deprecated/remove getters без замены.

**Рекомендация:** A. Она учитывает наблюдаемых CF7 consumers, не притворяясь, что
каждый adapter имеет SQL tables.

**Compatibility impact:** A откладывает visibility reduction; B ломает non-table
adapters; C ломает подтверждённые public maintenance/orphan-cleanup flows.

**Последствия решения:** CORE-06 сохраняет legacy concrete introspection;
REL-02, DOC-01 и REL-03 должны предоставить conformance и migration guidance.

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
| [DG-API20-01](../api-01-related-entities-contract.md#dg-api20-01-selector-cardinality-and-combination) | pending; recommendation B | repository owner | — | REST-06 selector semantics wait |
| [DG-API20-02](../api-01-related-entities-contract.md#dg-api20-02-endpoint-projection-vocabulary) | pending; recommendation B | repository owner | — | API-03/API-04 projection vocabulary waits |
| [DG-API20-03](../api-01-related-entities-contract.md#dg-api20-03-opt-in-rest-representation) | pending; recommendation A | repository owner | — | API-04/DOC-01 representation waits |
| [DG-API20-04](../api-01-related-entities-contract.md#dg-api20-04-pagination-ordering-and-totals) | pending; recommendation B | repository owner | — | API-04 collection contract waits |
| [DG-API20-05](../api-01-related-entities-contract.md#dg-api20-05-entity-filter-namespace-and-matching) | pending; recommendation B | repository owner | — | API-03/API-04 filtering waits |
| [DG-API20-06](../api-01-related-entities-contract.md#dg-api20-06-missing-and-inaccessible-projected-entities) | pending; recommendation A | repository owner/security | — | API-03/API-04 unavailable-entity policy waits |
| [DG-API20-07](../api-01-related-entities-contract.md#dg-api20-07-entity-authorization-and-rest-context) | pending; recommendation B | repository owner/security | — | API-04 authorization/context waits |
| [DG-API20-08](../api-01-related-entities-contract.md#dg-api20-08-fate-of-connectioncollectiongetposts) | pending; recommendation B | repository owner | — | API-03/getPosts compatibility path waits |
| [DG-API20-09](../api-01-related-entities-contract.md#dg-api20-09-resolver-query-budget) | pending; recommendation B | repository owner | — | API-03/API-04 query budget waits |
| DG-QMETA-01 | approved A | repository owner | 2026-09-11 | TEST-02F + CORE-07 started in Batch 6 |
| DG-UPDATE-01 | approved A | repository owner | 2026-09-11 | Sparse PHP/PATCH; replacement Connection/PUT/legacy POST |
| DG-UPDATE-02 | approved A | repository owner | 2026-09-11 | Field-specific omitted/null/empty/zero semantics |
| DG-UPDATE-02R | approved A | repository owner | 2026-09-11 | Direct zero endpoint writes are supplied-invalid; materialized reads preserved |
| DG-UPDATE-03 | pending; recommendation A | repository owner | — | Metadata update boundary не утверждена |
| DG-UPDATE-04 | approved A | repository owner | 2026-09-11 | Existing changed/no-op bool; not-found/failure are distinct exceptions |
| DG-UPDATE-05 | pending; recommendation A | repository owner | — | REST meta success/no-op response не утверждён |
| DG-SPI-01 | approved A | repository owner | 2026-09-11 | Domain sends fully materialized update state to SPI |
| DG-SPI-02 | approved A | repository owner | 2026-09-11 | Domain owns create ID/client hydration; signatures retained |
| DG-SPI-03 | pending; recommendation A | repository owner | — | DB-03A/REST-00A coordinate; DB-03B-B/REST/atomic production waits |
| DG-SPI-04 | pending; recommendation A | repository owner | — | DB-00 refines feasibility; DB-05/REL-02 wait |
| DG-SPI-05 | pending; recommendation A | repository owner | — | v1 class-string factory contract; REL-02/release docs wait |
| DG-SPI-06 | approved A | repository owner | 2026-09-11 | Commit-aware success hooks; DB-05/REL-02 unblocked on this gate |
| DG-SPI-07 | approved A | repository owner | 2026-09-11 | Legacy concrete table introspection retained in v1 |
| [`DG-ENT-01`](../entity-validation-contract.md#dg-ent-01) | approved A | repository owner | 2026-09-11 | Any extant exact-type `WP_Post` is a valid endpoint |
| [`DG-ENT-02`](../entity-validation-contract.md#dg-ent-02) | approved A | repository owner | 2026-09-11 | Typed client-scoped non-post resolver registry |
| [`DG-ENT-03`](../entity-validation-contract.md#dg-ent-03) | approved A | repository owner | 2026-09-11 | Stable 305—310 reasons; entity validation precedes existing invariants/hooks |
| [`DG-ENT-04`](../entity-validation-contract.md#dg-ent-04) | approved A | repository owner | 2026-09-11 | Full effective state validated; repair/bypass API deferred |
| [`DG-ENT-05`](../entity-validation-contract.md#dg-ent-05) | approved A | repository owner | 2026-09-11 | Persisted owning relation is immutable |
| [`DG-ENT-06`](../entity-validation-contract.md#dg-ent-06) | approved A | repository owner | 2026-09-11 | Mutable creating-hook identity/endpoints are conditionally revalidated |
| [`DG-DB-01`](../db-compatibility-contract.md#dg-db-01) | pending; recommendation A | repository owner | — | DB-05/DB-06/REL-01 wait for DB matrix |
| [`DG-DB-02`](../db-compatibility-contract.md#dg-db-02) | pending; recommendation A | repository owner | — | InnoDB preflight/migration for DB-05/DB-06/REL-01 |
| [`DG-DB-03`](../db-compatibility-contract.md#dg-db-03) | pending; recommendation A | repository owner | — | DB-05/REL-02 nested transaction conformance waits |
| [`DG-DB-04`](../db-compatibility-contract.md#dg-db-04) | pending; recommendation A | repository owner | — | DB-05/DB-06/REL-01 schema lifecycle waits |
| [DG-RESTERR-01](../rest-error-contract.md#dg-resterr-01) | pending; recommendation A | repository owner | — | Domain-to-HTTP taxonomy; REST-03/REST-05/DOC-01/REL-02 wait |
| [DG-RESTERR-02](../rest-error-contract.md#dg-resterr-02) | pending; recommendation A | repository owner | — | Default v1 library error body; REST-03/REST-05/DOC-01/REL-02 wait |
| [DG-RESTERR-03](../rest-error-contract.md#dg-resterr-03) | approved A | repository owner | 2026-09-11 | Preserve native WordPress gateway status/code/data shape |
| [DG-RESTERR-04](../rest-error-contract.md#dg-resterr-04) | pending; recommendation A | repository owner | — | Safe storage/unknown boundary; REST-03/REST-05/DOC-01/REL-02 wait |
| [DG-DELETE-01](../delete-result-contract.md#dg-delete-01--relation-ownership-and-selector-composition) | pending; recommendation A | repository owner | — | DB-03B-A/REST-03/REST-05/REL-02 wait; DOC-01 refinement |
| [DG-DELETE-02](../delete-result-contract.md#dg-delete-02--logical-affected-count-semantics) | pending; recommendation A | repository owner | — | DB-03B-A/DB-03B-B/REST-03/REL-02 wait; DB-05 assertion refinement |
| [DG-DELETE-03](../delete-result-contract.md#dg-delete-03--valid-no-match-and-partial-match-semantics) | pending; recommendation A | repository owner | — | DB-03B-A/DB-03B-B/REST-03/REL-02 wait; REST-00A mapping refinement |
| [DG-DELETE-04](../delete-result-contract.md#dg-delete-04--id-normalization-and-invalid-or-ambiguous-input) | pending; recommendation A | repository owner | — | DB-03B-A/DB-04/REST-03/REL-02 wait |
| [DG-DELETE-05](../delete-result-contract.md#dg-delete-05--rest-connection-delete-success-representation) | pending; recommendation A | repository owner | — | REST-03 waits; DOC-01 refinement |
| [DG-DELETE-06](../delete-result-contract.md#dg-delete-06--deleted_post-cleanup-failure-and-recovery) | pending; recommendation A | repository owner | — | DB-04 waits; REL-03/DOC-01 refinement |
| [`DG-NAME-01`](../client-naming-contract.md#dg-name-01) | approved A | repository owner | 2026-09-11 | Compatibility normalization plus safe canonical identity |
| [`DG-NAME-02`](../client-naming-contract.md#dg-name-02) | approved A | repository owner | 2026-09-11 | Two-phase `ClientRegisterFail` code 4 boundary |
| [`DG-NAME-03`](../client-naming-contract.md#dg-name-03) | approved A | repository owner | 2026-09-11 | Legacy postfix retained with atomic site-local ownership claim |
| [`DG-NAME-04`](../client-naming-contract.md#dg-name-04) | approved A | repository owner | 2026-09-11 | Reject overlong complete identifiers; no implicit hash/truncate |
| [`DG-NAME-05`](../client-naming-contract.md#dg-name-05) | approved A | repository owner | 2026-09-11 | Explicit in-place adoption; no automatic destructive migration |
| [`DG-NAME-06`](../client-naming-contract.md#dg-name-06) | approved A | repository owner | 2026-09-11 | Default storage binds to construction-site prefix |
| [`DG-NAME-06R`](../client-naming-contract.md#dg-name-06r) | approved staged A-to-D | repository owner | 2026-09-11 | Preserve direct callback identity in 1.x; context-aware manager at the 2.0 boundary |
| [`DG-HOOK-01`](../hook-lifecycle-transition.md#dg-hook-01) | approved B | repository owner | 2026-09-11; coordinates 2026-09-12 | `hokoo/wp-hooks-dispatcher` `v1.0.1` published; HOOK-01 completed |
| [`DG-HOOK-SCOPE-01`](../client-owned-hook-inventory.md#dg-hook-scope-01) | approved A | repository owner | 2026-09-11 | First stable manager release supports actions only |
| [`DG-HOOK-REST-01`](../client-owned-hook-inventory.md#dg-hook-rest-01) | approved B | repository owner | 2026-09-11 | Managed init plus REST route boundary |
| [`DG-HOOK-REST-02`](../client-owned-hook-inventory.md#dg-hook-rest-02) | approved A | repository owner | 2026-09-11 | Reject duplicate live REST owner within one site identity |
| [`DG-HOOK-REST-03`](../client-owned-hook-inventory.md#dg-hook-rest-03) | approved A | repository owner | 2026-09-11 | Immediate late binding; native 404 before stale callbacks; stale index visibility accepted |
| [`DG-HOOK-REST-04`](../client-owned-hook-inventory.md#dg-hook-rest-04) | approved A | repository owner | 2026-09-11 | Factory-selected current-context delegate; custom private registrations remain implementer-owned |
| [`DG-HOOK-LOG-01`](../client-owned-hook-inventory.md#dg-hook-log-01) | approved B | repository owner | 2026-09-11 | Singleton origin-routed debug observer and documented custom Storage payload |
| [`DG-HOOK-LIFE-01`](../client-owned-hook-inventory.md#dg-hook-life-01) | approved A | repository owner | 2026-09-11 | `Client::dispose()` plus transactional initialization rollback |

Для DG-ENT-01—DG-ENT-06, DG-RESTERR-01—DG-RESTERR-04,
DG-DELETE-01—DG-DELETE-06, DG-NAME-01—DG-NAME-06R и DG-HOOK-01,
DG-HOOK-SCOPE-01, DG-HOOK-REST-01—DG-HOOK-REST-04, DG-HOOK-LOG-01,
DG-HOOK-LIFE-01 связанные contracts являются
canonical decision bodies (problem, alternatives, recommendation и compatibility
impact). Этот registry — canonical запись решения/status, владельца и даты.
Implementation использует оба источника; рекомендация в contract сама по себе
не меняет status в registry.

Для DG-API20-01—DG-API20-09 canonical decision body находится в
[`docs/api-01-related-entities-contract.md`](../api-01-related-entities-contract.md).
Registry выше остаётся canonical записью approval status/owner/date;
завершение API-01 не утверждает рекомендации.

Для DG-DB-01—DG-DB-04 canonical decision body находится в
[`docs/db-compatibility-contract.md`](../db-compatibility-contract.md). Registry
выше остаётся canonical записью approval status/owner/date; завершение DB-00 не
утверждает рекомендации.

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

Status: completed

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

Verification:

- API-02: PR #57; independent QA pass; 17/17 required checks pass.
- TEST-02A + CORE-01: PR #58; independent QA pass; четыре dependency-download
  HTTP 504 подтверждены по CI logs как pre-test infrastructure failures;
  selective failed-job rerun дал 17/17 required checks pass.
- TEST-03B + TEST-03C: PR #59; independent QA pass; после strict-base update
  17/17 required checks pass, включая новый isolation step внутри прежнего
  Coverage check.
- TEST-02E + DB-01: PR #60; initial и remediation QA pass; новый глобальный
  ordering contract удалён, чтобы не предрешать DG-API20-04; 17/17 required
  checks pass.

### Batch 4. Cardinality fix и три implementation-ready contracts

Status: completed

Tasks:

- TEST-02B + TEST-02C + CORE-02 — единый red-first vertical: сначала отдельно
  воспроизвести инверсию `1-m` и `m-1`, затем исправить create/update
  cardinality matrix для `1-1`, `1-m`, `m-1`, `m-m`.
- CORE-00 — decision-ready entity-validation/extension/rollout contract без
  production change и без неявного выбора нового public extension API.
- SPI-01 — decision-ready storage implementer SPI, mutation boundary,
  transaction capability и conformance-test contract без изменения signatures.
- REST-00B — decision-ready omitted/null/falsy/no-op partial-update semantics,
  основанные на полном REST dispatch, без handler fix.
- TEST-02F + CORE-07 — подтверждённый `Query\Meta` autoload fatal: red-first
  evidence готовится отдельно; production fix начинается только после
  DG-QMETA-01 и занимает первый освободившийся execution slot.

Entry criteria:

- Batch 3 влит; TEST-01, CORE-01, REL-00 и REST-01 завершены.
- Post-merge `master` SHA `6d42300` имеет 17/17 success: initial five
  Composer-download HTTP 504 failures были pre-test transient и selective
  rerun прошёл.
- DG-M1, DG-M3, DG-M4, DG-M7 и DG-M9 утверждены.
- TEST-02B/TEST-02C разблокированы CORE-01; их red evidence переводит обе
  задачи в `review` и тем самым открывает paired CORE-02 в той же ветке.
- Issue #33 однозначно задаёт ошибочный `1-m`; зеркальная `m-1` семантика уже
  явно утверждена AC CORE-02 и не требует нового решения.

Execution model:

- Четыре стартовых изолированных workstreams с отдельным ownership: один
  production vertical и три docs/design contracts; TEST-02F/CORE-07 — пятый
  условный follow-on, не вытесняющий начатую работу.
- В cardinality workstream оба regression tests сначала запускаются на
  неизменённом production-коде; CORE-02 начинается только после сохранённого
  red evidence и status review в этой же утверждённой ветке.
- Contract workstreams инвентаризируют фактический код, hooks и compatibility
  evidence. Любой новый public API/SPI, rollout/migration или REST semantic
  choice оформляется как pending human decision gate, а не принимается внутри
  PR.
- CORE-02 не меняет public storage signatures: обнаруженное расхождение между
  `Abstracts\Storage::updateConnection()` и фактическим вызовом
  `Relation::updateConnection()` передаётся SPI-01 как decision-ready finding.
- Каждый workstream проходит независимый QA. Production vertical выполняет
  targeted matrix, full suite, isolation, coverage и compatibility lanes;
  docs contracts проходят structural/traceability checks и protected CI.

Exit criteria:

- Все четыре cardinality enum имеют create/update allowed/forbidden evidence;
  rejected update не меняет исходную connection, issue #33 защищён regression.
- CORE-04, DB-05/REL-02 и TEST-02D/DB-02/REST-02 соответственно получают
  однозначные downstream AC либо явно перечисленные pending gates.
- `Query\Meta` fatal имеет red evidence; CORE-07 либо поставлен зелёным после
  DG-QMETA-01, либо явно остаётся `waiting_dependency` без маскировки внутри
  DB-02.
- Новые gates собраны с полной проблематикой, alternatives, recommendation,
  compatibility impact и списком заблокированных задач для решения владельца.
- Все PR смержены последовательно после independent QA и 17/17 required checks.

Verification:

- REST-00B: PR #62, independent remediation QA pass, 17/17 required checks;
  canonical update contract и DG-UPDATE-01—DG-UPDATE-05 доступны в `master`.
- TEST-02B + TEST-02C + CORE-02: PR #63, red-first commits сохранены, полный
  create/update matrix зелёный, independent QA pass, 17/17 required checks;
  issue #33 переоткрыт с evidence и закрыт merge commit.
- SPI-01: PR #64, independent remediation QA pass и повторный QA после rebase,
  17/17 required checks; DG-SPI-01—DG-SPI-07 доступны в `master`.
- CORE-00: PR #65, independent remediation и post-rebase QA pass, 17/17
  required checks; canonical entity contract и DG-ENT-01—DG-ENT-05 доступны в
  `master`.
- TEST-02F: red-only commit `3f50f77` независимо проверен. Unit и integration
  targeted runs оба завершаются ожидаемым fatal об отсутствующем
  `iTRON\wpConnections\IQueryTrait`; production не изменён, ветка не мержится
  до paired CORE-07 после решения DG-QMETA-01.

### Batch 5. Error contracts, delete/DB discovery и client naming

Status: completed

Tasks:

- CORE-03 — зафиксировать существующие domain errors 301—304,
  duplicatable/closurable/cardinality precedence и missing-endpoint behavior;
  закрыть issue #31 и добавить regression traceability для закрытого issue #29.
- REST-00A — decision-ready domain error → HTTP mapping на полном WordPress REST
  dispatch без handler или response changes.
- DB-03A — decision-ready delete result/failure/affected-count/atomic-boundary
  contract без production delete changes.
- DB-00 — воспроизводимое исследование MySQL/MariaDB/engine/transaction/savepoint
  capabilities без schema или CI policy changes.
- CORE-05 — первый follow-on после освобождения design-слота: decision-ready
  client naming, collision, identifier-length и legacy migration contract без
  production table-name changes.

Entry criteria:

- Batch 4 завершён после independent QA и последовательного merge CORE-02,
  REST-00B, SPI-01 и CORE-00.
- CORE-02, TEST-02B и TEST-02C имеют completed status; issue #33 закрыт.
- TEST-02F red evidence независимо проверен и записан; на момент активации
  Batch 5 CORE-07 оставался `waiting_dependency`, пока DG-QMETA-01 был pending.
- Canonical entity-validation, storage-SPI и partial-update artifacts, а также
  DG-ENT-01—DG-ENT-05, DG-SPI-01—DG-SPI-07 и DG-UPDATE-01—DG-UPDATE-05 доступны
  в `master`.
- Итоговый Batch 4 `master` `0db202e` имеет 17/17 successful check-runs; все
  Batch 5 workstreams обновляются на этот strict base перед merge.
- Активация Batch 5 не утверждала ни один pending human decision gate.

Execution model:

- Четыре стартовых независимых workstreams: CORE-03 владеет только existing
  invariant/error production path; REST-00A — REST error mapping contract;
  DB-03A — delete result/failure contract; DB-00 — DB compatibility и
  transaction feasibility.
- CORE-05 занимает первый освободившийся docs/design slot и остаётся частью
  exit criteria Batch 5.
- REST-00A, DB-03A, DB-00 и CORE-05 не меняют production/API/signatures. Каждый
  material public choice оформляется как pending gate с problem, alternatives,
  recommendation, compatibility impact и blocked tasks.
- REST-00A cross-reference DG-ENT-03, DG-SPI-03 и DG-UPDATE-04/DG-UPDATE-05,
  но не утверждает новые entity/update/storage errors или HTTP statuses.
- DB-03A владеет delete-specific single/multiple/no-match/invalid/partial-failure
  semantics; generic adapter failure, capability и hooks остаются за
  DG-SPI-03/DG-SPI-04/DG-SPI-06.
- DB-00 уточняет backend feasibility для DG-SPI-04 и предлагает, но не
  утверждает, blocking/optional DB matrix и migration policy.
- CORE-05 согласует concrete storage introspection с DG-SPI-07;
  предварительные DB-00 identifier findings используются как input, но DB-00
  не является hard dependency.
- CORE-03 ограничивается существующими errors 301—304 и MissingParameters.
  Precedence будущих entity-validation errors остаётся за DG-ENT-03. Любой
  необходимый production fix сначала получает отдельное red evidence.
- Ветки проходят independent QA и вливаются последовательно на strict base.
  После каждого merge оставшиеся ветки обновляются и повторяют
  пропорциональные проверки.

Conditional follow-on:

- CORE-05 начинается сразу после завершения первого из REST-00A, DB-03A или
  DB-00.
- Если DB-00 до финализации CORE-05 обнаруживает более строгий identifier-byte
  limit или backend-specific naming constraint, CORE-05 включает его как input.
- CORE-06 не начинается до owner approval возникающих naming/migration gates.

Exit criteria:

- CORE-03 покрывает type/code/message для 301—304, duplicate+cardinality → 303,
  forbidden self-connection → 301, pure cardinality → 302 и update без ID →
  304; missing endpoints охарактеризованы без неутверждённого изменения public
  payload. Issue #31 закрыт, issue #29 связан с regression evidence.
- REST-00A содержит полную current-state/full-dispatch mapping matrix для
  validation, conflict/invariant, not-found, permission, storage и unknown
  failures; numeric 301—304 не становятся HTTP redirects. Точные status/body
  choices остаются pending; REST-03 остаётся `waiting_dependency`.
- DB-03A различает single/multiple ID, directed pair, object-side, no-match,
  invalid/empty/conflicting flags, duplicate rows, logical affected count,
  partial SQL failure и hook timing. DB-03B-A и DB-03B-B остаются
  `waiting_dependency` до утверждения contract/gates.
- DB-00 содержит воспроизводимые probes и evidence по выбранным MySQL/MariaDB
  versions, engines, implicit DDL commits, nested transactions/savepoints и
  existing non-transactional tables; DB-05/DB-06/REL-01 не разблокируются без
  owner-approved DB/SPI gates.
- На момент Batch 5 closeout CORE-05 содержала
  raw/canonical/collision/empty/overlong/legacy migration matrix, а naming gates
  ещё были pending. DG-NAME-01—06/A и DG-SPI-07/A утверждены 2026-09-11;
  CORE-06 теперь ждёт завершения пересекающейся CORE-04, DB-06 сохраняет свои
  остальные зависимости.
- Все пять artifacts/verticals прошли independent QA, traceability checks и
  применимые protected CI, затем последовательно смержены.
- Ни один pending DG не утверждён неявно; completion design tasks означает
  decision-ready artifact, а не готовность зависимой production implementation.

Verification:

- CORE-03: PR #67, merge `b36fa85`, independent QA PASS, 17/17 required checks;
  issue #31 закрыт.
- DB-00: PR #68, merge `d175073`, initial и post-rebase QA PASS, 17/17 required
  checks.
- REST-00A: PR #69, merge `2db8b4d`, две remediation итерации и final QA PASS,
  17/17 required checks.
- DB-03A: PR #70, merge `ea12872`, remediation и post-rebase integrity QA PASS,
  17/17 required checks.
- CORE-05: PR #71, merge `3151285`, remediation и post-rebase integrity QA PASS,
  17/17 required checks.
- Historical Batch-5 readiness sweep: 45 задач до split DB-03B — 23 `completed`, 20
  `waiting_dependency`, TEST-02F `review`, PROD-01 `deferred`; `todo` и
  `in_progress` отсутствовали. На том checkpoint после split план содержал 46
  задач и 21 `waiting_dependency`; это не current count после Batch 6 и E7.

### Batch 6. Query-meta recovery, entity validation и client isolation

Status: completed

Goal: после явного утверждения минимальных decision packets выполнить первые
три независимые production verticals без неявного принятия последующих REST,
delete, transaction или issue #20 contracts.

Decision packets:

| Packet | Gates | Статус и вариант | Разблокирует |
|---|---|---|---|
| DP-1 Query Meta | DG-QMETA-01 | approved A, 2026-09-11 | TEST-02F + CORE-07 |
| DP-2 Domain mutation | DG-UPDATE-01/02/02R, DG-SPI-01/02, DG-ENT-01—06 | approved all A, 2026-09-11 | CORE-04; подготавливает TEST-02D/DB-02/REST-02 |
| DP-3 Client bootstrap | DG-NAME-01—06R, DG-SPI-07 | NAME-01—06 approved A; NAME-06R approved staged A-to-D; SPI-07 approved A, 2026-09-11 | CORE-06R; naming/migration preflight and compatible 1.x callback delivery |
| DP-4 Persistence integrity | DG-UPDATE-03/04, DG-SPI-03/04/06, DG-DB-01—04 | partial: UPDATE-04 and SPI-06 approved A 2026-09-11; остальные pending A recommended | DB-02/DB-05/DB-06 и failure contracts |
| DP-5 Delete | DG-DELETE-01—04/06 | pending; все A recommended | DB-03B-A/DB-03B-B/DB-04 |
| DP-6 REST wire | DG-RESTERR-01—04, DG-UPDATE-05, DG-DELETE-05 | partial: RESTERR-03 approved A 2026-09-11; остальные pending A recommended | REST-03—REST-05 exact wire contract |
| DP-7 Issue #21 selector | DG-API20-01 | pending; B recommended | REST-06 |
| DP-8 Issue #20 expansion | DG-API20-02—09 | pending; B/A/B/B/A/B/B/B recommended | API-03/API-04/DOC-01 |
| DP-9 Factory compatibility | DG-SPI-05 | pending; A recommended | REL-02/release documentation |

Entry criteria:

- Batch 5 завершён; PR #67—#71 merged последовательно, каждый имеет 17/17
  required checks и independent QA PASS.
- DP-1 и DP-2 утверждены вариантом A по каждому отдельному gate владельцем
  2026-09-11. В DP-3 DG-NAME-01—06 и DG-SPI-07 утверждены вариантом A, а
  DG-NAME-06R — как staged A-to-D transition; все решения записаны в canonical
  bodies и central registry.
- В DP-4 утверждены DG-UPDATE-04/A и DG-SPI-06/A. Остаток DP-4 и DP-5—DP-9 не
  считается неявно утверждённым и не блокирует задачи batch, если не перечислен
  в их собственных dependencies; DG-RESTERR-03/A из DP-6 также учитывается
  отдельно.
- TEST-02F red evidence остаётся вне `master` до paired green CORE-07 PR.

Tasks:

- TEST-02F + CORE-07 — `completed`, один red-to-green query-meta compatibility
  vertical с сохранённым red evidence и зелёным paired fix.
- CORE-04 — `completed` и влит PR #75 как `7ec7643` после implementation,
  remediation, independent QA и полного verification gate.
- CORE-06R — `completed`: client naming, collision,
  migration-preflight, custom-storage boundaries и утверждённый 1.x multisite
  callback bridge прошли полную verification matrix, independent QA и 17/17
  protected jobs; PR #76 влит как `2371ed2`, post-merge 17/17 jobs зелёные.
- TEST-02D вне Batch 6 переведён в `todo`: его gate dependencies выполнены, но
  red test поставляется paired с REST-02 после готовности DB-02.

Execution model:

- CORE-07/TEST-02F завершены после DP-1; CORE-04 завершён после DP-2 и явно
  утверждённых refinement gates DG-UPDATE-02R/A, DG-UPDATE-04/A и DG-ENT-06/A.
- CORE-06 стартовал после merge/rebase CORE-04, поскольку обе задачи меняют
  client/factory registration boundary.
- Каждый vertical получает отдельный implementation worker, independent QA,
  strict-base rebase и sequential protected merge.

Exit criteria:

- TEST-02F и CORE-07 completed; query-meta autoload и create-with-meta зелёные.
- CORE-04 completed; entity validation, extension и no-mutation matrix зелёная.
- CORE-06R completed; isolation, collision, length, legacy, 1.x callback
  compatibility, multisite и custom storage boundaries зелёные.
- Fixed-floor/full/coverage/PHPCS и применимые compatibility lanes зелёные;
  каждый merge имеет independent QA и 17/17 required checks.
- Readiness sweep определяет Batch 7 без неявного принятия оставшихся gates
  DP-4—DP-9.

Verification:

- TEST-02F + CORE-07: production/test commit `5ea31e8` rebased на `master`
  `c846e23`; ранее зафиксированный red-only commit `3f50f77` сохраняет оба
  ожидаемых missing-trait fatal до fix.
- Explicit floor PHP 8.1.34 / WordPress 6.7.7 / Ramsey Collection 1.3.0:
  unit `7 / 19`, integration `68 / 353`; combined coverage `75 / 372`, PR gate
  `589/790 (74.56%)`, RC threshold ready.
- Clean Compose lane PHP 8.1.34 / WordPress 7.1.0 / Ramsey Collection 1.3.0:
  unit `7 / 19`, integration `68 / 353`. PHPCS production: `36/36`, exit 0.
- CORE-04 red-first commit `36bf8fd`: targeted integration `14 / 20`,
  `12 failures + 1 error` against unchanged production. Green commits:
  `374dabf` plus review remediation `38e1b48`; decision/docs commit `8efe079`.
- CORE-04 fixed floor PHP 8.1.34 / WordPress 6.7.7 / Ramsey 1.3.0: unit
  `12 / 58`, integration `93 / 606`; focused query presence `5 / 39`, focused
  entity validation `25 / 253`; PHPCS production `45/45`, exit 0.
- CORE-04 combined coverage: `105 / 664`, current `817/951 (85.91%)`; exact PR
  baseline `365/786 (46.44%)` unchanged, PR and explicit RC gates pass, active
  exception registry empty.
- Isolation seed `20260911`: reverse and seeded-random repeat-2 pass immediately,
  unit `24 / 116` and integration `186 / 1212` in each phase. Compatibility:
  all ten PHP 8.1.34—8.5.10 / Ramsey 1.3.0 and 2.1.1 unit lanes pass `12 / 58`;
  all five blocking PHP/WP/Ramsey integration pairs pass `93 / 606`. Existing
  dependency/WordPress dynamic-property deprecations on newer PHP are warnings,
  not test failures.
- CORE-06 red-first: `e3505f4` дал targeted `4 / 14 / 4 failures`, расширенный
  test-only `2f47c89` — `8 / 38 / 7 failures` на неизменённом production.
  Runtime implementation находится в `95f0085`, actual multisite regression —
  в `f87cc99`, independent QA remediation — в `cb32976`.
- CORE-06 pre-DG-NAME-06R focused PHP 8.1.34 / WordPress 7.1.0 / Ramsey 1.3.0:
  `ClientIsolationTest` — `11 / 128`; full unit `12 / 58`, integration
  `104 / 734`; PHPCS `45/45`. Реальный `WP_MULTISITE=1` switch/fresh-client
  lane — `1 / 11`.
- CORE-06 fixed-floor coverage PHP 8.1.34 / WordPress 6.7.7 / Ramsey 1.3.0:
  combined `116 / 792`, current `951/1053 (90.31%)`; exact PR baseline
  `365/786 (46.44%)` не изменён, PR gate зелёный и RC threshold ready.
  Seed `20260911`: unit reverse/random repeat-2 `24 / 116`, integration
  reverse/random repeat-2 `208 / 1468`, без retry. Newest compatibility PHP
  8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1: integration `104 / 734`; известные
  dependency/dynamic-property deprecations не являются failures. Эти counts
  являются предыдущим checkpoint evidence и будут заменены результатами
  полной CORE-06R повторной проверки до перевода задачи в `completed`.
- CORE-06R current verification: PHP 8.1.34 / WordPress 7.1.0 / Ramsey 1.3.0
  focused `ClientIsolationTest` `13 / 135`, unit `12 / 58`, integration
  `106 / 741`; PHP 8.1.34 / WordPress 6.7.7 full unit `12 / 58` and integration
  `106 / 741`. True WordPress multisite bootstrap focused lane is `13 / 144`.
  PHPCS is `45/45`; isolation seed `20260911` passes unit reverse/random
  repeat-2 at `24 / 116` and integration at `212 / 1482`. Fixed-floor PR and
  RC coverage both pass at combined `118 / 799`, current `957/1059 (90.37%)`,
  exact baseline `365/786 (46.44%)`, with zero active test exceptions. Newest
  PHP 8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1 integration passes `106 / 741`
  with only the already known dependency/dynamic-property deprecations.
- CORE-06R independent QA PASS; final PR head `93d09c6` and post-merge master
  `2371ed2` each passed all 17 required jobs.

### Batch 7. Semantic 1.x post-deletion lifecycle

Status: completed

Goal: дать consumers стабильный semantic API для управления post-deletion
cleanup до breaking перехода на context-aware manager в 2.0.

Entry criteria:

- CORE-06R завершён и влит PR #76 как `2371ed2`; post-merge 17/17 jobs зелёные.
- DG-NAME-06R staged A-to-D утверждён владельцем.
- Точный 1.x API contract записан в
  [`docs/hook-lifecycle-transition.md`](../hook-lifecycle-transition.md).
- Ни один pending DB/REST/issue #20 gate не требуется для additive lifecycle
  wrapper вокруг существующей callback registration.

Tasks:

- HOOK-TRANS-01 — `completed`; test-first additive
  `enablePostDeletionCleanup()` / `disablePostDeletionCleanup()` vertical.

Execution model:

- Отдельная ветка от merge `2371ed2`; plan/contract, red test, minimal green
  implementation, full verification, independent QA и protected merge.
- Constructor остаётся auto-enabled, а concrete callback identity/priority и
  legacy direct `remove_action()` сохраняются во всей 1.x линии.
- Никакой manager dependency или proxy callback в этот batch не добавляется.

Exit criteria:

- HOOK-TRANS-01 completed по всем AC и verification matrix.
- Public docs дают semantic migration path и выделяют будущий 2.0 break.
- Full/fixed-floor/coverage/PHPCS/isolation и compatibility lanes зелёные;
  independent QA и 17/17 required checks подтверждены.

### Batch 8. Hook manager selection и owned-hook audit

Status: completed

Goal: независимо подготовить решение о поставщике 2.0 manager и полную карту
Client-owned hook registrations, не смешивая discovery с runtime integration.

Entry criteria:

- HOOK-TRANS-01 завершён и влит.
- Current 1.x compatibility surface и 2.0 target contract опубликованы.

Tasks:

- HOOK-00 — `completed`; отдельный build-versus-buy artifact и decision packet
  DG-HOOK-01. После исправления QA-находки final head `345e36d` PR #78 и merge
  `cf8caa6` прошли по 17/17 jobs.
- HOOK-02 — `completed`; отдельный полный hook inventory/context/migration artifact
  и decision packets DG-HOOK-SCOPE-01, DG-HOOK-REST-01—DG-HOOK-REST-04,
  DG-HOOK-LOG-01, DG-HOOK-LIFE-01. Independent QA PASS на content head
  `06b07a7`; record head `d7ab4bd` прошёл все 17 protected jobs PR #79.

Execution model:

- Исследования могут идти параллельно, но поставляются отдельными reviewable PR.
- HOOK-00 не устанавливал dependency. DG-HOOK-01/B и package coordinates
  утверждены после завершения Batch 8; HOOK-01 активирован в Batch 9.
- HOOK-02 не объявляет каждый WordPress hook site-sensitive: для каждого hook
  требуется evidence и отдельное migration action.
- HOOK-02 не меняет runtime и не фиксирует сегодняшние duplicate/leak outcomes
  как желаемые regression contracts; они подтверждены временным probe.

Exit criteria:

- DG-HOOK-01 имеет current primary-source evidence, recommendation и rollback.
- Для каждого Client-owned hook известны owner, callback identity, priority,
  accepted args, context sensitivity, unregister path и 2.0 action.
- REST duplicate/late/dispatch/route-discovery/custom-factory,
  logging/custom-Storage и Client lifetime имеют отдельные decision gates и
  исполняемые задачи; они не скрыты внутри общего HOOK-03.
- HOOK-01 переведён в `in_progress` только после явного DG-HOOK-01/B и
  подтверждения package coordinates; decision-recording PR не устанавливает
  dependency и не меняет runtime.

### Batch 9. Standalone dispatcher и origin-routed logging

Status: active

Goal: сначала поставить independently releasable action dispatcher, затем
отдельным wpConnections PR устранить cross-client automatic debug fanout без
смешивания package delivery и consumer integration.

Entry criteria:

- Batch 8 завершён и влит с independent QA и 17/17 post-merge jobs.
- DG-HOOK-01/B, DG-HOOK-SCOPE-01/A, DG-HOOK-LOG-01/B и DG-SPI-06/A утверждены.
- Package coordinates зафиксированы как `hokoo/wp-hooks-dispatcher` и
  `iTRON\wpHooksDispatcher\`.

Tasks:

- HOOK-01 — `completed`; public MIT package `v1.0.1`, action-only API, PHP
  `^8.1`, no Composer runtime dependencies, contract tests, protected CI,
  independent QA, GitHub Release, Packagist publication и clean install.
- LOG-HOOK-01 — `in_progress`; второй отдельный PR после HOOK-01, хотя manager не
  является его runtime dependency. Он реализует singleton origin routing и
  trailing Client payload по утверждённым gates.

Execution model:

- Decision activation в wpConnections поставляется отдельно от нового package
  и не добавляет Composer dependency.
- HOOK-01 поставлен отдельным repository/package; точная stable версия может
  быть pinned consumer-репозиторием только в runtime integration task.
- LOG-HOOK-01 начат после HOOK-01 как второй batch item и не переносит
  public storage emissions под manager ownership.
- REST-HOOK-01 сохраняет `waiting_dependency` по REST-01; LIFE-HOOK-01 ждёт
  downstream hook/REST/logging tasks, а HOOK-03 — DB-04/DG-DELETE-06.

Exit criteria:

- HOOK-01 выполнен по собственному DoD, прошёл independent QA и опубликован с
  immutable stable tag.
- LOG-HOOK-01 выполнен отдельным wpConnections PR, включая custom Storage,
  same-site, multisite, ordering и `WP_DEBUG` regression coverage.
- План и release hand-offs называют точные package/version boundaries; runtime
  REST/deletion/lifecycle migration не совмещена с этими двумя задачами.

## E1. Test foundation и regression harness

Outcome: integration-тесты изолированы, воспроизводимы и способны надёжно
зафиксировать подтверждённые дефекты до их исправления.

Scope:

- WordPress-aware base test case и fixtures.
- Очистка таблиц/постов между тестами.
- Перенос подтверждённых диагностических probes в штатный suite.
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
  TEST-02A—TEST-02F поставлять red-first vertical slices вместе с
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

Status: completed

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
- Red evidence 2026-09-10 на неизменённом production-коде:
  `docker compose -p wpconnections-core02 run --rm phpunit test:integration
  --filter test_one_to_many_rejects_a_second_from_for_an_occupied_to` —
  ожидаемый fail `1 test / 3 assertions`: relation допустила `B -> X` после
  существующих `A -> X` и `A -> Y`.
- Green evidence после CORE-02 той же isolated-командой: `1 test / 6
  assertions`, passed; `B -> X` отклонён `ConnectionWrongData` code `302`,
  обе разрешённые строки сохранены, success hook для отклонённой операции не
  вызван.

### TEST-02C. Зафиксировать cardinality `m-1` regression

Status: completed

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
- Red evidence 2026-09-10 на неизменённом production-коде:
  `docker compose -p wpconnections-core02 run --rm phpunit test:integration
  --filter test_many_to_one_rejects_a_second_to_for_an_occupied_from` —
  ожидаемый fail `1 test / 3 assertions`: relation допустила `A -> Y` после
  существующих `A -> X` и `B -> X`.
- Green evidence после CORE-02 той же isolated-командой: `1 test / 6
  assertions`, passed; `A -> Y` отклонён `ConnectionWrongData` code `302`,
  обе разрешённые строки сохранены, success hook для отклонённой операции не
  вызван.

### TEST-02D. Зафиксировать REST update без `title`

Status: todo

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
- DG-UPDATE-01 и DG-UPDATE-02 утверждены.
- DG-SPI-01 утвердил форму update payload на domain/storage boundary.

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
- DG-UPDATE-01, DG-UPDATE-02.
- DG-SPI-01.

Notes/Risks:

- Нельзя подменять full-dispatch test прямым вызовом handler.

### TEST-02E. Зафиксировать поиск по `both`

Status: completed

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
- Red evidence 2026-09-10 на неизменённом `WPStorage`: два data-provider cases
  `both=from` и `both=to` завершились `2 failures / 2 assertions`; оба вернули
  `[]` вместо connection ID и сформировали SQL
  `WHERE c.relation = 'relation-0-test' AND ` с MariaDB syntax error.
- Green evidence 2026-09-10 после парного DB-01 fix: тот же filter завершился
  `OK (2 tests, 6 assertions)` на PHP 8.1.34 / WordPress 6.7.7 без
  `wpdb::prepare` warning и с пустым `$wpdb->last_error` для обеих сторон.

### TEST-02F. Зафиксировать `Query\Meta` autoload fatal

Status: completed

Priority: P0

Goal: воспроизвести невозможность материализовать непустой query-meta до
минимального compatibility fix CORE-07.

Scope:

- Unit regression: `Query\Connection->meta->fromArray()` с одной key/value
  создаёт `Query\Meta` и сохраняет round-trip data.
- Integration regression: relation create с одной metadata value сохраняет и
  читает connection/meta через public domain flow.
- Отдельная команда/filter и red transcript на неизменённом production-коде.

Out of Scope:

- Полная duplicate/falsy/replace/delete meta matrix DB-02/REST-05.
- Production fix CORE-07 или изменение REST error mapping.

DoR:

- TEST-01 завершена.
- Read-only spike подтвердил fatal и root cause в PR #26/commit `2b7bacc`.

DoD:

- Оба paths наблюдались красными из-за отсутствующего `IQueryTrait`, а не
  fixture/storage failure.
- Tests зелёные вместе с CORE-07 в одном final vertical PR по утверждённому
  DG-QMETA-01/A.
- Red/green evidence и затронутые critical scenario IDs записаны.

AC:

- Given одна query metadata pair, when она материализуется прямо и через
  create connection, then нет class/trait fatal и key/value читаются обратно.

Dependencies:

- TEST-01.

Notes/Risks:

- DG-QMETA-01/A утверждён; TEST-02F и paired CORE-07 завершены одним зелёным
  vertical и не поставлялись красными отдельно.
- Затронуты `STORE-CREATE-01`, `STORE-META-01`, `REST-CRUD-01` и
  `REST-META-01`; этот узкий test не заменяет полные downstream matrices.
- Red-only commit `3f50f77` содержит только unit/integration regressions и эту
  запись плана; production, fixtures и configuration не изменены.
- Independent QA повторил оба targeted запуска: unit и integration завершаются
  exit `255` с точной причиной `Trait "iTRON\wpConnections\IQueryTrait" not
  found` в `src/Query/Meta.php:9`. Integration успевает успешно поднять
  WordPress/MariaDB, но падает до storage mutation.
- Red-only ветка намеренно не публиковалась. Tests перенесены в paired CORE-07
  vertical на актуальном strict base и подготовлены к поставке одним зелёным
  PR.
- Green evidence на PHP 8.1.34 / WordPress 6.7.7 / Ramsey Collection 1.3.0:
  unit `7 / 19`, integration `68 / 353`; coverage `589/790 (74.56%)`.

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
- Issue #31 закрыт тестами стабильных codes.

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
- Каждый production fix начинается с соответствующего TEST-02A—TEST-02F
  regression и поставляется с ним одним финально зелёным vertical PR.

### CORE-00. Спроектировать entity validation strategy и rollout

Status: completed

Priority: P1

Goal: превратить DG-M1/C и DG-M9/A в implementation-ready contract без
неявного breaking rollout.

Scope:

- Единая validation boundary для connection mutations через
  `Relation::createConnection()`, `Relation::updateConnection()` и
  `Connection::update()`.
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
- REL-00 завершил public consumer/compatibility inventory.

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
- REL-00.

Notes/Risks:

- Strict validation может отклонить legacy data/import flows; rollout должен
  отделять чтение существующих rows от новых mutations.
- Decision-ready artifact: [`docs/entity-validation-contract.md`](../entity-validation-contract.md).
  Он инвентаризирует endpoint-bearing PHP/REST updates и отдельные
  delete/meta-delete/`deleted_post` cleanup paths, отделяет domain validation от
  Storage SPI и задаёт точные `ENT-VAL-01`/`ENT-EXT-01` scenarios для refinement
  CORE-04.
- DG-ENT-01—DG-ENT-06 были утверждены вариантом A владельцем 2026-09-11:
  `WP_Post` lifecycle, typed client-scoped non-post resolver, domain errors/hook
  precedence/revalidation, strict update rollout и immutable relation identity
  теперь являются implementation inputs для активной CORE-04.
- Verification 2026-09-10: source/REL-00/critical-registry traceability и
  relative links проверены; task/gate IDs уникальны; `git diff --check` и
  secrets/out-of-scope diff checks проходят. Docs-only change сохраняет
  подтверждённый `master` baseline: unit `6/14`, integration `39/169`, combined
  `45/183`, statements `574/791 (72.57%)`.

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

Status: completed

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
- Не менять в этой задаче public storage parameter types: mismatch abstract SPI
  и текущего Relation update flow исследует SPI-01; CORE-02 должен валидировать
  cardinality до существующего storage boundary.
- Общий internal guard проверяет create и оба существующих update entrypoint до
  storage mutation, исключая текущий положительный connection ID. Storage SPI,
  REST semantics, database indexes и duplicate/closure precedence не менялись.
- Production и regressions влиты PR #63 (`cd2aca7`); issue #33 было переоткрыто
  с reproduction evidence и автоматически закрыто merge 2026-09-10.

Verification:

- Red-first phase сохранена отдельным commit: isolated `1-m` и `m-1` regressions
  независимо дали ожидаемые failures `1 / 3` на неизменённом production.
- Green focused regressions: `1 / 6` каждый; полная create/update matrix
  `CardinalityTest`: `20 / 142`.
- Critical mapping в `tests/iTRON/wpConnections/WP/CardinalityTest.php`:
  `CARD-1M-01` → `::test_one_to_many_rejects_a_second_from_for_an_occupied_to`
  и create matrix `one to many`; `CARD-M1-01` →
  `::test_many_to_one_rejects_a_second_to_for_an_occupied_from` и create matrix
  `many to one`; `CARD-11-01`/cardinality slice `CARD-MM-01` → create matrix
  `one to one`/`many to many`; `CARD-MUT-01` → оба `::*update*` метода со всеми
  provider cases. Duplicate/closure часть `CARD-MM-01` остаётся в CORE-03
  согласно registry ownership.
- Full default: unit `6 / 14`, integration `59 / 311`.
- Fixed seed `20260910`: reverse/random repeat-2 — unit `12 / 28`, integration
  `118 / 622` в каждой фазе.
- Coverage floor PHP 8.1.34 / WordPress 6.7.7 / Ramsey 1.3.0: combined `65 /
  325`; PR gate `582/791 (73.58%)`, RC threshold ready. PHPCS: `36/36`.
- Blocking integration lanes PHP/WP/Ramsey `8.2.33/7.1.0/1.3.0`,
  `8.3.33/7.1.0/2.1.1`, `8.4.25/6.7.7/2.1.1` и
  `8.5.10/7.1.0/2.1.1`: `59 / 311` в каждой; floor lane покрыта combined
  coverage run. Known pre-existing PHP 8.2+ dynamic-property и PHP 8.4+
  dependency deprecations не являются failures.

### CORE-03. Зафиксировать duplicatable, closurable и error precedence

Status: completed

Priority: P0

Goal: все причины отклонения связи имеют стабильный тип/code и детерминированный
приоритет.

Scope:

- Tests и contracts для codes 301, 302, 303, 304.
- Duplicate check прежде cardinality, regression issue #29.
- Self-connection при `closurable=false/true`.
- Missing endpoints и update connection без ID, включая точные exception
  type/code/message и список действительно отсутствующих параметров.

Out of Scope:

- HTTP status mapping, который выполняется в REST-03.
- Endpoint entity-validation/rollout CORE-04.
- Изменение public API, Storage SPI, signatures или добавление новых error
  semantics сверх утверждённого DG-M3 и существующего `MissingParameters`.

DoR:

- DG-M3 решён.
- CORE-02 определяет cardinality semantics.

DoD:

- Issue #31 закрыт.
- Code/message/type assertions присутствуют для каждой ошибки.
- Сценарий, нарушающий несколько invariants, возвращает утверждённый priority
  error.
- Missing-only endpoint failure перечисляет только реально отсутствующую
  сторону, не мутирует storage и сохраняет `MissingParameters` code `4`.

AC:

- Given duplicate, одновременно нарушающий cardinality, when создаётся связь,
  then domain code равен 303.
- Given запрещённая self-connection, then code равен 301.
- Given `closurable=true`, when создаётся self-connection, then она сохраняется.
- Given cardinality violation без duplicate, then code равен 302.
- Given update объекта без ID, then code равен 304.
- Given отсутствует только `from` или только `to`, when создаётся связь, then
  `MissingParameters` содержит только эту сторону и точное совместимое message.

Dependencies:

- DG-M3.
- CORE-02.

Notes/Risks:

- Messages можно улучшать, но стабильность должна опираться на code/type.
- GitHub evidence 2026-09-10: issue
  [#31](https://github.com/hokoo/wpConnections/issues/31) с требованием tests
  для error codes закрыт merge PR #67; closed issue
  [#29](https://github.com/hokoo/wpConnections/issues/29) описывает ошибочный
  cardinality-before-duplicate result. Исторические commits `6c4f671` и
  `c3b4e70` уже переместили duplicate check первым, поэтому production порядок
  не менялся в CORE-03.
- PR [#67](https://github.com/hokoo/wpConnections/pull/67) merged в
  `b36fa85c62fc5984674a1bdf04b7648ff6065d8d` после 17/17 required checks;
  issue #31 автоматически закрыт 2026-09-10, поэтому DoD подтверждён.
- Red-first commit `b6eab2c`: targeted `ConnectionErrorContractTest` на
  неизменённом production — `8 tests / 30 assertions / 2 failures`; missing-only
  `from` и `to` оба фактически возвращали `Missing required fields: from to `.
  Tests codes `301`—`304`, duplicate-before-cardinality и разрешённого
  self-connection уже были зелёными.
- Минимальный fix собирает только пустые `from`/`to` перед существующим
  `MissingParameters`; exception classes, numeric codes/messages, public
  signatures, HTTP mapping и SPI не менялись.
- Green evidence: targeted `8 / 34`; fixed-floor PHP 8.1.34 / WordPress 6.7.7 /
  Ramsey 1.3.0 — unit `6 / 14`, integration `67 / 345`; isolation seed
  `20260910`, reverse/random repeat-2 — unit `12 / 28`, integration `134 / 690`
  в каждой фазе; combined coverage `73 / 359`, PR gate `588/790 (74.43%)`, RC
  threshold ready; PHPCS production `36/36`.
- Blocking integration lanes PHP/WP/Ramsey `8.2.33/7.1.0/1.3.0`,
  `8.3.33/7.1.0/2.1.1`, `8.4.25/6.7.7/2.1.1` и
  `8.5.10/7.1.0/2.1.1`: `67 / 345` в каждой. Известные pre-existing PHP 8.2+
  dynamic-property и PHP 8.4+ dependency deprecations не являются failures.

### CORE-04. Реализовать endpoint entity validation

Status: completed

Priority: P1

Goal: connection endpoints соответствуют утверждённому WordPress entity/post
type contract.

Scope:

- Реализовать DG-M1 для create и всех поддерживаемых update paths.
- Tests для missing/deleted/wrong-type endpoints.
- Typed client-scoped resolver registry и structured resolution result согласно
  утверждённому DG-ENT-02/A.
- Presence tracking прямых `Query\Connection::$from/$to` writes по
  DG-UPDATE-02R/A и missing/no-op/changed result boundary по DG-UPDATE-04/A.
- Сохранить mutable `relation/creating` transformation hook и условно повторить
  entity/closure/duplicate/cardinality validation по DG-ENT-06/A.
- Провести high-level mutations через общий validation path согласно DG-M9;
  direct storage writes остаются SPI и не являются consumer API.
- Сохранить cleanup boundary: explicit connection/meta deletes и `deleted_post`
  cascade не требуют существования endpoint entity и могут убрать legacy/orphan
  state.

Out of Scope:

- Возврат полных entities через REST.
- Поддержка произвольных entity types без зарегистрированного resolver.

DoR:

- DG-M1 решён.
- DG-M9 решён.
- CORE-00 завершила extension и backward-compatibility/rollout contract.
- DG-SPI-01 и DG-SPI-02 утверждены.
- REST-00B завершена; DG-UPDATE-01, DG-UPDATE-02, DG-UPDATE-02R и
  DG-UPDATE-04 утверждены.
- DG-ENT-01—DG-ENT-06 утверждены владельцем.

DoD:

- Validation единообразна для PHP и REST paths.
- Ошибки имеют стабильный domain contract.
- Документация relation `from`/`to` соответствует реализации.
- Legacy-invalid cleanup остаётся доступен через domain, full-dispatch REST и
  реальный `deleted_post`, без entity resolver.
- Critical scenario mapping, release/preflight notes и red/green/full evidence
  записаны; fixed floor, coverage, PHPCS, isolation и compatibility lanes зелёные.

AC:

- Given relation `page -> post`, when передан `post -> page`, then операция
  отклоняется предсказуемо.
- Given несуществующий ID, then запись в storage не создаётся.
- Given разрешающий extension strategy, then поддерживаемый non-post endpoint
  проходит без изменения core storage.
- Given direct `from=0` или `to=0` sparse assignment, then code `305` возникает
  до adapter write; untouched endpoint остаётся omission с legacy read `0`.
- Given positive update ID отсутствует, then Relation и aggregate entrypoints
  бросают exact `ConnectionNotFound`; existing adapter `true`/`false` остаются
  changed/no-op, а `Connection::update(): void` возвращает normally.
- Given mutable creating hook меняет relation, then code `310` возникает до
  storage; given hook меняет endpoint, then entity и existing invariants
  проверяются повторно; unchanged endpoint state не резолвится дважды.

Dependencies:

- DG-M1.
- DG-M9.
- DG-SPI-01, DG-SPI-02.
- DG-ENT-01—DG-ENT-06.
- CORE-00.
- CORE-03.
- REST-00B.
- DG-UPDATE-01, DG-UPDATE-02, DG-UPDATE-02R, DG-UPDATE-04.

Notes/Risks:

- Строгая проверка может быть breaking для consumers, использующих IDs не из
  `wp_posts`.
- Approved presence implementation меняет только undocumented raw
  `get_object_vars()`/default JSON shape untouched query endpoints; `toArray()`
  и direct reads остаются materialized.
- Entity deletion может произойти между validation и write; DB-04/DB-05 владеют
  cascade/transaction race, CORE-04 не заявляет cross-table atomicity.

Verification:

- Red-first commit `36bf8fd` дал targeted integration `14 tests / 20 assertions /
  12 failures + 1 error` только на ещё отсутствующем CORE-04 поведении. Production
  реализован в `374dabf`, review gaps закрыты `38e1b48`, решения и contracts
  синхронизированы в `8efe079`.
- Focused: `ConnectionQueryPresenceTest` — `5 / 39`; `EntityValidationTest` —
  `25 / 253`. Fixed floor: unit `12 / 58`, integration `93 / 606`.
- Combined coverage `105 / 664`, current `817/951 (85.91%)`; exact PR ratio
  `365/786 (46.44%)` не изменён, PR и RC gates зелёные, active exceptions `0`.
  PHPCS production: `45/45`, exit 0.
- Seed `20260911`: unit reverse/random repeat-2 `24 / 116`, integration
  reverse/random repeat-2 `186 / 1212`, без retry. Unit compatibility зелёная
  во всех 10 PHP 8.1.34—8.5.10 × Ramsey 1.3.0/2.1.1 lanes; integration зелёная
  во всех пяти blocking PHP/WP/Ramsey pairs (`93 / 606` в каждой).
- `ENT-VAL-01`: endpoint order/types, sparse/full update, zero-presence,
  missing-ID, hook revalidation, REST create/update/meta и cleanup assertions
  находятся в `EntityValidationTest`. `ENT-EXT-01`: structured outcomes,
  unsupported/throwing resolver, registry lifecycle/client isolation и
  domain-owned storage payload проверены там же.
- `ERR-CODE-01` scoped evidence проверяет exact leaf exception classes 305—310
  и previous exception chain; `CARD-MUT-01`/`HOOK-CONTRACT-01` scoped evidence
  проверяет повторную closure/duplicate/cardinality validation и отсутствие
  write/created hook при reject. Полное закрытие broad release scenarios остаётся
  за их владельцами и не заявляется этим vertical.
- Existing PHP/WordPress/dependency deprecations записаны отдельно от зелёного
  результата. Protected CI/merge и независимая QA ещё обязательны.

### CORE-05. Зафиксировать client naming и migration contract

Status: completed

Priority: P1

Goal: определить canonical client identifier, table-name mapping и legacy
migration до изменения production naming behavior.

Scope:

- Применить REL-00 inventory к collision examples и существующим identifiers.
- Canonical alphabet/case, empty-name и maximum-byte-length rules с учётом
  `$wpdb->prefix` и подтверждённого MySQL/MariaDB identifier limit.
- Поведение имён, нормализующихся в одинаковый table postfix.
- Backward-compatible handling/migration для legacy underscores и иных имён.
- Decision-ready contract и test matrix для CORE-06/DB-06.
- Двухфазная boundary: adapter-neutral logical validation до factory;
  `WPStorage` physical length/collision/ownership/site checks после выбора
  adapter, но до table registration/DDL/DML. Custom non-table adapters не
  получают SQL-table requirements.

Out of Scope:

- Production validation/table-name changes.
- Shared-table migration; утверждённый DG-M6 сохраняет table-per-client.

DoR:

- DG-M6 решён.
- REL-00 завершил inventory существующих client names.

DoD:

- Для каждого raw/canonical/colliding/overlong case определён result/error и
  migration consequence.
- Material compatibility/migration choices оформлены как human decision gates.
- Naming/migration часть CORE-06 и DB-06 является execution-ready после
  решений; их остальные явно перечисленные dependencies сохраняются.

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

Coordination inputs:

- DB-00 [PR #68](https://github.com/hokoo/wpConnections/pull/68), merge
  `d1750731d7e94f4e3349600431a33bf6954d3106`, и canonical
  [`docs/db-compatibility-contract.md`](../db-compatibility-contract.md)
  подтверждают exact MySQL 8.0.46/MariaDB 10.11.16 probes и 64-character limit
  полного table identifier; это evidence, а не утверждение DG-DB-01—DG-DB-04.
- DG-SPI-07/A утверждён владельцем 2026-09-11 и сохраняет v1 concrete table
  introspection как legacy compatibility surface с migration documentation.

Notes/Risks:

- Любое изменение table postfix может потребовать migration существующих tables.
- Evidence: [`docs/client-naming-contract.md`](../client-naming-contract.md)
  разделяет raw/logical/postfix/physical identities, фиксирует обе table-prefix
  formulas, two-phase adapter boundary, observed public mappings,
  collision/empty/unsafe/overlong/multisite matrix и non-destructive legacy
  adoption/copy/dual-read alternatives.
- DG-NAME-01—DG-NAME-06 утверждены вариантом A владельцем 2026-09-11;
  production/schema/API changes выполняются только в CORE-06 и её downstream
  tasks.
- Fixed-floor PHP 8.1.34 / WordPress 6.7.7 / Ramsey 1.3.0: unit `6 / 14`,
  integration `67 / 345`, PHPCS `36 / 36`; structural links/anchors/gate-shape,
  duplicate-ID, secret и `git diff --check` проверки зелёные.

### CORE-06. Реализовать multi-client isolation и table-name rules

Status: completed

Priority: P1

Goal: обещанная README изоляция клиентов сохраняется для всех утверждённых имён
и не допускает silent normalized collisions.

Scope:

- Реализовать утверждённый CORE-05 canonical/migration contract.
- Tests двух клиентов с разными relations/data.
- Collision, empty, length и legacy compatibility scenarios.
- Реализовать DG-NAME-06R 1.x bridge без изменения concrete `deleted_post`
  callback identity и priority.
- Документировать фактический table naming.

Out of Scope:

- Shared-table migration.
- Любая naming policy вне утверждённых DG-NAME-01—DG-NAME-06/A.
- Переходный semantic cleanup API, context-aware manager, полный аудит
  Client-owned hooks и 2.0 migration; это отдельный post-merge plan.

DoR:

- CORE-04 завершена и смержена; branch rebased на её результат.
- CORE-05 завершён.
- TEST-01 завершена.
- DG-NAME-01, DG-NAME-02, DG-NAME-03, DG-NAME-04, DG-NAME-05 и DG-NAME-06
  утверждены владельцем.
- DG-NAME-06R staged A-to-D transition утверждён владельцем; CORE-06 реализует
  только совместимый 1.x bridge.
- DG-SPI-07 утверждён для concrete `WPStorage` introspection/migration surface.

DoD:

- Разные допустимые client names не разделяют данные неожиданно.
- Collision/empty/overlong inputs дают утверждённый result/error до опасного SQL.
- Legacy compatibility/migration tests и документация соответствуют contract.
- Direct stale storage access отклоняется, inactive-site `deleted_post` delivery
  завершается до storage hooks/SQL, а применимый fresh-site callback выполняет
  cleanup без cross-site удаления.
- Concrete 1.x callback identity, priority и removability сохранены.

AC:

- Given два допустимых разных клиента, when каждый создаёт и удаляет связь, then
  данные другого не читаются и не изменяются.
- Given коллидирующие normalized names, then система не молча использует одну
  table pair как два разных client identity.
- Given client из другого site context и реальный `wp_delete_post()`, when fresh
  client создан до события, then stale callback не испускает storage hooks и не
  блокирует cleanup текущего сайта, а исходные данные сохраняются.
- Given 1.x consumer снимает `[$client->getStorage(), 'deleteByObjectID']` с
  `deleted_post` на priority 10, then callback успешно удаляется.

Dependencies:

- CORE-04.
- CORE-05.
- TEST-01.
- DG-NAME-01, DG-NAME-02, DG-NAME-03, DG-NAME-04, DG-NAME-05, DG-NAME-06.
- DG-NAME-06R.
- DG-SPI-07.

Notes/Risks:

- Любая table rename/copy операция требует отдельного destructive migration
  review и rollback; она не подразумевается этой задачей автоматически.
- Red-first evidence 2026-09-11: test-only `ClientIsolationTest` на неизменённом
  production завершился `4 tests / 14 assertions / 4 failures`. Current code
  принял unsafe logical input, silent hyphen/underscore collision и 65-character
  physical identifier, а reused storage после prefix change дошёл до SQL
  `alternate_post_connections_site_bound` вместо утверждённого
  `ClientRegisterFail`; custom-storage logical rejection также отсутствовала.
- Расширенный test-only commit `2f47c89` дал `8 tests / 38 assertions /
  7 failures`, отдельно подтвердив отсутствие ownership record, legacy
  attestation и long-prefix boundary. Production `95f0085`, multisite regression
  `f87cc99` и QA remediation `cb32976` закрыли эти failures без изменения
  public Storage SPI или REST wire contract.
- Pre-DG-NAME-06R green checkpoint: focused `11 / 128`; full PHP 8.1.34 / WordPress 7.1.0 /
  Ramsey 1.3.0 — unit `12 / 58`, integration `104 / 734`; fixed-floor coverage
  combined `116 / 792`, `951/1053 (90.31%)`, baseline `365/786` unchanged;
  PHPCS `45/45`; isolation seed `20260911` — unit `24 / 116`, integration
  `208 / 1468` в каждом reverse/random repeat-2 phase; actual multisite
  `1 / 11`; newest integration PHP 8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1 —
  `104 / 734` с только известными deprecation warnings. Обновлённые counts и
  independent QA фиксируются до перевода этой задачи в `completed`.
- Final DG-NAME-06R verification: focused `13 / 135`; true multisite
  `13 / 144`; full PHP 8.1.34 with WordPress 7.1.0 and fixed-floor WordPress
  6.7.7 — unit `12 / 58`, integration `106 / 741`; PHPCS `45/45`; isolation
  seed `20260911` — unit `24 / 116`, integration `212 / 1482` in every
  reverse/random repeat-2 phase. Fixed-floor PR and RC coverage pass at
  combined `118 / 799`, `957/1059 (90.37%)`, baseline `365/786` unchanged and
  zero active exceptions. Newest PHP 8.5.10 / WordPress 7.1.0 / Ramsey 2.1.1
  integration is `106 / 741` with only known deprecations. Independent QA
  independently repeated focused single-site `13 / 135`, true multisite
  `13 / 144`, full unit `12 / 58`, integration `106 / 741` and PHPCS `45/45`
  with PASS. All 17 protected jobs of PR #76 passed on `ef69d31`; merge remains
  the Batch 6 delivery gate.
- Fresh mappings получают atomic non-autoloaded site-local claim. Complete
  unowned, partial, malformed или conflicting mappings не исправляются
  автоматически. Operator-facing dry-run/attestation остаётся за DB-06/REL-03;
  один CORE-06 vertical не является самостоятельным release approval для
  существующих unclaimed installations.
- В 1.x bridge `current_filter() === 'deleted_post'` является единственным
  доступным признаком cascade delivery при сохранённой callback identity.
  Поэтому manual stale вызов изнутри другого `deleted_post` callback также
  fail-closed возвращает `0`; точное subscription routing принадлежит 2.0.

### CORE-07. Восстановить материализацию `Query\Meta`

Status: completed

Priority: P0

Goal: устранить подтверждённый fatal, не возвращая молча удалённую query-update
семантику.

Scope:

- Реализовать утверждённый DG-QMETA-01 compatibility path.
- Сохранить public class name, наследование от `Abstracts\Meta`, `GSInterface`
  и `Query\MetaCollection::$collectionType`.
- Удалить безопасный мёртвый `IQuery` import из `ClientRestApi` согласно
  утверждённому DG-QMETA-01/A.
- Поставить TEST-02F unit/integration regressions тем же vertical PR.

Out of Scope:

- Изменение storage, REST handlers/error mapping или meta semantics.
- Полная DB-02/REST-05 matrix.
- Восстановление `isUpdate`, исключённое утверждённым DG-QMETA-01/A.

DoR:

- DG-QMETA-01 утверждён владельцем.
- TEST-02F завершена с red/green evidence в том же vertical batch.

DoD:

- `Query\Meta` и непустой `Query\MetaCollection` autoload без fatal.
- Create connection с одной metadata pair сохраняет и читает её обратно.
- Public surface соответствует выбранному gate; unrelated meta behavior не
  изменён.

AC:

- Given `new Query\Meta('key', 'value')`, when Composer autoload объявляет
  класс, then объект создаётся без missing interface/trait fatal.
- Given connection query с одной metadata pair, when relation создаёт и читает
  connection, then пара сохранена без изменения handler/storage contracts.

Dependencies:

- DG-QMETA-01.
- TEST-02F (`completed` в paired vertical batch).

Notes/Risks:

- Git tags отсутствуют; public exposure в tagged release не доказан. Private
  usage старых `isUpdate()`/`setIsUpdate()` остаётся residual compatibility
  risk; он принят утверждённым DG-QMETA-01/A.
- DB-02 зависит от CORE-07, чтобы широкая meta matrix не маскировала class-load
  defect локальными fixture workarounds.
- Green evidence на PHP 8.1.34 / WordPress 6.7.7 / Ramsey Collection 1.3.0:
  unit `7 / 19`, integration `68 / 353`; coverage `589/790 (74.56%)`; PHPCS
  production `36/36`.

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

Status: completed

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
- Canonical artifact: [`docs/storage-spi-contract.md`](../storage-spi-contract.md),
  source snapshot `3f8bc3918fb0eea7888b071a5d7402335b8335ff`.
- Formal PHP type у abstract/default `updateConnection()` совпадает, но public
  callers создают semantic mismatch: `Relation` передаёт допустимый subtype
  `Query\Connection` с uninitialized patch fields, а `WPStorage` читает full
  replacement. Решение утверждено как DG-SPI-01/A; CORE-02 его не исправляет,
  implementation принадлежит CORE-04/DB-02/REST-02.
- Shared changed/no-op/not-found/storage-failure decision принадлежит
  REST-00B как `DG-UPDATE-04`; SPI artifact только связывает conformance с этим
  gate и не дублирует решение.
- DG-SPI-01, DG-SPI-02 и DG-SPI-07 утверждены вариантом A владельцем
  2026-09-11; DG-SPI-06/A утверждён отдельно, DG-SPI-03—DG-SPI-05 остаются
  pending. Production tasks меняют
  статус только после выполнения остальных explicit dependencies.

Verification:

- Source traceability охватывает 8/8 abstract operations и все соответствующие
  `WPStorage` methods, Factory construction, direct domain callers и hooks.
- Structural checks подтверждают полный gate shape, task attributes и валидные
  relative repository links; `git diff --check` проходит.
- Baseline PHP 8.1.34 / WordPress 6.7.7: unit `6 / 14`, integration `39 / 169`;
  PHPCS `35/35`, exit 0 с известным ruleset deprecation warning. SPI-01 не
  изменяет production, signatures или tests.

### DB-00. Исследовать DB compatibility и transaction capabilities

Status: completed

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
- Canonical artifact:
  [`docs/db-compatibility-contract.md`](../db-compatibility-contract.md), source
  snapshot `0db202e7d4a794fd21d82d5305f51f40cb583b92`.
- Текущий CI использует unpinned MariaDB из Debian внутри каждого PHP image, а
  local-dev — floating `mysql:8`; это не является reproducible DB matrix.
- Probes MySQL 8.0.46 и MariaDB 10.11.16 подтвердили InnoDB rollback/savepoints,
  отсутствие rollback у MyISAM, implicit commit при втором `START TRANSACTION`
  и DDL, наследование session default engine и общий 64-character table-name
  limit. MySQL также не поддержал MariaDB-specific `@@in_transaction`.
- DG-DB-01—DG-DB-04 остаются pending. Completion означает decision-ready
  feasibility artifact; schema, CI, engine и production transaction behavior не
  изменены.

Verification:

- Exact probe images/digests, последовательность SQL и observed result matrix
  записаны в canonical artifact; disposable containers остановлены и удалены.
- Repository inventory охватывает local Compose, все protected integration и
  coverage workflows, table install/retry paths и все multi-statement storage
  mutations.
- Official WordPress, MySQL, MariaDB и dbDelta sources связаны непосредственно
  с каждым compatibility conclusion; `git diff --check` проходит.

### DB-01. Исправить поиск по `both` и покрыть query matrix

Status: completed

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
- Ordering намеренно остаётся unspecified до решения DG-API20-04: DB-01 не
  добавляет глобальный `ORDER BY`, а identity/multiplicity и repeated-meta
  assertions canonicalized и не зависят от порядка строк.
- Green evidence 2026-09-10: DB-01 class — `8 tests / 48 assertions`; reverse
  order с `--repeat=2` — `16 / 96`; полный `test:all` — unit `4 / 7` и
  integration `18 / 117`; полный integration reverse/repeat — `36 / 234`.
- Compatibility evidence: тот же DB-01 class на PHP 8.2.33 / WordPress 7.1.0 —
  `8 / 48`; вывод содержит только уже известную dynamic-property deprecation в
  `GSInterface.php`, не относящуюся к DB-01.
- Quality evidence: fresh-image `test:coverage` — `22 tests / 124 assertions`,
  gate passed с `555/790 (70.25%)` против baseline `365/786 (46.44%)`;
  `cs:phpcs` — `35/35`, exit 0, с известным ruleset deprecation warning.

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
- CORE-07 устранил `Query\Meta` materialization fatal.
- CORE-02 определяет update invariants.
- CORE-04 завершил domain entity validation перед storage mutation.
- DG-UPDATE-01—DG-UPDATE-04 утверждены.
- DG-SPI-01 и DG-SPI-02 утвердили update payload и create ID/hydration
  ownership.
- DG-ENT-04 и DG-ENT-05 утвердили effective update-state/rollout policy и
  неизменяемую owning relation.

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
- CORE-07.
- CORE-02 для update endpoint invariants.
- CORE-04.
- REST-00B.
- DG-UPDATE-01, DG-UPDATE-02, DG-UPDATE-03, DG-UPDATE-04.
- DG-SPI-01, DG-SPI-02.
- DG-ENT-04, DG-ENT-05.

Notes/Risks:

- Таблица объявляет `meta_value NOT NULL`, тогда как object model допускает null;
  контракт нужно зафиксировать тестом и при необходимости schema change.

### DB-03A. Зафиксировать delete result и failure contract

Status: completed

Priority: P0

Goal: определить rows-affected, not-found, invalid-input и partial-failure
semantics до тестирования/refactor всех delete variants.

Scope:

- Инвентаризация Abstract Storage, WPStorage, Relation, Connection, REST и
  `deleted_post` delete paths, SQL, metadata cascade и hooks.
- Result semantics для single/multiple IDs, directed pair, object side/direction,
  relation scope, duplicates, partial match и valid no-match.
- Invalid/empty/mixed identifiers, ambiguous domain selectors, conflicting
  direction flags и exact-vs-pattern relation filter.
- Logical affected-connection count отдельно от metadata/physical row counts.
- Partial SQL failures и атомарная connection-plus-meta boundary approved DG-M7;
  generic adapter failure/capability/hook policy остаются DG-SPI-03/04/06.
- Decision-ready matrix для umbrella DB-03B (после split DB-03B-A/DB-03B-B),
  DB-04, REST-00A/REST-03 и REL-02.

Out of Scope:

- Production delete changes.
- Transactions DB-05.
- Реализация WordPress `deleted_post` cascade/recovery DB-04.
- Metadata update/delete value semantics DB-02/REST-05.
- Утверждение public result, REST, SPI, hook или recovery решений.

DoR:

- DG-M3 и DG-M7 решены.
- SPI-01 завершил generic storage result/failure/capability/hook inventory.

DoD:

- Canonical artifact отделяет observed behavior от conditional recommended
  contract для каждого delete variant и public surface.
- Single/multiple/directed/object-side/REST/cascade matrix определяет все
  decision points для success, no-match, invalid input, count и failure.
- Partial failure/atomicity requirements согласованы с approved DG-M7 и pending
  DG-SPI-03/04/06 без дублирования generic SPI решений.
- Material public compatibility choices оформлены как полноценные pending human
  decision gates; downstream production остаётся waiting.

AC:

- Given no matching row, invalid ID или conflicting flags, when читается matrix,
  then observed behavior, alternatives и recommended caller result/error
  определены отдельно для каждого случая без неявного утверждения.
- Given несколько matching connections, then contract определяет, что именно
  считает логический affected count для stored duplicates, duplicate input IDs,
  self-connections и metadata multiplicity.
- Given relation-scoped PHP/REST delete by ID, then cross-relation behavior и
  direct client-wide SPI compatibility представлены отдельным gate.
- Given failure между selector read, meta delete и connection delete, then
  contract требует attributable error и approved DG-M7 atomic outcome, а не
  misleading `0`/success.
- Given реальный `deleted_post`, then contract отделяет атомарность connection/meta
  cleanup от уже завершённого WordPress post deletion и передаёт recovery DB-04.

Dependencies:

- DG-M3, DG-M7.
- SPI-01 для ownership/cross-reference DG-SPI-03, DG-SPI-04 и DG-SPI-06.

Notes/Risks:

- Текущее `$wpdb->rows_affected` после второго SQL statement не обязательно
  отражает количество логически удалённых connections.
- Canonical artifact:
  [`docs/delete-result-contract.md`](../delete-result-contract.md), source
  snapshot `0db202e7d4a794fd21d82d5305f51f40cb583b92`.
- Temporary fixed-floor probe подтвердил cross-relation ID deletion `1`, mixed
  `[valid, invalid]` partial acceptance `1`, missing ID `0`, conflicting flags
  `0` и attempt-only hook; probe не входит в repository tests.
- DG-DELETE-01—DG-DELETE-06 остаются pending. Completion означает готовность
  design artifact к owner decision, а не утверждение production/API/SPI/REST или
  hook changes. DB-03B-A, DB-03B-B, DB-04 и REST-03 сохраняют
  `waiting_dependency`.
- Public inventory нашёл direct
  `getStorage()->deleteSpecificConnections()` consumer для orphan cleanup;
  private consumers/hooks остаются неизвестным compatibility risk.

Verification:

- Source/history traceability охватывает 3/3 connection-delete SPI methods,
  Relation dispatch, отсутствие Connection delete API, оба REST DELETE paths,
  metadata cascade, `deleted_post` registration и все delete hook families.
- Structural checks подтверждают шесть полных pending gate definitions, registry
  anchors, обязательные task attributes и валидные relative repository links.
- Post-rebase integrity на `b36fa85`: fixed-floor PHP 8.1.34 / WordPress 6.7.7 /
  Ramsey 1.3.0: unit `6 / 14`, integration `67 / 345`; PHPCS `36/36`, exit 0
  с известным ruleset deprecation warning. Docs-only diff не меняет
  production/tests.

### DB-03B-A. Покрыть delete selectors и successful meta cascade

Status: waiting_dependency

Priority: P0

Goal: успешное удаление по ID, endpoints, direction и relation выбирает ровно
нужные connections, возвращает утверждённый logical count и удаляет всю
связанную meta.

Scope:

- `deleteSpecificConnections` для одного/нескольких IDs.
- `deleteByObjectID` для both/onlyFrom/onlyTo и relation filter.
- `deleteDirectedConnections`, duplicate rows и not-found.
- Все branches `Relation::detachConnections`.
- Invalid IDs и обе direction flags одновременно.
- Успешная connection-plus-meta cascade для каждого selector variant.

Out of Scope:

- Автоматический `deleted_post` hook — DB-04.
- Fault injection, rollback и success-hook timing — DB-03B-B после DB-05.
- Transaction implementation — DB-05.

DoR:

- TEST-01 обеспечивает isolation.
- DB-03A contract утверждён.
- DG-DELETE-01, DG-DELETE-02, DG-DELETE-03 и DG-DELETE-04 утверждены.
- DG-NAME-03 утверждён для physical collision ownership.
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
- DG-DELETE-01, DG-DELETE-02, DG-DELETE-03, DG-DELETE-04.
- DG-NAME-03.
- CORE-06 для cross-client assertions.

Notes/Risks:

- Delete PR должен иметь отдельный review и не смешиваться с schema changes.
- Историческое Batch-5/readiness evidence до split использует umbrella ID
  `DB-03B`; текущие canonical artifacts и task references разделены явно:
  selector/count/normalization/successful-cascade относятся к DB-03B-A, а
  atomic failure и commit-hook conformance — к DB-03B-B.

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

- DB-03B-A и DB-03B-B завершены.
- DB-05 завершила reusable atomic mutation boundary.
- DG-M1 определяет поддерживаемые entities.
- DG-DELETE-04 и DG-DELETE-06 утверждены.
- DG-NAME-06 утверждён для site-prefix lifecycle callback.

DoD:

- Cascade integration tests зелёные.
- Нет orphan meta.
- Hook не регистрируется многократно между tests/clients неожиданным образом.

AC:

- Given post участвует в нескольких relations, when он удалён, then все его
  connections текущего клиента и meta удалены.
- Given другой клиент использует другой endpoint, then его данные не затронуты.

Dependencies:

- DB-03B-A, DB-03B-B.
- DB-05.
- DG-M1.
- DG-DELETE-04, DG-DELETE-06.
- DG-NAME-06.

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
- DG-UPDATE-03 и DG-UPDATE-04 решены.
- DG-SPI-02, DG-SPI-03, DG-SPI-04 и DG-SPI-06 решены.
- DG-DB-01, DG-DB-02, DG-DB-03 и DG-DB-04 решены.
- SPI-01 и DB-00 завершены, owner утвердил возникающие DB/migration gates.
- DB-02 и DB-03B-A задают корректные success semantics.

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
- DG-UPDATE-03, DG-UPDATE-04.
- DG-SPI-02, DG-SPI-03, DG-SPI-04, DG-SPI-06.
- DG-DB-01, DG-DB-02, DG-DB-03, DG-DB-04.
- SPI-01, DB-00.
- DB-02, DB-03B-A.

Notes/Risks:

- Таблицы и engine должны реально поддерживать выбранную transaction semantics.

### DB-03B-B. Проверить delete failure и commit-hook conformance

Status: waiting_dependency

Priority: P0

Goal: injected failure в любом delete variant не оставляет partial state, не
маскируется под valid no-match и не публикует ложный success hook.

Scope:

- Fault injection для ID, directed-pair и object-side deletion.
- Rollback connection и metadata rows на каждом failure point.
- Различение SPI failure, invalid input и valid no-match.
- Attempt/success hook timing относительно atomic commit.

Out of Scope:

- Реализация reusable transaction primitive — DB-05.
- REST error serialization — REST-03.
- WordPress `deleted_post` recovery после уже завершённого post deletion — DB-04.

DoR:

- DB-03B-A и DB-05 завершены.
- DG-SPI-03, DG-SPI-04 и DG-SPI-06 утверждены.
- DG-DELETE-02 и DG-DELETE-03 утверждены.

DoD:

- Каждый selector variant имеет fault-injection regression на каждом
  connection/meta boundary.
- Исходное состояние полностью восстановлено либо возвращён утверждённый
  attributable failure без partial success.
- Success-named hooks испускаются только после успешного commit согласно
  утверждённому SPI contract.

AC:

- Given meta delete failure, when удаляется connection, then connection и все
  исходные meta остаются, а caller получает стабильную failure category.
- Given connection delete failure после промежуточного шага, then операция не
  возвращает `0`/success и не вызывает success hook.
- Given valid selector без matches, then результат отличается от injected
  adapter failure.

Dependencies:

- TEST-01.
- DB-03B-A, DB-05.
- DG-SPI-03, DG-SPI-04, DG-SPI-06.
- DG-DELETE-02, DG-DELETE-03.

Notes/Risks:

- Выполняется отдельным PR после DB-05; так dependency graph не содержит цикла
  между определением success semantics и reusable atomic implementation.

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
- DG-DB-01, DG-DB-02 и DG-DB-04 утверждены.
- CORE-06 реализовал и проверил table naming rules.
- DG-NAME-01, DG-NAME-03, DG-NAME-04, DG-NAME-05 и DG-NAME-06 утверждены
  владельцем. DG-NAME-02 реализован в CORE-06 и является refinement для
  schema-side no-registration/no-DDL assertions, а не отдельным direct gate.

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
- DG-DB-01, DG-DB-02, DG-DB-04.
- DG-NAME-01, DG-NAME-03, DG-NAME-04, DG-NAME-05, DG-NAME-06.
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

Status: completed

Priority: P0

Goal: превратить DG-M3/A в полную таблицу стабильных v1 error responses до
REST-03 и OpenAPI.

Scope:

- Инвентаризация всех domain exceptions/codes и их фактической
  request-reachability, включая 301—304, not-found и bootstrap-only failures.
- Current-state matrix полного `WP_REST_Server::dispatch()` для native
  validation/routing, permission, invariant, not-found, storage и unknown
  failures.
- Decision-ready HTTP taxonomy и backward-compatible v1 body/serialization
  alternatives для REST-03/REST-04/REST-05.
- Downstream test matrix и явная coordination boundary с entity, storage и
  update contracts.

Out of Scope:

- Реализация handlers или REST-03 tests.
- Замена numeric domain codes строковыми identifiers.
- Утверждение HTTP/body choices, entity-error taxonomy, storage result protocol
  или update/meta success semantics.

DoR:

- DG-M3 и DG-M4 решены.
- REST-01 предоставляет изолированный full-dispatch harness.

DoD:

- Каждая известная domain error и pre-handler/failure family отражена в
  current-state matrix; proposed body/status mapping вынесен в pending gates.
- Numeric 301—304 нигде не трактуются как redirect statuses.
- Material mapping choices имеют problem, alternatives, recommendation,
  compatibility impact и blocked tasks для owner approval.
- Canonical artifact связывает entity, SPI и update gate ownership, не
  предрешая его.

AC:

- Given invariant code 301—304, when owner оценивает mapping alternatives, then
  recommendation однозначно задаёт независимый 4xx status, сохраняет numeric
  domain code в body и запрещает redirect interpretation.
- Given unknown/storage failure, then recommendation не выдаёт misleading
  success/not-found, не раскрывает database detail и сохраняет причину в
  server-side diagnostics.
- Given WordPress rejects route, args or permission before handler, then native
  status/body evidence и compatibility choice зафиксированы отдельно от
  library domain mapping.

Dependencies:

- DG-M3, DG-M4.
- REST-01.

Notes/Risks:

- Canonical artifact: [REST v1 error contract discovery](../rest-error-contract.md).
- DG-RESTERR-03/A утверждён 2026-09-11; DG-RESTERR-01/02/04 остаются pending.
  Completion REST-00A означает готовность discovery/decision package, а не
  неявное утверждение остальных recommendation A.
- Future entity rows consume approved DG-ENT-03/A from the merged CORE-00 contract;
  storage mapping consumes DG-SPI-03; mutation success/no-op classification
  consumes DG-UPDATE-04 and DG-UPDATE-05. REST-00A не дублирует их ownership.
- Full-dispatch probe on source snapshot `752362b` recorded WordPress native
  400/401/403/404, current library HTTP 500
  with numeric codes `1`, `2`, `4`, `300`—`303`, raw database disclosure,
  false-success/failure masking and escaped non-library `Throwable`. Temporary
  probe (`test:integration --filter
  test_records_current_full_dispatch_errors`: 1 test, 1 assertion) was not
  retained. Post-rebase source audit at `b36fa85` includes CORE-03 and confirms
  the same reachability boundaries plus read-failure-as-empty. The current REST
  meta update handler reaches `Connection::update()` after
  `findConnections()->first()`, but default `WPStorage` hydrates a non-empty
  database ID on a successful lookup, so code `304` normally cannot fire there;
  custom/malformed empty-ID hydration follows approved `DG-SPI-02/A`.
  Relation-registration `MissingParameters` remains bootstrap-only because no
  current REST handler reaches `Client::registerRelation()`. Structural
  task/gate checks, relative
  link/anchor checks, secret scan and `git diff --check` pass. Post-rebase
  fixed-floor PHP 8.1.34 / WordPress 6.7.7 / Ramsey 1.3.0: unit `6 / 14`,
  integration `67 / 345`; PHPCS `36/36`, exit 0 with the known ruleset
  deprecation warning.

### REST-00B. Зафиксировать connection partial-update semantics

Status: completed

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
- Evidence 2026-09-10:
  [`docs/rest-partial-update-contract.md`](../rest-partial-update-contract.md)
  инвентаризирует
  PHP/domain/storage/REST paths, историю commits `7f800b8`/`2b7bacc`, issues
  #13/#22 и Postman drift; DG-UPDATE-01/02/02R и result gate DG-UPDATE-04
  утверждены вариантом A, а metadata/REST-response choices DG-UPDATE-03 и
  DG-UPDATE-05 остаются pending с downstream acceptance matrix.
- Задача завершает discovery/design, но не разблокирует implementation до
  явного утверждения соответствующих gates владельцем.

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
- REST-00B завершена; DG-UPDATE-01, DG-UPDATE-02 и DG-UPDATE-04 утверждены.
- CORE-04 завершил entity validation и mutation precedence.
- DG-SPI-01, DG-ENT-04 и DG-ENT-05 утверждены.

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
- DG-UPDATE-01, DG-UPDATE-02, DG-UPDATE-04.
- DG-SPI-01.
- DG-ENT-04, DG-ENT-05.
- CORE-04.
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
- DG-UPDATE-01, DG-UPDATE-02 и DG-UPDATE-04 решены.
- DG-SPI-03 и DG-ENT-03 утверждены.
- DG-RESTERR-01—DG-RESTERR-04 утверждены.
- DG-DELETE-01, DG-DELETE-02, DG-DELETE-03, DG-DELETE-04 и DG-DELETE-05
  утверждены.
- DG-NAME-02 утверждён для attributable client-registration failures.
- REST-00A mapping утверждён.
- REST-01, REST-02, CORE-03, CORE-04, DB-03B-A и DB-03B-B завершены.

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
- DG-UPDATE-01, DG-UPDATE-02, DG-UPDATE-04.
- DG-SPI-03.
- DG-ENT-03.
- DG-RESTERR-01, DG-RESTERR-02, DG-RESTERR-03, DG-RESTERR-04.
- DG-DELETE-01, DG-DELETE-02, DG-DELETE-03, DG-DELETE-04, DG-DELETE-05.
- DG-NAME-02.
- REST-00A.
- REST-01, REST-02, CORE-03, CORE-04, DB-03B-A, DB-03B-B.

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
- DG-RESTERR-03 утвердил сохранение WordPress-native permission/error shape.

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
- DG-RESTERR-03.

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

- REST-01 и DB-02/DB-03B-A/DB-03B-B завершены.
- REST-00A и REST-03 завершены.
- CORE-07 устранил query-meta materialization fatal для selective DELETE.
- DG-UPDATE-03, DG-UPDATE-04 и DG-UPDATE-05 утверждены.
- DG-SPI-03 и DG-RESTERR-01—DG-RESTERR-04 утверждены.
- DG-DELETE-01 утверждён для relation ownership metadata DELETE.

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
- REST-00A, REST-03.
- CORE-07.
- DB-02, DB-03B-A, DB-03B-B.
- DG-UPDATE-03, DG-UPDATE-04, DG-UPDATE-05.
- DG-SPI-03.
- DG-RESTERR-01, DG-RESTERR-02, DG-RESTERR-03, DG-RESTERR-04.
- DG-DELETE-01.

Notes/Risks:

- Текущий DELETE route не описывает `meta` в собственных args.
- DG-RESTERR-02 применяется к REST-05 missing-connection и другим numeric domain
  errors. Классифицированный storage failure использует отдельный exact
  non-domain shape DG-RESTERR-04; WordPress-native pre-handler errors сохраняют
  DG-RESTERR-03 shape.

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
- API-01 завершил decision-ready исследование границы connection filters issue
  #21 и issue #20 entity representation; это не является approval.
- DG-API20-01 утверждён владельцем и задаёт selector combination semantics.
- DB-01 завершена.

DoD:

- Issue #21 закрыт.
- Filters отражены в REST tests и переданы как проверенный input для DOC-01;
  публикация OpenAPI остаётся ответственностью DOC-01.
- Unfiltered endpoint сохраняет v1 behavior.

AC:

- Given relation с несколькими connections, when GET содержит `from`, then
  возвращаются только matching rows.
- Given `both`, then endpoint находит connection независимо от стороны.
- Given invalid ID, then request получает validation error, а не raw SQL warning.

Dependencies:

- DG-M4.
- API-01.
- DG-API20-01.
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
- DG-ENT-02 утвердил public non-post resolver extension boundary.
- CORE-04 реализовал утверждённую validation boundary.
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
- DG-ENT-02.
- CORE-04.
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
- DG-NAME-01 и DG-NAME-02 утверждены.

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
- DG-NAME-01, DG-NAME-02.
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
- DG-DB-01, DG-DB-02 и DG-DB-04 утверждены.
- DG-NAME-04 утверждён для complete physical identifier boundary.

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
- DG-DB-01, DG-DB-02, DG-DB-04.
- DG-NAME-04.
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
- 2.0 logging origin payload: trailing Client на query event, unchanged
  existing argument order и priority-10 singleton observer timing.
- 2.0 REST factory delegate: unchanged filter signature plus managed built-in
  handler/permission dispatch and documented lifecycle-override break.
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
- LOG-HOOK-01 и REST-HOOK-01 завершены и передали exact compatibility
  boundaries.
- DG-DB-03 утверждён.
- DG-UPDATE-04 утверждён.
- DG-SPI-01—DG-SPI-07 утверждены.
- DG-RESTERR-01, DG-RESTERR-02 и DG-RESTERR-04 утверждены.
- DG-DELETE-01, DG-DELETE-02, DG-DELETE-03 и DG-DELETE-04 утверждены.
- DG-NAME-01, DG-NAME-02, DG-NAME-03, DG-NAME-05, DG-NAME-06 и
  DG-NAME-06R утверждены.

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
- DG-DB-03.
- DG-UPDATE-04.
- DG-SPI-01, DG-SPI-02, DG-SPI-03, DG-SPI-04, DG-SPI-05, DG-SPI-06,
  DG-SPI-07.
- DG-RESTERR-01, DG-RESTERR-02, DG-RESTERR-04.
- DG-DELETE-01, DG-DELETE-02, DG-DELETE-03, DG-DELETE-04.
- DG-NAME-01, DG-NAME-02, DG-NAME-03, DG-NAME-05, DG-NAME-06, DG-NAME-06R.
- REL-00, SPI-01, LOG-HOOK-01, REST-HOOK-01.

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
- DG-NAME-01—DG-NAME-06R утверждены.

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
- DG-NAME-01—DG-NAME-06R.

Notes/Risks:

- Не выпускать strict cardinality/entity validation без data-audit guidance.

## E7. Context-aware WordPress hook lifecycle

Outcome: 1.x consumers получают semantic lifecycle API без compatibility break,
а 2.0 переходит на проверяемую context-aware subscription boundary с явным
upgrade path для direct `remove_action()` consumers.

Canonical contract и staged delivery map:
[`docs/hook-lifecycle-transition.md`](../hook-lifecycle-transition.md).

### HOOK-TRANS-01. Добавить semantic 1.x post-deletion lifecycle API

Status: completed

Priority: P0

Goal: предоставить стабильный consumer-owned способ включать и выключать
автоматический `deleted_post` cleanup до замены callback identity в 2.0.

Scope:

- Public `Client::enablePostDeletionCleanup(): void`.
- Public `Client::disablePostDeletionCleanup(): void`.
- Constructor auto-enable через новый semantic метод.
- Idempotent registration/removal exact concrete storage callback на priority
  10 с одним accepted argument.
- Regression coverage для disabled, re-enabled, repeated и legacy-direct-remove
  flows.
- README и focused lifecycle contract.

Out of Scope:

- Closure/proxy callback, subscription token или context-aware manager.
- Новая Composer dependency либо отдельный package.
- Runtime deprecation notice.
- Изменение CORE-06R stale-prefix bridge, storage DML/hooks, REST или schema.
- Обещание поддержки consumer-re-registration callback на другом priority.

DoR:

- CORE-06R completed и влит PR #76 как `2371ed2`.
- DG-NAME-06R staged A-to-D утверждён владельцем.
- 1.x transition contract фиксирует точные method names, `void`, default
  auto-enable, identity, priority, accepted args и no-runtime-deprecation.

DoD:

- Новый API additive и одинаково работает с default и custom Storage.
- Repeated enable создаёт один effective callback; repeated disable безопасен.
- Constructor behavior и legacy direct `remove_action()` остаются совместимы.
- Disabled real `deleted_post` не выполняет cleanup; re-enabled выполняет его
  ровно один раз.
- Targeted/full/fixed-floor/coverage/PHPCS/isolation/compatibility проверки и
  independent QA зелёные; evidence записан до merge.

AC:

- Given новый Client, when consumer не вызывает lifecycle API, then exact
  `[$client->getStorage(), 'deleteByObjectID']` зарегистрирован на priority 10
  с одним accepted argument.
- Given enable вызван повторно, when срабатывает `deleted_post`, then storage
  callback выполняется один раз.
- Given disable вызван один или несколько раз, when срабатывает
  `deleted_post`, then callback отсутствует и matching connection сохраняется.
- Given consumer выполнил legacy direct `remove_action()` на priority 10, when
  затем вызван semantic enable, then exact callback снова зарегистрирован.
- Given semantic re-enable, when удалён новый endpoint post, then только его
  matching connection/meta очищаются без повторного callback execution.

Dependencies:

- CORE-06R.
- DG-NAME-06R.

Notes/Risks:

- API intentionally не раскрывает subscription handle или boolean state:
  hook identity перестанет быть public mechanism в 2.0.
- Direct callback manipulation поддерживается в 1.x для совместимости, но
  documentation помечает его как обязательный consumer audit перед 2.0.
- Rollback additive: revert возвращает constructor-owned direct registration и
  не меняет persisted data.

Verification evidence (2026-09-11):

- Red contract: focused suite `15 / 135` with the two expected undefined-method
  errors before production implementation.
- Current and fixed-floor: unit `12 / 58`, integration `108 / 756` on PHP
  8.1.34 with WordPress 7.1 and 6.7.7 respectively.
- True multisite focused suite `15 / 159`; isolation seed `20260911`
  reverse/random repeat-2 unit `24 / 116`, integration `216 / 1512`.
- Fixed-floor combined coverage `120 / 814`, `968/1070 (90.47%)`; exact
  baseline `365/786 (46.44%)`; PR and RC policies pass.
- PHP 8.5.10 / WordPress 7.1 / Ramsey Collection 2.1.1 integration
  `108 / 756`; PHPCS `45/45`; quality-tool synthetics pass.
- Independent QA независимо повторил focused `15/150`, current/fixed-floor
  unit `12/58` и integration `108/756`, true multisite `15/159`, isolation,
  coverage policies, newest compatibility и PHPCS на head `632da3a`; PASS без
  замечаний. Closure head PR #77 `1e98cf7` и merge `5c2fc26` прошли по 17/17
  protected/post-merge jobs. Задача завершена.

### HOOK-00. Выбрать источник и package boundary hook manager

Status: completed

Priority: P1

Goal: доказательно выбрать existing dependency, новый отдельный package или
internal fallback для утверждённого 2.0 context-aware manager.

Scope:

- Current primary-source Composer/Packagist/repository search.
- Candidate matrix: maintenance, license, PHP range, dependencies и release
  history.
- Проверка callback identity, priority, accepted args, deterministic order,
  context predicate, idempotent unsubscribe и active-error propagation.
- Minimal integration probe для кандидатов, прошедших static matrix.
- Подготовка DG-HOOK-01 с recommendation, compatibility cost и rollback.

Out of Scope:

- Установка production dependency.
- Реализация manager или изменение wpConnections hook registration.
- Выбор только по popularity/download count без contract conformance.

DoR:

- HOOK-TRANS-01 completed и влит.
- Target manager contract опубликован.

DoD:

- Для каждого серьёзного кандидата есть source-linked pass/fail matrix.
- Отсутствие подходящего кандидата подтверждено воспроизводимым search scope.
- A/B/C варианты DG-HOOK-01 decision-ready; никакой вариант не считается
  утверждённым по одной рекомендации.

AC:

- Given кандидат, when его API сопоставлен с обязательным contract, then каждый
  критерий имеет evidence или явный gap.
- Given ни один candidate не проходит все mandatory criteria без fork, then
  recommendation выбирает отдельный project-owned package, а не скрытый fork.

Dependencies:

- HOOK-TRANS-01.

Notes/Risks:

- Abandoned или framework-coupled package может стоить дороже малого manager.
- New package требует отдельного repository ownership, CI, versioning и release
  workflow; это входит в decision cost, а не создаётся автоматически.
- Source-linked search, full candidate matrix, option costs и rollback находятся
  в [`docs/hook-manager-selection.md`](../hook-manager-selection.md). Ни один
  candidate не прошёл mandatory static matrix, поэтому runtime probe не имел
  qualifying target. QA-discovered `tombroucke/wp-fluent-hooks` отдельно
  проверен: dispatch predicate есть, но license/subscription/registry boundary
  не проходят. DG-HOOK-01/B утверждён 2026-09-11; package coordinates
  `hokoo/wp-hooks-dispatcher` / `iTRON\wpHooksDispatcher\` подтверждены
  2026-09-12.
- Independent QA после remediation завершилась unconditional PASS на `010c5de`.
  Final head `345e36d` PR #78 и merge `cf8caa6` прошли все 17 protected и
  post-merge jobs. Завершение research не утверждает DG-HOOK-01.

### HOOK-01. Поставить выбранный context-aware manager

Status: completed

Priority: P1

Goal: получить independently testable manager implementation согласно
утверждённому DG-HOOK-01 без зависимости от wpConnections domain classes.

Scope:

- Отдельный public package `hokoo/wp-hooks-dispatcher` под namespace
  `iTRON\wpHooksDispatcher\`, выбранный DG-HOOK-01/B.
- Subscription, context predicate, exact priority/accepted args/order и
  idempotent unsubscribe contract tests.
- Action/filter feature boundary строго следует DG-HOOK-SCOPE-01; future filter
  support остаётся additive после утверждённого action-only первого release.
- Active callback exception propagation и inactive callback non-delivery.
- PHP 8.1+ compatibility, package CI, versioning и minimal usage docs.

Out of Scope:

- wpConnections runtime integration.
- Domain-specific Client/Storage dependencies.
- Перехват или замена глобального WordPress hook registry.

DoR:

- HOOK-00 completed.
- DG-HOOK-01 явно утверждён владельцем.
- DG-HOOK-SCOPE-01 явно утверждён владельцем.
- Package ownership/release location доступен для выбранного варианта.

DoD:

- Selected implementation проходит target contract conformance suite.
- Package/adaptation имеет release/rollback path и pinned compatible version.
- wpConnections может использовать manager через documented public boundary.

AC:

- Given mismatch site identity или prefix, when hook dispatch occurs, then
  target callback не вызывается.
- Given active context, when callbacks имеют equal/different priorities, then
  порядок и accepted arguments соответствуют регистрации.
- Given repeated unsubscribe, then операция безопасна; given active callback
  throws, then original failure не скрывается и не переписывается.

Dependencies:

- HOOK-00.
- DG-HOOK-01, DG-HOOK-SCOPE-01.

Notes/Risks:

- Standalone repository: <https://github.com/hokoo/wp-hooks-dispatcher>.
- Package PR #1 final head `2c3c88f`, merge `ca0040f`, independent QA PASS и
  `5/5` protected/post-merge jobs поставили contract/tests/implementation.
- README clarity PR #2 final head `1b1e59a`, merge `7f449c4`, independent QA
  PASS и `5/5` protected/post-merge jobs поставили точное problem/ownership
  explanation до публикации current patch release.
- Immutable GitHub/Packagist release `v1.0.1` разрешается в commit `7f449c4`.
  Clean PHP 8.1 Composer install скачал registry dist, подтвердил MIT, PHP
  `^8.1`, PSR-4 autoload и отсутствие security advisories.
- Package `master` защищён strict пятью required checks с enforcement для
  администратора, запретом force-push и deletion.

### HOOK-02. Проаудировать все Client-owned hook registrations

Status: completed

Priority: P1

Goal: определить полный 2.0 migration scope по evidence, а не переносить каждый
hook в manager автоматически.

Scope:

- `Client`, `ClientRestApi`, `Settings`, logger и factory-created collaborators.
- Для каждого registration: owner, callback, hook, priority, accepted args,
  construction context, global/site behavior и unregister path.
- Known-consumer search для direct `deleted_post` callback removal.
- Классификация: manager-required, manager-optional, process-global by design,
  internal или separately gated.
- Migration and test hand-off для HOOK-03/HOOK-04/REL-02.

Out of Scope:

- Runtime registration changes.
- Предположение, что `rest_api_init` или logging автоматически site-sensitive.
- Изменение hook names/arguments.

DoR:

- HOOK-TRANS-01 completed и влит.
- REL-00 consumer inventory доступен.

DoD:

- Все reachable Client-owned registrations traceable до code locations.
- Для каждого hook есть context risk, 2.0 action, compatibility statement и
  owner task.
- Consumer direct-remove search воспроизводим и входит в upgrade hand-off.

AC:

- Given любой hook, зарегистрированный при Client construction, when audit
  завершён, then известны identity/priority/args/context/unregister semantics.
- Given hook не требует manager, then artifact объясняет почему и кто защищает
  его contract tests.

Dependencies:

- HOOK-TRANS-01.
- REL-00.

Notes/Risks:

- Indirect registrations внутри constructors могут не находиться одним
  `add_action` search; audit обязан пройти factory graph и runtime probe.
- Evidence и migration map находятся в
  [`docs/client-owned-hook-inventory.md`](../client-owned-hook-inventory.md).
  Найдены 5 registrations при `WP_DEBUG`: `deleted_post`, `rest_api_init` и
  три `Settings` callbacks; library-owned filters отсутствуют.
- Одноразовые PHP 8.1 / WordPress 6.7.7 probes подтвердили: два Client дают по
  две регистрации каждого вида; один query event попадает в оба logger;
  late-created Client не получает route до повторного `rest_api_init`; failed
  constructor оставляет по callback каждого вида. True multisite подтвердил
  delivery обоим same-name Clients и смешение callbacks обоих sites в одном
  REST route. Парный isolated probe дал 0/3 Settings registrations при
  `WP_DEBUG=false/true`. Диагностические файлы после запуска удалены.
- GitHub search не нашёл consumer-owned direct removal в трёх известных public
  consumers; absence не исключает private usage и не снимает HOOK-04 red flag.
- Independent QA вернула unconditional PASS на content head `06b07a7`: source
  inventory/probes, decision packets, custom collaborator boundaries,
  non-cyclic hand-offs, status vocabulary и links/anchors проверены независимо.
  Delivery-owner verification: unit `12/58`, integration `108/756`, PHPCS
  `45/45`; final protected checks остаются условием closure.

### LIFE-HOOK-01. Ввести полный lifecycle Client-owned subscriptions

Status: waiting_dependency

Priority: P0 для 2.0

Goal: сделать удержание, teardown и rollback всех принадлежащих Client
регистраций явными и детерминированными.

Scope:

- Финальная коллекция deletion subscription, REST Client mapping и других
  revocable handles, принадлежащих одному Client.
- Public `Client::dispose(): void`, идемпотентный и terminal для owned
  hook/REST integration activation согласно DG-HOOK-LIFE-01.
- Reverse-order unsubscribe уже созданных subscriptions при любой ошибке
  инициализации.
- Согласованное поведение semantic cleanup API после dispose.
- Tests для strong-reference release, repeated disposal и partial construction.

Out of Scope:

- Автоматическое создание/удаление Client при `switch_to_blog()`.
- Garbage-collector/destructor как единственная lifecycle guarantee.
- Изменение persistence data.
- Новый blanket use-after-dispose guard для прямых domain вызовов через уже
  полученные Client/Relation/Storage references.

DoR:

- HOOK-03, REST-HOOK-01 и LOG-HOOK-01 completed; каждая интеграция уже
  предоставляет revocable ownership boundary или больше не регистрирует
  per-Client callbacks.
- HOOK-02 completed.
- DG-HOOK-LIFE-01 утверждён.

DoD:

- Ни один callback или route mapping, способный достичь failed/disposed Client,
  не остаётся после rollback или dispose. Context-neutral shared dispatcher
  может остаться зарегистрированным без Client reference.
- Dispose безопасен при повторе; semantic cleanup enable и REST activation
  после него дают `ClientRegisterFail` code 4 со stable disposed reason.
- Client каждого site остаётся явной ответственностью consumer.

AC:

- Given исключение после части initialization, when constructor завершается
  ошибкой, then не остаётся новой Client-owned subscription/mapping; допустима
  только идемпотентная shared infrastructure без ссылки на этот Client.
- Given активный Client, when dispose вызывается дважды, then все owned
  subscriptions отсутствуют и вторая операция harmless.
- Given disposed Client, when API пытается повторно активировать subscription,
  then возникает `ClientRegisterFail` code 4 со stable disposed-Client reason.
- Given consumer сохраняет direct domain reference, when integration disposal
  выполнен, then его дальнейшее domain behavior остаётся под прежними
  validation/site-prefix contracts и не активирует hooks/routes неявно.

Dependencies:

- HOOK-02, HOOK-03, REST-HOOK-01, LOG-HOOK-01.
- DG-HOOK-LIFE-01.

Notes/Risks:

- Hook registry удерживает callback objects, поэтому destructor не гарантирует
  достижимость cleanup.
- Terminal boundary относится к library-owned WordPress integrations, а не к
  автоматическому отзыву всех выданных domain/storage references.

### REST-HOOK-01. Защитить hook и route lifecycle REST API

Status: waiting_dependency

Priority: P0 для 2.0

Goal: исключить registration и dispatch REST handler другого site context,
включая reused server и Client, созданный после `rest_api_init`.

Scope:

- Manager-owned `rest_api_init` subscription.
- Current-context activation/rebinding четырёх custom route patterns.
- Factory-selected `ClientRestApi` и его public `$namespace`/`$base` остаются
  per-Client route identity/delegate; `init()` вызывается один раз, а subclass
  обязан делегировать base managed activation согласно DG-HOOK-REST-04.
- Context selection до `permission_callback` и route handler, чтобы ни один
  callback stale Client не выполнялся.
- Утверждённые duplicate-owner, late-initialization, unavailable-dispatch и
  reused-server route-discovery outcomes.
- Same-name multisite, repeated activation и reused-server regressions.

Out of Scope:

- Изменение v1 route URLs, method set или response shape.
- Автоматическая регистрация Client для site, которую consumer не выполнил.
- Общая замена WordPress REST server.
- Автоматическое ownership/переписывание произвольных hooks/routes, которые
  custom REST subclass регистрирует вне library-owned built-in transport.
- Автоматический rollback произвольных side effects custom `init()`.

DoR:

- HOOK-01 и HOOK-02 completed.
- REST-01 harness completed.
- DG-HOOK-REST-01—DG-HOOK-REST-04 утверждены.
- DG-RESTERR-03/A утверждён для native `rest_no_route` shape, выбранного
  DG-HOOK-REST-03/A.

DoD:

- Route текущего site никогда не вызывает Client, созданный под другим blog ID
  или prefix.
- REST integration предоставляет revocable Client mapping/handle для
  LIFE-HOOK-01; удаление mapping делает Client недостижимым из shared routes.
- Factory filter signature, one-time `init()`, public `$namespace`/`$base` и
  selected delegate handler/permission overrides работают через managed
  boundary; legacy registration overrides следуют утверждённому
  DG-HOOK-REST-04 contract.
- Duplicate, late, unavailable и repeated initialization следуют точным
  утверждённым contracts, включая route-index visibility.
- Все четыре route patterns и двенадцать method/callback combinations сохранены.

AC:

- Given same-name Clients sites A/B и reused REST server, when request идёт в
  context B, then только B permission callback и handler достигают Client code.
- Given второй live Client с тем же `(blog ID, prefix, canonical name)`, then
  регистрация по DG-HOOK-REST-02/A завершается
  стабильным `ClientRegisterFail`, а replacement возможен после явного отзыва
  предыдущего internal mapping. End-to-end replacement через public
  `Client::dispose()` принадлежит LIFE-HOOK-01.
- Given Client создан после первого `rest_api_init`, then его текущий-site route
  связывается до успешного завершения construction по DG-HOOK-REST-03/A.
- Given route не имеет live current-context mapping, then ни permission callback,
  ни handler/storage другого Client не вызывается; DG-HOOK-REST-03/A
  dispatch возвращает WordPress-native `rest_no_route`/404, а stale concrete
  path может оставаться видимым в index deliberately reused server.
- Given factory выбирает custom `ClientRestApi`, when built-in route dispatch
  проходит context selection, then вызываются permission/handler overrides
  только current-context delegate; его private registrations не объявляются
  library-owned.
- Given custom delegate меняет `$namespace`/`$base` и его `init()` вызывает
  parent, when Client активируется, then четыре managed patterns используют
  custom route identity и `init()` side effect выполняется ровно один раз.
- Given custom `init()` не выполняет base managed activation, when Client
  construction завершается, then он отклоняется стабильным
  `ClientRegisterFail`, а не возвращает частично unmanaged built-in REST API.

Dependencies:

- HOOK-01, HOOK-02, REST-01.
- DG-HOOK-REST-01—DG-HOOK-REST-04.
- DG-RESTERR-03 для native gateway shape.

Notes/Risks:

- Один manager wrapper не удаляет callbacks из уже заполненного
  `WP_REST_Server`; требуется отдельная route-boundary реализация.
- `WP_REST_Server` не предоставляет owned unregister token; route mapping и
  optional discovery filter должны иметь явную teardown boundary.
- REST-HOOK-01 передаёт factory/delegate compatibility cases в REL-02; REL-02
  не является prerequisite этой implementation задачи.

### LOG-HOOK-01. Устранить cross-client automatic debug fanout

Status: in_progress

Priority: P1 для 2.0

Goal: один storage operation создаёт одну debug запись через logger своего
Client, сохраняя public hook names/existing argument order и явно версионируя
необходимый trailing origin payload.

Scope:

- Удаление/замена трёх library-owned `Settings` subscriptions согласно
  DG-HOOK-LOG-01.
- Один process-global priority-10 observer, который выбирает logger по
  originating Client и не удерживает per-Client closure.
- Для `findConnections/dbQuery` — additive trailing Client argument; для meta
  removal и specific deletion — уже существующий first Client argument.
- Сохранение public hook names, existing argument order, legacy logged payload
  и `Logger::log()` compatibility action.
- Bundled/custom Storage, same-site multi-client, multisite, `WP_DEBUG` on/off,
  public-hook ordering и custom logger tests.

Out of Scope:

- Управление consumer-owned listeners на public storage hooks.
- Изменение PSR logger interface, hook names или порядка существующих arguments.
- Перенос logging callbacks в manager: утверждённый DG-HOOK-LOG-01/B оставляет
  automatic logging у singleton origin-routed observer.

DoR:

- HOOK-02 completed.
- DG-HOOK-LOG-01 утверждён.
- DG-SPI-06 утверждён; mutation hook timing известен до реализации observer.

DoD:

- Каждый instrumented operation создаёт ровно одну запись через origin Client
  logger при включённом debug и ни одной при выключенном.
- Другой Client/site logger не вызывается.
- Existing public extension callbacks продолжают получать прежние arguments в
  прежнем порядке; query hook документированно добавляет trailing Client.
- Custom Storage без корректного origin payload всё ещё испускает public event,
  но automatic logging безопасно пропускается вместо fanout.

AC:

- Given два Client одного site, when storage A выполняет find query, then logger
  A вызывается один раз, logger B — ни разу.
- Given Client A/B разных sites, when operation выполняется на B, then logger A
  не вызывается.
- Given conforming custom Storage, when оно испускает любой из трёх events с
  documented Client origin, then соответствующий logger вызывается один раз.
- Given custom Storage не передаёт валидный origin, when event испускается, then
  consumer callbacks всё ещё выполняются, но library automatic logger — нет.
- Given consumer listener с legacy accepted-argument count, when query event
  испускается, then он получает прежние два arguments; opt-in listener с тремя
  получает trailing Client.
- Given consumer callbacks до/после priority 10, when event испускается, then
  documented ordering/timing соответствует DG-SPI-06 и REL-02 contract tests.

Dependencies:

- HOOK-02.
- DG-HOOK-LOG-01.
- DG-SPI-06.
- REL-02 получает compatibility hand-off и не считается prerequisite этого
  implementation task.

Notes/Risks:

- `findConnections/dbQuery` не содержит Client argument; routing через прежний
  global event без additive payload невозможен.
- Изменение duplicate log count является намеренным 2.0 correction и должно
  войти в migration notes.
- Existing equal-priority consumer order может зависеть от registration order;
  singleton observer должен регистрироваться детерминированно, а REL-02 обязан
  зафиксировать точную границу вместо обещания «priority-independent» timing.

### HOOK-03. Перевести 2.0 registrations на context-aware manager

Status: waiting_dependency

Priority: P0 для 2.0

Goal: заменить direct process-global delivery там, где HOOK-02 доказал
site-context boundary, начиная с `deleted_post`.

Scope:

- Manager-backed `deleted_post` registration.
- Active/inactive/restored multisite, same-name clients, ordering, args,
  unsubscribe и failure conformance.
- Удаление 1.x `current_filter()` bridge только после equivalent manager tests.

Out of Scope:

- Поддержка direct storage callback identity в 2.0.
- Изменение storage/delete result contract за пределами утверждённых gates.
- Автоматическое создание Client после `switch_to_blog()`.

DoR:

- HOOK-01 и HOOK-02 completed.
- DB-04 completed для полного cascade contract.
- Применимые delete/failure gates утверждены.
- 2.0 release branch/version boundary открыт.

DoD:

- Mismatched context callback не запускается manager-ом, а не storage guard.
- Active callback поведение, ошибки и ordering соответствуют contracts.
- Semantic Client enable/disable API работает через manager без consumer code
  change.
- Client сохраняет один manager subscription handle; semantic disable
  idempotently его отзывает, а LIFE-HOOK-01 может собрать тот же ownership
  boundary без восстановления внутренней callback identity.
- 1.x direct callback compatibility break покрыт tests и upgrade fixture.

AC:

- Given clients site A и site B, when post удалён на B, then только B
  subscriptions запускаются и A callback вообще не вызывается.
- Given consumer использует semantic disable, then cleanup отсутствует до
  semantic enable независимо от внутренней callback identity.
- Given deletion subscription передана final lifecycle, when Client disposal
  начинается, then retained handle можно отозвать повторно без delivery.
- Given active callback throws, then manager не подавляет ошибку; recovery
  следует утверждённому delete failure contract.

Dependencies:

- HOOK-01, HOOK-02, DB-04.
- DG-HOOK-01.
- DG-DELETE-06.

Notes/Risks:

- Это intentional major-version break для direct `remove_action()` consumers.
- Consumer остаётся ответственным за отдельный Client в каждом site context.

### HOOK-04. Проверить migration и выпустить 2.0 hook upgrade guide

Status: waiting_dependency

Priority: P0 для 2.0 release

Goal: сделать callback-identity break видимым, обнаружимым и проверяемым до
обновления consumer applications.

Scope:

- Changelog/upgrade-guide red flag для direct `remove_action()`.
- Known-consumer repository search и migration checklist.
- Before/after examples через semantic Client lifecycle API.
- Clean 1.x-to-2.0 consumer fixture и rollback rehearsal.
- REST/logging/lifecycle migration notes, где применимо.
- 2.0 red flag для custom `ClientRestApi::init()`/`registerRestRoutes()`
  overrides: parent managed activation required; `$namespace`/`$base` and
  handler/permission overrides retained; arbitrary side effects and private
  routes stay implementer-owned.
- REL-02/REL-03 compatibility evidence update.

Out of Scope:

- Автоматическая перепись third-party consumer code.
- Обещание совместимости неизвестных callback internals.

DoR:

- HOOK-03, REST-HOOK-01, LOG-HOOK-01 и LIFE-HOOK-01 completed.
- REL-02 hook/factory compatibility evidence доступен.
- REL-03 release process активен.

DoD:

- Upgrade guide явно говорит, что storage-method `remove_action()` больше не
  управляет cleanup в 2.0.
- Known consumers проверены; найденные usages имеют owner/outcome.
- Representative consumer мигрирует на semantic API и проходит rollback test.

AC:

- Given 1.x consumer с direct callback removal, when он следует guide, then до
  2.0 upgrade переходит на semantic disable и сохраняет поведение после upgrade.
- Given release candidate, then no known direct-remove usage remains without an
  explicit migration owner or accepted external risk.

Dependencies:

- HOOK-03, REST-HOOK-01, LOG-HOOK-01, LIFE-HOOK-01, REL-02, REL-03.

Notes/Risks:

- Это обязательный release gate, а не обычная deprecation note.

## Traceability: замечания и GitHub issues

| Источник/наблюдение | План |
|---|---|
| Confirmed: `1-m`/`m-1` violations | TEST-02B, TEST-02C, CORE-02 |
| Confirmed: relation без `to` | TEST-02A, CORE-01 |
| Confirmed: REST update uninitialized `title` | TEST-02D, REST-02 |
| Confirmed: broken `both` placeholder | TEST-02E, DB-01 |
| Closed [#31 error code tests](https://github.com/hokoo/wpConnections/issues/31) | CORE-03, REST-03 |
| Open [#21 REST filters](https://github.com/hokoo/wpConnections/issues/21) | REST-06 |
| Open [#20 entities/getPosts](https://github.com/hokoo/wpConnections/issues/20) | API-01, API-03, API-04, DOC-01 |
| Open [#27 OpenAPI](https://github.com/hokoo/wpConnections/issues/27) | DOC-01 |
| Open [#28 dashboard](https://github.com/hokoo/wpConnections/issues/28) | PROD-01 deferred initiative |
| Closed [#13 order zero](https://github.com/hokoo/wpConnections/issues/13) | DB-02 |
| Closed [#29 duplicate precedence](https://github.com/hokoo/wpConnections/issues/29) | CORE-03 |
| Closed [#33 cardinality](https://github.com/hokoo/wpConnections/issues/33) | CORE-02; повторно закрыт PR #63 с regression evidence |
| Closed [#35 permissions](https://github.com/hokoo/wpConnections/issues/35) | REST-04 |
| Closed [#45 dbDelta/schema](https://github.com/hokoo/wpConnections/issues/45) | DB-06 |
| Untested delete/meta cascade | DB-03A, DB-03B-A, DB-03B-B, DB-04, DB-05 |
| Untested multi-client promise | CORE-05 design completed; CORE-06, DB-06 implementation |
| Closed [#16 create persistence integration](https://github.com/hokoo/wpConnections/issues/16) | TEST-01; retained `WPUnitTest::testCreateConnection`; DB-02 follow-on |
| Closed [#22 Connection self-update](https://github.com/hokoo/wpConnections/issues/22) | REST-00B; DB-02/REST-02 follow-on |
| Closed [#23 legacy `tests/dev` runner](https://github.com/hokoo/wpConnections/issues/23) | superseded by #37; INFRA-06/TEST-01 |
| Closed [#32 legacy WP unit workflow](https://github.com/hokoo/wpConnections/issues/32) | superseded by infrastructure PR #48; INFRA-01/INFRA-03/INFRA-08 |
| Closed [#37 local development environment](https://github.com/hokoo/wpConnections/issues/37) | INFRA-02/INFRA-06/INFRA-07 |
| Empty `Connection::load()` | DG-M5, API-02 |
| `type` has no behavior | DG-M2, CORE-01, DOC-01 |
| Direct storage mutation bypasses domain invariants | DG-M9, CORE-04, DB-05, REL-02 |
| Process-global `deleted_post` callback after multisite switch | CORE-06R, HOOK-TRANS-01, HOOK-00—HOOK-04 |
| Process-global REST hook plus reused route registry | HOOK-02, REST-HOOK-01, DG-HOOK-REST-01—DG-HOOK-REST-04, DG-RESTERR-03, REL-02 |
| `Settings` debug callbacks fan out to every Client logger | HOOK-02, LOG-HOOK-01, DG-HOOK-LOG-01, DG-SPI-06, REL-02 |
| Failed Client construction leaves owned callbacks registered | HOOK-02, LIFE-HOOK-01, DG-HOOK-LIFE-01 |
| Open issue #20 related entities | API-01, API-03, API-04, DOC-01 |
| Missing route-level REST tests | REST-01—REST-05 |
| Missing coverage/quality policy in CI | INFRA-04, TEST-03A, TEST-03B, TEST-03C |
