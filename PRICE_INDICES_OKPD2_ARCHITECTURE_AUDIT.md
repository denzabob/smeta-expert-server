# PriceIndices: аудит архитектуры канонического ОКПД2

Дата аудита: 2026-08-24
Режим работы: analysis-only
Целевой контур: `server/app/Domain/PriceIndices` и связанные таблицы/маршруты

## Goal summary

Цель — спроектировать добавление официального, версионируемого дерева ОКПД2 в PriceIndices без смешения двух разных сущностей:

- локальной позиции классификатора из набора статистических данных Росстата;
- канонического узла официальной редакции ОКПД2.

Главный инвариант:

> `statistical_classifier_items` остаётся dataset-local сущностью. Канонический узел ОКПД2 хранится отдельно, а связь между ними всегда явная, версионная и проверяемая.

В рамках аудита не выполняются импорт, миграции, изменение API, создание публичных маршрутов или массовая SEO-публикация.

### Ключевой вывод

На дату аудита production-активной должна считаться редакция с изменениями по №145/2026. Изменение №146/2026 известно, но его дата введения перенесена приказом Росстандарта от 06.08.2026 №907-ст с 01.09.2026 на 01.01.2027. Поэтому модель обязана различать «последняя известная редакция», «утверждена», «вступает в силу» и «активирована в приложении».

Файл `okpd.xlsx` и сайт classifikators.ru пригодны только как reference/fixture. Они не могут определять production-версию, даты действия или состав активного классификатора.

## Методика и границы

### Observed

- Исследованы текущие миграции, доменные модели, импорт статистических индексов, публичные snapshot-страницы, поиск, sitemap и тесты PriceIndices.
- Выполнены read-only запросы к локальной MariaDB для оценки фактических объёмов и формы кодов.
- Проверены официальная страница классификаторов Росстата, официальный ZIP ОКПД2 и карточка изменения №146/2026 Росстандарта.
- Reference-XLSX исследован как ZIP/XML fixture, без импорта в приложение.

### Inferred

- Канонический классификатор должен иметь собственный import/publication lifecycle, независимый от импорта статистических рядов.
- Активная версия должна задаваться явным указателем, а не вычисляться как максимальный номер или самая новая дата загрузки.

### Missing / not found

- В репозитории нет канонической модели ОКПД2, истории его версий, официального classifier-importer и явных mappings.
- В официальном консолидированном DOCX нет достаточной построчной истории `valid_from`, `valid_to`, replacement/deletion для каждого узла.
- Предоставленный XLSX отсутствовал среди локальных attachments; для исследования fixture был получен тот же файл по указанной пользователем reference-ссылке. Это не меняет его неавторитетный статус.

---

## 1. Текущая архитектура

### Observed

Текущий контур состоит из четырёх связанных слоёв:

1. Dataset/source/import:
   - `statistical_datasets` хранит `classifier_code`, но не имеет связи с каноническим классификатором;
   - `statistical_sources`, `statistical_source_files` и `statistical_imports` описывают конкретные статистические XLSX и их импорт;
   - `statistical_dataset_active_imports` указывает опубликованный импорт набора данных.
2. Dataset-local classification:
   - `statistical_classifier_items` уникальны по `(dataset_id, classifier_code, item_code)`;
   - содержат `parent_item_id`, имя, локальные даты и metadata;
   - не являются доказательством наличия кода в официальной действующей редакции ОКПД2.
3. Statistical series:
   - `statistical_series` напрямую ссылается на локальный `classifier_item_id`;
   - сервисы разрешения контролируют принадлежность series/item одному dataset.
4. Public snapshots:
   - `statistical_public_series_pages` ссылается на dataset, import, series, локальную classifier item и source file;
   - slug формируется из локального `item_code`;
   - `is_indexable` определяется качеством статистического snapshot, а не статусом официального классификатора.

Текущий parser `CommodityCodeParser` различает цифровой код и локальный код с суффиксом `.АГ`. `ProducerPriceIndicesByProductImporter` сохраняет цифровые строки как `okpd2_based`, но это название источника данных, а не подтверждённая связь с официальным узлом.

`ResolveClassifierItem` переиспользует позицию по локальной идентичности и сообщает о различии нормализованного имени, но не выполняет официальную валидацию. `StatisticalSeriesAdminResource` при отсутствии metadata фактически предполагает цифровой kind.

Публичные маршруты сейчас:

- `/`;
- `/producer-prices`;
- `/producer-prices/products`;
- `/sitemap.xml` и `/robots.txt`;
- `/{slug}`;
- `/{slug}/calculate`.

Маршрута `/okpd2/*` нет. `ListPublicIndexPages` и `ListPublicIndexSitemapEntries` работают только с уже созданными индексируемыми statistical snapshots.

