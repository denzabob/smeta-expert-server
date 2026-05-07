# Архитектура ревизий и доказательств

Актуально на: 2026-05-07
Основано на фактическом коде Laravel/Vue в `server/app`, `server/routes`, `server/database/migrations`, `client/src`.

## 1. Goal Summary

В проектном разделе `/projects/{id}/edit` сейчас сосуществуют три близких, но разных механизма:

1. `ProjectRevision` - неизменяемый снапшот проекта и документов.
2. `RevisionRun` - сессия проверки/обоснования цен перед созданием `ProjectRevision`.
3. `EstimateEvidenceRun` + `EvidenceRecord` - более универсальная система доказательств, которая хранит наблюдения, файлы и связи с разными сущностями.

Также есть специализированные контуры, которые используют общий слой `EvidenceRecord`: доказательства трудозатрат, доказательства готовых изделий/фасадов, доказательства прайсов и операций.

Главное различие: `RevisionRun` обслуживает legacy-путь финализации сметы в ревизию проекта, а `EstimateEvidenceRun` обслуживает отдельный аудит покрытия доказательствами. Они пересекаются по типам cost driver, Chrome Extension, скриншотам, URL-нормализации и частично по `MaterialPriceHistory`, но пишут в разные основные таблицы.

## 2. Current Architecture Involved

### 2.1 ProjectRevision: снимок проекта

Подтверждено в коде:

- Модель: `server/app/Models/ProjectRevision.php`.
- Сервис создания снапшота: `server/app/Services/SnapshotService.php`.
- Контроллер: `server/app/Http/Controllers/Api/ProjectRevisionController.php`.
- Таблицы: `project_revisions`, `revision_publications`, `revision_publication_views`.
- API:
  - `POST /api/projects/{project}/revisions`
  - `GET /api/projects/{project}/revisions`
  - `GET /api/projects/{project}/revisions/latest`
  - `GET /api/projects/{project}/revisions/{number}`
  - `GET /api/projects/{project}/revisions/{number}/pdf`
  - `GET /api/projects/{project}/revisions/{number}/price-justification.pdf`
  - `POST /api/projects/{project}/revisions/{number}/publish`
  - `POST /api/projects/{project}/revisions/{number}/unpublish`
  - `POST /api/projects/{project}/revisions/{number}/lock`

Логика:

- `SnapshotService::createSnapshot()` строит отчет через `ReportService::buildReport($project)`.
- Снапшот канонизируется рекурсивной сортировкой ключей и сохраняется как JSON-строка.
- `snapshot_hash` считается через SHA256.
- `ProjectRevision` защищает immutable-поля на update: `project_id`, `created_by_user_id`, `number`, `snapshot_json`, `snapshot_hash`.
- Статусы `ProjectRevision`: `locked`, `published`, `stale`.

Инференция:

- Это не система сбора доказательств сама по себе. Это юридически/документно значимый снимок состояния проекта, в который могут быть вложены результаты `RevisionRun` через `price_justifications`, `evidence_summary`, `revision_run_id`.

### 2.2 RevisionRun: legacy-сессия ревизии цен

Подтверждено в коде:

- Модели: `RevisionRun`, `RevisionRunItem`, `EvidenceArtifact`, `EvidenceAsset`.
- Контроллер: `RevisionRunController`.
- Jobs: `RunRevisionUpdateJob`, `UpdateMaterialObservationForRevisionItem`.
- Новый pipeline внутри legacy-пути: `EvidencePipelineService`.
- Основные таблицы:
  - `revision_runs`
  - `revision_run_items`
  - `evidence_artifacts`
  - `evidence_assets`
  - `material_price_histories`
- API:
  - `POST /api/projects/{project}/revisions/run`
  - `GET /api/projects/{project}/revisions/run/{runId}`
  - `POST /api/projects/{project}/revisions/run/{runId}/retry`
  - `POST /api/revisions/run/{runId}/items/{itemId}/manual`
  - `POST /api/revisions/run/{runId}/items/{itemId}/attach-document`
  - `POST /api/projects/{project}/revisions/run/{runId}/finalize`
  - `GET /api/chrome/revision-items`
  - `POST /api/chrome/revision-items/{itemId}/evidence`

Логика создания:

