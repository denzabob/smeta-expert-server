# Контур формирования PDF документов

## 📋 Оглавление

1. [Общая архитектура](#общая-архитектура)
2. [Entry Points и контроллеры](#entry-points-и-контроллеры)
3. [Pipeline данных](#pipeline-данных)
4. [Структура шаблона](#структура-шаблона)
5. [Управление разбиением на страницы](#управление-разбиением-на-страницы)
6. [Поведение при переносе](#поведение-при-переносе)
7. [Механизм DomPDF](#механизм-dompdf)
8. [Примеры потока данных](#примеры-потока-данных)

---

## Общая архитектура

### Общий поток

```
HTTP Request
    ↓
API Controllers (SmetaPdfController / ProjectRevisionController)
    ↓
ReportService::buildReport() — агрегирует data → DTO
    ↓
Blade Template (reports/smeta.blade.php) — HTML
    ↓
DomPDF (Laravel wrapper)
    ↓
PDF.download()
```

### Используемая технология

**DomPDF v3.1** (PHP HTML-to-PDF парсер с поддержкой CSS page-break)

### Конфигурация DomPDF

```php
->setPaper('a4')
->setOption('isHtml5ParserEnabled', true)      // Enable HTML5 parsing
->setOption('isPhpEnabled', false)             // Security: disable PHP execution
->setOption('defaultFont', 'DejaVu Sans')      // UTF-8 Cyrillic support
->setOption('fontDir', config('dompdf.font_dir'))
->setOption('fontCache', config('dompdf.font_cache_dir'))
```

---

## Entry Points и контроллеры

### API маршруты

| Endpoint | Контроллер | Метод | Описание |
|----------|-----------|-------|---------|
| `GET /api/smeta/pdf/{projectId}` | `SmetaPdfController` | `generate()` | Текущее состояние проекта + последняя ревизия |
| `GET /api/projects/{id}/revisions/{num}/pdf` | `ProjectRevisionController` | `pdf()` | Снимок ревизии (snapshot JSON) |
| `GET /api/projects/{id}/revisions/{num}/price-justification.pdf` | `ProjectRevisionController` | `priceJustificationPdf()` | Обоснование цен из снимка |
| `GET /v/{publicId}/pdf` | `PublicVerificationController` | `pdf()` | Публичная верификация с QR-кодом |

### Основные контроллеры

#### SmetaPdfController::generate()

```php
public function generate(Project $project)
{
    // 1. Authorize access
    $this->authorize('view', $project);

    try {
        // 2. Get report using ReportService
        $report = $this->reportService->buildReport($project);
        $reportArray = $report->toArray();

        // 3. Get publication (if exists)
        $publication = RevisionPublication::whereHas('revision', function ($q) use ($project) {
            $q->where('project_id', $project->id)->where('status', 'published');
        })->where('is_active', true)->orderByDesc('created_at')->first();

        // 4. Generate QR code
        $publicUrl = $publication ? $this->makePublicVerificationUrl($publication->public_id) : null;
        $qrSvg = $publicUrl ? $this->makeQrSvg($publicUrl) : null;

        // 5. Generate PDF
        $pdf = Pdf::loadView('reports.smeta', [
            'report' => $reportArray,
            'qrSvg' => $qrSvg,
            'revisionNumber' => $publication?->revision?->number,
            'revisionDate' => $publication?->revision?->created_at?->format('d.m.Y') ?? date('d.m.Y'),
            'snapshotHashShort' => $publication?->revision?->snapshot_hash
                ? (substr($publication->revision->snapshot_hash, 0, 8) . '…' . substr($publication->revision->snapshot_hash, -8))
                : null,
            'engineVersion' => $publication?->revision?->calculation_engine_version,
            'documentToken' => $publication?->public_id,
        ])
        ->setPaper('a4')
        ->setOption('isHtml5ParserEnabled', true)
        ->setOption('isPhpEnabled', false)
        ->setOption('defaultFont', 'DejaVu Sans')
        ->setOption('fontDir', config('dompdf.font_dir'))
        ->setOption('fontCache', config('dompdf.font_cache_dir'));

        $filename = $this->sanitizeFilename("smeta_{$project->number}_{$project->id}.pdf");
        return $pdf->download($filename);
    } catch (\Exception $e) {
        // Error handling
    }
}
```

#### ProjectRevisionController::pdf()

Загружает данные из снимка ревизии (snapshot JSON) и генерирует PDF с тем же шаблоном.

#### ProjectRevisionController::priceJustificationPdf()

Специализированный PDF с таблицей обоснования цен материалов:

```php
$justifications = $snapshot['price_justifications'] ?? [];
$pdf = Pdf::loadView('reports.price_justification', [
    'project' => $project,
    'revision' => $revision,
    'rows' => $rows,  // price justification data
])
```

---

## Pipeline данных

### ReportService::buildReport()

Последовательность получения и агрегирования данных:

```php
1. buildProjectMeta()              // Метаинформация проекта
2. calculatePlateData()            // Плитные материалы
3. calculateEdgeData()             // Кромочные материалы
4. calculateFacadeData()           // Фасадные материалы (агрегирование цен)
5. calculateOperationData()        // Операции (агрегирование одинаковых)
6. loadFittings()                  // Фурнитура
7. loadExpenses()                  // Накладные расходы
8. loadLaborWorks()                // Монтажно-демонтажные работы
9. aggregateOperationsForReport()  // Компактное представление операций
10. Aggregated profile rate justifications
11. Totals calculation
```

### Агрегирование операций

```php
private function aggregateOperationsForReport(array $operations): array
{
    $grouped = [];
    foreach ($operations as $operation) {
        // Build unique key: id|name|unit|price
        $key = implode('|', [
            (string) ($operation->id ?? 0),
            mb_strtolower(trim((string) $operation->name), 'UTF-8'),
            trim((string) $operation->unit),
            number_format((float) $operation->cost_per_unit, 6, '.', ''),
        ]);

        if (!isset($grouped[$key])) {
            $grouped[$key] = new OperationAggregateDto(
                id: $operation->id,
                name: $operation->name,
                unit: $operation->unit,
                cost_per_unit: $operation->cost_per_unit,
                quantity: 0,
                total_cost: 0,
            );
        }
        
        // Accumulate quantities and totals
        $grouped[$key]->quantity += $operation->quantity;
        $grouped[$key]->total_cost += $operation->total_cost;
    }
    
    return array_values($grouped);
}
```

**Результат**: Одинаковые операции объединяются в одну строку таблицы = **компактнее на странице**.

---

## Структура шаблона

### Основной шаблон: reports/smeta.blade.php

Полный путь к файлу:
```
server/resources/views/reports/smeta.blade.php (1800+ строк)
```

#### Разделы документа (в порядке следования)

| № | Раздел | CSS класс | Поведение |
|---|--------|----------|----------|
| 1 | Header (QR, реквизиты) | `.header` | `page-break-after: avoid` |
| 2 | Summary (итоги) | `.summary-wrap` | `page-break-inside: avoid` |
| 3 | Перечень позиций | `.positions-table` | `page-break-inside: auto` (разбивается по строкам) |
| 4 | Плитные материалы | `table` | `page-break-inside: auto` |
| 5 | Кромочные материалы | `table` | `page-break-inside: auto` |
| 6 | Фасадные материалы | `table` | `page-break-inside: auto` |
| 7 | Операции | `table` | `page-break-inside: auto` |
| 8 | Монтажно-демонтажные работы | `table` | `page-break-inside: auto` |
| 9 | Фурнитура | `table` | `page-break-inside: auto` |
| 10 | Накладные расходы | `table` | `page-break-inside: auto` |
| 11 | Итоговая стоимость | `.totals-section` | `page-break-inside: avoid` |
| 12 | Обоснование расчётов | `.card.no-break` | **page-break: explicit** перед разделом |
| 13 | Справочные сведения | `.card` | `page-break-inside: auto` |
| 14 | Описания коэффициентов | `.card` | `page-break-inside: auto` |
| 15 | Подписи | `.signatures` | `page-break-inside: avoid` |

---

## Управление разбиением на страницы

### CSS правила для page-break

#### Явный перенос на новую страницу

```css
.page-break { 
    page-break-after: always; 
}
```

**Где используется**: В конце профессиональных разделов (обоснование расчётов, справочные блоки).

#### Не переносить с предыдущим контентом

```css
.keep-with-next { 
    page-break-after: avoid; 
}

.section-title {
    page-break-after: avoid;  /* Название идёт с контентом */
}
```

#### Не разбивать блок внутри

```css
.no-break, 
.summary-wrap, 
.signatures { 
    page-break-inside: avoid;
}
```

**Результат**: Если блок не помещается на текущей странице, он переносится ЦЕЛИКОМ на следующую.

#### Разрешить разбиение

```css
table, 
.card { 
    page-break-inside: auto;
}

tr { 
    page-break-inside: avoid;  /* Строка - целиком */
    page-break-after: auto;    /* Но после неё можно разбить */
}

thead, thead tr { 
    page-break-inside: avoid;  /* Заголовок - целиком */
    page-break-after: avoid;   /* + не отделяется от тела таблицы */
}
```

### Параметры @page

```css
@page {
    size: A4;
    margin: 15mm 10mm 18mm 25mm;  /* top right bottom left */
}

body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 10.4pt;
    line-height: 1.35;
}
```

---

## Поведение при переносе

### ✅ ВСЕГДА ЦЕЛИКОМ на одной странице (avoid)

1. **Header** (заголовок с реквизитами) → `page-break-after: avoid`
2. **Summary card** (итоговая таблица) → `page-break-inside: avoid`
3. **Section title** + первый контент → `keep-with-next`
4. **Каждая строка таблицы** → `tr { page-break-inside: avoid }`
5. **Подписи** в конце → `page-break-inside: avoid`
6. **Каждая карточка обоснования** → `.card.no-break`

**Стратегия**: Если блок не помещается на текущей странице → переносится ЦЕЛИКОМ на следующую.

### ⚠️ МОЖЕТ БЫТЬ РАЗБИТА (auto)

1. **Большие таблицы** (позиции, операции, фурнитура) → разбиваются по строкам
2. **Карточки с длинными текстами** (справочные блоки) → разбиваются если нужно
3. **Таблица фасадов с котировками** → если много источников

**Стратегия**: DomPDF разбивает таблицы по `<tr>` границам, повторяя `<thead>` на каждой странице.

### Пример: Таблица не помещается на странице

```
СТРАНИЦА 1:
┌────────────────────────────────────┐
│ ... предыдущий контент             │
├────────────────────────────────────┤
│ Материалы | Кол-во | Цена          │ ← thead
├────────────────────────────────────┤
│ Дуб 18мм  | 10     | 5000          │ ← tr (no-break)
│ Сосна 16м | 5      | 3000          │ ← tr (no-break)
│ Ель 20мм  | 2      | 8000          │ ← tr (no-break)
│           ↓ [НЕ ПОМЕЩАЕТСЯ]        │
└────────────────────────────────────┘

СТРАНИЦА 2:
┌────────────────────────────────────┐
│ Материалы | Кол-во | Цена          │ ← thead повторяется!
├────────────────────────────────────┤
│ Берёза 15 | 3      | 4000          │ ← tr (перенесена целиком)
│ ...                               │
└────────────────────────────────────┘
```

**Механизм**: `<thead>` имеет `display: table-header-group` → DomPDF повторяет её при разрыве таблицы.

---

## Механизм DomPDF

### Как DomPDF работает

1. **Парсит HTML + CSS** для всех `page-break-*` свойств
2. **Измеряет высоту каждого блока** на основе:
   - Шрифта и размера текста
   - Падинга и маржина
   - Высоты строк
3. **Заполняет страницу** последовательно, сверху вниз
4. **При переполнении**:
   - Проверяет `page-break-inside: avoid` → если есть, переносит ВЕСЬ блок на следующую
   - Если в таблице `<tbody tr>` → может разбить таблицу по строкам
   - Если блок больше одной страницы → всё равно разбит (DomPDF не может рисовать бесконечные страницы)

### Таблич-специфичная логика

```html
<table>
    <thead>
        <tr>...</tr>
    </thead>
    <tbody>
        <tr>...</tr>
        ...
    </tbody>
    <tfoot>
        <tr>...</tr>
    </tfoot>
</table>
```

```css
thead { display: table-header-group; }  /* Повторяется в начале каждой страницы */
tfoot { display: table-footer-group; }  /* Повторяется в конце каждой страницы */
tr { page-break-inside: avoid; }        /* Каждая строка - целиком */
```

**Результат**: При разрыве таблицы:
- `<thead>` повторяется на каждой странице
- `<tfoot>` повторяется на каждой странице
- Строки переносятся целиком

---

## Примеры потока данных

### Типичный поток на 5-6 страниц

```
PAGE 1:
├─ Header (реквизиты, QR)
│  └─ page-break-after: avoid
├─ Summary (итоги)
│  └─ page-break-inside: avoid → [ЦЕЛИКОМ на странице]
└─ Positions table (часть 1)
   └─ tr: avoid, auto break

PAGE 2:
├─ Positions table (часть 2) 
│  └─ thead повторяется
├─ Plates table (плиты)
├─ Edges table (кромки)
└─ Facades summary

PAGE 3:
├─ Facades quotes (часть 1) [большая таблица]
│  └─ thead повторяется
├─ Facades quotes (часть 2)
└─ Operations table (часть 1)

PAGE 4:
├─ Operations table (часть 2)
│  └─ thead повторяется
├─ Labor works table
├─ Fittings table
└─ Expenses table

PAGE 5:
├─ Totals section
│  └─ page-break-inside: avoid
├─ PAGE BREAK (явный)
└─ Profile rate justifications

PAGE 6:
├─ Profile rate (card no-break)
│  ├─ Методика расчета
│  ├─ Таблица источников
│  └─ Таблица работ
├─ Reference blocks
├─ Waste descriptions
└─ Signatures
   └─ page-break-inside: avoid
```

### Сценарий: Обоснование расчётов не помещается

```
PAGE 4:
├─ ... Totals section (page-break-inside: avoid) ✓
├─ PAGE BREAK (div class="page-break") ✓
│  ↓ Осталось 3см до конца страницы
│
├─ Profile rate justification (card no-break)
│  ├─ Профиль (6см)
│  ├─ Статистика (4см)
│  └─ [ВСЕГО 15см > 3см осталось]
│     ↓ НЕ ПОМЕЩАЕТСЯ → переносится на следующую

PAGE 5:
├─ Profile rate justification (card no-break) [ЦЕЛИКОМ]
│  ├─ Профиль (6см)
│  ├─ Статистика (4см)
│  ├─ Методика (5см)
│  └─ Источники (15см)
└─ ...
```

---

## Специфичные решения в коде

### Проблема: Большая таблица не разбивается

**Решение**: `page-break-inside: auto` + `<thead>` повторяется

```css
table {
    page-break-inside: auto;
    break-inside: auto;
}

thead { display: table-header-group; }
```

### Проблема: Итоги попадают на новую половину страницы

**Решение**: `.summary-wrap { page-break-inside: avoid }`

```css
.summary-wrap {
    page-break-inside: avoid;  /* Целиком на странице */
}
```

→ Если не помещается, переносится ЦЕЛИКОМ на следующую страницу.

### Проблема: Обоснование расчётов разбиваются неправильно

**Решение**: Явный `<div class="page-break"></div>` перед разделом

```blade
<!-- === ОБОСНОВАНИЯ РАСЧЁТОВ === -->
@if(!empty($report['profile_rate_justifications']))
    <div class="page-break"></div>  <!-- Явный перенос -->
    
    @foreach($report['profile_rate_justifications'] as $justification)
        <div class="card no-break">
            <!-- Контент каждого обоснования -->
        </div>
    @endforeach
@endif
```

### Проблема: Операции занимают слишком много строк

**Решение**: `aggregateOperationsForReport()` сжимает одинаковые

```php
// ДО агрегирования: 20 строк разных операций
// ПОСЛЕ агрегирования: 5 строк (одинаковые объединены)
→ компактнее на странице!
```

### Проблема: Строка таблицы попадает на границу страницы

**Решение**: `tr { page-break-inside: avoid; }`

```css
tr { 
    page-break-inside: avoid;  /* Строка - целиком */
    page-break-after: auto;    /* Но после неё можно разбить */
}
```

→ Если строка не помещается, переносится целиком на следующую страницу.

### Проблема: Котировки фасадов занимают много места

**Решение**: Перемещены в конец документа после явного `page-break`

```blade
@if(!empty($aggregatedGroups))
    <div class="page-break"></div>
    <div class="section-title">Котировки фасадов по группам позиций</div>
    @foreach($aggregatedGroups as $aggGroup)
        <!-- Таблица котировок -->
    @endforeach
@endif
```

→ Не прерывают основной контент, размещены отдельным разделом.

---

## Дополнительные функции

### Данные, передаваемые в шаблон

```php
[
    'report' => [
        'project' => { number, address, expert_name, ... },
        'positions' => [ ... ],
        'plates' => [ { name, area_details, area_with_waste, ... } ],
        'edges' => [ { name, length_linear, length_with_waste, ... } ],
        'facades' => [ { name, position_details, price_method, ... } ],
        'operations' => [ { name, quantity, cost_per_unit, total_cost } ],
        'fittings' => [ { name, article, unit, quantity, unit_price, total_cost } ],
        'expenses' => [ { type, description, cost } ],
        'labor_works' => [ { title, basis, hours, rate_per_hour, cost } ],
        'totals' => { materials_cost, operations_cost, fittings_cost, ... grand_total },
        'profile_rate_justifications' => [ ... ],
        'text_blocks' => [ { title, text, enabled } ],
    ],
    'qrSvg' => 'data:image/svg+xml;base64,...',
    'revisionNumber' => 5,
    'revisionDate' => '20.01.2026',
    'snapshotHashShort' => '09c0dff2…769bc59d',
    'engineVersion' => '1.0.5',
    'documentToken' => 'public_id_...',
]
```

### Специальные шаблоны

#### Price Justification Template

```
server/resources/views/reports/price_justification.blade.php

Список материалов с:
- Источниками цен
- URL-ами поставщиков
- Скриншотами (если загружены)
- Ценами и датами
```

---

## Технические детали

### Поддерживаемые браузеры и форматы

- **Входные данные**: HTML + CSS с UTF-8
- **Выходной формат**: PDF/A-1b (совместимость)
- **Кодировка**: UTF-8 (Cyrillic support via DejaVu Sans)
- **Размер бумаги**: A4 (210mm × 297mm)
- **Поля**: top=15mm, right=10mm, bottom=18mm, left=25mm

### Размеры шрифтов в документе

| Элемент | Размер |
|---------|--------|
| Основной текст | 10.4pt |
| Заголовки секций | 11pt |
| Таблицы | 9.2pt |
| Малый текст | 9pt |
| Минимальный | 7pt (SHA-256 хеши) |

### Цветовая схема

- **Основной текст**: `#111`
- **Вторичный текст**: `#606060`
- **Границы**: `#d7d7d7`
- **Фон таблиц**: `#fbfbfb`, `#f0f0f0`
- **Акценты**: `#4a4a4a` (левые границы блоков)

---

## Заключение

PDF генерирование в приложении построено на **комбинации DomPDF + CSS page-break правил**, которая обеспечивает:

✅ **Профессиональный вид** — правильно разбитые страницы  
✅ **Корректные переносы** — никаких "висящих" заголовков  
✅ **Полная информация** — все данные на месте  
✅ **UTF-8 поддержка** — корректные Cyrillic символы  
✅ **Автоматизация** — minimal manual control required  

Ключ к успеху — правильное использование:
- `page-break-inside: avoid` для критичных блоков
- `page-break-inside: auto` для больших таблиц
- Явных `page-break` перед логическими разделами
- Агрегирования данных для компактности
