# Сессия ревизии: скриншоты цен и обоснование документа

## Назначение

«Сессия ревизии» — это автоматизированный процесс подтверждения актуальности цен всех материалов проекта. По итогам сессии формируется:
1. PDF «Смета» с расчётами
2. PDF «Обоснование цен» — документ с доказательной базой, включающий скриншоты страниц поставщиков с ценами

---

## Интерфейс: `/projects/{id}/edit`

Блок «Сессия ревизии» отображается в редакторе проекта. Отображает:
- Идентификатор и статус текущей сессии
- Счётчики: OK / Проблемных / Всего
- Progress bar (при работе)
- Таблицу позиций с их статусами
- Кнопки управления: Обновить, Повторить, Финализировать

---

## Жизненный цикл сессии

### Статусы `RevisionRun`

| Статус | Описание |
|--------|---------|
| `PENDING` | Сессия создана, обработка ещё не началась |
| `IN_PROGRESS` | Идёт автоматический сбор данных |
| `NEEDS_MANUAL` | Часть позиций требует ручного закрытия |
| `READY` | Все позиции закрыты, готово к финализации |
| `FINALIZED` | Ревизия зафиксирована, PDF сформированы |
| `FAILED` | Критическая ошибка |

### Статусы `RevisionRunItem`

| Статус | Описание |
|--------|---------|
| `PENDING` | Позиция ожидает обработки |
| `OK` | Цена подтверждена (автоматически или вручную) |
| `OK_NO_PRICE` | Материал без цены, позиция закрыта |
| `BLOCKED` | Сайт поставщика заблокирован (Cloudflare и т.п.) |
| `TIMEOUT` | Превышено время ожидания |
| `PARSE_ERROR` | Ошибка парсинга страницы |
| `NO_TEMPLATE` | Нет URL источника или шаблона |
| `NEEDS_MANUAL` | Требует ручного ввода цены |

---

## Поток данных: от запуска до PDF

### Шаг 1. Запуск сессии

```
POST /api/projects/{projectId}/revisions/run
```

**Серверная логика (`RevisionRunController::start`):**
1. Строит отчёт проекта через `ReportService::buildReport`
2. Собирает список позиций: плиты, кромка из панельных позиций, фурнитура из `project_fittings`
3. Создаёт запись `RevisionRun` (status=`PENDING`, total_items=N)
4. Для каждой позиции создаёт `RevisionRunItem` с `source_url` материала
5. Ставит в очередь `RunRevisionUpdateJob`

**Привязка к проекту:**
- `RevisionRun.project_id` = ID проекта
- `RevisionRunItem.project_position_id` = ID позиции проекта
- `RevisionRunItem.project_fitting_id` = ID фурнитуры (если это фурнитура)
- `RevisionRunItem.material_id` = ID материала

### Шаг 2. Автоматическая обработка позиций

`RunRevisionUpdateJob` → dispatches `UpdateMaterialObservationForRevisionItem` для каждого item.

Каждый item обрабатывается через **EvidencePipeline**:

```
UpdateMaterialObservationForRevisionItem
    └── EvidencePipelineService::process(revisionRunItemId)
            │
            ├── Есть source_url у материала?
            │        │
            │        └── НЕТ → status=NO_TEMPLATE
            │
            ├── Есть шаблон парсинга для домена?
            │        │
            │        └── НЕТ → status=NEEDS_MANUAL
            │
            ├── Парсинг страницы + захват скриншота
            │        │
            │        ├── Успех → status=OK, создаёт MaterialPriceHistory
            │        ├── Cloudflare/блокировка → status=BLOCKED
            │        └── Ошибка → status=PARSE_ERROR или NEEDS_MANUAL
            │
            └── RevisionRunItem обновляется
```

### Шаг 3. Автоматический скриншот (для парсируемых позиций)

При успешном парсинге `ScreenshotCaptureService::captureByUrl()` вызывает Python-скрипт:

```bash
python3 parser/screenshot_by_url.py \
  --url "https://supplier.ru/product/123" \
  --price "1234.50" \
  --currency "RUB" \
  --region-id 1 \
  --revision-run-item-id 42
```

**Скрипт `parser/screenshot_by_url.py`:**
- Использует **Playwright** (браузер Chromium headless)
- Реальный User-Agent (Chrome 120)
- Блокирует трекеры/аналитику/медиа для ускорения
- До 2 попыток при неудаче
- Таймаут навигации: 20 000 мс (конфигурируемо)
- Общий таймаут: 45 с (конфигурируемо)