### Inferred

- Добавление внешнего FK из `statistical_classifier_items` прямо на «текущий ОКПД2» разрушило бы историю при переключении редакции.
- Повторное использование `StatisticalImporterRegistry` для канонического ОКПД2 смешало бы два lifecycle: статистический XLSX и нормативный классификатор.
- Текущий `PublicIndexFormatter::classifierLabel` может назвать цифровую локальную позицию «ОКПД2» без подтверждённого mapping. Это риск достоверности, но его исправление не входит в первый блок.

### Missing / not found

- Нет источника истины для утверждения «этот локальный item соответствует официальному узлу редакции X».
- Нет отдельной модели future-effective версии.
- Нет механизма classifier rollback независимо от dataset publication.

### Затронутые файлы и директории

Текущая реализация, на которой основан аудит:

- `server/database/migrations/2026_08_07_000001_create_statistical_datasets_table.php`;
- `server/database/migrations/2026_08_10_000002_create_statistical_classifier_items_table.php`;
- `server/database/migrations/2026_08_10_000004_create_statistical_imports_table.php`;
- `server/database/migrations/2026_08_10_000006_create_statistical_series_table.php`;
- `server/database/migrations/2026_08_10_000008_create_statistical_dataset_active_imports_table.php`;
- `server/database/migrations/2026_08_12_000001_create_statistical_public_series_pages_table.php`;
- `server/app/Domain/PriceIndices/Infrastructure/Import/CommodityCodeParser.php`;
- `server/app/Domain/PriceIndices/Infrastructure/Import/ProducerPriceIndicesByProductImporter.php`;
- `server/app/Domain/PriceIndices/Application/Services/ResolveClassifierItem.php`;
- `server/app/Domain/PriceIndices/Application/Services/BuildPublicStatisticalSeriesSnapshot.php`;
- `server/app/Domain/PriceIndices/Application/Services/RefreshPublicStatisticalSeriesPages.php`;
- `server/app/Domain/PriceIndices/Application/Services/ListPublicIndexPages.php`;
- `server/app/Domain/PriceIndices/Application/Services/ListPublicIndexSitemapEntries.php`;
- `server/app/Domain/PriceIndices/Application/Support/PublicIndexFormatter.php`;
- `server/routes/web.php`;
- `server/tests/Feature/PriceIndices`.

---

## 2. Официальный источник и provenance

### Authoritative production chain

Рекомендуемая цепочка доверия:

1. Росстандарт — нормативное утверждение классификатора и изменений.
2. Официальная карточка классификатора/изменения Росстандарта — номера приказов и юридические даты.
3. Росстат — официальный распространитель консолидированного ОКПД2 для машинного импорта.
4. Неофициальные сайты/XLSX — только reference, fixture и UX research.

На момент аудита страница Росстата `https://rosstat.gov.ru/classification` указывает ОК 034-2014 (КПЕС 2008) с изменениями по №145/2026. Официальный download URL:

`https://rosstat.gov.ru/storage/mediabank/OKPD2.zip`

Исследованный ZIP:

| Поле | Значение |
|---|---|
| Content-Type | `application/zip` |
| Размер | 1 041 301 byte |
| Last-Modified | 2026-08-04 07:14:38 GMT |
| ETag | `"6a71915e-fe395"` |
| SHA-256 | `71A35241C4318C1FFBE4B47FEFB5C47CE34BD1EA24A6B58661ACD289EA91FC46` |
| Содержимое | `TIZ_OKPD2_1.docx`, `TIZ_OKPD2_2.docx` |

URL является стабильным, но содержимое по нему изменяемо. Production-import обязан сохранять каждый полученный artifact неизменно и идентифицировать его hash, а не URL.

Официальная карточка изменения №146/2026 Росстандарта подтверждает перенос даты введения приказом №907-ст на 01.01.2027. Generic-поле карточки и текст примечания могут описывать разные события, поэтому parser не должен слепо переносить одно поле в `effective_from`.

### Проверка официального snapshot №145/2026

После чтения WordprocessingML официального ZIP подтверждены:

- 21 раздел A–U;
- 20 961 цифровая позиция;
- 20 982 узла вместе с разделами;
- уровни: 88 классов, 271 подкласс, 619 групп, 1 463 подгруппы, 3 213 видов, 8 401 категория, 6 906 подкатегорий.

Распознанные маски:

| Уровень | Маска |
|---|---|
| class | `XX` |
| subclass | `XX.X` |
| group | `XX.XX` |
| subgroup | `XX.XX.X` |
| type | `XX.XX.XX` |
| category | `XX.XX.XX.XX0` |
| subcategory | `XX.XX.XX.XXX` |