- `RevisionRunController::start()` проверяет `update` policy и валидность сметы через `ReportService::buildReport()`.
- `collectReportItems()` собирает cost drivers:
  - `plate`
  - `edge`
  - `fitting`
  - `facade`, если `EvidenceFeatures::facadeEvidenceEnabled()`
  - `operation`, если `operations_enabled`
  - `labor_work`, если `labor_work_enabled`
  - `expense`, если `expenses_enabled`
- Для `operation`, `labor_work`, `expense` элементы создаются сразу `OK` и получают `EvidenceArtifact` с `capture_source=internal`.
- Для фасадов при включенном флаге создается manual-only item со статусом `NEEDS_MANUAL`.
- После создания запускается `RunRevisionUpdateJob`.

Логика автоматической обработки:

- `RunRevisionUpdateJob::handle()` ставит run в `IN_PROGRESS`, сбрасывает подходящие items в `PENDING` и синхронно вызывает `UpdateMaterialObservationForRevisionItem::dispatchSync()`.
- Internal-only типы не отправляются в scraping pipeline.
- Если `EvidenceFeatures::pipelineV2Enabled()`, item обрабатывает `EvidencePipelineService::process()`.
- Если флаг выключен, используется legacy-логика прямо в `UpdateMaterialObservationForRevisionItem::handleLegacy()`.

Legacy auto flow:

- Находит материал и URL.
- Нормализует URL через `UrlNormalizer`.
- Переиспользует свежий `MaterialPriceHistory` за сегодня или существующий snapshot с той же ценой.
- Вызывает `MaterialParseService::parseByUrl()`.
- Делает скриншот через `ScreenshotCaptureService::captureByUrl()`.
- Создает `MaterialPriceHistory`.
- Обновляет `RevisionRunItem.price_history_id`.

Pipeline v2 внутри RevisionRun:

- `EvidencePipelineService` ведет stages:
  - `INIT`
  - `FETCH`
  - `PAGE_CLASSIFY`
  - `EXTRACT`
  - `CAPTURE`
  - `VALIDATE`
  - `PERSIST_ARTIFACT`
  - `LINK_HISTORY`
  - `LINK_REVISION`
  - `DONE`
- При успехе создает `EvidenceArtifact` + `EvidenceAsset`, затем `MaterialPriceHistory`, затем связывает item.
- При сбое переводит item в `manual_required` и мапит reason code в legacy status: `BLOCKED`, `TIMEOUT`, `NEEDS_MANUAL`.

Ручное закрытие:

- `RevisionRunController::manual()` принимает цену и `screenshot_file`.
- Создает `EvidenceArtifact` с `mode=manual`, `capture_source=manual`.
- Создает `EvidenceAsset` типа `screenshot`.
- Создает `MaterialPriceHistory` с `source_type=manual`.
- Переводит item в `OK`.

Chrome закрытие legacy item:

- `ChromeExtensionController::listRevisionItems()` отдает проблемные items по проектам пользователя, если `EVIDENCE_CHROME_REVISION_ENABLED=true`.
- `ChromeExtensionController::submitItemEvidence()` создает `EvidenceArtifact` с `capture_source=chrome_ext`, `EvidenceAsset`, `MaterialPriceHistory`, переводит item в `OK`.

Финализация:

- `RevisionRunController::finalize()` требует, чтобы все items были `OK`.
- Собирает `price_justifications` из `RevisionRunItem`, `MaterialPriceHistory`, `EvidenceArtifact`, `evidenceSubject`.
- Для internal-only типов формирует строки из subject + artifact.
- Для spec-rooted фасадов использует `FinishedProductFacadeRevisionRowAssembler::buildRevisionReportRow()`.
- Вызывает `SnapshotService::createSnapshot()` с extra snapshot:
  - `price_justifications`
  - `evidence_summary`
  - `revision_run_id`
- Переводит `RevisionRun.status` в `FINALIZED`.

### 2.3 EstimateEvidenceRun: generic evidence-система

Подтверждено в коде:

- Модели: `EstimateEvidenceRun`, `EstimateEvidenceItem`, `EvidenceRecord`, `GenericEvidenceAsset`, `EvidenceLink`.
- Контроллер: `EvidenceRunController`.
- Сервисы:
  - `EvidenceRunItemCollector`
  - `EvidenceRunFinalizer`
  - `EstimateEvidencePdfBuilder`
  - `GenericChromeCaptureService`
  - `MaterialConfirmationService`
  - `FinishedProductEvidenceRecordBridge`
