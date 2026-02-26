<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FurnitureModuleController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectPositionController;
use App\Http\Controllers\Api\Parser\MaterialController as ParserMaterialController;
use App\Http\Controllers\Api\DetailTypeController;
use App\Http\Controllers\Api\ProjectFittingController;
use App\Http\Controllers\Api\ProjectsOperationsController;
use App\Http\Controllers\Api\ProjectOperationController;
use App\Http\Controllers\Api\ProjectManualOperationController;
use App\Http\Controllers\Api\ParsingController;
use App\Http\Controllers\Api\Internal\ParserCallbackController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\SupplierHealthController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ParserSettingsController;
use App\Http\Controllers\Api\UrlCollectionController;
use App\Http\Controllers\Api\SmetaController;
use App\Http\Controllers\Api\SmetaPdfController;
use App\Http\Controllers\Api\ProjectLaborWorkStepController;
use App\Http\Controllers\Api\ProjectLaborWorkController;
use App\Http\Controllers\Api\LaborWorkHoursController;
use App\Http\Controllers\Api\GlobalNormohourSourceController;
use App\Http\Controllers\Api\ProjectNormohourSourceController;
use App\Http\Controllers\Api\PositionProfileController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\UserSettingsController;
use App\Http\Controllers\Api\ProjectRevisionController;
use App\Http\Controllers\Api\ProjectProfileRateController;
use App\Http\Controllers\Api\WorkDecomposeController;
use App\Http\Controllers\Api\AdminLLMController;
use App\Http\Controllers\Api\AdminLLMStatsController;
use App\Http\Controllers\Api\PinAuthController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\Api\FacadeMaterialController;
use App\Http\Controllers\ProjectImportController;
use App\Http\Middleware\InternalOnlyMiddleware;

use App\Http\Controllers\Api\MaterialCatalogController;

// Публичные маршруты
Route::post('login', [AuthController::class, 'login']); // ← без middleware, Sanctum сам добавит

// ========== Chrome Extension Token Auth ==========
// Без stateful-middleware (нет сессии/cookie — только Bearer token)
Route::withoutMiddleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \App\Http\Middleware\EnforceSingleSession::class,
])->group(function () {
    Route::post('chrome/auth/token', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'issueToken']);
});

// ========== PIN Auth (публичные — без auth, но с проверкой cookie) ==========
Route::get('auth/pin/status', [PinAuthController::class, 'status']);
Route::post('auth/pin/login', [PinAuthController::class, 'login']);
Route::post('auth/trusted-device/forget', [PinAuthController::class, 'forgetDevice']);

Route::post('/parser/materials', [ParserMaterialController::class, 'store']);
Route::post('/parser/materials/batch', [ParserMaterialController::class, 'storeBatch']);
Route::get('/parser/materials/{article}', [ParserMaterialController::class, 'show']);

// Публичный эндпоинт для получения данных по URL (без авторизации)
// CSRF исключен в App\Http\Middleware\VerifyCsrfToken
Route::post('materials/fetch', [MaterialController::class, 'fetchByUrl']);

// ========== Парсинг API (Защищены InternalOnlyMiddleware) ==========
// Internal callback endpoint для Python parser
Route::middleware(InternalOnlyMiddleware::class)->group(function () {
    Route::post('/internal/parser/callback', [ParserCallbackController::class, 'handle']);
    
    // URL Collection endpoints (HMAC protected)
    Route::post('/parsing/save-urls', [UrlCollectionController::class, 'saveUrls']);
    
    // URL Queue endpoints (ЭТАП 2)
    Route::post('/parser/urls/claim', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'claim']);
    Route::post('/parser/urls/report', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'report']);
    Route::get('/parser/urls/stats', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'stats']);
    Route::get('/parser/urls/diagnostics', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'diagnostics']);
    Route::post('/parser/urls/reset-stale', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'resetStale']);
    Route::post('/parser/urls/retry-ready', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'retryReady']);
    Route::post('/parser/urls/full-scan-reset', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'fullScanReset']);
    Route::post('/parser/urls/release', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'release']);
    
    // Get URLs for parser (moved from auth:sanctum group)
    Route::get('/parsing/get-urls/{supplier}', [UrlCollectionController::class, 'getUrls']);
});

