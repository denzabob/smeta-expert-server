# Chrome Extension и система управления типами материалов

## Оглавление

1. [Плагин Chrome Extension](#плагин-chrome-extension)
2. [Система управления паттернами типов материалов](#система-управления-паттернами-типов-материалов)
3. [Взаимодействие плагина и сервера](#взаимодействие-плагина-и-сервера)

---

## Плагин Chrome Extension

### Обзор

Плагин **Призма — Автосбор материалов** предназначен для автоматического сбора информации о материалах со страниц поставщиков:
- Название товара
- Цена
- Артикул
- Размеры (для листовых материалов и кромок)

### Структура плагина

```
chrome-extension/
├── manifest.json              # Манифест расширения
├── background/
│   └── service-worker.js      # Фоновый сервис-воркер
├── content/
│   ├── content.js             # Скрипт на странице товара
│   └── content.css            # Стили оверлея
├── lib/
│   └── api.js                 # API клиент для сервера
├── popup/
│   ├── popup.html             # UI попапа расширения
│   ├── popup.css              # Стили попапа
│   └── popup.js               # Логика попапа
└── icons/                      # Иконки расширения
```

### Как работает автодетектирование типов материалов

#### 1. Встроенные паттерны в плагине

**В `content.js` и `popup.js` определены жёсткие паттерны:**

```javascript
const SHEET_MATERIAL_PATTERNS = /\b(ЛДСП|МДФ|ХДФ|ОСБ|ЛМДФ|OSB|ДВПО|ДСП|ДВП|ЛХДФ|ЛОСБ|HPL|CPL|ФСФ|ФК)\b/i;

function detectMaterialType(name, url) {
    // Edge: проверка на "Кромка" в названии или "kromka" в URL
    if (name && /кромка/i.test(name)) return 'edge';
    if (url && /kromka/i.test(url)) return 'edge';
    
    // Plate: проверка на листовые материалы
    if (name && SHEET_MATERIAL_PATTERNS.test(name)) return 'plate';
    
    // Default: фурнитура
    return 'hardware';
}
```

#### 2. Три типа материалов и их характеристики

| Тип | Определение | Единица | Размеры | Пример |
|-----|-----------|---------|---------|--------|
| **edge** (Кромка) | Ищет "кромка" в названии или URL | м.п. | Ширина × Толщина | 19 × 0,4 мм |
| **plate** (Плита) | Поиск кодов ЛДСП, МДФ, ХДФ и т.д. | м² | Длина × Ширина × Толщина | 2750 × 1830 × 16 мм |
| **hardware** (Фурнитура) | По умолчанию (если не совпало ничего) | шт | Без размеров | — |

#### 3. Парсинг размеров в зависимости от типа

**Edge (кромка):**
```javascript
// Формат: "19х0,4" или "19x0.4" (ширина_мм × толщина_мм)
// По конвенции БД: edge width → length field, edge thickness → width field
const dims = parseEdgeDimensions(name);
// Результат: { length: 19, width: 0.4 }
```

**Plate (плита):**
```javascript
// Парсит несколько форматов:
// 1. "2800х2070х16" (LxWxT)
// 2. "2750*1830 16 мм" (LxW + отдельно T)
// 3. "ЛДСП 2800х2070" (LxW из шаблона)

const dims = parseDimensionsFromName(name);
// Возвращает: { length: 2800, width: 2070, thickness: 16 }
```

**Hardware:**
```javascript
// Размеры не извлекаются
```

#### 4. Нормализация цены

Функция `normalizePrice()` обрабатывает различные форматы:

```javascript
"2 345,50 руб."   → "2345.50"
"1 234.56 RUB"    → "1234.56"
"от 999 рублей"   → "999"
"12 500 ₽"        → "12500"
```

### Механизм захвата данных

#### Режим выбора элемента (`startCapture`)

1. **Пользователь нажимает кнопку "Выбрать"** для поля (название, цена, артикул, размер)
2. **Активируется режим захвата** — появляется оверлей с подсказкой
3. **При наведении на элемент** — он подсвечивается цветом в соответствии с типом поля
4. **Пользователь кликает** на нужный элемент
5. **Элемент захватывается** — извлекается текст, CSS селектор и XPath
6. **Данные нормализуются** в зависимости от типа поля

#### Генерация селекторов

**CSS селектор (приоритет выше):**
```javascript
function generateSelector(el) {
    // Попытка использовать ID элемента
    if (el.id) return `#${CSS.escape(el.id)}`;
    
    // Построить путь через classList и nth-child
    // Результат: "div.product > div.title-row > h1.product-name"
}
```

**XPath (fallback):**
```javascript
function generateXPath(el) {
    // Результат: "/html/body/div[1]/div[2]/h1[1]"
}
```

### Автоматическое заполнение полей

При открытии попапа плагин пытается автоматически найти данные:

```javascript
async function autoDetectFields() {
    // 1. Парсит Schema.org/JSON-LD если присутствует на странице
    const schema = extractSchemaData();
    
    // 2. Ищет стандартные селекторы (meta tags, классы с "price" и т.д.)
    const title = getMetaContent('meta[property="og:title"]') || 
                  document.querySelector('h1').textContent;
    
    const price = getMetaContent('meta[property="product:price:amount"]') ||
                  document.querySelector('.price').textContent;
    
    // 3. Определяет тип материала из названия
    const materialType = detectMaterialType(title, url);
    
    // 4. Парсит размеры в зависимости от типа
    const dims = materialType === 'edge' 
        ? parseEdgeDimensions(title)
        : parseDimensionsFromName(title);
    
    // 5. Возвращает найденные данные с источником
    return {
        fields: {
            title: { value: title, auto: true, schema: false },
            price: { value: price, auto: true, schema: false },
            // ...
        },
        materialType: 'plate',
        unit: 'м²',
        warnings: []
    };
}
```

### Система шаблонов

Плагин позволяет сохранять **шаблоны сайтов** — наборы селекторов для автоматического извлечения данных:

```javascript
// При обнаружении знакомого сайта — автоматически применяется шаблон
const template = {
    name: "site.ru шаблон",
    url_pattern: "site.ru",
    extraction_rules: {
        title: "h1.product-title",      // CSS селектор
        price: ".price-value",
        article: "[data-sku]"
    },
    schema_mapping: {
        // Маппинг Schema.org полей на поля формы
        schemaIndex: 0,
        mapping: {
            "title": "name",
            "price": "offers.price"
        }
    }
};

// Шаблон сохраняется на сервере и переиспользуется
```

### Источники данных и доверие

Каждое заполненное поле может иметь источник:

```javascript
{
    title: {
        value: "ЛДСП Egger 16мм",
        source: 'auto',        // auto | manual | template | schema
        template: true,        // Из шаблона?
        selector: "h1",        // CSS селектор
        xpath: "/html/body/h1",
        schema: false          // Из Schema.org?
    }
}
```

**Порядок доверия:**
1. **template** — Сохраненный шаблон сайта (самое надёжное)
2. **auto** — Автоматически найдено через паттерны
3. **schema** — Из Schema.org разметки
4. **manual** — Введено пользователем вручную

### Schema.org / JSON-LD поддержка

Плагин может извлекать данные из структурированной разметки:

```javascript
function extractSchemaData() {
    // Ищет <script type="application/ld+json"></script>
    const schemas = [...document.querySelectorAll('script[type="application/ld+json"]')]
        .map(el => JSON.parse(el.textContent));
    
    // Парсит Product, BreadcrumbList и т.д.
    return {
        found: true,
        schemas: [
            {
                type: "Product",
                fields: [
                    { path: "name", value: "ЛДСП Egger" },
                    { path: "offers.price", value: 2500 },
                    { path: "sku", value: "EG001" }
                ]
            }
        ]
    };
}

// UI позволяет пользователю выбрать какие поля заполнить из Schema.org
```

### Валидация на клиенте

После захвата данных происходит валидация:

```javascript
async function handleValidate() {
    // Отправляет на сервер все заполненные поля
    const validationResult = await api.validateFields(
        {
            title: "ЛДСП Egger 2750x1830x16",
            price: "2500",
            article: "EG-001",
            length: 2750,
            width: 1830,
            thickness: 16
        },
        dataSources // откуда каждое поле взялось
    );
    
    // Результат:
    // {
    //   valid: true,
    //   detected_type: 'plate',
    //   detected_unit: 'м²',
    //   warnings: [],
    //   dimensions_parsed: { length: 2750, ... }
    // }
}
```

---

## Система управления паттернами типов материалов

### Архитектура

Система управления типами материалов имеет три уровня:

```
┌─────────────────────────────┐
│  АДМИН-ПАНЕЛЬ               │
│  prismcore.ru/admin         │
│  CRUD паттернов             │
└────────────┬────────────────┘
             │ POST/PUT/DELETE
             ↓
┌─────────────────────────────┐
│  СЕРВЕР (Laravel)           │
│  MaterialTypePattern (БД)    │
│  MaterialTypeDetectionService│
└────────────┬────────────────┘
             │ SELECT + обработка
      ┌──────┴───────┐
      ↓              ↓
┌──────────────┐  ┌──────────────┐
│  Плагин      │  │  В API       │
│  Chrome      │  │  /extract    │
│  (встроенные)│  │  (из БД)     │
└──────────────┘  └──────────────┘
```

### Таблица базы данных: `material_type_patterns`

#### Структура

```
┌─ id (Integer)                  Первичный ключ
├─ name (String)                 Название паттерна (макс. 255 символов)
├─ description (Text)            Описание для администратора
├─ is_active (Boolean)           Активен ли паттерн (default: true)
├─ priority (Integer)            Порядок проверки (меньше = выше приоритет)
├─ material_type (String)        Результат: 'plate' | 'edge' | 'hardware' | 'facade'
├─ source (String, NULL)         NULL = для всех, или код поставщика
├─ rule_type (String)            Тип правила (сейчас только 'regex')
├─ target_field (String)         Где искать: 'title' | 'url' | 'title_or_url'
├─ pattern (Text)                Regex выражение
├─ flags (String)                Regex флаги (по умолчанию 'iu')
├─ use_normalized_text (Boolean) Использовать нормализованный текст?
├─ example_input (String)        Пример входных данных
├─ expected_material_type (String) Ожидаемый результат
├─ created_by_user_id (FK)       Кто создал
├─ updated_by_user_id (FK)       Кто обновил
└─ timestamps                    created_at, updated_at
```

#### Индексы

```sql
KEY `priority_active` (is_active, priority)      -- Быстрый поиск активных
KEY `source_field` (source, target_field)        -- Фильтр по поставщику
KEY `material_type_active` (material_type, is_active)  -- Фильтр по типу
```

### Seed паттернов по умолчанию

При миграции создаются базовые паттерны:

#### 1. Определение кромки по названию (приоритет 10)

```
name:             "Edge by title keyword"
material_type:    "edge"
target_field:     "title"
pattern:          \bкромк[а-яa-z0-9_-]*\b
flags:            "iu"
example_input:    "Кромка ПВХ 0.4x19 мм белая"
priority:         10
```

#### 2. Определение кромки по URL (приоритет 20)

```
name:             "Edge by URL marker"
material_type:    "edge"
target_field:     "url"
pattern:          "kromka"
flags:            "iu"
example_input:    "https://site.ru/catalog/kromka/pvh-19mm"
priority:         20
```

#### 3. Определение плиты по ключевым словам (приоритет 50)

```
name:             "Plate by sheet keyword"
material_type:    "plate"
target_field:     "title"
pattern:          \b(лдсп|мдф|хдф|осб|лмдф|osb|двпо|дсп|двп|лхдф|лосб|hpl|cpl|фсф|фк)\b
flags:            "iu"
example_input:    "ЛДСП Egger 16мм дуб галифакс"
priority:         50
```

### API Endpoints

#### Список паттернов

```
GET /api/admin/material-type-patterns
GET /api/admin/material-type-patterns?material_type=plate&is_active=true&per_page=25
```

**Параметры фильтра:**
- `material_type` — Фильтр по типу (plate, edge, hardware, facade)
- `target_field` — Фильтр по полю (title, url, title_or_url)
- `source` — Фильтр по поставщику
- `is_active` — Только активные (true/false)
- `search` — Поиск по названию, описанию, regex
- `per_page` — Количество результатов на странице (default: 25)

**Ответ:**
```json
{
    "data": [
        {
            "id": 1,
            "name": "Edge by title keyword",
            "material_type": "edge",
            "pattern": "\\bкромк[а-яa-z0-9_-]*\\b",
            "priority": 10,
            "is_active": true,
            "target_field": "title",
            "flags": "iu",
            ...
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 5,
        "per_page": 25,
        "total": 105
    }
}
```

#### Просмотр паттерна

```
GET /api/admin/material-type-patterns/{id}
```

#### Создание паттерна

```
POST /api/admin/material-type-patterns

{
    "name": "Custom pattern",
    "description": "Описание",
    "is_active": true,
    "priority": 35,
    "material_type": "facade",
    "source": null,
    "rule_type": "regex",
    "target_field": "title",
    "pattern": "фасад|fasad",
    "flags": "iu",
    "use_normalized_text": true,
    "example_input": "Фасад 3D пластиковый",
    "expected_material_type": "facade"
}
```

#### Обновление паттерна

```
PUT /api/admin/material-type-patterns/{id}

{ ...новые значения... }
```

#### Удаление паттерна

```
DELETE /api/admin/material-type-patterns/{id}
```

#### ⭐ Превью паттерна перед сохранением

```
POST /api/admin/material-type-patterns/preview

{
    "name": "Новый паттерн",
    "material_type": "facade",
    "source": null,
    "rule_type": "regex",
    "target_field": "url",
    "pattern": "fasad|fassade|facade",
    "flags": "iu",
    "test_title": null,
    "test_url": "https://shop.ru/facade/terracota-red"
}
```

**Ответ (успешное совпадение):**
```json
{
    "matched": true,
    "message": "Pattern matches test_url",
    "test_value": "https://shop.ru/facade/terracota-red",
    "example": "https://shop.ru/facade/terracota-red"
}
```

**Ответ (ошибка regex):**
```json
{
    "errors": {
        "pattern": ["Regex pattern is invalid for the selected flags."]
    }
}
```

### Сервис: MaterialTypeDetectionService

#### Основной метод `resolve()`

```php
public function resolve(
    string $title,
    ?string $url = null,
    ?string $source = null,
    ?string $existingType = null
): array
```

**Параметры:**
- `$title` — Название товара (обязательно)
- `$url` — URL страницы товара (опционально)
- `$source` — Источник данных, например 'chrome_extension' (опционально)
- `$existingType` — Если у материала уже есть тип, он сохраняется (опционально)

**Возвращает:**
```php
[
    'detected_type' => 'plate',                 // Что нашли паттерны
    'resolved_type' => 'plate',                 // Финальный тип (с учётом lock)
    'detected_unit' => 'м²',                    // Единица измерения
    'resolved_unit' => 'м²',
    'decision' => 'pattern_matched',            // Причина решения
    'reason' => 'Plate by sheet keyword',       // Описание
    'matched_pattern' => [                      // Который паттерн совпал
        'id' => 3,
        'name' => 'Plate by sheet keyword',
        'priority' => 50
    ],
    'was_type_locked' => false,                 // Сохранён ли старый тип
    'lock_reason' => null,
    'normalized_title' => 'лдсп egger 16мм дуб'
]
```

#### Алгоритм `detect()`

```
1. Нормализация входных данных (lowercase, trim, т.д.)
2. Загрузка АКТИВНЫХ паттернов с сортировкой по приоритету
3. Фильтрация по source:
   - includeNULL: true (глобальные паттерны)
   - source == заданный (специфичные для поставщика)
4. Для каждого паттерна в порядке приоритета:
   a. Подготовить haystack (title, url или оба)
   b. Выполнить regex /pattern/flags против haystack
   c. Если совпадение НАЙДЕНО → вернуть тип из паттерна (First Match Wins!)
5. Если ничего не совпало → вернуть 'hardware' (default)
```

#### Вспомогательный метод `unitForType()`

```php
public static function unitForType(string $materialType): string
{
    return match($materialType) {
        Material::TYPE_PLATE => 'м²',
        Material::TYPE_EDGE => 'м.п.',
        default => 'шт'
    };
}
```

### Авторизация

Доступ к управлению паттернами ограничен для администратора:

```php
private function isAdmin(User $user): bool
{
    return (int) $user->id === 1;  // Только пользователь с id=1
}
```

---

## Взаимодействие плагина и сервера

### Общая архитектура потока

```
┌─────────────────────────────────────────┐
│ ШАГ 1: ПЛАГИН АВТОДЕТЕКТИРУЕТ           │
├─────────────────────────────────────────┤
│ content.js:                             │
│ ├─ autoDetectFields()                   │
│ ├─ detectMaterialType()       [встроен] │
│ ├─ parseDimensionsFromName()  [встроен] │
│ └─ parseEdgeDimensions()      [встроен] │
│                                         │
│ popup.js:                               │
│ ├─ tryAutomaticFill()                   │
│ └─ autoParseDimensions()      [встроен] │
└────────────┬────────────────────────────┘
             │
             ↓ AUTO_DETECT_FIELDS (сообщение)
             │
┌────────────────────────────────────────┐
│ ШАГ 2: ПОЛЬЗОВАТЕЛЬ ПРОВЕРЯЕТ          │
├────────────────────────────────────────┤
│ popup.js:                              │
│ ├─ Показывает найденные данные         │
│ ├─ Пользователь может отредактировать │
│ └─ Нажимает "Проверить"               │
└────────────┬────────────────────────────┘
             │
             ↓ POST /api/chrome/validate
             │
┌────────────────────────────────────────┐
│ ШАГ 3: СЕРВЕР ВАЛИДИРУЕТ              │
├────────────────────────────────────────┤
│ ChromeValidateController:              │
│ ├─ Проверяет формат данных             │
│ └─ Возвращает ошибки (если есть)      │
└────────────┬────────────────────────────┘
             │
             ↓ (если успех)
             │
┌────────────────────────────────────────┐
│ ШАГ 4: ПОЛЬЗОВАТЕЛЬ ОТПРАВЛЯЕТ         │
├────────────────────────────────────────┤
│ popup.js:                              │
│ └─ Нажимает "Добавить материал"      │
└────────────┬────────────────────────────┘
             │
             ↓ POST /api/chrome/extract
             │
┌────────────────────────────────────────────────┐
│ ШАГ 5: СЕРВЕР ОПРЕДЕЛЯЕТ ТИП                 │
├────────────────────────────────────────────────┤
│ ChromeExtractController::extract():            │
│                                                │
│ 1. Получить extracted fields:                  │
│    {title, price, article, length, width...}  │
│                                                │
│ 2. Вызвать MaterialTypeDetectionService:       │
│    resolve(                                    │
│        title = "ЛДСП Egger 2750x1830x16",    │
│        url = "https://site.ru/.....",         │
│        source = 'chrome_extension',           │
│        existingType = null                    │
│    )                                           │
│                                                │
│ 3. Сервис выполняет:                          │
│    a) Нормализует title                       │
│    b) SELECT паттерны WHERE is_active=true   │
│    c) Проверяет по приоритету (10, 20, 50...)│
│    d) Первое совпадение → материал тип       │
│       Regex /лдсп|мдф|.../ совпал!           │
│       → detected_type = 'plate'               │
│                                                │
│ 4. Вернуть ответ:                             │
│    {                                          │
│        extracted_data: {...},                 │
│        detected_type: 'plate',               │
│        detected_unit: 'м²',                  │
│        matched_pattern: {...}                │
│    }                                          │
└────────────┬─────────────────────────────────┘
             │
             ↓ Ответ в плагин
             │
