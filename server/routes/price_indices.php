<?php

use App\Domain\PriceIndices\Http\Controllers\DatasetAdminController;
use App\Domain\PriceIndices\Http\Controllers\PriceIndicesCapabilitiesController;
use App\Domain\PriceIndices\Http\Controllers\SourceAdminController;
use App\Domain\PriceIndices\Http\Controllers\SourceFileAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('indices')
    ->middleware(['auth:sanctum', 'price_indices.access'])
    ->group(function () {
        Route::get('capabilities', PriceIndicesCapabilitiesController::class)
            ->name('price-indices.capabilities');

        Route::prefix('admin')->name('price-indices.admin.')->group(function () {
            Route::get('datasets', [DatasetAdminController::class, 'index'])->name('datasets.index');
            Route::post('datasets', [DatasetAdminController::class, 'store'])->name('datasets.store');
            Route::get('datasets/{dataset}', [DatasetAdminController::class, 'show'])->name('datasets.show');
            Route::put('datasets/{dataset}', [DatasetAdminController::class, 'update'])->name('datasets.update');

            Route::get('sources', [SourceAdminController::class, 'index'])->name('sources.index');
            Route::post('sources', [SourceAdminController::class, 'store'])->name('sources.store');
            Route::get('sources/{source}', [SourceAdminController::class, 'show'])->name('sources.show');
            Route::put('sources/{source}', [SourceAdminController::class, 'update'])->name('sources.update');

            Route::get('source-files', [SourceFileAdminController::class, 'index'])->name('source-files.index');
            Route::post('source-files/upload', [SourceFileAdminController::class, 'upload'])->name('source-files.upload');
            Route::get('source-files/{sourceFile}', [SourceFileAdminController::class, 'show'])->name('source-files.show');
            Route::post('source-files/{sourceFile}/approve', [SourceFileAdminController::class, 'approve'])->name('source-files.approve');
            Route::post('source-files/{sourceFile}/reject', [SourceFileAdminController::class, 'reject'])->name('source-files.reject');
            Route::post('source-files/{sourceFile}/activate', [SourceFileAdminController::class, 'activate'])->name('source-files.activate');
            Route::get('source-files/{sourceFile}/download', [SourceFileAdminController::class, 'download'])->name('source-files.download');
        });
    });
