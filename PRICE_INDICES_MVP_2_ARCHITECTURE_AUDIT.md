# ПРИЗМА Индексы: аудит архитектуры и план MVP 2.0

Дата аудита: 2026-08-23  
Ревизия репозитория: `0f4983202f94a1995e1b733c3a0e4062f6d7a77e`

## Статус и границы аудита

Этот документ описывает фактически найденную реализацию и предлагает поэтапный переход к MVP 2.0. На этапе аудита код приложения, маршруты, схема БД и данные не изменялись.

Термины доказательности:

- **Наблюдается** — подтверждено текущим кодом, миграцией, route definition или тестом.
- **Следует из реализации** — вывод из нескольких наблюдаемых частей.
- **Не найдено** — концепция или механизм не обнаружены в репозитории.

Ограничения плана:

- canonical существующих карточек вида `/31-02-10-140` сохраняется;
- дубли `/okpd2/31-02-10-140` не создаются;
- полный будущий классификатор не открывается для массовой индексации;
- уникальность страниц обеспечивается данными, аналитикой, классификацией и связанными рядами, а не большими шаблонными SEO-текстами;
- индексы-дефляторы Минэкономразвития и индексы Минстроя не смешиваются с данными Росстата;
- новые модули ИПЦ/ОКПД2 и широкая переработка не входят в первый implementation block.

## 1. Фактическая текущая архитектура

### 1.1. Технологический контур

**Наблюдается:** frontend — Vue 3 + TypeScript + Vue Router + Pinia + Vuetify, а не React. Backend — Laravel 12/PHP 8.2+, Sanctum, MySQL-ориентированные миграции, очереди для импорта и Blade для публичных страниц. В клиентских зависимостях присутствует ApexCharts, но публичный price-indices его не использует.

Модуль изолирован в:

- backend: `server/app/Domain/PriceIndices`;
- API routes: `server/routes/price_indices.php`, подключён из `server/routes/api.php`;
- public SSR routes: `server/routes/web.php`;
- public templates: `server/resources/views/price-indices/public`;
- private user UI: `client/src/modules/price-indices`;
- private admin UI: `client/src/modules/price-indices/admin`;
- persistence: миграции `server/database/migrations/2026_08_07_*`, `2026_08_10_*`, `2026_08_12_*`.

**Проблема документации:** `server/app/Domain/PriceIndices/README.md` всё ещё объявляет стадию `skeleton` и утверждает, что persistence/import/calculation не реализованы. Это уже не соответствует коду. API capabilities также возвращает `stage: skeleton`.

### 1.2. Публичный контур

**Наблюдается:** public-контур — полноценный server-rendered Laravel Blade, привязанный к hostname из `PRICE_INDICES_PUBLIC_URL` (по умолчанию `https://indices.prismcore.ru`). Это не CSR-страница Vue.

Текущие public routes:

| Method | URL | Обработчик | Auth |
|---|---|---|---|
| GET | `/` | `PublicIndexCatalogController` | нет |
| GET | `/sitemap.xml` | `PublicIndexSitemapController` | нет |
| GET | `/robots.txt` | `PublicIndexRobotsController` | нет |
| GET | `/{slug}` | `PublicIndexDetailController` | нет |
| ANY | `/{path}` | host-specific 404 fallback | нет |

Маршруты существуют только при непустом `price_indices.public_host` и только на соответствующем host. Slug ограничен безопасным regex. Существующий `/31-02-10-140` является основным detail URL и уже формируется `PublicIndexSlug` из кода товарной позиции.

Публичные страницы читают не произвольные live-данные импорта, а материализованный read model `statistical_public_series_pages`. Detail дополнительно получает observations строго из пары `import_id + series_id`, зафиксированной snapshot.

### 1.3. Что получает crawler без авторизации

**Наблюдается:** crawler получает готовый HTML, в котором уже присутствуют:

- `<title>`, meta description, robots и canonical;
- Open Graph title/description/url/type;
- один JSON-LD graph;
- видимые H1/H2, вводный текст и breadcrumbs;
- на catalog — карточки рядов, период, изменение и коэффициент;
- на detail — код/название, summary metrics, полная помесячная HTML-таблица, методика, поставщик, dataset, исходный файл, SHA-256, версия importer/import и ссылка на источник;
- обычные HTML-ссылки на catalog, detail и private calculator.

