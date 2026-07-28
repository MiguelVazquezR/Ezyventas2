<?php

use Illuminate\Support\Facades\Route;
use Ezyventas\AiAgent\Http\Controllers\AiChatController;

/*
|--------------------------------------------------------------------------
| AI Agent Routes
|--------------------------------------------------------------------------
|
| Phases 1: synchronous chat + signed file downloads.
| Phase 11 (future): token-by-token streaming via queued job + Pusher.
|
*/

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->prefix('ai-agent')
    ->as('ai-agent.')
    ->group(function () {
        Route::get('/conversations', [AiChatController::class, 'index'])
            ->name('conversations.index');

        Route::post('/conversations', [AiChatController::class, 'store'])
            ->name('conversations.store');

        Route::get('/conversations/{conversation}', [AiChatController::class, 'show'])
            ->name('conversations.show');

        Route::post('/conversations/{conversation}/messages', [AiChatController::class, 'sendMessage'])
            ->name('messages.store');

        Route::delete('/conversations', [AiChatController::class, 'destroyAll'])
            ->name('conversations.destroy-all');

        Route::delete('/conversations/{conversation}', [AiChatController::class, 'destroy'])
            ->name('conversations.destroy');

        Route::get('/usage', [AiChatController::class, 'usage'])
            ->name('usage');
    });

// Download route — protected by signed URL signature AND subscription scoping
Route::middleware(['auth:sanctum', config('jetstream.auth_session')])
    ->get('/ai-agent/download/{path}', [AiChatController::class, 'download'])
    ->name('ai-agent.download')
    ->where('path', '.*')
    ->middleware('signed');
