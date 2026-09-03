<?php

use App\Domain\PriceIndices\Http\Controllers\PublicToolsIndexSeriesCalculateController;
use App\Domain\PriceIndices\Http\Controllers\PublicToolsIndexSeriesSearchController;
use App\Domain\PriceIndices\Http\Controllers\PublicToolsOkpd2SearchController;
use App\Domain\PriceIndices\Http\Middleware\PublicToolsJsonResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$priceIndicesPublicHost = (string) config('price_indices.public_host');

if ($priceIndicesPublicHost !== '') {
    Route::domain($priceIndicesPublicHost)
        ->prefix('api/public/v1')
        ->name('price-indices.public-tools.')
        ->middleware(PublicToolsJsonResponse::class)
        ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
        ->group(function (): void {
            Route::get('index-series/search', PublicToolsIndexSeriesSearchController::class)
                ->middleware('throttle:'.config('price_indices.public_tools.search_throttle_per_minute', 60).',1')
                ->name('index-series.search');
            Route::post('index-series/calculate', PublicToolsIndexSeriesCalculateController::class)
                ->middleware('throttle:'.config('price_indices.public_tools.calculate_throttle_per_minute', 30).',1')
                ->name('index-series.calculate');
            Route::get('okpd2/search', PublicToolsOkpd2SearchController::class)
                ->middleware('throttle:'.config('price_indices.public_tools.search_throttle_per_minute', 60).',1')
                ->name('okpd2.search');
        });
}