Выполнение пользовательского JavaScript для получения основного контента не требуется. JavaScript в layout используется для Яндекс.Метрики и цели CTA, а не для SSR-данных.

### 1.4. Приватный пользовательский контур

Все пользовательские Vue routes требуют `requiresAuth` и `requiresPriceIndices`:

| URL | Страница | Фактическое состояние |
|---|---|---|
| `/app/indices` | overview | рабочая точка входа и CTA |
| `/app/indices/new` | calculator | рабочий поиск, detail ряда, расчёт, цепочка, provenance |
| `/app/indices/calculations` | история | статическая empty-state, persistence отсутствует |
| `/app/indices/indicators` | показатели | статическая заглушка, несмотря на наличие данных |
| `/app/indices/sources` | источники | статическая заглушка, несмотря на наличие данных |

Рабочий calculator использует только backend calculation API. Он поддерживает восстановление `series`, `start`, `end` из query string, но намеренно не восстанавливает сумму и результат. Поиск debounce-ится, защищён от stale responses и работает по коду, префиксу кода или нормализованному названию.

Компоненты, которые можно переиспользовать концептуально или напрямую в private UI:

- `SeriesSearchField.vue`;
- `SelectedUserSeriesCard.vue`;
- `CalculationPeriodFields.vue`;
- `CalculationAmountField.vue`;
- `CalculationResultCard.vue`;
- `CalculationChainTable.vue`;
- `CalculationProvenancePanel.vue`;
- `CalculationSourceDrawer.vue`.

**Не найдено:** отдельный private chart ряда и рабочие history/projects/PDF/Word flows внутри price-indices.

### 1.5. Приватный admin-контур

Под `/admin/indices/*` имеются Vue pages для sources, imports, data explorer, mappings и logs. Реально разработаны upload/preview/import/publication/data explorer flows; часть визуальных страниц может быть тонкой оболочкой, но API-слой и lifecycle присутствуют.

### 1.6. Авторизация и отделение public от private

**Наблюдается:** public Blade routes не используют Sanctum и не проходят через `price_indices.enabled`, `price_indices.access` или `price_indices.user_access`. Их доступность определяется host route и наличием indexable snapshot. Поэтому public/private уже технически разделены корректно на уровне HTTP-контура.

Private API защищён `auth:sanctum`:

- `EnsurePriceIndicesAccess` — feature enabled + точная роль `admin|superadmin`;
- `EnsurePriceIndicesUserAccess` — feature enabled; при `admin_only=true` только admin/superadmin, при `false` допускает обычного authenticated user.

**Дефект будущего открытия:** `/api/indices/capabilities` находится под более строгим `price_indices.access`, а Vue guard и application menu сначала требуют успешный capabilities response. Поэтому даже при `PRICE_INDICES_ADMIN_ONLY=false` обычный пользователь сможет вызвать user series/calculation API, но Vue UI останется закрытым. Это зафиксировано существующим backend-тестом как текущее поведение, но противоречит целевой professional-модели.

### 1.7. Все `/api/indices/*`

Все перечисленные endpoints требуют Sanctum. `A` означает дополнительный admin/superadmin gate, `U` — user gate с текущей настройкой `admin_only`.

