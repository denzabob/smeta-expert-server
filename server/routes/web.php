<?php

use App\Http\Controllers\Api\FurnitureModuleController;
use App\Http\Controllers\Api\MaterialController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('api')->group(function () {
    Route::apiResource('materials', MaterialController::class);
    Route::apiResource('modules', FurnitureModuleController::class);
    Route::get('modules/{id}/cost', [FurnitureModuleController::class, 'getCost']);
});

Route::get('/', function () {
    return view('welcome');
});
