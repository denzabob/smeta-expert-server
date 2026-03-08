# Архитектура системы

Документ описывает текущее состояние системы по коду в репозитории `smeta-expert-server` на 2026-03-04.

## 1. Состав системы

В репозитории сейчас 5 основных подсистем:

- `parser/` — Python-парсер поставщиков.
- `server/` — Laravel API, бизнес-логика, PDF, очереди, хранение данных.
- `client/` — фронтенд на Vue 3 + Vite + Pinia + Vuetify.
- `chrome-extension/` — расширение Chrome для ручного захвата данных со страниц поставщиков.
- PDF generator — это не отдельный сервис, а часть `server/` (Laravel + DomPDF).

Ключевая идея архитектуры: все внешние источники данных (парсер, ручной ввод, Chrome extension, импорт прайсов) в итоге сходятся в Laravel API, а Laravel уже нормализует данные и пишет их в БД.

## 2. Высокоуровневая схема

```text
Поставщики / сайты / прайсы
        |
        +--> parser (Python, batch/queue)
        |         |
        |         +--> Laravel API (/api/parser/*, /api/internal/parser/*)
        |
        +--> Chrome extension
        |         |
        |         +--> Laravel API (/api/chrome/*)
        |
        +--> Web UI (frontend)
                  |
                  +--> Laravel API (/api/materials, /api/materials/catalog, /api/projects, ...)
                                    |
                                    +--> MySQL (materials, material_price_histories, material_prices, ...)
                                    |
                                    +--> PDF generation (DomPDF + Blade)
```

## 3. Подсистемы

### 3.1. Parser

`parser/` — это Python-подсистема с несколькими режимами:

- `collect_urls.py` — собирает URL товаров поставщика и отправляет их в API.
- `main.py` — единая entry point для CLI-режимов.
- `queue_worker_async.py` — асинхронный воркер на Playwright для пакетной обработки URL.
- `core.py` — общая логика парсинга, HTTP callback-и, batch-сохранение материалов.
- `suppliers/*.py` — адаптеры под конкретных поставщиков.
- `configs/*.json` — конфигурации селекторов и правил для поставщиков.

Текущий parser работает в детерминированном lifecycle без авто-циклов:

1. Laravel создаёт `ParsingSession`.
2. `RunParserJob` запускает Python-процесс.
3. Фаза `collect`: Python собирает URL и кладёт их в таблицу очереди `supplier_urls`.
4. Фаза `reset`: Laravel/API переводит нужные URL в `pending`.
5. Фаза `queue`: Python-воркер батчами забирает URL через `claim`.
6. По каждому URL парсер извлекает материал.
7. Материалы сохраняются в Laravel через `/api/parser/materials` или `/api/parser/materials/batch`.
8. Прогресс/логи/ошибки идут callback-ами в `/api/internal/parser/callback`.

С точки зрения хранения:

- очередь URL живёт в `supplier_urls`;
- сессия и прогресс живут в `parsing_sessions`;
- распарсенные материалы пишутся в `materials`;
- изменения цены парсером пишутся в `material_price_histories`.

### 3.2. API

`server/` — это Laravel 12 API.

Основные роли API:

- авторизация (`Sanctum`);
- CRUD материалов, проектов, операций и прайс-листов;
- orchestration парсера;
- обработка Chrome extension;
- импорт прайсов;
- генерация PDF;
- хранение истории цен и снапшотов ревизий.

API фактически является центральным оркестратором всей системы:

- принимает данные от parser и extension;
- выполняет дедупликацию;
- нормализует единицы, типы материалов и цену;
- рассчитывает trust score;
- ведёт историю наблюдений цены;
- строит отчёты и PDF.

### 3.3. Frontend

`client/` — SPA на:

- Vue 3
- Vite
- Pinia
- Vue Router
- Vuetify
- Axios

Архитектурно фронтенд разделён на:

- основной shell (`AppShell.vue`);
- модуль `parser` с отдельным layout и маршрутами (`/parser`, `/parser/history`, `/parser/settings`);
- предметные экраны: материалы, каталог, проекты, поставщики, импорт прайсов.

Сейчас есть два разных пользовательских потока для материалов:

- старый экран `MaterialsView` (`/materials`) — классический CRUD по `/api/materials`;
- новый каталог (`/catalog`) — более современный поток по `/api/materials/catalog`, с парсингом по URL, trust score, библиотекой пользователя и наблюдениями цены.