| Method | Endpoint | Gate | Назначение |
|---|---|---:|---|
| GET | `/api/indices/capabilities` | A | capability state |
| GET | `/api/indices/admin/datasets` | A | datasets list |
| POST | `/api/indices/admin/datasets` | A | create dataset |
| GET | `/api/indices/admin/datasets/{dataset}` | A | dataset detail |
| PUT | `/api/indices/admin/datasets/{dataset}` | A | update dataset |
| GET | `/api/indices/admin/datasets/{dataset}/active-import` | A | active publication |
| GET | `/api/indices/admin/sources` | A | sources list |
| POST | `/api/indices/admin/sources` | A | create source |
| GET | `/api/indices/admin/sources/{source}` | A | source detail |
| PUT | `/api/indices/admin/sources/{source}` | A | update source |
| GET | `/api/indices/admin/source-files` | A | source files list |
| POST | `/api/indices/admin/source-files/upload` | A | private upload |
| GET | `/api/indices/admin/source-files/{sourceFile}` | A | source file detail |
| POST | `/api/indices/admin/source-files/{sourceFile}/approve` | A | approve file |
| POST | `/api/indices/admin/source-files/{sourceFile}/reject` | A | reject file |
| POST | `/api/indices/admin/source-files/{sourceFile}/activate` | A | activate file |
| GET | `/api/indices/admin/source-files/{sourceFile}/download` | A | private download |
| POST | `/api/indices/admin/source-files/{sourceFile}/preview` | A | queue preview |
| POST | `/api/indices/admin/source-files/{sourceFile}/imports` | A | queue import |
| GET | `/api/indices/admin/previews/{preview}` | A | preview status |
| GET | `/api/indices/admin/previews/{preview}/result` | A | preview result |
| POST | `/api/indices/admin/previews/{preview}/retry` | A | retry preview |
| GET | `/api/indices/admin/imports` | A | import history |
| GET | `/api/indices/admin/imports/{import}` | A | import detail |
| GET | `/api/indices/admin/imports/{import}/issues` | A | import issues |
| GET | `/api/indices/admin/imports/{import}/series` | A | import series |
| GET | `/api/indices/admin/imports/{import}/observations` | A | observations |
| POST | `/api/indices/admin/imports/{import}/publish` | A | publish import |
| POST | `/api/indices/admin/imports/{import}/retry` | A | retry import |
| GET | `/api/indices/series` | U | active series search |
| GET | `/api/indices/series/{seriesPublicId}` | U | active series detail |
| POST | `/api/indices/calculate` | U | coefficient/amount calculation |

**Не найдено:** public JSON API для search/detail/observations/calculation. Public HTML читает application services напрямую, что для SSR нормально; публичный calculator потребует отдельного ограниченного transport contract либо обычного web POST.

## 2. Найденные технические и SEO-проблемы

### 2.1. REQUIRED FOR MVP

1. **Нет public search, chart и public calculator.** Catalog — только алфавитная пагинация; detail — summary + table. CTA уводит на authenticated `/app/indices/new`.
2. **Public snapshot не обновляется автоматически после publication.** `PublishStatisticalImport` переключает active import, а `price-indices:refresh-public-pages` запускается отдельной командой. Scheduler/listener/job, связывающий два события, не найден. Public HTML и private calculation могут временно использовать разные import versions.
3. **Модель public URL рассчитана на одно семейство и один ряд на slug.** `slug` глобально unique, `series_id` unique, а builder строит slug только из classifier item code. При нескольких datasets/indicators/territories одинаковый код создаёт collision или DB conflict. Collision detection внутри refresh загружает owners только текущего dataset, тогда как DB unique действует глобально.
4. **Поиск private UI ломается при публикации второго dataset.** Без `dataset_public_id` `ResolveActiveStatisticalImport::forSearch()` возвращает `dataset_required`, если active datasets больше одного. Calculator UI не предлагает dataset/family и не передаёт dataset id.
5. **Calculator не универсален по математической семантике.** Backend намеренно допускает только `monthly + previous_month + percent`; это корректный safety gate, но CPI с другой базой, fixed-base index и average prices не могут использовать текущую стратегию автоматически.
6. **Capabilities gate не соответствует будущему professional access.** При `admin_only=false` user API открывается, но capabilities/menu/router guard остаются admin-only.
7. **Нет атомарного/versioned public rollout contract.** Snapshot обновляется построчно. На большом refresh crawler может увидеть смешанное состояние, если dataset publication уже переключена, а public pages обновляются постепенно.
8. **Отсутствует публичная server-конфигурация в репозитории.** Laravel host routing для `indices.prismcore.ru` есть, но отдельный nginx/DNS/TLS deployment config этого host не найден. Это не доказывает проблему production, но deployment contract нельзя подтвердить из repo.

### 2.2. SEO/indexing

1. `sitemap.xml` содержит indexable detail pages, но не содержит catalog root.
2. Catalog page с `?page=N` имеет self-canonical и уникальные title/description, однако out-of-range page не переводится явно в 404/noindex. Стандартный paginator способен вернуть пустой 200 — потенциальный soft-404/thin page.
3. Public search отсутствует. При его добавлении query permutations нельзя индексировать: результаты поиска должны быть `noindex,follow` с canonical на стабильную landing/catalog страницу.
4. Внутренняя перелинковка ограничена цепочкой catalog → detail, detail → catalog и detail → private calculator. Нет связей parent/children classifier, соседних кодов, связанных series, family landing или source landing.
5. JSON-LD и тексты жёстко описывают «индекс цен производителей к предыдущему месяцу». Их нельзя переиспользовать для CPI, activity или average price без family-aware presenter.
6. Public responses не задают обнаруживаемые в модуле `Cache-Control`, ETag или Last-Modified. Это не блокирует индексацию, но повышает стоимость crawl и backend load.
7. Detail не имеет chart. HTML table достаточно для индексации, но пользовательская аналитическая ценность и отличие страниц ограничены summary metrics + raw history.
8. Catalog представляет только плоский список. При росте числа семейств и территорий title/H1/description перестанут точно соответствовать содержимому.