У 532 кодов формы subcategory нет отдельного существующего category-родителя, но существует type-родитель. Следовательно, parent надо определять как ближайшего реально существующего официального предка после полного parse; создавать синтетические category-узлы нельзя.

Контрольная цепочка `31.02.10.140`:

`C → 31 → 31.0 → 31.02 → 31.02.1 → 31.02.10 → 31.02.10.140`

Официальное имя: «Наборы кухонной мебели». В snapshot №145/2026 это category без дочернего узла.

### Reference-XLSX

Исследованный файл с classifikators.ru:

| Поле | Значение |
|---|---|
| URL | `https://classifikators.ru/assets/downloads/okpd/okpd.xlsx` |
| Размер | 703 894 byte |
| SHA-256 | `720DDDC3555F6D840E116C942EC6362E218F61C93DBA0C8C368F6179CBC0ECBA` |
| Рабочие листы | 1 заполненный + 2 пустых |
| Строки данных | 20 979 уникальных кодов |
| Состав | 21 раздел + 20 958 цифровых позиций |
| Служебная дата | подготовлен 12.08.2026 |

Столбцы заполненного листа: порядковый номер, код, название; ещё три пустых столбца. В строке 2 файл заявляет изменение №146/2026 и ввод 01.09.2026 по приказу №782-ст — дату, уже перенесённую приказом №907-ст.

Допустимое применение fixture:

- тесты извлечения строк XLSX;
- проверка формы кодов и поиска;
- сверка порядка/объёма как диагностический сигнал;
- UX reference.

Запрещённое применение:

- production publication;
- определение активной редакции или `effective_from`;
- автоматическое подтверждение mappings;
- доказательство официального имени/состава.

### Обязательная provenance-модель

Источник должен иметь trust tier:

- `official_authoritative` — artifact с официального allow-listed домена;
- `operator_official_upload` — загруженная оператором копия официального документа с обязательной ручной аттестацией;
- `reference_fixture` — неавторитетный тестовый/reference artifact.

Publication должна fail closed для `reference_fixture`.

Скачивание production-artifact:

- только allow-listed HTTPS host;
- строгая TLS-проверка;
- фиксирование конечного URL после redirects;
- MIME/signature/ZIP safety checks;
- immutable storage;
- SHA-256, размер, ETag, Last-Modified и время получения;
- parser code version;
- ссылка на нормативное основание редакции.

В ходе аудита локальный `curl` потребовал ослабления TLS только для исследовательского чтения. Это не должно переноситься в production.

---

## 3. Каноническая схема

### 3.1 `statistical_classifiers`

Идентичность семейства классификатора:

- `id`;
- `code`, unique, например `okpd2`;
- `standard_code`, например `OK 034-2014 (KPES 2008)`;
- `name`;
- `issuing_authority` = Rosstandart;
- `responsible_body`;
- `official_distributor` = Rosstat;
- timestamps.

Орган утверждения, ответственный орган и распространитель нельзя сводить в одно неоднозначное поле `provider`.

### 3.2 `statistical_classifier_source_files`

Неизменяемый artifact:

- `classifier_id`;
- `trust_tier`;
- `source_page_url`, `download_url`, `resolved_url`;
- `original_filename`, `storage_disk`, `storage_path`;
- `mime_type`, `size_bytes`, `sha256`;
- `etag`, `last_modified_at`, `downloaded_at`;
- заявленный label редакции;
- metadata нормативного документа;
- uploader/attestor при ручной загрузке.

Минимальная уникальность: `(classifier_id, sha256)`.

### 3.3 `statistical_classifier_imports`

Отдельная попытка parse/validate:

- `classifier_id`, `source_file_id`;
- `attempt`;
- `status`: queued, parsing, validating, ready, failed;
- `parser_code`, `parser_version`;
- counters и validation summary;
- structured error;
- actor и timestamps.

Эта таблица не должна использовать существующий registry статистических XLSX.

### 3.4 `statistical_classifier_versions`

Immutable snapshot редакции:

- `classifier_id`;
- `classifier_import_id`;
- `version_label`, например `145/2026`;
- `source_revision` или sequence;
- `approved_at`;
- `source_published_at`;
- `effective_from`;
- `effective_to` nullable;
- `status`: ready, scheduled, published, superseded;
- `node_count` и diff summary;
- publication timestamps/actor.

Уникальность label сама по себе недостаточна. Один label с новым hash требует явного решения о source revision, а не тихого overwrite.

### 3.5 `statistical_classifier_active_versions`

Явный transactional pointer:

- unique `classifier_id`;
- `classifier_version_id`;
- `activated_at`, `activated_by`;
- activation reason.

Нельзя вычислять active как `MAX(version_label)` или «последний import». Activation разрешена только если официальный provenance подтверждён и `effective_from <= as_of_date`.