### 3.4. PDF generator

Отдельного standalone-сервиса для PDF нет.

PDF генерируется внутри `server/` через:

- `barryvdh/laravel-dompdf`
- Blade-шаблон `resources/views/reports/smeta.blade.php`

Источники данных для PDF:

- либо live-расчёт через `ReportService`;
- либо сохранённый `snapshot_json` ревизии проекта.

Итог: PDF generator — это часть Laravel backend-а, а не внешний микросервис.

### 3.5. Chrome plugin

`chrome-extension/` — расширение с 3 слоями:

- `content/content.js` — работает на странице поставщика, захватывает DOM-элементы и извлекает значения;
- `popup/popup.js` — UI расширения;
- `background/service-worker.js` — прокси между popup и API.

Расширение не пишет напрямую в БД и не знает бизнес-логику. Оно только:

- получает токен,
- находит шаблон извлечения,
- собирает поля со страницы,
- отправляет результат в Laravel API.

## 4. Как работает parser

### 4.1. Запуск

Парсер сейчас запускается не сам по себе, а через Laravel job `RunParserJob`.

`RunParserJob`:

- создаёт и ведёт lifecycle `ParsingSession`;
- запускает Python как `python -m parser.main`;
- отдельно запускает фазы `--collect-only`, `--reset-only`, `--queue`;
- обрабатывает таймауты и переводит сессию в `failed`, если что-то пошло не так.

### 4.2. Сбор URL

В режиме `collect`:

- `collect_urls.py` читает конфиг поставщика;
- проходит по категориям/каталогу;
- определяет `material_type`;
- отправляет URL в Laravel API (`/api/parsing/save-urls`);
- URL сохраняются в таблицу `supplier_urls`.

### 4.3. Очередь обработки

В режиме `queue`:

- воркер (`queue_worker_async.py`) батчем вызывает `/api/parser/urls/claim`;
- API атомарно блокирует URL и переводит их в `processing`;
- воркер открывает страницы через Playwright;
- парсит поля через supplier adapter;
- результат возвращает в API:
  - статус URL через `/api/parser/urls/report`;
  - сами материалы через `/api/parser/materials` или `/api/parser/materials/batch`.

### 4.4. Сохранение материалов

Для материалов, пришедших от парсера:

- ищется существующий `materials` по `article` + `origin = parser`;
- если материал новый, он создаётся;
- если цена изменилась, увеличивается `version`;
- в `material_price_histories` создаётся новая запись;
- предыдущая активная запись истории закрывается через `valid_to`.

То есть parser обновляет и “текущий слепок” материала, и историю его цен.

### 4.5. Callback-и и прогресс

Python парсер шлёт callback-и в `/api/internal/parser/callback`:

- `log`
- `progress`
- `finish`
- `total_urls`
- `mark_url_failed`
- phase-based события

Laravel принимает их, обновляет `parsing_sessions`, пишет логи и может вернуть команду `stop`, если сессия отменяется.

## 5. Как работает Chrome plugin и что он отправляет в API

### 5.1. Поток работы

Текущий поток такой:

1. Пользователь открывает страницу поставщика.
2. `content.js` умеет:
   - захватывать кликом значения из DOM;
   - строить CSS selector/XPath;
   - автоматически нормализовать цену;
   - автоматически пытаться извлечь размеры;
   - обнаруживать `schema.org`.
3. `popup.js` показывает UI, выбранные поля, шаблоны и валидацию.
4. `background/service-worker.js` пересылает запросы в API.
5. Laravel endpoint `/api/chrome/extract` создаёт или обновляет материал.

### 5.2. Что расширение отправляет в API

Основной запрос на создание/обновление материала идёт в:

- `POST /api/chrome/extract`

Тело запроса сейчас такое:

```json
{
  "url": "https://supplier-site/product/123",
  "extracted": {
    "title": "ЛДСП Белый 2800х2070х16",
    "price": "3 250 ₽",
    "article": "ABC-123",
    "thickness": "16",
    "length": "2800",
    "width": "2070"
  },
  "data_sources": {
    "title": "capture",
    "price": "capture",
    "article": "capture",
    "thickness": "auto",
    "length": "auto",
    "width": "auto"
  },
  "template_id": 15,
  "region_id": 2
}
```

