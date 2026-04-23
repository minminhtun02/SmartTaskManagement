<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('tasks/search', [TaskController::class, 'search']);
        Route::get('tasks/filter', [TaskController::class, 'filter']);
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{id}', [TaskController::class, 'show'])->whereNumber('id');
        Route::post('tasks', [TaskController::class, 'store']);
        Route::put('tasks/{id}', [TaskController::class, 'update'])->whereNumber('id');
        Route::delete('tasks/{id}', [TaskController::class, 'destroy'])->whereNumber('id');
        Route::patch('tasks/{id}/status', [TaskController::class, 'updateStatus'])->whereNumber('id');

        Route::get('dashboard/summary', [DashboardController::class, 'summary']);

        Route::post('ai/priority', [AIController::class, 'priority']);
        Route::post('ai/category', [AIController::class, 'category']);
        Route::post('ai/time-estimate', [AIController::class, 'timeEstimate']);
        Route::get('ai/focus-task', [AIController::class, 'focusTask']);
        Route::get('ai/productivity-tip', [AIController::class, 'productivityTip']);
        Route::post('ai/suggestions-draft', [AIController::class, 'suggestionsDraft']);
        Route::post('ai/chat', [AIController::class, 'chat']);
    });
});
