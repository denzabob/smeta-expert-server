# Chrome-расширение «Призма — Автосбор материалов»

## Назначение

Расширение позволяет пользователю находиться на странице товара поставщика (любой сайт) и одним кликом добавить материал (плита, кромка, фурнитура) в базу данных приложения Призма вместе с текущей ценой и характеристиками.

---

## Архитектура расширения (Manifest V3)

```
chrome-extension/
├── manifest.json              # Манифест расширения (MV3)
├── background/
│   └── service-worker.js      # Фоновый сервис-воркер (посредник)
├── content/
│   ├── content.js             # Контент-скрипт (встраивается в каждую страницу)
│   └── content.css            # Стили оверлея захвата
├── popup/
│   ├── popup.html             # HTML попапа
│   ├── popup.js               # Логика попапа
│   └── popup.css              # Стили попапа
├── lib/
│   └── api.js                 # HTTP-клиент PrizmAPI (singleton)
└── icons/                     # Иконки расширения
```

### Разрешения

| Разрешение     | Зачем                                               |
|----------------|-----------------------------------------------------|
| `activeTab`    | Доступ к вкладке для инъекции и получения URL       |
| `storage`      | Хранение токена, базового URL и пользовательских настроек |
| `scripting`    | Программная инъекция контент-скрипта при необходимости |
| `host_permissions: https://*/*` | Обращение к API-серверу и страницам поставщиков |

---

## Компоненты и их роли

### 1. `lib/api.js` — API-клиент (PrizmAPI)

Синглтон, подключаемый через `importScripts` в сервис-воркере.

- При инициализации читает из `chrome.storage.local`:
  - `apiBaseUrl` — адрес сервера (по умолчанию `https://app.prismcore.ru/api`)
  - `authToken` — Bearer-токен Laravel Sanctum
- Все запросы к серверу идут с заголовком `Authorization: Bearer {token}`
- При получении `401` очищает токен и переводит расширение в состояние «требуется токен»

**Основные методы:**

| Метод | HTTP | Путь | Описание |
|-------|------|------|----------|
| `getMe()` | GET | `/chrome/me` | Получить данные авторизованного пользователя |
| `findTemplate(url)` | POST | `/chrome/find-template` | Найти шаблон селекторов для домена |
| `listTemplates(domain)` | GET | `/chrome/templates?domain=…` | Список шаблонов для домена |
| `saveTemplate(data)` | POST | `/chrome/templates` | Сохранить шаблон |
| `deleteTemplate(id)` | DELETE | `/chrome/templates/{id}` | Удалить шаблон |
| `validateFields(extracted, sources, url)` | POST | `/chrome/validate` | Валидировать захваченные поля |
| `extract(url, extracted, ...)` | POST | `/chrome/extract` | **Создать материал** |

---

### 2. `background/service-worker.js` — Сервис-воркер

Посредник между попапом и контент-скриптом. Слушает `chrome.runtime.onMessage` и маршрутизирует действия к API-клиенту.

**Обрабатываемые сообщения:**

| Action | Что делает |
|--------|-----------|
| `FIELD_CAPTURED` | Обновляет бейдж расширения (счётчик захваченных полей) |
| `CLEAR_BADGE` | Сбрасывает бейдж |
| `GET_ME` | Проксирует к `prizmApi.getMe()` |
| `FIND_TEMPLATE` | Проксирует к `prizmApi.findTemplate()` |
| `SAVE_TEMPLATE` | Проксирует к `prizmApi.saveTemplate()` |
| `DELETE_TEMPLATE` | Проксирует к `prizmApi.deleteTemplate()` |
| `VALIDATE_FIELDS` | Проксирует к `prizmApi.validateFields()` |
| `EXTRACT` | **Проксирует к `prizmApi.extract()`** — финальный шаг создания материала |
| `CHECK_AUTH` | Проверяет актуальность токена |
| `CONFIGURE` | Сохраняет новый baseUrl/token |
| `GET_CONFIG` | Возвращает текущую конфигурацию |

Также создаёт контекстное меню браузера «Призма: захватить элемент».

---

### 3. `content/content.js` — Контент-скрипт

Встраивается в **каждую** открытую страницу (`run_at: document_idle`, `<all_urls>`). Отвечает за:

#### Автоматическое определение полей (AUTO_DETECT_FIELDS)

Пытается найти на странице без участия пользователя:
- **Название**: мета-теги OG/Twitter, `<h1>`, специфические CSS-классы товаровых страниц
- **Цена**: `meta[property="product:price:amount"]`, `[itemprop="price"]`, `.price`, `.product-price` и аналоги
- **Артикул**: `[itemprop="sku"]`, поиск паттерна «артикул/sku/код: XXX» в тексте страницы
- **Schema.org**: поиск блоков `<script type="application/ld+json">` с типом `Product`

#### Ручной захват элементов (START_CAPTURE)

Режим выбора элемента на странице:
1. Показывает панель-оверлей с подсказкой
2. При наведении подсвечивает элемент цветом, соответствующим типу поля (каждый тип поля — свой цвет)
3. При клике:
   - Извлекает текст элемента (`innerText` / `value` для `<input>`)
   - Генерирует CSS-селектор и XPath для элемента
   - Нормализует значение (цена — очищает от валюты, размеры — извлекает число)
   - Отправляет `FIELD_CAPTURED` в сервис-воркер
   - Ставит визуальную метку на элемент

#### Применение шаблона (APPLY_TEMPLATE)

Принимает объект `{field: selector}`, прогоняет по DOM, возвращает значения.

#### Нормализация значений

