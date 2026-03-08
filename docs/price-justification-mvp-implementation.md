# MVP: Скриншоты-обоснования цен и strict revision gate

## План
1. Проверить существующие компоненты парсинга/скриншотов и встроить их без переработки архитектуры.
2. Добавить БД-слой для strict gate (revision runs/items), true_score, URL-normalization следов.
3. Реализовать backend API для запуска/мониторинга/ретрая/ручного закрытия/финализации ревизии.
4. Добавить отдельный PDF “Обоснование цен” на базе snapshot ревизии.
5. Включить публичные/приватные и active/disabled шаблоны парсинга.
6. Добавить минимальные тесты URL-нормализации.

## Изменения
- Проверка parser: в `parser/` уже есть Playwright и возможность делать screenshot (`parse_product_page(...take_screenshot)`), поэтому добавлен отдельный режим-скрипт `parser/screenshot_by_url.py`, а не новый сервис с нуля.
- Добавлен единый `UrlNormalizer` и подключён в потоки material/catalog/parser-controller/revision jobs.
- Реализован strict revision gate:
  - `RevisionRun` + `RevisionRunItem`.
  - API для `run/status/retry/manual/finalize`.
  - jobs: `RunRevisionUpdateJob`, `UpdateMaterialObservationForRevisionItem`.
- Реализован internal endpoint `POST /api/internal/parser/screenshot` (через `ScreenshotCaptureService`).
- Добавлен отдельный PDF-маршрут `price-justification.pdf` + blade-шаблон.
- Добавлены true_score и raw/normalized source URL в `material_price_histories`.
- Добавлен флаг `project_positions.requires_price_justification`.
- Расширены шаблоны parser профилей: `visibility` (private/public), `status` (active/disabled) + API updates.

## Миграции
1. `2026_03_05_000001_extend_material_price_histories_for_revision_gate.php`
   - `material_price_histories.true_score`
   - `material_price_histories.raw_source_url`
   - `material_price_histories.normalized_source_url`
   - индекс по `(normalized_source_url, price_per_unit, region_id)`
2. `2026_03_05_000002_create_revision_runs_tables.php`
   - `revision_runs`
   - `revision_run_items`
3. `2026_03_05_000003_add_revision_justification_flags.php`
   - `project_positions.requires_price_justification`
   - `parser_supplier_collect_profiles.visibility`
   - `parser_supplier_collect_profiles.status`

## Эндпойнты
### Internal
- `POST /api/internal/parser/screenshot`

### Revision run (strict gate)
- `POST /api/projects/{project}/revisions/run`
- `GET /api/projects/{project}/revisions/run/{runId}`
- `POST /api/projects/{project}/revisions/run/{runId}/retry`
- `POST /api/revisions/run/{runId}/items/{itemId}/manual` (multipart)
- `POST /api/projects/{project}/revisions/run/{runId}/finalize`

### PDF
- `GET /api/projects/{project}/revisions/{number}/price-justification.pdf`

### Chrome templates
- `PATCH /api/chrome/templates/{id}/visibility`
- `PATCH /api/chrome/templates/{id}/status`

## Тесты
- Добавлен unit-test:
  - `tests/Unit/Services/UrlNormalizerTest.php`
- Быстрый синтаксический smoke-check:
  - `php -l` по изменённым PHP-файлам: OK.
  - Python script AST-check (`parser/screenshot_by_url.py`): OK.

## Файл-список
- `server/database/migrations/2026_03_05_000001_extend_material_price_histories_for_revision_gate.php`
- `server/database/migrations/2026_03_05_000002_create_revision_runs_tables.php`
- `server/database/migrations/2026_03_05_000003_add_revision_justification_flags.php`
- `server/app/Models/RevisionRun.php`
- `server/app/Models/RevisionRunItem.php`
- `server/app/Services/UrlNormalizer.php`
- `server/app/Services/ScreenshotCaptureService.php`
- `parser/screenshot_by_url.py`
- `server/app/Http/Controllers/Api/Internal/ParserScreenshotController.php`
- `server/app/Jobs/RunRevisionUpdateJob.php`
- `server/app/Jobs/UpdateMaterialObservationForRevisionItem.php`
- `server/app/Http/Controllers/Api/RevisionRunController.php`
- `server/resources/views/reports/price_justification.blade.php`
- `server/tests/Unit/Services/UrlNormalizerTest.php`
- `server/tests/Feature/RevisionRunStrictGateTest.php`
- Изменены:
  - `server/routes/api.php`
  - `server/app/Http/Controllers/Api/ProjectRevisionController.php`
  - `server/app/Services/SnapshotService.php`
  - `server/app/Services/MaterialParseService.php`
  - `server/app/Http/Controllers/Api/MaterialCatalogController.php`
  - `server/app/Http/Controllers/Api/MaterialController.php`
  - `server/app/Http/Controllers/Api/Parser/MaterialController.php`
  - `server/app/Http/Controllers/Api/ChromeExtensionController.php`
  - `server/app/Services/ChromeExtractService.php`
  - `server/app/Services/DomainParseService.php`
  - `server/app/Models/MaterialPriceHistory.php`
  - `server/app/Models/ParserSupplierCollectProfile.php`
  - `server/app/Models/Project.php`
  - `server/app/Models/ProjectPosition.php`
  - `server/app/Http/Controllers/Api/ProjectPositionController.php`