**Параллельность:**
- Семафор на файловых локах: до 3 одновременных скриншотов (конфигурируемо)
- При исчерпании слотов: status=`blocked`

**Путь сохранения автоматического скриншота:**

```
server/storage/app/public/
  screenshots/
    {vendor}/               ← домен поставщика (без www)
      {YYYY-MM-DD}/         ← дата съёмки
        {sha1_hash}.jpg     ← SHA-1 от нормализованного URL, формат JPEG
```

Relative path (записывается в БД):
```
screenshots/{vendor}/{YYYY-MM-DD}/{sha1_hash}.jpg
```

Пример:
```
screenshots/leroy-merlin.ru/2026-03-28/a3f7c2b1d9e84f2a.jpg
```

### Шаг 4. Ручное закрытие позиции (NEEDS_MANUAL)

Для позиций с проблемным статусом пользователь открывает диалог «Ручное закрытие».

#### Интерфейс диалога

- Поле «Цена за единицу» — число (предзаполняется последней известной ценой)
- Поле «Валюта» — строка
- Поле «Source URL» — опционально
- **Зона загрузки скриншота** (обязательно):
  - `<v-file-input accept="image/*">` — обычный выбор файла
  - Drag-and-drop: зона с обработчиками `dragenter` / `dragover` / `drop`
  - Вставка из буфера: обработчик `paste` (Ctrl+V — вставляет изображение прямо из буфера)

#### API-запрос

```
POST /api/revisions/run/{runId}/items/{itemId}/manual
Content-Type: multipart/form-data

price_per_unit  = 1234.50
currency        = RUB
source_url      = https://supplier.ru/product/123   (опционально)
region_id       = 1                                  (опционально)
screenshot_file = <файл изображения>                 (обязательно, image/*, max 10 MB)
```

#### Серверная логика (`RevisionRunController::manual`)

1. Валидация: price > 0, currency строка, screenshot image max 10240 KB
2. Определяет материал позиции (приоритет: `item.material` → `fitting.material` → `position.facadeMaterial` → `position.edgeMaterial` → `position.material`)
3. **Сохраняет файл:**
   ```php
   $path = $validated['screenshot_file']->store(
       'screenshots/manual/' . now()->format('Y/m'),
       'public'
   );
   ```
4. Создаёт `MaterialPriceHistory`:
   - `source_type = 'manual'`
   - `screenshot_path` = путь к файлу
   - `is_verified = false`
   - `true_score = 0`
5. Обновляет `RevisionRunItem`: `status=OK`, `price_history_id` = ID записи
6. Пересчитывает счётчики `RevisionRun` (`ok_items`, `failed_items`)
7. Если `failed_items === 0` → статус сессии переходит в `READY`

**Путь сохранения ручного скриншота:**

```
server/storage/app/public/
  screenshots/
    manual/
      {YYYY/MM}/
        {original_filename_or_uuid}
```

Relative path (записывается в БД):
```
screenshots/manual/2026/03/abc123.jpg
```

### Шаг 5. Финализация сессии

```
POST /api/projects/{projectId}/revisions/run/{runId}/finalize
```

Доступно только при `failed_items === 0`.

**Серверная логика (`RevisionRunController::finalize`):**
1. Проверяет что все items в статусе OK
2. Собирает массив `justifications` для каждого item:
   ```json
   {
     "project_position_id": 5,
     "material_id": 42,
     "name": "Плита ЛДСП 16мм 2750×1830",
     "article": "12345",
     "unit": "м²",
     "material_type": "plate",
     "price_per_unit": 1234.50,
     "currency": "RUB",
     "source_url": "https://supplier.ru/product/123",
     "observed_at": "2026-03-28T10:00:00+03:00",
     "screenshot_path": "screenshots/leroy-merlin.ru/2026-03-28/a3f7c2b1.jpg",
     "true_score": 80,
     "source_type": "web"
   }
   ```
3. Вызывает `SnapshotService::createSnapshot` — создаёт запись `ProjectRevision` со `snapshot_json`, включающим массив `price_justifications`
4. Обновляет `RevisionRun.status = FINALIZED`
5. Возвращает ссылки на PDF:
   - `smeta`: `/api/projects/{id}/revisions/{number}/pdf`
   - `price_justification`: `/api/projects/{id}/revisions/{number}/price-justification.pdf`

---

## Хранение скриншотов

### Физическое расположение