┌────────────────────────────────────────┐
│ ШАГ 6: ПЛАГИН ПОЛУЧАЕТ РЕЗУЛЬТАТ      │
├────────────────────────────────────────┤
│ popup.js:                              │
│ ├─ Показывает determined_type          │
│ ├─ Показывает detected_unit            │
│ ├─ Пользователь подтверждает          │
│ └─ Материал добавлен в базу           │
└────────────────────────────────────────┘
```

### Пример запроса: Плагин → Сервер

```javascript
// popup.js
async function handleAddMaterial() {
    const extractPayload = {
        url: "https://supplier.ru/catalog/ldsp-16mm-dub",
        extracted: {
            title: "ЛДСП Egger 2750х1830х16 мм дуб галифакс",
            price: "2499.50",
            article: "EGGER-DUB-001",
            length: 2750,
            width: 1830,
            thickness: 16
        },
        data_sources: {
            title: "auto",      // Найдено автоматически
            price: "manual",    // Введено пользователем
            article: "auto",
            length: "auto",
            width: "auto",
            thickness: "auto"
        },
        template_id: null,
        region_id: 1
    };
    
    const response = await api.extract(
        extractPayload.url,
        extractPayload.extracted,
        extractPayload.template_id,
        extractPayload.region_id,
        extractPayload.data_sources
    );
}
```

### Пример обработки на сервере

```php
// server/app/Http/Controllers/Api/ChromeExtractController.php

