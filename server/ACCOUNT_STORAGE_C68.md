# 4E.3.2.2c.6.8: общий учёт файлов аккаунта

## Контракт

`storage_files` — реестр физических объектов; `disk + path` уникальны. Модуль
физического объекта определяет разбивку usage, модуль ссылки — бизнес источник.
`storage_usages` и `storage_usage_modules` — восстанавливаемые проекции billable
объектов со статусом `active`. Новые Expert/Smeta загрузки используют общий лимит
из `billing_plans.metadata_json.limits.storage_bytes` и generic reservations.
`NULL` разрешает неограниченные загрузки, `0` запрещает даже пустой billable файл.
Billing observation/fail-open flags не отключают storage quota.

Временные ImportSession sources (TTL), parser/OCR/PDF materialization, thumbnails,
preview cache и отчёты на запрос не billable. PriceImportSession/foundation sources
сохраняются пользователю и billable. `smeta/screenshots/parser/` — общий платформенный
источник без владельца аккаунта; backfill исключает его даже внутри revision snapshot.

Работающие S1 adapters, protected download/preview и PDF pipeline сохранены.
Expert legacy accounting tables остаются в схеме, новые uploads их не изменяют.
Последняя business reference переводит файл в `deleting`, освобождает usage; retry job
удаляет физический объект. После hard-delete аккаунта nullable owner допускается
только у cleanup tombstone: locator сохраняется для удаления после commit.

## Порядок rollout (оператор)

В этой задаче production migration/backfill/reset не выполнялись. Перед rollout
сделать обычный backup БД и включить maintenance; остановить приём новых загрузок и
дождаться завершения app/worker uploads. При backfill нельзя менять business locators.
Проверить наличие эффективных активных/default тарифов: неизвестный тариф блокирует
новую billable загрузку с `BILLING_LIMIT_CHECK_FAILED`.

```sh
php artisan down
php artisan migrate --force
php artisan storage:backfill-registry --dry-run
php artisan storage:backfill-registry
php artisan storage:recalculate-usage --all
php artisan storage:usage-audit
php artisan expert:storage-check --disk=s1
php artisan expert:storage-audit --all --hash
php artisan up
```

Backfill требует отсутствия активных `reserved` строк в старом и новом reservation журнале.
Истёкшие generic reservations освобождает `ExpireStorageUploadReservations`;
Текущие uploads должны завершиться до rollout. Истёкшие и consumed Expert reservations
не переносятся и не считаются в новом account usage.
При missing object, неизвестном owner, конфликте owner/size, legacy persistent locator
rollout остановить. Команда валидирует весь набор перед первой записью, не перемещает
физические файлы и не исправляет business данные по догадке. Повторный запуск идемпотентен.
Snapshots, оставшиеся после старого destructive reset, также должны ссылаться на
существующие объекты; broken snapshot приводит к остановке backfill.

Scheduler должен работать: jobs expiration и physical deletion запускаются каждые
5 минут. При ошибке queue dispatch deleting запись остаётся для scheduled retry.
Пересчёт и usage audit работают только по БД, не перечисляют S1 bucket.

## Проверки после deployment

1. Записать `GET /api/account/storage` до загрузок; API требует authentication.
2. Загрузить Expert image и Smeta evidence/прайс: общий размер растёт на фактические
   bytes, module/category counters соответствуют, reserved после finalize = 0.
3. Проверить private download, preview/304 и PDF с несколькими screenshots; проверить
   parsing S1 CSV/XLSX и отсутствие persistent local файлов.
4. На тестовом тарифе поставить малый общий лимит: оба модуля возвращают
   `STORAGE_QUOTA_EXCEEDED`; equality разрешена, NULL unlimited, 0 запрещает upload.
   После проверки вернуть исходный лимит. Downgrade не запрещает чтение/удаление.
5. Удалить файлы: последний link освобождает bytes/count; shared link сохраняет
   object/usage. После worker retry объект отсутствует в S1, status = deleted.
6. Перезапустить app/worker/web; повторить download/preview и account usage.
7. Выполнить `storage:usage-audit`: used/reserved/module/category mismatches и
   duplicate objects = 0. Для одного пользователя доступны `--user=<id>`.

## Автоматические команды проверки

MariaDB tests запускать последовательно: RefreshDatabase пересоздаёт только
`smeta_test`. Race test использует отдельные соединения и свои committed fixtures.

```sh
php artisan test tests/Unit/Services/AccountStorageUsageTest.php
php artisan test tests/Feature/Billing/AccountStorageLifecycleTest.php --env=testing
php artisan test tests/Integration/AccountStorageReservationRaceTest.php --env=testing
```

Real S1 test: `tests/Integration/ExpertS1StorageIntegrationTest.php`, включается
`EXPERT_S1_INTEGRATION_ENABLED=true` только при настроенных server-side credentials.
`tests/Unit/Services/Storage/S1StorageIntegrationTest.php` проверяет общий adapter
при наличии credentials. Secrets не передавать в аргументах CLI и не коммитить.

Production/UI/restart/live S1 acceptance — отдельный обязательный gate; targeted
fake S1 tests не подтверждают эти проверки. Статус COMPLETE допустим после этих gates.

## Результат локальных проверок

- PASS: 74 backend tests (13 общий lifecycle; 25 Expert lifecycle/quota/usage/reset;
  20 accounting/PDF/ObjectStorage/reset unit tests; 2 реальные MariaDB races;
  14 health-check/migration tooling tests).