// Защищённые маршруты
Route::middleware('auth:sanctum')->group(function () {
    Route::get('chrome/auth/status', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'tokenStatus']);
    Route::post('chrome/auth/token/session', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'issueTokenFromSession']);

    // ========== Chrome Extension API ==========
    // Без stateful/session middleware — только Bearer token аутентификация
    Route::withoutMiddleware([
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \App\Http\Middleware\EnforceSingleSession::class,
    ])->group(function () {
        Route::get('chrome/me', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'me']);
        Route::get('chrome/templates', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'listTemplates']);
        Route::get('chrome/templates/{id}', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'getTemplate']);
        Route::post('chrome/templates', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'saveTemplate']);
        Route::delete('chrome/templates/{id}', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'deleteTemplate']);
        Route::post('chrome/extract', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'extract']);
        Route::post('chrome/find-template', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'findTemplate']);
        Route::post('chrome/validate', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'validateFields']);
        Route::post('chrome/auth/revoke', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'revokeToken']);
    });
    
    // ========== Material Catalog (Block 1) ==========
    // IMPORTANT: These must be BEFORE apiResource('materials') to avoid
    // the resource route catching 'catalog' / 'search' as a {material} id.
    Route::get('materials/catalog', [MaterialCatalogController::class, 'catalog']);
    Route::post('materials/catalog', [MaterialCatalogController::class, 'store']);
    Route::get('materials/catalog/{id}', [MaterialCatalogController::class, 'show']);
    Route::put('materials/catalog/{id}', [MaterialCatalogController::class, 'updateMaterial']);
    Route::get('materials/search', [MaterialController::class, 'search']);
    Route::post('materials/parse-by-url', [MaterialCatalogController::class, 'parseByUrl']);
    Route::post('materials/check-domain', [MaterialCatalogController::class, 'checkDomain']);
    Route::post('materials/merge', [MaterialCatalogController::class, 'merge']);

    Route::apiResource('materials', MaterialController::class);
    Route::get('operations/search', [OperationController::class, 'search']);
    Route::get('operations/categories', [OperationController::class, 'getCategories']);
    Route::get('operations/{operation}/price-links', [OperationController::class, 'priceLinks']);
    Route::get('units', [\App\Http\Controllers\Api\UnitController::class, 'index']);
    Route::apiResource('operations', OperationController::class);
    Route::apiResource('detail-types', DetailTypeController::class);
    
    // Справочники
    Route::get('regions', [RegionController::class, 'index']);
    Route::apiResource('position-profiles', PositionProfileController::class);

    Route::apiResource('projects', ProjectController::class);
    Route::post('projects/{project}/positions/bulk', [ProjectPositionController::class, 'bulk']);
    Route::post('projects/{project}/positions/recalculate-prices', [ProjectPositionController::class, 'recalculatePrices']);
    Route::apiResource('projects.positions', ProjectPositionController::class);
    Route::apiResource('project-positions', ProjectPositionController::class)->only(['show','update','destroy']);

    // Facade materials API (legacy — kept for backward compat)
    Route::get('facade-materials/spec-constants', [FacadeMaterialController::class, 'specConstants']);
    Route::get('facade-materials', [FacadeMaterialController::class, 'index']);
    Route::get('facade-materials/{id}', [FacadeMaterialController::class, 'show']);
    Route::post('facade-materials/import-prices', [FacadeMaterialController::class, 'importPrices']);
    Route::post('facade-price-quotes', [\App\Http\Controllers\Api\FacadePriceQuoteController::class, '__invoke']);

    // Facades CRUD (canonical facades with multi-price quotes)
    Route::get('facades/filter-options', [\App\Http\Controllers\Api\FacadeController::class, 'filterOptions']);
    Route::get('facades', [\App\Http\Controllers\Api\FacadeController::class, 'index']);
    Route::post('facades', [\App\Http\Controllers\Api\FacadeController::class, 'store']);
    Route::get('facades/{id}', [\App\Http\Controllers\Api\FacadeController::class, 'show']);
    Route::put('facades/{id}', [\App\Http\Controllers\Api\FacadeController::class, 'update']);
    Route::delete('facades/{id}', [\App\Http\Controllers\Api\FacadeController::class, 'destroy']);
    Route::get('facades/{id}/quotes', [\App\Http\Controllers\Api\FacadeController::class, 'quotes']);

    // Facade quotes management
    Route::post('facade-quotes', [\App\Http\Controllers\Api\FacadeController::class, 'storeQuote']);
    Route::put('facade-quotes/{id}', [\App\Http\Controllers\Api\FacadeController::class, 'updateQuote']);
    Route::delete('facade-quotes/{id}', [\App\Http\Controllers\Api\FacadeController::class, 'deleteQuote']);
    Route::post('facade-quotes/{id}/duplicate', [\App\Http\Controllers\Api\FacadeController::class, 'duplicateQuote']);
    Route::post('facade-quotes/{id}/revalidate', [\App\Http\Controllers\Api\FacadeController::class, 'revalidateQuote']);
    Route::get('facade-quotes/similar', [\App\Http\Controllers\Api\FacadeController::class, 'similarQuotes']);

    // Unified finished products API (v2 compatibility layer; current subtype: facade)
    Route::get('finished-products/filter-options', [\App\Http\Controllers\Api\FinishedProductController::class, 'filterOptions']);
    Route::get('finished-products', [\App\Http\Controllers\Api\FinishedProductController::class, 'index']);
    Route::post('finished-products', [\App\Http\Controllers\Api\FinishedProductController::class, 'store']);
    Route::get('finished-products/{id}', [\App\Http\Controllers\Api\FinishedProductController::class, 'show']);
    Route::put('finished-products/{id}', [\App\Http\Controllers\Api\FinishedProductController::class, 'update']);
    Route::delete('finished-products/{id}', [\App\Http\Controllers\Api\FinishedProductController::class, 'destroy']);
    Route::get('finished-products/{id}/quotes', [\App\Http\Controllers\Api\FinishedProductController::class, 'quotes']);

    Route::get('materials/{id}/history', [MaterialController::class, 'history']);

    // ========== Material Catalog (Block 1) — ID-based routes ==========
    Route::post('materials/{id}/refresh', [MaterialCatalogController::class, 'refresh']);
    Route::get('materials/{id}/price-observations', [MaterialCatalogController::class, 'priceObservations']);
    Route::post('materials/{id}/price-observations', [MaterialCatalogController::class, 'addPriceObservation']);
    Route::post('materials/{id}/library', [MaterialCatalogController::class, 'addToLibrary']);
    Route::delete('materials/{id}/library', [MaterialCatalogController::class, 'removeFromLibrary']);
    Route::patch('materials/{id}/library', [MaterialCatalogController::class, 'updateLibraryEntry']);
    Route::post('materials/{id}/recalculate-trust', [MaterialCatalogController::class, 'recalculateTrust']);

    Route::apiResource('projects.fittings', ProjectFittingController::class);
    
    // Top-level routes for fittings - explicitly map to showById, updateById, destroyById
    Route::get('project-fittings/{fitting}', [ProjectFittingController::class, 'showById']);
    Route::put('project-fittings/{fitting}', [ProjectFittingController::class, 'updateById']);
    Route::delete('project-fittings/{fitting}', [ProjectFittingController::class, 'destroyById']);
    
    Route::apiResource('projects.expenses', \App\Http\Controllers\Api\ProjectExpenseController::class);
    Route::apiResource('projects.normohour-sources', ProjectNormohourSourceController::class);
    // Reorder route must come BEFORE apiResource to avoid {laborWork} param conflict
    Route::patch('projects/{project}/labor-works/reorder', [ProjectLaborWorkController::class, 'reorder']);
    Route::apiResource('projects.labor-works', ProjectLaborWorkController::class);
    
    // Labor work steps - explicit nested routes
    Route::get('projects/{project}/labor-works/{laborWork}/steps', [ProjectLaborWorkStepController::class, 'index']);
    Route::post('projects/{project}/labor-works/{laborWork}/steps', [ProjectLaborWorkStepController::class, 'store']);
    // Reorder route must come before individual step routes to avoid conflict
    Route::patch('projects/{project}/labor-works/{laborWork}/steps/reorder', [ProjectLaborWorkStepController::class, 'reorder']);
    // Batch operations for AI decomposition (must come before individual step routes)
    Route::put('projects/{project}/labor-works/{laborWork}/steps:replace', [ProjectLaborWorkStepController::class, 'replaceAll']);
    Route::post('projects/{project}/labor-works/{laborWork}/steps:append', [ProjectLaborWorkStepController::class, 'appendAll']);
    Route::get('projects/{project}/labor-works/{laborWork}/steps/{step}', [ProjectLaborWorkStepController::class, 'show']);
    Route::put('projects/{project}/labor-works/{laborWork}/steps/{step}', [ProjectLaborWorkStepController::class, 'update']);
    Route::patch('projects/{project}/labor-works/{laborWork}/steps/{step}', [ProjectLaborWorkStepController::class, 'update']);
    Route::delete('projects/{project}/labor-works/{laborWork}/steps/{step}', [ProjectLaborWorkStepController::class, 'destroy']);
    
    // Labor work hours management
    Route::post('projects/{project}/labor-works/{laborWork}/hours/set-manual', [LaborWorkHoursController::class, 'setManual']);
    Route::post('projects/{project}/labor-works/{laborWork}/hours/set-from-steps', [LaborWorkHoursController::class, 'setFromSteps']);
    Route::post('projects/{project}/labor-works/{laborWork}/hours/recalculate', [LaborWorkHoursController::class, 'recalculate']);
    Route::get('projects/{project}/labor-works/{laborWork}/hours/info', [LaborWorkHoursController::class, 'getInfo']);
    
    // ========== AI Work Decomposition API ==========
    Route::post('work/decompose', [WorkDecomposeController::class, 'decompose']);
    Route::post('work/presets/feedback', [WorkDecomposeController::class, 'feedback']);
    
    // Labor work rate binding
    Route::post('project-labor-works/{id}/bind-rate', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'bindRate']);
    Route::get('project-labor-works/{id}/rate-info', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'getRateInfo']);
    Route::post('projects/{projectId}/bind-labor-work-rates', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'bindRatesForProject']);
    
    // Labor work rate recalculation (новые endpoints)
    Route::post('projects/{projectId}/recalculate-labor-rates', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'recalculateLaborRates']);
    Route::get('projects/{projectId}/profiles/{profileId}/effective-rate', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'getEffectiveRate']);
    
    // Auto-recalculation на загрузке страницы (preview mode)
    Route::post('projects/{projectId}/labor-works/recalculate', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'recalculateLaborWorksAuto']);
    
    // Manual recalculation + fix rates (кнопка пересчета)
    Route::post('projects/{projectId}/profile-rates/recalculate-and-fix', [\App\Http\Controllers\Api\LaborWorkRateController::class, 'recalculateAndFixRates']);
    
    // Global Normohour Sources API
    Route::apiResource('global-normohour-sources', GlobalNormohourSourceController::class);
    Route::get('global-normohour-sources/{id}/toggle-active', [GlobalNormohourSourceController::class, 'toggleActive']);
    Route::get('global-normohour-sources/profile/{positionProfileId}', [GlobalNormohourSourceController::class, 'getForProfile']);
    
    // Profile rates (нормо-часовые ставки по профилям)
    // Важно: кастомные routes должны быть ДО apiResource, чтобы не переопределялись
    Route::post('projects/{projectId}/profile-rates/calculate', [ProjectProfileRateController::class, 'calculate']);
    Route::post('projects/{projectId}/profile-rates/lock', [ProjectProfileRateController::class, 'lockRates']);
    Route::post('projects/{projectId}/profile-rates/unlock', [ProjectProfileRateController::class, 'unlockRates']);
    Route::post('projects/{projectId}/profile-rates/{profileId}/recalculate', [ProjectProfileRateController::class, 'recalculate']);
    Route::get('projects/{projectId}/profile-rates/sources/{profileId}', [ProjectProfileRateController::class, 'getSources']);
    Route::apiResource('projects.profile-rates', ProjectProfileRateController::class);

    // Operations: aggregated automatic + manual per project
    Route::get('projects/{project}/operations', [ProjectsOperationsController::class, 'index']);
    Route::post('projects/{project}/operations', [ProjectOperationController::class, 'store']);
    // Manual operation update/delete
    Route::put('project-operations/{projectManualOperation}', [ProjectManualOperationController::class, 'update']);
    Route::delete('project-operations/{projectManualOperation}', [ProjectManualOperationController::class, 'destroy']);

    // ========== Смета API ==========
    Route::get('smeta/report/{projectId}', [SmetaController::class, 'report']);
    Route::get('smeta/pdf/{project}', [SmetaPdfController::class, 'generate']);
    
    // ========== Project Revisions (Snapshots) API ==========
    Route::get('projects/{project}/revisions/latest', [ProjectRevisionController::class, 'latest']);
    Route::post('projects/{project}/revisions', [ProjectRevisionController::class, 'store']);
    Route::get('projects/{project}/revisions', [ProjectRevisionController::class, 'index']);
    Route::get('projects/{project}/revisions/{number}', [ProjectRevisionController::class, 'show']);
    Route::get('projects/{project}/revisions/{number}/pdf', [ProjectRevisionController::class, 'pdf']);
    Route::post('projects/{project}/revisions/{number}/publish', [ProjectRevisionController::class, 'publish']);
    Route::post('projects/{project}/revisions/{number}/unpublish', [ProjectRevisionController::class, 'unpublish']);
    Route::post('projects/{project}/revisions/{number}/lock', [ProjectRevisionController::class, 'lock']);
    
    // ========== Position Import API ==========
    // Upload file and create import session
    Route::post('projects/{project}/imports', [ProjectImportController::class, 'upload']);
    // Run import
    Route::post('projects/{project}/imports/{importSession}/run', [ProjectImportController::class, 'run']);
    // Get import session details
    Route::get('imports/{importSession}', [ProjectImportController::class, 'show']);
    // Get preview (re-read with different options)
    Route::get('imports/{importSession}/preview', [ProjectImportController::class, 'preview']);
    // Save mapping
    Route::post('imports/{importSession}/mapping', [ProjectImportController::class, 'saveMapping']);
    // Get import preview (dry run)
    Route::get('imports/{importSession}/import-preview', [ProjectImportController::class, 'importPreview']);
    // Delete import session
    Route::delete('imports/{importSession}', [ProjectImportController::class, 'destroy']);
    
    // ========== Парсинг API ==========
    Route::apiResource('parsing/sessions', ParsingController::class);
    
    // URL Collection
    Route::post('parsing/collect-urls/{supplier}', [UrlCollectionController::class, 'collectUrls']);
    Route::get('parsing/url-stats/{supplier}', [UrlCollectionController::class, 'getStats']);
    Route::get('parsing/sessions/{session}/logs', [ParsingController::class, 'logs']);
    Route::post('parsing/sessions/{session}/stop', [ParsingController::class, 'stop']);
    Route::post('parsing/update-total', [ParsingController::class, 'updateTotal']); // Обновление total_urls после сбора
    
    // NEW: Session state & retry-failed endpoints (ANTI-LOOP)
    Route::get('parsing/sessions/{session}/state', [ParsingController::class, 'getState']);
    Route::post('parsing/sessions/{session}/retry-failed-urls', [ParsingController::class, 'retryFailedUrls']);
    
    // System Status
    Route::get('system/parser/status', [SystemController::class, 'parserStatus']);
    
    // Supplier Health
    Route::get('parsing/suppliers/health', [SupplierHealthController::class, 'index']);
    Route::get('parsing/suppliers/health/{supplier}', [SupplierHealthController::class, 'show']);

    // Supplier Configs
    Route::get('parsing/suppliers', [\App\Http\Controllers\Api\ParserSupplierConfigController::class, 'index']);
    Route::get('parsing/suppliers/{supplier}/config', [\App\Http\Controllers\Api\ParserSupplierConfigController::class, 'show']);
    Route::put('parsing/suppliers/{supplier}/config', [\App\Http\Controllers\Api\ParserSupplierConfigController::class, 'update']);

    // Supplier Collect Profiles
    Route::get('parsing/suppliers/{supplier}/collect-profiles', [\App\Http\Controllers\Api\ParserSupplierCollectProfileController::class, 'index']);
    Route::post('parsing/suppliers/{supplier}/collect-profiles', [\App\Http\Controllers\Api\ParserSupplierCollectProfileController::class, 'store']);
    Route::put('parsing/suppliers/{supplier}/collect-profiles/{profile}', [\App\Http\Controllers\Api\ParserSupplierCollectProfileController::class, 'update']);
    Route::delete('parsing/suppliers/{supplier}/collect-profiles/{profile}', [\App\Http\Controllers\Api\ParserSupplierCollectProfileController::class, 'destroy']);
    
    // Analytics
    Route::get('parsing/analytics/chart', [AnalyticsController::class, 'chart']);
    Route::get('parsing/analytics/stats', [AnalyticsController::class, 'stats']);
    Route::get('parsing/sessions/{session}/stats', [AnalyticsController::class, 'stats']);
    
    // Settings
    Route::get('parsing/settings', [ParserSettingsController::class, 'index']);
    Route::put('parsing/settings', [ParserSettingsController::class, 'update']);
    Route::post('parsing/settings/regenerate-token', [ParserSettingsController::class, 'regenerateToken']);
    Route::get('parsing/settings/allowed-ips', [ParserSettingsController::class, 'getAllowedIps']);
    Route::put('parsing/settings/allowed-ips', [ParserSettingsController::class, 'updateAllowedIps']);
    
    // Maintenance
    Route::post('parsing/maintenance/cleanup', [ParserSettingsController::class, 'cleanup']);
    Route::post('parsing/maintenance/prune', [ParserSettingsController::class, 'prune']);
    Route::post('parsing/maintenance/clear-logs', [ParserSettingsController::class, 'clearAllLogs']);
    
    // URL Queue Management (веб-интерфейс)
    Route::get('parser/urls', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'index']);
    Route::get('parser/urls/stats', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'stats']);
    Route::post('parser/urls/reset-stale', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'resetStale']);
    Route::post('parser/urls/reset-failed', [\App\Http\Controllers\Api\Parser\UrlQueueController::class, 'resetFailed']);

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateProfile']);
    Route::put('me/password', [AuthController::class, 'updatePassword']);
    Route::post('auth/password/change', [AuthController::class, 'changePassword']);
    
    // ========== PIN & Trusted Devices (Protected) ==========
    Route::post('auth/pin/set', [PinAuthController::class, 'set']);
    Route::post('auth/pin/disable', [PinAuthController::class, 'disable']);
    Route::get('auth/trusted-devices', [PinAuthController::class, 'trustedDevices']);
    Route::post('auth/trusted-devices/{id}/revoke', [PinAuthController::class, 'revokeDevice']);
    Route::post('auth/terminate-sessions', [PinAuthController::class, 'terminateSessions']);
    Route::get('auth/sessions', [PinAuthController::class, 'sessions']);
    Route::post('auth/sessions/terminate-others', [PinAuthController::class, 'terminateOtherSessions']);
    
    // User Settings API
    Route::get('user/settings', [UserSettingsController::class, 'get']);
    Route::put('user/settings', [UserSettingsController::class, 'update']);
    
    // ========== User Notifications API ==========
    Route::get('notifications', [UserNotificationController::class, 'index']);
    Route::get('notifications/unread-count', [UserNotificationController::class, 'unreadCount']);
    Route::post('notifications/{id}/read', [UserNotificationController::class, 'read']);
    Route::post('notifications/read-all', [UserNotificationController::class, 'readAll']);
    Route::post('notifications/{id}/click', [UserNotificationController::class, 'click']);
    
    // ========== Admin LLM Settings API ==========
    Route::get('admin/llm-providers', [AdminLLMController::class, 'providers']);
    Route::get('admin/llm-settings', [AdminLLMController::class, 'index']);
    Route::put('admin/llm-settings', [AdminLLMController::class, 'update']);
    Route::post('admin/llm-test', [AdminLLMController::class, 'test']);
    Route::post('admin/llm-reset-circuit', [AdminLLMController::class, 'resetCircuit']);
    
    // ========== Admin LLM Prompts API ==========
    Route::get('admin/llm-prompts', [AdminLLMController::class, 'getPrompts']);
    Route::put('admin/llm-prompts', [AdminLLMController::class, 'savePrompts']);
    Route::post('admin/llm-prompts/reset', [AdminLLMController::class, 'resetPrompts']);
    Route::post('admin/llm-prompts/preview', [AdminLLMController::class, 'previewPrompt']);
    
    // ========== Admin LLM Statistics API ==========
    Route::get('admin/llm-stats', [AdminLLMStatsController::class, 'index']);
    Route::get('admin/llm-stats/users', [AdminLLMStatsController::class, 'users']);
    Route::get('admin/llm-stats/providers', [AdminLLMStatsController::class, 'providers']);
    Route::get('admin/llm-stats/activity', [AdminLLMStatsController::class, 'activity']);
    
    // ========== Admin Notifications API ==========
    Route::get('admin/notifications', [AdminNotificationController::class, 'index']);
    Route::post('admin/notifications', [AdminNotificationController::class, 'store']);
    Route::get('admin/notifications/{id}', [AdminNotificationController::class, 'show']);
    Route::put('admin/notifications/{id}', [AdminNotificationController::class, 'update']);
    Route::post('admin/notifications/{id}/send', [AdminNotificationController::class, 'send']);
    Route::post('admin/notifications/{id}/cancel', [AdminNotificationController::class, 'cancel']);
    Route::get('admin/notifications/{id}/stats', [AdminNotificationController::class, 'stats']);
    Route::get('admin/users/search', [AdminNotificationController::class, 'searchUsers']);
    
    // ========== Suppliers & Price Lists API ==========
    Route::apiResource('suppliers', \App\Http\Controllers\Api\SupplierController::class);
    Route::post('suppliers/{supplier}/archive', [\App\Http\Controllers\Api\SupplierController::class, 'archive']);
    Route::post('suppliers/{supplier}/restore', [\App\Http\Controllers\Api\SupplierController::class, 'restore']);
    Route::get('suppliers/{supplier}/aliases', [\App\Http\Controllers\Api\SupplierController::class, 'aliases']);
    
    // Price Documents (DMS — no parsing, for facade price refs)
    Route::get('suppliers/{supplier}/price-documents', [\App\Http\Controllers\Api\PriceDocumentController::class, 'index']);
    Route::post('suppliers/{supplier}/price-documents', [\App\Http\Controllers\Api\PriceDocumentController::class, 'store']);
    Route::post('suppliers/{supplier}/price-documents/{version}/activate', [\App\Http\Controllers\Api\PriceDocumentController::class, 'activate']);
    Route::post('suppliers/{supplier}/price-documents/{version}/archive', [\App\Http\Controllers\Api\PriceDocumentController::class, 'archiveVersion']);
    
    // Price Lists (nested under suppliers)
    Route::get('price-lists', [\App\Http\Controllers\Api\PriceListController::class, 'listAll']);
    Route::get('suppliers/{supplier}/price-lists', [\App\Http\Controllers\Api\PriceListController::class, 'index']);
    Route::post('suppliers/{supplier}/price-lists', [\App\Http\Controllers\Api\PriceListController::class, 'store']);
    Route::get('price-lists/{priceList}', [\App\Http\Controllers\Api\PriceListController::class, 'show']);
    Route::patch('price-lists/{priceList}', [\App\Http\Controllers\Api\PriceListController::class, 'update']);
    Route::delete('price-lists/{priceList}', [\App\Http\Controllers\Api\PriceListController::class, 'destroy']);
    Route::get('price-lists/{priceList}/actual-version', [\App\Http\Controllers\Api\PriceListController::class, 'actualVersion']);
    
    // Price List Versions Management
    Route::get('price-lists/{priceList}/versions', [\App\Http\Controllers\Api\PriceListVersionController::class, 'index']);
    Route::post('price-list-versions', [\App\Http\Controllers\Api\PriceListVersionController::class, 'store']);
    Route::post('price-lists/{priceList}/versions/{version}/activate', [\App\Http\Controllers\Api\PriceListVersionController::class, 'activate']);
    Route::post('price-lists/{priceList}/versions/{version}/archive', [\App\Http\Controllers\Api\PriceListVersionController::class, 'archive']);
    
    // Version details and content
    Route::get('price-list-versions/{version}', [\App\Http\Controllers\Api\PriceListVersionController::class, 'show']);
    Route::get('price-list-versions/{version}/download', [\App\Http\Controllers\Api\PriceListVersionController::class, 'download']);
    Route::get('price-list-versions/{version}/items', [\App\Http\Controllers\Api\PriceListVersionController::class, 'items']);
    
    // Operation Price Linking
    Route::put('operation-prices/{operationPrice}/link', [\App\Http\Controllers\Api\PriceListVersionController::class, 'linkOperation']);
    Route::delete('operation-prices/{operationPrice}/link', [\App\Http\Controllers\Api\PriceListVersionController::class, 'unlinkOperation']);
    
    // ========== Price Import API ==========
    Route::get('price-imports', [\App\Http\Controllers\Api\PriceImportController::class, 'index']);
    Route::post('price-imports/upload', [\App\Http\Controllers\Api\PriceImportController::class, 'upload']);
    Route::post('price-imports/paste', [\App\Http\Controllers\Api\PriceImportController::class, 'paste']);
    Route::post('price-imports/reuse', [\App\Http\Controllers\Api\PriceImportController::class, 'reuse']);
    Route::get('price-imports/{session}', [\App\Http\Controllers\Api\PriceImportController::class, 'show']);
    Route::patch('price-imports/{session}', [\App\Http\Controllers\Api\PriceImportController::class, 'updateSettings']);
    Route::post('price-imports/{session}/mapping', [\App\Http\Controllers\Api\PriceImportController::class, 'saveMapping']);
    Route::get('price-imports/{session}/resolution', [\App\Http\Controllers\Api\PriceImportController::class, 'resolution']);
    Route::post('price-imports/{session}/bulk-action', [\App\Http\Controllers\Api\PriceImportController::class, 'bulkAction']);
    Route::post('price-imports/{session}/execute', [\App\Http\Controllers\Api\PriceImportController::class, 'execute']);
    Route::post('price-imports/{session}/cancel', [\App\Http\Controllers\Api\PriceImportController::class, 'cancel']);
    Route::delete('price-imports/{session}', [\App\Http\Controllers\Api\PriceImportController::class, 'destroy']);
    
    Route::get('operations/exclusion-groups', [\App\Http\Controllers\Api\OperationController::class, 'getExclusionGroups']);
    
    // ========== Operation Groups API (multi-supplier) ==========
    Route::apiResource('operation-groups', \App\Http\Controllers\Api\OperationGroupController::class);
    Route::post('operation-groups/{operationGroup}/add-operations', [\App\Http\Controllers\Api\OperationGroupController::class, 'addOperations']);
    Route::post('operation-groups/{operationGroup}/remove-operations', [\App\Http\Controllers\Api\OperationGroupController::class, 'removeOperations']);
    Route::get('operation-groups/{operationGroup}/median', [\App\Http\Controllers\Api\OperationGroupController::class, 'median']);
    
    // ========== Supplier Operations API ==========
    Route::get('supplier-operations', [\App\Http\Controllers\Api\SupplierOperationController::class, 'index']);
    Route::get('supplier-operations/search', [\App\Http\Controllers\Api\SupplierOperationController::class, 'search']);
    Route::get('supplier-operations/categories', [\App\Http\Controllers\Api\SupplierOperationController::class, 'categories']);
    Route::get('supplier-operations/units', [\App\Http\Controllers\Api\SupplierOperationController::class, 'units']);
    Route::get('supplier-operations/{supplierOperation}', [\App\Http\Controllers\Api\SupplierOperationController::class, 'show']);
});

// Старые маршруты (можно удалить позже)
Route::apiResource('modules', FurnitureModuleController::class);
Route::get('modules/{id}/cost', [FurnitureModuleController::class, 'getCost']);
