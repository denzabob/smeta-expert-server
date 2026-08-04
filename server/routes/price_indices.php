<?php

use App\Domain\PriceIndices\Http\Controllers\PriceIndicesCapabilitiesController;
use Illuminate\Support\Facades\Route;

Route::prefix('indices')
    ->middleware(['auth:sanctum', 'price_indices.access'])
    ->group(function () {
        Route::get('capabilities', PriceIndicesCapabilitiesController::class)
            ->name('price-indices.capabilities');
    });
