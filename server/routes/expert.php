<?php
use App\Http\Controllers\Api\Expert\ConversationController;
use App\Http\Controllers\Api\Expert\FindingController;
use App\Http\Controllers\Api\Expert\MaterialController;
use App\Http\Controllers\Api\Expert\MessageController;
use App\Http\Controllers\Api\Expert\MessageStreamController;
use App\Http\Controllers\Api\Expert\ProjectController;
use App\Http\Controllers\Api\Expert\ResearchObjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('expert')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', ProjectController::class)->names([
        'index' => 'expert.projects.index',
        'store' => 'expert.projects.store',
        'show' => 'expert.projects.show',
        'update' => 'expert.projects.update',
        'destroy' => 'expert.projects.destroy',
    ]);
    Route::get('projects/{project}/research-objects', [ResearchObjectController::class, 'index']);
    Route::post('projects/{project}/research-objects', [ResearchObjectController::class, 'store']);
    Route::patch('research-objects/{researchObject}', [ResearchObjectController::class, 'update']);
    Route::delete('research-objects/{researchObject}', [ResearchObjectController::class, 'destroy']);
    Route::get('projects/{project}/conversations', [ConversationController::class, 'index']);
    Route::post('projects/{project}/conversations', [ConversationController::class, 'store']);
    Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('conversations/{conversation}/messages/stream', [MessageStreamController::class, 'store']);
    Route::post('conversations/{conversation}/messages/{assistant}/continue/stream', [MessageStreamController::class, 'continue']);
    Route::post('conversations/{conversation}/runs/{runId}/cancel', [MessageStreamController::class, 'cancel'])->whereUuid('runId');
    Route::get('projects/{project}/materials', [MaterialController::class, 'index']);
    Route::post('projects/{project}/materials', [MaterialController::class, 'store']);
    Route::get('materials/{material}', [MaterialController::class, 'show']);
    Route::get('materials/{material}/content', [MaterialController::class, 'content']);
    Route::get('materials/{material}/thumbnail', [MaterialController::class, 'thumbnail']);
    Route::get('materials/{material}/download', [MaterialController::class, 'download']);
    Route::delete('materials/{material}', [MaterialController::class, 'destroy']);
    Route::get('projects/{project}/findings', [FindingController::class, 'index']);
    Route::post('projects/{project}/findings', [FindingController::class, 'store']);
    Route::patch('findings/{finding}', [FindingController::class, 'update']);
    Route::delete('findings/{finding}', [FindingController::class, 'destroy']);
});