- PASS: 3 frontend tests (account summary adapter и storage refresh event).
- PASS: PHP lint 76 изменённых/новых PHP файлов и git diff --check.
- SKIPPED: 2 real S1 tests, testing credentials/explicit integration flag отсутствуют.
- Общий frontend type-check не проходит: ошибки в неизменённых компонентах.
  Ошибок в изменённых billing.ts/AccountStorageSection.vue в последнем прогоне нет.
- Production миграция/backfill, UI/live S1/restart acceptance и full suite не выполнены.
- Изменение схемы применялось только к изолированной smeta_test; production reset
  и legacy physical migration не запускались. Коммиты/deployment не выполнялись.

## Изменённые файлы этого блока

- `.gitignore`
- `client/src/api/billing.ts`
- `client/src/api/billingStorage.spec.ts`
- `client/src/components/settings/AccountStorageSection.vue`
- `server/app/Console/Commands/AuditStorageUsage.php`
- `server/app/Console/Commands/BackfillAccountStorage.php`
- `server/app/Console/Commands/RecalculateStorageUsage.php`
- `server/app/Console/Commands/ResetExpertStorageCommand.php`
- `server/app/Console/Commands/ResetSmetaStorage.php`
- `server/app/Http/Controllers/Api/AccountStorageController.php`
- `server/app/Http/Controllers/Api/ChromeExtensionController.php`
- `server/app/Http/Controllers/Api/EvidenceRunController.php`
- `server/app/Http/Controllers/Api/FinishedProductPriceEvidenceAssetController.php`
- `server/app/Http/Controllers/Api/PriceDocumentController.php`
- `server/app/Http/Controllers/Api/PriceImportController.php`
- `server/app/Http/Controllers/Api/PriceListVersionController.php`
- `server/app/Http/Controllers/Api/RevisionRunController.php`
- `server/app/Jobs/DeleteAccountStorageFiles.php`
- `server/app/Jobs/ExpireStorageUploadReservations.php`
- `server/app/Jobs/UpdateMaterialObservationForRevisionItem.php`
- `server/app/Models/Concerns/AccountsStorageReferences.php`
- `server/app/Models/EvidenceArtifact.php`
- `server/app/Models/EvidenceAsset.php`
- `server/app/Models/EvidenceRecord.php`
- `server/app/Models/Expert/ExpertProjectMaterial.php`
- `server/app/Models/FinishedProductPriceEvidenceAsset.php`
- `server/app/Models/FinishedProductPriceSource.php`
- `server/app/Models/FinishedProductSpecification.php`
- `server/app/Models/GenericEvidenceAsset.php`
- `server/app/Models/ImportSession.php`
- `server/app/Models/Material.php`
- `server/app/Models/MaterialPriceHistory.php`
- `server/app/Models/PriceImport.php`
- `server/app/Models/PriceImportSession.php`
- `server/app/Models/PriceList.php`
- `server/app/Models/PriceListVersion.php`
- `server/app/Models/Project.php`
- `server/app/Models/ProjectRevision.php`
- `server/app/Models/RevisionRun.php`
- `server/app/Models/RevisionRunItem.php`
- `server/app/Models/Supplier.php`
- `server/app/Observers/StorageCascadeReferenceObserver.php`
- `server/app/Observers/StorageFileReferenceObserver.php`
- `server/app/Providers/AppServiceProvider.php`
- `server/app/Services/Admin/AdminUserService.php`
- `server/app/Services/Billing/BillingGateService.php`
- `server/app/Services/ChromeLaborCaptureService.php`
- `server/app/Services/EvidencePipelineService.php`
- `server/app/Services/Expert/ExpertMaterialService.php`
- `server/app/Services/Expert/ExpertProjectService.php`
- `server/app/Services/Expert/ExpertStorageQuotaService.php`
- `server/app/Services/Expert/ExpertStorageUsageService.php`
- `server/app/Services/FinishedProductEvidenceRecordBridge.php`
- `server/app/Services/GenericChromeCaptureService.php`
- `server/app/Services/Import/ImportSessionService.php`
- `server/app/Services/LaborEvidenceAssetService.php`
- `server/app/Services/ManualPricingSourceService.php`
- `server/app/Services/PriceImport/PriceImportSessionService.php`
- `server/app/Services/PriceImportService.php`
- `server/app/Services/ScreenshotCaptureService.php`
- `server/app/Services/Storage/AccountFileStorage.php`
- `server/app/Services/Storage/ObjectStorage.php`
- `server/app/Services/Storage/ObjectStorageException.php`
- `server/app/Services/Storage/PreparedStorageUpload.php`
- `server/app/Services/Storage/StorageCascadeReferences.php`
- `server/app/Services/Storage/StorageFileReferences.php`
- `server/app/Services/Storage/StorageQuotaException.php`
- `server/app/Services/Storage/StorageQuotaService.php`
- `server/app/Services/Storage/StorageUsageService.php`
- `server/bootstrap/app.php`
- `server/database/migrations/2026_09_28_000001_create_account_storage_tables.php`
- `server/routes/api.php`
- `server/routes/console.php`
- `server/tests/Feature/Billing/AccountStorageLifecycleTest.php`
- `server/tests/Feature/Expert/ExpertStorageAbstractionTest.php`
- `server/tests/Feature/Expert/ExpertStorageQuotaTest.php`
- `server/tests/Feature/Expert/ExpertStorageResetCommandTest.php`
- `server/tests/Feature/Expert/ExpertStorageUsageTest.php`
- `server/tests/Integration/AccountStorageReservationRaceTest.php`
- `server/tests/Unit/Services/AccountStorageUsageTest.php`
