# Интерфейс редактора сметы — подробное описание

> Документ описывает весь контур редактирования сметы в представлении `/projects/:id/edit`.  
> Написан на основе анализа исходного кода `client/src/views/ProjectEditorView.vue` (~8 600 строк).  
> Цель — системный анализ интерфейса для его дальнейшего улучшения.

---

## Содержание

1. [Общее описание и архитектура](#1-общее-описание-и-архитектура)
2. [Инициализация и загрузка данных](#2-инициализация-и-загрузка-данных)
3. [Панель инструментов (PageHeader)](#3-панель-инструментов-pageheader)
4. [Таблица позиций](#4-таблица-позиций)
5. [Пресеты колонок](#5-пресеты-колонок)
6. [Строчные действия при наведении (RowHoverActions)](#6-строчные-действия-при-наведении-rowhovergactions)
7. [Массовые операции (Bulk Actions)](#7-массовые-операции-bulk-actions)
8. [Диалог создания / редактирования позиции](#8-диалог-создания--редактирования-позиции)
   - 8.1 [Вкладка «Панель»](#81-вкладка-панель)
   - 8.2 [Вкладка «Фасад»](#82-вкладка-фасад)
9. [Правая панель позиции (Position Drawer)](#9-правая-панель-позиции-position-drawer)
10. [Секция «Материалы»](#10-секция-материалы)
    - 10.1 [Плитные материалы](#101-плитные-материалы)
    - 10.2 [Кромка](#102-кромка)
11. [Секция «Операции»](#11-секция-операции)
12. [Секция «Фурнитура»](#12-секция-фурнитура)
13. [Секция «Нормируемые работы»](#13-секция-нормируемые-работы)
    - 13.1 [Ставки по профилям (ProfileRatesSection)](#131-ставки-по-профилям-profileratesSection)
    - 13.2 [Источники нормо-часа](#132-источники-нормо-часа)
    - 13.3 [Детализация работы — Шаги (Steps)](#133-детализация-работы--шаги-steps)
    - 13.4 [AI-декомпозиция шагов](#134-ai-декомпозиция-шагов)
14. [Секция «Накладные расходы»](#14-секция-накладные-расходы)
15. [Секция «Ревизии»](#15-секция-ревизии)
    - 15.1 [Запуск ревизионного прогона (RevisionRun)](#151-запуск-ревизионного-прогона-revisionrun)
    - 15.2 [Мониторинг прогона и ручное закрытие](#152-мониторинг-прогона-и-ручное-закрытие)
    - 15.3 [История ревизий](#153-история-ревизий)
16. [PDF-генерация](#16-pdf-генерация)
17. [Настройки проекта (ProjectSettingsDrawer)](#17-настройки-проекта-projectsettingsdrawer)
18. [Импорт позиций из Excel (ImportPositionsDialog)](#18-импорт-позиций-из-excel-importpositionsdialog)
19. [Вычисляемые агрегаты и формулы](#19-вычисляемые-агрегаты-и-формулы)
20. [Уведомления (Snackbar)](#20-уведомления-snackbar)
21. [Схема взаимодействий пользователя](#21-схема-взаимодействий-пользователя)

---

## 1. Общее описание и архитектура

**URL:** `/projects/:id/edit`  
**Компонент:** `client/src/views/ProjectEditorView.vue`  
**Фреймворк:** Vue 3 (Composition API) + TypeScript + Vuetify 3  
**Управление состоянием:** Чистый Vue 3 — `ref()`, `reactive()`, `computed()`, `watch()`. Pinia не используется.

### Структура страницы

```
┌─────────────────────────────────────────────┐
│  PageHeader (toolbar)                        │
│  [PDF] [Ревизия] [Обновить] [Настройки]      │
├─────────────────────────────────────────────┤
│  SectionCard «Позиции»                       │
│    Панель массовых операций                  │
│    Кнопки пресетов колонок    [+ Добавить]   │
│    v-data-table с позициями                  │
├─────────────────────────────────────────────┤
│  SectionCard «Материалы»                     │
│    v-data-table плитные   (с expand)         │
│    v-data-table кромка    (с expand)         │
├─────────────────────────────────────────────┤
│  SectionCard «Операции»                      │
│    v-data-table (auto + manual, с expand)    │
├─────────────────────────────────────────────┤
│  SectionCard «Фурнитура»                     │
├─────────────────────────────────────────────┤
│  SectionCard «Нормируемые работы»            │
│    ProfileRatesSection                       │
│    Источники нормо-часа                      │
│    Перетаскиваемая таблица работ             │
├─────────────────────────────────────────────┤
│  SectionCard «Накладные расходы»             │
├─────────────────────────────────────────────┤
│  SectionCard «Ревизии»                       │
│    Активный прогон / История                 │
└─────────────────────────────────────────────┘
```

Справа — выдвижные панели (Drawer):
- **Position Drawer** → детали выбранной позиции  
- **ProjectSettingsDrawer** → настройки проекта  

Над всем — стек диалогов (v-dialog): создание позиции, фурнитуры, расходов, работ, источников нормо-часа, шагов, ревизий, ручного закрытия.

---

## 2. Инициализация и загрузка данных

При монтировании компонента выполняется параллельная загрузка:

```
onMounted:
  ├─ loadReferences()        → [GET /api/detail-types, /api/materials (+catalog), /api/operations,
  │                              /api/units, /api/regions, /api/position-profiles]
  ├─ fetchData()             → [GET /api/projects/:id, /api/projects/:id/positions,
  │                              /api/projects/:id/fittings, /api/projects/:id/expenses,
  │                              recalcOperations(), loadNormohourSources(), loadLaborWorks()]
  ├─ fetchLatestRevision()   → GET /api/projects/:id/revisions/latest
  └─ fetchRevisions(1)       → GET /api/projects/:id/revisions
```

**Кэш материалов:** Список материалов кэшируется на 25 секунд (TTL = 25 000 мс) и объединяет:
- собственный список (`GET /api/materials`)  
- каталожные режимы `library`, `public`, `curated` (пагинированные, `per_page=200`)

**Prefetch:** Если проект уже был загружен ранее (`consumePrefetchedProject`), HTTP-запрос пропускается.

При загрузке данных проекта нормализуются булевы флаги (из `0/1` → `false/true`) и числовые значения коэффициентов.  
Если сервер возвращает 404, пользователь перенаправляется на список проектов с flash-сообщением.

---

## 3. Панель инструментов (PageHeader)

Расположена в `<PageHeader>` в верхней части страницы.

| Элемент | Условие показа | Действие |
|---|---|---|
| Кнопка **«PDF»** | всегда | Генерирует PDF. Если нет ревизий — Snackbar-предупреждение. Иначе → `GET /api/smeta/pdf/:id` (blob) → скачивание `smeta_{id}.pdf` |
| Чип **«Ревизия #N»** / статус | есть latestRevision | Показывает номер и дату последней ревизии |
| Кнопка **«Ревизия (строгая)»** | всегда | Запускает новый RevisionRun → `POST /api/revision-runs/start` |
| Кнопка **«Обновить»** | всегда | `refreshAll()` — перезагрузка всех данных со спиннером |
| Кнопка **«Настройки»** | всегда | Открывает ProjectSettingsDrawer |
| Кнопка **«Импорт»** | всегда | Открывает ImportPositionsDialog |

---

## 4. Таблица позиций

**Компонент:** `v-data-table` с `items` = `positions`, `density` = `tableDensity` (compact / comfortable).

### Заголовок секции

- Отображает количество позиций `(positions.length)`
- Кнопки пресетов колонок — горизонтальная группа `v-btn-toggle`
- Переключатель плотности таблицы
- Кнопка **«+ Добавить позицию»** → `openPositionDialog()`

### Строки таблицы

Каждая строка имеет:
- **Чекбокс** выбора (поддерживает Shift+клик для диапазонного выбора)
- Поля в соответствии с активным пресетом
- При наведении (`@mouseenter/@mouseleave`) показывается панель действий `RowHoverActions`
- Клик по строке (вне чекбокса и кнопок) → открывает Position Drawer

### Подсветка

- `highlightedPositionId` — новодублированная позиция мигает 2 секунды (класс `row-highlight`)
- После дублирования таблица автоматически прокручивается к новой строке

### Типы позиций

| Тип (`kind`) | Иконка | Описание |
|---|---|---|
| `panel` | mdi-view-dashboard | Плитная деталь (корпус, полка и т.д.) |
| `facade` | mdi-door | Фасадная деталь с ценой за м² |

---

## 5. Пресеты колонок

Шесть предустановленных наборов колонок, сохраняются в `localStorage` (`positions_column_preset`).

| Пресет | Ключ | Колонки |
|---|---|---|
| Базовые | `basic` | Название, Тип, Тип детали, Материал, Размеры, Кол-во |
| Материалы | `materials` | Название, Тип, Материал, Кромка, Кол-во |
| Размеры | `sizes` | Название, Тип, Ширина, Длина, Кол-во, Площадь |
| Кромка | `edges` | Название, Материал, Кромка, Схема кромки |
| Фасады | `facades` | Название, Основа, Толщина, Декор, Размеры, Кол-во, Площадь, Цена/м², Сумма |
| Итоги | `totals` | Название, Тип, Материал, Кол-во, Площадь, Цена/м², Сумма |

**Все возможные колонки:**

| Ключ | Заголовок | Ширина |
|---|---|---|
| `custom_name` | Название | 200 |
| `kind` | Тип | 90 |
| `detail_type` | Тип детали | 130 |
| `material_short` | Материал | 180 |
| `edge_material_short` | Кромка | 150 |
| `base_material` | Основа | 80 |
| `thickness` | Толщина | 80 |
| `decor_label` | Декор | 160 |
| `size` | Размеры (Ш×В) | 120 |
| `width` | Ширина | 80 |
| `length` | Длина | 80 |
| `quantity` | Кол-во | 70 |
| `edge_scheme` | Схема кромки | 130 |
| `area_total` | Площадь (м²) | 110 |
| `price_per_m2` | Цена за м² | 110 |
| `unit_price` | Цена за ед. | 100 |
| `total_price` | Сумма | 100 |

---

## 6. Строчные действия при наведении (RowHoverActions)

При наведении курсора на строку появляется панель с двумя уровнями действий.

### Быстрые действия (видны сразу):

| Кнопка | Иконка | Действие |
|---|---|---|
| Изменить | mdi-pencil | `openPositionDrawer(item)` — открыть правую панель |
| Дублировать | mdi-content-duplicate | `clonePosition(item)` — POST копии, прокрутка к новой строке |
| Удалить | mdi-delete (red) | `deletePosition(item)` — confirm → DELETE |

### Меню «...» (дополнительные):

| Пункт | Иконка | Действие |
|---|---|---|
| Детали позиции | mdi-information-outline | `openPositionDrawer(item)` |
| Скопировать данные | mdi-content-copy | Копирует JSON {name, material, edge, size, quantity} в буфер |

Все действия заблокированы (`disabled`) пока `processingPositionId !== null` (идёт клонирование).

---

## 7. Массовые операции (Bulk Actions)

Панель появляется когда выбрана хотя бы одна позиция (`selectedPositionsRaw.length > 0`).

### Элементы панели:

1. **Счётчик** — `{N} выбрано`
2. **Кнопка «Выбрать все»** — `selectAllVisiblePositions()`
3. **Кнопка «Снять выбор»** — `clearSelection()`
4. **Выпадающий список действий** (`v-select` из `bulkActionItems`)
5. **Дополнительный селектор** (в зависимости от выбранного действия)
6. **Переключатель режима** `Строго / Частично` (IosToggle)
7. **Кнопка «Применить»** (активна, когда `bulkActionReady`)
8. **Счётчик применимых** `→ {N} из {M}` (при `bulkSkippedCount > 0`)

### Доступные действия:

| Значение | Заголовок | Дополнительный элемент | Ограничение |
|---|---|---|---|
| `replace_material` | Заменить материал основы | Селектор `materialsPlate` | Только панели |
| `replace_edge` | Заменить материал кромки | Селектор `materialsEdge` | Только панели |
| `replace_facade_material` | Заменить фасад | Селектор `facadeMaterials` | Только фасады |
| `set_edge_scheme` | Установить обработку торцов | Схема кромки (6 вариантов) | Только панели + должна быть кромка |
| `clear_field` | Очистить выбранное поле | Список полей для очистки | Зависит от типа |
| `delete` | Удалить позиции | — | Все типы |

### Очищаемые поля (`clear_field`):

| Значение | Заголовок | Ограничение |
|---|---|---|
| `material_id` | Материал основы | Только панели |
| `edge_material_id` | Материал кромки | Только панели |
| `edge_scheme` | Обработка торцов | Только панели |
| `custom_name` | Название | Все |
| `facade_material_id` | Фасад | Только фасады |

### Режимы применения:

- **Строго** — операция отменяется, если хотя бы одна позиция несовместима; показывается предупреждение
- **Частично** — операция применяется только к совместимым позициям, несовместимые пропускаются

### API вызов:

```
POST /api/projects/:id/positions/bulk
Body: {
  action: 'update' | 'delete',
  ids: number[],
  select_all: false,
  mode: 'strict' | 'partial',
  updates?: { material_id?, edge_material_id?, edge_scheme?, facade_material_id? },
  clear_field?: string
}
```

После успеха — Snackbar с результатом, сброс выбора и параметров.

---

## 8. Диалог создания / редактирования позиции

Открывается через `openPositionDialog()` (новая) или `editPosition(item)` (редактирование).  
Диалог содержит **две вкладки**: «Панель» и «Фасад».

### Общие поля (обе вкладки):

- **Название** (`custom_name`) — необязательное текстовое поле
- **Ширина / Длина** — числовые поля с **калькулятором выражений**:
  - Допустимы математические выражения: `600+100`, `2500/2`, `1800-50`
  - Разрешённые символы: `0-9`, `+`, `-`, `*`, `/`, `.`, `(`, `)`
  - Запрещено: начало с `0`, отрицательный результат
  - Результат округляется до целого мм
  - Под полем показывается: `= {result} мм` или сообщение об ошибке
- **Количество** (`quantity`) — число + быстрые кнопки: `×2`, `×4`, `×6`, `×8`, `×10`

### 8.1 Вкладка «Панель»

Поля панельной позиции:

| Поле | Тип | Описание |
|---|---|---|
| Тип детали (`detail_type_id`) | v-select из `detailTypes` | При выборе автоматически устанавливает схему кромки |
| Материал основы (`material_id`) | v-autocomplete из `materialsPlate` | Триггер пересчёта операций |
| Материал кромки (`edge_material_id`) | v-autocomplete из `materialsEdge` | Триггер пересчёта операций |
| Схема кромки (`edge_scheme`) | v-select / v-btn-group | 6 вариантов (none, O, =, \|\|, L, П) |

**Визуальный превью схемы кромки:**
- Мини-прямоугольник с подсвеченными сторонами
- Стороны подсвечены согласно логике `isEdgeSideActive(scheme, side)`
- Под превью — текстовое описание схемы (`getEdgeSchemeSummary`)

**Визуальные подсказки на полях ширины/длины:**
- Синяя рамка снизу/сверху у поля «Ширина» если схема затрагивает эти стороны
- Синяя рамка слева/справа у поля «Длина» если схема затрагивает эти стороны
- Реализовано через CSS классы: `edge-hint-width-tb`, `edge-hint-length-lr` и др.

**Проверка размера при сохранении:**
- Если размер детали превышает размер листа материала — `confirm()` с предупреждением

### 8.2 Вкладка «Фасад»

Поля фасадной позиции:

| Поле | Тип | Описание |
|---|---|---|
| Фасадный материал (`facade_material_id`) | v-autocomplete из `facadeMaterials` | Поиск с debounce 300мс |
| Метод ценообразования (`price_method`) | v-select | `single` / `mean` / `median` / `trimmed_mean` |
| Таблица котировок | v-data-table | Загружается из `GET /api/facade-materials/:id/quotes` |

**Таблица котировок:**

| Колонка | Описание |
|---|---|
| Чекбокс | Включить/исключить котировку из агрегации |
| Поставщик / источник | Название источника цены |
| Цена за м² | `price_per_m2` |
| Флаги несоответствия | `mismatch_flags` (если есть) |
| Дата | Дата котировки |

**Агрегация цены (`aggPreview`):**
- Вычисляется локально в computed
- Формулы агрегации:
  - `mean` — среднее арифметическое выбранных котировок
  - `median` — медиана
  - `trimmed_mean` — усечённая средняя (обрезает 10% снизу и сверху, минимум 3 котировки)
- Превью перед сохранением: `{aggregated} ₽/м² | мин {min} - макс {max} | {N} источников | Area {area} м² | Итого {total} ₽`

**Автоматическое определение метода:**
- 0 котировок → `single`
- 1 котировка → `single`
- 2+ котировок + метод был `single` → автоматически переключается на `mean`

**При выборе фасадного материала** автоматически заполняются:
- `base_material_label`, `thickness_mm`, `finish_type`, `finish_name`, `decor_label`, `price_per_m2`, `material_price_id`

**Сохранение фасадной позиции:**
- Поля панели очищаются (`material_id`, `edge_material_id`, `edge_scheme`, `detail_type_id` → null/none)
- В payload добавляется `price_method`, `quote_material_price_ids`, `quote_mismatch_flags`

**Сохранение через API:**
```
POST /api/projects/:id/positions            (создание)
PUT  /api/project-positions/:id             (обновление)
```

---

## 9. Правая панель позиции (Position Drawer)

**Ширина:** 420px, позиция `right`, открывается при клике на строку.

Панель содержит:
1. **Заголовок** — `custom_name` или тип детали, тип позиции (Панель/Фасад)
2. **Инлайн-редактирование полей:**
   - Ширина, Длина — с калькулятором выражений (аналогично диалогу)
   - Количество
   - Схема кромки — `v-select` → при смене на `none` автоматически обнуляет `edge_material_id`
   - Материал кромки — `v-autocomplete`
3. **Кнопка «⇄»** — переставить ширину и длину местами (`toggleSelectedPositionDimensions()`)
4. **Визуальный превью схемы кромки** — аналогично диалогу

**Для фасадных позиций** дополнительно:
- Метод ценообразования (`price_method`) с inline-переключением
- При смене метода → `PUT /api/project-positions/:id` с `{price_method}` → обновляет `price_per_m2`, `price_min`, `price_max`, `price_sources_count` из ответа сервера

**Секция материалов в drawer:**
- Краткая сводка по использованным материалам позиции

**Котировки фасада в drawer:**
- Таблица с котировками и агрегированной ценой

**Обновление поля:**
```
PUT /api/project-positions/:id   { [field]: value }
```
После обновления планируется пересчёт операций (`scheduleRecalc()`).

---

## 10. Секция «Материалы»

Вычисляемый раздел — агрегирует данные всех позиций.

### 10.1 Плитные материалы

**Computed `plateData`:** Группирует все позиции типа `panel` с материалом `type=plate`.

Для каждого плитного материала:
| Поле | Формула |
|---|---|
| `area_details` | Σ (width/1000 × length/1000 × quantity) по всем позициям |
| `waste_coeff` | `getWasteCoefficientForPlate()` |
| `area_with_waste` | `area_details × waste_coeff` |
| `sheet_area` | `length_mm × width_mm / 1_000_000` м² |
| `sheets_count` | `⌈area_with_waste / sheet_area⌉` (режим листов) |
| `price_per_sheet` | `material.price_per_unit` |
| `price_per_m2` | `price_per_sheet / sheet_area` (расчётная) |
| `total_price` | `sheets_count × price_per_sheet` |

**Режим расчёта** (переключатель в настройках):
- **По листам** (умолчание): показывает `Листов = {sheets_count}`, скрывает `Цена за м²`
- **По площади**: показывает `Площадь к оплате (м²) = area_with_waste`, скрывает `Размер листа`, `Цена за лист`

**Коэффициент отходов для плит:**
- Если `apply_waste_to_plate = false` → коэффициент = 1.0 (без отходов)
- Иначе: `waste_plate_coefficient` если задан, иначе общий `waste_coefficient`

**Раскрываемые строки (expand):**
- Список позиций, использующих данный материал с размерами, кол-вом и площадью

**Флаг проблем (`hasPlateMaterialIssue`):**
- Нет размера листа (`sheet_area = 0`) — предупреждение в строке
- Нет цены за лист (`price_per_sheet = 0`) — предупреждение в строке

### 10.2 Кромка

**Computed `edgeData`:** Группирует по `edge_material_id` с учётом схемы кромки.

Для каждого кромочного материала расчёт длины по схеме кромки:

| Схема | Формула периметра одной детали |
|---|---|
| `O` (вкруг) | `2 × (width_м + length_м)` |
| `=` (по ширине) | `2 × length_м` |
| `\|\|` (по длине) | `2 × width_м` |
| `L` (Г-образно) | `width_м + length_м` |
| `П` (П-образно) | `2 × width_м + length_м` |
| `none` | 0 |

| Поле | Формула |
|---|---|
| `length_linear` | Σ (perimeter_one × quantity) по всем позициям |
| `waste_coeff` | `getWasteCoefficientForEdge()` |
| `length_with_waste` | `length_linear × waste_coeff` |
| `price_per_unit` | `material.price_per_unit` |
| `total_price` | `length_with_waste × price_per_unit` |

---

## 11. Секция «Операции»

Смешанный список автоматических (рассчитанных) и ручных операций.

**Computed `operations`:** Строится из `_operations.ref` с дополнительными полями:
- `is_manual` — если `type === 'manual'` или `source === 'manual'`
- `total_cost` — `quantity × cost_per_unit`
- `_cached_details` — кэш деталей для раскрытия

**Заголовки таблицы:**

| Колонка | Описание |
|---|---|
| (expand) | Кнопка раскрытия деталей |
| Наименование | Название операции |
| Категория | Тип операции |
| Цена за ед. | `cost_per_unit` |
| Кол-во | (с иконкой автоматики/ручного режима) |
| Ед. изм. | `unit` |
| Стоимость | `quantity × cost_per_unit` |
| Источник | Иконка: робот (авто) / карандаш (ручная) |
| Действия | Только для ручных: редактировать, удалить |

**Типы операций и детали при раскрытии:**

| Тип | Определение | Детали expand |
|---|---|---|
| Окромление | Название содержит «кромк» | Список материалов × позиций × длины |
| Распиловка | Название содержит «пил» | Список плит × площади |
| Сверление | Название содержит «сверл» / «отверст» | По типам деталей: кол-во деталей × отверстий/деталь |
| Ручная | `type = 'manual'` | Текст «Введено вручную экспертом» |

**Для сверления (авто):** Если `source = 'detail_type'`, клиент **пересчитывает** количество отверстий сам (8 отверстий × кол-во деталей каждого типа), а не берёт значение с сервера.

**Добавление ручной операции:**
- Диалог `operationDialog` с выбором из справочника `allOperations`
- Поля: `operation_id`, `quantity`, `note`
- `POST /api/projects/:id/project-operations`
- `PUT /api/project-operations/:id` (редактирование)
- `DELETE /api/project-operations/:id` (удаление)

**Автоматический пересчёт `scheduleRecalc()`:**
Вызывается после любого изменения позиций (с debounce 350мс):
```
GET /api/projects/:id/operations  →  _operations.value = data
```

---

## 12. Секция «Фурнитура»

Таблица фурнитуры (hardware-позиции проекта).

**Заголовки:** Название, Кол-во, Цена, Действия

**Добавление через диалог:**
- Основное поле — `material_id` (v-select из `hardwareMaterials`, тип `hardware`)
- При выборе материала → автозаполнение: `name`, `article`, `unit`, `unit_price`, `source_url`
- Поля: `quantity`, `note`

**Разрешение `material_id` при редактировании:**
- Если `material_id` не задан, ищет совпадение сначала по `article`, затем по `name`

**API:**
```
POST   /api/projects/:id/fittings       (создание)
PUT    /api/project-fittings/:id        (обновление)
DELETE /api/project-fittings/:id        (удаление)
```

---

## 13. Секция «Нормируемые работы»

Монтажно-сборочные работы с нормированием по нормо-часу.

### 13.1 Ставки по профилям (ProfileRatesSection)

Отдельный компонент `ProfileRatesSection.vue`.

- Отображает ставки нормо-часа по профилям должностей
- Показывает: `normohour_rate` — базовая ставка из настроек проекта
- Поддерживает блокировку ставок (`ratesLocked` — все `profileRates.is_locked = true`)
- Кнопка **«Пересчитать ставки»** (если есть несоответствие)
- Кнопка **«Зафиксировать ставки»** (lock)

**Реактивность:** При изменении `normohour_rate` в настройках — все `laborWorks.cost` пересчитываются немедленно через `watch`.

### 13.2 Источники нормо-часа

Список источников обоснования ставки нормо-часа (до 20 записей).

**Структура записи:**
| Поле | Описание |
|---|---|
| `source` | Источник (сайт, справочник) — **обязательное** |
| `position_profile` | Должность/специальность |
| `salary_range` | Диапазон зарплаты |
| `period` | Период актуальности |
| `link` | URL ссылка |
| `note` | Примечание |

**Диалог редактирования:**
- Валидация: `source` обязателен, `link` проверяется по URL-паттерну
- API: `POST/PUT/DELETE /api/projects/:id/normohour-sources/:id`

### 13.3 Таблица работ

Перетаскиваемая таблица (drag-and-drop для сортировки) с нормируемыми работами.

**Заголовки:** Наименование, Основание, Норма ч, Ставка ₽/ч, Сумма ₽

**Поля `LaborWork`:**
| Поле | Описание |
|---|---|
| `title` | Наименование работы |
| `basis` | Нормативное основание (ГОСТ, СНиП и т.д.) |
| `hours` | Количество нормо-часов |
| `rate_per_hour` | Ставка за нормо-час |
| `cost_total` | Итоговая стоимость |
| `hours_source` | Источник нормы |
| `position_profile_id` | Профиль должности |
| `sort_order` | Порядок в списке |

**Drag-and-drop сортировка:**
- `@dragstart` / `@dragend` на строке  
- `@drop` → оптимистичное обновление UI + `PUT /api/labor-works/reorder` со списком ID

**RowHoverActions для работ:**
- **Детализация** — открывает стepsDialog
- **Изменить** — открывает laborWorkDialog
- **Удалить** — confirm → DELETE

**Итог:** `laborWorksTotal` = сумма `cost_total` всех работ

### 13.4 Детализация работы — Шаги (Steps)

Диалог `stepsDialog` — детализация выбранной нормируемой работы на подэтапы.

**Структура диалога (3 панели):**

**Левая панель — список шагов:**
- Заголовок: `{selectedLaborWork.title}`
- Поиск по шагам (фильтрация по `title`, `basis`, `note`)
- Переключатель режима: `Сортировка` / `Перемещение`
- Каждая строка: `sort_order`, `title`, `hours ч`, `input_data`, `basis`, `note`
- Действия на строке: редактировать (карандаш), удалить (корзина)
- При удалении — confirm-диалог с названием шага

**Правая панель — AI ассистент + форма добавления:**

**Блок AI-декомпозиции:**  
Заголовок: «ИИ ассистент»

Поля контекста AI:
| Поле | Тип | Варианты |
|---|---|---|
| Домен | v-select | furniture, construction, electrical, plumbing, cleaning |
| Тип действия | v-select | install, dismantle, repair, adjust |
| Условия | v-select | normal, cramped |
| Состояние объекта | v-select | rough, living, emergency |
| Материал | text | свободный ввод |
| Тип объекта | text | свободный ввод |
| Желаемые часы | number | опциональное |

Кнопка **«Декомпозировать»** → вызов `aiDecompose()` → `POST /api/work-decompose`

**Панель результата AI:**
- Список предложенных шагов с чекбоксами выбора
- Поля шага в превью: `title`, `hours`, `input_data`, `basis`
- Кнопки:
  - **«Заменить ({N})»** — заменить все существующие шаги
  - **«Добавить ({N})»** или **«Добавить все»** — добавить выбранные шаги
  - **«Отмена»** — скрыть панель результата

### Форма добавления / редактирования шага:

| Поле | Обязательное | Описание |
|---|---|---|
| Наименование этапа | ✅ | Многострочное поле |
| Время | ✅ | Числовое, `> 0`, шаг 0.25, суффикс «ч» |
| Объём / входные данные | ✗ | Пример: `1 шт.`, `6 модулей` |
| Основание | ✗ | ГОСТ, СНиП, методика |
| Примечание | ✗ | Коллапсируемое поле (кнопка «+ Добавить примечание») |

**Footer диалога:**  
`Итого: {totalStepsHours.toFixed(2)} ч`  
Если итог шагов ≠ `selectedLaborWork.hours` — показывает подсказку `(в смете: {N} ч)`

**API шагов:**
```
GET    /api/labor-works/:id/steps
POST   /api/labor-works/:id/steps
PUT    /api/labor-work-steps/:id
DELETE /api/labor-work-steps/:id
```

### 13.4 AI-декомпозиция шагов

**Endpoint:** `POST /api/work-decompose`

**Payload:**
```json
{
  "title": "Наименование работы",
  "basis": "...",
  "domain": "furniture",
  "action_type": "install",
  "constraints": "normal",
  "site_state": "rough",
  "material": "...",
  "object_type": "...",
  "desired_hours": 3.5
}
```

**Результат:** `DecomposeResponse` — список шагов с `title`, `hours`, `input_data`, `basis`.

**Фидбэк:** После применения AI-шагов (через `aiFeedback()`) отправляется обратная связь по отпечатку (`fingerprint`), если он ещё не был отправлен.

---

## 14. Секция «Накладные расходы»

Таблица дополнительных расходов по проекту.

**Заголовки:** Название, Описание, Сумма, Действия

**Поля записи:**
| Поле | Описание |
|---|---|
| `name` | Название расхода (обязательное) |
| `description` | Описание |
| `amount` | Сумма в рублях |

**API:**
```
POST   /api/projects/:id/expenses
PUT    /api/projects/:id/expenses/:id
DELETE /api/projects/:id/expenses/:id
```

---

## 15. Секция «Ревизии»

### 15.1 Запуск ревизионного прогона (RevisionRun)

Кнопка **«Ревизия (строгая)»** в toolbar → `createSnapshot()`:

```
POST /api/revision-runs/start   { project_id }
→ Response: { run_id, status, total_items }
```

Созданный прогон сохраняется в `localStorage` по ключу `project:{id}:activeRevisionRunId` для восстановления после перезагрузки.

**Начальные значения:**
```
activeRevisionRun = { id, project_id, status: 'PENDING', total_items }
revisionRunItems = []
```

### 15.2 Мониторинг прогона и ручное закрытие

**Polling:** После старта запускается интервальный опрос каждые **2.5 секунды**:
```
GET /api/revision-runs/:projectId/:runId
→ Response: { run: RevisionRun, items: RevisionRunItem[] }
```

**Статусы `RevisionRun`:**

| Статус | Цвет | Описание |
|---|---|---|
| `PENDING` | grey | В очереди |
| `IN_PROGRESS` | primary (синий) | Выполняется |
| `NEEDS_MANUAL` | warning (жёлтый) | Требует ручного закрытия |
| `READY` | success (зелёный) | Готово к финализации |
| `FINALIZED` | success | Финализировано |
| `FAILED` | error (красный) | Ошибка |

**Автоматическая финализация:**  
Когда статус меняется с любого на `READY` — вызывается `finalizeRevisionRun()` автоматически.

**Statuses `RevisionRunItem`:**

| Статус | Иконка | Описание |
|---|---|---|
| `PENDING` | spinner | В обработке |
| `OK` | ✅ success | Цена найдена |
| `OK_NO_PRICE` | ⚠️ warning | Обработан, цена не найдена |
| `NEEDS_MANUAL` | ✋ warning | Требует ручного ввода |
| `BLOCKED` | 🚫 error | Заблокирован |
| `TIMEOUT` | ⏱️ warning | Таймаут |
| `PARSE_ERROR` | ❌ error | Ошибка парсинга |
| `NO_TEMPLATE` | — grey | Нет шаблона парсера |

**Прогресс-бар прогона:**
- `ok_items / total_items` — заполнение
- `failed_items` — счётчик проблемных

**Кнопка «Retry»** (если `failed_items > 0` или статус `NEEDS_MANUAL`):
```
POST /api/revision-runs/:projectId/:runId/retry
```

**Ручное закрытие позиции (NEEDS_MANUAL):**

Диалог `manualCloseDialog` с полями:
| Поле | Описание |
|---|---|
| Цена за единицу | Числовое поле, `> 0` (автопредзаполнение из истории цен) |
| Валюта | `RUB` (по умолчанию) |
| URL источника | Ссылка на страницу товара |
| Скриншот | Drag&Drop / вставка из буфера (Ctrl+V) / выбор файла |

Скриншот:
- Принимает только файлы типа `image/*`
- Поддерживает `dragenter/dragleave/drop` с визуальным индикатором
- Поддерживает вставку из буфера (paste на весь документ, если открыт диалог)

```
POST /api/revision-runs/:runId/items/:itemId/manual
  FormData: { price_per_unit, currency, source_url, region_id, screenshot: File }
```

**Кнопка «Finalize»** (если статус `READY`):
```
POST /api/revision-runs/:projectId/:runId/finalize
→ Response: { revision: { number, ... }, pdf: { smeta: url, price_justification: url } }
```

После финализации:
- Очищается активный прогон
- Обновляется список ревизий
- Показываются ссылки на PDF (сметный + обоснование цен)

### 15.3 История ревизий

Таблица `v-data-table` с пагинацией (10 на страницу).

**Заголовки:** №, Статус, Создана, Автор, Действия

**Статусы ревизий:**

| Статус | Цвет | Название |
|---|---|---|
| `locked` | primary | Зафиксирована |
| `published` | success | Опубликована |
| `stale` | grey | Устарела |

**Действия в строке:**

| Действие | Условие |
|---|---|
| Просмотр snapshot JSON | всегда |
| Скачать PDF сметы | всегда |
| Скачать PDF обоснования цен | всегда |
| **Опубликовать** | статус = `locked` |
| **Снять публикацию** | статус = `published` |

**API:**
```
GET  /api/projects/:id/revisions/:number          (просмотр)
GET  /api/projects/:id/revisions/:number/pdf       (PDF сметы, blob)
GET  /api/projects/:id/revisions/:number/price-justification.pdf
POST /api/projects/:id/revisions/:number/publish
POST /api/projects/:id/revisions/:number/unpublish
```

**Просмотр snapshot:**  
Диалог с JSON `snapshot_json` отформатированным с `JSON.stringify(parsed, null, 2)`.

---

## 16. PDF-генерация

Два способа получить PDF:

1. **Из toolbar (актуальный):**  
   Кнопка «PDF» → `generatePdf()` → только если `hasRevisions = true`
   ```
   GET /api/smeta/pdf/:projectId  (responseType: 'blob')
   → скачивание smeta_{id}.pdf
   ```

2. **Из истории ревизий:**  
   Для конкретной ревизии:
   ```
   GET /api/projects/:id/revisions/:number/pdf
   → скачивание smeta_{projectId}_rev_{number}.pdf
   ```

3. **После финализации прогона:**  
   Ссылки `revisionRunPdfLinks` — открываются в новой вкладке (`window.open`).

---

## 17. Настройки проекта (ProjectSettingsDrawer)

Компонент `ProjectSettingsDrawer.vue`, открывается через `openSettingsDrawer()`.

Передаёт `project` и вызывает:
- `handleSettingsSaved(updatedProject)` → `Object.assign(project, updated)` + `updateProject()`
- `handleSettingsDrawerClosed()` → Snackbar «Все изменения применены»
- `handleSettingsError(error)` → Snackbar с ошибкой

**Сохраняемые поля проекта:**

| Группа | Поля |
|---|---|
| Реквизиты | `number`, `expert_name`, `address`, `region_id` |
| Коэффициенты отходов | `waste_coefficient` (общий), `waste_plate_coefficient`, `waste_edge_coefficient`, `waste_operations_coefficient` |
| Флаги применения | `apply_waste_to_plate`, `apply_waste_to_edge`, `apply_waste_to_operations` |
| Описания коэффициентов | `waste_*_description.{title, text}`, `show_waste_*_description` |
| Режим расчёта | `use_area_calc_mode` (листы / площадь) |
| Ремонтный коэф. | `repair_coefficient` |
| Материалы по умолчанию | `default_plate_material_id`, `default_edge_material_id` |
| Нормо-час | `normohour_rate`, `normohour_region`, `normohour_date`, `normohour_method`, `normohour_justification` |
| Текстовые блоки | `text_blocks[]` — `{title, text, enabled}` (rich-text, до 10 000 символов) |

**Автосохранение проекта:**  
При изменении данных планируется автосохранение через debounce 800мс (`scheduleAutoSave`).  
При закрытии drawer — немедленный flush (`flushAutoSave`).  
Флаг `suppressAutoSave` предотвращает сохранение во время загрузки данных.

**Нормализация `text_blocks`:**
- Из JSON-строки → массив объектов
- Из старого формата (массив строк) → конвертируется в `[{title, text, enabled}]`
- При вставке текста (`onPasteText`) — конвертация HTML→plain text с сохранением переносов строк

```
PUT /api/projects/:id
Body: { ...project, text_blocks: [...], waste_*_description: {...}, ... }
```

---

## 18. Импорт позиций из Excel (ImportPositionsDialog)

Компонент `ImportPositionsDialog.vue`.  
При успешном импорте вызывается `handlePositionsImported({ created_count, skipped_count, errors_count })`:
- Snackbar: `«Импортировано {N} позиций»`
- Перезагрузка `GET /api/projects/:id/positions`

---

## 19. Вычисляемые агрегаты и формулы

### Площадь позиции:
```
area_total = (width_mm / 1000) × (length_mm / 1000) × quantity   [м²]
```

### Цена позиции:
```
# Фасад:
total_price = area_total × price_per_m2

# Панель:
total_price = unit_price × quantity
```

### Цена за м² (отображение):
```
# Прямая (есть price_per_m2):
→ показывается price_per_m2, kind = 'direct'

# Производная (из unit_price):
→ derived_price_per_m2 = total_price / area_total, kind = 'derived'
```

### Коэффициент отходов (fallback-цепочка):

```
for PLATE:
  apply_waste_to_plate = false → 1.0
  waste_plate_coefficient (если задан) → используем его
  waste_coefficient (общий) → используем его

for EDGE:  [аналогично с waste_edge_coefficient]
for OPERATIONS:  [аналогично с waste_operations_coefficient]
```

---

## 20. Уведомления (Snackbar)

Все уведомления в правом нижнем углу через `v-snackbar`:

```typescript
showNotification(message: string, color: 'info' | 'success' | 'warning' | 'error', timeout = 3000)
```

| Тип | Цвет | Примеры |
|---|---|---|
| info | синий | По умолчанию |
| success | зелёный | «Сохранено», «Импортировано», «Позиция закрыта вручную» |
| warning | жёлтый | «Сначала создайте ревизию», «Несовместимые позиции», «Ошибка выражения» |
| error | красный | «Ошибка сохранения», «PDF generation error» |

---

## 21. Схема взаимодействий пользователя

```
ОТКРЫТИЕ СТРАНИЦЫ
  │
  ├── ЗАГРУЗКА ДАННЫХ
  │     ├── Материалы (каталог + собственные)
  │     ├── Типы деталей, Регионы, Единицы
  │     ├── Проект (настройки + коэффициенты)
  │     ├── Позиции, Фурнитура, Расходы
  │     ├── Операции (расчётные)
  │     ├── Нормо-час источники
  │     ├── Нормируемые работы
  │     └── Ревизии (история + последняя)
  │
  ├── РАБОТА С ПОЗИЦИЯМИ
  │     ├── [+ Добавить] → Диалог позиции
  │     │     ├── Тип: Панель / Фасад
  │     │     ├── Панель: материал, кромка, схема, тип детали, размеры, кол-во
  │     │     ├── Фасад: фасадный материал, котировки, метод агрегации
  │     │     └── [Сохранить] → POST/PUT → перезагрузка данных
  │     │
  │     ├── [Клик по строке] → Position Drawer
  │     │     ├── Редактирование полей (inline PUT)
  │     │     ├── Расчët выражений для размеров
  │     │     └── Смена метода цены фасада
  │     │
  │     ├── [Наведение на строку] → RowHoverActions
  │     │     ├── Изменить → Drawer
  │     │     ├── Дублировать → POST (copy) → highlight
  │     │     └── Удалить → confirm → DELETE
  │     │
  │     ├── [Чекбоксы] → Панель массовых операций
  │     │     ├── Выбор пресета действия
  │     │     ├── Режим Строго / Частично
  │     │     └── [Применить] → POST bulk → перезагрузка
  │     │
  │     └── [Пресет колонок] → Смена набора колонок (localStorage)
  │
  ├── ПРОСМОТР МАТЕРИАЛОВ (только чтение, computed)
  │     ├── Плитные: площадь + листы + цена
  │     └── Кромка: длина + цена
  │
  ├── ОПЕРАЦИИ
  │     ├── Авто → пересчитываются при каждом изменении позиций
  │     ├── [Expand] → детали расчёта
  │     └── [+ Добавить операцию] → ручная операция
  │
  ├── ФУРНИТУРА
  │     └── [+ Добавить] → Диалог → выбор из каталога hardware-материалов
  │
  ├── НОРМИРУЕМЫЕ РАБОТЫ
  │     ├── [+ Добавить работу] → Диалог
  │     ├── [Детализация] → Диалог шагов
  │     │     ├── Список шагов (редактирование / удаление)
  │     │     ├── AI-декомпозиция
  │     │     │     ├── Ввод контекста (домен, действие, условия...)
  │     │     │     ├── [Декомпозировать] → POST → список шагов
  │     │     │     └── [Заменить/Добавить] → POST steps
  │     │     └── Форма добавления шага
  │     ├── Drag-and-drop сортировка → PUT reorder
  │     └── ProfileRatesSection (ставки+блокировка)
  │
  ├── НАКЛАДНЫЕ РАСХОДЫ
  │     └── [+ Добавить расход] → Диалог
  │
  ├── РЕВИЗИИ
  │     ├── [Ревизия (строгая)] → POST start → RevisionRun
  │     │     ├── Polling 2.5с
  │     │     ├── NEEDS_MANUAL → [Закрыть вручную] → Диалог
  │     │     │     ├── Ввод цены
  │     │     │     └── Скриншот (drag/paste/file)
  │     │     ├── READY → автофинализация
  │     │     └── FINALIZED → новая ревизия создана, ссылки на PDF
  │     │
  │     └── История ревизий
  │           ├── [Просмотр] → JSON snapshot
  │           ├── [PDF сметы] → скачивание
  │           ├── [PDF обоснований] → скачивание
  │           ├── [Опубликовать] → POST publish
  │           └── [Снять публикацию] → POST unpublish
  │
  ├── PDF
  │     └── [PDF] в toolbar (требует хотя бы одна ревизия) → скачивание
  │
  └── НАСТРОЙКИ [шестерёнка]
        ├── ProjectSettingsDrawer
        │     ├── Реквизиты, Регион
        │     ├── Коэффициенты отходов
        │     ├── Режим расчёта (листы/площадь)
        │     ├── Ставка нормо-часа + метаданные
        │     └── Текстовые блоки (rich-text)
        └── [Импорт] → Excel import dialog
```

---

## Приложение: TypeScript-интерфейсы ключевых сущностей

```typescript
interface Position {
  id: number | null
  kind: 'panel' | 'facade'
  detail_type_id: number | null
  material_id: number | null
  facade_material_id: number | null
  material_price_id: number | null
  edge_material_id: number | null
  edge_scheme: 'none' | 'O' | '=' | '||' | 'L' | 'П'
  width: number             // мм
  length: number            // мм
  quantity: number
  custom_name: string | null
  unit_price?: number | null
  // Facade:
  decor_label?: string | null
  thickness_mm?: number | null
  base_material_label?: string | null
  price_per_m2?: number | null
  price_method?: string | null
  price_quotes?: any[] | null
}

interface LaborWork {
  id: number
  title: string
  basis: string
  hours: number
  rate_per_hour: number
  cost_total: number
  hours_source?: string
  position_profile_id?: number | null
  sort_order?: number
}

interface RevisionRun {
  id: number
  project_id: number
  status: 'PENDING' | 'IN_PROGRESS' | 'NEEDS_MANUAL' | 'READY' | 'FINALIZED' | 'FAILED'
  total_items: number
  ok_items: number
  failed_items: number
}
```

---

*Документ актуален для состояния кодовой базы на момент анализа.*  
*Основной файл: `client/src/views/ProjectEditorView.vue` (~8 622 строки)*