public function extract(ExtractRequest $request)
{
    $extracted = $request->validated()['extracted'];
    $url = $request->validated()['url'];
    $dataSources = $request->validated()['data_sources'] ?? [];
    
    // ← ОПРЕДЕЛЕНИЕ ТИПА МАТЕРИАЛА
    $detection = $this->materialTypeDetection->resolve(
        title: $extracted['title'] ?? '',
        url: $url,
        source: 'chrome_extension',
        existingType: null
    );
    
    // Базовая валидация
    $validation = $this->materialValidator->validate(
        data: $extracted,
        material_type: $detection['resolved_type'],
        data_sources: $dataSources
    );
    
    return response()->json([
        'success' => true,
        'extracted_data' => $extracted,
        'detected_type' => $detection['detected_type'],
        'resolved_type' => $detection['resolved_type'],
        'detected_unit' => $detection['detected_unit'],
        'matched_pattern' => $detection['matched_pattern'],
        'validation_issues' => $validation['issues'] ?? [],
        'material_created' => [
            'id' => $material->id,
            'type' => $detection['resolved_type']
        ]
    ], 201);
}
```

### Ответ сервера

```json
{
    "success": true,
    "extracted_data": {
        "title": "ЛДСП Egger 2750х1830х16 мм",
        "price": "2499.50",
        "article": "EGGER-DUB-001",
        "length": 2750,
        "width": 1830,
        "thickness": 16
    },
    "detected_type": "plate",
    "resolved_type": "plate",
    "detected_unit": "м²",
    "resolved_unit": "м²",
    "matched_pattern": {
        "id": 3,
        "name": "Plate by sheet keyword",
        "priority": 50,
        "pattern": "\\b(лдсп|мдф|...)\\b"
    },
    "validation_issues": [],
    "material_created": {
        "id": 12345,
        "type": "plate"
    }
}
```

### Сценарий специфичных паттернов по поставщику

Если для конкретного поставщика нужны особые правила:

```php
// 1. Администратор добавляет паттерн с source = 'leroymerlin'
$pattern = MaterialTypePattern::create([
    'name' => 'LeRoy Merlin specific: Color=Facade',
    'material_type' => 'facade',
    'source' => 'leroymerlin',  // ← Только для этого поставщика!
    'pattern' => 'цвет|color',
    'priority' => 5              // Выше базовых паттернов
]);