- Основные таблицы:
  - `estimate_evidence_runs`
  - `estimate_evidence_items`
  - `evidence_records`
  - `generic_evidence_assets`
  - `evidence_links`
- API:
  - `GET /api/projects/{project}/evidence-runs`
  - `POST /api/projects/{project}/evidence-runs`
  - `GET /api/projects/{project}/evidence-runs/{runId}`
  - `POST /api/projects/{project}/evidence-runs/{runId}/refresh`
  - `POST /api/projects/{project}/evidence-runs/{runId}/finalize`
  - `POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/resolve`
  - `POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/skip`
  - `POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/manual-resolve`
  - `GET /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/candidates`
  - `GET /api/projects/{project}/evidence-runs/{runId}/pdf`
  - `GET /api/evidence-records`
  - `GET /api/evidence-records/search`
  - `GET /api/evidence-records/{record}`
  - `PATCH /api/evidence-records/{record}/verification-status`
  - `POST /api/evidence-records`
  - `POST /api/evidence-records/{id}/assets`

Логика создания:

- `EvidenceRunController::store()` создает `EstimateEvidenceRun` со статусом `pending`.
- Проверяется billing gate `CAP_EVIDENCE_RUNS_MONTHLY_LIMIT`.
- `EvidenceRunItemCollector::populateRun()` строит отчет через `ReportService::buildReport($project)` и создает `EstimateEvidenceItem`.
- Internal-only компоненты `operation`, `labor_work`, `expense` получают auto `EvidenceRecord` с `source_type=internal_calc`, `verification_status=auto_verified` и item становится `resolved`.
- External компоненты `plate`, `edge`, `facade`, `fitting` ищут свежее доказательство через `MaterialConfirmationService`.
- Если найден fresh record, item становится `resolved` с `resolution_type=auto_fresh`.
- Если доказательства нет, item остается `pending`.
- Для project-scoped labor evidence добавляются external labor items, если у проекта есть активные `laborEvidenceSources` с `evidence_record_id`.

Логика refresh:

- `EvidenceRunController::refresh()` запрещен для terminal run.
- `EvidenceRunItemCollector::refreshPendingItems()` повторно ищет fresh proof для pending items.
- `refreshRunCounters()` переводит run в `ready`, если все items terminal.

Логика resolve:

- `resolveItem()` принимает `evidence_record_id`.
- Кандидат валидируется через `MaterialConfirmationService::isValidCandidateForItem()`:
  - совпадает `cost_component`
  - совпадает normalized URL, если у item есть URL
  - record не `rejected`
  - есть proof asset `screenshot` или `document`
  - при user scope record принадлежит пользователю
- Для фасадов с finished-product specification используется `FinishedProductEvidenceRecordBridge`.

Логика manual resolve:

- `manualResolveItem()` создает `EvidenceRecord` с `source_type=manual_input`, `capture_method=file_upload`.
- Загружает файл в `generic_evidence_assets`.
- Переводит item в `resolved`.

Логика skip:

- `skipItem()` переводит item в `skipped`, записывает `skip_reason` в `diagnostics_json`.
- `skipped` считается completed, но не доказательством цены.

Финализация:

- `EvidenceRunFinalizer::canFinalize()` разрешает финализацию только из статуса `ready`.
- Все items должны быть terminal: `resolved`, `failed`, `skipped`.
- `finalize()` строит `snapshot_json`:
  - `evidence_coverage_summary`
  - `evidence_items`
  - `evidence_records`
  - `labor`
  - `exceptions`
  - `generation_meta`
- `EvidenceRunController::pdf()` генерирует PDF через `EstimateEvidencePdfBuilder` и `reports.evidence_run`, но только если `genericChromeEnabled()` и run `finalized`.

### 2.4 Chrome Extension: общий вход для legacy и generic evidence

Подтверждено в коде:

- Legacy revision endpoints живут в `ChromeExtensionController`.
- Generic evidence endpoints живут в `GenericChromeController`.
- Оба контура используют Bearer token Sanctum и исключены из stateful/session middleware внутри auth-группы.

Generic Chrome flow:

- `GET /api/chrome/generic-items` отдает unresolved `EstimateEvidenceItem` пользователя.
- `POST /api/chrome/capture-observation` создает standalone `EvidenceRecord`.
- `POST /api/chrome/generic-items/{itemId}/capture` создает record, link и resolve item.
- `POST /api/chrome/extract-with-evidence` делает material upsert через `ChromeExtractService`, создает/переиспользует `EvidenceRecord`, пишет evidence bridge в material observation и пытается auto-link в matching evidence item.

Дедупликация generic evidence:

- `GenericChromeCaptureService::findDuplicate()` ищет same normalized URL + component + user + `capture_method=chrome_extension` за 60 секунд.
- `MaterialConfirmationService::getFreshEquivalentRecord()` переиспользует fresh record при совпадении URL, component, цены в пределах +/-1% и наличии screenshot.
- `storeScreenshot()` дедуплицирует asset по SHA256 внутри одного record.

Legacy Chrome flow:

- `GET /api/chrome/revision-items` отдает проблемные `RevisionRunItem`.
- `POST /api/chrome/revision-items/{itemId}/evidence` пишет в legacy-таблицы `EvidenceArtifact`, `EvidenceAsset`, `MaterialPriceHistory`.

### 2.5 Labor evidence

Подтверждено в коде:

- Модель: `LaborEvidenceSource`.
- Сервисы: `LaborEvidenceSourceService`, `LaborEvidenceAssetService`, `ChromeLaborCaptureService`.
- Project endpoints:
  - `GET /api/projects/{project}/labor-sources`
  - `POST /api/projects/{project}/labor-sources/attach`
  - `POST /api/projects/{project}/labor-sources/detach`
- Pricing/labor CRUD endpoints:
  - `GET/POST/PUT/DELETE /api/pricing/labor/sources`
  - `GET/POST/DELETE /api/pricing/labor/sources/{id}/assets`
- Chrome endpoint:
  - `POST /api/chrome/labor-captures`

Логика:

- `LaborEvidenceSourceService` при create/update создает или обновляет связанный `EvidenceRecord` с `cost_component=labor_work`.
- `LaborEvidenceAssetService` сохраняет файлы как `GenericEvidenceAsset`.
- `ChromeLaborCaptureService` создает `EvidenceRecord`, `LaborEvidenceSource`, screenshot asset, затем может автоматически привязать источник к проектам с тем же `labor_profile_id` и применить ставки через `ProjectLaborWorkRateApplierService`.
- `EvidenceRunItemCollector` при создании generic evidence run добавляет project-scoped external labor sources отдельными items.

### 2.6 Finished product / facade evidence

Подтверждено в коде:

- Специализированные assets: `FinishedProductPriceEvidenceAsset`.
- Контроллер: `FinishedProductPriceEvidenceAssetController`.
- Bridge: `FinishedProductEvidenceRecordBridge`.
- Revision row assembler: `FinishedProductFacadeRevisionRowAssembler`.
- API:
  - `GET /api/finished-product-price-sources/{source}/evidence-assets`
  - `POST /api/finished-product-price-sources/{source}/evidence-assets`
  - `GET /api/finished-product-price-evidence-assets/{asset}/open`
  - `DELETE /api/finished-product-price-evidence-assets/{asset}`

Логика:

- Сырой контур готовых изделий хранит свои evidence assets рядом с price source.
- Для generic evidence picker `FinishedProductEvidenceRecordBridge::materializeForSpecification()` материализует эти assets в `EvidenceRecord` + `GenericEvidenceAsset` + `EvidenceLink`.
- Для `RevisionRun` spec-rooted фасады при финализации попадают в price justification как snapshot summary через `FinishedProductFacadeRevisionRowAssembler`, а не как обычный `MaterialPriceHistory`.

## 3. Affected Files / Directories

Backend:

- `server/routes/api.php`
- `server/config/smeta.php`
- `server/app/Models/ProjectRevision.php`
- `server/app/Models/RevisionRun.php`
- `server/app/Models/RevisionRunItem.php`
- `server/app/Models/EvidenceArtifact.php`
- `server/app/Models/EvidenceAsset.php`
- `server/app/Models/EstimateEvidenceRun.php`
- `server/app/Models/EstimateEvidenceItem.php`
- `server/app/Models/EvidenceRecord.php`
- `server/app/Models/GenericEvidenceAsset.php`
- `server/app/Models/EvidenceLink.php`
- `server/app/Http/Controllers/Api/ProjectRevisionController.php`
- `server/app/Http/Controllers/Api/RevisionRunController.php`
- `server/app/Http/Controllers/Api/EvidenceRunController.php`
- `server/app/Http/Controllers/Api/ChromeExtensionController.php`
- `server/app/Http/Controllers/Api/GenericChromeController.php`
- `server/app/Http/Controllers/Api/ProjectLaborEvidenceSourceController.php`
- `server/app/Http/Controllers/Api/FinishedProductPriceEvidenceAssetController.php`
- `server/app/Jobs/RunRevisionUpdateJob.php`
- `server/app/Jobs/UpdateMaterialObservationForRevisionItem.php`
- `server/app/Services/SnapshotService.php`
- `server/app/Services/EvidencePipelineService.php`
- `server/app/Services/EvidenceRunItemCollector.php`
- `server/app/Services/EvidenceRunFinalizer.php`
- `server/app/Services/GenericChromeCaptureService.php`
- `server/app/Services/MaterialConfirmationService.php`
- `server/app/Services/ChromeLaborCaptureService.php`
- `server/app/Services/LaborEvidenceSourceService.php`
- `server/app/Services/LaborEvidenceAssetService.php`
- `server/app/Services/FinishedProductEvidenceRecordBridge.php`
- `server/app/Services/FinishedProductFacadeRevisionRowAssembler.php`

Frontend:

- `client/src/views/ProjectEditorView.vue`
- `client/src/api/revisionRun.ts`
- `client/src/api/evidenceRun.ts`
- `client/src/composables/useEvidenceRun.ts`
- `client/src/components/evidence/EvidenceRunPanel.vue`
- `client/src/components/evidence/EvidenceItemsTable.vue`
- `client/src/components/evidence/EvidenceResolutionDialog.vue`
- `client/src/components/project/ProjectLaborEvidencePanel.vue`
- `client/src/components/products/FinishedProductEvidenceManagerDialog.vue`
- `client/src/components/products/FinishedProductPricingModule.vue`

Database:

- `server/database/migrations/2026_01_20_150000_create_project_revisions_table.php`
- `server/database/migrations/2026_01_20_180000_create_revision_publications_table.php`
- `server/database/migrations/2026_01_20_180500_create_revision_publication_views_table.php`
- `server/database/migrations/2026_03_05_000002_create_revision_runs_tables.php`
- `server/database/migrations/2026_03_06_000101_create_evidence_artifacts_table.php`
- `server/database/migrations/2026_03_06_000102_extend_revision_items_and_price_histories_for_evidence_pipeline.php`
- `server/database/migrations/2026_03_07_000001_add_pending_to_revision_run_items_status.php`
- `server/database/migrations/2026_03_07_000002_add_finalized_to_revision_runs_status.php`
- `server/database/migrations/2026_03_12_000001_extend_project_fittings_and_revision_items_for_hardware.php`
- `server/database/migrations/2026_03_28_000001_extend_revision_run_items_for_generic_evidence.php`
- `server/database/migrations/2026_03_28_000003_create_evidence_assets_table.php`
- `server/database/migrations/2026_03_29_000001_create_evidence_records_table.php`
- `server/database/migrations/2026_03_29_000002_create_estimate_evidence_runs_table.php`
- `server/database/migrations/2026_03_29_000003_add_evidence_record_id_to_material_price_histories.php`
- `server/database/migrations/2026_03_29_100001_add_snapshot_and_item_fields.php`
- `server/database/migrations/2026_04_20_120000_create_pricing_labor_evidence_tables.php`
- `server/database/migrations/2026_04_20_130000_add_uploaded_by_to_generic_evidence_assets_table.php`
- `server/database/migrations/2026_04_22_120002_create_finished_product_price_evidence_assets_table.php`

## 4. Где системы отличаются