### 3.6 `statistical_classifier_nodes`

Узлы immutable version:

- `classifier_version_id`;
- `code`;
- `name`, `normalized_name`;
- `semantic_level`;
- `formal_depth`;
- nullable `parent_node_id`;
- `source_order`;
- `notes_text`;
- `metadata_json`;
- timestamps.

Ограничения:

- unique `(classifier_version_id, code)`;
- parent принадлежит той же версии;
- дерево ациклично;
- parent существует или узел является разделом/root;
- код не нормализуется с потерей значимых точек;
- никакого `is_active` на node: активность следует из version pointer.

Консолидированный DOCX не доказывает индивидуальные даты действия каждого узла. Нельзя массово присваивать всем nodes дату публикации файла как `valid_from`.

### 3.7 `statistical_classifier_item_mappings`

Явная связь локальной позиции и канонической версии:

- `statistical_classifier_item_id`;
- `classifier_version_id`;
- nullable `classifier_node_id`;
- `mapping_type`;
- `review_status`;
- `method`;
- nullable `confidence`;
- `evidence_json`;
- reviewer/confirmed timestamps.

Уникальность: `(statistical_classifier_item_id, classifier_version_id)`. Если `classifier_node_id` задан, он обязан принадлежать той же версии.

Mappings хранятся по версиям. Решение для №145/2026 не переносится молча на №146/2026.

### Почему существующие таблицы не расширяются напрямую

`statistical_classifier_items` — часть импортированного dataset и может содержать локальные агрегаты, исторические коды и провайдерские обозначения. Канонические nodes — нормативный immutable snapshot. У них разные identity, provenance, lifecycle и правила публикации.

---

## 4. Импорт и versioning

### Lifecycle

1. Discover: получить официальный classifier page и нормативные карточки.
2. Acquire: скачать artifact с allow-listed host и строгим TLS.
3. Preserve: сохранить immutable bytes и provenance.
4. Parse: разобрать snapshot вне publication transaction.
5. Validate: проверить версию, эффективную дату, структуру, counts, uniqueness, hierarchy и контрольные узлы.
6. Stage: создать `ready` или `scheduled` version без изменения active pointer.
7. Diff: сравнить полный snapshot с активной версией.
8. Publish: отдельной командой атомарно переключить pointer.
9. Rollback: вернуть pointer на предыдущую immutable version без переписывания nodes.

### Full snapshot и incremental update

Каждая редакция хранится как полный immutable snapshot. «Incremental» означает вычисленный diff между двумя snapshots, а не update/delete существующих nodes на месте.

Преимущества:

- воспроизводимость исторического mapping;
- быстрый rollback;
- прозрачная проверка additions/renames/moves/removals;
- отсутствие временно смешанного дерева.

### Effective-date policy

Хранить отдельно:

- `downloaded_at` — когда artifact получен;
- `approved_at` — когда изменение утверждено;
- `source_published_at` — когда материал опубликован;
- `effective_from` — юридическая дата начала действия;
- `activated_at` — когда приложение переключено.

Для изменения №146/2026 допустимо заранее создать только `scheduled` snapshot из официального источника. До 2027-01-01 он не может стать active. Third-party дата 01.09.2026 не проходит authoritative validation.

Activation command принимает явный `as_of_date` и:

- проверяет trust tier и нормативную ссылку;
- запрещает future-effective version;
- запрещает unresolved fatal validation issues;
- не запускает автоматически статистический import или public snapshot refresh;
- пишет audit trail.

### Идемпотентность и конфликт

- тот же classifier + SHA-256 возвращает существующий source artifact;
- повторный parse того же artifact не создаёт вторую version;
- тот же version label с другим hash останавливается как conflict/source revision;
- изменение URL без изменения hash не создаёт новую version;
- failed import не меняет active pointer;
- parser upgrade создаёт новую попытку и сравнимый validation result, но не перезаписывает опубликованную version.

### Fatal validation

Publication блокируют:

- неофициальный `reference_fixture`;
- неразрешённая версия/effective date;
- duplicate code с разным содержимым;
- неизвестная форма кода;
- невозможный или cross-version parent;
- cycle;
- неожиданно большое изменение counts без ручного подтверждения;
- отсутствие контрольных sections/nodes;
- несовпадение заявленной версии с официальным нормативным источником.

---

## 5. Mapping локальных позиций к ОКПД2

### Mapping types

| Тип | Смысл | Канонический node |
|---|---|---|
| `exact` | Код и подтверждённая семантика совпадают | required |
| `parent_aggregate` | Локальная позиция сознательно сопоставлена более широкому официальному предку | required |
| `local_rosstat` | Локальный агрегат Росстата, не являющийся узлом ОКПД2 | null |
| `ambiguous` | Есть несколько трактовок или код/name conflict | nullable |
| `unmapped` | Надёжного соответствия нет | null |