### 2.3. Технический долг и несогласованности

- domain README и capability stage устарели;
- private «Показатели» и «Источники» сообщают, что данные не импортированы/не подключены, хотя backend поддерживает published data и sources;
- «Мои расчёты» не имеет модели/endpoint сохранения;
- public snapshot aggregates специфичны для chain calculation: coefficient/change/min/max/factors; универсального presentation payload нет;
- list/search использует `%normalized_name%` без отдельного search index; для текущего bounded catalog это допустимо, для полного классификатора — нет;
- public host не проверяет `price_indices.enabled`: это может быть намеренным независимым public feature, но сейчас не задокументировано как контракт;
- отсутствуют browser E2E/crawler-level tests; имеющиеся тесты — PHPUnit feature и Vitest unit/module tests.

## 3. Что уже можно переиспользовать

### 3.1. Backend foundation

- dataset/source/source-file lifecycle и приватное хранение;
- immutable import attempts, issues, preview, retry и active-import pointer;
- dimension models: indicator, classifier item, territory, series;
- immutable observation с cell-level provenance;
- decimal-safe `DecimalMath`;
- строгие `MonthlyPeriod`/`MonthlyPeriodRange`;
- `CalculateStatisticalIndexChain` как стратегия для monthly previous-month percent series;
- active-publication resolvers и UUID-based API contracts;
- public snapshot quality statuses и deterministic slug для существующей family;
- server-rendered public views, URL helper, formatter и structured-data builder;
- idempotent public refresh command и stale-series marking.

### 3.2. Frontend foundation

- поиск ряда, выбор периода, сумма и server calculation flow;
- decimal-safe display без преобразования authoritative values во float;
- chain table и source drawer;
- provenance panel;
- request race guards и query hydration;
- shared Vue/Vuetify layout primitives для private UI.

### 3.3. SEO foundation

- host-isolated SSR;
- stable root detail slugs;
- canonical, robots meta, Open Graph;
- Dataset/WebPage/Breadcrumb/StatisticalVariable JSON-LD;
- XML escaping sitemap;
- indexability snapshot gates;
- data-derived title, description, year, periods, coefficient and extrema;
- tests на single metadata set, escaping и crawler-visible raw HTML.

## 4. Что необходимо изменить

### 4.1. REQUIRED FOR MVP

- формализовать public publication lifecycle: public read model должен однозначно соответствовать опубликованной версии и обновляться автоматически/наблюдаемо;
- добавить public search по уже indexable series, не по полному классификатору;
- добавить chart на существующую detail page, сохранив HTML table как authoritative/crawler fallback;
- дать basic public calculation без auth, переиспользуя decimal-safe calculation service и ограничивая расчёт exact snapshot scope;
- исправить capability contract до начала открытия private UI обычным пользователям;
- ввести family-aware presentation/calculation metadata без изменения поведения существующей producer-product family;
- определить collision-safe canonical path strategy, сохранив все существующие root slugs;
- расширить внутреннюю перелинковку только реально существующими series/pages;
- закрыть SEO gaps: root sitemap entry, invalid pagination, cache policy, search noindex.

### 4.2. NEXT

- добавить selective OKPD2 navigation layer и canonical classifier mapping;
- добавить dataset/family selector в private search;
- реализовать рабочие private indicators/sources pages;
- добавить сохранение расчётов и history API/model;
- подготовить CPI national/regional importers и calculation profiles отдельными блоками;
- добавить family landing pages только после появления достаточного реального содержимого.

### 4.3. LATER

- producer prices by economic activity/OKVED2;
- average consumer prices и отдельная direct-value аналитика;
- projects, batch tools, PDF/Word и professional reports;
- расширенный full-text/search projection;
- отдельные yearly/region pages только при доказанной пользовательской и индексной ценности.

## 5. Предлагаемая целевая архитектура

### 5.1. Контуры

