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
use App\Http\Controllers\Api\Internal\ParserScreenshotController;
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
use App\Http\Controllers\Api\RevisionRunController;
use App\Http\Controllers\Api\EvidenceRunController;
use App\Http\Controllers\Api\ProjectProfileRateController;
use App\Http\Controllers\Api\WorkDecomposeController;
use App\Http\Controllers\Api\AdminLLMController;
use App\Http\Controllers\Api\AdminLLMStatsController;
use App\Http\Controllers\Api\AdminMaterialDimensionParseFailureController;
use App\Http\Controllers\Api\AdminMaterialDimensionRuleController;
use App\Http\Controllers\Api\AdminMaterialTypePatternController;
use App\Http\Controllers\Api\AdminSystemLogController;
use App\Http\Controllers\Api\AdminUsersController;
use App\Http\Controllers\Api\PinAuthController;
use App\Http\Controllers\Api\PhoneAuthController;
use App\Http\Controllers\Api\YandexAuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\AuthMethodController;
use App\Http\Controllers\Api\SmsRuCallCheckWebhookController;
use App\Http\Controllers\Api\SmsRuCallCheckHealthController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\Api\FacadeMaterialController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\IdeaController;
use App\Http\Controllers\Api\VoteController;
use App\Http\Controllers\ProjectImportController;
use App\Http\Middleware\InternalOnlyMiddleware;

use App\Http\Controllers\Api\MaterialCatalogController;

// Публичные маршруты
Route::post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);

// ========== Chrome Extension Token Auth ==========
// Без stateful-middleware (нет сессии/cookie — только Bearer token).
// Rate-limited to 10 attempts/minute to protect against credential stuffing.
// CSRF exemption is not needed here: no session cookie is used.
Route::withoutMiddleware([
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \App\Http\Middleware\EnforceSingleSession::class,
])->middleware(['throttle:10,1'])->group(function () {
    Route::post('chrome/auth/token', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'issueToken']);
});

// ========== PIN Auth (публичные — без auth, но с проверкой cookie) ==========
Route::get('auth/pin/status', [PinAuthController::class, 'status']);
Route::post('auth/pin/login', [PinAuthController::class, 'login']);
Route::post('auth/trusted-device/forget', [PinAuthController::class, 'forgetDevice']);

// ========== Phone Auth (публичные) ==========
Route::post('auth/phone/call/request', [PhoneAuthController::class, 'requestCall']);
Route::get('auth/phone/call/status', [PhoneAuthController::class, 'callStatus']);
Route::post('auth/phone/call/status', [PhoneAuthController::class, 'callStatus']);
Route::post('auth/phone/call/webhook', SmsRuCallCheckWebhookController::class);
Route::get('auth/phone/call/webhook', SmsRuCallCheckWebhookController::class);

Route::post('auth/phone/request-code', [PhoneAuthController::class, 'requestCode']);
Route::post('auth/phone/resend-code', [PhoneAuthController::class, 'resendCode']);
Route::post('auth/phone/verify-code', [PhoneAuthController::class, 'verifyCode']);
Route::post('auth/phone/callcheck/webhook', SmsRuCallCheckWebhookController::class);
Route::get('auth/phone/callcheck/webhook', SmsRuCallCheckWebhookController::class);

// ========== Yandex OAuth (публичные) ==========
// OAuth state хранится в сессии, поэтому для redirect/callback обязателен web middleware.
Route::middleware('web')->group(function () {
    Route::get('auth/yandex/redirect', [YandexAuthController::class, 'redirect']);
    Route::get('auth/yandex/callback', [YandexAuthController::class, 'callback']);
});

// ========== Password Reset (публичные) ==========
Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink']);
Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);

// ========== Email Verification (публичные) ==========
// Signed URL redirect from verification email (stateless, no session required).
Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.email.verify');

// Public resend for users who cannot log in yet (unverified state).
// Rate-limited; anti-enumeration: always 200 regardless of email existence.
Route::post('email/resend-verification', [EmailVerificationController::class, 'resendVerification']);

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
    Route::post('/internal/parser/screenshot', [ParserScreenshotController::class, 'handle']);
    
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