// 2. При обработке данных с этого поставщика
$detection = $this->materialTypeDetection->resolve(
    title: $title,
    url: $url,
    source: 'leroymerlin',  // ← Передаём источник
    existingType: null
);

// 3. Сервис загружает:
// - Паттерны с source = NULL (глобальные)
// - Паттерны с source = 'leroymerlin' (специфичные)
// И проверяет специфичные ПЕРВЫМИ (по приоритету)
```

---

## Ключевые параметры паттернов

### `priority` — порядок проверки

Значение приоритета определяет порядок проверки паттернов:

```
priority=10  ← ПРОВЕРЯЕТСЯ ПЕРВЫМ
priority=20
priority=30
priority=50  ← ПРОВЕРЯЕТСЯ ПОСЛЕДНИМ

Правило: First Match Wins!
Как только найдено совпадение — проверка заканчивается,
результат возвращается немедленно.
```

**Рекомендации:**
- `1-20` — Специфичные для конкретных поставщиков
- `30-50` — Основные паттерны материалов
- `100+` — Fallback паттерны

### `target_field` — где ищем совпадение

```
'title'       → Только в названии товара
              Пример: "Кромка ПВХ 19х0,4"

'url'         → Только в URL страницы
              Пример: "https://site.ru/catalog/kromka/..."

