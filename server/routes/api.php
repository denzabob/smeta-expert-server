<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FurnitureModuleController;
use App\Http\Controllers\Api\SystemMaterialController;
use App\Http\Controllers\Api\UserMaterialController;

Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::apiResource('user-materials', UserMaterialController::class);
});

Route::apiResource('system-materials', SystemMaterialController::class);
Route::get('system-materials/{id}/history', [SystemMaterialController::class, 'history']);
Route::apiResource('modules', FurnitureModuleController::class);
Route::get('modules/{id}/cost', [FurnitureModuleController::class, 'getCost']);