| Аспект | ProjectRevision | RevisionRun | EstimateEvidenceRun / EvidenceRecord |
|---|---|---|---|
| Главная задача | Зафиксировать снапшот проекта | Собрать/подтвердить цены перед снапшотом | Собрать универсальные доказательства покрытия |
| Основной результат | `project_revisions.snapshot_json` | `ProjectRevision` через finalize | `estimate_evidence_runs.snapshot_json` и PDF evidence run |
| Основные item-строки | Нет отдельных item, только JSON | `revision_run_items` | `estimate_evidence_items` |
| Основной proof storage | Внутри snapshot JSON, если передан | `evidence_artifacts` + `evidence_assets` | `evidence_records` + `generic_evidence_assets` + `evidence_links` |
| История цен материалов | Не пишет напрямую | Активно пишет `material_price_histories` | Может использовать `material_price_histories.evidence_record_id`, но core proof живет в `evidence_records` |
| Статусы | `locked`, `published`, `stale` | UPPERCASE: `PENDING`, `IN_PROGRESS`, `NEEDS_MANUAL`, `READY`, `FINALIZED`, `FAILED` | lowercase: `pending`, `in_progress`, `ready`, `finalized`, `failed` |
| Автосбор | Нет | Есть parser/screenshot pipeline | Нет отдельной queue-обработки; есть auto-resolve по свежим record |
| Chrome Extension | Нет прямого контура | Закрывает проблемные items | Создает observations, resolve items, auto-link |
| Полиморфность | Нет, JSON snapshot | `evidence_subject_type/id` на item | `subject_type/id` на item и `evidence_links` для record |
| Зрелость для PDF | Высокая для проектной ревизии | Интегрирован в project revision PDF/price justification | Есть отдельный PDF, но gated `genericChromeEnabled()` |

## 5. Где системы пересекаются

Подтверждено:

- Обе item-системы (`RevisionRunItem` и `EstimateEvidenceItem`) собирают одни и те же cost types: `plate`, `edge`, `facade`, `fitting`, `operation`, `labor_work`, `expense`.
- Обе используют `ReportService::buildReport($project)` как источник cost drivers.
- Обе используют `UrlNormalizer`.
- Обе имеют Chrome Extension сценарии.
- Обе работают с доказательствами цены, скриншотами, URL, доверием и статусами подтверждения.
- Обе используют feature flags из `server/config/smeta.php`.
- `MaterialPriceHistory` имеет две связи:
  - `evidence_artifact_id` для legacy revision artifact.
  - `evidence_record_id` для generic evidence record.
- Фасады/готовые изделия соединяются с обоими мирами:
  - `RevisionRun` использует snapshot row assembler.
  - `EstimateEvidenceRun` использует bridge в `EvidenceRecord`.
- Labor evidence соединяется с generic evidence через `EvidenceRecord` и с project run через `EvidenceRunItemCollector`.

Инференция:

- Сейчас `EvidenceRecord` выглядит как более перспективный общий слой, но `RevisionRun` остается важным orchestration-путем для создания официальной `ProjectRevision`.

## 6. Есть ли дублирование логики

### 6.1 Подтвержденное дублирование

1. Сбор cost drivers из отчета.
   - `RevisionRunController::collectReportItems()`
   - `EvidenceRunItemCollector::collectDescriptors()`
   - Оба читают `plates`, `edges`, `operations`, `labor_works`, `expenses`, project fittings/facades.

2. Пересчет счетчиков run.
   - `RevisionRunController::refreshRunCounters()`
   - `UpdateMaterialObservationForRevisionItem::refreshRunStats()`
   - `EvidenceRunController::refreshRunCounters()`
   - `GenericChromeCaptureService::refreshRunCounters()`

3. Ручное создание доказательства с файлом.
   - `RevisionRunController::manual()`
   - `ChromeExtensionController::submitItemEvidence()`
   - `EvidenceRunController::manualResolveItem()`
   - `GenericChromeCaptureService::captureForItem()`

4. Проверка terminal/finalizable статусов.
   - В legacy это зашито в `RevisionRunController`, `RunRevisionUpdateJob`, `RevisionRunItem::isCompleted()`.
   - В generic это выделено в `EvidenceRunStatus`, `EvidenceItemStatus`, `EvidenceRunFinalizer`.

5. Работа со скриншотами.
   - Legacy: `evidence_assets`, пути `screenshots/manual`, `screenshots/chrome`, auto screenshot paths из `ScreenshotCaptureService`.
   - Generic: `generic_evidence_assets`, пути `screenshots/chrome/generic`, `evidence-records/{uuid}`.

### 6.2 Дублирование, которое частично оправдано