Review statuses:

- `proposed`;
- `needs_review`;
- `confirmed`;
- `rejected`.

Только `confirmed` mapping активной версии может влиять на публичное утверждение «ОКПД2».

### Строгая первичная стратегия

1. Код `.АГ` → `local_rosstat`, без canonical node.
2. Точный code + точное normalized name → детерминированный кандидат `exact`.
3. Точный code + отличающееся normalized name → `ambiguous / needs_review`.
4. Цифровой код отсутствует в официальном snapshot → `unmapped` или `ambiguous`; возможный parent показывается только как предложение.
5. Fuzzy name search никогда автоматически не подтверждает mapping.
6. Наличие дочерних узлов у точного официального кода не превращает exact mapping в `parent_aggregate`. Этот тип нужен только для заведомо более широкого, lossy сопоставления.

### Фактический профиль локальных данных

Read-only срез локальной БД:

| Метрика | Значение |
|---|---:|
| `statistical_classifier_items` | 1 327 |
| statistical series | 1 327 |
| public series pages | 1 327 |
| indexable public pages | 1 300 |
| `rosstat_local_ag` / `.АГ` | 76 |
| indexable `.АГ` pages | 75 |
| цифровые локальные коды | 1 251 |

Сопоставление цифровых кодов с официальным snapshot №145/2026:

| Результат | Количество |
|---|---:|
| Код найден | 1 245 |
| Код + normalized name совпали | 1 214 |
| Код найден, normalized name отличается | 31 |
| Код не найден | 6 |

Шесть цифровых локальных кодов, отсутствующих в текущем официальном snapshot:

- `10.73.11.130` — Лапша;
- `10.73.11.140` — Изделия макаронные фигурные;
- `10.73.11.150` — Рожки;
- `10.73.11.160` — Перья;
- `24.20.13.130` — Трубы стальные электросварные;
- `24.20.13.160` — Трубы стальные водогазопроводные.

У всех шести уже есть indexable public snapshots. Это не доказывает, что коды никогда не были официальными: они могли использоваться исторически в формах Росстата. В первом mapping pass их нельзя ни объявить действующим ОКПД2, ни автоматически перепривязать к родителю.

Из 1 245 exact-code targets 781 имеют официальных потомков, 464 являются листьями. Наличие потомков — характеристика узла, а не причина снижать mapping type.

### Обновление mappings при новой версии

- Для каждой новой version запускается новый mapping calculation.
- Автоматически предлагаются только строгие exact candidates.
- Ручное подтверждение предыдущей версии можно показать reviewer как evidence, но нельзя копировать как новый `confirmed` без проверки target.
- Удалённый или переименованный target становится review issue.
- Старые mappings остаются immutable для исторического воспроизведения и rollback.

---

## 6. Поиск и выдача

### Целевой принцип

Поиск должен объединять два разных result type, не маскируя один под другой:

1. Statistical result — существующий indexable public series snapshot.
2. Classifier result — узел активной официальной версии.

Минимальный classifier-result:

- `type = classifier_node`;
- code, name, level;
- active version label;
- parent/breadcrumb summary;
- `has_rosstat_data`;
- nullable linked statistical page/series;
- nullable URL.

`has_rosstat_data` разрешён только если существует `confirmed` mapping к локальному item активной classifier version и indexable statistical snapshot. Простое равенство строк code недостаточно.

### Query strategy для первого search-блока

Для объёма около 21 тыс. nodes на версию достаточно MariaDB:

- exact code;
- code prefix;
- normalized-name prefix;
- ограниченный contains fallback;
- приоритет exact code → code prefix → name prefix → contains;
- pagination/limit и стабильный tie-breaker.

Отдельный search engine или денормализованная projection до измерений не нужен. Перед решением о full-text требуется реальный `EXPLAIN` и p50/p95 на production-like объёме.

### UX-reference

С classifikators.ru можно заимствовать только информационные паттерны:

- поиск по коду и названию;
- дерево разделов A–U;
- breadcrumbs;
- parent/children;
- предупреждение, что узел содержит более детальные позиции.

Нельзя копировать утверждения о действующей версии, датах или authoritative статусе.

### Контрактная совместимость

Первый search block должен быть additive:

- существующая выдача public series не меняется;
- новый result type имеет явный discriminator;
- у classifier node без опубликованной страницы URL остаётся null;
- нельзя вести пользователя на выдуманный `/okpd2/{code}`.

---

## 7. Visibility и indexability

Нужно разделить четыре состояния:

| Состояние | Значение |
|---|---|
| A. Exists | Узел существует в активной официальной version |
| B. Search-visible | Узел разрешён во внутренней/публичной объединённой выдаче |
| C. Has public page | Для узла есть явно опубликованная landing page |
| D. Indexable | Страница прошла отдельную SEO policy и попадает в sitemap |

