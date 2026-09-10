# План завершения инфраструктурной ветки

## Контекст

- Ветка: `codex/dockerfile-ci-transition`.
- Pull request: [#48 Move CI checks toward Dockerfile workflow](https://github.com/hokoo/wpConnections/pull/48).
- Base: `master`; исходный implementation diff PR был на пять коммитов впереди,
  локальный HEAD дополнен execution-коммитами этого плана.
- Исходная реализация PR состояла из пяти коммитов. Поверх неё локально завершён
  согласованный hardening batch: freshness, version matrix, coverage gate,
  Compose v2 cleanup и CI runbook.
- Функциональные тесты и поведение библиотеки в этой ветке не меняются.

## Цель и границы

**Outcome:** после merge разработчик и CI получают один воспроизводимый способ
запустить unit, WordPress integration, PHPCS и coverage на заявленной матрице
версий, без зависимости от ранее собранного image или устаревшего `vendor`.

**Scope:** Docker test image, entrypoint, Make targets, GitHub Actions,
dependency/version matrix, coverage collection, документация и merge checklist.

**Out of Scope:** исправление cardinality, storage/REST поведения, расширение
публичного API, изменение схемы данных и реализация dashboard application.

## Рекомендуемый порядок

`INFRA-01 -> gates I1-I5 -> INFRA-02/03/04 -> INFRA-06 -> INFRA-07 -> DG-I6 -> INFRA-09 -> DG-I7 -> INFRA-08`

`INFRA-02`, `INFRA-03` и `INFRA-04` можно выполнять параллельно после решений
gate. `INFRA-05` вынесена в отдельный follow-up после merge и не блокирует
`INFRA-08`; оптимизация не должна ослаблять clean-checkout проверку.

## Decision gates

### DG-I1. Граница PR #48

**Вопрос:** сколько инфраструктурного hardening включать до merge текущего PR?

- A: влить текущее состояние сразу, всё остальное вынести в follow-up PR.
- B: до merge добавить freshness, version policy, coverage baseline и Compose
  cleanup; оптимизацию оставить follow-up.
- C: завершить в одном PR также caching и полную compatibility matrix.

**Рекомендация:** B. Она закрывает риски ложнозелёного локального запуска и
создаёт baseline для функциональных исправлений, не превращая PR в долгий
проект по оптимизации CI.

**Решение 2026-09-10:** B. Freshness, version policy, coverage baseline и
Compose cleanup входят в PR #48; cache/build optimization остаётся отдельным
follow-up и не блокирует merge.

**Блокирует:** финальный scope `INFRA-02`—`INFRA-08`.

### DG-I2. Политика свежести локального запуска

**Вопрос:** должен ли `make tests.run` всегда перестраивать image и запускать
`composer install`?

- A: всегда `--build` и всегда идемпотентный `composer install`.
- B: быстрый default с явными `tests.build`/`tests.clean`; entrypoint проверяет,
  что image и `vendor` соответствуют Dockerfile и lock.
- C: оставить текущую модель, где существующие image и `vendor` принимаются без
  проверки.

**Рекомендация:** B. Она сохраняет быстрый inner loop, но не допускает молчаливо
устаревшее окружение. В CI всегда используется clean build.

**Решение 2026-09-10:** B. Fast path остаётся командой по умолчанию; clean build
и проверяемая синхронизация зависимостей становятся явной частью интерфейса.

**Блокирует:** `INFRA-02`.

### DG-I3. WordPress compatibility policy

**Вопрос:** что означает поддерживаемый WordPress диапазон?

- A: только зафиксированная stable-версия.
- B: минимально поддерживаемая + текущая stable.
- C: минимальная + stable + `wordpress-develop/master` как allow-failure или
  scheduled canary.

**Рекомендация:** C. Blocking PR checks выполняются на минимальной и stable,
mutable `master` запускается отдельно как canary и не ломает воспроизводимость
обычных PR.

**Решение 2026-09-10:** C. Minimum и stable являются blocking inputs, trunk —
отдельным неблокирующим scheduled canary.

**Блокирует:** `INFRA-03`, `INFRA-05` и финальную release matrix.

### DG-I4. Coverage policy

**Вопрос:** вводить ли жёсткий процентный gate при текущем baseline 46,44%?

- A: сразу требовать глобальный threshold выше baseline.
- B: публиковать отчёт и запрещать снижение baseline; повышать threshold после
  каждого эпика основного плана.
- C: только сохранять artifact без gate.

**Рекомендация:** B. Первый threshold равен подтверждённому baseline, а
критические зоны дополнительно контролируются обязательными scenario tests, а
не только процентом.

**Решение 2026-09-10:** B. CI публикует единый coverage report и запрещает
снижение подтверждённого baseline; повышение порога идёт отдельными изменениями.

**Блокирует:** `INFRA-04` и задачу quality gates основного плана.

### DG-I5. Docker Compose CLI

**Вопрос:** сохранять совместимость с legacy `docker-compose` или перейти на
Compose v2 `docker compose`?

- A: перейти на v2 и задокументировать минимальную версию.
- B: временно поддерживать оба вызова через wrapper.

**Рекомендация:** A, если все активные developer/CI environments имеют Compose
v2; иначе B с датой удаления legacy path.

**Решение 2026-09-10:** A. Поддерживаемый локальный интерфейс — Compose v2
`docker compose`; legacy binary не сохраняется.

**Блокирует:** `INFRA-06`.

### DG-I6. Уязвимый dev lint toolchain

**Вопрос:** как закрыть две high-severity advisory, обнаруженные после
выполнения утверждённого scope?

- A: до merge обновить PHPCS до `3.13.6`, WPCS до `3.4.1` и выполнить
  минимальную миграцию ruleset.
- B: принять риск и вынести обновление в follow-up с владельцем и сроком.
- C: удалить WPCS из dev dependencies.

**Рекомендация:** A. Изолированный spike подтвердил узкий dependency-only
patch без изменений `src`: audit становится чистым, а PHPCS, unit, integration
и coverage сохраняют текущий результат. B оставляет исполняемые CI-инструменты с
двумя high advisory; C меняет dev-tool contract и не закрывает PHPCS advisory.

**Решение 2026-09-10:** A. Обновить PHPCS/WPCS и минимально мигрировать ruleset
до merge; не принимать security risk и не менять `src` в этой задаче.

**Блокирует:** `INFRA-09` и финальное решение `INFRA-08`.

### DG-I7. Защита ветки `master`

**Вопрос:** как обеспечить обязательное прохождение CI перед изменением
`master`, если branch protection в репозитории ещё не настроена?

- A: включить strict branch protection для всех 17 ожидаемых job contexts,
  применить её к администраторам и запретить force-push/delete; обязательный
  сторонний review не вводить.
- B: оставить `master` без защиты и использовать ручной merge checklist.
- C: сначала добавить стабильные aggregate jobs, затем защищать ветку по
  четырём постоянным context names.

**Рекомендация:** A. Она немедленно делает уже проверенный CI contract
обязательным и не расширяет текущий PR дополнительной оркестрацией. Версии в
matrix закреплены, поэтому изменение context names должно выполняться как
явная часть будущего изменения compatibility policy. C остаётся допустимым
follow-up для упрощения сопровождения branch protection.

**Решение 2026-09-10:** A. До merge включить strict required checks для 10 unit,
5 WordPress integration, coverage и PHPCS jobs; включить enforcement для
администраторов, запретить force-push/delete и не требовать стороннего approval.

**Блокирует:** `INFRA-08`.

## Реестр решений

| Gate | Решение | Владелец | Дата | Следствие |
|---|---|---|---|---|
| DG-I1 | approved B | repository owner | 2026-09-10 | INFRA-02/03/04/06 блокируют merge; INFRA-05 — follow-up |
| DG-I2 | approved B | repository owner | 2026-09-10 | Fast default + explicit clean path |
| DG-I3 | approved C | repository owner | 2026-09-10 | Blocking minimum/stable + non-blocking trunk canary |
| DG-I4 | approved B | repository owner | 2026-09-10 | Report + no-regression baseline gate |
| DG-I5 | approved A | repository owner | 2026-09-10 | Compose v2 only |
| DG-I6 | approved A | repository owner | 2026-09-10 | Исправить lint toolchain до merge |
| DG-I7 | approved A | repository owner | 2026-09-10 | Strict protection: 17 required checks, admin enforcement, no force-push/delete |

## Execution tasks

### INFRA-01. Принять текущий Docker CI transition

Status: completed

Priority: P0

Goal: подтвердить, что уже реализованная часть PR #48 является корректной базой
для оставшихся инфраструктурных задач.

Scope:

- Review пяти коммитов относительно `master`.
- Проверка разделения unit/integration/PHPCS workflows.
- Проверка `test:all`, `test:phpunit`, `test:integration`, `cs:phpcs`.
- Fresh Docker build и clean-checkout Composer install.

Out of Scope:

- Изменение поведения `src`.
- Добавление regression-тестов библиотеки.

DoR:

- PR #48 доступен и его HEAD совпадает с веткой.
- Docker и GitHub Actions доступны.

DoD:

- Review не выявил blocking regression в CI entrypoint.
- Результаты проверки и известные follow-up риски записаны в PR/план.
- Владелец переводит PR из draft после завершения согласованного scope.

AC:

- Given чистый архив HEAD без `vendor`, when выполняется fresh image build и
  `test:all`, then Composer install, 4 unit и 5 integration тестов завершаются
  успешно.
- Given текущий HEAD, when запускается `cs:phpcs`, then проверяются все файлы
  `src` без ошибок.
- Given PR #48, then все обязательные matrix checks имеют success status.

Dependencies:

- Нет.

Verification:

- `docker build --build-arg PHP_VERSION=8.1 -f Dockerfile.phpunit .`
- `docker run ... test:all`
- `make lint.phpcs`

Notes/Risks:

- Проверки выполнены успешно, а owner утвердил рекомендованный scope 2026-09-10.
- Единственное изменение `src` в ветке — форматирование `WPStorage`.

### INFRA-02. Исключить устаревшие image и Composer dependencies

Status: completed

Priority: P0

Goal: локальный зелёный результат должен относиться к текущим Dockerfile,
`composer.lock` и source tree.

Scope:

- Реализовать решение DG-I2.
- Добавить явные Make targets для build/rebuild при выбранной модели.
- Заменить безусловный `vendor/ already exists, skipping composer install` на
  проверяемую идемпотентную стратегию.
- Описать быстрый и clean режимы запуска.

Out of Scope:

- Обновление package versions.
- Оптимизация GitHub cache.

DoR:

- DG-I2 решён.
- Согласован допустимый overhead обычного локального запуска.

DoD:

- Изменение Dockerfile не может быть незаметно проигнорировано рекомендуемой
  командой проверки.
- Изменение lock-файла приводит к синхронизации `vendor` или к понятной ошибке.
- README содержит команды fast loop и clean verification.

AC:

- Given image от предыдущего Dockerfile, when разработчик запускает documented
  clean command, then используется заново собранный image.
- Given существующий `vendor`, не соответствующий lock, when запускаются тесты,
  then зависимости синхронизируются или запуск завершается диагностической
  ошибкой до PHPUnit.
- Given актуальное окружение, when запускается fast path, then повторный запуск
  не требует полного скачивания зависимостей.

Dependencies:

- DG-I2.

Notes/Risks:

- Всегда выполнять `composer update` нельзя: это меняет lock и разрушает
  воспроизводимость. Для штатного path нужен `composer install`.
- Реализовано коммитами `74ef965` и `03b9475`: fast path синхронизирует
  зависимости через идемпотентный `composer install`, clean path делает
  no-cache rebuild, а baked manifest до Composer проверяет Dockerfile,
  entrypoint и ожидаемые PHP/WordPress inputs.

### INFRA-03. Зафиксировать PHP, WordPress и Ramsey test matrix

Status: completed

Priority: P0

Goal: CI должен проверять объявленный compatibility contract, а не случайную
версию `wordpress-develop/master`.

Scope:

- Реализовать DG-I3.
- Зафиксировать blocking PHP/WordPress/Ramsey combinations.
- Отделить mutable WordPress trunk canary от обязательных PR checks.
- Проверить соответствие `composer.json`, lock и Docker build args.

Out of Scope:

- Расширение поддержки за пределы утверждённого диапазона.
- MySQL compatibility matrix основного плана.

DoR:

- Владелец утвердил минимальную и stable WordPress versions.
- Определено, является ли trunk failure blocking.

DoD:

- Матрица перечислена в документации и workflow.
- Каждый blocking job использует immutable version input.
- Trunk canary имеет понятную политику реакции на failure.

AC:

- Given pull request, when запускается CI, then обязательные jobs тестируют все
  утверждённые PHP × WordPress × Ramsey combinations.
- Given изменение `wordpress-develop/master`, then оно не меняет результат
  воспроизводимого stable job.
- Given unsupported combination, then она отсутствует в blocking matrix и
  явно отмечена в compatibility documentation.

Dependencies:

- DG-I3.

Notes/Risks:

- Полный Cartesian matrix может быть дорогим; допустима pairwise-матрица с
  отдельным scheduled full run.
- Реализовано коммитом `2a28bd5`: 10 unit lanes, 5 blocking pairwise
  integration lanes и scheduled/manual WordPress `trunk` canary.
- Compatibility floor закреплён на WordPress `6.7.7`, stable — на `7.1.0`;
  symbolic `latest` и stale GitHub branch `master` отклоняются.

### INFRA-04. Добавить штатный coverage report и baseline gate

Status: completed

Priority: P0

Goal: каждый PR показывает изменение покрытия обоих test suites и не может
молча снизить согласованный baseline.

Scope:

- Добавить coverage driver в test image или использовать встроенный `phpdbg`.
- Создать команду объединённого coverage для unit + WordPress integration.
- Публиковать machine-readable и human-readable artifacts.
- Реализовать решение DG-I4.

Out of Scope:

- Достижение финального coverage target основного плана.
- Замена scenario-based acceptance criteria процентом покрытия.

DoR:

- DG-I4 решён.
- Выбран формат artifact и способ отображения в GitHub.

DoD:

- Coverage воспроизводится локально одной documented командой.
- CI публикует общий отчёт даже при падении threshold.
- Baseline и правило его изменения записаны в репозитории.

AC:

- Given текущий baseline, when запускается coverage, then отчёт включает все
  файлы `src`, unit и integration suites.
- Given PR снижает coverage ниже утверждённого baseline, then blocking job
  завершается ошибкой и показывает изменение.
- Given новый непокрытый source file, then он учитывается в denominator.

Dependencies:

- DG-I4.
- INFRA-01.

Notes/Risks:

- Зафиксированный аудитом baseline — 46,44% строк; его нужно повторно измерить
  штатной командой перед включением gate.
- Реализовано коммитом `1324f15`; штатный combined run подтвердил точное
  значение `365/786` statements, которое сравнивается без округления.

### INFRA-05. Уменьшить стоимость и длительность CI без потери проверок

Status: deferred

Priority: P1

Goal: не собирать и не скачивать одинаковые тяжёлые слои для каждого job, при
этом сохранить независимый статус unit, integration и PHPCS.

Scope:

- Docker layer/build cache.
- Composer download cache.
- Разделение lightweight и WordPress runtime stages, если оно оправдано.
- Concurrency/cancellation для superseded PR runs.
- Scheduled full matrix при сокращённой PR matrix, если это выбрано.

Out of Scope:

- Удаление независимых quality checks.
- Пропуск clean install в CI.

DoR:

- INFRA-03 определила matrix.
- Есть baseline длительности jobs.

DoD:

- Median CI duration или downloaded/build work уменьшены измеримо.
- Cache miss по-прежнему приводит к успешному clean build.
- Unit, integration и PHPCS видны как отдельные checks.

AC:

- Given два последовательных CI run одного dependency set, when выполняется
  второй run, then безопасно переиспользуются подтверждённые cache layers.
- Given пустой cache, when выполняется workflow, then результат идентичен
  cached run.
- Given новый commit в том же PR, then предыдущий незавершённый run отменяется.

Dependencies:

- INFRA-03.
- INFRA-04, если coverage влияет на image stages.

Notes/Risks:

- Cache key обязан учитывать Dockerfile, lock, PHP, WordPress и Ramsey inputs.
- DG-I1 оставляет эту задачу отдельным follow-up; она не входит в blocking scope
  PR #48.

### INFRA-06. Нормализовать Docker Compose и Make interface

Status: completed

Priority: P1

Goal: убрать warning об obsolete Compose schema и предоставить единый
поддерживаемый CLI для локальной разработки.

Scope:

- Реализовать DG-I5.
- Удалить устаревшее поле `version` из Compose file.
- Унифицировать `tests.wpunit`/`tests.integration` naming и aliases.
- Проверить команды на чистом clone.

Out of Scope:

- Изменение production/local WordPress stack.
- Перестройка nginx/php/mysql development services.

DoR:

- DG-I5 решён.
- Известны используемые разработчиками Compose versions.

DoD:

- Рекомендуемые Make commands выполняются без obsolete warning.
- Legacy alias либо документирован с removal date, либо удалён осознанно.
- README соответствует фактическим командам.

AC:

- Given поддерживаемая Docker/Compose version, when запускаются все documented
  Make targets, then команды используют один и тот же project/service naming.
- Given `make tests.wpunit`, then поведение явно эквивалентно документированному
  integration target либо alias отсутствует из публичной документации.

Dependencies:

- DG-I5.
- INFRA-02, чтобы не редактировать Make interface дважды.

Notes/Risks:

- Удаление legacy binary без проверки developer environments может нарушить
  локальный workflow.
- Реализовано коммитом `a2a833e`; Compose v2 `2.26.1` и все documented targets
  проверены без obsolete schema warning.

### INFRA-07. Создать CI runbook и contribution contract

Status: completed

Priority: P1

Goal: новый contributor может понять suites, matrices, coverage и обязательные
checks без чтения shell scripts.

Scope:

- Назначение unit и WordPress integration suites.
- Fast и clean local commands.
- Version matrix и canary policy.
- Coverage command и интерпретация baseline.
- Troubleshooting MariaDB, Composer и Docker cache.

Out of Scope:

- Документация публичного API библиотеки.
- OpenAPI specification.

DoR:

- INFRA-02, INFRA-03, INFRA-04 и INFRA-06 завершены.

DoD:

- README или отдельный runbook отражает фактические команды и CI policy.
- Все команды runbook проверены на clean checkout.
- Из PR template/checklist видны обязательные проверки.

AC:

- Given новый clone, when contributor следует runbook, then получает тот же
  набор результатов, что и CI.
- Given coverage failure, then runbook объясняет локальное воспроизведение.

Dependencies:

- INFRA-02, INFRA-03, INFRA-04, INFRA-06.

Notes/Risks:

- Не дублировать команды в нескольких местах без единого source of truth.
- Реализовано коммитом `79ead80`: source of truth — `docs/ci-runbook.md`, а PR
  template содержит merge-readiness checklist.

### INFRA-09. Закрыть high-severity advisory lint toolchain

Status: completed

Priority: P0

Goal: CI и локальный lint не должны исполнять версии dev tools с известными
high-severity advisory.

Scope:

- Реализовать решение DG-I6.
- При варианте A обновить `squizlabs/php_codesniffer` до `^3.13.6` и
  `wp-coding-standards/wpcs` до `^3.4.1`.
- Обновить `composer.lock` целевым dependency update.
- Переименовать WPCS property `minimum_supported_wp_version` в
  `minimum_wp_version`.

Out of Scope:

- PHPCS 4.x.
- Миграция `phpcompatibility/php-compatibility` на следующий major.
- Исправление PHP dynamic-property deprecations в `src`.

DoR:

- DG-I6 решён.
- При варианте B назначены владелец, срок и явное risk acceptance.

DoD:

- При варианте A `composer audit --locked` не содержит advisory.
- PHPCS, unit, WordPress integration и combined coverage не регрессируют.
- Dependency diff ограничен lint toolchain и его обязательными transitive
  packages.

AC:

- Given обновлённый lock, when выполняется `composer audit --locked`, then
  advisory CVE-2026-67434 и CVE-2026-45293 отсутствуют.
- Given WPCS 3.4.1 и PHPCS 3.13.6, when выполняется `cs:phpcs`, then все 35
  source files проходят без violations.
- Given security update, when выполняется combined coverage, then gate остаётся
  на `365/786` или выше.

Dependencies:

- DG-I6.
- INFRA-03 и INFRA-04.

Verification:

- `composer validate --strict --no-check-publish`
- `composer audit --locked`
- `make lint.phpcs`
- `make tests.run`
- `make tests.coverage`

Notes/Risks:

- Feasibility spike разрешил PHPCS `3.13.6`, WPCS `3.4.1`, PHPCSExtra `1.5.1`
  и PHPCSUtils `1.2.3`; остальные package versions не изменились.
- Реализовано коммитом `7830ebb`; audit, PHPCS, обе test suites и coverage gate
  прошли implementation verification.
- Независимый epic-level QA подтвердил DoD/AC без blocking defects.
- После update появляется неблокирующий vendor deprecation из старого
  PHPCompatibility 9.3.5; его modernization остаётся отдельным follow-up.
- Источники: [PHPCS advisory](https://github.com/PHPCSStandards/PHP_CodeSniffer/security/advisories/GHSA-hmqg-cxww-wqhq),
  [WPCS advisory](https://github.com/WordPress/WordPress-Coding-Standards/security/advisories/GHSA-3pwp-g2mj-5p3v).

### INFRA-08. Завершить PR #48 и установить milestone M0

Status: in_progress

Priority: P0

Goal: безопасно влить инфраструктуру в `master` и разблокировать основной план.

Scope:

- Resolve всех выбранных blocking задач текущей ветки.
- Финальный clean-checkout run.
- Настройка одобренной защиты `master` по DG-I7.
- Review, снятие draft, merge и post-merge проверка `master`.
- Актуализация статусов планов.

Out of Scope:

- Любые functional fixes из `02-library-hardening.md`.

DoR:

- DG-I1 решён.
- DG-I6 решён; `INFRA-09` завершена либо явно отложена принятым решением.
- DG-I7 решён.
- Все задачи, включённые решением DG-I1 в scope PR, имеют `completed` или
  `review` без blocking замечаний.
- Required checks зелёные.

DoD:

- PR #48 merged в `master`.
- `master` защищён 17 обязательными CI contexts согласно DG-I7.
- Required checks проходят на merge commit.
- Milestone M0 отмечен в `docs/plans/README.md`.
- Первая задача основного плана переведена из `waiting_dependency` в `todo`.

AC:

- Given merge commit на `master`, when запускается documented clean command,
  then unit, integration, PHPCS и coverage завершаются согласно policy.
- Given попытка изменить `master`, when любой обязательный context отсутствует
  или завершился неуспешно, then branch protection не разрешает изменение.
- Given основной план, then ни одна функциональная задача больше не зависит от
  незамерженной CI-ветки.

Dependencies:

- INFRA-01.
- Все задачи, выбранные DG-I1 как blocking.
- DG-I7.
- Owner review и разрешение на merge.

Notes/Risks:

- Владелец одобрил DG-I7, снятие draft и merge 2026-09-10.
- Branch protection на момент принятия DG-I7 отсутствовала; её настройка и
  post-merge CI являются оставшимися внешними шагами задачи.

## Execution evidence 2026-09-10

| Проверка | Результат |
|---|---|
| Unit compatibility matrix | 10/10 lanes passed; 4 tests / 7 assertions каждая |
| WordPress integration matrix | 5/5 lanes passed; 5 tests / 34 assertions каждая |
| WordPress trunk canary | passed на `7.2-alpha-63166-src` |
| Aggregate `test:all` | passed; unit 4/7 + integration 5/34 |
| Combined coverage | passed; 9 tests / 41 assertions; `365/786` statements |
| Coverage negative paths | regression exit 1; malformed input exit 2; artifacts сохранены |
| PHPCS | passed; 35/35 files |
| Compose v2 / clean rebuild | config valid; no-cache build и повторный fast run passed |
| Local image freshness | current passed; stale/missing manifest и input mismatch завершились exit 78 до Composer |
| Composer security audit | 0 advisories после PHPCS/WPCS update |
| Final clean checkout | fresh Docker build; 47 lock packages installed; `test:all` passed |
| Independent epic QA | `pass_with_notes`; blocking defects и missing AC не обнаружены |
| GitHub PR checks на `16fe912` | 17/17 passed; PR `MERGEABLE` и `CLEAN` |

Известные наблюдения: PHP 8.4/8.5 показывают существующие deprecation notices;
`composer validate --strict` возвращает warning status из-за устаревшего SPDX
identifier `GPL-2.0+` и unbound constraint `psr/log >=1.1`. Две обнаруженные
audit advisory закрыты одобренным DG-I6 и `INFRA-09`. Эти notes не требуют
risk acceptance для локального infra outcome. Live GitHub checks подтверждены;
required-check configuration выполняется по одобренному DG-I7.