1. **Ingestion/admin write model** — существующие private admin APIs, importer registry, immutable imports и active pointers.
2. **Published statistical core** — dataset/indicator/classifier/territory/series/observation и explicit calculation profile.
3. **Public read model** — материализованные indexable pages, summary/chart points, exact publication identity, canonical path, internal-link projection.
4. **Public delivery** — Blade SSR + minimal progressive enhancement; основное содержание остаётся в HTML.
5. **Professional application** — Vue/Sanctum, saved calculations/projects/reports поверх того же published core.

Public и private должны переиспользовать domain calculations, но иметь разные transport policies:

- public: только published/indexable scope, throttle, без внутренних numeric IDs/paths, без mutation;
- private: authenticated active publication, расширенный provenance, позднее persistence/reporting;
- admin: import lifecycle и inspection.

### 5.2. Public route strategy

Рекомендуемый route map — ориентир, а не требование создать пустые страницы:

| Stage | URL | Indexing | Назначение |
|---|---|---|---|
| REQUIRED | `/` | index | единый SSR search/catalog текущих опубликованных series |
| REQUIRED | `/{existing-slug}` | index | сохранить producer-product detail canonical |
| REQUIRED | `/?q=...` | noindex,follow | SSR search results, canonical `/` |
| NEXT | `/producer-prices/` | index, когда есть контент | family landing |
| NEXT | `/producer-prices/products/` | index, когда есть контент | product catalog landing |
| NEXT | `/okpd2/` | index, selective | навигация/поиск по реально покрытым веткам |
| LATER | `/ipc/`, `/ipc/{year}/`, `/ipc/regions/` | только после данных | CPI family |
| LATER | `/producer-prices/activities/` | только после данных | activity family |

Правила:

- `/31-02-10-140` остаётся canonical навсегда;
- `/okpd2/31-02-10-140` не создаётся как вторая карточка той же series;
- classifier landing ссылается на существующую root detail page;
- новые canonical paths создаются только для новых сущностей, которым root-slug scheme недостаточна;
- redirects вводятся только для реально существовавших URL, не для воображаемой структуры.

### 5.3. Public basic calculator

Public calculation должен вызывать тот же decimal-safe strategy, но разрешать только series, найденную через indexable public page. Вход: public page/series identity, start, end, optional base amount. Расчёт обязан использовать `import_id + series_id` snapshot, отображаемый на странице, а не молча переключаться на новый active import.

Результат:

- coefficient, change/adjusted amount;
- interval semantics;
- used factors;
- source publication identity;
- контролируемые ошибки gap/out-of-range/unsupported.

Transport можно реализовать как throttled public endpoint с узким response contract. CSRF/CORS/cache policy должны быть определены явно. Сумма и результат не должны попадать в индексируемый canonical URL.

### 5.4. Universal calculator strategy

Не следует убирать текущий eligibility guard. Вместо одного универсального алгоритма нужен strategy dispatcher по явному calculation profile:

- `chain_previous_period_percent` — текущая реализация; подходит и другим series только при тех же dimensions;
- `ratio_of_index_levels` — fixed-base index, если методология допускает отношение уровней;
- `ratio_of_absolute_values` — average price/end-to-start comparison;
- `not_calculable` — series доступна для chart/table, но calculator отключён.

Каждая strategy должна объявлять допустимые frequency/comparison_basis/unit, interval semantics, minimum observations и output capabilities. CPI нельзя автоматически считать текущим chain только по слову «индекс».

## 6. Предлагаемые изменения моделей данных

### 6.1. Что сохраняется без изменений

Существующие datasets, sources, files, imports, indicators, classifier items, territories, series и observations уже дают хорошую additive foundation. Observation provenance и active-import pointer не следует переписывать.

### 6.2. Ограничения текущей модели

- classifier item принадлежит dataset; общей canonical сущности ОКПД2/ОКВЭД2 нет;
- `classifier_code` — строка, но нет classifier/version registry;
- series имеет ровно одну classifier dimension и одну territory dimension;
- unit/comparison_basis/frequency — свободные строки без calculation profile;
- public page имеет один global slug и chain-specific aggregate columns;
- calculation — transient result, persistent entity отсутствует;
- provenance существует на observation/import/source-file, но public summary не хранит отдельный immutable presentation payload.

### 6.3. Additive изменения для нескольких families