Импорт классификатора создаёт только состояние A.

Он не должен:

- создавать 20+ тыс. public pages;
- добавлять маршруты;
- ставить `is_indexable`;
- расширять sitemap;
- создавать SEO metadata;
- менять существующие statistical snapshots.

Если classifier pages понадобятся позднее, нужна отдельная curated publication table/policy, а не SEO-флаги на canonical nodes. Publication должна учитывать content quality, demand, mapping, данные ряда, canonical URL и дубликаты.

---

## 8. Legacy detail `/31-02-10-140`

### Observed

- Существующий canonical slug — `/31-02-10-140`.
- Snapshot связан с dataset-local item `31.02.10.140`.
- В официальной version №145/2026 существует точный узел `31.02.10.140` с именем «Наборы кухонной мебели».

### Требуемое поведение

URL и текущий statistical contract сохраняются. После mapping-блока additive context resolver может загрузить:

- подтверждённый node активной version;
- version label и effective date;
- breadcrumbs/ancestors;
- children/siblings при необходимости;
- provenance summary.

При отсутствии mapping или classifier subsystem страница продолжает работать в прежнем виде. Никакого redirect на `/okpd2/31-02-10-140` и смены canonical URL.

### Отдельный риск formatter

Сейчас `PublicIndexFormatter::classifierLabel` может выводить «ОКПД2» для `okpd2_based` без canonical proof. Корректная будущая политика: label «ОКПД2» только при `confirmed` mapping активной version; `.АГ` всегда остаётся локальным обозначением Росстата. Это отдельный совместимый блок с регрессионными тестами, не часть schema foundation.

---

## 9. Миграционная стратегия

### Additive-only

- Новые таблицы создаются рядом с текущими.
- Существующие dataset/import/series/public-page таблицы не меняются в первом блоке.
- Никакой массовой backfill связи при миграции.
- Никаких drop/rename.
- Никаких автоматических public side effects.

### Последовательность данных

1. Создать пустой canonical storage.
2. Добавить официальный source/import staging.
3. Импортировать и валидировать №145/2026 как candidate.
4. Отдельно опубликовать active pointer.
5. Рассчитать mapping proposals.
6. Подтвердить безопасные mappings и разобрать conflicts.
7. Только затем подключать search/detail context.

### Rollback

- Пока таблицы пусты, rollback schema foundation прямолинеен.
- После импорта версии нельзя удалять nodes как способ rollback: переключается active pointer.
- Удаление таблиц с production-history должно потребовать отдельного destructive migration plan/export.
- Statistical dataset publication и classifier publication откатываются независимо.

### Compatibility

Текущие FK, API payloads, importer registry, routes, slugs, sitemap и legacy URLs сохраняются. Любая будущая смена label/metadata должна быть выделена в отдельный блок.

---

## 10. Объём и индексы

### Оценка объёма

- Одна актуальная version: примерно 20 982 nodes.
- Десять полных versions: примерно 210 тыс. nodes.
- Текущие локальные items: 1 327 mappings на version.

Это нормальный реляционный объём для MariaDB 10.6. Не требуется преждевременное sharding или внешний graph/search store.

### Рекомендуемые индексы

`statistical_classifier_versions`:

- unique `(classifier_id, version_label, source_revision)`;
- `(classifier_id, status, effective_from)`.

`statistical_classifier_nodes`:

- unique `(classifier_version_id, code)`;
- `(classifier_version_id, parent_node_id, source_order)`;
- `(classifier_version_id, normalized_name)`;
- `(classifier_version_id, semantic_level)`.

`statistical_classifier_active_versions`:

- unique `classifier_id`;
- unique/validated `classifier_version_id` при выбранной кардинальности.

`statistical_classifier_item_mappings`:

- unique `(statistical_classifier_item_id, classifier_version_id)`;
- `(classifier_version_id, classifier_node_id, review_status)`;
- `(classifier_version_id, review_status, mapping_type)`.

### Иерархические запросы

Формальная глубина дерева ограничена семью цифровыми уровнями плюс section. Breadcrumb можно получать:

- итеративной загрузкой parent с request-local memoization; или
- recursive CTE MariaDB после проверки плана.

Не следует материализовать path/closure table до появления измеренного bottleneck. Для batch list нужно исключить N+1 через предварительную загрузку нужных ancestors.

### Риски индексов

- Длинный `normalized_name` может превысить практичный размер полного B-tree index в текущей кодировке; длину и prefix/full-text strategy надо подтвердить до миграции.
- Composite FK «parent в той же version» потребует подходящего unique key и проверки поддержки/порядка индексов MariaDB.
- `metadata_json` не должен становиться скрытым заменителем индексируемых доменных полей.

