<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FurnitureModuleController;
use App\Http\Controllers\Api\MaterialController; // ← добавлено
use App\Http\Controllers\Api\Parser\MaterialController as ParserMaterialController;


// Основные материалы (единый API)
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('materials', MaterialController::class);
    Route::get('materials/{id}/history', [MaterialController::class, 'history']);
});
Route::post('/parser/materials', [ParserMaterialController::class, 'store']); // ← только если парсер вызывается от пользователя
// Аутентификация
Route::post('login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    // user-materials больше не нужен → удалите, если не используете
});

// Старые маршруты — можно удалить после миграции
Route::apiResource('modules', FurnitureModuleController::class);
Route::get('modules/{id}/cost', [FurnitureModuleController::class, 'getCost']);
