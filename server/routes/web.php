<?php

use App\Domain\PriceIndices\Http\Controllers\PublicIndexCalculationController;
use App\Domain\PriceIndices\Http\Controllers\PublicIndexCatalogController;
use App\Domain\PriceIndices\Http\Controllers\PublicIndexDetailController;
use App\Domain\PriceIndices\Http\Controllers\PublicIndexFamilyController;
use App\Domain\PriceIndices\Http\Controllers\PublicIndexProductsController;
use App\Domain\PriceIndices\Http\Controllers\PublicIndexRobotsController;
use App\Domain\PriceIndices\Http\Controllers\PublicIndexSitemapController;
use App\Domain\PriceIndices\Http\Middleware\CachePublicIndexResponse;
use App\Http\Controllers\PublicPriceFileController;
use App\Http\Controllers\PublicVerificationController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$priceIndicesPublicHost = (string) config('price_indices.public_host');

if ($priceIndicesPublicHost !== '') {
    Route::domain($priceIndicesPublicHost)->name('price-indices.public.')->group(function (): void {
        Route::middleware(CachePublicIndexResponse::class)
            ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
            ->group(function (): void {
                Route::get('/', PublicIndexCatalogController::class)->name('catalog');
                Route::get('/producer-prices', PublicIndexFamilyController::class)->name('producer-prices');
                Route::get('/producer-prices/products', PublicIndexProductsController::class)->name('producer-prices.products');
                Route::get('/sitemap.xml', PublicIndexSitemapController::class)->name('sitemap');
                Route::get('/robots.txt', PublicIndexRobotsController::class)->name('robots');
                Route::get('/{slug}', PublicIndexDetailController::class)
                    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
                    ->name('detail');
            });
        Route::post('/{slug}/calculate', PublicIndexCalculationController::class)
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
            ->middleware('throttle:'.config('price_indices.public_calculation.throttle_per_minute', 20).',1')
            ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
            ->name('calculate');
        Route::any('/{path}', fn () => abort(404))->where('path', '.*')->name('fallback');
    });
}

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['throttle:30,1'])->group(function () {
    Route::get('/v/{publicId}', [PublicVerificationController::class, 'show'])->name('verification.show');
    Route::get('/v/{publicId}/pdf', [PublicVerificationController::class, 'pdf'])->middleware('throttle:10,1')->name('verification.pdf');
    Route::get('/public/price-file/{versionId}/{documentToken}', [PublicPriceFileController::class, 'download'])
        ->where('versionId', '[0-9]+')
        ->name('public.price-file');
});