---

## 11. Риски и backward compatibility

| Риск | Последствие | Контроль |
|---|---|---|
| Смешение local item и canonical node | Ложные нормативные утверждения | Разные таблицы + explicit mapping |
| Активация «последней» future version | №146/2026 станет активной раньше 2027-01-01 | `effective_from` + explicit pointer + fail-closed command |
| Third-party fixture принят за official | Неверный состав/даты в production | trust tier и запрет publication |
| Mutable official URL | Невоспроизводимый import | immutable artifact + SHA-256 |
| Same label, different bytes | Тихая подмена snapshot | source revision conflict |
| Synthetic hierarchy | Выдуманные официальные узлы | nearest existing parent, no synthesis |
| Code-only auto mapping | Ошибочные связи при rename/history | code + normalized name, review conflicts |
| Silent carry-over mapping | Старое решение применяется к новой редакции | version-scoped mappings |
| Массовые routes/SEO | Thin pages, sitemap blow-up | Import creates existence only |
| Изменение `/31-02-10-140` | Потеря legacy URL/SEO/API | additive context, stable canonical |
| Label «ОКПД2» без proof | Недостоверный public UI | отдельная formatter policy после mappings |
| Parser привязан к layout одного DOCX | Следующая поставка ломает import | parser version, structural validations, fixtures |
| Broad transaction на parse | Locks/partial publication | parse/stage вне pointer transaction |
| N+1 hierarchy | Медленная выдача | measured eager/batch ancestor loading |

### Особо опасные предположения

- Номер изменения нельзя сортировать как обычную строку и считать датой действия.
- Дата приказа, дата публикации и дата введения — не одно поле.
- Совпадение code не всегда означает совпадение семантики.
- Локальный агрегат Росстата не становится ОКПД2 из-за похожей формы кода.
- Наличие официального node не означает наличие статистических данных или право на indexable page.

---

## 12. Тестовая стратегия

### Schema/model tests

- classifier code unique;
- version identity unique с source revision;
- node code unique внутри version и допустим в другой version;
- parent только из той же version;
- mapping node/version consistency;
- один active pointer на classifier;
- rollback пустых новых таблиц.

### Parser fixture tests

- 21 section A–U;
- все семь цифровых масок;
- duplicate identical row и duplicate conflicting row;
- неизвестная форма кода;
- пропущенный непосредственный category parent с fallback к существующему type;
- отсутствие synthetic node;
- контрольный `31.02.10.140` и его цепочка;
- разрыв/повреждение двухчастного DOCX;
- ZIP bomb/path traversal/content-type checks.

Reference `okpd.xlsx` допустим только в parser/search fixture suite. Тест должен проверять, что его trust tier не допускает publication, даже если parse успешен.

### Import/version tests

- повтор того же SHA идемпотентен;
- same label + different SHA даёт conflict;
- failed validation не меняет active pointer;
- `scheduled` version нельзя активировать до `effective_from`;
- №146/2026 с 01.09.2026 из reference-XLSX отвергается;
- №146/2026 с официальной датой 2027-01-01 может быть staged, но не active на 2026-08-24;
- publish атомарно переключает pointer;
- rollback восстанавливает предыдущую version;
- parser retry не дублирует snapshot.

### Mapping tests

- 1 214 строгих code+name candidates на текущем локальном срезе;
- 31 name conflicts уходят в review;
- 6 отсутствующих текущих codes не подтверждаются автоматически;
- 76 `.АГ` получают `local_rosstat` без node;
- fuzzy suggestion не становится `confirmed`;
- mapping не переносится молча на новую version;
- exact aggregate node остаётся `exact`, а не `parent_aggregate`.

Числа выше — regression evidence текущего локального snapshot, а не вечные production-константы. Интеграционные тесты должны строить малые контролируемые fixtures; полный-file smoke может сверять диапазоны/контрольные значения отдельно.

### Search tests

- exact code выше prefix/name;
- code/name normalization;
- classifier result отделён discriminator;
- `has_rosstat_data` только через confirmed active-version mapping + indexable snapshot;
- node без published page не получает выдуманный URL;
- пагинация и стабильный порядок;
- query count/`EXPLAIN` на production-like объёме.

### Public regression tests

- `/31-02-10-140` отвечает по прежнему URL;
- canonical и calculate URL не меняются;
- текущий sitemap не получает classifier nodes от импорта;
- существующие public catalog/search results сохраняются;
- `.АГ` не маркируется как ОКПД2;
- отсутствие classifier subsystem не ломает legacy detail;
- cache/public headers не меняются schema foundation блоком.

### Что проверять вручную в будущих UI-блоках