Важно:

- `title` и `price` обязательны;
- `article`, размеры, `template_id`, `region_id` — опциональны;
- `data_sources` фиксирует происхождение каждого поля: `auto`, `capture`, `schema`, `manual`.

### 5.3. Что API делает с этими данными

На стороне Laravel (`ChromeExtractService`):

1. Валидирует поля.
2. Нормализует URL (чистит tracking-параметры).
3. Парсит цену и валюту.
4. Определяет тип материала:
   - `edge`
   - `plate`
   - `hardware`
5. Определяет unit:
   - `plate` -> `м²`
   - `edge` -> `м.п.`
   - `hardware` -> `шт`
6. Пытается извлечь размеры из названия, если они не переданы явно.
7. Выполняет дедупликацию через `MaterialDeduplicationService`.
8. Если найден уверенный дубль:
   - обновляет существующий `materials`.
9. Если дубля нет:
   - создаёт новый `materials`.
10. Всегда пишет наблюдение цены в `material_price_histories`.
11. Автоматически добавляет материал в `user_material_library`.

Отдельно расширение использует:

- `POST /api/chrome/find-template` — найти шаблон;
- `POST /api/chrome/validate` — проверить данные без создания материала;
- `GET/POST/DELETE /api/chrome/templates*` — управлять шаблонами;
- `GET /api/chrome/me` — проверить токен и получить `region_id`.

## 6. Как сейчас генерируется PDF обоснование цены

### 6.1. Что есть сейчас

Сейчас “PDF обоснование цены” не выделен в отдельный PDF-документ или отдельный рендерер.

Текущее состояние по коду:

- PDF формируется тем же шаблоном `reports/smeta.blade.php`;
- генерация идёт через DomPDF;
- в PDF включаются разделы с обоснованиями ставок и расчётов, если они есть в `report`.

То есть сейчас это часть общего PDF-сметы/ревизии, а не отдельный standalone “price justification PDF”.

### 6.2. Откуда берутся данные

Есть 3 основных сценария генерации:

- `GET /api/smeta/pdf/{project}` — live-генерация из `ReportService`;
- `GET /api/projects/{project}/revisions/{number}/pdf` — генерация из `snapshot_json` ревизии;
- `GET /v/{publicId}/pdf` — публичный PDF по опубликованной ревизии.

Во всех случаях рендер идёт через:

- `Pdf::loadView('reports.smeta', ...)`

### 6.3. Где именно “обоснование”

В шаблоне `reports/smeta.blade.php` уже встроены секции:

- `profile_rate_justifications`
- подробные блоки по источникам ставок
- `model_breakdown`
- блок “Экономическое обоснование стоимости подрядных работ”

Итог:

- если под “обоснованием цены” имеется обоснование ставок/стоимости работ, оно сейчас встраивается прямо в общий PDF-сметы;
- отдельного маршрута вроде “сгенерировать только PDF обоснования цены” сейчас в коде нет.

## 7. Как добавляется материал сейчас: форма, API, данные

Сейчас в системе реально существуют 3 разных потока добавления материала.

### 7.1. Старый CRUD-поток (`/materials`)

Форма:

- экран `MaterialsView.vue`;
- кнопка “Добавить” открывает диалог;
- пользователь вводит:
  - `name`
  - `article`
  - `type`
  - `unit`
  - `price_per_unit`
  - `source_url`
  - размеры и доп. поля

API:

- создание: `POST /api/materials`
- редактирование: `PUT /api/materials/{id}`
- история: `GET /api/materials/{id}/history`
- helper по URL: `POST /api/materials/fetch`

Что сохраняется:

- создаётся запись в `materials`;
- для пользовательского материала ставится `origin = user`, `user_id = текущий пользователь`;
- сразу создаётся первая запись в `material_price_histories`.

Особенность:

- это более “простой” и старый CRUD;
- он хранит текущую цену в материале и заводит первую историю цены;
- для parser-материалов редактирование из этого экрана заблокировано.

### 7.2. Новый каталог (`/catalog`)

Форма:

- `AddMaterialDialog.vue`;
- пользователь может:
  - начать с URL;
  - проверить поддержку домена;
  - распарсить поля по URL;
  - вручную поправить;
  - выбрать существующий дубль;
  - сохранить новый материал.

API:

- `POST /api/materials/check-domain`
- `POST /api/materials/parse-by-url`
- `POST /api/materials/catalog`

Какие данные отправляются:

- material fields:
  - `name`
  - `article`
  - `type`
  - `unit`
  - `price_per_unit`
  - `source_url`
  - `visibility`
  - `region_id`
  - размеры / `metadata` / `operation_ids`
- observation fields:
  - `observation_region_id`
  - `observation_source_type`
  - `parse_session_id`
  - `screenshot_path`
  - `snapshot_path`
- служебные:
  - `data_origin` (`manual`, `url_parse`, `price_list`, `chrome_ext`)

Что делает backend:

1. `MaterialCatalogController@store` делит payload на:
   - данные материала;
   - данные первого наблюдения цены.
2. `MaterialParseService::createMaterialWithObservation()`:
   - создаёт `materials`;
   - создаёт первую запись в `material_price_histories`;
   - пересчитывает trust score.
3. Материал автоматически добавляется в `user_material_library`.

Это сейчас более “богатый” и актуальный поток, чем старый `/materials`.

### 7.3. Через Chrome extension

Это третий поток:

- форма живёт не во фронтенде, а в popup расширения;
- API: `POST /api/chrome/extract`;
- сервер сам решает: обновить существующий материал или создать новый;
- затем создаёт новое наблюдение цены.

## 8. Как хранится цена и есть ли история цен

### 8.1. Где лежит “текущая” цена

Текущая цена материала хранится прямо в таблице `materials`:

- `materials.price_per_unit`
- `materials.price_checked_at`
- `materials.version`

Это удобно для быстрого чтения и отображения в UI.

### 8.2. Есть ли история цен

Да. История цен есть, и сейчас она хранится в `material_price_histories`.

Эта таблица — не просто “лог изменений”, а журнал наблюдений/версий цены.

Ключевые поля:

- `material_id`
- `version`
- `price_per_unit`
- `valid_from`
- `valid_to`
- `observed_at`
- `source_url`
- `screenshot_path`
- `snapshot_path`
- `region_id`
- `source_type` (`web`, `manual`, `price_list`, `chrome_ext`)
- `is_verified`
- `currency`
- `availability`

Как используется:

- parser пишет туда записи при изменении цены;
- catalog flow пишет туда первое наблюдение и новые observations;
- Chrome extension пишет туда observation при каждом извлечении;
- ручное добавление материала создаёт первую запись истории;
- UI может читать историю через `/api/materials/{id}/history` или `/api/materials/{id}/price-observations`.

### 8.3. Отдельно: цены из прайс-листов

Кроме `material_price_histories`, есть ещё таблица `material_prices`.

Это другой слой данных.

`material_prices` хранит не “жизненную историю материала”, а цены из конкретных версий прайс-листов:

- привязка к `price_list_version_id`;
- `source_price`;
- `source_unit`;
- `conversion_factor`;
- `price_per_internal_unit`;
- `supplier_id`;
- `article`, `category`, `description`.

То есть:

- `materials.price_per_unit` — текущая оперативная цена для материала;
- `material_price_histories` — история наблюдений/изменений цены;
- `material_prices` — нормализованные цены из версионных прайс-листов поставщиков.

### 8.4. Вывод по истории

История цен в системе есть, и она уже многослойная:

- простая “текущая цена” в `materials`;
- наблюдения и change history в `material_price_histories`;
- снапшоты по версиям прайс-листов в `material_prices`.

## 9. Практический вывод

Если смотреть на систему как на целое, то сейчас:

- Laravel API — центральное ядро;
- parser и Chrome extension — два разных канала сбора данных;
- фронтенд — основной пользовательский интерфейс;
- PDF — часть backend-а, строится из общего report/snapshot;
- материалы уже имеют и текущую цену, и историю наблюдений, и отдельный слой цен из прайс-листов.

## 10. Что важно учитывать дальше

При дальнейшей разработке стоит помнить о текущем разделении:

- старый поток `/api/materials` и новый каталог `/api/materials/catalog` сосуществуют параллельно;
- “история цены” и “цена из прайс-листа” — это не одно и то же;
- PDF “обоснования” сейчас встроен в общий PDF сметы, а не вынесен в отдельный документ;
- Chrome extension создаёт материалы через специальный поток, а не через обычный material CRUD.