'title_or_url'→ В названии ИЛИ URL
              Конкатенирует оба текста в одну строку
```

### `source` — специфичность для поставщика

```
NULL              → Применяется для ЛЮБОГО источника (глобальный)
'leroymerlin'     → Только для LeRoy Merlin
'castorama'       → Только для Castorama
'chrome_extension'→ Только для данных с плагина Chrome
```

Это позволяет переопределять глобальные паттерны для конкретных поставщиков с нестандартными форматами.

### `flags` — флаги Regex

```
'iu'  → i = case-insensitive (игнорировать верхний/нижний)
        u = unicode (работать с кириллицей и спецсимволами)

'i'   → Только case-insensitive

'm'   → Multiline (^ и $ для каждой строки)

'mu'  → Multiline + unicode
```

**По умолчанию:** `'iu'` (рекомендуется для русского текста)

---

## Процесс добавления нового паттерна

### Через админ-панель

#### 1. Администратор открывает админ-панель

```
GET https://app.prismcore.ru/admin/material-type-patterns
```

#### 2. Нажимает "Добавить паттерн"

#### 3. Заполняет форму

```
Название:              "Плитки фасадные по URL"
Описание:              "Для фасадных плиток через URL маркер"
Активен:               ✓ (галочка)
Приоритет:             35
Материал:              "facade"
Источник:              (оставить пусто для всех)
Правило:               "regex"
Поле:                  "url"
Паттерн:               "fasad|fassade|facade|tiles"
Флаги:                 "iu"
Нормализовать текст:   ✓ (галочка)
Пример входа:          "https://shop.ru/facade/terracota-red"
Ожидаемый результат:   "facade"
```

#### 4. Нажимает "Превью"

```
POST /api/admin/material-type-patterns/preview