| Поле | Алгоритм нормализации |
|------|----------------------|
| `price` | Убирает «руб.», «₽», «RUB», «от», пробелы-разделители тысяч, унифицирует десятичный разделитель. «2 345,50 руб.» → «2345.5» |
| `thickness` / `length` / `width` | Извлекает первое число с десятичной точкой. «16 мм» → «16» |
| `title` / `article` | Только `trim()` |

---

### 4. `popup/popup.js` — UI попапа

Главный пользовательский интерфейс. Основной процесс при открытии:

```
1. Проверить токен (chrome.storage → GET /chrome/me)
2. Получить информацию о текущей вкладке
3. POST /chrome/find-template — есть ли сохранённый шаблон для домена?
   ├── Если есть → применить шаблон автоматически (APPLY_TEMPLATE)
   └── Если нет  → запустить AUTO_DETECT_FIELDS на странице
4. POST /chrome/validate — показать предпросмотр с ошибками
5. Кнопка «Добавить материал» → POST /chrome/extract
```

**Поля, отображаемые пользователю:**

| Поле | Тип | Обязательное |
|------|-----|-------------|
| Название | строка | да |
| Цена | число + валюта | да |
| Артикул | строка | нет |
| Толщина (мм) | число | для плит |
| Длина (мм) | число | для плит и кромки |
| Ширина (мм) | число | для плит |

Режимы отображения:
- **Простой** (по умолчанию): минимум полей, статус-строка
- **Расширенный** (`isAdvancedMode`): полный набор вкладок (захват, шаблоны, Schema.org, настройки)

---

## Поток данных: от клика на сайте до записи в БД

```
[Страница поставщика]
      │
      │ AUTO_DETECT или ручной выбор элемента
      ▼
[Контент-скрипт]  —FIELD_CAPTURED→  [Сервис-воркер]
      │                                     │
      │  Popup запрашивает захваченные       │
      │  данные через GET_CAPTURED_DATA      │
      ▼                                     │
[Попап: capturedFields]                     │
      │                                     │
      │  POST /chrome/validate              ▼
      ├─────────────────────────→  [API-сервер Laravel]
      │                                     │
      │  Показ предпросмотра                │ ChromeExtractService::validateExtractedFields()
      │                                     │ + resolveMaterialType()
      │                                     │ + resolveDimensions()
      │   ← ошибки / предпросмотр ──────────┤
      │
      │  POST /chrome/extract
      ├─────────────────────────→  [API-сервер Laravel]
                                           │
                              ChromeExtractService::createOrUpdateMaterial()
                                           │
                              ┌────────────┼────────────┐
                              ▼            ▼            ▼
                         materials  material_price  user_material
                         (создать/  _history        _library
                          обновить) (наблюдение)    (добавить в
                                                     библиотеку)
```

---

## Данные, записываемые в БД

### Таблица `materials`

| Поле | Источник |
|------|---------|
| `name` | `extracted.title` |
| `article` | `extracted.article` |
| `type` | Определяется `MaterialTypeDetectionService` (plate / edge / hardware) |
| `unit` | Зависит от type: м², м.п., шт |
| `price_per_unit` | `extracted.price` (нормализованное число) |
| `source_url` | URL страницы поставщика (с вычищенными UTM-метками) |
| `thickness_mm` | `extracted.thickness` или парсинг из названия |
| `length_mm` | `extracted.length` или парсинг из названия |
| `width_mm` | `extracted.width` или парсинг из названия |
| `data_origin` | `'chrome_ext'` |
| `region_id` | Из настроек пользователя / явно переданный |
| `trust_level` | `verified` / `unverified` в зависимости от полноты данных |
| `metadata.field_sources` | Источник каждого поля (шаблон / авто / вручную) |
| `metadata.material_type_resolution` | Детали определения типа материала |

### Таблица `material_price_history`

| Поле | Значение |
|------|---------|
| `source_type` | `'chrome_ext'` |
| `price_per_unit` | Числовая цена |
| `currency` | `'RUB'` / `'USD'` / `'EUR'` |
| `source_url` | Очищенный URL |
| `raw_source_url` | Исходный URL |
| `screenshot_path` | `NULL` (скриншот не делается при ручном сборе через расширение) |
| `is_verified` | `true` если все поля корректны |
| `true_score` | 80 (фиксированный для chrome_ext) |

### Таблица `user_material_library`

Связь «пользователь — материал» создаётся или обновляется (`firstOrCreate`).

### Таблица `chrome_ext_logs`

Журнал действий: `action`, `status` (`success`/`partial`/`failed`), URL, извлечённые поля, ошибки, `material_id`.

### Таблица `parser_supplier_collect_profiles` (Шаблоны)

Шаблоны хранят CSS-селекторы (`selectors`) для каждого поля, привязанные к домену (`supplier_name`) и пользователю. Поддерживают паттерны URL для точного сопоставления с конкретными типами страниц товара.

---

## Аутентификация

- Bearer-токен Sanctum хранится в `chrome.storage.local` под ключом `authToken`
- Маршруты `/api/chrome/*` защищены Sanctum, но без session/stateful middleware
- Получение токена: `POST /api/chrome/auth/token` (логин+пароль) или `POST /api/chrome/auth/token/session` (из активной сессии браузера в веб-приложении)

---

## Дедупликация материалов

Перед созданием нового материала `MaterialDeduplicationService` проверяет:
1. Совпадение нормализованного URL
2. Совпадение артикула + unit + type
3. Текстовое сходство названий

При высокой уверенности (`confidence: high`) — обновляет существующий материал, не создаёт дубликат.