**REQUIRED FOR MVP:** по возможности обойтись без миграции и сначала ввести application-level `IndicatorFamily`/`CalculationProfile` mapping для текущего dataset. Если значение начинает влиять на публикацию и расчёт, следующим отдельным DB block добавить явное поле `calculation_profile` на indicator или series с безопасным backfill текущего значения.

**NEXT:** добавить canonical classifier registry отдельно от dataset-local items:

- `statistical_classifiers`: code, version, provider, valid dates;
- `statistical_classifier_nodes`: classifier_id, code, name, parent, validity, metadata;
- `statistical_classifier_item_mappings`: dataset-local item → canonical node, mapping kind/status/evidence.

Такой bridge сохраняет текущие imported codes и позволяет отдельно моделировать:

- точный OKPD2 match;
- Rosstat local `.АГ` item;
- aggregate/derived group;
- ambiguous/unmapped item.

Не следует делать `dataset_id` nullable или массово переносить существующие rows в рамках смешанного блока.

**NEXT/LATER:** расширить public read model additive полями `canonical_path`, `family`, `presentation_json`/`summary_json`, `calculation_profile`, `publication_key`. Существующий `slug` и chain columns остаются для backward compatibility до отдельной миграции consumers.

### 6.4. Пригодность по семействам

| Семейство | Core model | Import | Public snapshot | Calculator |
|---|---|---|---|---|
| Producer prices by product | подходит и работает | реализован | реализован | реализован для monthly previous-month % |
| CPI | базовые dimensions подходят | не реализован | presenter жёстко PPI, требует family-aware слой | только если metadata точно соответствует strategy |
| Producer prices by activity | базовые dimensions подходят с OKVED2 mapping | не реализован | slug/collision/presenter требуют изменения | зависит от comparison basis |
| Average prices | observation decimal подходит | не реализован | chain-specific aggregates не подходят | нужна ratio/absolute-value strategy |

**Следует из реализации:** core schema в основном многосемейная, но importer registry, public read model, routing, search default и calculator policy пока одно-семейные.

## 7. Public/private route strategy

### 7.1. REQUIRED FOR MVP

- сохранить host-isolated Laravel public SSR;
- не переносить public pages в authenticated Vue shell;
- оставить private routes `/app/indices/*` и admin routes `/admin/indices/*`;
- вынести capabilities из admin-only gate либо вернуть отдельные `user_access`/`admin_access`, чтобы router guard отражал backend policy;
- public endpoint расчёта отделить от `/api/indices/calculate` и ограничить public snapshot scope;
- добавить public search как SSR GET, а не как пустой SPA shell.

### 7.2. NEXT

- private user API должен требовать explicit dataset/family при наличии нескольких active datasets;
- capabilities должен возвращать доступные families/datasets или отдельный discovery endpoint;
- новые family routes подключать только вместе с read model, presenter и реальными данными.

### 7.3. Backward compatibility contract

- current detail slugs/status/canonical сохраняются;
- existing `/api/indices/series*` и `/api/indices/calculate` response fields не переименовываются;
- current calculation interval `(start,end]` сохраняется для current strategy;
- public source/provenance fields не ослабляются;
- legacy public snapshot остаётся serving fallback до успешного построения новой публикации.

## 8. SEO/indexing strategy

### 8.1. REQUIRED FOR MVP

- индексировать только pages с реальной published series и пройденными quality gates;
- оставить detail table в SSR HTML даже после добавления chart;
- добавить catalog root в sitemap;
- out-of-range pagination возвращать 404 или `noindex`;
- search/filter query pages — `noindex,follow`, canonical на стабильную landing;
- structured data собирать family-aware, без ложной PPI методики;
- `lastmod` связывать с изменением published content, а не с каждым crawl;
- добавить разумный public cache policy с invalidation/versioning после snapshot refresh;
- мониторить 404/5xx, snapshot freshness и число indexable/non-indexable pages.

### 8.2. NEXT

- family landing pages с собственными DataCatalog/Dataset descriptions;
- selective classifier pages только для веток, имеющих достаточное число опубликованных series;
- related links: parent/children/siblings только по indexable page projection;
- source/provider landing при наличии нескольких наборов и полезного содержимого;
- XML sitemap index при реальном превышении практического размера одного sitemap.

### 8.3. Запрещённые shortcut-решения

- генерация тысяч classifier pages без observations;
- indexable query/search permutations;
- тексты, различающиеся только подстановкой названия;
- canonical на `/okpd2/{slug}` для уже существующего `/{slug}`;
- schema.org claims, не подтверждённые dataset metadata;
- client-only table/chart как единственный источник данных для crawler.