Ответ: Pattern matches test_url ✓
```

#### 5. Нажимает "Сохранить"

```
POST /api/admin/material-type-patterns

Ответ: HTTP 201 Created
{
    "id": 15,
    "name": "Плитки фасадные по URL",
    "priority": 35,
    "is_active": true,
    ...
}
```

### ✅ Результат

**Немедленно** все следующие запросы с URL содержащим "facade", "fassade" или "fasad" будут определены как тип "facade"!

---

## Кэширование и производительность

### Текущая ситуация

- ❌ Паттерны **загружаются из БД** при каждом вызове `/api/chrome/extract`
- ❌ **Нет кэширования** на уровне сервера
- ❌ **Нет предзагрузки паттернов в плагин Chrome**

### Рекомендуемые оптимизации

#### Вариант 1: Server-side Cache (рекомендуется)

```php
// app/Services/MaterialTypes/MaterialTypeDetectionService.php

private function getPatterns(?string $source = null): Collection
{
    $cacheKey = 'material_patterns_' . ($source ?? 'global');
    
    return cache()->remember($cacheKey, 3600, function () use ($source) {
        return MaterialTypePattern::query()
            ->where('is_active', true)
            ->where(function ($q) use ($source) {
                $q->whereNull('source')
                  ->orWhere('source', $source);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    });
}

// Инвалидация при обновлении
public static function invalidateCache(string $source = null): void
{
    cache()->forget('material_patterns_' . ($source ?? 'global'));
    cache()->forget('material_patterns_global'); // Очистить общий кэш
}
```

**Профит:** 5-10x ускорение детектирования

#### Вариант 2: JSONEndpoint для плагина

```php
// routes/api.php
Route::get('/chrome/patterns', function() {
    return response()->json(
        MaterialTypePattern::where('is_active', true)->get(),
        headers: ['Cache-Control' => 'public, max-age=3600']
    );
});

// В плагине (popup.js)
async function loadPatterns() {
    const patterns = await fetch('https://app.prismcore.ru/api/chrome/patterns')
        .then(r => r.json());
    
    // Кэшировать локально
    chrome.storage.local.set({ patternCache: patterns });
}
```

#### Вариант 3: WebSocket обновления (для будущего)

```
При создании/обновлении паттерна:
1. Сервер отправляет сообщение подключённым клиентам
2. Плагины обновляют локальный кэш
3. Очень быстро, актуально в реальном времени
```

---

## Возможные расширения системы

| Функция | Статус | Описание |
|---------|--------|---------|
| **CRUD паттернов в админ-панели** | ✅ Реализовано | Полный контроль над паттернами |
| **Превью regex** | ✅ Реализовано | Проверка перед сохранением |
| **Специфичные паттерны по поставщику** | ✅ Реализовано | Поле `source` в БД |
| **Auto-sync паттернов в плагин** | ❌ Планируется | Автоматическое обновление |
| **Версионирование паттернов** | ❌ Планируется | История изменений |
| **A/B тестирование** | ❌ Планируется | Экспериментирование |
| **ML детектирование** | ❌ Планируется | Обучение на ошибках |

---

## Как администратор может повлиять на работу

### 1. Добавить новый тип материала

```
1. Определить regex для нового типа
2. Установить приоритет (не конфликтовать с существующими)
3. Добавить тестовые примеры
4. Протестировать через превью
5. Активировать паттерн

Результат: Система начнёт детектировать этот тип сразу же
```

### 2. Отключить неправильный паттерн

```
1. Найти паттерн в админ-панели
2. Нажать Edit → Снять галочку "Active"
3. Сохранить

Результат: Паттерн перестанет использоваться немедленно
```

### 3. Поднять приоритет определённого паттерна

```
Например, если видно что "Edge by title keyword" срабатывает после
"Plate by sheet keyword", хотя должно раньше:

1. Найти паттерн
2. Изменить priority с 20 на 5 (выше = проверяется первым)
3. Сохранить

Результат: Паттерн будет проверяться первым, вызвав его раньше
```

### 4. Привязать паттерн к конкретному поставщику

```
Если LeRoy Merlin испольует нестандартное кодирование материалов:

1. Создать новый паттерн (или скопировать существующий)
2. Установить "Источник" = "leroymerlin"
3. Адаптировать regex для их формата
4. Установить приоритет выше чем глобальные

Результат: Только материалы с source='leroymerlin' будут использовать
специальный паттерн
```

---

## Диагностика: Почему материал определился неправильно?

### 1. Посмотреть логи

```
tail -f server/storage/logs/laravel.log
```

### 2. Включить debug в запросе

```
POST /api/chrome/extract?debug=1

Ответ получит поле:
{
    "detection_debug": {
        "checked_patterns": [
            { "id": 1, "name": "...", "priority": 10, "matched": false },
            { "id": 2, "name": "...", "priority": 20, "matched": true }
        ],
        "stopped_at_pattern_id": 2
    }
}
```

### 3. Проверить через превью

```
POST /api/admin/material-type-patterns/preview

{
    "material_type": "plate",
    "pattern": "\\b(лдсп|мдф)\\b",
    "flags": "iu",
    "test_title": "Проблемное названием товара"
}
```

### 4. Проверить какие паттерны активны

```
GET /api/admin/material-type-patterns?is_active=true&sort=priority
```

---

## Резюме

**Chrome Extension:**
- ✅ Автоматический захват данных со страниц товаров
- ✅ Встроенное определение типа материала (быстро, offline)
- ✅ Парсинг размеров в зависимости от типа
- ✅ Система шаблонов для автоматизации по сайтам
- ✅ Schema.org / JSON-LD поддержка

**Система управления паттернами:**
- ✅ CRUD в админ-панели (логин только для id=1)
- ✅ Regex паттерны с приоритизацией (First Match Wins)
- ✅ Специфичные правила по поставщикам
- ✅ Превью перед сохранением
- ✅ Немедленное применение изменений

**Взаимодействие:**
- ✅ Плагин → Сервер: `/api/chrome/extract`
- ✅ Сервер использует паттерны из БД для определения типа
- ✅ Результат возвращается в плагин
- ⚠️ Кэширование можно оптимизировать