// ========== Screenshot / public-storage file serving ==========
// Serves evidence screenshot assets through the /api/ prefix so that
// production nginx (which routes only /api/ to the backend) delivers
// the actual image instead of the SPA shell.
// Restricted to the screenshots/ directory; rejects path-traversal.
Route::get('screenshots/{path}', function (string $path) {
    // Normalise and reject traversal
    $fullPath = 'screenshots/' . $path;
    if (str_contains($fullPath, '..') || str_contains($fullPath, "\0")) {
        abort(404);
    }
    if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($fullPath)) {
        abort(404);
    }
    return \Illuminate\Support\Facades\Storage::disk('public')->response($fullPath);
})->where('path', '.+');

// Защищённые маршруты
Route::middleware('auth:sanctum')->group(function () {
    // ========== Security: Auth-Method Profile + Universal Step-Up ==========
    // throttle:N,M  → N attempts per M minutes per authenticated user+IP
    Route::get('security/auth-status', [\App\Http\Controllers\Api\SecurityController::class, 'authStatus']);
    Route::post('security/step-up/initiate', [\App\Http\Controllers\Api\SecurityController::class, 'stepUpInitiate'])
        ->middleware('throttle:10,1');
    Route::post('security/step-up/verify-password', [\App\Http\Controllers\Api\SecurityController::class, 'stepUpVerifyPassword'])
        ->middleware('throttle:5,1');
    Route::post('security/step-up/request-phone-otp', [\App\Http\Controllers\Api\SecurityController::class, 'stepUpRequestPhoneOtp'])
        ->middleware('throttle:3,1');
    Route::post('security/step-up/verify-phone-otp', [\App\Http\Controllers\Api\SecurityController::class, 'stepUpVerifyPhoneOtp'])
        ->middleware('throttle:5,1');
    // Email OTP step-up (Block 6A) — reliable delivery for set_password flow
    Route::post('security/step-up/request-email-otp', [\App\Http\Controllers\Api\SecurityController::class, 'stepUpRequestEmailOtp'])
        ->middleware('throttle:3,1');
    Route::post('security/step-up/verify-email-otp', [\App\Http\Controllers\Api\SecurityController::class, 'stepUpVerifyEmailOtp'])
        ->middleware('throttle:5,1');
    Route::post('security/password/set', [\App\Http\Controllers\Api\SecurityController::class, 'setPassword'])
        ->middleware('throttle:5,1');

    // ========== Security: Account Control Surface (sessions + devices) ==========
    // Sessions — no step-up required (cleaning up your own access surfaces is always safe)
    Route::get('security/sessions', [\App\Http\Controllers\Api\AccountControlController::class, 'listSessions']);
    Route::delete('security/sessions/others', [\App\Http\Controllers\Api\AccountControlController::class, 'revokeOtherSessions']);
    Route::delete('security/sessions/{id}', [\App\Http\Controllers\Api\AccountControlController::class, 'revokeSession']);
    // Trusted devices — step-up only for bulk revocation (high impact)
    Route::get('security/trusted-devices', [\App\Http\Controllers\Api\AccountControlController::class, 'listDevices']);
    Route::delete('security/trusted-devices', [\App\Http\Controllers\Api\AccountControlController::class, 'revokeAllDevices'])
        ->middleware('throttle:3,1');
    Route::delete('security/trusted-devices/{id}', [\App\Http\Controllers\Api\AccountControlController::class, 'revokeDevice']);

    // ========== Security: Yandex-Bootstrap Phone Linking ==========
    // No step-up required — the active authenticated session is the trust anchor.
    // Scope: adding a phone as first factor only. Available to users without a verified phone.
    Route::post('security/bootstrap/phone/initiate', [\App\Http\Controllers\Api\BootstrapController::class, 'initiatePhoneLink'])
        ->middleware('throttle:5,1');
    Route::post('security/bootstrap/phone/verify', [\App\Http\Controllers\Api\BootstrapController::class, 'verifyPhoneLink'])
        ->middleware('throttle:10,1');

    // ========== Phone Auth: Complete Registration (onboarding) ==========
    Route::post('register/complete', [PhoneAuthController::class, 'completeRegistration']);

    // ========== Email Verification (protected) ==========
    Route::post('email/verification-notification', [EmailVerificationController::class, 'sendNotification']);

    // ========== Ideas Board API ==========
    Route::post('ideas', [IdeaController::class, 'store']);
    Route::get('ideas', [IdeaController::class, 'index']);
    Route::get('ideas/{idea}', [IdeaController::class, 'show']);
    Route::delete('ideas/{idea}', [IdeaController::class, 'destroy']);
    Route::post('ideas/{idea}/vote', [VoteController::class, 'vote']);
    Route::delete('ideas/{idea}/vote', [VoteController::class, 'removeVote']);
    Route::post('ideas/{idea}/comments', [CommentController::class, 'store']);
    Route::delete('ideas/{idea}/comments/{comment}', [CommentController::class, 'destroy']);
    Route::patch('ideas/{idea}/status', [IdeaController::class, 'updateStatus']);

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
        Route::patch('chrome/templates/{id}/visibility', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'updateTemplateVisibility']);
        Route::patch('chrome/templates/{id}/status', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'updateTemplateStatus']);
        Route::post('chrome/extract', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'extract']);
        Route::post('chrome/find-template', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'findTemplate']);
        Route::post('chrome/validate', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'validateFields']);
        Route::get('chrome/revision-items', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'listRevisionItems']);
        Route::post('chrome/revision-items/{itemId}/evidence', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'submitItemEvidence']);
        Route::post('chrome/auth/revoke', [\App\Http\Controllers\Api\ChromeExtensionController::class, 'revokeToken']);

        // ========== Generic Evidence Capture (Block G3) ==========
        Route::get('chrome/generic-items', [\App\Http\Controllers\Api\GenericChromeController::class, 'listGenericItems']);
        Route::post('chrome/capture-observation', [\App\Http\Controllers\Api\GenericChromeController::class, 'captureObservation']);
        Route::post('chrome/generic-items/{itemId}/capture', [\App\Http\Controllers\Api\GenericChromeController::class, 'captureGenericItem']);
        Route::post('chrome/extract-with-evidence', [\App\Http\Controllers\Api\GenericChromeController::class, 'extractWithEvidence']);
        Route::post('chrome/labor-captures', [\App\Http\Controllers\Api\ChromeLaborCaptureController::class, 'store']);
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
    Route::get('operations/{operation}/pricing-summary', [OperationController::class, 'pricingSummary']);
    Route::get('operations/{operation}/application-rule', [OperationController::class, 'applicationRule']);
    Route::post('operations/{operation}/application-rule', [OperationController::class, 'storeApplicationRule']);
    Route::put('operations/{operation}/application-rules/{rule}', [OperationController::class, 'updateApplicationRule']);
    Route::get('operations/{operation}/price-sources', [\App\Http\Controllers\Api\OperationPriceSourceController::class, 'index']);
    Route::post('operations/{operation}/price-sources', [\App\Http\Controllers\Api\OperationPriceSourceController::class, 'store']);
    Route::patch('price-sources/{id}/activate', [\App\Http\Controllers\Api\OperationPriceSourceController::class, 'activate']);
    Route::delete('price-sources/{id}', [\App\Http\Controllers\Api\OperationPriceSourceController::class, 'destroy']);
    Route::get('units', [\App\Http\Controllers\Api\UnitController::class, 'index']);
    Route::apiResource('operations', OperationController::class);
    Route::apiResource('detail-types', DetailTypeController::class);
    
    // Справочники
    Route::get('regions', [RegionController::class, 'index']);
    Route::apiResource('position-profiles', PositionProfileController::class);
    Route::prefix('pricing/labor')->group(function () {
        Route::get('providers', [\App\Http\Controllers\Api\Pricing\Labor\LaborProviderController::class, 'index']);
        Route::post('providers', [\App\Http\Controllers\Api\Pricing\Labor\LaborProviderController::class, 'store']);
        Route::get('providers/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborProviderController::class, 'show']);
        Route::put('providers/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborProviderController::class, 'update']);
        Route::delete('providers/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborProviderController::class, 'destroy']);

        Route::get('profiles', [\App\Http\Controllers\Api\Pricing\Labor\LaborProfileController::class, 'index']);
        Route::post('profiles', [\App\Http\Controllers\Api\Pricing\Labor\LaborProfileController::class, 'store']);
        Route::get('profiles/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborProfileController::class, 'show']);
        Route::put('profiles/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborProfileController::class, 'update']);
        Route::delete('profiles/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborProfileController::class, 'destroy']);

        Route::get('sources', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceSourceController::class, 'index']);
        Route::post('sources', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceSourceController::class, 'store']);
        Route::get('sources/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceSourceController::class, 'show']);
        Route::put('sources/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceSourceController::class, 'update']);
        Route::delete('sources/{id}', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceSourceController::class, 'destroy']);
        Route::get('sources/{id}/assets', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceAssetController::class, 'index']);
        Route::post('sources/{id}/assets', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceAssetController::class, 'store']);
        Route::delete('sources/{id}/assets/{assetId}', [\App\Http\Controllers\Api\Pricing\Labor\LaborEvidenceAssetController::class, 'destroy']);
    });

    Route::apiResource('projects', ProjectController::class);
    Route::get('projects/{project}/labor-sources', [\App\Http\Controllers\Api\ProjectLaborEvidenceSourceController::class, 'index']);
    Route::post('projects/{project}/labor-sources/attach', [\App\Http\Controllers\Api\ProjectLaborEvidenceSourceController::class, 'attach']);
    Route::post('projects/{project}/labor-sources/detach', [\App\Http\Controllers\Api\ProjectLaborEvidenceSourceController::class, 'detach']);
    Route::get('projects/{project}/labor-cost', [\App\Http\Controllers\Api\ProjectLaborCostController::class, 'show']);
    Route::get('projects/{project}/labor-diagnostics', [\App\Http\Controllers\Api\ProjectLaborDiagnosticsController::class, 'show']);
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
    Route::get('finished-product-specifications', [\App\Http\Controllers\Api\FinishedProductSpecificationController::class, 'index']);
    Route::post('finished-product-specifications', [\App\Http\Controllers\Api\FinishedProductSpecificationController::class, 'store']);
    Route::get('finished-product-specifications/{id}', [\App\Http\Controllers\Api\FinishedProductSpecificationController::class, 'show']);
    Route::match(['put', 'patch'], 'finished-product-specifications/{id}', [\App\Http\Controllers\Api\FinishedProductSpecificationController::class, 'update']);
    Route::delete('finished-product-specifications/{id}', [\App\Http\Controllers\Api\FinishedProductSpecificationController::class, 'destroy']);
    Route::get('finished-product-specifications/{specification}/pricing/sources', [\App\Http\Controllers\Api\FinishedProductPriceSourceController::class, 'index']);
    Route::post('finished-product-specifications/{specification}/pricing/sources', [\App\Http\Controllers\Api\FinishedProductPriceSourceController::class, 'store']);
    Route::get('finished-product-price-sources/{source}/details', [\App\Http\Controllers\Api\FinishedProductPriceSourceController::class, 'details']);
    Route::put('finished-product-price-sources/{source}', [\App\Http\Controllers\Api\FinishedProductPriceSourceController::class, 'update']);
    Route::post('finished-product-price-sources/{source}/activate', [\App\Http\Controllers\Api\FinishedProductPriceSourceController::class, 'activate']);
    Route::post('finished-product-price-sources/{source}/deactivate', [\App\Http\Controllers\Api\FinishedProductPriceSourceController::class, 'deactivate']);
    Route::get('finished-product-price-sources/{source}/evidence-assets', [\App\Http\Controllers\Api\FinishedProductPriceEvidenceAssetController::class, 'index']);
    Route::post('finished-product-price-sources/{source}/evidence-assets', [\App\Http\Controllers\Api\FinishedProductPriceEvidenceAssetController::class, 'store']);
    Route::get('finished-product-price-evidence-assets/{asset}/open', [\App\Http\Controllers\Api\FinishedProductPriceEvidenceAssetController::class, 'open']);
    Route::delete('finished-product-price-evidence-assets/{asset}', [\App\Http\Controllers\Api\FinishedProductPriceEvidenceAssetController::class, 'destroy']);
    Route::get('finished-product-specifications/{specification}/pricing/summary', [\App\Http\Controllers\Api\FinishedProductComputedPriceController::class, 'show']);
    Route::get('finished-product-specifications/{specification}/pricing/breakdown', [\App\Http\Controllers\Api\FinishedProductComputedPriceController::class, 'breakdown']);
    Route::put('finished-product-specifications/{specification}/pricing/aggregation-profile', [\App\Http\Controllers\Api\FinishedProductAggregationProfileController::class, 'update']);

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
    Route::get('projects/{project}/revisions/{number}/price-justification.pdf', [ProjectRevisionController::class, 'priceJustificationPdf']);
    Route::post('projects/{project}/revisions/{number}/publish', [ProjectRevisionController::class, 'publish']);
    Route::post('projects/{project}/revisions/{number}/unpublish', [ProjectRevisionController::class, 'unpublish']);
    Route::post('projects/{project}/revisions/{number}/lock', [ProjectRevisionController::class, 'lock']);
    Route::post('projects/{project}/revisions/run', [RevisionRunController::class, 'start']);
    Route::get('projects/{project}/revisions/run/{runId}', [RevisionRunController::class, 'show']);
    Route::post('projects/{project}/revisions/run/{runId}/retry', [RevisionRunController::class, 'retry']);
    Route::post('revisions/run/{runId}/items/{itemId}/manual', [RevisionRunController::class, 'manual']);
    Route::post('revisions/run/{runId}/items/{itemId}/attach-document', [RevisionRunController::class, 'attachDocument']);
    Route::post('projects/{project}/revisions/run/{runId}/finalize', [RevisionRunController::class, 'finalize']);

    // ========== Evidence Assets API ==========
    Route::get('evidence-assets/{assetId}/file', [\App\Http\Controllers\Api\EvidenceAssetController::class, 'file']);

    // ========== Generic Evidence Domain (Block G1) ==========
    Route::get('projects/{project}/evidence-runs', [EvidenceRunController::class, 'index']);
    Route::post('projects/{project}/evidence-runs', [EvidenceRunController::class, 'store']);
    Route::get('projects/{project}/evidence-runs/{runId}', [EvidenceRunController::class, 'show']);
    Route::post('projects/{project}/evidence-runs/{runId}/refresh', [EvidenceRunController::class, 'refresh']);
    Route::post('projects/{project}/evidence-runs/{runId}/finalize', [EvidenceRunController::class, 'finalize']);
    Route::post('projects/{project}/evidence-runs/{runId}/items/{itemId}/resolve', [EvidenceRunController::class, 'resolveItem']);
    Route::post('projects/{project}/evidence-runs/{runId}/items/{itemId}/skip', [EvidenceRunController::class, 'skipItem']);
    Route::post('projects/{project}/evidence-runs/{runId}/items/{itemId}/manual-resolve', [EvidenceRunController::class, 'manualResolveItem']);
    Route::get('projects/{project}/evidence-runs/{runId}/items/{itemId}/candidates', [EvidenceRunController::class, 'searchCandidatesForItem']);
    Route::get('projects/{project}/evidence-runs/{runId}/pdf', [EvidenceRunController::class, 'pdf']);
    Route::get('evidence-records', [EvidenceRunController::class, 'listRecords']);
    Route::get('evidence-records/search', [EvidenceRunController::class, 'searchRecords']);
    Route::get('evidence-records/{record}', [EvidenceRunController::class, 'showRecord']);
    Route::patch('evidence-records/{record}/verification-status', [EvidenceRunController::class, 'updateVerificationStatus']);
    Route::post('evidence-records', [EvidenceRunController::class, 'createRecord']);
    Route::post('evidence-records/{id}/assets', [EvidenceRunController::class, 'uploadAsset']);
    Route::post('pricing/manual-source', [\App\Http\Controllers\Api\ManualPricingSourceController::class, 'store']);
    
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
    Route::post('auth/pin/trust-device', [PinAuthController::class, 'trustDevice']);
    Route::get('auth/phone/callcheck/health', SmsRuCallCheckHealthController::class);
    Route::get('auth/methods', [AuthMethodController::class, 'index']);
    Route::get('auth/methods/providers/{provider}/redirect', [AuthMethodController::class, 'providerRedirect']);
    Route::post('auth/methods/providers/{provider}/unlink', [AuthMethodController::class, 'unlinkProvider']);
    Route::post('auth/methods/phone/request-change', [AuthMethodController::class, 'requestPhoneChange']);
    Route::post('auth/methods/phone/resend-change', [AuthMethodController::class, 'resendPhoneChange']);
    Route::post('auth/methods/phone/confirm-change', [AuthMethodController::class, 'confirmPhoneChange']);
    Route::post('auth/methods/email/change', [AuthMethodController::class, 'changeEmail']);
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
    Route::get('admin/llm-provider-states', [AdminLLMController::class, 'providerStates']);
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
    Route::delete('admin/notifications/{id}', [AdminNotificationController::class, 'destroy']);
    Route::get('admin/notifications/{id}/stats', [AdminNotificationController::class, 'stats']);
    Route::get('admin/users/search', [AdminNotificationController::class, 'searchUsers']);

    // ========== Admin System Logs API ==========
    Route::get('admin/system/logs', [AdminSystemLogController::class, 'index']);
    Route::get('admin/system/logs/download', [AdminSystemLogController::class, 'download']);

    // ========== Admin Users Management API ==========
    Route::get('admin/system/users', [AdminUsersController::class, 'index']);
    Route::get('admin/system/users/audit-log', [AdminUsersController::class, 'auditLog']);
    Route::post('admin/system/users/bulk-action', [AdminUsersController::class, 'bulkAction']);
    Route::get('admin/system/users/{id}', [AdminUsersController::class, 'show'])->where('id', '[0-9]+');
    Route::get('admin/system/users/{id}/dependencies', [AdminUsersController::class, 'dependencies'])->where('id', '[0-9]+');
    Route::post('admin/system/users/{id}/block', [AdminUsersController::class, 'block'])->where('id', '[0-9]+');
    Route::post('admin/system/users/{id}/unblock', [AdminUsersController::class, 'unblock'])->where('id', '[0-9]+');
    Route::delete('admin/system/users/{id}', [AdminUsersController::class, 'softDelete'])->where('id', '[0-9]+');
    Route::delete('admin/system/users/{id}/force', [AdminUsersController::class, 'hardDelete'])->where('id', '[0-9]+');
    Route::post('admin/system/users/{id}/restore', [AdminUsersController::class, 'restore'])->where('id', '[0-9]+');
    Route::put('admin/system/users/{id}/role', [AdminUsersController::class, 'updateRole'])->where('id', '[0-9]+');

    // ========== Admin Material Dimensions API ==========
    Route::post('admin/material-dimension-rules/preview', [AdminMaterialDimensionRuleController::class, 'preview']);
    Route::apiResource('admin/material-dimension-rules', AdminMaterialDimensionRuleController::class);
    Route::get('admin/material-dimension-failures', [AdminMaterialDimensionParseFailureController::class, 'index']);
    Route::get('admin/material-dimension-failures/{materialDimensionParseFailure}', [AdminMaterialDimensionParseFailureController::class, 'show']);
    Route::patch('admin/material-dimension-failures/{materialDimensionParseFailure}', [AdminMaterialDimensionParseFailureController::class, 'update']);

    // ========== Admin Material Type Patterns API ==========
    Route::post('admin/material-type-patterns/preview', [AdminMaterialTypePatternController::class, 'preview']);
    Route::apiResource('admin/material-type-patterns', AdminMaterialTypePatternController::class);
    
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
    Route::post('price-list-versions/{version}/evidence-links', [\App\Http\Controllers\Api\PriceListVersionController::class, 'storeEvidenceLink']);
    Route::get('price-list-versions/{version}/evidence-links', [\App\Http\Controllers\Api\PriceListVersionController::class, 'listEvidenceLinks']);
    Route::delete('price-list-versions/{version}/evidence-links/{link}', [\App\Http\Controllers\Api\PriceListVersionController::class, 'destroyVersionEvidenceLink']);
    Route::post('price-list-versions/{version}/evidence-records', [\App\Http\Controllers\Api\PriceListVersionController::class, 'createAndAttachEvidenceForVersion']);
    
    // Operation Price Linking
    Route::put('operation-prices/{operationPrice}/link', [\App\Http\Controllers\Api\PriceListVersionController::class, 'linkOperation']);
    Route::delete('operation-prices/{operationPrice}/link', [\App\Http\Controllers\Api\PriceListVersionController::class, 'unlinkOperation']);
    Route::post('operation-prices/{operationPrice}/evidence-links', [\App\Http\Controllers\Api\PriceListVersionController::class, 'storeOperationPriceEvidenceLink']);
    Route::get('operation-prices/{operationPrice}/evidence-links', [\App\Http\Controllers\Api\PriceListVersionController::class, 'listOperationPriceEvidenceLinks']);
    Route::delete('operation-prices/{operationPrice}/evidence-links/{link}', [\App\Http\Controllers\Api\PriceListVersionController::class, 'destroyOperationPriceEvidenceLink']);
    Route::post('operation-prices/{operationPrice}/evidence-records', [\App\Http\Controllers\Api\PriceListVersionController::class, 'createAndAttachEvidence']);
    
    // ========== Price Import API ==========
    Route::get('price-imports', [\App\Http\Controllers\Api\PriceImportController::class, 'index']);
    Route::post('price-imports', [\App\Http\Controllers\Api\PriceImportController::class, 'store']);
    Route::get('price-imports/{id}/items', [\App\Http\Controllers\Api\PriceImportController::class, 'items'])->whereNumber('id');
    Route::post('price-import-items/{id}/bind', [\App\Http\Controllers\Api\PriceImportController::class, 'bindItem']);
    Route::post('price-import-items/{id}/ignore', [\App\Http\Controllers\Api\PriceImportController::class, 'ignoreItem']);
    
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

    // ========== Support Chat (User) ==========
    Route::prefix('support-chat')->group(function () {
        Route::get('conversation', [\App\Http\Controllers\Api\SupportChatController::class, 'conversation']);
        Route::get('conversations/{conversation}/messages', [\App\Http\Controllers\Api\SupportChatController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [\App\Http\Controllers\Api\SupportChatController::class, 'sendMessage']);
        Route::post('conversations/{conversation}/read', [\App\Http\Controllers\Api\SupportChatController::class, 'markRead']);
        Route::post('conversations/{conversation}/typing', [\App\Http\Controllers\Api\SupportChatController::class, 'reportTyping']);
        Route::get('conversations/{conversation}/typing-status', [\App\Http\Controllers\Api\SupportChatController::class, 'typingStatus']);
    });

    // ========== Support Chat (Admin) ==========
    Route::prefix('admin/chat')->group(function () {
        Route::get('conversations', [\App\Http\Controllers\Api\AdminChatController::class, 'index']);
        Route::get('conversations/{conversation}', [\App\Http\Controllers\Api\AdminChatController::class, 'show']);
        Route::get('conversations/{conversation}/messages', [\App\Http\Controllers\Api\AdminChatController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [\App\Http\Controllers\Api\AdminChatController::class, 'sendMessage']);
        Route::post('conversations/{conversation}/read', [\App\Http\Controllers\Api\AdminChatController::class, 'markRead']);
        Route::post('conversations/{conversation}/assign', [\App\Http\Controllers\Api\AdminChatController::class, 'assign']);
        Route::post('conversations/{conversation}/typing', [\App\Http\Controllers\Api\AdminChatController::class, 'reportTyping']);
        Route::get('conversations/{conversation}/typing-status', [\App\Http\Controllers\Api\AdminChatController::class, 'typingStatus']);
    });

    // ========== Chat Attachments (auth-guarded file serving) ==========
    Route::get('chat/attachments/{attachment}', [\App\Http\Controllers\Api\ChatAttachmentController::class, 'download'])
        ->name('chat.attachment.download');
});

// Старые маршруты (можно удалить позже)
Route::apiResource('modules', FurnitureModuleController::class);
Route::get('modules/{id}/cost', [FurnitureModuleController::class, 'getCost']);