## 9. Порядок внедрения

### REQUIRED FOR MVP

1. Publication/read-model consistency и observability.
2. Capability contract separation для user/admin.
3. Public search по indexable snapshot + SEO rules для query pages.
4. Public detail chart с SSR table fallback.
5. Public snapshot-scoped basic calculator.
6. Family-aware presenter/calculation profile и collision-safe path groundwork.
7. Internal links и sitemap/cache hardening.

### NEXT

8. Canonical OKPD2 registry + mapping bridge без массовой индексации.
9. Private multi-dataset discovery/selector.
10. Working private indicators/sources.
11. Saved calculations/history.
12. CPI importer и отдельная public family после полного data/SEO review.

### LATER

13. Regional CPI.
14. Producer prices by activity/OKVED2.
15. Average prices.
16. Projects, batch calculation, PDF/Word and professional reports.

Каждый пункт должен быть отдельным bounded block с собственными acceptance criteria и rollback plan. Публикацию нового family нельзя смешивать с migration классификатора и redesign всех public pages.

## 10. Файлы и модули первого этапа

Рекомендуемый первый implementation block описан в разделе 13. Для него ожидается следующий узкий scope.

### Backend

- `server/app/Domain/PriceIndices/Application/Services/PublishStatisticalImport.php` — оставить atomic switch без тяжёлой синхронной генерации;
- `server/app/Domain/PriceIndices/Application/Services/PublishStatisticalImportForAdmin.php` — orchestration point для post-commit refresh dispatch;
- новый узкий queued job в `server/app/Domain/PriceIndices` — dataset-scoped refresh после commit;
- `server/app/Domain/PriceIndices/Application/Services/RefreshPublicStatisticalSeriesPages.php` — использовать существующий dataset selector; менять только при обнаружении необходимого idempotency/failure contract;
- при необходимости один listener/event или dispatch adapter; не вводить общий event bus refactor;
- logging/metrics с dataset public identity, import public identity, outcome и failure.

### Tests

- `server/tests/Feature/PriceIndices/PriceIndicesImportPublicationTest.php`;
- `server/tests/Feature/PriceIndices/RefreshPublicSeriesPagesTest.php`;
- новый focused test на post-commit dispatch, idempotent retry и сохранение старого public snapshot при failure.

### Явно вне первого этапа

- migrations и backfills;
- новые routes и public UI;
- OKPD2/CPI/OKVED2 importers;
- изменение canonical/slug;
- universal calculator;
- изменение существующего `/api/indices/*` contract.

## 11. Риски миграции и обратной совместимости

| Риск | Последствие | Мера |
|---|---|---|
| Изменение slug/path | потеря накопленного canonical | immutable mapping для существующих slugs, regression test `/31-02-10-140` |
| Два datasets с одинаковым item code | collision/ошибка refresh | canonical_path policy и global collision test до второй publication |
| Active import переключён раньше snapshot | public/private показывают разные версии | post-commit refresh, freshness status, old-snapshot serving, monitoring |
| Частичный snapshot refresh | смешанная версия catalog | publication key/staged generation в отдельном block |
| Обобщение calculator без методологии | неверный коэффициент | explicit strategy eligibility, default deny |
| Массовый classifier import | crawl bloat/thin pages | canonical registry отдельно от public visibility |
| Изменение classifier ownership | сломанные FK/import history | additive mapping tables, не переносить текущие items сразу |
| Открытие private user access | menu/API policy расходятся | единый capability contract + auth matrix tests |
| Public calculation abuse | нагрузка | published-only lookup, throttle, bounded period, response cache where safe |
| Family-aware SEO regression | ложные title/schema | typed presenter + snapshot fixture per family |

Rollback первого этапа должен быть простым: отключить dispatch нового refresh job; существующая manual command и текущий public snapshot продолжают работать. Никакой destructive migration не нужна.

## 12. Предлагаемые тесты

### 12.1. Уже хорошо покрыто

Существующие PHPUnit tests покрывают schema/invariants, lifecycle source files/imports, preview/import/publication, active pointers, user/admin auth, decimal calculation, gaps, provenance, query bounds, public snapshot eligibility, refresh idempotency/performance, SSR HTML, metadata, canonical, JSON-LD, sitemap/robots, escaping и Яндекс.Метрику.

