<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicVerificationController;
use App\Http\Controllers\PublicPriceFileController;

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