| Тип | Путь на диске |
|-----|--------------|
| Автоматический (парсер) | `server/storage/app/public/screenshots/{vendor}/{дата}/{sha1}.jpg` |
| Ручной (загружен пользователем) | `server/storage/app/public/screenshots/manual/{год}/{месяц}/{filename}` |

Доступны через веб по адресу:
```
https://app.prismcore.ru/storage/screenshots/...
```
(через symlink `public/storage` → `storage/app/public`)

### В базе данных

Таблица `material_price_history`:

| Поле | Описание |
|------|---------|
| `screenshot_path` | Relative path от `storage/app/public/` (или `NULL` если скриншот не был сделан) |
| `source_type` | `'web'` — автоматический, `'manual'` — ручной, `'chrome_ext'` — из расширения |

Таблица `project_revisions`:

Поле `snapshot_json` содержит сериализованный снапшот ревизии, включая массив `price_justifications` с полем `screenshot_path` для каждой позиции.

---

## Формирование PDF «Обоснование цен»

### Маршрут

```
GET /api/projects/{project}/revisions/{number}/price-justification.pdf
```

### Серверная логика (`ProjectRevisionController::priceJustificationPdf`)

1. Загружает `ProjectRevision` по номеру
2. Десериализует `snapshot_json`
3. Извлекает массив `price_justifications`
4. Рендерит Blade-шаблон `resources/views/reports/price_justification.blade.php`
5. Генерирует PDF через `Barryvdh\DomPDF`, бумага A4

### Встраивание скриншота в PDF

Шаблон `price_justification.blade.php` для каждой позиции:

```blade
@if(!empty($row['screenshot_path']) && file_exists(storage_path('app/public/' . $row['screenshot_path'])))
    <img src="{{ storage_path('app/public/' . $row['screenshot_path']) }}" alt="screenshot" />
@else
    <div class="shot-empty">Скриншот отсутствует</div>
@endif
```

> **Важно:** DomPDF встраивает скриншот как **локальный файл** по абсолютному пути (`storage_path()`), не через URL. Это позволяет включать изображения в PDF без HTTP-запросов.

### Структура раздела обоснования в PDF

Для каждой позиции отображается:
- Название материала, артикул, единица измерения
- Тип материала
- Дата наблюдения цены
- Ссылка на источник (URL поставщика)
- Цена с валютой
- **Скриншот страницы поставщика** (JPEG)

---

## Диаграмма хранения скриншотов

```
                    ЗАПУСК СЕССИИ
                         │
          ┌──────────────┴──────────────┐
          │                             │
   Авто (парсер)                  Вручную (пользователь)
          │                             │
   EvidencePipeline             ManualCloseDialog
          │                             │ FormData + screenshot_file
   ScreenshotCaptureService      POST /api/revisions/run/{id}/items/{id}/manual
          │                             │
   screenshot_by_url.py          Laravel ->store('screenshots/manual/Y/m', 'public')
   (Playwright Chromium)                │
          │                             │
   storage/app/public/          storage/app/public/
   screenshots/{vendor}/        screenshots/manual/
   {date}/{sha1}.jpg            {year}/{month}/{file}
          │                             │
          └──────────┬──────────────────┘
                     │
              material_price_history
              (screenshot_path = relative path)
                     │
              ФИНАЛИЗАЦИЯ СЕССИИ
                     │
              snapshot_json → price_justifications[].screenshot_path
                     │
              PDF generation (DomPDF)
              img src = storage_path('app/public/' + screenshot_path)
```

---

## Привязка к конкретному проекту

Цепочка связей:

```
Project
  └── RevisionRun           (project_id)
        └── RevisionRunItem  (revision_run_id, project_position_id / project_fitting_id, material_id)
              └── MaterialPriceHistory  (price_history_id → screenshot_path)
        └── ProjectRevision  (snapshot_json.price_justifications → screenshot_path)
```

Скриншот **физически не привязан к проекту** — он хранится глобально в `storage/app/public/screenshots/` и ссылается через `material_price_history.screenshot_path`. Один и тот же скриншот может быть использован в нескольких ревизиях, если материал переиспользуется.

---

## Именование PDF-файлов

| PDF | Имя файла |
|-----|----------|
| Смета | `smeta_{project.number}_rev_{revision.number}.pdf` |
| Обоснование цен | `price_justification_{project.number}_rev_{revision.number}.pdf` |

Специальные символы в имени заменяются на `_`.