Vitest покрывает application menu/capabilities, API adapters, calculator parsing/formatting/stale requests и admin workflow helpers.

### 12.2. REQUIRED FOR MVP

- publication → after-commit public refresh dispatch;
- refresh retry/idempotency/failure visibility;
- snapshot freshness: detail/calculation version identity;
- ordinary authenticated user matrix при `admin_only=false`: capabilities, route guard, menu, series, calculate;
- two-active-dataset search: explicit dataset selection и стабильная controlled error;
- same item code across datasets/indicators/territories: global route collision contract;
- exact legacy URL/canonical `/31-02-10-140` and absence of duplicate `/okpd2/31-02-10-140`;
- catalog root in sitemap;
- out-of-range pagination is not indexable 200;
- SSR search: escaping, bounded filters, noindex/canonical, empty result;
- chart data equals SSR table observations and exact snapshot import;
- public calculation auth-free, throttled, published/indexable-only and snapshot-scoped;
- public calculator rejects gaps, unsupported series, bad periods and excessive ranges;
- query-count/time bounds for catalog search, detail chart and public calculation;
- cache headers/invalidation after snapshot change.

### 12.3. NEXT/LATER

- classifier version/tree/mapping constraints and ambiguous mappings;
- only mapped + observed branches become indexable;
- one fixture per family for title/H1/description/JSON-LD/calculation profile;
- fixed-base and absolute-value strategy golden cases;
- browser E2E: anonymous crawler, mobile/desktop chart/table, calculator progressive enhancement, private auth redirect;
- accessibility checks for search, chart alternative, focus, errors, loading and keyboard navigation.

**Не найдено:** Playwright/Cypress/browser E2E configuration. Её добавление следует делать отдельным test-infrastructure block, не маскируя unit/feature gaps.

### 12.4. Baseline, выполненный при аудите

- `npm.cmd run test:unit -- src/modules/price-indices`: **9 files, 73 tests passed**.
- `php artisan test tests/Feature/PriceIndices` внутри локального app-container: **46 passed, 150 failed**, но все показанные DB-dependent failures имеют одну инфраструктурную причину — `SQLSTATE[HY000] [1044] Access denied for user 'smeta_user'@'%' to database 'smeta_test'`. Pure calculation/parser/formatter/validator tests, не требующие test DB, продолжили выполняться. Этот запуск не подтверждает backend regression; полный DB-dependent baseline остаётся **не проверен**, пока test database/grants не будут настроены отдельно.

## 13. Приоритеты и рекомендуемая первая задача

### REQUIRED FOR MVP

- согласованная published version;
- public SSR search/chart/basic calculator;
- stable current canonicals;
- family-aware, default-deny calculation and presentation contracts;
- controlled indexing and internal links;
- исправленный user/admin capability split;
- focused backend/frontend/SEO tests.

### NEXT

- canonical OKPD2 mapping/navigation;
- multi-dataset private UI;
- saved calculations;
- CPI family после отдельного анализа источника и методологии.

### LATER

- regional CPI, OKVED2/activity series, average prices;
- projects, batch tools, PDF/Word and professional reporting;
- масштабирование search/index architecture по измеренной нагрузке.

### Рекомендуемая первая implementation task

**«Автоматически и наблюдаемо синхронизировать public snapshot после успешной публикации dataset».**

Цель: после commit `PublishStatisticalImport` поставить idempotent dataset-scoped refresh job, не задерживая admin request, не очищая public table перед refresh и сохраняя текущую команду как recovery path.

Acceptance criteria:

1. job dispatch происходит только после успешного DB commit;
2. rollback/failed publication не создаёт job;
3. повторный job безопасен и даёт тот же snapshot;
4. failure не выполняет предварительный truncate/delete и пишет контролируемый log/metric с public IDs;
5. current manual `price-indices:refresh-public-pages` сохраняется;
6. canonical и API contracts не меняются;
7. targeted publication/refresh tests проходят.

Это небольшой блок без schema/UI changes. Он устраняет главный риск рассинхронизации public/private данных и создаёт безопасную основу для следующего отдельного блока — SSR search на текущих indexable producer-product pages.

Известное ограничение этого первого блока: существующий refresh обновляет rows последовательно, поэтому полная атомарная смена всего dataset snapshot в нём не достигается. Staged generation/publication key для устранения смешанного состояния остаётся отдельным REQUIRED block и не должна скрыто расширять первую задачу.