- `EvidenceArtifact` и `EvidenceRecord` похожи по смыслу, но у них разные контракты.
- `EvidenceArtifact` привязан к revision/material/history flow.
- `EvidenceRecord` является reusable proof entity с полиморфными `EvidenceLink`.
- Поэтому прямое объединение без миграции контрактов рискованно.

### 6.3 Дублирование, которое выглядит кандидатом на консолидацию

Инференция:

- Сбор descriptors можно вынести в общий read-only collector, который возвращает нейтральные cost driver descriptors, а `RevisionRun` и `EstimateEvidenceRun` будут мапить их в свои item-модели.
- Логику счетчиков можно сделать отдельными маленькими сервисами по доменам.
- Ручное сохранение файла можно привести к общему storage helper, но без объединения таблиц.
- `MaterialConfirmationService` уже является хорошим кандидатом на единый источник truth для проверки свежести и candidate matching.

## 7. Какая система доказательств более проработана

Подтвержденный вывод:

- Для официального проектного результата сейчас более интегрирован `RevisionRun`, потому что он финализируется прямо в `ProjectRevision`, добавляет `price_justifications`, `evidence_summary`, ссылки на PDF сметы и PDF обоснования цены.
- Для универсальной модели доказательств более проработан `EvidenceRecord`/`EstimateEvidenceRun`, потому что там есть:
  - отдельная reusable proof entity
  - polymorphic links
  - строгий candidate picker
  - fresh auto-resolution
  - deduplication
  - standalone records
  - labor bridge
  - finished-product bridge
  - verification status endpoint
  - assets с SHA256

Практическая оценка:

- `RevisionRun` зрелее как production workflow финализации сметы.
- `EvidenceRecord` зрелее как доменная модель доказательств.
- `EstimateEvidenceRun` пока выглядит как новый аудитный слой, который еще не полностью заменяет legacy revision flow.

## 8. Feature Flags

Файл: `server/config/smeta.php`, фасад доступа: `server/app/Evidence/EvidenceFeatures.php`.

| ENV | Config | Влияние |
|---|---|---|
| `EVIDENCE_PIPELINE_V2` | `smeta.evidence.pipeline_v2` | Переключает `UpdateMaterialObservationForRevisionItem` на `EvidencePipelineService` |
| `EVIDENCE_FACADE_ENABLED` | `facade_enabled` | Включает фасадные items в `RevisionRun` |
| `EVIDENCE_CHROME_REVISION_ENABLED` | `chrome_revision_enabled` | Включает `/api/chrome/revision-items` и submit evidence |
| `EVIDENCE_OPERATIONS_ENABLED` | `operations_enabled` | Добавляет operation evidence в `RevisionRun` |
| `EVIDENCE_LABOR_WORK_ENABLED` | `labor_work_enabled` | Добавляет labor work evidence в `RevisionRun` |
| `EVIDENCE_EXPENSES_ENABLED` | `expenses_enabled` | Добавляет expense evidence в `RevisionRun` |
| `EVIDENCE_EXPENSES_DOCUMENT_ENABLED` | `expenses_document_enabled` | Разрешает `attach-document` для expense items |
| `EVIDENCE_GENERIC_CHROME_ENABLED` | `generic_chrome_enabled` | Включает generic Chrome endpoints и PDF generic evidence run |

Важно:

- `EstimateEvidenceRun` project endpoints в `routes/api.php` зарегистрированы всегда, но часть behavior/PDF завязана на feature flags.
- Chrome generic endpoints возвращают `404`, если `generic_chrome_enabled=false`.
- Legacy Chrome revision endpoints возвращают `404`, если `chrome_revision_enabled=false`.

## 9. UI внутри проекта

Подтверждено в `client/src/views/ProjectEditorView.vue`:

- Модуль `activeModule === 'revisions'` показывает:
  - последнюю `ProjectRevision`
  - активную `RevisionRun`
  - таблицу `RevisionRunItem`
  - coverage по `has_evidence`
  - retry/finalize
  - PDF-ссылки после finalize
  - диалог просмотра `EvidenceArtifact` и assets
  - attach document для expense item при условиях
- Модуль `activeModule === 'evidence'` показывает `EvidenceRunPanel`.
- `ProjectLaborEvidencePanel` подключен отдельно в проектном редакторе для project-scoped labor evidence.

Подтверждено в evidence UI:

- `EvidenceRunPanel` использует `useEvidenceRun`.
- Пользователь может создать run, refresh, resolve item, skip item, manual resolve, start Chrome polling, finalize, download PDF.
- `EvidenceResolutionDialog` вызывает strict candidates endpoint.
- `EvidenceItemsTable` отображает item status, component, value, linked record.

## 10. Риски / Backward Compatibility Concerns

1. Нельзя silently заменить `RevisionRun` на `EstimateEvidenceRun`.
   - `RevisionRun` связан с `ProjectRevision` и price justification PDF.
   - `EstimateEvidenceRun` имеет другой snapshot и другой PDF.

2. Нельзя silently объединить `EvidenceArtifact` и `EvidenceRecord`.
   - Разные таблицы, FK, storage paths, связи с `MaterialPriceHistory`.
   - Legacy PDF/justification читает artifact/history path.

3. Любое изменение cost driver collection рискованно.
   - Затрагивает plates/edges/fittings/facades/operations/labor/expenses.
   - Есть feature flags и разные initial statuses.

4. Фасады имеют отдельный путь.
   - Spec-rooted фасады не проходят обычный material history manual flow.
   - Есть bridge для generic evidence и snapshot assembler для revision.

5. Labor evidence уже влияет на ставки проекта.
   - Attach/detach вызывает `ProjectLaborWorkRateApplierService`.
   - Chrome labor capture может автоматически привязывать источник к релевантным проектам.

6. Файлы сохраняются до DB transaction в некоторых legacy paths.
   - В коде прямо отмечено: при падении транзакции возможен orphan file.

7. Есть потенциальная точка проверки в `EvidenceRunController::searchRecords()`.
   - В прочитанном фрагменте response meta использует `facadeSpecificationId`, но в методе `searchRecords()` он не был виден как инициализированная переменная. Это требует отдельной проверки перед любым изменением этого метода.

## 11. Proposed Implementation Blocks

### Block 1: только документация

Цель: держать этот документ актуальным с текущим кодом.
Scope: `docs/evidence-revision-architecture.md`.
Out of scope: код, БД, UI, маршруты.
Acceptance: документ описывает три слоя `ProjectRevision`, `RevisionRun`, `EstimateEvidenceRun` и специализированные evidence bridges.

### Block 2: аудит дублирования без правок

Цель: составить точную карту дублирования collector/counter/manual-upload logic.
Scope: `RevisionRunController`, `EvidenceRunItemCollector`, `EvidenceRunController`, `GenericChromeCaptureService`, jobs.
Out of scope: рефакторинг.
Acceptance: отдельный список safe refactor candidates с blast radius.

### Block 3: общий descriptor collector

Цель: вынести read-only сбор cost drivers из `ReportService` в общий сервис.
Scope: новый сервис + адаптация legacy/generic collectors.
Out of scope: смена таблиц и публичных API.
Acceptance: одинаковые items до/после для контрольного проекта.

### Block 4: унификация run counters

Цель: убрать расхождение правил `READY`/completed/failed в рамках каждого домена.
Scope: маленькие сервисы счетчиков для legacy и generic.
Out of scope: изменение статусов и migrations.
Acceptance: существующие тесты revision/evidence проходят, ручной smoke на project UI.

### Block 5: миграционная стратегия EvidenceArtifact -> EvidenceRecord bridge

Цель: не заменить legacy, а добавить bridge/read model, чтобы старые artifact могли быть видны как generic evidence candidates.
Scope: отдельный bridge, без удаления legacy tables.
Out of scope: переписывание PDF и финализации.
Acceptance: legacy proof не теряется, generic picker может видеть совместимые записи при явном включении.

## 12. Recommended Next Block

Рекомендуемый следующий блок: Block 2, аудит дублирования без правок.

Причина: в системе уже есть два параллельных collector/counter/upload flow. Перед рефакторингом нужно сравнить реальные payloads на одном проекте и подтвердить, какие строки должны совпадать, а какие различаются намеренно.

Минимальные проверки для Block 2:

- Сравнить descriptors из `RevisionRunController::collectReportItems()` и `EvidenceRunItemCollector::collectDescriptors()`.
- Проверить один проект с:
  - плитой
  - кромкой
  - фурнитурой
  - фасадом
  - операцией
  - labor work
  - expense
- Зафиксировать, какие feature flags включены.
- Не менять runtime-код до согласования.