- desktop/mobile;
- keyboard focus;
- empty/loading/error states;
- long names and breadcrumbs;
- различимость «официальный узел» и «есть данные Росстата»;
- корректное отображение active/effective version.

---

## 13. Ограниченный план реализации

### Block 1 — Canonical storage foundation

Цель: создать пустую, additive основу идентичности classifier/version/node и active pointer.

В scope:

- migrations:
  - `statistical_classifiers`;
  - `statistical_classifier_versions`;
  - `statistical_classifier_nodes`;
  - `statistical_classifier_active_versions`;
- доменные models/enums/value objects;
- DB/model tests ограничений;
- никаких production rows.

Out of scope:

- source acquisition;
- source/import tables;
- DOCX/XLSX parser;
- data import;
- mappings;
- API/search/UI;
- public routes/pages;
- sitemap/SEO;
- изменение существующих таблиц.

Backward compatibility: нулевая смена существующих contracts и поведения.

Acceptance:

- migrations применяются и откатываются на пустой схеме;
- version/node identity и same-version parent защищены;
- active pointer явный и уникальный;
- future-effective поля моделируются отдельно;
- targeted tests проходят;
- существующие PriceIndices endpoints/routes не меняются.

Риск: до написания migration подтвердить безопасную реализацию composite parent/version FK и размеры name indexes в MariaDB 10.6.

### Block 2 — Official source and staging importer

Цель: сохранить официальный artifact и получить validated candidate snapshot №145/2026 без publication.

В scope:

- `statistical_classifier_source_files`;
- `statistical_classifier_imports`;
- provenance/trust tier;
- official allow-list/TLS acquisition;
- versioned parser;
- structural validation/diff;
- добавление provenance relation к version до первой production-загрузки.

Out of scope: active switch, mappings, public behavior.

Acceptance: immutable bytes/hash, reproducible parse, reference fixture publication rejected, №145 candidate остаётся неактивным.

### Block 3 — Publication, effective dates and rollback

Цель: безопасно активировать validated official version отдельной командой.

В scope:

- publish/rollback service/command;
- `as_of_date` guard;
- authoritative effective-date validation;
- transaction/audit trail.

Out of scope: mapping и public refresh.

Acceptance: №146 невозможно активировать до 2027-01-01; pointer switch/rollback атомарны.

### Block 4 — Versioned mappings

Цель: создать proposals и review workflow между local items и active canonical nodes.

В scope:

- mapping table/model/services;
- exact/name rules;
- `.АГ`;
- conflict report;
- manual confirmation audit.

Out of scope: public relabeling/routes.

Acceptance: no implicit mapping; conflicts и unmapped не публикуют claim.

### Block 5 — Combined internal search

Цель: добавить classifier result type без изменения существующих public series results.

В scope: code/name search, result discriminator, mapping-backed data marker, measurements.

Out of scope: classifier landing pages и sitemap.

### Block 6 — Legacy detail enrichment

Цель: добавить подтверждённый classifier context на существующий `/31-02-10-140` с null-safe fallback.

В scope: context resolver, breadcrumbs/version provenance, formatter correction, regression tests.

Out of scope: новый canonical route и массовые pages.

### Later — Curated classifier publication

Только после отдельного product/SEO решения: publication policy, ограниченный набор pages, canonical strategy и sitemap governance.

---

## Рекомендуемый следующий блок

Рекомендуется только **Block 1 — Canonical storage foundation**.

Это минимальный обратимо-аддитивный шаг, который фиксирует главный инвариант и future-effective versioning, но не делает никаких утверждений о данных, не импортирует №145/2026, не активирует №146/2026 и не влияет на публичный PriceIndices.

Перед реализацией Block 1 следует утвердить:

1. имена четырёх таблиц;
2. набор version statuses;
3. `date` против `datetime` для `effective_from/effective_to`;
4. стратегию same-version parent constraint для MariaDB;
5. отсутствие source/import FK до Block 2 при гарантированно пустых таблицах.

## Официальные и reference-ссылки

- Росстат, официальный раздел классификаторов: https://rosstat.gov.ru/classification
- Росстат, официальный mutable ZIP: https://rosstat.gov.ru/storage/mediabank/OKPD2.zip
- Росстандарт, карточка ОКПД2: https://protect.gost.ru/classificators/details/d62a528e-5761-40c2-a67b-2966a9d0911c
- Росстандарт, изменение №146/2026 и перенос даты: https://protect.gost.ru/classificators/changesdetails/43ad43f0-daad-4289-bde6-514802d32e02
- Reference UX/data fixture, не production source: https://classifikators.ru/okpd

## Итог границ аудита

Создан только этот архитектурный отчёт. Миграции, модели, сервисы, данные, endpoints, публичные routes, sitemap и SEO-поведение не изменены.
